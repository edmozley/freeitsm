<?php
/**
 * API: Get all CMDB objects related to a given object, across three buckets:
 *   - outgoing: cmdb_object_relationships where this object is from_object_id
 *   - incoming: cmdb_object_relationships where this object is to_object_id
 *   - property: cmdb_object_properties where value_object_id = this id
 *               (other objects referencing this one via an object_ref property)
 *
 * Used by Network Mapper's "Add related objects" flow to pull CMDB neighbours
 * onto the canvas. Each returned row carries enough info for the modal to
 * render (name, class, icon, planned flag) and for the connector to be
 * provenance-linked back to a real cmdb_object_relationships row when
 * applicable.
 *
 * Returns a flat array — one row per (related_object, relationship-path), so
 * the same object can appear multiple times if it's reachable through more
 * than one path. The frontend dedupes by (object_id, kind) and shows each
 * path as a separate tickable row so the user picks which one becomes the
 * connector.
 *
 * GET ?object_id=X
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $id = isset($_GET['object_id']) ? (int)$_GET['object_id'] : 0;
    if ($id <= 0) throw new Exception('object_id is required');

    $conn = connectToDatabase();

    // Confirm the source object exists — gives a cleaner error than the
    // queries below returning empty arrays for a bogus id
    $check = $conn->prepare("SELECT id FROM cmdb_objects WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetchColumn()) throw new Exception('Object not found');

    $related = [];

    // ---- Outgoing relationships (this object → other) ----
    // Verb reads naturally outward: "depends on", "hosts", etc.
    $outStmt = $conn->prepare(
        "SELECT r.id AS relationship_id, rt.verb AS label,
                o.id AS object_id, o.name, o.is_planned,
                c.id AS class_id, c.name AS class_name, i.icon_key AS class_icon
           FROM cmdb_object_relationships r
           JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
           JOIN cmdb_objects o ON o.id = r.to_object_id
           JOIN cmdb_classes c ON c.id = o.class_id
      LEFT JOIN cmdb_icons   i ON i.id = c.icon_id
          WHERE r.from_object_id = ?
       ORDER BY rt.display_order, rt.verb, o.name"
    );
    $outStmt->execute([$id]);
    foreach ($outStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $related[] = [
            'kind'            => 'outgoing',
            'object_id'       => (int)$r['object_id'],
            'name'            => $r['name'],
            'class_id'        => (int)$r['class_id'],
            'class_name'      => $r['class_name'],
            'class_icon'      => $r['class_icon'] ?: 'box',
            'is_planned'      => (int)$r['is_planned'] === 1,
            'label'           => $r['label'],
            'relationship_id' => (int)$r['relationship_id'],
        ];
    }

    // ---- Incoming relationships (other → this object) ----
    // Use inverse_verb so the row reads naturally from the source object's POV:
    // "X is hosted by this" → label = "is hosted by"
    $inStmt = $conn->prepare(
        "SELECT r.id AS relationship_id, rt.inverse_verb AS label,
                o.id AS object_id, o.name, o.is_planned,
                c.id AS class_id, c.name AS class_name, i.icon_key AS class_icon
           FROM cmdb_object_relationships r
           JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
           JOIN cmdb_objects o ON o.id = r.from_object_id
           JOIN cmdb_classes c ON c.id = o.class_id
      LEFT JOIN cmdb_icons   i ON i.id = c.icon_id
          WHERE r.to_object_id = ?
       ORDER BY rt.display_order, rt.inverse_verb, o.name"
    );
    $inStmt->execute([$id]);
    foreach ($inStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $related[] = [
            'kind'            => 'incoming',
            'object_id'       => (int)$r['object_id'],
            'name'            => $r['name'],
            'class_id'        => (int)$r['class_id'],
            'class_name'      => $r['class_name'],
            'class_icon'      => $r['class_icon'] ?: 'box',
            'is_planned'      => (int)$r['is_planned'] === 1,
            'label'           => $r['label'],
            'relationship_id' => (int)$r['relationship_id'],
        ];
    }

    // ---- Property references (other.property = this) ----
    // No cmdb_relationships row exists for these — they're a logical link via
    // an object_ref property. relationship_id is NULL so the resulting
    // connector is provenance-marked only by its label (the property name).
    $propStmt = $conn->prepare(
        "SELECT o.id AS object_id, o.name, o.is_planned,
                c.id AS class_id, c.name AS class_name, i.icon_key AS class_icon,
                p.label AS label
           FROM cmdb_object_properties op
           JOIN cmdb_objects o ON o.id = op.object_id
           JOIN cmdb_classes c ON c.id = o.class_id
      LEFT JOIN cmdb_icons   i ON i.id = c.icon_id
           JOIN cmdb_class_properties p ON p.id = op.property_id
          WHERE op.value_object_id = ?
       ORDER BY p.label, o.name"
    );
    $propStmt->execute([$id]);
    foreach ($propStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $related[] = [
            'kind'            => 'property',
            'object_id'       => (int)$r['object_id'],
            'name'            => $r['name'],
            'class_id'        => (int)$r['class_id'],
            'class_name'      => $r['class_name'],
            'class_icon'      => $r['class_icon'] ?: 'box',
            'is_planned'      => (int)$r['is_planned'] === 1,
            'label'           => $r['label'],
            'relationship_id' => null,
        ];
    }

    echo json_encode(['success' => true, 'related' => $related]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
