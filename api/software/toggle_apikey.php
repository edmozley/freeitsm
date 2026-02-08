<?php
/**
 * API Endpoint: Toggle API Key active status (revoke/activate)
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

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Key ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Toggle: if active=1 set to 0, if active=0 set to 1
    $sql = "UPDATE apikeys SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        // Fetch new status
        $stmt2 = $conn->prepare("SELECT active FROM apikeys WHERE id = ?");
        $stmt2->execute([$id]);
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => $row['active'] ? 'API key activated' : 'API key revoked',
            'active' => (bool)$row['active']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Key not found']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
