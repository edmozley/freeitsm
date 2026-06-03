<?php
/**
 * API: Test the saved AI config for a namespace with a tiny live call.
 * POST JSON { ns }
 * Returns: { success, provider, model, duration_ms, reply } or { success:false, error }
 */
session_start(['read_and_close' => true]);
require_once '../../../config.php';
require_once '../../../includes/functions.php';
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
    $cfg  = aiSettingsLoad($conn, $ns);
    if ($cfg['api_key'] === '') {
        echo json_encode(['success' => false, 'error' => 'No API key saved yet — save a key first.']);
        exit;
    }

    $result = aiProviderChat($cfg, [
        'system'     => 'You are a connection test. Reply with the single word: OK',
        'user'       => 'Reply with the single word: OK',
        'max_tokens' => 16,
    ]);

    echo json_encode([
        'success'     => true,
        'provider'    => $result['provider'],
        'model'       => $result['model'],
        'duration_ms' => $result['duration_ms'],
        'reply'       => mb_substr((string)$result['content'], 0, 80),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
