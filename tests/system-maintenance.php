<?php
/**
 * System -> Maintenance (GH #263): the tools registry, its cards' counts, and
 * that moving a tool under it loses neither its page, its help nor the System
 * search finding it.
 *
 *   php tests/system-maintenance.php
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/system-maintenance.php
 *
 * Part 1 needs no database. Part 2 runs only when FREEITSM_TEST_DATABASE names
 * the database config.php points at: it makes a ticket in a department that does
 * not exist, inside a transaction that is rolled back, so it leaves nothing.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'config.php';
require 'includes/functions.php';
require 'includes/i18n.php';
require 'system/includes/areas.php';
require 'system/maintenance/includes/tools.php';
require 'system/help/_registry.php';

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok    $label\n"; }
    else { $fail++; echo "  FAIL  $label\n        got  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n        want " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "Part 1 - the registry\n";
// ─────────────────────────────────────────────────────────────────────────────
$en = require 'lang/en/system.php';
$has = function (string $key) use ($en): bool {
    $parts = explode('.', $key);
    if (array_shift($parts) !== 'system') return false;
    $v = $en;
    foreach ($parts as $p) { if (!is_array($v) || !array_key_exists($p, $v)) return false; $v = $v[$p]; }
    return is_string($v) && $v !== '';
};

$tools = getMaintenanceTools();
$topics = getHelpTopics();
check('Maintenance has at least one tool', count($tools) > 0, true);
check('no tool is listed twice', count(array_unique(array_column($tools, 'url'))) === count($tools), true);
foreach ($tools as $tool) {
    $slug = rtrim($tool['url'], '/');
    check("$slug: its page exists, at the URL it always had", is_file("system/$slug/index.php"), true);
    check("$slug: title, description and keywords are English strings", $has($tool['title']) && $has($tool['desc']) && $has($tool['keywords']), true);
    check("$slug: has a help page", isset($topics[$slug]) && is_file("system/help/$slug.php"), true);
    check("$slug: its count, when it has one, is a function", !isset($tool['count']) || is_callable($tool['count']), true);
    check("$slug: no longer a card of its own on the System landing", in_array($tool['url'], array_column(getSystemAreas(), 'url'), true), false);
}

$areas = array_column(getSystemAreas(), null, 'url');
check('the System landing has a Maintenance card', isset($areas['maintenance/']), true);
check('Maintenance has its strings and a help page', $has('system.landing.maintenance_title') && isset($topics['maintenance']) && is_file('system/help/maintenance.php'), true);
foreach (['heading', 'intro', 'search_placeholder', 'no_results', 'count_found', 'count_none', 'back'] as $k) {
    check("string system.maintenance.$k", $has("system.maintenance.$k"), true);
}
$kw = mb_strtolower(systemAreaKeywords($areas['maintenance/']));
check('searching System for "orphaned" still finds the card that leads to it', str_contains($kw, 'orphaned tickets'), true);
$help = array_column(helpCards(true), 'slug');
check('System help still offers Orphaned tickets, straight after Maintenance',
    array_search('orphaned-tickets', $help, true) === array_search('maintenance', $help, true) + 1, true);
// A PDO that was never connected: enough for counts that never reach it.
$noDb = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
function zzCountThatFails(PDO $conn): int { throw new RuntimeException('no table'); }
check('a tool whose count fails shows no count, rather than breaking the page', maintenanceToolCount($noDb, ['count' => 'zzCountThatFails']), null);
check('a tool without a count shows none', maintenanceToolCount($noDb, ['url' => 'x/']), null);

// ─────────────────────────────────────────────────────────────────────────────
echo "Part 2 - the Orphaned tickets count\n";
// ─────────────────────────────────────────────────────────────────────────────
$conn = connectToDatabase();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    echo "  skip  set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this part writes a ticket\n";
} else {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->beginTransaction();
    try {
        $before = maintenanceCountOrphanedTickets($conn);
        $gone = (int)$conn->query("SELECT COALESCE(MAX(id), 0) + 1000 FROM departments")->fetchColumn();
        $conn->exec("SET FOREIGN_KEY_CHECKS = 0");
        $ins = $conn->prepare("INSERT INTO tickets (ticket_number, subject, department_id, created_datetime, deleted_datetime) VALUES (?, ?, ?, NOW(), ?)");
        $ins->execute(['ZZ-MAINT-1', 'ZZ maintenance test', $gone, null]);
        $ins->execute(['ZZ-MAINT-2', 'ZZ maintenance test, binned', $gone, date('Y-m-d H:i:s')]);
        $conn->exec("SET FOREIGN_KEY_CHECKS = 1");
        check('a ticket in a department that does not exist is counted; a binned one is not', maintenanceCountOrphanedTickets($conn), $before + 1);
        check('the card shows that count', maintenanceToolCount($conn, $tools[0]), $before + 1);
    } finally {
        $conn->rollBack();
        $conn->exec("SET FOREIGN_KEY_CHECKS = 1");
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
