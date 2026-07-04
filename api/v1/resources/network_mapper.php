<?php
/**
 * FreeITSM REST API v1 — network-mapper resource (diagrams, nodes, connectors,
 * versions, suggestions).
 *
 * Built for programmatic diagram maintenance — the target consumer is a
 * discovery tool or AI agent keeping network documentation alive, so the
 * surface is deliberately richer than the module's internal endpoints:
 *
 *   - reads are fully hydrated for machine understanding: every node carries
 *     its CMDB object's name/class/effective icon/planned flag (and, with
 *     ?include_properties=true, the object's full typed property values);
 *     connectors carry both endpoints' object ids/names and the CMDB
 *     relationship verb they represent; a computed layout block gives the
 *     canvas bounding box and the pixel size of each node size class;
 *   - writes come in BOTH granularities: incremental node/connector
 *     operations with STABLE ids (the UI editor can only full-replace, which
 *     regenerates every id on save), and a full-replace mode on PATCH for
 *     wholesale redraws;
 *   - connectors can be addressed by *object* ids, not just node ids, and
 *     `cmdb_relationship_id: "auto"` binds the connector to the existing
 *     CMDB relationship between the two objects — an agent thinks in CIs,
 *     the API translates;
 *   - GET .../suggestions is the "what's missing" tool: CMDB neighbours
 *     (relationships in both directions + object_ref property links) of
 *     on-diagram objects that aren't drawn yet.
 *
 * Versioning parity with the module: a linear chain via parent_diagram_id;
 * the LEAF (no children) is the only editable version, ancestors are frozen
 * history (writes to them are a 409). POST .../versions clones the leaf
 * forward exactly like the editor's "New version". DELETE is leaf-only
 * (deleting the leaf resurfaces its parent as current) or ?chain=true for
 * the whole chain — stricter than the UI, which lets a mid-chain delete
 * corrupt the version chain.
 *
 * Deliberate improvements over the internal endpoints, documented:
 *   - unknown cmdb_object_id / relationship ids / sizes / line styles /
 *     icon keys are a 422 (save_diagram.php silently SKIPS bad nodes and
 *     connectors — machines deserve the error);
 *   - adding an object already on the diagram is a 409 unless
 *     allow_duplicate=true (the editor allows silent duplicates).
 *
 * Network tables are install-wide (no tenant_id), same posture as CMDB.
 * No audit trail exists for diagrams and none was invented.
 *
 * Depends on cmdb.php (apiCmdbClassDefs) — index.php requires it earlier.
 */

const API_NM_NODE_SIZES_PX = ['small' => 40, 'medium' => 56, 'large' => 80];
const API_NM_LINE_STYLES   = ['solid', 'dashed'];

// ---------------------------------------------------------------------------
// Loaders + guards
// ---------------------------------------------------------------------------

function apiNmDiagramSelect(): string {
    return "SELECT d.*, a.full_name AS author_name,
                   (SELECT COUNT(*) FROM network_diagrams c WHERE c.parent_diagram_id = d.id) AS child_count,
                   (SELECT COUNT(*) FROM network_diagram_nodes n WHERE n.diagram_id = d.id) AS node_count,
                   (SELECT COUNT(*) FROM network_diagram_connectors k WHERE k.diagram_id = d.id) AS connector_count
            FROM network_diagrams d
            LEFT JOIN analysts a ON a.id = d.created_by_analyst_id";
}

