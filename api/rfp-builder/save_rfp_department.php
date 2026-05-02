<?php
/**
 * API Endpoint: Save RFP department (create or update)
 */
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
    $name = trim($data['name'] ?? '');
    $colour = trim($data['colour'] ?? '#6c757d');
    $sort_order = (int)($data['sort_order'] ?? 0);
    $is_active = !empty($data['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new Exception('Name is required');
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
        $colour = '#6c757d';
    }

    $conn = connectToDatabase();

    if ($id) {
        $sql = "UPDATE rfp_departments
                   SET name = ?, colour = ?, sort_order = ?, is_active = ?
                 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $colour, $sort_order, $is_active, $id]);
    } else {
        $sql = "INSERT INTO rfp_departments (name, colour, sort_order, is_active)
                VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $colour, $sort_order, $is_active]);
        $id = $conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => (int)$id]);
} catch (PDOException $e) {
    // Friendly message for the unique-name constraint
    if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
        echo json_encode(['success' => false, 'error' => 'A department with that name already exists']);
    } else {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
