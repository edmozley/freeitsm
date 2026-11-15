<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A location on people, tickets, contracts and projects (GH #311).
 *
 *   php tests/record-locations.php      (run from a TEST install, never a real one)
 *
 * Writes throwaway rows and removes them by the ids it got back, in a finally:
 * a "ZZ" second company, three ZZ locations (one each for the Default company,
 * the ZZ company and shared), a ZZ person, ZZ tickets (with their emails and
 * history), a ZZ contract and a ZZ project. It snapshots and restores the
 * ticket number counters and the ticket_location_from_requester setting.
 *
 * Covers:
 *   - recordLocationCheck(): own company, shared, another company's, unknown;
 *   - a new ticket takes its requester's location (setting on / off), a chosen
 *     one wins, a bad one is refused;
 *   - editing a ticket's location is recorded; moving the ticket to a company
 *     that cannot use it drops it;
 *   - contracts and projects take a location and refuse another company's;
 *   - deleting a location in use is refused (the real endpoint), and the
 *     Locations in use move skips a record the new location is not open to;
 *   - every route that makes a ticket calls ticketApplyRequesterLocation() (static).
 * Control: without the fix, the requester-location and refusal checks fail.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/record_locations.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/tickets.php';
require_once __DIR__ . '/../includes/services/contracts.php';
require_once __DIR__ . '/../includes/services/projects.php';

$pass = 0; $fail = 0;
/** Records and prints one PASS or FAIL line. */
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}
/** True when the call throws (a ServiceError or InvalidArgumentException). */
function throws(callable $f): bool { try { $f(); return false; } catch (Throwable $e) { return true; } }

echo "\nA location on people, tickets, contracts and projects (GH #311)\n" . str_repeat('=', 70) . "\n";
$conn = connectToDatabase();
$db = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
echo "  database: $db\n";
if (!preg_match('/test/i', $db)) { echo "  STOP  this test adds a company; run it only on a test install\n"; exit(1); }
foreach (['users', 'tickets', 'contracts', 'projects'] as $t) {
    if (!recordLocationReady($conn, $t)) { echo "  STOP  run Database Verification first ($t.location_id missing)\n"; exit(1); }
}
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 AND can_access_all_tenants = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$admin) { echo "  SKIP  no all-company administrator\n"; exit(0); }

