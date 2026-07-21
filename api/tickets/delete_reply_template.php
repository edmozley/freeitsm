<?php
/**
 * API Endpoint: delete a reply template.
 *
 * POST { id }
 *
 * Same split as the save: your own is yours to bin, a shared one is a settings action.
 * The scope is read from the DATABASE, never from the request — a client that could
 * name its own scope could delete anything.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/reply_templates.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = !empty($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $scope = replyTemplateWriteScope($conn, $analystId, $id);
    if ($scope === null) {
        // Covers both "no such template" and "somebody else's private one", and says
        // the same thing for each: a different message would confirm the id exists.
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Template not found']);
        exit;
    }
    if ($scope === 'shared') {
        requireCapabilityJson(Cap::TICKETS_REPLY_TEMPLATES);
    }

    $stmt = $conn->prepare("DELETE FROM ticket_reply_templates WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
