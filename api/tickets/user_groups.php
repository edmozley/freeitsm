<?php
/**
 * API Endpoint: people groups — a named bag of analysts and portal users.
 * Actions: list, get, search, create, update, delete, add_member, remove_member
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS IS THE FRONT DOOR TO A TABLE THAT ALREADY EXISTED
 *
 * `knowledge_user_groups` was created with the Knowledge ACLs, complete with
 * membership resolution (includes/knowledge/visibility.php), enforcement, and a
 * "Group" row in the folder-permission picker. What it never had was a way to
 * MAKE one — the only INSERT anywhere in the product was in its own test. So the
 * picker offered a kind of principal that could not exist, on every install.
 *
 * This endpoint is that missing screen. The table keeps its `knowledge_` name
 * deliberately: renaming it would mean a migration whose failure mode is every
 * membership on the install silently evaporating (db_verify only ever CREATEs,
 * so it would make an empty table under the new name and leave the populated one
 * orphaned beside it). The name is invisible to users; the risk is not.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY WRITES ARE ADMINISTRATOR-ONLY
 *
 * A group grants nothing by itself. But once one is on a folder's access list,
 * ADDING SOMEBODY TO IT IS A GRANT — made from a screen that is gated on the
 * tickets module, to a folder governed by Knowledge. Without this floor, anyone
 * holding Tickets could put themselves in "Payroll" and read it.
 *
 * That is the same rule api/knowledge/permissions.php already states for editing
 * an access list directly, and the same one that put analyst and team management
 * in the System module. Reading the groups is left on plain module access: an
 * analyst looking at a requester should be able to see what they belong to.
 * ─────────────────────────────────────────────────────────────────────────────
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/timezone.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$analystId = (int)$_SESSION['analyst_id'];
$method    = $_SERVER['REQUEST_METHOD'];
$input     = [];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
} else {
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? '';
}

/** The two kinds of person a group can hold. Mirrors knowledge_user_group_members.member_type. */
const GROUP_MEMBER_TYPES = ['analyst', 'user'];

try {
    $conn = connectToDatabase();

    switch ($action) {
        case 'list':          handleList($conn, $analystId); break;
        case 'get':           handleGet($conn, $analystId, (int)($_GET['id'] ?? 0)); break;
        case 'search':        handleSearch($conn, $analystId, (string)($_GET['q'] ?? '')); break;

        // Everything past here changes who can see what.
        case 'create':        requireAdminJson($conn); handleCreate($conn, $analystId, $input); break;
        case 'update':        requireAdminJson($conn); handleUpdate($conn, $input); break;
        case 'delete':        requireAdminJson($conn); handleDelete($conn, $input); break;
        case 'add_member':    requireAdminJson($conn); handleAddMember($conn, $analystId, $input); break;
        case 'remove_member': requireAdminJson($conn); handleRemoveMember($conn, $input); break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('user_groups: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Something went wrong']);
}

/**
 * Every active group with its member count.
 *
 * ⚠️ The count is the TRUE one, not the one this analyst can see. A group's size
 * is a fact about the group; hiding part of it would make "12 members" and a list
 * of nine disagree with no explanation. handleGet() says how many are withheld.
 *
 * `expired_count` is separate because an expired membership is still a row —
 * visibility.php applies the clock at READ time, so the row stays and simply
 * stops counting. A member list that showed them as current would be lying.
 */
function handleList(PDO $conn, int $analystId): void
{
    $sql = "SELECT g.id, g.name, g.description, g.created_datetime,
                   (SELECT COUNT(*) FROM knowledge_user_group_members m
                     WHERE m.group_id = g.id
                       AND (m.expires_at IS NULL OR m.expires_at > UTC_TIMESTAMP())) AS member_count,
                   (SELECT COUNT(*) FROM knowledge_user_group_members m
                     WHERE m.group_id = g.id
                       AND m.expires_at IS NOT NULL AND m.expires_at <= UTC_TIMESTAMP()) AS expired_count
              FROM knowledge_user_groups g
             WHERE g.is_active = 1
          ORDER BY g.name";
    $groups = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($groups as &$g) {
        $g['id']            = (int)$g['id'];
        $g['member_count']  = (int)$g['member_count'];
        $g['expired_count'] = (int)$g['expired_count'];
    }
    unset($g);

    echo json_encode(['success' => true, 'groups' => $groups, 'can_manage' => analystIsAdmin($conn, $analystId)]);
}

