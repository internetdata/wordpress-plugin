<?php
/**
 * Plugin Name: InternetData
 * Plugin URI: https://docs.internetdata.io/integrations/wordpress
 * Description: Block, hold or flag sign-ins, sign-ups, comments, contact forms, WooCommerce orders and pages by country or from VPNs, proxies, Tor and hosting networks, and send visitors on by their country.
 * Version: 1.0.5
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: InternetData
 * Author URI: https://internetdata.io
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: internetdata
 * WC requires at least: 9.0
 * WC tested up to: 11.3
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const INTERNETDATA_VERSION = '1.0.5';
const INTERNETDATA_FILE = __FILE__;
define('INTERNETDATA_DIR', plugin_dir_path(__FILE__));

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

/**
 * What InternetData answers about the visitor behind this request, for a theme or another plugin:
 * the lookup as the API sends it (`location`, `asn`, `company`, `is_tor`, `is_vpn`, ...), or null when
 * the visitor's address could not be read, the allowlist covers it, or the lookup failed. Looked up
 * once a request, and counted against the site's allowance like any check.
 *
 * @return array<string, mixed>|null
 */
function internetdata_visitor(): ?array
{
    return InternetData\WordPress\Plugin::instance()->detector->visit()->answer();
}
