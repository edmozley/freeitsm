<?php
/**
 * API: the portal's Approvals page (GH #160 Part C2, #348 slice 2).
 *
 * GET  ?filter=mine|decided  -> { success, items: [...], counts: {mine, decided}, away_until, deputy_mode }
 * POST { action: 'decide', submission_id, decision: 'approved'|'rejected', comment }
 *        -> { success, decision: approved | rejected | step_passed, step? }
 * POST { action: 'away', until: 'Y-m-d' | '' }  -> { success, away_until }
 *
 * The requester's manager and a cost centre's owner (or deputy) decide here, signed in
 * as themselves. ⚠️ Nothing here trusts a request id: every listing and every decision
 * asks catalogueUserMayDecide() of the request's CURRENT step, right then
 * (includes/catalogue_approval_people.php). The person comes from the session only.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/catalogue_approvals.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$userId = (int)$_SESSION['ss_user_id'];

try {
    $conn = connectToDatabase();
    requireModuleOnJson($conn, 'forms');   // GH #217
    if (!cataloguePeopleAvailable($conn)) {
        echo json_encode(['success' => true, 'items' => [], 'counts' => ['mine' => 0, 'decided' => 0], 'away_until' => null]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $filter = ($_GET['filter'] ?? 'mine') === 'decided' ? 'decided' : 'mine';
        $list = catalogueUserApprovalsList($conn, $userId, $filter);
        echo json_encode(['success' => true, 'items' => $list['items'], 'counts' => $list['counts'],
                          'away_until' => catalogueAwayUntil($conn, $userId),
                          'deputy_mode' => catalogueApprovalOptions($conn)['deputy_mode']]);
        exit;
    }

    csrfRequireWriteMethod();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');

    if ($action === 'decide') {
        $id = (int)($in['submission_id'] ?? 0);
        try {
            $r = catalogueDecideAsPerson($conn, $userId, $id, (string)($in['decision'] ?? ''), is_string($in['comment'] ?? null) ? $in['comment'] : '');
        } catch (PDOException $e) {
            throw $e;
        } catch (Exception $e) {
            // Written for the person deciding ("not waiting for you", "say why") - shown as is.
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        echo json_encode(['success' => true, 'decision' => $r['decision'], 'step' => $r['step'] ?? null]);
    } elseif ($action === 'away') {
        try {
            $until = catalogueSetAway($conn, $userId, is_string($in['until'] ?? null) ? $in['until'] : '');
        } catch (PDOException $e) {
            throw $e;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        echo json_encode(['success' => true, 'away_until' => $until]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    // Never the exception's own text: it can carry SQL and column names.
    error_log('self-service approvals: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
