<?php
/**
 * Documents — attach files (or links to an external DMS) to anything.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  THE ONE RULE THIS FILE EXISTS TO ENFORCE
 *
 *  A document has NO permissions of its own. It is visible if — and only if —
 *  you can see at least one of the things it is attached to.
 *
 *  Ed's decision, in his words: "if you can see the thing and the thing has
 *  document(s) attached then you should see those attachments." Which means
 *  attaching a document somewhere widely visible WIDENS who can read it. That is
 *  intended, and it is why the attach UI must show what else a document is on.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 🔑 WHY THERE IS NO PERMISSIONS TABLE HERE. The obvious design — a generic
 * per-object ACL table, with principals and groups — would mean building a
 * permission subsystem FreeITSM does not have, across every module, before the
 * feature could ship. FreeITSM already answers "can this analyst see X": module
 * membership (reads are not capability-gated — see api/system/global_search.php)
 * plus the module's own tenancy filter, plus a handful of per-record rules that
 * already live in includes/tenancy.php. This file borrows those rather than
 * inventing a rival to them. Storing visibility on the document would also be a
 * bug, not just duplication: permissions change after the row is written.
 *
 * 🔑 TWO SHAPES OF THE SAME QUESTION. Downloading asks about ONE document, so it
 * can afford an exact check. Searching asks about a SET, and checking N documents
 * one at a time is a loop that gets slower as the product succeeds. So:
 *
 *     documentCanView()          — one document. The download/preview boundary.
 *     documentVisibilityClause() — SQL. Constrains a search to what you may see.
 *
 * Both read the SAME registry below, so they cannot drift apart.
 *
 * ⚠️ FAIL CLOSED. An analyst with no accessible entity types gets `0=1`, not an
 * empty filter. An empty filter would return everything.
 */

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/knowledge/visibility.php';

/** Where uploaded documents live, relative to the uploads root. */
const DOCUMENT_STORAGE_DIR = 'documents';

/** A document is either a file we hold, or a link to somewhere else. */
const DOCUMENT_KIND_FILE = 'file';
const DOCUMENT_KIND_LINK = 'link';

/**
 * Every kind of thing a document can be attached to.
 *
 * ⚠️ ADDING A MODULE IS ADDING AN ENTRY HERE, AND NOTHING ELSE. That is the
 * whole point — the document system never learns any module's rules.
 *
 * Each entry:
 *   module  — the key in the analyst's allowed_modules (the read gate)
 *   table   — where the parent lives
 *   label   — what to call it in the UI
 *   title   — column to show as the parent's name
 *   url     — sprintf pattern taking the parent id, for linking back to the
 *             record. Relative to the install root, as the palette's urls are.
 *   can     — fn(PDO,int $analystId,int $id): bool   exact check, or null for
 *             "module + filter is the whole rule"
 *   filter  — fn(PDO,int $analystId,string $alias): [sql,params]  the SET form,
 *             or null when the entity has no per-row scoping at all
 *   alive   — extra SQL keeping deleted parents out (a deleted parent must not
 *             keep a document visible)
 */
