<?php

declare(strict_types=1);

namespace InternetData\WordPress\Areas;

use InternetData\WordPress\Detector;
use InternetData\WordPress\Settings;
use InternetData\WordPress\Verdict;
use WC_Order;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * WooCommerce checkout, classic and block-based: refuse the order, or take it and hold it for review.
 *
 * Besides the signals, an order can be caught on a billing country other than the one its buyer
 * connects from, which every plan answers. A held order is placed and paid as usual and then waits On
 * hold instead of going to Processing, with a note saying what was found. Logging only leaves the same
 * note and changes nothing else.
 */
final class Checkout
{
    private const META = '_internetdata';
    private const HOLD_META = '_internetdata_hold';

    public function __construct(private readonly Settings $settings, private readonly Detector $detector)
    {
    }

    public static function available(): bool
    {
        return class_exists('WooCommerce');
    }

    public function register(): void
    {
        if ($this->settings->action('checkout') === 'block') {
            add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 99, 2);
            add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'validateStoreApi'], 99, 1);
            return;
        }
        add_action('woocommerce_checkout_order_created', [$this, 'mark'], 99, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'mark'], 99, 1);
        add_filter('woocommerce_payment_complete_order_status', [$this, 'holdPaid'], 99, 3);
        add_filter('woocommerce_cod_process_payment_order_status', [$this, 'holdCod'], 99, 2);
    }

    /** Classic checkout: the order is refused before it exists. */
    public function validate(mixed $data, mixed $errors): void
    {
        $billing = is_array($data) ? (string) ($data['billing_country'] ?? '') : '';
        $verdict = $this->detector->judge('checkout', [], $billing);
        if ($errors instanceof WP_Error && $verdict !== null && $verdict->enforced()) {
            $errors->add('internetdata_blocked', esc_html($this->settings->message($verdict)));
        }
    }

    /** Block checkout: the Store API refuses the request, which the checkout shows as an error. */
    public function validateStoreApi(mixed $order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        $verdict = $this->detector->judge('checkout', ['order' => (string) $order->get_order_number()], $order->get_billing_country());
        if ($verdict === null || !$verdict->enforced()) {
            return;
        }
        $class = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
        throw new $class('internetdata_blocked', esc_html($this->settings->message($verdict)), 403);
    }

    public function mark(mixed $order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        $verdict = $this->detector->judge('checkout', ['order' => (string) $order->get_order_number()], $order->get_billing_country());
        if ($verdict === null) {
            return;
        }
        $result = $verdict->visit->result;
        $order->update_meta_data(self::META, wp_json_encode([
            'ip' => $verdict->visit->ip,
            'signals' => $verdict->signals,
            'provider' => $verdict->provider(),
            'country' => $result?->location->country ?? '',
            'billing_country' => $order->get_billing_country(),
            'asn' => $result?->asn->asn ?? 0,
            'network' => $result?->asn->name ?? '',
        ]));
        if ($verdict->action === 'hold') {
            $order->update_meta_data(self::HOLD_META, '1');
        }
        $order->save();
        $order->add_order_note(self::note($verdict));
    }

    public function holdPaid(mixed $status, mixed $orderId = 0, mixed $order = null): mixed
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);
        return $this->held($order) && in_array($status, ['processing', 'completed'], true) ? 'on-hold' : $status;
    }

    public function holdCod(mixed $status, mixed $order = null): mixed
    {
        return $this->held($order) ? 'on-hold' : $status;
    }

    private function held(mixed $order): bool
    {
        return $order instanceof WC_Order && $order->get_meta(self::HOLD_META) === '1';
    }

    private static function note(Verdict $verdict): string
    {
        $what = sprintf(
            /* translators: 1: what was found, such as "Tor, Hosting and cloud servers"; 2: an IP address. */
            __('InternetData flagged this order for %1$s. Placed from %2$s.', 'internetdata'),
            $verdict->describe(),
            (string) $verdict->visit->ip,
        );
        return $verdict->action === 'hold'
            ? $what . ' ' . __('Held for review: it stays On hold instead of going to Processing.', 'internetdata')
            : $what;
    }
}
