<?php

namespace BCC\Disputes\Services;

use BCC\Disputes\Plugin;
use BCC\Disputes\Repositories\DisputeRepository;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeScheduler
{
    const EVENT_AUTO_RESOLVE = 'bcc_disputes_auto_resolve';
    const EVENT_RECALC       = 'bcc_disputes_recalculate_score';

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::EVENT_AUTO_RESOLVE)) {
            wp_schedule_event(time(), 'daily', self::EVENT_AUTO_RESOLVE);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::EVENT_AUTO_RESOLVE);
        wp_unschedule_hook(self::EVENT_RECALC);
    }

    public static function boot(): void
    {
        add_action(self::EVENT_AUTO_RESOLVE, [__CLASS__, 'auto_resolve_expired']);
        add_action(self::EVENT_RECALC, [__CLASS__, 'recalculate_page_score']);
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
        $cutoff       = date('Y-m-d H:i:s', strtotime('-' . BCC_DISPUTES_TTL_DAYS . ' days'));

        $expired = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$disputeTable}
             WHERE status IN ('pending','reviewing')
               AND created_at <= %s",
            $cutoff
        ));

        if (empty($expired)) {
            return;
        }

        $api = Plugin::instance()->controller();

        foreach ($expired as $dispute) {
            $outcome = ((int) $dispute->panel_accepts > (int) $dispute->panel_rejects) ? 'accepted' : 'rejected';
            $api->resolve((int) $dispute->id, (int) $dispute->vote_id, (int) $dispute->page_id, (int) $dispute->voter_id, (int) $dispute->reporter_id, $outcome);
        }
    }

    /**
     * Re-run trust score calculation for a page after an accepted dispute.
     * Hooked from wp_schedule_single_event in the API.
     */
    public static function recalculate_page_score(int $page_id): void
    {
        if (function_exists('bcc_trust_recalculate_page_score')) {
            bcc_trust_recalculate_page_score($page_id);
            return;
        }

        if (class_exists('BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] trust_recalc_unavailable', [
                'page_id' => $page_id,
            ]);
        }
    }
}
