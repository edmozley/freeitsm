<?php
/**
 * API Endpoint: save the public base URL used to build messaging webhook URLs.
 *
 * Self-hosters set this once (their public domain, or the ngrok address while
 * testing) so the Messaging settings page shows a copy-paste-ready webhook URL
 * instead of "localhost". Stored as system_settings 'messaging_public_base_url'.
 * Only the origin (scheme://host[:port]) is kept — any path is stripped.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Messaging settings tab.
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_MESSAGING);

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $raw = trim((string) ($data['base_url'] ?? ''));

    $origin = '';
    if ($raw !== '') {
        // Accept a bare host (default to https) or a full URL; keep only the origin.
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        $parts = parse_url($raw);
        if (empty($parts['host'])) {
            throw new Exception('That does not look like a valid URL or host.');
        }
        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    $conn = connectToDatabase();
    $stmt = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value)
         VALUES ('messaging_public_base_url', :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([':v' => $origin]);

    echo json_encode(['success' => true, 'public_base_url' => $origin]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
