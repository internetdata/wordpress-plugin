<?php

declare(strict_types=1);

namespace InternetData\WordPress;

use InternetData\WordPress\Vendor\InternetData\Result;

defined('ABSPATH') || exit;

/**
 * What the plugin can act on, in the order the settings show it.
 *
 * Each signal is one member of a lookup answer, and which plans answer it is the API's to say. On
 * Unlimited, the free plan, with a key or without one, a lookup carries Tor, relays, where the address
 * is to the country and the network it belongs to; every paid plan answers every field, VPNs, proxies
 * and hosting among them, with the detail behind each. A key on the free plan also gets a daily trial:
 * its account's first 100 lookups each UTC day answer as a paid plan's, and the rest as Unlimited's,
 * which `trial_expired` says.
 */
final class Signals
{
    public const NEEDS_NOTHING = 'none';
    public const NEEDS_PAID = 'paid';

    /** @var array<string, array{member: string, detail: string, needs: string}> */
    public const ALL = [
        'country' => ['member' => 'location', 'detail' => 'location', 'needs' => self::NEEDS_NOTHING],
        'vpn' => ['member' => 'isVpn', 'detail' => 'vpn', 'needs' => self::NEEDS_PAID],
        'tor' => ['member' => 'isTor', 'detail' => 'tor', 'needs' => self::NEEDS_NOTHING],
        'pubproxy' => ['member' => 'isPubproxy', 'detail' => 'pubproxy', 'needs' => self::NEEDS_PAID],
        'resproxy' => ['member' => 'isResproxy', 'detail' => 'resproxy', 'needs' => self::NEEDS_PAID],
        'mobproxy' => ['member' => 'isMobproxy', 'detail' => 'mobproxy', 'needs' => self::NEEDS_PAID],
        'dcproxy' => ['member' => 'isDcproxy', 'detail' => 'dcproxy', 'needs' => self::NEEDS_PAID],
        // A paid plan answers is_hosting; without one it is read by its network's type, which every answer carries.
        'hosting' => ['member' => 'isHosting', 'detail' => 'hosting', 'needs' => self::NEEDS_NOTHING],
        'relay' => ['member' => 'isRelay', 'detail' => 'relay', 'needs' => self::NEEDS_NOTHING],
    ];

    /** Signals a pool sighting count applies to, where one sighting can be a shared address. */
    public const COUNTED = ['resproxy', 'mobproxy', 'dcproxy'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(string $signal): string
    {
        return match ($signal) {
            'vpn' => __('VPNs', 'internetdata'),
            'tor' => __('Tor', 'internetdata'),
            'pubproxy' => __('Public proxies', 'internetdata'),
            'resproxy' => __('Residential proxies', 'internetdata'),
            'mobproxy' => __('Mobile proxies', 'internetdata'),
            'dcproxy' => __('Datacenter proxies', 'internetdata'),
            'hosting' => __('Hosting and cloud servers', 'internetdata'),
            'relay' => __('Privacy relays', 'internetdata'),
            'country' => __('Countries', 'internetdata'),
            'billing' => __('Billing country differs', 'internetdata'),
            'geo' => __('Geo redirect', 'internetdata'),
            'unavailable' => __('Check unavailable', 'internetdata'),
            'custom' => __('Custom rule', 'internetdata'),
            default => $signal,
        };
    }

    public static function hint(string $signal): string
    {
        return match ($signal) {
            'vpn' => __('Commercial and self-hosted VPN servers.', 'internetdata'),
            'tor' => __('Tor exit and relay nodes.', 'internetdata'),
            'pubproxy' => __('Open proxies anyone can connect through.', 'internetdata'),
            'resproxy' => __('Home connections rented out as proxies.', 'internetdata'),
            'mobproxy' => __('Phone connections rented out as proxies. Carriers share these addresses with ordinary customers.', 'internetdata'),
            'dcproxy' => __('Proxies running in datacenters.', 'internetdata'),
            'hosting' => __('Servers at hosting and cloud providers, where bots and scripts run. Without a paid plan or the daily trial, judged by the network an address belongs to.', 'internetdata'),
            'relay' => __('iCloud Private Relay and company web gateways. Mostly ordinary people.', 'internetdata'),
            'country' => __('Where the visitor\'s address is, whatever their browser says. Works on every plan.', 'internetdata'),
            default => '',
        };
    }

    /**
     * The block condition for a set of signals, in the SDK's condition language: a list, so any one
     * signal is enough. Given the answer, it asks what that answer can tell: an Unlimited answer carries
     * no `is_hosting`, so hosting is read off the type of the network the address belongs to. A relay is
     * judged by the relay signal alone: iCloud Private Relay, WARP and the company gateways run on
     * hosting networks, and most of their addresses answer `is_hosting` too.
     *
     * @param list<string> $signals
     * @param list<string> $countries
     * @return list<array<string, mixed>>
     */
    public static function condition(
        array $signals,
        int $minHits,
        ?Result $answer = null,
        array $countries = [],
        string $countriesMode = Settings::COUNTRIES_BLOCK,
    ): array {
        $condition = [];
        foreach ($signals as $signal) {
            $one = self::one($signal, $minHits, $answer, $countries, $countriesMode);
            if ($one !== null) {
                $condition[] = $one;
            }
        }
        return $condition;
    }

    /**
     * One signal's term, or null for one that constrains nothing: countries with none listed, or, when
     * only the listed countries are let in, a visitor from one of them, or hosting for an answer that is
     * a relay.
     *
     * Letting in only some countries means refusing every other, which a condition cannot say: it
     * states what to act on, never what to spare. So that term is the answer's own country when it is
     * not on the list, and nothing when it is, or when the answer names no country.
     *
     * @param list<string> $countries
     * @return array<string, mixed>|null
     */
    public static function one(
        string $signal,
        int $minHits,
        ?Result $answer = null,
        array $countries = [],
        string $countriesMode = Settings::COUNTRIES_BLOCK,
    ): ?array {
        if ($signal === 'country') {
            if ($countries === []) {
                return null;
            }
            if ($countriesMode !== Settings::COUNTRIES_ALLOW) {
                return ['location' => ['country' => $countries]];
            }
            $country = $answer?->location->country ?? '';
            return $country === '' || in_array($country, $countries, true) ? null : ['location' => ['country' => [$country]]];
        }
        if ($signal === 'hosting' && $answer !== null && $answer->isRelay === true) {
            return null;
        }
        if ($signal === 'hosting' && $answer !== null && $answer->isHosting === null) {
            return ['asn' => ['type' => 'hosting']];
        }
        $def = self::ALL[$signal];
        $one = [$def['member'] => true];
        if ($minHits > 1 && in_array($signal, self::COUNTED, true)) {
            $one[$def['detail']] = ['hits' => ['gte' => $minHits]];
        }
        return $one;
    }

    /**
     * Whether a plan tier answers a signal on every lookup; null tier is a client with no key, and
     * `free` the free plan's, which answers every signal only for its account's first 100 lookups a day.
     */
    public static function served(string $signal, ?string $tier): bool
    {
        return self::ALL[$signal]['needs'] === self::NEEDS_NOTHING || ($tier !== null && $tier !== 'free');
    }
}
