<?php
/**
 * API Endpoint: Create a new ticket manually
 * Creates a ticket and an initial "email" entry for display in the inbox
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once dirname(dirname(__DIR__)) . '/workflow/includes/engine.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId = (int)$_SESSION['analyst_id'];
$analystName = $_SESSION['analyst_name'] ?? 'Unknown';

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$fromName = trim($input['from_name'] ?? '');
$fromEmail = trim($input['from_email'] ?? '');
$subject = trim($input['subject'] ?? '');
$body = trim($input['body'] ?? '');
$departmentId = !empty($input['department_id']) ? (int)$input['department_id'] : null;
$ticketTypeId = !empty($input['ticket_type_id']) ? (int)$input['ticket_type_id'] : null;
$priority = $input['priority'] ?? 'Normal';
$assignedAnalystId = !empty($input['assigned_analyst_id']) ? (int)$input['assigned_analyst_id'] : $analystId;
// The mailbox replies will be sent FROM (optional). Stamped on the initial
// email so the reply path can resolve a sender. Validated below against the
// active company so a foreign company's pinned mailbox can't be chosen.
$mailboxId = !empty($input['mailbox_id']) ? (int)$input['mailbox_id'] : null;

// Validate required fields
if (empty($fromName)) {
    echo json_encode(['success' => false, 'error' => 'Requester name is required']);
    exit;
}

if (empty($fromEmail)) {
    echo json_encode(['success' => false, 'error' => 'Requester email is required']);
    exit;
}

if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid email address']);
    exit;
}

if (empty($subject)) {
    echo json_encode(['success' => false, 'error' => 'Subject is required']);
    exit;
}

try {
    $conn = connectToDatabase();
    $conn->beginTransaction();

    // Check if user exists with this email, create if not
    $userId = null;
    $userCheckSql = "SELECT id FROM users WHERE email = ?";
    $userCheckStmt = $conn->prepare($userCheckSql);
    $userCheckStmt->execute([$fromEmail]);
    $existingUser = $userCheckStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingUser) {
        $userId = $existingUser['id'];
    } else {
        // Create new user
        $createUserSql = "INSERT INTO users (email, display_name, created_at) VALUES (?, ?, UTC_TIMESTAMP())";
        $createUserStmt = $conn->prepare($createUserSql);
        $createUserStmt->execute([$fromEmail, $fromName]);
        $userId = $conn->lastInsertId();
    }

    // Generate ticket number
    $ticketNumber = generateTicketNumber($conn);

    // Stamp the ticket with the analyst's active company (multi-tenancy).
    // At N=1 this is simply the Default company.
    $tenantId = getActiveTenantId($conn, $analystId);

    // Validate the chosen mailbox: it must exist and, on a multi-tenant install,
    // belong to the active company (pinned) or be shared intake (tenant_id NULL).
    // This stops a foreign company's pinned mailbox being attached to the ticket.
    if ($mailboxId !== null) {
        $mbStmt = $conn->prepare("SELECT tenant_id FROM target_mailboxes WHERE id = ? AND is_active = 1");
        $mbStmt->execute([$mailboxId]);
        $mb = $mbStmt->fetch(PDO::FETCH_ASSOC);
        $mbOk = (bool)$mb;
        if ($mb && isMultiTenant($conn)) {
            $mbOk = ($mb['tenant_id'] === null) || ((int)$mb['tenant_id'] === (int)$tenantId);
        }
        if (!$mbOk) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => 'The selected mailbox is not available for this company.']);
            exit;
        }
    }

    // Create the ticket and get the ID. Resolve status/priority names to ids via subselects.
    $ticketSql = "INSERT INTO tickets (
        tenant_id, ticket_number, subject, status_id, priority_id, department_id, ticket_type_id,
        assigned_analyst_id, user_id, created_datetime, updated_datetime
    ) VALUES (
        ?, ?, ?,
        (SELECT id FROM ticket_statuses   WHERE name = 'Open' LIMIT 1),
        (SELECT id FROM ticket_priorities WHERE name = ? LIMIT 1),
        ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()
    )";

    $ticketStmt = $conn->prepare($ticketSql);
    $ticketStmt->execute([
        $tenantId,
        $ticketNumber,
        $subject,
        $priority,
        $departmentId,
        $ticketTypeId,
        $assignedAnalystId,
        $userId,
    ]);

    $ticketId = $conn->lastInsertId();

    // Create an initial "email" entry (this makes it appear in the inbox like other tickets)
    // Direction is 'Manual' to indicate it was manually created
    $bodyHtml = nl2br(htmlspecialchars($body));
    $bodyPreview = substr(strip_tags($body), 0, 200);

    $emailSql = "INSERT INTO emails (
        mailbox_id, subject, from_address, from_name, to_recipients, received_datetime,
        body_preview, body_content, body_type, has_attachments, importance,
        is_read, ticket_id, is_initial, direction
    ) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, 'html', 0, 'normal', 1, ?, 1, 'Manual')";

    $emailStmt = $conn->prepare($emailSql);
    $emailStmt->execute([
        $mailboxId,  // send-from mailbox (NULL if none chosen — reply won't be sendable)
        $subject,
        $fromEmail,
        $fromName,
        $fromEmail,  // to_recipients = the requester
        $bodyPreview,
        $bodyHtml,
        $ticketId
    ]);

    // Log the creation in ticket audit
    $auditSql = "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
                 VALUES (?, ?, 'Ticket Created', NULL, CONCAT('Manual ticket created by ', ?), UTC_TIMESTAMP())";
    $auditStmt = $conn->prepare($auditSql);
    $auditStmt->execute([$ticketId, $analystId, $analystName]);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Ticket created successfully',
        'ticket_id' => $ticketId,
        'ticket_number' => $ticketNumber
    ]);

    // Workflow engine: ticket.created. Read back the resolved status_id /
    // priority_id (the INSERT used subselects on name) so the payload carries
    // real ids — that's what condition fields like ticket.priority_id compare
    // against. Engine swallows its own errors; outer try/catch is belt+braces.
    try {
        $readBack = $conn->prepare("SELECT status_id, priority_id FROM tickets WHERE id = ?");
        $readBack->execute([$ticketId]);
        $row = $readBack->fetch(PDO::FETCH_ASSOC) ?: [];

        WorkflowEngine::dispatch('ticket.created', [
            'ticket' => [
                'id'                  => (int)$ticketId,
                'subject'             => $subject,
                'priority_id'         => isset($row['priority_id']) ? (int)$row['priority_id'] : null,
                'status_id'           => isset($row['status_id'])   ? (int)$row['status_id']   : null,
                'department_id'       => $departmentId,
                'type_id'             => $ticketTypeId,
                'assigned_analyst_id' => $assignedAnalystId,
                'created_by'          => $analystId,
                'requester_email'     => $fromEmail,
            ],
        ]);
    } catch (Exception $wfEx) {
        error_log('Workflow dispatch error in create_ticket: ' . $wfEx->getMessage());
    }

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Generate a unique random ticket number (format: XXX-YYY-ZZZZZ)
 */
function generateTicketNumber($conn) {
    $maxAttempts = 10;
    $attempt = 0;

    while ($attempt < $maxAttempts) {
        // Generate 3 random letters (A-Z)
        $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));

        // Generate 3 random numbers (0-9)
        $numbers1 = rand(0, 9) . rand(0, 9) . rand(0, 9);

        // Generate 5 random numbers (0-9)
        $numbers2 = rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9);

        // Build ticket number
        $ticketNumber = $letters . '-' . $numbers1 . '-' . $numbers2;

        // Check if it already exists
        $checkSql = "SELECT COUNT(*) FROM tickets WHERE ticket_number = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->execute([$ticketNumber]);
        $exists = $checkStmt->fetchColumn();

        if (!$exists) {
            return $ticketNumber;
        }

        $attempt++;
    }

    throw new Exception('Failed to generate unique ticket number after ' . $maxAttempts . ' attempts');
}

?>
