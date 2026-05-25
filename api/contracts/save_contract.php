<?php
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $id = $data['id'] ?? null;
    $contract_number = trim($data['contract_number'] ?? '');
    $title = trim($data['title'] ?? '');
    $description = trim($data['description'] ?? '') ?: null;
    $supplier_id = $data['supplier_id'] ?? null;
    $contract_owner_id = $data['contract_owner_id'] ?? null;
    $contract_status_id = $data['contract_status_id'] ?? null;
    $contract_start = $data['contract_start'] ?? null;
    $contract_end = $data['contract_end'] ?? null;
    $notice_period_days = $data['notice_period_days'] ?? null;
    $notice_date = $data['notice_date'] ?? null;
    $contract_value = $data['contract_value'] ?? null;
    $currency = $data['currency'] ?? null;
    $payment_schedule_id = $data['payment_schedule_id'] ?? null;
    $cost_centre = trim($data['cost_centre'] ?? '') ?: null;
    $dms_link = trim($data['dms_link'] ?? '') ?: null;
    $terms_status = $data['terms_status'] ?? null;
    $personal_data_transferred = isset($data['personal_data_transferred']) ? ($data['personal_data_transferred'] ? 1 : 0) : null;
    $dpia_required = isset($data['dpia_required']) ? ($data['dpia_required'] ? 1 : 0) : null;
    $dpia_completed_date = $data['dpia_completed_date'] ?? null;
    $dpia_dms_link = trim($data['dpia_dms_link'] ?? '') ?: null;
    $is_active = $data['is_active'] ?? 1;

    if (empty($contract_number)) {
        throw new Exception('Contract number is required');
    }
    if (empty($title)) {
        throw new Exception('Title is required');
    }

    $conn = connectToDatabase();

    $fields = [$contract_number, $title, $description, $supplier_id ?: null, $contract_owner_id ?: null,
               $contract_status_id ?: null, $contract_start ?: null, $contract_end ?: null,
               $notice_period_days ?: null, $notice_date ?: null,
               $contract_value ?: null, $currency ?: null, $payment_schedule_id ?: null,
               $cost_centre, $dms_link, $terms_status ?: null,
               $personal_data_transferred, $dpia_required, $dpia_completed_date ?: null, $dpia_dms_link,
               $is_active];

    if ($id) {
        $sql = "UPDATE contracts SET contract_number=?, title=?, description=?, supplier_id=?, contract_owner_id=?,
                    contract_status_id=?, contract_start=?, contract_end=?,
                    notice_period_days=?, notice_date=?,
                    contract_value=?, currency=?, payment_schedule_id=?,
                    cost_centre=?, dms_link=?, terms_status=?,
                    personal_data_transferred=?, dpia_required=?, dpia_completed_date=?, dpia_dms_link=?,
                    is_active=?
                WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge($fields, [$id]));
    } else {
        $sql = "INSERT INTO contracts (contract_number, title, description, supplier_id, contract_owner_id,
                    contract_status_id, contract_start, contract_end,
                    notice_period_days, notice_date,
                    contract_value, currency, payment_schedule_id,
                    cost_centre, dms_link, terms_status,
                    personal_data_transferred, dpia_required, dpia_completed_date, dpia_dms_link,
                    is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute($fields);
        $id = $conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
