<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * People a directory owns - they cannot be deleted here, and their group moves are recorded.
 *
 * Deleting an imported person used to succeed, cascade away their link to the
 * directory and their group memberships, and then the next import created them
 * again as a brand-new person with none of their history. So
 * userDeleteBlockedByDirectory() - which api/tickets/delete_user.php asks -
 * refuses anybody an import keeps up to date (is_managed), leaver or not, and
 * leaves everybody else to the endpoint's existing rules.
 *
 * And "when did Ana leave Finance?" had no answer: a run recorded only how many
 * members each group gained or lost. entraLogMembershipChanges() now writes one
 * group_join / group_leave entry per person, against the person, which is what
 * the run detail's filters and search depend on.
 *
 * Runs in a transaction that is ALWAYS rolled back.
 *
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/directory-managed-users.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/entra_sync.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nPeople a directory owns\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
// Writes (inside a transaction that is rolled back, but writes), so it runs only
// against the throwaway database it was pointed at - never a live install by accident.
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes a provider, people and a run's history.\n");
}
$conn->beginTransaction();
try {
    $conn->prepare("INSERT INTO auth_providers (protocol, display_name, issuer_url, client_id, enabled)
                    VALUES ('oidc', 'ZZ Managed test Entra', 'https://login.microsoftonline.com/zz-test/v2.0', 'zz-test', 1)")->execute();
    $pid = (int)$conn->lastInsertId();
    $person = function (string $name, int $managed, int $active = 1) use ($conn, $pid): int {
        $conn->prepare("INSERT INTO users (email, display_name, directory_username, is_active, is_managed, auth_provider_id) VALUES (?, ?, ?, ?, ?, ?)")
             ->execute([strtolower(str_replace(' ', '.', $name)) . '.mutest@example.invalid', $name,
                        strtolower(str_replace(' ', '.', $name)) . '@zz.example.invalid', $active, $managed, $managed ? $pid : null]);
        return (int)$conn->lastInsertId();
    };
    $imported = $person('MU Imported', 1);
    $leaver   = $person('MU Imported Leaver', 1, 0);
    $local    = $person('MU Local', 0);

    echo "\nDeleting:\n";
    $why = userDeleteBlockedByDirectory($conn, $imported);
    ok('an imported person cannot be deleted', $why !== null);
    ok('...and the reason names the directory', $why !== null && str_contains($why, 'ZZ Managed test Entra'), (string)$why);
    ok('...and says the next import would create them again', $why !== null && str_contains($why, 'create them again'));
    ok('an imported leaver cannot be deleted either', userDeleteBlockedByDirectory($conn, $leaver) !== null);
    ok('POSITIVE CONTROL: a person nobody imports is not blocked by this rule', userDeleteBlockedByDirectory($conn, $local) === null);
    ok('an id that does not exist is not blocked (the endpoint answers that itself)', userDeleteBlockedByDirectory($conn, 0) === null);

    echo "\nGroup moves in the run history:\n";
    $conn->prepare("INSERT INTO directory_sync_runs (provider_id, mode, status, started_datetime) VALUES (?, 'live', 'running', UTC_TIMESTAMP())")->execute([$pid]);
    $run = (int)$conn->lastInsertId();
    entraLogMembershipChanges($conn, $run, 'ZZ Finance', [$imported], [$leaver], false);
    $rows = $conn->prepare("SELECT action, user_id, display_name, detail FROM directory_sync_entries WHERE run_id = ? ORDER BY id");
    $rows->execute([$run]);
    $got = $rows->fetchAll(PDO::FETCH_ASSOC);
    ok('one entry per person who moved', count($got) === 2, json_encode($got));
    ok('a join is recorded against the person, by name', ($got[0]['action'] ?? '') === 'group_join'
        && (int)($got[0]['user_id'] ?? 0) === $imported && ($got[0]['display_name'] ?? '') === 'MU Imported', json_encode($got[0] ?? null));
    ok('...saying which group', ($got[0]['detail'] ?? '') === 'Joined the group ZZ Finance.', (string)($got[0]['detail'] ?? ''));
    ok('a leave is recorded the same way', ($got[1]['action'] ?? '') === 'group_leave' && (int)($got[1]['user_id'] ?? 0) === $leaver
        && ($got[1]['detail'] ?? '') === 'Left the group ZZ Finance.', json_encode($got[1] ?? null));
    ok('both actions fit the action column', max(array_map('strlen', ['group_join', 'group_leave'])) <= 16);

    entraLogMembershipChanges($conn, $run, 'ZZ Payroll', [$local], [], true);
    $p = $conn->prepare("SELECT detail FROM directory_sync_entries WHERE run_id = ? AND user_id = ?");
    $p->execute([$run, $local]);
    ok('a preview says what WOULD happen', $p->fetchColumn() === 'Would have joined the group ZZ Payroll.');
} finally {
    $conn->rollBack();
}

echo "\n" . str_repeat('=', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
