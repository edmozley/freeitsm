<?php
/**
 * API: Self-Service Portal Registration
 * POST - Creates a new user account or claims an existing passwordless account
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$email = strtolower(trim($input['email'] ?? ''));
$displayName = trim($input['display_name'] ?? '');
$password = $input['password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? '';

// Validate fields
if (empty($email) || empty($displayName) || empty($password)) {
    echo json_encode(['success' => false, 'error' => 'All fields are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address']);
    exit;
}

if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters']);
    exit;
}

if ($password !== $confirmPassword) {
    echo json_encode(['success' => false, 'error' => 'Passwords do not match']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Self-registration is off unless an admin enabled it (System → Security).
    require_once '../../includes/self_service.php';
    if (!selfServiceRegistrationEnabled($conn)) {
        echo json_encode(['success' => false, 'error' => 'Self-service registration is not enabled. Please contact your service desk.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    // Check if email belongs to an analyst account
    $analystStmt = $conn->prepare("SELECT id FROM analysts WHERE email = ?");
    $analystStmt->execute([$email]);
    if ($analystStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'An account with this email already exists. Please use the main login.']);
        exit;
    }

    // If a real (password-set) account already exists, don't start a registration.
    $userStmt = $conn->prepare("SELECT id, password_hash, auth_provider_id FROM users WHERE email = ?");
    $userStmt->execute([$email]);
    $existingUser = $userStmt->fetch(PDO::FETCH_ASSOC);
    if ($existingUser && !empty($existingUser['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'An account with this email already exists. Please log in.']);
        exit;
    }

    // An account that signs in through a directory or an identity provider has
    // NO local password by design, so it looks "unclaimed" to the check above.
    // Letting someone register against it would hand that account a second
    // credential the directory cannot revoke — and would strand the real owner
    // with a password that never works, because the login routes a pinned
    // account to its provider and never checks a local hash.
    if ($existingUser && (int)($existingUser['auth_provider_id'] ?? 0) > 0) {
        echo json_encode([
            'success' => false,
            'error'   => 'This account signs in with your work account. Please use those details on the sign-in page.',
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // Everything below covers BOTH a brand-new email and an existing
    // *passwordless* account (one auto-created from an inbound email, web
    // chat or a ticket). We do NOT set a password or sign anyone in here.
    // Instead we email a confirmation link to the address itself and only
    // apply the password when that link is opened — so registering an email
    // you don't control can't take over someone else's account. The password
    // is parked (hashed) in user_verification_tokens until then.
    // ------------------------------------------------------------------
    require_once '../../includes/self_service_email.php';

    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    // One live token per email — a fresh registration supersedes any pending one.
    $conn->prepare("DELETE FROM user_verification_tokens WHERE email = ?")->execute([$email]);
    $conn->prepare(
        "INSERT INTO user_verification_tokens (email, password_hash, display_name, token_hash, expires_at, created_at)
         VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR), UTC_TIMESTAMP())"
    )->execute([$email, $hash, $displayName, $tokenHash]);

    $sent = ssSendSystemEmail(
        $conn, $email,
        'Confirm your account',
        ssVerifyEmailBody($displayName, ssBuildVerifyUrl($rawToken))
    );

    if (!$sent) {
        // Fail closed: no email means no verification means no account. Bin the
        // token so nothing is left half-created, and tell them plainly.
        $conn->prepare("DELETE FROM user_verification_tokens WHERE token_hash = ?")->execute([$tokenHash]);
        echo json_encode(['success' => false, 'error' => 'We couldn\'t send a confirmation email right now. Please contact your service desk.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'pending' => true,
        'message' => 'Almost there — check your inbox. We\'ve emailed a link to confirm your account.'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Registration failed. Please try again.']);
}
