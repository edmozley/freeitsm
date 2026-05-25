<?php
/**
 * API Endpoint: Save service impact level (create or update)
 *
 * severity_order drives "worst current impact" ordering on dashboards
 * (1 = worst e.g. Major Outage, higher = less severe). Two rows can share the
 * same severity_order; ties break on the lookup id.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $id             = $data['id'] ?? null;
    $name           = trim($data['name'] ?? '');
    $colour         = trim($data['colour'] ?? '');
    $is_default     = !empty($data['is_default']) ? 1 : 0;
    $severity_order = (int)($data['severity_order'] ?? 99);
    $display_order  = (int)($data['display_order'] ?? 0);
    $is_active      = !empty($data['is_active']) ? 1 : 0;

    if ($name === '') throw new Exception('Name is required');
    if ($colour !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
        throw new Exception('Colour must be a #rrggbb hex code');
    }

    $conn = connectToDatabase();
    $conn->beginTransaction();

    if ($is_default) {
        $clearSql = "UPDATE service_impact_levels SET is_default = 0";
        if ($id) $clearSql .= " WHERE id <> " . (int)$id;
        $conn->exec($clearSql);
    }

    if ($id) {
        $sql = "UPDATE service_impact_levels
                   SET name = ?, colour = ?, is_default = ?, severity_order = ?, display_order = ?, is_active = ?
                 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $colour ?: null, $is_default, $severity_order, $display_order, $is_active, $id]);
    } else {
        $sql = "INSERT INTO service_impact_levels (name, colour, is_default, severity_order, display_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $colour ?: null, $is_default, $severity_order, $display_order, $is_active]);
    }

    $hasDefault = (int) $conn->query("SELECT COUNT(*) FROM service_impact_levels WHERE is_default = 1")->fetchColumn();
    if ($hasDefault === 0) {
        $conn->exec("UPDATE service_impact_levels SET is_default = 1 ORDER BY severity_order DESC, id LIMIT 1");
    }

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
