<?php
/**
 * RFP Builder — list / dashboard
 * A feature of the Contracts module.
 */
session_start();
require_once '../../config.php';

$current_page = 'rfp-builder';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - RFP Builder</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .rfp-layout { display: flex; height: calc(100vh - 48px); background: #f5f5f5; }
        .rfp-sidebar {
            width: 260px; background: white; border-right: 1px solid #ddd;
            padding: 20px; overflow-y: auto; flex-shrink: 0;
        }
        .rfp-main { flex: 1; overflow-y: auto; padding: 30px; }

        .sidebar-section { margin-bottom: 24px; }
        .sidebar-section h3 {
            font-size: 14px; font-weight: 600; color: #333; margin: 0 0 12px 0;
        }
        .sidebar-stat {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 12px; border-radius: 6px; font-size: 14px; color: #333;
            margin-bottom: 4px;
        }
        .sidebar-stat .stat-value { font-weight: 700; font-size: 16px; }
        .sidebar-links { display: flex; flex-direction: column; gap: 4px; }
        .sidebar-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 6px;
            font-size: 14px; color: #333;
            text-decoration: none; transition: all 0.15s;
        }
        .sidebar-link:hover { background: #fff7ed; color: #f59e0b; }
        .sidebar-link svg { width: 18px; height: 18px; flex-shrink: 0; }

        .sidebar-add-btn {
            display: block; width: 100%; padding: 10px 16px;
            background: #f59e0b; color: white; border: none; border-radius: 6px;
            font-size: 14px; font-weight: 500; cursor: pointer;
            transition: background 0.2s; text-align: center;
        }
        .sidebar-add-btn:hover { background: #d97706; }

        .section-card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
        }
        .section-card .section-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 18px 24px; border-bottom: 1px solid #eee;
        }
        .section-card .section-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: #333; }

        .section-card table { width: 100%; border-collapse: collapse; }
        .section-card table th {
            text-align: left; padding: 12px 24px; font-size: 12px; font-weight: 600;
            color: #888; text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid #eee; background: #fafafa;
        }
        .section-card table td {
            padding: 14px 24px; font-size: 14px; color: #333; border-bottom: 1px solid #f0f0f0;
        }
        .section-card table tr:last-child td { border-bottom: none; }
        .section-card table tr:hover { background: #fafafa; }

        .rfp-name-link { color: #333; font-weight: 600; text-decoration: none; }
        .rfp-name-link:hover { color: #f59e0b; }

        .status-badge {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            font-size: 12px; font-weight: 500; text-transform: capitalize;
        }
        .status-badge.draft        { background: #e5e7eb; color: #374151; }
        .status-badge.collecting   { background: #dbeafe; color: #1e40af; }
        .status-badge.consolidating { background: #fed7aa; color: #9a3412; }
        .status-badge.generating   { background: #ede9fe; color: #5b21b6; }
        .status-badge.scoring      { background: #ccfbf1; color: #115e59; }
        .status-badge.closed       { background: #d1fae5; color: #065f46; }
        .status-badge.abandoned    { background: #fee2e2; color: #991b1b; }

        .pill-stat {
            display: inline-block; min-width: 24px; padding: 2px 8px;
            background: #f3f4f6; border-radius: 10px; text-align: center;
            font-size: 12px; font-weight: 600; color: #555;
        }

        .action-btn {
            background: none; border: 1px solid #ddd; color: #666; cursor: pointer;
            padding: 6px; margin-right: 4px; border-radius: 4px;
            display: inline-flex; align-items: center; justify-content: center;
            transition: all 0.2s;
        }
        .action-btn:hover { background: #f0f0f0; border-color: #f59e0b; color: #f59e0b; }
        .action-btn.danger:hover { border-color: #ef4444; color: #ef4444; background: #fef2f2; }
        .action-btn svg { width: 16px; height: 16px; }

        .empty-state { text-align: center; padding: 40px; color: #999; }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.4); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-card {
            background: white; border-radius: 10px; width: 100%; max-width: 560px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2); overflow: hidden;
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 24px; border-bottom: 1px solid #eee;
        }
        .modal-header h3 { margin: 0; font-size: 16px; font-weight: 600; }
        .modal-close {
            background: none; border: none; font-size: 24px; line-height: 1;
            cursor: pointer; color: #888; padding: 0 4px;
        }
        .modal-body { padding: 20px 24px; }
        .modal-footer {
            display: flex; justify-content: flex-end; gap: 8px;
            padding: 14px 24px; border-top: 1px solid #eee; background: #fafafa;
        }

        .form-row { margin-bottom: 16px; }
        .form-row label {
            display: block; font-size: 13px; font-weight: 600; color: #555;
            margin-bottom: 6px;
        }
        .form-row input, .form-row select, .form-row textarea {
            width: 100%; padding: 8px 10px; font-size: 14px;
            border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box;
            font-family: inherit;
        }
        .form-row textarea { min-height: 100px; resize: vertical; }
        .form-row input:focus, .form-row select:focus, .form-row textarea:focus {
            outline: none; border-color: #f59e0b;
        }
        .form-help { font-size: 12px; color: #888; margin-top: 4px; }

        .btn {
            padding: 8px 16px; font-size: 14px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: 1px solid transparent;
            transition: all 0.15s;
        }
        .btn-primary { background: #f59e0b; color: white; }
        .btn-primary:hover { background: #d97706; }
        .btn-secondary { background: white; color: #333; border-color: #ddd; }
        .btn-secondary:hover { background: #f5f5f5; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="rfp-layout">
        <div class="rfp-sidebar">
            <div class="sidebar-section">
                <h3>Overview</h3>
                <div class="sidebar-stat">
                    <span>RFPs</span>
                    <span class="stat-value" id="sideTotal">-</span>
                </div>
                <div class="sidebar-stat">
                    <span>Draft</span>
                    <span class="stat-value" id="sideDraft">-</span>
                </div>
                <div class="sidebar-stat">
                    <span>In progress</span>
                    <span class="stat-value" id="sideInProgress">-</span>
                </div>
                <div class="sidebar-stat">
                    <span>Closed</span>
                    <span class="stat-value" id="sideClosed">-</span>
                </div>
            </div>

            <div class="sidebar-section">
                <h3>Quick Links</h3>
                <div class="sidebar-links">
                    <a href="../" class="sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        Back to Contracts
                    </a>
                    <a href="help.php" class="sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                        Help &amp; user guide
                    </a>
                </div>
            </div>

            <div class="sidebar-section">
                <button class="sidebar-add-btn" onclick="openCreateModal()">+ New RFP</button>
            </div>
        </div>

        <div class="rfp-main">
            <div class="section-card">
                <div class="section-header">
                    <h2>RFPs</h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Docs</th>
                            <th>Reqs</th>
                            <th>Suppliers</th>
                            <th>Created</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="rfpList">
                        <tr><td colspan="8" class="empty-state">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create / Edit Modal -->
    <div class="modal-overlay" id="rfpModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="rfpModalTitle">New RFP</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rfpId">
                <div class="form-row">
                    <label for="rfpName">Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="rfpName" placeholder="e.g. New ITSM platform 2026" maxlength="200">
                </div>
                <div class="form-row">
                    <label for="rfpStatus">Status</label>
                    <select id="rfpStatus">
                        <option value="draft">Draft</option>
                        <option value="collecting">Collecting requirements</option>
                        <option value="consolidating">Consolidating</option>
                        <option value="generating">Generating RFP</option>
                        <option value="scoring">Scoring suppliers</option>
                        <option value="closed">Closed</option>
                        <option value="abandoned">Abandoned</option>
                    </select>
                </div>
                <div class="form-row">
                    <label for="rfpStyleGuide">Style guide override</label>
                    <textarea id="rfpStyleGuide" placeholder="Optional. Leave blank to use the system default."></textarea>
                    <div class="form-help">Appended to AI prompts to control writing style of the generated RFP. Plain text, one rule per line.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" id="rfpSaveBtn" onclick="saveRfp()">Save</button>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';

        document.addEventListener('DOMContentLoaded', async () => {
            await loadRfps();
            // If we landed here from view.php Edit, auto-open the modal for that RFP.
            const editId = new URLSearchParams(location.search).get('edit');
            if (editId) {
                openEditModal({id: editId});
                history.replaceState(null, '', './');
            }
        });

        async function loadRfps() {
            try {
                const res = await fetch(API_BASE + 'get_rfps.php');
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load');
                renderRfps(data.rfps);
                renderStats(data.rfps);
            } catch (err) {
                document.getElementById('rfpList').innerHTML =
                    `<tr><td colspan="8" class="empty-state" style="color:#d13438;">${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function renderStats(rfps) {
            const total = rfps.length;
            const draft = rfps.filter(r => r.status === 'draft').length;
            const closed = rfps.filter(r => r.status === 'closed' || r.status === 'abandoned').length;
            const inProgress = total - draft - closed;
            document.getElementById('sideTotal').textContent = total;
            document.getElementById('sideDraft').textContent = draft;
            document.getElementById('sideInProgress').textContent = inProgress;
            document.getElementById('sideClosed').textContent = closed;
        }

        function renderRfps(rfps) {
            const tbody = document.getElementById('rfpList');
            if (rfps.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="empty-state">No RFPs yet. Click "+ New RFP" to create one.</td></tr>';
                return;
            }
            tbody.innerHTML = rfps.map(r => `
                <tr>
                    <td><a href="view.php?id=${r.id}" class="rfp-name-link">${escapeHtml(r.name)}</a></td>
                    <td><span class="status-badge ${r.status}">${escapeHtml(r.status)}</span></td>
                    <td><span class="pill-stat">${r.document_count}</span></td>
                    <td><span class="pill-stat">${r.consolidated_count}</span></td>
                    <td><span class="pill-stat">${r.supplier_count}</span></td>
                    <td>${formatDate(r.created_datetime)}</td>
                    <td>${formatDate(r.updated_datetime)}</td>
                    <td>
                        <a href="view.php?id=${r.id}" class="action-btn" title="Open">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </a>
                        <button class="action-btn" title="Edit" onclick='openEditModal(${JSON.stringify(r)})'>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn danger" title="Delete" onclick="deleteRfp(${r.id}, ${JSON.stringify(r.name)})">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function openCreateModal() {
            document.getElementById('rfpModalTitle').textContent = 'New RFP';
            document.getElementById('rfpId').value = '';
            document.getElementById('rfpName').value = '';
            document.getElementById('rfpStatus').value = 'draft';
            document.getElementById('rfpStyleGuide').value = '';
            document.getElementById('rfpModal').classList.add('active');
            setTimeout(() => document.getElementById('rfpName').focus(), 50);
        }

        async function openEditModal(rfp) {
            // The list endpoint doesn't return style_guide; fetch the full record.
            try {
                const res = await fetch(API_BASE + 'get_rfp.php?id=' + rfp.id);
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load');
                const full = data.rfp;
                document.getElementById('rfpModalTitle').textContent = 'Edit RFP';
                document.getElementById('rfpId').value = full.id;
                document.getElementById('rfpName').value = full.name;
                document.getElementById('rfpStatus').value = full.status;
                document.getElementById('rfpStyleGuide').value = full.style_guide || '';
                document.getElementById('rfpModal').classList.add('active');
                setTimeout(() => document.getElementById('rfpName').focus(), 50);
            } catch (err) {
                alert('Could not load RFP: ' + err.message);
            }
        }

        function closeModal() {
            document.getElementById('rfpModal').classList.remove('active');
        }

        async function saveRfp() {
            const name = document.getElementById('rfpName').value.trim();
            if (!name) { alert('Name is required'); return; }
            const payload = {
                id: document.getElementById('rfpId').value || null,
                name,
                status: document.getElementById('rfpStatus').value,
                style_guide: document.getElementById('rfpStyleGuide').value
            };
            const btn = document.getElementById('rfpSaveBtn');
            btn.disabled = true; btn.textContent = 'Saving...';
            try {
                const res = await fetch(API_BASE + 'save_rfp.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Save failed');
                closeModal();
                loadRfps();
            } catch (err) {
                alert('Save failed: ' + err.message);
            } finally {
                btn.disabled = false; btn.textContent = 'Save';
            }
        }

        async function deleteRfp(id, name) {
            if (!confirm(`Delete RFP "${name}"?\n\nThis will permanently remove all documents, requirements, scores, and history for this RFP.`)) return;
            try {
                const res = await fetch(API_BASE + 'delete_rfp.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id})
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Delete failed');
                loadRfps();
            } catch (err) {
                alert('Delete failed: ' + err.message);
            }
        }

        function formatDate(s) {
            if (!s) return '-';
            const d = new Date(s.replace(' ', 'T'));
            if (isNaN(d)) return s;
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        // Close modal on Escape
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeModal();
        });
        // Close modal on overlay click
        document.getElementById('rfpModal').addEventListener('click', e => {
            if (e.target.id === 'rfpModal') closeModal();
        });
    </script>
</body>
</html>
