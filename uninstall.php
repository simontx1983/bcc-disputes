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
$prefix = $wpdb->prefix . 'bcc_';
$tables = [
    $prefix . 'disputes',
    $prefix . 'dispute_panel',
    $prefix . 'user_reports',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Clean up options.
delete_option('bcc_disputes_db_version');

// Clean up cron hooks.
wp_clear_scheduled_hook('bcc_disputes_auto_resolve');
