<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables - see tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Intune is set up under System -> Integrations -> Microsoft 365 (GH #263).
 *
 * It was the Intune tab of Assets -> Settings. It moved beside Entra ID, and
 * nobody who could use it there may lose it:
 *
 *   - the tab stays declared in the Assets manifest - its capability (granted in
 *     System -> Roles) and the intune_* keys it may write - with 'moved_to';
 *   - the tab bar links to the new page; its old link (?tab=intune) is redirected;
 *   - the System pages let an administrator in, or an analyst with the Assets
 *     module and Cap::ASSETS_INTUNE - and nobody else;
 *   - the Assets settings page has no Intune panel left, and Proxmox and vCloud
 *     each have their own guard (they used to sit inside Intune's);
 *   - nothing links to the old tab any more.
 *
 * Writes (two analysts, a role), so it runs only when FREEITSM_TEST_DATABASE names
 * the database config.php points at. Everything is named ZZINT and removed at the end.
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/intune-system-page.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/settings_manifest.php';
require_once __DIR__ . '/../includes/settings_keys.php';
$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  - $detail" : '') . "\n"; }
}

echo "Intune under System -> Integrations -> Microsoft 365 (GH #263)\n" . str_repeat('=', 64) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes analysts and a role.\n");
}

// ── the manifest: the tab stays, and says where it went ─────────────────────
$manifest = settingsManifestFor('assets');
$tab = null;
foreach ($manifest['tabs'] as $t) if ($t['id'] === 'intune') $tab = $t;
check('the Intune tab is still declared in the Assets manifest, with its capability', $tab && $tab['cap'] === capKey(Cap::ASSETS_INTUNE));
check('...and says where it went', $tab && ($tab['moved_to'] ?? '') === 'system/integrations/microsoft365/intune.php');
$owner = settingKeyOwner('intune_tenant_id');
check('the intune_* keys are still the Intune tab\'s to write', $owner !== null && (($owner['cap'] ?? null) === capKey(Cap::ASSETS_INTUNE) || ($owner['tab'] ?? null) === 'intune'), json_encode($owner));
check('the file it moved to exists', is_file($root . '/' . ($tab['moved_to'] ?? 'x')));

// ── the tab bar and the old link ─────────────────────────────────────────────
$visible = [['id' => 'intune', 'cap' => capKey(Cap::ASSETS_INTUNE), 'label' => 'Intune', 'moved_to' => 'system/integrations/microsoft365/intune.php'],
            ['id' => 'vcenter', 'cap' => capKey(Cap::ASSETS_VCENTER), 'label' => 'vCenter']];
check('a tab that moved is never the one to open first', settingsFirstTabId($visible) === 'vcenter');
check('...nor is anything, when it is the only one', settingsFirstTabId([$visible[0]]) === null);
check('its old ?tab= link resolves to where it went', settingsMovedTo($visible, 'intune') === 'system/integrations/microsoft365/intune.php'
    && settingsMovedTo($visible, 'vcenter') === null && settingsMovedTo($visible, '') === null);
ob_start(); renderSettingsTabBar($visible, 'vcenter'); $bar = ob_get_clean();
check('the tab bar links to the new page, and switches to the others as before',
    str_contains($bar, 'href="' . (defined('BASE_URL') ? BASE_URL : '/') . 'system/integrations/microsoft365/intune.php"')
    && str_contains($bar, "switchTab('vcenter')") && !str_contains($bar, "switchTab('intune')"), $bar);

// ── the Assets settings page ────────────────────────────────────────────────
$page = (string)file_get_contents($root . '/asset-management/settings/index.php');
check('the Assets settings page has no Intune panel or script left',
    !str_contains($page, 'id="intune-tab"') && !str_contains($page, 'function saveIntuneSettings') && !str_contains($page, 'intuneTenantId'));
check('Proxmox and vCloud each have their own guard (they sat inside Intune\'s)',
    str_contains($page, "settingsTabVisible(\$visibleTabs, 'proxmox')") && str_contains($page, "settingsTabVisible(\$visibleTabs, 'vcloud')")
    && !str_contains($page, "settingsTabVisible(\$visibleTabs, 'intune')"));
$moved = (string)file_get_contents($root . '/system/integrations/microsoft365/intune.php');
check('the new page has everything the tab had', str_contains($moved, 'function saveIntuneSettings') && str_contains($moved, 'id="intuneTypeMap"')
    && str_contains($moved, 'function startAppSync') && str_contains($moved, 'function loadIntuneFreshness') && str_contains($moved, "Cap::ASSETS_INTUNE"));

