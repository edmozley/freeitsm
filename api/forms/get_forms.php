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
                   f.created_by,  ca.full_name AS created_by_name,
                   DATE_FORMAT(f.created_date,  '%Y-%m-%d %H:%i:%s') AS created_date,
                   f.modified_by, ma.full_name AS modified_by_name,
                   DATE_FORMAT(f.modified_date, '%Y-%m-%d %H:%i:%s') AS modified_date,
                   f.version_number,
                   (SELECT COUNT(*) FROM form_fields      WHERE form_id = f.id) AS field_count,
                   (SELECT COUNT(*) FROM form_submissions WHERE form_id = f.id) AS submission_count
            FROM forms f
            LEFT JOIN analysts ca ON f.created_by  = ca.id
            LEFT JOIN analysts ma ON f.modified_by = ma.id
            ORDER BY f.modified_date DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'forms' => $forms]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
