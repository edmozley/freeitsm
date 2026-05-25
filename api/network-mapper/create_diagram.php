<?php
/**
 * API: Create a new diagram (the v1 of its chain).
 *
 * POST { title, description, version_label }
 *
 * Returns { id } of the new diagram so the client can navigate into the editor.
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
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $title       = trim((string)($data['title'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $versionLabel = trim((string)($data['version_label'] ?? 'v1'));

    if ($title === '') throw new Exception('Title is required');
    if (mb_strlen($title) > 255) throw new Exception('Title too long (max 255 chars)');
    if (mb_strlen($versionLabel) > 50) throw new Exception('Version label too long (max 50 chars)');

    $conn = connectToDatabase();
    $stmt = $conn->prepare(
        "INSERT INTO network_diagrams
              (parent_diagram_id, title, description, version_label,
               created_by_analyst_id, created_datetime, updated_datetime)
         VALUES (NULL, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $stmt->execute([
        $title,
        $description === '' ? null : $description,
        $versionLabel === '' ? null : $versionLabel,
        (int)$_SESSION['analyst_id'],
    ]);
    echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
