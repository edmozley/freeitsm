<?php
/**
 * Watchtower settings — which cards appear, and which statuses feed each count.
 *
 * WHY THIS IS NOT A FLAG ON THE STATUS ROW
 *
 * A status carries facts about itself — is_closed, is_default, pauses_sla — and
 * the SLA engine and half the application depend on them. "Show this one on my
 * dashboard" is not a fact about the status, it is a fact about the dashboard,
 * and it differs per site: one team treats Blocked as the first thing they want
 * to see in the morning, another never looks at it. Putting it on the status
 * would also mean tuning Watchtower by touring three modules' settings screens —
 * which is already the situation for the two Watchtower settings that exist
 * (one lives in Assets, and one has no screen at all).
 *
 * THE DEFAULT MUST BE CORRECT WITHOUT ANYONE CONFIGURING ANYTHING
 *
 * No row here means "use the built-in behaviour", which is the general,
 * name-free version. So a fresh install, and every existing one, is right on
 * day one and this screen only ever TRIMS what is already true. If ticking
 * boxes were what made Watchtower correct, every install would be wrong until
 * somebody found the screen — and a blank dashboard reads as "nothing needs
 * attention", which is the most dangerous thing it could say.
 *
 * is_customised is what makes that work: it separates "not configured" from
 * "configured to show nothing". Without it an empty selection is
 * indistinguishable from an untouched one, and would silently mean "all".
 */

/** analyst_id 0 = the installation's setting. Per-analyst overrides are future work. */
const WT_INSTALL_SCOPE = 0;

/** Every card Watchtower draws, in the order it draws them. */
function wtCardKeys(): array
{
    return ['morning_checks', 'tickets', 'changes', 'calendar', 'service_status',
            'contracts', 'software', 'knowledge', 'assets', 'tasks', 'workflows'];
}

// ─── Whose work am I looking at? (discussion #58) ───────────────────────────
//
// dschipfel: "The Watchtower currently displays information globally, which
// means that users often see entries that are not relevant to their own
// responsibilities."
//
// 🔑 NOT EVERY CARD CAN MEAN "MINE". Assets belong to end users rather than to
// analysts; a degraded service and a failed workflow belong to nobody. Three of
// the ten cards therefore have no owner to filter on, and pretending otherwise
// would either show an empty card that looks like good news or a global number
// that reads as a personal one. What happens to those three is the analyst's
// own choice — see wtImpersonalOnMine().

const WT_SCOPE_MINE = 'mine';
const WT_SCOPE_TEAM = 'team';
const WT_SCOPE_ALL  = 'all';

function wtScopeIsValid(string $scope): bool
{
    return in_array($scope, [WT_SCOPE_MINE, WT_SCOPE_TEAM, WT_SCOPE_ALL], true);
}

/**
 * The cards with no notion of an owner.
 *
 * ⚠️ Assets is in here and it looks as though it should not be. An asset is
 * assigned to a USER (users_assets), and an analyst is not a user — they are
 * different tables and an analyst frequently has no user row at all. "My
 * assigned assets" would therefore be empty for most people, which reads as
 * "nothing to worry about" rather than "this question does not apply to you".
 */
function wtImpersonalCards(): array
{
    return [
        'service_status',   // a degraded service belongs to nobody
        'workflows',        // so does a failed rule
        'assets',           // see the note above: assigned to USERS, not analysts
        // ⚠️ The shared team calendar. `calendar_events` has a created_by and no
        // owner or assignee at all — every analyst sees the same events, which
        // is the point of it. "My events" would mean "ones I happened to type
        // in", which is not a useful reading of the question.
        'calendar',
        // These two DO have an owner column (contract_owner_id, author_id), but
        // the questions the cards ask are not personal ones: a contract about to
        // expire needs chasing whoever owns it, and an article overdue review is
        // a gap in the library rather than in somebody's workload. Scoping them
        // would hide a renewal from everybody except one person.
        'contracts',
        'knowledge',
        // Software licences have no owner column at all — nobody is the person a
        // renewal belongs to. Same reasoning as contracts above, and the same
        // consequence: scoping it would hide a £12k renewal from everybody.
        'software',
    ];
}

/** Can this card narrow to a person or a team at all? */
function wtCardCanScope(string $cardKey): bool
{
    return !in_array($cardKey, wtImpersonalCards(), true);
}

/**
 * What this analyst wants done with the impersonal cards while on "Mine":
 * 'show' (marked as everyone's) or 'hide'.
 *
 * 🔑 A SETTING RATHER THAN A DECISION MADE FOR THEM (Ed). Both answers are
 * defensible: a dashboard with nothing on it that is not yours is genuinely
 * cleaner, and losing sight of a degraded service the moment you switch is
 * genuinely dangerous. Which matters more depends on the person and the desk.
 *
 * Defaults to 'show', because that is the answer that cannot hide something
 * urgent from somebody who never opened the settings.
 */
