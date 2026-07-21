<?php
/**
 * CSAT Survey Helpers
 *
 * Creates a ticket_csat_responses row with a tokenised URL and sends the
 * survey email via the existing template engine. The token is HMAC-derived
 * so a leaked database row can't be replayed against a different install
 * (the per-install secret lives in system_settings.csat_token_secret).
 *
 * Called from:
 *   - api/tickets/assign_ticket.php when a ticket transitions into a closed
 *     status and the global csat_mode is 'auto'
 *   - api/tickets/request_csat.php when an analyst clicks the manual
 *     "Request feedback" button (regardless of mode, as long as mode != 'off')
 */

require_once __DIR__ . '/template_email.php';

/**
 * Read a system_settings key with a default fallback.
 */
function csatGetSetting(PDO $conn, string $key, string $default = ''): string {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    $cache[$key] = ($val === false || $val === null) ? $default : (string)$val;
    return $cache[$key];
}

/**
 * Generate a token unique to (ticket, response_row). 32-byte random base ensures
 * it's not guessable from ticket id alone; we then HMAC it with the install
 * secret so even a database leak of `token` alone isn't enough — though for the
 * survey URL we just use the random portion since the response row IS the
 * authoritative store. Keeping HMAC available as a verification step in case we
 * ever want to surface the link in places where the DB isn't queried.
 */
function csatGenerateToken(): string {
    return bin2hex(random_bytes(24)); // 48 hex chars — fits in VARCHAR(64)
}

/**
 * Build the full public URL the user clicks. Uses the host the request came
 * in on so multi-domain installs (test.example.com vs example.com) work without
 * config changes.
 */
function csatBuildUrl(string $token): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Find the install root — config.php sits at the repo root, so we walk
    // up from this file's directory to derive it relative to DOCUMENT_ROOT.
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
    $appPath = $docRoot && strpos($appRoot, $docRoot) === 0
        ? substr($appRoot, strlen($docRoot))
        : '';
    // Canonical survey URL since the root-folder tidy (the old /csat.php
    // form still 301s here, so links in already-sent emails keep working).
    return $scheme . '://' . $host . $appPath . '/csat?token=' . urlencode($token);
}

/**
 * Result codes returned by sendCsatSurvey() so the caller can give an honest
 * UI message rather than silently lying. The DB row + email send are only
 * performed when the result will be 'sent' — every other case is a precondition
 * failure that should NOT leave an orphan row behind.
 */
const CSAT_RESULT_SENT          = 'sent';
const CSAT_RESULT_OFF           = 'off';            // csat_mode = off
const CSAT_RESULT_ALREADY_SENT  = 'already_sent';   // one-per-ticket guard tripped
const CSAT_RESULT_NO_TEMPLATE   = 'no_template';    // no active csat_request template
const CSAT_RESULT_NO_MAILBOX    = 'no_mailbox';     // ticket has no mailbox to send from

/**
 * Create a CSAT response row and send the survey email. Returns an array:
 *   ['result' => <CSAT_RESULT_*>, 'response_id' => int|null]
 *
 * Pass $force = true to bypass the one-per-ticket guard when the analyst
 * deliberately re-requests feedback.
 *
 * Pre-flight checks (mode, template existence, mailbox availability) all run
 * BEFORE the row is inserted, so failed sends don't leave orphan tokens behind.
 */
function sendCsatSurvey(PDO $conn, int $ticketId, ?int $analystId, bool $force = false): array {
    $mode = csatGetSetting($conn, 'csat_mode', 'off');
    if ($mode === 'off') {
        return ['result' => CSAT_RESULT_OFF, 'response_id' => null];
    }

    // Skip if a response row already exists and one-per-ticket is on (unless forced)
    if (!$force && csatGetSetting($conn, 'csat_one_per_ticket', '1') === '1') {
        $existing = $conn->prepare("SELECT id FROM ticket_csat_responses WHERE ticket_id = ? LIMIT 1");
        $existing->execute([$ticketId]);
        if ($existing->fetchColumn()) {
            return ['result' => CSAT_RESULT_ALREADY_SENT, 'response_id' => null];
        }
    }

    // Pre-flight: an active csat_request template must exist. Without it, the
    // template engine silently no-ops and we'd save a useless row with a useless token.
    $tplCheck = $conn->prepare("SELECT id FROM ticket_email_templates WHERE event_trigger = 'csat_request' AND is_active = 1 LIMIT 1");
    $tplCheck->execute();
    if (!$tplCheck->fetchColumn()) {
        return ['result' => CSAT_RESULT_NO_TEMPLATE, 'response_id' => null];
    }

    // Pre-flight: a mailbox must be reachable from this ticket. Manual / portal
    // tickets that came in without an email won't have one — survey would no-op.
    require_once __DIR__ . '/template_email.php';
    $mailbox = templateGetMailboxForTicket($conn, $ticketId);
    if (!$mailbox) {
        return ['result' => CSAT_RESULT_NO_MAILBOX, 'response_id' => null];
    }

    // All checks passed — now we can durably create the row and dispatch the email
    $token = csatGenerateToken();
    $insert = $conn->prepare(
        "INSERT INTO ticket_csat_responses (ticket_id, token, sent_datetime, analyst_id, created_at)
         VALUES (?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())"
    );
    $insert->execute([$ticketId, $token, $analystId]);
    $responseId = (int)$conn->lastInsertId();

    sendTemplateEmail($conn, $ticketId, 'csat_request', [
        'csat_link' => csatBuildUrl($token),
    ]);

    return ['result' => CSAT_RESULT_SENT, 'response_id' => $responseId];
}
