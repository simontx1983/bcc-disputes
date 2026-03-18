<?php

namespace BCC\Disputes\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeRepository
{
    public static function disputes_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_disputes';
    }

    public static function panel_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_dispute_panel';
    }

    public static function user_reports_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bcc_user_reports';
    }

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
            status          VARCHAR(20)     NOT NULL DEFAULT 'pending',
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
