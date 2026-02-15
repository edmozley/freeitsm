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
            max-width: 900px;
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
            if (c.contract_status_name) return { class: 'active', label: c.contract_status_name };
            if (!c.is_active) return { class: 'expired', label: 'Inactive' };
            if (!c.contract_end) return { class: 'active', label: 'Active' };
            const end = new Date(c.contract_end);
            const today = new Date(); today.setHours(0,0,0,0);
            const daysLeft = Math.ceil((end - today) / (1000*60*60*24));
            if (daysLeft < 0) return { class: 'expired', label: 'Expired' };
            if (daysLeft <= 90) return { class: 'expiring', label: 'Expiring' };
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
    </script>
</body>
</html>
