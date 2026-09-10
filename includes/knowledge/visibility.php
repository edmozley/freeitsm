<?php
/**
 * Who may READ a Knowledge article — the single place that answers it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS FILE EXISTS
 * ─────────────────────────────────────────────────────────────────────────────
 * Knowledge had no choke point. Roughly 48 raw `SELECT ... FROM
 * knowledge_articles` statements across ~30 files each assembled their own
 * visibility clause out of three separate ingredients:
 *
 *   1. lifecycle   is_published / is_archived   (KB_VISIBLE_SQL in kb_ai.php)
 *   2. company     knowledgeTenantFilter*()     (tenancy.php)
 *   3. audience    Audience::sqlFilter()        (audience.php)
 *
 * Nothing forced a caller to use all three, or any. That was survivable while
 * the axes were coarse; it stops being survivable the moment an article can be
 * restricted to named people, because then a forgotten clause is a disclosure
 * rather than an inconsistency.
 *
 * So: every read path goes through this file, and the three ingredients (plus
 * the access list, when it lands) are combined here exactly once.
 *
 * ⚠️ THIS FILE MUST NOT CHANGE BEHAVIOUR ON ARRIVAL. It composes the existing
 * helpers rather than reimplementing them, so migrating a caller onto it is a
 * refactor with an identical result set. That property is what makes the
 * migration verifiable — tests/knowledge-visibility/ must stay green untouched.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE RULE
 * ─────────────────────────────────────────────────────────────────────────────
 *   Every axis NARROWS. Nothing widens.
 *
 * An article is readable only if ALL of them agree. The axes are ANDed, never
 * weighed against each other, so there is no precedence question to answer —
 * and an access-list grant can never reach past the audience ladder. See
 * https://github.com/edmozley/freeitsm/wiki/Knowledge-Folders-and-Permissions
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE TWO ENTRY POINTS
 * ─────────────────────────────────────────────────────────────────────────────
 *   knowledgeVisibilitySql()  — a clause to AND into a list query
 *   knowledgeCanRead()        — the boundary check for a single id
 *
 * The second is implemented in terms of the first ON PURPOSE. A list and a
 * fetch that build their filters separately drift, and the drift always
 * resolves the same way: the list hides an article the fetch will happily
 * serve to anyone holding its id. One clause, two uses, no drift.
 */

require_once __DIR__ . '/audience.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../capabilities.php';   // Cap::KNOWLEDGE_MANAGE — the administrator floor
require_once __DIR__ . '/../rbac.php';           // analystHasCapability()
require_once __DIR__ . '/audit.php';             // the ONE writer of knowledge_audit

/**
 * WHO is asking. Not the same thing as ActorContext (service_context.php),
 * which models an *analyst* performing a write — a Knowledge reader may be a
 * portal user or an anonymous web-chat visitor, neither of whom has an
 * analyst id at all.
 *
 * The audience ladder already names exactly these three readers, so the viewer
 * carries its rung rather than inventing a parallel vocabulary:
 *
 *   Audience::INTERNAL  analyst (or an API key, which acts as one)
 *   Audience::CUSTOMER  signed-in self-service user
 *   Audience::PUBLIC    anonymous web-chat visitor
 *
 * Build one with a named constructor. There is deliberately no public
 * `new KnowledgeViewer(...)` that lets a caller assert its own rung from
 * request data — that is how a "level" parameter becomes a privilege
 * escalation.
 */
final class KnowledgeViewer
{
    /** One of the Audience:: constants. */
    private string $level;

    /** Analyst id, or null when the reader is not an analyst. */
    private ?int $analystId;

    /** Portal user id, or null. Reserved for access-list principals. */
    private ?int $userId;

    /**
     * The companies whose view this is, already resolved. THREE states, and they
     * are not interchangeable:
     *
     *   null   no company filtering at all — a single-company install, or an
     *          all-access API key
     *   []     SHARED ARTICLES ONLY — a reader with no company context
     *   [ids]  those companies, PLUS everything shared
     *
     * A SET rather than one id because the two kinds of reader genuinely differ:
     * a signed-in analyst has ONE active company (the header switcher), while an
     * API key carries a LIST. Modelling it as a single id forced the key through
     * the analyst's rule, which is the wrong question and quietly the wrong
     * answer. This reproduces both knowledgeTenantFilterForCompany() and
     * apiKeyKnowledgeFilter() exactly.
     *
     * ⚠️ In every state, `tenant_id IS NULL` stays visible — in Knowledge that
     * means SHARED WITH EVERY COMPANY, the opposite of tickets and assets.
     */
    private ?array $companyScope;

