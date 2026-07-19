<?php
/**
 * LDAP / Active Directory helper.
 *
 * Authenticates a user against a directory by BIND: we bind to the directory
 * as that user with the password they typed, and a successful bind IS the
 * proof the password is right. We never read or compare password hashes.
 *
 * Because a user types "alice", not their full DN, this is a two-step dance:
 *   1. bind as a read-only service account so we are allowed to search,
 *   2. search for the user to discover their real DN,
 *   3. re-bind as that DN with the typed password.
 *
 * This is NOT single sign-on: the user still types their password into our own
 * login form. It shares the `auth_providers` table with OIDC (protocol='ldap')
 * so there is one place to configure "how people sign in".
 *
 * Supports both Active Directory (sAMAccountName / objectGUID / memberOf) and
 * OpenLDAP (uid / entryUUID) — every attribute name is configurable precisely
 * so the code does not silently assume one flavour.
 */

require_once __DIR__ . '/encryption.php';

/** Connection/read timeout, in seconds. A dead DC must not hang the login page. */
define('LDAP_TIMEOUT_SECONDS', 8);

/**
 * Sensible per-flavour defaults, offered by the admin UI as presets.
 * 'ad' = Active Directory / Samba AD DC, 'openldap' = OpenLDAP / 389 / FreeIPA.
 */
function ldapFlavourDefaults(string $flavour): array {
    if ($flavour === 'openldap') {
        return [
            'ldap_port'          => 389,
            'ldap_encryption'    => 'none',
            'ldap_user_filter'   => '(&(objectClass=inetOrgPerson)(|(uid=%s)(mail=%s)))',
            'ldap_attr_username' => 'uid',
            'ldap_attr_email'    => 'mail',
            'ldap_attr_name'     => 'cn',
            'ldap_attr_guid'     => 'entryUUID',
            // OpenLDAP has no nested-group equivalent, and no memberOf at all
            // unless the memberof overlay is loaded — so ask the groups instead.
            'ldap_group_filter'  => '(&(objectClass=groupOfNames)(member=%s))',
        ];
    }
    return [
        'ldap_port'          => 389,
        'ldap_encryption'    => 'none',
        'ldap_user_filter'   => '(&(objectClass=user)(|(sAMAccountName=%s)(userPrincipalName=%s)(mail=%s)))',
        'ldap_attr_username' => 'sAMAccountName',
        'ldap_attr_email'    => 'mail',
        'ldap_attr_name'     => 'displayName',
        'ldap_attr_guid'     => 'objectGUID',
        // LDAP_MATCHING_RULE_IN_CHAIN — walks nested groups, which a plain
        // memberOf read cannot do. Active Directory only.
        'ldap_group_filter'  => '(&(objectClass=group)(member:1.2.840.113556.1.4.1941:=%s))',
    ];
}

/** True when the PHP ldap extension is present. Checked before every use. */
function ldapExtensionAvailable(): bool {
    return extension_loaded('ldap');
}

/**
 * Load an auth_providers row by id with the bind password decrypted.
 * Returns null if not found or not an LDAP provider.
 */
function ldapGetProvider(PDO $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM auth_providers WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($row['protocol'] ?? '') !== 'ldap') return null;
    $row['ldap_bind_password'] = decryptValue($row['ldap_bind_password']);
    return $row;
}

/** Build the connection URI, e.g. ldaps://dc1.example.com:636 */
function ldapBuildUri(array $p): string {
    $scheme = (($p['ldap_encryption'] ?? '') === 'ldaps') ? 'ldaps' : 'ldap';
    $port   = (int)($p['ldap_port'] ?? 0);
    if ($port <= 0) $port = ($scheme === 'ldaps') ? 636 : 389;
    return $scheme . '://' . trim((string)$p['ldap_host']) . ':' . $port;
}

/**
 * Turn a raw attribute value into something storable as text.
 *
 * AD's objectGUID is BINARY; OpenLDAP's entryUUID is already a readable
 * string. Hex-encode anything that is not clean printable text so the
 * `subject` column holds a stable, comparable value either way.
 */
function ldapStringifyId(string $raw): string {
    if ($raw === '') return '';
    // Valid UTF-8 with no control characters => use as-is.
    if (preg_match('//u', $raw) === 1 && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw) !== 1) {
        return $raw;
    }
    return bin2hex($raw);
}

