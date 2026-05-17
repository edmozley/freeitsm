<?php
/**
 * Calendar - View scheduled tickets
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
I18n::initFromSession();

$current_page = 'calendar';

// Namespaces the JS bridge needs (calendar.js looks up months, weekdays, modal labels).
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.calendar.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/calendar.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="calendar-container">
        <div class="calendar-header">
            <button class="btn btn-secondary" onclick="changeMonth(-1)">&lt; <?php echo htmlspecialchars(t('common.calendar.previous')); ?></button>
            <h2 id="calendarTitle"></h2>
            <button class="btn btn-secondary" onclick="changeMonth(1)"><?php echo htmlspecialchars(t('common.calendar.next')); ?> &gt;</button>
            <button class="btn btn-primary" onclick="goToToday()" style="margin-left: 20px;"><?php echo htmlspecialchars(t('common.calendar.today')); ?></button>
        </div>

        <div class="calendar-weekdays">
            <div class="weekday"><?php echo htmlspecialchars(t('common.calendar.weekdays.monday')); ?></div>
            <div class="weekday"><?php echo htmlspecialchars(t('common.calendar.weekdays.tuesday')); ?></div>
            <div class="weekday"><?php echo htmlspecialchars(t('common.calendar.weekdays.wednesday')); ?></div>
            <div class="weekday"><?php echo htmlspecialchars(t('common.calendar.weekdays.thursday')); ?></div>
            <div class="weekday"><?php echo htmlspecialchars(t('common.calendar.weekdays.friday')); ?></div>
            <div class="weekday weekend"><?php echo htmlspecialchars(t('common.calendar.weekdays.saturday')); ?></div>
            <div class="weekday weekend"><?php echo htmlspecialchars(t('common.calendar.weekdays.sunday')); ?></div>
        </div>

        <div class="calendar-grid" id="calendarGrid">
            <!-- Calendar days will be rendered here -->
        </div>
    </div>

    <!-- Ticket Detail Modal -->
    <div class="modal" id="ticketModal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <span id="ticketModalTitle"><?php echo htmlspecialchars(t('tickets.calendar.modal_title')); ?></span>
            </div>
            <div class="modal-body" id="ticketModalBody">
                <!-- Ticket details will be rendered here -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeTicketModal()"><?php echo htmlspecialchars(t('common.close')); ?></button>
                <a id="ticketModalLink" href="#" class="btn btn-primary"><?php echo htmlspecialchars(t('tickets.calendar.open_in_inbox')); ?></a>
            </div>
        </div>
    </div>

    <script>window.API_BASE = '../api/tickets/'; window.INBOX_URL = 'index.php';</script>
    <script src="../assets/js/calendar.js"></script>
</body>
</html>
