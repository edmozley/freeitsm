<?php
/**
 * Standard changes from approved templates (#180 Part 1 §8).
 *
 * IN PLAIN TERMS
 * --------------
 * A template is a recipe - "Restart the print spooler on a print server" - with its reason,
 * risk, test plan and rollback plan written once. Somebody holding "Approve standard change
 * templates" approves it ONCE. After that, a change raised from the recipe and NOT altered
 * is approved by that earlier decision, and its record says so. Change the recipe's
 * wording on the change and it is not that recipe any more: it goes to normal review.
 *
 * WHY NOT BY TYPE
 * ---------------
 * The first draft of #180 pre-authorised a change because of its type. The requester's
 * point was that choosing a type does not show a particular change is inside the scope
 * that was approved. So a type never pre-authorises anything; only an approved template
 * version does, and the evidence prints that version in full.
 *
 * THE MODEL
 * ---------
 *  change_templates          the template and its WORKING DRAFT (what the Templates tab edits)
 *  change_template_versions  every version submitted for approval - immutable once approved;
 *                            approving N+1 supersedes N, so a change always points at the
 *                            exact words that pre-authorised it
 *  change_template_teams     who may use it (no rows = everyone with Change Management)
 *  changes.template_version_id  the version a change was raised from
 *
 * The whole route sits behind Settings → Approvals → "Standard changes from approved
 * templates" (off by default). Off, templates still pre-fill a change; they never approve one.
 */

require_once __DIR__ . '/approvals.php';
require_once __DIR__ . '/../rbac.php';

final class ChangeTemplates
{
    /**
     * The fields a template sets on a change: change column => [template column, kind].
     * 'long' fields are the rich text; the rest are lookups and risk scores.
     */
    public const FIELDS = [
        'change_type_id'    => ['change_type_id',         'short'],
        'priority_id'       => ['priority_id',            'short'],
        'impact_id'         => ['impact_id',              'short'],
        'category_id'       => ['category_id',            'short'],
        'risk_likelihood'   => ['risk_likelihood',        'short'],
        'risk_impact_score' => ['risk_impact_score',      'short'],
        'description'       => ['description_template',   'long'],
        'reason_for_change' => ['reason_template',        'long'],
        'risk_evaluation'   => ['risk_template',          'long'],
        'test_plan'         => ['test_plan_template',     'long'],
        'rollback_plan'     => ['rollback_plan_template', 'long'],
    ];

    /** The default fields completed per change: the description says WHICH server, WHICH site. */
    public const DEFAULT_OPEN = ['description'];

    /**
     * A value as the fingerprint sees it: empty as null, numbers as strings, and long text
     * by its WORDS. TRAP: by its words, not its markup - the change editor rewrites HTML
     * (plain text comes back wrapped in <p>, entities change), so comparing markup would
     * take every untouched recipe out of pre-authorisation. Tags and spacing are set aside;
     * any change to the words counts.
     *
     * @param mixed $v  a field value
     * @param string $kind  'short' or 'long'
     */
    private static function norm($v, string $kind = 'short'): ?string
    {
        if ($v === null) return null;
        $s = (string)$v;
        if ($kind === 'long') {
            $s = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', ' ', $s);
            $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $s = preg_replace('/[\s\x{00A0}]+/u', ' ', $s);
        }
        $s = trim($s);
        return $s === '' ? null : $s;
    }

    /**
     * The fields a version actually fixes: every field it sets, except those completed per
     * change. A field the template leaves blank is not part of the recipe, so the change
     * may hold anything there (a priority the template did not choose, say).
     *
     * @param array $content  a version's (or the draft's) content
     * @return string[]  change columns
     */
    public static function fixedFields(array $content): array
    {
        $out = [];
        foreach (self::FIELDS as $col => [$_, $kind]) {
            if (in_array($col, $content['open'] ?? [], true)) continue;
            if (self::norm($content['values'][$col] ?? null, $kind) === null) continue;
            $out[] = $col;
        }
        return $out;
    }

