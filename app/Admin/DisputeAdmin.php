<?php

namespace BCC\Disputes\Admin;

use BCC\Disputes\Services\ResolveDisputeService;
use BCC\Disputes\Repositories\DisputeRepository;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeAdmin
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 20);
        add_action('admin_init', [self::class, 'handle_actions']);
        add_action('admin_init', [self::class, 'handle_report_actions']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'bcc-trust-dashboard',
            __('Disputes', 'bcc-disputes'),
            __('Disputes', 'bcc-disputes'),
            'manage_options',
            'bcc-disputes',
            [self::class, 'render_page']
        );

        add_submenu_page(
            'bcc-trust-dashboard',
            __('User Reports', 'bcc-disputes'),
            __('User Reports', 'bcc-disputes'),
            'manage_options',
            'bcc-reports',
            [self::class, 'render_reports_page']
        );
    }

    // ── Router ──────────────────────────────────────────────────────────────

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $dispute_id = isset($_GET['dispute_id']) ? absint($_GET['dispute_id']) : 0;

        if ($dispute_id) {
            self::render_detail($dispute_id);
        } else {
            self::render_list();
        }
    }

    // ── List view ───────────────────────────────────────────────────────────

    private static function render_list(): void
    {
        $table = new DisputeListTable();
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Disputes', 'bcc-disputes') . '</h1>';
        $table->views();
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="bcc-disputes" />';
        if (isset($_GET['dispute_status'])) {
            printf('<input type="hidden" name="dispute_status" value="%s" />', esc_attr(sanitize_key($_GET['dispute_status'])));
        }
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    // ── Detail view ─────────────────────────────────────────────────────────

    private static function render_detail(int $dispute_id): void
    {
        $dispute = DisputeRepository::getDisputeDetailForAdmin($dispute_id);

        if (!$dispute) {
            echo '<div class="wrap"><h1>' . esc_html__('Dispute Not Found', 'bcc-disputes') . '</h1></div>';
            return;
        }

        // Enrich with vote data from trust-engine via interface.
        // NullTrustReadService::getVotesByIds() returns [] — admin sees "no vote data".
        $vote = null;
        if ($dispute->vote_id) {
            $votes = \BCC\Core\ServiceLocator::resolveTrustReadService()->getVotesByIds([(int) $dispute->vote_id]);
            $vote  = $votes[(int) $dispute->vote_id] ?? null;
        }

        // Panel votes.
        $panelists = DisputeRepository::getPanelistsForDispute($dispute_id);

        $back_url = admin_url('admin.php?page=bcc-disputes');
        $is_open  = $dispute->status === 'reviewing';

        echo '<div class="wrap">';

        // Header
        printf(
            '<h1><a href="%s">← %s</a> &nbsp; %s #%d</h1>',
            esc_url($back_url),
            esc_html__('Disputes', 'bcc-disputes'),
            esc_html__('Dispute', 'bcc-disputes'),
            (int) $dispute->id
        );

        // Admin notices from actions.
        if (isset($_GET['resolved'])) {
            $outcome = sanitize_key($_GET['resolved']);
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf(__('Dispute resolved as %s.', 'bcc-disputes'), $outcome))
            );
        } elseif (isset($_GET['resolve_failed'])) {
            echo '<div class="notice notice-error is-dismissible"><p>'
               . esc_html__('Resolution failed. The trust engine may be unavailable. The dispute remains open.', 'bcc-disputes')
               . '</p></div>';
        }

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:16px;">';

        // ── Left column: Dispute info ───────────────────────────────────────

        echo '<div>';

        // Reporter claim card
        echo '<div class="card" style="max-width:none;">';
        echo '<h2>' . esc_html__('Reporter Claim', 'bcc-disputes') . '</h2>';
        echo '<table class="widefat striped" style="border:none;">';
        self::detail_row(__('Reporter', 'bcc-disputes'), esc_html($dispute->reporter_name ?: 'Unknown') . ' (#' . (int) $dispute->reporter_id . ')');
        self::detail_row(__('Reason', 'bcc-disputes'), esc_html($dispute->reason));
        if ($dispute->evidence_url) {
            self::detail_row(
                __('Evidence', 'bcc-disputes'),
                sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($dispute->evidence_url), esc_html($dispute->evidence_url))
            );
        }
        self::detail_row(__('Filed', 'bcc-disputes'), esc_html($dispute->created_at));
        echo '</table>';
        echo '</div>';

        // Vote details card
        echo '<div class="card" style="max-width:none;margin-top:16px;">';
        echo '<h2>' . esc_html__('Disputed Vote', 'bcc-disputes') . '</h2>';

        if (!$vote && $dispute->vote_id) {
            echo '<p class="description" style="color:#b71c1c;">'
               . esc_html__('Vote data unavailable — trust engine may be inactive.', 'bcc-disputes')
               . '</p>';
        }

        echo '<table class="widefat striped" style="border:none;">';
        self::detail_row(__('Vote ID', 'bcc-disputes'), '#' . (int) $dispute->vote_id);
        self::detail_row(__('Page', 'bcc-disputes'), esc_html($dispute->page_title ?: '(no title)') . ' (#' . (int) $dispute->page_id . ')');
        self::detail_row(__('Voter', 'bcc-disputes'), esc_html($dispute->voter_name ?: 'Unknown') . ' (#' . (int) $dispute->voter_id . ')');

        $vote_label = '—';
        if ($vote !== null) {
            $vote_label = $vote['vote_type'] > 0 ? '▲ Upvote' : '▼ Downvote';
        }
        self::detail_row(__('Vote Type', 'bcc-disputes'), esc_html($vote_label));
        self::detail_row(__('Weight', 'bcc-disputes'), $vote !== null ? round($vote['weight'], 2) : '—');
        self::detail_row(__('Vote Reason', 'bcc-disputes'), esc_html($vote ? ($vote['reason'] ?: '(none)') : '—'));
        self::detail_row(__('Vote Date', 'bcc-disputes'), esc_html($vote ? ($vote['created_at'] ?: '—') : '—'));
        echo '</table>';
        echo '</div>';

        echo '</div>'; // end left column

        // ── Right column: Panel + Metadata + Actions ────────────────────────

        echo '<div>';

        // Dispute metadata card
        echo '<div class="card" style="max-width:none;">';
        echo '<h2>' . esc_html__('Dispute Metadata', 'bcc-disputes') . '</h2>';
        echo '<table class="widefat striped" style="border:none;">';

        $status_colors = [
            'reviewing' => '#0288d1',
            'accepted'  => '#2e7d32',
            'rejected'  => '#c62828',
        ];
        $scolor = $status_colors[$dispute->status] ?? '#666';
        self::detail_row(
            __('Status', 'bcc-disputes'),
            sprintf('<strong style="color:%s;">%s</strong>', esc_attr($scolor), esc_html(ucfirst($dispute->status)))
        );
        self::detail_row(__('Panel Size', 'bcc-disputes'), (int) $dispute->panel_size);
        self::detail_row(__('Accepts', 'bcc-disputes'), (int) $dispute->panel_accepts);
        self::detail_row(__('Rejects', 'bcc-disputes'), (int) $dispute->panel_rejects);
        self::detail_row(__('Resolved', 'bcc-disputes'), esc_html($dispute->resolved_at ?: '—'));
        echo '</table>';
        echo '</div>';

        // Panel votes card
        echo '<div class="card" style="max-width:none;margin-top:16px;">';
        echo '<h2>' . esc_html__('Panel Votes', 'bcc-disputes') . '</h2>';

        if (empty($panelists)) {
            echo '<p>' . esc_html__('No panelists assigned.', 'bcc-disputes') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Panelist', 'bcc-disputes') . '</th>';
            echo '<th>' . esc_html__('Decision', 'bcc-disputes') . '</th>';
            echo '<th>' . esc_html__('Note', 'bcc-disputes') . '</th>';
            echo '<th>' . esc_html__('Voted At', 'bcc-disputes') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($panelists as $pan) {
                echo '<tr>';
                printf('<td>%s (#%d)</td>', esc_html($pan->display_name ?: 'Unknown'), (int) $pan->panelist_user_id);

                if ($pan->decision) {
                    $dcolor = $pan->decision === 'accept' ? '#2e7d32' : '#c62828';
                    printf('<td><strong style="color:%s;">%s</strong></td>', esc_attr($dcolor), esc_html(ucfirst($pan->decision)));
                } else {
                    echo '<td><em>' . esc_html__('Pending', 'bcc-disputes') . '</em></td>';
                }

                printf('<td>%s</td>', esc_html($pan->note ?: '—'));
                printf('<td>%s</td>', esc_html($pan->voted_at ?: '—'));
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
        echo '</div>';

        // Admin actions card
        if ($is_open) {
            echo '<div class="card" style="max-width:none;margin-top:16px;">';
            echo '<h2>' . esc_html__('Admin Actions', 'bcc-disputes') . '</h2>';
            echo '<p class="description">' . esc_html__('Force-resolve this dispute. This overrides the panel process.', 'bcc-disputes') . '</p>';

            echo '<div style="display:flex;gap:8px;margin-top:12px;">';

            // Accept form
            self::action_button($dispute_id, 'accepted', __('Approve Dispute', 'bcc-disputes'), 'button-primary');

            // Reject form
            self::action_button($dispute_id, 'rejected', __('Reject Dispute', 'bcc-disputes'), 'button-secondary');

            echo '</div>';
            echo '</div>';
        }

        echo '</div>'; // end right column
        echo '</div>'; // end grid
        echo '</div>'; // end wrap
    }

    // ── Handle admin POST actions ───────────────────────────────────────────

    public static function handle_actions(): void
    {
        if (!isset($_POST['bcc_dispute_action'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized.', 'bcc-disputes'));
        }

        check_admin_referer('bcc_dispute_admin_action');

        $dispute_id = absint($_POST['dispute_id'] ?? 0);
        $action     = sanitize_key($_POST['bcc_dispute_action']);

        if (!$dispute_id) {
            return;
        }

        $dispute = DisputeRepository::getDisputeById($dispute_id);

        if (!$dispute) {
            return;
        }

        // Prevent re-resolving already-resolved disputes
        if ($dispute->status !== 'reviewing') {
            return;
        }

        $success = false;

        if ($action === 'accepted' || $action === 'rejected') {
            $success = (new ResolveDisputeService())->handle($dispute_id, (int) $dispute->vote_id, (int) $dispute->page_id, (int) $dispute->voter_id, (int) $dispute->reporter_id, $action);
        }

        $query_args = ['page' => 'bcc-disputes', 'dispute_id' => $dispute_id];
        if ($success) {
            $query_args['resolved'] = $action;
        } else {
            $query_args['resolve_failed'] = 1;
        }

        wp_safe_redirect(add_query_arg($query_args, admin_url('admin.php')));
        exit;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function detail_row(string $label, $value): void
    {
        printf(
            '<tr><th style="width:120px;">%s</th><td>%s</td></tr>',
            esc_html($label),
            wp_kses_post((string) $value)
        );
    }

    private static function action_button(int $dispute_id, string $action, string $label, string $class): void
    {
        echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'' . esc_js(sprintf(__('Are you sure you want to %s?', 'bcc-disputes'), strtolower(wp_strip_all_tags($label)))) . '\');">';
        wp_nonce_field('bcc_dispute_admin_action');
        printf('<input type="hidden" name="dispute_id" value="%d" />', $dispute_id);
        printf('<input type="hidden" name="bcc_dispute_action" value="%s" />', esc_attr($action));
        printf('<button type="submit" class="button %s">%s</button>', esc_attr($class), esc_html($label));
        echo '</form>';
    }

    // ── User Reports page ───────────────────────────────────────────────────

    public static function render_reports_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $table = new ReportListTable();
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('User Reports', 'bcc-disputes') . '</h1>';

        if (isset($_GET['report_updated'])) {
            $new_status = sanitize_key($_GET['report_updated']);
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf(__('Report marked as %s.', 'bcc-disputes'), $new_status))
            );
        }

        $table->views();
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="bcc-reports" />';
        if (isset($_GET['report_status'])) {
            printf('<input type="hidden" name="report_status" value="%s" />', esc_attr(sanitize_key($_GET['report_status'])));
        }
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    public static function handle_report_actions(): void
    {
        // Handle GET-based actions (reviewed, dismissed)
        if (isset($_GET['report_action'], $_GET['page']) && $_GET['page'] === 'bcc-reports') {
            self::handle_report_status_change();
            return;
        }

        // Handle POST-based penalize action
        if (isset($_POST['report_action']) && $_POST['report_action'] === 'penalize') {
            self::handle_report_penalize();
            return;
        }
    }

    private static function handle_report_status_change(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized.', 'bcc-disputes'));
        }

        $report_id = absint($_GET['report_id'] ?? 0);
        $action    = sanitize_key($_GET['report_action']);

        if (!$report_id || !in_array($action, ['reviewed', 'dismissed'], true)) {
            return;
        }

        check_admin_referer('bcc_report_action_' . $report_id);

        if (!DisputeRepository::reportExists($report_id)) {
            return;
        }

        $update_ok = DisputeRepository::updateReportStatus($report_id, $action);

        if (!$update_ok) {
            \BCC\Core\Log\Logger::error('[Disputes] report_action_failed', [
                'report_id' => $report_id,
                'action'    => $action,
            ]);
            wp_die(__('Failed to update report.', 'bcc-disputes'));
        }

        wp_safe_redirect(add_query_arg(
            ['page' => 'bcc-reports', 'report_updated' => $action],
            admin_url('admin.php')
        ));
        exit;
    }

    private static function handle_report_penalize(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized.', 'bcc-disputes'));
        }

        $report_id      = absint($_POST['report_id'] ?? 0);
        $penalty_points = absint($_POST['penalty_points'] ?? 0);
        $penalty_reason = sanitize_textarea_field($_POST['penalty_reason'] ?? '');

        if (!$report_id || $penalty_points < 1 || $penalty_points > 20) {
            wp_die(__('Invalid penalty amount. Must be between 1 and 20.', 'bcc-disputes'));
        }

        check_admin_referer('bcc_report_penalize_' . $report_id);

        // Claim the report atomically FIRST — prevents double-penalize race.
        // updateReportStatus uses WHERE status = 'open', so only one request wins.
        $update_ok = DisputeRepository::updateReportStatus($report_id, 'penalized');

        if (!$update_ok) {
            wp_die(__('Report not found or already resolved.', 'bcc-disputes'));
        }

        // Status claimed — now safe to apply penalty (only runs once).
        $report = DisputeRepository::getReportById($report_id);
        $reported_user_id = (int) $report->reported_id;

        if (\BCC\Core\ServiceLocator::hasRealService(\BCC\Core\Contracts\TrustReadServiceInterface::class)) {
            $reputationRepo = \BCC\Trust\Plugin::instance()->reputationRepository();
            $reputationRepo->adjustScore($reported_user_id, -1 * abs($penalty_points), 'admin_report_penalty');
        }

        \BCC\Core\Log\Logger::audit('[Disputes] admin_report_penalty', [
            'report_id'  => $report_id,
            'user_id'    => $reported_user_id,
            'penalty'    => $penalty_points,
            'reason'     => $penalty_reason,
            'admin_id'   => get_current_user_id(),
        ]);

        wp_safe_redirect(add_query_arg(
            ['page' => 'bcc-reports', 'report_updated' => 'penalized'],
            admin_url('admin.php')
        ));
        exit;
    }
}
