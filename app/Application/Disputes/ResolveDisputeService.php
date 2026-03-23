<?php

namespace BCC\Disputes\Application\Disputes;

use BCC\Core\Contracts\DisputeAdjudicationInterface;
use BCC\Core\ServiceLocator;
use BCC\Disputes\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class ResolveDisputeService
{
    public function handle(ResolveDisputeCommand $command): void
    {
        global $wpdb;

        $disputeTable = $wpdb->prefix . 'bcc_disputes';

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

        $wpdb->query('COMMIT');

        $actorId = get_current_user_id();
        $adjudicator = $this->resolveAdjudicator();

        if ($command->outcome === 'accepted') {
            if ($adjudicator instanceof DisputeAdjudicationInterface) {
                $adjudicator->acceptVoteDispute(
                    $command->disputeId,
                    $command->voteId,
                    $command->pageId,
                    $command->voterId,
                    $actorId
                );
            } else {
                Logger::logFailure('trust_adjudication_service_missing', [
                    'dispute_id' => $command->disputeId,
                    'vote_id'    => $command->voteId,
                    'page_id'    => $command->pageId,
                ]);
            }

            do_action('bcc_dispute_accepted', $command->disputeId, $command->voteId, $command->pageId, $command->voterId);
        } else {
            if ($adjudicator instanceof DisputeAdjudicationInterface) {
                $adjudicator->rejectVoteDispute(
                    $command->disputeId,
                    $command->voteId,
                    $command->pageId,
                    $actorId
                );
            } else {
                Logger::logFailure('trust_adjudication_service_missing', [
                    'dispute_id' => $command->disputeId,
                    'vote_id'    => $command->voteId,
                    'page_id'    => $command->pageId,
                ]);
            }

            do_action('bcc_dispute_rejected', $command->disputeId, $command->voteId, $command->pageId);
        }

        // Notify the reporter — reporter_id passed directly, no re-fetch needed.
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

    private function resolveAdjudicator(): ?DisputeAdjudicationInterface
    {
        $service = class_exists('\\BCC\\Core\\ServiceLocator') ? ServiceLocator::resolveDisputeAdjudication() : null;

        if ($service instanceof DisputeAdjudicationInterface) {
            return $service;
        }

        Logger::logFailure('dispute_adjudicator_missing', []);

        return null;
    }
}
