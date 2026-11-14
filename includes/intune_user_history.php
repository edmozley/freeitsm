<?php
/**
 * Intune: every primary user a device has had, assets that follow it, and the
 * assets whose Intune user is not their holder (GH #335).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY
 *
 * The sync overwrote intune_devices.user_id with the current primary user, and
 * intuneAssignPrimaryUsers() only fills a gap. So once an asset had a holder, a
 * change of primary user in Intune - a laptop handed on - was neither recorded
 * nor followed: Intune said user 1, then 2, then 3; FreeITSM still said user 1,
 * with no trace of 2 or 3.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE THREE PARTS
 *
 *  1. intuneRecordUserChange() - from intuneUpsertDevice(), for every device the
 *     sync reads: a row in intune_device_user_history when a device is first
 *     seen, then one each time its primary user differs from the last row. The
 *     person each object id matches is kept with it, by object id as everywhere
 *     (user_sso_identities.source_ref of an OIDC/Entra source), never by name.
 *  2. intuneFollowPrimaryUsers() - behind intune_follow_primary_user (off by
 *     default): moves an asset to its device's new primary user, as an ordinary
 *     unassign + assign, so the custody trail, history and workflows all see it.
 *     🔑 ONLY A HOLDER INTUNE PUT THERE (assetAssignedByIntuneSql()). A holder an
 *     analyst chose, or the API set, is never moved: the change is recorded and
 *     the asset shows that the two disagree, and a person decides.
 *  3. intuneResolveUserHistory() - after both, says for each new row what
 *     FreeITSM did about it (`outcome`), so the asset's Intune tab can say "moved",
 *     or why not.
 *
 * Plus the disagreement itself: intuneAssetsWithDifferentUser() and its count,
 * for the asset's tab and the System -> Maintenance tool.
 *
 * Everything here is quiet on an install whose Database Verification has not
 * made the table yet: nothing is recorded, nothing fails.
 *
 * @wiki Asset-Reconciliation-Developer-Guide
 */

require_once __DIR__ . '/asset_assignment_source.php';

/** What FreeITSM did about a change of primary user (intune_device_user_history.outcome). */
enum IntuneUserOutcome: string
{
    case First         = 'first';          // the first user the device was seen with: where its history starts
    case Holder        = 'holder';         // the asset's holder is this person (assigned, or moved to them)
    case NotImported   = 'not_imported';   // nobody here has this object id: no Entra import brought them in
    case NoUser        = 'no_user';        // the device has no primary user any more
    case NoAsset       = 'no_asset';       // the device is not linked to an asset
    case NotAssigned   = 'not_assigned';   // the asset has no holder (the assign setting is off, or a rule stopped it)
    case AnalystHolder = 'analyst_holder'; // the holder was chosen by an analyst (or the API), so Intune does not move them
    case FollowOff     = 'follow_off';     // the holder came from Intune, but "Follow changes" is off
    case Skipped       = 'skipped';        // follow is on but a rule stopped it: a leaver, another company, devices disagree
}

/** Whether the history table exists (Database Verification has run). Cached per request. */
function intuneUserHistoryReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $conn->query("SELECT 1 FROM intune_device_user_history LIMIT 0");
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/** An object id as it is compared: lower case, '' as none. */
function intuneNormaliseOid($oid): ?string
{
    $oid = strtolower(trim((string)$oid));
    return $oid === '' ? null : $oid;
}

