<?php

namespace BCC\Disputes\Admin;

use BCC\Disputes\Repositories\DisputeRepository;
use WP_List_Table;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ReportListTable extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'report',
            'plural'   => 'reports',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'id'            => __('ID', 'bcc-disputes'),
            'reported_name' => __('Reported User', 'bcc-disputes'),
            'reporter_name' => __('Reporter', 'bcc-disputes'),
            'reason_key'    => __('Reason', 'bcc-disputes'),
            'reason_detail' => __('Details', 'bcc-disputes'),
            'status'        => __('Status', 'bcc-disputes'),
            'created_at'    => __('Created', 'bcc-disputes'),
            'actions'       => __('Actions', 'bcc-disputes'),
        ];
    }

    public function get_sortable_columns(): array
    {
        return [
            'id'         => ['id', true],
            'status'     => ['status', false],
            'created_at' => ['created_at', false],
        ];
    }

    protected function get_views(): array
    {
        $current = isset($_GET['report_status']) ? sanitize_key($_GET['report_status']) : 'all';
        $base    = admin_url('admin.php?page=bcc-reports');

        $counts = DisputeRepository::getReportStatusCounts();
        $total  = array_sum($counts);

        $views = [];
        $views['all'] = sprintf(
            '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
            esc_url($base),
            $current === 'all' ? 'current' : '',
            __('All', 'bcc-disputes'),
            $total
        );

        foreach (['open', 'reviewed', 'penalized', 'dismissed'] as $s) {
            $c = $counts[$s] ?? 0;
            $views[$s] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('report_status', $s, $base)),
                $current === $s ? 'current' : '',
                ucfirst($s),
                $c
            );
        }

        return $views;
    }

    public function prepare_items(): void
    {
        // Filters
        $status_filter = isset($_GET['report_status']) ? sanitize_key($_GET['report_status']) : '';
        if (!in_array($status_filter, ['open', 'reviewed', 'penalized', 'dismissed'], true)) {
            $status_filter = '';
        }

        // Sorting
        $allowed_orderby = ['id', 'status', 'created_at'];
        $orderby = isset($_GET['orderby']) && in_array($_GET['orderby'], $allowed_orderby, true)
            ? sanitize_key($_GET['orderby'])
            : 'id';
        $order = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Count
        $total = DisputeRepository::countReportsForAdminList($status_filter ?: null);

        // Pagination
        $per_page = 20;
        $paged    = $this->get_pagenum();
        $offset   = ($paged - 1) * $per_page;

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => ceil($total / $per_page),
        ]);

        // Query via repository — explicit columns, no SELECT *.
        $this->items = DisputeRepository::getReportsForAdminList(
            $status_filter ?: null,
            $orderby,
            $order,
            $per_page,
            $offset
        );

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    private static function reason_labels(): array
    {
        return [
            'spam'            => __('Spam or unsolicited content', 'bcc-disputes'),
            'harassment'      => __('Harassment or bullying', 'bcc-disputes'),
            'fraud'           => __('Fraudulent activity or scam', 'bcc-disputes'),
            'misinformation'  => __('False or misleading information', 'bcc-disputes'),
            'inappropriate'   => __('Inappropriate content', 'bcc-disputes'),
            'impersonation'   => __('Impersonating another person', 'bcc-disputes'),
            'other'           => __('Other', 'bcc-disputes'),
        ];
    }

    public function column_default($item, $column_name)
    {
        $labels = self::reason_labels();

        switch ($column_name) {
            case 'id':
                return (int) $item->id;

            case 'reported_name':
                return esc_html($item->reported_name ?: __('Unknown', 'bcc-disputes'))
                     . ' <span class="description">(#' . (int) $item->reported_id . ')</span>';

            case 'reporter_name':
                return esc_html($item->reporter_name ?: __('Unknown', 'bcc-disputes'))
                     . ' <span class="description">(#' . (int) $item->reporter_id . ')</span>';

            case 'reason_key':
                return esc_html($labels[$item->reason_key] ?? $item->reason_key);

            case 'reason_detail':
                $text = $item->reason_detail ?: '';
                return esc_html(mb_strimwidth($text, 0, 80, '…'));

            case 'status':
                $colors = [
                    'open'      => '#ed6c02',
                    'reviewed'  => '#2e7d32',
                    'penalized' => '#d63638',
                    'dismissed' => '#666',
                ];
                $color = $colors[$item->status] ?? '#666';
                return sprintf(
                    '<span style="font-weight:600;color:%s;">%s</span>',
                    esc_attr($color),
                    esc_html(ucfirst($item->status))
                );

            case 'created_at':
                return esc_html($item->created_at);

            case 'actions':
                $is_open = $item->status === 'open';
                if (!$is_open) {
                    $label = $item->status === 'penalized'
                        ? sprintf(__('Penalized (-%s pts)', 'bcc-disputes'), esc_html($item->penalty_amount ?? '?'))
                        : ucfirst($item->status);
                    return '<em>' . esc_html($label) . '</em>';
                }

                $reviewed_url = wp_nonce_url(
                    add_query_arg(['page' => 'bcc-reports', 'report_id' => (int) $item->id, 'report_action' => 'reviewed'], admin_url('admin.php')),
                    'bcc_report_action_' . (int) $item->id
                );
                $dismissed_url = wp_nonce_url(
                    add_query_arg(['page' => 'bcc-reports', 'report_id' => (int) $item->id, 'report_action' => 'dismissed'], admin_url('admin.php')),
                    'bcc_report_action_' . (int) $item->id
                );

                $penalize_nonce = wp_create_nonce('bcc_report_penalize_' . (int) $item->id);

                return sprintf(
                    '<a href="%s" class="button button-small" onclick="return confirm(\'%s\');">%s</a> ',
                    esc_url($reviewed_url),
                    esc_js(__('Mark this report as reviewed?', 'bcc-disputes')),
                    esc_html__('Reviewed', 'bcc-disputes')
                ) . sprintf(
                    '<a href="%s" class="button button-small" onclick="return confirm(\'%s\');">%s</a>',
                    esc_url($dismissed_url),
                    esc_js(__('Dismiss this report?', 'bcc-disputes')),
                    esc_html__('Dismiss', 'bcc-disputes')
                ) . sprintf(
                    '<form method="post" action="%s" style="display:inline-flex;align-items:center;gap:4px;margin-top:6px;" '
                    . 'onsubmit="return confirm(\'Reduce this user\\\'s reputation score?\');">'
                    . '<input type="hidden" name="page" value="bcc-reports" />'
                    . '<input type="hidden" name="report_action" value="penalize" />'
                    . '<input type="hidden" name="report_id" value="%d" />'
                    . '<input type="hidden" name="_wpnonce" value="%s" />'
                    . '<label style="font-size:12px;white-space:nowrap;">Penalize:</label>'
                    . '<input type="number" name="penalty_points" min="1" max="20" value="5" '
                    .   'style="width:55px;height:28px;padding:2px 4px;" title="Points to deduct (1-20)" />'
                    . '<input type="text" name="penalty_reason" placeholder="Reason..." '
                    .   'style="width:120px;height:28px;padding:2px 4px;font-size:12px;" />'
                    . '<button type="submit" class="button button-small" style="color:#d63638;">Apply</button>'
                    . '</form>',
                    esc_url(admin_url('admin-post.php')),
                    (int) $item->id,
                    esc_attr($penalize_nonce)
                );

            default:
                return '';
        }
    }

    public function no_items(): void
    {
        esc_html_e('No user reports found.', 'bcc-disputes');
    }
}
