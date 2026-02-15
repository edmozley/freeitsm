<?php
/**
 * API: Create or update a form with its fields
 * Expects JSON body: { id?, title, description, fields: [{ field_type, label, options?, is_required }] }
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
        // Update form metadata
        $stmt = $conn->prepare("UPDATE forms SET title = ?, description = ?, modified_date = GETUTCDATE() WHERE id = ?");
        $stmt->execute([$title, $description, $formId]);

        // Get existing field IDs in sort order
        $stmt = $conn->prepare("SELECT id FROM form_fields WHERE form_id = ? ORDER BY sort_order");
        $stmt->execute([$formId]);
        $existingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Build list of valid incoming fields
        $validFields = [];
        foreach ($fields as $field) {
            $label = trim($field['label'] ?? '');
            if (empty($label)) continue;
            $options = $field['options'] ?? null;
            if (is_array($options)) $options = json_encode($options);
            $validFields[] = [
                'field_type' => $field['field_type'] ?? 'text',
                'label'      => $label,
                'options'    => $options,
                'is_required'=> (int)($field['is_required'] ?? 0),
            ];
        }

        // Update existing fields in place, insert new ones
        foreach ($validFields as $i => $f) {
            if ($i < count($existingIds)) {
                // Update existing field (preserves ID and submission data)
                $stmt = $conn->prepare("UPDATE form_fields SET field_type = ?, label = ?, options = ?, is_required = ?, sort_order = ? WHERE id = ?");
                $stmt->execute([$f['field_type'], $f['label'], $f['options'], $f['is_required'], $i, $existingIds[$i]]);
            } else {
                // Insert new field
                $stmt = $conn->prepare("INSERT INTO form_fields (form_id, field_type, label, options, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$formId, $f['field_type'], $f['label'], $f['options'], $f['is_required'], $i]);
            }
        }

        // Remove fields that no longer exist (user deleted them from the form)
        $removeIds = array_slice($existingIds, count($validFields));
        foreach ($removeIds as $removeId) {
            $stmt = $conn->prepare("DELETE FROM form_submission_data WHERE field_id = ?");
            $stmt->execute([$removeId]);
            $stmt = $conn->prepare("DELETE FROM form_fields WHERE id = ?");
            $stmt->execute([$removeId]);
        }
    } else {
        // Create new form
        $stmt = $conn->prepare("INSERT INTO forms (title, description, created_by) OUTPUT INSERTED.id VALUES (?, ?, ?)");
        $stmt->execute([$title, $description, $_SESSION['analyst_id']]);
        $formId = (int)$stmt->fetch(PDO::FETCH_ASSOC)['id'];

        // Insert fields
        $sortOrder = 0;
        foreach ($fields as $field) {
            $label = trim($field['label'] ?? '');
            if (empty($label)) continue;
            $options = $field['options'] ?? null;
            if (is_array($options)) $options = json_encode($options);
            $stmt = $conn->prepare("INSERT INTO form_fields (form_id, field_type, label, options, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$formId, $field['field_type'] ?? 'text', $label, $options, (int)($field['is_required'] ?? 0), $sortOrder]);
            $sortOrder++;
        }
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
