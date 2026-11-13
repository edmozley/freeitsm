<?php
/**
 * API Endpoint: attach an EXISTING document to a record.
 *
 * POST { document_id, parent_type, parent_id }
 *
 * The counterpart to save.php, which creates a new document. This one adds a
 * link to one that already exists — one warranty on eleven laptops, stored once.
 *
 * ⚠️ BOTH ENDS ARE CHECKED, and the first is the one that is easy to miss. You
 * must be able to see the DOCUMENT already, not merely the record you are
 * attaching it to. Without that check, anyone could attach a document they have
 * no access to onto a record they do — and then read it, because visibility is
 * inherited from the parent. Two clicks, and the permission model is inverted.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/documents.php';
require_once '../../includes/services/documents.php';   // GH #287: the rules, shared with REST v1

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId = (int) $_SESSION['analyst_id'];
$allowed   = analystAllowedModulesNow();   // the grants now, not at sign-in

$in         = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$documentId = (int) ($in['document_id'] ?? 0);
$parentType = trim((string) ($in['parent_type'] ?? ''));
$parentId   = (int) ($in['parent_id'] ?? 0);

// GH #303: a file from Files, as a link or a copy - FilesAttach::attach() has the rules.
if ((int)($in['files_item_id'] ?? 0) > 0) {
    require_once '../../includes/services/files_attach.php';
    try {
        $conn = connectToDatabase();
        $docId = FilesAttach::attach($conn, $analystId, $allowed, $parentType, $parentId, (int)$in['files_item_id'], (string)($in['how'] ?? ''));
        echo json_encode(['success' => true, 'document_id' => $docId]);
    } catch (ServiceError $e) {
        if ($e->kind === 'forbidden') http_response_code(403);
        if ($e->kind === 'not_found') http_response_code(404);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($documentId <= 0 || !documentEntityDef($parentType) || $parentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'A document_id, parent_type and parent_id are required.']);
    exit;
}

try {
    $conn = connectToDatabase();
    // The record you attach TO, then the document itself - DocumentsService::attach().
    DocumentsService::attach($conn, $analystId, $allowed, $parentType, $parentId, $documentId);
    echo json_encode(['success' => true, 'document_id' => $documentId]);
} catch (ServiceError $e) {
    if ($e->kind === 'forbidden') http_response_code(403);
    if ($e->kind === 'not_found') http_response_code(404);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
