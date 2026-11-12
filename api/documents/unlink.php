<?php
/**
 * API Endpoint: detach a document from one record.
 *
 * 🔑 THIS REMOVES THE LINK, NOT THE DOCUMENT. The same file may be attached to
 * eleven other things, and taking it off a contract must not delete it from
 * under them. Only when the LAST link goes does the document become an orphan —
 * and an orphan is then soft-deleted and its file removed, because a document
 * nothing points at is visible to nobody and would otherwise sit on disk for ever.
 *
 * You may detach from a record you can see. That is the same permission as
 * attaching to it, deliberately: anyone who can put a document on a contract can
 * take it off again.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
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

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$documentId = (int) ($in['document_id'] ?? 0);
$parentType = trim((string) ($in['parent_type'] ?? ''));
$parentId   = (int) ($in['parent_id'] ?? 0);

if ($documentId <= 0 || !documentEntityDef($parentType) || $parentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'A document_id, parent_type and parent_id are required.']);
    exit;
}

try {
    $conn = connectToDatabase();
    $orphaned = DocumentsService::detach($conn, $analystId, $allowed, $parentType, $parentId, $documentId);
    echo json_encode([
        'success'  => true,
        'orphaned' => $orphaned,   // true = that was its last link, so it is gone
    ]);
} catch (ServiceError $e) {
    if ($e->kind === 'forbidden') http_response_code(403);
    if ($e->kind === 'not_found') http_response_code(404);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
