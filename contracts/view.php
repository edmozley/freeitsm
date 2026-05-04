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
        const contractId = <?php echo json_encode($contract_id); ?>;

        document.addEventListener('DOMContentLoaded', loadContract);

        async function loadContract() {
            try {
                const response = await fetch(API_BASE + 'get_contract.php?id=' + contractId);
                const data = await response.json();
                if (data.success) {
                    renderContract(data.contract);
                    loadAndRenderContractTerms();
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
    </script>
</body>
</html>
