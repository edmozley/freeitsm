<?php
/**
 * API: Save (insert or update) a morning-check status.
 * POST body: { statusId?, label, colour, requiresNotes, isActive, sortOrder? }
 *
 * Thin UI adapter over MorningChecksService (UI-only — the REST API only lists statuses).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/morning_checks.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $statusId = MorningChecksService::saveStatus($conn, ActorContext::fromSession($conn), [
        'id'             => $input['statusId'] ?? null,
        'label'          => $input['label'] ?? '',
        'colour'         => $input['colour'] ?? '',
        'requires_notes' => !empty($input['requiresNotes']),
        'is_active'      => $input['isActive'] ?? null,
        'sort_order'     => $input['sortOrder'] ?? null,
    ]);
    echo json_encode(['success' => true, 'statusId' => $statusId]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
