<?php
/**
 * API Endpoint: attach a document to something.
 *
 * Two kinds, one endpoint, because to the person using it they are one list:
 *   - file : multipart POST with `document`, stored through uploadStoreFile()
 *   - link : JSON or form POST with `external_url`, nothing stored at all
 *
 * ⚠️ THE ACCESS CHECK RUNS FIRST, BEFORE ANY FILE IS WRITTEN. Change management
 * learned this the hard way: its upload checked access AFTER move_uploaded_file(),
 * so somebody with no rights to the record could still land a file on the server
 * — the check stopped the database row, not the write.
 *
 * You may attach to a parent only if you can already SEE that parent. Which, given
 * documents inherit visibility from their parents, is also what stops attaching
 * being a way to launder a document into somewhere you can read.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/uploads.php';
require_once '../../includes/documents.php';
require_once '../../includes/services/documents.php';   // GH #287: the rules, shared with REST v1

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId = (int) $_SESSION['analyst_id'];
$allowed   = analystAllowedModulesNow();   // the grants now, not at sign-in

// A file upload arrives as multipart; a link may arrive as either.
$body = [];
if (empty($_POST) && ($raw = file_get_contents('php://input'))) {
    $body = json_decode($raw, true) ?: [];
}
$in = array_merge($body, $_POST);

$parentType = trim((string) ($in['parent_type'] ?? ''));
$parentId   = (int) ($in['parent_id'] ?? 0);
$title       = trim((string) ($in['title'] ?? ''));
$description = trim((string) ($in['description'] ?? ''));
$externalUrl = trim((string) ($in['external_url'] ?? ''));

if ($parentType === '' || $parentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'A parent_type and parent_id are required.']);
    exit;
}
if (!documentEntityDef($parentType)) {
    echo json_encode(['success' => false, 'error' => 'Unknown parent type: ' . $parentType]);
    exit;
}

try {
    $conn = connectToDatabase();
    // The rules - access first, before any file is written - live in DocumentsService.
    $result = DocumentsService::add($conn, $analystId, $allowed, $parentType, $parentId, [
        'file'         => $_FILES['document'] ?? null,
        'external_url' => $externalUrl,
        'title'        => $title,
        'description'  => $description,
    ]);
    echo json_encode(['success' => true, 'document_id' => $result['id']]);
} catch (ServiceError $e) {
    if ($e->kind === 'forbidden') http_response_code(403);
    if ($e->kind === 'not_found') http_response_code(404);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
