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
    $hash = password_hash($password, PASSWORD_BCRYPT);

    // Check if email belongs to an analyst account
    $analystStmt = $conn->prepare("SELECT id FROM analysts WHERE email = ?");
    $analystStmt->execute([$email]);
    if ($analystStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'An account with this email already exists. Please use the main login.']);
        exit;
    }

    // Check if user already exists
    $userStmt = $conn->prepare("SELECT id, password_hash FROM users WHERE email = ?");
    $userStmt->execute([$email]);
    $existingUser = $userStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingUser) {
        if (!empty($existingUser['password_hash'])) {
            // Already has a password - can't register again
            echo json_encode(['success' => false, 'error' => 'An account with this email already exists. Please log in.']);
            exit;
        }

        // Claim existing passwordless account
        $updateStmt = $conn->prepare("UPDATE users SET password_hash = ?, display_name = COALESCE(NULLIF(?, ''), display_name) WHERE id = ?");
        $updateStmt->execute([$hash, $displayName, $existingUser['id']]);

        $_SESSION['ss_user_id'] = (int)$existingUser['id'];
        $_SESSION['ss_user_email'] = $email;
        $_SESSION['ss_user_name'] = $displayName;
    } else {
        // Create new user
        $insertStmt = $conn->prepare("INSERT INTO users (email, display_name, password_hash, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())");
        $insertStmt->execute([$email, $displayName, $hash]);

        $_SESSION['ss_user_id'] = (int)$conn->lastInsertId();
        $_SESSION['ss_user_email'] = $email;
        $_SESSION['ss_user_name'] = $displayName;
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Registration failed. Please try again.']);
}
