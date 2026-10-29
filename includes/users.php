<?php
/**
 * People — the fields that describe a human rather than a login.
 *
 * `users` began as an authentication record: an address, a password hash, an MFA
 * secret. Everything here is the other half — job title, department, office,
 * manager — which is what an asset register, an approval chain and a service desk
 * actually need, and which nothing could store until slice 1 of directory sync.
 *
 * Kept in one file because THREE things will write these columns and they must
 * agree: the users screen, api/tickets/save_user.php, and (slice 2) the directory
 * sync. A list of field names duplicated across three writers is a list that will
 * disagree with itself within a month.
 *
 * See the wiki: Directory sync — importing people from Active Directory.
 */

require_once __DIR__ . '/sso_identity.php';   // SSO profile-sync lock (#155)

/**
 * Every person field that a human may edit through the UI or the API.
 *
 * Deliberately NOT included, because a directory sync owns them and nothing else
 * may write them: `is_managed`, `directory_username`, `last_seen_in_source`,
 * `auth_provider_id`. They are absent from this list rather than guarded
 * elsewhere, so a new endpoint that loops this constant cannot accidentally
 * expose them.
 */
const USER_PERSON_FIELDS = [
    'job_title',
    'department',
    'office',
    'phone',
    'mobile',
    'employee_id',
    'manager_id',
];

/**
 * Of those, the ones a DIRECTORY is the source of truth for.
 *
 * On a record flagged `is_managed`, these are refused rather than saved: the next
 * sync would overwrite them anyway, and an edit that silently reverts an hour
 * later is worse than one that says no. Anything not in this list stays editable
 * on a managed record — FreeITSM owns it, the directory has never heard of it.
 *
 * ⚠️ Keep in step with what the sync actually maps. A field that syncs but is not
 * listed here becomes an edit that vanishes without explanation.
 */
const USER_DIRECTORY_OWNED = [
    'job_title',
    'department',
    'office',
    'phone',
    'mobile',
    'employee_id',
    'manager_id',
];

/**
 * Of USER_DIRECTORY_OWNED, the ones a CARDDAV address book is the source of
 * truth for.
 *
 * 🔴 The full list was wrong for CardDAV and shipped that way in 1.8.0. The
 * constant above says, in its own doc comment, "keep in step with what the sync
 * actually maps" — and `cdsyncMapCard()` returns a hard `null` for `employee_id`
 * and `manager_dn`, because a vCard has nowhere to keep a payroll number and
 * almost nothing in the wild writes `RELATED;TYPE=manager`. So an address-book
 * contact had those two fields greyed out and refused on save, for data no
 * import will ever supply: permanently unfillable, while the help page said the
 * import "leaves them alone" and the code then contradicted it.
 *
 * ⚠️ This is why the question is per-PROTOCOL, not per-record. `is_managed` says
 * somebody else owns this person; it does not say who, or which parts of them.
 *
 * @see cdsyncMapCard() in includes/carddav_sync.php — the other end of this list
 */
const USER_CARDDAV_OWNED = [
    'job_title',    // TITLE
    'department',   // ORG, second component
    'office',       // ADR, the locality
    'phone',        // TEL without a CELL type
    'mobile',       // TEL;TYPE=CELL
];

/**
 * Which person fields are READ-ONLY on a managed record, given the protocol of
 * the provider that manages it and whether that provider writes changes back.
 *
 * 🔴 THE SECOND ARGUMENT IS THE WHOLE POINT OF WRITE-BACK, and leaving it out is
 * a bug that hides well. The reason these fields are refused is that the next
 * import would overwrite anything typed here, so an edit that silently reverts
 * an hour later is worse than one that says no. **An address book with write-back
 * switched on does not have that problem**: the edit is sent to the card, so the
 * next import reads back the value the analyst just typed. The justification for
 * refusing disappears, and the refusal has to disappear with it — otherwise the
 * feature is unreachable, because the save is rejected before anything is pushed.
 *
 * An unrecognised or absent protocol falls back to the FULL list, which is the
 * safe direction: a transport this function has not been taught about is assumed
 * to own everything, so a future importer cannot silently leave fields editable
 * that it then overwrites on its next run.
 *
 * @param ?string $protocol  `auth_providers.protocol` — 'ldap', 'oidc', 'carddav'
 * @param bool    $writeBack `auth_providers.carddav_write_back`; meaningless for
 *                           any other protocol and ignored there
 */
