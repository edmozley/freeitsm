<?php
/**
 * API: Compute the "blast radius" for an object — what would be affected if
 * this object went offline / was deleted.
 *
 * Three buckets:
 *   - descendants: every object below this in the hierarchy (cascade-delete
 *     candidates — they ontologically depend on this object)
 *   - referenced_by_property: objects that have THIS object set as the value
 *     of one of their object_ref properties (e.g. databases whose Host Server
 *     property points at this server)
 *   - referenced_by_relationship: objects on the OTHER side of an INCOMING
 *     relationship (i.e. someone said "I depend on this", "I'm managed by this")
 *
 * Used by:
 *   - The Impact panel on the object detail page
 *   - The AI summary generator (so the prose can say "X databases depend on this")
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');

try {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();

    // Company gate — impact walks descendants and inbound references, so an
    // ungated call here would enumerate another company's estate through the
    // blast-radius panel.
    if (!analystCanAccessCmdbObject($conn, (int) $_SESSION['analyst_id'], $id)) {
        echo json_encode(['success' => false, 'error' => 'Object not found']);
        exit;
    }

    // Walk descendants depth-first, capping at 1000 to avoid runaway loops on
    // circular/very deep trees. Each entry includes its hop count from the root.
    $descendants = [];
    $stack = [['id' => $id, 'depth' => 0]];
    $seen = [$id => true];
    $hops = 0;
    $childStmt = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name
           FROM cmdb_objects o JOIN cmdb_classes c ON c.id = o.class_id
          WHERE o.parent_id = ?"
    );
    while ($stack && $hops < 1000) {
        $cur = array_pop($stack);
        $childStmt->execute([$cur['id']]);
        foreach ($childStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['id'];
            if (isset($seen[$cid])) continue;
            $seen[$cid] = true;
            $descendants[] = [
                'id'         => $cid,
                'name'       => $row['name'],
                'class_name' => $row['class_name'],
                'depth'      => $cur['depth'] + 1,
            ];
            $stack[] = ['id' => $cid, 'depth' => $cur['depth'] + 1];
        }
        $hops++;
    }

    // Objects that reference THIS object as the value of an object_ref property
    $propRefStmt = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name, p.label AS property_label
           FROM cmdb_object_properties op
           JOIN cmdb_objects o ON o.id = op.object_id
           JOIN cmdb_classes c ON c.id = o.class_id
           JOIN cmdb_class_properties p ON p.id = op.property_id
          WHERE op.value_object_id = ?
       ORDER BY c.name, o.name"
    );
    $propRefStmt->execute([$id]);
    $referencedByProperty = $propRefStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($referencedByProperty as &$r) { $r['id'] = (int)$r['id']; }

    // Incoming relationships — i.e. someone said "I [verb] this".
    // Show with the inverse_verb because that's how it reads from THIS object's side.
    $relRefStmt = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name, rt.verb, rt.inverse_verb
           FROM cmdb_object_relationships r
           JOIN cmdb_objects o ON o.id = r.from_object_id
           JOIN cmdb_classes c ON c.id = o.class_id
           JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
          WHERE r.to_object_id = ?
       ORDER BY rt.display_order, o.name"
    );
    $relRefStmt->execute([$id]);
    $referencedByRelationship = $relRefStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($referencedByRelationship as &$r) { $r['id'] = (int)$r['id']; }

    echo json_encode([
        'success' => true,
        'impact' => [
            'descendants'                => $descendants,
            'referenced_by_property'     => $referencedByProperty,
            'referenced_by_relationship' => $referencedByRelationship,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
