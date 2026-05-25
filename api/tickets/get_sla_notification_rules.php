<?php
/**
 * API: List all SLA breach notification rules + auxiliary data needed to
 * render the settings UI in a single round-trip.
 *
 * Returns: { rules: [...], departments: [...], analysts: [...] }
 *   - rules are ordered global-default-first, then by department name
 *   - departments + analysts include only active rows (id, name)
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

    $rules = $conn->query("
        SELECT r.id, r.department_id, d.name AS department_name,
               r.trigger_type, r.target_type,
               r.notify_assignee, r.notify_department_teams,
               r.notify_analyst_id, a.full_name AS notify_analyst_name,
               r.notify_emails, r.is_active
          FROM sla_notification_rules r
     LEFT JOIN departments d ON d.id = r.department_id
     LEFT JOIN analysts a    ON a.id = r.notify_analyst_id
      ORDER BY (r.department_id IS NULL) DESC, d.name ASC, r.trigger_type ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rules as &$r) {
        $r['notify_assignee']         = (bool)$r['notify_assignee'];
        $r['notify_department_teams'] = (bool)$r['notify_department_teams'];
        $r['is_active']               = (bool)$r['is_active'];
    }

    $departments = $conn->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $analysts    = $conn->query("SELECT id, full_name FROM analysts WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'     => true,
        'rules'       => $rules,
        'departments' => $departments,
        'analysts'    => $analysts,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
