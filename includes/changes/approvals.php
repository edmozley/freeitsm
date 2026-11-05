<?php
/**
 * Change approvals - the rules that decide where a change may go, and the stage
 * of each status that those rules are written against.
 *
 * WHY STAGES
 * ----------
 * Statuses are rows an administrator can rename (Settings → Statuses). Code that
 * looked for the name 'Approved' or 'Pending Approval' was one rename away from
 * switching itself off - rename Approved to Authorised and a deciding CAB vote set
 * the change's status to NULL. So every status carries a STAGE, chosen in Settings,
 * and the code only ever asks about the stage:
 *
 *   draft         being written                     Draft, Submitted
 *   awaiting      submitted; waiting on a decision  Pending Approval
 *   approved      authorised, not started           Approved
 *   implementing  the work is happening             Scheduled, In Progress
 *   done          finished                          Completed, Failed
 *   stopped       ended without implementing        Rejected, Cancelled
 *
 * NULL means "not classified yet" - a custom status, or a seeded one renamed before
 * stages existed. It is shown loudly in Settings rather than guessed, because
 * guessing "draft" for an unknown "Authorised" would hand everybody the one status
 * approval exists to protect.
 *
 * Design: wiki Approvals-and-Evidence-1-Change-Approvals §3.
 */

require_once __DIR__ . '/../i18n.php';   // t() for the refusal messages

/** Every stage, in lifecycle order. TRAP: the order is the order shown in Settings. */
const CHANGE_STAGES = ['draft', 'awaiting', 'approved', 'implementing', 'done', 'stopped'];

/** Stages that must always keep at least one active status, because code moves changes into them. */
const CHANGE_STAGES_REQUIRED = ['draft', 'awaiting', 'approved'];

/** Stages whose statuses are closed (finished) - is_closed follows the stage. */
const CHANGE_STAGES_CLOSED = ['done', 'stopped'];

/**
 * The stage each SEEDED status name belongs to. Used once per status, to classify a
 * status that has no stage yet; never to read a stage back.
 */
const CHANGE_STAGE_SEED_NAMES = [
    'Draft'            => 'draft',
    'Submitted'        => 'draft',
    'Pending Approval' => 'awaiting',
    'Approved'         => 'approved',
    'Scheduled'        => 'implementing',
    'In Progress'      => 'implementing',
    'Completed'        => 'done',
    'Failed'           => 'done',
    'Rejected'         => 'stopped',
    'Cancelled'        => 'stopped',
];

/**
 * Is this a real stage key? NULL (unclassified) is not.
 *
 * @param mixed $stage  a value from a request or a row
 */
function changeStageIsValid($stage): bool
{
    return is_string($stage) && in_array($stage, CHANGE_STAGES, true);
}

/**
 * The status a change is moved to when the code needs "the X stage": the first
 * active status in that stage by display order. Null when the stage has none, which
 * Settings prevents for the required stages (CHANGE_STAGES_REQUIRED).
 *
 * @param string $stage  one of CHANGE_STAGES
 * @return ?array  ['id' => int, 'name' => string] or null
 */
function changeStatusForStage(PDO $conn, string $stage): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, name FROM change_statuses
          WHERE stage = ? AND is_active = 1
          ORDER BY display_order, id LIMIT 1"
    );
    $stmt->execute([$stage]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ['id' => (int)$row['id'], 'name' => $row['name']] : null;
}

/**
 * The required stages (CHANGE_STAGES_REQUIRED) that currently have no active status.
 * Settings refuses any save or delete that would leave one empty.
 *
 * @return string[]  stage keys, empty when all is well
 */
function changeStagesWithoutActiveStatus(PDO $conn): array
{
    $have = $conn->query("SELECT DISTINCT stage FROM change_statuses WHERE is_active = 1 AND stage IS NOT NULL")
                 ->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_diff(CHANGE_STAGES_REQUIRED, $have));
}

/**
 * The refusal shown when a save or delete would empty a required stage.
 *
 * @param string[] $stages  stage keys from changeStagesWithoutActiveStatus()
 */
function changeStagesMissingMessage(array $stages): string
{
    $names = array_map(fn($s) => t('change-management.stage.' . $s), $stages);
    return t('change-management.settings.stage_required_error', ['stages' => implode(', ', $names)]);
}

/**
 * Where Cancel moves a change: the Stopped-stage status chosen in Settings → Approvals,
 * if it is still active, else the LAST active one by display order. Last, not first:
 * the stage cannot tell "Rejected" from "Cancelled", and on a seeded install Rejected
 * comes first - a cancelled change must not read as rejected.
 *
 * @param array $cfg  changeApprovalSettings()
 */
