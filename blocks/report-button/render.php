<?php
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) {
    if (defined('REST_REQUEST') && REST_REQUEST) {
        echo '<div class="bcc-block-placeholder" style="padding:20px;background:#f0f0f0;border:1px dashed #ccc;color:#666;text-align:center;border-radius:4px;">'
           . '<strong>Report Button</strong><br>'
           . '<small>Visible on other users\' profiles when logged in.</small>'
           . '</div>';
        return;
    }
    return;
}

$reported_id = !empty($attributes['userId']) ? (int) $attributes['userId'] : 0;
if (!$reported_id || $reported_id === get_current_user_id()) {
    if (defined('REST_REQUEST') && REST_REQUEST) {
        echo '<div class="bcc-block-placeholder" style="padding:20px;background:#f0f0f0;border:1px dashed #ccc;color:#666;text-align:center;border-radius:4px;">'
           . '<strong>Report Button</strong><br>'
           . '<small>Visible on other users\' profiles when logged in.</small>'
           . '</div>';
        return;
    }
    return;
}

$user = get_userdata($reported_id);
if (!$user) {
    return;
}

wp_enqueue_style('bcc-disputes');
wp_enqueue_script('bcc-disputes');
?>
<div <?php echo get_block_wrapper_attributes(); ?>>
    <button class="bcc-report-user-btn"
            data-user-id="<?php echo esc_attr($reported_id); ?>"
            data-user-name="<?php echo esc_attr($user->display_name); ?>">&#9873; <?php esc_html_e('Report User', 'bcc-disputes'); ?></button>
</div>
