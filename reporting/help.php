<?php
/**
 * Reporting Help Guide - Full page with left pane navigation
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
    <title>Service Desk - Reporting Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .rp-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .rp-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .rp-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .rp-help-nav-link {
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

        .rp-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .rp-help-nav-link.active {
            background: #fbe9e7;
            color: #a5410a;
            font-weight: 600;
        }

        .rp-help-nav-link.highlight {
            color: #a5410a;
        }

        .rp-help-nav-link.highlight.active {
            background: #a5410a;
            color: white;
        }

        .rp-help-nav-num {
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

        .rp-help-nav-link.active .rp-help-nav-num {
            background: #a5410a;
            color: white;
        }

        .rp-help-nav-num.highlight {
            background: #fbe9e7;
            color: #a5410a;
        }

        .rp-help-nav-link.highlight.active .rp-help-nav-num {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Main content */
        .rp-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .rp-help-hero {
            background: linear-gradient(135deg, #ca5010 0%, #a5410a 50%, #7a2e06 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .rp-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .rp-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .rp-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .rp-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .rp-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .rp-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .rp-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .rp-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .rp-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .rp-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #fbe9e7;
            color: #a5410a;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .rp-help-section-num.highlight {
            background: #a5410a;
            color: white;
        }

        /* Feature cards grid */
        .rp-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .rp-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .rp-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .rp-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .rp-help-feature-icon.rust { background: #fbe9e7; color: #ca5010; }
        .rp-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .rp-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .rp-help-feature-icon.purple { background: #f3e5f5; color: #7b1fa2; }

        .rp-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .rp-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Numbered steps */
        .rp-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .rp-help-step-item {
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

        .rp-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #ca5010;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .rp-help-section-highlight {
            background: #fbe9e7;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #ffab91;
        }

        .rp-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .rp-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .rp-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Data cards */
        .rp-help-data-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .rp-help-data-card {
            padding: 12px 14px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #ca5010;
        }

        .rp-help-data-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .rp-help-data-card span {
            font-size: 12px;
            color: #777;
            line-height: 1.4;
        }

        /* Metric cards for understanding data */
        .rp-help-metric-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin: 14px 0;
        }

        .rp-help-metric-card {
            padding: 16px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .rp-help-metric-card h4 {
            margin: 0 0 6px;
            font-size: 14px;
            color: #333;
        }

        .rp-help-metric-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Tip callout */
        .rp-help-tip {
            font-size: 13px !important;
            color: #a5410a !important;
            background: #fbe9e7;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #ca5010;
            margin-top: 10px;
        }

        /* Quick tips grid */
        .rp-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .rp-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .rp-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .rp-help-tip-card strong {
            color: #333;
        }

        /* Log type badges */
        .rp-help-log-types {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 14px 0;
        }

        .rp-help-log-type {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .rp-help-log-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .rp-help-log-badge.login { background: #e3f2fd; color: #1565c0; }
        .rp-help-log-badge.email { background: #fbe9e7; color: #ca5010; }
        .rp-help-log-badge.system { background: #e8f5e9; color: #2e7d32; }
        .rp-help-log-badge.audit { background: #f3e5f5; color: #7b1fa2; }

        .rp-help-log-type div {
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .rp-help-log-type strong {
            color: #333;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .rp-help-sidebar { display: none; }
            .rp-help-content { padding: 10px 24px 40px; }
            .rp-help-hero { padding: 30px 24px; }
            .rp-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .rp-help-features-grid { grid-template-columns: 1fr; }
            .rp-help-data-grid { grid-template-columns: 1fr; }
            .rp-help-metric-grid { grid-template-columns: 1fr; }
            .rp-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="rp-help-container">
        <!-- Left pane navigation -->
        <div class="rp-help-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="rp-help-nav-link active" data-section="overview">
                <span class="rp-help-nav-num">1</span>
                Overview
            </a>
            <a href="#ticket-reports" class="rp-help-nav-link" data-section="ticket-reports">
                <span class="rp-help-nav-num">2</span>
                Ticket reports
            </a>
            <a href="#system-logs" class="rp-help-nav-link" data-section="system-logs">
                <span class="rp-help-nav-num">3</span>
                System logs
            </a>
            <a href="#understanding-data" class="rp-help-nav-link highlight" data-section="understanding-data">
                <span class="rp-help-nav-num highlight">4</span>
                Understanding the data
            </a>
            <a href="#settings-filters" class="rp-help-nav-link" data-section="settings-filters">
                <span class="rp-help-nav-num">5</span>
                Settings &amp; filters
            </a>
            <a href="#tips" class="rp-help-nav-link" data-section="tips">
                <span class="rp-help-nav-num">6</span>
                Quick tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="rp-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="rp-help-hero">
                <h2>Reporting guide</h2>
                <p>Turn your service desk data into actionable insights with logs, analytics, and dashboards.</p>
            </div>

            <div class="rp-help-content">

                <!-- Section 1: Overview -->
                <div class="rp-help-section" id="overview">
                    <div class="rp-help-section-header">
                        <span class="rp-help-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>The Reporting module brings together everything happening across your service desk into one place. Track ticket performance, monitor system activity, review login attempts, and audit email imports &mdash; all from a single module designed to help you spot trends and make data-driven decisions.</p>
                        </div>
                    </div>
                    <div class="rp-help-features-grid">
                        <div class="rp-help-feature-card">
                            <div class="rp-help-feature-icon rust">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                            </div>
                            <h4>Ticket analytics</h4>
                            <p>Visualise ticket volume, resolution times, SLA compliance, and team workload through interactive dashboards that update in real time.</p>
                        </div>
                        <div class="rp-help-feature-card">
                            <div class="rp-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            </div>
                            <h4>System logs</h4>
                            <p>Review every login attempt, email import, and system event in a searchable, filterable table with timestamps and status indicators.</p>
                        </div>
                        <div class="rp-help-feature-card">
                            <div class="rp-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4>Activity tracking</h4>
                            <p>Monitor analyst activity across the platform &mdash; who is logging in, what tickets are being worked, and where time is being spent.</p>
                        </div>
                        <div class="rp-help-feature-card">
                            <div class="rp-help-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                            </div>
                            <h4>Audit trail</h4>
                            <p>Every action is recorded with who did it, when, and what changed. Essential for compliance, security reviews, and troubleshooting.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Ticket Reports -->
                <div class="rp-help-section" id="ticket-reports">
                    <div class="rp-help-section-header">
                        <span class="rp-help-section-num">2</span>
                        <h3>Ticket reports</h3>
                    </div>
                    <p>The Tickets area of reporting provides KPI dashboards that give you a clear picture of how your service desk is performing. These dashboards pull data directly from your ticket records and present it through charts and summary cards.</p>

                    <div class="rp-help-data-grid">
                        <div class="rp-help-data-card">
                            <strong>Ticket volume</strong>
                            <span>See how many tickets are created, resolved, and still open over any time period. Identify busy days and seasonal patterns.</span>
                        </div>
                        <div class="rp-help-data-card">
                            <strong>SLA compliance</strong>
                            <span>Track what percentage of tickets meet their response and resolution targets. Drill down by priority or category to find problem areas.</span>
                        </div>
                        <div class="rp-help-data-card">
                            <strong>Resolution times</strong>
                            <span>Measure average and median time to resolve tickets. Compare across teams, categories, or priority levels to spot bottlenecks.</span>
                        </div>
                        <div class="rp-help-data-card">
                            <strong>Team workload</strong>
                            <span>See how tickets are distributed across analysts. Identify who is overloaded and who has capacity to take on more work.</span>
                        </div>
                        <div class="rp-help-data-card">
                            <strong>Category breakdown</strong>
                            <span>Understand which types of issues generate the most tickets. Use this to target training, documentation, or self-service improvements.</span>
                        </div>
                        <div class="rp-help-data-card">
                            <strong>Trend analysis</strong>
                            <span>View ticket data over weeks, months, or quarters to spot long-term trends and measure the impact of process improvements.</span>
                        </div>
                    </div>

                    <p class="rp-help-tip">Ticket dashboards are accessed via the Tickets tab in the header navigation. Use date range filters to compare different periods side by side.</p>
                </div>

                <!-- Section 3: System Logs -->
                <div class="rp-help-section" id="system-logs">
                    <div class="rp-help-section-header">
                        <span class="rp-help-section-num">3</span>
                        <h3>System logs</h3>
                    </div>
                    <p>The Logs area captures everything happening behind the scenes in your FreeITSM instance. Every login attempt, email import, and system event is recorded with a timestamp and status so you always have a complete picture of platform activity.</p>

                    <div class="rp-help-log-types">
                        <div class="rp-help-log-type">
                            <span class="rp-help-log-badge login">LOGIN</span>
                            <div>
                                <strong>Login attempts</strong> &mdash; Every successful and failed login is recorded with the analyst name, IP address, and timestamp. Failed attempts are flagged in red so you can quickly spot unauthorised access attempts or locked-out users.
                            </div>
                        </div>
                        <div class="rp-help-log-type">
                            <span class="rp-help-log-badge email">EMAIL</span>
                            <div>
                                <strong>Email imports</strong> &mdash; When the system processes incoming emails into tickets, each import is logged with the sender address, subject line, and whether it was successfully converted. Failed imports show the reason so you can investigate bounced or malformed messages.
                            </div>
                        </div>
                        <div class="rp-help-log-type">
                            <span class="rp-help-log-badge system">SYSTEM</span>
                            <div>
                                <strong>System events</strong> &mdash; Background processes, scheduled tasks, configuration changes, and API activity are all captured here. Use these logs to verify that automated jobs are running correctly and to diagnose issues.
                            </div>
                        </div>
                        <div class="rp-help-log-type">
                            <span class="rp-help-log-badge audit">AUDIT</span>
                            <div>
                                <strong>Audit entries</strong> &mdash; Field-level change tracking across the platform. See exactly who changed what, when, and what the previous value was. Invaluable for compliance requirements and resolving disputes.
                            </div>
                        </div>
                    </div>

                    <div class="rp-help-steps">
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">1</div>
                            <div>
                                <strong>Open the Logs tab</strong> &mdash; click Logs in the header navigation to access the system log viewer.
                            </div>
                        </div>
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">2</div>
                            <div>
                                <strong>Switch between log types</strong> &mdash; use the tab bar at the top to filter by login attempts, email imports, or system events.
                            </div>
                        </div>
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">3</div>
                            <div>
                                <strong>Review the details</strong> &mdash; each row shows a timestamp, status badge (success or failed), and contextual details like IP addresses, email subjects, or event descriptions.
                            </div>
                        </div>
                    </div>

                    <p class="rp-help-tip">Check login logs regularly for repeated failed attempts from unfamiliar IP addresses. This can indicate brute-force attacks or compromised credentials that need immediate attention.</p>
                </div>

                <!-- Section 4: Understanding the Data (highlighted) -->
                <div class="rp-help-section rp-help-section-highlight" id="understanding-data">
                    <div class="rp-help-section-header">
                        <span class="rp-help-section-num highlight">4</span>
                        <h3>Understanding the data</h3>
                    </div>
                    <p class="rp-help-intro">Raw data only becomes useful when you know what to look for. Here are the key metrics to watch and how to interpret them to drive real improvements in your service desk operations.</p>

                    <div class="rp-help-metric-grid">
                        <div class="rp-help-metric-card">
                            <h4>First response time</h4>
                            <p>How long users wait before an analyst acknowledges their ticket. A rising trend here means your team may be understaffed or tickets are not being routed effectively. Target: under your SLA threshold.</p>
                        </div>
                        <div class="rp-help-metric-card">
                            <h4>Resolution rate</h4>
                            <p>The percentage of tickets resolved within a given period versus those created. If more tickets come in than go out, your backlog is growing and you need to investigate the cause.</p>
                        </div>
                        <div class="rp-help-metric-card">
                            <h4>Repeat contacts</h4>
                            <p>Tickets reopened or users raising the same issue multiple times. High repeat contact rates suggest the root cause is not being addressed, or that solutions are not clearly communicated.</p>
                        </div>
                        <div class="rp-help-metric-card">
                            <h4>Category hotspots</h4>
                            <p>Which categories generate the most tickets over time. A spike in a particular category can signal a failing system, a bad software update, or a gap in user training that needs addressing.</p>
                        </div>
                    </div>

                    <p>Use these metrics together rather than in isolation. For example, a high resolution rate combined with a high repeat contact rate may indicate that tickets are being closed too quickly without solving the underlying problem.</p>

                    <p class="rp-help-tip">Schedule a weekly review of your key metrics with the team. Patterns that are invisible day-to-day often become obvious when viewed on a weekly or monthly cadence.</p>
                </div>

                <!-- Section 5: Settings & Filters -->
                <div class="rp-help-section" id="settings-filters">
                    <div class="rp-help-section-header">
                        <span class="rp-help-section-num">5</span>
                        <h3>Settings &amp; filters</h3>
                    </div>
                    <p>Both the log viewer and ticket dashboards support a range of filters to help you narrow down exactly the data you need. Effective use of filters turns a wall of data into targeted, actionable information.</p>

                    <div class="rp-help-steps">
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">1</div>
                            <div>
                                <strong>Date ranges</strong> &mdash; filter logs and reports to a specific time window. Use preset ranges (today, this week, this month) or set custom start and end dates for precise control.
                            </div>
                        </div>
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">2</div>
                            <div>
                                <strong>Status filters</strong> &mdash; in the log viewer, filter by success or failure status to quickly isolate problems. In ticket reports, filter by open, resolved, or closed status.
                            </div>
                        </div>
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">3</div>
                            <div>
                                <strong>Search</strong> &mdash; use the search box to find specific entries by keyword. In logs, this searches across analyst names, IP addresses, email subjects, and event descriptions.
                            </div>
                        </div>
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">4</div>
                            <div>
                                <strong>Time grouping</strong> &mdash; in ticket dashboards, group data by day, week, or month to change the granularity of your charts. Daily views show short-term spikes; monthly views reveal long-term trends.
                            </div>
                        </div>
                        <div class="rp-help-step-item">
                            <div class="rp-help-step-num">5</div>
                            <div>
                                <strong>Department filters</strong> &mdash; narrow dashboard results to a specific department to compare performance across different parts of the organisation.
                            </div>
                        </div>
                    </div>

                    <p class="rp-help-tip">Combine multiple filters for targeted analysis. For example, filter by a specific department and a date range to see how a recent process change affected that team's ticket volume.</p>
                </div>

                <!-- Section 6: Quick Tips -->
                <div class="rp-help-section" id="tips">
                    <div class="rp-help-section-header">
                        <span class="rp-help-section-num">6</span>
                        <h3>Quick tips</h3>
                    </div>
                    <div class="rp-help-tips-grid">
                        <div class="rp-help-tip-card">
                            <div class="rp-help-tip-icon">&#128202;</div>
                            <div><strong>Review regularly</strong><br>Reports are most valuable when reviewed consistently. Set a cadence &mdash; weekly for operational metrics, monthly for trend analysis &mdash; and stick to it.</div>
                        </div>
                        <div class="rp-help-tip-card">
                            <div class="rp-help-tip-icon">&#128269;</div>
                            <div><strong>Investigate anomalies</strong><br>A sudden spike or drop in any metric is a signal worth investigating. Check the logs for context &mdash; was there a system outage, a software rollout, or a staffing change?</div>
                        </div>
                        <div class="rp-help-tip-card">
                            <div class="rp-help-tip-icon">&#128200;</div>
                            <div><strong>Compare periods</strong><br>Use date filters to compare this week against last week, or this month against the same month last year. Relative comparisons reveal improvement or regression more clearly than raw numbers.</div>
                        </div>
                        <div class="rp-help-tip-card">
                            <div class="rp-help-tip-icon">&#128274;</div>
                            <div><strong>Monitor security</strong><br>Keep an eye on failed login attempts in the system logs. Repeated failures from the same IP address or against the same account may indicate a security concern that needs escalation.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.rp-help-nav-link');
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
