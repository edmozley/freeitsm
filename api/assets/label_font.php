<?php
/**
 * API: one uploaded label font, for the browser to draw labels with (GH #229).
 *
 * GET ?id=  the font file, to anybody with the Assets module in the font's company
 *           (or any company, for a font made for every company). The folder it lives
 *           in is not served directly: system/uploads/label-fonts is not web-servable.
 *
 * @wiki Asset-Label-Designer-Design
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/asset_label_designs.php';

if (!isset($_SESSION['analyst_id'])) { http_response_code(401); exit; }
requireModuleAccessJson('assets');

$conn = connectToDatabase();
if (!assetLabelDesignsReady($conn)) { http_response_code(404); exit; }
$tenantId = getActiveTenantId($conn, (int)$_SESSION['analyst_id']);
$s = $conn->prepare("SELECT stored_name, format FROM asset_label_fonts WHERE id = ? AND (tenant_id <=> ? OR tenant_id IS NULL)");
$s->execute([(int)($_GET['id'] ?? 0), $tenantId]);
$f = $s->fetch(PDO::FETCH_ASSOC);
$path = $f ? __DIR__ . '/../../system/uploads/label-fonts/' . basename((string)$f['stored_name']) : '';
if (!$f || !is_file($path)) { http_response_code(404); exit; }

$types = ['ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];
header('Content-Type: ' . ($types[$f['format']] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
