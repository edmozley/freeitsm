<?php
/**
 * People - the organisation chart. Reads only.
 *
 *   GET ?action=level&id=<manager, 0 = the top>&leavers=0|1   one level of the tree
 *   GET ?action=path&id=<person>&leavers=0|1                   the line from the top of the tree down to them
 *   GET ?action=person&id=<person>                             how to reach them, for the side panel
 *   GET ?action=branch&id=<person>&leavers=0|1&levels=<n>      one branch, for the chart
 *   GET ?action=context&id=<person>&leavers=0|1&levels=<n>     the line from the top to them, and their branch
 *   GET ?action=gaps                                           the gaps the chart makes visible, and the departments
 *
 * levels is how many levels below the person: 1 to PEOPLE_ORG_CHART_LEVELS_MAX
 * (32); 0 or "all" are that maximum - every level there is, as far as a
 * reporting line is ever walked; more than it is held to it; none, or anything
 * that is not a whole number, is PEOPLE_ORG_CHART_LEVELS (3). The answer says
 * what was drawn: 'levels', and 'all' when that is the maximum.
 *
 * Only people in companies this analyst can access, by the rules in
 * includes/people.php (peopleOrgLevel() and the rest). Out of reach reads as
 * "nobody", never as an error that says somebody exists.
 *
 * @wiki Organisation-Chart-Developer-Guide
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/people.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('people');

try {
    $conn = connectToDatabase();
    $aid = (int)$_SESSION['analyst_id'];
    // ?id[]=5 arrives as a list, which (int) would make 1 - somebody nobody asked for.
    $id = is_scalar($_GET['id'] ?? null) ? (int)$_GET['id'] : 0;
    $leavers = ($_GET['leavers'] ?? '') === '1';
    $levels = peopleOrgChartLevels($_GET['levels'] ?? null);

    switch ($_GET['action'] ?? '') {
        case 'level':
            $level = peopleOrgLevel($conn, $aid, max(0, $id), $leavers);
            echo json_encode(['success' => true, 'people' => $level['people'], 'more' => $level['more'], 'limit' => PEOPLE_ORG_LEVEL_LIMIT]);
            break;
        case 'path':
            $path = peopleOrgPath($conn, $aid, $id, $leavers);
            echo json_encode($path === null
                ? ['success' => false, 'error' => 'Person not found.']
                : ['success' => true, 'ids' => $path['ids'], 'is_active' => $path['is_active'], 'name' => $path['name']]);
            break;
        case 'person':
            $person = peopleOrgPerson($conn, $aid, $id);
            echo json_encode($person === null
                ? ['success' => false, 'error' => 'Person not found.']
                : ['success' => true, 'person' => $person]);
            break;
        case 'branch':
            $branch = peopleOrgBranch($conn, $aid, $id, $leavers, $levels);
            echo json_encode($branch === null
                ? ['success' => false, 'error' => 'Person not found.']
                : ['success' => true, 'branch' => $branch, 'levels' => $levels, 'all' => $levels >= PEOPLE_ORG_CHART_LEVELS_MAX, 'max' => PEOPLE_ORG_CHART_MAX]);
            break;
        case 'context':
            $context = peopleOrgContext($conn, $aid, $id, $leavers, $levels);
            echo json_encode($context === null
                ? ['success' => false, 'error' => 'Person not found.']
                : ['success' => true, 'branch' => $context, 'levels' => $levels, 'all' => $levels >= PEOPLE_ORG_CHART_LEVELS_MAX, 'max' => PEOPLE_ORG_CHART_MAX]);
            break;
        case 'gaps':
            // The departments ride along: the page hands out its colours by them.
            echo json_encode(['success' => true, 'gaps' => peopleOrgGaps($conn, $aid), 'limit' => PEOPLE_ORG_GAPS_LIMIT,
                              'departments' => peopleOrgDepartments($conn, $aid)]);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('people org: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The chart could not be loaded.']);
}
