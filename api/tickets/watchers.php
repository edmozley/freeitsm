<?php
/**
 * API: who watches a ticket, and watching / unwatching it (GitHub #277).
 *
 *   GET  ?ticket_id=N            the watchers, whether I watch, whether I may add others
 *   POST {action: 'watch',   ticket_id, analyst_id?}   analyst_id omitted = me
 *   POST {action: 'unwatch', ticket_id, analyst_id?}
 *   POST {action: 'watch_many', ticket_ids: [..]}      the right-click menu on a selection - me only
 *
 * Thin adapter over TicketWatchers (includes/services/ticket_watchers.php), which
 * holds the rules: anyone who can see a ticket may watch it; adding or removing
 * someone ELSE needs write access to Tickets.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/tenancy.php';
require_once __DIR__ . '/../../includes/services/ticket_watchers.php';
require_once __DIR__ . '/../../includes/service_context.php';   // ServiceError, thrown here directly

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$conn = connectToDatabase();
$me = (int)$_SESSION['analyst_id'];
$canWrite = analystCanWriteModule($conn, $me, 'tickets');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $ticketId = (int)($_GET['ticket_id'] ?? 0);
        if (!TicketWatchers::canSee($conn, $me, $ticketId)) {
            throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
        }
        $list = TicketWatchers::list($conn, $ticketId);
        echo json_encode([
            'success'   => true,
            'ready'     => TicketWatchers::ready($conn),
            'watchers'  => $list,
            'watching'  => in_array($me, array_column($list, 'analyst_id'), true),
            'can_add'   => $canWrite,
        ]);
        exit;
    }

    csrfRequireWriteMethod();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $ctx = ActorContext::fromSession($conn);
    $action = (string)($in['action'] ?? '');

    if ($action === 'watch_many') {
        // Me on each selected ticket I can see; one that is not mine to see is skipped, not an error.
        $done = 0; $already = 0; $skipped = 0;
        foreach (array_slice(array_unique(array_map('intval', (array)($in['ticket_ids'] ?? []))), 0, 500) as $id) {
            try {
                $r = TicketWatchers::add($conn, $ctx, $id, $me, $canWrite);
                $r['added'] ? $done++ : $already++;
            } catch (ServiceError $e) {
                if ($e->kind === 'validation' && $e->errorCode === 'not_ready') throw $e;
                $skipped++;
            }
        }
        echo json_encode(['success' => true, 'added' => $done, 'already' => $already, 'skipped' => $skipped]);
        exit;
    }

    $ticketId = (int)($in['ticket_id'] ?? 0);
    $who = isset($in['analyst_id']) && (int)$in['analyst_id'] > 0 ? (int)$in['analyst_id'] : $me;
    if ($action === 'watch') {
        $r = TicketWatchers::add($conn, $ctx, $ticketId, $who, $canWrite);
    } elseif ($action === 'unwatch') {
        $r = TicketWatchers::remove($conn, $ctx, $ticketId, $who, $canWrite);
    } else {
        throw new ServiceError('validation', 'invalid_field', 'Unknown action.');
    }
    $list = TicketWatchers::list($conn, $ticketId);
    echo json_encode(['success' => true] + $r + [
        'watchers' => $list,
        'watching' => in_array($me, array_column($list, 'analyst_id'), true),
    ]);
} catch (ServiceError $e) {
    http_response_code($e->kind === 'not_found' ? 404 : ($e->kind === 'forbidden' ? 403 : 400));
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('api/tickets/watchers.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not update the watchers.']);
}
