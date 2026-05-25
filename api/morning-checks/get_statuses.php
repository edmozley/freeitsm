<?php
/**
 * API: Get all configured morning-check statuses.
 *
 * Returns the full list (active and inactive) ordered by SortOrder so
 * the settings page can show everything, and the dashboard can resolve
 * a historical result's label/colour even if the status has since been
 * deactivated. Dashboard then filters to IsActive=1 for the buttons.
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

    $stmt = $conn->query(
        "SELECT StatusID, Label, Colour, RequiresNotes, SortOrder, IsActive
         FROM morningChecks_Statuses
         ORDER BY SortOrder, StatusID"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statuses = [];
    foreach ($rows as $r) {
        $statuses[] = [
            'StatusID'       => (int)$r['StatusID'],
            'Label'          => $r['Label'],
            'Colour'         => $r['Colour'],
            'RequiresNotes'  => (bool)$r['RequiresNotes'],
            'SortOrder'      => (int)$r['SortOrder'],
            'IsActive'       => (bool)$r['IsActive'],
        ];
    }

    echo json_encode(['success' => true, 'statuses' => $statuses]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
