<?php

namespace BCC\Disputes\Services;

use BCC\Core\Contracts\DisputeAdjudicationInterface;
use BCC\Core\ServiceLocator;
use BCC\Disputes\Repositories\DisputeRepository;
use BCC\Disputes\Services\DisputeNotificationService;
use BCC\Core\Log\Logger as CoreLogger;

if (!defined('ABSPATH')) {
    exit;
}

final class ResolveDisputeService
{
    public function handle(int $disputeId, int $voteId, int $pageId, int $voterId, int $reporterId, string $outcome, ?int $actorId = null): bool
    {
        // Atomic status transition via repository: WHERE status = 'reviewing'
        // prevents double-resolution under concurrent requests.
        // Transaction is left OPEN on success — we must commit or rollback.
        $txn = DisputeRepository::beginResolveTransaction($disputeId, $outcome);

        if ($txn['db_error']) {
            CoreLogger::error('[bcc-disputes] ' .'resolve_rollback', [
                'dispute_id' => $disputeId,
                'outcome'    => $outcome,
                'step'       => 'status_update',
                'db_error'   => $txn['db_error'],
            ]);
            return false;
        }

        if ($txn['race']) {
            // Already resolved by a concurrent request — expected under
            // concurrent panelist voting; not an error condition.
            CoreLogger::info('[bcc-disputes] resolve_race_skipped', [
                'dispute_id' => $disputeId,
                'outcome'    => $outcome,
            ]);
            return false;
        }

        // ── Pre-commit gate: verify trust engine is available ────────────
        try {
            $hasRealAdjudicator = ServiceLocator::hasRealService(DisputeAdjudicationInterface::class);

            if (!$hasRealAdjudicator) {
                DisputeRepository::rollbackTransaction();
                CoreLogger::error('[bcc-disputes] ' .'trust_adjudication_service_unavailable', [
                    'dispute_id' => $disputeId,
                    'outcome'    => $outcome,
                ]);
                return false;
            }

            // adjudication_status='pending' is already set atomically
            // by beginResolveTransaction() in the same UPDATE statement.

            DisputeRepository::commitTransaction();
        } catch (\Throwable $e) {
            DisputeRepository::rollbackTransaction();
            CoreLogger::error("[ResolveDisputeService] Transaction failed for dispute {$disputeId}: " . $e->getMessage());
            return false;
        }

        // Status changed — invalidate all caches tied to this dispute.
        DisputeRepository::invalidateDispute($disputeId);

        // ── Post-commit: execute adjudication ────────────────────────────
        $actorId     = $actorId ?? get_current_user_id();
        $adjudicationSucceeded = $this->executeAdjudication(
            $disputeId, $voteId, $pageId, $voterId, $outcome, $actorId
        );

        if (!$adjudicationSucceeded) {
            // Mark as failed for reconciliation cron to pick up.
            DisputeRepository::setAdjudicationStatus($disputeId, 'failed');

            CoreLogger::error('[bcc-disputes] ' .'trust_adjudication_failed', [
                'dispute_id' => $disputeId,
                'vote_id'    => $voteId,
                'outcome'    => $outcome,
                'action'     => 'marked_for_reconciliation',
            ]);
            return false;
        }

        // ── Adjudication succeeded — mark completed, fire hooks, notify ──
        DisputeRepository::setAdjudicationStatus($disputeId, 'completed');

        if ($outcome === 'rejected') {
            $hookName = 'bcc.trust.dispute_rejected_penalty';
            if (has_action($hookName)) {
                do_action($hookName, $reporterId, $disputeId);
            } else {
                CoreLogger::error('[bcc-disputes] rejection_penalty_skipped', [
                    'dispute_id'  => $disputeId,
                    'reporter_id' => $reporterId,
                    'reason'      => 'No listener for ' . $hookName . ' — trust engine may be inactive.',
                ]);
            }
        }

        DisputeNotificationService::enqueueAsync('bcc_disputes_email_reporter_result', [$disputeId, $reporterId, $outcome]);

        return true;
    }

    /**
     * Execute the trust-engine adjudication call.
     * Extracted so the reconciliation cron can reuse it.
     */
    public function executeAdjudication(
        int $disputeId,
        int $voteId,
        int $pageId,
        int $voterId,
        string $outcome,
        int $actorId
    ): bool {
        $adjudicator = ServiceLocator::resolveDisputeAdjudication();

        if ($outcome === 'accepted') {
            return $adjudicator->acceptVoteDispute(
                $disputeId,
                $voteId,
                $pageId,
                $voterId,
                $actorId
            );
        }

        return $adjudicator->rejectVoteDispute(
            $disputeId,
            $voteId,
            $pageId,
            $actorId
        );
    }
}
