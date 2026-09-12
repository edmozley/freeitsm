<?php
/**
 * Importing people from a CardDAV address book.
 *
 * ⭐ THIS FILE IS DELIBERATELY SMALL, AND THAT IS THE POINT.
 *
 * Directory sync's policy layer turned out to be genuinely transport-agnostic
 * rather than merely named that way: `dsyncFindExisting()`,
 * `dsyncApplyToExisting()`, `dsyncCreate()`, `dsyncLinkIdentity()` and
 * `dsyncHandleMissing()` contain **no LDAP references at all**. They consume a
 * normalised person array and write plain `users` columns.
 *
 * So this file does exactly two things the LDAP path does differently:
 *
 *   1. turns a vCard into that same person array, and
 *   2. decides which cards are in scope.
 *
 * Everything that was expensive to get right — nobody is ever deleted, a run
 * that returns far fewer people changes nothing, missing once is noise, and
 * preview runs the identical code path — is reused untouched. A second
 * implementation of those rules would be a second set of ways to lose somebody's
 * history.
 *
 * Asked for in https://github.com/edmozley/freeitsm/issues/133.
 * Wiki: CardDAV Contact Sync — Developer Guide.
 */

require_once __DIR__ . '/carddav.php';
require_once __DIR__ . '/directory_sync.php';   // the whole policy layer

/** Hard ceiling on one run, so a vast address book cannot run forever. */
const CDSYNC_MAX_CONTACTS = 20000;

/**
 * Turn one vCard into the person array the policy layer expects.
 *
 * 🔑 NO CONFIGURABLE FIELD MAPPING, and that is a real difference from LDAP
 * rather than a shortcut. Directories disagree about everything — `mail` versus
 * `userPrincipalName`, `sAMAccountName` versus `uid` — which is why
 * `auth_providers` carries eleven `ldap_attr_*` columns. vCard is a
 * *specification*: `FN`, `EMAIL`, `TEL`, `TITLE` and `ORG` mean the same thing
 * on every server that speaks it. Offering a mapping screen would invite
 * somebody to break a working import by editing settings that only have one
 * correct answer.
 *
 * The genuinely ambiguous choices are made here, in the open:
 *
 *   phone / mobile — `TEL;TYPE=CELL` is the mobile; anything else is the phone,
 *                    preferring one marked WORK. A card with one untyped number
 *                    puts it in `phone`, because that is the field a service
 *                    desk rings first.
 *   department     — `ORG` is a *structured* value, `Organisation;Department`,
 *                    so the second component is the department. Most clients
 *                    write only the first, which correctly yields nothing.
 *   office         — the locality (town) from `ADR`, which is the part that
 *                    answers "which site are they at". The full address is not
 *                    stored: FreeITSM has nowhere for it and inventing a column
 *                    to hold data nobody asked for is how tables rot.
 *   employee_id    — NOT MAPPED. vCard has no payroll-number property, and
 *                    guessing one from `UID` would put an opaque server
 *                    identifier into a field an analyst reads as an HR number.
 *
 * @return array|null null when the card cannot be a person — no UID, or it is
 *                    a group card rather than somebody.
 */
