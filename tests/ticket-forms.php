<?php
/* 🔴 NEVER OVER THE WEB - it writes to the database. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Forms on a ticket (GitHub #272): the switch, adding (newest version, once each),
 * the closure gate (block refuses, warn lets go), the shared fill page's endpoint
 * (forged session), submitting and the retry key, the answers rule, the evidence
 * guard in Forms, the case report, the REST API (real keys) and merge.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/ticket-forms.php
 *
 * Runs Database Verify first. Makes a ZZ form, a ZZ analyst and two keys, adds the
 * form to the first ticket; everything it made is removed in `finally`, the ticket's
 * history rows included, and the "Forms on tickets" setting put back.
 */

chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/tenancy.php';
require_once 'includes/rbac.php';
require_once 'includes/services/forms.php';
require_once 'includes/services/checklists.php';
require_once 'includes/services/task_forms.php';
require_once 'includes/case_report.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
exec(escapeshellarg(PHP_BINARY) . ' scripts/db_verify_cli.php --apply 2>&1', $o, $code);
if ($code !== 0) { check('Database Verify ran', false); exit(1); }

$sessDir = rtrim((string)(getenv('FREEITSM_SESS_DIR') ?: 'C:/wamp64/tmp'), '/');
$tag = 'zz272' . bin2hex(random_bytes(3));
$ticketId = (int)$conn->query("SELECT id FROM tickets WHERE deleted_datetime IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tickets_forms_enabled'"); $s->execute();
$oldSetting = $s->fetchColumn();
$maxAudit = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM ticket_audit")->fetchColumn();
$people = []; $keyIds = []; $sessions = []; $formIds = [];
$setOn = fn(?string $v) => $v === null
    ? $conn->exec("DELETE FROM system_settings WHERE setting_key = 'tickets_forms_enabled'")
    : $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('tickets_forms_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
$http = function (string $method, array $body = [], string $q = '') use ($base, $tag): array {
    $ch = curl_init("$base/api/tickets/forms.php$q");
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: x', 'Cookie: PHPSESSID=' . $tag . 'admin']];
    if ($method === 'POST') { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($body); }
    curl_setopt_array($ch, $o);
    $raw = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 300)]];
};