function userDirectoryOwnedFields(?string $protocol, bool $writeBack = false): array
{
    if (strtolower(trim((string)$protocol)) !== 'carddav') {
        return USER_DIRECTORY_OWNED;
    }
    // Write-back on: nothing is read-only. The five it holds are editable
    // because they are sent back, and the other two were never its business.
    return $writeBack ? [] : USER_CARDDAV_OWNED;
}

/**
 * Of those, the ones a person may change about THEMSELVES in the self-service
 * portal.
 *
 * This is the narrowest of the three lists and deliberately so. The question is
 * not "what is harmless?" but "what does this person know better than the
 * service desk?" — their own telephone number, obviously; their own job title
 * after a promotion, reasonably; where they sit, usefully, because the ticket
 * asset picker searches on location and nobody fills that in by hand.
 *
 * 🔴 THE THREE THAT ARE ABSENT MATTER MORE THAN THE FOUR THAT ARE HERE:
 *
 *  - `manager_id` — a requester must never choose their own approver. Nothing
 *    routes along the chain TODAY (`includes/catalogue_approvals.php` sends
 *    catalogue approvals to a designated analyst and calls manager-based
 *    routing "a later slice"), so this is not a live hole. It becomes one the
 *    day that slice lands, and by then a portal full of self-chosen managers
 *    would already be in place. Excluded now, while it costs nothing.
 *  - `employee_id` — a payroll number is an identity claim, not a contact
 *    detail. It is the join key when reconciling against HR, so a person
 *    typing their own would be asserting who they are in another system.
 *  - `department` — an organisational fact the service desk maintains, not
 *    something about the person. `idx_users_department` exists and the asset
 *    search reads it, so self-service reorganisation is somebody else's data
 *    changing under them.
 *
 * ⚠️ A field being in this list still does NOT mean it is editable: a record
 * flagged `is_managed` refuses all of USER_DIRECTORY_OWNED, and every one of
 * these is in that list too. The directory stays the source of truth; this list
 * only governs what an UNMANAGED person may do for themselves.
 */
const USER_SELF_EDITABLE_FIELDS = [
    'job_title',
    'office',
    'phone',
    'mobile',
];

/**
 * Does this person sign in through their linked provider rather than with a
 * FreeITSM password?
 *
 * 🔴 `auth_provider_id > 0` IS NOT THE QUESTION. Five portal paths (sign-in,
 * register, email confirmation, forgotten password, reset) used to treat any
 * linked provider as "signs in elsewhere" - which was true while only LDAP and
 * OIDC could be linked. A CardDAV address book is linked too, and it is a
 * source of contact details, NOT a way to sign in. So every contact imported
 * from an address book was told "this account signs in with single sign-on"
 * and could never use the portal at all, whatever password they were given.
 *
 * Only a CardDAV link counts as local. Anything else - LDAP, OIDC, a provider
 * this code does not recognise, or one that has since been deleted - keeps the
 * old, safe answer: the person signs in elsewhere, and no local password may
 * be planted on the account.
 *
 * @param mixed $providerId  the person's users.auth_provider_id; null or 0 means not linked
 */
function userSignsInElsewhere(PDO $conn, $providerId): bool
{
    $providerId = (int)$providerId;
    if ($providerId <= 0) return false;
    try {
        $st = $conn->prepare("SELECT protocol FROM auth_providers WHERE id = ?");
        $st->execute([$providerId]);
        $protocol = $st->fetchColumn();
    } catch (Throwable) {
        return true;
    }
    return strtolower((string)$protocol) !== 'carddav';
}

// ─── Where a person comes from ───────────────────────────────────────────────
//
// "Source" on the people screens: the directory, address book or sign-in
// provider a person is linked to (users.auth_provider_id), by NAME. The screens
// used to say only "a directory", which could not tell two address books apart
// and called an address book a directory.

/**
 * The SQL for the Source filter. Expects auth_providers joined as `ap`.
 *
 * @param mixed $source '' = everyone, 'local' = linked to nothing, or a provider id
 * @return array{0:string,1:array}
 */
function userSourceFilter($source): array
{
    $source = trim((string)$source);
    // `ap.id`, not `u.auth_provider_id`: a person pointing at a provider that no
    // longer exists belongs with the unlinked, which is how every screen shows them.
    if ($source === 'local') return [' AND ap.id IS NULL', []];
    if (ctype_digit($source) && (int)$source > 0) return [' AND ap.id = ?', [(int)$source]];
    return ['', []];
}

