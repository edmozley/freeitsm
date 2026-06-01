<?php
/**
 * Service Status Module - Settings
 * Manage the list of services
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'service-status'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('service-status.nav.settings')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* Override the shared .container max-width so this page uses the full screen */
        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; }

        .tab:hover { color: #10b981; }
        .tab.active { color: #10b981; border-bottom-color: #10b981; }

        .tab-content .action-btn {
            background: none;
            border: 1px solid #ddd;
            color: #666;
            cursor: pointer;
            padding: 6px;
            margin-right: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .tab-content .action-btn:hover { background: #f0f0f0; border-color: #10b981; color: #10b981; }
        .tab-content .action-btn.delete { color: #d13438; }
        .tab-content .action-btn.delete:hover { background: #fdf3f3; border-color: #d13438; color: #a00; }
        .tab-content .action-btn svg { width: 16px; height: 16px; }

        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.inactive { background: #f8d7da; color: #721c24; }

        /* Module accent — drives toggle, focus rings, button colours.
           Modal form CSS lives entirely in inbox.css. */
        body { --accent: #10b981; }

        .modal-content { padding: 20px; max-width: 500px; }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 20px; color: #333; padding: 0; border-bottom: none; }
        .modal-actions { margin-top: 20px; }

        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: background-color 0.15s; }
        .btn-primary { background-color: #10b981; color: white; }
        .btn-primary:hover { background-color: #059669; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="services" onclick="switchTab('services')"><?php echo htmlspecialchars(t('service-status.settings.tab_services')); ?></button>
            <button class="tab" data-tab="statuses" onclick="switchTab('statuses')"><?php echo htmlspecialchars(t('service-status.settings.tab_statuses')); ?></button>
            <button class="tab" data-tab="impacts" onclick="switchTab('impacts')"><?php echo htmlspecialchars(t('service-status.settings.tab_impacts')); ?></button>
        </div>

        <div class="tab-content active" id="services-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('service-status.settings.services_heading')); ?></h2>
                <button class="add-btn" onclick="openAddModal()"><?php echo htmlspecialchars(t('service-status.settings.add')); ?></button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('service-status.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('service-status.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('service-status.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('service-status.settings.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('service-status.settings.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="services-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('service-status.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Statuses Tab -->
        <div class="tab-content" id="statuses-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('service-status.settings.statuses_heading')); ?></h2>
                <button class="add-btn" onclick="openLookupModal('status')"><?php echo htmlspecialchars(t('service-status.settings.add')); ?></button>
            </div>
            <p style="color: #666; margin-bottom: 16px;"><?php echo t('service-status.settings.statuses_intro_html'); ?></p>
            <table>
                <thead><tr><th><?php echo htmlspecialchars(t('service-status.settings.col_name')); ?></th><th><?php echo htmlspecialchars(t('service-status.settings.col_colour')); ?></th><th><?php echo htmlspecialchars(t('service-status.settings.col_resolved')); ?></th><th><?php echo htmlspecialchars(t('service-status.settings.col_default')); ?></th><th><?php echo htmlspecialchars(t('service-status.settings.col_order')); ?></th><th><?php echo htmlspecialchars(t('service-status.settings.col_status')); ?></th><th><?php echo htmlspecialchars(t('service-status.settings.col_actions')); ?></th></tr></thead>
                <tbody id="statuses-list"><tr><td colspan="7" style="text-align:center;padding:20px;color:#999;"><?php echo htmlspecialchars(t('service-status.settings.loading')); ?></td></tr></tbody>
            </table>
        </div>

        <!-- Impact levels Tab -->
        <div class="tab-content" id="impacts-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('service-status.settings.impacts_heading')); ?></h2>
                <button class="add-btn" onclick="openLookupModal('impact')"><?php echo htmlspecialchars(t('service-status.settings.add')); ?></button>
            </div>
            <p style="color: #666; margin-bottom: 16px;"><?php echo t('service-status.settings.impacts_intro_html'); ?></p>
            <table>
                <thead><tr><th><?php echo htmlspecialchars(t('service-status.settings.col_name')); ?></th><th><?php echo htmlspecialchars(t('service-status.settings.col_colour')); ?></th><th><?php echo htmlspecialchars(t('service-status.settings.col_severity')); ?></th><th><?php echo htmlspecialchars(t('service-status.settings.col_default')); ?></th><th><?php echo htmlspecialchars(t('service-status.settings.col_order')); ?></th><th><?php echo htmlspecialchars(t('service-status.settings.col_status')); ?></th><th><?php echo htmlspecialchars(t('service-status.settings.col_actions')); ?></th></tr></thead>
                <tbody id="impacts-list"><tr><td colspan="7" style="text-align:center;padding:20px;color:#999;"><?php echo htmlspecialchars(t('service-status.settings.loading')); ?></td></tr></tbody>
            </table>
        </div>
    </div>

    <!-- Lookup edit modal (Statuses + Impact Levels share this) -->
    <div class="modal" id="lookupModal">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header" id="lookupModalTitle"><?php echo htmlspecialchars(t('service-status.settings.add_item')); ?></div>
            <form id="lookupForm">
                <input type="hidden" id="lookupItemKind">
                <input type="hidden" id="lookupItemId">

                <div class="form-group">
                    <label for="lookupItemName"><?php echo htmlspecialchars(t('service-status.settings.field_name')); ?></label>
                    <input type="text" id="lookupItemName" required>
                </div>

                <div class="form-group">
                    <label for="lookupItemColour"><?php echo htmlspecialchars(t('service-status.settings.field_colour')); ?></label>
                    <input type="color" id="lookupItemColour" value="#10b981" style="width: 60px; height: 32px; padding: 2px;">
                </div>

                <div class="form-group" id="lookupItemResolvedGroup" style="display: none;">
                    <label class="toggle-label">
                        <span class="toggle-switch"><input type="checkbox" id="lookupItemResolved"><span class="toggle-slider"></span></span>
                        <?php echo htmlspecialchars(t('service-status.settings.field_resolved')); ?>
                    </label>
                    <small style="display:block; color:#666; margin-top:4px;"><?php echo t('service-status.settings.resolved_help_html'); ?></small>
                </div>

                <div class="form-group" id="lookupItemSeverityGroup" style="display: none;">
                    <label for="lookupItemSeverityOrder"><?php echo htmlspecialchars(t('service-status.settings.field_severity')); ?></label>
                    <input type="number" id="lookupItemSeverityOrder" value="99" min="1">
                    <small style="display:block; color:#666; margin-top:4px;"><?php echo htmlspecialchars(t('service-status.settings.severity_help')); ?></small>
                </div>

                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch"><input type="checkbox" id="lookupItemDefault"><span class="toggle-slider"></span></span>
                        <?php echo htmlspecialchars(t('service-status.settings.field_default')); ?>
                    </label>
                </div>

                <div class="form-group">
                    <label for="lookupItemOrder"><?php echo htmlspecialchars(t('service-status.settings.field_order')); ?></label>
                    <input type="number" id="lookupItemOrder" value="0">
                </div>

                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch"><input type="checkbox" id="lookupItemActive" checked><span class="toggle-slider"></span></span>
                        <?php echo htmlspecialchars(t('service-status.settings.field_active')); ?>
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeLookupModal()"><?php echo htmlspecialchars(t('service-status.settings.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('service-status.settings.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit/Add Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle"><?php echo htmlspecialchars(t('service-status.settings.add_service')); ?></div>
            <form id="editForm" autocomplete="off">
                <input type="hidden" id="itemId">
                <div class="form-group">
                    <label for="itemName"><?php echo htmlspecialchars(t('service-status.settings.field_name')); ?></label>
                    <input type="text" id="itemName" required>
                </div>
                <div class="form-group">
                    <label for="itemDescription"><?php echo htmlspecialchars(t('service-status.settings.field_description')); ?></label>
                    <textarea id="itemDescription"></textarea>
                </div>
                <div class="form-group">
                    <label for="itemOrder"><?php echo htmlspecialchars(t('service-status.settings.field_order')); ?></label>
                    <input type="number" id="itemOrder" value="0" min="0">
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="itemActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('service-status.settings.field_active')); ?>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()"><?php echo htmlspecialchars(t('service-status.settings.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('service-status.settings.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/service-status/';
        let allServices = [];

        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadServices();
            loadLookup('status');
            loadLookup('impact');
        });

        async function loadServices() {
            try {
                const response = await fetch(API_BASE + 'get_services.php');
                const data = await response.json();
                if (data.success) {
                    allServices = data.services;
                    renderServices(data.services);
                } else {
                    document.getElementById('services-list').innerHTML =
                        '<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">' + escapeHtml(window.t('service-status.settings.error_prefix', { message: data.error })) + '</td></tr>';
                }
            } catch (error) {
                document.getElementById('services-list').innerHTML =
                    '<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">' + escapeHtml(window.t('service-status.settings.load_failed')) + '</td></tr>';
            }
        }

        function renderServices(items) {
            const tbody = document.getElementById('services-list');

            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#999;">' + escapeHtml(window.t('service-status.settings.no_services')) + '</td></tr>';
                return;
            }

            tbody.innerHTML = items.map(item => `
                <tr>
                    <td><strong>${escapeHtml(item.name)}</strong></td>
                    <td>${escapeHtml(item.description || '-')}</td>
                    <td>${item.display_order}</td>
                    <td><span class="status-badge ${item.is_active ? 'active' : 'inactive'}">${item.is_active ? escapeHtml(window.t('service-status.settings.active')) : escapeHtml(window.t('service-status.settings.inactive'))}</span></td>
                    <td>
                        <button class="action-btn" onclick="editService(${item.id})" title="${escapeHtml(window.t('service-status.settings.edit'))}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteService(${item.id}, '${escapeHtml(item.name)}')" title="${escapeHtml(window.t('service-status.settings.delete'))}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function openAddModal() {
            document.getElementById('modalTitle').textContent = window.t('service-status.settings.add_service');
            document.getElementById('itemId').value = '';
            document.getElementById('itemName').value = '';
            document.getElementById('itemDescription').value = '';
            document.getElementById('itemOrder').value = '0';
            document.getElementById('itemActive').checked = true;
            document.getElementById('editModal').classList.add('active');
        }

        function editService(id) {
            const item = allServices.find(i => i.id == id);
            if (!item) return;

            document.getElementById('modalTitle').textContent = window.t('service-status.settings.edit_service');
            document.getElementById('itemId').value = item.id;
            document.getElementById('itemName').value = item.name;
            document.getElementById('itemDescription').value = item.description || '';
            document.getElementById('itemOrder').value = item.display_order || 0;
            document.getElementById('itemActive').checked = item.is_active;
            document.getElementById('editModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const payload = {
                id: document.getElementById('itemId').value || null,
                name: document.getElementById('itemName').value,
                description: document.getElementById('itemDescription').value,
                display_order: parseInt(document.getElementById('itemOrder').value) || 0,
                is_active: document.getElementById('itemActive').checked ? 1 : 0
            };

            try {
                const response = await fetch(API_BASE + 'save_service.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    closeModal();
                    showToast(window.t('service-status.toast.saved'), 'success');
                    loadServices();
                } else {
                    showToast(data.error || window.t('service-status.toast.save_failed'), 'error');
                }
            } catch (error) {
                showToast(window.t('service-status.toast.save_service_failed'), 'error');
            }
        });

        async function deleteService(id, name) {
            if (!(await showConfirm({ title: window.t('service-status.confirm.delete_title'), message: window.t('service-status.confirm.delete_message', { name: name }), okLabel: window.t('service-status.confirm.delete_label'), okClass: 'danger' }))) return;

            try {
                const response = await fetch(API_BASE + 'delete_service.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('service-status.toast.deleted'), 'success');
                    loadServices();
                } else {
                    showToast(data.error || window.t('service-status.toast.delete_failed'), 'error');
                }
            } catch (error) {
                showToast(window.t('service-status.toast.delete_service_failed'), 'error');
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modal on outside click
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // ============================================================
        // Lookup tabs (Statuses + Impact Levels)
        // ============================================================

        const LOOKUP_KINDS = {
            'status': { get: 'get_incident_statuses.php', save: 'save_incident_status.php', del: 'delete_incident_status.php', listKey: 'statuses',      tableId: 'statuses-list', colspan: 7, hasResolved: true,  hasSeverity: false, labelKey: 'service-status.settings.kind_status' },
            'impact': { get: 'get_impact_levels.php',     save: 'save_impact_level.php',    del: 'delete_impact_level.php',    listKey: 'impact_levels', tableId: 'impacts-list',  colspan: 7, hasResolved: false, hasSeverity: true,  labelKey: 'service-status.settings.kind_impact' }
        };

        const lookupCache = { status: [], impact: [] };

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
                tbody.innerHTML = `<tr><td colspan="${cfg.colspan}" style="text-align:center;padding:20px;color:#999;">${escapeHtml(window.t('service-status.settings.no_items'))}</td></tr>`;
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const safeName = escapeHtml(r.name).replace(/'/g, "\\'");
                const swatch = r.colour
                    ? `<span style="display:inline-block;width:18px;height:18px;border-radius:3px;background:${escapeHtml(r.colour)};vertical-align:middle;border:1px solid #ddd;margin-right:6px;"></span><code style="font-size:12px;">${escapeHtml(r.colour)}</code>`
                    : '<span style="color:#999;">—</span>';
                const yesBadge = `<span class="status-badge active">${escapeHtml(window.t('service-status.settings.yes'))}</span>`;
                const noBadge = `<span style="color:#999;">${escapeHtml(window.t('service-status.settings.no'))}</span>`;
                const flagCol = cfg.hasResolved
                    ? `<td>${r.is_resolved ? yesBadge : noBadge}</td>`
                    : `<td>${r.severity_order}</td>`;
                return `
                <tr>
                    <td><strong>${escapeHtml(r.name)}</strong></td>
                    <td>${swatch}</td>
                    ${flagCol}
                    <td>${r.is_default ? yesBadge : noBadge}</td>
                    <td>${r.display_order}</td>
                    <td><span class="status-badge ${r.is_active ? 'active' : 'inactive'}">${r.is_active ? escapeHtml(window.t('service-status.settings.active')) : escapeHtml(window.t('service-status.settings.inactive'))}</span></td>
                    <td>
                        <button class="action-btn" onclick="editLookup('${kind}', ${r.id})" title="${escapeHtml(window.t('service-status.settings.edit'))}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteLookup('${kind}', ${r.id}, '${safeName}')" title="${escapeHtml(window.t('service-status.settings.delete'))}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        function openLookupModal(kind) {
            const cfg = LOOKUP_KINDS[kind];
            document.getElementById('lookupModalTitle').textContent = window.t('service-status.settings.add_kind', { kind: window.t(cfg.labelKey) });
            document.getElementById('lookupItemKind').value = kind;
            document.getElementById('lookupItemId').value = '';
            document.getElementById('lookupItemName').value = '';
            document.getElementById('lookupItemColour').value = '#10b981';
            document.getElementById('lookupItemResolved').checked = false;
            document.getElementById('lookupItemDefault').checked = false;
            document.getElementById('lookupItemSeverityOrder').value = '99';
            document.getElementById('lookupItemOrder').value = '0';
            document.getElementById('lookupItemActive').checked = true;
            document.getElementById('lookupItemResolvedGroup').style.display = cfg.hasResolved ? '' : 'none';
            document.getElementById('lookupItemSeverityGroup').style.display = cfg.hasSeverity ? '' : 'none';
            document.getElementById('lookupModal').classList.add('active');
        }

        function editLookup(kind, id) {
            const cfg = LOOKUP_KINDS[kind];
            const item = (lookupCache[kind] || []).find(r => r.id == id);
            if (!item) return;
            document.getElementById('lookupModalTitle').textContent = window.t('service-status.settings.edit_kind', { kind: window.t(cfg.labelKey) });
            document.getElementById('lookupItemKind').value = kind;
            document.getElementById('lookupItemId').value = item.id;
            document.getElementById('lookupItemName').value = item.name;
            document.getElementById('lookupItemColour').value = item.colour || '#10b981';
            document.getElementById('lookupItemResolved').checked = !!item.is_resolved;
            document.getElementById('lookupItemDefault').checked = !!item.is_default;
            document.getElementById('lookupItemSeverityOrder').value = item.severity_order ?? 99;
            document.getElementById('lookupItemOrder').value = item.display_order;
            document.getElementById('lookupItemActive').checked = !!item.is_active;
            document.getElementById('lookupItemResolvedGroup').style.display = cfg.hasResolved ? '' : 'none';
            document.getElementById('lookupItemSeverityGroup').style.display = cfg.hasSeverity ? '' : 'none';
            document.getElementById('lookupModal').classList.add('active');
        }

        function closeLookupModal() {
            document.getElementById('lookupModal').classList.remove('active');
        }

        async function deleteLookup(kind, id, name) {
            if (!(await showConfirm({ title: window.t('service-status.confirm.delete_title'), message: window.t('service-status.confirm.delete_message', { name: name }), okLabel: window.t('service-status.confirm.delete_label'), okClass: 'danger' }))) return;
            const cfg = LOOKUP_KINDS[kind];
            try {
                const res = await fetch(API_BASE + cfg.del, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id: id})
                });
                const data = await res.json();
                if (data.success) { showToast(window.t('service-status.toast.deleted'), 'success'); loadLookup(kind); }
                else showToast(data.error || window.t('service-status.toast.delete_failed'), 'error');
            } catch (e) { showToast(window.t('service-status.toast.delete_failed'), 'error'); }
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
            if (cfg.hasResolved) payload.is_resolved = document.getElementById('lookupItemResolved').checked ? 1 : 0;
            if (cfg.hasSeverity) payload.severity_order = parseInt(document.getElementById('lookupItemSeverityOrder').value || '99', 10);

            try {
                const res = await fetch(API_BASE + cfg.save, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) { closeLookupModal(); showToast(window.t('service-status.toast.saved'), 'success'); loadLookup(kind); }
                else showToast(data.error || window.t('service-status.toast.save_failed'), 'error');
            } catch (e) { showToast(window.t('service-status.toast.save_failed'), 'error'); }
        });

        document.getElementById('lookupModal').addEventListener('click', function(e) {
            if (e.target === this) closeLookupModal();
        });
    </script>
</body>
</html>
