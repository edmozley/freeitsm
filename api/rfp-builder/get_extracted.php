<?php
/**
 * List extracted requirements for an RFP, with optional filters.
 * Returns requirement_text, source_quote, department + document metadata,
 * type, ai_confidence — everything the browser page needs to render.
 *
 * Query params:
 *   rfp_id           required
 *   department_id    optional ("" or 0 = all)
 *   requirement_type optional (requirement | pain_point | challenge | "" for all)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $rfpId = isset($_GET['rfp_id']) ? (int)$_GET['rfp_id'] : 0;
    if ($rfpId <= 0) {
        throw new Exception('Missing or invalid rfp_id');
    }
    $departmentId   = (isset($_GET['department_id']) && $_GET['department_id'] !== '')
                        ? (int)$_GET['department_id'] : null;
    $typeFilter     = $_GET['requirement_type'] ?? '';
    $validTypes     = ['requirement', 'pain_point', 'challenge'];
    $typeWhere      = in_array($typeFilter, $validTypes, true) ? $typeFilter : '';

    $conn = connectToDatabase();

    $sql = "SELECT er.id, er.rfp_id, er.document_id,
                   er.requirement_text, er.requirement_type, er.source_quote,
                   er.ai_confidence, er.is_consolidated,
                   er.created_datetime,
                   d.original_filename,
                   d.department_id,
                   dept.name AS department_name, dept.colour AS department_colour
              FROM rfp_extracted_requirements er
         LEFT JOIN rfp_documents d   ON er.document_id = d.id
         LEFT JOIN rfp_departments dept ON d.department_id = dept.id
             WHERE er.rfp_id = ?";
    $params = [$rfpId];

    if ($departmentId !== null) {
        $sql .= " AND d.department_id = ?";
        $params[] = $departmentId;
    }
    if ($typeWhere !== '') {
        $sql .= " AND er.requirement_type = ?";
        $params[] = $typeWhere;
    }
    $sql .= " ORDER BY er.created_datetime DESC, er.id ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['ai_confidence']   = $r['ai_confidence'] !== null ? (float)$r['ai_confidence'] : null;
        $r['is_consolidated'] = (bool)$r['is_consolidated'];
    }

    // Counts for the summary pills (these honour the same rfp scope, but
    // ignore the filters so the user can see totals while filtering).
    $stats = ['total' => 0, 'requirement' => 0, 'pain_point' => 0, 'challenge' => 0];
    $countStmt = $conn->prepare(
        "SELECT requirement_type, COUNT(*) AS c
           FROM rfp_extracted_requirements
          WHERE rfp_id = ?
       GROUP BY requirement_type"
    );
    $countStmt->execute([$rfpId]);
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = $row['requirement_type'];
        if (isset($stats[$type])) $stats[$type] = (int)$row['c'];
        $stats['total'] += (int)$row['c'];
    }

    // Available departments for the filter dropdown — only those that
    // actually have a document tagged with them in this RFP.
    $deptStmt = $conn->prepare(
        "SELECT DISTINCT dept.id, dept.name, dept.colour
           FROM rfp_documents d
           JOIN rfp_departments dept ON d.department_id = dept.id
          WHERE d.rfp_id = ?
       ORDER BY dept.sort_order, dept.name"
    );
    $deptStmt->execute([$rfpId]);
    $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'      => true,
        'requirements' => $rows,
        'stats'        => $stats,
        'departments'  => $departments,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
