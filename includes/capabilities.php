<?php
/**
 * RBAC Layer 2 — the capability registry.
 *
 * A capability is an administrative permission: "may this analyst configure X?"
 * See docs/design/rbac.md. This file owns WHAT capabilities exist. includes/rbac.php
 * owns WHO holds them and enforces it.
 *
 * ---------------------------------------------------------------------------
 * WHY CONSTANTS AND NOT STRINGS
 * ---------------------------------------------------------------------------
 * Phase 3 gives every settings TAB its own capability — around 70 of them, guarded
 * at a couple of hundred call sites. At that scale a bare string key is dangerous,
 * because of how it fails:
 *
 *     requireCapabilityJson('lms.mange');    // <- typo
 *
 * That does not error. It fails CLOSED, exactly as designed: a permanent, polite
 * 403 that reads like a policy decision. Worse, analystHasCapability() short-circuits
 * true for is_admin BEFORE it looks at the string — so every test the maintainer runs
 * passes, and the only people who see it are users, who assume they simply aren't
 * allowed and ask to be made an admin. A typo quietly becomes a privilege escalation.
 *
 *     requireCapabilityJson(Cap::LMS_MANGE); // <- Fatal error: Undefined constant
 *
 * Same typo, opposite failure: loud, immediate, at the call site, on the first request
 * that touches the line — and it fires for the admin too, because the fatal happens
 * before the is_admin bypass can hide it. That asymmetry is the whole reason this
 * class exists. ALWAYS pass a Cap:: constant. Never write the string.
 *
 * ---------------------------------------------------------------------------
 * THE ENUM BRIDGE
 * ---------------------------------------------------------------------------
 * PHP 8.1 enums are the right tool here; the project's floor is 7.4, so we can't use
 * them (see the wiki: "Raising the PHP floor to 8.1"). Every helper below is therefore
 * named after the enum method it will become, so the swap stays a find-and-replace:
 *
 *     capAll()              -> Capability::cases()
 *     capFromKey($s)        -> Capability::tryFrom($s)
 *     capLabel($c)          -> $c->label()
 *     capModule($c)         -> $c->module()
 *     capIsSensitive($c)    -> $c->isSensitive()
 *     capsForModule($m)     -> Capability::forModule($m)
 *
 * If you add a helper, name it after the method it would be on the enum.
 */

/**
 * The capability keys. THE TYPE. Every guard call site names one of these constants.
 *
 * Naming: '<module>.<area>', where <area> is normally the settings tab it governs, and
 * '<module>.manage' is the per-module umbrella (holding it satisfies every capability
 * in that module — see capExpandUmbrellas()).
 *
 * Phase 3 adds one constant per settings tab as each module is converted. Only the LMS
 * is declared today; it is the pilot, and its 'manage' is an umbrella over a module that
 * is unusual in being almost entirely an admin surface.
 */
final class Cap
{
    // ---- LMS ---------------------------------------------------------------
    const LMS_MANAGE = 'lms.manage';

    // ---- Asset Management --------------------------------------------------
    // One per settings tab. The point of the split is visible here: VCENTER and
    // INTUNE hold integration credentials, and sit on the same tab bar as TYPES,
    // which is a lookup list. A single 'assets.manage' could not tell them apart.
    const ASSETS_MANAGE    = 'assets.manage';      // umbrella
    const ASSETS_TYPES     = 'assets.types';
    const ASSETS_STATUSES  = 'assets.statuses';
    const ASSETS_LOCATIONS = 'assets.locations';
    const ASSETS_SUPPLIERS = 'assets.suppliers';
    const ASSETS_WARRANTY  = 'assets.warranty';
    const ASSETS_VCENTER   = 'assets.vcenter';     // credentials
    const ASSETS_INTUNE    = 'assets.intune';      // credentials

    // ---- Contracts ---------------------------------------------------------
    const CONTRACTS_MANAGE             = 'contracts.manage';              // umbrella
    const CONTRACTS_SUPPLIER_TYPES     = 'contracts.supplier_types';
    const CONTRACTS_SUPPLIER_STATUSES  = 'contracts.supplier_statuses';
    const CONTRACTS_CONTRACT_STATUSES  = 'contracts.contract_statuses';
    const CONTRACTS_PAYMENT_SCHEDULES  = 'contracts.payment_schedules';
    const CONTRACTS_CONTRACT_TERMS     = 'contracts.contract_terms';
    const CONTRACTS_RFP_DEPARTMENTS    = 'contracts.rfp_departments';
    const CONTRACTS_RFP_AI             = 'contracts.rfp_ai';              // AI provider + API key
}

