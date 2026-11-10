<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Preference templates (discussion #124).
 *
 *   FREEITSM_TEST_DATABASE=freeitsm_test php tests/preference-templates.php
 *
 * 1. Every key the Preferences page saves is either in PREF_TEMPLATE_SECTIONS or
 *    listed as personal - so a new preference cannot silently miss templates.
 * 2. Capture takes exactly the ticked sections, with null for a key the source
 *    never set.
 * 3. Apply writes those keys, removes the ones held as null, and leaves every
 *    other preference alone.
 * 4. The "for new analysts" template reaches a new analyst.
 * 5. Empty Work sections and the new-ticket company survive capture, storage
 *    and apply, including explicit opt-outs and a return to built-in defaults.
 *
 * Throwaway fixtures (three ZZ analysts, one ZZ template) are deleted in `finally`.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/preference_templates.php';

$pass = 0; $fail = 0;
/**
 * Records and prints one PASS or FAIL line.
 *
 * @param string $what  what is being checked, in a few words
 * @param bool $cond  true when the check passed
 * @param string $detail  shown after a failure - the values that disagreed
 */
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

/**
 * One analyst's preferences, key => value.
 *
 * @param PDO $conn  the database
 * @param int $id  the analyst
 */
function prefsOf(PDO $conn, int $id): array {
    $st = $conn->prepare("SELECT preference_key, preference_value FROM user_preferences WHERE analyst_id = ?");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Sets one preference for an analyst.
 *
 * @param PDO $conn  the database
 * @param int $id  the analyst
 * @param string $key  the preference key
 * @param string $value  its value
 */
function setPref(PDO $conn, int $id, string $key, string $value): void {
    $conn->prepare("INSERT INTO user_preferences (analyst_id, preference_key, preference_value, updated_datetime)
                    VALUES (?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value)")
         ->execute([$id, $key, $value]);
}

/**
 * Creates a throwaway analyst and returns its id.
 *
 * @param PDO $conn  the database
 * @param string $tag  makes the username unique
 */
function zzAnalyst(PDO $conn, string $tag): int {
    $conn->prepare("INSERT INTO analysts (username, email, full_name, password_hash, is_active, is_admin)
                    VALUES (?, ?, ?, '!', 1, 0)")
         ->execute(["zz-test-pt-$tag", "zz-test-pt-$tag@invalid.test", "ZZ test pref templates $tag"]);
    return (int)$conn->lastInsertId();
}

echo "\nPreference templates (discussion #124)\n" . str_repeat('=', 70) . "\n";
$testDatabase = getenv('FREEITSM_TEST_DATABASE');
if (!$testDatabase) {
    fwrite(STDERR, "Set FREEITSM_TEST_DATABASE to the disposable install's database before running this writing test.\n");
    exit(1);
}
$conn = connectToDatabase();
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $testDatabase) {
    fwrite(STDERR, "FREEITSM_TEST_DATABASE does not match the connected database; no fixtures were written.\n");
    exit(1);
}

echo "\n  Every Preferences page key is accounted for\n";
$page = file_get_contents(__DIR__ . '/../system/preferences/index.php');
preg_match_all("/savePref\\('([a-z_]+)'/", $page, $m1);
preg_match_all("/(?<![a-z_])key: '([a-z_]+)'/", $page, $m2);   // not license_key etc.
preg_match("/\\\$prefDefaults = \\[(.*?)\\n\\];/s", $page, $pd);
preg_match_all("/^\\s*'([a-z_]+)'\\s*=>/m", $pd[1] ?? '', $m3);
$saved = array_unique(array_merge($m1[1], $m2[1], $m3[1], ['currency_format', 'currency_formats']));
$covered = array_merge(prefTemplateKeys(), PREF_TEMPLATE_PERSONAL);
$missing = array_values(array_diff($saved, $covered));
ok('found the keys the page saves (sanity: more than 20)', count($saved) > 20, (string)count($saved));
ok('each is in a template section or listed as personal', !$missing, implode(', ', $missing));

$src = 0; $dst = 0; $new = 0; $tplId = 0;
try {
    $src = zzAnalyst($conn, 'src');
    $dst = zzAnalyst($conn, 'dst');
    setPref($conn, $src, 'timezone', 'Pacific/Auckland');
    setPref($conn, $src, 'app_sidebar', '1');
    setPref($conn, $src, 'toast_position', 'top-left');            // a section NOT ticked
    setPref($conn, $dst, 'timezone', 'Europe/London');
    setPref($conn, $dst, 'app_sidebar_names', 'pinned');           // the source never set this
    setPref($conn, $dst, 'toast_position', 'bottom-left');

    echo "\n  Capture\n";
    $s = prefTemplateCapture($conn, $src, ['timezone', 'app-sidebar', 'not-a-section']);
    ok('takes the ticked sections\' keys only', array_keys($s) === ['timezone', 'app_sidebar', 'app_sidebar_modules', 'app_sidebar_names', 'app_sidebar_waffle'], json_encode(array_keys($s)));
    ok('copies the values the source has', $s['timezone'] === 'Pacific/Auckland' && $s['app_sidebar'] === '1');
    ok('holds null for a key the source never set', array_key_exists('app_sidebar_names', $s) && $s['app_sidebar_names'] === null);

    $conn->prepare("INSERT INTO preference_templates (name, settings, for_new_analysts, created_datetime, updated_datetime)
                    VALUES ('ZZ test template', ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([json_encode($s)]);
    $tplId = (int)$conn->lastInsertId();
    $row = $conn->query("SELECT * FROM preference_templates WHERE id = $tplId")->fetch(PDO::FETCH_ASSOC);
    $tpl = prefTemplateShape($row);
    ok('shape lists its sections in page order', $tpl['sections'] === ['timezone', 'app-sidebar'], json_encode($tpl['sections']));

    echo "\n  Apply\n";
    $n = prefTemplateApply($conn, $tpl, [$dst, $dst, 0]);
    $p = prefsOf($conn, $dst);
    ok('counts each person once', $n === 1, (string)$n);
    ok('overwrites their own choice', ($p['timezone'] ?? '') === 'Pacific/Auckland', $p['timezone'] ?? 'missing');
    ok('adds a setting they had not chosen', ($p['app_sidebar'] ?? '') === '1');
    ok('a null puts them back to the built-in default (row removed)', !array_key_exists('app_sidebar_names', $p));
    ok('leaves settings outside the template alone', ($p['toast_position'] ?? '') === 'bottom-left', $p['toast_position'] ?? 'missing');

    echo "\n  New analysts\n";
    $conn->prepare("UPDATE preference_templates SET for_new_analysts = 1 WHERE id = ?")->execute([$tplId]);
    $others = (int)$conn->query("SELECT COUNT(*) FROM preference_templates WHERE for_new_analysts = 1 AND id <> $tplId")->fetchColumn();
    if ($others === 0) {
        $new = zzAnalyst($conn, 'new');
        prefTemplatesApplyForNew($conn, $new);
        $p = prefsOf($conn, $new);
        ok('a new analyst gets the template', ($p['timezone'] ?? '') === 'Pacific/Auckland' && ($p['app_sidebar'] ?? '') === '1');
    } else {
        echo "  SKIP  another template is already given to new analysts on this install\n";
    }

    echo "\n  Work folding and new-ticket company (GH #218)\n";
    foreach ([
        'enabled' => ['tasks_collapse_empty_work' => 'on', 'tickets_new_ticket_company' => '7'],
        'disabled' => ['tasks_collapse_empty_work' => '', 'tickets_new_ticket_company' => ''],
        'defaults' => ['tasks_collapse_empty_work' => null, 'tickets_new_ticket_company' => null],
    ] as $case => $expected) {
        foreach ($expected as $key => $value) {
            if ($value === null) {
                $conn->prepare('DELETE FROM user_preferences WHERE analyst_id = ? AND preference_key = ?')->execute([$src, $key]);
            } else {
                setPref($conn, $src, $key, $value);
            }
            // Start with the opposite choice so a silently ignored key fails.
            setPref($conn, $dst, $key, $case === 'enabled' ? '' : ($key === 'tasks_collapse_empty_work' ? 'on' : '7'));
        }
        $captured = prefTemplateCapture($conn, $src, ['work-fold', 'new-ticket-company']);
        ok("$case: captures both selected sections", $captured === $expected, json_encode($captured));
        $conn->prepare('UPDATE preference_templates SET settings = ? WHERE id = ?')->execute([json_encode($captured), $tplId]);
        $row = $conn->query("SELECT * FROM preference_templates WHERE id = $tplId")->fetch(PDO::FETCH_ASSOC);
        $tpl = prefTemplateShape($row);
        ok("$case: stored values and sections survive loading", $tpl['settings'] === $expected && $tpl['sections'] === ['work-fold', 'new-ticket-company']);
        prefTemplateApply($conn, $tpl, [$dst]);
        $p = prefsOf($conn, $dst);
        foreach ($expected as $key => $value) {
            ok("$case: applies $key", $value === null ? !array_key_exists($key, $p) : ($p[$key] ?? null) === $value);
        }
        if ($new) {
            prefTemplatesApplyForNew($conn, $new);
            $p = prefsOf($conn, $new);
            foreach ($expected as $key => $value) {
                ok("$case: new-analyst default applies $key", $value === null ? !array_key_exists($key, $p) : ($p[$key] ?? null) === $value);
            }
        }
    }
} finally {
    if ($tplId) $conn->prepare("DELETE FROM preference_templates WHERE id = ? AND name = 'ZZ test template'")->execute([$tplId]);
    foreach ([$src, $dst, $new] as $id) {
        if (!$id) continue;
        $conn->prepare("DELETE FROM user_preferences WHERE analyst_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM analysts WHERE id = ? AND username LIKE 'zz-test-pt-%'")->execute([$id]);
    }
}
$left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM analysts WHERE username LIKE 'zz-test-pt-%') + (SELECT COUNT(*) FROM preference_templates WHERE name = 'ZZ test template')")->fetchColumn();
ok('cleanup: fixtures gone', $left === 0, "{$left} left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