// ── the hub: Entra, Intune, and the map of the rest ─────────────────────────
$hub = (string)file_get_contents($root . '/system/integrations/microsoft365/index.php');
check('Microsoft 365 lists everything else that talks to Microsoft, and where it is set up',
    str_contains($hub, "tickets/settings/#mailboxes") && str_contains($hub, "'../../calendar-sync/'")
    && str_contains($hub, "'../msteams'") && str_contains($hub, "tickets/settings/#messaging") && str_contains($hub, 'mailboxAuthenticatedSql()'));
check('...to administrators only', (bool)preg_match('~\$more = \[\];\s*if \(\$isAdmin\)~', $hub));
check('the Integrations card opens it, in place of the Entra signpost',
    str_contains((string)file_get_contents($root . '/system/integrations/index.php'), 'href="microsoft365/"'));

// ── nothing links to the old tab ─────────────────────────────────────────────
$stale = [];
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    fn($f) => !$f->isDir() || !in_array($f->getFilename(), ['.git', 'vendor', 'node_modules', 'engineering-wiki', 'tests', 'uploads', 'tickets'], true)
));
foreach ($it as $f) {
    $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
    if (!in_array($ext, ['php', 'js'], true)) continue;
    if (str_contains((string)file_get_contents($f->getPathname()), 'settings/?tab=intune')) $stale[] = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1);
}
check('nothing links to Assets -> Settings -> ?tab=intune any more', !$stale, implode(', ', $stale));

// ── who may open it ──────────────────────────────────────────────────────────
require_once $root . '/system/includes/page_access.php';   // systemPageAllowed(), without running the gate
$made = ['analysts' => [], 'role' => null];
$tag = 'zzint' . bin2hex(random_bytes(3));
try {
    $mk = function (string $name, int $admin) use ($conn, $tag, &$made) {
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin) VALUES (?, 'x', ?, ?, 1, ?)")
             ->execute([$tag . $name, 'ZZINT ' . $name, $tag . $name . '@example.invalid', $admin]);
        return $made['analysts'][] = (int)$conn->lastInsertId();
    };
    $admin = $mk('admin', 1); $holder = $mk('holder', 0); $plain = $mk('plain', 0);
    $conn->prepare("INSERT INTO rbac_roles (name, is_active) VALUES (?, 1)")->execute(['ZZINT intune ' . $tag]);
    $made['role'] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO rbac_role_capabilities (role_id, capability_key) VALUES (?, ?)")->execute([$made['role'], capKey(Cap::ASSETS_INTUNE)]);
    $conn->prepare("INSERT INTO rbac_analyst_roles (analyst_id, role_id) VALUES (?, ?)")->execute([$holder, $made['role']]);
    // The capability without the Assets module: set up before anything asks (the answers are cached per request).
    $noAssets = $mk('noassets', 0);
    $conn->prepare("INSERT INTO rbac_analyst_roles (analyst_id, role_id) VALUES (?, ?)")->execute([$noAssets, $made['role']]);
    $conn->prepare("UPDATE analysts SET can_access_all_modules = 0 WHERE id = ?")->execute([$noAssets]);
    $conn->prepare("INSERT INTO analyst_modules (analyst_id, module_key, access_level) VALUES (?, 'tickets', 'full')")->execute([$noAssets]);
    $grant = ['module' => 'assets', 'cap' => Cap::ASSETS_INTUNE];

    check('an administrator may open the Intune page', systemPageAllowed($conn, $admin, 'integrations', $grant));
    check('an analyst who may configure Intune may open it', systemPageAllowed($conn, $holder, 'integrations', $grant));
    check('...but no other System page', !systemPageAllowed($conn, $holder, 'integrations', null));
    check('an analyst without it may not', !systemPageAllowed($conn, $plain, 'integrations', $grant));
    check('every analyst may still open Preferences', systemPageAllowed($conn, $plain, 'preferences', null));
    // Without the Assets module the capability is not enough - as on the endpoints.
    check('...nor one who has the capability but not the Assets module', !systemPageAllowed($conn, $noAssets, 'integrations', $grant));
} catch (Throwable $e) {
    check('no unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($made['analysts'] as $a) {
        $conn->prepare("DELETE FROM analyst_modules WHERE analyst_id = ?")->execute([$a]);
        $conn->prepare("DELETE FROM rbac_analyst_roles WHERE analyst_id = ?")->execute([$a]);
        try { $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$a]); } catch (Throwable $e) {}
    }
    if ($made['role']) {
        $conn->prepare("DELETE FROM rbac_role_capabilities WHERE role_id = ?")->execute([$made['role']]);
        $conn->prepare("DELETE FROM rbac_roles WHERE id = ?")->execute([$made['role']]);
    }
    $left = (int)$conn->query("SELECT COUNT(*) FROM analysts WHERE username LIKE '$tag%'")->fetchColumn();
    check('cleanup removed everything it made', $left === 0, "$left left");
    echo str_repeat('-', 64) . "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
