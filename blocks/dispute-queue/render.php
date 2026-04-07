<?php
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) {
    return;
}

wp_enqueue_style('bcc-disputes');
wp_enqueue_script('bcc-disputes');
?>
<div <?php echo get_block_wrapper_attributes(); ?>>
    <div class="bcc-dispute-queue" id="bcc-dispute-queue">

        <div class="bcc-dispute-queue__header">
            <h3 class="bcc-dispute-queue__title"><?php esc_html_e('Dispute Panel Queue', 'bcc-disputes'); ?></h3>
            <p class="bcc-dispute-queue__sub"><?php esc_html_e('As a Gold or Platinum member, you have been selected to review the disputes below. Read the reason and evidence, then vote.', 'bcc-disputes'); ?></p>
        </div>

        <div class="bcc-dispute-queue__list" id="bcc-dispute-queue-list">
            <div class="bcc-dispute-loading"><?php esc_html_e('Loading disputes&hellip;', 'bcc-disputes'); ?></div>
        </div>

    </div>
</div>