function cdsyncMapCard(string $vcard, string $href): ?array
{
    $lines = cardDavUnfold($vcard);

    // A KIND:group card is a container, not a person. "ITSM" is not somebody
    // called ITSM, and importing one creates a contact nobody can explain.
    if (strtolower(trim(cardDavProperty($lines, 'KIND')[0] ?? '')) === 'group') {
        return null;
    }

    $uid = trim(cardDavProperty($lines, 'UID')[0] ?? '');
    // ⚠️ The UID is the identity, and without one there is nothing stable to
    // match on across runs — the next run would create a second copy of the
    // same person. Skipped rather than imported on a name, which is not unique.
    if ($uid === '') return null;

    $emails = cardDavProperty($lines, 'EMAIL');
    $email  = '';
    foreach ($emails as $e) {
        $e = strtolower(trim($e));
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) { $email = $e; break; }
    }

    [$phone, $mobile] = cdsyncPhones($lines);
    $org = cdsyncOrg($lines);

    $name = trim(cardDavProperty($lines, 'FN')[0] ?? '');
    if ($name === '') {
        // FN is mandatory in the spec and absent in the wild. Fall back to the
        // structured name, then the email, then the UID — anything but a blank
        // row in the people list.
        $n = cardDavProperty($lines, 'N')[0] ?? '';
        $parts = array_values(array_filter(array_map('trim', explode(';', $n))));
        $name = $parts ? trim(($parts[1] ?? '') . ' ' . ($parts[0] ?? '')) : '';
        if ($name === '') $name = $email !== '' ? $email : $uid;
    }

    return [
        // The policy layer calls this 'guid' because for LDAP it is objectGUID.
        // Here it is the vCard UID: same job, same guarantees — it survives a
        // rename and a move between address books.
        'guid'        => $uid,
        // A contact has no sign-in name. '' is correct and the policy layer
        // already stores NULL for it rather than occupying a unique index.
        'username'    => '',
        'email'       => $email !== '' ? $email : null,
        'name'        => $name,
        'job_title'   => trim(cardDavProperty($lines, 'TITLE')[0] ?? '') ?: null,
        'department'  => $org['department'],
        'office'      => cdsyncLocality($lines),
        'phone'       => $phone,
        'mobile'      => $mobile,
        // See the docblock: vCard has no payroll number, so this stays empty
        // rather than being filled with something that only looks like one.
        'employee_id' => null,
        // No manager relationship in a plain vCard. RELATED;TYPE=manager exists
        // in RFC 6350 and is written by almost nothing, so the chain is simply
        // not imported rather than half-imported.
        'manager_dn'  => null,
        // Used for logging, and the equivalent of a DN for display purposes.
        'dn'          => $href,
        // Nothing in a vCard says "this person has left".
        'disabled'    => false,
    ];
}

/**
 * Split a card's telephone numbers into a phone and a mobile.
 *
 * ⚠️ `TEL;TYPE=CELL` and `TEL;TYPE="voice,cell"` and `TEL;TYPE=cell;TYPE=voice`
 * are all legal ways to say the same thing, so the whole parameter string is
 * searched for the word rather than compared to it.
 */
function cdsyncPhones(array $lines): array
{
    $mobile = null; $work = null; $other = null;

    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) continue;
        $left = strtoupper(substr($line, 0, $colon));
        $semi = strpos($left, ';');
        $prop = trim($semi === false ? $left : substr($left, 0, $semi));
        if ($prop !== 'TEL') continue;

        $params = $semi === false ? '' : $left;
        $value  = trim(substr($line, $colon + 1));
        if ($value === '') continue;

        if (strpos($params, 'CELL') !== false || strpos($params, 'MOBILE') !== false) {
            if ($mobile === null) $mobile = $value;
        } elseif (strpos($params, 'WORK') !== false) {
            if ($work === null) $work = $value;
        } elseif ($other === null) {
            $other = $value;
        }
    }
    // A work number wins; failing that any non-mobile number, because a single
    // untyped number on a card is the number somebody wants rung.
    return [$work ?? $other, $mobile];
}

/**
 * Pull the department out of a structured `ORG` value.
 *
 * `ORG:Acme Ltd;Finance` — component 1 is the organisation and component 2 the
 * department. FreeITSM has nowhere to put the organisation itself (a person's
 * company is `users.tenant_id`, a real relationship rather than a string), so
 * only the department is taken.
 */
function cdsyncOrg(array $lines): array
{
    $raw = cardDavProperty($lines, 'ORG')[0] ?? '';
    if ($raw === '') return ['organisation' => null, 'department' => null];
    // An escaped semicolon is part of a value, not a separator.
    $parts = preg_split('/(?<!\\\\);/', $raw);
    $clean = array_map(function ($s) { return trim(str_replace(['\\;', '\\,'], [';', ','], $s)); }, $parts);
    return [
        'organisation' => ($clean[0] ?? '') !== '' ? $clean[0] : null,
        'department'   => ($clean[1] ?? '') !== '' ? $clean[1] : null,
    ];
}

