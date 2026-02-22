<?php
/**
 * Tickets Module Help Guide - Full page with left pane navigation
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
    <title>Service Desk - Tickets Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .tk-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .tk-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .tk-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .tk-help-nav-link {
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

        .tk-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .tk-help-nav-link.active {
            background: #e3f2fd;
            color: #005a9e;
            font-weight: 600;
        }

        .tk-help-nav-link.highlight {
            color: #005a9e;
        }

        .tk-help-nav-link.highlight.active {
            background: #0078d4;
            color: white;
        }

        .tk-help-nav-num {
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

        .tk-help-nav-link.active .tk-help-nav-num {
            background: #0078d4;
            color: white;
        }

        .tk-help-nav-num.highlight {
            background: #e3f2fd;
            color: #005a9e;
        }

        .tk-help-nav-link.highlight.active .tk-help-nav-num {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Main content */
        .tk-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .tk-help-hero {
            background: linear-gradient(135deg, #0078d4 0%, #005a9e 50%, #003d6b 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .tk-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .tk-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .tk-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .tk-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .tk-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .tk-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .tk-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .tk-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .tk-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .tk-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e3f2fd;
            color: #005a9e;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .tk-help-section-num.highlight {
            background: #0078d4;
            color: white;
        }

        /* Feature cards grid */
        .tk-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .tk-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .tk-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .tk-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .tk-help-feature-icon.blue { background: #e3f2fd; color: #0078d4; }
        .tk-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .tk-help-feature-icon.orange { background: #fff3e0; color: #e65100; }
        .tk-help-feature-icon.purple { background: #f3e5f5; color: #7b1fa2; }
        .tk-help-feature-icon.teal { background: #e0f2f1; color: #00695c; }
        .tk-help-feature-icon.red { background: #fce4ec; color: #c62828; }

        .tk-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .tk-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Numbered steps */
        .tk-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .tk-help-step-item {
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

        .tk-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #0078d4;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .tk-help-section-highlight {
            background: #e3f2fd;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #90caf9;
        }

        .tk-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .tk-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .tk-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Data cards grid */
        .tk-help-data-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .tk-help-data-card {
            padding: 12px 14px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #0078d4;
        }

        .tk-help-data-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .tk-help-data-card span {
            font-size: 12px;
            color: #777;
            line-height: 1.4;
        }

        /* Flow diagram */
        .tk-help-flow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .tk-help-flow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .tk-help-flow-step.inbox { background: #e3f2fd; color: #0078d4; }
        .tk-help-flow-step.action { background: #fff3e0; color: #e65100; }
        .tk-help-flow-step.resolve { background: #e8f5e9; color: #2e7d32; }
        .tk-help-flow-step.closed { background: #f3e5f5; color: #7b1fa2; }

        .tk-help-flow-arrow {
            padding: 0 8px;
            color: #bbb;
            font-size: 18px;
        }

        /* Tip callout */
        .tk-help-tip {
            font-size: 13px !important;
            color: #005a9e !important;
            background: #e3f2fd;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #0078d4;
            margin-top: 10px;
        }

        /* Quick tips grid */
        .tk-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .tk-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .tk-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .tk-help-tip-card strong {
            color: #333;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .tk-help-sidebar { display: none; }
            .tk-help-content { padding: 10px 24px 40px; }
            .tk-help-hero { padding: 30px 24px; }
            .tk-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .tk-help-features-grid { grid-template-columns: 1fr; }
            .tk-help-data-grid { grid-template-columns: 1fr; }
            .tk-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="tk-help-container">
        <!-- Left pane navigation -->
        <div class="tk-help-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="tk-help-nav-link active" data-section="overview">
                <span class="tk-help-nav-num">1</span>
                Overview
            </a>
            <a href="#inbox" class="tk-help-nav-link" data-section="inbox">
                <span class="tk-help-nav-num">2</span>
                The inbox
            </a>
            <a href="#working-with-tickets" class="tk-help-nav-link highlight" data-section="working-with-tickets">
                <span class="tk-help-nav-num highlight">3</span>
                Working with tickets
            </a>
            <a href="#comments-attachments" class="tk-help-nav-link" data-section="comments-attachments">
                <span class="tk-help-nav-num">4</span>
                Comments &amp; attachments
            </a>
            <a href="#dashboard" class="tk-help-nav-link" data-section="dashboard">
                <span class="tk-help-nav-num">5</span>
                Dashboard
            </a>
            <a href="#calendar-rota" class="tk-help-nav-link highlight" data-section="calendar-rota">
                <span class="tk-help-nav-num highlight">6</span>
                Calendar &amp; rota
            </a>
            <a href="#settings" class="tk-help-nav-link" data-section="settings">
                <span class="tk-help-nav-num">7</span>
                Settings
            </a>
            <a href="#tips" class="tk-help-nav-link" data-section="tips">
                <span class="tk-help-nav-num">8</span>
                Quick tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="tk-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="tk-help-hero">
                <h2>Tickets module guide</h2>
                <p>Log, track, and resolve IT support requests from a single folder-based inbox &mdash; built for service desk teams.</p>
            </div>

            <div class="tk-help-content">

                <!-- Section 1: Overview -->
                <div class="tk-help-section" id="overview">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>The Tickets module is the core of your IT service desk. It provides a familiar email-client style interface for managing support requests, incidents, and service tasks. Tickets flow in from end users via email or manual creation, and analysts work through them using folders, priorities, and SLA timers &mdash; everything you need to deliver responsive IT support.</p>
                        </div>
                    </div>
                    <div class="tk-help-features-grid">
                        <div class="tk-help-feature-card">
                            <div class="tk-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <h4>Inbox</h4>
                            <p>A folder-based inbox modelled on an email client. Browse My Tickets, Unassigned, All Open, and Closed folders. Click any ticket to open a reading pane with full details, history, and actions.</p>
                        </div>
                        <div class="tk-help-feature-card">
                            <div class="tk-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                            </div>
                            <h4>Dashboard</h4>
                            <p>Build a personalised dashboard with customisable Chart.js widgets. Visualise ticket volumes, response times, category breakdowns, and analyst workloads with bar, pie, doughnut, and line charts.</p>
                        </div>
                        <div class="tk-help-feature-card">
                            <div class="tk-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </div>
                            <h4>Calendar</h4>
                            <p>View tickets on a calendar grid based on their created, due, or resolved dates. Quickly spot overdue items and plan your week by seeing the ticket landscape at a glance.</p>
                        </div>
                        <div class="tk-help-feature-card">
                            <div class="tk-help-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </div>
                            <h4>Rota</h4>
                            <p>Schedule analyst shifts and on-call rotas. See who is available at any time, plan coverage for weekends and holidays, and ensure no ticket goes unattended.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: The Inbox -->
                <div class="tk-help-section" id="inbox">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">2</span>
                        <div>
                            <h3>The inbox</h3>
                            <p>The inbox is the primary workspace for analysts. It uses a three-pane layout &mdash; folders on the left, a ticket list in the centre, and a reading pane on the right &mdash; so you can triage and respond without ever leaving the page.</p>
                        </div>
                    </div>
                    <p>The folder panel organises tickets into logical groups. Each folder shows an unread count so you can spot new work immediately:</p>
                    <div class="tk-help-fields">
                        <div><strong>My Tickets</strong> &mdash; tickets assigned to you. This is your personal queue and the best place to start each day.</div>
                        <div><strong>Unassigned</strong> &mdash; tickets that have arrived but have no analyst yet. Pick items from here to take ownership.</div>
                        <div><strong>All Open</strong> &mdash; every ticket that is not yet closed, regardless of who owns it. Useful for managers and team leads monitoring overall volume.</div>
                        <div><strong>Closed</strong> &mdash; resolved tickets. Search here when you need to reference a past solution or reopen an issue.</div>
                        <div><strong>Department folders</strong> &mdash; tickets grouped by department (e.g. IT, HR, Finance). Departments are configured in Settings and appear as sub-folders automatically.</div>
                    </div>
                    <p>Above the ticket list, you have three action buttons: <strong>New</strong> to create a ticket, <strong>Search</strong> to find tickets by keyword, reference, or requester, and <strong>Refresh</strong> to reload the current folder.</p>
                    <p class="tk-help-tip">Click any ticket in the list to load it in the reading pane. From there you can update fields, add comments, reply by email, or attach files &mdash; all without navigating away from the inbox.</p>
                </div>

                <!-- Section 3: Working with Tickets (highlighted) -->
                <div class="tk-help-section tk-help-section-highlight" id="working-with-tickets">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num highlight">3</span>
                        <h3>Working with tickets</h3>
                    </div>
                    <p class="tk-help-intro">Every support request follows a lifecycle: it is created, triaged, worked on, and eventually resolved. The Tickets module gives you the tools to manage each stage efficiently, from initial logging through to closure.</p>

                    <p><strong>Creating a new ticket</strong></p>
                    <div class="tk-help-steps">
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">1</div>
                            <div>
                                <strong>Click New</strong> &mdash; open the new ticket modal from the inbox toolbar. Enter the requester's name and email address so they can receive updates.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">2</div>
                            <div>
                                <strong>Set the subject</strong> &mdash; write a clear, concise summary of the issue. This appears in the ticket list and in any email notifications sent to the end user.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">3</div>
                            <div>
                                <strong>Choose department, type, and priority</strong> &mdash; select the appropriate department, ticket type (e.g. Incident, Service Request), and priority level. These fields drive SLA timers and routing rules.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">4</div>
                            <div>
                                <strong>Add the description</strong> &mdash; provide the full details of the issue using the rich text editor. Include steps to reproduce, error messages, and any relevant context the resolving analyst will need.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">5</div>
                            <div>
                                <strong>Save</strong> &mdash; the ticket is created with a unique reference number and appears in the appropriate folder. The SLA clock starts immediately based on the priority.
                            </div>
                        </div>
                    </div>

                    <p style="margin-top: 20px;"><strong>Editing and managing tickets</strong></p>
                    <p>Once a ticket exists, you can update any field from the reading pane. Key fields include:</p>
                    <div class="tk-help-data-grid">
                        <div class="tk-help-data-card">
                            <strong>Status</strong>
                            <span>Track the ticket lifecycle: New, Open, In Progress, Pending, Resolved, Closed</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Priority</strong>
                            <span>Set urgency and impact: Critical, High, Medium, Low. Each level has its own SLA target</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Category</strong>
                            <span>Classify the issue type: Hardware, Software, Network, Access, General. Useful for reporting</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Assigned analyst</strong>
                            <span>The person responsible for resolving the ticket. Reassignment is logged in the activity trail</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>End user</strong>
                            <span>The person who raised the request. Lookup by name or email to link to an existing user record</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Department</strong>
                            <span>Route tickets to the correct team. Departments drive folder grouping and dashboard filters</span>
                        </div>
                    </div>

                    <div class="tk-help-flow">
                        <div class="tk-help-flow-step inbox">New</div>
                        <div class="tk-help-flow-arrow">&rarr;</div>
                        <div class="tk-help-flow-step action">In Progress</div>
                        <div class="tk-help-flow-arrow">&rarr;</div>
                        <div class="tk-help-flow-step resolve">Resolved</div>
                        <div class="tk-help-flow-arrow">&rarr;</div>
                        <div class="tk-help-flow-step closed">Closed</div>
                    </div>

                    <p class="tk-help-tip">Every field change is recorded in the ticket's activity trail. You can always see who changed what and when, which is essential for audit compliance and handover between analysts.</p>
                </div>

                <!-- Section 4: Comments & Attachments -->
                <div class="tk-help-section" id="comments-attachments">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">4</span>
                        <div>
                            <h3>Comments &amp; attachments</h3>
                            <p>The activity trail is the heart of every ticket. It captures every action taken &mdash; comments, field changes, emails sent, and files attached &mdash; in a chronological timeline that tells the full story of a support request.</p>
                        </div>
                    </div>

                    <p><strong>Adding comments</strong></p>
                    <div class="tk-help-steps">
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">1</div>
                            <div>
                                <strong>Open the ticket</strong> &mdash; click any ticket in the inbox to load its reading pane. Scroll down to see the full activity trail and the comment box at the bottom.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">2</div>
                            <div>
                                <strong>Write your note</strong> &mdash; use the Add Note button to open the comment editor. Notes are internal by default, visible only to analysts on the service desk.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">3</div>
                            <div>
                                <strong>Reply or forward by email</strong> &mdash; use the Reply or Forward actions to send a message to the end user or a third party. The email is logged in the activity trail alongside the outbound message content.
                            </div>
                        </div>
                    </div>

                    <p style="margin-top: 16px;"><strong>File attachments</strong></p>
                    <p>Attach screenshots, logs, documents, or any supporting file directly to a ticket. Files can be dragged into the attachment drop zone or selected using the file browser. Attachments are stored on the server and linked to the ticket's activity trail so nothing gets lost.</p>

                    <p><strong>Audit logging</strong></p>
                    <p>The activity trail automatically records every action without analyst intervention. When a priority is changed from Medium to High, the old and new values are captured. When a ticket is reassigned, both the previous and new analyst are logged. This creates a complete, tamper-proof history that satisfies ITIL audit requirements and helps managers understand how tickets are being handled.</p>

                    <p class="tk-help-tip">When replying by email, you can add Cc recipients and attach files in the same action. The entire email conversation is threaded into the ticket's activity trail, keeping all communication in one place.</p>
                </div>

                <!-- Section 5: Dashboard -->
                <div class="tk-help-section" id="dashboard">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">5</span>
                        <div>
                            <h3>Dashboard</h3>
                            <p>The ticket dashboard provides at-a-glance analytics with customisable Chart.js widgets. Each analyst has their own dashboard layout, so you can focus on the metrics that matter to your role &mdash; whether that is personal workload, team throughput, or SLA compliance.</p>
                        </div>
                    </div>
                    <div class="tk-help-steps">
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">1</div>
                            <div>
                                <strong>Open the Library</strong> &mdash; click Edit Dashboard to enter editing mode, then browse the widget library. Choose from pre-built widgets or create your own by selecting a chart type and the data property to aggregate.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">2</div>
                            <div>
                                <strong>Add widgets</strong> &mdash; click the + button on any widget in the library to add it to your dashboard. It appears immediately and starts displaying live data from your ticket database.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">3</div>
                            <div>
                                <strong>Configure</strong> &mdash; click the cog icon on any widget to customise its title, chart type (bar, pie, doughnut, line, stacked bar, multi-line), date range filter, department filter, and time grouping (daily, weekly, monthly).
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">4</div>
                            <div>
                                <strong>Arrange</strong> &mdash; drag and drop widgets to reorder them. Your layout is saved per analyst so it persists across sessions.
                            </div>
                        </div>
                    </div>
                    <p>Common widget examples include: <strong>Tickets by Status</strong> (doughnut chart showing open vs resolved vs closed), <strong>Tickets by Priority</strong> (bar chart of volume by severity), <strong>Weekly Volume</strong> (line chart tracking new tickets over time), and <strong>Analyst Workload</strong> (stacked bar showing each analyst's active tickets).</p>
                    <p class="tk-help-tip">Use date range filters on individual widgets to compare current performance against previous periods. For example, a "This Month vs Last Month" view helps you spot trends in ticket volume early.</p>
                </div>

                <!-- Section 6: Calendar & Rota (highlighted) -->
                <div class="tk-help-section tk-help-section-highlight" id="calendar-rota">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num highlight">6</span>
                        <h3>Calendar &amp; rota</h3>
                    </div>
                    <p class="tk-help-intro">Two scheduling tools help your team stay organised: a ticket calendar that visualises workload over time, and a staff rota for managing shifts and on-call availability.</p>

                    <p><strong>Calendar view</strong></p>
                    <p>The calendar displays tickets as events on a monthly, weekly, or daily grid. Each ticket appears on the date it was created (or its due date, depending on the view setting), colour-coded by priority. This makes it easy to see busy days at a glance and plan your workload accordingly.</p>
                    <div class="tk-help-fields">
                        <div><strong>Month view</strong> &mdash; a bird's-eye view of the entire month. Ticket counts are shown on each day cell. Click a day to drill down.</div>
                        <div><strong>Week view</strong> &mdash; more detail for the current week, with individual ticket titles visible. Useful for sprint planning.</div>
                        <div><strong>Day view</strong> &mdash; a full list of tickets for a single day, ideal for daily stand-ups and shift handovers.</div>
                    </div>

                    <p style="margin-top: 16px;"><strong>Staff rota</strong></p>
                    <p>The rota page lets team leads and managers schedule analyst shifts across the week. Define standard working hours, assign on-call periods, and mark holidays or absence so the team always knows who is available.</p>
                    <div class="tk-help-steps">
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">1</div>
                            <div>
                                <strong>View the rota grid</strong> &mdash; the rota displays analysts along one axis and days along the other, with shift blocks showing coverage at a glance.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">2</div>
                            <div>
                                <strong>Add or edit shifts</strong> &mdash; click on a cell to assign a shift type (e.g. Early, Late, Night, On-Call). Changes save immediately.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">3</div>
                            <div>
                                <strong>Plan ahead</strong> &mdash; navigate forward by week to build the rota in advance. This ensures adequate coverage during known busy periods, holidays, or training days.
                            </div>
                        </div>
                    </div>

                    <p class="tk-help-tip">Combine the calendar and rota together for effective capacity planning. If the calendar shows a spike in tickets every Monday morning, ensure the rota has extra coverage scheduled for that slot.</p>
                </div>

                <!-- Section 7: Settings -->
                <div class="tk-help-section" id="settings">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">7</span>
                        <div>
                            <h3>Settings</h3>
                            <p>The Settings page is where you configure the building blocks of your ticketing system. All lookup values, SLA targets, email integration, and custom fields are managed here.</p>
                        </div>
                    </div>
                    <div class="tk-help-data-grid">
                        <div class="tk-help-data-card">
                            <strong>Departments</strong>
                            <span>Create departments like IT, HR, and Finance. Each department becomes a folder in the inbox and a filter on the dashboard</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Ticket types</strong>
                            <span>Define the types of requests your desk handles: Incident, Service Request, Problem, Change. Types help analysts classify work</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Priorities</strong>
                            <span>Set priority levels with names, colours, and sort orders. Each priority links to an SLA target for response and resolution</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Statuses</strong>
                            <span>Customise the ticket lifecycle stages. Default statuses include New, Open, In Progress, Pending, Resolved, and Closed</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Categories</strong>
                            <span>Group tickets by technical area: Hardware, Software, Network, Access. Categories power reporting breakdowns</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>SLA targets</strong>
                            <span>Define response and resolution time targets per priority. SLA timers appear on tickets and drive breach warnings</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Email importing</strong>
                            <span>Connect a mailbox (Exchange, Microsoft 365) to automatically import emails as new tickets. Replies thread back into existing tickets</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Custom fields</strong>
                            <span>Add your own fields to tickets for data your organisation needs to capture that is not covered by the default field set</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Email templates</strong>
                            <span>Configure the notification templates sent to end users when tickets are created, updated, or resolved</span>
                        </div>
                    </div>

                    <p><strong>Email importing in detail</strong></p>
                    <p>The email import feature connects to your shared mailbox and pulls in new messages as tickets. When an end user sends an email to your support address (e.g. support@yourcompany.com), FreeITSM creates a ticket with the email subject as the title and the email body as the description. If the sender replies to a notification email, the reply is threaded into the existing ticket rather than creating a duplicate.</p>
                    <div class="tk-help-steps">
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">1</div>
                            <div>
                                <strong>Configure the mailbox</strong> &mdash; go to Settings and enter your Exchange or Microsoft 365 credentials. OAuth authentication is supported for secure, password-free connections.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">2</div>
                            <div>
                                <strong>Set polling interval</strong> &mdash; choose how frequently FreeITSM checks the mailbox for new messages. Shorter intervals mean faster ticket creation.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">3</div>
                            <div>
                                <strong>Map defaults</strong> &mdash; set the default department, type, and priority for tickets created from email. Analysts can adjust these after import.
                            </div>
                        </div>
                    </div>

                    <p class="tk-help-tip">Use email templates to maintain a professional, consistent tone in all end-user communications. Templates support dynamic placeholders for ticket reference, requester name, analyst name, and status.</p>
                </div>

                <!-- Section 8: Quick Tips -->
                <div class="tk-help-section" id="tips">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">8</span>
                        <h3>Quick tips</h3>
                    </div>
                    <div class="tk-help-tips-grid">
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128269;</div>
                            <div><strong>Search everything</strong><br>The search modal checks ticket references, subjects, requester names, and email addresses. Use it to find related tickets quickly when a user calls about an existing issue.</div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#9200;</div>
                            <div><strong>Watch your SLAs</strong><br>SLA timers are visible on each ticket in the reading pane. Colour-coded indicators turn amber when a breach is approaching and red when the target has been missed, so you can prioritise accordingly.</div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128233;</div>
                            <div><strong>Reply from the inbox</strong><br>You do not need to open your email client to respond to an end user. Use the Reply button in the reading pane to send a message directly. It threads into the ticket automatically.</div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128203;</div>
                            <div><strong>Use the activity trail</strong><br>Before working on a ticket, scroll through its activity trail. Previous analyst notes, emails, and field changes give you the full context so you do not ask the user to repeat information.</div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128200;</div>
                            <div><strong>Dashboard for managers</strong><br>Build a dashboard with widgets for SLA compliance rates, average resolution time, and tickets by department. Share it during team meetings to drive continuous improvement.</div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128197;</div>
                            <div><strong>Plan with the rota</strong><br>Fill out the rota at least two weeks in advance. When combined with the calendar view, it helps you identify gaps in coverage before they become a problem for response times.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.tk-help-nav-link');
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
