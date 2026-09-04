<?php
/**
 * API: Verify OTP during login
 * POST - Verifies TOTP code for MFA login challenge, completes login if valid
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/totp.php';
require_once '../../includes/encryption.php';
require_once '../../includes/mfa_throttle.php';
require_once '../../includes/landing.php';   // re-issue the landing cookie on login (#63)

header('Content-Type: application/json');

// Check for pending MFA state
if (!isset($_SESSION['mfa_pending_analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'No MFA challenge pending']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Verification code is required']);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = $_SESSION['mfa_pending_analyst_id'];

    // Get the encrypted TOTP secret and trust/password fields
    // Try extended query first; fall back to basic if security columns don't exist yet
    try {
        $sql = "SELECT totp_secret, trust_device_enabled, password_changed_datetime FROM analysts WHERE id = ? AND totp_enabled = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$analystId]);
    } catch (Exception $colEx) {
        $sql = "SELECT totp_secret FROM analysts WHERE id = ? AND totp_enabled = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$analystId]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['totp_secret'])) {
        echo json_encode(['success' => false, 'error' => 'MFA configuration error']);
        exit;
    }

    // ⚠️ Is this account's code step locked? Checked BEFORE the code is examined,
    // so a locked account leaks nothing about whether a guess was close.
    //
    // This is the half the session counter could never do: it survives the attacker
    // discarding the session, because it lives on the account row. See
    // includes/mfa_throttle.php for why re-presenting the password did not save us.
    $lockedMins = mfaThrottleMinutesRemaining($conn, 'analysts', (int)$analystId);
    if ($lockedMins > 0) {
        error_log('MFA code step is locked for analyst ' . (int)$analystId . ' for a further '
                  . $lockedMins . ' minute(s), from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        echo json_encode([
            'success' => false,
            'error'   => 'Too many incorrect codes. Please try again in ' . $lockedMins . ' minute(s).',
        ]);
        exit;
    }

    // Decrypt the secret
    $secret = decryptValue($row['totp_secret']);

    // ⚠️ Verify the code — and COUNT the failures, in TWO places.
    //
    // This endpoint had no attempt counter, no delay and no logging, and it sits
    // outside the IP-ban path that protects the password step. A six-digit TOTP is
    // one million possibilities, which is nothing to a script: given a valid
    // password, the second factor was brute-forceable.
    //
    // The session counter below abandons the challenge cheaply. It is NOT the
    // defence, and the reasoning that once said it was has been corrected: the
    // session belongs to the attacker, and re-presenting a valid password resets
    // failed_login_count, so looping cost one extra request per five guesses. The
    // account-row counter is the real limit; the session one stays because it is
    // what still applies on a database that has not been migrated yet.
    if (!verifyTotpCode($secret, $code)) {
        $_SESSION['mfa_attempts'] = ($_SESSION['mfa_attempts'] ?? 0) + 1;

        // Durable count first, so it is recorded even if the response below changes.
        $throttle = mfaThrottleRecordFailure($conn, 'analysts', (int)$analystId);
        if ($throttle['locked']) {
            $abandonedUsername = (string)($_SESSION['mfa_pending_username'] ?? 'unknown');
            unset(
                $_SESSION['mfa_pending_analyst_id'],
                $_SESSION['mfa_pending_username'],
                $_SESSION['mfa_pending_name'],
                $_SESSION['mfa_pending_email'],
                $_SESSION['mfa_pending_allowed_modules'],
                $_SESSION['mfa_attempts']
            );
            if (function_exists('logLoginAttempt')) {
                logLoginAttempt($conn, (int)$analystId, $abandonedUsername, false);
            }
            error_log('MFA code step LOCKED for analyst ' . (int)$analystId . ' after '
                      . $throttle['attempts'] . ' failed codes across sessions, for '
                      . $throttle['minutes'] . ' minute(s), from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            echo json_encode([
                'success' => false,
                'error'   => 'Too many incorrect codes. Please try again in ' . $throttle['minutes'] . ' minute(s).',
                'restart' => true,
            ]);
            exit;
        }

        if ($_SESSION['mfa_attempts'] >= 5) {
            // ⚠️ Read the username BEFORE the unset() below, not after. The logging call
            // used to sit under the unset and read a key it had just removed, so every
            // abandoned-challenge row in login_attempts recorded "unknown" — the audit
            // trail for repeated MFA failures named nobody, which is the one thing it
            // exists to do. Caught by Erlend Volden reviewing the F8 changes.
            $abandonedUsername = (string)($_SESSION['mfa_pending_username'] ?? 'unknown');

            // Abandon the challenge entirely. Back to the password.
            unset(
                $_SESSION['mfa_pending_analyst_id'],
                $_SESSION['mfa_pending_username'],
                $_SESSION['mfa_pending_name'],
                $_SESSION['mfa_pending_email'],
                $_SESSION['mfa_pending_allowed_modules'],
                $_SESSION['mfa_attempts']
            );
            if (function_exists('logLoginAttempt')) {
                logLoginAttempt($conn, (int)$analystId, $abandonedUsername, false);
            }
            error_log('MFA challenge abandoned after 5 failed codes for analyst ' . (int)$analystId
                      . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            echo json_encode(['success' => false, 'error' => 'Too many incorrect codes. Please sign in again.', 'restart' => true]);
            exit;
        }

        error_log('Failed MFA code for analyst ' . (int)$analystId . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
                  . ' (attempt ' . (int)$_SESSION['mfa_attempts'] . ' of 5)');
        echo json_encode(['success' => false, 'error' => 'Invalid code. Please try again.']);
        exit;
    }

    // Correct code — reset both counters so a later challenge starts clean.
    // This is the ONLY place the durable count is cleared: proving possession of the
    // second factor is the only thing that should earn a fresh allowance.
    unset($_SESSION['mfa_attempts']);
    mfaThrottleReset($conn, 'analysts', (int)$analystId);

    // MFA verified — complete login. Rotate the session id first: this is the point
    // the session stops being anonymous, so it must not keep the id it arrived with.
    sessionPromoteToAuthenticated();
    $_SESSION['analyst_id'] = $_SESSION['mfa_pending_analyst_id'];
    $_SESSION['analyst_username'] = $_SESSION['mfa_pending_username'];
    $_SESSION['analyst_name'] = $_SESSION['mfa_pending_name'];
    $_SESSION['analyst_email'] = $_SESSION['mfa_pending_email'];
    $_SESSION['allowed_modules'] = $_SESSION['mfa_pending_allowed_modules'];

    // Landing preference follows the person, not the browser (#63).
    landingRefreshCookieFromPreference($conn, (int)$_SESSION['analyst_id']);

    // Clear pending state
    unset($_SESSION['mfa_pending_analyst_id']);
    unset($_SESSION['mfa_pending_username']);
    unset($_SESSION['mfa_pending_name']);
    unset($_SESSION['mfa_pending_email']);
    unset($_SESSION['mfa_pending_allowed_modules']);

    // Update last login time
    $updateSql = "UPDATE analysts SET last_login_datetime = UTC_TIMESTAMP() WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$analystId]);

    // Set trusted device cookie if enabled
    if (!empty($row['trust_device_enabled'])) {
        $tdStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'trusted_device_days'");
        $tdStmt->execute();
        $tdRow = $tdStmt->fetch(PDO::FETCH_ASSOC);
        $trustDays = (int)($tdRow['setting_value'] ?? 0);

        if ($trustDays > 0) {
            $rawToken = random_bytes(64);
            $tokenHash = hash('sha256', $rawToken);
            $cookieValue = bin2hex($rawToken);
            $expirySeconds = $trustDays * 86400;

            $insStmt = $conn->prepare("INSERT INTO trusted_devices (analyst_id, device_token_hash, user_agent, ip_address, created_datetime, expires_datetime)
                                       VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY))");
            $insStmt->execute([(int)$analystId, $tokenHash, $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '', (int)$trustDays]);

            setcookie('trusted_device', $cookieValue, time() + $expirySeconds, '/', '', false, true);

            // Clean up expired tokens for this analyst
            $cleanStmt = $conn->prepare("DELETE FROM trusted_devices WHERE analyst_id = ? AND expires_datetime < UTC_TIMESTAMP()");
            $cleanStmt->execute([(int)$analystId]);
        }
    }

    // These are consumed by window.location.href in a fetch() on /auth/login.php,
    // so they must be absolute. A bare 'index.php' resolves against /auth/ and sends
    // the analyst to /auth/index.php, which does not exist (#132) - while
    // 'force_password_change.php' resolved correctly only by accident, because that
    // page really does live in /auth/. The non-MFA path in auth/login.php builds both
    // with BASE_URL already; turning MFA on must not change where you land.
    $base = defined('BASE_URL') ? BASE_URL : '/';

    // Check password expiry
    $redirect = $base . 'index.php';
    $peStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'password_expiry_days'");
    $peStmt->execute();
    $peRow = $peStmt->fetch(PDO::FETCH_ASSOC);
    $expiryDays = (int)($peRow['setting_value'] ?? 0);

    if ($expiryDays > 0 && array_key_exists('password_changed_datetime', $row)) {
        $pwChanged = $row['password_changed_datetime'];
        $expired = false;
        if (empty($pwChanged)) {
            $expired = true;
        } else {
            $changed = new DateTime($pwChanged);
            $now = new DateTime('now', new DateTimeZone('UTC'));
            if ($now->diff($changed)->days >= $expiryDays) {
                $expired = true;
            }
        }
        if ($expired) {
            $_SESSION['password_expired'] = true;
            $redirect = $base . 'auth/force_password_change.php';
        }
    }

    // The account-level flag, separate from the expiry policy above: it is what stops
    // the seeded admin/freeitsm credentials being used indefinitely. Read defensively
    // so an install that has not run Database Verify since this shipped still logs in.
    try {
        $mcStmt = $conn->prepare("SELECT must_change_password FROM analysts WHERE id = ?");
        $mcStmt->execute([$analystId]);
        if ((int)$mcStmt->fetchColumn() === 1) {
            $_SESSION['password_expired'] = true;
            $redirect = $base . 'auth/force_password_change.php';
        }
    } catch (Exception $mcEx) {
        // column not migrated yet — nothing to enforce
    }

    echo json_encode(['success' => true, 'message' => 'Login successful', 'redirect' => $redirect]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
