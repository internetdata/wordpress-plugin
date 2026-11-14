<?php

declare(strict_types=1);

namespace InternetData\WordPress\Admin;

use InternetData\WordPress\ActivityLog;
use InternetData\WordPress\Plugin;
use InternetData\WordPress\Settings;
use InternetData\WordPress\Signals;

defined('ABSPATH') || exit;

/** The Activity tab: who each area caught, and the one click that lets an address through for good. */
final class ActivityScreen
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function render(): void
    {
        $this->renderSummary();
        $table = new ActivityTable($this->plugin);
        $table->prepare_items();
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr(Page::SLUG) . '"><input type="hidden" name="tab" value="activity">';
        $table->search_box(__('Search activity', 'internetdata'), 'inetd-search');
        $table->display();
        echo '</form>';
        echo '<div class="inetd-actions">';
        (new Page($this->plugin))->postButton(
            'internetdata_clear',
            __('Clear activity', 'internetdata'),
            'button-link-delete',
            __('Clear every row of activity? This cannot be undone.', 'internetdata'),
        );
        printf(
            '<span class="description">%s</span></div>',
            esc_html(sprintf(
                /* translators: %d: a number of days. */
                _n('Activity is kept for %d day.', 'Activity is kept for %d days.', (int) $this->plugin->settings->get('log_days'), 'internetdata'),
                (int) $this->plugin->settings->get('log_days'),
            )),
        );
    }

    private function renderSummary(): void
    {
        $rows = $this->plugin->log->summary(7);
        echo '<h2>' . esc_html__('The last 7 days', 'internetdata') . '</h2>';
        if ($rows === []) {
            echo '<p>' . esc_html__('Nobody caught.', 'internetdata') . '</p>';
            return;
        }
        usort($rows, static fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);
        printf(
            '<table class="widefat striped inetd-summary"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>',
            esc_html__('Where', 'internetdata'),
            esc_html__('Outcome', 'internetdata'),
            esc_html__('Visitors', 'internetdata'),
            esc_html__('Attempts', 'internetdata'),
        );
        foreach ($rows as $row) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html(Page::areaText($row['area'])[0]),
                esc_html(Page::actionLabel($row['area'], $row['action'])),
                esc_html(number_format_i18n($row['visitors'])),
                esc_html(number_format_i18n($row['hits'])),
            );
        }
        echo '</tbody></table>';
    }

    /** What a row's detail column holds, for people. */
    public static function detail(string $area, string $detail): string
    {
        if ($detail === '') {
            return '';
        }
        return match ($area) {
            /* translators: %s: a user name. */
            'login', 'register', 'lostpassword' => sprintf(__('as %s', 'internetdata'), $detail),
            'comment' => str_starts_with($detail, 'post:') ? sprintf(
                /* translators: %s: a post title. */
                __('on %s', 'internetdata'),
                get_the_title((int) substr($detail, 5)),
            ) : $detail,
            /* translators: %s: an order number. */
            'checkout' => sprintf(__('order %s', 'internetdata'), $detail),
            default => $detail,
        };
    }

    /** @param list<string> $signals */
    public static function signals(array $signals): string
    {
        return implode(', ', array_map([Signals::class, 'label'], $signals));
    }

    public static function areas(): array
    {
        $out = [];
        foreach (array_keys(Settings::AREAS) as $area) {
            $out[$area] = Page::areaText($area)[0];
        }
        return $out;
    }

    public static function table(): string
    {
        return ActivityLog::table();
    }
}