function documentEntityRegistry(): array {
    static $reg = null;
    if ($reg !== null) return $reg;

    $reg = [
        'ticket' => [
            'module' => 'tickets',
            'table'  => 'tickets',
            'label'  => 'Ticket',
            'url'    => 'tickets/?ticket_id=%d',
            'title'  => 'subject',
            'alive'  => 'deleted_datetime IS NULL',
            'can'    => function (PDO $c, int $a, int $id) { return analystCanAccessTicket($c, $a, $id); },
            'filter' => function (PDO $c, int $a, string $alias) { return ticketTenantFilter($c, $a, $alias); },
        ],
        // A note on a ticket (discussion #69). The entity is the NOTE, not the
        // ticket, so a file lands on the note somebody wrote rather than in one
        // undifferentiated pile on the ticket — which is the whole request.
        //
        // 🔑 IT HAS NO RULES OF ITS OWN. A note is visible exactly when its
        // ticket is, so `can` and `filter` reach through to the parent ticket and
        // reuse the ticket's rule verbatim rather than restating it. That is the
        // block's "visible iff you can see a parent" rule applied one level down.
        //
        // ⚠️ INTERNAL NOTES ONLY, and that is enforced where notes are WRITTEN,
        // not here. A note can be shared with the requester, and the portal has
        // no documents path at all — api/documents/list.php requires an analyst
        // session — so a file on a shared note would show to analysts and
        // silently not to the person it was shared with. The note modal refuses
        // that combination; this registry would happily serve it to an analyst,
        // which is correct, because everything reaching this file IS an analyst.
        'ticket_note' => [
            'module' => 'tickets',
            'table'  => 'ticket_notes',
            'label'  => 'Ticket note',
            // The note has no page of its own — it is read on its ticket — so the
            // link back needs the TICKET's id, which sprintf cannot get from the
            // note's. Hence url_id_sql; see documentVisibleParents().
            'url'       => 'tickets/?ticket_id=%d',
            'url_id_sql' => '(SELECT t.id FROM tickets t WHERE t.id = ticket_notes.ticket_id)',
            'title'     => 'note_text',
            // Named by its ticket first: "a note" tells you nothing, and the note
            // text alone does not say which ticket you would be opening.
            'title_sql' => "(SELECT CONCAT(t.ticket_number, ' — ', LEFT(ticket_notes.note_text, 60))"
                         . " FROM tickets t WHERE t.id = ticket_notes.ticket_id)",
            // `alive` is applied to ticket_notes, which has no deleted flag of its
            // own — the note dies with its ticket. That check belongs in `filter`
            // and `can` below, where the parent ticket is actually reachable.
            'alive'  => null,
            'can'    => function (PDO $c, int $a, int $id) {
                $st = $c->prepare("SELECT ticket_id FROM ticket_notes WHERE id = ?");
                $st->execute([$id]);
                $ticketId = (int) $st->fetchColumn();
                if ($ticketId <= 0) return false;
                // ⚠️ analystCanAccessTicket() answers tenancy ONLY — it does not
                // look at deleted_datetime. The 'ticket' entry above gets that
                // from its `alive`; this one has to ask for itself, or a document
                // on a note would outlive the ticket it was written on.
                $st = $c->prepare("SELECT 1 FROM tickets WHERE id = ? AND deleted_datetime IS NULL");
                $st->execute([$ticketId]);
                if (!$st->fetchColumn()) return false;
                return analystCanAccessTicket($c, $a, $ticketId);
            },
            'filter' => function (PDO $c, int $a, string $alias) {
                list($tSql, $tParams) = ticketTenantFilter($c, $a, 'dnt');
                return [
                    " AND EXISTS (SELECT 1 FROM tickets dnt WHERE dnt.id = $alias.ticket_id"
                    . " AND dnt.deleted_datetime IS NULL" . $tSql . ")",
                    $tParams,
                ];
            },
        ],
        'asset' => [
            'module' => 'assets',
            'table'  => 'assets',
            'label'  => 'Asset',
            'url'    => 'asset-management/?asset_id=%d',
            'title'  => 'hostname',
            'alive'  => null,
            'can'    => function (PDO $c, int $a, int $id) { return analystCanAccessAsset($c, $a, $id); },
            'filter' => function (PDO $c, int $a, string $alias) { return activeTenantFilter($c, $a, $alias); },
        ],
        'contract' => [
            // No tenant_id column: a SUPPLIER contract is install-wide, so module
            // membership is the rule. A CUSTOMER contract belongs to its
            // customer's company (#153) and is gated by it.
            'module' => 'contracts',
            'table'  => 'contracts',
            'label'  => 'Contract',
            'url'    => 'contracts/view.php?id=%d',
            'title'  => 'title',
            'alive'  => null,
            'can'    => function (PDO $c, int $a, int $id) { require_once __DIR__ . '/contract_party.php'; return contractCanView($c, $a, $id); },
            'filter' => function (PDO $c, int $a, string $alias) { require_once __DIR__ . '/contract_party.php'; return contractVisibilitySql($c, $a, $alias); },
        ],
        'domain' => [
            // Registrar invoices, transfer authorisations, the letter from the
            // brand-protection agency (#154). Scoped data: the domain's company
            // decides who sees its documents, like an asset's.
            'module' => 'domains',
            'table'  => 'domains',
            'label'  => 'Domain',
            'url'    => 'domains/view.php?id=%d',
            'title'  => 'domain_name',
            'alive'  => null,
            'can'    => function (PDO $c, int $a, int $id) { return analystCanAccessDomain($c, $a, $id); },
            'filter' => function (PDO $c, int $a, string $alias) { return activeTenantFilter($c, $a, $alias); },
        ],
        'jml_case' => [
            // Starters & leavers (4.6.0): a signed returns form, an equipment
            // request. Scoped data, like a domain's: the case's company decides.
            'module' => 'starters',
            'table'  => 'jml_cases',
            'label'  => 'Starter or leaver',
            'url'    => 'starters/view.php?id=%d',
            'title'  => 'person_name',
            'alive'  => null,
            'can'    => function (PDO $c, int $a, int $id) { [$sql, $args] = activeTenantFilter($c, $a, 'x'); $st = $c->prepare('SELECT 1 FROM jml_cases x WHERE x.id = ?' . $sql); $st->execute(array_merge([$id], $args)); return (bool)$st->fetchColumn(); },
            'filter' => function (PDO $c, int $a, string $alias) { return activeTenantFilter($c, $a, $alias); },
        ],
        'knowledge_article' => [
            // ⚠️ Knowledge is the module that does NOT follow the others:
            // NULL means SHARED here, the opposite of tickets and assets. Its own
            // helpers know that; this file must not second-guess them.
            'module' => 'knowledge',
            'table'  => 'knowledge_articles',
            'label'  => 'Knowledge article',
            'url'    => 'knowledge/?article=%d',
            'title'  => 'title',
            'alive'  => null,
            // Both go through includes/knowledge/visibility.php, so a document
            // attached to an article inherits EVERY rule that article is subject
            // to — company, audience, and the access list when it lands — without
            // this file learning any of them. That is the same principle stated
            // at the top of this module: a document has no permissions of its
            // own, it is visible if you can see what it hangs off.
            //
            // lifecycle 'any' preserves exactly what was here: the previous pair
            // (analystCanAccessArticle + knowledgeTenantFilter) tested neither
            // is_published nor is_archived, so an attachment on a draft or on a
            // recycled article stayed reachable to an analyst.
            'can'    => function (PDO $c, int $a, int $id) {
                return knowledgeCanRead($c, KnowledgeViewer::forAnalyst($c, $a), $id, ['lifecycle' => 'any']);
            },
            'filter' => function (PDO $c, int $a, string $alias) {
                return knowledgeVisibilitySql($c, KnowledgeViewer::forAnalyst($c, $a), $alias, ['lifecycle' => 'any']);
            },
        ],
        'change' => [
            'module' => 'changes',
            'table'  => 'changes',
            'label'  => 'Change',
            'url'    => 'change-management/?change_id=%d',
            'title'  => 'title',
            'alive'  => null,
            'can'    => function (PDO $c, int $a, int $id) { return analystCanAccessChange($c, $a, $id); },
            'filter' => function (PDO $c, int $a, string $alias) { return activeTenantFilter($c, $a, $alias); },
        ],
        'problem' => [
            'module' => 'problems',
            'table'  => 'problems',
            'label'  => 'Problem',
            'url'    => 'problem-management/?problem_id=%d',
            'title'  => 'title',
            'alive'  => null,
            'can'    => function (PDO $c, int $a, int $id) { return analystCanAccessProblem($c, $a, $id); },
            'filter' => function (PDO $c, int $a, string $alias) { return activeTenantFilter($c, $a, $alias); },
        ],
        'task' => [
            // Tasks belong to a company (they gained tenant_id in GH #83), so a
            // task's documents follow the task's company exactly as a change's do.
            // This entry predates that and said tasks had no company column.
            'module' => 'tasks',
            'table'  => 'tasks',
            'label'  => 'Task',
            'url'    => 'tasks/?task_id=%d',
            'title'  => 'title',
            'alive'  => null,
            'can'    => function (PDO $c, int $a, int $id) { return analystCanAccessTask($c, $a, $id); },
            'filter' => function (PDO $c, int $a, string $alias) { return activeTenantFilter($c, $a, $alias); },
        ],
        'supplier' => [
            // Sits beside contracts and wants the same things — due diligence,
            // insurance certificates, signed terms.
            'module' => 'contracts',
            'table'  => 'suppliers',
            'label'  => 'Supplier',
            'url'    => 'contracts/suppliers.php?id=%d',
            'title'  => 'legal_name',
            'alive'  => null,
            'can'    => null,
            'filter' => null,
        ],
        'process' => [
            'module' => 'process-mapper',
            'table'  => 'processes',
            'label'  => 'Process',
            'url'    => 'process-mapper/?process_id=%d',
            'title'  => 'title',
            'alive'  => null,
            'can'    => null,
            'filter' => null,
        ],
        'calendar_event' => [
            'module' => 'calendar',
            'table'  => 'calendar_events',
            'label'  => 'Calendar event',
            'url'    => 'calendar/?event_id=%d',
            'title'  => 'title',
            'alive'  => null,
            'can'    => null,
            'filter' => null,
        ],
        'lease' => [
            // Assets -> Leases (3.5.0). Scoped data like assets: NULL = Default.
            'module' => 'assets',
            'table'  => 'leases',
            'label'  => 'Lease',
            'url'    => 'asset-management/leases/view.php?id=%d',
            'title'  => 'name',
            'alive'  => null,
            'can'    => function (PDO $c, int $a, int $id) { require_once __DIR__ . '/leases.php'; return leaseCanView($c, $a, $id); },
            'filter' => function (PDO $c, int $a, string $alias) { return activeTenantFilter($c, $a, $alias); },
        ],
        'software_licence' => [
            // ⚠️ THE ONLY ENTRY THAT NEEDS `title_sql`. A licence has no name of
            // its own — `software_licences` holds a type, a key and a renewal
            // date, and the product's name lives in software_inventory_apps via
            // app_id. Rather than teach the registry about joins for one entity,
            // the title is a scalar subquery. Licence certificates, purchase
            // orders and renewal quotes are exactly what people file.
            'module'    => 'software',
            'table'     => 'software_licences',
            'label'     => 'Software licence',
            'url'       => 'software/licences/?id=%d',
            'title'     => 'licence_type',      // the fallback if the join finds nothing
            'title_sql' => '(SELECT a.display_name FROM software_inventory_apps a WHERE a.id = software_licences.app_id)',
            'alive'     => null,
            'can'       => null,
            'filter'    => null,
        ],
        'status_service' => [
            'module' => 'service-status',
            'table'  => 'status_services',
            'label'  => 'Service',
            'url'    => 'service-status/?service_id=%d',
            'title'  => 'name',
            'alive'  => null,
            'can'    => null,
            'filter' => null,
        ],
        'project' => [
            // 3.3.0: plans, designs, sign-off letters, backout plans - and what a
            // gate checklist's document items point at. Scoped by the project's company.
            'module' => 'projects',
            'table'  => 'projects',
            'label'  => 'Project',
            'url'    => 'projects/view.php?id=%d',
            'title'  => 'name',
            'alive'  => null,
            'can'    => function (PDO $c, int $a, int $id) { return analystCanAccessProject($c, $a, $id); },
            'filter' => function (PDO $c, int $a, string $alias) { return activeTenantFilter($c, $a, $alias); },
        ],
        'cmdb_object' => [
            'module' => 'cmdb',
            'table'  => 'cmdb_objects',
            'label'  => 'CMDB object',
            'url'    => 'cmdb/object.php?id=%d',
            'title'  => 'name',
            'alive'  => null,
            'can'    => function (PDO $c, int $a, int $id) { return analystCanAccessCmdbObject($c, $a, $id); },
            'filter' => function (PDO $c, int $a, string $alias) { return activeTenantFilter($c, $a, $alias); },
        ],
    ];
    return $reg;
}

