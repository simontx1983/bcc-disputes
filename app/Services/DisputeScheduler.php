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

        // Admin health checks.
        add_action('admin_notices', [__CLASS__, 'warnIfCronDisabled']);
        add_action('admin_notices', [__CLASS__, 'warnIfAdjudicationDown']);
        add_action('admin_notices', [__CLASS__, 'warnIfPermanentOrphans']);

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
        // Single atomic lock: MySQL advisory lock serialises across all PHP
        // processes on the same DB.  GET_LOCK(name, 0) is non-blocking — if
        // another process holds it we return immediately.
        //
        // The previous transient-based "outer lock" had a TOCTOU race: two
        // processes could both read the transient as empty, both set it, and
        // both proceed.  Worse, the losing process deleted the transient on
        // advisory-lock failure, opening a window for a third process.
        // The advisory lock alone is sufficient and race-free.
        if (!DisputeRepository::acquireAutoResolveLock()) {
            return;
        }

        try {
            self::doAutoResolve();
            update_option('bcc_disputes_auto_resolve_last_run', time(), false);
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

        // PHASE A.5: Alert admins if adjudication has been unavailable for >1 hour.
        self::checkAdjudicationHealth();

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
                // Write status BEFORE firing side-effects. If this fails,
                // the dispute stays 'failed' and the next reconcile tick
                // retries — but the penalty hook will NOT have fired yet,
                // preventing double-penalty on retry.
                DisputeRepository::setAdjudicationStatus($disputeId, 'completed');

                // Verify the status write actually took effect before
                // firing irreversible side-effects (penalty hook, emails).
                // Uses the dedicated uncached repository method — NOT
                // getDisputeById() which is cached and omits this column.
                $adjStatus = DisputeRepository::getAdjudicationStatus($disputeId);
                if ($adjStatus !== 'completed') {
                    CoreLogger::error('[bcc-disputes] reconcile_status_write_failed', [
                        'dispute_id' => $disputeId,
                        'actual_status' => $adjStatus,
                    ]);
                    // Do NOT fire penalty or notification — next tick will retry.
                    continue;
                }

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
     * Alert admins when the trust adjudication service has been unavailable
     * for over 1 hour.  Detected by checking for disputes resolved >1 hour
     * ago whose adjudication_status is still 'pending' or 'failed'.
     *
     * Sets a transient that triggers an admin notice on the next dashboard
     * load.  The transient auto-expires after 2 hours to self-clear once
     * the service recovers and reconciliation catches up.
     */
    private static function checkAdjudicationHealth(): void
    {
        $staleCount = DisputeRepository::countStaleAdjudications();

        if ($staleCount === 0) {
            delete_transient('bcc_disputes_adjudication_alert');
            return;
        }

        // Only log + set transient if not already alerting (avoid log spam).
        if (!get_transient('bcc_disputes_adjudication_alert')) {
            CoreLogger::error('[bcc-disputes] adjudication_unavailable_prolonged', [
                'stale_count' => $staleCount,
                'threshold'   => '1 hour',
            ]);
            set_transient('bcc_disputes_adjudication_alert', $staleCount, 2 * HOUR_IN_SECONDS);
        }
    }

    /**
     * Show admin notice when adjudication has been unavailable for >1 hour.
     * Companion to checkAdjudicationHealth() (called from reconciliation cron).
     */
    public static function warnIfAdjudicationDown(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $staleCount = get_transient('bcc_disputes_adjudication_alert');
        if (!$staleCount) {
            return;
        }

        echo wp_kses_post(
            '<div class="notice notice-error"><p>'
            . '<strong>BCC Disputes:</strong> '
            . sprintf(
                '%d dispute(s) have been waiting over 1 hour for trust adjudication. '
                . 'The trust engine adjudication service may be unavailable. '
                . 'Check the <code>bcc-trust-engine</code> plugin status.',
                (int) $staleCount
            )
            . '</p></div>'
        );
    }

    /**
     * Emergency fallback: resolve severely overdue disputes on-demand.
     *
     * Called from DisputeController when a panelist or reporter loads
     * their queue. If cron has stopped (misconfigured, disabled, hosting
     * issue), disputes can sit in 'reviewing' indefinitely. This catches
     * disputes that are 2x the TTL (14 days) overdue and resolves up to
     * 5 per request to avoid blocking the HTTP response.
     *
     * This is a SAFETY NET, not a replacement for cron. It only fires
     * when cron has clearly failed.
     */
    public static function emergencyResolveIfStale(): void
    {
        // Only trigger if auto-resolve hasn't run in 48+ hours.
        $lastRun = (int) get_option('bcc_disputes_auto_resolve_last_run', 0);
        if ($lastRun > 0 && (time() - $lastRun) < 2 * DAY_IN_SECONDS) {
            return; // Cron is working — no emergency needed.
        }

        // Rate-limit the emergency check per-process, scoped per-dispute batch
        // via a global transient. Individual disputes are de-duped below.
        $emergencyKey = 'bcc_disputes_emergency_check';
        if (get_transient($emergencyKey)) {
            return;
        }
        set_transient($emergencyKey, 1, 600);

        $hardStopCutoff = gmdate('Y-m-d H:i:s', time() - (BCC_DISPUTES_TTL_DAYS * 2 * DAY_IN_SECONDS));
        $stale = DisputeRepository::getExpiredDisputes($hardStopCutoff, 5);

        if (empty($stale)) {
            return;
        }

        CoreLogger::warning('[bcc-disputes] emergency_resolve_triggered', [
            'count'       => count($stale),
            'last_cron'   => $lastRun > 0 ? gmdate('Y-m-d H:i:s', $lastRun) : 'never',
            'hard_cutoff' => $hardStopCutoff,
        ]);

        foreach ($stale as $dispute) {
            $disputeId = (int) $dispute->id;

            // Skip if an async resolve job is already pending for this dispute.
            // Check Action Scheduler first, then fall back to WP-Cron.
            $alreadyScheduled = false;
            if (function_exists('as_next_scheduled_action')) {
                $alreadyScheduled = (bool) as_next_scheduled_action(
                    'bcc_disputes_async_resolve',
                    [$disputeId],
                    'bcc-disputes'
                );
            }
            if (!$alreadyScheduled) {
                $alreadyScheduled = (bool) wp_next_scheduled(
                    'bcc_disputes_async_resolve',
                    [$disputeId]
                );
            }

            if ($alreadyScheduled) {
                CoreLogger::info('[bcc-disputes] emergency_resolve_skipped_already_queued', [
                    'dispute_id' => $disputeId,
                ]);
                continue;
            }

            $verdict = DisputeRepository::computeVerdict(
                (int) $dispute->panel_accepts,
                (int) $dispute->panel_rejects,
                (int) $dispute->panel_size
            );

            DisputeNotificationService::enqueueAsync('bcc_disputes_async_resolve', [
                $disputeId,
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

    /**
     * Show admin notice when disputes have exhausted all reconciliation
     * retries and require manual admin intervention.
     */
    public static function warnIfPermanentOrphans(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Cache the count check for 30 minutes to avoid extra queries on every admin page.
        $cacheKey = 'bcc_disputes_permanent_orphan_count';
        $count = get_transient($cacheKey);
        if ($count === false) {
            $count = DisputeRepository::countPermanentOrphans();
            set_transient($cacheKey, $count, 30 * MINUTE_IN_SECONDS);
        }

        if ((int) $count === 0) {
            return;
        }

        echo wp_kses_post(
            '<div class="notice notice-error"><p>'
            . '<strong>BCC Disputes:</strong> '
            . sprintf(
                '%d dispute(s) have failed adjudication 3+ times and are permanently stuck. '
                . 'Trust scores for these disputes are NOT applied. '
                . '<a href="%s">Review and re-adjudicate &rarr;</a>',
                (int) $count,
                admin_url('admin.php?page=bcc-trust-dashboard&tab=disputes&filter=orphaned')
            )
            . '</p></div>'
        );
    }

}
