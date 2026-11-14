<?php

declare(strict_types=1);

namespace InternetData\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use InternetData\WordPress\Settings;
use InternetData\WordPress\Signals;
use InternetData\WordPress\Vendor\InternetData\AbuseContact;
use InternetData\WordPress\Vendor\InternetData\Asn;
use InternetData\WordPress\Vendor\InternetData\ClassDetail;
use InternetData\WordPress\Vendor\InternetData\Company;
use InternetData\WordPress\Vendor\InternetData\Location;
use InternetData\WordPress\Vendor\InternetData\Middleware\Condition;
use InternetData\WordPress\Vendor\InternetData\ProxyDetail;
use InternetData\WordPress\Vendor\InternetData\Result;

final class SignalsTest extends TestCase
{
    public function testConditionIsAnyOf(): void
    {
        $this->assertSame(
            [['isVpn' => true], ['isTor' => true]],
            Signals::condition(['vpn', 'tor'], 1),
        );
    }

    public function testSightingsBoundOnlyTheCountedProxies(): void
    {
        $this->assertSame(
            [['isResproxy' => true, 'resproxy' => ['hits' => ['gte' => 3]]], ['isHosting' => true]],
            Signals::condition(['resproxy', 'hosting'], 3),
        );
    }

    /** The condition is the SDK's own language, so the SDK's matcher decides it. */
    public function testTheSdkMatchesIt(): void
    {
        $seenTwice = self::answer(['isVpn' => false, 'isResproxy' => true, 'resproxy' => new ProxyDetail(provider: 'ippeak', hits: 2)]);
        $this->assertTrue(Condition::matches(Signals::condition(['resproxy'], 2), $seenTwice));
        $this->assertFalse(Condition::matches(Signals::condition(['resproxy'], 3), $seenTwice));
        $this->assertFalse(Condition::matches(Signals::condition(['vpn'], 1), $seenTwice));
    }

    /** Unlimited answers Tor and relays, and no VPN: an answer without a key cannot match one. */
    public function testAnUnlimitedAnswerLacksTheVpnAndProxies(): void
    {
        $unlimited = self::answer(['isTor' => true]);
        $this->assertTrue(Condition::matches(Signals::condition(['tor'], 1, $unlimited), $unlimited));
        $this->assertSame(['isVpn'], Condition::missingMembers(Signals::condition(['vpn'], 1, $unlimited), $unlimited));
        $this->assertSame(['isResproxy'], Condition::missingMembers(Signals::condition(['resproxy'], 1, $unlimited), $unlimited));
    }

    public function testWhichPlansServeWhat(): void
    {
        foreach (['country', 'tor', 'relay', 'hosting'] as $signal) {
            $this->assertTrue(Signals::served($signal, null), "{$signal} without a key");
        }
        foreach (['vpn', 'pubproxy', 'resproxy', 'mobproxy', 'dcproxy'] as $signal) {
            $this->assertFalse(Signals::served($signal, null), "{$signal} without a key");
            $this->assertFalse(Signals::served($signal, 'free'), "{$signal} on a free-plan key, past its daily trial");
            $this->assertTrue(Signals::served($signal, 'starter'), "{$signal} on a paid plan");
        }
    }

    /** An answer that flags a proxy but carries no sightings reports a sighting count as a gap. */
    public function testAnAnswerWithNoSightingsCannotCountThem(): void
    {
        $answer = self::answer(['isResproxy' => true]);
        $this->assertTrue(Condition::matches(Signals::condition(['resproxy'], 1, $answer), $answer));
        $counted = Signals::condition(['resproxy'], 3, $answer);
        $this->assertFalse(Condition::matches($counted, $answer));
        $this->assertSame(['resproxy'], Condition::missingMembers($counted, $answer));
    }

    /** An Unlimited answer carries no is_hosting, but its network's type answers the same question. */
    public function testHostingWithoutAPaidPlanReadsTheNetworksType(): void
    {
        $unlimited = self::answer(['asn' => self::asn('hosting')]);
        $condition = Signals::condition(['hosting'], 1, $unlimited);
        $this->assertSame([['asn' => ['type' => 'hosting']]], $condition);
        $this->assertTrue(Condition::matches($condition, $unlimited));
        $this->assertSame([], Condition::missingMembers($condition, $unlimited));
        $paid = self::answer(['isHosting' => false, 'hosting' => new ClassDetail(), 'asn' => self::asn('hosting')]);
        $this->assertFalse(Condition::matches(Signals::condition(['hosting'], 1, $paid), $paid), 'a paid answer is taken at its word');
    }

