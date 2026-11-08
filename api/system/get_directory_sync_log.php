<?php
/**
 * API: the directory sync log.
 *
 * GET ?provider_id=N            recent runs for a provider
 * GET ?run_id=N[&action=create] what one run did, person by person
 *
 * "47 updated" is a number. This is the answer to "updated how, and who?" —
 * the only version anybody can act on, and the reason the entries table exists
 * alongside the counts.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    if (!analystIsAdmin($conn, (int)$_SESSION['analyst_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Administrator access required']);
        exit;
    }

    $runId = (int)($_GET['run_id'] ?? 0);

    if ($runId > 0) {
        $where = 'run_id = ?';
        $args  = [$runId];
        $action = (string)($_GET['action'] ?? '');
        // 'unchanged' is by far the biggest group on a healthy run and the least
        // interesting, so the UI filters it out by default rather than making
        // somebody scroll past 400 rows that say nothing happened.
        if ($action !== '' && $action !== 'all') {
            $where .= ' AND action = ?';
            $args[] = $action;
        }
        $stmt = $conn->prepare(
            "SELECT id, action, user_id, directory_username, display_name, detail, created_datetime
               FROM directory_sync_entries
              WHERE $where
              ORDER BY FIELD(action,'error','conflict','deactivate','adopt','create','update','group_join','group_leave','skip','unchanged'), id
              LIMIT 1000"
        );
        $stmt->execute($args);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = $conn->prepare("SELECT action, COUNT(*) c FROM directory_sync_entries WHERE run_id = ? GROUP BY action");
        $counts->execute([$runId]);

        echo json_encode([
            'success'  => true,
            'entries'  => $entries,
            'by_action'=> $counts->fetchAll(PDO::FETCH_KEY_PAIR),
        ]);
        exit;
    }

    $pid  = (int)($_GET['provider_id'] ?? 0);
    $stmt = $conn->prepare(
        "SELECT r.*, a.full_name AS triggered_by
           FROM directory_sync_runs r
      LEFT JOIN analysts a ON a.id = r.triggered_by_analyst_id
          WHERE r.provider_id = ?
          ORDER BY r.id DESC
          LIMIT 25"
    );
    $stmt->execute([$pid]);
    echo json_encode(['success' => true, 'runs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
