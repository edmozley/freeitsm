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

/**
 * Normalise a list of DNs (an array, or newline-separated text) for storage.
 *
 * ⚠️ These strings are handed to ldap_search() as the SEARCH BASE, which is a
 * position in the tree rather than a filter — so filter escaping does not apply
 * and would not help. What matters is that nothing but a DN is ever stored:
 *
 *  - control characters and newlines are stripped, since a newline is the
 *    record separator here and one embedded in a value would forge an entry;
 *  - anything without an '=' is not a DN and is dropped rather than stored and
 *    failed at run time, when the message would be far from the cause;
 *  - the list is deduplicated and capped, because it comes from a browser and
 *    a browser is not a promise.
 *
 * Whether the base is one the bind account may read is the DIRECTORY's decision,
 * not ours: an unreadable base returns nothing, and a base outside the tree
 * errors. Neither leaks anything the bind account could not already read.
 */
function ssoDnLines($value): ?string
{
    $lines = is_array($value) ? $value : preg_split('/\R/', (string)$value);
    $out = [];
    foreach ($lines as $line) {
        $line = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$line));
        if ($line === '' || strpos($line, '=') === false) continue;
        if (mb_strlen($line) > 512) continue;
        $out[strtolower($line)] = $line;
        if (count($out) >= 200) break;
    }
    return $out ? implode("\n", array_values($out)) : null;
}

// ⚠️ `=== 'ldap' ? 'ldap' : 'oidc'` was a complete answer while there were two
// protocols and silently wrong the moment there were three — every CardDAV
// provider would have been stored as OIDC. Validate against the list instead,
// so an unknown value still falls back safely but a known one survives.
$protoInput  = $data['protocol'] ?? 'oidc';
$protocol    = in_array($protoInput, ['oidc', 'ldap', 'carddav'], true) ? $protoInput : 'oidc';
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
// cannot leave stale settings from another one behind and quietly in force.
//
// 🔑 With three protocols that rule is what stops a real accident: a directory
// switched to CardDAV must not keep a live `sync_base_dn`, and a CardDAV source
// switched to LDAP must not keep a URL and password pointing at an address book
// nobody remembers configuring.
$LDAP_EMPTY = [
    'host' => null, 'port' => null, 'encryption' => null, 'bind_dn' => null,
    'base_dn' => null, 'user_filter' => null,
    'attr_username' => null, 'attr_email' => null, 'attr_name' => null, 'attr_guid' => null,
    'group_base_dn' => null, 'group_filter' => null, 'analyst_group' => null, 'user_group' => null,
    'sync_enabled' => 0, 'sync_base_dn' => null, 'sync_filter' => null,
    'sync_ou_includes' => null, 'sync_ou_excludes' => null,
    'sync_on_conflict' => 'adopt', 'sync_deactivate_after' => 3, 'sync_brake_percent' => 20,
    'attr_job_title' => null, 'attr_department' => null, 'attr_office' => null,
    'attr_phone' => null, 'attr_mobile' => null, 'attr_employee_id' => null, 'attr_manager' => null,
];
$CARDDAV_EMPTY = ['url' => null, 'username' => null, 'addressbook' => null, 'auth' => 'auto'];

$carddav          = $CARDDAV_EMPTY;
$cardDavSecretIn  = '';

