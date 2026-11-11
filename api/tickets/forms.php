<?php
/**
 * API: Tickets — forms a ticket needs (GH #272). The ticket twin of api/tasks/forms.php.
 *
 * GET  ?ticket_id=N                   -> { enabled, forms, available }   the ticket's Forms panel
 * GET  ?task_form_id=N                -> { fill }                         what the fill page needs
 * POST { action: add,    ticket_id, form_id, required, mode }
 * POST { action: update, task_form_id, required, mode }
 * POST { action: remove, task_form_id }
 * POST { action: draft,  task_form_id, revision, answers }       -> { revision }  or { conflict }
 * POST { action: submit, task_form_id, revision, client_reference, data } -> { submission_id }  or { conflict }
 *
 * `task_form_id` is the requirement's id - the shared fill page (forms/fill.php) sends
 * that name to both endpoints; `ticket_form_id` is accepted too.
 *
 * Thin adapter over TicketForms (includes/services/task_forms.php), which holds every
 * rule. 🔒 Every action is gated on the TICKET, including those addressed by a
 * requirement id: resolve it to its ticket first. Out of scope answers "not found".
 *
 * ⚠️ While Tickets → Settings → Checklists → "Forms on tickets" is OFF, GET says so
 * and every write is refused.
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../includes/service_context.php';
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/rbac.php';
require_once '../../includes/services/task_forms.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

/** Refuse unless this analyst can reach the ticket (and it exists). Same wording for "gone" and "not yours". */
function ticketFormsGate(PDO $conn, int $ticketId): void
{
    $s = $conn->prepare("SELECT 1 FROM tickets WHERE id = ?");
    $s->execute([$ticketId]);
    if ($ticketId <= 0 || !$s->fetchColumn() || !analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
    }
}

/** The forms that can be added: the newest version of each active form. */
function ticketFormsAvailable(PDO $conn): array
{
    $st = $conn->query("SELECT f.id, f.title FROM forms f
                         WHERE f.is_active = 1
                           AND NOT EXISTS (SELECT 1 FROM forms c WHERE c.parent_form_id = f.id)
                         ORDER BY f.title");
    return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => (string)$r['title']], $st->fetchAll(PDO::FETCH_ASSOC));
}

try {
    $conn     = connectToDatabase();
    $ctx      = ActorContext::fromSession($conn);
    $viewerId = (int)$_SESSION['analyst_id'];
    $enabled  = TicketForms::enabled($conn) && TicketForms::columnsReady($conn);
    $off      = 'Forms on tickets are switched off (Tickets → Settings → Checklists).';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $reqId = (int)($_GET['task_form_id'] ?? $_GET['ticket_form_id'] ?? 0);
        if ($reqId > 0) {
            if (!$enabled) throw new ServiceError('validation', 'disabled', $off);
            $row = TicketForms::row($conn, $reqId);
            ticketFormsGate($conn, TicketForms::hostOf($row));
            echo json_encode(['success' => true, 'fill' => TicketForms::forFill($conn, (int)$row['id']),
                              'may_fill' => TicketForms::mayFill($conn, $viewerId)]);
            exit;
        }
        $ticketId = (int)($_GET['ticket_id'] ?? 0);
        ticketFormsGate($conn, $ticketId);
        if (!$enabled) {
            echo json_encode(['success' => true, 'enabled' => false, 'forms' => [], 'available' => []]);
            exit;
        }
        echo json_encode([
            'success'   => true,
            'enabled'   => true,
            'forms'     => TicketForms::forTicket($conn, $ticketId, $viewerId, !empty($_GET['answers'])),
            'available' => ticketFormsAvailable($conn),
            'can_write' => analystCanWriteModule($conn, $viewerId, 'tickets'),
        ]);
        exit;
    }

    csrfRequireWriteMethod();
    if (!$enabled) throw new ServiceError('validation', 'disabled', $off);

    $in     = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    $reqId  = (int)($in['task_form_id'] ?? $in['ticket_form_id'] ?? 0);
    $extra  = [];

    if ($action === 'add') {
        $ticketId = (int)($in['ticket_id'] ?? 0);
        ticketFormsGate($conn, $ticketId);
        TicketForms::add($conn, $ctx, $ticketId, (int)($in['form_id'] ?? 0),
                         !array_key_exists('required', $in) || !empty($in['required']), (string)($in['mode'] ?? 'block'));
    } else {
        $row = TicketForms::row($conn, $reqId);
        $ticketId = TicketForms::hostOf($row);
        ticketFormsGate($conn, $ticketId);
        switch ($action) {
            case 'update': TicketForms::update($conn, $ctx, (int)$row['id'], !empty($in['required']), (string)($in['mode'] ?? 'block')); break;
            case 'remove': TicketForms::remove($conn, $ctx, (int)$row['id']); break;
            case 'draft':  $extra = TicketForms::saveDraft($conn, $ctx, (int)$row['id'], (int)($in['revision'] ?? -1), (array)($in['answers'] ?? [])); break;
            case 'submit': $extra = TicketForms::submit($conn, $ctx, (int)$row['id'], (int)($in['revision'] ?? -1),
                                                         (string)($in['client_reference'] ?? ''), (array)($in['data'] ?? [])); break;
            default: throw new ServiceError('validation', 'invalid_field', 'Unknown action.');
        }
    }

    // The whole state back, so the panel re-renders from one source of truth.
    echo json_encode(['success' => true, 'enabled' => true] + $extra + [
        'forms' => TicketForms::forTicket($conn, $ticketId, $viewerId, false),
    ]);

} catch (TaskFormConflict $e) {
    // A lost race: nothing was written. What the other person saved comes back.
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode, 'conflict' => $e->detail]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    error_log('[api/tickets/forms] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not update the ticket\'s forms.']);
}
