<?php
/**
 * API Endpoint: Create or update an email template
 * POST: { id, name, event_trigger, subject_template, body_template, is_active, display_order }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$id = $data['id'] ?? null;
$name = trim($data['name'] ?? '');
$eventTrigger = trim($data['event_trigger'] ?? '');
$subjectTemplate = trim($data['subject_template'] ?? '');
$bodyTemplate = trim($data['body_template'] ?? '');
$isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
$displayOrder = isset($data['display_order']) ? (int)$data['display_order'] : 0;

$validEvents = ['new_ticket_email', 'ticket_assigned', 'ticket_closed', 'csat_request'];

if ($name === '' || $eventTrigger === '' || $subjectTemplate === '' || $bodyTemplate === '') {
    echo json_encode(['success' => false, 'error' => 'Name, event trigger, subject, and body are required']);
    exit;
}

if (!in_array($eventTrigger, $validEvents)) {
    echo json_encode(['success' => false, 'error' => 'Invalid event trigger']);
    exit;
}

try {
    $conn = connectToDatabase();

    if ($id) {
        $sql = "UPDATE ticket_email_templates
                SET name = ?, event_trigger = ?, subject_template = ?, body_template = ?,
                    is_active = ?, display_order = ?, updated_datetime = UTC_TIMESTAMP()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $eventTrigger, $subjectTemplate, $bodyTemplate, $isActive, $displayOrder, $id]);
    } else {
        $sql = "INSERT INTO ticket_email_templates
                (name, event_trigger, subject_template, body_template, is_active, display_order)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $eventTrigger, $subjectTemplate, $bodyTemplate, $isActive, $displayOrder]);
        $id = $conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