    /**
     * True only for trusted internal machinery that must see everything —
     * the search indexer, the embedding backfill. Carries the reason so every
     * bypass is greppable and has to justify itself at the call site.
     */
    private bool $unrestricted;
    private string $unrestrictedReason;

    private function __construct(
        string $level,
        ?int $analystId,
        ?int $userId,
        ?array $companyScope,
        bool $unrestricted = false,
        string $unrestrictedReason = ''
    ) {
        $this->level              = Audience::normalise($level);
        $this->analystId          = $analystId;
        $this->userId             = $userId;
        $this->companyScope       = $companyScope;
        $this->unrestricted       = $unrestricted;
        $this->unrestrictedReason = $unrestrictedReason;
    }

    /**
     * One company id in the set form, honouring the two conditions under which
     * Knowledge does no company filtering at all: multi-tenancy dormant, or the
     * column absent on an install that has not run Database Verify.
     *
     * NULL $tenantId means "no company context" => shared only, which is what
     * knowledgeTenantFilterForCompany() has always done with a null.
     */
    private static function scopeForOneCompany(PDO $conn, ?int $tenantId): ?array
    {
        if (!function_exists('isMultiTenant') || !isMultiTenant($conn)) return null;
        if (!tenancyColumnExists($conn, 'knowledge_articles', 'tenant_id')) return null;
        return $tenantId === null ? [] : [$tenantId];
    }

    /**
     * A signed-in analyst, reading from whichever company they have switched to.
     *
     * The active company is resolved HERE rather than being passed in, so every
     * analyst read follows the header switcher automatically and a caller
     * cannot accidentally supply a different one.
     */
    public static function forAnalyst(PDO $conn, int $analystId): self
    {
        // The consolidated view (#1554). An analyst looking at every company's
        // tickets at once should be able to search every company's knowledge —
        // otherwise Ask AI answers a question about School B's ticket out of
        // School A's articles, and does it silently.
        //
        // ⚠️ Still an EXPLICIT id list, never "no filter". This reuses the shape
        // forApiKey() has always had (companyScope is already an array), so it is
        // not a new privilege — it is the same set the switcher would have given
        // them one company at a time.
        //
        // 🔑 Articles with tenant_id NULL are SHARED WITH EVERY COMPANY in
        // Knowledge (unlike tickets, where NULL means Default's) — see the big
        // warning in tenancy.php. So an install that files all its knowledge
        // globally is unaffected either way, which is the common case.
        if ($analystId > 0 && function_exists('isActiveTenantAll') && isActiveTenantAll($conn)) {
            $scope = self::scopeForAllAccessible($conn, $analystId);
            return new self(Audience::INTERNAL, $analystId, null, $scope);
        }

        $tenantId = ($analystId > 0) ? getActiveTenantId($conn, $analystId) : null;
        return new self(
            Audience::INTERNAL,
            $analystId > 0 ? $analystId : null,
            null,
            self::scopeForOneCompany($conn, $tenantId)
        );
    }

    /**
     * Every company this analyst may see, in the same set form as
     * scopeForOneCompany(). Honours the same two "no filtering at all" cases.
     */
    private static function scopeForAllAccessible(PDO $conn, int $analystId): ?array
    {
        if (!function_exists('isMultiTenant') || !isMultiTenant($conn)) return null;
        if (!tenancyColumnExists($conn, 'knowledge_articles', 'tenant_id')) return null;
        $ids = array_values(array_unique(array_map('intval', getAccessibleTenantIds($conn, $analystId))));
        // Empty means "shared articles only" here — the same thing an empty scope
        // has always meant in this file. It is NOT "everything".
        return $ids;
    }

    /**
     * An authenticated REST API key.
     *
     * `api_keys.analyst_id` is NOT NULL and api/v1/lib/auth.php states that
     * "every key acts as an analyst" — so a key inherits its analyst's reach and
     * needs no principal model of its own. The API comes along for free
     * PROVIDED it reads through this file like everything else.
     */
    public static function forApiKey(PDO $conn, array $apiKey): self
    {
        $analystId = (int)($apiKey["analyst_id"] ?? 0);

        // ⚠️ NOT forAnalyst(). A key does NOT read from its analyst's active
        // company — it carries its own company_scope, which may name several at
        // once and has no header switcher behind it. Routing a key through the
        // analyst rule answers the wrong question, and answers it quietly.
        // Mirrors apiKeyKnowledgeFilter() in api/v1/lib/auth.php: null scope or
        // a dormant multi-tenancy means no filtering; an empty list means shared
        // articles only.
        $scope = $apiKey["company_scope"] ?? null;
        if (!function_exists("isMultiTenant") || !isMultiTenant($conn) || $scope === null) {
            $scope = null;
        } else {
            $scope = array_map("intval", (array)$scope);
        }
        return new self(Audience::INTERNAL, $analystId > 0 ? $analystId : null, null, $scope);
    }

