<?php
/**
 * API: Create Ticket from Self-Service Portal
 * POST - Creates a new ticket for the logged-in user
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/html_sanitise.php';
require_once '../../includes/ticket_recordings.php';

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
$recordingIds = array_values(array_filter(array_map('intval', $input['recording_ids'] ?? [])));

if (empty($subject)) {
    echo json_encode(['success' => false, 'error' => 'Subject is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Validate the submitted priority against the CONFIGURED active priorities
    // (not a hardcoded Low/Normal/High list) so custom priorities like
    // Urgent/Critical are accepted (#40). Fall back to the default priority,
    // then Normal, then the first available, if the submitted one isn't valid.
    $activePrios = $conn->query("SELECT name, is_default FROM ticket_priorities WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $prioNames = array_column($activePrios, 'name');
    if (!in_array($priority, $prioNames, true)) {
        $default = '';
        foreach ($activePrios as $p) { if ((int)$p['is_default'] === 1) { $default = $p['name']; break; } }
        $priority = $default !== '' ? $default : (in_array('Normal', $prioNames, true) ? 'Normal' : ($prioNames[0] ?? 'Normal'));
    }

    $conn->beginTransaction();

    // Get user details
    $userStmt = $conn->prepare("SELECT email, username, display_name, tenant_id FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User not found');
    }

    // NULL, not '': a directory requester with no mailbox genuinely has no
    // address, and '' would look like an address everywhere downstream while
    // being useless to reply to. The name carries the identity for these people,
    // so it must never fall through to a blank.
    $fromEmail = ($user['email'] !== null && $user['email'] !== '') ? $user['email'] : null;
    $fromName  = $user['display_name'] ?: ($user['username'] ?: ($fromEmail ?: ('#' . $userId)));

    // Which company does this ticket belong to? The portal has no mailbox and no
    // sender to route on, so the answer is simply whose account raised it. A user
    // with no company on file falls to NULL = triage, exactly as an email from an
    // unrecognised domain does; a single-company install always gets Default so
    // portal tickets aren't shaped differently from every other intake path.
    $ticketTenantId = $user['tenant_id'] !== null
        ? (int) $user['tenant_id']
        : (isMultiTenant($conn) ? null : getDefaultTenantId($conn));

    // Generate unique ticket number
    $ticketNumber = generateTicketNumber($conn);

    // Create ticket. Resolve status/priority names to ids via subselects.
    $ticketSql = "INSERT INTO tickets (
        ticket_number, subject, status_id, priority_id,
        user_id, tenant_id, created_datetime, updated_datetime
    ) VALUES (
        ?, ?,
        (SELECT id FROM ticket_statuses   WHERE name = 'Open' LIMIT 1),
        (SELECT id FROM ticket_priorities WHERE name = ? LIMIT 1),
        ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()
    )";

    $ticketStmt = $conn->prepare($ticketSql);
    $ticketStmt->execute([
        $ticketNumber,
        $subject,
        $priority,
        $userId,
        $ticketTenantId,
    ]);

    $ticketId = $conn->lastInsertId();

    // Create initial email entry
    $hasAttachments = !empty($inputAttachments) ? 1 : 0;

    // The new-ticket form is a rich-text editor, so the description arrives as
    // HTML written by a CUSTOMER and is then read in the analyst's inbox. Clean
    // it to an allow-list on the way in, so the payload never reaches the
    // database and everything that later reads this row inherits the protection
    // (the reading pane also cleans at render — this is the first line, not the
    // only one).
    $bodyHtml = sanitiseUserHtml($description);
    if ($bodyHtml === '' && trim($description) !== '') {
        // Not HTML after all (an older client, or plain text): escape it rather
        // than lose what they wrote.
        $bodyHtml = nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8'));
    }
    // Preview from the SANITISED body, never the raw input: strip_tags() removes
    // tags but KEEPS their contents, so a <script> block in the raw description
    // would spill its source into the preview as readable text.
    $bodyPreview = substr(trim(preg_replace('/\s+/', ' ', strip_tags($bodyHtml))), 0, 200);

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

    // Claim any pending recordings this user uploaded for this ticket. Shared
    // with reply_ticket.php — see includes/ticket_recordings.php for the
    // ownership guard. Deliberately AFTER the commit: the file moves are not
    // transactional, and a recording that fails to move must not cost someone
    // the ticket they just wrote.
    //
    // $emailId is left NULL here: these were recorded with the OPENING message,
    // which is exactly what NULL means.
    claimPendingRecordings($conn, $recordingIds, (int)$ticketId, $userId, null);

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
