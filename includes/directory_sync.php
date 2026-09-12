<?php
/**
 * Directory sync — bring people in from LDAP / Active Directory.
 *
 * Sign-in (includes/ldap.php) asks the directory about ONE person who is
 * standing in front of it. This enumerates EVERYBODY, so people exist before
 * anyone signs in — which is the whole point: the staff who hold equipment are
 * largely the staff who never log into anything.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  THE THREE RULES THIS FILE EXISTS TO ENFORCE
 *
 *  1. NOBODY IS EVER DELETED. Assets, tickets and handover documents all hang
 *     off users.id. A person who has left is deactivated, and keeps their history.
 *
 *  2. A RUN THAT LOOKS WRONG CHANGES NOTHING. If the directory suddenly returns
 *     far fewer people than last time, that is far more likely to be a typo in a
 *     base DN, a service account losing read rights, or a slow replica than an
 *     actual redundancy round. The sanity brake stops the run instead of
 *     deactivating a company. See syncBrakeTripped().
 *
 *  3. MISSING ONCE IS NOISE. Nobody is deactivated on a single absence —
 *     sync_missed_count has to reach the provider's threshold first, and any
 *     sighting resets it.
 *
 *  Everything else here is detail. Those three are why it is safe to run.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Preview mode runs the identical code path and writes nothing to `users`, so
 * what you are shown is what would happen — not a separate estimate of it that
 * can drift from the real thing.
 *
 * See the wiki: Directory sync — importing people from Active Directory.
 */

require_once __DIR__ . '/ldap.php';
require_once __DIR__ . '/users.php';

/** How many entries one page of an LDAP search returns. AD caps at 1000. */
const DSYNC_PAGE_SIZE = 500;

/** Hard ceiling on one run, so a misconfigured filter cannot run forever. */
const DSYNC_MAX_PEOPLE = 20000;

/** Active Directory's userAccountControl bit for "account disabled". */
const DSYNC_UAC_DISABLED = 2;

/**
 * Attribute defaults, per directory flavour. These are the names the fixture in
 * docker/ldap-test uses, and the ones real installs overwhelmingly have.
 *
 * ⚠️ AD and OpenLDAP genuinely differ — sAMAccountName vs uid, and OpenLDAP has
 * no equivalent of most of the rest. Guessing is not possible, which is why every
 * one of these is configurable per provider.
 */
function dsyncAttrDefaults(string $flavour): array
{
    $ad = [
        'username'    => 'sAMAccountName',
        'email'       => 'mail',
        'name'        => 'displayName',
        'guid'        => 'objectGUID',
        'job_title'   => 'title',
        'department'  => 'department',
        'office'      => 'physicalDeliveryOfficeName',
        'phone'       => 'telephoneNumber',
        'mobile'      => 'mobile',
        'employee_id' => 'employeeID',
        'manager'     => 'manager',
    ];
    if ($flavour === 'openldap') {
        return array_merge($ad, [
            'username'    => 'uid',
            'name'        => 'cn',
            'guid'        => 'entryUUID',
            'office'      => 'l',
            'phone'       => 'telephoneNumber',
            'employee_id' => 'employeeNumber',
            // OpenLDAP has no manager attribute by convention; left blank so the
            // second pass simply finds nothing rather than erroring.
            'manager'     => '',
        ]);
    }
    return $ad;
}

/**
 * Which flavour is this provider?
 *
 * There is no column recording it: the settings screen applies the flavour's
 * defaults into the real ldap_attr_* columns when the provider is saved, so the
 * row only ever holds concrete attribute names. The sign-in attributes are
 * therefore the evidence — `uid` means OpenLDAP, `sAMAccountName` means AD.
 *
 * Inferring beats adding a column: the existing values are what the directory is
 * actually being queried with, so they cannot disagree with reality the way a
 * separate flavour field could after somebody hand-edits an attribute.
 */
function dsyncFlavour(array $provider): string
{
    $u = strtolower(trim((string)($provider['ldap_attr_username'] ?? '')));
    $g = strtolower(trim((string)($provider['ldap_attr_guid'] ?? '')));
    if ($u === 'uid' || $g === 'entryuuid') return 'openldap';
    return 'ad';
}

/** The configured attribute name for one field, falling back to the flavour default. */
function dsyncAttr(array $provider, string $field): string
{
    $col = 'ldap_attr_' . $field;
    $set = trim((string)($provider[$col] ?? ''));
    if ($set !== '') return $set;
    $d = dsyncAttrDefaults(dsyncFlavour($provider));
    return $d[$field] ?? '';
}

/**
 * Everybody the provider's sync scope contains.
 *
 * ⚠️ PAGED. Active Directory returns at most 1000 entries per search and then
 * simply stops — with no error. An unpaged search against a real company silently
 * imports the first thousand people and reports complete success, which is the
 * single most likely way this feature could lie to somebody.
 *
 * @return array<int,array> raw entries, one per person
 */
