<?php
/**
 * Calendar Help Guide - Full page with left pane navigation
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
    <title>Service Desk - Calendar Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .cal-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #f5f5f5;
        }

        /* Left sidebar navigation */
        .cal-help-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        .cal-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }

        .cal-help-nav-link {
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

        .cal-help-nav-link:hover {
            background: #f5f5f5;
            color: #333;
        }

        .cal-help-nav-link.active {
            background: #fff3e0;
            color: #e65100;
            font-weight: 600;
        }

        .cal-help-nav-link.highlight {
            color: #e65100;
        }

        .cal-help-nav-link.highlight.active {
            background: #e65100;
            color: white;
        }

        .cal-help-nav-num {
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

        .cal-help-nav-link.active .cal-help-nav-num {
            background: #e65100;
            color: white;
        }

        .cal-help-nav-num.highlight {
            background: #fff3e0;
            color: #e65100;
        }

        .cal-help-nav-link.highlight.active .cal-help-nav-num {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Main content */
        .cal-help-main {
            flex: 1;
            overflow-y: auto;
        }

        /* Hero banner */
        .cal-help-hero {
            background: linear-gradient(135deg, #ef6c00 0%, #e65100 50%, #bf360c 100%);
            color: white;
            padding: 40px 48px 36px;
            text-align: center;
        }

        .cal-help-hero h2 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 700;
        }

        .cal-help-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.85;
        }

        /* Content area */
        .cal-help-content {
            max-width: 1120px;
            margin: 0 auto;
            padding: 10px 48px 48px;
        }

        /* Sections */
        .cal-help-section {
            padding: 28px 0;
            border-bottom: 1px solid #eee;
            scroll-margin-top: 20px;
        }

        .cal-help-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .cal-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }

        .cal-help-section-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .cal-help-section-header p {
            margin: 6px 0 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .cal-help-section > p {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin: 0 0 14px;
        }

        .cal-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #fff3e0;
            color: #e65100;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .cal-help-section-num.highlight {
            background: #ef6c00;
            color: white;
        }

        /* Feature cards grid */
        .cal-help-features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .cal-help-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .cal-help-feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .cal-help-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .cal-help-feature-icon.orange { background: #fff3e0; color: #ef6c00; }
        .cal-help-feature-icon.blue { background: #e3f2fd; color: #1565c0; }
        .cal-help-feature-icon.green { background: #e8f5e9; color: #2e7d32; }
        .cal-help-feature-icon.purple { background: #f3e5f5; color: #7b1fa2; }

        .cal-help-feature-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            color: #333;
        }

        .cal-help-feature-card p {
            margin: 0;
            font-size: 12.5px;
            color: #666;
            line-height: 1.5;
        }

        /* Numbered steps */
        .cal-help-steps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: 46px;
        }

        .cal-help-step-item {
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

        .cal-help-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #ef6c00;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Highlighted section */
        .cal-help-section-highlight {
            background: #fff3e0;
            margin: 0 -48px;
            padding: 28px 48px !important;
            border-bottom: none !important;
            border-top: 2px solid #ffcc80;
        }

        .cal-help-intro {
            font-size: 14px;
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px !important;
        }

        /* Info fields list */
        .cal-help-fields {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 10px 0;
        }

        .cal-help-fields div {
            padding: 8px 14px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }

        /* Data cards grid */
        .cal-help-data-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .cal-help-data-card {
            padding: 12px 14px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 3px solid #ef6c00;
        }

        .cal-help-data-card strong {
            display: block;
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .cal-help-data-card span {
            font-size: 12px;
            color: #777;
            line-height: 1.4;
        }

        /* Flow diagram */
        .cal-help-flow {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 14px 0;
            flex-wrap: wrap;
            justify-content: center;
        }

        .cal-help-flow-step {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .cal-help-flow-step.calendar { background: #fff3e0; color: #e65100; }
        .cal-help-flow-step.view { background: #e3f2fd; color: #1565c0; }
        .cal-help-flow-step.category { background: #e8f5e9; color: #2e7d32; }
        .cal-help-flow-step.action { background: #f3e5f5; color: #7b1fa2; }

        .cal-help-flow-arrow {
            padding: 0 8px;
            color: #bbb;
            font-size: 18px;
        }

        /* Tip callout */
        .cal-help-tip {
            font-size: 13px !important;
            color: #e65100 !important;
            background: #fff3e0;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #ef6c00;
            margin-top: 10px;
        }

        /* Quick tips grid */
        .cal-help-tips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .cal-help-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }

        .cal-help-tip-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .cal-help-tip-card strong {
            color: #333;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .cal-help-sidebar { display: none; }
            .cal-help-content { padding: 10px 24px 40px; }
            .cal-help-hero { padding: 30px 24px; }
            .cal-help-section-highlight { margin: 0 -24px; padding: 20px 24px !important; }
        }

        @media (max-width: 700px) {
            .cal-help-features-grid { grid-template-columns: 1fr; }
            .cal-help-data-grid { grid-template-columns: 1fr; }
            .cal-help-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="cal-help-container">
        <!-- Left pane navigation -->
        <div class="cal-help-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="cal-help-nav-link active" data-section="overview">
                <span class="cal-help-nav-num">1</span>
                Overview
            </a>
            <a href="#views" class="cal-help-nav-link" data-section="views">
                <span class="cal-help-nav-num">2</span>
                Calendar views
            </a>
            <a href="#creating-events" class="cal-help-nav-link highlight" data-section="creating-events">
                <span class="cal-help-nav-num highlight">3</span>
                Creating events
            </a>
            <a href="#categories" class="cal-help-nav-link" data-section="categories">
                <span class="cal-help-nav-num">4</span>
                Event categories
            </a>
            <a href="#settings" class="cal-help-nav-link highlight" data-section="settings">
                <span class="cal-help-nav-num highlight">5</span>
                Settings
            </a>
            <a href="#tips" class="cal-help-nav-link" data-section="tips">
                <span class="cal-help-nav-num">6</span>
                Quick tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="cal-help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="cal-help-hero">
                <h2>Calendar guide</h2>
                <p>Track certificates, contracts, maintenance windows, and recurring events &mdash; all in one place.</p>
            </div>

            <div class="cal-help-content">

                <!-- Section 1: Overview -->
                <div class="cal-help-section" id="overview">
                    <div class="cal-help-section-header">
                        <span class="cal-help-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>The Calendar module gives your IT team a shared timeline for everything that matters. Instead of relying on spreadsheets or personal reminders, you can track certificate expiry dates, contract renewals, scheduled maintenance windows, and team events in a single, colour-coded calendar that everyone on the service desk can see.</p>
                        </div>
                    </div>
                    <div class="cal-help-features-grid">
                        <div class="cal-help-feature-card">
                            <div class="cal-help-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                            </div>
                            <h4>Event tracking</h4>
                            <p>Create events with titles, dates, times, locations, and descriptions. Every event is visible to the team so nothing falls through the cracks.</p>
                        </div>
                        <div class="cal-help-feature-card">
                            <div class="cal-help-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="14" width="7" height="7"></rect>
                                    <rect x="3" y="14" width="7" height="7"></rect>
                                </svg>
                            </div>
                            <h4>Multiple views</h4>
                            <p>Switch between month, week, and day views to get the level of detail you need. The month view shows an overview; week and day views show precise time slots.</p>
                        </div>
                        <div class="cal-help-feature-card">
                            <div class="cal-help-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path>
                                    <line x1="4" y1="22" x2="4" y2="15"></line>
                                </svg>
                            </div>
                            <h4>Categories</h4>
                            <p>Organise events into colour-coded categories like certificates, contracts, maintenance, and meetings. Filter the calendar to show only what you care about.</p>
                        </div>
                        <div class="cal-help-feature-card">
                            <div class="cal-help-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                            </div>
                            <h4>Scheduling</h4>
                            <p>Plan maintenance windows, set all-day events for deadlines, and schedule recurring work. The calendar helps your team coordinate and avoid conflicts.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Calendar Views -->
                <div class="cal-help-section" id="views">
                    <div class="cal-help-section-header">
                        <span class="cal-help-section-num">2</span>
                        <h3>Calendar views</h3>
                    </div>
                    <p>The calendar offers three views so you can zoom in or out depending on what you need. Switch between them using the toggle buttons in the top-right corner of the calendar header.</p>

                    <div class="cal-help-data-grid">
                        <div class="cal-help-data-card">
                            <strong>Month view</strong>
                            <span>The default view. Shows a full month grid with events displayed as coloured bars on each day. Ideal for getting an overview of what is coming up across the team.</span>
                        </div>
                        <div class="cal-help-data-card">
                            <strong>Week view</strong>
                            <span>Displays seven days with hourly time slots. Events are positioned according to their start and end times, making it easy to spot scheduling conflicts.</span>
                        </div>
                        <div class="cal-help-data-card">
                            <strong>Day view</strong>
                            <span>Focuses on a single day with detailed hourly breakdowns. Use this when you need to see exactly what is happening hour by hour during a busy day.</span>
                        </div>
                    </div>

                    <p>Use the navigation arrows next to the month/week/day title to move forwards and backwards in time. The <strong>Today</strong> button brings you straight back to the current date, no matter how far you have navigated.</p>

                    <div class="cal-help-flow">
                        <div class="cal-help-flow-step calendar">Today button</div>
                        <div class="cal-help-flow-arrow">&rarr;</div>
                        <div class="cal-help-flow-step view">Navigate prev/next</div>
                        <div class="cal-help-flow-arrow">&rarr;</div>
                        <div class="cal-help-flow-step category">Choose view</div>
                        <div class="cal-help-flow-arrow">&rarr;</div>
                        <div class="cal-help-flow-step action">Click event</div>
                    </div>

                    <p class="cal-help-tip">Click any event on the calendar to open a quick-view popup showing the title, time, location, and description. From there you can open the full edit form.</p>
                </div>

                <!-- Section 3: Creating Events (highlighted) -->
                <div class="cal-help-section cal-help-section-highlight" id="creating-events">
                    <div class="cal-help-section-header">
                        <span class="cal-help-section-num highlight">3</span>
                        <h3>Creating events</h3>
                    </div>
                    <p class="cal-help-intro">Adding events to the calendar is straightforward. Click the <strong>+ New Event</strong> button in the sidebar to open the event form. Fill in the details and save &mdash; the event appears on the calendar immediately.</p>

                    <div class="cal-help-steps">
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">1</div>
                            <div>
                                <strong>Click + New Event</strong> &mdash; the button is in the calendar sidebar on the left. This opens the event creation modal.
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">2</div>
                            <div>
                                <strong>Enter a title</strong> &mdash; give the event a clear, descriptive name. For example: "SSL certificate renewal &mdash; webserver01" or "Monthly patching window".
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">3</div>
                            <div>
                                <strong>Choose a category</strong> &mdash; select from the dropdown to colour-code the event. Categories are configured in Settings and help you filter the calendar later.
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">4</div>
                            <div>
                                <strong>Set the dates and times</strong> &mdash; pick a start date and optionally an end date. Add start and end times for timed events, or tick "All day event" for deadlines and full-day entries.
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">5</div>
                            <div>
                                <strong>Add location and description</strong> &mdash; optionally specify where the event takes place and add notes. These details are shown in the quick-view popup when someone clicks the event.
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">6</div>
                            <div>
                                <strong>Save</strong> &mdash; click Save and the event is created. It appears on the calendar straight away, colour-coded by its category.
                            </div>
                        </div>
                    </div>

                    <p class="cal-help-tip">To edit an existing event, click it on the calendar to open the popup, then click <strong>Edit</strong>. The same form opens pre-filled with the event's current details. You can also delete events from the edit form.</p>
                </div>

                <!-- Section 4: Event Categories -->
                <div class="cal-help-section" id="categories">
                    <div class="cal-help-section-header">
                        <span class="cal-help-section-num">4</span>
                        <h3>Event categories</h3>
                    </div>
                    <p>Categories are the backbone of calendar organisation. Each category has a name and a colour, so events are instantly recognisable at a glance. The sidebar shows all available categories with checkboxes &mdash; untick a category to hide those events from the calendar.</p>

                    <div class="cal-help-fields">
                        <div><strong>Certificates</strong> &mdash; track SSL/TLS certificate expiry dates, code signing certificates, and other credentials that need periodic renewal</div>
                        <div><strong>Contracts</strong> &mdash; log vendor contract renewal dates, licence expiry, and SLA review milestones so nothing lapses unexpectedly</div>
                        <div><strong>Maintenance</strong> &mdash; schedule planned maintenance windows for servers, network equipment, and infrastructure. Your team and stakeholders can see exactly when downtime is expected</div>
                        <div><strong>Meetings</strong> &mdash; record team stand-ups, CAB meetings, vendor calls, and other recurring appointments relevant to IT operations</div>
                        <div><strong>Custom categories</strong> &mdash; add your own categories in Settings to suit your team's workflow. Common additions include "Deployments", "Audits", and "Training"</div>
                    </div>

                    <p>Filtering is applied in real time. When you untick a category in the sidebar, events in that category are hidden immediately without reloading the page. Tick it again to bring them back.</p>

                    <p class="cal-help-tip">Colour-coding works across all three views. In month view, events show as coloured bars. In week and day views, events are displayed as coloured blocks positioned at the correct time.</p>
                </div>

                <!-- Section 5: Settings (highlighted) -->
                <div class="cal-help-section cal-help-section-highlight" id="settings">
                    <div class="cal-help-section-header">
                        <span class="cal-help-section-num highlight">5</span>
                        <h3>Settings</h3>
                    </div>
                    <p class="cal-help-intro">The Settings page lets you configure how the calendar works for your team. Access it by clicking <strong>Settings</strong> in the navigation bar at the top of the calendar module.</p>

                    <div class="cal-help-steps">
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">1</div>
                            <div>
                                <strong>Manage categories</strong> &mdash; add, edit, or remove event categories. Each category has a name and a colour. Changes take effect immediately across the calendar for all users.
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">2</div>
                            <div>
                                <strong>Set colours</strong> &mdash; choose a colour for each category using the colour picker. Pick distinct colours so events are easy to tell apart on a busy calendar.
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">3</div>
                            <div>
                                <strong>Rename categories</strong> &mdash; click on a category name to edit it. Existing events assigned to that category are updated automatically.
                            </div>
                        </div>
                        <div class="cal-help-step-item">
                            <div class="cal-help-step-num">4</div>
                            <div>
                                <strong>Delete categories</strong> &mdash; remove categories you no longer need. Events in a deleted category are not removed &mdash; they remain on the calendar without a category assignment.
                            </div>
                        </div>
                    </div>

                    <p class="cal-help-tip">Keep your category list focused. Having too many categories can make the sidebar cluttered and the colour coding harder to read. Aim for 5&ndash;10 well-defined categories that cover your team's needs.</p>
                </div>

                <!-- Section 6: Quick Tips -->
                <div class="cal-help-section" id="tips">
                    <div class="cal-help-section-header">
                        <span class="cal-help-section-num">6</span>
                        <h3>Quick tips</h3>
                    </div>
                    <div class="cal-help-tips-grid">
                        <div class="cal-help-tip-card">
                            <div class="cal-help-tip-icon">&#128197;</div>
                            <div><strong>Maintenance windows</strong><br>Create all-day events or timed blocks for planned maintenance. Include the affected systems in the description so analysts can quickly check if an outage is expected.</div>
                        </div>
                        <div class="cal-help-tip-card">
                            <div class="cal-help-tip-icon">&#128274;</div>
                            <div><strong>Certificate renewals</strong><br>Add events 30 days before each certificate expires. This gives your team enough lead time to renew without risking an outage from an expired cert.</div>
                        </div>
                        <div class="cal-help-tip-card">
                            <div class="cal-help-tip-icon">&#128203;</div>
                            <div><strong>Contract tracking</strong><br>Log contract renewal dates as all-day events. Add the vendor name and contract value in the description so the information is at hand when it is time to negotiate.</div>
                        </div>
                        <div class="cal-help-tip-card">
                            <div class="cal-help-tip-icon">&#128269;</div>
                            <div><strong>Use category filters</strong><br>When the calendar gets busy, untick categories you do not need. For example, hide meetings when you are only interested in upcoming maintenance windows.</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.cal-help-nav-link');
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
