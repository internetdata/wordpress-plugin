<?php

declare(strict_types=1);

namespace InternetData\WordPress;

defined('ABSPATH') || exit;

/** A visitor one area flagged, and what that area does about it. */
final class Verdict
{
    /**
     * @param list<string> $signals
     * @param array<string, string> $context
     */
    public function __construct(
        public readonly string $area,
        /** `log`, `block`, `hold`, `spam` or `redirect`. */
        public readonly string $action,
        /** The signals that matched, or `unavailable` when the check failed and failing closed. */
        public readonly array $signals,
        public readonly Visit $visit,
        /** What the visitor was doing: the sign-in name, the order, the page. */
        public readonly array $context = [],
        /** Where a geo redirect sends the visitor. */
        public readonly string $target = '',
        /** The order's billing country, when it differs from the visitor's. */
        public readonly string $billing = '',
    ) {
    }

    /** Whether the area acts on it, rather than only recording it. */
    public function enforced(): bool
    {
        return $this->action !== 'log';
    }

    /** The provider the answer names for the first matched signal that has one. */
    public function provider(): string
    {
        foreach ($this->signals as $signal) {
            $provider = $this->providerOf($signal);
            if ($provider !== '') {
                return $provider;
            }
        }
        return '';
    }

    /** What matched, for people: "VPNs (nordvpn), Hosting and cloud servers". */
    public function describe(): string
    {
        $parts = [];
        $named = false;
        $country = $this->visit->result?->location->country ?? '';
        foreach ($this->signals as $signal) {
            $label = Signals::label($signal);
            if ($signal === 'country' || $signal === 'geo') {
                $parts[] = $country === '' ? $label : sprintf('%s (%s)', $label, $country);
                continue;
            }
            if ($signal === 'billing') {
                $parts[] = sprintf(
                    /* translators: 1: "Billing country differs"; 2: a country code, such as US; 3: another, such as DE. */
                    __('%1$s (billed to %2$s, connected from %3$s)', 'internetdata'),
                    $label,
                    $this->billing,
                    $country,
                );
                continue;
            }
            $provider = $named ? '' : $this->providerOf($signal);
            $parts[] = $provider !== '' ? sprintf('%s (%s)', $label, $provider) : $label;
            $named = $named || $provider !== '';
        }
        return implode(', ', $parts);
    }

    /** The provider in one signal's detail, such as `nordvpn` for VPNs, or an empty string. */
    private function providerOf(string $signal): string
    {
        $detail = Signals::ALL[$signal]['detail'] ?? null;
        $object = $detail === null ? null : $this->visit->result?->{$detail};
        return is_object($object) && property_exists($object, 'provider') ? (string) $object->provider : '';
    }

    /**
     * The verdict as plain data, for the `internetdata_flagged` action.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'area' => $this->area,
            'action' => $this->action,
            'signals' => $this->signals,
            'ip' => $this->visit->ip,
            'answer' => $this->visit->answer(),
            'context' => $this->context,
            'target' => $this->target,
            'billing_country' => $this->billing,
        ], static fn ($v): bool => $v !== '');
    }
}
