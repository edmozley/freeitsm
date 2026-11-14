<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Intune: an asset with no type gets the one its device's kind is mapped to (GH #336).
 *
 * Part 1, no database: IntuneDeviceKind::fromDevice() - every chassisType Graph
 * sends, and the operating-system fallback only where it is unambiguous.
 *
 * Part 2, through the real intuneUpsertDevice() and intuneApplyAssetTypes():
 *   - an untyped asset gets the mapped type, recorded in its history;
 *   - a type somebody chose is never changed;
 *   - a kind nothing is mapped to, an unknown computer, and an asset whose
 *     devices disagree get nothing;
 *   - a device synced before the column existed is read from raw_json;
 *   - nothing at all while nothing is mapped.
 *
 * Part 2 runs in a transaction that is ALWAYS rolled back.
 *
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/intune-asset-type.php
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

echo "\nIntune: asset type from the device\n" . str_repeat('=', 70) . "\n";
echo "Part 1 - the kind of device\n";
$k = fn($c, $os = null) => ($x = IntuneDeviceKind::fromDevice($c, $os)) ? $x->value : null;
ok('laptop', $k('laptop') === 'laptop');
ok('desktop, and a workstation is a desktop', $k('desktop') === 'desktop' && $k('worksWorkstation') === 'desktop');
ok('server', $k('enterpriseServer') === 'server');
ok('phone and tablet', $k('phone') === 'phone' && $k('tablet') === 'tablet');
ok('other mobile, both ways Graph says it', $k('mobileOther') === 'other_mobile' && $k('mobileUnknown') === 'other_mobile');
ok('unknown iOS, iPadOS or Android is a mobile device', $k('unknown', 'iOS') === 'other_mobile' && $k(null, 'iPadOS') === 'other_mobile' && $k('unknown', 'Android') === 'other_mobile');
ok('an unknown computer is not guessed', $k('unknown', 'Windows') === null && $k(null, 'macOS') === null && $k(null, null) === null);

echo "Part 2 - the assets\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    echo "  SKIP  set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this part writes devices and assets\n";
} else {
    $conn->beginTransaction();
    try {
        $type = function (string $name) use ($conn): int {
            $conn->prepare("INSERT INTO asset_types (name, is_active) VALUES (?, 1)")->execute([$name]);
            return (int)$conn->lastInsertId();
        };
        $tLaptop = $type('ZZ-AT Laptop'); $tPhone = $type('ZZ-AT Phone'); $tChosen = $type('ZZ-AT Chosen');
        $asset = function (string $host, ?int $typeId = null) use ($conn): int {
            $conn->prepare("INSERT INTO assets (hostname, asset_type_id) VALUES (?, ?)")->execute([$host, $typeId]);
            return (int)$conn->lastInsertId();
        };
        $device = function (int $asset, ?string $chassis, string $os = 'Windows') use ($conn): string {
            $id = 'zz-at-' . bin2hex(random_bytes(6));
            intuneUpsertDevice($conn, ['id' => $id, 'deviceName' => 'ZZ-AT', 'chassisType' => $chassis, 'operatingSystem' => $os]);
            $conn->prepare("UPDATE intune_devices SET asset_id = ? WHERE intune_id = ?")->execute([$asset, $id]);
            return $id;
        };
        $typeOf = function (int $asset) use ($conn): ?int {
            $s = $conn->prepare("SELECT asset_type_id FROM assets WHERE id = ?"); $s->execute([$asset]);
            $v = $s->fetchColumn(); return $v === null ? null : (int)$v;
        };
        $map = function (array $m) use ($conn): void {
            $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('intune_type_map', ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$m ? json_encode($m) : '']);
        };

        $aLaptop  = $asset('ZZ-AT-LAPTOP');  $dLaptop = $device($aLaptop, 'laptop');
        $aChosen  = $asset('ZZ-AT-CHOSEN', $tChosen); $device($aChosen, 'laptop');
        $aDesk    = $asset('ZZ-AT-DESKTOP'); $device($aDesk, 'desktop');
        $aPc      = $asset('ZZ-AT-PC');      $device($aPc, 'unknown');
        $aIos     = $asset('ZZ-AT-IOS');     $device($aIos, 'unknown', 'iOS');
        $aTwo     = $asset('ZZ-AT-TWO');     $device($aTwo, 'laptop'); $device($aTwo, 'phone');
        $aRaw     = $asset('ZZ-AT-RAW');     $dRaw = $device($aRaw, 'laptop');
        // Synced before the column existed: only raw_json knows.
        $conn->prepare("UPDATE intune_devices SET chassis_type = NULL WHERE intune_id = ?")->execute([$dRaw]);

        $s = $conn->prepare("SELECT chassis_type FROM intune_devices WHERE intune_id = ?"); $s->execute([$dLaptop]);
        ok('the sync keeps chassisType in its own column', $s->fetchColumn() === 'laptop');

        $map([]);
        $r = intuneApplyAssetTypes($conn);
        ok('nothing mapped: nothing typed', $r['typed'] === 0 && $typeOf($aLaptop) === null, json_encode($r));

        $map(['laptop' => $tLaptop, 'other_mobile' => $tPhone]);
        $r = intuneApplyAssetTypes($conn);
        ok('POSITIVE CONTROL: an untyped laptop gets the laptop type', $typeOf($aLaptop) === $tLaptop, json_encode($r));
        $h = $conn->prepare("SELECT COUNT(*) FROM asset_history WHERE asset_id = ?"); $h->execute([$aLaptop]);
        ok('...recorded in its history', (int)$h->fetchColumn() >= 1);
        ok('a type somebody chose is never changed', $typeOf($aChosen) === $tChosen);
        ok('a kind nothing is mapped to gets nothing', $typeOf($aDesk) === null);
        ok('an unknown computer gets nothing', $typeOf($aPc) === null);
        ok('an unknown iOS device gets the other-mobile type', $typeOf($aIos) === $tPhone);
        ok('an asset whose devices disagree gets nothing', $typeOf($aTwo) === null);
        ok('a device from before the column is read from raw_json', $typeOf($aRaw) === $tLaptop);
        $again = intuneApplyAssetTypes($conn);
        ok('a second run changes nothing', $again['typed'] === 0 && $again['failed'] === 0, json_encode($again));

        $conn->prepare("UPDATE asset_types SET is_active = 0 WHERE id = ?")->execute([$tLaptop]);
        $aLater = $asset('ZZ-AT-LATER'); $device($aLater, 'laptop');
        intuneApplyAssetTypes($conn);
        ok('a type switched off since it was mapped maps to nothing', $typeOf($aLater) === null);
    } catch (Throwable $e) {
        ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        if ($conn->inTransaction()) $conn->rollBack();
    }
}

echo "\n" . str_repeat('=', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
