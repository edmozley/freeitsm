<?php
/**
 * API Endpoint: Delete an analyst
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$id = (int)$data['id'];

// Prevent self-deletion
if ($id === $_SESSION['analyst_id']) {
    echo json_encode(['success' => false, 'error' => 'You cannot delete your own account']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Check if analyst exists
    $checkSql = "SELECT id, username FROM analysts WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$id]);
    $analyst = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$analyst) {
        echo json_encode(['success' => false, 'error' => 'Analyst not found']);
        exit;
    }

    // Never delete the last active administrator — it would lock everyone out of System.
    $isTargetAdmin = (int)$conn->query("SELECT is_admin FROM analysts WHERE id = " . (int)$id)->fetchColumn() === 1;
    if ($isTargetAdmin) {
        $otherAdmins = (int)$conn->query("SELECT COUNT(*) FROM analysts WHERE is_admin = 1 AND is_active = 1 AND id <> " . (int)$id)->fetchColumn();
        if ($otherAdmins === 0) {
            echo json_encode(['success' => false, 'error' => 'This is the last active administrator and cannot be deleted. Grant admin to another analyst first.']);
            exit;
        }
    }

    // Delete the analyst
    $sql = "DELETE FROM analysts WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Analyst deleted successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
