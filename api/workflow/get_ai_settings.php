<?php
/**
 * API: Get the workflow AI settings (provider / model / api_key masked /
 * SSL-verify flag). Drives the AI tab on the workflow Settings page.
 *
 * The API key is NEVER returned in plaintext — only as a "****<last4>" mask
 * so the form can show that something is stored without exposing the secret.
 * Save endpoint treats the masked value as "no change".
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $stmt = $conn->prepare(
        "SELECT setting_key, setting_value FROM system_settings
          WHERE setting_key IN ('workflow_ai_provider', 'workflow_ai_model',
                                'workflow_ai_api_key', 'workflow_ai_verify_ssl')"
    );
    $stmt->execute();

    $out = [
        'workflow_ai_provider'   => 'anthropic',
        'workflow_ai_model'      => '',
        'workflow_ai_api_key'    => '',
        'workflow_ai_verify_ssl' => '1',
    ];
    $hasKey = false;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $k = $row['setting_key'];
        $v = $row['setting_value'];
        if ($k === 'workflow_ai_api_key') {
            $plain = decryptValue($v);
            if ($plain !== null && $plain !== '') {
                $hasKey = true;
                $out['workflow_ai_api_key'] = maskSecret($plain);
            }
        } else {
            $out[$k] = $v;
        }
    }

    echo json_encode([
        'success'  => true,
        'settings' => $out,
        'has_key'  => $hasKey,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
