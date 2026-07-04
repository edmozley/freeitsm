<?php
/**
 * FreeITSM REST API v1 — CMDB resource (configuration items).
 *
 * Mirrors the module's internal endpoints so an object touched via the API is
 * indistinguishable from one touched in the UI:
 *   - object create/update mirror api/cmdb/save_object.php: class is
 *     immutable after creation, parent links are cycle-checked, required
 *     properties are enforced on create (and only for touched properties on
 *     update — inline-edit friendly), and values are stored strongly typed
 *     with save_object.php's exact per-type validation (numbers numeric,
 *     object_ref existence + target-class constraint + no self-reference).
 *   - relationships mirror save/delete_object_relationship.php: no
 *     self-links, active type required, the unique from/to/type triple
 *     returns 409.
 *   - DELETE removes the whole descendant tree with children counted, but
 *     does it EXPLICITLY (properties, relationships, ticket links, object_ref
 *     back-references nulled) rather than trusting FK cascades — installs
 *     grown via Database Verify had no CMDB foreign keys at all.
 *
 * Two deliberate improvements over the UI, documented:
 *   - dropdown values are validated against the property's option list (422)
 *     instead of storing anything;
 *   - GET /cmdb/objects/{id}/tickets applies the key's COMPANY SCOPE to the
 *     tickets it returns (the internal endpoint reads tickets unscoped, a
 *     known multi-tenancy audit gap — the API does not reproduce it).
 *
 * CMDB tables themselves are install-wide (no tenant_id — matches the UI).
 * There is no audit trail in the product and none is invented here.
 */

// ---------------------------------------------------------------------------
// Classes (read-only in v1 — class design stays an admin activity in the UI)
// ---------------------------------------------------------------------------

function apiCmdbClassesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT c.id, c.class_key, c.name, c.description, c.is_active, i.icon_key,
                (SELECT COUNT(*) FROM cmdb_objects o WHERE o.class_id = c.id) AS object_count
         FROM cmdb_classes c
         LEFT JOIN cmdb_icons i ON i.id = c.icon_id
         ORDER BY c.display_order, c.name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($c) {
        return [
            'id'           => (int)$c['id'],
            'class_key'    => $c['class_key'],
            'name'         => $c['name'],
            'description'  => $c['description'],
            'icon'         => $c['icon_key'],
            'is_active'    => (bool)$c['is_active'],
            'object_count' => (int)$c['object_count'],
        ];
    }, $rows));
}

function apiCmdbClassesGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $stmt = $conn->prepare(
        "SELECT c.id, c.class_key, c.name, c.description, c.is_active, i.icon_key
         FROM cmdb_classes c LEFT JOIN cmdb_icons i ON i.id = c.icon_id WHERE c.id = ?"
    );
    $stmt->execute([$params[0]]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        apiError(404, 'not_found', 'Class not found.');
    }

    $props = $conn->prepare(
        "SELECT p.id, p.property_key, p.label, p.property_type, p.target_class_id,
                tc.name AS target_class_name, p.is_required, p.display_order
         FROM cmdb_class_properties p
         LEFT JOIN cmdb_classes tc ON tc.id = p.target_class_id
         WHERE p.class_id = ? ORDER BY p.display_order, p.id"
    );
    $props->execute([$params[0]]);
    $propRows = $props->fetchAll(PDO::FETCH_ASSOC);

    $opts = $conn->prepare(
        "SELECT o.property_id, o.option_value, o.colour
         FROM cmdb_class_property_options o
         JOIN cmdb_class_properties p ON p.id = o.property_id
         WHERE p.class_id = ? ORDER BY o.display_order, o.id"
    );
    $opts->execute([$params[0]]);
    $optionsByProp = [];
    foreach ($opts->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $optionsByProp[(int)$row['property_id']][] = ['value' => $row['option_value'], 'colour' => $row['colour']];
    }

    apiRespond([
        'id'          => (int)$c['id'],
        'class_key'   => $c['class_key'],
        'name'        => $c['name'],
        'description' => $c['description'],
        'icon'        => $c['icon_key'],
        'is_active'   => (bool)$c['is_active'],
        'properties'  => array_map(function ($p) use ($optionsByProp) {
            return [
                'id'            => (int)$p['id'],
                'property_key'  => $p['property_key'],
                'label'         => $p['label'],
                'type'          => $p['property_type'],
                'is_required'   => (bool)$p['is_required'],
                'target_class'  => $p['target_class_id'] !== null
                    ? ['id' => (int)$p['target_class_id'], 'name' => $p['target_class_name']] : null,
                'options'       => $optionsByProp[(int)$p['id']] ?? [],
            ];
        }, $propRows),
    ]);
}

