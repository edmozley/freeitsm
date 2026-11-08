<?php
/**
 * API Endpoint: Unlink a portal user from their sign-in provider
 *
 * POST JSON { id }
 *
 * Removes both halves of the link: `users.auth_provider_id` (which provider the
 * email-first router sends them to, and which one strict isolation lets in) and
 * their `user_sso_identities` rows (the provider's id for them). Afterwards they
 * are back where a first-time requester starts: their next sign-in through a
 * provider links them again by verified email, and a password works if they
 * have one. Tickets and details are untouched.
 *
 * Refused for a person a directory import keeps up to date (`is_managed`): the
 * next sync would re-attach them, so unlinking here would only appear to work.
 * Written to system_logs as 'signin_unlinked'.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/users.php';   // userManagedSql()

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

    // Same rule and same wording as delete_user.php: nothing about a person the
    // caller cannot reach, not even whether they exist.
    if (!analystCanAccessUser($conn, (int)$_SESSION['analyst_id'], $id)) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    $st = $conn->prepare(
        "SELECT u.email, " . userManagedSql() . " AS is_managed, u.auth_provider_id, p.display_name AS provider_name,
                (SELECT COUNT(*) FROM user_sso_identities i WHERE i.user_id = u.id) AS identities
           FROM users u
      LEFT JOIN auth_providers p ON p.id = u.auth_provider_id
          WHERE u.id = ?"
    );
    $st->execute([$id]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    if ((int)$user['is_managed'] === 1) {
        echo json_encode(['success' => false, 'error' => 'This person is kept up to date from '
            . ($user['provider_name'] ?: 'a directory') . ', which would link them again on its next sync. Remove them there instead.']);
        exit;
    }
    if ($user['auth_provider_id'] === null && (int)$user['identities'] === 0) {
        echo json_encode(['success' => false, 'error' => 'This person is not linked to a sign-in provider.']);
        exit;
    }

    $conn->beginTransaction();
    $del = $conn->prepare("DELETE FROM user_sso_identities WHERE user_id = ?");
    $del->execute([$id]);
    $conn->prepare("UPDATE users SET auth_provider_id = NULL WHERE id = ?")->execute([$id]);
    $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES ('signin_unlinked', ?, ?, UTC_TIMESTAMP())")
         ->execute([(int)$_SESSION['analyst_id'], json_encode([
             'user_id'            => $id,
             'email'              => $user['email'],
             'provider_id'        => $user['auth_provider_id'] !== null ? (int)$user['auth_provider_id'] : null,
             'provider_name'      => $user['provider_name'],
             'identities_removed' => $del->rowCount(),
         ])]);
    $conn->commit();

    echo json_encode(['success' => true, 'provider_name' => $user['provider_name']]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