function wtImpersonalOnMine(PDO $conn, int $analystId): string
{
    if ($analystId <= 0) return 'show';
    try {
        $stmt = $conn->prepare(
            "SELECT preference_value FROM user_preferences
              WHERE analyst_id = ? AND preference_key = 'watchtower_impersonal' LIMIT 1"
        );
        $stmt->execute([$analystId]);
        $v = (string)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 'show';
    }
    return $v === 'hide' ? 'hide' : 'show';
}

/** The scope this analyst last chose. Defaults to everyone, i.e. today's view. */
function wtScopeFor(PDO $conn, int $analystId): string
{
    if ($analystId <= 0) return WT_SCOPE_ALL;
    try {
        $stmt = $conn->prepare(
            "SELECT preference_value FROM user_preferences
              WHERE analyst_id = ? AND preference_key = 'watchtower_scope' LIMIT 1"
        );
        $stmt->execute([$analystId]);
        $v = (string)$stmt->fetchColumn();
    } catch (Exception $e) {
        return WT_SCOPE_ALL;
    }
    return wtScopeIsValid($v) ? $v : WT_SCOPE_ALL;
}

/**
 * The analyst ids "team" covers: everyone on any team this analyst belongs to,
 * including themselves.
 *
 * ⚠️ Returns just the analyst on an install with no teams, rather than an empty
 * list. An empty IN () is a syntax error, and "team" for somebody in no team
 * sensibly means themselves — not nobody.
 */
