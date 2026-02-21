<?php
/**
 * Change Management Approvals - View changes pending approval
 */
session_start();
require_once '../config.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'approvals';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Change Approvals</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/change-management.css">
    <style>
        .approvals-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        .approvals-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .approvals-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 10px;
        }

        .approval-filter {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            color: #333;
            transition: background 0.15s;
        }

        .approval-filter:hover { background: #f5f5f5; }

        .approval-filter.active {
            background: #e0f2f1;
            color: #00897b;
            font-weight: 600;
        }

        .approval-filter .filter-count {
            font-size: 12px;
            background: #eee;
            color: #666;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }

        .approval-filter.active .filter-count {
            background: #00897b;
            color: white;
        }

        .approvals-main {
            flex: 1;
            overflow-y: auto;
            padding: 24px 30px;
        }

        .approvals-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .approvals-header h2 {
            margin: 0;
            font-size: 20px;
            color: #333;
        }

        .approval-card {
            background: white;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            cursor: pointer;
            transition: box-shadow 0.15s;
            border-left: 4px solid #e65100;
        }

        .approval-card:hover {
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .approval-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .approval-card-ref {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            font-family: monospace;
        }

        .approval-card-badges {
            display: flex;
            gap: 6px;
        }

        .approval-card-title {
            font-size: 15px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .approval-card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 12px;
            color: #888;
        }

        .approval-card-meta span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .meta-label {
            font-weight: 600;
            color: #999;
        }

        .approval-empty {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .approval-empty svg {
            margin-bottom: 16px;
            color: #ccc;
        }

        .approval-empty h3 {
            font-size: 16px;
            color: #666;
            margin: 0 0 6px;
        }

        .approval-empty p {
            font-size: 13px;
            margin: 0;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="approvals-container">
        <div class="approvals-sidebar">
            <h3>Filter</h3>
            <div class="approval-filter active" data-filter="all" onclick="setFilter('all')">
                <span>All</span>
                <span class="filter-count" id="countAll">0</span>
            </div>
            <div class="approval-filter" data-filter="assigned" onclick="setFilter('assigned')">
                <span>Assigned to me</span>
                <span class="filter-count" id="countAssigned">0</span>
            </div>
            <div class="approval-filter" data-filter="requested" onclick="setFilter('requested')">
                <span>Requested by me</span>
                <span class="filter-count" id="countRequested">0</span>
            </div>
            <div class="approval-filter" data-filter="cab" onclick="setFilter('cab')">
                <span>My CAB reviews</span>
                <span class="filter-count" id="countCab">0</span>
            </div>
        </div>

        <div class="approvals-main">
            <div class="approvals-header">
                <h2 id="approvalsTitle">Pending Approvals</h2>
            </div>
            <div id="approvalsList">
                <div class="loading"><div class="spinner"></div></div>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/change-management/';
        let currentFilter = 'all';

        document.addEventListener('DOMContentLoaded', function() {
            loadApprovals();
        });

        function setFilter(filter) {
            currentFilter = filter;
            document.querySelectorAll('.approval-filter').forEach(el => {
                el.classList.toggle('active', el.dataset.filter === filter);
            });
            loadApprovals();
        }

        async function loadApprovals() {
            try {
                const res = await fetch(API_BASE + 'get_approvals.php?filter=' + currentFilter);
                const data = await res.json();

                if (!data.success) {
                    document.getElementById('approvalsList').innerHTML =
                        '<div class="approval-empty"><p>Error: ' + (data.error || 'Unknown error') + '</p></div>';
                    return;
                }

                // Update counts
                document.getElementById('countAll').textContent = data.counts.all;
                document.getElementById('countAssigned').textContent = data.counts.assigned;
                document.getElementById('countRequested').textContent = data.counts.requested;
                document.getElementById('countCab').textContent = data.counts.cab || 0;

                renderApprovals(data.changes);
            } catch (e) {
                console.error(e);
            }
        }

        function renderApprovals(changes) {
            const container = document.getElementById('approvalsList');

            if (!changes.length) {
                container.innerHTML = `
                    <div class="approval-empty">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                        <h3>No pending approvals</h3>
                        <p>There are no changes awaiting approval in this view.</p>
                    </div>`;
                return;
            }

            container.innerHTML = changes.map(c => {
                const ref = 'CHG-' + String(c.id).padStart(4, '0');
                const typeClass = c.change_type.toLowerCase();
                const priorityClass = c.priority.toLowerCase();
                const date = c.created_datetime ? formatDate(c.created_datetime) : '';

                let cabBadge = '';
                if (parseInt(c.cab_required) && c.cab_progress) {
                    cabBadge = `<span class="cab-progress-small">CAB: ${c.cab_progress.required_approved}/${c.cab_progress.required_total}</span>`;
                }

                return `
                    <div class="approval-card" onclick="openChange(${c.id})">
                        <div class="approval-card-top">
                            <span class="approval-card-ref">${ref}</span>
                            <div class="approval-card-badges">
                                ${cabBadge}
                                <span class="type-badge ${typeClass}">${escapeHtml(c.change_type)}</span>
                                <span class="priority-badge ${priorityClass}">${escapeHtml(c.priority)}</span>
                            </div>
                        </div>
                        <div class="approval-card-title">${escapeHtml(c.title)}</div>
                        <div class="approval-card-meta">
                            ${c.requester_name ? `<span><span class="meta-label">Requester:</span> ${escapeHtml(c.requester_name)}</span>` : ''}
                            ${c.approver_name ? `<span><span class="meta-label">Approver:</span> ${escapeHtml(c.approver_name)}</span>` : ''}
                            ${c.work_start_datetime ? `<span><span class="meta-label">Work Start:</span> ${formatDate(c.work_start_datetime)}</span>` : ''}
                            ${date ? `<span><span class="meta-label">Submitted:</span> ${date}</span>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function openChange(id) {
            window.location.href = '../change-management/?open=' + id;
        }

        function formatDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr.replace(' ', 'T'));
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            let hours = d.getHours();
            const mins = d.getMinutes().toString().padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            return `${months[d.getMonth()]} ${d.getDate()}, ${hours}:${mins} ${ampm}`;
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
