<?php
/** Shared folder/access writes and retry primitives for Knowledge integrations. */
require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../knowledge/visibility.php';
require_once __DIR__ . '/../knowledge/audit.php';

class KnowledgeTransferService
{
    /** API keys never enlarge their analyst's module or read-only permissions. */
    public static function requireActor(PDO $conn, ActorContext $ctx, bool $write = true, bool $manage = false): KnowledgeViewer
    {
        if ($ctx->actorId <= 0 || !analystCanAccessModule($conn, $ctx->actorId, 'knowledge')
            || ($write && !analystCanWriteModule($conn, $ctx->actorId, 'knowledge'))) {
            throw new ServiceError('forbidden', 'forbidden', 'Knowledge access with the required write level is needed.');
        }
        $viewer = KnowledgeViewer::forApiKey($conn, ['analyst_id' => $ctx->actorId, 'company_scope' => $ctx->companyScope]);
        if ($manage && !knowledgeViewerHasAdminFloor($conn, $viewer)) {
            throw new ServiceError('forbidden', 'forbidden', 'Knowledge access management permission is required.');
        }
        return $viewer;
    }

    /** Validate IDs without silently turning an invalid value into the shared root. */
    public static function nullableId($value, string $field): ?int
    {
        if ($value === null) return null;
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false || is_bool($value)) {
            throw new ServiceError('validation', 'invalid_field', "'$field' must be a positive integer or null.");
        }
        return (int)$value;
    }

    public static function object(PDO $conn, ActorContext $ctx, string $type, int $id, bool $write = false): array
    {
        $viewer = self::requireActor($conn, $ctx, $write);
        $table = self::table($type);
        $st = $conn->prepare("SELECT * FROM $table WHERE id = ?" . ($conn->inTransaction() ? ' FOR UPDATE' : ''));
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $allowed = $row && ($row['tenant_id'] === null || $ctx->companyScope === null
            || in_array((int)$row['tenant_id'], array_map('intval', $ctx->companyScope), true));
        $allowed = $allowed && ($type === 'article'
            ? knowledgeCanRead($conn, $viewer, $id, ['lifecycle' => 'any'])
            : knowledgeFolderIsReadable($conn, $viewer, $id));
        if (!$allowed) throw new ServiceError('not_found', 'not_found', 'Knowledge object not found.');
        return $row;
    }

    private static function table(string $type): string
    {
        if (!in_array($type, ['article', 'folder'], true)) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown Knowledge object type.');
        }
        return $type === 'article' ? 'knowledge_articles' : 'knowledge_folders';
    }

    /** Own a transaction or join the caller; no external effects belong here. */
    public static function transaction(PDO $conn, callable $write)
    {
        $owns = !$conn->inTransaction();
        if ($owns) $conn->beginTransaction();
        try {
            $result = $write();
            if ($owns) $conn->commit();
            return $result;
        } catch (Throwable $e) {
            if ($owns && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** Return explicit rules, not a flattened approximation of inherited access. */
    public static function access(PDO $conn, string $type, int $id): array
    {
        $table = self::table($type);
        $st = $conn->prepare("SELECT is_restricted, inherit_permissions FROM $table WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Knowledge object not found.');
        $st = $conn->prepare('SELECT principal_type, principal_id FROM knowledge_acl WHERE object_type = ? AND object_id = ? ORDER BY principal_type, principal_id');
        $st->execute([$type, $id]);
        $entries = array_map(static function ($entry) {
            return ['principal_type' => $entry['principal_type'], 'principal_id' => (int)$entry['principal_id']];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        return ['is_restricted' => (bool)$row['is_restricted'], 'inherits' => (bool)$row['inherit_permissions'], 'entries' => $entries];
    }

    /** Atomic replacement used by both permission UI edits and REST saves. */
    public static function setAccess(PDO $conn, ActorContext $ctx, string $type, int $id, array $access): void
    {
        self::requireActor($conn, $ctx, true, true);
        self::object($conn, $ctx, $type, $id, true);
        if (array_diff(array_keys($access), ['is_restricted', 'inherits', 'entries'])
            || !isset($access['is_restricted'], $access['inherits'], $access['entries'])
            || !is_bool($access['is_restricted']) || !is_bool($access['inherits'])
            || !is_array($access['entries']) || !array_is_list($access['entries'])) {
            throw new ServiceError('validation', 'invalid_access', 'Supply boolean is_restricted/inherits and an entries array.');
        }
        $tables = ['analyst' => 'analysts', 'team' => 'teams', 'user' => 'users', 'user_group' => 'knowledge_user_groups'];
        $entries = [];
        foreach ($access['entries'] as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), ['principal_type', 'principal_id'])
                || !is_string($entry['principal_type'] ?? null) || !isset($tables[$entry['principal_type']])) {
                throw new ServiceError('validation', 'invalid_access', 'Unknown access-list principal type.');
            }
            $principal = self::nullableId($entry['principal_id'] ?? null, 'principal_id');
            $st = $conn->prepare('SELECT id FROM ' . $tables[$entry['principal_type']] . ' WHERE id = ?');
            $st->execute([$principal]);
            if (!$principal || !$st->fetchColumn()) {
                throw new ServiceError('validation', 'invalid_access', 'An access-list principal does not exist. Map source permissions before saving.');
            }
            $entries[$entry['principal_type'] . ':' . $principal] = [$entry['principal_type'], $principal];
        }
        self::transaction($conn, static function () use ($conn, $ctx, $type, $id, $access, $entries) {
            $table = self::table($type);
            self::object($conn, $ctx, $type, $id, true);
            $conn->prepare("UPDATE $table SET is_restricted = ?, inherit_permissions = ? WHERE id = ?")
                ->execute([(int)$access['is_restricted'], (int)$access['inherits'], $id]);
            $st = $conn->prepare('SELECT id, principal_type, principal_id FROM knowledge_acl WHERE object_type = ? AND object_id = ?');
            $st->execute([$type, $id]);
            // Keep unchanged row IDs and their original authors. The UI uses
            // those IDs when removing an entry and another dialog may be open.
            $pending = $entries;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $existing) {
                $key = $existing['principal_type'] . ':' . $existing['principal_id'];
                if (isset($pending[$key])) {
                    unset($pending[$key]);
                } else {
                    $conn->prepare('DELETE FROM knowledge_acl WHERE id = ?')->execute([(int)$existing['id']]);
                }
            }
            $st = $conn->prepare('INSERT INTO knowledge_acl (object_type, object_id, principal_type, principal_id, created_by_id, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())');
            foreach ($pending as [$principalType, $principalId]) $st->execute([$type, $id, $principalType, $principalId, $ctx->actorId]);
            knowledgeAuditLog($conn, $type, $id, 'permissions', $ctx->actorId, ['replaced' => count($entries)]);
        });
        knowledgeAclResetCaches();
    }

    /** UI's create operation, with optional atomic restrictions for integrations. */
    public static function createFolder(PDO $conn, ActorContext $ctx, array $in): array
    {
        self::requireActor($conn, $ctx);
        if (!is_string($in['name'] ?? null)) throw new ServiceError('validation', 'invalid_field', 'A folder name is required.');
        $name = trim($in['name']);
        if ($name === '' || mb_strlen($name) > 255) throw new ServiceError('validation', 'invalid_field', 'A folder name of 1 to 255 characters is required.');
        $parent = self::nullableId($in['parent_id'] ?? null, 'parent_id');
        if ($parent !== null) self::object($conn, $ctx, 'folder', $parent);
        $tenant = self::nullableId($in['tenant_id'] ?? null, 'tenant_id');
        if ($tenant !== null && (!getTenantById($conn, $tenant) || ($ctx->companyScope !== null && !in_array($tenant, $ctx->companyScope, true)))) {
            throw new ServiceError('forbidden', 'forbidden', 'The requested company is not accessible.');
        }
        return self::transaction($conn, static function () use ($conn, $ctx, $in, $name, $parent, $tenant) {
            if ($parent !== null) self::object($conn, $ctx, 'folder', $parent);
            $conn->prepare('INSERT INTO knowledge_folders (parent_id, name, tenant_id, owner_id, created_by_id, created_datetime, modified_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute([$parent, $name, $tenant, $ctx->actorId, $ctx->actorId]);
            $id = (int)$conn->lastInsertId();
            knowledgeAclResetCaches();
            if (array_key_exists('access', $in)) self::setAccess($conn, $ctx, 'folder', $id, $in['access']);
            knowledgeAuditLog($conn, 'folder', $id, 'create', $ctx->actorId, ['name' => $name, 'parent_id' => $parent]);
            return ['id' => $id];
        });
    }

    /** Content token detects same-second UI edits as well as ACL/tag/image edits. */
    public static function revision(PDO $conn, string $type, int $id): string
    {
        $table = self::table($type);
        $st = $conn->prepare("SELECT * FROM $table WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Knowledge object not found.');
        // Reader statistics and asynchronous embeddings do not constitute an edit.
        unset($row['view_count'], $row['embedding'], $row['embedding_updated']);
        $row['access'] = self::access($conn, $type, $id);
        if ($type === 'article') {
            $st = $conn->prepare('SELECT tag_id FROM knowledge_article_tags WHERE article_id = ? ORDER BY tag_id');
            $st->execute([$id]);
            $row['tags'] = $st->fetchAll(PDO::FETCH_COLUMN);
            $st = $conn->prepare('SELECT id, sha256 FROM knowledge_images WHERE article_id = ? ORDER BY id');
            $st->execute([$id]);
            $row['images'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public static function checkRevision(PDO $conn, string $type, int $id, $expected): void
    {
        if (!is_string($expected) || !hash_equals(self::revision($conn, $type, $id), $expected)) {
            throw new ServiceError('conflict', 'revision_conflict', 'The object has changed. Read it again and reconcile your changes before retrying.');
        }
    }

    /**
     * Serialize retries across keys belonging to the same analyst. References
     * survive target deletion as tombstones: an old retry must not resurrect it.
     * Authorization is checked by the caller again when resolving the result.
     */
    public static function createOnce(PDO $conn, ActorContext $ctx, string $operation, int $parent, array $in, callable $write): array
    {
        $ref = $in['client_reference'] ?? null;
        if ($ref !== null && (!is_string($ref) || trim($ref) === '' || trim($ref) !== $ref || strlen($ref) > 191 || preg_match('/[\x00-\x1f\x7f]/', $ref))) {
            throw new ServiceError('validation', 'invalid_reference', 'client_reference must have 1 to 191 bytes, without control characters or surrounding whitespace.');
        }
        $hash = hash('sha256', json_encode(self::canonical($in), JSON_THROW_ON_ERROR));
        $lock = null;
        if ($ref !== null) {
            $db = $conn->query('SELECT DATABASE()')->fetchColumn();
            $lock = 'kb:' . substr(hash('sha256', "$db:$ctx->actorId:$operation:$parent:$ref"), 0, 60);
            $st = $conn->prepare('SELECT GET_LOCK(?, 10)');
            $st->execute([$lock]);
            if ((int)$st->fetchColumn() !== 1) throw new ServiceError('conflict', 'reference_busy', 'This reference is being written. Retry the same request.');
        }
        try {
            return self::transaction($conn, static function () use ($conn, $ctx, $operation, $parent, $ref, $hash, $write) {
                if ($ref !== null) {
                    $st = $conn->prepare('SELECT object_id, request_hash FROM knowledge_api_references WHERE analyst_id = ? AND operation = ? AND parent_id = ? AND client_reference = ? FOR UPDATE');
                    $st->execute([$ctx->actorId, $operation, $parent, $ref]);
                    if ($prior = $st->fetch(PDO::FETCH_ASSOC)) {
                        if (!hash_equals($prior['request_hash'], $hash)) throw new ServiceError('conflict', 'reference_conflict', 'This client_reference was used with different content. Use a new reference for a different operation.');
                        return ['id' => (int)$prior['object_id'], 'replayed' => true];
                    }
                }
                $result = $write();
                if ($ref !== null) {
                    $conn->prepare('INSERT INTO knowledge_api_references (analyst_id, operation, parent_id, client_reference, request_hash, object_id) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$ctx->actorId, $operation, $parent, $ref, $hash, $result['id']]);
                }
                return $result + ['replayed' => false];
            });
        } finally {
            if ($lock !== null) $conn->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }

    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item = self::canonical($item);
        return $value;
    }
}
