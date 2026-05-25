<?php
/**
 * API: Get organisation-wide branding settings.
 *
 * Returns the company logo URL (if uploaded) and the default header/footer
 * template slots (left/centre/right) that get applied to new diagrams and
 * acted as the fallback for any per-diagram slot that's NULL.
 *
 * Used by:
 *   - system/branding/index.php to populate the settings form
 *   - Network Mapper diagram editor to render header/footer overlays
 *   - (future) PDF/PNG exporters in any module that wants branded output
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
    $conn = connectToDatabase();

    $keys = [
        'branding_logo_path',
        'branding_header_left',
        'branding_header_center',
        'branding_header_right',
        'branding_footer_left',
        'branding_footer_center',
        'branding_footer_right',
    ];
    $place = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($place)");
    $stmt->execute($keys);
    $values = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $values[$row['setting_key']] = $row['setting_value'];
    }

    // Defaults applied when no row is stored yet. Tokens in the slot text:
    //   {{title}}, {{author}}, {{version}}, {{modified}}, {{logo}}
    // resolved client-side when rendering. Defaults below give a sensible
    // "company logo top-left, title centred, author + version + modified
    // along the bottom" layout out of the box.
    $defaults = [
        'branding_header_left'   => '{{logo}}',
        'branding_header_center' => '{{title}}',
        'branding_header_right'  => '',
        'branding_footer_left'   => 'Author: {{author}}',
        'branding_footer_center' => '{{version}}',
        'branding_footer_right'  => 'Modified: {{modified}}',
    ];
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $values) || $values[$k] === null) {
            $values[$k] = $v;
        }
    }

    // Logo URL: stored as a relative path from app root (e.g.
    // 'system/uploads/branding/logo.png'). Returned as-is; callers prefix
    // with their own path_prefix.
    $logoPath = $values['branding_logo_path'] ?? null;
    if ($logoPath !== null && $logoPath !== '') {
        // Sanity-check the file still exists on disk — a stale DB row pointing
        // at a deleted upload should surface as "no logo" rather than a 404
        // on every diagram open. Resolve relative to the app root.
        $abs = __DIR__ . '/../../' . $logoPath;
        if (!file_exists($abs)) $logoPath = null;
    }

    echo json_encode([
        'success' => true,
        'branding' => [
            'logo_path'      => $logoPath,
            'header_left'    => $values['branding_header_left'],
            'header_center'  => $values['branding_header_center'],
            'header_right'   => $values['branding_header_right'],
            'footer_left'    => $values['branding_footer_left'],
            'footer_center'  => $values['branding_footer_center'],
            'footer_right'   => $values['branding_footer_right'],
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
