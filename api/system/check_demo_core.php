<?php
/**
 * API Endpoint: Check if demo data modules have been imported.
 * Returns which modules already have data so the UI can reflect their state.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    $conn = connectToDatabase();

    // Core: check for demo analyst
    $stmt = $conn->prepare("SELECT COUNT(*) FROM analysts WHERE username = 'jsmith'");
    $stmt->execute();
    $coreExists = (int)$stmt->fetchColumn() > 0;

    // Software: check for demo apps
    $softwareExists = false;
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM software_inventory_apps");
        $softwareExists = (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {}

    // Assets: check for demo assets
    $assetsExists = false;
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM assets");
        $assetsExists = (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {}

    // Software-assets: check for installation records
    $softwareAssetsExists = false;
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM software_inventory_detail");
        $softwareAssetsExists = (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {}

    echo json_encode([
        'exists' => $coreExists,
        'modules' => [
            'software' => $softwareExists,
            'assets' => $assetsExists,
            'software-assets' => $softwareAssetsExists
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['exists' => false]);
}
?>