/**
 * Split a stored DN list into an array. One per line, blanks dropped.
 *
 * Lower-cased, because a DN is case-insensitive but string comparison is not,
 * and every comparison in this file (is X under Y?) is a string comparison.
 */
function dsyncDnList(?string $raw): array
{
    $out = [];
    foreach (preg_split('/\R/', (string)$raw) as $line) {
        $line = strtolower(trim($line));
        if ($line !== '') $out[$line] = true;   // keyed: a DN listed twice is once
    }
    return array_keys($out);
}

/**
 * Where this provider imports from: which branches, minus which carve-outs.
 *
 * A ticked branch means the whole branch, now and in future — so an OU created
 * under it next year is picked up without anybody remembering to come back
 * here. That is the behaviour that makes this worth having, and it is also why
 * carve-outs exist: "everybody in Staff except Contractors" cannot be said with
 * includes alone once you want new departments to arrive on their own.
 *
 * ⚠️ Falls back to sync_base_dn, then ldap_base_dn. An install upgraded from
 * before the OU browser has neither column set, and MUST go on importing
 * exactly who it imported yesterday.
 */
function dsyncScopes(array $provider): array
{
    $includes = dsyncDnList($provider['sync_ou_includes'] ?? null);
    if (!$includes) {
        $fallback = strtolower(trim((string)($provider['sync_base_dn'] ?? '')))
            ?: strtolower(trim((string)($provider['ldap_base_dn'] ?? '')));
        $includes = $fallback !== '' ? [$fallback] : [];
    }

    // Ticking a parent AND its child is not a mistake, it is what a tree lets
    // you do by accident. Dropping the child means one search instead of two
    // over the same people, and no double counting.
    $roots = [];
    foreach ($includes as $dn) {
        $covered = false;
        foreach ($includes as $other) {
            if ($other !== $dn && dsyncDnIsUnder($dn, $other)) { $covered = true; break; }
        }
        if (!$covered) $roots[] = $dn;
    }

    return ['includes' => $roots, 'excludes' => dsyncDnList($provider['sync_ou_excludes'] ?? null)];
}

/**
 * Is $dn inside the subtree rooted at $ancestor (or the ancestor itself)?
 *
 * ⚠️ The comma matters. Without it "OU=Sales,DC=x" tests true against
 * "OU=WholesaleSales,DC=x", and a carve-out would silently swallow an OU whose
 * name merely ends the same way.
 */
function dsyncDnIsUnder(string $dn, string $ancestor): bool
{
    $dn = strtolower(trim($dn));
    $ancestor = strtolower(trim($ancestor));
    if ($ancestor === '') return false;
    return $dn === $ancestor || str_ends_with($dn, ',' . $ancestor);
}

/**
 * Which of the stored branches no longer exist in the directory.
 *
 * Returns [['dn' => …, 'kind' => 'include'|'exclude'], …].
 *
 * ⚠️ Only checked when the selection came from the OU browser. An install
 * falling back to sync_base_dn has always behaved this way and an unreadable
 * base already surfaces as a search failure — warning about it here would
 * report a "problem" on every run of a perfectly working setup.
 *
 * A base search returning nothing is treated as gone. That conflates "deleted"
 * with "the bind account can no longer read it", which is the right call: the
 * consequence for the import is identical, and so is the thing to go and look at.
 */
function dsyncMissingScopes($ds, array $provider): array
{
    if (trim((string)($provider['sync_ou_includes'] ?? '')) === '') return [];

    $scopes  = dsyncScopes($provider);
    $missing = [];
    foreach (['include' => $scopes['includes'], 'exclude' => $scopes['excludes']] as $kind => $dns) {
        foreach ($dns as $dn) {
            // LDAP_SCOPE_BASE: does this exact entry exist, nothing more.
            $res = @ldap_read($ds, $dn, '(objectClass=*)', ['1.1']);
            if ($res === false || (int)(@ldap_count_entries($ds, $res) ?: 0) === 0) {
                $missing[] = ['dn' => $dn, 'kind' => $kind];
            }
        }
    }
    return $missing;
}

/** Say which branches are gone, and lead with the one that changes who gets in. */
function dsyncMissingScopeMessage(array $gone): string
{
    $ex = array_values(array_filter($gone, fn($g) => $g['kind'] === 'exclude'));
    $in = array_values(array_filter($gone, fn($g) => $g['kind'] === 'include'));
    $bits = [];
    if ($ex) {
        $bits[] = count($ex) . ' branch(es) you had left out no longer exist, so the people in them are being imported: '
                . implode(', ', array_column($ex, 'dn'))
                . '. This usually means somebody renamed or moved that part of the directory.';
    }
    if ($in) {
        $bits[] = count($in) . ' branch(es) you selected no longer exist, so nobody is being imported from them: '
                . implode(', ', array_column($in, 'dn')) . '.';
    }
    $bits[] = 'Open Browse directory and tick again to fix it.';
    return implode(' ', $bits);
}

