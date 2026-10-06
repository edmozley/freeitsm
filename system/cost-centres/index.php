<?php
/**
 * System — Cost Centres (GH #160, stage 1).
 *
 * The finance department's list of cost centres, per company: code, name,
 * description, parent (the hierarchy) and active/inactive. Added by hand, or
 * brought in from the accounting system with an Excel/CSV import or the REST
 * API (/cost-centres/sync) - all three through CostCentresService.
 *
 * Stage 1 is the list itself. Charging assets and service bookings to a cost
 * centre is stage 2, which is why "inactive" already means "not offered for
 * new assignments" rather than "gone".
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
$current_page = 'cost-centres';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
requireModuleAccess('system');
$ccT = fn(string $k) => t('system.cost_centres.' . $k);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName()); ?> - <?php echo htmlspecialchars($ccT('title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
            display: flex; flex-direction: column;
        }
        /* Full width, like the other System settings pages (Ed). flex:1 inside a
           flex column, so the page scrolls rather than being cut off. */
        .cc-container { flex: 1; min-height: 0; overflow-y: auto; width: 100%; box-sizing: border-box; padding: 24px 32px 40px; }
        .cc-header h2 { margin: 0; font-size: 22px; color: var(--text, #333); }
        .cc-header p { margin: 5px 0 20px; font-size: 13px; color: var(--text-dim, #888); line-height: 1.55; }
        .cc-verify { margin-bottom: 18px; padding: 12px 14px; border-radius: 8px; font-size: 13px;
            background: var(--warning-bg, #fff4ce); color: var(--warning-text, #6b5900); border: 1px solid var(--warning-border, #f2d675); }
        .cc-panel { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px; padding: 18px 20px; }

        .cc-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 14px; }
        .cc-toolbar .cc-spacer { flex: 1; }
        .cc-toolbar select, .cc-toolbar input[type=search] { padding: 8px 10px; border: 1px solid var(--border, #cfd8dc); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #333); }
        .cc-toolbar input[type=search] { width: 260px; max-width: 100%; }
        .cc-check { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-muted, #555); cursor: pointer; }
        .cc-summary { font-size: 12.5px; color: var(--text-dim, #888); margin: -4px 0 10px; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover { background: var(--sys-accent-hover, #455a64); }
        .btn-secondary { background: var(--surface-2, #eceff1); color: var(--text, #333); }
        .btn-secondary:hover { background: var(--border, #cfd8dc); }
        .btn:disabled { opacity: .5; cursor: default; }

        .cc-export { position: relative; }
        .cc-export-menu { position: absolute; right: 0; top: calc(100% + 4px); z-index: 20; min-width: 190px; background: var(--surface, #fff);
            border: 1px solid var(--border, #ddd); border-radius: 8px; box-shadow: 0 6px 20px var(--shadow, rgba(0,0,0,.12)); padding: 4px; }
        .cc-export-menu a { display: block; padding: 8px 12px; border-radius: 5px; font-size: 13px; color: var(--text, #333); text-decoration: none; }
        .cc-export-menu a:hover { background: var(--surface-2, #eceff1); }
        .cc-export-menu small { display: block; color: var(--text-dim, #888); font-size: 11.5px; }
        .cc-export-menu { max-height: 70vh; overflow-y: auto; }
        .cc-export-company { padding: 8px 12px 2px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-dim, #888); }
        .cc-export-company:not(:first-child) { border-top: 1px solid var(--border-soft, #eee); margin-top: 4px; padding-top: 10px; }
        .cc-company { white-space: nowrap; color: var(--text-muted, #666); }

        .cc-table-wrap { overflow-x: auto; }
        .cc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .cc-table th { text-align: left; font-size: 12px; color: var(--text-muted, #888); font-weight: 600; padding: 8px 10px; border-bottom: 1px solid var(--border-soft, #eee); white-space: nowrap; }
        .cc-table td { padding: 9px 10px; border-bottom: 1px solid var(--border-soft, #f2f2f2); color: var(--text, #444); vertical-align: top; }
        .cc-table tr.is-inactive td { color: var(--text-faint, #9aa3ab); }
        .cc-table tr.is-context td { opacity: .55; }
        .cc-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; white-space: nowrap; }
        .cc-tree { display: inline-block; width: 14px; color: var(--text-faint, #b0bec5); }
        .cc-desc { color: var(--text-muted, #777); max-width: 420px; }
        .cc-badge { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .cc-badge.on { background: #e8f5e9; color: #2e7d32; }
        .cc-badge.off { background: #f0f0f0; color: #888; }
        .cc-actions { text-align: right; white-space: nowrap; }
        /* Edit / Delete as icons, the same buttons as Tickets -> Settings. Scoped,
           because inbox.css's bare .action-btn is a big toolbar button. */
        .cc-actions .action-btn { background: none; border: 1px solid var(--border, #ddd); color: var(--text-muted, #666); cursor: pointer; padding: 6px; margin-left: 4px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; }
        .cc-actions .action-btn:hover { background: var(--surface-hover, #f0f0f0); border-color: var(--accent, #0078d4); color: var(--accent, #0078d4); }
        .cc-actions .action-btn.delete { color: var(--danger-accent, #d13438); }
        .cc-actions .action-btn.delete:hover { background: var(--danger-bg, #fdf3f3); border-color: var(--danger-accent, #d13438); color: var(--danger-text, #a00); }
        .cc-actions .action-btn svg { width: 16px; height: 16px; }
        .cc-empty { padding: 28px 10px; text-align: center; color: var(--text-faint, #999); font-size: 13px; }

        /* Modals - namespaced so inbox.css's global .modal rules stay out of it. */
        .cc-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 2100; align-items: center; justify-content: center; }
        .cc-overlay.open { display: flex; }
        .cc-modal { background: var(--surface, #fff); border-radius: 10px; width: 520px; max-width: 94vw; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,.25); }
        .cc-modal.wide { width: 820px; }
        .cc-modal-head { padding: 18px 22px; border-bottom: 1px solid var(--border-soft, #eee); font-size: 16px; font-weight: 600; color: var(--text, #333); }
        .cc-modal-body { padding: 18px 22px; overflow-y: auto; }
        .cc-modal-foot { padding: 14px 22px; border-top: 1px solid var(--border-soft, #eee); display: flex; justify-content: flex-end; gap: 10px; }
        .cc-field { margin-bottom: 14px; }
        .cc-field label { display: block; font-size: 13px; font-weight: 600; color: var(--text, #444); margin-bottom: 4px; }
        .cc-field .hint { font-size: 12px; color: var(--text-dim, #888); margin-top: 4px; line-height: 1.45; }
        .cc-field input[type=text], .cc-field textarea, .cc-field select { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--border, #ddd); border-radius: 6px; font-size: 13px; font-family: inherit; background: var(--surface, #fff); color: var(--text, #333); }
        .cc-field textarea { min-height: 70px; resize: vertical; }
        .cc-field input:focus, .cc-field textarea:focus, .cc-field select:focus { outline: none; border-color: var(--sys-accent, #546e7a); }
        .cc-field input#ccCode { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        /* Toggles use inbox.css's .toggle-label / .toggle-switch (caption above, switch below). */
        .cc-toggle { margin-bottom: 4px; }
        .cc-toggle > span { font-weight: 600; color: var(--text, #444); }
        .cc-toggle > span small { display: block; font-weight: 400; color: var(--text-dim, #888); font-size: 12px; margin-top: 2px; line-height: 1.45; }
        .cc-note { font-size: 12.5px; line-height: 1.55; color: var(--text-muted, #666); background: var(--surface-2, #f5f7f8); border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; }
        .cc-note code { font-size: 12px; }

        /* Import preview */
        .cc-counts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .cc-count { padding: 6px 12px; border-radius: 6px; font-size: 13px; background: var(--surface-2, #eceff1); color: var(--text, #333); }
        .cc-count strong { font-size: 15px; margin-right: 4px; }
        .cc-errors { border: 1px solid #f5c2c0; background: #fdecea; color: #7d1f16; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; font-size: 12.5px; }
        .cc-errors ul { margin: 6px 0 0; padding-left: 18px; }
        .cc-errors li { margin-bottom: 3px; }
        .cc-act { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; white-space: nowrap; }
        .cc-act.create { background: #e3f2fd; color: #1565c0; }
        .cc-act.update { background: #fff3e0; color: #b45309; }
        .cc-act.unchanged { background: #f0f0f0; color: #888; }
        .cc-act.deactivate { background: #fdecea; color: #b71c1c; }
        .cc-done { color: #2e7d32; font-weight: 600; font-size: 13px; margin-bottom: 10px; }

        [data-theme-mode="dark"] .cc-badge.on, [data-theme-mode="dark"] .cc-act.create { background: #16331f; color: #86efac; }
        [data-theme-mode="dark"] .cc-act.create { background: #1e3a5f; color: #90caf9; }
        [data-theme-mode="dark"] .cc-badge.off, [data-theme-mode="dark"] .cc-act.unchanged { background: #2b313a; color: #9aa3af; }
        [data-theme-mode="dark"] .cc-act.update { background: #3a2e12; color: #fcd34d; }
        [data-theme-mode="dark"] .cc-act.deactivate, [data-theme-mode="dark"] .cc-errors { background: #3b1512; color: #f3c7c2; border-color: #7d2e26; }
        [data-theme-mode="dark"] .cc-done { color: #86efac; }
        @media (max-width: 700px) {
            .cc-container { padding: 16px; }
            .cc-desc { display: none; }
            .cc-toolbar input[type=search] { width: 100%; }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=174">
</head>
<body data-mobile-module="system" data-mobile-page="cost-centres">
    <?php include '../includes/header.php'; ?>
    <div class="cc-container">
        <div class="cc-header">
            <h2><?php echo htmlspecialchars($ccT('title')); ?></h2>
            <p><?php echo htmlspecialchars($ccT('intro')); ?></p>
        </div>

        <div class="cc-verify" id="ccVerify" hidden><?php echo htmlspecialchars($ccT('needs_verify')); ?></div>

        <div class="cc-panel" id="ccPanel">
            <div class="cc-toolbar">
                <input type="search" id="ccSearch" placeholder="<?php echo htmlspecialchars($ccT('search')); ?>" aria-label="<?php echo htmlspecialchars($ccT('search')); ?>">
                <label class="cc-check"><input type="checkbox" id="ccShowInactive" checked> <?php echo htmlspecialchars($ccT('show_inactive')); ?></label>
                <span class="cc-spacer"></span>
                <button type="button" class="btn btn-secondary" id="ccImportBtn"><?php echo htmlspecialchars($ccT('import')); ?></button>
                <div class="cc-export">
                    <button type="button" class="btn btn-secondary" id="ccExportBtn" aria-haspopup="true" aria-expanded="false"><?php echo htmlspecialchars($ccT('export')); ?></button>
                    <div class="cc-export-menu" id="ccExportMenu" hidden>
                        <a href="#" data-format="xlsx"><?php echo htmlspecialchars($ccT('export_xlsx')); ?><small><?php echo htmlspecialchars($ccT('export_xlsx_hint')); ?></small></a>
                        <a href="#" data-format="csv"><?php echo htmlspecialchars($ccT('export_csv')); ?><small><?php echo htmlspecialchars($ccT('export_csv_hint')); ?></small></a>
                    </div>
                </div>
                <button type="button" class="btn btn-primary" id="ccAddBtn"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <div class="cc-summary" id="ccSummary"></div>
            <div class="cc-table-wrap">
                <table class="cc-table">
                    <thead><tr>
                        <th id="ccColCompany" hidden><?php echo htmlspecialchars($ccT('company')); ?></th>
                        <th><?php echo htmlspecialchars($ccT('col_code')); ?></th>
                        <th><?php echo htmlspecialchars($ccT('col_name')); ?></th>
                        <th class="cc-desc"><?php echo htmlspecialchars($ccT('col_description')); ?></th>
                        <th><?php echo htmlspecialchars($ccT('col_status')); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody id="ccBody"><tr><td colspan="5" class="cc-empty"><?php echo htmlspecialchars($ccT('loading')); ?></td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add / edit -->
    <div class="cc-overlay" id="ccEdit">
        <div class="cc-modal" role="dialog" aria-modal="true" aria-labelledby="ccEditTitle">
            <div class="cc-modal-head" id="ccEditTitle"></div>
            <div class="cc-modal-body">
                <div class="cc-field" id="ccCompanyField" hidden>
                    <label for="ccCompany"><?php echo htmlspecialchars($ccT('company')); ?></label>
                    <select id="ccCompany"></select>
                </div>
                <div class="cc-field">
                    <label for="ccCode"><?php echo htmlspecialchars($ccT('field_code')); ?></label>
                    <input type="text" id="ccCode" maxlength="50" autocomplete="off" spellcheck="false">
                    <div class="hint"><?php echo htmlspecialchars($ccT('field_code_hint')); ?></div>
                </div>
                <div class="cc-field">
                    <label for="ccName"><?php echo htmlspecialchars($ccT('field_name')); ?></label>
                    <input type="text" id="ccName" maxlength="150" autocomplete="off">
                </div>
                <div class="cc-field">
                    <label for="ccDesc"><?php echo htmlspecialchars($ccT('field_description')); ?></label>
                    <textarea id="ccDesc" maxlength="500"></textarea>
                </div>
                <div class="cc-field">
                    <label for="ccParent"><?php echo htmlspecialchars($ccT('field_parent')); ?></label>
                    <select id="ccParent"></select>
                    <div class="hint"><?php echo htmlspecialchars($ccT('field_parent_hint')); ?></div>
                </div>
                <div class="toggle-label cc-toggle">
                    <span><?php echo htmlspecialchars($ccT('field_active')); ?><small><?php echo htmlspecialchars($ccT('field_active_hint')); ?></small></span>
                    <label class="toggle-switch"><input type="checkbox" id="ccActive"><span class="toggle-slider"></span></label>
                </div>
            </div>
            <div class="cc-modal-foot">
                <button type="button" class="btn btn-secondary" data-close><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="ccSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Import -->
    <div class="cc-overlay" id="ccImport">
        <div class="cc-modal wide" role="dialog" aria-modal="true" aria-labelledby="ccImportTitle">
            <div class="cc-modal-head" id="ccImportTitle"><?php echo htmlspecialchars($ccT('import_title')); ?></div>
            <div class="cc-modal-body">
                <div id="ccImportStep1">
                    <div class="cc-note"><?php echo $ccT('import_help_html'); ?></div>
                    <div class="cc-note"><?php echo $ccT('import_zeros_html'); ?></div>
                    <div class="cc-field" id="ccImportCompanyField" hidden>
                        <label for="ccImportCompany"><?php echo htmlspecialchars($ccT('company')); ?></label>
                        <select id="ccImportCompany"></select>
                    </div>
                    <div class="cc-field">
                        <label for="ccFile"><?php echo htmlspecialchars($ccT('import_file')); ?></label>
                        <input type="file" id="ccFile" accept=".xlsx,.csv,.txt">
                    </div>
                    <div class="toggle-label cc-toggle">
                        <span><?php echo htmlspecialchars($ccT('import_deactivate')); ?><small><?php echo htmlspecialchars($ccT('import_deactivate_hint')); ?></small></span>
                        <label class="toggle-switch"><input type="checkbox" id="ccDeactivate"><span class="toggle-slider"></span></label>
                    </div>
                </div>
                <div id="ccImportStep2" hidden></div>
            </div>
            <div class="cc-modal-foot">
                <button type="button" class="btn btn-secondary" id="ccImportBack" hidden><?php echo htmlspecialchars($ccT('back')); ?></button>
                <button type="button" class="btn btn-secondary" data-close id="ccImportClose"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="ccPreview"><?php echo htmlspecialchars($ccT('preview')); ?></button>
                <button type="button" class="btn btn-primary" id="ccApply" hidden><?php echo htmlspecialchars($ccT('import')); ?></button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/confirm.js"></script>
    <script>
    (function () {
        const API = '../../api/system/cost_centres.php';
        const $ = id => document.getElementById(id);
        const T = (k, p) => t('system.cost_centres.' + k, p);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
        const toast = (m, k) => { if (typeof showToast === 'function') showToast(m, k); };

        let companyId = 0;       // the company a NEW cost centre goes into
        let allMode = false;     // header switcher on "All companies": every company's list, with a Company column
        let companies = [];
        let items = [];          // the cost centres, flat
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
                $('ccColCompany').hidden = !allMode;
                paintExportMenu();
                $('ccVerify').hidden = d.ready;
                $('ccPanel').hidden = !d.ready;
                items = d.cost_centres;
                byId = new Map(items.map(i => [i.id, i]));
                render();
            } catch (e) {
                $('ccBody').innerHTML = '<tr><td colspan="' + cols() + '" class="cc-empty">' + esc(T('load_failed', { error: String(e.message || e) })) + '</td></tr>';
            }
        }
        const cols = () => allMode ? 6 : 5;
        const companyOptions = selected => companies.map(c =>
            '<option value="' + c.id + '"' + (c.id === selected ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('');


        // ── The tree ───────────────────────────────────────────────────────
        // Parents first, children under them, each level by code. A cost centre
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
            const q = $('ccSearch').value.trim().toLowerCase();
            const showInactive = $('ccShowInactive').checked;
            const inactive = items.filter(i => !i.is_active).length;
            $('ccSummary').textContent = items.length
                ? T(items.length === 1 ? 'summary_one' : 'summary', { count: items.length }) + (inactive ? ' · ' + T('summary_inactive', { count: inactive }) : '')
                : '';

            if (!items.length) {
                $('ccBody').innerHTML = '<tr><td colspan="' + cols() + '" class="cc-empty">' + esc(T('empty')) + '</td></tr>';
                return;
            }

            // Searching keeps each match's parents on screen, faded, so the
            // match is still seen in its place in the tree.
            let visible = items.filter(i => showInactive || i.is_active);
            let matches = null;
            if (q) {
                matches = new Set(visible.filter(i => (i.code + ' ' + i.name + ' ' + (i.description || '') + (allMode ? ' ' + i.company_name : '')).toLowerCase().includes(q)).map(i => i.id));
                const keep = new Set(matches);
                matches.forEach(id => { for (let p = byId.get(id).parent_id, n = 0; p && n < 1000; p = (byId.get(p) || {}).parent_id, n++) keep.add(p); });
                visible = items.filter(i => keep.has(i.id));
            }
            const rows = treeOrder(visible);
            if (!rows.length) {
                $('ccBody').innerHTML = '<tr><td colspan="' + cols() + '" class="cc-empty">' + esc(T('none_match')) + '</td></tr>';
                return;
            }
            $('ccBody').innerHTML = rows.map(({ item: i, depth }) => {
                const cls = [!i.is_active ? 'is-inactive' : '', matches && !matches.has(i.id) ? 'is-context' : ''].join(' ');
                const indent = '<span class="cc-tree"></span>'.repeat(Math.max(0, depth - 1)) + (depth ? '<span class="cc-tree">└</span>' : '');
                return '<tr class="' + cls + '">'
                    + (allMode ? '<td class="cc-company">' + esc(i.company_name) + '</td>' : '')
                    + '<td class="cc-code">' + indent + esc(i.code) + '</td>'
                    + '<td>' + esc(i.name) + '</td>'
                    + '<td class="cc-desc">' + esc(i.description || '') + '</td>'
                    + '<td><span class="cc-badge ' + (i.is_active ? 'on">' + esc(T('active')) : 'off">' + esc(T('inactive'))) + '</span></td>'
                    + '<td class="cc-actions"><button type="button" class="action-btn" data-edit="' + i.id + '" title="' + esc(t('common.edit')) + '" aria-label="' + esc(t('common.edit')) + '">' + ICON_EDIT + '</button>'
                    + '<button type="button" class="action-btn delete" data-delete="' + i.id + '" title="' + esc(t('common.delete')) + '" aria-label="' + esc(t('common.delete')) + '">' + ICON_DELETE + '</button></td>'
                    + '</tr>';
            }).join('');
        }
        let searchTimer = null;
        $('ccSearch').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(render, 150); });
        $('ccShowInactive').addEventListener('change', render);

        $('ccBody').addEventListener('click', function (e) {
            const ed = e.target.closest('[data-edit]');
            if (ed) return openEdit(Number(ed.dataset.edit));
            const del = e.target.closest('[data-delete]');
            if (del) return remove(Number(del.dataset.delete));
        });

        // ── Modals ─────────────────────────────────────────────────────────
        const open = id => $(id).classList.add('open');
        const close = id => $(id).classList.remove('open');
        document.querySelectorAll('.cc-overlay').forEach(o => {
            o.addEventListener('click', e => { if (e.target === o || e.target.closest('[data-close]')) close(o.id); });
        });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.cc-overlay.open').forEach(o => close(o.id)); });

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

        // The company the open modal is working in: the cost centre's own when
        // editing, the picker's when adding in "All companies", else the active one.
        function editCompany() {
            const it = editingId ? byId.get(editingId) : null;
            if (it) return it.company_id;
            return allMode ? Number($('ccCompany').value) : companyId;
        }

        // A parent is always in the same company, and never the cost centre
        // itself or anything below it - that would be a loop.
        function paintParents(selectedParent) {
            const it = editingId ? byId.get(editingId) : null;
            const banned = it ? descendantsOf(it.id).add(it.id) : new Set();
            const cid = editCompany();
            $('ccParent').innerHTML = '<option value="">' + esc(T('parent_none')) + '</option>'
                + treeOrder(items.filter(i => i.company_id === cid)).filter(r => !banned.has(r.item.id)).map(r =>
                    '<option value="' + r.item.id + '"' + (selectedParent === r.item.id ? ' selected' : '') + '>'
                    + '  '.repeat(r.depth) + esc(r.item.code + ' - ' + r.item.name) + (r.item.is_active ? '' : ' (' + esc(T('inactive').toLowerCase()) + ')')
                    + '</option>').join('');
        }

        function openEdit(id) {
            editingId = id || 0;
            const it = id ? byId.get(id) : null;
            $('ccEditTitle').textContent = it ? T('edit_title', { code: it.code }) : T('add_title');
            // Only a NEW cost centre asks which company: an existing one stays in its own.
            $('ccCompanyField').hidden = !(allMode && !it);
            if (allMode && !it) $('ccCompany').innerHTML = companyOptions(companyId);
            $('ccCode').value = it ? it.code : '';
            $('ccName').value = it ? it.name : '';
            $('ccDesc').value = it ? (it.description || '') : '';
            $('ccActive').checked = it ? it.is_active : true;
            paintParents(it ? it.parent_id : null);
            open('ccEdit');
            setTimeout(() => $(allMode && !it ? 'ccCompany' : 'ccCode').focus(), 30);
        }
        $('ccAddBtn').addEventListener('click', () => openEdit(0));
        $('ccCompany').addEventListener('change', () => paintParents(null));

        $('ccSave').addEventListener('click', async function () {
            const body = {
                action: 'save', id: editingId, company_id: editCompany(),
                code: $('ccCode').value, name: $('ccName').value, description: $('ccDesc').value,
                parent_id: $('ccParent').value || null, is_active: $('ccActive').checked,
            };
            this.disabled = true;
            try {
                const d = await (await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json();
                if (!d.success) { toast(d.error || T('save_failed'), 'error'); return; }
                close('ccEdit');
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
        $('ccExportBtn').addEventListener('click', function (e) {
            e.stopPropagation();
            const m = $('ccExportMenu');
            m.hidden = !m.hidden;
            this.setAttribute('aria-expanded', String(!m.hidden));
        });
        document.addEventListener('click', () => { $('ccExportMenu').hidden = true; $('ccExportBtn').setAttribute('aria-expanded', 'false'); });
        $('ccExportMenu').addEventListener('click', function (e) {
            const a = e.target.closest('[data-format]');
            if (!a) return;
            e.preventDefault();
            window.location.href = API + '?action=export&format=' + a.dataset.format + '&company_id=' + (a.dataset.company || companyId);
        });
        // A file is always ONE company's list - it is what an import reads back.
        // So in "All companies" the menu offers both formats under each company.
        const exportMenuPlain = $('ccExportMenu').innerHTML;
        function paintExportMenu() {
            if (!allMode) { $('ccExportMenu').innerHTML = exportMenuPlain; return; }
            const tpl = document.createElement('div');
            tpl.innerHTML = exportMenuPlain;
            tpl.querySelectorAll('small').forEach(s => s.remove());   // one hint per company is too long a menu
            $('ccExportMenu').innerHTML = companies.map(c => {
                tpl.querySelectorAll('[data-format]').forEach(a => a.setAttribute('data-company', c.id));
                return '<div class="cc-export-company">' + esc(c.name) + '</div>' + tpl.innerHTML;
            }).join('');
        }

        // ── Import ─────────────────────────────────────────────────────────
        function importStep(n) {
            $('ccImportStep1').hidden = n !== 1;
            $('ccImportStep2').hidden = n === 1;
            $('ccPreview').hidden = n !== 1;
            $('ccImportBack').hidden = n !== 2;
            $('ccApply').hidden = n !== 2;
            $('ccImportClose').textContent = n === 3 ? t('common.close') : t('common.cancel');
        }
        $('ccImportBtn').addEventListener('click', () => {
            $('ccFile').value = ''; $('ccDeactivate').checked = false;
            $('ccImportCompanyField').hidden = !allMode;
            if (allMode) $('ccImportCompany').innerHTML = companyOptions(companyId);
            importStep(1); open('ccImport');
        });
        $('ccImportBack').addEventListener('click', () => importStep(1));

        async function runImport(apply) {
            const f = $('ccFile').files[0];
            if (!f) { toast(T('import_choose'), 'warning'); return null; }
            const fd = new FormData();
            fd.append('action', 'import');
            fd.append('company_id', allMode ? $('ccImportCompany').value : companyId);
            fd.append('apply', apply ? '1' : '0');
            fd.append('deactivate_missing', $('ccDeactivate').checked ? '1' : '0');
            fd.append('file', f);
            const d = await (await fetch(API, { method: 'POST', credentials: 'same-origin', body: fd })).json();
            if (!d.success) { toast(d.error || T('import_failed'), 'error'); return null; }
            return d.report;
        }

        function paintReport(r) {
            const c = r.counts;
            const chip = (n, k) => '<span class="cc-count"><strong>' + n + '</strong>' + esc(T('count_' + k)) + '</span>';
            let html = '';
            if (r.applied) html += '<div class="cc-done">' + esc(T('import_done')) + '</div>';
            html += '<div class="cc-counts">' + chip(c.create, 'create') + chip(c.update, 'update') + chip(c.unchanged, 'unchanged')
                 + (c.deactivate ? chip(c.deactivate, 'deactivate') : '') + '</div>';
            if (r.errors.length) {
                html += '<div class="cc-errors"><strong>' + esc(T(r.errors.length === 1 ? 'import_errors_one' : 'import_errors', { count: r.errors.length })) + '</strong><ul>'
                     + r.errors.slice(0, 200).map(e => '<li>' + (e.line ? esc(T('line', { line: e.line })) + ' ' : '') + esc(e.message) + '</li>').join('')
                     + '</ul></div>';
            }
            const shown = r.rows.filter(x => x.action !== 'unchanged');
            if (shown.length) {
                html += '<div class="cc-table-wrap"><table class="cc-table"><thead><tr><th>' + esc(T('col_line')) + '</th><th>' + esc(T('col_code')) + '</th><th>'
                     + esc(T('col_name')) + '</th><th>' + esc(T('col_action')) + '</th><th>' + esc(T('col_changes')) + '</th></tr></thead><tbody>'
                     + shown.slice(0, 500).map(x => '<tr><td>' + (x.line || '') + '</td><td class="cc-code">' + esc(x.code) + '</td><td>' + esc(x.name) + '</td>'
                        + '<td><span class="cc-act ' + x.action + '">' + esc(T('action_' + x.action)) + '</span></td>'
                        + '<td>' + esc(x.changes.map(ch => T('change_' + ch)).join(', ')) + '</td></tr>').join('')
                     + '</tbody></table></div>'
                     + (shown.length > 500 ? '<p class="cc-summary">' + esc(T('import_more', { count: shown.length - 500 })) + '</p>' : '');
            } else if (!r.errors.length) {
                html += '<p class="cc-summary">' + esc(T('import_nothing')) + '</p>';
            }
            $('ccImportStep2').innerHTML = html;
            const nothing = !c.create && !c.update && !c.deactivate;
            $('ccApply').disabled = r.errors.length > 0 || nothing;
        }

        $('ccPreview').addEventListener('click', async function () {
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
        $('ccApply').addEventListener('click', async function () {
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
    <script src="../../assets/js/mobile.js?v=71"></script>
</body>
</html>
