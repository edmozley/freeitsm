<?php
/**
 * The recent trail — "how did I get here", not "what did I look at".
 *
 * GH discussion #124 asked for internal tabs, in the shape the products
 * FreeITSM is an alternative to draw them: a horizontal strip along the top,
 * one tab per screen, managed by hand. That was declined — 91 server-rendered
 * pages cannot carry it without becoming a single-page app, the browser already
 * does tabs better than any of us would, and a tab strip is a desktop-only idea
 * in a product whose 16 modules were just made to work at 360px.
 *
 * What survived was the SENTENCE underneath the request: help somebody get back
 * to what they were doing when they had to jump out into a different module.
 *
 * This answers that sentence, and inverts every property of a tab bar on the way
 * — which is precisely what stops it reading as an imitation of one:
 *
 *   a tab bar is                     the trail is
 *   ─────────────────────────────    ────────────────────────────────────────
 *   automatic, every click a tab     ambient: nothing to open, close or manage
 *   ephemeral, per browser tab       per ANALYST — survives sign-out, follows
 *                                    you to another machine
 *   horizontal, eats vertical space  a pane in the waffle drawer, identical at
 *                                    360px and 1920px
 *   accumulates until tidied         capped, ages out, prunes itself
 *
 * 🔑 RECORDS, NOT SCREENS. "APPSVR01", never "Assets". A list of pages visited
 * is noise; a list of THINGS is the whole point.
 *
 * ⭐ AND IT IS AN OUTLINE, NOT A LIST — Ed's idea, and the thing that makes it
 * worth building rather than merely useful. Records are grouped under the module
 * they were viewed in, the way headings and body text sit in a word processor.
 * Go to Tickets and read three tickets: one "Tickets" heading, three rows under
 * it. Jump to Knowledge for an article: a "Knowledge" heading with the article
 * under it. Come BACK to tickets and you get a SECOND, NEW "Tickets" heading —
 * not an amendment to the first. The repetition is the information: it is what
 * turns a list of records into a picture of an afternoon's work, and it says
 * plainly WHY those three tickets were open together.
 *
 * ⚠️ SO THIS IS A LOG, NOT A "RECENTS" LIST, and that is a deliberate departure
 * from every other most-recently-used list in the product. The same ticket seen
 * twice in a day MUST appear twice, in two different groups, or the navigation
 * story it is drawing is a lie. There is therefore no unique key on
 * (analyst, type, id) — see the schema — and pruning does the job that a unique
 * key would otherwise have done.
 *
 * 🔴 ACCESS IS RE-CHECKED AT RENDER, NEVER AT WRITE TIME. A record you could
 * open on Monday and cannot open on Wednesday has to be GONE from Wednesday's
 * drawer. The rule inherited from record previews (#91) holds here in full:
 * the MODULE gate first, then the RECORD gate, and a record that is missing must
 * be indistinguishable from one that is forbidden — otherwise the trail becomes
 * a way to confirm that a record exists.
 *
 * ⚠️ NOT activeTenantFilter(). That answers "is this in the company I am
 * currently LOOKING AT", which is a view setting rather than a permission, and
 * would quietly drop records the analyst is perfectly entitled to open.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/entity_links.php';

/**
 * A new level-1 heading opens when the module changes, OR when this long has
 * passed since the previous record — whichever comes first.
 *
 * ⚠️ THE TIME HALF IS NOT OPTIONAL. Without it, closing the laptop in Tickets on
 * Tuesday and opening Tickets on Wednesday would APPEND to Tuesday's heading,
 * producing one group spanning two days under a single date. The gap rule is
 * what keeps a heading meaning "a sitting".
 */
const RECENT_TRAIL_GAP_MINUTES = 30;

/** Rows read per analyst before grouping. Filtering happens first, so this is
 *  deliberately larger than anything the drawer will show. */
const RECENT_TRAIL_READ_ROWS = 120;

/** Groups shown in the drawer. Beyond this you are not remembering, you are
 *  browsing history — and the search box is the tool for that. */
const RECENT_TRAIL_MAX_GROUPS = 20;

