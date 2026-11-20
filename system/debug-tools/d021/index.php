<?php
// FIRST: this page loads an includes/ file before config.php, so the version guard
// must run before that file is read - see includes/php_version_guard.php.
require_once __DIR__ . '/../../../includes/php_version_guard.php';
// Debug tool D021 - the notification bell against the ticket history (GH #365). Detail +
// metadata live in the registry (system/debug-tools/includes/tools.php); rendering is shared.
$DEBUG_TOOL_SLUG = 'd021';
require __DIR__ . '/../includes/tool-page.php';
