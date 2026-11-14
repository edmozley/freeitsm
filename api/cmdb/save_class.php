<?php
/**
 * API: Create or update a CMDB class.
 * Thin UI adapter over CmdbSchemaService (GH #313) - the rules (key from the
 * name, _N on a key collision, length limits) live there. On update class_key is
 * updatable but defaults to the existing value (immutable in spirit - see
 * docs/cmdb.md - though changes are technically allowed).
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
    $res = CmdbSchemaService::saveClass($conn, ActorContext::fromSession($conn), $data);
    echo json_encode($res['created']
        ? ['success' => true, 'id' => $res['id'], 'class_key' => $res['class_key']]
        : ['success' => true, 'id' => $res['id']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
