<?php
/**
 * API Endpoint: Get all emails for a ticket (for building reply thread)
 * Returns emails ordered by received_datetime ASC
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$ticketId = $_GET['ticket_id'] ?? null;

if (!$ticketId) {
    echo json_encode(['success' => false, 'error' => 'Ticket ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT id, from_address, from_name, to_recipients, received_datetime,
                   body_content, direction
            FROM emails
            WHERE ticket_id = ?
            ORDER BY received_datetime ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticketId]);
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($emails as &$email) {
        if ($email['body_content']) {
            $email['body_content'] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $email['body_content']);
            $email['body_content'] = str_replace("\xEF\xBF\xBD", '', $email['body_content']);
            // Strip quoted thread content so each email only shows its own content
            $email['body_content'] = stripQuotedThread($email['body_content']);
        }
        if ($email['received_datetime']) {
            $email['received_datetime'] = date('Y-m-d\TH:i:s', strtotime($email['received_datetime']));
        }
    }

    echo json_encode(['success' => true, 'emails' => $emails]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Strip quoted/nested thread content from an email body
 * Relies on our own visible marker text, with generic blockquote fallback
 */
function stripQuotedThread($body) {
    $stripped = null;

    // 1. Our visible marker text: "Please reply above this line"
    if (preg_match('/\x{2014}\s*Please reply above this line\s*\x{2014}/u', $body, $matches, PREG_OFFSET_CAPTURE)) {
        $s = trim(substr($body, 0, $matches[0][1]));
        if (!empty($s)) $stripped = $s;
    }

    // 2. Our data-reply-marker div (if preserved)
    if ($stripped === null && preg_match('/<div[^>]*data-reply-marker="true"[^>]*>/i', $body, $matches, PREG_OFFSET_CAPTURE)) {
        $s = trim(substr($body, 0, $matches[0][1]));
        if (!empty($s)) $stripped = $s;
    }

    // 3. Legacy SDREF marker text from older emails
    if ($stripped === null && preg_match('/\[\*{3}\s*SDREF:[A-Z]{3}-\d{3}-\d{5}\s*REPLY ABOVE THIS LINE\s*\*{3}\]/i', $body, $matches, PREG_OFFSET_CAPTURE)) {
        $s = trim(substr($body, 0, $matches[0][1]));
        if (!empty($s)) $stripped = $s;
    }

    // 4. Generic fallback: blockquote (only if there's content before it)
    if ($stripped === null && preg_match('/<blockquote[^>]*>/i', $body, $matches, PREG_OFFSET_CAPTURE)) {
        $s = trim(substr($body, 0, $matches[0][1]));
        if (!empty($s)) $stripped = $s;
    }

    if ($stripped === null) $stripped = $body;

    // Remove trailing "On [date], [name] wrote:" attribution lines added by email clients
    $stripped = preg_replace('/(<br\s*\/?>|\s|<\/?div[^>]*>)*\bOn\s+.{10,120}\s+wrote:\s*(<\/?div[^>]*>|<br\s*\/?>|\s)*$/is', '', $stripped);

    return trim($stripped);
}
