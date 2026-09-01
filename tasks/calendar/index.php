<?php
/**
 * Tasks Module — Calendar View
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
// Whether this analyst wants subtasks on the calendar (#90). Read here and
// published to the page rather than fetched, the same way the board reads
// tasks_detail_view: the select must render already showing the right choice,
// or it flickers to the stored value a moment after the page settles.
$calSubtaskScope = '';
try {
    $__p = connectToDatabase()->prepare(
        "SELECT preference_value FROM user_preferences
         WHERE analyst_id = ? AND preference_key = 'tasks_calendar_subtasks'"
    );
    $__p->execute([(int) ($_SESSION['analyst_id'] ?? 0)]);
    $__v = (string) $__p->fetchColumn();
    if ($__v === 'both' || $__v === 'only') { $calSubtaskScope = $__v; }
} catch (Throwable $e) {
    // Un-migrated install, or no preferences row: parent tasks only, as before.
}

I18n::initFromSession();
Tz::init();

requireModuleAccess('tasks');

$current_page = 'calendar';
$path_prefix = '../../';
$translationNamespaces = ['common', 'tasks'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('tasks.title') . ' ' . t('tasks.nav.calendar')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../../assets/css/tasks.css?v=28">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=127">
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

            <?php /* Subtasks on the calendar (discussion #90). A subtask is the same
                     record as a task and carries its own deadline and assignee, but the
                     list endpoint leaves subtasks out by default so the board and table
                     do not show them twice. The calendar opts in. */ ?>
            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.show')); ?></div>
                <select id="subtaskFilter" class="sidebar-select" onchange="setSubtaskScope(this.value)">
                    <option value=""<?php echo $calSubtaskScope === ''     ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('tasks.filter.parents_only')); ?></option>
                    <option value="both"<?php echo $calSubtaskScope === 'both' ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('tasks.filter.parents_and_subtasks')); ?></option>
                    <option value="only"<?php echo $calSubtaskScope === 'only' ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('tasks.filter.subtasks_only')); ?></option>
                </select>
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
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.legend')); ?></div>
                <div class="cal-legend" id="calLegend"></div>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.calendar.span_mode')); ?></div>
                <div class="cal-mode-hint">
                    <span id="calModeHint"></span>
                    <a href="../settings/#calendar"><?php echo htmlspecialchars(t('tasks.calendar.change')); ?></a>
                </div>
            </div>
        </div>

        <!-- Main content -->
        <div class="tasks-main">
            <div class="cal-layout">
                <div class="cal-toolbar">
                    <div class="cal-nav">
                        <button class="cal-nav-btn cal-today-btn" onclick="calToday()"><?php echo htmlspecialchars(t('tasks.calendar.today')); ?></button>
                        <button class="cal-nav-btn cal-nav-arrow" onclick="calPrev()" title="<?php echo htmlspecialchars(t('tasks.calendar.prev')); ?>">&lsaquo;</button>
                        <button class="cal-nav-btn cal-nav-arrow" onclick="calNext()" title="<?php echo htmlspecialchars(t('tasks.calendar.next')); ?>">&rsaquo;</button>
                        <h2 id="calTitle">&nbsp;</h2>
                    </div>
                    <div class="view-toggle">
                        <button class="view-btn active" data-view="month" onclick="setView('month')"><?php echo htmlspecialchars(t('tasks.calendar.view_month')); ?></button>
                        <button class="view-btn" data-view="week" onclick="setView('week')"><?php echo htmlspecialchars(t('tasks.calendar.view_week')); ?></button>
                        <button class="view-btn" data-view="day" onclick="setView('day')"><?php echo htmlspecialchars(t('tasks.calendar.view_day')); ?></button>
                    </div>
                </div>
                <div class="cal-wrap" id="calWrap">
                    <div class="cal-weekdays" id="calWeekdays">
                        <div><?php echo htmlspecialchars(t('tasks.calendar.mon')); ?></div>
                        <div><?php echo htmlspecialchars(t('tasks.calendar.tue')); ?></div>
                        <div><?php echo htmlspecialchars(t('tasks.calendar.wed')); ?></div>
                        <div><?php echo htmlspecialchars(t('tasks.calendar.thu')); ?></div>
                        <div><?php echo htmlspecialchars(t('tasks.calendar.fri')); ?></div>
                        <div><?php echo htmlspecialchars(t('tasks.calendar.sat')); ?></div>
                        <div><?php echo htmlspecialchars(t('tasks.calendar.sun')); ?></div>
                    </div>
                    <div class="cal-grid" id="calGrid">
                        <div class="cal-loading"><?php echo htmlspecialchars(t('tasks.calendar.loading')); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.API_BASE = '../../api/tasks/';
        // The stored choice, rendered server-side so the select is already correct.
        window.CAL_SUBTASK_SCOPE = <?php echo json_encode($calSubtaskScope); ?>;
    </script>
    <script src="../../assets/js/tasks-quick-panel.js?v=1"></script>
    <script src="../../assets/js/tasks-calendar.js?v=12"></script>
    <script src="../../assets/js/mobile.js?v=50"></script>
</body>
</html>