function dsyncFetchPeople($ds, array $provider): array
{
    $scopes = dsyncScopes($provider);
    if (!$scopes['includes']) {
        throw new Exception('This provider has nowhere to import from. Tick at least one part of the directory.');
    }
    $filter = trim((string)($provider['sync_filter'] ?? ''))
        ?: '(&(objectClass=user)(objectCategory=person))';

    $attrs = array_values(array_unique(array_filter([
        'dn',
        dsyncAttr($provider, 'username'), dsyncAttr($provider, 'email'),
        dsyncAttr($provider, 'name'),     dsyncAttr($provider, 'guid'),
        dsyncAttr($provider, 'job_title'), dsyncAttr($provider, 'department'),
        dsyncAttr($provider, 'office'),   dsyncAttr($provider, 'phone'),
        dsyncAttr($provider, 'mobile'),   dsyncAttr($provider, 'employee_id'),
        dsyncAttr($provider, 'manager'),
        'userAccountControl',   // AD's disabled flag
    ])));

    // Keyed by DN so the same person found under two ticked branches is one
    // person. Overlapping ticks are pruned in dsyncScopes(), but a directory
    // with aliases or referrals can still hand the same entry back twice, and
    // a duplicate would be counted twice by the brake as well as processed
    // twice — the brake comparing an inflated count is the dangerous half.
    $byDn = [];
    foreach ($scopes['includes'] as $baseDn) {
        $cookie = '';
        do {
            $controls = [[
                'oid'   => LDAP_CONTROL_PAGEDRESULTS,
                'value' => ['size' => DSYNC_PAGE_SIZE, 'cookie' => $cookie],
            ]];
            $res = @ldap_search($ds, $baseDn, $filter, $attrs, 0, 0, 0, LDAP_DEREF_NEVER, $controls);
            if ($res === false) {
                // Name the branch. "Directory search failed" over five ticked
                // OUs leaves you guessing which one you cannot read.
                throw new Exception('Directory search failed under ' . $baseDn . ': ' . ldap_error($ds));
            }
            $entries = @ldap_get_entries($ds, $res) ?: ['count' => 0];
            for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
                $entry = $entries[$i];
                $dn    = strtolower((string)($entry['dn'] ?? ''));
                // A carve-out is applied here rather than in the LDAP filter:
                // "not under this subtree" is not something an LDAP filter can
                // express at all, since a filter tests attributes and this
                // tests position in the tree.
                if (dsyncDnIsExcluded($dn, $scopes['excludes'])) continue;
                $byDn[$dn] = $entry;
                if (count($byDn) >= DSYNC_MAX_PEOPLE) {
                    throw new Exception('More than ' . DSYNC_MAX_PEOPLE
                        . ' people matched. Narrow what you have ticked, or the filter — this is almost'
                        . ' certainly enumerating more of the directory than you meant.');
                }
            }
            $cookie = '';
            if (@ldap_parse_result($ds, $res, $errcode, $matcheddn, $errmsg, $referrals, $ctrls)) {
                $cookie = $ctrls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '';
            }
        } while ($cookie !== null && $cookie !== '');
    }

    return array_values($byDn);
}

/** Does this DN sit inside any carved-out branch? */
function dsyncDnIsExcluded(string $dn, array $excludes): bool
{
    foreach ($excludes as $ex) {
        if (dsyncDnIsUnder($dn, $ex)) return true;
    }
    return false;
}

/** One attribute off a raw entry, as a plain string ('' when absent). */
function dsyncValue(array $entry, string $attr): string
{
    if ($attr === '') return '';
    $k = strtolower($attr);
    if (!isset($entry[$k])) return '';
    $v = $entry[$k];
    if (is_array($v)) return trim((string)($v[0] ?? ''));
    return trim((string)$v);
}

/**
 * Turn a raw directory entry into the fields FreeITSM stores.
 * Returns null when the entry has no usable identity at all.
 */
