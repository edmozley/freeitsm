<?php
/**
 * API: System -> Storage (#284). Administrators only.
 *
 * GET  - the connection (no secret), each store's location, size and move
 * POST - {action: test | save_connection | set_location | move_action, ...}
 *          test             {connection: {endpoint, region, bucket, access_key_id, secret, prefix, path_style}}
 *          save_connection  {connection: {...}}
 *          set_location     {store, location: local|s3}
 *          move_action      {store, move: retry_failed|skip_missing|remove_old|finish_keep}
 *
 * Errors come back as the service's message (written for the person saving); never a
 * database or library dump, except the storage error a Test shows.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // signed in AND an administrator
require_once '../../includes/functions.php';
require_once '../../includes/services/storage.php';

header('Content-Type: application/json');

$conn = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true] + StorageService::describe($conn));
    exit;
}

csrfRequireWriteMethod();
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$action = (string)($in['action'] ?? '');

try {
    switch ($action) {
        case 'test':
            echo json_encode(['success' => true, 'test' => StorageService::test($conn, (array)($in['connection'] ?? []))]);
            break;
        case 'save_connection':
            StorageService::saveConnection($conn, (array)($in['connection'] ?? []), $analystId);
            echo json_encode(['success' => true]);
            break;
        case 'set_location':
            $n = StorageService::setLocation($conn, (string)($in['store'] ?? ''), (string)($in['location'] ?? ''), $analystId);
            echo json_encode(['success' => true, 'queued' => $n]);
            break;
        case 'move_action':
            StorageService::moveAction($conn, (string)($in['store'] ?? ''), (string)($in['move'] ?? ''), $analystId);
            echo json_encode(['success' => true]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown request.']);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[storage] ' . $action . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong on the server. Details are in the server log.']);
}
