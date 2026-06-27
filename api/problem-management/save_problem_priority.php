<?php
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
try {
    $d = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($d['id'] ?? 0);
    $name = trim((string) ($d['name'] ?? ''));
    if ($name === '') throw new Exception('Name is required');
    $colour = trim((string) ($d['colour'] ?? '')) ?: null;
    $isDefault = !empty($d['is_default']) ? 1 : 0;
    $order = (int) ($d['display_order'] ?? 0);
    $isActive = isset($d['is_active']) ? (!empty($d['is_active']) ? 1 : 0) : 1;
    $conn = connectToDatabase();
    if ($isDefault) $conn->exec("UPDATE problem_priorities SET is_default = 0");
    if ($id > 0) {
        $conn->prepare("UPDATE problem_priorities SET name=?, colour=?, is_default=?, display_order=?, is_active=? WHERE id=?")
             ->execute([$name, $colour, $isDefault, $order, $isActive, $id]);
        echo json_encode(['success' => true, 'id' => $id]);
    } else {
        $conn->prepare("INSERT INTO problem_priorities (name, colour, is_default, display_order, is_active) VALUES (?, ?, ?, ?, ?)")
             ->execute([$name, $colour, $isDefault, $order, $isActive]);
        echo json_encode(['success' => true, 'id' => (int) $conn->lastInsertId()]);
    }
} catch (Exception $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
