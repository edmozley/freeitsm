<?php
/**
 * API: Save analyst module permissions
 * POST - Updates module access for a specific analyst
 * Input: { analyst_id: int, modules: string[] }
 * Empty modules array = full access (all modules)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$analyst_id = isset($input['analyst_id']) ? (int)$input['analyst_id'] : null;
$modules = isset($input['modules']) ? $input['modules'] : null;

if (!$analyst_id) {
    echo json_encode(['success' => false, 'error' => 'Analyst ID is required']);
    exit;
}

if (!is_array($modules)) {
    echo json_encode(['success' => false, 'error' => 'Modules must be an array']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Delete existing assignments for this analyst
    $delSql = "DELETE FROM analyst_modules WHERE analyst_id = ?";
    $delStmt = $conn->prepare($delSql);
    $delStmt->execute([$analyst_id]);

    // If modules array is not empty, insert new assignments
    if (!empty($modules)) {
        // Always ensure system is included
        if (!in_array('system', $modules)) {
            $modules[] = 'system';
        }

        $insSql = "INSERT INTO analyst_modules (analyst_id, module_key) VALUES (?, ?)";
        $insStmt = $conn->prepare($insSql);
        foreach ($modules as $moduleKey) {
            $insStmt->execute([$analyst_id, $moduleKey]);
        }
    }

    // If the analyst being modified is the current user, update their session
    if ($analyst_id === (int)$_SESSION['analyst_id']) {
        $_SESSION['allowed_modules'] = empty($modules) ? null : $modules;
    }

    $message = empty($modules) ? 'Full access granted' : 'Module permissions updated';
    echo json_encode(['success' => true, 'message' => $message]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
