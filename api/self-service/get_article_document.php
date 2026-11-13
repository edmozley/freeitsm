<?php
/**
 * Serve a document attached to a knowledge article, to a portal requester who
 * may read that article in the Help Centre (GH #177).
 *
 * The portal twin of api/documents/download.php for ARTICLES, as
 * get_document.php is for shared ticket notes. Kept as its own endpoint rather
 * than a branch of that one: the two answer different questions ("did you raise
 * this ticket" against "may you read this article"), and each is easier to
 * trust when it answers exactly one.
 *
 * Every refusal is the same 404 - a wrong id, a hidden document, an article in
 * another company, an internal article, a document that is only on a contract -
 * so ids cannot be used to learn what exists. The decision is
 * helpCentreDocumentForPortalUser(); this file only serves what it returns.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/uploads.php';     // attachmentSendHeaders()
require_once '../../includes/knowledge/help_centre_documents.php';

if (!isset($_SESSION['ss_user_id'])) {
    http_response_code(401);
    exit('Not authenticated');
}

$userId     = (int) $_SESSION['ss_user_id'];
$documentId = (int) ($_GET['id'] ?? 0);

try {
    $conn = connectToDatabase();
    // GH #217: nothing from a module switched off for the install.
    requireModuleOnJson($conn, 'knowledge');
    // A requester marked as left keeps nothing, even with a portal tab still
    // open - the same rule the portal's pages apply (self-service/includes/auth.php).
    if (!requesterIsActive($conn, $userId)) {
        http_response_code(401);
        exit('Not authenticated');
    }
    $doc  = helpCentreDocumentForPortalUser($conn, $userId, $documentId);
    if (!$doc) {
        http_response_code(404);
        exit('Document not found');
    }

    // A DMS entry is a link out, not a file we hold - the same answer the
    // analyst-side route and the ticket-note route give.
    if ($doc['kind'] === DOCUMENT_KIND_LINK) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'kind' => 'link', 'external_url' => $doc['external_url']]);
        exit;
    }

    // The documents store (a server folder or S3, System -> Storage). It refuses a key
    // that could leave it and, on a server folder, a file that resolves outside it.
    require_once __DIR__ . '/../../includes/storage/stores.php';
    $key = (string) $doc['storage_key'];
    try {
        $size = $key === '' ? null : storeSize($conn, 'documents', $key);
    } catch (StorageUnavailable $e) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Storage cannot be reached right now. Try again in a few minutes.\n");
    }
    if ($size === null) {
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        exit("That file is recorded but missing from storage.\n");
    }

    // The shared serving rules decide inline-versus-download (an HTML or SVG
    // shown inline on our own origin would run as us) and clean the file name.
    $name = (string) ($doc['original_name'] ?: $doc['title']);
    $code = storeServe($conn, 'documents', $key, 0, max(0, $size - 1), function () use ($name, $size) {
        attachmentSendHeaders($name, $size);
        header('Cache-Control: private, no-store');
    });
    if ($code !== 0) {
        http_response_code($code === 503 ? 503 : 410);
        header('Content-Type: text/plain; charset=utf-8');
        exit($code === 503 ? "Storage cannot be reached right now.\n" : "That file is recorded but missing from storage.\n");
    }


} catch (Exception $e) {
    http_response_code(500);
    exit('Could not retrieve the document');
}
