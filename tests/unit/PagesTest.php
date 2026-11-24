<?php

declare(strict_types=1);

namespace InternetData\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use InternetData\WordPress\Areas\Pages;
use InternetData\WordPress\Vendor\InternetData\BotDetail;
use InternetData\WordPress\Vendor\InternetData\Result;

final class PagesTest extends TestCase
{
    /** A paid answer says whose bot an address is and whether its operator vouches for it. */
    public function testAVouchedSearchCrawlerPasses(): void
    {
        $this->assertTrue(Pages::vouchedCrawler(self::bot(['search_engine'], true)));
        $this->assertTrue(Pages::vouchedCrawler(self::bot(['ai_search', 'search_engine'], true)));
    }

    public function testOtherBotsAreJudgedAsAnyVisitor(): void
    {
        $this->assertFalse(Pages::vouchedCrawler(self::bot(['search_engine'], false)), 'a claim nobody vouches for');
        $this->assertFalse(Pages::vouchedCrawler(self::bot(['ai_training'], true)), 'a vouched bot that is no search engine');
        $this->assertFalse(Pages::vouchedCrawler(self::bot(['scanner'], true)), 'a declared scanner');
    }

    /** Without a key, or past the trial, an answer has is_bot alone, which says nothing of whose bot it is. */
    public function testAnUnlimitedAnswerLetsNoCrawlerThrough(): void
    {
        $this->assertFalse(Pages::vouchedCrawler(SignalsTest::answer(['ip' => '5.255.253.1', 'isBot' => true])));
        $this->assertFalse(Pages::vouchedCrawler(SignalsTest::answer()));
    }

    /** @param list<string> $categories */
    private static function bot(array $categories, bool $verified): Result
    {
        return SignalsTest::answer(['ip' => '5.255.253.1', 'isBot' => true, 'bot' => new BotDetail(
            provider: 'yandex',
            providerName: 'Yandex',
            agents: ['YandexBot'],
            categories: $categories,
            verified: $verified,
            identity: $verified ? 'verified' : 'declared',
            verifiedBy: $verified ? 'rdns' : null,
            confidence: 'high',
        )]);
    }
}
