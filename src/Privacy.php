<?php

declare(strict_types=1);

namespace InternetData\WordPress;

defined('ABSPATH') || exit;

/** The paragraph WordPress offers site owners for their privacy policy. */
final class Privacy
{
    public static function suggest(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        $text = '<p>' . esc_html__(
            'This site uses InternetData to tell which country a visitor connects from, and whether it is through a VPN, a proxy, Tor or a hosting network. When you sign in, create an account, reset a password, comment, send a form, place an order or open a protected page, your IP address is sent to InternetData (internetdata.io), which answers where it is and what kind of network it belongs to. Where the site checks for forged crawlers, your browser\'s user agent is sent with it. The answer is kept for a few hours so a later visit does not ask again, and a visit the site acts on is recorded with the address for a set number of days.',
            'internetdata',
        ) . '</p><p>' . sprintf(
            /* translators: 1: privacy policy URL; 2: terms URL. */
            esc_html__('InternetData\'s privacy policy is at %1$s and its terms at %2$s.', 'internetdata'),
            '<a href="https://internetdata.io/privacy">https://internetdata.io/privacy</a>',
            '<a href="https://internetdata.io/terms">https://internetdata.io/terms</a>',
        ) . '</p>';
        wp_add_privacy_policy_content('InternetData', wp_kses_post($text));
    }
}
