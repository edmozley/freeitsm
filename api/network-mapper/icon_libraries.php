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
 *   POST multipart: action=upload, file=<picture>   -> {icon}   (My images: PNG / JPEG / GIF / WebP, 4 MB)
 *   POST {action:'delete_image', id}                 (one picture out of My images)
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
    $isUpload = $method === 'POST' && isset($_FILES['file']);
    $data = $isUpload ? $_POST : ($method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET);
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
    if ($isUpload && $action === 'upload') {
        // GH #433: an uploaded or pasted picture, into My images. Read from PHP's own temp file
        // and stored in the database - nothing is written to a served folder.
        $f = $_FILES['file'];
        $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            // PHP's upload_max_filesize (2 MB on a stock install) can be lower than the 4 MB allowed here
            throw new ServiceError('validation', 'too_big', 'That picture is larger than this server accepts.');
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new ServiceError('validation', 'not_image', 'The picture did not arrive.');
        }
        $bytes = (string)file_get_contents($f['tmp_name']);
        echo json_encode(['success' => true, 'icon' => NetworkIconService::addUpload($conn, $ctx, $bytes, (string)($f['name'] ?? 'picture'))]);
    } elseif ($action === 'delete_image') {
        NetworkIconService::deleteUpload($conn, (int)($data['id'] ?? 0));
        echo json_encode(['success' => true]);
    } elseif ($action === 'import_start') {
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