try {
    // A ZZ administrator with a forged session (the browser endpoint) and an API key.
    $u = $tag . 'admin';
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', 'ZZ form filler', ?, 1, 1, 1, 1)")
         ->execute([$u, "$u@example.invalid"]);
    $admin = $people[] = (int)$conn->lastInsertId();
    file_put_contents("$sessDir/sess_$u", 'analyst_id|i:' . $admin . ';analyst_username|s:' . strlen($u) . ':"' . $u . '";analyst_name|s:14:"ZZ form filler";csrf_token|s:1:"x";');
    $sessions[] = "$sessDir/sess_$u";
    $ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ form filler');

    // The form: an equipment request, version 1 then 2.
    $v1 = FormsService::saveForm($conn, $ctx, ['title' => 'ZZ-TKF Equipment', 'is_active' => 1, 'fields' => [
        ['field_type' => 'radio', 'label' => 'Device', 'is_required' => 1, 'options' => ['Laptop', 'Desktop']],
        ['field_type' => 'textarea', 'label' => 'Notes', 'is_required' => 0],
    ]]);
    $formIds[] = $v1['id'];
    $v2 = FormsService::createVersion($conn, $ctx, $v1['id']);
    $formIds[] = $v2['id'];
    $fq = $conn->prepare("SELECT id, label FROM form_fields WHERE form_id = ? ORDER BY sort_order, id");
    $fq->execute([$v2['id']]);
    $fields = $fq->fetchAll(PDO::FETCH_KEY_PAIR);
    $device = (int)array_search('Device', $fields, true);
    $notes  = (int)array_search('Notes', $fields, true);

    echo "Switched off\n";
    $setOn('0');
    [$c, $j] = $http('GET', [], "?ticket_id=$ticketId");
    check("GET says forms are off, and lists nothing ($c)", $c === 200 && ($j['enabled'] ?? null) === false && ($j['forms'] ?? null) === []);
    [$c, $j] = $http('POST', ['action' => 'add', 'ticket_id' => $ticketId, 'form_id' => $v1['id']]);
    check('adding is refused while off', ($j['success'] ?? null) === false && ($j['code'] ?? '') === 'disabled', json_encode($j));
    $conn->prepare("INSERT INTO ticket_forms (ticket_id, form_id, title, is_required, requirement_mode) VALUES (?, ?, 'ZZ-TKF Equipment', 1, 'block')")->execute([$ticketId, $v2['id']]);
    $e = null; try { ChecklistsService::assertClosureAllowed($conn, $ticketId); } catch (ServiceError $x) { $e = $x; }
    check('a required form left over from before is ignored by the close while off', !($e && $e->errorCode === 'forms_outstanding'));
    $conn->prepare("DELETE FROM ticket_forms WHERE ticket_id = ?")->execute([$ticketId]);
    $setOn('1');

    echo "\nAdding\n";
    [$c, $j] = $http('GET', [], "?ticket_id=$ticketId");
    check("on: GET lists the active forms to add ($c)", ($j['enabled'] ?? null) === true && in_array($v2['id'], array_column($j['available'] ?? [], 'id'), true)
        && !in_array($v1['id'], array_column($j['available'] ?? [], 'id'), true));
    [$c, $j] = $http('POST', ['action' => 'add', 'ticket_id' => $ticketId, 'form_id' => $v1['id'], 'required' => true, 'mode' => 'block']);
    $tf = (int)($j['forms'][0]['id'] ?? 0);
    check('added from version 1, it is filled on version 2 (the newest)', ($j['success'] ?? false) && (int)($j['forms'][0]['form_id'] ?? 0) === (int)$v2['id'], json_encode($j));
    [$c, $j] = $http('POST', ['action' => 'add', 'ticket_id' => $ticketId, 'form_id' => $v2['id']]);
    check('the same form twice is refused', ($j['success'] ?? null) === false);
    check('the ticket history says "Form added"', (int)$conn->query("SELECT COUNT(*) FROM ticket_audit WHERE id > $maxAudit AND ticket_id = $ticketId AND field_name = 'Form added'")->fetchColumn() === 1);
    [$c, $j] = $http('POST', ['action' => 'add', 'ticket_id' => 999999999, 'form_id' => $v2['id']]);
    check('a ticket that does not exist is "not found"', ($j['code'] ?? '') === 'not_found', json_encode($j));

    echo "\nThe close\n";
    $e = null; try { ChecklistsService::assertClosureAllowed($conn, $ticketId); } catch (ServiceError $x) { $e = $x; }
    check('required + block, not submitted: the close is refused, naming the form', $e && $e->errorCode === 'forms_outstanding' && str_contains($e->getMessage(), 'ZZ-TKF Equipment'), $e ? $e->getMessage() : 'no throw');
    $http('POST', ['action' => 'update', 'task_form_id' => $tf, 'required' => true, 'mode' => 'warn']);
    $e = null; try { ChecklistsService::assertClosureAllowed($conn, $ticketId); } catch (ServiceError $x) { $e = $x; }
    check('set to warn: the close is not refused by the form', !($e && $e->errorCode === 'forms_outstanding'));
    $http('POST', ['action' => 'update', 'task_form_id' => $tf, 'required' => true, 'mode' => 'block']);

    echo "\nFilling (the shared fill page's calls)\n";
    [$c, $j] = $http('GET', [], "?task_form_id=$tf");
    check("GET ?task_form_id gives the fill page a ticket host ($c)", ($j['fill']['host'] ?? '') === 'ticket', json_encode($j));
    $rev = (int)($j['fill']['revision'] ?? -1);
    [$c, $j] = $http('POST', ['action' => 'draft', 'task_form_id' => $tf, 'revision' => $rev, 'answers' => [(string)$device => 'Laptop']]);
    check('a draft saves and moves the revision on', ($j['success'] ?? false) && (int)($j['revision'] ?? 0) === $rev + 1, json_encode($j));
    [$c, $j] = $http('POST', ['action' => 'draft', 'task_form_id' => $tf, 'revision' => $rev, 'answers' => [(string)$device => 'Desktop']]);
    check('a draft from a stale revision is a conflict naming who saved', isset($j['conflict']) && ($j['conflict']['by'] ?? '') === 'ZZ form filler', json_encode($j));
    [$c, $j] = $http('POST', ['action' => 'submit', 'task_form_id' => $tf, 'revision' => $rev + 1, 'client_reference' => "$tag-1", 'data' => [(string)$notes => 'no device']]);
    check('a submit missing a required answer is refused', ($j['success'] ?? null) === false && !isset($j['conflict']), json_encode($j));
    [$c, $j] = $http('POST', ['action' => 'submit', 'task_form_id' => $tf, 'revision' => $rev + 1, 'client_reference' => "$tag-1", 'data' => [(string)$device => 'Laptop', (string)$notes => 'Docking station too']]);
    $sid = (int)($j['submission_id'] ?? 0);
    check('submitted', ($j['success'] ?? false) && $sid > 0, json_encode($j));
    $sub = $conn->query("SELECT for_ticket_id, task_id, form_id, client_reference FROM form_submissions WHERE id = $sid")->fetch(PDO::FETCH_ASSOC);
    check('...linked to the ticket (for_ticket_id), not to a task', (int)$sub['for_ticket_id'] === $ticketId && $sub['task_id'] === null && (int)$sub['form_id'] === (int)$v2['id'], json_encode($sub));
    check('the ticket history says "Form submitted"', (int)$conn->query("SELECT COUNT(*) FROM ticket_audit WHERE id > $maxAudit AND ticket_id = $ticketId AND field_name = 'Form submitted'")->fetchColumn() === 1);
    $e = null; try { ChecklistsService::assertClosureAllowed($conn, $ticketId); } catch (ServiceError $x) { $e = $x; }
    check('submitted: the form no longer holds up the close', !($e && $e->errorCode === 'forms_outstanding'));
    [$c, $j] = $http('GET', [], "?ticket_id=$ticketId&answers=1");
    $f = $j['forms'][0] ?? [];
    check('the panel shows who submitted, and the answers to someone with Forms access', ($f['submitted_by'] ?? '') === 'ZZ form filler' && ($f['answers'][0]['value'] ?? '') === 'Laptop', json_encode($f));

    echo "\nWho reads the answers\n";
    // Read the override's own source: the ticket class must check the ticket permission.
    $m = new ReflectionMethod('TicketForms', 'mayActOnAnswers');
    $src = implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    check('the ticket permission (not the task one) grants the answers', $m->getDeclaringClass()->getName() === 'TicketForms'
        && str_contains($src, 'TICKETS_FORM_ANSWERS') && !str_contains($src, 'TASKS_FORM_ANSWERS'));

    echo "\nForms keeps the evidence\n";
    $e = null; try { FormsService::deleteSubmission($conn, $ctx, $sid); } catch (ServiceError $x) { $e = $x; }
    check('a submission a ticket relies on cannot be deleted from Forms', $e && $e->errorCode === 'ticket_evidence', $e ? $e->errorCode : 'no throw');

    echo "\nCase report\n";
    $r = CaseReport::forTicket($conn, $ticketId, $admin, ['sections' => ['checklists'], 'internal' => false]);
    check('the case report carries the ticket\'s form, with its answers', ($r['ticket_forms'][0]['answers'][0]['value'] ?? '') === 'Laptop', json_encode($r['ticket_forms'] ?? null));

    echo "\nThe REST API\n";
    $keys = [];
    foreach (['rw' => ['ticket_forms' => ['read', 'create']], 'ro' => ['ticket_forms' => ['read']]] as $k => $perm) {
        $keys[$k] = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions) VALUES (?, ?, ?, ?, ?)')
             ->execute([$tag . $k, substr($keys[$k], 0, 12), hash('sha256', $keys[$k]), $admin, json_encode($perm)]);
        $keyIds[] = (int)$conn->lastInsertId();
    }
    $api = function (string $method, string $path, string $key, ?array $body = null) use ($base): array {
        $ch = curl_init($base . '/api/v1/index.php?path=' . rawurlencode($path));
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
              CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']];
        if ($body !== null) $o[CURLOPT_POSTFIELDS] = json_encode($body);
        curl_setopt_array($ch, $o);
        $raw = (string)curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 300)]];
    };
    [$c, $j] = $api('GET', "/tickets/$ticketId/forms", $keys['ro']);
    check("GET /tickets/{id}/forms ($c)", $c === 200 && ($j['data'][0]['requirement'] ?? '') === 'block' && ($j['data'][0]['answers'][0]['answer'] ?? '') === 'Laptop', json_encode($j));
    $path = "/tickets/$ticketId/forms/$tf/submissions";
    $body = ['data' => [(string)$device => 'Desktop'], 'client_reference' => "$tag-api"];
    [$c] = $api('POST', $path, $keys['ro'], $body);
    check("a read-only key cannot submit ($c)", $c === 403);
    [$c, $j] = $api('POST', $path, $keys['rw'], $body);
    check("POST .../submissions corrects it - 201 ($c)", $c === 201 && ($j['data']['ticket_form_id'] ?? 0) === $tf, json_encode($j));
    [$c, $j2] = $api('POST', $path, $keys['rw'], $body);
    check("...the same client_reference again is a replay - 200, same submission ($c)", $c === 200 && ($j2['data']['replayed'] ?? null) === true
        && ($j2['data']['submission_id'] ?? 0) === ($j['data']['submission_id'] ?? -1));
    [$c] = $api('POST', "/tickets/" . ($ticketId + 1000000) . "/forms/$tf/submissions", $keys['rw'], $body);
    check("a requirement addressed through the wrong ticket is refused ($c)", $c === 404);

    echo "\nRemoving, and merge\n";
    [$c, $j] = $http('POST', ['action' => 'remove', 'task_form_id' => $tf]);
    check('removed from the ticket', ($j['success'] ?? false) && ($j['forms'] ?? null) === [], json_encode($j));
    check('...its submissions stay in Forms', (int)$conn->query("SELECT COUNT(*) FROM form_submissions WHERE for_ticket_id = $ticketId AND form_id = " . (int)$v2['id'])->fetchColumn() === 2);
    require_once 'includes/ticket_merge.php';
    check('ticket forms move with a merge, once each', (MERGE_MOVE_DEDUPE['ticket_forms'] ?? '') === 'form_id');
} finally {
    $conn->exec("DELETE FROM ticket_forms WHERE ticket_id = $ticketId AND title LIKE 'ZZ-TKF%'");
    $conn->exec("DELETE FROM ticket_audit WHERE id > $maxAudit AND ticket_id = $ticketId AND field_name LIKE 'Form %'");
    if ($formIds) {
        $p = implode(',', array_map('intval', $formIds));
        $conn->exec("DELETE sd FROM form_submission_data sd JOIN form_submissions s ON s.id = sd.submission_id WHERE s.form_id IN ($p)");
        $conn->exec("UPDATE form_submissions SET superseded_by_id = NULL WHERE form_id IN ($p)");
        $conn->exec("DELETE FROM form_submissions WHERE form_id IN ($p)");
        $conn->exec("DELETE FROM form_fields WHERE form_id IN ($p)");
        foreach (array_reverse($formIds) as $f) $conn->exec("DELETE FROM forms WHERE id = " . (int)$f);
    }
    foreach ($keyIds as $id) { try { $conn->exec("DELETE FROM api_key_rate_limits WHERE api_key_id = $id"); } catch (Throwable) {} $conn->exec("DELETE FROM api_keys WHERE id = $id"); }
    $ids = implode(',', array_map('intval', $people ?: [0]));
    $conn->exec("DELETE FROM analysts WHERE id IN ($ids)");
    foreach ($sessions as $f) @unlink($f);
    $setOn($oldSetting === false ? null : $oldSetting);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
