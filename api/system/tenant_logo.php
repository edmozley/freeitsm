<?php
/**
 * API Endpoint: a company's own logo (System -> Companies).
 *
 *   POST multipart {tenant_id, logo}   upload / replace
 *   POST multipart {tenant_id, remove: 1}
 *
 * Shown in the corner of the Files desktop for people working in that company
 * (System -> Branding -> Files desktop decides whether a logo shows at all),
 * falling back to the organisation logo when a company has none.
 *
 * Stored in system/uploads/branding/companies/ under OUR random name, like the
 * organisation logo: a web-servable folder, because the desktop shows it by URL,
 * with execution blocked (uploadPrepareWebServableDir). Raster images only - SVG
 * can carry script, and this file is served from our own origin.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/uploads.php';

header('Content-Type: application/json');

const TENANT_LOGO_DIR = 'system/uploads/branding/companies';
const TENANT_LOGO_TYPES = [
    'png'  => ['image/png'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
];

try {
    $conn = connectToDatabase();
    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    $st = $conn->prepare("SELECT id, logo_path FROM tenants WHERE id = ?");
    $st->execute([$tenantId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new Exception('Company not found.');

    $root = dirname(__DIR__, 2) . '/';
    $removeOld = function () use ($t, $root) {
        $old = (string)($t['logo_path'] ?? '');
        if ($old !== '' && preg_match('#^' . preg_quote(TENANT_LOGO_DIR, '#') . '/[0-9a-f]{32}\.[a-z]{3,4}$#', $old)) {
            @unlink($root . $old);
        }
    };

    if (($_POST['remove'] ?? '') === '1') {
        $removeOld();
        $conn->prepare("UPDATE tenants SET logo_path = NULL WHERE id = ?")->execute([$tenantId]);
        echo json_encode(['success' => true, 'logo_url' => null]);
        exit;
    }

    if (empty($_FILES['logo']) || $_FILES['logo']['error'] === UPLOAD_ERR_NO_FILE) throw new Exception('Choose an image first.');
    $dir = $root . TENANT_LOGO_DIR;
    uploadPrepareWebServableDir($dir);
    $stored = uploadStoreFile($_FILES['logo'], $dir, TENANT_LOGO_TYPES, 2 * 1024 * 1024);
    $removeOld();
    $rel = TENANT_LOGO_DIR . '/' . $stored['stored_name'];
    $conn->prepare("UPDATE tenants SET logo_path = ? WHERE id = ?")->execute([$rel, $tenantId]);
    echo json_encode(['success' => true, 'logo_url' => BASE_URL . $rel]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
