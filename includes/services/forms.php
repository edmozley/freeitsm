<?php
/**
 * FormsService — the single home for the forms module's write rules:
 * form save (create + id-based field sync), version fork, delete
 * (leaf/chain), submission create (with the form.submitted workflow dispatch)
 * and submission delete.
 *
 * Shared by the UI endpoints (api/forms/*.php) and the REST API
 * (api/v1/resources/forms.php). Each caller passes an ActorContext + canonical
 * input; this layer validates + writes and returns the affected id(s) or throws
 * ServiceError. It never emits HTTP. The AI form-generation + settings endpoints
 * are UI-only and stay out of here.
 *
 * Canonical behaviour = the API resource's (see docs/design/service-layer.md):
 * an empty field label / unknown field_type is a 422 (the UI silently dropped /
 * blindly stored), an unknown field id in a submission is a 422 (was a raw FK
 * error), a frozen (non-leaf) version can't be edited/forked (409), and delete
 * is leaf-only unless the chain flag is set. Timestamps are written UTC.
 *
 * ⚙️ Side effect: a successful submission dispatches the `form.submitted`
 * workflow event with a label-keyed answers map (+ the first email answer) —
 * the "new starter form → tickets" automation. It fires after commit and its
 * errors are swallowed so a workflow can never break a submission — caught as
 * Throwable, not Exception, because these run after the commit and a PHP Error
 * escaping there would fail a submission that is already saved.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../form_logic.php';
// ⚠️ A HARD dependency, not a convenience. Lookup scoping has to know which
// company is Default, because a NULL tenant_id on an asset means "the Default
// company's". Behind a function_exists() guard this was worse than a missing
// feature: the search endpoint loads tenancy and so OFFERS those records, while
// the submit guard runs wherever the caller put it and, without tenancy loaded,
// REFUSES the very record it had just offered. Two halves of one rule cannot be
// allowed to disagree depending on who included what.
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../rbac.php';   // analystMayAct() - "Design forms" (#180 Part 3)
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class FormsService
{
    // 'section' is a heading, not a question — see ANSWERABLE_TYPES.
    const FIELD_TYPES = ['text', 'textarea', 'email', 'number', 'checkbox', 'checkboxes', 'dropdown', 'radio', 'datetime', 'lookup', 'section', 'note', 'image', 'grid'];

    /**
     * ⚠️ THIS LIST IS NARROWER THAN ITS NAME. It is what a conditional-visibility
     * rule may DEPEND ON, not everything that collects an answer — 'grid' stores
     * a real answer and is deliberately absent, because a table cannot be a
     * condition trigger. Do not reach for it to mean "is this a question";
     * isAnswerable() below is that question.
     */
    const ANSWERABLE_TYPES = ['text', 'textarea', 'email', 'number', 'checkbox', 'checkboxes', 'dropdown', 'radio', 'datetime', 'lookup'];

    /**
     * The types that are there to be READ, not answered.
     *
     * 🔴 WHY THIS EXISTS AS A LIST. "Is this a question?" was asked in thirteen
     * places by writing `=== 'section'`, in PHP, in SQL and in three JavaScript
     * renderers. That is fine while there is exactly one presentational type and
     * becomes a silent bug the moment there are two: every site that was never
     * updated treats the new one as a question, and a block of standing text
     * turns into a column in an export, a row in a submission, or something a
     * required-field check refuses to let anyone past.
     *
     * Ask isAnswerable() instead. The one place a literal 'section' is still
     * correct is code that draws a heading specifically, because that is about
     * what the thing IS, not about whether it collects anything.
     */
    const PRESENTATIONAL_TYPES = ['section', 'note', 'image'];

    /**
     * What a 'note' may look like. 🔴 A NAMED LIST, NEVER A COLOUR.
     *
     * Every one of these resolves to theme tokens that are defined twice, once
     * per mode. A hex an author typed can only be right in one of them: in dark
     * mode an informational panel's background has to sit DARKER than the page
     * and its text LIGHTER, so the pair inverts rather than shifting. Nobody
     * building a form should be asked to get that right, and a form built by
     * someone who was not asked is a form that looks broken at night.
     *
     * It also keeps forms looking like FreeITSM, and it means a re-skin carries
     * every form with it.
     */
    const NOTE_STYLES = ['plain', 'info', 'warning', 'danger', 'success'];
    const NOTE_STYLE_DEFAULT = 'info';

    /** How long a note's optional body may be. An abuse ceiling, not a feature. */
    const NOTE_BODY_MAX = 4000;

    /**
     * Where a question's label sits relative to its control.
     *
     * 'above' is what every form has always done and stays the default, so
     * absent means unchanged and nothing needed migrating. 'beside' is what
     * produces a row reading `| First name | [input] | Surname | [input] |`
     * without any cell editor: two half-width questions with the label beside
     * give exactly that, which is the shape a paper form usually wants.
     *
     * 🔴 BESIDE COLLAPSES TO ABOVE ON A PHONE, always. A label beside an input
     * at 360px leaves roughly 200px for the control — the same objection that
     * makes every width full on a phone, for the same reason.
     */
    const LABEL_POSITIONS = ['above', 'beside'];
    const LABEL_POSITION_DEFAULT = 'above';

    /**
     * How wide an image block's picture may draw, as a percentage of its column.
     *
     * 🔑 A percentage, not pixels. Everything else on a form is sized in
     * twelfths of the row and reflows; asking an author for "480px" produces a
     * picture that is right on their screen and wrong on a phone. 100 is the
     * default and stores nothing, so an image simply fits its column unless
     * somebody deliberately holds it back.
     */
    const IMAGE_MAX_WIDTHS = [100, 75, 50, 25];
    const IMAGE_MAX_DEFAULT = 100;

    /**
     * Does this field type collect an answer?
     *
     * @param ?string $type  the field_type; null counts as not answerable
     */
    public static function isAnswerable(?string $type): bool
    {
        return $type !== null && !in_array($type, self::PRESENTATIONAL_TYPES, true);
    }

    /**
     * A SQL fragment excluding the presentational types: `field_type NOT IN (…)`.
     *
     * 🔑 Written as an EXCLUSION rather than a list of questions on purpose. A
     * new question type is then included automatically, and only a new
     * presentational type has to be declared — which is the direction that fails
     * safe. The opposite shape is what left 'grid' out of three lists.
     *
     * @param string $column  the field-type column as the calling query names it, written in code
     */
    public static function presentationalSqlExclusion(string $column = 'field_type'): string
    {
        return $column . " NOT IN ('" . implode("','", self::PRESENTATIONAL_TYPES) . "')";
    }

    /**
     * What a 'datetime' field actually asks for, held in config.date_mode.
     *
     * ONE field type with a mode rather than three separate types, because a field's
     * field_type cannot be changed once it exists: picking `date` and later wanting the
     * time too would mean deleting the field and adding a new one, which retires the old
     * one and strands every answer already given to it under a separate column. A mode
     * is a setting you can flip, and the field keeps its identity.
     */
    /* A field's width, in twelfths of the form. 12 divides by 1, 2, 3, 4 and 6,
       which is why this is the convention everywhere: full, three-quarters,
       two-thirds, half, third and quarter are all whole numbers of columns, and
       so are the asymmetric pairs an actual document wants (8+4 for
       "Area | Date", not 6+6).

       🔑 ABSENT = FULL. Every field that predates this has no `width` key at
       all, so nothing needs migrating and no existing form changes.

       ⚠️ Below the mobile breakpoint everything is full width regardless. Two
       controls side by side on a 360px screen is worse than one, and the
       portal is where customers fill these in. */
    /* ---- Grid ------------------------------------------------------------
       A question whose answer is a table: named columns, and as many rows as
       the person needs.

       🔑 THE PALETTE IS RESTRICTED ON PURPOSE. A file upload or a signature pad
       inside a 200px column is unusable, and per-cell conditional logic is
       combinatorial. The product that solved this commercially restricts its
       table the same way and offers a separate "repeating section" for the rich
       case. Widening this later is easy; narrowing it after people have built
       forms is not.

       ⚠️ `radio` is permitted because it was agreed, but a radio group in a
       table cell is cramped past two options — a dropdown says the same thing
       in the space available. The builder should steer people accordingly.

       ⬜ `lookup` is NOT here yet. It is a scoped search over the install's own
       records, and making that behave inside a repeating row is its own piece
       of work rather than a line on a list. */
    const GRID_CELL_TYPES = ['text', 'number', 'dropdown', 'radio', 'checkbox', 'datetime'];

    /** Cell types that are meaningless without a list to choose from. */
    const GRID_CELL_TYPES_WITH_OPTIONS = ['dropdown', 'radio'];

    /* A cap, not a design limit. Twelve is what fits on a page and what the
       comparable product allows; beyond that a table is a spreadsheet and
       belongs somewhere else. */
    const GRID_MAX_COLUMNS = 12;

    /* Rows are "as many as you need", so this is an ABUSE ceiling rather than a
       feature. Nobody fills in 500 rows of a web form by hand; a script might. */
    const GRID_MAX_ROWS = 500;

    const FIELD_WIDTHS = [12, 9, 8, 6, 4, 3];
    const FIELD_WIDTH_DEFAULT = 12;

    /* ── Layout ──────────────────────────────────────────────────────────────
       A form's layout says WHERE its questions go; the questions themselves are
       a pool in form_fields. Separating the two is what lets an existing form be
       re-laid-out without touching a question, a condition or a stored answer.

       🔑 'flow' and 'grid' are THE SAME FORMAT. A grid cell may span rows and may
       hold no question at all; a flow layout is a grid where every rowspan is 1.
       That is deliberate and is the whole reason a cell designer can be added
       later as a UI rather than as a rewrite — see docs/design/form-designer.md.

       🔴 A flow layout's rows are STRUCTURAL, NOT MARKUP. The renderers flatten
       them back to a stream of cells, because the CSS grid already wraps at 12
       columns and emitting row elements would change how every existing form
       draws. Real row elements arrive only when rowspan does. */
    const LAYOUT_TYPES = ['flow', 'grid'];
    const LAYOUT_TYPE_DEFAULT = 'flow';
    const LAYOUT_COLUMNS = 12;

    /* An abuse ceiling, not a feature — the same reasoning as GRID_MAX_ROWS. */
    const LAYOUT_MAX_ROWS = 500;

    const DATE_MODES = ['date', 'time', 'datetime'];
    const DATE_MODE_DEFAULT = 'date';

    /**
     * Accepted stored formats per mode — exactly what the matching HTML input produces.
     * ⚠️ These are NAIVE local values, deliberately. See dateModeOf().
     */
    const DATE_MODE_PATTERNS = [
        'date'     => '/^\d{4}-\d{2}-\d{2}$/',
        'time'     => '/^\d{2}:\d{2}$/',
        'datetime' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
    ];

    // ==================================================================
    //  Lookup fields — asking about something we already hold
    // ==================================================================
    //
    // A 'lookup' asks "which one?" against FreeITSM's own records instead of a
    // free-text box: which laptop, which service, which contract. It is ONE
    // field type with a `config.lookup_source`, for exactly the reason
    // `datetime` is one type with a mode — a field's field_type cannot change
    // once it exists, so five separate types would force an irreversible guess.
    //
    // ⚠️ WHAT IS STORED IS BOTH THE ID AND THE LABEL, as JSON:
    //     {"id":123,"label":"LAPTOP-042"}
    //
    // The label is what the answer MEANT when it was given; the id is what makes
    // it a lookup rather than a text box. Storing only the id would rewrite
    // history when somebody renames an asset, and leave a dangling number when
    // one is deleted. Storing only the label would lose the link to the record.
    // This is the same split as an issue tracker's stable id versus its display
    // key — see the Issue Trackers developer guide.

    /**
     * The sources a lookup may point at.
     *
     * `tenant_col` is how a row is scoped to a company. NULL means the table has
     * no company column, so it can never be offered on the portal — see
     * lookupSourcePortalAllowed().
     *
     * ⚠️ Adding a source here is NOT the whole job: it must also be safe to show
     * a customer. Read the note on `portal_safe` before adding one.
     */
    const LOOKUP_SOURCES = [
        'asset' => [
            'table'       => 'assets',
            'id_col'      => 'id',
            // The hostname is what a person recognises; the tags are what they
            // read off a sticker when the hostname means nothing to them.
            'label_col'   => 'hostname',
            'search_cols' => ['hostname', 'asset_tag', 'service_tag'],
            'tenant_col'  => 'tenant_id',
            // Names and tags of kit. A customer picking "which laptop" needs it.
            'portal_safe' => true,
        ],
        'cmdb' => [
            'table'       => 'cmdb_objects',
            'id_col'      => 'id',
            'label_col'   => 'name',
            'search_cols' => ['name'],
            'tenant_col'  => 'tenant_id',
            'portal_safe' => true,
        ],
        'user' => [
            'table'       => 'users',
            'id_col'      => 'id',
            'label_col'   => 'display_name',
            'search_cols' => ['display_name', 'email'],
            'tenant_col'  => 'tenant_id',
            // ⚠️ A staff directory with email addresses. Never a customer's to
            // browse, whatever a form builder ticks.
            'portal_safe' => false,
        ],
    ];

    // ⚠️ NOT here, and each for a reason rather than an oversight:
    //
    //   contracts  — the table has NO company column at all, so a lookup could
    //                not be scoped and a company-restricted analyst would see
    //                every client's contract titles. Needs `contracts.tenant_id`
    //                first; adding the source without it is the leak.
    //   software   — `software_licences` has no name of its own; the product
    //                name lives on `software_inventory_apps`, so the source
    //                needs a join and a decision about which of the two a person
    //                is actually choosing.
    //
    // Both were in the original pitch. They are worth having — they are just not
    // one-line additions, and a half-scoped source is worse than no source.

    /**
     * Which source this lookup points at, or null if it is not configured yet.
     *
     * @param array $field  a form_fields row or payload field; config may be an array or JSON text
     */
    public static function lookupSourceOf(array $field): ?string
    {
        $config = $field['config'] ?? null;
        if (is_string($config)) $config = json_decode($config, true);
        $src = is_array($config) ? ($config['lookup_source'] ?? null) : null;
        return isset(self::LOOKUP_SOURCES[$src]) ? $src : null;
    }

    /**
     * May this particular field be used by a customer on the portal?
     *
     * Two gates, and BOTH must pass:
     *   1. the source is `portal_safe` — a staff directory never is, whatever a
     *      form builder ticks;
     *   2. the field itself has `config.portal_lookup` set — off by default, so
     *      nothing is ever exposed by accident. The person building the form
     *      decides, because they know what that form is for.
     *
     * @param array $field  a form_fields row or payload field; config may be an array or JSON text
     */
    public static function lookupPortalAllowed(array $field): bool
    {
        $src = self::lookupSourceOf($field);
        if ($src === null || empty(self::LOOKUP_SOURCES[$src]['portal_safe'])) return false;

        $config = $field['config'] ?? null;
        if (is_string($config)) $config = json_decode($config, true);
        return is_array($config) && !empty($config['portal_lookup']);
    }

    /**
     * The company-scope SQL for a lookup source, shared by the search and the
     * submit-time guard so the two can never disagree about what is in scope.
     *
     * ⚠️ For these tables `tenant_id IS NULL` means "unassigned — treat as the
     * DEFAULT company's", NOT "shared with everyone". (Knowledge means the
     * opposite by the same NULL; see tenancyKnowledgeFilter.) So a NULL row is
     * in scope only when Default itself is, never as a blanket include — and on
     * a single-company install, where every row is NULL, that is what makes the
     * feature work at all.
     *
     * @param array $meta  the source's LOOKUP_SOURCES entry; its tenant_col is used
     * @param int[] $tenantIds non-empty; callers handle null/empty themselves.
     * @return array{0:string,1:array} [sql clause, params]
     */
    private static function lookupTenantClause(PDO $conn, array $meta, array $tenantIds): array
    {
        $in     = implode(',', array_fill(0, count($tenantIds), '?'));
        $col    = "`{$meta['tenant_col']}`";
        $clause = "$col IN ($in)";
        if (in_array(getDefaultTenantId($conn), array_map('intval', $tenantIds), true)) {
            $clause = "($clause OR $col IS NULL)";
        }
        return [$clause, array_map('intval', $tenantIds)];
    }

    /**
     * Search one lookup source, scoped to what the person asking may see.
     *
     * ⚠️ THE SCOPE IS THE WHOLE FEATURE. A lookup is a search box over records
     * we already hold, so getting `$tenantIds` wrong turns a convenience into a
     * disclosure — one client browsing another's asset register. It is passed in
     * rather than derived here, because the two callers know different things:
     * an analyst has accessible companies, a portal user has exactly one.
     *
     * @param string $source  a LOOKUP_SOURCES key, e.g. 'asset'; anything else returns []
     * @param string $term  the text typed - untrusted, matched with LIKE; '' lists the first rows
     * @param int[]|null $tenantIds  companies whose rows may be returned.
     *                               `null` means UNRESTRICTED and is only ever
     *                               correct for an analyst who can see every
     *                               company. There is no "all" string — a typo
     *                               in a string would silently mean everything.
     * @param int $limit  the most rows to return (1-50)
     * @return array [['id'=>int,'label'=>string], …]
     */
    public static function lookupSearch(PDO $conn, string $source, string $term, ?array $tenantIds, int $limit = 20): array
    {
        if (!isset(self::LOOKUP_SOURCES[$source])) return [];
        $s = self::LOOKUP_SOURCES[$source];

        // ⚠️ An EMPTY array means "no companies", which must return nothing.
        // Treating it as "no filter" is the bug this comment exists to prevent:
        // an analyst with access to nothing would see everything.
        if ($tenantIds !== null && count($tenantIds) === 0) return [];

        $term  = trim($term);
        $limit = max(1, min(50, $limit));

        // Column names come from the registry above, never from the request —
        // they are interpolated into SQL and a user-supplied one would be an
        // injection. The registry is the whitelist.
        $where  = [];
        $params = [];

        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $ors  = [];
            foreach ($s['search_cols'] as $col) {
                $ors[]    = "`$col` LIKE ?";
                $params[] = $like;
            }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        }

        // A row with no label is unpickable — it would render as an empty option.
        $where[] = "`{$s['label_col']}` IS NOT NULL AND `{$s['label_col']}` <> ''";

        if ($tenantIds !== null) {
            [$clause, $tParams] = self::lookupTenantClause($conn, $s, $tenantIds);
            $where[] = $clause;
            $params  = array_merge($params, $tParams);
        }

        $sql = "SELECT `{$s['id_col']}` AS id, `{$s['label_col']}` AS label
                  FROM `{$s['table']}`"
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . " ORDER BY `{$s['label_col']}` LIMIT $limit";

        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('FormsService::lookupSearch(' . $source . '): ' . $e->getMessage());
            return [];
        }

        return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => (string)$r['label']], $rows);
    }

    /**
     * Is this exact record one the person answering was allowed to pick?
     *
     * The generalisation of the rule already applied to dropdowns — *"a choice
     * field must be answered with one of ITS OWN choices"* — to a list that is
     * built at answer time. Without it the stored id is whatever the client
     * posted, and a crafted submission could name a record from another company
     * that then appears, resolved and labelled, on a submission an analyst reads.
     *
     * @param string $source  a LOOKUP_SOURCES key
     * @param int $id  the record id that was posted
     * @param ?array $tenantIds  companies whose rows may be picked; null means unrestricted, [] means none
     */
    public static function lookupValueAllowed(PDO $conn, string $source, int $id, ?array $tenantIds): bool
    {
        if (!isset(self::LOOKUP_SOURCES[$source]) || $id <= 0) return false;
        $s = self::LOOKUP_SOURCES[$source];
        if ($tenantIds !== null && count($tenantIds) === 0) return false;

        $params = [$id];
        $sql = "SELECT 1 FROM `{$s['table']}` WHERE `{$s['id_col']}` = ?";

        if ($tenantIds !== null) {
            // The SAME clause the search used. If these two ever differed, the
            // list would offer a record the submit then refused.
            [$clause, $tParams] = self::lookupTenantClause($conn, $s, $tenantIds);
            $sql   .= " AND $clause";
            $params = array_merge($params, $tParams);
        }

        try {
            $stmt = $conn->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            // ⚠️ Refuse on error. A lookup that cannot be checked must not be
            // accepted — the failure mode of the alternative is silent.
            error_log('FormsService::lookupValueAllowed(' . $source . '): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The companies a portal user may pick lookup records from: their own, and only
     * that. Shared by the search and the submit so the two cannot disagree.
     *
     * ⚠️ Never null, which would mean everything. A portal user with no company
     * falls back to the Default company rather than to unrestricted.
     *
     * @param int $userId  the requester's users.id
     * @return int[]  always exactly one company
     */
    public static function lookupPortalScope(PDO $conn, int $userId): array
    {
        $stmt = $conn->prepare("SELECT tenant_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $tenant = $stmt->fetchColumn();
        return [$tenant !== false && $tenant !== null ? (int)$tenant : (int)getDefaultTenantId($conn)];
    }

    /**
     * A record's own name, so a stored answer says what the record is called rather
     * than what the post claimed. Null when it has none, which is also why the
     * search would never have offered it.
     *
     * @param string $source  a LOOKUP_SOURCES key
     * @param int $id  a record already checked with lookupValueAllowed()
     */
    private static function lookupRecordLabel(PDO $conn, string $source, int $id): ?string
    {
        $s = self::LOOKUP_SOURCES[$source];
        $stmt = $conn->prepare("SELECT `{$s['label_col']}` FROM `{$s['table']}` WHERE `{$s['id_col']}` = ?");
        $stmt->execute([$id]);
        $label = trim((string)$stmt->fetchColumn());
        return $label !== '' ? $label : null;
    }

    /** Operators a conditional-visibility rule may use. Mirrors assets/js/form-logic.js. */
    const CONDITION_OPS = [
        'equals', 'not_equals', 'contains', 'is_empty', 'is_not_empty',
        'greater_than', 'less_than',
        // Date-shaped aliases of greater_than / less_than. Same comparison — ISO-8601
        // values sort correctly as plain strings — but "is more than 2026-08-14" reads
        // like nonsense next to a date, so the operator gets a name that doesn't.
        'is_after', 'is_before',
    ];

    /**
     * The mode a 'datetime' field is in, defaulting for a field saved before modes
     * existed or by an adapter that didn't send one.
     *
     * @param array $field  a form_fields row or payload field; config may be an array or JSON text
     */
    public static function dateModeOf(array $field): string
    {
        $config = $field['config'] ?? null;
        if (is_string($config)) $config = json_decode($config, true);
        $mode = is_array($config) ? ($config['date_mode'] ?? null) : null;
        return in_array($mode, self::DATE_MODES, true) ? $mode : self::DATE_MODE_DEFAULT;
    }

    // ======================================================================
    //  Forms
    // ======================================================================

    /** Create (no id) or update (id present) a form + its fields. Returns ['id','created']. */
    // ---------------------------------------------------------------- //
    //  Collections                                                     //
    // ---------------------------------------------------------------- //

    /** What closing a collection DOES. The operator chooses; see closeEffect(). */
    const CLOSE_EFFECTS = ['reporting_only', 'stop_submissions', 'stop_and_hide'];
    const CLOSE_EFFECT_DEFAULT = 'stop_submissions';

    /**
     * 🔴 Is the collections schema actually here?
     *
     * A new column has to survive being absent. Someone who pulls the code and
     * has not yet run DB Verification must get the forms module they had
     * yesterday, not a wall of 500s - so every read and write below is guarded
     * by this, and with it false the feature is simply not offered.
     *
     * Cached per request: this is an information_schema query and the forms
     * list would otherwise run it once per form.
     */
    public static function collectionsAvailable(PDO $conn): bool
    {
        static $known = null;
        if ($known !== null) return $known;
        try {
            $q = $conn->prepare(
                "SELECT
                   (SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_name = 'form_collections')
                 + (SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'forms' AND column_name = 'collection_id')
                 + (SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'form_submissions' AND column_name = 'collection_id')"
            );
            $q->execute();
            // All three, not any: two of the three is a half-migrated database
            // and offering the feature there writes stamps nothing can read.
            $known = ((int)$q->fetchColumn() === 3);
        } catch (Exception) {
            $known = false;
        }
        return $known;
    }

    /**
     * What closing a collection means on this install. An operator setting
     * rather than a property of the collection, because organisations disagree
     * about what "closed" implies and there is no right answer to hard-code.
     */
    public static function closeEffect(PDO $conn): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $q = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'forms_collection_close_effect'");
            $q->execute();
            $v = (string)$q->fetchColumn();
        } catch (Exception) {
            $v = '';
        }
        $cached = in_array($v, self::CLOSE_EFFECTS, true) ? $v : self::CLOSE_EFFECT_DEFAULT;
        return $cached;
    }

    /**
     * Collections, newest first, each with how many forms point at it and how
     * many submissions carry its stamp.
     *
     * ⚠️ The form count is over LEAVES only. A form's history is a chain of
     * rows all carrying collection_id, so counting rows would report "Staff
     * Survey 2026 - 4 forms" for one form that had been edited three times.
     * The submission count is not filtered that way on purpose: every one of
     * those IS a real submission, whichever version it came from.
     */
    public static function listCollections(PDO $conn): array
    {
        if (!self::collectionsAvailable($conn)) return [];
        $q = $conn->query(
            "SELECT c.id, c.name, c.description,
                    c.closed_datetime, c.closed_by, closer.full_name AS closed_by_name,
                    c.created_date,
                    (SELECT COUNT(*) FROM forms f
                       WHERE f.collection_id = c.id
                         AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)) AS form_count,
                    (SELECT COUNT(*) FROM form_submissions s WHERE s.collection_id = c.id) AS submission_count
               FROM form_collections c
               LEFT JOIN analysts closer ON closer.id = c.closed_by
              ORDER BY c.closed_datetime IS NOT NULL, c.name"
        );
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The leaf forms currently paired to a collection, for the settings list.
     *
     * @param int $collectionId  the form_collections row
     */
    public static function collectionForms(PDO $conn, int $collectionId): array
    {
        if (!self::collectionsAvailable($conn)) return [];
        $q = $conn->prepare(
            "SELECT f.id, f.title, f.is_active, f.is_portal_visible
               FROM forms f
              WHERE f.collection_id = ?
                AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)
              ORDER BY f.title"
        );
        $q->execute([$collectionId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Set exactly which forms belong to a collection, from the collection's
     * side. Ed asked for this as well as the per-form control on the forms
     * list; both write through the same column, so they cannot disagree.
     *
     * 🔑 A form belongs to AT MOST ONE collection, so adding one that is
     * already in another MOVES it. That is a real consequence and the caller
     * has to have said it out loud - the returned `moved` list is what the
     * screen uses to do so.
     *
     * 🔴 Touches `forms` ONLY. Submissions already made keep the collection
     * they were filed under: unlinking a form here must not rewrite history,
     * which is the entire point of the second column.
     *
     * @param int $collectionId  the form_collections row whose forms are set
     * @param  int[] $formIds leaf form ids that should be in the collection
     * @return array{added:int,removed:int,moved:array}
     */
    public static function setCollectionForms(PDO $conn, ActorContext $ctx, int $collectionId, array $formIds): array
    {
        self::requireCollections($conn);

        $exists = $conn->prepare("SELECT id FROM form_collections WHERE id = ?");
        $exists->execute([$collectionId]);
        if ($exists->fetchColumn() === false) {
            throw new ServiceError('not_found', 'not_found', 'Collection not found');
        }

        $wanted = array_values(array_unique(array_map('intval', $formIds)));

        /* Only leaves. A form is a chain of rows and the frozen versions are
           history; pairing one would put a collection on a snapshot nobody can
           submit against. */
        $leaves = [];
        if ($wanted) {
            $in = implode(',', array_fill(0, count($wanted), '?'));
            $q = $conn->prepare(
                "SELECT f.id, f.title, f.collection_id
                   FROM forms f
                  WHERE f.id IN ($in)
                    AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)"
            );
            $q->execute($wanted);
            $leaves = $q->fetchAll(PDO::FETCH_ASSOC);
        }

        // Which of them are being taken from somewhere else?
        $moved = [];
        foreach ($leaves as $row) {
            $from = $row['collection_id'];
            if ($from !== null && (int)$from !== $collectionId) {
                $moved[] = ['id' => (int)$row['id'], 'title' => $row['title'], 'from' => (int)$from];
            }
        }

        $conn->beginTransaction();
        try {
            // Out: anything currently in this collection but not in the list.
            $keep = array_column($leaves, 'id');
            if ($keep) {
                $in = implode(',', array_fill(0, count($keep), '?'));
                $out = $conn->prepare("UPDATE forms SET collection_id = NULL, modified_by = ?, modified_date = UTC_TIMESTAMP()
                                        WHERE collection_id = ? AND id NOT IN ($in)");
                $out->execute(array_merge([$ctx->actorId, $collectionId], $keep));
            } else {
                $out = $conn->prepare("UPDATE forms SET collection_id = NULL, modified_by = ?, modified_date = UTC_TIMESTAMP()
                                        WHERE collection_id = ?");
                $out->execute([$ctx->actorId, $collectionId]);
            }
            $removed = $out->rowCount();

            // In: everything on the list that is not already here.
            $added = 0;
            if ($keep) {
                $in = implode(',', array_fill(0, count($keep), '?'));
                $ins = $conn->prepare("UPDATE forms SET collection_id = ?, modified_by = ?, modified_date = UTC_TIMESTAMP()
                                        WHERE id IN ($in) AND (collection_id IS NULL OR collection_id <> ?)");
                $ins->execute(array_merge([$collectionId, $ctx->actorId], $keep, [$collectionId]));
                $added = $ins->rowCount();
            }
            $conn->commit();
            return ['added' => $added, 'removed' => $removed, 'moved' => $moved];
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Every leaf form, with the collection it is in — the picker's whole list,
     * so somebody choosing can see what a tick would take away from where.
     */
    public static function formsForPicker(PDO $conn): array
    {
        if (!self::collectionsAvailable($conn)) return [];
        return $conn->query(
            "SELECT f.id, f.title, f.is_active, f.collection_id, c.name AS collection_name
               FROM forms f
               LEFT JOIN form_collections c ON c.id = f.collection_id
              WHERE NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)
              ORDER BY f.title"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Every submission stamped into a collection, across all its forms, newest
     * first — plus the field definitions of each form involved, because a
     * collection's rows do not share a set of questions and both the detail
     * panel and the PDF need the right ones.
     *
     * 🔑 Selected on `form_submissions.collection_id` — the STAMP — not by
     * joining through the form. A submission made while the form was in this
     * collection belongs here whatever the form says today, and one made before
     * it was paired does not. That is the whole reason for the second column.
     *
     * @param int $collectionId  the form_collections row
     */
    public static function collectionSubmissions(PDO $conn, int $collectionId, ?array $companyScope = null): array
    {
        self::requireCollections($conn);
        // GH #190: one made inside a task is visible only within that task's company.
        require_once __DIR__ . '/task_forms.php';
        [$scopeSql, $scopeArgs] = TaskForms::submissionScopeSql($conn, $companyScope, 's');

        $q = $conn->prepare(
            "SELECT s.id, s.form_id, f.title AS form_title,
                    -- Matches get_submissions.php: `users` has display_name, not
                    -- full_name, and a requester who set neither still has an email.
                    COALESCE(a.full_name, u.display_name, u.email) AS submitted_by,
                    s.submitted_date,
                    s.approval_status, s.approval_comment,
                    decider.full_name AS approval_decided_by,
                    s.approval_decided_datetime
               FROM form_submissions s
               JOIN forms f            ON f.id = s.form_id
               LEFT JOIN analysts a    ON a.id = s.submitted_by
               LEFT JOIN users u       ON u.id = s.submitted_by_user_id
               LEFT JOIN analysts decider ON decider.id = s.approval_decided_by_id
              WHERE s.collection_id = ? AND $scopeSql
              ORDER BY s.submitted_date DESC, s.id DESC"
        );
        $q->execute(array_merge([$collectionId], $scopeArgs));
        $subs = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$subs) return ['submissions' => [], 'forms' => []];

        // The answers, in one query rather than one per submission.
        $ids = array_column($subs, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $d = $conn->prepare("SELECT submission_id, field_id, field_value FROM form_submission_data WHERE submission_id IN ($in)");
        $d->execute($ids);
        $byId = [];
        foreach ($d->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byId[(int)$row['submission_id']][(int)$row['field_id']] = $row['field_value'];
        }
        foreach ($subs as &$sub) {
            $sub['data'] = $byId[(int)$sub['id']] ?? [];
        }
        unset($sub);

        /* The questions of every form represented. ⚠️ Retired questions are
           INCLUDED: the answers people gave them are still on these records,
           and a heading that simply disappeared would make those answers look
           as though they had never been given. */
        $formIds = array_values(array_unique(array_map('intval', array_column($subs, 'form_id'))));
        $in = implode(',', array_fill(0, count($formIds), '?'));
        $fq = $conn->prepare(
            "SELECT id, form_id, label, field_type, options, config, is_deleted, sort_order
               FROM form_fields
              WHERE form_id IN ($in) AND " . self::presentationalSqlExclusion() . "
              ORDER BY form_id, sort_order, id"
        );
        $fq->execute($formIds);

        $forms = [];
        foreach ($formIds as $fid) $forms[$fid] = ['id' => $fid, 'title' => '', 'fields' => []];
        foreach ($subs as $sub) $forms[(int)$sub['form_id']]['title'] = $sub['form_title'];
        foreach ($fq->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $forms[(int)$row['form_id']]['fields'][] = $row;
        }

        return ['submissions' => $subs, 'forms' => array_values($forms)];
    }

    /** Throws until Database Verification has added the form collections schema. */
    private static function requireCollections(PDO $conn): void
    {
        if (!self::collectionsAvailable($conn)) {
            throw new ServiceError('conflict', 'schema_missing',
                'Collections need a database update. Run DB Verification in System settings.');
        }
    }

    /**
     * Create or rename. Returns the id.
     *
     * @param array $in  id (0 or absent = new), name (required, unique ignoring case), description
     */
    public static function saveCollection(PDO $conn, ActorContext $ctx, array $in): int
    {
        self::requireCollections($conn);
        $id   = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        $desc = trim((string)($in['description'] ?? ''));

        if ($name === '') {
            throw new ServiceError('validation', 'missing_field', 'A collection needs a name');
        }
        if (mb_strlen($name) > 255) {
            throw new ServiceError('validation', 'invalid_field', 'Name is too long');
        }

        // Case-insensitive, because "Staff Survey 2026" and "staff survey 2026"
        // in the same list is a filing error waiting to happen. Not a UNIQUE
        // index: that would reject on the collation's terms rather than on
        // these, and give a raw SQL error instead of a sentence.
        $dupe = $conn->prepare("SELECT id FROM form_collections WHERE LOWER(name) = LOWER(?) AND id <> ?");
        $dupe->execute([$name, $id]);
        if ($dupe->fetchColumn() !== false) {
            throw new ServiceError('conflict', 'duplicate', 'A collection with that name already exists');
        }

        if ($id > 0) {
            $q = $conn->prepare("UPDATE form_collections SET name = ?, description = ?, modified_date = UTC_TIMESTAMP() WHERE id = ?");
            $q->execute([$name, $desc !== '' ? $desc : null, $id]);
            if ($q->rowCount() === 0) {
                $e = $conn->prepare("SELECT id FROM form_collections WHERE id = ?");
                $e->execute([$id]);
                if ($e->fetchColumn() === false) throw new ServiceError('not_found', 'not_found', 'Collection not found');
            }
            return $id;
        }

        $conn->prepare(
            "INSERT INTO form_collections (name, description, created_by, created_date, modified_date)
             VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$name, $desc !== '' ? $desc : null, $ctx->actorId]);
        return (int)$conn->lastInsertId();
    }

    /**
     * Close or reopen.
     *
     * 🔴 Writes to form_collections and NOTHING ELSE. If closing stamped
     * is_portal_visible = 0 on the forms, reopening would turn them all back
     * on - including one deliberately kept off the portal. What closing means
     * is asked at the moment somebody tries to fill the form in; see
     * submissionsBlockedBy() and portalHiddenBy().
     *
     * @param int $id  the form_collections row
     * @param bool $closed  true to close it, false to reopen it
     */
    public static function setCollectionClosed(PDO $conn, ActorContext $ctx, int $id, bool $closed): void
    {
        self::requireCollections($conn);
        $q = $conn->prepare(
            $closed
                ? "UPDATE form_collections SET closed_datetime = UTC_TIMESTAMP(), closed_by = ?, modified_date = UTC_TIMESTAMP() WHERE id = ? AND closed_datetime IS NULL"
                : "UPDATE form_collections SET closed_datetime = NULL, closed_by = NULL, modified_date = UTC_TIMESTAMP() WHERE id = ? AND closed_datetime IS NOT NULL"
        );
        $closed ? $q->execute([$ctx->actorId, $id]) : $q->execute([$id]);

        if ($q->rowCount() === 0) {
            $e = $conn->prepare("SELECT closed_datetime FROM form_collections WHERE id = ?");
            $e->execute([$id]);
            $row = $e->fetch(PDO::FETCH_ASSOC);
            if ($row === false) throw new ServiceError('not_found', 'not_found', 'Collection not found');
            // Already in the state asked for - not an error worth failing on.
        }
    }

    /**
     * Delete, but only while nothing is stamped into it.
     *
     * 🔑 A collection holding submissions is CLOSABLE, not deletable. The
     * database enforces this too (no ON DELETE rule on the submission FK), but
     * a raw constraint error is not an explanation, so say it here.
     *
     * @param int $id  the form_collections row to delete; refused while it holds submissions
     */
    public static function deleteCollection(PDO $conn, ActorContext $ctx, int $id): void
    {
        self::requireCollections($conn);
        $q = $conn->prepare("SELECT COUNT(*) FROM form_submissions WHERE collection_id = ?");
        $q->execute([$id]);
        if ((int)$q->fetchColumn() > 0) {
            throw new ServiceError('conflict', 'has_submissions',
                'This collection holds submissions, so it can be closed but not deleted.');
        }
        // Forms pointing at it are simply unpaired (ON DELETE SET NULL).
        $d = $conn->prepare("DELETE FROM form_collections WHERE id = ?");
        $d->execute([$id]);
        if ($d->rowCount() === 0) throw new ServiceError('not_found', 'not_found', 'Collection not found');
    }

    /**
     * Is this form's collection closed in a way that stops new submissions?
     * Returns the collection name when it is, null when it is not.
     *
     * 🔑 Asked at the moment it matters, never stored. `is_active` remains the
     * form's OWN switch - two independent reasons a form may be shut, and
     * neither overwrites the other.
     *
     * @param int $formId  the form someone is about to fill in
     */
    public static function submissionsBlockedBy(PDO $conn, int $formId): ?string
    {
        if (!self::collectionsAvailable($conn)) return null;
        if (self::closeEffect($conn) === 'reporting_only') return null;
        try {
            $q = $conn->prepare(
                "SELECT c.name FROM forms f
                   JOIN form_collections c ON c.id = f.collection_id
                  WHERE f.id = ? AND c.closed_datetime IS NOT NULL"
            );
            $q->execute([$formId]);
            $name = $q->fetchColumn();
            return $name === false ? null : (string)$name;
        } catch (Exception) {
            // A broken probe must not stop people filling forms in.
            return null;
        }
    }

    /**
     * A SQL fragment for the portal catalogue: forms whose collection is closed
     * are not offered, but only when the operator chose 'stop_and_hide'.
     * Returns '' when nothing should be filtered, so callers can concatenate.
     *
     * ⚠️ NOT EXISTS rather than a LEFT JOIN with IS NULL: a form with no
     * collection at all must stay in the catalogue, and that is the common case.
     *
     * @param string $formsAlias  the alias the calling query gives the forms table, written in code
     */
    public static function portalCatalogueFilter(PDO $conn, string $formsAlias = 'f'): string
    {
        if (!self::collectionsAvailable($conn)) return '';
        if (self::closeEffect($conn) !== 'stop_and_hide') return '';
        return " AND NOT EXISTS (SELECT 1 FROM form_collections fc
                                  WHERE fc.id = {$formsAlias}.collection_id
                                    AND fc.closed_datetime IS NOT NULL)";
    }

    /**
     * Creates a form, or updates the current version of one, with its fields.
     *
     * Historical versions cannot be edited.
     *
     * @param array $in  id (absent = new), title, description, is_active, is_portal_visible, requires_approval, approver_id,
     *     submission_actions, collection_id, fields - on update only keys sent are changed
     */
    /**
     * Designing a form - including who approves it and what happens next - needs "Design
     * forms" once Forms → Settings → Access restricts it (#180 Part 3). Until then, as
     * before, module access is enough. TRAP: in the service as well as the endpoints, so
     * an API key acting as an analyst who may not design is refused too.
     */
    private static function guardDesign(PDO $conn, ActorContext $ctx): void
    {
        self::assertCanWrite($conn, $ctx);
        if (!analystMayAct($conn, $ctx->actorId, Cap::FORMS_DESIGN)) {
            throw new ServiceError('forbidden', 'forbidden', 'Designing forms needs the "Design forms" permission.');
        }
    }

    /**
     * Read only access (#180 Part 3), in the service as well as the endpoints, so an API
     * key acting as a read-only analyst cannot write either. Only an explicit Read only
     * refuses (see ChangesService::assertCanWrite()). Deciding a catalogue request is
     * identity, not a write, and does not come here.
     */
    private static function assertCanWrite(PDO $conn, ActorContext $ctx): void
    {
        if ($ctx->actorId > 0 && analystModuleLevel($conn, $ctx->actorId, 'forms') === 'read') {
            throw new ServiceError('forbidden', 'read_only', 'You have read-only access to Forms.');
        }
    }

    public static function saveForm(PDO $conn, ActorContext $ctx, array $in): array
    {
        self::guardDesign($conn, $ctx);
        if (!empty($in['id'])) {
            $formId  = (int)$in['id'];
            $current = self::loadFormRow($conn, $formId);      // 404 if gone
            self::requireLeaf($current);                       // 409 on frozen versions
            if (!array_diff_key($in, ['id' => true])) {
                throw new ServiceError('validation', 'missing_field', 'No fields to update.');
            }
            $title = array_key_exists('title', $in) ? trim((string)$in['title']) : $current['title'];
            if ($title === '') {
                throw new ServiceError('validation', 'invalid_field', "'title' cannot be empty.");
            }
            $fields = null;
            if (array_key_exists('fields', $in)) {
                if (!is_array($in['fields'])) {
                    throw new ServiceError('validation', 'invalid_field', "'fields' must be an array.");
                }
                $fields = self::validateFields($in['fields']);
            }

            $conn->beginTransaction();
            try {
                /* Collections join the same incremental rule: named only when
                   the schema has the column AND the caller sent it, so neither
                   an un-migrated database nor a collections-unaware adapter can
                   unpair a form as a side effect of saving something else.
                   ⚠️ Appended LAST, before the WHERE - the argument list below
                   is positional and inserting it anywhere else would write the
                   collection id into whichever column followed. */
                $collectionSet = (self::collectionsAvailable($conn) && array_key_exists('collection_id', $in))
                    ? ', collection_id = ?' : '';
                $conn->prepare(
                    "UPDATE forms SET title = ?, description = ?, is_active = ?, is_portal_visible = ?,
                            requires_approval = ?, approver_id = ?, submission_actions = ?,
                            modified_by = ?" . $collectionSet . ", modified_date = UTC_TIMESTAMP()
                     WHERE id = ?"
                )->execute([
                    $title,
                    array_key_exists('description', $in) ? trim((string)$in['description']) : $current['description'],
                    array_key_exists('is_active', $in) ? (int)(bool)$in['is_active'] : (int)$current['is_active'],
                    // Only touched when sent, so an adapter that knows nothing about
                    // the portal can never silently withdraw a catalogue form.
                    array_key_exists('is_portal_visible', $in)
                        ? (int)(bool)$in['is_portal_visible']
                        : (int)($current['is_portal_visible'] ?? 0),
                    // Catalogue-request approval (#928), same incremental rule.
                    array_key_exists('requires_approval', $in)
                        ? (int)(bool)$in['requires_approval']
                        : (int)($current['requires_approval'] ?? 0),
                    array_key_exists('approver_id', $in)
                        ? (($in['approver_id'] === null || $in['approver_id'] === '') ? null : (int)$in['approver_id'])
                        : ($current['approver_id'] ?? null),
                    // Same incremental rule again: only touched when sent, so an
                    // adapter that predates #95 (the REST API, an integration)
                    // cannot wipe a form's automation just by saving its title.
                    array_key_exists('submission_actions', $in)
                        ? self::encodeActionLists($in['submission_actions'])
                        : ($current['submission_actions'] ?? null),
                    $ctx->actorId,
                    // Matches $collectionSet above, and is absent when it is.
                    ...($collectionSet !== ''
                        ? [($in['collection_id'] === null || $in['collection_id'] === '') ? null : (int)$in['collection_id']]
                        : []),
                    $formId,
                ]);
                self::saveApprovalPolicy($conn, $formId, $in);   // GH #220
                if ($fields !== null) {
                    self::syncFields($conn, $formId, $fields);
                }
                $conn->commit();
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                throw $e;
            }
            return ['id' => $formId, 'created' => false];
        }

        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') {
            throw new ServiceError('validation', 'missing_field', "'title' is required.");
        }
        $fields = self::validateFields(is_array($in['fields'] ?? null) ? $in['fields'] : []);

        $conn->beginTransaction();
        try {
            $conn->prepare(
                "INSERT INTO forms (title, description, is_active, is_portal_visible, requires_approval, approver_id, submission_actions, created_by, modified_by, version_number, created_date, modified_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([
                $title,
                trim((string)($in['description'] ?? '')),
                isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1,
                // Fail closed: a new form is NOT offered to customers unless
                // someone deliberately says so.
                isset($in['is_portal_visible']) ? (int)(bool)$in['is_portal_visible'] : 0,
                isset($in['requires_approval']) ? (int)(bool)$in['requires_approval'] : 0,
                (isset($in['approver_id']) && $in['approver_id'] !== null && $in['approver_id'] !== '') ? (int)$in['approver_id'] : null,
                // Accepted on create as well as update: a designer that lets you
                // build the form and say what happens to it in one sitting would
                // otherwise lose the second half on the first Save.
                isset($in['submission_actions']) ? self::encodeActionLists($in['submission_actions']) : null,
                $ctx->actorId,
                $ctx->actorId,
            ]);
            $formId = (int)$conn->lastInsertId();
            self::saveApprovalPolicy($conn, $formId, $in);   // GH #220
            // Same path as an update, so a brand-new form's conditions get resolved
            // and stored by exactly the same code rather than a second copy of it.
            self::syncFields($conn, $formId, $fields);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        return ['id' => $formId, 'created' => true];
    }

    /**
     * Delete one version (leaf only) or the whole chain. Returns ['id','versions_deleted'].
     *
     * @param int $id  the form version to delete
     * @param bool $chain  true to delete every version of the form, false for this (leaf) version only
     */
    public static function deleteForm(PDO $conn, ActorContext $ctx, int $id, bool $chain = false): array
    {
        self::guardDesign($conn, $ctx);
        $current = self::loadFormRow($conn, $id);              // 404 if gone

        if ($chain) {
            $rootId = (int)$current['id'];
            $hops = 0;
            while ($hops < 500) {
                $stmt = $conn->prepare("SELECT parent_form_id FROM forms WHERE id = ?");
                $stmt->execute([$rootId]);
                $parent = $stmt->fetchColumn();
                if (!$parent) break;
                $rootId = (int)$parent;
                $hops++;
            }
            $ids   = [$rootId];
            $queue = [$rootId];
            while ($queue) {
                $place = implode(',', array_fill(0, count($queue), '?'));
                $stmt = $conn->prepare("SELECT id FROM forms WHERE parent_form_id IN ($place)");
                $stmt->execute($queue);
                $children = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                if (!$children) break;
                $ids   = array_merge($ids, $children);
                $queue = $children;
            }
        } else {
            if (((int)$current['child_count']) > 0) {
                throw new ServiceError('conflict', 'conflict', 'This version has newer versions built on it. Delete the whole chain with ?chain=true, or delete the current (leaf) version.');
            }
            $ids = [(int)$current['id']];
        }

        $place = implode(',', array_fill(0, count($ids), '?'));
        self::assertNotTaskEvidence($conn, "form_id IN ($place)", $ids);
        $conn->beginTransaction();
        try {
            $conn->prepare(
                "DELETE sd FROM form_submission_data sd
                 INNER JOIN form_submissions s ON sd.submission_id = s.id
                 WHERE s.form_id IN ($place)"
            )->execute($ids);
            $conn->prepare("DELETE FROM form_submissions WHERE form_id IN ($place)")->execute($ids);
            $conn->prepare("DELETE FROM form_fields WHERE form_id IN ($place)")->execute($ids);
            // Children before parents so fk_forms_parent never blocks.
            foreach (array_reverse($ids) as $fid) {
                $conn->prepare("DELETE FROM forms WHERE id = ?")->execute([$fid]);
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        return ['id' => $id, 'versions_deleted' => count($ids)];
    }

    // ======================================================================
    //  Versions
    // ======================================================================

    /**
     * Validate and encode the three action lists for storage.
     *
     * Explicit NULL stores NULL — "never configured", the state that keeps a
     * pre-#95 form behaving exactly as it did. Anything else is normalised to
     * the three known keys holding lists of {type, args}, so a malformed or
     * hostile payload cannot put arbitrary structure into the column that the
     * engine will later hand to an action handler.
     *
     * @param mixed $value  submission_actions as an array or JSON: {submitted, approved, rejected: [{type, args}]}; null or '' stores NULL
     */
    private static function encodeActionLists($value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            throw new ServiceError('validation', 'invalid_value', "'submission_actions' must be an object.");
        }

        $out = [];
        foreach (['submitted', 'approved', 'rejected'] as $key) {
            if (!isset($value[$key]) || !is_array($value[$key])) continue;
            $list = [];
            foreach ($value[$key] as $action) {
                if (!is_array($action)) continue;
                $type = trim((string)($action['type'] ?? ''));
                if ($type === '') continue;
                // Only actions the engine actually has a handler for. Storing an
                // unknown type would fail at run time, inside a form submission,
                // where the person who typed it will never see the error.
                if (!isset(WorkflowEngine::availableActions()[$type])) {
                    throw new ServiceError('validation', 'unknown_action', "Unknown action type: {$type}");
                }
                $list[] = ['type' => $type, 'args' => is_array($action['args'] ?? null) ? $action['args'] : []];
            }
            require_once __DIR__ . '/../webhook_delivery.php';
            $out[$key] = webhookEncryptActions($list);   // webhook url + secret at rest, as workflows do
        }
        return json_encode($out);
    }

    /**
     * A form's three action lists (#95): what to do when it is submitted,
     * approved or rejected.
     *
     * 🔑 NULL and [] mean DIFFERENT things and the difference is load-bearing.
     * NULL is "never configured" — the pre-#95 behaviour applies, which is what
     * lets this ship without a data migration: every form that existed before
     * keeps doing exactly what it did, including raising a ticket on approval
     * the hard-coded way. [] is "somebody opened the panel and chose nothing",
     * and must be obeyed as the deliberate instruction it is.
     *
     * A malformed value is treated as NULL rather than thrown: a form must stay
     * submittable even if its configuration is nonsense.
     *
     * @param int $formId  the form
     * @return array{submitted: ?array, approved: ?array, rejected: ?array}
     */
    public static function actionLists(PDO $conn, int $formId): array
    {
        $none = ['submitted' => null, 'approved' => null, 'rejected' => null];

        $stmt = $conn->prepare("SELECT submission_actions FROM forms WHERE id = ?");
        $stmt->execute([$formId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null || trim((string)$raw) === '') return $none;

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) return $none;

        foreach (array_keys($none) as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                $none[$key] = array_values($decoded[$key]);
            }
        }
        return $none;
    }

    /**
     * Fork the leaf into a new version. Returns ['id','version_number'].
     *
     * @param int $parentId  the current (leaf) version to copy
     */
    public static function createVersion(PDO $conn, ActorContext $ctx, int $parentId): array
    {
        self::guardDesign($conn, $ctx);
        if ($parentId <= 0) {
            throw new ServiceError('validation', 'missing_field', 'parent_form_id is required');
        }
        $src = self::loadFormRow($conn, $parentId);            // 404 if gone
        self::requireLeaf($src);                               // 409 on frozen versions

        $conn->beginTransaction();
        try {
            /* ⚠️ THE COLUMN LIST IS THE TRAP. Every per-form setting has to be
               named here or pressing Save deletes it on the new version — that is
               exactly how approval gating was lost before #95, and why
               collection_id had to be added in 2.2.0.

               The two optional columns are appended in a fixed order, each guarded
               by its own feature-detect, because a positional array that matches
               only one of the four possible shapes writes values into the wrong
               columns without complaining. */
            $optionalCols = [];
            if (self::collectionsAvailable($conn)) $optionalCols[] = 'collection_id';
            if (self::layoutAvailable($conn))      $optionalCols[] = 'layout';

            $colSql  = $optionalCols ? ', ' . implode(', ', $optionalCols) : '';
            $markSql = str_repeat(', ?', count($optionalCols));

            $conn->prepare(
                "INSERT INTO forms (title, description, is_active, is_portal_visible, requires_approval,
                                    approver_id, submission_actions, created_by, modified_by,
                                    parent_form_id, version_number{$colSql}, created_date, modified_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?{$markSql}, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([
                $src['title'],
                $src['description'],
                (int)$src['is_active'],
                // Carried to the new version DELIBERATELY. A new version is the
                // editable leaf and the catalogue lists leaves, so dropping this
                // would silently withdraw a published form from the portal the
                // moment someone edited it — a disappearance nobody would connect
                // to having pressed Save.
                (int)($src['is_portal_visible'] ?? 0),
                // Carried for the same reason, and more urgently: these were
                // MISSING here until #95. The catalogue lists leaves, so editing
                // an approval-gated form made the new leaf ungated — requests that
                // needed a manager's sign-off stopped needing one, silently, and
                // (because only the gated path raises a ticket) started behaving
                // differently in two ways at once. A gate that can be removed by
                // pressing Save is not a gate.
                (int)($src['requires_approval'] ?? 0),
                // NULL, not 0: approver_id is a real FK and "nobody assigned" is a
                // meaningful state the gate treats as unconfigured.
                isset($src['approver_id']) && $src['approver_id'] !== null ? (int)$src['approver_id'] : null,
                // The action lists travel with the version, like the questions do.
                // This is the trap that ate the approval gate: a per-form setting
                // left out of this INSERT is a setting that pressing Save deletes,
                // and here it would mean a form quietly stopped raising tickets.
                // NULL stays NULL — "never configured" must survive the copy, or
                // every new version would look deliberately configured as empty.
                $src['submission_actions'] ?? null,
                $ctx->actorId,
                $ctx->actorId,
                $parentId,
                (int)$src['version_number'] + 1,
                // Carried for the same reason as everything above it: the
                // catalogue lists leaves, so a new version that dropped this
                // would unpair the form from its collection the moment
                // somebody pressed Save, and the next submission would be
                // stamped with nothing. Spread rather than appended inline,
                // because the INSERT has two shapes and a positional array
                // that matches only one of them writes version_number into
                // collection_id without complaining.
                /* Spread in the SAME order the column list was built above, and
                   each under the same condition. Appending inline was what made
                   the old two-shape version fragile. */
                ...(self::collectionsAvailable($conn)
                    ? [isset($src['collection_id']) && $src['collection_id'] !== null ? (int)$src['collection_id'] : null]
                    : []),
                /* The layout travels with the version exactly as the questions do.
                   A new version that dropped it would throw away a form somebody
                   had laid out, the moment they pressed Save — and because a NULL
                   layout silently DERIVES a plausible one, the form would still
                   look fine. That is the worst shape of this bug: not an error,
                   just a design quietly replaced by a default. */
                ...(self::layoutAvailable($conn)
                    ? [isset($src['layout']) && $src['layout'] !== null ? (string)$src['layout'] : null]
                    : []),
            ]);
            $newId = (int)$conn->lastInsertId();
            // The group-approval policy travels with the version (GH #220) - the trap
            // above, again: a per-form setting left out of the copy is a setting that
            // pressing Save deletes, and here a team-approved form would fall back to
            // its named approver (or to no gate at all) the moment somebody edited it.
            self::copyApprovalPolicy($conn, $src, $newId);

            // Copied row by row rather than INSERT..SELECT because a condition stores
            // the form_fields.id it depends on: a bulk copy would leave the new
            // version's rules pointing at the OLD version's fields, so editing the
            // copy would change what the frozen original shows. Retired (soft-deleted)
            // fields are left behind — they exist to keep old answers readable on the
            // version they belong to, not to follow the form forward.
            // NOT $src — that already holds the source form's row, and this method
            // still reads $src['version_number'] after the copy is done.
            $srcStmt = $conn->prepare(
                "SELECT id, field_type, label, options, is_required, sort_order, config
                   FROM form_fields
                  WHERE form_id = ? AND is_deleted = 0
                  ORDER BY sort_order, id"
            );
            $srcStmt->execute([$parentId]);
            $srcFields = $srcStmt->fetchAll(PDO::FETCH_ASSOC);

            $ins = $conn->prepare(
                "INSERT INTO form_fields (form_id, field_type, label, options, is_required, sort_order, config)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $idMap = [];
            foreach ($srcFields as $i => $f) {
                $ins->execute([$newId, $f['field_type'], $f['label'], $f['options'], $f['is_required'], $i, $f['config']]);
                $idMap[(int)$f['id']] = (int)$conn->lastInsertId();
            }

            $updCfg = $conn->prepare("UPDATE form_fields SET config = ? WHERE id = ?");
            foreach ($srcFields as $f) {
                if (empty($f['config'])) continue;
                $config = json_decode((string)$f['config'], true);
                if (!is_array($config) || !isset($config['visible_if']['rules'])) continue;
                foreach ($config['visible_if']['rules'] as $r => $rule) {
                    $oldRef = (int)($rule['field'] ?? 0);
                    $config['visible_if']['rules'][$r]['field'] = $idMap[$oldRef] ?? $oldRef;
                }
                $updCfg->execute([json_encode($config), $idMap[(int)$f['id']]]);
            }

            /* 🔴 THE LAYOUT REFERENCES FIELDS BY ID, and every field above was
               just given a NEW one. Copying the layout verbatim — which the
               INSERT did — leaves every cell pointing at the previous version's
               fields, and because an unreadable cell is dropped and unplaced
               fields are appended, the form would come back looking laid out
               while actually being in default order. Silent, and indistinguishable
               from "the designer was never used". Remapped through the SAME id map
               the conditional rules use, and for the same reason. */
            if (self::layoutAvailable($conn) && !empty($src['layout'])) {
                $conn->prepare("UPDATE forms SET layout = ? WHERE id = ?")
                     ->execute([self::remapLayoutFields((string)$src['layout'], $idMap), $newId]);
            }

            /* 🔴 THE AUDIENCE COMES TOO (GH #145). It lives in its own table
               rather than a column, so the INSERT above cannot carry it and it
               is easy to forget — and forgetting is not a neutral loss here.
               An audience that did not come forward means NO rows, and no rows
               means EVERYONE: a form restricted to the HR group would be
               republished to every customer in the catalogue the moment
               somebody pressed "Save as new version". A restriction that a
               routine edit silently removes is not a restriction.
               ⚠️ No id remapping needed, unlike the layout — these point at
               people groups, which the copy does not touch. */
            if (self::audiencesAvailable($conn)) {
                $conn->prepare(
                    "INSERT INTO form_audiences (form_id, principal_type, principal_id)
                     SELECT ?, principal_type, principal_id FROM form_audiences WHERE form_id = ?"
                )->execute([$newId, $parentId]);
            }

            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        return ['id' => $newId, 'version_number' => (int)$src['version_number'] + 1];
    }

    // ======================================================================
    //  Submissions
    // ======================================================================

    /**
     * Validate + record a submission, then dispatch form.submitted. $data is a
     * field_id => value map. Returns the submission id.
     *
     * @param int $formId  the form being submitted
     * @param array $data  the answers, [form_fields id => value]
     * @param ?int $portalUserId  a REQUESTER (users.id) submitting through the
     *   self-service request catalogue, or null for the analyst paths.
     *
     *   It is a separate argument rather than $ctx->actorId because the two are
     *   DIFFERENT ID SPACES. `submitted_by` has no foreign key and every reader
     *   LEFT JOINs it to `analysts`, so writing a users.id there would silently
     *   attribute a customer's request to whichever analyst happened to share
     *   the number. They go in different columns and exactly one is set.
     * @param array $link  GH #190, a submission made inside a task occurrence (TaskForms::submit()):
     *   task_id, tenant_id, client_reference, and `within` - a callable(PDO, int $submissionId) run
     *   INSIDE this transaction, which links the occurrence and may throw to roll the whole
     *   submission back (a lost race). Empty for every other caller, which behave as before.
     */
    public static function submitForm(PDO $conn, ActorContext $ctx, int $formId, array $data, ?int $portalUserId = null, array $link = []): int
    {
        if ($portalUserId === null) {
            self::assertCanWrite($conn, $ctx);   // an analyst filling in a form; portal users never come here
        }
        $form = self::loadFormRow($conn, $formId);             // 404 if gone
        if (!(int)$form['is_active']) {
            throw new ServiceError('conflict', 'conflict', 'This form is inactive and cannot accept submissions.');
        }

        // A requester may only submit a form actually offered in the catalogue.
        // Checked HERE, not just in the adapter, so the rule holds however this
        // is reached — knowing a hidden form's id must not be enough.
        if ($portalUserId !== null && !(int)($form['is_portal_visible'] ?? 0)) {
            throw new ServiceError('not_found', 'not_found', 'Form not found.');
        }

        /* 🔴 And the AUDIENCE (GH #145). Same reasoning one line up, and the
           reason this is here rather than only in the catalogue query: the list
           hiding a card has never been a check. Somebody who was in the group
           yesterday, or who has a colleague's link, reaches this path with a
           perfectly valid form id.
           ⚠️ 'not_found', not 'forbidden' — a refusal that distinguishes "not
           for you" from "does not exist" tells a customer which forms exist
           that they are not allowed to see. */
        if ($portalUserId !== null && !self::portalCanUseForm($conn, $formId, $portalUserId)) {
            throw new ServiceError('not_found', 'not_found', 'Form not found.');
        }

        /* The form's collection has been closed, and this operator's setting
           says closing stops submissions. Same reasoning as the guard above:
           checked here so a bookmarked URL cannot walk around it.
           🔑 Independent of is_active, which stays the form's OWN switch -
           two separate reasons a form may be shut, and reopening the
           collection restores exactly what was there because closing never
           wrote to the form in the first place. */
        $closedCollection = self::submissionsBlockedBy($conn, $formId);
        if ($closedCollection !== null) {
            throw new ServiceError('conflict', 'collection_closed',
                'This form is part of "' . $closedCollection . '", which has closed, so it is no longer accepting submissions.');
        }

        // Ordered, and without retired fields: a soft-deleted question is no longer
        // asked, so it can neither be answered nor be required. Order matters because
        // conditions are evaluated in it (a rule may only look backwards).
        $stmt = $conn->prepare(
            "SELECT id, label, field_type, is_required, options, config, sort_order
               FROM form_fields
              WHERE form_id = ? AND is_deleted = 0
              ORDER BY sort_order, id"
        );
        $stmt->execute([$formId]);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $fieldsById = [];
        foreach ($fields as $f) {
            $fieldsById[(int)$f['id']] = $f;
        }

        // Unknown field ids are a 422 (the UI inserts them blindly → FK error).
        foreach (array_keys($data) as $fieldId) {
            if (!isset($fieldsById[(int)$fieldId])) {
                throw new ServiceError('validation', 'invalid_field', "Unknown field id for this form: {$fieldId}");
            }
            if (!self::isAnswerable($fieldsById[(int)$fieldId]['field_type'])) {
                throw new ServiceError('validation', 'invalid_field', "Field {$fieldId} is a section heading and takes no answer.");
            }
        }

        // Normalise values (bools and arrays accepted natively).
        $normalised = [];
        foreach ($data as $fieldId => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            if (is_array($value)) {
                /* A grid's rows are a list of OBJECTS keyed by column id, so
                   array_values would be wrong for the rows themselves but is
                   right for the list — json_encode preserves each row's map. */
                $value = json_encode(array_values($value));
            }
            $normalised[(int)$fieldId] = (string)$value;
        }

        // Which questions were actually ASKED, given the answers given. Re-derived here
        // rather than trusted from the client, because "required" has to mean something
        // even when the browser never ran our JS: without this, hiding a required field
        // client-side would still 422 on submit, and a crafted post could skip a
        // required question by pretending it was hidden.
        $visible = formLogicVisibility($fields, $normalised);

        // An answer to a question that was never shown is dropped, so what we store is
        // what the person was actually asked.
        foreach (array_keys($normalised) as $fieldId) {
            if (empty($visible[$fieldId])) {
                unset($normalised[$fieldId]);
            }
        }

        // Per-type required + format validation.
        foreach ($fields as $field) {
            $fid  = (int)$field['id'];
            $val  = array_key_exists($fid, $normalised) ? $normalised[$fid] : '';
            $type = $field['field_type'];

            // Headings collect nothing; hidden questions were never asked.
            if (!self::isAnswerable($type) || empty($visible[$fid])) {
                continue;
            }

            /* ---- A grid's answer -----------------------------------------
               Validated against the column definitions as they are NOW, which
               is right: somebody is filling the form in now. A submission made
               earlier keeps whatever it stored, and readers label it from the
               column list including retired ones. */
            if ($type === 'grid') {
                $rows = self::gridRows($val);
                $cols = self::gridLiveColumns($field);

                if (count($rows) > self::GRID_MAX_ROWS) {
                    throw new ServiceError('validation', 'invalid_field',
                        "'{$field['label']}' has more than " . self::GRID_MAX_ROWS . ' rows.');
                }

                /* A row where every cell is blank is somebody pressing "add"
                   and changing their mind. Dropped rather than refused — and
                   dropped BEFORE the required check, or an empty trailing row
                   would make a required column fail. */
                $kept = [];
                foreach ($rows as $row) {
                    $any = false;
                    foreach ($row as $v) {
                        if (is_array($v) ? !empty($v) : trim((string)$v) !== '') { $any = true; break; }
                    }
                    if ($any) $kept[] = $row;
                }

                foreach ($kept as $rIdx => $row) {
                    $human = $rIdx + 1;
                    foreach ($row as $cid => $cell) {
                        if (!isset($cols[(int)$cid])) {
                            throw new ServiceError('validation', 'invalid_field',
                                "'{$field['label']}' row {$human}: no column {$cid} on this grid.");
                        }
                    }
                    foreach ($cols as $cid => $col) {
                        $cell = $row[$cid] ?? ($row[(string)$cid] ?? '');
                        $str  = is_array($cell) ? implode(', ', $cell) : trim((string)$cell);

                        if (!empty($col['required'])) {
                            $blank = ($col['type'] === 'checkbox')
                                ? ($str === '' || $str === '0')
                                : ($str === '');
                            if ($blank) {
                                throw new ServiceError('validation', 'invalid_field',
                                    "'{$field['label']}' row {$human}: '{$col['label']}' is required.");
                            }
                        }
                        if ($str === '') continue;

                        if ($col['type'] === 'number' && !is_numeric($str)) {
                            throw new ServiceError('validation', 'invalid_field',
                                "'{$field['label']}' row {$human}: '{$col['label']}' must be a number.");
                        }
                        /* A narrowed dropdown has never been a check — the list
                           is re-tested here, exactly as it is for a top-level
                           dropdown field. */
                        if (in_array($col['type'], self::GRID_CELL_TYPES_WITH_OPTIONS, true)) {
                            $allowed = $col['options'] ?? [];
                            if ($allowed && !in_array($str, $allowed, true)) {
                                throw new ServiceError('validation', 'invalid_field',
                                    "'{$field['label']}' row {$human}: '{$str}' is not one of the choices for '{$col['label']}'.");
                            }
                        }
                    }
                }

                // Store the tidied table, so a blank row never reaches the record.
                $normalised[$fid] = json_encode(array_values($kept));

                if ($field['is_required'] && !$kept) {
                    throw new ServiceError('validation', 'missing_field',
                        "'{$field['label']}' needs at least one row.");
                }
                continue;   // the generic required/format checks do not apply
            }
            if ($field['is_required']) {
                $isEmpty = false;
                if ($val === '' || $val === null) {
                    $isEmpty = true;
                } elseif ($type === 'checkbox' && (string)$val === '0') {
                    $isEmpty = true;
                } elseif ($type === 'checkboxes') {
                    $decoded = json_decode((string)$val, true);
                    $isEmpty = !is_array($decoded) || count($decoded) === 0;
                }
                if ($isEmpty) {
                    throw new ServiceError('validation', 'missing_field', '"' . $field['label'] . '" is required.');
                }
            }
            if ($val !== '' && $val !== null) {
                if ($type === 'email' && !filter_var((string)$val, FILTER_VALIDATE_EMAIL)) {
                    throw new ServiceError('validation', 'invalid_field', '"' . $field['label'] . '" must be a valid email address.');
                }
                if ($type === 'number' && !is_numeric((string)$val)) {
                    throw new ServiceError('validation', 'invalid_field', '"' . $field['label'] . '" must be a number.');
                }

                // A date/time answer must match the shape its own mode asks for. The
                // browser's date input already produces exactly this, so a mismatch means
                // either an unsupported browser's text fallback or a hand-made request.
                if ($type === 'datetime') {
                    $mode = self::dateModeOf($field);
                    if (!preg_match(self::DATE_MODE_PATTERNS[$mode], (string)$val)) {
                        $expected = ['date' => 'a date', 'time' => 'a time', 'datetime' => 'a date and time'][$mode];
                        throw new ServiceError('validation', 'invalid_field',
                            '"' . $field['label'] . '" must be ' . $expected . '.');
                    }
                    // ⚠️ NOT converted to UTC, and deliberately so. A form answer is a
                    // NAIVE local value: "needed by 14 August" means the 14th to whoever
                    // typed it and to whoever reads it. Storing it as an instant and
                    // rendering it in the reader's timezone would show an analyst in
                    // another zone the 13th. Stored, exported and displayed verbatim.
                }

                // A lookup answer is {"id":123,"label":"LT-001"} — see the block
                // above LOOKUP_SOURCES for why both halves are stored.
                if ($type === 'lookup') {
                    $decoded = json_decode((string)$val, true);
                    $lid     = is_array($decoded) ? (int)($decoded['id'] ?? 0) : 0;
                    $llabel  = is_array($decoded) ? trim((string)($decoded['label'] ?? '')) : '';
                    if ($lid <= 0 || $llabel === '') {
                        throw new ServiceError('validation', 'invalid_field',
                            '"' . $field['label'] . '" must be chosen from the list.');
                    }
                    $source = self::lookupSourceOf($field);
                    if ($source === null) {
                        throw new ServiceError('validation', 'invalid_field',
                            '"' . $field['label'] . '" has no source configured.');
                    }
                    // ⚠️ THE ANTI-TAMPER CHECK. This is the dropdown rule below,
                    // generalised to a list built at answer time: the posted id
                    // must be a record this submitter was allowed to see. Without
                    // it, a crafted request could name another company's asset
                    // and it would appear — resolved and labelled — on a
                    // submission an analyst reads and believes.
                    // TRAP: a portal context's companyScope is null, which means
                    // "every company", so a requester's scope has to come from
                    // the requester. The search's two portal gates are repeated
                    // here because a crafted post never goes through the search.
                    if ($portalUserId !== null && !self::lookupPortalAllowed($field)) {
                        throw new ServiceError('validation', 'invalid_field',
                            '"' . $field['label'] . '" is not something you can choose.');
                    }
                    $scope = $portalUserId !== null ? self::lookupPortalScope($conn, $portalUserId) : $ctx->companyScope;
                    $recordLabel = self::lookupValueAllowed($conn, $source, $lid, $scope)
                        ? self::lookupRecordLabel($conn, $source, $lid)
                        : null;
                    if ($recordLabel === null) {
                        throw new ServiceError('validation', 'invalid_field',
                            '"' . $field['label'] . '" is not something you can choose.');
                    }
                    // The label is what an approver reads, so it is the record's
                    // own and never the one that was posted.
                    $normalised[$fid] = json_encode(['id' => $lid, 'label' => $recordLabel], JSON_UNESCAPED_UNICODE);
                }

                // A choice field must be answered with one of ITS OWN choices.
                // This was never checked: the value was whatever the client
                // posted, so a select could carry arbitrary text straight into
                // the stored answers. Harmless-ish while only analysts could
                // reach it; not once customers can.
                if (in_array($type, ['dropdown', 'radio', 'checkboxes'], true)) {
                    $options = json_decode((string)($field['options'] ?? '[]'), true);
                    if (is_array($options) && $options) {
                        $options = array_map('strval', $options);
                        $chosen  = ($type === 'checkboxes')
                            ? (json_decode((string)$val, true) ?: [])
                            : [(string)$val];
                        foreach ($chosen as $one) {
                            if (!in_array((string)$one, $options, true)) {
                                throw new ServiceError('validation', 'invalid_field',
                                    '"' . $field['label'] . '" has an option that is not on its list.');
                            }
                        }
                    }
                }
            }
        }

        // Catalogue-request approval (#928): gate a PORTAL submission behind the
        // form's designated approver, if one is configured. Only portal submissions
        // are gated — the feature auto-raises a ticket for the requester, and an
        // analyst filling a form internally has no requester to raise one for. A form
        // flagged requires_approval but with no approver is treated as unconfigured so
        // it can never strand a request nobody can clear.
        //
        // GH #220: one rule, catalogueApprovalGate(), for both ways of deciding - a
        // named person or a team - and it returns what to SNAPSHOT onto the
        // submission, so a later edit of the form never reroutes a waiting request.
        require_once __DIR__ . '/../catalogue_approvals.php';
        $gate = catalogueApprovalGate($form, $portalUserId);
        $approvalStatus = $gate !== null ? 'pending' : 'not_required';
        $teamColumns = catalogueTeamApprovalAvailable($conn);

        $conn->beginTransaction();
        try {
            // Exactly one submitter column is populated — see the $portalUserId
            // note on this method.
            /* The collection this submission belongs to, SNAPSHOTTED here and
               never read live again. Re-pairing the form afterwards must not
               rewrite what last year's responses were part of - the same rule
               as approver_id above, and the reason the column exists at all.
               Guarded because an install that has not run DB Verification yet
               has neither column, and a submission must still go through. */
            $hasCollections = self::collectionsAvailable($conn);
            $collectionId = $hasCollections ? ($form['collection_id'] ?? null) : null;
            $collectionId = ($collectionId !== null && $collectionId !== '') ? (int)$collectionId : null;

            $cols = 'form_id, submitted_by, submitted_by_user_id, submitted_date, approval_status, approver_id';
            $vals = '?, ?, ?, UTC_TIMESTAMP(), ?, ?';
            $args = [
                $formId,
                $portalUserId !== null ? null : $ctx->actorId,
                $portalUserId,
                $approvalStatus,
                $gate['approver_id'] ?? null,
            ];
            // The group-approval snapshot (GH #220) - only once Database Verification
            // has added the columns; before that every form is named-person only.
            if ($teamColumns) {
                $cols .= ', approval_team_id, approvals_required, rejections_required, approval_exclude_requester';
                $vals .= ', ?, ?, ?, ?';
                array_push($args, $gate['approval_team_id'] ?? null, $gate['approvals_required'] ?? null,
                    $gate['rejections_required'] ?? null, (int)($gate['approval_exclude_requester'] ?? 0));
            }
            if ($hasCollections) {
                $cols .= ', collection_id';
                $vals .= ', ?';
                $args[] = $collectionId;
            }
            if (!empty($link['task_id'])) {
                $cols .= ', task_id, tenant_id, client_reference';
                $vals .= ', ?, ?, ?';
                array_push($args, (int)$link['task_id'], $link['tenant_id'] ?? null, $link['client_reference'] ?? null);
            }
            $conn->prepare("INSERT INTO form_submissions ($cols) VALUES ($vals)")->execute($args);
            $submissionId = (int)$conn->lastInsertId();

            $ins = $conn->prepare("INSERT INTO form_submission_data (submission_id, field_id, field_value) VALUES (?, ?, ?)");
            foreach ($normalised as $fieldId => $value) {
                $ins->execute([$submissionId, $fieldId, $value]);
            }
            // GH #190: the occurrence's link, in this same transaction - so a submission
            // can never exist half-linked, and a lost race undoes the submission too.
            if (isset($link['within']) && is_callable($link['within'])) {
                ($link['within'])($conn, $submissionId);
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }

        // Workflow dispatch — label-keyed answers + first email answer, fired after
        // commit and swallowed on error (never breaks a submission).
        try {
            $submissionFields = [];
            $submissionEmail  = '';
            foreach ($normalised as $fieldId => $value) {
                if (!isset($fieldsById[$fieldId])) continue;
                $label = $fieldsById[$fieldId]['label'];
                /* ⚠️ A grid's rows are OBJECTS, so the generic implode below would
                   render them as "Array, Array" and emit a PHP warning. It gets
                   readable text instead — one line per row, cells labelled. */
                if ($fieldsById[$fieldId]['field_type'] === 'grid') {
                    $flat = self::gridToText($fieldsById[$fieldId], $value);
                } else {
                    $decoded = json_decode($value, true);
                    $flat = is_array($decoded) ? implode(', ', $decoded) : $value;
                }
                $submissionFields[$label] = $flat;
                if ($submissionEmail === '' && $fieldsById[$fieldId]['field_type'] === 'email' && $flat !== '') {
                    $submissionEmail = $flat;
                }
            }
            $payload = [
                'form' => [
                    'id'   => $formId,
                    'name' => $form['title'],
                ],
                'submission' => [
                    'id'     => $submissionId,
                    'email'  => $submissionEmail,
                    'fields' => $submissionFields,
                ],
            ];
            // GH #190: made inside a task occurrence - say which, for a rule that cares.
            if (!empty($link['task_id'])) $payload['task'] = ['id' => (int)$link['task_id']];
            // A gated request must NOT fire form.submitted: an admin's create-ticket
            // rule on that event would jump the approval gate and raise the ticket
            // anyway. It fires catalogue_request.submitted instead — the hook to
            // notify the approver that something is waiting for them.
            if ($gate !== null) {
                // Who decides: the named approver, or the team (GH #220) - so a rule
                // can tell whoever it is that something is waiting for them.
                if ($gate['approver_id'] !== null) $payload['approver'] = ['id' => $gate['approver_id']];
                if ($gate['approval_team_id'] !== null) {
                    $payload['approval_team'] = ['id' => $gate['approval_team_id'], 'approvals_required' => $gate['approvals_required']];
                }
                WorkflowEngine::dispatch('catalogue_request.submitted', $payload);
            } else {
                WorkflowEngine::dispatch('form.submitted', $payload);
            }
        /* Throwable, not Exception: this runs AFTER the commit, so anything
           escaping here turns a submission that WAS saved into a 500 the
           person then repeats. A TypeError in a workflow action is not an
           Exception and used to walk straight out of here. */
        } catch (Throwable $wfEx) {
            error_log('Workflow dispatch error in form submission: ' . $wfEx->getMessage());
        }

        // The form's OWN "when submitted" list (#95) — configured in the form
        // designer rather than in Workflows, and run here so the UI, the portal
        // and the REST API all behave identically without any of them knowing
        // this exists.
        //
        // A gated request runs NOTHING here, for the same reason it fires a
        // different event: its actions belong to the approval decision, and
        // raising a ticket at submission time would step straight over the gate.
        if ($gate === null) {
            try {
                $lists = self::actionLists($conn, $formId);
                if (!empty($lists['submitted'])) {
                    WorkflowEngine::runActionList(
                        'Form: ' . $form['title'] . ' — when submitted',
                        'form.submitted',
                        $lists['submitted'],
                        $payload
                    );
                }
            /* Throwable for the same reason as the dispatch above: post-commit,
               so an Error escaping here loses a submission that is already in
               the database. */
            } catch (Throwable $e) {
                error_log('Form action list error on submission: ' . $e->getMessage());
            }
        }

        return $submissionId;
    }

    /**
     * Delete a submission (+ its data). $formId scopes the 404 when supplied. Returns the id.
     *
     * @param int $submissionId  the form_submissions row to delete
     * @param ?int $formId  the form it must belong to, or null for any
     */
    public static function deleteSubmission(PDO $conn, ActorContext $ctx, int $submissionId, ?int $formId = null): int
    {
        self::assertCanWrite($conn, $ctx);
        if ($formId !== null) {
            $stmt = $conn->prepare("SELECT id FROM form_submissions WHERE id = ? AND form_id = ?");
            $stmt->execute([$submissionId, $formId]);
            if (!$stmt->fetchColumn()) {
                throw new ServiceError('not_found', 'not_found', 'Submission not found on this form.');
            }
        } else {
            $stmt = $conn->prepare("SELECT id FROM form_submissions WHERE id = ?");
            $stmt->execute([$submissionId]);
            if (!$stmt->fetchColumn()) {
                throw new ServiceError('not_found', 'not_found', 'Submission not found.');
            }
        }
        self::assertNotTaskEvidence($conn, 'id = ?', [$submissionId]);
        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM form_submission_data WHERE submission_id = ?")->execute([$submissionId]);
            $conn->prepare("DELETE FROM form_submissions WHERE id = ?")->execute([$submissionId]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        return $submissionId;
    }

    /**
     * Refuse to delete a submission that is a task occurrence's evidence (GH #190), or a
     * form that one was made on or that a task still needs.
     *
     * 🔴 Deleting it would delete the answers behind a completed check, and deleting the
     * form would take the field labels with it. A form can be made inactive instead.
     * Quiet on an install that has not run Database Verification - nothing can be linked yet.
     *
     * @param string $where  a condition on form_submissions (e.g. "id = ?" or "form_id IN (...)")
     * @param array $args  its parameters
     */
    private static function assertNotTaskEvidence(PDO $conn, string $where, array $args): void
    {
        try {
            $q = $conn->prepare("SELECT COUNT(*) FROM form_submissions WHERE task_id IS NOT NULL AND $where");
            $q->execute($args);
            $linked = (int)$q->fetchColumn();
            $needed = 0;
            if (str_starts_with($where, 'form_id')) {
                $r = $conn->prepare("SELECT COUNT(*) FROM task_forms WHERE $where");
                $r->execute($args);
                $needed = (int)$r->fetchColumn();
            }
        } catch (Throwable) {
            return;
        }
        if ($linked > 0) {
            throw new ServiceError('conflict', 'task_evidence',
                $linked === 1 && str_starts_with($where, 'id = ?')
                    ? 'This submission is the record of work done on a task, so it cannot be deleted. It stays with that task\'s history and its evidence report.'
                    : 'Submissions of this form are the record of work on ' . $linked . ' task occurrence(s), so it cannot be deleted. Make it inactive instead.');
        }
        if ($needed > 0) {
            throw new ServiceError('conflict', 'task_evidence',
                $needed . ' task(s) need this form. Take it off those tasks, or make the form inactive instead.');
        }
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /**
     * Load a form row (with child_count for the leaf check), or throw 404.
     *
     * @param int $id  the form to load
     */
    /**
     * Store a form's group-approval policy (GH #220), each part only when sent and
     * only once Database Verification has added the columns - so an adapter that
     * knows nothing about teams (the REST API, an older page) cannot clear one by
     * saving something else, and an un-migrated install saves exactly as before.
     *
     * approval_team_id set = a team decides, approvals_required different members;
     * null = the named approver (approver_id), unchanged. The two are kept apart, so
     * switching back to a named person restores the person who was chosen before.
     *
     * @param array $in  the save payload: approval_team_id, approvals_required, rejections_required, approval_exclude_requester
     */
    private static function saveApprovalPolicy(PDO $conn, int $formId, array $in): void
    {
        require_once __DIR__ . '/../catalogue_approvals.php';
        if (!catalogueTeamApprovalAvailable($conn)) return;
        $set = [];
        $args = [];
        if (array_key_exists('approval_team_id', $in)) {
            $teamId = ($in['approval_team_id'] === null || $in['approval_team_id'] === '') ? null : (int)$in['approval_team_id'];
            if ($teamId !== null) {
                $t = $conn->prepare('SELECT 1 FROM teams WHERE id = ? AND is_active = 1');
                $t->execute([$teamId]);
                if (!$t->fetchColumn()) throw new ServiceError('validation', 'invalid_field', 'That team was not found.');
            }
            $set[] = 'approval_team_id = ?';
            $args[] = $teamId;
        }
        foreach (['approvals_required', 'rejections_required'] as $k) {
            if (!array_key_exists($k, $in)) continue;
            $n = (int)$in[$k];
            if ($n < 1 || $n > 20) {
                throw new ServiceError('validation', 'invalid_field', "'$k' must be between 1 and 20.");
            }
            $set[] = "$k = ?";
            $args[] = $n;
        }
        if (array_key_exists('approval_exclude_requester', $in)) {
            $set[] = 'approval_exclude_requester = ?';
            $args[] = (int)(bool)$in['approval_exclude_requester'];
        }
        if (!$set) return;
        $args[] = $formId;
        $conn->prepare('UPDATE forms SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    }

    /**
     * Carry the group-approval policy to a new version of the form (GH #220).
     *
     * @param array $src  the version being copied (loadFormRow())
     * @param int $newId  the new version's id
     */
    private static function copyApprovalPolicy(PDO $conn, array $src, int $newId): void
    {
        require_once __DIR__ . '/../catalogue_approvals.php';
        if (!catalogueTeamApprovalAvailable($conn) || !array_key_exists('approval_team_id', $src)) return;
        $conn->prepare(
            'UPDATE forms SET approval_team_id = ?, approvals_required = ?, rejections_required = ?, approval_exclude_requester = ? WHERE id = ?'
        )->execute([
            $src['approval_team_id'] !== null ? (int)$src['approval_team_id'] : null,
            max(1, (int)($src['approvals_required'] ?? 1)),
            max(1, (int)($src['rejections_required'] ?? 1)),
            (int)($src['approval_exclude_requester'] ?? 0),
            $newId,
        ]);
    }

    private static function loadFormRow(PDO $conn, int $id): array
    {
        $stmt = $conn->prepare(
            "SELECT f.*, (SELECT COUNT(*) FROM forms ch WHERE ch.parent_form_id = f.id) AS child_count
             FROM forms f WHERE f.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Form not found.');
        }
        return $row;
    }

    /**
     * 409 unless the form is the chain leaf (the current editable version).
     *
     * @param array $formRow  the form row from loadFormRow(), with child_count
     */
    private static function requireLeaf(array $formRow): void
    {
        if (((int)$formRow['child_count']) > 0) {
            throw new ServiceError('conflict', 'conflict', 'This is a frozen historical version. Use the current (leaf) version of the chain.');
        }
    }

    /**
     * Validate an incoming fields array — 422s where the raw UI silently drops
     * or blindly stores. Returns rows ready for the id-based sync.
     *
     * Each row may carry an `id`: the form_fields.id it is editing. Absent (or null)
     * means a brand-new field. That id is what keeps a respondent's answer attached
     * to the question they actually answered when the builder reorders things.
     *
     * A conditional rule's `field` may be either an existing form_fields.id or the
     * string "idx:N", meaning "the field at position N of THIS payload" — needed
     * because a rule can point at a question that is itself being created by this
     * same save and has no id yet. syncFields() resolves "idx:N" once the ids exist.
     *
     * @param array $fields  the payload's question list, each {id?, label, field_type, options, is_required, config}
     */
    private static function validateFields(array $fields): array
    {
        // Position of each already-saved field in this payload, so a rule can be
        // checked against the order the user is actually looking at.
        $indexById = [];
        foreach ($fields as $i => $field) {
            if (is_array($field) && !empty($field['id'])) {
                $indexById[(int)$field['id']] = $i;
            }
        }

        $out = [];
        foreach ($fields as $i => $field) {
            if (!is_array($field)) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}] must be an object.");
            }
            $label = trim((string)($field['label'] ?? ''));
            if ($label === '') {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}] needs a non-empty 'label'.");
            }
            $type = (string)($field['field_type'] ?? 'text');
            if (!in_array($type, self::FIELD_TYPES, true)) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: unknown field_type '{$type}'. One of: " . implode(', ', self::FIELD_TYPES) . '.');
            }
            $options = $field['options'] ?? null;
            if (is_array($options)) {
                $options = json_encode(array_values($options));
            } elseif ($options !== null && !is_string($options)) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: 'options' must be an array.");
            }

            /* A presentational item collects nothing, so "required" would be a
               promise we could never keep — reject it rather than store a flag
               the renderers ignore and a submit check would then enforce against
               an answer that can never be given.
               🔑 Asked of the LIST, not of 'section' by name, so a block added
               later cannot arrive here still able to be marked required. */
            $isRequired = (int)(bool)($field['is_required'] ?? false);
            if (!self::isAnswerable($type)) {
                if ($isRequired) {
                    throw new ServiceError('validation', 'invalid_field',
                        "fields[{$i}]: a '{$type}' is presentational and cannot be required.");
                }
                $options = null;
            }

            $out[] = [
                'id'          => !empty($field['id']) ? (int)$field['id'] : null,
                'field_type'  => $type,
                'label'       => $label,
                'options'     => $options,
                'is_required' => $isRequired,
                'config'      => self::validateFieldConfig($field['config'] ?? null, $i, $fields, $indexById, $type),
            ];
        }
        return $out;
    }

    /**
     * Validate one field's `config` JSON (today: the conditional-visibility rule).
     * Returns the config as an array, or null for "no settings" — which is what every
     * pre-existing field has, and why upgrading changes nothing about how a form looks.
     *
     * The important rule enforced here: a condition may only reference an EARLIER
     * answerable field. That single constraint is what makes circular conditions
     * (A shows when B is set, B shows when A is set) structurally impossible, so
     * neither evaluator ever has to detect a cycle at render time.
     */
    /**
     * A grid's columns, as stored. Includes RETIRED ones, because a submission
     * made while a column existed still holds its answers and a reader has to
     * be able to label them.
     *
     * @return array<int,array> keyed by column id
     */
    /**
     * An option list, from whatever shape it is stored in.
     *
     * 🔑 Mirrors FormLogic.parseOptions() exactly: options are a JSON ARRAY,
     * not a newline-separated string. Guessing the other way here would have
     * split "A, B" into one option and shown it as two on screen — the two
     * halves of one rule disagreeing, which is the failure this codebase keeps
     * writing warnings about.
     *
     * @param mixed $raw  the options as an array or a JSON array string
     */
    private static function parseOptionList($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                fn($v) => trim((string)$v),
                $raw
            ), fn($v) => $v !== ''));
        }
        if (!is_string($raw) || $raw === '') return [];
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) return [];
        return array_values(array_filter(array_map(
            fn($v) => trim((string)$v),
            $parsed
        ), fn($v) => $v !== ''));
    }
    /**
     * A grid field's column definitions from its config, keyed by column id.
     *
     * @param array $field  the grid field; its config (array or JSON) holds 'columns'
     */
    public static function gridColumns(array $field): array
    {
        $cfg = $field['config'] ?? null;
        if (is_string($cfg)) $cfg = json_decode($cfg, true);
        $cols = (is_array($cfg) && isset($cfg['columns']) && is_array($cfg['columns'])) ? $cfg['columns'] : [];

        $out = [];
        foreach ($cols as $c) {
            if (!is_array($c) || !isset($c['id'])) continue;
            $out[(int)$c['id']] = $c;
        }
        return $out;
    }

    /**
     * The columns a NEW row may be filled in against — retired ones excluded.
     *
     * @param array $field  the grid field; its config (array or JSON) holds 'columns'
     */
    public static function gridLiveColumns(array $field): array
    {
        return array_filter(self::gridColumns($field), fn($c) => empty($c['deleted']));
    }

    /**
     * A grid's answer, decoded. Always a list of rows, each a map of
     * column id => value; anything unreadable becomes an empty table rather
     * than an error, because a submission that cannot be displayed is worse
     * than one displayed as empty.
     */
    /**
     * A grid's answer as readable text — one line per row, each cell prefixed
     * with its column's label.
     *
     * Used wherever a grid has to become a single string: the workflow payload
     * handed to automations, and anywhere else that expects one value per
     * question. The PDF and the submissions table render a real table instead,
     * because they have the room.
     *
     * ⚠️ Labels come from gridColumns(), which INCLUDES retired ones — a value
     * stored against a column since withdrawn still says what it was, rather
     * than appearing as an unexplained extra.
     *
     * @param array $field  the grid field, for its column labels
     * @param mixed $raw  the stored answer: JSON or an array, {rows: [...]} or a bare list of rows
     */
    public static function gridToText(array $field, $raw): string
    {
        $cols = self::gridColumns($field);
        $lines = [];
        foreach (self::gridRows($raw) as $row) {
            $parts = [];
            foreach ($row as $cid => $v) {
                $label = $cols[(int)$cid]['label'] ?? ('#' . $cid);
                $val   = is_array($v) ? implode(', ', $v) : trim((string)$v);
                if ($val === '') continue;
                $parts[] = $label . ': ' . $val;
            }
            if ($parts) $lines[] = implode(', ', $parts);
        }
        return implode("\n", $lines);
    }
    /**
     * A grid answer's rows, from JSON or an array, as {"rows": [...]} or a bare list.
     *
     * @param mixed $raw  the stored answer: JSON or an array, {rows: [...]} or a bare list of rows
     */
    public static function gridRows($raw): array
    {
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw)) return [];
        // Tolerate both {"rows": [...]} and a bare list.
        if (isset($raw['rows']) && is_array($raw['rows'])) $raw = $raw['rows'];
        $out = [];
        foreach ($raw as $row) {
            if (is_array($row)) $out[] = $row;
        }
        return $out;
    }
    /* ══ Who may request a form (GH #145) ═══════════════════════════════════
       Benjamin, by email: "How can I restrict a form to a specific group of
       people?"

       🔑 An audience restricts the CATALOGUE, not the module. It answers "which
       customers may request this", never "who may administer it" — analysts
       reach forms through module access and are untouched by any of this.

       🔑 NO ROWS MEANS EVERYONE, which is every form that predates the feature.
       No migration, and no form silently vanishing from anybody's catalogue. */

    /** The principal kinds an audience may name. 'user_group' is a PEOPLE group
     *  (knowledge_user_groups) — the only one of the three groups in this
     *  product that holds portal users as well as analysts, which is exactly
     *  why it is the right one here. See the Groups-of-People developer guide.
     *  ⚠️ Adding 'user' later needs no schema change, only this list and a UI. */
    const AUDIENCE_TYPES = ['user_group'];

    /** True when the form_audiences table exists. Cached per request. */
    public static function audiencesAvailable(PDO $conn): bool
    {
        static $known = null;
        if ($known !== null) return $known;
        try {
            $q = $conn->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'form_audiences'"
            );
            $q->execute();
            $known = ((int)$q->fetchColumn() === 1);
        } catch (Exception) {
            $known = false;
        }
        return $known;
    }

    /**
     * The people groups a form is restricted to. Empty means everyone.
     *
     * @param int $formId  the form
     */
    public static function formAudiences(PDO $conn, int $formId): array
    {
        if (!self::audiencesAvailable($conn)) return [];
        $q = $conn->prepare(
            "SELECT principal_id FROM form_audiences
              WHERE form_id = ? AND principal_type = 'user_group' ORDER BY principal_id"
        );
        $q->execute([$formId]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Replace a form's audience. An empty list means everyone.
     *
     * ⚠️ Ids are checked against real, active groups rather than trusted. A
     * typo that stored a group id which does not exist would restrict the form
     * to nobody — and it would look identical to "restricted to a group" from
     * the outside, which is the worst kind of wrong.
     *
     * @param int $formId  the form whose audience is replaced
     * @param array $groupIds  knowledge_user_groups ids allowed to request it; [] means everyone
     */
    public static function setFormAudiences(PDO $conn, int $formId, array $groupIds): void
    {
        if (!self::audiencesAvailable($conn)) {
            throw new ServiceError('conflict', 'not_ready',
                'Restricting a form needs Database Verification to be run first.');
        }
        $wanted = array_values(array_unique(array_map('intval', $groupIds)));
        $wanted = array_values(array_filter($wanted, fn($g) => $g > 0));

        if ($wanted) {
            $in = implode(',', array_fill(0, count($wanted), '?'));
            $q = $conn->prepare("SELECT id FROM knowledge_user_groups WHERE id IN ($in) AND is_active = 1");
            $q->execute($wanted);
            $real = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            $missing = array_diff($wanted, $real);
            if ($missing) {
                throw new ServiceError('validation', 'unknown_group',
                    'Unknown group: ' . implode(', ', $missing) . '.');
            }
            $wanted = $real;
        }

        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM form_audiences WHERE form_id = ? AND principal_type = 'user_group'")
                 ->execute([$formId]);
            if ($wanted) {
                $ins = $conn->prepare(
                    "INSERT INTO form_audiences (form_id, principal_type, principal_id) VALUES (?, 'user_group', ?)"
                );
                foreach ($wanted as $g) $ins->execute([$formId, $g]);
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /**
     * 🔴 THE ONE PLACE THAT DECIDES. Every portal entry point asks this — the
     * catalogue list, opening a form by id, saving a draft, fetching an image,
     * and submitting. A restriction enforced in four places is a restriction
     * that will be enforced in three of them by the end of the year.
     *
     * Returns a SQL fragment for `WHERE …`, with `:audUser` to bind. Written as
     * a fragment rather than a helper that runs its own query because the list
     * has to FILTER rather than ask once per row.
     *
     * ⚠️ The membership check honours `expires_at`: a people group carries a
     * per-member expiry and a lapsed member is not a member.
     *
     * @param string $formAlias  the alias the calling query gives the forms table, written in code
     */
    public static function portalAudienceSql(PDO $conn, string $formAlias = 'f'): string
    {
        if (!self::audiencesAvailable($conn)) return '1 = 1';
        return "(NOT EXISTS (SELECT 1 FROM form_audiences fa WHERE fa.form_id = {$formAlias}.id)
                 OR EXISTS (
                     SELECT 1
                       FROM form_audiences fa2
                       JOIN knowledge_user_group_members gm
                         ON gm.group_id = fa2.principal_id
                        AND gm.member_type = 'user'
                        AND gm.member_id = :audUser
                        AND (gm.expires_at IS NULL OR gm.expires_at > UTC_TIMESTAMP())
                       JOIN knowledge_user_groups g ON g.id = fa2.principal_id AND g.is_active = 1
                      WHERE fa2.form_id = {$formAlias}.id
                        AND fa2.principal_type = 'user_group'
                 ))";
    }

    /**
     * The same decision as a yes/no, for an endpoint that already has one form.
     *
     * @param int $formId  the form
     * @param int $userId  the portal user (users.id) asking
     */
    public static function portalCanUseForm(PDO $conn, int $formId, int $userId): bool
    {
        if (!self::audiencesAvailable($conn)) return true;
        $sql = "SELECT 1 FROM forms f WHERE f.id = :fid AND " . self::portalAudienceSql($conn, 'f');
        $q = $conn->prepare($sql);
        $q->execute([':fid' => $formId, ':audUser' => $userId]);
        return (bool)$q->fetchColumn();
    }

    /* ══ Drafts ═════════════════════════════════════════════════════════════
       A form somebody started and did not finish. Kept in its own table so a
       draft is invisible to the submissions list, the collection view, the
       counts, the exports, the approval inbox, the workflow triggers and the
       REST API — none of which had to learn anything. */

    const DRAFT_OWNER_KINDS = ['analyst', 'portal'];

    /** Does this database have the drafts table yet? */
    public static function draftsAvailable(PDO $conn): bool
    {
        static $known = null;
        if ($known !== null) return $known;
        try {
            $q = $conn->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'form_drafts'"
            );
            $q->execute();
            $known = ((int)$q->fetchColumn() === 1);
        } catch (Exception) {
            $known = false;
        }
        return $known;
    }

    /**
     * Save (or overwrite) somebody's draft of a form.
     *
     * 🔴 NOTHING HERE IS VALIDATED AGAINST THE FORM. Not being finished is the
     * whole point: a required field may be empty, a conditional branch may be
     * half-answered, a number box may hold the word "tbc". Validation happens on
     * SUBMIT, which is a different act. What IS enforced is the shape — keys
     * must be field ids belonging to this form, so a crafted post cannot use a
     * draft as somewhere to park arbitrary data.
     *
     * @param int $formId  the form version the draft belongs to
     * @param string $ownerKind  'analyst' or 'portal' - which id space $ownerId is in
     * @param int $ownerId  analysts.id for 'analyst', users.id for 'portal'
     * @param array $answers  [form_fields id => value]; ids not on this form are dropped
     */
    public static function saveDraft(PDO $conn, int $formId, string $ownerKind, int $ownerId, array $answers): array
    {
        if (!self::draftsAvailable($conn)) {
            /* ⚠️ 'conflict' (409), not a new kind. serviceErrorHttpStatus() has a
               fixed switch and anything it does not know falls silently through
               to 422, which would say "you sent something invalid" when the
               truth is "this install has not been verified yet". */
            throw new ServiceError('conflict', 'not_ready',
                'Drafts need Database Verification to be run first.');
        }
        if (!in_array($ownerKind, self::DRAFT_OWNER_KINDS, true)) {
            throw new ServiceError('validation', 'invalid_owner', 'Unknown draft owner kind.');
        }
        if ($formId <= 0 || $ownerId <= 0) {
            throw new ServiceError('validation', 'missing_field', 'A draft needs a form and an owner.');
        }

        $fq = $conn->prepare("SELECT id FROM form_fields WHERE form_id = ? AND is_deleted = 0");
        $fq->execute([$formId]);
        $known = array_flip(array_map('intval', $fq->fetchAll(PDO::FETCH_COLUMN)));
        if (!$known) {
            throw new ServiceError('not_found', 'no_form', 'That form has no questions.');
        }

        /* Keys filtered to this form's own live fields. An id that is not one
           of them is dropped rather than refused: a draft is a convenience and
           losing a stale key should not cost somebody the rest of their typing. */
        $clean = [];
        foreach ($answers as $fieldId => $value) {
            $fid = (int)$fieldId;
            if (!isset($known[$fid])) continue;
            if (is_array($value) || is_object($value)) $value = json_encode($value);
            $clean[$fid] = (string)$value;
        }

        $json = json_encode($clean);
        if (strlen($json) > self::DRAFT_MAX_BYTES) {
            throw new ServiceError('validation', 'too_large', 'That draft is too large to save.');
        }

        /* One row per person per form version, so saving again overwrites.
           ON DUPLICATE KEY rather than a read-then-write, which would race two
           tabs into two rows and then fail the unique key anyway. */
        $conn->prepare(
            "INSERT INTO form_drafts (form_id, owner_kind, owner_id, answers, created_date, modified_date)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE answers = VALUES(answers), modified_date = UTC_TIMESTAMP()"
        )->execute([$formId, $ownerKind, $ownerId, $json]);

        return ['saved' => true, 'fields' => count($clean)];
    }

    /** An abuse ceiling. A draft is typing, not an upload. */
    const DRAFT_MAX_BYTES = 256 * 1024;

    /**
     * Somebody's draft of a form, or null.
     *
     * 🔴 ALSO REPORTS WHETHER THE FORM HAS MOVED ON. createVersion() renumbers
     * every field, so a draft's answers only mean anything against the version
     * they were typed into. `stale` true means that version is no longer the
     * one people fill in — the caller must SAY SO rather than load the answers
     * into a newer form, where they would attach to whatever now holds those
     * ids, or to nothing at all.
     *
     * @param int $formId  the form version
     * @param string $ownerKind  'analyst' or 'portal'
     * @param int $ownerId  analysts.id for 'analyst', users.id for 'portal'
     */
    public static function loadDraft(PDO $conn, int $formId, string $ownerKind, int $ownerId): ?array
    {
        if (!self::draftsAvailable($conn)) return null;
        if (!in_array($ownerKind, self::DRAFT_OWNER_KINDS, true)) return null;

        $q = $conn->prepare(
            "SELECT id, form_id, answers,
                    DATE_FORMAT(modified_date, '%Y-%m-%d %H:%i:%s') AS modified_date
               FROM form_drafts
              WHERE form_id = ? AND owner_kind = ? AND owner_id = ?"
        );
        $q->execute([$formId, $ownerKind, $ownerId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $answers = json_decode((string)$row['answers'], true);
        if (!is_array($answers)) $answers = [];

        $leaf = $conn->prepare("SELECT COUNT(*) FROM forms WHERE parent_form_id = ?");
        $leaf->execute([$formId]);

        return [
            'id'            => (int)$row['id'],
            'form_id'       => (int)$row['form_id'],
            'answers'       => $answers,
            'modified_date' => $row['modified_date'],
            'stale'         => ((int)$leaf->fetchColumn() > 0),
        ];
    }

    /**
     * Throw a draft away. Idempotent — deleting one that is gone is not an error.
     *
     * @param int $formId  the form version
     * @param string $ownerKind  'analyst' or 'portal'
     * @param int $ownerId  analysts.id for 'analyst', users.id for 'portal'
     */
    public static function deleteDraft(PDO $conn, int $formId, string $ownerKind, int $ownerId): void
    {
        if (!self::draftsAvailable($conn)) return;
        if (!in_array($ownerKind, self::DRAFT_OWNER_KINDS, true)) return;
        $conn->prepare("DELETE FROM form_drafts WHERE form_id = ? AND owner_kind = ? AND owner_id = ?")
             ->execute([$formId, $ownerKind, $ownerId]);
    }

    /**
     * Which of these forms this person has a draft of, as form_id => modified_date.
     *
     * @param string $ownerKind  'analyst' or 'portal'
     * @param int $ownerId  analysts.id for 'analyst', users.id for 'portal'
     */
    public static function draftsForOwner(PDO $conn, string $ownerKind, int $ownerId): array
    {
        if (!self::draftsAvailable($conn)) return [];
        if (!in_array($ownerKind, self::DRAFT_OWNER_KINDS, true)) return [];
        $q = $conn->prepare(
            "SELECT form_id, DATE_FORMAT(modified_date, '%Y-%m-%d %H:%i:%s') AS modified_date
               FROM form_drafts WHERE owner_kind = ? AND owner_id = ?"
        );
        $q->execute([$ownerKind, $ownerId]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['form_id']] = $r['modified_date'];
        return $out;
    }

    /* ══ Layout ══════════════════════════════════════════════════════════════
       See the LAYOUT_* constants for the shape and why flow and grid share it. */

    /**
     * Does this database have the layout column yet?
     *
     * 🔴 Everything below must work with it ABSENT. A new column that only works
     * after Database Verification breaks every install between the upgrade and
     * the moment somebody remembers to run it — and the failure would be a form
     * that will not open.
     */
    public static function layoutAvailable(PDO $conn): bool
    {
        static $known = null;
        if ($known !== null) return $known;
        try {
            $q = $conn->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'forms' AND column_name = 'layout'"
            );
            $q->execute();
            $known = ((int)$q->fetchColumn() === 1);
        } catch (Exception) {
            $known = false;
        }
        return $known;
    }

    /**
     * The flow layout a form has implicitly always had: its fields in
     * sort_order, packed into rows of twelve by their widths.
     *
     * 🔑 This is what makes the whole change migration-free. Every form that
     * predates layouts stores NULL, derives this, and renders exactly as it did
     * — the packing here is the same arithmetic the CSS grid already does when
     * it wraps, so the derived rows describe what is already on the screen.
     *
     * @param array $fields  the form's fields in sort_order, each with id and config
     */
    public static function deriveLayout(array $fields): array
    {
        $rows = [];
        $row  = [];
        $used = 0;

        foreach ($fields as $f) {
            $w = self::fieldWidthOf($f);
            if ($used > 0 && $used + $w > self::LAYOUT_COLUMNS) {
                $rows[] = ['cells' => $row];
                $row = []; $used = 0;
            }
            $row[] = ['field' => (int)$f['id'], 'width' => $w, 'rowspan' => 1];
            $used += $w;
            if ($used >= self::LAYOUT_COLUMNS) {
                $rows[] = ['cells' => $row];
                $row = []; $used = 0;
            }
        }
        if ($row) $rows[] = ['cells' => $row];

        return ['type' => self::LAYOUT_TYPE_DEFAULT, 'rows' => $rows];
    }

    /**
     * A field's width in twelfths, defaulting to full. Absent is every old field.
     *
     * @param array $field  a form_fields row or payload field; config may be an array or JSON text
     */
    public static function fieldWidthOf(array $field): int
    {
        $config = $field['config'] ?? null;
        if (is_string($config)) $config = json_decode($config, true);
        if (!is_array($config)) $config = [];
        $w = isset($config['width']) ? (int)$config['width'] : self::FIELD_WIDTH_DEFAULT;
        return in_array($w, self::FIELD_WIDTHS, true) ? $w : self::FIELD_WIDTH_DEFAULT;
    }

    /**
     * The layout to render a form with: the stored one if it has one, otherwise
     * the derived flow.
     *
     * ⚠️ A stored layout is AUTHORITATIVE for order and width. sort_order is
     * then only the pool's own ordering, which is what the simple builder edits.
     * Two sources that can disagree is the drift this codebase keeps being bitten
     * by, so the rule is one sentence long and written down here: **if a layout
     * exists, it wins; if it does not, sort_order does.**
     *
     * @param mixed $stored  forms.layout as JSON text or an array; null or '' derives one
     * @param array $fields  the form's live fields in sort_order
     */
    public static function layoutFor($stored, array $fields): array
    {
        if ($stored === null || $stored === '') return self::deriveLayout($fields);

        $layout = is_string($stored) ? json_decode($stored, true) : $stored;
        if (!is_array($layout) || empty($layout['rows']) || !is_array($layout['rows'])) {
            // Unreadable rather than absent. Deriving beats refusing to draw the
            // form at all, and the form is still editable afterwards.
            return self::deriveLayout($fields);
        }
        return self::reconcileLayout($layout, $fields);
    }

    /**
     * Make a stored layout agree with the fields that actually exist.
     *
     * 🔴 THE CASE THAT MATTERS: a question added or retired since the layout was
     * saved. A field the layout does not mention would silently never render —
     * the same silent-drop failure as an untaught type, arriving by a different
     * road. Unplaced fields are therefore appended as their own rows, and cells
     * pointing at a field that is gone are dropped.
     *
     * @param array $layout  the decoded stored layout, {type, rows: [{cells: [...]}]}
     * @param array $fields  the form's live fields in sort_order
     */
    private static function reconcileLayout(array $layout, array $fields): array
    {
        $byId = [];
        foreach ($fields as $f) $byId[(int)$f['id']] = $f;

        $seen = [];
        $rows = [];
        foreach (array_slice($layout['rows'], 0, self::LAYOUT_MAX_ROWS) as $row) {
            if (!is_array($row) || !isset($row['cells']) || !is_array($row['cells'])) continue;
            $cells = [];
            foreach ($row['cells'] as $cell) {
                if (!is_array($cell)) continue;
                $fid = isset($cell['field']) ? (int)$cell['field'] : 0;
                // A cell with no question is a legitimate spacer, and is what a
                // grid layout uses for a merged or empty box. Keep it.
                if ($fid === 0) { $cells[] = self::normaliseCell($cell, null); continue; }
                if (!isset($byId[$fid]) || isset($seen[$fid])) continue;   // gone, or already placed
                $seen[$fid] = true;
                $cells[] = self::normaliseCell($cell, $byId[$fid]);
            }
            if ($cells) $rows[] = ['cells' => $cells];
        }

        // Anything the layout never mentioned, in the pool's own order.
        $unplaced = [];
        foreach ($fields as $f) {
            if (!isset($seen[(int)$f['id']])) $unplaced[] = $f;
        }
        if ($unplaced) {
            foreach (self::deriveLayout($unplaced)['rows'] as $r) $rows[] = $r;
        }

        $type = (isset($layout['type']) && in_array($layout['type'], self::LAYOUT_TYPES, true))
            ? $layout['type'] : self::LAYOUT_TYPE_DEFAULT;

        return ['type' => $type, 'rows' => $rows];
    }

    /**
     * One cell, with every value forced into range.
     *
     * @param array $cell  one stored cell, {field, width, rowspan}
     * @param ?array $field  the field it places, or null for an empty spacer cell
     */
    private static function normaliseCell(array $cell, ?array $field): array
    {
        $w = isset($cell['width']) ? (int)$cell['width'] : null;
        if (!in_array($w, self::FIELD_WIDTHS, true)) {
            // Fall back to the FIELD's own width, which is what a derived layout
            // would have used — never to 12, or a half-width field placed by the
            // designer would silently grow to fill the row.
            $w = $field ? self::fieldWidthOf($field) : self::FIELD_WIDTH_DEFAULT;
        }
        $span = isset($cell['rowspan']) ? (int)$cell['rowspan'] : 1;
        if ($span < 1) $span = 1;

        return [
            'field'   => $field ? (int)$field['id'] : null,
            'width'   => $w,
            'rowspan' => $span,
        ];
    }

    /**
     * Rewrite a stored layout's field references through an id map.
     *
     * Used by createVersion(), where every copied field gets a new id. A cell
     * whose field is not in the map keeps its old id and is therefore dropped on
     * read — which is right: it refers to a field that did not come forward
     * (a retired one), and inventing a reference would place the wrong question.
     *
     * @param string $json  the stored layout JSON
     * @param array $idMap  [old form_fields id => new id]
     */
    public static function remapLayoutFields(string $json, array $idMap): string
    {
        $layout = json_decode($json, true);
        if (!is_array($layout) || empty($layout['rows']) || !is_array($layout['rows'])) return $json;

        foreach ($layout['rows'] as $r => $row) {
            if (!isset($row['cells']) || !is_array($row['cells'])) continue;
            foreach ($row['cells'] as $c => $cell) {
                if (!is_array($cell) || empty($cell['field'])) continue;
                $old = (int)$cell['field'];
                $layout['rows'][$r]['cells'][$c]['field'] = $idMap[$old] ?? $old;
            }
        }
        return json_encode($layout);
    }

    /**
     * Every field id a layout places, in the order it places them.
     *
     * @param array $layout  a layout, {rows: [{cells: [{field}]}]}
     */
    public static function layoutFieldOrder(array $layout): array
    {
        $out = [];
        foreach ($layout['rows'] ?? [] as $row) {
            foreach ($row['cells'] ?? [] as $cell) {
                if (!empty($cell['field'])) $out[] = (int)$cell['field'];
            }
        }
        return $out;
    }

    /**
     * Validates and cleans one field's config JSON - width, label position, the settings of
     * notes, images, date fields and grids, and its visible_if rule - and returns it, or
     * null when empty.
     *
     * @param mixed $config  the field's config as sent - an array, JSON text, or null/'' for none
     * @param int $i  the field's position in the payload, for error messages and the earlier-field rule
     * @param array $fields  the whole payload's question list, to check visible_if targets
     * @param array $indexById  [existing form_fields id => position in the payload]
     * @param string $type  the field's field_type, which decides which settings apply
     */
    private static function validateFieldConfig($config, int $i, array $fields, array $indexById, string $type = 'text'): ?array
    {
        if ($config === null || $config === '') {
            $config = [];
        }
        if (is_string($config)) {
            $config = json_decode($config, true);
            if (!is_array($config)) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: 'config' is not valid JSON.");
            }
        }
        if (!is_array($config)) {
            throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: 'config' must be an object.");
        }

        /* Width applies to every kind of field, including a section heading and
           a presentational block — a heading that spans half a row beside
           another is a real layout.

           Absent stays absent rather than being written as 12: that keeps
           `config` empty for the forms that never asked for a width, so the
           "60 of 66 have config NULL" property survives, and it keeps the
           default in ONE place (this constant) instead of copied into rows. */
        if (array_key_exists('width', $config)) {
            $w = $config['width'];
            if ($w === null || $w === '' || (int)$w === self::FIELD_WIDTH_DEFAULT) {
                unset($config['width']);
            } elseif (!in_array((int)$w, self::FIELD_WIDTHS, true)) {
                throw new ServiceError('validation', 'invalid_field',
                    "fields[{$i}]: unknown width '{$w}'. One of: " . implode(', ', self::FIELD_WIDTHS) . '.');
            } else {
                $config['width'] = (int)$w;
            }
        }
        /* Where the label sits. Applies to anything with a label to place, which
           is every ANSWERABLE type — a presentational block has no control for
           its text to sit beside, so the setting is dropped there rather than
           stored meaning nothing.

           Absent stays absent rather than being written as 'above', for the same
           reason a full-width field stores no width: it keeps `config` empty for
           the forms that never asked, and keeps the default in one place. */
        if (self::isAnswerable($type)) {
            if (array_key_exists('label_position', $config)) {
                $lp = $config['label_position'];
                if ($lp === null || $lp === '' || $lp === self::LABEL_POSITION_DEFAULT) {
                    unset($config['label_position']);
                } elseif (!in_array($lp, self::LABEL_POSITIONS, true)) {
                    throw new ServiceError('validation', 'invalid_field',
                        "fields[{$i}]: unknown label_position '{$lp}'. One of: " . implode(', ', self::LABEL_POSITIONS) . '.');
                } else {
                    $config['label_position'] = $lp;
                }
            }
        } else {
            unset($config['label_position']);
        }

        /* A note's appearance and its optional longer text. Both belong to a
           'note' and nowhere else, and are dropped from other types rather than
           stored looking meaningful — the same rule date_mode follows below. */
        if ($type === 'note') {
            $style = $config['note_style'] ?? self::NOTE_STYLE_DEFAULT;
            if (!in_array($style, self::NOTE_STYLES, true)) {
                throw new ServiceError('validation', 'invalid_field',
                    "fields[{$i}]: unknown note_style '{$style}'. One of: " . implode(', ', self::NOTE_STYLES) . '.');
            }
            /* 🔴 A NAME, never a colour. The whole point is that the author does
               not choose a value: every style resolves to theme tokens defined
               for light AND dark, and a hex can only be right in one of them. */
            $config['note_style'] = $style;

            $body = $config['note_body'] ?? '';
            if (is_array($body) || is_object($body)) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: 'note_body' must be text.");
            }
            $body = trim((string)$body);
            if (mb_strlen($body) > self::NOTE_BODY_MAX) {
                throw new ServiceError('validation', 'invalid_field',
                    "fields[{$i}]: 'note_body' is longer than " . self::NOTE_BODY_MAX . ' characters.');
            }
            // Absent stays absent, so a one-line note keeps an empty-ish config.
            if ($body === '') unset($config['note_body']); else $config['note_body'] = $body;
        } else {
            unset($config['note_style'], $config['note_body']);
        }

        /* An image block's picture. The PATH is the only part that matters for
           safety, and it is never trusted from here — it is re-checked against
           the one shape api/forms/upload_image.php ever writes, and checked
           again by api/forms/image.php before a byte is read.
           🔴 Validated in BOTH places deliberately. This one stops a bad value
           being stored; that one stops a bad value already in a row being
           served. A single check would be a single point of failure, and the
           row is the thing an attacker who reached the database would edit. */
        if ($type === 'image') {
            $path = trim((string)($config['image_path'] ?? ''));
            if ($path === '') {
                unset($config['image_path'], $config['image_name'], $config['image_max']);
            } elseif (!preg_match('~^[0-9]+/[0-9a-f]{32}\.[a-z0-9]{1,5}$~', $path)) {
                throw new ServiceError('validation', 'invalid_field',
                    "fields[{$i}]: 'image_path' is not a stored image reference.");
            } else {
                $config['image_path'] = $path;

                /* The name the author recognises. Display only — it is never
                   used to build a filesystem path, which is the whole reason
                   uploadStoreFile() generates its own stored name. */
                $name = trim((string)($config['image_name'] ?? ''));
                if ($name === '') unset($config['image_name']);
                else              $config['image_name'] = mb_substr($name, 0, 255);

                /* How wide the picture may draw, as a percentage of its column.
                   A picture is the one block whose natural size has nothing to
                   do with the form, so a 2000px diagram needs holding back
                   without asking an author for pixels. */
                $max = isset($config['image_max']) ? (int)$config['image_max'] : self::IMAGE_MAX_DEFAULT;
                if (!in_array($max, self::IMAGE_MAX_WIDTHS, true)) $max = self::IMAGE_MAX_DEFAULT;
                if ($max === self::IMAGE_MAX_DEFAULT) unset($config['image_max']);
                else                                  $config['image_max'] = $max;
            }
        } else {
            unset($config['image_path'], $config['image_name'], $config['image_max']);
        }

        // date_mode belongs to a 'datetime' field and nowhere else — dropped rather
        // than stored on other types, so it can never sit there looking meaningful.
        if ($type === 'datetime') {
            $mode = $config['date_mode'] ?? self::DATE_MODE_DEFAULT;
            if (!in_array($mode, self::DATE_MODES, true)) {
                throw new ServiceError('validation', 'invalid_field',
                    "fields[{$i}]: unknown date_mode '{$mode}'. One of: " . implode(', ', self::DATE_MODES) . '.');
            }
            $config['date_mode'] = $mode;
        } else {
            unset($config['date_mode']);
        }

        /* GH #190 phase 4: answers that COUNT AS AN EXCEPTION - "Warning", "Failure" on a
           backup check - so the task evidence report lists them beside failed checklist
           steps. A choice field's own options only; anything else is dropped. Belongs to
           the form VERSION, like the options themselves, so it cannot change after the fact. */
        if (in_array($type, ['dropdown', 'radio', 'checkboxes'], true) && !empty($config['exception_values']) && is_array($config['exception_values'])) {
            $opts = $fields[$i]['options'] ?? [];
            if (is_string($opts)) $opts = json_decode($opts, true) ?: [];
            $opts = array_map('strval', is_array($opts) ? $opts : []);
            $keep = array_values(array_unique(array_filter(array_map('strval', $config['exception_values']),
                fn($v) => in_array($v, $opts, true))));
            if ($keep) $config['exception_values'] = $keep; else unset($config['exception_values']);
        } else {
            unset($config['exception_values']);
        }

        /* ---- Grid columns ------------------------------------------------
           🔑 Identified by a STABLE ID, never a position or a label. An id is
           what lets a column be reordered, renamed and retired without
           orphaning every value ever stored against it — the same rule
           form_fields already follows for questions, one level down.

           A retired column keeps its row here (soft delete) so that readers can
           still label the answers people genuinely gave it. */
        if ($type === 'grid') {
            $cols = $config['columns'] ?? null;
            if (!is_array($cols) || !$cols) {
                throw new ServiceError('validation', 'invalid_field',
                    "fields[{$i}]: a grid needs at least one column.");
            }

            $live = 0;
            $seen = [];
            $clean = [];
            foreach ($cols as $n => $col) {
                if (!is_array($col)) {
                    throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: column {$n} is not an object.");
                }
                $cid = isset($col['id']) ? (int)$col['id'] : 0;
                if ($cid <= 0) {
                    throw new ServiceError('validation', 'invalid_field',
                        "fields[{$i}]: column {$n} has no id. Columns are identified by id, not by position.");
                }
                if (isset($seen[$cid])) {
                    throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: column id {$cid} is used twice.");
                }
                $seen[$cid] = true;

                $deleted = !empty($col['deleted']);
                $label   = trim((string)($col['label'] ?? ''));
                $ctype   = (string)($col['type'] ?? 'text');

                if (!$deleted && $label === '') {
                    throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: column {$cid} has no label.");
                }
                if (!in_array($ctype, self::GRID_CELL_TYPES, true)) {
                    throw new ServiceError('validation', 'invalid_field',
                        "fields[{$i}]: column {$cid} has type '{$ctype}'. A grid cell may be one of: "
                        . implode(', ', self::GRID_CELL_TYPES) . '.');
                }

                $opts = null;
                if (in_array($ctype, self::GRID_CELL_TYPES_WITH_OPTIONS, true)) {
                    $opts = self::parseOptionList($col['options'] ?? '');
                    if (!$deleted && !$opts) {
                        throw new ServiceError('validation', 'invalid_field',
                            "fields[{$i}]: column {$cid} is a '{$ctype}' and needs at least one option.");
                    }
                }

                if (!$deleted) $live++;

                /* Rebuilt rather than passed through, so a client cannot store
                   arbitrary keys inside a column definition. */
                $entry = ['id' => $cid, 'label' => $label, 'type' => $ctype, 'required' => !empty($col['required'])];
                if ($opts !== null)  $entry['options'] = $opts;
                if ($deleted)        $entry['deleted'] = true;
                $clean[] = $entry;
            }

            if ($live < 1) {
                throw new ServiceError('validation', 'invalid_field',
                    "fields[{$i}]: a grid needs at least one column that is not retired.");
            }
            if ($live > self::GRID_MAX_COLUMNS) {
                throw new ServiceError('validation', 'invalid_field',
                    "fields[{$i}]: a grid may have at most " . self::GRID_MAX_COLUMNS . " columns.");
            }

            /* The id counter must never go backwards, or a retired column's id
               gets reused and its old answers reappear under a new heading. */
            $maxId = $seen ? max(array_keys($seen)) : 0;
            $next  = (int)($config['next_column_id'] ?? 0);
            $config['next_column_id'] = max($next, $maxId + 1);
            $config['columns'] = $clean;
        } else {
            // Columns belong to a grid and nowhere else — dropped rather than
            // stored on another type, where they would look meaningful.
            unset($config['columns'], $config['next_column_id']);
        }
        $vif = $config['visible_if'] ?? null;
        if ($vif === null) {
            return $config ?: null;
        }
        if (!is_array($vif) || !isset($vif['rules']) || !is_array($vif['rules'])) {
            throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: 'visible_if' needs a 'rules' array.");
        }
        if (!$vif['rules']) {
            // An empty rule list means "no condition" — drop it rather than store a
            // shape that later reads as a condition that can never be satisfied.
            unset($config['visible_if']);
            return $config ?: null;
        }

        $match = (($vif['match'] ?? 'all') === 'any') ? 'any' : 'all';
        $clean = [];
        foreach ($vif['rules'] as $r => $rule) {
            if (!is_array($rule)) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: rule {$r} must be an object.");
            }
            $op = (string)($rule['op'] ?? 'equals');
            if (!in_array($op, self::CONDITION_OPS, true)) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: unknown condition operator '{$op}'. One of: " . implode(', ', self::CONDITION_OPS) . '.');
            }

            // Resolve the referenced field to a position in this payload.
            $ref = $rule['field'] ?? null;
            if (is_string($ref) && str_starts_with($ref, 'idx:')) {
                $refIndex = (int)substr($ref, 4);
                $refValue = $ref;
            } else {
                $refId = (int)$ref;
                if (!isset($indexById[$refId])) {
                    throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: condition references field {$refId}, which is not on this form.");
                }
                $refIndex = $indexById[$refId];
                $refValue = $refId;
            }
            if (!isset($fields[$refIndex])) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: condition references position {$refIndex}, which does not exist.");
            }
            if ($refIndex >= $i) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: a condition can only depend on an earlier question.");
            }
            $refType = (string)($fields[$refIndex]['field_type'] ?? 'text');
            if (!in_array($refType, self::ANSWERABLE_TYPES, true)) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: a condition cannot depend on a '{$refType}', which collects no answer.");
            }

            $clean[] = [
                'field' => $refValue,
                'op'    => $op,
                'value' => (string)($rule['value'] ?? ''),
            ];
        }

        $config['visible_if'] = ['match' => $match, 'rules' => $clean];
        return $config;
    }

    /**
     * Sync a form's fields BY ID.
     *
     * This used to be positional — it updated the existing rows in order, so dragging
     * a question to a new place rewrote the labels while the answers stayed put, and
     * every historic submission silently started reading against the wrong questions.
     * Removing a field hard-deleted the trailing row's answers outright. Now each
     * payload row carries the id it is editing, a field with no id is genuinely new,
     * and a field that disappears is SOFT deleted so past answers survive.
     *
     * Two passes: the ids have to exist before a condition that points at a
     * brand-new field ("idx:N") can be resolved to one.
     *
     * @param int $formId  the form whose fields are written
     * @param array $fields  the validated list from validateFields(), in order
     */
    private static function syncFields(PDO $conn, int $formId, array $fields): void
    {
        // Ids that really belong to this form. A payload id outside this set is
        // ignored rather than trusted — otherwise a crafted save could re-point
        // another form's field (and, with it, another form's answers).
        $stmt = $conn->prepare("SELECT id FROM form_fields WHERE form_id = ?");
        $stmt->execute([$formId]);
        $ownIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $ownIds = array_flip($ownIds);

        $upd = $conn->prepare(
            "UPDATE form_fields
                SET field_type = ?, label = ?, options = ?, is_required = ?, sort_order = ?, is_deleted = 0
              WHERE id = ? AND form_id = ?"
        );
        $ins = $conn->prepare(
            "INSERT INTO form_fields (form_id, field_type, label, options, is_required, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        // ---- Pass 1: write the fields, remember which id each position ended up as.
        $idByIndex = [];
        $keptIds   = [];
        foreach ($fields as $i => $f) {
            $id = $f['id'] ?? null;
            if ($id !== null && isset($ownIds[$id])) {
                $upd->execute([$f['field_type'], $f['label'], $f['options'], $f['is_required'], $i, $id, $formId]);
            } else {
                $ins->execute([$formId, $f['field_type'], $f['label'], $f['options'], $f['is_required'], $i]);
                $id = (int)$conn->lastInsertId();
            }
            $idByIndex[$i] = $id;
            $keptIds[]     = $id;
        }

        // ---- Retire whatever is no longer on the form, WITHOUT touching its answers.
        if ($keptIds) {
            $place = implode(',', array_fill(0, count($keptIds), '?'));
            $conn->prepare("UPDATE form_fields SET is_deleted = 1 WHERE form_id = ? AND id NOT IN ($place)")
                 ->execute(array_merge([$formId], $keptIds));
        } else {
            $conn->prepare("UPDATE form_fields SET is_deleted = 1 WHERE form_id = ?")->execute([$formId]);
        }

        // ---- Pass 2: store the conditions, now that every referenced field has an id.
        $updCfg = $conn->prepare("UPDATE form_fields SET config = ? WHERE id = ? AND form_id = ?");
        foreach ($fields as $i => $f) {
            $config = $f['config'] ?? null;
            if (is_array($config) && isset($config['visible_if']['rules'])) {
                foreach ($config['visible_if']['rules'] as $r => $rule) {
                    $ref = $rule['field'] ?? null;
                    if (is_string($ref) && str_starts_with($ref, 'idx:')) {
                        $refIndex = (int)substr($ref, 4);
                        // validateFields already proved this position exists and is earlier.
                        $config['visible_if']['rules'][$r]['field'] = $idByIndex[$refIndex] ?? 0;
                    } else {
                        $config['visible_if']['rules'][$r]['field'] = (int)$ref;
                    }
                }
            }
            $updCfg->execute([
                $config ? json_encode($config) : null,
                $idByIndex[$i],
                $formId,
            ]);
        }
    }
}
