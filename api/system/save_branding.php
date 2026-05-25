<?php
/**
 * API: Save organisation-wide branding settings (text slots + optional logo upload).
 *
 * Accepts multipart/form-data so the logo file can ride along in the same
 * request as the text slots. Logo file is optional — pass `remove_logo=1` to
 * explicitly clear an existing logo, otherwise omit the file to leave the
 * existing logo unchanged.
 *
 * Text slots are limited to 200 chars each — these are toolbar-strip text,
 * not paragraphs. Tokens (`{{title}}` etc.) are passed through verbatim and
 * resolved at render time client-side.
 *
 * POST fields:
 *   header_left, header_center, header_right
 *   footer_left, footer_center, footer_right
 *   remove_logo (optional, '1' to clear the stored logo)
 *   logo (optional file upload)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $textKeys = [
        'header_left', 'header_center', 'header_right',
        'footer_left', 'footer_center', 'footer_right',
    ];
    $maxLen = 200;
    $values = [];
    foreach ($textKeys as $k) {
        $v = (string)($_POST[$k] ?? '');
        if (mb_strlen($v) > $maxLen) {
            throw new Exception("'$k' is too long (max $maxLen characters)");
        }
        $values[$k] = $v;
    }

    $conn = connectToDatabase();

    $upsert = function (PDO $conn, string $key, ?string $value): void {
        $stmt = $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value)
                  VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
    };

    foreach ($values as $k => $v) {
        $upsert($conn, 'branding_' . $k, $v);
    }

    // Logo handling — three paths: upload new / explicitly remove / leave alone
    $removeLogo = ($_POST['remove_logo'] ?? '') === '1';
    $hasFile = isset($_FILES['logo']) && is_array($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE;

    if ($hasFile) {
        $f = $_FILES['logo'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Logo upload failed (error code ' . (int)$f['error'] . ')');
        }
        if ($f['size'] > 2 * 1024 * 1024) {
            throw new Exception('Logo too large (max 2 MB)');
        }
        // Whitelist on extension AND mime so a renamed .php can't slip past.
        // Allowed: PNG, JPG, SVG (vector ideal for crisp print/export).
        $allowed = [
            'png'  => ['image/png'],
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'svg'  => ['image/svg+xml', 'text/xml', 'application/xml'],
        ];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            throw new Exception('Unsupported logo format. Use PNG, JPG, or SVG.');
        }
        $mime = function_exists('mime_content_type') ? @mime_content_type($f['tmp_name']) : null;
        if ($mime && !in_array($mime, $allowed[$ext], true)) {
            throw new Exception('Logo file content does not match its extension.');
        }
        $uploadDir = __DIR__ . '/../../system/uploads/branding';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new Exception('Could not create upload directory');
            }
        }
        // Tear down any previous logo before saving the new one — otherwise
        // a switch from logo.png to logo.svg leaves the stale png behind
        foreach (glob($uploadDir . '/logo.*') as $old) {
            @unlink($old);
        }
        $destName = 'logo.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $destPath = $uploadDir . '/' . $destName;
        if (!move_uploaded_file($f['tmp_name'], $destPath)) {
            throw new Exception('Failed to save logo file');
        }
        $relPath = 'system/uploads/branding/' . $destName;
        $upsert($conn, 'branding_logo_path', $relPath);
    } elseif ($removeLogo) {
        // Explicit remove — delete file(s) and clear the DB pointer
        $uploadDir = __DIR__ . '/../../system/uploads/branding';
        if (is_dir($uploadDir)) {
            foreach (glob($uploadDir . '/logo.*') as $old) {
                @unlink($old);
            }
        }
        $upsert($conn, 'branding_logo_path', null);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