    /** A signed-in self-service portal user. */
    public static function forPortalUser(PDO $conn, ?int $userId, ?int $tenantId): self
    {
        return new self(Audience::CUSTOMER, null, $userId > 0 ? $userId : null, self::scopeForOneCompany($conn, $tenantId));
    }

    /**
     * An anonymous web-chat visitor. They typed a name and an email and neither
     * was verified, which is the whole reason PUBLIC is the bottom rung.
     */
    public static function forWebChat(PDO $conn, ?int $tenantId): self
    {
        return new self(Audience::PUBLIC, null, null, self::scopeForOneCompany($conn, $tenantId));
    }

    /**
     * ⚠️ COMPATIBILITY ONLY — do not use in new code.
     *
     * kbRetrieveArticles() has long accepted a bare Audience:: string, and its
     * harness passes every rung through it. Rather than rewrite the code and the
     * test that guards it in the same breath — which would leave the test unable
     * to catch the rewrite — that form still works, and lands here.
     *
     * This is the ONE place a caller may state its own rung. It is named to be
     * obvious and greppable for exactly that reason: everything else must use a
     * constructor that DERIVES the rung from who is actually asking, because a
     * level accepted from request data is a privilege escalation waiting to be
     * found. Deleting this method is the last step of the migration.
     */
    public static function fromLegacyAudienceString(PDO $conn, ?int $tenantId, string $level): self
    {
        return new self(Audience::normalise($level), null, null, self::scopeForOneCompany($conn, $tenantId));
    }

    /**
     * No filtering whatsoever — for internal machinery that must process every
     * article regardless of who could read it (the search indexer building rows
     * it will later filter at query time; the embedding backfill).
     *
     * $reason is required and is not decoration: it makes every bypass in the
     * codebase findable with one grep, and forces the author to name the
     * justification at the point of use rather than in a commit message.
     */
    public static function forSystem(string $reason): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('KnowledgeViewer::forSystem() requires a reason.');
        }
        return new self(Audience::INTERNAL, null, null, null, true, $reason);
    }

    public function level(): string             { return $this->level; }
    public function analystId(): ?int           { return $this->analystId; }
    public function userId(): ?int              { return $this->userId; }
    public function companyScope(): ?array      { return $this->companyScope; }
    public function isUnrestricted(): bool      { return $this->unrestricted; }
    public function unrestrictedReason(): string { return $this->unrestrictedReason; }
    public function isAnalyst(): bool           { return $this->level === Audience::INTERNAL && !$this->unrestricted; }
}

/**
 * The SQL clause restricting a knowledge_articles query to what $viewer may read.
 *
 * Returns [' AND ...', $params] ready to concatenate into a WHERE, or ['', []]
 * when nothing needs restricting. The leading ' AND ' is included, so callers
 * append it to an existing predicate exactly as they do today with
 * knowledgeTenantFilter() and Audience::sqlFilter().
 *
 * $alias is the table alias ('' for an unaliased query).
 *
 * $opts:
 *   'lifecycle' => 'live' (default) | 'published' | 'unarchived' | 'archived' | 'any'
 *
 *       live        published AND not archived — every customer-facing reader
 *       published   published, ANY archive state (the AI chat's include-archived)
 *       unarchived  not archived, ANY publish state (the analyst list, with drafts)
 *       archived    archived only (the recycle bin)
 *       any         no lifecycle filter at all (the indexer, and total counts)
 *
 *       Lifecycle is NOT a permission and is therefore separable. Three real
 *       callers prove it has to be: the recycle bin wants archived rows, the
 *       search indexer wants everything, and the analyst article list
 *       deliberately shows UNPUBLISHED drafts — the Knowledge assistant saves
 *       AI-written work as a draft, and a draft nobody can find is a draft
 *       nobody will ever publish.
 *
 *       Had lifecycle been welded into the permission clause, each of those
 *       would have had to opt OUT of the security filter to get the rows it
 *       needs. That is precisely the shape of mistake this file exists to
 *       prevent, so they are separate knobs.
 */
