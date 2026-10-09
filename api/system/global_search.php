<?php
/**
 * API: Global search for the command palette (⌘/Ctrl-K).
 *
 * A single aggregator that fans out across the entity types worth jumping to
 * from anywhere — tickets, CMDB configuration items and assets — and returns a
 * flat, typed result list the palette renders directly.
 *
 * Every source is gated two ways, so the palette can never surface something the
 * analyst couldn't otherwise reach:
 *   1. Module access — skipped entirely unless the module is in the analyst's
 *      allowed_modules (the same list the waffle launcher honours). Reads aren't
 *      capability-gated in FreeITSM, so module membership is the right gate here.
 *   2. Company scope — each query runs through the module's own tenancy filter
 *      (ticketTenantFilter / activeTenantFilter), exactly as that module's own
 *      list endpoint does, so results respect the active company.
 *
 * Each source is wrapped in its own try/catch: on a part-migrated install a
 * missing table just yields no rows for that type rather than failing the whole
 * search.
 *
 * Query params:
 *   q — text to match (required, min 2 chars); matched as a substring.
 *
 * Returns: { success, results: [ { type, module, id, title, subtitle, url } ] }
 * where `url` is relative to BASE_URL (the client prefixes it).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/knowledge/visibility.php';
require_once '../../includes/documents.php';
require_once '../../includes/entity_links.php';   // entityLink() — the one record→URL map

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId = (int) $_SESSION['analyst_id'];
$q = trim((string) ($_GET['q'] ?? ''));

// Min 2 chars — one letter matches half the database and isn't a useful jump.
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

// null allowed_modules = unrestricted (all modules). Matches the waffle panel.
$allowed = $_SESSION['allowed_modules'] ?? null;
$can = function (string $key) use ($allowed): bool {
    return $allowed === null || in_array($key, $allowed, true);
};

$like = '%' . $q . '%';
$perType = 6;   // a handful per source keeps the palette scannable
$results = [];

try {
    $conn = connectToDatabase();

    // --- Tickets: by reference or subject -------------------------------
    if ($can('tickets')) {
        try {
            [$tSql, $tArgs] = ticketTenantFilter($conn, $analystId, 't');
            $sql = "SELECT t.id, t.ticket_number, t.subject
                      FROM tickets t
                     WHERE t.deleted_datetime IS NULL
                       AND (t.ticket_number LIKE ? OR t.subject LIKE ?)" . $tSql . "
                     ORDER BY t.updated_datetime DESC
                     LIMIT " . $perType;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$like, $like], $tArgs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $results[] = [
                    'type'     => 'ticket',
                    'module'   => 'tickets',
                    'id'       => (int) $r['id'],
                    'title'    => $r['subject'],
                    'subtitle' => $r['ticket_number'],
                    'url'      => entityLink('ticket', (int) $r['id']),
                ];
            }
        } catch (Exception $e) { /* table not ready — no ticket results */ }
    }

    // --- CMDB configuration items: by name ------------------------------
    if ($can('cmdb')) {
        try {
            [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'o');
            $sql = "SELECT o.id, o.name, c.name AS class_name
                      FROM cmdb_objects o
                      JOIN cmdb_classes c ON c.id = o.class_id
                     WHERE o.name LIKE ?" . $tSql . "
                     ORDER BY o.name
                     LIMIT " . $perType;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$like], $tArgs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $results[] = [
                    'type'     => 'ci',
                    'module'   => 'cmdb',
                    'id'       => (int) $r['id'],
                    'title'    => $r['name'],
                    'subtitle' => $r['class_name'],
                    'url'      => entityLink('cmdb_object', (int) $r['id']),
                ];
            }
        } catch (Exception $e) { /* table not ready — no CI results */ }
    }

    // --- Assets: by hostname, service tag, or a searchable custom field --
    //
    // The custom-field half is a UNION rather than an OR against a join: a join
    // would multiply the asset row by however many field values matched, and
    // "MTG-TV-01" would appear three times in the palette.
    //
    // 🔑 Only fields ticked "include in search" are searched. The catalogue can
    // hold anything, and quietly searching a free-text notes field would make
    // half the estate match half the queries.
    if ($can('assets')) {
        try {
            [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'a');

            // Which fields opted in. Absent (or an install that has not run
            // Database Verification) simply means no custom-field matching.
            $searchable = [];
            try {
                $sf = $conn->query(
                    "SELECT id, label FROM asset_fields WHERE is_deleted = 0 AND is_searchable = 1"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($sf as $row) { $searchable[(int)$row['id']] = $row['label']; }
            } catch (Exception $e) { /* schema not ready */ }

            // ⚠️ TWO QUERIES, merged in PHP — NOT a UNION.
            //
            // A UNION of `NULL AS matched_value` against
            // `COALESCE(value_text, CAST(value_number AS CHAR))` fails with
            // "Illegal mix of collations": the CAST takes the connection's
            // collation while the column has the table's. Fixable with an
            // explicit COLLATE, but that hardcodes a collation name into a
            // product that supports both MySQL and MariaDB. Two queries cost
            // nothing here (both are indexed and capped) and cannot break that
            // way at all.
            $rows = [];

            $stmt = $conn->prepare(
                "SELECT a.id, a.hostname, a.service_tag, NULL AS matched_field, NULL AS matched_value
                   FROM assets a
                  WHERE (a.hostname LIKE ? OR a.service_tag LIKE ?)" . $tSql . "
                  ORDER BY a.hostname LIMIT " . $perType
            );
            $stmt->execute(array_merge([$like, $like], $tArgs));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($searchable) {
                $ph = implode(',', array_fill(0, count($searchable), '?'));
                // value_text covers text / dropdown / url / email; a number is
                // cast so "27" finds a 27-inch monitor. Dates and booleans are
                // deliberately not matched — nobody types a date into ⌘K, and
                // "yes" would match half the estate.
                $fs = $conn->prepare(
                    "SELECT a.id, a.hostname, a.service_tag, v.field_id AS matched_field,
                            COALESCE(v.value_text, CAST(v.value_number AS CHAR)) AS matched_value
                       FROM assets a
                       JOIN asset_field_values v ON v.asset_id = a.id
                      WHERE v.field_id IN ({$ph})
                        AND (v.value_text LIKE ? OR CAST(v.value_number AS CHAR) LIKE ?)" . $tSql . "
                      ORDER BY a.hostname LIMIT " . $perType
                );
                $fs->execute(array_merge(array_keys($searchable), [$like, $like], $tArgs));
                // Hostname matches first, so they win the dedupe below — that is
                // what somebody typing a hostname expects to see.
                $rows = array_merge($rows, $fs->fetchAll(PDO::FETCH_ASSOC));
            }

            // One asset can match both ways; the first row for an id wins.
            $seenAssets = [];
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                if (isset($seenAssets[$id])) {
                    continue;
                }
                $seenAssets[$id] = true;
                $tag = trim((string) ($r['service_tag'] ?? ''));
                // Say WHY it matched when it was a custom field — otherwise a
                // result appears with no visible connection to what was typed.
                $subtitle = $tag;
                if (!empty($r['matched_field']) && isset($searchable[(int)$r['matched_field']])) {
                    $subtitle = $searchable[(int)$r['matched_field']] . ': ' . $r['matched_value'];
                }
                $results[] = [
                    'type'     => 'asset',
                    'module'   => 'assets',
                    'id'       => $id,
                    'title'    => $r['hostname'],
                    'subtitle' => $subtitle,
                    'url'      => entityLink('asset', (int) $id),
                ];
            }
        } catch (Exception $e) { /* table not ready — no asset results */ }
    }

    // Digits pulled from the query, used where a record's reference is derived
    // from its id rather than stored (changes: "CHG-0047" isn't a column, so
    // searching "47" or "CHG-47" must match id 47).
    $digits = preg_replace('/\D+/', '', $q);

    // --- Changes: by title, or by id via its CHG-#### reference ----------
    if ($can('changes')) {
        try {
            [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'c');
            $idClause = $digits !== '' ? ' OR c.id = ?' : '';
            $sql = "SELECT c.id, c.title
                      FROM changes c
                     WHERE (c.title LIKE ?" . $idClause . ")" . $tSql . "
                     ORDER BY c.modified_datetime DESC
                     LIMIT " . $perType;
            $params = $digits !== '' ? [$like, (int) $digits] : [$like];
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($params, $tArgs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $results[] = [
                    'type'     => 'change',
                    'module'   => 'changes',
                    'id'       => (int) $r['id'],
                    'title'    => $r['title'],
                    'subtitle' => sprintf('CHG-%04d', (int) $r['id']),
                    'url'      => entityLink('change', (int) $r['id']),
                ];
            }
        } catch (Exception $e) { /* table not ready — no change results */ }
    }

    // --- Problems: by reference or title --------------------------------
    if ($can('problems')) {
        try {
            [$tSql, $tArgs] = ticketTenantFilter($conn, $analystId, 'p');
            $sql = "SELECT p.id, p.problem_number, p.title
                      FROM problems p
                     WHERE (p.problem_number LIKE ? OR p.title LIKE ?)" . $tSql . "
                     ORDER BY p.updated_datetime DESC
                     LIMIT " . $perType;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$like, $like], $tArgs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $results[] = [
                    'type'     => 'problem',
                    'module'   => 'problems',
                    'id'       => (int) $r['id'],
                    'title'    => $r['title'],
                    'subtitle' => (string) ($r['problem_number'] ?? ''),
                    'url'      => entityLink('problem', (int) $r['id']),
                ];
            }
        } catch (Exception $e) { /* table not ready — no problem results */ }
    }

    // --- Knowledge articles: by title -----------------------------------
    // knowledgeTenantFilter, NOT activeTenantFilter — for Knowledge a NULL
    // tenant means "shared with every company", the opposite of tickets/assets.
    // Archived articles are excluded; unpublished drafts are kept (analysts
    // should still find their own work-in-progress).
    if ($can('knowledge')) {
        try {
            // 'unarchived' keeps the documented behaviour above: archived
            // articles out, unpublished drafts IN, so an analyst can still find
            // their own work in progress.
            [$tSql, $tArgs] = knowledgeVisibilitySql(
                $conn, KnowledgeViewer::forAnalyst($conn, $analystId), 'a', ['lifecycle' => 'unarchived']
            );
            $sql = "SELECT a.id, a.title
                      FROM knowledge_articles a
                     WHERE a.title LIKE ?" . $tSql . "
                     ORDER BY a.modified_datetime DESC
                     LIMIT " . $perType;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$like], $tArgs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $results[] = [
                    'type'     => 'knowledge',
                    'module'   => 'knowledge',
                    'id'       => (int) $r['id'],
                    'title'    => $r['title'],
                    'subtitle' => '',
                    'url'      => entityLink('knowledge_article', (int) $r['id']),
                ];
            }
        } catch (Exception $e) { /* table not ready — no knowledge results */ }
    }

    // --- Contracts: by reference or title -------------------------------
    // Contracts have no tenant_id, but a CUSTOMER contract belongs to its
    // customer's company and is hidden from analysts outside it (#153).
    if ($can('contracts')) {
        try {
            require_once __DIR__ . '/../../includes/contract_party.php';
            [$kVis, $kArgs] = contractVisibilitySql($conn, $analystId, 'k');
            $sql = "SELECT k.id, k.contract_number, k.title
                      FROM contracts k
                     WHERE (k.contract_number LIKE ? OR k.title LIKE ?)$kVis
                     ORDER BY k.title
                     LIMIT " . $perType;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$like, $like], $kArgs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $results[] = [
                    'type'     => 'contract',
                    'module'   => 'contracts',
                    'id'       => (int) $r['id'],
                    'title'    => $r['title'],
                    'subtitle' => (string) ($r['contract_number'] ?? ''),
                    'url'      => entityLink('contract', (int) $r['id']),
                ];
            }
        } catch (Exception $e) { /* table not ready — no contract results */ }
    }

    // --- People (#153): a person by name, email or job; a company by name -----
    // Only people in companies the analyst can access - the same rule as the
    // person page the result opens.
    if ($can('people')) {
        try {
            [$uSql, $uArgs] = isMultiTenant($conn) ? allAccessibleTenantsFilter($conn, $analystId, 'u.tenant_id') : ['', []];
            $sql = "SELECT u.id, COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, ''), u.email, u.username) AS name,
                           u.email, u.job_title, u.is_active
                      FROM users u
                     WHERE (u.display_name LIKE ? OR u.preferred_name LIKE ? OR u.email LIKE ? OR u.job_title LIKE ?)" . $uSql . "
                     ORDER BY u.is_active DESC, name
                     LIMIT " . $perType;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$like, $like, $like, $like], $uArgs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $results[] = [
                    'type'     => 'person',
                    'module'   => 'people',
                    'id'       => (int) $r['id'],
                    'title'    => (string) $r['name'],
                    'subtitle' => trim(($r['job_title'] ?? '') . ($r['email'] && $r['email'] !== $r['name'] ? ' · ' . $r['email'] : ''), ' ·'),
                    'url'      => entityLink('person', (int) $r['id']),
                ];
            }
            if (isMultiTenant($conn)) {
                $ids = array_map('intval', getAccessibleTenantIds($conn, $analystId));
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $conn->prepare("SELECT id, name FROM tenants WHERE name LIKE ? AND id IN ($in) ORDER BY name LIMIT " . $perType);
                    $stmt->execute(array_merge([$like], $ids));
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $results[] = [
                            'type' => 'company', 'module' => 'people', 'id' => (int) $r['id'],
                            'title' => $r['name'], 'subtitle' => '', 'url' => entityLink('company', (int) $r['id']),
                        ];
                    }
                }
            }
        } catch (Exception $e) { /* no people results */ }
    }

    // --- Suppliers and supplier contacts (#153 step 3) ----------------------
    // Contracts' records, shown in People: both modules, as the pages need.
    // Install-wide, like Contracts itself - they have no company.
    if ($can('people') && $can('contracts')) {
        try {
            $stmt = $conn->prepare("SELECT id, COALESCE(NULLIF(trading_name, ''), legal_name) AS name, legal_name, city
                                      FROM suppliers WHERE legal_name LIKE ? OR trading_name LIKE ?
                                     ORDER BY is_active DESC, name LIMIT " . $perType);
            $stmt->execute([$like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $results[] = [
                    'type' => 'supplier', 'module' => 'people', 'id' => (int) $r['id'], 'title' => (string) $r['name'],
                    'subtitle' => (string) ($r['legal_name'] !== $r['name'] ? $r['legal_name'] : ($r['city'] ?? '')),
                    'url' => entityLink('supplier', (int) $r['id']),
                ];
            }
            $stmt = $conn->prepare("SELECT c.id, TRIM(CONCAT(c.first_name, ' ', c.surname)) AS name, c.email, c.job_title,
                                           COALESCE(NULLIF(s.trading_name, ''), s.legal_name) AS supplier
                                      FROM contacts c LEFT JOIN suppliers s ON s.id = c.supplier_id
                                     WHERE CONCAT(c.first_name, ' ', c.surname) LIKE ? OR c.email LIKE ?
                                     ORDER BY c.is_active DESC, name LIMIT " . $perType);
            $stmt->execute([$like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $results[] = [
                    'type' => 'supplier_contact', 'module' => 'people', 'id' => (int) $r['id'], 'title' => (string) $r['name'],
                    'subtitle' => implode(' · ', array_filter([$r['supplier'], $r['job_title'], $r['email']])),
                    'url' => entityLink('supplier_contact', (int) $r['id']),
                ];
            }
        } catch (Exception $e) { /* no supplier results */ }
    }

    // --- Domains (#154): by name, registrar or tag -------------------------
    // Scoped data: the analyst's active company, as the register's own list.
    if ($can('domains')) {
        try {
            [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'd');
            $sql = "SELECT d.id, d.domain_name, d.display_name, d.expiry_date, d.registrar_name
                      FROM domains d
                     WHERE (d.domain_name LIKE ? OR d.display_name LIKE ? OR d.registrar_name LIKE ? OR d.tags LIKE ?)" . $tSql . "
                     ORDER BY d.domain_name
                     LIMIT " . $perType;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$like, $like, $like, $like], $tArgs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $results[] = [
                    'type'     => 'domain',
                    'module'   => 'domains',
                    'id'       => (int) $r['id'],
                    'title'    => $r['display_name'] ?: $r['domain_name'],
                    'subtitle' => trim(($r['registrar_name'] ?? '') . ($r['expiry_date'] ? ' · ' . $r['expiry_date'] : ''), ' ·'),
                    'url'      => entityLink('domain', (int) $r['id']),
                ];
            }
        } catch (Exception $e) { /* table not ready — no domain results */ }
    }

    // --- Projects (3.2.0): by name, goal or the PRJ-0042 reference ------------
    // Scoped data: the analyst's active company, as the portfolio's own list.
    if ($can('projects')) {
        try {
            [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'p');
            // Members-only projects (3.3.0).
            require_once __DIR__ . '/../../includes/projects/visibility.php';
            [$vSql, $vArgs] = projectVisibleSql($conn, $analystId, 'p');
            $tSql .= $vSql; $tArgs = array_merge($tArgs, $vArgs);
            $num = preg_match('/^(?:prj-?)?0*(\d+)$/i', trim($q), $m) ? (int)$m[1] : 0;
            $sql = "SELECT p.id, p.name, p.status, p.target_end_date
                      FROM projects p
                     WHERE (p.name LIKE ? OR p.goal LIKE ? OR p.id = ?)" . $tSql . "
                     ORDER BY FIELD(p.status, 'active', 'proposed', 'on_hold', 'closed', 'cancelled'), p.name
                     LIMIT " . $perType;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$like, $like, $num], $tArgs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $results[] = [
                    'type'     => 'project',
                    'module'   => 'projects',
                    'id'       => (int) $r['id'],
                    'title'    => $r['name'],
                    'subtitle' => trim('PRJ-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT) . ' · ' . ucfirst(str_replace('_', ' ', $r['status'])) . ($r['target_end_date'] ? ' · ' . $r['target_end_date'] : '')),
                    'url'      => entityLink('project', (int) $r['id']),
                ];
            }
        } catch (Exception $e) { /* table not ready — no project results */ }
    }

    // --- Attached documents (discussion #76) -----------------------------
    //
    // ⚠️ THE ONLY SOURCE HERE WHOSE PERMISSION IS NOT ITS OWN. Every block above
    // gates on one module and filters by that module's tenancy. A document
    // belongs to no module: it is readable if you can see at least one RECORD it
    // is attached to, which may be a contract, an asset, a ticket or a CMDB
    // object. So the gate is "can you reach any attachable type at all", and the
    // real check is documentVisibilityClause() — the same SQL the download
    // endpoint and the main search use, so all three agree by construction.
    //
    // Matched on title and description with LIKE, in keeping with every other
    // block here: the palette is for jumping to a thing you can half-name. The
    // full-text search of a document's CONTENTS lives in the corpus, which the
    // ticket-content block below queries.
    if (documentAccessibleTypes($allowed)) {
        try {
            [$dSql, $dArgs] = documentVisibilityClause($conn, $analystId, $allowed, 'd');
            $sql = "SELECT d.id, d.kind, d.title, d.description, d.external_url
                      FROM documents d
                     WHERE d.deleted_datetime IS NULL
                       AND (d.title LIKE ? OR d.description LIKE ? OR d.original_name LIKE ?)"
                       . $dSql . "
                     ORDER BY d.created_datetime DESC
                     LIMIT " . $perType;
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$like, $like, $like], $dArgs));

            // Name one place it is attached, so a result is not just a filename
            // floating free — but ONLY a record this caller can see, or the
            // subtitle would disclose a contract they have no access to.
            $linkStmt = $conn->prepare(
                "SELECT parent_type, parent_id FROM document_links WHERE document_id = ?"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $subtitle = '';
                $linkStmt->execute([(int) $r['id']]);
                foreach ($linkStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
                    if (!documentCanViewParent($conn, $analystId, $allowed, (string) $l['parent_type'], (int) $l['parent_id'])) {
                        continue;
                    }
                    $def = documentEntityDef((string) $l['parent_type']);
                    $subtitle = $def['label'];
                    $nm = documentParentName($conn, (string) $l['parent_type'], (int) $l['parent_id']);
                    if ($nm) $subtitle .= ': ' . $nm;
                    break;
                }
                $results[] = [
                    'type'     => 'document',
                    'module'   => 'system',
                    'id'       => (int) $r['id'],
                    'title'    => $r['title'],
                    'subtitle' => $subtitle,
                    // Always our own endpoint, never the external URL: it
                    // authorises, it records the access, and a link row answers
                    // with its destination rather than being redirected to.
                    'url'      => 'api/documents/download.php?id=' . (int) $r['id'],
                ];
            }
        } catch (Exception $e) { /* table not ready — no document results */ }
    }

    // --- Inside ticket content: messages and notes (discussion #53) ------
    //
    // Every source above matches a NAME or a REFERENCE. This one asks the search
    // corpus, so the palette finds a phrase buried in the fourth reply of a
    // two-year-old ticket — the thing discussion #53 asked for.
    //
    // ⚠️ DELIBERATELY LAST, and capped harder than the rest. The palette is a
    // jump-to-a-thing tool: type a hostname, press Enter, you are there. Content
    // hits are a different intent — find where something was SAID — and mixing
    // them in would mean "LT-001" returns message snippets above the asset
    // called LT-001. Appended last, so the client renders them in their own
    // group below the name matches.
    //
    // It does NOT decide permissions itself: searchScopeForAnalyst builds the
    // predicate and the search function applies it INSIDE the query, the same as
    // api/tickets/search_content.php. Filtering here would starve the results.
    if ($can('tickets')) {
        try {
            require_once '../../includes/search/search.php';

            // Tickets already found by number or subject above. A content hit on
            // the same ticket is not a second thing to jump to.
            $already = [];
            foreach ($results as $r) {
                if (($r['type'] ?? '') === 'ticket') $already[(int)$r['id']] = true;
            }

            $scope = searchScopeForAnalyst($conn, $analystId, ['include_internal' => true]);
            $res   = searchCorpusQuery($conn, $q, $scope, ['limit' => $perType + count($already)]);

            if (!empty($res['ok']) && !empty($res['results'])) {
                $ids = [];
                $articleIds = [];
                foreach ($res['results'] as $g) {
                    $tid = $g['ticket_id'] ?? null;
                    if ($tid !== null) {
                        if (!isset($already[(int)$tid])) $ids[] = (int)$tid;
                        continue;
                    }
                    // No ticket_id means a source that hangs off no ticket. The
                    // search groups those individually (COALESCE(ticket_id, -id)),
                    // so each article arrives as its own result.
                    foreach ($g['hits'] as $h) {
                        if (($h['source_type'] ?? '') === SEARCH_SOURCE_KB_ARTICLE) {
                            $articleIds[] = (int)$h['source_id'];
                        }
                    }
                }
                $ids = array_slice($ids, 0, $perType);

                if ($ids) {
                    // Read the display fields from `tickets`, not the corpus —
                    // the corpus is a search index, not a second ticket list.
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $st = $conn->prepare(
                        "SELECT id, ticket_number, subject FROM tickets
                          WHERE id IN ($in) AND deleted_datetime IS NULL"
                    );
                    $st->execute($ids);
                    $meta = [];
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $meta[(int)$r['id']] = $r;

                    // Keep the corpus's relevance order rather than the SQL's.
                    foreach ($ids as $tid) {
                        if (!isset($meta[$tid])) continue;
                        $m = $meta[$tid];
                        $results[] = [
                            'type'     => 'ticket_content',
                            'module'   => 'tickets',
                            'id'       => $tid,
                            'title'    => $m['subject'],
                            'subtitle' => $m['ticket_number'],
                            'url'      => entityLink('ticket', (int) $tid),
                        ];
                    }
                }

                // Articles found by their TEXT rather than their title. Gated on
                // the knowledge module separately — the corpus scope decides what
                // this analyst may read, but module access decides whether they
                // should be offered the module at all.
                if ($articleIds && $can('knowledge')) {
                    $already = [];
                    foreach ($results as $r) {
                        if (($r['type'] ?? '') === 'knowledge') $already[(int)$r['id']] = true;
                    }
                    $articleIds = array_values(array_diff(array_unique($articleIds), array_keys($already)));
                    $articleIds = array_slice($articleIds, 0, $perType);

                    if ($articleIds) {
                        $in = implode(',', array_fill(0, count($articleIds), '?'));
                        $st = $conn->prepare(
                            "SELECT id, title FROM knowledge_articles
                              WHERE id IN ($in) AND (is_archived = 0 OR is_archived IS NULL)"
                        );
                        $st->execute($articleIds);
                        $ameta = [];
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $ameta[(int)$r['id']] = $r;

                        foreach ($articleIds as $aid) {
                            if (!isset($ameta[$aid])) continue;
                            $results[] = [
                                'type'     => 'article_content',
                                'module'   => 'knowledge',
                                'id'       => $aid,
                                'title'    => $ameta[$aid]['title'],
                                'subtitle' => '',
                                // Same URL shape as the title-matching knowledge
                                // source above, not one invented here.
                                'url'      => entityLink('knowledge_article', (int) $aid),
                            ];
                        }
                    }
                }
            }
        } catch (Exception $e) { /* corpus absent or not verified — no content results */ }
    }

    // --- Inside attached documents (discussion #76) ----------------------
    //
    // The text INSIDE a file — the PDF that mentions a serial number, the
    // contract that mentions a clause. Everything above matches a document's
    // name; this matches what is in it.
    //
    // ⚠️ ITS OWN CORPUS QUERY, not folded into the block above, and the reason
    // is a leak. That block is gated on $can('tickets') because the corpus does
    // not know about module access — a corpus row is judged on tenancy and an
    // internal flag alone. Loosening that gate so a contracts-only analyst could
    // reach document content would have handed them ticket content too. So this
    // asks a question restricted to source_type='document', which the scope's
    // document clause then filters to what they may actually see.
    if (documentAccessibleTypes($allowed)) {
        try {
            require_once '../../includes/search/search.php';
            require_once '../../includes/search/documents_index.php';

            // A document already listed by NAME above is not a second thing to
            // offer; the name match is the better row.
            $already = [];
            foreach ($results as $r) {
                if (($r['type'] ?? '') === 'document') $already[(int) $r['id']] = true;
            }

            $scope = searchScopeForAnalyst($conn, $analystId, [
                'source_types'   => [SEARCH_SOURCE_DOCUMENT],
                'require_ticket' => false,
            ]);
            $res = searchCorpusQuery($conn, $q, $scope, ['limit' => $perType + count($already)]);

            if (!empty($res['ok']) && !empty($res['results'])) {
                $docIds = [];
                foreach ($res['results'] as $g) {
                    foreach (($g['hits'] ?? []) as $h) {
                        if (($h['source_type'] ?? '') !== SEARCH_SOURCE_DOCUMENT) continue;
                        $did = (int) $h['source_id'];
                        if (!isset($already[$did])) $docIds[$did] = true;
                    }
                }
                $docIds = array_slice(array_keys($docIds), 0, $perType);

                if ($docIds) {
                    // Display fields from `documents`, not from the corpus — the
                    // corpus is a search index, not a second documents table.
                    $in = implode(',', array_fill(0, count($docIds), '?'));
                    $st = $conn->prepare(
                        "SELECT id, title, original_name FROM documents
                          WHERE id IN ($in) AND deleted_datetime IS NULL"
                    );
                    $st->execute($docIds);
                    $meta = [];
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $meta[(int) $r['id']] = $r;

                    $linkStmt = $conn->prepare(
                        "SELECT parent_type, parent_id FROM document_links WHERE document_id = ?"
                    );
                    foreach ($docIds as $did) {          // keep the corpus's relevance order
                        if (!isset($meta[$did])) continue;

                        // Name one place it lives, and only one this caller can see.
                        $subtitle = '';
                        $linkStmt->execute([$did]);
                        foreach ($linkStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
                            if (!documentCanViewParent($conn, $analystId, $allowed, (string) $l['parent_type'], (int) $l['parent_id'])) continue;
                            $def = documentEntityDef((string) $l['parent_type']);
                            $subtitle = $def['label'];
                            $nm = documentParentName($conn, (string) $l['parent_type'], (int) $l['parent_id']);
                            if ($nm) $subtitle .= ': ' . $nm;
                            break;
                        }

                        $results[] = [
                            'type'     => 'document_content',
                            'module'   => 'system',
                            'id'       => $did,
                            'title'    => $meta[$did]['title'],
                            'subtitle' => $subtitle,
                            'url'      => 'api/documents/download.php?id=' . $did,
                        ];
                    }
                }
            }
        } catch (Exception $e) { /* corpus absent — no document content results */ }
    }

    echo json_encode(['success' => true, 'results' => $results]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
