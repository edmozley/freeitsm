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
            <a href="#working-with-tickets" class="tk-help-nav-link" data-section="working-with-tickets">
                <span class="tk-help-nav-num">3</span>
                Working with tickets
            </a>
            <a href="#comments-attachments" class="tk-help-nav-link" data-section="comments-attachments">
                <span class="tk-help-nav-num">4</span>
                Comments &amp; attachments
            </a>
            <a href="#ai-tools" class="tk-help-nav-link" data-section="ai-tools">
                <span class="tk-help-nav-num">5</span>
                AI tools
            </a>
            <a href="#csat" class="tk-help-nav-link" data-section="csat">
                <span class="tk-help-nav-num">6</span>
                CSAT surveys
            </a>
            <a href="#user-management" class="tk-help-nav-link" data-section="user-management">
                <span class="tk-help-nav-num">7</span>
                User management
            </a>
            <a href="#dashboard" class="tk-help-nav-link" data-section="dashboard">
                <span class="tk-help-nav-num">8</span>
                Dashboard
            </a>
            <a href="#calendar-rota" class="tk-help-nav-link" data-section="calendar-rota">
                <span class="tk-help-nav-num">9</span>
                Calendar &amp; rota
            </a>
            <a href="#settings" class="tk-help-nav-link" data-section="settings">
                <span class="tk-help-nav-num">10</span>
                Settings
            </a>
            <a href="#tips" class="tk-help-nav-link" data-section="tips">
                <span class="tk-help-nav-num">11</span>
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

                    <p style="margin-top: 20px;"><strong>Switching between Department and Analyst grouping</strong></p>
                    <p>A small two-button toggle at the top of the folder panel switches between two views of the same data. <strong>Department</strong> grouping (default) lists tickets under each department with status sub-folders inside; <strong>Analyst</strong> grouping flips it so each top-level folder represents an analyst's personal queue with status sub-folders inside. The <strong>Unassigned</strong> folder is context-aware in each view &mdash; in Department view it lists tickets with no department set; in Analyst view it lists tickets with no assignee &mdash; so you can always see what needs picking up. Your choice is saved per-analyst so each team member keeps the view they prefer across sessions.</p>

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

                    <p style="margin-top: 24px;"><strong>Triage by drag and drop</strong></p>
                    <p>You don't have to open a ticket to triage it. Drag any row from the ticket list onto a folder to update fields in one motion:</p>
                    <div class="tk-help-fields">
                        <div><strong>Department view</strong> &mdash; drop on a department folder to reassign, drop on a status sub-folder under a department to update both at once, drop on <strong>Unassigned</strong> to clear the department.</div>
                        <div><strong>Analyst view</strong> &mdash; drop on an analyst's folder to assign that analyst as the owner (sets both <code>assigned_analyst_id</code> and <code>owner_id</code> so the Owner field in the reading pane stays in sync), drop on a status sub-folder to update both, drop on <strong>Unassigned</strong> to clear the assignee.</div>
                        <div><strong>Spring-loaded folders</strong> &mdash; hover a collapsed folder during a drag and it auto-expands (Outlook-style) so any nested status is reachable without dropping first.</div>
                    </div>

                    <p style="margin-top: 20px;"><strong>Full-screen view</strong></p>
                    <p>When you need to focus on a single ticket &mdash; a long conversation, a complex change, drafting a detailed reply &mdash; switch to full-screen view. The folder list and the ticket list disappear, the conversation fills the available width, and the properties (Department, Type, Status, Origin, First Time Fix, IT Training, Owner) move to a vertical sidebar on the right.</p>
                    <div class="tk-help-fields">
                        <div><strong>Maximize icon</strong> &mdash; small icon at the right end of the ticket subject line. Click to enter full-screen; click again to return to the 3-pane layout.</div>
                        <div><strong>Double-click a row</strong> &mdash; double-click any ticket in the list to open it directly in full-screen, Outlook-style "really open this one" gesture.</div>
                        <div><strong>Sticks across selections</strong> &mdash; once you're in full-screen, switching to the next ticket keeps you there. A fresh page reload (F5) with no ticket selected returns to the default 3-column layout, so you can't end up trapped with the folder list hidden.</div>
                    </div>

                    <p style="margin-top: 20px;"><strong>Right-click for quick actions</strong></p>
                    <p>Right-click any ticket in the list to open a context menu. The menu shows the ticket reference at the top so you know what you're acting on, and every action operates on the right-clicked ticket <em>without</em> changing what's currently open in the reading pane &mdash; useful when you're reading ticket A and need to triage ticket B without losing your place.</p>
                    <div class="tk-help-fields">
                        <div><strong>Set status &rarr;</strong> &mdash; flyout submenu listing every active status with its colour swatch. A tick appears next to the current value if you're right-clicking the ticket you've got open. Picks immediately update the ticket, write an audit-trail entry, refresh the folder counts, and sync the reading pane's Status dropdown if applicable.</div>
                        <div><strong>Set priority &rarr;</strong> &mdash; same flyout pattern, sourced from the active priorities lookup with each one's colour as a swatch. The first row is a <em>(no priority)</em> option for clearing the assignment (priority is nullable).</div>
                        <div><strong>Assign to &rarr;</strong> &mdash; submenu listing every loaded analyst with a grey initial chip, plus an <em>(unassigned)</em> row at the top to clear. Picks set both <code>assigned_analyst_id</code> AND <code>owner_id</code> so the reading pane's Owner field stays in sync.</div>
                        <div><strong>Link CMDB object&hellip;</strong> &mdash; opens a search-as-you-type picker. Click a result to link it, then keep picking &mdash; the input clears and refocuses for the next one, and a "Recently linked" log inside the modal shows your running list of green-tick confirmations.</div>
                        <div><strong>Record time&hellip;</strong> &mdash; opens a modal with minutes + a datetime picker (defaults to "now" so you can also backdate) + an optional notes box.</div>
                    </div>
                    <p class="tk-help-tip">If the ticket you're acting on via the context menu happens to be the same one open in the reading pane, the relevant section there (Status / Priority dropdowns, CMDB objects, time entries) auto-refreshes when the modal closes so the UI stays in sync.</p>

                    <p style="margin-top: 20px;"><strong>Recording time</strong></p>
                    <p>Time spent on a ticket is logged into a dedicated <strong>Time Entries</strong> section in the reading pane, sitting between the linked CMDB objects panel and the Notes panel. The header shows the running total (e.g. "Total 2h 30m"), and each entry below shows the time spent, the analyst, the date, and any notes you added.</p>
                    <div class="tk-help-steps">
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">1</div>
                            <div>
                                <strong>Inline form</strong> &mdash; type the minutes spent into the numeric input, an optional short description into the notes input, and click <strong>Add</strong>. The new entry appears immediately at the top of the list with your name and the current timestamp.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">2</div>
                            <div>
                                <strong>Backdate via right-click</strong> &mdash; if you need to log time you forgot to record earlier, right-click the ticket in the list and pick <strong>Record time&hellip;</strong>. The modal includes a datetime picker so you can stamp the entry with the actual time the work was done.
                            </div>
                        </div>
                        <div class="tk-help-step-item">
                            <div class="tk-help-step-num">3</div>
                            <div>
                                <strong>Delete your own entries</strong> &mdash; a small &times; button appears next to entries you logged. Click it (and confirm) to soft-delete &mdash; the row stays in the database with <code>is_active = 0</code> for audit, but disappears from the list. You can't delete other analysts' entries.
                            </div>
                        </div>
                    </div>
                    <p class="tk-help-tip">Times are stored as plain minutes (45 min, 90 min, etc.) and rendered as <code>45m</code> or <code>1h 30m</code> in the UI &mdash; granular enough for short fixes, no decimal-hours rounding pain.</p>

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

                <!-- Section 5: AI tools -->
                <div class="tk-help-section" id="ai-tools">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">5</span>
                        <div>
                            <h3>AI tools</h3>
                            <p>Two Claude-powered features sit inside the ticket workflow: <strong>Reply Cleanup</strong> rewrites a rough draft into a properly formatted email, and <strong>Ask AI</strong> searches the knowledge base in natural language using ticket context.</p>
                        </div>
                    </div>

                    <p><strong>Reply Cleanup &mdash; ✨ button in the reply editor</strong></p>
                    <p>Type a quick rough draft into a reply (or just bullet points like *"need to restart the print service, take screenshot for them"*) and click the <strong>✨ Cleanup</strong> button. Claude rewrites it as a properly formatted email &mdash; adds a <em>Dear [name],</em> greeting from the requester, fixes grammar, applies the configured tone, and signs off with <em>Kind regards,</em>. The prompt is locked down so it will NOT invent technical details, fabricate apologies, or pad the content beyond what you wrote.</p>
                    <div class="tk-help-fields">
                        <div><strong>Streams live</strong> &mdash; the rewrite appears in the editor as Claude generates it (no waiting in front of a spinner).</div>
                        <div><strong>Undo link for 30s</strong> &mdash; in case Claude butchers the rewrite, a small <em>Undo</em> link appears below the editor for 30 seconds after the rewrite completes, restoring the original draft.</div>
                        <div><strong>Per-tone</strong> &mdash; three tone presets configurable under <strong>Settings &rarr; Reply Cleanup</strong>: <em>Friendly</em> (default), <em>Formal</em>, <em>Brief</em>. Pick whichever matches your team's house style.</div>
                        <div><strong>Own API key</strong> &mdash; separate Anthropic key from the RFP / Knowledge / Workflow AI features so its usage shows as its own line on the Anthropic billing dashboard. Configured under <strong>Settings &rarr; Reply Cleanup</strong>.</div>
                    </div>
                    <p class="tk-help-tip">Cleanup is best used for the routine "I tried this, please try X" style replies. For long, nuanced messages you'll want to write them yourself &mdash; the AI is great at structure but doesn't know your specific environment.</p>

                    <p style="margin-top: 20px;"><strong>Ask AI &mdash; 🤖 button in the ticket toolbar</strong></p>
                    <p>Click the <strong>Ask AI</strong> button (🤖) in the ticket toolbar to open a slide-in chat panel that knows about the open ticket's subject, body, and reading-pane context. Ask it questions like *"have we seen this before?"* or *"what's the usual fix for this error?"* and it searches the <a href="../knowledge/" style="color:#0078d4;">Knowledge</a> base semantically (via Claude + OpenAI embeddings) and replies with relevant articles, with direct links to open them.</p>
                    <div class="tk-help-fields">
                        <div><strong>Context-aware</strong> &mdash; the panel knows what ticket you're looking at, so you can ask generic questions and it figures out the relevant context.</div>
                        <div><strong>Linked articles</strong> &mdash; suggestions are clickable and open the matching knowledge article in a new tab.</div>
                        <div><strong>Shared with Knowledge module</strong> &mdash; the AI uses the same key + model as Knowledge AI search, so configuring it once gives you both. See the Knowledge module help for setup details.</div>
                    </div>
                </div>

                <!-- Section 6: CSAT surveys -->
                <div class="tk-help-section" id="csat">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">6</span>
                        <div>
                            <h3>Customer satisfaction surveys (CSAT)</h3>
                            <p>Email a 1&ndash;5 satisfaction survey to the requester when a ticket is resolved, capture the response on a no-login landing page, and track per-analyst trends on a dedicated analytics page.</p>
                        </div>
                    </div>

                    <p><strong>Two trigger modes &mdash; pick one</strong></p>
                    <div class="tk-help-fields">
                        <div><strong>Auto on close</strong> &mdash; survey is queued automatically when a ticket transitions into any closed status. The analyst doesn't need to do anything &mdash; resolution drives the survey.</div>
                        <div><strong>Manual only</strong> &mdash; analyst clicks the ⭐ <strong>Request feedback</strong> button in the ticket toolbar when they want to ask. Useful when you'd rather decide ticket-by-ticket whether to invite feedback.</div>
                        <div><strong>Off</strong> &mdash; feature disabled; no survey emails are sent.</div>
                    </div>
                    <p>Choose under <strong>Tickets &rarr; Settings &rarr; CSAT</strong>. The manual button still works in <em>auto</em> mode &mdash; analysts can re-request feedback on a ticket that's already been surveyed if needed.</p>

                    <p style="margin-top: 20px;"><strong>The email template</strong></p>
                    <p>The survey email content comes from the existing <strong>Email templates</strong> tab &mdash; create a template with event <strong>CSAT survey</strong> (the new event alongside <em>Ticket assigned</em> / <em>Ticket closed</em>) and embed the <code>[csat_link]</code> merge code wherever you want the rating URL to appear. Standard merge codes (<code>[requester_name]</code>, <code>[ticket_reference]</code>, <code>[analyst_name]</code>) are available as normal.</p>
                    <p class="tk-help-tip">If no active CSAT template exists, the <em>Request feedback</em> button refuses with a clear error and the auto-trigger silently skips &mdash; you can never produce dead survey links.</p>

                    <p style="margin-top: 20px;"><strong>The survey page</strong></p>
                    <p>The requester clicks the link in the email and lands on a no-login page with a 1&ndash;5 picker plus an optional comment box. Two visual styles configurable under <strong>Settings &rarr; CSAT</strong>:</p>
                    <div class="tk-help-fields">
                        <div><strong>Stars</strong> &mdash; classic fill-on-hover trailing behaviour. Hovering star #3 fills #1+#2+#3 in gold. Click to lock.</div>
                        <div><strong>Emojis</strong> &mdash; 5 faces 😡 🙁 😐 🙂 😀 start greyscale at 40% opacity; the hovered or selected one returns to full colour at 1.25&times; scale. Click to lock.</div>
                    </div>
                    <p>Both modes store the same 1&ndash;5 number so dashboards and averages work identically regardless of which style is in use. The survey URL is one-shot &mdash; once the user submits, the same link refuses re-submission.</p>

                    <p style="margin-top: 20px;"><strong>The analytics page</strong></p>
                    <p>Click the ⭐ <strong>CSAT</strong> nav button (between Rota and Settings) to open the analytics dashboard at <code>tickets/csat/</code>. Window selectable: 7 / 30 / 90 / 365 days.</p>
                    <div class="tk-help-data-grid">
                        <div class="tk-help-data-card">
                            <strong>KPI tiles</strong>
                            <span>Average rating (out of 5), response count, response rate (responded ÷ sent)</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Score distribution</strong>
                            <span>Bar chart of 1–5 ratings showing exact counts and percentages</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Per-analyst leaderboard</strong>
                            <span>Average rating + response count per analyst, ordered by avg desc. Use for development conversations</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Recent responses</strong>
                            <span>The 25 most-recent ratings with their free-text comments, linked back to the source ticket</span>
                        </div>
                    </div>
                    <p class="tk-help-tip">Per-analyst attribution survives ticket reassignment because the analyst id is captured onto the survey row at send time, not looked up live. If a ticket gets passed around before being closed, the analyst who actually closed it owns the rating.</p>

                    <p style="margin-top: 20px;"><strong>One survey per ticket</strong></p>
                    <p>If <em>One survey per ticket</em> is on (default), a reopened-then-closed ticket only gets another survey when an analyst manually triggers it &mdash; stops survey-spamming a flaky ticket that keeps cycling. Turn it off if you want every close to fire a fresh survey.</p>
                </div>

                <!-- Section 7: User management -->
                <div class="tk-help-section" id="user-management">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">7</span>
                        <div>
                            <h3>User management</h3>
                            <p>The <strong>Users</strong> nav button opens <code>tickets/users.php</code> &mdash; a directory of every end user (the people who raise tickets, not analysts) alongside each one's ticket history.</p>
                        </div>
                    </div>

                    <p>Users land in the system four reactive ways &mdash; portal self-registration, an inbound email from a new sender, the mailbox poller, or the workflow engine creating one from a triggered ticket. That covers most cases, but the Users page also lets you <strong>pre-create</strong> a user (e.g. a new starter who's about to need access) and <strong>edit</strong> or <strong>delete</strong> existing rows.</p>

                    <p style="margin-top: 16px;"><strong>Add user</strong></p>
                    <p>The <strong>Add</strong> button in the users-list header opens a modal:</p>
                    <div class="tk-help-fields">
                        <div><strong>Email</strong> (required) &mdash; must be unique, and must not collide with an analyst account.</div>
                        <div><strong>Display name / preferred name</strong> &mdash; optional. Preferred name is what the user sees themselves greeted with in emails (e.g. <em>"Ed"</em> instead of <em>"Ed Mozley"</em>).</div>
                        <div><strong>Password</strong> &mdash; optional. Leaving it blank creates a <em>passwordless</em> account &mdash; exactly the same state inbound-ticket users start in. The user can later claim the account via the self-service portal's register flow by setting their own password.</div>
                    </div>

                    <p style="margin-top: 16px;"><strong>Edit user</strong></p>
                    <p>Select a user from the list, then click <strong>Edit</strong> in the detail header. Same modal, pre-filled. Saving without a password leaves the existing hash untouched; supplying one resets it.</p>

                    <p style="margin-top: 16px;"><strong>Delete user</strong></p>
                    <p>The <strong>Delete</strong> button is <strong>FK-safe</strong>: it refuses if the user is the requester on any tickets or has any rows in <code>users_assets</code>, returning a clear <em>"Cannot delete: this user is the requester on N ticket(s). Reassign or close those tickets first."</em> message. Audit history can never silently break.</p>
                    <p class="tk-help-tip">All modal fields have browser autofill disabled (including <code>autocomplete="new-password"</code> on the password field) so the analyst's own saved credentials aren't suggested into a form whose entire purpose is creating <em>someone else's</em> record.</p>
                </div>

                <!-- Section 8: Dashboard -->
                <div class="tk-help-section" id="dashboard">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">8</span>
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

                <!-- Section 9: Calendar & Rota (highlighted) -->
                <div class="tk-help-section tk-help-section-highlight" id="calendar-rota">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num highlight">9</span>
                        <h3>Calendar &amp; rota</h3>
                    </div>
                    <p class="tk-help-intro">Two scheduling tools help your team stay organised: a ticket calendar that visualises workload over time, and a staff rota for managing shifts and on-call availability.</p>

                    <p><strong>Calendar view</strong></p>
                    <p>The calendar plots every ticket that has a <code>work_start_datetime</code> set (via the right-click <em>Record time</em> / <em>Schedule</em> modal) as a coloured block at its scheduled time. Red blocks are <em>High</em> priority, green are <em>Low</em>, blue is anything else. Use <em>Today</em>, the <em>&lt;</em> / <em>&gt;</em> chevrons, and the <strong>Month / Week / Day</strong> toggle on the right of the header to navigate &mdash; <em>Prev</em> / <em>Next</em> step by month, week or day depending on the active view.</p>
                    <div class="tk-help-fields">
                        <div><strong>Month view</strong> &mdash; the classic 6-row grid with a weekday header strip; each day cell shows up to three ticket pills with their start time, plus a <em>+N more</em> link that drops you into Day view for that date. Today is highlighted.</div>
                        <div><strong>Week view</strong> &mdash; Mon&ndash;Sun across the top over a 24-hour vertical timeline. Tickets render as 1-hour coloured blocks at their start time. Today's column is highlighted; the weekday header stays put while the hour grid scrolls.</div>
                        <div><strong>Day view</strong> &mdash; a single tall column for the chosen day with the same 24-hour timeline. Tickets render as larger blocks showing <code>ticket_number &mdash; subject</code> and start time; click one to open its details.</div>
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

                <!-- Section 10: Settings -->
                <div class="tk-help-section" id="settings">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">10</span>
                        <div>
                            <h3>Settings</h3>
                            <p>The Settings page is where you configure the building blocks of your ticketing system. All lookup values, SLA targets, email integration, and custom fields are managed here.</p>
                        </div>
                    </div>

                    <!-- Prominent SLA Management callout -->
                    <a href="help-sla.php" style="display:flex;align-items:center;gap:18px;padding:20px 24px;margin-bottom:24px;background:linear-gradient(135deg, #0078d4 0%, #005a9e 100%);color:white;border-radius:12px;text-decoration:none;box-shadow:0 4px 12px rgba(0,120,212,0.25);transition:transform 0.15s, box-shadow 0.15s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 16px rgba(0,120,212,0.35)';" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 12px rgba(0,120,212,0.25)';">
                        <div style="flex-shrink:0;width:56px;height:56px;border-radius:12px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:30px;height:30px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        </div>
                        <div style="flex:1;">
                            <div style="font-size:18px;font-weight:700;margin-bottom:4px;">SLA Management &mdash; full guide</div>
                            <div style="font-size:13px;opacity:0.9;line-height:1.5;">Configurable options, business calendars, pause statuses, breach notifications, cron job setup, plus worked examples for single-timezone and cross-timezone teams. Meaty enough to deserve its own page.</div>
                        </div>
                        <div style="flex-shrink:0;font-size:24px;opacity:0.7;">&rarr;</div>
                    </a>

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
                            <span>Configure the notification templates sent to end users when tickets are created, updated, resolved, or for the CSAT survey. Embed merge codes like [requester_name] and [csat_link]</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>Reply Cleanup AI</strong>
                            <span>Anthropic key + model + tone for the ✨ Cleanup button on the reply editor. Separate key from other AI features for granular billing</span>
                        </div>
                        <div class="tk-help-data-card">
                            <strong>CSAT</strong>
                            <span>Send mode (off / auto / manual), delay, one-per-ticket guard, scale (stars or emojis). See section 6 for the full flow</span>
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

                <!-- Section 11: Quick Tips -->
                <div class="tk-help-section" id="tips">
                    <div class="tk-help-section-header">
                        <span class="tk-help-section-num">11</span>
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
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#129032;</div>
                            <div><strong>Drag to triage</strong><br>Drag tickets from the list onto folders to update them without opening. Department + status sub-folders update both at once. Spring-loaded expansion makes nested statuses reachable mid-drag.</div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128471;</div>
                            <div><strong>Double-click for full screen</strong><br>Single-click previews a ticket in the reading pane; double-click opens it in full-screen mode for focus work. Click the maximize icon next to the subject to toggle back.</div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128499;</div>
                            <div><strong>Right-click for quick actions</strong><br>Right-click any ticket for one-click status, priority, or assignee changes plus link-CMDB and record-time, all without switching away from the ticket you're currently reading.</div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#10024;</div>
                            <div><strong>Let AI clean up your reply</strong><br>Type a rough draft (or just bullet points) and click <strong>✨ Cleanup</strong> &mdash; Claude rewrites it as a proper email with greeting, grammar fixes, and sign-off. Undo link appears for 30 seconds.</div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#11088;</div>
                            <div><strong>Ask for feedback</strong><br>Switch on CSAT under <strong>Settings &rarr; CSAT</strong> to auto-survey requesters when their tickets close. Per-analyst trends live at <code>tickets/csat/</code> &mdash; gold for development conversations.</div>
                        </div>
                        <div class="tk-help-tip-card">
                            <div class="tk-help-tip-icon">&#128100;</div>
                            <div><strong>Pre-create end users</strong><br>The <strong>Users</strong> nav button lets you add a user before they need to log a ticket &mdash; leave the password blank and they can claim the account themselves via the self-service portal.</div>
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
