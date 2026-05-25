<?php
/**
 * API Endpoint: Save rota location (create or update)
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

    $id            = $data['id'] ?? null;
    $name          = trim($data['name'] ?? '');
    $colour        = trim($data['colour'] ?? '');
    $is_default    = !empty($data['is_default']) ? 1 : 0;
    $display_order = (int)($data['display_order'] ?? 0);
    $is_active     = !empty($data['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new Exception('Name is required');
    }
    if ($colour !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
        throw new Exception('Colour must be a #rrggbb hex code');
    }

    $conn = connectToDatabase();
    $conn->beginTransaction();

    if ($is_default) {
        $clearSql = "UPDATE rota_locations SET is_default = 0";
        if ($id) $clearSql .= " WHERE id <> " . (int)$id;
        $conn->exec($clearSql);
    }

    if ($id) {
        $sql = "UPDATE rota_locations
                   SET name = ?, colour = ?, is_default = ?, display_order = ?, is_active = ?
                 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $colour ?: null, $is_default, $display_order, $is_active, $id]);
    } else {
        $sql = "INSERT INTO rota_locations (name, colour, is_default, display_order, is_active)
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $colour ?: null, $is_default, $display_order, $is_active]);
    }

    $hasDefault = (int) $conn->query("SELECT COUNT(*) FROM rota_locations WHERE is_default = 1")->fetchColumn();
    if ($hasDefault === 0) {
        $conn->exec("UPDATE rota_locations SET is_default = 1 ORDER BY display_order, id LIMIT 1");
    }

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