/**
 * Open a connection and apply the options that matter.
 * Throws on failure. Does NOT bind.
 */
function ldapOpen(array $p) {
    if (!ldapExtensionAvailable()) {
        throw new Exception('The PHP "ldap" extension is not enabled on this server.');
    }
    $uri = ldapBuildUri($p);
    $ds  = @ldap_connect($uri);
    if ($ds === false) {
        throw new Exception('Could not create an LDAP connection to ' . $uri);
    }
    ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
    // Active Directory answers subtree searches with referrals to other naming
    // contexts. Chasing them makes searches fail or hang, and we never need
    // them, so switch it off.
    ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($ds, LDAP_OPT_NETWORK_TIMEOUT, LDAP_TIMEOUT_SECONDS);
    ldap_set_option($ds, LDAP_OPT_TIMELIMIT, LDAP_TIMEOUT_SECONDS);

    if (($p['ldap_encryption'] ?? '') === 'starttls') {
        if (!@ldap_start_tls($ds)) {
            throw new Exception('STARTTLS failed: ' . ldap_error($ds));
        }
    }
    return $ds;
}

/**
 * Bind as the configured read-only service account.
 * Throws with a useful message on failure.
 */
function ldapBindService($ds, array $p): void {
    $dn = trim((string)($p['ldap_bind_dn'] ?? ''));
    $pw = (string)($p['ldap_bind_password'] ?? '');
    if ($dn === '') {
        // Anonymous bind. Some directories permit it for searching; most do not.
        if (!@ldap_bind($ds)) {
            throw new Exception('Anonymous bind was rejected: ' . ldap_error($ds));
        }
        return;
    }
    if ($pw === '') {
        throw new Exception('A password is required for the service account bind DN.');
    }
    if (!@ldap_bind($ds, $dn, $pw)) {
        throw new Exception('Service account bind failed: ' . ldap_error($ds));
    }
}

/**
 * Find exactly one directory entry for $login.
 * Returns the raw entry array (from ldap_get_entries) or null when not found.
 * Throws when the search itself fails or matches more than one user.
 */
function ldapFindUser($ds, array $p, string $login): ?array {
    $baseDn = trim((string)($p['ldap_base_dn'] ?? ''));
    $filter = trim((string)($p['ldap_user_filter'] ?? ''));
    if ($baseDn === '' || $filter === '') {
        throw new Exception('The provider is missing a base DN or user filter.');
    }

    // Escape BEFORE substituting, or a crafted login could rewrite the filter.
    $safe  = ldap_escape($login, '', LDAP_ESCAPE_FILTER);
    // str_replace (not sprintf) so a filter may contain any number of %s.
    $query = str_replace('%s', $safe, $filter);

    $attrs = array_values(array_unique(array_filter([
        'dn',
        (string)($p['ldap_attr_username'] ?? ''),
        (string)($p['ldap_attr_email'] ?? ''),
        (string)($p['ldap_attr_name'] ?? ''),
        (string)($p['ldap_attr_guid'] ?? ''),
    ])));

    $result = @ldap_search($ds, $baseDn, $query, $attrs, 0, 2, LDAP_TIMEOUT_SECONDS);
    if ($result === false) {
        // OpenLDAP reports an unreadable subtree as "No such object" rather than
        // a permission error, so say so — it is almost always the ACL on the
        // service account, not a wrong base DN.
        throw new Exception('Directory search failed: ' . ldap_error($ds)
            . '. Check the base DN, and that the service account may read it.');
    }
    $entries = ldap_get_entries($ds, $result);
    if (!is_array($entries) || (int)$entries['count'] === 0) return null;
    if ((int)$entries['count'] > 1) {
        throw new Exception('That username matches more than one directory entry.');
    }
    return $entries[0];
}

/** Read a single attribute out of an ldap_get_entries() row (keys are lowercased). */
function ldapAttr(array $entry, ?string $attr): string {
    if ($attr === null || $attr === '') return '';
    $key = strtolower($attr);
    if (!isset($entry[$key]) || !isset($entry[$key][0])) return '';
    return (string)$entry[$key][0];
}

