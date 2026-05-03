<?php
/**
 * RFP Builder — per-RFP source documents page.
 * Upload, view, re-extract and delete the .docx files that feed Pass 1.
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
    <title>Service Desk - RFP Documents</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; }
        .page-wrap { padding: 30px 40px; background: #f5f5f5; min-height: calc(100vh - 48px); box-sizing: border-box; }

        .breadcrumb { font-size: 13px; color: #888; margin-bottom: 8px; }
        .breadcrumb a { color: #666; text-decoration: none; }
        .breadcrumb a:hover { color: #f59e0b; }
        .breadcrumb span { margin: 0 6px; color: #ccc; }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px;
        }
        .page-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: #222; }
        .page-actions { display: flex; gap: 8px; }

        .btn {
            padding: 8px 16px; font-size: 14px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: 1px solid transparent;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.15s;
        }
        .btn-primary { background: #f59e0b; color: white; }
        .btn-primary:hover:not(:disabled) { background: #d97706; }
        .btn-primary:disabled { background: #fcd34d; cursor: not-allowed; }
        .btn-secondary { background: white; color: #333; border-color: #ddd; }
        .btn-secondary:hover { background: #f5f5f5; }

        .card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            padding: 16px 24px; border-bottom: 1px solid #eee;
            font-size: 15px; font-weight: 600; color: #333;
        }
        .card-body { padding: 20px 24px; }

        /* Upload form */
        .upload-form { display: grid; grid-template-columns: 1fr 220px auto; gap: 12px; align-items: end; }
        .upload-form .field { display: flex; flex-direction: column; }
        .upload-form label {
            font-size: 12px; font-weight: 600; color: #555;
            margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .upload-form input[type=file], .upload-form select {
            padding: 8px 10px; font-size: 14px;
            border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box;
            font-family: inherit;
        }
        .upload-form input[type=file]:focus, .upload-form select:focus {
            outline: none; border-color: #f59e0b;
        }
        .upload-status {
            font-size: 13px; color: #555; margin-top: 12px;
        }
        .upload-status.error { color: #d13438; }
        .upload-status.success { color: #065f46; }
        .upload-status.busy { color: #b45309; }
        .upload-help {
            font-size: 12px; color: #888; margin-top: 8px;
        }

        /* Documents table */
        table { width: 100%; border-collapse: collapse; }
        thead th {
            text-align: left; padding: 12px 24px; font-size: 12px; font-weight: 600;
            color: #888; text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid #eee; background: #fafafa;
        }
        tbody td {
            padding: 14px 24px; font-size: 14px; color: #333; border-bottom: 1px solid #f0f0f0;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fafafa; }

        .filename { font-weight: 600; color: #222; }
        .dept-badge {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            font-size: 12px; font-weight: 500; color: white;
        }
        .dept-badge.empty { background: #e5e7eb; color: #6b7280; }

        .doc-status {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            font-size: 12px; font-weight: 500; text-transform: capitalize;
        }
        .doc-status.uploaded  { background: #e5e7eb; color: #374151; }
        .doc-status.extracted { background: #d1fae5; color: #065f46; }
        .doc-status.processed { background: #ccfbf1; color: #115e59; }
        .doc-status.error     { background: #fee2e2; color: #991b1b; }

        .action-btn {
            background: none; border: 1px solid #ddd; color: #666; cursor: pointer;
            padding: 6px; margin-right: 4px; border-radius: 4px;
            display: inline-flex; align-items: center; justify-content: center;
            transition: all 0.2s;
        }
        .action-btn:hover { background: #f0f0f0; border-color: #f59e0b; color: #f59e0b; }
        .action-btn.danger:hover { border-color: #ef4444; color: #ef4444; background: #fef2f2; }
        .action-btn svg { width: 16px; height: 16px; }
        .action-btn:disabled { opacity: 0.4; cursor: not-allowed; }

        .empty-state { text-align: center; padding: 40px; color: #999; }

        /* View text modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.4); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-card {
            background: white; border-radius: 10px; width: 90%; max-width: 900px;
            max-height: 85vh; display: flex; flex-direction: column;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2); overflow: hidden;
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 24px; border-bottom: 1px solid #eee;
        }
        .modal-header h3 { margin: 0; font-size: 16px; font-weight: 600; }
        .modal-meta { font-size: 12px; color: #888; }
        .modal-close {
            background: none; border: none; font-size: 24px; line-height: 1;
            cursor: pointer; color: #888; padding: 0 4px;
        }
        .modal-body {
            padding: 0; flex: 1; overflow: hidden; display: flex;
        }
        .modal-body pre {
            margin: 0; padding: 20px 24px; flex: 1; overflow: auto;
            white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 13px; line-height: 1.5; color: #333; background: #fafafa;
        }

        .alert-info {
            background: #fff7ed; border-left: 4px solid #f59e0b;
            padding: 12px 16px; border-radius: 6px; font-size: 13px; color: #7c2d12;
            margin-bottom: 16px;
        }
        .alert-info a { color: #b45309; font-weight: 600; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../">Contracts</a><span>›</span>
            <a href="./">RFP Builder</a><span>›</span>
            <a href="view.php" id="bcRfp">RFP</a><span>›</span>
            <span>Documents</span>
        </div>

        <div class="page-header">
            <h1>Source documents — <span id="rfpName" style="color:#f59e0b;">Loading...</span></h1>
            <div class="page-actions">
                <a href="view.php" id="backLink" class="btn btn-secondary">&larr; RFP overview</a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Upload a new document</div>
            <div class="card-body">
                <div id="noDeptsAlert" class="alert-info" style="display:none;">
                    <strong>No active departments yet.</strong> Add at least one department in
                    <a href="../settings/" target="_blank">Contracts settings → RFP Departments</a>
                    so you can tag uploaded documents with their source.
                </div>
                <form id="uploadForm" class="upload-form" autocomplete="off">
                    <div class="field">
                        <label for="fileInput">.docx file</label>
                        <input type="file" id="fileInput" accept=".docx" required>
                    </div>
                    <div class="field">
                        <label for="deptSelect">Department</label>
                        <select id="deptSelect">
                            <option value="">— Unassigned —</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>&nbsp;</label>
                        <button type="submit" id="uploadBtn" class="btn btn-primary">Upload</button>
                    </div>
                </form>
                <div id="uploadStatus" class="upload-status"></div>
                <div class="upload-help">
                    Max 20 MB. Only .docx files are supported. Text is extracted automatically on upload —
                    you can view the extracted plain text from each row below.
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Uploaded documents</div>
            <table>
                <thead>
                    <tr>
                        <th>Filename</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th>Size</th>
                        <th>Reqs</th>
                        <th>Uploaded</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="docsList">
                    <tr><td colspan="7" class="empty-state">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- View text modal -->
    <div class="modal-overlay" id="textModal">
        <div class="modal-card">
            <div class="modal-header">
                <div>
                    <h3 id="modalTitle">Extracted text</h3>
                    <div class="modal-meta" id="modalMeta">-</div>
                </div>
                <button class="modal-close" onclick="closeTextModal()">&times;</button>
            </div>
            <div class="modal-body">
                <pre id="modalText">Loading...</pre>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const rfpId = new URLSearchParams(location.search).get('id');

        document.addEventListener('DOMContentLoaded', async () => {
            if (!rfpId) {
                document.getElementById('rfpName').textContent = 'No RFP selected';
                document.getElementById('docsList').innerHTML =
                    '<tr><td colspan="6" class="empty-state">No RFP id supplied. <a href="./">Back to list</a>.</td></tr>';
                return;
            }
            // Wire up the back link with the RFP id
            document.getElementById('backLink').href = 'view.php?id=' + rfpId;
            document.getElementById('bcRfp').href = 'view.php?id=' + rfpId;

            await Promise.all([loadRfp(), loadDepartments(), loadDocuments()]);
        });

        async function loadRfp() {
            try {
                const res = await fetch(API_BASE + 'get_rfp.php?id=' + encodeURIComponent(rfpId));
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                document.getElementById('rfpName').textContent = data.rfp.name;
                document.getElementById('bcRfp').textContent = data.rfp.name;
                document.title = 'Service Desk - Documents — ' + data.rfp.name;
            } catch (err) {
                document.getElementById('rfpName').textContent = '(could not load RFP)';
            }
        }

        async function loadDepartments() {
            try {
                const res = await fetch(API_BASE + 'get_rfp_departments.php');
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                const active = data.rfp_departments.filter(d => d.is_active);
                const sel = document.getElementById('deptSelect');
                // Keep the unassigned default option, append active depts
                sel.innerHTML = '<option value="">— Unassigned —</option>' +
                    active.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');
                document.getElementById('noDeptsAlert').style.display = active.length === 0 ? 'block' : 'none';
            } catch (err) {
                console.error('Failed to load departments:', err);
            }
        }

        async function loadDocuments() {
            try {
                const res = await fetch(API_BASE + 'get_documents.php?rfp_id=' + encodeURIComponent(rfpId));
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                renderDocs(data.documents);
            } catch (err) {
                document.getElementById('docsList').innerHTML =
                    `<tr><td colspan="6" class="empty-state" style="color:#d13438;">${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function renderDocs(docs) {
            const tbody = document.getElementById('docsList');
            if (docs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No documents yet. Upload a .docx above to get started.</td></tr>';
                return;
            }
            tbody.innerHTML = docs.map(d => {
                const deptBadge = d.department_name
                    ? `<span class="dept-badge" style="background:${escapeHtml(d.department_colour || '#6c757d')};">${escapeHtml(d.department_name)}</span>`
                    : `<span class="dept-badge empty">Unassigned</span>`;
                const sizeText = d.has_text ? formatNumber(d.char_count) + ' chars' : '—';
                const reqsCell = d.extracted_count > 0
                    ? `<span style="display:inline-block; min-width:24px; padding:2px 8px; background:#d1fae5; color:#065f46; border-radius:10px; text-align:center; font-size:12px; font-weight:600;">${d.extracted_count}</span>`
                    : `<span style="color:#bbb;">—</span>`;
                const extractTitle = d.extracted_count > 0 ? 'Re-extract requirements with AI' : 'Extract requirements with AI';
                return `
                    <tr>
                        <td><span class="filename">${escapeHtml(d.original_filename)}</span></td>
                        <td>${deptBadge}</td>
                        <td><span class="doc-status ${d.status}">${escapeHtml(d.status)}</span></td>
                        <td>${sizeText}</td>
                        <td>${reqsCell}</td>
                        <td>${formatDateTime(d.uploaded_datetime)}</td>
                        <td>
                            <button class="action-btn" title="View extracted text" onclick="viewText(${d.id})" ${d.has_text ? '' : 'disabled'}>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                            <button class="action-btn" title="${extractTitle}" onclick="extractRequirements(${d.id}, this)" ${d.has_text ? '' : 'disabled'}>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v3"></path><path d="M12 18v3"></path><path d="M5.6 5.6l2.1 2.1"></path><path d="M16.3 16.3l2.1 2.1"></path><path d="M3 12h3"></path><path d="M18 12h3"></path><path d="M5.6 18.4l2.1-2.1"></path><path d="M16.3 7.7l2.1-2.1"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                            <button class="action-btn" title="Re-run text extraction" onclick="reExtract(${d.id}, this)">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10"></path><path d="M20.49 15a9 9 0 0 1-14.85 3.36L1 14"></path></svg>
                            </button>
                            <button class="action-btn danger" title="Delete" onclick="deleteDoc(${d.id}, ${JSON.stringify(d.original_filename)})">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"></path><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"></path></svg>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        async function extractRequirements(id, btn) {
            const wasExtracted = btn.title.startsWith('Re-extract');
            if (wasExtracted && !confirm('Re-running will discard the current extracted requirements for this document and replace them. Continue?')) return;

            btn.disabled = true;
            setStatus('Extracting requirements with AI... this can take 10-30 seconds per document.', 'busy');
            try {
                const res = await fetch(API_BASE + 'extract_requirements.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({document_id: id})
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                const cacheNote = (data.cache_read || data.cache_write)
                    ? ` · cache: ${data.cache_read || 0} read / ${data.cache_write || 0} written`
                    : '';
                setStatus(
                    `Extracted ${data.count} requirements in ${(data.duration_ms / 1000).toFixed(1)}s — ${data.tokens_in} in / ${data.tokens_out} out tokens${cacheNote}.`,
                    'success'
                );
                await loadDocuments();
            } catch (err) {
                setStatus('Extraction failed: ' + err.message, 'error');
            } finally {
                btn.disabled = false;
            }
        }

        document.getElementById('uploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fileInput = document.getElementById('fileInput');
            const deptSel = document.getElementById('deptSelect');
            const btn = document.getElementById('uploadBtn');
            const statusEl = document.getElementById('uploadStatus');

            const f = fileInput.files[0];
            if (!f) {
                setStatus('Pick a .docx file first.', 'error');
                return;
            }

            const fd = new FormData();
            fd.append('rfp_id', rfpId);
            fd.append('department_id', deptSel.value);
            fd.append('file', f);

            btn.disabled = true; btn.textContent = 'Uploading...';
            setStatus('Uploading and extracting text...', 'busy');

            try {
                const res = await fetch(API_BASE + 'upload_document.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Upload failed');

                if (data.status === 'extracted') {
                    setStatus(`Uploaded — extracted ${data.word_count} words.`, 'success');
                } else if (data.status === 'error') {
                    setStatus(`Uploaded, but text extraction failed: ${data.extraction_error}. You can re-extract from the row.`, 'error');
                } else {
                    setStatus('Uploaded.', 'success');
                }

                fileInput.value = '';
                deptSel.value = '';
                await loadDocuments();
            } catch (err) {
                setStatus('Upload failed: ' + err.message, 'error');
            } finally {
                btn.disabled = false; btn.textContent = 'Upload';
            }
        });

        function setStatus(msg, kind) {
            const el = document.getElementById('uploadStatus');
            el.textContent = msg;
            el.className = 'upload-status ' + (kind || '');
        }

        async function viewText(id) {
            const overlay = document.getElementById('textModal');
            document.getElementById('modalTitle').textContent = 'Extracted text';
            document.getElementById('modalMeta').textContent = 'Loading...';
            document.getElementById('modalText').textContent = 'Loading...';
            overlay.classList.add('active');
            try {
                const res = await fetch(API_BASE + 'get_document_text.php?id=' + id);
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                const d = data.document;
                document.getElementById('modalTitle').textContent = d.original_filename;
                document.getElementById('modalMeta').textContent =
                    `${formatNumber(d.word_count)} words · ${formatNumber(d.char_count)} chars · status: ${d.status}`;
                document.getElementById('modalText').textContent = d.raw_text || '(no text extracted)';
            } catch (err) {
                document.getElementById('modalText').textContent = 'Failed to load: ' + err.message;
            }
        }

        function closeTextModal() {
            document.getElementById('textModal').classList.remove('active');
        }

        async function reExtract(id, btn) {
            btn.disabled = true;
            try {
                const res = await fetch(API_BASE + 'extract_document.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id})
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                await loadDocuments();
            } catch (err) {
                alert('Re-extract failed: ' + err.message);
            } finally {
                btn.disabled = false;
            }
        }

        async function deleteDoc(id, name) {
            if (!confirm(`Delete "${name}"?\n\nAny extracted requirements from this document will be removed too.`)) return;
            try {
                const res = await fetch(API_BASE + 'delete_document.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id})
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                await loadDocuments();
            } catch (err) {
                alert('Delete failed: ' + err.message);
            }
        }

        function formatDateTime(s) {
            if (!s) return '-';
            const d = new Date(s.replace(' ', 'T'));
            if (isNaN(d)) return s;
            return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        }
        function formatNumber(n) { return Number(n).toLocaleString('en-GB'); }
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        // Modal close behaviour
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeTextModal(); });
        document.getElementById('textModal').addEventListener('click', e => {
            if (e.target.id === 'textModal') closeTextModal();
        });
    </script>
</body>
</html>
