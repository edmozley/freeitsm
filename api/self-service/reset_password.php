<?php
/**
 * API: use a reset link to set a SELF-SERVICE PORTAL password (GH #134).
 *
 * POST JSON: { "token": "<raw token>", "password": "...", "confirm_password": "..." }
 *
 * The second half of api/self-service/request_password_reset.php — read the rules
 * at the top of that file first.
 *
 * ── WHAT MAKES A TOKEN ACCEPTABLE ────────────────────────────────────────────
 *
 * All four conditions are checked in ONE statement, so there is no window between
 * deciding a token is good and using it, and no chance of the four drifting apart
 * later:
 *
 *   - the hash exists
 *   - it has not been used
 *   - it has not expired  (UTC_TIMESTAMP, because every stored instant is UTC)
 *   - the account it points at is still active and still local
 *
 * ⚠️ THE ACCOUNT IS RE-CHECKED HERE, not just when the link was sent. An hour is
 * long enough for somebody to be deactivated or moved to SSO, and a link minted
 * before that must not still work after it.
 *
 * ⚠️ ONE MESSAGE FOR EVERY BAD TOKEN. Wrong, already used, expired and
 * belonging-to-a-closed-account are indistinguishable in the reply. Saying
 * "expired" rather than "not found" would confirm that a token was once real.
 */
session_start();
header('Content-Type: application/json');

require_once '../../config.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true);
$rawToken = trim($input['token'] ?? '');
$password = $input['password'] ?? '';
$confirm  = $input['confirm_password'] ?? '';

$badToken = 'This link is no longer valid. It may have expired, or already been used. Please ask for a new one.';

// Shape-check before touching the database — a malformed token is not a lookup.
if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
    echo json_encode(['success' => false, 'error' => $badToken]);
    exit;
}

if ($password === '' || $confirm === '') {
    echo json_encode(['success' => false, 'error' => 'Please enter your new password twice.']);
    exit;
}
if ($password !== $confirm) {
    echo json_encode(['success' => false, 'error' => 'The two passwords do not match.']);
    exit;
}
// The same minimum the portal's own change-password screen enforces. Stated here
// rather than assumed: an endpoint that sets a password owns its own policy.
if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'error' => 'Your password must be at least 8 characters long.']);
    exit;
}

try {
    $conn = connectToDatabase();
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $conn->prepare(
        "SELECT t.id AS token_id, u.id AS user_id, u.email
           FROM user_password_reset_tokens t
           JOIN users u ON u.id = t.user_id
          WHERE t.token_hash = ?
            AND t.used = 0
            AND t.expires_at > UTC_TIMESTAMP()
            AND COALESCE(u.is_active, 1) = 1
            AND COALESCE(u.auth_provider_id, 0) = 0
          LIMIT 1"
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => $badToken]);
        exit;
    }

    $conn->beginTransaction();

    // Spend the token FIRST, and only where it is still unused. If two requests
    // arrive together this affects one row in one of them, so the second finds
    // nothing to spend and stops — the link works exactly once even under a race.
    $spend = $conn->prepare("UPDATE user_password_reset_tokens SET used = 1 WHERE id = ? AND used = 0");
    $spend->execute([(int)$row['token_id']]);
    if ($spend->rowCount() !== 1) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'error' => $badToken]);
        exit;
    }

    $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
         ->execute([password_hash($password, PASSWORD_BCRYPT), (int)$row['user_id']]);

    // Any other live link for this account dies with this one. Somebody who asked
    // twice, or whose mailbox is compromised, must not be left with a spare key.
    $conn->prepare("UPDATE user_password_reset_tokens SET used = 1 WHERE user_id = ? AND used = 0")
         ->execute([(int)$row['user_id']]);

    $conn->commit();

    // ⚠️ NOT signed in here, deliberately. Setting a password from an emailed link
    // and being handed a live session are different things: it would mean anyone
    // who reached the mailbox is straight into the account without ever proving
    // they know the password they just set. They go to the sign-in page and use it.
    echo json_encode([
        'success'  => true,
        'message'  => 'Your password is set. You can sign in now.',
        'email'    => $row['email'],
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) { $conn->rollBack(); }
    error_log('self-service password reset failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again shortly.']);
}
