<?php
/**
 * API: Check encryption key status
 * GET - Returns whether the encryption key file exists and is valid
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once '../../includes/encryption.php';

$keyPath = ENCRYPTION_KEY_PATH;
$keyExists = file_exists($keyPath);
$keyValid = false;

if ($keyExists) {
    $hex = trim(file_get_contents($keyPath));
    $keyValid = (strlen($hex) === 64 && ctype_xdigit($hex));
}

echo json_encode([
    'success' => true,
    'key_exists' => $keyExists,
    'key_valid' => $keyValid,
    'key_path' => $keyPath
]);
