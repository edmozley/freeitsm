<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Intune: every primary user a device has had, assets that follow it, and the
 * assets whose Intune user differs (GH #335).
 *
 * Through the real intuneUpsertDevice() (which records), intuneFollowPrimaryUsers(),
 * intuneResolveUserHistory(), intuneAssetsWithDifferentUser() and
 * AssetsService. Pinned, because each one would move somebody's laptop to the
 * wrong person or lose who had it:
 *
 *   - a device's first user starts its history; the same user again adds nothing;
 *     a change adds a row with who it was and who it is, matched by object id;
 *   - with "follow" off nothing moves, and the row says why;
 *   - with it on, a holder Intune put there moves - as a custody check-in and
 *     check-out - and so does one from before the source column (by its note);
 *   - a holder an analyst chose never moves; nor to a leaver, nor to somebody
 *     nobody imported;
 *   - the disagreement is counted, and stops being one once settled.
 *
 * Runs in a transaction that is ALWAYS rolled back; asset workflows are paused
 * for the run and switched back on in `finally`.
 *
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/intune-user-history.php
 *
 * @wiki Asset-Reconciliation-Developer-Guide
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/intune.php';
require_once __DIR__ . '/../includes/services/assets.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nIntune: primary user history and follow\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes devices, assets and assignments, and pauses asset workflows.\n");
}
if (!intuneUserHistoryReady($conn)) { echo "  SKIP  run Database Verification first: no intune_device_user_history table\n"; exit(0); }

$paused = array_map('intval', $conn->query("SELECT id FROM workflows WHERE is_active = 1 AND trigger_event IN ('asset.assigned', 'asset.unassigned')")->fetchAll(PDO::FETCH_COLUMN));
if ($paused) $conn->exec("UPDATE workflows SET is_active = 0 WHERE id IN (" . implode(',', $paused) . ")");