function knowledgeVisibilitySql(PDO $conn, KnowledgeViewer $viewer, string $alias = 'a', array $opts = []): array
{
    $sql = '';
    $params = [];

    $lifecycle = $opts['lifecycle'] ?? 'live';
    $q = static function (string $col) use ($alias): string {
        return $alias === '' ? $col : $alias . '.' . $col;
    };

    // ── 1. Lifecycle ────────────────────────────────────────────────────────
    // Archiving does NOT unpublish, so 'live' has to check both flags — an
    // archived article can still carry is_published = 1.
    $notArchived = ' AND (' . $q('is_archived') . ' = 0 OR ' . $q('is_archived') . ' IS NULL)';
    $published   = ' AND ' . $q('is_published') . ' = 1';
    if ($lifecycle === 'live') {
        $sql .= $published . $notArchived;
    } elseif ($lifecycle === 'published') {
        $sql .= $published;
    } elseif ($lifecycle === 'unarchived') {
        $sql .= $notArchived;
    } elseif ($lifecycle === 'archived') {
        $sql .= ' AND ' . $q('is_archived') . ' = 1';
    }

    // Machinery sees every article, but still respects the lifecycle it asked
    // for — "unrestricted" is about permission, not about wanting the bin.
    if ($viewer->isUnrestricted()) {
        return [$sql, $params];
    }

    // ── 2. Company ──────────────────────────────────────────────────────────
    // ⚠️ `tenant_id IS NULL` means SHARED WITH EVERY COMPANY in Knowledge — the
    // opposite of tickets and assets, where it means the Default company's. So
    // NULL survives EVERY branch below. Run Knowledge through a ticket-shaped
    // filter and every shared article silently vanishes; see the essay on
    // knowledgeTenantFilterForCompany() in tenancy.php. This has bitten twice.
    $scope = $viewer->companyScope();
    if ($scope !== null) {
        $col = $q('tenant_id');
        if (!$scope) {
            // No company context at all — shared articles only.
            $sql .= ' AND ' . $col . ' IS NULL';
        } else {
            $marks = implode(',', array_fill(0, count($scope), '?'));
            $sql .= ' AND (' . $col . ' IN (' . $marks . ') OR ' . $col . ' IS NULL)';
            $params = array_merge($params, $scope);
        }
    }

    // ── 3. Audience ─────────────────────────────────────────────────────────
    // Returns ['', []] for an analyst, who reads every rung — so an analyst
    // query stays byte-identical to what it was before this file existed.
    //
    // Skipped entirely on an install that predates the column, where every
    // article is shared and there is no rung to compare against. This guard
    // came from portal_reader.php and is hoisted here on purpose: it is a
    // property of the SCHEMA, not of one caller, and leaving each reader to
    // remember it is how one of them ends up emitting SQL against a column
    // that does not exist yet.
    if (tenancyColumnExists($conn, 'knowledge_articles', 'audience')) {
        [$audSql, $audParams] = Audience::sqlFilter($viewer->level(), $alias);
        $sql .= $audSql;
        $params = array_merge($params, $audParams);
    }

    // ── 4. Access list ──────────────────────────────────────────────────────
    [$aclSql, $aclParams] = knowledgeAclSql($conn, $viewer, $alias, $opts);
    $sql .= $aclSql;
    $params = array_merge($params, $aclParams);

    return [$sql, $params];
}

/**
 * May $viewer read article $articleId?
 *
 * The boundary check. Built on knowledgeVisibilitySql() so it can never
 * disagree with the list that produced the id — see the header note.
 *
 * A missing row returns FALSE, matching analystCanAccessArticle()'s reasoning:
 * "let the caller's own 404 handle it" is only safe if every caller HAS a 404,
 * and that is not a property this function can check. Denying is correct and is
 * indistinguishable to a legitimate reader, who gets not-found either way.
 */
