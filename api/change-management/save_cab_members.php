<?php
/**
 * API Endpoint: Sync CAB members for a change
 * Diffs against existing members: adds new, removes deleted, updates is_required changes.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId = (int)$_SESSION['analyst_id'];
$input = json_decode(file_get_contents('php://input'), true);

$changeId = (int)($input['change_id'] ?? 0);
$members = $input['members'] ?? [];

if (!$changeId) {
    echo json_encode(['success' => false, 'error' => 'Change ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Fetch existing members
    $existStmt = $conn->prepare("SELECT analyst_id, is_required FROM change_cab_members WHERE change_id = ?");
    $existStmt->execute([$changeId]);
    $existingRows = $existStmt->fetchAll(PDO::FETCH_ASSOC);
    $existingMap = [];
    foreach ($existingRows as $row) {
        $existingMap[(int)$row['analyst_id']] = (int)$row['is_required'];
    }

    // Build new map
    $newMap = [];
    foreach ($members as $m) {
        $newMap[(int)$m['analyst_id']] = (int)($m['is_required'] ?? 1);
    }

    // Fetch analyst names for audit display
    $allIds = array_unique(array_merge(array_keys($existingMap), array_keys($newMap)));
    $nameMap = [];
    if (!empty($allIds)) {
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $nameStmt = $conn->prepare("SELECT id, full_name FROM analysts WHERE id IN ($placeholders)");
        $nameStmt->execute(array_values($allIds));
        foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nameMap[(int)$row['id']] = $row['full_name'];
        }
    }

    $auditSql = "INSERT INTO change_audit (change_id, analyst_id, action_type, field_name, old_value, new_value, created_datetime)
                 VALUES (?, ?, 'cab_vote', 'CAB Member', ?, ?, UTC_TIMESTAMP())";
    $auditStmt = $conn->prepare($auditSql);

    // Add new members
    $insertSql = "INSERT INTO change_cab_members (change_id, analyst_id, is_required, added_by_id, added_datetime)
                  VALUES (?, ?, ?, ?, UTC_TIMESTAMP())";
    $insertStmt = $conn->prepare($insertSql);

    foreach ($newMap as $aId => $isReq) {
        if (!isset($existingMap[$aId])) {
            $insertStmt->execute([$changeId, $aId, $isReq, $analystId]);
            $name = $nameMap[$aId] ?? 'Unknown';
            $reqLabel = $isReq ? 'Required' : 'Optional';
            $auditStmt->execute([$changeId, $analystId, null, "Added: $name ($reqLabel)"]);
        }
    }

    // Remove deleted members
    $deleteSql = "DELETE FROM change_cab_members WHERE change_id = ? AND analyst_id = ?";
    $deleteStmt = $conn->prepare($deleteSql);

    foreach ($existingMap as $aId => $isReq) {
        if (!isset($newMap[$aId])) {
            $deleteStmt->execute([$changeId, $aId]);
            $name = $nameMap[$aId] ?? 'Unknown';
            $auditStmt->execute([$changeId, $analystId, $name, 'Removed']);
        }
    }

    // Update is_required changes
    $updateSql = "UPDATE change_cab_members SET is_required = ? WHERE change_id = ? AND analyst_id = ?";
    $updateStmt = $conn->prepare($updateSql);

    foreach ($newMap as $aId => $isReq) {
        if (isset($existingMap[$aId]) && $existingMap[$aId] !== $isReq) {
            $updateStmt->execute([$isReq, $changeId, $aId]);
            $name = $nameMap[$aId] ?? 'Unknown';
            $oldLabel = $existingMap[$aId] ? 'Required' : 'Optional';
            $newLabel = $isReq ? 'Required' : 'Optional';
            $auditStmt->execute([$changeId, $analystId, "$name: $oldLabel", "$name: $newLabel"]);
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
