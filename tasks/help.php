<?php
/**
 * Tasks Module Help Guide — full page with left-pane scroll-spy navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'help';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Tasks Guide</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        body { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        .header { flex-shrink: 0; }

        .thp-container { display: flex; flex: 1; min-height: 0; background: #f5f5f5; }

        /* Left sidebar navigation */
        .thp-sidebar {
            width: 260px;
            background: #fff;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
            overflow-y: auto;
        }
        .thp-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .thp-nav-link {
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
        .thp-nav-link:hover { background: #f5f5f5; color: #333; }
        .thp-nav-link.active { background: #f3f0ff; color: #6d28d9; font-weight: 600; }
        .thp-nav-num {
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
        .thp-nav-link.active .thp-nav-num { background: #7c3aed; color: #fff; }

        /* Main content */
        .thp-main { flex: 1; overflow-y: auto; }

        .thp-hero {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%);
            color: #fff;
            padding: 40px 48px 36px;
            text-align: center;
        }
        .thp-hero h2 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .thp-hero p { margin: 0; font-size: 15px; opacity: 0.85; }

        .thp-content { max-width: 1120px; margin: 0 auto; padding: 10px 48px 48px; }

        /* Sections */
        .thp-section { padding: 28px 0; border-bottom: 1px solid #eee; scroll-margin-top: 20px; }
        .thp-section:last-child { border-bottom: none; padding-bottom: 0; }
        .thp-section-header { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 16px; }
        .thp-section-header h3 { margin: 0; font-size: 18px; color: #333; }
        .thp-section-header p { margin: 6px 0 0; font-size: 14px; color: #666; line-height: 1.6; }
        .thp-section > p { font-size: 14px; color: #555; line-height: 1.7; margin: 0 0 14px; }
        .thp-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f3f0ff;
            color: #6d28d9;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }
        .thp-section h4 { margin: 22px 0 8px; font-size: 14.5px; color: #333; }

        /* Feature cards grid */
        .thp-features-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .thp-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: #fff;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .thp-feature-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .thp-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }
        .thp-feature-icon.purple { background: #f3e8ff; color: #7c3aed; }
        .thp-feature-icon.blue   { background: #e3f2fd; color: #0078d4; }
        .thp-feature-icon.green  { background: #e8f5e9; color: #2e7d32; }
        .thp-feature-icon.orange { background: #fff3e0; color: #e65100; }
        .thp-feature-icon.indigo { background: #e0e7ff; color: #4338ca; }
        .thp-feature-icon.teal   { background: #e0f2f1; color: #00695c; }
        .thp-feature-icon.red    { background: #fce4ec; color: #c62828; }
        .thp-feature-card h4 { margin: 0 0 6px; font-size: 15px; color: #333; }
        .thp-feature-card p { margin: 0; font-size: 12.5px; color: #666; line-height: 1.5; }

        /* Numbered steps */
        .thp-steps { display: flex; flex-direction: column; gap: 12px; margin-left: 46px; }
        .thp-step-item {
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
        .thp-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #7c3aed;
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Definition-style list */
        .thp-fields { display: flex; flex-direction: column; gap: 8px; }
        .thp-fields > div {
            font-size: 13.5px;
            color: #555;
            line-height: 1.6;
            padding: 8px 12px;
            background: #fafafa;
            border-radius: 6px;
        }
        .thp-fields strong { color: #333; }

        /* Tip callout */
        .thp-tip {
            font-size: 13px !important;
            color: #6d28d9 !important;
            background: #f3f0ff;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #7c3aed;
            margin-top: 12px !important;
        }

        /* Quick tips grid */
        .thp-tips-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .thp-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }
        .thp-tip-icon { font-size: 22px; flex-shrink: 0; }
        .thp-tip-card strong { color: #333; }

        @media (max-width: 900px) {
            .thp-sidebar { display: none; }
            .thp-content { padding: 10px 24px 40px; }
            .thp-hero { padding: 30px 24px; }
        }
        @media (max-width: 700px) {
            .thp-features-grid { grid-template-columns: 1fr; }
            .thp-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="thp-container">
        <!-- Left pane navigation -->
        <div class="thp-sidebar">
            <h3>Guide</h3>
            <a href="#overview" class="thp-nav-link active" data-section="overview">
                <span class="thp-nav-num">1</span> Overview
            </a>
            <a href="#board" class="thp-nav-link" data-section="board">
                <span class="thp-nav-num">2</span> The board
            </a>
            <a href="#list" class="thp-nav-link" data-section="list">
                <span class="thp-nav-num">3</span> List view
            </a>
            <a href="#calendar" class="thp-nav-link" data-section="calendar">
                <span class="thp-nav-num">4</span> Calendar view
            </a>
            <a href="#timeline" class="thp-nav-link" data-section="timeline">
                <span class="thp-nav-num">5</span> Timeline view
            </a>
            <a href="#table" class="thp-nav-link" data-section="table">
                <span class="thp-nav-num">6</span> Table view
            </a>
            <a href="#panel" class="thp-nav-link" data-section="panel">
                <span class="thp-nav-num">7</span> The task panel
            </a>
            <a href="#tags" class="thp-nav-link" data-section="tags">
                <span class="thp-nav-num">8</span> Tags
            </a>
            <a href="#settings" class="thp-nav-link" data-section="settings">
                <span class="thp-nav-num">9</span> Settings
            </a>
            <a href="#tips" class="thp-nav-link" data-section="tips">
                <span class="thp-nav-num">10</span> Quick tips
            </a>
        </div>

        <!-- Main content area -->
        <div class="thp-main" id="helpMain">
            <div class="thp-hero">
                <h2>Tasks module guide</h2>
                <p>Plan, assign, and track internal work on a Kanban board &mdash; with calendar, timeline, tags, and per-status columns.</p>
            </div>

            <div class="thp-content">

                <!-- 1. Overview -->
                <div class="thp-section" id="overview">
                    <div class="thp-section-header">
                        <span class="thp-section-num">1</span>
                        <div>
                            <h3>Overview</h3>
                            <p>The Tasks module is a lightweight work tracker for your service desk team. Use it for the jobs that aren't tickets &mdash; project work, internal admin, recurring chores, follow-ups &mdash; and see them as a Kanban board, a sortable list, a calendar, a Gantt-style timeline, or a full-screen editable spreadsheet. Tasks can link to tickets and changes, carry tags, and be broken into subtasks.</p>
                        </div>
                    </div>
                    <div class="thp-features-grid">
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>
                            </div>
                            <h4>Board</h4>
                            <p>A Kanban board with one column per status. Drag cards between columns to change status, drag column headers to reorder, and quick-add tasks straight into a column.</p>
                        </div>
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                            </div>
                            <h4>List view</h4>
                            <p>The same tasks as a sortable table &mdash; click any column header to sort by title, status, priority, assignee, team, or due date.</p>
                        </div>
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </div>
                            <h4>Calendar</h4>
                            <p>A month grid placing tasks by due date. Multi-day tasks can show as a single chip, a spanning bar, or a chip on every day &mdash; your choice in Settings.</p>
                        </div>
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="14" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="5" y1="18" x2="16" y2="18"></line></svg>
                            </div>
                            <h4>Timeline</h4>
                            <p>A Gantt-style chart showing each task as a bar from its start date to its due date. Drag bars to reschedule, drag the edges to set start and due dates.</p>
                        </div>
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon indigo">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="3" y1="15" x2="21" y2="15"></line><line x1="9" y1="3" x2="9" y2="21"></line><line x1="15" y1="3" x2="15" y2="21"></line></svg>
                            </div>
                            <h4>Table</h4>
                            <p>Excel-style grid with inline cell editing &mdash; tweak status, priority, assignee, team or dates straight in the row. Columns, sort, filter and search persist per analyst.</p>
                        </div>
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon teal">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
                            </div>
                            <h4>Tags</h4>
                            <p>Multi-value labels for cross-cutting themes &mdash; Security, ISO, Environment &mdash; that you can filter and search by.</p>
                        </div>
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon red">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            </div>
                            <h4>Settings</h4>
                            <p>Configure the statuses, priorities and tags; control the calendar's multi-day rendering and what each card shows.</p>
                        </div>
                    </div>
                    <p class="thp-tip">The board, list, calendar and timeline are just different windows onto the same tasks. A change made in one is reflected in all of them.</p>
                </div>

                <!-- 2. The board -->
                <div class="thp-section" id="board">
                    <div class="thp-section-header">
                        <span class="thp-section-num">2</span>
                        <div>
                            <h3>The board</h3>
                            <p>The board is the default view &mdash; a Kanban layout with one column for each status. It's the fastest way to see where everything stands and to move work along.</p>
                        </div>
                    </div>

                    <h4>Columns are statuses</h4>
                    <p>Each column is a status defined in Settings &rarr; Statuses, shown in display order with the status's colour as a header dot. Add a status and it becomes a column; there's no separate "column" concept to manage. To reorder the columns, just <strong>drag a column header</strong> left or right &mdash; the new order is saved.</p>

                    <h4>Creating tasks</h4>
                    <div class="thp-steps">
                        <div class="thp-step-item">
                            <div class="thp-step-num">1</div>
                            <div><strong>Click the + on a column header</strong> &mdash; an inline box opens at the top of that column.</div>
                        </div>
                        <div class="thp-step-item">
                            <div class="thp-step-num">2</div>
                            <div><strong>Type a title and press Enter</strong> &mdash; the task is created in that column's status and assigned to you. Click it any time to fill in the rest.</div>
                        </div>
                    </div>

                    <h4>Moving and reordering</h4>
                    <p>Drag a card to another column to change its status; drag it up or down within a column to reorder. A purple line shows where it will land. Dropping a card into a status flagged as <em>Closed</em> stamps its completion time automatically.</p>

                    <h4>Right-click a card</h4>
                    <p>Right-clicking any card opens a quick-action menu &mdash; assign an analyst or team, change status or priority, or create a subtask &mdash; without opening the card at all.</p>

                    <h4>Search and filters</h4>
                    <p>The sidebar carries a <strong>Search</strong> box (filters as you type, matching the title and description &mdash; and tag names if enabled) plus filters for <strong>My Tasks / All Tasks</strong>, <strong>Team</strong>, <strong>Analyst</strong> and, when enabled, <strong>Tag</strong>. Filters and search apply to the list view too.</p>
                    <p class="thp-tip">Choose what each card shows &mdash; priority, assignee, team, dates, a description excerpt, subtask progress, tags &mdash; under Settings &rarr; Card, so you can scan tasks without opening them.</p>
                </div>

                <!-- 3. List view -->
                <div class="thp-section" id="list">
                    <div class="thp-section-header">
                        <span class="thp-section-num">3</span>
                        <div>
                            <h3>List view</h3>
                            <p>The list is the board's data twin &mdash; the same tasks as a sortable table. Switch to it with the View toggle in the sidebar.</p>
                        </div>
                    </div>
                    <p>Click any column header &mdash; Title, Status, Priority, Assignee, Team, Due &mdash; to sort by it; click again to reverse the direction. The search and sidebar filters work exactly as they do on the board, so you can narrow the list down and then sort what's left. Click a row to open the task.</p>
                    <p class="thp-tip">The list is the place to find a task whose status has no board column (for example a custom "Icebox" status) &mdash; the list shows every task regardless of status.</p>
                </div>

                <!-- 4. Calendar view -->
                <div class="thp-section" id="calendar">
                    <div class="thp-section-header">
                        <span class="thp-section-num">4</span>
                        <div>
                            <h3>Calendar view</h3>
                            <p>The calendar places tasks on a date grid so you can see deadlines and workload at a glance. Tasks are coloured by their status.</p>
                        </div>
                    </div>
                    <p>The toolbar has <strong>Today</strong>, prev / next, and a <strong>Month / Week / Day</strong> view toggle on the right. <em>Month</em> is the familiar 4&ndash;6 week grid; <em>Week</em> zooms in on a single seven-day row that stretches to fill the screen; <em>Day</em> drops to a single date and lists every task occurring on it as a card row. Prev / next steps by month, week or day to match whichever view is active.</p>
                    <p>The sidebar shows a colour legend, the same My / All / Team / Analyst filters as the board, and a <strong>Span mode</strong> panel with a Change link straight to the Calendar settings.</p>
                    <h4>Clicking a task</h4>
                    <p>Clicking a task chip, bar or day-view row opens a slide-in <strong>quick panel</strong> on the right of the calendar &mdash; you stay where you are. Title, status, priority, assignee, team and dates are all editable in the panel; the launch icon at the top opens the dashboard's full editor when you need rich-text description, subtasks or comments.</p>
                    <h4>How multi-day tasks are drawn</h4>
                    <p>A task with a start date earlier than its due date covers a range of days. How that range is shown is set once, per install, in Settings &rarr; Calendar:</p>
                    <div class="thp-fields">
                        <div><strong>Deadline chip</strong> &mdash; one chip on the due date only. The tidiest option; the full span is still visible on the timeline.</div>
                        <div><strong>Spanning bar</strong> &mdash; one continuous bar across the whole range, wrapping at week rows. Best for seeing duration.</div>
                        <div><strong>Every day</strong> &mdash; a chip in every day cell the task covers. Thorough, but long tasks can crowd the grid.</div>
                    </div>
                    <p style="margin-top:14px;">A task that has only a due date always shows as a single chip on that date, whichever mode is chosen.</p>
                </div>

                <!-- 5. Timeline view -->
                <div class="thp-section" id="timeline">
                    <div class="thp-section-header">
                        <span class="thp-section-num">5</span>
                        <div>
                            <h3>Timeline view</h3>
                            <p>The timeline is a Gantt-style chart. Each task is a horizontal bar running from its start date to its due date, so you can see overlaps, gaps and durations.</p>
                        </div>
                    </div>
                    <p>A task with only a due date shows as a single-day bar. Rows can be grouped by <strong>Assignee</strong>, <strong>Status</strong>, or shown flat &mdash; pick from the sidebar. A dashed marker shows today, the task-name column and date header stay put while you scroll, and the day grid auto-fits the screen: short ranges stretch edge-to-edge, longer ones hold each day at a comfortable size and scroll horizontally.</p>

                    <h4>Drag bars to reschedule</h4>
                    <p>The bars are live editors. Hover one and translucent grips appear on either end:</p>
                    <div class="thp-fields">
                        <div><strong>Drag the bar body</strong> &mdash; both the start and due dates shift by the same number of days. The task moves bodily without changing duration.</div>
                        <div><strong>Drag the left edge</strong> &mdash; only the start date changes. Useful for pulling the start back earlier or pushing it later, while the deadline stays put.</div>
                        <div><strong>Drag the right edge</strong> &mdash; only the due date changes. On a deadline-only task (no start date set), the old due date becomes the new start date, so the bar grows into a proper range rather than jumping to a single-day position at the new end.</div>
                    </div>
                    <p style="margin-top:14px;">Drags snap to whole days. A short, non-drag click on a bar still opens the task &mdash; below a 3px movement threshold it's treated as a click, not a drag.</p>

                    <h4>Right-click for quick actions</h4>
                    <p>Right-click any bar for the same context menu the board uses &mdash; <strong>Assign analyst</strong>, <strong>Assign team</strong>, <strong>Change status</strong>, <strong>Change priority</strong> &mdash; without opening anything. Each submenu marks the task's current value with a tick.</p>

                    <h4>Clicking a bar</h4>
                    <p>A normal click on a bar (or on the task-name column on the left) opens the same slide-in quick panel the calendar uses, on the right of the timeline. Edit the everyday fields in place, or hit the launch icon at the top of the panel to jump to the dashboard's full editor.</p>
                </div>

                <!-- 6. Table view -->
                <div class="thp-section" id="table">
                    <div class="thp-section-header">
                        <span class="thp-section-num">6</span>
                        <div>
                            <h3>Table view</h3>
                            <p>The Table view is a full-screen Excel-style grid &mdash; one row per task, with editable cells. It's the fastest place to triage a list, bulk-tweak fields, or build the exact column layout you want to live in.</p>
                        </div>
                    </div>

                    <h4>Edit straight in the cell</h4>
                    <p>Most columns render an editable control instead of static text. Pick a new status or priority from its dropdown, swap the assignee or team, type a new title, type or pick a new start / due date &mdash; each cell saves on change with no Save button to hunt for. Status and priority show a coloured dot that updates in place after the save.</p>

                    <h4>Columns: show, hide, reorder</h4>
                    <p>Click the <strong>Columns</strong> button on the toolbar to open a drawer with every column the table knows about. Tick the ones you want visible; drag the rows to reorder. The drag handle on a column header also reorders columns directly. Your visibility and order are saved to your account, so the next time you open the table it's the way you left it.</p>

                    <h4>Sort, filter, search</h4>
                    <div class="thp-fields">
                        <div><strong>Sort</strong> &mdash; click any column header to sort by it. Click again to flip the direction. The active sort is saved per analyst alongside the column layout.</div>
                        <div><strong>Filter</strong> &mdash; click the funnel icon on a column header to pop up an Excel-style tickbox list of every value that column actually contains. Untick the ones you don't want; counts in the list show how many rows match each value. Active filters show the funnel in violet.</div>
                        <div><strong>Search</strong> &mdash; the toolbar search box matches every visible column's text. It filters as you type.</div>
                        <div><strong>Reset</strong> &mdash; the toolbar Reset button clears all filters, the search box, and resets the sort.</div>
                    </div>

                    <h4>Right-click and export</h4>
                    <p>Right-clicking a row opens the same context menu the board and timeline use &mdash; Assign analyst / team, Change status / priority. The <strong>CSV</strong> button on the toolbar downloads the current view (respecting visible columns, search, filters and sort) as a UTF-8 CSV that Excel opens cleanly.</p>

                    <p class="thp-tip">Sort and filter <em>don't</em> rerun while you're editing a cell &mdash; they'd steal your focus. Your edit is saved straight away; when you next change the sort, change a filter, or hit Reset, the new value is what gets used.</p>
                </div>

                <!-- 7. The task panel -->
                <div class="thp-section" id="panel">
                    <div class="thp-section-header">
                        <span class="thp-section-num">7</span>
                        <div>
                            <h3>The task panel</h3>
                            <p>Clicking a task opens a slide-in panel on the right. Every field auto-saves the moment you change it &mdash; there's no Save button. The board's panel is the full editor; the calendar and timeline open a lighter <em>quick panel</em> with the everyday fields and a launch icon that jumps to the full editor when you need rich-text description, subtasks or comments.</p>
                        </div>
                    </div>
                    <div class="thp-fields">
                        <div><strong>Title</strong> &mdash; click and type at the top of the panel.</div>
                        <div><strong>Status &amp; Priority</strong> &mdash; dropdowns built from your configured lookups.</div>
                        <div><strong>Assignee &amp; Team</strong> &mdash; who owns the task and which team it belongs to.</div>
                        <div><strong>Start &amp; Due dates</strong> &mdash; together they define the span shown on the calendar and timeline.</div>
                        <div><strong>Tags</strong> &mdash; a type-to-filter picker; add as many as you like, remove with the &times; on each chip.</div>
                        <div><strong>Description</strong> &mdash; a rich-text editor with bold, lists and links.</div>
                        <div><strong>Links</strong> &mdash; search and attach related tickets or changes.</div>
                        <div><strong>Subtasks</strong> &mdash; break the task into a checklist; progress shows on the parent card.</div>
                        <div><strong>Comments</strong> &mdash; a running discussion thread on the task.</div>
                    </div>
                    <p style="margin-top:14px;">The delete button (top-right of the panel) removes the task and all of its subtasks &mdash; it asks for confirmation first.</p>
                </div>

                <!-- 8. Tags -->
                <div class="thp-section" id="tags">
                    <div class="thp-section-header">
                        <span class="thp-section-num">8</span>
                        <div>
                            <h3>Tags</h3>
                            <p>Tags are multi-value labels for cross-cutting themes &mdash; the things that don't fit a status or a priority. A task can carry any number of them.</p>
                        </div>
                    </div>
                    <p>Status answers "what stage is this at?", priority answers "how urgent?", and a tag answers "what is this <em>about</em>?" &mdash; Security, ISO, Environment, and so on. Because a task can hold several tags, you can slice your work by theme across every status and owner.</p>
                    <p>Tags are managed in Settings &rarr; Tags, each with a name and colour. Five display options there control whether tags can be created from a task and where they appear &mdash; card chips, the sidebar filter, search matching, and the calendar/timeline.</p>
                    <p class="thp-tip">A tag is the right home for a "someday / maybe" theme. Tag a handful of ideas as <em>Blue Sky</em> and you can pull them up any time with the tag filter, without a dedicated column.</p>
                </div>

                <!-- 9. Settings -->
                <div class="thp-section" id="settings">
                    <div class="thp-section-header">
                        <span class="thp-section-num">9</span>
                        <div>
                            <h3>Settings</h3>
                            <p>The Settings page has five tabs &mdash; Statuses, Priorities, Calendar, Card and Tags. Settings are install-wide: a change here shapes the board, calendar and cards for the whole team, not just for you.</p>
                        </div>
                    </div>

                    <h4>Statuses &amp; Priorities</h4>
                    <p>These two tabs manage the lookup lists every task draws from. Each row has a name, a colour and a display order, and you add, edit or delete rows with the buttons on the right.</p>
                    <div class="thp-fields">
                        <div><strong>Statuses</strong> &mdash; the workflow states, which double as the board columns (shown in display order). One status is the <em>default</em> for new tasks. A status flagged as <em>Closed</em> counts as done &mdash; tasks moved into it stamp a completion time and drop out of open-task counters. A status can also be made inactive to retire it without losing history.</div>
                        <div><strong>Priorities</strong> &mdash; the priority bands shown as a coloured dot on each card. One priority is the default for new tasks.</div>
                    </div>

                    <h4>Card</h4>
                    <p>The Card tab is a set of checkboxes controlling what extra detail appears on each board and list card, so you can scan tasks without opening them: priority dot, assignee initials, team, start date, due date, a description excerpt, subtask progress, and the linked-item indicator. The task title always shows. Changes take effect the next time the board loads.</p>

                    <h4>Calendar options</h4>
                    <p>The Calendar tab has a single decision: how a <strong>multi-day task</strong> &mdash; one whose start date is earlier than its due date &mdash; is drawn on the Tasks calendar. Pick one of three modes:</p>
                    <div class="thp-fields">
                        <div><strong>Deadline chip</strong> (the default) &mdash; the task appears once, as a single chip on its due date. This keeps the calendar focused on what needs finishing rather than on durations, and is the tidiest choice when you have lots of tasks. The full span is still visible on the Timeline.</div>
                        <div><strong>Spanning bar</strong> &mdash; the task is drawn as one continuous bar running from its start date to its due date, wrapping onto the next row at the end of each week. Bars stack into lanes so they never overlap. Best when seeing how long things take, and what overlaps, matters more than a tidy grid.</div>
                        <div><strong>Every day</strong> &mdash; a chip is repeated in every single day cell the task covers. The most thorough &mdash; the task is impossible to miss on any given day &mdash; but a few long tasks can crowd the grid quickly.</div>
                    </div>
                    <p style="margin-top:14px;">Whichever mode you pick, a task that has <em>only</em> a due date (no start date) always shows as a single chip on that date &mdash; the modes only change how a genuine date <em>range</em> is drawn. The calendar itself shows the current mode in its toolbar, with a <strong>Change</strong> link straight back to this tab.</p>

                    <h4>Tag options</h4>
                    <p>The Tags tab does two jobs. The top half is the <strong>tag list</strong> &mdash; add, edit, delete and recolour the tags your team can apply (each is simply a name and a colour). The bottom half, <strong>Display options</strong>, is five toggles that decide how tagging behaves and where tags surface:</p>
                    <div class="thp-fields">
                        <div><strong>Allow new tags from a task</strong> (off by default) &mdash; the managed-vs-open switch. Off, the tag list is a fixed vocabulary: analysts can only pick existing tags, and new ones are added here in Settings &mdash; best for a governed set like Security or ISO. On, the tag picker on a task also lets an analyst type and create a brand-new tag on the spot.</div>
                        <div><strong>Card chips</strong> &mdash; show coloured tag chips on board and list cards. Turn off if you'd rather keep cards minimal.</div>
                        <div><strong>Sidebar filter</strong> &mdash; show a Tag dropdown in the board/list sidebar so you can narrow the view to a single tag.</div>
                        <div><strong>Search matching</strong> &mdash; include tag names in what the search box matches, so typing a tag name finds every task carrying it.</div>
                        <div><strong>Calendar &amp; timeline</strong> &mdash; show small tag dots on task bars in the calendar and timeline views. Off by default, since those views are date-focused.</div>
                    </div>
                    <p style="margin-top:14px;">The toggles are independent &mdash; you can, for example, keep tags off the cards but still filter and search by them. Tag-display changes take effect the next time a view loads.</p>
                </div>

                <!-- 10. Quick tips -->
                <div class="thp-section" id="tips">
                    <div class="thp-section-header">
                        <span class="thp-section-num">10</span>
                        <div>
                            <h3>Quick tips</h3>
                            <p>A few shortcuts and habits that make the module quicker to live in.</p>
                        </div>
                    </div>
                    <div class="thp-tips-grid">
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">🖱️</span>
                            <div><strong>Right-click is faster.</strong> Re-assign or re-prioritise a card from its context menu without opening it.</div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">⌨️</span>
                            <div><strong>Quick-add, then Enter.</strong> Capture a task title with the column + button and flesh it out later.</div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">🔍</span>
                            <div><strong>Search is instant.</strong> It filters as you type and matches every word &mdash; no need to wait or press Enter.</div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">🏷️</span>
                            <div><strong>Tag for themes.</strong> Use tags for anything that cuts across statuses &mdash; compliance, security, projects.</div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">📅</span>
                            <div><strong>Two dates, two views.</strong> Set a start and a due date and the task draws itself on both the calendar and the timeline.</div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">🔗</span>
                            <div><strong>Link the source.</strong> Attach the ticket or change a task came from, so the context is one click away.</div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">✋</span>
                            <div><strong>Drag the bar.</strong> On the timeline, drag the body to reschedule and drag either edge to set the start or due date directly &mdash; no panel needed.</div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">📊</span>
                            <div><strong>Build your own table.</strong> On the Table view, pick which columns to show, drag them into the order you want, sort and filter &mdash; your layout is saved per analyst.</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight the active section in the sidebar as the user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.thp-nav-link');
        const sections = [];

        navLinks.forEach(link => {
            const el = document.getElementById(link.dataset.section);
            if (el) sections.push({ id: link.dataset.section, el });
        });

        helpMain.addEventListener('scroll', function () {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0] && sections[0].id;
            for (const s of sections) {
                if (s.el.offsetTop - 200 <= scrollTop) current = s.id;
            }
            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
        });

        navLinks.forEach(link => {
            link.addEventListener('click', function (e) {
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
