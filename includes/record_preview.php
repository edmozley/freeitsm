<?php
/**
 * At-a-glance previews of a linked record (discussion #91).
 *
 * dschipfel asked for linked records to be clickable; that shipped. He then
 * asked for a preview so you can see what is on the other end of a link without
 * leaving the page, named the relationships that matter most, and asked for the
 * fields listed below. Read-only, by his request: "allowing edits from the
 * preview would add complexity and could increase the risk of accidental
 * changes."
 *
 * 🔴 A PREVIEW IS A READ. Everything here is a way to see a record you are
 * looking at a link to — and a link can be to something you are not allowed to
 * open. So every type checks BOTH the module and the record itself, and an
 * unreachable record returns exactly what a non-existent one returns: null.
 * Telling the two apart would confirm that a record exists, which is the thing
 * the check is there to withhold.
 *
 * ⚠️ ONE function per type, all reached through recordPreview(). Wiring a
 * preview into a new screen must never mean writing a new query.
 *
 * The fields are the ones promised in the discussion, so they are listed here
 * as the contract they are:
 *
 *   Ticket            number, subject, status, priority, who it is with, requester
 *   Task              title, status, assignee, due date, subtask progress
 *   Change            title, status, risk, planned window
 *   Problem           number, title, status, how many tickets are attached
 *   Asset             tag, make and model, serial, who holds it, warranty
 *   Contract          number, supplier, renewal date
 *   Knowledge article title, and the opening lines
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/entity_links.php';

/** The types a preview can be asked for, and the module each needs. */
const RECORD_PREVIEW_MODULES = [
    'ticket'            => 'tickets',
    'task'              => 'tasks',
    'change'            => 'changes',
    'problem'           => 'problems',
    'asset'             => 'assets',
    'contract'          => 'contracts',
    'knowledge_article' => 'knowledge',
    'domain'            => 'domains',
];

/**
 * A preview, or NULL when the type is unknown, the record does not exist, or
 * this analyst may not see it. One answer for all three, deliberately.
 *
 * @param string $type  the record type, a RECORD_PREVIEW_MODULES key such as 'ticket' or 'knowledge_article'
 * @param int $id  the record's id; 0 or less gives null
 */
function recordPreview(PDO $conn, int $analystId, string $type, int $id): ?array
{
    if ($id <= 0 || !isset(RECORD_PREVIEW_MODULES[$type])) {
        return null;
    }
    // The module gate first: somebody with no access to Assets should not learn
    // anything about one, however they arrived at the link.
    if (!analystCanAccessModule($conn, $analystId, RECORD_PREVIEW_MODULES[$type])) {
        return null;
    }

    $fn = 'recordPreview' . str_replace(' ', '', ucwords(str_replace('_', ' ', $type)));
    if (!function_exists($fn)) {
        return null;
    }
    $preview = $fn($conn, $analystId, $id);
    if ($preview === null) {
        return null;
    }

    $preview['type'] = $type;
    $preview['id']   = $id;
    $preview['url']  = entityLink($type, $id);
    return $preview;
}

/**
 * Shorthand for one line of a preview.
 *
 * @param string $label  the field's label as shown
 * @param mixed $value  the value to show; null or an empty string leaves the field out
 * @param ?string $colour  a status colour for the dot (CSS colour); anything that is not a plain colour is dropped
 */
function rpField(string $label, $value, ?string $colour = null): ?array
{
    $value = is_string($value) ? trim($value) : $value;
    // An empty field is left out rather than shown blank. A preview is a glance;
    // six labels with nothing beside them is worse than three with something.
    if ($value === null || $value === '' ) {
        return null;
    }

    // 🔒 The colour is the ONE value that does not reach the browser as text —
    // it is written into a style attribute. Escaping stops it breaking OUT of
    // the attribute, but not from adding a second declaration INSIDE it, so
    // `red;background-image:url(https://elsewhere/beacon)` would still fetch
    // from elsewhere every time somebody glanced at a record. These come from
    // status settings rather than from a visitor, and every one on this install
    // is clean — but "the only people who can set it are trusted" is a fact
    // about today, and an import or a future screen can change it. A colour
    // that is not a colour is dropped, and the field renders without a dot.
    if ($colour !== null && !preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,20}|rgba?\([0-9,.\s%]{5,40}\))$/', trim($colour))) {
        $colour = null;
    }

    return array_filter([
        'label'  => $label,
        'value'  => (string)$value,
        'colour' => $colour === null ? null : trim($colour),
    ], fn($v) => $v !== null);
}

/**
 * Drop the empties, so callers can just list every field they might have.
 *
 * @param array $fields  the fields from rpField(), nulls included - they are dropped
 */
function rpFields(array $fields): array
{
    return array_values(array_filter($fields));
}

