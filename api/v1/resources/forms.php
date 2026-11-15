<?php
/**
 * FreeITSM REST API v1 — forms resource (forms, fields, versions, submissions).
 *
 * Mirrors the module's internal endpoints:
 *   - GET /forms is get_forms.php: ONE row per version chain (the leaf — the
 *     current editable version), with field + submission counts;
 *   - PATCH replicates save_form.php's field sync EXACTLY, and refuses to touch
 *     a frozen historical version (409, like the server-side leaf check).
 *     ⚠️ That sync is now BY ID, not positional. Send each field's `id` back (as
 *     GET returns it) and it is updated in place. A field with no `id` is treated
 *     as new, and any field you omit is RETIRED — soft-deleted, keeping the
 *     answers people already gave it. A client that rebuilds the field list from
 *     scratch without ids therefore replaces the questions rather than renaming
 *     them; that is deliberate, and the opposite of the old behaviour, which
 *     quietly re-pointed historical answers at whichever question had landed in
 *     that position;
 *   - POST /forms/{id}/versions is create_version.php: fork the leaf into a
 *     new row (parent_form_id chain, version_number + 1), fields cloned;
 *   - POST /forms/{id}/submissions is submit_form.php: per-type required +
 *     format validation, then the form.submitted workflow dispatch with the
 *     label-keyed answers map (the "new starter form → tickets" use case) —
 *     fired after commit and swallowed on error, exactly like the UI.
 *
 * Deliberate differences from the UI, documented:
 *   - unknown field ids in a submission are a 422 (the UI inserts them
 *     blindly and hits the FK);
 *   - a field with an empty label is a 422 on create/update (the UI
 *     silently drops it — machines deserve the error), and field_type must
 *     be one of the module's eight types (the UI stores anything);
 *   - PATCH can set is_active — NOTHING in the UI writes it today (the
 *     column only carries across version clones), so the API is the only
 *     way to retire a form without deleting it;
 *   - DELETE refuses a non-leaf version (409) unless ?chain=true, which
 *     removes the WHOLE chain + all its submissions transactionally —
 *     deleting just the leaf resurfaces the previous version in the list.
 *
 * Field types: text, textarea, email, number, checkbox (single yes/no),
 * checkboxes (multi — stored as a JSON array string), dropdown, radio.
 * `options` is a JSON array for dropdown / radio / checkboxes.
 *
 * Install-wide (no company scoping — matches the UI); no audit trail exists.
 * The module's AI form-generation endpoints stay UI-only.
 */

// Form WRITES (save/version/delete/submit) are delegated to FormsService
// (includes/services/forms.php), which also pulls in the workflow engine for
// the form.submitted dispatch. The read handlers + serializers stay here.
require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/forms.php';

// ---------------------------------------------------------------------------
// Serializers + loaders
// ---------------------------------------------------------------------------

/**
 * The SELECT for forms, with creator and editor names and counts of child versions,
 * fields and submissions - child_count 0 marks the current version.
 */
function apiFormSelect(): string {
    return "SELECT f.*, ca.full_name AS created_by_name, ma.full_name AS modified_by_name,
                   (SELECT COUNT(*) FROM forms ch WHERE ch.parent_form_id = f.id)       AS child_count,
                   (SELECT COUNT(*) FROM form_fields      WHERE form_id = f.id)          AS field_count,
                   (SELECT COUNT(*) FROM form_submissions WHERE form_id = f.id)          AS submission_count
            FROM forms f
            LEFT JOIN analysts ca ON ca.id = f.created_by
            LEFT JOIN analysts ma ON ma.id = f.modified_by";
}

/**
 * Shapes a form field row for the API - type, label, decoded options, required flag,
 * order and its conditional-visibility config.
 *
 * @param array $r  a form_fields row (options and config still JSON text)
 */
function apiSerializeFormField(array $r): array {
    $options = null;
    if ($r['options'] !== null && $r['options'] !== '') {
        $decoded = json_decode($r['options'], true);
        $options = is_array($decoded) ? $decoded : $r['options'];
    }
    $config = null;
    if (!empty($r['config'])) {
        $decoded = json_decode((string)$r['config'], true);
        $config  = is_array($decoded) ? $decoded : null;
    }
    return [
        'id'          => (int)$r['id'],
        // 'section' is a heading — presentational, collects no answer.
        'field_type'  => $r['field_type'],
        'label'       => $r['label'],
        'options'     => $options,
        'is_required' => (bool)$r['is_required'],
        'sort_order'  => (int)$r['sort_order'],
        // Conditional visibility, or null when the field is always shown.
        // rules[].field is a form_fields.id on this same form.
        'config'      => $config,
    ];
}