    /**
     * The fingerprint of a recipe's FIXED fields - a version's own values, or a change's
     * same fields. A change is still "the recipe" while the two match.
     *
     * @param array $values  change column => value (any superset)
     * @param string[] $fixed  the fields to fingerprint, from fixedFields()
     */
    public static function fixedHash(array $values, array $fixed): string
    {
        $out = [];
        foreach ($fixed as $col) {
            $out[$col] = self::norm($values[$col] ?? null, self::FIELDS[$col][1] ?? 'short');
        }
        ksort($out);
        return hash('sha256', json_encode($out, JSON_UNESCAPED_UNICODE));
    }

    /**
     * The template's working draft as a content array (what a version freezes).
     *
     * @param int $templateId  the change_templates id
     */
    public static function draftContent(PDO $conn, int $templateId): ?array
    {
        $s = $conn->prepare("SELECT * FROM change_templates WHERE id = ?");
        $s->execute([$templateId]);
        $t = $s->fetch(PDO::FETCH_ASSOC);
        if (!$t) return null;
        $values = [];
        foreach (self::FIELDS as $col => [$tcol, $_]) {
            $values[$col] = $t[$tcol] ?? null;
        }
        $teams = $conn->prepare("SELECT team_id FROM change_template_teams WHERE template_id = ? ORDER BY team_id");
        $teams->execute([$templateId]);
        $open = array_values(array_intersect(array_filter(explode(',', (string)($t['open_fields'] ?? ''))), array_keys(self::FIELDS)));
        $content = [
            'name'      => $t['name'],
            'scope_use' => $t['scope_use'] ?? null,
            'scope_not' => $t['scope_not'] ?? null,
            'values'    => $values,
            'open'      => $open,
            'team_ids'  => array_map('intval', $teams->fetchAll(PDO::FETCH_COLUMN)),
        ];
        // The risk policy its ratings were given under (#246). Only when it is a saved one, so a
        // template rated on the built-in 5 x 5 freezes exactly the content it always did.
        if (!empty($t['risk_policy_version_id'])) $content['risk_policy_version_id'] = (int)$t['risk_policy_version_id'];
        return $content;
    }

    /**
     * Submit the working draft for approval: freeze it as version N+1 (state 'draft' until
     * approved). Returns the version id.
     *
     * @param int $templateId  the template
     * @param int $actorId  who submits it (its author, for the independence rule)
     */
    public static function submitVersion(PDO $conn, int $templateId, int $actorId): int
    {
        $content = self::draftContent($conn, $templateId);
        if ($content === null) throw new ServiceError('not_found', 'not_found', 'Template not found.');
        // A version already waiting for approval is replaced, not stacked.
        $conn->prepare("DELETE FROM change_template_versions WHERE template_id = ? AND state = 'draft'")->execute([$templateId]);
        $n = $conn->prepare("SELECT COALESCE(MAX(version_no), 0) + 1 FROM change_template_versions WHERE template_id = ?");
        $n->execute([$templateId]);
        $conn->prepare(
            "INSERT INTO change_template_versions (template_id, version_no, state, content, content_hash, created_by_id, created_datetime)
             VALUES (?, ?, 'draft', ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$templateId, (int)$n->fetchColumn(), json_encode($content, JSON_UNESCAPED_UNICODE),
                    self::fixedHash($content['values'], self::fixedFields($content)), $actorId ?: null]);
        return (int)$conn->lastInsertId();
    }

