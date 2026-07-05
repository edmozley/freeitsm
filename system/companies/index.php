<?php
/**
 * System - Companies
 * List / create / edit companies (the user-facing word for tenants). On a
 * single-company install this just shows the one "Default" company.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'companies';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.companies.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* flex:1 (not a hardcoded 100vh-48px height) so a taller/wrapping header
           can't push the page off-screen — see the tickets/settings fix (#535). */
        .companies-container { flex: 1; overflow-y: auto; padding: 30px 20px; }
        .page-title { font-size: 22px; font-weight: 600; color: #333; margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: #888; margin: 0 0 30px 0; }

        .settings-card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); margin-bottom: 24px; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; }
        .btn-primary { background: #546e7a; color: #fff; }
        .btn-primary:hover { background: #455a64; }
        .btn-secondary { background: #eceff1; color: #455a64; }
        .btn-secondary:hover { background: #cfd8dc; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Companies table */
        .companies-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .add-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #546e7a; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .add-btn:hover { background: #455a64; }
        table.companies { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.companies th { text-align: left; color: #888; font-weight: 600; font-size: 12px; padding: 8px 10px; border-bottom: 1px solid #eee; }
        table.companies td { padding: 10px; border-bottom: 1px solid #f2f2f2; color: #444; vertical-align: middle; }
        table.companies tr:last-child td { border-bottom: none; }
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status-badge.on { background: #e8f5e9; color: #2e7d32; }
        .status-badge.off { background: #f0f0f0; color: #999; }
        .badge-default { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #e3f2fd; color: #1565c0; margin-left: 8px; }
        .domain-chip { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #ede7f6; color: #5e35b1; margin: 2px 4px 2px 0; }

        /* Public email domains card */
        .card-title { font-size: 16px; font-weight: 600; color: #333; margin: 0 0 6px 0; }
        .card-hint { font-size: 13px; color: #888; margin: 0 0 16px 0; line-height: 1.5; }
        .freemail-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 6px 4px 10px; border-radius: 14px; font-size: 12px; background: #eceff1; color: #37474f; margin: 2px 6px 2px 0; }
        .freemail-chip button { background: none; border: none; cursor: pointer; color: #90a4ae; font-size: 14px; line-height: 1; padding: 0 2px; border-radius: 50%; }
        .freemail-chip button:hover { color: #c62828; }
        .freemail-none { color: #aaa; font-size: 12px; font-style: italic; }
        .builtin-freemail { margin-top: 16px; }
        .builtin-toggle { background: none; border: none; padding: 0; cursor: pointer; color: #607d8b; font-size: 12px; font-weight: 600; }
        .builtin-toggle:hover { text-decoration: underline; }
        #builtinFreemailList { margin-top: 8px; }
        .builtin-chip { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #f5f5f5; color: #999; margin: 2px 4px 2px 0; }
        .domains-none { color: #bbb; }
        .table-action-btn { background: none; border: none; cursor: pointer; color: #607d8b; padding: 4px 8px; font-size: 13px; border-radius: 4px; }
        .table-action-btn:hover { background: #eceff1; }
        .empty-row td { text-align: center; color: #aaa; padding: 24px; font-style: italic; }

        /* Modal — namespaced (co-) so it doesn't inherit inbox.css's global .modal
           framework, whose .modal rule sets opacity:0/visibility:hidden by default. */
        .co-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 2100; align-items: center; justify-content: center; }
        .co-modal-overlay.open { display: flex; }
        .co-modal { background: #fff; border-radius: 10px; width: 480px; max-width: 92vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .co-modal-header { padding: 20px 24px; border-bottom: 1px solid #eee; font-size: 16px; font-weight: 600; color: #333; }
        .co-modal-body { padding: 20px 24px; }
        .co-modal-footer { padding: 16px 24px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; }
        .form-field { margin-bottom: 16px; }
        .form-field label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 4px; }
        .form-field .hint { font-size: 12px; color: #999; font-weight: 400; margin-bottom: 6px; }
        .form-field input[type=text] { width: 100%; padding: 9px 11px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; font-family: inherit; box-sizing: border-box; }
        .form-field input:focus { outline: none; border-color: #546e7a; }
        .checkbox-field { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 14px; }
        .checkbox-field input { margin-top: 3px; }
        .checkbox-field .cb-label { font-size: 13px; color: #444; }
        .checkbox-field .cb-label strong { display: block; }
        .checkbox-field .cb-label span { color: #999; font-size: 12px; }

        /* "How email reaches this company" — derived routing summary panel */
        .routing-panel { background: #f7f9fa; border: 1px solid #e3e8ea; border-radius: 6px; padding: 12px 14px; }
        .routing-path { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; border-bottom: 1px solid #eceff1; }
        .routing-path:last-child { border-bottom: none; }
        .routing-path .rp-icon { flex: 0 0 auto; font-size: 15px; line-height: 1.3; }
        .routing-path .rp-body { flex: 1; min-width: 0; }
        .routing-path .rp-kind { font-size: 12px; font-weight: 600; color: #455a64; }
        .routing-path .rp-kind .rp-flag { font-weight: 600; color: #c62828; font-size: 11px; margin-left: 6px; }
        .routing-path .rp-desc { font-size: 12px; color: #666; margin-top: 2px; }
        .routing-path .rp-desc strong { color: #444; font-weight: 600; }
        .routing-note { font-size: 12px; color: #777; margin-top: 10px; font-style: italic; }
        .routing-warn { display: flex; gap: 8px; font-size: 12px; color: #8a5a00; background: #fff8e1; border: 1px solid #ffe0a3; border-radius: 5px; padding: 8px 10px; margin-top: 8px; }
        .routing-empty { font-size: 12px; color: #aaa; font-style: italic; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="companies-container">
        <h1 class="page-title"><?php echo htmlspecialchars(t('system.companies.title')); ?></h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('system.companies.subtitle')); ?></p>

        <a href="../help/sso.php" style="display:flex;align-items:center;gap:12px;text-decoration:none;background:linear-gradient(135deg,#eef2ff,#e0e7ff);border:1px solid #c7d2fe;border-radius:8px;padding:14px 18px;margin-bottom:20px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M15 7h3a5 5 0 0 1 5 5 5 5 0 0 1-5 5h-3m-6 0H6a5 5 0 0 1-5-5 5 5 0 0 1 5-5h3"></path><line x1="8" y1="12" x2="16" y2="12"></line></svg>
            <span style="flex:1;color:#3730a3;font-size:13.5px;line-height:1.45;"><strong style="display:block;font-size:14px;margin-bottom:1px;">Setting up single sign-on for the self-service portal?</strong>Click here for the step-by-step guide — it covers both single-company and multi-company (MSP) setups.</span>
            <span style="color:#6366f1;font-weight:700;font-size:18px;">&rarr;</span>
        </a>

        <div class="settings-card">
            <div class="companies-head">
                <div></div>
                <button class="add-btn" id="addCompanyBtn"><?php echo htmlspecialchars(t('system.companies.add')); ?></button>
            </div>
            <table class="companies">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('system.companies.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('system.companies.col_domains')); ?></th>
                        <th><?php echo htmlspecialchars(t('system.companies.col_status')); ?></th>
                        <th style="text-align:right;"><?php echo htmlspecialchars(t('system.companies.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="companiesBody">
                    <tr class="empty-row"><td colspan="4"><?php echo htmlspecialchars(t('system.companies.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Public email domains (global). Only meaningful once there's more
             than one company (shared-intake routing), so hidden at N=1. -->
        <div class="settings-card" id="freemailCard" style="display: none;">
            <h2 class="card-title"><?php echo htmlspecialchars(t('system.companies.freemail_title')); ?></h2>
            <p class="card-hint"><?php echo htmlspecialchars(t('system.companies.freemail_hint')); ?></p>

            <div id="customFreemailList"></div>
            <div style="display: flex; gap: 8px; margin-top: 8px; max-width: 420px;">
                <input type="text" id="freemailInput" placeholder="<?php echo htmlspecialchars(t('system.companies.freemail_placeholder')); ?>" style="flex: 1;">
                <button type="button" class="btn btn-secondary" id="addFreemailBtn"><?php echo htmlspecialchars(t('system.companies.freemail_add')); ?></button>
            </div>

            <div class="builtin-freemail">
                <button type="button" class="builtin-toggle" id="builtinToggle"><span id="builtinToggleText"></span></button>
                <div id="builtinFreemailList" style="display: none;"></div>
            </div>
        </div>
    </div>

    <!-- Add/Edit modal -->
    <div class="co-modal-overlay" id="companyModal">
        <div class="co-modal">
            <div class="co-modal-header" id="modalTitle"><?php echo htmlspecialchars(t('system.companies.modal_add_title')); ?></div>
            <div class="co-modal-body">
                <input type="hidden" id="companyId">
                <div class="form-field">
                    <label><?php echo htmlspecialchars(t('system.companies.field_name')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.companies.field_name_hint')); ?></div>
                    <input type="text" id="fName" placeholder="<?php echo htmlspecialchars(t('system.companies.field_name_placeholder')); ?>">
                </div>
                <div class="checkbox-field">
                    <input type="checkbox" id="fActive" checked>
                    <div class="cb-label"><strong><?php echo htmlspecialchars(t('system.companies.cb_active')); ?></strong><span><?php echo htmlspecialchars(t('system.companies.cb_active_desc')); ?></span></div>
                </div>

                <!-- Email domains (shared-intake routing). Shown only when editing an
                     existing company on a multi-company install (populated by JS). -->
                <div class="form-field" id="domainsSection" style="display: none;">
                    <label><?php echo htmlspecialchars(t('system.companies.domains_label')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.companies.domains_hint')); ?></div>
                    <div id="domainsList"></div>
                    <div style="display: flex; gap: 8px; margin-top: 8px;">
                        <input type="text" id="domainInput" placeholder="<?php echo htmlspecialchars(t('system.companies.domain_placeholder')); ?>" style="flex: 1;">
                        <button type="button" class="btn btn-secondary" id="addDomainBtn"><?php echo htmlspecialchars(t('system.companies.domain_add')); ?></button>
                    </div>
                </div>

                <!-- Specific senders (shared-intake routing, address-level). Lets a
                     personal/freemail address route to this company even though its
                     domain can't be mapped. Same visibility as the domains section. -->
                <div class="form-field" id="sendersSection" style="display: none;">
                    <label><?php echo htmlspecialchars(t('system.companies.senders_label')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.companies.senders_hint')); ?></div>
                    <div id="sendersList"></div>
                    <div style="display: flex; gap: 8px; margin-top: 8px;">
                        <input type="text" id="senderInput" placeholder="<?php echo htmlspecialchars(t('system.companies.sender_placeholder')); ?>" style="flex: 1;">
                        <button type="button" class="btn btn-secondary" id="addSenderBtn"><?php echo htmlspecialchars(t('system.companies.sender_add')); ?></button>
                    </div>
                </div>

                <!-- Derived, read-only "How email reaches this company" summary.
                     Same visibility as the domains section (multi-company edit). -->
                <div class="form-field" id="routingSection" style="display: none;">
                    <label><?php echo htmlspecialchars(t('system.companies.routing_label')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.companies.routing_hint')); ?></div>
                    <div class="routing-panel" id="routingPanel"></div>
                </div>
            </div>
            <div class="co-modal-footer">
                <button class="btn btn-secondary" id="cancelModalBtn" type="button"><?php echo htmlspecialchars(t('system.companies.cancel')); ?></button>
                <button class="btn btn-primary" id="saveCompanyBtn" type="button"><?php echo htmlspecialchars(t('system.companies.save')); ?></button>
            </div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js"></script>
    <script>
    const API = '<?php echo $path_prefix; ?>api/';
    let companies = [];
    let freemailLoaded = false;

    function esc(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    // ---------- Companies list ----------
    async function loadCompanies() {
        try {
            const r = await fetch(API + 'system/get_tenants.php');
            const d = await r.json();
            companies = d.success ? d.companies : [];
        } catch (e) { companies = []; }
        renderCompanies();
        // Public email domains only matter for shared-intake routing, i.e. once
        // a second company exists — keep the card invisible at N=1.
        const multi = companies.length > 1;
        document.getElementById('freemailCard').style.display = multi ? '' : 'none';
        if (multi && !freemailLoaded) { freemailLoaded = true; loadFreemail(); }
    }

    function renderDomainCell(domains) {
        if (!domains || !domains.length) {
            return '<span class="domains-none">' + esc(window.t('system.companies.domains_dash')) + '</span>';
        }
        return domains.map(d => '<span class="domain-chip">' + esc(d) + '</span>').join('');
    }

    function renderCompanies() {
        const body = document.getElementById('companiesBody');
        if (!companies.length) {
            body.innerHTML = '<tr class="empty-row"><td colspan="4">' + window.t('system.companies.no_companies', { add: '<strong>' + window.t('system.companies.add_strong') + '</strong>' }) + '</td></tr>';
            return;
        }
        body.innerHTML = companies.map(c => `
            <tr>
                <td><strong>${esc(c.name)}</strong>${c.is_default ? '<span class="badge-default">' + window.t('system.companies.default') + '</span>' : ''}</td>
                <td>${renderDomainCell(c.domains)}</td>
                <td><span class="status-badge ${c.is_active ? 'on' : 'off'}">${c.is_active ? window.t('system.companies.active') : window.t('system.companies.inactive')}</span></td>
                <td style="text-align:right;">
                    <button class="table-action-btn" data-edit="${c.id}">${window.t('system.companies.edit')}</button>
                </td>
            </tr>`).join('');
    }

    // ---------- Modal ----------
    const modal = document.getElementById('companyModal');
    function openModal(c) {
        document.getElementById('modalTitle').textContent = c ? window.t('system.companies.modal_edit_title') : window.t('system.companies.modal_add_title');
        document.getElementById('companyId').value = c ? c.id : '';
        document.getElementById('fName').value = c ? c.name : '';
        const active = document.getElementById('fActive');
        active.checked = c ? !!c.is_active : true;
        // The default company is always active and can't be deactivated.
        active.disabled = !!(c && c.is_default);

        // Email domains: only when editing an existing company on a multi-company
        // install (shared-intake routing is meaningless with a single company).
        const domainsSection = document.getElementById('domainsSection');
        const sendersSection = document.getElementById('sendersSection');
        const routingSection = document.getElementById('routingSection');
        document.getElementById('domainInput').value = '';
        document.getElementById('senderInput').value = '';
        if (c && c.id && companies.length > 1) {
            domainsSection.style.display = '';
            sendersSection.style.display = '';
            routingSection.style.display = '';
            loadDomains(c.id);
            loadSenders(c.id);
            loadRouting(c.id);
        } else {
            domainsSection.style.display = 'none';
            sendersSection.style.display = 'none';
            routingSection.style.display = 'none';
            document.getElementById('domainsList').innerHTML = '';
            document.getElementById('sendersList').innerHTML = '';
            document.getElementById('routingPanel').innerHTML = '';
        }

        modal.classList.add('open');
        document.getElementById('fName').focus();
    }
    function closeModal() { modal.classList.remove('open'); }

    // ---------- Company email domains ----------
    let currentDomains = [];
    async function loadDomains(tenantId) {
        document.getElementById('domainsList').innerHTML = '';
        try {
            const r = await fetch(API + 'system/get_tenant_domains.php?tenant_id=' + tenantId);
            const d = await r.json();
            currentDomains = d.success ? d.domains : [];
        } catch (e) { currentDomains = []; }
        renderDomains();
    }
    function renderDomains() {
        const list = document.getElementById('domainsList');
        if (!currentDomains.length) {
            list.innerHTML = '<div style="color:#aaa; font-size:12px; font-style:italic; padding:6px 0;">' + esc(window.t('system.companies.domains_none')) + '</div>';
            return;
        }
        list.innerHTML = currentDomains.map(d => `
            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; border:1px solid #eee; border-radius:5px; margin-bottom:6px; font-size:13px;">
                <span>${esc(d.domain)}</span>
                <button type="button" class="table-action-btn" data-remove-domain="${d.id}">${esc(window.t('system.companies.domain_remove'))}</button>
            </div>`).join('');
    }
    async function addDomain() {
        const tenantId = document.getElementById('companyId').value;
        const input = document.getElementById('domainInput');
        const domain = input.value.trim();
        if (!tenantId || !domain) return;
        const btn = document.getElementById('addDomainBtn');
        btn.disabled = true;
        try {
            const r = await fetch(API + 'system/add_tenant_domain.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tenant_id: tenantId, domain })
            });
            const d = await r.json();
            if (d.success) {
                input.value = '';
                showToast(window.t('system.companies.domain_added'), 'success');
                loadDomains(tenantId);
                loadRouting(tenantId); // domains drive shared-intake routing
                loadCompanies(); // keep the list's domain chips in sync
            } else {
                showToast(d.error || window.t('system.companies.domain_add_failed'), 'error');
            }
        } catch (e) { showToast(window.t('system.companies.domain_add_failed'), 'error'); }
        btn.disabled = false;
    }
    async function removeDomain(id) {
        try {
            const r = await fetch(API + 'system/delete_tenant_domain.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const d = await r.json();
            if (d.success) {
                showToast(window.t('system.companies.domain_removed'), 'success');
                loadDomains(document.getElementById('companyId').value);
                loadRouting(document.getElementById('companyId').value); // domains drive routing
                loadCompanies(); // keep the list's domain chips in sync
            } else {
                showToast(d.error || window.t('system.companies.domain_remove_failed'), 'error');
            }
        } catch (e) { showToast(window.t('system.companies.domain_remove_failed'), 'error'); }
    }
    // ---------- Company specific senders (address-level routing) ----------
    let currentSenders = [];
    async function loadSenders(tenantId) {
        document.getElementById('sendersList').innerHTML = '';
        try {
            const r = await fetch(API + 'system/get_tenant_senders.php?tenant_id=' + tenantId);
            const d = await r.json();
            currentSenders = d.success ? d.senders : [];
        } catch (e) { currentSenders = []; }
        renderSenders();
    }
    function renderSenders() {
        const list = document.getElementById('sendersList');
        if (!currentSenders.length) {
            list.innerHTML = '<div style="color:#aaa; font-size:12px; font-style:italic; padding:6px 0;">' + esc(window.t('system.companies.senders_none')) + '</div>';
            return;
        }
        list.innerHTML = currentSenders.map(s => `
            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; border:1px solid #eee; border-radius:5px; margin-bottom:6px; font-size:13px;">
                <span>${esc(s.email)}</span>
                <button type="button" class="table-action-btn" data-remove-sender="${s.id}">${esc(window.t('system.companies.sender_remove'))}</button>
            </div>`).join('');
    }
    async function addSender() {
        const tenantId = document.getElementById('companyId').value;
        const input = document.getElementById('senderInput');
        const email = input.value.trim();
        if (!tenantId || !email) return;
        const btn = document.getElementById('addSenderBtn');
        btn.disabled = true;
        try {
            const r = await fetch(API + 'system/add_tenant_sender.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tenant_id: tenantId, email })
            });
            const d = await r.json();
            if (d.success) {
                input.value = '';
                showToast(window.t('system.companies.sender_added'), 'success');
                loadSenders(tenantId);
                loadRouting(tenantId); // senders drive shared-intake routing
            } else {
                showToast(d.error || window.t('system.companies.sender_add_failed'), 'error');
            }
        } catch (e) { showToast(window.t('system.companies.sender_add_failed'), 'error'); }
        btn.disabled = false;
    }
    async function removeSender(id) {
        try {
            const r = await fetch(API + 'system/delete_tenant_sender.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const d = await r.json();
            if (d.success) {
                showToast(window.t('system.companies.sender_removed'), 'success');
                loadSenders(document.getElementById('companyId').value);
                loadRouting(document.getElementById('companyId').value); // senders drive routing
            } else {
                showToast(d.error || window.t('system.companies.sender_remove_failed'), 'error');
            }
        } catch (e) { showToast(window.t('system.companies.sender_remove_failed'), 'error'); }
    }

    // ---------- How email reaches this company (derived, read-only) ----------
    async function loadRouting(tenantId) {
        const panel = document.getElementById('routingPanel');
        panel.innerHTML = '<div class="routing-empty">' + esc(window.t('system.companies.routing_loading')) + '</div>';
        let data;
        try {
            const r = await fetch(API + 'system/get_tenant_email_routing.php?tenant_id=' + tenantId);
            data = await r.json();
        } catch (e) { data = null; }
        if (!data || !data.success) {
            panel.innerHTML = '<div class="routing-empty">' + esc(window.t('system.companies.routing_failed')) + '</div>';
            return;
        }
        renderRouting(panel, data);
    }

    function routingFlags(p) {
        const flags = [];
        if (!p.is_active) flags.push(window.t('system.companies.routing_inactive'));
        if (!p.authenticated) flags.push(window.t('system.companies.routing_unauth'));
        return flags.length ? ' <span class="rp-flag">(' + flags.map(esc).join(', ') + ')</span>' : '';
    }

    function renderRouting(panel, data) {
        let html = '';
        (data.paths || []).forEach(p => {
            const addr = '<strong>' + esc(p.address || p.name) + '</strong>';
            if (p.type === 'pinned') {
                html += `<div class="routing-path">
                    <span class="rp-icon">📌</span>
                    <div class="rp-body">
                        <div class="rp-kind">${esc(window.t('system.companies.routing_pinned'))}${routingFlags(p)}</div>
                        <div class="rp-desc">${window.t('system.companies.routing_pinned_desc', { address: addr })}</div>
                    </div>
                </div>`;
            } else {
                const domainList = p.matched_domains || [];
                const senderList = p.matched_senders || [];
                const domains = domainList.map(d => '<strong>' + esc(d) + '</strong>').join(', ');
                const senders = senderList.map(s => '<strong>' + esc(s) + '</strong>').join(', ');
                let desc;
                if (domainList.length && senderList.length) {
                    desc = window.t('system.companies.routing_shared_desc_both', { address: addr, domains: domains, senders: senders });
                } else if (senderList.length) {
                    desc = window.t('system.companies.routing_shared_desc_senders', { address: addr, senders: senders });
                } else {
                    desc = window.t('system.companies.routing_shared_desc', { address: addr, domains: domains });
                }
                html += `<div class="routing-path">
                    <span class="rp-icon">📥</span>
                    <div class="rp-body">
                        <div class="rp-kind">${esc(window.t('system.companies.routing_shared'))}${routingFlags(p)}</div>
                        <div class="rp-desc">${desc}</div>
                    </div>
                </div>`;
            }
        });
        if (!html) {
            html = '<div class="routing-empty">' + esc(window.t('system.companies.routing_warn_no_route')) + '</div>';
        }
        (data.warnings || []).forEach(w => {
            const key = { no_route: 'routing_warn_no_route', domains_no_shared: 'routing_warn_domains_no_shared', unauthenticated: 'routing_warn_unauth' }[w];
            // no_route already shown as the empty state above.
            if (key && !(w === 'no_route' && !(data.paths && data.paths.length))) {
                html += '<div class="routing-warn"><span>⚠️</span><span>' + esc(window.t('system.companies.' + key)) + '</span></div>';
            }
        });
        if (data.catches_unrouted) {
            html += '<div class="routing-note">' + esc(window.t('system.companies.routing_default_note')) + '</div>';
        }
        panel.innerHTML = html;
    }

    document.getElementById('addDomainBtn').addEventListener('click', addDomain);
    document.getElementById('domainInput').addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); addDomain(); }
    });
    document.getElementById('domainsList').addEventListener('click', e => {
        const rm = e.target.getAttribute('data-remove-domain');
        if (rm) removeDomain(parseInt(rm, 10));
    });

    document.getElementById('addSenderBtn').addEventListener('click', addSender);
    document.getElementById('senderInput').addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); addSender(); }
    });
    document.getElementById('sendersList').addEventListener('click', e => {
        const rm = e.target.getAttribute('data-remove-sender');
        if (rm) removeSender(parseInt(rm, 10));
    });

    document.getElementById('addCompanyBtn').addEventListener('click', () => openModal(null));
    document.getElementById('cancelModalBtn').addEventListener('click', closeModal);
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

    document.getElementById('companiesBody').addEventListener('click', function (e) {
        const editId = e.target.getAttribute('data-edit');
        if (editId) openModal(companies.find(c => c.id == editId));
    });

    // ---------- Save company ----------
    document.getElementById('saveCompanyBtn').addEventListener('click', async function () {
        const payload = {
            id: document.getElementById('companyId').value || 0,
            name: document.getElementById('fName').value.trim(),
            is_active: document.getElementById('fActive').checked ? 1 : 0
        };
        if (!payload.name) {
            showToast(window.t('system.companies.required_name'), 'error');
            return;
        }
        this.disabled = true;
        try {
            const r = await fetch(API + 'system/save_tenant.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const d = await r.json();
            if (d.success) {
                showToast(window.t('system.companies.company_saved'), 'success');
                closeModal();
                loadCompanies();
            } else {
                showToast(window.t('system.companies.error', { error: d.error }), 'error');
            }
        } catch (e) { showToast(window.t('system.companies.save_failed'), 'error'); }
        this.disabled = false;
    });

    // ---------- Public email domains (global, add-only) ----------
    let customFreemail = [];
    async function loadFreemail() {
        let data;
        try {
            const r = await fetch(API + 'system/get_freemail_domains.php');
            data = await r.json();
        } catch (e) { data = null; }
        if (!data || !data.success) { customFreemail = []; renderFreemail([], []); return; }
        customFreemail = data.custom || [];
        renderFreemail(customFreemail, data.builtin || []);
    }
    function renderFreemail(custom, builtin) {
        const list = document.getElementById('customFreemailList');
        if (!custom.length) {
            list.innerHTML = '<div class="freemail-none">' + esc(window.t('system.companies.freemail_none')) + '</div>';
        } else {
            list.innerHTML = custom.map(d => `
                <span class="freemail-chip">${esc(d.domain)}
                    <button type="button" data-remove-freemail="${d.id}" title="${esc(window.t('system.companies.freemail_remove'))}">&times;</button>
                </span>`).join('');
        }
        // Built-in list (toggle).
        const toggle = document.getElementById('builtinToggleText');
        const box = document.getElementById('builtinFreemailList');
        toggle.textContent = window.t('system.companies.freemail_builtin_toggle', { count: builtin.length });
        box.innerHTML = builtin.map(d => '<span class="builtin-chip">' + esc(d) + '</span>').join('');
    }
    async function addFreemail() {
        const input = document.getElementById('freemailInput');
        const domain = input.value.trim();
        if (!domain) return;
        const btn = document.getElementById('addFreemailBtn');
        btn.disabled = true;
        try {
            const r = await fetch(API + 'system/add_freemail_domain.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ domain })
            });
            const d = await r.json();
            if (d.success) {
                input.value = '';
                showToast(window.t('system.companies.freemail_added'), 'success');
                loadFreemail();
            } else {
                showToast(d.error || window.t('system.companies.freemail_add_failed'), 'error');
            }
        } catch (e) { showToast(window.t('system.companies.freemail_add_failed'), 'error'); }
        btn.disabled = false;
    }
    async function removeFreemail(id) {
        try {
            const r = await fetch(API + 'system/delete_freemail_domain.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const d = await r.json();
            if (d.success) {
                showToast(window.t('system.companies.freemail_removed'), 'success');
                loadFreemail();
            } else {
                showToast(d.error || window.t('system.companies.freemail_remove_failed'), 'error');
            }
        } catch (e) { showToast(window.t('system.companies.freemail_remove_failed'), 'error'); }
    }
    document.getElementById('addFreemailBtn').addEventListener('click', addFreemail);
    document.getElementById('freemailInput').addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); addFreemail(); }
    });
    document.getElementById('customFreemailList').addEventListener('click', e => {
        const rm = e.target.getAttribute('data-remove-freemail');
        if (rm) removeFreemail(parseInt(rm, 10));
    });
    document.getElementById('builtinToggle').addEventListener('click', () => {
        const box = document.getElementById('builtinFreemailList');
        box.style.display = box.style.display === 'none' ? '' : 'none';
    });

    loadCompanies();
    </script>
</body>
</html>