function knowledgeCanRead(PDO $conn, KnowledgeViewer $viewer, $articleId, array $opts = []): bool
{
    $id = (int)$articleId;
    if ($id <= 0) return false;

    $ask = static function (array $o) use ($conn, $viewer, $id): bool {
        [$sql, $params] = knowledgeVisibilitySql($conn, $viewer, 'a', $o);
        $stmt = $conn->prepare("SELECT 1 FROM knowledge_articles a WHERE a.id = ?" . $sql . " LIMIT 1");
        $stmt->execute(array_merge([$id], $params));
        return (bool)$stmt->fetchColumn();
    };

    try {
        // Ask WITHOUT the administrator floor first. Almost everybody is allowed
        // on the merits, so this is the only query that usually runs.
        if ($ask($opts + ['no_admin_floor' => true])) return true;

        // Refused on the merits. If this analyst holds the floor, they get in —
        // that is what makes a Restricted folder whose only grantee has left
        // recoverable rather than lost — but the pass is RECORDED. A permission
        // that always succeeds and leaves no trace is indistinguishable from not
        // having a permission system at all.
        //
        // Only here, never in the list clause: opening something you were not
        // granted is an override, browsing a list is not, and auditing every
        // list would bury the rows that matter under the ones that do not.
        if (knowledgeViewerHasAdminFloor($conn, $viewer) && $ask($opts)) {
            knowledgeAuditAdminOverride($conn, $viewer, $id);
            return true;
        }
        return false;
    } catch (PDOException $e) {
        error_log('knowledge visibility: canRead(' . $id . ') failed — ' . $e->getMessage());
        return false;   // fail closed
    }
}

/**
 * Does this viewer hold the administrator floor?
 *
 * Cap::KNOWLEDGE_MANAGE rather than a new knowledge.admin: it already exists as
 * the module's umbrella capability and its holders already administer Knowledge,
 * so inventing one would mean an RBAC seed and a settings row for no extra
 * safety. Split it out later if it ever earns its own.
 *
 * Only an ANALYST can hold it. A portal user or a web-chat visitor never does,
 * whatever else is true of them.
 */
function knowledgeViewerHasAdminFloor(PDO $conn, KnowledgeViewer $viewer): bool
{
    if (!$viewer->isAnalyst() || $viewer->analystId() === null) return false;
    if (!function_exists('analystHasCapability')) return false;
    try {
        return analystHasCapability($conn, $viewer->analystId(), Cap::KNOWLEDGE_MANAGE);
    } catch (Throwable $e) {
        return false;   // cannot confirm the floor => no floor
    }
}

/** Record that the floor let somebody past an access list they were not on. */
function knowledgeAuditAdminOverride(PDO $conn, KnowledgeViewer $viewer, int $articleId): void
{
    knowledgeAuditLog($conn, 'article', $articleId, 'admin_override',
                      $viewer->analystId(), ['capability' => Cap::KNOWLEDGE_MANAGE]);
}

/**
 * The access-list clause. Returns ['', []] until the folder/ACL tables exist,
 * which is why introducing this file changes nothing.
 *
 * ── The plan, recorded so it is not re-derived later ──────────────────────────
 * README states MySQL 8.0+, and api/tickets/get_emails.php already depends on a
 * window function, so `WITH RECURSIVE` IS available. The effective permission
 * is therefore computed by walking the folder chain in SQL at read time, NOT by
 * maintaining a denormalised effective-ACL table — a cache of a permission is a
 * second source of truth about who can see what, and it is wrong every time the
 * rebuild is missed.
 *
 * The walk climbs from the article's folder to the first ancestor that does not
 * inherit (or the root), and the node it stops on supplies the polarity:
 *
 *   Open       readable unless a DENY matches one of the viewer's principals
 *   Restricted readable only if a GRANT matches one
 *
 * Because polarity lives on the object and never on the row, an allow and a
 * deny cannot coexist — there is no precedence rule to encode here.
 *
 * Two behaviours still to implement alongside the tables:
 *   • knowledge_folder_permission_model — 'containers' (default; EVERY ancestor
 *     must be readable) vs 'filing' (the article's own list is authoritative).
 *     It is a branch INSIDE this function, which is why it must ship with the
 *     first version rather than being layered on later.
 *   • the knowledge.admin floor — always passes, and writes an audit row.
 */
