<?php
/**
 * API: custom fields on companies and people (GH #279) - System -> Custom fields.
 *
 * GET  ?entity=company|person            every field, for the settings screen
 * GET  ?entity=company&record=N          company N's fields with their answers (the Companies dialog)
 * POST {action: 'save', entity, id?, label, field_type, config, help_text, options, is_required, tenant_id?}
 * POST {action: 'delete', entity, id}
 * POST {action: 'reorder', entity, ids: [...]}
 *
 * Administrators only, as every System page. The rules are RecordFieldsService
 * (includes/services/record_fields.php).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/record_fields.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $entity = (string)($_GET['entity'] ?? '');
        if (!isset(RecordFieldsService::ENTITIES[$entity])) throw new ServiceError('validation', 'invalid_field', 'Unknown kind of record.');
        if (!RecordFieldsService::schemaReady($conn, $entity)) {
            echo json_encode(['success' => true, 'ready' => false, 'fields' => []]);
            exit;
        }
        if (isset($_GET['record'])) {
            $rid = (int)$_GET['record'];
            if ($entity !== 'company') throw new ServiceError('validation', 'invalid_field', 'Only a company is read here.');
            echo json_encode(['success' => true, 'ready' => true, 'fields' => RecordFieldsService::forRecord($conn, 'company', max(0, $rid))]);
            exit;
        }
        $out = ['success' => true, 'ready' => true, 'fields' => RecordFieldsService::listForSettings($conn, $entity)];
        if ($entity === 'person') {
            $out['companies'] = array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], getAllTenants($conn));
            $out['multi'] = isMultiTenant($conn);
        }
        echo json_encode($out);
        exit;
    }

    // Changes data: never on a GET, which a link on another site can send (csrf.php).
    csrfRequireWriteMethod();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new ServiceError('validation', 'invalid_field', 'Invalid request data');
    $entity = (string)($data['entity'] ?? '');
    $ctx = ActorContext::fromSession($conn);
    switch ((string)($data['action'] ?? '')) {
        case 'save':
            echo json_encode(['success' => true] + RecordFieldsService::saveField($conn, $ctx, $entity, $data));
            break;
        case 'delete':
            RecordFieldsService::deleteField($conn, $ctx, $entity, (int)($data['id'] ?? 0));
            echo json_encode(['success' => true]);
            break;
        case 'reorder':
            RecordFieldsService::reorder($conn, $ctx, $entity, (array)($data['ids'] ?? []));
            echo json_encode(['success' => true]);
            break;
        default:
            throw new ServiceError('validation', 'invalid_field', 'Unknown action.');
    }
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('custom_fields: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'That did not work.']);
}