/** Rows kept per analyst. The cap a log needs in place of a unique key. */
const RECENT_TRAIL_KEEP_ROWS = 250;

/** And an age, so a dormant account does not carry last spring around forever. */
const RECENT_TRAIL_KEEP_DAYS = 60;

/**
 * Which module is a record's level-1 heading?
 *
 * ⚠️ These are WAFFLE MODULE KEYS, and they are load-bearing twice over: the
 * gate calls analystCanAccessModule() with them, and the drawer looks up the
 * heading's icon, colour, name and link by finding the module's own tile in the
 * grid above. A key that does not match a tile renders a heading without an icon
 * rather than an error, which is why the fallback in the JS exists.
 */
const RECENT_TRAIL_MODULES = [
    'ticket'            => 'tickets',
    'task'              => 'tasks',
    'problem'           => 'problems',
    'change'            => 'changes',
    'asset'             => 'assets',
    'cmdb_object'       => 'cmdb',
    'knowledge_article' => 'knowledge',
    'contract'          => 'contracts',
];

/**
 * Record that this analyst has just looked at this record — the PAGE LOAD path.
 *
 * Call it from a record page AFTER that page has decided the analyst may see the
 * record. It is a write on a GET, so it deliberately does nothing at all for a
 * request that is not a person opening a page: see recentTrailShouldRecord().
 * Records opened without a page load go through recentTrailWrite() instead.
 *
 * 🔴 NEVER THROWS. This is bookkeeping hung off the side of pages that have real
 * work to do — a ticket must still open if the trail table is missing, or is
 * mid-verification, or the install has not run Database Verification since
 * upgrading. Every failure here is swallowed on purpose.
 */
function entityVisit(string $type, int $id, ?PDO $conn = null): void
{
    if (!recentTrailShouldRecord()) {
        return;
    }
    recentTrailWrite($type, $id, $conn);
}

/**
 * The write itself, with no opinion about what kind of request it is.
 *
 * ⚠️ TWO CALLERS, AND THE SECOND ONE IS NOT OPTIONAL. entityVisit() covers a
 * record whose page the SERVER rendered — a deep link from search, a
 * notification, a preview, or a page that is a record in its own right such as
 * contracts/view.php.
 *
 * 🔴 BUT THAT IS THE MINORITY OF HOW RECORDS ARE ACTUALLY OPENED. Tickets,
 * tasks, assets, problems, changes and knowledge are all single screens whose
 * lists open a record IN PLACE, without a page load and (for most of them)
 * without even touching the URL. Server-side hooks alone would therefore have
 * recorded only the times you ARRIVED at a module from somewhere else, and
 * missed every ticket you opened while you were in it — which is precisely the
 * work the trail exists to lead you back to. api/system/recent_trail_visit.php
 * is the other half, and it calls this.
 */
function recentTrailWrite(string $type, int $id, ?PDO $conn = null): void
{
    if ($id <= 0 || !isset(RECENT_TRAIL_MODULES[$type])) {
        return;
    }
    $analystId = (int)($_SESSION['analyst_id'] ?? 0);
    if ($analystId <= 0) {
        return;
    }

    try {
        if ($conn === null) {
            $conn = connectToDatabase();
        }

        // ⚠️ COLLAPSE A REFRESH, BUT ONLY A REFRESH. If the newest row is already
        // this same record, move its timestamp rather than adding a row — reading
        // one long ticket over ten minutes, or hitting F5, is one visit and should
        // draw one line. But the check is against the newest row ONLY: open
        // another record in between and the chain breaks, so coming BACK to the
        // first one is a genuine second visit and gets its own row. That is the
        // difference between an outline of an afternoon and a deduplicated list.
        $last = $conn->prepare(
            "SELECT id, entity_type, entity_id
               FROM analyst_recent_trail
              WHERE analyst_id = ?
           ORDER BY visited_datetime DESC, id DESC
              LIMIT 1"
        );
        $last->execute([$analystId]);
        $row = $last->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['entity_type'] === $type && (int)$row['entity_id'] === $id) {
            $conn->prepare(
                "UPDATE analyst_recent_trail SET visited_datetime = UTC_TIMESTAMP() WHERE id = ?"
            )->execute([(int)$row['id']]);
            return;
        }

        // ⚠️ visited_datetime is NAMED, and set to UTC_TIMESTAMP(). Connections are
        // pinned to UTC (#1446) so the column default would in fact be correct
        // today — naming it anyway is the house rule that came out of GH #126,
        // where 302 INSERTs across 220 tables were letting CURRENT_TIMESTAMP fire
        // in whatever zone the server happened to be sitting in.
        $conn->prepare(
            "INSERT INTO analyst_recent_trail (analyst_id, entity_type, entity_id, visited_datetime)
             VALUES (?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$analystId, $type, $id]);

        // Prune on roughly one insert in ten. A log with no unique key needs a
        // cap, but paying for it on every record page anybody opens would be a
        // tax on the whole product for a drawer most of those visits never open.
        if (random_int(1, 10) === 1) {
            recentTrailPrune($conn, $analystId);
        }
    } catch (Throwable $e) {
        // Deliberately silent. See the note above.
    }
}