function wtTeamAnalystIds(PDO $conn, int $analystId): array
{
    if ($analystId <= 0) return [0];
    try {
        $stmt = $conn->prepare(
            "SELECT DISTINCT at2.analyst_id
               FROM analyst_teams at1
               JOIN analyst_teams at2 ON at2.team_id = at1.team_id
              WHERE at1.analyst_id = ?"
        );
        $stmt->execute([$analystId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        $ids = [];
    }
    if (!in_array($analystId, $ids, true)) $ids[] = $analystId;
    return $ids;
}

/**
 * "Mine" for a morning check, which is not a column.
 *
 * 🔴 A CHECK IS ROUTED THROUGH ITS GROUP, not by an analyst id on the check.
 * On a real install `morningChecks_Checks.AssignedAnalystID` is empty on every
 * row — routing is done by putting a check in a group and pointing the group at
 * a team or a person. Scoping on the check's own column therefore produces a
 * CONFIDENT ZERO for everybody: "no checks are yours", which reads as "nothing
 * to do" on the one card whose whole job is to say otherwise. Watchtower has
 * shipped that class of bug before, counting statuses by names nothing matched.
 *
 * So "mine" is: assigned to me directly, OR in a group pointed at me, OR in a
 * group pointed at a team I am in.
 *
 * Expects the query to expose `c` (checks) and `g` (groups, LEFT JOINed).
 */
function wtMorningCheckScope(PDO $conn, int $analystId, string $scope): array
{
    if ($scope === WT_SCOPE_ALL || $analystId <= 0) return ['', []];

    $analystIds = $scope === WT_SCOPE_TEAM ? wtTeamAnalystIds($conn, $analystId) : [$analystId];
    $aPh = implode(',', array_fill(0, count($analystIds), '?'));

    $teamIds = [];
    try {
        $stmt = $conn->prepare("SELECT team_id FROM analyst_teams WHERE analyst_id = ?");
        $stmt->execute([$analystId]);
        $teamIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        $teamIds = [];
    }

    // ⚠️ BRACKET THE WHOLE GROUP. These are ORs appended to a WHERE that already
    // has an AND in it; without the outer brackets the last branch binds alone
    // and the filter quietly does nothing. Same trap as the tenancy clauses.
    $sql  = " AND (c.AssignedAnalystID IN ({$aPh}) OR g.AssignedAnalystID IN ({$aPh})";
    $args = array_merge($analystIds, $analystIds);
    if ($teamIds) {
        $tPh  = implode(',', array_fill(0, count($teamIds), '?'));
        $sql .= " OR g.AssignedTeamID IN ({$tPh})";
        $args = array_merge($args, $teamIds);
    }
    $sql .= ')';
    return [$sql, $args];
}

/**
 * A SQL fragment restricting $column to the chosen scope, plus its parameters.
 * Returns ['', []] for "everyone", so a caller can concatenate unconditionally.
 *
 * ⚠️ The column is INTERPOLATED, so it must never come from a request. Every
 * caller passes a literal — `t.assigned_analyst_id`, `c.assigned_to_id` — and
 * that is the only safe way to use this.
 */
function wtScopeClause(PDO $conn, int $analystId, string $scope, string $column): array
{
    if ($scope === WT_SCOPE_MINE) {
        return [" AND {$column} = ?", [$analystId]];
    }
    if ($scope === WT_SCOPE_TEAM) {
        $ids = wtTeamAnalystIds($conn, $analystId);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        return [" AND {$column} IN ({$ph})", $ids];
    }
    return ['', []];
}

/**
 * The set-driven items, and what kind of thing each one selects from.
 * item_key => ['entity_type' => …, 'table' => …, 'id' => …, 'name' => …, 'where' => …]
 */
function wtSelectableItems(): array
{
    return [
        'tickets.by_status' => [
            'entity_type' => 'ticket_status',
            'table' => 'ticket_statuses', 'id' => 'id', 'name' => 'name',
            'where' => 'is_closed = 0 AND is_active = 1', 'order' => 'display_order, name',
        ],
        'tickets.high_priority' => [
            'entity_type' => 'ticket_priority',
            'table' => 'ticket_priorities', 'id' => 'id', 'name' => 'name',
            'where' => 'is_active = 1', 'order' => 'display_order, name',
        ],
        // Which impact levels put a service on the card at all. The default status
        // (Operational) is never offered — a healthy service is not a problem.
        'service.levels' => [
            'entity_type' => 'impact_level',
            'table' => 'service_impact_levels', 'id' => 'id', 'name' => 'name',
            'where' => 'is_active = 1 AND is_default = 0', 'order' => 'severity_order, name',
        ],
        // Which of them turn the light red rather than amber. Separate from the
        // level's counts_as_downtime flag ON PURPOSE: that one decides whether
        // time at this level counts against your uptime figures, which is a
        // reporting fact, not a question about how loudly a dashboard should
        // shout. Tying them together would mean distorting your uptime to change
        // a colour.
        'service.serious' => [
            'entity_type' => 'impact_level',
            'table' => 'service_impact_levels', 'id' => 'id', 'name' => 'name',
            'where' => 'is_active = 1 AND is_default = 0', 'order' => 'severity_order, name',
        ],
        'changes.by_status' => [
            'entity_type' => 'change_status',
            'table' => 'change_statuses', 'id' => 'id', 'name' => 'name',
            'where' => 'is_closed = 0 AND is_active = 1', 'order' => 'display_order, name',
        ],
        'tasks.by_status' => [
            'entity_type' => 'task_status',
            'table' => 'task_statuses', 'id' => 'id', 'name' => 'name',
            'where' => 'is_closed = 0 AND is_active = 1', 'order' => 'display_order, name',
        ],
        'mc.attention' => [
            'entity_type' => 'mc_status',
            'table' => 'morningChecks_Statuses', 'id' => 'StatusID', 'name' => 'Label',
            'where' => 'IsActive = 1', 'order' => 'SortOrder, Label',
        ],
    ];
}

/** Card visibility. Missing row = visible. */
function wtVisibleCards(PDO $conn): array
{
    $visible = array_fill_keys(wtCardKeys(), true);
    try {
        $stmt = $conn->prepare(
            "SELECT item_key, is_visible FROM watchtower_items WHERE analyst_id = ? AND item_key LIKE 'card.%'"
        );
        $stmt->execute([WT_INSTALL_SCOPE]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = substr($row['item_key'], 5);
            if (isset($visible[$key])) $visible[$key] = (bool)(int)$row['is_visible'];
        }
    } catch (Exception $e) {
        // Table not created yet (upgrade not run) — everything shows, which is
        // the behaviour this screen was added on top of.
    }
    return $visible;
}

/**
 * The chosen members for an item, or NULL meaning "not customised, use the
 * built-in default". An empty ARRAY is a real answer — the admin chose nothing —
 * and is not the same as null.
 *
 * Ids are checked against the live lookup table, so a status deleted after being
 * selected drops out instead of being counted as a member that no longer exists.
 */
function wtItemMembers(PDO $conn, string $itemKey): ?array
{
    $items = wtSelectableItems();
    if (!isset($items[$itemKey])) return null;
    $spec = $items[$itemKey];

    try {
        $c = $conn->prepare("SELECT is_customised FROM watchtower_items WHERE analyst_id = ? AND item_key = ? LIMIT 1");
        $c->execute([WT_INSTALL_SCOPE, $itemKey]);
        if ((int)$c->fetchColumn() !== 1) return null;

        $stmt = $conn->prepare(
            "SELECT m.entity_id
               FROM watchtower_item_members m
               JOIN `{$spec['table']}` e ON e.`{$spec['id']}` = m.entity_id
              WHERE m.analyst_id = ? AND m.item_key = ? AND m.entity_type = ?"
        );
        $stmt->execute([WT_INSTALL_SCOPE, $itemKey, $spec['entity_type']]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return null;   // never let a settings read break the dashboard
    }
}

/** A safe `IN (…)` fragment from a list of ints. '(0)' when empty, so it matches nothing. */
function wtIdListSql(array $ids): string
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return $ids ? '(' . implode(',', $ids) . ')' : '(0)';
}
