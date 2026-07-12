<?php
/**
 * LMS API: Get all active analysts (for group member selection)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
// The full analyst list is a management picker (choosing group members) — a
// learner has no business enumerating everyone, so it's manage-only.
requireCapabilityJson('lms.manage');

$conn = connectToDatabase();
$stmt = $conn->query("SELECT id, full_name, username FROM analysts WHERE is_active = 1 ORDER BY full_name");
echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
