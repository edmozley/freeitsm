<?php
/**
 * Return the full section tree for an RFP — every category with its
 * generated section content (or null if not yet generated). Powers
 * the document.php page in 4a; same shape will work for the full
 * preview + PDF export in 4d.
 *
 * Includes the lock state of the RFP's consolidated requirements so
 * the page can show the right call-to-action — generation is gated
 * on a fully-locked consolidation set.
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
        "SELECT c.id, c.name, c.description, c.sort_order,
                (SELECT COUNT(*) FROM rfp_consolidated_requirements cr WHERE cr.category_id = c.id) AS req_count,
                s.id          AS section_id,
                s.section_title,
                s.section_content,
                s.version,
                s.is_manually_edited,
                s.requirements_hash,
                s.generated_datetime,
                s.edited_datetime
           FROM rfp_categories c
      LEFT JOIN rfp_output_sections s ON s.category_id = c.id
          WHERE c.rfp_id = ?
       ORDER BY c.sort_order, c.id"
    );
    $cats->execute([$rfpId]);
    $rows = $cats->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['req_count']          = (int)$r['req_count'];
        $r['section_id']         = $r['section_id'] !== null ? (int)$r['section_id']         : null;
        $r['version']            = $r['version']    !== null ? (int)$r['version']            : null;
        $r['is_manually_edited'] = $r['is_manually_edited'] !== null ? (bool)$r['is_manually_edited'] : null;
        // Skip-up-to-date flag: section exists, hash matches the one
        // it was last generated against, and analyst hasn't edited it.
        // The current "input hash" must be recomputed from live data —
        // but for the page render we just expose `requirements_hash`
        // and let the page decide. For 4a we just say "needs regen?"
        // is true only if there's no section yet.
        $r['needs_first_generation'] = ($r['section_id'] === null);
    }
    unset($r);

    $lockState = $conn->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) AS locked
           FROM rfp_consolidated_requirements WHERE rfp_id = ?"
    );
    $lockState->execute([$rfpId]);
    $ls = $lockState->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'locked' => 0];
    $allLocked = ((int)$ls['total']) > 0 && ((int)$ls['locked']) === ((int)$ls['total']);

    echo json_encode([
        'success'  => true,
        'rfp_id'   => $rfpId,
        'categories' => $rows,
        'lock'     => [
            'total'      => (int)$ls['total'],
            'locked'     => (int)$ls['locked'],
            'all_locked' => $allLocked,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
