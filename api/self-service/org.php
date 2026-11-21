<?php
/**
 * API: the organisation chart for the signed-in portal user (GH #414). Reads only.
 *
 * The People module's chart endpoint (api/people/org.php) with the portal's reader
 * in place of an analyst - the same queries in includes/people.php, asked as a
 * PeopleOrgReader: the reader's own company, current staff only.
 *
 *   GET ?action=level&id=<manager, 0 = the top>     one level of the tree
 *   GET ?action=path&id=<person>                    the line from the top down to them
 *   GET ?action=branch&id=<person>&levels=<n>       one branch, for the chart
 *   GET ?action=context&id=<person>&levels=<n>      the line from the top to them, and their branch
 *   GET ?action=gaps                                the departments only (the chart's colours)
 *   GET ?action=search&q=<text>                     up to 10 people, for the search box
 *
 * Not here, deliberately: action=person (email, phone, office - contact details the
 * portal does not hand out) and the gaps themselves (an administrator's to-do list).
 * leavers= is ignored: the portal never shows a leaver.
 *
 * TRAP: this endpoint is the permission, not the page or the nav - every request asks
 * portalOrgChartAllowed() (includes/portal_org_chart.php).
 *
 * @wiki Organisation-Chart-on-the-Portal-Developer-Guide
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/portal_org_chart.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$userId = (int)$_SESSION['ss_user_id'];

try {
    $conn = connectToDatabase();
    requireModuleOnJson($conn, 'people');
    if (!portalOrgChartAllowed($conn, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'The organisation chart is not available']);
        exit;
    }
    $reader = PeopleOrgReader::portal($conn, $userId);
    // ?id[]=5 arrives as a list, which (int) would make 1 - somebody nobody asked for.
    $id = is_scalar($_GET['id'] ?? null) ? (int)$_GET['id'] : 0;
    $levels = peopleOrgChartLevels($_GET['levels'] ?? null);

    switch ($_GET['action'] ?? '') {
        case 'level':
            $level = peopleOrgLevel($conn, $reader, max(0, $id), false);
            echo json_encode(['success' => true, 'people' => $level['people'], 'more' => $level['more'], 'limit' => PEOPLE_ORG_LEVEL_LIMIT]);
            break;
        case 'path':
            $path = peopleOrgPath($conn, $reader, $id, false);
            echo json_encode($path === null
                ? ['success' => false, 'error' => 'Person not found.']
                : ['success' => true, 'ids' => $path['ids'], 'is_active' => $path['is_active'], 'name' => $path['name']]);
            break;
        case 'branch':
            $branch = peopleOrgBranch($conn, $reader, $id, false, $levels);
            echo json_encode($branch === null
                ? ['success' => false, 'error' => 'Person not found.']
                : ['success' => true, 'branch' => $branch, 'levels' => $levels, 'all' => $levels >= PEOPLE_ORG_CHART_LEVELS_MAX, 'max' => PEOPLE_ORG_CHART_MAX]);
            break;
        case 'context':
            $context = peopleOrgContext($conn, $reader, $id, false, $levels);
            echo json_encode($context === null
                ? ['success' => false, 'error' => 'Person not found.']
                : ['success' => true, 'branch' => $context, 'levels' => $levels, 'all' => $levels >= PEOPLE_ORG_CHART_LEVELS_MAX, 'max' => PEOPLE_ORG_CHART_MAX]);
            break;
        case 'gaps':
            echo json_encode(['success' => true, 'gaps' => (object)[], 'limit' => 0, 'departments' => peopleOrgDepartments($conn, $reader)]);
            break;
        case 'search':
            echo json_encode(['success' => true, 'people' => portalOrgChartSearch($conn, $reader, is_scalar($_GET['q'] ?? null) ? (string)$_GET['q'] : '')]);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('self-service/org.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The chart could not be loaded.']);
}
