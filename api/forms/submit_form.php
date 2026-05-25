<?php
/**
 * API: Submit a filled-in form
 * Expects JSON body: { form_id, data: { field_id: value, ... } }
 */
session_start(['read_and_close' => true]);
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

$formId = (int)($input['form_id'] ?? 0);
$data = $input['data'] ?? [];

if ($formId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing form ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Validate form exists and is active
    $stmt = $conn->prepare("SELECT id FROM forms WHERE id = ? AND is_active = 1");
    $stmt->execute([$formId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Form not found or inactive']);
        exit;
    }

    // Get required fields
    $stmt = $conn->prepare("SELECT id, label, is_required FROM form_fields WHERE form_id = ?");
    $stmt->execute([$formId]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Validate required fields
    foreach ($fields as $field) {
        if ($field['is_required']) {
            $val = $data[$field['id']] ?? '';
            if ($val === '' || $val === null) {
                echo json_encode(['success' => false, 'error' => '"' . $field['label'] . '" is required']);
                exit;
            }
        }
    }

    $conn->beginTransaction();

    // Create submission
    $stmt = $conn->prepare("INSERT INTO form_submissions (form_id, submitted_by) VALUES (?, ?)");
    $stmt->execute([$formId, $_SESSION['analyst_id']]);
    $submissionId = (int)$conn->lastInsertId();

    // Save field values
    foreach ($data as $fieldId => $value) {
        $fieldId = (int)$fieldId;
        if ($fieldId <= 0) continue;

        // Convert boolean checkbox values to string
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        $stmt = $conn->prepare("INSERT INTO form_submission_data (submission_id, field_id, field_value) VALUES (?, ?, ?)");
        $stmt->execute([$submissionId, $fieldId, (string)$value]);
    }

    $conn->commit();

    echo json_encode(['success' => true, 'submission_id' => $submissionId, 'message' => 'Form submitted']);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
