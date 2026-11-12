<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Undo merge gives the merged-away ticket back everything the merge took - including the
 * rows it DELETED because the survivor already had the same one.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/ticket-merge-undo.php
 *
 * The merge de-duplicates watchers, CMDB links, assets, problems, changes and forms: if both
 * tickets have the same one, the merged-away ticket's copy is deleted. Until 5.1.0 nothing
 * recorded it, so Undo merge left that ticket without it - a watcher on both tickets stopped
 * watching the one that came back. Now the merge keeps the whole rows and undo restores them.
 *
 * Writes two ZZ tickets, a ZZ analyst and their watchers on the database named, and removes
 * them all at the end. Refuses to run unless config.php points at FREEITSM_TEST_DATABASE.
 */

chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/tenancy.php';
require_once 'includes/rbac.php';
require_once 'includes/ticket_merge.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$tag = 'zzmrg' . bin2hex(random_bytes(3));
$people = []; $tickets = [];
$watchers = function (int $ticketId) use ($conn): array {
    $q = $conn->prepare("SELECT analyst_id FROM ticket_watchers WHERE ticket_id = ? ORDER BY analyst_id");
    $q->execute([$ticketId]);
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
};

try {
    $mk = function (string $name, int $admin) use ($conn, $tag, &$people): int {
        $u = $tag . $name;
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', ?, ?, 1, ?, 1, 1)")
             ->execute([$u, "ZZ $name", "$u@example.invalid", $admin]);
        return $people[] = (int)$conn->lastInsertId();
    };
    $admin = $mk('admin', 1);
    $both  = $mk('both', 0);    // watches both tickets - the case that was lost
    $only  = $mk('only', 0);    // watches only the merged-away one - moved and back, always worked
    $keep  = $mk('keep', 0);    // watches only the survivor - must stay there
    $_SESSION = ['analyst_id' => $admin];

    foreach (['source', 'target'] as $k) {
        $conn->prepare("INSERT INTO tickets (ticket_number, subject, created_datetime, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([strtoupper($tag) . '-' . $k, "ZZ merge undo $k"]);
        $tickets[$k] = (int)$conn->lastInsertId();
    }
    $w = $conn->prepare("INSERT INTO ticket_watchers (ticket_id, analyst_id, added_by_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())");
    $w->execute([$tickets['source'], $both, $admin]);
    $w->execute([$tickets['source'], $only, $admin]);
    $w->execute([$tickets['target'], $both, $admin]);
    $w->execute([$tickets['target'], $keep, $admin]);

    mergeTickets($conn, $admin, [$tickets['source']], $tickets['target']);
    check('after the merge the survivor has each watcher once', $watchers($tickets['target']) === [$both, $only, $keep],
        json_encode($watchers($tickets['target'])));
    check('after the merge the merged-away ticket has none', $watchers($tickets['source']) === []);

    $m = $conn->prepare("SELECT id, moved_related FROM ticket_merges WHERE source_ticket_id = ? ORDER BY id DESC LIMIT 1");
    $m->execute([$tickets['source']]);
    $merge = $m->fetch(PDO::FETCH_ASSOC);
    $rel = json_decode((string)($merge['moved_related'] ?? ''), true) ?: [];
    check('the merge recorded the duplicate row it deleted', count($rel['_dropped']['ticket_watchers'] ?? []) === 1);

    unmergeTicket($conn, $admin, (int)$merge['id']);
    check('after Undo merge the ticket that came back has BOTH its watchers again (the shared one was lost before 5.1.0)',
        $watchers($tickets['source']) === [$both, $only], json_encode($watchers($tickets['source'])));
    check('the survivor keeps its own watchers', $watchers($tickets['target']) === [$both, $keep], json_encode($watchers($tickets['target'])));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($tickets) {
        $in = implode(',', $tickets);
        foreach (["DELETE FROM ticket_watchers WHERE ticket_id IN ($in)", "DELETE FROM ticket_merges WHERE source_ticket_id IN ($in) OR target_ticket_id IN ($in)",
                  "DELETE FROM ticket_links WHERE source_ticket_id IN ($in) OR target_ticket_id IN ($in)", "DELETE FROM ticket_audit WHERE ticket_id IN ($in)",
                  "DELETE FROM tickets WHERE id IN ($in)"] as $sql) {
            try { $conn->exec($sql); } catch (Throwable) {}
        }
    }
    if ($people) { try { $conn->exec("DELETE FROM analysts WHERE id IN (" . implode(',', $people) . ")"); } catch (Throwable) {} }
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
