<?php
/**
 * API: save the Microsoft Entra ID directory-sync settings of an OIDC provider.
 *
 * POST { id, sync_enabled, graph_tenant_id, graph_use_own_app, graph_client_id,
 *        graph_client_secret, graph_user_scope, graph_user_groups,
 *        graph_include_guests, graph_group_scope, graph_groups,
 *        sync_on_conflict, sync_deactivate_after, sync_brake_percent }
 *
 * Separate from save_sso_provider.php on purpose: that endpoint owns the
 * sign-in half of the row and rewrites every column it knows; this one writes
 * ONLY the import half, so neither screen can undo the other.
 *
 * Secrets: graph_client_secret is encrypted at rest, and blank or masked means
 * "keep the stored one". graph_use_own_app = 0 clears both overrides, so the
 * sign-in app registration is used again.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/entra_sync.php';

csrfRequireWriteMethod();
header('Content-Type: application/json');

/**
 * Answers {success: false, error} as JSON and stops.
 *
 * @param string $msg  the error shown to the admin
 */
function bail(string $msg): void {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) bail('Invalid request data');

try {
    $conn = connectToDatabase();

    if (!$conn->query("SHOW COLUMNS FROM auth_providers LIKE 'graph_user_scope'")->fetch()) {
        bail('Run Database Verification (System → Database) first: the Entra sync settings have nowhere to be saved yet.');
    }

    $id = (int)($data['id'] ?? 0);
    $s = $conn->prepare("SELECT * FROM auth_providers WHERE id = ? AND protocol = 'oidc'");
    $s->execute([$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) bail('Provider not found');
    if (!entraIsEntraIssuer((string)$row['issuer_url'])) {
        bail('This provider does not sign in through Microsoft Entra ID, so it has no directory to import from.');
    }

    $enabled   = !empty($data['sync_enabled']) ? 1 : 0;
    $tenant    = trim((string)($data['graph_tenant_id'] ?? ''));
    if ($tenant !== '' && !entraIsGuid($tenant) && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $tenant)) {
        bail('The tenant id is either a GUID (Directory ID) or a domain such as contoso.onmicrosoft.com.');
    }
    $ownApp    = !empty($data['graph_use_own_app']);
    $clientId  = $ownApp ? trim((string)($data['graph_client_id'] ?? '')) : '';
    if ($ownApp && $clientId === '') bail('Type the application (client) id of the app registration used for the import.');
    $secretIn  = (string)($data['graph_client_secret'] ?? '');

    $userScope  = ($data['graph_user_scope'] ?? 'all') === 'groups' ? 'groups' : 'all';
    $userGroups = entraIdList($data['graph_user_groups'] ?? '');
    $groupScope = in_array($data['graph_group_scope'] ?? 'none', ['none', 'selected', 'all'], true) ? $data['graph_group_scope'] : 'none';
    $groups     = entraIdList($data['graph_groups'] ?? '');

    // Checked even with the import switched off: a setting that cannot work is
    // better refused now than discovered on the night the schedule first runs.
    if ($userScope === 'groups' && !$userGroups) bail('Choose at least one group whose members are imported, or import everybody.');
    if ($groupScope === 'selected' && !$groups)  bail('Choose at least one group to import, or import no groups.');
    if (count($userGroups) > 200 || count($groups) > 200) bail('Choose 200 groups or fewer, or import all groups.');

    $cols = [
        'sync_enabled'          => $enabled,
        'graph_tenant_id'       => $tenant !== '' ? $tenant : null,
        'graph_client_id'       => $clientId !== '' ? $clientId : null,
        'graph_user_scope'      => $userScope,
        'graph_user_groups'     => $userGroups ? implode("\n", $userGroups) : null,
        'graph_include_guests'  => !empty($data['graph_include_guests']) ? 1 : 0,
        'graph_group_scope'     => $groupScope,
        'graph_groups'          => $groups ? implode("\n", $groups) : null,
        'sync_on_conflict'      => (($data['sync_on_conflict'] ?? 'adopt') === 'flag') ? 'flag' : 'adopt',
        'sync_deactivate_after' => max(0, min(50, (int)($data['sync_deactivate_after'] ?? 3))),
        // ⚠️ 0 turns THE SAFETY BRAKE OFF; clamped, never defaulted to it.
        'sync_brake_percent'    => max(0, min(100, (int)($data['sync_brake_percent'] ?? 20))),
    ];
    if (!$ownApp) {
        $cols['graph_client_secret'] = null;
    } elseif (!isMaskedNoChangeValue($secretIn)) {
        $cols['graph_client_secret'] = encryptValue($secretIn);
    } elseif (trim((string)$row['graph_client_id']) !== $clientId || (string)$row['graph_client_secret'] === '') {
        // A different app with the old app's secret would fail at run time with
        // an AADSTS code; say it here instead.
        bail('Type the client secret of this app registration.');
    }

    // Field mapping (graph_attr_*): blank is the default attribute. A name that
    // is not plainly an attribute is refused here, because it ends up in a
    // Graph URL. Only once Database Verification has added the columns.
    if (array_key_exists('graph_attr_name', $row)) {
        foreach (array_keys(ESYNC_ATTR_DEFAULTS) as $field) {
            $attr = trim((string)($data['graph_attr_' . $field] ?? ''));
            if ($attr !== '' && !entraAttrNameValid($attr)) {
                bail('"' . $attr . '" is not an Entra attribute name. Use the name as Microsoft Graph spells it, such as city or onPremisesExtensionAttributes/extensionAttribute5.');
            }
            $cols['graph_attr_' . $field] = $attr !== '' ? $attr : null;
        }
    }

    if ($enabled) {
        // Resolve now so a multi-tenant issuer without a tenant id is caught at save.
        try { entraConfig(array_merge($row, $cols)); } catch (Throwable $e) { bail(entraErrorForAdmin($e, 'save_entra_sync')); }
    }

    $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($cols)));
    $conn->prepare("UPDATE auth_providers SET $set, last_modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute(array_merge(array_values($cols), [$id]));

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => entraErrorForAdmin($e, 'save_entra_sync')]);
}
