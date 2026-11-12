<?php
/**
 * Report Packs: who may open, change and share a pack.
 *
 * A pack is PRIVATE to the analyst who made it until they share it. A share names
 * one of three kinds of people:
 *
 *   analyst     one analyst                         target_id = analysts.id
 *   team        everyone in a team                  target_id = teams.id
 *   department  everyone whose profile says it      target_value = analysts.department
 *
 * "Department" is the free-text field on an analyst's profile (often filled in by
 * directory sync), NOT the ticket-routing departments table - see the wiki page
 * Groups-of-People-Developer-Guide for why FreeITSM has both. It is matched
 * case-insensitively and trimmed, because a directory writes "IT Services" and a
 * person types "it services".
 *
 * Each share is View (open and export) or Edit (also change the design). Only the
 * owner shares, renames into a new owner, or deletes. A pack whose owner has been
 * deleted is orphaned (owner_id NULL by the foreign key); administrators can manage
 * those so nothing is left that nobody can remove.
 *
 * ⚠️ Access to a PACK is not access to its DATA. Every block is fetched with the
 * viewer's own module access and companies (api/reporting/packs/block_data.php), so
 * sharing a pack never shows anybody a figure they could not already see.
 */

const RP_ROLE_RANK = ['view' => 1, 'edit' => 2, 'owner' => 3];

/** The teams and department this analyst counts as, for matching shares. */
function rpViewer(PDO $conn, int $analystId): array
{
    static $cache = [];
    if (isset($cache[$analystId])) return $cache[$analystId];

    $teams = [];
    try {
        // Active teams only: a retired team no longer opens packs shared with it.
        $s = $conn->prepare("SELECT at.team_id FROM analyst_teams at JOIN teams t ON t.id = at.team_id AND COALESCE(t.is_active, 1) = 1 WHERE at.analyst_id = ?");
        $s->execute([$analystId]);
        $teams = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception) { /* no teams table yet: no team shares match */ }

    $s = $conn->prepare("SELECT department, is_admin FROM analysts WHERE id = ?");
    $s->execute([$analystId]);
    $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    $dept = rpNormDept($row['department'] ?? '');

    return $cache[$analystId] = [
        'id'         => $analystId,
        'team_ids'   => $teams,
        'department' => $dept,
        'is_admin'   => !empty($row['is_admin']),
    ];
}

/**
 * A department name normalised for matching: spaces collapsed, trimmed and lower case.
 *
 * @param mixed $s  a department name as typed or stored; null gives ''
 */
function rpNormDept($s): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$s)));
}

/**
 * SQL that matches the shares reaching this viewer, for `report_pack_shares s`.
 *
 * @param array $viewer  the viewer from rpViewer(): id, team_ids and department (already normalised)
 * @return array{0:string,1:array}
 */
function rpShareMatchSql(array $viewer): array
{
    $parts  = ["(s.target_type = 'analyst' AND s.target_id = ?)"];
    $params = [$viewer['id']];
    if ($viewer['team_ids']) {
        $parts[] = "(s.target_type = 'team' AND s.target_id IN (" . implode(',', array_fill(0, count($viewer['team_ids']), '?')) . "))";
        $params  = array_merge($params, $viewer['team_ids']);
    }
    if ($viewer['department'] !== '') {
        $parts[] = "(s.target_type = 'department' AND LOWER(TRIM(s.target_value)) = ?)";
        $params[] = $viewer['department'];
    }
    return ['(' . implode(' OR ', $parts) . ')', $params];
}

/**
 * This viewer's role on a pack: 'owner', 'edit', 'view', or null (no access, or no
 * such pack - the caller says "not found" for both, so ids cannot be probed).
 *
 * @param int $packId  the report_packs id
 */
function rpRole(PDO $conn, int $analystId, int $packId): ?string
{
    $s = $conn->prepare("SELECT owner_id FROM report_packs WHERE id = ?");
    $s->execute([$packId]);
    $pack = $s->fetch(PDO::FETCH_ASSOC);
    if (!$pack) return null;

    if ((int)$pack['owner_id'] === $analystId) return 'owner';

    $viewer = rpViewer($conn, $analystId);
    if ($pack['owner_id'] === null && $viewer['is_admin']) return 'owner';   // orphaned

    [$match, $params] = rpShareMatchSql($viewer);
    $s = $conn->prepare("SELECT MAX(s.can_edit) FROM report_pack_shares s WHERE s.pack_id = ? AND $match");
    $s->execute(array_merge([$packId], $params));
    $edit = $s->fetchColumn();
    if ($edit === null || $edit === false) return null;
    return (int)$edit === 1 ? 'edit' : 'view';
}

/**
 * True if $role is at least $need ('view' < 'edit' < 'owner').
 *
 * @param ?string $role  the viewer's role from rpRole(): 'owner', 'edit', 'view' or null
 * @param string $need  the least role required: 'view', 'edit' or 'owner'
 */
function rpRoleAtLeast(?string $role, string $need): bool
{
    return $role !== null && (RP_ROLE_RANK[$role] ?? 0) >= (RP_ROLE_RANK[$need] ?? 99);
}

/**
 * Every pack this analyst can open, newest change first, with their role on each.
 * @return array<int,array>
 */
