<?php
/**
 * People (#153 step 2) — GET ?q=&company=&group=&status=active|leavers|all
 * group is a directory_groups id (an imported Entra group); see peopleGroups().
 * The people this analyst can see: only companies they can access.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/people.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('people');

try {
    $conn = connectToDatabase();
    $aid = (int)$_SESSION['analyst_id'];
    $status = in_array($_GET['status'] ?? '', ['active', 'leavers', 'all'], true) ? $_GET['status'] : 'active';
    $limit = 300;
    $rows = peopleListRows($conn, $aid, [
        'q' => (string)($_GET['q'] ?? ''),
        'company' => (int)($_GET['company'] ?? 0) ?: null,
        'group' => (int)($_GET['group'] ?? 0) ?: null,
        'status' => $status,
    ], $limit + 1);
    $more = count($rows) > $limit;
    echo json_encode(['success' => true, 'people' => array_slice($rows, 0, $limit), 'more' => $more, 'limit' => $limit]);
} catch (Throwable $e) {
    error_log('people list: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The list could not be loaded.']);
}
