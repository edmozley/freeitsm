<?php
/**
 * API Endpoint: Trigger a codebase rescan
 * POST request - launches the PowerShell scanner as a background process
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST method required']);
    exit;
}

try {
    // Path to the scanner script
    $scriptPath = realpath(__DIR__ . '/../../system-wiki/scanner/Scan-Codebase.ps1');

    if (!$scriptPath || !file_exists($scriptPath)) {
        echo json_encode(['success' => false, 'error' => 'Scanner script not found']);
        exit;
    }

    // Launch PowerShell as a background process
    $command = 'powershell.exe -ExecutionPolicy Bypass -File "' . $scriptPath . '"';
    pclose(popen("start /B " . $command, "r"));

    echo json_encode(['success' => true, 'message' => 'Scan triggered. Check the Scan page for progress.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
