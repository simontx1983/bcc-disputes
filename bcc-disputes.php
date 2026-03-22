<?php
/**
 * Plugin Name: Blue Collar Crypto – Disputes
 * Description: Community dispute system for BCC trust votes. Page owners can challenge votes; Gold/Platinum panelists decide the outcome.
 * Version: 1.1.0
 * Author: Blue Collar Labs LLC
 * Text Domain: bcc-disputes
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: bcc-core
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BCC_DISPUTES_VERSION', '1.1.0');
define('BCC_DISPUTES_PATH', plugin_dir_path(__FILE__));
define('BCC_DISPUTES_URL', plugin_dir_url(__FILE__));
define('BCC_DISPUTES_PANEL_SIZE', 5);     // panelists per dispute
define('BCC_DISPUTES_TTL_DAYS', 7);       // auto-resolve after N days

// ── Dependency check — bcc-core must be active ──────────────────────────────
if ( ! defined( 'BCC_CORE_VERSION' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
           . '<strong>BCC Disputes:</strong> '
           . 'The <strong>BCC Core</strong> plugin must be activated first. '
           . 'Please activate BCC Core, then re-activate BCC Disputes.'
           . '</p></div>';
    } );
    return;
}

$bcc_disputes_autoloader = BCC_DISPUTES_PATH . 'vendor/autoload.php';
if ( ! file_exists( $bcc_disputes_autoloader ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
           . '<strong>BCC Disputes:</strong> '
           . 'Run <code>composer install</code> in the plugin directory to generate the autoloader.'
           . '</p></div>';
    } );
    return;
}
require_once $bcc_disputes_autoloader;

if (is_admin()) {
    \BCC\Disputes\Admin\DisputeAdmin::boot();
}

// ── Activation: install tables + schedule cron ───────────────────────────────
register_activation_hook(__FILE__, function () {
    \BCC\Disputes\Repositories\DisputeRepository::install();
    \BCC\Disputes\Services\DisputeScheduler::schedule();
});

register_deactivation_hook(__FILE__, function () {
    \BCC\Disputes\Services\DisputeScheduler::unschedule();
});

add_action('init', function () {
    \BCC\Disputes\Services\DisputeScheduler::boot();
});

// ── Boot ─────────────────────────────────────────────────────────────────────
add_action('rest_api_init', function () {
    \BCC\Disputes\Plugin::instance()->controller()->register_routes();
});

add_action('wp_enqueue_scripts', function () {
    $should_enqueue = false;

    // Check shortcodes in current post content
    $post = get_post();
    if ($post instanceof WP_Post) {
        $content = $post->post_content;
        if (has_shortcode($content, 'bcc_dispute_form')
            || has_shortcode($content, 'bcc_dispute_queue')
            || has_shortcode($content, 'bcc_report_button')
        ) {
            $should_enqueue = true;
        }
    }

    // Always load on PeepSo profile pages (report button is injected there)
    if (!$should_enqueue && function_exists('PeepSo') && class_exists('PeepSoProfileShortcode')) {
        $profile_page = PeepSo::get_option('page_profile');
        if ($profile_page && (int) $profile_page === (int) get_the_ID()) {
            $should_enqueue = true;
        }
    }

    if (!$should_enqueue) {
        return;
    }

    wp_enqueue_style('bcc-disputes', BCC_DISPUTES_URL . 'assets/css/bcc-disputes.css', [], BCC_DISPUTES_VERSION);
    wp_enqueue_script('bcc-disputes', BCC_DISPUTES_URL . 'assets/js/bcc-disputes.js', [], BCC_DISPUTES_VERSION, true);
    wp_localize_script('bcc-disputes', 'bccDisputes', [
        'restUrl'       => esc_url_raw(rest_url('bcc/v1/disputes')),
        'reportUserUrl' => esc_url_raw(rest_url('bcc/v1/report-user')),
        'nonce'         => wp_create_nonce('wp_rest'),
        'userId'        => get_current_user_id(),
    ]);
});

// ── PeepSo profile button ─────────────────────────────────────────────────────

/**
 * Inject the Report User button into PeepSo user profiles.
 * $user is either a PeepSoUser object or a plain object with ->id and ->display_name.
 */
add_action('peepso_user_profile_after_buttons', function ($user) {
    if (!is_user_logged_in()) return;
    $profile_uid  = isset($user->id) ? (int) $user->id : (int) ($user->ID ?? 0);
    $current_uid  = get_current_user_id();
    if (!$profile_uid || $profile_uid === $current_uid) return;
    $display_name = isset($user->display_name) ? $user->display_name : get_userdata($profile_uid)->display_name;
    printf(
        '<button class="bcc-report-user-btn" data-user-id="%d" data-user-name="%s">⚑ %s</button>',
        $profile_uid,
        esc_attr($display_name),
        esc_html__('Report User', 'bcc-disputes')
    );
});

// ── Shortcodes ────────────────────────────────────────────────────────────────

/**
 * [bcc_report_button user_id="123"]
 * Renders a Report User button for the given user.
 * Hides if the viewer is not logged in or is the same user.
 */
add_shortcode('bcc_report_button', function ($atts) {
    if (!is_user_logged_in()) return '';

    $atts        = shortcode_atts(['user_id' => 0], $atts, 'bcc_report_button');
    $reported_id = (int) $atts['user_id'];
    if (!$reported_id || $reported_id === get_current_user_id()) return '';

    $user = get_userdata($reported_id);
    if (!$user) return '';

    return sprintf(
        '<button class="bcc-report-user-btn" data-user-id="%d" data-user-name="%s">⚑ %s</button>',
        $reported_id,
        esc_attr($user->display_name),
        esc_html__('Report User', 'bcc-disputes')
    );
});

/**
 * [bcc_dispute_form page_id="123"]
 * Dispute management panel for the page owner:
 *   - Lists all votes on the page (via JS/REST) with a "Dispute" button per vote
 *   - Lists pending & resolved disputes for this page
 */
add_shortcode('bcc_dispute_form', function ($atts) {
    if (!is_user_logged_in()) {
        return '<p class="bcc-dispute-notice">' . esc_html__('Log in to manage disputes.', 'bcc-disputes') . '</p>';
    }

    $atts = shortcode_atts(['page_id' => 0], $atts, 'bcc_dispute_form');
    $page_id = (int) $atts['page_id'] ?: get_the_ID();
    if (!$page_id) {
        return '';
    }

    ob_start();
    include BCC_DISPUTES_PATH . 'templates/dispute-form.php';
    return ob_get_clean();
});

/**
 * [bcc_dispute_queue]
 * Panelist queue: list of pending disputes assigned to the current user.
 * Automatically hidden if the user is not Gold/Platinum tier.
 */
add_shortcode('bcc_dispute_queue', function () {
    if (!is_user_logged_in()) {
        return '';
    }

    ob_start();
    include BCC_DISPUTES_PATH . 'templates/dispute-queue.php';
    return ob_get_clean();
});
