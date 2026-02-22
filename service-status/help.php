<?php
/**
 * Service Status Help Guide - Full page with left pane navigation
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
    <title>Service Desk - Service Status Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .ss-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .ss-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .ss-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .ss-help-nav-link {
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

        .ss-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .ss-help-nav-link.active {
            background: #ecfdf5;
            color: #065f46;
            font-weight: 600;
        }

        .ss-help-nav-link.highlight {
            color: #065f46;
        }

        .ss-help-nav-link.highlight.active {
            background: #059669;
            color: white;
        }

        .ss-help-nav-num {
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

        .ss-help-nav-link.active .ss-help-nav-num {
            background: #059669;
            color: white;
        }

        .ss-help-nav-num.highlight {
            background: #ecfdf5;
            color: #065f46;
        }

        .ss-help-nav-link.highlight.active .ss-help-nav-num {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Main content */
        .ss-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .ss-help-hero {
            background: linear-gradient(135deg, #10b981 0%, #059669 50%, #047857 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .ss-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .ss-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .ss-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .ss-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .ss-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .ss-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .ss-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .ss-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .ss-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .ss-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #ecfdf5;
            color: #065f46;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .ss-help-section-num.highlight {
            background: #059669;
            color: white;
        }

        /* Feature cards grid */
        .ss-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .ss-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .ss-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .ss-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .ss-help-feature-icon.emerald { background: #ecfdf5; color: #10b981; }
        .ss-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .ss-help-feature-icon.orange { background: #fff3e0; color: #e65100; }
        .ss-help-feature-icon.red { background: #fce4ec; color: #c62828; }

        .ss-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .ss-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Status level cards */
        .ss-help-status-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .ss-help-status-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 8px;
            background: white;
            border: 1px solid #e0e0e0;
        }

        .ss-help-status-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .ss-help-status-dot.operational { background: #10b981; }
        .ss-help-status-dot.degraded { background: #f59e0b; }
        .ss-help-status-dot.maintenance { background: #3b82f6; }
        .ss-help-status-dot.major-outage { background: #ef4444; }

        .ss-help-status-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 2px;
        }

        .ss-help-status-card span {
            font-size: 12px;
            color: #777;
            line-height: 1.4;
        }

        /* Numbered steps */
        .ss-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .ss-help-step-item {
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

        .ss-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #10b981;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .ss-help-section-highlight {
            background: #ecfdf5;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #6ee7b7;
        }

        .ss-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .ss-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .ss-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Incident flow diagram */
        .ss-help-flow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .ss-help-flow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .ss-help-flow-step.investigating { background: #fff7ed; color: #c2410c; }
        .ss-help-flow-step.identified { background: #e0e7ff; color: #3730a3; }
        .ss-help-flow-step.monitoring { background: #dbeafe; color: #1e40af; }
        .ss-help-flow-step.resolved { background: #d1fae5; color: #065f46; }

        .ss-help-flow-arrow {
            padding: 0 8px;
            color: #bbb;
            font-size: 18px;
        }

        /* Tip callout */
        .ss-help-tip {
            font-size: 13px !important;
            color: #065f46 !important;
            background: #ecfdf5;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #10b981;
            margin-top: 10px;
        }

        /* Quick tips grid */
        .ss-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .ss-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .ss-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .ss-help-tip-card strong {
            color: #333;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .ss-help-sidebar { display: none; }
            .ss-help-content { padding: 10px 24px 40px; }
            .ss-help-hero { padding: 30px 24px; }
            .ss-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .ss-help-features-grid { grid-template-columns: 1fr; }
            .ss-help-status-grid { grid-template-columns: 1fr; }
            .ss-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="ss-help-container">
        <!-- Left pane navigation -->
        <div class="ss-help-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="ss-help-nav-link active" data-section="overview">
                <span class="ss-help-nav-num">1</span>
                Overview
            </a>
            <a href="#status-dashboard" class="ss-help-nav-link" data-section="status-dashboard">
                <span class="ss-help-nav-num">2</span>
                The status dashboard
            </a>
            <a href="#managing-services" class="ss-help-nav-link highlight" data-section="managing-services">
                <span class="ss-help-nav-num highlight">3</span>
                Managing services
            </a>
            <a href="#incident-history" class="ss-help-nav-link" data-section="incident-history">
                <span class="ss-help-nav-num">4</span>
                Incident history
            </a>
            <a href="#settings" class="ss-help-nav-link" data-section="settings">
                <span class="ss-help-nav-num">5</span>
                Settings
            </a>
            <a href="#tips" class="ss-help-nav-link" data-section="tips">
                <span class="ss-help-nav-num">6</span>
                Quick tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="ss-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="ss-help-hero">
                <h2>Service status guide</h2>
                <p>Monitor your IT services, communicate incidents, and keep stakeholders informed in real time.</p>
            </div>

            <div class="ss-help-content">

                <!-- Section 1: Overview -->
                <div class="ss-help-section" id="overview">
                    <div class="ss-help-section-header">
                        <span class="ss-help-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>The Service Status module gives you a centralised view of the health of every IT service your organisation relies on. When something goes wrong, you can record incidents, update affected services, and keep users informed throughout the resolution process.</p>
                        </div>
                    </div>
                    <div class="ss-help-features-grid">
                        <div class="ss-help-feature-card">
                            <div class="ss-help-feature-icon emerald">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4>Status dashboard</h4>
                            <p>See the current health of every service at a glance. Colour-coded badges show whether each service is operational, degraded, under maintenance, or experiencing an outage.</p>
                        </div>
                        <div class="ss-help-feature-card">
                            <div class="ss-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            </div>
                            <h4>Incident tracking</h4>
                            <p>Record incidents with titles, status updates, and comments. Link affected services to each incident so everyone knows exactly what is impacted and why.</p>
                        </div>
                        <div class="ss-help-feature-card">
                            <div class="ss-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            </div>
                            <h4>Service management</h4>
                            <p>Configure your service catalogue in settings. Add services with names, descriptions, and display order. Activate or deactivate services as your infrastructure evolves.</p>
                        </div>
                        <div class="ss-help-feature-card">
                            <div class="ss-help-feature-icon red">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                            </div>
                            <h4>Communication</h4>
                            <p>Keep stakeholders informed with real-time status updates. Each incident carries a status and comment trail so users can follow the resolution progress without chasing the service desk.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: The Status Dashboard -->
                <div class="ss-help-section" id="status-dashboard">
                    <div class="ss-help-section-header">
                        <span class="ss-help-section-num">2</span>
                        <h3>The status dashboard</h3>
                    </div>
                    <p>The dashboard is the first thing you see when you open the Service Status module. It displays a grid of service cards, each showing the service name, a short description, and a colour-coded impact badge reflecting its current worst status. Below the grid sits the incidents table listing all recent and active incidents.</p>
                    <p>Each service card automatically reflects the most severe impact level assigned to it from any active (unresolved) incident. When all incidents affecting a service are resolved, it returns to <strong>Operational</strong>.</p>

                    <p style="margin-top: 18px; margin-bottom: 10px; font-weight: 600; color: #333;">Status levels</p>
                    <div class="ss-help-status-grid">
                        <div class="ss-help-status-card">
                            <div class="ss-help-status-dot operational"></div>
                            <div>
                                <strong>Operational</strong>
                                <span>The service is running normally with no known issues. This is the default state for all healthy services.</span>
                            </div>
                        </div>
                        <div class="ss-help-status-card">
                            <div class="ss-help-status-dot degraded"></div>
                            <div>
                                <strong>Degraded Performance</strong>
                                <span>The service is available but running slower than expected or with reduced functionality. Users may notice delays.</span>
                            </div>
                        </div>
                        <div class="ss-help-status-card">
                            <div class="ss-help-status-dot maintenance"></div>
                            <div>
                                <strong>Under Maintenance</strong>
                                <span>Planned downtime or maintenance window. The service may be temporarily unavailable while work is carried out.</span>
                            </div>
                        </div>
                        <div class="ss-help-status-card">
                            <div class="ss-help-status-dot major-outage"></div>
                            <div>
                                <strong>Major Outage</strong>
                                <span>The service is completely unavailable. This is the most severe status and should trigger immediate investigation.</span>
                            </div>
                        </div>
                    </div>
                    <p class="ss-help-tip">Impact levels are hierarchical. If a service is linked to multiple active incidents, the dashboard shows the worst impact. For example, one incident marking a service as Degraded and another marking it as Major Outage will result in Major Outage being displayed.</p>
                </div>

                <!-- Section 3: Managing Services (highlighted) -->
                <div class="ss-help-section ss-help-section-highlight" id="managing-services">
                    <div class="ss-help-section-header">
                        <span class="ss-help-section-num highlight">3</span>
                        <h3>Managing services &amp; recording incidents</h3>
                    </div>
                    <p class="ss-help-intro">Services are the building blocks of your status page. Each one represents an IT service, system, or infrastructure component that your users depend on. When something goes wrong, you create an incident and link it to the affected services.</p>

                    <p style="font-weight: 600; color: #333; margin-bottom: 10px;">Adding a new incident</p>
                    <div class="ss-help-steps">
                        <div class="ss-help-step-item">
                            <div class="ss-help-step-num">1</div>
                            <div>
                                <strong>Click "New"</strong> on the dashboard to open the incident form.
                            </div>
                        </div>
                        <div class="ss-help-step-item">
                            <div class="ss-help-step-num">2</div>
                            <div>
                                <strong>Enter a title</strong> &mdash; a brief, clear description of the issue. For example: "Email delivery delays" or "VPN gateway unreachable".
                            </div>
                        </div>
                        <div class="ss-help-step-item">
                            <div class="ss-help-step-num">3</div>
                            <div>
                                <strong>Set the status</strong> &mdash; choose Investigating, Identified, 3rd Party, Monitoring, or Resolved. Start with Investigating and update as you learn more.
                            </div>
                        </div>
                        <div class="ss-help-step-item">
                            <div class="ss-help-step-num">4</div>
                            <div>
                                <strong>Add a comment</strong> &mdash; describe what is known so far, what actions are being taken, and any workarounds available to users.
                            </div>
                        </div>
                        <div class="ss-help-step-item">
                            <div class="ss-help-step-num">5</div>
                            <div>
                                <strong>Link affected services</strong> &mdash; add one or more services and choose the impact level for each (Major Outage, Partial Outage, Degraded, Maintenance, Operational, or No Disruption).
                            </div>
                        </div>
                        <div class="ss-help-step-item">
                            <div class="ss-help-step-num">6</div>
                            <div>
                                <strong>Save</strong> &mdash; the incident appears in the table and affected service cards update immediately on the dashboard.
                            </div>
                        </div>
                    </div>

                    <p style="font-weight: 600; color: #333; margin: 20px 0 10px;">Incident status workflow</p>
                    <div class="ss-help-flow">
                        <div class="ss-help-flow-step investigating">Investigating</div>
                        <div class="ss-help-flow-arrow">&rarr;</div>
                        <div class="ss-help-flow-step identified">Identified</div>
                        <div class="ss-help-flow-arrow">&rarr;</div>
                        <div class="ss-help-flow-step monitoring">Monitoring</div>
                        <div class="ss-help-flow-arrow">&rarr;</div>
                        <div class="ss-help-flow-step resolved">Resolved</div>
                    </div>
                    <p style="font-size: 13px; color: #555; text-align: center; margin-top: 4px;">Use <strong>3rd Party</strong> when the root cause lies with an external vendor or provider.</p>

                    <p class="ss-help-tip">You can edit any incident by clicking its title in the table. Update the status, add new comments, or change affected services as the situation evolves. Keeping incidents updated is key to transparent communication.</p>
                </div>

                <!-- Section 4: Incident History -->
                <div class="ss-help-section" id="incident-history">
                    <div class="ss-help-section-header">
                        <span class="ss-help-section-num">4</span>
                        <h3>Incident history</h3>
                    </div>
                    <p>The incidents table on the dashboard shows both active and resolved incidents, giving you a complete timeline of service health. Each row displays the incident title, current status, affected services with their impact levels, and the last updated timestamp.</p>
                    <div class="ss-help-fields">
                        <div><strong>Title</strong> &mdash; a clickable link that opens the incident for editing. Use clear, descriptive titles so the history is easy to scan.</div>
                        <div><strong>Status</strong> &mdash; colour-coded badge showing the current investigation phase (Investigating, Identified, 3rd Party, Monitoring, or Resolved).</div>
                        <div><strong>Affected services</strong> &mdash; tagged badges showing each linked service with its impact level colour. At a glance you can see what is impacted and how severely.</div>
                        <div><strong>Updated</strong> &mdash; the timestamp of the most recent change. Resolved incidents are styled with muted text so active incidents stand out visually.</div>
                    </div>
                    <p>Resolved incidents remain visible in the table as a historical record. This makes it easy to spot recurring issues, review how past incidents were handled, and identify patterns that might point to underlying problems.</p>
                    <p class="ss-help-tip">Regularly reviewing your incident history helps you identify services that are frequently disrupted. If the same service appears in multiple incidents, it may be time to investigate the root cause more deeply or plan an infrastructure upgrade.</p>
                </div>

                <!-- Section 5: Settings -->
                <div class="ss-help-section" id="settings">
                    <div class="ss-help-section-header">
                        <span class="ss-help-section-num">5</span>
                        <h3>Settings</h3>
                    </div>
                    <p>The Settings page is where you build and maintain your service catalogue. Every service that appears on the status dashboard must first be configured here.</p>
                    <div class="ss-help-steps">
                        <div class="ss-help-step-item">
                            <div class="ss-help-step-num">1</div>
                            <div>
                                <strong>Add a service</strong> &mdash; click "Add" and provide a name (e.g. "Email", "VPN", "ERP System") and an optional description explaining what the service does.
                            </div>
                        </div>
                        <div class="ss-help-step-item">
                            <div class="ss-help-step-num">2</div>
                            <div>
                                <strong>Set the display order</strong> &mdash; the order number controls where the service appears on the dashboard grid. Lower numbers appear first, so put your most critical services at the top.
                            </div>
                        </div>
                        <div class="ss-help-step-item">
                            <div class="ss-help-step-num">3</div>
                            <div>
                                <strong>Toggle active/inactive</strong> &mdash; deactivating a service removes it from the dashboard without deleting it. This is useful for decommissioned services or seasonal systems.
                            </div>
                        </div>
                        <div class="ss-help-step-item">
                            <div class="ss-help-step-num">4</div>
                            <div>
                                <strong>Edit or delete</strong> &mdash; use the action buttons on each row to update service details or remove a service entirely. Editing is always preferred over deleting so that historical incident links remain intact.
                            </div>
                        </div>
                    </div>
                    <p class="ss-help-tip">Think of your service catalogue as the foundation of your status page. Spend time getting the names and descriptions right &mdash; these are what your users and stakeholders will see when they check the health of your IT environment.</p>
                </div>

                <!-- Section 6: Quick Tips -->
                <div class="ss-help-section" id="tips">
                    <div class="ss-help-section-header">
                        <span class="ss-help-section-num">6</span>
                        <h3>Quick tips</h3>
                    </div>
                    <div class="ss-help-tips-grid">
                        <div class="ss-help-tip-card">
                            <div class="ss-help-tip-icon">&#128226;</div>
                            <div><strong>Communicate early</strong><br>Post an incident as soon as you know something is wrong, even if you don't have all the details yet. Acknowledging an issue quickly builds trust with your users.</div>
                        </div>
                        <div class="ss-help-tip-card">
                            <div class="ss-help-tip-icon">&#128260;</div>
                            <div><strong>Update frequently</strong><br>Regular status updates &mdash; even if nothing has changed &mdash; show users that the issue is being actively worked on. Silence breeds frustration and support tickets.</div>
                        </div>
                        <div class="ss-help-tip-card">
                            <div class="ss-help-tip-icon">&#128200;</div>
                            <div><strong>Review patterns</strong><br>Check your incident history regularly. If the same service keeps appearing, it might point to a deeper infrastructure issue worth addressing proactively.</div>
                        </div>
                        <div class="ss-help-tip-card">
                            <div class="ss-help-tip-icon">&#128736;</div>
                            <div><strong>Plan maintenance</strong><br>Use the Maintenance impact level for planned work. Creating an incident in advance lets users know about scheduled downtime before it happens.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.ss-help-nav-link');
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
