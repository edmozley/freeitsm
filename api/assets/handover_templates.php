<?php
/**
 * API: handover template CRUD + preview (discussion #56).
 *
 * GET  ?action=list                     every template
 * GET  ?action=get&id=<int>             one, with its blocks
 * GET  ?action=meta                     block catalogue, columns, merge codes
 * POST {action:'save', …}               create or update
 * POST {action:'default', id}           make one the default
 * POST {action:'delete', id}            remove one
 * POST {action:'preview', blocks, user_id}  render without saving
 *
 * Preview renders through the SAME renderer as the printable page, so the
 * designer cannot show something the document will not produce.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/services/handover_templates.php';
require_once '../../includes/services/assets.php';
require_once '../../includes/tenancy.php';
I18n::initFromSession();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');
Tz::init();   // the analyst's zone, for the assigned date (fmt_local)

$isWrite = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isWrite) {
    // Designing the document is administration; reading it is not.
    requireCapabilityJson(Cap::ASSETS_HANDOVER);
}

try {
    $conn = connectToDatabase();
    $in   = $isWrite ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
    $action = (string)($in['action'] ?? 'list');

    switch ($action) {
        case 'meta':
            echo json_encode([
                'success'     => true,
                'catalogue'   => HandoverTemplates::catalogue(),
                'columns'     => HandoverTemplates::assetColumns($conn),
                'merge_codes' => HandoverTemplates::mergeCodes(),
                'defaults'    => HandoverTemplates::defaultBlocks(),
            ]);
            break;

        case 'list':
            echo json_encode(['success' => true, 'templates' => HandoverTemplates::listAll($conn)]);
            break;

        case 'get':
            $t = HandoverTemplates::load($conn, (int)($in['id'] ?? 0));
            if (!$t) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Not found']); break; }
            echo json_encode(['success' => true, 'template' => $t]);
            break;

        case 'save':
            $id = HandoverTemplates::save($conn, $in);
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'default':
            HandoverTemplates::makeDefault($conn, (int)($in['id'] ?? 0));
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            HandoverTemplates::delete($conn, (int)($in['id'] ?? 0));
            echo json_encode(['success' => true]);
            break;

        case 'preview':
            // Sample data when no real person is chosen, so the designer works on
            // an install that has not assigned anything to anybody yet.
            $userId = (int)($in['user_id'] ?? 0);
            $user   = ['id' => 0, 'name' => 'Alex Sample', 'email' => 'alex.sample@example.com'];
            $assets = [
                ['asset_type' => 'Laptop',  'hostname' => 'LT-0042', 'manufacturer' => 'Dell', 'model' => 'Latitude 5540',
                 'service_tag' => 'ABC1234', 'asset_tag' => 'LT0042', 'assigned_datetime' => gmdate('Y-m-d H:i:s'),
                 'location' => 'Head office', 'asset_status' => 'In use', 'notes' => 'Issued at onboarding'],
                ['asset_type' => 'Monitor', 'hostname' => 'MON-0088', 'manufacturer' => 'Dell', 'model' => 'U2722D',
                 'service_tag' => 'XYZ9876', 'asset_tag' => '', 'assigned_datetime' => gmdate('Y-m-d H:i:s'),
                 'location' => 'Head office', 'asset_status' => 'In use', 'notes' => ''],
            ];
            if ($userId > 0) {
                $real = AssetsService::assetsForUser($conn, ActorContext::fromSession($conn), $userId);
                if ($real) { $user = $real['user']; $assets = $real['assets']; }
            }
            echo json_encode([
                'success' => true,
                'html'    => HandoverTemplates::renderBlocks(
                    HandoverTemplates::sanitiseBlocks($in['blocks'] ?? null),
                    $user, $assets,
                    ['logo_path' => null, 'analyst_name' => $_SESSION['analyst_name'] ?? null]
                ),
            ]);
            break;

        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