function dsyncMapPerson(array $entry, array $provider): ?array
{
    $username = dsyncValue($entry, dsyncAttr($provider, 'username'));
    $guidRaw  = dsyncValue($entry, dsyncAttr($provider, 'guid'));
    $guid     = $guidRaw !== '' ? ldapStringifyId($guidRaw) : '';
    if ($username === '' && $guid === '') return null;

    $email = strtolower(dsyncValue($entry, dsyncAttr($provider, 'email')));
    $uac   = (int)dsyncValue($entry, 'userAccountControl');

    return [
        'guid'        => $guid,
        'username'    => $username,
        // ⚠️ '' would occupy the unique index and the SECOND mailbox-less person
        // would be rejected as a duplicate. Absent must be NULL.
        'email'       => $email !== '' ? $email : null,
        'name'        => dsyncValue($entry, dsyncAttr($provider, 'name')) ?: $username,
        'job_title'   => dsyncValue($entry, dsyncAttr($provider, 'job_title'))   ?: null,
        'department'  => dsyncValue($entry, dsyncAttr($provider, 'department'))  ?: null,
        'office'      => dsyncValue($entry, dsyncAttr($provider, 'office'))      ?: null,
        'phone'       => dsyncValue($entry, dsyncAttr($provider, 'phone'))       ?: null,
        'mobile'      => dsyncValue($entry, dsyncAttr($provider, 'mobile'))      ?: null,
        'employee_id' => dsyncValue($entry, dsyncAttr($provider, 'employee_id')) ?: null,
        'manager_dn'  => dsyncValue($entry, dsyncAttr($provider, 'manager'))     ?: null,
        'dn'          => strtolower(dsyncValue($entry, 'dn')),
        // AD only. A directory without the attribute reports 0, which reads as
        // "enabled" — the right default, since we should never invent a reason
        // to deactivate somebody.
        'disabled'    => $uac > 0 && ($uac & DSYNC_UAC_DISABLED) === DSYNC_UAC_DISABLED,
    ];
}

/**
 * Find the FreeITSM record for a directory person, in order of reliability.
 *
 *   1. the stored GUID          — survives renames AND moves between OUs
 *   2. the directory username   — for records linked before GUIDs were captured
 *   3. the email address        — a person who already exists here, see §4.2
 *
 * Returns [row|null, howMatched].
 */
function dsyncFindExisting(PDO $conn, array $provider, array $p): array
{
    $pid = (int)$provider['id'];

    if ($p['guid'] !== '') {
        $s = $conn->prepare(
            "SELECT u.* FROM user_sso_identities i JOIN users u ON u.id = i.user_id
              WHERE i.provider_id = ? AND i.subject = ? LIMIT 1"
        );
        $s->execute([$pid, $p['guid']]);
        if ($row = $s->fetch(PDO::FETCH_ASSOC)) return [$row, 'guid'];
    }

    if ($p['username'] !== '') {
        $s = $conn->prepare(
            "SELECT * FROM users WHERE auth_provider_id = ? AND LOWER(directory_username) = LOWER(?) LIMIT 1"
        );
        $s->execute([$pid, $p['username']]);
        if ($row = $s->fetch(PDO::FETCH_ASSOC)) return [$row, 'directory_username'];
    }

    // ⚠️ Email matching is scoped to the provider's company. Two tenants can
    // legitimately share an address (a contractor, a shared admin@), and matching
    // across them would merge two customers' people — a data leak dressed as a
    // convenience.
    if ($p['email'] !== null) {
        $tenantId = $provider['tenant_id'] !== null ? (int)$provider['tenant_id'] : null;
        if ($tenantId === null) {
            $s = $conn->prepare("SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1");
            $s->execute([$p['email']]);
        } else {
            $s = $conn->prepare("SELECT * FROM users WHERE LOWER(email) = ? AND tenant_id = ? LIMIT 1");
            $s->execute([$p['email'], $tenantId]);
        }
        if ($row = $s->fetch(PDO::FETCH_ASSOC)) return [$row, 'email'];
    }

    return [null, ''];
}

/**
 * Has the directory returned so much less than last time that we should refuse
 * to act on it?
 *
 * THE MOST IMPORTANT FUNCTION IN THIS FILE. Without it, one wrong character in a
 * base DN deactivates every person in a company, and the sync reports success
 * while doing it. With it, the run stops and says why.
 *
 * A first run (no baseline) is never braked — there is nothing to compare with,
 * and refusing to import anybody the first time would make the feature unusable.
 */
function syncBrakeTripped(array $provider, int $seen, ?array $words = null): ?string
{
    $pct  = (int)($provider['sync_brake_percent'] ?? 20);
    $last = $provider['sync_last_count'] !== null ? (int)$provider['sync_last_count'] : null;
    if ($pct <= 0 || $last === null || $last === 0) return null;
    if ($seen >= $last) return null;

    $drop = (int)round((($last - $seen) / $last) * 100);
    if ($drop < $pct) return null;

    // The RULE is transport-agnostic; only the likely causes differ. An LDAP
    // drop is usually a base DN or a filter; a CardDAV drop is usually the
    // wrong address book or a group that has been renamed. Defaults preserve
    // the LDAP wording exactly, so nothing about that path changes.
    $source = $words['source'] ?? 'directory';
    $causes = $words['causes'] ?? 'a base DN, a filter or the service account\'s read rights';

    // ⚠️ "%d of them" rather than "%d people": at a drop of one this read
    // "than 1 people actually leaving". There is no pluralisation here to lean
    // on, and putting the count in front of a noun is how you get that.
    return sprintf(
        'Stopped without changing anything: the %s returned %d, down %d%% from %d '
        . 'on the last run. That is far more often %s than %d of them actually leaving. '
        . 'Check the %s, then run again — or raise the safety threshold if the drop is genuine.',
        $source, $seen, $drop, $last, $causes, $last - $seen, $source
    );
}

