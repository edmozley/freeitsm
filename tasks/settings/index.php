<?php
/**
 * Tasks Settings - Manage status / priority lookup tables
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
    <title>Service Desk - Tasks Settings</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <link rel="stylesheet" href="../../assets/css/tasks.css">
    <style>
        body { overflow: auto; height: auto; }

        .container { max-width: none; margin: 0; padding: 30px; }

        /* Tasks-purple theme for tabs */
        .tab:hover { color: #9333ea; }
        .tab.active { color: #9333ea; border-bottom-color: #9333ea; }

        .section-header h2 { margin: 0 0 8px; font-size: 18px; color: #333; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }

        .lookup-table { width: 100%; border-collapse: collapse; }
        .lookup-table th, .lookup-table td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .lookup-table th { font-weight: 600; color: #666; background: #fafafa; }
        .badge-yes { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #f3e8ff; color: #7e22ce; font-size: 11px; font-weight: 600; }
        .badge-no { color: #999; }
        .badge-active   { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #f3e8ff; color: #7e22ce; font-size: 11px; font-weight: 600; }
        .badge-inactive { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #fafafa; color: #999;   font-size: 11px; font-weight: 600; }
        .swatch { display: inline-block; width: 18px; height: 18px; border-radius: 3px; vertical-align: middle; border: 1px solid #ddd; margin-right: 6px; }
        .action-btn { background: none; border: none; cursor: pointer; padding: 4px; color: #666; }
        .action-btn:hover { color: #9333ea; }
        .action-btn.delete:hover { color: #c62828; }
        .actions-cell { white-space: nowrap; }
        .add-btn { background: #9333ea; color: white; padding: 8px 16px; border: none; border-radius: 4px; font-size: 13px; cursor: pointer; }
        .add-btn:hover { background: #7e22ce; }

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
        .btn { padding: 10px 20px; border-radius: 4px; font-size: 14px; cursor: pointer; border: none; transition: all 0.2s; }
        .btn-primary { background: #9333ea; color: white; }
        .btn-primary:hover { background: #7e22ce; }
        .btn-secondary { background: #e0e0e0; color: #333; }
        .btn-secondary:hover { background: #bdbdbd; }

        /* Toast */
        .toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: #333; color: white; padding: 10px 18px; border-radius: 4px; font-size: 14px; opacity: 0; pointer-events: none; transition: opacity 0.3s; z-index: 1100; }
        .toast.show { opacity: 1; }
        .toast.toast-error { background: #c62828; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="statuses" onclick="switchTab('statuses')">Statuses</button>
            <button class="tab" data-tab="priorities" onclick="switchTab('priorities')">Priorities</button>
        </div>

        <!-- Statuses Tab -->
        <div class="tab-content active" id="statuses-tab">
            <div class="section-header">
                <h2>Statuses</h2>
                <button class="add-btn" onclick="openLookupModal('status')">Add</button>
            </div>
            <p style="color: #666; margin-bottom: 16px;">Workflow states a task can be in — used as kanban columns and to filter the list view. Statuses flagged as <em>Closed</em> count as done — they auto-stamp <code>completed_datetime</code> and are excluded from open-task counters. Exactly one status is the default for new tasks.</p>
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
            <p style="color: #666; margin-bottom: 16px;">Priority bands shown on each task card. Exactly one priority is the default for new tasks.</p>
            <table class="lookup-table">
                <thead><tr><th>Name</th><th>Colour</th><th>Default</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="priorities-list"><tr><td colspan="6" style="text-align:center;">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>

    <!-- Lookup edit modal -->
    <div class="lk-modal" id="lookupModal">
        <div class="lk-modal-content">
            <div class="lk-modal-header" id="lookupModalTitle">Add Item</div>
            <form id="lookupForm">
                <input type="hidden" id="lookupItemKind">
                <input type="hidden" id="lookupItemId">

                <div class="lk-form-group">
                    <label for="lookupItemName">Name</label>
                    <input type="text" id="lookupItemName" required>
                </div>

                <div class="lk-form-group">
                    <label for="lookupItemColour">Colour</label>
                    <input type="color" id="lookupItemColour" value="#9333ea" style="width: 60px; height: 32px; padding: 2px;">
                    <span class="help">Used for badges on task cards and the kanban board.</span>
                </div>

                <div class="lk-form-group" id="lookupItemClosedGroup" style="display: none;">
                    <label><input type="checkbox" id="lookupItemClosed"> Counts as closed</label>
                    <span class="help">Tasks in this status auto-stamp <code>completed_datetime</code> and are excluded from open-task watchtower counters.</span>
                </div>

                <div class="lk-form-group">
                    <label><input type="checkbox" id="lookupItemDefault"> Default for new tasks</label>
                    <span class="help">Only one row can be the default — setting this clears the flag on the others.</span>
                </div>

                <div class="lk-form-group">
                    <label for="lookupItemOrder">Display Order</label>
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

    <div class="toast" id="toast"></div>

    <script>
        const API_BASE = '../../api/tasks/';

        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        const LOOKUP_KINDS = {
            'status':   { get: 'get_task_statuses.php',   save: 'save_task_status.php',   del: 'delete_task_status.php',   listKey: 'statuses',   tableId: 'statuses-list',   colspan: 7, hasClosed: true,  label: 'Status'   },
            'priority': { get: 'get_task_priorities.php', save: 'save_task_priority.php', del: 'delete_task_priority.php', listKey: 'priorities', tableId: 'priorities-list', colspan: 6, hasClosed: false, label: 'Priority' }
        };

        const lookupCache = { status: [], priority: [] };

        document.addEventListener('DOMContentLoaded', () => {
            for (const kind of Object.keys(LOOKUP_KINDS)) loadLookup(kind);
        });

        async function loadLookup(kind) {
            const cfg = LOOKUP_KINDS[kind];
            try {
                const res = await fetch(API_BASE + cfg.get);
                const data = await res.json();
                if (data.success) {
                    lookupCache[kind] = data[cfg.listKey] || [];
                    renderLookup(kind);
                }
            } catch (e) { console.error(e); }
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
                    <td class="actions-cell">
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
            document.getElementById('lookupItemColour').value = '#9333ea';
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
            document.getElementById('lookupItemColour').value = item.colour || '#9333ea';
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
                if (data.success) { showToast('Deleted'); loadLookup(kind); }
                else { showToast(data.error || 'Failed to delete', true); }
            } catch (e) { showToast('Failed to delete', true); }
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
            } catch (e) { showToast('Failed to save', true); }
        });

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
