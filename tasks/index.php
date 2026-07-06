<?php
/**
 * Tasks Module — Kanban Board & List View
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'board';
$path_prefix = '../';
$translationNamespaces = ['common', 'tasks'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('tasks.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/tasks.css?v=10">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
</head>
<body data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>">
    <?php include 'includes/header.php'; ?>

    <div class="tasks-container">
        <!-- Sidebar -->
        <div class="tasks-sidebar">
            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.search')); ?></div>
                <div class="search-box">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" id="taskSearch" class="search-input" placeholder="<?php echo htmlspecialchars(t('tasks.search.placeholder')); ?>" oninput="setSearch(this.value)" autocomplete="off">
                    <button type="button" class="search-clear" id="searchClear" onclick="clearSearch()" title="<?php echo htmlspecialchars(t('tasks.search.clear')); ?>">&times;</button>
                </div>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.view')); ?></div>
                <div class="view-toggle">
                    <button class="view-btn active" data-view="board" onclick="switchView('board')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>
                        <?php echo htmlspecialchars(t('tasks.view.board')); ?>
                    </button>
                    <button class="view-btn" data-view="list" onclick="switchView('list')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        <?php echo htmlspecialchars(t('tasks.view.list')); ?>
                    </button>
                </div>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.filter')); ?></div>
                <button class="filter-btn active" data-filter="my" onclick="setFilter('my')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    <?php echo htmlspecialchars(t('tasks.filter.my')); ?>
                </button>
                <button class="filter-btn" data-filter="all" onclick="setFilter('all')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    <?php echo htmlspecialchars(t('tasks.filter.all')); ?>
                </button>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.team')); ?></div>
                <select id="teamFilter" class="sidebar-select" onchange="setTeamFilter(this.value)">
                    <option value=""><?php echo htmlspecialchars(t('tasks.filter.all_teams')); ?></option>
                </select>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.analyst')); ?></div>
                <select id="analystFilter" class="sidebar-select" onchange="setAnalystFilter(this.value)">
                    <option value=""><?php echo htmlspecialchars(t('tasks.filter.all_analysts')); ?></option>
                </select>
            </div>

            <div class="sidebar-section" id="tagFilterSection" style="display:none;">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.tag')); ?></div>
                <select id="tagFilter" class="sidebar-select" onchange="setTagFilter(this.value)">
                    <option value=""><?php echo htmlspecialchars(t('tasks.filter.all_tags')); ?></option>
                </select>
            </div>
        </div>

        <!-- Main content -->
        <div class="tasks-main">
            <!-- Board view — one column per status, generated by tasks.js -->
            <div id="boardView" class="board-view"></div>

            <!-- List view -->
            <div id="listView" class="list-view" style="display:none;">
                <table class="task-table">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="title" onclick="sortList('title')"><?php echo htmlspecialchars(t('tasks.list.col_title')); ?></th>
                            <th class="sortable" data-sort="status" onclick="sortList('status')"><?php echo htmlspecialchars(t('tasks.list.col_status')); ?></th>
                            <th class="sortable" data-sort="priority" onclick="sortList('priority')"><?php echo htmlspecialchars(t('tasks.list.col_priority')); ?></th>
                            <th class="sortable" data-sort="analyst_name" onclick="sortList('analyst_name')"><?php echo htmlspecialchars(t('tasks.list.col_assignee')); ?></th>
                            <th class="sortable" data-sort="team_name" onclick="sortList('team_name')"><?php echo htmlspecialchars(t('tasks.list.col_team')); ?></th>
                            <th class="sortable" data-sort="due_date" onclick="sortList('due_date')"><?php echo htmlspecialchars(t('tasks.list.col_due')); ?></th>
                            <th><?php echo htmlspecialchars(t('tasks.list.col_subtasks')); ?></th>
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
            <h3><?php echo htmlspecialchars(t('tasks.detail.heading')); ?></h3>
            <div class="detail-panel-actions">
                <button class="btn-icon" onclick="deleteCurrentTask()" title="<?php echo htmlspecialchars(t('tasks.detail.delete')); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </button>
                <button class="btn-icon" onclick="closeDetailPanel()" title="<?php echo htmlspecialchars(t('tasks.detail.close')); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
        </div>
        <div class="detail-panel-body" id="detailPanelBody">
            <!-- Populated by JS -->
        </div>
    </div>

    <!-- Card right-click context menu -->
    <div class="ctx-menu" id="ctxMenu">
        <div class="ctx-item ctx-has-sub" data-action="analyst">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.assign_analyst')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxAnalyst"></div>
        </div>
        <div class="ctx-item ctx-has-sub" data-action="team">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.assign_team')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxTeam"></div>
        </div>
        <div class="ctx-item ctx-has-sub" data-action="status">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.change_status')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxStatus"></div>
        </div>
        <div class="ctx-item ctx-has-sub" data-action="priority">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.change_priority')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxPriority"></div>
        </div>
        <div class="ctx-sep"></div>
        <div class="ctx-item" data-action="subtask">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.create_subtask')); ?></span>
        </div>
    </div>

    <!-- Toast -->
    <script>window.API_BASE = '../api/tasks/';</script>
    <script src="../assets/js/tasks-ctx-menu.js?v=1"></script>
    <script src="../assets/js/tasks.js?v=11"></script>
</body>
</html>
