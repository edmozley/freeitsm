<?php
/**
 * POST {id, status: done | cancelled | open} - mark a case done, cancel it or
 * reopen it. Cancelling and reopening need Manage Starters & leavers, or to be
 * the case's owner; marking done is anyone working on it.
 */
require_once __DIR__ . '/../../includes/starters/api_bootstrap.php';

startersApiRequirePost();
startersApiRun(function () use ($conn, $ctx, $analystId) {
    $in = startersApiBody();
    $id = (int)($in['id'] ?? 0);
    $status = (string)($in['status'] ?? '');
    if ($status !== 'done') {
        $case = StartersService::loadForActor($conn, $ctx, $id);
        if ((int)$case['owner_analyst_id'] !== $analystId && !analystHasCapability($conn, $analystId, Cap::STARTERS_MANAGE)) {
            startersApiFail('Only the case\'s owner, or somebody who manages Starters & leavers, can cancel or reopen it.');
        }
    }
    StartersService::setStatus($conn, $ctx, $id, $status);
    startersApiOk();
});
