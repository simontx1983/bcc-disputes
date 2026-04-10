<?php

namespace BCC\Disputes\Services;

use BCC\Disputes\Services\ResolveDisputeService;
use BCC\Disputes\Repositories\DisputeRepository;
use BCC\Core\Log\Logger as CoreLogger;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeScheduler
{
    const EVENT_AUTO_RESOLVE  = 'bcc_disputes_auto_resolve';
    const EVENT_RECONCILE     = 'bcc_disputes_reconcile_orphans';

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::EVENT_AUTO_RESOLVE)) {
            wp_schedule_event(time(), 'daily', self::EVENT_AUTO_RESOLVE);
        }
        // Reconciliation runs every 5 minutes to catch split-brain disputes.
        if (!wp_next_scheduled(self::EVENT_RECONCILE)) {
            wp_schedule_event(time(), 'bcc_five_minutes', self::EVENT_RECONCILE);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::EVENT_AUTO_RESOLVE);
        wp_clear_scheduled_hook(self::EVENT_RECONCILE);
    }

    public static function boot(): void
    {
        add_action(self::EVENT_AUTO_RESOLVE, [__CLASS__, 'auto_resolve_expired']);
        add_action(self::EVENT_RECONCILE, [__CLASS__, 'reconcileOrphanedDisputes']);
        add_action('bcc_disputes_async_resolve', [__CLASS__, 'handleAsyncResolve'], 10, 6);

        // Register the 5-minute cron interval if not already available.
        add_filter('cron_schedules', function (array $schedules): array {
            if (!isset($schedules['bcc_five_minutes'])) {
                $schedules['bcc_five_minutes'] = [
                    'interval' => 300,
                    'display'  => 'Every 5 Minutes (BCC Disputes)',
                ];
            }
            return $schedules;
        });
    }

    /**
     * Async handler: resolve a single dispute outside the cron loop.
     */
    public static function handleAsyncResolve($dispute_id, $vote_id, $page_id, $voter_id, $reporter_id, $outcome): void
    {
        (new ResolveDisputeService())->handle((int) $dispute_id, (int) $vote_id, (int) $page_id, (int) $voter_id, (int) $reporter_id, (string) $outcome, null);
    }

    /**
     * Auto-resolve disputes that have been open longer than BCC_DISPUTES_TTL_DAYS.
     * Outcome is determined by whichever side has more votes; ties go to 'rejected'
     * (benefit of the doubt to the voter).
     */
    public static function auto_resolve_expired(): void
    {
        // Advisory lock prevents overlapping cron runs from processing
        // the same expired disputes concurrently.
        if (!DisputeRepository::acquireAutoResolveLock()) {
            return;
        }

        try {
            self::doAutoResolve();
        } finally {
            DisputeRepository::releaseAutoResolveLock();
        }
    }

    private static function doAutoResolve(): void
    {
        $cutoff  = gmdate('Y-m-d H:i:s', time() - (BCC_DISPUTES_TTL_DAYS * DAY_IN_SECONDS));
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

    /**
     * Reconciliation cron: find disputes that were committed as resolved
     * but whose adjudication never completed (split-brain state).
     *
     * These are disputes where:
     *   - status is 'accepted' or 'rejected' (committed)
     *   - adjudication_status is 'pending' or 'failed' (never completed)
     *   - resolved_at is > 2 minutes ago (grace period for in-flight)
     *   - reopen_count < 3 (circuit breaker)
     *
     * For each orphan, we retry the adjudication call. If it fails again,
     * we increment reopen_count. After 3 failures, we leave it for manual
     * admin review.
     */
    public static function reconcileOrphanedDisputes(): void
    {
        $orphans = DisputeRepository::getOrphanedDisputes(10);

        if (empty($orphans)) {
            return;
        }

        $resolver = new ResolveDisputeService();

        foreach ($orphans as $dispute) {
            $disputeId = (int) $dispute->id;

            CoreLogger::info('[bcc-disputes] reconcile_retry', [
                'dispute_id'   => $disputeId,
                'status'       => $dispute->status,
                'reopen_count' => $dispute->reopen_count,
            ]);

            $success = $resolver->executeAdjudication(
                $disputeId,
                (int) $dispute->vote_id,
                (int) $dispute->page_id,
                (int) $dispute->voter_id,
                $dispute->status,
                0 // system actor
            );

            if ($success) {
                DisputeRepository::setAdjudicationStatus($disputeId, 'completed');

                // Fire penalty hook if dispute was rejected.
                if ($dispute->status === 'rejected') {
                    do_action('bcc.trust.dispute_rejected_penalty', (int) $dispute->reporter_id, $disputeId);
                }

                DisputeNotificationService::enqueueAsync(
                    'bcc_disputes_email_reporter_result',
                    [$disputeId, (int) $dispute->reporter_id, $dispute->status]
                );

                CoreLogger::info('[bcc-disputes] reconcile_success', [
                    'dispute_id' => $disputeId,
                ]);
            } else {
                // Increment reopen_count as circuit breaker.
                DisputeRepository::setAdjudicationStatus($disputeId, 'failed');
                // The reopen_count increment happens via the existing reopenDispute
                // mechanism or directly here for the reconciliation path.
                global $wpdb;
                $table = DisputeRepository::disputes_table();
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET reopen_count = reopen_count + 1 WHERE id = %d",
                    $disputeId
                ));

                CoreLogger::error('[bcc-disputes] reconcile_failed', [
                    'dispute_id'   => $disputeId,
                    'reopen_count' => (int) $dispute->reopen_count + 1,
                ]);
            }
        }
    }

}
