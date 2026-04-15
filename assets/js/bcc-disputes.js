/* global bccDisputes */
(function () {
    'use strict';

    const BASE            = bccDisputes.restUrl;       // /wp-json/bcc/v1/disputes
    const REPORT_USER_URL = bccDisputes.reportUserUrl; // /wp-json/bcc/v1/report-user
    const NONCE           = bccDisputes.nonce;

    function apiFetch(url, opts = {}) {
        return fetch(url, {
            headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json', ...opts.headers },
            ...opts,
        }).then(async res => {
            const json = await res.json();
            if (!res.ok) {
                const err = new Error(json.message || 'Request failed');
                err.code = json.code || '';
                throw err;
            }
            return json;
        });
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function safeUrl(url) {
        if (!url) return '#';
        try { const u = new URL(url, location.origin); return /^https?:$/.test(u.protocol) ? escHtml(url) : '#'; }
        catch (_) { return '#'; }
    }

    function badgeHtml(status) {
        return `<span class="bcc-dispute-badge bcc-dispute-badge--${escHtml(status)}">${escHtml(status)}</span>`;
    }

    function relativeDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr.replace(' ', 'T') + 'Z');
        const s = Math.floor((Date.now() - d.getTime()) / 1000);
        if (s < 60)   return 'just now';
        if (s < 3600) return `${Math.floor(s/60)}m ago`;
        if (s < 86400) return `${Math.floor(s/3600)}h ago`;
        return `${Math.floor(s/86400)}d ago`;
    }

    // ── Owner dispute form ─────────────────────────────────────────────────────
    function initDisputeForm(el) {
        const pageId     = el.dataset.pageId;
        const voteList   = el.querySelector('#bcc-dispute-vote-list');
        const submitPanel = el.querySelector('#bcc-dispute-submit-panel');
        const historyList = el.querySelector('.bcc-dispute-history__list');

        let selectedVoteId   = null;
        let selectedVoterName = '';

        // ── Load votes ──────────────────────────────────────────────────────
        apiFetch(`${BASE}/votes/${pageId}`)
            .then(votes => {
                if (!votes.length) {
                    voteList.innerHTML = '<p class="bcc-dispute-empty">No votes on this page yet.</p>';
                    return;
                }
                voteList.innerHTML = votes.map(v => `
                    <div class="bcc-dispute-vote-item">
                        <div class="bcc-dispute-vote-meta">
                            <div class="bcc-dispute-vote-name">${escHtml(v.voter_name)}</div>
                            <div class="bcc-dispute-vote-type bcc-dispute-vote-type--${v.vote_type === 'upvote' ? 'up' : 'down'}">
                                ${v.vote_type === 'upvote' ? '▲ Upvote' : '▼ Downvote'}
                                · weight ${v.weight} · ${relativeDate(v.date)}
                                ${v.reason ? `· "${escHtml(v.reason.slice(0,60))}${v.reason.length>60?'…':''}"` : ''}
                            </div>
                        </div>
                        <button class="bcc-dispute-btn bcc-dispute-btn--danger bcc-dispute-btn--sm js-open-dispute"
                                data-vote-id="${v.id}"
                                data-voter="${escHtml(v.voter_name)}"
                                ${v.already_disputed ? 'disabled title="Already disputed"' : ''}>
                            ${v.already_disputed ? 'Disputed' : 'Dispute'}
                        </button>
                    </div>
                `).join('');

                voteList.querySelectorAll('.js-open-dispute').forEach(btn => {
                    btn.addEventListener('click', () => {
                        selectedVoteId   = btn.dataset.voteId;
                        selectedVoterName = btn.dataset.voter;
                        submitPanel.querySelector('.bcc-dispute-submit-panel__voter').textContent =
                            `Disputing vote by: ${selectedVoterName}`;
                        submitPanel.removeAttribute('hidden');
                        submitPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        el.querySelector('.bcc-dispute-status').textContent = '';
                        el.querySelector('#bcc-dispute-submit').disabled = false;
                    });
                });
            })
            .catch(() => {
                voteList.innerHTML = '<p class="bcc-dispute-empty">Could not load votes.</p>';
            });

        // ── Cancel ──────────────────────────────────────────────────────────
        el.querySelector('#bcc-dispute-cancel').addEventListener('click', () => {
            submitPanel.setAttribute('hidden', '');
            selectedVoteId = null;
        });

        // ── Submit ──────────────────────────────────────────────────────────
        el.querySelector('#bcc-dispute-submit').addEventListener('click', () => {
            const reason   = el.querySelector('.bcc-dispute-reason').value.trim();
            const evidence = el.querySelector('.bcc-dispute-evidence').value.trim();
            const statusEl = el.querySelector('.bcc-dispute-status');
            const btn      = el.querySelector('#bcc-dispute-submit');

            if (!selectedVoteId) return;
            var minLen = (typeof bccDisputes !== 'undefined' && bccDisputes.minReasonLength) ? parseInt(bccDisputes.minReasonLength, 10) : 20;
            if (reason.length < minLen) {
                statusEl.textContent = 'Reason must be at least ' + minLen + ' characters.';
                statusEl.className = 'bcc-dispute-status bcc-dispute-status--err';
                return;
            }

            btn.disabled = true;
            statusEl.textContent = 'Submitting…';
            statusEl.className = 'bcc-dispute-status';

            apiFetch(BASE, {
                method: 'POST',
                body: JSON.stringify({ vote_id: parseInt(selectedVoteId), reason, evidence_url: evidence }),
            })
                .then(res => {
                    statusEl.textContent = res.message;
                    statusEl.className = 'bcc-dispute-status bcc-dispute-status--ok';
                    submitPanel.setAttribute('hidden', '');
                    loadHistory();
                })
                .catch(err => {
                    if (err.code === 'insufficient_panelists') {
                        statusEl.textContent = err.message;
                        statusEl.className = 'bcc-dispute-status bcc-dispute-status--info';
                    } else {
                        statusEl.textContent = err.message || 'Submission failed.';
                        statusEl.className = 'bcc-dispute-status bcc-dispute-status--err';
                    }
                    btn.disabled = false;
                });
        });

        // ── Load history ────────────────────────────────────────────────────
        function loadHistory() {
            apiFetch(`${BASE}/mine?page_id=${pageId}`)
                .then(disputes => {
                    const mine = disputes;
                    if (!mine.length) {
                        historyList.innerHTML = '<p class="bcc-dispute-empty">No disputes yet.</p>';
                        return;
                    }
                    historyList.innerHTML = mine.map(d => `
                        <div class="bcc-dispute-card">
                            <div class="bcc-dispute-card__meta">
                                <span class="bcc-dispute-card__voter">Vote by ${escHtml(d.voter_name)}</span>
                                ${badgeHtml(d.status)}
                                <span class="bcc-dispute-card__date">${relativeDate(d.created_at)}</span>
                            </div>
                            <div class="bcc-dispute-card__reason">${escHtml(d.reason)}</div>
                            ${d.evidence_url ? `<a class="bcc-dispute-card__evidence" href="${safeUrl(d.evidence_url)}" target="_blank" rel="noopener">View evidence →</a>` : ''}
                            <div class="bcc-dispute-card__tally">Panel: ${d.accepts} accept · ${d.rejects} reject (of ${d.panel_size})</div>
                        </div>
                    `).join('');
                })
                .catch(() => {
                    historyList.innerHTML = '<p class="bcc-dispute-empty">Could not load dispute history.</p>';
                });
        }

        loadHistory();
    }

    // ── Panelist queue ─────────────────────────────────────────────────────────
    function initDisputeQueue(el) {
        const listEl = el.querySelector('#bcc-dispute-queue-list');

        apiFetch(`${BASE}/panel`)
            .then(disputes => {
                if (!disputes.length) {
                    listEl.innerHTML = '<p class="bcc-dispute-empty">No disputes assigned to you — check back later.</p>';
                    return;
                }
                listEl.innerHTML = disputes.map(d => `
                    <div class="bcc-dispute-card" data-dispute-id="${escHtml(d.id)}">
                        <div class="bcc-dispute-card__meta">
                            <span class="bcc-dispute-card__page">${escHtml(d.page_title)}</span>
                            <span class="bcc-dispute-card__voter">· Vote by ${escHtml(d.voter_name)}</span>
                            ${badgeHtml(d.status)}
                            <span class="bcc-dispute-card__date">${relativeDate(d.created_at)}</span>
                        </div>
                        <div class="bcc-dispute-card__reason">${escHtml(d.reason)}</div>
                        ${d.evidence_url ? `<a class="bcc-dispute-card__evidence" href="${safeUrl(d.evidence_url)}" target="_blank" rel="noopener">View evidence →</a>` : ''}
                        <div class="bcc-dispute-card__tally">${d.accepts !== null ? `Current tally: ${d.accepts} accept · ${d.rejects} reject (panel of ${d.panel_size})` : `Panel of ${d.panel_size} — vote to see tally`}</div>
                        ${d.my_decision
                            ? `<p class="bcc-dispute-already-voted">You voted: <strong>${escHtml(d.my_decision)}</strong></p>`
                            : `<div class="bcc-dispute-panel-actions">
                                   <button class="bcc-dispute-btn bcc-dispute-btn--success bcc-dispute-btn--sm js-panel-vote" data-dispute="${escHtml(d.id)}" data-decision="accept">✓ Accept (remove vote)</button>
                                   <button class="bcc-dispute-btn bcc-dispute-btn--secondary bcc-dispute-btn--sm js-panel-vote" data-dispute="${escHtml(d.id)}" data-decision="reject">✗ Reject (keep vote)</button>
                               </div>
                               <p class="bcc-dispute-status" aria-live="polite"></p>`
                        }
                    </div>
                `).join('');

                listEl.querySelectorAll('.js-panel-vote').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const disputeId = btn.dataset.dispute;
                        const decision  = btn.dataset.decision;
                        const card      = btn.closest('.bcc-dispute-card');
                        const statusEl  = card.querySelector('.bcc-dispute-status');

                        card.querySelectorAll('.js-panel-vote').forEach(b => b.disabled = true);
                        if (statusEl) { statusEl.textContent = 'Submitting…'; statusEl.className = 'bcc-dispute-status'; }

                        apiFetch(`${BASE}/${disputeId}/vote`, {
                            method: 'POST',
                            body: JSON.stringify({ decision }),
                        })
                            .then(res => {
                                const actionsEl = card.querySelector('.bcc-dispute-panel-actions');
                                if (actionsEl) {
                                    actionsEl.outerHTML = `<p class="bcc-dispute-already-voted">You voted: <strong>${escHtml(decision)}</strong> — ${escHtml(res.message)}</p>`;
                                }
                            })
                            .catch(err => {
                                if (statusEl) {
                                    statusEl.textContent = err.message || 'Failed.';
                                    statusEl.className = 'bcc-dispute-status bcc-dispute-status--err';
                                }
                                btn.disabled = false;
                            });
                    });
                });
            })
            .catch(() => {
                listEl.innerHTML = '<p class="bcc-dispute-empty">Could not load panel queue.</p>';
            });
    }

    // ── Report User Modal ──────────────────────────────────────────────────────

    const REPORT_REASONS = [
        { key: 'spam',           label: 'Spam or unsolicited content' },
        { key: 'harassment',     label: 'Harassment or bullying' },
        { key: 'fraud',          label: 'Fraudulent activity or scam' },
        { key: 'misinformation', label: 'False or misleading information' },
        { key: 'inappropriate',  label: 'Inappropriate content' },
        { key: 'impersonation',  label: 'Impersonating another person' },
        { key: 'other',          label: 'Other (please describe below)' },
    ];

    function createReportModal() {
        if (document.getElementById('bcc-report-modal')) return;

        const optionsHtml = REPORT_REASONS.map(r =>
            `<option value="${r.key}">${escHtml(r.label)}</option>`
        ).join('');

        const modal = document.createElement('div');
        modal.id        = 'bcc-report-modal';
        modal.className = 'bcc-report-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'bcc-report-modal-title');
        modal.setAttribute('hidden', '');
        modal.innerHTML = `
            <div class="bcc-report-modal__backdrop"></div>
            <div class="bcc-report-modal__dialog">
                <button class="bcc-report-modal__close" aria-label="Close">✕</button>
                <h2 id="bcc-report-modal-title" class="bcc-report-modal__title">Report User</h2>
                <p class="bcc-report-modal__sub">Reporting: <strong class="bcc-report-modal__username"></strong></p>
                <label class="bcc-dispute-label" for="bcc-report-reason">
                    Reason <span class="bcc-dispute-required">*</span>
                    <select id="bcc-report-reason" class="bcc-report-select">
                        <option value="">— Select a reason —</option>
                        ${optionsHtml}
                    </select>
                </label>
                <label class="bcc-dispute-label" for="bcc-report-detail" id="bcc-report-detail-label">
                    Additional details <span class="bcc-report-modal__optional">(optional)</span>
                    <textarea id="bcc-report-detail" class="bcc-dispute-reason" rows="4"
                              placeholder="Provide any additional context that may help our team…"></textarea>
                </label>
                <div class="bcc-dispute-submit-actions">
                    <button id="bcc-report-submit" class="bcc-dispute-btn bcc-dispute-btn--danger">Submit Report</button>
                    <button id="bcc-report-cancel" class="bcc-dispute-btn bcc-dispute-btn--secondary">Cancel</button>
                </div>
                <p class="bcc-dispute-status" id="bcc-report-status" aria-live="polite"></p>
            </div>
        `;
        document.body.appendChild(modal);

        const reasonSelect  = modal.querySelector('#bcc-report-reason');
        const detailLabel   = modal.querySelector('#bcc-report-detail-label');
        const detailOptional = modal.querySelector('.bcc-report-modal__optional');
        const detailRequired = document.createElement('span');
        detailRequired.className = 'bcc-dispute-required';
        detailRequired.textContent = ' *';

        // Toggle "required" label on detail field when "other" is selected.
        reasonSelect.addEventListener('change', () => {
            if (reasonSelect.value === 'other') {
                detailOptional.hidden = true;
                detailLabel.appendChild(detailRequired);
            } else {
                detailOptional.hidden = false;
                if (detailRequired.parentNode) detailRequired.parentNode.removeChild(detailRequired);
            }
        });

        // Close handlers.
        function closeModal() {
            modal.setAttribute('hidden', '');
            reasonSelect.value = '';
            modal.querySelector('#bcc-report-detail').value = '';
            modal.querySelector('#bcc-report-status').textContent = '';
            modal.querySelector('#bcc-report-status').className = 'bcc-dispute-status';
            modal.querySelector('#bcc-report-submit').disabled = false;
            detailOptional.hidden = false;
            if (detailRequired.parentNode) detailRequired.parentNode.removeChild(detailRequired);
        }
        modal.querySelector('.bcc-report-modal__backdrop').addEventListener('click', closeModal);
        modal.querySelector('.bcc-report-modal__close').addEventListener('click', closeModal);
        modal.querySelector('#bcc-report-cancel').addEventListener('click', closeModal);
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hasAttribute('hidden')) closeModal(); });

        // Submit handler.
        modal.querySelector('#bcc-report-submit').addEventListener('click', () => {
            const statusEl   = modal.querySelector('#bcc-report-status');
            const submitBtn  = modal.querySelector('#bcc-report-submit');
            const reasonKey  = reasonSelect.value;
            const detail     = modal.querySelector('#bcc-report-detail').value.trim();
            const userId     = parseInt(modal.dataset.targetUserId, 10);

            if (!reasonKey) {
                statusEl.textContent = 'Please select a reason.';
                statusEl.className = 'bcc-dispute-status bcc-dispute-status--err';
                return;
            }
            var minDetailLen = (typeof bccDisputes !== 'undefined' && bccDisputes.minDetailLength) ? parseInt(bccDisputes.minDetailLength, 10) : 10;
            if (reasonKey === 'other' && detail.length < minDetailLen) {
                statusEl.textContent = 'Please provide at least ' + minDetailLen + ' characters describing the issue.';
                statusEl.className = 'bcc-dispute-status bcc-dispute-status--err';
                return;
            }

            submitBtn.disabled = true;
            statusEl.textContent = 'Submitting…';
            statusEl.className = 'bcc-dispute-status';

            apiFetch(REPORT_USER_URL, {
                method: 'POST',
                body: JSON.stringify({ reported_user_id: userId, reason_key: reasonKey, reason_detail: detail }),
            })
                .then(res => {
                    statusEl.textContent = res.message;
                    statusEl.className = 'bcc-dispute-status bcc-dispute-status--ok';
                    setTimeout(closeModal, 2500);
                })
                .catch(err => {
                    statusEl.textContent = err.message || 'Submission failed. Please try again.';
                    statusEl.className = 'bcc-dispute-status bcc-dispute-status--err';
                    submitBtn.disabled = false;
                });
        });

        return modal;
    }

    function initReportButtons() {
        const modal = createReportModal();
        if (!modal) return;

        document.addEventListener('click', e => {
            const btn = e.target.closest('.bcc-report-user-btn');
            if (!btn) return;
            modal.dataset.targetUserId = btn.dataset.userId;
            modal.querySelector('.bcc-report-modal__username').textContent = btn.dataset.userName || 'this user';
            modal.removeAttribute('hidden');
            modal.querySelector('#bcc-report-reason').focus();
        });
    }

    // ── Boot ──────────────────────────────────────────────────────────────────
    function boot() {
        document.querySelectorAll('.bcc-dispute-form').forEach(initDisputeForm);
        document.querySelectorAll('.bcc-dispute-queue').forEach(initDisputeQueue);
        initReportButtons();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