/**
 * Every group the user belongs to, as ['dn' => ..., 'cn' => ...].
 *
 * Asks the GROUPS who their members are, rather than reading memberOf off the
 * user. That is deliberate:
 *   - OpenLDAP has no memberOf at all without the memberof overlay loaded;
 *   - on AD, memberOf lists DIRECT groups only, so a user in "IT-Support",
 *     which is itself inside "All-Staff", does not show "All-Staff" at all.
 * The AD default filter uses LDAP_MATCHING_RULE_IN_CHAIN, which walks the
 * nesting properly. Returns [] when no group filter is configured.
 *
 * Must be called on a connection bound as the SERVICE account: the user's own
 * bind may not be permitted to read group objects.
 */
function ldapUserGroups($ds, array $p, string $userDn): array {
    $filter = trim((string)($p['ldap_group_filter'] ?? ''));
    if ($filter === '') return [];

    $baseDn = trim((string)($p['ldap_group_base_dn'] ?? ''));
    if ($baseDn === '') $baseDn = trim((string)($p['ldap_base_dn'] ?? ''));
    if ($baseDn === '') return [];

    $safe  = ldap_escape($userDn, '', LDAP_ESCAPE_FILTER);
    $query = str_replace('%s', $safe, $filter);

    $result = @ldap_search($ds, $baseDn, $query, ['cn'], 0, 0, LDAP_TIMEOUT_SECONDS);
    if ($result === false) return [];
    $entries = ldap_get_entries($ds, $result);
    if (!is_array($entries)) return [];

    $out = [];
    for ($i = 0; $i < (int)$entries['count']; $i++) {
        $out[] = [
            'dn' => (string)$entries[$i]['dn'],
            'cn' => isset($entries[$i]['cn'][0]) ? (string)$entries[$i]['cn'][0] : '',
        ];
    }
    return $out;
}

/**
 * Is the user in $wanted? $wanted may be a plain group name ("NW-IT-Support")
 * or a full DN — admins reach for either, so accept both. Matching is
 * case-insensitive because directories treat these names that way.
 */
function ldapInGroup(array $groups, string $wanted): bool {
    $wanted = trim($wanted);
    if ($wanted === '') return false;
    foreach ($groups as $g) {
        if (strcasecmp($g['cn'], $wanted) === 0) return true;
        if (strcasecmp($g['dn'], $wanted) === 0) return true;
    }
    return false;
}

/**
 * Authenticate $login/$password against the directory.
 *
 * Returns:
 *   ['ok' => true,  'user' => ['dn','guid','username','email','name','groups']]
 *   ['ok' => false, 'error' => string, 'reason' => 'credentials'|'config'|'notfound']
 *
 * 'reason' lets the caller decide what to reveal: a login form should say
 * "wrong username or password" for everything, while the admin Test button
 * wants the detail.
 */