// ---------------------------------------------------------------------------
// Object helpers
// ---------------------------------------------------------------------------

function apiCmdbLoadObject(PDO $conn, int $objectId): array {
    $stmt = $conn->prepare(
        "SELECT o.*, c.name AS class_name, c.class_key
         FROM cmdb_objects o JOIN cmdb_classes c ON c.id = o.class_id WHERE o.id = ?"
    );
    $stmt->execute([$objectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Object not found.');
    }
    return $row;
}

function apiCmdbSerializeObject(array $r): array {
    return [
        'id'         => (int)$r['id'],
        'name'       => $r['name'],
        'class'      => ['id' => (int)$r['class_id'], 'class_key' => $r['class_key'], 'name' => $r['class_name']],
        'parent_id'  => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
        'is_planned' => (bool)$r['is_planned'],
        'created_at' => apiIsoDate($r['created_datetime']),
        'updated_at' => apiIsoDate($r['updated_datetime']),
    ];
}

/** Property definitions for a class, keyed by property_key. */
function apiCmdbClassDefs(PDO $conn, int $classId): array {
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

/** Dropdown options (values only) for one property. */
function apiCmdbPropertyOptionValues(PDO $conn, int $propertyId): array {
    $stmt = $conn->prepare("SELECT option_value FROM cmdb_class_property_options WHERE property_id = ? ORDER BY display_order, id");
    $stmt->execute([$propertyId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Validate + write a set of property values (keyed by property_key) for an
 * object — save_object.php's exact typing and rules, plus dropdown-option
 * validation (deliberate improvement). Unknown keys are a 422 (machines
 * should not have typos silently ignored).
 */
function apiCmdbWriteProperties(PDO $conn, int $objectId, int $classId, array $values): void {
    $defs = apiCmdbClassDefs($conn, $classId);
    $del = $conn->prepare("DELETE FROM cmdb_object_properties WHERE object_id = ? AND property_id = ?");
    $ins = $conn->prepare(
        "INSERT INTO cmdb_object_properties
             (object_id, property_id, value_text, value_number, value_date, value_boolean, value_object_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($values as $key => $rawValue) {
        if (!isset($defs[$key])) {
            apiError(422, 'invalid_field', "Unknown property '{$key}' for this class. See GET /cmdb/classes/{$classId}.");
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
                $allowed = apiCmdbPropertyOptionValues($conn, $pid);
                if ($allowed && !in_array($vText, $allowed, true)) {
                    apiError(422, 'invalid_field', "Property '{$def['label']}' must be one of: " . implode(', ', $allowed));
                }
                break;
            case 'number':
                if (!is_numeric($rawValue)) {
                    apiError(422, 'invalid_field', "Property '{$def['label']}' expects a number.");
                }
                $vNumber = (float)$rawValue;
                break;
            case 'date':
                $vDate = apiParseDate((string)$rawValue, $key);
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
                    apiError(422, 'invalid_field', "Property '{$def['label']}' can't reference its own object.");
                }
                $rs = $conn->prepare("SELECT class_id FROM cmdb_objects WHERE id = ?");
                $rs->execute([$vObj]);
                $refClassId = $rs->fetchColumn();
                if ($refClassId === false) {
                    apiError(422, 'invalid_field', "Property '{$def['label']}' references an object that doesn't exist.");
                }
                if ($def['target_class_id'] !== null && (int)$refClassId !== (int)$def['target_class_id']) {
                    apiError(422, 'invalid_field', "Property '{$def['label']}' can only reference objects of its target class.");
                }
                break;
            default:
                apiError(422, 'invalid_field', "Unknown property type: {$def['property_type']}");
        }

        $ins->execute([$objectId, $pid, $vText, $vNumber, $vDate, $vBool, $vObj]);
    }
}

/** Required-property enforcement — save_object.php's create/update asymmetry. */
function apiCmdbCheckRequired(PDO $conn, int $classId, array $values, bool $isCreate): void {
    foreach (apiCmdbClassDefs($conn, $classId) as $key => $def) {
        if ((int)$def['is_required'] !== 1) {
            continue;
        }
        if (array_key_exists($key, $values)) {
            $v = $values[$key];
            if ($v === null || $v === '' || (is_array($v) && empty($v))) {
                apiError(422, 'missing_field', "Required property missing: {$def['label']}");
            }
        } elseif ($isCreate) {
            apiError(422, 'missing_field', "Required property missing: {$def['label']}");
        }
    }
}

/** Parent validation incl. save_object.php's cycle walk. */
function apiCmdbValidateParent(PDO $conn, ?int $objectId, ?int $parentId): void {
    if ($parentId === null) {
        return;
    }
    if ($objectId !== null && $parentId === $objectId) {
        apiError(422, 'invalid_field', "An object can't be its own parent.");
    }
    $ps = $conn->prepare("SELECT id FROM cmdb_objects WHERE id = ?");
    $ps->execute([$parentId]);
    if (!$ps->fetchColumn()) {
        apiError(422, 'invalid_field', "Parent object not found: {$parentId}");
    }
    if ($objectId !== null) {
        $cursor = $parentId;
        $hops = 0;
        while ($cursor !== null && $hops < 100) {
            if ($cursor === $objectId) {
                apiError(422, 'invalid_field', 'That parent would create a cycle (the parent is a descendant of this object).');
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
function apiCmdbDescendantIds(PDO $conn, int $rootId): array {
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

// ---------------------------------------------------------------------------
// GET /cmdb/objects
// ---------------------------------------------------------------------------
function apiCmdbObjectsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];

    if (isset($_GET['class_id']) && $_GET['class_id'] !== '') {
        $where[] = 'o.class_id = ?';
        $args[]  = (int)$_GET['class_id'];
    }
    if (isset($_GET['class_key']) && $_GET['class_key'] !== '') {
        $where[] = 'c.class_key = ?';
        $args[]  = trim($_GET['class_key']);
    }
    if (isset($_GET['parent_id']) && $_GET['parent_id'] !== '') {
        $where[] = 'o.parent_id = ?';
        $args[]  = (int)$_GET['parent_id'];
    }
    if (($_GET['top_level'] ?? '') === 'true') {
        $where[] = 'o.parent_id IS NULL';
    }
    if (isset($_GET['is_planned']) && $_GET['is_planned'] !== '') {
        $where[] = 'o.is_planned = ?';
        $args[]  = $_GET['is_planned'] === 'true' ? 1 : 0;
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = 'o.name LIKE ?';
        $args[]  = '%' . trim($_GET['q']) . '%';
    }

    $sortable = [
        'name' => 'o.name', 'id' => 'o.id', 'created_at' => 'o.created_datetime',
        'updated_at' => 'o.updated_datetime',
    ];
    $sortParam = trim($_GET['sort'] ?? 'name');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC');

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) FROM cmdb_objects o JOIN cmdb_classes c ON c.id = o.class_id WHERE $whereSql"
    );
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(
        "SELECT o.*, c.name AS class_name, c.class_key
         FROM cmdb_objects o JOIN cmdb_classes c ON c.id = o.class_id
         WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($args);
    apiRespond(array_map('apiCmdbSerializeObject', $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// ---------------------------------------------------------------------------
// GET /cmdb/objects/{id} — fully hydrated
// ---------------------------------------------------------------------------
function apiCmdbObjectsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $r = apiCmdbLoadObject($conn, $params[0]);
    $obj = apiCmdbSerializeObject($r);
    $obj['ai_summary'] = $r['ai_summary'];

    // Typed property values, hydrated like get_object.php.
    $vals = $conn->prepare(
        "SELECT op.*, ro.name AS ref_name, rc.name AS ref_class_name
         FROM cmdb_object_properties op
         LEFT JOIN cmdb_objects ro ON ro.id = op.value_object_id
         LEFT JOIN cmdb_classes rc ON rc.id = ro.class_id
         WHERE op.object_id = ?"
    );
    $vals->execute([$params[0]]);
    $valuesByProp = [];
    foreach ($vals->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $valuesByProp[(int)$v['property_id']] = $v;
    }

    $obj['properties'] = [];
    foreach (apiCmdbClassDefs($conn, (int)$r['class_id']) as $key => $def) {
        $pid = (int)$def['id'];
        $v = $valuesByProp[$pid] ?? null;
        $value = null;
        $valueObject = null;
        if ($v) {
            switch ($def['property_type']) {
                case 'text':
                case 'dropdown':   $value = $v['value_text']; break;
                case 'number':     $value = $v['value_number'] !== null ? (float)$v['value_number'] : null; break;
                case 'date':       $value = apiIsoDate($v['value_date']); break;
                case 'boolean':    $value = $v['value_boolean'] !== null ? ((int)$v['value_boolean'] === 1) : null; break;
                case 'object_ref':
                    $value = $v['value_object_id'] !== null ? (int)$v['value_object_id'] : null;
                    if ($value !== null) {
                        $valueObject = ['id' => $value, 'name' => $v['ref_name'], 'class_name' => $v['ref_class_name']];
                    }
                    break;
            }
        }
        $obj['properties'][] = [
            'property_key' => $key,
            'label'        => $def['label'],
            'type'         => $def['property_type'],
            'is_required'  => (bool)$def['is_required'],
            'value'        => $value,
            'value_object' => $valueObject,
        ];
    }

    // Parent + children
    $obj['parent'] = null;
    if ($r['parent_id'] !== null) {
        $p = $conn->prepare("SELECT o.id, o.name, c.name AS class_name FROM cmdb_objects o JOIN cmdb_classes c ON c.id = o.class_id WHERE o.id = ?");
        $p->execute([(int)$r['parent_id']]);
        $pr = $p->fetch(PDO::FETCH_ASSOC);
        if ($pr) {
            $obj['parent'] = ['id' => (int)$pr['id'], 'name' => $pr['name'], 'class_name' => $pr['class_name']];
        }
    }
    $ch = $conn->prepare("SELECT o.id, o.name, c.name AS class_name FROM cmdb_objects o JOIN cmdb_classes c ON c.id = o.class_id WHERE o.parent_id = ? ORDER BY o.name");
    $ch->execute([$params[0]]);
    $obj['children'] = array_map(function ($x) {
        return ['id' => (int)$x['id'], 'name' => $x['name'], 'class_name' => $x['class_name']];
    }, $ch->fetchAll(PDO::FETCH_ASSOC));

    // Relationships, both directions with natural-reading verbs (get_object.php).
    $serializeRel = function (array $rows) {
        return array_map(function ($x) {
            return [
                'id'               => (int)$x['id'],
                'type_id'          => (int)$x['type_id'],
                'verb'             => $x['verb'],
                'inverse_verb'     => $x['inverse_verb'],
                'other_id'         => (int)$x['other_id'],
                'other_name'       => $x['other_name'],
                'other_class_name' => $x['other_class_name'],
            ];
        }, $rows);
    };
    $out = $conn->prepare(
        "SELECT r.id, rt.id AS type_id, rt.verb, rt.inverse_verb,
                r.to_object_id AS other_id, oo.name AS other_name, oc.name AS other_class_name
         FROM cmdb_object_relationships r
         JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
         JOIN cmdb_objects oo ON oo.id = r.to_object_id
         JOIN cmdb_classes oc ON oc.id = oo.class_id
         WHERE r.from_object_id = ? ORDER BY rt.display_order, rt.verb, oo.name"
    );
    $out->execute([$params[0]]);
    $in = $conn->prepare(
        "SELECT r.id, rt.id AS type_id, rt.verb, rt.inverse_verb,
                r.from_object_id AS other_id, oo.name AS other_name, oc.name AS other_class_name
         FROM cmdb_object_relationships r
         JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
         JOIN cmdb_objects oo ON oo.id = r.from_object_id
         JOIN cmdb_classes oc ON oc.id = oo.class_id
         WHERE r.to_object_id = ? ORDER BY rt.display_order, rt.inverse_verb, oo.name"
    );
    $in->execute([$params[0]]);
    $obj['relationships'] = [
        'outgoing' => $serializeRel($out->fetchAll(PDO::FETCH_ASSOC)),
        'incoming' => $serializeRel($in->fetchAll(PDO::FETCH_ASSOC)),
    ];

    apiRespond($obj);
}

// ---------------------------------------------------------------------------
// POST /cmdb/objects
// ---------------------------------------------------------------------------
function apiCmdbObjectsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        apiError(422, 'missing_field', "'name' is required.");
    }
    if (mb_strlen($name) > 255) {
        apiError(422, 'invalid_field', "'name' must be at most 255 characters.");
    }

    // Class by id or key; must be active (save_object.php's create rule).
    if (isset($body['class_id']) && $body['class_id'] !== '') {
        $cs = $conn->prepare("SELECT id FROM cmdb_classes WHERE id = ? AND is_active = 1");
        $cs->execute([(int)$body['class_id']]);
    } elseif (isset($body['class_key']) && trim((string)$body['class_key']) !== '') {
        $cs = $conn->prepare("SELECT id FROM cmdb_classes WHERE class_key = ? AND is_active = 1");
        $cs->execute([trim((string)$body['class_key'])]);
    } else {
        apiError(422, 'missing_field', "'class_id' or 'class_key' is required.");
    }
    $classId = $cs->fetchColumn();
    if ($classId === false) {
        apiError(422, 'invalid_field', 'Class not found or inactive.');
    }
    $classId = (int)$classId;

    $parentId = isset($body['parent_id']) && $body['parent_id'] !== '' && $body['parent_id'] !== null
        ? (int)$body['parent_id'] : null;
    apiCmdbValidateParent($conn, null, $parentId);
    $isPlanned = !empty($body['is_planned']) ? 1 : 0;

    $values = (isset($body['properties']) && is_array($body['properties'])) ? $body['properties'] : [];
    apiCmdbCheckRequired($conn, $classId, $values, true);

    $conn->beginTransaction();
    try {
        $ins = $conn->prepare(
            "INSERT INTO cmdb_objects (class_id, name, parent_id, is_planned, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $ins->execute([$classId, $name, $parentId, $isPlanned]);
        $objectId = (int)$conn->lastInsertId();
        apiCmdbWriteProperties($conn, $objectId, $classId, $values);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    apiRespond(apiCmdbSerializeObject(apiCmdbLoadObject($conn, $objectId)), 201);
}

// ---------------------------------------------------------------------------
// PATCH /cmdb/objects/{id}
// ---------------------------------------------------------------------------
function apiCmdbObjectsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $objectId = $params[0];
    $current = apiCmdbLoadObject($conn, $objectId);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }
    $classId = (int)$current['class_id']; // class is immutable, like the UI

    $newName = $current['name'];
    if (array_key_exists('name', $body)) {
        $newName = trim((string)$body['name']);
        if ($newName === '') {
            apiError(422, 'invalid_field', "'name' cannot be empty.");
        }
        if (mb_strlen($newName) > 255) {
            apiError(422, 'invalid_field', "'name' must be at most 255 characters.");
        }
    }
    $newParent = $current['parent_id'] !== null ? (int)$current['parent_id'] : null;
    if (array_key_exists('parent_id', $body)) {
        $newParent = ($body['parent_id'] === '' || $body['parent_id'] === null) ? null : (int)$body['parent_id'];
        apiCmdbValidateParent($conn, $objectId, $newParent);
    }

    $values = (isset($body['properties']) && is_array($body['properties'])) ? $body['properties'] : [];
    apiCmdbCheckRequired($conn, $classId, $values, false);

    $conn->beginTransaction();
    try {
        if (array_key_exists('is_planned', $body)) {
            $conn->prepare("UPDATE cmdb_objects SET name = ?, parent_id = ?, is_planned = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$newName, $newParent, !empty($body['is_planned']) ? 1 : 0, $objectId]);
        } else {
            $conn->prepare("UPDATE cmdb_objects SET name = ?, parent_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$newName, $newParent, $objectId]);
        }
        if ($values) {
            apiCmdbWriteProperties($conn, $objectId, $classId, $values);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    apiRespond(apiCmdbSerializeObject(apiCmdbLoadObject($conn, $objectId)));
}

// ---------------------------------------------------------------------------
// DELETE /cmdb/objects/{id} — explicit descendant-tree removal (grown installs
// have no CMDB FK cascades; see the db_verify FK group added alongside this)
// ---------------------------------------------------------------------------
function apiCmdbObjectsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiCmdbLoadObject($conn, $params[0]);

    $descendants = apiCmdbDescendantIds($conn, $params[0]);
    $ids = array_merge([$params[0]], $descendants);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $conn->beginTransaction();
    try {
        // object_ref values pointing at anything in the tree -> NULL (the FK's SET NULL rule).
        $conn->prepare("UPDATE cmdb_object_properties SET value_object_id = NULL WHERE value_object_id IN ($ph)")->execute($ids);
        $conn->prepare("DELETE FROM cmdb_object_properties WHERE object_id IN ($ph)")->execute($ids);
        // Network Mapper: connector provenance pointing at these objects' relationships
        // goes NULL (before the relationships die), then the objects' diagram nodes and
        // their connectors go — the FKs' CASCADE/SET NULL rules, done explicitly so
        // installs grown without the network FKs behave identically.
        $conn->prepare("UPDATE network_diagram_connectors c JOIN cmdb_object_relationships r ON r.id = c.cmdb_relationship_id
                        SET c.cmdb_relationship_id = NULL
                        WHERE r.from_object_id IN ($ph) OR r.to_object_id IN ($ph)")->execute(array_merge($ids, $ids));
        $conn->prepare("DELETE c FROM network_diagram_connectors c
                        JOIN network_diagram_nodes n ON (n.id = c.from_node_id OR n.id = c.to_node_id)
                        WHERE n.cmdb_object_id IN ($ph)")->execute($ids);
        $conn->prepare("DELETE FROM network_diagram_nodes WHERE cmdb_object_id IN ($ph)")->execute($ids);
        $conn->prepare("DELETE FROM cmdb_object_relationships WHERE from_object_id IN ($ph) OR to_object_id IN ($ph)")->execute(array_merge($ids, $ids));
        $conn->prepare("DELETE FROM ticket_cmdb_objects WHERE cmdb_object_id IN ($ph)")->execute($ids);
        // Children first so the parent FK (where it exists) never blocks.
        foreach (array_reverse($ids) as $oid) {
            $conn->prepare("DELETE FROM cmdb_objects WHERE id = ?")->execute([$oid]);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    apiRespond(['id' => $params[0], 'deleted' => true, 'deleted_descendants' => count($descendants)]);
}

// ---------------------------------------------------------------------------
// GET /cmdb/objects/{id}/impact — blast radius (mirrors get_object_impact.php)
// ---------------------------------------------------------------------------
function apiCmdbObjectImpact(PDO $conn, array $apiKey, array $params, array $body): void {
    apiCmdbLoadObject($conn, $params[0]);

    $descendants = [];
    $stack = [['id' => $params[0], 'depth' => 0]];
    $seen = [$params[0] => true];
    $hops = 0;
    $childStmt = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name FROM cmdb_objects o JOIN cmdb_classes c ON c.id = o.class_id WHERE o.parent_id = ?"
    );
    while ($stack && $hops < 1000) {
        $cur = array_pop($stack);
        $childStmt->execute([$cur['id']]);
        foreach ($childStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['id'];
            if (isset($seen[$cid])) {
                continue;
            }
            $seen[$cid] = true;
            $descendants[] = ['id' => $cid, 'name' => $row['name'], 'class_name' => $row['class_name'], 'depth' => $cur['depth'] + 1];
            $stack[] = ['id' => $cid, 'depth' => $cur['depth'] + 1];
        }
        $hops++;
    }

    $propRef = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name, p.label AS property_label
         FROM cmdb_object_properties op
         JOIN cmdb_objects o ON o.id = op.object_id
         JOIN cmdb_classes c ON c.id = o.class_id
         JOIN cmdb_class_properties p ON p.id = op.property_id
         WHERE op.value_object_id = ? ORDER BY c.name, o.name"
    );
    $propRef->execute([$params[0]]);

    $incoming = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name, rt.inverse_verb AS relationship
         FROM cmdb_object_relationships r
         JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
         JOIN cmdb_objects o ON o.id = r.from_object_id
         JOIN cmdb_classes c ON c.id = o.class_id
         WHERE r.to_object_id = ? ORDER BY c.name, o.name"
    );
    $incoming->execute([$params[0]]);

    apiRespond([
        'descendants' => $descendants,
        'referenced_by_property' => array_map(function ($x) {
            return ['id' => (int)$x['id'], 'name' => $x['name'], 'class_name' => $x['class_name'], 'property' => $x['property_label']];
        }, $propRef->fetchAll(PDO::FETCH_ASSOC)),
        'incoming_relationships' => array_map(function ($x) {
            return ['id' => (int)$x['id'], 'name' => $x['name'], 'class_name' => $x['class_name'], 'relationship' => $x['relationship']];
        }, $incoming->fetchAll(PDO::FETCH_ASSOC)),
    ]);
}

// ---------------------------------------------------------------------------
// Relationships — POST /cmdb/objects/{id}/relationships, DELETE .../{rel_id}
// ---------------------------------------------------------------------------
function apiCmdbRelationshipsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $fromId = $params[0];
    apiCmdbLoadObject($conn, $fromId);

    $toId = isset($body['to_object_id']) ? (int)$body['to_object_id'] : 0;
    if ($toId <= 0) {
        apiError(422, 'missing_field', "'to_object_id' is required.");
    }
    if ($toId === $fromId) {
        apiError(422, 'invalid_field', "An object can't have a relationship with itself.");
    }
    apiCmdbLoadObject($conn, $toId);

    // Type by id or verb; must be active (save_object_relationship.php).
    if (isset($body['relationship_type_id']) && $body['relationship_type_id'] !== '') {
        $ts = $conn->prepare("SELECT id, verb FROM cmdb_relationship_types WHERE id = ? AND is_active = 1");
        $ts->execute([(int)$body['relationship_type_id']]);
    } elseif (isset($body['verb']) && trim((string)$body['verb']) !== '') {
        $ts = $conn->prepare("SELECT id, verb FROM cmdb_relationship_types WHERE verb = ? AND is_active = 1");
        $ts->execute([trim((string)$body['verb'])]);
    } else {
        apiError(422, 'missing_field', "'relationship_type_id' or 'verb' is required.");
    }
    $type = $ts->fetch(PDO::FETCH_ASSOC);
    if (!$type) {
        apiError(422, 'invalid_field', 'Relationship type not found or inactive.');
    }

    try {
        $conn->prepare(
            "INSERT INTO cmdb_object_relationships (from_object_id, to_object_id, relationship_type_id, created_datetime)
             VALUES (?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$fromId, $toId, (int)$type['id']]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) {
            apiError(409, 'conflict', 'That relationship already exists.');
        }
        throw $e;
    }

    apiRespond([
        'id'             => (int)$conn->lastInsertId(),
        'from_object_id' => $fromId,
        'to_object_id'   => $toId,
        'verb'           => $type['verb'],
    ], 201);
}

function apiCmdbRelationshipsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    [$objectId, $relId] = $params;
    apiCmdbLoadObject($conn, $objectId);
    // The relationship must involve this object (either direction).
    $stmt = $conn->prepare("DELETE FROM cmdb_object_relationships WHERE id = ? AND (from_object_id = ? OR to_object_id = ?)");
    $stmt->execute([$relId, $objectId, $objectId]);
    if ($stmt->rowCount() === 0) {
        apiError(404, 'not_found', 'Relationship not found on this object.');
    }
    apiRespond(['id' => $relId, 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// Ticket links — company-scoped, unlike the internal CMDB-side read
// ---------------------------------------------------------------------------
function apiCmdbObjectTicketsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiCmdbLoadObject($conn, $params[0]);
    // Scope the ticket rows to the key's companies (the internal
    // get_object_tickets.php reads them unscoped — the API does not).
    [$scopeSql, $scopeArgs] = apiKeyTenantFilter($conn, $apiKey, 't');
    $stmt = $conn->prepare(
        "SELECT t.id, t.ticket_number, t.subject, ts.name AS status, ts.is_closed, l.created_datetime AS linked_at
         FROM ticket_cmdb_objects l
         JOIN tickets t ON t.id = l.ticket_id
         LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         WHERE l.cmdb_object_id = ? AND t.deleted_datetime IS NULL{$scopeSql}
         ORDER BY t.created_datetime DESC"
    );
    $stmt->execute(array_merge([$params[0]], $scopeArgs));
    apiRespond(array_map(function ($t) {
        return [
            'id'            => (int)$t['id'],
            'ticket_number' => $t['ticket_number'],
            'subject'       => $t['subject'],
            'status'        => $t['status'],
            'is_closed'     => (bool)$t['is_closed'],
            'linked_at'     => apiIsoDate($t['linked_at']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiCmdbObjectTicketsLink(PDO $conn, array $apiKey, array $params, array $body): void {
    apiCmdbLoadObject($conn, $params[0]);
    $ticketId = isset($body['ticket_id']) ? (int)$body['ticket_id'] : 0;
    if ($ticketId <= 0) {
        apiError(422, 'missing_field', "'ticket_id' is required.");
    }
    // Company scope on the ticket — mirrors the tickets-side endpoint's
    // analystCanAccessTicket gate.
    if (!apiKeyCanAccessTicket($conn, $apiKey, $ticketId)) {
        apiError(404, 'not_found', 'Ticket not found.');
    }
    try {
        $conn->prepare(
            "INSERT INTO ticket_cmdb_objects (ticket_id, cmdb_object_id, created_datetime, created_by_analyst_id)
             VALUES (?, ?, UTC_TIMESTAMP(), ?)"
        )->execute([$ticketId, $params[0], (int)$apiKey['analyst_id']]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) {
            apiError(409, 'conflict', 'This ticket is already linked to this object.');
        }
        throw $e;
    }
    apiRespond(['object_id' => $params[0], 'ticket_id' => $ticketId, 'linked' => true], 201);
}

function apiCmdbObjectTicketsUnlink(PDO $conn, array $apiKey, array $params, array $body): void {
    [$objectId, $ticketId] = $params;
    apiCmdbLoadObject($conn, $objectId);
    if (!apiKeyCanAccessTicket($conn, $apiKey, $ticketId)) {
        apiError(404, 'not_found', 'Ticket not found.');
    }
    $stmt = $conn->prepare("DELETE FROM ticket_cmdb_objects WHERE cmdb_object_id = ? AND ticket_id = ?");
    $stmt->execute([$objectId, $ticketId]);
    if ($stmt->rowCount() === 0) {
        apiError(404, 'not_found', 'Link not found.');
    }
    apiRespond(['object_id' => $objectId, 'ticket_id' => $ticketId, 'unlinked' => true]);
}

// ---------------------------------------------------------------------------
// Reference: relationship types
// ---------------------------------------------------------------------------
function apiCmdbRelationshipTypesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, verb, inverse_verb, description, is_active
         FROM cmdb_relationship_types ORDER BY display_order, verb"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($t) {
        return [
            'id'           => (int)$t['id'],
            'verb'         => $t['verb'],
            'inverse_verb' => $t['inverse_verb'],
            'description'  => $t['description'],
            'is_active'    => (bool)$t['is_active'],
        ];
    }, $rows));
}
