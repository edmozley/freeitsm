<?php
/**
 * API Endpoint: Quick-add a supplier from the Asset Settings Suppliers tab.
 *
 * Creates a minimal row in the shared suppliers registry (legal_name only),
 * already flagged supplies_assets so it appears in the asset dropdown. Fuller
 * details (VAT, address, type/status, contacts) can be filled in later in the
 * Contracts module. If a supplier with the same legal name already exists, it
 * is simply flagged rather than duplicated.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('assets');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['name'] ?? '');
    if ($name === '') {
        throw new Exception('Supplier name is required');
    }

    $conn = connectToDatabase();

    // Match an existing supplier (by legal or trading name) before creating one.
    $sel = $conn->prepare("SELECT id FROM suppliers WHERE legal_name = ? OR trading_name = ? LIMIT 1");
    $sel->execute([$name, $name]);
    $id = $sel->fetchColumn();

    if ($id) {
        $conn->prepare("UPDATE suppliers SET supplies_assets = 1, is_active = 1 WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'id' => (int)$id, 'existing' => true]);
    } else {
        $ins = $conn->prepare("INSERT INTO suppliers (legal_name, supplies_assets, is_active) VALUES (?, 1, 1)");
        $ins->execute([$name]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'existing' => false]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
