<?php
// Variables: $page_id (int)
if (!defined('ABSPATH')) exit;
?>
<div class="bcc-dispute-form" data-page-id="<?php echo esc_attr($page_id); ?>">

    <div class="bcc-dispute-form__header">
        <h3 class="bcc-dispute-form__title">Vote Disputes</h3>
        <p class="bcc-dispute-form__sub">Select a vote on your page to challenge it. A panel of Gold &amp; Platinum members will review and decide within <?php echo esc_html(BCC_DISPUTES_TTL_DAYS); ?> days.</p>
    </div>

    <div class="bcc-dispute-form__votes" id="bcc-dispute-vote-list">
        <div class="bcc-dispute-loading">Loading votes…</div>
    </div>

    <!-- Submit form (shown in modal-like inline panel) -->
    <div class="bcc-dispute-submit-panel" id="bcc-dispute-submit-panel" hidden>
        <h4 class="bcc-dispute-submit-panel__heading">Submit Dispute</h4>
        <p class="bcc-dispute-submit-panel__voter"></p>

        <label class="bcc-dispute-label">
            Reason <span class="bcc-dispute-required">*</span>
            <textarea class="bcc-dispute-reason" rows="4" minlength="20" maxlength="1000"
                      placeholder="Explain why you believe this vote is invalid (min 20 chars)…"></textarea>
        </label>

        <label class="bcc-dispute-label">
            Evidence URL (optional)
            <input type="url" class="bcc-dispute-evidence" placeholder="https://…">
        </label>

        <div class="bcc-dispute-submit-actions">
            <button type="button" class="bcc-dispute-btn bcc-dispute-btn--secondary" id="bcc-dispute-cancel">Cancel</button>
            <button type="button" class="bcc-dispute-btn bcc-dispute-btn--primary" id="bcc-dispute-submit">Submit Dispute</button>
        </div>
        <p class="bcc-dispute-status" aria-live="polite"></p>
    </div>

    <!-- Past disputes -->
    <div class="bcc-dispute-history" id="bcc-dispute-history">
        <h4 class="bcc-dispute-history__heading">Previous Disputes</h4>
        <div class="bcc-dispute-history__list"></div>
    </div>
</div>
