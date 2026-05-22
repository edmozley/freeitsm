<?php
/**
 * Tasks Module — Calendar View
 */
session_start();
require_once '../../config.php';

$current_page = 'calendar';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Tasks Calendar</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <link rel="stylesheet" href="../../assets/css/tasks.css">
</head>
<body data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>">
    <?php include '../includes/header.php'; ?>

    <div class="tasks-container">
        <!-- Sidebar -->
        <div class="tasks-sidebar">
            <div class="sidebar-section">
                <div class="sidebar-label">Filter</div>
                <button class="filter-btn active" data-filter="my" onclick="setFilter('my')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    My Tasks
                </button>
                <button class="filter-btn" data-filter="all" onclick="setFilter('all')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    All Tasks
                </button>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label">Team</div>
                <select id="teamFilter" class="sidebar-select" onchange="setTeamFilter(this.value)">
                    <option value="">All teams</option>
                </select>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label">Analyst</div>
                <select id="analystFilter" class="sidebar-select" onchange="setAnalystFilter(this.value)">
                    <option value="">All analysts</option>
                </select>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label">Legend</div>
                <div class="cal-legend" id="calLegend"></div>
            </div>
        </div>

        <!-- Main content -->
        <div class="tasks-main">
            <div class="cal-layout">
                <div class="cal-toolbar">
                    <div class="cal-nav">
                        <button class="cal-nav-btn" onclick="calPrev()" title="Previous month">&lsaquo;</button>
                        <button class="cal-nav-btn cal-today-btn" onclick="calToday()">Today</button>
                        <button class="cal-nav-btn" onclick="calNext()" title="Next month">&rsaquo;</button>
                        <h2 id="calTitle">&nbsp;</h2>
                    </div>
                    <div class="cal-mode-hint">
                        <span id="calModeHint"></span>
                        <a href="../settings/#calendar">Change</a>
                    </div>
                </div>
                <div class="cal-wrap">
                    <div class="cal-weekdays">
                        <div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div>
                        <div>Fri</div><div>Sat</div><div>Sun</div>
                    </div>
                    <div class="cal-grid" id="calGrid">
                        <div class="cal-loading">Loading…</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>window.API_BASE = '../../api/tasks/';</script>
    <script src="../../assets/js/tasks-calendar.js"></script>
</body>
</html>
