<?php

namespace BCC\Disputes\Controllers;

use BCC\Core\Contracts\TrustReadServiceInterface;
use BCC\Core\Log\Logger as CoreLogger;
use BCC\Core\Permissions\Permissions;
use BCC\Core\ServiceLocator;
use BCC\Disputes\Services\ResolveDisputeService;
use BCC\Disputes\Repositories\DisputeRepository;
use BCC\Disputes\Services\DisputeNotificationService;
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

        // Operational health endpoint (admin only).
        register_rest_route(self::NS, '/disputes/health', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'health'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
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

        // Only downvotes can be disputed — upvotes benefit the page owner,
        // so disputing one is either an error or an attempt to weaponize
        // the fraud penalty against the voter.
        if ((int) $vote->vote_type > 0) {
            return $this->error('upvote_not_disputable', 'Only downvotes can be disputed.', 400);
        }

        // One active dispute per vote
        if (DisputeRepository::hasActiveDisputeForVote($vote_id)) {
            return $this->error('already_disputed', 'This vote already has an active dispute.', 409);
        }

        // Insert dispute + panelists atomically (includes FOR UPDATE limit check)
        $panelists = $this->selectPanelists($current_user_id, $voter_id);

        if (count($panelists) < BCC_DISPUTES_PANEL_SIZE) {
            return $this->error('insufficient_panelists',
                'Cannot create dispute — not enough eligible panelists available. The system requires at least '
                . BCC_DISPUTES_PANEL_SIZE . ' independent panelists.', 503);
        }

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
            if ($result['db_error'] === 'reporter_limit_reached') {
                return $this->error('reporter_limit_reached', 'You have too many active disputes. Please wait for existing disputes to resolve.', 429);
            }
            if ($result['db_error'] === 'vote_no_longer_active') {
                return $this->error('vote_no_longer_active', 'This vote is no longer active and cannot be disputed.', 410);
            }
            if ($result['db_error'] === 'already_disputed') {
                return $this->error('already_disputed', 'This vote already has an active dispute.', 409);
            }
            if ($result['failed_panelist'] !== null) {
                CoreLogger::error('[bcc-disputes] ' .'panel_insert_failed', [
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
            CoreLogger::error('[bcc-disputes] ' .'trust_read_service_missing', [
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
        $page_id  = $req->get_param('page_id') !== null ? (int) $req->get_param('page_id') : null;

        $total = DisputeRepository::countByReporter($userId, $page_id);
        $rows  = DisputeRepository::getByReporterPaginated($userId, $per_page, $offset, $page_id);

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

        $throttled = $this->throttle('dispute_vote', $userId, 10);
        if ($throttled) return $throttled;

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
        if (!$dispute || $dispute->status !== 'reviewing') {
            return $this->error('dispute_closed', 'This dispute is no longer open.', 410);
        }

        // Atomic transaction: lock → vote → tally → re-read (all in repository).
        /** @var array{status: string, code: string, message: string, http: int, dispute: object|null, accepts: int, rejects: int, step?: string, db_error?: string} $result */
        $result = DisputeRepository::castPanelVoteAtomic($dispute_id, $userId, $decision, $note);

        if ($result['status'] !== 'success') {
            if (isset($result['db_error'])) {
                CoreLogger::error('[bcc-disputes] ' .'cast_vote_rollback', [
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
        $panel_size = (int) $dispute->panel_size;

        // Single source of truth for verdict calculation.
        $verdict = DisputeRepository::computeVerdict($accepts, $rejects, $panel_size);

        CoreLogger::audit('dispute_vote_cast', ['dispute_id' => $dispute_id, 'user_id' => $userId, 'decision' => $decision]);

        // Resolve outside the transaction. Multiple concurrent voters may
        // evaluate should_resolve = true; ResolveDisputeService is the
        // authoritative idempotency gate (WHERE status = 'reviewing').
        if ($verdict['should_resolve']) {
            $final = $verdict['outcome'];
            $this->resolve($dispute_id, (int) $dispute->vote_id, (int) $dispute->page_id, (int) $dispute->voter_id, (int) $dispute->reporter_id, $final);
        }

        // Tally intentionally omitted from response to preserve
        // independent deliberation — panelists must not see running
        // totals before all votes are in.
        return rest_ensure_response([
            'message'  => 'Vote recorded.',
            'decision' => $decision,
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

        if ($dispute->status !== 'reviewing') {
            return $this->error('already_resolved', 'This dispute has already been resolved.', 409);
        }

        $adminId = get_current_user_id();

        $success = $this->resolve($dispute_id, (int) $dispute->vote_id, (int) $dispute->page_id, (int) $dispute->voter_id, (int) $dispute->reporter_id, $decision);

        if (!$success) {
            return $this->error('resolution_failed', 'Dispute could not be resolved. Trust engine may be unavailable.', 503);
        }

        CoreLogger::audit('dispute_force_resolved', [
            'dispute_id' => $dispute_id,
            'admin_id'   => $adminId,
            'decision'   => $decision,
        ]);

        return rest_ensure_response(['message' => 'Dispute resolved as ' . $decision . '.']);
    }

    // ── Resolution ────────────────────────────────────────────────────────────

    private function resolve(int $disputeId, int $voteId, int $pageId, int $voterId, int $reporterId, string $outcome): bool
    {
        return (new ResolveDisputeService())->handle($disputeId, $voteId, $pageId, $voterId, $reporterId, $outcome, get_current_user_id());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getVote(int $vote_id): ?object
    {
        if (!ServiceLocator::hasRealService(TrustReadServiceInterface::class)) {
            CoreLogger::error('[bcc-disputes] ' .'trust_read_service_missing', [
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
     *
     * @return int[]
     */
    private function selectPanelists(int $reporter_id, int $voter_id): array
    {
        $needed = BCC_DISPUTES_PANEL_SIZE;

        if (!ServiceLocator::hasRealService(TrustReadServiceInterface::class)) {
            CoreLogger::error('[bcc-disputes] ' .'trust_read_service_missing', [
                'reporter_id' => $reporter_id,
                'voter_id' => $voter_id,
                'operation' => 'select_panelists',
            ]);

            return [];
        }

        $service = ServiceLocator::resolveTrustReadService();
        return $service->getEligiblePanelistUserIds([$reporter_id, $voter_id], $needed);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDispute(object $d): array
    {
        $data = [
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

        // Hide reporter identity and vote tallies from panelists until
        // ALL panel votes are in. This enforces independent deliberation —
        // even panelists who have already voted must not see running totals
        // to prevent them from sharing tally information with allies.
        $userId     = get_current_user_id();
        $isPanelist = property_exists($d, 'my_decision');
        $isReporter = (int) $d->reporter_id === $userId;
        $isAdmin    = current_user_can('manage_options');
        $totalVoted = (int) $d->panel_accepts + (int) $d->panel_rejects;
        $panelSize  = (int) $d->panel_size;
        $votingComplete = ($totalVoted >= $panelSize) || in_array($d->status, ['accepted', 'rejected'], true);

        if ($isPanelist && !$isReporter && !$isAdmin && !$votingComplete) {
            $data['reporter_name'] = null;
            $data['accepts']       = null;
            $data['rejects']       = null;
            // Mask final outcome to prevent tally inference from status changes.
            if (in_array($data['status'], ['accepted', 'rejected'], true)) {
                $data['status'] = 'closed';
            }
        }

        return $data;
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

        if ( DisputeRepository::countRecentReportsByReporter($reporter_id) >= 5 ) {
            return $this->error('report_limit_reached', 'You have reached the daily report limit. Please try again later.', 429);
        }

        if ( DisputeRepository::hasActiveReport($reporter_id, $reported_id) ) {
            return $this->error('already_reported', 'You have already submitted an active report against this user.', 409);
        }

        // Protect targets from coordinated report campaigns.
        if ( DisputeRepository::countActiveReportsAgainst($reported_id) >= 10 ) {
            return $this->error('target_report_limit', 'This user already has reports pending review.', 429);
        }

        $report_id = DisputeRepository::createReport($reported_id, $reporter_id, $reason_key, $reason_detail);
        if ( ! $report_id ) {
            return $this->error('db_error', 'Failed to submit report.', 500);
        }

        // Emails queued async — never block the REST response with SMTP.
        DisputeNotificationService::enqueueAsync('bcc_disputes_email_reported_user', [$report_id, $reported_id]);
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
        $key     = "bcc_throttle_{$action}_{$user_id}";
        $allowed = \BCC\Core\Security\Throttle::allow($action, 1, $cooldown_seconds, $key);

        if (!$allowed) {
            return $this->error(
                'rate_limited',
                sprintf('Please wait %d seconds before trying again.', $cooldown_seconds),
                429
            );
        }
        return null;
    }

    // ── Health endpoint ────────────────────────────────────────────────────────

    /**
     * GET /bcc/v1/disputes/health — operational health snapshot (admin only).
     *
     * Reports cron last-run times, queue depths, and service availability
     * so admins can detect stale crons, backlogged queues, or missing
     * trust-engine bindings without SSH access.
     */
    public function health(WP_REST_Request $req): WP_REST_Response
    {
        $now = time();

        // Last auto-resolve run (tracked by DisputeScheduler).
        $lastAutoResolve = (int) get_option('bcc_disputes_auto_resolve_last_run', 0);

        // Count disputes in each status for queue depth.
        $statusCounts = DisputeRepository::getDisputeStatusCounts();

        // Orphaned disputes (committed but adjudication pending/failed).
        $orphanCount = DisputeRepository::countOrphanedDisputes();

        // Trust-engine availability.
        $hasTrustRead = ServiceLocator::hasRealService(TrustReadServiceInterface::class);
        $hasAdjudicator = ServiceLocator::hasRealService(\BCC\Core\Contracts\DisputeAdjudicationInterface::class);

        // Action Scheduler backlog (if available).
        // Use bounded per_page to avoid loading thousands of rows just to count.
        $asBacklog = null;
        if (function_exists('as_get_scheduled_actions')) {
            $maxCheck = 501;
            $pending = as_get_scheduled_actions([
                'group'    => 'bcc-disputes',
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => $maxCheck,
            ], 'ARRAY_A');
            $count = is_array($pending) ? count($pending) : 0;
            $asBacklog = $count >= $maxCheck ? '500+' : $count;
        }

        // WP-Cron status.
        $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $nextAutoResolve = wp_next_scheduled('bcc_disputes_auto_resolve');

        return rest_ensure_response([
            'status'    => 'ok',
            'timestamp' => gmdate('c'),
            'cron'      => [
                'wp_cron_disabled'         => $cronDisabled,
                'auto_resolve_last_run'    => $lastAutoResolve > 0 ? gmdate('c', $lastAutoResolve) : null,
                'auto_resolve_age_seconds' => $lastAutoResolve > 0 ? $now - $lastAutoResolve : null,
                'next_auto_resolve'        => $nextAutoResolve ? gmdate('c', $nextAutoResolve) : null,
                'action_scheduler_backlog' => $asBacklog,
            ],
            'queues'    => [
                'status_counts' => $statusCounts,
                'orphaned'      => $orphanCount,
            ],
            'services'  => [
                'trust_read_service'    => $hasTrustRead,
                'dispute_adjudicator'   => $hasAdjudicator,
            ],
        ]);
    }

    private function error(string $code, string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['code' => $code, 'message' => $message], $status);
    }

}
