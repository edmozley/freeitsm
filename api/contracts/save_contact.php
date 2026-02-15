<?php
session_start();
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
    $supplier_id = $data['supplier_id'] ?? null;
    $first_name = trim($data['first_name'] ?? '');
    $surname = trim($data['surname'] ?? '');
    $email = trim($data['email'] ?? '');
    $mobile = trim($data['mobile'] ?? '');
    $job_title = trim($data['job_title'] ?? '') ?: null;
    $direct_dial = trim($data['direct_dial'] ?? '') ?: null;
    $switchboard = trim($data['switchboard'] ?? '') ?: null;
    $is_active = $data['is_active'] ?? 1;

    if (empty($first_name) || empty($surname)) {
        throw new Exception('First name and surname are required');
    }

    $conn = connectToDatabase();

    if ($id) {
        $sql = "UPDATE contacts SET supplier_id = ?, first_name = ?, surname = ?, email = ?, mobile = ?, job_title = ?, direct_dial = ?, switchboard = ?, is_active = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$supplier_id ?: null, $first_name, $surname, $email ?: null, $mobile ?: null, $job_title, $direct_dial, $switchboard, $is_active, $id]);
    } else {
        $sql = "INSERT INTO contacts (supplier_id, first_name, surname, email, mobile, job_title, direct_dial, switchboard, is_active) OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$supplier_id ?: null, $first_name, $surname, $email ?: null, $mobile ?: null, $job_title, $direct_dial, $switchboard, $is_active]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = $row['id'];
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
