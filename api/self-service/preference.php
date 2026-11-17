<?php
/**
 * API: one per-person setting for the signed-in SELF-SERVICE PORTAL user.
 *
 *   GET  ?key=kb_layout        read it
 *   POST { key, value }        store it
 *
 * The portal twin of api/system/get_user_preference.php + set_user_preference.php,
 * which are keyed by analyst_id and so unusable here.
 *
 * 🔴 KEYS AND VALUES ARE ALLOW-LISTED, not free text. This endpoint is reachable
 * by any signed-in customer, and an open key/value store behind a portal login is
 * somewhere to park arbitrary strings on the server. Only settings the portal
 * actually offers are accepted, and only with values it actually renders.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/portal_preferences.php';

header('Content-Type: application/json');

if (empty($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$userId = (int)$_SESSION['ss_user_id'];

/**
 * What a portal user is allowed to store, and what counts as a value.
 *
 * Adding a setting here is the whole job of offering a new one — the allow-list
 * and the renderer then cannot disagree about what is valid.
 */
require_once '../../includes/i18n.php';
$ALLOWED = [
    PORTAL_KB_LAYOUT_KEY => ['values' => PORTAL_KB_LAYOUTS, 'default' => PORTAL_KB_LAYOUT_DEFAULT],
    // GH #368: '' = the browser's language; otherwise a supported locale code.
    PORTAL_LANGUAGE_KEY  => ['values' => array_merge([''], array_map('strval', array_keys(I18n::getSupportedLocales()))), 'default' => ''],
];

try {
    $conn = connectToDatabase();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $key = (string)($_GET['key'] ?? '');
        if (!isset($ALLOWED[$key])) {
            echo json_encode(['success' => false, 'error' => 'Unknown setting']);
            exit;
        }
        $value = portalPrefGet($conn, $userId, $key, $ALLOWED[$key]['default']);
        if (!in_array($value, $ALLOWED[$key]['values'], true)) {
            $value = $ALLOWED[$key]['default'];   // stored by an older version, or by hand
        }
        echo json_encode(['success' => true, 'key' => $key, 'value' => $value]);
        exit;
    }

    $in    = json_decode(file_get_contents('php://input'), true) ?: [];
    $key   = (string)($in['key'] ?? '');
    $value = (string)($in['value'] ?? '');

    if (!isset($ALLOWED[$key])) {
        echo json_encode(['success' => false, 'error' => 'Unknown setting']);
        exit;
    }
    if (!in_array($value, $ALLOWED[$key]['values'], true)) {
        echo json_encode(['success' => false, 'error' => 'Unknown value for that setting']);
        exit;
    }

    // ⚠️ false here means the table is not there yet (the code ships before an
    // operator runs Database Verification). The caller treats that as "the
    // layout changed but will not be remembered", which is the truth and is not
    // worth an error banner over a view toggle.
    $stored = portalPrefSet($conn, $userId, $key, $value);
    echo json_encode(['success' => true, 'stored' => $stored, 'value' => $value]);

} catch (Throwable $e) {
    error_log('self-service/preference.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not save that setting']);
}
