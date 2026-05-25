<?php
/**
 * API: Get a single form with its fields
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$formId = (int)($_GET['id'] ?? 0);
if ($formId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing form ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT id, title, description, is_active, created_by,
                                   DATE_FORMAT(created_date, '%Y-%m-%d %H:%i:%s') as created_date
                            FROM forms WHERE id = ?");
    $stmt->execute([$formId]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        echo json_encode(['success' => false, 'error' => 'Form not found']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, field_type, label, options, is_required, sort_order
                            FROM form_fields WHERE form_id = ? ORDER BY sort_order");
    $stmt->execute([$formId]);
    $form['fields'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'form' => $form]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
