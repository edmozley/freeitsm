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
 *    It works for a DELETED source too: deleting one releases its people, and
 *    the log entry the delete writes keeps whom its imports created - so they
 *    can still be removed afterwards (deletedSources(), removeSource()).
 *    ⚠️ Not the import history alone: on an install made from
 *    database/freeitsm.sql, directory_sync_runs has ON DELETE CASCADE to
 *    auth_providers, so the history goes with the source. A verified install
 *    has no foreign key and keeps it; both are read.
 *
 * 🔑 NOTHING WITH HISTORY IS DELETED. Somebody who raised tickets or holds
 *    equipment is skipped, with the reason - userDeleteBlockedByHistory(), the
 *    same answer Tickets -> Users gives - and so is anybody another record still
 *    names (a starters and leavers case, a contract...: userDeleteBlockedByRecords()).
 *    The delete itself is userDeletePerson(), shared with Tickets -> Users.
 *
 * 🔑 ADOPTED PEOPLE ARE NEVER DELETED. Delete on the orphans and stale lists, like
 *    Remove, acts only on people an import CREATED; the ones it adopted existed
 *    before it, and are skipped with the reason (notCreatedReasons()).
 *
 * 🔑 REMOVE, AND DELETE ON THE STALE LIST, REFUSE WHILE THE SOURCE IMPORTS. With
 *    the source or its import switched on, the next run would create everybody
 *    again as new people. They do not ask userDeleteBlockedByDirectory() - the
 *    hand-delete rule (#238) - because undoing an import is exactly what that rule
 *    leaves a way through for.
 *
 * 🔑 DELETING A SOURCE NEEDS EVERY COMPANY. It changes all its people, so an
 *    analyst limited to some companies may do it only when all of them are in
 *    those companies (sourcePeopleOutOfReach()).
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
 * @wiki Directory-Maintenance-Developer-Guide
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';   // analystHasAllTenantAccess()
require_once __DIR__ . '/../users.php';     // userManagedSql(), userDeleteBlockedByHistory(), userDeletePerson()
require_once __DIR__ . '/../people.php';    // peopleScope(), peopleName()

final class DirectoryMaintenance
{
    /** The lists a write may be made from. */
    public const LISTS = ['orphans', 'stale', 'imported'];
    /** What each list may do to the people in it. */
    public const ACTIONS = ['orphans' => ['keep', 'left', 'delete'], 'stale' => ['keep', 'left', 'delete'], 'imported' => ['remove']];
    /** "Not reported for" choices, in days. */
    public const STALE_DAYS = [30, 90, 180, 365];
    /** The one Imported people opens on, and each source's page counts. */
    public const STALE_DEFAULT = 90;
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
        $src = self::source($conn, $providerId);
        [$where, $args] = self::staleWhere($providerId, self::days($days));
        $out = self::people($conn, $actor, $where, $args);
        // Delete waits until the source no longer imports, as Remove does (apply()).
        $out['source_imports'] = self::sourceImports($src);
        return $out;
    }

    /**
     * How many are on that list: the line on each source's page (#261). One query,
     * in the reader's companies, as the list is.
     */
    public static function staleCount(PDO $conn, ActorContext $actor, int $providerId, int $days): int
    {
        [$where, $args] = self::staleWhere($providerId, self::days($days));
        [$scope, $sArgs] = peopleScope($conn, $actor->actorId, 'u.tenant_id');
        $st = $conn->prepare("SELECT COUNT(*) FROM users u WHERE $where $scope");
        $st->execute(array_merge($args, $sArgs));
        return (int)$st->fetchColumn();
    }

    /**
     * The live runs of a source that created anybody, newest first, with how many of
     * those people are still here and still the source's.
     */
    public static function importRuns(PDO $conn, ActorContext $actor, int $providerId): array
    {
        $src = self::removeSource($conn, $providerId);
        [$scope, $sArgs] = peopleScope($conn, $actor->actorId, 'u.tenant_id');
        $still = !empty($src['deleted']) ? 'NOT ' . userManagedSql('u') : 'u.auth_provider_id = r.provider_id AND u.is_managed = 1';
        $st = $conn->prepare(
            "SELECT r.id, r.started_datetime, r.created_count,
                    (SELECT COUNT(DISTINCT e.user_id) FROM directory_sync_entries e JOIN users u ON u.id = e.user_id
                      WHERE e.run_id = r.id AND e.action = 'created' AND $still $scope) AS remaining
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
        $src = self::removeSource($conn, $providerId);
        [$where, $args] = self::importedWhere($providerId, $runId, !empty($src['deleted']), $src['created'] ?? []);
        $out = self::people($conn, $actor, $where, $args);
        $out['source_imports'] = self::sourceImports($src);
        return $out;
    }

    /**
     * Sources that were deleted but whose imports created somebody: their import
     * history stays, so Remove imported people can still undo them. Named from the
     * log entry the delete wrote (releaseSource()); "#id" when there is none.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public static function deletedSources(PDO $conn): array
    {
        $out = [];
        // The delete's own log entry: the name, and whom its imports created.
        $st = $conn->query("SELECT details FROM system_logs WHERE log_type = 'directory_maintenance' AND details LIKE '%source_deleted%' ORDER BY id");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $d = json_decode((string)$json, true);
            if (($d['list'] ?? '') !== 'source_deleted' || empty($d['provider_id'])) continue;
            $id = (int)$d['provider_id'];
            $out[$id] = ['id' => $id, 'name' => ($d['provider'] ?? '') !== '' ? (string)$d['provider'] : '#' . $id,
                         'created' => array_values(array_unique(array_merge($out[$id]['created'] ?? [], array_map('intval', (array)($d['created'] ?? [])))))];
        }
        // The import history, where it outlived the source (a verified install).
        try {
            foreach ($conn->query("SELECT DISTINCT r.provider_id FROM directory_sync_runs r
                                    WHERE r.mode = 'live' AND r.created_count > 0")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $id = (int)$id;
                $out[$id] = $out[$id] ?? ['id' => $id, 'name' => '#' . $id, 'created' => []];
                $out[$id]['history'] = true;
            }
        } catch (Throwable $e) {
            // No import history on this install.
        }
        // Only the ones that are gone, and that created somebody.
        $exists = array_flip(array_map('intval', $conn->query("SELECT id FROM auth_providers")->fetchAll(PDO::FETCH_COLUMN)));
        $out = array_filter($out, fn($s) => !isset($exists[$s['id']]) && ($s['created'] || !empty($s['history'])));
        ksort($out);
        return array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'created' => $s['created']], array_values($out));
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
     * The reader's companies: an administrator who can access every company sees
     * every person; one who cannot sees the people in the companies they reach and
     * how many more there are (people_hidden) - and may not delete the source while
     * there are any (can_delete false; releaseSource() refuses the same).
     *
     * @param bool $markLeft  the choice in the delete question: mark the current ones as left
     * @return array  source, choice, unlocks, people, people_total, people_hidden, can_delete, unlinked, records, schedule
     */
    public static function previewSourceDelete(PDO $conn, ActorContext $actor, int $providerId, bool $markLeft): array
    {
        $st = $conn->prepare("SELECT * FROM auth_providers WHERE id = ?");
        $st->execute([$providerId]);
        $src = $st->fetch(PDO::FETCH_ASSOC);
        if (!$src) throw new ServiceError('not_found', 'not_found', 'No such provider.');
        $protocol = strtolower((string)$src['protocol']);

        // Its people: the ones it manages, as releaseSource() finds them - in the reader's companies.
        [$scope, $sArgs] = self::deleteScope($conn, $actor);
        $hidden = self::sourcePeopleOutOfReach($conn, $actor, $providerId);
        $st = $conn->prepare("SELECT COUNT(*) FROM users u WHERE u.auth_provider_id = ? AND u.is_managed = 1 $scope");
        $st->execute(array_merge([$providerId], $sArgs));
        $total = (int)$st->fetchColumn();
        $st = $conn->prepare(
            "SELECT u.id, u.display_name, u.preferred_name, u.email, u.username, u.is_active, t.name AS company
               FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id
              WHERE u.auth_provider_id = ? AND u.is_managed = 1 $scope
           ORDER BY COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, ''), u.email)
              LIMIT " . self::PREVIEW_LIMIT);
        $st->execute(array_merge([$providerId], $sArgs));
        $people = array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => peopleName($r), 'email' => $r['email'], 'company' => $r['company'],
            'is_active' => (int)$r['is_active'] === 1,
            // What happens to them under the choice made.
            'outcome' => (int)$r['is_active'] !== 1 ? 'kept_left' : ($markLeft ? 'marked_left' : 'kept'),
        ], $st->fetchAll(PDO::FETCH_ASSOC));

        // People pointing at it without being managed by it: only the pointer goes.
        $st = $conn->prepare("SELECT COUNT(*) FROM users u WHERE u.auth_provider_id = ? AND u.is_managed = 0 $scope");
        $st->execute(array_merge([$providerId], $sArgs));
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
        // Kept - unless this install has the foreign key database/freeitsm.sql
        // declares, which takes the history with the source.
        $add('import_runs', self::historyGoesWithSource($conn) ? 'removed' : 'kept', "SELECT COUNT(*) FROM directory_sync_runs WHERE provider_id = ?");

        // The analyst reading it, when they sign in with it: named first, and marked
        // - the delete locks them out until a password is set for them.
        foreach ($records as &$rec) {
            if ($rec['key'] !== 'analysts') continue;
            $me = $conn->prepare("SELECT full_name FROM analysts WHERE id = ? AND auth_provider_id = ?");
            $me->execute([$actor->actorId, $providerId]);
            $mine = $me->fetchColumn();
            if ($mine !== false) {
                $i = array_search((string)$mine, $rec['items'], true);
                if ($i !== false) array_splice($rec['items'], $i, 1);
                array_unshift($rec['items'], (string)$mine);
                $rec['you'] = true;
            }
        }
        unset($rec);

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
            // In companies the reader cannot access: not listed, and the reason they may not delete it.
            'people_hidden' => $hidden,
            'can_delete' => $hidden === 0,
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
        $src = self::removeSource($conn, $providerId);
        [$where, $args] = self::importedWhere($providerId, $runId, !empty($src['deleted']), $src['created'] ?? []);
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
            $why = self::deleteBlocked($conn, $id);
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
            $src = self::source($conn, $providerId);
            // The same guard as Remove: deleted while the source imports, they would be created again.
            if ($action === 'delete' && self::sourceImports($src)) {
                throw new ServiceError('conflict', 'source_imports', 'Switch this source off, or its import, before deleting the people it imported: the next run would create them again.');
            }
            [$where, $args] = self::staleWhere($providerId, self::days((int)($ctx['days'] ?? 0)));
        } else {
            $src = self::removeSource($conn, $providerId);
            if (self::sourceImports($src)) {
                throw new ServiceError('conflict', 'source_imports', 'Switch this source off, or its import, before removing what it imported: the next run would create everybody again.');
            }
            $runId = isset($ctx['run_id']) && (int)$ctx['run_id'] > 0 ? (int)$ctx['run_id'] : null;
            [$where, $args] = self::importedWhere($providerId, $runId, !empty($src['deleted']), $src['created'] ?? []);
        }

        // Who is in the list now, of the ones picked - read with the same rule as the page.
        [$scope, $sArgs] = peopleScope($conn, $actor->actorId, 'u.tenant_id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $st = $conn->prepare("SELECT u.id, u.display_name, u.preferred_name, u.email, u.username FROM users u
                               WHERE u.id IN ($marks) AND $where $scope");
        $st->execute(array_merge($ids, $args, $sArgs));
        $inList = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $inList[(int)$r['id']] = peopleName($r);

        // Delete (orphans, stale) acts only on the people an import CREATED: the
        // ones it adopted existed before it and are released, never deleted (#261).
        $notCreated = $action === 'delete' && $inList ? self::notCreatedReasons($conn, array_keys($inList), $list) : [];

        $done = $skipped = [];
        foreach ($ids as $id) {
            if (!isset($inList[$id])) { $skipped[] = ['id' => $id, 'name' => null, 'reason' => 'Not in this list any more.']; continue; }
            $name = $inList[$id];
            try {
                if ($action === 'keep') {
                    self::release($conn, $id);
                } elseif ($action === 'left') {
                    // A leaver whose source is gone is released as well, as deleting a
                    // source with "mark them as left" does - or they would stay on this list for good.
                    $release = $list === 'orphans' ? ', is_managed = 0, auth_provider_id = NULL' : '';
                    $conn->prepare("UPDATE users SET is_active = 0, deactivated_datetime = COALESCE(deactivated_datetime, UTC_TIMESTAMP())$release WHERE id = ?")->execute([$id]);
                } else {   // delete, remove
                    if ($why = ($notCreated[$id] ?? null) ?: self::deleteBlocked($conn, $id)) { $skipped[] = ['id' => $id, 'name' => $name, 'reason' => $why]; continue; }
                    userDeletePerson($conn, $id);
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
        // Every company's people change, so it needs an analyst who can access every
        // company - or a source whose people are all in the ones they can (#261).
        if ($n = self::sourcePeopleOutOfReach($conn, $actor, $providerId)) {
            throw new ServiceError('forbidden', 'company_scope',
                "Deleting this source needs access to every company: $n of its people are in companies you cannot access.");
        }
        $st = $conn->prepare("SELECT id FROM users WHERE auth_provider_id = ? AND is_managed = 1");
        $st->execute([$providerId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        // Whom its imports created, read now: the history may go with the source.
        // An install without the import history has nobody recorded.
        $created = [];
        try {
            [$cw, $ca] = self::importedWhere($providerId, null);
            $st = $conn->prepare("SELECT u.id FROM users u WHERE $cw");
            $st->execute($ca);
            $created = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            if (!in_array($e->getCode(), ['42S02', '42S22'], true)) throw $e;
        }
        if ($markLeft) {
            $conn->prepare("UPDATE users SET is_active = 0, deactivated_datetime = COALESCE(deactivated_datetime, UTC_TIMESTAMP())
                             WHERE auth_provider_id = ? AND is_managed = 1 AND is_active = 1")->execute([$providerId]);
        }
        // Every row pointing at the source, flagged or not: by hand a foreign key
        // would NULL them, but Database Verification adds none (see userManagedSql()).
        $conn->prepare("UPDATE users SET is_managed = 0, auth_provider_id = NULL WHERE auth_provider_id = ?")->execute([$providerId]);
        // Logged even with nobody to release: the entry is also where a deleted
        // source's name is kept, for Remove imported people (deletedSources()).
        self::log($conn, $actor, ['list' => 'source_deleted', 'action' => $markLeft ? 'left' : 'keep',
            'provider_id' => $providerId, 'provider' => $providerName, 'done' => $ids, 'skipped' => [],
            'created' => $created]);
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

    /**
     * Created by a live run of this source (one, or any), and still its - or, for a
     * deleted source, managed by no source that exists: deleting it released them,
     * and anybody another source has taken on since is that source's business.
     */
    private static function importedWhere(int $providerId, ?int $runId, bool $deleted = false, array $logged = []): array
    {
        $run = $runId ? ' AND r.id = ?' : '';
        $created = "EXISTS (
                    SELECT 1 FROM directory_sync_entries e JOIN directory_sync_runs r ON r.id = e.run_id
                     WHERE e.user_id = u.id AND e.action = 'created' AND r.provider_id = ? AND r.mode = 'live'$run)";
        if ($deleted) {
            // From the history, and - for "any import" - from the delete's log entry.
            $args = $runId ? [$providerId, $runId] : [$providerId];
            $logged = array_values(array_filter(array_map('intval', $logged), fn($i) => $i > 0));
            if (!$runId && $logged) {
                $created = "($created OR u.id IN (" . implode(',', array_fill(0, count($logged), '?')) . '))';
                $args = array_merge($args, $logged);
            }
            return ['NOT ' . userManagedSql('u') . " AND $created", $args];
        }
        return ["u.auth_provider_id = ? AND u.is_managed = 1 AND $created",
                $runId ? [$providerId, $providerId, $runId] : [$providerId, $providerId]];
    }

    /**
     * The source Remove imported people works on: one that exists, or a deleted one
     * its import history still names - which can import nothing any more.
     */
    private static function removeSource(PDO $conn, int $providerId): array
    {
        try {
            return self::source($conn, $providerId);
        } catch (ServiceError $e) {
            foreach (self::deletedSources($conn) as $d) {
                if ($d['id'] === $providerId) {
                    return ['id' => $providerId, 'display_name' => $d['name'], 'protocol' => '', 'enabled' => 0, 'sync_enabled' => 0,
                            'deleted' => true, 'created' => $d['created']];
                }
            }
            throw $e;
        }
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

    /**
     * Whether deleting a source takes its import history with it: true on an
     * install made from database/freeitsm.sql (directory_sync_runs.provider_id has
     * ON DELETE CASCADE), false on a verified one, which has no foreign keys.
     */
    private static function historyGoesWithSource(PDO $conn): bool
    {
        try {
            $st = $conn->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'directory_sync_runs'
                                   AND REFERENCED_TABLE_NAME = 'auth_providers' AND DELETE_RULE = 'CASCADE'");
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
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
     * Why a person may not be deleted here, or null: tickets or equipment
     * (userDeleteBlockedByHistory()), or any other record that names them
     * (userDeleteBlockedByRecords()) - a bulk tool deletes nobody whose history
     * would lose them. The delete itself is userDeletePerson(), the one way a
     * person is deleted, which also clears what a verified install has no
     * foreign key to clear.
     */
    private static function deleteBlocked(PDO $conn, int $userId): ?string
    {
        return userDeleteBlockedByHistory($conn, $userId) ?? userDeleteBlockedByRecords($conn, $userId);
    }

    /**
     * For Delete on the orphans and stale lists: why each person is NOT one their
     * source's imports created (and so is kept), or null when they are.
     *
     * Created means the import history says a live run of their source created
     * them - or, for a deleted source, the delete's log entry lists them
     * (deletedSources()). Anybody it ADOPTED existed before the import and is
     * never deleted (Ed, #261); where nothing records either, nobody can say, and
     * the person is kept rather than guessed at. On the stale list, somebody no
     * import has ever recorded finding (last_seen_in_source empty) is kept too.
     *
     * @param int[] $ids  users.id, already known to be in $list
     * @return array<int,?string>  id => reason, or null to go ahead
     */
    private static function notCreatedReasons(PDO $conn, array $ids, string $list): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $st = $conn->prepare("SELECT id, auth_provider_id, last_seen_in_source FROM users WHERE id IN ($marks)");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // What the import history says about them, for their own source, where this install has it.
        $hist = [];
        try {
            $h = $conn->prepare(
                "SELECT e.user_id, MAX(e.action = 'created') AS created, MAX(e.action = 'adopted') AS adopted
                   FROM directory_sync_entries e
                   JOIN directory_sync_runs r ON r.id = e.run_id AND r.mode = 'live'
                   JOIN users u ON u.id = e.user_id AND u.auth_provider_id = r.provider_id
                  WHERE e.user_id IN ($marks)
               GROUP BY e.user_id");
            $h->execute($ids);
            foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $r) $hist[(int)$r['user_id']] = $r;
        } catch (PDOException $e) {
            if (!in_array($e->getCode(), ['42S02', '42S22'], true)) throw $e;   // no import history on this install
        }
        // A deleted source: whom its imports created, as its delete's log entry kept them.
        $logged = [];
        if ($list === 'orphans') {
            foreach (self::deletedSources($conn) as $d) {
                foreach ($d['created'] as $uid) $logged[(int)$uid][$d['id']] = true;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $pid = $r['auth_provider_id'] === null ? null : (int)$r['auth_provider_id'];
            if ($list === 'stale' && $r['last_seen_in_source'] === null) {
                $out[$id] = 'No import has recorded finding this person yet - kept.';
            } elseif (!empty($hist[$id]['adopted'])) {
                $out[$id] = 'Existed before the import - kept.';
            } elseif (!empty($hist[$id]['created']) || ($pid !== null && isset($logged[$id][$pid]))) {
                $out[$id] = null;
            } else {
                $out[$id] = 'Nothing records that an import created this person - kept.';
            }
        }
        return $out;
    }

    /** The company filter for the people of a source, as the reader may see them (peopleScope()). */
    private static function deleteScope(PDO $conn, ActorContext $actor): array
    {
        return peopleScope($conn, $actor->actorId, 'u.tenant_id');
    }

    /**
     * How many of the people a source manages are in companies this analyst cannot
     * access - 0 for an analyst who can access every company
     * (analystHasAllTenantAccess()). Deleting the source changes all of them, so
     * it is refused while this is more than 0.
     */
    public static function sourcePeopleOutOfReach(PDO $conn, ActorContext $actor, int $providerId): int
    {
        if (analystHasAllTenantAccess($conn, $actor->actorId)) return 0;
        [$scope, $sArgs] = self::deleteScope($conn, $actor);
        $st = $conn->prepare("SELECT COUNT(*) FROM users u WHERE u.auth_provider_id = ? AND u.is_managed = 1");
        $st->execute([$providerId]);
        $all = (int)$st->fetchColumn();
        $st = $conn->prepare("SELECT COUNT(*) FROM users u WHERE u.auth_provider_id = ? AND u.is_managed = 1 $scope");
        $st->execute(array_merge([$providerId], $sArgs));
        return $all - (int)$st->fetchColumn();
    }

    /** One system log row per write. */
    private static function log(PDO $conn, ActorContext $actor, array $details): void
    {
        $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES ('directory_maintenance', ?, ?, UTC_TIMESTAMP())")
             ->execute([$actor->actorId ?: null, json_encode($details)]);
    }
}
