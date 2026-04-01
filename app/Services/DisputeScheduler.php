<?php

namespace BCC\Disputes\Services;

use BCC\Disputes\Controllers\DisputeController;
use BCC\Disputes\Repositories\DisputeRepository;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeScheduler
{
    const EVENT_AUTO_RESOLVE = 'bcc_disputes_auto_resolve';

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::EVENT_AUTO_RESOLVE)) {
            wp_schedule_event(time(), 'daily', self::EVENT_AUTO_RESOLVE);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::EVENT_AUTO_RESOLVE);
    }

    public static function boot(): void
    {
        add_action(self::EVENT_AUTO_RESOLVE, [__CLASS__, 'auto_resolve_expired']);
    }

    /**
     * Auto-resolve disputes that have been open longer than BCC_DISPUTES_TTL_DAYS.
     * Outcome is determined by whichever side has more votes; ties go to 'rejected'
     * (benefit of the doubt to the voter).
     */
    public static function auto_resolve_expired(): void
    {
        global $wpdb;

        $disputeTable = DisputeRepository::disputes_table();
        $cutoff       = date('Y-m-d H:i:s', current_time('timestamp') - (BCC_DISPUTES_TTL_DAYS * DAY_IN_SECONDS));

        $expired = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$disputeTable}
             WHERE status IN ('pending','reviewing')
               AND created_at <= %s
             ORDER BY created_at ASC
             LIMIT 50",
            $cutoff
        ));

        if (empty($expired)) {
            return;
        }

        $api = new DisputeController();

        foreach ($expired as $dispute) {
            $outcome = ((int) $dispute->panel_accepts > (int) $dispute->panel_rejects) ? 'accepted' : 'rejected';
            $api->resolve((int) $dispute->id, (int) $dispute->vote_id, (int) $dispute->page_id, (int) $dispute->voter_id, (int) $dispute->reporter_id, $outcome);
        }
    }

}
