<?php
/**
 * Tasks Module — Full-screen table view
 *
 * Thin page over the shared data-table engine (assets/js/data-table.js +
 * assets/css/data-table.css). Tasks keeps its My/Team/Analyst sidebar and the
 * shared right-click context menu; the columns + inline-edit saves live in
 * assets/js/tasks-table.js.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

$current_page = 'table';
$path_prefix = '../../';
$translationNamespaces = ['common', 'tasks'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('tasks.title') . ' ' . t('tasks.nav.table')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <link rel="stylesheet" href="../../assets/css/tasks.css?v=14">
    <link rel="stylesheet" href="../../assets/css/data-table.css?v=1">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
</head>
<body data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>">
    <?php include '../includes/header.php'; ?>

    <div class="tasks-container">
        <!-- Sidebar -->
        <div class="tasks-sidebar">
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
        </div>

        <!-- Main: shared data-table -->
        <div class="tasks-main">
            <?php include '../../includes/data-table-skeleton.php'; ?>
        </div>
    </div>

    <!-- Shared right-click context menu (same markup contract as dashboard + timeline) -->
    <div class="ctx-menu" id="ctxMenu">
        <div class="ctx-item ctx-has-sub">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.assign_analyst')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxAnalyst"></div>
        </div>
        <div class="ctx-item ctx-has-sub">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.assign_team')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxTeam"></div>
        </div>
        <div class="ctx-item ctx-has-sub">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.change_status')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxStatus"></div>
        </div>
        <div class="ctx-item ctx-has-sub">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.change_priority')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxPriority"></div>
        </div>
    </div>

    <script src="../../assets/js/data-table.js?v=1"></script>
    <script src="../../assets/js/tasks-ctx-menu.js?v=1"></script>
    <script src="../../assets/js/tasks-table.js?v=2"></script>
</body>
</html>
