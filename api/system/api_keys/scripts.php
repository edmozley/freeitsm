<?php
/**
 * API Endpoint (internal, session): the API script library on System -> API -> Scripts.
 *
 *   GET                         the catalogue (titles, modules, endpoints, permissions)
 *   GET ?slug=x&lang=python     one script's text, as JSON
 *   GET ?slug=x&lang=python&download=1   the file itself, as a text/plain attachment
 *
 * Read only: it never runs anything. Scripts come from script-library/ through
 * includes/api_script_library.php, which never builds a path from the request.
 * System administrators only, like the rest of the API keys endpoints.
 */
session_start(['read_and_close' => true]);
require_once '../../../config.php';
require_once '../../../includes/admin_api_guard.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/api_script_library.php';

$slug = (string)($_GET['slug'] ?? '');
$lang = (string)($_GET['lang'] ?? '');

if ($slug === '') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'scripts' => apiScriptCataloguePublic(),
        'languages' => array_map(static fn($d) => $d['label'], API_SCRIPT_LANGUAGES)]);
    exit;
}

$script = apiScriptRead($slug, $lang);
if ($script === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Script not found']);
    exit;
}

if (!empty($_GET['download'])) {
    // Always plain text and always an attachment: a script is something you save
    // and read before running, never something the browser renders or runs.
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $script['filename'] . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $script['text'];
    exit;
}

header('Content-Type: application/json');
echo json_encode(['success' => true] + $script);
