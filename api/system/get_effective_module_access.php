<?php
/**
 * API: effective module access for one analyst, with reasons (issue #30). Admins only.
 * GET ?analyst_id=N  →  per-module { key, name, allowed, reason }, plus the analyst's
 * name and the active mode. Mirrors getAnalystAllowedModules()'s resolution so the
 * explanation always matches what enforcement will actually do.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';

header('Content-Type: application/json');

$analystId = (int) ($_GET['analyst_id'] ?? 0);
if ($analystId <= 0) {
    echo json_encode(['success' => false, 'error' => 'analyst_id is required']);
    exit;
}

try {
    $conn = connectToDatabase();
    $mode = getModulePermissionMode($conn);

    $aStmt = $conn->prepare("SELECT full_name, can_access_all_modules FROM analysts WHERE id = ?");
    $aStmt->execute([$analystId]);
    $analyst = $aStmt->fetch(PDO::FETCH_ASSOC);
    if (!$analyst) { echo json_encode(['success' => false, 'error' => 'Analyst not found']); exit; }

    $analystAll = (int) $analyst['can_access_all_modules'] === 1;
    $analystSet = $analystAll ? [] : $conn->query("SELECT module_key FROM analyst_modules WHERE analyst_id = " . $analystId)->fetchAll(PDO::FETCH_COLUMN);

    // Teams the analyst is in.
    $teams = [];
    $tStmt = $conn->prepare("SELECT t.id, t.name, t.can_access_all_modules FROM analyst_teams at JOIN teams t ON at.team_id = t.id WHERE at.analyst_id = ?");
    $tStmt->execute([$analystId]);
    foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $all = (int) $t['can_access_all_modules'] === 1;
        $teams[] = [
            'name' => $t['name'],
            'all'  => $all,
            'set'  => $all ? [] : $conn->query("SELECT module_key FROM team_modules WHERE team_id = " . (int) $t['id'])->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    $rows = [];
    foreach (getModuleRegistry() as $key => $name) {
        $allowed = false;
        $reason  = '';

        if ($mode === 'least') {
            // Denied unless every SPECIFIC source (analyst + specific teams) grants it.
            $blockers = [];
            if (!$analystAll && !in_array($key, $analystSet, true)) $blockers[] = 'their own access';
            foreach ($teams as $t) {
                if (!$t['all'] && !in_array($key, $t['set'], true)) $blockers[] = 'team "' . $t['name'] . '"';
            }
            if (empty($blockers)) {
                $allowed = true;
                $reason  = 'Allowed — their own access and every team permit it';
            } else {
                $allowed = false;
                $reason  = 'Blocked (strict mode) — not granted by ' . implode(' and ', $blockers);
            }
        } else {
            // most: allowed if ANY source grants it.
            if ($analystAll)                         { $allowed = true; $reason = 'Granted — this analyst has all modules'; }
            elseif (in_array($key, $analystSet, true)) { $allowed = true; $reason = 'Granted directly'; }
            else {
                foreach ($teams as $t) {
                    if ($t['all'])                         { $allowed = true; $reason = 'Granted via team "' . $t['name'] . '" (all modules)'; break; }
                    if (in_array($key, $t['set'], true))   { $allowed = true; $reason = 'Granted via team "' . $t['name'] . '"'; break; }
                }
                if (!$allowed) $reason = 'Not granted — no individual or team access';
            }
        }

        $rows[] = ['key' => $key, 'name' => $name, 'allowed' => $allowed, 'reason' => $reason];
    }

    echo json_encode([
        'success'      => true,
        'analyst_name' => $analyst['full_name'],
        'mode'         => $mode,
        'modules'      => $rows,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