// ── Ticket ──────────────────────────────────────────────────────────────────
// @param int $id  the tickets id
function recordPreviewTicket(PDO $conn, int $analystId, int $id): ?array
{
    // ⚠️ analystCanAccessTicket() is TENANCY only — it answers "is this ticket
    // in a company you can reach", not "may you read tickets". The module gate
    // in recordPreview() is the other half, and both are needed.
    if (!analystCanAccessTicket($conn, $analystId, $id)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT t.ticket_number, t.subject,
                s.name AS status, s.colour AS status_colour,
                p.name AS priority, p.colour AS priority_colour,
                a.full_name AS assignee,
                -- The house form for a person's name; a preferred name wins when
                -- there is one, as it does everywhere else a requester is shown.
                COALESCE(NULLIF(TRIM(u.preferred_name), ''), u.display_name) AS requester
           FROM tickets t
      LEFT JOIN ticket_statuses s    ON s.id = t.status_id
      LEFT JOIN ticket_priorities p  ON p.id = t.priority_id
      LEFT JOIN analysts a           ON a.id = t.assigned_analyst_id
      LEFT JOIN users u              ON u.id = t.user_id
          WHERE t.id = ?"
    );
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    return [
        'heading'  => trim(($r['ticket_number'] ?? '') . ' ' . ($r['subject'] ?? '')),
        'fields'   => rpFields([
            rpField(t('common.preview.status'),    $r['status'],   $r['status_colour']),
            rpField(t('common.preview.priority'),  $r['priority'], $r['priority_colour']),
            // ⚠️ The ANALYST only. A ticket carries no team of its own - there
            // is no assigned_team_id on the table - so there is nothing to fall
            // back to, unlike a task.
            rpField(t('common.preview.with'),      $r['assignee']),
            rpField(t('common.preview.requester'), $r['requester']),
        ]),
    ];
}

// ── Task ────────────────────────────────────────────────────────────────────
// @param int $id  the tasks id
function recordPreviewTask(PDO $conn, int $analystId, int $id): ?array
{
    // 🔴 analystCanAccessTask(), NOT activeTenantFilter(). The filter answers
    // "is this in the company I am CURRENTLY LOOKING AT", which is a view
    // setting, not a permission — so it refuses records the analyst is
    // perfectly entitled to open, and the refusal is indistinguishable from a
    // real one. Every module gates its own reads with these helpers; a preview
    // that were stricter would show a link and then deny what is behind it.
    if (!analystCanAccessTask($conn, $analystId, $id)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT tk.title, tk.due_date,
                s.name AS status, s.colour AS status_colour,
                a.full_name AS assignee, tm.name AS team,
                (SELECT COUNT(*) FROM tasks c WHERE c.parent_task_id = tk.id) AS subtasks,
                (SELECT COUNT(*) FROM tasks c
                   JOIN task_statuses cs ON cs.id = c.status_id
                  WHERE c.parent_task_id = tk.id AND cs.is_closed = 1) AS subtasks_done
           FROM tasks tk
      LEFT JOIN task_statuses s ON s.id = tk.status_id
      LEFT JOIN analysts a      ON a.id = tk.assigned_analyst_id
      LEFT JOIN teams tm        ON tm.id = tk.assigned_team_id
          WHERE tk.id = ?"
    );
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    $progress = (int)$r['subtasks'] > 0
        ? t('common.preview.subtask_progress', ['done' => (int)$r['subtasks_done'], 'total' => (int)$r['subtasks']])
        : null;

    return [
        'heading' => $r['title'],
        'fields'  => rpFields([
            rpField(t('common.preview.status'),   $r['status'], $r['status_colour']),
            rpField(t('common.preview.assignee'), $r['assignee'] ?: $r['team']),
            rpField(t('common.preview.due'),      $r['due_date']),
            rpField(t('common.preview.subtasks'), $progress),
        ]),
    ];
}

// ── Change ──────────────────────────────────────────────────────────────────
// @param int $id  the changes id
function recordPreviewChange(PDO $conn, int $analystId, int $id): ?array
{
    // 🔴 The module's own gate, not the active-company filter. See the note on
    // recordPreviewTask() for why the difference matters.
    if (!analystCanAccessChange($conn, $analystId, $id)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT c.title, c.risk_level, c.work_start_datetime, c.work_end_datetime,
                s.name AS status, s.colour AS status_colour
           FROM changes c
      LEFT JOIN change_statuses s ON s.id = c.status_id
          WHERE c.id = ?"
    );
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    // The planned window is two columns and reads as one fact.
    $window = null;
    if (!empty($r['work_start_datetime'])) {
        $window = substr((string)$r['work_start_datetime'], 0, 16);
        if (!empty($r['work_end_datetime'])) {
            $window .= ' → ' . substr((string)$r['work_end_datetime'], 0, 16);
        }
    }

    return [
        'heading' => $r['title'],
        'fields'  => rpFields([
            rpField(t('common.preview.status'), $r['status'], $r['status_colour']),
            rpField(t('common.preview.risk'),   $r['risk_level']),
            rpField(t('common.preview.window'), $window),
        ]),
    ];
}

