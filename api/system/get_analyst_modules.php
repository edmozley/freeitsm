<?php
/**
 * API: Get analyst module assignments
 * GET - Returns all active analysts and their module permissions
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get all active analysts
    $sql = "SELECT id, full_name, username FROM analysts WHERE is_active = 1 ORDER BY full_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $analysts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all module assignments
    $sql2 = "SELECT analyst_id, module_key FROM analyst_modules ORDER BY analyst_id";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->execute();
    $assignments = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // Group assignments by analyst_id
    $moduleMap = [];
    foreach ($assignments as $row) {
        $aid = $row['analyst_id'];
        if (!isset($moduleMap[$aid])) {
            $moduleMap[$aid] = [];
        }
        $moduleMap[$aid][] = $row['module_key'];
    }

    // Available modules list
    $availableModules = [
        'tickets', 'assets', 'knowledge', 'changes', 'calendar',
        'morning-checks', 'reporting', 'software', 'forms', 'contracts', 'wiki', 'system'
    ];

    echo json_encode([
        'success' => true,
        'analysts' => $analysts,
        'module_assignments' => (object)$moduleMap,
        'available_modules' => $availableModules
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