function changeCancelTarget(PDO $conn, array $cfg): ?array
{
    if ($cfg['cancel_status_id']) {
        $s = $conn->prepare("SELECT id, name FROM change_statuses WHERE id = ? AND is_active = 1 AND stage = 'stopped'");
        $s->execute([$cfg['cancel_status_id']]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if ($r) return ['id' => (int)$r['id'], 'name' => $r['name']];
    }
    $r = $conn->query("SELECT id, name FROM change_statuses WHERE stage = 'stopped' AND is_active = 1 ORDER BY display_order DESC, id DESC LIMIT 1")
              ->fetch(PDO::FETCH_ASSOC);
    return $r ? ['id' => (int)$r['id'], 'name' => $r['name']] : null;
}

/**
 * Classify every status that has no stage but still carries a seeded name. Called by
 * Database Verify on every run - idempotent, and it never overrides a stage somebody
 * chose. A renamed or custom status stays NULL for an administrator to decide.
 * Returns how many rows it classified.
 */
function changeStagesBackfill(PDO $conn): int
{
    $n = 0;
    $stmt = $conn->prepare("UPDATE change_statuses SET stage = ? WHERE stage IS NULL AND name = ?");
    foreach (CHANGE_STAGE_SEED_NAMES as $name => $stage) {
        $stmt->execute([$stage, $name]);
        $n += $stmt->rowCount();
    }
    return $n;
}


// ===========================================================================
//  Approval settings (Change Management → Settings → Approvals)
// ===========================================================================

/**
 * Every approval setting, as stored in system_settings, with its default. TRAP: each
 * default is TODAY's behaviour - upgrading changes nothing anybody can see until an
 * administrator chooses otherwise (#180 design, §1 "nothing changes until you choose it").
 */
const CHANGE_APPROVAL_DEFAULTS = [
    'change_approval_mode'             => 'record',      // record | enforce
    'change_approval_own_allowed'      => '0',           // may the change's own people approve it? (Record: only flags it)
    'change_approval_own_people'       => 'requester,assignee,creator',
    'change_approval_override'         => '0',           // administrator override with a written reason
    'change_approval_material_fields'  => 'description,reason_for_change,risk_evaluation,risk_likelihood,risk_impact_score,test_plan,rollback_plan,change_type_id,impact_id',
    'change_approval_reject_status_id' => '',            // '' = the first draft-stage status (today)
    'change_approval_cancel_status_id' => '',            // '' = the LAST stopped-stage status (Cancelled on a seeded install)
    'change_approval_choose_approvers' => 'anyone',      // anyone | permission
    'change_approval_edit_after'       => 'keep',        // Record mode: keep (and mark) | withdraw
    'change_approval_stale_vote'       => 'record',      // Record mode: record (and mark) | refuse
    'change_approval_delete_submitted' => 'allowed',     // Record mode: allowed | refuse
    'change_templates_enabled'         => '0',           // standard changes from approved templates
];

/** Who can be "the change's own people", in the order Settings shows them. */
const CHANGE_APPROVAL_OWN_CHOICES = ['requester' => 'requester_id', 'assignee' => 'assigned_to_id', 'creator' => 'created_by_id'];

/**
 * The fields an administrator may tick as material ("edits that need a fresh approval"):
 * key => [kind, columns]. 'long' fields are fingerprinted by the sha256 of their text and
 * point at a revision; the rest by value. The PIR fields are never offered: they are
 * written after the work, and a review that reopened an approval would be absurd.
 */
const CHANGE_MATERIAL_CHOICES = [
    'title'             => ['short',  ['title']],
    'description'       => ['long',   ['description']],
    'reason_for_change' => ['long',   ['reason_for_change']],
    'risk_evaluation'   => ['long',   ['risk_evaluation']],
    'risk_likelihood'   => ['short',  ['risk_likelihood']],
    'risk_impact_score' => ['short',  ['risk_impact_score']],
    'test_plan'         => ['long',   ['test_plan']],
    'rollback_plan'     => ['long',   ['rollback_plan']],
    'change_type_id'    => ['short',  ['change_type_id']],
    'impact_id'         => ['short',  ['impact_id']],
    'priority_id'       => ['short',  ['priority_id']],
    'assigned_to_id'    => ['short',  ['assigned_to_id']],
    'work_window'       => ['short',  ['work_start_datetime', 'work_end_datetime']],
    'outage_window'     => ['short',  ['outage_start_datetime', 'outage_end_datetime']],
];

/**
 * The approval settings, read once per request and normalised. Unknown or missing
 * values fall back to the defaults, so a half-saved tab can never switch a rule on.
 *
 * @param bool $fresh  true to re-read (after a save, and in tests)
 */
function changeApprovalSettings(PDO $conn, bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    $raw = CHANGE_APPROVAL_DEFAULTS;
    try {
        $keys = array_keys(CHANGE_APPROVAL_DEFAULTS);
        $in = implode(',', array_fill(0, count($keys), '?'));
        $s = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $s->execute($keys);
        foreach ($s->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            if ($v !== null) $raw[$k] = (string)$v;
        }
    } catch (Exception) { /* system_settings unreadable: defaults, i.e. today's behaviour */ }

    $list = fn(string $v, array $allowed) => array_values(array_intersect(array_filter(array_map('trim', explode(',', $v))), $allowed));
    $pick = fn(string $v, array $allowed, string $default) => in_array($v, $allowed, true) ? $v : $default;

    $cfg = [
        'mode'              => $pick($raw['change_approval_mode'], ['record', 'enforce'], 'record'),
        'own_allowed'       => $raw['change_approval_own_allowed'] === '1',
        'own_people'        => $list($raw['change_approval_own_people'], array_keys(CHANGE_APPROVAL_OWN_CHOICES)),
        'override'          => $raw['change_approval_override'] === '1',
        'material'          => $list($raw['change_approval_material_fields'], array_keys(CHANGE_MATERIAL_CHOICES)),
        'reject_status_id'  => ctype_digit($raw['change_approval_reject_status_id']) ? (int)$raw['change_approval_reject_status_id'] : null,
        'cancel_status_id'  => ctype_digit($raw['change_approval_cancel_status_id']) ? (int)$raw['change_approval_cancel_status_id'] : null,
        'choose_approvers'  => $pick($raw['change_approval_choose_approvers'], ['anyone', 'permission'], 'anyone'),
        'edit_after'        => $pick($raw['change_approval_edit_after'], ['keep', 'withdraw'], 'keep'),
        'stale_vote'        => $pick($raw['change_approval_stale_vote'], ['record', 'refuse'], 'record'),
        'delete_submitted'  => $pick($raw['change_approval_delete_submitted'], ['allowed', 'refuse'], 'allowed'),
        'templates_enabled' => $raw['change_templates_enabled'] === '1',
    ];
    $cfg['enforce'] = $cfg['mode'] === 'enforce';
    // TRAP: in Enforce mode these three are not choices. Each of them, switched the
    // other way, would be a way round the approval Enforce exists to guarantee.
    $cfg['edit_withdraws'] = $cfg['enforce'] || $cfg['edit_after'] === 'withdraw';
    $cfg['stale_refused']  = $cfg['enforce'] || $cfg['stale_vote'] === 'refuse';
    $cfg['delete_refused'] = $cfg['enforce'] || $cfg['delete_submitted'] === 'refuse';
    return $cache = $cfg;
}

// ===========================================================================
//  The content fingerprint - what an approval was given against
// ===========================================================================

/**
 * The material fields of a change, canonically: short fields by value, long fields by
 * the sha256 of their text. Sorted by key so the same content always serialises the same.
 *
 * @param array $row  a changes row (any superset)
 * @param string[] $material  keys of CHANGE_MATERIAL_CHOICES
 */
function changeApprovalMaterialValues(array $row, array $material): array
{
    $out = [];
    foreach ($material as $key) {
        if (!isset(CHANGE_MATERIAL_CHOICES[$key])) continue;
        [$kind, $cols] = CHANGE_MATERIAL_CHOICES[$key];
        $vals = [];
        foreach ($cols as $col) {
            $v = $row[$col] ?? null;
            $vals[] = $kind === 'long' ? hash('sha256', (string)($v ?? '')) : ($v === null ? null : (string)$v);
        }
        $out[$key] = count($vals) === 1 ? $vals[0] : $vals;
    }
    // A rating means nothing without its scale (#246): "3 of 5" re-assessed as "3 of 4"
    // is a material change even though the number is the same. TRAP: added only when the
    // change is on a saved policy - the built-in is NULL, so every fingerprint taken
    // before risk policies existed is still the same and no approval reads as edited.
    if ((in_array('risk_likelihood', $material, true) || in_array('risk_impact_score', $material, true))
        && ($row['risk_policy_version_id'] ?? null) !== null) {
        $out['risk_policy_version_id'] = (string)$row['risk_policy_version_id'];
    }
    ksort($out);
    return $out;
}

/**
 * The fingerprint (sha256) of a change's material content. The page that shows an
 * approval carries it, the vote sends it back, and the server compares (§5 "a vote is
 * cast against the version on screen"). Read-only: it never writes a revision.
 *
 * @param array $row  a changes row
 * @param string[] $material  the material field keys to fingerprint
 */
function changeApprovalContentHash(array $row, array $material): string
{
    return hash('sha256', json_encode(changeApprovalMaterialValues($row, $material), JSON_UNESCAPED_UNICODE));
}

/**
 * The snapshot a round keeps of exactly what it was asked to approve: the material
 * values, and for each long field the id of the revision holding its text (so the
 * evidence can print the words, and "what changed since" can diff them).
 *
 * @param array $row  the changes row as it stands
 * @param array $cfg  changeApprovalSettings()
 */
function changeApprovalSnapshot(PDO $conn, array $row, array $cfg): array
{
    require_once __DIR__ . '/revisions.php';
    $revisions = [];
    foreach ($cfg['material'] as $key) {
        if ((CHANGE_MATERIAL_CHOICES[$key][0] ?? '') === 'long') {
            $revisions[$key] = ChangeRevisions::currentId($conn, (int)$row['id'], $key, $row[$key] ?? null);
        }
    }
    return [
        'material'            => $cfg['material'],
        'values'              => changeApprovalMaterialValues($row, $cfg['material']),
        'revisions'           => $revisions,
        'template_version_id' => isset($row['template_version_id']) && $row['template_version_id'] !== null ? (int)$row['template_version_id'] : null,
    ];
}

// ===========================================================================
//  Rounds
// ===========================================================================

/**
 * The route a round takes when a change is submitted: the board votes (cab), one named
 * person decides (approver), or nobody is set up (manual - in Record mode a hand-set
 * Approved will be recorded; in Enforce mode submission is refused).
 *
 * @param array $row  the changes row
 */
function changeApprovalRoute(array $row): string
{
    if (!empty($row['cab_required'])) return 'cab';
    if (!empty($row['approver_id']))  return 'approver';
    return 'manual';
}

/**
 * The analysts who count as the change's own people (Settings: requester, assignee,
 * creator), taken from the row at the moment of the decision.
 *
 * @param array $row  the changes row
 * @param array $cfg  changeApprovalSettings()
 * @return int[]
 */
function changeApprovalOwnPeople(array $row, array $cfg): array
{
    $ids = [];
    foreach ($cfg['own_people'] as $who) {
        $col = CHANGE_APPROVAL_OWN_CHOICES[$who] ?? null;
        if ($col && !empty($row[$col])) $ids[] = (int)$row[$col];
    }
    return array_values(array_unique($ids));
}

/**
 * Is this analyst independent of the change - not one of its own people? TRAP: an
 * administrator is NOT exempt. is_admin bypasses permissions everywhere else; it must
 * not bypass separation of duties, or "make me an admin" becomes the way round it.
 *
 * @param array $row  the changes row
 * @param int $analystId  who is voting or deciding
 * @param array $cfg  changeApprovalSettings()
 */
function changeApprovalIsIndependent(array $row, int $analystId, array $cfg): bool
{
    return !in_array($analystId, changeApprovalOwnPeople($row, $cfg), true);
}

/**
 * Open a new round for a change (state open) and make it the change's current one.
 * Returns the round's id.
 *
 * @param array $row  the changes row as it stands (after the save)
 * @param string $route  cab | approver | manual | emergency
 * @param ?int $actorId  who submitted it
 * @param array $cfg  changeApprovalSettings()
 */
function changeApprovalOpenRound(PDO $conn, array $row, string $route, ?int $actorId, array $cfg): int
{
    return changeApprovalInsertRound($conn, $row, $route, 'open', $actorId, $cfg);
}

/**
 * Record a round that is decided the moment it exists: a hand-set Approved (manual), an
 * administrator override, a pre-authorised template. Returns its id.
 *
 * @param array $row  the changes row as it stands
 * @param string $route  manual | override | pre_authorised
 * @param ?int $actorId  who decided it (null for pre_authorised - nobody did, the template did)
 * @param ?string $note  the decision note (the override's reason, the template's name)
 * @param array $cfg  changeApprovalSettings()
 */
function changeApprovalRecordApproved(PDO $conn, array $row, string $route, ?int $actorId, ?string $note, array $cfg): int
{
    $id = changeApprovalInsertRound($conn, $row, $route, 'approved', $actorId, $cfg);
    $independent = $actorId === null ? null : (changeApprovalIsIndependent($row, $actorId, $cfg) ? 1 : 0);
    $conn->prepare(
        "UPDATE change_approvals SET decided_by_id = ?, decided_datetime = UTC_TIMESTAMP(), decision_note = ?, is_independent = ?
          WHERE id = ?"
    )->execute([$actorId, $note, $independent, $id]);
    return $id;
}

/**
 * The insert behind both of the above.
 *
 * @param array $row  the changes row
 * @param string $route  the round's route
 * @param string $state  open | approved
 * @param ?int $actorId  who opened it
 * @param array $cfg  changeApprovalSettings()
 */
function changeApprovalInsertRound(PDO $conn, array $row, string $route, string $state, ?int $actorId, array $cfg): int
{
    $changeId = (int)$row['id'];
    $n = $conn->prepare("SELECT COALESCE(MAX(round_no), 0) + 1 FROM change_approvals WHERE change_id = ?");
    $n->execute([$changeId]);
    $roundNo = (int)$n->fetchColumn();
    $snapshot = changeApprovalSnapshot($conn, $row, $cfg);
    $conn->prepare(
        "INSERT INTO change_approvals (change_id, round_no, route, state, content_hash, snapshot, opened_by_id, opened_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    )->execute([$changeId, $roundNo, $route, $state, changeApprovalContentHash($row, $cfg['material']),
                json_encode($snapshot, JSON_UNESCAPED_UNICODE), $actorId ?: null]);
    $id = (int)$conn->lastInsertId();
    $conn->prepare("UPDATE changes SET approval_id = ? WHERE id = ?")->execute([$id, $changeId]);
    return $id;
}