/**
 * One group and its members.
 *
 * Portal users are scoped to the analyst's active company, exactly as
 * api/tickets/get_users.php scopes its list — a group is a searchable way to
 * read out names, and it must not become the way round that. Analysts are staff
 * and are not company-scoped.
 *
 * Anything withheld is COUNTED and reported, never silently dropped: an admin
 * deciding whether a group is right needs to know it has members they can't see.
 */
function handleGet(PDO $conn, int $analystId, int $groupId): void
{
    if ($groupId <= 0) { echo json_encode(['success' => false, 'error' => 'No group given']); return; }

    $st = $conn->prepare("SELECT id, name, description FROM knowledge_user_groups WHERE id = ? AND is_active = 1");
    $st->execute([$groupId]);
    $group = $st->fetch(PDO::FETCH_ASSOC);
    if (!$group) { echo json_encode(['success' => false, 'error' => 'Group not found']); return; }

    $group['id'] = (int)$group['id'];

    // Analyst members.
    $st = $conn->prepare(
        "SELECT m.member_type, m.member_id, m.expires_at, a.full_name AS name, a.username AS secondary
           FROM knowledge_user_group_members m
           JOIN analysts a ON a.id = m.member_id
          WHERE m.group_id = ? AND m.member_type = 'analyst'
       ORDER BY a.full_name"
    );
    $st->execute([$groupId]);
    $members = $st->fetchAll(PDO::FETCH_ASSOC);

    // Portal-user members, company-scoped.
    list($uSql, $uParams) = activeTenantFilter($conn, $analystId, 'u');
    $st = $conn->prepare(
        "SELECT m.member_type, m.member_id, m.expires_at,
                COALESCE(NULLIF(u.display_name, ''), u.email, u.username) AS name,
                COALESCE(u.email, u.username) AS secondary
           FROM knowledge_user_group_members m
           JOIN users u ON u.id = m.member_id
          WHERE m.group_id = ? AND m.member_type = 'user'" . $uSql . "
       ORDER BY name"
    );
    $st->execute(array_merge([$groupId], $uParams));
    $members = array_merge($members, $st->fetchAll(PDO::FETCH_ASSOC));

    // How many rows exist in total, so anything the scope removed can be owned up
    // to. A member whose analyst/user row has been deleted outright is counted
    // here too — it is still a row, and still worth an admin knowing about.
    $st = $conn->prepare("SELECT COUNT(*) FROM knowledge_user_group_members WHERE group_id = ?");
    $st->execute([$groupId]);
    $total = (int)$st->fetchColumn();

    $now = new DateTime('now', new DateTimeZone('UTC'));
    foreach ($members as &$m) {
        $m['member_id']  = (int)$m['member_id'];
        $m['is_expired'] = !empty($m['expires_at'])
            && new DateTime($m['expires_at'], new DateTimeZone('UTC')) <= $now;

        // 🔑 THE CALENDAR DAY THAT WAS TYPED, SENT SEPARATELY FROM THE INSTANT.
        //
        // `expires_at` is an instant, because that is what the access check needs
        // — but "their last day is the 30th" is a DATE, and the two stop agreeing
        // the moment the reader's display zone differs from the installation's.
        // Measured: an end-of-day stored as 2026-11-30 23:59:59 UTC renders as
        // "1 December" to a viewer one hour east. Being told somebody's access
        // ends on a different day from the one you typed is not a rounding error,
        // it is the screen contradicting you.
        //
        // So the day is converted back HERE, in the zone it was picked in, and the
        // page renders it with fmtNaiveDate() — no client-side zone maths at all.
        // The instant is still what decides access. (The fourth kind of stored
        // date, GH #116; see Timezones-and-Time-Handling.)
        $m['expires_on'] = null;
        if (!empty($m['expires_at'])) {
            $dt = new DateTime($m['expires_at'], new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $m['expires_on'] = $dt->format('Y-m-d');
        }
    }
    unset($m);

    echo json_encode([
        'success'     => true,
        'group'       => $group,
        'members'     => $members,
        'hidden_count'=> max(0, $total - count($members)),
        'can_manage'  => analystIsAdmin($conn, $analystId),
    ]);
}

/**
 * Find people to add — analysts and portal users in one list, because you know
 * the NAME of who you want, not which table they are in. Same shape as the
 * Knowledge principal picker.
 */
function handleSearch(PDO $conn, int $analystId, string $q): void
{
    $q = trim($q);
    if (mb_strlen($q) < 2) { echo json_encode(['success' => true, 'results' => []]); return; }
    $like = '%' . $q . '%';
    $out  = [];

    $st = $conn->prepare(
        "SELECT id, full_name AS name, username AS secondary
           FROM analysts
          WHERE is_active = 1 AND (full_name LIKE ? OR username LIKE ?)
       ORDER BY full_name LIMIT 10"
    );
    $st->execute([$like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['member_type' => 'analyst', 'member_id' => (int)$r['id'],
                  'name' => (string)$r['name'], 'secondary' => (string)($r['secondary'] ?? '')];
    }

    // Company-scoped, for the reason spelled out at length in get_users.php: a
    // picker is a prominent front end to a list, so an unscoped one is worse
    // than an unscoped list.
    list($uSql, $uParams) = activeTenantFilter($conn, $analystId, 'u');
    $st = $conn->prepare(
        "SELECT u.id,
                COALESCE(NULLIF(u.display_name, ''), u.email, u.username) AS name,
                COALESCE(u.email, u.username) AS secondary
           FROM users u
          WHERE (u.display_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)" . $uSql . "
       ORDER BY name LIMIT 10"
    );
    $st->execute(array_merge([$like, $like, $like], $uParams));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['member_type' => 'user', 'member_id' => (int)$r['id'],
                  'name' => (string)$r['name'], 'secondary' => (string)($r['secondary'] ?? '')];
    }

    echo json_encode(['success' => true, 'results' => $out]);
}

function handleCreate(PDO $conn, int $analystId, array $input): void
{
    $name = trim((string)($input['name'] ?? ''));
    $desc = trim((string)($input['description'] ?? ''));
    if ($name === '') { echo json_encode(['success' => false, 'error' => 'Give the group a name']); return; }

    // The name is UNIQUE in the schema, so say so in words rather than letting a
    // constraint violation surface as "Something went wrong".
    $st = $conn->prepare("SELECT id, is_active FROM knowledge_user_groups WHERE name = ?");
    $st->execute([$name]);
    if ($existing = $st->fetch(PDO::FETCH_ASSOC)) {
        // A deleted group is only deactivated, and its members are still there.
        // Reviving it silently would hand back an access list nobody reviewed, so
        // this stops and says what it found instead.
        $why = $existing['is_active'] ? 'There is already a group with that name'
                                      : 'A deleted group had that name. Choose another, or ask for it to be restored.';
        echo json_encode(['success' => false, 'error' => $why]);
        return;
    }

    $st = $conn->prepare(
        "INSERT INTO knowledge_user_groups (name, description, created_by_id, created_datetime, updated_datetime)
              VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $st->execute([$name, $desc !== '' ? $desc : null, $analystId]);

    echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
}

function handleUpdate(PDO $conn, array $input): void
{
    $id   = (int)($input['id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $desc = trim((string)($input['description'] ?? ''));
    if ($id <= 0)     { echo json_encode(['success' => false, 'error' => 'No group given']); return; }
    if ($name === '') { echo json_encode(['success' => false, 'error' => 'Give the group a name']); return; }

    $st = $conn->prepare("SELECT id FROM knowledge_user_groups WHERE name = ? AND id <> ?");
    $st->execute([$name, $id]);
    if ($st->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'There is already a group with that name']);
        return;
    }

    $st = $conn->prepare(
        "UPDATE knowledge_user_groups SET name = ?, description = ?, updated_datetime = UTC_TIMESTAMP()
          WHERE id = ? AND is_active = 1"
    );
    $st->execute([$name, $desc !== '' ? $desc : null, $id]);

    echo json_encode(['success' => true]);
}

/**
 * Deactivate, never DELETE.
 *
 * 🔑 The membership rows have no foreign key to lean on and the visibility check
 * joins `is_active = 1`, so deactivating is already enough to stop the group
 * granting anything. Removing the rows outright would destroy the record of who
 * had been given what, which is the one thing an access list is kept for.
 */
function handleDelete(PDO $conn, array $input): void
{
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'No group given']); return; }

    $conn->prepare("UPDATE knowledge_user_groups SET is_active = 0, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$id]);

    echo json_encode(['success' => true]);
}

