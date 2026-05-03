<?php
/**
 * Return everything the scoring page needs for one (rfp, supplier, analyst)
 * scoring session: every consolidated requirement grouped by category, the
 * current analyst's existing scores (if any), and the average score from
 * other analysts who have also scored this supplier (so the analyst can
 * see how their judgment compares to the team without seeing individual
 * names — single-blind on purpose).
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
    $rfpId      = isset($_GET['rfp_id'])      ? (int)$_GET['rfp_id']      : 0;
    $supplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
    if ($rfpId <= 0 || $supplierId <= 0) {
        throw new Exception('Missing or invalid rfp_id / supplier_id');
    }
    $analystId = (int)$_SESSION['analyst_id'];

    $conn = connectToDatabase();

    // Sanity check — the supplier must actually be on this RFP.
    $inv = $conn->prepare(
        "SELECT i.id, s.legal_name, s.trading_name
           FROM rfp_invited_suppliers i
      INNER JOIN suppliers s ON i.supplier_id = s.id
          WHERE i.rfp_id = ? AND i.supplier_id = ?"
    );
    $inv->execute([$rfpId, $supplierId]);
    $invitation = $inv->fetch(PDO::FETCH_ASSOC);
    if (!$invitation) {
        throw new Exception('Supplier not in this RFP');
    }

    $cats = $conn->prepare(
        "SELECT id, name, description, sort_order FROM rfp_categories
          WHERE rfp_id = ? ORDER BY sort_order, id"
    );
    $cats->execute([$rfpId]);
    $categories = $cats->fetchAll(PDO::FETCH_ASSOC);

    $reqs = $conn->prepare(
        "SELECT id, category_id, requirement_text, requirement_type, priority
           FROM rfp_consolidated_requirements
          WHERE rfp_id = ?
       ORDER BY FIELD(priority,'critical','high','medium','low'), category_id, id"
    );
    $reqs->execute([$rfpId]);
    $requirements = $reqs->fetchAll(PDO::FETCH_ASSOC);

    // My scores (this analyst, this supplier)
    $mine = $conn->prepare(
        "SELECT consolidated_id, score, notes, updated_datetime
           FROM rfp_scores
          WHERE rfp_id = ? AND supplier_id = ? AND analyst_id = ?"
    );
    $mine->execute([$rfpId, $supplierId, $analystId]);
    $myScoresByReq = [];
    foreach ($mine->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $myScoresByReq[(int)$row['consolidated_id']] = [
            'score' => $row['score'] !== null ? (int)$row['score'] : null,
            'notes' => $row['notes'],
            'updated_datetime' => $row['updated_datetime'],
        ];
    }

    // Other analysts' scores — aggregated, not per-analyst, to keep
    // peer review single-blind. Show count and average so the analyst
    // can calibrate their own scoring without being unduly influenced.
    $others = $conn->prepare(
        "SELECT consolidated_id,
                COUNT(*) AS scorer_count,
                AVG(score) AS avg_score
           FROM rfp_scores
          WHERE rfp_id = ? AND supplier_id = ? AND analyst_id != ? AND score IS NOT NULL
       GROUP BY consolidated_id"
    );
    $others->execute([$rfpId, $supplierId, $analystId]);
    $othersByReq = [];
    foreach ($others->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $othersByReq[(int)$row['consolidated_id']] = [
            'scorer_count' => (int)$row['scorer_count'],
            'avg_score'    => round((float)$row['avg_score'], 2),
        ];
    }

    // Aggregate over all analysts (mine + others) — used by the suppliers
    // page later to show the overall average per supplier.
    $aggStmt = $conn->prepare(
        "SELECT COUNT(*) AS scorer_count,
                AVG(score) AS avg_score
           FROM (
                SELECT analyst_id, AVG(score) AS score
                  FROM rfp_scores
                 WHERE rfp_id = ? AND supplier_id = ? AND score IS NOT NULL
              GROUP BY analyst_id
           ) per_analyst"
    );
    $aggStmt->execute([$rfpId, $supplierId]);
    $agg = $aggStmt->fetch(PDO::FETCH_ASSOC) ?: ['scorer_count' => 0, 'avg_score' => null];

    foreach ($requirements as &$r) {
        $rid = (int)$r['id'];
        $r['my']     = $myScoresByReq[$rid] ?? ['score' => null, 'notes' => null];
        $r['others'] = $othersByReq[$rid]   ?? null;
    }
    unset($r);

    echo json_encode([
        'success'    => true,
        'rfp_id'     => $rfpId,
        'supplier'   => [
            'id'           => $supplierId,
            'display_name' => $invitation['trading_name'] ?: $invitation['legal_name'],
            'legal_name'   => $invitation['legal_name'],
        ],
        'analyst_id' => $analystId,
        'categories' => $categories,
        'requirements' => $requirements,
        'aggregate'  => [
            'scorer_count' => (int)$agg['scorer_count'],
            'avg_score'    => $agg['avg_score'] !== null ? round((float)$agg['avg_score'], 2) : null,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
