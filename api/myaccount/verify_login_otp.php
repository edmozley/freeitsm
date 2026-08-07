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

    // Decrypt the secret
    $secret = decryptValue($row['totp_secret']);

    // ⚠️ Verify the code — and COUNT the failures.
    //
    // This endpoint had no attempt counter, no delay and no logging, and it sits
    // outside the IP-ban path that protects the password step. A six-digit TOTP is
    // one million possibilities, which is nothing to a script: given a valid
    // password, the second factor was brute-forceable.
    //
    // The counter lives in the session because the challenge does — mfa_pending_*
    // is what makes this endpoint answer at all. An attacker who throws the session
    // away to reset the count has to present the password again, and THAT path is
    // rate-limited by account lockout and the IP ban. So this closes the loop rather
    // than just narrowing it.
    if (!verifyTotpCode($secret, $code)) {
        $_SESSION['mfa_attempts'] = ($_SESSION['mfa_attempts'] ?? 0) + 1;

        if ($_SESSION['mfa_attempts'] >= 5) {
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
                logLoginAttempt($conn, (int)$analystId, (string)($_SESSION['mfa_pending_username'] ?? 'unknown'), false);
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

    // Correct code — reset the counter so a later challenge starts clean.
    unset($_SESSION['mfa_attempts']);

    // MFA verified — complete login. Rotate the session id first: this is the point
    // the session stops being anonymous, so it must not keep the id it arrived with.
    sessionPromoteToAuthenticated();
    $_SESSION['analyst_id'] = $_SESSION['mfa_pending_analyst_id'];
    $_SESSION['analyst_username'] = $_SESSION['mfa_pending_username'];
    $_SESSION['analyst_name'] = $_SESSION['mfa_pending_name'];
    $_SESSION['analyst_email'] = $_SESSION['mfa_pending_email'];
    $_SESSION['allowed_modules'] = $_SESSION['mfa_pending_allowed_modules'];

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

    // Check password expiry
    $redirect = 'index.php';
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
            $redirect = 'force_password_change.php';
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
            $redirect = 'force_password_change.php';
        }
    } catch (Exception $mcEx) {
        // column not migrated yet — nothing to enforce
    }

    echo json_encode(['success' => true, 'message' => 'Login successful', 'redirect' => $redirect]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
