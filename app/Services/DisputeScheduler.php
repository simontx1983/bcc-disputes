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

        // Admin health check: warn if WP-Cron is disabled without a system cron.
        add_action('admin_notices', [__CLASS__, 'warnIfCronDisabled']);

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
     *
     * @param int|string $dispute_id
     * @param int|string $vote_id
     * @param int|string $page_id
     * @param int|string $voter_id
     * @param int|string $reporter_id
     * @param string     $outcome
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
        // Double-lock: transient guard (cross-process) + advisory lock (per-connection).
        // The transient prevents overlapping cron runs on separate PHP processes;
        // the advisory lock provides MySQL-level serialization within a connection.
        $lockKey = 'bcc_disputes_cron_lock';
        if (get_transient($lockKey)) {
            return; // Another process is already running
        }
        set_transient($lockKey, 1, 300); // 5-minute TTL as safety net

        if (!DisputeRepository::acquireAutoResolveLock()) {
            delete_transient($lockKey);
            return;
        }

        try {
            self::doAutoResolve();
            update_option('bcc_disputes_auto_resolve_last_run', time(), false);
        } finally {
            DisputeRepository::releaseAutoResolveLock();
            delete_transient($lockKey);
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
            $verdict = DisputeRepository::computeVerdict(
                (int) $dispute->panel_accepts,
                (int) $dispute->panel_rejects,
                (int) $dispute->panel_size
            );

            $args = [
                (int) $dispute->id,
                (int) $dispute->vote_id,
                (int) $dispute->page_id,
                (int) $dispute->voter_id,
                (int) $dispute->reporter_id,
                $verdict['outcome'],
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
        if (!DisputeRepository::acquireReconcileLock()) {
            return; // Another process is running — skip this tick.
        }

        try {
            self::doReconcile();
        } finally {
            DisputeRepository::releaseReconcileLock();
        }
    }

    private static function doReconcile(): void
    {
        // PHASE A: Retry stuck "reviewing" disputes where all votes are in
        // but resolution failed (trust engine was unavailable at resolution time).
        // These are invisible to the orphan query (which only looks for
        // accepted/rejected status), so they'd wait 7 days for auto-resolve.
        self::retryStuckReviewingDisputes();

        // PHASE B: Retry orphaned adjudications (committed but never completed).
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

            try {
                $success = $resolver->executeAdjudication(
                    $disputeId,
                    (int) $dispute->vote_id,
                    (int) $dispute->page_id,
                    (int) $dispute->voter_id,
                    $dispute->status,
                    0 // system actor
                );
            } catch (\Throwable $e) {
                CoreLogger::error('[bcc-disputes] reconcile_exception', [
                    'dispute_id' => $disputeId,
                    'error'      => $e->getMessage(),
                ]);
                $success = false;
            }

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
                DisputeRepository::incrementReopenCount($disputeId);

                CoreLogger::error('[bcc-disputes] reconcile_failed', [
                    'dispute_id'   => $disputeId,
                    'reopen_count' => (int) $dispute->reopen_count + 1,
                ]);
            }
        }
    }

    /**
     * Find disputes stuck in "reviewing" where total votes >= panel_size
     * (all votes are in but resolution was never executed — typically
     * because the trust engine was unavailable at the moment of the
     * deciding vote). Re-trigger resolution for these disputes.
     */
    private static function retryStuckReviewingDisputes(): void
    {
        // Grace period: only retry disputes where the last vote was > 2 minutes ago.
        $cutoff = gmdate('Y-m-d H:i:s', time() - 120);

        $stuck = DisputeRepository::getStuckReviewingDisputes($cutoff, 10);

        if (empty($stuck)) {
            return;
        }

        foreach ($stuck as $dispute) {
            $verdict = DisputeRepository::computeVerdict(
                (int) $dispute->panel_accepts,
                (int) $dispute->panel_rejects,
                (int) $dispute->panel_size
            );

            CoreLogger::info('[bcc-disputes] retry_stuck_reviewing', [
                'dispute_id' => (int) $dispute->id,
                'accepts'    => (int) $dispute->panel_accepts,
                'rejects'    => (int) $dispute->panel_rejects,
                'outcome'    => $verdict['outcome'],
            ]);

            DisputeNotificationService::enqueueAsync('bcc_disputes_async_resolve', [
                (int) $dispute->id,
                (int) $dispute->vote_id,
                (int) $dispute->page_id,
                (int) $dispute->voter_id,
                (int) $dispute->reporter_id,
                $verdict['outcome'],
            ]);
        }
    }

    /**
     * Show an admin notice if WP-Cron is disabled and the auto-resolve
     * cron hasn't fired recently. Without a system cron replacement,
     * disputes will never auto-resolve and reconciliation won't run.
     */
    public static function warnIfCronDisabled(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
            return;
        }

        // Check if auto-resolve has fired within the last 48 hours.
        $lastRun = (int) get_option('bcc_disputes_auto_resolve_last_run', 0);
        if ($lastRun > 0 && (time() - $lastRun) < 2 * DAY_IN_SECONDS) {
            return; // System cron is working — no warning needed.
        }

        echo wp_kses_post(
            '<div class="notice notice-warning"><p>'
            . '<strong>BCC Disputes:</strong> '
            . 'DISABLE_WP_CRON is enabled but the dispute auto-resolve cron has not fired in over 48 hours. '
            . 'Please configure a system cron (<code>wp-cron.php</code>) to ensure disputes are auto-resolved and reconciliation runs.'
            . '</p></div>'
        );
    }

}