function knowledgeAclSql(PDO $conn, KnowledgeViewer $viewer, string $alias = "a", array $opts = []): array
{
    if ($viewer->isUnrestricted()) return ['', []];
    if (!knowledgeAclTablesExist($conn)) return ['', []];

    // ── The fast path, and it is the one almost every install takes ─────────
    // No access rows anywhere means nothing is restricted, so there is nothing
    // to narrow and the SQL stays byte-identical to what it was before folders
    // existed. Checked FIRST because the work below (loading the folder tree and
    // resolving the viewer's principals) is pure waste otherwise.
    if (!knowledgeAclHasAnyRows($conn)) return ['', []];

    // ── The administrator floor ─────────────────────────────────────────────
    // Somebody will create a Restricted folder, grant one person, and that
    // person will leave. Without a floor the folder is unrecoverable, so this
    // capability always passes.
    //
    // Cap::KNOWLEDGE_MANAGE rather than a new knowledge.admin: it already exists
    // as the module's umbrella capability, its holders already administer
    // Knowledge, and inventing a capability means an RBAC seed plus a settings
    // screen for no extra safety. Easy to split later if it earns its own.
    //
    // ⚠️ A permission that always passes must leave a trace, or it is
    // indistinguishable from having no permission system. The audit row is
    // written by knowledgeCanRead() — the moment somebody actually OPENS
    // something they were not granted — rather than here, because a list query
    // is browsing, not an override, and auditing every list would bury the rows
    // that matter under the ones that do not.
    if (empty($opts['no_admin_floor']) && knowledgeViewerHasAdminFloor($conn, $viewer)) {
        return ['', []];
    }

    $q = static function (string $col) use ($alias): string {
        return $alias === '' ? $col : $alias . '.' . $col;
    };

    $principals = knowledgeViewerPrincipals($conn, $viewer);
    $mode       = knowledgeFolderPermissionModel($conn);
    $acl        = knowledgeAclIndex($conn);
    $folders    = knowledgeFolderIndex($conn);

    // Which folders this viewer may NOT reach. Computed in PHP rather than as a
    // recursive CTE in the clause: this fragment is appended to somebody else's
    // WHERE, and a WITH cannot be introduced there — while a correlated
    // recursive subquery would re-walk the tree once per row. Folders are an
    // organisational structure numbering tens to hundreds, so one pass in PHP is
    // both cheaper and legible. Same instinct as global_search's documented
    // "two queries, merged in PHP — NOT a UNION".
    $badFolders = [];
    foreach ($folders as $fid => $_f) {
        if (!knowledgeFolderReadable($fid, $folders, $acl, $principals, $mode)) {
            $badFolders[] = $fid;
        }
    }

    // Articles carrying their OWN rules — the only ones whose folder cannot
    // answer for them. By design these are meant to be rare (that is what the
    // exception badge and report exist to keep true), so this stays small.
    $badArticles = [];
    $st = $conn->query("SELECT id, is_restricted FROM knowledge_articles WHERE inherit_permissions = 0");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!knowledgeNodePermits('article', (int)$row['id'], (int)$row['is_restricted'], $acl, $principals)) {
            $badArticles[] = (int)$row['id'];
        }
    }

    $sql = '';
    $params = [];
    $inList = static function (array $ids): string {
        return implode(',', array_map('intval', $ids));
    };

    if ($mode === 'filing') {
        // Folders are filing: an article's own rules are authoritative when it
        // has them, and the folder chain only speaks for articles that inherit.
        $parts = [];
        $parts[] = $badArticles
            ? '(' . $q('inherit_permissions') . ' = 0 AND ' . $q('id') . ' NOT IN (' . $inList($badArticles) . '))'
            : '(' . $q('inherit_permissions') . ' = 0)';
        $parts[] = $badFolders
            ? '(' . $q('inherit_permissions') . ' = 1 AND (' . $q('folder_id') . ' IS NULL OR ' . $q('folder_id') . ' NOT IN (' . $inList($badFolders) . ')))'
            : '(' . $q('inherit_permissions') . ' = 1)';
        $sql = ' AND (' . implode(' OR ', $parts) . ')';
    } else {
        // Folders are containers (the default): a locked cabinet is locked, so
        // BOTH tests apply — the article's own rules AND every ancestor.
        if ($badArticles) {
            $sql .= ' AND ' . $q('id') . ' NOT IN (' . $inList($badArticles) . ')';
        }
        if ($badFolders) {
            $sql .= ' AND (' . $q('folder_id') . ' IS NULL OR ' . $q('folder_id') . ' NOT IN (' . $inList($badFolders) . '))';
        }
    }

    return [$sql, $params];
}

/**
 * Does this node let this viewer through?
 *
 *   Open (is_restricted = 0)       yes, unless a DENY names one of their principals
 *   Restricted (is_restricted = 1) only if a GRANT names one
 *
 * The polarity comes from the OBJECT, so an allow and a deny can never both be
 * present and there is no precedence rule to apply. Note the two directions:
 * on an Open node ANY matching row excludes them (deny is absolute), on a
 * Restricted node ANY matching row admits them (grants are additive). Different
 * directions, each unambiguous, both failing safe.
 *
 * ⚠️ A Restricted node with NO rows admits NOBODY. That is correct rather than a
 * bug — it is "restricted to nobody" — and it is precisely the state the
 * administrator floor exists to recover from.
 */
