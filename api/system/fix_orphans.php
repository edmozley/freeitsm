<?php
/**
 * API Endpoint: Fix orphaned child rows that block a ticket-child foreign key.
 *
 * "Orphans" are rows whose parent has already been deleted — e.g.
 * email_attachments left behind when an email was removed but its attachment
 * rows weren't. They are unreachable dead data and are exactly what MySQL
 * rejects when adding the foreign key. The Database Verify page surfaces them
 * with a Fix button that calls this endpoint, then re-runs verification (which
 * then succeeds in adding the FK).
 *
 * Strictly whitelisted: only the four known ticket-child tables, and only rows
 * with no matching parent are ever deleted.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// table => [parent table, child FK column, has files on disk]
$WHITELIST = [
    'email_attachments'   => ['emails',  'email_id',  true],
    'ticket_notes'        => ['tickets', 'ticket_id', false],
    'ticket_audit'        => ['tickets', 'ticket_id', false],
    'ticket_time_entries' => ['tickets', 'ticket_id', false],
];

$data  = json_decode(file_get_contents('php://input'), true);
$table = $data['table'] ?? '';

if (!isset($WHITELIST[$table])) {
    echo json_encode(['success' => false, 'error' => 'Unknown or non-fixable table']);
    exit;
}

[$parent, $col, $hasFiles] = $WHITELIST[$table];

try {
    $conn = connectToDatabase();

    // For attachments, grab the file paths of the orphans first so we can remove
    // the physical files after the rows are gone.
    $orphanFiles = [];
    if ($hasFiles) {
        $stmt = $conn->prepare(
            "SELECT c.file_path FROM `$table` c
             LEFT JOIN `$parent` p ON p.id = c.`$col`
             WHERE p.id IS NULL AND c.file_path IS NOT NULL"
        );
        $stmt->execute();
        $orphanFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Delete only rows whose parent is missing.
    $del = $conn->prepare(
        "DELETE c FROM `$table` c
         LEFT JOIN `$parent` p ON p.id = c.`$col`
         WHERE p.id IS NULL"
    );
    $del->execute();
    $deleted = $del->rowCount();

    // Best-effort removal of the now-orphaned attachment files.
    $filesRemoved = 0;
    if ($hasFiles && $deleted > 0) {
        $base = dirname(dirname(__DIR__)) . '/tickets/attachments/';
        foreach ($orphanFiles as $rel) {
            $full = $base . $rel;
            if (is_file($full) && @unlink($full)) {
                $filesRemoved++;
            }
        }
    }

    echo json_encode([
        'success'       => true,
        'table'         => $table,
        'deleted'       => $deleted,
        'files_removed' => $filesRemoved,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
