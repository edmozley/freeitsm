<?php
/**
 * API: List CMDB objects, optionally filtered by class and/or text search.
 * Returns lightweight rows for the browse page table.
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
    $classId = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;
    $search  = trim((string)($_GET['search'] ?? ''));

    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];

    // Per-company scope. Three separate fragments because they land in three
    // different places and PDO binds positionally — SELECT-clause params bind
    // before JOIN params, which bind before WHERE params. Get that order wrong
    // and the filters silently swap values.
    [$tChild,  $aChild]  = activeTenantFilter($conn, $analystId, 'ch');
    [$tParent, $aParent] = activeTenantFilter($conn, $analystId, 'p');
    [$tSql,    $tArgs]   = activeTenantFilter($conn, $analystId, 'o');

    // The parent filter goes in the ON clause, not the WHERE: a CI whose parent
    // somehow sits in another company must still appear in the list, just with a
    // blank parent — never vanish from its own company's view.
    $sql = "SELECT o.id, o.name, o.class_id, c.name AS class_name,
                   o.parent_id, p.name AS parent_name, pc.name AS parent_class_name,
                   o.is_planned,
                   o.created_datetime, o.updated_datetime,
                   (SELECT COUNT(*) FROM cmdb_objects ch
                     WHERE ch.parent_id = o.id" . $tChild . ") AS child_count
              FROM cmdb_objects o
              JOIN cmdb_classes c ON c.id = o.class_id
         LEFT JOIN cmdb_objects p ON p.id = o.parent_id" . $tParent . "
         LEFT JOIN cmdb_classes pc ON pc.id = p.class_id
             WHERE 1 = 1" . $tSql;
    $params = array_merge($aChild, $aParent, $tArgs);

    if ($classId !== null) {
        $sql .= " AND o.class_id = ?";
        $params[] = $classId;
    }
    if ($search !== '') {
        $sql .= " AND o.name LIKE ?";
        $params[] = '%' . $search . '%';
    }
    $sql .= " ORDER BY o.name";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['class_id'] = (int)$r['class_id'];
        $r['parent_id'] = $r['parent_id'] !== null ? (int)$r['parent_id'] : null;
        $r['child_count'] = (int)$r['child_count'];
        $r['is_planned'] = (int)$r['is_planned'] === 1;
    }

    echo json_encode(['success' => true, 'objects' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
