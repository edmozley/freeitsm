<?php
/**
 * Asset Management Help Guide - Full page with left pane navigation
 */
session_start();
require_once '../config.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'help';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Asset Management Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .am-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .am-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .am-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .am-help-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }

        .am-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .am-help-nav-link.active {
            background: #e8f5e9;
            color: #1b5e20;
            font-weight: 600;
        }

        .am-help-nav-link.highlight {
            color: #1b5e20;
        }

        .am-help-nav-link.highlight.active {
            background: #2e7d32;
            color: white;
        }

        .am-help-nav-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #eee;
            color: #888;
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }

        .am-help-nav-link.active .am-help-nav-num {
            background: #2e7d32;
            color: white;
        }

        .am-help-nav-num.highlight {
            background: #e8f5e9;
            color: #1b5e20;
        }

        .am-help-nav-link.highlight.active .am-help-nav-num {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Main content */
        .am-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .am-help-hero {
            background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 50%, #0d3b0f 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .am-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .am-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .am-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .am-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .am-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .am-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .am-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .am-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .am-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .am-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e8f5e9;
            color: #1b5e20;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .am-help-section-num.highlight {
            background: #2e7d32;
            color: white;
        }

        /* Feature cards grid */
        .am-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .am-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .am-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .am-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .am-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .am-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .am-help-feature-icon.orange { background: #fff3e0; color: #e65100; }
        .am-help-feature-icon.purple { background: #f3e5f5; color: #7b1fa2; }
        .am-help-feature-icon.teal { background: #e0f2f1; color: #00695c; }
        .am-help-feature-icon.red { background: #fce4ec; color: #c62828; }

        .am-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .am-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Numbered steps */
        .am-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .am-help-step-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            background: #fafafa;
            font-size: 14px;
            color: #444;
            line-height: 1.5;
        }

        .am-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #2e7d32;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .am-help-section-highlight {
            background: #f1f8e9;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #c5e1a5;
        }

        .am-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Code blocks */
        .am-help-code {
            background: #263238;
            color: #eeffff;
            border-radius: 8px;
            padding: 16px 20px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            line-height: 1.6;
            overflow-x: auto;
            margin: 14px 0;
        }

        .am-help-code .comment { color: #546e7a; }
        .am-help-code .param { color: #82aaff; }
        .am-help-code .string { color: #c3e88d; }
        .am-help-code .flag { color: #ffcb6b; }

        /* Data cards (what gets collected) */
        .am-help-data-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .am-help-data-card {
            padding: 12px 14px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #2e7d32;
        }

        .am-help-data-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .am-help-data-card span {
            font-size: 12px;
            color: #777;
            line-height: 1.4;
        }

        .am-help-data-card.optional {
            border-left-color: #ff8f00;
        }

        /* Flow diagram */
        .am-help-flow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .am-help-flow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .am-help-flow-step.script { background: #e3f2fd; color: #1565c0; }
        .am-help-flow-step.api { background: #fff3e0; color: #e65100; }
        .am-help-flow-step.db { background: #e8f5e9; color: #2e7d32; }
        .am-help-flow-step.ui { background: #f3e5f5; color: #7b1fa2; }

        .am-help-flow-arrow {
            padding: 0 8px;
            color: #bbb;
            font-size: 18px;
        }

        /* Info fields list */
        .am-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .am-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Tip callout */
        .am-help-tip {
            font-size: 13px !important;
            color: #1b5e20 !important;
            background: #e8f5e9;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #2e7d32;
            margin-top: 10px;
        }

        /* Quick tips grid */
        .am-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .am-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .am-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .am-help-tip-card strong {
            color: #333;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .am-help-sidebar { display: none; }
            .am-help-content { padding: 10px 24px 40px; }
            .am-help-hero { padding: 30px 24px; }
            .am-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .am-help-features-grid { grid-template-columns: 1fr; }
            .am-help-data-grid { grid-template-columns: 1fr; }
            .am-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="am-help-container">
        <!-- Left pane navigation -->
        <div class="am-help-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="am-help-nav-link active" data-section="overview">
                <span class="am-help-nav-num">1</span>
                Overview
            </a>
            <a href="#asset-detail" class="am-help-nav-link" data-section="asset-detail">
                <span class="am-help-nav-num">2</span>
                Asset detail view
            </a>
            <a href="#inventory-script" class="am-help-nav-link highlight" data-section="inventory-script">
                <span class="am-help-nav-num highlight">3</span>
                Inventory script
            </a>
            <a href="#what-gets-collected" class="am-help-nav-link" data-section="what-gets-collected">
                <span class="am-help-nav-num">4</span>
                What gets collected
            </a>
            <a href="#deployment" class="am-help-nav-link highlight" data-section="deployment">
                <span class="am-help-nav-num highlight">5</span>
                Deploying at scale
            </a>
            <a href="#servers" class="am-help-nav-link" data-section="servers">
                <span class="am-help-nav-num">6</span>
                Servers &amp; vCenter
            </a>
            <a href="#dashboard" class="am-help-nav-link" data-section="dashboard">
                <span class="am-help-nav-num">7</span>
                Dashboard
            </a>
            <a href="#tips" class="am-help-nav-link" data-section="tips">
                <span class="am-help-nav-num">8</span>
                Quick tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="am-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="am-help-hero">
                <h2>Asset management guide</h2>
                <p>Discover, track, and manage every device in your estate &mdash; from laptops to servers.</p>
            </div>

            <div class="am-help-content">

                <!-- Section 1: Overview -->
                <div class="am-help-section" id="overview">
                    <div class="am-help-section-header">
                        <span class="am-help-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>Asset Management gives you a complete inventory of your IT estate. Windows machines report in automatically using a PowerShell script, and VMware virtual machines sync from vCenter. Everything lands in one place where you can search, assign users, and build dashboards.</p>
                        </div>
                    </div>
                    <div class="am-help-features-grid">
                        <div class="am-help-feature-card">
                            <div class="am-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                            </div>
                            <h4>Assets</h4>
                            <p>Browse and search every discovered machine. Click any asset to see its hardware, storage, devices, and installed software at a glance.</p>
                        </div>
                        <div class="am-help-feature-card">
                            <div class="am-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                            </div>
                            <h4>Dashboard</h4>
                            <p>Build your own dashboard from 13+ chart types &mdash; OS distribution, manufacturer breakdown, memory tiers, and more. Each analyst has their own layout.</p>
                        </div>
                        <div class="am-help-feature-card">
                            <div class="am-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg>
                            </div>
                            <h4>Servers</h4>
                            <p>Connect to VMware vCenter and sync your entire virtual estate. View VM details, host placement, storage, and network configuration.</p>
                        </div>
                        <div class="am-help-feature-card">
                            <div class="am-help-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </div>
                            <h4>User assignment</h4>
                            <p>Assign users to assets so you always know who has which device. Reassignments are logged in the audit trail.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Asset Detail View -->
                <div class="am-help-section" id="asset-detail">
                    <div class="am-help-section-header">
                        <span class="am-help-section-num">2</span>
                        <h3>Asset detail view</h3>
                    </div>
                    <p>Click any asset in the list to open its detail panel. The view is split into sections:</p>
                    <div class="am-help-fields">
                        <div><strong>Header</strong> &mdash; Hostname, service tag, assigned user, and a View History button for the full audit trail</div>
                        <div><strong>Info grid</strong> &mdash; Type, status, manufacturer, model, CPU, memory, operating system, feature release, build number, and BIOS</div>
                        <div><strong>Storage</strong> &mdash; Drive cards showing capacity, usage percentage with colour-coded bars (green &lt; 75%, amber 75&ndash;90%, red &gt; 90%), and file system</div>
                        <div><strong>Devices tab</strong> &mdash; Every device from Windows Device Manager, grouped by category (Display adapters, Network adapters, etc.) with driver info and status badges. Use the search box to filter.</div>
                        <div><strong>Software tab</strong> &mdash; All installed applications and system components with publisher and version. Toggle between Applications, Components, and All.</div>
                    </div>
                    <p class="am-help-tip">Type and Status are editable inline &mdash; click the dropdown in the info grid to change them. Changes are recorded in the history.</p>
                </div>

                <!-- Section 3: Inventory Script (highlighted) -->
                <div class="am-help-section am-help-section-highlight" id="inventory-script">
                    <div class="am-help-section-header">
                        <span class="am-help-section-num highlight">3</span>
                        <h3>The inventory script</h3>
                    </div>
                    <p class="am-help-intro">Assets are discovered automatically using a PowerShell script that runs on each Windows machine. It collects hardware, software, and device information, then posts it to your FreeITSM instance via the API.</p>

                    <p>The script is located at <strong>scripts/Invoke-AssetInventory.ps1</strong> in your FreeITSM installation. It takes two parameters:</p>

                    <div class="am-help-code">
                        <span class="comment"># Basic usage &mdash; post inventory to FreeITSM</span><br>
                        .\Invoke-AssetInventory.ps1 <span class="flag">-ApiUrl</span> <span class="string">"https://itsm.yourcompany.com"</span> <span class="flag">-ApiKey</span> <span class="string">"your-api-key"</span><br><br>
                        <span class="comment"># Save to a file (useful for testing)</span><br>
                        .\Invoke-AssetInventory.ps1 <span class="flag">-OutputFile</span> <span class="string">"C:\Temp\asset.json"</span><br><br>
                        <span class="comment"># Both &mdash; post to API and save a local copy</span><br>
                        .\Invoke-AssetInventory.ps1 <span class="flag">-ApiUrl</span> <span class="string">"https://itsm.yourcompany.com"</span> <span class="flag">-ApiKey</span> <span class="string">"your-api-key"</span> <span class="flag">-OutputFile</span> <span class="string">"C:\Temp\asset.json"</span>
                    </div>

                    <div class="am-help-flow">
                        <div class="am-help-flow-step script">PowerShell script</div>
                        <div class="am-help-flow-arrow">&rarr;</div>
                        <div class="am-help-flow-step api">system-info API</div>
                        <div class="am-help-flow-arrow">&rarr;</div>
                        <div class="am-help-flow-step api">device-manager API</div>
                        <div class="am-help-flow-arrow">&rarr;</div>
                        <div class="am-help-flow-step db">Database</div>
                        <div class="am-help-flow-arrow">&rarr;</div>
                        <div class="am-help-flow-step ui">Asset detail</div>
                    </div>

                    <p class="am-help-tip">The script makes two API calls: one to <strong>/api/external/system-info/submit/</strong> (hardware, disks, network, software) and one to <strong>/api/external/device-manager/submit/</strong> (Device Manager data). Both are authenticated using the same API key.</p>
                </div>

                <!-- Section 4: What Gets Collected -->
                <div class="am-help-section" id="what-gets-collected">
                    <div class="am-help-section-header">
                        <span class="am-help-section-num">4</span>
                        <h3>What gets collected</h3>
                    </div>
                    <p>The PowerShell script gathers everything you'd want to know about a Windows machine in a single run:</p>
                    <div class="am-help-data-grid">
                        <div class="am-help-data-card">
                            <strong>System</strong>
                            <span>Hostname, manufacturer, model, service tag, domain, logged-in user</span>
                        </div>
                        <div class="am-help-data-card">
                            <strong>CPU &amp; Memory</strong>
                            <span>Processor name, clock speed, total physical memory</span>
                        </div>
                        <div class="am-help-data-card">
                            <strong>Operating System</strong>
                            <span>OS name, feature release (e.g. 24H2), build number</span>
                        </div>
                        <div class="am-help-data-card">
                            <strong>Storage</strong>
                            <span>Logical drives with capacity, free space, file system, and percentage used</span>
                        </div>
                        <div class="am-help-data-card">
                            <strong>Network</strong>
                            <span>Adapter name, MAC address, IP, subnet, gateway, DHCP status</span>
                        </div>
                        <div class="am-help-data-card">
                            <strong>GPU</strong>
                            <span>Graphics card name, driver version, VRAM, resolution</span>
                        </div>
                        <div class="am-help-data-card">
                            <strong>Device Manager</strong>
                            <span>All present devices grouped by category, with driver manufacturer, version, and date</span>
                        </div>
                        <div class="am-help-data-card">
                            <strong>Installed Software</strong>
                            <span>Every application and component from Add/Remove Programs, with version and publisher</span>
                        </div>
                        <div class="am-help-data-card optional">
                            <strong>BIOS &amp; Boot</strong>
                            <span>BIOS version, last boot time, uptime</span>
                        </div>
                        <div class="am-help-data-card optional">
                            <strong>TPM</strong>
                            <span>Version, manufacturer, enabled/activated status (requires admin)</span>
                        </div>
                        <div class="am-help-data-card optional">
                            <strong>BitLocker</strong>
                            <span>Protection status, encryption method per volume (requires admin)</span>
                        </div>
                        <div class="am-help-data-card optional">
                            <strong>Uptime</strong>
                            <span>Last boot time (UTC) and uptime in days</span>
                        </div>
                    </div>
                    <p class="am-help-tip">Items with an amber border require running the script as Administrator. Everything else works under a standard user account.</p>
                </div>

                <!-- Section 5: Deploying at Scale (highlighted) -->
                <div class="am-help-section am-help-section-highlight" id="deployment">
                    <div class="am-help-section-header">
                        <span class="am-help-section-num highlight">5</span>
                        <h3>Deploying at scale</h3>
                    </div>
                    <p class="am-help-intro">Running the script manually on one machine is fine for testing. In production, you'll want it running automatically across your entire estate.</p>

                    <div class="am-help-steps">
                        <div class="am-help-step-item">
                            <div class="am-help-step-num">1</div>
                            <div>
                                <strong>Get your API key</strong> &mdash; go to Admin &gt; API Keys and generate a key. This authenticates the script against FreeITSM.
                            </div>
                        </div>
                        <div class="am-help-step-item">
                            <div class="am-help-step-num">2</div>
                            <div>
                                <strong>Copy the script</strong> &mdash; place <strong>Invoke-AssetInventory.ps1</strong> on a network share (e.g. <code>\\server\scripts$\</code>) so all machines can reach it.
                            </div>
                        </div>
                        <div class="am-help-step-item">
                            <div class="am-help-step-num">3</div>
                            <div>
                                <strong>Create a scheduled task</strong> &mdash; use Group Policy Preferences or your endpoint management tool to schedule the script. A daily or weekly run keeps things fresh.
                            </div>
                        </div>
                        <div class="am-help-step-item">
                            <div class="am-help-step-num">4</div>
                            <div>
                                <strong>Set execution policy</strong> &mdash; the scheduled task command should use:<br>
                                <code>powershell.exe -ExecutionPolicy Bypass -File "\\server\scripts$\Invoke-AssetInventory.ps1" -ApiUrl "https://itsm.yourcompany.com" -ApiKey "your-key"</code>
                            </div>
                        </div>
                        <div class="am-help-step-item">
                            <div class="am-help-step-num">5</div>
                            <div>
                                <strong>Run as SYSTEM</strong> &mdash; for full data (TPM, BitLocker), run the scheduled task as <strong>NT AUTHORITY\SYSTEM</strong> with highest privileges. Otherwise, standard user works for the core inventory.
                            </div>
                        </div>
                    </div>

                    <div class="am-help-code">
                        <span class="comment"># Example: Group Policy scheduled task action</span><br>
                        <span class="param">Program:</span> <span class="string">powershell.exe</span><br>
                        <span class="param">Arguments:</span> <span class="string">-ExecutionPolicy Bypass -File "\\fileserver\scripts$\Invoke-AssetInventory.ps1" -ApiUrl "https://itsm.yourcompany.com" -ApiKey "abc123"</span><br>
                        <span class="param">Run as:</span> <span class="string">NT AUTHORITY\SYSTEM</span><br>
                        <span class="param">Schedule:</span> <span class="string">Daily at 12:00</span>
                    </div>

                    <p class="am-help-tip">Each run is idempotent &mdash; the API creates the asset on first contact and updates it on every subsequent run. Software that's been uninstalled is automatically removed from the inventory.</p>
                </div>

                <!-- Section 6: Servers & vCenter -->
                <div class="am-help-section" id="servers">
                    <div class="am-help-section-header">
                        <span class="am-help-section-num">6</span>
                        <h3>Servers &amp; vCenter</h3>
                    </div>
                    <p>If you run VMware vCenter, FreeITSM can sync your entire virtual machine estate with a single click.</p>
                    <div class="am-help-steps">
                        <div class="am-help-step-item">
                            <div class="am-help-step-num">1</div>
                            <div>
                                <strong>Configure credentials</strong> &mdash; go to Settings &gt; vCenter tab and enter the server hostname, username, and password.
                            </div>
                        </div>
                        <div class="am-help-step-item">
                            <div class="am-help-step-num">2</div>
                            <div>
                                <strong>Click Sync vCenter</strong> &mdash; on the Servers tab, click the sync button. FreeITSM connects to vCenter's REST API and imports all VMs.
                            </div>
                        </div>
                        <div class="am-help-step-item">
                            <div class="am-help-step-num">3</div>
                            <div>
                                <strong>Browse the results</strong> &mdash; the Servers tab shows summary cards (total VMs, vCPU, memory, storage) and a searchable, sortable table of every VM. Click any row to see the full detail &mdash; disks, NICs, guest OS, VMware Tools, and the raw JSON from vCenter.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 7: Dashboard -->
                <div class="am-help-section" id="dashboard">
                    <div class="am-help-section-header">
                        <span class="am-help-section-num">7</span>
                        <h3>Dashboard</h3>
                    </div>
                    <p>The dashboard lets you visualise your asset estate with customisable Chart.js widgets. Each analyst has their own dashboard &mdash; choose the charts that matter to you.</p>
                    <div class="am-help-steps">
                        <div class="am-help-step-item">
                            <div class="am-help-step-num">1</div>
                            <div>
                                <strong>Open the Library</strong> &mdash; click Edit Dashboard, then browse or create widgets. Each widget has a chart type (bar, pie, doughnut) and an aggregate property.
                            </div>
                        </div>
                        <div class="am-help-step-item">
                            <div class="am-help-step-num">2</div>
                            <div>
                                <strong>Add to your dashboard</strong> &mdash; click the + button to add any widget. It appears on your dashboard immediately.
                            </div>
                        </div>
                        <div class="am-help-step-item">
                            <div class="am-help-step-num">3</div>
                            <div>
                                <strong>Customise</strong> &mdash; drag widgets to reorder. Click the cog icon to change the title, chart type, or apply date range and department filters.
                            </div>
                        </div>
                    </div>
                    <p>Available chart properties include: <strong>Operating System</strong>, <strong>Manufacturer</strong>, <strong>Model</strong>, <strong>Asset Type</strong>, <strong>Asset Status</strong>, <strong>Feature Release</strong>, <strong>Domain</strong>, <strong>CPU</strong>, <strong>Memory</strong>, <strong>GPU</strong>, <strong>TPM Version</strong>, <strong>BitLocker Status</strong>, and <strong>BIOS Version</strong>.</p>
                </div>

                <!-- Section 8: Quick Tips -->
                <div class="am-help-section" id="tips">
                    <div class="am-help-section-header">
                        <span class="am-help-section-num">8</span>
                        <h3>Quick tips</h3>
                    </div>
                    <div class="am-help-tips-grid">
                        <div class="am-help-tip-card">
                            <div class="am-help-tip-icon">&#128269;</div>
                            <div><strong>Search</strong><br>The asset list search checks hostnames. On the Servers tab, it also searches IP, host, cluster, and guest OS.</div>
                        </div>
                        <div class="am-help-tip-card">
                            <div class="am-help-tip-icon">&#128203;</div>
                            <div><strong>History</strong><br>Click "View History" on any asset to see every change &mdash; who updated a field, what the old and new values were, and when.</div>
                        </div>
                        <div class="am-help-tip-card">
                            <div class="am-help-tip-icon">&#128274;</div>
                            <div><strong>API keys</strong><br>API keys authenticate the PowerShell script. Generate them in Admin &gt; API Keys. You can deactivate a key at any time without deleting it.</div>
                        </div>
                        <div class="am-help-tip-card">
                            <div class="am-help-tip-icon">&#128187;</div>
                            <div><strong>Device Manager</strong><br>The Devices tab shows exactly what Windows Device Manager shows. Use the filter box to quickly find a specific driver or device class.</div>
                        </div>
                        <div class="am-help-tip-card">
                            <div class="am-help-tip-icon">&#128230;</div>
                            <div><strong>Software tabs</strong><br>Applications are what users see in Add/Remove Programs. Components are hidden system entries. Toggle between them to reduce noise.</div>
                        </div>
                        <div class="am-help-tip-card">
                            <div class="am-help-tip-icon">&#9889;</div>
                            <div><strong>Idempotent syncs</strong><br>Run the script as many times as you like. It creates assets on first contact and updates them on every subsequent run. Removed software is automatically cleaned up.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.am-help-nav-link');
        const sections = [];

        navLinks.forEach(link => {
            const id = link.dataset.section;
            const el = document.getElementById(id);
            if (el) sections.push({ id, el });
        });

        helpMain.addEventListener('scroll', function() {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0]?.id;

            for (const s of sections) {
                if (s.el.offsetTop - 200 <= scrollTop) {
                    current = s.id;
                }
            }

            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
        });

        // Scroll within the help container, not the page
        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const el = document.getElementById(this.dataset.section);
                if (el) {
                    const containerTop = helpMain.getBoundingClientRect().top;
                    const elTop = el.getBoundingClientRect().top;
                    helpMain.scrollTo({ top: helpMain.scrollTop + (elTop - containerTop) - 20, behavior: 'smooth' });
                }
                navLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>
