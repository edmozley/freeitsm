<?php
/**
 * Calendar Module - Event tracking for certificates, contracts, maintenance, etc.
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();

$current_page = 'calendar';
$path_prefix = '../';
$translationNamespaces = ['common', 'calendar'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('calendar.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=13">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=37">
    <link rel="stylesheet" href="../assets/css/calendar-grid.css?v=1">
    <link rel="stylesheet" href="../assets/css/itsm_calendar.css?v=6">
    <style>
        /* Pin the shared accent to the module orange so canonical components
           (modal .btn-primary, input focus rings, confirm dialog) are on-brand. */
        body { --accent: var(--cal-accent, #ef6c00); --accent-hover: var(--cal-accent-hover, #e65100); }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
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

    <!-- Subscribe ("Add to your phone") Modal -->
    <div class="modal" id="subscribeModal">
        <div class="modal-content subscribe-modal">
            <div class="modal-header"><?php echo htmlspecialchars(t('calendar.subscribe.modal_title')); ?></div>
            <div class="modal-body">
                <p class="subscribe-intro"><?php echo htmlspecialchars(t('calendar.subscribe.modal_intro')); ?></p>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('calendar.subscribe.address_label')); ?></label>
                    <input type="text" class="form-input" id="subscribeHost" oninput="refreshSubscribe()" autocomplete="off" autocapitalize="off" spellcheck="false">
                    <p class="form-hint"><?php echo htmlspecialchars(t('calendar.subscribe.address_hint')); ?></p>
                </div>
                <div class="subscribe-qr" id="subscribeQr"></div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('calendar.subscribe.url_label')); ?></label>
                    <div class="subscribe-url-row">
                        <input type="text" id="subscribeUrl" class="form-input subscribe-url" readonly value="">
                        <button type="button" class="btn btn-secondary btn-sm" id="subscribeCopyBtn" onclick="copySubscribeUrl()"><?php echo htmlspecialchars(t('calendar.subscribe.copy')); ?></button>
                    </div>
                </div>
                <p class="subscribe-hint"><strong><?php echo htmlspecialchars(t('calendar.subscribe.ios_label')); ?>:</strong> <?php echo htmlspecialchars(t('calendar.subscribe.ios_hint')); ?></p>
                <p class="subscribe-hint"><strong><?php echo htmlspecialchars(t('calendar.subscribe.android_label')); ?>:</strong> <?php echo htmlspecialchars(t('calendar.subscribe.android_hint')); ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="subscribe-reset" onclick="resetSubscribeUrl()"><?php echo htmlspecialchars(t('calendar.subscribe.reset')); ?></button>
                <div class="modal-footer-right">
                    <button class="btn btn-secondary" onclick="closeSubscribeModal()"><?php echo htmlspecialchars(t('calendar.subscribe.close')); ?></button>
                </div>
            </div>
        </div>
    </div>

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
    <script src="../assets/js/itsm_calendar.js"></script>
    <script src="../assets/js/qrcode.min.js"></script>
    <script>
    // "Add to your phone" — subscription modal. Fetches the analyst's feed URL once,
    // then lets the user swap the host (e.g. replace localhost with the laptop's LAN
    // IP so the phone can reach it); the URL + QR rebuild live. QR encodes the
    // webcal:// link so an iPhone camera scan offers "Subscribe".
    (function () {
        var S = null; // { scheme, host, path, suggestedHost }

        function hostValue() {
            var el = document.getElementById('subscribeHost');
            return (el && el.value.trim()) ? el.value.trim() : (S ? S.host : '');
        }
        function refresh() {
            if (!S) return;
            var host = hostValue();
            var url = S.scheme + '://' + host + S.path;
            var webcal = 'webcal://' + host + S.path;
            var input = document.getElementById('subscribeUrl');
            if (input) input.value = url;
            var qr = document.getElementById('subscribeQr');
            if (qr) {
                qr.innerHTML = '';
                try { var q = qrcode(0, 'M'); q.addData(webcal); q.make(); qr.innerHTML = q.createImgTag(4, 0); }
                catch (e) { /* QR optional — the copy link still works */ }
            }
        }
        window.refreshSubscribe = refresh;

        function applyAndPrefill(d) {
            S = { scheme: d.scheme, host: d.host, path: d.path, suggestedHost: d.suggestedHost || '' };
            var hostInput = document.getElementById('subscribeHost');
            if (hostInput) {
                // If the server was reached on localhost, default to the detected LAN
                // IP (if any) since a phone can't reach loopback.
                var isLocal = /^(localhost|127\.|\[?::1\]?)/i.test(d.host || '');
                hostInput.value = (isLocal && d.suggestedHost) ? d.suggestedHost : d.host;
            }
            refresh();
        }

        window.openSubscribeModal = function () {
            var modal = document.getElementById('subscribeModal');
            var show = function () { if (modal) modal.classList.add('active'); };
            if (S) { show(); return; }
            fetch(window.API_BASE + 'get_feed_url.php')
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d && d.success) { applyAndPrefill(d); show(); } })
                .catch(function () {});
        };
        window.closeSubscribeModal = function () {
            var modal = document.getElementById('subscribeModal');
            if (modal) modal.classList.remove('active');
        };

        window.copySubscribeUrl = function () {
            var input = document.getElementById('subscribeUrl');
            if (!input || !input.value) return;
            var done = function () {
                var b = document.getElementById('subscribeCopyBtn');
                if (!b) return;
                var prev = b.textContent;
                b.textContent = window.t('calendar.subscribe.copied');
                setTimeout(function () { b.textContent = prev; }, 1500);
            };
            input.select();
            if (navigator.clipboard) { navigator.clipboard.writeText(input.value).then(done, done); }
            else { try { document.execCommand('copy'); } catch (e) {} done(); }
        };

        window.resetSubscribeUrl = function () {
            var msg = window.t('calendar.subscribe.reset_confirm');
            var doReset = function () {
                var keepHost = hostValue();
                fetch(window.API_BASE + 'get_feed_url.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=reset'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || !d.success) return;
                        S = { scheme: d.scheme, host: d.host, path: d.path, suggestedHost: d.suggestedHost || '' };
                        var hostInput = document.getElementById('subscribeHost');
                        if (hostInput && keepHost) hostInput.value = keepHost; // keep the user's IP override
                        refresh();
                    })
                    .catch(function () {});
            };
            if (window.showConfirm) {
                showConfirm({
                    title: window.t('calendar.subscribe.reset'),
                    message: msg,
                    okLabel: window.t('calendar.subscribe.reset'),
                    okClass: 'primary',
                    onConfirm: doReset
                });
            } else if (window.confirm(msg)) { doReset(); }
        };

        // Click outside the dialog closes the modal (matches the rest of the app).
        document.addEventListener('click', function (e) {
            var modal = document.getElementById('subscribeModal');
            if (modal && e.target === modal) modal.classList.remove('active');
        });
    })();
    </script>
</body>
</html>
