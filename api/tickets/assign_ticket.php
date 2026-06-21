<?php
/**
 * API Endpoint: Assign ticket to department and/or ticket type
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/workflow/includes/engine.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $ticket_id = $data['ticket_id'] ?? null;
    $department_id = $data['department_id'] ?? null;
    $ticket_type_id = $data['ticket_type_id'] ?? null;
    $status = $data['status'] ?? null;
    $origin_id = array_key_exists('origin_id', $data) ? $data['origin_id'] : null;
    $first_time_fix = array_key_exists('first_time_fix', $data) ? $data['first_time_fix'] : null;
    $it_training_provided = array_key_exists('it_training_provided', $data) ? $data['it_training_provided'] : null;
    // Priority is optional — set explicitly via the detail-panel dropdown.
    // Empty string / null clears it (no priority assigned).
    $priority_id = array_key_exists('priority_id', $data) ? $data['priority_id'] : null;
    $priorityWasSent = array_key_exists('priority_id', $data);
    // Assignment changes ONLY when assigned_analyst_id is supplied explicitly
    // (e.g. drag-to-analyst-folder or the Owner field) — see the assignment
    // tracking block below.
    $explicitAnalyst = array_key_exists('assigned_analyst_id', $data);
    $explicitAnalystId = $explicitAnalyst
        ? (($data['assigned_analyst_id'] === '' || $data['assigned_analyst_id'] === null) ? null : (int)$data['assigned_analyst_id'])
        : null;

    if (!$ticket_id) {
        throw new Exception('Ticket ID is required');
    }

    $conn = connectToDatabase();

    // Fetch current ticket state for change detection
    $currentStmt = $conn->prepare(
        "SELECT t.assigned_analyst_id, t.status_id AS old_status_id, t.priority_id AS old_priority_id,
                ts.name AS status, ts.is_closed AS old_is_closed
         FROM tickets t
         LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         WHERE t.id = ?"
    );
    $currentStmt->execute([$ticket_id]);
    $currentTicket = $currentStmt->fetch(PDO::FETCH_ASSOC);
    $oldAnalystId = $currentTicket ? $currentTicket['assigned_analyst_id'] : null;
    $oldStatus = $currentTicket ? $currentTicket['status'] : null;
    $oldIsClosed = $currentTicket ? (int)($currentTicket['old_is_closed'] ?? 0) : 0;
    $oldStatusId = $currentTicket ? ($currentTicket['old_status_id'] !== null ? (int)$currentTicket['old_status_id'] : null) : null;
    $oldPriorityId = $currentTicket ? ($currentTicket['old_priority_id'] !== null ? (int)$currentTicket['old_priority_id'] : null) : null;

    // Resolve incoming status name -> id (and the new status's is_closed flag)
    $newStatusId = null;
    $newIsClosed = null;
    if ($status !== null) {
        $sLookup = $conn->prepare("SELECT id, is_closed FROM ticket_statuses WHERE name = ? LIMIT 1");
        $sLookup->execute([$status]);
        $sRow = $sLookup->fetch(PDO::FETCH_ASSOC);
        if (!$sRow) {
            throw new Exception("Unknown status: {$status}");
        }
        $newStatusId = (int)$sRow['id'];
        $newIsClosed = (int)$sRow['is_closed'];
    }

    // Build dynamic SQL based on what's being updated
    $updates = [];
    $params = [];

    if ($department_id !== null) {
        $updates[] = "department_id = ?";
        $params[] = $department_id === '' ? null : $department_id;
    }

    if ($ticket_type_id !== null) {
        $updates[] = "ticket_type_id = ?";
        $params[] = $ticket_type_id === '' ? null : $ticket_type_id;
    }

    if ($status !== null) {
        $updates[] = "status_id = ?";
        $params[] = $newStatusId;
        // Set closed_datetime when closing
        if ($newIsClosed && !$oldIsClosed) {
            $updates[] = "closed_datetime = UTC_TIMESTAMP()";
        }
        // Clear closed_datetime if reopening
        if (!$newIsClosed && $oldIsClosed) {
            $updates[] = "closed_datetime = NULL";
        }
    }

    if (array_key_exists('origin_id', $data)) {
        $updates[] = "origin_id = ?";
        $params[] = $origin_id === '' ? null : $origin_id;
    }

    if (array_key_exists('first_time_fix', $data)) {
        $updates[] = "first_time_fix = ?";
        $params[] = $first_time_fix;
    }

    if (array_key_exists('it_training_provided', $data)) {
        $updates[] = "it_training_provided = ?";
        $params[] = $it_training_provided;
    }

    if ($priorityWasSent) {
        $updates[] = "priority_id = ?";
        $params[] = ($priority_id === '' || $priority_id === null) ? null : (int)$priority_id;
    }

    // Add assignment tracking. Assignment changes ONLY when an analyst is
    // supplied explicitly (drag-to-analyst-folder / Owner field), and then we
    // set both assigned_analyst_id and owner_id so they stay in sync. Editing a
    // ticket's department, type or status no longer auto-assigns it to the
    // current user — that previously stole an existing assignment and desynced
    // assigned_analyst_id from owner_id.
    $newAnalystId = null;
    if ($explicitAnalyst) {
        $updates[] = "assigned_analyst_id = ?";
        $newAnalystId = $explicitAnalystId;
        $params[] = $newAnalystId;
        $updates[] = "owner_id = ?";
        $params[] = $newAnalystId;
    }

    if (empty($updates)) {
        throw new Exception('No updates specified');
    }

    // Always update the updated_datetime
    $updates[] = "updated_datetime = UTC_TIMESTAMP()";

    $params[] = $ticket_id;

    $sql = "UPDATE tickets SET " . implode(", ", $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['success' => true]);

    // Trigger template emails after successful update (non-blocking)
    try {
        require_once dirname(dirname(__DIR__)) . '/includes/template_email.php';

        // Trigger ticket_assigned if analyst actually changed
        if ($newAnalystId !== null && (string)$newAnalystId !== (string)$oldAnalystId) {
            sendTemplateEmail($conn, $ticket_id, 'ticket_assigned');
        }

        // Trigger ticket_closed if status changed to a closed state
        if ($newIsClosed && !$oldIsClosed) {
            sendTemplateEmail($conn, $ticket_id, 'ticket_closed');

            // CSAT auto-trigger — only when mode is 'auto'. Manual mode requires the
            // analyst to click "Request feedback" explicitly. Off mode no-ops inside
            // sendCsatSurvey() anyway, but checking here saves a settings round-trip.
            require_once dirname(dirname(__DIR__)) . '/includes/csat.php';
            try {
                if (csatGetSetting($conn, 'csat_mode', 'off') === 'auto') {
                    sendCsatSurvey($conn, (int)$ticket_id, (int)($_SESSION['analyst_id'] ?? 0) ?: null);
                }
            } catch (Exception $csEx) {
                error_log('CSAT auto-trigger failed for ticket ' . $ticket_id . ': ' . $csEx->getMessage());
            }
        }
    } catch (Exception $tplEx) {
        error_log('Template email error in assign_ticket: ' . $tplEx->getMessage());
    }

    // Workflow engine dispatches — fire after the update is durable. The
    // engine swallows its own errors, but wrap defensively so an engine
    // outage can never break the ticket save response (already echoed).
    try {
        // Read back the FULL post-update ticket state once so every
        // dispatch payload carries the same canonical `ticket` object.
        // Without this, conditions on fields the user didn't directly
        // change (e.g. condition = priority is critical on a
        // ticket.assigned event) read null and skip the workflow.
        $ticketReadBack = $conn->prepare(
            "SELECT t.id, t.subject, t.priority_id, t.status_id, t.department_id,
                    t.ticket_type_id AS type_id, t.assigned_analyst_id,
                    t.owner_id, t.origin_id, t.user_id AS created_by,
                    u.email AS requester_email
             FROM tickets t
             LEFT JOIN users u ON u.id = t.user_id
             WHERE t.id = ?"
        );
        $ticketReadBack->execute([$ticket_id]);
        $ticketRow = $ticketReadBack->fetch(PDO::FETCH_ASSOC) ?: [];

        $ticketPayload = [
            'id'                  => (int)$ticket_id,
            'subject'             => isset($ticketRow['subject'])             ? (string)$ticketRow['subject'] : null,
            'priority_id'         => isset($ticketRow['priority_id'])         ? (int)$ticketRow['priority_id'] : null,
            'status_id'           => isset($ticketRow['status_id'])           ? (int)$ticketRow['status_id']   : null,
            'department_id'       => isset($ticketRow['department_id'])       ? (int)$ticketRow['department_id'] : null,
            'type_id'             => isset($ticketRow['type_id'])             ? (int)$ticketRow['type_id']     : null,
            'assigned_analyst_id' => isset($ticketRow['assigned_analyst_id']) ? (int)$ticketRow['assigned_analyst_id'] : null,
            'owner_id'            => isset($ticketRow['owner_id'])            ? (int)$ticketRow['owner_id']    : null,
            'origin_id'           => isset($ticketRow['origin_id'])           ? (int)$ticketRow['origin_id']   : null,
            'created_by'          => isset($ticketRow['created_by'])          ? (int)$ticketRow['created_by']  : null,
            'requester_email'     => isset($ticketRow['requester_email'])     ? (string)$ticketRow['requester_email'] : null,
        ];

        // ticket.status_changed
        if ($newStatusId !== null && $newStatusId !== $oldStatusId) {
            WorkflowEngine::dispatch('ticket.status_changed', [
                'ticket'        => $ticketPayload,
                'old_status_id' => $oldStatusId,
                'new_status_id' => $newStatusId,
            ]);
        }

        // ticket.priority_changed
        $newPriorityId = $ticketPayload['priority_id'];
        if ($priorityWasSent && (string)$newPriorityId !== (string)$oldPriorityId) {
            WorkflowEngine::dispatch('ticket.priority_changed', [
                'ticket'          => $ticketPayload,
                'old_priority_id' => $oldPriorityId,
                'new_priority_id' => $newPriorityId,
            ]);
        }

        // ticket.assigned
        if ($newAnalystId !== null && (string)$newAnalystId !== (string)$oldAnalystId) {
            WorkflowEngine::dispatch('ticket.assigned', [
                'ticket'     => $ticketPayload,
                'analyst_id' => $newAnalystId,
                'team_id'    => null,
            ]);
        }
    } catch (Exception $wfEx) {
        error_log('Workflow dispatch error in assign_ticket: ' . $wfEx->getMessage());
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