/**
 * The locality (town) from `ADR`, which is the part that answers "which site".
 *
 * ADR has seven components: PO box, extended, street, locality, region,
 * postcode, country. The fourth is the town.
 */
function cdsyncLocality(array $lines): ?string
{
    foreach (cardDavProperty($lines, 'ADR') as $raw) {
        $parts = preg_split('/(?<!\\\\);/', $raw);
        $town  = trim(str_replace(['\\;', '\\,'], [';', ','], $parts[3] ?? ''));
        if ($town !== '') return $town;
    }
    return null;
}

/**
 * Which cards are in scope, resolved BEFORE anything is imported.
 *
 * 🔴 Group scope needs TWO passes and cannot be done card by card. A
 * `KIND:group` card lists its members by UID, so you have to read the group
 * card first and only then know whether an ordinary card belongs. Filtering in
 * one pass silently imports nobody when the group card happens to sort after
 * its members — which it usually does, alphabetically.
 *
 * ⚠️ MEMBER values are URIs: `urn:uuid:alice`, `mailto:a@b.c`, or a bare UID.
 * All three are legal and clients differ, so the scheme is stripped and both
 * the UID and the email are accepted as ways of naming a member.
 *
 * @return array ['uids' => set of in-scope UIDs, 'emails' => set of in-scope emails]
 *               or null when the scope is 'all' and everything is in scope.
 */
function cdsyncResolveScope(array $cards, string $scope, array $wanted): ?array
{
    if ($scope === 'all' || !$wanted) return null;

    $wantedLower = array_map('mb_strtolower', $wanted);

    if ($scope === 'category') {
        // A tag lives on each card, so one pass is enough — but the comparison
        // is case-insensitive, because clients do not agree on case and
        // "ITSM" and "itsm" are the same tag to every one of them.
        $uids = [];
        foreach ($cards as $card) {
            $lines = cardDavUnfold($card['vcard']);
            if (strtolower(trim(cardDavProperty($lines, 'KIND')[0] ?? '')) === 'group') continue;
            foreach (cardDavProperty($lines, 'CATEGORIES') as $rawCats) {
                foreach (preg_split('/(?<!\\\\),/', $rawCats) as $cat) {
                    $cat = mb_strtolower(trim(str_replace('\\,', ',', $cat)));
                    if ($cat !== '' && in_array($cat, $wantedLower, true)) {
                        $uid = trim(cardDavProperty($lines, 'UID')[0] ?? '');
                        if ($uid !== '') $uids[$uid] = true;
                    }
                }
            }
        }
        return ['uids' => $uids, 'emails' => []];
    }

    // scope === 'group': find the chosen group cards, then their members.
    $uids = []; $emails = [];
    foreach ($cards as $card) {
        $lines = cardDavUnfold($card['vcard']);
        if (strtolower(trim(cardDavProperty($lines, 'KIND')[0] ?? '')) !== 'group') continue;

        $uid  = trim(cardDavProperty($lines, 'UID')[0] ?? '');
        $name = trim(cardDavProperty($lines, 'FN')[0] ?? '');
        // Matched on either, because the picker stores the UID but a group
        // recreated on the server gets a new UID and keeps its name.
        $isWanted = in_array(mb_strtolower($uid), $wantedLower, true)
                 || in_array(mb_strtolower($name), $wantedLower, true);
        if (!$isWanted) continue;

        foreach (cardDavProperty($lines, 'MEMBER') as $member) {
            $member = trim($member);
            if ($member === '') continue;
            if (stripos($member, 'mailto:') === 0) {
                $emails[mb_strtolower(substr($member, 7))] = true;
                continue;
            }
            // urn:uuid:xxx, or a bare UID. Take the last colon-separated part.
            $pos = strrpos($member, ':');
            $uidPart = $pos === false ? $member : substr($member, $pos + 1);
            if ($uidPart !== '') $uids[$uidPart] = true;
        }
    }
    return ['uids' => $uids, 'emails' => $emails];
}

