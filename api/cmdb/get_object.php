<?php
/**
 * API: Get a single CMDB object with everything hydrated:
 * - class info
 * - parent + parent class
 * - children (id + name + class)
 * - properties (with type-aware values; object_ref values include the linked
 *   object's name and class so the frontend can render them as cards/links)
 * - relationships (outgoing AND incoming, with verb / inverse_verb and the
 *   other side's name+class hydrated)
 *
 * Single round-trip is the goal — the detail page renders from this payload
 * without needing follow-up calls.
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
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();

    // Object + class + parent (and the cached AI summary, if any)
    $stmt = $conn->prepare(
        "SELECT o.id, o.name, o.class_id, c.name AS class_name, c.class_key,
                o.parent_id, p.name AS parent_name, pc.id AS parent_class_id, pc.name AS parent_class_name,
                o.is_planned,
                o.ai_summary, o.ai_summary_generated_at,
                o.created_datetime, o.updated_datetime
           FROM cmdb_objects o
           JOIN cmdb_classes c ON c.id = o.class_id
      LEFT JOIN cmdb_objects p ON p.id = o.parent_id
      LEFT JOIN cmdb_classes pc ON pc.id = p.class_id
          WHERE o.id = ?"
    );
    $stmt->execute([$id]);
    $obj = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$obj) throw new Exception('Object not found');

    foreach (['id', 'class_id', 'parent_id', 'parent_class_id'] as $f) {
        $obj[$f] = $obj[$f] !== null ? (int)$obj[$f] : null;
    }
    $obj['is_planned'] = (int)$obj['is_planned'] === 1;

    // Children
    $childStmt = $conn->prepare(
        "SELECT ch.id, ch.name, ch.class_id, cc.name AS class_name
           FROM cmdb_objects ch
           JOIN cmdb_classes cc ON cc.id = ch.class_id
          WHERE ch.parent_id = ?
       ORDER BY cc.name, ch.name"
    );
    $childStmt->execute([$id]);
    $children = $childStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($children as &$c) { $c['id'] = (int)$c['id']; $c['class_id'] = (int)$c['class_id']; }
    $obj['children'] = $children;

    // Property definitions for this class
    $propStmt = $conn->prepare(
        "SELECT p.id, p.property_key, p.label, p.property_type, p.target_class_id, p.is_required, p.display_order,
                tc.name AS target_class_name
           FROM cmdb_class_properties p
      LEFT JOIN cmdb_classes tc ON tc.id = p.target_class_id
          WHERE p.class_id = ?
       ORDER BY p.display_order, p.label"
    );
    $propStmt->execute([$obj['class_id']]);
    $propDefs = $propStmt->fetchAll(PDO::FETCH_ASSOC);

    // Existing values for this object — keyed by property_id
    $valStmt = $conn->prepare(
        "SELECT op.property_id, op.value_text, op.value_number, op.value_date,
                op.value_boolean, op.value_object_id,
                refo.name AS value_object_name, refoc.name AS value_object_class_name
           FROM cmdb_object_properties op
      LEFT JOIN cmdb_objects refo ON refo.id = op.value_object_id
      LEFT JOIN cmdb_classes refoc ON refoc.id = refo.class_id
          WHERE op.object_id = ?"
    );
    $valStmt->execute([$id]);
    $valuesByProp = [];
    foreach ($valStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $valuesByProp[(int)$v['property_id']] = $v;
    }

    // Dropdown options for any dropdown-typed properties on this class.
    // Returned as {value, colour} so the detail page can render coloured pills.
    $optStmt = $conn->prepare(
        "SELECT o.property_id, o.option_value, o.colour
           FROM cmdb_class_property_options o
           JOIN cmdb_class_properties p ON p.id = o.property_id
          WHERE p.class_id = ?
       ORDER BY o.display_order, o.id"
    );
    $optStmt->execute([$obj['class_id']]);
    $optionsByProp = [];
    foreach ($optStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $optionsByProp[(int)$row['property_id']][] = [
            'value'  => $row['option_value'],
            'colour' => $row['colour'],
        ];
    }

    $properties = [];
    foreach ($propDefs as $def) {
        $pid = (int)$def['id'];
        $val = $valuesByProp[$pid] ?? null;

        $entry = [
            'property_id'        => $pid,
            'property_key'       => $def['property_key'],
            'label'              => $def['label'],
            'property_type'      => $def['property_type'],
            'target_class_id'    => $def['target_class_id'] !== null ? (int)$def['target_class_id'] : null,
            'target_class_name'  => $def['target_class_name'],
            'is_required'        => (int)$def['is_required'] === 1,
            'display_order'      => (int)$def['display_order'],
            'value'              => null,
            'value_object'       => null,
            'options'            => $optionsByProp[$pid] ?? [],
        ];

        if ($val) {
            switch ($def['property_type']) {
                case 'text':       $entry['value'] = $val['value_text']; break;
                case 'number':     $entry['value'] = $val['value_number'] !== null ? (float)$val['value_number'] : null; break;
                case 'date':       $entry['value'] = $val['value_date']; break;
                case 'boolean':    $entry['value'] = $val['value_boolean'] !== null ? ((int)$val['value_boolean'] === 1) : null; break;
                case 'dropdown':   $entry['value'] = $val['value_text']; break;
                case 'object_ref':
                    $entry['value'] = $val['value_object_id'] !== null ? (int)$val['value_object_id'] : null;
                    if ($val['value_object_id'] !== null) {
                        $entry['value_object'] = [
                            'id' => (int)$val['value_object_id'],
                            'name' => $val['value_object_name'],
                            'class_name' => $val['value_object_class_name'],
                        ];
                    }
                    break;
            }
        }

        $properties[] = $entry;
    }
    $obj['properties'] = $properties;

    // Relationships — outgoing
    $outStmt = $conn->prepare(
        "SELECT r.id, rt.id AS type_id, rt.verb, rt.inverse_verb,
                r.to_object_id AS other_id, oo.name AS other_name, oc.name AS other_class_name
           FROM cmdb_object_relationships r
           JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
           JOIN cmdb_objects oo ON oo.id = r.to_object_id
           JOIN cmdb_classes oc ON oc.id = oo.class_id
          WHERE r.from_object_id = ?
       ORDER BY rt.display_order, rt.verb, oo.name"
    );
    $outStmt->execute([$id]);
    $outgoing = $outStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($outgoing as &$r) { $r['id'] = (int)$r['id']; $r['type_id'] = (int)$r['type_id']; $r['other_id'] = (int)$r['other_id']; }

    // Relationships — incoming (other → this); show with the inverse verb so it reads naturally
    $inStmt = $conn->prepare(
        "SELECT r.id, rt.id AS type_id, rt.verb, rt.inverse_verb,
                r.from_object_id AS other_id, oo.name AS other_name, oc.name AS other_class_name
           FROM cmdb_object_relationships r
           JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
           JOIN cmdb_objects oo ON oo.id = r.from_object_id
           JOIN cmdb_classes oc ON oc.id = oo.class_id
          WHERE r.to_object_id = ?
       ORDER BY rt.display_order, rt.inverse_verb, oo.name"
    );
    $inStmt->execute([$id]);
    $incoming = $inStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($incoming as &$r) { $r['id'] = (int)$r['id']; $r['type_id'] = (int)$r['type_id']; $r['other_id'] = (int)$r['other_id']; }

    $obj['relationships'] = [
        'outgoing' => $outgoing,
        'incoming' => $incoming,
    ];

    echo json_encode(['success' => true, 'object' => $obj]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
