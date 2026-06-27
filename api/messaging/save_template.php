<?php
/**
 * API Endpoint: create/update a messaging template definition.
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
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        throw new Exception('Invalid request data');
    }

    $id          = $data['id'] ?? null;
    $name        = trim((string) ($data['name'] ?? ''));
    $provider    = ($data['provider'] ?? 'twilio') === 'meta' ? 'meta' : 'twilio';
    $language    = trim((string) ($data['language'] ?? 'en')) ?: 'en';
    $providerRef = trim((string) ($data['provider_ref'] ?? ''));
    $body        = trim((string) ($data['body'] ?? ''));
    $isActive    = !empty($data['is_active']) ? 1 : 0;

    if ($name === '')        throw new Exception('Name is required');
    if ($providerRef === '') throw new Exception($provider === 'meta' ? 'Meta template name is required' : 'Twilio Content SID is required');
    if ($body === '')        throw new Exception('Template body is required');

    $conn = connectToDatabase();

    if ($id) {
        $sql = "UPDATE messaging_templates SET name=?, provider=?, language=?, provider_ref=?, body=?, is_active=? WHERE id=?";
        $conn->prepare($sql)->execute([$name, $provider, $language, $providerRef, $body, $isActive, (int) $id]);
        echo json_encode(['success' => true, 'id' => (int) $id, 'message' => 'Template saved']);
    } else {
        $sql = "INSERT INTO messaging_templates (name, provider, language, provider_ref, body, is_active) VALUES (?, ?, ?, ?, ?, ?)";
        $conn->prepare($sql)->execute([$name, $provider, $language, $providerRef, $body, $isActive]);
        echo json_encode(['success' => true, 'id' => (int) $conn->lastInsertId(), 'message' => 'Template created']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
