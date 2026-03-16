<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BCC_Disputes_List_Table extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'dispute',
            'plural'   => 'disputes',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'id'            => __('ID', 'bcc-disputes'),
            'page_title'    => __('Page', 'bcc-disputes'),
            'reporter_name' => __('Reporter', 'bcc-disputes'),
            'voter_name'    => __('Accused Voter', 'bcc-disputes'),
            'vote_type'     => __('Vote', 'bcc-disputes'),
            'reason'        => __('Reason', 'bcc-disputes'),
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
        $current = isset($_GET['dispute_status']) ? sanitize_key($_GET['dispute_status']) : 'all';
        $base    = admin_url('admin.php?page=bcc-disputes');

        global $wpdb;
        $dt = BCC_Disputes_DB::disputes_table();

        $counts = [];
        $rows   = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM {$dt} GROUP BY status");
        $total  = 0;
        foreach ($rows as $r) {
            $counts[$r->status] = (int) $r->cnt;
            $total += (int) $r->cnt;
        }

        $views = [];
        $views['all'] = sprintf(
            '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
            esc_url($base),
            $current === 'all' ? 'current' : '',
            __('All', 'bcc-disputes'),
            $total
        );

        foreach (['pending', 'reviewing', 'accepted', 'rejected'] as $s) {
            $c = $counts[$s] ?? 0;
            $views[$s] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg('dispute_status', $s, $base)),
                $current === $s ? 'current' : '',
                ucfirst($s),
                $c
            );
        }

        return $views;
    }

    public function prepare_items(): void
    {
        global $wpdb;

        $dt          = BCC_Disputes_DB::disputes_table();
        $votes_table = function_exists('bcc_trust_votes_table')
            ? bcc_trust_votes_table()
            : $wpdb->prefix . 'bcc_trust_votes';

        // Filters
        $where  = '1=1';
        $params = [];

        $status_filter = isset($_GET['dispute_status']) ? sanitize_key($_GET['dispute_status']) : '';
        if ($status_filter && in_array($status_filter, ['pending', 'reviewing', 'accepted', 'rejected'], true)) {
            $where   .= ' AND d.status = %s';
            $params[] = $status_filter;
        }

        // Sorting
        $allowed_orderby = ['id', 'status', 'created_at'];
        $orderby = isset($_GET['orderby']) && in_array($_GET['orderby'], $allowed_orderby, true)
            ? sanitize_key($_GET['orderby'])
            : 'id';
        $order = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Count
        $count_sql = "SELECT COUNT(*) FROM {$dt} d WHERE {$where}";
        $total     = $params
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params))
            : (int) $wpdb->get_var($count_sql);

        // Pagination
        $per_page = 20;
        $paged    = $this->get_pagenum();
        $offset   = ($paged - 1) * $per_page;

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => ceil($total / $per_page),
        ]);

        // Query
        $sql = "SELECT d.*,
                       p.post_title   AS page_title,
                       reporter.display_name AS reporter_name,
                       voter.display_name    AS voter_name,
                       v.vote_type
                FROM {$dt} d
                LEFT JOIN {$wpdb->posts} p         ON d.page_id     = p.ID
                LEFT JOIN {$wpdb->users} reporter   ON d.reporter_id = reporter.ID
                LEFT JOIN {$wpdb->users} voter      ON d.voter_id    = voter.ID
                LEFT JOIN {$votes_table} v          ON d.vote_id     = v.id
                WHERE {$where}
                ORDER BY d.{$orderby} {$order}
                LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        $this->items = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'id':
                return (int) $item->id;

            case 'page_title':
                $title = $item->page_title ?: __('(no title)', 'bcc-disputes');
                return sprintf(
                    '%s <span class="description">(#%d)</span>',
                    esc_html($title),
                    (int) $item->page_id
                );

            case 'reporter_name':
                return esc_html($item->reporter_name ?: __('Unknown', 'bcc-disputes'));

            case 'voter_name':
                return esc_html($item->voter_name ?: __('Unknown', 'bcc-disputes'));

            case 'vote_type':
                if ($item->vote_type === null) {
                    return '—';
                }
                return (int) $item->vote_type > 0
                    ? '<span style="color:#2e7d32;">&#9650; Upvote</span>'
                    : '<span style="color:#c62828;">&#9660; Downvote</span>';

            case 'reason':
                $text = $item->reason ?: '';
                return esc_html(mb_strimwidth($text, 0, 80, '…'));

            case 'status':
                $colors = [
                    'pending'   => '#ed6c02',
                    'reviewing' => '#0288d1',
                    'accepted'  => '#2e7d32',
                    'rejected'  => '#c62828',
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
                $review_url = add_query_arg(
                    ['page' => 'bcc-disputes', 'dispute_id' => (int) $item->id],
                    admin_url('admin.php')
                );
                return sprintf(
                    '<a href="%s" class="button button-small">%s</a>',
                    esc_url($review_url),
                    esc_html__('Review', 'bcc-disputes')
                );

            default:
                return '';
        }
    }

    public function no_items(): void
    {
        esc_html_e('No disputes found.', 'bcc-disputes');
    }
}