    /** WARP, iCloud Private Relay and Zscaler run on hosting networks: a relay is judged as a relay alone. */
    public function testARelayOnAHostingNetworkIsNotHosting(): void
    {
        $warp = new Asn(asn: 13335, name: 'Cloudflare, Inc.', domain: 'cloudflare.com', type: 'hosting', prefix: '104.28.0.0/16');
        $unlimited = self::answer(['isRelay' => true, 'asn' => $warp]);
        $this->assertSame([], Signals::condition(['hosting'], 1, $unlimited));
        $this->assertNull(Signals::one('hosting', 1, $unlimited));
        $paid = self::answer(['isRelay' => true, 'isHosting' => true, 'hosting' => new ClassDetail(), 'asn' => $warp]);
        $this->assertSame([], Signals::condition(['hosting'], 1, $paid));
        $condition = Signals::condition(['vpn', 'hosting', 'relay'], 1, $paid);
        $this->assertSame([['isVpn' => true], ['isRelay' => true]], $condition);
        $this->assertTrue(Condition::matches($condition, $paid), 'the relay signal still matches it');
    }

    public function testBlockingCountries(): void
    {
        $sweden = self::answer(['location' => self::location('SE')]);
        $this->assertTrue(Condition::matches(Signals::condition(['country'], 1, $sweden, ['NO', 'SE']), $sweden));
        $this->assertFalse(Condition::matches(Signals::condition(['country'], 1, $sweden, ['DE']), $sweden));
        $this->assertSame([], Signals::condition(['country'], 1, $sweden, []), 'no countries listed constrains nothing');
    }

    /** Allowing only some countries refuses every other, and a visitor whose country is unknown passes. */
    public function testAllowingOnlySomeCountries(): void
    {
        $allow = static fn (Result $r): array => Signals::condition(['country'], 1, $r, ['DE', 'AT'], Settings::COUNTRIES_ALLOW);
        $germany = self::answer(['location' => self::location('DE')]);
        $this->assertSame([], $allow($germany), 'a listed country constrains nothing');
        $sweden = self::answer(['location' => self::location('SE')]);
        $this->assertSame([['location' => ['country' => ['SE']]]], $allow($sweden));
        $this->assertTrue(Condition::matches($allow($sweden), $sweden));
        $nowhere = self::answer(['location' => self::location('')]);
        $this->assertSame([], $allow($nowhere), 'an answer with no country is let through');
        $this->assertSame([], Signals::condition(['country'], 1, null, ['DE'], Settings::COUNTRIES_ALLOW), 'no answer, no term');
    }

    public function testEverySignalIsAResultMember(): void
    {
        foreach (Signals::ALL as $signal => $def) {
            $this->assertTrue(property_exists(Result::class, $def['member']), "{$signal}'s member");
            $this->assertTrue(property_exists(Result::class, $def['detail']), "{$signal}'s detail");
        }
    }

    /**
     * An Unlimited answer for a home connection in Germany, with the given members over it.
     *
     * @param array<string, mixed> $members
     */
    public static function answer(array $members = []): Result
    {
        return new Result(...array_merge([
            'ip' => '81.2.69.142',
            'isBogon' => false,
            'isTor' => false,
            'isRelay' => false,
            'isAnycast' => false,
            'isCarrier' => false,
            'isSatellite' => false,
            'isSsh' => false,
            'isSmtp' => false,
            'isWeb' => false,
            'isDnsResolver' => false,
            'isBittorrent' => false,
            'tor' => new ClassDetail(),
            'relay' => new ClassDetail(),
            'location' => self::location('DE'),
            'asn' => self::asn('isp'),
            'company' => new Company(name: 'Deutsche Telekom AG', domain: 'telekom.de'),
            'hostname' => '',
            'abuse' => new AbuseContact(email: 'abuse@telekom.de'),
        ], $members));
    }

    public static function location(string $country): Location
    {
        return new Location(
            continent: 'EU',
            continentName: 'Europe',
            country: $country,
            countryName: '',
            timezone: '',
            currencyCode: '',
            currencyName: '',
            currencySymbol: '',
            flagEmoji: '',
            flagUnicode: '',
        );
    }

    public static function asn(string $type): Asn
    {
        return new Asn(asn: 3320, name: 'Deutsche Telekom AG', domain: 'telekom.de', type: $type, prefix: '81.2.69.0/24');
    }
}
