<?php
/**
 * API Endpoint: Save a new note.
 * Thin UI adapter over TicketsService::createNote.
 *
 * Two shapes. JSON is the long-standing one (checklists still use it). A
 * multipart POST is the note box when it holds pasted pictures (GH #224):
 * the pictures are validated and stored FIRST, so a refused one refuses the
 * whole save - no note, no shared-note email, and the analyst keeps their text.
 * includes/note_images.php explains the marker the text carries.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/tickets.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

$docIds = [];
try {
    $multipart = !empty($_POST);
    $data = $multipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);
    $ticketId = $data['ticket_id'] ?? null;
    if (!$ticketId) {
        throw new Exception('Ticket ID is required');
    }
    // A form field arrives as a string; "0" must still mean shared.
    $isInternal = $data['is_internal'] ?? true;
    if (is_string($isInternal)) $isInternal = !in_array(strtolower($isInternal), ['0', 'false', ''], true);
    $text = (string) ($data['note_text'] ?? '');

    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];

    $images = [];
    if ($multipart) {
        require_once '../../includes/note_images.php';
        $images = noteImagesFromRequest();
    }
    if ($images) {
        // The same gate as attaching a file, checked before anything is written:
        // read-only access to Tickets attaches nothing (#181).
        if (!documentCanWriteParent($conn, $analystId, analystAllowedModulesNow(), 'ticket', (int) $ticketId)) {
            throw new Exception('Ticket not found');
        }
        $docIds = noteImagesStore($conn, $analystId, $images, getActiveTenantId($conn, $analystId) ?: null);
        $text = noteImagesRewriteMarkers($text, $docIds);
    }

    $noteId = TicketsService::createNote($conn, ActorContext::fromSession($conn), (int)$ticketId, [
        'text'        => $text,
        'is_internal' => $isInternal,
    ]);

    // The note exists: its pictures become its documents. From here on they are
    // ordinary note files - the portal serves them on a shared note, the Files
    // viewer opens them, and deleting the ticket removes them.
    foreach ($docIds as $docId) {
        documentInsertLink($conn, $docId, 'ticket_note', $noteId, $analystId);
    }
    $docIds = [];

    // The id is returned because attachments need it (discussion #69): a note has
    // no id until it is saved, so the modal holds the chosen files in the browser
    // and uploads them against this id once the note is real. It was always
    // produced — createNote() returns it — and simply thrown away here.
    echo json_encode(['success' => true, 'note_id' => $noteId]);
} catch (ServiceError $e) {
    if ($docIds) noteImagesDiscard($conn, $docIds);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    if ($docIds && isset($conn)) noteImagesDiscard($conn, $docIds);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
