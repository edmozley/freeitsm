<?php
/**
 * API Endpoint: Delete department
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_DEPARTMENTS);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;

    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();

    // GH #218: never retire a department that is a default for new tickets - new
    // tickets would silently start with none. Replace or clear the default first.
    require_once '../../includes/ticket_department_rules.php';
    $usedBy = ticketDepartmentDefaultUses($conn, (int) $id);
    if ($usedBy) {
        throw new Exception('This department is the default for new tickets for ' . ticketDepartmentPlaceList($usedBy)
            . '. Choose another default, or No default, on Tickets -> Settings -> Custom fields -> Department field, then delete it.');
    }

    $sql = "DELETE FROM departments WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