// ── Problem ─────────────────────────────────────────────────────────────────
// @param int $id  the problems id
function recordPreviewProblem(PDO $conn, int $analystId, int $id): ?array
{
    // 🔴 The module's own gate, not the active-company filter. See the note on
    // recordPreviewTask(). This is the one the live run caught: a ticket showed
    // a problem pill and the preview behind it refused, because the problem sat
    // in a company other than the one the analyst happened to be filtered to.
    if (!analystCanAccessProblem($conn, $analystId, $id)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT p.problem_number, p.title,
                s.name AS status, s.colour AS status_colour,
                -- ⚠️ A JOIN TABLE, not a column on tickets. One problem can hold
                -- many tickets and the link is recorded in problem_tickets.
                (SELECT COUNT(*) FROM problem_tickets pt WHERE pt.problem_id = p.id) AS tickets
           FROM problems p
      LEFT JOIN problem_statuses s ON s.id = p.status_id
          WHERE p.id = ?"
    );
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    return [
        'heading' => trim(($r['problem_number'] ?? '') . ' ' . ($r['title'] ?? '')),
        'fields'  => rpFields([
            rpField(t('common.preview.status'),  $r['status'], $r['status_colour']),
            // Shown even when zero: "no tickets attached" is a fact about a
            // problem worth knowing, unlike an empty due date.
            rpField(t('common.preview.tickets'), (string)(int)$r['tickets']),
        ]),
    ];
}

// ── Asset ───────────────────────────────────────────────────────────────────
// @param int $id  the assets id
function recordPreviewAsset(PDO $conn, int $analystId, int $id): ?array
{
    if (!analystCanAccessAsset($conn, $analystId, $id)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT a.hostname, a.manufacturer, a.model, a.service_tag, a.warranty_expiry,
                ty.name AS type_name,
                -- ⚠️ An asset can be held by MORE THAN ONE person - a shared
                -- laptop, a meeting-room screen - so the count comes back with
                -- the name. Naming the most recent holder alone would say one
                -- person has something two people do.
                -- 🔴 Both kinds of holder, or the preview lies QUIETLY: the
                -- count below is COUNT(*) and includes analyst rows, so an
                -- analyst-held asset used to show a holder count of one with no name
                -- beside it. A blank where a name should be reads as a bug in
                -- the preview rather than as information.
                (SELECT COALESCE(NULLIF(TRIM(u.preferred_name), ''), u.display_name, ha.full_name)
                   FROM users_assets ua
                   LEFT JOIN users u     ON u.id  = ua.user_id
                   LEFT JOIN analysts ha ON ha.id = ua.analyst_id
                  WHERE ua.asset_id = a.id
                    AND (u.id IS NOT NULL OR ha.id IS NOT NULL)
                  ORDER BY ua.assigned_datetime DESC LIMIT 1) AS holder,
                (SELECT COUNT(*) FROM users_assets ua WHERE ua.asset_id = a.id) AS holders
           FROM assets a
      LEFT JOIN asset_types ty ON ty.id = a.asset_type_id
          WHERE a.id = ?"
    );
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    // The asset tag column only exists once Database Verification has run, so it
    // is fetched separately rather than naming it in the query above.
    $tag = null;
    require_once __DIR__ . '/asset_labels.php';
    if (assetLabelsSchemaReady($conn)) {
        $q = $conn->prepare("SELECT asset_tag FROM assets WHERE id = ?");
        $q->execute([$id]);
        $tag = $q->fetchColumn() ?: null;
    }

    $makeModel = trim(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? ''));

    return [
        'heading' => $r['hostname'] ?: ($tag ?: ($makeModel ?: ('#' . $id))),
        'fields'  => rpFields([
            rpField(t('common.preview.tag'),      $tag),
            rpField(t('common.preview.type'),     $r['type_name']),
            rpField(t('common.preview.model'),    $makeModel),
            rpField(t('common.preview.serial'),   $r['service_tag']),
            rpField(t('common.preview.held_by'),  (int)$r['holders'] > 1
                ? t('common.preview.held_by_many', ['name' => $r['holder'], 'n' => (int)$r['holders'] - 1])
                : $r['holder']),
            rpField(t('common.preview.warranty'), $r['warranty_expiry']),
        ]),
    ];
}

