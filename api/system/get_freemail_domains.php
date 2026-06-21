<?php
/**
 * API: List public / free-email domains.
 * GET — returns the built-in list (always-on, read-only) and the admin-added
 * custom list ([{ id, domain }], removable). See includes/tenancy.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $custom = [];
    try {
        $stmt = $conn->query("SELECT id, domain FROM freemail_domains ORDER BY domain");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $custom[] = ['id' => (int)$row['id'], 'domain' => $row['domain']];
        }
    } catch (Exception $e) {
        $custom = []; // table not created yet
    }

    $builtin = freemailBuiltinDomains();
    sort($builtin);

    echo json_encode(['success' => true, 'builtin' => $builtin, 'custom' => $custom]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
