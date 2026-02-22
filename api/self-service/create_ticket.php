<?php
/**
 * API: Create Ticket from Self-Service Portal
 * POST - Creates a new ticket for the logged-in user
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId = (int)$_SESSION['ss_user_id'];
$input = json_decode(file_get_contents('php://input'), true);

$subject = trim($input['subject'] ?? '');
$description = trim($input['description'] ?? '');
$priority = $input['priority'] ?? 'Normal';
$mailboxId = !empty($input['mailbox_id']) ? (int)$input['mailbox_id'] : null;
$inputAttachments = $input['attachments'] ?? [];

if (empty($subject)) {
    echo json_encode(['success' => false, 'error' => 'Subject is required']);
    exit;
}

$allowedPriorities = ['Low', 'Normal', 'High'];
if (!in_array($priority, $allowedPriorities)) {
    $priority = 'Normal';
}

try {
    $conn = connectToDatabase();
    $conn->beginTransaction();

    // Get user details
    $userStmt = $conn->prepare("SELECT email, display_name FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User not found');
    }

    $fromEmail = $user['email'];
    $fromName = $user['display_name'] ?: $user['email'];

    // Generate unique ticket number
    $ticketNumber = generateTicketNumber($conn);

    // Create ticket
    $ticketSql = "INSERT INTO tickets (
        ticket_number, subject, status, priority,
        user_id, requester_name, requester_email,
        created_datetime, updated_datetime
    ) VALUES (?, ?, 'Open', ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())";

    $ticketStmt = $conn->prepare($ticketSql);
    $ticketStmt->execute([
        $ticketNumber,
        $subject,
        $priority,
        $userId,
        $fromName,
        $fromEmail
    ]);

    $ticketId = $conn->lastInsertId();

    // Create initial email entry
    $hasAttachments = !empty($inputAttachments) ? 1 : 0;
    $bodyHtml = nl2br(htmlspecialchars($description));
    $bodyPreview = substr(strip_tags($description), 0, 200);

    $emailSql = "INSERT INTO emails (
        subject, from_address, from_name, to_recipients, received_datetime,
        body_preview, body_content, body_type, has_attachments, importance,
        is_read, ticket_id, is_initial, direction, mailbox_id
    ) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, 'html', ?, 'normal', 0, ?, 1, 'Portal', ?)";

    $emailStmt = $conn->prepare($emailSql);
    $emailStmt->execute([
        $subject,
        $fromEmail,
        $fromName,
        $fromEmail,
        $bodyPreview,
        $bodyHtml,
        $hasAttachments,
        $ticketId,
        $mailboxId
    ]);

    $emailId = $conn->lastInsertId();

    // Save attachments to disk and create database records
    if (!empty($inputAttachments)) {
        $attachDir = realpath(__DIR__ . '/../../tickets/attachments');
        $subdir = floor($emailId / 1000);
        $emailDir = $attachDir . '/' . $subdir . '/' . $emailId;

        if (!is_dir($emailDir)) {
            mkdir($emailDir, 0755, true);
        }

        $attachStmt = $conn->prepare(
            "INSERT INTO email_attachments (email_id, filename, content_type, file_path, file_size, is_inline, created_datetime)
             VALUES (?, ?, ?, ?, ?, 0, UTC_TIMESTAMP())"
        );

        foreach ($inputAttachments as $att) {
            $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $att['name'] ?? 'file');
            $fileData = base64_decode($att['content'] ?? '');
            $fileSize = strlen($fileData);

            // Handle duplicate filenames
            $savePath = $emailDir . '/' . $filename;
            $counter = 1;
            $info = pathinfo($filename);
            while (file_exists($savePath)) {
                $filename = $info['filename'] . '_' . $counter . '.' . ($info['extension'] ?? '');
                $savePath = $emailDir . '/' . $filename;
                $counter++;
            }

            file_put_contents($savePath, $fileData);

            $relPath = $subdir . '/' . $emailId . '/' . $filename;
            $contentType = $att['type'] ?? 'application/octet-stream';

            $attachStmt->execute([$emailId, $filename, $contentType, $relPath, $fileSize]);
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'ticket_id' => (int)$ticketId,
        'ticket_number' => $ticketNumber
    ]);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Generate a unique random ticket number (format: XXX-YYY-ZZZZZ)
 */
function generateTicketNumber($conn) {
    $maxAttempts = 10;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
        $numbers1 = rand(0, 9) . rand(0, 9) . rand(0, 9);
        $numbers2 = rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9);
        $ticketNumber = $letters . '-' . $numbers1 . '-' . $numbers2;

        $stmt = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number = ?");
        $stmt->execute([$ticketNumber]);
        if (!$stmt->fetchColumn()) {
            return $ticketNumber;
        }
    }
    throw new Exception('Failed to generate unique ticket number');
}
