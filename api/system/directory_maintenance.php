<?php
/**
 * Directory maintenance - the people a directory source imported (issue #261).
 *
 *   GET  ?action=orphans                                  people flagged as imported whose source is gone
 *   GET  ?action=stale&provider=<id>&days=30|90|180|365   people a source has not returned for that long
 *   GET  ?action=runs&provider=<id>                       the runs of a source that created anybody
 *   GET  ?action=imported&provider=<id>[&run=<id>]        the people a source created, and still manages
 *   GET  ?action=preview_delete&provider=<id>&people=keep|left
 *                                                         what deleting a source would do (#261), changing nothing
 *   GET  ?action=preview_remove&provider=<id>[&run=<id>][&ids=1,2,3]
 *                                                         what Remove imported people would do, changing nothing
 *   POST { action: 'apply', list: orphans|stale|imported, do: keep|left|delete|remove,
 *          ids: [...], provider, days, run }               act on the ones picked, if still in that list
 *
 * System administrators only. Reads never write; a write is a POST. Every rule
 * - which list may do what, who is in it right now, the company scope, that
 * nobody with tickets or equipment is deleted, that Remove waits until the
 * source no longer imports - is in includes/services/directory_maintenance.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // System admins only
require_once '../../includes/functions.php';
require_once '../../includes/services/directory_maintenance.php';

header('Content-Type: application/json');

try {
    $conn   = connectToDatabase();
    $actor  = ActorContext::fromSession($conn);
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        csrfRequireWriteMethod();
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        if (($in['action'] ?? '') !== 'apply') throw new ServiceError('validation', 'invalid_field', 'Unknown action.');
        $result = DirectoryMaintenance::apply($conn, $actor, (string)($in['list'] ?? ''), (string)($in['do'] ?? ''),
            is_array($in['ids'] ?? null) ? $in['ids'] : [],
            ['provider_id' => (int)($in['provider'] ?? 0), 'days' => (int)($in['days'] ?? 0), 'run_id' => (int)($in['run'] ?? 0)]);
        echo json_encode(['success' => true] + $result);
        exit;
    }

    // Reads. A list-shaped parameter is not a number: treated as none.
    $num = function (string $k): int { return is_scalar($_GET[$k] ?? null) ? (int)$_GET[$k] : 0; };
    switch (is_scalar($_GET['action'] ?? null) ? (string)$_GET['action'] : '') {
        case 'orphans':
            echo json_encode(['success' => true] + DirectoryMaintenance::orphans($conn, $actor) + ['limit' => DirectoryMaintenance::LIMIT]);
            break;
        case 'stale':
            echo json_encode(['success' => true] + DirectoryMaintenance::stale($conn, $actor, $num('provider'), $num('days')) + ['limit' => DirectoryMaintenance::LIMIT]);
            break;
        case 'runs':
            echo json_encode(['success' => true, 'runs' => DirectoryMaintenance::importRuns($conn, $actor, $num('provider'))]);
            break;
        case 'imported':
            echo json_encode(['success' => true] + DirectoryMaintenance::imported($conn, $actor, $num('provider'), $num('run') ?: null) + ['limit' => DirectoryMaintenance::LIMIT]);
            break;
        case 'preview_delete':
            require_once '../../includes/entra_sync.php';   // entraIsEntraIssuer(): whether an OIDC source is Entra
            echo json_encode(['success' => true, 'prepared_by' => $_SESSION['analyst_name'] ?? '',
                'preview' => DirectoryMaintenance::previewSourceDelete($conn, $actor, $num('provider'), ($_GET['people'] ?? '') === 'left')]);
            break;
        case 'preview_remove':
            $ids = is_scalar($_GET['ids'] ?? null) && (string)$_GET['ids'] !== '' ? explode(',', (string)$_GET['ids']) : null;
            echo json_encode(['success' => true, 'prepared_by' => $_SESSION['analyst_name'] ?? '',
                'preview' => DirectoryMaintenance::previewRemove($conn, $actor, $num('provider'), $num('run') ?: null, $ids)]);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    error_log('directory_maintenance: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Something went wrong - nothing has been changed.']);
}
