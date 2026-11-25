<?php
/**
 * Self-service portal: Starters & leavers cases, read-only, for chosen People groups
 * (5.11.0). GET ?view=open|done.
 *
 * TRAP: portalStartersAllowed() is the rule - the page and the nav tab ask it too, but
 * a hidden tab is not a permission. Only the reader's own company's cases, and only
 * what includes/portal_starters.php says HR may see.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/portal_starters.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$userId = (int)$_SESSION['ss_user_id'];

try {
    $conn = connectToDatabase();
    requireModuleOnJson($conn, 'starters');
    if (!portalStartersAllowed($conn, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Starters & leavers cases are not available']);
        exit;
    }
    $view = ($_GET['view'] ?? '') === 'done' ? 'done' : 'open';
    echo json_encode(['success' => true, 'view' => $view] + portalStartersCases($conn, $userId, $view));
} catch (Throwable $e) {
    error_log('api/self-service/starters: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'The cases could not be loaded']);
}
