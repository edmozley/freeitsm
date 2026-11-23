<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables — it creates forms,
   assets, documents and even working analyst accounts, and only tidies them up
   if it runs to the end. Served by a web server it is an unauthenticated write
   endpoint, and the request can be cut off half way. FreeITSM is normally
   deployed by putting the repository in the document root, so this file is
   reachable unless it refuses. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A table question's answers must survive the trip back out (#1849).
 *
 * 🔴 WHAT THIS EXISTS TO CATCH. A table question keeps its COLUMNS in
 * `form_fields.config` and its ANSWERS in `form_submission_data.field_value`.
 * api/forms/get_submissions.php selected `id, field_type, label, is_deleted` —
 * no `config` — so the answers came back and the columns did not.
 *
 * Every reader of that endpoint then laid the answers out against an empty
 * column list, and all three did it SILENTLY:
 *
 *   on screen  a <table> with an empty <thead> and empty <tr>s
 *   the PDF    autoTable called with head: [[]] — the label printed, nothing under it
 *   the CSV    no cells for that field
 *
 * ⚠️ Nothing errored, and the JSON genuinely contained the answers, so the bug
 * reads as "the table was never filled in". It was reported as a PDF fault
 * because that is where somebody happened to look.
 *
 * 🔑 THE SHAPE OF THE FIX IS THE POINT. The sibling endpoint —
 * FormsService::collectionSubmissions() — already selected `options` and
 * `config`, so the collection view rendered the same table correctly. Two
 * endpoints feeding the same JavaScript different field shapes is why this
 * lasted. The test therefore asserts the SHAPES MATCH, not just that one works.
 *
 * ⚠️ Touches the database. Everything it makes is prefixed ZZGRID and removed in
 * the finally block, and the cleanup is asserted.
 *
 * Run:  php tests/form-submission-grid-export.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/forms.php';

define('BASE_TEST_URL', rtrim(getenv('FREEITSM_TEST_URL') ?: 'http://localhost/freeitsm-app', '/') . '/');
define('SESS_DIR', rtrim(getenv('FREEITSM_SESS_DIR') ?: (ini_get('session.save_path') ?: sys_get_temp_dir()), '/\\'));

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  — $detail" : '') . "\n"; }
}

echo "A table question's answers must survive the trip back out\n" . str_repeat('=', 64) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$formId = null; $sid = null;

