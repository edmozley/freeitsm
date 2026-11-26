<?php
/**
 * FreeITSM REST API v1 - a file sent in pieces (GH #416, #417).
 *
 *   POST   /uploads                          start         the purpose's create permission
 *   PUT    /uploads/{upload_id}?offset=N     one piece     (raw bytes, in order)
 *   GET    /uploads/{upload_id}              how much has arrived - to resume
 *   DELETE /uploads/{upload_id}              give up
 *   GET    /uploads/limits                   every purpose's size and type limits
 *
 * A finished upload is handed to the record that wants it with upload_id:
 * POST /knowledge/articles/{id}/images and POST /knowledge/articles/{id}/documents.
 *
 * 🔑 NO uploads.* PERMISSION (Ed, 21 Nov 2026): an upload needs its PURPOSE's create
 * permission - knowledge_images.create for a picture, knowledge_documents.create for a
 * document - so a key that may add a picture may upload one, and nobody has another
 * box to tick. The routes therefore carry no permission of their own; each handler
 * checks the purpose's, written out literally so the OpenAPI document lists it
 * (x-permissions-also), and the purpose's module.
 *
 * @wiki API-Uploads-and-Knowledge-Documents-Design
 */

require_once dirname(__DIR__, 3) . '/includes/services/api_uploads.php';
require_once dirname(__DIR__, 3) . '/includes/services/knowledge_transfer.php';

/** POST /uploads - {purpose, filename, size, sha256?}. 201 with the upload_id and chunk_bytes. */
function apiUploadsStart(PDO $conn, array $apiKey, array $params, array $body): void {
    $unknown = array_diff(array_keys($body), ['purpose', 'filename', 'size', 'sha256']);
    if ($unknown) apiError(422, 'unknown_field', 'Unsupported fields: ' . implode(', ', $unknown) . '. Send purpose, filename, size and optionally sha256.');
    $purpose = $body['purpose'] ?? null;
    if ($purpose === 'knowledge_image') {
        apiRequirePermission($apiKey, 'knowledge_images', 'create');
        apiV1RequireModulesEnabled($conn, 'knowledge_images', '/uploads');
    } elseif ($purpose === 'knowledge_document') {
        apiRequirePermission($apiKey, 'knowledge_documents', 'create');
        apiV1RequireModulesEnabled($conn, 'knowledge_documents', '/uploads');
    } else {
        apiError(422, 'invalid_purpose', 'purpose must be one of: ' . implode(', ', ApiUploadService::PURPOSES) . '. GET /uploads/limits lists them.');
    }
    try {
        // The key's analyst must be able to change Knowledge at all - refused now, not after 50 MB.
        KnowledgeTransferService::requireActor($conn, ActorContext::fromApiKey($apiKey), true);
        $row = ApiUploadService::start($conn, (int)$apiKey['id'], $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(ApiUploadService::describe($row), 201);
}

/**
 * PUT /uploads/{upload_id}?offset=N - the next piece as the raw request body
 * (application/octet-stream; not multipart, not base64). The router does not read
 * this body: it is streamed to disk here (API_V1_RAW_BODY_HANDLERS in index.php).
 */
function apiUploadsPut(PDO $conn, array $apiKey, array $params, array $body): void {
    $uploadId = (string)$params[0];
    $offset = $_GET['offset'] ?? null;
    if (!is_string($offset) || !ctype_digit($offset)) apiError(400, 'invalid_parameter', 'offset is required: the number of bytes already received (GET /uploads/{upload_id} says how many).');
    $length = isset($_SERVER['CONTENT_LENGTH']) && ctype_digit((string)$_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : null;
    try {
        $row = ApiUploadService::load($conn, (int)$apiKey['id'], $uploadId);
        if ($row['purpose'] === 'knowledge_image') apiRequirePermission($apiKey, 'knowledge_images', 'create');
        else apiRequirePermission($apiKey, 'knowledge_documents', 'create');
        $in = fopen('php://input', 'rb');
        if ($in === false) apiError(400, 'invalid_body', 'The request body could not be read.');
        try {
            $row = ApiUploadService::append($conn, (int)$apiKey['id'], $uploadId, (int)$offset, $in, $length);
        } finally {
            fclose($in);
        }
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(ApiUploadService::describe($row));
}

/** GET /uploads/{upload_id} - received, size, complete, expires_at; and what it became, once used. */
function apiUploadsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $row = ApiUploadService::load($conn, (int)$apiKey['id'], (string)$params[0]);
    } catch (ServiceError $e) { apiFailFromService($e); }
    if ($row['purpose'] === 'knowledge_image') apiRequirePermission($apiKey, 'knowledge_images', 'create');
    else apiRequirePermission($apiKey, 'knowledge_documents', 'create');
    apiRespond(ApiUploadService::describe($row));
}

/** DELETE /uploads/{upload_id} - throws the pieces away (or the receipt of a used upload). */
function apiUploadsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $row = ApiUploadService::load($conn, (int)$apiKey['id'], (string)$params[0]);
        if ($row['purpose'] === 'knowledge_image') apiRequirePermission($apiKey, 'knowledge_images', 'create');
        else apiRequirePermission($apiKey, 'knowledge_documents', 'create');
        ApiUploadService::discard($conn, (int)$apiKey['id'], (string)$params[0]);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(['upload_id' => (string)$params[0], 'deleted' => true]);
}

/** GET /uploads/limits - chunk_bytes, the per-key limit, the expiry, and every purpose's max_bytes and formats. */
function apiUploadsLimits(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(ApiUploadService::limits($conn));
}
