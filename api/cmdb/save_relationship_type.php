<?php
/**
 * API: Create or update a CMDB relationship type.
 * Thin UI adapter over CmdbSchemaService (GH #313) - the rules (unique verb,
 * impact direction, a redundancy rule needs one) live there.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/services/cmdb_schema.php';

header('Content-Type: application/json');

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');
requireCapabilityJson(Cap::CMDB_RELATIONSHIP_TYPES);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $conn = connectToDatabase();
    $res = CmdbSchemaService::saveRelationshipType($conn, ActorContext::fromSession($conn), $data);
    echo json_encode(['success' => true, 'id' => $res['id']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