    /**
     * Approve a submitted version. Needs "Approve standard change templates"; the version's
     * author cannot approve it (administrators included - separation of duties is not a
     * power), unless Settings allows approving your own, which the record then shows.
     *
     * @param int $versionId  the change_template_versions id, in state 'draft'
     * @param int $actorId  the approver
     * @param string $note  the approval note ("Approved at CAB, 12 Oct, minute 4")
     */
    public static function approveVersion(PDO $conn, int $versionId, int $actorId, string $note): void
    {
        if (!analystMayAct($conn, $actorId, Cap::CHANGES_TEMPLATES_APPROVE)) {
            throw new ServiceError('forbidden', 'forbidden', t('change-management.templates.cannot_approve'));
        }
        $s = $conn->prepare("SELECT * FROM change_template_versions WHERE id = ? FOR UPDATE");
        $s->execute([$versionId]);
        $v = $s->fetch(PDO::FETCH_ASSOC);
        if (!$v || $v['state'] !== 'draft') {
            throw new ServiceError('conflict', 'not_awaiting_approval', t('change-management.templates.not_awaiting'));
        }
        $cfg = changeApprovalSettings($conn);
        if ((int)$v['created_by_id'] === $actorId && !$cfg['own_allowed']) {
            throw new ServiceError('forbidden', 'not_independent', t('change-management.templates.not_independent'));
        }
        $conn->prepare("UPDATE change_template_versions SET state = 'superseded' WHERE template_id = ? AND state = 'approved'")
             ->execute([(int)$v['template_id']]);
        $conn->prepare(
            "UPDATE change_template_versions SET state = 'approved', approved_by_id = ?, approved_datetime = UTC_TIMESTAMP(), approval_note = ? WHERE id = ?"
        )->execute([$actorId, trim($note) !== '' ? trim($note) : null, $versionId]);
        $conn->prepare("UPDATE change_templates SET current_version_id = ?, retired_datetime = NULL WHERE id = ?")
             ->execute([$versionId, (int)$v['template_id']]);
    }

    /**
     * Retire a template: no new change may use it. Changes already approved by it keep
     * their approval - it was valid when given.
     *
     * @param int $templateId  the template
     */
    public static function retire(PDO $conn, int $templateId): void
    {
        $conn->prepare("UPDATE change_template_versions SET state = 'retired' WHERE template_id = ? AND state IN ('approved', 'draft')")
             ->execute([$templateId]);
        $conn->prepare("UPDATE change_templates SET retired_datetime = UTC_TIMESTAMP(), current_version_id = NULL WHERE id = ?")
             ->execute([$templateId]);
    }

    /**
     * May this analyst use this version (Who may use it)? No teams named = everyone with
     * Change Management.
     *
     * @param array $content  the version's decoded content
     * @param int $analystId  the analyst
     */
    public static function mayUse(PDO $conn, array $content, int $analystId): bool
    {
        $teams = $content['team_ids'] ?? [];
        if (!$teams) return true;
        if ($analystId <= 0) return false;
        $in = implode(',', array_map('intval', $teams));
        $s = $conn->prepare("SELECT 1 FROM analyst_teams WHERE analyst_id = ? AND team_id IN ($in) LIMIT 1");
        $s->execute([$analystId]);
        return (bool)$s->fetchColumn();
    }

    /**
     * One version with its template's name and its approver, or null.
     *
     * @param int $versionId  the change_template_versions id
     */
    public static function version(PDO $conn, int $versionId): ?array
    {
        $s = $conn->prepare(
            "SELECT v.*, t.name AS template_name, t.retired_datetime, ap.full_name AS approved_by_name, cb.full_name AS created_by_name
               FROM change_template_versions v
               JOIN change_templates t ON t.id = v.template_id
               LEFT JOIN analysts ap ON ap.id = v.approved_by_id
               LEFT JOIN analysts cb ON cb.id = v.created_by_id
              WHERE v.id = ?"
        );
        $s->execute([$versionId]);
        $v = $s->fetch(PDO::FETCH_ASSOC);
        if (!$v) return null;
        $v['content'] = json_decode((string)$v['content'], true) ?: [];
        return $v;
    }

