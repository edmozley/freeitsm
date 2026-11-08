<?php
/**
 * API: Get the mailboxes this self-service user may raise a ticket through
 * GET - Returns mailbox id, name, and email address only
 *
 * Their own company's plus shared ones on a multi-company install (GH #172) -
 * see includes/self_service_mailboxes.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/self_service_mailboxes.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $mailboxes = array_map(function ($m) {
        return ['id' => $m['id'], 'name' => $m['name'], 'target_mailbox' => $m['target_mailbox']];
    }, array_values(selfServiceMailboxes($conn, (int)$_SESSION['ss_user_id'],
        // GH #228: the "Raise with" company; re-checked inside.
        !empty($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null)));

    echo json_encode(['success' => true, 'mailboxes' => $mailboxes]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
