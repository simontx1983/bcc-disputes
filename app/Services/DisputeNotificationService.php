<?php

namespace BCC\Disputes\Services;

use BCC\Disputes\Repositories\DisputeRepository;
use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeNotificationService
{
    /**
     * Register async action handlers for deferred email notifications.
     * Called once during plugin boot (init hook).
     */
    public static function registerAsyncHandlers(): void
    {
        add_action('bcc_disputes_notify_panelist', function (int $uid, int $dispute_id, int $page_id) {
            self::notifyPanelist($uid, $dispute_id, $page_id);
        }, 10, 3);

        add_action('bcc_disputes_email_reported_user', function (int $report_id, int $reported_user_id) {
            $user = get_userdata($reported_user_id);
            if ($user) {
                self::emailReportedUser($report_id, $user);
            }
        }, 10, 2);

        add_action('bcc_disputes_email_admin_report', function (int $report_id, int $reporter_id, int $reported_user_id, string $reason_key, string $reason_detail) {
            $reported_user = get_userdata($reported_user_id);
            if ($reported_user) {
                self::emailAdminReport($report_id, $reporter_id, $reported_user, $reason_key, $reason_detail);
            }
        }, 10, 5);

        add_action('bcc_disputes_email_reporter_result', function (int $dispute_id, int $reporter_id, string $outcome) {
            self::emailReporterResult($dispute_id, $reporter_id, $outcome);
        }, 10, 3);
    }

    /**
     * Enqueue an async action. Uses Action Scheduler when available;
     * falls back to wp_schedule_single_event (wp-cron).
     *
     * @param array<int, mixed> $args
     */
    public static function enqueueAsync(string $hook, array $args): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action($hook, $args, 'bcc-disputes');
            return;
        }

        // Even with DISABLE_WP_CRON, wp_schedule_single_event queues the
        // event in the database. It will fire on the next HTTP request that
        // invokes wp-cron.php (which managed hosts trigger via system cron).
        // The old synchronous fallback risked 50 sequential resolutions
        // timing out the cron process.
        //
        // On hosts where system cron fires wp-cron.php (WP Engine, Kinsta),
        // this works. If neither WP-Cron nor system cron is available, the
        // admin notice in DisputeScheduler::warnIfCronDisabled() alerts.
        wp_schedule_single_event(time(), $hook, $args);
    }

    public static function notifyPanelist(int $uid, int $dispute_id, int $page_id): void
    {
        // Idempotency gate: only send if notified_at is still NULL.
        // markPanelistNotified atomically sets the timestamp and returns
        // false if already set, preventing duplicate emails on retries.
        if (!DisputeRepository::markPanelistNotified($dispute_id, $uid)) {
            return;
        }

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
        if (!wp_mail($user->user_email, $subject, $body)) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] wp_mail failed', [
                'type' => 'panelist_notification',
                'user_id' => $uid,
                'dispute_id' => $dispute_id,
            ]);
        }
    }

    public static function emailReportedUser(int $report_id, WP_User $reported_user): void
    {
        if (!DisputeRepository::markReportNotified($report_id)) {
            return; // Already sent.
        }
        $site_name = get_bloginfo('name');
        $subject   = sprintf('[%s] Your account has received a report', $site_name);
        $body      = sprintf(
            "Hello %s,\n\nWe wanted to let you know that a report has been submitted regarding your account on %s.\n\nOur moderation team will review the report and take appropriate action if necessary. No action is required from you at this time.\n\nIf you believe this report was made in error, you can contact us by replying to this email.\n\nThe %s Team",
            $reported_user->display_name,
            $site_name,
            $site_name
        );
        if (!wp_mail($reported_user->user_email, $subject, $body)) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] wp_mail failed', [
                'type' => 'reported_user_notification',
                'report_id' => $report_id,
            ]);
        }
    }

    public static function emailAdminReport(int $report_id, int $reporter_id, WP_User $reported_user, string $reason_key, string $reason_detail): void
    {
        // Idempotency gate: prevent duplicate admin emails on retry/overlap.
        // Token is set AFTER successful wp_mail to allow retry on failure.
        $lock_key = 'bcc_admin_report_sent_' . $report_id;
        if (get_transient($lock_key)) {
            return;
        }

        $admin_email   = get_option('admin_email');
        $site_name     = get_bloginfo('name');
        $reporter      = get_userdata($reporter_id);
        $admin_url     = admin_url('admin.php?page=bcc-reports');

        $reason_labels = [
            'spam'            => 'Spam or unsolicited content',
            'harassment'      => 'Harassment or bullying',
            'fraud'           => 'Fraudulent activity or scam',
            'misinformation'  => 'False or misleading information',
            'inappropriate'   => 'Inappropriate content',
            'impersonation'   => 'Impersonating another person',
            'other'           => 'Other',
        ];

        $subject = sprintf('[%s Admin] User Report #%d — %s', $site_name, $report_id, $reason_labels[$reason_key] ?? $reason_key);
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
            gmdate('Y-m-d H:i:s'),
            $reported_user->ID,
            $reported_user->display_name,
            $reported_user->user_email,
            get_author_posts_url($reported_user->ID),
            $reporter_id,
            $reporter ? $reporter->display_name : 'Unknown',
            $reporter ? $reporter->user_email   : 'Unknown',
            $reason_labels[$reason_key] ?? $reason_key,
            $reason_detail ?: '(none provided)',
            $admin_url
        );

        if (wp_mail($admin_email, $subject, $body)) {
            // Only set the idempotency token AFTER confirmed send.
            // If wp_mail fails, the token is NOT set, allowing retry.
            set_transient($lock_key, 1, DAY_IN_SECONDS);
        } else {
            \BCC\Core\Log\Logger::error('[bcc-disputes] wp_mail failed', [
                'type' => 'admin_report_notification',
                'report_id' => $report_id,
            ]);
        }
    }

    public static function emailReporterResult(int $disputeId, int $reporterId, string $outcome): void
    {
        if (!DisputeRepository::markResolvedNotified($disputeId)) {
            return; // Already sent.
        }

        $reporter = get_userdata($reporterId);
        if (!$reporter || !$reporter->user_email) {
            return;
        }

        $subject = $outcome === 'accepted'
            ? '[BCC] Your dispute was accepted — vote removed'
            : '[BCC] Your dispute was reviewed — vote stands';
        $body = $outcome === 'accepted'
            ? 'Good news! The community panel reviewed your dispute and agreed the vote was invalid. It has been removed from your trust score.'
            : 'The community panel reviewed your dispute and decided the vote was valid. The vote remains on your profile.';
        if (!wp_mail($reporter->user_email, $subject, $body)) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] wp_mail failed', [
                'type' => 'reporter_result_notification',
                'dispute_id' => $disputeId,
                'reporter_id' => $reporterId,
            ]);
        }
    }
}
