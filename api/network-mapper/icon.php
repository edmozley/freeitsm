<?php
/**
 * API: one icon from an imported icon library (GitHub #433) - the picture a diagram's 'icon'
 * shape shows. Analysts with Network Mapper access only.
 *
 * 🔴 SVG can carry script. The icon was cleaned on import (NetworkIconService::cleanSvg) and is
 * served here with a sandbox CSP and nosniff as well, so even opening this address directly
 * runs nothing. An icon never changes once imported (a re-import is a new id), so it caches.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/network_icons.php';

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    exit;
}
requireModuleAccessJson('network-mapper');

$id = (int)($_GET['id'] ?? 0);
$icon = $id > 0 ? NetworkIconService::iconData(connectToDatabase(), $id) : null;
$allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];
if (!$icon || !in_array($icon['mime'], $allowed, true)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $icon['mime']);
header('Content-Length: ' . strlen($icon['data']));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox");
echo $icon['data'];
