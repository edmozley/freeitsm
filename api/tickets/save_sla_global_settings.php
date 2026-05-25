<?php
/**
 * API: Save the seven SLA global settings (system_settings rows).
 *
 * POST JSON: { sla_enforce_from, sla_priority_change_behaviour, sla_reopen_behaviour,
 *              sla_warning_threshold_percent, sla_notify_assignee_at_warning,
 *              sla_notify_lead_at_breach, sla_first_response_definition }
 *
 * Validation: enums are checked against the allowed set; the threshold is bounded
 * 1-100; sla_enforce_from must parse as a datetime or be empty/null.
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
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate enums — refuse junk values rather than silently accepting
    $allowedPriorityChange  = ['forward', 'recompute', 'reset'];
    $allowedReopen          = ['reset', 'continue'];
    $allowedFirstResponse   = ['outbound_email', 'status_change', 'either'];

    $values = [
        'sla_enforce_from'                => $data['sla_enforce_from'] ?? null,
        'sla_priority_change_behaviour'   => $data['sla_priority_change_behaviour'] ?? null,
        'sla_reopen_behaviour'            => $data['sla_reopen_behaviour'] ?? null,
        'sla_warning_threshold_percent'   => $data['sla_warning_threshold_percent'] ?? null,
        'sla_notify_assignee_at_warning'  => !empty($data['sla_notify_assignee_at_warning']) ? '1' : '0',
        'sla_notify_lead_at_breach'       => !empty($data['sla_notify_lead_at_breach']) ? '1' : '0',
        'sla_first_response_definition'   => $data['sla_first_response_definition'] ?? null,
    ];

    // Normalise enforce_from: empty/null → NULL in DB; otherwise convert to MySQL DATETIME
    if (empty($values['sla_enforce_from'])) {
        $values['sla_enforce_from'] = null;
    } else {
        $ts = strtotime($values['sla_enforce_from']);
        if ($ts === false) throw new Exception('Invalid datetime for sla_enforce_from');
        $values['sla_enforce_from'] = date('Y-m-d H:i:s', $ts);
    }

    if ($values['sla_priority_change_behaviour'] !== null && !in_array($values['sla_priority_change_behaviour'], $allowedPriorityChange, true)) {
        throw new Exception('Invalid sla_priority_change_behaviour');
    }
    if ($values['sla_reopen_behaviour'] !== null && !in_array($values['sla_reopen_behaviour'], $allowedReopen, true)) {
        throw new Exception('Invalid sla_reopen_behaviour');
    }
    if ($values['sla_first_response_definition'] !== null && !in_array($values['sla_first_response_definition'], $allowedFirstResponse, true)) {
        throw new Exception('Invalid sla_first_response_definition');
    }

    $threshold = $values['sla_warning_threshold_percent'];
    if ($threshold !== null && $threshold !== '') {
        $t = (int)$threshold;
        if ($t < 1 || $t > 100) throw new Exception('Warning threshold must be between 1 and 100');
        $values['sla_warning_threshold_percent'] = (string)$t;
    }

    $conn = connectToDatabase();
    $upsert = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
                              VALUES (?, ?, NOW())
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = NOW()");

    foreach ($values as $key => $val) {
        $upsert->execute([$key, $val]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
