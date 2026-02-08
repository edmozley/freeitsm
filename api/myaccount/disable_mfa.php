<?php
/**
 * API: Disable MFA
 * POST - Verifies password and disables MFA for the current analyst
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
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
    $analystId = $_SESSION['analyst_id'];

    // Verify password
    $sql = "SELECT password_hash FROM analysts WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$analystId]);
    $analyst = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$analyst || !password_verify($password, $analyst['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Incorrect password']);
        exit;
    }

    // Disable MFA
    $updateSql = "UPDATE analysts SET totp_enabled = 0, totp_secret = NULL, last_modified_datetime = GETDATE() WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$analystId]);

    echo json_encode(['success' => true, 'message' => 'MFA has been disabled']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
