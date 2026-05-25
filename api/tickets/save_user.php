<?php
/**
 * API Endpoint: Save (create or update) an end user
 *
 * Password is optional. Leaving it blank on create means the user is "passwordless" —
 * the same state inbound-ticket users start in. They can later claim the account via
 * the self-service portal's register flow by setting their own password.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$id            = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;
$email         = strtolower(trim($data['email'] ?? ''));
$displayName   = trim($data['display_name'] ?? '');
$preferredName = trim($data['preferred_name'] ?? '');
$password      = $data['password'] ?? '';

if (empty($email)) {
    echo json_encode(['success' => false, 'error' => 'Email is required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address']);
    exit;
}

if ($password !== '' && strlen($password) < 8) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Email must be unique within users, and must not collide with an analyst account
    $analystStmt = $conn->prepare("SELECT id FROM analysts WHERE LOWER(email) = ?");
    $analystStmt->execute([$email]);
    if ($analystStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'This email belongs to an analyst account']);
        exit;
    }

    $dupeStmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND id != ?");
    $dupeStmt->execute([$email, $id ?? 0]);
    if ($dupeStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'A user with this email already exists']);
        exit;
    }

    if ($id) {
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET email = ?, display_name = ?, preferred_name = ?, password_hash = ? WHERE id = ?");
            $stmt->execute([$email, $displayName ?: null, $preferredName ?: null, $hash, $id]);
        } else {
            $stmt = $conn->prepare("UPDATE users SET email = ?, display_name = ?, preferred_name = ? WHERE id = ?");
            $stmt->execute([$email, $displayName ?: null, $preferredName ?: null, $id]);
        }
        echo json_encode(['success' => true, 'id' => $id, 'message' => 'User updated']);
    } else {
        $hash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null;
        $stmt = $conn->prepare("INSERT INTO users (email, display_name, preferred_name, password_hash, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())");
        $stmt->execute([$email, $displayName ?: null, $preferredName ?: null, $hash]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'message' => 'User created']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
