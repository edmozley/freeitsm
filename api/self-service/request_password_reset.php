<?php
/**
 * API: ask for a password-reset link for a SELF-SERVICE PORTAL account (GH #134).
 *
 * POST JSON: { "email": "someone@example.com" }
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * Reported by mbsouth: every customer and partner had been created in the ticket
 * system with a name and an email address and no password, and there was then no
 * way on earth for any of them to obtain one. The portal had a registration flow
 * and nothing else, so an account created FOR somebody was an account nobody
 * could ever sign in to. There was no administrator route either.
 *
 * ── THE RULES THIS FILE ENFORCES ─────────────────────────────────────────────
 *
 * 1. THE ANSWER IS ALWAYS THE SAME. Whether the address is unknown, known,
 *    disabled or signed in with SSO, the caller is told the identical thing.
 *    Anything else turns this endpoint into a way of asking "does this person
 *    have an account here?", which for a service desk's customer list is a
 *    question worth protecting.
 *
 * 2. THE TOKEN IS NEVER STORED. Only its SHA-256 is kept, so the table is not a
 *    list of working links if it is ever read.
 *
 * 3. ASKING AGAIN INVALIDATES THE LAST ONE. Two live links to one account means
 *    the older email keeps working after somebody has already used the newer.
 *
 * 4. AN SSO ACCOUNT IS NOT OFFERED A PASSWORD. Setting one would create a second
 *    way in that the organisation's identity provider does not know about, which
 *    is the opposite of why they turned SSO on.
 */
session_start(['read_and_close' => true]);
header('Content-Type: application/json');

require_once '../../config.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$email = strtolower(trim($input['email'] ?? ''));

// The one message this endpoint gives, whatever it finds. See rule 1 above.
$generic = 'If there is a portal account for that email address, a link to set a password is on its way. '
         . 'It is valid for one hour.';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT id, email, display_name, preferred_name, is_active, auth_provider_id
           FROM users WHERE email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Every one of these is a silent success. Rule 1.
    $eligible = $user
        && (int)($user['is_active'] ?? 1) === 1
        && (int)($user['auth_provider_id'] ?? 0) === 0;

    if (!$eligible) {
        echo json_encode(['success' => true, 'message' => $generic]);
        exit;
    }

    // Rule 3.
    $conn->prepare("UPDATE user_password_reset_tokens SET used = 1 WHERE user_id = ? AND used = 0")
         ->execute([(int)$user['id']]);

    // 32 bytes of randomness. The raw token goes in the email and nowhere else;
    // only its hash is stored (rule 2).
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    $conn->prepare(
        "INSERT INTO user_password_reset_tokens (user_id, token_hash, expires_at, used, created_at)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), 0, UTC_TIMESTAMP())"
    )->execute([(int)$user['id'], $tokenHash]);

    require_once '../../includes/self_service_email.php';

    $name = trim((string)($user['preferred_name'] ?? '')) ?: trim((string)($user['display_name'] ?? ''));
    $sent = ssSendSystemEmail(
        $conn,
        $user['email'],
        'Set your password',
        ssResetEmailBody($name, ssBuildResetUrl($rawToken))
    );

    // ⚠️ A send failure is the ONE case that breaks rule 1, and on purpose. It is
    // a fact about this installation's mail configuration, not about whether the
    // address has an account — every caller gets it, so it reveals nothing — and
    // staying silent would leave somebody waiting for an email that was never
    // going to arrive. ssSendSystemEmail() has already written the reason to the
    // email log for whoever administers the system.
    if (!$sent) {
        echo json_encode([
            'success' => false,
            'error'   => 'This system cannot send email at the moment, so the link could not be sent. '
                       . 'Please contact your service desk.'
        ]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => $generic]);

} catch (Throwable $e) {
    error_log('self-service password reset request failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again shortly.']);
}
