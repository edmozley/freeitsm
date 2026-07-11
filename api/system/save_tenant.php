<?php
/**
 * API: Create or update a company (tenant).
 * POST JSON { id?, name, is_active }
 *
 * "Company" is the user-facing word for a tenant; the underlying table/code
 * stays `tenants`. is_default is out of scope here and never edited.
 *
 * GUARD: the default company (is_default = 1) can never be set inactive.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

// --- Validate fields ---
$name = trim($data['name'] ?? '');
if ($name === '') {
    echo json_encode(['success' => false, 'error' => 'Name is required']);
    exit;
}
$isActive = !empty($data['is_active']) ? 1 : 0;
$id       = isset($data['id']) ? (int)$data['id'] : 0;

try {
    $conn = connectToDatabase();

    if ($id > 0) {
        // --- Update existing company ---
        $existing = getTenantById($conn, $id);
        if (!$existing) {
            echo json_encode(['success' => false, 'error' => 'Company not found']);
            exit;
        }
        // Never let the default company be deactivated.
        if ($existing['is_default'] && !$isActive) {
            echo json_encode(['success' => false, 'error' => 'The default company cannot be set inactive']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE tenants SET name = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $isActive, $id]);
        echo json_encode(['success' => true, 'id' => $id]);

    } else {
        // --- Create new company (always non-default) ---
        $stmt = $conn->prepare(
            "INSERT INTO tenants (name, is_default, is_active, created_datetime)
             VALUES (?, 0, ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([$name, $isActive]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