if ($protocol === 'carddav') {
    // A contact SOURCE, not a sign-in method: issuer_url / client_id are NOT
    // NULL so they store '', exactly as an LDAP row does.
    $issuerUrl = '';
    $clientId  = '';
    $scopes    = 'openid email profile';   // column default; unused here
    $ldap      = $LDAP_EMPTY;

    $url = trim($data['carddav_url'] ?? '');
    if ($url === '') {
        bail('The address of the CardDAV server is required');
    }
    if (!preg_match('#^https?://#i', $url)) {
        bail('The CardDAV address must start with http:// or https://');
    }

    $authIn = $data['carddav_auth'] ?? 'auto';
    $carddav = [
        'url'         => $url,
        'username'    => trim($data['carddav_username'] ?? ''),
        // May be blank: an address book open to anyone on the LAN is unusual but
        // legal, and refusing it would be inventing a rule the protocol has not.
        'addressbook' => trim($data['carddav_addressbook'] ?? '') ?: null,
        'auth'        => in_array($authIn, ['auto', 'digest', 'basic'], true) ? $authIn : 'auto',
    ];
    $cardDavSecretIn = $data['carddav_password'] ?? '';
    // ⚠️ Both of these MUST be set, even though a CardDAV provider has neither
    // secret. The shared code below reads them unconditionally, so leaving them
    // undefined emitted two "Undefined variable" warnings — printed BEFORE the
    // JSON body, so every caller's JSON.parse() would have thrown on a save
    // that had in fact succeeded. Second time in one afternoon; the lesson is
    // to assert that a response PARSES, not that it reads correctly.
    // '' is also the right value semantically: it means "no secret supplied",
    // which is what keeps the columns NULL rather than storing an encrypted
    // empty string.
    $secretInput     = '';
    $ldapSecretInput = '';

    // An address book cannot authenticate anybody, so these two are meaningless
    // here and are forced off rather than trusted from the request — the dialog
    // hides them, and a hidden control is not a guard.
    $autoCreate      = 0;
    $requireVerified = 0;

} elseif ($protocol === 'oidc') {
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
    // Directory sync is not an OIDC thing; this provider stores the column
    // defaults. Shared with the CardDAV branch so the two cannot drift.
    $ldap = $LDAP_EMPTY;
    $ldapSecretInput = '';

} else {
    // issuer_url / client_id are NOT NULL, so an LDAP row stores '' in them.
    // (Kept as a convention, not a necessity: db_verify CAN relax a column via
    // a probe-then-MODIFY block — it does exactly that for users.email and
    // emails.from_address. Relaxing these two would simply buy nothing, since
    // '' already reads unambiguously as "not applicable to this protocol".)
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
        // --- directory sync ---
        'sync_enabled'  => !empty($data['sync_enabled']) ? 1 : 0,
        // Blank falls back to the sign-in base DN at run time, so an install that
        // syncs the same subtree it authenticates against configures nothing.
        'sync_base_dn'  => trim($data['sync_base_dn'] ?? '') ?: null,
        // The OU browser writes these: ticked branches, and carve-outs within
        // them. Normalised to one DN per line so the engine can split on it and
        // so two saves of the same selection produce the same stored text.
        'sync_ou_includes' => ssoDnLines($data['sync_ou_includes'] ?? null),
        'sync_ou_excludes' => ssoDnLines($data['sync_ou_excludes'] ?? null),
        'sync_filter'   => trim($data['sync_filter'] ?? '') ?: null,
        'sync_on_conflict' => (($data['sync_on_conflict'] ?? 'adopt') === 'flag') ? 'flag' : 'adopt',
        // 0 disables automatic deactivation entirely; negatives would be nonsense.
        'sync_deactivate_after' => max(0, (int)($data['sync_deactivate_after'] ?? 3)),
        // Clamped 0-100. ⚠️ 0 turns THE SAFETY BRAKE OFF, which somebody should
        // have to type deliberately rather than arrive at by accident.
        'sync_brake_percent'    => max(0, min(100, (int)($data['sync_brake_percent'] ?? 20))),
        'attr_job_title'   => trim($data['ldap_attr_job_title'] ?? '') ?: null,
        'attr_department'  => trim($data['ldap_attr_department'] ?? '') ?: null,
        'attr_office'      => trim($data['ldap_attr_office'] ?? '') ?: null,
        'attr_phone'       => trim($data['ldap_attr_phone'] ?? '') ?: null,
        'attr_mobile'      => trim($data['ldap_attr_mobile'] ?? '') ?: null,
        'attr_employee_id' => trim($data['ldap_attr_employee_id'] ?? '') ?: null,
        'attr_manager'     => trim($data['ldap_attr_manager'] ?? '') ?: null,
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
             'ldap_group_base_dn', 'ldap_group_filter', 'ldap_analyst_group', 'ldap_user_group',
             'sync_enabled', 'sync_base_dn', 'sync_ou_includes', 'sync_ou_excludes', 'sync_filter', 'sync_on_conflict',
             'sync_deactivate_after', 'sync_brake_percent',
             'ldap_attr_job_title', 'ldap_attr_department', 'ldap_attr_office',
             'ldap_attr_phone', 'ldap_attr_mobile', 'ldap_attr_employee_id', 'ldap_attr_manager',
             'carddav_url', 'carddav_username', 'carddav_addressbook', 'carddav_auth'];
    $vals = [$displayName, $protocol, $issuerUrl, $clientId, $scopes,
             $enabled, $autoCreate, $requireVerified,
             $defaultModules, $sortOrder, $tenantId,
             $ldap['host'], $ldap['port'], $ldap['encryption'], $ldap['bind_dn'],
             $ldap['base_dn'], $ldap['user_filter'], $ldap['attr_username'],
             $ldap['attr_email'], $ldap['attr_name'], $ldap['attr_guid'],
             $ldap['group_base_dn'], $ldap['group_filter'], $ldap['analyst_group'], $ldap['user_group'],
             $ldap['sync_enabled'], $ldap['sync_base_dn'], $ldap['sync_ou_includes'], $ldap['sync_ou_excludes'], $ldap['sync_filter'], $ldap['sync_on_conflict'],
             $ldap['sync_deactivate_after'], $ldap['sync_brake_percent'],
             $ldap['attr_job_title'], $ldap['attr_department'], $ldap['attr_office'],
             $ldap['attr_phone'], $ldap['attr_mobile'], $ldap['attr_employee_id'], $ldap['attr_manager'],
             $carddav['url'], $carddav['username'], $carddav['addressbook'], $carddav['auth']];

    // A blank/masked secret on update = keep what is stored.
    $writeSecret        = !isMaskedNoChangeValue($secretInput);
    $writeLdapSecret    = !isMaskedNoChangeValue($ldapSecretInput);
    $writeCardDavSecret = !isMaskedNoChangeValue($cardDavSecretIn);

    if ($id > 0) {
        if ($writeSecret)        { $cols[] = 'client_secret';      $vals[] = encryptValue($secretInput); }
        if ($writeLdapSecret)    { $cols[] = 'ldap_bind_password'; $vals[] = encryptValue($ldapSecretInput); }
        if ($writeCardDavSecret) { $cols[] = 'carddav_password';   $vals[] = encryptValue($cardDavSecretIn); }

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
        $cols[] = 'carddav_password';
        $vals[] = ($writeCardDavSecret && $cardDavSecretIn !== '') ? encryptValue($cardDavSecretIn) : null;

        $names  = '`' . implode('`, `', $cols) . '`';
        $marks  = implode(', ', array_fill(0, count($cols), '?'));
        $conn->prepare("INSERT INTO auth_providers ($names) VALUES ($marks)")->execute($vals);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
