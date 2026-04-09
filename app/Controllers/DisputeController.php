<?php

namespace BCC\Disputes\Controllers;

use BCC\Core\Contracts\TrustReadServiceInterface;
use BCC\Core\Log\Logger as CoreLogger;
use BCC\Core\Permissions\Permissions;
use BCC\Core\ServiceLocator;
use BCC\Disputes\Application\Disputes\ResolveDisputeService;
use BCC\Disputes\Repositories\DisputeRepository;
use BCC\Disputes\Services\DisputeNotificationService;
use BCC\Disputes\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

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
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(); },
            'args'                => [
                'vote_id'      => ['required' => true,  'type' => 'integer', 'minimum' => 1],
                'reason'       => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field', 'minLength' => 20, 'maxLength' => 1000,
                                   'validate_callback' => function ($value) { return strlen(trim($value)) >= 20 ? true : new \WP_Error('too_short', 'Reason must be at least 20 non-whitespace characters.'); }],
                'evidence_url' => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'esc_url_raw', 'maxLength' => 2083],
            ],
        ]);

        // List votes for a page (so owner can pick which one to dispute)
        register_rest_route(self::NS, '/disputes/votes/(?P<page_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'list_votes'],
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(); },
        ]);

        // Page owner's disputes
        register_rest_route(self::NS, '/disputes/mine', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'mine'],
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(); },
        ]);

        // Panelist queue
        register_rest_route(self::NS, '/disputes/panel', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'panel_queue'],
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(); },
        ]);

        // Cast panel vote
        register_rest_route(self::NS, '/disputes/(?P<id>\d+)/vote', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'cast_vote'],
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(); },
            'args'                => [
                'decision' => ['required' => true, 'type' => 'string', 'enum' => ['accept', 'reject']],
                'note'     => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'maxLength' => 500],
            ],
        ]);

        // Report a user
        register_rest_route(self::NS, '/report-user', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'report_user'],
            'permission_callback' => function () { return is_user_logged_in() && Permissions::is_not_suspended(); },
            'args'                => [
                'reported_user_id' => ['required' => true,  'type' => 'integer', 'minimum' => 1],
                'reason_key'       => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_key',
                                       'enum'     => ['spam','harassment','fraud','misinformation','inappropriate','impersonation','other']],
                'reason_detail'    => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field', 'maxLength' => 1000,
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
        if (!Permissions::owns_page($page_id, $current_user_id)) {
            return $this->error('not_page_owner', 'Only the page owner can dispute votes.', 403);
        }

        // Can't dispute your own vote (shouldn't happen but guard it)
        if ($voter_id === $current_user_id) {
            return $this->error('cannot_self_dispute', 'You cannot dispute your own vote.', 400);
        }

        // One active dispute per vote
        if (DisputeRepository::hasActiveDisputeForVote($vote_id)) {
            return $this->error('already_disputed', 'This vote already has an active dispute.', 409);
        }

        // Max 3 disputes per page per 30 days — prevents brute-force dispute spam.
        if (DisputeRepository::countRecentDisputesForPage($page_id, 30) >= 3) {
            return $this->error('dispute_limit_reached', 'This page has reached its dispute limit. Please try again later.', 429);
        }

        // Insert dispute + panelists atomically
        $panelists = $this->selectPanelists($current_user_id, $voter_id);

        $result = DisputeRepository::createDisputeWithPanel([
            'vote_id'      => $vote_id,
            'page_id'      => $page_id,
            'reporter_id'  => $current_user_id,
            'voter_id'     => $voter_id,
            'reason'       => $reason,
            'evidence_url' => $evidence_url,
            'status'       => 'reviewing',
            'panel_size'   => BCC_DISPUTES_PANEL_SIZE,
        ], $panelists);

        $dispute_id = $result['id'];

        if (!$dispute_id) {
            // Atomic limit check inside transaction returned this error
            if ($result['db_error'] === 'dispute_limit_reached') {
                return $this->error('dispute_limit_reached', 'This page has reached its dispute limit. Please try again later.', 429);
            }
            if ($result['failed_panelist'] !== null) {
                Logger::logFailure('panel_insert_failed', [
                    'panelist_id' => $result['failed_panelist'],
                    'db_error'    => $result['db_error'],
                ]);
                return $this->error('db_error', 'Failed to assign panelists.', 500);
            }
            return $this->error('db_error', 'Failed to create dispute.', 500);
        }

        // Notifications queued async — never block the REST response with SMTP.
        foreach ($panelists as $uid) {
            DisputeNotificationService::enqueueAsync('bcc_disputes_notify_panelist', [$uid, $dispute_id, $page_id]);
        }

        CoreLogger::audit('dispute_submitted', ['dispute_id' => $dispute_id, 'user_id' => $current_user_id, 'vote_id' => $vote_id, 'panelists' => count($panelists)]);

        return rest_ensure_response([
            'dispute_id' => $dispute_id,
            'panelists'  => count($panelists),
            'message'    => 'Dispute submitted. ' . count($panelists) . ' panelists have been notified.',
        ]);
    }

    // ── List votes for a page ─────────────────────────────────────────────────

    public function list_votes(WP_REST_Request $req): WP_REST_Response
    {
        $page_id         = (int) $req->get_param('page_id');
        $current_user_id = get_current_user_id();

        if (!Permissions::owns_page($page_id, $current_user_id) && !current_user_can('manage_options')) {
            return $this->error('forbidden', 'Access denied.', 403);
        }

        if (!ServiceLocator::hasRealService(TrustReadServiceInterface::class)) {
            Logger::logFailure('trust_read_service_missing', [
                'page_id' => $page_id,
                'operation' => 'list_votes',
            ]);

            return $this->error('trust_service_unavailable', 'Trust service unavailable.', 503);
        }

        $service  = ServiceLocator::resolveTrustReadService();
        $page     = max(1, (int) $req->get_param('page'));
        $per_page = min(100, max(1, (int) ($req->get_param('per_page') ?: 50)));
        $offset   = ($page - 1) * $per_page;

        // Pagination pushed into the DB query — only the requested page is fetched.
        $total = $service->countActiveVotesForPage($page_id);
        $votes = $service->getActiveVotesForPage($page_id, $per_page, $offset);

        $voteIds = array_map(static fn(array $vote): int => (int) $vote['id'], $votes);
        $disputedVoteIds = DisputeRepository::getDisputedVoteIds($voteIds);

        $response = rest_ensure_response(array_map(function (array $vote) use ($disputedVoteIds) {
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
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $per_page)));
        return $response;
    }

    // ── My disputes ───────────────────────────────────────────────────────────

    public function mine(WP_REST_Request $req): WP_REST_Response
    {
        $userId   = get_current_user_id();
        $page     = max(1, (int) $req->get_param('page'));
        $per_page = min(100, max(1, (int) ($req->get_param('per_page') ?: 20)));
        $offset   = ($page - 1) * $per_page;

        $total = DisputeRepository::countByReporter($userId);
        $rows  = DisputeRepository::getByReporterPaginated($userId, $per_page, $offset);

        $response = rest_ensure_response(array_map([$this, 'formatDispute'], $rows));
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $per_page)));
        return $response;
    }

    // ── Panel queue ───────────────────────────────────────────────────────────

    public function panel_queue(WP_REST_Request $req): WP_REST_Response
    {
        $userId   = get_current_user_id();
        $page     = max(1, (int) $req->get_param('page'));
        $per_page = min(100, max(1, (int) ($req->get_param('per_page') ?: 20)));
        $offset   = ($page - 1) * $per_page;

        $total = DisputeRepository::countPanelQueueForUser($userId);
        $rows  = DisputeRepository::getPanelQueueForUser($userId, $per_page, $offset);

        $response = rest_ensure_response(array_map([$this, 'formatDispute'], $rows));
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $per_page)));
        return $response;
    }

    // ── Cast panel vote ───────────────────────────────────────────────────────

    public function cast_vote(WP_REST_Request $req): WP_REST_Response
    {
        $dispute_id = (int) $req->get_param('id');
        $decision   = $req->get_param('decision'); // 'accept' | 'reject'
        $note       = $req->get_param('note') ?? '';
        $userId     = get_current_user_id();

        if (!in_array($decision, ['accept', 'reject'], true)) {
            return $this->error('invalid_decision', 'Decision must be accept or reject.', 400);
        }

        // Confirm this user is assigned to this dispute
        $assignment = DisputeRepository::getPanelAssignment($dispute_id, $userId);
        if (!$assignment) {
            return $this->error('not_assigned', 'You are not assigned to this dispute.', 403);
        }
        if ($assignment->decision !== null) {
            return $this->error('already_voted', 'You have already voted on this dispute.', 409);
        }

        $dispute = DisputeRepository::getDisputeById($dispute_id);
        if (!$dispute || !in_array($dispute->status, ['pending', 'reviewing'], true)) {
            return $this->error('dispute_closed', 'This dispute is no longer open.', 410);
        }

        // Atomic transaction: lock → vote → tally → re-read (all in repository).
        $result = DisputeRepository::castPanelVoteAtomic($dispute_id, $userId, $decision, $note);

        if ($result['status'] !== 'success') {
            if (isset($result['db_error'])) {
                Logger::logFailure('cast_vote_rollback', [
                    'dispute_id' => $dispute_id,
                    'user_id'    => $userId,
                    'step'       => $result['step'] ?? 'unknown',
                    'db_error'   => $result['db_error'],
                ]);
            }
            return $this->error($result['code'], $result['message'], $result['http']);
        }

        $accepts    = $result['accepts'];
        $rejects    = $result['rejects'];
        $dispute    = $result['dispute'];
        $total_voted = $accepts + $rejects;
        $panel_size  = (int) $dispute->panel_size;
        $majority    = (int) floor($panel_size / 2) + 1;
        $should_resolve = ($accepts >= $majority || $rejects >= $majority || $total_voted >= $panel_size);

        CoreLogger::audit('dispute_vote_cast', ['dispute_id' => $dispute_id, 'user_id' => $userId, 'decision' => $decision]);

        // Resolve outside the transaction. Multiple concurrent voters may
        // evaluate $should_resolve = true; ResolveDisputeService is the
        // authoritative idempotency gate (WHERE status IN ('pending','reviewing')).
        if ($should_resolve) {
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
        $dispute_id = (int) $req->get_param('id');
        $decision   = $req->get_param('decision'); // 'accepted' | 'rejected'

        if (!in_array($decision, ['accepted', 'rejected'], true)) {
            return $this->error('invalid_decision', 'Decision must be accepted or rejected.', 400);
        }

        $dispute = DisputeRepository::getDisputeById($dispute_id);

        if (!$dispute) {
            return $this->error('not_found', 'Dispute not found.', 404);
        }

        if (!in_array($dispute->status, ['pending', 'reviewing'], true)) {
            return $this->error('already_resolved', 'This dispute has already been resolved.', 409);
        }

        $success = $this->resolve($dispute_id, (int) $dispute->vote_id, (int) $dispute->page_id, (int) $dispute->voter_id, (int) $dispute->reporter_id, $decision);

        if (!$success) {
            return $this->error('resolution_failed', 'Dispute could not be resolved. Trust engine may be unavailable.', 503);
        }

        return rest_ensure_response(['message' => 'Dispute resolved as ' . $decision . '.']);
    }

    // ── Resolution logic ──────────────────────────────────────────────────────

    public function resolve(int $dispute_id, int $vote_id, int $page_id, int $voter_id, int $reporter_id, string $outcome): bool
    {
        return (new ResolveDisputeService())->handle($dispute_id, $vote_id, $page_id, $voter_id, $reporter_id, $outcome);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getVote(int $vote_id): ?object
    {
        if (!ServiceLocator::hasRealService(TrustReadServiceInterface::class)) {
            Logger::logFailure('trust_read_service_missing', [
                'vote_id' => $vote_id,
            ]);
            return null;
        }

        $service = ServiceLocator::resolveTrustReadService();
        $vote = $service->getVoteById($vote_id);

        return $vote ? (object) $vote : null;
    }

    /**
     * Pick up to BCC_DISPUTES_PANEL_SIZE Gold/Platinum users,
     * excluding the reporter and the voter.
     */
    private function selectPanelists(int $reporter_id, int $voter_id): array
    {
        $needed = BCC_DISPUTES_PANEL_SIZE;

        if (!ServiceLocator::hasRealService(TrustReadServiceInterface::class)) {
            Logger::logFailure('trust_read_service_missing', [
                'reporter_id' => $reporter_id,
                'voter_id' => $voter_id,
                'operation' => 'select_panelists',
            ]);

            return [];
        }

        $service = ServiceLocator::resolveTrustReadService();
        return $service->getEligiblePanelistUserIds([$reporter_id, $voter_id], $needed);
    }

    private function formatDispute(object $d): array
    {
        return [
            'id'            => (int) $d->id,
            'vote_id'       => (int) $d->vote_id,
            'page_id'       => (int) $d->page_id,
            'page_title'    => $d->page_title ?? '',
            'voter_name'    => $d->voter_name ?? 'Unknown',
            'reporter_name' => $d->reporter_name ?? null,
            'reason'        => $d->reason,
            'evidence_url'  => $d->evidence_url ?? '',
            'status'        => $d->status,
            'accepts'       => (int) $d->panel_accepts,
            'rejects'       => (int) $d->panel_rejects,
            'panel_size'    => (int) $d->panel_size,
            'my_decision'   => $d->my_decision ?? null,
            'created_at'    => $d->created_at,
            'resolved_at'   => $d->resolved_at ?? null,
        ];
    }

    // ── Report user ───────────────────────────────────────────────────────────

    public function report_user( WP_REST_Request $req ): WP_REST_Response
    {
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

        $report_id = DisputeRepository::createReport($reported_id, $reporter_id, $reason_key, $reason_detail);
        if ( ! $report_id ) {
            return $this->error('db_error', 'Failed to submit report.', 500);
        }

        // Emails queued async — never block the REST response with SMTP.
        DisputeNotificationService::enqueueAsync('bcc_disputes_email_reported_user', [$reported_id]);
        DisputeNotificationService::enqueueAsync('bcc_disputes_email_admin_report', [$report_id, $reporter_id, $reported_id, $reason_key, $reason_detail]);

        CoreLogger::audit('user_reported', ['reporter' => $reporter_id, 'reported' => $reported_id, 'reason' => $reason_key]);

        return rest_ensure_response([
            'message' => 'Your report has been submitted. Our team will review it shortly.',
        ]);
    }

    /**
     * Throttle an action per user.
     *
     * Uses trust-engine's atomic RateLimiter when available (gains trust-tier
     * awareness and Cloudflare-aware IP resolution). Falls back to simple
     * transient-based throttle when trust-engine is inactive.
     *
     * @return WP_REST_Response|null  Error response if throttled, null if allowed.
     */
    private function throttle(string $action, int $user_id, int $cooldown_seconds = 60): ?WP_REST_Response
    {
        $key = "bcc_throttle_{$action}_{$user_id}";

        if (class_exists('\\BCC\\Trust\\Security\\RateLimiter')) {
            $allowed = \BCC\Trust\Security\RateLimiter::allowByKey($key, 1, $cooldown_seconds);
        } else {
            // Fallback: simple transient-based throttle
            $allowed = !get_transient($key);
            if ($allowed) {
                set_transient($key, 1, $cooldown_seconds);
            }
        }

        if (!$allowed) {
            return $this->error(
                'rate_limited',
                sprintf('Please wait %d seconds before trying again.', $cooldown_seconds),
                429
            );
        }
        return null;
    }

    private function error(string $code, string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['code' => $code, 'message' => $message], $status);
    }

}
