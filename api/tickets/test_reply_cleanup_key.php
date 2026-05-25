<?php
/**
 * API: Test Reply Cleanup API key
 * Sends a tiny request to Anthropic to validate the configured key + model.
 * Returns success/failure with a friendly message.
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
          WHERE setting_key IN ('tickets_reply_cleanup_api_key', 'tickets_reply_cleanup_model')"
    );
    $stmt->execute();

    $apiKey = '';
    $model  = '';
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['setting_key'] === 'tickets_reply_cleanup_api_key') {
            $apiKey = decryptValue($row['setting_value']);
        } elseif ($row['setting_key'] === 'tickets_reply_cleanup_model') {
            $model = $row['setting_value'];
        }
    }

    if ($apiKey === '') {
        throw new Exception('No API key configured. Save your key first, then test.');
    }
    if ($model === '') {
        $model = 'claude-haiku-4-5-20251001';
    }

    $body = json_encode([
        'model'      => $model,
        'max_tokens' => 1,
        'messages'   => [['role' => 'user', 'content' => 'ok']],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => SSL_VERIFY_PEER,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new Exception('Network error: ' . $err);
    }

    $data = json_decode($resp, true);
    if ($http === 200) {
        echo json_encode([
            'success' => true,
            'message' => 'Connection OK — model "' . $model . '" responded.',
        ]);
        exit;
    }

    $apiMessage = $data['error']['message'] ?? ('HTTP ' . $http);
    echo json_encode(['success' => false, 'error' => $apiMessage]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
