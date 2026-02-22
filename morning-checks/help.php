<?php
/**
 * Morning Checks Help Guide - Full page with left pane navigation
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
    <title>Service Desk - Morning Checks Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .mc-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .mc-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .mc-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .mc-help-nav-link {
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

        .mc-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .mc-help-nav-link.active {
            background: #e0f7fa;
            color: #00838f;
            font-weight: 600;
        }

        .mc-help-nav-link.highlight {
            color: #00838f;
        }

        .mc-help-nav-link.highlight.active {
            background: #00838f;
            color: white;
        }

        .mc-help-nav-num {
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

        .mc-help-nav-link.active .mc-help-nav-num {
            background: #00838f;
            color: white;
        }

        .mc-help-nav-num.highlight {
            background: #e0f7fa;
            color: #00838f;
        }

        .mc-help-nav-link.highlight.active .mc-help-nav-num {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Main content */
        .mc-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .mc-help-hero {
            background: linear-gradient(135deg, #00acc1 0%, #00838f 50%, #005662 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .mc-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .mc-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .mc-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .mc-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .mc-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .mc-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .mc-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .mc-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .mc-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .mc-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e0f7fa;
            color: #00838f;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .mc-help-section-num.highlight {
            background: #00838f;
            color: white;
        }

        /* Feature cards grid */
        .mc-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .mc-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .mc-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .mc-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .mc-help-feature-icon.teal { background: #e0f7fa; color: #00acc1; }
        .mc-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .mc-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .mc-help-feature-icon.orange { background: #fff3e0; color: #e65100; }

        .mc-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .mc-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Numbered steps */
        .mc-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .mc-help-step-item {
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

        .mc-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #00acc1;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .mc-help-section-highlight {
            background: #e0f7fa;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #80deea;
        }

        .mc-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .mc-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .mc-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Status indicator cards */
        .mc-help-status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin: 14px 0;
        }

        .mc-help-status-card {
            padding: 16px;
            border-radius: 8px;
            border-left: 4px solid;
            background: white;
        }

        .mc-help-status-card.status-green { border-left-color: #28a745; }
        .mc-help-status-card.status-amber { border-left-color: #ffc107; }
        .mc-help-status-card.status-red { border-left-color: #dc3545; }

        .mc-help-status-card strong {
            display: block;
            font-size: 14px;
            color: #333;
            margin-bottom: 4px;
        }

        .mc-help-status-card span {
            font-size: 12.5px;
            color: #666;
            line-height: 1.4;
        }

        /* Tip callout */
        .mc-help-tip {
            font-size: 13px !important;
            color: #00838f !important;
            background: #e0f7fa;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #00acc1;
            margin-top: 10px;
        }

        /* Quick tips grid */
        .mc-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .mc-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .mc-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .mc-help-tip-card strong {
            color: #333;
        }

        /* Chart preview illustration */
        .mc-help-chart-preview {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin: 14px 0;
        }

        .mc-help-chart-bars {
            display: flex;
            align-items: flex-end;
            gap: 6px;
            height: 100px;
            padding: 0 10px;
        }

        .mc-help-chart-bar-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .mc-help-chart-bar {
            width: 100%;
            border-radius: 3px 3px 0 0;
            min-height: 4px;
        }

        .mc-help-chart-bar.green { background: #28a745; }
        .mc-help-chart-bar.amber { background: #ffc107; }
        .mc-help-chart-bar.red { background: #dc3545; }

        .mc-help-chart-label {
            font-size: 10px;
            color: #999;
            text-align: center;
        }

        .mc-help-chart-legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 12px;
            font-size: 12px;
            color: #666;
        }

        .mc-help-chart-legend span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .mc-help-chart-legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .mc-help-sidebar { display: none; }
            .mc-help-content { padding: 10px 24px 40px; }
            .mc-help-hero { padding: 30px 24px; }
            .mc-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .mc-help-features-grid { grid-template-columns: 1fr; }
            .mc-help-status-grid { grid-template-columns: 1fr; }
            .mc-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="mc-help-container">
        <!-- Left pane navigation -->
        <div class="mc-help-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="mc-help-nav-link active" data-section="overview">
                <span class="mc-help-nav-num">1</span>
                Overview
            </a>
            <a href="#daily-checks" class="mc-help-nav-link" data-section="daily-checks">
                <span class="mc-help-nav-num">2</span>
                Performing daily checks
            </a>
            <a href="#trend-chart" class="mc-help-nav-link highlight" data-section="trend-chart">
                <span class="mc-help-nav-num highlight">3</span>
                The trend chart
            </a>
            <a href="#pdf-export" class="mc-help-nav-link" data-section="pdf-export">
                <span class="mc-help-nav-num">4</span>
                PDF export
            </a>
            <a href="#settings" class="mc-help-nav-link highlight" data-section="settings">
                <span class="mc-help-nav-num highlight">5</span>
                Settings
            </a>
            <a href="#tips" class="mc-help-nav-link" data-section="tips">
                <span class="mc-help-nav-num">6</span>
                Quick tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="mc-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="mc-help-hero">
                <h2>Morning checks guide</h2>
                <p>A structured daily checklist to keep your IT operations running smoothly &mdash; every single morning.</p>
            </div>

            <div class="mc-help-content">

                <!-- Section 1: Overview -->
                <div class="mc-help-section" id="overview">
                    <div class="mc-help-section-header">
                        <span class="mc-help-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>Morning Checks provides a structured daily checklist for your IT operations team. Each morning, analysts work through a defined set of checks &mdash; verifying that critical systems are online, backups completed successfully, queues are clear, and services are healthy. Every check is recorded with a status, building a reliable audit trail and a 30-day trend view so you can spot recurring problems before they become incidents.</p>
                        </div>
                    </div>
                    <div class="mc-help-features-grid">
                        <div class="mc-help-feature-card">
                            <div class="mc-help-feature-icon teal">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <h4>Daily checklist</h4>
                            <p>A repeatable list of operational checks that resets each day. Work through every item, mark a status, and know nothing has been missed before the team starts taking calls.</p>
                        </div>
                        <div class="mc-help-feature-card">
                            <div class="mc-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4>Trend tracking</h4>
                            <p>A stacked bar chart shows the last 30 days at a glance. Quickly see whether your green rate is improving, or if a particular check keeps failing week after week.</p>
                        </div>
                        <div class="mc-help-feature-card">
                            <div class="mc-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                            </div>
                            <h4>PDF export</h4>
                            <p>Generate a professional PDF report for any day's checks. Ideal for management reviews, audit evidence, or handing off to the next shift with a clear record of what was checked.</p>
                        </div>
                        <div class="mc-help-feature-card">
                            <div class="mc-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c.26.604.852.997 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09c-.658.003-1.25.396-1.51 1z"></path></svg>
                            </div>
                            <h4>Configurable</h4>
                            <p>Define exactly which checks your team needs to perform. Add, edit, remove, and reorder check items from the Settings page to match your operational requirements.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Performing Daily Checks -->
                <div class="mc-help-section" id="daily-checks">
                    <div class="mc-help-section-header">
                        <span class="mc-help-section-num">2</span>
                        <h3>Performing daily checks</h3>
                    </div>
                    <p>The dashboard shows today's checklist by default. Each row in the table represents one check item with its name, description, status buttons, and a notes column. Work through the list from top to bottom at the start of each day.</p>

                    <div class="mc-help-steps">
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">1</div>
                            <div>
                                <strong>Open the dashboard</strong> &mdash; navigate to Morning Checks. Today's date is selected automatically and the checklist loads with all your configured check items.
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">2</div>
                            <div>
                                <strong>Perform each check</strong> &mdash; for every row, investigate the system or service described. Then click the appropriate status button: <strong>Green</strong>, <strong>Amber</strong>, or <strong>Red</strong>.
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">3</div>
                            <div>
                                <strong>Add notes for non-green statuses</strong> &mdash; when you select Amber or Red, a modal appears requiring you to enter notes explaining the issue. This is mandatory so there is always context for failed or degraded checks.
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">4</div>
                            <div>
                                <strong>Results save automatically</strong> &mdash; each status selection is saved to the database immediately. There is no separate save button; your progress is preserved as you go.
                            </div>
                        </div>
                    </div>

                    <p style="margin-top: 18px;">The three statuses represent different operational states:</p>

                    <div class="mc-help-status-grid">
                        <div class="mc-help-status-card status-green">
                            <strong>Green</strong>
                            <span>Everything is working as expected. The system or service is fully operational and no action is needed. No notes required.</span>
                        </div>
                        <div class="mc-help-status-card status-amber">
                            <strong>Amber</strong>
                            <span>Something needs attention but is not critically impacted. For example, a backup completed with warnings, or a queue is higher than usual. Notes are required to explain what was observed.</span>
                        </div>
                        <div class="mc-help-status-card status-red">
                            <strong>Red</strong>
                            <span>A failure or critical issue has been identified. The system or service is down, a backup has failed, or a major problem needs immediate attention. Notes are required to detail the issue and any actions taken.</span>
                        </div>
                    </div>

                    <p class="mc-help-tip">Use the date picker at the top of the dashboard to view or update checks for previous days. Click the "Today" button to jump back to the current date at any time.</p>
                </div>

                <!-- Section 3: The Trend Chart (highlighted) -->
                <div class="mc-help-section mc-help-section-highlight" id="trend-chart">
                    <div class="mc-help-section-header">
                        <span class="mc-help-section-num highlight">3</span>
                        <h3>The trend chart</h3>
                    </div>
                    <p class="mc-help-intro">At the bottom of the dashboard, a collapsible stacked bar chart shows the last 30 days of check results. Each bar represents one day, broken down into green, amber, and red segments so you can see overall operational health at a glance.</p>

                    <div class="mc-help-chart-preview">
                        <div class="mc-help-chart-bars">
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar red" style="height: 8px;"></div>
                                <div class="mc-help-chart-bar amber" style="height: 12px;"></div>
                                <div class="mc-help-chart-bar green" style="height: 60px;"></div>
                            </div>
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar amber" style="height: 6px;"></div>
                                <div class="mc-help-chart-bar green" style="height: 74px;"></div>
                            </div>
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar green" style="height: 80px;"></div>
                            </div>
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar red" style="height: 16px;"></div>
                                <div class="mc-help-chart-bar amber" style="height: 10px;"></div>
                                <div class="mc-help-chart-bar green" style="height: 54px;"></div>
                            </div>
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar amber" style="height: 8px;"></div>
                                <div class="mc-help-chart-bar green" style="height: 72px;"></div>
                            </div>
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar green" style="height: 80px;"></div>
                            </div>
                            <div class="mc-help-chart-bar-group">
                                <div class="mc-help-chart-bar red" style="height: 6px;"></div>
                                <div class="mc-help-chart-bar green" style="height: 74px;"></div>
                            </div>
                        </div>
                        <div class="mc-help-chart-legend">
                            <span><span class="mc-help-chart-legend-dot" style="background:#28a745;"></span> Green</span>
                            <span><span class="mc-help-chart-legend-dot" style="background:#ffc107;"></span> Amber</span>
                            <span><span class="mc-help-chart-legend-dot" style="background:#dc3545;"></span> Red</span>
                        </div>
                    </div>

                    <div class="mc-help-steps">
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">1</div>
                            <div>
                                <strong>Spot recurring failures</strong> &mdash; if the same day of the week regularly shows red or amber, it may indicate a scheduled job that consistently fails or a resource that is under strain at predictable times.
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">2</div>
                            <div>
                                <strong>Track improvement</strong> &mdash; after addressing a systemic issue, watch the chart over the following days and weeks to confirm the fix has held and the green rate is recovering.
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">3</div>
                            <div>
                                <strong>Click to navigate</strong> &mdash; click any bar in the chart to jump directly to that day's checks. The date picker updates and the checklist reloads, so you can review exactly what happened.
                            </div>
                        </div>
                    </div>

                    <p class="mc-help-tip">Click the chart header to collapse or expand the trend view. The chart automatically adjusts when you change the selected date, always showing the 30 days ending on the selected date.</p>
                </div>

                <!-- Section 4: PDF Export -->
                <div class="mc-help-section" id="pdf-export">
                    <div class="mc-help-section-header">
                        <span class="mc-help-section-num">4</span>
                        <h3>PDF export</h3>
                    </div>
                    <p>Generate a downloadable PDF report of any day's morning checks. This is useful for management reviews, compliance evidence, shift handover documentation, or simply keeping an offline record of your operational checks.</p>

                    <div class="mc-help-steps">
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">1</div>
                            <div>
                                <strong>Select the date</strong> &mdash; use the date picker to navigate to the day you want to export. The checklist will load with that day's saved results.
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">2</div>
                            <div>
                                <strong>Click "Save to PDF"</strong> &mdash; the button is located next to the date picker. The PDF is generated instantly in your browser and downloaded automatically.
                            </div>
                        </div>
                    </div>

                    <p>The exported PDF includes:</p>

                    <div class="mc-help-fields">
                        <div><strong>Company logo</strong> &mdash; your organisation's logo appears at the top of the report if one has been configured</div>
                        <div><strong>Date heading</strong> &mdash; the full date of the checks, matching what appears on the dashboard</div>
                        <div><strong>Results table</strong> &mdash; every check item with its name, description, status (colour-coded), and any notes recorded by the analyst</div>
                    </div>

                    <p class="mc-help-tip">The PDF file is named <strong>morning-checks-YYYY-MM-DD.pdf</strong> automatically, making it easy to organise downloaded reports by date in your file system.</p>
                </div>

                <!-- Section 5: Settings (highlighted) -->
                <div class="mc-help-section mc-help-section-highlight" id="settings">
                    <div class="mc-help-section-header">
                        <span class="mc-help-section-num highlight">5</span>
                        <h3>Settings</h3>
                    </div>
                    <p class="mc-help-intro">The Settings page is where you define the check items that appear on the daily checklist. Every team has different operational requirements, so Morning Checks is fully configurable. Add checks for backup verification, service health, queue monitoring, certificate expiry, or anything else your team needs to verify each morning.</p>

                    <div class="mc-help-steps">
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">1</div>
                            <div>
                                <strong>Add a check item</strong> &mdash; click the Add button to create a new check. Give it a clear, concise name (e.g. "Backup verification") and an optional description explaining what the analyst should look for.
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">2</div>
                            <div>
                                <strong>Edit existing checks</strong> &mdash; click the edit button on any check item to update its name or description. Changes take effect immediately on the dashboard for future checks.
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">3</div>
                            <div>
                                <strong>Reorder checks</strong> &mdash; drag and drop check items using the grip handle on the left side to arrange them in the order your team should work through them. The most critical checks should sit at the top.
                            </div>
                        </div>
                        <div class="mc-help-step-item">
                            <div class="mc-help-step-num">4</div>
                            <div>
                                <strong>Remove checks</strong> &mdash; delete a check item you no longer need. Historical data for that check is preserved, so previous day's results remain intact for reporting purposes.
                            </div>
                        </div>
                    </div>

                    <p class="mc-help-tip">Think of your check items as a runbook distilled into single-line verifications. If an analyst needs to check three separate backup systems, create three separate check items rather than one generic "Backups" entry. This gives you much better visibility in the trend chart.</p>
                </div>

                <!-- Section 6: Quick Tips -->
                <div class="mc-help-section" id="tips">
                    <div class="mc-help-section-header">
                        <span class="mc-help-section-num">6</span>
                        <h3>Quick tips</h3>
                    </div>
                    <div class="mc-help-tips-grid">
                        <div class="mc-help-tip-card">
                            <div class="mc-help-tip-icon">&#9200;</div>
                            <div><strong>Be consistent</strong><br>Perform morning checks at the same time every day, ideally before the team starts handling incidents. Consistency builds a reliable data set and ensures nothing is overlooked during busy mornings.</div>
                        </div>
                        <div class="mc-help-tip-card">
                            <div class="mc-help-tip-icon">&#128221;</div>
                            <div><strong>Write useful notes</strong><br>When marking a check as Amber or Red, include enough detail for someone unfamiliar with the issue to understand what happened. Mention error messages, ticket numbers, and any workarounds applied.</div>
                        </div>
                        <div class="mc-help-tip-card">
                            <div class="mc-help-tip-icon">&#128257;</div>
                            <div><strong>Use it for handovers</strong><br>Export the PDF at the end of your shift and share it with the incoming team. It gives them an instant snapshot of what passed, what failed, and what still needs attention.</div>
                        </div>
                        <div class="mc-help-tip-card">
                            <div class="mc-help-tip-icon">&#128200;</div>
                            <div><strong>Review the trend weekly</strong><br>Set aside time each week to review the 30-day trend chart. Look for patterns &mdash; checks that regularly go amber on Mondays, services that degrade towards month-end, or gradual decline in pass rates.</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.mc-help-nav-link');
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
