<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * LMS competency tests (3.0.0): includes/lms/competency_tests.php, the
 * candidate's endpoint (api/lms/test_public.php, over HTTP) and page
 * (lms/test.php), and the analyst API's gate.
 *
 * Everything it creates is its own - questions with a tagged skill, one test,
 * its sittings - and is deleted in a finally block. The one setting it touches
 * (the purge's last-run day) is put back.
 *
 * Run: php tests/lms-competency-tests.php
 *      FREEITSM_URL=https://freeitsm.internal/ php tests/lms-competency-tests.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/lms/competency_tests.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s %s\n", $label, $detail); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}
$base = rtrim(getenv('FREEITSM_URL') ?: 'http://localhost/' . basename($root) . '/', '/') . '/';
function http(string $url, ?array $json = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 20]);
    if ($json !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($json), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, $body, json_decode($body, true)];
}

$conn = connectToDatabase();
$one = function (string $sql, array $a = []) use ($conn) { $s = $conn->prepare($sql); $s->execute($a); return $s->fetchColumn(); };
$tag = 'ct-test-' . bin2hex(random_bytes(3));
$qIds = []; $testId = 0;
$savedPurge = $one("SELECT setting_value FROM system_settings WHERE setting_key = ?", [LMS_CT_LAST_PURGE]);

$choice = fn(string $text, array $answers) => ['skill' => $tag, 'difficulty' => 'intermediate', 'format' => 'choice',
    'question_text' => $text, 'answers' => $answers, 'status' => 'approved'];

