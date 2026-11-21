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
require_once '../../includes/theme.php';
require_once '../../includes/company_editor.php';   // GH #419: the one company editor
I18n::initFromSession();
Tz::init();

$current_page = 'companies';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.companies.title'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=29">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=97">
    <style>
        /* System module accent (blue-grey) — pin the generic --accent so shared
           components (inbox.css, header) pick up the module colour. */
        body {
            /* System is the FIRST module whose DARK accent is a LIGHT colour (#90a4ae).
               inbox.css renders .btn-primary/.add-btn as background:var(--accent) +
               color:var(--on-accent) — and the global --on-accent stays WHITE in dark.
               So pinning --accent alone would put white text on a light button. Pin
               --on-accent too: it flips to near-black in dark. */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        /* flex:1 (not a hardcoded 100vh-48px height) so a taller/wrapping header
           can't push the page off-screen — see the tickets/settings fix (#535). */
        /* 🔴 ...but flex:1 only means something inside a flex column. inbox.css
           makes body 100vh + overflow:hidden and leaves it a block, so the
           container grew to its content and the bottom of the page (public
           email domains) was cut off with no way to scroll to it. */
        body { display: flex; flex-direction: column; }
        .companies-container { flex: 1; min-height: 0; overflow-y: auto; }
        /* Page layout, title and subtitle: assets/css/system-page.css (.sys-page). */

        .settings-card { background: var(--surface, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08)); margin-bottom: 24px; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: var(--font-size-md, 13px); font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover { background: #455a64; }
        .btn-secondary { background: var(--sys-accent-soft, #eceff1); color: #455a64; }
        .btn-secondary:hover { background: #cfd8dc; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Companies table */
        .companies-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .add-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); border: none; border-radius: 6px; font-size: var(--font-size-md, 13px); font-weight: 600; cursor: pointer; }
        .add-btn:hover { background: #455a64; }
        table.companies { width: 100%; border-collapse: collapse; font-size: var(--font-size-md, 13px); }
        table.companies th { text-align: left; color: var(--text-dim, #888); font-weight: 600; font-size: var(--font-size-sm, 12px); padding: 8px 10px; border-bottom: 1px solid var(--border-soft, #eee); }
        table.companies td { padding: 10px; border-bottom: 1px solid var(--border-soft, #f2f2f2); color: var(--text, #444); vertical-align: middle; }
        table.companies tr:last-child td { border-bottom: none; }
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: var(--font-size-xs, 11px); font-weight: 600; }
        .status-badge.on { background: #e8f5e9; color: #2e7d32; }
        .status-badge.off { background: #f0f0f0; color: #999; }
        .badge-default { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: var(--font-size-xs, 11px); background: #e3f2fd; color: #1565c0; margin-left: 8px; }
        .domain-chip { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: var(--font-size-xs, 11px); background: #ede7f6; color: #5e35b1; margin: 2px 4px 2px 0; }

        /* Public email domains card */
        .card-title { font-size: var(--font-size-xl, 16px); font-weight: 600; color: var(--text, #333); margin: 0 0 6px 0; }
        .card-hint { font-size: var(--font-size-md, 13px); color: var(--text-dim, #888); margin: 0 0 16px 0; line-height: 1.5; }
        .freemail-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 6px 4px 10px; border-radius: 14px; font-size: var(--font-size-sm, 12px); background: var(--sys-accent-soft, #eceff1); color: #37474f; margin: 2px 6px 2px 0; }
        .freemail-chip button { background: none; border: none; cursor: pointer; color: #90a4ae; font-size: var(--font-size-lg, 14px); line-height: 1; padding: 0 2px; border-radius: 50%; }
        .freemail-chip button:hover { color: #c62828; }
        .freemail-none { color: var(--text-faint, #aaa); font-size: var(--font-size-sm, 12px); font-style: italic; }
        .builtin-freemail { margin-top: 16px; }
        .builtin-toggle { background: none; border: none; padding: 0; cursor: pointer; color: #607d8b; font-size: var(--font-size-sm, 12px); font-weight: 600; }
        .builtin-toggle:hover { text-decoration: underline; }
        #builtinFreemailList { margin-top: 8px; }
        .builtin-chip { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: var(--font-size-xs, 11px); background: var(--surface-3, #f5f5f5); color: var(--text-faint, #999); margin: 2px 4px 2px 0; }
        .domains-none { color: var(--text-faint, #bbb); }
        #freemailInput { background: var(--surface, #fff); color: var(--text, #333); }
        .table-action-btn { background: none; border: none; cursor: pointer; color: #607d8b; padding: 4px 8px; font-size: var(--font-size-md, 13px); border-radius: 4px; }
        .table-action-btn:hover { background: var(--sys-accent-soft, #eceff1); }
        .empty-row td { text-align: center; color: var(--text-faint, #aaa); padding: 24px; font-style: italic; }

        /* SSO help banner */
        .sso-banner { display: flex; align-items: center; gap: 12px; text-decoration: none; background: linear-gradient(135deg, #eef2ff, #e0e7ff); border: 1px solid #c7d2fe; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px; }
        .sso-banner svg { flex-shrink: 0; }
        .sso-banner .sso-text { flex: 1; color: #3730a3; font-size: var(--font-size-lg, 14px); line-height: 1.45; }
        .sso-banner .sso-text strong { display: block; font-size: var(--font-size-lg, 14px); margin-bottom: 1px; }
        .sso-banner .sso-arrow { color: #6366f1; font-weight: 700; font-size: var(--font-size-2xl, 18px); }

        /* ---- Dark mode overrides: pale washes / tinted pills that would glow ---- */
        [data-theme-mode="dark"] .btn-primary:hover,
        [data-theme-mode="dark"] .add-btn:hover { background: var(--sys-accent-hover, #455a64); color: var(--sys-on-accent, #fff); }
        [data-theme-mode="dark"] .btn-secondary { color: var(--text, #eceff1); }
        [data-theme-mode="dark"] .btn-secondary:hover { background: #37474f; }
        [data-theme-mode="dark"] .freemail-chip { color: var(--text, #eceff1); }
        [data-theme-mode="dark"] .builtin-toggle,
        [data-theme-mode="dark"] .table-action-btn { color: var(--sys-accent, #90a4ae); }
        [data-theme-mode="dark"] .status-badge.on { background: #16331f; color: #86efac; }
        [data-theme-mode="dark"] .status-badge.off { background: #2b313a; color: #9aa3af; }
        [data-theme-mode="dark"] .badge-default { background: #1e3a5f; color: #90caf9; }
        [data-theme-mode="dark"] .domain-chip { background: #332a45; color: #c9b6ec; }
        [data-theme-mode="dark"] .freemail-chip button:hover { color: #fca5a5; }
        [data-theme-mode="dark"] .sso-banner { filter: brightness(0.82); }
        .co-search { width: 400px; max-width: 100%; padding: 8px 10px; border: 1px solid var(--border, #d1d5db); border-radius: 6px; font-size: var(--font-size-md, 13px); background: var(--surface, #fff); color: var(--text, #333); }
        .co-ref { display: block; font-size: var(--font-size-sm, 12px); color: var(--text-dim, #888); font-weight: 400; margin-top: 2px; }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="system" data-mobile-page="companies">
    <?php include '../includes/header.php'; ?>

    <div class="companies-container sys-page">
        <div class="sys-page-head"><div>
            <h1 class="sys-title"><?php echo htmlspecialchars(t('system.companies.title')); ?></h1>
            <p class="sys-subtitle"><?php echo htmlspecialchars(t('system.companies.subtitle')); ?></p>
        </div></div>

        <a href="../help/sso.php" class="sso-banner">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 7h3a5 5 0 0 1 5 5 5 5 0 0 1-5 5h-3m-6 0H6a5 5 0 0 1-5-5 5 5 0 0 1 5-5h3"></path><line x1="8" y1="12" x2="16" y2="12"></line></svg>
            <span class="sso-text"><strong>Setting up single sign-on for the self-service portal?</strong>Click here for the step-by-step guide — it covers both single-company and multi-company (MSP) setups.</span>
            <span class="sso-arrow">&rarr;</span>
        </a>

        <div class="settings-card">
            <div class="companies-head">
                <?php /* GH #279: with hundreds of companies the list needs a search - name,
                         outside reference, tax ID, ticket code or email domain. */ ?>
                <input type="search" id="coSearch" class="co-search" autocomplete="off"
                       placeholder="<?php echo htmlspecialchars(t('system.companies.search_placeholder')); ?>"
                       aria-label="<?php echo htmlspecialchars(t('system.companies.search_placeholder')); ?>">
                <button class="add-btn" id="addCompanyBtn"><?php echo htmlspecialchars(t('system.companies.add')); ?></button>
            </div>
            <table class="companies">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('system.companies.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('system.companies.col_domains')); ?></th>
                        <th id="codeColHead" style="display:none;"><?php echo htmlspecialchars(t('system.companies.col_ticket_code')); ?></th>
                        <th><?php echo htmlspecialchars(t('system.companies.col_status')); ?></th>
                        <th style="text-align:right;"><?php echo htmlspecialchars(t('system.companies.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="companiesBody">
                    <tr class="empty-row"><td colspan="4"><?php echo htmlspecialchars(t('system.companies.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Company lists (GH #358): when a company dropdown becomes a search box.
             Only meaningful once there's more than one company. -->
        <div class="settings-card" id="companyListsCard" style="display: none;">
            <h2 id="company-lists" class="card-title"><?php echo htmlspecialchars(t('system.companies.lists_title')); ?></h2>
            <p class="card-hint"><?php echo htmlspecialchars(t('system.companies.lists_hint')); ?></p>
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <label for="listAfterInput"><?php echo htmlspecialchars(t('system.companies.lists_after')); ?></label>
                <input type="number" id="listAfterInput" min="0" max="1000" step="1" style="width: 90px;">
                <button type="button" class="btn btn-primary" id="listAfterSave"><?php echo htmlspecialchars(t('system.companies.lists_save')); ?></button>
                <span id="listAfterMsg" class="card-hint" style="margin: 0;"></span>
            </div>
        </div>

        <!-- Public email domains (global). Only meaningful once there's more
             than one company (shared-intake routing), so hidden at N=1. -->
        <div class="settings-card" id="freemailCard" style="display: none;">
            <h2 id="freemail" class="card-title"><?php echo htmlspecialchars(t('system.companies.freemail_title')); ?></h2>
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

    <?php /* GH #419: Add and Edit open the ONE company editor (includes/company_editor.php),
             shared with the person editor and People - one form, so they cannot drift. */
          companyEditorRender(); ?>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=4"></script>
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
        document.getElementById('companyListsCard').style.display = multi ? '' : 'none';
        if (multi) loadListAfter();
    }

    // ---------- Company lists (GH #358) ----------
    async function loadListAfter() {
        try {
            const d = await (await fetch(API + 'system/company_list_settings.php')).json();
            if (d.success) document.getElementById('listAfterInput').value = d.after;
        } catch (e) { /* the box stays empty; Save still works */ }
    }
    document.getElementById('listAfterSave').addEventListener('click', async function () {
        const msg = document.getElementById('listAfterMsg');
        try {
            const d = await (await fetch(API + 'system/company_list_settings.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ after: document.getElementById('listAfterInput').value })
            })).json();
            msg.textContent = d.success ? window.t('system.companies.lists_saved') : (d.error || '');
        } catch (e) { msg.textContent = e.message; }
    });

    function renderDomainCell(domains) {
        if (!domains || !domains.length) {
            return '<span class="domains-none">' + esc(window.t('system.companies.domains_dash')) + '</span>';
        }
        return domains.map(d => '<span class="domain-chip">' + esc(d) + '</span>').join('');
    }

    /** Whether the ticket code is worth showing at all. One company means
        {COMPANY} has nothing to tell apart, so the column and the field stay out
        of the way entirely. Adding a company counts, because that is the moment
        it starts to matter. */
    function codeRelevant(adding) { return companies.length > 1 || !!adding; }

    /** Companies whose effective code is shared with another — the thing that
        would quietly break per-company numbering. Worked out here as well as on
        the server so the list can mark them without a round trip. */
    function clashingCodes() {
        const seen = {}, clash = {};
        companies.forEach(c => {
            const code = (c.effective_ticket_code || '');
            if (seen[code]) clash[code] = true;
            seen[code] = true;
        });
        return clash;
    }

    function renderCompanies() {
        const body = document.getElementById('companiesBody');
        const showCode = codeRelevant(false);
        document.getElementById('codeColHead').style.display = showCode ? '' : 'none';
        if (!companies.length) {
            body.innerHTML = '<tr class="empty-row"><td colspan="5">' + window.t('system.companies.no_companies', { add: '<strong>' + window.t('system.companies.add_strong') + '</strong>' }) + '</td></tr>';
            return;
        }
        const clash = clashingCodes();
        // GH #279: the search box - name, outside reference, tax ID, ticket code, domains.
        const q = (document.getElementById('coSearch').value || '').trim().toLowerCase();
        const shown = !q ? companies : companies.filter(c => [c.name, c.external_ref, c.tax_id, c.effective_ticket_code].concat(c.domains || [])
            .some(v => v && String(v).toLowerCase().includes(q)));
        if (!shown.length) {
            body.innerHTML = '<tr class="empty-row"><td colspan="5">' + esc(window.t('system.companies.search_none')) + '</td></tr>';
            return;
        }
        body.innerHTML = shown.map(c => {
            const code = c.effective_ticket_code || '';
            // A derived code is shown in a lighter weight than one that was
            // typed, so "FreeITSM guessed this" and "somebody chose this" are
            // not presented as the same fact.
            const derived = !c.ticket_code;
            const cell = !showCode ? '' : `<td>${code
                ? `<code style="font-size:var(--font-size-sm, 12px);${derived ? 'opacity:.65;' : ''}">${esc(code)}</code>`
                  + (clash[code] ? ` <span title="${esc(window.t('system.companies.code_clash'))}" style="color: var(--danger-text, #c0392b);">&#9888;</span>` : '')
                : '<span style="opacity:.5;">&mdash;</span>'}</td>`;
            return `
            <tr>
                <td><strong>${esc(c.name)}</strong>${c.is_default ? '<span class="badge-default">' + window.t('system.companies.default') + '</span>' : ''}
                    ${(c.external_ref || c.tax_id) ? '<span class="co-ref">' + [c.external_ref ? esc(window.t('system.companies.ref_short', { ref: c.external_ref })) : '', c.tax_id ? esc(window.t('system.companies.tax_short', { tax: c.tax_id })) : ''].filter(Boolean).join(' · ') + '</span>' : ''}</td>
                <td>${renderDomainCell(c.domains)}</td>
                ${cell}
                <td><span class="status-badge ${c.is_active ? 'on' : 'off'}">${c.is_active ? window.t('system.companies.active') : window.t('system.companies.inactive')}</span></td>
                <td style="text-align:right;">
                    <button class="table-action-btn" data-edit="${c.id}">${window.t('system.companies.edit')}</button>
                </td>
            </tr>`;
        }).join('');
    }

    // ---------- Add / Edit: the shared company editor (GH #419) ----------
    // Saving, a domain, a sender or a logo all refresh the list behind it.
    const openCompany = c => CompanyEditor.open(c, { onSaved: loadCompanies, onChanged: loadCompanies });
    document.getElementById('addCompanyBtn').addEventListener('click', () => openCompany(null));
    // GH #397: an old link to ?new=1 still opens the editor (People now opens it in place).
    if (new URLSearchParams(location.search).get('new') === '1') openCompany(null);
    document.getElementById('companiesBody').addEventListener('click', function (e) {
        const editId = e.target.getAttribute('data-edit');
        if (editId) openCompany(companies.find(c => c.id == editId));
    });
    document.getElementById('coSearch').addEventListener('input', renderCompanies);

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
    <script src="../../assets/js/mobile.js?v=87"></script>
</body>
</html>
