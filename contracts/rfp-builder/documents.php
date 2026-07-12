<?php
/**
 * RFP Builder — per-RFP source documents page.
 * Upload, view, re-extract and delete the .docx files that feed Pass 1.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('contracts');

$current_page = 'rfp-builder';
$path_prefix = '../../';
$translationNamespaces = ['common', 'contracts'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('contracts.rfp.documents.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { --accent: var(--con-accent, #f59e0b); }
        .page-wrap { padding: 30px 40px; background: var(--app-bg, #f5f5f5); height: calc(100vh - 48px); overflow-y: auto; box-sizing: border-box; }

        .breadcrumb { font-size: 13px; color: var(--text-dim, #888); margin-bottom: 8px; }
        .breadcrumb a { color: var(--text-muted, #666); text-decoration: none; }
        .breadcrumb a:hover { color: var(--con-accent, #f59e0b); }
        .breadcrumb span { margin: 0 6px; color: var(--text-faint, #ccc); }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px;
        }
        .page-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: var(--text, #222); }
        .page-actions { display: flex; gap: 8px; }

        .btn {
            padding: 8px 16px; font-size: 14px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: 1px solid transparent;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.15s;
        }
        .btn-primary { background: var(--con-accent, #f59e0b); color: white; }
        .btn-primary:hover:not(:disabled) { background: var(--con-accent-hover, #d97706); }
        .btn-primary:disabled { background: #fcd34d; cursor: not-allowed; }
        .btn-secondary { background: var(--surface, white); color: var(--text, #333); border-color: var(--border, #ddd); }
        .btn-secondary:hover { background: var(--surface-hover, #f5f5f5); }

        .card {
            background: var(--surface, #fff); border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            padding: 16px 24px; border-bottom: 1px solid var(--border-soft, #eee);
            font-size: 15px; font-weight: 600; color: var(--text, #333);
        }
        .card-body { padding: 20px 24px; }

        /* Upload form */
        .upload-form { display: grid; grid-template-columns: 1fr 220px auto; gap: 12px; align-items: end; }
        .upload-form .field { display: flex; flex-direction: column; }
        .upload-form label {
            font-size: 12px; font-weight: 600; color: var(--text-muted, #555);
            margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .upload-form input[type=file], .upload-form select {
            padding: 8px 10px; font-size: 14px;
            border: 1px solid var(--border, #ddd); border-radius: 6px; box-sizing: border-box;
            font-family: inherit;
        }
        .upload-form input[type=file]:focus, .upload-form select:focus {
            outline: none; border-color: var(--con-accent, #f59e0b);
        }
        .upload-status {
            font-size: 13px; color: var(--text-muted, #555); margin-top: 12px;
        }
        .upload-status.error { color: #d13438; }
        .upload-status.success { color: #065f46; }
        .upload-status.busy { color: #b45309; }
        .upload-help {
            font-size: 12px; color: var(--text-dim, #888); margin-top: 8px;
        }

        /* Documents table */
        table { width: 100%; border-collapse: collapse; }
        thead th {
            text-align: left; padding: 12px 24px; font-size: 12px; font-weight: 600;
            color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-soft, #eee); background: var(--surface-2, #fafafa);
        }
        tbody td {
            padding: 14px 24px; font-size: 14px; color: var(--text, #333); border-bottom: 1px solid var(--border-soft, #f0f0f0);
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: var(--surface-hover, #fafafa); }

        .filename { font-weight: 600; color: var(--text, #222); }
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
            background: none; border: 1px solid var(--border, #ddd); color: var(--text-muted, #666); cursor: pointer;
            padding: 6px; margin-right: 4px; border-radius: 4px;
            display: inline-flex; align-items: center; justify-content: center;
            transition: all 0.2s;
        }
        .action-btn:hover { background: var(--surface-hover, #f0f0f0); border-color: var(--con-accent, #f59e0b); color: var(--con-accent, #f59e0b); }
        .action-btn.danger:hover { border-color: #ef4444; color: #ef4444; background: #fef2f2; }
        .action-btn svg { width: 16px; height: 16px; }
        .action-btn:disabled { opacity: 0.4; cursor: not-allowed; }

        .empty-state { text-align: center; padding: 40px; color: var(--text-dim, #999); }

        /* View text modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.4); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-card {
            background: var(--surface, white); border-radius: 10px; width: 90%; max-width: 900px;
            max-height: 85vh; display: flex; flex-direction: column;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2); overflow: hidden;
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 24px; border-bottom: 1px solid var(--border-soft, #eee);
        }
        .modal-header h3 { margin: 0; font-size: 16px; font-weight: 600; }
        .modal-meta { font-size: 12px; color: var(--text-dim, #888); }
        .modal-close {
            background: none; border: none; font-size: 24px; line-height: 1;
            cursor: pointer; color: var(--text-dim, #888); padding: 0 4px;
        }
        .modal-body {
            padding: 0; flex: 1; overflow: hidden; display: flex;
        }
        .modal-body pre {
            margin: 0; padding: 20px 24px; flex: 1; overflow: auto;
            white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 13px; line-height: 1.5; color: var(--text, #333); background: var(--surface-2, #fafafa);
        }
        .modal-footer {
            display: flex; justify-content: flex-end; gap: 10px;
            padding: 12px 24px; border-top: 1px solid var(--border-soft, #eee); background: var(--surface, #fff);
        }

        .alert-info {
            background: #fff7ed; border-left: 4px solid var(--con-accent, #f59e0b);
            padding: 12px 16px; border-radius: 6px; font-size: 13px; color: #7c2d12;
            margin-bottom: 16px;
        }
        .alert-info a { color: var(--con-accent-hover, #b45309); font-weight: 600; }
        [data-theme-mode="dark"] .alert-info { background: #3a2e12; color: #fde8c8; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../"><?php echo htmlspecialchars(t('contracts.title')); ?></a><span>›</span>
            <a href="./"><?php echo htmlspecialchars(t('contracts.nav.rfp_builder')); ?></a><span>›</span>
            <a href="view.php" id="bcRfp"><?php echo htmlspecialchars(t('contracts.rfp.documents.rfp')); ?></a><span>›</span>
            <span><?php echo htmlspecialchars(t('contracts.rfp.documents.documents')); ?></span>
        </div>

        <div class="page-header">
            <h1><?php echo htmlspecialchars(t('contracts.rfp.documents.heading')); ?> — <span id="rfpName" style="color:var(--con-accent, #f59e0b);"><?php echo htmlspecialchars(t('common.loading')); ?></span></h1>
            <div class="page-actions">
                <a href="view.php" id="backLink" class="btn btn-secondary">&larr; <?php echo htmlspecialchars(t('contracts.rfp.documents.rfp_overview')); ?></a>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><?php echo htmlspecialchars(t('contracts.rfp.documents.upload_new')); ?></div>
            <div class="card-body">
                <div id="noDeptsAlert" class="alert-info" style="display:none;">
                    <strong><?php echo htmlspecialchars(t('contracts.rfp.documents.no_depts_title')); ?></strong> <?php echo htmlspecialchars(t('contracts.rfp.documents.no_depts_body_1')); ?>
                    <a href="../settings/" target="_blank"><?php echo htmlspecialchars(t('contracts.rfp.documents.no_depts_link')); ?></a>
                    <?php echo htmlspecialchars(t('contracts.rfp.documents.no_depts_body_2')); ?>
                </div>
                <form id="uploadForm" class="upload-form" autocomplete="off">
                    <div class="field">
                        <label for="fileInput"><?php echo htmlspecialchars(t('contracts.rfp.documents.docx_file')); ?></label>
                        <input type="file" id="fileInput" accept=".docx" required>
                    </div>
                    <div class="field">
                        <label for="deptSelect"><?php echo htmlspecialchars(t('contracts.rfp.documents.department')); ?></label>
                        <select id="deptSelect">
                            <option value="">— <?php echo htmlspecialchars(t('contracts.rfp.documents.unassigned')); ?> —</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>&nbsp;</label>
                        <button type="submit" id="uploadBtn" class="btn btn-primary"><?php echo htmlspecialchars(t('contracts.rfp.documents.upload')); ?></button>
                    </div>
                </form>
                <div id="uploadStatus" class="upload-status"></div>
                <div class="upload-help">
                    <?php echo htmlspecialchars(t('contracts.rfp.documents.upload_help')); ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><?php echo htmlspecialchars(t('contracts.rfp.documents.uploaded_documents')); ?></div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('contracts.rfp.documents.col_filename')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.rfp.documents.department')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.detail.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.rfp.documents.col_size')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.rfp.list.col_reqs')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.rfp.documents.col_uploaded')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.list.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="docsList">
                    <tr><td colspan="7" class="empty-state"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- View text modal -->
    <div class="modal-overlay" id="textModal">
        <div class="modal-card">
            <div class="modal-header">
                <div>
                    <h3 id="modalTitle"><?php echo htmlspecialchars(t('contracts.rfp.documents.extracted_text')); ?></h3>
                    <div class="modal-meta" id="modalMeta">-</div>
                </div>
            </div>
            <div class="modal-body">
                <pre id="modalText"><?php echo htmlspecialchars(t('common.loading')); ?></pre>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeTextModal()"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const rfpId = new URLSearchParams(location.search).get('id');

        document.addEventListener('DOMContentLoaded', async () => {
            if (!rfpId) {
                document.getElementById('rfpName').textContent = window.t('contracts.rfp.documents.no_rfp_selected');
                document.getElementById('docsList').innerHTML =
                    '<tr><td colspan="6" class="empty-state">' + window.t('contracts.rfp.view.no_id') + ' <a href="./">' + window.t('contracts.rfp.view.back_to_list') + '</a>.</td></tr>';
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
                document.title = window.t('contracts.rfp.documents.title_with_name', { name: data.rfp.name });
            } catch (err) {
                document.getElementById('rfpName').textContent = window.t('contracts.rfp.documents.could_not_load');
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
                sel.innerHTML = '<option value="">— ' + escapeHtml(window.t('contracts.rfp.documents.unassigned')) + ' —</option>' +
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
                tbody.innerHTML = '<tr><td colspan="7" class="empty-state">' + escapeHtml(window.t('contracts.rfp.documents.empty')) + '</td></tr>';
                return;
            }
            tbody.innerHTML = docs.map(d => {
                const deptBadge = d.department_name
                    ? `<span class="dept-badge" style="background:${escapeHtml(d.department_colour || '#6c757d')};">${escapeHtml(d.department_name)}</span>`
                    : `<span class="dept-badge empty">${escapeHtml(window.t('contracts.rfp.documents.unassigned'))}</span>`;
                const sizeText = d.has_text ? window.t('contracts.rfp.documents.chars', { n: formatNumber(d.char_count) }) : '—';
                const reqsCell = d.extracted_count > 0
                    ? `<span style="display:inline-block; min-width:24px; padding:2px 8px; background:#d1fae5; color:#065f46; border-radius:10px; text-align:center; font-size:12px; font-weight:600;">${d.extracted_count}</span>`
                    : `<span style="color:var(--text-faint, #bbb);">—</span>`;
                const extractTitle = d.extracted_count > 0 ? window.t('contracts.rfp.documents.reextract_title') : window.t('contracts.rfp.documents.extract_title');
                return `
                    <tr>
                        <td><span class="filename">${escapeHtml(d.original_filename)}</span></td>
                        <td>${deptBadge}</td>
                        <td><span class="doc-status ${d.status}">${escapeHtml(docStatusLabel(d.status))}</span></td>
                        <td>${sizeText}</td>
                        <td>${reqsCell}</td>
                        <td>${formatDateTime(d.uploaded_datetime)}</td>
                        <td>
                            <button class="action-btn" title="${escapeHtml(window.t('contracts.rfp.documents.view_text'))}" onclick="viewText(${d.id})" ${d.has_text ? '' : 'disabled'}>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                            <button class="action-btn" title="${escapeHtml(extractTitle)}" data-reextract="${d.extracted_count > 0 ? '1' : '0'}" onclick="extractRequirements(${d.id}, this)" ${d.has_text ? '' : 'disabled'}>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v3"></path><path d="M12 18v3"></path><path d="M5.6 5.6l2.1 2.1"></path><path d="M16.3 16.3l2.1 2.1"></path><path d="M3 12h3"></path><path d="M18 12h3"></path><path d="M5.6 18.4l2.1-2.1"></path><path d="M16.3 7.7l2.1-2.1"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                            <button class="action-btn" title="${escapeHtml(window.t('contracts.rfp.documents.rerun_extraction'))}" onclick="reExtract(${d.id}, this)">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10"></path><path d="M20.49 15a9 9 0 0 1-14.85 3.36L1 14"></path></svg>
                            </button>
                            <button class="action-btn danger" title="${escapeHtml(window.t('common.delete'))}" onclick="deleteDoc(${d.id}, ${JSON.stringify(d.original_filename)})">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"></path><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"></path></svg>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        async function extractRequirements(id, btn) {
            const wasExtracted = btn.dataset.reextract === '1';
            if (wasExtracted) {
                const ok = await showConfirm({
                    title: window.t('contracts.rfp.documents.reextract_confirm_title'),
                    message: window.t('contracts.rfp.documents.reextract_confirm_msg'),
                    okLabel: window.t('contracts.rfp.documents.continue'),
                    okClass: 'primary'
                });
                if (!ok) return;
            }

            btn.disabled = true;
            setStatus(window.t('contracts.rfp.documents.extracting'), 'busy');
            try {
                const res = await fetch(API_BASE + 'extract_requirements.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({document_id: id})
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                const cacheNote = (data.cache_read || data.cache_write)
                    ? ' · ' + window.t('contracts.rfp.documents.cache_note', { read: data.cache_read || 0, written: data.cache_write || 0 })
                    : '';
                setStatus(
                    window.t('contracts.rfp.documents.extract_result', { count: data.count, secs: (data.duration_ms / 1000).toFixed(1), in: data.tokens_in, out: data.tokens_out }) + cacheNote + '.',
                    'success'
                );
                await loadDocuments();
            } catch (err) {
                setStatus(window.t('contracts.rfp.documents.extract_failed') + ' ' + err.message, 'error');
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
                setStatus(window.t('contracts.rfp.documents.pick_file'), 'error');
                return;
            }

            const fd = new FormData();
            fd.append('rfp_id', rfpId);
            fd.append('department_id', deptSel.value);
            fd.append('file', f);

            btn.disabled = true; btn.textContent = window.t('contracts.rfp.documents.uploading');
            setStatus(window.t('contracts.rfp.documents.uploading_extracting'), 'busy');

            try {
                const res = await fetch(API_BASE + 'upload_document.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.documents.upload_failed_short'));

                if (data.status === 'extracted') {
                    setStatus(window.t('contracts.rfp.documents.uploaded_words', { n: data.word_count }), 'success');
                } else if (data.status === 'error') {
                    setStatus(window.t('contracts.rfp.documents.uploaded_extract_failed', { error: data.extraction_error }), 'error');
                } else {
                    setStatus(window.t('contracts.rfp.documents.uploaded'), 'success');
                }

                fileInput.value = '';
                deptSel.value = '';
                await loadDocuments();
            } catch (err) {
                setStatus(window.t('contracts.rfp.documents.upload_failed') + ' ' + err.message, 'error');
            } finally {
                btn.disabled = false; btn.textContent = window.t('contracts.rfp.documents.upload');
            }
        });

        function setStatus(msg, kind) {
            const el = document.getElementById('uploadStatus');
            el.textContent = msg;
            el.className = 'upload-status ' + (kind || '');
        }

        async function viewText(id) {
            const overlay = document.getElementById('textModal');
            document.getElementById('modalTitle').textContent = window.t('contracts.rfp.documents.extracted_text');
            document.getElementById('modalMeta').textContent = window.t('common.loading');
            document.getElementById('modalText').textContent = window.t('common.loading');
            overlay.classList.add('active');
            try {
                const res = await fetch(API_BASE + 'get_document_text.php?id=' + id);
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                const d = data.document;
                document.getElementById('modalTitle').textContent = d.original_filename;
                document.getElementById('modalMeta').textContent =
                    window.t('contracts.rfp.documents.modal_meta', { words: formatNumber(d.word_count), chars: formatNumber(d.char_count), status: docStatusLabel(d.status) });
                document.getElementById('modalText').textContent = d.raw_text || window.t('contracts.rfp.documents.no_text_extracted');
            } catch (err) {
                document.getElementById('modalText').textContent = window.t('contracts.rfp.documents.failed_to_load') + ' ' + err.message;
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
                showToast(window.t('contracts.rfp.documents.reextract_failed') + ' ' + err.message, 'error');
            } finally {
                btn.disabled = false;
            }
        }

        async function deleteDoc(id, name) {
            if (!(await showConfirm({ title: window.t('common.delete'), message: window.t('contracts.rfp.documents.delete_confirm', { name: name }), okLabel: window.t('common.delete'), okClass: 'danger' }))) return;
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
                showToast(window.t('contracts.rfp.list.delete_failed') + ' ' + err.message, 'error');
            }
        }

        function formatDateTime(s) {
            if (!s) return '-';
            const d = parseUTCDate(s);
            if (isNaN(d)) return s;
            return d.toLocaleString('en-GB', tzOpts({ day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }));
        }
        function formatNumber(n) { return Number(n).toLocaleString('en-GB'); }
        function docStatusLabel(s) {
            const key = 'contracts.rfp.documents.status_' + s;
            const label = window.t(key);
            return label === key ? s : label;
        }
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
