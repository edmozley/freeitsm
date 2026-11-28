<?php
/**
 * An administrator's view of ANOTHER analyst's signed-in devices (GH #443, System ->
 * Analysts -> edit -> Signed-in devices). Administrators only: everyone else manages
 * their own devices from the avatar menu (api/myaccount/sessions.php), and can never
 * reach anybody else's.
 *
 *   GET  ?analyst_id=N                                       { devices: [...] }
 *   POST { "analyst_id": N, "action": "sign_out", "id": R }  sign out one of N's devices
 *   POST { "analyst_id": N, "action": "sign_out_all" }       sign N out everywhere
 *
 * The administrator's own current session is never signed out from here.
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

try {
    $conn = connectToDatabase();
    requireAdminJson($conn);
    $isGet = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
    if (!$isGet) csrfRequireWriteMethod();   // changes data: never on a GET (csrf.php)
    $in = $isGet ? $_GET : (json_decode((string)file_get_contents('php://input'), true) ?: []);
    $target = (int)($in['analyst_id'] ?? 0);
    $exists = $conn->prepare("SELECT 1 FROM analysts WHERE id = ?");
    $exists->execute([$target]);
    if ($target <= 0 || !$exists->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Analyst not found.']);
        exit;
    }
    if ($isGet) {
        echo json_encode(['success' => true, 'devices' => analystSessionList($conn, $target)]);
        exit;
    }
    $action = (string)($in['action'] ?? '');
    if ($action === 'sign_out') {
        $n = analystSessionRevokeAny($conn, $target, (int)($in['id'] ?? 0) ?: -1);
    } elseif ($action === 'sign_out_all') {
        $n = analystSessionRevokeAny($conn, $target, null);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        exit;
    }
    echo json_encode(['success' => true, 'signed_out' => $n]);
} catch (Throwable $e) {
    error_log('system/analyst_sessions.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not read the list. Try again.']);
}