// ── Contract ────────────────────────────────────────────────────────────────
// @param int $id  the contracts id
function recordPreviewContract(PDO $conn, int $analystId, int $id): ?array
{
    // Contracts carry no tenant_id, but a CUSTOMER contract belongs to its customer's
    // company (#153): one this analyst may not see previews as nothing at all.
    require_once __DIR__ . '/contract_party.php';
    [$partyCols, $partyJoins] = contractPartySql($conn, 'c');
    [$vis, $visParams] = contractVisibilitySql($conn, $analystId, 'c');
    $stmt = $conn->prepare(
        "SELECT c.contract_number, c.title, c.contract_end, c.notice_date,
                s.legal_name AS supplier_name, s.trading_name AS supplier_trading_name,
                st.name AS status
                $partyCols
           FROM contracts c
      LEFT JOIN suppliers s          ON s.id = c.supplier_id
      LEFT JOIN contract_statuses st ON st.id = c.contract_status_id
                $partyJoins
          WHERE c.id = ?$vis"
    );
    $stmt->execute(array_merge([$id], $visParams));
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    return [
        'heading' => trim(($r['contract_number'] ?? '') . ' ' . ($r['title'] ?? '')),
        'fields'  => rpFields([
            rpField(t('common.preview.status'),   $r['status']),
            ($r['party_type'] ?? 'supplier') === 'customer'
                ? rpField(t('common.preview.customer'), contractPartyLabel($r))
                : rpField(t('common.preview.supplier'), contractPartyLabel($r)),
            rpField(t('common.preview.renewal'),  $r['contract_end']),
            // Not in the promised list, and included anyway: on a contract the
            // notice date is the one that costs money to miss.
            rpField(t('common.preview.notice'),   $r['notice_date']),
        ]),
    ];
}

// ── Domain (#154) ───────────────────────────────────────────────────────────
// @param int $id  the domains id
function recordPreviewDomain(PDO $conn, int $analystId, int $id): ?array
{
    // The domain's own record gate — never activeTenantFilter(), which is a list
    // filter and would answer "is it in my ACTIVE company" rather than "may I see it".
    if (!analystCanAccessDomain($conn, $analystId, $id)) return null;
    $stmt = $conn->prepare(
        "SELECT d.domain_name, d.display_name, d.expiry_date, d.security_grade, d.ssl_expiry_date,
                d.registrar_name, s.name AS status, a.full_name AS owner,
                COALESCE(NULLIF(sup.trading_name, ''), sup.legal_name) AS registrar
           FROM domains d
      LEFT JOIN domain_statuses s ON s.id = d.status_id
      LEFT JOIN analysts a        ON a.id = d.owner_analyst_id
      LEFT JOIN suppliers sup     ON sup.id = d.registrar_supplier_id
          WHERE d.id = ?"
    );
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    return [
        'heading' => $r['display_name'] ?: $r['domain_name'],
        'fields'  => rpFields([
            rpField(t('common.preview.status'),      $r['status']),
            rpField(t('common.preview.expires'),     $r['expiry_date']),
            rpField(t('common.preview.grade'),       $r['security_grade']),
            rpField(t('common.preview.registrar'),   $r['registrar'] ?: $r['registrar_name']),
            rpField(t('common.preview.owner'),       $r['owner']),
            rpField(t('common.preview.certificate'), $r['ssl_expiry_date']),
        ]),
    ];
}

// ── Knowledge article ───────────────────────────────────────────────────────
// @param int $id  the knowledge_articles id
function recordPreviewKnowledgeArticle(PDO $conn, int $analystId, int $id): ?array
{
    // 🔴 Knowledge has its own visibility rules — folders, audiences, lifecycle —
    // and they are not a tenancy filter. Reuse them rather than approximating:
    // an approximation here would be a way to read a restricted article.
    require_once __DIR__ . '/knowledge/visibility.php';
    $viewer = KnowledgeViewer::forAnalyst($conn, $analystId);
    [$vis, $args] = knowledgeVisibilitySql($conn, $viewer, 'a');

    $stmt = $conn->prepare("SELECT a.title, a.body FROM knowledge_articles a WHERE a.id = ?" . $vis);
    $stmt->execute(array_merge([$id], $args));
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    // The opening lines, as plain text. Tags are stripped rather than rendered:
    // a preview is a glance, and an article's own markup would fight the panel's.
    // The shared helper (PR #255): entities decoded, words kept apart across
    // tags, cut on a whole word. record-preview.js escapes it.
    require_once __DIR__ . '/knowledge/article_preview.php';
    $lead = knowledgeArticlePreview((string)$r['body'], 240);

    return [
        'heading' => $r['title'],
        'lead'    => $lead,
        'fields'  => [],
    ];
}