/**
 * Returns the keys of every record type documents can be attached to.
 *
 * @return string[] Entity types this build knows about.
 */
function documentEntityTypes(): array {
    return array_keys(documentEntityRegistry());
}

/**
 * Returns one record type's registry entry (label, URL, title column, access checks), or null when unknown.
 *
 * @param string $type  an entity type key from documentEntityRegistry(), e.g. 'contract' - may be untrusted
 */
function documentEntityDef(string $type): ?array {
    $reg = documentEntityRegistry();
    return $reg[$type] ?? null;
}

/**
 * Which entity types this analyst may read at all.
 *
 * Module membership is the read gate, exactly as the command palette has it:
 * "Reads aren't capability-gated in FreeITSM, so module membership is the right
 * gate here." A NULL allowed_modules means unrestricted.
 *
 * @param ?array $allowedModules  the analyst's allowed module keys ($_SESSION['allowed_modules']); null means all
 */
function documentAccessibleTypes(?array $allowedModules): array {
    $out = [];
    foreach (documentEntityRegistry() as $type => $def) {
        if ($allowedModules === null || in_array($def['module'], $allowedModules, true)) {
            $out[] = $type;
        }
    }
    return $out;
}

/**
 * Can this analyst see one specific parent record?
 *
 * Used for the exact, one-row question: may this document be downloaded, may it
 * be attached here. Checks the module gate first (cheap, and the coarse rule),
 * then the module's own record-level check where it has one.
 *
 * @param ?array $allowedModules  the analyst's allowed module keys ($_SESSION['allowed_modules']); null means all
 * @param string $type            the parent's entity type key from documentEntityRegistry(); unknown gives false
 * @param int    $parentId        the parent record's id in that type's table
 */