    /**
     * Does this change's template pre-authorise it, right now? Every one of these must hold
     * (§8): the switch is on; the version is still approved; the person submitting may use
     * it; and the fixed fields still match the version word for word. Returns
     * ['ok' => bool, 'reason' => a code when not, 'version' => the version or null].
     *
     * @param array $row  the changes row as it stands
     * @param array $cfg  changeApprovalSettings()
     * @param int $actorId  who is submitting it
     */
    public static function check(PDO $conn, array $row, array $cfg, int $actorId): array
    {
        $vid = isset($row['template_version_id']) ? (int)$row['template_version_id'] : 0;
        if ($vid <= 0) return ['ok' => false, 'reason' => 'no_template', 'version' => null];
        $v = self::version($conn, $vid);
        if (!$v) return ['ok' => false, 'reason' => 'no_template', 'version' => null];
        if (!$cfg['templates_enabled']) return ['ok' => false, 'reason' => 'switched_off', 'version' => $v];
        if ($v['state'] !== 'approved' || $v['retired_datetime'] !== null) return ['ok' => false, 'reason' => 'version_not_current', 'version' => $v];
        if (!self::mayUse($conn, $v['content'], $actorId)) return ['ok' => false, 'reason' => 'not_allowed', 'version' => $v];
        if (!hash_equals($v['content_hash'], self::fixedHash($row, self::fixedFields($v['content'])))) {
            return ['ok' => false, 'reason' => 'changed', 'version' => $v,
                    'changed_fields' => self::changedFields($row, $v['content'])];
        }
        // The same numbers on a different risk scale are not what was approved (#246).
        $riskFixed = array_values(array_intersect(self::fixedFields($v['content']), ['risk_likelihood', 'risk_impact_score']));
        $rowVersion = isset($row['risk_policy_version_id']) && $row['risk_policy_version_id'] !== null ? (int)$row['risk_policy_version_id'] : null;
        if ($riskFixed && !self::riskPolicyMatches($v['content'], $rowVersion)) {
            return ['ok' => false, 'reason' => 'changed', 'version' => $v, 'changed_fields' => $riskFixed];
        }
        return ['ok' => true, 'reason' => null, 'version' => $v];
    }

    /**
     * Which fixed fields of the change no longer match the version - for "the rollback plan
     * was changed, so it needs normal approval".
     *
     * @param array $row  the changes row
     * @param array $content  the version's content
     */
    public static function changedFields(array $row, array $content): array
    {
        $out = [];
        foreach (self::fixedFields($content) as $col) {
            $kind = self::FIELDS[$col][1];
            if (self::norm($row[$col] ?? null, $kind) !== self::norm($content['values'][$col] ?? null, $kind)) $out[] = $col;
        }
        return $out;
    }

    /**
     * Were a template version's ratings given under this risk policy version (#246)? A version
     * frozen before risk policies, or rated on the built-in, has no key: the built-in.
     *
     * @param array $content  a version's content
     * @param ?int $policyVersionId  the policy version to compare with; null = the built-in
     */
    public static function riskPolicyMatches(array $content, ?int $policyVersionId): bool
    {
        $own = isset($content['risk_policy_version_id']) ? (int)$content['risk_policy_version_id'] : null;
        return $own === $policyVersionId;
    }

    /**
     * The approved templates this analyst may raise a change from, with their scope.
     *
     * @param int $analystId  the analyst
     */
    public static function usable(PDO $conn, int $analystId): array
    {
        $rows = $conn->query(
            "SELECT v.id AS version_id, v.version_no, t.id AS template_id, t.name
               FROM change_templates t JOIN change_template_versions v ON v.id = t.current_version_id
              WHERE v.state = 'approved' AND t.retired_datetime IS NULL
              ORDER BY t.display_order, t.name"
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $v = self::version($conn, (int)$r['version_id']);
            if (!$v || !self::mayUse($conn, $v['content'], $analystId)) continue;
            $out[] = [
                'template_id' => (int)$r['template_id'],
                'version_id'  => (int)$r['version_id'],
                'version_no'  => (int)$r['version_no'],
                'name'        => $r['name'],
                'scope_use'   => $v['content']['scope_use'] ?? null,
                'scope_not'   => $v['content']['scope_not'] ?? null,
                'values'      => $v['content']['values'] ?? [],
                'open'        => $v['content']['open'] ?? [],
                'risk_policy_version_id' => $v['content']['risk_policy_version_id'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * The decision note a pre-authorised round carries: the template, the version, and who
     * approved it when - the answer to "what made this change pre-approved?".
     *
     * @param array $v  a version()
     */
    public static function decisionNote(array $v): string
    {
        return 'Pre-authorised by template "' . $v['template_name'] . '" v' . (int)$v['version_no']
            . ', approved by ' . ($v['approved_by_name'] ?: 'unknown') . ' on ' . substr((string)$v['approved_datetime'], 0, 10) . '.';
    }
}