/**
 * Add one person, with an optional last day of access.
 *
 * ⚠️ Members are added and removed ONE AT A TIME rather than by posting a
 * replacement list. A replace-all would have to re-insert every row, which
 * silently resets each member's `expires_at` and the date they were added — so
 * editing a group to add one person would quietly give six other people
 * permanent access. The LMS group editor does exactly that; it gets away with it
 * only because its membership carries no expiry to lose.
 */
function handleAddMember(PDO $conn, int $analystId, array $input): void
{
    $groupId = (int)($input['id'] ?? 0);
    $type    = (string)($input['member_type'] ?? '');
    $memberId= (int)($input['member_id'] ?? 0);
    $until   = trim((string)($input['expires_on'] ?? ''));

    if ($groupId <= 0 || $memberId <= 0 || !in_array($type, GROUP_MEMBER_TYPES, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']); return;
    }

    $st = $conn->prepare("SELECT id FROM knowledge_user_groups WHERE id = ? AND is_active = 1");
    $st->execute([$groupId]);
    if (!$st->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'Group not found']); return; }

    // The person has to exist, and a portal user has to be one this analyst can
    // already see. Otherwise the picker's company scope is decoration: you could
    // add anybody by guessing an id.
    if ($type === 'analyst') {
        $st = $conn->prepare("SELECT id FROM analysts WHERE id = ? AND is_active = 1");
        $st->execute([$memberId]);
        $ok = (bool)$st->fetchColumn();
    } else {
        list($uSql, $uParams) = activeTenantFilter($conn, $analystId, 'u');
        $st = $conn->prepare("SELECT u.id FROM users u WHERE u.id = ?" . $uSql);
        $st->execute(array_merge([$memberId], $uParams));
        $ok = (bool)$st->fetchColumn();
    }
    if (!$ok) { echo json_encode(['success' => false, 'error' => 'That person could not be found']); return; }

    $expiresAt = groupExpiryToUtc($until);
    if ($until !== '' && $expiresAt === null) {
        echo json_encode(['success' => false, 'error' => 'That date could not be read']); return;
    }

    // Re-adding somebody already there is a no-op on the row but SHOULD be able to
    // change or clear their expiry, which is the ordinary way an access period is
    // extended. ON DUPLICATE KEY rather than a delete-then-insert, so the date they
    // originally joined survives.
    $st = $conn->prepare(
        "INSERT INTO knowledge_user_group_members (group_id, member_type, member_id, expires_at, created_datetime)
              VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)"
    );
    $st->execute([$groupId, $type, $memberId, $expiresAt]);

    echo json_encode(['success' => true]);
}

