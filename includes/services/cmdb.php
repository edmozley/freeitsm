<?php
/**
 * CmdbService — the shared write rules for CMDB configuration items: object
 * create/update (with the typed property system), object delete (descendant
 * tree), and relationship create/delete.
 *
 * Shared by the UI endpoints (api/cmdb/*.php) and the REST API
 * (api/v1/resources/cmdb.php). Each caller passes an ActorContext + canonical
 * input; this layer validates + writes and returns the affected id(s) or throws
 * ServiceError. It never emits HTTP.
 *
 * SCOPE: objects + relationships (the genuinely-duplicated overlaps). Classes /
 * property definitions / relationship types are UI-only admin settings (the API
 * exposes classes + rel-types read-only), and CMDB↔ticket links are API-only
 * (no UI twin) — both stay on their own code.
 *
 * Canonical behaviour = the API resource's: class is immutable after creation,
 * parents are cycle-checked, required properties are enforced on create (and on
 * touched properties on update — inline-edit friendly), and property values are
 * stored strongly typed with per-type validation (numbers numeric, dropdowns
 * against the option list, dates parsed, object_ref existence + target-class +
 * no self-reference). Delete removes the whole descendant tree explicitly.
 *
 * ⚠️ The TYPING rules themselves (what a 'number' accepts, how a date is parsed,
 * what a reference must point at) live in includes/typed_fields.php, shared with
 * the flexible asset fields. This file decides only WHICH properties apply to a
 * class and WHERE their values are stored — plus every authorisation rule, which
 * deliberately never moves into a general-purpose layer. See
 * docs/design/flexible-asset-fields.md §7.1.
 *
 * Property input — two accepted shapes (the service normalises both):
 *   - `properties`: { property_key: value }   (the API's canonical map)
 *   - `property_values`: [{ property_id, value }]   (the UI's id-addressed list;
 *     entries whose property_id isn't in the class are dropped, as the UI did)
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../typed_fields.php';
require_once __DIR__ . '/../cmdb_record_refs.php';   // GH #312: person / team / location ... properties
require_once __DIR__ . '/asset_ci_links.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class CmdbService
{
    /**
     * Multi-tenancy gate: refuse to touch a CI outside the actor's companies.
     * companyScope null = all companies (single-company install or an all-access
     * actor) → no gate. Framed as not-found so it never reveals another
     * company's CI. $row must include tenant_id.
     *
     * @param array $row  the cmdb_objects row; must include tenant_id
     */
    private static function assertScope(PDO $conn, ActorContext $ctx, array $row): void
    {
        if ($ctx->companyScope === null) {
            return;
        }
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        if (!in_array($tid, $ctx->companyScope, true)) {
            throw new ServiceError('not_found', 'not_found', 'Object not found.');
        }
    }

    /**
     * Resolve the company a new CI should belong to, and refuse one the actor
     * can't reach. Callers may pass an explicit company; otherwise it's the
     * actor's current company context.
     *
     * Returns the value to STORE: the Default company normalises to NULL so
     * UI-created and API-created CIs store the same thing (every read treats
     * NULL as Default's), matching AssetsService.
     *
     * @param array $in  the request fields; tenant_id or company_id names the company, else the actor's active one
     */
    private static function resolveTenantForCreate(PDO $conn, ActorContext $ctx, array $in): ?int
    {
        $tenantId = null;
        foreach (['tenant_id', 'company_id'] as $k) {
            if (isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null) {
                $tenantId = (int)$in[$k];
                break;
            }
        }
        if ($tenantId === null) {
            $tenantId = getActiveTenantId($conn, $ctx->actorId);
        }
        if ($tenantId !== null && $ctx->companyScope !== null
            && !in_array($tenantId, $ctx->companyScope, true)) {
            throw new ServiceError('validation', 'invalid_field', 'Company not found.');
        }
        return ($tenantId !== null && $tenantId === getDefaultTenantId($conn)) ? null : $tenantId;
    }

    /**
     * The same-company invariant. A CI belongs to exactly one company, so every
     * link between two CIs — parent/child, a relationship, an object_ref
     * property — must have both ends in the same company. Without this a link
     * becomes a side channel: you can't read the other CI, but you can still
     * discover it exists and bind your own records to it.
     *
     * @param int $aId  one cmdb_objects id
     * @param int $bId  the other cmdb_objects id
     * @param string $what  the kind of link, for the error message, e.g. 'parent' or 'relationship'
     */
    private static function assertSameCompany(PDO $conn, int $aId, int $bId, string $what): void
    {
        if (!isMultiTenant($conn)) {
            return;
        }
        $stmt = $conn->prepare("SELECT id, tenant_id FROM cmdb_objects WHERE id IN (?, ?)");
        $stmt->execute([$aId, $bId]);
        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byId[(int)$r['id']] = ($r['tenant_id'] === null)
                ? getDefaultTenantId($conn) : (int)$r['tenant_id'];
        }
        if (!isset($byId[$aId], $byId[$bId])) {
            return; // a missing row is the callers' existence check to report
        }
        if ($byId[$aId] !== $byId[$bId]) {
            throw new ServiceError('validation', 'invalid_field',
                "Both configuration items must belong to the same company ($what).");
        }
    }

    // ======================================================================
    //  Objects
    // ======================================================================

    /**
     * Create (no id) or update (id present) an object. Returns ['id','created'].
     *
     * @param array $in  the request fields: id (absent = new) plus those createObject() / updateObject() take
     */
    public static function saveObject(PDO $conn, ActorContext $ctx, array $in): array
    {
        if (!empty($in['id'])) {
            return ['id' => self::updateObject($conn, $ctx, (int)$in['id'], $in), 'created' => false];
        }
        return ['id' => self::createObject($conn, $ctx, $in), 'created' => true];
    }

    /**
     * Create a CI inside a transaction the CALLER owns, without announcing it - for a
     * caller that must create something else in the same transaction (GH #201: a CI and its
     * asset link, both or neither). Call announceCreated() after the caller commits, so a
     * rolled-back create never fires the workflow event for a CI that does not exist.
     *
     * @param array $in  as createObject()
     */
    public static function createInTransaction(PDO $conn, ActorContext $ctx, array $in): int
    {
        if (!$conn->inTransaction()) {
            throw new LogicException('createInTransaction() needs the caller\'s transaction.');
        }
        return self::createObject($conn, $ctx, $in, false);
    }

    /** Fire cmdb.object.created for a CI made by createInTransaction(), once its transaction has committed. */
    public static function announceCreated(PDO $conn, int $objectId): void
    {
        $row = self::loadObjectRow($conn, $objectId);
        try {
            WorkflowEngine::dispatch('cmdb.object.created', [
                'object' => ['id' => $objectId, 'name' => $row['name'], 'class_id' => (int)$row['class_id'], 'is_planned' => (int)($row['is_planned'] ?? 0)],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in cmdb service (object.created): ' . $wfEx->getMessage());
        }
    }

    /**
     * The properties a class requires on create, for a form that has to ask for them
     * (GH #201's Create linked CI): key, label, type, dropdown options, a reference's class.
     *
     * @param int $classId  the cmdb_classes id
     */
    public static function requiredProperties(PDO $conn, int $classId): array
    {
        $out = [];
        foreach (self::classDefs($conn, $classId) as $key => $d) {
            if (!$d['required']) { continue; }
            $out[] = ['key' => $key, 'label' => $d['label'], 'type' => $d['type'],
                'options' => $d['type'] === 'dropdown' ? self::propertyOptionValues($conn, $d['id']) : [],
                'ref_class_id' => $d['ref_target'],
                // GH #312: person / team / location ... - picked from records, not CIs
                'record_type' => $d['record_type'] ?? null];
        }
        return $out;
    }

    /**
     * Creates a CI: validates the name, class, parent, company and properties, inserts it
     * with its property values and fires the cmdb.object.created workflow event.
     *
     * Inside a transaction the caller already holds, it neither begins nor commits one.
     *
     * @param array $in  name, class_id or class_key, parent_id, is_planned, tenant_id or company_id, properties or property_values
     * @param bool $announce  false to leave the workflow event to the caller (createInTransaction())
     */
    private static function createObject(PDO $conn, ActorContext $ctx, array $in, bool $announce = true): int
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new ServiceError('validation', 'missing_field', "'name' is required.");
        }
        if (mb_strlen($name) > 255) {
            throw new ServiceError('validation', 'invalid_field', "'name' must be at most 255 characters.");
        }

        // Class by id or key; must be active.
        if (isset($in['class_id']) && $in['class_id'] !== '') {
            $cs = $conn->prepare("SELECT id FROM cmdb_classes WHERE id = ? AND is_active = 1");
            $cs->execute([(int)$in['class_id']]);
        } elseif (isset($in['class_key']) && trim((string)$in['class_key']) !== '') {
            $cs = $conn->prepare("SELECT id FROM cmdb_classes WHERE class_key = ? AND is_active = 1");
            $cs->execute([trim((string)$in['class_key'])]);
        } else {
            throw new ServiceError('validation', 'missing_field', "'class_id' or 'class_key' is required.");
        }
        $classId = $cs->fetchColumn();
        if ($classId === false) {
            throw new ServiceError('validation', 'invalid_field', 'Class not found or inactive.');
        }
        $classId = (int)$classId;

        $parentId = isset($in['parent_id']) && $in['parent_id'] !== '' && $in['parent_id'] !== null
            ? (int)$in['parent_id'] : null;
        self::validateParent($conn, null, $parentId);
        $isPlanned = !empty($in['is_planned']) ? 1 : 0;

        // Multi-tenancy: which company owns this CI (NULL = Default's).
        $storeTenant = self::resolveTenantForCreate($conn, $ctx, $in);

        // A parent must be reachable AND in the same company as the new CI —
        // otherwise a CI could be filed under another client's tree.
        if ($parentId !== null) {
            self::assertScope($conn, $ctx, self::loadObjectRow($conn, $parentId));
            $parentTenant = self::tenantOf($conn, $parentId);
            $newTenant = ($storeTenant === null) ? getDefaultTenantId($conn) : $storeTenant;
            if (isMultiTenant($conn) && $parentTenant !== $newTenant) {
                throw new ServiceError('validation', 'invalid_field',
                    'The parent belongs to a different company.');
            }
        }

        $values = self::normaliseProperties($conn, $classId, $in);
        self::checkRequired($conn, $classId, $values, true);
        self::assertObjectRefsInCompany($conn, $ctx, $classId, $values, $storeTenant);
        self::assertRecordRefsAllowed($conn, $ctx, $classId, $values, $storeTenant, null);

        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $conn->prepare(
                "INSERT INTO cmdb_objects (class_id, name, parent_id, is_planned, tenant_id, created_datetime, updated_datetime)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([$classId, $name, $parentId, $isPlanned, $storeTenant]);
            $objectId = (int)$conn->lastInsertId();
            self::writeProperties($conn, $objectId, $classId, $values);
            if ($own) $conn->commit();
        } catch (Exception $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }

        if (!$announce) {
            return $objectId;
        }
        try {
            WorkflowEngine::dispatch('cmdb.object.created', [
                'object' => ['id' => $objectId, 'name' => $name, 'class_id' => $classId, 'is_planned' => $isPlanned],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in cmdb service (object.created): ' . $wfEx->getMessage());
        }

        return $objectId;
    }

    /**
     * Updates a CI's name, parent, planned flag and properties; the class never changes.
     * Fires the cmdb.object.updated workflow event.
     *
     * @param int $objectId  the CI to update
     * @param array $in  any of name, parent_id, is_planned, properties or property_values; the class cannot change
     */
    private static function updateObject(PDO $conn, ActorContext $ctx, int $objectId, array $in): int
    {
        $current = self::loadObjectRow($conn, $objectId);       // 404 if gone
        self::assertScope($conn, $ctx, $current);               // 404 if another company's
        if (!array_diff_key($in, ['id' => true])) {
            throw new ServiceError('validation', 'missing_field', 'No fields to update.');
        }
        $classId = (int)$current['class_id'];                   // class is immutable

        $newName = $current['name'];
        if (array_key_exists('name', $in)) {
            $newName = trim((string)$in['name']);
            if ($newName === '') {
                throw new ServiceError('validation', 'invalid_field', "'name' cannot be empty.");
            }
            if (mb_strlen($newName) > 255) {
                throw new ServiceError('validation', 'invalid_field', "'name' must be at most 255 characters.");
            }
        }
        $newParent = $current['parent_id'] !== null ? (int)$current['parent_id'] : null;
        if (array_key_exists('parent_id', $in)) {
            $newParent = ($in['parent_id'] === '' || $in['parent_id'] === null) ? null : (int)$in['parent_id'];
            self::validateParent($conn, $objectId, $newParent);
            if ($newParent !== null) {
                self::assertScope($conn, $ctx, self::loadObjectRow($conn, $newParent));
                self::assertSameCompany($conn, $objectId, $newParent, 'parent');
            }
        }

        $values = self::normaliseProperties($conn, $classId, $in);
        self::checkRequired($conn, $classId, $values, false);
        self::assertObjectRefsInCompany($conn, $ctx, $classId, $values,
            $current['tenant_id'] !== null ? (int)$current['tenant_id'] : null);
        self::assertRecordRefsAllowed($conn, $ctx, $classId, $values,
            $current['tenant_id'] !== null ? (int)$current['tenant_id'] : null, $objectId);

        $conn->beginTransaction();
        try {
            if (array_key_exists('is_planned', $in)) {
                $conn->prepare("UPDATE cmdb_objects SET name = ?, parent_id = ?, is_planned = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                     ->execute([$newName, $newParent, !empty($in['is_planned']) ? 1 : 0, $objectId]);
            } else {
                $conn->prepare("UPDATE cmdb_objects SET name = ?, parent_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                     ->execute([$newName, $newParent, $objectId]);
            }
            if ($values) {
                self::writeProperties($conn, $objectId, $classId, $values);
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::dispatch('cmdb.object.updated', ['object' => ['id' => $objectId, 'name' => $newName, 'class_id' => $classId]]);
        return $objectId;
    }

    /**
     * Delete an object + its whole descendant tree (explicit, not FK cascade). Returns ['id','deleted_descendants'].
     *
     * @param int $objectId  the CI to delete, with every descendant
     */
    public static function deleteObject(PDO $conn, ActorContext $ctx, int $objectId): array
    {
        $row = self::loadObjectRow($conn, $objectId);
        self::assertScope($conn, $ctx, $row);   // never delete another company's CI

        $descendants = self::descendantIds($conn, $objectId);
        $ids = array_merge([$objectId], $descendants);
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $conn->beginTransaction();
        try {
            // GH #201: a linked asset is kept; its link ends here, with the CI's name kept on
            // the row. Before the deletes below, so the name and class can still be read.
            AssetCiLinkService::ciDeleted($conn, $ctx, $ids);
            // GH #199 Part B: a discovered object's CI deleted by a person - its binding ends and
            // the object waits for a decision rather than being recreated on the next report.
            require_once __DIR__ . '/virtual_estate_cmdb.php';
            VirtualEstateCmdbService::ciDeleted($conn, $ctx, $ids);
            self::clearAssetRefFields($conn, $ctx, $ids);
            $conn->prepare("UPDATE cmdb_object_properties SET value_object_id = NULL WHERE value_object_id IN ($ph)")->execute($ids);
            $conn->prepare("DELETE FROM cmdb_object_properties WHERE object_id IN ($ph)")->execute($ids);
            // Network Mapper: null connector provenance, then remove diagram nodes/connectors.
            $conn->prepare("UPDATE network_diagram_connectors c JOIN cmdb_object_relationships r ON r.id = c.cmdb_relationship_id
                            SET c.cmdb_relationship_id = NULL
                            WHERE r.from_object_id IN ($ph) OR r.to_object_id IN ($ph)")->execute(array_merge($ids, $ids));
            $conn->prepare("DELETE c FROM network_diagram_connectors c
                            JOIN network_diagram_nodes n ON (n.id = c.from_node_id OR n.id = c.to_node_id)
                            WHERE n.cmdb_object_id IN ($ph)")->execute($ids);
            $conn->prepare("DELETE FROM network_diagram_nodes WHERE cmdb_object_id IN ($ph)")->execute($ids);
            $conn->prepare("DELETE FROM cmdb_object_relationships WHERE from_object_id IN ($ph) OR to_object_id IN ($ph)")->execute(array_merge($ids, $ids));
            $conn->prepare("DELETE FROM ticket_cmdb_objects WHERE cmdb_object_id IN ($ph)")->execute($ids);
            foreach (array_reverse($ids) as $oid) {
                $conn->prepare("DELETE FROM cmdb_objects WHERE id = ?")->execute([$oid]);
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::dispatch('cmdb.object.deleted', ['object' => ['id' => $objectId, 'name' => $row['name'] ?? null, 'class_id' => isset($row['class_id']) ? (int)$row['class_id'] : null]]);
        return ['id' => $objectId, 'deleted_descendants' => count($descendants)];
    }

    // ======================================================================
    //  Relationships
    // ======================================================================

    /**
     * Create a relationship from $fromId. Returns ['id','to_object_id','verb'].
     *
     * @param int $fromId  the CI the relationship starts from
     * @param array $in  to_object_id (required), and relationship_type_id or verb
     */
    public static function createRelationship(PDO $conn, ActorContext $ctx, int $fromId, array $in): array
    {
        self::assertScope($conn, $ctx, self::loadObjectRow($conn, $fromId));

        $toId = isset($in['to_object_id']) ? (int)$in['to_object_id'] : 0;
        if ($toId <= 0) {
            throw new ServiceError('validation', 'missing_field', "'to_object_id' is required.");
        }
        if ($toId === $fromId) {
            throw new ServiceError('validation', 'invalid_field', "An object can't have a relationship with itself.");
        }
        // Both ends gated, then required to share a company. The scope check
        // alone isn't enough for an all-access actor, who can reach both
        // companies and could otherwise wire two clients' estates together.
        self::assertScope($conn, $ctx, self::loadObjectRow($conn, $toId));
        self::assertSameCompany($conn, $fromId, $toId, 'relationship');

        // Type by id or verb; must be active.
        if (isset($in['relationship_type_id']) && $in['relationship_type_id'] !== '') {
            $ts = $conn->prepare("SELECT id, verb FROM cmdb_relationship_types WHERE id = ? AND is_active = 1");
            $ts->execute([(int)$in['relationship_type_id']]);
        } elseif (isset($in['verb']) && trim((string)$in['verb']) !== '') {
            $ts = $conn->prepare("SELECT id, verb FROM cmdb_relationship_types WHERE verb = ? AND is_active = 1");
            $ts->execute([trim((string)$in['verb'])]);
        } else {
            throw new ServiceError('validation', 'missing_field', "'relationship_type_id' or 'verb' is required.");
        }
        $type = $ts->fetch(PDO::FETCH_ASSOC);
        if (!$type) {
            throw new ServiceError('validation', 'invalid_field', 'Relationship type not found or inactive.');
        }

        try {
            $conn->prepare(
                "INSERT INTO cmdb_object_relationships (from_object_id, to_object_id, relationship_type_id, created_datetime)
                 VALUES (?, ?, ?, UTC_TIMESTAMP())"
            )->execute([$fromId, $toId, (int)$type['id']]);
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                throw new ServiceError('conflict', 'conflict', 'That relationship already exists.');
            }
            throw $e;
        }
        return ['id' => (int)$conn->lastInsertId(), 'to_object_id' => $toId, 'verb' => $type['verb']];
    }

    /**
     * Delete a relationship by row id. If $objectId is given (API), the
     * relationship must involve that object and a miss is a 404; if null (UI),
     * it's an unscoped delete that succeeds regardless. Returns the relationship id.
     *
     * @param int $relId  the cmdb_object_relationships row to delete
     * @param ?int $objectId  the CI it must involve (API), or null to check only its 'from' end (UI)
     */
    public static function deleteRelationship(PDO $conn, ActorContext $ctx, int $relId, ?int $objectId = null): int
    {
        if ($objectId !== null) {
            self::assertScope($conn, $ctx, self::loadObjectRow($conn, $objectId));
            $stmt = $conn->prepare("DELETE FROM cmdb_object_relationships WHERE id = ? AND (from_object_id = ? OR to_object_id = ?)");
            $stmt->execute([$relId, $objectId, $objectId]);
            if ($stmt->rowCount() === 0) {
                throw new ServiceError('not_found', 'not_found', 'Relationship not found on this object.');
            }
        } else {
            // UI path: no object id, so gate on the relationship's own ends —
            // otherwise a bare row id would delete any company's relationship.
            $ends = $conn->prepare("SELECT from_object_id, to_object_id FROM cmdb_object_relationships WHERE id = ?");
            $ends->execute([$relId]);
            $row = $ends->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new ServiceError('not_found', 'not_found', 'Relationship not found.');
            }
            self::assertScope($conn, $ctx, self::loadObjectRow($conn, (int)$row['from_object_id']));
            $conn->prepare("DELETE FROM cmdb_object_relationships WHERE id = ?")->execute([$relId]);
        }
        return $relId;
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /**
     * Asset custom fields of type "Link to something else -> configuration item" that point
     * at CIs being deleted. They used to keep the dead id and show as "#88" (found mapping
     * GH #201). Cleared in the delete's transaction, with a history line on each asset that
     * stores the id, not the name - as every other link-field history row does.
     *
     * @param int[] $ids  the CIs being deleted
     */
    private static function clearAssetRefFields(PDO $conn, ActorContext $ctx, array $ids): void
    {
        try {
            $fields = $conn->query("SELECT id, field_key, config FROM asset_fields WHERE field_type = 'ref'")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return;   // flexible asset fields not set up on this install
        }
        $byId = [];
        foreach ($fields as $f) {
            if ((json_decode((string)$f['config'], true)['ref_kind'] ?? '') === 'cmdb_object') { $byId[(int)$f['id']] = $f['field_key']; }
        }
        if (!$byId) { return; }
        $fph = implode(',', array_fill(0, count($byId), '?'));
        $oph = implode(',', array_fill(0, count($ids), '?'));
        $args = array_merge(array_keys($byId), array_map('intval', $ids));
        $st = $conn->prepare("SELECT asset_id, field_id, value_ref_id FROM asset_field_values WHERE field_id IN ($fph) AND value_ref_id IN ($oph)");
        $st->execute($args);
        $hist = $conn->prepare('INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, ?, ?, NULL, UTC_TIMESTAMP())');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $hist->execute([(int)$v['asset_id'], $ctx->actorId > 0 ? $ctx->actorId : null, 'field:' . $byId[(int)$v['field_id']], (string)$v['value_ref_id']]);
        }
        $conn->prepare("DELETE FROM asset_field_values WHERE field_id IN ($fph) AND value_ref_id IN ($oph)")->execute($args);
    }

    /**
     * The company id a CI effectively belongs to (NULL stored = Default's).
     *
     * @param int $id  the cmdb_objects id
     */
    private static function tenantOf(PDO $conn, int $id): int
    {
        $stmt = $conn->prepare("SELECT tenant_id FROM cmdb_objects WHERE id = ?");
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? getDefaultTenantId($conn) : (int)$v;
    }

    /**
     * object_ref properties point at another CI, so they're a third way to link
     * across companies — alongside parent/child and relationships. Every
     * object_ref value being written must resolve to a CI in the same company as
     * the CI being written.
     *
     * $ownTenant is the STORED value (NULL = Default's).
     *
     * @param int $classId  the CI's class, whose object_ref properties are checked
     * @param array $values  [property_key => value] about to be written
     * @param ?int $ownTenant  the CI's company as stored - null for the Default company
     */
    private static function assertObjectRefsInCompany(
        PDO $conn, ActorContext $ctx, int $classId, array $values, ?int $ownTenant): void
    {
        if (!isMultiTenant($conn) || !$values) {
            return;
        }
        $own = ($ownTenant === null) ? getDefaultTenantId($conn) : $ownTenant;
        foreach (self::classDefs($conn, $classId) as $key => $def) {
            // ⚠️ Reads the canonical TypedFields shape, NOT the raw column:
            // classDefs() maps 'object_ref' -> type 'ref' + kind 'cmdb_object'.
            // If this test stops matching, cross-company references stop being
            // checked SILENTLY — hence both halves, not just the type.
            if (($def['type'] ?? '') !== 'ref' || ($def['ref_kind'] ?? '') !== 'cmdb_object') {
                continue;
            }
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $refId = $values[$key];
            if ($refId === null || $refId === '' || (int)$refId <= 0) {
                continue;
            }
            $refId = (int)$refId;
            self::assertScope($conn, $ctx, self::loadObjectRow($conn, $refId));
            if (self::tenantOf($conn, $refId) !== $own) {
                throw new ServiceError('validation', 'invalid_field',
                    "'{$key}' must point at a configuration item in the same company.");
            }
        }
    }

    /**
     * GH #312: a record property (person, team, location ...) may point only at a
     * record this CI may hold - see cmdbRecordAllowed(): a person, location or
     * contract of the CI's own company (or a shared location), a company the
     * person editing can reach, an active team / analyst / supplier. Runs on every
     * install, not only multi-company ones, because "not inactive" applies to all.
     *
     * The value already stored is allowed even if its record has since been made
     * inactive, so re-saving a CI never fails over an untouched retired supplier.
     *
     * @param ?int $ownTenant  the CI's company as stored - null for the Default company
     * @param ?int $objectId   the CI being updated, null on create
     */
    private static function assertRecordRefsAllowed(
        PDO $conn, ActorContext $ctx, int $classId, array $values, ?int $ownTenant, ?int $objectId): void
    {
        if (!$values) {
            return;
        }
        $own = ($ownTenant === null) ? (int)getDefaultTenantId($conn) : $ownTenant;
        foreach (self::classDefs($conn, $classId) as $key => $def) {
            if (empty($def['record_type']) || !array_key_exists($key, $values)) {
                continue;
            }
            $refId = $values[$key];
            if ($refId === null || $refId === '' || (int)$refId <= 0) {
                continue;
            }
            $refId = (int)$refId;
            if (!cmdbRecordRefsReady()) {
                throw new ServiceError('validation', 'invalid_field',
                    "'{$def['label']}' needs a database update: run System -> Database Verification.");
            }
            $stored = false;
            if ($objectId !== null) {
                $st = $conn->prepare("SELECT value_ref_id FROM cmdb_object_properties WHERE object_id = ? AND property_id = ?");
                $st->execute([$objectId, $def['id']]);
                $stored = ((int)$st->fetchColumn() === $refId);
            }
            if (!cmdbRecordAllowed($conn, $def['record_type'], $refId, $own, $ctx->companyScope, $stored)) {
                throw new ServiceError('validation', 'invalid_field',
                    "'{$def['label']}' can't point at that record: it doesn't exist, is no longer in use, or belongs to another company.");
            }
        }
    }

    /**
     * Loads one CI with its class name and key; throws not found when it does not exist.
     *
     * @param int $id  the cmdb_objects id to load
     */
    private static function loadObjectRow(PDO $conn, int $id): array
    {
        $stmt = $conn->prepare(
            "SELECT o.*, c.name AS class_name, c.class_key
             FROM cmdb_objects o JOIN cmdb_classes c ON c.id = o.class_id WHERE o.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Object not found.');
        }
        return $row;
    }

    /**
     * Property definitions for a class, keyed by property_key, in the canonical
     * TypedFields shape.
     *
     * 🔑 Resolving WHICH fields a thing has is the module's job, not the
     * engine's — the CMDB answers it from a class, where Assets answers it from
     * the field sets attached to a type plus any attached to the one asset.
     * Only the shape is shared.
     *
     * The CMDB's `object_ref` is the engine's `ref` with a `cmdb_object` kind,
     * and `target_class_id` is that kind's target discriminator.
     *
     * @param int $classId  the cmdb_classes id
     */
    private static function classDefs(PDO $conn, int $classId): array
    {
        $stmt = $conn->prepare(
            "SELECT id, property_key, label, property_type, target_class_id, is_required
             FROM cmdb_class_properties WHERE class_id = ? ORDER BY display_order, id"
        );
        $stmt->execute([$classId]);
        $defs = [];
        $records = cmdbRecordTypes();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $isRef = ($d['property_type'] === 'object_ref');
            // GH #312: a person / analyst / team / location / company / supplier /
            // contract property is a ref too, of that record's kind, kept in the
            // 'record' slot (value_ref_id) - never beside the CI links.
            $record = $records[$d['property_type']] ?? null;
            $defs[$d['property_key']] = [
                'id'         => (int)$d['id'],
                'key'        => $d['property_key'],
                'label'      => $d['label'],
                'type'       => ($isRef || $record) ? 'ref' : $d['property_type'],
                'required'   => ((int)$d['is_required'] === 1),
                'config'     => [],
                'ref_kind'   => $isRef ? 'cmdb_object' : ($record ? $record['kind'] : null),
                'ref_target' => ($isRef && $d['target_class_id'] !== null) ? (int)$d['target_class_id'] : null,
                'ref_slot'   => $record ? 'record' : 'ref',
                'record_type'=> $record ? $d['property_type'] : null,
            ];
        }
        return $defs;
    }

    /**
     * Where CMDB property values live, for TypedFields.
     *
     * `self_kind` says which reference kind counts as "the same table as the
     * owner", so the no-self-reference rule fires for a CI pointing at itself
     * and NOT for, say, an asset whose linked user happens to share its id.
     *
     * @param int $classId  the CI's class, used only in the 'unknown property' hint
     */
    private static function valueSchema(int $classId): array
    {
        $columns = [
            'text'    => 'value_text',
            'number'  => 'value_number',
            'date'    => 'value_date',
            'boolean' => 'value_boolean',
            'ref'     => 'value_object_id',
        ];
        // GH #312. Only once Database Verification has added it: an install that has
        // pulled the update but not run it must still read and save every other
        // property (it cannot have record properties until it runs it anyway).
        if (cmdbRecordRefsReady()) {
            $columns['record'] = 'value_ref_id';
        }
        return [
            'value_table'  => 'cmdb_object_properties',
            'owner_column' => 'object_id',
            'def_column'   => 'property_id',
            'columns'      => $columns,
            'self_kind'    => 'cmdb_object',
            // A closure, not [self::class, ...]: the method is private, and a
            // closure created in class scope keeps access to it.
            'options'      => static function (PDO $conn, int $propertyId): array {
                return self::propertyOptionValues($conn, $propertyId);
            },
            'unknown_hint' => "See GET /cmdb/classes/{$classId}.",
        ];
    }

    /**
     * Normalise the two accepted property shapes to a { property_key: value } map.
     * The API's `properties` map passes through (unknown keys -> 422 later); the
     * UI's `property_values` id-list is translated, dropping ids not in the class.
     *
     * @param int $classId  the CI's class, to translate property ids to keys
     * @param array $in  the request fields; 'properties' (API, keyed by property_key) or 'property_values' (UI, [{property_id, value}])
     */
    private static function normaliseProperties(PDO $conn, int $classId, array $in): array
    {
        if (isset($in['properties']) && is_array($in['properties'])) {
            return $in['properties'];
        }
        if (isset($in['property_values']) && is_array($in['property_values'])) {
            $idToKey = [];
            $stmt = $conn->prepare("SELECT id, property_key FROM cmdb_class_properties WHERE class_id = ?");
            $stmt->execute([$classId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $idToKey[(int)$d['id']] = $d['property_key'];
            }
            $out = [];
            foreach ($in['property_values'] as $entry) {
                if (!is_array($entry)) continue;
                $pid = isset($entry['property_id']) ? (int)$entry['property_id'] : 0;
                if (!isset($idToKey[$pid])) continue;   // drop unknown / other-class ids (UI behaviour)
                $out[$idToKey[$pid]] = array_key_exists('value', $entry) ? $entry['value'] : null;
            }
            return $out;
        }
        return [];
    }

    /**
     * Dropdown option values for one property.
     *
     * @param int $propertyId  the cmdb_class_properties id
     */
    private static function propertyOptionValues(PDO $conn, int $propertyId): array
    {
        $stmt = $conn->prepare("SELECT option_value FROM cmdb_class_property_options WHERE property_id = ? ORDER BY display_order, id");
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Validate + write property values (keyed by property_key). Unknown keys -> 422.
     *
     * The typing, validation and storage rules live in TypedFields (see
     * includes/typed_fields.php); this decides only WHICH properties apply and
     * WHERE they are stored.
     *
     * @param int $objectId  the CI the values belong to
     * @param int $classId  the CI's class
     * @param array $values  [property_key => value]
     */
    private static function writeProperties(PDO $conn, int $objectId, int $classId, array $values): void
    {
        TypedFields::writeValues(
            $conn,
            self::valueSchema($classId),
            $objectId,
            self::classDefs($conn, $classId),
            $values
        );
    }

    /**
     * Required-property enforcement — create/update asymmetry.
     *
     * @param int $classId  the CI's class
     * @param array $values  [property_key => value] being written
     * @param bool $isCreate  true on create, when every required property must be present; on update only those sent are checked
     */
    private static function checkRequired(PDO $conn, int $classId, array $values, bool $isCreate): void
    {
        TypedFields::checkRequired(self::classDefs($conn, $classId), $values, $isCreate);
    }

    /**
     * Parent validation incl. the cycle walk.
     *
     * @param ?int $objectId  the CI being saved, or null when it is new (no cycle check)
     * @param ?int $parentId  the proposed parent, or null for none
     */
    private static function validateParent(PDO $conn, ?int $objectId, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($objectId !== null && $parentId === $objectId) {
            throw new ServiceError('validation', 'invalid_field', "An object can't be its own parent.");
        }
        $ps = $conn->prepare("SELECT id FROM cmdb_objects WHERE id = ?");
        $ps->execute([$parentId]);
        if (!$ps->fetchColumn()) {
            throw new ServiceError('validation', 'invalid_field', "Parent object not found: {$parentId}");
        }
        if ($objectId !== null) {
            $cursor = $parentId;
            $hops = 0;
            while ($cursor !== null && $hops < 100) {
                if ($cursor === $objectId) {
                    throw new ServiceError('validation', 'invalid_field', 'That parent would create a cycle (the parent is a descendant of this object).');
                }
                $u = $conn->prepare("SELECT parent_id FROM cmdb_objects WHERE id = ?");
                $u->execute([$cursor]);
                $next = $u->fetchColumn();
                $cursor = $next ? (int)$next : null;
                $hops++;
            }
        }
    }

    /**
     * All descendant ids of an object (excluding itself), cycle-safe.
     *
     * @param int $rootId  the CI whose children, grandchildren and so on are wanted
     */
    private static function descendantIds(PDO $conn, int $rootId): array
    {
        $ids = [];
        $stack = [$rootId];
        $seen = [$rootId => true];
        $hops = 0;
        $stmt = $conn->prepare("SELECT id FROM cmdb_objects WHERE parent_id = ?");
        while ($stack && $hops < 10000) {
            $cur = array_pop($stack);
            $stmt->execute([$cur]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                $cid = (int)$cid;
                if (isset($seen[$cid])) {
                    continue;
                }
                $seen[$cid] = true;
                $ids[] = $cid;
                $stack[] = $cid;
            }
            $hops++;
        }
        return $ids;
    }

    // parseDate() moved to TypedFields::parseDate() — its only caller was the
    // property coercion that now lives there. Same UTC output, same message.
}