/**
 * Every source the people in scope are linked to, with how many - what the
 * Source filter offers. Counted inside the caller's company scope, so the list
 * never reveals how many people another company has in a shared directory.
 *
 * @param string $tenantSql  activeTenantFilter() for alias `u`
 * @param array $tenantArgs  the bound values that go with $tenantSql
 */
function userSourcesInScope(PDO $conn, string $tenantSql, array $tenantArgs): array
{
    try {
        $st = $conn->prepare(
            "SELECT ap.id, ap.display_name AS name, ap.protocol, COUNT(*) AS people
               FROM users u
               JOIN auth_providers ap ON ap.id = u.auth_provider_id
              WHERE 1=1 $tenantSql
           GROUP BY ap.id, ap.display_name, ap.protocol
           ORDER BY ap.display_name"
        );
        $st->execute($tenantArgs);
        return array_map(function ($r) {
            return ['id' => (int)$r['id'], 'name' => $r['name'],
                    'protocol' => $r['protocol'], 'people' => (int)$r['people']];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable) {
        return [];
    }
}

// ─── What an administrator lets the portal offer ─────────────────────────────
//
// System → Portal profile. Two settings in system_settings:
//
//  - which of USER_SELF_EDITABLE_FIELDS a person may change about themselves.
//    Only ever NARROWS that list: the constant is the ceiling, and a field the
//    constant leaves out (manager, employee ID, department) cannot be switched
//    on from a setting, because the reasons it is out do not depend on anyone's
//    preference.
//  - whether a person whose record comes from a CardDAV address book with
//    write-back on may change those fields too, their change being sent to the
//    address book (GDPR Art. 16, #133). Off by default: it lets customers write
//    into the operator's address book, which is the operator's decision.

const PORTAL_PROFILE_FIELDS_SETTING       = 'portal_profile_fields';
const PORTAL_PROFILE_ADDRESS_BOOK_SETTING = 'portal_profile_address_book';

/**
 * A raw system_settings value, or null when it has never been saved.
 *
 * @param string $key  the system_settings key, e.g. PORTAL_PROFILE_FIELDS_SETTING
 */
function portalProfileSettingRaw(PDO $conn, string $key): ?string
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (Throwable) {
        return null;
    }
}

/**
 * The fields a portal user may change about themselves, in the constant's order.
 *
 * 🔑 NEVER SAVED means all of them, which is what the portal did before this
 * setting existed - an upgrade must not quietly take a field away. SAVED EMPTY
 * means none: an administrator unticking everything meant exactly that.
 */
function portalProfileEditableFields(PDO $conn): array
{
    $raw = portalProfileSettingRaw($conn, PORTAL_PROFILE_FIELDS_SETTING);
    if ($raw === null) return USER_SELF_EDITABLE_FIELDS;
    $chosen = array_filter(array_map('trim', explode(',', $raw)));
    // Intersect with the constant, never the other way round: a value in the
    // setting that the constant does not hold is ignored, not honoured.
    return array_values(array_intersect(USER_SELF_EDITABLE_FIELDS, $chosen));
}

/** May an address-book contact change those fields, their change being sent back? */
function portalProfileAddressBookWrites(PDO $conn): bool
{
    return portalProfileSettingRaw($conn, PORTAL_PROFILE_ADDRESS_BOOK_SETTING) === '1';
}

/**
 * How the portal treats one person's contact details. One answer, used by both
 * the read and the save, so the form and the endpoint cannot disagree.
 *
 * @param int $userId  the portal user (users.id) whose details are being read or saved
 * @return array|null null when the person does not exist. Otherwise:
 *   fields       - what the portal offers them (the administrator's choice)
 *   locked       - of those, the ones they may not change: a directory owns them
 *   address_book - their changes are sent to an address book, and only saved
 *                  here once it has accepted them
 *   managed      - a directory keeps this record up to date at all
 *   row          - the record's current values, for the save's three-way merge
 */
function portalProfileAccess(PDO $conn, int $userId): ?array
{
    $cols = implode(', ', array_map(function ($f) { return 'u.' . $f; }, USER_PERSON_FIELDS));
    // profile_sync_mode only once Database Verification has added it (#155) -
    // naming a missing column here would break My Account for everyone.
    $syncCol = ssoJitColumnsReady($conn) ? 'p.profile_sync_mode' : 'NULL AS profile_sync_mode';
    $st = $conn->prepare(
        "SELECT u.is_managed, u.display_name, p.protocol, p.carddav_write_back, $syncCol, $cols
           FROM users u
      LEFT JOIN auth_providers p ON p.id = u.auth_provider_id
          WHERE u.id = ?"
    );
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) return null;

    $fields  = portalProfileEditableFields($conn);
    $managed = (int)($row['is_managed'] ?? 0) === 1;
    $isCardDav = strtolower((string)($row['protocol'] ?? '')) === 'carddav';

    // 🔴 Write-back on the address book is NOT enough on its own. That switch
    // is about ANALYSTS' edits; letting customers write too is a separate
    // decision, made on System → Portal profile.
    $addressBook = $managed && $isCardDav
        && (int)($row['carddav_write_back'] ?? 0) === 1
        && portalProfileAddressBookWrites($conn);

    // `false` for write-back when working out what is locked, deliberately: when
    // the address book is not taking the customer's change, the next import
    // would put the old value back, so the field must be refused.
    $locked = ($managed && !$addressBook)
        ? array_values(array_intersect($fields, userDirectoryOwnedFields($row['protocol'] ?? null, false)))
        : [];

    // SSO profile sync (discussion #155): an OIDC provider set to 'always'
    // rewrites these on every sign-in, so an edit here would be undone at the
    // next one. Only the five it actually writes - never employee_id or
    // manager_id, which OIDC sync does not touch. Kept apart from $managed,
    // which means "a directory import owns this record" and which the CardDAV
    // write-back rules above depend on.
    if (ssoProfileSyncLocks($row['protocol'] ?? null, $row['profile_sync_mode'] ?? null)) {
        $locked = array_values(array_unique(array_merge(
            $locked,
            array_intersect($fields, USER_CARDDAV_OWNED)   // the same five: job_title, department, office, phone, mobile
        )));
    }

    return [
        'fields'       => $fields,
        'locked'       => $locked,
        'address_book' => $addressBook,
        'managed'      => $managed,
        'row'          => $row,
    ];
}

