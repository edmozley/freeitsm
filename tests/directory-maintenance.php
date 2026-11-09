<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Directory maintenance (#261): a person is managed only while their source exists,
 * and the three lists that decide the rest.
 *
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/directory-maintenance.php
 *
 * Through the real userManagedSql(), personDetail(), peopleOrgGaps() and
 * DirectoryMaintenance. Builds two companies, two sources, people imported by
 * each, a source deleted the way a verified install deletes one (leaving a
 * dangling id), an import run that created one person and adopted another, and a
 * person with a ticket.
 *
 * Everything happens inside a transaction that is ALWAYS rolled back.
 *
 * The "what if" previews are checked against the writes they describe: who they
 * list, what they say happens to each, and that they change nothing.
 *
 * Controls: a person whose source still exists stays managed and stays out of
 * the orphan list, an analyst limited to one company sees nobody from the other,
 * and Remove imports nothing it was not asked to - so a person missing from a
 * list means "left out", not "broken".
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/people.php';
require_once __DIR__ . '/../includes/services/directory_maintenance.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}
/** The ServiceError code a call throws, or '' when it does not. */
function refused(callable $fn): string
{
    try { $fn(); return ''; } catch (ServiceError $e) { return $e->errorCode; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the throwaway database config.php points at (this one is {$dbName}).\n";
    exit(0);
}

$tag = 'zz-dm-' . bin2hex(random_bytes(3));
$conn->beginTransaction();
try {
    $co = function (string $name) use ($conn): int {
        $conn->prepare("INSERT INTO tenants (name, is_active, is_default) VALUES (?, 1, 0)")->execute([$name]);
        return (int)$conn->lastInsertId();
    };
    $a = $co("$tag A");
    $b = $co("$tag B");
    $analyst = function (bool $all) use ($conn, $tag): int {
        $n = $tag . ($all ? '-all' : '-a');
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules)
                        VALUES (?, 'x', ?, ?, 1, 1, ?, 1)")->execute([$n, $n, "$n@example.invalid", $all ? 1 : 0]);
        return (int)$conn->lastInsertId();
    };
    $r = $analyst(false);
    $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$r, $a]);
    $all = $analyst(true);
    $actor = new ActorContext($all, null, 'ui');
    $restricted = new ActorContext($r, [$a], 'ui');

    $source = function (string $name, int $sync = 1) use ($conn, $tag): int {
        $conn->prepare("INSERT INTO auth_providers (protocol, display_name, issuer_url, client_id, enabled, sync_enabled) VALUES ('ldap', ?, '', '', 1, ?)")
             ->execute(["$tag $name", $sync]);
        return (int)$conn->lastInsertId();
    };
    $n = 0;
    $mk = function (string $name, ?int $provider, int $tenant, bool $managed = true, ?string $lastSeen = null) use ($conn, $tag, &$n): int {
        $n++;
        $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active, is_managed, auth_provider_id, last_seen_in_source) VALUES (?, ?, ?, 1, ?, ?, ?)")
             ->execute(["$tag-$n@example.invalid", "$tag $name", $tenant, $managed ? 1 : 0, $provider, $lastSeen]);
        return (int)$conn->lastInsertId();
    };
    $managed = function (int $id) use ($conn): bool {
        $st = $conn->prepare("SELECT " . userManagedSql() . " FROM users u WHERE u.id = ?");
        $st->execute([$id]);
        return (int)$st->fetchColumn() === 1;
    };
    $row = function (int $id) use ($conn): ?array {
        $st = $conn->prepare("SELECT is_managed, auth_provider_id, is_active, deactivated_datetime FROM users WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $ids = fn(array $list) => array_column($list['people'], 'id');
    $now = gmdate('Y-m-d H:i:s');

    $src = $source('Directory');
    $kept = $mk('Kept', $src, $a, true, $now);
    $local = $mk('Local', null, $a, false);
    // A source deleted the way a verified install deletes one: no foreign key, so the id dangles.
    $gone = $source('Gone');
    $orphanA = $mk('Orphan A', $gone, $a);
    $orphanB = $mk('Orphan B', $gone, $b);
    $conn->prepare("SET FOREIGN_KEY_CHECKS = 0")->execute();
    $conn->prepare("DELETE FROM auth_providers WHERE id = ?")->execute([$gone]);
    $conn->prepare("SET FOREIGN_KEY_CHECKS = 1")->execute();
    $flaggedNoSource = $mk('Flagged, no source', null, $a);

    echo "One rule: managed only while the source exists\n";
    check('a person imported by a source that exists is managed', $managed($kept));
    check('a person whose source was deleted is not, though still flagged', !$managed($orphanA) && (int)$row($orphanA)['is_managed'] === 1);
    check('...nor one flagged with no source at all', !$managed($flaggedNoSource));
    check('CONTROL: a person nobody imports is not managed', !$managed($local));
    $pd = personDetail($conn, $all, $orphanA)['person'] ?? null;
    check('a person\'s page reads the same rule', $pd !== null && $pd['is_managed'] === false && (personDetail($conn, $all, $kept)['person']['is_managed'] ?? null) === true,
        json_encode([$pd['is_managed'] ?? null]));
    check('...and so does the delete refusal (#238)', userDeleteBlockedByDirectory($conn, $orphanA) === null && userDeleteBlockedByDirectory($conn, $kept) !== null);

    echo "People whose source was deleted\n";
    $orph = $ids(DirectoryMaintenance::orphans($conn, $actor));
    check('are listed, from every company the analyst can access', in_array($orphanA, $orph, true) && in_array($orphanB, $orph, true) && in_array($flaggedNoSource, $orph, true), json_encode($orph));
    check('CONTROL: somebody whose source exists is not', !in_array($kept, $orph, true) && !in_array($local, $orph, true));
    $orphR = $ids(DirectoryMaintenance::orphans($conn, $restricted));
    check('an analyst limited to one company sees only theirs', in_array($orphanA, $orphR, true) && !in_array($orphanB, $orphR, true), json_encode($orphR));
    $g = peopleOrgGaps($conn, $all);
    check('People shows them as a gap', in_array($orphanA, array_column($g['no_source']['people'], 'id'), true), json_encode($g['no_source']['total'] ?? null));

    $res = DirectoryMaintenance::apply($conn, $actor, 'orphans', 'keep', [$orphanA]);
    $ra = $row($orphanA);
    check('keep: an ordinary person, still current, pointing at nothing', array_column($res['done'], 'id') === [$orphanA]
        && (int)$ra['is_managed'] === 0 && $ra['auth_provider_id'] === null && (int)$ra['is_active'] === 1, json_encode($ra));
    DirectoryMaintenance::apply($conn, $actor, 'orphans', 'left', [$flaggedNoSource]);
    $rf = $row($flaggedNoSource);
    check('left: marked as left, with the date', (int)$rf['is_active'] === 0 && $rf['deactivated_datetime'] !== null);
    $res = DirectoryMaintenance::apply($conn, $restricted, 'orphans', 'delete', [$orphanB, $kept]);
    check('nobody out of reach, or not in the list, is touched', $res['done'] === [] && count($res['skipped']) === 2
        && $row($orphanB) !== null && $row($kept) !== null, json_encode($res));
    $conn->prepare("INSERT INTO tickets (ticket_number, subject, user_id, tenant_id) VALUES (?, 'ZZ maintenance', ?, ?)")->execute(["$tag-T1", $orphanB, $b]);
    $res = DirectoryMaintenance::apply($conn, $actor, 'orphans', 'delete', [$orphanB]);
    check('nobody with a ticket is deleted, and it says why', $res['done'] === [] && str_contains($res['skipped'][0]['reason'] ?? '', 'ticket') && $row($orphanB) !== null,
        json_encode($res['skipped']));
    check('an action a list does not offer is refused', refused(fn() => DirectoryMaintenance::apply($conn, $actor, 'orphans', 'remove', [$orphanB])) === 'invalid_field');
    // Four writes so far: three by the analyst who sees everything, one by the restricted one.
    $log = $conn->prepare("SELECT analyst_id, COUNT(*) FROM system_logs WHERE log_type = 'directory_maintenance' AND analyst_id IN (?, ?) GROUP BY analyst_id");
    $log->execute([$all, $r]);
    $byWho = $log->fetchAll(PDO::FETCH_KEY_PAIR);
    check('every write is logged, with who', (int)($byWho[$all] ?? 0) === 3 && (int)($byWho[$r] ?? 0) === 1, json_encode($byWho));

    echo "People a source no longer brings in\n";
    $old = $mk('Not seen', $src, $a, true, gmdate('Y-m-d H:i:s', time() - 200 * 86400));
    $never = $mk('Never seen', $src, $a, true, null);
    $st90 = $ids(DirectoryMaintenance::stale($conn, $actor, $src, 90));
    check('not found by an import for 200 days: on the 90-day list', in_array($old, $st90, true) && in_array($never, $st90, true), json_encode($st90));
    check('CONTROL: found today: not', !in_array($kept, $st90, true));
    check('...and not on the 365-day list', !in_array($old, $ids(DirectoryMaintenance::stale($conn, $actor, $src, 365)), true));
    check('a length not offered is refused', refused(fn() => DirectoryMaintenance::stale($conn, $actor, $src, 7)) === 'invalid_field');
    check('the count on the source\'s page is the length of that list', DirectoryMaintenance::staleCount($conn, $actor, $src, 90) === count($st90)
        && DirectoryMaintenance::staleCount($conn, $actor, $src, DirectoryMaintenance::STALE_DEFAULT) === count($ids(DirectoryMaintenance::stale($conn, $actor, $src, DirectoryMaintenance::STALE_DEFAULT))));
    check('...in the reader\'s companies only', DirectoryMaintenance::staleCount($conn, new ActorContext($r, [$a], 'ui'), $src, 90)
        === count($ids(DirectoryMaintenance::stale($conn, new ActorContext($r, [$a], 'ui'), $src, 90))));
    DirectoryMaintenance::apply($conn, $actor, 'stale', 'keep', [$old], ['provider_id' => $src, 'days' => 90]);
    check('keep: the source no longer manages them', !$managed($old) && $row($old)['auth_provider_id'] === null);

    echo "Remove imported people\n";
    $src2 = $source('Import');
    $created = $mk('Created', $src2, $a);
    $adopted = $mk('Adopted', $src2, $a);
    $createdTicket = $mk('Created, with a ticket', $src2, $a);
    $conn->prepare("INSERT INTO directory_sync_runs (provider_id, mode, status, created_count, adopted_count) VALUES (?, 'live', 'ok', 2, 1)")->execute([$src2]);
    $run = (int)$conn->lastInsertId();
    $entry = $conn->prepare("INSERT INTO directory_sync_entries (run_id, action, user_id) VALUES (?, ?, ?)");
    $entry->execute([$run, 'created', $created]);
    $entry->execute([$run, 'created', $createdTicket]);
    $entry->execute([$run, 'adopted', $adopted]);
    $conn->prepare("INSERT INTO tickets (ticket_number, subject, user_id, tenant_id) VALUES (?, 'ZZ maintenance', ?, ?)")->execute(["$tag-T2", $createdTicket, $a]);
    $conn->prepare("INSERT INTO user_sso_identities (user_id, provider_id, subject) VALUES (?, ?, ?)")->execute([$created, $src2, "$tag-sub"]);

    $imp = DirectoryMaintenance::imported($conn, $actor, $src2, null);
    check('lists the people the source created', in_array($created, $ids($imp), true) && in_array($createdTicket, $ids($imp), true));
    check('...never one it adopted', !in_array($adopted, $ids($imp), true));
    check('...and says the source still imports', $imp['source_imports'] === true);
    $runs = DirectoryMaintenance::importRuns($conn, $actor, $src2);
    check('the run is offered, with how many are still here', ($runs[0]['id'] ?? 0) === $run && $runs[0]['remaining'] === 2, json_encode($runs));
    check('refused while the source imports: the next run would create them again',
        refused(fn() => DirectoryMaintenance::apply($conn, $actor, 'imported', 'remove', [$created], ['provider_id' => $src2])) === 'source_imports'
        && $row($created) !== null);
    echo "What if: Remove imported people\n";
    $pv = DirectoryMaintenance::previewRemove($conn, $actor, $src2, $run, null);
    $by = array_column($pv['people'], null, 'id');
    check('lists the same people as Remove would act on, never the adopted one', isset($by[$created], $by[$createdTicket]) && !isset($by[$adopted]), json_encode(array_keys($by)));
    check('the created one would be deleted, with their sign-in link', ($by[$created]['outcome'] ?? '') === 'removed' && ($by[$created]['signins'] ?? 0) === 1, json_encode($by[$created] ?? null));
    check('the one with a ticket would be skipped, and it says why', ($by[$createdTicket]['outcome'] ?? '') === 'skipped' && str_contains((string)($by[$createdTicket]['reason'] ?? ''), 'ticket'));
    check('...and that the source still imports', $pv['source_imports'] === true);
    $pick = DirectoryMaintenance::previewRemove($conn, $actor, $src2, null, [$created, $adopted]);
    check('for the ones picked: only those of them in the list', array_column($pick['people'], 'id') === [$created], json_encode(array_column($pick['people'], 'id')));
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules)
                    VALUES (?, 'x', ?, ?, 1, 1, 0, 1)")->execute(["$tag-b", "$tag-b", "$tag-b@example.invalid"]);
    $onlyB = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$onlyB, $b]);
    check('an analyst who can access only another company sees none of them',
        DirectoryMaintenance::previewRemove($conn, new ActorContext($onlyB, [$b], 'ui'), $src2, null, null)['people'] === []);
    check('a preview changes nothing', $row($created) !== null && $managed($created));

    $conn->prepare("UPDATE auth_providers SET sync_enabled = 0 WHERE id = ?")->execute([$src2]);
    $res = DirectoryMaintenance::apply($conn, $actor, 'imported', 'remove', [$created, $createdTicket, $adopted], ['provider_id' => $src2, 'run_id' => $run]);
    check('with its import off: the created person is removed', array_column($res['done'], 'id') === [$created] && $row($created) === null, json_encode($res));
    $idn = $conn->prepare("SELECT COUNT(*) FROM user_sso_identities WHERE user_id = ?");
    $idn->execute([$created]);
    check('...with their sign-in link, which a verified install has no foreign key to take', (int)$idn->fetchColumn() === 0);
    check('the one with a ticket is skipped, and stays', $row($createdTicket) !== null && count(array_filter($res['skipped'], fn($s) => $s['id'] === $createdTicket)) === 1);
    check('the adopted one is not touched', $row($adopted) !== null);

    echo "Deleting a source\n";
    $src3 = $source('To delete');
    $cur = $mk('Current', $src3, $a);
    $plain = $mk('Unflagged, linked', $src3, $a, false);
    check('the count the delete dialog shows', DirectoryMaintenance::sourcePeopleCount($conn, $src3) === 1);

    echo "What if: deleting a source\n";
    $conn->prepare("INSERT INTO user_sso_identities (user_id, provider_id, subject) VALUES (?, ?, ?)")->execute([$cur, $src3, "$tag-sub3"]);
    $pv = DirectoryMaintenance::previewSourceDelete($conn, $src3, false);
    check('lists its people, each kept as an ordinary person', $pv['people_total'] === 1 && array_column($pv['people'], 'outcome', 'id') === [$cur => 'kept'], json_encode($pv['people']));
    check('says which fields unlock for them: all a directory locks (LDAP)', $pv['unlocks'] === USER_DIRECTORY_OWNED, json_encode($pv['unlocks']));
    check('counts the people only linked to it, whose link goes', $pv['unlinked'] === 1);
    $rec = array_column($pv['records'], null, 'key');
    check('lists the sign-in links that go with it', ($rec['user_signins']['count'] ?? 0) === 1 && $rec['user_signins']['outcome'] === 'removed', json_encode($pv['records']));
    check('says the scheduled import imports it now', $pv['schedule']['imports'] === true);
    $pvl = DirectoryMaintenance::previewSourceDelete($conn, $src3, true);
    check('with "mark them as left": the current ones are marked as left', array_column($pvl['people'], 'outcome', 'id') === [$cur => 'marked_left']);
    check('a preview changes nothing', $managed($cur) && (int)$row($cur)['is_active'] === 1);
    check('a provider that does not exist is refused', refused(fn() => DirectoryMaintenance::previewSourceDelete($conn, $gone, false)) === 'not_found');
    $released = DirectoryMaintenance::releaseSource($conn, $actor, $src3, "$tag To delete", false);
    $rc = $row($cur);
    check('keep: its people stay, current and editable, pointing at nothing', $released === 1 && (int)$rc['is_managed'] === 0 && $rc['auth_provider_id'] === null && (int)$rc['is_active'] === 1);
    check('...and anybody else linked to it lets go of it too', $row($plain)['auth_provider_id'] === null);
    $src4 = $source('To delete, leavers');
    $cur4 = $mk('Current 4', $src4, $a);
    DirectoryMaintenance::releaseSource($conn, $actor, $src4, "$tag To delete, leavers", true);
    $r4 = $row($cur4);
    check('left: marked as left as well', (int)$r4['is_active'] === 0 && (int)$r4['is_managed'] === 0);

    echo "Remove imported people, after the source was deleted\n";
    $src5 = $source('Deleted later');
    $made5 = $mk('Made by 5', $src5, $a);
    $adopted5 = $mk('Adopted by 5', $src5, $a);
    $moved5 = $mk('Made by 5, now another source', $src5, $a);
    $conn->prepare("INSERT INTO directory_sync_runs (provider_id, mode, status, created_count, adopted_count) VALUES (?, 'live', 'ok', 2, 1)")->execute([$src5]);
    $run5 = (int)$conn->lastInsertId();
    $entry->execute([$run5, 'created', $made5]);
    $entry->execute([$run5, 'created', $moved5]);
    $entry->execute([$run5, 'adopted', $adopted5]);
    // Deleted the way the endpoint deletes it, keeping its people.
    DirectoryMaintenance::releaseSource($conn, $actor, $src5, "$tag Deleted later", false);
    $conn->prepare("DELETE FROM auth_providers WHERE id = ?")->execute([$src5]);
    // ...and one of them taken on by a source that exists since.
    $conn->prepare("UPDATE users SET is_managed = 1, auth_provider_id = ? WHERE id = ?")->execute([$src, $moved5]);

    $del = array_column(DirectoryMaintenance::deletedSources($conn), 'name', 'id');
    check('the deleted source is offered, by the name it had', ($del[$src5] ?? '') === "$tag Deleted later", json_encode($del));
    $imp5 = DirectoryMaintenance::imported($conn, $actor, $src5, null);
    check('lists whom its imports created, as the delete recorded them', in_array($made5, $ids($imp5), true), json_encode($ids($imp5)));
    check('...never one it adopted', !in_array($adopted5, $ids($imp5), true));
    check('...nor one another source keeps up to date now', !in_array($moved5, $ids($imp5), true));
    check('...and it does not import any more', $imp5['source_imports'] === false);
    // The runs only where the history outlived the source (no foreign key here).
    $hasFk = (int)$conn->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
                                 AND TABLE_NAME = 'directory_sync_runs' AND REFERENCED_TABLE_NAME = 'auth_providers' AND DELETE_RULE = 'CASCADE'")->fetchColumn() > 0;
    $runs5 = DirectoryMaintenance::importRuns($conn, $actor, $src5);
    check($hasFk ? 'its history went with it (this database has the foreign key): no runs, and the list still works'
                 : 'its run is offered, with how many are still here',
        $hasFk ? $runs5 === [] : (($runs5[0]['remaining'] ?? null) === 1), json_encode($runs5));
    $pvd = DirectoryMaintenance::previewSourceDelete($conn, $src, false);
    $hist = array_column($pvd['records'], 'outcome', 'key')['import_runs'] ?? null;
    check('the delete preview says what really happens to the history here', $hist === null || $hist === ($hasFk ? 'removed' : 'kept'), (string)$hist);
    $pv5 = DirectoryMaintenance::previewRemove($conn, $actor, $src5, null, null);
    check('the preview names it and lists the same people', $pv5['source']['name'] === "$tag Deleted later" && array_column($pv5['people'], 'id') === [$made5]);
    $res = DirectoryMaintenance::apply($conn, $actor, 'imported', 'remove', [$made5, $adopted5, $moved5], ['provider_id' => $src5]);
    check('Remove works with nothing to switch off first, and only on them',
        array_column($res['done'], 'id') === [$made5] && $row($made5) === null && $row($adopted5) !== null && $row($moved5) !== null, json_encode($res));
    check('a source that never existed is still refused', refused(fn() => DirectoryMaintenance::imported($conn, $actor, 999999999, null)) === 'not_found');
} catch (Throwable $e) {
    check('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = $conn->prepare("SELECT COUNT(*) FROM users WHERE email LIKE ?");
$left->execute(["$tag-%"]);
check('nothing survived the run (rolled back)', (int)$left->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
