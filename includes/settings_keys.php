<?php
/**
 * Who owns each key in `system_settings`.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * api/settings/save_system_settings.php is a GENERIC key/value writer: it loops over
 * whatever keys the caller posts and writes them. Five settings pages use it —
 * Asset Management (warranty/vCenter/Intune), Tickets (general), and the System
 * colours/security/SSO areas — because those tabs have no endpoint of their own.
 *
 * That made it impossible to authorise properly. A guard at the top of the file is
 * meaningless when it's the same file for all five callers, so the endpoint checked
 * only that you were a logged-in analyst. Any analyst — including one whose module
 * access was a single unrelated module — could therefore POST any key and overwrite
 * any system setting: vCenter and Intune credentials, the SSO configuration, or the
 * brute-force lockout policy (max_failed_logins, lockout_duration_minutes).
 *
 * So the capability attaches to the KEY, not the file. This map is the authority:
 * save_system_settings.php looks up each posted key and demands its owner's access.
 *
 * ---------------------------------------------------------------------------
 * DENY BY DEFAULT, AND FAIL LOUD
 * ---------------------------------------------------------------------------
 * A key that is NOT in this map is REJECTED. That matters twice over:
 *
 *  1. `system_settings` holds ~88 keys, but only the ones below are legitimately
 *     written through this endpoint. The rest — branding, AI provider keys, cron
 *     tokens, module_permission_mode, local_login_enabled — have their own dedicated,
 *     guarded endpoints. There is no reason for a generic writer to reach them, and
 *     before this map, it could.
 *
 *  2. If a key is missed when a new tab is built, the symptom is that saving that tab
 *     ERRORS VISIBLY. The alternative failure — silently accepting an unowned key —
 *     is how this hole existed in the first place. Loud beats open.
 *
 * ---------------------------------------------------------------------------
 * ROLLOUT
 * ---------------------------------------------------------------------------
 * 'cap' is the per-tab capability that will own the key once its module is converted
 * (docs/design/rbac.md §9, phase 3b). Until then it is null and the key falls back to
 * 'module' — plain Layer 1 module access, which is exactly what reaching these settings
 * pages requires today, so nothing breaks. When a module converts, fill in its 'cap'.
 *
 * Module 'system' resolves to is_admin via analystCanAccessModule(), so the System
 * areas are admin-only with no special-casing here.
 */

require_once __DIR__ . '/capabilities.php';

/**
 * Exact key -> owner. 'tab' is documentation: it names the settings tab the key
 * belongs to, so the eventual per-tab capability is obvious when the module converts.
 *
 * @return array<string,array{module:string,cap:?string,tab:string}>
 */
function settingKeyOwners(): array
{
    return [
        // --- Asset Management ------------------------------------------------
        // Warranty tab
        'asset_warranty_surface'  => ['module' => 'assets', 'cap' => null, 'tab' => 'warranty'],
        'asset_warranty_days'     => ['module' => 'assets', 'cap' => null, 'tab' => 'warranty'],
        // vCenter tab — credentials
        'vcenter_server'          => ['module' => 'assets', 'cap' => null, 'tab' => 'vcenter'],
        'vcenter_user'            => ['module' => 'assets', 'cap' => null, 'tab' => 'vcenter'],
        'vcenter_password'        => ['module' => 'assets', 'cap' => null, 'tab' => 'vcenter'],
        // Intune tab — credentials
        'intune_tenant_id'        => ['module' => 'assets', 'cap' => null, 'tab' => 'intune'],
        'intune_client_id'        => ['module' => 'assets', 'cap' => null, 'tab' => 'intune'],
        'intune_client_secret'    => ['module' => 'assets', 'cap' => null, 'tab' => 'intune'],
        'intune_verify_ssl'       => ['module' => 'assets', 'cap' => null, 'tab' => 'intune'],
        'intune_app_batch_size'   => ['module' => 'assets', 'cap' => null, 'tab' => 'intune'],

        // --- Tickets ---------------------------------------------------------
        // General tab
        'system_name'             => ['module' => 'tickets', 'cap' => null, 'tab' => 'general'],

        // --- System (admin-only; 'system' => is_admin) -------------------------
        // Security area — the lockout policy. An analyst able to raise max_failed_logins
        // could switch off brute-force protection, which is why this one matters most.
        'trusted_device_days'     => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        'password_expiry_days'    => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        'max_failed_logins'       => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        'max_ip_attempts'         => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        'min_ip_attempts'         => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        'lockout_duration_minutes'=> ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        // SSO area
        'sso_enabled'             => ['module' => 'system', 'cap' => null, 'tab' => 'sso'],
        'local_login_enabled'     => ['module' => 'system', 'cap' => null, 'tab' => 'sso'],
    ];
}

/**
 * Prefix -> owner, for keys generated at runtime rather than named in code.
 *
 * Only the System colours page needs this: it posts one 'module_color_<module>' key per
 * module in the waffle, so the key set is a pattern, not a list. Keep this map tiny —
 * a prefix is a blunter instrument than an exact key, and every entry widens what the
 * generic writer can reach.
 *
 * @return array<string,array{module:string,cap:?string,tab:string}>
 */
function settingKeyPrefixes(): array
{
    return [
        'module_color_' => ['module' => 'system', 'cap' => null, 'tab' => 'colours'],
    ];
}

/**
 * Who owns this setting key? null = nobody, so it may not be written through the
 * generic endpoint. Exact matches win over prefixes.
 *
 * @return array{module:string,cap:?string,tab:string}|null
 */
function settingKeyOwner(string $key): ?array
{
    $owners = settingKeyOwners();
    if (isset($owners[$key])) return $owners[$key];

    foreach (settingKeyPrefixes() as $prefix => $owner) {
        if (strncmp($key, $prefix, strlen($prefix)) === 0) return $owner;
    }
    return null;
}

/**
 * May this analyst write this setting key?
 *
 * Once a module converts (phase 3b) its keys name a capability, and that capability is
 * required. Until then the key falls back to its module's Layer 1 access. is_admin
 * bypasses both — via analystHasCapability() and via analystCanAccessModule('system').
 */
function analystCanWriteSettingKey(PDO $conn, int $analystId, string $key): bool
{
    $owner = settingKeyOwner($key);
    if ($owner === null) return false;   // unowned key — deny by default

    if ($owner['cap'] !== null) {
        return analystHasCapability($conn, $analystId, $owner['cap']);
    }
    return analystCanAccessModule($conn, $analystId, $owner['module']);
}
