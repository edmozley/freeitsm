<?php
/**
 * API Endpoint: Get full detail for a single file
 * Query param: ?id=123
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($fileId <= 0) {
    echo json_encode(['success' => false, 'error' => 'File ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get file record
    $stmt = $conn->prepare("SELECT * FROM wiki_files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        echo json_encode(['success' => false, 'error' => 'File not found']);
        exit;
    }

    // Functions in this file
    $funcStmt = $conn->prepare("SELECT id, function_name, line_number, parameters, class_name, visibility, is_static, description FROM wiki_functions WHERE file_id = ? ORDER BY line_number");
    $funcStmt->execute([$fileId]);
    $functions = $funcStmt->fetchAll(PDO::FETCH_ASSOC);

    // Classes in this file
    $classStmt = $conn->prepare("SELECT id, class_name, line_number, extends_class, implements_interfaces, description FROM wiki_classes WHERE file_id = ? ORDER BY line_number");
    $classStmt->execute([$fileId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    // Dependencies FROM this file (what it includes/fetches)
    $depStmt = $conn->prepare("SELECT d.id, d.dependency_type, d.target_path, d.line_number, d.resolved_file_id,
                                       rf.file_path as resolved_path, rf.file_name as resolved_name
                                FROM wiki_dependencies d
                                LEFT JOIN wiki_files rf ON d.resolved_file_id = rf.id
                                WHERE d.file_id = ?
                                ORDER BY d.line_number");
    $depStmt->execute([$fileId]);
    $dependencies = $depStmt->fetchAll(PDO::FETCH_ASSOC);

    // Dependents ON this file (who includes/fetches this file)
    $revStmt = $conn->prepare("SELECT d.id, d.dependency_type, d.line_number,
                                       f.id as source_file_id, f.file_path as source_file_path, f.file_name as source_file_name
                                FROM wiki_dependencies d
                                INNER JOIN wiki_files f ON d.file_id = f.id
                                WHERE d.resolved_file_id = ?
                                ORDER BY f.file_path");
    $revStmt->execute([$fileId]);
    $dependents = $revStmt->fetchAll(PDO::FETCH_ASSOC);

    // DB table references
    $dbStmt = $conn->prepare("SELECT id, table_name, reference_type, line_number FROM wiki_db_references WHERE file_id = ? ORDER BY line_number");
    $dbStmt->execute([$fileId]);
    $dbReferences = $dbStmt->fetchAll(PDO::FETCH_ASSOC);

    // Session variables
    $sessStmt = $conn->prepare("SELECT id, variable_name, access_type, line_number FROM wiki_session_vars WHERE file_id = ? ORDER BY line_number");
    $sessStmt->execute([$fileId]);
    $sessionVars = $sessStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'file' => $file,
        'functions' => $functions,
        'classes' => $classes,
        'dependencies' => $dependencies,
        'dependents' => $dependents,
        'db_references' => $dbReferences,
        'session_vars' => $sessionVars
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
