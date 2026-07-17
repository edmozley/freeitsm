<?php
/**
 * API Endpoint: Describe a single table (READ-ONLY).
 *
 * Powers the detail panel on the DB Verify page: given ?table=xxx, returns the
 * table's LIVE structure as it exists right now — columns, indexes and foreign
 * keys — read straight from information_schema. It mutates nothing and is fully
 * decoupled from db_verify.php's schema-creation logic, so it can never mis-fire
 * a migration; it just reports what the database currently holds.
 *
 * Admin-only: it discloses the full schema of a table, so it sits behind the
 * same gate as the rest of the System module (a read that exposes structure,
 * not a normal ticket-flow read).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

require_once '../../includes/admin_api_guard.php';   // administrators only

$table = trim($_GET['table'] ?? '');

// The table name goes into information_schema lookups as a bound parameter, but
// keep it to a strict identifier shape anyway — defence in depth, and it rejects
// junk early with a clear message.
if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'A valid table name is required']);
    exit;
}

try {
    $conn = connectToDatabase();
    $dbName = DB_NAME;

    // Confirm the table exists before describing it.
    $exists = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?");
    $exists->execute([$dbName, $table]);
    if ((int)$exists->fetchColumn() === 0) {
        echo json_encode(['success' => false, 'error' => 'Table not found in the database', 'missing' => true]);
        exit;
    }

    // --- Columns ---
    // Alias every column to a lowercase name: information_schema returns column
    // names UPPERCASE on some MySQL builds and lowercase on others, so aliasing
    // makes the read casing-independent.
    $colStmt = $conn->prepare(
        "SELECT column_name AS column_name, column_type AS column_type,
                is_nullable AS is_nullable, column_key AS column_key,
                column_default AS column_default, extra AS extra
           FROM information_schema.columns
          WHERE table_schema = ? AND table_name = ?
          ORDER BY ordinal_position"
    );
    $colStmt->execute([$dbName, $table]);
    $columns = [];
    foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $columns[] = [
            'name'     => $r['column_name'],
            'type'     => $r['column_type'],
            'nullable' => strtoupper($r['is_nullable']) === 'YES',
            'key'      => $r['column_key'],                 // PRI / UNI / MUL / ''
            'default'  => $r['column_default'],
            'extra'    => $r['extra'],                      // auto_increment, etc.
        ];
    }

    // --- Indexes (grouped from statistics; keep column order via seq_in_index) ---
    $idxStmt = $conn->prepare(
        "SELECT index_name AS index_name, non_unique AS non_unique,
                seq_in_index AS seq_in_index, column_name AS column_name
           FROM information_schema.statistics
          WHERE table_schema = ? AND table_name = ?
          ORDER BY index_name, seq_in_index"
    );
    $idxStmt->execute([$dbName, $table]);
    $idxMap = [];
    foreach ($idxStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = $r['index_name'];
        if (!isset($idxMap[$name])) {
            $idxMap[$name] = [
                'name'    => $name,
                'unique'  => (int)$r['non_unique'] === 0,
                'primary' => $name === 'PRIMARY',
                'columns' => [],
            ];
        }
        $idxMap[$name]['columns'][] = $r['column_name'];
    }
    $indexes = array_values($idxMap);

    // --- Foreign keys (join key_column_usage to referential_constraints for the
    //     ON DELETE / ON UPDATE rules) ---
    $fkStmt = $conn->prepare(
        "SELECT k.constraint_name AS constraint_name, k.column_name AS column_name,
                k.referenced_table_name AS referenced_table_name,
                k.referenced_column_name AS referenced_column_name,
                r.delete_rule AS delete_rule, r.update_rule AS update_rule
           FROM information_schema.key_column_usage k
           JOIN information_schema.referential_constraints r
             ON r.constraint_schema = k.table_schema
            AND r.constraint_name   = k.constraint_name
          WHERE k.table_schema = ? AND k.table_name = ?
            AND k.referenced_table_name IS NOT NULL
          ORDER BY k.constraint_name, k.ordinal_position"
    );
    $fkStmt->execute([$dbName, $table]);
    $foreignKeys = [];
    foreach ($fkStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $foreignKeys[] = [
            'name'       => $r['constraint_name'],
            'column'     => $r['column_name'],
            'references' => $r['referenced_table_name'] . '(' . $r['referenced_column_name'] . ')',
            'refTable'   => $r['referenced_table_name'],
            'onDelete'   => $r['delete_rule'],
            'onUpdate'   => $r['update_rule'],
        ];
    }

    // Approximate row count (fast, from table metadata — exact isn't worth a COUNT(*)).
    $rowStmt = $conn->prepare("SELECT table_rows AS table_rows FROM information_schema.tables WHERE table_schema = ? AND table_name = ?");
    $rowStmt->execute([$dbName, $table]);
    $approxRows = (int)$rowStmt->fetchColumn();

    echo json_encode([
        'success'      => true,
        'table'        => $table,
        'columns'      => $columns,
        'indexes'      => $indexes,
        'foreign_keys' => $foreignKeys,
        'approx_rows'  => $approxRows,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
