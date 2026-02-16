<?php
/**
 * Contracts Module - Add/Edit Contract
 */
session_start();
require_once '../config.php';

$current_page = 'dashboard';
$path_prefix = '../';
$contract_id = $_GET['id'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo $contract_id ? 'Edit' : 'Add'; ?> Contract</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script src="../assets/js/toast.js"></script>
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
    <style>
        body { overflow: auto; height: auto; }

        .form-container {
            max-width: 1120px;
            margin: 0 auto;
            padding: 30px;
        }

        .form-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            overflow: hidden;
        }

        .form-card-header {
            padding: 20px 30px;
            border-bottom: 1px solid #eee;
        }

        .form-card-header h2 { margin: 0; font-size: 20px; color: #333; }

        .form-card-body { padding: 30px; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333; }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px;
            font-size: 14px; box-sizing: border-box; font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .form-group textarea { height: 80px; resize: vertical; }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: #f59e0b; box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.1);
        }

        .form-section {
            font-size: 13px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 0 6px 0;
            margin-top: 10px;
            border-top: 1px solid #eee;
        }

        .form-hint { font-size: 12px; color: #888; margin-top: 4px; }

        .form-actions {
            display: flex; align-items: center; gap: 12px;
            padding-top: 20px; border-top: 1px solid #eee; margin-top: 10px;
        }

        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background-color: #f59e0b; color: white; }
        .btn-primary:hover { background-color: #d97706; }
        .btn-secondary { background: #e0e0e0; color: #333; }
        .btn-secondary:hover { background: #d0d0d0; }


        .toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #ccc; border-radius: 24px; transition: background 0.2s;
        }
        .toggle-slider::before {
            content: ''; position: absolute;
            height: 18px; width: 18px; left: 3px; bottom: 3px;
            background: white; border-radius: 50%; transition: transform 0.2s;
        }
        .toggle-switch input:checked + .toggle-slider { background: #f59e0b; }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }
        .toggle-row { display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer; margin-bottom: 15px; }

        .terms-tabs { display: flex; gap: 0; border-bottom: 2px solid #e0e0e0; margin-bottom: 0; }
        .terms-tab {
            padding: 10px 20px; font-size: 13px; font-weight: 500; color: #666; cursor: pointer;
            background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.15s;
        }
        .terms-tab:hover { color: #333; background: #f5f5f5; }
        .terms-tab.active { color: #f59e0b; border-bottom-color: #f59e0b; font-weight: 600; }
        .terms-panel { display: none; padding-top: 16px; }
        .terms-panel.active { display: block; }
        .terms-empty { color: #999; font-size: 13px; padding: 12px 0; }
        .terms-empty a { color: #f59e0b; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="form-container">
        <div class="form-card">
            <div class="form-card-header">
                <h2 id="pageTitle"><?php echo $contract_id ? 'Edit Contract' : 'Add Contract'; ?></h2>
            </div>
            <div class="form-card-body">
                <form id="contractForm" autocomplete="off">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="contractNumber">Contract Number *</label>
                            <input type="text" id="contractNumber" required placeholder="e.g. CON-001">
                        </div>
                        <div class="form-group">
                            <label for="contractStatusId">Status</label>
                            <select id="contractStatusId">
                                <option value="">-- None --</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="title">Title *</label>
                        <input type="text" id="title" required placeholder="e.g. Annual Support Agreement">
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" placeholder="Contract description..."></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="supplierId">Supplier</label>
                            <select id="supplierId">
                                <option value="">-- Select Supplier --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="ownerId">Contract Owner</label>
                            <select id="ownerId">
                                <option value="">-- Select Owner --</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-section">Dates</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="contractStart">Start Date</label>
                            <input type="date" id="contractStart">
                        </div>
                        <div class="form-group">
                            <label for="contractEnd">End Date</label>
                            <input type="date" id="contractEnd">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="noticePeriod">Notice Period (days)</label>
                            <input type="number" id="noticePeriod" min="0" placeholder="e.g. 90">
                        </div>
                        <div class="form-group">
                            <label for="noticeDate">Notice Date</label>
                            <input type="date" id="noticeDate">
                        </div>
                    </div>

                    <div class="form-section">Financial</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="contractValue">Contract Value</label>
                            <input type="number" id="contractValue" step="0.01" min="0" placeholder="e.g. 10000.00">
                        </div>
                        <div class="form-group">
                            <label for="currency">Currency</label>
                            <select id="currency">
                                <option value="">-- None --</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="paymentScheduleId">Payment Schedule</label>
                            <select id="paymentScheduleId">
                                <option value="">-- None --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="costCentre">Cost Centre</label>
                            <input type="text" id="costCentre">
                        </div>
                    </div>

                    <div class="form-section">Documents</div>
                    <div class="form-group">
                        <label for="dmsLink">DMS Link (Contract)</label>
                        <input type="url" id="dmsLink" placeholder="https://...">
                    </div>

                    <div class="form-section">Terms &amp; Data Protection</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="termsStatus">Terms</label>
                            <select id="termsStatus">
                                <option value="">-- None --</option>
                                <option value="received">Received</option>
                                <option value="reviewed">Reviewed</option>
                                <option value="agreed">Agreed</option>
                            </select>
                        </div>
                        <div></div>
                    </div>
                    <div class="form-row">
                        <label class="toggle-row">
                            <span class="toggle-switch">
                                <input type="checkbox" id="personalDataTransferred">
                                <span class="toggle-slider"></span>
                            </span>
                            Personal data transferred
                        </label>
                        <label class="toggle-row">
                            <span class="toggle-switch">
                                <input type="checkbox" id="dpiaRequired">
                                <span class="toggle-slider"></span>
                            </span>
                            DPIA required
                        </label>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="dpiaCompletedDate">DPIA Completed Date</label>
                            <input type="date" id="dpiaCompletedDate">
                        </div>
                        <div class="form-group">
                            <label for="dpiaDmsLink">DMS Link (DPIA)</label>
                            <input type="url" id="dpiaDmsLink" placeholder="https://...">
                        </div>
                    </div>

                    <div class="form-section" style="margin-top: 20px;">Contract Terms Detail</div>
                    <div id="contractTermsSection" style="display: none;">
                        <div class="terms-tabs" id="termsTabs"></div>
                        <div id="termsPanels"></div>
                    </div>
                    <div id="contractTermsEmpty" class="terms-empty">
                        No contract terms tabs configured. <a href="settings/">Configure in settings</a>.
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="saveBtn">Save</button>
                        <a href="index.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/contracts/';
        const TICKETS_API = '../api/tickets/';
        let contractId = <?php echo json_encode($contract_id); ?>;

        const currencies = [
            { code: 'GBP', name: 'British Pound (GBP)' },
            { code: 'USD', name: 'US Dollar (USD)' },
            { code: 'EUR', name: 'Euro (EUR)' },
            { code: 'AUD', name: 'Australian Dollar (AUD)' },
            { code: 'CAD', name: 'Canadian Dollar (CAD)' },
            { code: 'CHF', name: 'Swiss Franc (CHF)' },
            { code: 'CNY', name: 'Chinese Yuan (CNY)' },
            { code: 'DKK', name: 'Danish Krone (DKK)' },
            { code: 'HKD', name: 'Hong Kong Dollar (HKD)' },
            { code: 'INR', name: 'Indian Rupee (INR)' },
            { code: 'JPY', name: 'Japanese Yen (JPY)' },
            { code: 'KRW', name: 'South Korean Won (KRW)' },
            { code: 'MXN', name: 'Mexican Peso (MXN)' },
            { code: 'NOK', name: 'Norwegian Krone (NOK)' },
            { code: 'NZD', name: 'New Zealand Dollar (NZD)' },
            { code: 'PLN', name: 'Polish Zloty (PLN)' },
            { code: 'SEK', name: 'Swedish Krona (SEK)' },
            { code: 'SGD', name: 'Singapore Dollar (SGD)' },
            { code: 'ZAR', name: 'South African Rand (ZAR)' }
        ];

        let termTabs = [];
        let termEditorIds = [];

        document.addEventListener('DOMContentLoaded', async function() {
            populateCurrencies();
            await Promise.all([
                loadSuppliers(),
                loadAnalysts(),
                loadContractStatuses(),
                loadPaymentSchedules(),
                loadContractTermTabs()
            ]);
            if (contractId) await loadContract();

            buildTermEditors();
            initTermEditors(() => {
                if (contractId) loadContractTermValues();
            });
        });

        function populateCurrencies() {
            const select = document.getElementById('currency');
            select.innerHTML = '<option value="">-- None --</option>' +
                currencies.map(c => `<option value="${c.code}">${c.name}</option>`).join('');
        }

        async function loadSuppliers() {
            try {
                const response = await fetch(API_BASE + 'get_suppliers.php');
                const data = await response.json();
                if (data.success) {
                    const select = document.getElementById('supplierId');
                    select.innerHTML = '<option value="">-- Select Supplier --</option>' +
                        data.suppliers.filter(s => s.is_active).map(s =>
                            `<option value="${s.id}">${escapeHtml(s.legal_name)}</option>`
                        ).join('');
                }
            } catch (error) { console.error('Error loading suppliers:', error); }
        }

        async function loadAnalysts() {
            try {
                const response = await fetch(TICKETS_API + 'get_analysts.php');
                const data = await response.json();
                if (data.success) {
                    const select = document.getElementById('ownerId');
                    select.innerHTML = '<option value="">-- Select Owner --</option>' +
                        data.analysts.filter(a => a.is_active).map(a =>
                            `<option value="${a.id}">${escapeHtml(a.full_name)}</option>`
                        ).join('');
                }
            } catch (error) { console.error('Error loading analysts:', error); }
        }

        async function loadContractStatuses() {
            try {
                const response = await fetch(API_BASE + 'get_contract_statuses.php');
                const data = await response.json();
                if (data.success) {
                    const select = document.getElementById('contractStatusId');
                    select.innerHTML = '<option value="">-- None --</option>' +
                        data.contract_statuses.filter(s => s.is_active).map(s =>
                            `<option value="${s.id}">${escapeHtml(s.name)}</option>`
                        ).join('');
                }
            } catch (error) { console.error('Error loading contract statuses:', error); }
        }

        async function loadPaymentSchedules() {
            try {
                const response = await fetch(API_BASE + 'get_payment_schedules.php');
                const data = await response.json();
                if (data.success) {
                    const select = document.getElementById('paymentScheduleId');
                    select.innerHTML = '<option value="">-- None --</option>' +
                        data.payment_schedules.filter(p => p.is_active).map(p =>
                            `<option value="${p.id}">${escapeHtml(p.name)}</option>`
                        ).join('');
                }
            } catch (error) { console.error('Error loading payment schedules:', error); }
        }

        async function loadContract() {
            try {
                const response = await fetch(API_BASE + 'get_contract.php?id=' + contractId);
                const data = await response.json();
                if (data.success) {
                    const c = data.contract;
                    document.getElementById('contractNumber').value = c.contract_number;
                    document.getElementById('title').value = c.title;
                    document.getElementById('description').value = c.description || '';
                    document.getElementById('supplierId').value = c.supplier_id || '';
                    document.getElementById('ownerId').value = c.contract_owner_id || '';
                    document.getElementById('contractStatusId').value = c.contract_status_id || '';
                    document.getElementById('contractStart').value = c.contract_start || '';
                    document.getElementById('contractEnd').value = c.contract_end || '';
                    document.getElementById('noticePeriod').value = c.notice_period_days || '';
                    document.getElementById('noticeDate').value = c.notice_date || '';
                    document.getElementById('contractValue').value = c.contract_value || '';
                    document.getElementById('currency').value = c.currency || '';
                    document.getElementById('paymentScheduleId').value = c.payment_schedule_id || '';
                    document.getElementById('costCentre').value = c.cost_centre || '';
                    document.getElementById('dmsLink').value = c.dms_link || '';
                    document.getElementById('termsStatus').value = c.terms_status || '';
                    document.getElementById('personalDataTransferred').checked = !!c.personal_data_transferred;
                    document.getElementById('dpiaRequired').checked = !!c.dpia_required;
                    document.getElementById('dpiaCompletedDate').value = c.dpia_completed_date || '';
                    document.getElementById('dpiaDmsLink').value = c.dpia_dms_link || '';
                }
            } catch (error) { console.error('Error loading contract:', error); }
        }

        document.getElementById('contractForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            const payload = {
                contract_number: document.getElementById('contractNumber').value.trim(),
                title: document.getElementById('title').value.trim(),
                description: document.getElementById('description').value.trim(),
                supplier_id: document.getElementById('supplierId').value || null,
                contract_owner_id: document.getElementById('ownerId').value || null,
                contract_status_id: document.getElementById('contractStatusId').value || null,
                contract_start: document.getElementById('contractStart').value || null,
                contract_end: document.getElementById('contractEnd').value || null,
                notice_period_days: document.getElementById('noticePeriod').value || null,
                notice_date: document.getElementById('noticeDate').value || null,
                contract_value: document.getElementById('contractValue').value || null,
                currency: document.getElementById('currency').value || null,
                payment_schedule_id: document.getElementById('paymentScheduleId').value || null,
                cost_centre: document.getElementById('costCentre').value.trim(),
                dms_link: document.getElementById('dmsLink').value.trim(),
                terms_status: document.getElementById('termsStatus').value || null,
                personal_data_transferred: document.getElementById('personalDataTransferred').checked ? 1 : 0,
                dpia_required: document.getElementById('dpiaRequired').checked ? 1 : 0,
                dpia_completed_date: document.getElementById('dpiaCompletedDate').value || null,
                dpia_dms_link: document.getElementById('dpiaDmsLink').value.trim(),
                is_active: 1
            };
            if (contractId) payload.id = parseInt(contractId);

            try {
                const response = await fetch(API_BASE + 'save_contract.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    const savedId = contractId || data.id;

                    // Save terms if any editors exist
                    if (termEditorIds.length > 0) {
                        const terms = termTabs.map(tab => ({
                            term_tab_id: tab.id,
                            content: tinymce.get('termEditor_' + tab.id)?.getContent() || ''
                        }));
                        await fetch(API_BASE + 'save_contract_terms.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ contract_id: parseInt(savedId), terms })
                        });
                    }

                    if (contractId) {
                        showToast('Contract saved', 'success');
                    } else {
                        window.location.href = 'view.php?id=' + savedId;
                    }
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to save contract', 'error');
            }

            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
        });

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Contract Terms
        async function loadContractTermTabs() {
            try {
                const response = await fetch(API_BASE + 'get_contract_term_tabs.php');
                const data = await response.json();
                if (data.success) {
                    termTabs = data.contract_term_tabs.filter(t => t.is_active);
                }
            } catch (error) { console.error('Error loading contract term tabs:', error); }
        }

        function buildTermEditors() {
            if (termTabs.length === 0) {
                document.getElementById('contractTermsSection').style.display = 'none';
                document.getElementById('contractTermsEmpty').style.display = '';
                return;
            }

            document.getElementById('contractTermsSection').style.display = '';
            document.getElementById('contractTermsEmpty').style.display = 'none';

            const tabsContainer = document.getElementById('termsTabs');
            const panelsContainer = document.getElementById('termsPanels');
            termEditorIds = [];

            tabsContainer.innerHTML = termTabs.map((tab, i) =>
                `<button type="button" class="terms-tab ${i === 0 ? 'active' : ''}" data-tab-id="${tab.id}" onclick="switchTermTab(${tab.id})">${escapeHtml(tab.name)}</button>`
            ).join('');

            panelsContainer.innerHTML = termTabs.map((tab, i) => {
                const editorId = 'termEditor_' + tab.id;
                termEditorIds.push(editorId);
                return `<div class="terms-panel ${i === 0 ? 'active' : ''}" id="termPanel_${tab.id}"><textarea id="${editorId}"></textarea></div>`;
            }).join('');
        }

        function switchTermTab(tabId) {
            document.querySelectorAll('.terms-tab').forEach(btn => btn.classList.remove('active'));
            document.querySelector('.terms-tab[data-tab-id="' + tabId + '"]').classList.add('active');
            document.querySelectorAll('.terms-panel').forEach(p => p.classList.remove('active'));
            document.getElementById('termPanel_' + tabId).classList.add('active');
        }

        function initTermEditors(callback) {
            if (termEditorIds.length === 0) { if (callback) callback(); return; }

            let initialized = 0;
            const total = termEditorIds.length;

            termEditorIds.forEach(id => {
                tinymce.init({
                    selector: '#' + id,
                    license_key: 'gpl',
                    height: 300,
                    menubar: false,
                    plugins: ['advlist', 'autolink', 'lists', 'link', 'table', 'wordcount'],
                    toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link table | removeformat',
                    content_style: 'body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; }',
                    setup: function(editor) {
                        editor.on('init', function() {
                            initialized++;
                            if (initialized === total && callback) callback();
                        });
                    }
                });
            });
        }

        async function loadContractTermValues() {
            if (!contractId) return;
            try {
                const response = await fetch(API_BASE + 'get_contract_terms.php?contract_id=' + contractId);
                const data = await response.json();
                if (data.success) {
                    data.contract_terms.forEach(tv => {
                        const editor = tinymce.get('termEditor_' + tv.term_tab_id);
                        if (editor) editor.setContent(tv.content || '');
                    });
                }
            } catch (error) { console.error('Error loading contract term values:', error); }
        }

    </script>
</body>
</html>
