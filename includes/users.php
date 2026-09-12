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
 * Normalise one incoming person field.
 *
 * Blank always becomes NULL, never '': the columns are nullable so that "not
 * known" is distinguishable from "known to be empty", and an empty string quietly
 * destroys that distinction — the same trap `users.email` documents at length,
 * one column over.
 *
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