function knowledgeNodePermits(string $type, int $id, int $isRestricted, array $acl, array $principals): bool
{
    $rows = $acl[$type][$id] ?? [];
    $matches = false;
    foreach ($principals as $p) {
        if (isset($rows[$p])) { $matches = true; break; }
    }
    return $isRestricted ? $matches : !$matches;
}

/**
 * May this viewer reach $folderId?
 *
 * containers — EVERY node from the folder up to the root must permit. A node
 *   that inherits has no rules of its own and therefore permits trivially, which
 *   is what makes inheritance fall out of the walk rather than needing its own
 *   pass: the nearest non-inheriting ancestor is the only one that can object.
 * filing — only the NEAREST non-inheriting node speaks.
 *
 * A cycle (which the parent FK does not prevent) terminates the walk rather than
 * hanging: a folder that is its own ancestor is corrupt, and the safe reading of
 * corrupt is "not readable".
 */
function knowledgeFolderReadable(int $folderId, array $folders, array $acl, array $principals, string $mode): bool
{
    $seen = [];
    $cursor = $folderId;
    while ($cursor !== null && isset($folders[$cursor])) {
        if (isset($seen[$cursor])) return false;   // cycle — fail closed
        $seen[$cursor] = true;

        $f = $folders[$cursor];
        if (!$f['inherit']) {
            if (!knowledgeNodePermits('folder', $cursor, $f['restricted'], $acl, $principals)) {
                return false;
            }
            // In filing mode the nearest non-inheriting node is the ONLY one
            // that speaks, so stop the moment it has spoken.
            if ($mode === 'filing') return true;
        }
        $cursor = $f['parent'];
    }
    return true;
}

/**
 * Everything the viewer counts as, as "type:id" strings for cheap lookup.
 *
 * An analyst is themselves plus their teams; a portal user is themselves. Both
 * pick up any user group they belong to whose membership has not expired —
 * ⚠️ the expiry is applied HERE, at read time, so the three engineers who were
 * given a folder for a week lose it by the clock rather than by somebody
 * remembering to take it away.
 */
function knowledgeViewerPrincipals(PDO $conn, KnowledgeViewer $viewer): array
{
    $out = [];
    $memberType = null;
    $memberId   = null;

    if ($viewer->analystId() !== null) {
        $out[] = 'analyst:' . $viewer->analystId();
        $memberType = 'analyst';
        $memberId   = $viewer->analystId();
        try {
            $st = $conn->prepare("SELECT team_id FROM analyst_teams WHERE analyst_id = ?");
            $st->execute([$viewer->analystId()]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) $out[] = 'team:' . (int)$t;
        } catch (PDOException $e) { /* no teams table yet — no team principals */ }
    }
    if ($viewer->userId() !== null) {
        $out[] = 'user:' . $viewer->userId();
        $memberType = 'user';
        $memberId   = $viewer->userId();
    }

    if ($memberType !== null) {
        try {
            $st = $conn->prepare(
                "SELECT m.group_id FROM knowledge_user_group_members m
                   JOIN knowledge_user_groups g ON g.id = m.group_id AND g.is_active = 1
                  WHERE m.member_type = ? AND m.member_id = ?
                    AND (m.expires_at IS NULL OR m.expires_at > UTC_TIMESTAMP())"
            );
            $st->execute([$memberType, $memberId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $g) $out[] = 'user_group:' . (int)$g;
        } catch (PDOException $e) { /* groups not created yet */ }
    }
    return $out;
}

/**
 * Per-request memo for the four things the access list needs to look up: the
 * permission model, whether any rules exist at all, the rules themselves, and
 * the folder tree. Read once per request rather than once per query — a page
 * that lists articles and then fetches one would otherwise ask four times over.
 *
 * A holder class rather than `static` inside each function for one reason: a
 * `static` cannot be cleared from outside, and two callers genuinely need it
 * cleared — the test harness, which changes permissions and asks again in the
 * same process, and any long-running worker. A cache nobody can invalidate is
 * a cache that will eventually answer with yesterday's permissions.
 */
final class KnowledgeAclCache
{
    private static array $store = [];

    /** @return mixed|null null = not cached (distinct from a cached null) */
    public static function get(string $key)
    {
        return array_key_exists($key, self::$store) ? self::$store[$key] : null;
    }

    /** Stores and returns the value, so callers can `return set(...)`. */
    public static function set(string $key, $value)
    {
        self::$store[$key] = $value;
        return $value;
    }

    public static function clear(): void { self::$store = []; }
}

/**
 * Forget everything memoised about folders and the access list.
 *
 * Call after CHANGING permissions inside a single process — the tests do this
 * constantly, and any future admin screen that edits an access list and then
 * re-renders in the same request must too, or it will show the state it just
 * replaced.
 */
function knowledgeAclResetCaches(): void
{
    KnowledgeAclCache::clear();
}

/** Which permission model this install runs. Default 'containers' — the safe end. */
function knowledgeFolderPermissionModel(PDO $conn): string
{
    $mode = KnowledgeAclCache::get("mode");
    if ($mode !== null) return $mode;
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_folder_permission_model'");
        $st->execute();
        $v = (string)($st->fetchColumn() ?: '');
    } catch (PDOException $e) { $v = ''; }
    return KnowledgeAclCache::set("mode", ($v === "filing") ? "filing" : "containers");
}

