<?php
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) {
    printf(
        '<div %s><p class="bcc-dispute-notice">%s</p></div>',
        get_block_wrapper_attributes(),
        esc_html__('Log in to manage disputes.', 'bcc-disputes')
    );
    return;
}

$page_id = !empty($attributes['pageId']) ? (int) $attributes['pageId'] : get_the_ID();
if (!$page_id) {
    if (defined('REST_REQUEST') && REST_REQUEST) {
        echo '<div class="bcc-block-placeholder" style="padding:20px;background:#f0f0f0;border:1px dashed #ccc;color:#666;text-align:center;border-radius:4px;">'
           . '<strong>Dispute Form</strong><br>'
           . '<small>Requires a logged-in page owner on their PeepSo profile.</small>'
           . '</div>';
        return;
    }
    return;
}

wp_enqueue_style('bcc-disputes');
wp_enqueue_script('bcc-disputes');
?>
<div <?php echo get_block_wrapper_attributes(); ?>>
    <div class="bcc-dispute-form" data-page-id="<?php echo esc_attr($page_id); ?>">

        <div class="bcc-dispute-form__header">
            <h3 class="bcc-dispute-form__title"><?php esc_html_e('Vote Disputes', 'bcc-disputes'); ?></h3>
            <p class="bcc-dispute-form__sub"><?php
                printf(
                    /* translators: %d: number of days for auto-resolve */
                    esc_html__('Select a vote on your page to challenge it. A panel of Gold & Platinum members will review and decide within %d days.', 'bcc-disputes'),
                    BCC_DISPUTES_TTL_DAYS
                );
            ?></p>
        </div>

        <div class="bcc-dispute-form__votes" id="bcc-dispute-vote-list">
            <div class="bcc-dispute-loading"><?php esc_html_e('Loading votes&hellip;', 'bcc-disputes'); ?></div>
        </div>

        <!-- Submit form (shown in modal-like inline panel) -->
        <div class="bcc-dispute-submit-panel" id="bcc-dispute-submit-panel" hidden>
            <h4 class="bcc-dispute-submit-panel__heading"><?php esc_html_e('Submit Dispute', 'bcc-disputes'); ?></h4>
            <p class="bcc-dispute-submit-panel__voter"></p>

            <label class="bcc-dispute-label">
                <?php esc_html_e('Reason', 'bcc-disputes'); ?> <span class="bcc-dispute-required">*</span>
                <textarea class="bcc-dispute-reason" rows="4" minlength="<?php echo esc_attr(BCC_DISPUTES_MIN_REASON_LENGTH); ?>" maxlength="<?php echo esc_attr(BCC_DISPUTES_MAX_REASON_LENGTH); ?>"
                          placeholder="<?php echo esc_attr(sprintf(__('Explain why you believe this vote is invalid (min %d chars)&hellip;', 'bcc-disputes'), BCC_DISPUTES_MIN_REASON_LENGTH)); ?>"></textarea>
            </label>

            <label class="bcc-dispute-label">
                <?php esc_html_e('Evidence URL (optional)', 'bcc-disputes'); ?>
                <input type="url" class="bcc-dispute-evidence" placeholder="https://&hellip;">
            </label>

            <div class="bcc-dispute-submit-actions">
                <button type="button" class="bcc-dispute-btn bcc-dispute-btn--secondary" id="bcc-dispute-cancel"><?php esc_html_e('Cancel', 'bcc-disputes'); ?></button>
                <button type="button" class="bcc-dispute-btn bcc-dispute-btn--primary" id="bcc-dispute-submit"><?php esc_html_e('Submit Dispute', 'bcc-disputes'); ?></button>
            </div>
            <p class="bcc-dispute-status" aria-live="polite"></p>
        </div>

        <!-- Past disputes -->
        <div class="bcc-dispute-history" id="bcc-dispute-history">
            <h4 class="bcc-dispute-history__heading"><?php esc_html_e('Previous Disputes', 'bcc-disputes'); ?></h4>
            <div class="bcc-dispute-history__list"></div>
        </div>
    </div>
</div>
