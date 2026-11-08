<?php
/**
 * API: Which companies a company supports, and how (GH #228).
 * GET ?tenant_id=N - every OTHER company, with this company's two ticks for it:
 *   [{ id, name, is_active, quiet_warning, portal_raise }]
 *
 * See includes/tenant_support.php for what the ticks mean.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/tenant_support.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('system');

$tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
if ($tenantId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing company id']);
    exit;
}

try {
    $conn = connectToDatabase();
    if (!getTenantById($conn, $tenantId)) throw new Exception('That company does not exist.');

    $links = tenantSupportLinksFor($conn, $tenantId);
    $st = $conn->prepare("SELECT id, name, is_active FROM tenants WHERE id <> ? ORDER BY name");
    $st->execute([$tenantId]);
    $companies = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int)$r['id'];
        $companies[] = [
            'id'            => $id,
            'name'          => $r['name'],
            'is_active'     => (bool)$r['is_active'],
            'quiet_warning' => $links[$id]['quiet_warning'] ?? false,
            'portal_raise'  => $links[$id]['portal_raise'] ?? false,
        ];
    }

    echo json_encode(['success' => true, 'ready' => tenantSupportReady($conn), 'companies' => $companies]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
