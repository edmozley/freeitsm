<?php
/**
 * API: Create or update a property definition on a class.
 * Thin UI adapter over CmdbSchemaService (GH #313) - the rules (valid types, an
 * object reference needs a target, the type-change guard from #312, dropdown
 * options passed as an `options` array and replaced wholesale) live there.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/services/cmdb_schema.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');
requireCapabilityJson(Cap::CMDB_CLASSES);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $conn = connectToDatabase();
    $res = CmdbSchemaService::saveProperty($conn, ActorContext::fromSession($conn), $data);
    echo json_encode(['success' => true, 'id' => $res['id'], 'property_key' => $res['property_key']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
