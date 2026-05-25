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
    $legal_name = trim($data['legal_name'] ?? '');
    $trading_name = trim($data['trading_name'] ?? '') ?: null;
    $reg_number = trim($data['reg_number'] ?? '') ?: null;
    $vat_number = trim($data['vat_number'] ?? '') ?: null;
    $supplier_type_id = $data['supplier_type_id'] ?: null;
    $supplier_status_id = $data['supplier_status_id'] ?: null;
    $address_line_1 = trim($data['address_line_1'] ?? '') ?: null;
    $address_line_2 = trim($data['address_line_2'] ?? '') ?: null;
    $city = trim($data['city'] ?? '') ?: null;
    $county = trim($data['county'] ?? '') ?: null;
    $postcode = trim($data['postcode'] ?? '') ?: null;
    $country = trim($data['country'] ?? '') ?: null;
    $questionnaire_date_issued = $data['questionnaire_date_issued'] ?: null;
    $questionnaire_date_received = $data['questionnaire_date_received'] ?: null;
    $comments = trim($data['comments'] ?? '') ?: null;
    $is_active = $data['is_active'] ?? 1;

    if (empty($legal_name)) {
        throw new Exception('Legal name is required');
    }

    $conn = connectToDatabase();

    $fields = [$legal_name, $trading_name, $reg_number, $vat_number,
               $supplier_type_id, $supplier_status_id,
               $address_line_1, $address_line_2, $city, $county, $postcode, $country,
               $questionnaire_date_issued, $questionnaire_date_received, $comments, $is_active];

    if ($id) {
        $sql = "UPDATE suppliers SET legal_name=?, trading_name=?, reg_number=?, vat_number=?,
                    supplier_type_id=?, supplier_status_id=?,
                    address_line_1=?, address_line_2=?, city=?, county=?, postcode=?, country=?,
                    questionnaire_date_issued=?, questionnaire_date_received=?, comments=?, is_active=?
                WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge($fields, [$id]));
    } else {
        $sql = "INSERT INTO suppliers (legal_name, trading_name, reg_number, vat_number,
                    supplier_type_id, supplier_status_id,
                    address_line_1, address_line_2, city, county, postcode, country,
                    questionnaire_date_issued, questionnaire_date_received, comments, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute($fields);
        $id = $conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
