<?php
/**
 * API Endpoint: Delete an end user
 *
 * Refuses to delete if the user is referenced by any tickets or asset assignments,
 * so analyst-visible history is never silently broken - or if a directory import
 * manages them (is_managed), which would only create them again.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? (int)$data['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'User id is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // ⚠️ THIS CHECK MUST STAY ABOVE THE TWO COUNTS BELOW.
    //
    // This endpoint had no company check of any kind: any signed-in analyst with the
    // tickets module could delete any customer contact on the install, including one
    // belonging to a company they cannot see. It is the sibling of the save_user.php
    // hole (S1) — same table, same module, opposite verb — and was reported by Erlend
    // Volden alongside it.
    //
    // Position is half the fix. The two "cannot delete, this user is on N ticket(s)"
    // refusals below are computed from a plain `WHERE user_id = ?` with no tenancy
    // clause, so running them first would answer "does contact 412 exist, and how much
    // work is attached to them?" for every company on the install, and answer it
    // accurately, before refusing. That is a cross-tenant oracle whether or not the
    // DELETE is ever reached. Nothing may be measured about a contact the caller
    // cannot reach.
    //
    // The refusal deliberately matches the one for an id that does not exist, exactly
    // as save_user.php's edit guard does: a scoped analyst must not be able to tell
    // "not yours" from "not there".
    if (!analystCanAccessUser($conn, (int)$_SESSION['analyst_id'], $id)) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    // A person a directory import keeps up to date is never deleted here: the
    // next import would only create them again. See userDeleteBlockedByDirectory().
    require_once '../../includes/users.php';
    if ($blocked = userDeleteBlockedByDirectory($conn, $id)) {
        echo json_encode(['success' => false, 'error' => $blocked]);
        exit;
    }

    // Somebody with tickets or equipment is never deleted: the same rule the
    // directory maintenance tools follow (userDeleteBlockedByHistory()).
    if ($blocked = userDeleteBlockedByHistory($conn, $id)) {
        echo json_encode(['success' => false, 'error' => $blocked]);
        exit;
    }

    // With what is theirs, and letting go of them where a record outlives them -
    // by hand, because a verified install has no foreign keys (userDeletePerson()).
    userDeletePerson($conn, $id);

    echo json_encode(['success' => true, 'message' => 'User deleted']);

} catch (Exception $e) {
    // Never the exception's own text: it can carry SQL and column names.
    error_log('delete_user: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The person could not be deleted.']);
}
