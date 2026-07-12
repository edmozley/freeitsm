<?php
/**
 * API: list outbound webhook deliveries for the log page. Read-only.
 * Optional filters: ?status=pending|delivering|delivered|failed|dead, ?q=<url text>.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../includes/webhook_delivery.php';   // webhookDiagnoseError()
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $where = ['1=1'];
    $args  = [];
    $status = $_GET['status'] ?? '';
    if (in_array($status, ['pending', 'delivering', 'delivered', 'failed', 'dead'], true)) {
        $where[] = 'd.status = ?';
        $args[]  = $status;
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = 'd.url LIKE ?';
        $args[]  = '%' . trim($_GET['q']) . '%';
    }
    $whereSql = implode(' AND ', $where);
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));

    // Summary counts per status (for the filter chips).
    $summary = [];
    foreach ($conn->query("SELECT status, COUNT(*) c FROM webhook_deliveries GROUP BY status") as $r) {
        $summary[$r['status']] = (int)$r['c'];
    }

    $stmt = $conn->prepare(
        "SELECT d.id, d.workflow_id, w.name AS workflow_name, d.preset, d.url, d.method,
                d.status, d.attempts, d.max_attempts, d.last_status_code, d.last_error,
                d.response_snippet, d.request_headers, d.request_body,
                d.created_datetime, d.delivered_datetime, d.next_attempt_at
         FROM webhook_deliveries d
         LEFT JOIN workflows w ON w.id = d.workflow_id
         WHERE $whereSql
         ORDER BY d.id DESC
         LIMIT $limit"
    );
    $stmt->execute($args);
    $rows = array_map(function ($r) {
        return [
            'id'             => (int)$r['id'],
            'workflow'       => $r['workflow_name'] ?: ($r['workflow_id'] ? '#' . $r['workflow_id'] : '(deleted)'),
            'preset'         => $r['preset'],
            'url'            => $r['url'],
            'method'         => $r['method'],
            'status'         => $r['status'],
            'attempts'       => (int)$r['attempts'],
            'max_attempts'   => (int)$r['max_attempts'],
            'last_status'    => $r['last_status_code'] !== null ? (int)$r['last_status_code'] : null,
            'last_error'     => $r['last_error'],
            // Diagnosed at render time from the stored error, so historic rows
            // benefit too — no column, nothing to backfill.
            'diagnosis'      => webhookDiagnoseError($r['last_error']),
            'response'       => $r['response_snippet'],
            'headers'        => json_decode($r['request_headers'] ?: '[]', true) ?: [],
            'body'           => $r['request_body'],
            'created'        => $r['created_datetime'],
            'delivered'      => $r['delivered_datetime'],
            'next_attempt'   => $r['next_attempt_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(['success' => true, 'deliveries' => $rows, 'summary' => $summary]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
