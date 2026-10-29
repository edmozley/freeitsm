<?php
/**
 * POST {id, action: issue | recover, asset_id, status_id?} - give the person a
 * piece of kit, or take it back (and optionally set its status - Retired
 * included). Both go through Assets custody, so its history, events and
 * handover document are the Assets module's own. Needs Assets as well.
 */
require_once __DIR__ . '/../../includes/starters/api_bootstrap.php';

startersApiRequirePost();
startersApiRun(function () use ($conn, $ctx, $analystId) {
    if (!analystCanAccessModule($conn, $analystId, 'assets')) startersApiFail('You need access to Assets to issue or recover kit.');
    $in = startersApiBody();
    $id = (int)($in['id'] ?? 0);
    $asset = (int)($in['asset_id'] ?? 0);
    if (($in['action'] ?? '') === 'issue') {
        StartersService::issueKit($conn, $ctx, $id, $asset);
    } elseif (($in['action'] ?? '') === 'recover') {
        StartersService::recoverKit($conn, $ctx, $id, $asset, !empty($in['status_id']) ? (int)$in['status_id'] : null);
    } else {
        startersApiFail('Unknown action.');
    }
    startersApiOk();
});
