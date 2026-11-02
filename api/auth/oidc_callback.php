<?php
/**
 * SSO login callback.
 * GET ?code=...&state=...   (the provider redirects the browser here)
 *
 * Completes the OpenID Connect login:
 *   1. validate `state` (CSRF), exchange the code for tokens (PKCE),
 *   2. validate the ID token (signature/JWKS, issuer, audience, nonce),
 *   3. resolve the analyst: existing link (provider+sub) -> email match ->
 *      just-in-time create (if the provider allows it),
 *   4. enforce STRICT isolation: an analyst may only sign in via the provider
 *      they're assigned to,
 *   5. set the session directly (SSO users skip the local TOTP/MFA step).
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/oidc.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/landing.php';   // re-issue the landing cookie on login (#63)

/**
 * Bounce back to the originating portal's login page with an error message.
 *
 * @param string $msg  the error shown on the login page, in plain text
 */
function ssoBail(string $msg): void {
    $loginPath = (($_SESSION['oidc_portal'] ?? 'analyst') === 'self-service')
        ? 'self-service/login.php' : 'login.php';
    // Clear any in-flight OIDC state so a retry starts clean.
    unset($_SESSION['oidc_state'], $_SESSION['oidc_nonce'],
          $_SESSION['oidc_code_verifier'], $_SESSION['oidc_provider_id'],
          $_SESSION['oidc_portal']);
    $_SESSION['sso_error'] = $msg;
    header('Location: ' . BASE_URL . $loginPath);
    exit;
}

// --- Fallback confirmation from auth/sso_confirm_portal.php ---
if (isset($_POST['action']) && $_POST['action'] === 'confirm_portal_proceed') {
    $csrf = $_POST['csrf'] ?? '';
    if (empty($csrf) || empty($_SESSION['sso_portal_csrf']) || !hash_equals($_SESSION['sso_portal_csrf'], $csrf)) {
        ssoBail('Security check failed (CSRF mismatch). Please try signing in again.');
    }
    $pending = $_SESSION['sso_pending_portal'] ?? null;
    unset($_SESSION['sso_pending_portal'], $_SESSION['sso_portal_csrf']);
    // The same ten minutes a code-exchange round trip would reasonably take: a
    // confirm screen left open on a shared PC should not work the next morning.
    if (!$pending || empty($pending['provider_id'])
        || (time() - (int)($pending['created'] ?? 0)) > 600) {
        ssoBail('Session expired. Please try signing in again.');
    }
    try {
        $conn = connectToDatabase();
        $st = $conn->prepare('SELECT * FROM auth_providers WHERE id = ? AND enabled = 1');
        $st->execute([(int)$pending['provider_id']]);
        $prov = $st->fetch(PDO::FETCH_ASSOC);
        if (!$prov) {
            ssoBail('Sign-in provider is no longer available.');
        }
        completeSelfServiceSso(
            $conn,
            $prov,
            (int)$pending['provider_id'],
            (string)$pending['sub'],
            (string)$pending['email'],
            (bool)$pending['email_verified'],
            (string)$pending['name'],
            (array)$pending['tokens'],
            (array)($pending['claims'] ?? [])
        );
    } catch (Exception $e) {
        require_once __DIR__ . '/../../includes/db_errors.php';
        ssoBail('Sign-in failed: ' . dbSafeClientMessage($e, 'a server error. The details are in the server log.'));
    }
}

