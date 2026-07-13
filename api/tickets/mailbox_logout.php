<?php
/**
 * API Endpoint: Logout/clear token for a specific mailbox
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_MAILBOXES);   // settings tab — see docs/design/rbac.md

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
$mailboxId = $data['mailbox_id'] ?? null;

if (!$mailboxId) {
    echo json_encode(['success' => false, 'error' => 'Mailbox ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Clear token data for the mailbox
    $sql = "UPDATE target_mailboxes SET token_data = NULL WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$mailboxId]);

    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Mailbox logged out successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Mailbox not found'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