/** The person (users.id) an Entra object id belongs to, as intuneAssignPrimaryUsers() matches; null for nobody. */
function intunePersonForObjectId(PDO $conn, ?string $oid): ?int
{
    if ($oid === null) return null;
    $st = $conn->prepare(
        "SELECT MIN(i.user_id) FROM user_sso_identities i
           JOIN auth_providers p ON p.id = i.provider_id AND p.protocol = 'oidc'
          WHERE i.source_ref = ?");
    $st->execute([$oid]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/**
 * After a device is written: a history row if it is new, or its primary user is
 * not the one its last row names. Never throws - a sync must not stop over its
 * own history.
 *
 * @param array $d  the managed device as Graph returned it (id, userId, userPrincipalName, userDisplayName)
 */
function intuneRecordUserChange(PDO $conn, array $d): void
{
    if (!intuneUserHistoryReady($conn) || empty($d['id'])) return;
    try {
        $dev = $conn->prepare("SELECT id, asset_id FROM intune_devices WHERE intune_id = ?");
        $dev->execute([$d['id']]);
        $device = $dev->fetch(PDO::FETCH_ASSOC);
        if (!$device) return;

        $last = $conn->prepare(
            "SELECT new_user_id, new_upn, new_name, new_person_id FROM intune_device_user_history
              WHERE intune_device_id = ? ORDER BY id DESC LIMIT 1");
        $last->execute([(int)$device['id']]);
        $prev = $last->fetch(PDO::FETCH_ASSOC);

        $now = intuneNormaliseOid($d['userId'] ?? null);
        if ($prev && intuneNormaliseOid($prev['new_user_id']) === $now) return;   // no change

        $conn->prepare(
            "INSERT INTO intune_device_user_history
                (intune_device_id, asset_id, old_user_id, old_upn, old_name, old_person_id,
                 new_user_id, new_upn, new_name, new_person_id, outcome, seen_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([
            (int)$device['id'], $device['asset_id'] !== null ? (int)$device['asset_id'] : null,
            $prev['new_user_id'] ?? null, $prev['new_upn'] ?? null, $prev['new_name'] ?? null,
            isset($prev['new_person_id']) ? (int)$prev['new_person_id'] : null,
            $now, $d['userPrincipalName'] ?? null, $d['userDisplayName'] ?? null,
            intunePersonForObjectId($conn, $now),
            // The first row is where the history starts, not a change to act on.
            $prev ? null : IntuneUserOutcome::First->value,
        ]);
    } catch (Throwable $e) {
        error_log('[intune] user history for ' . ($d['id'] ?? '?') . ': ' . $e->getMessage());
    }
}

/**
 * Move each asset Intune gave to somebody on to its device's new primary user.
 *
 * Only when ALL of these hold - otherwise the asset stays where it is, and the
 * change is only recorded:
 *  - the asset has exactly one holder, a requester, put there by Intune
 *    (assetAssignedByIntuneSql()) - never one an analyst or the API chose;
 *  - Intune's primary user is somebody else, matched by object id, current
 *    (not a leaver) and in the asset's company;
 *  - the asset's devices agree on who that is; the asset is not retired.
 *
 * The move is an unassign and an assign through AssetsService, so it is in the
 * custody trail and the asset's history ("from Ana to Ben") and fires the
 * asset workflows, each asset all or nothing.
 *
 * @return array  ['moved' => assets moved, 'failed' => assets that could not be, each logged]
 */
function intuneFollowPrimaryUsers(PDO $conn): array
{
    require_once __DIR__ . '/services/assets.php';
    require_once __DIR__ . '/tenancy.php';
    require_once __DIR__ . '/asset_retirement.php';

    $defaultId = getDefaultTenantId($conn);
    $byIntune = assetAssignedByIntuneSql('ua');
    $stmt = $conn->prepare(
        "SELECT d.asset_id, MIN(u.id) AS new_user, MIN(ua.user_id) AS old_user
           FROM intune_devices d
           JOIN assets a               ON a.id = d.asset_id
           JOIN user_sso_identities i  ON i.source_ref = d.user_id
           JOIN auth_providers p       ON p.id = i.provider_id AND p.protocol = 'oidc'
           JOIN users u                ON u.id = i.user_id AND u.is_active = 1
           JOIN users_assets ua        ON ua.asset_id = d.asset_id
          WHERE d.user_id IS NOT NULL AND d.user_id <> ''
            AND COALESCE(u.tenant_id, :def1) = COALESCE(a.tenant_id, :def2)
            AND ua.user_id IS NOT NULL AND ua.user_id <> u.id AND $byIntune
            AND (SELECT COUNT(*) FROM users_assets x WHERE x.asset_id = d.asset_id) = 1"
          . assetNotRetiredSql($conn, 'a') . "
       GROUP BY d.asset_id
         HAVING COUNT(DISTINCT u.id) = 1"
    );
    $stmt->execute([':def1' => $defaultId, ':def2' => $defaultId]);

    $ctx = ActorContext::system('Intune');
    $moved = 0; $failed = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $own = !$conn->inTransaction();
        try {
            if ($own) $conn->beginTransaction();
            AssetsService::unassignUser($conn, $ctx, (int)$row['asset_id'], (int)$row['old_user'], true);
            AssetsService::assignUser($conn, $ctx, (int)$row['asset_id'], [
                'user_id'          => (int)$row['new_user'],
                'previous_user_id' => (int)$row['old_user'],
                'notes'            => AssetAssignmentSource::INTUNE_NOTE,
                'source'           => AssetAssignmentSource::Intune,
            ]);
            if ($own) $conn->commit();
            $moved++;
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            $failed++;
            error_log('[intune] follow primary user of asset ' . $row['asset_id'] . ': ' . $e->getMessage());
        }
    }
    return ['moved' => $moved, 'failed' => $failed];
}

/**
 * Says, for each history row not decided yet, what FreeITSM did about it - run
 * after the assignments, so "holder" means it really is. Also fills in the asset
 * of a row whose device was linked later in the same sync.
 *
 * @param bool $followOn  whether "Follow changes of primary user" is on
 * @return int  how many rows were decided
 */
function intuneResolveUserHistory(PDO $conn, bool $followOn): int
{
    if (!intuneUserHistoryReady($conn)) return 0;
    $conn->exec("UPDATE intune_device_user_history h JOIN intune_devices d ON d.id = h.intune_device_id
                    SET h.asset_id = d.asset_id
                  WHERE h.outcome IS NULL AND h.asset_id IS NULL AND d.asset_id IS NOT NULL");
    $rows = $conn->query("SELECT id, asset_id, new_user_id, new_person_id FROM intune_device_user_history WHERE outcome IS NULL")
                 ->fetchAll(PDO::FETCH_ASSOC);
    $holders = $conn->prepare("SELECT ua.user_id, " . assetAssignedByIntuneSql('ua') . " AS by_intune FROM users_assets ua WHERE ua.asset_id = ?");
    $set = $conn->prepare("UPDATE intune_device_user_history SET outcome = ?, new_person_id = COALESCE(new_person_id, ?) WHERE id = ?");
    $n = 0;
    foreach ($rows as $r) {
        $person = $r['new_person_id'] !== null ? (int)$r['new_person_id'] : intunePersonForObjectId($conn, intuneNormaliseOid($r['new_user_id']));
        if ($r['new_user_id'] === null || $r['new_user_id'] === '') {
            $outcome = IntuneUserOutcome::NoUser;
        } elseif ($r['asset_id'] === null) {
            $outcome = IntuneUserOutcome::NoAsset;
        } elseif ($person === null) {
            $outcome = IntuneUserOutcome::NotImported;
        } else {
            $holders->execute([(int)$r['asset_id']]);
            $h = $holders->fetchAll(PDO::FETCH_ASSOC);
            if (in_array($person, array_map(fn($x) => (int)$x['user_id'], $h), true)) $outcome = IntuneUserOutcome::Holder;
            elseif (!$h)                                                            $outcome = IntuneUserOutcome::NotAssigned;
            elseif (count(array_filter($h, fn($x) => (int)$x['by_intune'] === 1)) < count($h)) $outcome = IntuneUserOutcome::AnalystHolder;
            elseif (!$followOn)                                                     $outcome = IntuneUserOutcome::FollowOff;
            else                                                                    $outcome = IntuneUserOutcome::Skipped;
        }
        $set->execute([$outcome->value, $person, (int)$r['id']]);
        $n++;
    }
    return $n;
}

/**
 * Assets whose Intune primary user is not among their holders: the asset has a
 * holder, a linked device names a primary user, and no holder has that object id.
 * A primary user nobody imported counts too - nobody here can be them.
 *
 * Every company: for the System -> Maintenance tool, which is administrators'.
 *
 * @param int $limit  the most rows to return
 * @return array  rows: asset_id, hostname, holders (names), upn, intune_name, person_id, person_name
 */
function intuneAssetsWithDifferentUser(PDO $conn, int $limit = 500): array
{
    require_once __DIR__ . '/asset_retirement.php';
    $st = $conn->prepare(
        "SELECT a.id AS asset_id, a.hostname, d.user_principal_name AS upn, d.user_display_name AS intune_name, d.user_id,
                (SELECT GROUP_CONCAT(COALESCE(u.display_name, an.full_name) ORDER BY ua.id SEPARATOR ', ')
                   FROM users_assets ua LEFT JOIN users u ON u.id = ua.user_id LEFT JOIN analysts an ON an.id = ua.analyst_id
                  WHERE ua.asset_id = a.id) AS holders
           FROM intune_devices d
           JOIN assets a ON a.id = d.asset_id
          WHERE " . intuneDifferentUserWhere() . assetNotRetiredSql($conn, 'a') . "
       GROUP BY a.id, a.hostname, d.user_principal_name, d.user_display_name, d.user_id
       ORDER BY a.hostname
          LIMIT " . max(1, $limit));
    $st->execute();
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = intunePersonForObjectId($conn, intuneNormaliseOid($r['user_id']));
        $name = null;
        if ($pid) { $n = $conn->prepare("SELECT display_name FROM users WHERE id = ?"); $n->execute([$pid]); $name = $n->fetchColumn() ?: null; }
        unset($r['user_id']);
        $out[] = array_merge($r, ['asset_id' => (int)$r['asset_id'], 'person_id' => $pid, 'person_name' => $name]);
    }
    return $out;
}

/** How many assets intuneAssetsWithDifferentUser() would list - one query, for the Maintenance card. */
function intuneCountAssetsWithDifferentUser(PDO $conn): int
{
    require_once __DIR__ . '/asset_retirement.php';
    return (int)$conn->query(
        "SELECT COUNT(DISTINCT a.id) FROM intune_devices d JOIN assets a ON a.id = d.asset_id
          WHERE " . intuneDifferentUserWhere() . assetNotRetiredSql($conn, 'a'))->fetchColumn();
}

/** The disagreement, as SQL over intune_devices d / assets a. */
function intuneDifferentUserWhere(): string
{
    return "d.user_id IS NOT NULL AND d.user_id <> ''
            AND EXISTS (SELECT 1 FROM users_assets h WHERE h.asset_id = a.id)
            AND NOT EXISTS (SELECT 1 FROM users_assets h
                              JOIN user_sso_identities i ON i.user_id = h.user_id AND i.source_ref = d.user_id
                              JOIN auth_providers p ON p.id = i.provider_id AND p.protocol = 'oidc'
                             WHERE h.asset_id = a.id)";
}

/**
 * For the asset's Intune tab: its devices' primary-user history, newest first,
 * and whether Intune's primary user is one of its holders.
 *
 * @return array  ['history' => rows, 'differs' => bool, 'intune_person' => ?array{id,name}]
 */
function intuneAssetUserInfo(PDO $conn, int $assetId): array
{
    $out = ['history' => [], 'differs' => false, 'intune_person' => null];
    if (intuneUserHistoryReady($conn)) {
        $st = $conn->prepare(
            "SELECT h.seen_datetime, h.old_upn, h.old_name, h.new_upn, h.new_name, h.outcome,
                    op.display_name AS old_person, np.display_name AS new_person, d.device_name
               FROM intune_device_user_history h
               JOIN intune_devices d ON d.id = h.intune_device_id
          LEFT JOIN users op ON op.id = h.old_person_id
          LEFT JOIN users np ON np.id = h.new_person_id
              WHERE h.asset_id = ? OR d.asset_id = ?
           ORDER BY h.id DESC LIMIT 100");
        $st->execute([$assetId, $assetId]);
        $out['history'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach (intuneAssetsWithDifferentUserFor($conn, $assetId) as $r) {
        $out['differs'] = true;
        $out['intune_person'] = $r['person_id'] ? ['id' => $r['person_id'], 'name' => $r['person_name']] : null;
    }
    return $out;
}

/** intuneAssetsWithDifferentUser() for one asset. */
function intuneAssetsWithDifferentUserFor(PDO $conn, int $assetId): array
{
    $st = $conn->prepare("SELECT d.user_id FROM intune_devices d JOIN assets a ON a.id = d.asset_id
                           WHERE a.id = ? AND " . intuneDifferentUserWhere() . " LIMIT 1");
    $st->execute([$assetId]);
    $oid = $st->fetchColumn();
    if ($oid === false) return [];
    $pid = intunePersonForObjectId($conn, intuneNormaliseOid($oid));
    $name = null;
    if ($pid) { $n = $conn->prepare("SELECT display_name FROM users WHERE id = ?"); $n->execute([$pid]); $name = $n->fetchColumn() ?: null; }
    return [['person_id' => $pid, 'person_name' => $name]];
}
