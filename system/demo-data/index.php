<?php
/**
 * System - Demo Data Import
 * Per-module import of realistic sample data for evaluation and testing.
 */
session_start();
require_once '../../config.php';

$current_page = 'demo-data';
$path_prefix = '../../';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Demo Data</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; }

        .demo-container {
            max-width: 900px;
            margin: 30px auto;
            padding: 0 20px 40px;
        }

        .demo-header {
            margin-bottom: 25px;
        }

        .demo-header h2 {
            margin: 0;
            font-size: 22px;
            color: #333;
        }

        .demo-header p {
            margin: 5px 0 0 0;
            font-size: 13px;
            color: #888;
        }

        .warning-card {
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .warning-card svg { color: #f9a825; flex-shrink: 0; margin-top: 2px; }

        .warning-card .warning-text {
            font-size: 13px;
            color: #6d4c00;
            line-height: 1.5;
        }

        .warning-card .warning-text strong { color: #e65100; }

        .section-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #888;
            margin: 0 0 12px 0;
            font-weight: 600;
        }

        /* Core card - full width, highlighted */
        .core-card {
            background: white;
            border: 2px solid #546e7a;
            border-radius: 10px;
            padding: 20px 24px;
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .core-card .core-info h3 {
            margin: 0 0 4px 0;
            font-size: 16px;
            color: #333;
        }

        .core-card .core-info p {
            margin: 0;
            font-size: 13px;
            color: #666;
        }

        .core-card .core-info .core-detail {
            margin: 8px 0 0 0;
            font-size: 12px;
            color: #888;
        }

        /* Module grid */
        .module-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }

        .module-card {
            background: white;
            border-radius: 8px;
            padding: 18px 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            display: flex;
            flex-direction: column;
        }

        .module-card h4 {
            margin: 0 0 4px 0;
            font-size: 14px;
            color: #333;
        }

        .module-card .module-desc {
            font-size: 12px;
            color: #888;
            margin: 0 0 14px 0;
            line-height: 1.4;
            flex: 1;
        }

        .module-card .module-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Import buttons */
        .import-btn {
            background: #546e7a;
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .import-btn:hover { background: #37474f; }
        .import-btn:disabled { background: #bbb; cursor: not-allowed; }

        .import-btn.success {
            background: #2e7d32;
            cursor: default;
        }

        .import-btn.success:hover { background: #2e7d32; }

        .import-btn-lg {
            padding: 10px 24px;
            font-size: 14px;
        }

        .record-count {
            font-size: 12px;
            color: #999;
        }

        .error-text {
            font-size: 12px;
            color: #c62828;
            margin-top: 8px;
        }

        .spinner-inline {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .check-icon {
            display: inline-block;
            width: 14px;
            height: 14px;
        }

        /* Bonus cross-module section */
        .bonus-section {
            display: none;
            margin-top: 8px;
        }

        .bonus-card {
            background: white;
            border: 2px dashed #90a4ae;
            border-radius: 10px;
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }

        .bonus-card .bonus-info h4 {
            margin: 0 0 4px 0;
            font-size: 14px;
            color: #333;
        }

        .bonus-card .bonus-info p {
            margin: 0;
            font-size: 12px;
            color: #888;
            line-height: 1.4;
        }

        .bonus-card .bonus-info .bonus-detail {
            margin: 8px 0 0 0;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="demo-container">
        <div class="demo-header">
            <h2>Demo Data</h2>
            <p>Import realistic sample data module by module. Import Core first, then choose which modules to populate.</p>
        </div>

        <div class="warning-card">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <div class="warning-text">
                <strong>Designed for fresh installations only.</strong> Importing demo data into a system that already contains real data may cause conflicts. Each module can only be imported once.
            </div>
        </div>

        <!-- Core -->
        <p class="section-label">Step 1 &mdash; Required</p>
        <div class="core-card" id="core-card">
            <div class="core-info">
                <h3>Core Data</h3>
                <p>Analysts, departments, teams, ticket types, origins, and end users. All other modules depend on this.</p>
                <p class="core-detail">4 analysts (password: demo1234) &bull; 5 departments &bull; 2 teams &bull; 15 end users &bull; 5 ticket types &bull; 4 origins</p>
            </div>
            <button class="import-btn import-btn-lg" id="btn-core" onclick="importModule('core', this)">Import</button>
        </div>

        <!-- Modules -->
        <p class="section-label">Step 2 &mdash; Choose modules</p>
        <div class="module-grid" id="moduleGrid">
            <div class="module-card" data-module="tickets">
                <h4>Tickets</h4>
                <p class="module-desc">30 tickets with emails, notes, and audit history across multiple statuses and priorities.</p>
                <div class="module-footer">
                    <span class="record-count">~115 records</span>
                    <button class="import-btn" id="btn-tickets" onclick="importModule('tickets', this)" disabled>Import</button>
                </div>
                <div class="error-text" id="err-tickets" style="display:none"></div>
            </div>

            <div class="module-card" data-module="assets">
                <h4>Assets</h4>
                <p class="module-desc">10 assets (laptops, desktops, monitors) with types, statuses, and user assignments.</p>
                <div class="module-footer">
                    <span class="record-count">~24 records</span>
                    <button class="import-btn" id="btn-assets" onclick="importModule('assets', this)" disabled>Import</button>
                </div>
                <div class="error-text" id="err-assets" style="display:none"></div>
            </div>

            <div class="module-card" data-module="knowledge">
                <h4>Knowledge Base</h4>
                <p class="module-desc">5 articles covering VPN, Outlook, passwords, printing, and onboarding with tags.</p>
                <div class="module-footer">
                    <span class="record-count">~23 records</span>
                    <button class="import-btn" id="btn-knowledge" onclick="importModule('knowledge', this)" disabled>Import</button>
                </div>
                <div class="error-text" id="err-knowledge" style="display:none"></div>
            </div>

            <div class="module-card" data-module="changes">
                <h4>Change Management</h4>
                <p class="module-desc">5 changes in Draft, Approved, In Progress, Completed, and Cancelled statuses.</p>
                <div class="module-footer">
                    <span class="record-count">~5 records</span>
                    <button class="import-btn" id="btn-changes" onclick="importModule('changes', this)" disabled>Import</button>
                </div>
                <div class="error-text" id="err-changes" style="display:none"></div>
            </div>

            <div class="module-card" data-module="calendar">
                <h4>Calendar</h4>
                <p class="module-desc">3 categories and 8 events including maintenance windows, meetings, and releases.</p>
                <div class="module-footer">
                    <span class="record-count">~11 records</span>
                    <button class="import-btn" id="btn-calendar" onclick="importModule('calendar', this)" disabled>Import</button>
                </div>
                <div class="error-text" id="err-calendar" style="display:none"></div>
            </div>

            <div class="module-card" data-module="checks">
                <h4>Morning Checks</h4>
                <p class="module-desc">6 checks with 30 days of results showing realistic OK, Warning, and Fail patterns.</p>
                <div class="module-footer">
                    <span class="record-count">~186 records</span>
                    <button class="import-btn" id="btn-checks" onclick="importModule('checks', this)" disabled>Import</button>
                </div>
                <div class="error-text" id="err-checks" style="display:none"></div>
            </div>

            <div class="module-card" data-module="contracts">
                <h4>Contracts</h4>
                <p class="module-desc">3 suppliers, 5 contacts, 3 contracts with SLA terms, plus lookup tables.</p>
                <div class="module-footer">
                    <span class="record-count">~25 records</span>
                    <button class="import-btn" id="btn-contracts" onclick="importModule('contracts', this)" disabled>Import</button>
                </div>
                <div class="error-text" id="err-contracts" style="display:none"></div>
            </div>

            <div class="module-card" data-module="services">
                <h4>Service Status</h4>
                <p class="module-desc">5 services with 2 incidents showing resolved and monitoring states.</p>
                <div class="module-footer">
                    <span class="record-count">~11 records</span>
                    <button class="import-btn" id="btn-services" onclick="importModule('services', this)" disabled>Import</button>
                </div>
                <div class="error-text" id="err-services" style="display:none"></div>
            </div>

            <div class="module-card" data-module="software">
                <h4>Software</h4>
                <p class="module-desc">20 applications with 13 licences &mdash; subscriptions, perpetual, expired, and bundled. Includes M365, Adobe CC, CrowdStrike, Citrix, and more.</p>
                <div class="module-footer">
                    <span class="record-count">~33 records</span>
                    <button class="import-btn" id="btn-software" onclick="importModule('software', this)" disabled>Import</button>
                </div>
                <div class="error-text" id="err-software" style="display:none"></div>
            </div>

            <div class="module-card" data-module="forms">
                <h4>Forms</h4>
                <p class="module-desc">2 forms (New Starter, Equipment Return) with fields and 3 completed submissions.</p>
                <div class="module-footer">
                    <span class="record-count">~22 records</span>
                    <button class="import-btn" id="btn-forms" onclick="importModule('forms', this)" disabled>Import</button>
                </div>
                <div class="error-text" id="err-forms" style="display:none"></div>
            </div>
        </div>

        <!-- Bonus: cross-module linking (appears after both software + assets imported) -->
        <div class="bonus-section" id="bonusSection">
            <p class="section-label">Step 3 &mdash; Cross-module data</p>
            <div class="bonus-card" id="bonus-software-assets">
                <div class="bonus-info">
                    <h4>Software Installed on Assets</h4>
                    <p>Links software applications to computers, showing which apps are installed on each device. Requires both Software and Assets to be imported first.</p>
                    <p class="bonus-detail">~55 installation records across 6 computers &bull; Realistic version numbers and install paths</p>
                </div>
                <button class="import-btn" id="btn-software-assets" onclick="importModule('software-assets', this)" disabled>Import</button>
            </div>
            <div class="error-text" id="err-software-assets" style="display:none"></div>
        </div>
    </div>

    <script>
        let coreImported = false;
        let importedModules = {};

        const checkSvg = '<svg class="check-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

        async function importModule(module, btn) {
            btn.disabled = true;
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-inline"></span> Importing...';

            const errEl = document.getElementById('err-' + module);
            if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }

            try {
                const body = new FormData();
                body.append('module', module);

                const response = await fetch('../../api/system/import_demo_data.php', { method: 'POST', body: body });
                const data = await response.json();

                if (data.success) {
                    btn.className = 'import-btn success';
                    btn.innerHTML = checkSvg + ' ' + data.total + ' imported';
                    btn.disabled = true;
                    importedModules[module] = true;

                    if (module === 'core') {
                        coreImported = true;
                        enableModuleButtons();
                    }

                    checkBonusEligibility();
                } else {
                    if (errEl) { errEl.textContent = data.error; errEl.style.display = 'block'; }
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                }
            } catch (error) {
                if (errEl) { errEl.textContent = 'Connection failed: ' + error.message; errEl.style.display = 'block'; }
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }

        function enableModuleButtons() {
            const modules = ['tickets', 'assets', 'knowledge', 'changes', 'calendar', 'checks', 'contracts', 'services', 'software', 'forms'];
            modules.forEach(function(m) {
                const btn = document.getElementById('btn-' + m);
                if (btn && !btn.classList.contains('success')) {
                    btn.disabled = false;
                }
            });
        }

        function checkBonusEligibility() {
            if (importedModules['software'] && importedModules['assets']) {
                var section = document.getElementById('bonusSection');
                section.style.display = 'block';
                var btn = document.getElementById('btn-software-assets');
                if (btn && !btn.classList.contains('success')) {
                    btn.disabled = false;
                }
            }
        }

        // On page load, check if core data already exists
        (async function checkCoreStatus() {
            try {
                const response = await fetch('../../api/system/check_demo_core.php');
                const data = await response.json();
                if (data.exists) {
                    coreImported = true;
                    const btn = document.getElementById('btn-core');
                    btn.className = 'import-btn import-btn-lg success';
                    btn.innerHTML = checkSvg + ' Already imported';
                    btn.disabled = true;
                    enableModuleButtons();
                }
            } catch (e) { /* ignore - user can still click Import */ }
        })();
    </script>
</body>
</html>
