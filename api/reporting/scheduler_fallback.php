<?php
/**
 * API: start the built-in scheduler from a page (#233) - the fallback for an install
 * with no scheduled task.
 *
 * Called by the notification bell (assets/js/notification-bell.js) when its poll says
 * scheduler_due. Does nothing unless an administrator switched the fallback on under
 * Reporting -> System logs -> Background jobs and no real scheduler has run for a few
 * minutes. Ten open tabs start it once: the minute is claimed by one atomic UPDATE.
 *
 * Any signed-in analyst's page may ask. That gives them nothing: it starts only jobs
 * that are due anyway, as their own processes, and answers before they run.
 *
 * @input POST, no body
 * @output JSON {success, result: started|not_due|failed}
 *
 * @wiki Background-Jobs-Developer-Guide
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/job_scheduler.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

try {
    echo json_encode(['success' => true, 'result' => jobSchedulerFallbackStart(connectToDatabase())]);
} catch (Throwable $e) {
    error_log('[scheduler fallback] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The scheduler could not be started.']);
}
