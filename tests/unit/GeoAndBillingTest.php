<?php

declare(strict_types=1);

namespace InternetData\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use InternetData\WordPress\Settings;
use InternetData\WordPress\Verdict;
use InternetData\WordPress\Visit;

final class GeoAndBillingTest extends TestCase
{
    public function testGeoRules(): void
    {
        $text = "de, AT ch https://example.de/\n\n# a note\nUS https://example.com/us?x=1 # the US store\nFR\nhttps://example.fr/\nXX ftp://example.org/";
        $this->assertSame([
            ['countries' => ['DE', 'AT', 'CH'], 'url' => 'https://example.de/'],
            ['countries' => ['US'], 'url' => 'https://example.com/us?x=1'],
        ], Settings::geoRules($text));
        $this->assertSame(['FR', 'https://example.fr/', 'XX ftp://example.org/'], Settings::invalidGeoRules($text));
    }

    public function testCountryCodes(): void
    {
        $this->assertSame(['CN', 'RU', 'IR'], Settings::countryCodes('cn, RU;ir cn'));
        $this->assertSame([], Settings::countryCodes('USA, Germany'), 'only two-letter codes count');
    }

    /** An order's note says what was found, the billing country beside the buyer's own. */
    public function testDescribe(): void
    {
        $visit = new Visit('81.2.69.142', SignalsTest::answer(['isTor' => true]));
        $verdict = new Verdict('checkout', 'hold', ['tor', 'billing'], $visit, billing: 'US');
        $this->assertSame('Tor, Billing country differs (billed to US, connected from DE)', $verdict->describe());
        $this->assertSame('Countries (DE)', (new Verdict('login', 'block', ['country'], $visit))->describe());
        $geo = new Verdict('pages', 'redirect', ['geo'], $visit, target: 'https://example.de/');
        $this->assertSame('https://example.de/', $geo->toArray()['target']);
        $this->assertTrue($geo->enforced());
    }
}
