<?php
/**
 * API Endpoint: when an analyst leaves (GH #178) - System -> Analysts.
 *
 * GET  ?id=N
 *      What Deactivate, Anonymise and Delete would mean for them: their open work
 *      (by kind), what is set up to route to them, how much history carries their
 *      name, and whether Delete is possible - plus the active analysts their work
 *      could go to.
 *
 * POST { id, action: 'deactivate' | 'anonymise' | 'delete', reassign: bool, reassign_to: id | null }
 *      Optionally hand their open work on first (reassign_to null = unassigned),
 *      then do the action. Each item is moved through its module's own service;
 *      any it could not move come back in `failed` and the action still runs,
 *      except Delete, which refuses while they hold work.
 *
 * The rules all live in includes/analyst_lifecycle.php; this only adapts them.
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../includes/service_context.php';   // ServiceError, thrown below (GH #288)
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/analyst_lifecycle.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $ctx  = ActorContext::fromSession($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        $f  = analystFootprint($conn, $id);
        $st = $conn->prepare("SELECT id, full_name FROM analysts WHERE is_active = 1 AND id <> ? ORDER BY full_name");
        $st->execute([$id]);
        $f['candidates'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['full_name']], $st->fetchAll(PDO::FETCH_ASSOC));
        $f['is_self'] = $id === (int) $_SESSION['analyst_id'];
        echo json_encode(['success' => true] + $f);
        exit;
    }

    $in     = json_decode(file_get_contents('php://input'), true) ?: [];
    $id     = (int) ($in['id'] ?? 0);
    $action = (string) ($in['action'] ?? '');
    if (!in_array($action, ['deactivate', 'anonymise', 'delete'], true)) {
        throw new ServiceError('validation', 'bad_action', 'Choose Deactivate, Anonymise or Delete.');
    }

    $moved = ['moved' => [], 'failed' => []];
    if (!empty($in['reassign'])) {
        $to = (isset($in['reassign_to']) && $in['reassign_to'] !== '' && $in['reassign_to'] !== null) ? (int) $in['reassign_to'] : null;
        analystLcAssertActionable($conn, $ctx, $id);
        $moved = analystReassignLive($conn, $ctx, $id, $to);
    }

    if ($action === 'deactivate')     analystDeactivate($conn, $ctx, $id);
    elseif ($action === 'anonymise')  analystAnonymise($conn, $ctx, $id);
    else                              analystDelete($conn, $ctx, $id);

    echo json_encode(['success' => true, 'action' => $action, 'moved' => $moved['moved'], 'failed' => $moved['failed']]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
