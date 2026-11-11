<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The help assistant's written answers - "Ask" (wiki Help-Assistant-Design, step 5;
 * includes/help_ask.php).
 *
 * Part 1, no database, no AI: reading the AI's reply (its SOURCES line, inline
 * markers, NOT_COVERED in its forms, numbers it was never given); the rules it is
 * given; the text of a long section; permission marks.
 *
 * Part 2, FREEITSM_TEST_DATABASE (a throwaway install, help index built): against
 * the stand-in OpenAI-compatible server tests/ai-compatible/mock.php, which this
 * test starts - nothing paid, nothing leaves the machine:
 *   help-answer  a written answer, built from the sections it was given, citing them
 *   help-none    "not covered": an honest line, the closest sections, and the
 *                question on the unanswered list
 *   help-search  the AI searching again itself (the tool round trip)
 *   missing      the AI failing: the search reply, as if no AI were set up
 * The settings it touches are put back in finally.
 *
 *   php tests/help-ask.php
 *   FREEITSM_TEST_DATABASE=<the throwaway db> php tests/help-ask.php
 *
 * @wiki Help-Assistant-Developer-Guide
 */
chdir(__DIR__ . '/..');
require_once 'includes/help_assistant.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

// ─── Part 1 ─────────────────────────────────────────────────────────────────
echo "Reading the AI's reply\n";
$numbered = [1 => ['section_key' => 'a'], 2 => ['section_key' => 'b'], 3 => ['section_key' => 'c']];
$r = helpAskParse("Go to **Tickets → Settings** [1] and add one [1, 3].\nSOURCES: 1, 3", $numbered);
check('the SOURCES line becomes the sections it names, in order', array_column($r['sources'], 'section_key') === ['a', 'c'] && !$r['not_covered']);
check('...and is taken off the answer, with the inline [1] markers', $r['text'] === 'Go to **Tickets → Settings** and add one.', json_encode($r['text']));
check('a number it was never given is ignored', array_column(helpAskParse("x\nSOURCES: 2, 9", $numbered)['sources'], 'section_key') === ['b']);
check('NOT_COVERED, alone or dressed up, means not covered',
    helpAskParse('NOT_COVERED', $numbered)['not_covered'] && helpAskParse("  NOT_COVERED.\n", $numbered)['not_covered'] && helpAskParse("NOT_COVERED\nSOURCES: 1", $numbered)['not_covered']);
