<?php
/**
 * API Endpoint: test one Proxmox server, or sync it now.
 *   POST {id, action:'test'|'sync'}
 * A sync runs in the request and returns its summary. The scheduled run is
 * cron/proxmox_sync.php, which does the same for servers that are due.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/encryption.php';
require_once '../../includes/proxmox_sync.php';

header('Content-Type: application/json');
set_time_limit(600);

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireCapabilityJson(Cap::ASSETS_PROXMOX);

try {
    $in = json_decode(file_get_contents('php://input'), true);
    $id = (int)($in['id'] ?? 0);
    $action = (string)($in['action'] ?? 'sync');
    if ($id <= 0) {
        throw new Exception('Server ID is required');
    }
    $conn = connectToDatabase();

    if ($action === 'test') {
        $c = proxmoxLoadConnection($conn, $id);
        echo json_encode(['success' => true, 'message' => proxmoxTestConnection($c)]);
        exit;
    }
    if ($action !== 'sync') {
        throw new Exception('Unknown action');
    }
    // #182: Sync now is a MANUAL run of the job, for this one server - recorded,
    // and it can clear that server's failure, but it never makes the schedule look alive.
    require_once __DIR__ . '/../../includes/job_runs.php';
    if (!JobRun::tryLock($conn, 'proxmox_sync')) {   // #233: never alongside a scheduled sync
        throw new Exception('A scheduled sync is running. Try again in a few minutes.');
    }
    $runId = JobRun::start($conn, 'proxmox_sync', 'manual', (int)($_SESSION['analyst_id'] ?? 0) ?: null);
    try {
        $summary = proxmoxSyncConnection($conn, $id);
    } catch (Throwable $e) {
        JobRun::fail($conn, $runId, $e);
        throw $e;
    }
    $nm = $conn->prepare("SELECT name FROM proxmox_connections WHERE id = ?");
    $nm->execute([$id]);
    JobRun::source($conn, $runId, 'proxmox_sync', 'server:' . $id, (string)($nm->fetchColumn() ?: ('#' . $id)), $summary['status'] !== 'error', $summary['message'] ?? null);
    JobRun::finish($conn, $runId, null, ['servers' => 1]);
    echo json_encode(['success' => $summary['status'] !== 'error', 'summary' => $summary,
        'error' => $summary['status'] === 'error' ? $summary['message'] : null]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
