<?php
/**
 * API: check that FreeITSM can read the Entra directory, with the values on the form.
 *
 * POST { provider_id, graph_tenant_id?, graph_use_own_app?, graph_client_id?, graph_client_secret? }
 *
 * Three separate answers, because they fail for three separate reasons and each
 * has a different fix: the app can authenticate (tenant, id, secret), it may
 * read users (User.Read.All), and it may read groups (GroupMember.Read.All).
 * Read-only: writes nothing.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/entra_sync.php';

csrfRequireWriteMethod();
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $conn = connectToDatabase();
    $s = $conn->prepare("SELECT * FROM auth_providers WHERE id = ? AND protocol = 'oidc'");
    $s->execute([(int)($data['provider_id'] ?? 0)]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    // Entra only: the stored secret goes to Microsoft, and another provider's must not.
    if (!$row || !entraIsEntraIssuer((string)$row['issuer_url'])) {
        echo json_encode(['success' => false, 'error' => 'Provider not found']);
        exit;
    }

    $cfg = entraConfig(entraApplyFormOverrides($row, $data));
    entraToken($cfg);
    $lines = ['✓ Signed in to tenant ' . $cfg['tenant'] . ' as application ' . $cfg['client_id'] . '.'];
    $ok = true;

    try {
        $u = entraGet($cfg, '/users?$count=true&$top=1&$select=id');
        $lines[] = '✓ Can read users' . (isset($u['@odata.count']) ? ' — ' . (int)$u['@odata.count'] . ' in the directory.' : '.');
    } catch (Throwable $e) {
        $ok = false;
        $lines[] = '✗ Cannot read users: ' . entraErrorForAdmin($e, 'test_entra_connection');
    }
    try {
        $g = entraGet($cfg, '/groups?$count=true&$top=1&$select=id');
        $lines[] = '✓ Can read groups' . (isset($g['@odata.count']) ? ' — ' . (int)$g['@odata.count'] . ' in the directory.' : '.');
    } catch (Throwable $e) {
        $ok = false;
        $lines[] = '✗ Cannot read groups: ' . entraErrorForAdmin($e, 'test_entra_connection');
    }

    echo json_encode(['success' => $ok, 'message' => implode("\n", $lines)]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => entraErrorForAdmin($e, 'test_entra_connection')]);
}
