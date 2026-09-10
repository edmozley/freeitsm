<?php
/**
 * Ticket categories and resolution codes (#1540) — the shared reader.
 *
 * ---------------------------------------------------------------------------
 * THE ONE RULE THIS FILE EXISTS TO ENFORCE
 * ---------------------------------------------------------------------------
 * A ticket stores the LEAF category id and nothing else. There is no
 * `parent_category_id` on `tickets` and there must never be one. Every ancestor
 * — the path shown on screen, the root used for roll-up reporting, the ticket
 * type a category belongs to — is DERIVED here, from `parent_id`.
 *
 * That is not tidiness. `tickets` already stores the assignee twice, in
 * `assigned_analyst_id` and `owner_id`, and on a real installation 93 of 110
 * rows disagree with themselves. Two columns holding one fact will always end
 * up holding two different facts. So: one column, derived ancestors, always.
 *
 * ---------------------------------------------------------------------------
 * THE TYPE LINK LIVES ON THE ROOT
 * ---------------------------------------------------------------------------
 * `ticket_type_id` is only meaningful where `parent_id IS NULL`. A child
 * inherits its root's type, and saving a type onto a child is refused. If a
 * sub-category could name its own type, "Hardware → Printer" could be an
 * Incident while "Hardware" was a Service request, and neither answer would be
 * wrong — which means neither would be usable.
 *
 * ---------------------------------------------------------------------------
 * DEPTH
 * ---------------------------------------------------------------------------
 * Capped at MAX_DEPTH (3), in code rather than in the schema. An unbounded tree
 * is unreportable: nobody can read a chart with two hundred leaves on it, and
 * the roll-up has no natural level to stop at. Three is what the tools that do
 * this well settle on (Category → Subcategory → Item, where the item is the
 * actual noun: Hardware → Printer → Toner).
 */

require_once __DIR__ . '/tenancy.php';

/** Levels allowed, root inclusive. A root is depth 1. */
const TICKET_CATEGORY_MAX_DEPTH = 3;

/** How the path reads on screen and in exports. */
const TICKET_CATEGORY_SEPARATOR = ' → ';

/**
 * Every category this company can see, each with its derived path and root.
 *
 * @param ?int  $tenantId     the company whose lists apply, or null for the install
 * @param array $opts         activeOnly  — drop retired categories (default false)
 *                            portalOnly  — only those a requester may see (default false)
 *                            typeId      — only categories offered for this ticket type
 *                                          (roots with no type, plus that type's roots,
 *                                          plus everything beneath either)
 * @return array<int,array> keyed by id, each row carrying:
 *                          depth, path (array of names), path_label, root_id,
 *                          effective_type_id, has_children
 */
