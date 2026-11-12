<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The smaller fixes in 5.1.0, each pinned so it cannot quietly come back.
 *
 *   php tests/bug-fixes-5-1.php
 *
 * No database and no web server - every check calls the code directly or reads a file.
 * Each section names the old behaviour it would have caught; run against 5.0.0 they fail.
 *
 *   1. SLA: a ticket closed with no closed_datetime stopped its resolution clock (it never did),
 *      and a ticket closed then reopened keeps counting.
 *   2. Email templates: in an HTML template every merge value is escaped in the body; the
 *      subject keeps the raw text; a plain-text template is left for the later whole-body escape.
 *   3. Calendar feeds: folding never splits a multi-byte character.
 *   4. War Room: renaming a channel to nothing is refused, not silently ignored.
 *   5. SCORM: read-only is 403 to a SCORM 1.2 course and 404 to a SCORM 2004 one.
 *   6. Tasks board: one endDrag, not two.
 */

chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $what\n"; }
    else { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n        $detail" : '') . "\n"; }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n-- 1. SLA resolution clock --\n";
require_once 'includes/sla.php';
$utc = new DateTimeZone('UTC');
$at  = fn(string $s) => new DateTimeImmutable($s, $utc);
// 24/7 calendar, so business minutes are plain minutes.
$cal = ['timezone' => 'UTC', 'holidays' => [],
        'hours' => array_map(fn($d) => ['weekday' => $d, 'start_time' => '00:00:00', 'end_time' => '23:59:59'], range(1, 7))];
$prio = ['sla_resolution_minutes' => 600];
$seg  = fn(string $when, string $status, bool $closed) => ['start' => $at($when), 'status' => $status, 'pauses' => false, '_is_closed' => $closed];

$closed = [$seg('2026-01-05 09:00', 'Open', false), $seg('2026-01-05 10:00', 'In progress', false), $seg('2026-01-05 11:00', 'Closed', true)];
$r = sla_compute_resolution(['closed_datetime' => null], $prio, $cal, $closed);
check('closed with no closed_datetime: the clock stops at the close', $r['achieved_at'] === '2026-01-05 11:00:00', 'achieved_at = ' . var_export($r['achieved_at'], true));

$reopened = [$seg('2026-01-05 09:00', 'Open', false), $seg('2026-01-05 10:00', 'Closed', true), $seg('2026-01-05 11:00', 'Open', false)];
$r = sla_compute_resolution(['closed_datetime' => null], $prio, $cal, $reopened);
check('closed then reopened: the clock keeps running', $r['achieved_at'] === null, 'achieved_at = ' . var_export($r['achieved_at'], true));

$twice = [$seg('2026-01-05 09:00', 'Open', false), $seg('2026-01-05 10:00', 'Closed', true), $seg('2026-01-05 11:00', 'Open', false),
          $seg('2026-01-05 12:00', 'Resolved', true), $seg('2026-01-05 12:30', 'Closed', true)];
$r = sla_compute_resolution(['closed_datetime' => null], $prio, $cal, $twice);
check('closed, reopened, closed again: the clock stops at the LAST close', $r['achieved_at'] === '2026-01-05 12:00:00', 'achieved_at = ' . var_export($r['achieved_at'], true));

$r = sla_compute_resolution(['closed_datetime' => '2026-01-05 10:30:00'], $prio, $cal, $closed);
check('closed_datetime still wins when it is set', $r['achieved_at'] === '2026-01-05 10:30:00');

$src = file_get_contents('includes/sla.php');
check("the timeline records '_is_closed' on each status change", substr_count($src, "'_is_closed' =>") >= 2);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Email template merge values --\n";
require_once 'includes/template_email.php';
$merge = ['ticket_subject' => '<a href="https://evil.example">Verify your account</a>', 'requester_name' => "O'Brien & Sons", 'csat_link' => 'https://x.example/?a=1&b=2'];
$html  = templateBodyMergeData($merge, true);
$body  = resolveMergeCodes('<p>Your ticket [ticket_subject] from [requester_name]</p>', $html);
check('HTML body: a requester\'s tag in the subject arrives as text, not a link', !str_contains($body, '<a href') && str_contains($body, '&lt;a href='), $body);
check('HTML body: the ticket\'s own values are escaped too (they used to go in raw)', str_contains($body, 'O&#039;Brien &amp; Sons'), $body);
check('HTML body: a link value is still a valid href', $html['csat_link'] === 'https://x.example/?a=1&amp;b=2');
check('plain-text body: values untouched (the whole body is escaped later, once)', templateBodyMergeData($merge, false) === $merge);
$subject = resolveMergeCodes('Re: [ticket_subject]', $merge);
check('the subject keeps the raw text (it is never HTML)', $subject === 'Re: ' . $merge['ticket_subject']);
$tsrc = file_get_contents('includes/template_email.php');
check('sendTemplateEmail() builds the body from templateBodyMergeData()', str_contains($tsrc, "resolveMergeCodes(\$template['body_template'], templateBodyMergeData(\$mergeData, \$bodyIsHtml))"));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Calendar feed folding --\n";
require_once 'includes/ics.php';
foreach ([0, 1, 2, 3] as $pad) {
    $line = 'SUMMARY:' . str_repeat('x', $pad) . str_repeat('é', 40) . str_repeat('😀', 20) . 'end';
    $parts = explode("\r\n", icsFold($line));
    $ok = true; $joined = '';
    foreach ($parts as $i => $p) {
        $piece = $i ? substr($p, 1) : $p;
        if (strlen($p) > 75 || !mb_check_encoding($piece, 'UTF-8')) $ok = false;
        $joined .= $piece;
    }
    check("accented and emoji text (offset $pad): every folded line is whole UTF-8, at most 75 bytes, and unfolds to the original", $ok && $joined === $line);
}
check('a short line is left alone', icsFold('SUMMARY:Hi') === 'SUMMARY:Hi');

// ─────────────────────────────────────────────────────────────────────────────
echo "\n-- 4. War Room rename --\n";
require_once 'includes/warroom.php';
$threw = null;
try {
    // The name is checked before the connection is used, so any PDO will do; none is touched.
    $pdo = in_array('sqlite', PDO::getAvailableDrivers(), true) ? new PDO('sqlite::memory:') : null;
    if ($pdo === null) throw new RuntimeException('no sqlite');
    warRoomUpdateChannel($pdo, 1, "   \t ", 'topic');
    $threw = 'nothing';
} catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
catch (RuntimeException $e) { $threw = 'SKIP'; }
if ($threw === 'SKIP') {
    $wsrc = file_get_contents('includes/warroom.php');
    check('a blank name throws name_required (read from the source: no sqlite driver here)', str_contains($wsrc, "if (\$name === '') throw new InvalidArgumentException('name_required');"));
} else {
    check('a blank name is refused with name_required (the page shows "a name is required")', $threw === 'name_required', 'got ' . var_export($threw, true));
}
check('the always-true rowCount() >= 0 is gone', !str_contains(file_get_contents('includes/warroom.php'), 'rowCount() >= 0'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n-- 5. SCORM read-only error code --\n";
$scorm = file_get_contents('assets/js/scorm-api.js');
check("doSetValue answers 403 to SCORM 1.2 and 404 to SCORM 2004", str_contains($scorm, "lastError = version === '2004' ? '404' : '403';"));
check("window.API (1.2) passes '1.2'", str_contains($scorm, "LMSSetValue:      function(el, val) { return doSetValue(el, val, '1.2'); }"));
check("window.API_1484_11 (2004) passes '2004'", str_contains($scorm, "SetValue:         function(el, val) { return doSetValue(el, val, '2004'); }"));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n-- 6. Tasks board --\n";
check('tasks.js declares endDrag once', substr_count(file_get_contents('assets/js/tasks.js'), 'async function endDrag(') === 1);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
