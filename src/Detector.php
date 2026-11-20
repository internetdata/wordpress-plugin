<?php

declare(strict_types=1);

namespace InternetData\WordPress;

use InvalidArgumentException;
use InternetData\WordPress\Vendor\InternetData\Bogon;
use InternetData\WordPress\Vendor\InternetData\Client;
use InternetData\WordPress\Vendor\InternetData\Middleware\Condition;
use InternetData\WordPress\Vendor\InternetData\Middleware\Core;
use InternetData\WordPress\Vendor\InternetData\Middleware\Options as MiddlewareOptions;
use InternetData\WordPress\Vendor\InternetData\Options;
use InternetData\WordPress\Vendor\InternetData\Result;

defined('ABSPATH') || exit;

/**
 * Looks the visitor up at most once a request, and decides for each area whether they are someone
 * it should act on.
 *
 * Built on the SDK's middleware core, which every framework adapter shares: the address is resolved
 * by the plugin's own selector, a private one is answered without a lookup, and a lookup that fails
 * lets the visitor through unless the site chose to fail closed.
 */
final class Detector
{
    /** Seconds a lookup may hold a visitor's request: failing open quickly beats waiting. */
    public const TIMEOUT = 2.5;

    private ?Visit $visit = null;
    private ?Client $client = null;
    private ?string $address = null;
    private bool $resolved = false;
    /** @var array<string, Verdict|null> */
    private array $judged = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly ActivityLog $log,
        private readonly Diagnostics $diagnostics,
    ) {
    }

    /** The SDK client the request path uses: the site's key, a short timeout and no retries. */
    public function client(): Client
    {
        return $this->client ??= self::newClient($this->settings->apiKey(), self::TIMEOUT, 0);
    }

    public static function newClient(?string $apiKey, float $timeout, int $retries): Client
    {
        return new Client(new Options(
            apiKey: $apiKey,
            baseUrl: self::baseUrl(),
            retries: $retries,
            timeout: $timeout,
            httpClient: WpHttp::client(),
            cacheStore: new Store(),
            cacheTtlSeconds: (float) apply_filters('internetdata_cache_ttl', 6 * HOUR_IN_SECONDS),
        ));
    }

    /** The API's origin. A constant points the plugin at another deployment, for testing it. */
    public static function baseUrl(): string
    {
        return defined('INTERNETDATA_BASE_URL') ? (string) constant('INTERNETDATA_BASE_URL') : Client::DEFAULT_BASE_URL;
    }

    /** The visitor behind this request, looked up once however many areas ask. */
    public function visit(): Visit
    {
        return $this->visit ??= $this->lookUp();
    }

    /** The visitor's address, resolved without a lookup. */
    public function address(): ?string
    {
        if (!$this->resolved) {
            $server = Request::server();
            $ip = ClientAddress::fromSettings($this->settings)->resolve($server);
            /**
             * Filters the visitor's address, for a site whose edge names it in a way the settings
             * cannot.
             *
             * @param string|null $ip
             * @param array<string, string> $server
             */
            $ip = apply_filters('internetdata_client_ip', $ip, $server);
            $this->address = is_string($ip) ? Address::normalize($ip) : null;
            $this->resolved = true;
        }
        return $this->address;
    }

    /**
     * Whether an area should act on this visitor, and how. Null when the area is off, nothing is set
     * to block, or the visitor passes.
     *
     * @param array<string, string> $context What the visitor was doing, for the activity log.
     * @param string $billing At checkout, the order's billing country, which the visitor's own must
     *     match while the site asks for that.
     */
    public function judge(string $area, array $context = [], string $billing = ''): ?Verdict
    {
        $billing = $area === 'checkout' && $this->settings->get('checkout_billing') ? strtoupper(trim($billing)) : '';
        $key = $area . "\0" . $billing;
        if (array_key_exists($key, $this->judged)) {
            return $this->judged[$key];
        }
        return $this->judged[$key] = $this->decide($area, $context, $billing);
    }

    /**
     * Where the pages area sends this visitor by their country, as a verdict whose action is
     * `redirect`, or `log` while the area only logs. Null when no rule lists their country, or the
     * rule's address is the page they asked for.
     *
     * @param array<string, string> $context
     * @param callable(string): bool $isHere Whether an address is the page being asked for.
     */
    public function geo(array $context, callable $isHere): ?Verdict
    {
        $action = $this->settings->action('pages');
        $rules = $this->settings->geoRedirects();
        if ($action === 'off' || $rules === []) {
            return null;
        }
        $visit = $this->visit();
        $country = $visit->result?->location->country ?? '';
        if ($visit->allowed || $visit->result === null || $visit->result->isBogon || $country === '') {
            return null;
        }
        foreach ($rules as $rule) {
            if (!in_array($country, $rule['countries'], true)) {
                continue;
            }
            if ($isHere($rule['url'])) {
                return null;
            }
            $verdict = new Verdict('pages', $action === 'log' ? 'log' : 'redirect', ['geo'], $visit, $context, $rule['url']);
            return $this->flag($verdict);
        }
        return null;
    }

    /** @param array<string, string> $context */
    private function decide(string $area, array $context, string $billing): ?Verdict
    {
        $action = $this->settings->action($area);
        $signals = $this->settings->signals($area);
        if ($action === 'off' || ($signals === [] && $billing === '')) {
            return null;
        }
        $visit = $this->visit();
        if ($visit->ip === null || $visit->allowed) {
            return null;
        }
        if ($visit->error !== null) {
            if (!$this->settings->get('fail_closed')) {
                return null;
            }
            return $this->flag(new Verdict($area, $action, ['unavailable'], $visit, $context));
        }
        $result = $visit->result;
        if ($result === null || $result->isBogon) {
            return null;
        }
        $matched = $this->matched($area, $signals, $result);
        $country = $result->location->country;
        if ($billing !== '' && $country !== '' && $billing !== $country) {
            $matched[] = 'billing';
        }
        if ($matched === []) {
            return null;
        }
        $billing = in_array('billing', $matched, true) ? $billing : '';
        return $this->flag(new Verdict($area, $action, $matched, $visit, $context, billing: $billing));
    }

    /**
     * The signals this answer matched in an area, `custom` when only a filtered rule did, or none.
     *
     * @param list<string> $signals
     * @return list<string>
     */
    private function matched(string $area, array $signals, Result $result): array
    {
        if ($signals === []) {
            return [];
        }
        $minHits = (int) $this->settings->get('min_hits');
        $countries = $this->settings->countries();
        $mode = $this->settings->get('countries_mode');
        $condition = Signals::condition($signals, $minHits, $result, $countries, $mode);
        /**
         * Filters the rule an area blocks on, in the PHP SDK's condition format.
         *
         * @param list<array<string, mixed>> $condition
         * @param string $area
         * @param list<string> $signals
         */
        $condition = apply_filters('internetdata_condition', $condition, $area, $signals);
        if (!is_array($condition) || $condition === []) {
            return [];
        }
        try {
            Condition::validate($condition);
        } catch (InvalidArgumentException) {
            return [];
        }
        $this->notePlanGaps($signals, $minHits, $result, $countries, $mode);
        if (!Condition::matches($condition, $result)) {
            return [];
        }
        $matched = [];
        foreach ($signals as $signal) {
            $one = Signals::one($signal, $minHits, $result, $countries, $mode);
            if ($one !== null && Condition::matches([$one], $result)) {
                $matched[] = $signal;
            }
        }
        return $matched === [] ? ['custom'] : $matched;
    }

    private function flag(Verdict $verdict): ?Verdict
    {
        /**
         * Filters a verdict before the area acts on it. Return null to let the visitor through.
         *
         * @param Verdict|null $verdict
         */
        $verdict = apply_filters('internetdata_verdict', $verdict);
        if (!$verdict instanceof Verdict) {
            return null;
        }
        $this->log->record($verdict);
        do_action('internetdata_flagged', $verdict->toArray());
        return $verdict;
    }

    private function lookUp(): Visit
    {
        $ip = $this->address();
        if ($ip === null) {
            return new Visit(null);
        }
        $allowlist = Allowlist::parse($this->settings->get('allowlist'));
        if ($allowlist->coversAddress($ip)) {
            return new Visit($ip, allowed: true);
        }
        if (Bogon::isBogon($ip)) {
            $this->diagnostics->privateAddress($ip);
        }
        // The core's own warnings name the SDK's settings; the plugin says it in its own terms above.
        $core = new Core(
            new MiddlewareOptions(client: $this->client(), onMissingField: 'ignore', onWarn: static function (): void {
            }),
            static fn (): string => $ip,
        );
        $lookup = $core->evaluate(null);
        if ($lookup === null || $lookup->result === null) {
            if ($lookup?->error !== null) {
                $this->diagnostics->lookupFailed($lookup->error);
            }
            return new Visit($ip, error: $lookup?->error);
        }
        // A failure the settings are still showing is over once a lookup answers; one younger than
        // five minutes stays, so a flapping API costs a write every few minutes, not every request.
        $failed = $this->diagnostics->recent()['lookup']['at'] ?? null;
        if ($failed !== null && $failed < time() - 5 * MINUTE_IN_SECONDS) {
            $this->diagnostics->clear('lookup');
        }
        $this->diagnostics->capReached($lookup->result->capReached === true);
        $asn = $lookup->result->asn?->asn ?? 0;
        return new Visit($ip, $lookup->result, allowed: $allowlist->coversAsn($asn));
    }

    /**
     * Notes the signals this answer could never match because the key's plan does not answer them,
     * so the settings can say why a signal blocks nobody.
     *
     * @param list<string> $signals
     * @param list<string> $countries
     */
    private function notePlanGaps(array $signals, int $minHits, Result $result, array $countries, string $mode): void
    {
        $gaps = [];
        foreach ($signals as $signal) {
            $one = Signals::one($signal, $minHits, $result, $countries, $mode);
            if ($one !== null && Condition::missingMembers([$one], $result) !== []) {
                $gaps[] = $signal;
            }
        }
        if ($gaps !== []) {
            $this->diagnostics->planGaps($gaps);
        } elseif (isset($this->diagnostics->recent()['gaps'])) {
            $this->diagnostics->clear('gaps');
        }
    }
}
