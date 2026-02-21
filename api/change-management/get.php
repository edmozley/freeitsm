<?php
/**
 * API Endpoint: Get single change by ID with attachments and comments
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$changeId = (int)($_GET['id'] ?? 0);

if (!$changeId) {
    echo json_encode(['success' => false, 'error' => 'Change ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT
                c.*,
                requester.full_name as requester_name,
                assigned.full_name as assigned_to_name,
                approver.full_name as approver_name,
                creator.full_name as created_by_name,
                cat.name as category_name
            FROM changes c
            LEFT JOIN analysts requester ON c.requester_id = requester.id
            LEFT JOIN analysts assigned ON c.assigned_to_id = assigned.id
            LEFT JOIN analysts approver ON c.approver_id = approver.id
            LEFT JOIN analysts creator ON c.created_by_id = creator.id
            LEFT JOIN change_categories cat ON c.category_id = cat.id
            WHERE c.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$changeId]);
    $change = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$change) {
        echo json_encode(['success' => false, 'error' => 'Change not found']);
        exit;
    }

    // Get attachments
    $attSql = "SELECT id, file_name, file_size, file_type, uploaded_datetime
               FROM change_attachments
               WHERE change_id = ?
               ORDER BY uploaded_datetime DESC";
    $attStmt = $conn->prepare($attSql);
    $attStmt->execute([$changeId]);
    $change['attachments'] = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get comments
    $commentSql = "SELECT c.id, c.analyst_id, c.comment_text, c.is_internal, c.created_datetime,
                          a.full_name as analyst_name
                   FROM change_comments c
                   LEFT JOIN analysts a ON c.analyst_id = a.id
                   WHERE c.change_id = ?
                   ORDER BY c.created_datetime DESC
                   LIMIT 100";
    $commentStmt = $conn->prepare($commentSql);
    $commentStmt->execute([$changeId]);
    $change['comments'] = $commentStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get CAB members
    $cabSql = "SELECT m.id, m.analyst_id, m.is_required, m.vote, m.vote_comment,
                      m.vote_datetime, m.added_by_id, m.added_datetime,
                      a.full_name as analyst_name, adder.full_name as added_by_name
               FROM change_cab_members m
               LEFT JOIN analysts a ON m.analyst_id = a.id
               LEFT JOIN analysts adder ON m.added_by_id = adder.id
               WHERE m.change_id = ?
               ORDER BY m.is_required DESC, a.full_name ASC";
    $cabStmt = $conn->prepare($cabSql);
    $cabStmt->execute([$changeId]);
    $change['cab_members'] = $cabStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'change' => $change
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