/**
 * Close a round. approved/rejected record who decided and when; withdrawn/superseded
 * record why, in words. A round that is no longer open stops being the change's current
 * one unless it was approved (an approved round stays current until superseded).
 *
 * @param int $roundId  the change_approvals row
 * @param string $state  approved | rejected | withdrawn | superseded
 * @param ?string $reason  why it was withdrawn or superseded, e.g. "Test plan edited by Sam"
 * @param ?int $decidedBy  who decided it (approved / rejected)
 * @param ?string $note  the decision note
 * @param ?bool $independent  whether the decision was independent, when one was made
 */
function changeApprovalClose(PDO $conn, int $roundId, string $state, ?string $reason = null, ?int $decidedBy = null,
                             ?string $note = null, ?bool $independent = null): void
{
    if (in_array($state, ['approved', 'rejected'], true)) {
        $conn->prepare(
            "UPDATE change_approvals
                SET state = ?, decided_by_id = ?, decided_datetime = UTC_TIMESTAMP(), decision_note = ?, is_independent = ?
              WHERE id = ?"
        )->execute([$state, $decidedBy ?: null, $note, $independent === null ? null : ($independent ? 1 : 0), $roundId]);
    } else {
        $conn->prepare("UPDATE change_approvals SET state = ?, closed_reason = ? WHERE id = ?")
             ->execute([$state, $reason, $roundId]);
    }
    if ($state !== 'approved') {
        $conn->prepare("UPDATE changes SET approval_id = NULL WHERE approval_id = ?")->execute([$roundId]);
    }
}

