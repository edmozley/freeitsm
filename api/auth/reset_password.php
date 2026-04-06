<?php
/**
 * Reset Password
 *
 * Validates a reset token and updates the analyst's password.
 *
 * POST JSON: { "token": "...", "new_password": "...", "confirm_password": "..." }
 */
session_start();
header('Content-Type: application/json');

require_once '../../config.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = trim($input['token'] ?? '');
    $newPassword = $input['new_password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';

    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Invalid or missing reset token.']);
        exit;
    }

    if (empty($newPassword) || empty($confirmPassword)) {
        echo json_encode(['success' => false, 'error' => 'Please fill in both password fields.']);
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'error' => 'Passwords do not match.']);
        exit;
    }

    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters.']);
        exit;
    }

    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Look up token
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT id, analyst_id, expires_datetime, used FROM password_reset_tokens WHERE token_hash = ?");
    $stmt->execute([$tokenHash]);
    $resetToken = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resetToken) {
        echo json_encode(['success' => false, 'error' => 'Invalid reset link. Please request a new one.']);
        exit;
    }

    if ($resetToken['used']) {
        echo json_encode(['success' => false, 'error' => 'This reset link has already been used. Please request a new one.']);
        exit;
    }

    $expires = new DateTime($resetToken['expires_datetime']);
    $now = new DateTime('now', new DateTimeZone('UTC'));
    if ($now > $expires) {
        echo json_encode(['success' => false, 'error' => 'This reset link has expired. Please request a new one.']);
        exit;
    }

    // Update password
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateStmt = $conn->prepare("UPDATE analysts SET password_hash = ?, password_changed_datetime = UTC_TIMESTAMP(), failed_login_count = 0, locked_until = NULL WHERE id = ?");
    $updateStmt->execute([$passwordHash, $resetToken['analyst_id']]);

    // Mark token as used
    $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = ?")->execute([$resetToken['id']]);

    // Log the reset
    try {
        $details = json_encode([
            'action' => 'password_reset',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES ('password_reset', ?, ?, UTC_TIMESTAMP())")
            ->execute([$resetToken['analyst_id'], $details]);
    } catch (Exception $e) {
        // Don't break the reset if logging fails
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('Password reset error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred. Please try again.']);
}
