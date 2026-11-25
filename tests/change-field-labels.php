<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A change form field's own name (GH #435): includes/changes/field_labels.php.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/change-field-labels.php
 *
 * Pins: with no name set every field shows its standard (translated) name; a name set on
 * a field replaces it - looked up by the field's key AND by the other keys screens use for
 * it (priority_id, test_plan...); blank is the standard name again; CAB and Attachments
 * cannot be renamed; the script the pages print carries the names. Runs in a transaction
 * that is rolled back, so the install's own names are untouched.
 *
 * @wiki Change-Management
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/changes/field_labels.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at.\n");
}
try { $conn->query("SELECT label FROM change_field_layout LIMIT 0"); }
catch (PDOException $e) { echo "  SKIP  run Database Verification first: no change_field_layout.label\n"; exit(0); }
if (!$conn->query("SELECT 1 FROM change_field_layout WHERE field_key IN ('description', 'priority') HAVING COUNT(*) = 2")->fetchColumn()) {
    echo "  SKIP  this database has no layout rows for description and priority\n"; exit(0);
}

echo "\nChange form: a field's own name\n" . str_repeat('=', 60) . "\n";
// Whatever names this database already has are put back afterwards (part of the test commits).
$before = $conn->query("SELECT field_key, label FROM change_field_layout")->fetchAll(PDO::FETCH_KEY_PAIR);
$conn->beginTransaction();
try {
    $conn->exec("UPDATE change_field_layout SET label = NULL");
    ok('with no names set, a field shows its standard name', changeFieldLabel($conn, 'description') === t('change-management.editor.tab_description')
        && changeFieldLabel($conn, 'priority') === t('change-management.editor.priority'));

    $conn->exec("UPDATE change_field_layout SET label = 'Implementation Plan' WHERE field_key = 'description'");
    $conn->exec("UPDATE change_field_layout SET label = 'Importance' WHERE field_key = 'priority'");
    // the per-request cache: run the lookups in a fresh PHP process, as the next page load would
    $php = escapeshellarg(PHP_BINARY);
    $code = 'require ' . var_export(__DIR__ . '/../config.php', true) . '; require ' . var_export(__DIR__ . '/../includes/functions.php', true) . '; require ' . var_export(__DIR__ . '/../includes/changes/field_labels.php', true) . ';'
          . '$c = connectToDatabase(); echo json_encode([changeFieldLabel($c, "description"), changeFieldLabel($c, "test_plan"), changeFieldLabel($c, "priority_id"), changeFieldLabel($c, "reason"), changeFieldCustomLabels($c)]);';
    // a separate connection cannot see an uncommitted row - so this part commits and puts it back in finally
    $conn->commit();
    // TRAP: a file, not php -r - on Windows escapeshellarg() turns every " in the code into a space.
    $tmp = tempnam(sys_get_temp_dir(), 'zzcfl') . '.php';
    file_put_contents($tmp, '<?php ' . $code);
    $out = json_decode((string)shell_exec($php . ' ' . escapeshellarg($tmp)), true);
    @unlink($tmp);
    ok('a name set replaces the standard one', ($out[0] ?? null) === 'Implementation Plan', json_encode($out));
    ok('...and is found by the other keys screens use (priority_id)', ($out[2] ?? null) === 'Importance');
    ok('a field without one keeps its standard name', ($out[1] ?? null) === t('change-management.editor.tab_testplan') && ($out[3] ?? null) === t('change-management.editor.tab_reason'));
    ok('only fields with a name are in the list the pages get', ($out[4] ?? null) === ['description' => 'Implementation Plan', 'priority' => 'Importance']
        || ($out[4] ?? null) === ['priority' => 'Importance', 'description' => 'Implementation Plan'], json_encode($out[4] ?? null));
    ok('CAB and Attachments cannot be renamed', !isset(CHANGE_FIELD_STANDARD_NAMES['cab']) && !isset(CHANGE_FIELD_STANDARD_NAMES['attachments']));
    ok('every renamable field is on the form catalogue', (function () {
        require_once __DIR__ . '/../api/change-management/_field_catalogue.php';
        return !array_diff(array_keys(CHANGE_FIELD_STANDARD_NAMES), array_keys(FIELD_CATALOGUE));
    })());
    ok('every alias points at a renamable field', !array_diff(array_values(CHANGE_FIELD_ALIASES), array_keys(CHANGE_FIELD_STANDARD_NAMES)));
    $script = changeFieldLabelsScript($conn);
    ok('the script the pages print defines cmLabel and carries the names', str_contains($script, 'window.cmLabel') && str_contains($script, 'window.CM_FIELD_LABELS'));
} catch (Throwable $e) {
    ok('no unexpected error', false, $e->getMessage());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    $restore = $conn->prepare("UPDATE change_field_layout SET label = ? WHERE field_key = ?");
    foreach ($before as $k => $l) $restore->execute([$l, $k]);
}
ok('every name the database had is put back', $conn->query("SELECT field_key, label FROM change_field_layout")->fetchAll(PDO::FETCH_KEY_PAIR) == $before);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
