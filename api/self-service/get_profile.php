<?php
/**
 * API: Get self-service user profile
 * GET - Returns display name, email, and preferred name
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT email, display_name, preferred_name FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['ss_user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'email' => $user['email'],
        'display_name' => $user['display_name'],
        'preferred_name' => $user['preferred_name'] ?? ''
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