$counters = $conn->query("SELECT * FROM ticket_number_counters")->fetchAll(PDO::FETCH_ASSOC);
$settingWas = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_location_from_requester'")->fetchColumn();
$tenantZ = 0; $locs = []; $userIds = []; $ticketIds = []; $contractIds = []; $projectIds = [];
$setSetting = function (?string $v) use ($conn) {
    $conn->prepare("DELETE FROM system_settings WHERE setting_key = 'ticket_location_from_requester'")->execute();
    if ($v !== null) $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('ticket_location_from_requester', ?)")->execute([$v]);
};
try {
    $conn->exec("INSERT INTO tenants (name) VALUES ('ZZ record locations test')");
    $tenantZ = (int)$conn->lastInsertId();
    $def = getDefaultTenantId($conn);
    $mkLoc = function (string $name, ?int $tenant, int $shared) use ($conn, &$locs) {
        $conn->prepare("INSERT INTO asset_locations (name, tenant_id, is_shared) VALUES (?, ?, ?)")->execute([$name, $tenant, $shared]);
        return $locs[] = (int)$conn->lastInsertId();
    };
    $locDef    = $mkLoc('ZZ Loc Default', null, 0);
    $locZ      = $mkLoc('ZZ Loc Other', $tenantZ, 0);
    $locShared = $mkLoc('ZZ Loc Shared', null, 1);

    echo "\nThe rule\n";
    ok('a company may use its own location', recordLocationCheck($conn, $def, $locDef) === $locDef);
    ok('a company may use a shared location', recordLocationCheck($conn, $tenantZ, $locShared) === $locShared);
    ok("a company may not use another company's location", throws(fn() => recordLocationCheck($conn, $def, $locZ)));
    ok('an unknown location is refused', throws(fn() => recordLocationCheck($conn, $def, 99999999)));
    ok('blank means none', recordLocationCheck($conn, $def, '') === null);
    ok('the path is the full path', recordLocationPath($conn, $locDef) === 'ZZ Loc Default');

    // A person with a location.
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id, location_id, is_active, created_at) VALUES ('zz-recloc@example.invalid', 'ZZ Recloc', ?, ?, 1, UTC_TIMESTAMP())")->execute([$def, $locDef]);
    $userIds[] = $person = (int)$conn->lastInsertId();
    $ctx = ActorContext::fromApiKey(['analyst_id' => $admin, 'company_scope' => null, 'analyst_name' => 'test']);
    $mkTicket = function (array $extra = []) use ($conn, $ctx, $def, $person, &$ticketIds) {
        $id = TicketsService::createTicket($conn, $ctx, $def, ['subject' => 'ZZ record locations', 'description' => 'ZZ', 'user_id' => $person] + $extra, null, 'ZZ test');
        return $ticketIds[] = (int)$id;
    };
    $locOf = function (string $table, int $id) use ($conn) {
        $s = $conn->prepare("SELECT location_id FROM `$table` WHERE id = ?"); $s->execute([$id]);
        $v = $s->fetchColumn(); return $v === null || $v === false ? null : (int)$v;
    };

    echo "\nA new ticket\n";
    $setSetting(null);
    ok("takes its requester's location (setting never saved = on)", $locOf('tickets', $mkTicket()) === $locDef);
    $setSetting('0');
    ok('takes none when the setting is off', $locOf('tickets', $mkTicket()) === null);
    $setSetting('1');
    ok('a location chosen by the caller wins', $locOf('tickets', $mkTicket(['location_id' => $locShared])) === $locShared);
    ok("another company's location is refused", throws(fn() => $mkTicket(['location_id' => $locZ])));

    echo "\nEditing a ticket\n";
    $t = $ticketIds[0];
    TicketsService::updateTicket($conn, $ctx, $t, ['location_id' => $locShared], true);
    ok('its location can be changed', $locOf('tickets', $t) === $locShared);
    $a = $conn->prepare("SELECT old_value, new_value FROM ticket_audit WHERE ticket_id = ? AND field_name = 'Location' ORDER BY id DESC LIMIT 1");
    $a->execute([$t]); $row = $a->fetch(PDO::FETCH_ASSOC);
    ok('the change is in its history', $row && $row['old_value'] === 'ZZ Loc Default' && $row['new_value'] === 'ZZ Loc Shared', json_encode($row));
    TicketsService::updateTicket($conn, $ctx, $t, ['location_id' => $locDef], true);
    TicketsService::updateTicket($conn, $ctx, $t, ['company_id' => $tenantZ], true);
    ok('moving it to a company that cannot use its location drops it', $locOf('tickets', $t) === null);

    echo "\nContracts and projects\n";
    $cr = ContractsService::saveContract($conn, $ctx, ['contract_number' => 'ZZ-RECLOC-1', 'title' => 'ZZ record locations', 'tenant_id' => $def, 'location_id' => $locDef]);
    $contractIds[] = (int)$cr['id'];
    ok('a contract takes a location', $locOf('contracts', (int)$cr['id']) === $locDef);
    ok("a contract refuses another company's location", throws(fn() => ContractsService::saveContract($conn, $ctx, ['id' => $cr['id'], 'location_id' => $locZ])));
    $pr = ProjectsService::saveProject($conn, $ctx, ['name' => 'ZZ record locations', 'location_id' => $locDef], $def);
    $projectIds[] = (int)$pr['id'];
    ok('a project takes a location', $locOf('projects', (int)$pr['id']) === $locDef);
    ok("a project refuses another company's location", throws(fn() => ProjectsService::updateProject($conn, $ctx, (int)$pr['id'], ['location_id' => $locZ])));

    echo "\nDeleting a location in use\n";
    $usage = recordLocationUsage($conn, $locDef);
    ok('its usage is counted', ($usage['people'] ?? 0) === 1 && ($usage['contracts'] ?? 0) === 1 && ($usage['projects'] ?? 0) === 1, json_encode($usage));
    // The real endpoint over HTTP, as an administrator (a forged session, as
    // tests/accounts-http.php does). Skipped without FREEITSM_TEST_URL.
    $base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
    $raw = '';
    if ($base === '') {
        echo "  SKIP  the delete endpoint (set FREEITSM_TEST_URL)\n";
    } else {
        $sessDir = rtrim((string)(getenv('FREEITSM_SESS_DIR') ?: 'C:/wamp64/tmp'), '/');
        $sid = 'zzrecloc' . bin2hex(random_bytes(4));
        file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_username|s:5:"admin";analyst_name|s:5:"Admin";csrf_token|s:1:"x";active_tenant_id|i:' . $def . ';');
        try {
            $ch = curl_init("$base/api/assets/delete_asset_location.php");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['id' => $locDef]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: x', 'Cookie: PHPSESSID=' . $sid, 'Sec-Fetch-Site: same-origin']]);
            $raw = (string)curl_exec($ch);
            curl_close($ch);
        } finally {
            @unlink("$sessDir/sess_$sid");
        }
    }
    $d = json_decode(trim($raw), true);
    if ($base !== '') ok('deleting it is refused', is_array($d) && empty($d['success']) && !empty($d['in_use']), substr($raw, 0, 200));
    if ($base !== '') ok('the refusal points at the tool', is_array($d) && str_contains((string)($d['tool'] ?? ''), 'locations-in-use'));
    ok('the location is still there', recordLocationPath($conn, $locDef) !== null);

    echo "\nLocations in use: moving\n";
    // The ZZ-company ticket's location is none; give the ZZ company a record on locDef? It cannot hold one,
    // so move to the ZZ company's own location: Default-company records must be skipped.
    $res = recordLocationMove($conn, $admin, $locDef, $locZ);
    ok("a record the new location is not open to is skipped", array_sum($res['skipped']) === 3 && array_sum($res['moved']) === 0, json_encode($res));
    $res = recordLocationMove($conn, $admin, $locDef, $locShared);
    ok('moving to a shared location moves them all', array_sum($res['moved']) === 3, json_encode($res));
    ok('nothing points at it now', array_sum(recordLocationUsage($conn, $locDef)) === 0);
    $res = recordLocationMove($conn, $admin, $locShared, null);
    ok('clearing leaves them with none', $locOf('users', $person) === null && array_sum(recordLocationUsage($conn, $locShared)) === 0, json_encode($res));

    echo "\nEvery route that makes a ticket\n";
    $root = dirname(__DIR__);
    foreach (['includes/services/tickets.php', 'api/self-service/create_ticket.php', 'api/tickets/check_mailbox_email.php',
              'includes/catalogue_approvals.php', 'workflow/includes/engine.php', 'includes/messaging/ingest.php',
              'includes/webchat/webchat.php', 'includes/ticket_merge.php', 'includes/ticket_split.php'] as $f) {
        $src = (string)@file_get_contents("$root/$f");
        ok("$f gives a new ticket a location", str_contains($src, 'ticketApplyRequesterLocation('));
    }
    // A route added later without it: every INSERT INTO tickets must sit in a file that calls it.
    $missing = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $p = str_replace('\\', '/', (string)$file);
        if (!str_ends_with($p, '.php') || preg_match('#/(tests|vendor|database|scripts|docs|node_modules|\.git)/#', $p)) continue;
        $src = (string)@file_get_contents($p);
        if (preg_match('/INSERT\s+INTO\s+tickets\s*[\(\n]/i', $src) && !str_contains($src, 'ticketApplyRequesterLocation(')) $missing[] = substr($p, strlen(str_replace('\\', '/', $root)) + 1);
    }
    ok('no other file makes a ticket without it', !$missing, implode(', ', $missing));
} finally {
    if ($ticketIds) {
        $in = implode(',', array_map('intval', $ticketIds));
        foreach (['emails', 'ticket_audit', 'ticket_field_values', 'ticket_watchers'] as $tb) { try { $conn->exec("DELETE FROM $tb WHERE ticket_id IN ($in)"); } catch (Throwable $e) {} }
        $conn->exec("DELETE FROM tickets WHERE id IN ($in)");
    }
    foreach ($projectIds as $pid) { try { $conn->prepare("DELETE FROM projects WHERE id = ?")->execute([$pid]); } catch (Throwable $e) { echo "  note: project $pid: " . $e->getMessage() . "\n"; } }
    foreach ($contractIds as $cid) { try { $conn->prepare("DELETE FROM contracts WHERE id = ?")->execute([$cid]); } catch (Throwable $e) { echo "  note: contract $cid: " . $e->getMessage() . "\n"; } }
    if ($userIds) $conn->exec("DELETE FROM users WHERE id IN (" . implode(',', array_map('intval', $userIds)) . ")");
    foreach (array_reverse($locs) as $l) $conn->prepare("DELETE FROM asset_locations WHERE id = ?")->execute([$l]);
    if ($tenantZ) $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tenantZ]);
    $conn->exec("DELETE FROM ticket_number_counters");
    $ins = null;
    foreach ($counters as $c) {
        $cols = array_keys($c);
        $ins ??= $conn->prepare("INSERT INTO ticket_number_counters (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
        $ins->execute(array_values($c));
    }
    $setSetting($settingWas === false ? null : (string)$settingWas);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM tenants WHERE name = 'ZZ record locations test')
        + (SELECT COUNT(*) FROM asset_locations WHERE name LIKE 'ZZ Loc %')
        + (SELECT COUNT(*) FROM users WHERE email = 'zz-recloc@example.invalid')
        + (SELECT COUNT(*) FROM tickets WHERE subject = 'ZZ record locations')
        + (SELECT COUNT(*) FROM contracts WHERE contract_number = 'ZZ-RECLOC-1')
        + (SELECT COUNT(*) FROM projects WHERE name = 'ZZ record locations')")->fetchColumn();
    echo "\n";
    ok('everything the test wrote is gone', $left === 0, "$left rows left");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