/**
 * Is this request a person opening a record page?
 *
 * ⚠️ WITHOUT THIS THE TRAIL FILLS WITH NOISE. The same module directories serve
 * XHR fragments, polling endpoints and print views, and a heartbeat that fires
 * every thirty seconds would otherwise write a "visit" every thirty seconds and
 * push everything you actually did off the end of the log.
 */
function recentTrailShouldRecord(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return false;
    }
    // An XHR is the page you are already on asking a question, not a new visit.
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
        return false;
    }
    // A prefetch or a prerender is the BROWSER guessing, not the analyst
    // choosing. Recording one would put a record in the trail that nobody ever
    // looked at, which is worse than missing one they did.
    $purpose = strtolower(($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_PURPOSE'] ?? ''));
    if (strpos($purpose, 'prefetch') !== false || strpos($purpose, 'prerender') !== false) {
        return false;
    }
    return true;
}

/** Keep the log to its cap, by count and by age. */
function recentTrailPrune(PDO $conn, int $analystId): void
{
    $conn->prepare(
        "DELETE FROM analyst_recent_trail
          WHERE analyst_id = ?
            AND visited_datetime < (UTC_TIMESTAMP() - INTERVAL " . RECENT_TRAIL_KEEP_DAYS . " DAY)"
    )->execute([$analystId]);

    // ⚠️ The derived table is not decoration: MySQL refuses a subquery that names
    // the table being deleted from, and materialising it into a JOIN is the
    // standard way round that. The cut-off row is found by OFFSET, so this stays
    // a single statement however far over the cap the analyst has gone.
    $conn->prepare(
        "DELETE t FROM analyst_recent_trail t
           JOIN (SELECT visited_datetime, id
                   FROM analyst_recent_trail
                  WHERE analyst_id = ?
               ORDER BY visited_datetime DESC, id DESC
                  LIMIT 1 OFFSET " . RECENT_TRAIL_KEEP_ROWS . ") cut
          WHERE t.analyst_id = ?
            AND (t.visited_datetime < cut.visited_datetime
                 OR (t.visited_datetime = cut.visited_datetime AND t.id <= cut.id))"
    )->execute([$analystId, $analystId]);
}

/**
 * The analyst's trail, resolved and grouped into level-1 headings.
 *
 * 🔴 FILTER FIRST, GROUP SECOND, and the order matters for two separate reasons:
 *
 *   1. A heading whose every record has been deleted or put out of reach must
 *      not render at all. Grouping first and filtering after would leave a dated
 *      "Assets" heading with nothing under it — which is both ugly and a small
 *      confession that SOMETHING was there.
 *   2. When a record in the middle of a run drops out, the runs either side of it
 *      are the same module and correctly merge into one. Grouping first would
 *      leave a seam where a now-invisible record used to be.
 *
 * @return array<int,array> Newest group first.
 */
function entityRecentTrail(PDO $conn, int $analystId): array
{
    if ($analystId <= 0) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT entity_type, entity_id, visited_datetime
           FROM analyst_recent_trail
          WHERE analyst_id = ?
       ORDER BY visited_datetime DESC, id DESC
          LIMIT " . RECENT_TRAIL_READ_ROWS
    );
    $stmt->execute([$analystId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    $labels = recentTrailLabels($conn, $analystId, $rows);

    $groups  = [];
    $current = null;
    foreach ($rows as $row) {
        $type = (string)$row['entity_type'];
        $id   = (int)$row['entity_id'];
        $key  = $type . ':' . $id;

        // Gone, or not yours any more. Silently absent — never a dead row.
        if (!isset($labels[$key])) {
            continue;
        }
        $module = RECENT_TRAIL_MODULES[$type] ?? null;
        if ($module === null) {
            continue;
        }

        $visited = (string)$row['visited_datetime'];
        $record  = [
            'type'    => $type,
            'id'      => $id,
            'label'   => $labels[$key],
            'url'     => entityLink($type, $id),
            'visited' => $visited,
        ];

        // ⚠️ The rows arrive NEWEST FIRST, so the gap is measured from the group's
        // EARLIEST row so far back to the one about to be added. The two are
        // adjacent in time even though the loop is walking backwards through it.
        $sameModule = $current !== null && $current['module'] === $module;

        if ($sameModule && recentTrailWithinGap($visited, $current['started'])) {
            $current['records'][] = $record;
            $current['started']   = $visited;
        } else {
            if ($current !== null) {
                $groups[] = $current;
            }
            $current = [
                'module'  => $module,
                'started' => $visited,
                'latest'  => $visited,
                'records' => [$record],
            ];
        }

        if (count($groups) >= RECENT_TRAIL_MAX_GROUPS) {
            $current = null;
            break;
        }
    }
    if ($current !== null) {
        $groups[] = $current;
    }

    return $groups;
}

/** Are two visits close enough in time to belong to the same sitting? */
function recentTrailWithinGap(string $earlier, string $later): bool
{
    $a = strtotime($earlier . ' UTC');
    $b = strtotime($later . ' UTC');
    if ($a === false || $b === false) {
        return false;
    }
    return abs($b - $a) <= RECENT_TRAIL_GAP_MINUTES * 60;
}

/**
 * Resolve a batch of trail rows to labels, dropping every one the analyst may
 * not see.
 *
 * ⚠️ WHY NOT recordPreview(). The preview resolver is the right AUTHORITY and
 * the wrong SHAPE: it runs a multi-join query per record to fill in a card of
 * fields, and the drawer needs one line of text. Sixty previews to draw sixty
 * one-line rows would make opening the drawer noticeably slow, on a control that
 * exists on all 91 screens. So the LABEL is fetched in one query per type and
 * the GATE is the very same per-record helper the preview uses — the expensive
 * half is batched, the authoritative half is not reimplemented.
 *
 * ⚠️ cmdb_object has no preview at all (previews cover seven types, links cover
 * eight), so there was never a resolver here to reuse for that one.
 *
 * @param array<int,array> $rows
 * @return array<string,string> "type:id" => label, for the visible ones only.
 */
function recentTrailLabels(PDO $conn, int $analystId, array $rows): array
{
    // The same record appears in several groups — a repeated visit is the whole
    // point of the outline — so resolve each DISTINCT record exactly once.
    $byType = [];
    foreach ($rows as $row) {
        $type = (string)$row['entity_type'];
        $id   = (int)$row['entity_id'];
        if ($id > 0 && isset(RECENT_TRAIL_MODULES[$type])) {
            $byType[$type][$id] = true;
        }
    }

    $out = [];
    foreach ($byType as $type => $ids) {
        // 🔴 THE MODULE GATE FIRST. Somebody with no access to Assets should learn
        // nothing about one, and that includes learning that they once looked at
        // it. One check for the whole type, before any query runs.
        if (!analystCanAccessModule($conn, $analystId, RECENT_TRAIL_MODULES[$type])) {
            continue;
        }
        try {
            foreach (recentTrailLabelsForType($conn, $analystId, $type, array_keys($ids)) as $id => $label) {
                $out[$type . ':' . $id] = $label;
            }
        } catch (Throwable $e) {
            // A type whose table is missing on this install contributes nothing,
            // rather than emptying the whole drawer.
        }
    }
    return $out;
}

/** One query for the labels of one type, then the record gate on each. */
function recentTrailLabelsForType(PDO $conn, int $analystId, string $type, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));

    switch ($type) {
        case 'ticket':
            $sql = "SELECT id, TRIM(CONCAT(COALESCE(ticket_number,''), ' ', COALESCE(subject,''))) AS label
                      FROM tickets WHERE id IN ($in)";
            $gate = fn($id) => analystCanAccessTicket($conn, $analystId, $id);
            break;

        case 'task':
            $sql  = "SELECT id, title AS label FROM tasks WHERE id IN ($in)";
            $gate = fn($id) => analystCanAccessTask($conn, $analystId, $id);
            break;

        case 'problem':
            $sql = "SELECT id, TRIM(CONCAT(COALESCE(problem_number,''), ' ', COALESCE(title,''))) AS label
                      FROM problems WHERE id IN ($in)";
            $gate = fn($id) => analystCanAccessProblem($conn, $analystId, $id);
            break;

        case 'change':
            $sql  = "SELECT id, title AS label FROM changes WHERE id IN ($in)";
            $gate = fn($id) => analystCanAccessChange($conn, $analystId, $id);
            break;

        case 'asset':
            // The same fallback ladder the asset preview uses, so a machine is
            // called the same thing in the drawer as it is on its own card.
            $sql = "SELECT id,
                           COALESCE(NULLIF(TRIM(hostname), ''),
                                    NULLIF(TRIM(CONCAT(COALESCE(manufacturer,''), ' ', COALESCE(model,''))), ''),
                                    NULLIF(TRIM(service_tag), '')) AS label
                      FROM assets WHERE id IN ($in)";
            $gate = fn($id) => analystCanAccessAsset($conn, $analystId, $id);
            break;

        case 'cmdb_object':
            $sql  = "SELECT id, name AS label FROM cmdb_objects WHERE id IN ($in)";
            $gate = fn($id) => analystCanAccessCmdbObject($conn, $analystId, $id);
            break;

        case 'contract':
            // ⚠️ No record gate: contracts carry no tenant_id, so the module gate
            // already applied is the whole of the check. Same as the preview, and
            // for the same reason — see includes/contract_assets.php.
            $sql = "SELECT id, TRIM(CONCAT(COALESCE(contract_number,''), ' ', COALESCE(title,''))) AS label
                      FROM contracts WHERE id IN ($in)";
            $gate = fn($id) => true;
            break;

        case 'knowledge_article':
            // 🔴 Knowledge visibility is folders, audiences and lifecycle — NOT a
            // tenancy filter. Its own SQL is folded into the label query so the
            // rule is applied by the module that owns it rather than approximated
            // here; an approximation would be a way to read a restricted article.
            require_once __DIR__ . '/knowledge/visibility.php';
            $viewer = KnowledgeViewer::forAnalyst($conn, $analystId);
            [$vis, $args] = knowledgeVisibilitySql($conn, $viewer, 'a');
            $stmt = $conn->prepare("SELECT a.id, a.title AS label FROM knowledge_articles a WHERE a.id IN ($in)" . $vis);
            $stmt->execute(array_merge($ids, $args));
            $found = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $label = trim((string)$r['label']);
                if ($label !== '') {
                    $found[(int)$r['id']] = $label;
                }
            }
            return $found;

        default:
            return [];
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($ids);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id    = (int)$r['id'];
        $label = trim((string)($r['label'] ?? ''));
        // A record with no name at all is dropped rather than rendered as a bare
        // "#41": a row you cannot recognise is not a way back to anything.
        if ($label === '' || !$gate($id)) {
            continue;
        }
        $out[$id] = $label;
    }
    return $out;
}
