<?php
/**
 * Test page: Raw email thread display for ticket #45
 * Completely flat - no boxes, no indentation, no coloured borders
 */
session_start();
require_once 'config.php';
require_once 'includes/functions.php';

$ticketId = 45;
$conn = connectToDatabase();

$ticketStmt = $conn->prepare("SELECT ticket_number, subject FROM tickets WHERE id = ?");
$ticketStmt->execute([$ticketId]);
$ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);

$sql = "SELECT id, from_address, from_name, to_recipients, received_datetime,
               body_content, direction
        FROM emails WHERE ticket_id = ? ORDER BY received_datetime DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([$ticketId]);
$emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Thread Test - <?= htmlspecialchars($ticket['ticket_number'] ?? $ticketId) ?></title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; max-width: 800px; margin: 40px auto; padding: 0 20px; }
        h1 { font-size: 18px; color: #333; }
        .info { color: #666; font-size: 13px; margin-bottom: 20px; }
        .separator { border-top: 1px solid #ddd; margin: 20px 0; }
        .meta { font-size: 13px; color: #888; margin-bottom: 6px; }
        .meta strong { color: #333; }
        .badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; margin-right: 6px; }
        .badge-in { background: #e3f2fd; color: #0078d4; }
        .badge-out { background: #e8f5e9; color: #28a745; }
        .body-content { line-height: 1.6; color: #333; margin: 8px 0; }
        /* Kill any indentation or boxing from inline HTML styles */
        .body-content blockquote,
        .body-content div[style],
        .body-content p[style] { margin: 0 !important; padding: 0 !important; border: none !important; color: inherit !important; }
        .toggle { cursor: pointer; color: #0078d4; font-size: 12px; }
        .raw { padding: 10px; background: #f8f8f8; border: 1px solid #eee; font-size: 11px; font-family: monospace; white-space: pre-wrap; word-break: break-all; max-height: 200px; overflow-y: auto; margin-top: 6px; }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($ticket['subject'] ?? 'Unknown') ?></h1>
    <p class="info"><?= count($emails) ?> emails, newest first | Ticket <?= htmlspecialchars($ticket['ticket_number'] ?? $ticketId) ?></p>

    <?php foreach ($emails as $i => $email):
        $out = $email['direction'] === 'Outbound';
        $date = date('D, d M Y g:i A', strtotime($email['received_datetime']));
        $stripped = stripForDisplay($email['body_content'] ?? '');
        $raw = htmlspecialchars($email['body_content'] ?? '');
    ?>
        <?php if ($i > 0): ?><div class="separator"></div><?php endif; ?>
        <div class="meta">
            <span class="badge <?= $out ? 'badge-out' : 'badge-in' ?>"><?= $out ? 'Sent' : 'Received' ?></span>
            <strong><?= htmlspecialchars($email['from_name'] ?: $email['from_address']) ?></strong>
            &lt;<?= htmlspecialchars($email['from_address']) ?>&gt; &mdash; <?= $date ?>
        </div>
        <div class="body-content"><?= $stripped ?></div>
        <span class="toggle" onclick="var r=this.nextElementSibling;r.style.display=r.style.display==='none'?'block':'none'">raw html</span>
        <div class="raw" style="display:none"><?= $raw ?></div>
    <?php endforeach; ?>
</body>
</html>
<?php
function stripForDisplay($body) {
    $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $body);
    $stripped = null;

    // Our visible marker text
    if (preg_match('/\x{2014}\s*Please reply above this line\s*\x{2014}/u', $body, $m, PREG_OFFSET_CAPTURE)) {
        $s = trim(substr($body, 0, $m[0][1]));
        if (!empty($s)) $stripped = $s;
    }
    // data-reply-marker div
    if ($stripped === null && preg_match('/<div[^>]*data-reply-marker="true"[^>]*>/i', $body, $m, PREG_OFFSET_CAPTURE)) {
        $s = trim(substr($body, 0, $m[0][1]));
        if (!empty($s)) $stripped = $s;
    }
    // Legacy SDREF marker
    if ($stripped === null && preg_match('/\[\*{3}\s*SDREF:[A-Z]{3}-\d{3}-\d{5}\s*REPLY ABOVE THIS LINE\s*\*{3}\]/i', $body, $m, PREG_OFFSET_CAPTURE)) {
        $s = trim(substr($body, 0, $m[0][1]));
        if (!empty($s)) $stripped = $s;
    }
    // Blockquote fallback
    if ($stripped === null && preg_match('/<blockquote[^>]*>/i', $body, $m, PREG_OFFSET_CAPTURE)) {
        $s = trim(substr($body, 0, $m[0][1]));
        if (!empty($s)) $stripped = $s;
    }

    if ($stripped === null) $stripped = $body;

    // Remove trailing "On [date], [name] wrote:" attribution lines added by email clients
    $stripped = preg_replace('/(<br\s*\/?>|\s|<\/?div[^>]*>)*\bOn\s+.{10,120}\s+wrote:\s*(<\/?div[^>]*>|<br\s*\/?>|\s)*$/is', '', $stripped);

    return trim($stripped);
}
?>
