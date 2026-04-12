<?php
/**
 * API: Tasks — Search tickets/changes for linking
 * GET ?type=ticket|change&q=searchterm
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$type = $_GET['type'] ?? '';
$q = trim($_GET['q'] ?? '');

if (!$type || !$q) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

try {
    $conn = connectToDatabase();
    $searchTerm = '%' . $q . '%';

    if ($type === 'ticket') {
        $stmt = $conn->prepare(
            "SELECT id, ticket_number, subject
             FROM tickets
             WHERE (ticket_number LIKE ? OR subject LIKE ?)
             ORDER BY created_datetime DESC
             LIMIT 10"
        );
        $stmt->execute([$searchTerm, $searchTerm]);
    } elseif ($type === 'change') {
        $stmt = $conn->prepare(
            "SELECT id, title
             FROM changes
             WHERE title LIKE ?
             ORDER BY created_datetime DESC
             LIMIT 10"
        );
        $stmt->execute([$searchTerm]);
    } else {
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }

    echo json_encode(['success' => true, 'results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
