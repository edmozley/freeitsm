<?php
/**
 * NetworkMapperService — the shared write rules for network diagrams: create,
 * full-contents save (metadata + nodes + connectors replace), delete
 * (leaf/chain) and version fork.
 *
 * Shared by the UI endpoints (api/network-mapper/*.php) and the REST API
 * (api/v1/resources/network_mapper.php). Each caller passes an ActorContext +
 * canonical input; this layer validates + writes and returns the affected id(s)
 * or throws ServiceError. It never emits HTTP.
 *
 * SCOPE: the diagram-level writes (create / save / delete / version) that are
 * duplicated between the UI and API. The API's *incremental* node/connector
 * endpoints (POST/PATCH/DELETE .../nodes|connectors) are API-only (no UI twin)
 * and keep their own handlers in the resource — this service is not involved.
 *
 * Canonical behaviour = the API resource's: writes are leaf-only (a version
 * with children is frozen — 409), the full-replace validates every node
 * (cmdb_object_id exists, size/icon valid) and every connector (endpoints
 * resolvable, no self-link, line_style valid, relationship id / 'auto'), and
 * nodes without coordinates auto-place. The raw UI's save_diagram.php was loose
 * (it silently skipped invalid rows); the UI now adopts the strict validation.
 *
 * Property/branding input shapes: the service reads the API's canonical forms
 * (nested `branding.{band}.{slot}`, node `ref` + connector `from_ref/to_ref`);
 * the UI adapter maps its flat branding keys + node-id refs onto them.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';   // CMDB objects on a diagram are company-scoped
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class NetworkMapperService
{
    const NODE_SIZES  = ['small' => 40, 'medium' => 56, 'large' => 80];
    const LINE_STYLES = ['solid', 'dashed'];

    // ======================================================================
    //  Diagrams
    // ======================================================================

    /** Create a new diagram (v1 of a chain), optionally with contents. Returns the id. */
    public static function createDiagram(PDO $conn, ActorContext $ctx, array $in): int
    {
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') {
            throw new ServiceError('validation', 'missing_field', "'title' is required.");
        }
        [$paperSize, $paperOrientation] = self::validatePaper($in, null);

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
                ($v = trim((string)($in['description'] ?? ''))) !== '' ? $v : null,
                ($v = trim((string)($in['version_label'] ?? 'v1'))) !== '' ? substr($v, 0, 50) : null,
                $ctx->actorId,
                $paperSize,
                $paperOrientation,
                self::brandingSlot($in, 'header', 'left', null),
                self::brandingSlot($in, 'header', 'center', null),
                self::brandingSlot($in, 'header', 'right', null),
                self::brandingSlot($in, 'footer', 'left', null),
                self::brandingSlot($in, 'footer', 'center', null),
                self::brandingSlot($in, 'footer', 'right', null),
            ]);
            $diagramId = (int)$conn->lastInsertId();

            if ((isset($in['nodes']) && is_array($in['nodes'])) || (isset($in['connectors']) && is_array($in['connectors']))) {
                self::replaceContents($conn, $ctx, $diagramId, $in['nodes'] ?? [], $in['connectors'] ?? []);
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::emitCrud('network_diagram', 'created', $diagramId, $title);
        return $diagramId;
    }

    /** Save a diagram: partial metadata; sending nodes/connectors replaces BOTH sets. Returns the id. */
    public static function saveDiagram(PDO $conn, ActorContext $ctx, int $diagramId, array $in): int
    {
        $current = self::loadDiagram($conn, $diagramId);        // 404 if gone
        self::requireLeaf($current);                            // 409 on frozen versions
        if (!array_diff_key($in, ['id' => true])) {
            throw new ServiceError('validation', 'missing_field', 'No fields to update.');
        }

        $title = array_key_exists('title', $in) ? trim((string)$in['title']) : $current['title'];
        if ($title === '') {
            throw new ServiceError('validation', 'invalid_field', "'title' cannot be empty.");
        }
        [$paperSize, $paperOrientation] = self::validatePaper($in, $current);

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
                array_key_exists('description', $in)
                    ? (($v = trim((string)($in['description'] ?? ''))) !== '' ? $v : null)
                    : $current['description'],
                array_key_exists('version_label', $in)
                    ? (($v = trim((string)($in['version_label'] ?? ''))) !== '' ? substr($v, 0, 50) : null)
                    : $current['version_label'],
                $paperSize,
                $paperOrientation,
                self::brandingSlot($in, 'header', 'left',   $current['header_left']),
                self::brandingSlot($in, 'header', 'center', $current['header_center']),
                self::brandingSlot($in, 'header', 'right',  $current['header_right']),
                self::brandingSlot($in, 'footer', 'left',   $current['footer_left']),
                self::brandingSlot($in, 'footer', 'center', $current['footer_center']),
                self::brandingSlot($in, 'footer', 'right',  $current['footer_right']),
                $diagramId,
            ]);

            if ((isset($in['nodes']) && is_array($in['nodes'])) || (isset($in['connectors']) && is_array($in['connectors']))) {
                self::replaceContents($conn, $ctx, $diagramId, $in['nodes'] ?? [], $in['connectors'] ?? []);
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::emitCrud('network_diagram', 'updated', $diagramId, $title);
        return $diagramId;
    }

    /** Delete a diagram version (leaf-only) or the whole chain. Returns ['id','versions_deleted']. */
    public static function deleteDiagram(PDO $conn, ActorContext $ctx, int $diagramId, bool $chain = false): array
    {
        $diagram = self::loadDiagram($conn, $diagramId);
        if (!$chain && (int)$diagram['child_count'] > 0) {
            throw new ServiceError('conflict', 'conflict', 'This version has newer versions after it — deleting it would corrupt the chain. Delete the current (leaf) version, or pass ?chain=true to delete the whole chain.');
        }
        $ids = $chain ? self::chainIds($conn, $diagramId) : [$diagramId];
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM network_diagram_connectors WHERE diagram_id IN ($ph)")->execute($ids);
            $conn->prepare("DELETE FROM network_diagram_nodes WHERE diagram_id IN ($ph)")->execute($ids);
            foreach (array_reverse($ids) as $id) {
                $conn->prepare("DELETE FROM network_diagrams WHERE id = ?")->execute([$id]);
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::emitCrud('network_diagram', 'deleted', $diagramId, $diagram['title'] ?? null);
        return ['id' => $diagramId, 'versions_deleted' => count($ids)];
    }

    /** Fork the leaf into a new version (clones nodes + connectors forward). Returns the new id. */
    public static function createVersion(PDO $conn, ActorContext $ctx, int $parentId, array $in): int
    {
        $parent = self::loadDiagram($conn, $parentId);          // 404 if gone (incl. a 0/absent parent)
        self::requireLeaf($parent);

        $title = trim((string)($in['title'] ?? '')) ?: $parent['title'];
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
                $parentId,
                $title,
                array_key_exists('description', $in)
                    ? (($v = trim((string)($in['description'] ?? ''))) !== '' ? $v : null)
                    : $parent['description'],
                array_key_exists('version_label', $in)
                    ? (($v = trim((string)($in['version_label'] ?? ''))) !== '' ? substr($v, 0, 50) : null)
                    : $parent['version_label'],
                $ctx->actorId,
                $parent['paper_size'], $parent['paper_orientation'],
                $parent['header_left'], $parent['header_center'], $parent['header_right'],
                $parent['footer_left'], $parent['footer_center'], $parent['footer_right'],
            ]);
            $newId = (int)$conn->lastInsertId();

            $nodeMap = [];
            $nodes = $conn->prepare("SELECT * FROM network_diagram_nodes WHERE diagram_id = ? ORDER BY id");
            $nodes->execute([$parentId]);
            $insertNode = $conn->prepare(
                "INSERT INTO network_diagram_nodes (diagram_id, cmdb_object_id, x, y, size, icon_override) VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($nodes->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $insertNode->execute([$newId, (int)$n['cmdb_object_id'], (int)$n['x'], (int)$n['y'], $n['size'], $n['icon_override']]);
                $nodeMap[(int)$n['id']] = (int)$conn->lastInsertId();
            }
            $conns = $conn->prepare("SELECT * FROM network_diagram_connectors WHERE diagram_id = ? ORDER BY id");
            $conns->execute([$parentId]);
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
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::emitCrud('network_diagram', 'created', $newId, $title);
        return $newId;
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    private static function loadDiagram(PDO $conn, int $diagramId): array
    {
        $stmt = $conn->prepare(
            "SELECT d.*,
                    (SELECT COUNT(*) FROM network_diagrams c WHERE c.parent_diagram_id = d.id) AS child_count
             FROM network_diagrams d WHERE d.id = ?"
        );
        $stmt->execute([$diagramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Diagram not found.');
        }
        return $row;
    }

    private static function requireLeaf(array $diagram): void
    {
        if ((int)$diagram['child_count'] > 0) {
            throw new ServiceError('conflict', 'conflict', 'This is a historical version (read-only). Edit the current version — find it via GET /network-diagrams/{id}/versions.');
        }
    }

    /** The whole version chain containing $diagramId: root first. */
    private static function chainIds(PDO $conn, int $diagramId): array
    {
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

    /** paper_size / paper_orientation whitelists. */
    private static function validatePaper(array $in, ?array $current): array
    {
        $sizes = ['A4', 'A3', 'A2', 'Letter', 'Tabloid'];
        $size = array_key_exists('paper_size', $in)
            ? (($v = trim((string)($in['paper_size'] ?? ''))) !== '' ? $v : null)
            : ($current['paper_size'] ?? null);
        if ($size !== null && !in_array($size, $sizes, true)) {
            throw new ServiceError('validation', 'invalid_field', "Unknown paper_size '{$size}'. Valid: " . implode(', ', $sizes) . ' (null = no paper overlay).');
        }
        $orientation = array_key_exists('paper_orientation', $in)
            ? (($v = trim((string)($in['paper_orientation'] ?? ''))) !== '' ? $v : null)
            : ($current['paper_orientation'] ?? null);
        if ($orientation !== null && !in_array($orientation, ['portrait', 'landscape'], true)) {
            throw new ServiceError('validation', 'invalid_field', "paper_orientation must be 'portrait' or 'landscape'.");
        }
        return [$size, $orientation];
    }

    /** Read a branding slot from in.branding.{band}.{slot}. NULL = inherit, '' = explicit blank. */
    private static function brandingSlot(array $in, string $band, string $slot, ?string $current): ?string
    {
        if (!isset($in['branding']) || !is_array($in['branding'])
            || !isset($in['branding'][$band]) || !is_array($in['branding'][$band])
            || !array_key_exists($slot, $in['branding'][$band])) {
            return $current;
        }
        $v = $in['branding'][$band][$slot];
        return $v === null ? null : substr((string)$v, 0, 200);
    }

    /** Full contents replace (delete + reinsert; ref-based connector wiring, strict validation). */
    private static function replaceContents(PDO $conn, ActorContext $ctx, int $diagramId, array $nodesIn, array $connectorsIn): void
    {
        $conn->prepare("DELETE FROM network_diagram_connectors WHERE diagram_id = ?")->execute([$diagramId]);
        $conn->prepare("DELETE FROM network_diagram_nodes WHERE diagram_id = ?")->execute([$diagramId]);

        $place = null;
        $refMap = [];
        $objectMap = [];
        $insertNode = $conn->prepare(
            "INSERT INTO network_diagram_nodes (diagram_id, cmdb_object_id, x, y, size, icon_override) VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach (array_values($nodesIn) as $i => $n) {
            if (!is_array($n)) {
                throw new ServiceError('validation', 'invalid_field', "nodes[{$i}] must be an object.");
            }
            [$objectId, $x, $y, $size, $icon, $ref] = self::validateNodeInput($conn, $ctx, $n, $i);
            if ($x === null || $y === null) {
                if ($place === null) {
                    $place = self::autoPlacer($conn, $diagramId);
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
                throw new ServiceError('validation', 'invalid_field', "connectors[{$i}] must be an object.");
            }
            $resolve = function (string $prefix) use ($k, $refMap, $objectMap, $i): int {
                if (isset($k["{$prefix}_ref"]) && $k["{$prefix}_ref"] !== '') {
                    $ref = (string)$k["{$prefix}_ref"];
                    if (!isset($refMap[$ref])) {
                        throw new ServiceError('validation', 'invalid_field', "connectors[{$i}]: '{$prefix}_ref' \"{$ref}\" doesn't match any node's 'ref' in this payload.");
                    }
                    return $refMap[$ref];
                }
                if (isset($k["{$prefix}_object_id"]) && (int)$k["{$prefix}_object_id"] > 0) {
                    $objectId = (int)$k["{$prefix}_object_id"];
                    $nodeIds = $objectMap[$objectId] ?? [];
                    if (count($nodeIds) === 0) {
                        throw new ServiceError('validation', 'invalid_field', "connectors[{$i}]: object {$objectId} isn't among the nodes in this payload.");
                    }
                    if (count($nodeIds) > 1) {
                        throw new ServiceError('validation', 'invalid_field', "connectors[{$i}]: object {$objectId} appears " . count($nodeIds) . " times — use '{$prefix}_ref' instead.");
                    }
                    return $nodeIds[0];
                }
                throw new ServiceError('validation', 'missing_field', "connectors[{$i}]: needs '{$prefix}_ref' or '{$prefix}_object_id'.");
            };
            $fromId = $resolve('from');
            $toId   = $resolve('to');
            if ($fromId === $toId) {
                throw new ServiceError('validation', 'invalid_field', "connectors[{$i}]: cannot connect a node to itself.");
            }
            $relId = self::resolveRelationshipId($conn, $k['cmdb_relationship_id'] ?? null, $fromId, $toId);
            $insertConn->execute([
                $diagramId, $fromId, $toId, $relId,
                ($v = trim((string)($k['label'] ?? ''))) !== '' ? substr($v, 0, 255) : null,
                self::validateLineStyle(trim((string)($k['line_style'] ?? 'solid')) ?: 'solid'),
            ]);
        }
    }

    /** Validate one node input row. Returns [objectId, x|null, y|null, size, icon, ref]. */
    private static function validateNodeInput(PDO $conn, ActorContext $ctx, array $n, int $index): array
    {
        $objectId = isset($n['cmdb_object_id']) ? (int)$n['cmdb_object_id'] : 0;
        if ($objectId <= 0) {
            throw new ServiceError('validation', 'missing_field', "nodes[{$index}]: 'cmdb_object_id' is required.");
        }
        self::validateObjectExists($conn, $ctx, $objectId);
        $size = self::validateSize(trim((string)($n['size'] ?? 'medium')) ?: 'medium');
        $icon = null;
        if (isset($n['icon_override']) && $n['icon_override'] !== null && trim((string)$n['icon_override']) !== '') {
            $icon = self::validateIcon($conn, trim((string)$n['icon_override']));
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

    private static function autoPlacer(PDO $conn, int $diagramId): callable
    {
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

    private static function resolveRelationshipId(PDO $conn, $raw, int $fromNodeId, int $toNodeId): ?int
    {
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
            throw new ServiceError('validation', 'invalid_field', "Unknown cmdb_relationship_id: {$relId}");
        }
        return $relId;
    }

    private static function validateObjectExists(PDO $conn, ActorContext $ctx, int $objectId): void
    {
        $stmt = $conn->prepare("SELECT tenant_id FROM cmdb_objects WHERE id = ?");
        $stmt->execute([$objectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('validation', 'invalid_field', "Unknown cmdb_object_id: {$objectId}");
        }
        // Multi-tenancy: a CI can only be dropped onto a diagram by someone who
        // can reach it. The pickers are scoped, but this is the write path they
        // feed — without the check a raw id would still plant another company's
        // CI on a canvas, where get_diagram.php would then render its name.
        // Same wording as the not-found case so it reveals nothing either way.
        if ($ctx->companyScope !== null) {
            $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
            if (!in_array($tid, $ctx->companyScope, true)) {
                throw new ServiceError('validation', 'invalid_field', "Unknown cmdb_object_id: {$objectId}");
            }
        }
    }

    private static function validateSize(string $size): string
    {
        if (!isset(self::NODE_SIZES[$size])) {
            throw new ServiceError('validation', 'invalid_field', "Unknown size '{$size}'. Valid: " . implode(', ', array_keys(self::NODE_SIZES)) . '.');
        }
        return $size;
    }

    private static function validateLineStyle(string $style): string
    {
        if (!in_array($style, self::LINE_STYLES, true)) {
            throw new ServiceError('validation', 'invalid_field', "Unknown line_style '{$style}'. Valid: " . implode(', ', self::LINE_STYLES) . '.');
        }
        return $style;
    }

    private static function validateIcon(PDO $conn, string $iconKey): string
    {
        $stmt = $conn->prepare("SELECT 1 FROM cmdb_icons WHERE icon_key = ?");
        $stmt->execute([$iconKey]);
        if (!$stmt->fetchColumn()) {
            throw new ServiceError('validation', 'invalid_field', "Unknown icon key '{$iconKey}'. See GET /cmdb-icons.");
        }
        return $iconKey;
    }
}
