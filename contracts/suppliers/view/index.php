<?php
/**
 * Contracts Module - View Supplier
 */
session_start();
require_once '../../../config.php';
require_once __DIR__ . '/../../../includes/i18n.php';
require_once '../../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'suppliers';
$path_prefix = '../../../';
$translationNamespaces = ['common', 'contracts'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('contracts.suppliers.view_page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../../assets/js/tz.js?v=1"></script>
    <script src="../../../assets/js/i18n.js"></script>
    <link rel="stylesheet" href="../../../assets/css/inbox.css">
    <style>
        /* Sidebar layout - matches suppliers list */
        .contracts-layout {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }
        .contracts-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            overflow-y: auto;
            flex-shrink: 0;
        }
        .contracts-main {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }

        .sidebar-section { margin-bottom: 24px; }
        .sidebar-section h3 {
            font-size: 14px; font-weight: 600; color: #333;
            margin: 0 0 12px 0;
        }
        .sidebar-section h4 {
            font-size: 13px; font-weight: 600; color: #555;
            margin: 14px 0 6px 0;
        }
        .sidebar-section h4:first-of-type { margin-top: 0; }

        .sidebar-stat {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 12px; border-radius: 6px;
            font-size: 13px; color: #555; cursor: default;
            margin-bottom: 2px;
        }
        .sidebar-stat .stat-value { font-weight: 700; font-size: 14px; color: #333; }
        .sidebar-total {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 12px; border-radius: 6px;
            font-size: 14px; color: #333; cursor: default;
            margin-bottom: 4px; background: #fafafa;
        }
        .sidebar-total .stat-value { font-weight: 700; font-size: 16px; }

        .sidebar-add-btn {
            display: block; width: 100%;
            padding: 10px 16px;
            background: #f59e0b; color: white;
            border: none; border-radius: 6px;
            font-size: 14px; font-weight: 500;
            cursor: pointer; transition: background 0.2s;
            text-align: center; text-decoration: none;
            box-sizing: border-box;
        }
        .sidebar-add-btn:hover { background: #d97706; }

        /* Main card */
        .section-card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
        }
        .section-card .section-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 18px 24px; border-bottom: 1px solid #eee;
        }
        .section-card .section-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: #333; }
        .section-card .section-header .header-actions { display: flex; align-items: center; gap: 10px; }

        .back-link {
            display: inline-flex; align-items: center; gap: 6px;
            color: #888; text-decoration: none; font-size: 13px;
        }
        .back-link:hover { color: #f59e0b; }

        .action-btn {
            background: none; border: 1px solid #ddd; color: #666; cursor: pointer;
            padding: 6px; border-radius: 4px;
            display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;
        }
        .action-btn:hover { background: #f0f0f0; border-color: #f59e0b; color: #f59e0b; }
        .action-btn svg { width: 16px; height: 16px; }

        .status-badge { display: inline-block; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: 500; }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.inactive { background: #f8d7da; color: #721c24; }

        /* View field grid - mirrors edit modal */
        .view-body { padding: 24px; }

        .view-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px 24px;
        }
        .view-grid .full-width { grid-column: span 2; }

        .view-field label {
            display: block;
            font-size: 12px; font-weight: 600;
            color: #888; text-transform: uppercase; letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .view-value {
            font-size: 14px; color: #333;
            min-height: 1.2em;
            word-wrap: break-word;
        }
        .view-value.empty { color: #bbb; }
        .view-value.multiline { white-space: pre-wrap; }
        .view-value a { color: #b45309; text-decoration: none; }
        .view-value a:hover { color: #d97706; text-decoration: underline; }

        .view-section {
            grid-column: span 2;
            font-size: 13px; font-weight: 600;
            color: #888; text-transform: uppercase; letter-spacing: 0.5px;
            padding: 16px 0 4px 0;
            margin-top: 8px;
            border-top: 1px solid #eee;
        }

        .empty-state { text-align: center; padding: 60px 20px; color: #999; }

        /* Edit modal styles */
        .modal-content {
            padding: 20px;
            max-width: 750px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 20px; color: #333; padding: 0; border-bottom: none; }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 20px;
        }
        .form-grid .full-width { grid-column: span 2; }

        .form-section {
            grid-column: span 2;
            font-size: 13px; font-weight: 600;
            color: #888; text-transform: uppercase; letter-spacing: 0.5px;
            padding: 12px 0 6px 0;
            margin-top: 5px;
            border-top: 1px solid #eee;
        }
        .form-section:first-child { border-top: none; margin-top: 0; padding-top: 0; }

        .modal .form-group { margin-bottom: 15px; }
        .modal .form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333; }
        .modal .form-group input,
        .modal .form-group select,
        .modal .form-group textarea {
            width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;
            font-size: 14px; box-sizing: border-box; font-family: inherit;
        }
        .modal .form-group textarea { height: 70px; resize: vertical; }
        .modal .form-group input:focus,
        .modal .form-group select:focus,
        .modal .form-group textarea:focus { outline: none; border-color: #f59e0b; box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.1); }
        .modal .checkbox-label { display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer; }
        .modal .checkbox-label input[type="checkbox"] { width: auto; }
        .modal-actions { margin-top: 20px; grid-column: span 2; }

        .btn-primary { background-color: #f59e0b; color: white; }
        .btn-primary:hover { background-color: #d97706; }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>

    <div class="contracts-layout">
        <!-- Left Sidebar -->
        <div class="contracts-sidebar">
            <div class="sidebar-section">
                <h3><?php echo htmlspecialchars(t('contracts.list.overview')); ?></h3>
                <div class="sidebar-total">
                    <span><?php echo htmlspecialchars(t('contracts.suppliers.all_suppliers')); ?></span>
                    <span class="stat-value" id="sideTotal">-</span>
                </div>
                <div id="overviewBreakdown"></div>
            </div>

            <div class="sidebar-section">
                <a href="../" class="sidebar-add-btn" style="background:#fff;color:#333;border:1px solid #ddd;">← <?php echo htmlspecialchars(t('contracts.suppliers.back_to_list')); ?></a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="contracts-main">
            <div class="section-card" id="viewCard">
                <div class="section-header">
                    <h2 id="supplierTitle"><?php echo htmlspecialchars(t('common.loading')); ?></h2>
                    <div class="header-actions">
                        <a href="../" class="back-link" title="<?php echo htmlspecialchars(t('contracts.suppliers.back_to_suppliers')); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            <?php echo htmlspecialchars(t('contracts.detail.back')); ?>
                        </a>
                        <button class="action-btn" id="editBtn" onclick="openModal(currentSupplier)" title="<?php echo htmlspecialchars(t('common.edit')); ?>" style="display:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                    </div>
                </div>
                <div class="view-body" id="viewBody">
                    <div class="empty-state"><?php echo htmlspecialchars(t('common.loading')); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal (same as suppliers list) -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle"><?php echo htmlspecialchars(t('contracts.suppliers.edit_supplier')); ?></div>
            <div class="modal-body">
            <form id="editForm">
                <input type="hidden" id="itemId">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="legalName"><?php echo htmlspecialchars(t('contracts.suppliers.legal_name')); ?></label>
                        <input type="text" id="legalName" required>
                    </div>
                    <div class="form-group full-width">
                        <label for="tradingName"><?php echo htmlspecialchars(t('contracts.suppliers.trading_name')); ?></label>
                        <input type="text" id="tradingName" placeholder="<?php echo htmlspecialchars(t('contracts.suppliers.trading_name_ph')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="regNumber"><?php echo htmlspecialchars(t('contracts.suppliers.reg_number')); ?></label>
                        <input type="text" id="regNumber">
                    </div>
                    <div class="form-group">
                        <label for="vatNumber"><?php echo htmlspecialchars(t('contracts.suppliers.vat_number')); ?></label>
                        <input type="text" id="vatNumber">
                    </div>
                    <div class="form-group">
                        <label for="supplierTypeId"><?php echo htmlspecialchars(t('contracts.suppliers.supplier_type')); ?></label>
                        <select id="supplierTypeId">
                            <option value="">-- <?php echo htmlspecialchars(t('contracts.edit.none')); ?> --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="supplierStatusId"><?php echo htmlspecialchars(t('contracts.detail.status')); ?></label>
                        <select id="supplierStatusId">
                            <option value="">-- <?php echo htmlspecialchars(t('contracts.edit.none')); ?> --</option>
                        </select>
                    </div>

                    <div class="form-section"><?php echo htmlspecialchars(t('contracts.suppliers.section_address')); ?></div>
                    <div class="form-group full-width">
                        <label for="addressLine1"><?php echo htmlspecialchars(t('contracts.suppliers.address_line_1')); ?></label>
                        <input type="text" id="addressLine1">
                    </div>
                    <div class="form-group full-width">
                        <label for="addressLine2"><?php echo htmlspecialchars(t('contracts.suppliers.address_line_2')); ?></label>
                        <input type="text" id="addressLine2">
                    </div>
                    <div class="form-group">
                        <label for="city"><?php echo htmlspecialchars(t('contracts.suppliers.city')); ?></label>
                        <input type="text" id="city">
                    </div>
                    <div class="form-group">
                        <label for="county"><?php echo htmlspecialchars(t('contracts.suppliers.county')); ?></label>
                        <input type="text" id="county">
                    </div>
                    <div class="form-group">
                        <label for="postcode"><?php echo htmlspecialchars(t('contracts.suppliers.postcode')); ?></label>
                        <input type="text" id="postcode">
                    </div>
                    <div class="form-group">
                        <label for="country"><?php echo htmlspecialchars(t('contracts.suppliers.country')); ?></label>
                        <input type="text" id="country">
                    </div>

                    <div class="form-section"><?php echo htmlspecialchars(t('contracts.suppliers.section_questionnaire')); ?></div>
                    <div class="form-group">
                        <label for="questionnaireDateIssued"><?php echo htmlspecialchars(t('contracts.suppliers.date_issued')); ?></label>
                        <input type="date" id="questionnaireDateIssued">
                    </div>
                    <div class="form-group">
                        <label for="questionnaireDateReceived"><?php echo htmlspecialchars(t('contracts.suppliers.date_received')); ?></label>
                        <input type="date" id="questionnaireDateReceived">
                    </div>

                    <div class="form-section"><?php echo htmlspecialchars(t('contracts.suppliers.section_other')); ?></div>
                    <div class="form-group full-width">
                        <label for="comments"><?php echo htmlspecialchars(t('contracts.suppliers.comments')); ?></label>
                        <textarea id="comments"></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label class="toggle-label">
                            <span class="toggle-switch">
                                <input type="checkbox" id="itemActive" checked>
                                <span class="toggle-slider"></span>
                            </span>
                            <?php echo htmlspecialchars(t('contracts.status.active')); ?>
                        </label>
                    </div>
                </div>
            </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="submit" form="editForm" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../../api/contracts/';
        const supplierId = parseInt(new URLSearchParams(window.location.search).get('id') || '0');
        let suppliers = [];
        let currentSupplier = null;
        let supplierTypes = [];
        let supplierStatuses = [];

        document.addEventListener('DOMContentLoaded', function() {
            if (!supplierId) {
                document.getElementById('supplierTitle').textContent = window.t('contracts.suppliers.not_specified');
                document.getElementById('viewBody').innerHTML = '<div class="empty-state">' + window.t('contracts.suppliers.no_id_in_url') + ' <a href="../">' + escapeHtml(window.t('contracts.suppliers.back_to_suppliers')) + '</a>.</div>';
                return;
            }
            loadSupplierTypes();
            loadSupplierStatuses();
            loadSuppliers();
        });

        async function loadSupplierTypes() {
            try {
                const response = await fetch(API_BASE + 'get_supplier_types.php');
                const data = await response.json();
                if (data.success) {
                    supplierTypes = data.supplier_types;
                    const select = document.getElementById('supplierTypeId');
                    select.innerHTML = '<option value="">-- ' + escapeHtml(window.t('contracts.edit.none')) + ' --</option>' +
                        supplierTypes.filter(t => t.is_active).map(t =>
                            `<option value="${t.id}">${escapeHtml(t.name)}</option>`
                        ).join('');
                }
            } catch (e) { console.error(e); }
        }

        async function loadSupplierStatuses() {
            try {
                const response = await fetch(API_BASE + 'get_supplier_statuses.php');
                const data = await response.json();
                if (data.success) {
                    supplierStatuses = data.supplier_statuses;
                    const select = document.getElementById('supplierStatusId');
                    select.innerHTML = '<option value="">-- ' + escapeHtml(window.t('contracts.edit.none')) + ' --</option>' +
                        supplierStatuses.filter(s => s.is_active).map(s =>
                            `<option value="${s.id}">${escapeHtml(s.name)}</option>`
                        ).join('');
                }
            } catch (e) { console.error(e); }
        }

        async function loadSuppliers() {
            try {
                const response = await fetch(API_BASE + 'get_suppliers.php');
                const data = await response.json();
                if (data.success) {
                    suppliers = data.suppliers;
                    renderOverview();
                    currentSupplier = suppliers.find(s => s.id == supplierId) || null;
                    renderView();
                } else {
                    document.getElementById('viewBody').innerHTML =
                        '<div class="empty-state" style="color:#d13438;">' + escapeHtml(window.t('contracts.settings.error_prefix')) + ' ' + escapeHtml(data.error) + '</div>';
                }
            } catch (e) {
                document.getElementById('viewBody').innerHTML =
                    '<div class="empty-state" style="color:#d13438;">' + escapeHtml(window.t('contracts.suppliers.view_load_failed')) + '</div>';
            }
        }

        function renderOverview() {
            document.getElementById('sideTotal').textContent = suppliers.length;

            const groups = {};
            suppliers.forEach(s => {
                const typeName = s.supplier_type_name || 'Uncategorised';
                const statusName = s.supplier_status_name || 'No status';
                if (!groups[typeName]) groups[typeName] = {};
                groups[typeName][statusName] = (groups[typeName][statusName] || 0) + 1;
            });

            const typeOrder = Object.keys(groups).sort((a, b) => {
                if (a === 'Uncategorised') return 1;
                if (b === 'Uncategorised') return -1;
                return a.localeCompare(b);
            });

            const container = document.getElementById('overviewBreakdown');
            if (typeOrder.length === 0) {
                container.innerHTML = '<div style="font-size:13px;color:#999;padding:8px 12px;">' + escapeHtml(window.t('contracts.suppliers.no_suppliers_yet')) + '</div>';
                return;
            }

            const labelFor = (key) => {
                if (key === 'Uncategorised') return window.t('contracts.suppliers.uncategorised');
                if (key === 'No status') return window.t('contracts.suppliers.no_status');
                return key;
            };

            container.innerHTML = typeOrder.map(typeName => {
                const statuses = groups[typeName];
                const statusOrder = Object.keys(statuses).sort((a, b) => {
                    if (a === 'No status') return 1;
                    if (b === 'No status') return -1;
                    return a.localeCompare(b);
                });
                const rows = statusOrder.map(statusName =>
                    `<div class="sidebar-stat">
                        <span>${escapeHtml(labelFor(statusName))}</span>
                        <span class="stat-value">${statuses[statusName]}</span>
                    </div>`
                ).join('');
                return `<h4>${escapeHtml(labelFor(typeName))}</h4>${rows}`;
            }).join('');
        }

        function renderView() {
            if (!currentSupplier) {
                document.getElementById('supplierTitle').textContent = window.t('contracts.suppliers.not_found');
                document.getElementById('viewBody').innerHTML =
                    '<div class="empty-state">' + window.t('contracts.suppliers.no_supplier_with_id', { id: supplierId }) + ' <a href="../">' + escapeHtml(window.t('contracts.suppliers.back_to_suppliers')) + '</a>.</div>';
                document.getElementById('editBtn').style.display = 'none';
                return;
            }

            const s = currentSupplier;
            document.getElementById('supplierTitle').textContent = s.legal_name;
            document.getElementById('editBtn').style.display = '';

            const statusBadge = s.is_active
                ? '<span class="status-badge active">' + escapeHtml(window.t('contracts.status.active')) + '</span>'
                : '<span class="status-badge inactive">' + escapeHtml(window.t('contracts.status.inactive')) + '</span>';

            document.getElementById('viewBody').innerHTML = `
                <div class="view-grid">
                    <div class="view-field full-width">
                        <label>${escapeHtml(window.t('contracts.suppliers.legal_name'))}</label>
                        ${val(s.legal_name)}
                    </div>
                    <div class="view-field full-width">
                        <label>${escapeHtml(window.t('contracts.suppliers.trading_name'))}</label>
                        ${val(s.trading_name)}
                    </div>
                    <div class="view-field">
                        <label>${escapeHtml(window.t('contracts.suppliers.reg_number'))}</label>
                        ${val(s.reg_number)}
                    </div>
                    <div class="view-field">
                        <label>${escapeHtml(window.t('contracts.suppliers.vat_number'))}</label>
                        ${val(s.vat_number)}
                    </div>
                    <div class="view-field">
                        <label>${escapeHtml(window.t('contracts.suppliers.supplier_type'))}</label>
                        ${val(s.supplier_type_name)}
                    </div>
                    <div class="view-field">
                        <label>${escapeHtml(window.t('contracts.detail.status'))}</label>
                        ${val(s.supplier_status_name)}
                    </div>

                    <div class="view-section">${escapeHtml(window.t('contracts.suppliers.section_address'))}</div>
                    <div class="view-field full-width">
                        <label>${escapeHtml(window.t('contracts.suppliers.address_line_1'))}</label>
                        ${val(s.address_line_1)}
                    </div>
                    <div class="view-field full-width">
                        <label>${escapeHtml(window.t('contracts.suppliers.address_line_2'))}</label>
                        ${val(s.address_line_2)}
                    </div>
                    <div class="view-field">
                        <label>${escapeHtml(window.t('contracts.suppliers.city'))}</label>
                        ${val(s.city)}
                    </div>
                    <div class="view-field">
                        <label>${escapeHtml(window.t('contracts.suppliers.county'))}</label>
                        ${val(s.county)}
                    </div>
                    <div class="view-field">
                        <label>${escapeHtml(window.t('contracts.suppliers.postcode'))}</label>
                        ${val(s.postcode)}
                    </div>
                    <div class="view-field">
                        <label>${escapeHtml(window.t('contracts.suppliers.country'))}</label>
                        ${val(s.country)}
                    </div>

                    <div class="view-section">${escapeHtml(window.t('contracts.suppliers.section_questionnaire'))}</div>
                    <div class="view-field">
                        <label>${escapeHtml(window.t('contracts.suppliers.date_issued'))}</label>
                        ${val(formatDate(s.questionnaire_date_issued))}
                    </div>
                    <div class="view-field">
                        <label>${escapeHtml(window.t('contracts.suppliers.date_received'))}</label>
                        ${val(formatDate(s.questionnaire_date_received))}
                    </div>

                    <div class="view-section">${escapeHtml(window.t('contracts.suppliers.section_other'))}</div>
                    <div class="view-field full-width">
                        <label>${escapeHtml(window.t('contracts.suppliers.comments'))}</label>
                        ${s.comments ? `<div class="view-value multiline">${escapeHtml(s.comments)}</div>` : `<div class="view-value empty">—</div>`}
                    </div>
                    <div class="view-field full-width">
                        <label>${escapeHtml(window.t('contracts.detail.active'))}</label>
                        <div class="view-value">${statusBadge}</div>
                    </div>
                </div>
            `;
        }

        function val(text) {
            if (text === null || text === undefined || text === '') {
                return '<div class="view-value empty">—</div>';
            }
            return `<div class="view-value">${escapeHtml(text)}</div>`;
        }

        function formatDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            if (isNaN(d.getTime())) return '';
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }

        // Edit modal
        function openModal(supplier) {
            if (!supplier) return;
            document.getElementById('modalTitle').textContent = window.t('contracts.suppliers.edit_supplier');
            document.getElementById('itemId').value = supplier.id;
            document.getElementById('legalName').value = supplier.legal_name || '';
            document.getElementById('tradingName').value = supplier.trading_name || '';
            document.getElementById('regNumber').value = supplier.reg_number || '';
            document.getElementById('vatNumber').value = supplier.vat_number || '';
            document.getElementById('supplierTypeId').value = supplier.supplier_type_id || '';
            document.getElementById('supplierStatusId').value = supplier.supplier_status_id || '';
            document.getElementById('addressLine1').value = supplier.address_line_1 || '';
            document.getElementById('addressLine2').value = supplier.address_line_2 || '';
            document.getElementById('city').value = supplier.city || '';
            document.getElementById('county').value = supplier.county || '';
            document.getElementById('postcode').value = supplier.postcode || '';
            document.getElementById('country').value = supplier.country || '';
            document.getElementById('questionnaireDateIssued').value = supplier.questionnaire_date_issued || '';
            document.getElementById('questionnaireDateReceived').value = supplier.questionnaire_date_received || '';
            document.getElementById('comments').value = supplier.comments || '';
            document.getElementById('itemActive').checked = !!supplier.is_active;
            document.getElementById('editModal').classList.add('active');
        }

        function closeModal() { document.getElementById('editModal').classList.remove('active'); }

        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const id = document.getElementById('itemId').value;
            const payload = {
                id: parseInt(id),
                legal_name: document.getElementById('legalName').value.trim(),
                trading_name: document.getElementById('tradingName').value.trim(),
                reg_number: document.getElementById('regNumber').value.trim(),
                vat_number: document.getElementById('vatNumber').value.trim(),
                supplier_type_id: document.getElementById('supplierTypeId').value || null,
                supplier_status_id: document.getElementById('supplierStatusId').value || null,
                address_line_1: document.getElementById('addressLine1').value.trim(),
                address_line_2: document.getElementById('addressLine2').value.trim(),
                city: document.getElementById('city').value.trim(),
                county: document.getElementById('county').value.trim(),
                postcode: document.getElementById('postcode').value.trim(),
                country: document.getElementById('country').value.trim(),
                questionnaire_date_issued: document.getElementById('questionnaireDateIssued').value || null,
                questionnaire_date_received: document.getElementById('questionnaireDateReceived').value || null,
                comments: document.getElementById('comments').value.trim(),
                is_active: document.getElementById('itemActive').checked ? 1 : 0
            };
            try {
                const response = await fetch(API_BASE + 'save_supplier.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    closeModal();
                    showToast(window.t('contracts.suppliers.toast_saved'), 'success');
                    loadSuppliers();
                } else {
                    showToast(window.t('contracts.settings.error_prefix') + ' ' + data.error, 'error');
                }
            } catch (e) { showToast(window.t('contracts.suppliers.toast_save_failed'), 'error'); }
        });

        let modalMouseDownTarget = null;
        document.getElementById('editModal').addEventListener('mousedown', function(e) { modalMouseDownTarget = e.target; });
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this && modalMouseDownTarget === this) closeModal();
        });

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }
    </script>
</body>
</html>
