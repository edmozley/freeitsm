<?php
/**
 * System -> API -> Activity: the archived days of the API activity log (GH #287).
 *
 * GET                 -> { success, archives: [{name, from, to, bytes}] }
 * GET ?download=NAME  -> the zip
 * POST {delete: NAME} -> { success }
 *
 * Administrators only. NAME must be exactly api-log-<YYYYMMDD>-<YYYYMMDD>.zip, so nothing
 * outside uploads/api-log-archive/ can be named. The folder itself is denied to
 * direct requests (.htaccess, web.config, nginx /uploads/).
 */
session_start(['read_and_close' => true]);
require_once '../../../config.php';
require_once '../../../includes/admin_api_guard.php'; // System admins only
require_once '../../../includes/functions.php';
require_once '../../../includes/api_security.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

/** The archive's path, or null when the name is not one of ours. */
function apiArchivePath(string $name): ?string {
    if (!preg_match(API_LOG_ARCHIVE_NAME, $name)) return null;
    $path = apiLogArchiveDir() . '/' . $name;
    return is_file($path) ? $path : null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfRequireWriteMethod();
        header('Content-Type: application/json');
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $path = apiArchivePath((string)($in['delete'] ?? ''));
        if ($path === null) throw new Exception('No such archive.');
        if (!@unlink($path)) throw new Exception('The archive could not be deleted.');
        echo json_encode(['success' => true]);
        exit;
    }
    if (isset($_GET['download'])) {
        $path = apiArchivePath((string)$_GET['download']);
        if ($path === null) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit("Not found\n"); }
        header('Content-Type: application/zip');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'archives' => apiLogArchives()]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