function apiNmLoadDiagram(PDO $conn, int $diagramId): array {
    $stmt = $conn->prepare(apiNmDiagramSelect() . " WHERE d.id = ?");
    $stmt->execute([$diagramId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Diagram not found.');
    }
    return $row;
}

/** Writes are leaf-only — a version with children is frozen history. */
function apiNmRequireLeaf(array $diagram): void {
    if ((int)$diagram['child_count'] > 0) {
        apiError(409, 'conflict', 'This is a historical version (read-only). Edit the current version — find it via GET /network-diagrams/{id}/versions.');
    }
}

function apiNmLoadNode(PDO $conn, int $diagramId, int $nodeId): array {
    $stmt = $conn->prepare("SELECT * FROM network_diagram_nodes WHERE id = ? AND diagram_id = ?");
    $stmt->execute([$nodeId, $diagramId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Node not found on this diagram.');
    }
    return $row;
}

function apiNmLoadConnector(PDO $conn, int $diagramId, int $connectorId): array {
    $stmt = $conn->prepare("SELECT * FROM network_diagram_connectors WHERE id = ? AND diagram_id = ?");
    $stmt->execute([$connectorId, $diagramId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Connector not found on this diagram.');
    }
    return $row;
}

/** The whole version chain containing $diagramId: root first. Caps mirror the UI (200 up / 500 total). */
function apiNmChainIds(PDO $conn, int $diagramId): array {
    $rootId = $diagramId;
    for ($i = 0; $i < 200; $i++) {
        $stmt = $conn->prepare("SELECT parent_diagram_id FROM network_diagrams WHERE id = ?");
        $stmt->execute([$rootId]);
        $parent = $stmt->fetchColumn();
        if ($parent === false || $parent === null) break;
        $rootId = (int)$parent;
    }
    $ids = [];
    $queue = [$rootId];
    while ($queue && count($ids) < 500) {
        $id = array_shift($queue);
        $ids[] = $id;
        $stmt = $conn->prepare("SELECT id FROM network_diagrams WHERE parent_diagram_id = ? ORDER BY id");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
            $queue[] = (int)$childId;
        }
    }
    return $ids;
}

// ---------------------------------------------------------------------------
// Serializers
// ---------------------------------------------------------------------------

function apiSerializeNmDiagram(array $r, bool $full): array {
    $out = [
        'id'                => (int)$r['id'],
        'title'             => $r['title'],
        'description'       => $r['description'],
        'version_label'     => $r['version_label'],
        'parent_diagram_id' => $r['parent_diagram_id'] === null ? null : (int)$r['parent_diagram_id'],
        'is_current'        => (int)$r['child_count'] === 0,
        'created_by'        => $r['created_by_analyst_id'] === null ? null
            : ['id' => (int)$r['created_by_analyst_id'], 'name' => $r['author_name']],
        'created_at'        => apiIsoDate($r['created_datetime']),
        'updated_at'        => apiIsoDate($r['updated_datetime']),
        'node_count'        => (int)$r['node_count'],
        'connector_count'   => (int)$r['connector_count'],
    ];
    if ($full) {
        $out['paper'] = [
            'size'        => $r['paper_size'],
            'orientation' => $r['paper_orientation'],
        ];
        // NULL slot = inherit the org-wide branding default; '' = explicit blank.
        $out['branding'] = [
            'header' => ['left' => $r['header_left'], 'center' => $r['header_center'], 'right' => $r['header_right']],
            'footer' => ['left' => $r['footer_left'], 'center' => $r['footer_center'], 'right' => $r['footer_right']],
        ];
    }
    return $out;
}

function apiNmNodeSelect(): string {
    return "SELECT n.*, o.name AS object_name, o.is_planned, c.id AS class_id, c.name AS class_name,
                   i.icon_key AS class_icon
            FROM network_diagram_nodes n
            JOIN cmdb_objects o ON o.id = n.cmdb_object_id
            JOIN cmdb_classes c ON c.id = o.class_id
            LEFT JOIN cmdb_icons i ON i.id = c.icon_id";
}

function apiSerializeNmNode(array $n): array {
    return [
        'id'            => (int)$n['id'],
        'x'             => (int)$n['x'],
        'y'             => (int)$n['y'],
        'size'          => $n['size'],
        'size_px'       => API_NM_NODE_SIZES_PX[$n['size']] ?? API_NM_NODE_SIZES_PX['medium'],
        'icon'          => $n['icon_override'] ?: ($n['class_icon'] ?: 'box'), // what actually renders
        'icon_override' => $n['icon_override'],
        'object'        => [
            'id'         => (int)$n['cmdb_object_id'],
            'name'       => $n['object_name'],
            'is_planned' => (bool)$n['is_planned'],
            'class'      => ['id' => (int)$n['class_id'], 'name' => $n['class_name'], 'icon' => $n['class_icon']],
        ],
    ];
}

/** Connectors with endpoint objects + the CMDB relationship verb they represent. */
function apiNmConnectorsForDiagram(PDO $conn, int $diagramId): array {
    $stmt = $conn->prepare(
        "SELECT k.*,
                fn.cmdb_object_id AS from_object_id, fo.name AS from_object_name,
                tn.cmdb_object_id AS to_object_id,   to2.name AS to_object_name,
                rt.verb AS rel_verb, rt.inverse_verb AS rel_inverse_verb
         FROM network_diagram_connectors k
         JOIN network_diagram_nodes fn ON fn.id = k.from_node_id
         JOIN network_diagram_nodes tn ON tn.id = k.to_node_id
         JOIN cmdb_objects fo  ON fo.id  = fn.cmdb_object_id
         JOIN cmdb_objects to2 ON to2.id = tn.cmdb_object_id
         LEFT JOIN cmdb_object_relationships r ON r.id = k.cmdb_relationship_id
         LEFT JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
         WHERE k.diagram_id = ? ORDER BY k.id"
    );
    $stmt->execute([$diagramId]);
    return array_map('apiSerializeNmConnector', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function apiSerializeNmConnector(array $k): array {
    return [
        'id'           => (int)$k['id'],
        'from'         => [
            'node_id'     => (int)$k['from_node_id'],
            'object_id'   => (int)$k['from_object_id'],
            'object_name' => $k['from_object_name'],
        ],
        'to'           => [
            'node_id'     => (int)$k['to_node_id'],
            'object_id'   => (int)$k['to_object_id'],
            'object_name' => $k['to_object_name'],
        ],
        'relationship' => $k['cmdb_relationship_id'] === null ? null : [
            'id'           => (int)$k['cmdb_relationship_id'],
            'verb'         => $k['rel_verb'],         // null if the relationship was deleted
            'inverse_verb' => $k['rel_inverse_verb'],
        ],
        'label'        => $k['label'],
        'line_style'   => $k['line_style'] ?: 'solid',
    ];
}

/** Load one connector by id fully hydrated (for single-op responses). */
function apiNmConnectorHydrated(PDO $conn, int $diagramId, int $connectorId): array {
    foreach (apiNmConnectorsForDiagram($conn, $diagramId) as $c) {
        if ($c['id'] === $connectorId) return $c;
    }
    apiError(404, 'not_found', 'Connector not found on this diagram.');
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

function apiNmValidateObjectExists(PDO $conn, int $objectId): void {
    $stmt = $conn->prepare("SELECT 1 FROM cmdb_objects WHERE id = ?");
    $stmt->execute([$objectId]);
    if (!$stmt->fetchColumn()) {
        apiError(422, 'invalid_field', "Unknown cmdb_object_id: {$objectId}");
    }
}

function apiNmValidateSize(string $size): string {
    if (!isset(API_NM_NODE_SIZES_PX[$size])) {
        apiError(422, 'invalid_field', "Unknown size '{$size}'. Valid: " . implode(', ', array_keys(API_NM_NODE_SIZES_PX)) . '.');
    }
    return $size;
}

function apiNmValidateLineStyle(string $style): string {
    if (!in_array($style, API_NM_LINE_STYLES, true)) {
        apiError(422, 'invalid_field', "Unknown line_style '{$style}'. Valid: " . implode(', ', API_NM_LINE_STYLES) . '.');
    }
    return $style;
}

function apiNmValidateIcon(PDO $conn, string $iconKey): string {
    $stmt = $conn->prepare("SELECT 1 FROM cmdb_icons WHERE icon_key = ?");
    $stmt->execute([$iconKey]);
    if (!$stmt->fetchColumn()) {
        apiError(422, 'invalid_field', "Unknown icon key '{$iconKey}'. See GET /cmdb-icons.");
    }
    return $iconKey;
}

/**
 * Resolve a connector endpoint to a node id on this diagram. Accepts
 * {prefix}_node_id or {prefix}_object_id (422 if the object isn't on the
 * diagram, or is on it more than once — use node ids to disambiguate).
 */
function apiNmResolveEndpoint(PDO $conn, int $diagramId, array $body, string $prefix): int {
    if (isset($body["{$prefix}_node_id"]) && (int)$body["{$prefix}_node_id"] > 0) {
        $nodeId = (int)$body["{$prefix}_node_id"];
        apiNmLoadNode($conn, $diagramId, $nodeId); // 404->422 shape: node must be on this diagram
        return $nodeId;
    }
    if (isset($body["{$prefix}_object_id"]) && (int)$body["{$prefix}_object_id"] > 0) {
        $objectId = (int)$body["{$prefix}_object_id"];
        $stmt = $conn->prepare("SELECT id FROM network_diagram_nodes WHERE diagram_id = ? AND cmdb_object_id = ?");
        $stmt->execute([$diagramId, $objectId]);
        $nodeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($nodeIds) === 0) {
            apiError(422, 'invalid_field', "Object {$objectId} is not on this diagram — add it first (POST .../nodes).");
        }
        if (count($nodeIds) > 1) {
            apiError(422, 'invalid_field', "Object {$objectId} appears " . count($nodeIds) . " times on this diagram — use '{$prefix}_node_id' to say which node.");
        }
        return (int)$nodeIds[0];
    }
    apiError(422, 'missing_field', "Connector needs '{$prefix}_node_id' or '{$prefix}_object_id'.");
}

/**
 * Resolve cmdb_relationship_id input: null clears, an int is validated,
 * 'auto' finds the existing CMDB relationship between the two objects
 * (either direction; null if none).
 */
function apiNmResolveRelationshipId(PDO $conn, $raw, int $fromNodeId, int $toNodeId): ?int {
    if ($raw === null || $raw === '') {
        return null;
    }
    if ($raw === 'auto') {
        $stmt = $conn->prepare(
            "SELECT r.id
             FROM cmdb_object_relationships r
             JOIN network_diagram_nodes fn ON fn.id = ?
             JOIN network_diagram_nodes tn ON tn.id = ?
             WHERE (r.from_object_id = fn.cmdb_object_id AND r.to_object_id = tn.cmdb_object_id)
                OR (r.from_object_id = tn.cmdb_object_id AND r.to_object_id = fn.cmdb_object_id)
             ORDER BY r.id LIMIT 1"
        );
        $stmt->execute([$fromNodeId, $toNodeId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }
    $relId = (int)$raw;
    $stmt = $conn->prepare("SELECT 1 FROM cmdb_object_relationships WHERE id = ?");
    $stmt->execute([$relId]);
    if (!$stmt->fetchColumn()) {
        apiError(422, 'invalid_field', "Unknown cmdb_relationship_id: {$relId}");
    }
    return $relId;
}

/**
 * Validate + normalise one node input row. Returns
 * [cmdb_object_id, x|null, y|null, size, icon_override, ref] — null x/y
 * means auto-place.
 */
function apiNmValidateNodeInput(PDO $conn, array $n, int $index): array {
    $objectId = isset($n['cmdb_object_id']) ? (int)$n['cmdb_object_id'] : 0;
    if ($objectId <= 0) {
        apiError(422, 'missing_field', "nodes[{$index}]: 'cmdb_object_id' is required.");
    }
    apiNmValidateObjectExists($conn, $objectId);
    $size = apiNmValidateSize(trim((string)($n['size'] ?? 'medium')) ?: 'medium');
    $icon = null;
    if (isset($n['icon_override']) && $n['icon_override'] !== null && trim((string)$n['icon_override']) !== '') {
        $icon = apiNmValidateIcon($conn, trim((string)$n['icon_override']));
    }
    $hasX = array_key_exists('x', $n) && $n['x'] !== null && $n['x'] !== '';
    $hasY = array_key_exists('y', $n) && $n['y'] !== null && $n['y'] !== '';
    return [
        $objectId,
        $hasX ? (int)$n['x'] : null,
        $hasY ? (int)$n['y'] : null,
        $size,
        $icon,
        isset($n['ref']) ? (string)$n['ref'] : null,
    ];
}

/**
 * Auto-placement for nodes sent without coordinates: a fresh column to the
 * right of the current bounding box, stacking downward — legible enough for
 * an agent drop; a human tidies the layout in the editor.
 */
function apiNmAutoPlacer(PDO $conn, int $diagramId): callable {
    $stmt = $conn->prepare("SELECT COALESCE(MAX(x), -60) AS max_x, COALESCE(MIN(y), 80) AS min_y FROM network_diagram_nodes WHERE diagram_id = ?");
    $stmt->execute([$diagramId]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    $colX = (int)$b['max_x'] + 160;
    $rowY = (int)$b['min_y'];
    $i = 0;
    return function () use ($colX, &$rowY, &$i): array {
        $pos = [$colX + 180 * intdiv($i, 8), $rowY + 110 * ($i % 8)];
        $i++;
        return $pos;
    };
}

// ---------------------------------------------------------------------------
// Diagrams
// ---------------------------------------------------------------------------

// GET /network-diagrams — current versions by default; ?all_versions=true for everything.
function apiNmDiagramsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];
    if (!isset($_GET['all_versions']) || $_GET['all_versions'] !== 'true') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM network_diagrams c2 WHERE c2.parent_diagram_id = d.id)';
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(d.title LIKE ? OR d.description LIKE ?)';
        $q = '%' . trim($_GET['q']) . '%';
        $args[] = $q;
        $args[] = $q;
    }
    if (isset($_GET['contains_object_id']) && (int)$_GET['contains_object_id'] > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM network_diagram_nodes nx WHERE nx.diagram_id = d.id AND nx.cmdb_object_id = ?)';
        $args[]  = (int)$_GET['contains_object_id'];
    }
    if (isset($_GET['created_by']) && (int)$_GET['created_by'] > 0) {
        $where[] = 'd.created_by_analyst_id = ?';
        $args[]  = (int)$_GET['created_by'];
    }
    if (isset($_GET['updated_since']) && $_GET['updated_since'] !== '') {
        $where[] = 'd.updated_datetime >= ?';
        $args[]  = apiParseDate($_GET['updated_since'], 'updated_since');
    }

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM network_diagrams d WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(
        apiNmDiagramSelect() . " WHERE $whereSql
         ORDER BY d.updated_datetime DESC, d.id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($args);
    apiRespond(array_map(function ($r) {
        return apiSerializeNmDiagram($r, false);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// GET /network-diagrams/{id} — fully hydrated; ?include_properties=true adds each object's CI properties.
function apiNmDiagramsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $out = apiSerializeNmDiagram(apiNmLoadDiagram($conn, $params[0]), true);

    $stmt = $conn->prepare(apiNmNodeSelect() . " WHERE n.diagram_id = ? ORDER BY n.id");
    $stmt->execute([$params[0]]);
    $nodeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $nodes = array_map('apiSerializeNmNode', $nodeRows);

    if (isset($_GET['include_properties']) && $_GET['include_properties'] === 'true' && $nodeRows) {
        $props = apiNmObjectProperties($conn, $nodeRows);
        foreach ($nodes as &$n) {
            $n['object']['properties'] = $props[$n['object']['id']] ?? [];
        }
        unset($n);
    }

    $out['nodes']      = $nodes;
    $out['connectors'] = apiNmConnectorsForDiagram($conn, $params[0]);

    // Layout metadata so an agent can reason about the canvas: raw x/y are
    // the node's top-left; add each size class's pixel footprint.
    $bounds = null;
    foreach ($nodes as $n) {
        $px = $n['size_px'];
        $bounds = [
            'min_x' => min($bounds['min_x'] ?? $n['x'], $n['x']),
            'min_y' => min($bounds['min_y'] ?? $n['y'], $n['y']),
            'max_x' => max($bounds['max_x'] ?? $n['x'] + $px, $n['x'] + $px),
            'max_y' => max($bounds['max_y'] ?? $n['y'] + $px, $n['y'] + $px),
        ];
    }
    $out['layout'] = [
        'bounds'        => $bounds,
        'width'         => $bounds ? $bounds['max_x'] - $bounds['min_x'] : 0,
        'height'        => $bounds ? $bounds['max_y'] - $bounds['min_y'] : 0,
        'node_sizes_px' => API_NM_NODE_SIZES_PX,
    ];

    apiRespond($out);
}

/** Typed property values for the distinct objects behind a diagram's nodes, keyed by object id. */
function apiNmObjectProperties(PDO $conn, array $nodeRows): array {
    $byObject = [];
    $classByObject = [];
    foreach ($nodeRows as $r) {
        $classByObject[(int)$r['cmdb_object_id']] = (int)$r['class_id'];
    }
    $objectIds = array_keys($classByObject);
    $ph = implode(',', array_fill(0, count($objectIds), '?'));
    $vals = $conn->prepare(
        "SELECT op.*, ro.name AS ref_name
         FROM cmdb_object_properties op
         LEFT JOIN cmdb_objects ro ON ro.id = op.value_object_id
         WHERE op.object_id IN ($ph)"
    );
    $vals->execute($objectIds);
    $valuesByObjectProp = [];
    foreach ($vals->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $valuesByObjectProp[(int)$v['object_id']][(int)$v['property_id']] = $v;
    }
    foreach ($classByObject as $objectId => $classId) {
        $props = [];
        foreach (apiCmdbClassDefs($conn, $classId) as $key => $def) {
            $v = $valuesByObjectProp[$objectId][(int)$def['id']] ?? null;
            $value = null;
            if ($v) {
                switch ($def['property_type']) {
                    case 'text':
                    case 'dropdown':   $value = $v['value_text']; break;
                    case 'number':     $value = $v['value_number'] !== null ? (float)$v['value_number'] : null; break;
                    case 'date':       $value = apiIsoDate($v['value_date']); break;
                    case 'boolean':    $value = $v['value_boolean'] !== null ? ((int)$v['value_boolean'] === 1) : null; break;
                    case 'object_ref': $value = $v['value_object_id'] !== null
                        ? ['id' => (int)$v['value_object_id'], 'name' => $v['ref_name']] : null; break;
                }
            }
            $props[] = ['property_key' => $key, 'label' => $def['label'], 'type' => $def['property_type'], 'value' => $value];
        }
        $byObject[$objectId] = $props;
    }
    return $byObject;
}

// POST /network-diagrams — create, optionally with initial contents in one call.
function apiNmDiagramsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') {
        apiError(422, 'missing_field', "'title' is required.");
    }
    [$paperSize, $paperOrientation] = apiNmValidatePaper($body, null);

    $conn->beginTransaction();
    try {
        $conn->prepare(
            "INSERT INTO network_diagrams
             (parent_diagram_id, title, description, version_label, created_by_analyst_id,
              paper_size, paper_orientation,
              header_left, header_center, header_right, footer_left, footer_center, footer_right,
              created_datetime, updated_datetime)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            $title,
            ($v = trim((string)($body['description'] ?? ''))) !== '' ? $v : null,
            ($v = trim((string)($body['version_label'] ?? 'v1'))) !== '' ? substr($v, 0, 50) : null,
            (int)$apiKey['analyst_id'],
            $paperSize,
            $paperOrientation,
            apiNmBrandingSlot($body, 'header', 'left', null),
            apiNmBrandingSlot($body, 'header', 'center', null),
            apiNmBrandingSlot($body, 'header', 'right', null),
            apiNmBrandingSlot($body, 'footer', 'left', null),
            apiNmBrandingSlot($body, 'footer', 'center', null),
            apiNmBrandingSlot($body, 'footer', 'right', null),
        ]);
        $diagramId = (int)$conn->lastInsertId();

        if ((isset($body['nodes']) && is_array($body['nodes'])) || (isset($body['connectors']) && is_array($body['connectors']))) {
            apiNmReplaceContents($conn, $diagramId, $body['nodes'] ?? [], $body['connectors'] ?? []);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    $params[0] = $diagramId;
    $_GET['include_properties'] = 'false';
    apiNmRespondFull($conn, $diagramId, 201);
}

/** paper_size / paper_orientation whitelists (save_diagram.php's). */
function apiNmValidatePaper(array $body, ?array $current): array {
    $sizes = ['A4', 'A3', 'A2', 'Letter', 'Tabloid'];
    $size = array_key_exists('paper_size', $body)
        ? (($v = trim((string)($body['paper_size'] ?? ''))) !== '' ? $v : null)
        : ($current['paper_size'] ?? null);
    if ($size !== null && !in_array($size, $sizes, true)) {
        apiError(422, 'invalid_field', "Unknown paper_size '{$size}'. Valid: " . implode(', ', $sizes) . ' (null = no paper overlay).');
    }
    $orientation = array_key_exists('paper_orientation', $body)
        ? (($v = trim((string)($body['paper_orientation'] ?? ''))) !== '' ? $v : null)
        : ($current['paper_orientation'] ?? null);
    if ($orientation !== null && !in_array($orientation, ['portrait', 'landscape'], true)) {
        apiError(422, 'invalid_field', "paper_orientation must be 'portrait' or 'landscape'.");
    }
    return [$size, $orientation];
}

/**
 * Read a branding slot from body.branding.header/footer.{left,center,right}.
 * NULL = inherit org default, '' = explicit blank — both are meaningful, so
 * the fallback only applies when the key is absent entirely.
 */
function apiNmBrandingSlot(array $body, string $band, string $slot, ?string $current): ?string {
    if (!isset($body['branding']) || !is_array($body['branding'])
        || !isset($body['branding'][$band]) || !is_array($body['branding'][$band])
        || !array_key_exists($slot, $body['branding'][$band])) {
        return $current;
    }
    $v = $body['branding'][$band][$slot];
    return $v === null ? null : substr((string)$v, 0, 200);
}

/**
 * Full contents replace (create-with-contents + PATCH replace mode).
 * Nodes may carry a 'ref' (any string) that connectors reference via
 * from_ref/to_ref; from_object_id/to_object_id also work when the object
 * appears exactly once in the incoming set. Strict 422s where the UI's
 * save_diagram.php silently skips.
 */
function apiNmReplaceContents(PDO $conn, int $diagramId, array $nodesIn, array $connectorsIn): void {
    $conn->prepare("DELETE FROM network_diagram_connectors WHERE diagram_id = ?")->execute([$diagramId]);
    $conn->prepare("DELETE FROM network_diagram_nodes WHERE diagram_id = ?")->execute([$diagramId]);

    $place = null;
    $refMap = [];       // ref -> new node id
    $objectMap = [];    // object id -> [new node ids]
    $insertNode = $conn->prepare(
        "INSERT INTO network_diagram_nodes (diagram_id, cmdb_object_id, x, y, size, icon_override) VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach (array_values($nodesIn) as $i => $n) {
        if (!is_array($n)) {
            apiError(422, 'invalid_field', "nodes[{$i}] must be an object.");
        }
        [$objectId, $x, $y, $size, $icon, $ref] = apiNmValidateNodeInput($conn, $n, $i);
        if ($x === null || $y === null) {
            if ($place === null) {
                $place = apiNmAutoPlacer($conn, $diagramId);
            }
            [$ax, $ay] = $place();
            $x = $x ?? $ax;
            $y = $y ?? $ay;
        }
        $insertNode->execute([$diagramId, $objectId, $x, $y, $size, $icon]);
        $newId = (int)$conn->lastInsertId();
        if ($ref !== null) {
            $refMap[$ref] = $newId;
        }
        $objectMap[$objectId][] = $newId;
    }

    $insertConn = $conn->prepare(
        "INSERT INTO network_diagram_connectors (diagram_id, from_node_id, to_node_id, cmdb_relationship_id, label, line_style) VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach (array_values($connectorsIn) as $i => $k) {
        if (!is_array($k)) {
            apiError(422, 'invalid_field', "connectors[{$i}] must be an object.");
        }
        $resolve = function (string $prefix) use ($k, $refMap, $objectMap, $i): int {
            if (isset($k["{$prefix}_ref"]) && $k["{$prefix}_ref"] !== '') {
                $ref = (string)$k["{$prefix}_ref"];
                if (!isset($refMap[$ref])) {
                    apiError(422, 'invalid_field', "connectors[{$i}]: '{$prefix}_ref' \"{$ref}\" doesn't match any node's 'ref' in this payload.");
                }
                return $refMap[$ref];
            }
            if (isset($k["{$prefix}_object_id"]) && (int)$k["{$prefix}_object_id"] > 0) {
                $objectId = (int)$k["{$prefix}_object_id"];
                $nodeIds = $objectMap[$objectId] ?? [];
                if (count($nodeIds) === 0) {
                    apiError(422, 'invalid_field', "connectors[{$i}]: object {$objectId} isn't among the nodes in this payload.");
                }
                if (count($nodeIds) > 1) {
                    apiError(422, 'invalid_field', "connectors[{$i}]: object {$objectId} appears " . count($nodeIds) . " times — use '{$prefix}_ref' instead.");
                }
                return $nodeIds[0];
            }
            apiError(422, 'missing_field', "connectors[{$i}]: needs '{$prefix}_ref' or '{$prefix}_object_id'.");
        };
        $fromId = $resolve('from');
        $toId   = $resolve('to');
        if ($fromId === $toId) {
            apiError(422, 'invalid_field', "connectors[{$i}]: cannot connect a node to itself.");
        }
        $relId = apiNmResolveRelationshipId($conn, $k['cmdb_relationship_id'] ?? null, $fromId, $toId);
        $insertConn->execute([
            $diagramId, $fromId, $toId, $relId,
            ($v = trim((string)($k['label'] ?? ''))) !== '' ? substr($v, 0, 255) : null,
            apiNmValidateLineStyle(trim((string)($k['line_style'] ?? 'solid')) ?: 'solid'),
        ]);
    }
}

/** Respond with the full hydrated diagram (shared by create/update/version). */
function apiNmRespondFull(PDO $conn, int $diagramId, int $status = 200): void {
    $out = apiSerializeNmDiagram(apiNmLoadDiagram($conn, $diagramId), true);
    $stmt = $conn->prepare(apiNmNodeSelect() . " WHERE n.diagram_id = ? ORDER BY n.id");
    $stmt->execute([$diagramId]);
    $out['nodes']      = array_map('apiSerializeNmNode', $stmt->fetchAll(PDO::FETCH_ASSOC));
    $out['connectors'] = apiNmConnectorsForDiagram($conn, $diagramId);
    apiRespond($out, $status);
}

// PATCH /network-diagrams/{id} — partial metadata; sending nodes/connectors = full contents replace.
function apiNmDiagramsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $current = apiNmLoadDiagram($conn, $params[0]);
    apiNmRequireLeaf($current);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }

    $title = array_key_exists('title', $body) ? trim((string)$body['title']) : $current['title'];
    if ($title === '') {
        apiError(422, 'invalid_field', "'title' cannot be empty.");
    }
    [$paperSize, $paperOrientation] = apiNmValidatePaper($body, $current);

    $conn->beginTransaction();
    try {
        $conn->prepare(
            "UPDATE network_diagrams
             SET title = ?, description = ?, version_label = ?, paper_size = ?, paper_orientation = ?,
                 header_left = ?, header_center = ?, header_right = ?,
                 footer_left = ?, footer_center = ?, footer_right = ?,
                 updated_datetime = UTC_TIMESTAMP()
             WHERE id = ?"
        )->execute([
            $title,
            array_key_exists('description', $body)
                ? (($v = trim((string)($body['description'] ?? ''))) !== '' ? $v : null)
                : $current['description'],
            array_key_exists('version_label', $body)
                ? (($v = trim((string)($body['version_label'] ?? ''))) !== '' ? substr($v, 0, 50) : null)
                : $current['version_label'],
            $paperSize,
            $paperOrientation,
            apiNmBrandingSlot($body, 'header', 'left',   $current['header_left']),
            apiNmBrandingSlot($body, 'header', 'center', $current['header_center']),
            apiNmBrandingSlot($body, 'header', 'right',  $current['header_right']),
            apiNmBrandingSlot($body, 'footer', 'left',   $current['footer_left']),
            apiNmBrandingSlot($body, 'footer', 'center', $current['footer_center']),
            apiNmBrandingSlot($body, 'footer', 'right',  $current['footer_right']),
            $params[0],
        ]);

        // Contents replace mode: either key present replaces BOTH sets (a
        // node replace regenerates every node id, so old connectors can't
        // survive it — send both, or use the incremental endpoints).
        if ((isset($body['nodes']) && is_array($body['nodes'])) || (isset($body['connectors']) && is_array($body['connectors']))) {
            apiNmReplaceContents($conn, $params[0], $body['nodes'] ?? [], $body['connectors'] ?? []);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    apiNmRespondFull($conn, $params[0]);
}

// DELETE /network-diagrams/{id} — leaf-only (parent resurfaces as current); ?chain=true deletes the whole chain.
function apiNmDiagramsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    $diagram = apiNmLoadDiagram($conn, $params[0]);
    $chain = isset($_GET['chain']) && $_GET['chain'] === 'true';

    if (!$chain && (int)$diagram['child_count'] > 0) {
        apiError(409, 'conflict', 'This version has newer versions after it — deleting it would corrupt the chain. Delete the current (leaf) version, or pass ?chain=true to delete the whole chain.');
    }

    $ids = $chain ? apiNmChainIds($conn, $params[0]) : [$params[0]];
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $conn->beginTransaction();
    try {
        $conn->prepare("DELETE FROM network_diagram_connectors WHERE diagram_id IN ($ph)")->execute($ids);
        $conn->prepare("DELETE FROM network_diagram_nodes WHERE diagram_id IN ($ph)")->execute($ids);
        // Leaf-to-root so the parent self-FK never blocks.
        foreach (array_reverse($ids) as $id) {
            $conn->prepare("DELETE FROM network_diagrams WHERE id = ?")->execute([$id]);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    apiRespond(['id' => $params[0], 'deleted' => true, 'versions_deleted' => count($ids)]);
}

// ---------------------------------------------------------------------------
// Versions
// ---------------------------------------------------------------------------

// GET /network-diagrams/{id}/versions — the whole chain, oldest first.
function apiNmVersionsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiNmLoadDiagram($conn, $params[0]);
    $ids = apiNmChainIds($conn, $params[0]);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(apiNmDiagramSelect() . " WHERE d.id IN ($ph) ORDER BY d.created_datetime, d.id");
    $stmt->execute($ids);
    apiRespond(array_map(function ($r) {
        return apiSerializeNmDiagram($r, false);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// POST /network-diagrams/{id}/versions — clone the leaf forward (the editor's "New version").
function apiNmVersionsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $parent = apiNmLoadDiagram($conn, $params[0]);
    apiNmRequireLeaf($parent); // can only version forward from the current version

    $title = trim((string)($body['title'] ?? '')) ?: $parent['title'];
    $conn->beginTransaction();
    try {
        $conn->prepare(
            "INSERT INTO network_diagrams
             (parent_diagram_id, title, description, version_label, created_by_analyst_id,
              paper_size, paper_orientation,
              header_left, header_center, header_right, footer_left, footer_center, footer_right,
              created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            $params[0],
            $title,
            array_key_exists('description', $body)
                ? (($v = trim((string)($body['description'] ?? ''))) !== '' ? $v : null)
                : $parent['description'],
            array_key_exists('version_label', $body)
                ? (($v = trim((string)($body['version_label'] ?? ''))) !== '' ? substr($v, 0, 50) : null)
                : $parent['version_label'],
            (int)$apiKey['analyst_id'],
            $parent['paper_size'], $parent['paper_orientation'],
            $parent['header_left'], $parent['header_center'], $parent['header_right'],
            $parent['footer_left'], $parent['footer_center'], $parent['footer_right'],
        ]);
        $newId = (int)$conn->lastInsertId();

        // Clone nodes with an old->new id map, then connectors remapped.
        $nodeMap = [];
        $nodes = $conn->prepare("SELECT * FROM network_diagram_nodes WHERE diagram_id = ? ORDER BY id");
        $nodes->execute([$params[0]]);
        $insertNode = $conn->prepare(
            "INSERT INTO network_diagram_nodes (diagram_id, cmdb_object_id, x, y, size, icon_override) VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($nodes->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $insertNode->execute([$newId, (int)$n['cmdb_object_id'], (int)$n['x'], (int)$n['y'], $n['size'], $n['icon_override']]);
            $nodeMap[(int)$n['id']] = (int)$conn->lastInsertId();
        }
        $conns = $conn->prepare("SELECT * FROM network_diagram_connectors WHERE diagram_id = ? ORDER BY id");
        $conns->execute([$params[0]]);
        $insertConn = $conn->prepare(
            "INSERT INTO network_diagram_connectors (diagram_id, from_node_id, to_node_id, cmdb_relationship_id, label, line_style) VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($conns->fetchAll(PDO::FETCH_ASSOC) as $k) {
            $insertConn->execute([
                $newId, $nodeMap[(int)$k['from_node_id']], $nodeMap[(int)$k['to_node_id']],
                $k['cmdb_relationship_id'] !== null ? (int)$k['cmdb_relationship_id'] : null,
                $k['label'], $k['line_style'],
            ]);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    apiNmRespondFull($conn, $newId, 201);
}

// ---------------------------------------------------------------------------
// Suggestions — the "what's missing from this diagram" tool
// ---------------------------------------------------------------------------

// GET /network-diagrams/{id}/suggestions — CMDB neighbours of on-diagram objects not yet drawn.
function apiNmSuggestionsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiNmLoadDiagram($conn, $params[0]);
    $stmt = $conn->prepare("SELECT DISTINCT cmdb_object_id FROM network_diagram_nodes WHERE diagram_id = ?");
    $stmt->execute([$params[0]]);
    $onDiagram = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$onDiagram) {
        apiRespond([]);
    }

    $scope = $onDiagram;
    if (isset($_GET['object_id']) && (int)$_GET['object_id'] > 0) {
        $objectId = (int)$_GET['object_id'];
        if (!in_array($objectId, $onDiagram, true)) {
            apiError(422, 'invalid_parameter', "Object {$objectId} is not on this diagram.");
        }
        $scope = [$objectId];
    }
    $limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;

    $ph    = implode(',', array_fill(0, count($scope), '?'));
    $phAll = implode(',', array_fill(0, count($onDiagram), '?'));

    // Neighbours via relationships (both directions) and object_ref property
    // links (both directions — one more than the UI's get_related_objects,
    // which only finds objects referencing the seed).
    $sql = "
        SELECT r.to_object_id AS object_id, r.from_object_id AS via_object_id,
               'relationship' AS kind, rt.verb AS label, r.id AS relationship_id
        FROM cmdb_object_relationships r
        JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
        WHERE r.from_object_id IN ($ph) AND r.to_object_id NOT IN ($phAll)
        UNION ALL
        SELECT r.from_object_id, r.to_object_id, 'relationship', rt.inverse_verb, r.id
        FROM cmdb_object_relationships r
        JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
        WHERE r.to_object_id IN ($ph) AND r.from_object_id NOT IN ($phAll)
        UNION ALL
        SELECT op.object_id, op.value_object_id, 'property', cp.label, NULL
        FROM cmdb_object_properties op
        JOIN cmdb_class_properties cp ON cp.id = op.property_id
        WHERE op.value_object_id IN ($ph) AND op.object_id NOT IN ($phAll)
        UNION ALL
        SELECT op.value_object_id, op.object_id, 'property', cp.label, NULL
        FROM cmdb_object_properties op
        JOIN cmdb_class_properties cp ON cp.id = op.property_id
        WHERE op.object_id IN ($ph) AND op.value_object_id IS NOT NULL AND op.value_object_id NOT IN ($phAll)";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge($scope, $onDiagram, $scope, $onDiagram, $scope, $onDiagram, $scope, $onDiagram));

    // Group by suggested object; each path becomes a 'via' entry.
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $oid = (int)$row['object_id'];
        $grouped[$oid][] = [
            'kind'            => $row['kind'],
            'from_object_id'  => (int)$row['via_object_id'],
            'label'           => $row['label'],
            'relationship_id' => $row['relationship_id'] !== null ? (int)$row['relationship_id'] : null,
        ];
    }
    if (!$grouped) {
        apiRespond([]);
    }

    $ids = array_slice(array_keys($grouped), 0, $limit);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $objs = $conn->prepare(
        "SELECT o.id, o.name, o.is_planned, c.id AS class_id, c.name AS class_name, i.icon_key AS class_icon
         FROM cmdb_objects o
         JOIN cmdb_classes c ON c.id = o.class_id
         LEFT JOIN cmdb_icons i ON i.id = c.icon_id
         WHERE o.id IN ($ph) ORDER BY o.name"
    );
    $objs->execute($ids);
    apiRespond(array_map(function ($o) use ($grouped) {
        return [
            'object' => [
                'id'         => (int)$o['id'],
                'name'       => $o['name'],
                'is_planned' => (bool)$o['is_planned'],
                'class'      => ['id' => (int)$o['class_id'], 'name' => $o['class_name'], 'icon' => $o['class_icon']],
            ],
            'via' => $grouped[(int)$o['id']],
        ];
    }, $objs->fetchAll(PDO::FETCH_ASSOC)));
}

// ---------------------------------------------------------------------------
// Nodes — incremental operations with stable ids
// ---------------------------------------------------------------------------

// POST /network-diagrams/{id}/nodes — add one node (object) or a batch ({"nodes": [...]}).
function apiNmNodesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $diagram = apiNmLoadDiagram($conn, $params[0]);
    apiNmRequireLeaf($diagram);

    $batch = isset($body['nodes']) && is_array($body['nodes']);
    $nodesIn = $batch ? array_values($body['nodes']) : [$body];
    if (!$nodesIn) {
        apiError(422, 'missing_field', 'No nodes to add.');
    }
    $allowDuplicate = !empty($body['allow_duplicate']);

    $validated = [];
    foreach ($nodesIn as $i => $n) {
        if (!is_array($n)) {
            apiError(422, 'invalid_field', "nodes[{$i}] must be an object.");
        }
        $validated[] = apiNmValidateNodeInput($conn, $n, $i);
    }
    if (!$allowDuplicate) {
        foreach ($validated as [$objectId]) {
            $dup = $conn->prepare("SELECT id FROM network_diagram_nodes WHERE diagram_id = ? AND cmdb_object_id = ?");
            $dup->execute([$params[0], $objectId]);
            $existing = $dup->fetchColumn();
            if ($existing) {
                apiError(409, 'conflict', "Object {$objectId} is already on this diagram (node {$existing}). Pass allow_duplicate=true to add it again.");
            }
        }
    }

    $conn->beginTransaction();
    try {
        $place = apiNmAutoPlacer($conn, $params[0]);
        $insert = $conn->prepare(
            "INSERT INTO network_diagram_nodes (diagram_id, cmdb_object_id, x, y, size, icon_override) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $newIds = [];
        foreach ($validated as [$objectId, $x, $y, $size, $icon]) {
            if ($x === null || $y === null) {
                [$ax, $ay] = $place();
                $x = $x ?? $ax;
                $y = $y ?? $ay;
            }
            $insert->execute([$params[0], $objectId, $x, $y, $size, $icon]);
            $newIds[] = (int)$conn->lastInsertId();
        }
        $conn->prepare("UPDATE network_diagrams SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$params[0]]);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    $ph = implode(',', array_fill(0, count($newIds), '?'));
    $stmt = $conn->prepare(apiNmNodeSelect() . " WHERE n.id IN ($ph) ORDER BY n.id");
    $stmt->execute($newIds);
    $created = array_map('apiSerializeNmNode', $stmt->fetchAll(PDO::FETCH_ASSOC));
    apiRespond($batch ? $created : $created[0], 201);
}

// PATCH /network-diagrams/{id}/nodes/{node_id} — move / resize / re-icon.
function apiNmNodesUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $diagram = apiNmLoadDiagram($conn, $params[0]);
    apiNmRequireLeaf($diagram);
    $node = apiNmLoadNode($conn, $params[0], $params[1]);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }

    $size = array_key_exists('size', $body)
        ? apiNmValidateSize(trim((string)$body['size']) ?: 'medium')
        : $node['size'];
    $icon = $node['icon_override'];
    if (array_key_exists('icon_override', $body)) {
        $icon = ($body['icon_override'] === null || trim((string)$body['icon_override']) === '')
            ? null
            : apiNmValidateIcon($conn, trim((string)$body['icon_override']));
    }

    $conn->prepare("UPDATE network_diagram_nodes SET x = ?, y = ?, size = ?, icon_override = ? WHERE id = ?")
         ->execute([
             array_key_exists('x', $body) ? (int)$body['x'] : (int)$node['x'],
             array_key_exists('y', $body) ? (int)$body['y'] : (int)$node['y'],
             $size,
             $icon,
             $params[1],
         ]);
    $conn->prepare("UPDATE network_diagrams SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$params[0]]);

    $stmt = $conn->prepare(apiNmNodeSelect() . " WHERE n.id = ?");
    $stmt->execute([$params[1]]);
    apiRespond(apiSerializeNmNode($stmt->fetch(PDO::FETCH_ASSOC)));
}

// DELETE /network-diagrams/{id}/nodes/{node_id} — the node and its connectors.
function apiNmNodesDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    $diagram = apiNmLoadDiagram($conn, $params[0]);
    apiNmRequireLeaf($diagram);
    apiNmLoadNode($conn, $params[0], $params[1]);

    $conn->beginTransaction();
    try {
        $del = $conn->prepare("DELETE FROM network_diagram_connectors WHERE from_node_id = ? OR to_node_id = ?");
        $del->execute([$params[1], $params[1]]);
        $connectorsDeleted = $del->rowCount();
        $conn->prepare("DELETE FROM network_diagram_nodes WHERE id = ?")->execute([$params[1]]);
        $conn->prepare("UPDATE network_diagrams SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$params[0]]);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    apiRespond(['id' => $params[1], 'deleted' => true, 'connectors_deleted' => $connectorsDeleted]);
}

// ---------------------------------------------------------------------------
// Connectors — incremental operations
// ---------------------------------------------------------------------------

// POST /network-diagrams/{id}/connectors — endpoints by node id or object id.
function apiNmConnectorsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $diagram = apiNmLoadDiagram($conn, $params[0]);
    apiNmRequireLeaf($diagram);

    $fromId = apiNmResolveEndpoint($conn, $params[0], $body, 'from');
    $toId   = apiNmResolveEndpoint($conn, $params[0], $body, 'to');
    if ($fromId === $toId) {
        apiError(422, 'invalid_field', 'Cannot connect a node to itself.');
    }
    if (empty($body['allow_duplicate'])) {
        $dup = $conn->prepare(
            "SELECT id FROM network_diagram_connectors
             WHERE diagram_id = ? AND ((from_node_id = ? AND to_node_id = ?) OR (from_node_id = ? AND to_node_id = ?))"
        );
        $dup->execute([$params[0], $fromId, $toId, $toId, $fromId]);
        $existing = $dup->fetchColumn();
        if ($existing) {
            apiError(409, 'conflict', "These nodes are already connected (connector {$existing}). Pass allow_duplicate=true to add another.");
        }
    }
    $relId = apiNmResolveRelationshipId($conn, $body['cmdb_relationship_id'] ?? null, $fromId, $toId);

    $conn->prepare(
        "INSERT INTO network_diagram_connectors (diagram_id, from_node_id, to_node_id, cmdb_relationship_id, label, line_style) VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $params[0], $fromId, $toId, $relId,
        ($v = trim((string)($body['label'] ?? ''))) !== '' ? substr($v, 0, 255) : null,
        apiNmValidateLineStyle(trim((string)($body['line_style'] ?? 'solid')) ?: 'solid'),
    ]);
    $newId = (int)$conn->lastInsertId();
    $conn->prepare("UPDATE network_diagrams SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$params[0]]);

    apiRespond(apiNmConnectorHydrated($conn, $params[0], $newId), 201);
}

// PATCH /network-diagrams/{id}/connectors/{connector_id} — label / style / provenance.
function apiNmConnectorsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $diagram = apiNmLoadDiagram($conn, $params[0]);
    apiNmRequireLeaf($diagram);
    $connector = apiNmLoadConnector($conn, $params[0], $params[1]);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }

    $relId = $connector['cmdb_relationship_id'] !== null ? (int)$connector['cmdb_relationship_id'] : null;
    if (array_key_exists('cmdb_relationship_id', $body)) {
        $relId = apiNmResolveRelationshipId(
            $conn, $body['cmdb_relationship_id'],
            (int)$connector['from_node_id'], (int)$connector['to_node_id']
        );
    }

    $conn->prepare("UPDATE network_diagram_connectors SET cmdb_relationship_id = ?, label = ?, line_style = ? WHERE id = ?")
         ->execute([
             $relId,
             array_key_exists('label', $body)
                 ? (($v = trim((string)($body['label'] ?? ''))) !== '' ? substr($v, 0, 255) : null)
                 : $connector['label'],
             array_key_exists('line_style', $body)
                 ? apiNmValidateLineStyle(trim((string)$body['line_style']) ?: 'solid')
                 : ($connector['line_style'] ?: 'solid'),
             $params[1],
         ]);
    $conn->prepare("UPDATE network_diagrams SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$params[0]]);

    apiRespond(apiNmConnectorHydrated($conn, $params[0], $params[1]));
}

// DELETE /network-diagrams/{id}/connectors/{connector_id}
function apiNmConnectorsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    $diagram = apiNmLoadDiagram($conn, $params[0]);
    apiNmRequireLeaf($diagram);
    apiNmLoadConnector($conn, $params[0], $params[1]);

    $conn->prepare("DELETE FROM network_diagram_connectors WHERE id = ?")->execute([$params[1]]);
    $conn->prepare("UPDATE network_diagrams SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$params[0]]);
    apiRespond(['id' => $params[1], 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// Reference — the icon catalogue (valid icon_override values)
// ---------------------------------------------------------------------------

function apiCmdbIconsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT icon_key, label, is_active FROM cmdb_icons ORDER BY display_order, label"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($i) {
        return [
            'icon_key'  => $i['icon_key'],
            'label'     => $i['label'],
            'is_active' => (bool)$i['is_active'],
        ];
    }, $rows));
}
