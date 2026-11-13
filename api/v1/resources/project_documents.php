<?php
/**
 * FreeITSM REST API v1 - a project's documents (GH #287).
 *
 *   GET    /projects/{id}/documents                          projects.read
 *   POST   /projects/{id}/documents                          projects.update   (a file, or a link)
 *   POST   /projects/{id}/documents/{document_id}/link       projects.update   (attach one that exists)
 *   GET    /projects/{id}/documents/{document_id}/content    projects.read + documents.download
 *   DELETE /projects/{id}/documents/{document_id}            projects.update   (the link, not the document)
 *
 * The rules are DocumentsService's - the same as the Documents panel in the app,
 * acting as the key's analyst with that analyst's modules. A project the key
 * cannot reach is 404 (apiLoadProject); so is a document that is not on THAT
 * project - a document is never served by id alone.
 *
 * 🔴 Downloads go through the download guard (includes/api_security.php) BEFORE
 * the file is read, are logged with the key and the bytes, and need their own
 * permission, documents.download, which no existing key has.
 *
 * @wiki Project-Documents-API-Design
 */

require_once dirname(__DIR__, 3) . '/includes/services/documents.php';
require_once dirname(__DIR__, 3) . '/includes/api_security.php';

/** The modules the key's analyst may use now (null = all), as the app works them out. */
function apiDocAllowedModules(PDO $conn, array $apiKey): ?array {
    return modulesWithoutSwitchedOff($conn, getAnalystAllowedModules($conn, (int)$apiKey['analyst_id']));
}

/**
 * A document for the API.
 *
 * @param array $r  a row from DocumentsService::listFor() (or the same columns)
 * @param int $projectId  the project it is listed under, for content_url
 */
function apiSerializeProjectDocument(array $r, int $projectId): array {
    return [
        'id'               => (int)$r['id'],
        'kind'             => $r['kind'],
        'title'            => $r['title'],
        'description'      => $r['description'],
        'original_name'    => $r['original_name'],
        'mime_type'        => $r['mime_type'],
        'size_bytes'       => $r['size_bytes'] === null ? null : (int)$r['size_bytes'],
        'sha256'           => $r['content_hash'],
        'external_url'     => $r['external_url'],
        'client_reference' => $r['client_reference'] ?? null,
        'uploaded_by'      => $r['uploaded_by_id'] === null ? null : ['id' => (int)$r['uploaded_by_id'], 'name' => $r['uploaded_by_name']],
        'uploaded_at'      => apiIsoDate($r['created_datetime']),
        'content_url'      => '/api/v1/projects/' . $projectId . '/documents/' . (int)$r['id'] . '/content',
    ];
}

/** One document on a project, as the API shows it; 404 when it is not on that project. */
function apiProjectDocumentRow(PDO $conn, int $projectId, int $documentId): array {
    if (!DocumentsService::isOn($conn, $documentId, 'project', $projectId)) apiError(404, 'not_found', 'Document not found on this project.');
    $refCol = DocumentsService::referenceReady($conn) ? 'd.client_reference' : 'NULL AS client_reference';
    $st = $conn->prepare("SELECT d.*, a.full_name AS uploaded_by_name, $refCol FROM documents d LEFT JOIN analysts a ON a.id = d.uploaded_by_id WHERE d.id = ?");
    $st->execute([$documentId]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

/** GET /projects/{id}/documents - metadata only, newest first, paged; ?client_reference= finds a retry. */
function apiProjectDocumentsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $pid = (int)$params[0];
    apiLoadProject($conn, $apiKey, $pid);
    [$page, $perPage, $offset] = apiPagination();
    $ref = isset($_GET['client_reference']) && is_string($_GET['client_reference']) ? $_GET['client_reference'] : null;
    try {
        $res = DocumentsService::listFor($conn, (int)$apiKey['analyst_id'], apiDocAllowedModules($conn, $apiKey), 'project', $pid, $offset, $perPage, $ref);
    } catch (ServiceError $e) {
        apiError(404, 'not_found', 'Project not found.');   // the key reaches the project but its analyst cannot see documents there
    }
    apiRespond(array_map(fn($r) => apiSerializeProjectDocument($r, $pid), $res['rows']), 200,
        ['page' => $page, 'per_page' => $perPage, 'total' => $res['total'], 'total_pages' => (int)ceil($res['total'] / max(1, $perPage))]);
}

/**
 * POST /projects/{id}/documents - multipart `file` (+ title, description, client_reference),
 * or JSON {external_url, title, description, client_reference} for a link.
 * 201 new; 200 a retry with the same reference and the same content; 409 a reference naming something else.
 */
function apiProjectDocumentsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $pid = (int)$params[0];
    $project = apiLoadProject($conn, $apiKey, $pid);
    $isFile = isset($_FILES['file']);
    $in = $isFile ? $_POST : $body;
    foreach (['title', 'description', 'external_url', 'client_reference'] as $f) {
        if (isset($in[$f]) && !is_string($in[$f])) apiError(422, 'invalid_field', "$f must be a string.");
    }
    if (!$isFile && empty($in['external_url'])) apiError(422, 'missing_file', 'Send a multipart/form-data file part named file, or JSON with external_url for a link.');
    $tenant = $conn->prepare("SELECT tenant_id FROM projects WHERE id = ?");
    $tenant->execute([$pid]);
    $tenantId = $tenant->fetchColumn();
    try {
        $result = DocumentsService::add($conn, (int)$apiKey['analyst_id'], apiDocAllowedModules($conn, $apiKey), 'project', $pid, [
            'file'             => $isFile ? $_FILES['file'] : null,
            'external_url'     => $isFile ? '' : (string)($in['external_url'] ?? ''),
            'title'            => (string)($in['title'] ?? ''),
            'description'      => (string)($in['description'] ?? ''),
            'client_reference' => $in['client_reference'] ?? null,
            'tenant_id'        => $tenantId === false || $tenantId === null ? null : (int)$tenantId,   // the project's company
        ]);
    } catch (ServiceError $e) {
        if ($e->kind === 'forbidden') apiError(403, 'forbidden', 'The key\'s analyst may not add documents to this project.');
        apiFailFromService($e);
    }
    apiRespond(apiSerializeProjectDocument(apiProjectDocumentRow($conn, $pid, $result['id']), $pid), $result['replayed'] ? 200 : 201);
}

