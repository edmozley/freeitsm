<?php
/**
 * Tasks Module — Timeline (Gantt) View
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('tasks');
// Read only access to Tasks (#181 Part G): the page offers nothing that writes. The
// services and endpoints refuse regardless - this only stops the page offering it.
$tasksReadOnly = analystModuleLevel(connectToDatabase(), (int)$_SESSION['analyst_id'], 'tasks') === 'read';

$current_page = 'timeline';
$path_prefix = '../../';
$translationNamespaces = ['common', 'tasks'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('tasks', t('tasks.nav.timeline'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../../assets/css/tasks.css?v=58">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>"<?php echo $tasksReadOnly ? ' class="module-read-only"' : ''; ?>>
    <?php include '../includes/header.php'; ?>
    <?php /* The action row (#185). Tasks had no general New - only the + at the
             top of each board column, which stays as a shortcut that already
             knows the status. New asks for a title and opens the task. */
          require_once __DIR__ . '/../../includes/action_row.php';
          renderActionRow(t('tasks.title'), $tasksReadOnly ? [] : [actionRowNew('../?new=1', t('tasks.board.new_task'), true)], ['accent' => 'tsk']); ?>

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

            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.group_by')); ?></div>
                <select id="groupBy" class="sidebar-select" onchange="setGroupBy(this.value)">
                    <option value="analyst"><?php echo htmlspecialchars(t('tasks.timeline.group_assignee')); ?></option>
                    <option value="status"><?php echo htmlspecialchars(t('tasks.timeline.group_status')); ?></option>
                    <option value="none"><?php echo htmlspecialchars(t('tasks.timeline.group_none')); ?></option>
                </select>
            </div>
        </div>

        <!-- Main content -->
        <div class="tasks-main">
            <div class="tl-layout">
                <div class="tl-toolbar">
                    <div class="tl-nav">
                        <button class="cal-nav-btn cal-today-btn" onclick="scrollToToday()"><?php echo htmlspecialchars(t('tasks.timeline.today')); ?></button>
                        <span class="tl-range" id="tlRange"></span>
                    </div>
                </div>
                <div class="tl-scroll" id="tlScroll">
                    <div class="cal-loading"><?php echo htmlspecialchars(t('tasks.timeline.loading')); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bar right-click context menu (shared with tasks/index.php — see assets/js/tasks-ctx-menu.js) -->
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
        <?php /* Move to company - hidden at N=1; filled by tasks-ctx-menu.js. */ ?>
        <div class="ctx-item ctx-has-sub" data-action="company" id="ctxCompanyParent" style="display:none;">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.move_company')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxCompany"></div>
        </div>
    </div>

    <script>window.API_BASE = '../../api/tasks/';</script>
    <script src="../../assets/js/tasks-priority.js?v=1"></script>
    <script src="../../assets/js/tasks-ctx-menu.js?v=5"></script>
    <script src="../../assets/js/safe-html.js?v=4"></script>
    <script src="../../assets/js/tasks-quick-panel.js?v=5"></script>
    <script src="../../assets/js/tasks-timeline.js?v=13"></script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
