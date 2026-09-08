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
 * errors are swallowed so a workflow can never break a submission.
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
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class FormsService
{
    // 'section' is a heading, not a question — see ANSWERABLE_TYPES.
    const FIELD_TYPES = ['text', 'textarea', 'email', 'number', 'checkbox', 'checkboxes', 'dropdown', 'radio', 'datetime', 'lookup', 'section'];

    /** The types that actually collect an answer. A 'section' never produces submission data. */
    const ANSWERABLE_TYPES = ['text', 'textarea', 'email', 'number', 'checkbox', 'checkboxes', 'dropdown', 'radio', 'datetime', 'lookup'];

    /**
     * What a 'datetime' field actually asks for, held in config.date_mode.
     *
     * ONE field type with a mode rather than three separate types, because a field's
     * field_type cannot be changed once it exists: picking `date` and later wanting the
     * time too would mean deleting the field and adding a new one, which retires the old
     * one and strands every answer already given to it under a separate column. A mode
     * is a setting you can flip, and the field keeps its identity.
     */
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

    /** Which source this lookup points at, or null if it is not configured yet. */
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
     * @param int[]|null $tenantIds  companies whose rows may be returned.
     *                               `null` means UNRESTRICTED and is only ever
     *                               correct for an analyst who can see every
     *                               company. There is no "all" string — a typo
     *                               in a string would silently mean everything.
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
    public static function saveForm(PDO $conn, ActorContext $ctx, array $in): array
    {
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
                $conn->prepare(
                    "UPDATE forms SET title = ?, description = ?, is_active = ?, is_portal_visible = ?,
                            requires_approval = ?, approver_id = ?,
                            modified_by = ?, modified_date = UTC_TIMESTAMP()
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
                    $ctx->actorId,
                    $formId,
                ]);
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
                "INSERT INTO forms (title, description, is_active, is_portal_visible, requires_approval, approver_id, created_by, modified_by, version_number, created_date, modified_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([
                $title,
                trim((string)($in['description'] ?? '')),
                isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1,
                // Fail closed: a new form is NOT offered to customers unless
                // someone deliberately says so.
                isset($in['is_portal_visible']) ? (int)(bool)$in['is_portal_visible'] : 0,
                isset($in['requires_approval']) ? (int)(bool)$in['requires_approval'] : 0,
                (isset($in['approver_id']) && $in['approver_id'] !== null && $in['approver_id'] !== '') ? (int)$in['approver_id'] : null,
                $ctx->actorId,
                $ctx->actorId,
            ]);
            $formId = (int)$conn->lastInsertId();
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

    /** Delete one version (leaf only) or the whole chain. Returns ['id','versions_deleted']. */
    public static function deleteForm(PDO $conn, ActorContext $ctx, int $id, bool $chain = false): array
    {
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

    /** Fork the leaf into a new version. Returns ['id','version_number']. */
    public static function createVersion(PDO $conn, ActorContext $ctx, int $parentId): array
    {
        if ($parentId <= 0) {
            throw new ServiceError('validation', 'missing_field', 'parent_form_id is required');
        }
        $src = self::loadFormRow($conn, $parentId);            // 404 if gone
        self::requireLeaf($src);                               // 409 on frozen versions

        $conn->beginTransaction();
        try {
            $conn->prepare(
                "INSERT INTO forms (title, description, is_active, is_portal_visible, requires_approval, approver_id, created_by, modified_by, parent_form_id, version_number, created_date, modified_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
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
                $ctx->actorId,
                $ctx->actorId,
                $parentId,
                (int)$src['version_number'] + 1,
            ]);
            $newId = (int)$conn->lastInsertId();

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
     * @param ?int $portalUserId  a REQUESTER (users.id) submitting through the
     *   self-service request catalogue, or null for the analyst paths.
     *
     *   It is a separate argument rather than $ctx->actorId because the two are
     *   DIFFERENT ID SPACES. `submitted_by` has no foreign key and every reader
     *   LEFT JOINs it to `analysts`, so writing a users.id there would silently
     *   attribute a customer's request to whichever analyst happened to share
     *   the number. They go in different columns and exactly one is set.
     */
    public static function submitForm(PDO $conn, ActorContext $ctx, int $formId, array $data, ?int $portalUserId = null): int
    {
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
            if ($fieldsById[(int)$fieldId]['field_type'] === 'section') {
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
            if ($type === 'section' || empty($visible[$fid])) {
                continue;
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
                    // The scope is the ACTOR's, taken from the context rather
                    // than re-derived here — whoever built the context already
                    // knows whether this is an analyst, an API key or a portal
                    // user, and `null` there already means "every company".
                    if (!self::lookupValueAllowed($conn, $source, $lid, $ctx->companyScope)) {
                        throw new ServiceError('validation', 'invalid_field',
                            '"' . $field['label'] . '" is not something you can choose.');
                    }
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
        $gateApproverId = ($portalUserId !== null && !empty($form['requires_approval']) && !empty($form['approver_id']))
            ? (int) $form['approver_id']
            : null;
        $approvalStatus = $gateApproverId !== null ? 'pending' : 'not_required';

        $conn->beginTransaction();
        try {
            // Exactly one submitter column is populated — see the $portalUserId
            // note on this method.
            $conn->prepare(
                "INSERT INTO form_submissions (form_id, submitted_by, submitted_by_user_id, submitted_date, approval_status, approver_id)
                 VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?)"
            )->execute([
                $formId,
                $portalUserId !== null ? null : $ctx->actorId,
                $portalUserId,
                $approvalStatus,
                $gateApproverId,
            ]);
            $submissionId = (int)$conn->lastInsertId();

            $ins = $conn->prepare("INSERT INTO form_submission_data (submission_id, field_id, field_value) VALUES (?, ?, ?)");
            foreach ($normalised as $fieldId => $value) {
                $ins->execute([$submissionId, $fieldId, $value]);
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
                $decoded = json_decode($value, true);
                $flat = is_array($decoded) ? implode(', ', $decoded) : $value;
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
            // A gated request must NOT fire form.submitted: an admin's create-ticket
            // rule on that event would jump the approval gate and raise the ticket
            // anyway. It fires catalogue_request.submitted instead — the hook to
            // notify the approver that something is waiting for them.
            if ($gateApproverId !== null) {
                $payload['approver'] = ['id' => $gateApproverId];
                WorkflowEngine::dispatch('catalogue_request.submitted', $payload);
            } else {
                WorkflowEngine::dispatch('form.submitted', $payload);
            }
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in form submission: ' . $wfEx->getMessage());
        }

        return $submissionId;
    }

    /** Delete a submission (+ its data). $formId scopes the 404 when supplied. Returns the id. */
    public static function deleteSubmission(PDO $conn, ActorContext $ctx, int $submissionId, ?int $formId = null): int
    {
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

    // ======================================================================
    //  Internals
    // ======================================================================

    /** Load a form row (with child_count for the leaf check), or throw 404. */
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

    /** 409 unless the form is the chain leaf (the current editable version). */
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

            // A heading collects nothing, so "required" would be a promise we could
            // never keep — reject it rather than store a flag the renderers ignore.
            $isRequired = (int)(bool)($field['is_required'] ?? false);
            if ($type === 'section') {
                if ($isRequired) {
                    throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: a 'section' heading cannot be required.");
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
            if (is_string($ref) && strpos($ref, 'idx:') === 0) {
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
                    if (is_string($ref) && strpos($ref, 'idx:') === 0) {
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
