<?php
/**
 * Contracts Module - View Contract
 */
session_start();
require_once '../config.php';

$current_page = 'dashboard';
$path_prefix = '../';
$contract_id = $_GET['id'] ?? null;

if (!$contract_id) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - View Contract</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script src="../assets/js/toast.js"></script>
    <style>
        body { overflow: auto; height: auto; }

        .contract-container {
            max-width: 1120px;
            margin: 0 auto;
            padding: 30px;
        }

        .contract-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            overflow: hidden;
        }

        .contract-card-header {
            padding: 24px 30px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .contract-card-header h2 { margin: 0; font-size: 20px; color: #333; }

        .contract-card-header .actions { display: flex; gap: 8px; }

        .contract-card-header .btn {
            padding: 8px 16px; border-radius: 4px; font-size: 13px; font-weight: 500;
            text-decoration: none; cursor: pointer; transition: all 0.2s;
        }

        .btn-edit-contract { background: #f59e0b; color: white; border: none; }
        .btn-edit-contract:hover { background: #d97706; }
        .btn-back { background: #e0e0e0; color: #333; border: none; }
        .btn-back:hover { background: #d0d0d0; }

        .contract-details {
            padding: 30px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .detail-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .detail-group .value {
            font-size: 15px;
            color: #333;
        }

        .detail-group.full-width { grid-column: span 2; }

        .section-divider {
            grid-column: span 2;
            border-top: 1px solid #eee;
            padding-top: 16px;
            margin-top: 4px;
        }

        .section-divider h3 {
            margin: 0;
            font-size: 13px;
            font-weight: 600;
            color: #f59e0b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge {
            display: inline-block; padding: 4px 12px; border-radius: 12px;
            font-size: 13px; font-weight: 500;
        }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.expired { background: #f8d7da; color: #721c24; }
        .status-badge.expiring { background: #fff3cd; color: #856404; }

        .bool-yes { color: #155724; font-weight: 500; }
        .bool-no { color: #999; }

        .dms-link a { color: #f59e0b; text-decoration: none; word-break: break-all; }
        .dms-link a:hover { text-decoration: underline; }

        .loading { text-align: center; padding: 60px; color: #999; }

        .terms-view-tabs { display: flex; gap: 0; border-bottom: 2px solid #e0e0e0; margin-top: 8px; }
        .terms-view-tab {
            padding: 10px 20px; font-size: 13px; font-weight: 500; color: #666; cursor: pointer;
            background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.15s;
        }
        .terms-view-tab:hover { color: #333; background: #f5f5f5; }
        .terms-view-tab.active { color: #f59e0b; border-bottom-color: #f59e0b; font-weight: 600; }
        .terms-view-panel { display: none; padding: 20px 0; }
        .terms-view-panel.active { display: block; }
        .terms-view-panel .rich-content { font-size: 14px; line-height: 1.6; color: #333; }
        .terms-view-panel .rich-content table { border-collapse: collapse; width: 100%; }
        .terms-view-panel .rich-content td, .terms-view-panel .rich-content th { border: 1px solid #ddd; padding: 8px; }

        .btn-create-task { background: #6366f1; color: white; border: none; }
        .btn-create-task:hover { background: #4f46e5; }
        .btn-create-event { background: #0ea5e9; color: white; border: none; }
        .btn-create-event:hover { background: #0284c7; }

        .related-list { padding: 0 30px 20px 30px; }
        .related-section { margin-bottom: 24px; }
        .related-section h3 {
            margin: 0 0 10px 0; font-size: 13px; font-weight: 600;
            color: #f59e0b; text-transform: uppercase; letter-spacing: 0.5px;
            padding-top: 16px; border-top: 1px solid #eee;
        }
        .related-empty { color: #999; font-size: 13px; padding: 8px 0; }
        .related-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 0; border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }
        .related-item:last-child { border-bottom: none; }
        .related-item a { color: #f59e0b; text-decoration: none; font-weight: 500; }
        .related-item a:hover { text-decoration: underline; }
        .related-item .meta { color: #666; }
        .related-item .meta-sep { color: #ccc; margin: 0 4px; }
        .related-pill {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; font-weight: 500; background: #eee; color: #333;
        }
        .related-pill.todo { background: #e5e7eb; color: #374151; }
        .related-pill.in-progress { background: #fde68a; color: #92400e; }
        .related-pill.done { background: #d1fae5; color: #065f46; }
        .related-pill.cancelled { background: #f3f4f6; color: #6b7280; }
        .related-pill.high, .related-pill.urgent { background: #fee2e2; color: #991b1b; }
        .related-cat-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; vertical-align: middle; margin-right: 4px; }

        /* Modal */
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            display: none; align-items: center; justify-content: center; z-index: 1000;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: #fff; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            width: 480px; max-width: calc(100vw - 40px); max-height: calc(100vh - 40px); overflow: auto;
        }
        .modal-header {
            padding: 16px 20px; border-bottom: 1px solid #eee;
            display: flex; justify-content: space-between; align-items: center;
        }
        .modal-header h3 { margin: 0; font-size: 16px; color: #333; }
        .modal-close {
            background: none; border: none; font-size: 22px; line-height: 1;
            color: #999; cursor: pointer; padding: 0;
        }
        .modal-close:hover { color: #333; }
        .modal-body { padding: 20px; }
        .modal-body .form-group { margin-bottom: 14px; }
        .modal-body label {
            display: block; margin-bottom: 6px; font-weight: 500;
            font-size: 13px; color: #333;
        }
        .modal-body input, .modal-body select, .modal-body textarea {
            width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 4px;
            font-size: 13px; box-sizing: border-box; font-family: inherit;
        }
        .modal-body textarea { height: 70px; resize: vertical; }
        .modal-body input:focus, .modal-body select:focus, .modal-body textarea:focus {
            outline: none; border-color: #f59e0b; box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.1);
        }
        .modal-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .modal-footer {
            padding: 14px 20px; border-top: 1px solid #eee;
            display: flex; justify-content: flex-end; gap: 8px;
        }
        .modal-footer .btn {
            padding: 8px 16px; border-radius: 4px; font-size: 13px; font-weight: 500;
            cursor: pointer; border: none; transition: all 0.2s;
        }
        .modal-footer .btn-primary { background: #f59e0b; color: white; }
        .modal-footer .btn-primary:hover { background: #d97706; }
        .modal-footer .btn-primary:disabled { background: #fcd34d; cursor: not-allowed; }
        .modal-footer .btn-secondary { background: #e0e0e0; color: #333; }
        .modal-footer .btn-secondary:hover { background: #d0d0d0; }
        .checkbox-row { display: flex; align-items: center; gap: 8px; }
        .checkbox-row input { width: auto; }
        .checkbox-row label { margin: 0; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="contract-container">
        <div class="contract-card" id="contractCard">
            <div class="loading">Loading contract...</div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/contracts/';
        const TASKS_API = '../api/tasks/';
        const CALENDAR_API = '../api/calendar/';
        const contractId = <?php echo json_encode($contract_id); ?>;
        let currentContract = null;
        let analystOptions = [];
        let teamOptions = [];
        let categoryOptions = [];

        document.addEventListener('DOMContentLoaded', loadContract);

        async function loadContract() {
            try {
                const response = await fetch(API_BASE + 'get_contract.php?id=' + contractId);
                const data = await response.json();
                if (data.success) {
                    currentContract = data.contract;
                    renderContract(data.contract);
                    loadAndRenderContractTerms();
                    loadRelatedItems();
                } else {
                    document.getElementById('contractCard').innerHTML =
                        '<div class="loading" style="color:#d13438;">Error: ' + escapeHtml(data.error) + '</div>';
                }
            } catch (error) {
                document.getElementById('contractCard').innerHTML =
                    '<div class="loading" style="color:#d13438;">Failed to load contract</div>';
            }
        }

        function renderContract(c) {
            const status = getContractStatus(c);
            const contractValue = c.contract_value ? (c.currency || '') + ' ' + parseFloat(c.contract_value).toLocaleString('en-GB', {minimumFractionDigits: 2}) : '-';

            document.getElementById('contractCard').innerHTML = `
                <div class="contract-card-header">
                    <h2>${escapeHtml(c.contract_number)} — ${escapeHtml(c.title)}</h2>
                    <div class="actions">
                        <a href="index.php" class="btn btn-back">Back</a>
                        <button type="button" class="btn btn-create-task" onclick="openTaskModal()">Task</button>
                        <button type="button" class="btn btn-create-event" onclick="openEventModal()">Calendar</button>
                        <a href="edit.php?id=${c.id}" class="btn btn-edit-contract">Edit</a>
                    </div>
                </div>
                <div class="contract-details">
                    <div class="detail-group">
                        <label>Contract Number</label>
                        <div class="value">${escapeHtml(c.contract_number)}</div>
                    </div>
                    <div class="detail-group">
                        <label>Status</label>
                        <div class="value"><span class="status-badge ${status.class}">${status.label}</span></div>
                    </div>
                    <div class="detail-group full-width">
                        <label>Title</label>
                        <div class="value">${escapeHtml(c.title)}</div>
                    </div>
                    ${c.description ? `<div class="detail-group full-width">
                        <label>Description</label>
                        <div class="value">${escapeHtml(c.description)}</div>
                    </div>` : ''}
                    <div class="detail-group">
                        <label>Supplier</label>
                        <div class="value">${escapeHtml(c.supplier_name || '-')}${c.supplier_trading_name ? ' <span style="color:#888;">(t/a ' + escapeHtml(c.supplier_trading_name) + ')</span>' : ''}</div>
                    </div>
                    <div class="detail-group">
                        <label>Contract Owner</label>
                        <div class="value">${escapeHtml(c.owner_name || '-')}</div>
                    </div>

                    <div class="section-divider"><h3>Dates</h3></div>
                    <div class="detail-group">
                        <label>Start Date</label>
                        <div class="value">${formatDate(c.contract_start)}</div>
                    </div>
                    <div class="detail-group">
                        <label>End Date</label>
                        <div class="value">${formatDate(c.contract_end)}</div>
                    </div>
                    <div class="detail-group">
                        <label>Notice Period</label>
                        <div class="value">${c.notice_period_days ? c.notice_period_days + ' days' : '-'}</div>
                    </div>
                    <div class="detail-group">
                        <label>Notice Date</label>
                        <div class="value">${formatDate(c.notice_date)}</div>
                    </div>

                    <div class="section-divider"><h3>Financial</h3></div>
                    <div class="detail-group">
                        <label>Contract Value</label>
                        <div class="value">${contractValue}</div>
                    </div>
                    <div class="detail-group">
                        <label>Payment Schedule</label>
                        <div class="value">${escapeHtml(c.payment_schedule_name || '-')}</div>
                    </div>
                    <div class="detail-group">
                        <label>Cost Centre</label>
                        <div class="value">${escapeHtml(c.cost_centre || '-')}</div>
                    </div>
                    <div class="detail-group">
                        <label>DMS Link</label>
                        <div class="value dms-link">${c.dms_link ? '<a href="' + escapeHtml(c.dms_link) + '" target="_blank">' + escapeHtml(c.dms_link) + '</a>' : '-'}</div>
                    </div>

                    <div class="section-divider"><h3>Terms & Data Protection</h3></div>
                    <div class="detail-group">
                        <label>Terms</label>
                        <div class="value">${escapeHtml(formatTermsStatus(c.terms_status))}</div>
                    </div>
                    <div class="detail-group">
                        <label>Personal Data Transferred</label>
                        <div class="value">${formatBool(c.personal_data_transferred)}</div>
                    </div>
                    <div class="detail-group">
                        <label>DPIA Required</label>
                        <div class="value">${formatBool(c.dpia_required)}</div>
                    </div>
                    <div class="detail-group">
                        <label>DPIA Completed Date</label>
                        <div class="value">${formatDate(c.dpia_completed_date)}</div>
                    </div>
                    ${c.dpia_dms_link ? `<div class="detail-group full-width">
                        <label>DPIA DMS Link</label>
                        <div class="value dms-link"><a href="${escapeHtml(c.dpia_dms_link)}" target="_blank">${escapeHtml(c.dpia_dms_link)}</a></div>
                    </div>` : ''}

                    <div class="section-divider"><h3>System</h3></div>
                    <div class="detail-group">
                        <label>Created</label>
                        <div class="value">${formatDate(c.created_datetime)}</div>
                    </div>
                    <div class="detail-group">
                        <label>Active</label>
                        <div class="value">${c.is_active ? '<span class="bool-yes">Yes</span>' : '<span class="bool-no">No</span>'}</div>
                    </div>
                </div>
                <div class="related-list">
                    <div class="related-section" id="relatedTasksSection">
                        <h3>Related Tasks</h3>
                        <div id="relatedTasksList" class="related-empty">Loading...</div>
                    </div>
                    <div class="related-section" id="relatedEventsSection">
                        <h3>Related Calendar Events</h3>
                        <div id="relatedEventsList" class="related-empty">Loading...</div>
                    </div>
                </div>
            `;
        }

        function getContractStatus(c) {
            if (!c.is_active) return { class: 'expired', label: 'Inactive' };
            if (c.contract_end) {
                const end = new Date(c.contract_end);
                const today = new Date(); today.setHours(0,0,0,0);
                const daysLeft = Math.ceil((end - today) / (1000*60*60*24));
                if (daysLeft < 0) return { class: 'expired', label: 'Expired' };
                if (c.contract_status_name) return { class: 'active', label: c.contract_status_name };
                if (daysLeft <= 90) return { class: 'expiring', label: 'Expiring' };
                return { class: 'active', label: 'Active' };
            }
            if (c.contract_status_name) return { class: 'active', label: c.contract_status_name };
            return { class: 'active', label: 'Active' };
        }

        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }

        function formatBool(val) {
            if (val === null || val === undefined || val === '') return '<span class="bool-no">-</span>';
            return val == 1 ? '<span class="bool-yes">Yes</span>' : '<span class="bool-no">No</span>';
        }

        function formatTermsStatus(val) {
            if (!val) return '-';
            const labels = { received: 'Received', reviewed: 'Reviewed', agreed: 'Agreed' };
            return labels[val] || val;
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Contract Terms Detail (read-only)
        async function loadAndRenderContractTerms() {
            try {
                const [tabsResp, valuesResp] = await Promise.all([
                    fetch(API_BASE + 'get_contract_term_tabs.php'),
                    fetch(API_BASE + 'get_contract_terms.php?contract_id=' + contractId)
                ]);
                const tabsData = await tabsResp.json();
                const valuesData = await valuesResp.json();

                if (!tabsData.success || !valuesData.success) return;

                const activeTabs = tabsData.contract_term_tabs.filter(t => t.is_active);
                if (activeTabs.length === 0) return;

                const valueMap = {};
                (valuesData.contract_terms || []).forEach(tv => {
                    valueMap[tv.term_tab_id] = tv.content || '';
                });

                const hasAnyContent = activeTabs.some(tab => valueMap[tab.id] && valueMap[tab.id].trim());
                if (!hasAnyContent) return;

                const tabButtons = activeTabs.map((tab, i) =>
                    `<button class="terms-view-tab ${i === 0 ? 'active' : ''}" data-tab-id="${tab.id}" onclick="switchViewTermTab(${tab.id})">${escapeHtml(tab.name)}</button>`
                ).join('');

                const tabPanels = activeTabs.map((tab, i) =>
                    `<div class="terms-view-panel ${i === 0 ? 'active' : ''}" id="viewTermPanel_${tab.id}"><div class="rich-content">${valueMap[tab.id] || '<span style="color:#999;">No content</span>'}</div></div>`
                ).join('');

                const termsHtml = `
                    <div class="section-divider"><h3>Contract Terms Detail</h3></div>
                    <div class="detail-group full-width">
                        <div class="terms-view-tabs">${tabButtons}</div>
                        ${tabPanels}
                    </div>
                `;

                // Insert before the System section divider
                const dividers = document.querySelectorAll('.section-divider');
                const systemDivider = dividers[dividers.length - 1];
                systemDivider.insertAdjacentHTML('beforebegin', termsHtml);

            } catch (error) {
                console.error('Error loading contract terms:', error);
            }
        }

        function switchViewTermTab(tabId) {
            document.querySelectorAll('.terms-view-tab').forEach(btn => btn.classList.remove('active'));
            document.querySelector('.terms-view-tab[data-tab-id="' + tabId + '"]').classList.add('active');
            document.querySelectorAll('.terms-view-panel').forEach(p => p.classList.remove('active'));
            document.getElementById('viewTermPanel_' + tabId).classList.add('active');
        }

        // Related items
        async function loadRelatedItems() {
            loadRelatedTasks();
            loadRelatedEvents();
        }

        async function loadRelatedTasks() {
            const list = document.getElementById('relatedTasksList');
            try {
                const resp = await fetch(TASKS_API + 'list.php?filter=contract&contract_id=' + contractId);
                const data = await resp.json();
                if (!data.success) {
                    list.innerHTML = '<div class="related-empty">Failed to load tasks</div>';
                    return;
                }
                if (!data.tasks.length) {
                    list.className = '';
                    list.innerHTML = '<div class="related-empty">No related tasks</div>';
                    return;
                }
                list.className = '';
                list.innerHTML = data.tasks.map(t => {
                    const statusClass = (t.status || '').toLowerCase().replace(/\s+/g, '-');
                    return `<div class="related-item">
                        <a href="../tasks/index.php?task=${t.id}">${escapeHtml(t.title)}</a>
                        <span class="related-pill ${statusClass}">${escapeHtml(t.status || '')}</span>
                        <span class="meta">
                            ${t.analyst_name ? escapeHtml(t.analyst_name) : (t.team_name ? escapeHtml(t.team_name) : 'Unassigned')}
                            ${t.due_date ? '<span class="meta-sep">•</span>Due ' + formatDate(t.due_date) : ''}
                        </span>
                    </div>`;
                }).join('');
            } catch (e) {
                list.innerHTML = '<div class="related-empty">Failed to load tasks</div>';
            }
        }

        async function loadRelatedEvents() {
            const list = document.getElementById('relatedEventsList');
            try {
                const resp = await fetch(CALENDAR_API + 'get_events.php?contract_id=' + contractId);
                const data = await resp.json();
                if (!data.success) {
                    list.innerHTML = '<div class="related-empty">Failed to load events</div>';
                    return;
                }
                if (!data.events.length) {
                    list.className = '';
                    list.innerHTML = '<div class="related-empty">No related events</div>';
                    return;
                }
                list.className = '';
                list.innerHTML = data.events.map(e => {
                    const dot = e.category_color ? `<span class="related-cat-dot" style="background:${escapeHtml(e.category_color)}"></span>` : '';
                    return `<div class="related-item">
                        <a href="../calendar/index.php?event=${e.id}">${dot}${escapeHtml(e.title)}</a>
                        <span class="meta">
                            ${formatDateTime(e.start_datetime, e.all_day)}
                            ${e.category_name ? '<span class="meta-sep">•</span>' + escapeHtml(e.category_name) : ''}
                        </span>
                    </div>`;
                }).join('');
            } catch (err) {
                list.innerHTML = '<div class="related-empty">Failed to load events</div>';
            }
        }

        function formatDateTime(dtStr, allDay) {
            if (!dtStr) return '-';
            const d = new Date(dtStr.replace(' ', 'T'));
            if (allDay) return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
            return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

        // Modals
        async function openTaskModal() {
            // Lazy-load analyst & team lists
            if (!analystOptions.length) {
                try {
                    const [aResp, tResp] = await Promise.all([
                        fetch(TASKS_API + 'list.php?analysts=1'),
                        fetch(TASKS_API + 'list.php?teams=1')
                    ]);
                    const aData = await aResp.json();
                    const tData = await tResp.json();
                    if (aData.success) analystOptions = aData.analysts;
                    if (tData.success) teamOptions = tData.teams;
                } catch (e) {
                    showToast('Failed to load assignee list', 'error');
                    return;
                }
            }

            const c = currentContract;
            const titleDefault = `Contract: ${c.contract_number} — ${c.title}`;
            const dueDefault = c.notice_date || c.contract_end || '';
            // Default assignee = contract owner if present
            const assigneeDefault = c.contract_owner_id || '';

            document.getElementById('taskTitle').value = titleDefault;
            document.getElementById('taskDescription').value = `Linked to contract ${c.contract_number} — ${c.title}` + (c.supplier_name ? ` (Supplier: ${c.supplier_name})` : '');
            document.getElementById('taskDueDate').value = dueDefault ? dueDefault.substring(0, 10) : '';
            document.getElementById('taskPriority').value = 'Medium';
            document.getElementById('taskStatus').value = 'To Do';

            const analystSel = document.getElementById('taskAnalyst');
            analystSel.innerHTML = '<option value="">Unassigned</option>' +
                analystOptions.map(a => `<option value="${a.id}" ${a.id == assigneeDefault ? 'selected' : ''}>${escapeHtml(a.name)}</option>`).join('');

            const teamSel = document.getElementById('taskTeam');
            teamSel.innerHTML = '<option value="">No team</option>' +
                teamOptions.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');

            document.getElementById('taskModal').classList.add('active');
        }

        function closeTaskModal() {
            document.getElementById('taskModal').classList.remove('active');
        }

        async function saveTask() {
            const btn = document.getElementById('taskSaveBtn');
            const title = document.getElementById('taskTitle').value.trim();
            if (!title) {
                showToast('Title is required', 'error');
                return;
            }
            btn.disabled = true;
            try {
                const resp = await fetch(TASKS_API + 'save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        title: title,
                        description: document.getElementById('taskDescription').value,
                        status: document.getElementById('taskStatus').value,
                        priority: document.getElementById('taskPriority').value,
                        due_date: document.getElementById('taskDueDate').value || null,
                        assigned_analyst_id: document.getElementById('taskAnalyst').value || null,
                        assigned_team_id: document.getElementById('taskTeam').value || null,
                        contract_id: contractId
                    })
                });
                const data = await resp.json();
                if (data.success) {
                    showToast('Task created', 'success');
                    closeTaskModal();
                    loadRelatedTasks();
                } else {
                    showToast('Error: ' + (data.error || 'Failed to save'), 'error');
                }
            } catch (e) {
                showToast('Failed to save task', 'error');
            } finally {
                btn.disabled = false;
            }
        }

        async function openEventModal() {
            if (!categoryOptions.length) {
                try {
                    const resp = await fetch(CALENDAR_API + 'get_categories.php?active_only=1');
                    const data = await resp.json();
                    if (data.success) categoryOptions = data.categories;
                } catch (e) {
                    showToast('Failed to load categories', 'error');
                    return;
                }
            }

            const c = currentContract;
            const dateDefault = c.contract_end || c.notice_date || '';
            const titleDefault = `${c.contract_number} — ${c.title}`;

            document.getElementById('eventTitle').value = titleDefault;
            document.getElementById('eventDescription').value = `Linked to contract ${c.contract_number} — ${c.title}` + (c.supplier_name ? ` (Supplier: ${c.supplier_name})` : '');
            document.getElementById('eventStart').value = dateDefault ? dateDefault.substring(0, 10) : '';
            document.getElementById('eventAllDay').checked = true;
            document.getElementById('eventLocation').value = '';

            const catSel = document.getElementById('eventCategory');
            catSel.innerHTML = '<option value="">No category</option>' +
                categoryOptions.map(cat => `<option value="${cat.id}">${escapeHtml(cat.name)}</option>`).join('');

            updateEventStartType();
            document.getElementById('eventModal').classList.add('active');
        }

        function closeEventModal() {
            document.getElementById('eventModal').classList.remove('active');
        }

        function updateEventStartType() {
            const allDay = document.getElementById('eventAllDay').checked;
            document.getElementById('eventStart').type = allDay ? 'date' : 'datetime-local';
        }

        async function saveEvent() {
            const btn = document.getElementById('eventSaveBtn');
            const title = document.getElementById('eventTitle').value.trim();
            const start = document.getElementById('eventStart').value;
            if (!title) {
                showToast('Title is required', 'error');
                return;
            }
            if (!start) {
                showToast('Start date is required', 'error');
                return;
            }
            const allDay = document.getElementById('eventAllDay').checked;
            // Calendar API expects 'YYYY-MM-DD HH:MM:SS'. For date-only, default to start of day.
            const startDateTime = allDay ? start + ' 00:00:00' : start.replace('T', ' ') + ':00';

            btn.disabled = true;
            try {
                const resp = await fetch(CALENDAR_API + 'save_event.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        title: title,
                        description: document.getElementById('eventDescription').value,
                        category_id: document.getElementById('eventCategory').value || null,
                        start_datetime: startDateTime,
                        end_datetime: startDateTime,
                        all_day: allDay,
                        location: document.getElementById('eventLocation').value,
                        contract_id: contractId
                    })
                });
                const data = await resp.json();
                if (data.success) {
                    showToast('Event added to calendar', 'success');
                    closeEventModal();
                    loadRelatedEvents();
                } else {
                    showToast('Error: ' + (data.error || 'Failed to save'), 'error');
                }
            } catch (e) {
                showToast('Failed to save event', 'error');
            } finally {
                btn.disabled = false;
            }
        }
    </script>

    <!-- Create Task Modal -->
    <div class="modal-overlay" id="taskModal">
        <div class="modal">
            <div class="modal-header">
                <h3>New task for this contract</h3>
                <button type="button" class="modal-close" onclick="closeTaskModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" id="taskTitle" />
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="taskDescription"></textarea>
                </div>
                <div class="modal-row">
                    <div class="form-group">
                        <label>Assignee</label>
                        <select id="taskAnalyst"></select>
                    </div>
                    <div class="form-group">
                        <label>Team</label>
                        <select id="taskTeam"></select>
                    </div>
                </div>
                <div class="modal-row">
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" id="taskDueDate" />
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select id="taskPriority">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                            <option value="Urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="taskStatus">
                        <option value="To Do" selected>To Do</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Blocked">Blocked</option>
                        <option value="Done">Done</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeTaskModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="taskSaveBtn" onclick="saveTask()">Save</button>
            </div>
        </div>
    </div>

    <!-- Create Event Modal -->
    <div class="modal-overlay" id="eventModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add to calendar</h3>
                <button type="button" class="modal-close" onclick="closeEventModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" id="eventTitle" />
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="eventDescription"></textarea>
                </div>
                <div class="form-group checkbox-row">
                    <input type="checkbox" id="eventAllDay" checked onchange="updateEventStartType()" />
                    <label for="eventAllDay">All day</label>
                </div>
                <div class="modal-row">
                    <div class="form-group">
                        <label>Start</label>
                        <input type="date" id="eventStart" />
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select id="eventCategory"></select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" id="eventLocation" />
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEventModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="eventSaveBtn" onclick="saveEvent()">Save</button>
            </div>
        </div>
    </div>
</body>
</html>
