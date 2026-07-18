<?php
/**
 * API: Search objects for autocomplete pickers.
 *
 * Used by:
 *   - Parent picker (no class_id filter, optionally exclude self)
 *   - object_ref property pickers (class_id = the property's target_class_id)
 *   - Relationship target pickers (no class_id filter, exclude self)
 *
 * Query params:
 *   q         — text to substring-match against name (required, min 1 char)
 *   class_id  — optional: only return objects of this class
 *   exclude_id — optional: skip this object (e.g. exclude self for parent picker)
 *   limit     — optional cap, default 20, max 50
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
    $q = trim((string)($_GET['q'] ?? ''));
    $classId   = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;
    $excludeId = isset($_GET['exclude_id']) && $_GET['exclude_id'] !== '' ? (int)$_GET['exclude_id'] : null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $limit = max(1, min(50, $limit));

    if ($q === '') {
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }

    $conn = connectToDatabase();

    // Per-company scope. This feeds the parent, object_ref and relationship-target
    // pickers, so scoping it here is what stops an analyst attaching one company's
    // CI to another's — the invariant the service also enforces on write.
    [$tSql, $tArgs] = activeTenantFilter($conn, (int) $_SESSION['analyst_id'], 'o');

    $sql = "SELECT o.id, o.name, c.id AS class_id, c.name AS class_name, o.is_planned
              FROM cmdb_objects o
              JOIN cmdb_classes c ON c.id = o.class_id
             WHERE o.name LIKE ?" . $tSql;
    $params = array_merge(['%' . $q . '%'], $tArgs);

    if ($classId !== null) {
        $sql .= " AND o.class_id = ?";
        $params[] = $classId;
    }
    if ($excludeId !== null) {
        $sql .= " AND o.id <> ?";
        $params[] = $excludeId;
    }
    $sql .= " ORDER BY o.name LIMIT $limit";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['class_id'] = (int)$r['class_id'];
        $r['is_planned'] = (int)$r['is_planned'] === 1;
    }

    echo json_encode(['success' => true, 'results' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
