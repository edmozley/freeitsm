<?php
/**
 * API: Create or update an authentication provider.
 *
 * Two shapes, selected by `protocol`:
 *   'oidc' — { id?, display_name, issuer_url, client_id, client_secret, scopes, ... }
 *   'ldap' — { id?, display_name, ldap_host, ldap_port, ldap_encryption,
 *              ldap_bind_dn, ldap_bind_password, ldap_base_dn, ldap_user_filter,
 *              ldap_attr_*, ... }
 * Shared: enabled, auto_create_users, require_verified_email, default_modules,
 *         sort_order, tenant_id.
 *
 * Secrets (client_secret / ldap_bind_password) are encrypted at rest via
 * encryptValue(). On update, a blank or masked ("****") secret means "leave the
 * stored one untouched" so the admin needn't re-enter it every save.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

function bail(string $msg): void {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$protocol    = ($data['protocol'] ?? 'oidc') === 'ldap' ? 'ldap' : 'oidc';
$displayName = trim($data['display_name'] ?? '');
if ($displayName === '') bail('Display name is required');

// --- Shared optional fields ---
$enabled         = !empty($data['enabled']) ? 1 : 0;
$autoCreate      = !empty($data['auto_create_users']) ? 1 : 0;
$requireVerified = !empty($data['require_verified_email']) ? 1 : 0;
$defaultModules  = isset($data['default_modules']) && trim($data['default_modules']) !== ''
                   ? trim($data['default_modules']) : null;
$sortOrder       = (int)($data['sort_order'] ?? 0);
// Which client company owns this provider. Empty/0 = a global (MSP-internal) one.
$tenantId        = !empty($data['tenant_id']) ? (int)$data['tenant_id'] : null;
$id              = isset($data['id']) ? (int)$data['id'] : 0;

// --- Per-protocol fields ---
// The unused column group is written empty, so switching a provider's protocol
// cannot leave stale settings from the other one behind and quietly in force.
if ($protocol === 'oidc') {
    $issuerUrl = rtrim(trim($data['issuer_url'] ?? ''), '/');
    $clientId  = trim($data['client_id'] ?? '');
    if ($issuerUrl === '' || $clientId === '') {
        bail('Issuer URL and client ID are required');
    }
    if (!preg_match('#^https?://#i', $issuerUrl)) {
        bail('Issuer URL must start with http:// or https://');
    }
    $scopes = trim($data['scopes'] ?? '');
    if ($scopes === '') $scopes = 'openid email profile';

    $secretInput = $data['client_secret'] ?? '';
    $ldap = [
        'host' => null, 'port' => null, 'encryption' => null, 'bind_dn' => null,
        'base_dn' => null, 'user_filter' => null,
        'attr_username' => null, 'attr_email' => null, 'attr_name' => null, 'attr_guid' => null,
        'group_base_dn' => null, 'group_filter' => null, 'analyst_group' => null, 'user_group' => null,
    ];
    $ldapSecretInput = '';

} else {
    // issuer_url / client_id are NOT NULL and db_verify only ever ADDS columns,
    // so they cannot be relaxed on upgraded installs — an LDAP row stores ''.
    $issuerUrl = '';
    $clientId  = '';
    $scopes    = 'openid email profile'; // column default; unused by LDAP

    $host   = trim($data['ldap_host'] ?? '');
    $baseDn = trim($data['ldap_base_dn'] ?? '');
    $filter = trim($data['ldap_user_filter'] ?? '');
    if ($host === '' || $baseDn === '' || $filter === '') {
        bail('Server, base DN and user filter are required');
    }
    if (strpos($filter, '%s') === false) {
        bail('The user filter must contain %s — it is replaced by what the user types.');
    }
    $enc = $data['ldap_encryption'] ?? 'none';
    if (!in_array($enc, ['none', 'starttls', 'ldaps'], true)) $enc = 'none';
    $port = (int)($data['ldap_port'] ?? 0);
    if ($port <= 0 || $port > 65535) $port = ($enc === 'ldaps') ? 636 : 389;

    // Naming a group but giving us no way to look groups up would fail every
    // login closed, with a confusing message. Catch it here instead.
    $groupFilter  = trim($data['ldap_group_filter'] ?? '');
    $analystGroup = trim($data['ldap_analyst_group'] ?? '');
    $userGroup    = trim($data['ldap_user_group'] ?? '');
    if (($analystGroup !== '' || $userGroup !== '') && $groupFilter === '') {
        bail('A group filter is required when you restrict access by group.');
    }
    if ($groupFilter !== '' && strpos($groupFilter, '%s') === false) {
        bail('The group filter must contain %s — it is replaced by the user\'s DN.');
    }

    $secretInput     = '';
    $ldapSecretInput = $data['ldap_bind_password'] ?? '';
    $ldap = [
        'host'          => $host,
        'port'          => $port,
        'encryption'    => $enc,
        'bind_dn'       => trim($data['ldap_bind_dn'] ?? '') ?: null,
        'base_dn'       => $baseDn,
        'user_filter'   => $filter,
        'attr_username' => trim($data['ldap_attr_username'] ?? '') ?: null,
        'attr_email'    => trim($data['ldap_attr_email'] ?? '') ?: null,
        'attr_name'     => trim($data['ldap_attr_name'] ?? '') ?: null,
        'attr_guid'     => trim($data['ldap_attr_guid'] ?? '') ?: null,
        'group_base_dn' => trim($data['ldap_group_base_dn'] ?? '') ?: null,
        'group_filter'  => $groupFilter ?: null,
        'analyst_group' => $analystGroup ?: null,
        'user_group'    => $userGroup ?: null,
    ];
}

try {
    $conn = connectToDatabase();

    // Columns common to both branches, in a fixed order.
    $cols = ['display_name', 'protocol', 'issuer_url', 'client_id', 'scopes',
             'enabled', 'auto_create_users', 'require_verified_email',
             'default_modules', 'sort_order', 'tenant_id',
             'ldap_host', 'ldap_port', 'ldap_encryption', 'ldap_bind_dn',
             'ldap_base_dn', 'ldap_user_filter', 'ldap_attr_username',
             'ldap_attr_email', 'ldap_attr_name', 'ldap_attr_guid',
             'ldap_group_base_dn', 'ldap_group_filter', 'ldap_analyst_group', 'ldap_user_group'];
    $vals = [$displayName, $protocol, $issuerUrl, $clientId, $scopes,
             $enabled, $autoCreate, $requireVerified,
             $defaultModules, $sortOrder, $tenantId,
             $ldap['host'], $ldap['port'], $ldap['encryption'], $ldap['bind_dn'],
             $ldap['base_dn'], $ldap['user_filter'], $ldap['attr_username'],
             $ldap['attr_email'], $ldap['attr_name'], $ldap['attr_guid'],
             $ldap['group_base_dn'], $ldap['group_filter'], $ldap['analyst_group'], $ldap['user_group']];

    // A blank/masked secret on update = keep what is stored.
    $writeSecret     = !isMaskedNoChangeValue($secretInput);
    $writeLdapSecret = !isMaskedNoChangeValue($ldapSecretInput);

    if ($id > 0) {
        if ($writeSecret)     { $cols[] = 'client_secret';      $vals[] = encryptValue($secretInput); }
        if ($writeLdapSecret) { $cols[] = 'ldap_bind_password'; $vals[] = encryptValue($ldapSecretInput); }

        $set  = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, $cols));
        $vals[] = $id;
        $conn->prepare("UPDATE auth_providers SET $set, last_modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute($vals);
        echo json_encode(['success' => true, 'id' => $id]);

    } else {
        $cols[] = 'client_secret';
        $vals[] = ($writeSecret && $secretInput !== '') ? encryptValue($secretInput) : null;
        $cols[] = 'ldap_bind_password';
        $vals[] = ($writeLdapSecret && $ldapSecretInput !== '') ? encryptValue($ldapSecretInput) : null;

        $names  = '`' . implode('`, `', $cols) . '`';
        $marks  = implode(', ', array_fill(0, count($cols), '?'));
        $conn->prepare("INSERT INTO auth_providers ($names) VALUES ($marks)")->execute($vals);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