/**
 * Is ANYTHING restricted on this install? The cheap test that skips all the work.
 *
 * ⚠️ IT IS NOT ENOUGH TO ASK WHETHER knowledge_acl HAS ROWS, and that mistake
 * shipped for exactly one afternoon. A **Restricted object with an empty list**
 * is a real restriction — it means "restricted to nobody" — and it has no access
 * rows at all. Asking only about rows meant that emptying a list (or flipping an
 * Open object to Restricted, which WIPES the list by design) could leave
 * knowledge_acl empty, take this fast path, and hand the object back to
 * everybody. The most restrictive state in the model was the one that turned
 * the guard off.
 *
 * Found by a harness that drove the real modal rather than a fixture that always
 * had rows in it — the unit-shaped test passed throughout.
 */
function knowledgeAclHasAnyRows(PDO $conn): bool
{
    $any = KnowledgeAclCache::get("any");
    if ($any !== null) return $any;
    try {
        $any = (bool)$conn->query("SELECT 1 FROM knowledge_acl LIMIT 1")->fetchColumn()
            || (bool)$conn->query("SELECT 1 FROM knowledge_folders WHERE is_restricted = 1 LIMIT 1")->fetchColumn()
            || (bool)$conn->query("SELECT 1 FROM knowledge_articles WHERE is_restricted = 1 LIMIT 1")->fetchColumn();
    } catch (PDOException $e) {
        // Cannot tell — assume there ARE rules so the guard still runs, matching
        // tenancyColumnExists()'s direction. Failing the other way would drop the
        // access list on exactly the hiccup where you least want it dropped.
        $any = true;
    }
    return KnowledgeAclCache::set("any", $any);
}

/** All access rows as [type][object_id]['principal:id' => true]. One query. */
function knowledgeAclIndex(PDO $conn): array
{
    $idx = KnowledgeAclCache::get("acl");
    if ($idx !== null) return $idx;
    $idx = [];
    try {
        $st = $conn->query("SELECT object_type, object_id, principal_type, principal_id FROM knowledge_acl");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $idx[$r['object_type']][(int)$r['object_id']][$r['principal_type'] . ':' . (int)$r['principal_id']] = true;
        }
    } catch (PDOException $e) { $idx = []; }
    return KnowledgeAclCache::set("acl", $idx);
}

/** The folder tree as [id => ['parent'=>?int,'restricted'=>int,'inherit'=>int]]. */
function knowledgeFolderIndex(PDO $conn): array
{
    $idx = KnowledgeAclCache::get("folders");
    if ($idx !== null) return $idx;
    $idx = [];
    try {
        $st = $conn->query("SELECT id, parent_id, is_restricted, inherit_permissions FROM knowledge_folders");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $idx[(int)$r['id']] = [
                'parent'     => $r['parent_id'] === null ? null : (int)$r['parent_id'],
                'restricted' => (int)$r['is_restricted'],
                'inherit'    => (int)$r['inherit_permissions'],
            ];
        }
    } catch (PDOException $e) { $idx = []; }
    return KnowledgeAclCache::set("folders", $idx);
}

/**
 * Do the folder/access-list tables exist yet?
 *
 * Mirrors tenancyColumnExists(): cached per request, and on an UNEXPECTED error
 * it assumes the tables ARE present so the guard still runs. A probe that fails
 * open would drop the access list on exactly the kind of database hiccup where
 * you least want it dropped.
 */
function knowledgeAclTablesExist(PDO $conn): bool
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'knowledge_acl'");
        return $cache = (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('knowledge visibility: could not inspect knowledge_acl (' . $e->getMessage()
                  . ') — assuming it exists so the guard still runs');
        return $cache = true;
    }
}