function ticketCategoriesResolved(PDO $conn, ?int $tenantId, array $opts = []): array
{
    $activeOnly = !empty($opts['activeOnly']);
    $portalOnly = !empty($opts['portalOnly']);
    $typeId     = array_key_exists('typeId', $opts) && $opts['typeId'] !== null ? (int) $opts['typeId'] : null;

    $cols = 'id, name, description, parent_id, ticket_type_id, is_portal_visible, is_active, display_order, tenant_id';

    // ⚠️ The whole tree is fetched, INCLUDING retired and non-portal rows, and the
    // filters are applied afterwards. A parent that is retired must not orphan its
    // children out of the result before their path can be built — the path has to
    // be derivable even when a link in the chain is one the caller cannot pick.
    if ($tenantId !== null && $tenantId > 0) {
        $rows = getTenantConfigRows($conn, 'ticket_categories', 'ticket_category', $tenantId, $cols, '', 'display_order, name');
    } else {
        try {
            $rows = $conn->query("SELECT $cols FROM ticket_categories ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];   // table absent on a part-migrated install
        }
    }

    $byId = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $byId[$id] = [
            'id'                => $id,
            'name'              => (string) $r['name'],
            'description'       => $r['description'],
            'parent_id'         => $r['parent_id'] === null ? null : (int) $r['parent_id'],
            'ticket_type_id'    => $r['ticket_type_id'] === null ? null : (int) $r['ticket_type_id'],
            'is_portal_visible' => (bool) $r['is_portal_visible'],
            'is_active'         => (bool) $r['is_active'],
            'display_order'     => (int) $r['display_order'],
            'tenant_id'         => $r['tenant_id'] === null ? null : (int) $r['tenant_id'],
            'has_children'      => false,
        ];
    }

    // Derive depth, path and root. Iterative, and it counts its own steps: a
    // parent_id cycle would otherwise spin forever here rather than failing.
    foreach ($byId as $id => &$row) {
        $path  = [$row['name']];
        $chain = [$id];
        $cur   = $row['parent_id'];
        $steps = 0;
        while ($cur !== null && isset($byId[$cur]) && $steps < TICKET_CATEGORY_MAX_DEPTH + 2) {
            array_unshift($path, $byId[$cur]['name']);
            array_unshift($chain, $cur);
            $cur = $byId[$cur]['parent_id'];
            $steps++;
        }
        $row['depth']      = count($chain);
        $row['path']       = $path;
        $row['path_label'] = implode(TICKET_CATEGORY_SEPARATOR, $path);
        $row['root_id']    = $chain[0];
        // The type comes from the ROOT, never from the row itself.
        $row['effective_type_id'] = $byId[$chain[0]]['ticket_type_id'];
    }
    unset($row);

    foreach ($byId as $id => $row) {
        if ($row['parent_id'] !== null && isset($byId[$row['parent_id']])) {
            $byId[$row['parent_id']]['has_children'] = true;
        }
    }

    // Filters last, for the reason given above.
    $out = [];
    foreach ($byId as $id => $row) {
        if ($activeOnly && !$row['is_active'])         continue;
        if ($portalOnly && !$row['is_portal_visible']) continue;
        // A category tied to a DIFFERENT type is not on offer. One with no type at
        // all is offered whatever the type is, including when no type is chosen yet.
        if ($typeId !== null && $row['effective_type_id'] !== null && $row['effective_type_id'] !== $typeId) continue;
        $out[$id] = $row;
    }

    // ⚠️ An active child whose ANCESTOR is retired must not remain pickable — the
    // parent is gone from the list, so the child would appear at the top level
    // wearing a path that no longer resolves. Same for portal visibility.
    if ($activeOnly || $portalOnly) {
        foreach ($out as $id => $row) {
            $cur = $row['parent_id'];
            while ($cur !== null && isset($byId[$cur])) {
                if (($activeOnly && !$byId[$cur]['is_active'])
                    || ($portalOnly && !$byId[$cur]['is_portal_visible'])) {
                    unset($out[$id]);
                    break;
                }
                $cur = $byId[$cur]['parent_id'];
            }
        }
    }

    // Depth-first, so a picker renders parents immediately above their children.
    return ticketCategorySortTree($out);
}

/**
 * Order a resolved set depth-first: each root, then its descendants, by
 * display_order then name at every level.
 *
 * @param array<int,array> $rows
 * @return array<int,array>
 */
function ticketCategorySortTree(array $rows): array
{
    $children = [];
    foreach ($rows as $r) {
        $p = ($r['parent_id'] !== null && isset($rows[$r['parent_id']])) ? $r['parent_id'] : 0;
        $children[$p][] = $r;
    }
    foreach ($children as &$set) {
        usort($set, static function ($a, $b) {
            return [$a['display_order'], mb_strtolower($a['name'])]
               <=> [$b['display_order'], mb_strtolower($b['name'])];
        });
    }
    unset($set);

    $out = [];
    $walk = static function ($parentKey) use (&$walk, &$out, $children) {
        foreach ($children[$parentKey] ?? [] as $row) {
            $out[$row['id']] = $row;
            $walk($row['id']);
        }
    };
    $walk(0);
    return $out;
}

/**
 * One category's full path, for display next to a ticket.
 *
 * Resolves against the WHOLE table rather than a company's visible list, and
 * ignores is_active — a closed ticket must still render the label it was closed
 * with, even after that category has been retired or hidden from a company.
 *
 * @return ?string null when the id is unknown
 */
function ticketCategoryPathLabel(PDO $conn, ?int $categoryId): ?string
{
    if (!$categoryId) return null;
    static $cache = [];
    if (array_key_exists($categoryId, $cache)) return $cache[$categoryId];

    $path  = [];
    $cur   = $categoryId;
    $steps = 0;
    try {
        $st = $conn->prepare("SELECT name, parent_id FROM ticket_categories WHERE id = ?");
        while ($cur !== null && $steps < TICKET_CATEGORY_MAX_DEPTH + 2) {
            $st->execute([$cur]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) break;
            array_unshift($path, (string) $row['name']);
            $cur = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $steps++;
        }
    } catch (Throwable $e) {
        return $cache[$categoryId] = null;
    }
    return $cache[$categoryId] = $path ? implode(TICKET_CATEGORY_SEPARATOR, $path) : null;
}

