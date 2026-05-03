<?php
/**
 * RFP Builder — extracted requirements browser (Phase 2 step 2c).
 * Lists every Pass-1 extracted requirement for an RFP across all
 * documents, with department + type filters, inline edit modal,
 * and delete. The next phase (consolidation) reads from this list.
 */
session_start();
require_once '../../config.php';

$current_page = 'rfp-builder';
$path_prefix  = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Extracted requirements</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; }
        .page-wrap { padding: 30px 40px; background: #f5f5f5; min-height: calc(100vh - 48px); box-sizing: border-box; }

        .breadcrumb { font-size: 13px; color: #888; margin-bottom: 8px; }
        .breadcrumb a { color: #666; text-decoration: none; }
        .breadcrumb a:hover { color: #f59e0b; }
        .breadcrumb span.sep { margin: 0 6px; color: #ccc; }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px;
        }
        .page-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: #222; }
        .page-actions { display: flex; gap: 8px; }

        .btn {
            padding: 8px 16px; font-size: 14px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: 1px solid transparent;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.15s; font-family: inherit;
        }
        .btn-primary { background: #f59e0b; color: white; }
        .btn-primary:hover:not(:disabled) { background: #d97706; }
        .btn-primary:disabled { background: #fcd34d; cursor: not-allowed; }
        .btn-secondary { background: white; color: #333; border-color: #ddd; }
        .btn-secondary:hover { background: #f5f5f5; }

        /* Stats strip */
        .stats-strip {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
            margin-bottom: 18px;
        }
        .stat-card {
            background: white; border-radius: 8px; padding: 14px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border-left: 4px solid #ddd;
        }
        .stat-card.total       { border-left-color: #6b7280; }
        .stat-card.requirement { border-left-color: #3b82f6; }
        .stat-card.pain_point  { border-left-color: #f59e0b; }
        .stat-card.challenge   { border-left-color: #8b5cf6; }
        .stat-card .stat-value { font-size: 22px; font-weight: 700; color: #222; line-height: 1; }
        .stat-card .stat-label { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px; }

        /* Filter bar */
        .card {
            background: white; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
            margin-bottom: 18px;
        }
        .filter-bar { display: flex; gap: 14px; align-items: end; padding: 16px 24px; flex-wrap: wrap; }
        .filter-bar .field { display: flex; flex-direction: column; }
        .filter-bar label {
            font-size: 11px; font-weight: 600; color: #555;
            margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .filter-bar select {
            padding: 7px 10px; font-size: 14px;
            border: 1px solid #ddd; border-radius: 6px;
            font-family: inherit; min-width: 180px;
        }
        .filter-bar .filter-spacer { flex: 1; }
        .filter-bar .clear-link {
            font-size: 13px; color: #888; cursor: pointer; text-decoration: none;
            padding: 8px 0;
        }
        .filter-bar .clear-link:hover { color: #f59e0b; }

        /* Table */
        table { width: 100%; border-collapse: collapse; }
        thead th {
            text-align: left; padding: 12px 24px; font-size: 12px; font-weight: 600;
            color: #888; text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid #eee; background: #fafafa;
        }
        tbody td {
            padding: 14px 24px; font-size: 14px; color: #333; border-bottom: 1px solid #f0f0f0;
            vertical-align: top;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fafafa; }

        .req-text { font-weight: 500; color: #222; line-height: 1.45; }
        .req-quote {
            font-style: italic; color: #888; font-size: 13px;
            margin-top: 6px; line-height: 1.4;
            border-left: 2px solid #eee; padding-left: 10px;
        }
        .req-doc { font-size: 12px; color: #888; margin-top: 6px; }

        .dept-badge {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            font-size: 12px; font-weight: 500; color: white; white-space: nowrap;
        }
        .dept-badge.empty { background: #e5e7eb; color: #6b7280; }

        .type-badge {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            font-size: 12px; font-weight: 500; text-transform: capitalize; white-space: nowrap;
        }
        .type-badge.requirement { background: #dbeafe; color: #1e40af; }
        .type-badge.pain_point  { background: #fed7aa; color: #9a3412; }
        .type-badge.challenge   { background: #ede9fe; color: #5b21b6; }

        .conf-pill {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 12px; font-weight: 600; min-width: 36px; text-align: center;
        }
        .conf-high { background: #d1fae5; color: #065f46; }
        .conf-mid  { background: #fef3c7; color: #92400e; }
        .conf-low  { background: #fee2e2; color: #991b1b; }
        .conf-none { color: #bbb; }

        .action-btn {
            background: none; border: 1px solid #ddd; color: #666; cursor: pointer;
            padding: 6px; margin-right: 4px; border-radius: 4px;
            display: inline-flex; align-items: center; justify-content: center;
            transition: all 0.2s;
        }
        .action-btn:hover { background: #f0f0f0; border-color: #f59e0b; color: #f59e0b; }
        .action-btn.danger:hover { border-color: #ef4444; color: #ef4444; background: #fef2f2; }
        .action-btn svg { width: 16px; height: 16px; }

        .empty-state { text-align: center; padding: 48px; color: #999; }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.4); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-card {
            background: white; border-radius: 10px; width: 90%; max-width: 720px;
            max-height: 85vh; display: flex; flex-direction: column;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2); overflow: hidden;
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 24px; border-bottom: 1px solid #eee;
        }
        .modal-header h3 { margin: 0; font-size: 16px; font-weight: 600; }
        .modal-close { background: none; border: none; font-size: 24px; line-height: 1; cursor: pointer; color: #888; }
        .modal-body { padding: 20px 24px; overflow-y: auto; }
        .modal-footer {
            display: flex; justify-content: flex-end; gap: 8px;
            padding: 14px 24px; border-top: 1px solid #eee; background: #fafafa;
        }

        .form-row { margin-bottom: 16px; }
        .form-row label {
            display: block; font-size: 12px; font-weight: 600; color: #555;
            margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .form-row input, .form-row select, .form-row textarea {
            width: 100%; padding: 8px 10px; font-size: 14px;
            border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box;
            font-family: inherit;
        }
        .form-row textarea { min-height: 90px; resize: vertical; line-height: 1.5; }
        .form-row input:focus, .form-row select:focus, .form-row textarea:focus {
            outline: none; border-color: #f59e0b;
        }
        .form-row .meta-row {
            display: flex; gap: 12px; font-size: 13px; color: #666;
        }
        .form-row .meta-row .meta-item { display: flex; gap: 6px; }
        .form-row .meta-row .meta-item span:first-child { color: #888; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../">Contracts</a><span class="sep">›</span>
            <a href="./">RFP Builder</a><span class="sep">›</span>
            <a href="view.php" id="bcRfp">RFP</a><span class="sep">›</span>
            <span>Extracted requirements</span>
        </div>

        <div class="page-header">
            <h1>Extracted requirements — <span id="rfpName" style="color:#f59e0b;">Loading...</span></h1>
            <div class="page-actions">
                <a href="view.php" id="backLink" class="btn btn-secondary">&larr; RFP overview</a>
                <a href="documents.php" id="docsLink" class="btn btn-secondary">Documents</a>
            </div>
        </div>

        <div class="stats-strip">
            <div class="stat-card total">
                <div class="stat-value" id="statTotal">0</div>
                <div class="stat-label">Total</div>
            </div>
            <div class="stat-card requirement">
                <div class="stat-value" id="statRequirement">0</div>
                <div class="stat-label">Requirements</div>
            </div>
            <div class="stat-card pain_point">
                <div class="stat-value" id="statPainPoint">0</div>
                <div class="stat-label">Pain points</div>
            </div>
            <div class="stat-card challenge">
                <div class="stat-value" id="statChallenge">0</div>
                <div class="stat-label">Challenges</div>
            </div>
        </div>

        <div class="card">
            <div class="filter-bar">
                <div class="field">
                    <label for="filterDept">Department</label>
                    <select id="filterDept" onchange="onFilterChange()">
                        <option value="">All departments</option>
                    </select>
                </div>
                <div class="field">
                    <label for="filterType">Type</label>
                    <select id="filterType" onchange="onFilterChange()">
                        <option value="">All types</option>
                        <option value="requirement">Requirements</option>
                        <option value="pain_point">Pain points</option>
                        <option value="challenge">Challenges</option>
                    </select>
                </div>
                <div class="filter-spacer"></div>
                <a class="clear-link" id="clearLink" onclick="clearFilters()">Clear filters</a>
            </div>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th style="width: 140px;">Department</th>
                        <th style="width: 120px;">Type</th>
                        <th>Requirement</th>
                        <th style="width: 80px;">Conf.</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="reqList">
                    <tr><td colspan="5" class="empty-state">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Edit requirement</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editId">
                <div class="form-row">
                    <div class="meta-row" id="editMeta"></div>
                </div>
                <div class="form-row">
                    <label for="editText">Requirement text</label>
                    <textarea id="editText" rows="4"></textarea>
                </div>
                <div class="form-row">
                    <label for="editType">Type</label>
                    <select id="editType">
                        <option value="requirement">Requirement</option>
                        <option value="pain_point">Pain point</option>
                        <option value="challenge">Challenge</option>
                    </select>
                </div>
                <div class="form-row">
                    <label for="editQuote">Source quote (verbatim from the source document)</label>
                    <textarea id="editQuote" rows="3" placeholder="Optional"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button class="btn btn-primary" id="editSaveBtn" onclick="saveEdit()">Save</button>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const rfpId = new URLSearchParams(location.search).get('id');
        let allReqs = [];

        document.addEventListener('DOMContentLoaded', async () => {
            if (!rfpId) {
                document.getElementById('rfpName').textContent = 'No RFP selected';
                document.getElementById('reqList').innerHTML =
                    '<tr><td colspan="5" class="empty-state">No RFP id supplied. <a href="./">Back to list</a>.</td></tr>';
                return;
            }
            document.getElementById('backLink').href = 'view.php?id=' + rfpId;
            document.getElementById('docsLink').href = 'documents.php?id=' + rfpId;
            document.getElementById('bcRfp').href    = 'view.php?id=' + rfpId;
            await loadRfp();
            await loadRequirements();
        });

        async function loadRfp() {
            try {
                const res = await fetch(API_BASE + 'get_rfp.php?id=' + encodeURIComponent(rfpId));
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                document.getElementById('rfpName').textContent = data.rfp.name;
                document.getElementById('bcRfp').textContent = data.rfp.name;
                document.title = 'Extracted requirements — ' + data.rfp.name;
            } catch (err) {
                document.getElementById('rfpName').textContent = '(could not load)';
            }
        }

        async function loadRequirements() {
            const params = new URLSearchParams({rfp_id: rfpId});
            const dept = document.getElementById('filterDept').value;
            const type = document.getElementById('filterType').value;
            if (dept) params.set('department_id', dept);
            if (type) params.set('requirement_type', type);

            try {
                const res = await fetch(API_BASE + 'get_extracted.php?' + params.toString());
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                allReqs = data.requirements;
                renderStats(data.stats);
                renderDepartmentFilter(data.departments);
                renderRequirements(data.requirements);
            } catch (err) {
                document.getElementById('reqList').innerHTML =
                    `<tr><td colspan="5" class="empty-state" style="color:#d13438;">${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function renderStats(stats) {
            document.getElementById('statTotal').textContent       = stats.total       || 0;
            document.getElementById('statRequirement').textContent = stats.requirement || 0;
            document.getElementById('statPainPoint').textContent   = stats.pain_point  || 0;
            document.getElementById('statChallenge').textContent   = stats.challenge   || 0;
        }

        function renderDepartmentFilter(depts) {
            const sel = document.getElementById('filterDept');
            const current = sel.value;
            sel.innerHTML = '<option value="">All departments</option>'
                + depts.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');
            sel.value = current;
        }

        function renderRequirements(rows) {
            const tbody = document.getElementById('reqList');
            if (rows.length === 0) {
                const hasFilters = document.getElementById('filterDept').value || document.getElementById('filterType').value;
                tbody.innerHTML = `<tr><td colspan="5" class="empty-state">${hasFilters
                    ? 'No requirements match the current filters.'
                    : 'No requirements extracted yet. Run AI extraction from the <a href="documents.php?id=' + rfpId + '" style="color:#f59e0b;">Documents</a> page.'}</td></tr>`;
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const dept = r.department_name
                    ? `<span class="dept-badge" style="background:${escapeHtml(r.department_colour || '#6c757d')};">${escapeHtml(r.department_name)}</span>`
                    : `<span class="dept-badge empty">Unassigned</span>`;
                const conf = r.ai_confidence !== null
                    ? `<span class="conf-pill ${confClass(r.ai_confidence)}">${Math.round(r.ai_confidence * 100)}%</span>`
                    : `<span class="conf-pill conf-none">—</span>`;
                const typeLabel = (r.requirement_type || '').replace('_', ' ');
                return `
                    <tr>
                        <td>${dept}</td>
                        <td><span class="type-badge ${r.requirement_type}">${escapeHtml(typeLabel)}</span></td>
                        <td>
                            <div class="req-text">${escapeHtml(r.requirement_text)}</div>
                            ${r.source_quote ? `<div class="req-quote">${escapeHtml(r.source_quote)}</div>` : ''}
                            <div class="req-doc">${escapeHtml(r.original_filename || '(no source)')}</div>
                        </td>
                        <td>${conf}</td>
                        <td>
                            <button class="action-btn" title="Edit" onclick="openEdit(${r.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </button>
                            <button class="action-btn danger" title="Delete" onclick="deleteReq(${r.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"></path><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"></path></svg>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function confClass(c) {
            if (c >= 0.85) return 'conf-high';
            if (c >= 0.6)  return 'conf-mid';
            return 'conf-low';
        }

        function onFilterChange() { loadRequirements(); }

        function clearFilters() {
            document.getElementById('filterDept').value = '';
            document.getElementById('filterType').value = '';
            loadRequirements();
        }

        function openEdit(id) {
            const r = allReqs.find(x => x.id === id);
            if (!r) return;
            document.getElementById('editId').value    = r.id;
            document.getElementById('editText').value  = r.requirement_text || '';
            document.getElementById('editType').value  = r.requirement_type || 'requirement';
            document.getElementById('editQuote').value = r.source_quote || '';
            const meta = document.getElementById('editMeta');
            const conf = r.ai_confidence !== null ? Math.round(r.ai_confidence * 100) + '%' : '—';
            meta.innerHTML = `
                <div class="meta-item"><span>Department:</span><strong>${escapeHtml(r.department_name || 'Unassigned')}</strong></div>
                <div class="meta-item"><span>Document:</span><strong>${escapeHtml(r.original_filename || '(none)')}</strong></div>
                <div class="meta-item"><span>AI confidence:</span><strong>${conf}</strong></div>
            `;
            document.getElementById('editModal').classList.add('active');
            setTimeout(() => document.getElementById('editText').focus(), 50);
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        async function saveEdit() {
            const payload = {
                id: parseInt(document.getElementById('editId').value, 10),
                requirement_text: document.getElementById('editText').value,
                requirement_type: document.getElementById('editType').value,
                source_quote: document.getElementById('editQuote').value,
            };
            if (!payload.requirement_text.trim()) { alert('Requirement text cannot be empty.'); return; }
            const btn = document.getElementById('editSaveBtn');
            btn.disabled = true; btn.textContent = 'Saving...';
            try {
                const res = await fetch(API_BASE + 'save_extracted.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                closeEditModal();
                await loadRequirements();
            } catch (err) {
                alert('Save failed: ' + err.message);
            } finally {
                btn.disabled = false; btn.textContent = 'Save';
            }
        }

        async function deleteReq(id) {
            const r = allReqs.find(x => x.id === id);
            const text = r ? r.requirement_text : '';
            const preview = text.length > 80 ? text.substring(0, 77) + '...' : text;
            if (!confirm('Delete this requirement?\n\n"' + preview + '"')) return;
            try {
                const res = await fetch(API_BASE + 'delete_extracted.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id}),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                await loadRequirements();
            } catch (err) {
                alert('Delete failed: ' + err.message);
            }
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        // Modal close behaviour
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEditModal(); });
        document.getElementById('editModal').addEventListener('click', e => {
            if (e.target.id === 'editModal') closeEditModal();
        });
    </script>
</body>
</html>
