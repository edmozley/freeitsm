<?php
/**
 * Tickets - Triage queue
 * Inbound email that matched no company (tenant_id IS NULL). File each to a
 * company. Only meaningful on a multi-company install.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'triage';
$path_prefix = '../../';
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('tickets.triage.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .triage-container { flex: 1; overflow-y: auto; padding: 30px 20px; }
        .page-title { font-size: 22px; font-weight: 600; color: #333; margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: #888; margin: 0 0 24px 0; max-width: 760px; }
        .settings-card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); margin-bottom: 24px; }

        .triage-info { display: flex; gap: 12px; align-items: flex-start; background: #eef4f8; border: 1px solid #d5e3ec; border-radius: 8px; padding: 14px 16px; margin-bottom: 20px; max-width: 860px; }
        .triage-info svg { flex-shrink: 0; color: #4a7a96; margin-top: 1px; }
        .triage-info .ti-text { font-size: 12.5px; color: #38596b; line-height: 1.5; }
        .triage-info .ti-text strong { color: #1f3d4d; }
        .triage-info .ti-title { display: block; font-weight: 600; color: #1f3d4d; margin-bottom: 3px; font-size: 13px; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; }
        .btn-primary { background: #546e7a; color: #fff; }
        .btn-primary:hover { background: #455a64; }
        .btn-secondary { background: #eceff1; color: #455a64; }
        .btn-secondary:hover { background: #cfd8dc; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        table.triage { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.triage th { text-align: left; color: #888; font-weight: 600; font-size: 12px; padding: 8px 10px; border-bottom: 1px solid #eee; }
        table.triage td { padding: 10px; border-bottom: 1px solid #f2f2f2; color: #444; vertical-align: middle; }
        table.triage tr:last-child td { border-bottom: none; }
        .sender-name { font-weight: 600; color: #333; }
        .sender-addr { color: #888; font-size: 12px; }
        .domain-chip { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #ede7f6; color: #5e35b1; }
        .domain-chip.freemail { background: #fff3e0; color: #ef6c00; }
        .subject-cell { max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .table-action-btn { background: #546e7a; color: #fff; border: none; cursor: pointer; padding: 6px 14px; font-size: 12px; font-weight: 600; border-radius: 5px; }
        .table-action-btn:hover { background: #455a64; }
        .empty-row td { text-align: center; color: #aaa; padding: 36px; font-style: italic; }

        /* Modal — namespaced (tr-) so it doesn't inherit inbox.css's global .modal. */
        .tr-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 2100; align-items: center; justify-content: center; }
        .tr-modal-overlay.open { display: flex; }
        .tr-modal { background: #fff; border-radius: 10px; width: 480px; max-width: 92vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .tr-modal-header { padding: 20px 24px; border-bottom: 1px solid #eee; font-size: 16px; font-weight: 600; color: #333; }
        .tr-modal-body { padding: 20px 24px; }
        .tr-modal-footer { padding: 16px 24px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; }
        .form-field { margin-bottom: 16px; }
        .form-field label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 4px; }
        .form-field select, .form-field input[type=text] { width: 100%; padding: 9px 11px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; font-family: inherit; box-sizing: border-box; }
        .form-field select:focus, .form-field input:focus { outline: none; border-color: #546e7a; }
        .checkbox-field { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 6px; }
        .checkbox-field input { margin-top: 3px; }
        .checkbox-field .cb-label { font-size: 13px; color: #444; }
        .checkbox-field .cb-label span { display: block; color: #999; font-size: 12px; }
        .triage-email-summary { font-size: 12px; color: #777; background: #f7f8f9; border-radius: 6px; padding: 10px 12px; margin-bottom: 16px; }
        .freemail-note { font-size: 12px; color: #ef6c00; margin-bottom: 12px; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="triage-container">
        <h1 class="page-title"><?php echo htmlspecialchars(t('tickets.triage.title')); ?></h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('tickets.triage.subtitle')); ?></p>

        <div class="triage-info">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            <div class="ti-text">
                <span class="ti-title"><?php echo htmlspecialchars(t('tickets.triage.info_title')); ?></span>
                <?php echo t('tickets.triage.info_body'); /* contains intentional <strong>/<em> markup */ ?>
            </div>
        </div>

        <div class="settings-card">
            <table class="triage">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.triage.col_sender')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.triage.col_domain')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.triage.col_subject')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.triage.col_mailbox')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.triage.col_received')); ?></th>
                        <th style="text-align:right;"><?php echo htmlspecialchars(t('tickets.triage.col_action')); ?></th>
                    </tr>
                </thead>
                <tbody id="triageBody">
                    <tr class="empty-row"><td colspan="6"><?php echo htmlspecialchars(t('tickets.triage.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Resolve modal -->
    <div class="tr-modal-overlay" id="resolveModal">
        <div class="tr-modal">
            <div class="tr-modal-header"><?php echo htmlspecialchars(t('tickets.triage.modal_title')); ?></div>
            <div class="tr-modal-body">
                <div class="triage-email-summary" id="resolveSummary"></div>

                <div class="form-field">
                    <label for="resolveCompany"><?php echo htmlspecialchars(t('tickets.triage.field_company')); ?></label>
                    <select id="resolveCompany"></select>
                </div>

                <div class="form-field" id="newNameField" style="display:none;">
                    <label for="resolveNewName"><?php echo htmlspecialchars(t('tickets.triage.field_new_name')); ?></label>
                    <input type="text" id="resolveNewName" placeholder="<?php echo htmlspecialchars(t('tickets.triage.new_name_placeholder')); ?>">
                </div>

                <div class="checkbox-field" id="mapDomainField">
                    <input type="checkbox" id="resolveMapDomain" checked>
                    <div class="cb-label">
                        <strong id="mapDomainLabel"></strong>
                        <span id="mapDomainHelp"></span>
                    </div>
                </div>
                <div class="checkbox-field" id="mapSenderField" style="display:none;">
                    <input type="checkbox" id="resolveMapSender" checked>
                    <div class="cb-label">
                        <strong id="mapSenderLabel"></strong>
                        <span id="mapSenderHelp"></span>
                    </div>
                </div>
                <div class="freemail-note" id="freemailNote" style="display:none;"></div>
            </div>
            <div class="tr-modal-footer">
                <button class="btn btn-secondary" id="resolveCancel" type="button"><?php echo htmlspecialchars(t('tickets.triage.cancel')); ?></button>
                <button class="btn btn-primary" id="resolveConfirm" type="button"><?php echo htmlspecialchars(t('tickets.triage.confirm')); ?></button>
            </div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js"></script>
    <script>
    const API = '<?php echo $path_prefix; ?>api/';
    let triageTickets = [];
    let companies = [];
    let current = null; // the ticket being resolved

    function esc(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
    function fmtDate(s) { if (!s) return ''; const d = parseUTCDate(s); return (!d || isNaN(d)) ? esc(s) : d.toLocaleString(undefined, tzOpts()); }

    async function loadCompanies() {
        try {
            const r = await fetch(API + 'system/get_tenants.php');
            const d = await r.json();
            companies = d.success ? d.companies : [];
        } catch (e) { companies = []; }
    }

    async function loadTriage() {
        try {
            const r = await fetch(API + 'tickets/get_triage.php');
            const d = await r.json();
            triageTickets = d.success ? d.tickets : [];
        } catch (e) { triageTickets = []; }
        renderTriage();
    }

    function renderTriage() {
        const body = document.getElementById('triageBody');
        if (!triageTickets.length) {
            body.innerHTML = '<tr class="empty-row"><td colspan="6">' + esc(window.t('tickets.triage.empty')) + '</td></tr>';
            return;
        }
        body.innerHTML = triageTickets.map(tk => {
            const domainChip = tk.domain
                ? `<span class="domain-chip ${tk.is_freemail ? 'freemail' : ''}">${esc(tk.domain)}${tk.is_freemail ? ' · ' + esc(window.t('tickets.triage.freemail')) : ''}</span>`
                : '';
            const senderName = tk.from_name ? `<div class="sender-name">${esc(tk.from_name)}</div>` : '';
            return `
                <tr>
                    <td>${senderName}<div class="sender-addr">${esc(tk.from_address)}</div></td>
                    <td>${domainChip}</td>
                    <td class="subject-cell" title="${esc(tk.subject)}"><strong>${esc(tk.ticket_number)}</strong> ${esc(tk.subject)}</td>
                    <td>${esc(tk.mailbox_name)}</td>
                    <td>${fmtDate(tk.received)}</td>
                    <td style="text-align:right;"><button class="table-action-btn" data-resolve="${tk.ticket_id}">${esc(window.t('tickets.triage.resolve'))}</button></td>
                </tr>`;
        }).join('');
    }

    // ---------- Resolve modal ----------
    const modal = document.getElementById('resolveModal');

    function buildCompanyOptions() {
        const sel = document.getElementById('resolveCompany');
        let html = '';
        companies.filter(c => c.is_active).forEach(c => {
            html += `<option value="${c.id}">${esc(c.name)}</option>`;
        });
        html += `<option value="__new__">${esc(window.t('tickets.triage.company_create'))}</option>`;
        sel.innerHTML = html;
    }

    function syncModalControls() {
        const isNew = document.getElementById('resolveCompany').value === '__new__';
        document.getElementById('newNameField').style.display = isNew ? '' : 'none';
        // Map-domain only makes sense for a real (non-freemail) domain. When the
        // sender is on a public provider (or has no usable domain), offer to map
        // the exact address instead — the freemail-safe alternative.
        const canMapDomain = !!(current && current.domain && !current.is_freemail);
        const canMapSender = !!(current && current.from_address && !canMapDomain);
        document.getElementById('mapDomainField').style.display = canMapDomain ? '' : 'none';
        document.getElementById('mapSenderField').style.display = canMapSender ? '' : 'none';
        const freemailNote = document.getElementById('freemailNote');
        if (current && current.domain && current.is_freemail) {
            freemailNote.style.display = '';
            freemailNote.textContent = window.t('tickets.triage.freemail_note', { domain: current.domain });
        } else {
            freemailNote.style.display = 'none';
        }
    }

    function openResolve(ticketId) {
        current = triageTickets.find(t => t.ticket_id == ticketId);
        if (!current) return;
        document.getElementById('resolveSummary').innerHTML =
            `<strong>${esc(current.ticket_number)}</strong> — ${esc(current.subject)}<br>${esc(current.from_address)}`;
        buildCompanyOptions();
        document.getElementById('resolveNewName').value = '';
        document.getElementById('resolveMapDomain').checked = true;
        document.getElementById('mapDomainLabel').textContent = window.t('tickets.triage.map_domain_label', { domain: current.domain || '' });
        document.getElementById('mapDomainHelp').textContent = window.t('tickets.triage.map_domain_help', { domain: current.domain || '' });
        document.getElementById('resolveMapSender').checked = true;
        document.getElementById('mapSenderLabel').textContent = window.t('tickets.triage.map_sender_label', { address: current.from_address || '' });
        document.getElementById('mapSenderHelp').textContent = window.t('tickets.triage.map_sender_help', { address: current.from_address || '' });
        syncModalControls();
        modal.classList.add('open');
    }
    function closeResolve() { modal.classList.remove('open'); current = null; }

    document.getElementById('resolveCompany').addEventListener('change', syncModalControls);
    document.getElementById('resolveCancel').addEventListener('click', closeResolve);
    modal.addEventListener('click', e => { if (e.target === modal) closeResolve(); });
    document.getElementById('triageBody').addEventListener('click', e => {
        const id = e.target.getAttribute('data-resolve');
        if (id) openResolve(id);
    });

    document.getElementById('resolveConfirm').addEventListener('click', async function () {
        if (!current) return;
        const sel = document.getElementById('resolveCompany').value;
        const isNew = sel === '__new__';
        const canMapDomain = !!(current.domain && !current.is_freemail);
        const canMapSender = !!(current.from_address && !canMapDomain);
        const payload = {
            ticket_id: current.ticket_id,
            tenant_id: isNew ? 0 : parseInt(sel, 10),
            new_company_name: isNew ? document.getElementById('resolveNewName').value.trim() : '',
            domain: current.domain || '',
            map_domain: canMapDomain ? document.getElementById('resolveMapDomain').checked : false,
            from_address: current.from_address || '',
            map_sender: canMapSender ? document.getElementById('resolveMapSender').checked : false
        };
        if (isNew && !payload.new_company_name) {
            showToast(window.t('tickets.triage.choose_company'), 'error');
            return;
        }
        this.disabled = true;
        try {
            const r = await fetch(API + 'tickets/resolve_triage.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const d = await r.json();
            if (d.success) {
                const companyName = isNew ? payload.new_company_name : (companies.find(c => c.id == payload.tenant_id) || {}).name;
                const msg = d.assigned_count > 1
                    ? window.t('tickets.triage.filed_many', { count: d.assigned_count, company: companyName })
                    : window.t('tickets.triage.filed_one', { company: companyName });
                showToast(msg, 'success');
                closeResolve();
                await loadCompanies(); // a new company may now exist
                loadTriage();
            } else {
                showToast(d.error || window.t('tickets.triage.file_failed'), 'error');
            }
        } catch (e) { showToast(window.t('tickets.triage.file_failed'), 'error'); }
        this.disabled = false;
    });

    (async function init() {
        await loadCompanies();
        loadTriage();
    })();
    </script>
</body>
</html>
