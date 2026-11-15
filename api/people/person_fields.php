<?php
/**
 * API: the custom fields a person in company N has, without values (GH #279).
 * GET ?company_id=N   (0 or absent = the Default company)
 *
 * For the person editor when it is adding someone, or when their company is
 * changed: the shared person fields plus that company's own (System -> Custom
 * fields), so the form shows exactly the fields the save will accept. The
 * same modules as the editor itself; a company the analyst cannot reach is refused.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/record_fields.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireAnyModuleAccessJson(['tickets', 'assets']);

try {
    $conn = connectToDatabase();
    $raw = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
    $tenantId = $raw > 0 ? $raw : null;
    if ($tenantId !== null && isMultiTenant($conn) && !analystCanAccessTenant($conn, (int)$_SESSION['analyst_id'], $tenantId)) {
        echo json_encode(['success' => false, 'error' => 'Company not found']);
        exit;
    }
    echo json_encode(['success' => true, 'fields' => RecordFieldsService::forRecord($conn, 'person', 0, $tenantId)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
