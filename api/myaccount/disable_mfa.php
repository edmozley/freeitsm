<?php
/**
 * API: Disable MFA
 * POST - Verifies password and disables MFA
 * Works for both analysts and self-service users via getMfaAuthContext()
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/mfa_helpers.php';

header('Content-Type: application/json');

$ctx = getMfaAuthContext();
if (!$ctx) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$password = $input['password'] ?? '';

if (empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Password is required to disable MFA']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Verify password
    $sql = "SELECT password_hash FROM {$ctx['table']} WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$ctx['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($password, $row['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Incorrect password']);
        exit;
    }

    // Disable MFA
    if ($ctx['type'] === 'analyst') {
        $updateSql = "UPDATE {$ctx['table']} SET totp_enabled = 0, totp_secret = NULL, last_modified_datetime = UTC_TIMESTAMP() WHERE id = ?";
    } else {
        $updateSql = "UPDATE {$ctx['table']} SET totp_enabled = 0, totp_secret = NULL WHERE id = ?";
    }
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$ctx['id']]);

    echo json_encode(['success' => true, 'message' => 'MFA has been disabled']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