function ldapAuthenticate(array $provider, string $login, string $password): array {
    $login = trim($login);

    // ---- THE GUARD ----------------------------------------------------
    // RFC 4513 defines a bind with a DN but an EMPTY password as an
    // "unauthenticated bind", and many directories answer it with SUCCESS.
    // Without this check, submitting a blank password would authenticate as
    // whoever was found — i.e. log in as anyone. Our OpenLDAP and Samba AD
    // test rigs both happen to REJECT it, so no amount of local testing would
    // catch a regression here. Never remove this; never rely on the server.
    // ⚠️ Also refuse anything that becomes empty AT THE C BOUNDARY. libldap
    // takes a NUL-terminated string, so a password of "\0" — or "\0anything" —
    // is truncated to "" by the library and becomes exactly the unauthenticated
    // bind this guard exists to prevent, having sailed past a `=== ''` check in
    // PHP. Deliberately NOT trimming whitespace: "   " is a real (if daft)
    // password of non-zero length, the directory checks it properly, and
    // silently trimming would mean rejecting a password some directory might
    // legitimately hold.
    if ($password === '' || strpos($password, "\0") !== false) {
        return ['ok' => false, 'reason' => 'credentials', 'error' => 'A password is required.'];
    }
    if ($login === '') {
        return ['ok' => false, 'reason' => 'credentials', 'error' => 'A username is required.'];
    }
    // -------------------------------------------------------------------

    try {
        $ds = ldapOpen($provider);
    } catch (Exception $e) {
        return ['ok' => false, 'reason' => 'config', 'error' => $e->getMessage()];
    }

    try {
        ldapBindService($ds, $provider);
        $entry = ldapFindUser($ds, $provider, $login);
    } catch (Exception $e) {
        @ldap_unbind($ds);
        return ['ok' => false, 'reason' => 'config', 'error' => $e->getMessage()];
    }

    if ($entry === null) {
        @ldap_unbind($ds);
        return ['ok' => false, 'reason' => 'notfound', 'error' => 'No directory entry for that username.'];
    }

    $userDn = (string)($entry['dn'] ?? '');
    if ($userDn === '') {
        @ldap_unbind($ds);
        return ['ok' => false, 'reason' => 'config', 'error' => 'Directory entry has no DN.'];
    }

    // Re-bind as the user. THIS is the password check.
    // A disabled/expired/locked account fails here too: the directory refuses
    // the bind even when the password is correct.
    if (!@ldap_bind($ds, $userDn, $password)) {
        $err = ldap_error($ds);
        @ldap_unbind($ds);
        return ['ok' => false, 'reason' => 'credentials', 'error' => 'Invalid credentials (' . $err . ').'];
    }

    $guidAttr = (string)($provider['ldap_attr_guid'] ?? '');
    $guid     = ldapStringifyId(ldapAttr($entry, $guidAttr));
    $username = ldapAttr($entry, $provider['ldap_attr_username'] ?? '');
    $email    = strtolower(ldapAttr($entry, $provider['ldap_attr_email'] ?? ''));
    $name     = ldapAttr($entry, $provider['ldap_attr_name'] ?? '');

    // Read groups back as the SERVICE account — the user's own bind may not be
    // allowed to read group objects.
    $groups = [];
    try {
        ldapBindService($ds, $provider);
        $groups = ldapUserGroups($ds, $provider, $userDn);
    } catch (Exception $e) {
        // Password was already proven. A group-read failure must not silently
        // become "access granted", so the caller's gate decides — an empty
        // group list fails a configured gate closed.
    }

    @ldap_unbind($ds);

    return ['ok' => true, 'user' => [
        'dn'       => $userDn,
        // Fall back to the DN only if no immutable id attribute is configured.
        // (A DN changes on rename/OU move, which would orphan the link — so the
        // GUID is strongly preferred and the UI defaults it in.)
        'guid'     => $guid !== '' ? $guid : $userDn,
        'username' => $username,
        'email'    => $email,
        'name'     => $name,
        'groups'   => $groups,
    ]];
}

/**
 * Decide what an authenticated directory user is allowed to be.
 *
 * Returns 'analyst', 'user' (self-service requester), or null for no access.
 *
 * The gate exists because of GitHub #47: with auto-create on, EVERY person the
 * directory authenticates would otherwise become an analyst. Point that at a
 * 500-person company and you get 500 analysts. Naming an analyst group is what
 * makes just-in-time creation safe.
 *
 * Fails CLOSED: once a group is configured, membership must be proven. If the
 * group read failed for any reason the list is empty, and empty never matches.
 * When NO groups are configured at all the gate is off and anyone the directory
 * authenticates is an analyst — that is the single-team default, and the help
 * page says so plainly.
 */
function ldapAccessRole(array $provider, array $ldapUser): ?string {
    $analystGroup = trim((string)($provider['ldap_analyst_group'] ?? ''));
    $userGroup    = trim((string)($provider['ldap_user_group'] ?? ''));

    if ($analystGroup === '' && $userGroup === '') return 'analyst'; // gate off

    $groups = isset($ldapUser['groups']) && is_array($ldapUser['groups']) ? $ldapUser['groups'] : [];

    // Analyst wins when someone is in both — the more capable role.
    if ($analystGroup !== '' && ldapInGroup($groups, $analystGroup)) return 'analyst';
    if ($userGroup    !== '' && ldapInGroup($groups, $userGroup))    return 'user';
    return null;
}

