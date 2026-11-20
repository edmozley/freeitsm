<?php
/**
 * API: the organisation chart for the signed-in portal user (GH #414).
 *
 * TRAP: this endpoint is the permission, not the page or the nav. It takes the
 * user from the session, has no parameters and returns only the reader's own
 * company's current staff, with names, job titles, departments and manager ids -
 * see includes/portal_org_chart.php for why that and nothing more.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/portal_org_chart.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$userId = (int)$_SESSION['ss_user_id'];

try {
    $conn = connectToDatabase();
    requireModuleOnJson($conn, 'people');
    if (!portalOrgChartAllowed($conn, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'The organisation chart is not available']);
        exit;
    }
    $chart = portalOrgChartPeople($conn, $userId);
    echo json_encode(['success' => true, 'me' => $userId, 'people' => $chart['people'], 'truncated' => $chart['truncated']]);
} catch (Throwable $e) {
    error_log('self-service/org_chart.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not load the organisation chart']);
}
