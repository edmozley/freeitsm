<?php
/**
 * API: Create or update a CMDB class.
 * On create, class_key is auto-generated from the name if blank. On update,
 * class_key is updatable but defaults to the existing value (immutable in
 * spirit — see docs/cmdb.md — though changes are technically allowed).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

function slugify($name) {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug !== '' ? $slug : 'class';
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;
    $name = trim((string)($data['name'] ?? ''));
    $key = trim((string)($data['class_key'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $iconId = isset($data['icon_id']) && $data['icon_id'] !== '' ? (int)$data['icon_id'] : null;
    $displayOrder = isset($data['display_order']) ? (int)$data['display_order'] : 0;
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if ($name === '') throw new Exception('Name is required');
    if (mb_strlen($name) > 150) throw new Exception('Name too long (max 150 chars)');

    if ($key === '') {
        $key = slugify($name);
    } else {
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            throw new Exception('Key may only contain lowercase letters, numbers, and underscores');
        }
    }
    if (mb_strlen($key) > 100) throw new Exception('Key too long (max 100 chars)');

    $conn = connectToDatabase();

    if ($id === null) {
        // Auto-resolve key collisions on create by appending _N
        $base = $key; $n = 2;
        $check = $conn->prepare("SELECT id FROM cmdb_classes WHERE class_key = ?");
        while (true) {
            $check->execute([$key]);
            if (!$check->fetch()) break;
            $key = $base . '_' . $n++;
            if ($n > 50) throw new Exception('Could not generate a unique key — please supply one explicitly');
        }

        $stmt = $conn->prepare(
            "INSERT INTO cmdb_classes (class_key, name, description, icon_id, display_order, is_active, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmt->execute([$key, $name, $description ?: null, $iconId, $displayOrder, $isActive]);
        $newId = (int)$conn->lastInsertId();
        echo json_encode(['success' => true, 'id' => $newId, 'class_key' => $key]);
    } else {
        // Refuse a key change that would collide with another class
        $check = $conn->prepare("SELECT id FROM cmdb_classes WHERE class_key = ? AND id <> ?");
        $check->execute([$key, $id]);
        if ($check->fetch()) {
            throw new Exception('Another class already uses that key');
        }

        $stmt = $conn->prepare(
            "UPDATE cmdb_classes
                SET class_key = ?, name = ?, description = ?, icon_id = ?, display_order = ?, is_active = ?, updated_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        );
        $stmt->execute([$key, $name, $description ?: null, $iconId, $displayOrder, $isActive, $id]);
        echo json_encode(['success' => true, 'id' => $id]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
