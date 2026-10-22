<?php
/**
 * Saved table views (discussion #96, dschipfel; library and sharing by Ed).
 *
 * A view is a saved way of LOOKING at a table: which columns, in what order,
 * sorted how, filtered to what. It belongs to the shared data-table engine
 * (assets/js/data-table.js), so every table running that engine gets views for
 * free — assets, tasks, calendar and change management today.
 *
 * ⚠️ EVERY read goes through viewVisibleClause(). One place decides who can see
 * what, because there are three answers (mine, my team's, everyone's) and a
 * fourth that must never happen (somebody else's private view). Four endpoints
 * each writing their own clause is four chances to get that wrong.
 *
 * 🔑 A view is not data. It carries no tenant_id and needs none: its filters are
 * applied to rows the reader was already allowed to load, so a shared view
 * cannot show anybody another company's assets. It can name a value that means
 * nothing to the reader, which shows up as a filter matching nothing — an empty
 * table, not a leak.
 */

const TABLE_VIEW_KEYS = ['assets', 'tasks', 'calendar', 'changes', 'domains', 'tickets'];
const TABLE_VIEW_VISIBILITIES = ['private', 'team', 'public'];

/** The teams this analyst belongs to. Empty array is a valid answer. */
function tableViewTeamIds(PDO $conn, int $analystId): array
{
    $stmt = $conn->prepare("SELECT team_id FROM analyst_teams WHERE analyst_id = ?");
    $stmt->execute([$analystId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The WHERE fragment and arguments that limit views to what this analyst may
 * see: their own, ones shared with a team they are in, and public ones.
 *
 * ⚠️ BRACKETED as one group. Callers append it to a WHERE that already has a
 * table_key condition, and an unbracketed set of alternatives would make that
 * condition apply to the last branch only — so a tasks view would appear on the
 * asset table. The same shape has bitten this codebase before.
 */
function tableViewVisibleClause(PDO $conn, int $analystId): array
{
    $terms = ['v.owner_id = ?', "v.visibility = 'public'"];
    $args  = [$analystId];

    $teamIds = tableViewTeamIds($conn, $analystId);
    if ($teamIds) {
        $in = implode(',', array_fill(0, count($teamIds), '?'));
        $terms[] = "(v.visibility = 'team' AND v.team_id IN ($in))";
        $args    = array_merge($args, $teamIds);
    }

    return [' AND (' . implode(' OR ', $terms) . ')', $args];
}

/** One view row plus the names the library shows. */
function tableViewSelect(): string
{
    return "SELECT v.id, v.table_key, v.name, v.description, v.owner_id, v.visibility,
                   v.team_id, v.config, v.created_datetime, v.updated_datetime,
                   v.last_used_datetime,
                   a.full_name AS owner_name, t.name AS team_name
              FROM table_views v
         LEFT JOIN analysts a ON a.id = v.owner_id
         LEFT JOIN teams    t ON t.id = v.team_id";
}

/**
 * Coerce the types the browser cares about.
 *
 * @param array $r  a table_views row from tableViewSelect()
 */
function tableViewShape(array $r, int $analystId): array
{
    $r['id']       = (int)$r['id'];
    $r['owner_id'] = $r['owner_id'] !== null ? (int)$r['owner_id'] : null;
    $r['team_id']  = $r['team_id']  !== null ? (int)$r['team_id']  : null;
    // Whether the reader may change it, answered here rather than left to the
    // browser to work out from owner_id — the same rule the write path enforces.
    $r['can_edit'] = ($r['owner_id'] !== null && $r['owner_id'] === $analystId);
    return $r;
}

/**
 * Views on one table that this analyst can see, newest-used first.
 *
 * $q searches the name, the description and the owner's name — "that one Dave
 * made" is how people look for a view they did not write.
 *
 * @param string $tableKey  which table the views belong to: 'assets', 'tasks', 'calendar', 'changes' or 'domains'
 * @param string $q  search text matched against the name, description and owner's name; '' for all
 */
function tableViewList(PDO $conn, int $analystId, string $tableKey, string $q = ''): array
{
    [$vis, $args] = tableViewVisibleClause($conn, $analystId);

    $sql    = tableViewSelect() . " WHERE v.table_key = ?" . $vis;
    $params = array_merge([$tableKey], $args);

    if ($q !== '') {
        $sql .= " AND (v.name LIKE ? OR v.description LIKE ? OR a.full_name LIKE ?)";
        $params = array_merge($params, array_fill(0, 3, '%' . $q . '%'));
    }

    // Last used first, then newest. A view nobody has opened yet still has to
    // appear somewhere sensible, which is why created_datetime is the fallback
    // rather than leaving NULLs to sort arbitrarily.
    $sql .= " ORDER BY v.last_used_datetime IS NULL, v.last_used_datetime DESC, v.created_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return array_map(fn($r) => tableViewShape($r, $analystId), $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * One view, if this analyst may see it.
 *
 * @param int $viewId  the view (table_views.id) to load
 * @throws RuntimeException when it does not exist or is out of reach — the same
 *         message either way, because telling them apart tells you it exists.
 */
function tableViewLoad(PDO $conn, int $analystId, int $viewId): array
{
    [$vis, $args] = tableViewVisibleClause($conn, $analystId);
    $stmt = $conn->prepare(tableViewSelect() . " WHERE v.id = ?" . $vis);
    $stmt->execute(array_merge([$viewId], $args));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('No such view');
    }
    return tableViewShape($row, $analystId);
}

/**
 * Create or update a view. Returns its id.
 *
 * ⚠️ Only the OWNER may change one. A view shared with a team is readable by
 * that team and writable by nobody else — Ed's choice, and the one that makes
 * "who changed my view?" a question that never gets asked.
 *
 * @param array $in  id (to change one the analyst owns), table_key, name (required, up to 120), description (up to 500),
 *     visibility ('private', 'team', 'public'), team_id (a team the analyst is in, for 'team'), config (JSON or array)
 * @throws RuntimeException on anything invalid or out of reach.
 */
function tableViewSave(PDO $conn, int $analystId, array $in): int
{
    $tableKey = (string)($in['table_key'] ?? '');
    if (!in_array($tableKey, TABLE_VIEW_KEYS, true)) {
        throw new RuntimeException('Unknown table');
    }

    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('A name is required');
    }
    $name        = mb_substr($name, 0, 120);
    $description = trim((string)($in['description'] ?? ''));
    $description = $description !== '' ? mb_substr($description, 0, 500) : null;

    $visibility = in_array(($in['visibility'] ?? ''), TABLE_VIEW_VISIBILITIES, true)
        ? $in['visibility'] : 'private';

    // A team view has to name a team the SAVER is in. Otherwise anybody could
    // share into any team by posting its id, and "shared with a team you are
    // not in" is not a thing that should exist.
    $teamId = null;
    if ($visibility === 'team') {
        $teamId  = (int)($in['team_id'] ?? 0);
        $myTeams = tableViewTeamIds($conn, $analystId);
        if (!in_array($teamId, $myTeams, true)) {
            throw new RuntimeException('Choose a team you belong to');
        }
    }

    // The engine's own state. Stored as it arrives, but only after proving it is
    // JSON — a malformed config would break the table for everybody it is
    // shared with, and the moment to find out is now.
    $config = $in['config'] ?? null;
    if (is_array($config)) {
        $config = json_encode($config);
    }
    if (!is_string($config) || $config === '' || json_decode($config) === null) {
        throw new RuntimeException('The view could not be saved because its settings were unreadable');
    }

    $viewId = (int)($in['id'] ?? 0);
    if ($viewId > 0) {
        $owner = $conn->prepare("SELECT owner_id FROM table_views WHERE id = ?");
        $owner->execute([$viewId]);
        $ownerId = $owner->fetchColumn();
        if ($ownerId === false || (int)$ownerId !== $analystId) {
            throw new RuntimeException('No such view');
        }
        $conn->prepare(
            "UPDATE table_views
                SET name = ?, description = ?, visibility = ?, team_id = ?, config = ?,
                    updated_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute([$name, $description, $visibility, $teamId, $config, $viewId]);
        return $viewId;
    }

    $conn->prepare(
        "INSERT INTO table_views (table_key, name, description, owner_id, visibility, team_id,
                                  config, created_datetime, updated_datetime)
              VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    )->execute([$tableKey, $name, $description, $analystId, $visibility, $teamId, $config]);

    return (int)$conn->lastInsertId();
}

/**
 * Delete a view. Owner only.
 *
 * @param int $viewId  the view (table_views.id) to delete; must be the analyst's own
 * @throws RuntimeException when it is not theirs.
 */
function tableViewDelete(PDO $conn, int $analystId, int $viewId): void
{
    $stmt = $conn->prepare("DELETE FROM table_views WHERE id = ? AND owner_id = ?");
    $stmt->execute([$viewId, $analystId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('No such view');
    }
}

/**
 * Stamp a view as used.
 *
 * ⚠️ One timestamp for the view, not one per reader. On a shared view this
 * answers "is anybody still using this?", which is the question that decides
 * whether it can be deleted. "When did I last use it?" would need a row per
 * person per view to answer, and nobody has asked that.
 *
 * @param int $viewId  the view (table_views.id) just opened
 */
function tableViewTouch(PDO $conn, int $analystId, int $viewId): void
{
    tableViewLoad($conn, $analystId, $viewId);   // reachable? same rules as reading
    $conn->prepare("UPDATE table_views SET last_used_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$viewId]);
}
