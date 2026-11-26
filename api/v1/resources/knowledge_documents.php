<?php
/**
 * FreeITSM REST API v1 - the documents on a Knowledge article (GH #416).
 *
 *   GET    /knowledge/articles/{id}/documents                         knowledge_documents.read
 *   POST   /knowledge/articles/{id}/documents                         knowledge_documents.create  (a file, or a link)
 *   POST   /knowledge/articles/{id}/documents/{document_id}/link     knowledge_documents.create  (attach one that exists)
 *   PATCH  /knowledge/articles/{id}/documents/{document_id}          knowledge_documents.create
 *   GET    /knowledge/articles/{id}/documents/{document_id}/content  knowledge_documents.read + documents.download
 *   DELETE /knowledge/articles/{id}/documents/{document_id}          knowledge_documents.delete  (this article's link)
 *
 * The rules are KnowledgeDocumentService's: the article as the key may reach it, and
 * DocumentsService's checks and writes - the same as the Documents panel in the app.
 * An article the key cannot reach is 404; so is a document that is not on THAT article.
 *
 * 🔴 Downloads go through the download guard (includes/api_security.php) BEFORE the file
 * is read, are logged with the key and the bytes, and need documents.download, which no
 * key has unless an administrator ticks it - exactly as project documents (GH #287).
 *
 * PATCH and the Help Centre setting sit under knowledge_documents.create: showing a
 * document to customers widens who can read it, the same act as attaching it
 * (api/documents/help_centre.php says the same for the app).
 *
 * @wiki API-Uploads-and-Knowledge-Documents-Design
 */

require_once dirname(__DIR__, 3) . '/includes/services/knowledge_documents.php';
require_once dirname(__DIR__, 3) . '/includes/api_security.php';

/**
 * A document on an article, for the API.
 *
 * @param array $r  a row from KnowledgeDocumentService::rows()
 * @param int $articleId  the article it is listed under
 */
function apiSerializeKnowledgeDocument(array $r, int $articleId): array {
    $id = (int)$r['id'];
    return [
        'id'                  => $id,
        'article_id'          => $articleId,
        'kind'                => $r['kind'],
        'title'               => $r['title'],
        'description'         => $r['description'],
        'filename'            => $r['original_name'],
        'mime_type'           => $r['mime_type'],
        'size_bytes'          => $r['size_bytes'] === null ? null : (int)$r['size_bytes'],
        'sha256'              => $r['content_hash'],
        'external_url'        => $r['external_url'],
        'show_in_help_centre' => (int)$r['show_in_help_centre'] === 1,
        'client_reference'    => $r['client_reference'],
        'uploaded_by'         => $r['uploaded_by_id'] === null ? null : ['id' => (int)$r['uploaded_by_id'], 'name' => $r['uploaded_by_name']],
        'uploaded_at'         => apiIsoDate($r['created_datetime']),
        // For the article's HTML: re-checks the signed-in analyst or portal user against this article.
        'url'                 => KnowledgeDocumentService::sessionUrl($articleId, $id, (string)$r['kind']),
        'content_url'         => '/api/v1/knowledge/articles/' . $articleId . '/documents/' . $id . '/content',
    ];
}

/** One document on the article as the API shows it, with the article's revision now. */
function apiKnowledgeDocumentOut(PDO $conn, ActorContext $ctx, int $articleId, int $documentId): array {
    $res = KnowledgeDocumentService::rows($conn, $ctx, $articleId, 0, 1, null, $documentId);
    return apiSerializeKnowledgeDocument($res['rows'][0], $articleId)
        + ['article_revision' => KnowledgeTransferService::revision($conn, 'article', $articleId)];
}

/**
 * The fields a document write accepts, type-checked. JSON sends real booleans; a
 * multipart form sends text, so there show_in_help_centre may be true/false/1/0.
 *
 * @param array $in  the JSON body, or $_POST for a multipart upload
 * @param string[] $allowed  the fields this operation takes
 * @param bool $form  true for a multipart form
 */
