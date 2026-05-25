<?php
/**
 * API Endpoint: Get folder tree structure
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
    $conn = connectToDatabase();

    $scanStmt = $conn->prepare("SELECT id FROM wiki_scan_runs WHERE status = 'completed' ORDER BY id DESC LIMIT 1");
    $scanStmt->execute();
    $scan = $scanStmt->fetch(PDO::FETCH_ASSOC);

    if (!$scan) {
        echo json_encode(['success' => true, 'tree' => []]);
        exit;
    }

    $scanId = (int)$scan['id'];

    // Get all folders with file counts
    $stmt = $conn->query("SELECT folder_path, COUNT(*) as file_count FROM wiki_files WHERE scan_id = $scanId GROUP BY folder_path ORDER BY folder_path");
    $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build tree structure
    $tree = [];
    foreach ($folders as $folder) {
        $path = $folder['folder_path'];
        $count = (int)$folder['file_count'];

        if ($path === '') {
            // Root-level files
            $tree[] = ['name' => '(root)', 'path' => '', 'file_count' => $count, 'children' => []];
            continue;
        }

        $parts = explode('/', $path);
        $current = &$tree;

        foreach ($parts as $i => $part) {
            $partPath = implode('/', array_slice($parts, 0, $i + 1));
            $found = false;

            foreach ($current as &$node) {
                if ($node['path'] === $partPath) {
                    $current = &$node['children'];
                    $found = true;
                    break;
                }
            }
            unset($node);

            if (!$found) {
                $isLeaf = ($i === count($parts) - 1);
                $newNode = [
                    'name' => $part,
                    'path' => $partPath,
                    'file_count' => $isLeaf ? $count : 0,
                    'children' => []
                ];
                $current[] = $newNode;
                $current = &$current[count($current) - 1]['children'];
            }
        }
        unset($current);
    }

    echo json_encode(['success' => true, 'tree' => $tree]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
