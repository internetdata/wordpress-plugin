<?php

declare(strict_types=1);

namespace InternetData\WordPress\Admin;

use InternetData\WordPress\ActivityLog;
use InternetData\WordPress\Allowlist;
use InternetData\WordPress\Areas\Checkout;
use InternetData\WordPress\Areas\Forms;
use InternetData\WordPress\ClientAddress;
use InternetData\WordPress\Connect;
use InternetData\WordPress\Detector;
use InternetData\WordPress\Diagnostics;
use InternetData\WordPress\Plugin;
use InternetData\WordPress\Request;
use InternetData\WordPress\Settings;
use InternetData\WordPress\Signals;
use InternetData\WordPress\Vendor\InternetData\Bogon;
use InternetData\WordPress\Vendor\InternetData\Entitlement;
use InternetData\WordPress\Vendor\InternetData\InternetDataException;

defined('ABSPATH') || exit;

/** Settings, InternetData: the account, what to block and where, and the activity log. */
final class Page
{
    public const SLUG = 'internetdata';
    private const GROUP = 'internetdata';
    private const ACCOUNT_CACHE = 'internetdata_account';
    private const SET_UP = 'internetdata_set_up';
    /** The plan an account with no subscription is on, which InternetData calls Unlimited. */
    private const FREE_PLAN = 'free';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_internetdata_key', [$this, 'saveKey']);
        add_action('admin_post_internetdata_disconnect', [$this, 'disconnect']);
        add_action('admin_post_internetdata_allow', [$this, 'allow']);
        add_action('admin_post_internetdata_clear', [$this, 'clear']);
        add_filter('plugin_action_links_' . plugin_basename(INTERNETDATA_FILE), [$this, 'links']);
        // Activation schedules the purge, but a network activation runs it for one site alone.
        add_action('admin_init', static function (): void {
            if (!wp_next_scheduled(ActivityLog::PURGE_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', ActivityLog::PURGE_HOOK);
            }
        });
    }

    public function menu(): void
    {
        add_options_page('InternetData', 'InternetData', 'manage_options', self::SLUG, [$this, 'render']);
    }

