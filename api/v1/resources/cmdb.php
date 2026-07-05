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
 *
 * Object + relationship WRITES are delegated to CmdbService
 * (includes/services/cmdb.php); the read handlers, serializers, class defs, and
 * the (API-only) ticket-link endpoints stay here.
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/cmdb.php';

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
    try {
        $res = CmdbService::saveObject($conn, ActorContext::fromApiKey($apiKey), $body);
        apiRespond(apiCmdbSerializeObject(apiCmdbLoadObject($conn, $res['id'])), 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// PATCH /cmdb/objects/{id}
// ---------------------------------------------------------------------------
function apiCmdbObjectsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = CmdbService::saveObject($conn, ActorContext::fromApiKey($apiKey), array_merge($body, ['id' => (int)$params[0]]));
        apiRespond(apiCmdbSerializeObject(apiCmdbLoadObject($conn, $res['id'])));
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// DELETE /cmdb/objects/{id} — explicit descendant-tree removal (grown installs
// have no CMDB FK cascades; see the db_verify FK group added alongside this)
// ---------------------------------------------------------------------------
function apiCmdbObjectsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = CmdbService::deleteObject($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond(['id' => $params[0], 'deleted' => true, 'deleted_descendants' => $res['deleted_descendants']]);
    } catch (ServiceError $e) { apiFailFromService($e); }
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
    try {
        $res = CmdbService::createRelationship($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
        apiRespond([
            'id'             => $res['id'],
            'from_object_id' => $params[0],
            'to_object_id'   => $res['to_object_id'],
            'verb'           => $res['verb'],
        ], 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

function apiCmdbRelationshipsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        CmdbService::deleteRelationship($conn, ActorContext::fromApiKey($apiKey), (int)$params[1], (int)$params[0]);
        apiRespond(['id' => $params[1], 'deleted' => true]);
    } catch (ServiceError $e) { apiFailFromService($e); }
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
