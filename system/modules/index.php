<?php
/**
 * System - Module Access Management
 * Configure which modules each analyst can access
 */
session_start();
require_once '../../config.php';

$current_page = 'modules';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Module Access</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .main-container {
            flex: 1;
            background: #f5f7fa;
            overflow-y: auto;
        }

        .modules-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .page-title {
            font-size: 22px;
            font-weight: 600;
            color: #333;
            margin: 0 0 6px 0;
        }

        .page-subtitle {
            font-size: 13px;
            color: #888;
            margin: 0 0 24px 0;
        }

        /* Info banner */
        .info-banner {
            background: #e3f2fd;
            border: 1px solid #90caf9;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-banner svg {
            width: 18px;
            height: 18px;
            color: #1565c0;
            flex-shrink: 0;
        }

        .info-banner p {
            margin: 0;
            font-size: 13px;
            color: #1565c0;
            line-height: 1.4;
        }

        /* Matrix table */
        .matrix-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .matrix-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .matrix-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .matrix-table th {
            background: #546e7a;
            color: #fff;
            padding: 10px 8px;
            font-weight: 600;
            font-size: 12px;
            text-align: center;
            white-space: nowrap;
            border-right: 1px solid rgba(255,255,255,0.1);
        }

        .matrix-table th:first-child {
            text-align: left;
            padding-left: 16px;
            min-width: 180px;
        }

        .matrix-table th:last-child {
            border-right: none;
        }

        .matrix-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #f0f0f0;
            text-align: center;
            vertical-align: middle;
        }

        .matrix-table td:first-child {
            text-align: left;
            padding-left: 16px;
        }

        .matrix-table tr:hover td {
            background: #f9fafb;
        }

        .matrix-table tr:last-child td {
            border-bottom: none;
        }

        /* Analyst cell */
        .analyst-info {
            display: flex;
            flex-direction: column;
        }

        .analyst-name {
            font-weight: 600;
            color: #333;
        }

        .analyst-username {
            font-size: 11px;
            color: #999;
        }

        /* Checkbox toggle */
        .toggle {
            position: relative;
            display: inline-block;
            width: 36px;
            height: 20px;
        }

        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #ccc;
            border-radius: 20px;
            transition: 0.2s;
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background: #fff;
            border-radius: 50%;
            transition: 0.2s;
        }

        .toggle input:checked + .toggle-slider {
            background: #546e7a;
        }

        .toggle input:checked + .toggle-slider::before {
            transform: translateX(16px);
        }

        .toggle input:disabled + .toggle-slider {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* All access badge */
        .all-access-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .all-access-label {
            font-size: 11px;
            color: #888;
            white-space: nowrap;
        }

        /* Status indicator */
        .save-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            color: #107c10;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .save-indicator.show { opacity: 1; }

        .save-indicator svg {
            width: 14px;
            height: 14px;
        }

        /* Loading */
        .loading-spinner {
            text-align: center;
            padding: 60px;
            color: #888;
            font-size: 13px;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #888;
        }

        .empty-state svg {
            width: 48px;
            height: 48px;
            color: #ccc;
            margin-bottom: 12px;
        }

        .empty-state h3 {
            margin: 0 0 4px 0;
            font-size: 16px;
            color: #666;
        }

        .empty-state p {
            margin: 0;
            font-size: 13px;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #333;
            color: #fff;
            padding: 12px 20px;
            border-radius: 6px;
            font-size: 13px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            z-index: 1000;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s;
        }

        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .toast.error { background: #d32f2f; }
        .toast.success { background: #2e7d32; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="modules-container">
            <h1 class="page-title">Module Access</h1>
            <p class="page-subtitle">Control which modules each analyst can see on the home screen and in navigation</p>

            <div class="info-banner">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                <p>By default all analysts have access to every module. Toggle <strong>All Access</strong> off to restrict an analyst to specific modules. The System module cannot be disabled.</p>
            </div>

            <div id="loading" class="loading-spinner">Loading analysts...</div>

            <div id="matrixCard" class="matrix-card" style="display: none;"></div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
    const MODULE_LABELS = {
        'tickets': 'Tickets',
        'assets': 'Assets',
        'knowledge': 'Knowledge',
        'changes': 'Changes',
        'calendar': 'Calendar',
        'morning-checks': 'Checks',
        'reporting': 'Reporting',
        'software': 'Software',
        'forms': 'Forms',
        'contracts': 'Contracts',
        'wiki': 'Wiki',
        'system': 'System'
    };

    let analysts = [];
    let moduleAssignments = {};
    let availableModules = [];
    let saveTimers = {};

    async function loadData() {
        try {
            const resp = await fetch('<?php echo $path_prefix; ?>api/system/get_analyst_modules.php');
            const data = await resp.json();

            if (!data.success) {
                showToast(data.error, 'error');
                return;
            }

            analysts = data.analysts;
            moduleAssignments = data.module_assignments || {};
            availableModules = data.available_modules;

            renderMatrix();
        } catch (e) {
            showToast('Failed to load data', 'error');
        }
    }

    function renderMatrix() {
        document.getElementById('loading').style.display = 'none';
        const card = document.getElementById('matrixCard');
        card.style.display = 'block';

        if (analysts.length === 0) {
            card.innerHTML = `
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                    <h3>No analysts found</h3>
                    <p>Add analysts in the Tickets module settings first.</p>
                </div>
            `;
            return;
        }

        // Build header
        let headerHtml = '<th>Analyst</th><th>All Access</th>';
        availableModules.forEach(m => {
            headerHtml += `<th>${escapeHtml(MODULE_LABELS[m] || m)}</th>`;
        });

        // Build rows
        let rowsHtml = '';
        analysts.forEach(analyst => {
            const aid = analyst.id;
            const assigned = moduleAssignments[aid] || [];
            const hasAllAccess = assigned.length === 0;

            rowsHtml += `<tr data-analyst-id="${aid}">`;
            rowsHtml += `<td>
                <div class="analyst-info">
                    <span class="analyst-name">${escapeHtml(analyst.full_name)}</span>
                    <span class="analyst-username">${escapeHtml(analyst.username)}</span>
                </div>
            </td>`;

            // All Access toggle
            rowsHtml += `<td>
                <div class="all-access-toggle">
                    <label class="toggle">
                        <input type="checkbox" ${hasAllAccess ? 'checked' : ''} onchange="toggleAllAccess(${aid}, this.checked)">
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            </td>`;

            // Module toggles
            availableModules.forEach(m => {
                const isSystem = m === 'system';
                const isChecked = hasAllAccess || assigned.includes(m);
                const isDisabled = hasAllAccess || isSystem;

                rowsHtml += `<td>
                    <label class="toggle">
                        <input type="checkbox"
                            ${isChecked ? 'checked' : ''}
                            ${isDisabled ? 'disabled' : ''}
                            data-analyst="${aid}"
                            data-module="${m}"
                            onchange="toggleModule(${aid}, '${m}', this.checked)">
                        <span class="toggle-slider"></span>
                    </label>
                </td>`;
            });

            rowsHtml += '</tr>';
        });

        card.innerHTML = `
            <table class="matrix-table">
                <thead><tr>${headerHtml}</tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table>
        `;
    }

    function toggleAllAccess(analystId, allAccess) {
        const row = document.querySelector(`tr[data-analyst-id="${analystId}"]`);
        const checkboxes = row.querySelectorAll('input[data-module]');

        if (allAccess) {
            // All access: check all, disable all
            checkboxes.forEach(cb => {
                cb.checked = true;
                cb.disabled = true;
            });
            // Save empty array = full access
            moduleAssignments[analystId] = undefined;
            saveModules(analystId, []);
        } else {
            // Restricted: enable all (except system), keep all checked
            checkboxes.forEach(cb => {
                cb.checked = true;
                cb.disabled = (cb.dataset.module === 'system');
            });
            // Save all modules (since they're all still checked)
            const allModules = availableModules.slice();
            moduleAssignments[analystId] = allModules;
            saveModules(analystId, allModules);
        }
    }

    function toggleModule(analystId, moduleKey, checked) {
        // Get current modules for this analyst
        let current = moduleAssignments[analystId] || [];
        if (!Array.isArray(current)) current = availableModules.slice();

        if (checked && !current.includes(moduleKey)) {
            current.push(moduleKey);
        } else if (!checked) {
            current = current.filter(m => m !== moduleKey);
        }

        // Ensure system is always included
        if (!current.includes('system')) {
            current.push('system');
        }

        moduleAssignments[analystId] = current;
        saveModules(analystId, current);
    }

    async function saveModules(analystId, modules) {
        // Debounce saves per analyst (300ms)
        if (saveTimers[analystId]) clearTimeout(saveTimers[analystId]);

        saveTimers[analystId] = setTimeout(async () => {
            try {
                const resp = await fetch('<?php echo $path_prefix; ?>api/system/save_analyst_modules.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ analyst_id: analystId, modules: modules })
                });
                const data = await resp.json();
                if (data.success) {
                    showToast(data.message, 'success');
                } else {
                    showToast(data.error, 'error');
                }
            } catch (e) {
                showToast('Failed to save', 'error');
            }
        }, 300);
    }

    function showToast(message, type) {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.className = 'toast ' + (type || '') + ' show';
        setTimeout(() => { toast.className = 'toast'; }, 2500);
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    // Init
    loadData();
    </script>
</body>
</html>
