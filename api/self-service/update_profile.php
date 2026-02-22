<?php
/**
 * API: Update self-service user profile
 * POST - Updates preferred name
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$preferredName = trim($input['preferred_name'] ?? '');

if (strlen($preferredName) > 100) {
    echo json_encode(['success' => false, 'error' => 'Name must be 100 characters or less']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("UPDATE users SET preferred_name = ? WHERE id = ?");
    $stmt->execute([
        $preferredName !== '' ? $preferredName : null,
        $_SESSION['ss_user_id']
    ]);

    // Update session with new display name
    if ($preferredName !== '') {
        $_SESSION['ss_user_name'] = $preferredName;
    } else {
        // Fall back to display_name or email
        $userStmt = $conn->prepare("SELECT display_name, email FROM users WHERE id = ?");
        $userStmt->execute([$_SESSION['ss_user_id']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        $_SESSION['ss_user_name'] = $user['display_name'] ?: $user['email'];
    }

    echo json_encode([
        'success' => true,
        'display_name' => $_SESSION['ss_user_name']
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
