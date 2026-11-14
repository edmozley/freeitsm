<?php
/**
 * Intune: give each asset a type from what its device is (GH #336).
 *
 * An asset the Intune sync creates had no type - createDiscoveredAsset() is
 * given a hostname, make, model, OS and serial, never asset_type_id - so on an
 * install fed by Intune most assets were untyped: no icon, nothing to group or
 * report on. Intune does know: Graph sends `chassisType` with every managed device.
 *
 * Asset types are each install's own ("Laptop" here, "Notebook" there), so
 * nothing is hard-coded: Assets -> Settings -> Intune maps each kind of device
 * (IntuneDeviceKind) to one of the install's types, in intune_type_map. Nothing
 * is mapped until somebody maps it.
 *
 * 🔑 IT FILLS, NEVER CHANGES. Only an asset with no type gets one; a type
 *    somebody chose is never overwritten, whatever Intune says.
 *
 * @wiki Asset-Reconciliation-Developer-Guide
 */

require_once __DIR__ . '/service_context.php';

/** What kind of device Intune says it is - the rows of the mapping. */
enum IntuneDeviceKind: string
{
    case Laptop      = 'laptop';
    case Desktop     = 'desktop';        // and workstations
    case Server      = 'server';
    case Phone       = 'phone';
    case Tablet      = 'tablet';
    case OtherMobile = 'other_mobile';

    /**
     * From Graph's chassisType, or - when it is unknown - from the operating
     * system, but only where that is unambiguous: iOS, iPadOS and Android are
     * mobile; a Windows, macOS or Linux machine could be a laptop or a desktop,
     * so it gets no kind rather than a guess.
     */
    public static function fromDevice(?string $chassis, ?string $os): ?self
    {
        switch (strtolower(trim((string)$chassis))) {
            case 'laptop':            return self::Laptop;
            case 'desktop':
            case 'worksworkstation':  return self::Desktop;
            case 'enterpriseserver':  return self::Server;
            case 'phone':             return self::Phone;
            case 'tablet':            return self::Tablet;
            case 'mobileother':
            case 'mobileunknown':     return self::OtherMobile;
        }
        $os = strtolower((string)$os);
        if (str_contains($os, 'ios') || str_contains($os, 'ipados') || str_contains($os, 'android')) return self::OtherMobile;
        return null;
    }
}

/**
 * The mapping, as kind value => asset_types.id, keeping only types that exist
 * and are active - a type deleted since it was mapped maps to nothing.
 *
 * @return array<string,int>
 */
function intuneTypeMap(PDO $conn): array
{
    $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'intune_type_map'");
    $st->execute();
    $raw = json_decode((string)$st->fetchColumn(), true);
    if (!is_array($raw)) return [];
    $active = array_flip(array_map('intval', $conn->query("SELECT id FROM asset_types WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN)));
    $out = [];
    foreach (IntuneDeviceKind::cases() as $k) {
        $id = (int)($raw[$k->value] ?? 0);
        if ($id > 0 && isset($active[$id])) $out[$k->value] = $id;
    }
    return $out;
}

/**
 * After a device is written: keep its chassisType in a column of its own.
 * Nothing before Database Verification has added the column; never throws.
 *
 * @param array $d  the managed device as Graph returned it
 */
function intuneRecordChassis(PDO $conn, array $d): void
{
    static $ready = null;
    if ($ready === null) {
        require_once __DIR__ . '/tenancy.php';
        $ready = tenancyColumnExists($conn, 'intune_devices', 'chassis_type');
    }
    if (!$ready || empty($d['id'])) return;
    try {
        $conn->prepare("UPDATE intune_devices SET chassis_type = ? WHERE intune_id = ?")
             ->execute([isset($d['chassisType']) ? substr((string)$d['chassisType'], 0, 32) : null, $d['id']]);
    } catch (Throwable $e) {
        error_log('[intune] chassis type for ' . $d['id'] . ': ' . $e->getMessage());
    }
}

/**
 * Give each linked asset with no type the type its device's kind is mapped to.
 *
 * Skipped: an asset that has a type; a kind nothing is mapped to; a device whose
 * kind is unknown; and an asset whose devices say different kinds. Each type is
 * set through AssetsService::updateFields(), so it is in the asset's history.
 * Devices synced before chassis_type existed are read from raw_json.
 *
 * @return array  ['typed' => assets given a type, 'failed' => assets that could not be, each logged]
 */
function intuneApplyAssetTypes(PDO $conn): array
{
    $map = intuneTypeMap($conn);
    if (!$map) return ['typed' => 0, 'failed' => 0];
    require_once __DIR__ . '/services/assets.php';
    require_once __DIR__ . '/tenancy.php';

    $chassis = tenancyColumnExists($conn, 'intune_devices', 'chassis_type')
        ? "COALESCE(d.chassis_type, JSON_UNQUOTE(JSON_EXTRACT(d.raw_json, '$.chassisType')))"
        : "JSON_UNQUOTE(JSON_EXTRACT(d.raw_json, '$.chassisType'))";
    $rows = $conn->query(
        "SELECT d.asset_id, $chassis AS chassis, d.operating_system
           FROM intune_devices d JOIN assets a ON a.id = d.asset_id
          WHERE a.asset_type_id IS NULL AND JSON_VALID(COALESCE(d.raw_json, '{}'))"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Every device of an asset must say the same kind.
    $kinds = [];
    foreach ($rows as $r) {
        $k = IntuneDeviceKind::fromDevice($r['chassis'] === 'null' ? null : $r['chassis'], $r['operating_system']);
        $kinds[(int)$r['asset_id']][] = $k ? $k->value : '';
    }

    $ctx = ActorContext::system('Intune');
    $typed = 0; $failed = 0;
    foreach ($kinds as $assetId => $list) {
        $list = array_unique($list);
        if (count($list) !== 1 || $list[0] === '' || !isset($map[$list[0]])) continue;
        try {
            AssetsService::updateFields($conn, $ctx, $assetId, ['asset_type_id' => $map[$list[0]]]);
            $typed++;
        } catch (Throwable $e) {
            $failed++;
            error_log('[intune] asset type for asset ' . $assetId . ': ' . $e->getMessage());
        }
    }
    return ['typed' => $typed, 'failed' => $failed];
}