/**
 * Shapes a form row for the API - title, version (is_current marks the editable one),
 * counts and people, plus its live fields unless $withFields is false.
 *
 * @param array $r  the forms row as apiFormSelect() loads it, with child, field and submission counts and people names
 * @param bool $withFields  false to leave out the 'fields' list (and its extra query), as the list endpoint does
 */
function apiSerializeForm(PDO $conn, array $r, bool $withFields = true): array {
    $form = [
        'id'          => (int)$r['id'],
        'title'       => $r['title'],
        'description' => $r['description'],
        'is_active'   => (bool)$r['is_active'],
        'version'     => [
            'number'         => (int)$r['version_number'],
            'parent_form_id' => $r['parent_form_id'] !== null ? (int)$r['parent_form_id'] : null,
            // is_current = leaf = editable; older snapshots are frozen.
            'is_current'     => ((int)$r['child_count']) === 0,
        ],
        'field_count'      => (int)$r['field_count'],
        'submission_count' => (int)$r['submission_count'],
        'created_by'  => $r['created_by'] === null ? null : ['id' => (int)$r['created_by'], 'name' => $r['created_by_name']],
        'modified_by' => $r['modified_by'] === null ? null : ['id' => (int)$r['modified_by'], 'name' => $r['modified_by_name']],
        'created_at'  => apiIsoDate($r['created_date']),
        'modified_at' => apiIsoDate($r['modified_date']),
        // GH #348: the catalogue card - all null before Database Verification adds the
        // columns, or on a form with no card. The picture's stored path is never sent
        // (catalogue_has_image says whether there is one): it is set in the form editor.
        'catalogue_summary'      => $r['catalogue_summary'] ?? null,
        'catalogue_price'        => isset($r['catalogue_price']) && $r['catalogue_price'] !== '' ? (float)$r['catalogue_price'] : null,
        'catalogue_currency'     => ($r['catalogue_currency'] ?? '') !== '' ? $r['catalogue_currency'] : null,
        'catalogue_price_period' => isset($r['catalogue_price']) && $r['catalogue_price'] !== ''
            ? (($r['catalogue_price_period'] ?? '') !== '' ? $r['catalogue_price_period'] : 'once') : null,
        'catalogue_has_image'    => !empty($r['catalogue_image_path']),
    ];
    if ($withFields) {
        // Retired fields stay out of the API's view of the form for the same reason
        // they stay out of the builder: the form no longer asks them.
        $stmt = $conn->prepare("SELECT * FROM form_fields WHERE form_id = ? AND is_deleted = 0 ORDER BY sort_order, id");
        $stmt->execute([(int)$r['id']]);
        $form['fields'] = array_map('apiSerializeFormField', $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    return $form;
}

/**
 * Loads one form row (apiFormSelect), or answers 404 and stops.
 *
 * @param int $formId  the forms id
 */
function apiLoadForm(PDO $conn, int $formId): array {
    $stmt = $conn->prepare(apiFormSelect() . " WHERE f.id = ?");
    $stmt->execute([$formId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Form not found.');
    }
    return $row;
}

/**
 * Shapes a submission for the API with every answer beside its field - checkbox answers
 * decoded to lists, and a table's answer decoded to rows with its column definitions
 * (retired columns included and marked).
 *
 * @param array $r  the form_submissions row as apiLoadFormSubmission() loads it, with form_title and submitted_by_name
 */
function apiSerializeFormSubmission(PDO $conn, array $r): array {
    // Answers joined to their field definitions; checkboxes values are
    // stored as JSON array strings — decode them for machine consumers.
    $stmt = $conn->prepare(
        "SELECT d.field_id, d.field_value, ff.label, ff.field_type, ff.config
         FROM form_submission_data d
         JOIN form_fields ff ON ff.id = d.field_id
         WHERE d.submission_id = ?
         ORDER BY ff.sort_order, ff.id"
    );
    $stmt->execute([(int)$r['id']]);
    $answers = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $value = $d['field_value'];
        if ($d['field_type'] === 'checkboxes' && $value !== null && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        $entry = [
            'field_id'   => (int)$d['field_id'],
            'label'      => $d['label'],
            'field_type' => $d['field_type'],
            'value'      => $value,
        ];
        if ($d['field_type'] === 'grid') {
            /* A table's answer is rows keyed by COLUMN ID, which is meaningless
               to a consumer on its own — "7" is not a heading. Decoded into real
               rows and accompanied by the column definitions, so a machine can
               read it without also having to fetch the form and decode its
               config.
               🔴 gridColumns(), so RETIRED columns are included and marked: a
               value stored against a withdrawn column must still say what it was
               answering. A consumer that silently dropped it would be reading a
               different record from the one a person sees on screen. */
            $field = ['config' => $d['config']];
            $entry['value'] = FormsService::gridRows($value);
            $entry['columns'] = array_values(array_map(function ($c) {
                return [
                    'id'      => (int)$c['id'],
                    'label'   => (string)($c['label'] ?? ''),
                    'type'    => (string)($c['type'] ?? 'text'),
                    'retired' => !empty($c['deleted']),
                ];
            }, FormsService::gridColumns($field)));
        }
        $answers[] = $entry;
    }
    return [
        'id'           => (int)$r['id'],
        'form'         => ['id' => (int)$r['form_id'], 'title' => $r['form_title']],
        'submitted_by' => $r['submitted_by'] === null ? null : ['id' => (int)$r['submitted_by'], 'name' => $r['submitted_by_name']],
        'submitted_at' => apiIsoDate($r['submitted_date']),
        'answers'      => $answers,
    ];
}

/**
 * Loads one submission that belongs to the given form, or answers 404 and stops.
 *
 * @param int $formId  the forms id the submission must belong to
 * @param int $submissionId  the form_submissions id
 */
function apiLoadFormSubmission(PDO $conn, int $formId, int $submissionId, ?array $companyScope = null): array {
    // GH #190: one made inside a task is visible only within that task's company.
    require_once __DIR__ . '/../../../includes/services/task_forms.php';
    [$scopeSql, $scopeArgs] = TaskForms::submissionScopeSql($conn, $companyScope, 's');
    $stmt = $conn->prepare(
        "SELECT s.*, f.title AS form_title, a.full_name AS submitted_by_name
         FROM form_submissions s
         JOIN forms f ON f.id = s.form_id
         LEFT JOIN analysts a ON a.id = s.submitted_by
         WHERE s.id = ? AND s.form_id = ? AND $scopeSql"
    );
    $stmt->execute(array_merge([$submissionId, $formId], $scopeArgs));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Submission not found on this form.');
    }
    return $row;
}

// ---------------------------------------------------------------------------
// Forms
// ---------------------------------------------------------------------------

// GET /forms — one row per chain (the current version), like the module list.
function apiFormsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ["NOT EXISTS (SELECT 1 FROM forms ch2 WHERE ch2.parent_form_id = f.id)"];
    $args  = [];
    if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
        $where[] = 'f.is_active = ?';
        $args[]  = $_GET['is_active'] === 'true' ? 1 : 0;
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = 'f.title LIKE ?';
        $args[]  = '%' . trim($_GET['q']) . '%';
    }
    $stmt = $conn->prepare(
        apiFormSelect() . " WHERE " . implode(' AND ', $where) . " ORDER BY f.modified_date DESC"
    );
    $stmt->execute($args);
    apiRespond(array_map(function ($r) use ($conn) {
        return apiSerializeForm($conn, $r, false);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

/** GET /forms/{id} - one form with its fields. */
function apiFormsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(apiSerializeForm($conn, apiLoadForm($conn, $params[0])));
}

/**
 * The save payload as the API may send it. catalogue_summary, catalogue_price,
 * catalogue_currency and catalogue_price_period pass through (GH #348, checked by
 * catalogueCardFromInput()); catalogue_image_path does not - the API has no way to
 * upload the picture, and the stored path is internal.
 *
 * @param array $body  the request body
 */
function apiFormsWritableBody(array $body): array {
    unset($body['catalogue_image_path']);
    return $body;
}

// POST /forms — mirrors save_form.php's create branch (version_number 1).
function apiFormsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = FormsService::saveForm($conn, ActorContext::fromApiKey($apiKey), apiFormsWritableBody($body));
        apiRespond(apiSerializeForm($conn, apiLoadForm($conn, $res['id'])), 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// PATCH /forms/{id} — save_form.php's in-place save (id-based field sync).
function apiFormsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = FormsService::saveForm($conn, ActorContext::fromApiKey($apiKey), array_merge(apiFormsWritableBody($body), ['id' => (int)$params[0]]));
        apiRespond(apiSerializeForm($conn, apiLoadForm($conn, $res['id'])));
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// DELETE /forms/{id} — one version (leaf only), or the whole chain with
// ?chain=true. Always transactional, submissions + data removed explicitly.
function apiFormsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $chain = isset($_GET['chain']) && $_GET['chain'] === 'true';
        $res = FormsService::deleteForm($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $chain);
        apiRespond(['id' => $params[0], 'deleted' => true, 'versions_deleted' => $res['versions_deleted']]);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// Versions
// ---------------------------------------------------------------------------

// GET /forms/{id}/versions — the whole chain, oldest first (list_versions.php).
function apiFormVersionsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadForm($conn, $params[0]);

    $rootId = $params[0];
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

    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(apiFormSelect() . " WHERE f.id IN ($place) ORDER BY f.version_number, f.id");
    $stmt->execute($ids);
    apiRespond(array_map(function ($r) use ($conn) {
        return apiSerializeForm($conn, $r, false);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// POST /forms/{id}/versions — fork the leaf (create_version.php).
function apiFormVersionsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = FormsService::createVersion($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond(apiSerializeForm($conn, apiLoadForm($conn, $res['id'])), 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// Submissions
// ---------------------------------------------------------------------------

/** The SELECT for form submissions, with the form's title and the submitter's name. */
function apiFormSubmissionsSelect(): string {
    return "SELECT s.*, f.title AS form_title, a.full_name AS submitted_by_name
            FROM form_submissions s
            JOIN forms f ON f.id = s.form_id
            LEFT JOIN analysts a ON a.id = s.submitted_by";
}

// GET /forms/{id}/submissions — newest first, paginated.
function apiFormSubmissionsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadForm($conn, $params[0]);
    $where = ['s.form_id = ?'];
    $args  = [$params[0]];
    // GH #190: a submission made inside a task is listed only within that task's company.
    require_once __DIR__ . '/../../../includes/services/task_forms.php';
    [$scopeSql, $scopeArgs] = TaskForms::submissionScopeSql($conn, ActorContext::fromApiKey($apiKey)->companyScope, 's');
    $where[] = $scopeSql;
    $args = array_merge($args, $scopeArgs);
    foreach (['submitted_since' => '>=', 'submitted_before' => '<'] as $param => $op) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "s.submitted_date $op ?";
            $args[]  = apiParseDate($_GET[$param], $param);
        }
    }
    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM form_submissions s WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(
        apiFormSubmissionsSelect() . " WHERE $whereSql
         ORDER BY s.submitted_date DESC, s.id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($args);
    apiRespond(array_map(function ($r) use ($conn) {
        return apiSerializeFormSubmission($conn, $r);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

/** GET /forms/{id}/submissions/{submissionId} - one submission with its answers. */
function apiFormSubmissionsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(apiSerializeFormSubmission($conn, apiLoadFormSubmission($conn, $params[0], $params[1], ActorContext::fromApiKey($apiKey)->companyScope)));
}

// POST /forms/{id}/submissions — submit_form.php: per-type validation, then
// the form.submitted workflow dispatch (after commit, errors swallowed).
function apiFormSubmissionsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $data = (isset($body['data']) && is_array($body['data'])) ? $body['data'] : [];
        $submissionId = FormsService::submitForm($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $data);
        apiRespond(apiSerializeFormSubmission($conn, apiLoadFormSubmission($conn, $params[0], $submissionId, ActorContext::fromApiKey($apiKey)->companyScope)), 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// DELETE /forms/{id}/submissions/{sid} — delete_submission.php, but with the
// data rows removed explicitly (grown installs may lack the cascade FK).
function apiFormSubmissionsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        FormsService::deleteSubmission($conn, ActorContext::fromApiKey($apiKey), (int)$params[1], (int)$params[0]);
        apiRespond(['id' => $params[1], 'deleted' => true]);
    } catch (ServiceError $e) { apiFailFromService($e); }
}
