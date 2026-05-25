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

        .field-group-heading {
            font-size: 13px;
            font-weight: 600;
            color: #00897b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px 0 8px;
            border-bottom: 1px solid #e0e0e0;
            margin-bottom: 0;
        }

        .field-group-heading:first-child {
            padding-top: 0;
        }

        .field-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .field-row:last-child {
            border-bottom: none;
        }

        .field-row-label {
            font-size: 14px;
            color: #333;
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
            <p style="color: #666; margin-bottom: 20px;">Control which fields appear on the change editor and detail view. Hidden fields will not be shown or required.</p>

            <div id="fieldSettings"></div>

            <div class="form-actions">
                <button class="btn btn-primary" onclick="saveSettings()">Save</button>
            </div>
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

        // Field configuration organised by section
        const FIELD_SECTIONS = [
            {
                id: 'general',
                label: 'General information',
                fields: [
                    { id: 'title',       label: 'Title' },
                    { id: 'change_type', label: 'Change type' },
                    { id: 'status',      label: 'Status' },
                    { id: 'priority',    label: 'Priority' },
                    { id: 'impact',      label: 'Impact' },
                    { id: 'category',    label: 'Category' }
                ]
            },
            {
                id: 'people',
                label: 'People',
                fields: [
                    { id: 'requester',   label: 'Requester' },
                    { id: 'assigned_to', label: 'Assigned to' },
                    { id: 'approver',    label: 'Approver' }
                ]
            },
            {
                id: 'schedule',
                label: 'Schedule',
                fields: [
                    { id: 'work_start',   label: 'Work start' },
                    { id: 'work_end',     label: 'Work end' },
                    { id: 'outage_start', label: 'Outage start' },
                    { id: 'outage_end',   label: 'Outage end' }
                ]
            },
            {
                id: 'details',
                label: 'Details',
                fields: [
                    { id: 'description', label: 'Description' },
                    { id: 'reason',      label: 'Reason for change' },
                    { id: 'risk',        label: 'Risk evaluation' },
                    { id: 'testplan',    label: 'Test plan' },
                    { id: 'rollback',    label: 'Rollback plan' },
                    { id: 'pir',         label: 'Post-implementation review' }
                ]
            },
            {
                id: 'attachments',
                label: 'Attachments',
                fields: [
                    { id: 'attachments', label: 'Attachments' }
                ]
            }
        ];

        // Current visibility state (default all visible)
        let fieldVisibility = {};

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

        async function loadSettings() {
            try {
                const res = await fetch(API_BASE + 'get_settings.php');
                const data = await res.json();
                if (data.success && data.settings && data.settings.field_visibility) {
                    fieldVisibility = data.settings.field_visibility;
                }
            } catch (e) {
                console.error(e);
            }
            renderFieldSettings();
        }

        function renderFieldSettings() {
            const container = document.getElementById('fieldSettings');
            let html = '';

            FIELD_SECTIONS.forEach(section => {
                html += `<div class="field-group-heading">${section.label}</div>`;
                section.fields.forEach(field => {
                    const isVisible = fieldVisibility[field.id] !== false;
                    html += `
                        <div class="field-row">
                            <span class="field-row-label">${field.label}</span>
                            <label class="toggle-switch">
                                <input type="checkbox" data-field="${field.id}" ${isVisible ? 'checked' : ''} onchange="toggleField('${field.id}', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    `;
                });
            });

            container.innerHTML = html;
        }

        function toggleField(fieldId, visible) {
            fieldVisibility[fieldId] = visible;
        }

        async function saveSettings() {
            try {
                const res = await fetch(API_BASE + 'save_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: { field_visibility: fieldVisibility } })
                });
                const data = await res.json();

                if (data.success) {
                    showToast('Settings saved');
                } else {
                    showToast('Error: ' + data.error, true);
                }
            } catch (e) {
                showToast('Failed to save settings', true);
            }
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
