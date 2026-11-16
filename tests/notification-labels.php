<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Every notification type has an English bell line (common.notifications.event.<type>)
 * and a Preferences label (common.notifications.pref.<type>). A missing one shows the raw
 * key - Preferences listed "common.notifications.pref.ticket.team_assigned".
 *
 * No database.
 *   php tests/notification-labels.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/i18n.php";
require_once "$root/includes/services/notifications.php";

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nNotification labels\n" . str_repeat('=', 72) . "\n";
I18n::setLocale('en');
$types = NotificationsService::types();
ok('control: there are notification types to check', count($types) > 10, (string)count($types));
foreach (array_keys($types) as $type) {
    foreach (['event', 'pref'] as $kind) {
        $key = "common.notifications.$kind.$type";
        $text = t($key);
        ok("$kind label for $type", $text !== $key && $text !== '', $text);
    }
}
ok('control: a type that does not exist has no label', t('common.notifications.pref.zz.nothing') === 'common.notifications.pref.zz.nothing');

// GH #365: the same gap in another language reads the same way - a bare name in the
// bell, which looked like the wrong assignee. The complete languages (the ones the
// i18n gate holds at 100%) must have their own line for every type, not English.
echo "\nIn the complete languages\n" . str_repeat('=', 72) . "\n";
foreach (['da', 'de', 'el', 'es', 'fr', 'id', 'pl', 'uk'] as $locale) {
    $strings = include "$root/lang/$locale/common.php";
    $missing = [];
    foreach (array_keys($types) as $type) {
        [$group, $name] = explode('.', $type, 2);
        foreach (['event', 'pref'] as $kind) {
            if (!isset($strings['notifications'][$kind][$group][$name])) $missing[] = "$kind.$type";
        }
    }
    ok("$locale has both labels for every type", !$missing, implode(', ', $missing));
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
