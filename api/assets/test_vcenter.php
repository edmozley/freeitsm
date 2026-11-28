<?php
/**
 * API Endpoint: test the vCenter connection step by step (GH #445).
 *
 * POST test_vcenter.php   { "server": "...", "user": "...", "password": "" }
 *
 * Tests what is in the form, saved or not, so a connection can be proved before
 * it is saved. An empty password means "the saved one" - but see the TRAP below.
 * The checks themselves are in includes/vcenter_diagnose.php.
 *
 * Read-only: signs in to vCenter, reads, signs out. Writes nothing.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/encryption.php';
require_once '../../includes/i18n.php';
require_once '../../includes/vcenter_diagnose.php';

// Sends a password to a server: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database unavailable']);
    exit;
}
// The same door as the vCenter settings tab and debug_vcenter.php: whoever
// administers the vCenter connection.
requireModuleAccessJson('assets', $conn);
requireCapabilityJson(Cap::ASSETS_VCENTER, $conn);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bad request.']);
    exit;
}

$stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('vcenter_server', 'vcenter_user', 'vcenter_password')");
$stmt->execute();
$saved = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $saved[$row['setting_key']] = (string)decryptValue($row['setting_value']);
}

$server = trim((string)($in['server'] ?? ''));
$user   = trim((string)($in['user'] ?? ''));
$pass   = (string)($in['password'] ?? '');
if ($server === '') $server = $saved['vcenter_server'] ?? '';
if ($user === '')   $user   = $saved['vcenter_user'] ?? '';

if ($pass === '') {
    // TRAP: the saved password is only lent to the SAVED server and user. Without
    // this, anyone allowed on this tab could type their own server's address,
    // leave the password blank, press Test and read the saved vCenter password
    // off their own server's log - the page never shows the password, but the
    // test would have sent it.
    $typed = vcenterNormaliseServer($server);
    $kept  = vcenterNormaliseServer($saved['vcenter_server'] ?? '');
    $sameTarget = $typed !== null && $kept !== null && $typed['base'] === $kept['base']
        && strcasecmp($user, $saved['vcenter_user'] ?? '') === 0;
    if (!$sameTarget || ($saved['vcenter_password'] ?? '') === '') {
        echo json_encode(['success' => false, 'error' => t('asset-management.vcenter_test.need_password')]);
        exit;
    }
    $pass = $saved['vcenter_password'];
}
if ($server === '' || $user === '') {
    echo json_encode(['success' => false, 'error' => t('asset-management.vcenter_test.need_server_user')]);
    exit;
}

set_time_limit(120);
$result = vcenterDiagnose($server, $user, $pass);
echo json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES);
