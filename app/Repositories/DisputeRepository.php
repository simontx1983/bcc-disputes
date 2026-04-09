<?php

namespace BCC\Disputes\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeRepository
{
    /** Cache group for all dispute-related keys. */
    private const CACHE_GROUP = 'bcc_disputes';

    /** TTL for data that changes frequently (counts, active queues). */
    private const TTL_HOT  = 60;

    /** TTL for data that changes less often (individual dispute lookups). */
    private const TTL_WARM = 300;

    public static function disputes_table(): string
    {
        return DB::table('disputes');
    }

    public static function panel_table(): string
    {
        return DB::table('dispute_panel');
    }

    public static function user_reports_table(): string
    {
        return DB::table('user_reports');
    }

    // ── Cache helpers ────────────────────────────────────────────────────────

    /**
     * Get the current generation counter for a cache namespace.
     *
     * Generation counters solve the wildcard-deletion problem: instead of
     * deleting panel_queue:{userId}:* (impossible with wp_cache), we
     * increment the generation and all old keys become unreachable,
     * expiring naturally via TTL.
     */
    private static function getGeneration(string $genKey): int
    {
        $gen = wp_cache_get($genKey, self::CACHE_GROUP);
        if ($gen === false) {
            $gen = 1;
            wp_cache_set($genKey, $gen, self::CACHE_GROUP, 0);
        }
        return (int) $gen;
    }

    /**
     * Atomically increment a generation counter, invalidating all keys that embed it.
     *
     * Uses wp_cache_incr() which maps to Redis INCR — a single atomic operation
     * when a persistent object cache is available. The fallback (no object cache)
     * uses set-if-missing which is not fully atomic but acceptable for cache
     * invalidation (worst case: one extra DB query).
     */
    private static function bumpGeneration(string $genKey): void
    {
        $result = wp_cache_incr($genKey, 1, self::CACHE_GROUP);
        if ($result === false) {
            // incr failed — key may not exist. Verify before overwriting.
            $current = wp_cache_get($genKey, self::CACHE_GROUP);
            if ($current === false) {
                // Key truly doesn't exist — set to 2 (first bump from implicit gen=1).
                wp_cache_set($genKey, 2, self::CACHE_GROUP, 0);
            } else {
                // Key exists but incr failed (transient backend issue) — force set incremented value.
                wp_cache_set($genKey, (int) $current + 1, self::CACHE_GROUP, 0);
            }
        }
    }

    // ── Query methods ────────────────────────────────────────────────────────

