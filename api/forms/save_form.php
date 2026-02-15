<?php
/**
 * API: Create or update a form with its fields
 * Expects JSON body: { id?, title, description, fields: [{ field_type, label, options?, is_required, sort_order }] }
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$fields = $input['fields'] ?? [];

if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Form title is required']);
    exit;
}

try {
    $conn = connectToDatabase();
    $conn->beginTransaction();

    $formId = (int)($input['id'] ?? 0);

    if ($formId > 0) {
        // Update existing form
        $stmt = $conn->prepare("UPDATE forms SET title = ?, description = ?, modified_date = GETUTCDATE() WHERE id = ?");
        $stmt->execute([$title, $description, $formId]);

        // Delete submission data for existing fields (FK constraint prevents direct field deletion)
        $stmt = $conn->prepare("DELETE FROM form_submission_data WHERE field_id IN (SELECT id FROM form_fields WHERE form_id = ?)");
        $stmt->execute([$formId]);

        // Delete existing fields and re-insert
        $stmt = $conn->prepare("DELETE FROM form_fields WHERE form_id = ?");
        $stmt->execute([$formId]);
    } else {
        // Create new form
        $stmt = $conn->prepare("INSERT INTO forms (title, description, created_by) OUTPUT INSERTED.id VALUES (?, ?, ?)");
        $stmt->execute([$title, $description, $_SESSION['analyst_id']]);
        $formId = (int)$stmt->fetch(PDO::FETCH_ASSOC)['id'];
    }

    // Insert fields
    $sortOrder = 0;
    foreach ($fields as $field) {
        $fieldType = $field['field_type'] ?? 'text';
        $label = trim($field['label'] ?? '');
        $options = $field['options'] ?? null;
        $isRequired = (int)($field['is_required'] ?? 0);

        if (empty($label)) continue;

        // Store options as JSON string if it's an array
        if (is_array($options)) {
            $options = json_encode($options);
        }

        $stmt = $conn->prepare("INSERT INTO form_fields (form_id, field_type, label, options, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$formId, $fieldType, $label, $options, $isRequired, $sortOrder]);
        $sortOrder++;
    }

    $conn->commit();

    echo json_encode(['success' => true, 'form_id' => $formId, 'message' => 'Form saved']);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
