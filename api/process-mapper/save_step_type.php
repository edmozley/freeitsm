<?php
/**
 * API Endpoint: Save a process step type (create or update).
 *
 * On create, a unique slug is generated from the name; the slug never changes
 * afterwards because existing process_steps.type strings reference it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('process-mapper');

$VALID_SHAPES = ['rectangle', 'rounded', 'pill', 'circle', 'diamond', 'parallelogram',
                 'trapezoid', 'hexagon', 'document', 'cylinder', 'cloud', 'subroutine'];

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $id        = isset($data['id']) ? (int)$data['id'] : 0;
    $name      = trim($data['name'] ?? '');
    $shape     = trim($data['shape'] ?? '');
    $color     = trim($data['color'] ?? '');
    $is_active = !empty($data['is_active']) ? 1 : 0;

    if ($name === '')                            throw new Exception('Name is required');
    if (!in_array($shape, $VALID_SHAPES, true))  throw new Exception('Unknown shape');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) throw new Exception('Colour must be a #rrggbb hex code');

    $conn = connectToDatabase();

    if ($id) {
        // Update — slug is immutable so existing steps keep resolving.
        $stmt = $conn->prepare("UPDATE process_step_types SET name = ?, shape = ?, color = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $shape, $color, $is_active, $id]);
        echo json_encode(['success' => true, 'id' => $id]);
    } else {
        // Create — generate a unique slug from the name.
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ($base === '') $base = 'type';
        $slug = $base;
        $check = $conn->prepare("SELECT COUNT(*) FROM process_step_types WHERE slug = ?");
        $n = 2;
        while (true) {
            $check->execute([$slug]);
            if ((int)$check->fetchColumn() === 0) break;
            $slug = $base . '-' . $n++;
        }
        $maxOrder = (int)$conn->query("SELECT COALESCE(MAX(display_order), 0) FROM process_step_types")->fetchColumn();
        $stmt = $conn->prepare("INSERT INTO process_step_types (name, slug, shape, color, display_order, is_active, is_builtin)
                                VALUES (?, ?, ?, ?, ?, ?, 0)");
        $stmt->execute([$name, $slug, $shape, $color, $maxOrder + 10, $is_active]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'slug' => $slug]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
