<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables — it creates forms,
   assets, documents and even working analyst accounts, and only tidies them up
   if it runs to the end. Served by a web server it is an unauthenticated write
   endpoint, and the request can be cut off half way. FreeITSM is normally
   deployed by putting the repository in the document root, so this file is
   reachable unless it refuses. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The approvals inbox must survive a request containing a TABLE question (#1850).
 *
 * 🔴 WHAT THIS EXISTS TO CATCH. `catalogueAnswerText()` flattened any JSON array
 * answer with `implode(', ', $decoded)`. A table's answer is a list of OBJECTS,
 * so that produced "Array, Array" **and emitted a PHP warning per row** — and on
 * an install with display_errors on, the warning is printed into the response
 * body before the JSON. The body is then not JSON, the page cannot parse it, and
 * the approvals inbox showed a bare "Error".
 *
 * ⚠️ THE SYMPTOM POINTED AWAY FROM THE CAUSE. The tab counts kept working,
 * because they are returned by whichever call succeeds — and "Decided by me" had
 * no rows to flatten, so it succeeded. A screen showing correct counts beside an
 * error reads like a broken list endpoint, not like one bad answer in one row.
 *
 * 🔑 IT IS NOT ONLY THE INBOX. The same function renders the approval
 * NOTIFICATION EMAIL and the `{{submission.fields.*}}` merge codes, so a table
 * reached an approver's inbox as "Array, Array" even where display_errors was
 * off and nothing appeared broken at all.
 *
 * ⚠️ Touches the database. Everything is prefixed ZZAPPG and removed in the
 * finally block, and the cleanup is asserted.
 *
 * Run:  php tests/catalogue-approvals-grid.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/forms.php';
require_once __DIR__ . '/../includes/catalogue_approvals.php';

define('BASE_TEST_URL', rtrim(getenv('FREEITSM_TEST_URL') ?: 'http://localhost/freeitsm-app', '/') . '/');
define('SESS_DIR', rtrim(getenv('FREEITSM_SESS_DIR') ?: (ini_get('session.save_path') ?: sys_get_temp_dir()), '/\\'));

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  — $detail" : '') . "\n"; }
}

echo "The approvals inbox with a table question in the queue\n" . str_repeat('=', 64) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$formId = null; $sid = null;

try {
    $actor = $conn->query("SELECT id, full_name FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC);
    if (!$actor) { echo "  SKIPPED — no active analyst to act as.\n"; exit(0); }
    $aid = (int)$actor['id'];

    $res = FormsService::saveForm($conn, new ActorContext(actorId: $aid, source: 'ui'), [
        'title'  => 'ZZAPPG approval with a table',
        'fields' => [
            ['field_type' => 'grid', 'label' => 'ZZAPPG items', 'config' => ['columns' => [
                ['id' => 1, 'label' => 'Item', 'type' => 'text'],
                ['id' => 2, 'label' => 'Qty',  'type' => 'number'],
            ]]],
        ],
    ]);
    $formId = (int)$res['id'];
    $gid = (int)$conn->query("SELECT id FROM form_fields WHERE form_id = $formId AND field_type = 'grid'")->fetchColumn();

    // A request sitting in the queue, assigned to our analyst.
    $conn->prepare("INSERT INTO form_submissions (form_id, submitted_by, submitted_date, approval_status, approver_id)
                    VALUES (?, ?, UTC_TIMESTAMP(), 'pending', ?)")->execute([$formId, $aid, $aid]);
    $subId = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO form_submission_data (submission_id, field_id, field_value) VALUES (?,?,?)")
         ->execute([$subId, $gid, json_encode([['1' => 'Pencil', '2' => '1'], ['1' => 'Pen', '2' => '5']])]);

    /* ---- 1. The flattener itself ---------------------------------------- */
    $field = $conn->query("SELECT label, field_type, config FROM form_fields WHERE id = $gid")->fetch(PDO::FETCH_ASSOC);
    $raw   = (string)$conn->query("SELECT field_value FROM form_submission_data WHERE submission_id = $subId")->fetchColumn();

    $before = error_get_last();
    $text = catalogueAnswerText($raw, 'grid', $field);
    check('a table answer does not render as "Array"', !str_contains($text, 'Array'), $text);
    check('…it names the columns', str_contains($text, 'Item') && str_contains($text, 'Qty'), $text);
    check('…and carries the values', str_contains($text, 'Pencil') && str_contains($text, 'Pen'), $text);

    /* ---- 2. Through the REAL endpoint, which is where it broke ----------- */
    $sid = 'zzappg' . random_int(1000, 9999);
    file_put_contents(SESS_DIR . "/sess_$sid",
        'analyst_id|i:' . $aid . ';analyst_name|s:' . strlen((string)$actor['full_name'])
      . ':"' . $actor['full_name'] . '";');

    foreach (['mine', 'all'] as $filter) {
        $ch = curl_init(BASE_TEST_URL . 'api/forms/catalogue_approvals.php?filter=' . $filter);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                                CURLOPT_COOKIE => session_name() . '=' . $sid]);
        $body = (string)curl_exec($ch);

        /* 🔑 THE ASSERTION THE BUG FAILED. Not "did it return items" but "is the
           body JSON AT ALL" — a warning printed before it is what broke this,
           and json_decode is exactly what the browser does. */
        $json = json_decode($body, true);
        check("filter=$filter: the response is valid JSON", is_array($json),
            'first 160 bytes: ' . substr($body, 0, 160));
        check("filter=$filter: nothing precedes the JSON", $body === '' || $body[0] === '{',
            'body starts: ' . substr($body, 0, 60));
        if (is_array($json)) {
            check("filter=$filter: success is true", !empty($json['success']),
                (string)($json['error'] ?? ''));
            $found = false;
            foreach (($json['items'] ?? []) as $it) {
                if ((int)($it['id'] ?? 0) !== $subId) continue;
                foreach (($it['answers'] ?? []) as $a) {
                    if (str_contains((string)$a['value'], 'Pencil')) $found = true;
                }
            }
            check("filter=$filter: the table's answers reach the card", $found);
        }
    }

    /* ---- 3. CONTROL — the checker must be able to fail ------------------- */
    $withWarning = "\nWarning: Array to string conversion in x.php on line 1\n" . json_encode(['success' => true]);
    check('CONTROL — a body with a PHP warning in front is NOT valid JSON',
        json_decode($withWarning, true) === null);
    check('CONTROL — the same body without it IS',
        is_array(json_decode(json_encode(['success' => true]), true)));

} finally {
    if ($sid) @unlink(SESS_DIR . "/sess_$sid");
    if ($formId) {
        $conn->prepare("DELETE d FROM form_submission_data d JOIN form_submissions s ON s.id = d.submission_id WHERE s.form_id = ?")->execute([$formId]);
        $conn->prepare("DELETE FROM form_submissions WHERE form_id = ?")->execute([$formId]);
        $conn->prepare("DELETE FROM form_fields WHERE form_id = ?")->execute([$formId]);
        $conn->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        $left = (int)$conn->query("SELECT COUNT(*) FROM forms WHERE title LIKE 'ZZAPPG%'")->fetchColumn();
        check('cleanup left nothing behind', $left === 0, "$left ZZAPPG form(s) remain");
    }
}

echo "\n" . str_repeat('=', 64) . "\n";
echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
