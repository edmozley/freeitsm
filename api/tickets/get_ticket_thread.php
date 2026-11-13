<?php
/**
 * API Endpoint: Get all emails for a ticket (for building reply thread)
 * Returns emails ordered by received_datetime ASC
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_numbering.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/messaging/messaging.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$ticketId = $_GET['ticket_id'] ?? null;

if (!$ticketId) {
    echo json_encode(['success' => false, 'error' => 'Ticket ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Multi-tenancy: don't reveal a ticket in a company this analyst can't access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    // body_type matters to the renderer: chat channels store the sender's message
    // verbatim as 'text', so it must be ESCAPED rather than parsed as markup.
    $sql = "SELECT id, from_address, from_name, to_recipients, received_datetime, subject,
                   body_content, body_type, direction, channel
            FROM emails
            WHERE ticket_id = ?
            ORDER BY received_datetime ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticketId]);
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Determine the ticket's channel (any non-email message → a channel ticket like
    // WhatsApp) so the UI can render/compose appropriately rather than over email.
    $ticketChannel = 'email';
    foreach ($emails as &$email) {
        if (($email['channel'] ?? 'email') !== 'email') {
            $ticketChannel = $email['channel'];
        }
        if ($email['body_content']) {
            $email['body_content'] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $email['body_content']);
            $email['body_content'] = str_replace("\xEF\xBF\xBD", '', $email['body_content']);
            // Email threads carry quoted history to strip; channel messages don't.
            if (($email['channel'] ?? 'email') === 'email') {
                $email['body_content'] = stripQuotedThread($email['body_content']);
            }
        }
        if ($email['received_datetime']) {
            $email['received_datetime'] = date('Y-m-d\TH:i:s', strtotime($email['received_datetime']));
        }
    }
    unset($email);

    /* ---------------------------------------------------------------------
       NEAR-DUPLICATE MESSAGES  (idea #10 from discussion #104)

       A long ticket is rarely long because one message is long — it is long
       because the same message arrived five times. A resend, an auto-reply
       that quotes the whole original, a bounce carrying the message back, a
       distribution list delivering twice.

       So each message is fingerprinted on its VISIBLE TEXT — tags stripped,
       whitespace collapsed, lower-cased — and compared with the ones before
       it. An exact fingerprint match is "identical"; the same length to
       within 3% with the same opening 300 characters is "nearly identical",
       which catches the resend that differs only by a timestamp or a
       signature line.

       ⚠️ It only ever FLAGS. The message is returned in full and the reading
       pane collapses it by default with a line saying which earlier message
       it matches — the same rule as everything else here, that being wrong
       costs a tap and never a fact. Two genuinely different messages that
       happen to open identically are a mild annoyance; a hidden one is not.

       Done here rather than in the browser because it is O(n²) on the
       message count and the server has the whole thread in hand anyway.
       --------------------------------------------------------------------- */
    if (!empty($emails)) {
        $fingerprints = [];
        foreach ($emails as $i => &$email) {
            $text = strtolower(trim(preg_replace('/\s+/u', ' ',
                html_entity_decode(strip_tags((string)($email['body_content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
            $len  = mb_strlen($text);
            $email['same_as_id']   = null;
            $email['same_as_time'] = null;
            $email['same_kind']    = null;

            // Too short to say anything useful. "Thanks" is not a duplicate of
            // "Thanks" in any sense worth acting on.
            if ($len >= 120) {
                $hash = md5($text);
                $head = mb_substr($text, 0, 300);
                foreach ($fingerprints as $prev) {
                    $kind = null;
                    if ($prev['hash'] === $hash) {
                        $kind = 'identical';
                    } elseif ($prev['head'] === $head && $prev['len'] > 0
                              && abs($len - $prev['len']) / $prev['len'] <= 0.03) {
                        $kind = 'near';
                    }
                    if ($kind !== null) {
                        $email['same_as_id']   = $prev['id'];
                        $email['same_as_time'] = $prev['time'];
                        $email['same_kind']    = $kind;
                        break;                       // the FIRST match is the original
                    }
                }
                $fingerprints[] = ['hash' => $hash, 'head' => $head, 'len' => $len,
                                   'id' => $email['id'], 'time' => $email['received_datetime']];
            }
        }
        unset($email);
    }

    // For channel tickets, expose whether the provider's 24h service window is
    // still open (outside it, only template replies are allowed), plus the channel's
    // provider so the composer can offer the matching templates.
    $windowOpen = false;
    $channelProvider = '';
    if ($ticketChannel !== 'email') {
        // Only WhatsApp has a provider reply window. The list lives in ONE place,
        // channelHasServiceWindow(), shared with send_message.php and
        // send_attachment.php - so the composer and the API cannot disagree.
        if (!channelHasServiceWindow($ticketChannel)) {
            $windowOpen = true;
        } else {
            $ts = $conn->prepare("SELECT last_inbound_at FROM tickets WHERE id = ?");
            $ts->execute([$ticketId]);
            $windowOpen = channelWindowOpen($ts->fetchColumn() ?: null);
        }

        try {
            $pp = $conn->prepare(
                "SELECT mc.provider
                 FROM emails e JOIN messaging_channels mc ON mc.id = e.channel_id
                 WHERE e.ticket_id = ? AND e.channel <> 'email' AND e.channel_id IS NOT NULL
                 ORDER BY e.id DESC LIMIT 1"
            );
            $pp->execute([$ticketId]);
            $channelProvider = (string) ($pp->fetchColumn() ?: '');
        } catch (Exception $e) { /* leave blank */ }
    }

    echo json_encode([
        'success'          => true,
        'emails'           => $emails,
        'channel'          => $ticketChannel,
        'window_open'      => $windowOpen,
        'channel_provider' => $channelProvider,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Strip quoted/nested thread content from an email body
 * Relies on our own visible marker text, with generic blockquote fallback
 *
 * @param mixed $body  an email's HTML body, which may still carry the quoted earlier thread
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
    if ($stripped === null && preg_match(TicketNumbering::REF_LINE_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE)) {
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
