<?php
/**
 * API Endpoint: delete a MANUALLY added application (#1549).
 *
 * Three guards, in this order:
 *
 *   1. Manual only. An agent-discovered row is a report of what is installed
 *      somewhere; deleting it would just make it reappear on the next inventory
 *      run, so the button would look broken rather than refused.
 *   2. No licences. `software_licences.app_id` is NOT NULL with a foreign key,
 *      so the DB would refuse anyway — this says why, and names the number.
 *   3. No installs. A manual row the agent has since ADOPTED (see the submit
 *      endpoint) now has real machines behind it, and deleting it would throw
 *      away that history. Those installs are the answer to "is anyone using
 *      this?", which is usually the question being asked when someone reaches
 *      for delete in the first place.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('software');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = !empty($data['id']) ? (int) $data['id'] : null;
    if (!$id) throw new Exception('ID is required');

    $conn = connectToDatabase();

    $cur = $conn->prepare("SELECT display_name, source FROM software_inventory_apps WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Application not found');

    if (($row['source'] ?? 'agent') !== 'manual') {
        throw new Exception('That application was found by the inventory agent. It would come straight back on the next inventory run — remove it from the machines instead.');
    }

    $l = $conn->prepare("SELECT COUNT(*) FROM software_licences WHERE app_id = ?");
    $l->execute([$id]);
    $licences = (int) $l->fetchColumn();
    if ($licences > 0) {
        throw new Exception($licences === 1
            ? 'This application has a licence recorded against it. Delete that first.'
            : "This application has {$licences} licences recorded against it. Delete those first.");
    }

    $d = $conn->prepare("SELECT COUNT(DISTINCT host_id) FROM software_inventory_detail WHERE app_id = ?");
    $d->execute([$id]);
    $installs = (int) $d->fetchColumn();
    if ($installs > 0) {
        throw new Exception($installs === 1
            ? 'The inventory agent has since found this installed on a machine, so it is no longer just a manual entry.'
            : "The inventory agent has since found this installed on {$installs} machines, so it is no longer just a manual entry.");
    }

    $conn->prepare("DELETE FROM software_inventory_apps WHERE id = ?")->execute([$id]);

    wf_emit('software_app', 'deleted', $id, $row['display_name']);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
