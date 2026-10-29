<?php
/**
 * POST {id} - mark a leaver as left in Users, the same change the person screen
 * makes. Refused for a person kept up to date from a directory.
 */
require_once __DIR__ . '/../../includes/starters/api_bootstrap.php';

startersApiRequirePost();
startersApiRun(function () use ($conn, $ctx) {
    StartersService::markLeft($conn, $ctx, (int)(startersApiBody()['id'] ?? 0));
    startersApiOk();
});
