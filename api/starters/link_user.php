<?php
/**
 * POST {id, user_id} - link a starter's case to the person once they are in Users.
 */
require_once __DIR__ . '/../../includes/starters/api_bootstrap.php';

startersApiRequirePost();
startersApiRun(function () use ($conn, $ctx) {
    $in = startersApiBody();
    StartersService::linkUser($conn, $ctx, (int)($in['id'] ?? 0), (int)($in['user_id'] ?? 0));
    startersApiOk();
});
