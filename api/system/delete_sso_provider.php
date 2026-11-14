<?php
/**
 * API: Delete an SSO / OIDC identity provider.
 * POST JSON { id, people: 'keep' | 'left' }
 *
 * The people a source imported stay, as ordinary people - or as leavers, with
 * people: 'left' - and nothing points at the source any more: see
 * DirectoryMaintenance::releaseSource() (issue #261).
 *
 * All or nothing, in one transaction. Everything that names it goes with it - sign-in links, group mappings, imported
 * groups, the CardDAV write log - removed here rather than left to foreign keys a
 * verified install does not have; analysts and teams that signed in with it are
 * reset. ?action=preview_delete on api/system/directory_maintenance.php lists all
 * of it first.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/services/directory_maintenance.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? (int)$data['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing provider id']);
    exit;
}

try {
    $conn = connectToDatabase();
    $actor = ActorContext::fromSession($conn);

    // ALL OR NOTHING (#261): the people, the links and the source go together, or
    // none of them does. The people first, while the row still says who they
    // belong to: kept as ordinary people, or marked as left when asked, never
    // left half-managed - and refused, before anything changes, for an analyst
    // who cannot access every company its people are in.
    $conn->beginTransaction();

    $name = $conn->prepare("SELECT display_name FROM auth_providers WHERE id = ?");
    $name->execute([$id]);
    $released = DirectoryMaintenance::releaseSource($conn, $actor, $id,
        (string)$name->fetchColumn(), ($data['people'] ?? 'keep') === 'left');

    // ⚠️ Delete the write log EXPLICITLY rather than relying on the foreign key.
    // `carddav_write_log` declares ON DELETE CASCADE in database/freeitsm.sql, but
    // Database Verify creates tables from a column list and adds no constraints —
    // so on any install where that table was created by a verify rather than by
    // the schema file, there is no cascade and the rows are simply orphaned.
    // Verified on this dev box: `directory_sync_entries` has the same 0 foreign
    // keys for the same reason, so this is a general property of verified tables
    // and not something peculiar to CardDAV.
    //
    // 🔑 Doing it here is correct EITHER WAY: with the constraint present the rows
    // are already gone and this deletes nothing, and without it they go now.
    //
    // Each cleanup tolerates a table this install has not got (an install that
    // predates it): a failed statement inside a transaction undoes only itself.
    // Same reasoning for groups imported from Entra, members first; and for the
    // sign-in links, group -> team mappings and the groups seen at sign-in - the
    // preview (#261) says they go.
    foreach (["DELETE FROM carddav_write_log WHERE provider_id = ?",
              "DELETE m FROM directory_group_members m JOIN directory_groups g ON g.id = m.group_id WHERE g.provider_id = ?",
              "DELETE FROM directory_groups WHERE provider_id = ?",
              "DELETE FROM user_sso_identities WHERE provider_id = ?",
              "DELETE FROM analyst_sso_identities WHERE provider_id = ?",
              "DELETE FROM sso_group_teams WHERE provider_id = ?",
              "DELETE FROM sso_groups_seen WHERE provider_id = ?"] as $sql) {
        try {
            $conn->prepare($sql)->execute([$id]);
        } catch (PDOException $e) {
            if (!in_array($e->getCode(), ['42S02', '42S22'], true)) throw $e;
            error_log('[sso] cleanup skipped: ' . $e->getMessage());
        }
    }

    $stmt = $conn->prepare("DELETE FROM auth_providers WHERE id = ?");
    $stmt->execute([$id]);

    // The same reasoning as the write log above, for the two places that name a
    // sign-in method. With the foreign key these find nothing left to do; without
    // it, an analyst pinned here kept a dangling id - and a dangling OIDC-looking
    // pin meant the password form refused them and no SSO button matched either.
    $conn->prepare("UPDATE analysts SET auth_provider_id = NULL WHERE auth_provider_id = ?")->execute([$id]);
    try {
        $conn->prepare("UPDATE teams SET auth_method = NULL, auth_provider_id = NULL WHERE auth_method = 'provider' AND auth_provider_id = ?")->execute([$id]);
        // A team that pointed here now sets nothing, which can settle a disagreement
        // between someone's teams (GH #41).
        require_once __DIR__ . '/../../includes/analyst_signin.php';
        analystSignInApplyMany($conn, analystSignInFollowerIds($conn));
    } catch (PDOException $e) {
        // An install whose Database Verification has not added the team columns yet.
        if (!in_array($e->getCode(), ['42S02', '42S22'], true)) throw $e;
        error_log('[sso] team sign-in cleanup skipped: ' . $e->getMessage());
    }
    $conn->commit();
    echo json_encode(['success' => true, 'released' => $released]);
} catch (ServiceError $e) {
    // Refused before anything changed (the company check): its own words.
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    // Never the exception's own text: it can carry SQL and column names.
    error_log('delete_sso_provider: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The provider could not be deleted.']);
}
