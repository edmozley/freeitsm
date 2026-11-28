<?php
/**
 * The browsers the signed-in analyst is kept signed in on (GH #443, avatar menu ->
 * Kept signed in). Their own only - every query is keyed on the session's analyst.
 *
 *   GET                                      { days, devices: [...] }
 *   POST { "action": "remove", "id": N }     stop keeping that browser signed in
 *   POST { "action": "remove_all" }          stop it on every browser, this one included
 *
 * Removing a browser ends "Keep me signed in" there: its current session carries on
 * until it times out or is closed, and the next sign-in asks for the password again.
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
        echo json_encode(['success' => true, 'days' => keepSignedInDays($conn), 'devices' => keepSignedInList($conn, $analystId)]);
        exit;
    }
    // Changes data: never on a GET, which a link on another site can send (csrf.php).
    csrfRequireWriteMethod();
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    $mine = array_values(array_filter(keepSignedInList($conn, $analystId), fn($d) => $d['this_device']));
    if ($action === 'remove') {
        $id = (int)($in['id'] ?? 0);
        $removed = $id > 0 ? keepSignedInRevoke($conn, $analystId, $id) : 0;
        if ($removed && $mine && $mine[0]['id'] === $id) keepSignedInSetCookie('');
    } elseif ($action === 'remove_all') {
        $removed = keepSignedInRevoke($conn, $analystId, null);
        keepSignedInSetCookie('');
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        exit;
    }
    echo json_encode(['success' => true, 'removed' => $removed]);
} catch (Throwable $e) {
    error_log('keep_signed_in.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not read the list. Try again.']);
}
