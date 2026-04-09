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
        add_action('bcc_disputes_async_resolve', [__CLASS__, 'handleAsyncResolve'], 10, 6);
    }

    /**
     * Async handler: resolve a single dispute outside the cron loop.
     */
    public static function handleAsyncResolve(int $dispute_id, int $vote_id, int $page_id, int $voter_id, int $reporter_id, string $outcome): void
    {
        Plugin::instance()->controller()->resolve($dispute_id, $vote_id, $page_id, $voter_id, $reporter_id, $outcome);
    }

    /**
     * Auto-resolve disputes that have been open longer than BCC_DISPUTES_TTL_DAYS.
     * Outcome is determined by whichever side has more votes; ties go to 'rejected'
     * (benefit of the doubt to the voter).
     */
    public static function auto_resolve_expired(): void
    {
        $cutoff  = gmdate('Y-m-d H:i:s', current_time('timestamp', true) - (BCC_DISPUTES_TTL_DAYS * DAY_IN_SECONDS));
        $expired = DisputeRepository::getExpiredDisputes($cutoff, 50);

        if (empty($expired)) {
            return;
        }

        // Dispatch each resolution as an async action instead of resolving
        // synchronously in a loop. Each resolve() triggers a DB transaction +
        // trust-engine adjudication — blocking the cron with 50 sequential
        // transactions causes timeouts at scale.
        foreach ($expired as $dispute) {
            $outcome = ((int) $dispute->panel_accepts > (int) $dispute->panel_rejects) ? 'accepted' : 'rejected';
            $args = [
                (int) $dispute->id,
                (int) $dispute->vote_id,
                (int) $dispute->page_id,
                (int) $dispute->voter_id,
                (int) $dispute->reporter_id,
                $outcome,
            ];

            DisputeNotificationService::enqueueAsync('bcc_disputes_async_resolve', $args);
        }
    }

}
