<?php
/**
 * API Endpoint: get (or reset) this analyst's scheduled-work subscription URL.
 *
 *   GET                -> the URL, minting a token if there is none yet
 *   POST action=reset  -> rotate the token, revoking every copy of the old URL
 *   POST action=revoke -> delete the token entirely; the feed 403s from then on
 *
 * ⚠️ A SEPARATE TOKEN FROM THE SHARED CALENDAR'S (calendar_feed_token). The two
 * feeds carry different things — that one is maintenance windows, this one is
 * ticket subjects — and they are gated on different module access. Sharing a
 * token would mean rotating your work feed forced you to re-add the team
 * calendar to your phone, and that an analyst with Tickets but not Calendar
 * could mint a token that also unlocks the Calendar feed.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/calendar_sync/calendar_sync.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// This mints the secret for an UNAUTHENTICATED feed of ticket data, so it must
// not be reachable by someone who cannot use Tickets. Same reasoning that debug
// tool D005 found on the Calendar feed.
requireModuleAccessJson('tickets');

$analystId = (int)$_SESSION['analyst_id'];
$action    = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['action'] ?? '') : '';

try {
    $conn = connectToDatabase();

    // The install-wide policy is checked HERE as well as in the feed itself. A
    // screen that hands out a link which then 403s is worse than one that
    // explains the link is not available.
    if (!scheduleFeedAllowed($conn)) {
        echo json_encode([
            'success'   => false,
            'available' => false,
            'error'     => 'Calendar subscription links are switched off on this system.',
        ]);
        exit;
    }

    if ($action === 'revoke') {
        $conn->prepare("DELETE FROM user_preferences WHERE analyst_id = ? AND preference_key = 'tickets_schedule_feed_token'")
             ->execute([$analystId]);
        echo json_encode(['success' => true, 'available' => true, 'revoked' => true]);
        exit;
    }

    $token = null;
    if ($action !== 'reset') {
        $stmt = $conn->prepare(
            "SELECT preference_value FROM user_preferences
              WHERE analyst_id = ? AND preference_key = 'tickets_schedule_feed_token' LIMIT 1"
        );
        $stmt->execute([$analystId]);
        $token = $stmt->fetchColumn() ?: null;
    }

    if (!$token) {
        // 24 random bytes = 192 bits. Not guessable; the security of the whole
        // scheme rests on this line.
        $token = bin2hex(random_bytes(24));
        $conn->prepare(
            "INSERT INTO user_preferences (analyst_id, preference_key, preference_value, updated_datetime)
             VALUES (?, 'tickets_schedule_feed_token', ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_datetime = UTC_TIMESTAMP()"
        )->execute([$analystId, $token]);
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/tickets/x.php')), '/');
    $path   = $dir . '/schedule_feed.php?token=' . $token;

    echo json_encode([
        'success'   => true,
        'available' => true,
        'url'       => $scheme . '://' . $host . $path,
        'webcal'    => 'webcal://' . $host . $path,   // iOS taps this to subscribe
        // 🔑 The screen MUST warn when this is true. Over plain HTTP the token —
        // which is the only thing protecting your ticket subjects — crosses the
        // network in clear on every refresh, several times a day, for ever.
        'insecure'  => !$https,
        'detail'    => scheduleFeedDetail($conn, $analystId),
        // 'ref' here means the ORGANISATION capped it, so the analyst's own
        // detail control must be shown as locked rather than simply ignored.
        'detail_locked' => scheduleFeedMode($conn) === FEED_MODE_REF,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
