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
    $search = $_GET['search'] ?? '';
    $limit = $_GET['limit'] ?? null;

    $sql = "SELECT c.id, c.contract_number, c.title, c.supplier_id, c.contract_owner_id,
                   c.contract_start, c.contract_end, c.notice_period_days, c.is_active, c.created_datetime,
                   s.legal_name AS supplier_name, s.trading_name AS supplier_trading_name,
                   a.full_name AS owner_name
            FROM contracts c
            LEFT JOIN suppliers s ON c.supplier_id = s.id
            LEFT JOIN analysts a ON c.contract_owner_id = a.id";

    $params = [];
    if (!empty($search)) {
        $sql .= " WHERE (c.contract_number LIKE ? OR c.title LIKE ? OR s.legal_name LIKE ?)";
        $params = ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%'];
    }

    if ($limit) {
        $sql = str_replace("SELECT c.id", "SELECT TOP " . intval($limit) . " c.id", $sql);
    }

    $sql .= " ORDER BY c.created_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($contracts as &$c) {
        $c['is_active'] = (bool)$c['is_active'];
    }

    echo json_encode(['success' => true, 'contracts' => $contracts]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