function apiKnowledgeDocumentFields(array $in, array $allowed, bool $form = false): array {
    $unknown = array_diff(array_keys($in), $allowed);
    if ($unknown) apiError(422, 'unknown_field', 'Unsupported fields: ' . implode(', ', $unknown) . '. Check the documented request fields.');
    foreach (['title', 'external_url', 'upload_id', 'client_reference', 'expected_revision'] as $f) {
        if (array_key_exists($f, $in) && !is_string($in[$f])) apiError(422, 'invalid_field', "$f must be a string.");
    }
    if (array_key_exists('description', $in) && $in['description'] !== null && !is_string($in['description'])) {
        apiError(422, 'invalid_field', 'description must be a string or null.');
    }
    if (array_key_exists('show_in_help_centre', $in)) {
        $v = $in['show_in_help_centre'];
        if ($form && is_string($v) && in_array(strtolower($v), ['true', 'false', '1', '0'], true)) {
            $in['show_in_help_centre'] = in_array(strtolower($v), ['true', '1'], true);
        } elseif (!is_bool($v)) {
            apiError(422, 'invalid_field', 'show_in_help_centre must be a boolean' . ($form ? ' (true, false, 1 or 0 in a form)' : '') . '.');
        }
    }
    return $in;
}

/** GET /knowledge/articles/{id}/documents - metadata only, newest first, paged; ?client_reference= finds a retry. */
function apiKnowledgeDocumentsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $articleId = (int)$params[0];
    [$page, $perPage, $offset] = apiPagination();
    $ref = $_GET['client_reference'] ?? null;
    if ($ref !== null && (!is_string($ref) || $ref === '')) apiError(400, 'invalid_parameter', 'client_reference must be a non-empty string.');
    try {
        $res = KnowledgeDocumentService::rows($conn, ActorContext::fromApiKey($apiKey), $articleId, $offset, $perPage, $ref);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(array_map(fn($r) => apiSerializeKnowledgeDocument($r, $articleId), $res['rows']), 200,
        ['page' => $page, 'per_page' => $perPage, 'total' => $res['total'], 'total_pages' => (int)ceil($res['total'] / max(1, $perPage))]);
}

/**
 * POST /knowledge/articles/{id}/documents - multipart `file` (+ title, description,
 * client_reference, show_in_help_centre, expected_revision), JSON with upload_id for a
 * file sent in pieces (POST /uploads, GH #417), or JSON with external_url for a link.
 * 201 new; 200 a retry with the same client_reference and the same request; 409 a
 * reference used for something else.
 */
function apiKnowledgeDocumentsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $articleId = (int)$params[0];
    $isFile = isset($_FILES['file']);
    $fields = ['title', 'description', 'external_url', 'upload_id', 'client_reference', 'show_in_help_centre', 'expected_revision'];
    $in = apiKnowledgeDocumentFields($isFile ? $_POST : $body, $fields, $isFile);
    if (!$isFile && empty($in['external_url']) && empty($in['upload_id'])) {
        apiError(422, 'missing_file', 'Send a multipart/form-data file part named file, JSON with upload_id for a file sent in pieces, or JSON with external_url for a link.');
    }
    if (array_key_exists('description', $in) && $in['description'] === null) unset($in['description']);
    $ctx = ActorContext::fromApiKey($apiKey);
    try {
        $upload = null;
        if (isset($in['upload_id'])) {
            if ($isFile) apiError(422, 'invalid_field', 'Give a file or an upload_id, not both.');
            // Access to the article first - an upload id never gets round it.
            KnowledgeDocumentService::article($conn, $ctx, $articleId, true);
            $upload = ApiUploadService::forHandOver($conn, (int)$apiKey['id'], $in['upload_id'], 'knowledge_document');
        }
        unset($in['upload_id']);
        $result = KnowledgeDocumentService::create($conn, $ctx, $articleId, $in, $isFile ? $_FILES['file'] : null, $upload);
        $out = apiKnowledgeDocumentOut($conn, $ctx, $articleId, $result['id']) + ['replayed' => $result['replayed']];
    } catch (ServiceError $e) { apiFailFromService($e); }
    if ($result['replayed']) apiRespond($out, 200);
    apiRespond($out, 201);
}

/**
 * POST /knowledge/articles/{id}/documents/{document_id}/link - attach a document the key's
 * analyst can already see. 201 attached now; 200 it was already on the article (unchanged).
 */
