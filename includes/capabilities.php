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

    // Further modules land here as phase 3 converts them, e.g.
    //   const ASSETS_MANAGE  = 'assets.manage';    // umbrella
    //   const ASSETS_VCENTER = 'assets.vcenter';
    //   const ASSETS_INTUNE  = 'assets.intune';
}

/**
 * Modules that own capabilities, and how to name them in the Roles picker.
 * Keys match the module slugs used by Layer 1 (getAnalystAllowedModules()).
 */
function capModules(): array
{
    return [
        'lms' => 'LMS',
    ];
}

/**
 * The registry: metadata for every capability key.
 *
 * This is the ONLY place a capability's module, label and sensitivity are declared.
 * The Roles picker is generated from it, so a capability cannot exist in code but be
 * missing from the UI, or vice versa — capSelfCheck() proves it.
 *
 * 'umbrella'  — holding this satisfies every other capability in the same module.
 * 'sensitive' — reaches credentials, email, money or the audit trail. The Roles UI
 *               badges these; granting one should make you think.
 */
function capRegistry(): array
{
    return [
        Cap::LMS_MANAGE => [
            'module'    => 'lms',
            'umbrella'  => true,
            'sensitive' => false,
            'label'     => 'Manage courses, learning groups and assignments, and view everyone\'s progress',
        ],
    ];
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
 * Self-check: prove the constants, the registry and the module list agree.
 *
 * Types catch a typo at a call site. They cannot catch a HALF-ADDED capability — a
 * Cap:: constant with no registry entry (invisible in the Roles UI, so grantable by
 * nobody, so a permanent silent 403 — the exact bug this file exists to prevent), or a
 * registry entry naming a module that isn't declared. Only an audit catches those, so:
 * this runs in the coverage report, and any new capability should be added with both
 * halves in the same commit.
 *
 * @return array<int,string> human-readable problems; empty means healthy
 */
function capSelfCheck(): array
{
    $problems = [];
    $registry = capRegistry();
    $modules  = capModules();

    // Every Cap:: constant must have a registry entry.
    $constants = (new ReflectionClass('Cap'))->getConstants();
    foreach ($constants as $name => $value) {
        if (!isset($registry[$value])) {
            $problems[] = "Cap::{$name} ('{$value}') has no capRegistry() entry — it can never be granted, so any guard using it will 403 everyone but admins.";
        }
    }

    // …and every registry entry must have a constant, a known module, and a label.
    $declared = array_flip($constants);
    foreach ($registry as $key => $meta) {
        if (!isset($declared[$key])) {
            $problems[] = "capRegistry() declares '{$key}' but there is no Cap:: constant for it — call sites would have to spell it as a string.";
        }
        if (!isset($modules[$meta['module']])) {
            $problems[] = "Capability '{$key}' names module '{$meta['module']}', which is not in capModules() — it will not appear in the Roles picker.";
        }
        if (empty($meta['label'])) {
            $problems[] = "Capability '{$key}' has no label — the Roles picker would show the raw key.";
        }
    }

    // At most one umbrella per module, or capUmbrella() is ambiguous.
    foreach ($modules as $module => $_label) {
        $umbrellas = array_filter(capsForModule($module), 'capIsUmbrella');
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

    return $problems;
}

/** Is this capability its module's umbrella? */
function capIsUmbrella(string $key): bool
{
    return (bool) (capRegistry()[$key]['umbrella'] ?? false);
}
