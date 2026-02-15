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
    $conn = connectToDatabase();
    $supplier_id = $_GET['supplier_id'] ?? null;

    $sql = "SELECT c.id, c.supplier_id, c.first_name, c.surname, c.email, c.mobile,
                   c.job_title, c.direct_dial, c.switchboard,
                   c.is_active, c.created_datetime,
                   s.legal_name AS supplier_name
            FROM contacts c
            LEFT JOIN suppliers s ON c.supplier_id = s.id";

    $params = [];
    if ($supplier_id) {
        $sql .= " WHERE c.supplier_id = ?";
        $params[] = $supplier_id;
    }

    $sql .= " ORDER BY c.surname, c.first_name";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($contacts as &$c) {
        $c['is_active'] = (bool)$c['is_active'];
    }

    echo json_encode(['success' => true, 'contacts' => $contacts]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
