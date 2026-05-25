<?php
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Debug get_files v2 ===\n\n";

$folder = isset($_GET['folder']) ? trim($_GET['folder']) : null;
echo "folder param: " . var_export($folder, true) . "\n";
echo "folder type: " . gettype($folder) . "\n";
echo "folder strlen: " . ($folder !== null ? strlen($folder) : 'N/A') . "\n\n";

try {
    $conn = connectToDatabase();
    echo "DB connected OK\n";
    echo "PDO driver: " . $conn->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

    $scanId = 2; // hardcoded from previous debug

    // ---- Test A: The exact query from get_files.php (with CAST on column) ----
    echo "=== Test A: Full query with CAST on column ===\n";
    try {
        $sql = "SELECT f.id, f.file_path, f.file_name, f.folder_path, f.file_type,
                       f.file_size_bytes, f.line_count, f.last_modified, f.description,
                       (SELECT COUNT(*) FROM wiki_functions WHERE file_id = f.id) as function_count,
                       (SELECT COUNT(*) FROM wiki_dependencies WHERE file_id = f.id) as dependency_count
                FROM wiki_files f
                WHERE f.scan_id = $scanId
                AND (f.folder_path = ? OR f.folder_path LIKE ?)
                ORDER BY f.folder_path, f.file_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$folder, $folder . '/%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "  OK - returned " . count($rows) . " rows\n";
    } catch (Exception $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // ---- Test B: Plain parameter comparison ----
    echo "=== Test B: Plain parameter comparison ===\n";
    try {
        $sql = "SELECT f.id, f.file_path, f.file_name, f.folder_path, f.file_type,
                       f.file_size_bytes, f.line_count, f.last_modified, f.description,
                       (SELECT COUNT(*) FROM wiki_functions WHERE file_id = f.id) as function_count,
                       (SELECT COUNT(*) FROM wiki_dependencies WHERE file_id = f.id) as dependency_count
                FROM wiki_files f
                WHERE f.scan_id = $scanId
                AND (f.folder_path = ? OR f.folder_path LIKE ?)
                ORDER BY f.folder_path, f.file_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$folder, $folder . '/%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "  OK - returned " . count($rows) . " rows\n";
    } catch (Exception $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // ---- Test C: CAST on BOTH sides ----
    echo "=== Test C: Plain comparison (both sides) ===\n";
    try {
        $sql = "SELECT f.id, f.file_path, f.file_name, f.folder_path, f.file_type,
                       f.file_size_bytes, f.line_count, f.last_modified, f.description,
                       (SELECT COUNT(*) FROM wiki_functions WHERE file_id = f.id) as function_count,
                       (SELECT COUNT(*) FROM wiki_dependencies WHERE file_id = f.id) as dependency_count
                FROM wiki_files f
                WHERE f.scan_id = $scanId
                AND (f.folder_path = ? OR f.folder_path LIKE ?)
                ORDER BY f.folder_path, f.file_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$folder, $folder . '/%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "  OK - returned " . count($rows) . " rows\n";
    } catch (Exception $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // ---- Test D: Using bindParam with explicit PDO::PARAM_STR ----
    echo "=== Test D: bindParam with PDO::PARAM_STR ===\n";
    try {
        $sql = "SELECT f.id, f.file_path, f.file_name, f.folder_path, f.file_type,
                       f.file_size_bytes, f.line_count, f.last_modified, f.description,
                       (SELECT COUNT(*) FROM wiki_functions WHERE file_id = f.id) as function_count,
                       (SELECT COUNT(*) FROM wiki_dependencies WHERE file_id = f.id) as dependency_count
                FROM wiki_files f
                WHERE f.scan_id = $scanId
                AND (f.folder_path = ? OR f.folder_path LIKE ?)
                ORDER BY f.folder_path, f.file_name";
        $stmt = $conn->prepare($sql);
        $likeval = $folder . '/%';
        $stmt->bindParam(1, $folder, PDO::PARAM_STR);
        $stmt->bindParam(2, $likeval, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "  OK - returned " . count($rows) . " rows\n";
    } catch (Exception $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // ---- Test E: Using bindParam with explicit length ----
    echo "=== Test E: bindParam with PDO::PARAM_STR and length ===\n";
    try {
        $sql = "SELECT f.id, f.file_path, f.file_name, f.folder_path, f.file_type,
                       f.file_size_bytes, f.line_count, f.last_modified, f.description,
                       (SELECT COUNT(*) FROM wiki_functions WHERE file_id = f.id) as function_count,
                       (SELECT COUNT(*) FROM wiki_dependencies WHERE file_id = f.id) as dependency_count
                FROM wiki_files f
                WHERE f.scan_id = $scanId
                AND (f.folder_path = ? OR f.folder_path LIKE ?)
                ORDER BY f.folder_path, f.file_name";
        $stmt = $conn->prepare($sql);
        $likeval = $folder . '/%';
        $stmt->bindParam(1, $folder, PDO::PARAM_STR, 500);
        $stmt->bindParam(2, $likeval, PDO::PARAM_STR, 500);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "  OK - returned " . count($rows) . " rows\n";
    } catch (Exception $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // ---- Test F: No bound params at all - inline the value ----
    echo "=== Test F: Inline value (no bound params) ===\n";
    try {
        $safePath = str_replace("'", "''", $folder);
        $sql = "SELECT f.id, f.file_path, f.file_name, f.folder_path, f.file_type,
                       f.file_size_bytes, f.line_count, f.last_modified, f.description,
                       (SELECT COUNT(*) FROM wiki_functions WHERE file_id = f.id) as function_count,
                       (SELECT COUNT(*) FROM wiki_dependencies WHERE file_id = f.id) as dependency_count
                FROM wiki_files f
                WHERE f.scan_id = $scanId
                AND (f.folder_path = N'$safePath' OR f.folder_path LIKE N'$safePath/%')
                ORDER BY f.folder_path, f.file_name";
        $stmt = $conn->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "  OK - returned " . count($rows) . " rows\n";
    } catch (Exception $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // ---- Test G: Simple query with bound params (no subqueries, no description) ----
    echo "=== Test G: Simple query (no subqueries, no description column) ===\n";
    try {
        $sql = "SELECT f.id, f.file_path, f.file_name, f.folder_path, f.file_type,
                       f.line_count
                FROM wiki_files f
                WHERE f.scan_id = $scanId
                AND (f.folder_path = ? OR f.folder_path LIKE ?)
                ORDER BY f.folder_path, f.file_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$folder, $folder . '/%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "  OK - returned " . count($rows) . " rows\n";
    } catch (Exception $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // ---- Test H: Full query but WITHOUT description column ----
    echo "=== Test H: Full query with subqueries but NO description column ===\n";
    try {
        $sql = "SELECT f.id, f.file_path, f.file_name, f.folder_path, f.file_type,
                       f.file_size_bytes, f.line_count, f.last_modified,
                       (SELECT COUNT(*) FROM wiki_functions WHERE file_id = f.id) as function_count,
                       (SELECT COUNT(*) FROM wiki_dependencies WHERE file_id = f.id) as dependency_count
                FROM wiki_files f
                WHERE f.scan_id = $scanId
                AND (f.folder_path = ? OR f.folder_path LIKE ?)
                ORDER BY f.folder_path, f.file_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$folder, $folder . '/%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "  OK - returned " . count($rows) . " rows\n";
    } catch (Exception $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // ---- Test I: Full query WITH description but using CAST(description) ----
    echo "=== Test I: Full query with description column ===\n";
    try {
        $sql = "SELECT f.id, f.file_path, f.file_name, f.folder_path, f.file_type,
                       f.file_size_bytes, f.line_count, f.last_modified,
                       f.description,
                       (SELECT COUNT(*) FROM wiki_functions WHERE file_id = f.id) as function_count,
                       (SELECT COUNT(*) FROM wiki_dependencies WHERE file_id = f.id) as dependency_count
                FROM wiki_files f
                WHERE f.scan_id = $scanId
                AND (f.folder_path = ? OR f.folder_path LIKE ?)
                ORDER BY f.folder_path, f.file_name";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$folder, $folder . '/%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "  OK - returned " . count($rows) . " rows\n";
    } catch (Exception $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";

    echo "=== DONE ===\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
}
?>
