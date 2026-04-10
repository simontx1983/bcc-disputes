<?php
/**
 * BCC Disputes – Uninstall handler.
 *
 * Runs when the plugin is deleted via the WordPress admin.
 * Drops all custom tables and cleans up options/cron hooks.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop custom tables.
// Prefer DB::table() from bcc-core for consistent prefix resolution;
// fall back to manual prefix if bcc-core is unavailable during uninstall.
if (class_exists('\\BCC\\Core\\DB\\DB')) {
    $tables = [
        \BCC\Core\DB\DB::table('disputes'),
        \BCC\Core\DB\DB::table('dispute_panel'),
        \BCC\Core\DB\DB::table('user_reports'),
    ];
} else {
    $prefix = $wpdb->prefix . 'bcc_';
    $tables = [
        $prefix . 'disputes',
        $prefix . 'dispute_panel',
        $prefix . 'user_reports',
    ];
}

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Clean up cron hooks.
wp_clear_scheduled_hook('bcc_disputes_auto_resolve');
