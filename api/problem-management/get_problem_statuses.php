<?php
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
try {
    $conn = connectToDatabase();
    $sql = "SELECT id, name, is_closed, colour, is_default, display_order, is_active FROM problem_statuses"
         . (!empty($_GET['manage']) ? '' : ' WHERE is_active = 1') . ' ORDER BY display_order, name';
    echo json_encode(['success' => true, 'statuses' => $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