function handleRemoveMember(PDO $conn, array $input): void
{
    $groupId = (int)($input['id'] ?? 0);
    $type    = (string)($input['member_type'] ?? '');
    $memberId= (int)($input['member_id'] ?? 0);

    if ($groupId <= 0 || $memberId <= 0 || !in_array($type, GROUP_MEMBER_TYPES, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']); return;
    }

    $conn->prepare(
        "DELETE FROM knowledge_user_group_members WHERE group_id = ? AND member_type = ? AND member_id = ?"
    )->execute([$groupId, $type, $memberId]);

    echo json_encode(['success' => true]);
}

/**
 * "Access until 14 September" → the UTC instant that day ends.
 *
 * 🔑 `expires_at` IS AN INSTANT, not a bare date: visibility.php compares it to
 * `UTC_TIMESTAMP()`. So a picked calendar day is the fourth kind of stored date —
 * a wall clock that has to be converted before it is stored (GH #116, GH #126).
 * The zone is the installation's, set by config.php, which is the same frame
 * naive_now() reads local "now" in.
 *
 * End of the chosen day, not the start of it: an admin who types the 14th means
 * the 14th is their last day, and expiring them at midnight would take the access
 * away a day early — on the morning of the day they were told they still had.
 *
 * @return string|null NULL for "no expiry" (a blank box), and also for a date
 *                     that cannot be read — the caller separates the two.
 */
function groupExpiryToUtc(string $date): ?string
{
    if ($date === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;

    try {
        $dt = new DateTime($date . ' 23:59:59', new DateTimeZone(date_default_timezone_get()));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}
