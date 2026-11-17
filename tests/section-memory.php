<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Reopen the last section of each module (GH #376).
 *
 *   php tests/section-memory.php
 *
 * With System -> Preferences -> "Reopen the last section of each module" on, a
 * module page remembers its active header-nav button, and a general way into a
 * module (waffle menu, sidebar, home card, Ctrl+K) goes back to it. Links to a
 * particular page, the module's own nav buttons and new-tab clicks are untouched.
 *
 * Runs the REAL assets/js/section-memory.js in headless Chrome against a page that
 * plays an Assets section page, with navigation captured instead of followed.
 * Plus: the preference is off by default, and the PHP side prints nothing unless
 * it is on. No database writes.
 */
require __DIR__ . '/support/ticket-workspace-browser.php';
$root = dirname(__DIR__);
$js = str_replace('</script', '<\/script', (string)file_get_contents("$root/assets/js/section-memory.js"));

$page = function (string $module, string $activeHref, string $before, string $checks) use ($js): string {
    $cfg = json_encode(['on' => true, 'analyst' => 7, 'module' => $module, 'base' => '/',
        'roots' => ['assets' => 'asset-management/', 'tickets' => 'tickets/', 'calendar' => 'calendar/']]);
    return '<!doctype html><html><body>
<nav class="header-nav"><a class="nav-btn" href="/asset-management/">Assets</a><a class="nav-btn active" href="' . $activeHref . '">Section</a><a class="nav-btn" href="/asset-management/help.php">Help</a></nav>
<a id="waffleAssets" class="waffle-module-link" href="/asset-management/">Assets</a>
<a id="waffleTickets" class="waffle-module-link" href="/tickets/">Tickets</a>
<a id="cardAssets" class="module-card" href="asset-management/">Assets</a>
<a id="sideAssets" class="app-sidebar-link" href="/asset-management/">Assets</a>
<a id="recordLink" href="/asset-management/?asset=42">A record</a>
<pre id="result"></pre>
<script>' . $before . '</script>
<script>window.FREEITSM_SECTIONS = ' . $cfg . ';</script>
<script>' . $js . '</script>
<script>
const checks = [], check = (label, pass, detail) => checks.push({label, pass: !!pass, detail});
let went = null; window.FreeITSMSections.navigate = u => { went = u; };
// Clicks never leave the page: anything this script did not stop is stopped here, after it.
document.addEventListener("click", e => e.preventDefault());
const click = (id, opts) => { went = null; document.getElementById(id).dispatchEvent(new MouseEvent("click", Object.assign({bubbles: true, cancelable: true, button: 0}, opts || {}))); return went; };
// After DOMContentLoaded, when the page has remembered its own section.
document.addEventListener("DOMContentLoaded", () => {
    try { ' . $checks . ' } catch (e) { check("test runs", false, String(e)); }
    document.getElementById("result").textContent = JSON.stringify(checks);
});
</script></body></html>';
};

// Page 1: in Tickets, with Assets -> Servers remembered from earlier.
$p1 = $page('tickets', '/tickets/settings/', 'localStorage.setItem("freeitsm.section.7.assets", "/asset-management/servers/");', '
check("the waffle link to Assets goes to the remembered section (Servers)", click("waffleAssets") === "/asset-management/servers/", went);
check("so do the home card and the sidebar", click("cardAssets") === "/asset-management/servers/" && click("sideAssets") === "/asset-management/servers/", went);
check("Ctrl+K resolves the same way", window.FreeITSMSections.resolve("/asset-management/") === "/asset-management/servers/");
check("a link to a particular record is untouched", click("recordLink") === null, went);
check("a new-tab click (Ctrl) goes as asked", click("waffleAssets", {ctrlKey: true}) === null, went);
check("each module keeps its own: Tickets remembers the page it is on now", localStorage.getItem("freeitsm.section.7.tickets") === "/tickets/settings/", localStorage.getItem("freeitsm.section.7.tickets"));
check("...and the waffle link to the module you are in goes to its front page", click("waffleTickets") === null, went);
check("another analyst\'s memory is separate", localStorage.getItem("freeitsm.section.8.assets") === null);
localStorage.setItem("freeitsm.section.7.assets", "/elsewhere/evil/");
check("a remembered address outside the module\'s folder is ignored", click("waffleAssets") === null, went);
');
// Page 2: in Assets on its Help page - never remembered.
$p2 = $page('assets', '/asset-management/help.php', 'localStorage.setItem("freeitsm.section.7.assets", "/asset-management/servers/");', '
check("Help is never remembered as the section", localStorage.getItem("freeitsm.section.7.assets") === "/asset-management/servers/", localStorage.getItem("freeitsm.section.7.assets"));
');

$exit = ticketWorkspaceBrowser(['index.html' => $p1]);
$exit = ticketWorkspaceBrowser(['index.html' => $p2]) || $exit;

// PHP side: off by default, and nothing printed unless on.
$fail = 0;
/** Prints one PASS or FAIL line and counts the failures. */
function smCheck(string $label, bool $cond): void { global $fail; echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n"; if (!$cond) $fail++; }
$prefs = (string)file_get_contents("$root/system/preferences/index.php");
smCheck('the preference is off by default', (bool)preg_match("/'remember_module_section'\s*=>\s*''/", $prefs));
$waffle = (string)file_get_contents("$root/includes/waffle-menu.php");
smCheck('the script is only printed when the preference is on', str_contains($waffle, 'if (!$on) return;'));
exit(($exit || $fail) ? 1 : 0);
