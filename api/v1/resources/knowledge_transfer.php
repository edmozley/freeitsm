<?php
/** Knowledge migration capabilities, built on the same writes and visibility as the UI. */
require_once dirname(__DIR__, 3) . '/includes/services/knowledge_transfer.php';
require_once dirname(__DIR__, 3) . '/includes/services/knowledge_images.php';

function apiKnowledgeValidateFields(array $body, array $allowed): void
{
    $unknown = array_diff(array_keys($body), $allowed);
    if ($unknown) apiError(422, 'unknown_field', 'Unsupported fields: ' . implode(', ', $unknown) . '. Check the documented request fields.');
}

/** Additional permissions are deliberately narrower than knowledge.update. */
function apiKnowledgeWriteOptions(PDO $conn, array $apiKey, array $body, bool $update): ActorContext
{
    $ctx = ActorContext::fromApiKey($apiKey);
    KnowledgeTransferService::requireActor($conn, $ctx);
    apiKnowledgeValidateFields($body, ['title', 'body_html', 'tags', 'owner_id', 'next_review_date',
        'tenant_id', 'audience', 'folder_id', 'is_published', 'save_as_version', 'access', 'processing', 'client_reference', 'expected_revision', 'required_image_ids']);
    foreach (['title', 'body_html'] as $field) {
        if (array_key_exists($field, $body) && !is_string($body[$field])) apiError(422, 'invalid_field', "$field must be a string.");
    }
    foreach (['is_published', 'save_as_version'] as $field) {
        if (array_key_exists($field, $body) && !is_bool($body[$field])) apiError(422, 'invalid_field', "$field must be a boolean.");
    }
    foreach (['folder_id', 'tenant_id', 'owner_id'] as $field) {
        if (array_key_exists($field, $body)) KnowledgeTransferService::nullableId($body[$field], $field);
    }
    if (array_key_exists('tags', $body) && (!is_array($body['tags']) || !array_is_list($body['tags'])
        || array_filter($body['tags'], static function ($v) { return !is_string($v); }))) {
        apiError(422, 'invalid_field', 'tags must be an array of strings.');
    }
    if (array_key_exists('access', $body)) {
        if (!is_array($body['access'])) apiError(422, 'invalid_access', 'access must be an object.');
        KnowledgeTransferService::requireActor($conn, $ctx, true, true);
    }
    if (array_key_exists('processing', $body)) {
        KnowledgeProcessing::suppresses($body);
    }
    if ($update && (isset($body['processing']) || isset($body['access'])) && !isset($body['expected_revision'])) {
        apiError(422, 'missing_revision', 'Read the article and supply its expected_revision for an import or access update.');
    }
    if ($update && isset($body['client_reference'])) apiError(422, 'invalid_reference', 'client_reference identifies creates. Use expected_revision for updates.');
    if (!$update && isset($body['expected_revision'])) apiError(422, 'invalid_field', 'expected_revision is only valid for updates.');
    return $ctx;
}

function apiKnowledgeFolderData(PDO $conn, ActorContext $ctx, int $id): array
{
    $row = KnowledgeTransferService::object($conn, $ctx, 'folder', $id);
    return ['id' => (int)$row['id'], 'parent_id' => $row['parent_id'] === null ? null : (int)$row['parent_id'],
        'name' => $row['name'], 'tenant_id' => $row['tenant_id'] === null ? null : (int)$row['tenant_id'],
        'revision' => KnowledgeTransferService::revision($conn, 'folder', $id)];
}

