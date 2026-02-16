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
    $sql = "SELECT totp_secret, trust_device_enabled, password_changed_datetime FROM analysts WHERE id = ? AND totp_enabled = 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$analystId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['totp_secret'])) {
        echo json_encode(['success' => false, 'error' => 'MFA configuration error']);
        exit;
    }

    // Decrypt the secret
    $secret = decryptValue($row['totp_secret']);

    // Verify the code
    if (!verifyTotpCode($secret, $code)) {
        echo json_encode(['success' => false, 'error' => 'Invalid code. Please try again.']);
        exit;
    }

    // MFA verified — complete login
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
    $updateSql = "UPDATE analysts SET last_login_datetime = GETUTCDATE() WHERE id = ?";
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

            // Interpolate int values directly — PDO ODBC sends all params as text type
            // which SQL Server refuses for int columns
            $aid = intval($analystId);
            $days = intval($trustDays);
            $insStmt = $conn->prepare("INSERT INTO trusted_devices (analyst_id, device_token_hash, user_agent, ip_address, created_datetime, expires_datetime)
                                       VALUES ({$aid}, ?, ?, ?, GETUTCDATE(), DATEADD(DAY, {$days}, GETUTCDATE()))");
            $insStmt->execute([$tokenHash, $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '']);

            setcookie('trusted_device', $cookieValue, time() + $expirySeconds, '/', '', false, true);

            // Clean up expired tokens for this analyst
            $cleanStmt = $conn->prepare("DELETE FROM trusted_devices WHERE analyst_id = {$aid} AND expires_datetime < GETUTCDATE()");
            $cleanStmt->execute();
        }
    }

    // Check password expiry
    $redirect = 'index.php';
    $peStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'password_expiry_days'");
    $peStmt->execute();
    $peRow = $peStmt->fetch(PDO::FETCH_ASSOC);
    $expiryDays = (int)($peRow['setting_value'] ?? 0);

    if ($expiryDays > 0) {
        $pwChanged = $row['password_changed_datetime'] ?? null;
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

    echo json_encode(['success' => true, 'message' => 'Login successful', 'redirect' => $redirect]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
