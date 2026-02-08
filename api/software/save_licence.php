<?php
/**
 * API Endpoint: Save Software Licence
 * Creates or updates a licence record
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

$id = isset($input['id']) ? (int)$input['id'] : null;
$app_id = isset($input['app_id']) ? (int)$input['app_id'] : null;
$licence_type = trim($input['licence_type'] ?? '');
$licence_key = trim($input['licence_key'] ?? '');
$quantity = isset($input['quantity']) && $input['quantity'] !== '' ? (int)$input['quantity'] : null;
$renewal_date = !empty($input['renewal_date']) ? $input['renewal_date'] : null;
$notice_period_days = isset($input['notice_period_days']) && $input['notice_period_days'] !== '' ? (int)$input['notice_period_days'] : null;
$portal_url = trim($input['portal_url'] ?? '');
$cost = isset($input['cost']) && $input['cost'] !== '' ? (float)$input['cost'] : null;
$currency = trim($input['currency'] ?? 'GBP');
$purchase_date = !empty($input['purchase_date']) ? $input['purchase_date'] : null;
$vendor_contact = trim($input['vendor_contact'] ?? '');
$notes = trim($input['notes'] ?? '');
$status = trim($input['status'] ?? 'Active');

if (!$app_id) {
    echo json_encode(['success' => false, 'error' => 'Application is required']);
    exit;
}

if (empty($licence_type)) {
    echo json_encode(['success' => false, 'error' => 'Licence type is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    if ($id) {
        $sql = "UPDATE software_licences
                SET app_id = ?, licence_type = ?, licence_key = ?, quantity = ?,
                    renewal_date = ?, notice_period_days = ?, portal_url = ?,
                    cost = ?, currency = ?, purchase_date = ?, vendor_contact = ?,
                    notes = ?, status = ?, updated_at = GETDATE()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $app_id, $licence_type, $licence_key, $quantity,
            $renewal_date, $notice_period_days, $portal_url,
            $cost, $currency, $purchase_date, $vendor_contact,
            $notes, $status, $id
        ]);

        echo json_encode(['success' => true, 'message' => 'Licence updated', 'id' => $id]);
    } else {
        $sql = "INSERT INTO software_licences
                (app_id, licence_type, licence_key, quantity, renewal_date,
                 notice_period_days, portal_url, cost, currency, purchase_date,
                 vendor_contact, notes, status, created_by)
                OUTPUT INSERTED.id
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $app_id, $licence_type, $licence_key, $quantity, $renewal_date,
            $notice_period_days, $portal_url, $cost, $currency, $purchase_date,
            $vendor_contact, $notes, $status, $_SESSION['analyst_id']
        ]);
        $newId = $stmt->fetchColumn();

        echo json_encode(['success' => true, 'message' => 'Licence created', 'id' => $newId]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