/**
 * The change's current round (open, or approved and still standing), or null.
 *
 * @param int $changeId  the change
 */
function changeApprovalCurrent(PDO $conn, int $changeId): ?array
{
    $s = $conn->prepare(
        "SELECT a.* FROM changes c JOIN change_approvals a ON a.id = c.approval_id WHERE c.id = ?"
    );
    $s->execute([$changeId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Has this change ever been submitted - does it have any round at all? A change with
 * one is evidence and, where the settings say so, cannot be deleted (§4).
 *
 * @param int $changeId  the change
 */
function changeApprovalHasHistory(PDO $conn, int $changeId): bool
{
    $s = $conn->prepare("SELECT 1 FROM change_approvals WHERE change_id = ? LIMIT 1");
    $s->execute([$changeId]);
    return (bool)$s->fetchColumn();
}

/**
 * The approval as the page and the API show it: the current round, the live version the
 * page must send back with a vote, and whether the content has moved on since the round
 * was opened ("approved against an earlier version" / "edited while awaiting approval").
 *
 * @param array $row  the changes row
 * @param array $cfg  changeApprovalSettings()
 */
function changeApprovalLiveState(PDO $conn, array $row, array $cfg): array
{
    $round = !empty($row['approval_id']) ? changeApprovalCurrent($conn, (int)$row['id']) : null;
    $out = [
        'id'              => null,
        'round_no'        => null,
        'route'           => null,
        'state'           => null,
        'is_independent'  => null,
        'current_version' => changeApprovalContentHash($row, $cfg['material']),
        'content_changed' => false,
        'changed_fields'  => [],     // long field => the revision the round was given, for "see what changed"
        'mode'            => $cfg['mode'],
    ];
    if (!$round) {
        return $out;
    }
    $snap = json_decode((string)$round['snapshot'], true) ?: [];
    $roundMaterial = $snap['material'] ?? $cfg['material'];
    $out['id']             = (int)$round['id'];
    $out['round_no']       = (int)$round['round_no'];
    $out['route']          = $round['route'];
    $out['state']          = $round['state'];
    $out['is_independent'] = $round['is_independent'] === null ? null : (bool)$round['is_independent'];
    // Compared on the round's OWN material list, so changing the ticks in Settings
    // does not retroactively mark every approval "earlier version".
    $out['content_changed'] = $round['content_hash'] !== null
        && $round['content_hash'] !== changeApprovalContentHash($row, $roundMaterial);
    if ($out['content_changed']) {
        $then = $snap['values'] ?? [];
        $now  = changeApprovalMaterialValues($row, $roundMaterial);
        foreach ($snap['revisions'] ?? [] as $field => $revId) {
            if ($revId && ($then[$field] ?? null) !== ($now[$field] ?? null)) {
                $out['changed_fields'][$field] = (int)$revId;
            }
        }
    }
    return $out;
}

/**
 * Every round of a change, oldest first, each with its votes and the names behind them.
 * For the Approval panel, the evidence export and GET /changes/{id}/approvals.
 *
 * @param int $changeId  the change
 */
function changeApprovalRounds(PDO $conn, int $changeId): array
{
    $s = $conn->prepare(
        "SELECT a.*, ob.full_name AS opened_by_name, db.full_name AS decided_by_name
           FROM change_approvals a
           LEFT JOIN analysts ob ON ob.id = a.opened_by_id
           LEFT JOIN analysts db ON db.id = a.decided_by_id
          WHERE a.change_id = ? ORDER BY a.round_no"
    );
    $s->execute([$changeId]);
    $rounds = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rounds[(int)$r['id']] = [
            'id'              => (int)$r['id'],
            'round_no'        => (int)$r['round_no'],
            'route'           => $r['route'],
            'state'           => $r['state'],
            'is_independent'  => $r['is_independent'] === null ? null : (bool)$r['is_independent'],
            'content_hash'    => $r['content_hash'],
            'snapshot'        => $r['snapshot'] !== null ? json_decode($r['snapshot'], true) : null,
            'opened_by_id'    => $r['opened_by_id'] !== null ? (int)$r['opened_by_id'] : null,
            'opened_by_name'  => $r['opened_by_name'],
            'opened_at'       => $r['opened_datetime'],
            'decided_by_id'   => $r['decided_by_id'] !== null ? (int)$r['decided_by_id'] : null,
            'decided_by_name' => $r['decided_by_name'],
            'decided_at'      => $r['decided_datetime'],
            'decision_note'   => $r['decision_note'],
            'closed_reason'   => $r['closed_reason'],
            'votes'           => [],
        ];
    }
    if ($rounds) {
        $in = implode(',', array_keys($rounds));
        $v = $conn->query(
            "SELECT v.*, an.full_name AS analyst_name FROM change_approval_votes v
               LEFT JOIN analysts an ON an.id = v.analyst_id
              WHERE v.approval_id IN ($in) ORDER BY v.id"
        );
        foreach ($v->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rounds[(int)$row['approval_id']]['votes'][] = [
                'id'           => (int)$row['id'],
                'analyst_id'   => $row['analyst_id'] !== null ? (int)$row['analyst_id'] : null,
                'analyst_name' => $row['analyst_name'],
                'vote'         => $row['vote'],
                'comment'      => $row['comment'],
                'is_required'  => (bool)$row['is_required'],
                'is_counted'   => (bool)$row['is_counted'],
                'content_hash' => $row['content_hash'],
                'was_current'  => $row['was_current'] === null ? null : (bool)$row['was_current'],
                'voted_at'     => $row['voted_datetime'],
            ];
        }
    }
    return array_values($rounds);
}

/**
 * Check that a vote or decision was made against the version on screen (§5). Returns
 * whether it was current; throws approval_stale where the settings refuse a stale one,
 * and missing_field where they require the version and it was not sent.
 *
 * TRAP: $sentHash must be what the page was DRAWN with. A client that re-fetched the
 * change just before voting would turn this into "approve whatever is current".
 *
 * @param array $row  the changes row, read under the row lock
 * @param ?array $round  the change's current round
 * @param mixed $sentRoundId  approval_id from the request
 * @param mixed $sentHash  content_hash from the request
 * @param array $cfg  changeApprovalSettings()
 * @return ?bool  true current, false out of date (recorded), null not stated (Record, API)
 */
function changeApprovalCheckVersion(array $row, ?array $round, $sentRoundId, $sentHash, array $cfg): ?bool
{
    $stated = $sentRoundId !== null && $sentRoundId !== '' && is_string($sentHash) && $sentHash !== '';
    if (!$stated) {
        if ($cfg['stale_refused']) {
            throw new ServiceError('bad_request', 'missing_field',
                "'approval_id' and 'content_hash' are required: send the values from the change you read.");
        }
        return null;
    }
    $current = $round && (int)$sentRoundId === (int)$round['id']
        && hash_equals(changeApprovalContentHash($row, $cfg['material']), $sentHash);
    if (!$current && $cfg['stale_refused']) {
        throw new ServiceError('conflict', 'approval_stale', t('change-management.approval.stale_refused'));
    }
    return $current;
}

/**
 * Give the changes that existed before approval records a round each (§8, Legacy), so
 * switching Enforce on later does not strand work in flight: an approved-or-later change
 * gets an approved 'legacy' round (no snapshot - nothing is known about what was
 * approved), and a change awaiting approval gets an open 'legacy' round with its existing
 * votes copied, so voting carries on where it was. Only for changes with NO round, and
 * only called on the Database Verify run that creates change_approvals.
 *
 * @return int[]  [approved rounds written, open rounds written]
 */
function changeApprovalBackfillLegacy(PDO $conn): array
{
    $rows = $conn->query(
        "SELECT c.id, c.approval_datetime, cs.stage FROM changes c
           JOIN change_statuses cs ON cs.id = c.status_id
          WHERE cs.stage IN ('awaiting', 'approved', 'implementing', 'done')
            AND NOT EXISTS (SELECT 1 FROM change_approvals a WHERE a.change_id = c.id)"
    )->fetchAll(PDO::FETCH_ASSOC);
    $approved = 0; $open = 0;
    $ins = $conn->prepare(
        "INSERT INTO change_approvals (change_id, round_no, route, state, opened_datetime, decided_datetime, decision_note)
         VALUES (?, 1, 'legacy', ?, UTC_TIMESTAMP(), ?, ?)"
    );
    foreach ($rows as $r) {
        $isOpen = $r['stage'] === 'awaiting';
        $ins->execute([(int)$r['id'], $isOpen ? 'open' : 'approved', $isOpen ? null : $r['approval_datetime'],
                       $isOpen ? null : 'Approved before FreeITSM kept approval records - no detail available.']);
        $roundId = (int)$conn->lastInsertId();
        $conn->prepare("UPDATE changes SET approval_id = ? WHERE id = ?")->execute([$roundId, (int)$r['id']]);
        if ($isOpen) {
            $conn->prepare(
                "INSERT INTO change_approval_votes (approval_id, analyst_id, is_required, is_counted, vote, comment, voted_datetime)
                 SELECT ?, analyst_id, is_required, 1, vote, vote_comment, COALESCE(vote_datetime, UTC_TIMESTAMP())
                   FROM change_cab_members WHERE change_id = ? AND vote IS NOT NULL"
            )->execute([$roundId, (int)$r['id']]);
            $open++;
        } else {
            $approved++;
        }
    }
    return [$approved, $open];
}

/**
 * What stands in the way of switching Enforce on: the names of active statuses with no
 * stage. Enforce reads the stage of every move; on an unclassified status it would have
 * to guess, and guessing "draft" would hand anybody the status approval protects.
 *
 * @return string[]  status names, empty when Enforce may be switched on
 */
function changeApprovalEnforceBlockers(PDO $conn): array
{
    return $conn->query("SELECT name FROM change_statuses WHERE is_active = 1 AND stage IS NULL ORDER BY display_order, name")
                ->fetchAll(PDO::FETCH_COLUMN);
}

// ===========================================================================
//  Enforce mode (#180 Part 1 §4, §9, §10)
// ===========================================================================

/** Stages a change may not enter in Enforce mode without a current approval. */
const CHANGE_STAGES_NEED_APPROVAL = ['approved', 'implementing', 'done'];

/**
 * Is this change's type approved afterwards (Emergency)? Read from change_types.
 *
 * @param array $row  the changes row
 */
function changeTypeIsEmergency(PDO $conn, array $row): bool
{
    if (empty($row['change_type_id'])) return false;
    $s = $conn->prepare("SELECT approval_route FROM change_types WHERE id = ?");
    $s->execute([(int)$row['change_type_id']]);
    return $s->fetchColumn() === 'emergency';
}

/**
 * The guard on every status move. TRAP: the ONLY place a move is judged in Enforce
 * mode. Every ChangesService write that can change status_id must call it, or that
 * path is a way round - the edit form, the right-click menu, PATCH and POST creating a
 * change straight into Completed all meet this same function.
 *
 * Record mode never refuses. Enforce mode refuses a move into Approved, Implementing or
 * Done without a current approved round - except an Emergency round, which may go ahead
 * (Implementing, Done) while its retrospective decision is still open, but not into
 * Approved, which only a decision does.
 *
 * @param ?array $round  the change's current round (open or approved), or null
 * @param array $to  the target status: ['name' => ..., 'stage' => ...]
 * @param array $cfg  changeApprovalSettings()
 */
function changeApprovalGuardMove(?array $round, array $to, array $cfg): void
{
    if (!$cfg['enforce']) return;
    if (!changeStageIsValid($to['stage'] ?? null)) {
        throw new ServiceError('conflict', 'status_unclassified',
            t('change-management.approval.status_unclassified', ['status' => $to['name'] ?? '']));
    }
    if (!in_array($to['stage'], CHANGE_STAGES_NEED_APPROVAL, true)) return;
    if ($round && $round['state'] === 'approved') return;
    if ($round && $round['route'] === 'emergency' && $round['state'] === 'open' && $to['stage'] !== 'approved') return;
    throw new ServiceError('conflict', 'approval_required',
        t('change-management.approval.approval_required', ['status' => $to['name'] ?? '']));
}

/**
 * Before a change is submitted in Enforce mode: is there somebody who can decide it?
 * A round nobody can close helps nobody, so the submission is refused with the reason
 * instead - no CAB and no approver; a CAB whose required members are all the change's
 * own people; a named approver who is one of them.
 *
 * @param array $row  the changes row as it will stand
 * @param array $cabRequiredIds  the required CAB members' analyst ids
 * @param array $cfg  changeApprovalSettings()
 */
function changeApprovalGuardSubmit(array $row, array $cabRequiredIds, array $cfg): void
{
    if (!$cfg['enforce']) return;
    $route = changeApprovalRoute($row);
    if ($route === 'manual') {
        throw new ServiceError('conflict', 'approval_setup_missing', t('change-management.approval.setup_missing'));
    }
    if ($cfg['own_allowed']) return;
    if ($route === 'cab') {
        $independent = array_filter($cabRequiredIds, fn($aid) => changeApprovalIsIndependent($row, (int)$aid, $cfg));
        if (!$independent) {
            throw new ServiceError('conflict', 'cab_unreachable', t('change-management.approval.cab_unreachable'));
        }
    } elseif (!changeApprovalIsIndependent($row, (int)$row['approver_id'], $cfg)) {
        throw new ServiceError('conflict', 'approver_not_independent', t('change-management.approval.approver_not_independent'));
    }
}

/**
 * The statuses the page may offer for a change right now: every active status the guard
 * would accept. Comfort only - the guard is what enforces it, and the API, which has no
 * dropdown, meets the same guard.
 *
 * @param array $row  the changes row
 * @param array $cfg  changeApprovalSettings()
 * @return int[]  status ids
 */
function changeApprovalAllowedStatuses(PDO $conn, array $row, array $cfg): array
{
    $round = !empty($row['approval_id']) ? changeApprovalCurrent($conn, (int)$row['id']) : null;
    $out = [];
    foreach ($conn->query("SELECT id, name, stage FROM change_statuses WHERE is_active = 1 ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC) as $st) {
        if ((int)$st['id'] === (int)($row['status_id'] ?? 0)) { $out[] = (int)$st['id']; continue; }
        try {
            changeApprovalGuardMove($round, $st, $cfg);
            $out[] = (int)$st['id'];
        } catch (ServiceError) { /* not offered */ }
    }
    return $out;
}

/**
 * Emergency changes that went ahead and still await their retrospective decision - the
 * Watchtower count, so an approve-afterwards change cannot quietly stay unapproved.
 */
function changeApprovalEmergencyOpenCount(PDO $conn, string $scopeSql = '', array $scopeArgs = []): int
{
    $s = $conn->prepare(
        "SELECT COUNT(*) FROM changes c
           JOIN change_approvals a ON a.id = c.approval_id
           JOIN change_statuses cs ON cs.id = c.status_id
          WHERE a.route = 'emergency' AND a.state = 'open' AND cs.stage IN ('implementing', 'done'){$scopeSql}"
    );
    $s->execute($scopeArgs);
    return (int)$s->fetchColumn();
}
