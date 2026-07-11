<?php
/**
 * System - Module Access Management (issue #30)
 * Team- and analyst-level module access: a module-centric summary, a per-module
 * edit modal, the most/least conflict-policy toggle, and an effective-access tool.
 * Backed by api/system/get_module_access.php + save_module_grants.php +
 * save_module_permission_mode.php + get_effective_module_access.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'modules';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.modules.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .main-container { flex: 1; background: var(--bg, #f5f7fa); overflow-y: auto; }
        .modules-container { flex: 1; min-width: 0; overflow-y: auto; padding: 28px 32px 80px; box-sizing: border-box; }
        .page-title { font-size: 24px; font-weight: 700; color: var(--text, #1a1a1a); margin: 0 0 4px; }
        .page-subtitle { color: var(--text-muted, #666); margin: 0 0 22px; }

        .panel { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .panel > h2 { font-size: 15px; margin: 0 0 12px; color: var(--text, #333); }

        .mode-opt { display: block; margin: 8px 0; cursor: pointer; color: var(--text, #333); }
        .mode-opt input { margin-right: 8px; }
        .strict-explainer { margin-top: 12px; padding: 12px 14px; border-radius: 8px; background: #fff3e0; border: 1px solid #ffcc80; color: #7c2d12; font-size: 13px; line-height: 1.55; }

        .eff-tool select { padding: 8px 10px; border: 1px solid var(--border, #ddd); border-radius: 6px; font-size: 14px; min-width: 260px; margin-left: 8px; }
        .eff-head { margin: 12px 0 6px; font-size: 13px; color: var(--text-muted, #666); }
        .eff-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .eff-table td { padding: 6px 10px; border-bottom: 1px solid var(--border, #eee); vertical-align: top; }
        .eff-table td:first-child { font-weight: 600; width: 22%; }
        .eff-table .yes { color: #2e7d32; font-weight: 600; white-space: nowrap; }
        .eff-table .no  { color: #c62828; font-weight: 600; white-space: nowrap; }
        .eff-table .reason { color: var(--text-muted, #666); }

        table.access-table { width: 100%; border-collapse: collapse; background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .access-table th { text-align: left; padding: 12px 16px; background: var(--surface-alt, #f8fafc); font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted, #667); border-bottom: 1px solid var(--border, #e5e7eb); }
        .access-table td { padding: 12px 16px; border-bottom: 1px solid var(--border, #f0f0f0); vertical-align: middle; }
        .mod-row { cursor: pointer; transition: background 0.12s; }
        .mod-row:hover { background: var(--surface-hover, #f5f8ff); }

        .pill { display: inline-block; padding: 3px 10px; margin: 2px; border-radius: 14px; font-size: 12px; background: #e8f0fe; color: #1565c0; }
        .pill-all { background: #ede7f6; color: #5e35b1; }
        .pill-more { background: #eceff1; color: #546e7a; cursor: pointer; }
        .pill-none { color: var(--text-faint, #999); font-size: 12px; }

        .chk-row { display: block; padding: 6px 4px; cursor: pointer; border-bottom: 1px solid var(--border, #f2f2f2); }
        .chk-row input { margin-right: 8px; }
        #moduleModalBody h4 { margin: 14px 0 4px; font-size: 13px; color: var(--text-muted, #667); text-transform: uppercase; letter-spacing: 0.4px; }

        .lvl-filter { width: 100%; max-width: 320px; padding: 7px 10px; border: 1px solid var(--border, #ddd); border-radius: 6px; font-size: 13px; margin: 4px 0 14px; box-sizing: border-box; }
        .lvl-cols { display: flex; gap: 20px; flex-wrap: wrap; }
        .lvl-col { flex: 1; min-width: 260px; }
        .lvl-col h4 { margin: 0 0 6px; font-size: 12px; color: var(--text-muted, #667); text-transform: uppercase; letter-spacing: 0.4px; }
        .lvl-list { max-height: 260px; overflow-y: auto; border: 1px solid var(--border, #eee); border-radius: 8px; }
        .lvl-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 12px; border-bottom: 1px solid var(--border, #f2f2f2); }
        .lvl-row:last-child { border-bottom: none; }
        .lvl-name { font-size: 13px; color: var(--text, #333); }
        .lvl-tag { font-size: 11px; color: #c62828; margin-left: 6px; }
        .lvl-empty { padding: 14px; text-align: center; color: var(--text-faint, #999); font-size: 13px; }

        .loading-spinner { text-align: center; color: var(--text-muted, #666); padding: 30px; }
        .toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(120px); background: #333; color: #fff; padding: 12px 22px; border-radius: 8px; font-size: 14px; z-index: 3000; transition: transform 0.3s; }
        .toast.show { transform: translateX(-50%) translateY(0); }
        .toast.success { background: #2e7d32; } .toast.error { background: #d32f2f; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="modules-container">
            <h1 class="page-title"><?php echo htmlspecialchars(t('system.modules.title')); ?></h1>
            <p class="page-subtitle">Control which modules teams and analysts can use. Grant a module to a whole team, or to individuals. (System access is separate — set the Administrator flag on the Analysts screen.)</p>

            <!-- Conflict policy -->
            <div class="panel">
                <h2>When grants conflict</h2>
                <label class="mode-opt"><input type="radio" name="permMode" value="most" onchange="setMode('most')"> <strong>Most permissive</strong> &mdash; an analyst can use a module if <em>any</em> of their teams, or their own access, grants it. (Recommended default.)</label>
                <label class="mode-opt"><input type="radio" name="permMode" value="least" onchange="setMode('least')"> <strong>Least permissive (strict)</strong> &mdash; an analyst can use a module only if their own access <em>and every team they're in</em> grant it.</label>
                <div id="strictExplainer" class="strict-explainer" style="display:none;">
                    <strong>&#9888; Strict mode is ON.</strong> An analyst can open a module <strong>only if their own access and every team they belong to allow it</strong>. Granting a module to a person will <strong>not</strong> give them access if any of their teams lacks it &mdash; and a team with <strong>no modules</strong> granted removes access to <strong>everything</strong> for its members. Use the effective-access checker below to see exactly what anyone can reach.
                </div>
            </div>

            <!-- Access level: grant / revoke all-module access without leaving this screen -->
            <div class="panel">
                <h2>Access level</h2>
                <p style="color:var(--text-muted,#666);font-size:13px;margin:0 0 8px;">Turn <strong>all modules</strong> on or off for a team or analyst right here. <strong>Off</strong> = restrict them to specific modules, which you then grant in the table below.</p>
                <input type="text" id="lvlFilter" class="lvl-filter" placeholder="Filter by name&hellip;" onkeyup="renderAccessLevels()">
                <div class="lvl-cols">
                    <div class="lvl-col">
                        <h4>Teams</h4>
                        <div class="lvl-list" id="lvlTeams"></div>
                    </div>
                    <div class="lvl-col">
                        <h4>Analysts</h4>
                        <div class="lvl-list" id="lvlAnalysts"></div>
                    </div>
                </div>
            </div>

            <!-- Effective access checker -->
            <div class="panel eff-tool">
                <h2>Effective access checker</h2>
                <label for="effAnalyst">Show what an analyst can actually reach, and why:</label>
                <select id="effAnalyst" onchange="loadEffective()"><option value="">&mdash; choose an analyst &mdash;</option></select>
                <div id="effResult"></div>
            </div>

            <div id="loading" class="loading-spinner">Loading&hellip;</div>
            <table class="access-table" id="summaryTable" style="display:none;">
                <thead><tr><th style="width:22%">Module</th><th>Teams with access</th><th>Analysts with access</th></tr></thead>
                <tbody id="summaryBody"></tbody>
            </table>
        </div>
    </div>

    <!-- Per-module edit modal — canonical 3-pane modal (header / body / footer) -->
    <div class="modal" id="moduleModal">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header" id="moduleModalTitle"></div>
            <div class="modal-body" id="moduleModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModuleModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveModuleModal()">Save</button>
            </div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script>
    const API = '../../api/system/';
    let DATA = { mode: 'most', modules: [], teams: [], analysts: [] };
    let editingModule = null;

    async function loadData() {
        try {
            const d = await (await fetch(API + 'get_module_access.php')).json();
            if (!d.success) throw new Error(d.error || 'load failed');
            DATA = d;
            const radio = document.querySelector(`input[name="permMode"][value="${d.mode}"]`);
            if (radio) radio.checked = true;
            document.getElementById('strictExplainer').style.display = d.mode === 'least' ? 'block' : 'none';
            document.getElementById('effAnalyst').innerHTML =
                '<option value="">— choose an analyst —</option>' +
                d.analysts.map(a => `<option value="${a.id}">${escapeHtml(a.name)}</option>`).join('');
            renderAccessLevels();
            renderSummary();
            document.getElementById('loading').style.display = 'none';
            document.getElementById('summaryTable').style.display = '';
        } catch (e) {
            document.getElementById('loading').textContent = 'Failed to load: ' + e.message;
        }
    }

    function grantedBy(list, key) { return list.filter(e => e.all_modules || (e.modules || []).includes(key)); }

    // Access-level lists: an all-modules toggle per team / analyst, editable in place.
    function renderAccessLevels() {
        const q = (document.getElementById('lvlFilter').value || '').toLowerCase();
        const row = (e, kind) => {
            if (q && !(e.name || '').toLowerCase().includes(q)) return '';
            const restricted = !Number(e.all_modules);
            return `<div class="lvl-row">
                <span class="lvl-name">${escapeHtml(e.name)}${restricted ? ' <span class="lvl-tag">restricted</span>' : ''}</span>
                <label class="toggle-switch"><input type="checkbox" ${restricted ? '' : 'checked'} onchange="toggleAllAccess('${kind}', ${e.id}, this.checked)"><span class="toggle-slider"></span></label>
            </div>`;
        };
        const fill = (id, arr, kind, empty) => {
            const html = arr.map(e => row(e, kind)).join('');
            document.getElementById(id).innerHTML = arr.length
                ? (html || '<div class="lvl-empty">No matches</div>')
                : `<div class="lvl-empty">${empty}</div>`;
        };
        fill('lvlTeams', DATA.teams, 'team', 'No teams');
        fill('lvlAnalysts', DATA.analysts, 'analyst', 'No analysts');
    }

    async function toggleAllAccess(kind, id, checked) {
        const d = await (await fetch(API + 'set_module_all_access.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ kind, id, all_modules: checked })
        })).json();
        if (!d.success) { showToast('Failed: ' + (d.error || ''), 'error'); renderAccessLevels(); return; }
        const e = (kind === 'team' ? DATA.teams : DATA.analysts).find(x => Number(x.id) === Number(id));
        if (e) e.all_modules = checked ? 1 : 0;
        renderSummary();
        renderAccessLevels();
        if (document.getElementById('effAnalyst').value) loadEffective();
        showToast(checked ? 'Granted all modules' : 'Restricted — grant modules in the table below', 'success');
    }

    function pills(items) {
        if (!items.length) return '<span class="pill-none">None</span>';
        const shown = items.slice(0, 3), extra = items.length - 3;
        let html = shown.map(e => `<span class="pill ${e.all_modules ? 'pill-all' : ''}"${e.all_modules ? ' title="Has all modules"' : ''}>${escapeHtml(e.name)}${e.all_modules ? ' &#10022;' : ''}</span>`).join('');
        if (extra > 0) html += `<span class="pill pill-more">+${extra}</span>`;
        return html;
    }

    function renderSummary() {
        document.getElementById('summaryBody').innerHTML = DATA.modules.map(m => `
            <tr class="mod-row" onclick="openModuleModal('${m.key}')">
                <td><strong>${escapeHtml(m.name)}</strong></td>
                <td>${pills(grantedBy(DATA.teams, m.key))}</td>
                <td>${pills(grantedBy(DATA.analysts, m.key))}</td>
            </tr>`).join('');
    }

    async function setMode(mode) {
        const d = await (await fetch(API + 'save_module_permission_mode.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ mode })
        })).json();
        if (d.success) {
            DATA.mode = d.mode;
            document.getElementById('strictExplainer').style.display = d.mode === 'least' ? 'block' : 'none';
            showToast('Conflict policy updated', 'success');
            if (document.getElementById('effAnalyst').value) loadEffective();
        } else showToast('Failed: ' + (d.error || ''), 'error');
    }

    function openModuleModal(key) {
        editingModule = key;
        const m = DATA.modules.find(x => x.key === key);
        document.getElementById('moduleModalTitle').textContent = 'Who can use ' + m.name + '?';
        const rows = (arr, kind) => (arr.length ? arr.map(e => {
            const has = e.all_modules || (e.modules || []).includes(key);
            const note = e.all_modules ? ' <span style="color:#5e35b1;">(all modules)</span>' : '';
            return `<label class="chk-row"><input type="checkbox" data-kind="${kind}" data-id="${e.id}" ${has ? 'checked' : ''} ${e.all_modules ? 'disabled' : ''}> ${escapeHtml(e.name)}${note}</label>`;
        }).join('') : '<p class="pill-none">None</p>');
        document.getElementById('moduleModalBody').innerHTML =
            `<p style="color:var(--text-muted,#666);font-size:13px;margin:0 0 6px;">Tick the teams and analysts that can use <strong>${escapeHtml(m.name)}</strong>. Anyone with <em>all modules</em> always has access &mdash; flip that with the <strong>Access level</strong> toggles above.</p>
             <h4>Teams</h4>${rows(DATA.teams, 'team')}
             <h4>Analysts</h4>${rows(DATA.analysts, 'analyst')}`;
        document.getElementById('moduleModal').classList.add('active');
    }
    function closeModuleModal() { document.getElementById('moduleModal').classList.remove('active'); }

    async function saveModuleModal() {
        const team_ids = [], analyst_ids = [];
        document.querySelectorAll('#moduleModalBody input[type=checkbox]:not([disabled])').forEach(c => {
            if (c.checked) (c.dataset.kind === 'team' ? team_ids : analyst_ids).push(parseInt(c.dataset.id));
        });
        const d = await (await fetch(API + 'save_module_grants.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ module_key: editingModule, team_ids, analyst_ids })
        })).json();
        if (d.success) { closeModuleModal(); showToast('Saved', 'success'); loadData(); }
        else showToast('Failed: ' + (d.error || ''), 'error');
    }

    async function loadEffective() {
        const id = document.getElementById('effAnalyst').value;
        const box = document.getElementById('effResult');
        if (!id) { box.innerHTML = ''; return; }
        box.innerHTML = '<div class="eff-head">Loading…</div>';
        const d = await (await fetch(API + 'get_effective_module_access.php?analyst_id=' + id)).json();
        if (!d.success) { box.innerHTML = 'Failed: ' + escapeHtml(d.error || ''); return; }
        box.innerHTML =
            `<div class="eff-head">Conflict policy: <strong>${d.mode === 'least' ? 'Least permissive (strict)' : 'Most permissive'}</strong></div>` +
            '<table class="eff-table">' + d.modules.map(m =>
                `<tr><td>${escapeHtml(m.name)}</td><td>${m.allowed ? '<span class="yes">&#10003; Access</span>' : '<span class="no">&#10007; No access</span>'}</td><td class="reason">${escapeHtml(m.reason)}</td></tr>`
            ).join('') + '</table>';
    }

    function showToast(msg, type) {
        let t = document.getElementById('toast');
        if (!t) { t = document.createElement('div'); t.id = 'toast'; t.className = 'toast'; document.body.appendChild(t); }
        t.textContent = msg;
        t.className = 'toast ' + (type || '') + ' show';
        setTimeout(() => { t.className = 'toast ' + (type || ''); }, 2500);
    }

    function escapeHtml(str) { const d = document.createElement('div'); d.textContent = str || ''; return d.innerHTML; }

    loadData();
    </script>
</body>
</html>