function rpListPacks(PDO $conn, int $analystId): array
{
    $viewer = rpViewer($conn, $analystId);
    [$match, $params] = rpShareMatchSql($viewer);
    $orphan = $viewer['is_admin'] ? ' OR p.owner_id IS NULL' : '';

    $sql = "SELECT p.id, p.name, p.description, p.owner_id, p.created_datetime, p.updated_datetime,
                   o.full_name AS owner_name, u.full_name AS updated_by_name,
                   (SELECT MAX(s.can_edit) FROM report_pack_shares s WHERE s.pack_id = p.id AND $match) AS share_edit,
                   (SELECT COUNT(*) FROM report_pack_shares s2 WHERE s2.pack_id = p.id) AS share_count
              FROM report_packs p
              LEFT JOIN analysts o ON o.id = p.owner_id
              LEFT JOIN analysts u ON u.id = p.updated_by
             WHERE p.owner_id = ?$orphan
                OR EXISTS (SELECT 1 FROM report_pack_shares s WHERE s.pack_id = p.id AND $match)
             ORDER BY COALESCE(p.updated_datetime, p.created_datetime) DESC, p.id DESC";
    $s = $conn->prepare($sql);
    $s->execute(array_merge($params, [$analystId], $params));

    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['owner_id'] === $analystId || ($r['owner_id'] === null && $viewer['is_admin'])) {
            $role = 'owner';
        } else {
            $role = ((int)$r['share_edit'] === 1) ? 'edit' : 'view';
        }
        $out[] = [
            'id'              => (int)$r['id'],
            'name'            => $r['name'],
            'description'     => $r['description'],
            'owner_id'        => $r['owner_id'] !== null ? (int)$r['owner_id'] : null,
            'owner_name'      => $r['owner_name'],
            'updated_by_name' => $r['updated_by_name'],
            'created'         => $r['created_datetime'],
            'updated'         => $r['updated_datetime'] ?: $r['created_datetime'],
            'role'            => $role,
            'shared'          => (int)$r['share_count'] > 0,
        ];
    }
    return $out;
}

/**
 * A pack's shares, with a display name for each target. Owner only.
 *
 * @param int $packId  the report_packs id
 */
function rpListShares(PDO $conn, int $packId): array
{
    $s = $conn->prepare(
        "SELECT s.id, s.target_type, s.target_id, s.target_value, s.can_edit,
                a.full_name AS analyst_name, t.name AS team_name
           FROM report_pack_shares s
           LEFT JOIN analysts a ON s.target_type = 'analyst' AND a.id = s.target_id
           LEFT JOIN teams t    ON s.target_type = 'team'    AND t.id = s.target_id
          WHERE s.pack_id = ?
          ORDER BY s.target_type, COALESCE(a.full_name, t.name, s.target_value)"
    );
    $s->execute([$packId]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $label = $r['target_type'] === 'analyst' ? $r['analyst_name']
               : ($r['target_type'] === 'team' ? $r['team_name'] : $r['target_value']);
        if ($label === null) continue;   // the analyst or team has since been deleted
        $out[] = [
            'type'     => $r['target_type'],
            'id'       => $r['target_id'] !== null ? (int)$r['target_id'] : null,
            'value'    => $r['target_value'],
            'label'    => $label,
            'can_edit' => (int)$r['can_edit'] === 1,
        ];
    }
    return $out;
}

/**
 * Replace a pack's shares with $shares (the whole list, as the dialog sends it).
 * Each entry is validated against what exists; unknown targets are dropped, never
 * stored, so a share cannot point at an id that a later analyst or team would
 * inherit.
 *
 * @param int $packId  the report_packs id
 * @param int $ownerId  the pack owner's analyst id - a share to them is dropped
 * @param array $shares  the whole list from the share dialog (untrusted): each {type: analyst|team|department, id or value, can_edit}
 */
function rpSaveShares(PDO $conn, int $packId, int $ownerId, array $shares): void
{
    $clean = [];
    foreach ($shares as $sh) {
        if (!is_array($sh)) continue;
        $type = (string)($sh['type'] ?? '');
        $edit = !empty($sh['can_edit']) ? 1 : 0;
        if ($type === 'analyst' || $type === 'team') {
            $id = (int)($sh['id'] ?? 0);
            if ($id <= 0 || ($type === 'analyst' && $id === $ownerId)) continue;
            $table = $type === 'analyst' ? 'analysts' : 'teams';
            $chk = $conn->prepare("SELECT 1 FROM $table WHERE id = ?");
            $chk->execute([$id]);
            if (!$chk->fetchColumn()) continue;
            $clean["$type:$id"] = [$type, $id, null, $edit];
        } elseif ($type === 'department') {
            $v = trim(preg_replace('/\s+/u', ' ', (string)($sh['value'] ?? '')));
            if ($v === '' || mb_strlen($v) > 255) continue;
            $clean['department:' . rpNormDept($v)] = [$type, null, $v, $edit];
        }
    }

    $conn->beginTransaction();
    try {
        $conn->prepare("DELETE FROM report_pack_shares WHERE pack_id = ?")->execute([$packId]);
        $ins = $conn->prepare("INSERT INTO report_pack_shares (pack_id, target_type, target_id, target_value, can_edit) VALUES (?, ?, ?, ?, ?)");
        foreach ($clean as [$type, $id, $value, $edit]) {
            $ins->execute([$packId, $type, $id, $value, $edit]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
}

/** The departments analysts actually have, for the share picker. */
function rpKnownDepartments(PDO $conn): array
{
    $s = $conn->query("SELECT DISTINCT TRIM(department) AS d FROM analysts
                        WHERE is_active = 1 AND department IS NOT NULL AND TRIM(department) <> ''
                        ORDER BY d");
    $seen = []; $out = [];
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $k = rpNormDept($d);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $d;
    }
    return $out;
}
