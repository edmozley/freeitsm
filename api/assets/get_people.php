<?php
/**
 * API: the people directory (directory sync slice 1).
 *
 * GET ?search=<text>&scope=current|leavers|everyone|holding
 *
 * The companion to get_users_with_assets.php, which lists only current asset
 * holders. That is the right answer to "who has equipment" and the wrong one to
 * "who is there" — and you cannot issue a laptop to somebody the list refuses to
 * show you.
 *
 * Thin UI adapter over AssetsService::people(), which applies the tenancy filter
 * to the PERSON, so this returns only people the analyst is allowed to see.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/assets.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');

$scope = (string)($_GET['scope'] ?? 'current');
if (!in_array($scope, ['current', 'leavers', 'everyone', 'holding'], true)) $scope = 'current';

try {
    $conn = connectToDatabase();
    $ctx = ActorContext::fromSession($conn);
    $people = AssetsService::people(
        $conn,
        $ctx,
        (string)($_GET['search'] ?? ''),
        $scope,
        500,
        (string)($_GET['source'] ?? '')
    );
    $out = ['success' => true, 'users' => $people, 'scope' => $scope];
    // What the Source filter offers, counted inside the same company scope.
    if (!empty($_GET['include_sources'])) {
        [$tSql, $tArgs] = activeTenantReadFilter($conn, $ctx->actorId, 'u');
        $out['sources'] = userSourcesInScope($conn, $tSql, $tArgs);
    }
    echo json_encode($out);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
