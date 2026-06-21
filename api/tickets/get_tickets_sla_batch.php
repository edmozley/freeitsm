<?php
/**
 * API: Get SLA state for a batch of tickets in one round-trip.
 *
 * Used by the inbox to populate per-row SLA indicators after the email list
 * has rendered. Calls the same sla_get_state() engine per ticket — v1 is
 * O(N * queries_per_ticket) which is fine for the ~50 tickets per inbox page.
 * If this becomes a perf bottleneck the engine could grow a proper batch
 * variant that loads lookups (settings / statuses / calendars) once.
 *
 * GET ?ticket_ids=1,2,3,4
 *
 * Returns: { sla: { '<ticket_id>': { enabled, response, resolution, ... } } }
 * Only returns rows for tickets where SLA is enabled (others are silently
 * omitted to keep the payload small).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/sla.php';
require_once '../../includes/tenancy.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$raw = $_GET['ticket_ids'] ?? '';
$ids = array_filter(array_map('intval', explode(',', $raw)));
if (empty($ids)) {
    echo json_encode(['success' => true, 'sla' => new stdClass()]);
    exit;
}
// Cap to avoid abuse / runaway queries
$ids = array_slice($ids, 0, 200);

try {
    $conn = connectToDatabase();
    $out = [];
    $analystId = (int)$_SESSION['analyst_id'];
    foreach ($ids as $id) {
        // Multi-tenancy: silently skip ids in companies this analyst can't access.
        if (!analystCanAccessTicket($conn, $analystId, $id)) continue;
        $state = sla_get_state($conn, $id);
        if ($state['enabled']) {
            // Slim the payload: callers only need the response/resolution data + the
            // priority-name for the inbox indicator. Drop the heavy nested calendar.
            $out[$id] = [
                'response'   => $state['response'],
                'resolution' => $state['resolution'],
                'priority'   => $state['priority'] ? [
                    'name'   => $state['priority']['name'],
                    'colour' => $state['priority']['colour'] ?? null,
                ] : null,
            ];
        }
    }
    echo json_encode(['success' => true, 'sla' => $out]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
