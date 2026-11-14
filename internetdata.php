<?php
/**
 * Plugin Name: InternetData
 * Plugin URI: https://docs.internetdata.io/integrations/wordpress
 * Description: The official InternetData plugin for WordPress.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: InternetData
 * Author URI: https://internetdata.io
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: internetdata
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const INTERNETDATA_VERSION = '1.0.0';
const INTERNETDATA_FILE = __FILE__;

// WordPress refuses to activate the plugin below its PHP, but a site moved to an older PHP afterwards
// would still load it: say so instead of failing every request.
if (PHP_VERSION_ID < 80200) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('InternetData needs PHP 8.2 or newer, and is doing nothing until the site runs it.', 'internetdata')
        );
    });
    return;
}

require __DIR__ . '/vendor/autoload.php';

InternetData\WordPress\Plugin::boot();