/**
 * Every module's settings manifest, loaded once.
 *
 * ---------------------------------------------------------------------------
 * THE MANIFEST IS THE SINGLE DECLARATION
 * ---------------------------------------------------------------------------
 * A module declares its settings tabs — and therefore its capabilities — in exactly one
 * file: <module>/settings/manifest.php. Everything else is DERIVED from that:
 *
 *   capRegistry()      the capabilities, their module, label and sensitivity
 *   capModules()       the module list for the Roles picker
 *   settingKeyOwners() who may write each shared system_settings key
 *   the tab bar        rendered from the same list (includes/settings_manifest.php)
 *
 * The earlier design had all four written out by hand and a self-check to catch them
 * drifting apart. Needing that check WAS the bug: four copies of one fact, kept in step
 * by discipline. They can't disagree now, because there is only one of them.
 *
 * The Cap:: constants above are the one thing that cannot be derived — they are the type,
 * and their whole value is that a typo at a call site is a fatal error rather than a
 * silent 403. capSelfCheck() proves every constant is claimed by exactly one manifest.
 *
 * Discovery is a glob, so adding a module means adding its manifest — nothing to register.
 *
 * @return array<int,array> the manifests, in module order
 */
function settingsManifests(): array
{
    static $manifests = null;
    if ($manifests !== null) return $manifests;

    $manifests = [];
    foreach (glob(__DIR__ . '/../*/settings/manifest.php') ?: [] as $path) {
        $m = require $path;
        if (is_array($m) && !empty($m['module'])) $manifests[] = $m;
    }
    usort($manifests, fn($a, $b) => strcmp($a['module'], $b['module']));
    return $manifests;
}

/** The manifest for one module, or null. */
function settingsManifestFor(string $module): ?array
{
    foreach (settingsManifests() as $m) {
        if ($m['module'] === $module) return $m;
    }
    return null;
}

/**
 * Modules that own capabilities, and how to name them in the Roles picker.
 * DERIVED from the manifests. Keys are the Layer 1 module slugs.
 */
function capModules(): array
{
    $out = [];
    foreach (settingsManifests() as $m) {
        $out[$m['module']] = $m['label'] ?? $m['module'];
    }
    return $out;
}

/**
 * The capability registry — DERIVED from the manifests.
 *
 * A tab's 'cap' becomes a capability; its 'grant' is the description shown in the Roles
 * picker; 'sensitive' badges it. The module's 'umbrella' becomes the "manage everything
 * here" capability. Tabs declaring 'cap' => null are personal preferences and contribute
 * nothing — they are not administration and there is nothing to grant.
 *
 * @return array<string,array{module:string,umbrella:bool,sensitive:bool,label:string}>
 */
function capRegistry(): array
{
    static $registry = null;
    if ($registry !== null) return $registry;

    $registry = [];
    foreach (settingsManifests() as $m) {
        $module = $m['module'];

        if (!empty($m['umbrella']['cap'])) {
            $registry[$m['umbrella']['cap']] = [
                'module'    => $module,
                'umbrella'  => true,
                'sensitive' => !empty($m['umbrella']['sensitive']),
                'label'     => $m['umbrella']['grant'] ?? ('Manage everything in ' . ($m['label'] ?? $module) . ' settings'),
            ];
        }

        foreach ($m['tabs'] ?? [] as $tab) {
            $cap = $tab['cap'] ?? null;
            if ($cap === null) continue;               // a personal preference — nothing to grant
            if (isset($registry[$cap])) continue;      // already declared (e.g. a tab reusing the umbrella)
            $registry[$cap] = [
                'module'    => $module,
                'umbrella'  => false,
                'sensitive' => !empty($tab['sensitive']),
                'label'     => $tab['grant'] ?? $tab['id'],
            ];
        }
    }
    return $registry;
}

