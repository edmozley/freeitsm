<?php
/**
 * Catalogue-request approvals inbox (#928).
 *
 * Where a designated approver signs off (or turns down) the catalogue requests waiting
 * on them. Approving raises the ticket; rejecting records the decision. Modelled on the
 * Change Management approvals inbox, scoped to the Forms module.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}
requireModuleAccess('forms');

$current_page = 'approvals';
$path_prefix = '../';
$translationNamespaces = ['common', 'forms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('forms.approval.inbox_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/forms.css">
    <style>
        .ca-container { display: flex; height: calc(100vh - 48px); background: var(--app-bg, #f5f5f5); }
        .ca-sidebar { width: 260px; background: var(--surface, #fff); border-right: 1px solid var(--border, #ddd); padding: 20px; display: flex; flex-direction: column; gap: 6px; }
        .ca-sidebar h3 { font-size: 12px; font-weight: 600; color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 10px; }
        .ca-filter { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-radius: 6px; cursor: pointer; font-size: 14px; color: var(--text, #333); transition: background 0.15s; }
        .ca-filter:hover { background: var(--surface-hover, #f5f5f5); }
        .ca-filter.active { background: var(--accent-soft, #e8f4fd); color: var(--accent, #0078d4); font-weight: 600; }
        .ca-filter .cnt { font-size: 12px; background: var(--border-soft, #eee); color: var(--text-muted, #666); padding: 2px 8px; border-radius: 10px; font-weight: 600; min-width: 20px; text-align: center; }
        .ca-filter.active .cnt { background: var(--accent, #0078d4); color: var(--on-accent, #fff); }
        .ca-main { flex: 1; overflow-y: auto; padding: 24px 30px; }
        .ca-main h2 { margin: 0 0 20px; font-size: 20px; color: var(--text, #333); }
        .ca-card { background: var(--surface, #fff); border-radius: 8px; padding: 16px 20px; margin-bottom: 12px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.06)); border-left: 4px solid var(--warning-border, #f0d9a8); }
        .ca-card-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; gap: 12px; }
        .ca-card-title { font-size: 15px; font-weight: 600; color: var(--text, #333); }
        .ca-card-meta { display: flex; flex-wrap: wrap; gap: 16px; font-size: 12px; color: var(--text-dim, #888); margin-bottom: 12px; }
        .ca-card-meta .lbl { font-weight: 600; color: var(--text-faint, #999); }
        .ca-answers { border: 1px solid var(--border-soft, #eee); border-radius: 6px; background: var(--surface-2, #fafafa); padding: 4px 0; margin-bottom: 12px; }
        .ca-answer { display: flex; gap: 12px; padding: 6px 12px; font-size: 12.5px; }
        .ca-answer .a-lbl { font-weight: 600; color: var(--text-muted, #666); flex: 0 0 40%; }
        .ca-answer .a-val { color: var(--text, #333); white-space: pre-wrap; }
        .ca-comment { width: 100%; padding: 8px 10px; border: 1px solid var(--border, #ddd); border-radius: 5px; font-size: 13px; font-family: inherit; background: var(--surface, #fff); color: var(--text, #333); margin-bottom: 10px; resize: vertical; min-height: 38px; }
        .ca-actions { display: flex; gap: 10px; }
        .ca-btn { padding: 8px 16px; border: none; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: 500; }
        .ca-btn-approve { background: var(--success-accent, #16a34a); color: #fff; }
        .ca-btn-reject { background: var(--surface, #fff); color: var(--danger-accent, #d13438); border: 1px solid var(--danger-accent, #d13438); }
        .ca-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .ca-badge { font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 10px; }
        .ca-badge.approved { background: var(--success-bg, #dcfce7); color: var(--success-text, #166534); }
        .ca-badge.rejected { background: var(--danger-bg, #fee2e2); color: var(--danger-text, #991b1b); }
        .ca-decided-note { font-size: 12.5px; color: var(--text-muted, #666); }
        .ca-decided-note a { color: var(--accent, #0078d4); }
        .ca-empty { text-align: center; padding: 60px 20px; color: var(--text-faint, #999); }
        .ca-empty h3 { font-size: 16px; color: var(--text-muted, #666); margin: 0 0 6px; }
        .ca-empty p { font-size: 13px; margin: 0; }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="ca-container">
        <div class="ca-sidebar">
            <h3><?php echo htmlspecialchars(t('forms.approval.filter')); ?></h3>
            <div class="ca-filter active" data-filter="mine" onclick="setFilter('mine')">
                <span><?php echo htmlspecialchars(t('forms.approval.filter_mine')); ?></span>
                <span class="cnt" id="cntMine">0</span>
            </div>
            <div class="ca-filter" data-filter="all" onclick="setFilter('all')">
                <span><?php echo htmlspecialchars(t('forms.approval.filter_all')); ?></span>
                <span class="cnt" id="cntAll">0</span>
            </div>
            <div class="ca-filter" data-filter="decided" onclick="setFilter('decided')">
                <span><?php echo htmlspecialchars(t('forms.approval.filter_decided')); ?></span>
                <span class="cnt" id="cntDecided">0</span>
            </div>
        </div>

        <div class="ca-main">
            <h2 id="caTitle"><?php echo htmlspecialchars(t('forms.approval.inbox_heading')); ?></h2>
            <div id="caList"><div class="ca-empty"><p><?php echo htmlspecialchars(t('forms.approval.loading')); ?></p></div></div>
        </div>
    </div>

    <script>
        // showToast is provided by the shared header (renderWaffleMenuJS loads toast.js).
        const API_BASE = '../api/forms/';
        let currentFilter = 'mine';

        document.addEventListener('DOMContentLoaded', loadApprovals);

        function setFilter(f) {
            currentFilter = f;
            document.querySelectorAll('.ca-filter').forEach(el => el.classList.toggle('active', el.dataset.filter === f));
            loadApprovals();
        }

        async function loadApprovals() {
            const list = document.getElementById('caList');
            list.innerHTML = '<div class="ca-empty"><p>' + esc(window.t('forms.approval.loading')) + '</p></div>';
            try {
                const res = await fetch(API_BASE + 'catalogue_approvals.php?filter=' + currentFilter);
                const data = await res.json();
                if (!data.success) {
                    list.innerHTML = '<div class="ca-empty"><p>' + esc(data.error || 'Error') + '</p></div>';
                    return;
                }
                document.getElementById('cntMine').textContent = data.counts.mine;
                document.getElementById('cntAll').textContent = data.counts.all;
                document.getElementById('cntDecided').textContent = data.counts.decided;
                renderApprovals(data.items);
            } catch (e) {
                list.innerHTML = '<div class="ca-empty"><p>Error</p></div>';
            }
        }

        function renderApprovals(items) {
            const list = document.getElementById('caList');
            if (!items.length) {
                list.innerHTML = `<div class="ca-empty"><h3>${esc(window.t('forms.approval.empty_heading'))}</h3><p>${esc(window.t('forms.approval.empty_text'))}</p></div>`;
                return;
            }
            list.innerHTML = items.map(it => {
                const requester = it.requester_name || it.requester_username || window.t('forms.approval.unknown_requester');
                const answers = (it.answers || []).map(a =>
                    `<div class="ca-answer"><span class="a-lbl">${esc(a.label)}</span><span class="a-val">${esc(a.value)}</span></div>`).join('')
                    || `<div class="ca-answer"><span class="a-val">${esc(window.t('forms.approval.no_answers'))}</span></div>`;

                const decided = it.approval_status === 'approved' || it.approval_status === 'rejected';
                let footer;
                if (decided) {
                    const badge = `<span class="ca-badge ${it.approval_status}">${esc(window.t('forms.approval.status_' + it.approval_status))}</span>`;
                    const ticket = (it.approval_status === 'approved' && it.ticket_number)
                        ? ` — <a href="../tickets/?ticket=${encodeURIComponent(it.ticket_number)}">${esc(it.ticket_number)}</a>` : '';
                    const cmt = it.approval_comment ? `<div class="ca-decided-note">${esc(it.approval_comment)}</div>` : '';
                    footer = `<div class="ca-decided-note">${badge}${ticket}</div>${cmt}`;
                } else {
                    footer = `
                        <textarea class="ca-comment" id="cmt-${it.id}" placeholder="${escAttr(window.t('forms.approval.comment_placeholder'))}"></textarea>
                        <div class="ca-actions">
                            <button class="ca-btn ca-btn-approve" onclick="decide(${it.id}, 'approved', this)">${esc(window.t('forms.approval.approve'))}</button>
                            <button class="ca-btn ca-btn-reject" onclick="decide(${it.id}, 'rejected', this)">${esc(window.t('forms.approval.reject'))}</button>
                        </div>`;
                }

                return `
                    <div class="ca-card">
                        <div class="ca-card-top"><span class="ca-card-title">${esc(it.form_title)}</span></div>
                        <div class="ca-card-meta">
                            <span><span class="lbl">${esc(window.t('forms.approval.requester'))}</span> ${esc(requester)}</span>
                            ${it.submitted_date ? `<span><span class="lbl">${esc(window.t('forms.approval.submitted'))}</span> ${esc(formatDate(it.submitted_date))}</span>` : ''}
                            ${(currentFilter !== 'mine' && it.approver_name) ? `<span><span class="lbl">${esc(window.t('forms.approval.approver'))}</span> ${esc(it.approver_name)}</span>` : ''}
                        </div>
                        <div class="ca-answers">${answers}</div>
                        ${footer}
                    </div>`;
            }).join('');
        }

        async function decide(id, decision, btn) {
            const card = btn.closest('.ca-card');
            card.querySelectorAll('button').forEach(b => b.disabled = true);
            const comment = (document.getElementById('cmt-' + id) || {}).value || '';
            try {
                const res = await fetch(API_BASE + 'decide_catalogue_approval.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ submission_id: id, decision: decision, comment: comment })
                });
                const data = await res.json();
                if (!data.success) {
                    showToast(data.error || window.t('forms.approval.decide_failed'), 'error');
                    card.querySelectorAll('button').forEach(b => b.disabled = false);
                    return;
                }
                if (decision === 'approved' && data.ticket_number) {
                    showToast(window.t('forms.approval.approved_toast', { ref: data.ticket_number }), 'success');
                } else {
                    showToast(window.t('forms.approval.rejected_toast'), 'success');
                }
                loadApprovals();
            } catch (e) {
                showToast(window.t('forms.approval.decide_failed'), 'error');
                card.querySelectorAll('button').forEach(b => b.disabled = false);
            }
        }

        // Submitted = server UTC timestamp → analyst zone.
        function formatDate(dateStr) {
            if (!dateStr) return '';
            const d = parseUTCDate(dateStr);
            if (!d || isNaN(d.getTime())) return dateStr;
            return d.toLocaleString(undefined, tzOpts());
        }

        function esc(t) { const d = document.createElement('div'); d.textContent = (t == null ? '' : t); return d.innerHTML; }
        function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    </script>
</body>
</html>
