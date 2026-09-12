<?php
/**
 * API: Get self-service user profile
 *
 * GET - display name, email, preferred name, and the contact details a person
 * may maintain about themselves (USER_SELF_EDITABLE_FIELDS), plus whether a
 * directory owns the record.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/users.php';   // USER_SELF_EDITABLE_FIELDS

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Columns are named from the constant rather than listed, so a field added
    // to USER_SELF_EDITABLE_FIELDS arrives here without a second edit. Safe to
    // interpolate: the constant is source code, never request input.
    $personCols = implode(', ', USER_SELF_EDITABLE_FIELDS);

    $stmt = $conn->prepare(
        "SELECT email, display_name, preferred_name, is_managed, $personCols
           FROM users WHERE id = ?"
    );
    $stmt->execute([$_SESSION['ss_user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // ⚠️ A missing row must not be reported as a person with every field blank.
    // The session outlives the record if an administrator deletes the account
    // mid-session, and rendering that as an empty, editable form would invite
    // the user to Save it — writing their guesses onto nothing, or onto a
    // recreated id. Say it plainly instead.
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Account not found']);
        exit;
    }

    $out = [
        'success'        => true,
        'email'          => $user['email'],
        'display_name'   => $user['display_name'],
        'preferred_name' => $user['preferred_name'] ?? '',
        // Drives whether the contact fields render read-only. Sent as a real
        // boolean: the front end must not have to guess whether "0" is false.
        'is_managed'     => (int)$user['is_managed'] === 1,
        // The names themselves, so the page can build its own form from the
        // server's list instead of carrying a second copy that can drift.
        'fields'         => array_values(USER_SELF_EDITABLE_FIELDS),
    ];
    foreach (USER_SELF_EDITABLE_FIELDS as $f) {
        $out[$f] = $user[$f] ?? '';
    }
    echo json_encode($out);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
