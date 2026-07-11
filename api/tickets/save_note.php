<?php
/**
 * API Endpoint: Save a new note.
 * Thin UI adapter over TicketsService::createNote.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/tickets.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $ticketId = $data['ticket_id'] ?? null;
    if (!$ticketId) {
        throw new Exception('Ticket ID is required');
    }
    $conn = connectToDatabase();
    TicketsService::createNote($conn, ActorContext::fromSession($conn), (int)$ticketId, [
        'text'        => $data['note_text'] ?? '',
        'is_internal' => $data['is_internal'] ?? true,
    ]);
    echo json_encode(['success' => true]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
