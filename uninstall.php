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
wp_clear_scheduled_hook('bcc_disputes_reconcile_orphans');

// Clean up options.
delete_option('bcc_disputes_auto_resolve_last_run');

// Clean up transients created by DisputeNotificationService (idempotency locks).
// Pattern: bcc_admin_report_sent_{report_id}
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_bcc_admin_report_sent_%'
        OR option_name LIKE '_transient_timeout_bcc_admin_report_sent_%'"
);

// Clean up throttle transients created by DisputeController.
// Pattern: bcc_throttle_{action}_{user_id}
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_bcc_throttle_%'
        OR option_name LIKE '_transient_timeout_bcc_throttle_%'"
);
