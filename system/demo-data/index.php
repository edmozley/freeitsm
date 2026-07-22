<?php
/**
 * System - Demo Data Import
 * Per-module import of realistic sample data for evaluation and testing.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
Tz::init();

$current_page = 'demo-data';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.demo.heading')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* System module accent (blue-grey) */
        body {
            /* System is the FIRST module whose DARK accent is a LIGHT colour (#90a4ae).
               inbox.css renders .btn-primary/.add-btn as background:var(--accent) +
               color:var(--on-accent) — and the global --on-accent stays WHITE in dark.
               So pinning --accent alone would put white text on a light button. Pin
               --on-accent too: it flips to near-black in dark. */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        .demo-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            padding: 0 20px 40px;
        }

        .demo-header {
            margin-bottom: 25px;
        }

        .demo-header h2 {
            margin: 0;
            font-size: 22px;
            color: var(--text, #333);
        }

        .demo-header p {
            margin: 5px 0 0 0;
            font-size: 13px;
            color: var(--text-dim, #888);
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

        .tip-card {
            background: #e8f4fd;
            border: 1px solid #90caf9;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .tip-card svg { color: #1976d2; flex-shrink: 0; }

        .tip-card .tip-text {
            font-size: 13px;
            color: #1565c0;
            line-height: 1.4;
        }

        .tip-card .tip-text strong { color: #0d47a1; }

        .section-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-dim, #888);
            margin: 0 0 12px 0;
            font-weight: 600;
        }

        /* Core card - full width, highlighted */
        .core-card {
            background: var(--surface, #fff);
            border: 2px solid var(--sys-accent, #546e7a);
            border-radius: 10px;
            padding: 20px 24px;
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px var(--shadow, rgba(0,0,0,0.06));
        }

        .core-card .core-info h3 {
            margin: 0 0 4px 0;
            font-size: 16px;
            color: var(--text, #333);
        }

        .core-card .core-info p {
            margin: 0;
            font-size: 13px;
            color: var(--text-muted, #666);
        }

        .core-card .core-info .core-detail {
            margin: 8px 0 0 0;
            font-size: 12px;
            color: var(--text-dim, #888);
        }

        /* Module grid */
        .module-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }

        .module-card {
            background: var(--surface, #fff);
            border-radius: 8px;
            padding: 18px 20px;
            box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08));
            display: flex;
            flex-direction: column;
        }

        .module-card h4 {
            margin: 0 0 4px 0;
            font-size: 14px;
            color: var(--text, #333);
        }

        .module-card .module-desc {
            font-size: 12px;
            color: var(--text-dim, #888);
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
            background: var(--sys-accent, #546e7a);
            color: var(--sys-on-accent, #fff);
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

        .import-btn:hover { background: var(--sys-accent-hover, #37474f); }
        /* Solid fills below keep white text in BOTH modes — --sys-on-accent is
           dark in dark mode, which would be unreadable on them. */
        .import-btn:disabled { background: #bbb; color: #fff; cursor: not-allowed; }

        .import-btn.success {
            background: #2e7d32;
            color: #fff;
            cursor: pointer;
        }

        .import-btn.success:hover { background: #1b5e20; }

        .import-btn-lg {
            padding: 10px 24px;
            font-size: 14px;
        }

        .record-count {
            font-size: 12px;
            color: var(--text-faint, #999);
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
            border-top-color: currentColor; /* follows the button's text colour */
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
            background: var(--surface, #fff);
            border: 2px dashed #90a4ae;
            border-radius: 10px;
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.06));
        }

        .bonus-card .bonus-info h4 {
            margin: 0 0 4px 0;
            font-size: 14px;
            color: var(--text, #333);
        }

        .bonus-card .bonus-info p {
            margin: 0;
            font-size: 12px;
            color: var(--text-dim, #888);
            line-height: 1.4;
        }

        .bonus-card .bonus-info .bonus-detail {
            margin: 8px 0 0 0;
            font-size: 12px;
            color: var(--text-faint, #999);
        }

        /* ---- Dark mode overrides ----------------------------------------
           The amber warning band, the blue tip band and the red error text are
           meaning-carrying: hues kept, washes sunk, text lifted. */
        [data-theme-mode="dark"] .warning-card { background: #3a2e12; border-color: #5a4a1e; }
        [data-theme-mode="dark"] .warning-card svg { color: #fbbf24; }
        [data-theme-mode="dark"] .warning-card .warning-text { color: #fcd34d; }
        [data-theme-mode="dark"] .warning-card .warning-text strong { color: #ffb74d; }

        [data-theme-mode="dark"] .tip-card { background: #16293f; border-color: #2c4a6b; }
        [data-theme-mode="dark"] .tip-card svg { color: #60a5fa; }
        [data-theme-mode="dark"] .tip-card .tip-text { color: #93c5fd; }
        [data-theme-mode="dark"] .tip-card .tip-text strong { color: #bfdbfe; }

        [data-theme-mode="dark"] .import-btn:disabled { background: #4a505a; color: var(--text-faint, #999); }
        [data-theme-mode="dark"] .import-btn.success { background: #2e7d32; color: #fff; }
        [data-theme-mode="dark"] .import-btn.success:hover { background: #256428; }
        [data-theme-mode="dark"] .spinner-inline { border-color: rgba(0,0,0,0.2); border-top-color: currentColor; }
        [data-theme-mode="dark"] .error-text { color: var(--danger-text, #fca5a5); }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="demo-container">
        <div class="demo-header">
            <h2><?php echo htmlspecialchars(t('system.demo.heading')); ?></h2>
            <p><?php echo htmlspecialchars(t('system.demo.subtitle')); ?></p>
        </div>

        <div class="warning-card">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <div class="warning-text">
                <strong><?php echo htmlspecialchars(t('system.demo.warning_strong')); ?></strong> <?php echo htmlspecialchars(t('system.demo.warning_text')); ?>
            </div>
        </div>

        <div class="tip-card">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
            <div class="tip-text">
                <?php echo htmlspecialchars(t('system.demo.tip_text_prefix')); ?> <strong><?php echo htmlspecialchars(t('system.demo.tip_assets')); ?></strong> <?php echo htmlspecialchars(t('system.demo.tip_text_and')); ?> <strong><?php echo htmlspecialchars(t('system.demo.tip_software')); ?></strong> <?php echo htmlspecialchars(t('system.demo.tip_text_suffix')); ?>
            </div>
        </div>

        <!-- Core -->
        <p class="section-label"><?php echo htmlspecialchars(t('system.demo.step1')); ?></p>
        <div class="core-card" id="core-card">
            <div class="core-info">
                <h3>Core Data</h3>
                <p>Analysts, departments, teams with roles and module access, ticket types, origins, and end users. All other modules depend on this.</p>
                <p class="core-detail">4 analysts, none admin (password: demo1234) &bull; 5 departments &bull; 2 teams with matching roles &amp; module access &bull; 15 end users &bull; 5 ticket types &bull; 4 origins</p>
            </div>
            <button class="import-btn import-btn-lg" id="btn-core" onclick="importModule('core', this)"><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
        </div>

        <!-- Modules -->
        <p class="section-label"><?php echo htmlspecialchars(t('system.demo.step2')); ?></p>
        <div class="module-grid" id="moduleGrid">
            <div class="module-card" data-module="tickets">
                <h4>Tickets</h4>
                <p class="module-desc">30 tickets with emails, notes, and audit history across multiple statuses and priorities.</p>
                <div class="module-footer">
                    <span class="record-count">~115 records</span>
                    <button class="import-btn" id="btn-tickets" onclick="importModule('tickets', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-tickets" style="display:none"></div>
            </div>

            <div class="module-card" data-module="assets">
                <h4>Assets</h4>
                <p class="module-desc">10 assets (laptops, desktops, monitors) with types, statuses, and user assignments.</p>
                <div class="module-footer">
                    <span class="record-count">~24 records</span>
                    <button class="import-btn" id="btn-assets" onclick="importModule('assets', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-assets" style="display:none"></div>
            </div>

            <div class="module-card" data-module="knowledge">
                <h4>Knowledge Base</h4>
                <p class="module-desc">5 articles covering VPN, Outlook, passwords, printing, and onboarding with tags.</p>
                <div class="module-footer">
                    <span class="record-count">~23 records</span>
                    <button class="import-btn" id="btn-knowledge" onclick="importModule('knowledge', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-knowledge" style="display:none"></div>
            </div>

            <div class="module-card" data-module="changes">
                <h4>Change Management</h4>
                <p class="module-desc">5 changes in Draft, Approved, In Progress, Completed, and Cancelled statuses.</p>
                <div class="module-footer">
                    <span class="record-count">~5 records</span>
                    <button class="import-btn" id="btn-changes" onclick="importModule('changes', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-changes" style="display:none"></div>
            </div>

            <div class="module-card" data-module="calendar">
                <h4>Calendar</h4>
                <p class="module-desc">3 categories and 8 events including maintenance windows, meetings, and releases.</p>
                <div class="module-footer">
                    <span class="record-count">~11 records</span>
                    <button class="import-btn" id="btn-calendar" onclick="importModule('calendar', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-calendar" style="display:none"></div>
            </div>

            <div class="module-card" data-module="checks">
                <h4>Morning Checks</h4>
                <p class="module-desc">6 checks with 30 days of results showing realistic OK, Warning, and Fail patterns.</p>
                <div class="module-footer">
                    <span class="record-count">~186 records</span>
                    <button class="import-btn" id="btn-checks" onclick="importModule('checks', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-checks" style="display:none"></div>
            </div>

            <div class="module-card" data-module="contracts">
                <h4>Contracts</h4>
                <p class="module-desc">3 suppliers, 5 contacts, 3 contracts with SLA terms, plus lookup tables.</p>
                <div class="module-footer">
                    <span class="record-count">~25 records</span>
                    <button class="import-btn" id="btn-contracts" onclick="importModule('contracts', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-contracts" style="display:none"></div>
            </div>

            <div class="module-card" data-module="services">
                <h4>Service Status</h4>
                <p class="module-desc">5 services with 2 incidents showing resolved and monitoring states.</p>
                <div class="module-footer">
                    <span class="record-count">~11 records</span>
                    <button class="import-btn" id="btn-services" onclick="importModule('services', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-services" style="display:none"></div>
            </div>

            <div class="module-card" data-module="software">
                <h4>Software</h4>
                <p class="module-desc">20 applications with 13 licences &mdash; subscriptions, perpetual, expired, and bundled. Includes M365, Adobe CC, CrowdStrike, Citrix, and more.</p>
                <div class="module-footer">
                    <span class="record-count">~33 records</span>
                    <button class="import-btn" id="btn-software" onclick="importModule('software', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-software" style="display:none"></div>
            </div>

            <div class="module-card" data-module="forms">
                <h4>Forms</h4>
                <p class="module-desc">2 forms (New Starter, Equipment Return) with fields and 3 completed submissions.</p>
                <div class="module-footer">
                    <span class="record-count">~22 records</span>
                    <button class="import-btn" id="btn-forms" onclick="importModule('forms', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-forms" style="display:none"></div>
            </div>

            <div class="module-card" data-module="tasks">
                <h4>Tasks</h4>
                <p class="module-desc">12 parent tasks across To Do, In Progress, Done with subtasks, due dates, and comments.</p>
                <div class="module-footer">
                    <span class="record-count">~42 records</span>
                    <button class="import-btn" id="btn-tasks" onclick="importModule('tasks', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-tasks" style="display:none"></div>
            </div>

            <div class="module-card" data-module="process-mapper">
                <h4>Process Mapper</h4>
                <p class="module-desc">6 ITSM flowcharts (incident triage, onboarding, change approval, major incident, asset disposal, password reset) with auto-laid-out steps and connectors.</p>
                <div class="module-footer">
                    <span class="record-count">~125 records</span>
                    <button class="import-btn" id="btn-process-mapper" onclick="importModule('process-mapper', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-process-mapper" style="display:none"></div>
            </div>

            <div class="module-card" data-module="cmdb">
                <h4>CMDB</h4>
                <p class="module-desc">A small IT estate &mdash; 8 classes (Server, Database, Application, Service, Person, Team, Network Device, Endpoint) with ~50 properties (incl. coloured criticality / environment / tier dropdowns), 39 objects (8 people, 4 teams, 5 servers, 4 databases hierarchically parented, 6 apps, 6 services, 3 network devices, 3 endpoints), and ~30 relationships across 6 verbs (depends on, connects to, managed by, hosted on, uses identity from, monitors).</p>
                <div class="module-footer">
                    <span class="record-count">~310 records</span>
                    <button class="import-btn" id="btn-cmdb" onclick="importModule('cmdb', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
                </div>
                <div class="error-text" id="err-cmdb" style="display:none"></div>
            </div>
        </div>

        <!-- Bonus: cross-module linking (appears after both software + assets imported) -->
        <div class="bonus-section" id="bonusSection">
            <p class="section-label"><?php echo htmlspecialchars(t('system.demo.step3_cross')); ?></p>
            <div class="bonus-card" id="bonus-software-assets">
                <div class="bonus-info">
                    <h4>Software Installed on Assets</h4>
                    <p>Links software applications to computers, showing which apps are installed on each device. Requires both Software and Assets to be imported first.</p>
                    <p class="bonus-detail">~55 installation records across 6 computers &bull; Realistic version numbers and install paths</p>
                </div>
                <button class="import-btn" id="btn-software-assets" onclick="importModule('software-assets', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
            </div>
            <div class="error-text" id="err-software-assets" style="display:none"></div>
        </div>

        <!-- Dashboards: appears after tickets imported -->
        <div class="bonus-section" id="dashboardsSection">
            <p class="section-label"><?php echo htmlspecialchars(t('system.demo.step3_dashboards')); ?></p>
            <div class="bonus-card" id="bonus-dashboards">
                <div class="bonus-info">
                    <h4>Dashboard Widgets</h4>
                    <p>Pre-built dashboard widgets and per-analyst layouts for the ticket dashboard. Requires Tickets to be imported first.</p>
                    <p class="bonus-detail">15 widgets &bull; 3 analyst dashboards with varied layouts</p>
                </div>
                <button class="import-btn" id="btn-dashboards" onclick="importModule('dashboards', this)" disabled><?php echo htmlspecialchars(t('system.demo.import')); ?></button>
            </div>
            <div class="error-text" id="err-dashboards" style="display:none"></div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script>
        let coreImported = false;
        let importedModules = {};

        const checkSvg = '<svg class="check-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

        async function importModule(module, btn) {
            if (btn.classList.contains('success')) {
                if (!(await showConfirm({ title: window.t('system.demo.delete_title'), message: window.t('system.demo.delete_confirm', { module: module }), okLabel: window.t('system.demo.delete_ok'), okClass: 'danger' }))) return;
            }

            btn.disabled = true;
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-inline"></span> ' + window.t('system.demo.importing');

            const errEl = document.getElementById('err-' + module);
            if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }

            try {
                const body = new FormData();
                body.append('module', module);

                const response = await fetch('../../api/system/import_demo_data.php', { method: 'POST', body: body });
                const data = await response.json();

                if (data.success) {
                    btn.className = btn.className.includes('import-btn-lg') ? 'import-btn import-btn-lg success' : 'import-btn success';
                    btn.innerHTML = checkSvg + ' ' + window.t('system.demo.imported_count', { total: data.total });
                    btn.disabled = false;
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
                if (errEl) { errEl.textContent = window.t('system.demo.connection_failed', { message: error.message }); errEl.style.display = 'block'; }
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }

        function enableModuleButtons() {
            const modules = ['tickets', 'assets', 'knowledge', 'changes', 'calendar', 'checks', 'contracts', 'services', 'software', 'forms', 'tasks', 'process-mapper', 'cmdb'];
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
            if (importedModules['tickets']) {
                var section = document.getElementById('dashboardsSection');
                section.style.display = 'block';
                var btn = document.getElementById('btn-dashboards');
                if (btn && !btn.classList.contains('success')) {
                    btn.disabled = false;
                }
            }
        }

        // On page load, check if core data and modules already exist
        (async function checkCoreStatus() {
            try {
                const response = await fetch('../../api/system/check_demo_core.php');
                const data = await response.json();
                if (data.exists) {
                    coreImported = true;
                    const btn = document.getElementById('btn-core');
                    btn.className = 'import-btn import-btn-lg success';
                    btn.innerHTML = checkSvg + ' ' + window.t('system.demo.already_imported');
                    enableModuleButtons();
                }
                // Track which modules have data so bonus sections appear
                if (data.modules) {
                    if (data.modules.software) importedModules['software'] = true;
                    if (data.modules.assets) importedModules['assets'] = true;
                    if (data.modules.tickets) importedModules['tickets'] = true;
                    if (data.modules['software-assets']) {
                        importedModules['software-assets'] = true;
                        var saBtn = document.getElementById('btn-software-assets');
                        if (saBtn) {
                            saBtn.className = 'import-btn success';
                            saBtn.innerHTML = checkSvg + ' ' + window.t('system.demo.already_imported');
                            saBtn.disabled = false;
                        }
                    }
                    if (data.modules.dashboards) {
                        importedModules['dashboards'] = true;
                        var dbBtn = document.getElementById('btn-dashboards');
                        if (dbBtn) {
                            dbBtn.className = 'import-btn success';
                            dbBtn.innerHTML = checkSvg + ' ' + window.t('system.demo.already_imported');
                            dbBtn.disabled = false;
                        }
                    }
                    checkBonusEligibility();
                }
            } catch (e) { /* ignore - user can still click Import */ }
        })();
    </script>
</body>
</html>
