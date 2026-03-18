<?php

namespace BCC\Disputes\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class Logger
{
    public static function logFailure(string $operation, array $context = []): void
    {
        if (class_exists('BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] ' . $operation, $context);
        } else {
            error_log('[bcc-disputes] ' . $operation . ' ' . wp_json_encode($context));
        }
    }
}
