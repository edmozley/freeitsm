<?php
/**
 * Return the full consolidated tree for an RFP — categories, consolidated
 * requirements with their source quotes/departments, and conflicts.
 *
 * Powers the consolidate.php page. The page renders flat in 3a (no
 * grouping/expansion) and is enhanced in 3b (group by category, expand
 * source quotes inline, badges, etc.). Same shape works for both.
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

    $conn = connectToDatabase();

    $cats = $conn->prepare(
        "SELECT id, name, description, sort_order, is_active
           FROM rfp_categories
          WHERE rfp_id = ?
       ORDER BY sort_order, id"
    );
    $cats->execute([$rfpId]);
    $categories = $cats->fetchAll(PDO::FETCH_ASSOC);

    $consStmt = $conn->prepare(
        "SELECT id, category_id, requirement_text, requirement_type, priority,
                ai_rationale, is_locked, created_datetime, updated_datetime
           FROM rfp_consolidated_requirements
          WHERE rfp_id = ?
       ORDER BY category_id, id"
    );
    $consStmt->execute([$rfpId]);
    $consolidated = $consStmt->fetchAll(PDO::FETCH_ASSOC);

    // Pull every source link for these consolidated rows in one query,
    // join to the extracted requirement and its document/department,
    // then group in PHP by consolidated_id.
    $sourcesByCons = [];
    if (!empty($consolidated)) {
        $consIds  = array_column($consolidated, 'id');
        $place    = implode(',', array_fill(0, count($consIds), '?'));
        $srcStmt  = $conn->prepare(
            "SELECT cs.consolidated_id, er.id AS extracted_id,
                    er.requirement_text, er.requirement_type,
                    er.source_quote, er.ai_confidence,
                    dept.id AS department_id, dept.name AS department_name, dept.colour AS department_colour,
                    d.id AS document_id, d.original_filename AS document_filename
               FROM rfp_consolidated_sources cs
          INNER JOIN rfp_extracted_requirements er ON cs.extracted_id = er.id
           LEFT JOIN rfp_documents d    ON er.document_id = d.id
           LEFT JOIN rfp_departments dept ON d.department_id = dept.id
              WHERE cs.consolidated_id IN ($place)
           ORDER BY dept.sort_order, dept.name, er.id"
        );
        $srcStmt->execute($consIds);
        foreach ($srcStmt->fetchAll(PDO::FETCH_ASSOC) as $src) {
            $sourcesByCons[(int)$src['consolidated_id']][] = $src;
        }
    }
    foreach ($consolidated as &$c) {
        $c['is_locked'] = (bool)$c['is_locked'];
        $c['sources']   = $sourcesByCons[(int)$c['id']] ?? [];
    }
    unset($c);

    $conflictsStmt = $conn->prepare(
        "SELECT c.id, c.consolidated_id_a, c.consolidated_id_b,
                c.ai_explanation, c.resolution, c.resolution_notes,
                c.resolved_by_analyst_id, c.resolved_datetime, c.created_datetime,
                ra.full_name AS resolved_by_name,
                a.requirement_text AS a_text, a.priority AS a_priority,
                b.requirement_text AS b_text, b.priority AS b_priority
           FROM rfp_conflicts c
      LEFT JOIN rfp_consolidated_requirements a  ON c.consolidated_id_a = a.id
      LEFT JOIN rfp_consolidated_requirements b  ON c.consolidated_id_b = b.id
      LEFT JOIN analysts                     ra ON c.resolved_by_analyst_id = ra.id
          WHERE c.rfp_id = ?
       ORDER BY (c.resolution = 'open') DESC, c.created_datetime DESC"
    );
    $conflictsStmt->execute([$rfpId]);
    $conflicts = $conflictsStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'      => true,
        'categories'   => $categories,
        'consolidated' => $consolidated,
        'conflicts'    => $conflicts,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
