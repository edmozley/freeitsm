<?php
/**
 * System -> API -> Threat protection: the API security log (GH #287).
 *
 * GET ?key_id=&event=&page= -> { success, events: [...], total, page, per_page }
 * Newest first, 50 a page. Administrators only. The "...alerted" and "seen_ip"
 * bookkeeping rows are left out unless asked for by name.
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
    if (!apiSecurityReady($conn)) { echo json_encode(['success' => true, 'ready' => false, 'events' => [], 'total' => 0]); exit; }
    $where = []; $args = [];
    if (!empty($_GET['key_id'])) { $where[] = 'e.api_key_id = ?'; $args[] = (int)$_GET['key_id']; }
    if (!empty($_GET['event']) && preg_match('/^[a-z_]{1,30}$/', (string)$_GET['event'])) { $where[] = 'e.event = ?'; $args[] = (string)$_GET['event']; }
    else { $where[] = "e.event NOT LIKE '%\_alerted'"; $where[] = "e.event <> 'seen_ip'"; }
    $page = max(1, (int)($_GET['page'] ?? 1)); $per = 50;
    $sql = implode(' AND ', $where);
    $c = $conn->prepare("SELECT COUNT(*) FROM api_key_events e WHERE $sql");
    $c->execute($args);
    $st = $conn->prepare("SELECT e.id, e.api_key_id, k.name AS key_name, e.event, e.ip_address, e.detail, e.created_datetime
                            FROM api_key_events e LEFT JOIN api_keys k ON k.id = e.api_key_id
                           WHERE $sql ORDER BY e.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    echo json_encode(['success' => true, 'ready' => true, 'events' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => (int)$c->fetchColumn(), 'page' => $page, 'per_page' => $per]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