function apiKnowledgeFoldersList(PDO $conn, array $apiKey, array $params, array $body): void
{
    try {
        $ctx = ActorContext::fromApiKey($apiKey);
        KnowledgeTransferService::requireActor($conn, $ctx, false);
        $out = [];
        foreach ($conn->query('SELECT id FROM knowledge_folders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try { $out[] = apiKnowledgeFolderData($conn, $ctx, (int)$id); }
            catch (ServiceError $e) { if ($e->kind !== 'not_found') throw $e; }
        }
        $present = array_column($out, 'id');
        foreach ($out as &$folder) {
            if ($folder['parent_id'] !== null && !in_array($folder['parent_id'], $present, true)) {
                $folder['parent_id'] = null;
                $folder['detached'] = true;
            }
        }
        unset($folder);
        apiRespond($out);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

function apiKnowledgeFoldersGet(PDO $conn, array $apiKey, array $params, array $body): void
{
    try { apiRespond(apiKnowledgeFolderData($conn, ActorContext::fromApiKey($apiKey), (int)$params[0])); }
    catch (ServiceError $e) { apiFailFromService($e); }
}

function apiKnowledgeFoldersCreate(PDO $conn, array $apiKey, array $params, array $body): void
{
    try {
        apiKnowledgeValidateFields($body, ['name', 'parent_id', 'tenant_id', 'access', 'client_reference']);
        $ctx = ActorContext::fromApiKey($apiKey);
        KnowledgeTransferService::requireActor($conn, $ctx);
        if (array_key_exists('access', $body)) {
            apiRequirePermission($apiKey, 'knowledge_access', 'manage');
            KnowledgeTransferService::requireActor($conn, $ctx, true, true);
            if (!is_array($body['access'])) apiError(422, 'invalid_access', 'access must be an object.');
        }
        $result = KnowledgeTransferService::createOnce($conn, $ctx, 'folder', 0, $body,
            static function () use ($conn, $ctx, $body) { return KnowledgeTransferService::createFolder($conn, $ctx, $body); });
        $out = apiKnowledgeFolderData($conn, $ctx, $result['id']);
        $out['replayed'] = $result['replayed'];
        if ($result['replayed']) apiRespond($out, 200);
        apiRespond($out, 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

/** /knowledge/{articles|folders}/{id}/access always requires the administrator floor. */
function apiKnowledgeAccessGet(PDO $conn, array $apiKey, array $params, array $body): void
{
    try {
        $ctx = ActorContext::fromApiKey($apiKey);
        KnowledgeTransferService::requireActor($conn, $ctx, false, true);
        $type = $params[0] === 'articles' ? 'article' : 'folder';
        $id = (int)$params[1];
        KnowledgeTransferService::object($conn, $ctx, $type, $id);
        apiRespond(KnowledgeTransferService::access($conn, $type, $id) + ['revision' => KnowledgeTransferService::revision($conn, $type, $id),
            'permission_model' => knowledgeFolderPermissionModel($conn)]);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

function apiKnowledgeAccessUpdate(PDO $conn, array $apiKey, array $params, array $body): void
{
    try {
        apiKnowledgeValidateFields($body, ['access', 'expected_revision']);
        $ctx = ActorContext::fromApiKey($apiKey);
        KnowledgeTransferService::requireActor($conn, $ctx, true, true);
        if (!is_array($body['access'] ?? null)) apiError(422, 'invalid_access', 'access must be an object.');
        if (!isset($body['expected_revision'])) apiError(422, 'missing_revision', 'Read the object and supply expected_revision before changing its access.');
        $type = $params[0] === 'articles' ? 'article' : 'folder';
        $id = (int)$params[1];
        KnowledgeTransferService::transaction($conn, static function () use ($conn, $ctx, $type, $id, $body) {
            KnowledgeTransferService::object($conn, $ctx, $type, $id, true);
            KnowledgeTransferService::checkRevision($conn, $type, $id, $body['expected_revision'] ?? null);
            KnowledgeTransferService::setAccess($conn, $ctx, $type, $id, $body['access']);
        });
        apiRespond(KnowledgeTransferService::access($conn, $type, $id) + ['revision' => KnowledgeTransferService::revision($conn, $type, $id),
            'permission_model' => knowledgeFolderPermissionModel($conn)]);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

function apiKnowledgeImagesList(PDO $conn, array $apiKey, array $params, array $body): void
{
    try {
        KnowledgeTransferService::object($conn, ActorContext::fromApiKey($apiKey), 'article', (int)$params[0]);
        $st = $conn->prepare('SELECT * FROM knowledge_images WHERE article_id = ? ORDER BY id');
        $st->execute([(int)$params[0]]);
        apiRespond(array_map(['KnowledgeImageService', 'describe'], $st->fetchAll(PDO::FETCH_ASSOC)));
    } catch (ServiceError $e) { apiFailFromService($e); }
}

/**
 * GET /knowledge/images/constraints (GH #417): the size limit and formats in force, so a
 * client never hard-codes them.
 */
function apiKnowledgeImagesConstraints(PDO $conn, array $apiKey, array $params, array $body): void
{
    try {
        KnowledgeTransferService::requireActor($conn, ActorContext::fromApiKey($apiKey), false);
        apiRespond(KnowledgeImageService::constraints($conn));
    } catch (ServiceError $e) { apiFailFromService($e); }
}

function apiKnowledgeImagesCreate(PDO $conn, array $apiKey, array $params, array $body): void
{
    try {
        apiKnowledgeValidateFields($body, ['filename', 'content_base64', 'client_reference']);
        $ctx = ActorContext::fromApiKey($apiKey);
        $result = KnowledgeImageService::create($conn, $ctx, (int)$params[0], $body);
        $row = KnowledgeImageService::load($conn, KnowledgeTransferService::requireActor($conn, $ctx, false), $result['id'], 'any');
        KnowledgeImageService::path($row); // An interrupted/removed file is not a successful replay.
        $out = KnowledgeImageService::describe($row) + ['replayed' => $result['replayed']];
        if ($result['replayed']) apiRespond($out, 200);
        apiRespond($out, 201);
    } catch (ServiceError $e) {
        if ($e->errorCode === 'image_too_large') {
            $max = KnowledgeImageService::maxBytes($conn);
            apiError(413, 'image_too_large', $e->getMessage(), ['max_bytes' => $max, 'max_base64_length' => KnowledgeImageService::maxBase64Length($max)]);
        }
        apiFailFromService($e);
    }
}

function apiKnowledgeImagesGet(PDO $conn, array $apiKey, array $params, array $body): void
{
    try {
        $viewer = KnowledgeTransferService::requireActor($conn, ActorContext::fromApiKey($apiKey), false);
        $row = KnowledgeImageService::load($conn, $viewer, (int)$params[0], 'any');
        KnowledgeImageService::serve($row);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

function apiKnowledgeImagesDelete(PDO $conn, array $apiKey, array $params, array $body): void
{
    try {
        $cleaned = KnowledgeImageService::delete($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], (int)$params[1]);
        apiRespond(['id' => (int)$params[1], 'deleted' => true, 'file_removed' => $cleaned]);
    } catch (ServiceError $e) { apiFailFromService($e); }
}
