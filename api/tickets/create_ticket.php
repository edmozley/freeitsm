<?php
/**
 * API Endpoint: Create a new ticket manually
 * Creates a ticket and an initial "email" entry for display in the inbox
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

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

    // Create the ticket and get the ID
    $ticketSql = "INSERT INTO tickets (
        ticket_number, subject, status, priority, department_id, ticket_type_id,
        assigned_analyst_id, user_id, requester_name, requester_email, created_datetime, updated_datetime
    ) VALUES (?, ?, 'Open', ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())";

    $ticketStmt = $conn->prepare($ticketSql);
    $ticketStmt->execute([
        $ticketNumber,
        $subject,
        $priority,
        $departmentId,
        $ticketTypeId,
        $assignedAnalystId,
        $userId,
        $fromName,
        $fromEmail
    ]);

    $ticketId = $conn->lastInsertId();

    // Create an initial "email" entry (this makes it appear in the inbox like other tickets)
    // Direction is 'Manual' to indicate it was manually created
    $bodyHtml = nl2br(htmlspecialchars($body));
    $bodyPreview = substr(strip_tags($body), 0, 200);

    $emailSql = "INSERT INTO emails (
        subject, from_address, from_name, to_recipients, received_datetime,
        body_preview, body_content, body_type, has_attachments, importance,
        is_read, ticket_id, is_initial, direction
    ) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, 'html', 0, 'normal', 1, ?, 1, 'Manual')";

    $emailStmt = $conn->prepare($emailSql);
    $emailStmt->execute([
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