/** Record one thing a run did to one person. */
function dsyncLogEntry(PDO $conn, int $runId, string $action, ?int $userId, array $p, string $detail = ''): void
{
    try {
        $conn->prepare(
            "INSERT INTO directory_sync_entries
               (run_id, action, user_id, directory_username, display_name, detail, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([
            $runId, $action, $userId,
            mb_substr((string)($p['username'] ?? ''), 0, 255),
            mb_substr((string)($p['name'] ?? ''), 0, 255),
            mb_substr($detail, 0, 1000),
        ]);
    } catch (Throwable $e) {
        // A log write must never break the sync it is logging.
        error_log('[dsync] entry log failed: ' . $e->getMessage());
    }
}

/**
 * Run a sync.
 *
 * @param string $mode 'live' | 'preview'
 * @return array the run row, including counts and status
 */
function directorySyncRun(PDO $conn, array $provider, string $mode = 'live', ?int $analystId = null): array
{
    $preview = ($mode === 'preview');
    $pid     = (int)$provider['id'];

    $conn->prepare(
        "INSERT INTO directory_sync_runs (provider_id, mode, status, started_datetime, triggered_by_analyst_id)
         VALUES (?, ?, 'running', UTC_TIMESTAMP(), ?)"
    )->execute([$pid, $preview ? 'preview' : 'live', $analystId]);
    $runId = (int)$conn->lastInsertId();

    $counts = ['seen' => 0, 'created' => 0, 'updated' => 0, 'adopted' => 0,
               'deactivated' => 0, 'conflict' => 0, 'error' => 0];
    $status  = 'ok';
    $message = '';

    try {
        $ds = ldapOpen($provider);
        ldapBindService($ds, $provider);

        // A ticked branch is stored as a DN, and a DN changes when somebody
        // renames or moves an OU. Both directions fail SILENTLY otherwise:
        //
        //   a missing include — that branch imports nobody, and everyone in it
        //     starts counting towards being marked as left;
        //   a missing exclude — WORSE. The carve-out stops applying and the
        //     people it was keeping out start being imported. The sanity brake
        //     guards against a sudden DROP, so it cannot see this at all.
        //
        // Cheap to check (one existence test per stored branch, and there are
        // usually one or two), and it turns both into something you are told
        // about rather than something you notice months later.
        // The two halves get different treatment, because their failures are
        // not equally bad:
        //
        //   a missing INCLUDE means everybody in that branch looks like they
        //     have left, and after enough runs they are marked as such. That
        //     is destructive, so REFUSE THE WHOLE RUN — importing the branches
        //     that do still exist is exactly how the damage would happen.
        //
        //   a missing EXCLUDE means people you deliberately left out start
        //     arriving. Unwanted, but additive and undoable, so the run goes
        //     ahead and says loudly what it did.
        $gone = dsyncMissingScopes($ds, $provider);
        $goneIn = array_values(array_filter($gone, fn($g) => $g['kind'] === 'include'));
        if ($goneIn) {
            throw new Exception(dsyncMissingScopeMessage($goneIn) . ' Nothing was changed.');
        }
        foreach ($gone as $g) {
            $message = dsyncMissingScopeMessage($gone);
            dsyncLogEntry($conn, $runId, 'error', null,
                ['username' => '', 'name' => 'Missing carve-out'],
                $g['dn'] . ' is no longer in the directory, so the people it was keeping out are being imported.');
            $counts['error']++;
        }

        $raw = dsyncFetchPeople($ds, $provider);

        // Map first, so a person with no usable identity is counted as skipped
        // rather than silently dropped between the directory and the database.
        $people = [];
        foreach ($raw as $entry) {
            $p = dsyncMapPerson($entry, $provider);
            if ($p === null) {
                $counts['error']++;
                dsyncLogEntry($conn, $runId, 'skip', null, ['username' => '', 'name' => ''],
                    'No username and no unique id — nothing to identify this entry by.');
                continue;
            }
            $people[] = $p;
        }
        $counts['seen'] = count($people);

        // ---- the brake, BEFORE anything is written -------------------------
        $brake = syncBrakeTripped($provider, $counts['seen']);
        if ($brake !== null) {
            $status  = 'stopped';
            // Prepend, do not replace: a branch that has vanished is very often
            // WHY the count dropped, and losing the cause to report the effect
            // leaves somebody staring at "far fewer people than last time" with
            // the explanation already discarded.
            $message = $message !== '' ? $brake . "\n\n" . $message : $brake;
            dsyncFinishRun($conn, $runId, $status, $counts, $message);
            return dsyncGetRun($conn, $runId);
        }

        $seenUserIds = [];
        // DN -> user id, built as we go. The manager pass needs it, and a
        // manager is very often somebody this same run has just created.
        $dnToUserId  = [];
        foreach ($people as $p) {
            [$existing, $how] = dsyncFindExisting($conn, $provider, $p);

            // Somebody already here, matched only by email, and the provider is
            // set to leave them alone.
            if ($existing && $how === 'email'
                && (string)($provider['sync_on_conflict'] ?? 'adopt') !== 'adopt') {
                $counts['conflict']++;
                dsyncLogEntry($conn, $runId, 'conflict', (int)$existing['id'], $p,
                    'Already exists here with the same email address. Left untouched, because this '
                    . 'directory is set to flag conflicts rather than adopt them.');
                continue;
            }

            if ($existing) {
                $seenUserIds[] = (int)$existing['id'];
                if ($p['dn'] !== '') $dnToUserId[$p['dn']] = (int)$existing['id'];
                $changes = dsyncApplyToExisting($conn, $provider, $existing, $p, $preview);
                if ($how === 'email') {
                    $counts['adopted']++;
                    dsyncLogEntry($conn, $runId, 'adopt', (int)$existing['id'], $p,
                        'Matched an existing person by email address and linked them to this directory. '
                        . '⚠️ They now sign in through the directory, so any portal password they had '
                        . 'no longer works.' . ($changes ? ' Also updated: ' . $changes : ''));
                } elseif ($changes !== '') {
                    $counts['updated']++;
                    dsyncLogEntry($conn, $runId, 'update', (int)$existing['id'], $p, $changes);
                } else {
                    dsyncLogEntry($conn, $runId, 'unchanged', (int)$existing['id'], $p, '');
                }
                continue;
            }

            // Nobody here yet.
            $newId = dsyncCreate($conn, $provider, $p, $preview);
            if ($newId === null) {
                $counts['error']++;
                dsyncLogEntry($conn, $runId, 'error', null, $p, 'Could not be created — see the server error log.');
            } else {
                $counts['created']++;
                if ($newId > 0) $seenUserIds[] = $newId;
                if ($newId > 0 && $p['dn'] !== '') $dnToUserId[$p['dn']] = $newId;
                // "Would be created." says nothing you can check. A preview is
                // for deciding whether to go ahead, and the decision turns on
                // WHO is arriving — so list what the new record will hold.
                dsyncLogEntry($conn, $runId, 'create', $newId ?: null, $p, dsyncNewPersonSummary($p));
            }
        }

        // ---- second pass: the reporting line --------------------------------
        // `manager` holds a DN, not a name, so it can only be resolved once
        // everybody exists — the manager may well be somebody this same run has
        // just created. Hence a second pass rather than doing it inline.
        dsyncResolveManagers($conn, $people, $dnToUserId, $runId, $preview);

        // ---- people who were NOT seen this run -----------------------------
        $counts['deactivated'] = dsyncHandleMissing($conn, $provider, $seenUserIds, $runId, $preview);

    } catch (Throwable $e) {
        $status  = 'failed';
        $message = $e->getMessage();
        error_log('[dsync] provider ' . $pid . ' failed: ' . $e->getMessage());
    }

    // A preview never becomes the baseline the brake compares against — it
    // changed nothing, so it proves nothing about the state of the world.
    if (!$preview && $status === 'ok') {
        $conn->prepare("UPDATE auth_providers SET sync_last_run_datetime = UTC_TIMESTAMP(), sync_last_count = ? WHERE id = ?")
             ->execute([$counts['seen'], $pid]);
    }

    dsyncFinishRun($conn, $runId, $status, $counts, $message);
    return dsyncGetRun($conn, $runId);
}

/** Update an existing person, returning a human summary of what changed. */
/**
 * A readable name for a person column, for the run log.
 *
 * The log is written once and read later, so it holds English prose rather than
 * translation keys — the same choice the rest of the detail text already makes.
 * A column with no entry here falls back to itself, so adding a field to the
 * sync can never make the log throw.
 */
function dsyncFieldLabel(string $col): string
{
    static $labels = [
        'display_name' => 'Name',
        'job_title'    => 'Job title',
        'department'   => 'Department',
        'office'       => 'Office',
        'phone'        => 'Phone',
        'mobile'       => 'Mobile',
        'employee_id'  => 'Employee number',
        'email'        => 'Email',
    ];
    return $labels[$col] ?? $col;
}

/**
 * What a person arriving for the first time will actually hold.
 *
 * Only the details that are present are listed: padding the line with
 * "Office: (empty)" for everybody who has no office would bury the fields that
 * do carry something. An account with nothing but a name says so plainly, which
 * is itself worth seeing before an import.
 */
function dsyncNewPersonSummary(array $p): string
{
    $bits = [];
    foreach (['email', 'job_title', 'department', 'office', 'employee_id'] as $col) {
        if (($p[$col] ?? null) !== null && $p[$col] !== '') {
            $bits[] = dsyncFieldLabel($col) . ': ' . $p[$col];
        }
    }
    return $bits ? implode('; ', $bits) : 'No details beyond a name.';
}

function dsyncApplyToExisting(PDO $conn, array $provider, array $existing, array $p, bool $preview): string
{
    $changes = [];
    $sets    = [];
    $args    = [];

    // The directory owns these. Compared before writing so an unchanged person
    // is reported as unchanged rather than as an update that did nothing.
    $map = [
        'display_name' => $p['name'],
        'job_title'    => $p['job_title'],
        'department'   => $p['department'],
        'office'       => $p['office'],
        'phone'        => $p['phone'],
        'mobile'       => $p['mobile'],
        'employee_id'  => $p['employee_id'],
        'email'        => $p['email'],
    ];
    foreach ($map as $col => $new) {
        $old = $existing[$col] ?? null;
        if ((string)$old === (string)$new) continue;
        // Never blank an address we already hold just because the directory has
        // none: that would delete somebody's way of signing in.
        if ($col === 'email' && $new === null) continue;
        $sets[] = "$col = ?";
        $args[] = $new;
        // A person reading the run detail should not have to know the database
        // column names. `job_title: … → …` was leaking straight onto the screen.
        $changes[] = dsyncFieldLabel($col) . ': ' . (($old === null || $old === '') ? '(empty)' : $old)
                   . ' → ' . (($new === null || $new === '') ? '(empty)' : $new);
    }

    // Somebody disabled in the directory is marked as left here.
    if ($p['disabled'] && (int)($existing['is_active'] ?? 1) === 1) {
        $sets[] = 'is_active = 0';
        $sets[] = 'deactivated_datetime = UTC_TIMESTAMP()';
        $changes[] = 'marked as left (disabled in the directory)';
    } elseif (!$p['disabled'] && (int)($existing['is_active'] ?? 1) === 0) {
        $sets[] = 'is_active = 1';
        $sets[] = 'deactivated_datetime = NULL';
        $changes[] = 'reactivated (re-enabled in the directory)';
    }

    // Bookkeeping, always — including on an otherwise unchanged person, because
    // "we saw them" is the fact the missing-count rule depends on.
    $sets[] = 'last_seen_in_source = UTC_TIMESTAMP()';
    $sets[] = 'sync_missed_count = 0';
    $sets[] = 'is_managed = 1';
    $sets[] = 'auth_provider_id = ?';       $args[] = (int)$provider['id'];
    if ($p['username'] !== '') { $sets[] = 'directory_username = ?'; $args[] = $p['username']; }

    if (!$preview) {
        $args[] = (int)$existing['id'];
        $conn->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
        dsyncLinkIdentity($conn, (int)$provider['id'], (int)$existing['id'], $p);
    }

    return implode('; ', $changes);
}

/** Create a person. Returns the new id, 0 on a preview, or null on failure. */
function dsyncCreate(PDO $conn, array $provider, array $p, bool $preview): ?int
{
    if ($preview) return 0;
    try {
        $conn->prepare(
            "INSERT INTO users
               (email, display_name, job_title, department, office, phone, mobile, employee_id,
                directory_username, auth_provider_id, tenant_id, is_managed, is_active,
                last_seen_in_source, sync_missed_count, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, UTC_TIMESTAMP(), 0, UTC_TIMESTAMP())"
        )->execute([
            $p['email'], $p['name'], $p['job_title'], $p['department'], $p['office'],
            $p['phone'], $p['mobile'], $p['employee_id'],
            $p['username'] !== '' ? $p['username'] : null,
            (int)$provider['id'],
            $provider['tenant_id'] !== null ? (int)$provider['tenant_id'] : null,
            $p['disabled'] ? 0 : 1,
        ]);
        $id = (int)$conn->lastInsertId();
        dsyncLinkIdentity($conn, (int)$provider['id'], $id, $p);
        return $id;
    } catch (Throwable $e) {
        error_log('[dsync] create failed for ' . ($p['username'] ?: $p['name']) . ': ' . $e->getMessage());
        return null;
    }
}

/** Store the directory's own id for this person, so a rename or OU move is survivable. */
function dsyncLinkIdentity(PDO $conn, int $providerId, int $userId, array $p): void
{
    if ($p['guid'] === '') return;
    try {
        $conn->prepare(
            "INSERT INTO user_sso_identities (user_id, provider_id, subject, email, linked_datetime)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), email = VALUES(email)"
        )->execute([$userId, $providerId, $p['guid'], $p['email']]);
    } catch (Throwable $e) {
        error_log('[dsync] identity link failed: ' . $e->getMessage());
    }
}

/**
 * People this provider manages who were NOT in this run's results.
 *
 * They are not deactivated on the spot — sync_missed_count is incremented, and
 * only somebody who has been missing for the provider's threshold number of runs
 * is marked as left. Rule 3 at the top of this file.
 */
function dsyncHandleMissing(PDO $conn, array $provider, array $seenUserIds, int $runId, bool $preview): int
{
    $after = (int)($provider['sync_deactivate_after'] ?? 3);
    $pid   = (int)$provider['id'];

    $sql  = "SELECT id, display_name, directory_username, sync_missed_count
               FROM users
              WHERE auth_provider_id = ? AND is_managed = 1 AND is_active = 1";
    $args = [$pid];
    if ($seenUserIds) {
        $sql .= " AND id NOT IN (" . implode(',', array_fill(0, count($seenUserIds), '?')) . ")";
        $args = array_merge($args, $seenUserIds);
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    $missing = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deactivated = 0;
    foreach ($missing as $m) {
        $count = (int)$m['sync_missed_count'] + 1;
        $p = ['username' => $m['directory_username'], 'name' => $m['display_name']];

        // 0 means "never deactivate automatically" — a deliberate choice for
        // installs that would rather handle leavers by hand.
        if ($after > 0 && $count >= $after) {
            $deactivated++;
            if (!$preview) {
                $conn->prepare(
                    "UPDATE users SET is_active = 0, deactivated_datetime = UTC_TIMESTAMP(),
                            sync_missed_count = ? WHERE id = ?"
                )->execute([$count, (int)$m['id']]);
            }
            dsyncLogEntry($conn, $runId, 'deactivate', (int)$m['id'], $p,
                ($preview ? 'Would be marked' : 'Marked') . ' as left — not found in the directory for '
                . $count . ' run(s) running.');
        } else {
            if (!$preview) {
                $conn->prepare("UPDATE users SET sync_missed_count = ? WHERE id = ?")
                     ->execute([$count, (int)$m['id']]);
            }
            dsyncLogEntry($conn, $runId, 'skip', (int)$m['id'], $p,
                'Not found in the directory this run (' . $count . ' of ' . $after
                . '). Nothing done yet — missing once is usually the directory, not the person.');
        }
    }
    return $deactivated;
}

/**
 * Second pass: turn each person's manager DN into a manager_id.
 *
 * Separate from the main loop because `manager` is a DN, and the person it names
 * is very often somebody the same run has only just created — resolving inline
 * would work for whoever happened to be processed later and fail for everybody
 * else, which is the worst kind of half-working.
 *
 * A manager OUTSIDE the synced scope is not an error: plenty of organisations
 * have people reporting to someone in an OU nobody chose to import. Those are
 * left unset and counted, rather than logged forty times.
 */
function dsyncResolveManagers(PDO $conn, array $people, array $dnToUserId, int $runId, bool $preview): void
{
    $set = 0; $outside = 0; $looped = 0;

    foreach ($people as $p) {
        if (empty($p['manager_dn']) || $p['dn'] === '') continue;
        $meId = $dnToUserId[$p['dn']] ?? null;
        if ($meId === null) continue;               // preview: nobody was created

        $mgrDn = strtolower(trim($p['manager_dn']));
        $mgrId = $dnToUserId[$mgrDn] ?? null;
        if ($mgrId === null) { $outside++; continue; }
        if ($mgrId === $meId) continue;             // their own manager, per the directory

        // The same loop guard the UI uses. A directory can absolutely contain a
        // cycle — two people covering for each other — and anything walking the
        // chain to find an approver would walk it forever.
        if (!userManagerIsSafe($conn, $meId, $mgrId)) {
            $looped++;
            dsyncLogEntry($conn, $runId, 'skip', $meId, $p,
                'Reporting line not set: the directory says they report to somebody who (directly or '
                . 'indirectly) reports back to them, which would make the chain loop.');
            continue;
        }

        if (!$preview) {
            $conn->prepare("UPDATE users SET manager_id = ? WHERE id = ?")->execute([$mgrId, $meId]);
        }
        $set++;
    }

    if ($set || $outside || $looped) {
        dsyncLogEntry($conn, $runId, 'update', null, ['username' => '', 'name' => 'Reporting lines'],
            sprintf('%d set%s%s.', $set,
                $outside ? sprintf(', %d manager(s) outside the synced area', $outside) : '',
                $looped  ? sprintf(', %d skipped as circular', $looped) : ''));
    }
}

/** Close a run off. */
function dsyncFinishRun(PDO $conn, int $runId, string $status, array $c, string $message): void
{
    $conn->prepare(
        "UPDATE directory_sync_runs
            SET status = ?, finished_datetime = UTC_TIMESTAMP(), seen_count = ?, created_count = ?,
                updated_count = ?, adopted_count = ?, deactivated_count = ?, conflict_count = ?,
                error_count = ?, message = ?
          WHERE id = ?"
    )->execute([
        $status, $c['seen'], $c['created'], $c['updated'], $c['adopted'],
        $c['deactivated'], $c['conflict'], $c['error'], $message ?: null, $runId,
    ]);
}

/** Read a run back. */
function dsyncGetRun(PDO $conn, int $runId): array
{
    $s = $conn->prepare("SELECT * FROM directory_sync_runs WHERE id = ?");
    $s->execute([$runId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}
