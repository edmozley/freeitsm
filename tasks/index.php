<?php
/**
 * Tasks Module — Kanban Board & List View
 */
session_start();
require_once '../config.php';

$current_page = 'board';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Tasks</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/tasks.css">
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
</head>
<body data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>">
    <?php include 'includes/header.php'; ?>

    <div class="tasks-container">
        <!-- Sidebar -->
        <div class="tasks-sidebar">
            <div class="sidebar-section">
                <div class="sidebar-label">View</div>
                <div class="view-toggle">
                    <button class="view-btn active" data-view="board" onclick="switchView('board')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>
                        Board
                    </button>
                    <button class="view-btn" data-view="list" onclick="switchView('list')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        List
                    </button>
                </div>
            </div>

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
        </div>

        <!-- Main content -->
        <div class="tasks-main">
            <!-- Board view -->
            <div id="boardView" class="board-view">
                <div class="board-column" data-status="To Do">
                    <div class="board-column-header">
                        <span class="column-title">To Do</span>
                        <span class="column-count" id="countTodo">0</span>
                        <button class="column-add-btn" onclick="showQuickAdd('To Do')" title="Add">+</button>
                    </div>
                    <div class="quick-add-container" id="quickAdd-To Do" style="display:none;">
                        <input type="text" class="quick-add-input" placeholder="Task title..." onkeydown="handleQuickAdd(event, 'To Do')">
                    </div>
                    <div class="board-cards" id="cards-To Do"></div>
                </div>
                <div class="board-column" data-status="In Progress">
                    <div class="board-column-header">
                        <span class="column-title">In Progress</span>
                        <span class="column-count" id="countInProgress">0</span>
                        <button class="column-add-btn" onclick="showQuickAdd('In Progress')" title="Add">+</button>
                    </div>
                    <div class="quick-add-container" id="quickAdd-In Progress" style="display:none;">
                        <input type="text" class="quick-add-input" placeholder="Task title..." onkeydown="handleQuickAdd(event, 'In Progress')">
                    </div>
                    <div class="board-cards" id="cards-In Progress"></div>
                </div>
                <div class="board-column" data-status="Done">
                    <div class="board-column-header">
                        <span class="column-title">Done</span>
                        <span class="column-count" id="countDone">0</span>
                        <button class="column-add-btn" onclick="showQuickAdd('Done')" title="Add">+</button>
                    </div>
                    <div class="quick-add-container" id="quickAdd-Done" style="display:none;">
                        <input type="text" class="quick-add-input" placeholder="Task title..." onkeydown="handleQuickAdd(event, 'Done')">
                    </div>
                    <div class="board-cards" id="cards-Done"></div>
                </div>
            </div>

            <!-- List view -->
            <div id="listView" class="list-view" style="display:none;">
                <table class="task-table">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="title" onclick="sortList('title')">Title</th>
                            <th class="sortable" data-sort="status" onclick="sortList('status')">Status</th>
                            <th class="sortable" data-sort="priority" onclick="sortList('priority')">Priority</th>
                            <th class="sortable" data-sort="analyst_name" onclick="sortList('analyst_name')">Assignee</th>
                            <th class="sortable" data-sort="team_name" onclick="sortList('team_name')">Team</th>
                            <th class="sortable" data-sort="due_date" onclick="sortList('due_date')">Due</th>
                            <th>Subtasks</th>
                        </tr>
                    </thead>
                    <tbody id="listTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Detail panel overlay -->
    <div class="detail-overlay" id="detailOverlay" onclick="closeDetailPanel()"></div>

    <!-- Detail panel -->
    <div class="detail-panel" id="detailPanel">
        <div class="detail-panel-header">
            <h3>Task Details</h3>
            <div class="detail-panel-actions">
                <button class="btn-icon" onclick="deleteCurrentTask()" title="Delete">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </button>
                <button class="btn-icon" onclick="closeDetailPanel()" title="Close">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
        </div>
        <div class="detail-panel-body" id="detailPanelBody">
            <!-- Populated by JS -->
        </div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <script>window.API_BASE = '../api/tasks/';</script>
    <script src="../assets/js/tasks.js"></script>
</body>
</html>
