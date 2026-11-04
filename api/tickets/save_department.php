<?php
/**
 * API Endpoint: Save department (create or update)
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
    $name = $data['name'] ?? '';
    $description = $data['description'] ?? '';
    $display_order = $data['display_order'] ?? 0;
    $is_active = $data['is_active'] ?? 1;

    if (empty($name)) {
        throw new Exception('Name is required');
    }

    $conn = connectToDatabase();
    $raised = 0;

    if ($id && !(int) $is_active) {
        // GH #218: never retire a department that is a default for new tickets - new
        // tickets would silently start with none. Replace or clear the default first.
        require_once '../../includes/ticket_department_rules.php';
        $usedBy = ticketDepartmentDefaultUses($conn, (int) $id);
        if ($usedBy) {
            throw new Exception('This department is the default for new tickets for ' . ticketDepartmentPlaceList($usedBy)
                . '. Choose another default, or No default, on Tickets -> Settings -> Custom fields -> Department field, then deactivate it.');
        }
    }

    if ($id) {
        // Update existing
        $sql = "UPDATE departments SET name = ?, description = ?, display_order = ?, is_active = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $description, $display_order, $is_active, $id]);
    } else {
        // Create new
        $sql = "INSERT INTO departments (name, description, display_order, is_active) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $description, $display_order, $is_active]);
        // Read NOW: lastInsertId() reports on the last query, and the sensitivity
        // block below runs other queries first, after which it says 0.
        $id = (int)$conn->lastInsertId();
    }

    // Confidential department (discussion #62). Only when the form sent it, and
    // only once Database Verification has added the column.
    require_once '../../includes/ticket_sensitivity.php';
    if (array_key_exists('default_sensitivity', $data) && ticketSensitivityReady($conn)) {
        $deptId  = (int)$id;
        $newSens = ticketSensitivityNormalise($data['default_sensitivity']);
        $was     = $conn->prepare("SELECT default_sensitivity FROM departments WHERE id = ?");
        $was->execute([$deptId]);
        $oldSens = ticketSensitivityNormalise($was->fetchColumn());
        $conn->prepare("UPDATE departments SET default_sensitivity = ? WHERE id = ?")->execute([$newSens, $deptId]);

        // Turned ON: the tickets already here are exactly the ones the setting is
        // for, so they are marked too ("every ticket in, or moved into"). Turned
        // OFF: nothing changes - lowering is always a person's decision, per ticket.
        if ($newSens === 'confidential' && $oldSens !== 'confidential') {
            $ids = $conn->prepare("SELECT id FROM tickets WHERE department_id = ? AND sensitivity <> 'confidential'");
            $ids->execute([$deptId]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                if (ticketSensitivityApplyDefaults($conn, (int)$tid)) $raised++;
            }
        }
    }

    echo json_encode(['success' => true, 'raised' => $raised]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
