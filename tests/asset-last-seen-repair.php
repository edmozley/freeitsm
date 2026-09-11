<?php
/**
 * The hand-added-asset last_seen repair (#1583/#1591/#1592).
 *
 * Until #1583, creating an asset by hand stamped last_seen as well as
 * first_seen, as though something had reported it. Once #1578 put last_seen on
 * screen, every television and SIM card in every install started reading
 * "21 days ago" in amber, and had been inflating the Watchtower "not seen"
 * count all along. db_verify.php repairs them on upgrade.
 *
 * WHAT THIS PINS DOWN:
 *   - the repair fires on a hand-added asset that never reported
 *   - 🔴 it does NOT touch an asset an agent has reported, ever
 *   - the date is not destroyed, only de-duplicated: first_seen keeps it
 *   - it is idempotent, so it needs no run-once flag
 *   - the preview COUNTS the same rows the repair UPDATEs — the two are
 *     maintained by hand in different files and will drift the first time
 *     somebody edits one of them
 *
 * ⚠️ Touches the database. Everything it makes is prefixed ZZLS and removed in
 * the cleanup at the bottom, including on failure.
 *
 * Run:  php tests/asset-last-seen-repair.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_verify_preview.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nThe hand-added-asset last_seen repair\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$made = [];

/** The repair exactly as db_verify.php runs it. */
function runRepair(PDO $conn): int {
    return (int)$conn->exec(
        "UPDATE assets a
            SET a.last_seen = NULL
          WHERE a.last_seen IS NOT NULL
            AND a.first_seen <=> a.last_seen
            AND EXISTS (SELECT 1 FROM asset_history h
                         WHERE h.asset_id = a.id AND h.field_name = 'asset_created')"
    );
}

try {
    $stamp = '2026-01-05 09:00:00';
    // asset_history.analyst_id is NOT NULL — the audit records who did it, and
    // a fixture that skips that is not the row the application writes.
    $analystId = (int)$conn->query("SELECT id FROM analysts ORDER BY id LIMIT 1")->fetchColumn();

    $mk = function (string $host, ?string $first, ?string $last, bool $audited) use ($conn, &$made, $analystId): int {
        $conn->prepare("INSERT INTO assets (hostname, first_seen, last_seen) VALUES (?, ?, ?)")
             ->execute([$host, $first, $last]);
        $id = (int)$conn->lastInsertId();
        $made[] = $id;
        if ($audited) {
            $conn->prepare("INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
                            VALUES (?, ?, 'asset_created', NULL, 'ZZLS fixture', UTC_TIMESTAMP())")->execute([$id, $analystId]);
        }
        return $id;
    };

    // A television: created by hand, audited, never reported since.
    $tv = $mk('ZZLS-TV-01', $stamp, $stamp, true);
    // 🔴 A laptop created by hand and LATER picked up by the agent. Audited too,
    // so the audit row ALONE would wrongly clear it — first_seen != last_seen is
    // what saves it, and this is the case that makes that guard load-bearing.
    $adopted = $mk('ZZLS-LAPTOP-ADOPTED', $stamp, '2026-03-01 12:00:00', true);
    // A pure agent asset: never created by hand, so no audit row.
    $agent = $mk('ZZLS-AGENT-01', $stamp, $stamp, false);
    // Already correct — the shape a manual asset takes after #1583.
    $modern = $mk('ZZLS-TV-02', $stamp, null, true);

    // ---- The preview must count exactly what the repair will change --------
    $repairs = dbPreviewRepairs($conn, DB_NAME);
    $previewed = 0;
    foreach ($repairs as $r) {
        if ($r['key'] === 'assets_last_seen_manual') $previewed = $r['rows'];
    }
    ok('the preview reports the repair at all', $previewed > 0, json_encode($repairs));

    $changed = runRepair($conn);
    ok('🔴 the preview COUNTED exactly what the repair CHANGED',
       $previewed === $changed,
       "preview said {$previewed}, repair changed {$changed}");

    $get = function (int $id) use ($conn): array {
        $s = $conn->prepare("SELECT first_seen, last_seen FROM assets WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    $row = $get($tv);
    ok('a hand-added asset that never reported is cleared', $row['last_seen'] === null, json_encode($row));
    ok('🔑 its date is NOT destroyed - first_seen still holds it',
       $row['first_seen'] === $stamp, json_encode($row));

    $row = $get($adopted);
    ok('🔴 an asset the AGENT has reported is left alone',
       $row['last_seen'] === '2026-03-01 12:00:00', json_encode($row));

    $row = $get($agent);
    ok('🔴 an agent-created asset is left alone (no audit row)',
       $row['last_seen'] === $stamp, json_encode($row));

    $row = $get($modern);
    ok('an already-correct asset is untouched', $row['last_seen'] === null, json_encode($row));

    // ---- Idempotent, so it needs no run-once flag --------------------------
    $again = runRepair($conn);
    ok('running it a second time changes nothing', $again === 0, "changed {$again}");

    $repairs = dbPreviewRepairs($conn, DB_NAME);
    $after = 0;
    foreach ($repairs as $r) {
        if ($r['key'] === 'assets_last_seen_manual') $after = $r['rows'];
    }
    ok('and the preview then reports nothing left to do', $after === 0, "preview says {$after}");

} finally {
    foreach ($made as $id) {
        try { $conn->prepare("DELETE FROM asset_history WHERE asset_id = ?")->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM assets WHERE id = ? AND hostname LIKE 'ZZLS-%'")->execute([$id]); } catch (Throwable $e) {}
    }
    $left = (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZLS-%'")->fetchColumn();
    echo "  cleanup: {$left} ZZLS assets left\n";
}

echo str_repeat('-', 70) . "\n";
echo "  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
