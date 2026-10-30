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
 *         sort_order, tenant_id, auto_create_analysts (discussion #155).
 * OIDC only in practice: analyst_fallback_mode, profile_sync_mode (#155).
 * OIDC only: portal_email_domains, portal_show_button (GH #147).
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
require_once '../../includes/sso_identity.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

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

/**
 * Answers {success: false, error} as JSON and stops.
 *
 * @param string $msg  the error shown to the admin
 */
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
 *
 * @param mixed $value  the DNs from the request - an array, or newline-separated text (untrusted)
 */
function ssoDnLines($value): ?string
{
    $lines = is_array($value) ? $value : preg_split('/\R/', (string)$value);
    $out = [];
    foreach ($lines as $line) {
        $line = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$line));
        if ($line === '' || !str_contains($line, '=')) continue;
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
$enabled            = !empty($data['enabled']) ? 1 : 0;
$autoCreate         = !empty($data['auto_create_users']) ? 1 : 0;
$autoCreateAnalysts = !empty($data['auto_create_analysts']) ? 1 : 0;
$fallbackInput      = $data['analyst_fallback_mode'] ?? 'confirm';
$analystFallback    = in_array($fallbackInput, ['confirm', 'redirect', 'block'], true) ? $fallbackInput : 'confirm';
$requireVerified    = !empty($data['require_verified_email']) ? 1 : 0;
$profileSyncMode    = in_array($data['profile_sync_mode'] ?? '', ['always', 'initial', 'never'], true) ? $data['profile_sync_mode'] : 'never';
$defaultModules  = isset($data['default_modules']) && trim($data['default_modules']) !== ''
                   ? trim($data['default_modules']) : null;
$sortOrder       = (int)($data['sort_order'] ?? 0);
// 3.5.0: companies an auto-created analyst can see (ssoApplyJitCompanies()).
$jitCompanyIn    = in_array($data['jit_company_access'] ?? '', ['all', 'provider', 'none'], true) ? $data['jit_company_access'] : 'all';
// SSO claim mapping (3.5.0, OIDC only) - see includes/sso_claims.php.
$groupClaimIn    = mb_substr(trim((string)($data['oidc_group_claim'] ?? '')), 0, 100);
$analystGroupIn  = mb_substr(trim((string)($data['oidc_analyst_group'] ?? '')), 0, 255);
$adminGroupIn    = mb_substr(trim((string)($data['oidc_admin_group'] ?? '')), 0, 255);
// Group -> team rows (sso_group_teams): only touched when the dialog sends them.
$teamMappingsIn  = (isset($data['team_mappings']) && is_array($data['team_mappings'])) ? $data['team_mappings'] : null;
// Names for the groups the provider has sent (sso_groups_seen.label).
$groupLabelsIn   = (isset($data['group_labels']) && is_array($data['group_labels'])) ? $data['group_labels'] : null;
// Which client company owns this provider. Empty/0 = a global (MSP-internal) one.
$tenantId        = !empty($data['tenant_id']) ? (int)$data['tenant_id'] : null;
$id              = isset($data['id']) ? (int)$data['id'] : 0;
// GH #147 - portal routing. Only an OIDC provider can be redirected to, so
// the per-protocol branches below reset these for LDAP and CardDAV.
$portalShowButton = array_key_exists('portal_show_button', $data) ? (!empty($data['portal_show_button']) ? 1 : 0) : 1;
$portalDomains    = ssoParseEmailDomains($data['portal_email_domains'] ?? '');
if ($portalDomains['invalid']) {
    bail('"' . implode('", "', $portalDomains['invalid']) . '" is not an email domain. Type just the part after the @, for example company.com.');
}
$portalDomainList = $portalDomains['domains'];

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
$CARDDAV_EMPTY = ['url' => null, 'username' => null, 'addressbook' => null, 'auth' => 'auto',
                  'scope' => 'all', 'scope_value' => null, 'write_back' => 0, 'allow_create' => 0];

$carddav          = $CARDDAV_EMPTY;
$cardDavSecretIn  = '';

if ($protocol === 'carddav') {
    // A contact SOURCE, not a sign-in method: issuer_url / client_id are NOT
    // NULL so they store '', exactly as an LDAP row does.
    $issuerUrl = '';
    $clientId  = '';
    $scopes    = 'openid email profile';   // column default; unused here
    $ldap      = $LDAP_EMPTY;
    // 🔑 `enabled` IS the import switch for an address book. A directory has
    // two separate ideas — appear on the login page, and import people — but an
    // address book cannot do the first, so a second toggle would be a control
    // with only one meaningful setting. Kept in step here so `--all` in the
    // scheduled task picks the source up exactly when it is switched on, and
    // never when it is not.
    $ldap['sync_enabled'] = $enabled;

    $url = trim($data['carddav_url'] ?? '');
    if ($url === '') {
        bail('The address of the CardDAV server is required');
    }
    if (!preg_match('#^https?://#i', $url)) {
        bail('The CardDAV address must start with http:// or https://');
    }

    $authIn  = $data['carddav_auth'] ?? 'auto';
    $scopeIn = $data['carddav_scope'] ?? 'all';
    $carddav = [
        'url'         => $url,
        'username'    => trim($data['carddav_username'] ?? ''),
        // May be blank: an address book open to anyone on the LAN is unusual but
        // legal, and refusing it would be inventing a rule the protocol has not.
        'addressbook' => trim($data['carddav_addressbook'] ?? '') ?: null,
        'auth'        => in_array($authIn, ['auto', 'digest', 'basic'], true) ? $authIn : 'auto',
        // Which records, within that book. Validated against the list so an
        // unexpected value degrades to 'all' rather than being stored and later
        // matching nothing — a scope nothing matches is an import that silently
        // brings in zero people.
        //
        // 🔴 $scopeIn, read once above. This used to test `$data['carddav_scope']
        // ?? 'all'` and then return `$data['carddav_scope']` itself - so when the
        // key was absent (the Add dialog never sends it; the book and scope are
        // chosen afterwards on carddav.php) the test passed on the default and
        // the value returned was undefined: a PHP warning printed before the
        // JSON, then NULL into a NOT NULL column. Nobody could add a CardDAV
        // source at all.
        'scope'       => in_array($scopeIn, ['all', 'group', 'category'], true) ? $scopeIn : 'all',
        'scope_value' => trim($data['carddav_scope_value'] ?? '') ?: null,
        // 🔴 Whether edits in FreeITSM are pushed back to the card. Absent means
        // OFF on a NEW source - the one setting that can modify the operator's
        // own address book is never switched on by default. On an update, absent
        // means "as it was" (see below): the Add/Edit dialog never shows this
        // switch, so treating its silence as "off" turned write-back off every
        // time somebody renamed the source.
        'write_back'  => !empty($data['carddav_write_back']) ? 1 : 0,
        // Adding NEW people to the address book. Off unless asked, like
        // write-back; and see below, forced off whenever write-back is off.
        'allow_create' => !empty($data['carddav_allow_create']) ? 1 : 0,
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

    // An address book cannot authenticate anybody, so these are meaningless
    // here and are forced off rather than trusted from the request — the dialog
    // hides them, and a hidden control is not a guard.
    $autoCreate         = 0;
    $autoCreateAnalysts = 0;
    $requireVerified    = 0;
    $profileSyncMode    = 'never';
    $portalDomainList   = [];
    $portalShowButton   = 1;

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
    // LDAP-style directory sync is not an OIDC thing; this provider stores the
    // column defaults. Shared with the CardDAV branch so the two cannot drift.
    // (Entra sync's sync_* policy values are kept on update - see $readable.)
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
    // A directory has no redirect and no portal button (GH #147).
    $portalDomainList = [];
    $portalShowButton = 1;

    $host   = trim($data['ldap_host'] ?? '');
    $baseDn = trim($data['ldap_base_dn'] ?? '');
    $filter = trim($data['ldap_user_filter'] ?? '');
    if ($host === '' || $baseDn === '' || $filter === '') {
        bail('Server, base DN and user filter are required');
    }
    if (!str_contains($filter, '%s')) {
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
    if ($groupFilter !== '' && !str_contains($groupFilter, '%s')) {
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
             'enabled', 'auto_create_users', 'auto_create_analysts', 'analyst_fallback_mode', 'require_verified_email', 'profile_sync_mode',
             'default_modules', 'sort_order', 'tenant_id',
             'ldap_host', 'ldap_port', 'ldap_encryption', 'ldap_bind_dn',
             'ldap_base_dn', 'ldap_user_filter', 'ldap_attr_username',
             'ldap_attr_email', 'ldap_attr_name', 'ldap_attr_guid',
             'ldap_group_base_dn', 'ldap_group_filter', 'ldap_analyst_group', 'ldap_user_group',
             'sync_enabled', 'sync_base_dn', 'sync_ou_includes', 'sync_ou_excludes', 'sync_filter', 'sync_on_conflict',
             'sync_deactivate_after', 'sync_brake_percent',
             'ldap_attr_job_title', 'ldap_attr_department', 'ldap_attr_office',
             'ldap_attr_phone', 'ldap_attr_mobile', 'ldap_attr_employee_id', 'ldap_attr_manager',
             'carddav_url', 'carddav_username', 'carddav_addressbook', 'carddav_auth',
             'carddav_scope', 'carddav_scope_value', 'carddav_write_back', 'carddav_allow_create'];
    $vals = [$displayName, $protocol, $issuerUrl, $clientId, $scopes,
             $enabled, $autoCreate, $autoCreateAnalysts, $analystFallback, $requireVerified, $profileSyncMode,
             $defaultModules, $sortOrder, $tenantId,
             $ldap['host'], $ldap['port'], $ldap['encryption'], $ldap['bind_dn'],
             $ldap['base_dn'], $ldap['user_filter'], $ldap['attr_username'],
             $ldap['attr_email'], $ldap['attr_name'], $ldap['attr_guid'],
             $ldap['group_base_dn'], $ldap['group_filter'], $ldap['analyst_group'], $ldap['user_group'],
             $ldap['sync_enabled'], $ldap['sync_base_dn'], $ldap['sync_ou_includes'], $ldap['sync_ou_excludes'], $ldap['sync_filter'], $ldap['sync_on_conflict'],
             $ldap['sync_deactivate_after'], $ldap['sync_brake_percent'],
             $ldap['attr_job_title'], $ldap['attr_department'], $ldap['attr_office'],
             $ldap['attr_phone'], $ldap['attr_mobile'], $ldap['attr_employee_id'], $ldap['attr_manager'],
             $carddav['url'], $carddav['username'], $carddav['addressbook'], $carddav['auth'],
             $carddav['scope'], $carddav['scope_value'], $carddav['write_back'], $carddav['allow_create']];
    $cols[] = 'portal_email_domains';
    $vals[] = $portalDomainList ? implode("\n", $portalDomainList) : null;
    $cols[] = 'portal_show_button';
    $vals[] = $portalShowButton;
    $cols[] = 'jit_company_access';
    $vals[] = $protocol === 'carddav' ? 'all' : $jitCompanyIn;   // an address book creates nobody
    // Claim mapping (3.5.0): an OIDC provider's; cleared for any other protocol.
    $isOidcRow = $protocol === 'oidc';
    array_push($cols, 'oidc_group_claim', 'oidc_analyst_group', 'oidc_admin_group');
    array_push($vals, $isOidcRow && $groupClaimIn !== '' ? $groupClaimIn : null,
                      $isOidcRow && $analystGroupIn !== '' ? $analystGroupIn : null,
                      $isOidcRow && $adminGroupIn !== '' ? $adminGroupIn : null);

    // 🔴 ON UPDATE, A SETTING THE REQUEST DOES NOT MENTION KEEPS ITS STORED VALUE.
    //
    // Three screens save through this endpoint and none of them sends everything:
    //   - the Add/Edit dialog sends no address book, scope or write-back for a
    //     CardDAV source, and none of the directory-sync settings for LDAP;
    //   - carddav.php sends no company or default modules;
    //   - provider.php (LDAP) sends no require-verified-email or default modules.
    // Treating "not sent" as "empty" meant editing a source's name in the dialog
    // silently cleared its chosen address book, reset it to import everyone and
    // turned write-back off - and the LDAP equivalent switched directory sync
    // off - while each settings page reset the company to Global.
    //
    // Only when the protocol is unchanged: switching protocol must still clear
    // the old protocol's settings (see the note above the per-protocol fields).
    // Only for settings this protocol actually reads from the request; values
    // this endpoint forces (an address book's empty LDAP columns, its
    // auto-create switch) are never taken from the stored row.
    if ($id > 0) {
        $st = $conn->prepare("SELECT * FROM auth_providers WHERE id = ?");
        $st->execute([$id]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if (!$existing) bail('Provider not found');

        if ($existing['protocol'] === $protocol) {
            $readable = ['enabled', 'default_modules', 'sort_order', 'tenant_id', 'jit_company_access'];
            if ($protocol === 'oidc') {
                $readable = array_merge($readable, ['auto_create_users', 'auto_create_analysts', 'analyst_fallback_mode', 'require_verified_email', 'profile_sync_mode', 'issuer_url', 'client_id', 'scopes',
                                                    'portal_email_domains', 'portal_show_button',
                                                    'oidc_group_claim', 'oidc_analyst_group', 'oidc_admin_group',
                                                    // Entra directory sync (entra.php / save_entra_sync.php) uses
                                                    // these on an OIDC row; the dialog never sends them, so an
                                                    // edit there must not switch the import off.
                                                    'sync_enabled', 'sync_on_conflict', 'sync_deactivate_after', 'sync_brake_percent']);
            } elseif ($protocol === 'ldap') {
                $readable = array_merge($readable, ['auto_create_users', 'auto_create_analysts', 'analyst_fallback_mode', 'require_verified_email', 'profile_sync_mode'],
                    array_values(array_filter($cols, fn($c) => str_starts_with($c, 'ldap_') || str_starts_with($c, 'sync_'))));
            } else {
                $readable = array_merge($readable,
                    array_values(array_filter($cols, fn($c) => str_starts_with($c, 'carddav_'))));
            }
            foreach ($cols as $i => $c) {
                // Every readable column is named after its request key.
                // ⚠️ Only if the stored row HAS the column: before Database
                // Verification a newer column is missing, and reading it would
                // print a warning ahead of the JSON and break the save.
                if (in_array($c, $readable, true) && !array_key_exists($c, $data)
                    && array_key_exists($c, $existing)) {
                    $vals[$i] = $existing[$c];
                }
            }
            // An address book's import switch IS its enabled flag (see above), so
            // it follows whatever `enabled` ended up as, sent or kept.
            if ($protocol === 'carddav') {
                $vals[array_search('sync_enabled', $cols, true)] = $vals[array_search('enabled', $cols, true)];
            }
        }
    }

    // Adding new people only makes sense while FreeITSM may write at all. Worked
    // out AFTER the keep-stored step, so a write-back switched off here also
    // switches this off, whether or not the request mentioned it.
    $iWb = array_search('carddav_write_back', $cols, true);
    $iAc = array_search('carddav_allow_create', $cols, true);
    if ((int)$vals[$iWb] !== 1) $vals[$iAc] = 0;

    // 🔴 An install that has not run Database Verification since this column
    // arrived has no such column, and naming it failed EVERY provider save.
    // (Listing it did worse: the Authentication page showed no providers at
    // all.) Leave it out until it exists; nothing else depends on it.
    if (!$conn->query("SHOW COLUMNS FROM auth_providers LIKE 'carddav_allow_create'")->fetch()) {
        array_splice($cols, $iAc, 1);
        array_splice($vals, $iAc, 1);
    }
    // Same for the three discussion #155 columns, which arrive together.
    if (!ssoJitColumnsReady($conn)) {
        foreach (['auto_create_analysts', 'analyst_fallback_mode', 'profile_sync_mode'] as $jitCol) {
            $i = array_search($jitCol, $cols, true);
            if ($i !== false) {
                array_splice($cols, $i, 1);
                array_splice($vals, $i, 1);
            }
        }
    }

    if (!ssoJitCompanyReady($conn)) {
        $i = array_search('jit_company_access', $cols, true);
        if ($i !== false) { array_splice($cols, $i, 1); array_splice($vals, $i, 1); }
    }
    // Claim mapping: leave the columns out until Database Verification adds them;
    // otherwise the analyst / admin groups and team rows need the claim they are
    // read from.
    require_once '../../includes/sso_claims.php';
    if (!ssoClaimsReady($conn)) {
        foreach (['oidc_group_claim', 'oidc_analyst_group', 'oidc_admin_group'] as $c) {
            $i = array_search($c, $cols, true);
            if ($i !== false) { array_splice($cols, $i, 1); array_splice($vals, $i, 1); }
        }
        $teamMappingsIn = null;
    } else {
        $fClaim = $vals[array_search('oidc_group_claim', $cols, true)];
        $anyMapping = $teamMappingsIn !== null && array_filter($teamMappingsIn, fn($r) => trim((string)($r['group'] ?? '')) !== '');
        if (($fClaim === null || $fClaim === '')
            && ($vals[array_search('oidc_analyst_group', $cols, true)] || $vals[array_search('oidc_admin_group', $cols, true)] || $anyMapping)) {
            bail('Choose where the groups come from first - the analyst group, admin group and teams are read from it.');
        }
        if ($protocol !== 'oidc') $teamMappingsIn = [];   // a protocol switch clears them
    }

    // GH #147 portal routing. Checked on the FINAL value (sent or kept), so a
    // rename that does not mention the domains is still held to the same rules.
    if (ssoPortalRoutingColumnsReady($conn)) {
        $finalDomains = (string)($vals[array_search('portal_email_domains', $cols, true)] ?? '');
        $finalDomains = $finalDomains === '' ? [] : explode("\n", $finalDomains);
        if ($finalDomains) {
            // A public email domain would send every Gmail or Outlook user on the
            // portal to this provider - the same reason System -> Companies will
            // not map one to a company.
            require_once '../../includes/tenancy.php';
            foreach ($finalDomains as $d) {
                if (isFreemailDomain($conn, $d)) {
                    bail("$d is a public email domain, so it cannot be sent to one provider - everyone who uses it would be.");
                }
            }
            // One provider per domain, or which one wins would be decided by sort order
            // and nobody would be able to see why.
            $others = $conn->prepare("SELECT display_name, portal_email_domains FROM auth_providers
                                       WHERE id <> ? AND portal_email_domains IS NOT NULL AND portal_email_domains <> ''");
            $others->execute([$id]);
            foreach ($others->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $clash = array_intersect($finalDomains, explode("\n", $o['portal_email_domains']));
                if ($clash) {
                    bail(implode(', ', $clash) . ' is already sent to ' . $o['display_name'] . '. A domain can only go to one provider.');
                }
            }
        }
    } else {
        foreach (['portal_email_domains', 'portal_show_button'] as $c) {
            $i = array_search($c, $cols, true);
            if ($i !== false) {
                array_splice($cols, $i, 1);
                array_splice($vals, $i, 1);
            }
        }
    }

    // A blank/masked secret on update = keep what is stored.
    $writeSecret        = !isMaskedNoChangeValue($secretInput);
    $writeLdapSecret    = !isMaskedNoChangeValue($ldapSecretInput);
    $writeCardDavSecret = !isMaskedNoChangeValue($cardDavSecretIn);

    // The provider and its group -> team rows are saved together or not at all
    // (bail() exits; the open transaction is rolled back when the request ends).
    $conn->beginTransaction();

    if ($id > 0) {
        if ($writeSecret)        { $cols[] = 'client_secret';      $vals[] = encryptValue($secretInput); }
        if ($writeLdapSecret)    { $cols[] = 'ldap_bind_password'; $vals[] = encryptValue($ldapSecretInput); }
        if ($writeCardDavSecret) { $cols[] = 'carddav_password';   $vals[] = encryptValue($cardDavSecretIn); }

        $set  = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, $cols));
        $vals[] = $id;
        $conn->prepare("UPDATE auth_providers SET $set, last_modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute($vals);
        if ($teamMappingsIn !== null) {
            try { ssoSaveTeamMappings($conn, $id, $teamMappingsIn); } catch (ServiceError $e) { bail($e->getMessage()); }
        }
        if ($groupLabelsIn !== null && ssoClaimsReady($conn)) ssoSaveGroupLabels($conn, $id, $groupLabelsIn);
        $conn->commit();
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
        $newId = (int)$conn->lastInsertId();
        if ($teamMappingsIn) {
            try { ssoSaveTeamMappings($conn, $newId, $teamMappingsIn); } catch (ServiceError $e) { bail($e->getMessage()); }
        }
        $conn->commit();
        echo json_encode(['success' => true, 'id' => $newId]);
    }

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
