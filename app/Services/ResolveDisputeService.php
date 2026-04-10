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
            // Already resolved by a concurrent request — safe to skip
            CoreLogger::error('[bcc-disputes] ' .'resolve_race_skipped', [
                'dispute_id' => $disputeId,
                'outcome'    => $outcome,
            ]);
            return false;
        }

        // ── Pre-commit gate: verify trust engine is available ────────────
        //
        // Check BEFORE committing the resolution. If the adjudicator is not
        // available, ROLLBACK so the dispute stays in its original state.
        // Wrapped in try/catch to guarantee the open transaction is closed
        // even if hasRealService() throws unexpectedly.
        try {
            $hasRealAdjudicator = ServiceLocator::hasRealService(DisputeAdjudicationInterface::class);
        } catch (\Throwable $e) {
            DisputeRepository::rollbackTransaction();
            CoreLogger::error('[bcc-disputes] ' .'trust_adjudication_check_failed', [
                'dispute_id' => $disputeId,
                'outcome'    => $outcome,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }

        if (!$hasRealAdjudicator) {
            DisputeRepository::rollbackTransaction();
            CoreLogger::error('[bcc-disputes] ' .'trust_adjudication_service_unavailable', [
                'dispute_id' => $disputeId,
                'outcome'    => $outcome,
            ]);
            return false;
        }

        DisputeRepository::commitTransaction();

        // Status changed — invalidate all caches tied to this dispute.
        DisputeRepository::invalidateDispute($disputeId);

        // ── Post-commit: execute adjudication ────────────────────────────
        //
        // The dispute is now marked resolved in the DB. We must attempt the
        // trust-engine operation. If it fails, re-open the dispute.
        $actorId     = $actorId ?? get_current_user_id();
        $adjudicator = ServiceLocator::resolveDisputeAdjudication();

        if ($outcome === 'accepted') {
            $adjudicationSucceeded = $adjudicator->acceptVoteDispute(
                $disputeId,
                $voteId,
                $pageId,
                $voterId,
                $actorId
            );
        } else {
            $adjudicationSucceeded = $adjudicator->rejectVoteDispute(
                $disputeId,
                $voteId,
                $pageId,
                $actorId
            );
        }

        if (!$adjudicationSucceeded) {
            // Adjudicator returned false — the vote/score operation failed.
            // Re-open the dispute so it can be retried.
            DisputeRepository::reopenDispute($disputeId);

            // Re-opened — invalidate caches again (status reverted to 'reviewing').
            DisputeRepository::invalidateDispute($disputeId);

            CoreLogger::error('[bcc-disputes] ' .'trust_adjudication_failed', [
                'dispute_id' => $disputeId,
                'vote_id'    => $voteId,
                'outcome'    => $outcome,
                'action'     => 'reopened_dispute',
            ]);
            return false;
        }

        // ── Adjudication succeeded — fire hooks and notify ───────────────
        if ($outcome === 'rejected') {
            // Reputation cost for filing a rejected dispute (-5 points).
            // Makes dispute spam expensive — attackers can't brute-force
            // downvote removal without paying a reputation price.
            do_action('bcc.trust.dispute_rejected_penalty', $reporterId, $disputeId);
        }

        DisputeNotificationService::enqueueAsync('bcc_disputes_email_reporter_result', [$disputeId, $reporterId, $outcome]);

        return true;
    }
}
