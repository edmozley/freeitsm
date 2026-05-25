<?php
/**
 * API: List property definitions for a single class, including dropdown options.
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
    $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    if ($classId <= 0) throw new Exception('class_id is required');

    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT p.id, p.class_id, p.property_key, p.label, p.property_type,
                p.target_class_id, p.is_required, p.display_order,
                tc.name AS target_class_name
           FROM cmdb_class_properties p
      LEFT JOIN cmdb_classes tc ON tc.id = p.target_class_id
          WHERE p.class_id = ?
       ORDER BY p.display_order, p.label"
    );
    $stmt->execute([$classId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pull dropdown options per property in one pass.
    // Returns objects (value + colour) so the row-based editor can render swatches.
    $optStmt = $conn->prepare(
        "SELECT o.property_id, o.option_value, o.colour, o.display_order
           FROM cmdb_class_property_options o
           JOIN cmdb_class_properties p ON p.id = o.property_id
          WHERE p.class_id = ?
       ORDER BY o.display_order, o.id"
    );
    $optStmt->execute([$classId]);
    $optionsByProp = [];
    foreach ($optStmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
        $optionsByProp[(int)$opt['property_id']][] = [
            'value'  => $opt['option_value'],
            'colour' => $opt['colour'],
            'display_order' => (int)$opt['display_order'],
        ];
    }

    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['class_id'] = (int)$r['class_id'];
        $r['target_class_id'] = $r['target_class_id'] !== null ? (int)$r['target_class_id'] : null;
        $r['is_required'] = (int)$r['is_required'] === 1;
        $r['display_order'] = (int)$r['display_order'];
        $r['options'] = $optionsByProp[$r['id']] ?? [];
    }

    echo json_encode(['success' => true, 'properties' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
