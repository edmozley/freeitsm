<?php
/**
 * API Endpoint: list messaging templates.
 *   ?manage=1        → all templates (for the settings page)
 *   ?provider=twilio → only that provider's active templates (for the composer picker)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/messaging/messaging.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// The inbox's template picker — everyday work.
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    $manage   = !empty($_GET['manage']);
    $provider = $_GET['provider'] ?? '';

    $sql = "SELECT * FROM messaging_templates";
    $where = [];
    $params = [];
    if (!$manage) {
        $where[] = "is_active = 1";
    }
    if ($provider !== '') {
        $where[] = "provider = ?";
        $params[] = $provider;
    }
    if ($where) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $templates = array_map(function ($r) {
        return [
            'id'           => (int) $r['id'],
            'name'         => $r['name'],
            'provider'     => $r['provider'],
            'language'     => $r['language'],
            'provider_ref' => $r['provider_ref'],
            'body'         => $r['body'],
            'var_count'    => messagingTemplateVarCount((string) $r['body']),
            'is_active'    => (bool) $r['is_active'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(['success' => true, 'templates' => $templates]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