function apiKnowledgeDocumentsLink(PDO $conn, array $apiKey, array $params, array $body): void {
    [$articleId, $documentId] = [(int)$params[0], (int)$params[1]];
    $in = apiKnowledgeDocumentFields($body, ['show_in_help_centre', 'expected_revision']);
    $ctx = ActorContext::fromApiKey($apiKey);
    try {
        $allowed = modulesWithoutSwitchedOff($conn, getAnalystAllowedModules($conn, (int)$apiKey['analyst_id']));
        $made = KnowledgeDocumentService::attach($conn, $ctx, $allowed, $articleId, $documentId, $in);
        $out = apiKnowledgeDocumentOut($conn, $ctx, $articleId, $documentId);
    } catch (ServiceError $e) { apiFailFromService($e); }
    // Two calls, not a ternary: the OpenAPI generator reads the success codes from them.
    if ($made) apiRespond($out, 201);
    apiRespond($out);
}

/** PATCH /knowledge/articles/{id}/documents/{document_id} - title, description, show_in_help_centre. */
function apiKnowledgeDocumentsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    [$articleId, $documentId] = [(int)$params[0], (int)$params[1]];
    $in = apiKnowledgeDocumentFields($body, ['title', 'description', 'show_in_help_centre', 'expected_revision']);
    $ctx = ActorContext::fromApiKey($apiKey);
    try {
        $changed = KnowledgeDocumentService::update($conn, $ctx, $articleId, $documentId, $in);
        $out = apiKnowledgeDocumentOut($conn, $ctx, $articleId, $documentId) + ['changed' => $changed];
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond($out);
}

/**
 * GET /knowledge/articles/{id}/documents/{document_id}/content - the file, or a link's URL
 * as JSON (never a redirect). Needs documents.download as well as knowledge_documents.read;
 * through the download guard, logged with the key and the bytes.
 */
function apiKnowledgeDocumentContent(PDO $conn, array $apiKey, array $params, array $body): void {
    [$articleId, $documentId] = [(int)$params[0], (int)$params[1]];
    try {
        $doc = KnowledgeDocumentService::forContent($conn, ActorContext::fromApiKey($apiKey), $articleId, $documentId);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRequirePermission($apiKey, 'documents', 'download');

    if ($doc['kind'] === DOCUMENT_KIND_FILES) {   // GH #303: lives in Files, under Files' own permissions
        apiError(409, 'in_files', 'This document is a link to a file in Files; it is not served through the API.');
    }
    if ($doc['kind'] === DOCUMENT_KIND_LINK) {
        apiDownloadRecord($conn, $apiKey, $documentId, 'follow', 0);
        apiRespond(['kind' => 'link', 'external_url' => $doc['external_url']]);
    }
    $path = documentStoragePath((string)$doc['storage_key']);
    if (!is_file($path)) apiError(410, 'gone', 'That file is recorded but missing from storage.');
    $size = (int)filesize($path);
    // 🔴 The guard BEFORE a byte is read: refuses (429), or switches the key off, at the limit.
    apiDownloadGuard($conn, $apiKey, $size, $documentId);
    apiDownloadRecord($conn, $apiKey, $documentId, 'download', $size);

    $name = str_replace(['"', "\r", "\n"], '', (string)($doc['original_name'] ?: $doc['title'])) ?: 'document';
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . $size);
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

/**
 * DELETE /knowledge/articles/{id}/documents/{document_id}[?expected_revision=] - this
 * article's link; the last link deletes the document, as in the app.
 */
function apiKnowledgeDocumentsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    [$articleId, $documentId] = [(int)$params[0], (int)$params[1]];
    $expected = $_GET['expected_revision'] ?? null;
    if ($expected !== null && (!is_string($expected) || $expected === '')) apiError(400, 'invalid_parameter', 'expected_revision must be the revision string from the article.');
    try {
        $deleted = KnowledgeDocumentService::detach($conn, ActorContext::fromApiKey($apiKey), $articleId, $documentId, $expected);
        $revision = KnowledgeTransferService::revision($conn, 'article', $articleId);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(['id' => $documentId, 'article_id' => $articleId, 'removed' => true, 'document_deleted' => $deleted, 'article_revision' => $revision]);
}
