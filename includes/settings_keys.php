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
    static $owners = null;
    if ($owners !== null) return $owners;

    // CONVERTED MODULES — derived from their manifests, so the tab that shows a setting
    // and the permission that guards it are the same declaration. They cannot disagree.
    $owners = [];
    foreach (settingsManifests() as $m) {
        foreach ($m['tabs'] ?? [] as $tab) {
            foreach ($tab['setting_keys'] ?? [] as $key) {
                $owners[$key] = [
                    'module' => $m['module'],
                    'cap'    => $tab['cap'] ?? null,
                    'tab'    => $tab['id'],
                ];
            }
        }
    }

    // THE SYSTEM MODULE — and, now that the roll-out is complete, nothing else.
    //
    // These are not "not yet converted": the System module is deliberately NOT split into
    // capabilities (docs/design/rbac.md §9a). It is ~20 areas, every one of them
    // administration by definition, with no operational half to separate out — so
    // splitting it would produce permissions only administrators would ever hold, which is
    // what is_admin already means.
    //
    // 'cap' => null therefore falls back to Layer 1, and module 'system' resolves to
    // is_admin via analystCanAccessModule(). So these keys are administrator-only, with no
    // special-casing here. This list is permanent, not a to-do.
    //
    // (Every other module's keys are DERIVED from its manifest above. Tickets' 'system_name'
    // was the last one to move out, when Tickets converted in #848.)
    /* --- System: AI thinking area ---
       One <ns>_reasoning key per REGISTERED AI namespace, generated from the
       registry rather than listed here. That is the whole point: a tenth AI
       feature appears on the page and becomes writable in the same commit that
       registers it, with nothing to remember. Administrator-only, like every
       other System key. */
    require_once __DIR__ . '/ai_settings.php';
    foreach (array_keys(aiSettingsRegistry()) as $ns) {
        $owners[$ns . '_reasoning'] = ['module' => 'system', 'cap' => null, 'tab' => 'ai'];
    }

    return $owners += [
        // --- System: Security area. The lockout policy — an analyst able to raise
        // max_failed_logins could switch off brute-force protection entirely. ---
        'self_service_registration_enabled' => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        // The first word of every page title - System -> Branding (moved from Tickets' General tab).
        'system_name'              => ['module' => 'system', 'cap' => null, 'tab' => 'branding'],
        // The "Back to ..." link in the headers (3.5.0) - includes/back_link.php.
        'back_link_label'          => ['module' => 'system', 'cap' => null, 'tab' => 'branding'],
        'back_link_url'            => ['module' => 'system', 'cap' => null, 'tab' => 'branding'],
        'back_link_where'          => ['module' => 'system', 'cap' => null, 'tab' => 'branding'],
        'trusted_device_days'      => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        'keep_signed_in_days'      => ['module' => 'system', 'cap' => null, 'tab' => 'security'],   // GH #443
        'password_expiry_days'     => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        'max_failed_logins'        => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        'max_ip_attempts'          => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        'min_ip_attempts'          => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        'lockout_duration_minutes' => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        // What happens to an inbound attachment whose type we do not accept —
        // keep it inert, or do not keep it. See includes/uploads.php.
        'attachment_rejected_behaviour' => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        // Which attachment types this install accepts. Narrows the catalogue in
        // includes/uploads.php; it can never widen past it.
        'attachment_allowed_extensions' => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        // Size limits (GH #170): the largest attachment anywhere, and the largest
        // total per inbound email. Read and clamped in includes/attachment_limits.php.
        'attachment_max_mb'             => ['module' => 'system', 'cap' => null, 'tab' => 'security'],
        'attachment_max_total_mb'       => ['module' => 'system', 'cap' => null, 'tab' => 'security'],

        // --- Tickets: time tracking (discussion #72) ---
        // The INSTALL-WIDE defaults. A company may override either of them —
        // see includes/tenant_settings.php and the Time tracking tab.
        'time_tracking_enabled'     => ['module' => 'tickets', 'cap' => capKey(Cap::TICKETS_MANAGE), 'tab' => 'time-tracking'],
        'time_tracking_api_enabled' => ['module' => 'tickets', 'cap' => capKey(Cap::TICKETS_MANAGE), 'tab' => 'time-tracking'],

        // --- Tickets: classification fields (#1540) ---
        // The INSTALL-WIDE defaults for whether each of the three fields appears on
        // a ticket. A company may override any of them — same mechanism as time
        // tracking above (includes/tenant_settings.php).
        //
        // ⚠️ These default to OFF, where time tracking defaults to ON. Deliberate:
        // upgrading must not grow three empty dropdowns on everybody's ticket page
        // before they have created a single category. Build the list, then switch on.
        //
        // 🔑 One switch each, NOT the UI/API pair time tracking carries. These are
        // plain columns rather than a panel with its own endpoints, so a hidden
        // field simply never gets set and the REST API returns null on its own —
        // there is no endpoint to quietly empty out from under an integration.
        //
        // ⚠️ NOT LISTED HERE. They are declared as `setting_keys` on the Categories
        // tab in tickets/settings/manifest.php and derived by settingKeyOwners()
        // above — which is the pattern this file's own header calls for, so that
        // the tab showing a setting and the capability guarding it are one
        // declaration that cannot disagree. `time_tracking_enabled` sitting in
        // this block is the older arrangement, not the one to copy.

        // --- System: SSO area ---
        'sso_enabled'              => ['module' => 'system', 'cap' => null, 'tab' => 'sso'],
        'local_login_enabled'      => ['module' => 'system', 'cap' => null, 'tab' => 'sso'],

        // --- System: Date and time formats area (GH #105) ---
        // The install-wide DEFAULT format. Analysts override it per-account in
        // Preferences (user_preferences, not here); these two rows are what
        // everyone who has not chosen sees, and the only format the portal reads.
        'date_format'              => ['module' => 'system', 'cap' => null, 'tab' => 'date-formats'],
        'time_format'              => ['module' => 'system', 'cap' => null, 'tab' => 'date-formats'],
        'week_start'               => ['module' => 'system', 'cap' => null, 'tab' => 'date-formats'],   // GH #355

        // --- System: Currencies area (GH #189) ---
        // The default currency, the favourites shown first in every currency
        // dropdown, and the install-wide format (plus per-currency exceptions).
        // Analysts override the FORMAT in Preferences (user_preferences). Stored
        // as given; includes/money.php validates every one of them on read.
        'currency_default'         => ['module' => 'system', 'cap' => null, 'tab' => 'currencies'],
        'currency_favourites'      => ['module' => 'system', 'cap' => null, 'tab' => 'currencies'],
        'currency_format'          => ['module' => 'system', 'cap' => null, 'tab' => 'currencies'],
        'currency_formats'         => ['module' => 'system', 'cap' => null, 'tab' => 'currencies'],
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
 * @param string $key  a system_settings key, e.g. 'vcenter_server' or 'module_color_tickets'
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
 * May this analyst manage the AI configuration for a namespace ('knowledge_ai', 'cmdb_ai',
 * 'tickets_reply_cleanup', …)?
 *
 * The AI settings panel is SHARED by seven modules and posts to one endpoint
 * (api/system/ai/*), passing its namespace. So — like the shared settings writer — a single
 * guard on that file cannot be right for seven different audiences, and until now it simply
 * demanded is_admin: fine, but it meant a module's AI tab could never be delegated.
 *
 * A namespace's settings ARE system_settings keys ('<ns>_api_key' and friends), so this
 * reuses the ownership map rather than inventing a second mechanism:
 *
 *   - the module has been CONVERTED (its manifest claims those keys) → the tab's capability;
 *   - it has NOT been converted yet                                  → is_admin, exactly as
 *     today, so nothing loosens by accident.
 *
 * @param string $ns  the AI settings namespace, e.g. 'knowledge_ai', 'cmdb_ai', 'tickets_reply_cleanup'
 */
function analystCanManageAiNamespace(PDO $conn, int $analystId, string $ns): bool
{
    $key = $ns . '_api_key';
    if (settingKeyOwner($key) !== null) {
        return analystCanWriteSettingKey($conn, $analystId, $key);
    }
    return analystIsAdmin($conn, $analystId);
}

/**
 * May this analyst manage the AI settings of ANY namespace?
 *
 * For the shared model-list endpoint, which has no namespace of its own but is needed by
 * the panel to populate its model dropdown. Anyone who can configure at least one module's
 * AI can fetch the list; nobody else can.
 */
function analystCanManageAnyAiNamespace(PDO $conn, int $analystId): bool
{
    require_once __DIR__ . '/ai_settings.php';
    foreach (array_keys(aiSettingsRegistry()) as $ns) {
        if (analystCanManageAiNamespace($conn, $analystId, $ns)) return true;
    }
    return false;
}

/**
 * The API twin: refuse with a 403 unless this analyst may manage the namespace's AI config.
 *
 * @param string $ns  the AI settings namespace, e.g. 'knowledge_ai'; checked for the signed-in analyst
 */
function requireAiNamespaceJson(PDO $conn, string $ns): void
{
    $analystId = (int) ($_SESSION['analyst_id'] ?? 0);
    if ($analystId <= 0 || !analystCanManageAiNamespace($conn, $analystId, $ns)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'You do not have permission to manage these AI settings']);
        exit;
    }
}

/**
 * May this analyst write this setting key?
 *
 * Once a module converts (phase 3b) its keys name a capability, and that capability is
 * required. Until then the key falls back to its module's Layer 1 access. is_admin
 * bypasses both — via analystHasCapability() and via analystCanAccessModule('system').
 *
 * @param string $key  the system_settings key to be written; a key with no owner is always refused
 */
function analystCanWriteSettingKey(PDO $conn, int $analystId, string $key): bool
{
    $owner = settingKeyOwner($key);
    if ($owner === null) return false;   // unowned key — deny by default

    if ($owner['cap'] !== null) {
        return analystHasCapability($conn, $analystId, Cap::from($owner['cap']));
    }
    return analystCanAccessModule($conn, $analystId, $owner['module']);
}