/**
 * Normalise one incoming person field.
 *
 * Blank always becomes NULL, never '': the columns are nullable so that "not
 * known" is distinguishable from "known to be empty", and an empty string quietly
 * destroys that distinction — the same trap `users.email` documents at length,
 * one column over.
 *
 * @param string $field  the users column, one of USER_PERSON_FIELDS; 'manager_id' is read as an id
 * @param  mixed  $value  raw from the request body
 * @return string|int|null
 */
function userPersonFieldValue(string $field, $value)
{
    if ($field === 'manager_id') {
        // 0 and '' both mean "no manager". A self-reference is refused here
        // rather than by the database, which would allow it happily.
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }
    $v = trim((string)$value);
    return $v === '' ? null : $v;
}

/**
 * Would setting $managerId on $userId create a loop?
 *
 * A manages B manages A is not a hypothetical: it happens whenever two people are
 * each other's cover, and any code that walks the chain to find an approver would
 * then walk it forever. Checked here because the database cannot express it.
 *
 * Returns true if the assignment is SAFE.
 *
 * @param int $userId  the person (users.id) being given a manager
 * @param ?int $managerId  the proposed manager's users.id; null (no manager) is always safe
 */
function userManagerIsSafe(PDO $conn, int $userId, ?int $managerId): bool
{
    if ($managerId === null) return true;
    if ($managerId === $userId) return false;          // your own manager

    $seen = [];
    $at   = $managerId;
    $stmt = $conn->prepare("SELECT manager_id FROM users WHERE id = ?");
    // Bounded as well as cycle-checked: a chain that is somehow already circular
    // in the data must not hang the request while proving it.
    for ($hops = 0; $hops < 50 && $at !== null; $hops++) {
        if ($at === $userId) return false;             // loops back to us
        if (isset($seen[$at])) return true;            // pre-existing loop, not ours
        $seen[$at] = true;
        $stmt->execute([$at]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $at  = $row && $row['manager_id'] !== null ? (int)$row['manager_id'] : null;
    }
    return true;
}

/**
 * The SET fragments that mark a person active or as having left: is_active, and
 * deactivated_datetime stamped in the same statement so "who left this month" is
 * answerable without a second write to miss. The one definition - the person
 * screen (api/tickets/save_user.php) and Starters & leavers both use it.
 *
 * TRAP: on a person kept up to date from a directory (users.is_managed), the next
 * sync switches is_active back on unless the account is disabled in the
 * directory (includes/directory_sync.php). Callers that offer "left" to a
 * managed person must say so.
 *
 * @return list<string>  SQL fragments with no placeholders
 */
function userActiveSets(bool $active): array
{
    return ['is_active = ' . ($active ? '1' : '0'), 'deactivated_datetime = ' . ($active ? 'NULL' : 'UTC_TIMESTAMP()')];
}
