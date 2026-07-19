<?php
/**
 * API: Get submissions for a form
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$formId = (int)($_GET['form_id'] ?? 0);
if ($formId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing form ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get form info
    $stmt = $conn->prepare("SELECT id, title, description FROM forms WHERE id = ?");
    $stmt->execute([$formId]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        echo json_encode(['success' => false, 'error' => 'Form not found']);
        exit;
    }

    // Get fields (for column headers)
    $stmt = $conn->prepare("SELECT id, field_type, label FROM form_fields WHERE form_id = ? ORDER BY sort_order");
    $stmt->execute([$formId]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get submissions with data
    // A submission comes from EITHER an analyst or a portal requester, and they
    // are different id spaces — so both are joined and whichever is set wins.
    // Joining only `analysts` (as this did) left every customer's request showing
    // a blank submitter.
    $stmt = $conn->prepare("SELECT s.id,
                                   COALESCE(a.full_name, u.display_name, u.email) AS submitted_by,
                                   CASE WHEN s.submitted_by_user_id IS NOT NULL THEN 1 ELSE 0 END AS from_portal,
                                   s.ticket_id,
                                   t.ticket_number,
                                   DATE_FORMAT(s.submitted_date, '%Y-%m-%d %H:%i:%s') as submitted_date
                            FROM form_submissions s
                            LEFT JOIN analysts a ON s.submitted_by = a.id
                            LEFT JOIN users    u ON u.id = s.submitted_by_user_id
                            LEFT JOIN tickets  t ON t.id = s.ticket_id
                            WHERE s.form_id = ?
                            ORDER BY s.submitted_date DESC");
    $stmt->execute([$formId]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all submission data in one query
    $submissionIds = array_column($submissions, 'id');
    $dataMap = [];

    if (!empty($submissionIds)) {
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        $stmt = $conn->prepare("SELECT submission_id, field_id, field_value
                                FROM form_submission_data
                                WHERE submission_id IN ({$placeholders})");
        $stmt->execute($submissionIds);
        $allData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allData as $d) {
            $dataMap[$d['submission_id']][$d['field_id']] = $d['field_value'];
        }
    }

    // Attach data to submissions
    foreach ($submissions as &$sub) {
        $sub['data'] = $dataMap[$sub['id']] ?? [];
    }

    echo json_encode([
        'success' => true,
        'form' => $form,
        'fields' => $fields,
        'submissions' => $submissions
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
