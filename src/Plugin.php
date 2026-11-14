<?php

declare(strict_types=1);

namespace InternetData\WordPress;

use InternetData\WordPress\Vendor\InternetData\Client;

defined('ABSPATH') || exit;

/** Wires the plugin into WordPress. */
final class Plugin
{
    public static function boot(): void
    {
    }

    /** The SDK client, from the copy vendored under the plugin's own namespace. */
    public static function client(): Client
    {
        return new Client();
    }
}
