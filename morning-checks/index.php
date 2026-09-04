<?php
/**
 * Morning Checks Dashboard
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/branding.php';   // the organisation's logo (GH #87)
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('morning-checks');

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$analyst_email = $_SESSION['analyst_email'] ?? '';
$analyst_id = $_SESSION['analyst_id'] ?? 0;
$current_page = 'dashboard';
$path_prefix = '../';

// Pre-fetch the analyst's chart preferences AND the configured status
// list so the first paint is already correct (no flash of empty status
// buttons or wrong chart height).
$chart_height_pct = 35.0;     // matches DEFAULT_CHART_PCT in the page-script
$chart_fill_style = 'plain';
$mc_statuses = [];            // [{StatusID, Label, Colour, RequiresNotes, SortOrder, IsActive}]
// Discussion #64: which teams this analyst belongs to, so "Mine" can include a
// check routed to a team rather than to them by name. Emitted with the page
// rather than fetched, because it never changes mid-round and one fewer request
// on a page somebody opens first thing is worth having.
$mc_my_team_ids = [];
try {
    $conn = connectToDatabase();

    try {
        $tStmt = $conn->prepare("SELECT team_id FROM analyst_teams WHERE analyst_id = ?");
        $tStmt->execute([(int)$analyst_id]);
        $mc_my_team_ids = array_map('intval', $tStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        // Teams are optional; an install without them simply has no team routing.
    }

    $stmt = $conn->prepare(
        "SELECT preference_key, preference_value FROM user_preferences
         WHERE analyst_id = ? AND preference_key IN ('mc_chart_height_pct', 'mc_chart_fill_style')"
    );
    $stmt->execute([(int)$analyst_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['preference_key'] === 'mc_chart_height_pct' && is_numeric($row['preference_value'])) {
            $v = (float)$row['preference_value'];
            if ($v >= 12 && $v <= 80) $chart_height_pct = $v;
        } elseif ($row['preference_key'] === 'mc_chart_fill_style' && $row['preference_value'] === 'gradient') {
            $chart_fill_style = 'gradient';
        }
    }

    // Active statuses — drive the dashboard's status buttons. We fetch
    // ALL of them (active + inactive) so a historical result saved
    // against a now-deactivated status can still resolve its colour
    // for the row classname / PDF; the UI filters to active for the
    // actual button list.
    $sStmt = $conn->query(
        "SELECT StatusID, Label, Colour, RequiresNotes, SortOrder, IsActive
         FROM morningChecks_Statuses ORDER BY SortOrder, StatusID"
    );
    foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $mc_statuses[] = [
            'StatusID'      => (int)$s['StatusID'],
            'Label'         => $s['Label'],
            'Colour'        => $s['Colour'],
            'RequiresNotes' => (bool)$s['RequiresNotes'],
            'SortOrder'     => (int)$s['SortOrder'],
            'IsActive'      => (bool)$s['IsActive'],
        ];
    }
} catch (Exception $e) {
    // Stick with the defaults / empty statuses; JS will deal with it
}
// CSS calc() needs the percentage as a fraction (0-1). The 60px is a
// reasonable approximation of the global header height — JS will
// reconcile to the exact pixel value once the DOM is ready, so any
// sub-pixel mismatch here is invisible.
$chart_initial_height_calc = 'calc((100vh - 60px) * ' . ($chart_height_pct / 100) . ')';
$translationNamespaces = ['common', 'morning-checks'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('morning-checks.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="style.css?v=3">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        /* Layout: body is the outer flex column so .container fills
           exactly the viewport minus the global header — no manual
           calc(100vh - Npx), which was undershooting (the global header
           is actually ~60px tall, not 48). Modals are position: fixed
           so they sit outside the flex flow. */
        body {
            padding-top: 0;
            display: flex;
            flex-direction: column;
            /* inbox.css already sets height: 100vh; overflow: hidden */
        }
        /* Module accent (cyan) → drives focus rings, shared .btn primaries,
           tabs and toggles. The chart border + raise-ticket chip use the
           --mc-* tokens directly. */
        body { --accent: var(--mc-accent, #00acc1); --accent-hover: var(--mc-accent-hover, #00838f); }
        .container {
            max-width: none;
            margin: 0;
            padding: 0;
            flex: 1;
            min-height: 0;   /* lets the inner overflow:auto work in a flex parent */
            display: flex;
            flex-direction: column;
        }
        .date-display {
            flex-shrink: 0;
            padding: 16px 30px 5px;
        }
        .checks-section {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 16px 30px 16px;
            margin-bottom: 0;
        }
        /* Chart sits in flow at the bottom of the flex column (was
           position: fixed). flex-shrink: 0 pins it; the checks-section
           above scrolls underneath. position: relative anchors the
           absolutely-positioned collapse chevron; min-height keeps the
           chevron visible when the chart is collapsed (the chart-
           container-inner is the only in-flow child, so without
           min-height the footer would shrink to nothing — bumped a
           little to leave room for the chevron now that it sits near
           the bottom edge). */
        .chart-footer {
            position: relative;
            flex-shrink: 0;
            min-height: 40px;
            border-top-color: var(--mc-accent, #00acc1);
            box-shadow: 0 -2px 10px var(--shadow, rgba(0,0,0,0.06));
        }

        /* Tighten the chart-container-inner padding. style.css defaults
           to 20px 30px 30px 30px which leaves ~30px of dead space
           around the canvas — the chart and legend now use that
           reclaimed area. Chevron is positioned relative to .chart-
           footer (one level up) so it's unaffected by this. */
        .chart-container-inner {
            padding: 6px 14px 4px 14px;
            overflow: hidden;
            transition: height 0.25s ease, padding 0.25s ease, opacity 0.2s ease;
        }
        /* Collapsed state — height animates from its inline px value to
           0 over the transition. !important needed to beat the inline
           style.height that applyChartHeightFromPct sets. The chart-
           footer's min-height (40px) keeps the chevron visible. */
        .chart-container-inner.collapsed {
            height: 0 !important;
            padding-top: 0;
            padding-bottom: 0;
            opacity: 0;
        }

        /* Collapse chevron — small floating button in the bottom-right
           of the chart area, vertically aligned with the Chart.js
           bottom-position legend. Footer's min-height keeps it visible
           when chart is collapsed. */
        .chart-toggle-btn {
            position: absolute;
            bottom: 8px;
            right: 12px;
            z-index: 5;
            width: 26px;
            height: 22px;
            padding: 0;
            background: var(--surface, rgba(255, 255, 255, 0.92));
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 4px;
            color: var(--accent, #007bff);
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s, border-color 0.15s;
        }
        .chart-toggle-btn:hover {
            background: var(--surface-hover, white);
            border-color: var(--accent, #007bff);
        }

        /* Orphan-status banner (top of dashboard) + per-row badge
           (inside .status-buttons cell). Surface unmapped results so
           the admin knows to use the Settings normalisation tool. */
        .orphan-banner {
            background: var(--warning-bg, #fff3cd);
            border: 1px solid var(--warning-border, #ffe69c);
            border-radius: 6px;
            padding: 10px 14px;
            margin: 0 30px 12px;
            font-size: 13px;
            color: var(--warning-text, #664d03);
        }
        .orphan-banner a { color: var(--warning-text, #664d03); font-weight: 600; }
        .status-orphan-badge {
            display: inline-block;
            margin-top: 6px;
            padding: 2px 8px;
            border-radius: 10px;
            background: var(--warning-bg, #fff3cd);
            color: var(--warning-text, #664d03);
            border: 1px solid var(--warning-border, #ffe69c);
            font-size: 11px;
            font-weight: 600;
        }

        /* Dynamic status buttons — colour comes from CSS custom
           properties on each button (--c = base, --tc = readable
           text on filled background). Overrides the legacy hard-coded
           .status-btn.green / .amber / .red rules in style.css. */
        .status-btn {
            border-color: var(--c, #999) !important;
            color: var(--c, #555) !important;
            background: var(--surface, white) !important;
        }
        .status-btn:hover,
        .status-btn.active {
            background: var(--c, #999) !important;
            color: var(--tc, #fff) !important;
        }

        /* Drag handle between the checks list and the chart. Hover /
           active states use a subtle blue tint so it's discoverable
           without being noisy in its resting state. */
        .mc-divider {
            flex-shrink: 0;
            height: 6px;
            background: transparent;
            cursor: row-resize;
            border-top: 1px solid var(--border, #e0e0e0);
            transition: background 0.15s;
            user-select: none;
        }
        .mc-divider:hover { background: rgba(0, 123, 255, 0.18); }
        .mc-divider.dragging { background: rgba(0, 123, 255, 0.35); }

    </style>
    <!-- Mobile layer. Linked AFTER this page's inline <style> on purpose: the
         mobile rules must win on equal specificity, and a link placed above it
         would silently lose to the desktop block below (the load-order trap). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=132">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="container">
        <div class="date-display">
            <?php /* Was date('l, F j, Y') — English weekday and month whatever the
                     interface language, and a US arrangement whatever the analyst
                     had chosen. Rendered through DateFmt so it matches the same
                     heading once JS repaints it (formatDate below). */ ?>
            <h2 id="dateDisplayText"><?php
                $mcToday = new DateTime('now', new DateTimeZone(Tz::current()));
                echo htmlspecialchars(t('morning-checks.dashboard.todays_checks', [
                    'date' => DateFmt::weekdays()[(int)$mcToday->format('N') - 1]
                            . ' ' . DateFmt::render($mcToday, 'D MONTH YYYY'),
                ]));
            ?></h2>
            <div class="date-selector-container">
                <label for="checkDate"><?php echo htmlspecialchars(t('morning-checks.dashboard.select_date')); ?></label>
                <input type="date" id="checkDate" value="<?php echo date('Y-m-d'); ?>" onchange="dateChanged()">
                <button onclick="setToday()" class="btn-today"><?php echo htmlspecialchars(t('morning-checks.dashboard.today')); ?></button>
                <button onclick="saveToPDF()" class="btn-pdf"><?php echo htmlspecialchars(t('morning-checks.dashboard.save_to_pdf')); ?></button>
                <!-- Discussion #64. A VIEW filter: it hides rows, it does not
                     withhold them. Anyone can still complete anything shown
                     under "All", which is the point on a morning somebody is
                     off sick. -->
                <span class="mc-filter" id="mcFilter" hidden>
                    <button type="button" class="mc-filter-btn is-on" data-mine="0" onclick="setMineFilter(false)"><?php echo htmlspecialchars(t('morning-checks.dashboard.filter_all')); ?></button>
                    <button type="button" class="mc-filter-btn" data-mine="1" onclick="setMineFilter(true)"><?php echo htmlspecialchars(t('morning-checks.dashboard.filter_mine')); ?></button>
                </span>
            </div>
        </div>

        <!-- Orphan-statuses banner. Populated by checkForOrphans() when
             results exist with unmapped StatusID (e.g. left over from
             a deleted status). Links to the Settings → Statuses
             normalisation tool. Hidden by default. -->
        <div id="orphanBanner" class="orphan-banner" style="display: none;">
            <strong>⚠ <?php echo htmlspecialchars(t('morning-checks.orphan.banner_title')); ?></strong>
            <span id="orphanBannerCount"></span>
            <?php echo htmlspecialchars(t('morning-checks.orphan.banner_goto')); ?> <a href="settings/#statuses-tab"><?php echo htmlspecialchars(t('morning-checks.orphan.banner_link')); ?></a> <?php echo htmlspecialchars(t('morning-checks.orphan.banner_suffix')); ?>
        </div>

        <div class="checks-section">
            <table id="checksTable">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('morning-checks.dashboard.col_check_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('morning-checks.dashboard.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('morning-checks.dashboard.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('morning-checks.dashboard.col_notes')); ?></th>
                        <th><?php echo htmlspecialchars(t('morning-checks.dashboard.col_checked_by')); ?></th>
                        <th><?php echo htmlspecialchars(t('morning-checks.dashboard.col_checked_at')); ?></th>
                        <th class="mc-actions-col"><?php echo htmlspecialchars(t('morning-checks.dashboard.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="checksTableBody">
                    <tr>
                        <td colspan="7" class="loading"><?php echo htmlspecialchars(t('morning-checks.dashboard.loading_checks')); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Drag-handle between the checks list and the chart. The chart's
             height is a per-analyst preference (mc_chart_height_pct) so the
             split each user prefers persists across reloads. -->
        <div class="mc-divider" id="mcDivider" title="<?php echo htmlspecialchars(t('morning-checks.dashboard.drag_resize')); ?>"></div>

        <!-- Chart sits in the flex column at the bottom of .container.
             No grey header bar anymore — the collapse chevron is
             overlaid in the chart's top-right corner so the entire
             chart-footer height goes to the canvas. Chart-footer keeps a
             small min-height so the chevron stays visible when the
             chart-container is collapsed.
             The chart-container's inline height is set server-side from
             the analyst's saved preference so the first paint matches
             their chosen split (no snap from the CSS default of 280px
             to the saved value once JS finished its preference fetch). -->
        <div class="chart-footer">
            <button id="chartToggle" class="chart-toggle-btn" onclick="toggleChart()" aria-label="<?php echo htmlspecialchars(t('morning-checks.dashboard.collapse_chart')); ?>">▼</button>
            <div id="chartContainer" class="chart-container-inner" style="height: <?php echo $chart_initial_height_calc; ?>;">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Notes Modal -->
    <div id="notesModal" class="modal">
        <div class="modal-content">
            <h2><?php echo htmlspecialchars(t('morning-checks.notes_modal.title')); ?></h2>
            <p><?php
                // Split the interpolated string around {status} so the live
                // status label can sit in its own span (set by JS).
                $parts = explode('{status}', t('morning-checks.notes_modal.intro'), 2);
                echo htmlspecialchars($parts[0]);
            ?><span id="modalStatus"></span><?php echo htmlspecialchars($parts[1] ?? ''); ?></p>
            <form id="notesForm">
                <input type="hidden" id="modalCheckId">
                <!-- Holds the StatusID picked from a button. modalStatus
                     (the span above) shows the human label. -->
                <input type="hidden" id="modalStatusId">
                <div class="form-group">
                    <label for="modalNotes"><?php echo htmlspecialchars(t('morning-checks.notes_modal.label')); ?></label>
                    <textarea id="modalNotes" name="modalNotes" rows="5" required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeNotesModal()"><?php echo htmlspecialchars(t('morning-checks.notes_modal.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('morning-checks.notes_modal.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Raise Ticket Modal -->
    <div id="raiseTicketModal" class="modal">
        <div class="modal-content" style="max-width: 640px;">
            <h2><?php echo htmlspecialchars(t('morning-checks.raise_modal.title')); ?></h2>
            <p><?php echo htmlspecialchars(t('morning-checks.raise_modal.intro')); ?></p>
            <form id="raiseTicketForm">
                <!-- Ticket or task (discussion #64). A morning check turns up two
                     different kinds of follow-up: something a customer is waiting
                     on, and something we have to go and do. Forcing both into a
                     ticket was why "raise" got used less than it should have. -->
                <input type="hidden" id="rtCheckId">
                <input type="hidden" id="rtResultId">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('morning-checks.raise_modal.what')); ?></label>
                    <div class="mc-kind-toggle">
                        <button type="button" class="mc-kind-btn is-on" data-kind="ticket" onclick="setRaiseKind('ticket')"><?php echo htmlspecialchars(t('morning-checks.raise_modal.kind_ticket')); ?></button>
                        <button type="button" class="mc-kind-btn" data-kind="task" onclick="setRaiseKind('task')"><?php echo htmlspecialchars(t('morning-checks.raise_modal.kind_task')); ?></button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="rtSubject"><?php echo htmlspecialchars(t('morning-checks.raise_modal.subject')); ?></label>
                    <input type="text" id="rtSubject" required>
                </div>
                <div class="form-row" id="rtTicketOnly" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label for="rtPriority"><?php echo htmlspecialchars(t('morning-checks.raise_modal.priority')); ?></label>
                        <select id="rtPriority">
                            <option value="Low"><?php echo htmlspecialchars(t('morning-checks.raise_modal.priority_low')); ?></option>
                            <option value="Normal"><?php echo htmlspecialchars(t('morning-checks.raise_modal.priority_normal')); ?></option>
                            <option value="High"><?php echo htmlspecialchars(t('morning-checks.raise_modal.priority_high')); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="rtDepartment"><?php echo htmlspecialchars(t('morning-checks.raise_modal.department')); ?></label>
                        <select id="rtDepartment">
                            <option value=""><?php echo htmlspecialchars(t('morning-checks.raise_modal.select')); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="rtTicketType"><?php echo htmlspecialchars(t('morning-checks.raise_modal.type')); ?></label>
                        <select id="rtTicketType">
                            <option value=""><?php echo htmlspecialchars(t('morning-checks.raise_modal.select')); ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="rtAssignee"><?php echo htmlspecialchars(t('morning-checks.raise_modal.assign_to')); ?></label>
                    <select id="rtAssignee">
                        <option value=""><?php echo htmlspecialchars(t('morning-checks.raise_modal.unassigned')); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="rtBody"><?php echo htmlspecialchars(t('morning-checks.raise_modal.description')); ?></label>
                    <textarea id="rtBody" rows="6"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeRaiseTicketModal()"><?php echo htmlspecialchars(t('morning-checks.raise_modal.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary" id="rtSubmitBtn"><?php echo htmlspecialchars(t('morning-checks.raise_modal.create')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script>
        const API_BASE = '../api/morning-checks/';
        const TICKETS_API = '../api/tickets/';
        const SESSION_ANALYST = {
            id: <?php echo (int)$analyst_id; ?>,
            name: <?php echo json_encode($analyst_name); ?>,
            email: <?php echo json_encode($analyst_email); ?>
        };
        // Chart preferences pre-fetched server-side so we don't
        // need a separate AJAX round-trip (and so the page paints at
        // the right size and style from the start).
        const INITIAL_CHART_PCT = <?php echo json_encode($chart_height_pct); ?>;
        const CHART_FILL_STYLE = <?php echo json_encode($chart_fill_style); ?>;
        // All configured statuses (active + inactive). Drives the
        // status buttons on each check row and the colour lookup for
        // historical results.
        const MC_STATUSES = <?php echo json_encode($mc_statuses); ?>;
        const MC_ACTIVE_STATUSES = MC_STATUSES.filter(s => s.IsActive);
        let rtAnalystOptions = [];
        let rtDepartmentOptions = [];
        let rtTicketTypeOptions = [];

        // Load checks for selected date
        async function loadChecks() {
            const selectedDate = document.getElementById('checkDate').value;
            try {
                const response = await fetch(`${API_BASE}get_todays_checks.php?date=${selectedDate}`);
                const data = await response.json();

                if (data.error) {
                    document.getElementById('checksTableBody').innerHTML =
                        `<tr><td colspan="7" class="error">${escapeHtml(window.t('morning-checks.checklist.error', { message: data.error }))}</td></tr>`;
                    return;
                }

                displayChecks(data);
            } catch (error) {
                document.getElementById('checksTableBody').innerHTML =
                    `<tr><td colspan="7" class="error">${escapeHtml(window.t('morning-checks.checklist.error_loading', { message: error.message }))}</td></tr>`;
            }
        }

        function dateChanged() {
            const selectedDate = document.getElementById('checkDate').value;
            const dateObj = new Date(selectedDate + 'T00:00:00');
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            let displayText = '';
            if (dateObj.getTime() === today.getTime()) {
                displayText = window.t('morning-checks.dashboard.todays_checks', { date: formatDate(dateObj) });
            } else {
                displayText = window.t('morning-checks.dashboard.checks_for', { date: formatDate(dateObj) });
            }
            document.getElementById('dateDisplayText').textContent = displayText;

            loadChecks();
            loadChart();
        }

        function setToday() {
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            document.getElementById('checkDate').value = `${yyyy}-${mm}-${dd}`;
            dateChanged();
        }

        function formatDate(date) {
            // The heading over the day's checks - a full "Wednesday 5 August 2026".
            // Naive: the date picker's value is a wall-clock day, not an instant.
            return fmtNaiveWeekday(date) + ' ' + fmtNaiveTemplate(date, 'D MONTH YYYY');
        }

        // Pick black/white text for a given hex background so the active
        // pill is always readable. Standard relative-luminance threshold.
        function readableTextOn(hexColour) {
            const m = /^#([0-9a-f]{6})$/i.exec(hexColour || '');
            if (!m) return '#fff';
            const r = parseInt(m[1].slice(0, 2), 16);
            const g = parseInt(m[1].slice(2, 4), 16);
            const b = parseInt(m[1].slice(4, 6), 16);
            const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
            return lum > 0.6 ? '#333' : '#fff';
        }

        /* ─── Routing and the Mine/All filter (discussion #64) ────────────────
           ⚠️ Read this before "tightening" anything here. Assignment on a
           morning check is ROUTING, not permission. There is no server-side
           check on who may complete one and there deliberately never will be:
           the round is a daily ritual that has to happen, and the morning the
           named person is off sick is exactly when somebody else must be able
           to do it without asking anyone. Same principle as collision
           detection, which warns and never blocks. */
        let MC_FILTER_MINE = false;
        let MC_MY_TEAM_IDS = <?php echo json_encode($mc_my_team_ids); ?>;
        try { MC_FILTER_MINE = localStorage.getItem('mc_filter_mine') === '1'; } catch (e) {}

        /** Is this check routed to me — directly, or via a team I am in? */
        function isMine(check) {
            if (check.AssignedAnalystID && Number(check.AssignedAnalystID) === Number(SESSION_ANALYST.id)) return true;
            if (check.AssignedTeamID && MC_MY_TEAM_IDS.includes(Number(check.AssignedTeamID))) return true;
            return false;
        }

        /** The group heading's routing suffix, or '' when it goes to nobody. */
        function groupRouteLabel(check) {
            if (check.GroupID === null) return '';
            if (check.AssignedSource === 'group') return window.t('morning-checks.checklist.assigned_to', { name: check.AssignedLabel || '' });
            if (check.AssignedSource === 'team')  return window.t('morning-checks.checklist.assigned_team', { name: check.AssignedLabel || '' });
            return '';
        }

        /** "09:24" for today, "12 Aug 09:24" otherwise — the column is narrow. */
        function formatCheckedAt(utcString) {
            try {
                const d = parseUTCDate(utcString);
                if (!d || isNaN(d.getTime())) return utcString;
                const viewing = document.getElementById('checkDate').value;
                const today = new Date().toISOString().slice(0, 10);
                const timeStr = fmtTime(d);
                if (viewing === today) return timeStr;
                return fmtDayMonth(d) + ' ' + timeStr;
            } catch (e) { return utcString; }
        }

        /** Put a check back to not-checked. Confirmed, because it discards the note. */
        async function clearCheckResult(checkId, checkName) {
            const ok = await showConfirm({
                title:   window.t('morning-checks.checklist.clear_heading'),
                message: window.t('morning-checks.checklist.clear_confirm', { name: checkName }),
                okLabel: window.t('morning-checks.checklist.clear_ok'),
                okClass: 'danger'
            });
            if (!ok) return;
            try {
                const r = await fetch(API_BASE + 'clear_check_result.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ checkId: checkId, date: document.getElementById('checkDate').value })
                });
                const d = await r.json();
                if (d.success) {
                    showToast(window.t('morning-checks.toast.cleared'), 'success');
                    loadChecks();
                } else {
                    showToast(d.error || window.t('morning-checks.toast.clear_failed'), 'error');
                }
            } catch (e) {
                showToast(window.t('morning-checks.toast.clear_failed'), 'error');
            }
        }

        function setMineFilter(on) {
            MC_FILTER_MINE = !!on;
            // Remembered per analyst rather than configured in Settings: it is a
            // view preference somebody flips as their morning goes, not a policy.
            try { localStorage.setItem('mc_filter_mine', MC_FILTER_MINE ? '1' : '0'); } catch (e) {}
            document.querySelectorAll('.mc-filter-btn').forEach(b =>
                b.classList.toggle('is-on', (b.dataset.mine === '1') === MC_FILTER_MINE));
            displayChecks(window.MC_LAST_CHECKS || []);
        }

        function displayChecks(checks) {
            window.MC_LAST_CHECKS = checks;   // so the filter can re-render without refetching
            const tbody = document.getElementById('checksTableBody');

            if (checks.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="no-data">' + window.t('morning-checks.checklist.no_checks_html') + '</td></tr>';
                return;
            }
            if (MC_ACTIVE_STATUSES.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="no-data">' + window.t('morning-checks.checklist.no_statuses_html') + '</td></tr>';
                return;
            }

            // The Mine/All control only appears once something is actually
            // routed. An install that never opens the Groups tab sees the
            // dashboard exactly as it was — a filter offering to narrow a list
            // that has nothing to narrow by is just a button that does nothing.
            const anyRouted = checks.some(c => c.AssignedSource !== null);
            const filterEl = document.getElementById('mcFilter');
            if (filterEl) filterEl.hidden = !anyRouted;
            if (!anyRouted) MC_FILTER_MINE = false;

            // Discussion #64: filter to the checks routed to me, if asked.
            // ⚠️ This is a VIEW filter and nothing else. Nothing here or on the
            // server stops anyone completing anyone's check — on the morning
            // somebody is off sick the round still has to be done by whoever is
            // in, so "Mine" is a convenience and never a boundary.
            const visible = (MC_FILTER_MINE ? checks.filter(isMine) : checks);
            if (visible.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" class="no-data">${escapeHtml(window.t('morning-checks.checklist.none_mine'))}</td></tr>`;
                return;
            }

            let lastGroupKey = null;
            tbody.innerHTML = visible.map(check => {
                // A heading row whenever the group changes. Ungrouped checks sort
                // last and get their own heading rather than appearing loose, so
                // a partially-grouped round still reads as a set of sections.
                const groupKey = check.GroupID === null ? '__none__' : String(check.GroupID);
                let groupRow = '';
                if (groupKey !== lastGroupKey) {
                    lastGroupKey = groupKey;
                    const anyGrouped = visible.some(c => c.GroupID !== null);
                    if (anyGrouped) {
                        const title = check.GroupName || window.t('morning-checks.checklist.group_ungrouped');
                        const routed = groupRouteLabel(check);
                        groupRow = `<tr class="mc-group-row"><td colspan="7">
                            <span class="mc-group-name">${escapeHtml(title)}</span>
                            ${routed ? `<span class="mc-group-route">${escapeHtml(routed)}</span>` : ''}
                        </td></tr>`;
                    }
                }

                // Actions, as icons in their own column so the row stays one line.
                // "Raise" is always offered now: it used to appear only when the
                // status required notes, which is a fair proxy for "something is
                // wrong" but a poor one for "I want to follow this up" — a green
                // check can still warrant a task to go and look at something.
                const raiseBtn = `<button class="mc-icon-btn" title="${escapeHtmlAttr(window.t('morning-checks.checklist.raise_title'))}"
                        onclick="openRaiseModal(${check.CheckID}, ${check.ResultID === null ? 'null' : check.ResultID}, '${escapeJsString(check.CheckName)}', '${escapeJsString(check.CheckDescription || '')}', '${escapeJsString(check.Status || '')}', '${escapeJsString(check.Notes || '')}')">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    </button>`;

                // Undo. Only offered once there is something to undo, because an
                // "unset" on a check nobody has touched is a button that cannot
                // do anything — and clicking green by mistake is common enough
                // that the way back should not be "set it to something else".
                // ⚠️ ALWAYS RENDERED, and DISABLED when there is nothing to
                // undo. It used to be omitted entirely until a check had been
                // set, which is truthful but made the row change width the
                // instant you pressed a status — so the buttons you were aiming
                // at moved under your finger, worst of all on a phone. A
                // control that is present and visibly unavailable says the same
                // thing without the row jumping.
                const canClear = (check.ResultID !== null);
                const clearBtn = `<button class="mc-icon-btn mc-icon-clear"${canClear ? '' : ' disabled'}
                            title="${escapeHtmlAttr(window.t(canClear ? 'morning-checks.checklist.clear_title' : 'morning-checks.checklist.clear_title_none'))}"
                            onclick="clearCheckResult(${check.CheckID}, '${escapeJsString(check.CheckName)}')">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"></path><path d="M3.51 13a9 9 0 1 0 2.13-9.36L3 7"></path></svg>
                        </button>`;

                // Buttons — one per active status. The active button is
                // the one whose StatusID matches the saved StatusID.
                // For orphan rows (StatusID is null), no button is
                // highlighted — the orphan badge below makes that
                // visible and prompts the admin to remap.
                const buttonsHtml = MC_ACTIVE_STATUSES.map(s => {
                    const isActive = check.StatusID !== null && check.StatusID === s.StatusID;
                    const textColour = readableTextOn(s.Colour);
                    const styleVars = '--c: ' + s.Colour + '; --tc: ' + textColour + ';';
                    return `<button class="status-btn${isActive ? ' active' : ''}"
                                    style="${styleVars}"
                                    onclick="handleStatusClick(${check.CheckID}, ${s.StatusID}, '${escapeJsString(check.Notes || '')}')">${escapeHtml(s.Label)}</button>`;
                }).join('');

                // Inline orphan badge — flags rows whose Status label
                // doesn't match any current StatusID (e.g. their status
                // was deleted). Settings → Statuses has the remap tool.
                const orphanBadge = check.IsOrphan
                    ? `<span class="status-orphan-badge" title="${escapeHtmlAttr(window.t('morning-checks.orphan.row_badge_title'))}">⚠ ${escapeHtml(window.t('morning-checks.orphan.row_badge', { label: check.Status }))}</span>`
                    : '';

                // Row's status-{slug} class — keeps existing CSS hooks
                // working for legacy row-level highlight rules.
                const slug = check.Status ? check.Status.toLowerCase().replace(/[^a-z0-9]+/g, '-') : 'none';

                // Who it is routed to. Shown only when it differs from the group
                // heading already above it, so a group routed to one analyst does
                // not repeat that person's name on every one of its rows.
                const ownRoute = (check.AssignedSource === 'check')
                    ? `<span class="mc-assigned" title="${escapeHtmlAttr(window.t('morning-checks.checklist.assigned_title'))}">${escapeHtml(window.t('morning-checks.checklist.assigned_to', { name: check.AssignedLabel || '' }))}</span>`
                    : '';

                // Who did it and when, each in their own column so nothing stacks
                // under the status buttons and every row stays a single line.
                const checkedBy = (check.ResultID !== null && check.CompletedBy)
                    ? escapeHtml(check.CompletedBy) : '<span class="mc-dash">—</span>';
                const checkedAt = (check.ResultID !== null && check.CompletedAt)
                    ? escapeHtml(formatCheckedAt(check.CompletedAt)) : '<span class="mc-dash">—</span>';

                return `
                ${groupRow}
                <tr data-check-id="${check.CheckID}" class="status-${slug}">
                    <td><strong>${escapeHtml(check.CheckName)}</strong>${ownRoute}</td>
                    <td>${escapeHtml(check.CheckDescription || '')}</td>
                    <td>
                        <div class="status-buttons">${buttonsHtml}</div>
                        ${orphanBadge}
                    </td>
                    <td class="notes-display">${check.Notes
                        ? escapeHtml(check.Notes)
                        /* The "no notes" placeholder is wrapped so the phone can
                           drop it: in a table an empty cell reads as a rendering
                           fault, so the dash earns its place there — but on a
                           card it is a line of its own saying nothing. The class
                           carries NO desktop styling, so the table is unchanged;
                           mobile.css hides the whole cell when it is present. */
                        : `<span class="mc-nonotes">${escapeHtml(window.t('morning-checks.checklist.no_notes'))}</span>`}</td>
                    <td class="mc-checked-by">${checkedBy}</td>
                    <td class="mc-checked-at">${checkedAt}</td>
                    <td class="mc-actions">${raiseBtn}${clearBtn}</td>
                </tr>
                `;
            }).join('');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function escapeHtmlAttr(text) {
            return String(text == null ? '' : text).replace(/"/g, '&quot;');
        }

        // Escape string for use inside JavaScript single-quoted strings in onclick handlers
        function escapeJsString(text) {
            if (!text) return '';
            return text.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, '\\n').replace(/\r/g, '\\r');
        }

        // Click handler for a status button. Looks up the status by its
        // numeric StatusID, then decides whether to save immediately
        // (RequiresNotes = false) or pop the notes modal first
        // (RequiresNotes = true).
        async function handleStatusClick(checkId, statusId, existingNotes = '') {
            const s = MC_STATUSES.find(x => x.StatusID === statusId);
            if (!s) return;
            if (!s.RequiresNotes) {
                // Moving to a status that carries no notes wipes the note that is
                // already there. Ask first — the note is usually the only record of
                // WHY yesterday's amber happened, and losing it to a stray click is
                // not recoverable. (The other branch is safe: the notes modal
                // pre-fills with the existing text, so nothing is discarded.)
                if (existingNotes) {
                    const ok = await showConfirm({
                        title:   window.t('morning-checks.checklist.notes_lost_heading'),
                        message: window.t('morning-checks.checklist.notes_lost_confirm', { status: s.Label }),
                        okLabel: window.t('morning-checks.checklist.notes_lost_ok'),
                        okClass: 'danger'
                    });
                    if (!ok) return;
                }
                saveCheckResult(checkId, s.StatusID, '');
            } else {
                document.getElementById('modalCheckId').value = checkId;
                document.getElementById('modalStatusId').value = s.StatusID;
                document.getElementById('modalStatus').textContent = s.Label;
                document.getElementById('modalNotes').value = existingNotes;
                document.getElementById('notesModal').classList.add('active');
            }
        }

        async function saveCheckResult(checkId, statusId, notes) {
            const selectedDate = document.getElementById('checkDate').value;
            try {
                const response = await fetch(`${API_BASE}save_check_result.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        checkId: checkId,
                        statusId: statusId,
                        notes: notes,
                        checkDate: selectedDate
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showToast(window.t('morning-checks.toast.check_saved'), 'success');
                    loadChecks();
                    loadChart();
                    checkForOrphans();   // recount in case this save resolved orphans for the same check on the same date
                } else {
                    showToast(window.t('morning-checks.toast.save_error', { message: data.error }), 'error');
                }
            } catch (error) {
                showToast(window.t('morning-checks.toast.save_check_error', { message: error.message }), 'error');
            }
        }

        function closeNotesModal() {
            document.getElementById('notesModal').classList.remove('active');
            document.getElementById('notesForm').reset();
        }

        document.getElementById('notesForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const checkId  = parseInt(document.getElementById('modalCheckId').value, 10);
            const statusId = parseInt(document.getElementById('modalStatusId').value, 10);
            const label    = document.getElementById('modalStatus').textContent;
            const notes    = document.getElementById('modalNotes').value.trim();

            if (!notes) {
                showToast(window.t('morning-checks.notes_modal.required', { status: label }), 'error');
                return;
            }

            closeNotesModal();
            saveCheckResult(checkId, statusId, notes);
        });

        window.onclick = function(event) {
            const notesModal = document.getElementById('notesModal');
            if (event.target === notesModal && notesModal.classList.contains('active')) {
                closeNotesModal();
            }
            const raiseModal = document.getElementById('raiseTicketModal');
            if (event.target === raiseModal && raiseModal.classList.contains('active')) {
                closeRaiseTicketModal();
            }
        }

        // Raise Ticket from morning check
        /* ─── Raise a ticket or a task from a check (discussion #64) ──────────
           The old opener took no result id, so nothing could be linked back to
           the check afterwards. It also only ever made tickets. Both fixed here;
           the ticket-shaped fields hide when "Task" is chosen rather than the
           modal being duplicated. */
        let MC_RAISE_KIND = 'ticket';

        function setRaiseKind(kind) {
            MC_RAISE_KIND = (kind === 'task') ? 'task' : 'ticket';
            document.querySelectorAll('.mc-kind-btn').forEach(b =>
                b.classList.toggle('is-on', b.dataset.kind === MC_RAISE_KIND));
            // Priority/department/type belong to a ticket. A task has its own
            // shape, and showing fields that will be ignored is worse than
            // showing fewer.
            const only = document.getElementById('rtTicketOnly');
            if (only) only.style.display = (MC_RAISE_KIND === 'ticket') ? 'grid' : 'none';
        }

        async function openRaiseModal(checkId, resultId, checkName, checkDesc, status, notes) {
            document.getElementById('rtCheckId').value = checkId;
            document.getElementById('rtResultId').value = resultId === null ? '' : resultId;
            setRaiseKind('ticket');
            await openRaiseTicketModal(checkId, checkName, checkDesc, status, notes);
        }

        async function openRaiseTicketModal(checkId, checkName, checkDesc, status, notes) {
            // Lazy-load lookup lists
            try {
                if (!rtAnalystOptions.length) {
                    const r = await fetch(TICKETS_API + 'get_analysts.php');
                    const d = await r.json();
                    if (d.success) rtAnalystOptions = (d.analysts || []).filter(a => a.is_active);
                }
                if (!rtDepartmentOptions.length) {
                    const r = await fetch(TICKETS_API + 'get_departments.php');
                    const d = await r.json();
                    if (d.success) rtDepartmentOptions = (d.departments || []).filter(x => x.is_active);
                }
                if (!rtTicketTypeOptions.length) {
                    const r = await fetch(TICKETS_API + 'get_ticket_types.php');
                    const d = await r.json();
                    if (d.success) rtTicketTypeOptions = (d.ticket_types || []).filter(x => x.is_active);
                }
            } catch (e) {
                showToast(window.t('morning-checks.toast.lookup_failed', { message: e.message }), 'error');
                return;
            }

            const checkDate = document.getElementById('checkDate').value;
            const subject = window.t('morning-checks.raise_modal.subject_prefill', { name: checkName, status: status });
            let body = window.t('morning-checks.raise_modal.body_prefill', { name: checkName, status: status, date: checkDate });
            if (checkDesc) body += `\n\n` + window.t('morning-checks.raise_modal.body_description', { description: checkDesc });
            if (notes)     body += `\n\n` + window.t('morning-checks.raise_modal.body_notes', { notes: notes });

            document.getElementById('rtSubject').value = subject;
            document.getElementById('rtBody').value = body;
            // Map status → ticket priority. Used to be hard-coded Red→High,
            // others→Normal; with configurable statuses we use the
            // highest-sort-order RequiresNotes status as the "High"
            // proxy (typically the most severe — e.g. Red). Falls back
            // to Normal if no such status or the current one isn't it.
            const sevStatuses = MC_STATUSES.filter(s => s.RequiresNotes)
                                          .sort((a, b) => b.SortOrder - a.SortOrder);
            const isMostSevere = sevStatuses.length > 0 && status === sevStatuses[0].Label;
            document.getElementById('rtPriority').value = isMostSevere ? 'High' : 'Normal';

            // Populate selects
            const assigneeSel = document.getElementById('rtAssignee');
            assigneeSel.innerHTML = '<option value="">' + escapeHtml(window.t('morning-checks.raise_modal.unassigned')) + '</option>' +
                rtAnalystOptions.map(a =>
                    `<option value="${a.id}" ${a.id === SESSION_ANALYST.id ? 'selected' : ''}>${escapeHtml(a.full_name)}</option>`
                ).join('');

            const deptSel = document.getElementById('rtDepartment');
            deptSel.innerHTML = '<option value="">' + escapeHtml(window.t('morning-checks.raise_modal.select')) + '</option>' +
                rtDepartmentOptions.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');

            const typeSel = document.getElementById('rtTicketType');
            typeSel.innerHTML = '<option value="">' + escapeHtml(window.t('morning-checks.raise_modal.select')) + '</option>' +
                rtTicketTypeOptions.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');

            // Stash check id for reference (currently not persisted; included in description above)
            document.getElementById('raiseTicketModal').dataset.checkId = String(checkId);
            document.getElementById('raiseTicketModal').classList.add('active');
        }

        function closeRaiseTicketModal() {
            document.getElementById('raiseTicketModal').classList.remove('active');
            document.getElementById('raiseTicketForm').reset();
        }

        document.getElementById('raiseTicketForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('rtSubmitBtn');
            const subject = document.getElementById('rtSubject').value.trim();
            if (!subject) {
                showToast(window.t('morning-checks.toast.subject_required'), 'error');
                return;
            }
            if (!SESSION_ANALYST.email) {
                showToast(window.t('morning-checks.toast.no_email'), 'error');
                return;
            }
            btn.disabled = true;
            try {
                const checkId  = parseInt(document.getElementById('rtCheckId').value, 10);
                const resultId = document.getElementById('rtResultId').value;
                const body     = document.getElementById('rtBody').value;

                let resp, data, entityId = null, entityRef = null;

                if (MC_RAISE_KIND === 'task') {
                    resp = await fetch('../api/tasks/save.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            title: subject,
                            description: body,
                            assigned_analyst_id: document.getElementById('rtAssignee').value || null
                        })
                    });
                    data = await resp.json();
                    if (data.success) { entityId = data.id || data.task_id || null; entityRef = subject; }
                } else {
                    resp = await fetch(TICKETS_API + 'create_ticket.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            from_name: SESSION_ANALYST.name,
                            from_email: SESSION_ANALYST.email,
                            subject: subject,
                            body: body,
                            priority: document.getElementById('rtPriority').value,
                            department_id: document.getElementById('rtDepartment').value || null,
                            ticket_type_id: document.getElementById('rtTicketType').value || null,
                            assigned_analyst_id: document.getElementById('rtAssignee').value || null
                        })
                    });
                    data = await resp.json();
                    if (data.success) { entityId = data.ticket_id || null; entityRef = data.ticket_number || null; }
                }

                if (data && data.success) {
                    // Record the link so the check shows what it raised. Best
                    // effort and deliberately after the fact: the ticket or task
                    // exists either way, and failing to note the connection must
                    // not look like the ticket failed to be created.
                    if (resultId && entityId) {
                        try {
                            await fetch(API_BASE + 'link_result.php', {
                                method: 'POST', headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    result_id: parseInt(resultId, 10),
                                    entity_type: MC_RAISE_KIND,
                                    entity_id: entityId,
                                    entity_ref: entityRef
                                })
                            });
                        } catch (linkErr) { /* the breadcrumb is not worth an error toast */ }
                    }
                    showToast(MC_RAISE_KIND === 'task'
                        ? window.t('morning-checks.toast.task_created')
                        : window.t('morning-checks.toast.ticket_created', { number: entityRef || '' }), 'success');
                    closeRaiseTicketModal();
                    loadChecks();
                } else {
                    showToast(window.t('morning-checks.toast.save_error', { message: (data && data.error) || window.t('morning-checks.toast.ticket_failed') }), 'error');
                }
            } catch (err) {
                showToast(window.t('morning-checks.toast.ticket_error', { message: err.message }), 'error');
            } finally {
                btn.disabled = false;
            }
        });

        // Chart functionality
        let chartInstance = null;
        let chartRawDates = [];

        async function loadChart() {
            const selectedDate = document.getElementById('checkDate').value;
            try {
                const response = await fetch(`${API_BASE}get_chart_data.php?endDate=${selectedDate}`);
                const data = await response.json();

                if (data.error) {
                    showToast(window.t('morning-checks.toast.chart_error', { message: data.error }), 'error');
                    return;
                }

                chartRawDates = data.rawDates || [];
                displayChart(data);
            } catch (error) {
                showToast(window.t('morning-checks.toast.chart_error', { message: error.message }), 'error');
            }
        }

        function displayChart(data) {
            const canvas = document.getElementById('statusChart');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');

            if (chartInstance) chartInstance.destroy();

            // X-axis: just the day number per tick (instead of the full
            // "May 25" label). The chart heading and month name(s) move
            // to a single axis title below — frees up the chart-footer
            // header to be just the collapse chevron. When the 30-day
            // window spans two months we show both joined by an en-dash.
            // Tooltips still show the full original label so hovering a
            // bar is still informative.
            const monthTitle = monthRangeText(chartRawDates);
            const axisTitle = monthTitle
                ? window.t('morning-checks.chart.axis_title_month', { month: monthTitle })
                : window.t('morning-checks.chart.axis_title');

            // Background colour resolver — returns the solid colour when
            // CHART_FILL_STYLE is 'plain', or a function that produces a
            // top-to-bottom linear gradient when 'gradient'. Returning a
            // function lets Chart.js rebuild the gradient on every draw,
            // so it tracks the chart's actual area as it resizes.
            function barFill(baseHex) {
                if (CHART_FILL_STYLE !== 'gradient') return baseHex;
                return function(context) {
                    const chart = context.chart;
                    const { ctx, chartArea } = chart;
                    if (!chartArea) return baseHex;   // first frame before layout — fall back
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradient.addColorStop(0, lighten(baseHex, 0.45));
                    gradient.addColorStop(1, baseHex);
                    return gradient;
                };
            }

            // Build one Chart.js dataset per status returned by the API
            // (already filtered to active + ordered by SortOrder server-side).
            const datasets = (data.datasets || []).map(d => ({
                label: d.label,
                data: d.data,
                backgroundColor: barFill(d.colour)
            }));

            // Dark-mode readability: Chart.js paints to a canvas and can't
            // read our CSS tokens, so pick tick / legend / title / grid
            // colours from the active palette mode (the same data-theme-mode
            // signal TinyMCE uses elsewhere). Bars keep their status colours.
            const mcDark = document.documentElement.getAttribute('data-theme-mode') === 'dark';
            const mcTickColor = mcDark ? '#aab2bd' : '#666';
            const mcTitleColor = mcDark ? '#e6e8eb' : '#2c3e50';
            const mcGridColor = mcDark ? 'rgba(255,255,255,0.10)' : 'rgba(0,0,0,0.08)';
            Chart.defaults.color = mcTickColor;

            chartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.dates,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                            grid: { color: mcGridColor },
                            ticks: {
                                // Day-of-month only. chartRawDates is the
                                // ISO YYYY-MM-DD parallel array we
                                // already store for the click-through
                                // (parsing through Date avoids timezone
                                // skew on the day component).
                                callback: function(value, index) {
                                    const raw = chartRawDates[index];
                                    if (!raw) return '';
                                    const d = new Date(raw + 'T00:00:00');
                                    return d.getDate();
                                },
                                autoSkip: false,
                                maxRotation: 0
                            },
                            title: {
                                display: true,
                                text: axisTitle,
                                font: { size: 13, weight: '600' },
                                color: mcTitleColor,
                                padding: { top: 6 }
                            }
                        },
                        y: { stacked: true, beginAtZero: true, grid: { color: mcGridColor }, ticks: { stepSize: 1 } }
                    },
                    plugins: {
                        // Legend at the bottom — the top-right of the
                        // chart area now hosts the floating collapse
                        // chevron, so leaving the legend on top would
                        // mean the two overlap each other.
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                title: (context) => context[0].label,
                                label: (context) => context.dataset.label + ': ' + context.parsed.y
                            }
                        }
                    },
                    onClick: function(e, elements) {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            if (chartRawDates[index]) {
                                document.getElementById('checkDate').value = chartRawDates[index];
                                dateChanged();
                            }
                        }
                    }
                }
            });
        }

        // Blend a #rrggbb colour toward white by `amount` (0 = no change,
        // 1 = fully white). Used to compute the top stop of the bar
        // gradient when CHART_FILL_STYLE is 'gradient'.
        function lighten(hex, amount) {
            const r = parseInt(hex.slice(1, 3), 16);
            const g = parseInt(hex.slice(3, 5), 16);
            const b = parseInt(hex.slice(5, 7), 16);
            const nr = Math.round(r + (255 - r) * amount);
            const ng = Math.round(g + (255 - g) * amount);
            const nb = Math.round(b + (255 - b) * amount);
            return 'rgb(' + nr + ',' + ng + ',' + nb + ')';
        }

        // Build the x-axis title that replaces per-tick month names.
        // Returns 'May' if all dates fall in one month, 'May – June' if
        // the window spans two. Empty string if no dates.
        function monthRangeText(rawDates) {
            if (!rawDates || !rawDates.length) return '';
            const first = new Date(rawDates[0] + 'T00:00:00');
            const last = new Date(rawDates[rawDates.length - 1] + 'T00:00:00');
            const fmt = d => fmtNaiveTemplate(d, 'MONTH');
            const sameMonth = first.getMonth() === last.getMonth()
                && first.getFullYear() === last.getFullYear();
            return sameMonth ? fmt(first) : (fmt(first) + ' – ' + fmt(last));
        }

        function toggleChart() {
            const chartContainer = document.getElementById('chartContainer');
            const toggleIcon = document.getElementById('chartToggle');
            const divider = document.getElementById('mcDivider');
            const collapsed = chartContainer.classList.contains('collapsed');

            if (collapsed) {
                // Update the target inline height before un-collapsing
                // (handles the window-was-resized-while-collapsed case)
                // but DON'T call chartInstance.resize() yet — the canvas
                // parent is still 0px tall, that'd fit the chart to nothing.
                applyChartHeightFromPct(currentChartPct, false);
                chartContainer.classList.remove('collapsed');
                toggleIcon.textContent = '▼';
                if (divider) divider.style.display = '';
                // After the slide-out transition lands, redraw the chart
                // at the new canvas size.
                setTimeout(() => {
                    if (typeof chartInstance !== 'undefined' && chartInstance) {
                        chartInstance.resize();
                    }
                }, 280);
            } else {
                chartContainer.classList.add('collapsed');
                toggleIcon.textContent = '▲';
                // Nothing to resize once collapsed, hide the handle.
                if (divider) divider.style.display = 'none';
            }
        }

        // ===== Resizable chart =====
        // The divider between the checks list and the chart can be
        // dragged to resize the chart. The chosen split is saved per
        // analyst as a percentage of the container height so it follows
        // the user across reloads / window sizes.

        const CHART_PCT_PREF = 'mc_chart_height_pct';
        const DEFAULT_CHART_PCT = 35;   // chart takes ~a third of the available vertical space by default
        const MIN_CHART_PCT = 12;
        const MAX_CHART_PCT = 80;
        // Seed from the server-fetched value so the initial JS-applied
        // height matches what the page was painted with — no flicker.
        let currentChartPct = (typeof INITIAL_CHART_PCT === 'number' && !isNaN(INITIAL_CHART_PCT))
            ? INITIAL_CHART_PCT
            : DEFAULT_CHART_PCT;

        function applyChartHeightFromPct(pct, resizeChart) {
            // resizeChart defaults to true. Pass false when the chart is
            // hidden / collapsed (calling resize() would fit the canvas
            // to 0 and require a re-resize on expand).
            if (resizeChart === undefined) resizeChart = true;
            const container = document.querySelector('.container');
            const inner = document.getElementById('chartContainer');
            if (!container || !inner) return;
            const containerH = container.clientHeight;
            // No header bar any more — chart-footer height IS the inner
            // (canvas) height. The collapse chevron is absolutely
            // positioned so it doesn't take in-flow space.
            const innerH = Math.max(60, containerH * (pct / 100));
            inner.style.height = innerH + 'px';
            if (resizeChart && typeof chartInstance !== 'undefined' && chartInstance) {
                chartInstance.resize();
            }
        }

        // Pin the chart-container-inner height to the exact pixel value
        // for the current container size. The server-side calc() got us
        // very close; this just locks in the precise number so any later
        // adjustment (drag, window resize) starts from a clean base.
        function lockChartHeightFromInitial() {
            applyChartHeightFromPct(currentChartPct);
        }

        function saveChartHeightPref(pct) {
            // Fire-and-forget — a missed save isn't worth blocking the UI.
            fetch('../api/system/set_user_preference.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key: CHART_PCT_PREF, value: pct.toFixed(1) })
            }).catch(() => {});
        }

        function startChartResize(e) {
            const chartContainer = document.getElementById('chartContainer');
            // Don't allow drag when the chart is collapsed — nothing to resize.
            if (chartContainer && chartContainer.style.display === 'none') return;

            const container = document.querySelector('.container');
            const chartFooter = document.querySelector('.chart-footer');
            const divider = document.getElementById('mcDivider');
            const containerH = container.clientHeight;
            const startY = e.clientY;
            const startFooterH = chartFooter.offsetHeight;

            document.body.style.cursor = 'row-resize';
            document.body.style.userSelect = 'none';
            divider.classList.add('dragging');

            function onMove(ev) {
                const dy = ev.clientY - startY;
                // Cursor moving DOWN shrinks the chart; UP grows it.
                let newFooterH = startFooterH - dy;
                const minH = containerH * (MIN_CHART_PCT / 100);
                const maxH = containerH * (MAX_CHART_PCT / 100);
                newFooterH = Math.max(minH, Math.min(maxH, newFooterH));
                currentChartPct = (newFooterH / containerH) * 100;
                applyChartHeightFromPct(currentChartPct);
            }

            function onUp() {
                window.removeEventListener('mousemove', onMove);
                window.removeEventListener('mouseup', onUp);
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                divider.classList.remove('dragging');
                saveChartHeightPref(currentChartPct);
            }

            window.addEventListener('mousemove', onMove);
            window.addEventListener('mouseup', onUp);
            e.preventDefault();
        }

        // Save to PDF
        async function saveToPDF() {
            const selectedDate = document.getElementById('checkDate').value;
            const dateText = document.getElementById('dateDisplayText').textContent;
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });

            let startY = 10;

            // Add logo
            try {
                const img = new Image();
                img.crossOrigin = 'anonymous';
                await new Promise((resolve, reject) => {
                    img.onload = resolve;
                    img.onerror = reject;
                    img.src = <?php echo json_encode(brandingLogoUrl()); ?>;
                });
                const maxH = 12;
                const w = maxH * (img.width / img.height);
                doc.addImage(img, 'PNG', 10, startY, w, maxH);
                startY += maxH + 5;
            } catch (e) {
                // Continue without logo
            }

            // Add title
            doc.setFontSize(14);
            doc.setTextColor(44, 62, 80);
            doc.text(dateText, 10, startY + 5);
            startY += 12;

            // Build table data from the DOM
            const rows = document.querySelectorAll('#checksTableBody tr');
            const body = [];
            rows.forEach(row => {
                if (row.cells.length > 1) {
                    const name = row.cells[0].textContent.trim();
                    const desc = row.cells[1].textContent.trim();
                    const activeBtn = row.cells[2]?.querySelector('.status-btn.active');
                    const status = activeBtn ? activeBtn.textContent : window.t('morning-checks.checklist.not_set');
                    const notes = row.cells[3]?.textContent.trim() || window.t('morning-checks.checklist.no_notes');
                    body.push([name, desc, status, notes]);
                }
            });

            // Generate table
            doc.autoTable({
                startY: startY,
                head: [[
                    window.t('morning-checks.pdf.col_check_name'),
                    window.t('morning-checks.pdf.col_description'),
                    window.t('morning-checks.pdf.col_status'),
                    window.t('morning-checks.pdf.col_notes')
                ]],
                body: body,
                styles: { fontSize: 9, cellPadding: 3 },
                headStyles: { fillColor: [248, 249, 250], textColor: [0, 0, 0], fontStyle: 'bold' },
                columnStyles: {
                    0: { cellWidth: 35, fontStyle: 'bold' },
                    2: { cellWidth: 20, halign: 'center' },
                    3: { cellWidth: 35 }
                },
                didParseCell: function(data) {
                    if (data.section === 'body' && data.column.index === 2) {
                        const status = data.cell.raw;
                        data.cell.styles.fontStyle = 'bold';
                        // Look up the status's configured colour from MC_STATUSES
                        // and convert #rrggbb → [r,g,b] for jsPDF. Unknown
                        // statuses (e.g. a "Not set" placeholder, or a label
                        // that's been since deleted) fall back to grey.
                        const s = MC_STATUSES.find(x => x.Label === status);
                        if (s) {
                            const m = /^#([0-9a-f]{6})$/i.exec(s.Colour);
                            data.cell.styles.textColor = m
                                ? [parseInt(m[1].slice(0,2),16), parseInt(m[1].slice(2,4),16), parseInt(m[1].slice(4,6),16)]
                                : [108, 117, 125];
                        } else {
                            data.cell.styles.textColor = [108, 117, 125];
                        }
                    }
                }
            });

            doc.save(window.t('morning-checks.pdf.filename', { date: selectedDate }));
            showToast(window.t('morning-checks.toast.pdf_saved'), 'success');
        }

        // Check the orphan-results count (rows whose StatusID is NULL
        // but Status string is set — e.g. left over from a deleted
        // status). Shows / hides the warning banner accordingly.
        async function checkForOrphans() {
            try {
                const res = await fetch(API_BASE + 'get_status_orphans.php');
                const data = await res.json();
                const banner = document.getElementById('orphanBanner');
                const count  = document.getElementById('orphanBannerCount');
                if (data && data.success && data.totalOrphans > 0) {
                    const n = data.totalOrphans;
                    const labelCount = data.labels.length;
                    const resultsText = window.t(n === 1 ? 'morning-checks.orphan.banner_results_one' : 'morning-checks.orphan.banner_results_other', { n: n });
                    const labelsText = window.t(labelCount === 1 ? 'morning-checks.orphan.banner_labels_one' : 'morning-checks.orphan.banner_labels_other', { n: labelCount });
                    count.textContent = window.t('morning-checks.orphan.banner_count', { results: resultsText, labels: labelsText });
                    banner.style.display = '';
                } else {
                    banner.style.display = 'none';
                }
            } catch (e) {
                // Soft-fail — banner stays hidden
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadChecks();
            loadChart();
            checkForOrphans();
            // Pin the calc()-based initial height to an exact pixel
            // value (so drag math and window-resize handling work).
            lockChartHeightFromInitial();

            // Wire up the resize divider and keep the chosen proportion
            // consistent across window-resizes.
            const divider = document.getElementById('mcDivider');
            if (divider) divider.addEventListener('mousedown', startChartResize);
            window.addEventListener('resize', () => applyChartHeightFromPct(currentChartPct));
        });
    </script>
    <!-- The mobile layer's JS: gives this page the views hamburger (top right)
         and moves the page controls into that drawer on a phone. Loaded last so
         it can wrap the page's own globals rather than edit them. -->
    <script src="../assets/js/mobile.js?v=55"></script>
</body>
</html>