/**
 * The LDAP providers a PORTAL sign-in attempt may be tried against.
 *
 * ⚠️ WHY THIS IS NOT `ldapAnalystProviders()` WITH A DIFFERENT FILTER
 * -------------------------------------------------------------------
 * Analyst directories are global (`tenant_id IS NULL`) and there is exactly one
 * set of them, so trying each in turn costs nothing. The portal is different:
 * on an MSP install each client company can own its own directory, and trying
 * them all would mean
 *
 *   1. taking a password someone typed for THEIR employer and offering it to
 *      every other client's domain controller, and
 *   2. incrementing the AD lockout counter on every one of those directories —
 *      so one person fat-fingering their password could lock accounts that
 *      belong to a different company entirely.
 *
 * Neither is acceptable, and neither is visible in testing at N=1. So the
 * candidate list is SCOPED:
 *
 *   • single-company install  → every enabled LDAP provider (no boundary exists)
 *   • an identifier with a domain we recognise → that company's directories,
 *     plus any global ones
 *   • anything else (a bare username, or an unknown domain) → global only
 *
 * ⚠️ KNOWN LIMIT: on a MULTI-company install, a mailbox-less user signing in
 * with a bare username can only be matched against a GLOBAL directory — there
 * is nothing in "w.noemail" to say which company they belong to. A company-owned
 * directory therefore needs its people to have addresses, or the portal needs a
 * company hint we don't collect yet. Documented rather than guessed at, because
 * guessing here means spraying passwords.
 *
 * @param ?string $identifier what the user typed (email or username), or null
 */
function ldapPortalProviders(PDO $conn, ?string $identifier = null): array {
    $ids = [];
    try {
        require_once __DIR__ . '/tenancy.php';

        if (!isMultiTenant($conn)) {
            // One company: every directory is "the" directory.
            $stmt = $conn->query(
                "SELECT id FROM auth_providers
                  WHERE protocol = 'ldap' AND enabled = 1
                  ORDER BY sort_order, display_name"
            );
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $tenantId = null;
            $addr = strtolower(trim((string)$identifier));
            if ($addr !== '' && strpos($addr, '@') !== false) {
                $tenantId = resolveTenantIdForAddress($conn, $addr);
            }

            if ($tenantId !== null) {
                $stmt = $conn->prepare(
                    "SELECT id FROM auth_providers
                      WHERE protocol = 'ldap' AND enabled = 1
                        AND (tenant_id = ? OR tenant_id IS NULL)
                      ORDER BY sort_order, display_name"
                );
                $stmt->execute([$tenantId]);
            } else {
                $stmt = $conn->query(
                    "SELECT id FROM auth_providers
                      WHERE protocol = 'ldap' AND enabled = 1 AND tenant_id IS NULL
                      ORDER BY sort_order, display_name"
                );
            }
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Exception $e) {
        return []; // column/table not present yet (pre db-verify)
    }

    $out = [];
    foreach ($ids as $id) {
        $p = ldapGetProvider($conn, (int)$id);
        if ($p) $out[] = $p;
    }
    return $out;
}

/**
 * Every enabled, analyst-facing LDAP provider, in display order.
 * tenant_id IS NULL = global/analyst-facing, matching how the OIDC analyst
 * login lists providers.
 */
function ldapAnalystProviders(PDO $conn): array {
    try {
        $stmt = $conn->query(
            "SELECT id FROM auth_providers
              WHERE protocol = 'ldap' AND enabled = 1 AND tenant_id IS NULL
              ORDER BY sort_order, display_name"
        );
    } catch (Exception $e) {
        return []; // column/table not present yet (pre db-verify)
    }
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $p = ldapGetProvider($conn, (int)$id);
        if ($p) $out[] = $p;
    }
    return $out;
}

/**
 * Create an analyst from directory attributes, assigned to this provider.
 *
 * The local password is set to an unusable random hash: a directory-backed
 * analyst signs in via the directory, never a local password.
 *
 * NOTE: deliberately mirrors oidcCreateAnalyst() in api/auth/oidc_callback.php
 * rather than sharing with it. Folding the two into one provisioning helper is
 * worthwhile, but is a refactor of working auth code and belongs in its own
 * change, not bundled with a new auth path.
 */
