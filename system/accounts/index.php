<?php
/**
 * System — Chart of Accounts (GH #282, slice 1).
 *
 * The finance department's account codes, per company: code, name, KIND (expense,
 * fixed asset, payroll, income), description, parent (the hierarchy) and
 * active/inactive. Added by hand, imported from a spreadsheet, or kept in step
 * through the REST API (/accounts/sync) - all through AccountsService.
 *
 * A copy of System -> Cost Centres on purpose (the two lists behave identically for
 * the people who keep them), with the kind added: a column, a filter and a field.
 * Projects name accounts on budget lines and tasks from slice 2 on.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
$current_page = 'accounts';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
requireModuleAccess('system');
$acT = fn(string $k) => t('system.accounts.' . $k);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', $acT('title'))); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
            display: flex; flex-direction: column;
        }
        /* Full width, like the other System settings pages (Ed). flex:1 inside a
           flex column, so the page scrolls rather than being cut off. */
        .ac-container { flex: 1; min-height: 0; overflow-y: auto; }
        /* Page layout, title and subtitle: assets/css/system-page.css (.sys-page). */
        .ac-verify { margin-bottom: 18px; padding: 12px 14px; border-radius: 8px; font-size: 13px;
            background: var(--warning-bg, #fff4ce); color: var(--warning-text, #6b5900); border: 1px solid var(--warning-border, #f2d675); }
        .ac-panel { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px; padding: 18px 20px; }

        .ac-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 14px; }
        .ac-toolbar .ac-spacer { flex: 1; }
        .ac-toolbar select, .ac-toolbar input[type=search] { padding: 8px 10px; border: 1px solid var(--border, #cfd8dc); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #333); }
        .ac-toolbar input[type=search] { width: 260px; max-width: 100%; }
        .ac-check { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-muted, #555); cursor: pointer; }
        .ac-summary { font-size: 12.5px; color: var(--text-dim, #888); margin: -4px 0 10px; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover { background: var(--sys-accent-hover, #455a64); }
        .btn-secondary { background: var(--surface-2, #eceff1); color: var(--text, #333); }
        .btn-secondary:hover { background: var(--border, #cfd8dc); }
        .btn:disabled { opacity: .5; cursor: default; }

        .ac-export { position: relative; }
        .ac-export-menu { position: absolute; right: 0; top: calc(100% + 4px); z-index: 20; min-width: 190px; background: var(--surface, #fff);
            border: 1px solid var(--border, #ddd); border-radius: 8px; box-shadow: 0 6px 20px var(--shadow, rgba(0,0,0,.12)); padding: 4px; }
        .ac-export-menu a { display: block; padding: 8px 12px; border-radius: 5px; font-size: 13px; color: var(--text, #333); text-decoration: none; }
        .ac-export-menu a:hover { background: var(--surface-2, #eceff1); }
        .ac-export-menu small { display: block; color: var(--text-dim, #888); font-size: 11.5px; }
        .ac-export-menu { max-height: 70vh; overflow-y: auto; }
        .ac-export-company { padding: 8px 12px 2px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-dim, #888); }
        .ac-export-company:not(:first-child) { border-top: 1px solid var(--border-soft, #eee); margin-top: 4px; padding-top: 10px; }
        .ac-company { white-space: nowrap; color: var(--text-muted, #666); }

        .ac-table-wrap { overflow-x: auto; }
        .ac-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .ac-table th { text-align: left; font-size: 12px; color: var(--text-muted, #888); font-weight: 600; padding: 8px 10px; border-bottom: 1px solid var(--border-soft, #eee); white-space: nowrap; }
        .ac-table td { padding: 9px 10px; border-bottom: 1px solid var(--border-soft, #f2f2f2); color: var(--text, #444); vertical-align: top; }
        .ac-table tr.is-inactive td { color: var(--text-faint, #9aa3ab); }
        .ac-table tr.is-context td { opacity: .55; }
        .ac-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; white-space: nowrap; }
        .ac-tree { display: inline-block; width: 14px; color: var(--text-faint, #b0bec5); }
        .ac-desc { color: var(--text-muted, #777); max-width: 420px; }
        .ac-badge { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .ac-badge.on { background: #e8f5e9; color: #2e7d32; }
        .ac-badge.off { background: #f0f0f0; color: #888; }
        .ac-kind { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .ac-kind.expense     { background: #fff3e0; color: #b45309; }
        .ac-kind.fixed_asset { background: #e3f2fd; color: #1565c0; }
        .ac-kind.payroll     { background: #f3e5f5; color: #7b1fa2; }
        .ac-kind.income      { background: #e8f5e9; color: #2e7d32; }
        .ac-actions { text-align: right; white-space: nowrap; }
        /* Edit / Delete as icons, the same buttons as Tickets -> Settings. Scoped,
           because inbox.css's bare .action-btn is a big toolbar button. */
        .ac-actions .action-btn { background: none; border: 1px solid var(--border, #ddd); color: var(--text-muted, #666); cursor: pointer; padding: 6px; margin-left: 4px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; }
        .ac-actions .action-btn:hover { background: var(--surface-hover, #f0f0f0); border-color: var(--accent, #0078d4); color: var(--accent, #0078d4); }
        .ac-actions .action-btn.delete { color: var(--danger-accent, #d13438); }
        .ac-actions .action-btn.delete:hover { background: var(--danger-bg, #fdf3f3); border-color: var(--danger-accent, #d13438); color: var(--danger-text, #a00); }
        .ac-actions .action-btn svg { width: 16px; height: 16px; }
        .ac-empty { padding: 28px 10px; text-align: center; color: var(--text-faint, #999); font-size: 13px; }

        /* Modals - namespaced so inbox.css's global .modal rules stay out of it. */
        .ac-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 2100; align-items: center; justify-content: center; }
        .ac-overlay.open { display: flex; }
        /* The dialog box, head, body and foot are the shared .sys-modal (system-page.css). */
        /* TRAP: any author `display` on .btn beats the browser's own [hidden] rule, so the
           import footer's hidden Back / Import buttons showed on step 1. Hidden means hidden. */
        .ac-modal-foot .btn[hidden] { display: none; }
        .ac-field { margin-bottom: 14px; }
        .ac-field label { display: block; font-size: 13px; font-weight: 600; color: var(--text, #444); margin-bottom: 4px; }
        .ac-field .hint { font-size: 12px; color: var(--text-dim, #888); margin-top: 4px; line-height: 1.45; }
        .ac-field input[type=text], .ac-field textarea, .ac-field select { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--border, #ddd); border-radius: 6px; font-size: 13px; font-family: inherit; background: var(--surface, #fff); color: var(--text, #333); }
        .ac-field textarea { min-height: 70px; resize: vertical; }
        .ac-field input:focus, .ac-field textarea:focus, .ac-field select:focus { outline: none; border-color: var(--sys-accent, #546e7a); }
        .ac-field input#acCode { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        /* Toggles use inbox.css's .toggle-label / .toggle-switch (caption above, switch below). */
        .ac-toggle { margin-bottom: 4px; }
        .ac-toggle > span { font-weight: 600; color: var(--text, #444); }
        .ac-toggle > span small { display: block; font-weight: 400; color: var(--text-dim, #888); font-size: 12px; margin-top: 2px; line-height: 1.45; }
        .ac-note { font-size: 12.5px; line-height: 1.55; color: var(--text-muted, #666); background: var(--surface-2, #f5f7f8); border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; }
        .ac-note code { font-size: 12px; }

        /* Import preview */
        .ac-counts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .ac-count { padding: 6px 12px; border-radius: 6px; font-size: 13px; background: var(--surface-2, #eceff1); color: var(--text, #333); }
        .ac-count strong { font-size: 15px; margin-right: 4px; }
        .ac-errors { border: 1px solid #f5c2c0; background: #fdecea; color: #7d1f16; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; font-size: 12.5px; }
        .ac-errors ul { margin: 6px 0 0; padding-left: 18px; }
        .ac-errors li { margin-bottom: 3px; }
        .ac-act { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; white-space: nowrap; }
        .ac-act.create { background: #e3f2fd; color: #1565c0; }
        .ac-act.update { background: #fff3e0; color: #b45309; }
        .ac-act.unchanged { background: #f0f0f0; color: #888; }
        .ac-act.deactivate { background: #fdecea; color: #b71c1c; }
        .ac-done { color: #2e7d32; font-weight: 600; font-size: 13px; margin-bottom: 10px; }

        [data-theme-mode="dark"] .ac-badge.on, [data-theme-mode="dark"] .ac-act.create { background: #16331f; color: #86efac; }
        [data-theme-mode="dark"] .ac-act.create { background: #1e3a5f; color: #90caf9; }
        [data-theme-mode="dark"] .ac-badge.off, [data-theme-mode="dark"] .ac-act.unchanged { background: #2b313a; color: #9aa3af; }
        [data-theme-mode="dark"] .ac-act.update { background: #3a2e12; color: #fcd34d; }
        [data-theme-mode="dark"] .ac-act.deactivate, [data-theme-mode="dark"] .ac-errors { background: #3b1512; color: #f3c7c2; border-color: #7d2e26; }
        [data-theme-mode="dark"] .ac-done { color: #86efac; }
        [data-theme-mode="dark"] .ac-kind.expense     { background: #3a2e12; color: #fcd34d; }
        [data-theme-mode="dark"] .ac-kind.fixed_asset { background: #1e3a5f; color: #90caf9; }
        [data-theme-mode="dark"] .ac-kind.payroll     { background: #3b1f45; color: #e1bee7; }
        [data-theme-mode="dark"] .ac-kind.income      { background: #16331f; color: #86efac; }
        @media (max-width: 700px) {
            .ac-desc { display: none; }
            .ac-toolbar input[type=search] { width: 100%; }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="system" data-mobile-page="accounts">
    <?php include '../includes/header.php'; ?>
    <div class="ac-container sys-page">
        <div class="sys-page-head"><div>
            <h1 class="sys-title"><?php echo htmlspecialchars($acT('title')); ?></h1>
            <p class="sys-subtitle"><?php echo htmlspecialchars($acT('intro')); ?></p>
        </div></div>

        <div class="ac-verify" id="acVerify" hidden><?php echo htmlspecialchars($acT('needs_verify')); ?></div>

        <div class="ac-panel" id="acPanel">
            <div class="ac-toolbar">
                <input type="search" id="acSearch" placeholder="<?php echo htmlspecialchars($acT('search')); ?>" aria-label="<?php echo htmlspecialchars($acT('search')); ?>">
                <select id="acKindFilter" aria-label="<?php echo htmlspecialchars($acT('filter_kind')); ?>">
                    <option value=""><?php echo htmlspecialchars($acT('kind_all')); ?></option>
                    <?php foreach (['expense', 'fixed_asset', 'payroll', 'income'] as $k): ?><option value="<?php echo $k; ?>"><?php echo htmlspecialchars($acT('kind_' . $k)); ?></option><?php endforeach; ?>
                </select>
                <label class="ac-check"><input type="checkbox" id="acShowInactive" checked> <?php echo htmlspecialchars($acT('show_inactive')); ?></label>
                <span class="ac-spacer"></span>
                <button type="button" class="btn btn-secondary" id="acImportBtn"><?php echo htmlspecialchars($acT('import')); ?></button>
                <div class="ac-export">
                    <button type="button" class="btn btn-secondary" id="acExportBtn" aria-haspopup="true" aria-expanded="false"><?php echo htmlspecialchars($acT('export')); ?></button>
                    <div class="ac-export-menu" id="acExportMenu" hidden>
                        <a href="#" data-format="xlsx"><?php echo htmlspecialchars($acT('export_xlsx')); ?><small><?php echo htmlspecialchars($acT('export_xlsx_hint')); ?></small></a>
                        <a href="#" data-format="csv"><?php echo htmlspecialchars($acT('export_csv')); ?><small><?php echo htmlspecialchars($acT('export_csv_hint')); ?></small></a>
                    </div>
                </div>
                <button type="button" class="btn btn-primary" id="acAddBtn"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <div class="ac-summary" id="acSummary"></div>
            <div class="ac-table-wrap">
                <table class="ac-table">
                    <thead><tr>
                        <th id="acColCompany" hidden><?php echo htmlspecialchars($acT('company')); ?></th>
                        <th><?php echo htmlspecialchars($acT('col_code')); ?></th>
                        <th><?php echo htmlspecialchars($acT('col_name')); ?></th>
                        <th><?php echo htmlspecialchars($acT('col_kind')); ?></th>
                        <th class="ac-desc"><?php echo htmlspecialchars($acT('col_description')); ?></th>
                        <th><?php echo htmlspecialchars($acT('col_status')); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody id="acBody"><tr><td colspan="6" class="ac-empty"><?php echo htmlspecialchars($acT('loading')); ?></td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add / edit -->
    <div class="ac-overlay" id="acEdit">
        <div class="ac-modal sys-modal" role="dialog" aria-modal="true" aria-labelledby="acEditTitle">
            <div class="ac-modal-head sys-modal-head" id="acEditTitle"></div>
            <div class="ac-modal-body sys-modal-body">
                <div class="ac-field" id="acCompanyField" hidden>
                    <label for="acCompany"><?php echo htmlspecialchars($acT('company')); ?></label>
                    <select id="acCompany"></select>
                </div>
                <div class="ac-field">
                    <label for="acCode"><?php echo htmlspecialchars($acT('field_code')); ?></label>
                    <input type="text" id="acCode" maxlength="50" autocomplete="off" spellcheck="false">
                    <div class="hint"><?php echo htmlspecialchars($acT('field_code_hint')); ?></div>
                </div>
                <div class="ac-field">
                    <label for="acName"><?php echo htmlspecialchars($acT('field_name')); ?></label>
                    <input type="text" id="acName" maxlength="150" autocomplete="off">
                </div>
                <div class="ac-field">
                    <label for="acKind"><?php echo htmlspecialchars($acT('field_kind')); ?></label>
                    <select id="acKind">
                        <?php foreach (['expense', 'fixed_asset', 'payroll', 'income'] as $k): ?><option value="<?php echo $k; ?>"><?php echo htmlspecialchars($acT('kind_' . $k)); ?></option><?php endforeach; ?>
                    </select>
                    <div class="hint"><?php echo htmlspecialchars($acT('field_kind_hint')); ?></div>
                </div>
                <div class="ac-field">
                    <label for="acDesc"><?php echo htmlspecialchars($acT('field_description')); ?></label>
                    <textarea id="acDesc" maxlength="500"></textarea>
                </div>
                <div class="ac-field">
                    <label for="acParent"><?php echo htmlspecialchars($acT('field_parent')); ?></label>
                    <select id="acParent"></select>
                    <div class="hint"><?php echo htmlspecialchars($acT('field_parent_hint')); ?></div>
                </div>
                <div class="toggle-label ac-toggle">
                    <span><?php echo htmlspecialchars($acT('field_active')); ?><small><?php echo htmlspecialchars($acT('field_active_hint')); ?></small></span>
                    <label class="toggle-switch"><input type="checkbox" id="acActive"><span class="toggle-slider"></span></label>
                </div>
            </div>
            <div class="ac-modal-foot sys-modal-foot">
                <button type="button" class="btn btn-secondary" data-close><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="acSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Import -->
    <div class="ac-overlay" id="acImport">
        <div class="ac-modal wide sys-modal sys-modal-wide" role="dialog" aria-modal="true" aria-labelledby="acImportTitle">
            <div class="ac-modal-head sys-modal-head" id="acImportTitle"><?php echo htmlspecialchars($acT('import_title')); ?></div>
            <div class="ac-modal-body sys-modal-body">
                <div id="acImportStep1">
                    <div class="ac-note"><?php echo $acT('import_help_html'); ?></div>
                    <div class="ac-note"><?php echo $acT('import_zeros_html'); ?></div>
                    <div class="ac-field" id="acImportCompanyField" hidden>
                        <label for="acImportCompany"><?php echo htmlspecialchars($acT('company')); ?></label>
                        <select id="acImportCompany"></select>
                    </div>
                    <div class="ac-field">
                        <label for="acFile"><?php echo htmlspecialchars($acT('import_file')); ?></label>
                        <input type="file" id="acFile" accept=".xlsx,.csv,.txt">
                    </div>
                    <div class="toggle-label ac-toggle">
                        <span><?php echo htmlspecialchars($acT('import_deactivate')); ?><small><?php echo htmlspecialchars($acT('import_deactivate_hint')); ?></small></span>
                        <label class="toggle-switch"><input type="checkbox" id="acDeactivate"><span class="toggle-slider"></span></label>
                    </div>
                </div>
                <div id="acImportStep2" hidden></div>
            </div>
            <div class="ac-modal-foot sys-modal-foot">
                <button type="button" class="btn btn-secondary" id="acImportBack" hidden><?php echo htmlspecialchars($acT('back')); ?></button>
                <button type="button" class="btn btn-secondary" data-close id="acImportClose"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="acPreview"><?php echo htmlspecialchars($acT('preview')); ?></button>
                <button type="button" class="btn btn-primary" id="acApply" hidden><?php echo htmlspecialchars($acT('import')); ?></button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/confirm.js?v=7"></script>
    <script>
    (function () {
        const API = '../../api/system/accounts.php';
        const $ = id => document.getElementById(id);
        const T = (k, p) => t('system.accounts.' + k, p);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
        const toast = (m, k) => { if (typeof showToast === 'function') showToast(m, k); };

        let companyId = 0;       // the company a NEW account goes into
        let allMode = false;     // header switcher on "All companies": every company's list, with a Company column
        let companies = [];
        let items = [];          // the accounts, flat
        let byId = new Map();
        let editingId = 0;
        // The pencil and bin from Tickets -> Settings, so the two screens match.
        const ICON_EDIT = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        const ICON_DELETE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

        // ── Loading ────────────────────────────────────────────────────────
        async function load() {
            const url = API + '?action=list' + (companyId ? '&company_id=' + companyId : '');
            try {
                const d = await (await fetch(url, { credentials: 'same-origin' })).json();
                if (!d.success) throw new Error(d.error || '');
                // The company is the one chosen in the header's company switcher,
                // which reloads the page on a change - so no picker of our own.
                companyId = d.company_id;
                allMode = !!d.all;
                companies = d.companies || [];
                $('acColCompany').hidden = !allMode;
                paintExportMenu();
                $('acVerify').hidden = d.ready;
                $('acPanel').hidden = !d.ready;
                items = d.accounts;
                byId = new Map(items.map(i => [i.id, i]));
                render();
            } catch (e) {
                $('acBody').innerHTML = '<tr><td colspan="' + cols() + '" class="ac-empty">' + esc(T('load_failed', { error: String(e.message || e) })) + '</td></tr>';
            }
        }
        const cols = () => allMode ? 7 : 6;
        const kindLabel = k => T('kind_' + k);
        const companyOptions = selected => companies.map(c =>
            '<option value="' + c.id + '"' + (c.id === selected ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('');


        // ── The tree ───────────────────────────────────────────────────────
        // Parents first, children under them, each level by code. An account
        // whose parent is missing (it cannot be, but be safe) shows at the top.
        function treeOrder(list) {
            const kids = new Map();
            const ids = new Set(list.map(i => i.id));
            list.forEach(i => {
                const p = i.parent_id && ids.has(i.parent_id) ? i.parent_id : 0;
                if (!kids.has(p)) kids.set(p, []);
                kids.get(p).push(i);
            });
            // Company first, so "All companies" reads as one block per company.
            const cmp = (a, b) => (a.company_name || '').localeCompare(b.company_name || '', undefined, { sensitivity: 'base' })
                || a.code.localeCompare(b.code, undefined, { sensitivity: 'base' });
            const out = [];
            const walk = (p, depth, guard) => {
                (kids.get(p) || []).sort(cmp).forEach(i => {
                    if (guard.has(i.id)) return;
                    out.push({ item: i, depth });
                    walk(i.id, depth + 1, new Set(guard).add(i.id));
                });
            };
            walk(0, 0, new Set());
            return out;
        }

        function render() {
            const q = $('acSearch').value.trim().toLowerCase();
            const showInactive = $('acShowInactive').checked;
            const inactive = items.filter(i => !i.is_active).length;
            $('acSummary').textContent = items.length
                ? T(items.length === 1 ? 'summary_one' : 'summary', { count: items.length }) + (inactive ? ' · ' + T('summary_inactive', { count: inactive }) : '')
                : '';

            if (!items.length) {
                $('acBody').innerHTML = '<tr><td colspan="' + cols() + '" class="ac-empty">' + esc(T('empty')) + '</td></tr>';
                return;
            }

            // Searching keeps each match's parents on screen, faded, so the
            // match is still seen in its place in the tree.
            const kind = $('acKindFilter').value;
            let visible = items.filter(i => (showInactive || i.is_active) && (!kind || i.kind === kind));
            let matches = null;
            if (q) {
                matches = new Set(visible.filter(i => (i.code + ' ' + i.name + ' ' + kindLabel(i.kind) + ' ' + (i.description || '') + (allMode ? ' ' + i.company_name : '')).toLowerCase().includes(q)).map(i => i.id));
                const keep = new Set(matches);
                matches.forEach(id => { for (let p = byId.get(id).parent_id, n = 0; p && n < 1000; p = (byId.get(p) || {}).parent_id, n++) keep.add(p); });
                visible = items.filter(i => keep.has(i.id));
            }
            const rows = treeOrder(visible);
            if (!rows.length) {
                $('acBody').innerHTML = '<tr><td colspan="' + cols() + '" class="ac-empty">' + esc(T('none_match')) + '</td></tr>';
                return;
            }
            $('acBody').innerHTML = rows.map(({ item: i, depth }) => {
                const cls = [!i.is_active ? 'is-inactive' : '', matches && !matches.has(i.id) ? 'is-context' : ''].join(' ');
                const indent = '<span class="ac-tree"></span>'.repeat(Math.max(0, depth - 1)) + (depth ? '<span class="ac-tree">└</span>' : '');
                return '<tr class="' + cls + '">'
                    + (allMode ? '<td class="ac-company">' + esc(i.company_name) + '</td>' : '')
                    + '<td class="ac-code">' + indent + esc(i.code) + '</td>'
                    + '<td>' + esc(i.name) + '</td>'
                    + '<td><span class="ac-kind ' + esc(i.kind) + '">' + esc(kindLabel(i.kind)) + '</span></td>'
                    + '<td class="ac-desc">' + esc(i.description || '') + '</td>'
                    + '<td><span class="ac-badge ' + (i.is_active ? 'on">' + esc(T('active')) : 'off">' + esc(T('inactive'))) + '</span></td>'
                    + '<td class="ac-actions"><button type="button" class="action-btn" data-edit="' + i.id + '" title="' + esc(t('common.edit')) + '" aria-label="' + esc(t('common.edit')) + '">' + ICON_EDIT + '</button>'
                    + '<button type="button" class="action-btn delete" data-delete="' + i.id + '" title="' + esc(t('common.delete')) + '" aria-label="' + esc(t('common.delete')) + '">' + ICON_DELETE + '</button></td>'
                    + '</tr>';
            }).join('');
        }
        let searchTimer = null;
        $('acSearch').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(render, 150); });
        $('acShowInactive').addEventListener('change', render);
        $('acKindFilter').addEventListener('change', render);

        $('acBody').addEventListener('click', function (e) {
            const ed = e.target.closest('[data-edit]');
            if (ed) return openEdit(Number(ed.dataset.edit));
            const del = e.target.closest('[data-delete]');
            if (del) return remove(Number(del.dataset.delete));
        });

        // ── Modals ─────────────────────────────────────────────────────────
        const open = id => $(id).classList.add('open');
        const close = id => $(id).classList.remove('open');
        document.querySelectorAll('.ac-overlay').forEach(o => {
            o.addEventListener('click', e => { if (e.target === o || e.target.closest('[data-close]')) close(o.id); });
        });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.ac-overlay.open').forEach(o => close(o.id)); });

        // ── Add / edit ─────────────────────────────────────────────────────
        function descendantsOf(id) {
            const out = new Set();
            const stack = [id];
            while (stack.length) {
                const at = stack.pop();
                items.forEach(i => { if (i.parent_id === at && !out.has(i.id)) { out.add(i.id); stack.push(i.id); } });
            }
            return out;
        }

        // The company the open modal is working in: the account's own when
        // editing, the picker's when adding in "All companies", else the active one.
        function editCompany() {
            const it = editingId ? byId.get(editingId) : null;
            if (it) return it.company_id;
            return allMode ? Number($('acCompany').value) : companyId;
        }

        // A parent is always in the same company, and never the account
        // itself or anything below it - that would be a loop.
        function paintParents(selectedParent) {
            const it = editingId ? byId.get(editingId) : null;
            const banned = it ? descendantsOf(it.id).add(it.id) : new Set();
            const cid = editCompany();
            $('acParent').innerHTML = '<option value="">' + esc(T('parent_none')) + '</option>'
                + treeOrder(items.filter(i => i.company_id === cid)).filter(r => !banned.has(r.item.id)).map(r =>
                    '<option value="' + r.item.id + '"' + (selectedParent === r.item.id ? ' selected' : '') + '>'
                    + '  '.repeat(r.depth) + esc(r.item.code + ' - ' + r.item.name) + (r.item.is_active ? '' : ' (' + esc(T('inactive').toLowerCase()) + ')')
                    + '</option>').join('');
        }

        function openEdit(id) {
            editingId = id || 0;
            const it = id ? byId.get(id) : null;
            $('acEditTitle').textContent = it ? T('edit_title', { code: it.code }) : T('add_title');
            // Only a NEW account asks which company: an existing one stays in its own.
            $('acCompanyField').hidden = !(allMode && !it);
            if (allMode && !it) $('acCompany').innerHTML = companyOptions(companyId);
            $('acCode').value = it ? it.code : '';
            $('acName').value = it ? it.name : '';
            $('acKind').value = it ? it.kind : 'expense';
            $('acDesc').value = it ? (it.description || '') : '';
            $('acActive').checked = it ? it.is_active : true;
            paintParents(it ? it.parent_id : null);
            open('acEdit');
            setTimeout(() => $(allMode && !it ? 'acCompany' : 'acCode').focus(), 30);
        }
        $('acAddBtn').addEventListener('click', () => openEdit(0));
        $('acCompany').addEventListener('change', () => paintParents(null));
        $('acParent').addEventListener('change', function () {
            const p = byId.get(Number(this.value));
            if (p && !editingId) $('acKind').value = p.kind;   // a NEW child starts as its parent's kind
        });

        $('acSave').addEventListener('click', async function () {
            const body = {
                action: 'save', id: editingId, company_id: editCompany(),
                code: $('acCode').value, name: $('acName').value, kind: $('acKind').value, description: $('acDesc').value,
                parent_id: $('acParent').value || null, is_active: $('acActive').checked,
            };
            this.disabled = true;
            try {
                const d = await (await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json();
                if (!d.success) { toast(d.error || T('save_failed'), 'error'); return; }
                close('acEdit');
                toast(T('saved'), 'success');
                load();
            } catch (e) {
                toast(T('save_failed'), 'error');
            } finally {
                this.disabled = false;
            }
        });

        async function remove(id) {
            const it = byId.get(id);
            if (!it) return;
            const ok = await showConfirm({
                title: T('delete_title'),
                message: T(it.child_count ? 'delete_has_children' : 'delete_confirm', { code: it.code, name: it.name, count: it.child_count }),
                okLabel: t('common.delete'),
                okClass: 'danger'
            });
            if (!ok) return;
            try {
                const d = await (await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id }) })).json();
                if (!d.success) { toast(d.error || T('delete_failed'), 'error'); return; }
                toast(T('deleted'), 'success');
                load();
            } catch (e) {
                toast(T('delete_failed'), 'error');
            }
        }

        // ── Export ─────────────────────────────────────────────────────────
        $('acExportBtn').addEventListener('click', function (e) {
            e.stopPropagation();
            const m = $('acExportMenu');
            m.hidden = !m.hidden;
            this.setAttribute('aria-expanded', String(!m.hidden));
        });
        document.addEventListener('click', () => { $('acExportMenu').hidden = true; $('acExportBtn').setAttribute('aria-expanded', 'false'); });
        $('acExportMenu').addEventListener('click', function (e) {
            const a = e.target.closest('[data-format]');
            if (!a) return;
            e.preventDefault();
            window.location.href = API + '?action=export&format=' + a.dataset.format + '&company_id=' + (a.dataset.company || companyId);
        });
        // A file is always ONE company's list - it is what an import reads back.
        // So in "All companies" the menu offers both formats under each company.
        const exportMenuPlain = $('acExportMenu').innerHTML;
        function paintExportMenu() {
            if (!allMode) { $('acExportMenu').innerHTML = exportMenuPlain; return; }
            const tpl = document.createElement('div');
            tpl.innerHTML = exportMenuPlain;
            tpl.querySelectorAll('small').forEach(s => s.remove());   // one hint per company is too long a menu
            $('acExportMenu').innerHTML = companies.map(c => {
                tpl.querySelectorAll('[data-format]').forEach(a => a.setAttribute('data-company', c.id));
                return '<div class="ac-export-company">' + esc(c.name) + '</div>' + tpl.innerHTML;
            }).join('');
        }

        // ── Import ─────────────────────────────────────────────────────────
        function importStep(n) {
            $('acImportStep1').hidden = n !== 1;
            $('acImportStep2').hidden = n === 1;
            $('acPreview').hidden = n !== 1;
            $('acImportBack').hidden = n !== 2;
            $('acApply').hidden = n !== 2;
            $('acImportClose').textContent = n === 3 ? t('common.close') : t('common.cancel');
        }
        $('acImportBtn').addEventListener('click', () => {
            $('acFile').value = ''; $('acDeactivate').checked = false;
            $('acImportCompanyField').hidden = !allMode;
            if (allMode) $('acImportCompany').innerHTML = companyOptions(companyId);
            importStep(1); open('acImport');
        });
        $('acImportBack').addEventListener('click', () => importStep(1));

        async function runImport(apply) {
            const f = $('acFile').files[0];
            if (!f) { toast(T('import_choose'), 'warning'); return null; }
            const fd = new FormData();
            fd.append('action', 'import');
            fd.append('company_id', allMode ? $('acImportCompany').value : companyId);
            fd.append('apply', apply ? '1' : '0');
            fd.append('deactivate_missing', $('acDeactivate').checked ? '1' : '0');
            fd.append('file', f);
            const d = await (await fetch(API, { method: 'POST', credentials: 'same-origin', body: fd })).json();
            if (!d.success) { toast(d.error || T('import_failed'), 'error'); return null; }
            return d.report;
        }

        function paintReport(r) {
            const c = r.counts;
            const chip = (n, k) => '<span class="ac-count"><strong>' + n + '</strong>' + esc(T('count_' + k)) + '</span>';
            let html = '';
            if (r.applied) html += '<div class="ac-done">' + esc(T('import_done')) + '</div>';
            html += '<div class="ac-counts">' + chip(c.create, 'create') + chip(c.update, 'update') + chip(c.unchanged, 'unchanged')
                 + (c.deactivate ? chip(c.deactivate, 'deactivate') : '') + '</div>';
            if (r.errors.length) {
                html += '<div class="ac-errors"><strong>' + esc(T(r.errors.length === 1 ? 'import_errors_one' : 'import_errors', { count: r.errors.length })) + '</strong><ul>'
                     + r.errors.slice(0, 200).map(e => '<li>' + (e.line ? esc(T('line', { line: e.line })) + ' ' : '') + esc(e.message) + '</li>').join('')
                     + '</ul></div>';
            }
            const shown = r.rows.filter(x => x.action !== 'unchanged');
            if (shown.length) {
                html += '<div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>' + esc(T('col_line')) + '</th><th>' + esc(T('col_code')) + '</th><th>'
                     + esc(T('col_name')) + '</th><th>' + esc(T('col_action')) + '</th><th>' + esc(T('col_changes')) + '</th></tr></thead><tbody>'
                     + shown.slice(0, 500).map(x => '<tr><td>' + (x.line || '') + '</td><td class="ac-code">' + esc(x.code) + '</td><td>' + esc(x.name) + '</td>'
                        + '<td><span class="ac-act ' + x.action + '">' + esc(T('action_' + x.action)) + '</span></td>'
                        + '<td>' + esc(x.changes.map(ch => T('change_' + ch)).join(', ')) + '</td></tr>').join('')
                     + '</tbody></table></div>'
                     + (shown.length > 500 ? '<p class="ac-summary">' + esc(T('import_more', { count: shown.length - 500 })) + '</p>' : '');
            } else if (!r.errors.length) {
                html += '<p class="ac-summary">' + esc(T('import_nothing')) + '</p>';
            }
            $('acImportStep2').innerHTML = html;
            const nothing = !c.create && !c.update && !c.deactivate;
            $('acApply').disabled = r.errors.length > 0 || nothing;
        }

        $('acPreview').addEventListener('click', async function () {
            this.disabled = true;
            try {
                const r = await runImport(false);
                if (!r) return;
                paintReport(r);
                importStep(2);
            } catch (e) {
                toast(T('import_failed'), 'error');
            } finally {
                this.disabled = false;
            }
        });
        $('acApply').addEventListener('click', async function () {
            this.disabled = true;
            try {
                const r = await runImport(true);
                if (!r) { this.disabled = false; return; }
                paintReport(r);
                if (r.applied) { importStep(3); toast(T('import_done'), 'success'); load(); }
            } catch (e) {
                toast(T('import_failed'), 'error');
                this.disabled = false;
            }
        });

        load();
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
