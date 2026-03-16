<?php

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Disputes_API
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
        $vote = $this->get_vote($vote_id);
        if (!$vote) {
            return $this->error('vote_not_found', 'Vote not found.', 404);
        }

        $page_id  = (int) $vote->page_id;
        $voter_id = (int) $vote->voter_user_id;

        // Only page owner can dispute
        if (!$this->is_page_owner($page_id, $current_user_id)) {
            return $this->error('not_page_owner', 'Only the page owner can dispute votes.', 403);
        }

        // Can't dispute your own vote (shouldn't happen but guard it)
        if ($voter_id === $current_user_id) {
            return $this->error('cannot_self_dispute', 'You cannot dispute your own vote.', 400);
        }

        $dt = BCC_Disputes_DB::disputes_table();
        $pt = BCC_Disputes_DB::panel_table();

        // One active dispute per vote
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$dt} WHERE vote_id = %d AND status IN ('pending','reviewing') LIMIT 1",
            $vote_id
        ));
        if ($existing) {
            return $this->error('already_disputed', 'This vote already has an active dispute.', 409);
        }

        // Insert dispute
        $wpdb->insert($dt, [
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
        $panelists = $this->select_panelists($current_user_id, $voter_id);

        foreach ($panelists as $uid) {
            $wpdb->insert($pt, [
                'dispute_id'       => $dispute_id,
                'panelist_user_id' => $uid,
            ], ['%d', '%d']);

            if ($wpdb->last_error) {
                error_log('BCC Disputes DB error (panel insert): ' . $wpdb->last_error);
            }

            $this->notify_panelist($uid, $dispute_id, $page_id);
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

        if (!$this->is_page_owner($page_id, $current_user_id) && !current_user_can('manage_options')) {
            return $this->error('forbidden', 'Access denied.', 403);
        }

        $votes_table    = $this->trust_table('trust_votes');
        $disputes_table = BCC_Disputes_DB::disputes_table();

        // Replace correlated subquery with a LEFT JOIN + COUNT to avoid N+1 per row.
        $votes = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.voter_user_id, v.vote_type, v.weight, v.reason, v.created_at,
                    u.display_name,
                    COUNT(d.id) AS dispute_count
             FROM {$votes_table} v
             LEFT JOIN {$wpdb->users} u ON v.voter_user_id = u.ID
             LEFT JOIN {$disputes_table} d
                    ON d.vote_id = v.id AND d.status IN ('pending','reviewing','accepted')
             WHERE v.page_id = %d AND v.status = 1
             GROUP BY v.id, v.voter_user_id, v.vote_type, v.weight, v.reason, v.created_at, u.display_name
             ORDER BY v.created_at DESC",
            $page_id
        ));

        return rest_ensure_response(array_map(function ($v) {
            return [
                'id'             => (int) $v->id,
                'voter_name'     => $v->display_name ?? 'Unknown',
                'vote_type'      => (int) $v->vote_type > 0 ? 'upvote' : 'downvote',
                'weight'         => round((float) $v->weight, 2),
                'reason'         => $v->reason ?? '',
                'date'           => $v->created_at,
                'already_disputed' => (int) $v->dispute_count > 0,
            ];
        }, $votes));
    }

    // ── My disputes ───────────────────────────────────────────────────────────

    public function mine(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $uid = get_current_user_id();
        $dt  = BCC_Disputes_DB::disputes_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, p.post_title as page_title, u.display_name as voter_name
             FROM {$dt} d
             LEFT JOIN {$wpdb->posts} p ON d.page_id = p.ID
             LEFT JOIN {$wpdb->users} u ON d.voter_id = u.ID
             WHERE d.reporter_id = %d
             ORDER BY d.created_at DESC",
            $uid
        ));

        return rest_ensure_response(array_map([$this, 'format_dispute'], $rows));
    }

    // ── Panel queue ───────────────────────────────────────────────────────────

    public function panel_queue(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $uid = get_current_user_id();
        $dt  = BCC_Disputes_DB::disputes_table();
        $pt  = BCC_Disputes_DB::panel_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, pan.decision as my_decision, pan.voted_at as my_voted_at,
                    p.post_title as page_title, u.display_name as voter_name
             FROM {$pt} pan
             JOIN {$dt} d ON d.id = pan.dispute_id
             LEFT JOIN {$wpdb->posts} p ON d.page_id = p.ID
             LEFT JOIN {$wpdb->users} u ON d.voter_id = u.ID
             WHERE pan.panelist_user_id = %d
               AND d.status IN ('pending','reviewing')
             ORDER BY d.created_at ASC",
            $uid
        ));

        return rest_ensure_response(array_map([$this, 'format_dispute'], $rows));
    }

    // ── Cast panel vote ───────────────────────────────────────────────────────

    public function cast_vote(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;

        $dispute_id = (int) $req->get_param('id');
        $decision   = $req->get_param('decision'); // 'accept' | 'reject'
        $note       = $req->get_param('note') ?? '';
        $uid        = get_current_user_id();

        $dt = BCC_Disputes_DB::disputes_table();
        $pt = BCC_Disputes_DB::panel_table();

        // Confirm this user is assigned to this dispute
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$pt} WHERE dispute_id = %d AND panelist_user_id = %d LIMIT 1",
            $dispute_id, $uid
        ));
        if (!$assignment) {
            return $this->error('not_assigned', 'You are not assigned to this dispute.', 403);
        }
        if ($assignment->decision !== null) {
            return $this->error('already_voted', 'You have already voted on this dispute.', 409);
        }

        $dispute = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$dt} WHERE id = %d LIMIT 1",
            $dispute_id
        ));
        if (!$dispute || !in_array($dispute->status, ['pending', 'reviewing'], true)) {
            return $this->error('dispute_closed', 'This dispute is no longer open.', 410);
        }

        // Record vote
        $wpdb->update($pt,
            ['decision' => $decision, 'note' => $note, 'voted_at' => current_time('mysql')],
            ['dispute_id' => $dispute_id, 'panelist_user_id' => $uid],
            ['%s', '%s', '%s'], ['%d', '%d']
        );

        if ($wpdb->last_error) {
            error_log('BCC Disputes DB error (panel vote): ' . $wpdb->last_error);
        }

        // Atomic tally update — avoids race condition when two panelists vote simultaneously
        $col = $decision === 'accept' ? 'panel_accepts' : 'panel_rejects';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$dt} SET {$col} = {$col} + 1 WHERE id = %d",
            $dispute_id
        ));

        if ($wpdb->last_error) {
            error_log('BCC Disputes DB error (tally update): ' . $wpdb->last_error);
        }

        // Re-fetch for accurate majority check after atomic update
        $dispute = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$dt} WHERE id = %d LIMIT 1",
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

        $dt      = BCC_Disputes_DB::disputes_table();
        $dispute = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$dt} WHERE id = %d LIMIT 1", $dispute_id));

        if (!$dispute) {
            return $this->error('not_found', 'Dispute not found.', 404);
        }

        $this->resolve($dispute_id, (int) $dispute->vote_id, (int) $dispute->page_id, (int) $dispute->voter_id, (int) $dispute->reporter_id, $decision);

        return rest_ensure_response(['message' => 'Dispute resolved as ' . $decision . '.']);
    }

    // ── Resolution logic ──────────────────────────────────────────────────────

    public function resolve(int $dispute_id, int $vote_id, int $page_id, int $voter_id, int $reporter_id, string $outcome): void
    {
        global $wpdb;

        $dt = BCC_Disputes_DB::disputes_table();

        $wpdb->update($dt,
            ['status' => $outcome, 'resolved_at' => current_time('mysql')],
            ['id' => $dispute_id],
            ['%s', '%s'], ['%d']
        );

        if ($wpdb->last_error) {
            error_log('BCC Disputes DB error (resolve dispute): ' . $wpdb->last_error);
        }

        if ($outcome === 'accepted') {
            // Soft-delete the disputed vote
            $votes_table = $this->trust_table('trust_votes');

            $wpdb->update(
                $votes_table,
                ['status' => 0, 'updated_at' => current_time('mysql')],
                ['id' => $vote_id],
                ['%d', '%s'], ['%d']
            );

            if ($wpdb->last_error) {
                error_log('BCC Disputes DB error (soft-delete vote): ' . $wpdb->last_error);
            }

            // Schedule a score recalculation for this page
            wp_schedule_single_event(time() + 30, 'bcc_disputes_recalculate_score', [$page_id]);

            // Apply a weight penalty to the voter (increase their fraud_score slightly)
            $this->penalise_voter($voter_id);

            do_action('bcc_dispute_accepted', $dispute_id, $vote_id, $page_id, $voter_id);
        } else {
            do_action('bcc_dispute_rejected', $dispute_id, $vote_id, $page_id);
        }

        // Notify the reporter — reporter_id passed directly, no re-fetch needed
        $reporter = get_userdata($reporter_id);
        if ($reporter && $reporter->user_email) {
            $subject = $outcome === 'accepted'
                ? '[BCC] Your dispute was accepted — vote removed'
                : '[BCC] Your dispute was reviewed — vote stands';
            $body = $outcome === 'accepted'
                ? 'Good news! The community panel reviewed your dispute and agreed the vote was invalid. It has been removed from your trust score.'
                : 'The community panel reviewed your dispute and decided the vote was valid. The vote remains on your profile.';
            wp_mail($reporter->user_email, $subject, $body);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function get_vote(int $vote_id): ?object
    {
        global $wpdb;
        $table = $this->trust_table('trust_votes');

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND status = 1 LIMIT 1",
            $vote_id
        ));
    }

    private function is_page_owner(int $page_id, int $user_id): bool
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
    private function select_panelists(int $reporter_id, int $voter_id): array
    {
        global $wpdb;
        $scores = $this->trust_table('trust_page_scores');

        $needed = defined('BCC_DISPUTES_PANEL_SIZE') ? BCC_DISPUTES_PANEL_SIZE : 3;

        // SQL-level random sampling — avoids fetching all eligible users into PHP
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT page_owner_id as user_id
             FROM {$scores}
             WHERE reputation_tier IN ('gold','platinum')
               AND page_owner_id NOT IN (%d, %d)
               AND page_owner_id > 0
             ORDER BY RAND()
             LIMIT %d",
            $reporter_id,
            $voter_id,
            $needed
        ));

        if (empty($rows)) {
            return [];
        }

        return array_map(fn($r) => (int) $r->user_id, $rows);
    }

    private function notify_panelist(int $uid, int $dispute_id, int $page_id): void
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

    private function penalise_voter(int $voter_id): void
    {
        global $wpdb;

        $table = $this->trust_table('trust_user_info');

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET fraud_score = LEAST(100, fraud_score + 5)
             WHERE user_id = %d",
            $voter_id
        ));

        if ($wpdb->last_error) {
            error_log('BCC Disputes DB error (penalise voter): ' . $wpdb->last_error);
        }
    }

    private function format_dispute(object $d): array
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

        $rt = BCC_Disputes_DB::user_reports_table();

        $wpdb->insert( $rt, [
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

        $this->email_reported_user( $reported_user );
        $this->email_admin_report( $report_id, $reporter_id, $reported_user, $reason_key, $reason_detail );

        return rest_ensure_response([
            'message' => 'Your report has been submitted. Our team will review it shortly.',
        ]);
    }

    private function email_reported_user( WP_User $reported_user ): void
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

    private function email_admin_report( int $report_id, int $reporter_id, WP_User $reported_user, string $reason_key, string $reason_detail ): void
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

    private function trust_table(string $name): string
    {
        if (class_exists('BCC\\Core\\DB\\DB')) {
            return \BCC\Core\DB\DB::table($name);
        }
        $helpers = [
            'trust_votes'       => 'bcc_trust_votes_table',
            'trust_page_scores' => 'bcc_trust_scores_table',
            'trust_user_info'   => 'bcc_trust_user_info_table',
        ];
        if (isset($helpers[$name]) && function_exists($helpers[$name])) {
            return ($helpers[$name])();
        }
        global $wpdb;
        return $wpdb->prefix . 'bcc_' . $name;
    }

    private function error(string $code, string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['code' => $code, 'message' => $message], $status);
    }
}