function ldapCreateAnalyst(PDO $conn, int $providerId, string $preferredUser, string $name, string $email, ?string $defaultModules): int {
    $base = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '', $preferredUser ?: explode('@', $email)[0]));
    if ($base === '') $base = 'ldapuser';
    $username = $base;
    $i = 1;
    $check = $conn->prepare("SELECT COUNT(*) FROM analysts WHERE username = ?");
    while (true) {
        $check->execute([$username]);
        if ((int)$check->fetchColumn() === 0) break;
        $username = $base . $i++;
    }

    $mods = ($defaultModules !== null && trim($defaultModules) !== '')
        ? array_values(array_filter(array_map('trim', explode(',', $defaultModules))))
        : [];

    // 🔴 `can_access_all_modules` MUST be set to 0 when a module list is
    // configured. It defaults to 1, and getAnalystAllowedModules() short-circuits
    // on it and returns "everything" WITHOUT ever reading `analyst_modules` — so
    // writing those rows while leaving the flag at 1 restricts nobody. That was
    // the behaviour until this was fixed: an admin could set "Default module
    // access for auto-created users" to `tickets, knowledge`, watch the rows
    // appear, and every auto-created directory user still got the lot.
    //
    // No list configured → flag stays 1, which is the documented "leave it blank
    // and they get every module" behaviour.
    $restricted = !empty($mods) ? 0 : 1;

    $unusable = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "INSERT INTO analysts (username, password_hash, full_name, email, is_active, created_datetime, auth_provider_id, can_access_all_modules)
         VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP(), ?, ?)"
    );
    $stmt->execute([$username, $unusable, $name ?: $username, $email, $providerId, $restricted]);
    $analystId = (int)$conn->lastInsertId();

    if (!empty($mods)) {
        $ins = $conn->prepare("INSERT INTO analyst_modules (analyst_id, module_key) VALUES (?, ?)");
        foreach ($mods as $m) {
            try { $ins->execute([$analystId, $m]); } catch (Exception $e) { /* ignore dupes */ }
        }
    }
    return $analystId;
}

/**
 * Map an authenticated directory user onto an analyst id, creating one if the
 * provider allows it. Mirrors the OIDC resolution order:
 *   1) existing link by (provider, subject),
 *   2) match an existing analyst by email — STRICTLY isolated: the analyst must
 *      already be assigned to this provider,
 *   3) just-in-time create, if auto_create_users is on.
 *
 * Returns ['ok' => true, 'analyst_id' => int] or ['ok' => false, 'error' => string].
 */
