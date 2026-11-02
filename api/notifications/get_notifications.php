<?php
/**
 * API: the current analyst's notifications, plus the unread count.
 *
 * Polled by the bell in the header on every page, so it is deliberately one
 * request rather than a list call and a count call.
 *
 * GET ?count_only=1 returns just the badge number — what the poll uses between
 * openings of the dropdown.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/notifications.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $unread = NotificationsService::unreadCount($conn, $analystId);

    // The badge poll is the common case and runs on every open tab. Skipping the
    // list keeps it to a single indexed COUNT.
    if (!empty($_GET['count_only'])) {
        // #233: the scheduler's fallback rides on this once-a-minute poll. Only a flag -
        // starting anything is a write, so the bell POSTs to scheduler_fallback.php for that.
        require_once '../../includes/job_scheduler.php';
        echo json_encode(['success' => true, 'unread' => $unread, 'scheduler_due' => jobSchedulerFallbackDue($conn)]);
        exit;
    }

    echo json_encode([
        'success'       => true,
        'unread'        => $unread,
        'notifications' => NotificationsService::listFor($conn, $analystId),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