check('...or followed by an explanation of why (as models do)', helpAskParse("NOT_COVERED

The sections mention departments but give no steps.", $numbered)['not_covered']);
check('...but an answer that merely mentions the word is still an answer', !helpAskParse('The status NOT_COVERED appears when a ticket has no SLA policy covering it.', $numbered)['not_covered']);
$leak = "I need to search for this.\n<function_calls>\n<invoke name=\"search_help\">\n<parameter name=\"query\">assets access</parameter>\n</invoke>\n</function_calls>\n\nBased on the help sections, your colleague needs module access.\nSOURCES: 1";
$r = helpAskParse($leak, $numbered);
check('a tool call the model WROTE as text, and its narration, are dropped (seen with a real model)',
    $r['text'] === 'Your colleague needs module access.' && array_column($r['sources'], 'section_key') === ['a'], json_encode($r['text']));
check('a forbidden "Based on the help sections," opening is taken off', helpAskParse('Based on the help sections provided, go to Preferences.', $numbered)['text'] === 'Go to Preferences.');

echo "\nThe rules the AI is given\n";
$sys = helpAskSystemPrompt('de', 'tickets');
foreach ([
    'only FreeITSM\'s help, never other products' => 'ONLY from the numbered FreeITSM help sections',
    'never invent a menu or button'               => 'Never invent a menu, button',
    'the user\'s language, by name'               => 'Answer in German',
    'screen names kept as written'                => 'keep menu, button and setting names exactly as the sections write them',
    'brief'                                       => 'Be brief',
    'search again in English before giving up'    => 'English keywords',
    'NOT_COVERED when the help does not cover it' => 'exactly NOT_COVERED',
    'who, not how, without the permission'        => 'Do not explain how',
    'a SOURCES line'                              => 'SOURCES:',
    'the module the user is in'                   => 'the tickets module',
] as $what => $needle) check("the rules say: $what", str_contains($sys, $needle));

echo "\nThe help it reads\n";
$long = str_repeat('Filler about priorities and statuses. ', 120) . 'Departments Create departments like IT, HR and Finance.';
$t = helpAskSectionText(['body' => $long, 'passage' => 'Departments Create departments like IT, HR and Finance.']);
check('a long section is given from the passage that matched, not from its start', str_starts_with($t, 'Departments Create departments') && mb_strlen($t) <= HELP_ASK_SECTION_CHARS);
check('a short section is given whole', helpAskSectionText(['body' => 'Short.', 'passage' => '']) === 'Short.');
check('a section lacking permission is marked for the AI',
    str_contains(helpAskFormatSection(2, ['title' => 'Settings', 'page_title' => 'Tickets Help', 'body' => 'x'], true), '[2] Tickets Help > Settings [needs permission the user does not have]'));

// ─── Part 2 ─────────────────────────────────────────────────────────────────
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected) { echo "\n  (skipped the database checks: set FREEITSM_TEST_DATABASE)\n\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0); }
require_once 'config.php';
require_once 'includes/functions.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "\n  (skipped: config.php does not use $expected)\n\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0); }
if (!(int)$conn->query("SELECT COUNT(*) FROM help_sections")->fetchColumn()) { check('the help index is built', false); exit(1); }

$port = 19700 + random_int(0, 299);
$keys = ['help_ask_enabled', 'help_ai_provider', 'help_ai_model', 'help_ai_api_key', 'help_ai_compatible_base_url', 'help_vectors_enabled'];
$saved = [];
foreach ($keys as $k) { $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $st->execute([$k]); $saved[$k] = $st->fetchColumn(); }
$firstQ = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM help_assistant_unanswered")->fetchColumn();
$firstN = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM notifications")->fetchColumn();
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", 'tests/ai-compatible/mock.php'], [1 => ['file', sys_get_temp_dir() . "/help-ask-$port.out", 'w'], 2 => ['file', sys_get_temp_dir() . "/help-ask-$port.err", 'w']], $pipes, getcwd());
$admin = helpIndexRenderAnalyst($conn);
$model = function (string $m) use ($conn, $port): void {
    aiSettingsSave($conn, HELP_ASK_NS, ['provider' => 'openai_compatible', 'model' => $m, 'api_key' => '', 'clear_api_key' => true, 'compatible_base_url' => "http://127.0.0.1:$port/v1"]);
};
$ask = fn(string $q) => helpAssistantAnswer($conn, $admin, $q, 'en', null);
$zz = null;

try {
    for ($i = 0; $i < 40 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    check('the stand-in server started', (bool)@fsockopen('127.0.0.1', $port));
    helpAssistantSetSetting($conn, 'help_vectors_enabled', '0');   // words alone: the AI is what is under test

    echo "\nSwitched on\n";
    $model('help-answer');
    helpAssistantSetSetting($conn, 'help_ask_enabled', '0');
    check('off: the search reply, no AI', !isset($ask('how do I create a ticketing department')['ai']) && helpAssistantLevel($conn)['level'] !== 'ask');
    helpAssistantSetSetting($conn, 'help_ask_enabled', '1');
    check('on, with a provider set up: the level is "ask"', helpAssistantLevel($conn)['level'] === 'ask');

    echo "\nA written answer\n";
    $a = $ask('how do I create a ticketing department');
    check('the reply is a written answer', $a['kind'] === 'answer' && $a['text'] !== '', json_encode($a));
    check('...built from the sections it was given (it quotes the first one)', str_contains($a['text'], (string)($a['ai']['sections'][0]['title'] ?? '#')), $a['text']);
    check('...whose cards are the sections it cited', count($a['results']) === 1 && $a['results'][0]['title'] === ($a['ai']['sections'][0]['title'] ?? null));
    check('...with what it was given and how long it took, for Try it', count($a['ai']['sections']) >= 1 && $a['ai']['ms'] >= 0 && $a['ai']['model'] === 'help-answer');

    echo "\nThe help does not cover it\n";
    $model('help-none');
    $a = $ask('how do I create a ticketing department');
    check('an honest line and the closest sections', $a['kind'] === 'not_covered' && $a['text'] !== '' && count($a['results']) >= 1, json_encode(['kind' => $a['kind'], 'text' => $a['text']]));
    $id = helpAssistantRecordUnanswered($conn, $admin, 'how do I create a ticketing department', 'en', null, $a);
    check('...and the question joins the unanswered list', $id !== null);

    echo "\nSearching again itself\n";
    $model('help-search');
    $a = $ask('how do I create a ticketing department');
    check('it called search_help, with words of its own', ($a['ai']['searches'] ?? []) === ['department create'], json_encode($a['ai']['searches'] ?? null));
    check('...and answered from a section that search found', $a['kind'] === 'answer' && count($a['results']) === 1 && count($a['ai']['sections']) > HELP_ASK_SECTIONS - 1, json_encode(array_column($a['ai']['sections'] ?? [], 'n')));

    echo "\nWhen the AI fails\n";
    $model('missing');
    $a = $ask('how do I create a ticketing department');
    check('the search reply, as if no AI were set up', !isset($a['ai']) && in_array($a['kind'], ['clear', 'several', 'module', 'weak'], true) && $a['results'], $a['kind']);

    echo "\nPermissions\n";
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules) VALUES (?, '!', 'ZZ ask', 'zzask@example.invalid', 1, 0, 1)")->execute(['zzask' . bin2hex(random_bytes(3))]);
    $zz = (int)$conn->lastInsertId();
    check('an analyst without the permission a section is marked with does not hold it', !helpAskCapHeld($conn, $zz, 'tickets.manage'));
    check('...an administrator does, and an unmarked section is open to all', helpAskCapHeld($conn, $admin, 'tickets.manage') && helpAskCapHeld($conn, $zz, null));
    $marked = (int)$conn->query("SELECT COUNT(*) FROM help_sections WHERE locale = 'en' AND cap IS NOT NULL")->fetchColumn();
    check('the settings sections carry their marks in the index', $marked >= 11, (string)$marked);
} catch (Throwable $e) {
    check('the database checks ran: ' . $e->getMessage(), false);
} finally {
    if ($server) { proc_terminate($server); proc_close($server); }
    foreach ($saved as $k => $val) {
        if ($val === false) $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
        else $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $val]);
    }
    $conn->exec("DELETE FROM help_assistant_unanswered WHERE id > $firstQ");
    $conn->exec("DELETE FROM notifications WHERE event_type = 'help.unanswered' AND id > $firstN");
    if ($zz) $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$zz]);
    foreach (['out', 'err'] as $x) @unlink(sys_get_temp_dir() . "/help-ask-$port.$x");
    @unlink('tests/ai-compatible/last-request.json');
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
