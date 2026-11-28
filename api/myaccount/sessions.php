<?php
/**
 * Signed-in devices for the signed-in analyst (GH #443, avatar menu -> Signed-in devices).
 * Their own only - every query is keyed on the session's analyst.
 *
 *   GET                                         { devices: [...] }
 *   POST { "action": "sign_out", "id": N }      sign that device out
 *   POST { "action": "sign_out_others" }        sign out every device but this one
 *
 * A signed-out device is refused on its next request (includes/analyst_sessions.php),
 * and its Keep me signed in token goes with it. This browser is never signed out here -
 * that is what Sign out is for.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$analystId = (int)$_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        echo json_encode(['success' => true, 'devices' => analystSessionList($conn, $analystId)]);
        exit;
    }
    // Changes data: never on a GET, which a link on another site can send (csrf.php).
    csrfRequireWriteMethod();
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    if ($action === 'sign_out') {
        $id = (int)($in['id'] ?? 0);
        $n = $id > 0 ? analystSessionRevoke($conn, $analystId, $id) : 0;
    } elseif ($action === 'sign_out_others') {
        $n = analystSessionRevoke($conn, $analystId, null);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        exit;
    }
    echo json_encode(['success' => true, 'signed_out' => $n]);
} catch (Throwable $e) {
    error_log('myaccount/sessions.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not read the list. Try again.']);
}