    public function settings(): void
    {
        register_setting(self::GROUP, Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => [],
        ]);
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function sanitize($input): array
    {
        $clean = Settings::sanitize($input);
        $bad = array_merge(
            Allowlist::invalid($clean['allowlist']),
            array_filter(Settings::lines($clean['proxy_trusted']), static fn (string $l): bool => \InternetData\WordPress\Address::block($l) === null),
        );
        if ($bad !== []) {
            add_settings_error(Settings::OPTION, 'internetdata_invalid', sprintf(
                /* translators: %s: the entries, comma separated. */
                __('These entries are not an address, a block or an AS number, and are ignored: %s', 'internetdata'),
                implode(', ', array_slice($bad, 0, 10)),
            ), 'warning');
        }
        $geo = Settings::invalidGeoRules($clean['pages_geo']);
        if ($geo !== []) {
            add_settings_error(Settings::OPTION, 'internetdata_geo', sprintf(
                /* translators: %s: the lines, comma separated. */
                __('These geo redirect lines name no two-letter country code or no web address, and are ignored: %s', 'internetdata'),
                implode(', ', array_slice($geo, 0, 10)),
            ), 'warning');
        }
        // An area with nothing to act on catches nobody: pages may still have geo redirects, and
        // checkout its billing country check.
        $on = array_filter($clean['areas'], static fn (array $a): bool => $a['action'] !== 'off');
        $empty = array_filter($on, static fn (array $a): bool => ($a['signals'] ?? $clean['signals']) === []);
        if (Settings::geoRules($clean['pages_geo']) !== []) {
            unset($empty['pages']);
        }
        if ($clean['checkout_billing']) {
            unset($empty['checkout']);
        }
        if ($empty !== []) {
            add_settings_error(Settings::OPTION, 'internetdata_nothing', __(
                'An area is on with nothing ticked to block, so it catches nobody.',
                'internetdata',
            ), 'warning');
        }
        update_option(self::SET_UP, '1', false);
        $this->plugin->settings->reload();
        return $clean;
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function links(array $links): array
    {
        $url = admin_url('options-general.php?page=' . self::SLUG);
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url($url), esc_html__('Settings', 'internetdata')));
        return $links;
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::SLUG) {
            return;
        }
        $version = INTERNETDATA_VERSION;
        wp_enqueue_style('internetdata-admin', plugins_url('assets/admin.css', INTERNETDATA_FILE), [], $version);
        wp_enqueue_script('internetdata-admin', plugins_url('assets/admin.js', INTERNETDATA_FILE), [], $version, ['in_footer' => true]);
        wp_localize_script('internetdata-admin', 'internetdataAdmin', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Connect::NONCE),
            'text' => [
                'waiting' => __('Approve the sign-in in the tab that opened. If it asks for a code, it is', 'internetdata'),
                'denied' => __('The sign-in was declined. Nothing changed.', 'internetdata'),
                'expired' => __('The sign-in code ran out. Start again when you are ready.', 'internetdata'),
                'nokey' => __('Signed in, but that key cannot be handed over: its secret can no longer be shown. Pick another key, or paste one below.', 'internetdata'),
                'failed' => __('Something went wrong talking to InternetData. Try again, or paste a key below.', 'internetdata'),
                'popup' => __('Your browser blocked the new tab. Open this link to approve:', 'internetdata'),
            ],
        ]);
    }

    public function notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = get_current_screen();
        $here = $screen !== null && $screen->id === 'settings_page_' . self::SLUG;
        $where = $here || ($screen !== null && in_array($screen->id, ['dashboard', 'plugins'], true));
        $lookup = $this->plugin->diagnostics->recent()['lookup'] ?? null;
        if ($where && is_array($lookup) && Diagnostics::needsOwner((string) $lookup['kind'])) {
            $failClosed = (bool) $this->plugin->settings->get('fail_closed');
            $message = match ((string) $lookup['kind']) {
                'quota_exceeded' => $failClosed
                    ? __('InternetData: this site has used its allowance of checks, so visitors are being refused until it resets.', 'internetdata')
                    : __('InternetData: this site has used its allowance of checks, so visitors are let through unchecked until it resets.', 'internetdata'),
                default => __('InternetData refused this site\'s API key, so visitors are not being checked. Connect again, or check the key in the console.', 'internetdata'),
            };
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
        }
        if ($where && isset($this->plugin->diagnostics->recent()['cap'])) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__('InternetData: your organization has reached the overage cap it set, so visitors are checked with the free plan\'s signals until the billing period ends or the cap is raised in Billing.', 'internetdata'),
            );
        }
        if ($screen !== null && $screen->id === 'plugins' && get_option(self::SET_UP) !== '1') {
            printf(
                '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
                esc_html__('InternetData is active, and checks nothing until you choose what to protect.', 'internetdata'),
                esc_url(admin_url('options-general.php?page=' . self::SLUG)),
                esc_html__('Set it up', 'internetdata'),
            );
        }
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab to show; nothing is changed.
        $tab = isset($_GET['tab']) && sanitize_key(wp_unslash($_GET['tab'])) === 'activity' ? 'activity' : 'settings';
        $base = admin_url('options-general.php?page=' . self::SLUG);
        echo '<div class="wrap inetd">';
        echo '<h1>InternetData</h1>';
        echo '<nav class="nav-tab-wrapper">';
        foreach (['settings' => __('Settings', 'internetdata'), 'activity' => __('Activity', 'internetdata')] as $id => $label) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($id === 'settings' ? $base : add_query_arg('tab', $id, $base)),
                $tab === $id ? ' nav-tab-active' : '',
                esc_html($label),
            );
        }
        echo '</nav>';
        $this->flash();
        if ($tab === 'activity') {
            (new ActivityScreen($this->plugin))->render();
        } else {
            $this->renderSettings();
        }
        echo '</div>';
    }

    private function renderSettings(): void
    {
        settings_errors(Settings::OPTION);
        $settings = $this->plugin->settings;
        $account = $this->account();
        $tier = $account instanceof Entitlement ? $account->plan->tier : null;
        $this->renderAccount($account);
        $this->renderConnection($tier);
        echo '<form method="post" action="options.php" class="inetd-form">';
        settings_fields(self::GROUP);
        printf('<input type="hidden" name="%s[form]" value="1">', esc_attr(Settings::OPTION));
        $this->renderSignals($settings, $tier);
        $this->renderAreas($settings, $tier);
        $this->renderMessage($settings);
        $this->renderAllowlist($settings);
        $this->renderAdvanced($settings);
        submit_button();
        echo '</form>';
    }

    /** @param Entitlement|InternetDataException|null $account */
    private function renderAccount($account): void
    {
        $settings = $this->plugin->settings;
        echo '<div class="inetd-card" id="inetd-account">';
        echo '<h2>' . esc_html__('Account', 'internetdata') . '</h2>';
        if ($account instanceof Entitlement) {
            $usage = $account->usage;
            // The free plan, Unlimited, has no allowance to spend: its usage is its trial, the checks answered
            // with every signal in the window the API names (a UTC calendar month since 2026-11-21).
            $free = $account->plan->key === self::FREE_PLAN;
            printf(
                '<p><strong>%s</strong> %s</p>',
                esc_html(sprintf(
                    /* translators: %s: the plan's name, such as "Unlimited" or "Starter". */
                    __('Connected, on the %s plan.', 'internetdata'),
                    $account->plan->name,
                )),
                esc_html($free
                    ? sprintf(
                        /* translators: 1: checks made with every signal; 2: how many get every signal; 3: a date. */
                        __('%1$s of %2$s checks with every signal used this month, which resets on %3$s.', 'internetdata'),
                        number_format_i18n($usage->requests),
                        number_format_i18n($usage->quota),
                        wp_date(get_option('date_format'), $usage->windowEnd->getTimestamp()),
                    )
                    : sprintf(
                        /* translators: 1: checks made; 2: checks included; 3: a date. */
                        __('%1$s of %2$s checks used this period, which resets on %3$s.', 'internetdata'),
                        number_format_i18n($usage->requests),
                        number_format_i18n($usage->quota),
                        wp_date(get_option('date_format'), $usage->windowEnd->getTimestamp()),
                    )),
            );
            echo '<div class="inetd-actions">';
            printf(
                '<a class="button" href="%s" target="_blank" rel="noopener">%s</a>',
                esc_url(self::console('/billing/plan', 'plan')),
                $account->plan->tier === 'free' ? esc_html__('Upgrade for every signal', 'internetdata') : esc_html__('Manage plan', 'internetdata'),
            );
            if (!$settings->keyInConfig()) {
                $this->postButton('internetdata_disconnect', __('Disconnect', 'internetdata'), 'button-link-delete', __('Disconnect this site? It goes back to checking without an account, which catches no VPNs or proxies.', 'internetdata'));
            }
            echo '</div>';
            if ($settings->keyInConfig()) {
                echo '<p class="description">' . esc_html__('Using the API key set as INTERNETDATA_API_KEY in wp-config.php.', 'internetdata') . '</p>';
            }
            echo '</div>';
            return;
        }
        if ($account instanceof InternetDataException) {
            printf(
                '<div class="notice notice-error inline"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: %s: the reason, as InternetData gave it. */
                    __('InternetData did not accept this site\'s API key: %s', 'internetdata'),
                    $account->getMessage(),
                )),
            );
        }
        if ($settings->keyInConfig()) {
            echo '<p>' . esc_html__('This site uses the API key set as INTERNETDATA_API_KEY in wp-config.php.', 'internetdata') . '</p></div>';
            return;
        }
        echo '<p>' . esc_html__('Connect an InternetData account to try every signal, VPNs and proxies included. Paid plans check every signal on every visit.', 'internetdata') . '</p>';
        echo '<p class="description">' . esc_html__('Without an account the plugin checks countries, Tor, privacy relays and hosting, judged by the network an address belongs to, with no cap, and runs your country rules, geo redirects and the checkout\'s billing country check.', 'internetdata') . '</p>';
        printf(
            '<p><button type="button" class="button button-primary" id="inetd-connect">%s</button> <span id="inetd-connect-status" aria-live="polite"></span></p>',
            esc_html__('Connect with InternetData', 'internetdata'),
        );
        echo '<details class="inetd-key"><summary>' . esc_html__('Or paste an API key', 'internetdata') . '</summary>';
        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        echo '<input type="hidden" name="action" value="internetdata_key">';
        wp_nonce_field('internetdata_key');
        printf(
            '<p><label for="inetd-api-key" class="screen-reader-text">%1$s</label><input type="password" id="inetd-api-key" name="api_key" class="regular-text" autocomplete="off" placeholder="%1$s"> ',
            esc_attr__('API key', 'internetdata'),
        );
        submit_button(__('Save key', 'internetdata'), 'secondary', 'submit', false);
        printf(
            '</p><p class="description">%s <a href="%s" target="_blank" rel="noopener">%s</a></p>',
            esc_html__('Your keys are in the console.', 'internetdata'),
            esc_url(self::console('/api/keys', 'api-keys')),
            esc_html__('Open the console', 'internetdata'),
        );
        echo '</form></details></div>';
    }

    /** What the plugin sees of the person looking at this page: the self-check. */
    private function renderConnection(?string $tier): void
    {
        $detector = $this->plugin->detector;
        $server = Request::server();
        $ip = $detector->address();
        echo '<div class="inetd-card" id="inetd-connection">';
        echo '<h2>' . esc_html__('Your connection', 'internetdata') . '</h2>';
        if ($ip === null) {
            echo '<p>' . esc_html__('This request carried no address the plugin could read.', 'internetdata') . '</p></div>';
            return;
        }
        $visit = $detector->visit();
        $result = $visit->result;
        $where = [];
        if ($result?->location !== null && $result->location->countryName !== '') {
            $where[] = $result->location->countryName;
        }
        if ($result?->asn !== null && $result->asn->asn > 0) {
            $where[] = sprintf('AS%d %s', $result->asn->asn, $result->asn->name);
        }
        printf(
            '<p><code>%s</code>%s</p>',
            esc_html($ip),
            $where === [] ? '' : ' ' . esc_html(implode(', ', $where)),
        );
        if (Bogon::isBogon($ip)) {
            echo '<p class="inetd-warn">' . esc_html__('That is a private address, so a proxy or load balancer sits in front of this site and the plugin cannot see past it. Every visitor looks like this one and nobody is flagged. Set Visitor address under Advanced to the proxy in front of you.', 'internetdata') . '</p>';
        } elseif ($visit->allowed) {
            echo '<p>' . esc_html__('Your allowlist covers this address, so it is never checked.', 'internetdata') . '</p>';
        } elseif ($visit->error !== null) {
            printf('<p class="inetd-warn">%s</p>', esc_html(sprintf(
                /* translators: %s: the reason, as InternetData gave it. */
                __('The check failed: %s', 'internetdata'),
                $visit->error->getMessage(),
            )));
        } elseif ($result !== null) {
            $flagged = [];
            foreach (Signals::keys() as $signal) {
                $member = Signals::ALL[$signal]['member'];
                if ($result->{$member} === true) {
                    $flagged[] = Signals::label($signal);
                }
            }
            if ($flagged === []) {
                echo '<p>' . esc_html__('Not a VPN, proxy, Tor or hosting address, as far as your plan answers.', 'internetdata') . '</p>';
            } else {
                printf('<p class="inetd-warn">%s</p>', esc_html(sprintf(
                    /* translators: %s: what the connection is, such as "VPNs, Hosting and cloud servers". */
                    __('Your connection counts as: %s. An area set to block these blocks you too, except sign-in while administrators can always sign in. Put your address on the allowlist to be safe.', 'internetdata'),
                    implode(', ', $flagged),
                )));
            }
        }
        $private = $this->plugin->diagnostics->recent()['address']['ip'] ?? '';
        if (is_string($private) && $private !== '' && !Bogon::isBogon($ip)) {
            printf('<p class="inetd-warn">%s</p>', esc_html(sprintf(
                /* translators: %s: an IP address. */
                __('In the last day some visitors reached the plugin as %s, a private address, so nobody behind it was checked. A proxy sits in front of those requests: set Visitor address under Advanced to trust it.', 'internetdata'),
                $private,
            )));
        }
        $peer = $server['REMOTE_ADDR'] ?? '';
        $forwarded = array_filter(ClientAddress::HEADERS, static fn (string $var): bool => ($server[$var] ?? '') !== '');
        if ($forwarded !== [] && $ip === \InternetData\WordPress\Address::normalize($peer) && !Bogon::isBogon($ip)) {
            printf('<p class="description">%s</p>', esc_html(sprintf(
                /* translators: 1: an IP address; 2: header names. */
                __('Your request came from %1$s and carried %2$s, which the plugin does not believe from that address. If %1$s is your CDN or proxy, set Visitor address under Advanced to trust it.', 'internetdata'),
                $peer,
                implode(', ', array_keys($forwarded)),
            )));
        }
        echo '</div>';
    }

    private function renderSignals(Settings $settings, ?string $tier): void
    {
        echo '<h2>' . esc_html__('What to block', 'internetdata') . '</h2>';
        echo '<p class="description">' . esc_html__('Every area below acts on these, unless you give it signals of its own.', 'internetdata') . '</p>';
        if (array_filter(Signals::keys(), static fn (string $s): bool => !Signals::served($s, $tier)) !== []) {
            echo '<p class="description">' . esc_html__('A signal marked "Paid plan or trial" is answered on every paid plan, and on a free account during its trial. Otherwise it blocks nobody.', 'internetdata') . '</p>';
        }
        $gaps = $this->plugin->diagnostics->recent()['gaps']['signals'] ?? [];
        if (is_array($gaps) && $gaps !== []) {
            $capped = isset($this->plugin->diagnostics->recent()['cap']);
            printf('<div class="notice notice-warning inline"><p>%s</p></div>', esc_html(sprintf(
                $capped
                    /* translators: %s: signals, such as "Residential proxies, Hosting and cloud servers". */
                    ? __('Ticked, but not answered past the overage cap, so they block nobody: %s.', 'internetdata')
                    /* translators: %s: signals, such as "Residential proxies, Hosting and cloud servers". */
                    : __('Ticked, but not answered on this site\'s plan, so they block nobody: %s.', 'internetdata'),
                implode(', ', array_map([Signals::class, 'label'], $gaps)),
            )));
        }
        $this->signalBoxes(Settings::OPTION . '[signals][]', $settings->get('signals'), $tier, 'inetd-signals', $settings);
    }

    /**
     * @param list<string> $checked
     * @param Settings|null $countries The settings to read the country list from, where it is edited.
     */
    private function signalBoxes(string $name, array $checked, ?string $tier, string $class, ?Settings $countries = null): void
    {
        printf('<fieldset class="%s">', esc_attr($class));
        foreach (Signals::keys() as $signal) {
            $badge = '';
            if (!Signals::served($signal, $tier)) {
                $badge = __('Paid plan or trial', 'internetdata');
            }
            $list = '';
            if ($signal === 'country' && $countries !== null) {
                $mode = $countries->get('countries_mode');
                $list = sprintf(
                    '<select name="%1$s[countries_mode]" class="inetd-countries-mode" aria-label="%2$s"><option value="%3$s"%4$s>%5$s</option><option value="%6$s"%7$s>%8$s</option></select>',
                    esc_attr(Settings::OPTION),
                    esc_attr__('What the Countries signal does with the countries listed', 'internetdata'),
                    esc_attr(Settings::COUNTRIES_BLOCK),
                    selected($mode, Settings::COUNTRIES_BLOCK, false),
                    esc_html__('Block these countries', 'internetdata'),
                    esc_attr(Settings::COUNTRIES_ALLOW),
                    selected($mode, Settings::COUNTRIES_ALLOW, false),
                    esc_html__('Allow only these countries', 'internetdata'),
                ) . sprintf(
                    '<input type="text" name="%s[countries]" value="%s" class="regular-text code inetd-countries" placeholder="%s" aria-label="%s">',
                    esc_attr(Settings::OPTION),
                    esc_attr($countries->get('countries')),
                    esc_attr__('CN, RU', 'internetdata'),
                    esc_attr__('Two-letter country codes, separated by commas', 'internetdata'),
                );
            }
            printf(
                '<label class="inetd-signal"><input type="checkbox" name="%s" value="%s"%s> <strong>%s</strong>%s<span class="description">%s</span>%s</label>',
                esc_attr($name),
                esc_attr($signal),
                checked(in_array($signal, $checked, true), true, false),
                esc_html(Signals::label($signal)),
                $badge === '' ? '' : ' <span class="inetd-badge">' . esc_html($badge) . '</span>',
                esc_html(Signals::hint($signal)),
                $list, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
            );
        }
        echo '</fieldset>';
    }

    private function renderAreas(Settings $settings, ?string $tier): void
    {
        echo '<h2>' . esc_html__('Where to check', 'internetdata') . '</h2>';
        echo '<p class="description">' . esc_html__('Start an area on Log only to see who it would catch on the Activity tab before it blocks anyone.', 'internetdata') . '</p>';
        echo '<table class="form-table inetd-areas" role="presentation"><tbody>';
        foreach (array_keys(Settings::AREAS) as $area) {
            if ($area === 'checkout' && !Checkout::available()) {
                continue;
            }
            if ($area === 'forms' && !Forms::available()) {
                continue;
            }
            $this->renderArea($settings, $area, $tier);
        }
        echo '</tbody></table>';
    }

    private function renderArea(Settings $settings, string $area, ?string $tier): void
    {
        [$title, $about] = self::areaText($area);
        $base = Settings::OPTION . '[areas][' . $area . ']';
        $own = $settings->get('areas')[$area]['signals'];
        printf('<tr class="inetd-area" data-area="%s"><th scope="row"><label for="inetd-%s">%s</label></th><td>', esc_attr($area), esc_attr($area), esc_html($title));
        printf('<select id="inetd-%s" name="%s[action]" class="inetd-action">', esc_attr($area), esc_attr($base));
        foreach (Settings::AREAS[$area] as $action) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($action),
                selected($settings->action($area), $action, false),
                esc_html(self::actionLabel($area, $action)),
            );
        }
        echo '</select>';
        printf('<p class="description">%s</p>', esc_html($about));
        echo '<div class="inetd-when-on">';
        if ($area === 'login') {
            printf(
                '<p><label><input type="checkbox" name="%s[admins_sign_in]" value="1"%s> %s</label></p>',
                esc_attr(Settings::OPTION),
                checked((bool) $settings->get('admins_sign_in'), true, false),
                esc_html__('Administrators can always sign in, whatever their connection', 'internetdata'),
            );
        }
        if ($area === 'checkout') {
            printf(
                '<p><label><input type="checkbox" name="%s[checkout_billing]" value="1"%s> %s</label><br><span class="description">%s</span></p>',
                esc_attr(Settings::OPTION),
                checked((bool) $settings->get('checkout_billing'), true, false),
                esc_html__('Also catch an order whose billing country is not the country its buyer connects from', 'internetdata'),
                esc_html__('Works on every plan. Travelers and people living abroad trip it too, so it suits holding an order better than refusing one.', 'internetdata'),
            );
        }
        if ($area === 'pages') {
            $this->renderPagesScope($settings);
        }
        printf(
            '<p><label><input type="checkbox" class="inetd-own" name="%s[own_signals]" value="1"%s> %s</label></p>',
            esc_attr($base),
            checked($own !== null, true, false),
            esc_html__('Block on different signals here', 'internetdata'),
        );
        $this->signalBoxes($base . '[signals][]', $own ?? $settings->get('signals'), $tier, 'inetd-signals inetd-own-signals');
        echo '</div></td></tr>';
    }

    private function renderPagesScope(Settings $settings): void
    {
        $name = Settings::OPTION;
        $scope = $settings->get('pages_scope');
        echo '<fieldset class="inetd-scope">';
        printf(
            '<p><label><input type="radio" name="%s[pages_scope]" value="site"%s> %s</label><br>',
            esc_attr($name),
            checked($scope, 'site', false),
            esc_html__('The whole site', 'internetdata'),
        );
        printf(
            '<label><input type="radio" name="%s[pages_scope]" value="selected"%s> %s</label></p>',
            esc_attr($name),
            checked($scope, 'selected', false),
            esc_html__('Only these:', 'internetdata'),
        );
        echo '<div class="inetd-selected"><p>';
        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if ($type->name === 'attachment') {
                continue;
            }
            printf(
                '<label class="inetd-inline"><input type="checkbox" name="%s[pages_post_types][]" value="%s"%s> %s</label> ',
                esc_attr($name),
                esc_attr($type->name),
                checked(in_array($type->name, $settings->get('pages_post_types'), true), true, false),
                esc_html($type->labels->name),
            );
        }
        printf(
            '</p><p><label for="inetd-paths">%s</label><br><textarea id="inetd-paths" name="%s[pages_paths]" rows="3" class="large-text code" placeholder="/members/">%s</textarea></p></div>',
            esc_html__('Pages whose address starts with any of these paths, one per line:', 'internetdata'),
            esc_attr($name),
            esc_textarea($settings->get('pages_paths')),
        );
        printf(
            '<p><label for="inetd-redirect">%s</label><br><input type="url" id="inetd-redirect" name="%s[pages_redirect]" value="%s" class="regular-text" placeholder="https://"></p>',
            esc_html__('Send refused visitors to this page instead of showing the message (optional):', 'internetdata'),
            esc_attr($name),
            esc_attr($settings->get('pages_redirect')),
        );
        printf(
            '<p><label for="inetd-geo">%s</label><br><textarea id="inetd-geo" name="%s[pages_geo]" rows="3" class="large-text code" placeholder="DE, AT, CH https://example.de/">%s</textarea><br><span class="description">%s</span></p>',
            esc_html__('Geo redirects: send visitors from these countries to another address, one rule per line, the two-letter codes and then the address (optional):', 'internetdata'),
            esc_attr($name),
            esc_textarea($settings->get('pages_geo')),
            esc_html__('Works on every plan. Visitors the area refuses are refused first; Log only records who would be sent, without sending them.', 'internetdata'),
        );
        printf(
            '<p><label><input type="checkbox" name="%s[pages_uncached]" value="1"%s> %s</label><br><span class="description">%s</span></p>',
            esc_attr($name),
            checked((bool) $settings->get('pages_uncached'), true, false),
            esc_html__('Keep these pages out of the page cache, so the server checks every visit', 'internetdata'),
            esc_html__('Otherwise a cached page is checked in the visitor\'s browser, which works with any cache but not for a visitor who turns scripts off. Signed-in users and search engine crawlers are never refused.', 'internetdata'),
        );
        echo '</fieldset>';
    }

    private function renderMessage(Settings $settings): void
    {
        echo '<h2>' . esc_html__('What a refused visitor sees', 'internetdata') . '</h2>';
        printf(
            '<p><label for="inetd-message" class="screen-reader-text">%s</label><textarea id="inetd-message" name="%s[message]" rows="3" class="large-text" placeholder="%s">%s</textarea></p>',
            esc_html__('Message', 'internetdata'),
            esc_attr(Settings::OPTION),
            esc_attr((new Settings())->message()),
            esc_textarea($settings->get('message')),
        );
    }

    private function renderAllowlist(Settings $settings): void
    {
        echo '<h2>' . esc_html__('Allowlist', 'internetdata') . '</h2>';
        printf(
            '<p><label for="inetd-allowlist">%s</label></p><p><textarea id="inetd-allowlist" name="%s[allowlist]" rows="5" class="large-text code" placeholder="203.0.113.7&#10;198.51.100.0/24&#10;AS64500 # our office VPN">%s</textarea></p>',
            esc_html__('Never refuse these. One per line: an address, a block in CIDR form, or a network by its AS number. Text after # is a note.', 'internetdata'),
            esc_attr(Settings::OPTION),
            esc_textarea($settings->get('allowlist')),
        );
    }

    private function renderAdvanced(Settings $settings): void
    {
        $name = Settings::OPTION;
        echo '<details class="inetd-advanced"><summary><h2>' . esc_html__('Advanced', 'internetdata') . '</h2></summary>';
        echo '<table class="form-table" role="presentation"><tbody>';
        printf('<tr><th scope="row"><label for="inetd-proxy">%s</label></th><td>', esc_html__('Visitor address', 'internetdata'));
        printf('<select id="inetd-proxy" name="%s[proxy]">', esc_attr($name));
        $modes = [
            ClientAddress::AUTO => __('Work it out (recommended)', 'internetdata'),
            ClientAddress::DIRECT => __('Visitors connect to this server directly', 'internetdata'),
            ClientAddress::CLOUDFLARE => __('Behind Cloudflare', 'internetdata'),
            ClientAddress::CUSTOM => __('Behind another proxy, CDN or load balancer', 'internetdata'),
        ];
        foreach ($modes as $mode => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($mode), selected($settings->get('proxy'), $mode, false), esc_html($label));
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Working it out believes Cloudflare from Cloudflare\'s own addresses, and a proxy on your server\'s private network. Anything else in front of the site has to be named here, or every visitor looks like it.', 'internetdata') . '</p>';
        echo '<div class="inetd-custom-proxy">';
        printf('<p><label for="inetd-header">%s</label> <select id="inetd-header" name="%s[proxy_header]">', esc_html__('It names the visitor in', 'internetdata'), esc_attr($name));
        foreach (array_keys(ClientAddress::HEADERS) as $header) {
            printf('<option value="%s"%s>%s</option>', esc_attr($header), selected($settings->get('proxy_header'), $header, false), esc_html($header));
        }
        printf(
            '</select></p><p><label for="inetd-trusted">%s</label><br><textarea id="inetd-trusted" name="%s[proxy_trusted]" rows="3" class="large-text code" placeholder="192.0.2.0/24">%s</textarea></p></div></td></tr>',
            esc_html__('Its addresses, one address or CIDR block per line:', 'internetdata'),
            esc_attr($name),
            esc_textarea($settings->get('proxy_trusted')),
        );
        printf(
            '<tr><th scope="row"><label for="inetd-hits">%s</label></th><td><input type="number" min="1" max="1000" id="inetd-hits" name="%s[min_hits]" value="%d" class="small-text"><p class="description">%s</p></td></tr>',
            esc_html__('Proxy sightings', 'internetdata'),
            esc_attr($name),
            (int) $settings->get('min_hits'),
            esc_html__('Residential, mobile and datacenter proxies count only once an address has been seen in a proxy pool this many times. 1 counts every sighting; a higher number spares home addresses a proxy used once. Counting sightings needs a paid plan.', 'internetdata'),
        );
        printf('<tr><th scope="row">%s</th><td><fieldset>', esc_html__('When a check fails', 'internetdata'));
        printf(
            '<label><input type="radio" name="%s[fail_closed]" value="0"%s> %s</label><br><label><input type="radio" name="%s[fail_closed]" value="1"%s> %s</label>',
            esc_attr($name),
            checked((bool) $settings->get('fail_closed'), false, false),
            esc_html__('Let the visitor through (recommended)', 'internetdata'),
            esc_attr($name),
            checked((bool) $settings->get('fail_closed'), true, false),
            esc_html__('Refuse the visitor', 'internetdata'),
        );
        echo '<p class="description">' . esc_html__('A check fails when InternetData cannot be reached in time, or the allowance is spent.', 'internetdata') . '</p></fieldset></td></tr>';
        printf(
            '<tr><th scope="row"><label for="inetd-days">%s</label></th><td><input type="number" min="1" max="365" id="inetd-days" name="%s[log_days]" value="%d" class="small-text"> %s</td></tr>',
            esc_html__('Keep activity for', 'internetdata'),
            esc_attr($name),
            (int) $settings->get('log_days'),
            esc_html__('days', 'internetdata'),
        );
        echo '</tbody></table></details>';
    }

    public function saveKey(): void
    {
        $this->guard();
        check_admin_referer('internetdata_key');
        $key = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';
        if ($key === '') {
            $this->back('nokey');
        }
        try {
            Detector::newClient($key, 10.0, 1)->myEntitlement();
        } catch (InternetDataException $e) {
            $this->back('badkey', $e->getMessage());
        }
        update_option(Settings::KEY_OPTION, $key, true);
        delete_transient(self::ACCOUNT_CACHE);
        $this->plugin->diagnostics->clear('lookup');
        $this->back('connected');
    }

    public function disconnect(): void
    {
        $this->guard();
        check_admin_referer('internetdata_disconnect');
        delete_option(Settings::KEY_OPTION);
        delete_transient(self::ACCOUNT_CACHE);
        $this->plugin->diagnostics->clear('lookup');
        $this->plugin->diagnostics->clear('gaps');
        $this->back('disconnected');
    }

    public function allow(): void
    {
        $this->guard();
        check_admin_referer('internetdata_allow');
        $ip = isset($_GET['ip']) ? \InternetData\WordPress\Address::normalize(sanitize_text_field(wp_unslash($_GET['ip']))) : null;
        if ($ip !== null) {
            $stored = get_option(Settings::OPTION, []);
            $stored = is_array($stored) ? $stored : [];
            $list = (string) ($stored['allowlist'] ?? '');
            if (!Allowlist::parse($list)->coversAddress($ip)) {
                $stored['allowlist'] = rtrim($list) . ($list === '' ? '' : "\n") . $ip . ' # ' . wp_date('Y-m-d');
                update_option(Settings::OPTION, $stored);
            }
        }
        $this->back('allowed', (string) $ip, 'activity');
    }

    public function clear(): void
    {
        $this->guard();
        check_admin_referer('internetdata_clear');
        $this->plugin->log->clear();
        $this->back('cleared', '', 'activity');
    }

    /**
     * The account behind the key, read at most every five minutes.
     *
     * @return Entitlement|InternetDataException|null
     */
    private function account()
    {
        $key = $this->plugin->settings->apiKey();
        if ($key === null) {
            return null;
        }
        $cached = get_transient(self::ACCOUNT_CACHE);
        // isset: an account an older release cached has no plan name, so it is read again.
        if (is_array($cached) && ($cached['key'] ?? '') === hash('sha256', $key) && $cached['account'] instanceof Entitlement
            && isset($cached['account']->plan->name)) {
            return $cached['account'];
        }
        try {
            $account = Detector::newClient($key, 10.0, 1)->myEntitlement();
        } catch (InternetDataException $e) {
            return $e;
        }
        set_transient(self::ACCOUNT_CACHE, ['key' => hash('sha256', $key), 'account' => $account], 5 * MINUTE_IN_SECONDS);
        return $account;
    }

    /** A one-click form posting to admin-post.php, with its nonce and an optional confirmation. */
    public function postButton(string $action, string $label, string $class, string $confirm = '', array $fields = []): void
    {
        printf(
            '<form method="post" action="%s" class="inetd-inline-form"%s>',
            esc_url(admin_url('admin-post.php')),
            $confirm === '' ? '' : ' data-confirm="' . esc_attr($confirm) . '"',
        );
        printf('<input type="hidden" name="action" value="%s">', esc_attr($action));
        foreach ($fields as $field => $value) {
            printf('<input type="hidden" name="%s" value="%s">', esc_attr($field), esc_attr($value));
        }
        wp_nonce_field($action);
        printf('<button type="submit" class="button %s">%s</button></form>', esc_attr($class), esc_html($label));
    }

    private function guard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to change this.', 'internetdata'), '', ['response' => 403]);
        }
    }

    private function back(string $done, string $detail = '', string $tab = 'settings'): never
    {
        $url = add_query_arg(
            array_filter(['page' => self::SLUG, 'tab' => $tab === 'settings' ? '' : $tab, 'inetd' => $done, 'inetd_detail' => $detail]),
            admin_url('options-general.php'),
        );
        wp_safe_redirect($url);
        exit;
    }

    /** The result of an action this page posted, shown once after its redirect. */
    private function flash(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- shows the result of an action already checked.
        $done = isset($_GET['inetd']) ? sanitize_key(wp_unslash($_GET['inetd'])) : '';
        $detail = isset($_GET['inetd_detail']) ? sanitize_text_field(wp_unslash($_GET['inetd_detail'])) : '';
        // phpcs:enable
        $messages = [
            'connected' => ['success', __('Connected. The plugin now checks with your own allowance.', 'internetdata')],
            'disconnected' => ['success', __('Disconnected. The plugin is back to checking without an account, which catches no VPNs or proxies.', 'internetdata')],
            'nokey' => ['error', __('Paste a key first.', 'internetdata')],
            /* translators: %s: the reason, as InternetData gave it. */
            'badkey' => ['error', sprintf(__('InternetData did not accept that key: %s', 'internetdata'), $detail)],
            /* translators: %s: an IP address. */
            'allowed' => ['success', sprintf(__('%s is on the allowlist and will not be refused again.', 'internetdata'), $detail)],
            'cleared' => ['success', __('Activity cleared.', 'internetdata')],
        ];
        if (isset($messages[$done])) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($messages[$done][0]), esc_html($messages[$done][1]));
        }
    }

    /** A console link, tagged so a sign-up that starts from the plugin is counted as the plugin's. */
    public static function console(string $path, string $content): string
    {
        return self::tagged('https://app.internetdata.io' . $path, $content);
    }

    public static function tagged(string $url, string $content): string
    {
        return add_query_arg([
            'utm_source' => 'wordpress',
            'utm_medium' => 'referral',
            'utm_campaign' => 'plugin',
            'utm_content' => $content,
        ], $url);
    }

    /** @return array{0: string, 1: string} */
    public static function areaText(string $area): array
    {
        return match ($area) {
            'login' => [__('Sign-in', 'internetdata'), __('The login page, front-end login forms such as WooCommerce\'s, and XML-RPC.', 'internetdata')],
            'register' => [__('New accounts', 'internetdata'), __('WordPress\'s registration page, a network\'s sign-up and WooCommerce\'s My Account.', 'internetdata')],
            'lostpassword' => [__('Password resets', 'internetdata'), __('Asking for a reset email, on WordPress and WooCommerce.', 'internetdata')],
            'comment' => [__('Comments', 'internetdata'), __('Comments from visitors who are not signed in.', 'internetdata')],
            'forms' => [__('Contact forms', 'internetdata'), __('Contact Form 7 and WPForms submissions.', 'internetdata')],
            'checkout' => [__('Checkout', 'internetdata'), __('WooCommerce orders, classic and block checkout. A held order is paid as usual and then waits On hold, with a note saying why.', 'internetdata')],
            'pages' => [__('Pages', 'internetdata'), __('Viewing the site, for visitors who are not signed in, and sending them on by their country.', 'internetdata')],
            default => [$area, ''],
        };
    }

    public static function actionLabel(string $area, string $action): string
    {
        return match ($action) {
            'off' => __('Off', 'internetdata'),
            'log' => __('Log only', 'internetdata'),
            'hold' => $area === 'checkout' ? __('Hold the order for review', 'internetdata') : __('Hold for moderation', 'internetdata'),
            'spam' => __('Mark as spam', 'internetdata'),
            'block' => $area === 'checkout' ? __('Refuse the order', 'internetdata') : __('Block', 'internetdata'),
            'redirect' => __('Redirect', 'internetdata'),
            default => $action,
        };
    }
}