/**
 * Retired keys -> their current replacement.
 *
 * A capability key appears in exactly one place in code (its Cap:: constant), so renaming
 * one is a one-line edit — but grants already in rbac_role_capabilities still hold the OLD
 * string. Map it here and those grants keep working; omit it and they are silently dropped
 * (which is safe, but surprising). Applied in capFromKey().
 */
function capAliases(): array
{
    return [
        // 'assets.vcentre' => Cap::ASSETS_VCENTER,
    ];
}

// ---------------------------------------------------------------------------
// Accessors. Named for the enum methods they will become — see the header.
// ---------------------------------------------------------------------------

/** Every declared capability key. Enum twin: Capability::cases(). */
function capAll(): array
{
    return array_keys(capRegistry());
}

/** Is this a capability the code defines? Enum twin: Capability::tryFrom() !== null. */
function capExists(string $key): bool
{
    return isset(capRegistry()[$key]);
}

/**
 * Resolve a key that came from OUTSIDE the code (a DB row, a form post) to a known
 * capability, or null if the code no longer defines it. Enum twin: Capability::tryFrom().
 *
 * This is the trust boundary: everything read from rbac_role_capabilities goes through
 * here, so a stale or hand-inserted key can never grant anything.
 */
function capFromKey(string $key): ?string
{
    if (capExists($key)) return $key;
    $aliases = capAliases();
    if (isset($aliases[$key]) && capExists($aliases[$key])) return $aliases[$key];
    return null;
}

/** Human description, shown in System -> Roles. Enum twin: $cap->label(). */
function capLabel(string $key): string
{
    return capRegistry()[$key]['label'] ?? $key;
}

/** The module this capability administers. Enum twin: $cap->module(). */
function capModule(string $key): ?string
{
    return capRegistry()[$key]['module'] ?? null;
}

/** Display name for a module slug. Enum twin: $module->label(). */
function capModuleLabel(string $module): string
{
    return capModules()[$module] ?? $module;
}

/** Does granting this need a second thought? Enum twin: $cap->isSensitive(). */
function capIsSensitive(string $key): bool
{
    return (bool) (capRegistry()[$key]['sensitive'] ?? false);
}

/** Every capability belonging to a module. Enum twin: Capability::forModule(). */
function capsForModule(string $module): array
{
    $out = [];
    foreach (capRegistry() as $key => $meta) {
        if ($meta['module'] === $module) $out[] = $key;
    }
    return $out;
}

/** The module's umbrella capability ('<module>.manage'), or null if it has none. */
function capUmbrella(string $module): ?string
{
    foreach (capRegistry() as $key => $meta) {
        if ($meta['module'] === $module && !empty($meta['umbrella'])) return $key;
    }
    return null;
}

/**
 * Expand umbrella grants: holding '<module>.manage' means holding every capability in
 * that module.
 *
 * This is what makes ~70 capabilities usable — an "Asset Administrator" role is one tick,
 * not seven. It also keeps roles stable over time: add a tab next year and umbrella-holders
 * pick it up automatically, while a role that ticked individual tabs does NOT. That is the
 * safe direction, and it is why deny-by-default survives the convenience.
 *
 * @param array<int,string> $held capability keys granted directly by roles
 * @return array<int,string> those keys plus everything their umbrellas imply
 */
function capExpandUmbrellas(array $held): array
{
    $expanded = $held;
    foreach ($held as $key) {
        $meta = capRegistry()[$key] ?? null;
        if ($meta && !empty($meta['umbrella'])) {
            $expanded = array_merge($expanded, capsForModule($meta['module']));
        }
    }
    return array_values(array_unique($expanded));
}

/**
 * The registry shaped for the System -> Roles picker: grouped by module, labelled,
 * with the umbrella first so "manage everything here" reads as the headline choice.
 *
 * Generated from capRegistry(), so adding a constant + a registry entry makes the
 * tick-box appear. There is no second list to keep in step.
 */