$conn->beginTransaction();
try {
    $conn->prepare("INSERT INTO auth_providers (protocol, display_name, issuer_url, client_id, enabled)
                    VALUES ('oidc', 'ZZ Intune history Entra', 'https://login.microsoftonline.com/zz-hist/v2.0', 'zz-hist', 1)")->execute();
    $pid = (int)$conn->lastInsertId();
    $oid = fn() => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
    $person = function (string $name, int $active = 1) use ($conn, $pid, $oid): array {
        $conn->prepare("INSERT INTO users (email, display_name, is_active, is_managed, auth_provider_id) VALUES (?, ?, ?, 1, ?)")
             ->execute([strtolower(str_replace(' ', '.', $name)) . '.uhtest@example.invalid', $name, $active, $pid]);
        $id = (int)$conn->lastInsertId();
        $o = $oid();
        $conn->prepare("INSERT INTO user_sso_identities (user_id, provider_id, subject, source_ref) VALUES (?, ?, ?, ?)")
             ->execute([$id, $pid, 'entra:' . $o, $o]);
        return [$id, $o];
    };
    // A device as the sync writes it, linked to an asset as the link step would.
    $seen = function (string $intuneId, ?string $userOid, ?int $asset = null) use ($conn): void {
        intuneUpsertDevice($conn, ['id' => $intuneId, 'deviceName' => 'ZZ-UH-DEV', 'userId' => $userOid,
                                   'userPrincipalName' => $userOid ? "$userOid@example.invalid" : null, 'userDisplayName' => null]);
        if ($asset) $conn->prepare("UPDATE intune_devices SET asset_id = ? WHERE intune_id = ?")->execute([$asset, $intuneId]);
    };
    $asset = function (string $host) use ($conn): int {
        $conn->prepare("INSERT INTO assets (hostname) VALUES (?)")->execute([$host]);
        return (int)$conn->lastInsertId();
    };
    $history = function (string $intuneId) use ($conn): array {
        $s = $conn->prepare("SELECT h.* FROM intune_device_user_history h JOIN intune_devices d ON d.id = h.intune_device_id
                              WHERE d.intune_id = ? ORDER BY h.id");
        $s->execute([$intuneId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    };
    $holders = function (int $asset) use ($conn): array {
        $s = $conn->prepare("SELECT user_id FROM users_assets WHERE asset_id = ? ORDER BY user_id"); $s->execute([$asset]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    };
    $setting = function (string $key, string $v) use ($conn): void {
        $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $v]);
    };

    [$ana, $anaOid]   = $person('UH Ana');
    [$ben, $benOid]   = $person('UH Ben');
    [$cara, $caraOid] = $person('UH Cara');
    [$gone, $goneOid] = $person('UH Leaver', 0);

    echo "Recording\n";
    $d1 = 'zz-uh-' . bin2hex(random_bytes(6));
    $a1 = $asset('ZZ-UH-ONE');
    $seen($d1, $anaOid, $a1);
    $h = $history($d1);
    ok('a device\'s first user starts its history', count($h) === 1 && $h[0]['outcome'] === 'first' && $h[0]['old_user_id'] === null
        && (int)$h[0]['new_person_id'] === $ana, json_encode($h));
    $seen($d1, strtoupper($anaOid));
    ok('the same user again - in another case - adds nothing', count($history($d1)) === 1);

    // Intune gives the asset to Ana, as the sync would.
    $r = intuneAssignPrimaryUsers($conn);
    ok('POSITIVE CONTROL: the unheld asset goes to Ana', $holders($a1) === [$ana], json_encode($r));
    $src = $conn->prepare("SELECT assigned_source FROM users_assets WHERE asset_id = ?");
    $src->execute([$a1]);
    ok('...and the assignment says Intune made it', $src->fetchColumn() === 'intune');

    $seen($d1, $benOid);
    $h = $history($d1);
    ok('a change adds a row: who it was, who it is, each matched by object id', count($h) === 2
        && (int)$h[1]['old_person_id'] === $ana && (int)$h[1]['new_person_id'] === $ben && $h[1]['outcome'] === null, json_encode($h[1] ?? null));

    echo "Follow off\n";
    intuneResolveUserHistory($conn, false);
    ok('nothing moves, and the row says follow is off', $holders($a1) === [$ana] && $history($d1)[1]['outcome'] === 'follow_off', $history($d1)[1]['outcome']);
    ok('the asset is counted as differing', intuneCountAssetsWithDifferentUser($conn) >= 1
        && in_array($a1, array_column(intuneAssetsWithDifferentUser($conn), 'asset_id'), true));
    $info = intuneAssetUserInfo($conn, $a1);
    ok('its Intune tab says so, naming Ben', $info['differs'] === true && ($info['intune_person']['id'] ?? null) === $ben && count($info['history']) === 2, json_encode($info['intune_person']));

    echo "Follow on\n";
    $seen($d1, $caraOid);
    $m = intuneFollowPrimaryUsers($conn);
    intuneResolveUserHistory($conn, true);
    ok('a holder Intune put there moves to the new primary user', $holders($a1) === [$cara] && $m['moved'] >= 1 && $m['failed'] === 0, json_encode($m));
    $h = $history($d1);
    ok('...and the row says the asset is theirs', $h[count($h) - 1]['outcome'] === 'holder', $h[count($h) - 1]['outcome']);
    $log = $conn->prepare("SELECT action, user_id FROM asset_checkout_log WHERE asset_id = ? ORDER BY id DESC LIMIT 2");
    $log->execute([$a1]);
    $l = $log->fetchAll(PDO::FETCH_ASSOC);
    ok('...as a custody check-in of Ana and check-out to Cara', count($l) === 2 && $l[0]['action'] === 'checkout' && (int)$l[0]['user_id'] === $cara
        && $l[1]['action'] === 'checkin' && (int)$l[1]['user_id'] === $ana, json_encode($l));
    ok('...which settles the disagreement', !in_array($a1, array_column(intuneAssetsWithDifferentUser($conn), 'asset_id'), true));

    // An analyst's choice.
    $analyst = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $d2 = 'zz-uh-' . bin2hex(random_bytes(6));
    $a2 = $asset('ZZ-UH-ANALYST');
    $seen($d2, $anaOid, $a2);
    AssetsService::assignUser($conn, new ActorContext($analyst, null, 'ui'), $a2, ['user_id' => $ana]);
    $seen($d2, $benOid);
    $m = intuneFollowPrimaryUsers($conn);
    intuneResolveUserHistory($conn, true);
    $h2 = $history($d2);
    ok('a holder an analyst chose never moves, and the row says why', $holders($a2) === [$ana] && $h2[count($h2) - 1]['outcome'] === 'analyst_holder', $h2[count($h2) - 1]['outcome']);
    ok('...and it stays on the list for a person to decide', in_array($a2, array_column(intuneAssetsWithDifferentUser($conn), 'asset_id'), true));
    // A second device on the same asset naming somebody else: still one row, as counted.
    $seen('zz-uh-' . bin2hex(random_bytes(6)), $caraOid, $a2);
    $list = intuneAssetsWithDifferentUser($conn);
    ok('an asset with two devices is one row on the list, and the count agrees', count(array_keys(array_column($list, 'asset_id'), $a2)) === 1
        && count($list) === intuneCountAssetsWithDifferentUser($conn), count($list) . ' vs ' . intuneCountAssetsWithDifferentUser($conn));

    // From before the column: the note, and no analyst.
    $d3 = 'zz-uh-' . bin2hex(random_bytes(6));
    $a3 = $asset('ZZ-UH-LEGACY');
    $seen($d3, $anaOid, $a3);
    $conn->prepare("INSERT INTO users_assets (asset_id, user_id, notes, assigned_source) VALUES (?, ?, ?, NULL)")
         ->execute([$a3, $ana, AssetAssignmentSource::INTUNE_NOTE]);
    $seen($d3, $benOid);
    intuneFollowPrimaryUsers($conn);
    ok('an Intune assignment from before the column moves too (by its note)', $holders($a3) === [$ben]);

    // Database Verification fills the column in for rows from before it: only the
    // ones Intune made (its note, no analyst), never somebody else's.
    $aOld = $asset('ZZ-UH-OLD');
    $conn->prepare("INSERT INTO users_assets (asset_id, user_id, notes, assigned_source) VALUES (?, ?, ?, NULL), (?, ?, 'Given by hand', NULL)")
         ->execute([$aOld, $ana, AssetAssignmentSource::INTUNE_NOTE, $aOld, $ben]);
    assetAssignmentSourceBackfill($conn);
    $s = $conn->prepare("SELECT user_id, assigned_source FROM users_assets WHERE asset_id = ? ORDER BY user_id");
    $s->execute([$aOld]);
    $rows = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    ok('Database Verification marks an old Intune assignment as made by Intune, and leaves a hand-made one alone',
        ($rows[$ana] ?? null) === 'intune' && array_key_exists($ben, $rows) && $rows[$ben] === null, json_encode($rows));

    // Never to a leaver, nor to somebody nobody imported.
    $d4 = 'zz-uh-' . bin2hex(random_bytes(6));
    $a4 = $asset('ZZ-UH-LEAVER');
    $seen($d4, $anaOid, $a4);
    intuneAssignPrimaryUsers($conn);
    $seen($d4, $goneOid);
    intuneFollowPrimaryUsers($conn);
    intuneResolveUserHistory($conn, true);
    $h4 = $history($d4);
    ok('never to a leaver: it stays, and the row says a rule stopped it', $holders($a4) === [$ana] && $h4[count($h4) - 1]['outcome'] === 'skipped', $h4[count($h4) - 1]['outcome']);
    $seen($d4, $oid());
    intuneFollowPrimaryUsers($conn);
    intuneResolveUserHistory($conn, true);
    $h4 = $history($d4);
    ok('nor to somebody nobody imported', $holders($a4) === [$ana] && $h4[count($h4) - 1]['outcome'] === 'not_imported', $h4[count($h4) - 1]['outcome']);

    echo "After an Entra import\n";
    $d5 = 'zz-uh-' . bin2hex(random_bytes(6));
    $a5 = $asset('ZZ-UH-ENTRA');
    $seen($d5, $anaOid, $a5);
    $setting('intune_assign_primary_user', '1');
    $setting('intune_follow_primary_user', '0');
    intuneAssignAfterDirectoryImport($conn);
    $seen($d5, $benOid);
    $r = intuneAssignAfterDirectoryImport($conn);
    ok('with follow off, an import only fills gaps', $holders($a5) === [$ana] && ($r['moved'] ?? null) === 0, json_encode($r));
    $setting('intune_follow_primary_user', '1');
    $r = intuneAssignAfterDirectoryImport($conn);
    ok('with it on, an import follows the change too', $holders($a5) === [$ben] && ($r['moved'] ?? 0) >= 1, json_encode($r));

    echo "Where an assignment came from\n";
    // A request body reaches AssetsService as it is, so a string must never name the source.
    $a6 = $asset('ZZ-UH-SOURCE');
    AssetsService::assignUser($conn, new ActorContext($analyst, null, 'ui'), $a6, ['user_id' => $ana, 'source' => 'intune']);
    AssetsService::assignUser($conn, new ActorContext($analyst, null, 'api'), $a6, ['user_id' => $ben, 'source' => 'intune']);
    $s = $conn->prepare("SELECT user_id, assigned_source FROM users_assets WHERE asset_id = ?");
    $s->execute([$a6]);
    $rows = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    ok('a request saying "source":"intune" is recorded as the analyst\'s, not Intune\'s', ($rows[$ana] ?? null) === 'analyst', json_encode($rows));
    ok('...and an API one as the API\'s', ($rows[$ben] ?? null) === 'api', json_encode($rows));
    ok('only the enum names a source', AssetAssignmentSource::resolve(AssetAssignmentSource::Intune, new ActorContext($analyst, null, 'ui')) === AssetAssignmentSource::Intune
        && AssetAssignmentSource::resolve('intune', new ActorContext($analyst, null, 'ui')) === AssetAssignmentSource::Analyst);

    // Before Database Verification adds users_assets.assigned_source the follow query
    // cannot run. Dropping a column mid-test is not something to do even to a
    // throwaway database, so this pins the guard in the source instead: it must come
    // before the query.
    $rf = new ReflectionFunction('intuneFollowPrimaryUsers');
    $body = implode('', array_slice(file($rf->getFileName()), $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1));
    $guard = strpos($body, "tenancyColumnExists(\$conn, 'users_assets', 'assigned_source')) return ['moved' => 0, 'failed' => 0]");
    ok('follow does nothing before Database Verification adds assigned_source (guard before the query)',
        $guard !== false && $guard < strpos($body, '$conn->prepare('));
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 1 WHERE id IN (" . implode(',', $paused) . ")");
}

// The asset's Intune tab (api/intune/get_intune_device.php) checks the asset's
// company like get_asset_users.php beside it. Over HTTP, so the rows are committed
// - ZZ-tagged and removed by id in finally.
echo "The Intune tab and companies\n";
$base = getenv('FREEITSM_BASE_URL');
if (!$base) {
    echo "  SKIP  set FREEITSM_BASE_URL to the throwaway install's URL for the HTTP checks\n";
} else {
    $tag = 'zzuh' . bin2hex(random_bytes(3));
    $made = ['tenant' => 0, 'asset' => 0, 'device' => 0, 'analyst' => 0];
    $sessions = [];
    $call = function (int $analystId, int $isAdmin, int $assetId) use ($base, &$sessions): array {
        $sid = 'zzuh' . bin2hex(random_bytes(6));
        $file = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\") . '/sess_' . $sid;
        file_put_contents($file, 'analyst_id|i:' . $analystId . ';analyst_name|s:2:"ZZ";is_admin|i:' . $isAdmin . ';csrf_token|s:4:"zzzz";');
        $sessions[] = $file;
        $ch = curl_init(rtrim($base, '/') . '/api/intune/get_intune_device.php?asset_id=' . $assetId);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_TIMEOUT => 20]);
        $raw = (string)curl_exec($ch); curl_close($ch);
        return json_decode($raw, true) ?? ['raw' => substr($raw, 0, 200)];
    };
    try {
        $conn->prepare("INSERT INTO tenants (name) VALUES (?)")->execute(["$tag company"]);
        $made['tenant'] = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO assets (hostname, tenant_id) VALUES (?, ?)")->execute(["ZZ-UH-$tag", $made['tenant']]);
        $made['asset'] = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO intune_devices (intune_id, asset_id, device_name) VALUES (?, ?, ?)")->execute(["$tag-dev", $made['asset'], "ZZ-UH-$tag"]);
        $made['device'] = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants)
                        VALUES (?, '!', 'ZZ UH elsewhere', ?, 1, 0, 1, 0)")->execute([$tag, "$tag@example.invalid"]);
        $made['analyst'] = (int)$conn->lastInsertId();
        $admin = (int)$conn->query('SELECT MIN(id) FROM analysts WHERE is_admin = 1 AND is_active = 1')->fetchColumn();

        $r = $call($admin, 1, $made['asset']);
        ok('POSITIVE CONTROL: an administrator sees the device', ($r['success'] ?? false) === true && ($r['device']['intune_id'] ?? null) === "$tag-dev", json_encode($r));
        $r = $call($made['analyst'], 0, $made['asset']);
        ok('an analyst outside the asset\'s company is told it is not found', ($r['success'] ?? true) === false && empty($r['device']), json_encode($r));
    } catch (Throwable $e) {
        ok('the HTTP checks completed', false, get_class($e) . ': ' . $e->getMessage());
    } finally {
        foreach ($sessions as $f) @unlink($f);
        if ($made['device'])  $conn->prepare("DELETE FROM intune_devices WHERE id = ?")->execute([$made['device']]);
        if ($made['asset'])   { $conn->prepare("DELETE FROM asset_history WHERE asset_id = ?")->execute([$made['asset']]); $conn->prepare("DELETE FROM assets WHERE id = ?")->execute([$made['asset']]); }
        if ($made['analyst']) $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$made['analyst']]);
        if ($made['tenant'])  $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$made['tenant']]);
    }
}

echo "\n" . str_repeat('=', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