try {
    echo "\n1. A question is checked before it is kept\n";
    [$q, $e] = lmsCtValidateQuestion($choice('Two right?', [['text' => 'a', 'marks' => 1], ['text' => 'b', 'marks' => 1], ['text' => 'c', 'marks' => 0]]));
    ok('multiple choice with two correct answers is refused', $q === null && stripos($e, 'exactly one') !== false);
    [$q] = lmsCtValidateQuestion($choice('One right', [['text' => 'a', 'marks' => 7], ['text' => 'b', 'marks' => 0]]));
    ok('...one correct answer is stored as worth 1, whatever was typed', $q && $q['answers'][0]['marks'] === 1);
    [$q, $e] = lmsCtValidateQuestion(['format' => 'graded'] + $choice('All zero', [['text' => 'a', 'marks' => 0], ['text' => 'b', 'marks' => 0]]));
    ok('a graded question with no answer worth anything is refused', $q === null);
    [$q] = lmsCtValidateQuestion(['format' => 'graded'] + $choice('Graded', [['text' => 'a', 'marks' => 5], ['text' => 'b', 'marks' => 3], ['text' => 'c', 'marks' => 1], ['text' => 'd', 'marks' => 0]]));
    ok('a graded question keeps its 5/3/1/0', $q && array_column($q['answers'], 'marks') === [5, 3, 1, 0]);
    [$q] = lmsCtValidateQuestion($choice('One answer', [['text' => 'a', 'marks' => 1]]));
    ok('fewer than two answers is refused', $q === null);
    [$q] = lmsCtValidateQuestion(['status' => 'published'] + $choice('Odd status', [['text' => 'a', 'marks' => 1], ['text' => 'b', 'marks' => 0]]));
    ok('an unknown status falls back to draft, never approved', $q && $q['status'] === 'draft');

    echo "\n1b. The AI's reply is checked the same way\n";
    $reply = "```json\n" . json_encode(['questions' => [
        ['question' => 'Fine', 'explanation' => 'e', 'answers' => [['text' => 'a', 'correct' => true], ['text' => 'b', 'correct' => false], ['text' => 'c', 'correct' => false], ['text' => 'd', 'correct' => false]]],
        ['question' => 'Two right', 'answers' => [['text' => 'a', 'correct' => true], ['text' => 'b', 'correct' => true], ['text' => 'c', 'correct' => false]]],
    ]]) . "\n```";
    $d = lmsCtParseDrafts($reply, $tag, 'beginner', 'choice', [5, 3, 1, 0]);
    ok('a fenced reply parses; the question with two right answers is dropped', count($d) === 1 && $d[0]['question_text'] === 'Fine' && $d[0]['status'] === 'draft');
    $d = lmsCtParseDrafts(json_encode(['questions' => [['question' => 'G', 'answers' => ['best', 'second', 'third', 'worst']], ['question' => 'Short', 'answers' => ['x', 'y', 'z']]]]), $tag, 'advanced', 'graded', [4, 2, 1, 0]);
    ok('graded answers get the install\'s marks best-first; three answers are dropped', count($d) === 1 && array_column($d[0]['answers'], 'marks') === [4, 2, 1, 0] && $d[0]['answers'][0]['text'] === 'best');
    try { lmsCtParseDrafts('Sorry, I cannot help with that.', $tag, 'beginner', 'choice', [5, 3, 1, 0]); ok('a reply that is not JSON is an error, not an empty success', false); }
    catch (RuntimeException $e) { ok('a reply that is not JSON is an error, not an empty success', true); }

    echo "\n2. Graded marks setting\n";
    ok('"5, 3, 1, 0" parses', lmsCtParseMarks('5, 3, 1, 0') === [5, 3, 1, 0]);
    ok('marks given worst-first are put best-first', lmsCtParseMarks('0,1,3,5') === [5, 3, 1, 0]);
    ok('three numbers are refused', lmsCtParseMarks('5,3,1') === null);
    ok('all zeros are refused', lmsCtParseMarks('0,0,0,0') === null);

    echo "\n3. Scoring: every question weighs the same\n";
    $snap = ['questions' => [
        ['skill' => 'A', 'difficulty' => 'beginner', 'max' => 1, 'answers' => [['marks' => 0], ['marks' => 1]]],
        ['skill' => 'B', 'difficulty' => 'advanced', 'max' => 5, 'answers' => [['marks' => 3], ['marks' => 5], ['marks' => 0], ['marks' => 1]]],
    ]];
    [$p, $sk] = lmsCtScore($snap, ['0' => 1, '1' => 0]);
    ok('right choice + second-best graded = (1 + 3/5) / 2 = 80%', $p === 80.0, "got $p");
    ok('per skill: A 100%, B 60%', $sk[0]['percent'] === 100.0 && $sk[1]['percent'] === 60.0);
    [$p, $sk] = lmsCtScore($snap, ['1' => 1]);
    ok('an unanswered question scores 0 and is counted as unanswered', $p === 50.0 && $sk[0]['answered'] === 0);
    [$p] = lmsCtScore($snap, ['0' => 99]);
    ok('an answer index that does not exist scores 0', $p === 0.0);

    echo "\n4. A test is frozen into a sitting\n";
    $conn->prepare("INSERT INTO lms_ct_tests (title, role_description, time_limit_minutes, created_datetime) VALUES (?, 'test role', 30, UTC_TIMESTAMP())")->execute([$tag]);
    $testId = (int)$conn->lastInsertId();
    try { lmsCtBuildSnapshot($conn, $testId); ok('an empty test cannot be sent', false); }
    catch (RuntimeException $e) { ok('an empty test cannot be sent', stripos($e->getMessage(), 'no questions') !== false); }

    foreach ([
        $choice("$tag Q1", [['text' => 'right', 'marks' => 1], ['text' => 'w1', 'marks' => 0], ['text' => 'w2', 'marks' => 0], ['text' => 'w3', 'marks' => 0]]),
        ['format' => 'graded'] + $choice("$tag Q2", [['text' => 'best', 'marks' => 5], ['text' => 'ok', 'marks' => 3], ['text' => 'poor', 'marks' => 1], ['text' => 'bad', 'marks' => 0]]),
        ['status' => 'draft'] + $choice("$tag Q3", [['text' => 'x', 'marks' => 1], ['text' => 'y', 'marks' => 0]]),
    ] as $raw) {
        [$q] = lmsCtValidateQuestion($raw);
        $qIds[] = lmsCtInsertQuestion($conn, $q, 'manual', null, null);
    }
    lmsCtAttach($conn, $testId, $qIds);
    ok('attaching the same question twice adds nothing', lmsCtAttach($conn, $testId, [$qIds[0]]) === 0);
    try { lmsCtBuildSnapshot($conn, $testId); ok('a test holding a draft cannot be sent', false); }
    catch (RuntimeException $e) { ok('a test holding a draft cannot be sent', stripos($e->getMessage(), 'draft') !== false); }
    $conn->prepare("UPDATE lms_ct_questions SET status = 'hidden' WHERE id = ?")->execute([$qIds[2]]);
    try { lmsCtBuildSnapshot($conn, $testId); ok('...nor one holding a hidden question', false); }
    catch (RuntimeException $e) { ok('...nor one holding a hidden question', stripos($e->getMessage(), 'hidden') !== false); }
    $conn->prepare("DELETE FROM lms_ct_test_questions WHERE test_id = ? AND question_id = ?")->execute([$testId, $qIds[2]]);

    $snap = lmsCtBuildSnapshot($conn, $testId);
    ok('the snapshot holds the two approved questions', count($snap['questions']) === 2);
    $texts = array_column($snap['questions'][0]['answers'], 'text'); sort($texts);
    ok('answers are shuffled, never lost', $texts === ['right', 'w1', 'w2', 'w3']);
    ok('the graded question keeps its best mark as max', $snap['questions'][1]['max'] === 5);
    // Shuffle really happens: over 40 snapshots the right answer is not always first.
    $positions = [];
    for ($i = 0; $i < 40; $i++) { $s2 = lmsCtBuildSnapshot($conn, $testId); $positions[array_search('right', array_column($s2['questions'][0]['answers'], 'text'))] = 1; }
    ok('the right answer moves around between candidates', count($positions) > 1, count($positions) . ' positions seen');

    echo "\n5. The link: only its hash is kept\n";
    [$raw, $hash] = lmsCtNewToken();
    $conn->prepare("INSERT INTO lms_ct_sittings (test_id, candidate_name, token_hash, snapshot_json, time_limit_minutes, expires_datetime, created_datetime)
                    VALUES (?, ?, ?, ?, 30, UTC_TIMESTAMP() + INTERVAL 7 DAY, UTC_TIMESTAMP())")
         ->execute([$testId, "$tag candidate", $hash, json_encode($snap)]);
    $sid = (int)$conn->lastInsertId();
    ok('the raw token is nowhere in the row', (int)$one("SELECT COUNT(*) FROM lms_ct_sittings WHERE id = ? AND (token_hash = ? OR snapshot_json LIKE ?)", [$sid, $raw, "%$raw%"]) === 0);
    ok('the raw token finds the sitting', (lmsCtFindSitting($conn, $raw)['id'] ?? 0) == $sid);
    ok('the hash itself does not', lmsCtFindSitting($conn, $hash) === null || lmsCtFindSitting($conn, $hash)['id'] != $sid);
    ok('a malformed token is refused before the database', lmsCtFindSitting($conn, "' OR 1=1 -- ") === null);

    echo "\n6. Editing the bank later does not change a frozen paper\n";
    $conn->prepare("UPDATE lms_ct_questions SET question_text = 'CHANGED', answers_json = ? WHERE id = ?")->execute([json_encode([['text' => 'new', 'marks' => 1], ['text' => 'n2', 'marks' => 0]]), $qIds[0]]);
    $frozen = json_decode($one("SELECT snapshot_json FROM lms_ct_sittings WHERE id = ?", [$sid]), true);
    ok('the sitting still has the original wording', $frozen['questions'][0]['text'] === "$tag Q1");

    echo "\n7. The candidate's endpoint, over HTTP ($base)\n";
    $api = $base . 'api/lms/test_public.php';
    [$code, , $d] = http($api, ['t' => str_repeat('a', 64), 'action' => 'start']);
    ok('an unknown link is refused', $code === 404 && ($d['error'] ?? '') === 'invalid', "HTTP $code");
    [$code, , $d] = http($api, ['t' => $raw, 'action' => 'save', 'q' => 0, 'a' => 0]);
    ok('answers before Start are refused', ($d['error'] ?? '') === 'not_started');
    [$code, , $d] = http($api, ['t' => $raw, 'action' => 'start']);
    ok('Start starts the clock', !empty($d['success']) && $one("SELECT started_datetime IS NOT NULL FROM lms_ct_sittings WHERE id = ?", [$sid]) == 1);
    $started = $one("SELECT started_datetime FROM lms_ct_sittings WHERE id = ?", [$sid]);
    http($api, ['t' => $raw, 'action' => 'start']);
    ok('pressing Start again does not restart the clock', $one("SELECT started_datetime FROM lms_ct_sittings WHERE id = ?", [$sid]) === $started);
    $rightIdx = array_search('right', array_column($frozen['questions'][0]['answers'], 'text'));
    $okIdx    = array_search('ok', array_column($frozen['questions'][1]['answers'], 'text'));
    [, , $d] = http($api, ['t' => $raw, 'action' => 'save', 'q' => 0, 'a' => $rightIdx]);
    ok('an answer saves', !empty($d['success']) && $d['remaining'] > 1700, 'remaining ' . ($d['remaining'] ?? '?') . 's');
    http($api, ['t' => $raw, 'action' => 'save', 'q' => 1, 'a' => $okIdx]);
    [, , $d] = http($api, ['t' => $raw, 'action' => 'save', 'q' => 5, 'a' => 0]);
    ok('a question that does not exist is refused', ($d['error'] ?? '') === 'bad_answer');
    $resp = json_decode($one("SELECT responses_json FROM lms_ct_sittings WHERE id = ?", [$sid]), true);
    ok('both answers are stored, neither overwrote the other', isset($resp['0'], $resp['1']) && (int)$resp['0'] === $rightIdx);

    [$code, $html] = http($base . 'lms/test.php?t=' . $raw);
    ok('the page shows the questions', $code === 200 && str_contains($html, "$tag Q1"));
    ok('the page never carries the marks or the explanation', !str_contains($html, '"marks"') && !str_contains($html, 'class="ct-mark') && stripos($html, 'explanation') === false);

    [, , $d] = http($api, ['t' => $raw, 'action' => 'submit']);
    $row = $conn->query("SELECT * FROM lms_ct_sittings WHERE id = $sid")->fetch(PDO::FETCH_ASSOC);
    ok('Submit scores it: (1 + 3/5) / 2 = 80%', !empty($d['success']) && (float)$row['score_percent'] === 80.0, 'score ' . $row['score_percent']);
    ok('...and records why it finished', $row['finish_reason'] === 'submitted');
    [, , $d] = http($api, ['t' => $raw, 'action' => 'save', 'q' => 0, 'a' => 0]);
    ok('nothing can be changed after Submit', ($d['error'] ?? '') === 'finished');

    echo "\n8. The clock belongs to the server\n";
    [$raw2, $hash2] = lmsCtNewToken();
    $conn->prepare("INSERT INTO lms_ct_sittings (test_id, candidate_name, token_hash, snapshot_json, time_limit_minutes, expires_datetime, started_datetime, responses_json, created_datetime)
                    VALUES (?, ?, ?, ?, 30, UTC_TIMESTAMP() + INTERVAL 7 DAY, UTC_TIMESTAMP() - INTERVAL 40 MINUTE, ?, UTC_TIMESTAMP())")
         ->execute([$testId, "$tag late", $hash2, json_encode($snap), json_encode(['0' => array_search('right', array_column($snap['questions'][0]['answers'], 'text'))])]);
    $sid2 = (int)$conn->lastInsertId();
    [, , $d] = http($api, ['t' => $raw2, 'action' => 'save', 'q' => 1, 'a' => 0]);
    ok('a save after the time limit is refused', ($d['error'] ?? '') === 'finished');
    $row = $conn->query("SELECT * FROM lms_ct_sittings WHERE id = $sid2")->fetch(PDO::FETCH_ASSOC);
    ok('...and the sitting is closed as time up, with what was saved scored', $row['finish_reason'] === 'time_up' && (float)$row['score_percent'] === 50.0);
    ok('...its finish time is the deadline, not the moment it was noticed',
        strtotime($row['submitted_datetime'] . ' UTC') === strtotime($row['started_datetime'] . ' UTC') + 1800);

    [$raw3, $hash3] = lmsCtNewToken();
    $conn->prepare("INSERT INTO lms_ct_sittings (test_id, candidate_name, token_hash, snapshot_json, time_limit_minutes, expires_datetime, created_datetime)
                    VALUES (?, ?, ?, ?, 30, UTC_TIMESTAMP() - INTERVAL 1 DAY, UTC_TIMESTAMP() - INTERVAL 9 DAY)")
         ->execute([$testId, "$tag expired", $hash3, json_encode($snap)]);
    $sid3 = (int)$conn->lastInsertId();
    [, , $d] = http($api, ['t' => $raw3, 'action' => 'start']);
    ok('a link not started in time cannot be started', ($d['error'] ?? '') === 'expired');
    $conn->prepare("UPDATE lms_ct_sittings SET is_cancelled = 1, expires_datetime = UTC_TIMESTAMP() + INTERVAL 1 DAY WHERE id = ?")->execute([$sid3]);
    [$code, , $d] = http($api, ['t' => $raw3, 'action' => 'start']);
    ok('a cancelled link answers exactly like an unknown one', $code === 404 && ($d['error'] ?? '') === 'invalid');

    echo "\n9. Retention\n";
    $conn->prepare("UPDATE lms_ct_sittings SET submitted_datetime = UTC_TIMESTAMP() - INTERVAL 200 DAY WHERE id = ?")->execute([$sid2]);
    [$raw4, $hash4] = lmsCtNewToken();
    $conn->prepare("INSERT INTO lms_ct_sittings (test_id, candidate_name, token_hash, snapshot_json, expires_datetime, started_datetime, created_datetime)
                    VALUES (?, ?, ?, ?, UTC_TIMESTAMP() - INTERVAL 300 DAY, UTC_TIMESTAMP() - INTERVAL 301 DAY, UTC_TIMESTAMP() - INTERVAL 302 DAY)")
         ->execute([$testId, "$tag open", $hash4, json_encode($snap)]);
    $sid4 = (int)$conn->lastInsertId();
    $before = (int)$one("SELECT COUNT(*) FROM lms_ct_sittings WHERE id NOT IN ($sid, $sid2, $sid3, $sid4)");
    $settings = [LMS_CT_RETENTION_DAYS => '180', LMS_CT_LAST_PURGE => ''] + lmsCtSettings($conn);
    // Purge for real, but only what this test made is old enough: count the rest first.
    $oldOthers = (int)$one("SELECT COUNT(*) FROM lms_ct_sittings WHERE id NOT IN ($sid, $sid2, $sid3, $sid4)
                            AND COALESCE(submitted_datetime, expires_datetime) < UTC_TIMESTAMP() - INTERVAL 180 DAY");
    if ($oldOthers > 0) {
        ok('retention (skipped: this install has its own old candidates, which a test must not delete)', true);
    } else {
        lmsCtPurge($conn, $settings, true);
        ok('a result finished 200 days ago is deleted at 180', (int)$one("SELECT COUNT(*) FROM lms_ct_sittings WHERE id = ?", [$sid2]) === 0);
        ok('a fresh result is kept', (int)$one("SELECT COUNT(*) FROM lms_ct_sittings WHERE id = ?", [$sid]) === 1);
        ok('a sitting started and never finished is not deleted (it is closed first)', (int)$one("SELECT COUNT(*) FROM lms_ct_sittings WHERE id = ?", [$sid4]) === 1);
        ok('nobody else\'s candidates were touched', (int)$one("SELECT COUNT(*) FROM lms_ct_sittings WHERE id NOT IN ($sid, $sid2, $sid3, $sid4)") === $before);
    }

    echo "\n10. The analyst API is shut without a session\n";
    [$code, , $d] = http($base . 'api/lms/tests.php?action=tests');
    ok('no session, no data', empty($d['success']), "HTTP $code");
    [$code, , $d] = http($base . 'api/lms/tests.php?action=sitting&id=' . $sid);
    ok('...including a candidate\'s result', empty($d['success']) && !str_contains(json_encode($d), "$tag candidate"));
} finally {
    $conn->exec("DELETE FROM lms_ct_sittings WHERE candidate_name LIKE " . $conn->quote("$tag%"));
    if ($testId) {
        $conn->prepare("DELETE FROM lms_ct_test_questions WHERE test_id = ?")->execute([$testId]);
        $conn->prepare("DELETE FROM lms_ct_test_skills WHERE test_id = ?")->execute([$testId]);
        $conn->prepare("DELETE FROM lms_ct_tests WHERE id = ?")->execute([$testId]);
    }
    $conn->prepare("DELETE FROM lms_ct_questions WHERE skill = ?")->execute([$tag]);
    if ($savedPurge === false) $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([LMS_CT_LAST_PURGE]);
    else lmsCtSaveSetting($conn, LMS_CT_LAST_PURGE, (string)$savedPurge);
    $left = (int)$one("SELECT COUNT(*) FROM lms_ct_questions WHERE skill = ?", [$tag]) + (int)$one("SELECT COUNT(*) FROM lms_ct_sittings WHERE candidate_name LIKE ?", ["$tag%"]);
    echo "\n  cleanup: " . ($left === 0 ? 'nothing left behind' : "$left rows LEFT BEHIND") . "\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
