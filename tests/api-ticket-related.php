<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A ticket's `related` block and GET /tickets/{id}/attachments (GitHub #328).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/api-ticket-related.php
 *
 * Two ZZ tickets (one the parent of the other), a note with a document, a message with
 * one attachment and one inline picture, and a task on the ticket. Called over real HTTP
 * with two temporary API keys - one with the sub-endpoint permissions, one with tickets
 * read only - to prove the counts and links are right AND never say more than the key may
 * read. Then the Full record webhook path. Everything ZZ is removed in finally.
 *
 * @wiki REST-API-Ticket-Related-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/tickets.php';
require_once __DIR__ . '/../workflow/includes/engine.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}
$rest = function (string $path, string $key) use ($base): array {
    [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
    $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode($p) . ($q !== '' ? '&' . $q : ''));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
    $raw = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 300)]];
};

$tag = 'ZZ328-' . bin2hex(random_bytes(3));
$tickets = []; $keys = []; $docs = []; $tasks = []; $emails = [];
try {
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $tenant = getDefaultTenantId($conn);
    $user = (int)$conn->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ tester');
    $t1 = $tickets[] = TicketsService::createTicket($conn, $ctx, $tenant, ['subject' => "$tag main", 'user_id' => $user], null, 'ZZ test');
    $t2 = $tickets[] = TicketsService::createTicket($conn, $ctx, $tenant, ['subject' => "$tag SECRET child subject", 'user_id' => $user], null, 'ZZ test');
    $conn->prepare("INSERT INTO ticket_links (source_ticket_id, target_ticket_id, relation_type) VALUES (?, ?, 'parent')")->execute([$t1, $t2]);
    $t2num = (string)$conn->query("SELECT ticket_number FROM tickets WHERE id = $t2")->fetchColumn();

    $note = TicketsService::createNote($conn, $ctx, $t1, ['text' => "$tag a note", 'is_internal' => true]);
    $noteId = (int)(is_array($note) ? ($note['note_id'] ?? $note['id'] ?? 0) : $note)
        ?: (int)$conn->query("SELECT MAX(id) FROM ticket_notes WHERE ticket_id = $t1")->fetchColumn();
    $conn->prepare("INSERT INTO documents (kind, title, original_name, mime_type, size_bytes) VALUES ('file', ?, ?, 'application/pdf', 1234)")->execute(["$tag doc", "$tag.pdf"]);
    $docs[] = $doc = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO document_links (document_id, parent_type, parent_id) VALUES (?, 'ticket_note', ?)")->execute([$doc, $noteId]);

    $conn->prepare("INSERT INTO emails (ticket_id, subject, direction, received_datetime) VALUES (?, ?, 'inbound', UTC_TIMESTAMP())")->execute([$t1, "$tag mail"]);
    $emails[] = $mail = (int)$conn->lastInsertId();
    $att = $conn->prepare("INSERT INTO email_attachments (email_id, filename, content_type, file_path, file_size, is_inline) VALUES (?, ?, ?, 'zz/none', ?, ?)");
    $att->execute([$mail, "$tag.xlsx", 'application/vnd.ms-excel', 2048, 0]);
    $att->execute([$mail, "$tag-logo.png", 'image/png', 99, 1]);

    $conn->prepare("INSERT INTO tasks (title, ticket_id, created_by_id, tenant_id) VALUES (?, ?, ?, ?)")->execute(["$tag task", $t1, $admin, $tenant]);
    $tasks[] = $task = (int)$conn->lastInsertId();

    $mkKey = function (array $perms) use ($conn, $admin, &$keys, $tag): string {
        $key = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())')
             ->execute(["$tag key", substr($key, 0, 12), hash('sha256', $key), $admin, json_encode($perms)]);
        $keys[] = (int)$conn->lastInsertId();
        return $key;
    };
    $full = $mkKey(['tickets' => ['read'], 'ticket_notes' => ['read'], 'ticket_thread' => ['read'], 'ticket_attachments' => ['read'],
                    'ticket_forms' => ['read'], 'ticket_checklists' => ['read'], 'ticket_watchers' => ['read'], 'ticket_time_entries' => ['read'], 'tasks' => ['read']]);
    $narrow = $mkKey(['tickets' => ['read']]);
    $msgs = (int)$conn->query("SELECT COUNT(*) FROM emails WHERE ticket_id = $t1")->fetchColumn();

    echo "GET /tickets/{id} with the sub-endpoint permissions\n";
    [$code, $r] = $rest("/tickets/$t1", $full);
    $rel = $r['data']['related'] ?? null;
    check('200 and a related block', $code === 200 && is_array($rel), json_encode($r));
    $c = $rel['counts'] ?? [];
    check('counts: 1 note, every message, 2 attachments (the inline picture left out)', ($c['notes'] ?? -1) === 1 && ($c['messages'] ?? -1) === $msgs && ($c['attachments'] ?? -1) === 2, json_encode($c));
    check('counts: forms, checklists, watchers and time entries are all there', count(array_intersect(['forms', 'checklists', 'watchers', 'time_entries'], array_keys($c))) === 4, json_encode($c));
    $links = $rel['links'] ?? [];
    $byType = fn(string $t) => array_values(array_filter($links, fn($l) => $l['type'] === $t));
    $tk = $byType('task');
    check('links: the task, by id and name', count($tk) === 1 && $tk[0]['id'] === $task && $tk[0]['name'] === "$tag task", json_encode($links));
    $tt = $byType('ticket');
    check('links: the child ticket, parent_of, by number', count($tt) === 1 && $tt[0]['id'] === $t2 && $tt[0]['relation'] === 'parent_of' && $tt[0]['reference'] === $t2num, json_encode($tt));
    check('links: a linked ticket never carries its subject', !str_contains(json_encode($links), 'SECRET'));

    [, $r2] = $rest("/tickets/$t2", $full);
    $back = array_values(array_filter($r2['data']['related']['links'] ?? [], fn($l) => $l['type'] === 'ticket'));
    check('from the child: child_of the main ticket', count($back) === 1 && $back[0]['id'] === $t1 && $back[0]['relation'] === 'child_of', json_encode($back));

    echo "GET /tickets/{id} with tickets read only\n";
    [$code, $r] = $rest("/tickets/$t1", $narrow);
    $rel = $r['data']['related'] ?? null;
    check('no counts - the key cannot read any sub-endpoint', $code === 200 && is_array($rel) && $rel['counts'] === [], json_encode($rel));
    check('no task link - the key cannot read tasks; the ticket link stays', !array_filter($rel['links'] ?? [], fn($l) => $l['type'] === 'task')
        && array_filter($rel['links'] ?? [], fn($l) => $l['type'] === 'ticket'), json_encode($rel['links'] ?? null));

    echo "GET /tickets/{id}/attachments\n";
    [$code, $r] = $rest("/tickets/$t1/attachments", $full);
    $list = $r['data'] ?? [];
    $src = array_column($list, 'source');
    sort($src);
    check('the message file and the note document, no inline picture', $code === 200 && count($list) === 2 && $src === ['message', 'note'], json_encode($r));
    $m = array_values(array_filter($list, fn($a) => $a['source'] === 'message'))[0] ?? [];
    $n = array_values(array_filter($list, fn($a) => $a['source'] === 'note'))[0] ?? [];
    check('names, types, sizes and where each hangs', ($m['name'] ?? '') === "$tag.xlsx" && ($m['size_bytes'] ?? 0) === 2048 && ($m['source_id'] ?? 0) === $mail
        && ($n['name'] ?? '') === "$tag.pdf" && ($n['source_id'] ?? 0) === $noteId && ($n['content_type'] ?? '') === 'application/pdf', json_encode($list));
    [$code] = $rest("/tickets/$t1/attachments", $narrow);
    check('403 without ticket_attachments.read', $code === 403, (string)$code);

    echo "GET /tickets\n";
    [, $r] = $rest("/tickets?q=" . rawurlencode($tag) . "&include=related", $full);
    $rows = $r['data'] ?? [];
    check('?include=related adds it to every row', count($rows) === 2 && count(array_filter($rows, fn($x) => isset($x['related']['counts']))) === 2, json_encode(array_map(fn($x) => array_keys($x), $rows)));
    [, $r] = $rest("/tickets?q=" . rawurlencode($tag), $full);
    check('without it, the list is as before', count($r['data'] ?? []) === 2 && !array_filter($r['data'], fn($x) => isset($x['related'])));

    echo "The Full record webhook\n";
    $p = WorkflowEngine::enrichWithFullObjects($conn, ['ticket' => ['id' => $t1]]);
    $wr = $p['ticket']['full']['related'] ?? null;
    check('ticket.full carries related - every count, the task and the child', is_array($wr) && count($wr['counts']) === 7 && ($wr['counts']['attachments'] ?? 0) === 2
        && array_filter($wr['links'], fn($l) => $l['type'] === 'task') && array_filter($wr['links'], fn($l) => $l['type'] === 'ticket'), json_encode($wr));
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($keys) $conn->exec("DELETE FROM api_keys WHERE id IN (" . implode(',', $keys) . ")");
    if ($tasks) $conn->exec("DELETE FROM tasks WHERE id IN (" . implode(',', $tasks) . ")");
    if ($emails) $conn->exec("DELETE FROM email_attachments WHERE email_id IN (" . implode(',', $emails) . ")");
    if ($docs) { $in = implode(',', $docs); $conn->exec("DELETE FROM document_links WHERE document_id IN ($in)"); $conn->exec("DELETE FROM documents WHERE id IN ($in)"); }
    $tickets = array_values(array_filter(array_map('intval', $tickets)));
    if ($tickets) {
        $in = implode(',', $tickets);
        $conn->exec("DELETE FROM ticket_links WHERE source_ticket_id IN ($in) OR target_ticket_id IN ($in)");
        $tables = $conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                  AND COLUMN_NAME = 'ticket_id' AND TABLE_NAME <> 'tickets'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $tbl) { try { $conn->exec("DELETE FROM `$tbl` WHERE ticket_id IN ($in)"); } catch (Throwable) {} }
        $conn->exec("DELETE FROM tickets WHERE id IN ($in)");
    }
    $left = (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE subject LIKE " . $conn->quote("$tag%"))->fetchColumn();
    check('cleaned up', $left === 0);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