function documentCanViewParent(PDO $conn, int $analystId, ?array $allowedModules, string $type, int $parentId): bool {
    $def = documentEntityDef($type);
    if (!$def || $parentId <= 0) return false;
    if ($allowedModules !== null && !in_array($def['module'], $allowedModules, true)) return false;

    if (is_callable($def['can'])) {
        try {
            if (!$def['can']($conn, $analystId, $parentId)) return false;
        } catch (Throwable) {
            return false;   // an access check that errors is a NO, never a yes
        }
    }

    // The parent must still exist. A deleted contract cannot keep its documents
    // readable through a link nobody cleaned up.
    $sql = "SELECT 1 FROM `" . $def['table'] . "` WHERE id = ?";
    if (!empty($def['alive'])) $sql .= " AND " . $def['alive'];
    try {
        $st = $conn->prepare($sql . " LIMIT 1");
        $st->execute([$parentId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/**
 * May this analyst CHANGE what is attached to a record - attach, upload to it, or unlink?
 * documentCanViewParent(), plus the module's Read only level (#180, #181): somebody who
 * may only read Change Management, Forms or Tasks must not attach evidence to it either.
 *
 * @param ?array $allowedModules  the analyst's allowed module keys; null means all
 * @param string $type            the parent's entity type key from documentEntityRegistry()
 * @param int    $parentId        the parent record's id
 */
function documentCanWriteParent(PDO $conn, int $analystId, ?array $allowedModules, string $type, int $parentId): bool {
    if (!documentCanViewParent($conn, $analystId, $allowedModules, $type, $parentId)) return false;
    $def = documentEntityDef($type);
    if ($def && in_array($def['module'], MODULES_WITH_READ_ONLY, true)) {
        return analystCanWriteModule($conn, $analystId, $def['module']);
    }
    return true;
}

/**
 * SQL constraining a document query to what this analyst may see.
 *
 * Builds one EXISTS per accessible entity type, OR'd together — which is
 * literally the agreed rule: visible if you can see AT LEAST ONE parent. The
 * database evaluates it as a join and stops at the first match; nothing is
 * fetched and then discarded in PHP.
 *
 * ⚠️ Returns ' AND 0=1' when the analyst can reach no entity type at all. An
 * empty string would mean "no constraint", i.e. every document in the system.
 *
 * @param ?array $allowedModules  the analyst's allowed module keys ($_SESSION['allowed_modules']); null means all
 * @param string $docAlias        the alias the calling query gives the documents table; '' for no alias
 *
 * @return array [sqlFragment, params] — the [sql, params] shape every other
 *               filter in includes/tenancy.php uses.
 */
function documentVisibilityClause(PDO $conn, int $analystId, ?array $allowedModules, string $docAlias = 'd'): array {
    $types = documentAccessibleTypes($allowedModules);
    if (!$types) return [' AND 0=1', []];

    $doc    = $docAlias === '' ? 'id' : "$docAlias.id";
    $parts  = [];
    $params = [];
    $n      = 0;

    foreach ($types as $type) {
        $def = documentEntityDef($type);
        $dl  = 'dl' . $n;
        $pa  = 'pa' . $n;
        $n++;

        $sql = "EXISTS (SELECT 1 FROM document_links $dl"
             . " JOIN `" . $def['table'] . "` $pa ON $pa.id = $dl.parent_id"
             . " WHERE $dl.document_id = $doc AND $dl.parent_type = ?";
        $params[] = $type;

        if (!empty($def['alive'])) $sql .= " AND $pa." . $def['alive'];

        if (is_callable($def['filter'])) {
            try {
                list($fSql, $fParams) = $def['filter']($conn, $analystId, $pa);
                if ($fSql !== '') {
                    $sql   .= ' ' . $fSql;               // already begins " AND "
                    $params = array_merge($params, $fParams);
                }
            } catch (Throwable) {
                // A filter that cannot be built must not silently widen the
                // query. Drop this type instead.
                array_pop($params);
                continue;
            }
        }

        $parts[] = $sql . ')';
    }

    if (!$parts) return [' AND 0=1', []];
    return [' AND (' . implode(' OR ', $parts) . ')', $params];
}

/**
 * May this analyst see this document? The download/preview boundary.
 *
 * ⚠️ THIS IS THE CHECK THAT MATTERS. Search deciding what to LIST is a
 * convenience; this decides what somebody may HAVE. It must be called on every
 * download, preview and metadata read, and must never trust that the caller
 * found the id through a filtered list — /download.php?id=12345 is the attack,
 * and guessing an integer is not hard.
 *
 * @param ?array $allowedModules  the analyst's allowed module keys ($_SESSION['allowed_modules']); null means all
 * @param int    $documentId      the documents.id asked for - untrusted, often straight from the URL
 */
function documentCanView(PDO $conn, int $analystId, ?array $allowedModules, int $documentId): bool {
    if ($documentId <= 0) return false;

    try {
        $st = $conn->prepare(
            "SELECT parent_type, parent_id FROM document_links WHERE document_id = ?"
        );
        $st->execute([$documentId]);
        $links = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return false;
    }

    // An orphan — every parent gone, or never attached — is visible to nobody.
    // It is not "unrestricted"; it is waiting to be collected.
    foreach ($links as $l) {
        if (documentCanViewParent($conn, $analystId, $allowedModules, (string)$l['parent_type'], (int)$l['parent_id'])) {
            return true;
        }
    }
    return false;
}

/**
 * The display name of one parent record.
 *
 * 🔑 ONE implementation. This query was written out four separate times — in
 * documentVisibleParents(), list.php and twice in global_search.php — which is
 * how a registry gains a fifth caller that quietly does it differently.
 *
 * ⚠️ `title` is a COLUMN NAME on the parent's own table, which covers eleven of
 * the twelve entity types. `title_sql` is the escape hatch for the twelfth: a
 * software licence has no name of its own — it lives in software_inventory_apps
 * via app_id — so its entry supplies a scalar subquery instead. Both come from
 * the registry, which is code, so neither is ever attacker-supplied.
 *
 * @param string $type      the parent's entity type key from documentEntityRegistry(); unknown gives null
 * @param int    $parentId  the parent record's id in that type's table
 */
function documentParentName(PDO $conn, string $type, int $parentId): ?string
{
    $def = documentEntityDef($type);
    if (!$def || $parentId <= 0) return null;

    $expr = !empty($def['title_sql'])
        ? $def['title_sql']
        : '`' . $def['title'] . '`';

    try {
        $st = $conn->prepare("SELECT " . $expr . " FROM `" . $def['table'] . "` WHERE id = ?");
        $st->execute([$parentId]);
        $name = $st->fetchColumn();
        return ($name === false || $name === null || $name === '') ? null : (string) $name;
    } catch (Throwable $e) {
        // A label is never worth failing a list for.
        error_log('[documentParentName] ' . $type . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Everywhere a document is attached — that this caller may see.
 *
 * ⚠️ THE FILTER IS THE POINT, not a nicety. Listing a parent the caller cannot
 * see would disclose that a contract exists, and its title, to somebody with no
 * access to Contracts at all. So a hidden parent is omitted silently rather than
 * shown as "1 other record", which would leak the same fact more quietly.
 *
 * @param ?array $allowedModules  the analyst's allowed module keys ($_SESSION['allowed_modules']); null means all
 * @param int    $documentId      the documents.id whose attachments to list
 *
 * @return array<int,array{parent_type:string,parent_id:int,label:string,name:?string,url:?string}>
 */
function documentVisibleParents(PDO $conn, int $analystId, ?array $allowedModules, int $documentId): array
{
    $out = [];
    try {
        $st = $conn->prepare(
            "SELECT parent_type, parent_id, created_datetime
               FROM document_links WHERE document_id = ? ORDER BY created_datetime, id"
        );
        $st->execute([$documentId]);
        $links = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return $out;
    }

    foreach ($links as $l) {
        $type = (string) $l['parent_type'];
        $pid  = (int) $l['parent_id'];
        if (!documentCanViewParent($conn, $analystId, $allowedModules, $type, $pid)) continue;

        $def  = documentEntityDef($type);
        $name = documentParentName($conn, $type, $pid);

        // ⚠️ `url` is a sprintf pattern taking the PARENT's id, which is right
        // for everything that has a page of its own. A ticket note does not — it
        // is read on its ticket — so its entry supplies url_id_sql, a scalar
        // subquery giving the id to put in the pattern instead. Same escape hatch
        // as title_sql, and like it the SQL comes from the registry, which is
        // code, so it is never attacker-supplied.
        $urlId = $pid;
        if (!empty($def['url_id_sql'])) {
            try {
                $st2 = $conn->prepare("SELECT " . $def['url_id_sql'] . " FROM `" . $def['table'] . "` WHERE id = ?");
                $st2->execute([$pid]);
                $got = $st2->fetchColumn();
                // A parent that cannot resolve one gets no link rather than a
                // link to record 0.
                $urlId = ($got === false || $got === null) ? 0 : (int) $got;
            } catch (Throwable) {
                $urlId = 0;
            }
        }

        $out[] = [
            'parent_type' => $type,
            'parent_id'   => $pid,
            'label'       => $def['label'],
            'name'        => $name,
            'url'         => (!empty($def['url']) && $urlId > 0) ? sprintf($def['url'], $urlId) : null,
            'linked_at'   => $l['created_datetime'] ?? null,
        ];
    }
    return $out;
}

/**
 * Detach everything from a record that is being deleted.
 *
 * Call this from a module's delete path when you have one. It is the tidy answer:
 * the links go at the moment the parent does.
 *
 * @param string $parentType  the deleted record's entity type key from documentEntityRegistry(); unknown does nothing
 * @param int    $parentId    the deleted record's id
 *
 * @return int links removed
 */
function documentsDetachParent(PDO $conn, string $parentType, int $parentId): int
{
    if (!documentEntityDef($parentType) || $parentId <= 0) return 0;
    try {
        $st = $conn->prepare("DELETE FROM document_links WHERE parent_type = ? AND parent_id = ?");
        $st->execute([$parentType, $parentId]);
        return $st->rowCount();
    } catch (Throwable $e) {
        error_log('[documentsDetachParent] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Remove links whose parent no longer exists, then collect what that orphans.
 *
 * ⚠️ WHY A SWEEP AND NOT ONLY A HOOK. document_links.parent_id is POLYMORPHIC — it
 * points at whichever table parent_type names — so no foreign key can protect it.
 * Delete a contract and its links survive; nothing in the database can object.
 * The document is invisible from that moment (every permission check verifies the
 * parent still exists, which is why nothing leaks) but it is never collected: the
 * file sits on disk for ever and the row never orphans.
 *
 * A hook on every module's delete would be the tidy answer, and documentsDetachParent()
 * is there for the ones that have one. It cannot be the ONLY answer: twelve delete
 * paths, plus bulk deletes, plus anything that ever removes a row by SQL, is twelve
 * chances to forget — and forgetting is silent. So this is the net, and it is
 * correct on its own.
 *
 * Bounded so it can run opportunistically without becoming the cost of a page load.
 *
 * @param int $limit  most rows to delete per entity type, and most documents to collect, in one run (at least 1)
 *
 * @return array{links_removed:int,documents_orphaned:int,files_removed:int,errors:string[]}
 */
function documentsCollectOrphans(PDO $conn, int $limit = 200): array
{
    $out = ['links_removed' => 0, 'documents_orphaned' => 0, 'files_removed' => 0, 'errors' => []];

    try {
        // 1. Links whose parent is gone. Asked per entity type, because each one
        //    lives in a different table — the price of a polymorphic link.
        foreach (documentEntityRegistry() as $type => $def) {
            // ⚠️ SINGLE-TABLE DELETE, deliberately. The obvious form —
            // `DELETE dl FROM document_links dl WHERE … LIMIT n` — is a multi-table
            // delete, and MySQL does not accept LIMIT on one. It fails as a syntax
            // error, which the catch below then swallows, and the sweep reports
            // "0 removed" while doing nothing at all. Found by running it.
            $sql = "DELETE FROM document_links
                     WHERE parent_type = ?
                       AND NOT EXISTS (SELECT 1 FROM `" . $def['table'] . "` p WHERE p.id = document_links.parent_id)
                     LIMIT " . max(1, (int) $limit);
            try {
                $st = $conn->prepare($sql);
                $st->execute([$type]);
                $out['links_removed'] += $st->rowCount();
            } catch (Throwable $e) {
                // A module whose table is absent on this install must not stop the
                // others being tidied — but the caller has to be able to tell that
                // apart from "there was nothing to do", or a broken sweep looks
                // exactly like a clean one.
                $out['errors'][] = $type . ': ' . $e->getMessage();
                error_log('[documentsCollectOrphans] ' . $type . ': ' . $e->getMessage());
            }
        }

        // 2. Documents nothing points at any more.
        $st = $conn->prepare(
            "SELECT d.id, d.storage_key FROM documents d
              WHERE d.deleted_datetime IS NULL
                AND NOT EXISTS (SELECT 1 FROM document_links dl WHERE dl.document_id = d.id)
              LIMIT " . max(1, (int) $limit)
        );
        $st->execute();
        $orphans = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orphans as $o) {
            $id = (int) $o['id'];
            $conn->prepare("UPDATE documents SET deleted_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$id]);
            $out['documents_orphaned']++;

            // Out of the search index, or a deleted document stays findable by title.
            if (function_exists('searchUnindexDocument')) {
                searchUnindexDocument($conn, $id);
            }

            // The file last, and only if no live row still uses the same key.
            $key = (string) ($o['storage_key'] ?? '');
            if ($key !== '') {
                $chk = $conn->prepare("SELECT COUNT(*) FROM documents WHERE storage_key = ? AND deleted_datetime IS NULL");
                $chk->execute([$key]);
                if ((int) $chk->fetchColumn() === 0) {
                    $path = documentStoragePath($key);
                    if (is_file($path) && @unlink($path)) $out['files_removed']++;
                }
            }
        }
    } catch (Throwable $e) {
        $out['errors'][] = $e->getMessage();
        error_log('[documentsCollectOrphans] ' . $e->getMessage());
    }

    return $out;
}

/**
 * Absolute path for a stored document.
 *
 * 🔑 The database stores an opaque KEY, never a path. A path baked into a row is
 * a data migration the day the files move to another disk, a NAS or object
 * storage; a key is a change to this one function.
 *
 * @param string $storageKey  documents.storage_key, as made by documentStorageKey(); any "../" is stripped
 */
function documentStoragePath(string $storageKey): string {
    $key = str_replace('\\', '/', $storageKey);
    // Defensive: a key is ours, but never let one climb out of the directory.
    $key = preg_replace('#\.\.+/#', '', $key);
    $key = ltrim($key, '/');
    return dirname(__DIR__) . '/uploads/' . DOCUMENT_STORAGE_DIR . '/' . $key;
}

/**
 * The key for a newly stored file.
 *
 * Flat, and that is fine: at the few thousand documents this is built for, a
 * single directory is not a problem worth solving. Sharding into subdirectories,
 * or moving to object storage entirely, changes THIS function and nothing else —
 * which is the entire reason the column holds a key rather than a path.
 *
 * @param string $storedName  the random file name uploadStoreFile() gave the upload (its 'stored_name')
 */
function documentStorageKey(string $storedName): string {
    return basename(str_replace('\\', '/', $storedName));
}

// ─── The Help Centre (GH #177) ───────────────────────────────────────────────
//
// Documents on a KNOWLEDGE ARTICLE can be downloaded from the self-service
// portal's Help Centre, by anyone who may read that article there - unless the
// link says not to. Every other kind of record is unaffected: its documents
// never reach the portal (ticket-note files have their own route,
// api/self-service/get_document.php). The article side lives in
// includes/knowledge/help_centre_documents.php.

/**
 * Has Database Verification added document_links.show_in_help_centre yet?
 *
 * Every writer of document_links asks this first, because naming a column that
 * is not there yet would break EVERY document upload on EVERY module between
 * pulling the code and running verification - not just the new feature.
 */
function documentHelpCentreReady(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool) $conn->query("SHOW COLUMNS FROM `document_links` LIKE 'show_in_help_centre'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $ready = false;
    }
    return $ready;
}

/**
 * The show_in_help_centre value a NEW link to this kind of record starts with:
 * 1 for a knowledge article (attaching a document to a customer-facing article
 * publishes it, which is what people expect), 0 for everything else.
 *
 * TRAP: new links only. Never backfill existing rows to 1 - they were attached
 *   before anybody was told that publishes them (see the column's TRAP in
 *   includes/db_verify_schema.php).
 *
 * @param string $parentType  the document_links.parent_type being written, e.g. 'knowledge_article'
 */
function documentLinkHelpCentreDefault(string $parentType): int {
    return $parentType === 'knowledge_article' ? 1 : 0;
}

/**
 * Insert one document_links row, setting show_in_help_centre when the column
 * exists. The one place a link is written, so the default above cannot be
 * forgotten by a new caller.
 *
 * @param int $documentId  documents.id
 * @param string $parentType  the record type, a key of documentEntityRegistry()
 * @param int $parentId  that record's id
 * @param ?int $linkedById  the analyst making the link; null for the system
 * @param bool $ignoreDuplicate  true = INSERT IGNORE (attaching twice is a no-op)
 */
function documentInsertLink(PDO $conn, int $documentId, string $parentType, int $parentId, ?int $linkedById, bool $ignoreDuplicate = false): void {
    $verb = $ignoreDuplicate ? 'INSERT IGNORE' : 'INSERT';
    if (documentHelpCentreReady($conn)) {
        $conn->prepare("$verb INTO document_links (document_id, parent_type, parent_id, linked_by_id, show_in_help_centre)
                        VALUES (?,?,?,?,?)")
             ->execute([$documentId, $parentType, $parentId, $linkedById, documentLinkHelpCentreDefault($parentType)]);
    } else {
        $conn->prepare("$verb INTO document_links (document_id, parent_type, parent_id, linked_by_id)
                        VALUES (?,?,?,?)")
             ->execute([$documentId, $parentType, $parentId, $linkedById]);
    }
}
