<?php
/**
 * API Endpoint: Get all email templates
 * GET: Returns all templates ordered by display_order, id
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

    $sql = "SELECT id, name, event_trigger, subject_template, body_template,
                   is_active, display_order, created_datetime, updated_datetime
            FROM ticket_email_templates
            ORDER BY display_order ASC, id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'templates' => $templates]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
