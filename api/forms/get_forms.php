<?php
/**
 * API: Get all forms with field count and submission count
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT f.id, f.title, f.description, f.is_active,
                   f.created_by, a.full_name as created_by_name,
                   DATE_FORMAT(f.created_date, '%Y-%m-%d %H:%i:%s') as created_date,
                   DATE_FORMAT(f.modified_date, '%Y-%m-%d %H:%i:%s') as modified_date,
                   (SELECT COUNT(*) FROM form_fields WHERE form_id = f.id) as field_count,
                   (SELECT COUNT(*) FROM form_submissions WHERE form_id = f.id) as submission_count
            FROM forms f
            LEFT JOIN analysts a ON f.created_by = a.id
            ORDER BY f.modified_date DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'forms' => $forms]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
