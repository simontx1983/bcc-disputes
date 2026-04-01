<?php

namespace BCC\Disputes\Application\Disputes;

use BCC\Core\Contracts\DisputeAdjudicationInterface;
use BCC\Core\ServiceLocator;
use BCC\Disputes\Repositories\DisputeRepository;
use BCC\Disputes\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class ResolveDisputeService
{
    public function handle(ResolveDisputeCommand $command): void
    {
        global $wpdb;

        $disputeTable = DisputeRepository::disputes_table();

        $wpdb->query('START TRANSACTION');

        // Atomic status transition: WHERE status IN ('pending','reviewing')
        // prevents double-resolution under concurrent requests.
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$disputeTable} SET status = %s, resolved_at = %s
             WHERE id = %d AND status IN ('pending','reviewing')",
            $command->outcome,
            current_time('mysql'),
            $command->disputeId
        ));

        if ($result === false) {
            $wpdb->query('ROLLBACK');
            Logger::logFailure('resolve_rollback', [
                'dispute_id' => $command->disputeId,
                'outcome'    => $command->outcome,
                'step'       => 'status_update',
                'db_error'   => $wpdb->last_error,
            ]);
            return;
        }

        if ($result === 0) {
            // Already resolved by a concurrent request — safe to skip
            $wpdb->query('ROLLBACK');
            Logger::logFailure('resolve_race_skipped', [
                'dispute_id' => $command->disputeId,
                'outcome'    => $command->outcome,
            ]);
            return;
        }

        // ── Pre-commit gate: verify trust engine is available ────────────
        //
        // Check BEFORE committing the resolution. If the adjudicator is not
        // available, ROLLBACK so the dispute stays in its original state.
        // This avoids the crash-between-commit-and-revert window.
        $hasRealAdjudicator = class_exists('\\BCC\\Core\\ServiceLocator')
            && ServiceLocator::hasRealService(DisputeAdjudicationInterface::class);

        if (!$hasRealAdjudicator) {
            $wpdb->query('ROLLBACK');
            Logger::logFailure('trust_adjudication_service_unavailable', [
                'dispute_id' => $command->disputeId,
                'outcome'    => $command->outcome,
            ]);
            return;
        }

        $wpdb->query('COMMIT');

        // ── Post-commit: execute adjudication ────────────────────────────
        //
        // The dispute is now marked resolved in the DB. We must attempt the
        // trust-engine operation. If it fails, re-open the dispute.
        $actorId     = get_current_user_id();
        $adjudicator = ServiceLocator::resolveDisputeAdjudication();

        if ($command->outcome === 'accepted') {
            $adjudicationSucceeded = $adjudicator->acceptVoteDispute(
                $command->disputeId,
                $command->voteId,
                $command->pageId,
                $command->voterId,
                $actorId
            );
        } else {
            $adjudicationSucceeded = $adjudicator->rejectVoteDispute(
                $command->disputeId,
                $command->voteId,
                $command->pageId,
                $actorId
            );
        }

        if (!$adjudicationSucceeded) {
            // Adjudicator returned false — the vote/score operation failed.
            // Re-open the dispute so it can be retried.
            $wpdb->update(
                $disputeTable,
                ['status' => 'reviewing', 'resolved_at' => null],
                ['id' => $command->disputeId],
                ['%s', '%s'],
                ['%d']
            );

            Logger::logFailure('trust_adjudication_failed', [
                'dispute_id' => $command->disputeId,
                'vote_id'    => $command->voteId,
                'outcome'    => $command->outcome,
                'action'     => 'reopened_dispute',
            ]);
            return;
        }

        // ── Adjudication succeeded — fire hooks and notify ───────────────
        if ($command->outcome === 'accepted') {
            do_action('bcc_dispute_accepted', $command->disputeId, $command->voteId, $command->pageId, $command->voterId);
        } else {
            do_action('bcc_dispute_rejected', $command->disputeId, $command->voteId, $command->pageId);
        }

        $reporter = get_userdata($command->reporterId);
        if ($reporter && $reporter->user_email) {
            $subject = $command->outcome === 'accepted'
                ? '[BCC] Your dispute was accepted — vote removed'
                : '[BCC] Your dispute was reviewed — vote stands';
            $body = $command->outcome === 'accepted'
                ? 'Good news! The community panel reviewed your dispute and agreed the vote was invalid. It has been removed from your trust score.'
                : 'The community panel reviewed your dispute and decided the vote was valid. The vote remains on your profile.';
            wp_mail($reporter->user_email, $subject, $body);
        }
    }
}
