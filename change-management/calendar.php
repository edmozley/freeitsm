<?php
/**
 * Change Management Calendar - Visual timeline of scheduled changes
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}
requireModuleAccess('changes');

$current_page = 'calendar';
$path_prefix = '../';
$translationNamespaces = ['common', 'change-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('change-management.page.calendar')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=19">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/calendar-grid.css?v=1">
    <link rel="stylesheet" href="../assets/css/itsm_calendar.css?v=6">
    <style>
        /* Teal theme overrides for change management */
        .calendar-container .btn-primary {
            background: var(--cm-accent, #00897b);
            border-color: var(--cm-accent, #00897b);
        }
        .calendar-container .btn-primary:hover {
            background: var(--cm-accent-hover, #00695c);
            border-color: var(--cm-accent-hover, #00695c);
        }
        .month-day.today .day-number {
            background: var(--cm-accent, #00897b);
        }
        .view-btn.active {
            background: var(--cm-accent, #00897b);
            border-color: var(--cm-accent, #00897b);
        }
        .week-header-day.today .week-day-number {
            background: var(--cm-accent, #00897b);
        }

        /* Change popup styling */
        .change-popup {
            display: none;
            position: fixed;
            background: var(--surface, white);
            border-radius: 8px;
            box-shadow: 0 4px 20px var(--shadow, rgba(0,0,0,0.2));
            padding: 16px;
            z-index: 500;
            max-width: 320px;
            min-width: 260px;
        }
        .change-popup.active { display: block; }
        .change-popup-close {
            position: absolute;
            top: 8px;
            right: 10px;
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: var(--text-faint, #999);
            line-height: 1;
        }
        .change-popup-close:hover { color: var(--text, #333); }
        .change-popup-badges {
            display: flex;
            gap: 6px;
            margin-bottom: 8px;
        }
        .change-type-badge, .change-status-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        /* Type badges */
        .change-type-badge.standard { background: #e8f5e9; color: #2e7d32; }
        .change-type-badge.normal { background: #e3f2fd; color: #1565c0; }
        .change-type-badge.emergency { background: #fce4ec; color: #c62828; }
        /* Status badges */
        .change-status-badge.draft { background: #f0f0f0; color: #666; }
        .change-status-badge.pending-approval { background: #fff3e0; color: #e65100; }
        .change-status-badge.approved { background: #e8f5e9; color: #2e7d32; }
        .change-status-badge.in-progress { background: #e3f2fd; color: #1565c0; }
        .change-status-badge.completed { background: #e8f5e9; color: #1b5e20; }
        .change-status-badge.failed { background: #fce4ec; color: #c62828; }
        .change-status-badge.cancelled { background: #f5f5f5; color: #999; }

        .change-popup-title {
            margin: 0 0 10px;
            font-size: 15px;
            color: var(--text, #333);
            padding-right: 20px;
        }
        .change-popup-details {
            border-top: 1px solid var(--border-soft, #eee);
            padding-top: 8px;
            margin-bottom: 12px;
        }
        .change-popup-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 4px 0;
            font-size: 12px;
            color: var(--text-muted, #555);
        }
        .change-popup-label {
            font-weight: 600;
            color: var(--text-dim, #888);
            white-space: nowrap;
        }
        .change-popup-row > span:last-child {
            text-align: right;
        }
        .change-popup-actions {
            border-top: 1px solid var(--border-soft, #eee);
            padding-top: 10px;
            text-align: right;
        }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="calendar-container">
        <!-- Sidebar with status filters -->
        <div class="calendar-sidebar">
            <div class="sidebar-section">
                <h3><?php echo htmlspecialchars(t('change-management.calendar.status')); ?></h3>
                <div class="category-filter-list" id="statusFilterList">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
            </div>
        </div>

        <!-- Main calendar area -->
        <div class="calendar-main">
            <!-- Calendar header with navigation -->
            <div class="calendar-header">
                <div class="calendar-nav">
                    <button class="btn btn-secondary" onclick="goToToday()"><?php echo htmlspecialchars(t('change-management.calendar.today')); ?></button>
                    <button class="btn btn-icon" onclick="navigatePrev()" title="<?php echo htmlspecialchars(t('change-management.calendar.prev')); ?>">&lsaquo;</button>
                    <button class="btn btn-icon" onclick="navigateNext()" title="<?php echo htmlspecialchars(t('change-management.calendar.next')); ?>">&rsaquo;</button>
                    <h2 class="calendar-title" id="calendarTitle"></h2>
                </div>
                <div class="view-toggle">
                    <button class="view-btn active" data-view="month" onclick="setView('month')"><?php echo htmlspecialchars(t('change-management.calendar.month')); ?></button>
                    <button class="view-btn" data-view="week" onclick="setView('week')"><?php echo htmlspecialchars(t('change-management.calendar.week')); ?></button>
                    <button class="view-btn" data-view="day" onclick="setView('day')"><?php echo htmlspecialchars(t('change-management.calendar.day')); ?></button>
                </div>
            </div>

            <!-- Calendar grid -->
            <div class="calendar-grid" id="calendarGrid">
                <div class="loading"><div class="spinner"></div></div>
            </div>
        </div>
    </div>

    <!-- Change Detail Popup -->
    <div class="change-popup" id="changePopup">
        <button class="change-popup-close" onclick="closeChangePopup()">&times;</button>
        <div id="changePopupContent"></div>
    </div>

    <script>window.API_BASE = '../api/change-management/';</script>
    <script src="../assets/js/change-calendar.js?v=3"></script>
</body>
</html>
