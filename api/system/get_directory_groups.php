<?php
/**
 * API: the groups a provider has imported, or the members of one.
 *
 * GET ?provider_id=N            — every group, active first, with member counts
 * GET ?group_id=N               — the people FreeITSM links to that group
 *
 * Read-only, administrators only (System → Authentication is an admin screen).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

try {
    $conn = connectToDatabase();
    if (!$conn->query("SHOW TABLES LIKE 'directory_groups'")->fetch()) {
        echo json_encode(['success' => true, 'groups' => [], 'not_ready' => true]);
        exit;
    }

    if (!empty($_GET['group_id'])) {
        $s = $conn->prepare(
            "SELECT u.id, u.display_name, u.email, u.job_title, u.department, u.is_active
               FROM directory_group_members m JOIN users u ON u.id = m.user_id
              WHERE m.group_id = ?
           ORDER BY u.display_name
              LIMIT 2000"
        );
        $s->execute([(int)$_GET['group_id']]);
        echo json_encode(['success' => true, 'members' => $s->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $s = $conn->prepare(
        "SELECT g.id, g.external_id, g.display_name, g.description, g.mail, g.group_kind, g.member_count,
                g.is_active, g.last_seen_in_source,
                (SELECT COUNT(*) FROM directory_group_members m WHERE m.group_id = g.id) AS linked_count
           FROM directory_groups g
          WHERE g.provider_id = ?
       ORDER BY g.is_active DESC, g.display_name"
    );
    $s->execute([(int)($_GET['provider_id'] ?? 0)]);
    echo json_encode(['success' => true, 'groups' => $s->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    // Database only: its error text (SQL, table names) is never shown to the page.
    error_log('[entra] get_directory_groups: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The groups could not be loaded. The details are in the PHP error log.']);
}