/** Is this mapped person inside the resolved scope? */
function cdsyncInScope(?array $resolved, array $p): bool
{
    if ($resolved === null) return true;                     // scope = all
    if (isset($resolved['uids'][$p['guid']])) return true;
    if ($p['email'] !== null && isset($resolved['emails'][$p['email']])) return true;
    return false;
}

/**
 * Run an import for one CardDAV provider.
 *
 * Mirrors `directorySyncRun()` and shares its bookkeeping table, its counts and
 * every one of its safety rules — including preview, which runs this identical
 * code path and writes nothing to `users`.
 *
 * @param string $mode 'live' | 'preview'
 */
function cardDavSyncRun(PDO $conn, array $provider, string $mode = 'live', ?int $analystId = null): array
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
    $status = 'ok';
    $message = '';

    try {
        $book = trim((string)($provider['carddav_addressbook'] ?? ''));
        if ($book === '') {
            throw new RuntimeException('No address book has been chosen for this source yet. Open it, press Test connection and pick one.');
        }

        $cfg = cardDavConfigFromProvider($provider);
        $res = cardDavFetchCards($cfg, $book);
        if (!$res['ok']) {
            throw new RuntimeException($res['error'] !== '' ? $res['error'] : 'The address book could not be read.');
        }
        $cards = $res['cards'];
        if (count($cards) > CDSYNC_MAX_CONTACTS) {
            $cards = array_slice($cards, 0, CDSYNC_MAX_CONTACTS);
        }

        $scope   = (string)($provider['carddav_scope'] ?? 'all');
        $wanted  = cardDavScopeList($provider['carddav_scope_value'] ?? '');
        $resolved = cdsyncResolveScope($cards, $scope, $wanted);

        // ⚠️ A scope that resolves to NOTHING must stop the run, not import
        // nobody and then mark every existing contact as missing. A renamed
        // group on the server is far more likely than everyone leaving at once,
        // and this is the same reasoning as the sanity brake.
        if ($resolved !== null && !$resolved['uids'] && !$resolved['emails']) {
            throw new RuntimeException(sprintf(
                'Stopped without changing anything: nothing in that address book matches %s. '
                . 'That is far more often a group or tag renamed on the server than everybody '
                . 'leaving it, so no contact has been touched. Open Test connection to see what '
                . 'the address book actually contains.',
                $scope === 'group' ? 'the chosen group' : 'the chosen tag'
            ));
        }

        // --- map, filter, and count what we are actually going to act on ---
        $people = [];
        foreach ($cards as $card) {
            $p = cdsyncMapCard($card['vcard'], $card['href']);
            if ($p === null) continue;                       // group card, or no UID
            if (!cdsyncInScope($resolved, $p)) continue;
            $people[] = $p;
        }
        $counts['seen'] = count($people);

        // Rule 2: a run that looks wrong changes nothing.
        $brake = syncBrakeTripped($provider, $counts['seen'], [
            'source' => 'address book',
            'causes' => 'the wrong address book, or a group renamed on the server',
        ]);
        if ($brake !== null) {
            dsyncFinishRun($conn, $runId, 'refused', $counts, $brake);
            return ['run_id' => $runId, 'status' => 'refused', 'message' => $brake, 'counts' => $counts];
        }

        // --- the policy layer, reused verbatim ---
        //
        // ⚠️ `dsyncApplyToExisting()` returns a DESCRIPTION OF THE CHANGES, not
        // an action keyword. Written as `if ($action === 'updated')` this loop
        // compiled, ran, imported everybody correctly — and counted nothing,
        // because no branch ever matched. The data was right and the run summary
        // and History tab both said "0 updated" while a job title, department
        // and office had all changed. Found by changing a card on the server
        // and reading the counts, not by reading the code.
        //
        // Whether somebody was ADOPTED rather than updated comes from `$how`,
        // which says which rung of dsyncFindExisting() matched: an email match
        // means a person who already existed here and is now linked to this
        // source, and that deserves its own word because their portal password
        // stops working.
        $seenUserIds = [];
        foreach ($people as $p) {
            try {
                [$existing, $how] = dsyncFindExisting($conn, $provider, $p);

                // Already here, matched only by email, and this source is set to
                // flag rather than adopt.
                if ($existing && $how === 'email'
                    && (string)($provider['sync_on_conflict'] ?? 'adopt') !== 'adopt') {
                    $counts['conflict']++;
                    dsyncLogEntry($conn, $runId, 'conflict', (int)$existing['id'], $p,
                        'Already exists here with the same email address. Left untouched, because this '
                        . 'address book is set to flag conflicts rather than adopt them.');
                    continue;
                }

                if ($existing) {
                    $seenUserIds[] = (int)$existing['id'];
                    $changes = dsyncApplyToExisting($conn, $provider, $existing, $p, $preview);
                    if ($how === 'email') {
                        $counts['adopted']++;
                        dsyncLogEntry($conn, $runId, 'adopt', (int)$existing['id'], $p,
                            'Matched an existing person by email address and linked them to this address book. '
                            . '⚠️ Their contact details are now maintained there, so they can no longer be '
                            . 'edited in FreeITSM.' . ($changes ? ' Also updated: ' . $changes : ''));
                    } elseif ($changes !== '') {
                        $counts['updated']++;
                        dsyncLogEntry($conn, $runId, 'update', (int)$existing['id'], $p, $changes);
                    } else {
                        dsyncLogEntry($conn, $runId, 'unchanged', (int)$existing['id'], $p, '');
                    }
                    continue;
                }

                $newId = dsyncCreate($conn, $provider, $p, $preview);
                if ($newId === null) {
                    $counts['error']++;
                    dsyncLogEntry($conn, $runId, 'error', null, $p, 'Could not be created — see the server error log.');
                } else {
                    $counts['created']++;
                    if ($newId > 0) $seenUserIds[] = $newId;
                    dsyncLogEntry($conn, $runId, 'created', $newId ?: null, $p, dsyncNewPersonSummary($p));
                }
            } catch (Throwable $e) {
                $counts['error']++;
                error_log('[cdsync] ' . ($p['guid'] ?? '?') . ': ' . $e->getMessage());
            }
        }

        // Rule 1 and 3: nobody is deleted, and missing once is noise.
        $counts['deactivated'] = dsyncHandleMissing($conn, $provider, $seenUserIds, $runId, $preview);

        if (!$preview) {
            $conn->prepare(
                "UPDATE auth_providers
                    SET sync_last_run_datetime = UTC_TIMESTAMP(), sync_last_count = ?
                  WHERE id = ?"
            )->execute([$counts['seen'], $pid]);
        }

        // ⚠️ Counts AFTER a label, never in front of a noun: "Read 1 contacts"
        // is what `%d contacts` gives you, and there is no pluralisation here
        // to lean on. Same reason the brake message says "%d of them".
        $message = sprintf(
            'Contacts read: %d. Created: %d. Updated: %d. Adopted: %d. Marked as left: %d.',
            $counts['seen'], $counts['created'], $counts['updated'], $counts['adopted'], $counts['deactivated']
        );
        if ($counts['error'] > 0)    $message .= sprintf(' Could not be imported: %d.', $counts['error']);
        if ($counts['conflict'] > 0) $message .= sprintf(' Left alone as a conflict: %d.', $counts['conflict']);

    } catch (Throwable $e) {
        $status  = 'error';
        $message = $e->getMessage();
    }

    dsyncFinishRun($conn, $runId, $status, $counts, $message);
    return ['run_id' => $runId, 'status' => $status, 'message' => $message, 'counts' => $counts];
}
