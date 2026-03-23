<?php

namespace BCC\Disputes\Controllers;

use BCC\Core\Contracts\TrustReadServiceInterface;
use BCC\Core\ServiceLocator;
use BCC\Disputes\Application\Disputes\ResolveDisputeCommand;
use BCC\Disputes\Plugin;
use BCC\Disputes\Repositories\DisputeRepository;
use BCC\Disputes\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeController
{
    const NS = 'bcc/v1';

    public function register_routes(): void
    {
        // Submit a dispute (page owner)
        register_rest_route(self::NS, '/disputes', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'submit'],
            'permission_callback' => 'is_user_logged_in',
            'args'                => [
                'vote_id'      => ['required' => true,  'type' => 'integer', 'minimum' => 1],
                'reason'       => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field', 'minLength' => 20],
                'evidence_url' => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'esc_url_raw'],
            ],
        ]);

        // List votes for a page (so owner can pick which one to dispute)
        register_rest_route(self::NS, '/disputes/votes/(?P<page_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'list_votes'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // Page owner's disputes
        register_rest_route(self::NS, '/disputes/mine', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'mine'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // Panelist queue
        register_rest_route(self::NS, '/disputes/panel', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'panel_queue'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // Cast panel vote
        register_rest_route(self::NS, '/disputes/(?P<id>\d+)/vote', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'cast_vote'],
            'permission_callback' => 'is_user_logged_in',
            'args'                => [
                'decision' => ['required' => true, 'type' => 'string', 'enum' => ['accept', 'reject']],
                'note'     => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            ],
        ]);

        // Report a user
        register_rest_route(self::NS, '/report-user', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'report_user'],
            'permission_callback' => 'is_user_logged_in',
            'args'                => [
                'reported_user_id' => ['required' => true,  'type' => 'integer', 'minimum' => 1],
                'reason_key'       => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_key',
                                       'enum'     => ['spam','harassment','fraud','misinformation','inappropriate','impersonation','other']],
                'reason_detail'    => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field',
                                       'default'  => ''],
            ],
        ]);

        // Admin force-resolve
        register_rest_route(self::NS, '/disputes/(?P<id>\d+)/resolve', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'force_resolve'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'args'                => [
                'decision' => ['required' => true, 'type' => 'string', 'enum' => ['accepted', 'rejected']],
            ],
        ]);
    }

    // ── Submit dispute ────────────────────────────────────────────────────────

    public function submit(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;

        $current_user_id = get_current_user_id();

        $throttled = $this->throttle('dispute_submit', $current_user_id, 60);
        if ($throttled) return $throttled;

        $vote_id         = (int) $req->get_param('vote_id');
        $reason          = $req->get_param('reason');
        $evidence_url    = $req->get_param('evidence_url') ?? '';

        // Verify vote exists
        $vote = $this->getVote($vote_id);
        if (!$vote) {
            return $this->error('vote_not_found', 'Vote not found.', 404);
        }

        $page_id  = (int) $vote->page_id;
        $voter_id = (int) $vote->voter_user_id;

        // Only page owner can dispute
        if (!$this->isPageOwner($page_id, $current_user_id)) {
            return $this->error('not_page_owner', 'Only the page owner can dispute votes.', 403);
        }

        // Can't dispute your own vote (shouldn't happen but guard it)
        if ($voter_id === $current_user_id) {
            return $this->error('cannot_self_dispute', 'You cannot dispute your own vote.', 400);
        }

        $disputeTable = DisputeRepository::disputes_table();
        $panelTable   = DisputeRepository::panel_table();

        // One active dispute per vote
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$disputeTable} WHERE vote_id = %d AND status IN ('pending','reviewing') LIMIT 1",
            $vote_id
        ));
        if ($existing) {
            return $this->error('already_disputed', 'This vote already has an active dispute.', 409);
        }

        // Insert dispute
        $wpdb->insert($disputeTable, [
            'vote_id'      => $vote_id,
            'page_id'      => $page_id,
            'reporter_id'  => $current_user_id,
            'voter_id'     => $voter_id,
            'reason'       => $reason,
            'evidence_url' => $evidence_url,
            'status'       => 'reviewing',
            'panel_size'   => BCC_DISPUTES_PANEL_SIZE,
        ], ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d']);

        $dispute_id = $wpdb->insert_id;

        if (!$dispute_id) {
            return $this->error('db_error', 'Failed to create dispute.', 500);
        }

        // Assign panelists
        $panelists = $this->selectPanelists($current_user_id, $voter_id);

        foreach ($panelists as $uid) {
            $wpdb->insert($panelTable, [
                'dispute_id'       => $dispute_id,
                'panelist_user_id' => $uid,
            ], ['%d', '%d']);

            if ($wpdb->last_error) {
                Logger::logFailure('panel_insert_failed', [
                    'dispute_id'  => $dispute_id,
                    'panelist_id' => $uid,
                    'db_error'    => $wpdb->last_error,
                ]);
            }

            $this->notifyPanelist($uid, $dispute_id, $page_id);
        }

        return rest_ensure_response([
            'dispute_id' => $dispute_id,
            'panelists'  => count($panelists),
            'message'    => 'Dispute submitted. ' . count($panelists) . ' panelists have been notified.',
        ]);
    }

    // ── List votes for a page ─────────────────────────────────────────────────

    public function list_votes(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;


        $page_id         = (int) $req->get_param('page_id');
        $current_user_id = get_current_user_id();

        if (!$this->isPageOwner($page_id, $current_user_id) && !current_user_can('manage_options')) {
            return $this->error('forbidden', 'Access denied.', 403);
        }

        $disputes_table = DisputeRepository::disputes_table();
        $service = class_exists('\\BCC\\Core\\ServiceLocator') ? ServiceLocator::resolveTrustReadService() : null;

        if (!$service instanceof TrustReadServiceInterface) {
            Logger::logFailure('trust_read_service_missing', [
                'page_id' => $page_id,
                'operation' => 'list_votes',
            ]);

            return $this->error('trust_service_unavailable', 'Trust service unavailable.', 503);
        }

        $votes = $service->getActiveVotesForPage($page_id);
        $voteIds = array_map(static fn(array $vote): int => (int) $vote['id'], $votes);
        $disputedVoteIds = [];

        if (!empty($voteIds)) {
            $placeholders = implode(',', array_fill(0, count($voteIds), '%d'));
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT vote_id
                 FROM {$disputes_table}
                 WHERE vote_id IN ({$placeholders})
                   AND status IN ('pending','reviewing','accepted')",
                ...$voteIds
            ));

            $disputedVoteIds = array_fill_keys(array_map('intval', $rows), true);
        }

        return rest_ensure_response(array_map(function (array $vote) use ($disputedVoteIds) {
            return [
                'id' => (int) $vote['id'],
                'voter_name' => $vote['voter_name'] ?? 'Unknown',
                'vote_type' => (int) $vote['vote_type'] > 0 ? 'upvote' : 'downvote',
                'weight' => round((float) $vote['weight'], 2),
                'reason' => $vote['reason'] ?? '',
                'date' => $vote['created_at'] ?? null,
                'already_disputed' => isset($disputedVoteIds[(int) $vote['id']]),
            ];
        }, $votes));
    }

    // ── My disputes ───────────────────────────────────────────────────────────

    public function mine(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;

        $userId       = get_current_user_id();
        $disputeTable = DisputeRepository::disputes_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, p.post_title as page_title, u.display_name as voter_name
             FROM {$disputeTable} d
             LEFT JOIN {$wpdb->posts} p ON d.page_id = p.ID
             LEFT JOIN {$wpdb->users} u ON d.voter_id = u.ID
             WHERE d.reporter_id = %d
             ORDER BY d.created_at DESC",
            $userId
        ));

        return rest_ensure_response(array_map([$this, 'formatDispute'], $rows));
    }

    // ── Panel queue ───────────────────────────────────────────────────────────

    public function panel_queue(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;

        $userId       = get_current_user_id();
        $disputeTable = DisputeRepository::disputes_table();
        $panelTable   = DisputeRepository::panel_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, pan.decision as my_decision, pan.voted_at as my_voted_at,
                    p.post_title as page_title, u.display_name as voter_name
             FROM {$panelTable} pan
             JOIN {$disputeTable} d ON d.id = pan.dispute_id
             LEFT JOIN {$wpdb->posts} p ON d.page_id = p.ID
             LEFT JOIN {$wpdb->users} u ON d.voter_id = u.ID
             WHERE pan.panelist_user_id = %d
               AND d.status IN ('pending','reviewing')
             ORDER BY d.created_at ASC",
            $userId
        ));

        return rest_ensure_response(array_map([$this, 'formatDispute'], $rows));
    }

    // ── Cast panel vote ───────────────────────────────────────────────────────

    public function cast_vote(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;

        $dispute_id = (int) $req->get_param('id');
        $decision   = $req->get_param('decision'); // 'accept' | 'reject'
        $note       = $req->get_param('note') ?? '';
        $userId     = get_current_user_id();

        $disputeTable = DisputeRepository::disputes_table();
        $panelTable   = DisputeRepository::panel_table();

        // Confirm this user is assigned to this dispute
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$panelTable} WHERE dispute_id = %d AND panelist_user_id = %d LIMIT 1",
            $dispute_id, $userId
        ));
        if (!$assignment) {
            return $this->error('not_assigned', 'You are not assigned to this dispute.', 403);
        }
        if ($assignment->decision !== null) {
            return $this->error('already_voted', 'You have already voted on this dispute.', 409);
        }

        $dispute = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$disputeTable} WHERE id = %d LIMIT 1",
            $dispute_id
        ));
        if (!$dispute || !in_array($dispute->status, ['pending', 'reviewing'], true)) {
            return $this->error('dispute_closed', 'This dispute is no longer open.', 410);
        }

        // Transaction: vote recording + tally must be atomic.
        // If the vote records but tally fails, the panelist can't revote and
        // the dispute may never auto-resolve.
        $wpdb->query('START TRANSACTION');

        // Atomic vote recording: UPDATE … WHERE decision IS NULL prevents double-voting
        // even under concurrent requests (the second request gets 0 affected rows).
        $voted = $wpdb->query($wpdb->prepare(
            "UPDATE {$panelTable} SET decision = %s, note = %s, voted_at = %s
             WHERE dispute_id = %d AND panelist_user_id = %d AND decision IS NULL",
            $decision, $note, current_time('mysql'), $dispute_id, $userId
        ));

        if ($voted === false) {
            $wpdb->query('ROLLBACK');
            Logger::logFailure('cast_vote_rollback', [
                'dispute_id' => $dispute_id,
                'user_id'    => $userId,
                'step'       => 'panel_vote_update',
                'db_error'   => $wpdb->last_error,
            ]);
            return $this->error('db_error', 'Failed to record vote.', 500);
        }
        if ($voted === 0) {
            $wpdb->query('ROLLBACK');
            return $this->error('already_voted', 'You have already voted on this dispute.', 409);
        }

        // Atomic tally update — avoids race condition when two panelists vote simultaneously
        $col = $decision === 'accept' ? 'panel_accepts' : 'panel_rejects';
        $tally_ok = $wpdb->query($wpdb->prepare(
            "UPDATE {$disputeTable} SET {$col} = {$col} + 1 WHERE id = %d",
            $dispute_id
        ));

        if ($tally_ok === false) {
            $wpdb->query('ROLLBACK');
            Logger::logFailure('cast_vote_rollback', [
                'dispute_id' => $dispute_id,
                'user_id'    => $userId,
                'step'       => 'tally_increment',
                'db_error'   => $wpdb->last_error,
            ]);
            return $this->error('db_error', 'Failed to update tally.', 500);
        }

        $wpdb->query('COMMIT');

        // Re-fetch for accurate majority check after atomic update
        $dispute = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$disputeTable} WHERE id = %d LIMIT 1",
            $dispute_id
        ));

        $accepts     = (int) $dispute->panel_accepts;
        $rejects     = (int) $dispute->panel_rejects;
        $total_voted = $accepts + $rejects;
        $panel_size  = (int) $dispute->panel_size;
        $majority    = (int) floor($panel_size / 2) + 1;

        // Auto-resolve when all panelists have voted or a majority is reached
        if ($accepts >= $majority || $rejects >= $majority || $total_voted >= $panel_size) {
            $final = $accepts > $rejects ? 'accepted' : 'rejected';
            $this->resolve($dispute_id, (int) $dispute->vote_id, (int) $dispute->page_id, (int) $dispute->voter_id, (int) $dispute->reporter_id, $final);
        }

        return rest_ensure_response([
            'message'  => 'Vote recorded.',
            'decision' => $decision,
            'tally'    => ['accept' => $accepts, 'reject' => $rejects],
        ]);
    }

    // ── Admin force-resolve ───────────────────────────────────────────────────

    public function force_resolve(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;

        $dispute_id = (int) $req->get_param('id');
        $decision   = $req->get_param('decision'); // 'accepted' | 'rejected'

        $disputeTable = DisputeRepository::disputes_table();
        $dispute      = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$disputeTable} WHERE id = %d LIMIT 1", $dispute_id));

        if (!$dispute) {
            return $this->error('not_found', 'Dispute not found.', 404);
        }

        if (!in_array($dispute->status, ['pending', 'reviewing'], true)) {
            return $this->error('already_resolved', 'This dispute has already been resolved.', 409);
        }

        $this->resolve($dispute_id, (int) $dispute->vote_id, (int) $dispute->page_id, (int) $dispute->voter_id, (int) $dispute->reporter_id, $decision);

        return rest_ensure_response(['message' => 'Dispute resolved as ' . $decision . '.']);
    }

    // ── Resolution logic ──────────────────────────────────────────────────────

    public function resolve(int $dispute_id, int $vote_id, int $page_id, int $voter_id, int $reporter_id, string $outcome): void
    {
        Plugin::instance()->resolve_dispute_service()->handle(new ResolveDisputeCommand(
            $dispute_id,
            $vote_id,
            $page_id,
            $voter_id,
            $reporter_id,
            $outcome
        ));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getVote(int $vote_id): ?object
    {
        $service = class_exists('\\BCC\\Core\\ServiceLocator') ? ServiceLocator::resolveTrustReadService() : null;

        if (!$service instanceof TrustReadServiceInterface) {
            Logger::logFailure('trust_read_service_missing', [
                'vote_id' => $vote_id,
            ]);
            return null;
        }

        $vote = $service->getVoteById($vote_id);

        return $vote ? (object) $vote : null;
    }

    private function isPageOwner(int $page_id, int $user_id): bool
    {
        if (class_exists('BCC\\Core\\Permissions\\Permissions')) {
            return \BCC\Core\Permissions\Permissions::owns_page($page_id, $user_id);
        }
        // Fallback: check via trust engine helper or post author
        if (function_exists('bcc_trust_get_page_owner')) {
            return bcc_trust_get_page_owner($page_id) === $user_id;
        }
        $post = get_post($page_id);
        return $post && (int) $post->post_author === $user_id;
    }

    /**
     * Pick up to BCC_DISPUTES_PANEL_SIZE Gold/Platinum users,
     * excluding the reporter and the voter.
     */
    private function selectPanelists(int $reporter_id, int $voter_id): array
    {
        $needed = defined('BCC_DISPUTES_PANEL_SIZE') ? BCC_DISPUTES_PANEL_SIZE : 3;
        $service = class_exists('\\BCC\\Core\\ServiceLocator') ? ServiceLocator::resolveTrustReadService() : null;


        if (!$service instanceof TrustReadServiceInterface) {
            Logger::logFailure('trust_read_service_missing', [
                'reporter_id' => $reporter_id,
                'voter_id' => $voter_id,
                'operation' => 'select_panelists',
            ]);

            return [];
        }

        return $service->getEligiblePanelistUserIds([$reporter_id, $voter_id], $needed);
    }

    private function notifyPanelist(int $uid, int $dispute_id, int $page_id): void
    {
        $user = get_userdata($uid);
        if (!$user || !$user->user_email) {
            return;
        }
        $page = get_post($page_id);
        $subject = '[BCC] You have been selected as a dispute panelist';
        $body = sprintf(
            "Hello %s,\n\nA dispute has been filed against a vote on \"%s\". As a Gold/Platinum member, you've been selected to help review it.\n\nLog in and visit your dispute queue to cast your vote within %d days.\n\nDispute ID: #%d\n",
            $user->display_name,
            $page ? $page->post_title : "a project page",
            BCC_DISPUTES_TTL_DAYS,
            $dispute_id
        );
        wp_mail($user->user_email, $subject, $body);
    }

    private function formatDispute(object $d): array
    {
        return [
            'id'           => (int) $d->id,
            'vote_id'      => (int) $d->vote_id,
            'page_id'      => (int) $d->page_id,
            'page_title'   => $d->page_title ?? '',
            'voter_name'   => $d->voter_name ?? 'Unknown',
            'reason'       => $d->reason,
            'evidence_url' => $d->evidence_url ?? '',
            'status'       => $d->status,
            'accepts'      => (int) $d->panel_accepts,
            'rejects'      => (int) $d->panel_rejects,
            'panel_size'   => (int) $d->panel_size,
            'my_decision'  => $d->my_decision ?? null,
            'created_at'   => $d->created_at,
            'resolved_at'  => $d->resolved_at ?? null,
        ];
    }

    // ── Report user ───────────────────────────────────────────────────────────

    public function report_user( WP_REST_Request $req ): WP_REST_Response
    {
        global $wpdb;

        $reporter_id      = get_current_user_id();

        $throttled = $this->throttle('report_user', $reporter_id, 60);
        if ($throttled) return $throttled;

        $reported_id      = (int) $req->get_param('reported_user_id');
        $reason_key       = $req->get_param('reason_key');
        $reason_detail    = (string) $req->get_param('reason_detail');

        if ( $reported_id === $reporter_id ) {
            return $this->error('cannot_self_report', 'You cannot report yourself.', 400);
        }

        $reported_user = get_userdata( $reported_id );
        if ( ! $reported_user ) {
            return $this->error('user_not_found', 'User not found.', 404);
        }

        if ( $reason_key === 'other' && strlen( $reason_detail ) < 10 ) {
            return $this->error('detail_required', 'Please provide at least 10 characters describing your reason.', 400);
        }

        $reportTable = DisputeRepository::user_reports_table();

        $wpdb->insert( $reportTable, [
            'reported_id'   => $reported_id,
            'reporter_id'   => $reporter_id,
            'reason_key'    => $reason_key,
            'reason_detail' => $reason_detail,
            'status'        => 'open',
        ], [ '%d', '%d', '%s', '%s', '%s' ] );

        if ( ! $wpdb->insert_id ) {
            return $this->error('db_error', 'Failed to submit report.', 500);
        }

        $report_id = (int) $wpdb->insert_id;

        $this->emailReportedUser( $reported_user );
        $this->emailAdminReport( $report_id, $reporter_id, $reported_user, $reason_key, $reason_detail );

        return rest_ensure_response([
            'message' => 'Your report has been submitted. Our team will review it shortly.',
        ]);
    }

    private function emailReportedUser( WP_User $reported_user ): void
    {
        $site_name = get_bloginfo('name');
        $subject   = sprintf( '[%s] Your account has received a report', $site_name );
        $body      = sprintf(
            "Hello %s,\n\nWe wanted to let you know that a report has been submitted regarding your account on %s.\n\nOur moderation team will review the report and take appropriate action if necessary. No action is required from you at this time.\n\nIf you believe this report was made in error, you can contact us by replying to this email.\n\nThe %s Team",
            $reported_user->display_name,
            $site_name,
            $site_name
        );
        wp_mail( $reported_user->user_email, $subject, $body );
    }

    private function emailAdminReport( int $report_id, int $reporter_id, WP_User $reported_user, string $reason_key, string $reason_detail ): void
    {
        $admin_email   = get_option('admin_email');
        $site_name     = get_bloginfo('name');
        $reporter      = get_userdata( $reporter_id );
        $admin_url     = admin_url( 'admin.php?page=bcc-reports' );

        $reason_labels = [
            'spam'            => 'Spam or unsolicited content',
            'harassment'      => 'Harassment or bullying',
            'fraud'           => 'Fraudulent activity or scam',
            'misinformation'  => 'False or misleading information',
            'inappropriate'   => 'Inappropriate content',
            'impersonation'   => 'Impersonating another person',
            'other'           => 'Other',
        ];

        $subject = sprintf( '[%s Admin] User Report #%d — %s', $site_name, $report_id, $reason_labels[ $reason_key ] ?? $reason_key );
        $body    = sprintf(
            "A new user report has been submitted on %s.\n\n" .
            "REPORT DETAILS\n" .
            "--------------\n" .
            "Report ID:       #%d\n" .
            "Date/Time:       %s\n\n" .
            "REPORTED USER\n" .
            "-------------\n" .
            "User ID:         %d\n" .
            "Display Name:    %s\n" .
            "Email:           %s\n" .
            "Profile URL:     %s\n\n" .
            "REPORTER\n" .
            "--------\n" .
            "User ID:         %d\n" .
            "Display Name:    %s\n" .
            "Email:           %s\n\n" .
            "REASON\n" .
            "------\n" .
            "Category:        %s\n" .
            "Details:         %s\n\n" .
            "Review this report in the admin dashboard:\n%s\n",
            $site_name,
            $report_id,
            current_time('mysql'),
            $reported_user->ID,
            $reported_user->display_name,
            $reported_user->user_email,
            get_author_posts_url( $reported_user->ID ),
            $reporter_id,
            $reporter ? $reporter->display_name : 'Unknown',
            $reporter ? $reporter->user_email   : 'Unknown',
            $reason_labels[ $reason_key ] ?? $reason_key,
            $reason_detail ?: '(none provided)',
            $admin_url
        );

        wp_mail( $admin_email, $subject, $body );
    }

    /**
     * Throttle an action per user using transients.
     *
     * @return WP_REST_Response|null  Error response if throttled, null if allowed.
     */
    private function throttle(string $action, int $user_id, int $cooldown_seconds = 60): ?WP_REST_Response
    {
        $key = "bcc_throttle_{$action}_{$user_id}";
        if (get_transient($key)) {
            return $this->error(
                'rate_limited',
                sprintf('Please wait %d seconds before trying again.', $cooldown_seconds),
                429
            );
        }
        set_transient($key, 1, $cooldown_seconds);
        return null;
    }

    private function error(string $code, string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['code' => $code, 'message' => $message], $status);
    }

}
