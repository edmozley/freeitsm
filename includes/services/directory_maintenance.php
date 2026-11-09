<?php
/**
 * DirectoryMaintenance - looking after the people a directory source imported,
 * for every kind of source (LDAP, CardDAV, Entra ID), ONCE (issue #261).
 *
 * Shared by api/system/directory_maintenance.php (Imported people in System -> Maintenance,
 * system/directory-maintenance/) and api/system/delete_sso_provider.php. Each
 * caller passes an ActorContext; this layer reads, writes and returns, or throws
 * ServiceError. It never emits HTTP.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE RULE IT RESTS ON
 *
 * A person is managed by a directory only while the source that imports them
 * exists (userManagedSql() in includes/users.php). That rule alone unlocks the
 * people of a deleted source everywhere; what is left for a person to decide is
 * here, in three lists:
 *
 *  - ORPHANS: people still flagged `is_managed` whose source is gone - from
 *    before this rule, or a source deleted some other way. Keep them (an
 *    ordinary person: the flag cleared), mark them as left, or delete them.
 *  - NO LONGER BROUGHT IN: people still assigned to a source that exists, which
 *    no import has found for N days (last_seen_in_source) - removed from the
 *    directory, moved out of what is imported, or the import stopped running.
 *    The missed-run threshold already marks them as left; this is the list to
 *    review. Same three actions - keeping one means the source no longer
 *    manages them.
 *  - REMOVE IMPORTED PEOPLE: the people a source CREATED (directory_sync_entries
 *    'created'), from every run or one, removed on purpose - undoing an import
 *    that should not have happened. Never the people it ADOPTED: they existed
 *    before the import, and removing them would delete somebody who was here.
 *
 * 🔑 NOTHING WITH HISTORY IS DELETED. Somebody who raised tickets or holds
 *    equipment is skipped, with the reason - userDeleteBlockedByHistory(), the
 *    same answer Tickets -> Users gives.
 *
 * 🔑 REMOVE REFUSES WHILE THE SOURCE IMPORTS. With the source or its import
 *    switched on, the next run would create everybody again as new people. It
 *    does not ask userDeleteBlockedByDirectory() - the hand-delete rule (#238) -
 *    because undoing an import is exactly what that rule leaves a way through for.
 *
 * 🔑 THE LIST IS WORKED OUT AGAIN ON EVERY WRITE. An id is acted on only if it
 *    is in the list it was picked from at that moment, and in a company the
 *    analyst can access. A stale page, or an id typed into a request, does
 *    nothing to anybody else.
 *
 * Every write is logged (system_logs 'directory_maintenance'): who, which list,
 * what was done to whom, and what was skipped and why.
 *
 * WHAT IF (#261): previewSourceDelete() and previewRemove() say in full what
 * deleting a source, or Remove imported people, would do - and change nothing.
 * They read with the same rules as the writes they describe, so the preview and
 * the write cannot disagree about who is affected.
 *
 * @wiki Directory-Maintenance
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../users.php';     // userManagedSql(), userDeleteBlockedByHistory()
require_once __DIR__ . '/../people.php';    // peopleScope(), peopleName()

final class DirectoryMaintenance
{
    /** The lists a write may be made from. */
    public const LISTS = ['orphans', 'stale', 'imported'];
    /** What each list may do to the people in it. */
    public const ACTIONS = ['orphans' => ['keep', 'left', 'delete'], 'stale' => ['keep', 'left', 'delete'], 'imported' => ['remove']];
    /** "Not reported for" choices, in days. */
    public const STALE_DAYS = [30, 90, 180, 365];
    /** The most people one list returns. */
    public const LIMIT = 500;

    // ─────────────────────────────────────────────────────────────────────────
    //  Reads
    // ─────────────────────────────────────────────────────────────────────────

    /** People still flagged as imported whose source no longer exists. */
    public static function orphans(PDO $conn, ActorContext $actor): array
    {
        return self::people($conn, $actor, self::orphanWhere(), []);
    }

    /**
     * How many orphans there are, in every company: the count on the card in
     * System -> Maintenance (#263). One query, no list.
     */
    public static function orphanCount(PDO $conn): int
    {
        return (int)$conn->query("SELECT COUNT(*) FROM users u WHERE " . self::orphanWhere())->fetchColumn();
    }

    /**
     * People still assigned to a source that exists which no import has found for $days days (or ever, by date).
     *
     * @param int $days  one of STALE_DAYS
     */
    public static function stale(PDO $conn, ActorContext $actor, int $providerId, int $days): array
    {
        self::source($conn, $providerId);
        [$where, $args] = self::staleWhere($providerId, self::days($days));
        return self::people($conn, $actor, $where, $args);
    }

    /**
     * The live runs of a source that created anybody, newest first, with how many of
     * those people are still here and still the source's.
     */
    public static function importRuns(PDO $conn, ActorContext $actor, int $providerId): array
    {
        self::source($conn, $providerId);
        [$scope, $sArgs] = peopleScope($conn, $actor->actorId, 'u.tenant_id');
        $st = $conn->prepare(
            "SELECT r.id, r.started_datetime, r.created_count,
                    (SELECT COUNT(DISTINCT e.user_id) FROM directory_sync_entries e JOIN users u ON u.id = e.user_id
                      WHERE e.run_id = r.id AND e.action = 'created' AND u.auth_provider_id = r.provider_id AND u.is_managed = 1 $scope) AS remaining
               FROM directory_sync_runs r
              WHERE r.provider_id = ? AND r.mode = 'live' AND r.created_count > 0
           ORDER BY r.started_datetime DESC, r.id DESC
              LIMIT 200"
        );
        $st->execute(array_merge($sArgs, [$providerId]));
        return array_map(fn($r) => ['id' => (int)$r['id'], 'started' => $r['started_datetime'],
            'created' => (int)$r['created_count'], 'remaining' => (int)$r['remaining']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The people a source created and still manages - from every run, or one.
     *
     * @return array ['people' => rows, 'total' => int, 'source_imports' => bool]
     */
    public static function imported(PDO $conn, ActorContext $actor, int $providerId, ?int $runId): array
    {
        $src = self::source($conn, $providerId);
        [$where, $args] = self::importedWhere($providerId, $runId);
        $out = self::people($conn, $actor, $where, $args);
        $out['source_imports'] = self::sourceImports($src);
        return $out;
    }

    /** How many people a source still manages - what deleting it would release. */
    public static function sourcePeopleCount(PDO $conn, int $providerId): int
    {
        $st = $conn->prepare("SELECT COUNT(*) FROM users WHERE auth_provider_id = ? AND is_managed = 1");
        $st->execute([$providerId]);
        return (int)$st->fetchColumn();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  What if (#261): the full effect of a write, without making it
    // ─────────────────────────────────────────────────────────────────────────

    /** The most people one preview lists - far more than a page does, because a preview is read in full. */
    public const PREVIEW_LIMIT = 5000;

    /**
     * What deleting a source would do: to its people - released, or marked as
     * left - with the fields that unlock for them, and to everything that hangs off
     * it (sign-in links, the analysts and teams that sign in with it, group
     * mappings, imported groups, the import's log, the scheduled import).
     *
     * Every company, not only the reader's: the delete is not limited to them
     * either. Administrators only, as the endpoint is.
     *
     * @param bool $markLeft  the choice in the delete question: mark the current ones as left
     * @return array  source, choice, unlocks, people, people_total, unlinked, records, schedule
     */
    public static function previewSourceDelete(PDO $conn, int $providerId, bool $markLeft): array
    {
        $st = $conn->prepare("SELECT * FROM auth_providers WHERE id = ?");
        $st->execute([$providerId]);
        $src = $st->fetch(PDO::FETCH_ASSOC);
        if (!$src) throw new ServiceError('not_found', 'not_found', 'No such provider.');
        $protocol = strtolower((string)$src['protocol']);

        // Its people: the ones it manages, as releaseSource() finds them.
        $st = $conn->prepare("SELECT COUNT(*) FROM users WHERE auth_provider_id = ? AND is_managed = 1");
        $st->execute([$providerId]);
        $total = (int)$st->fetchColumn();
        $st = $conn->prepare(
            "SELECT u.id, u.display_name, u.preferred_name, u.email, u.username, u.is_active, t.name AS company
               FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id
              WHERE u.auth_provider_id = ? AND u.is_managed = 1
           ORDER BY COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, ''), u.email)
              LIMIT " . self::PREVIEW_LIMIT);
        $st->execute([$providerId]);
        $people = array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => peopleName($r), 'email' => $r['email'], 'company' => $r['company'],
            'is_active' => (int)$r['is_active'] === 1,
            // What happens to them under the choice made.
            'outcome' => (int)$r['is_active'] !== 1 ? 'kept_left' : ($markLeft ? 'marked_left' : 'kept'),
        ], $st->fetchAll(PDO::FETCH_ASSOC));

        // People pointing at it without being managed by it: only the pointer goes.
        $st = $conn->prepare("SELECT COUNT(*) FROM users WHERE auth_provider_id = ? AND is_managed = 0");
        $st->execute([$providerId]);
        $unlinked = (int)$st->fetchColumn();

        // Everything else that names it, and what the delete does to it.
        $records = [];
        $add = function (string $key, string $outcome, string $countSql, ?string $listSql = null) use ($conn, $providerId, &$records) {
            try {
                $c = $conn->prepare($countSql);
                $c->execute([$providerId]);
                $n = (int)$c->fetchColumn();
                if ($n === 0) return;
                $items = [];
                if ($listSql) {
                    $l = $conn->prepare($listSql . ' LIMIT 200');
                    $l->execute([$providerId]);
                    $items = $l->fetchAll(PDO::FETCH_COLUMN);
                }
                $records[] = ['key' => $key, 'outcome' => $outcome, 'count' => $n, 'items' => array_map('strval', $items)];
            } catch (Throwable $e) {
                // A table this install has not got: nothing of it to delete either.
            }
        };
        $add('user_signins', 'removed', "SELECT COUNT(*) FROM user_sso_identities WHERE provider_id = ?");
        $add('analyst_signins', 'removed', "SELECT COUNT(*) FROM analyst_sso_identities WHERE provider_id = ?");
        $add('analysts', 'password', "SELECT COUNT(*) FROM analysts WHERE auth_provider_id = ?",
            "SELECT full_name FROM analysts WHERE auth_provider_id = ? ORDER BY full_name");
        $add('teams', 'nothing', "SELECT COUNT(*) FROM teams WHERE auth_method = 'provider' AND auth_provider_id = ?",
            "SELECT name FROM teams WHERE auth_method = 'provider' AND auth_provider_id = ? ORDER BY name");
        $add('group_teams', 'removed', "SELECT COUNT(*) FROM sso_group_teams WHERE provider_id = ?",
            "SELECT CONCAT(g.group_name, ' -> ', COALESCE(t.name, CONCAT('#', g.team_id))) FROM sso_group_teams g LEFT JOIN teams t ON t.id = g.team_id WHERE g.provider_id = ? ORDER BY g.group_name");
        $add('groups_seen', 'removed', "SELECT COUNT(*) FROM sso_groups_seen WHERE provider_id = ?",
            "SELECT group_name FROM sso_groups_seen WHERE provider_id = ? ORDER BY group_name");
        $add('directory_groups', 'removed', "SELECT COUNT(*) FROM directory_groups WHERE provider_id = ?",
            "SELECT CONCAT(g.display_name, ' (', (SELECT COUNT(*) FROM directory_group_members m WHERE m.group_id = g.id), ')') FROM directory_groups g WHERE g.provider_id = ? ORDER BY g.display_name");
        $add('group_members', 'removed', "SELECT COUNT(*) FROM directory_group_members m JOIN directory_groups g ON g.id = m.group_id WHERE g.provider_id = ?");
        $add('write_log', 'removed', "SELECT COUNT(*) FROM carddav_write_log WHERE provider_id = ?");
        $add('import_runs', 'kept', "SELECT COUNT(*) FROM directory_sync_runs WHERE provider_id = ?");

        // The scheduled directory import: does it import this source now, and how many others it still has.
        $importer = in_array($protocol, ['ldap', 'carddav'], true)
            || ($protocol === 'oidc' && function_exists('entraIsEntraIssuer') && entraIsEntraIssuer((string)$src['issuer_url']));
        $imports = $importer && (int)($src['enabled'] ?? 0) === 1 && (int)($src['sync_enabled'] ?? 0) === 1;
        $others = 0;
        if ($imports) {
            $st = $conn->prepare("SELECT COUNT(*) FROM auth_providers WHERE enabled = 1 AND sync_enabled = 1
                                   AND protocol IN ('ldap', 'carddav', 'oidc') AND id <> ?");
            $st->execute([$providerId]);
            $others = (int)$st->fetchColumn();
        }

        return [
            'source'   => ['id' => (int)$src['id'], 'name' => $src['display_name'], 'protocol' => $protocol],
            'choice'   => $markLeft ? 'left' : 'keep',
            // The same for each of them: what the source locked, and deleting it unlocks.
            'unlocks'  => $total ? userDirectoryOwnedFields($protocol, (int)($src['carddav_write_back'] ?? 0) === 1) : [],
            'people'   => $people,
            'people_total' => $total,
            'unlinked' => $unlinked,
            'records'  => $records,
            'schedule' => ['imports' => $imports, 'others' => $others],
        ];
    }

    /**
     * What Remove imported people would do: who would be deleted and who skipped
     * (with the reason), and what goes with each - their sign-in links, group
     * memberships, and the people who report to them, who are left with no manager.
     *
     * Read with the same rule as apply('imported'): the analyst's companies only.
     *
     * @param int[]|null $ids  only these (the ones picked), or null for the whole list
     * @return array  source, source_imports, people, total
     */
    public static function previewRemove(PDO $conn, ActorContext $actor, int $providerId, ?int $runId, ?array $ids): array
    {
        $src = self::source($conn, $providerId);
        [$where, $args] = self::importedWhere($providerId, $runId);
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
            if (!$ids) throw new ServiceError('validation', 'missing_field', 'Choose somebody first.');
            if (count($ids) > self::PREVIEW_LIMIT) throw new ServiceError('validation', 'invalid_field', 'Too many people at once.');
            $where .= ' AND u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $args = array_merge($args, $ids);
        }
        [$scope, $sArgs] = peopleScope($conn, $actor->actorId, 'u.tenant_id');
        $st = $conn->prepare("SELECT COUNT(*) FROM users u WHERE $where $scope");
        $st->execute(array_merge($args, $sArgs));
        $total = (int)$st->fetchColumn();
        $st = $conn->prepare(
            "SELECT u.id, u.display_name, u.preferred_name, u.email, u.username, t.name AS company,
                    (SELECT COUNT(*) FROM users r WHERE r.manager_id = u.id AND r.id <> u.id) AS reports
               FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id
              WHERE $where $scope
           ORDER BY COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, ''), u.email)
              LIMIT " . self::PREVIEW_LIMIT);
        $st->execute(array_merge($args, $sArgs));
        $count = function (string $sql, int $id) use ($conn): int {
            try {
                $c = $conn->prepare($sql);
                $c->execute([$id]);
                return (int)$c->fetchColumn();
            } catch (Throwable $e) {
                return 0;   // a table this install has not got
            }
        };
        $people = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            $why = userDeleteBlockedByHistory($conn, $id);
            $people[] = [
                'id' => $id, 'name' => peopleName($r), 'email' => $r['email'], 'company' => $r['company'],
                'outcome' => $why ? 'skipped' : 'removed', 'reason' => $why,
                'signins' => $count("SELECT COUNT(*) FROM user_sso_identities WHERE user_id = ?", $id),
                'groups'  => $count("SELECT COUNT(*) FROM directory_group_members WHERE user_id = ?", $id),
                'reports' => (int)$r['reports'],
            ];
        }
        return [
            'source' => ['id' => (int)$src['id'], 'name' => $src['display_name'], 'protocol' => strtolower((string)$src['protocol'])],
            'source_imports' => self::sourceImports($src),
            'people' => $people,
            'total'  => $total,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Writes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Does $action to the people in $ids who are in $list right now, and logs it.
     *
     * @param string $list  orphans, stale or imported
     * @param string $action  keep, left or delete (orphans, stale); remove (imported)
     * @param int[] $ids  users.id, as picked on the page
     * @param array $ctx  provider_id (stale, imported), days (stale), run_id (imported, optional)
     * @return array ['done' => [['id','name']], 'skipped' => [['id','name','reason']]]
     */
    public static function apply(PDO $conn, ActorContext $actor, string $list, string $action, array $ids, array $ctx = []): array
    {
        if (!in_array($list, self::LISTS, true)) throw new ServiceError('validation', 'invalid_field', 'Unknown list.');
        if (!in_array($action, self::ACTIONS[$list], true)) throw new ServiceError('validation', 'invalid_field', 'That action is not offered for this list.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids) throw new ServiceError('validation', 'missing_field', 'Choose somebody first.');
        if (count($ids) > self::LIMIT) throw new ServiceError('validation', 'invalid_field', 'Too many people at once.');

        $providerId = (int)($ctx['provider_id'] ?? 0);
        if ($list === 'orphans') {
            [$where, $args] = [self::orphanWhere(), []];
        } elseif ($list === 'stale') {
            self::source($conn, $providerId);
            [$where, $args] = self::staleWhere($providerId, self::days((int)($ctx['days'] ?? 0)));
        } else {
            $src = self::source($conn, $providerId);
            if (self::sourceImports($src)) {
                throw new ServiceError('conflict', 'source_imports', 'Switch this source off, or its import, before removing what it imported: the next run would create everybody again.');
            }
            $runId = isset($ctx['run_id']) && (int)$ctx['run_id'] > 0 ? (int)$ctx['run_id'] : null;
            [$where, $args] = self::importedWhere($providerId, $runId);
        }

        // Who is in the list now, of the ones picked - read with the same rule as the page.
        [$scope, $sArgs] = peopleScope($conn, $actor->actorId, 'u.tenant_id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $st = $conn->prepare("SELECT u.id, u.display_name, u.preferred_name, u.email, u.username FROM users u
                               WHERE u.id IN ($marks) AND $where $scope");
        $st->execute(array_merge($ids, $args, $sArgs));
        $inList = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $inList[(int)$r['id']] = peopleName($r);

        $done = $skipped = [];
        foreach ($ids as $id) {
            if (!isset($inList[$id])) { $skipped[] = ['id' => $id, 'name' => null, 'reason' => 'Not in this list any more.']; continue; }
            $name = $inList[$id];
            try {
                if ($action === 'keep') {
                    self::release($conn, $id);
                } elseif ($action === 'left') {
                    $conn->prepare("UPDATE users SET is_active = 0, deactivated_datetime = COALESCE(deactivated_datetime, UTC_TIMESTAMP()) WHERE id = ?")->execute([$id]);
                } else {   // delete, remove
                    if ($why = userDeleteBlockedByHistory($conn, $id)) { $skipped[] = ['id' => $id, 'name' => $name, 'reason' => $why]; continue; }
                    self::deletePerson($conn, $id);
                }
                $done[] = ['id' => $id, 'name' => $name];
            } catch (Throwable $e) {
                error_log('[directory maintenance] ' . $action . ' #' . $id . ': ' . $e->getMessage());
                $skipped[] = ['id' => $id, 'name' => $name, 'reason' => 'Could not be changed: something else still refers to this person.'];
            }
        }

        self::log($conn, $actor, ['list' => $list, 'action' => $action, 'provider_id' => $providerId ?: null,
            'run_id' => $ctx['run_id'] ?? null, 'days' => $ctx['days'] ?? null,
            'done' => array_column($done, 'id'), 'skipped' => array_map(fn($s) => ['id' => $s['id'], 'reason' => $s['reason']], $skipped)]);
        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * What deleting a source does to its people: they stay, as ordinary people
     * (or as leavers, when asked), with nothing pointing at the source any more.
     * Called by api/system/delete_sso_provider.php BEFORE the source row goes.
     *
     * @param bool $markLeft  also mark the current ones as left
     * @return int  how many people it released
     */
    public static function releaseSource(PDO $conn, ActorContext $actor, int $providerId, string $providerName, bool $markLeft): int
    {
        $st = $conn->prepare("SELECT id FROM users WHERE auth_provider_id = ? AND is_managed = 1");
        $st->execute([$providerId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($markLeft) {
            $conn->prepare("UPDATE users SET is_active = 0, deactivated_datetime = COALESCE(deactivated_datetime, UTC_TIMESTAMP())
                             WHERE auth_provider_id = ? AND is_managed = 1 AND is_active = 1")->execute([$providerId]);
        }
        // Every row pointing at the source, flagged or not: by hand a foreign key
        // would NULL them, but Database Verification adds none (see userManagedSql()).
        $conn->prepare("UPDATE users SET is_managed = 0, auth_provider_id = NULL WHERE auth_provider_id = ?")->execute([$providerId]);
        if ($ids) {
            self::log($conn, $actor, ['list' => 'source_deleted', 'action' => $markLeft ? 'left' : 'keep',
                'provider_id' => $providerId, 'provider' => $providerName, 'done' => $ids, 'skipped' => []]);
        }
        return count($ids);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Internals
    // ─────────────────────────────────────────────────────────────────────────

    /** Flagged as imported, with no source left. */
    private static function orphanWhere(): string
    {
        return "u.is_managed = 1 AND NOT EXISTS (SELECT 1 FROM auth_providers op WHERE op.id = u.auth_provider_id)";
    }

    /** Managed by this source, and not found by any of its imports since $days days ago. */
    private static function staleWhere(int $providerId, int $days): array
    {
        return ["u.auth_provider_id = ? AND u.is_managed = 1
                 AND (u.last_seen_in_source IS NULL OR u.last_seen_in_source < UTC_TIMESTAMP() - INTERVAL ? DAY)", [$providerId, $days]];
    }

    /** Created by a live run of this source (one, or any), and still its. */
    private static function importedWhere(int $providerId, ?int $runId): array
    {
        $run = $runId ? ' AND r.id = ?' : '';
        return ["u.auth_provider_id = ? AND u.is_managed = 1 AND EXISTS (
                    SELECT 1 FROM directory_sync_entries e JOIN directory_sync_runs r ON r.id = e.run_id
                     WHERE e.user_id = u.id AND e.action = 'created' AND r.provider_id = ? AND r.mode = 'live'$run)",
                $runId ? [$providerId, $providerId, $runId] : [$providerId, $providerId]];
    }

    /** A list of people, scoped to the analyst's companies, each with what would stop a delete. */
    private static function people(PDO $conn, ActorContext $actor, string $where, array $args): array
    {
        [$scope, $sArgs] = peopleScope($conn, $actor->actorId, 'u.tenant_id');
        $st = $conn->prepare("SELECT COUNT(*) FROM users u WHERE $where $scope");
        $st->execute(array_merge($args, $sArgs));
        $total = (int)$st->fetchColumn();
        $st = $conn->prepare(
            "SELECT u.id, u.display_name, u.preferred_name, u.email, u.username, u.is_active, u.last_seen_in_source,
                    (SELECT COUNT(*) FROM tickets t WHERE t.user_id = u.id) AS tickets,
                    (SELECT COUNT(*) FROM users_assets a WHERE a.user_id = u.id) AS assets
               FROM users u
              WHERE $where $scope
           ORDER BY COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, ''), u.email)
              LIMIT " . self::LIMIT
        );
        $st->execute(array_merge($args, $sArgs));
        $people = array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => peopleName($r), 'email' => $r['email'],
            'is_active' => (int)$r['is_active'] === 1, 'last_seen' => $r['last_seen_in_source'],
            'tickets' => (int)$r['tickets'], 'assets' => (int)$r['assets'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['people' => $people, 'total' => $total];
    }

    /** The source, or a ServiceError. */
    private static function source(PDO $conn, int $providerId): array
    {
        $st = $conn->prepare("SELECT id, display_name, protocol, enabled, sync_enabled FROM auth_providers WHERE id = ? AND protocol IN ('ldap', 'carddav', 'oidc')");
        $st->execute([$providerId]);
        $src = $st->fetch(PDO::FETCH_ASSOC);
        if (!$src) throw new ServiceError('not_found', 'not_found', 'No such people source.');
        return $src;
    }

    /** Whether a run of this source could create people: it, and its import, switched on. */
    private static function sourceImports(array $src): bool
    {
        return (int)$src['enabled'] === 1 && (int)$src['sync_enabled'] === 1;
    }

    /** One of STALE_DAYS, or a ServiceError. */
    private static function days(int $days): int
    {
        if (!in_array($days, self::STALE_DAYS, true)) throw new ServiceError('validation', 'invalid_field', 'Choose how long first.');
        return $days;
    }

    /** An ordinary person from now on: not managed, pointing at no source. */
    private static function release(PDO $conn, int $userId): void
    {
        $conn->prepare("UPDATE users SET is_managed = 0, auth_provider_id = NULL WHERE id = ?")->execute([$userId]);
    }

    /**
     * Deletes a person and what hangs off them that a foreign key would take - by
     * hand, because a verified install has none (see delete_sso_provider.php).
     */
    private static function deletePerson(PDO $conn, int $userId): void
    {
        // All or nothing - unless the caller already holds a transaction (a test), which then decides.
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            foreach (["DELETE FROM user_sso_identities WHERE user_id = ?", "DELETE FROM directory_group_members WHERE user_id = ?"] as $sql) {
                try { $conn->prepare($sql)->execute([$userId]); }
                catch (PDOException $e) { if (!str_contains($e->getMessage(), '42S02')) throw $e; }   // a table this install has not got
            }
            $conn->prepare("UPDATE users SET manager_id = NULL WHERE manager_id = ?")->execute([$userId]);
            $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own) $conn->rollBack();
            throw $e;
        }
    }

    /** One system log row per write. */
    private static function log(PDO $conn, ActorContext $actor, array $details): void
    {
        $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES ('directory_maintenance', ?, ?, UTC_TIMESTAMP())")
             ->execute([$actor->actorId ?: null, json_encode($details)]);
    }
}
