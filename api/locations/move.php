<?php
/**
 * API: move everything that points at one location to another, or clear it (GH #311).
 * POST {from: N, to: M | null}
 *
 * System -> Maintenance -> Locations in use. Both locations must be ones this
 * analyst may manage (recordLocationManageError()); each record is checked
 * against its own company, and one the new location is not open to is left
 * as it was and counted as skipped (recordLocationMove()).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/record_locations.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

locationsRequireAccessJson(true);

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $from = (int)($data['from'] ?? 0);
    $to = isset($data['to']) && $data['to'] !== '' && $data['to'] !== null ? (int)$data['to'] : null;
    if ($from < 1 || recordLocationPath($conn, $from) === null) {
        echo json_encode(['success' => false, 'error' => 'Location not found']);
        exit;
    }
    if ($to !== null && ($to === $from || recordLocationPath($conn, $to) === null)) {
        echo json_encode(['success' => false, 'error' => 'Choose another location to move them to.']);
        exit;
    }
    foreach (array_filter([$from, $to]) as $loc) {
        $reason = recordLocationManageError($conn, $analystId, $loc);
        if ($reason !== null) {
            echo json_encode(['success' => false, 'error' => $reason]);
            exit;
        }
    }
    $res = recordLocationMove($conn, $analystId, $from, $to);
    echo json_encode(['success' => true] + $res + ['counts' => recordLocationUsage($conn, $from)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
