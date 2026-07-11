<?php
/**
 * Shared administrator gate for System-module JSON APIs.
 *
 * Require this AFTER session_start() and config.php, near the top of any endpoint
 * that manages analysts, teams, company access, SSO, security, API keys, demo data,
 * DB verify, etc. It loads functions.php, then refuses non-administrators with a 403
 * (authoritative DB check via requireAdminJson(), so a just-demoted analyst can't act
 * on a stale session). Read-only endpoints used by normal ticket flows must NOT
 * include this — see includes/functions.php analystIsAdmin().
 */
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireAdminJson(connectToDatabase());
