<?php
/**
 * API: Assets -> Consumables (5.8.0). Every read and write goes through
 * ConsumablesService (includes/services/consumables.php), the tables' only writer.
 *
 *   GET  ?action=list&q=&type=&status=low|out&inactive=1   the items, with what is on hand
 *   GET  ?action=item&id=N                                 one item: fields, movements, history
 *   GET  ?action=meta                                      types, suppliers, open stock take, may_adjust
 *   GET  ?action=movements&ticket=N | &recipient=N         what went out against a ticket / to a person
 *   GET  ?action=people&q=                                 people to issue to (the analyst's companies)
 *   GET  ?action=counts | count&id=N | differences&months=N   stock takes and the differences report
 *   GET  ?action=verify                                    items whose stock and ledger disagree (should be none)
 *   GET  ?action=deliveries&q= | delivery&id=N               deliveries: one order, its lines and documents
 *   GET  ?action=ticket&id=N                               a ticket's consumables: requested on its forms, and issued against it
 *   GET  ?action=ticket_items&id=N                         the items the ticket's company has, for "Use a consumable"
 *
 *   POST {action: save_item | save_type | move | reverse | count_start | count_line | count_finish | count_cancel
 *               | receive_delivery | save_delivery | reverse_delivery, ...}
 *
 * Reading needs Assets. Writing needs WRITE access to Assets (read-only Assets sees stock
 * and cannot move it); types need the Consumables settings permission; adjusting and
 * finishing a stock take need "Adjust consumable stock" - the service checks that one,
 * so it holds however it is reached.
 *
 * @wiki Consumables-Developer-Guide
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/services/consumables.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');

try {
    $conn = connectToDatabase();
    if (!ConsumablesService::ready($conn)) {
        echo json_encode(['success' => false, 'error' => 'Run System -> Database Verification to set up consumables.', 'code' => 'schema_not_ready']);
        exit;
    }
    $ctx = ActorContext::fromSession($conn);
    $aid = (int)$_SESSION['analyst_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $int = fn(string $k) => is_scalar($_GET[$k] ?? null) ? (int)$_GET[$k] : 0;
        $str = fn(string $k) => is_scalar($_GET[$k] ?? null) ? (string)$_GET[$k] : '';
        switch ($_GET['action'] ?? 'list') {
            case 'list':
                $out = ['items' => ConsumablesService::listItems($conn, $ctx, [
                    'q' => $str('q'), 'type' => $int('type'), 'status' => $str('status'), 'inactive' => $str('inactive') === '1',
                ])];
                break;
            case 'item':
                $out = ['item' => ConsumablesService::getItem($conn, $ctx, $int('id'))];
                break;
            case 'meta':
                $sup = $conn->query("SELECT id, COALESCE(NULLIF(trading_name, ''), legal_name) AS name FROM suppliers ORDER BY name LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
                $out = [
                    'types'      => ConsumablesService::types($conn),
                    'suppliers'  => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']], $sup),
                    'open_count' => ConsumablesService::openCount($conn, $ctx),
                    'may_adjust' => analystHasCapability($conn, $aid, Cap::ASSETS_CONSUMABLES_ADJUST),
                    'may_setup'  => analystHasCapability($conn, $aid, Cap::ASSETS_CONSUMABLES),
                    'fields_by_type' => (object)array_reduce(ConsumablesService::types($conn), function ($acc, $t) use ($conn) {
                        $acc[$t['id']] = RecordFieldsService::schemaReady($conn, 'consumable')
                            ? RecordFieldsService::forRecord($conn, 'consumable', 0, $t['id']) : [];
                        return $acc;
                    }, ['0' => RecordFieldsService::schemaReady($conn, 'consumable') ? RecordFieldsService::forRecord($conn, 'consumable', 0, null) : []]),
                ];
                break;
            case 'movements':
                if ($int('ticket') > 0 && !analystCanAccessTicket($conn, $aid, $int('ticket'))) throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
                if ($int('recipient') > 0 && !analystCanAccessUser($conn, $aid, $int('recipient'))) throw new ServiceError('not_found', 'not_found', 'Person not found.');
                $out = ['movements' => ConsumablesService::movements($conn, ['ticket' => $int('ticket'), 'recipient' => $int('recipient')])];
                break;
            case 'people':
                require_once '../../includes/people.php';
                $q = trim($str('q'));
                $rows = mb_strlen($q) >= 2 ? peopleListRows($conn, $aid, ['q' => $q], 15) : [];
                $out = ['people' => array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'] ?? '', 'email' => $p['email'] ?? null,
                                                         'department' => $p['department'] ?? null], $rows)];
                break;
            case 'counts':
                $out = ['counts' => ConsumablesService::counts($conn, $ctx)];
                break;
            case 'count':
                $out = ['count' => ConsumablesService::getCount($conn, $ctx, $int('id'))];
                break;
            case 'differences':
                $out = ['report' => ConsumablesService::differences($conn, $ctx, $int('months') ?: 12)];
                break;
            case 'ticket':
                if (!analystCanAccessTicket($conn, $aid, $int('id'))) throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
                $out = ConsumablesService::forTicket($conn, $ctx, $int('id'));
                break;
            case 'ticket_items':
                if (!analystCanAccessTicket($conn, $aid, $int('id'))) throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
                $out = ['items' => ConsumablesService::ticketItems($conn, $ctx, $int('id'))];
                break;
            case 'deliveries':
                $out = ['deliveries' => ConsumablesService::deliveries($conn, $ctx, ['q' => $str('q')])];
                break;
            case 'delivery':
                $out = ['delivery' => ConsumablesService::getDelivery($conn, $ctx, $int('id'))];
                break;
            case 'verify':
                $out = ['mismatches' => ConsumablesService::verify($conn)];
                break;
            default:
                throw new ServiceError('validation', 'invalid_field', 'Unknown action.');
        }
        echo json_encode(['success' => true] + $out);
        exit;
    }

    csrfRequireWriteMethod();
    requireModuleWriteJson('assets');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    // From a ticket, the history says so.
    if (($in['source'] ?? '') === 'ticket') $ctx->source = 'ticket';
    switch ($in['action'] ?? '') {
        case 'save_item':
            $out = ['id' => ConsumablesService::saveItem($conn, $ctx, (array)($in['item'] ?? []))];
            break;
        case 'save_type':
            requireCapabilityJson(Cap::ASSETS_CONSUMABLES, $conn);
            $out = ['id' => ConsumablesService::saveType($conn, $ctx, (array)($in['type'] ?? []))];
            break;
        case 'move':
            unset($in['delivery_id']);   // a receipt joins a delivery only through receive_delivery
            $out = ConsumablesService::move($conn, $ctx, (string)($in['kind'] ?? ''), (int)($in['item_id'] ?? 0), $in);
            break;
        case 'issue_to_ticket':
            // Use a consumable on a ticket (right-click, or the ticket's Consumables pills): to its requester.
            if (!analystCanAccessTicket($conn, $aid, (int)($in['ticket_id'] ?? 0))) throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
            $out = ConsumablesService::issueToTicket($conn, $ctx, (int)($in['ticket_id'] ?? 0), (int)($in['item_id'] ?? 0), $in['quantity'] ?? 1);
            break;
        case 'receive_delivery':
            $out = ConsumablesService::receiveDelivery($conn, $ctx, (array)($in['delivery'] ?? []));
            break;
        case 'save_delivery':
            ConsumablesService::saveDelivery($conn, $ctx, (int)($in['id'] ?? 0), (array)($in['delivery'] ?? []));
            $out = [];
            break;
        case 'reverse_delivery':
            $out = ['undone' => ConsumablesService::reverseDelivery($conn, $ctx, (int)($in['id'] ?? 0), (string)($in['reason'] ?? ''))];
            break;
        case 'reverse':
            $out = ConsumablesService::reverse($conn, $ctx, (int)($in['movement_id'] ?? 0), (string)($in['reason'] ?? ''));
            break;
        case 'count_start':
            $out = ['id' => ConsumablesService::startCount($conn, $ctx, (string)($in['note'] ?? ''))];
            break;
        case 'count_line':
            $out = ConsumablesService::countLine($conn, $ctx, (int)($in['count_id'] ?? 0), (int)($in['item_id'] ?? 0), $in['counted'] ?? null);
            break;
        case 'count_finish':
            $out = ConsumablesService::finishCount($conn, $ctx, (int)($in['count_id'] ?? 0));
            break;
        case 'count_cancel':
            ConsumablesService::cancelCount($conn, $ctx, (int)($in['count_id'] ?? 0));
            $out = [];
            break;
        default:
            throw new ServiceError('validation', 'invalid_field', 'Unknown action.');
    }
    echo json_encode(['success' => true] + $out);
} catch (ServiceError $e) {
    if ($e->kind === 'forbidden') http_response_code(403);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    error_log('api/assets/consumables.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
