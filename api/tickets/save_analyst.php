<?php
/**
 * API Endpoint: Save (create or update) an analyst
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$id = $data['id'] ?? null;
$username = trim($data['username'] ?? '');
$fullName = trim($data['full_name'] ?? '');
$email = trim($data['email'] ?? '') ?: null;
$password = $data['password'] ?? null;
$isActive = $data['is_active'] ?? true;

// Validation
if (empty($username)) {
    echo json_encode(['success' => false, 'error' => 'Username is required']);
    exit;
}

if (empty($fullName)) {
    echo json_encode(['success' => false, 'error' => 'Full name is required']);
    exit;
}

// Password required for new analysts
if (empty($id) && empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Password is required for new analysts']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Check for duplicate username
    $checkSql = "SELECT id FROM analysts WHERE username = ? AND id != ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$username, $id ?? 0]);
    if ($checkStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Username already exists']);
        exit;
    }

    if ($id) {
        // Update existing analyst
        if (!empty($password)) {
            // Update with new password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $sql = "UPDATE analysts SET
                    username = ?,
                    full_name = ?,
                    email = ?,
                    password_hash = ?,
                    is_active = ?,
                    last_modified_datetime = UTC_TIMESTAMP()
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$username, $fullName, $email, $passwordHash, $isActive ? 1 : 0, $id]);
        } else {
            // Update without changing password
            $sql = "UPDATE analysts SET
                    username = ?,
                    full_name = ?,
                    email = ?,
                    is_active = ?,
                    last_modified_datetime = UTC_TIMESTAMP()
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$username, $fullName, $email, $isActive ? 1 : 0, $id]);
        }

        echo json_encode(['success' => true, 'message' => 'Analyst updated successfully']);
    } else {
        // Create new analyst
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO analysts (username, password_hash, full_name, email, is_active, created_datetime, last_modified_datetime)
                VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$username, $passwordHash, $fullName, $email, $isActive ? 1 : 0]);

        echo json_encode(['success' => true, 'message' => 'Analyst created successfully']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
