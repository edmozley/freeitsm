<?php
/**
 * API: Save the editable (current) diagram — metadata, nodes, and connectors.
 * Thin UI adapter over NetworkMapperService. Maps the UI's payload shape onto
 * the service's canonical form: node id/tempId -> `ref`, connector
 * from_node_id/to_node_id -> from_ref/to_ref, flat branding -> nested.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/network_mapper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('network-mapper');

try {
    $conn = connectToDatabase();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) ? (int)$data['id'] : 0;

    $in = [];
    foreach (['title', 'description', 'version_label', 'paper_size', 'paper_orientation'] as $k) {
        if (array_key_exists($k, $data)) $in[$k] = $data[$k];
    }
    // Flat branding keys (header_left, …) -> nested branding.{band}.{slot}
    $branding = [];
    foreach (['header', 'footer'] as $band) {
        foreach (['left', 'center', 'right'] as $slot) {
            $flat = "{$band}_{$slot}";
            if (array_key_exists($flat, $data)) $branding[$band][$slot] = $data[$flat];
        }
    }
    if ($branding) $in['branding'] = $branding;
    // Nodes: the UI's id/tempId is the ref connectors point at.
    if (array_key_exists('nodes', $data) && is_array($data['nodes'])) {
        $in['nodes'] = [];
        foreach ($data['nodes'] as $i => $n) {
            if (!is_array($n)) { $in['nodes'][] = $n; continue; }
            $ref = $n['id'] ?? ($n['tempId'] ?? "_idx_$i");
            $in['nodes'][] = [
                'cmdb_object_id' => $n['cmdb_object_id'] ?? null,
                'x'             => $n['x'] ?? null,
                'y'             => $n['y'] ?? null,
                'size'          => $n['size'] ?? null,
                'icon_override' => $n['icon_override'] ?? null,
                'ref'           => (string)$ref,
            ];
        }
    }
    // Connectors: from_node_id/to_node_id reference a node's id/tempId (its ref).
    if (array_key_exists('connectors', $data) && is_array($data['connectors'])) {
        $in['connectors'] = [];
        foreach ($data['connectors'] as $c) {
            if (!is_array($c)) { $in['connectors'][] = $c; continue; }
            $in['connectors'][] = [
                'from_ref'             => isset($c['from_node_id']) ? (string)$c['from_node_id'] : null,
                'to_ref'               => isset($c['to_node_id']) ? (string)$c['to_node_id'] : null,
                'cmdb_relationship_id' => $c['cmdb_relationship_id'] ?? null,
                'label'                => $c['label'] ?? null,
                'line_style'           => $c['line_style'] ?? null,
            ];
        }
    }

    $savedId = NetworkMapperService::saveDiagram($conn, ActorContext::fromSession($conn), $id, $in);
    echo json_encode(['success' => true, 'id' => $savedId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
