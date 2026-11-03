<?php
/**
 * API: asset label designs and label fonts (GH #229, slice 1).
 *
 * GET                     the active company's designs (its "Standard" design is made the
 *                         first time), the label sizes, the templates, the fonts, and whether
 *                         this analyst may change them
 * POST {action:'create', name, template, stock:{key, w?, h?, offset_x?, offset_y?}, font, mono?, default?}
 * POST {action:'update', id, version, name, stock, font, mono?}
 * POST {action:'set_default', id}
 * POST {action:'delete', id}
 * POST multipart action=upload_font, name, licence=1, font=<file>
 * POST {action:'delete_font', id}
 *
 * Reading needs the Assets module (whoever prints labels picks a design). Every write
 * needs Cap::ASSETS_LABELS, the permission the label settings have always had. A
 * design or font belongs to one company; a write to another company's is "not found".
 *
 * @wiki Asset-Label-Designer-Design
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/tenant_settings.php';
require_once '../../includes/capabilities.php';
require_once '../../includes/uploads.php';
require_once '../../includes/asset_label_designs.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');

/** Stops with a JSON error. */
function aldFail(string $msg, int $code = 200): never
{
    if ($code !== 200) http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    if (!assetLabelDesignsReady($conn)) aldFail(t('asset-management.label_designs.not_ready'));
    $tenantId = getActiveTenantId($conn, $analystId);
    $canManage = analystHasCapability($conn, $analystId, Cap::ASSETS_LABELS);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $stocks = [];
        foreach (assetLabelStocks() as $k => $st) $stocks[] = ['key' => (string)$k] + $st;
        echo json_encode([
            'success'    => true,
            'designs'    => assetLabelDesignsFor($conn, $tenantId, $analystId),
            'stocks'     => $stocks,
            'templates'  => ASSET_LABEL_TEMPLATES,
            'fonts'      => assetLabelFontsFor($conn, $tenantId),
            'can_manage' => $canManage,
        ]);
        exit;
    }

    csrfRequireWriteMethod();
    if (!$canManage) aldFail(t('asset-management.label_designs.no_permission'), 403);
    $isMultipart = !empty($_POST) || !empty($_FILES);
    $in = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);
    $action = (string)($in['action'] ?? '');

    /** The design, if it is this company's; otherwise stop as not found. */
    $own = function (int $id) use ($conn, $tenantId): array {
        $s = $conn->prepare("SELECT * FROM asset_label_designs WHERE id = ? AND tenant_id <=> ?");
        $s->execute([$id, $tenantId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) aldFail(t('asset-management.label_designs.not_found'), 404);
        return $r;
    };

    switch ($action) {
        case 'create':
            try {
                $id = assetLabelDesignCreate($conn, $tenantId, (string)($in['name'] ?? ''), (string)($in['template'] ?? ''), (array)($in['stock'] ?? []),
                                             $analystId, !empty($in['default']), ['font' => (string)($in['font'] ?? '')] + (isset($in['mono']) ? ['mono' => !empty($in['mono'])] : []));
            } catch (InvalidArgumentException $e) { aldFail($e->getMessage()); }
            echo json_encode(['success' => true, 'id' => $id]);
            exit;

        case 'update':
            $row = $own((int)($in['id'] ?? 0));
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) aldFail(t('asset-management.label_designs.name_needed'));
            try { $stock = assetLabelStockFrom((array)($in['stock'] ?? [])); } catch (InvalidArgumentException $e) { aldFail($e->getMessage()); }
            $design = json_decode((string)$row['design_json'], true) ?: ['v' => 1, 'template' => 'standard'];
            $design['font'] = assetLabelFontRef($conn, $tenantId, (string)($in['font'] ?? ASSET_LABEL_BUILTIN_FONT));
            if (isset($in['mono'])) $design['mono'] = !empty($in['mono']);
            // Somebody else saved it since this screen loaded it: say so rather than overwrite.
            $u = $conn->prepare("UPDATE asset_label_designs SET name = ?, stock_json = ?, design_json = ?, version = version + 1, updated_by = ?, updated_datetime = UTC_TIMESTAMP()
                                  WHERE id = ? AND version = ?");
            $u->execute([$name, json_encode($stock), json_encode($design), $analystId, (int)$row['id'], (int)($in['version'] ?? 0)]);
            if (!$u->rowCount()) aldFail(t('asset-management.label_designs.changed_meanwhile'), 409);
            echo json_encode(['success' => true]);
            exit;

        case 'save_items':
            // The designer (slice 2): the design's own items, cleaned, on its own size.
            // From now on it no longer follows a template.
            $row = $own((int)($in['id'] ?? 0));
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) aldFail(t('asset-management.label_designs.name_needed'));
            try { $stock = assetLabelStockFrom((array)($in['stock'] ?? [])); } catch (InvalidArgumentException $e) { aldFail($e->getMessage()); }
            $items = assetLabelSanitiseItems((array)($in['items'] ?? []), $stock, array_keys(assetLabelAvailableFields($conn, $tenantId)));
            $design = ['v' => 1, 'template' => 'custom', 'font' => assetLabelFontRef($conn, $tenantId, (string)($in['font'] ?? ASSET_LABEL_BUILTIN_FONT)), 'items' => $items];
            // The black-only setting is made on the settings screen, not here: keep it.
            $was = json_decode((string)$row['design_json'], true) ?: [];
            if (isset($was['mono'])) $design['mono'] = (bool)$was['mono'];
            $u = $conn->prepare("UPDATE asset_label_designs SET name = ?, stock_json = ?, design_json = ?, version = version + 1, updated_by = ?, updated_datetime = UTC_TIMESTAMP()
                                  WHERE id = ? AND version = ?");
            $u->execute([$name, json_encode($stock), json_encode($design), $analystId, (int)$row['id'], (int)($in['version'] ?? 0)]);
            if (!$u->rowCount()) aldFail(t('asset-management.label_designs.changed_meanwhile'), 409);
            echo json_encode(['success' => true, 'version' => (int)$in['version'] + 1, 'items' => $items]);
            exit;

        case 'set_default':
            $row = $own((int)($in['id'] ?? 0));
            $conn->beginTransaction();
            $conn->prepare("UPDATE asset_label_designs SET is_default = 0 WHERE tenant_id <=> ?")->execute([$tenantId]);
            $conn->prepare("UPDATE asset_label_designs SET is_default = 1 WHERE id = ?")->execute([(int)$row['id']]);
            $conn->commit();
            echo json_encode(['success' => true]);
            exit;

        case 'delete':
            $row = $own((int)($in['id'] ?? 0));
            $conn->prepare("DELETE FROM asset_label_designs WHERE id = ?")->execute([(int)$row['id']]);
            // The default went with it: the next design takes over, so Print label always has one.
            if ((int)$row['is_default'] === 1) {
                $conn->prepare("UPDATE asset_label_designs SET is_default = 1 WHERE tenant_id <=> ? ORDER BY id LIMIT 1")->execute([$tenantId]);
            }
            echo json_encode(['success' => true]);
            exit;

        case 'upload_font':
            if (empty($in['licence'])) aldFail(t('asset-management.label_designs.font_licence_needed'));
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) aldFail(t('asset-management.label_designs.font_name_needed'));
            $file = $_FILES['font'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                aldFail(t('asset-management.label_designs.font_missing'));
            }
            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            // The real check: the file's own first bytes, whatever this server's fileinfo calls it.
            if (!assetLabelFontLooksValid($file['tmp_name'], $ext)) aldFail(t('asset-management.label_designs.font_invalid'));
            if ((int)$file['size'] > 20 * 1024 * 1024) aldFail(t('asset-management.label_designs.font_too_big'));
            $dir = __DIR__ . '/../../system/uploads/label-fonts';
            uploadPrepareDir($dir);
            $stored = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) aldFail(t('asset-management.label_designs.font_not_saved'));
            $conn->prepare("INSERT INTO asset_label_fonts (tenant_id, name, original_name, stored_name, format, size_bytes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                 ->execute([$tenantId, $name, mb_substr(uploadCleanName((string)$file['name']), 0, 255), $stored, $ext, (int)$file['size'], $analystId]);
            echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
            exit;

        case 'delete_font':
            $id = (int)($in['id'] ?? 0);
            $s = $conn->prepare("SELECT * FROM asset_label_fonts WHERE id = ? AND tenant_id <=> ?");
            $s->execute([$id, $tenantId]);
            $font = $s->fetch(PDO::FETCH_ASSOC);
            if (!$font) aldFail(t('asset-management.label_designs.not_found'), 404);
            // A font in use is never removed from under a design: name the designs instead.
            $u = $conn->prepare("SELECT name FROM asset_label_designs WHERE design_json LIKE ?");
            $u->execute(['%"font:' . $id . '"%']);
            $using = $u->fetchAll(PDO::FETCH_COLUMN);
            if ($using) aldFail(t('asset-management.label_designs.font_in_use', ['designs' => implode(', ', $using)]));
            $conn->prepare("DELETE FROM asset_label_fonts WHERE id = ?")->execute([$id]);
            $path = __DIR__ . '/../../system/uploads/label-fonts/' . basename((string)$font['stored_name']);
            if (is_file($path)) @unlink($path);
            echo json_encode(['success' => true]);
            exit;
    }
    aldFail('Unknown action.');
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('label_designs: ' . $e->getMessage());
    aldFail(t('asset-management.label_designs.error'));
}
