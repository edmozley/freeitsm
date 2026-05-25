<?php
/**
 * Change Management Settings - Configure module behaviour
 */
session_start();
require_once '../../config.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Change management settings</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: none;
            margin: 0;
            padding: 16px 30px 24px;
        }

        /* Teal theme for tabs */
        .tab:hover { color: #00897b; }
        .tab.active { color: #00897b; border-bottom-color: #00897b; }

        .section-header h2 {
            margin: 0 0 8px;
            font-size: 18px;
            color: #333;
        }

        /* Form fields tab: section cards + draggable field rows. */
        .field-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .field-toolbar .field-save-status {
            font-size: 12px;
            color: #16a34a;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .field-toolbar .field-save-status.visible {
            opacity: 1;
        }
        .section-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            margin-bottom: 12px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
        }
        .section-card.drop-target-section {
            border-color: #00897b;
            box-shadow: 0 0 0 2px rgba(0, 137, 123, 0.15);
        }
        .section-card.dragging {
            opacity: 0.4;
        }
        .section-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid #f0f0f0;
            background: #fafafa;
            border-radius: 6px 6px 0 0;
        }
        .section-card-header .drag-handle {
            cursor: grab;
            color: #999;
            font-size: 14px;
            user-select: none;
            padding: 4px;
        }
        .section-card-header .drag-handle:active { cursor: grabbing; }
        .section-name-input {
            flex: 1;
            font-size: 15px;
            font-weight: 600;
            color: #00897b;
            border: 1px solid transparent;
            background: transparent;
            padding: 6px 10px;
            border-radius: 4px;
        }
        .section-name-input:hover {
            border-color: #e0e0e0;
            background: #fff;
        }
        .section-name-input:focus {
            outline: none;
            border-color: #00897b;
            background: #fff;
            box-shadow: 0 0 0 2px rgba(0, 137, 123, 0.1);
        }
        .section-delete-btn {
            background: none;
            border: 1px solid transparent;
            color: #999;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 16px;
            line-height: 1;
        }
        .section-delete-btn:hover {
            color: #c62828;
            border-color: #fce4e4;
            background: #fff5f5;
        }
        .section-fields {
            padding: 4px 14px 8px;
            min-height: 36px;
        }
        .section-fields:empty::after {
            content: 'Drop fields here';
            display: block;
            padding: 16px;
            text-align: center;
            color: #aaa;
            font-style: italic;
            font-size: 13px;
        }
        .field-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 6px;
            border-bottom: 1px solid #f3f3f3;
        }
        .field-row:last-child {
            border-bottom: none;
        }
        .field-row.dragging {
            opacity: 0.4;
        }
        .field-row.drop-target-field {
            border-top: 2px solid #00897b;
        }
        .field-row .drag-handle {
            cursor: grab;
            color: #bbb;
            font-size: 13px;
            user-select: none;
            padding: 2px 4px;
        }
        .field-row .drag-handle:active { cursor: grabbing; }
        .field-row-label {
            flex: 1;
            font-size: 14px;
            color: #333;
        }
        .unplaced-fields {
            margin-top: 20px;
            border: 1px dashed #e0a800;
            background: #fffbe6;
            border-radius: 6px;
            padding: 10px 14px;
        }
        .unplaced-fields h4 {
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 600;
            color: #856404;
        }
        .unplaced-fields p.hint {
            margin: 0 0 8px;
            font-size: 12px;
            color: #856404;
        }
        .add-section-btn {
            background: #00897b;
            color: white;
            border: none;
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
        }
        .add-section-btn:hover {
            background: #00695c;
        }

        /* Toggle switch */
        .toggle-switch {
            position: relative;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #ccc;
            border-radius: 24px;
            transition: background 0.2s;
        }

        .toggle-slider:before {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: transform 0.2s;
        }

        .toggle-switch input:checked + .toggle-slider {
            background: #00897b;
        }

        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(20px);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .btn-primary { background: #00897b; color: white; }
        .btn-primary:hover { background: #00695c; }
        .btn-secondary { background: #e0e0e0; color: #333; }
        .btn-secondary:hover { background: #bdbdbd; }

        /* Lookup tab tables */
        .lookup-table { width: 100%; border-collapse: collapse; }
        .lookup-table th, .lookup-table td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .lookup-table th { font-weight: 600; color: #666; background: #fafafa; }
        .badge-yes { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #e0f2f1; color: #00695c; font-size: 11px; font-weight: 600; }
        .badge-no  { color: #999; }
        .badge-active   { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #e0f2f1; color: #00695c; font-size: 11px; font-weight: 600; }
        .badge-inactive { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #fafafa; color: #999;   font-size: 11px; font-weight: 600; }
        .swatch { display: inline-block; width: 18px; height: 18px; border-radius: 3px; vertical-align: middle; border: 1px solid #ddd; margin-right: 6px; }
        .action-btn { background: none; border: none; cursor: pointer; padding: 4px; color: #666; display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; }
        /* Force the Actions column to size to its content (width: 1%) and
           never wrap the icon buttons. width:1% + white-space:nowrap is the
           classic trick to collapse a table cell to exactly its content
           width regardless of how wide the table is. */
        .lookup-table td:last-child,
        .lookup-table th:last-child {
            white-space: nowrap;
            width: 1%;
        }
        .action-btn:hover { color: #00897b; }
        .action-btn.delete:hover { color: #c62828; }
        .add-btn { background: #00897b; color: white; padding: 8px 16px; border: none; border-radius: 4px; font-size: 13px; cursor: pointer; }
        .add-btn:hover { background: #00695c; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }

        /* Modal */
        .lk-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1000; }
        .lk-modal.active { display: flex; align-items: center; justify-content: center; }
        .lk-modal-content { background: white; border-radius: 8px; padding: 24px; width: 100%; max-width: 480px; }
        .lk-modal-header { font-size: 18px; font-weight: 600; margin-bottom: 16px; }
        .lk-form-group { margin-bottom: 14px; }
        .lk-form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 4px; }
        .lk-form-group input[type="text"], .lk-form-group input[type="number"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .lk-form-group .help { display: block; font-size: 12px; color: #888; margin-top: 4px; }
        .lk-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="fields" onclick="switchTab('fields')">Form fields</button>
            <button class="tab" data-tab="statuses" onclick="switchTab('statuses')">Statuses</button>
            <button class="tab" data-tab="priorities" onclick="switchTab('priorities')">Priorities</button>
            <button class="tab" data-tab="types" onclick="switchTab('types')">Types</button>
            <button class="tab" data-tab="impacts" onclick="switchTab('impacts')">Impacts</button>
        </div>

        <!-- Form Fields Tab -->
        <div class="tab-content active" id="fields-tab">
            <div class="section-header">
                <h2>Form fields</h2>
            </div>
            <p style="color: #666; margin-bottom: 16px;">Drag to reorder sections and fields, drag fields between sections, click a section name to rename. Sections with no visible fields are hidden on the change form. Changes save automatically.</p>

            <div class="field-toolbar">
                <button type="button" class="add-section-btn" onclick="addSection()">+ Add section</button>
                <span class="field-save-status" id="fieldSaveStatus">Saved</span>
            </div>

            <div id="fieldSettings"></div>
        </div>

        <!-- Statuses Tab -->
        <div class="tab-content" id="statuses-tab">
            <div class="section-header">
                <h2>Statuses</h2>
                <button class="add-btn" onclick="openLookupModal('status')">Add</button>
            </div>
            <p style="color: #666; margin-bottom: 16px;">Workflow states a change can be in. Statuses flagged as <em>Closed</em> count as terminal — used by reports and watchtower counters. Exactly one status is the default for new changes.</p>
            <table class="lookup-table">
                <thead><tr><th>Name</th><th>Colour</th><th>Closed</th><th>Default</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="statuses-list"><tr><td colspan="7" style="text-align:center;">Loading...</td></tr></tbody>
            </table>
        </div>

        <!-- Priorities Tab -->
        <div class="tab-content" id="priorities-tab">
            <div class="section-header">
                <h2>Priorities</h2>
                <button class="add-btn" onclick="openLookupModal('priority')">Add</button>
            </div>
            <p style="color: #666; margin-bottom: 16px;">Priority bands shown on change records. Exactly one priority is the default for new changes.</p>
            <table class="lookup-table">
                <thead><tr><th>Name</th><th>Colour</th><th>Default</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="priorities-list"><tr><td colspan="6" style="text-align:center;">Loading...</td></tr></tbody>
            </table>
        </div>

        <!-- Types Tab -->
        <div class="tab-content" id="types-tab">
            <div class="section-header">
                <h2>Change types</h2>
                <button class="add-btn" onclick="openLookupModal('type')">Add</button>
            </div>
            <p style="color: #666; margin-bottom: 16px;">Categories of change request — typically Standard, Normal, Emergency. Exactly one type is the default for new changes.</p>
            <table class="lookup-table">
                <thead><tr><th>Name</th><th>Colour</th><th>Default</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="types-list"><tr><td colspan="6" style="text-align:center;">Loading...</td></tr></tbody>
            </table>
        </div>

        <!-- Impacts Tab -->
        <div class="tab-content" id="impacts-tab">
            <div class="section-header">
                <h2>Impacts</h2>
                <button class="add-btn" onclick="openLookupModal('impact')">Add</button>
            </div>
            <p style="color: #666; margin-bottom: 16px;">Impact bands describing how widely a change affects the business. Combines with priority to drive risk scoring.</p>
            <table class="lookup-table">
                <thead><tr><th>Name</th><th>Colour</th><th>Default</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="impacts-list"><tr><td colspan="6" style="text-align:center;">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>

    <!-- Lookup edit modal (shared by all four tabs) -->
    <div class="lk-modal" id="lookupModal">
        <div class="lk-modal-content">
            <div class="lk-modal-header" id="lookupModalTitle">Add item</div>
            <form id="lookupForm">
                <input type="hidden" id="lookupItemKind">
                <input type="hidden" id="lookupItemId">

                <div class="lk-form-group">
                    <label for="lookupItemName">Name</label>
                    <input type="text" id="lookupItemName" required>
                </div>

                <div class="lk-form-group">
                    <label for="lookupItemColour">Colour</label>
                    <input type="color" id="lookupItemColour" value="#2563eb" style="width: 60px; height: 32px; padding: 2px;">
                    <span class="help">Used for badges in lists, dashboards and the calendar.</span>
                </div>

                <div class="lk-form-group" id="lookupItemClosedGroup" style="display: none;">
                    <label><input type="checkbox" id="lookupItemClosed"> Counts as closed</label>
                    <span class="help">Changes in this status are treated as terminal — excluded from open-queue counts.</span>
                </div>

                <div class="lk-form-group">
                    <label><input type="checkbox" id="lookupItemDefault"> Default for new changes</label>
                    <span class="help">Only one row can be the default — setting this clears the flag on the others.</span>
                </div>

                <div class="lk-form-group">
                    <label for="lookupItemOrder">Display order</label>
                    <input type="number" id="lookupItemOrder" value="0">
                </div>

                <div class="lk-form-group">
                    <label><input type="checkbox" id="lookupItemActive" checked> Active</label>
                </div>

                <div class="lk-modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeLookupModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast notification -->
    <div class="toast" id="toast"></div>

    <script>
        const API_BASE = '../../api/change-management/';

        // Layout state — loaded from get_field_layout.php; mutated locally
        // on every UI action and then auto-saved to save_field_layout.php.
        // Shape:
        //   sections: [{ id, name, display_order }]
        //   fields:   [{ key, label, section_id, display_order, is_visible }]
        //   unplaced: [{ key, label }]  // catalogue keys with no layout row
        // Section ids < 0 are tempIds for locally-created sections that
        // haven't been saved yet — the API resolves them on save and
        // returns real ids in the response.
        let layout = { sections: [], fields: [], unplaced: [] };
        let nextTempSectionId = -1;

        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadSettings();
            loadLookups();
        });

        // ============================================================
        // Lookup tabs (Statuses / Priorities / Types / Impacts)
        // ============================================================

        // Per-kind metadata: API filenames, response key, table id, column count, fields shown.
        const LOOKUP_KINDS = {
            'status':   { get: 'get_change_statuses.php',   save: 'save_change_status.php',   del: 'delete_change_status.php',   listKey: 'statuses',   tableId: 'statuses-list',   colspan: 7, hasClosed: true,  label: 'status'  },
            'priority': { get: 'get_change_priorities.php', save: 'save_change_priority.php', del: 'delete_change_priority.php', listKey: 'priorities', tableId: 'priorities-list', colspan: 6, hasClosed: false, label: 'priority'},
            'type':     { get: 'get_change_types.php',      save: 'save_change_type.php',     del: 'delete_change_type.php',     listKey: 'types',      tableId: 'types-list',      colspan: 6, hasClosed: false, label: 'type'    },
            'impact':   { get: 'get_change_impacts.php',    save: 'save_change_impact.php',   del: 'delete_change_impact.php',   listKey: 'impacts',    tableId: 'impacts-list',    colspan: 6, hasClosed: false, label: 'impact'  }
        };

        const lookupCache = { status: [], priority: [], type: [], impact: [] };

        async function loadLookups() {
            for (const kind of Object.keys(LOOKUP_KINDS)) {
                await loadLookup(kind);
            }
        }

        async function loadLookup(kind) {
            const cfg = LOOKUP_KINDS[kind];
            try {
                const res = await fetch(API_BASE + cfg.get);
                const data = await res.json();
                if (data.success) {
                    lookupCache[kind] = data[cfg.listKey] || [];
                    renderLookup(kind);
                }
            } catch (e) {
                console.error(e);
            }
        }

        function renderLookup(kind) {
            const cfg = LOOKUP_KINDS[kind];
            const rows = lookupCache[kind];
            const tbody = document.getElementById(cfg.tableId);
            if (!rows || rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="${cfg.colspan}" style="text-align:center;">No items found</td></tr>`;
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const safeName = escapeHtml(r.name).replace(/'/g, "\\'");
                const swatch = r.colour
                    ? `<span class="swatch" style="background:${escapeHtml(r.colour)};"></span><code style="font-size:12px;">${escapeHtml(r.colour)}</code>`
                    : '<span class="badge-no">—</span>';
                const closedCol = cfg.hasClosed
                    ? `<td>${r.is_closed ? '<span class="badge-yes">Yes</span>' : '<span class="badge-no">No</span>'}</td>`
                    : '';
                return `
                <tr>
                    <td><strong>${escapeHtml(r.name)}</strong></td>
                    <td>${swatch}</td>
                    ${closedCol}
                    <td>${r.is_default ? '<span class="badge-yes">Yes</span>' : '<span class="badge-no">No</span>'}</td>
                    <td>${r.display_order}</td>
                    <td><span class="${r.is_active ? 'badge-active' : 'badge-inactive'}">${r.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editLookup('${kind}', ${r.id})" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteLookup('${kind}', ${r.id}, '${safeName}')" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        function escapeHtml(text) {
            if (text == null) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function openLookupModal(kind) {
            const cfg = LOOKUP_KINDS[kind];
            document.getElementById('lookupModalTitle').textContent = `Add ${cfg.label}`;
            document.getElementById('lookupItemKind').value = kind;
            document.getElementById('lookupItemId').value = '';
            document.getElementById('lookupItemName').value = '';
            document.getElementById('lookupItemColour').value = '#2563eb';
            document.getElementById('lookupItemClosed').checked = false;
            document.getElementById('lookupItemDefault').checked = false;
            document.getElementById('lookupItemOrder').value = '0';
            document.getElementById('lookupItemActive').checked = true;
            document.getElementById('lookupItemClosedGroup').style.display = cfg.hasClosed ? '' : 'none';
            document.getElementById('lookupModal').classList.add('active');
        }

        function editLookup(kind, id) {
            const cfg = LOOKUP_KINDS[kind];
            const item = (lookupCache[kind] || []).find(r => r.id == id);
            if (!item) return;
            document.getElementById('lookupModalTitle').textContent = `Edit ${cfg.label}`;
            document.getElementById('lookupItemKind').value = kind;
            document.getElementById('lookupItemId').value = item.id;
            document.getElementById('lookupItemName').value = item.name;
            document.getElementById('lookupItemColour').value = item.colour || '#2563eb';
            document.getElementById('lookupItemClosed').checked = !!item.is_closed;
            document.getElementById('lookupItemDefault').checked = !!item.is_default;
            document.getElementById('lookupItemOrder').value = item.display_order;
            document.getElementById('lookupItemActive').checked = !!item.is_active;
            document.getElementById('lookupItemClosedGroup').style.display = cfg.hasClosed ? '' : 'none';
            document.getElementById('lookupModal').classList.add('active');
        }

        function closeLookupModal() {
            document.getElementById('lookupModal').classList.remove('active');
        }

        async function deleteLookup(kind, id, name) {
            if (!confirm(`Delete "${name}"?`)) return;
            const cfg = LOOKUP_KINDS[kind];
            try {
                const res = await fetch(API_BASE + cfg.del, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id: id})
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Deleted');
                    loadLookup(kind);
                } else {
                    showToast(data.error || 'Failed to delete', true);
                }
            } catch (e) {
                showToast('Failed to delete', true);
            }
        }

        document.getElementById('lookupForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const kind = document.getElementById('lookupItemKind').value;
            const cfg = LOOKUP_KINDS[kind];
            const payload = {
                id: document.getElementById('lookupItemId').value || null,
                name: document.getElementById('lookupItemName').value,
                colour: document.getElementById('lookupItemColour').value,
                is_default: document.getElementById('lookupItemDefault').checked ? 1 : 0,
                display_order: parseInt(document.getElementById('lookupItemOrder').value || '0', 10),
                is_active: document.getElementById('lookupItemActive').checked ? 1 : 0
            };
            if (cfg.hasClosed) payload.is_closed = document.getElementById('lookupItemClosed').checked ? 1 : 0;

            try {
                const res = await fetch(API_BASE + cfg.save, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    closeLookupModal();
                    showToast('Saved');
                    loadLookup(kind);
                } else {
                    showToast(data.error || 'Failed to save', true);
                }
            } catch (e) {
                showToast('Failed to save', true);
            }
        });

        // ============================================================
        // Form fields tab — load / render / auto-save
        // ============================================================
        async function loadSettings() {
            try {
                const res = await fetch(API_BASE + 'get_field_layout.php');
                const data = await res.json();
                if (data.success) {
                    layout.sections = data.sections || [];
                    layout.fields   = data.fields   || [];
                    layout.unplaced = data.unplaced || [];
                }
            } catch (e) {
                console.error('Failed to load field layout:', e);
            }
            renderFieldSettings();
        }

        function renderFieldSettings() {
            const container = document.getElementById('fieldSettings');
            const sections = [...layout.sections].sort((a, b) => a.display_order - b.display_order);

            let html = '';
            sections.forEach(section => {
                const fieldsInSection = layout.fields
                    .filter(f => f.section_id === section.id)
                    .sort((a, b) => a.display_order - b.display_order);

                html += `
                    <div class="section-card" data-section-id="${section.id}" draggable="true">
                        <div class="section-card-header">
                            <span class="drag-handle" title="Drag to reorder section">⋮⋮</span>
                            <input type="text" class="section-name-input"
                                   value="${escapeAttr(section.name)}"
                                   data-section-id="${section.id}"
                                   onblur="renameSection(${section.id}, this.value)"
                                   onkeydown="if (event.key === 'Enter') this.blur();">
                            <button type="button" class="section-delete-btn"
                                    title="Delete section"
                                    onclick="deleteSection(${section.id})">&times;</button>
                        </div>
                        <div class="section-fields" data-section-id="${section.id}">
                            ${fieldsInSection.map(f => renderFieldRow(f)).join('')}
                        </div>
                    </div>
                `;
            });

            // Unplaced fields — catalogue entries that have no layout row yet
            // (e.g. a newly-added field key or fields orphaned by a deleted
            // section). The admin needs to drag them into a section before
            // they appear on the change form.
            if (layout.unplaced.length > 0) {
                html += `
                    <div class="unplaced-fields">
                        <h4>Unplaced fields</h4>
                        <p class="hint">These fields aren't in any section yet, so they won't appear on the change form. Drag them into a section above.</p>
                        ${layout.unplaced.map(f => renderFieldRow({
                            key: f.key, label: f.label,
                            section_id: null, display_order: 0, is_visible: true
                        }, true)).join('')}
                    </div>
                `;
            }

            container.innerHTML = html;
            wireDragAndDrop();
        }

        function renderFieldRow(field, isUnplaced) {
            return `
                <div class="field-row" data-field-key="${field.key}" draggable="true">
                    <span class="drag-handle" title="Drag to reorder">⋮⋮</span>
                    <span class="field-row-label">${escapeHtml(field.label)}</span>
                    ${isUnplaced ? '' : `
                        <label class="toggle-switch" title="Show / hide this field on the change form">
                            <input type="checkbox" ${field.is_visible ? 'checked' : ''}
                                   onchange="toggleFieldVisibility('${field.key}', this.checked)">
                            <span class="toggle-slider"></span>
                        </label>
                    `}
                </div>
            `;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : text;
            return div.innerHTML;
        }
        function escapeAttr(text) {
            return escapeHtml(text).replace(/"/g, '&quot;');
        }

        // ----- State mutations (each triggers an auto-save) -----

        function addSection() {
            const id = nextTempSectionId--;
            const maxOrder = layout.sections.reduce((m, s) => Math.max(m, s.display_order), 0);
            layout.sections.push({ id, name: 'New section', display_order: maxOrder + 10 });
            renderFieldSettings();
            // Focus the new name input so the user can rename immediately
            requestAnimationFrame(() => {
                const input = document.querySelector(`input.section-name-input[data-section-id="${id}"]`);
                if (input) { input.focus(); input.select(); }
            });
            scheduleAutoSave();
        }

        function renameSection(sectionId, newName) {
            const section = layout.sections.find(s => s.id === sectionId);
            if (!section) return;
            const trimmed = (newName || '').trim();
            if (!trimmed || trimmed === section.name) return;
            section.name = trimmed;
            scheduleAutoSave();
        }

        async function deleteSection(sectionId) {
            const section = layout.sections.find(s => s.id === sectionId);
            if (!section) return;
            const fieldsInSection = layout.fields.filter(f => f.section_id === sectionId);
            if (fieldsInSection.length > 0) {
                const msg = `Delete "${section.name}"?\n\n${fieldsInSection.length} field${fieldsInSection.length === 1 ? '' : 's'} will become unplaced and won't appear on the change form until you drag ${fieldsInSection.length === 1 ? 'it' : 'them'} into another section.`;
                if (!confirm(msg)) return;
            } else if (!confirm(`Delete "${section.name}"?`)) {
                return;
            }
            // Locally: remove the section and the fields-in-section. The
            // fields then re-surface as "unplaced" after the server save.
            layout.sections = layout.sections.filter(s => s.id !== sectionId);
            const orphaned = layout.fields.filter(f => f.section_id === sectionId);
            layout.fields = layout.fields.filter(f => f.section_id !== sectionId);
            orphaned.forEach(f => layout.unplaced.push({ key: f.key, label: f.label }));
            renderFieldSettings();
            scheduleAutoSave();
        }

        function toggleFieldVisibility(fieldKey, isVisible) {
            const field = layout.fields.find(f => f.key === fieldKey);
            if (!field) return;
            field.is_visible = !!isVisible;
            scheduleAutoSave();
        }

        // ----- Drag-and-drop wiring -----
        //
        // Two interactions:
        //   - Drag a .section-card by its header (drag handle) to reorder sections
        //   - Drag a .field-row by its handle to reorder within a section
        //     or move it between sections (including from / to "Unplaced")
        //
        // We use HTML5 native drag-and-drop. The dragged item carries its
        // type + identifier on dataTransfer; drop targets accept based on type.
        let draggedSectionId = null;
        let draggedFieldKey  = null;

        function wireDragAndDrop() {
            // Section drag
            document.querySelectorAll('.section-card').forEach(card => {
                card.addEventListener('dragstart', e => {
                    // Don't initiate section drag if the user is dragging a field row
                    if (e.target.closest('.field-row')) {
                        e.stopPropagation();
                        return;
                    }
                    draggedSectionId = parseInt(card.dataset.sectionId, 10);
                    draggedFieldKey = null;
                    card.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', `section:${draggedSectionId}`);
                });
                card.addEventListener('dragend', () => {
                    card.classList.remove('dragging');
                    document.querySelectorAll('.section-card').forEach(c =>
                        c.classList.remove('drop-target-section'));
                });
                card.addEventListener('dragover', e => {
                    if (draggedSectionId == null) return;
                    if (parseInt(card.dataset.sectionId, 10) === draggedSectionId) return;
                    e.preventDefault();
                    document.querySelectorAll('.section-card').forEach(c =>
                        c.classList.remove('drop-target-section'));
                    card.classList.add('drop-target-section');
                });
                card.addEventListener('drop', e => {
                    if (draggedSectionId == null) return;
                    e.preventDefault();
                    e.stopPropagation();
                    const targetId = parseInt(card.dataset.sectionId, 10);
                    if (targetId === draggedSectionId) return;
                    reorderSection(draggedSectionId, targetId);
                });
            });

            // Field-row drag
            document.querySelectorAll('.field-row').forEach(row => {
                row.addEventListener('dragstart', e => {
                    e.stopPropagation(); // Don't bubble up to section drag
                    draggedFieldKey = row.dataset.fieldKey;
                    draggedSectionId = null;
                    row.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', `field:${draggedFieldKey}`);
                });
                row.addEventListener('dragend', () => {
                    row.classList.remove('dragging');
                    document.querySelectorAll('.field-row').forEach(r =>
                        r.classList.remove('drop-target-field'));
                });
                row.addEventListener('dragover', e => {
                    if (draggedFieldKey == null) return;
                    if (row.dataset.fieldKey === draggedFieldKey) return;
                    e.preventDefault();
                    document.querySelectorAll('.field-row').forEach(r =>
                        r.classList.remove('drop-target-field'));
                    row.classList.add('drop-target-field');
                });
                row.addEventListener('drop', e => {
                    if (draggedFieldKey == null) return;
                    e.preventDefault();
                    e.stopPropagation();
                    const targetKey = row.dataset.fieldKey;
                    if (targetKey === draggedFieldKey) return;
                    moveFieldBeforeField(draggedFieldKey, targetKey);
                });
            });

            // Drop a field on an empty section's body (so empty sections can
            // receive fields). Each section-fields container accepts drops.
            document.querySelectorAll('.section-fields').forEach(zone => {
                zone.addEventListener('dragover', e => {
                    if (draggedFieldKey == null) return;
                    e.preventDefault();
                });
                zone.addEventListener('drop', e => {
                    if (draggedFieldKey == null) return;
                    // Ignore if the drop landed on a child field-row — that
                    // row's own drop handler covers it.
                    if (e.target.closest('.field-row')) return;
                    e.preventDefault();
                    e.stopPropagation();
                    const sectionId = parseInt(zone.dataset.sectionId, 10);
                    moveFieldToSectionEnd(draggedFieldKey, sectionId);
                });
            });
        }

        function reorderSection(draggedId, targetId) {
            const sections = [...layout.sections].sort((a, b) => a.display_order - b.display_order);
            const dragged = sections.find(s => s.id === draggedId);
            const targetIdx = sections.findIndex(s => s.id === targetId);
            if (!dragged || targetIdx < 0) return;
            const without = sections.filter(s => s.id !== draggedId);
            without.splice(targetIdx, 0, dragged);
            without.forEach((s, i) => { s.display_order = (i + 1) * 10; });
            layout.sections = without;
            renderFieldSettings();
            scheduleAutoSave();
        }

        function moveFieldBeforeField(draggedKey, targetKey) {
            const dragged = layout.fields.find(f => f.key === draggedKey)
                          || promoteUnplacedToField(draggedKey);
            const target  = layout.fields.find(f => f.key === targetKey);
            if (!dragged || !target) return;
            dragged.section_id = target.section_id;
            // Insert dragged just before target by sliding the orders
            // around. Re-pack the whole section to keep numbers tidy.
            const inSection = layout.fields
                .filter(f => f.section_id === target.section_id && f.key !== draggedKey)
                .sort((a, b) => a.display_order - b.display_order);
            const targetIdx = inSection.findIndex(f => f.key === targetKey);
            inSection.splice(targetIdx, 0, dragged);
            inSection.forEach((f, i) => { f.display_order = (i + 1) * 10; });
            renderFieldSettings();
            scheduleAutoSave();
        }

        function moveFieldToSectionEnd(draggedKey, sectionId) {
            const dragged = layout.fields.find(f => f.key === draggedKey)
                          || promoteUnplacedToField(draggedKey);
            if (!dragged) return;
            dragged.section_id = sectionId;
            const inSection = layout.fields
                .filter(f => f.section_id === sectionId)
                .sort((a, b) => a.display_order - b.display_order);
            const maxOrder = inSection.length > 0
                ? Math.max(...inSection.map(f => f.display_order))
                : 0;
            dragged.display_order = maxOrder + 10;
            renderFieldSettings();
            scheduleAutoSave();
        }

        // Helper: when a field is dragged FROM the Unplaced list, it needs
        // to be promoted into layout.fields so the save endpoint sees it.
        function promoteUnplacedToField(fieldKey) {
            const idx = layout.unplaced.findIndex(f => f.key === fieldKey);
            if (idx < 0) return null;
            const meta = layout.unplaced.splice(idx, 1)[0];
            const newField = {
                key: meta.key,
                label: meta.label,
                section_id: 0,        // placeholder — caller will overwrite
                display_order: 0,
                is_visible: true,
            };
            layout.fields.push(newField);
            return newField;
        }

        // ----- Auto-save -----
        let autoSaveTimer = null;
        function scheduleAutoSave() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(saveLayout, 350);
        }

        async function saveLayout() {
            try {
                const payload = {
                    sections: layout.sections.map(s => ({
                        id: s.id,
                        name: s.name,
                        display_order: s.display_order,
                    })),
                    fields: layout.fields.map(f => ({
                        key: f.key,
                        section_id: f.section_id,
                        display_order: f.display_order,
                        is_visible: f.is_visible,
                    })),
                };
                const res = await fetch(API_BASE + 'save_field_layout.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!data.success) {
                    showToast('Save failed: ' + (data.error || 'unknown error'), true);
                    return;
                }
                // Replace local state with the server's authoritative copy.
                // This swaps any negative tempIds for real ones and ensures
                // unplaced is in sync with what's actually in the DB.
                const placedKeys = new Set((data.fields || []).map(f => f.key));
                layout.sections = data.sections || [];
                layout.fields = data.fields || [];
                layout.unplaced = layout.unplaced.filter(u => !placedKeys.has(u.key));
                // Plus any catalogue keys that fell out of layout.fields
                // (e.g. their section was deleted) should appear in unplaced.
                // The server already knows the catalogue — easier to just
                // re-fetch the full layout to refresh `unplaced`.
                fetch(API_BASE + 'get_field_layout.php')
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            layout.unplaced = d.unplaced || [];
                            renderFieldSettings();
                        }
                    });
                showSaveStatus();
            } catch (e) {
                console.error('Auto-save error:', e);
                showToast('Save failed: ' + e.message, true);
            }
        }

        function showSaveStatus() {
            const el = document.getElementById('fieldSaveStatus');
            if (!el) return;
            el.classList.add('visible');
            clearTimeout(showSaveStatus._t);
            showSaveStatus._t = setTimeout(() => el.classList.remove('visible'), 1500);
        }

        function showToast(message, isError) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast' + (isError ? ' toast-error' : '');
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }
    </script>
</body>
</html>
