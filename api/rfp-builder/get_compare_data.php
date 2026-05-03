<?php
/**
 * Aggregate scoring data for the cross-supplier compare page.
 *
 * For each supplier: their per-category averages and an overall
 * average, computed via the same multi-analyst rollup the scoring
 * page uses:
 *   1. Per (supplier, requirement): mean across analysts who scored
 *   2. Per (supplier, category):    mean of those per-requirement
 *                                   means across requirements in the cat
 *   3. Overall per supplier:        mean of all per-requirement means
 *
 * Categories with no scored requirements get null (not 0) so the
 * page can render "—" instead of pretending there's a zero.
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

    // Index requirements by category so we can compute per-category
    // totals and know which requirements belong where.
    $reqStmt = $conn->prepare(
        "SELECT id, category_id FROM rfp_consolidated_requirements WHERE rfp_id = ?"
    );
    $reqStmt->execute([$rfpId]);
    $reqs = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
    $reqCatById = [];           // requirement_id => category_id (or 0 for orphan)
    $reqsByCat  = [];           // category_id => [requirement_id, ...]
    foreach ($reqs as $r) {
        $rid = (int)$r['id'];
        $cid = $r['category_id'] !== null ? (int)$r['category_id'] : 0;
        $reqCatById[$rid] = $cid;
        $reqsByCat[$cid][] = $rid;
    }
    $totalReqCount = count($reqs);

    $supStmt = $conn->prepare(
        "SELECT i.supplier_id, s.legal_name, s.trading_name
           FROM rfp_invited_suppliers i
      INNER JOIN suppliers s ON i.supplier_id = s.id
          WHERE i.rfp_id = ?
       ORDER BY COALESCE(s.trading_name, s.legal_name)"
    );
    $supStmt->execute([$rfpId]);
    $supplierRows = $supStmt->fetchAll(PDO::FETCH_ASSOC);

    // Pull every score in one go and aggregate in PHP — small datasets,
    // simpler than nesting CTEs.
    $scoreStmt = $conn->prepare(
        "SELECT supplier_id, analyst_id, consolidated_id, score
           FROM rfp_scores
          WHERE rfp_id = ? AND score IS NOT NULL"
    );
    $scoreStmt->execute([$rfpId]);
    $allScores = $scoreStmt->fetchAll(PDO::FETCH_ASSOC);

    // Bucket by (supplier_id, requirement_id) → array of scores from
    // each analyst, plus track unique analysts per supplier.
    $byPair  = [];
    $analystsBySupplier = [];
    foreach ($allScores as $row) {
        $sid = (int)$row['supplier_id'];
        $rid = (int)$row['consolidated_id'];
        $aid = (int)$row['analyst_id'];
        $byPair[$sid][$rid][] = (int)$row['score'];
        $analystsBySupplier[$sid][$aid] = true;
    }

    // Build the per-supplier output now.
    $suppliers = [];
    foreach ($supplierRows as $s) {
        $sid = (int)$s['supplier_id'];

        // Per-requirement mean for this supplier
        $perReqAvg = [];
        if (isset($byPair[$sid])) {
            foreach ($byPair[$sid] as $rid => $scores) {
                $perReqAvg[$rid] = array_sum($scores) / count($scores);
            }
        }

        // Per-category mean (mean of per-req means within the cat)
        $byCategory = [];
        foreach ($categories as $c) {
            $cid = (int)$c['id'];
            $reqIds = $reqsByCat[$cid] ?? [];
            $vals   = [];
            foreach ($reqIds as $rid) {
                if (isset($perReqAvg[$rid])) $vals[] = $perReqAvg[$rid];
            }
            $byCategory[] = [
                'category_id'   => $cid,
                'scored_count'  => count($vals),
                'total_count'   => count($reqIds),
                'avg'           => count($vals) > 0 ? round(array_sum($vals) / count($vals), 3) : null,
            ];
        }
        // Orphan bucket (uncategorised) — only emit if there are any
        if (!empty($reqsByCat[0])) {
            $reqIds = $reqsByCat[0];
            $vals   = [];
            foreach ($reqIds as $rid) {
                if (isset($perReqAvg[$rid])) $vals[] = $perReqAvg[$rid];
            }
            $byCategory[] = [
                'category_id'   => 0,
                'scored_count'  => count($vals),
                'total_count'   => count($reqIds),
                'avg'           => count($vals) > 0 ? round(array_sum($vals) / count($vals), 3) : null,
            ];
        }

        // Overall = mean of all per-requirement means (each requirement
        // counts equally regardless of which category it's in)
        $overall = count($perReqAvg) > 0
            ? round(array_sum($perReqAvg) / count($perReqAvg), 3)
            : null;

        $suppliers[] = [
            'id'             => $sid,
            'display_name'   => $s['trading_name'] ?: $s['legal_name'],
            'legal_name'     => $s['legal_name'],
            'analyst_count'  => isset($analystsBySupplier[$sid]) ? count($analystsBySupplier[$sid]) : 0,
            'scored_req_count' => count($perReqAvg),
            'total_req_count'  => $totalReqCount,
            'overall_avg'    => $overall,
            'by_category'    => $byCategory,
        ];
    }

    echo json_encode([
        'success'    => true,
        'rfp_id'     => $rfpId,
        'categories' => $categories,
        'has_orphan_category' => !empty($reqsByCat[0]),
        'suppliers'  => $suppliers,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
