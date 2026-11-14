<?php

declare(strict_types=1);

namespace InternetData\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use InternetData\WordPress\Plugin;

final class ScopedTest extends TestCase
{
    public function testTheSdkLoadsUnderThePluginsNamespace(): void
    {
        $this->assertSame(
            'InternetData\\WordPress\\Vendor\\InternetData\\Client',
            Plugin::client()::class,
        );
    }
}