try {
    // An analyst to act as. Any active one will do; the endpoint only needs a session.
    $actor = $conn->query("SELECT id, full_name FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC);
    if (!$actor) { echo "  SKIPPED — no active analyst to act as.\n"; exit(0); }

    /* ---- A form with a table question, and one submission against it ------ */
    $cols = [
        ['id' => 1, 'label' => 'Item',   'type' => 'text'],
        ['id' => 2, 'label' => 'Qty',    'type' => 'number'],
        ['id' => 3, 'label' => 'Choice', 'type' => 'radio', 'options' => ['1', '3', '5']],
    ];
    $res = FormsService::saveForm($conn, new ActorContext(actorId: (int)$actor['id'], source: 'ui'), [
        'title'  => 'ZZGRID export test',
        'fields' => [
            ['field_type' => 'grid', 'label' => 'ZZGRID items', 'config' => ['columns' => $cols]],
            ['field_type' => 'text', 'label' => 'ZZGRID note'],
        ],
    ]);
    $formId = (int)$res['id'];

    $fields = $conn->prepare("SELECT id, field_type FROM form_fields WHERE form_id = ? ORDER BY sort_order");
    $fields->execute([$formId]);
    $ids = [];
    foreach ($fields as $f) $ids[$f['field_type']] = (int)$f['id'];

    $answer = json_encode([
        ['1' => 'Pencil', '2' => '1', '3' => '3'],
        ['1' => 'Pen',    '2' => '5', '3' => '5'],
        ['1' => 'Biro',   '2' => '4', '3' => '1'],
    ]);
    $conn->prepare("INSERT INTO form_submissions (form_id, submitted_by, submitted_date)
                    VALUES (?, ?, UTC_TIMESTAMP())")->execute([$formId, (int)$actor['id']]);
    $subId = (int)$conn->lastInsertId();
    $ins = $conn->prepare("INSERT INTO form_submission_data (submission_id, field_id, field_value) VALUES (?, ?, ?)");
    $ins->execute([$subId, $ids['grid'], $answer]);
    $ins->execute([$subId, $ids['text'], 'a note']);

    /* ---- Through the REAL endpoint, as a signed-in analyst ---------------- */
    $sid = 'zzgrid' . random_int(1000, 9999);
    file_put_contents(SESS_DIR . "/sess_$sid",
        'analyst_id|i:' . (int)$actor['id'] . ';analyst_name|s:' . strlen((string)$actor['full_name'])
      . ':"' . $actor['full_name'] . '";');

    $ch = curl_init(BASE_TEST_URL . 'api/forms/get_submissions.php?form_id=' . $formId);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                            CURLOPT_COOKIE => session_name() . '=' . $sid]);
    $raw  = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode($raw, true);

    check('the endpoint answered 200', $code === 200, 'got ' . $code);
    check('…with JSON we can read', is_array($data), substr($raw, 0, 160));
    if (!is_array($data)) { throw new RuntimeException('no usable response'); }

    /* 🔑 THE ASSERTION THE BUG WOULD HAVE FAILED. Not "is there a grid field"
       — that was always true — but "does it carry the columns its answers are
       meant to be laid out against". */
    $gridField = null;
    foreach (($data['fields'] ?? []) as $f) {
        if (($f['field_type'] ?? '') === 'grid') { $gridField = $f; break; }
    }
    check('the response includes the table question', $gridField !== null);

    if ($gridField) {
        check('…and it carries its `config`', !empty($gridField['config']),
            'without config a table has no columns and renders empty everywhere');

        $parsed = FormsService::gridColumns($gridField);
        check('…which parses to the 3 columns the form defines (got ' . count($parsed) . ')',
            count($parsed) === 3);

        $labels = array_values(array_map(fn($c) => $c['label'], $parsed));
        check('…with their labels intact', $labels === ['Item', 'Qty', 'Choice'],
            implode(',', $labels));

        /* A radio column's options travel in config too — gridCellText needs
           them, and a cell whose column has none renders blank. */
        $choice = null;
        foreach ($parsed as $c) if ($c['label'] === 'Choice') $choice = $c;
        check('…and a choice column keeps its options',
            $choice && count($choice['options'] ?? []) === 3);
    }

    // The answers themselves, which were never the broken half.
    $rows = null;
    foreach (($data['submissions'] ?? []) as $s) {
        if ((int)($s['id'] ?? 0) === $subId) {
            $rows = FormsService::gridRows($s['data'][$ids['grid']] ?? ($s['data'][(string)$ids['grid']] ?? null));
        }
    }
    check('the three answer rows come back', is_array($rows) && count($rows) === 3,
        'got ' . (is_array($rows) ? count($rows) : 'nothing'));

    /* ---- The two endpoints must return the SAME field shape -------------- */
    $mine = array_keys($gridField ?? []);
    sort($mine);
    $collection = ['config', 'field_type', 'id', 'is_deleted', 'label', 'options'];
    check('this endpoint returns every field key the collection view gets',
        count(array_diff($collection, $mine)) === 0,
        'missing: ' . implode(',', array_diff($collection, $mine)));

    /* ---- CONTROL — the check must be able to fail ------------------------ */
    $stripped = $gridField ?? [];
    unset($stripped['config']);
    check('CONTROL — with `config` removed, no columns are found',
        count(FormsService::gridColumns($stripped)) === 0,
        'the column parser finds columns even without config, so the test above proves nothing');
    check('CONTROL — and the real field DOES find some',
        count(FormsService::gridColumns($gridField ?? [])) > 0);

} finally {
    if ($sid) @unlink(SESS_DIR . "/sess_$sid");
    if ($formId) {
        $conn->prepare("DELETE d FROM form_submission_data d JOIN form_submissions s ON s.id = d.submission_id WHERE s.form_id = ?")->execute([$formId]);
        $conn->prepare("DELETE FROM form_submissions WHERE form_id = ?")->execute([$formId]);
        $conn->prepare("DELETE FROM form_fields WHERE form_id = ?")->execute([$formId]);
        $conn->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        $left = (int)$conn->query("SELECT COUNT(*) FROM forms WHERE title LIKE 'ZZGRID%'")->fetchColumn();
        check('cleanup left nothing behind', $left === 0, "$left ZZGRID form(s) remain");
    }
}

echo "\n" . str_repeat('=', 64) . "\n";
echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
