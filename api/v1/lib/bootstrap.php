<?php
/**
 * FreeITSM REST API v1 — bootstrap.
 *
 * Shared boot for every v1 request: config + core includes, JSON/CORS headers,
 * and a last-resort exception handler so an unexpected error still returns a
 * well-formed JSON error instead of an HTML stack trace.
 *
 * The legacy machine-ingest endpoints under api/external/ are untouched by all
 * of this — v1 is a separate, versioned surface with its own key store.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/tenancy.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('X-FreeITSM-Api-Version: 1');

// CORS preflight — answer before any auth.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// The API must never leak HTML notices/warnings into a JSON body.
ini_set('display_errors', '0');

set_exception_handler(function ($e) {
    error_log('API v1 uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    apiError(500, 'server_error', 'An unexpected server error occurred.');
});
