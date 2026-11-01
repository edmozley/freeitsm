<?php
/**
 * API: show what the Entra field mapping would actually produce.
 *
 * POST { provider_id, graph_tenant_id?, graph_use_own_app?, graph_client_id?,
 *        graph_client_secret?, graph_attr_*?, sample? }
 *
 * The Entra twin of test_directory_mapping.php (LDAP): reads ONE real person
 * through Microsoft Graph and runs the mapping ON THE FORM over them, so each
 * row shows the value it would import rather than just the attribute name.
 * `sample` is a user principal name (or object id) to check somebody you know;
 * without it, the first user Graph returns.
 *
 * Also returns the attributes that person has from a wide list of user
 * properties, flattened one level (onPremisesExtensionAttributes/…,
 * employeeOrgData/…), so a field that cannot be mapped because the person does
 * not have it is distinguishable from one mapped to the wrong name. A tenant's
 * own directory extensions are only listed when the mapping names them: Graph
 * returns an extension only when it is asked for by name.
 *
 * Reads only. Administrators only.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/entra_sync.php';

csrfRequireWriteMethod();
header('Content-Type: application/json');

/** User properties fetched to fill the "what this person has" list, beyond what the mapping reads. */
const ESYNC_SAMPLE_EXTRA = 'givenName,surname,city,country,state,streetAddress,postalCode,companyName,usageLocation,'
    . 'employeeType,employeeHireDate,preferredLanguage,faxNumber,otherMails,mailNickname,'
    . 'onPremisesSamAccountName,onPremisesUserPrincipalName,employeeOrgData,onPremisesExtensionAttributes';

$data = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $conn = connectToDatabase();
    $s = $conn->prepare("SELECT * FROM auth_providers WHERE id = ? AND protocol = 'oidc'");
    $s->execute([(int)($data['provider_id'] ?? 0)]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row || !entraIsEntraIssuer((string)$row['issuer_url'])) {
        echo json_encode(['success' => false, 'error' => 'Provider not found']);
        exit;
    }

    // Test what is ON THE FORM, not what was last saved.
    $provider = entraApplyFormOverrides($row, $data);
    foreach (array_keys(ESYNC_ATTR_DEFAULTS) as $field) {
        if (!array_key_exists('graph_attr_' . $field, $data)) continue;
        $attr = trim((string)$data['graph_attr_' . $field]);
        if ($attr !== '' && !entraAttrNameValid($attr)) {
            echo json_encode(['success' => false, 'error' => '"' . $attr . '" is not an Entra attribute name.']);
            exit;
        }
        $provider['graph_attr_' . $field] = $attr !== '' ? $attr : null;
    }
    $cfg = entraConfig($provider);

    $select = implode(',', array_values(array_unique(array_merge(
        explode(',', entraUserSelect($provider)), explode(',', ESYNC_SAMPLE_EXTRA)
    ))));
    $query = '?$select=' . $select . '&$expand=manager($select=id,displayName)';
    $sample = trim((string)($data['sample'] ?? ''));
    if ($sample !== '') {
        try {
            $u = entraGet($cfg, '/users/' . rawurlencode($sample) . $query);
        } catch (EntraGraphError $e) {
            if ($e->status !== 404) throw $e;
            echo json_encode(['success' => false, 'error' => 'Nobody in Entra has the sign-in name or object id "' . $sample . '".']);
            exit;
        }
    } else {
        $u = entraGet($cfg, '/users' . $query . '&$top=1')['value'][0] ?? null;
        if (!$u) {
            echo json_encode(['success' => false, 'error' => 'Graph returned nobody to read.']);
            exit;
        }
    }

    $mapped = entraMapUser($u, $provider) ?? [];

    // Every attribute the person has, one level deep, so "not there" and
    // "misspelt" can be told apart.
    $available = [];
    foreach ($u as $k => $v) {
        if (str_starts_with((string)$k, '@odata') || $k === 'manager') continue;
        if (is_array($v) && $v !== array_values($v)) {             // complex: one level in
            foreach ($v as $sk => $sv) {
                if (is_scalar($sv) && trim((string)$sv) !== '' && !is_bool($sv)) $available[] = ['name' => "$k/$sk", 'value' => mb_substr(trim((string)$sv), 0, 120)];
            }
        } else {
            $val = entraUserValue([$k => $v], (string)$k);
            if ($val !== '') $available[] = ['name' => (string)$k, 'value' => mb_substr($val, 0, 120)];
        }
    }
    usort($available, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    $rows = [];
    foreach (array_keys(ESYNC_ATTR_DEFAULTS) as $field) {
        $val = (string)($mapped[$field] ?? '');
        $rows[] = ['key' => $field, 'attribute' => entraAttr($provider, $field), 'value' => $val, 'missing' => $val === ''];
    }
    // The two that are not configurable, shown so the table reads as the whole person.
    $rows[] = ['key' => 'guid', 'attribute' => 'id', 'value' => (string)($u['id'] ?? ''), 'missing' => empty($u['id'])];
    $mgr = trim((string)($u['manager']['displayName'] ?? ''));
    $rows[] = ['key' => 'manager', 'attribute' => 'manager', 'value' => $mgr, 'missing' => $mgr === ''];

    echo json_encode([
        'success'   => true,
        'skipped'   => $mapped === [],
        'sample'    => ($mapped['name'] ?? '') ?: (string)($u['userPrincipalName'] ?? ''),
        'rows'      => $rows,
        'available' => $available,
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => entraErrorForAdmin($e, 'test_entra_mapping')]);
}
