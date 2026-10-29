<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. Reads source files only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Every endpoint under api/ either checks module access (or admin), or is listed
 * below with the reason it must not.
 *
 * Module access (#30) first guarded pages and WRITE endpoints only, leaving reads
 * open because some are shared by several modules. That left 188 reads - ticket
 * threads, contracts, asset lists, API keys, decrypted settings - readable by any
 * signed-in analyst whose team was never given the module. They were classified
 * one by one by their real callers in October 2026; this test keeps it that way.
 *
 * A new endpoint fails here until it is either guarded:
 *     requireModuleAccessJson('tickets');
 *     requireAnyModuleAccessJson(['tickets', 'system']);   // shared by several
 *     requireModuleAccessJson('system');                   // administrators only
 * or added to $EXEMPT with the reason. "Every module's page calls it" is a
 * reason; "it's only a read" is not.
 *
 * Run: php tests/module-access-coverage.php
 */

$root = dirname(__DIR__);

// What counts as a guard: the helpers themselves, the other authorisation gates,
// and the two includes that guard at the top of every file that loads them.
$GUARD = '/requireModuleAccessJson|requireAnyModuleAccessJson|requireAdminJson|analystIsAdmin|analystCanAccessModule'
       . '|requireCapability|requireAiNamespaceJson|requireLmsCourseAccessJson'
       . '|admin_api_guard\.php|domains\/api_bootstrap\.php|projects\/api_bootstrap\.php|starters\/api_bootstrap\.php|files\/api_bootstrap\.php/';

// Whole folders with their own audience.
$EXEMPT_DIRS = [
    'api/v1/'           => 'REST API: authenticates by API key and checks each resource itself',
    'api/self-service/' => 'portal users, who have no modules - their own session and checks',
    'api/auth/'         => 'signing in and resetting a password happen before there is a session',
    'api/myaccount/'    => 'your own profile, signature, password and MFA - every analyst has one',
    'api/external/'     => 'inventory agents, authenticated by API key',
    'api/webchat/'      => 'the public web-chat widget on a customer website',
];

// Single files, each with the reason it is open.
$EXEMPT = [
    'api/calendar/feed.php'                => 'iCal feed, authenticated by a token in the URL - no session to check',
    'api/lms/test_public.php'              => 'a candidate sitting a competency test, authenticated by the link token - no session, no modules',
    'api/calendar/graph_notify.php'        => 'Microsoft Graph change notifications, verified by clientState',
    'api/tickets/schedule_feed.php'        => 'iCal feed, authenticated by a token in the URL - no session to check',
    'api/messaging/webhook.php'            => 'inbound messages from Telegram/WhatsApp/Slack, verified per channel',
    'api/messaging/media.php'              => 'media for inbound messages, served to the channel provider',
    'api/watchtower/get_dashboard_ext.php' => 'browser extension, authenticated by API key',
    'api/mcp/index.php'                    => 'MCP server: bearer API key with mcp.read, no session; each tool checks module access (includes/mcp/tools.php)',
    'api/change-management/_field_catalogue.php' => 'an include, not an endpoint',
    'api/cmdb/_ai_helpers.php'             => 'an include, not an endpoint',
    'api/forms/_ai_helpers.php'            => 'an include, not an endpoint',
    'api/workflow/_ai_helpers.php'         => 'an include, not an endpoint',
    'api/forms/lookup_search.php'          => 'analysts AND portal users; the scope is worked out per caller inside',
    'api/documents/attach.php'             => 'documents panel on every module; checks the parent record and its module',
    'api/documents/find.php'               => 'documents panel on every module; returns only documents you can already see',
    'api/documents/links.php'              => 'documents panel and global search; checks the parent record and its module',
    'api/documents/list.php'               => 'documents panel on every module; checks the parent record and its module',
    'api/documents/download.php'           => 'checks the parent record and its module, and logs the access',
    'api/documents/save.php'               => 'checks the parent record and its module before storing',
    'api/documents/unlink.php'             => 'checks the parent record and its module',
    'api/documents/help_centre.php'        => 'documents panel on knowledge articles; checks the article (and so the Knowledge module) and the document',
    'api/notifications/clear.php'          => 'your own notifications (the bell on every page)',
    'api/notifications/get_notifications.php' => 'your own notifications (the bell on every page)',
    'api/notifications/mark_read.php'      => 'your own notifications (the bell on every page)',
    'api/settings/save_system_settings.php' => 'generic writer: authorised PER KEY by includes/settings_keys.php',
    'api/system/set_user_preference.php'   => 'your own preferences, used by every module',
    'api/system/get_user_preference.php'   => 'your own preferences, used by every module',
    'api/system/ai/openrouter_models.php'  => 'refused unless you can manage an AI namespace',
    'api/system/get_branding.php'          => 'logo and header text only',
    'api/system/global_search.php'         => 'search bar on every page; filters each result type by module and company',
    'api/system/recent_trail.php'          => 'your own recent items; checks each type by module',
    'api/system/recent_trail_visit.php'    => 'records your own recent items',
    'api/system/record_preview.php'        => 'checks the module for each record type, then the record',
    'api/system/set_active_tenant.php'     => 'your own session; limited to companies you can access',
    'api/table-views/use.php'              => 'marks your own saved view as used',
    'api/table-views/delete.php'           => 'deletes only views you own',
    'api/tickets/list_timezones.php'       => 'the list of world timezones - nothing to protect',
];

$pass = 0; $fail = 0;
function check($ok, $label) {
    global $pass, $fail;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    $ok ? $pass++ : $fail++;
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/api', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (substr($f->getFilename(), -4) !== '.php') continue;
    $files[] = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
}
sort($files);

$unguarded = [];
$guarded = 0;
foreach ($files as $rel) {
    foreach ($EXEMPT_DIRS as $dir => $why) {
        if (str_starts_with($rel, $dir)) continue 2;
    }
    if (isset($EXEMPT[$rel])) continue;
    if (preg_match($GUARD, file_get_contents($root . '/' . $rel))) { $guarded++; continue; }
    $unguarded[] = $rel;
}

echo "1. Every endpoint checks module access, or says why not\n";
check(count($files) > 500, 'positive control: the endpoints were found (' . count($files) . ')');
check($guarded > 400, "positive control: guards were recognised ($guarded endpoints)");
check(!$unguarded, count($unguarded) . ' endpoint(s) with no guard and no listed reason');
foreach ($unguarded as $u) echo "          $u\n";

echo "2. The exemptions are still true\n";
$stale = array_values(array_filter(array_keys($EXEMPT), fn($p) => !is_file($root . '/' . $p)));
check(!$stale, 'every exempt file still exists' . ($stale ? ': ' . implode(', ', $stale) : ''));
$nowGuarded = array_values(array_filter(array_keys($EXEMPT), fn($p) => is_file($root . '/' . $p)
    && preg_match('/requireModuleAccessJson|requireAnyModuleAccessJson/', file_get_contents($root . '/' . $p))));
check(!$nowGuarded, 'no exempt file has since gained a guard (take it off the list)' . ($nowGuarded ? ': ' . implode(', ', $nowGuarded) : ''));

echo "3. Pages\n";
// The four Tickets pages that had no gate of their own (the shared header checks
// sign-in only after HTML has started, so its redirect could never fire).
foreach (['tickets/csat/index.php', 'tickets/dashboard/index.php', 'tickets/dashboard/library.php', 'tickets/triage/index.php'] as $p) {
    check(str_contains(file_get_contents($root . '/' . $p), "requireModuleAccess('tickets')"), "$p is gated on tickets");
}

echo "4. Read only modules: every write is refused to a read-only analyst (#180)\n";
// A module offers Read only (MODULES_WITH_READ_ONLY) only once every write endpoint calls
// requireModuleWriteJson(). Without this check Read only would be a promise with a hole
// in it - and the hole would look exactly like a working feature.
$READ_ONLY_DIRS = ['changes' => 'api/change-management/', 'forms' => 'api/forms/', 'tasks' => 'api/tasks/'];
$WRITE_EXEMPT = [
    // Approving is identity, not a write: a read-only approver must still approve.
    'api/change-management/submit_cab_vote.php'  => 'a CAB vote - needs a change_cab_members row, not write access',
    'api/change-management/decide_approval.php'  => 'the named approver deciding - identity, not write access',
    'api/forms/decide_catalogue_approval.php'    => 'the named approver deciding - identity, not write access',
];
$writeShaped = '/INSERT INTO|UPDATE [a-z_]+ SET|DELETE FROM|Service::(create|update|delete|save|link|unlink|cancel|override|move|set|submit)|uploadStoreFile|saveDraft|deleteDraft/';
$checked = 0; $holes = [];
require_once $root . '/config.php';
require_once $root . '/includes/functions.php';
foreach ($READ_ONLY_DIRS as $module => $dir) {
    check(in_array($module, MODULES_WITH_READ_ONLY, true), "$module is declared as offering Read only");
    foreach (glob($root . '/' . $dir . '*.php') as $abs) {
        $rel = $dir . basename($abs);
        if (basename($abs)[0] === '_') continue;
        $src = file_get_contents($abs);
        if (!preg_match($writeShaped, $src)) continue;                        // a read
        if (preg_match('/requireCapabilityJson|admin_api_guard/', $src) || str_contains(basename($abs), 'settings')) continue;   // a settings tab: its own permission
        if (isset($WRITE_EXEMPT[$rel])) continue;
        $checked++;
        if (strpos($src, "requireModuleWriteJson('{$module}')") === false) $holes[] = $rel;
    }
}
check($checked >= 20, "positive control: write endpoints were found ($checked)");
check(!$holes, count($holes) . ' write endpoint(s) a read-only analyst could still use');
foreach ($holes as $h) echo "          $h\n";

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
