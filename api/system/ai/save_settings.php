<?php
/**
 * API: Save the AI config for a namespace.
 * POST JSON { ns, provider, model, api_key? }
 * A masked/empty api_key leaves the stored key untouched.
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

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$ns = isset($data['ns']) ? trim((string)$data['ns']) : '';
if (!aiSettingsIsValidNs($ns)) {
    echo json_encode(['success' => false, 'error' => 'Unknown AI settings namespace']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Seven modules share this endpoint, so one guard cannot be right for all of them.
    // Authorise the NAMESPACE: a converted module defers to its AI tab's capability; an
    // unconverted one still requires is_admin, exactly as this file did before.
    requireAiNamespaceJson($conn, $ns);

    aiSettingsSave($conn, $ns, [
        'provider'   => $data['provider']   ?? 'anthropic',
        'model'      => $data['model']       ?? '',
        'api_key'    => $data['api_key']     ?? '',
    ]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
