<?php
/**
 * API Endpoint: Get Today's Morning Checks with Results
 *
 * Normalised: JOINs morningChecks_Results to morningChecks_Statuses
 * so the response carries the StatusID + Label + Colour + RequiresNotes
 * already. Orphan rows (StatusID NULL but Status string set, e.g. from
 * a since-deleted status) come back with StatusID = null and the
 * orphan label string in Status — the dashboard surfaces those in a
 * warning banner and offers a normalisation tool in Settings.
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
    $checkDate = $_GET['date'] ?? date('Y-m-d');

    $dateObj = DateTime::createFromFormat('Y-m-d', $checkDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $checkDate) {
        $checkDate = date('Y-m-d');
    }

    $conn = connectToDatabase();

    $sql = "SELECT c.CheckID, c.CheckName, c.CheckDescription, c.SortOrder,
                   r.StatusID, r.Status AS OrphanLabel,
                   s.Label AS StatusLabel, s.Colour AS StatusColour,
                   s.RequiresNotes AS StatusRequiresNotes,
                   r.Notes
            FROM morningChecks_Checks c
            LEFT JOIN morningChecks_Results r
                ON c.CheckID = r.CheckID AND r.CheckDate = ?
            LEFT JOIN morningChecks_Statuses s
                ON r.StatusID = s.StatusID
            WHERE c.IsActive = 1
            ORDER BY c.SortOrder, c.CheckName";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$checkDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $checks = [];
    foreach ($rows as $r) {
        // Resolve the effective label / colour. For normalised rows
        // the JOIN gives us Label/Colour. For orphans (StatusID NULL
        // but OrphanLabel set) the dashboard falls back to the label
        // string with no colour (renders as grey unmapped status).
        $statusId       = $r['StatusID'] !== null ? (int)$r['StatusID'] : null;
        $statusLabel    = $r['StatusLabel'] ?: $r['OrphanLabel'];
        $statusColour   = $r['StatusColour'];
        $requiresNotes  = $r['StatusRequiresNotes'] !== null ? (bool)$r['StatusRequiresNotes'] : null;
        $isOrphan       = ($statusId === null && $statusLabel !== null && $statusLabel !== '');

        $checks[] = [
            'CheckID'              => (int)$r['CheckID'],
            'CheckName'            => $r['CheckName'],
            'CheckDescription'     => $r['CheckDescription'],
            'SortOrder'            => (int)$r['SortOrder'],
            'StatusID'             => $statusId,
            'Status'               => $statusLabel,        // effective label (joined or orphan)
            'StatusColour'         => $statusColour,
            'StatusRequiresNotes'  => $requiresNotes,
            'IsOrphan'             => $isOrphan,
            'Notes'                => $r['Notes'],
        ];
    }

    echo json_encode($checks);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
