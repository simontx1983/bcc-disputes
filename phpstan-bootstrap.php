<?php
/**
 * PHPStan bootstrap for bcc-disputes.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 4) . '/');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'phpstan_stub');
}

// Plugin constants (normally defined in bcc-disputes.php after ABSPATH check)
if (!defined('BCC_DISPUTES_VERSION')) {
    define('BCC_DISPUTES_VERSION', '1.1.0');
}
if (!defined('BCC_DISPUTES_PATH')) {
    define('BCC_DISPUTES_PATH', __DIR__ . '/');
}
if (!defined('BCC_DISPUTES_URL')) {
    define('BCC_DISPUTES_URL', '/wp-content/plugins/bcc-disputes/');
}
if (!defined('BCC_DISPUTES_PANEL_SIZE')) {
    define('BCC_DISPUTES_PANEL_SIZE', 5);
}
if (!defined('BCC_DISPUTES_TTL_DAYS')) {
    define('BCC_DISPUTES_TTL_DAYS', 7);
}
if (!defined('BCC_DISPUTES_MAX_PER_PAGE')) {
    define('BCC_DISPUTES_MAX_PER_PAGE', 3);
}
if (!defined('BCC_DISPUTES_REPORTER_MAX_ACTIVE')) {
    define('BCC_DISPUTES_REPORTER_MAX_ACTIVE', 5);
}
if (!defined('BCC_DISPUTES_MIN_REASON_LENGTH')) {
    define('BCC_DISPUTES_MIN_REASON_LENGTH', 10);
}
if (!defined('BCC_DISPUTES_MAX_REASON_LENGTH')) {
    define('BCC_DISPUTES_MAX_REASON_LENGTH', 1000);
}
if (!defined('BCC_DISPUTES_MIN_DETAIL_LENGTH')) {
    define('BCC_DISPUTES_MIN_DETAIL_LENGTH', 10);
}

// ActionScheduler stub
if (!class_exists('ActionScheduler_Store')) {
    class ActionScheduler_Store {
        const STATUS_PENDING = 'pending';
        const STATUS_RUNNING = 'in-progress';
        const STATUS_COMPLETE = 'complete';
        const STATUS_CANCELED = 'canceled';
        const STATUS_FAILED = 'failed';
    }
}
