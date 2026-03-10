<?php
/**
 * API: Generate encryption key
 * POST - Generates a new AES-256 encryption key and writes it to the key file
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$overwrite = isset($input['overwrite']) && $input['overwrite'] === true;

require_once '../../includes/encryption.php';

$keyPath = ENCRYPTION_KEY_PATH;
$keyDir = dirname($keyPath);

// Check if key already exists
if (file_exists($keyPath) && !$overwrite) {
    echo json_encode(['success' => false, 'error' => 'Encryption key already exists. To regenerate, confirm overwrite.']);
    exit;
}

try {
    // Create directory if it doesn't exist
    if (!is_dir($keyDir)) {
        if (!mkdir($keyDir, 0700, true)) {
            throw new Exception('Failed to create encryption key directory: ' . $keyDir);
        }
    }

    // Generate 256-bit key (64 hex characters)
    $key = bin2hex(random_bytes(32));

    // Write key to file
    if (file_put_contents($keyPath, $key) === false) {
        throw new Exception('Failed to write encryption key file. Check directory permissions.');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Encryption key generated successfully'
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
