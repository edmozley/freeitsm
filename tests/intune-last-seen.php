<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * An asset's Last seen follows its Intune device's last check-in (GitHub #436).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/intune-last-seen.php
 *
 * The bug: last_seen was written only when the Intune sync CREATED the asset, so a
 * linked device that kept checking in left its asset "last seen" on the day it was
 * first synced. intuneRefreshLastSeen() now runs on every sync (the last step of
 * intuneLinkDevicesToAssets()). Pins: a stale date moves up (the bug), an empty one is
 * filled, a NEWER agent report is never pulled back, two devices give the later
 * check-in, a check-in in the future is ignored, an unlinked device changes nothing.
 * Everything runs in a transaction that is rolled back.
 *
 * @wiki Bugs-Resolved
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/intune.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nIntune: Last seen follows the device's last check-in\n" . str_repeat('=', 70) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes assets and Intune devices (and rolls them back).\n");
}
try { $conn->query("SELECT 1 FROM intune_devices LIMIT 0"); }
catch (PDOException $e) { echo "  SKIP  run Database Verification first: no intune_devices table\n"; exit(0); }

$at = fn(string $mod) => gmdate('Y-m-d H:i:s', strtotime($mod));
$conn->beginTransaction();
try {
    $asset = function (string $host, ?string $lastSeen) use ($conn): int {
        $conn->prepare("INSERT INTO assets (hostname, last_seen) VALUES (?, ?)")->execute([$host, $lastSeen]);
        return (int)$conn->lastInsertId();
    };
    $device = fn(?int $asset, ?string $checkedIn) => $conn->prepare("INSERT INTO intune_devices (intune_id, asset_id, device_name, last_sync_datetime) VALUES (?, ?, ?, ?)")
        ->execute(['zz-ls-' . bin2hex(random_bytes(8)), $asset, 'ZZ-LS-DEV', $checkedIn]);
    $seen = function (int $id) use ($conn): ?string { $s = $conn->prepare("SELECT last_seen FROM assets WHERE id = ?"); $s->execute([$id]); return $s->fetchColumn() ?: null; };

    $stale   = $asset('ZZ-LS-STALE', $at('-10 days'));   $device($stale, $at('-1 hour'));
    $never   = $asset('ZZ-LS-NEVER', null);              $device($never, $at('-1 day'));
    $agent   = $asset('ZZ-LS-AGENT', $at('-5 minutes')); $device($agent, $at('-2 days'));
    $two     = $asset('ZZ-LS-TWO', $at('-20 days'));     $device($two, $at('-3 days')); $device($two, $at('-2 hours'));
    $future  = $asset('ZZ-LS-FUTURE', $at('-4 days'));   $device($future, $at('+30 days'));
    $nocheck = $asset('ZZ-LS-NOCHECK', $at('-6 days'));  $device($nocheck, null);
    $loose   = $asset('ZZ-LS-LOOSE', $at('-8 days'));    $device(null, $at('-1 hour'));   // a device linked to nothing
    $agentWas = $seen($agent);

    $n = intuneRefreshLastSeen($conn);
    ok('POSITIVE CONTROL: a stale Last seen moves up to the last check-in (the bug)', $seen($stale) === $at('-1 hour') || abs(strtotime($seen($stale)) - strtotime($at('-1 hour'))) < 5, (string)$seen($stale));
    ok('an asset with no Last seen gets one', $seen($never) !== null && abs(strtotime($seen($never)) - strtotime($at('-1 day'))) < 5, (string)$seen($never));
    ok('a newer report from the inventory agent is never pulled back to an older check-in', $seen($agent) === $agentWas, (string)$seen($agent));
    ok('two devices on one asset: the later check-in wins', abs(strtotime((string)$seen($two)) - strtotime($at('-2 hours'))) < 5, (string)$seen($two));
    ok('a check-in in the future (a wrong clock) is ignored', abs(strtotime((string)$seen($future)) - strtotime($at('-4 days'))) < 5, (string)$seen($future));
    ok('a device that never checked in changes nothing', abs(strtotime((string)$seen($nocheck)) - strtotime($at('-6 days'))) < 5);
    ok('a device linked to no asset changes nothing', abs(strtotime((string)$seen($loose)) - strtotime($at('-8 days'))) < 5);
    ok('it reports how many assets it moved (at least the three here)', $n >= 3, (string)$n);
    ok('a second run moves nothing more on these', (function () use ($conn, $seen, $stale) { $before = $seen($stale); intuneRefreshLastSeen($conn); return $seen($stale) === $before; })());

    // It is part of every sync: intuneLinkDevicesToAssets() runs it as its last step.
    $conn->prepare("UPDATE assets SET last_seen = ? WHERE id = ?")->execute([$at('-10 days'), $stale]);
    $unlinked = (int)$conn->query("SELECT COUNT(*) FROM intune_devices WHERE asset_id IS NULL AND device_name IS NOT NULL AND device_name <> '' AND intune_id NOT LIKE 'zz-ls-%'")->fetchColumn();
    if ($unlinked === 0) {
        $conn->exec("UPDATE intune_devices SET device_name = NULL WHERE intune_id LIKE 'zz-ls-%' AND asset_id IS NULL");   // our loose one is not for linking
        $r = intuneLinkDevicesToAssets($conn);
        ok('the sync\'s linking step refreshes Last seen too', abs(strtotime((string)$seen($stale)) - strtotime($at('-1 hour'))) < 5 && ($r['last_seen_updated'] ?? 0) >= 1, json_encode($r));
    } else {
        echo "  SKIP  the full linking step: this database has $unlinked unlinked Intune devices it would link\n";
    }
} catch (Throwable $e) {
    ok('no unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}
ok('rolled back', (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZ-LS-%'")->fetchColumn() === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
