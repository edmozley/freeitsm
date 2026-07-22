<?php
/**
 * API: Get the UI-safe AI config for a namespace.
 * GET ?ns=<namespace>
 * Returns: { success, provider, model, has_key, masked_key }
 * Never returns the plaintext API key.
 */
session_start(['read_and_close' => true]);
require_once '../../../config.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/rbac.php';
require_once '../../../includes/settings_keys.php';
require_once '../../../includes/ai_settings.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$ns = isset($_GET['ns']) ? trim((string)$_GET['ns']) : '';
if (!aiSettingsIsValidNs($ns)) {
    echo json_encode(['success' => false, 'error' => 'Unknown AI settings namespace']);
    exit;
}

try {
    $conn = connectToDatabase();

    // This loads the AI settings panel. It had NO permission check at all — any logged-in
    // analyst could read which provider and model every module uses. (The API key itself is
    // masked before it leaves the server, so this was disclosure of configuration, not of
    // the secret.) Now it needs the same permission as saving.
    requireAiNamespaceJson($conn, $ns);

    echo json_encode(['success' => true] + aiSettingsForUi($conn, $ns));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
