<?php
/**
 * LMS API: List groups (GET) or Create group (POST)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$conn = connectToDatabase();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "SELECT g.*,
                   (SELECT COUNT(*) FROM lms_learning_group_members WHERE group_id = g.id) as member_count
            FROM lms_learning_groups g
            WHERE g.is_active = 1
            ORDER BY g.name";
    $stmt = $conn->query($sql);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load members for each group
    foreach ($groups as &$g) {
        $mStmt = $conn->prepare("SELECT m.analyst_id, a.full_name, a.username FROM lms_learning_group_members m JOIN analysts a ON m.analyst_id = a.id WHERE m.group_id = ? ORDER BY a.full_name");
        $mStmt->execute([$g['id']]);
        $g['members'] = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'data' => $groups]);
    exit;
}

// POST: create group
$input = json_decode(file_get_contents('php://input'), true);
$name = trim($input['name'] ?? '');
$description = trim($input['description'] ?? '');
$memberIds = $input['member_ids'] ?? [];

if (empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Name is required']);
    exit;
}

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("INSERT INTO lms_learning_groups (name, description, created_by_id) VALUES (?, ?, ?)");
    $stmt->execute([$name, $description, $_SESSION['analyst_id']]);
    $groupId = (int)$conn->lastInsertId();

    if (!empty($memberIds)) {
        $mStmt = $conn->prepare("INSERT INTO lms_learning_group_members (group_id, analyst_id) VALUES (?, ?)");
        foreach ($memberIds as $aid) {
            $mStmt->execute([$groupId, (int)$aid]);
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'id' => $groupId]);
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
