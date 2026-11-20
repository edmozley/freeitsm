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
 * @param array $manifest  a module's settings manifest (<module>/settings/manifest.php), with its 'tabs' list
 * @return array<int,array> the visible tab definitions
 */
function settingsVisibleTabs(PDO $conn, int $analystId, array $manifest): array
{
    $visible = [];
    foreach ($manifest['tabs'] as $tab) {
        if (($tab['cap'] ?? null) === null) {
            $visible[] = $tab;                     // personal preference — never gated
        } elseif (analystHasCapability($conn, $analystId, Cap::from($tab['cap']))) {
            $visible[] = $tab;
        } else {
            // 'also_visible_to': capabilities that may SEE the tab without holding it - an
            // approver has to read what they approve (#180: template approvers). Saving is
            // still the tab's own capability, checked by its endpoints.
            foreach ($tab['also_visible_to'] ?? [] as $cap) {
                if (analystHasCapability($conn, $analystId, Cap::from($cap))) { $visible[] = $tab; break; }
            }
        }
    }
    return $visible;
}

/**
 * Is this tab id in the visible set? Use to decide whether to emit its panel.
 *
 * @param array $visibleTabs  the tabs from settingsVisibleTabs()
 * @param string $tabId  the tab's id from the manifest, e.g. 'vcenter'
 */
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
 *
 * @param array $visibleTabs  the tabs from settingsVisibleTabs()
 */
function settingsFirstTabId(array $visibleTabs): ?string
{
    // A tab that moved to another page (GH #263) has no panel here to open.
    foreach ($visibleTabs as $tab) {
        if (empty($tab['moved_to'])) return $tab['id'];
    }
    return null;
}

/**
 * Where a tab that moved to another page lives now (GH #263: Intune, to System ->
 * Integrations -> Microsoft 365) - or null. The tab stays in its manifest, because
 * the manifest is what grants its capability and owns its setting keys; only its
 * page moved. Its old link is redirected there, and the tab bar links to it.
 *
 * @param array  $visibleTabs  the tabs from settingsVisibleTabs()
 * @param string $tabId        the tab asked for
 * @return ?string  the path from the install root, or null
 */
function settingsMovedTo(array $visibleTabs, string $tabId): ?string
{
    foreach ($visibleTabs as $tab) {
        if ($tab['id'] === $tabId && !empty($tab['moved_to'])) return (string)$tab['moved_to'];
    }
    return null;
}

/**
 * Does this analyst hold ANY administrative capability in this module?
 *
 * @param array $visibleTabs  the tabs from settingsVisibleTabs()
 */
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
 *
 * @param array $visibleTabs  the tabs from settingsVisibleTabs(), drawn in that order
 * @param ?string $activeId  the id of the tab drawn as active; null for none
 * @param string $onclickFn  the name of the page's JavaScript tab switcher, called with the tab id
 */
function renderSettingsTabBar(array $visibleTabs, ?string $activeId, string $onclickFn = 'switchTab'): void
{
    echo '<div class="tabs">' . "\n";
    foreach ($visibleTabs as $tab) {
        $id     = $tab['id'];
        $cap    = $tab['cap'] ?? null;
        $active = ($id === $activeId) ? ' active' : '';

        // A tab names an i18n key, OR — for a module that is not translated — a plain
        // label. Problem Management's tab bar is hardcoded English today, and half
        // translating it here would be worse than being honest about it.
        $label = isset($tab['label_key']) ? t($tab['label_key']) : ($tab['label'] ?? $id);

        // A tab that moved to another page (GH #263) is a link to it, marked as leaving.
        if (!empty($tab['moved_to'])) {
            printf(
                '            <a class="tab" href="%s" data-tab="%s" data-capability="%s" style="text-decoration:none;color:inherit;">%s &#8599;</a>' . "\n",
                htmlspecialchars((defined('BASE_URL') ? BASE_URL : '/') . $tab['moved_to']),
                htmlspecialchars($id),
                htmlspecialchars($cap ?? 'none'),
                htmlspecialchars($label)
            );
            continue;
        }

        printf(
            '            <button class="tab%s" data-tab="%s" data-capability="%s" onclick="%s(\'%s\')">%s</button>' . "\n",
            $active,
            htmlspecialchars($id),
            htmlspecialchars($cap ?? 'none'),
            htmlspecialchars($onclickFn),   // not every module calls its switcher switchTab()
            htmlspecialchars($id),
            htmlspecialchars($label)
        );
    }
    echo '        </div>' . "\n";
    renderSettingsViewSwitch();
}

/**
 * GH #399: the Tabs / One page switch for a settings page, once per page.
 *
 * One page shows every panel at once with an index down the left and a search box; Tabs
 * is the page as it always was, and the default. The choice is the person's own, kept in
 * user_preferences (settings_view = 'page' | ''), and applies to every module's settings.
 * Everything else is assets/js/settings-view.js, which steps aside on a page whose tabs it
 * cannot match to panels (the SSO pages draw a tab bar with no panels) and on a phone.
 */
function renderSettingsViewSwitch(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $mode = '';
    try {
        if (!empty($_SESSION['analyst_id'])) {
            $st = connectToDatabase()->prepare("SELECT preference_value FROM user_preferences WHERE analyst_id = ? AND preference_key = 'settings_view'");
            $st->execute([(int)$_SESSION['analyst_id']]);
            $mode = (string)$st->fetchColumn() === 'page' ? 'page' : '';
        }
    } catch (Throwable $e) { $mode = ''; }
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $cfg = ['mode' => $mode, 'save' => $base . 'api/system/set_user_preference.php',
            'labels' => function_exists('t') ? [
                'view' => t('common.settings_view.view'), 'tabs' => t('common.settings_view.tabs'), 'page' => t('common.settings_view.page'),
                'search' => t('common.settings_view.search'), 'none' => t('common.settings_view.none'), 'index' => t('common.settings_view.index'),
                'elsewhere' => t('common.settings_view.elsewhere'),
            ] : []];
    echo '        <link rel="stylesheet" href="' . htmlspecialchars($base) . 'assets/css/settings-view.css?v=1">' . "\n";
    echo '        <script>window.SETTINGS_VIEW = ' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';</script>' . "\n";
    echo '        <script src="' . htmlspecialchars($base) . 'assets/js/settings-view.js?v=1" defer></script>' . "\n";
}
