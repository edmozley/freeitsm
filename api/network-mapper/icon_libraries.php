<?php
/**
 * API: icon libraries from icons.diagrams.net (GitHub #433). Thin adapter over
 * NetworkIconService - see includes/services/network_icons.php for the rules.
 *
 *   GET  ?action=catalogue[&refresh=1]   every set on the site (cached a day)
 *   GET  ?action=preview&slug=X          one set: licence, author, every icon (remote addresses)
 *   GET  ?action=libraries               the sets imported into this install
 *   GET  ?action=palette                 every imported icon (no picture data) for the Shapes tab
 *   POST {action:'import_start', slug}            -> {id, total}
 *   POST {action:'import_batch', id, offset}      -> {next, total, imported, skipped, done}
 *   POST {action:'delete', id}
 *
 * Changes are POST only. Any analyst with Network Mapper access may import or remove a set -
 * an imported set is shared by the whole install.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/network_icons.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('network-mapper');

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $data = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
    $action = (string)($data['action'] ?? '');

    if ($method === 'GET' && $action === 'catalogue') {
        echo json_encode(['success' => true, 'sets' => NetworkIconService::catalogue(!empty($_GET['refresh']))]);
        exit;
    }
    if ($method === 'GET' && $action === 'preview') {
        echo json_encode(['success' => true, 'library' => NetworkIconService::library((string)($_GET['slug'] ?? ''))]);
        exit;
    }
    $conn = connectToDatabase();
    if ($method === 'GET' && $action === 'libraries') {
        echo json_encode(['success' => true, 'libraries' => NetworkIconService::libraries($conn)]);
        exit;
    }
    if ($method === 'GET' && $action === 'palette') {
        echo json_encode(['success' => true, 'libraries' => NetworkIconService::libraries($conn), 'icons' => NetworkIconService::paletteIcons($conn)]);
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }
    @set_time_limit(180);
    $ctx = ActorContext::fromSession($conn);
    if ($action === 'import_start') {
        echo json_encode(['success' => true] + NetworkIconService::importStart($conn, $ctx, (string)($data['slug'] ?? '')));
    } elseif ($action === 'import_batch') {
        echo json_encode(['success' => true] + NetworkIconService::importBatch($conn, (int)($data['id'] ?? 0), (int)($data['offset'] ?? 0)));
    } elseif ($action === 'delete') {
        NetworkIconService::deleteLibrary($conn, (int)($data['id'] ?? 0));
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
