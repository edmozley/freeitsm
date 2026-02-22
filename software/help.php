<?php
/**
 * Software Help Guide - Full page with left pane navigation
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
    <title>Service Desk - Software Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .sw-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .sw-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .sw-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .sw-help-nav-link {
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

        .sw-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .sw-help-nav-link.active {
            background: #e8eaf6;
            color: #283593;
            font-weight: 600;
        }

        .sw-help-nav-link.highlight {
            color: #283593;
        }

        .sw-help-nav-link.highlight.active {
            background: #3f51b5;
            color: white;
        }

        .sw-help-nav-num {
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

        .sw-help-nav-link.active .sw-help-nav-num {
            background: #283593;
            color: white;
        }

        .sw-help-nav-num.highlight {
            background: #e8eaf6;
            color: #283593;
        }

        .sw-help-nav-link.highlight.active .sw-help-nav-num {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Main content */
        .sw-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .sw-help-hero {
            background: linear-gradient(135deg, #5c6bc0 0%, #3f51b5 50%, #283593 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .sw-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .sw-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .sw-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .sw-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .sw-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .sw-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .sw-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .sw-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .sw-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .sw-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e8eaf6;
            color: #283593;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .sw-help-section-num.highlight {
            background: #3f51b5;
            color: white;
        }

        /* Feature cards grid */
        .sw-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .sw-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .sw-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .sw-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .sw-help-feature-icon.indigo { background: #e8eaf6; color: #5c6bc0; }
        .sw-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .sw-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .sw-help-feature-icon.orange { background: #fff3e0; color: #e65100; }

        .sw-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .sw-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Numbered steps */
        .sw-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .sw-help-step-item {
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

        .sw-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #5c6bc0;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .sw-help-section-highlight {
            background: #e8eaf6;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #9fa8da;
        }

        .sw-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .sw-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .sw-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Flow diagram */
        .sw-help-flow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .sw-help-flow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .sw-help-flow-step.script { background: #e8eaf6; color: #283593; }
        .sw-help-flow-step.api { background: #fff3e0; color: #e65100; }
        .sw-help-flow-step.db { background: #e8f5e9; color: #2e7d32; }
        .sw-help-flow-step.ui { background: #e3f2fd; color: #1565c0; }

        .sw-help-flow-arrow {
            padding: 0 8px;
            color: #bbb;
            font-size: 18px;
        }

        /* Tip callout */
        .sw-help-tip {
            font-size: 13px !important;
            color: #283593 !important;
            background: #e8eaf6;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #5c6bc0;
            margin-top: 10px;
        }

        /* Data cards */
        .sw-help-data-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .sw-help-data-card {
            padding: 12px 14px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #5c6bc0;
        }

        .sw-help-data-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .sw-help-data-card span {
            font-size: 12px;
            color: #777;
            line-height: 1.4;
        }

        /* Quick tips grid */
        .sw-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .sw-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .sw-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .sw-help-tip-card strong {
            color: #333;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .sw-help-sidebar { display: none; }
            .sw-help-content { padding: 10px 24px 40px; }
            .sw-help-hero { padding: 30px 24px; }
            .sw-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .sw-help-features-grid { grid-template-columns: 1fr; }
            .sw-help-data-grid { grid-template-columns: 1fr; }
            .sw-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="sw-help-container">
        <!-- Left pane navigation -->
        <div class="sw-help-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="sw-help-nav-link active" data-section="overview">
                <span class="sw-help-nav-num">1</span>
                Overview
            </a>
            <a href="#inventory" class="sw-help-nav-link" data-section="inventory">
                <span class="sw-help-nav-num">2</span>
                Software inventory
            </a>
            <a href="#dashboard" class="sw-help-nav-link" data-section="dashboard">
                <span class="sw-help-nav-num">3</span>
                Dashboard
            </a>
            <a href="#licences" class="sw-help-nav-link" data-section="licences">
                <span class="sw-help-nav-num">4</span>
                Licence management
            </a>
            <a href="#data-collection" class="sw-help-nav-link highlight" data-section="data-collection">
                <span class="sw-help-nav-num highlight">5</span>
                How data gets collected
            </a>
            <a href="#settings" class="sw-help-nav-link" data-section="settings">
                <span class="sw-help-nav-num">6</span>
                Settings
            </a>
            <a href="#tips" class="sw-help-nav-link" data-section="tips">
                <span class="sw-help-nav-num">7</span>
                Quick tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="sw-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="sw-help-hero">
                <h2>Software management guide</h2>
                <p>Track every application across your estate &mdash; from inventory and version control to licence compliance.</p>
            </div>

            <div class="sw-help-content">

                <!-- Section 1: Overview -->
                <div class="sw-help-section" id="overview">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>The Software module gives you a centralised view of every application installed across all managed machines. Software data is collected automatically by the Asset Management PowerShell script, then aggregated here so you can search, analyse, and track licence compliance from a single screen. Whether you need to find every machine running an outdated version or verify you have enough licences for a critical application, this is where you start.</p>
                        </div>
                    </div>
                    <div class="sw-help-features-grid">
                        <div class="sw-help-feature-card">
                            <div class="sw-help-feature-icon indigo">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line></svg>
                            </div>
                            <h4>Inventory</h4>
                            <p>A complete list of every application discovered across your managed estate. See install counts, publishers, and version numbers at a glance, grouped by software name.</p>
                        </div>
                        <div class="sw-help-feature-card">
                            <div class="sw-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                            </div>
                            <h4>Dashboard</h4>
                            <p>Build customisable Chart.js widgets to visualise your software landscape &mdash; version distribution, top installed applications, publisher breakdowns, and more.</p>
                        </div>
                        <div class="sw-help-feature-card">
                            <div class="sw-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                            </div>
                            <h4>Licences</h4>
                            <p>Record licence entitlements against software titles and monitor compliance. Compare purchased licence counts with actual installs to spot over- or under-licensing.</p>
                        </div>
                        <div class="sw-help-feature-card">
                            <div class="sw-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </div>
                            <h4>Search</h4>
                            <p>Instantly filter the software list by name, publisher, or version. Click any row to expand and see exactly which machines have that application installed.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Software Inventory -->
                <div class="sw-help-section" id="inventory">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num">2</span>
                        <h3>Software inventory</h3>
                    </div>
                    <p>The main Software page presents every discovered application in a sortable, searchable table. Each row represents a unique software title, showing the publisher, the most common version, and the number of machines where it was found.</p>

                    <div class="sw-help-steps">
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">1</div>
                            <div>
                                <strong>Browse the list</strong> &mdash; the table loads all discovered software, sorted alphabetically by name. Use the column headers to sort by publisher, version, or install count instead.
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">2</div>
                            <div>
                                <strong>Search and filter</strong> &mdash; type in the search box at the top to instantly filter by software name or publisher. The results update as you type, making it easy to locate a specific application across hundreds of titles.
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">3</div>
                            <div>
                                <strong>Use the view tabs</strong> &mdash; switch between Applications (user-facing software visible in Add/Remove Programs) and Components (system-level entries) to reduce noise and focus on what matters.
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">4</div>
                            <div>
                                <strong>Expand for detail</strong> &mdash; click any software row to expand it and see a per-machine breakdown. You will see every machine that has the application installed, along with the specific version on each device and when it was last reported.
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">5</div>
                            <div>
                                <strong>Spot version sprawl</strong> &mdash; the expanded view makes it easy to identify machines running outdated versions. If ten machines have version 5.2 but two are still on 4.8, you can see that immediately and take action.
                            </div>
                        </div>
                    </div>

                    <p class="sw-help-tip">The install count reflects how many unique machines currently have the software installed. When a machine reports in without a previously seen application, the old record is automatically cleaned up.</p>
                </div>

                <!-- Section 3: Dashboard -->
                <div class="sw-help-section" id="dashboard">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num">3</span>
                        <h3>Dashboard</h3>
                    </div>
                    <p>The Software Dashboard lets you visualise your software landscape using customisable Chart.js widgets. Each analyst has their own dashboard layout, so you can focus on the charts and data points that matter most to your role.</p>

                    <div class="sw-help-steps">
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">1</div>
                            <div>
                                <strong>Open the Library</strong> &mdash; click Edit Dashboard, then browse the widget library. You can search existing widgets or create new ones from scratch. Each widget has a chart type, an aggregate property, and optional filters.
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">2</div>
                            <div>
                                <strong>Add widgets</strong> &mdash; click the + button on any widget in the library to add it to your dashboard. It appears immediately and starts rendering data.
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">3</div>
                            <div>
                                <strong>Customise</strong> &mdash; drag widgets to reorder them. Click the cog icon on any widget to change its title, chart type, date range, department filter, or time grouping. Changes save automatically.
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">4</div>
                            <div>
                                <strong>Click to drill down</strong> &mdash; click any segment of a chart (a bar, a pie slice, a doughnut section) to drill into the underlying data. This lets you go from a high-level view to the specific machines behind the numbers.
                            </div>
                        </div>
                    </div>

                    <p>Common widget types for the Software module include:</p>
                    <div class="sw-help-data-grid">
                        <div class="sw-help-data-card">
                            <strong>Version distribution</strong>
                            <span>See which versions of a given application are deployed across your estate, useful for patch compliance</span>
                        </div>
                        <div class="sw-help-data-card">
                            <strong>Top installed</strong>
                            <span>A ranked list of the most commonly installed applications by machine count</span>
                        </div>
                        <div class="sw-help-data-card">
                            <strong>Publisher breakdown</strong>
                            <span>Group all software by publisher to see which vendors have the largest footprint in your environment</span>
                        </div>
                    </div>

                    <p class="sw-help-tip">Widgets you create in the library are shared across the team. Any analyst can add them to their own dashboard, but each person's layout and selection is private.</p>
                </div>

                <!-- Section 4: Licence Management -->
                <div class="sw-help-section" id="licences">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num">4</span>
                        <h3>Licence management</h3>
                    </div>
                    <p>The Licences page lets you record and track your software licence entitlements alongside the live inventory data. By comparing how many licences you own with how many installs exist, you get an instant view of compliance across your estate.</p>

                    <div class="sw-help-steps">
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">1</div>
                            <div>
                                <strong>Add a licence record</strong> &mdash; click Add and enter the software name, publisher, licence type (per-device, per-user, site, subscription), the number of licences purchased, and any relevant dates such as expiry or renewal.
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">2</div>
                            <div>
                                <strong>Monitor compliance</strong> &mdash; the licence list shows each title alongside its purchased count and the actual number of installs detected across your estate. A colour-coded status indicator highlights where you are compliant, approaching the limit, or over-licensed.
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">3</div>
                            <div>
                                <strong>Track key dates</strong> &mdash; licence records can include purchase dates, expiry dates, and renewal dates. This gives you early warning when a licence agreement is approaching renewal so you can plan procurement in advance.
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">4</div>
                            <div>
                                <strong>Search and filter</strong> &mdash; use the search box to quickly find a specific licence record by software name or publisher. The list is sortable by any column.
                            </div>
                        </div>
                    </div>

                    <div class="sw-help-fields">
                        <div><strong>Compliant</strong> &mdash; the number of installs is at or below the purchased licence count. No action needed.</div>
                        <div><strong>Approaching limit</strong> &mdash; installs are within 90% of the licence count. Consider purchasing additional licences soon.</div>
                        <div><strong>Over-licensed</strong> &mdash; more installs detected than licences purchased. This is a compliance risk that should be addressed promptly.</div>
                    </div>

                    <p class="sw-help-tip">Licence compliance is calculated in real time against the live software inventory. As machines report in and software changes, the compliance status updates automatically.</p>
                </div>

                <!-- Section 5: How Data Gets Collected (highlighted) -->
                <div class="sw-help-section sw-help-section-highlight" id="data-collection">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num highlight">5</span>
                        <h3>How data gets collected</h3>
                    </div>
                    <p class="sw-help-intro">The Software module does not collect data on its own. Instead, it relies on the Asset Management PowerShell script (<strong>Invoke-AssetInventory.ps1</strong>) which runs on each managed Windows machine and reports installed software as part of the broader hardware and software inventory.</p>

                    <p>When the PowerShell script runs on a machine, it reads the Add/Remove Programs registry entries and collects every installed application and system component. This data is posted to the FreeITSM API, where it is stored against the asset record. The Software module then aggregates this data across all machines to give you the estate-wide view.</p>

                    <div class="sw-help-flow">
                        <div class="sw-help-flow-step script">PowerShell script</div>
                        <div class="sw-help-flow-arrow">&rarr;</div>
                        <div class="sw-help-flow-step api">system-info API</div>
                        <div class="sw-help-flow-arrow">&rarr;</div>
                        <div class="sw-help-flow-step db">Database</div>
                        <div class="sw-help-flow-arrow">&rarr;</div>
                        <div class="sw-help-flow-step ui">Software module</div>
                    </div>

                    <p>For each application, the script collects:</p>
                    <div class="sw-help-data-grid">
                        <div class="sw-help-data-card">
                            <strong>Name</strong>
                            <span>The display name of the application as shown in Add/Remove Programs</span>
                        </div>
                        <div class="sw-help-data-card">
                            <strong>Publisher</strong>
                            <span>The software vendor or developer, read from the registry entry</span>
                        </div>
                        <div class="sw-help-data-card">
                            <strong>Version</strong>
                            <span>The installed version string, essential for tracking patch levels and updates</span>
                        </div>
                    </div>

                    <p class="sw-help-tip">To deploy the inventory script across your estate, see the <a href="../asset-management/help.php" style="color: #283593; font-weight: 600;">Asset Management guide</a> for full deployment instructions including Group Policy scheduling, API key setup, and running at scale.</p>
                </div>

                <!-- Section 6: Settings -->
                <div class="sw-help-section" id="settings">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num">6</span>
                        <h3>Settings</h3>
                    </div>
                    <p>The Settings page lets you configure how the Software module behaves. From here you can manage API keys that authenticate the inventory script and control which software entries appear in the main list.</p>

                    <div class="sw-help-steps">
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">1</div>
                            <div>
                                <strong>API key management</strong> &mdash; generate and manage API keys that authenticate the PowerShell inventory script against your FreeITSM instance. You can create multiple keys, deactivate them without deleting, and track when each key was last used.
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">2</div>
                            <div>
                                <strong>Ignored software</strong> &mdash; some system components or unwanted entries clutter the software list. Use the ignore feature to hide specific titles from the inventory view. Ignored items are still collected and stored, but they are hidden from the main list and dashboard calculations.
                            </div>
                        </div>
                        <div class="sw-help-step-item">
                            <div class="sw-help-step-num">3</div>
                            <div>
                                <strong>Software categories</strong> &mdash; organise your software titles into categories (e.g. Productivity, Security, Development) to make browsing and reporting easier. Categories can be assigned to software titles and used as filters throughout the module.
                            </div>
                        </div>
                    </div>

                    <p class="sw-help-tip">When you ignore a software title, it applies globally. All analysts will see the same filtered view. You can always un-ignore a title later if you change your mind.</p>
                </div>

                <!-- Section 7: Quick Tips -->
                <div class="sw-help-section" id="tips">
                    <div class="sw-help-section-header">
                        <span class="sw-help-section-num">7</span>
                        <h3>Quick tips</h3>
                    </div>
                    <div class="sw-help-tips-grid">
                        <div class="sw-help-tip-card">
                            <div class="sw-help-tip-icon">&#128269;</div>
                            <div><strong>Search smart</strong><br>The search box filters across both software name and publisher simultaneously. If you are looking for all Microsoft products, just type "Microsoft" to see every title from that publisher.</div>
                        </div>
                        <div class="sw-help-tip-card">
                            <div class="sw-help-tip-icon">&#128200;</div>
                            <div><strong>Version sprawl</strong><br>Expand any software row to see per-machine version details. If you spot machines on older versions, you can target them for updates rather than rolling out patches blindly across the estate.</div>
                        </div>
                        <div class="sw-help-tip-card">
                            <div class="sw-help-tip-icon">&#128274;</div>
                            <div><strong>Licence vigilance</strong><br>Set up licence records for your most critical commercial software early. The compliance indicators will warn you before you exceed your entitlement, helping you avoid audit surprises.</div>
                        </div>
                        <div class="sw-help-tip-card">
                            <div class="sw-help-tip-icon">&#128203;</div>
                            <div><strong>Clean up clutter</strong><br>Windows machines report hundreds of system components that you probably do not care about. Use the Applications tab to focus on user-facing software, or use Settings to ignore noisy entries entirely.</div>
                        </div>
                        <div class="sw-help-tip-card">
                            <div class="sw-help-tip-icon">&#128187;</div>
                            <div><strong>Dashboard drill-down</strong><br>Do not just look at charts &mdash; click into them. Every chart segment is interactive. Clicking a bar or pie slice takes you to the machines behind that data point, so you can investigate further.</div>
                        </div>
                        <div class="sw-help-tip-card">
                            <div class="sw-help-tip-icon">&#9889;</div>
                            <div><strong>Automatic updates</strong><br>Software data refreshes every time the PowerShell script runs on a machine. Set the scheduled task to run daily and your inventory will always reflect the current state of your estate.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.sw-help-nav-link');
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
