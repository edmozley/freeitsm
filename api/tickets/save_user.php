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

if (empty($email)) {
    echo json_encode(['success' => false, 'error' => 'Email is required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address']);
    exit;
}

if ($password !== '' && strlen($password) < 8) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Email must be unique within users, and must not collide with an analyst account
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

    // You can only file someone into a company you yourself can reach. This binds
    // every analyst including all-access ones: reaching both companies is not a
    // licence to move people between them by hand-crafting a request.
    if ($tenantSent && $tenantId !== null && !analystCanAccessTenant($conn, (int)$_SESSION['analyst_id'], $tenantId)) {
        echo json_encode(['success' => false, 'error' => 'You do not have access to that company']);
        exit;
    }

    if ($id) {
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET email = ?, display_name = ?, preferred_name = ?, password_hash = ? WHERE id = ?");
            $stmt->execute([$email, $displayName ?: null, $preferredName ?: null, $hash, $id]);
        } else {
            $stmt = $conn->prepare("UPDATE users SET email = ?, display_name = ?, preferred_name = ? WHERE id = ?");
            $stmt->execute([$email, $displayName ?: null, $preferredName ?: null, $id]);
        }
        if ($tenantSent) {
            $conn->prepare("UPDATE users SET tenant_id = ? WHERE id = ?")->execute([$tenantId, $id]);
        }
        echo json_encode(['success' => true, 'id' => $id, 'message' => 'User updated']);
    } else {
        $hash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null;
        // Not told a company → pre-fill from the address so a new install doesn't
        // start with every requester blank. Freemail stays blank by design.
        $newTenantId = $tenantSent ? $tenantId : resolveTenantForNewUser($conn, $email);
        $stmt = $conn->prepare("INSERT INTO users (email, display_name, preferred_name, password_hash, tenant_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())");
        $stmt->execute([$email, $displayName ?: null, $preferredName ?: null, $hash, $newTenantId]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'message' => 'User created']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