    /**
     * Count disputes filed for a page within the last N days.
     *
     * Used to enforce per-page dispute limits (e.g., max 3 per 30 days)
     * to prevent brute-force dispute spam.
     */
    public static function countRecentDisputesForPage(int $pageId, int $days = 30): int
    {
        global $wpdb;
        $table = self::disputes_table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE page_id = %d
               AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $pageId, $days
        ));
    }

    /**
     * Check whether an active (pending/reviewing) dispute already exists for a vote.
     */
    public static function hasActiveDisputeForVote(int $voteId): bool
    {
        global $wpdb;
        $table = self::disputes_table();

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE vote_id = %d AND status IN ('pending','reviewing') LIMIT 1",
            $voteId
        ));

        return (bool) $existing;
    }

    /**
     * Atomically create a dispute row and its panel assignments.
     *
     * @param array  $disputeData  Dispute column values.
     * @param int[]  $panelistIds  User IDs to assign as panelists.
     * @return array{id: ?int, failed_panelist: ?int, db_error: ?string}
     */
    public static function createDisputeWithPanel(array $disputeData, array $panelistIds): array
    {
        global $wpdb;
        $disputeTable = self::disputes_table();
        $panelTable   = self::panel_table();

        $wpdb->query('START TRANSACTION');

        // Atomic dispute limit check: count recent disputes FOR UPDATE to
        // prevent race where two concurrent requests both read count=2.
        $pageId = (int) $disputeData['page_id'];
        $recentCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$disputeTable}
             WHERE page_id = %d
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             FOR UPDATE",
            $pageId
        ));
        if ($recentCount >= 3) {
            $wpdb->query('ROLLBACK');
            return ['id' => null, 'failed_panelist' => null, 'db_error' => 'dispute_limit_reached'];
        }

        $wpdb->insert($disputeTable, [
            'vote_id'      => $disputeData['vote_id'],
            'page_id'      => $disputeData['page_id'],
            'reporter_id'  => $disputeData['reporter_id'],
            'voter_id'     => $disputeData['voter_id'],
            'reason'       => $disputeData['reason'],
            'evidence_url' => $disputeData['evidence_url'],
            'status'       => $disputeData['status'],
            'panel_size'   => $disputeData['panel_size'],
        ], ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d']);

        $dispute_id = $wpdb->insert_id;

        if (!$dispute_id) {
            $wpdb->query('ROLLBACK');
            return ['id' => null, 'failed_panelist' => null, 'db_error' => $wpdb->last_error];
        }

        foreach ($panelistIds as $uid) {
            $wpdb->insert($panelTable, [
                'dispute_id'       => $dispute_id,
                'panelist_user_id' => $uid,
            ], ['%d', '%d']);

            if ($wpdb->last_error) {
                $error = $wpdb->last_error;
                $wpdb->query('ROLLBACK');
                return ['id' => null, 'failed_panelist' => $uid, 'db_error' => $error];
            }
        }

        $wpdb->query('COMMIT');

        // Invalidate: each panelist's queue cache, reporter's dispute list.
        foreach ($panelistIds as $uid) {
            self::bumpGeneration("panel_q_gen:{$uid}");
        }
        self::bumpGeneration("reporter_gen:{$disputeData['reporter_id']}");

        return ['id' => $dispute_id, 'failed_panelist' => null, 'db_error' => null];
    }

    /**
     * Return vote IDs (from a given set) that have an active or accepted dispute.
     *
     * @param int[] $voteIds
     * @return array<int, true>  vote_id => true for disputed votes.
     */
    public static function getDisputedVoteIds(array $voteIds): array
    {
        if (empty($voteIds)) {
            return [];
        }

        $sorted = $voteIds;
        sort($sorted);
        $cacheKey = 'disputed_votes:' . md5(json_encode($sorted));
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = self::disputes_table();

        $placeholders = implode(',', array_fill(0, count($voteIds), '%d'));
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT vote_id
             FROM {$table}
             WHERE vote_id IN ({$placeholders})
               AND status IN ('pending','reviewing','accepted')",
            ...$voteIds
        ));

        $result = array_fill_keys(array_map('intval', $rows), true);
        wp_cache_set($cacheKey, $result, self::CACHE_GROUP, self::TTL_HOT);
        return $result;
    }

    /**
     * Count disputes filed by a user.
     */
    public static function countByReporter(int $userId): int
    {
        $gen      = self::getGeneration("reporter_gen:{$userId}");
        $cacheKey = "reporter_count:{$userId}:{$gen}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $table = self::disputes_table();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE reporter_id = %d",
            $userId
        ));

        wp_cache_set($cacheKey, $count, self::CACHE_GROUP, self::TTL_HOT);
        return $count;
    }

    /**
     * Paginated disputes filed by a user, with post/user display names.
     *
     * @return object[]
     */
    public static function getByReporterPaginated(int $userId, int $limit, int $offset): array
    {
        $gen      = self::getGeneration("reporter_gen:{$userId}");
        $cacheKey = "reporter:{$userId}:{$gen}:{$limit}:{$offset}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = self::disputes_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.vote_id, d.page_id, d.reason, d.evidence_url, d.status,
                    d.panel_accepts, d.panel_rejects, d.panel_size,
                    d.voter_id, d.reporter_id, d.created_at, d.resolved_at,
                    p.post_title AS page_title,
                    u.display_name AS voter_name,
                    r.display_name AS reporter_name
             FROM {$table} d
             LEFT JOIN {$wpdb->posts} p ON d.page_id = p.ID
             LEFT JOIN {$wpdb->users} u ON d.voter_id = u.ID
             LEFT JOIN {$wpdb->users} r ON d.reporter_id = r.ID
             WHERE d.reporter_id = %d
             ORDER BY d.created_at DESC
             LIMIT %d OFFSET %d",
            $userId, $limit, $offset
        ));

        $result = $rows ?: [];
        wp_cache_set($cacheKey, $result, self::CACHE_GROUP, self::TTL_HOT);
        return $result;
    }

    /**
     * Count active disputes assigned to a panelist.
     */
    public static function countPanelQueueForUser(int $userId): int
    {
        $gen      = self::getGeneration("panel_q_gen:{$userId}");
        $cacheKey = "panel_q_count:{$userId}:{$gen}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $disputeTable = self::disputes_table();
        $panelTable   = self::panel_table();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$panelTable} pan
             JOIN {$disputeTable} d ON d.id = pan.dispute_id
             WHERE pan.panelist_user_id = %d AND d.status IN ('pending','reviewing')",
            $userId
        ));

        wp_cache_set($cacheKey, $count, self::CACHE_GROUP, self::TTL_HOT);
        return $count;
    }

    /**
     * Paginated active disputes assigned to a panelist, with display names.
     *
     * @return object[]
     */
    public static function getPanelQueueForUser(int $userId, int $limit, int $offset): array
    {
        $gen      = self::getGeneration("panel_q_gen:{$userId}");
        $cacheKey = "panel_q:{$userId}:{$gen}:{$limit}:{$offset}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $disputeTable = self::disputes_table();
        $panelTable   = self::panel_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.vote_id, d.page_id, d.reason, d.evidence_url, d.status,
                    d.panel_accepts, d.panel_rejects, d.panel_size,
                    d.voter_id, d.reporter_id, d.created_at, d.resolved_at,
                    pan.decision AS my_decision,
                    p.post_title AS page_title,
                    u.display_name AS voter_name,
                    r.display_name AS reporter_name
             FROM {$panelTable} pan
             JOIN {$disputeTable} d ON d.id = pan.dispute_id
             LEFT JOIN {$wpdb->posts} p ON d.page_id = p.ID
             LEFT JOIN {$wpdb->users} u ON d.voter_id = u.ID
             LEFT JOIN {$wpdb->users} r ON d.reporter_id = r.ID
             WHERE pan.panelist_user_id = %d
               AND d.status IN ('pending','reviewing')
             ORDER BY d.created_at ASC
             LIMIT %d OFFSET %d",
            $userId, $limit, $offset
        ));

        $result = $rows ?: [];
        wp_cache_set($cacheKey, $result, self::CACHE_GROUP, self::TTL_HOT);
        return $result;
    }

    /**
     * Get a panelist's assignment for a dispute.
     *
     * @return object|null  Object with id, decision columns.
     */
    public static function getPanelAssignment(int $disputeId, int $userId): ?object
    {
        global $wpdb;
        $table = self::panel_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, decision FROM {$table} WHERE dispute_id = %d AND panelist_user_id = %d LIMIT 1",
            $disputeId, $userId
        ));

        return $row ?: null;
    }

    /**
     * Get a dispute by ID (selected columns for controller use).
     *
     * @return object|null  Object with id, status, vote_id, page_id, voter_id, reporter_id,
     *                      panel_accepts, panel_rejects, panel_size.
     */
    public static function getDisputeById(int $disputeId): ?object
    {
        $cacheKey = "dispute:{$disputeId}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached === 'NULL' ? null : $cached;
        }

        global $wpdb;
        $table = self::disputes_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, vote_id, page_id, voter_id, reporter_id,
                    panel_accepts, panel_rejects, panel_size
             FROM {$table} WHERE id = %d LIMIT 1",
            $disputeId
        ));

        $result = $row ?: null;
        wp_cache_set($cacheKey, $result ?? 'NULL', self::CACHE_GROUP, self::TTL_WARM);
        return $result;
    }

    /**
     * Atomically cast a panel vote: lock dispute, record decision, update tally, re-read.
     *
     * This method encapsulates the entire cast_vote transaction:
     * 1. SELECT FOR UPDATE on dispute row (serialises concurrent voters)
     * 2. UPDATE panel row WHERE decision IS NULL (prevents double-voting)
     * 3. Increment tally column
     * 4. Re-read dispute inside the lock
     * 5. COMMIT
     *
     * @return array{status: string, code: string, message: string, dispute: ?object, accepts: int, rejects: int}
     */
    public static function castPanelVoteAtomic(int $disputeId, int $userId, string $decision, string $note): array
    {
        global $wpdb;
        $disputeTable = self::disputes_table();
        $panelTable   = self::panel_table();

        $wpdb->query('START TRANSACTION');

        // Lock the dispute row — concurrent voters block here.
        $dispute = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, vote_id, page_id, voter_id, reporter_id,
                    panel_accepts, panel_rejects, panel_size
             FROM {$disputeTable} WHERE id = %d FOR UPDATE",
            $disputeId
        ));

        if (!$dispute || !in_array($dispute->status, ['pending', 'reviewing'], true)) {
            $wpdb->query('ROLLBACK');
            return ['status' => 'error', 'code' => 'dispute_closed', 'message' => 'This dispute is no longer open.', 'http' => 410, 'dispute' => null, 'accepts' => 0, 'rejects' => 0];
        }

        // Atomic vote recording: UPDATE … WHERE decision IS NULL prevents double-voting.
        $voted = $wpdb->query($wpdb->prepare(
            "UPDATE {$panelTable} SET decision = %s, note = %s, voted_at = %s
             WHERE dispute_id = %d AND panelist_user_id = %d AND decision IS NULL",
            $decision, $note, current_time('mysql'), $disputeId, $userId
        ));

        if ($voted === false) {
            $wpdb->query('ROLLBACK');
            return ['status' => 'error', 'code' => 'db_error', 'message' => 'Failed to record vote.', 'http' => 500, 'step' => 'panel_vote_update', 'dispute' => null, 'accepts' => 0, 'rejects' => 0, 'db_error' => $wpdb->last_error];
        }
        if ($voted === 0) {
            $wpdb->query('ROLLBACK');
            return ['status' => 'error', 'code' => 'already_voted', 'message' => 'You have already voted on this dispute.', 'http' => 409, 'dispute' => null, 'accepts' => 0, 'rejects' => 0];
        }

        // Atomic tally update
        $col = $decision === 'accept' ? 'panel_accepts' : 'panel_rejects';
        $tally_ok = $wpdb->query($wpdb->prepare(
            "UPDATE {$disputeTable} SET {$col} = {$col} + 1 WHERE id = %d",
            $disputeId
        ));

        if ($tally_ok === false) {
            $wpdb->query('ROLLBACK');
            return ['status' => 'error', 'code' => 'db_error', 'message' => 'Failed to update tally.', 'http' => 500, 'step' => 'tally_increment', 'dispute' => null, 'accepts' => 0, 'rejects' => 0, 'db_error' => $wpdb->last_error];
        }

        // Re-read tallies (still inside transaction / row lock)
        $dispute = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, panel_accepts, panel_rejects, panel_size,
                    vote_id, page_id, voter_id, reporter_id
             FROM {$disputeTable} WHERE id = %d",
            $disputeId
        ));

        $wpdb->query('COMMIT');

        // Invalidate all caches affected by this vote (tally changed,
        // queue state changed for ALL panelists, reporter sees updated tally).
        self::invalidateDispute($disputeId);

        return [
            'status'  => 'success',
            'code'    => 'ok',
            'message' => 'Vote recorded.',
            'http'    => 200,
            'dispute' => $dispute,
            'accepts' => (int) $dispute->panel_accepts,
            'rejects' => (int) $dispute->panel_rejects,
        ];
    }

    /**
     * Insert a user report row.
     *
     * @return int|null  The new report ID, or null on failure.
     */
    public static function createReport(int $reportedId, int $reporterId, string $reasonKey, string $reasonDetail): ?int
    {
        global $wpdb;
        $table = self::user_reports_table();

        $wpdb->insert($table, [
            'reported_id'   => $reportedId,
            'reporter_id'   => $reporterId,
            'reason_key'    => $reasonKey,
            'reason_detail' => $reasonDetail,
            'status'        => 'open',
        ], ['%d', '%d', '%s', '%s', '%s']);

        $id = (int) $wpdb->insert_id;
        return $id ?: null;
    }

    // ── Admin query methods ──────────────────────────────────────────────────

    /**
     * Get a dispute with joined page title and user display names for admin detail view.
     */
    public static function getDisputeDetailForAdmin(int $disputeId): ?object
    {
        global $wpdb;
        $table = self::disputes_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT d.id, d.vote_id, d.page_id, d.reporter_id, d.voter_id,
                    d.reason, d.evidence_url, d.status,
                    d.panel_accepts, d.panel_rejects, d.panel_size,
                    d.created_at, d.resolved_at,
                    p.post_title   AS page_title,
                    reporter.display_name AS reporter_name,
                    voter.display_name    AS voter_name
             FROM {$table} d
             LEFT JOIN {$wpdb->posts} p         ON d.page_id     = p.ID
             LEFT JOIN {$wpdb->users} reporter  ON d.reporter_id = reporter.ID
             LEFT JOIN {$wpdb->users} voter     ON d.voter_id    = voter.ID
             WHERE d.id = %d
             LIMIT 1",
            $disputeId
        ));

        return $row ?: null;
    }

    /**
     * Get all panelists for a dispute with display names.
     *
     * @return object[]
     */
    public static function getPanelistsForDispute(int $disputeId): array
    {
        global $wpdb;
        $table = self::panel_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pan.id, pan.dispute_id, pan.panelist_user_id,
                    pan.decision, pan.note, pan.assigned_at, pan.voted_at,
                    u.display_name
             FROM {$table} pan
             LEFT JOIN {$wpdb->users} u ON pan.panelist_user_id = u.ID
             WHERE pan.dispute_id = %d
             ORDER BY pan.assigned_at ASC
             LIMIT %d",
            $disputeId, BCC_DISPUTES_PANEL_SIZE
        ));

        return $rows ?: [];
    }

    /**
     * Get dispute counts grouped by status.
     *
     * @return array<string, int>
     */
    public static function getDisputeStatusCounts(): array
    {
        $cacheKey = 'dispute_status_counts';
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = self::disputes_table();

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status"
        );

        $counts = [];
        foreach ($rows as $r) {
            $counts[$r->status] = (int) $r->cnt;
        }

        wp_cache_set($cacheKey, $counts, self::CACHE_GROUP, self::TTL_HOT);
        return $counts;
    }

    /**
     * Count disputes for admin list, optionally filtered by status.
     */
    public static function countDisputesForAdminList(?string $status): int
    {
        global $wpdb;
        $table = self::disputes_table();

        if ($status) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                $status
            ));
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Paginated dispute list for admin, with joined page title and user names.
     *
     * @return object[]
     */
    public static function getDisputesForAdminList(?string $status, string $orderBy, string $order, int $limit, int $offset): array
    {
        global $wpdb;
        $table = self::disputes_table();

        $allowed = ['id', 'status', 'created_at'];
        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'id';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $where  = '1=1';
        $params = [];

        if ($status) {
            $where   .= ' AND d.status = %s';
            $params[] = $status;
        }

        $sql = "SELECT d.id, d.vote_id, d.page_id, d.reporter_id, d.voter_id,
                       d.reason, d.status,
                       d.panel_accepts, d.panel_rejects, d.panel_size,
                       d.created_at, d.resolved_at,
                       p.post_title   AS page_title,
                       reporter.display_name AS reporter_name,
                       voter.display_name    AS voter_name
                FROM {$table} d
                LEFT JOIN {$wpdb->posts} p         ON d.page_id     = p.ID
                LEFT JOIN {$wpdb->users} reporter  ON d.reporter_id = reporter.ID
                LEFT JOIN {$wpdb->users} voter     ON d.voter_id    = voter.ID
                WHERE {$where}
                ORDER BY d.{$orderBy} {$order}
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];
    }

    /**
     * Get report counts grouped by status.
     *
     * @return array<string, int>
     */
    public static function getReportStatusCounts(): array
    {
        $cacheKey = 'report_status_counts';
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = self::user_reports_table();

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status"
        );

        $counts = [];
        foreach ($rows as $r) {
            $counts[$r->status] = (int) $r->cnt;
        }

        wp_cache_set($cacheKey, $counts, self::CACHE_GROUP, self::TTL_HOT);
        return $counts;
    }

    /**
     * Count reports for admin list, optionally filtered by status.
     */
    public static function countReportsForAdminList(?string $status): int
    {
        global $wpdb;
        $table = self::user_reports_table();

        if ($status) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                $status
            ));
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Paginated report list for admin, with joined user display names.
     *
     * @return object[]
     */
    public static function getReportsForAdminList(?string $status, string $orderBy, string $order, int $limit, int $offset): array
    {
        global $wpdb;
        $table = self::user_reports_table();

        $allowed = ['id', 'status', 'created_at'];
        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'id';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $where  = '1=1';
        $params = [];

        if ($status) {
            $where   .= ' AND r.status = %s';
            $params[] = $status;
        }

        $sql = "SELECT r.id, r.reported_id, r.reporter_id, r.reason_key, r.reason_detail,
                       r.status, r.created_at, r.reviewed_at,
                       reported.display_name AS reported_name,
                       reporter.display_name AS reporter_name
                FROM {$table} r
                LEFT JOIN {$wpdb->users} reported ON r.reported_id = reported.ID
                LEFT JOIN {$wpdb->users} reporter ON r.reporter_id = reporter.ID
                WHERE {$where}
                ORDER BY r.{$orderBy} {$order}
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];
    }

    /**
     * Check whether a report exists.
     */
    public static function reportExists(int $reportId): bool
    {
        global $wpdb;
        $table = self::user_reports_table();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d LIMIT 1",
            $reportId
        ));
    }

    /**
     * Update a report's status and reviewed_at timestamp.
     *
     * @return bool True on success, false on DB error.
     */
    public static function updateReportStatus(int $reportId, string $status): bool
    {
        global $wpdb;
        $table = self::user_reports_table();

        $result = $wpdb->update(
            $table,
            ['status' => $status, 'reviewed_at' => current_time('mysql')],
            ['id' => $reportId],
            ['%s', '%s'],
            ['%d']
        );

        if ($result !== false) {
            wp_cache_delete('report_status_counts', self::CACHE_GROUP);
        }

        return $result !== false;
    }

    // ── Scheduler query methods ─────────────────────────────────────────────

    /**
     * Get expired disputes past the cutoff date, limited batch.
     *
     * @return object[]  Each with id, panel_accepts, panel_rejects, vote_id, page_id, voter_id, reporter_id.
     */
    public static function getExpiredDisputes(string $cutoff, int $limit = 50): array
    {
        global $wpdb;
        $table = self::disputes_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, panel_accepts, panel_rejects, vote_id, page_id, voter_id, reporter_id
             FROM {$table}
             WHERE status IN ('pending','reviewing')
               AND created_at <= %s
             ORDER BY created_at ASC
             LIMIT %d",
            $cutoff, $limit
        ));

        return $rows ?: [];
    }

    // ── Transaction methods (for ResolveDisputeService) ─────────────────────

    /**
     * Begin an atomic dispute resolution: START TRANSACTION, UPDATE status.
     *
     * The transaction is left OPEN on success — caller must call
     * commitTransaction() or rollbackTransaction().
     *
     * @return array{success: bool, affected_rows: int, db_error: ?string, race: bool}
     */
    public static function beginResolveTransaction(int $disputeId, string $outcome): array
    {
        global $wpdb;
        $table = self::disputes_table();

        $wpdb->query('START TRANSACTION');

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, resolved_at = %s
             WHERE id = %d AND status IN ('pending','reviewing')",
            $outcome,
            current_time('mysql'),
            $disputeId
        ));

        if ($result === false) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'affected_rows' => 0, 'db_error' => $wpdb->last_error, 'race' => false];
        }

        if ($result === 0) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'affected_rows' => 0, 'db_error' => null, 'race' => true];
        }

        // Transaction still OPEN — caller must commit or rollback.
        return ['success' => true, 'affected_rows' => $result, 'db_error' => null, 'race' => false];
    }

    /**
     * Commit the current open transaction.
     */
    public static function commitTransaction(): void
    {
        global $wpdb;
        $wpdb->query('COMMIT');
    }

    /**
     * Rollback the current open transaction.
     */
    public static function rollbackTransaction(): void
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
    }

    /**
     * Re-open a resolved dispute (on adjudication failure).
     *
     * Only acts on disputes currently in a resolved state ('accepted'/'rejected')
     * to prevent blindly overwriting a concurrent admin re-resolution.
     * Uses a raw query to set resolved_at to SQL NULL (wpdb::update with %s
     * casts PHP null to empty string, not SQL NULL).
     */
    public static function reopenDispute(int $disputeId): bool
    {
        global $wpdb;
        $table = self::disputes_table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'reviewing', resolved_at = NULL
             WHERE id = %d AND status IN ('accepted','rejected')",
            $disputeId
        ));

        return $result !== false && $result > 0;
    }

    // ── Cache invalidation ─────────────────────────────────────────────────

    /**
     * Invalidate all caches affected by a dispute status/tally change.
     *
     * Must be called by any code that writes to the disputes or panel tables
     * outside of this repository (e.g. ResolveDisputeService).
     *
     * Invalidates:
     * - dispute:{disputeId} (direct delete)
     * - panel_q_gen:{panelistId} for ALL panelists on the dispute (generation bump)
     * - reporter_gen:{reporterId} (generation bump)
     */
    public static function invalidateDispute(int $disputeId): void
    {
        wp_cache_delete("dispute:{$disputeId}", self::CACHE_GROUP);
        wp_cache_delete('dispute_status_counts', self::CACHE_GROUP);

        global $wpdb;
        $disputeTable = self::disputes_table();
        $panelTable   = self::panel_table();

        // Get reporter_id and all panelist IDs for this dispute.
        $dispute = $wpdb->get_row($wpdb->prepare(
            "SELECT reporter_id FROM {$disputeTable} WHERE id = %d LIMIT 1",
            $disputeId
        ));

        if ($dispute) {
            self::bumpGeneration("reporter_gen:{$dispute->reporter_id}");
        }

        $panelistIds = $wpdb->get_col($wpdb->prepare(
            "SELECT panelist_user_id FROM {$panelTable} WHERE dispute_id = %d LIMIT %d",
            $disputeId, BCC_DISPUTES_PANEL_SIZE
        ));

        foreach ($panelistIds as $uid) {
            self::bumpGeneration("panel_q_gen:{$uid}");
        }
    }

    // ── Schema installation ──────────────────────────────────────────────────

    public static function install(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $disputes = self::disputes_table();
        $panel    = self::panel_table();

        $sql = "
        CREATE TABLE {$disputes} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vote_id         BIGINT UNSIGNED NOT NULL,
            page_id         BIGINT UNSIGNED NOT NULL,
            reporter_id     BIGINT UNSIGNED NOT NULL,
            voter_id        BIGINT UNSIGNED NOT NULL,
            reason          VARCHAR(1000)   NOT NULL DEFAULT '',
            evidence_url    VARCHAR(2083)            DEFAULT NULL,
            status          VARCHAR(20)     NOT NULL DEFAULT 'reviewing',
            panel_accepts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
            panel_rejects   TINYINT UNSIGNED NOT NULL DEFAULT 0,
            panel_size      TINYINT UNSIGNED NOT NULL DEFAULT 5,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at     DATETIME                 DEFAULT NULL,
            PRIMARY KEY (id),
            INDEX idx_page   (page_id),
            INDEX idx_vote   (vote_id),
            INDEX idx_status (status),
            INDEX idx_reporter (reporter_id),
            INDEX idx_status_created (status, created_at)
        ) {$charset};

        CREATE TABLE {$panel} (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dispute_id       BIGINT UNSIGNED NOT NULL,
            panelist_user_id BIGINT UNSIGNED NOT NULL,
            decision         VARCHAR(20)              DEFAULT NULL,
            note             VARCHAR(500)             DEFAULT NULL,
            assigned_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            voted_at         DATETIME                 DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_panelist_dispute (dispute_id, panelist_user_id),
            INDEX idx_dispute   (dispute_id),
            INDEX idx_panelist  (panelist_user_id),
            INDEX idx_undecided (decision)
        ) {$charset};
        ";

        $reports = self::user_reports_table();

        $sql .= "
        CREATE TABLE {$reports} (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reported_id    BIGINT UNSIGNED NOT NULL,
            reporter_id    BIGINT UNSIGNED NOT NULL,
            reason_key     VARCHAR(100)    NOT NULL DEFAULT '',
            reason_detail  VARCHAR(1000)   NOT NULL DEFAULT '',
            status         VARCHAR(20)     NOT NULL DEFAULT 'open',
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at    DATETIME                 DEFAULT NULL,
            PRIMARY KEY (id),
            INDEX idx_reported (reported_id),
            INDEX idx_reporter (reporter_id),
            INDEX idx_status   (status)
        ) {$charset};
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('bcc_disputes_db_version', BCC_DISPUTES_VERSION);
    }
}
