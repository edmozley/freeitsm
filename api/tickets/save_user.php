<?php
/**
 * API Endpoint: Save (create or update) an end user
 *
 * Password is optional. Leaving it blank on create means the user is "passwordless" —
 * the same state inbound-ticket users start in. They can later claim the account via
 * the self-service portal's register flow by setting their own password.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/users.php';   // USER_PERSON_FIELDS, manager-loop guard

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$id            = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;
$email         = strtolower(trim($data['email'] ?? ''));
$displayName   = trim($data['display_name'] ?? '');
$preferredName = trim($data['preferred_name'] ?? '');
$password      = $data['password'] ?? '';

// Company. An absent key means "don't touch it" (on update) or "work it out from
// the address" (on create); a present-but-empty one means the admin deliberately
// chose no company, i.e. this person's tickets go to triage.
$tenantSent    = array_key_exists('tenant_id', $data);
$tenantId      = ($tenantSent && $data['tenant_id'] !== '' && $data['tenant_id'] !== null)
    ? (int)$data['tenant_id'] : null;

// An address is now OPTIONAL: staff who sign in through a directory may have
// no mailbox (GitHub #47), and their sign-in name identifies them instead.
// Supplying a malformed one is still an error — blank and wrong are different.
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address']);
    exit;
}

// Absent means NULL, never '': '' occupies the unique index, so the SECOND
// person saved without an address would be refused as a duplicate.
$emailOrNull = $email !== '' ? $email : null;

if ($password !== '' && strlen($password) < 8) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Email must be unique within users, and must not collide with an analyst account
    // Both checks are skipped when there is no address — otherwise every
    // mailbox-less person would collide with every other one.
    if ($emailOrNull !== null) {
        $analystStmt = $conn->prepare("SELECT id FROM analysts WHERE LOWER(email) = ?");
        $analystStmt->execute([$email]);
        if ($analystStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'This email belongs to an analyst account']);
            exit;
        }

        $dupeStmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND id != ?");
        $dupeStmt->execute([$email, $id ?? 0]);
        if ($dupeStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'A user with this email already exists']);
            exit;
        }
    }

    // Where this save will ACTUALLY file the person. For a new person that is not
    // necessarily what the request said: with no tenant_id in the body at all we fall
    // back to resolveTenantForNewUser(), which maps the email domain to a company —
    // and that resolved value is the one the check below has to test.
    //
    // ⚠️ This used to read `if ($tenantSent && …)`, which meant the guard was skipped
    // entirely whenever tenant_id was simply left out. On the create path that was a
    // privilege escalation, not a gap: POST {"email":"x@companyB.com","password":"…"}
    // from an analyst scoped to company A created a portal account owned by B, with a
    // password of the attacker's choosing, that they could then sign in as. Reported by
    // Erlend Volden against the first round of these fixes — the same bug the F9 edit
    // check closed, one branch over.
    //
    // Editing with no tenant_id means "leave the company alone", so there is no
    // destination to check; creating with none means "work it out", so there is.
    $destinationTenantId = $tenantSent
        ? $tenantId
        : ($id ? null : resolveTenantForNewUser($conn, $email));

    // You can only file someone into a company you yourself can reach. This binds
    // every analyst including all-access ones: reaching both companies is not a
    // licence to move people between them by hand-crafting a request.
    //
    // ⚠️ The isMultiTenant() short-circuit is load-bearing, and matches what
    // analystCanAccessTicket()/analystCanAccessUser() do on their first line. On a
    // single-company install an ordinary analyst has no rows in analyst_tenant_access,
    // so getAccessibleTenantIds() returns [] and analystCanAccessTenant() says no to
    // everything — without this line, creating any requester at all would be refused
    // on every single-tenant install. Every other caller of that function is an
    // inherently multi-company action ("move to company", "set active company"), which
    // is why none of them needed it and this one does.
    if (isMultiTenant($conn)
        && $destinationTenantId !== null
        && !analystCanAccessTenant($conn, (int)$_SESSION['analyst_id'], $destinationTenantId)) {
        echo json_encode(['success' => false, 'error' => 'You do not have access to that company']);
        exit;
    }

    // ⚠️ ...and you can only edit someone you can already reach. The check above
    // guards where the account is going; without this one, nothing guarded where it
    // was. An analyst scoped to one company could set the email address and password
    // on another company's portal account and then sign in as them. Guarding the
    // destination alone guards nothing.
    if ($id && !analystCanAccessUser($conn, (int)$_SESSION['analyst_id'], $id)) {
        // Same answer as an id that does not exist: a scoped analyst learns nothing
        // about another company's account either way.
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    // Is this record owned by a directory? Decides whether the directory-owned
    // person fields may be edited at all. Read once, here, so the update block
    // below does not have to go back to the database per field.
    $isManaged = false;
    if ($id) {
        $mStmt = $conn->prepare("SELECT is_managed FROM users WHERE id = ?");
        $mStmt->execute([$id]);
        $isManaged = (int)($mStmt->fetchColumn() ?: 0) === 1;
    }

    // A manager chain that loops would make any code walking it to find an
    // approver walk forever. The database cannot express this, so it is checked
    // here — on create too, where $id is 0 and only a self-reference is possible.
    if (array_key_exists('manager_id', $data)) {
        $mgr = userPersonFieldValue('manager_id', $data['manager_id']);
        if ($mgr !== null) {
            // 🔴 The manager must be somebody THIS ANALYST CAN ALREADY REACH.
            //
            // This used to be a bare `SELECT id FROM users WHERE id = ?`, which
            // proved existence across the whole install. Two problems, and the
            // second is the one that bites:
            //
            //  - An analyst scoped to one company could set any person's manager
            //    to anybody on the install, by id. The picker on the users
            //    screen only ever offers people in scope, but a dropdown is a
            //    convenience and never the guard.
            //  - The manager's display name is then rendered on the Assets
            //    people screen, so a write nobody could see turned into a read
            //    across a company boundary. Both halves are closed in this
            //    release; either alone would have left it exploitable.
            //
            // ⚠️ BOTH checks, not just the access one. analystCanAccessUser()
            // short-circuits to `true` on a single-company install, so relying
            // on it alone would drop the existence check for the majority of
            // installs — a bogus id would reach the database and come back as a
            // raw foreign-key error instead of a sentence.
            //
            // ⚠️ And both give the SAME answer. A distinct "that manager does
            // not exist" would be an enumeration oracle: post ids until the
            // message changes and you have learned which people exist in
            // companies you cannot see.
            $exists = $conn->prepare("SELECT id FROM users WHERE id = ?");
            $exists->execute([$mgr]);
            $mgrExists = (bool)$exists->fetch();
            if (!$mgrExists
                || !analystCanAccessUser($conn, (int)$_SESSION['analyst_id'], (int)$mgr)) {
                echo json_encode(['success' => false, 'error' => 'That manager does not exist']);
                exit;
            }
            if (!userManagerIsSafe($conn, (int)($id ?? 0), (int)$mgr)) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'That would make the reporting line loop back on itself',
                ]);
                exit;
            }
        }
    }

    if ($id) {
        // ⚠️ An UPDATE used to write email, display_name and preferred_name
        // unconditionally, so a request that simply did not mention a field WIPED it —
        // absent read as "" read as NULL. Sending {"id":143,"display_name":"..."} silently
        // deleted that person's email address, and for a portal account the address is
        // how they sign in, so it deleted their access too. tenant_id already got this
        // right with array_key_exists ("absent means don't touch"); the other three now
        // follow the same rule. Found by tripping over it while testing the F9 fix.
        $sets = [];
        $args = [];
        if (array_key_exists('email', $data))          { $sets[] = 'email = ?';          $args[] = $emailOrNull; }
        if (array_key_exists('display_name', $data))   { $sets[] = 'display_name = ?';   $args[] = $displayName ?: null; }
        if (array_key_exists('preferred_name', $data)) { $sets[] = 'preferred_name = ?'; $args[] = $preferredName ?: null; }
        if ($password !== '')                          { $sets[] = 'password_hash = ?';  $args[] = password_hash($password, PASSWORD_BCRYPT); }
        if ($tenantSent)                               { $sets[] = 'tenant_id = ?';      $args[] = $tenantId; }

        // The person fields, same "absent means don't touch" rule as above.
        // ⚠️ Directory-owned ones are refused outright on a MANAGED record rather
        // than accepted and then overwritten by the next sync — a save that
        // silently does nothing is worse than one that says no.
        foreach (USER_PERSON_FIELDS as $f) {
            if (!array_key_exists($f, $data)) continue;
            if ($isManaged && in_array($f, USER_DIRECTORY_OWNED, true)) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'This person is kept up to date from a directory, so ' . $f
                               . ' cannot be edited here. Change it in the directory instead.',
                ]);
                exit;
            }
            $sets[] = "$f = ?";
            $args[] = userPersonFieldValue($f, $data[$f]);
        }
        // Active/inactive. Stamps deactivated_datetime in the same statement so
        // "who left this month" is answerable without a second write to miss.
        if (array_key_exists('is_active', $data)) {
            $active = !empty($data['is_active']) ? 1 : 0;
            $sets[] = 'is_active = ?';            $args[] = $active;
            $sets[] = 'deactivated_datetime = ' . ($active ? 'NULL' : 'UTC_TIMESTAMP()');
        }

        if ($sets) {
            $args[] = $id;
            $conn->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
        }
        echo json_encode(['success' => true, 'id' => $id, 'message' => 'User updated']);
    } else {
        $hash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null;
        // Not told a company → pre-filled from the address so a new install doesn't
        // start with every requester blank. Freemail stays blank by design. Resolved
        // once, above, so the value written here is the same one that was authorised.
        $cols = ['email', 'display_name', 'preferred_name', 'password_hash', 'tenant_id'];
        $vals = [$emailOrNull, $displayName ?: null, $preferredName ?: null, $hash, $destinationTenantId];
        foreach (USER_PERSON_FIELDS as $f) {
            if (!array_key_exists($f, $data)) continue;
            $cols[] = $f;
            $vals[] = userPersonFieldValue($f, $data[$f]);
        }
        // is_active defaults to 1 in the schema; a create is never a deactivation.
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $conn->prepare("INSERT INTO users (" . implode(', ', $cols) . ", created_at) VALUES ($ph, UTC_TIMESTAMP())");
        $stmt->execute($vals);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'message' => 'User created']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
