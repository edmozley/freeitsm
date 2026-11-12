<?php
/**
 * System -> API -> Activity: the API activity log (GH #287).
 *
 * GET ?key_id=&failures=1&path=&page= -> { success, ready, rows, total, page, per_page, summary }
 * Newest first, 50 a page. summary = today's calls and failures, and the last 24 hours by key.
 * Administrators only.
 */
session_start(['read_and_close' => true]);
require_once '../../../config.php';
require_once '../../../includes/admin_api_guard.php'; // System admins only
require_once '../../../includes/functions.php';
require_once '../../../includes/api_security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    if (!apiActivityReady($conn)) { echo json_encode(['success' => true, 'ready' => false, 'rows' => [], 'total' => 0]); exit; }
    $where = []; $args = [];
    if (($_GET['key_id'] ?? '') === 'none') $where[] = 'l.api_key_id IS NULL';
    elseif (!empty($_GET['key_id'])) { $where[] = 'l.api_key_id = ?'; $args[] = (int)$_GET['key_id']; }
    if (!empty($_GET['failures'])) $where[] = 'l.status >= 400';
    if (trim((string)($_GET['path'] ?? '')) !== '') { $where[] = 'l.path LIKE ?'; $args[] = '%' . addcslashes(trim((string)$_GET['path']), '%_\\') . '%'; }
    $sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $page = max(1, (int)($_GET['page'] ?? 1)); $per = 50;
    $c = $conn->prepare("SELECT COUNT(*) FROM api_request_log l $sql");
    $c->execute($args);
    $st = $conn->prepare("SELECT l.id, l.api_key_id, k.name AS key_name, l.channel, l.method, l.path, l.status, l.error_code, l.duration_ms, l.bytes_out, l.ip_address, l.created_datetime
                            FROM api_request_log l LEFT JOIN api_keys k ON k.id = l.api_key_id
                           $sql ORDER BY l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    $today = $conn->query("SELECT COUNT(*) AS calls, COALESCE(SUM(status >= 400), 0) AS failures FROM api_request_log WHERE created_datetime >= UTC_DATE()")->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'success' => true, 'ready' => true,
        'rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => (int)$c->fetchColumn(), 'page' => $page, 'per_page' => $per,
        'summary' => ['calls_today' => (int)$today['calls'], 'failures_today' => (int)$today['failures']],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
