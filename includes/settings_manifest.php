<?php
/**
 * Settings manifests — the only place a settings TAB may be declared.
 *
 * RBAC phase 3 gives every settings tab its own capability (docs/design/rbac.md §5).
 * A manifest binds the two together, and the tab bar is RENDERED FROM IT — so a tab
 * cannot exist without either a declared capability or an explicit null meaning "this
 * is a personal preference, not administration". Drift between what the settings page
 * shows and what the code enforces becomes impossible, rather than merely detectable.
 *
 * A module's manifest lives at <module>/settings/manifest.php and returns:
 *
 *   [
 *     'module' => 'assets',                       // Layer 1 module slug
 *     'label'  => 'Asset management',             // its name in the Roles picker
 *     'umbrella' => ['cap' => Cap::ASSETS_MANAGE, 'grant' => '…'],
 *     'tabs'   => [
 *       ['id' => 'vcenter',
 *        'cap' => Cap::ASSETS_VCENTER,            // null = personal preference, never gated
 *        'label_key' => 'asset-management.settings.tab_vcenter',   // the TAB's name
 *        'grant' => 'Configure the vCenter connection…',           // the PERMISSION's description
 *        'sensitive' => true,                                      // badged in the Roles picker
 *        'setting_keys' => ['vcenter_server', …],                  // who may write these
 *       ],
 *     ],
 *   ]
 *
 * This file is the ONLY place any of that is written. The capability registry, the module
 * list for System → Roles, and the setting-key ownership map are all DERIVED from it (see
 * includes/capabilities.php). They cannot disagree with it, because they are it.
 *
 * ---------------------------------------------------------------------------
 * NOT RENDERED, NOT HIDDEN
 * ---------------------------------------------------------------------------
 * A panel the analyst lacks the capability for is not emitted into the page at all.
 * There is no `display:none` to flip in devtools, because there is no DOM. The
 * data-capability attribute the renderer stamps on each tab is a LABEL, not a lock —
 * it exists so the page is machine-readable for the coverage report. The enforcement
 * is (a) the panel not being rendered and (b) the guard on the endpoint behind it.
 */

require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/settings_keys.php';

/**
 * (settingsManifestSelfCheck() used to live here. It compared the manifest against the
 * capability registry and the setting-key map — three hand-written lists that had to
 * agree. All three are now DERIVED from the manifest, so they cannot disagree, and the
 * check has nothing left to find. The one seam derivation doesn't cross — a Cap::
 * constant no manifest claims — is checked by capSelfCheck().)
 */

/**
 * The tabs this analyst may see, in manifest order.
 *
 * A tab with 'cap' => null is a personal preference (e.g. the left-panel display
 * setting, which appears on seven modules' settings pages) and is always visible.
 * Everything else is deny-by-default: no capability, no tab. is_admin holds every
 * capability, so administrators see the lot.
 *
 * @return array<int,array> the visible tab definitions
 */
function settingsVisibleTabs(PDO $conn, int $analystId, array $manifest): array
{
    $visible = [];
    foreach ($manifest['tabs'] as $tab) {
        if (($tab['cap'] ?? null) === null) {
            $visible[] = $tab;                     // personal preference — never gated
        } elseif (analystHasCapability($conn, $analystId, $tab['cap'])) {
            $visible[] = $tab;
        }
    }
    return $visible;
}

/** Is this tab id in the visible set? Use to decide whether to emit its panel. */
function settingsTabVisible(array $visibleTabs, string $tabId): bool
{
    foreach ($visibleTabs as $tab) {
        if ($tab['id'] === $tabId) return true;
    }
    return false;
}

/**
 * The tab that should open first: the first visible one.
 *
 * Not simply "the first in the manifest" — that tab may not be visible to this analyst,
 * and hard-coding `class="tab-content active"` on it would leave a restricted user
 * looking at a settings page with no panel showing.
 */
function settingsFirstTabId(array $visibleTabs): ?string
{
    return $visibleTabs[0]['id'] ?? null;
}

/** Does this analyst hold ANY administrative capability in this module? */
function settingsHasAnyAdminTab(array $visibleTabs): bool
{
    foreach ($visibleTabs as $tab) {
        if (($tab['cap'] ?? null) !== null) return true;
    }
    return false;
}

/**
 * Render the tab bar from the manifest — only the tabs this analyst may see.
 *
 * data-capability is emitted FROM the Cap:: constant, so the attribute cannot be a typo
 * either. Tabs with no capability are marked data-capability="none" rather than being
 * left bare, so the coverage report can tell "personal preference" apart from "someone
 * forgot to declare one".
 */
function renderSettingsTabBar(array $visibleTabs, ?string $activeId): void
{
    echo '<div class="tabs">' . "\n";
    foreach ($visibleTabs as $tab) {
        $id     = $tab['id'];
        $cap    = $tab['cap'] ?? null;
        $active = ($id === $activeId) ? ' active' : '';
        printf(
            '            <button class="tab%s" data-tab="%s" data-capability="%s" onclick="switchTab(\'%s\')">%s</button>' . "\n",
            $active,
            htmlspecialchars($id),
            htmlspecialchars($cap ?? 'none'),
            htmlspecialchars($id),
            htmlspecialchars(t($tab['label_key']))
        );
    }
    echo '        </div>' . "\n";
}
