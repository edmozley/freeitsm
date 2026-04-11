<?php
/**
 * LMS API: Single group operations (PUT update with members, DELETE deactivate)
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
$input = json_decode(file_get_contents('php://input'), true);
$id = (int)($input['id'] ?? 0);
$method = $input['_method'] ?? $_SERVER['REQUEST_METHOD'];

if (strtoupper($method) === 'PUT') {
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $memberIds = $input['member_ids'] ?? [];

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Name is required']);
        exit;
    }

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare("UPDATE lms_learning_groups SET name = ?, description = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$name, $description, $id]);

        // Replace members
        $conn->prepare("DELETE FROM lms_learning_group_members WHERE group_id = ?")->execute([$id]);
        if (!empty($memberIds)) {
            $mStmt = $conn->prepare("INSERT INTO lms_learning_group_members (group_id, analyst_id) VALUES (?, ?)");
            foreach ($memberIds as $aid) {
                $mStmt->execute([$id, (int)$aid]);
            }
        }

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (strtoupper($method) === 'DELETE') {
    $conn->prepare("UPDATE lms_learning_groups SET is_active = 0, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid method']);