/** POST /projects/{id}/documents/{document_id}/link - attach a document the key's analyst can already see. */
function apiProjectDocumentsLink(PDO $conn, array $apiKey, array $params, array $body): void {
    [$pid, $did] = [(int)$params[0], (int)$params[1]];
    apiLoadProject($conn, $apiKey, $pid);
    $already = DocumentsService::isOn($conn, $did, 'project', $pid);
    try {
        DocumentsService::attach($conn, (int)$apiKey['analyst_id'], apiDocAllowedModules($conn, $apiKey), 'project', $pid, $did);
    } catch (ServiceError $e) {
        if ($e->kind === 'forbidden') apiError(403, 'forbidden', 'The key\'s analyst may not add documents to this project.');
        apiError(404, 'not_found', 'Document not found.');
    }
    apiRespond(apiSerializeProjectDocument(apiProjectDocumentRow($conn, $pid, $did), $pid), $already ? 200 : 201);
}

/**
 * GET /projects/{id}/documents/{document_id}/content - the file, or a link's URL as JSON
 * (never a redirect). Needs documents.download as well as projects.read; through the
 * download guard, logged with the key and the bytes.
 */
function apiProjectDocumentContent(PDO $conn, array $apiKey, array $params, array $body): void {
    [$pid, $did] = [(int)$params[0], (int)$params[1]];
    apiLoadProject($conn, $apiKey, $pid);
    apiRequirePermission($apiKey, 'documents', 'download');
    $doc = apiProjectDocumentRow($conn, $pid, $did);
    if (!documentCanView($conn, (int)$apiKey['analyst_id'], apiDocAllowedModules($conn, $apiKey), $did)) apiError(404, 'not_found', 'Document not found on this project.');

    if ($doc['kind'] === DOCUMENT_KIND_FILES) {   // GH #303: lives in Files, under Files' own permissions
        apiError(409, 'in_files', 'This document is a link to a file in Files; it is not served through the API.');
    }
    if ($doc['kind'] === DOCUMENT_KIND_LINK) {
        apiDownloadRecord($conn, $apiKey, $did, 'follow', 0);
        apiRespond(['kind' => 'link', 'external_url' => $doc['external_url']]);
    }
    $path = documentStoragePath((string)$doc['storage_key']);
    if (!is_file($path)) apiError(410, 'gone', 'That file is recorded but missing from storage.');
    $size = (int)filesize($path);
    // 🔴 The guard BEFORE a byte is read: refuses (429), or switches the key off, at the limit.
    apiDownloadGuard($conn, $apiKey, $size, $did);
    apiDownloadRecord($conn, $apiKey, $did, 'download', $size);

    $name = str_replace(['"', "\r", "\n"], '', (string)($doc['original_name'] ?: $doc['title'])) ?: 'document';
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . $size);
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

/** DELETE /projects/{id}/documents/{document_id} - the link; the last link soft-deletes the document. */
function apiProjectDocumentsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    [$pid, $did] = [(int)$params[0], (int)$params[1]];
    apiLoadProject($conn, $apiKey, $pid);
    if (!DocumentsService::isOn($conn, $did, 'project', $pid)) apiError(404, 'not_found', 'Document not found on this project.');
    try {
        $orphaned = DocumentsService::detach($conn, (int)$apiKey['analyst_id'], apiDocAllowedModules($conn, $apiKey), 'project', $pid, $did);
    } catch (ServiceError $e) {
        if ($e->kind === 'forbidden') apiError(403, 'forbidden', 'The key\'s analyst may not remove documents from this project.');
        apiError(404, 'not_found', 'Document not found on this project.');
    }
    apiRespond(['id' => $did, 'removed' => true, 'document_deleted' => $orphaned]);
}
