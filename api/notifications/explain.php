<?php
/**
 * API: why is this notification in my bell? (5.8.0) - the bell's (i) panel.
 *
 * GET ?id=N -> { success, explain: {...} } for one of the signed-in analyst's OWN
 * notifications; 404 for anybody else's (never says whether the id exists). The
 * matching itself lives in includes/notification_explain.php, shared with D021.
 * Read-only.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/notification_explain.php';

header('Content-Type: application/json');
header('Cache-Control: private, no-store');

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
try {
    $x = notificationExplain(connectToDatabase(), (int)$_SESSION['analyst_id'], (int)($_GET['id'] ?? 0));
    if ($x === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Not found']);
        exit;
    }
    echo json_encode(['success' => true, 'explain' => $x]);
} catch (Throwable $e) {
    error_log('[notifications/explain] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not explain this notification.']);
}
