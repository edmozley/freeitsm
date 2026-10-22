<?php
/**
 * API Endpoint: Get list of emails
 * Returns emails from the database for display in inbox
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_snooze.php';
require_once '../../includes/ticket_list.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    [$sql, $params, $snoozeReady] = ticketListBase($conn, (int)$_SESSION['analyst_id'], $_GET);

    // The Snoozed folder sorts by when each ticket comes back — the only order
    // that answers the question you open that folder to ask ("what's next?").
    $sql .= (!empty($_GET['snoozed']) && $snoozeReady)
        ? " ORDER BY t.snoozed_until ASC"
        : " ORDER BY COALESCE(le.received_datetime, t.created_datetime) DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $emails = ticketListFormat($emails);

    echo json_encode([
        'success' => true,
        'emails' => $emails,
        'count' => count($emails),
        // The consolidated view (#1554). Told once per fetch rather than inferred
        // by the front end from whether the rows happen to span companies — a
        // filtered page can easily contain one company's tickets while the view
        // is still "all", and the column must not vanish when it does.
        'all_companies' => isActiveTenantAll($conn)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
