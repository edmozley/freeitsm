<?php
/**
 * FormsService — the single home for the forms module's write rules:
 * form save (create + positional-sync update), version fork, delete
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
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class FormsService
{
    const FIELD_TYPES = ['text', 'textarea', 'email', 'number', 'checkbox', 'checkboxes', 'dropdown', 'radio'];

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
            $ins = $conn->prepare(
                "INSERT INTO form_fields (form_id, field_type, label, options, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($fields as $i => $f) {
                $ins->execute([$formId, $f['field_type'], $f['label'], $f['options'], $f['is_required'], $i]);
            }
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
                "INSERT INTO forms (title, description, is_active, is_portal_visible, created_by, modified_by, parent_form_id, version_number, created_date, modified_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
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
                $ctx->actorId,
                $ctx->actorId,
                $parentId,
                (int)$src['version_number'] + 1,
            ]);
            $newId = (int)$conn->lastInsertId();
            $conn->prepare(
                "INSERT INTO form_fields (form_id, field_type, label, options, is_required, sort_order)
                 SELECT ?, field_type, label, options, is_required, sort_order
                 FROM form_fields WHERE form_id = ? ORDER BY sort_order, id"
            )->execute([$newId, $parentId]);
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

        $stmt = $conn->prepare("SELECT id, label, field_type, is_required, options FROM form_fields WHERE form_id = ?");
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

        // Per-type required + format validation.
        foreach ($fields as $field) {
            $fid  = (int)$field['id'];
            $val  = array_key_exists($fid, $normalised) ? $normalised[$fid] : '';
            $type = $field['field_type'];

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
     * or blindly stores. Returns rows ready for the positional sync.
     */
    private static function validateFields(array $fields): array
    {
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
            $out[] = [
                'field_type'  => $type,
                'label'       => $label,
                'options'     => $options,
                'is_required' => (int)(bool)($field['is_required'] ?? false),
            ];
        }
        return $out;
    }

    /** The UI's positional field sync: update existing ids in order, append extras, delete trailing leftovers (+ their submission data). */
    private static function syncFields(PDO $conn, int $formId, array $fields): void
    {
        $stmt = $conn->prepare("SELECT id FROM form_fields WHERE form_id = ? ORDER BY sort_order");
        $stmt->execute([$formId]);
        $existingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $upd = $conn->prepare("UPDATE form_fields SET field_type = ?, label = ?, options = ?, is_required = ?, sort_order = ? WHERE id = ?");
        $ins = $conn->prepare("INSERT INTO form_fields (form_id, field_type, label, options, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($fields as $i => $f) {
            if ($i < count($existingIds)) {
                $upd->execute([$f['field_type'], $f['label'], $f['options'], $f['is_required'], $i, $existingIds[$i]]);
            } else {
                $ins->execute([$formId, $f['field_type'], $f['label'], $f['options'], $f['is_required'], $i]);
            }
        }
        foreach (array_slice($existingIds, count($fields)) as $removeId) {
            $conn->prepare("DELETE FROM form_submission_data WHERE field_id = ?")->execute([$removeId]);
            $conn->prepare("DELETE FROM form_fields WHERE id = ?")->execute([$removeId]);
        }
    }
}
