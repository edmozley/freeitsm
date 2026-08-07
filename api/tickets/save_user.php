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

    // You can only file someone into a company you yourself can reach. This binds
    // every analyst including all-access ones: reaching both companies is not a
    // licence to move people between them by hand-crafting a request.
    if ($tenantSent && $tenantId !== null && !analystCanAccessTenant($conn, (int)$_SESSION['analyst_id'], $tenantId)) {
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

        if ($sets) {
            $args[] = $id;
            $conn->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
        }
        echo json_encode(['success' => true, 'id' => $id, 'message' => 'User updated']);
    } else {
        $hash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null;
        // Not told a company → pre-fill from the address so a new install doesn't
        // start with every requester blank. Freemail stays blank by design.
        $newTenantId = $tenantSent ? $tenantId : resolveTenantForNewUser($conn, $email);
        $stmt = $conn->prepare("INSERT INTO users (email, display_name, preferred_name, password_hash, tenant_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())");
        $stmt->execute([$emailOrNull, $displayName ?: null, $preferredName ?: null, $hash, $newTenantId]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'message' => 'User created']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
