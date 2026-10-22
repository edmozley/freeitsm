<?php
/**
 * Tasks Module — Kanban Board & List View
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$taskEmbedded = isset($taskEmbedContext);
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
requireModuleAccess('tasks');
// Read only access to Tasks (#181 Part G): the page offers nothing that writes. The
// services and endpoints refuse regardless - this only stops the page offering it.
$tasksReadOnly = analystModuleLevel(connectToDatabase(), (int)$_SESSION['analyst_id'], 'tasks') === 'read';

// How this analyst likes a task to open: the side panel, or a large window.
// Read here and published to the page, the same way the theme and timezone are
// — tasks.js must not have to fetch a preference before it can open anything.
$taskDetailView = 'panel';
// And, WITHIN the large window, whether the sections are laid out as two
// columns (as they always have been) or split across tabs. A task now carries
// enough — fields, involved people, tags, description, repeats, links,
// subtasks, time, comments, documents — that one scroll is a long way for
// somebody who only wants the comments. Offered as a choice rather than
// swapped, because a long page is genuinely better when you want to read the
// whole thing at once.
$taskModalLayout = 'columns';

// And how this analyst likes the board itself: the board or the list, showing
// their own work or everybody's (GH #131). Both used to reset on every page
// load, so choosing "All tasks" and refreshing put you back on your own board
// — the one thing on this page that did not remember, while the detail panel,
// the card fields and both settings on the tasks calendar all did.
//
// 🔑 ONLY THESE TWO. The team and analyst dropdowns, the tag filter and the
// search box are deliberately NOT remembered. A filter is how you like to look
// at things; a search is a question you asked once (discussion #96, and the
// same reasoning as the saved views in data-table.js). Persisting a narrow one
// is also how somebody opens Tasks tomorrow to an EMPTY BOARD and concludes
// their tasks have been deleted — and a stored analyst id can outlive the
// analyst. Board/list and my/all cannot do either: neither can hide a task
// that is there, beyond the ordinary meaning of the button you pressed.
$taskView   = 'board';
$taskFilter = 'my';
$taskBoardGroup = 'status';
// Card order on the board (GH #191): manual (the shared board positions) or by due date.
// A personal choice; it orders the DISPLAY only and never rewrites board_position.
$taskBoardSort = 'manual';
$taskHideDoneSubtasks = false;
// GH #184. Hide done: like Board/List, it cannot hide a task beyond the plain
// meaning of the switch you ticked, so it is remembered (the rule above).
$taskHideDone = false;
// Subtasks under the cards: all, open only, or none - plus a per-card choice
// that overrides it for that card. 'open' replaces the old Preferences switch
// "hide them once all are complete", which is the same idea for a card whose
// subtasks are ALL done; anybody who had that on starts on 'open'.
$taskBoardSubtasks = null;
// GH #190: does a task with working material open on its Work tab? On unless turned off.
$taskWorkOpen = true;
$taskSubtaskCards = [];
try {
    $__p = connectToDatabase()->prepare(
        "SELECT preference_key, preference_value FROM user_preferences
         WHERE analyst_id = ? AND preference_key IN
               ('tasks_detail_view', 'tasks_modal_layout', 'tasks_view', 'tasks_filter', 'tasks_board_group',
                'tasks_hide_done_subtasks', 'tasks_hide_done', 'tasks_board_subtasks', 'tasks_subtask_cards', 'tasks_board_sort', 'tasks_work_open')"
    );
    $__p->execute([(int) ($_SESSION['analyst_id'] ?? 0)]);
    foreach ($__p->fetchAll(PDO::FETCH_KEY_PAIR) as $__k => $__v) {
        if ($__k === 'tasks_detail_view'  && $__v === 'modal') { $taskDetailView  = 'modal'; }
        if ($__k === 'tasks_modal_layout' && $__v === 'tabs')  { $taskModalLayout = 'tabs'; }
        // Whitelisted rather than trusted: the column is free text, and an
        // unrecognised value must land on the default rather than on a view
        // that does not exist and renders as a blank page.
        if ($__k === 'tasks_view'   && $__v === 'list') { $taskView   = 'list'; }
        if ($__k === 'tasks_filter' && $__v === 'all')  { $taskFilter = 'all'; }
        // Board columns by status (default) or by analyst (2.10.0). No schema
        // change: a user_preferences row, like the board/list choice above.
        if ($__k === 'tasks_board_group' && $__v === 'analyst') { $taskBoardGroup = 'analyst'; }
        if ($__k === 'tasks_board_sort' && $__v === 'due') { $taskBoardSort = 'due'; }
        if ($__k === 'tasks_work_open' && $__v === 'off') { $taskWorkOpen = false; }
        // System -> Preferences: stop listing subtasks under a card once all are done.
        if ($__k === 'tasks_hide_done_subtasks' && $__v === 'on') { $taskHideDoneSubtasks = true; }
        if ($__k === 'tasks_hide_done' && $__v === 'on') { $taskHideDone = true; }
        if ($__k === 'tasks_board_subtasks' && in_array($__v, ['all', 'open', 'none'], true)) { $taskBoardSubtasks = $__v; }
        if ($__k === 'tasks_subtask_cards') {
            // card id => 'show' | 'hide'. Anything else in the row is dropped.
            $__m = json_decode((string)$__v, true);
            if (is_array($__m)) foreach ($__m as $__id => $__s) if (ctype_digit((string)$__id) && in_array($__s, ['show', 'hide'], true)) $taskSubtaskCards[(int)$__id] = $__s;
        }
    }
} catch (Throwable $e) {
    // Un-migrated install, or no preferences row: the side panel, as before.
}
if ($taskBoardSubtasks === null) $taskBoardSubtasks = $taskHideDoneSubtasks ? 'open' : 'all';
// Projects (3.2.0): may this analyst open Projects? Decides whether the task
// window offers a Project picker or just names the project.
$taskCanProjects = false;
try {
    $__mods = getAnalystAllowedModules(connectToDatabase(), (int) ($_SESSION['analyst_id'] ?? 0));
    $taskCanProjects = $__mods === null || in_array('projects', $__mods, true);
} catch (Throwable $e) { /* leave it off */ }
I18n::initFromSession();
Tz::init();

