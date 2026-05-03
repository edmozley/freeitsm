<?php
/**
 * Coverage heatmap data: which departments contributed requirements
 * to which categories. The cell value is the number of CONSOLIDATED
 * requirements in (category, department) — i.e. consolidated rows
 * with at least one source extracted requirement that came from a
 * document tagged with that department.
 *
 * One consolidated row can count toward multiple departments if its
 * source items spanned departments — that's the entire point of the
 * consolidation pass and the heatmap reflects it.
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
    if ($rfpId <= 0) throw new Exception('Missing or invalid rfp_id');

    $conn = connectToDatabase();

    $cats = $conn->prepare(
        "SELECT id, name, description, sort_order
           FROM rfp_categories
          WHERE rfp_id = ?
       ORDER BY sort_order, id"
    );
    $cats->execute([$rfpId]);
    $categories = $cats->fetchAll(PDO::FETCH_ASSOC);

    // Only departments that actually contributed something to this RFP.
    // Order by department sort_order so the matrix is stable.
    $depts = $conn->prepare(
        "SELECT DISTINCT dept.id, dept.name, dept.colour, dept.sort_order
           FROM rfp_extracted_requirements er
      INNER JOIN rfp_documents   d    ON er.document_id = d.id
      INNER JOIN rfp_departments dept ON d.department_id = dept.id
          WHERE er.rfp_id = ?
       ORDER BY dept.sort_order, dept.name"
    );
    $depts->execute([$rfpId]);
    $departments = $depts->fetchAll(PDO::FETCH_ASSOC);

    // For each (consolidated, department) where the consolidated row
    // has at least one source from that department, count it once.
    // Doing this in SQL keeps it cheap even on bigger RFPs.
    $cell = $conn->prepare(
        "SELECT cr.category_id, dept.id AS department_id,
                COUNT(DISTINCT cr.id) AS req_count
           FROM rfp_consolidated_requirements cr
      INNER JOIN rfp_consolidated_sources cs ON cr.id = cs.consolidated_id
      INNER JOIN rfp_extracted_requirements er ON cs.extracted_id = er.id
      INNER JOIN rfp_documents   d    ON er.document_id = d.id
      INNER JOIN rfp_departments dept ON d.department_id = dept.id
          WHERE cr.rfp_id = ?
       GROUP BY cr.category_id, dept.id"
    );
    $cell->execute([$rfpId]);
    $rawCells = $cell->fetchAll(PDO::FETCH_ASSOC);

    // Reshape into matrix[category_id][department_id] = count
    $matrix = [];
    foreach ($rawCells as $row) {
        $cid = $row['category_id'] !== null ? (int)$row['category_id'] : 0;
        $did = (int)$row['department_id'];
        $matrix[$cid][$did] = (int)$row['req_count'];
    }

    // Per-category totals (DISTINCT consolidated rows that have any
    // departmental source) and per-department totals (across all
    // categories).
    $catTotals  = [];
    $deptTotals = [];
    $rowsHaveOrphan = false;

    $catTotalStmt = $conn->prepare(
        "SELECT cr.category_id, COUNT(DISTINCT cr.id) AS total
           FROM rfp_consolidated_requirements cr
      INNER JOIN rfp_consolidated_sources cs ON cr.id = cs.consolidated_id
      INNER JOIN rfp_extracted_requirements er ON cs.extracted_id = er.id
      INNER JOIN rfp_documents d ON er.document_id = d.id
      INNER JOIN rfp_departments dept ON d.department_id = dept.id
          WHERE cr.rfp_id = ?
       GROUP BY cr.category_id"
    );
    $catTotalStmt->execute([$rfpId]);
    foreach ($catTotalStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cid = $row['category_id'] !== null ? (int)$row['category_id'] : 0;
        $catTotals[$cid] = (int)$row['total'];
        if ($cid === 0) $rowsHaveOrphan = true;
    }

    $deptTotalStmt = $conn->prepare(
        "SELECT dept.id AS department_id, COUNT(DISTINCT cr.id) AS total
           FROM rfp_consolidated_requirements cr
      INNER JOIN rfp_consolidated_sources cs ON cr.id = cs.consolidated_id
      INNER JOIN rfp_extracted_requirements er ON cs.extracted_id = er.id
      INNER JOIN rfp_documents d ON er.document_id = d.id
      INNER JOIN rfp_departments dept ON d.department_id = dept.id
          WHERE cr.rfp_id = ?
       GROUP BY dept.id"
    );
    $deptTotalStmt->execute([$rfpId]);
    foreach ($deptTotalStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $deptTotals[(int)$row['department_id']] = (int)$row['total'];
    }

    // Summary: how many categories are single-source vs multi-source,
    // for the stats strip on the page.
    $catBreakdown = ['single_source' => 0, 'multi_source' => 0, 'unsupported' => 0];
    foreach ($categories as $c) {
        $row = $matrix[(int)$c['id']] ?? [];
        $deptHits = count($row);
        if      ($deptHits === 0) $catBreakdown['unsupported']++;
        elseif  ($deptHits === 1) $catBreakdown['single_source']++;
        else                       $catBreakdown['multi_source']++;
    }

    // Total consolidated reqs (denominator for the headline number).
    $totalStmt = $conn->prepare(
        "SELECT COUNT(*) FROM rfp_consolidated_requirements WHERE rfp_id = ?"
    );
    $totalStmt->execute([$rfpId]);
    $totalConsolidated = (int)$totalStmt->fetchColumn();

    echo json_encode([
        'success'        => true,
        'rfp_id'         => $rfpId,
        'categories'     => $categories,
        'departments'    => $departments,
        'matrix'         => $matrix,
        'category_totals' => $catTotals,
        'department_totals' => $deptTotals,
        'has_orphan_category' => $rowsHaveOrphan,
        'total_consolidated' => $totalConsolidated,
        'category_breakdown' => $catBreakdown,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
