<?php
/**
 * API: how many drives across the estate look like this one (#97).
 *
 * GET ?asset_id=123&model=Microsoft+Virtual+Disk&size_bytes=32901120
 *   -> { success, drives, assets, others }
 *
 * Answers the question the Hide dialog puts to the user before they commit:
 * "50 drives on 50 machines look like this one — hide those too?" A count is
 * the whole point of the dialog, so it is fetched when the button is pressed
 * rather than for every drive on every asset screen.
 *
 * `size_bytes` is sent as a STRING and passed through as one. It is a BIGINT
 * and PHP ints are signed 64-bit, so casting is safe today, but the matching
 * helper compares NULL-safely and a string keeps "absent" and "zero" apart —
 * and zero is not hypothetical here, it is the virtual disk this exists for.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/asset_disk_visibility.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('assets');

try {
    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $assetId   = (int)($_GET['asset_id'] ?? 0);

    // The count reaches across the estate, so entry is still gated on an asset
    // this analyst can open — it is reached from that asset's screen.
    if ($assetId <= 0 || !analystCanAccessAsset($conn, $analystId, $assetId)) {
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit;
    }

    // An absent parameter means the drive genuinely has no model / no size, and
    // must not be confused with the empty string a form would send.
    $model = array_key_exists('model', $_GET) && $_GET['model'] !== '' ? (string)$_GET['model'] : null;
    $size  = array_key_exists('size_bytes', $_GET) && $_GET['size_bytes'] !== '' ? (string)$_GET['size_bytes'] : null;

    $counts = assetDiskMatchCount($conn, $analystId, $model, $size);

    echo json_encode([
        'success' => true,
        'drives'  => $counts['drives'],
        'assets'  => $counts['assets'],
        // What the dialog actually offers to hide alongside the one in front of
        // you — the total minus this drive itself.
        'others'  => max(0, $counts['drives'] - 1),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
