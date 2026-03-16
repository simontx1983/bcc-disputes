<?php

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Disputes_Cron
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

        $dt       = BCC_Disputes_DB::disputes_table();
        $cutoff   = date('Y-m-d H:i:s', strtotime('-' . BCC_DISPUTES_TTL_DAYS . ' days'));

        $expired = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$dt}
             WHERE status IN ('pending','reviewing')
               AND created_at <= %s",
            $cutoff
        ));

        if (empty($expired)) {
            return;
        }

        $api = new BCC_Disputes_API();

        foreach ($expired as $d) {
            $outcome = ((int) $d->panel_accepts > (int) $d->panel_rejects) ? 'accepted' : 'rejected';
            $api->resolve((int) $d->id, (int) $d->vote_id, (int) $d->page_id, (int) $d->voter_id, (int) $d->reporter_id, $outcome);
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

        // Fallback: manually recalculate from remaining active votes
        global $wpdb;

        $votes_table  = function_exists('bcc_trust_votes_table')
            ? bcc_trust_votes_table()
            : $wpdb->prefix . 'bcc_trust_votes';
        $scores_table = function_exists('bcc_trust_scores_table')
            ? bcc_trust_scores_table()
            : $wpdb->prefix . 'bcc_trust_page_scores';

        $votes = $wpdb->get_results($wpdb->prepare(
            "SELECT vote_type, weight, created_at FROM {$votes_table}
             WHERE page_id = %d AND status = 1",
            $page_id
        ));

        $positive = 0.0;
        $negative = 0.0;
        $now      = time();

        foreach ($votes as $v) {
            // Respect time decay (mirrors VoteService logic)
            $decay_days = defined('BCC_TRUST_DECAY_DAYS') ? BCC_TRUST_DECAY_DAYS : 365;
            $decay_min  = defined('BCC_TRUST_DECAY_MIN')  ? BCC_TRUST_DECAY_MIN  : 0.1;
            $age_days   = ($now - strtotime($v->created_at)) / DAY_IN_SECONDS;
            $factor     = $age_days > $decay_days ? 0 : max($decay_min, 1 - ($age_days / $decay_days));
            $w          = (float) $v->weight * $factor;

            (int) $v->vote_type > 0 ? $positive += $w : $negative += $w;
        }

        $new_score = max(0, min(100, 50 + (($positive - $negative) * 2)));

        $tier = match (true) {
            $new_score >= 85 => 'platinum',
            $new_score >= 70 => 'gold',
            $new_score >= 50 => 'silver',
            $new_score >= 30 => 'bronze',
            default          => 'unranked',
        };

        $wpdb->update(
            $scores_table,
            ['total_score' => $new_score, 'reputation_tier' => $tier],
            ['page_id' => $page_id],
            ['%f', '%s'], ['%d']
        );
    }
}

// Register cron handlers at init
add_action('init', ['BCC_Disputes_Cron', 'boot']);
