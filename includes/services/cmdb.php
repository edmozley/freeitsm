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
 * Property input — two accepted shapes (the service normalises both):
 *   - `properties`: { property_key: value }   (the API's canonical map)
 *   - `property_values`: [{ property_id, value }]   (the UI's id-addressed list;
 *     entries whose property_id isn't in the class are dropped, as the UI did)
 */

require_once __DIR__ . '/../service_context.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class CmdbService
{
    // ======================================================================
    //  Objects
    // ======================================================================

    /** Create (no id) or update (id present) an object. Returns ['id','created']. */
    public static function saveObject(PDO $conn, ActorContext $ctx, array $in): array
    {
        if (!empty($in['id'])) {
            return ['id' => self::updateObject($conn, $ctx, (int)$in['id'], $in), 'created' => false];
        }
        return ['id' => self::createObject($conn, $ctx, $in), 'created' => true];
    }

    private static function createObject(PDO $conn, ActorContext $ctx, array $in): int
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

        $values = self::normaliseProperties($conn, $classId, $in);
        self::checkRequired($conn, $classId, $values, true);

        $conn->beginTransaction();
        try {
            $conn->prepare(
                "INSERT INTO cmdb_objects (class_id, name, parent_id, is_planned, created_datetime, updated_datetime)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([$classId, $name, $parentId, $isPlanned]);
            $objectId = (int)$conn->lastInsertId();
            self::writeProperties($conn, $objectId, $classId, $values);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
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

    private static function updateObject(PDO $conn, ActorContext $ctx, int $objectId, array $in): int
    {
        $current = self::loadObjectRow($conn, $objectId);       // 404 if gone
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
        }

        $values = self::normaliseProperties($conn, $classId, $in);
        self::checkRequired($conn, $classId, $values, false);

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
        return $objectId;
    }

    /** Delete an object + its whole descendant tree (explicit, not FK cascade). Returns ['id','deleted_descendants']. */
    public static function deleteObject(PDO $conn, ActorContext $ctx, int $objectId): array
    {
        self::loadObjectRow($conn, $objectId);

        $descendants = self::descendantIds($conn, $objectId);
        $ids = array_merge([$objectId], $descendants);
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $conn->beginTransaction();
        try {
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
        return ['id' => $objectId, 'deleted_descendants' => count($descendants)];
    }

    // ======================================================================
    //  Relationships
    // ======================================================================

    /** Create a relationship from $fromId. Returns ['id','to_object_id','verb']. */
    public static function createRelationship(PDO $conn, ActorContext $ctx, int $fromId, array $in): array
    {
        self::loadObjectRow($conn, $fromId);

        $toId = isset($in['to_object_id']) ? (int)$in['to_object_id'] : 0;
        if ($toId <= 0) {
            throw new ServiceError('validation', 'missing_field', "'to_object_id' is required.");
        }
        if ($toId === $fromId) {
            throw new ServiceError('validation', 'invalid_field', "An object can't have a relationship with itself.");
        }
        self::loadObjectRow($conn, $toId);

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
     */
    public static function deleteRelationship(PDO $conn, ActorContext $ctx, int $relId, ?int $objectId = null): int
    {
        if ($objectId !== null) {
            self::loadObjectRow($conn, $objectId);
            $stmt = $conn->prepare("DELETE FROM cmdb_object_relationships WHERE id = ? AND (from_object_id = ? OR to_object_id = ?)");
            $stmt->execute([$relId, $objectId, $objectId]);
            if ($stmt->rowCount() === 0) {
                throw new ServiceError('not_found', 'not_found', 'Relationship not found on this object.');
            }
        } else {
            $conn->prepare("DELETE FROM cmdb_object_relationships WHERE id = ?")->execute([$relId]);
        }
        return $relId;
    }

    // ======================================================================
    //  Internals
    // ======================================================================

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

    /** Property definitions for a class, keyed by property_key. */
    private static function classDefs(PDO $conn, int $classId): array
    {
        $stmt = $conn->prepare(
            "SELECT id, property_key, label, property_type, target_class_id, is_required
             FROM cmdb_class_properties WHERE class_id = ? ORDER BY display_order, id"
        );
        $stmt->execute([$classId]);
        $defs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $defs[$d['property_key']] = $d;
        }
        return $defs;
    }

    /**
     * Normalise the two accepted property shapes to a { property_key: value } map.
     * The API's `properties` map passes through (unknown keys -> 422 later); the
     * UI's `property_values` id-list is translated, dropping ids not in the class.
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

    /** Dropdown option values for one property. */
    private static function propertyOptionValues(PDO $conn, int $propertyId): array
    {
        $stmt = $conn->prepare("SELECT option_value FROM cmdb_class_property_options WHERE property_id = ? ORDER BY display_order, id");
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Validate + write property values (keyed by property_key). Unknown keys -> 422. */
    private static function writeProperties(PDO $conn, int $objectId, int $classId, array $values): void
    {
        $defs = self::classDefs($conn, $classId);
        $del = $conn->prepare("DELETE FROM cmdb_object_properties WHERE object_id = ? AND property_id = ?");
        $ins = $conn->prepare(
            "INSERT INTO cmdb_object_properties
                 (object_id, property_id, value_text, value_number, value_date, value_boolean, value_object_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($values as $key => $rawValue) {
            if (!isset($defs[$key])) {
                throw new ServiceError('validation', 'invalid_field', "Unknown property '{$key}' for this class. See GET /cmdb/classes/{$classId}.");
            }
            $def = $defs[$key];
            $pid = (int)$def['id'];
            $del->execute([$objectId, $pid]);

            if ($rawValue === null || $rawValue === '') {
                continue; // clear this property
            }

            $vText = null; $vNumber = null; $vDate = null; $vBool = null; $vObj = null;
            switch ($def['property_type']) {
                case 'text':
                    $vText = (string)$rawValue;
                    break;
                case 'dropdown':
                    $vText = (string)$rawValue;
                    $allowed = self::propertyOptionValues($conn, $pid);
                    if ($allowed && !in_array($vText, $allowed, true)) {
                        throw new ServiceError('validation', 'invalid_field', "Property '{$def['label']}' must be one of: " . implode(', ', $allowed));
                    }
                    break;
                case 'number':
                    if (!is_numeric($rawValue)) {
                        throw new ServiceError('validation', 'invalid_field', "Property '{$def['label']}' expects a number.");
                    }
                    $vNumber = (float)$rawValue;
                    break;
                case 'date':
                    $vDate = self::parseDate((string)$rawValue, $key);
                    break;
                case 'boolean':
                    $vBool = ($rawValue === true || $rawValue === 1 || $rawValue === '1' || $rawValue === 'true') ? 1 : 0;
                    break;
                case 'object_ref':
                    $vObj = (int)$rawValue;
                    if ($vObj <= 0) {
                        continue 2;
                    }
                    if ($vObj === $objectId) {
                        throw new ServiceError('validation', 'invalid_field', "Property '{$def['label']}' can't reference its own object.");
                    }
                    $rs = $conn->prepare("SELECT class_id FROM cmdb_objects WHERE id = ?");
                    $rs->execute([$vObj]);
                    $refClassId = $rs->fetchColumn();
                    if ($refClassId === false) {
                        throw new ServiceError('validation', 'invalid_field', "Property '{$def['label']}' references an object that doesn't exist.");
                    }
                    if ($def['target_class_id'] !== null && (int)$refClassId !== (int)$def['target_class_id']) {
                        throw new ServiceError('validation', 'invalid_field', "Property '{$def['label']}' can only reference objects of its target class.");
                    }
                    break;
                default:
                    throw new ServiceError('validation', 'invalid_field', "Unknown property type: {$def['property_type']}");
            }

            $ins->execute([$objectId, $pid, $vText, $vNumber, $vDate, $vBool, $vObj]);
        }
    }

    /** Required-property enforcement — create/update asymmetry. */
    private static function checkRequired(PDO $conn, int $classId, array $values, bool $isCreate): void
    {
        foreach (self::classDefs($conn, $classId) as $key => $def) {
            if ((int)$def['is_required'] !== 1) {
                continue;
            }
            if (array_key_exists($key, $values)) {
                $v = $values[$key];
                if ($v === null || $v === '' || (is_array($v) && empty($v))) {
                    throw new ServiceError('validation', 'missing_field', "Required property missing: {$def['label']}");
                }
            } elseif ($isCreate) {
                throw new ServiceError('validation', 'missing_field', "Required property missing: {$def['label']}");
            }
        }
    }

    /** Parent validation incl. the cycle walk. */
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

    /** All descendant ids of an object (excluding itself), cycle-safe. */
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

    /** Parse a date/time to 'Y-m-d H:i:s' UTC (throwing twin of apiParseDate; 400 on bad input). */
    private static function parseDate(string $value, string $field): string
    {
        $v = trim($value);
        try {
            $dt = new DateTimeImmutable($v, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            throw new ServiceError('bad_request', 'invalid_parameter', "'{$field}' is not a valid date/time. Use ISO 8601, e.g. 2026-07-02T09:00:00Z.");
        }
    }
}
