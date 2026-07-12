<?php
/**
 * RFP Builder — list / dashboard
 * A feature of the Contracts module.
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
    <title><?php echo htmlspecialchars(t('contracts.rfp.list.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=20">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { --accent: var(--con-accent, #f59e0b); }

        .rfp-layout { display: flex; height: calc(100vh - 48px); background: var(--app-bg, #f5f5f5); }
        .rfp-sidebar {
            width: 260px; background: var(--surface, white); border-right: 1px solid var(--border, #ddd);
            padding: 20px; overflow-y: auto; flex-shrink: 0;
        }
        .rfp-main { flex: 1; overflow-y: auto; padding: 30px; }

        .sidebar-section { margin-bottom: 24px; }
        .sidebar-section h3 {
            font-size: 14px; font-weight: 600; color: var(--text, #333); margin: 0 0 12px 0;
        }
        .sidebar-stat {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 12px; border-radius: 6px; font-size: 14px; color: var(--text, #333);
            margin-bottom: 4px;
        }
        .sidebar-stat .stat-value { font-weight: 700; font-size: 16px; }
        .sidebar-links { display: flex; flex-direction: column; gap: 4px; }
        .sidebar-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 6px;
            font-size: 14px; color: var(--text, #333);
            text-decoration: none; transition: all 0.15s;
        }
        .sidebar-link:hover { background: #fff7ed; color: var(--con-accent, #f59e0b); }
        .sidebar-link svg { width: 18px; height: 18px; flex-shrink: 0; }

        .sidebar-add-btn {
            display: block; width: 100%; padding: 10px 16px;
            background: var(--con-accent, #f59e0b); color: white; border: none; border-radius: 6px;
            font-size: 14px; font-weight: 500; cursor: pointer;
            transition: background 0.2s; text-align: center;
        }
        .sidebar-add-btn:hover { background: var(--con-accent-hover, #d97706); }

        .section-card {
            background: var(--surface, #fff); border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
        }
        .section-card .section-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 18px 24px; border-bottom: 1px solid var(--border-soft, #eee);
        }
        .section-card .section-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: var(--text, #333); }

        .section-card table { width: 100%; border-collapse: collapse; }
        .section-card table th {
            text-align: left; padding: 12px 24px; font-size: 12px; font-weight: 600;
            color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-soft, #eee); background: var(--surface-2, #fafafa);
        }
        .section-card table td {
            padding: 14px 24px; font-size: 14px; color: var(--text, #333); border-bottom: 1px solid var(--border-soft, #f0f0f0);
        }
        .section-card table tr:last-child td { border-bottom: none; }
        .section-card table tr:hover { background: var(--surface-hover, #fafafa); }

        .rfp-name-link { color: var(--text, #333); font-weight: 600; text-decoration: none; }
        .rfp-name-link:hover { color: var(--con-accent, #f59e0b); }

        .status-badge {
            display: inline-block; padding: 4px 8px; border-radius: 3px;
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
            background: var(--surface-3, #f3f4f6); border-radius: 10px; text-align: center;
            font-size: 12px; font-weight: 600; color: var(--text-muted, #555);
        }

        .action-btn {
            background: none; border: 1px solid var(--border, #ddd); color: var(--text-muted, #666); cursor: pointer;
            padding: 6px; margin-right: 4px; border-radius: 4px;
            display: inline-flex; align-items: center; justify-content: center;
            transition: all 0.2s;
        }
        .action-btn:hover { background: var(--surface-hover, #f0f0f0); border-color: var(--con-accent, #f59e0b); color: var(--con-accent, #f59e0b); }
        .action-btn.danger:hover { border-color: #ef4444; color: #ef4444; background: #fef2f2; }
        .action-btn svg { width: 16px; height: 16px; }

        .empty-state { text-align: center; padding: 40px; color: var(--text-dim, #999); }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.4); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-card {
            background: var(--surface, white); border-radius: 10px; width: 100%; max-width: 560px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2); overflow: hidden;
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 24px; border-bottom: 1px solid var(--border-soft, #eee);
        }
        .modal-header h3 { margin: 0; font-size: 16px; font-weight: 600; }
        .modal-close {
            background: none; border: none; font-size: 24px; line-height: 1;
            cursor: pointer; color: var(--text-dim, #888); padding: 0 4px;
        }
        .modal-body { padding: 20px 24px; }
        .modal-footer {
            display: flex; justify-content: flex-end; gap: 8px;
            padding: 14px 24px; border-top: 1px solid var(--border-soft, #eee); background: var(--surface-2, #fafafa);
        }

        .form-row { margin-bottom: 16px; }
        .form-row label {
            display: block; font-size: 13px; font-weight: 600; color: var(--text-muted, #555);
            margin-bottom: 6px;
        }
        .form-row input, .form-row select, .form-row textarea {
            width: 100%; padding: 8px 10px; font-size: 14px;
            border: 1px solid var(--border, #ddd); border-radius: 6px; box-sizing: border-box;
            font-family: inherit;
        }
        .form-row textarea { min-height: 100px; resize: vertical; }
        .form-row input:focus, .form-row select:focus, .form-row textarea:focus {
            outline: none; border-color: var(--con-accent, #f59e0b);
        }
        .form-help { font-size: 12px; color: var(--text-dim, #888); margin-top: 4px; }

        .btn {
            padding: 8px 16px; font-size: 14px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: 1px solid transparent;
            transition: all 0.15s;
        }
        .btn-primary { background: var(--con-accent, #f59e0b); color: white; }
        .btn-primary:hover { background: var(--con-accent-hover, #d97706); }
        .btn-secondary { background: var(--surface, white); color: var(--text, #333); border-color: var(--border, #ddd); }
        .btn-secondary:hover { background: var(--surface-hover, #f5f5f5); }

        /* Pale-tint dark overrides (light values differ from token light values) */
        [data-theme-mode="dark"] .sidebar-link:hover { background: #3a2e12; }
        [data-theme-mode="dark"] .action-btn.danger:hover { background: #3a1a1a; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="rfp-layout">
        <div class="rfp-sidebar">
            <div class="sidebar-section">
                <h3><?php echo htmlspecialchars(t('contracts.list.overview')); ?></h3>
                <div class="sidebar-stat">
                    <span><?php echo htmlspecialchars(t('contracts.rfp.list.rfps')); ?></span>
                    <span class="stat-value" id="sideTotal">-</span>
                </div>
                <div class="sidebar-stat">
                    <span><?php echo htmlspecialchars(t('contracts.rfp.status.draft')); ?></span>
                    <span class="stat-value" id="sideDraft">-</span>
                </div>
                <div class="sidebar-stat">
                    <span><?php echo htmlspecialchars(t('contracts.rfp.list.in_progress')); ?></span>
                    <span class="stat-value" id="sideInProgress">-</span>
                </div>
                <div class="sidebar-stat">
                    <span><?php echo htmlspecialchars(t('contracts.rfp.status.closed')); ?></span>
                    <span class="stat-value" id="sideClosed">-</span>
                </div>
            </div>

            <div class="sidebar-section">
                <h3><?php echo htmlspecialchars(t('contracts.list.quick_links')); ?></h3>
                <div class="sidebar-links">
                    <a href="../" class="sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        <?php echo htmlspecialchars(t('contracts.rfp.list.back_to_contracts')); ?>
                    </a>
                    <a href="help.php" class="sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                        <?php echo htmlspecialchars(t('contracts.rfp.list.help_user_guide')); ?>
                    </a>
                </div>
            </div>

            <div class="sidebar-section">
                <button class="sidebar-add-btn" onclick="openCreateModal()">+ <?php echo htmlspecialchars(t('contracts.rfp.list.new_rfp')); ?></button>
            </div>
        </div>

        <div class="rfp-main">
            <div class="section-card">
                <div class="section-header">
                    <h2><?php echo htmlspecialchars(t('contracts.rfp.list.rfps')); ?></h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('contracts.rfp.list.col_name')); ?></th>
                            <th><?php echo htmlspecialchars(t('contracts.detail.status')); ?></th>
                            <th><?php echo htmlspecialchars(t('contracts.rfp.list.col_docs')); ?></th>
                            <th><?php echo htmlspecialchars(t('contracts.rfp.list.col_reqs')); ?></th>
                            <th><?php echo htmlspecialchars(t('contracts.nav.suppliers')); ?></th>
                            <th><?php echo htmlspecialchars(t('contracts.detail.created')); ?></th>
                            <th><?php echo htmlspecialchars(t('contracts.rfp.list.col_updated')); ?></th>
                            <th><?php echo htmlspecialchars(t('contracts.list.col_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="rfpList">
                        <tr><td colspan="8" class="empty-state"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create / Edit Modal -->
    <div class="modal-overlay" id="rfpModal">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="rfpModalTitle"><?php echo htmlspecialchars(t('contracts.rfp.list.new_rfp')); ?></h3>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rfpId">
                <div class="form-row">
                    <label for="rfpName"><?php echo htmlspecialchars(t('contracts.rfp.list.col_name')); ?> <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="rfpName" placeholder="<?php echo htmlspecialchars(t('contracts.rfp.list.name_ph')); ?>" maxlength="200">
                </div>
                <div class="form-row">
                    <label for="rfpStatus"><?php echo htmlspecialchars(t('contracts.detail.status')); ?></label>
                    <select id="rfpStatus">
                        <option value="draft"><?php echo htmlspecialchars(t('contracts.rfp.status.draft')); ?></option>
                        <option value="collecting"><?php echo htmlspecialchars(t('contracts.rfp.status.collecting')); ?></option>
                        <option value="consolidating"><?php echo htmlspecialchars(t('contracts.rfp.status.consolidating')); ?></option>
                        <option value="generating"><?php echo htmlspecialchars(t('contracts.rfp.status.generating')); ?></option>
                        <option value="scoring"><?php echo htmlspecialchars(t('contracts.rfp.status.scoring')); ?></option>
                        <option value="closed"><?php echo htmlspecialchars(t('contracts.rfp.status.closed')); ?></option>
                        <option value="abandoned"><?php echo htmlspecialchars(t('contracts.rfp.status.abandoned')); ?></option>
                    </select>
                </div>
                <div class="form-row">
                    <label for="rfpStyleGuide"><?php echo htmlspecialchars(t('contracts.rfp.list.style_guide_override')); ?></label>
                    <textarea id="rfpStyleGuide" placeholder="<?php echo htmlspecialchars(t('contracts.rfp.list.style_guide_ph')); ?>"></textarea>
                    <div class="form-help"><?php echo htmlspecialchars(t('contracts.rfp.list.style_guide_help')); ?></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" id="rfpSaveBtn" onclick="saveRfp()"><?php echo htmlspecialchars(t('common.save')); ?></button>
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

        function rfpStatusLabel(status) {
            const key = 'contracts.rfp.status.' + status;
            const label = window.t(key);
            return label === key ? status : label;
        }

        function renderRfps(rfps) {
            const tbody = document.getElementById('rfpList');
            if (rfps.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="empty-state">' + escapeHtml(window.t('contracts.rfp.list.empty')) + '</td></tr>';
                return;
            }
            tbody.innerHTML = rfps.map(r => `
                <tr>
                    <td><a href="view.php?id=${r.id}" class="rfp-name-link">${escapeHtml(r.name)}</a></td>
                    <td><span class="status-badge ${r.status}">${escapeHtml(rfpStatusLabel(r.status))}</span></td>
                    <td><span class="pill-stat">${r.document_count}</span></td>
                    <td><span class="pill-stat">${r.consolidated_count}</span></td>
                    <td><span class="pill-stat">${r.supplier_count}</span></td>
                    <td>${formatDate(r.created_datetime)}</td>
                    <td>${formatDate(r.updated_datetime)}</td>
                    <td>
                        <a href="view.php?id=${r.id}" class="action-btn" title="${escapeHtml(window.t('common.open'))}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </a>
                        <button class="action-btn" title="${escapeHtml(window.t('common.edit'))}" onclick='openEditModal(${JSON.stringify(r)})'>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn danger" title="${escapeHtml(window.t('common.delete'))}" onclick="deleteRfp(${r.id}, ${JSON.stringify(r.name)})">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function openCreateModal() {
            document.getElementById('rfpModalTitle').textContent = window.t('contracts.rfp.list.new_rfp');
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
                document.getElementById('rfpModalTitle').textContent = window.t('contracts.rfp.list.edit_rfp');
                document.getElementById('rfpId').value = full.id;
                document.getElementById('rfpName').value = full.name;
                document.getElementById('rfpStatus').value = full.status;
                document.getElementById('rfpStyleGuide').value = full.style_guide || '';
                document.getElementById('rfpModal').classList.add('active');
                setTimeout(() => document.getElementById('rfpName').focus(), 50);
            } catch (err) {
                showToast(window.t('contracts.rfp.list.load_one_failed') + ' ' + err.message, 'error');
            }
        }

        function closeModal() {
            document.getElementById('rfpModal').classList.remove('active');
        }

        async function saveRfp() {
            const name = document.getElementById('rfpName').value.trim();
            if (!name) { showToast(window.t('contracts.rfp.list.name_required'), 'error'); return; }
            const payload = {
                id: document.getElementById('rfpId').value || null,
                name,
                status: document.getElementById('rfpStatus').value,
                style_guide: document.getElementById('rfpStyleGuide').value
            };
            const btn = document.getElementById('rfpSaveBtn');
            btn.disabled = true; btn.textContent = window.t('common.saving');
            try {
                const res = await fetch(API_BASE + 'save_rfp.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.list.save_failed_short'));
                closeModal();
                showToast(window.t('contracts.rfp.list.toast_saved'), 'success');
                loadRfps();
            } catch (err) {
                showToast(window.t('contracts.rfp.list.save_failed') + ' ' + err.message, 'error');
            } finally {
                btn.disabled = false; btn.textContent = window.t('common.save');
            }
        }

        async function deleteRfp(id, name) {
            if (!(await showConfirm({ title: window.t('common.delete'), message: window.t('contracts.rfp.list.delete_confirm', { name: name }), okLabel: window.t('common.delete'), okClass: 'danger' }))) return;
            try {
                const res = await fetch(API_BASE + 'delete_rfp.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id})
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || window.t('contracts.rfp.list.delete_failed_short'));
                showToast(window.t('contracts.rfp.list.toast_deleted'), 'success');
                loadRfps();
            } catch (err) {
                showToast(window.t('contracts.rfp.list.delete_failed') + ' ' + err.message, 'error');
            }
        }

        function formatDate(s) {
            if (!s) return '-';
            const d = parseUTCDate(s);
            if (isNaN(d)) return s;
            return d.toLocaleDateString('en-GB', tzOpts({ day: '2-digit', month: 'short', year: 'numeric' }));
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