// Provider-side error (e.g. user cancelled).
if (isset($_GET['error'])) {
    ssoBail('Sign-in was cancelled or failed: ' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']));
}

// --- CSRF: state must match what we issued ---
$state = $_GET['state'] ?? '';
if ($state === '' || empty($_SESSION['oidc_state']) || !hash_equals($_SESSION['oidc_state'], $state)) {
    ssoBail('Security check failed (state mismatch). Please try signing in again.');
}

$code         = $_GET['code'] ?? '';
$nonce        = $_SESSION['oidc_nonce'] ?? '';
$codeVerifier = $_SESSION['oidc_code_verifier'] ?? '';
$providerId   = (int)($_SESSION['oidc_provider_id'] ?? 0);
if ($code === '' || $nonce === '' || $codeVerifier === '' || $providerId <= 0) {
    ssoBail('Sign-in session expired. Please try again.');
}

try {
    $conn = connectToDatabase();

    $provider = oidcGetProvider($conn, $providerId);
    if (!$provider || (int)$provider['enabled'] !== 1) {
        ssoBail('That identity provider is no longer available.');
    }

    $disco  = oidcDiscover($provider['issuer_url']);
    $tokens = oidcExchangeCode($provider, $disco, $code, $codeVerifier);
    $claims = oidcValidateIdToken($tokens['id_token'], $disco, $provider, $nonce);

    // Profile sync (discussion #155) can top the claims up from the userinfo
    // endpoint, for IdPs that leave job title / department out of the ID token.
    //  - Only when sync is on: the result feeds nothing else, so with sync off
    //    it would be an extra round trip on every sign-in for no reason.
    //  - The userinfo `sub` MUST match the ID token's, or the response is
    //    thrown away (OpenID Connect Core 1.0 §5.3.2).
    //  - array_merge() puts the ID token LAST, so a signed claim is never
    //    overwritten by an unsigned one.
    //  - Also when a GROUP CLAIM is set (3.5.0): many IdPs put groups or roles
    //    in userinfo rather than the ID token.
    require_once __DIR__ . '/../../includes/sso_claims.php';
    $groupClaim = ssoClaimsReady($conn) ? trim((string)($provider['oidc_group_claim'] ?? '')) : '';
    if ((($provider['profile_sync_mode'] ?? 'never') !== 'never' || $groupClaim !== '')
        && !empty($disco['userinfo_endpoint']) && !empty($tokens['access_token'])) {
        $userInfo = oidcFetchUserInfo($disco['userinfo_endpoint'], $tokens['access_token']);
        if (!empty($userInfo) && isset($userInfo['sub'], $claims['sub'])
            && hash_equals((string)$claims['sub'], (string)$userInfo['sub'])) {
            $claims = array_merge($userInfo, $claims);
        }
    }

    // One-time use: invalidate the stashed tokens now that they're consumed.
    unset($_SESSION['oidc_state'], $_SESSION['oidc_nonce'],
          $_SESSION['oidc_code_verifier'], $_SESSION['oidc_provider_id']);

    // --- Groups (claim mapping, 3.5.0) ---
    // Read once; remembered so System -> SSO can show what the IdP actually sends.
    $ssoGroups = $groupClaim !== '' ? ssoClaimValues($claims, $groupClaim) : [];
    $claimsAllowAnalyst = true;
    if ($groupClaim !== '') {
        ssoRememberGroups($conn, $providerId, (string)($claims['email'] ?? $claims['preferred_username'] ?? $claims['sub'] ?? ''), $ssoGroups);
        $analystGroup = trim((string)($provider['oidc_analyst_group'] ?? ''));
        $claimsAllowAnalyst = $analystGroup === '' || ssoInGroup($ssoGroups, $analystGroup);
    }

    // --- Identity claims ---
    $sub           = $claims['sub'] ?? '';
    $email         = strtolower(trim($claims['email'] ?? ''));
    // email_verified handling. IdPs differ: Keycloak/Entra send the claim
    // (true/false); Okta's org authorization server omits it entirely.
    //  - An explicit `false` is ALWAYS rejected.
    //  - A MISSING claim is accepted by default, but a provider can be set to
    //    require an explicit verified-email claim (require_verified_email) for
    //    IdPs that permit unverified self-registration.
    if (array_key_exists('email_verified', $claims)) {
        $emailVerified = ($claims['email_verified'] === true || $claims['email_verified'] === 'true');
    } else {
        $emailVerified = (int)($provider['require_verified_email'] ?? 0) !== 1;
    }
    $name          = $claims['name']
                     ?? trim(($claims['given_name'] ?? '') . ' ' . ($claims['family_name'] ?? ''))
                     ?: ($claims['preferred_username'] ?? $email);
    $preferredUser = $claims['preferred_username'] ?? ($email ?: $sub);
    if ($sub === '') {
        ssoBail('The provider did not return a user identifier.');
    }

    // --- Self-service portal branches off here (resolve against `users`) ---
    if (($_SESSION['oidc_portal'] ?? 'analyst') === 'self-service') {
        completeSelfServiceSso($conn, $provider, $providerId, $sub, $email, $emailVerified, $name, $tokens, $claims);
        // (function sets the session, redirects and exits)
    }

    // --- 1) Existing link by (provider, sub) ---
    $stmt = $conn->prepare(
        "SELECT analyst_id FROM analyst_sso_identities WHERE provider_id = ? AND subject = ?"
    );
    $stmt->execute([$providerId, $sub]);
    $analystId = $stmt->fetchColumn();

    // A link whose analyst has been deleted counts as no link at all — see
    // ssoClearDanglingLink(). Fall through to the email match / JIT path.
    $analyst = $analystId ? oidcLoadAnalyst($conn, (int)$analystId) : null;
    if ($analystId && !$analyst) {
        ssoClearDanglingLink($conn, 'analyst_sso_identities', $providerId, $sub);
        $analystId = false;
    }

    if ($analystId) {
        $analystId = (int)$analystId;
        if ((int)$analyst['is_active'] !== 1) {
            ssoBail('Your account is inactive. Contact an administrator.');
        }
        // Strict isolation: must still be assigned to this provider.
        if ((int)($analyst['auth_provider_id'] ?? 0) !== $providerId) {
            ssoBail('Your account is not assigned to this sign-in method.');
        }
        $conn->prepare("UPDATE analyst_sso_identities SET last_login_datetime = UTC_TIMESTAMP(), email = ? WHERE provider_id = ? AND subject = ?")
             ->execute([$email ?: null, $providerId, $sub]);
    } else {
        // --- 2) Match an existing analyst by email ---
        $analyst = null;
        if ($email !== '') {
            if (!$emailVerified) {
                ssoBail('Your email is not verified with the identity provider.');
            }
            $stmt = $conn->prepare("SELECT * FROM analysts WHERE LOWER(email) = ? LIMIT 1");
            $stmt->execute([$email]);
            $analyst = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($analyst) {
            $analystId = (int)$analyst['id'];
            if ((int)$analyst['is_active'] !== 1) {
                ssoBail('Your account is inactive. Contact an administrator.');
            }
            // Strict isolation: the analyst must be assigned to THIS provider.
            if ((int)($analyst['auth_provider_id'] ?? 0) !== $providerId) {
                ssoBail('This account is not set up to sign in with this provider.');
            }
        } else {
            // --- 3) No analyst account: create one (JIT), or apply the fallback ---
            // ⚠️ Before Database Verification has added the discussion #155
            // columns, the row has neither. Behave exactly as before the split:
            // the old single switch decides, and anyone else is refused.
            $autoAnalysts = array_key_exists('auto_create_analysts', $provider)
                ? (int)$provider['auto_create_analysts'] === 1
                : (int)($provider['auto_create_users'] ?? 0) === 1;
            // Claim mapping: nobody outside the analyst group is created (3.5.0).
            if ($autoAnalysts && $claimsAllowAnalyst) {
                if ($email === '') {
                    ssoBail('Cannot auto-create an account without an email from the provider.');
                }
                $analystId = oidcCreateAnalyst($conn, $providerId, $preferredUser, $name, $email, $provider['default_modules'], $claims, $provider['profile_sync_mode'] ?? 'never');
                $analyst   = oidcLoadAnalyst($conn, $analystId);
            } else {
                $fallbackMode = $provider['analyst_fallback_mode'] ?? 'block';
                if ($fallbackMode === 'block') {
                    ssoBail('No analyst account exists for ' . ($email ?: 'this user') . '. Ask an administrator to create one.');
                } elseif ($fallbackMode === 'redirect') {
                    completeSelfServiceSso($conn, $provider, $providerId, $sub, $email, $emailVerified, $name, $tokens, $claims);
                } else {
                    // 'confirm': ask before switching to the self-service portal.
                    // The IdP has already proved who they are, so what is parked
                    // here is the verified result, not a way to skip the proof.
                    // Only the ID token is kept (logout needs it as a hint) -
                    // there is no reason to hold the access or refresh token.
                    $_SESSION['sso_portal_csrf'] = bin2hex(random_bytes(16));
                    $_SESSION['sso_pending_portal'] = [
                        'provider_id'    => $providerId,
                        'sub'            => $sub,
                        'email'          => $email,
                        'email_verified' => $emailVerified,
                        'name'           => $name,
                        'tokens'         => ['id_token' => $tokens['id_token']],
                        'claims'         => $claims,
                        'created'        => time(),
                    ];
                    header('Location: ' . BASE_URL . 'auth/sso_confirm_portal.php');
                    exit;
                }
            }
        }

        // Link this IdP identity to the analyst for next time.
        $conn->prepare(
            "INSERT INTO analyst_sso_identities (analyst_id, provider_id, subject, email, linked_datetime, last_login_datetime)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$analystId, $providerId, $sub, $email ?: null]);
    }

    // Claim mapping (3.5.0): an existing analyst who is no longer in the analyst
    // group is refused - LDAP's rule. Their account is left as it is.
    if (!$claimsAllowAnalyst) {
        ssoBail('Your account is not in the group that gives analyst access. Ask an administrator, or use the self-service portal.');
    }

    // Every path above has now passed its access checks, so it is safe to write.
    oidcSyncProfile($conn, $provider, 'analysts', $analyst, $claims, $name);

    // SSO groups (3.5.0): mapped teams and the admin flag follow the groups.
    if ($groupClaim !== '') {
        ssoApplyGroupAccess($conn, $provider, (int)$analystId, $ssoGroups);
    }
    $analyst = oidcLoadAnalyst($conn, $analystId);   // pick up any new full_name for the session

    // --- Success: set the session directly (SSO bypasses local MFA) ---
    $conn->prepare("UPDATE analysts SET last_login_datetime = UTC_TIMESTAMP(), failed_login_count = 0 WHERE id = ?")
         ->execute([$analystId]);

    sessionPromoteToAuthenticated();   // rotate the session id — see includes/session_security.php
    $_SESSION['analyst_id']       = $analystId;
    $_SESSION['analyst_username'] = $analyst['username'];
    $_SESSION['analyst_name']     = $analyst['full_name'];
    $_SESSION['analyst_email']    = $analyst['email'];
    $_SESSION['allowed_modules']  = getAnalystAllowedModules($conn, $analystId);
    // Landing preference follows the person, not the browser (#63).
    landingRefreshCookieFromPreference($conn, (int)$analystId);
    // Remember the SSO context so logout can also end the session at the IdP.
    $_SESSION['sso_provider_id']  = $providerId;
    $_SESSION['sso_id_token']     = $tokens['id_token'];
    unset($_SESSION['oidc_portal']);

    // ⚠️ Honour must_change_password here too, or SSO is the one door around the gate.
    // It reads oddly at first — the analyst just proved themselves at the IdP, so why
    // ask for a password? Because the flag is not about this sign-in: it is set on an
    // account whose LOCAL password is the published default, and that password still
    // works at auth/login.php no matter how they arrived this time. Signing in another
    // way does not make admin/freeitsm any less valid.
    //
    // Only the explicit flag, never the expiry POLICY: an SSO account may have no local
    // password to expire, and login.php makes the same distinction for LDAP via its
    // $skipPasswordExpiry. See the comment above loginRequiresPasswordChange().
    if (!empty($analyst['must_change_password'])) {
        $_SESSION['password_expired'] = true;
    }

    header('Location: ' . BASE_URL);
    exit;

} catch (Exception $e) {
    require_once __DIR__ . '/../../includes/db_errors.php';
    ssoBail('Sign-in failed: ' . dbSafeClientMessage($e, 'a server error. The details are in the server log.'));
}

// --------------------------------------------------------------------------

/**
 * Loads one analyst row by id, or null when there is none.
 *
 * @param int $id  the analysts.id to load
 */
function oidcLoadAnalyst(PDO $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM analysts WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Loads one self-service user row by id, or null when there is none.
 *
 * @param int $id  the users.id (requester) to load
 */
function ssLoadUser(PDO $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Copy profile details from the identity provider onto a local account.
 *
 * 🔴 Call this ONLY after the account has passed every access check (active,
 * assigned to this provider). Writing first and refusing afterwards lets a
 * provider that is about to be turned away edit someone else's record.
 *
 * Only runs when the provider's profile_sync_mode is 'always'. 'initial' is
 * handled at creation time (oidcCreateAnalyst / the requester INSERT), and
 * 'never' does nothing.
 *
 * @param array $provider  the sso_providers row the user signed in with; only profile_sync_mode is read
 * @param string $table 'analysts' or 'users' - never user input
 * @param array $record  the local account row as loaded (analysts or users), including id and the current profile values
 * @param array $claims  the validated ID token claims, topped up from userinfo when sync is on
 * @param string $name  the display name worked out from the claims; empty means leave the name alone
 */
function oidcSyncProfile(PDO $conn, array $provider, string $table, array $record, array $claims, string $name): void {
    if (($provider['profile_sync_mode'] ?? 'never') !== 'always') return;

    if ($table === 'analysts') {
        $nameCol = 'full_name';
        $cols    = ['job_title', 'department', 'phone', 'mobile'];            // analysts has no office column
        $stamp   = ', last_modified_datetime = UTC_TIMESTAMP()';
    } elseif ($table === 'users') {
        $nameCol = 'display_name';
        $cols    = ['job_title', 'department', 'office', 'phone', 'mobile'];
        $stamp   = '';
    } else {
        return;
    }

    $attrs   = oidcExtractProfileAttributes($claims);
    $updates = [];
    $params  = [];

    // A requester's own preferred_name wins over the directory's display name.
    $nameLocked = ($table === 'users' && !empty($record['preferred_name']));
    if ($name !== '' && !$nameLocked && ($record[$nameCol] ?? null) !== $name) {
        $updates[] = "$nameCol = ?";
        $params[]  = $name;
    }

    foreach ($cols as $col) {
        if (!empty($attrs[$col]) && ($record[$col] ?? null) !== $attrs[$col]) {
            $updates[] = "$col = ?";
            $params[]  = $attrs[$col];
        }
    }

    if (!$updates) return;

    $params[] = (int)$record['id'];
    $conn->prepare("UPDATE $table SET " . implode(', ', $updates) . "$stamp WHERE id = ?")
         ->execute($params);
}

/**
 * Pull the five profile fields out of OIDC claims. Each IdP names them its own
 * way (Entra `jobTitle`/`businessPhones`, Okta `title`, Keycloak `job_title`,
 * the standard `phone_number`), so each field tries the known spellings in
 * turn. Values are trimmed and cut to the column length; missing = null.
 *
 * @param array $claims  the OIDC claims (ID token merged with userinfo), keyed by claim name
 */
function oidcExtractProfileAttributes(array $claims): array {
    $clean = function ($val, int $max = 100) {
        if ($val === null || $val === "") return null;
        if (is_array($val)) $val = implode(", ", array_filter($val));
        $s = trim((string)$val);
        return $s === "" ? null : mb_substr($s, 0, $max);
    };

    // Job Title
    $jobTitle = $claims["job_title"] ?? $claims["jobTitle"] ?? $claims["title"] ?? $claims["jobtitle"] ?? null;

    // Department
    $department = $claims["department"] ?? $claims["departmentName"] ?? $claims["dept"] ?? null;

    // Office
    $office = $claims["office"] ?? $claims["officeLocation"] ?? $claims["physicalDeliveryOfficeName"] ?? $claims["location"] ?? null;
    if (!$office && isset($claims["address"]) && is_array($claims["address"])) {
        $office = $claims["address"]["locality"] ?? null;
    }

    // Phone & Mobile
    $phone = $claims["phone_number"] ?? $claims["telephoneNumber"] ?? $claims["phone"] ?? (isset($claims["businessPhones"][0]) ? $claims["businessPhones"][0] : ($claims["businessPhones"] ?? null)) ?? $claims["telephonenumber"] ?? null;
    $mobile = $claims["mobile"] ?? $claims["mobilePhone"] ?? $claims["mobile_phone"] ?? (isset($claims["mobilePhones"][0]) ? $claims["mobilePhones"][0] : ($claims["mobilePhones"] ?? null)) ?? $claims["mobilephone"] ?? null;

    return [
        "job_title"  => $clean($jobTitle, 100),
        "department" => $clean($department, 100),
        "office"     => $clean($office, 100),
        "phone"      => $clean($phone, 50),
        "mobile"     => $clean($mobile, 50),
    ];
}

/**
 * Complete an SSO sign-in for the SELF-SERVICE portal.
 *
 * Mirrors the analyst flow against the requester `users` table:
 *   1) existing link by (provider, sub),
 *   2) match an existing requester by verified email,
 *   3) just-in-time create (if the provider allows it).
 * Then sets the self-service session (SSO bypasses local TOTP) and lands in
 * the portal. Never returns — it redirects and exits.
 *
 * Also reached from the ANALYST login, when someone with no analyst account
 * is sent on to the portal (the provider's analyst_fallback_mode, #155).
 *
 * Deliberate difference from analysts: a requester matched by verified email
 * who is still UNASSIGNED is auto-claimed onto this provider, rather than
 * rejected. Requesters are low-privilege and self-onboarding (a ticket-created
 * contact starts passwordless and unassigned), so we don't require an admin to
 * pre-enrol every customer. An already-assigned requester is still strictly
 * isolated to their own provider.
 *
 * @param array $provider  the sso_providers row being signed in with
 * @param int $providerId  that provider's id
 * @param string $sub  the user's subject (sub claim) at the identity provider - never empty here
 * @param string $email  the email claim, lower-cased and trimmed; empty when the provider sent none
 * @param bool $emailVerified  true when the provider vouches for the email (or the claim is missing and not required)
 * @param string $name  the display name worked out from the claims
 * @param array $tokens  the token endpoint response; id_token is kept in the session for single logout
 * @param array $claims  the validated claims, used for profile sync
 */
function completeSelfServiceSso(PDO $conn, array $provider, int $providerId, string $sub, string $email, bool $emailVerified, string $name, array $tokens, array $claims = []): void {
    // From here on this is a portal sign-in, so any error goes back to the
    // portal's login page - including when we arrived from the analyst one.
    $_SESSION['oidc_portal'] = 'self-service';
    // --- 1) Existing link by (provider, sub) ---
    $stmt = $conn->prepare("SELECT user_id FROM user_sso_identities WHERE provider_id = ? AND subject = ?");
    $stmt->execute([$providerId, $sub]);
    $userId = $stmt->fetchColumn();

    // A link whose requester has been deleted counts as no link at all — see
    // ssoClearDanglingLink(). Fall through to the email match / JIT path.
    $user = $userId ? ssLoadUser($conn, (int)$userId) : null;
    if ($userId && !$user) {
        ssoClearDanglingLink($conn, 'user_sso_identities', $providerId, $sub);
        $userId = false;
    }

    // --- 1b) Somebody Entra directory sync imported (includes/entra_sync.php) ---
    // Graph knows a person by object id, Entra sign-in by a PAIRWISE `sub`, so
    // the import records the object id in source_ref under a placeholder
    // subject. The `oid` claim finds that row, and the real `sub` replaces the
    // placeholder so step 1 finds them directly from now on. Only the placeholder:
    // a real subject is a link somebody already made, see oidcLinkRequester().
    $oid = strtolower(trim((string)($claims['oid'] ?? '')));
    if (!$userId && $oid !== '') {
        $stmt = $conn->prepare("SELECT id, user_id FROM user_sso_identities WHERE provider_id = ? AND source_ref = ? AND subject = ? LIMIT 1");
        $stmt->execute([$providerId, $oid, 'entra:' . $oid]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        $linked = $link ? ssLoadUser($conn, (int)$link['user_id']) : null;
        if ($linked) {
            $conn->prepare("UPDATE user_sso_identities SET subject = ? WHERE id = ?")->execute([$sub, (int)$link['id']]);
            $userId = (int)$link['user_id'];
            $user   = $linked;
        }
    }

    if ($userId) {
        $userId = (int)$userId;
        // Strict isolation: must still be assigned to this provider.
        if ((int)($user['auth_provider_id'] ?? 0) !== $providerId) {
            ssoBail('Your account is not assigned to this sign-in method.');
        }
        $conn->prepare("UPDATE user_sso_identities SET last_login_datetime = UTC_TIMESTAMP(), email = ? WHERE provider_id = ? AND subject = ?")
             ->execute([$email ?: null, $providerId, $sub]);
    } else {
        // --- 2) Match an existing requester by verified email ---
        $user = null;
        if ($email !== '') {
            if (!$emailVerified) {
                ssoBail('Your email is not verified with the identity provider.');
            }
            $stmt = $conn->prepare("SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($user) {
            $userId   = (int)$user['id'];
            $assigned = (int)($user['auth_provider_id'] ?? 0);
            if ($assigned === 0) {
                // Auto-claim an unassigned requester onto this provider.
                $conn->prepare("UPDATE users SET auth_provider_id = ? WHERE id = ?")->execute([$providerId, $userId]);
                $user['auth_provider_id'] = $providerId;
            } elseif ($assigned !== $providerId) {
                ssoBail('This account is not set up to sign in with this provider.');
            }
        } else {
            // --- 3) Just-in-time provisioning (only if the provider allows it) ---
            if ((int)$provider['auto_create_users'] !== 1) {
                ssoBail('No self-service account exists for ' . ($email ?: 'this user') . '. Raise a ticket or register first.');
            }
            if ($email === '') {
                ssoBail('Cannot create an account without an email from the provider.');
            }
            // A provider pinned to a company vouches for whoever it signs in, so it
            // outranks the email domain; an unpinned (shared) provider falls back
            // to the domain, and to blank if that proves nothing.
            $jitTenantId = !empty($provider['tenant_id'])
                ? (int)$provider['tenant_id']
                : resolveTenantForNewUser($conn, $email);

            $syncMode  = $provider["profile_sync_mode"] ?? "never";
            $profAttrs = ($syncMode !== "never")
                ? oidcExtractProfileAttributes($claims)
                : ["job_title" => null, "department" => null, "office" => null, "phone" => null, "mobile" => null];
            $stmt = $conn->prepare(
                "INSERT INTO users (email, display_name, job_title, department, office, phone, mobile, auth_provider_id, tenant_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
            );
            $stmt->execute([
                $email, $name ?: $email,
                $profAttrs["job_title"], $profAttrs["department"], $profAttrs["office"],
                $profAttrs["phone"], $profAttrs["mobile"],
                $providerId, $jitTenantId
            ]);
            $userId = (int)$conn->lastInsertId();
            $user   = ssLoadUser($conn, $userId);
        }

        // Link this IdP identity to the requester for next time.
        if (!oidcLinkRequester($conn, $providerId, (int)$userId, $sub, $email, $oid)) {
            ssoBail('This account is already linked to a different sign-in. Contact the service desk.');
        }
    }

    // Every path above has now passed its access checks, so it is safe to write.
    oidcSyncProfile($conn, $provider, 'users', $user, $claims, $name);
    $user = ssLoadUser($conn, $userId);

    // Marked as left (3.5.0): the identity provider may still say yes; FreeITSM does not.
    if (!requesterIsActive($conn, (int)$userId)) {
        ssoBail('Your account is no longer active. Contact the service desk if you think this is a mistake.');
    }

    // --- Success: set the self-service session (SSO bypasses local TOTP) ---
    $displayName = $user['preferred_name'] ?: $user['display_name'] ?: $user['email'];
    sessionPromoteToAuthenticated();   // rotate the session id — see includes/session_security.php
    $_SESSION['ss_user_id']    = $userId;
    $_SESSION['ss_user_email'] = $user['email'];
    $_SESSION['ss_user_name']  = $displayName;
    // Remember the SSO context so logout can also end the session at the IdP.
    $_SESSION['ss_sso_provider_id'] = $providerId;
    $_SESSION['ss_sso_id_token']    = $tokens['id_token'];
    unset($_SESSION['oidc_portal']);

    header('Location: ' . BASE_URL . 'self-service/index.php');
    exit;
}

/**
 * Create a new analyst from SSO claims, assigned to the given provider.
 * The local password is set to an unusable random hash (SSO users sign in
 * via the IdP, not a local password).
 *
 * @param int $providerId  the SSO provider the new analyst is assigned to
 * @param string $preferredUser  the preferred_username claim (or email/sub), the base for the new username
 * @param string $name  the display name; empty falls back to the username
 * @param string $email  the email claim, lower-cased
 * @param ?string $defaultModules  the provider's comma-separated module keys; null or blank means every module
 * @param array $claims  the validated claims, read for job title, department and phones
 * @param string $syncMode  the provider's profile_sync_mode - 'never' leaves the profile fields empty
 */
function oidcCreateAnalyst(PDO $conn, int $providerId, string $preferredUser, string $name, string $email, ?string $defaultModules, array $claims = [], string $syncMode = 'never'): int {
    // Derive a unique username from the preferred username / email local-part.
    $base = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '', $preferredUser ?: explode('@', $email)[0]));
    if ($base === '') $base = 'ssouser';
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

    // 🔴 Must be 0 when a module list is configured — see the same note in
    // ldapCreateAnalyst() (includes/ldap.php). `can_access_all_modules` defaults
    // to 1 and getAnalystAllowedModules() short-circuits on it without ever
    // reading `analyst_modules`, so writing those rows alone restricts nobody.
    // Empty list → 1, the documented "blank means every module".
    $restricted = !empty($mods) ? 0 : 1;

    $unusable = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $profAttrs = ($syncMode !== 'never') ? oidcExtractProfileAttributes($claims) : ['job_title' => null, 'department' => null, 'phone' => null, 'mobile' => null];
    $stmt = $conn->prepare(
        "INSERT INTO analysts (username, password_hash, full_name, email, job_title, department, phone, mobile, is_active, created_datetime, auth_provider_id, can_access_all_modules)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), ?, ?)"
    );
    $stmt->execute([
        $username, $unusable, $name ?: $username, $email,
        $profAttrs['job_title'], $profAttrs['department'], $profAttrs['phone'], $profAttrs['mobile'],
        $providerId, $restricted
    ]);
    $analystId = (int)$conn->lastInsertId();

    if (!empty($mods)) {
        $ins = $conn->prepare("INSERT INTO analyst_modules (analyst_id, module_key) VALUES (?, ?)");
        foreach ($mods as $m) {
            try { $ins->execute([$analystId, $m]); } catch (Exception $e) { /* ignore dupes */ }
        }
    }
    require_once __DIR__ . '/../../includes/sso_identity.php';
    ssoApplyJitCompanies($conn, $providerId, $analystId);   // which companies (3.5.0)
    require_once __DIR__ . '/../../includes/sso_claims.php';
    ssoJitStartForTeams($conn, $providerId, $analystId, $defaultModules);   // teams give the modules (3.5.0)
    // The "for new analysts" preference template (discussion #124).
    require_once __DIR__ . '/../../includes/preference_templates.php';
    prefTemplatesApplyForNew($conn, $analystId);
    return $analystId;
}
