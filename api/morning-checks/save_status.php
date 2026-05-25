<?php
/**
 * API: Save (insert or update) a morning-check status.
 *
 * POST body:
 *   {
 *     statusId:        int | null      // null = insert
 *     label:           string,
 *     colour:          string  (hex like #28a745),
 *     requiresNotes:   bool,
 *     isActive:        bool,
 *     sortOrder?:      int     // optional; defaults to end-of-list on insert
 *   }
 *
 * Note: results normalised to StatusID don't need a label-cascade on
 * rename — the JOIN at read time gives the current label automatically.
 * Orphan rows (StatusID NULL, Status string set) are left alone; the
 * admin remaps them explicitly via normalise_status.php.
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
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $statusId      = isset($input['statusId']) && $input['statusId'] !== null ? (int)$input['statusId'] : null;
    $label         = isset($input['label']) ? trim((string)$input['label']) : '';
    $colour        = isset($input['colour']) ? trim((string)$input['colour']) : '';
    $requiresNotes = !empty($input['requiresNotes']) ? 1 : 0;
    $isActive      = isset($input['isActive']) ? (!empty($input['isActive']) ? 1 : 0) : 1;
    $sortOrder     = isset($input['sortOrder']) ? (int)$input['sortOrder'] : null;

    if ($label === '')                              throw new Exception('Label is required');
    if (mb_strlen($label) > 50)                     throw new Exception('Label too long (max 50 chars)');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) throw new Exception('Colour must be a #rrggbb hex value');

    $conn = connectToDatabase();

    if ($statusId === null) {
        // Insert. If no sort order supplied, put it at the end.
        if ($sortOrder === null) {
            $sortOrder = (int)$conn->query("SELECT COALESCE(MAX(SortOrder), 0) + 10 FROM morningChecks_Statuses")->fetchColumn();
        }
        $stmt = $conn->prepare(
            "INSERT INTO morningChecks_Statuses
                (Label, Colour, RequiresNotes, SortOrder, IsActive, CreatedDate, ModifiedDate)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmt->execute([$label, $colour, $requiresNotes, $sortOrder, $isActive]);
        echo json_encode(['success' => true, 'statusId' => (int)$conn->lastInsertId()]);
        exit;
    }

    // Verify the row exists; sort order defaults to the existing value
    // if the caller didn't provide one.
    if ($sortOrder === null) {
        $sortStmt = $conn->prepare("SELECT SortOrder FROM morningChecks_Statuses WHERE StatusID = ?");
        $sortStmt->execute([$statusId]);
        $sortRow = $sortStmt->fetchColumn();
        if ($sortRow === false) throw new Exception('Status not found');
        $sortOrder = (int)$sortRow;
    }

    $stmt = $conn->prepare(
        "UPDATE morningChecks_Statuses
         SET Label = ?, Colour = ?, RequiresNotes = ?, SortOrder = ?, IsActive = ?, ModifiedDate = UTC_TIMESTAMP()
         WHERE StatusID = ?"
    );
    $stmt->execute([$label, $colour, $requiresNotes, $sortOrder, $isActive, $statusId]);

    echo json_encode(['success' => true, 'statusId' => $statusId]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