$current_page = 'board';
$path_prefix = '../';
$translationNamespaces = ['common', 'tasks'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('tasks')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../assets/css/tasks.css?v=53">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/record-preview.css?v=1">
    <script src="../assets/js/record-preview.js?v=1"></script>
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=195">
    <?php if ($taskEmbedded): ?><link rel="stylesheet" href="../assets/css/task-embed.css?v=2"><?php endif; ?>
</head>
<body data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>" class="<?php echo ($tasksReadOnly ? 'module-read-only ' : '').($taskEmbedded ? 'task-embed' : ''); ?>">
    <?php if (!$taskEmbedded): ?>
    <?php include 'includes/header.php'; ?>
    <?php /* The action row (#185). Tasks had no general New - only the + at the
             top of each board column, which stays as a shortcut that already
             knows the status. New asks for a title and opens the task. */
          require_once __DIR__ . '/../includes/action_row.php';
          renderActionRow(t('tasks.title'), $tasksReadOnly ? [] : [actionRowNew('openNewTask()', t('tasks.board.new_task'), false)], ['accent' => 'tsk']); ?>

    <?php /* `--views` marks the ONE page whose sidebar carries the board/list
             switch. mobile.css brings that switch back on a phone (#1499), and
             the marker keeps it off the Calendar, Table and Timeline pages,
             whose sidebars carry different things and must stay as they are.
             No desktop rule uses either new class. */ ?>
    <div class="tasks-container tasks-container--views">
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

            <div class="sidebar-section sidebar-section--view">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.view')); ?></div>
                <div class="view-toggle">
                    <button class="view-btn<?php echo $taskView === 'board' ? ' active' : ''; ?>" data-view="board" onclick="switchView('board')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>
                        <?php echo htmlspecialchars(t('tasks.view.board')); ?>
                    </button>
                    <button class="view-btn<?php echo $taskView === 'list' ? ' active' : ''; ?>" data-view="list" onclick="switchView('list')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        <?php echo htmlspecialchars(t('tasks.view.list')); ?>
                    </button>
                </div>
            </div>

            <?php /* Board columns by status or by analyst (2.10.0). Built exactly like
                     the View section above (same label, same .view-toggle / .view-btn) so
                     it reads as the same kind of control. Board view only - switchView()
                     hides the section in the list. */ ?>
            <div class="sidebar-section sidebar-section--view" id="boardGroupSection"<?php echo $taskView === 'list' ? ' style="display:none"' : ''; ?>>
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.board.group_label')); ?></div>
                <div class="view-toggle" id="boardGroupToggle">
                    <button class="view-btn<?php echo $taskBoardGroup === 'status' ? ' active' : ''; ?>" data-group="status" onclick="switchBoardGroup('status')"><?php echo htmlspecialchars(t('tasks.board.group_status')); ?></button>
                    <button class="view-btn<?php echo $taskBoardGroup === 'analyst' ? ' active' : ''; ?>" data-group="analyst" onclick="switchBoardGroup('analyst')"><?php echo htmlspecialchars(t('tasks.board.group_analyst')); ?></button>
                </div>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.filter')); ?></div>
                <button class="filter-btn<?php echo $taskFilter === 'my' ? ' active' : ''; ?>" data-filter="my" onclick="setFilter('my')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    <?php echo htmlspecialchars(t('tasks.filter.my')); ?>
                </button>
                <button class="filter-btn<?php echo $taskFilter === 'all' ? ' active' : ''; ?>" data-filter="all" onclick="setFilter('all')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    <?php echo htmlspecialchars(t('tasks.filter.all')); ?>
                </button>
            </div>

            <?php /* GH #184: what the board and list show. Hide done is remembered, like
                     Board/List; the subtask choice is the board's own (hidden in the list),
                     and each card's "1/4" line still opens or closes its own list. */ ?>
            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.show')); ?></div>
                <label class="sidebar-check">
                    <input type="checkbox" id="hideDoneToggle" onchange="setHideDone(this.checked)"<?php echo $taskHideDone ? ' checked' : ''; ?>>
                    <?php echo htmlspecialchars(t('tasks.filter.hide_done')); ?>
                </label>
            </div>
            <?php /* GH #191: the order of cards within each column. Due date sorts the display only;
                     the board's shared manual order is untouched and comes back on Manual. */ ?>
            <div class="sidebar-section sidebar-section--view" id="boardSortSection"<?php echo $taskView === 'list' ? ' style="display:none"' : ''; ?>>
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.board.sort_label')); ?></div>
                <div class="view-toggle" id="boardSortToggle">
                    <button class="view-btn<?php echo $taskBoardSort === 'manual' ? ' active' : ''; ?>" data-sort="manual" onclick="setBoardSort('manual')" title="<?php echo htmlspecialchars(t('tasks.board.sort_manual_title')); ?>"><?php echo htmlspecialchars(t('tasks.board.sort_manual')); ?></button>
                    <button class="view-btn<?php echo $taskBoardSort === 'due' ? ' active' : ''; ?>" data-sort="due" onclick="setBoardSort('due')" title="<?php echo htmlspecialchars(t('tasks.board.sort_due_title')); ?>"><?php echo htmlspecialchars(t('tasks.board.sort_due')); ?></button>
                </div>
                <div class="sidebar-hint" id="boardSortHint"<?php echo $taskBoardSort === 'due' ? '' : ' style="display:none"'; ?>><?php echo htmlspecialchars(t('tasks.board.sort_due_hint')); ?></div>
            </div>

            <div class="sidebar-section sidebar-section--view" id="boardSubtasksSection"<?php echo $taskView === 'list' ? ' style="display:none"' : ''; ?>>
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.board.subtasks_label')); ?></div>
                <div class="view-toggle" id="boardSubtasksToggle">
                    <?php foreach (['all', 'open', 'none'] as $__m): ?>
                        <button class="view-btn<?php echo $taskBoardSubtasks === $__m ? ' active' : ''; ?>" data-subtasks="<?php echo $__m; ?>"
                                onclick="setBoardSubtasks('<?php echo $__m; ?>')" title="<?php echo htmlspecialchars(t('tasks.board.subtasks_' . $__m . '_title')); ?>"><?php echo htmlspecialchars(t('tasks.board.subtasks_' . $__m)); ?></button>
                    <?php endforeach; ?>
                </div>
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

            <!-- Projects (3.2.0): shown only when a task here belongs to a project. -->
            <div class="sidebar-section" id="projectFilterSection" style="display:none;">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.project')); ?></div>
                <select id="projectFilter" class="sidebar-select" onchange="setProjectFilter(this.value)">
                    <option value=""><?php echo htmlspecialchars(t('tasks.filter.all_projects')); ?></option>
                </select>
            </div>

            <!-- Contractors (3.3.0): shown only when a task here is with a supplier. -->
            <div class="sidebar-section" id="contractorFilterSection" style="display:none;">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.contractor')); ?></div>
                <select id="contractorFilter" class="sidebar-select" onchange="setContractorFilter(this.value)">
                    <option value=""><?php echo htmlspecialchars(t('tasks.filter.all_contractors')); ?></option>
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
            <!-- Board view — one column per status, generated by tasks.js.
                 Which of the two panes starts visible is decided HERE rather
                 than by tasks.js on load: a list-view analyst would otherwise
                 see the board painted and then swapped out from under them. -->
            <div id="boardView" class="board-view"<?php echo $taskView === 'board' ? '' : ' style="display:none;"'; ?>></div>

            <!-- List view -->
            <div id="listView" class="list-view"<?php echo $taskView === 'list' ? '' : ' style="display:none;"'; ?>>
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

    <?php else:
        require_once '../includes/documents_panel.php';
        documentsPanelAssets(BASE_URL);
    ?>
    <script src="../assets/js/toast.js"></script>
    <script src="../assets/js/confirm.js?v=6"></script>
    <?php endif; ?>

    <?php require_once __DIR__.'/includes/detail-shell.php'; renderTaskDetailShell(); ?>

    <?php if (!$taskEmbedded): ?>
    <!-- Card right-click context menu -->
    <div class="ctx-menu" id="ctxMenu">
        <div class="ctx-item" data-action="open">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.open')); ?></span>
        </div>
        <div class="ctx-item" data-action="assign-me">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.assign_me')); ?></span>
        </div>
        <div class="ctx-sep"></div>
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
        <div class="ctx-item ctx-has-sub" data-action="due">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.due_date')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxDue"></div>
        </div>
        <?php /* Move to company. Hidden at N=1 and never rendered on a
                 single-company install, so nothing changes for anyone who does
                 not use companies. The menu module fills and unhides it. */ ?>
        <div class="ctx-item ctx-has-sub" data-action="company" id="ctxCompanyParent" style="display:none;">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.move_company')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxCompany"></div>
        </div>
        <div class="ctx-sep"></div>
        <!-- Label flips between Mark complete and Reopen, set per task in
             buildSubmenus() from the status's is_closed FLAG — never by comparing
             the status name to the English word "Done", which any installation is
             free to rename. -->
        <div class="ctx-item" data-action="complete">
            <span class="ctx-item-label" id="ctxCompleteLabel"><?php echo htmlspecialchars(t('tasks.context.mark_complete')); ?></span>
        </div>
        <div class="ctx-item" data-action="logtime" id="ctxLogTime">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.log_time')); ?></span>
        </div>
        <div class="ctx-item" data-action="subtask">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.create_subtask')); ?></span>
        </div>
        <?php /* Repeats (#94). Hidden for a subtask by the menu's own code — the
                 series belongs to the piece of work, not a step inside it, and the
                 API refuses it too. */ ?>
        <div class="ctx-item" data-action="repeat" id="ctxRepeat">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.set_repeat')); ?></span>
        </div>
        <div class="ctx-sep"></div>
        <div class="ctx-item" data-action="copylink">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.copy_link')); ?></span>
        </div>
        <div class="ctx-item ctx-danger" data-action="delete">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.delete')); ?></span>
        </div>
    </div>

    <?php endif; ?>
    <!-- Toast -->
    <script>window.CASE_REPORT_URL = <?php echo json_encode(BASE_URL . 'tasks/case-report.php'); ?>; window.API_BASE = '../api/tasks/';
    window.APP_BASE = '<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>';
    window.TASK_HOST = <?php echo json_encode($taskEmbedded ? 'ticket' : 'module'); ?>;
    <?php if ($taskEmbedded): ?>window.TASK_EMBED_CONTEXT = <?php echo json_encode($taskEmbedContext); ?>;<?php endif; ?>
    window.TASK_DETAIL_VIEW = <?php echo json_encode($taskEmbedded ? 'modal' : $taskDetailView); ?>;
    window.TASK_MODAL_LAYOUT = <?php echo json_encode($taskModalLayout); ?>;
    window.TASK_WORK_OPEN = <?php echo json_encode($taskWorkOpen); ?>;
    window.TASK_VIEW = <?php echo json_encode($taskView); ?>;
    window.TASK_BOARD_GROUP = <?php echo json_encode($taskBoardGroup); ?>;
    window.TASK_BOARD_SORT = <?php echo json_encode($taskBoardSort); ?>;
    window.TASK_HIDE_DONE = <?php echo json_encode($taskHideDone); ?>;
    window.TASK_BOARD_SUBTASKS = <?php echo json_encode($taskBoardSubtasks); ?>;
    window.TASK_SUBTASK_CARDS = <?php echo json_encode((object)$taskSubtaskCards); ?>;
    window.TASK_FILTER = <?php echo json_encode($taskFilter); ?>;
    window.TASK_CAN_PROJECTS = <?php echo json_encode($taskCanProjects); ?>;</script>
    <script src="../assets/js/tasks-priority.js?v=1"></script>
    <script src="../assets/js/tasks-ctx-menu.js?v=5"></script>
    <!-- The one cleaner for HTML from elsewhere: Knowledge procedure bodies and task instructions in the Work view (GH #190). -->
    <script src="../assets/js/safe-html.js?v=2"></script>
    <?php if ($taskEmbedded): ?><script src="../assets/js/task-embed-bridge.js?v=2"></script><?php endif; ?>
    <script src="../assets/js/tasks.js?v=69"></script>
    <?php if (!$taskEmbedded): ?><script src="../assets/js/mobile.js?v=82"></script><?php endif; ?>
</body>
</html>