function ldapResolveAnalyst(PDO $conn, array $provider, array $ldapUser): array {
    $providerId = (int)$provider['id'];
    $subject    = (string)$ldapUser['guid'];
    $email      = strtolower(trim((string)$ldapUser['email']));

    // 1) Existing link.
    $stmt = $conn->prepare("SELECT analyst_id FROM analyst_sso_identities WHERE provider_id = ? AND subject = ?");
    $stmt->execute([$providerId, $subject]);
    $analystId = $stmt->fetchColumn();

    if ($analystId) {
        $analystId = (int)$analystId;
        $stmt = $conn->prepare("SELECT * FROM analysts WHERE id = ?");
        $stmt->execute([$analystId]);
        $analyst = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$analyst || (int)$analyst['is_active'] !== 1) {
            return ['ok' => false, 'error' => 'Your account is inactive. Contact an administrator.'];
        }
        if ((int)($analyst['auth_provider_id'] ?? 0) !== $providerId) {
            return ['ok' => false, 'error' => 'Your account is not assigned to this sign-in method.'];
        }
        // Keep the cached email fresh — people get renamed in the directory.
        $conn->prepare("UPDATE analyst_sso_identities SET last_login_datetime = UTC_TIMESTAMP(), email = ? WHERE provider_id = ? AND subject = ?")
             ->execute([$email ?: null, $providerId, $subject]);
        if ($email !== '' && strtolower((string)$analyst['email']) !== $email) {
            $conn->prepare("UPDATE analysts SET email = ? WHERE id = ?")->execute([$email, $analystId]);
        }
        return ['ok' => true, 'analyst_id' => $analystId];
    }

    // 2) Match an existing analyst by email.
    $analyst = null;
    if ($email !== '') {
        $stmt = $conn->prepare("SELECT * FROM analysts WHERE LOWER(email) = ? LIMIT 1");
        $stmt->execute([$email]);
        $analyst = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($analyst) {
        $analystId = (int)$analyst['id'];
        if ((int)$analyst['is_active'] !== 1) {
            return ['ok' => false, 'error' => 'Your account is inactive. Contact an administrator.'];
        }
        // Strict isolation, exactly as OIDC: an analyst who is local-only or
        // belongs to another provider is NOT silently claimed by this one.
        if ((int)($analyst['auth_provider_id'] ?? 0) !== $providerId) {
            return ['ok' => false, 'error' => 'This account is not set up to sign in with this directory.'];
        }
    } else {
        // 3) Just-in-time create.
        if ((int)$provider['auto_create_users'] !== 1) {
            return ['ok' => false, 'error' => 'No FreeITSM account exists for that user. Ask an administrator to create one.'];
        }
        // No email is allowed: plenty of directories hold staff who were never
        // given a mailbox. The bind already proved who they are, so there is
        // nothing an address would add. Safe because analysts.email is NOT NULL
        // but NOT unique (so '' may repeat), and step 2 above skips the
        // match-by-email branch when $email is '' — otherwise the second
        // mailbox-less starter would match the first and log in as them.
        $analystId = ldapCreateAnalyst(
            $conn, $providerId,
            (string)$ldapUser['username'], (string)$ldapUser['name'], $email,
            $provider['default_modules']
        );
    }

    // Link the directory identity for next time.
    try {
        $conn->prepare(
            "INSERT INTO analyst_sso_identities (analyst_id, provider_id, subject, email, linked_datetime, last_login_datetime)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$analystId, $providerId, $subject, $email ?: null]);
    } catch (Exception $e) {
        // uq_sso_provider_analyst can trip if the analyst was linked concurrently.
        // The analyst is resolved either way, so this is not fatal.
    }

    return ['ok' => true, 'analyst_id' => $analystId];
}

/**
 * Map a directory user to a SELF-SERVICE PORTAL account (`users`), creating one
 * if needed. The portal twin of ldapResolveAnalyst().
 *
 * Returns ['ok' => true, 'user_id' => int] or ['ok' => false, 'error' => string].
 *
 * THREE DELIBERATE DIVERGENCES FROM THE ANALYST VERSION
 * -----------------------------------------------------
 * 1. 🔑 **No mailbox means NULL, never `''`.** The analyst side stores `''`
 *    (#872) and gets away with it only because `analysts.email` is not unique.
 *    `users.email` IS unique, so the second mailbox-less warehouse worker would
 *    collide on the first. MySQL allows many NULLs in a unique index, so NULL is
 *    the representation that actually scales. Anything writing `''` here
 *    re-introduces the bug.
 *
 * 2. 🔑 **An unclaimed existing account is CLAIMED, not refused.** The analyst
 *    version rejects a local-only match as strict isolation. The portal cannot:
 *    `users` rows are auto-created without a password all over the product —
 *    inbound email, web chat, WhatsApp, workflows, the API — so the person
 *    signing in for the first time usually ALREADY has a row holding their
 *    ticket history. Refusing would strand them beside their own tickets. This
 *    matches what portal OIDC already does. An account belonging to a DIFFERENT
 *    provider is still refused.
 *
 * 3. 🔑 **The company comes from the provider first.** A directory owned by a
 *    company vouches for its people, which is the only thing that works for a
 *    user with no address to derive a domain from — precisely the people this
 *    exists for. Domain matching is the fallback for everyone else.
 */
function ldapResolveUser(PDO $conn, array $provider, array $ldapUser): array {
    $providerId = (int)$provider['id'];
    $subject    = (string)$ldapUser['guid'];
    $email      = strtolower(trim((string)$ldapUser['email']));
    $username   = trim((string)$ldapUser['username']);
    $name       = trim((string)$ldapUser['name']);

    // See divergence 1. Everything below passes $emailOrNull to the database.
    $emailOrNull = ($email !== '') ? $email : null;

    // 1) Existing directory link.
    $stmt = $conn->prepare("SELECT user_id FROM user_sso_identities WHERE provider_id = ? AND subject = ?");
    $stmt->execute([$providerId, $subject]);
    $userId = $stmt->fetchColumn();

    if ($userId) {
        $userId = (int)$userId;
        $stmt = $conn->prepare("SELECT id, auth_provider_id, email FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return ['ok' => false, 'error' => 'Your account could not be found. Contact your service desk.'];
        }
        if ((int)($user['auth_provider_id'] ?? 0) !== $providerId) {
            return ['ok' => false, 'error' => 'Your account is not assigned to this sign-in method.'];
        }
        // People get renamed and given mailboxes; keep the cached copies fresh.
        $conn->prepare(
            "UPDATE user_sso_identities SET last_login_datetime = UTC_TIMESTAMP(), email = ?
              WHERE provider_id = ? AND subject = ?"
        )->execute([$emailOrNull, $providerId, $subject]);

        if ($emailOrNull !== null && strtolower((string)$user['email']) !== $email) {
            $conn->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$emailOrNull, $userId]);
        }
        return ['ok' => true, 'user_id' => $userId];
    }

    // 2) Match an existing account by email.
    //
    // ⚠️ Guarded on a non-empty address. Without this, a mailbox-less user would
    // match every OTHER mailbox-less user and sign in as them — the same trap
    // the analyst version documents, and the reason NULL beats ''. (A NULL
    // parameter would never match in SQL anyway; the guard makes the intent
    // explicit rather than relying on that.)
    $user = null;
    if ($emailOrNull !== null) {
        $stmt = $conn->prepare("SELECT id, auth_provider_id FROM users WHERE LOWER(email) = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($user) {
        $userId   = (int)$user['id'];
        $assigned = (int)($user['auth_provider_id'] ?? 0);

        if ($assigned === 0) {
            // See divergence 2 — claim it, don't strand them.
            $conn->prepare("UPDATE users SET auth_provider_id = ? WHERE id = ?")->execute([$providerId, $userId]);
        } elseif ($assigned !== $providerId) {
            return ['ok' => false, 'error' => 'This account is not set up to sign in with this directory.'];
        }

        // Backfill a sign-in name if the row predates the directory link.
        if ($username !== '') {
            try {
                $conn->prepare("UPDATE users SET username = ? WHERE id = ? AND (username IS NULL OR username = '')")
                     ->execute([$username, $userId]);
            } catch (Exception $e) {
                // uq_users_username — another directory already uses that name.
                // Harmless: they are identified by the link from here on.
            }
        }
    } else {
        // 3) Just-in-time create.
        if ((int)$provider['auto_create_users'] !== 1) {
            return ['ok' => false, 'error' => 'No account exists for you here. Ask your service desk to create one.'];
        }

        // See divergence 3. A company-owned directory vouches for its people;
        // only fall back to the address domain when the provider is global.
        $tenantId = null;
        try {
            require_once __DIR__ . '/tenancy.php';
            $tenantId = ($provider['tenant_id'] !== null)
                ? (int)$provider['tenant_id']
                : ($emailOrNull !== null ? resolveTenantForNewUser($conn, $email) : null);
        } catch (Exception $e) { /* leave NULL → triage, never a wrong company */ }

        // display_name falls back through the directory name, then the sign-in
        // name, then the address — never blank, because this is what an analyst
        // sees on the ticket. NOTE password_hash is left NULL on purpose: a
        // placeholder hash would make the local login path treat a directory
        // user as having a password of their own.
        $display = $name !== '' ? $name : ($username !== '' ? $username : (string)$email);

        try {
            $ins = $conn->prepare(
                "INSERT INTO users (email, username, display_name, auth_provider_id, tenant_id, created_at)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
            );
            $ins->execute([$emailOrNull, ($username !== '' ? $username : null), $display, $providerId, $tenantId]);
            $userId = (int)$conn->lastInsertId();
        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Your account could not be created. Contact your service desk.'];
        }
    }

    // Link the directory identity for next time.
    try {
        $conn->prepare(
            "INSERT INTO user_sso_identities (user_id, provider_id, subject, email, linked_datetime, last_login_datetime)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$userId, $providerId, $subject, $emailOrNull]);
    } catch (Exception $e) {
        // uq_user_sso_provider_user can trip on a concurrent link; resolved anyway.
    }

    return ['ok' => true, 'user_id' => $userId];
}

/**
 * Admin "Test" helper: prove the service account can connect, bind and search.
 * Optionally also verify one real user's credentials.
 * Returns ['ok' => bool, 'error' => ?string, 'found' => ?array].
 */
function ldapTestConnection(array $provider, string $login = '', string $password = ''): array {
    try {
        $ds = ldapOpen($provider);
        ldapBindService($ds, $provider);
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    // Service bind worked. If no sample user given, just prove the base DN reads.
    if ($login === '') {
        try {
            $r = @ldap_search($ds, trim((string)$provider['ldap_base_dn']), '(objectClass=*)', ['dn'], 0, 1, LDAP_TIMEOUT_SECONDS);
            if ($r === false) {
                throw new Exception('Connected and bound, but the base DN could not be read: '
                    . ldap_error($ds) . '. This is usually the service account\'s permissions.');
            }
        } catch (Exception $e) {
            @ldap_unbind($ds);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        @ldap_unbind($ds);
        return ['ok' => true, 'error' => null, 'found' => null];
    }
    @ldap_unbind($ds);

    $res = ldapAuthenticate($provider, $login, $password);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error']];
    return ['ok' => true, 'error' => null, 'found' => $res['user']];
}