function capGroups(): array
{
    $groups = [];
    foreach (capModules() as $module => $moduleLabel) {
        $caps = capsForModule($module);
        if (!$caps) continue;

        // Umbrella first, then the rest alphabetically — a stable, readable order.
        $umbrella = capUmbrella($module);
        usort($caps, function ($a, $b) use ($umbrella) {
            if ($a === $umbrella) return -1;
            if ($b === $umbrella) return 1;
            return strcmp($a, $b);
        });

        $entries = [];
        foreach ($caps as $key) {
            $entries[$key] = [
                'label'     => capLabel($key),
                'sensitive' => capIsSensitive($key),
                'umbrella'  => $key === $umbrella,
            ];
        }
        $groups[$module] = ['label' => $moduleLabel, 'capabilities' => $entries];
    }
    return $groups;
}

/**
 * Self-check: the things derivation CANNOT rule out.
 *
 * Most of what this used to check is now impossible by construction — the registry, the
 * module list and the setting-key map are all derived from the manifests, so they cannot
 * disagree with them. What's left is the seam that derivation doesn't cross: the Cap::
 * constants are hand-written (they have to be — they're the type), so a constant can
 * still be declared and then never claimed by any manifest.
 *
 * That is not a cosmetic problem. An unclaimed constant is a capability nobody can be
 * granted, so any guard using it 403s everyone except administrators — permanently, and
 * invisibly to the administrator, who bypasses the check. It is exactly the failure this
 * whole file exists to prevent, arriving by a different door.
 *
 * @return array<int,string> human-readable problems; empty means healthy
 */
function capSelfCheck(): array
{
    $problems = [];
    $registry = capRegistry();

    // Every Cap:: constant must be claimed by some manifest.
    foreach ((new ReflectionClass('Cap'))->getConstants() as $name => $value) {
        if (!isset($registry[$value])) {
            $problems[] = "Cap::{$name} ('{$value}') is not claimed by any settings manifest — it can never be granted, so any guard using it will 403 everyone but administrators, silently.";
        }
    }

    // A manifest must not invent a capability with no constant behind it — call sites
    // would then have to spell it as a bare string, which is the dangerous form.
    $declared = array_flip((new ReflectionClass('Cap'))->getConstants());
    foreach ($registry as $key => $meta) {
        if (!isset($declared[$key])) {
            $problems[] = "A manifest declares capability '{$key}', but there is no Cap:: constant for it — guards would have to name it as a string.";
        }
        if (empty($meta['label'])) {
            $problems[] = "Capability '{$key}' has no description ('grant') — the Roles picker would show the raw key.";
        }
    }

    // One umbrella per module, or capUmbrella() is ambiguous.
    foreach (array_keys(capModules()) as $module) {
        $umbrellas = array_values(array_filter(capsForModule($module), 'capIsUmbrella'));
        if (count($umbrellas) > 1) {
            $problems[] = "Module '{$module}' declares more than one umbrella capability (" . implode(', ', $umbrellas) . ").";
        }
    }

    // An alias must point at a capability that still exists, or the grants it rescues are dropped anyway.
    foreach (capAliases() as $old => $new) {
        if (!capExists($new)) {
            $problems[] = "Alias '{$old}' points at '{$new}', which is not a declared capability.";
        }
    }

    // Every setting key a manifest claims must be enforced by settings_keys.php, and with
    // the SAME capability — otherwise the tab's save is either unguarded or refused.
    if (function_exists('settingKeyOwner')) {
        foreach (settingsManifests() as $m) {
            foreach ($m['tabs'] ?? [] as $tab) {
                foreach ($tab['setting_keys'] ?? [] as $key) {
                    $owner = settingKeyOwner($key);
                    if ($owner === null) {
                        $problems[] = "Tab '{$m['module']}/{$tab['id']}' claims setting key '{$key}', but nothing owns it — saving that tab would be refused.";
                    } elseif (($owner['cap'] ?? null) !== ($tab['cap'] ?? null)) {
                        $problems[] = "Setting key '{$key}' is guarded by '" . var_export($owner['cap'] ?? null, true) . "' but its tab '{$m['module']}/{$tab['id']}' requires '" . var_export($tab['cap'] ?? null, true) . "'.";
                    }
                }
            }
        }
    }

    return $problems;
}

/** Is this capability its module's umbrella? */
function capIsUmbrella(string $key): bool
{
    return (bool) (capRegistry()[$key]['umbrella'] ?? false);
}