/**
 * The depth a NEW child of $parentId would sit at, 1 for a root.
 *
 * Used by the save endpoint to refuse a fourth level before it is written,
 * rather than discovering it when a report cannot render.
 */
function ticketCategoryDepthUnder(PDO $conn, ?int $parentId): int
{
    if (!$parentId) return 1;
    $depth = 1;
    $cur   = $parentId;
    try {
        $st = $conn->prepare("SELECT parent_id FROM ticket_categories WHERE id = ?");
        while ($cur !== null && $depth <= TICKET_CATEGORY_MAX_DEPTH + 2) {
            $st->execute([$cur]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) break;
            $depth++;
            $cur = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }
    } catch (Throwable $e) {
        return TICKET_CATEGORY_MAX_DEPTH + 1;   // unknown → refuse, don't guess
    }
    return $depth;
}

/**
 * How many levels the subtree rooted at $id occupies (1 = no children).
 *
 * Only needed when RE-PARENTING: the category itself may fit under its new
 * parent while its own children fall off the end of the depth cap.
 */
function ticketCategorySubtreeDepth(PDO $conn, int $id, int $guard = 0): int
{
    if ($guard > TICKET_CATEGORY_MAX_DEPTH + 2) return $guard;
    try {
        $st = $conn->prepare("SELECT id FROM ticket_categories WHERE parent_id = ?");
        $st->execute([$id]);
        $kids = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return TICKET_CATEGORY_MAX_DEPTH + 1;   // unknown → refuse, don't guess
    }
    if (!$kids) return 1;
    $deepest = 1;
    foreach ($kids as $kid) {
        $deepest = max($deepest, 1 + ticketCategorySubtreeDepth($conn, (int) $kid, $guard + 1));
    }
    return $deepest;
}

/**
 * Would making $parentId the parent of $id create a loop?
 *
 * A cycle makes every path unresolvable and every roll-up infinite, and the
 * schema cannot express "not a descendant of itself", so it is checked here.
 */
function ticketCategoryWouldCycle(PDO $conn, int $id, ?int $parentId): bool
{
    if (!$parentId) return false;
    if ($parentId === $id) return true;
    $cur   = $parentId;
    $steps = 0;
    try {
        $st = $conn->prepare("SELECT parent_id FROM ticket_categories WHERE id = ?");
        while ($cur !== null && $steps < 50) {
            if ($cur === $id) return true;
            $st->execute([$cur]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;
            $cur = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $steps++;
        }
    } catch (Throwable $e) {
        return true;   // cannot prove it is safe → refuse
    }
    return false;
}

/**
 * Resolution codes this company can see.
 *
 * @param array $opts activeOnly — drop retired codes (default false)
 */
function ticketResolutionCodesResolved(PDO $conn, ?int $tenantId, array $opts = []): array
{
    $where = !empty($opts['activeOnly']) ? 'is_active = 1' : '';
    $cols  = 'id, name, description, is_active, display_order, tenant_id';

    if ($tenantId !== null && $tenantId > 0) {
        $rows = getTenantConfigRows($conn, 'ticket_resolution_codes', 'ticket_resolution_code', $tenantId, $cols, $where, 'display_order, name');
    } else {
        try {
            $sql  = "SELECT $cols FROM ticket_resolution_codes" . ($where !== '' ? " WHERE $where" : '') . " ORDER BY display_order, name";
            $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'            => (int) $r['id'],
            'name'          => (string) $r['name'],
            'description'   => $r['description'],
            'is_active'     => (bool) $r['is_active'],
            'display_order' => (int) $r['display_order'],
            'tenant_id'     => $r['tenant_id'] === null ? null : (int) $r['tenant_id'],
        ];
    }
    return $out;
}

/**
 * One resolution code's name, ignoring is_active.
 *
 * Same reasoning as ticketCategoryPathLabel: a closed ticket keeps the label it
 * was closed with, whatever has happened to the list since.
 */
function ticketResolutionCodeName(PDO $conn, ?int $codeId): ?string
{
    if (!$codeId) return null;
    static $cache = [];
    if (array_key_exists($codeId, $cache)) return $cache[$codeId];
    try {
        $st = $conn->prepare("SELECT name FROM ticket_resolution_codes WHERE id = ?");
        $st->execute([$codeId]);
        $v = $st->fetchColumn();
        return $cache[$codeId] = ($v === false ? null : (string) $v);
    } catch (Throwable $e) {
        return $cache[$codeId] = null;
    }
}
