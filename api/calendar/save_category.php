<?php
/**
 * API Endpoint: Save Calendar Category.
 * Thin UI adapter over CalendarService (categories are UI-only — no API twin).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/calendar.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('calendar');

try {
    $conn  = connectToDatabase();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    // The UI already sends canonical keys (id, name, color, description, is_active).
    $res = CalendarService::saveCategory($conn, ActorContext::fromSession($conn), $input);
    echo json_encode([
        'success' => true,
        'message' => $res['created'] ? 'Category created' : 'Category updated',
        'id'      => $res['id'],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
