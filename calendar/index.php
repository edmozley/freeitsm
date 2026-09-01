<?php
/**
 * Calendar Module - Event tracking for certificates, contracts, maintenance, etc.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
requireModuleAccess('calendar');
I18n::initFromSession();
Tz::init();

$current_page = 'calendar';
$path_prefix = '../';
$translationNamespaces = ['common', 'calendar'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('calendar.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/calendar-grid.css?v=1">
    <link rel="stylesheet" href="../assets/css/itsm_calendar.css?v=7">
    <link rel="stylesheet" href="../assets/css/subscribe.css?v=1">
    <style>
        /* Pin the shared accent to the module orange so canonical components
           (modal .btn-primary, input focus rings, confirm dialog) are on-brand. */
        body { --accent: var(--cal-accent, #ef6c00); --accent-hover: var(--cal-accent-hover, #e65100); }
    </style>
    <!-- Mobile-friendly overrides (LAYER 16). Linked LAST of the stylesheets so
         its @media rules win ties against the module CSS and the block above. -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=127">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="calendar-container">
        <!-- Sidebar with category filters -->
        <div class="calendar-sidebar">
            <div class="sidebar-section">
                <button class="btn btn-primary btn-full" onclick="openEventModal()">+ <?php echo htmlspecialchars(t('calendar.sidebar.new_event')); ?></button>
            </div>
            <div class="sidebar-section">
                <h3><?php echo htmlspecialchars(t('calendar.sidebar.categories')); ?></h3>
                <div class="category-filter-list" id="categoryFilterList">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
            </div>
            <div class="sidebar-section calendar-subscribe">
                <h3><?php echo htmlspecialchars(t('calendar.subscribe.heading')); ?></h3>
                <p class="subscribe-intro"><?php echo htmlspecialchars(t('calendar.subscribe.intro')); ?></p>
                <button type="button" class="btn btn-primary btn-full" onclick="openSubscribeModal()"><?php echo htmlspecialchars(t('calendar.subscribe.button')); ?></button>
            </div>
        </div>

        <!-- Main calendar area -->
        <div class="calendar-main">
            <!-- Calendar header with navigation -->
            <div class="calendar-header">
                <div class="calendar-nav">
                    <button class="btn btn-secondary" onclick="goToToday()"><?php echo htmlspecialchars(t('common.calendar.today')); ?></button>
                    <button class="btn btn-icon" onclick="navigatePrev()" title="<?php echo htmlspecialchars(t('common.calendar.previous')); ?>">&lsaquo;</button>
                    <button class="btn btn-icon" onclick="navigateNext()" title="<?php echo htmlspecialchars(t('common.calendar.next')); ?>">&rsaquo;</button>
                    <h2 class="calendar-title" id="calendarTitle"></h2>
                </div>
                <div class="view-toggle">
                    <button class="view-btn active" data-view="month" onclick="setView('month')"><?php echo htmlspecialchars(t('common.calendar.view_month')); ?></button>
                    <button class="view-btn" data-view="week" onclick="setView('week')"><?php echo htmlspecialchars(t('common.calendar.view_week')); ?></button>
                    <button class="view-btn" data-view="day" onclick="setView('day')"><?php echo htmlspecialchars(t('common.calendar.view_day')); ?></button>
                </div>
            </div>

            <!-- Calendar grid -->
            <div class="calendar-grid" id="calendarGrid">
                <div class="loading"><div class="spinner"></div></div>
            </div>
        </div>
    </div>

    <!-- Event Modal -->
    <div class="modal" id="eventModal">
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header" id="eventModalTitle"><?php echo htmlspecialchars(t('calendar.event.modal_new')); ?></div>
            <div class="modal-body">
                <input type="hidden" id="eventId" value="">
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('calendar.event.title')); ?> *</label>
                    <input type="text" class="form-input" id="eventTitle" placeholder="<?php echo htmlspecialchars(t('calendar.event.title_ph')); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('calendar.event.category')); ?></label>
                    <select class="form-input" id="eventCategory">
                        <option value=""><?php echo htmlspecialchars(t('calendar.event.category_none')); ?></option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?php echo htmlspecialchars(t('calendar.event.start_date')); ?> *</label>
                        <input type="date" class="form-input" id="eventStartDate">
                    </div>
                    <div class="form-group" id="startTimeGroup">
                        <label class="form-label"><?php echo htmlspecialchars(t('calendar.event.start_time')); ?></label>
                        <input type="time" class="form-input" id="eventStartTime">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?php echo htmlspecialchars(t('calendar.event.end_date')); ?></label>
                        <input type="date" class="form-input" id="eventEndDate">
                    </div>
                    <div class="form-group" id="endTimeGroup">
                        <label class="form-label"><?php echo htmlspecialchars(t('calendar.event.end_time')); ?></label>
                        <input type="time" class="form-input" id="eventEndTime">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-checkbox">
                        <input type="checkbox" id="eventAllDay" onchange="toggleAllDay()">
                        <?php echo htmlspecialchars(t('calendar.event.all_day')); ?>
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('calendar.event.location')); ?></label>
                    <input type="text" class="form-input" id="eventLocation" placeholder="<?php echo htmlspecialchars(t('calendar.event.location_ph')); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('calendar.event.description')); ?></label>
                    <textarea class="form-textarea" id="eventDescription" rows="3" placeholder="<?php echo htmlspecialchars(t('calendar.event.description_ph')); ?>"></textarea>
                </div>
                <?php /* Attached documents (discussion #76). Hidden for a NEW event —
                         an unsaved one has no id to attach to. openEventModal() shows
                         and mounts it once the event exists. */ ?>
                <div id="eventDocuments" hidden></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger" id="deleteEventBtn" onclick="deleteEvent()" style="display: none;"><?php echo htmlspecialchars(t('calendar.event.delete')); ?></button>
                <div class="modal-footer-right">
                    <button class="btn btn-secondary" onclick="closeEventModal()"><?php echo htmlspecialchars(t('calendar.event.cancel')); ?></button>
                    <button class="btn btn-primary" onclick="saveEvent()"><?php echo htmlspecialchars(t('calendar.event.save')); ?></button>
                </div>
            </div>
        </div>
    </div>

    <?php
        // "Add to your phone". Markup + behaviour are shared with Preferences'
        // work-calendar link (includes/subscribe_modal.php, assets/js/subscribe.js)
        // — the two hand out different feeds but the act is identical, and the
        // security warning belongs on BOTH.
        require_once '../includes/subscribe_modal.php';
        renderSubscribeModal('subscribe', [
            'title'         => t('calendar.subscribe.modal_title'),
            'intro'         => t('calendar.subscribe.modal_intro'),
            'insecure'      => t('common.subscribe.insecure'),
            'address_label' => t('calendar.subscribe.address_label'),
            'address_hint'  => t('calendar.subscribe.address_hint'),
            'url_label'     => t('calendar.subscribe.url_label'),
            'copy'          => t('calendar.subscribe.copy'),
            'ios_label'     => t('calendar.subscribe.ios_label'),
            'ios_hint'      => t('calendar.subscribe.ios_hint'),
            'android_label' => t('calendar.subscribe.android_label'),
            'android_hint'  => t('calendar.subscribe.android_hint'),
            'reset'         => t('calendar.subscribe.reset'),
            'close'         => t('calendar.subscribe.close'),
        ]);
    ?>

    <!-- Event Detail Popup (for quick view) -->
    <div class="event-popup" id="eventPopup">
        <div class="event-popup-header">
            <span class="event-popup-category" id="popupCategory"></span>
            <button class="event-popup-close" onclick="closeEventPopup()">&times;</button>
        </div>
        <h4 class="event-popup-title" id="popupTitle"></h4>
        <div class="event-popup-time" id="popupTime"></div>
        <div class="event-popup-location" id="popupLocation"></div>
        <div class="event-popup-description" id="popupDescription"></div>
        <div class="event-popup-actions">
            <button class="btn btn-secondary btn-sm" onclick="editEventFromPopup()"><?php echo htmlspecialchars(t('calendar.event.edit')); ?></button>
        </div>
    </div>

    <script>window.API_BASE = '../api/calendar/';</script>
    <script src="../assets/js/itsm_calendar.js?v=2"></script>
    <script src="../assets/js/qrcode.min.js"></script>
    <script src="../assets/js/subscribe.js?v=1"></script>
    <script>
    // Behaviour is shared (assets/js/subscribe.js). This page only says WHICH
    // endpoint mints its URL and keeps the old global names working, since the
    // sidebar button and anything else still call openSubscribeModal().
    FreeITSMSubscribe.mount('subscribe', window.API_BASE + 'get_feed_url.php');
    window.openSubscribeModal  = function () { FreeITSMSubscribe.open('subscribe'); };
    window.closeSubscribeModal = function () { FreeITSMSubscribe.close('subscribe'); };

    // Click outside the dialog closes it (matches the rest of the app).
    document.addEventListener('click', function (e) {
        var modal = document.getElementById('subscribeModal');
        if (modal && e.target === modal) modal.classList.remove('active');
    });
    </script>
    </script>
    <!-- Loaded last so it can wrap the calendar's own globals. -->
    <script src="../assets/js/mobile.js?v=50"></script>
</body>
</html>
