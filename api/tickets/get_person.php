<?php
/**
 * API: one person (a `users` row - a requester, not an analyst), complete, for
 * the shared person editor (includes/person_editor.php).
 * GET ?id=<users.id>
 *
 * WHY IT EXISTS
 * -------------
 * Tickets -> Users and Assets -> Users each had their own editor, fed from their
 * own list endpoint (get_users.php and api/assets/get_people.php), and the two
 * lists are different shapes. One editor needs one source of truth for the
 * record it is about to change, rather than trusting whichever list the page it
 * happens to be on has loaded - so it reads the person here.
 *
 * Reached from BOTH modules, so either grants it; the subject check is the real
 * guard. A refusal reads exactly like an id that does not exist.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/users.php';   // userDirectoryOwnedFields()
require_once '../../includes/cost_centre_assign.php';   // GH #160

header('Content-Type: application/json');
requireAnyModuleAccessJson(['tickets', 'assets']);

$id = (int)($_GET['id'] ?? 0);

try {
    $conn = connectToDatabase();
    if ($id <= 0 || !analystCanAccessUser($conn, (int)$_SESSION['analyst_id'], $id)) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT u.id, u.email, u.username, u.display_name, u.preferred_name,
                u.tenant_id, u.job_title, u.department, u.office, u.phone, u.mobile,
                u.employee_id, u.manager_id, u.is_active,
                -- Managed only while the source exists (userManagedSql(), #261).
                " . userManagedSql() . " AS is_managed
                " . costCentreSelectSql($conn, 'users', 'u') . ",
                ap.protocol AS managed_protocol, ap.carddav_write_back AS managed_write_back,
                ap.display_name AS source_name
           FROM users u
      LEFT JOIN auth_providers ap ON ap.id = u.auth_provider_id
          WHERE u.id = ?"
    );
    $stmt->execute([$id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    // Which fields a directory owns for THIS person - per record, because it
    // depends on the source (LDAP owns all seven; an address book five; one with
    // write-back none). Same resolver save_user.php refuses against.
    $u['managed_fields'] = ((int)($u['is_managed'] ?? 0) === 1)
        ? array_values(userDirectoryOwnedFields($u['managed_protocol'] ?? null, (int)($u['managed_write_back'] ?? 0) === 1))
        : [];
    $u['is_active'] = (int)($u['is_active'] ?? 1) === 1;

    // The manager's name, for the editor's type-ahead - ONLY when this analyst
    // may see that person. Otherwise null, and the editor keeps the manager
    // without showing (or sending) who it is.
    $u['manager_name'] = null;
    if (!empty($u['manager_id']) && analystCanAccessUser($conn, (int)$_SESSION['analyst_id'], (int)$u['manager_id'])) {
        $mn = $conn->prepare("SELECT COALESCE(NULLIF(display_name, ''), NULLIF(email, ''), username) FROM users WHERE id = ?");
        $mn->execute([(int)$u['manager_id']]);
        $u['manager_name'] = $mn->fetchColumn() ?: null;
    }

    echo json_encode(['success' => true, 'user' => $u]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Could not load the person']);
}
