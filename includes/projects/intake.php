<?php
/**
 * Projects - intake and approval (3.3.0): proposing a project, and approving it
 * before it can start.
 *
 * A PROPOSAL is an ordinary project with status 'proposed' and
 * approval_status 'pending'. The case for it is its business_case; its own
 * figures are estimated_cost and estimated_benefit; when it came from a form,
 * form_submission_id and proposed_by_name / proposed_by_email say who asked.
 *
 * WAYS IN
 *  - A form: the workflow action create_project (Forms -> a form's Submitted or
 *    Approved list, or any rule in Workflows) - projectCreateFromAction().
 *  - The New project button, when project_proposal_approval is 'all'.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE JUDGEMENT CALLS ARE SETTINGS (Projects -> Settings -> General):
 *   project_proposal_approval   forms | all | off - which new projects wait
 *   project_proposal_approver   managers | person (+ project_proposal_approver_id)
 *   project_proposal_on_approve proposed | active - what approving does
 * 🔑 Whoever holds Manage Projects can ALWAYS decide - a named approver who has
 * left must not strand every proposal.
 * 🔑 approval_status NULL means "needs none": every project from before 3.3.0,
 * and every one created while the setting says it needs none. Turning the
 * setting on later never strands an existing project.
 * 🔑 A pending proposal cannot go active, on hold or closed - only stay
 * proposed or be cancelled (withdrawn). ProjectsService::updateProject() asks
 * projectProposalBlocksStatus().
 */

require_once __DIR__ . '/settings.php';

/** Has Database Verification added the columns? Everything here is quiet before it. */
function projectIntakeReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT approval_status, estimated_cost, form_submission_id FROM projects LIMIT 0"); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** Does a new project wait for approval? $fromForm = it was proposed through a form. */
function projectProposalNeedsApproval(PDO $conn, bool $fromForm): bool
{
    if (!projectIntakeReady($conn)) return false;
    $mode = projectSetting($conn, 'project_proposal_approval');
    return $mode === 'all' || ($mode === 'forms' && $fromForm);
}

/** The named approver, when the setting names one and they are still an active analyst. */
function projectProposalNamedApprover(PDO $conn): ?int
{
    if (projectSetting($conn, 'project_proposal_approver') !== 'person') return null;
    $id = (int)projectSetting($conn, 'project_proposal_approver_id');
    if ($id <= 0) return null;
    $st = $conn->prepare("SELECT 1 FROM analysts WHERE id = ? AND is_active = 1");
    $st->execute([$id]);
    return $st->fetchColumn() ? $id : null;
}

/** May this analyst approve or reject proposals? The named approver, and Manage Projects always. */
function projectCanDecideProposal(PDO $conn, int $analystId): bool
{
    if ($analystId <= 0) return false;
    if (projectProposalNamedApprover($conn) === $analystId) return true;
    return projectIsManager($conn, $analystId);
}

/**
 * Who is told a proposal is waiting: the named approver if there is one,
 * otherwise everybody who holds Manage Projects (admins included).
 */
function projectProposalApprovers(PDO $conn): array
{
    if ($id = projectProposalNamedApprover($conn)) return [$id];
    $out = [];
    foreach ($conn->query("SELECT id FROM analysts WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (projectIsManager($conn, (int)$id)) $out[] = (int)$id;
    }
    return $out;
}

/** A pending proposal may only stay proposed or be withdrawn (cancelled). */
function projectProposalBlocksStatus(array $project, string $newStatus): bool
{
    return ($project['approval_status'] ?? null) === 'pending' && !in_array($newStatus, ['proposed', 'cancelled'], true);
}

/** The proposal panel on a project's page; null when it never needed approval. */
function projectProposalDetail(PDO $conn, array $project, int $analystId): ?array
{
    if (!projectIntakeReady($conn)) return null;
    $st = $conn->prepare("SELECT p.estimated_cost, p.estimated_benefit, p.approval_status, p.approval_datetime, p.approval_notes,
                                 p.form_submission_id, p.proposed_by_name, p.proposed_by_email, p.created_by_id,
                                 a.full_name AS approval_by_name, c.full_name AS created_by_name, f.title AS form_title
                            FROM projects p
                       LEFT JOIN analysts a ON a.id = p.approval_by_id
                       LEFT JOIN analysts c ON c.id = p.created_by_id
                       LEFT JOIN form_submissions s ON s.id = p.form_submission_id
                       LEFT JOIN forms f ON f.id = s.form_id
                           WHERE p.id = ?");
    $st->execute([(int)$project['id']]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $hasFigures = $r['estimated_cost'] !== null || ($r['estimated_benefit'] ?? '') !== '';
    if ($r['approval_status'] === null && !$hasFigures && !$r['form_submission_id']) return null;
    return [
        'status'         => $r['approval_status'],
        'estimated_cost' => $r['estimated_cost'] !== null ? (float)$r['estimated_cost'] : null,
        'estimated_benefit' => $r['estimated_benefit'],
        'decided_by_name'=> $r['approval_by_name'],
        'decided_datetime' => $r['approval_datetime'],
        'notes'          => $r['approval_notes'],
        // Who asked: somebody on a form, else the analyst who created it.
        'proposed_by'    => $r['proposed_by_name'] ?: ($r['proposed_by_email'] ?: $r['created_by_name']),
        'proposed_by_email' => $r['proposed_by_email'],
        'form_title'     => $r['form_title'],
        'submission_id'  => $r['form_submission_id'] !== null ? (int)$r['form_submission_id'] : null,
        'can_decide'     => $r['approval_status'] === 'pending' && projectCanDecideProposal($conn, $analystId),
        'on_approve'     => projectSetting($conn, 'project_proposal_on_approve'),
    ];
}

/** Proposals waiting for a decision, per project id - for the portfolio's view and its chip. */
function projectProposalApprovalColumn(PDO $conn): string
{
    return projectIntakeReady($conn) ? 'p.approval_status' : 'NULL AS approval_status';
}

/**
 * The workflow action create_project: a proposal from a form (or any rule).
 * Answers fill what the action leaves blank - the same rule the form-to-ticket
 * path follows: an override always wins, the submission fills the rest.
 *
 * @return array{project_id:int, code:string, name:string, approval:?string}
 */
function projectCreateFromAction(PDO $conn, array $a, array $payload): array
{
    require_once __DIR__ . '/../services/projects.php';
    require_once __DIR__ . '/budget.php';
    $submissionId = isset($payload['submission']['id']) ? (int)$payload['submission']['id'] : 0;
    $sub = null; $answers = [];
    if ($submissionId > 0) {
        $st = $conn->prepare("SELECT s.*, f.title AS form_title FROM form_submissions s JOIN forms f ON f.id = s.form_id WHERE s.id = ?");
        $st->execute([$submissionId]);
        $sub = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($sub) {
            require_once __DIR__ . '/../catalogue_approvals.php';
            $answers = catalogueSubmissionAnswers($conn, $submissionId);
        }
    }
    // Who asked, and which company it is for: a portal requester's own record,
    // an analyst's name, or the email typed on the form.
    $tenantId = null; $byName = null; $byEmail = null; $byAnalyst = null;
    if ($sub && !empty($sub['submitted_by_user_id'])) {
        $u = $conn->prepare("SELECT email, COALESCE(NULLIF(display_name, ''), email) AS name, tenant_id FROM users WHERE id = ?");
        $u->execute([(int)$sub['submitted_by_user_id']]);
        if ($user = $u->fetch(PDO::FETCH_ASSOC)) { $byName = $user['name']; $byEmail = $user['email']; $tenantId = $user['tenant_id'] !== null ? (int)$user['tenant_id'] : null; }
    } elseif ($sub && !empty($sub['submitted_by'])) {
        $byAnalyst = (int)$sub['submitted_by'];
    }
    if (!$byEmail && !empty($payload['submission']['email'])) $byEmail = (string)$payload['submission']['email'];

    $name = trim((string)($a['name'] ?? ''));
    if ($name === '') $name = trim(($sub['form_title'] ?? 'Proposal') . ($byName || $byEmail ? ' - ' . ($byName ?: $byEmail) : ''));
    $summary = trim((string)($a['summary'] ?? ''));
    if ($summary === '' && $answers) {
        $summary = implode("\n", array_map(fn($x) => $x['label'] . ': ' . $x['value'], array_filter($answers, fn($x) => trim((string)$x['value']) !== '')));
    }
    $in = ['name' => mb_substr($name, 0, 200), 'status' => 'proposed'];
    if ($summary !== '') $in['summary'] = mb_substr($summary, 0, 20000);
    foreach (['business_case', 'estimated_benefit', 'goal'] as $k) if (trim((string)($a[$k] ?? '')) !== '') $in[$k] = trim((string)$a[$k]);
    if (trim((string)($a['estimated_cost'] ?? '')) !== '') {
        // A typed "£12,500" is forgiving about its symbol; anything else that is not a number is left out, not fatal.
        $c = preg_replace('/[^\d.\-]/', '', (string)$a['estimated_cost']);
        if ($c !== '' && is_numeric($c)) $in['estimated_cost'] = $c;
    }
    if (!empty($a['methodology']) && isset(projectMethodologies()[$a['methodology']])) $in['methodology'] = $a['methodology'];
    if (!empty($a['priority']) && in_array($a['priority'], projectPriorities(), true)) $in['priority'] = $a['priority'];
    if (!empty($a['owner_analyst_id'])) $in['owner_analyst_id'] = (int)$a['owner_analyst_id'];
    if (!empty($a['target_end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$a['target_end_date'])) $in['target_end_date'] = $a['target_end_date'];
    $in['_from_form'] = $submissionId > 0;

    // Nobody signed in made this: the actor is the system, so the create
    // policy (which is about people pressing New) does not apply to a form.
    $ctx = new ActorContext(0, null, 'ui', 'en', 'Workflow');
    $id = ProjectsService::createProject($conn, $ctx, $in, $tenantId);
    $conn->prepare("UPDATE projects SET form_submission_id = ?, proposed_by_name = ?, proposed_by_email = ?, created_by_id = COALESCE(created_by_id, ?) WHERE id = ?")
         ->execute([$submissionId ?: null, $byName ? mb_substr($byName, 0, 200) : null, $byEmail ? mb_substr($byEmail, 0, 255) : null, $byAnalyst, $id]);
    $st = $conn->prepare("SELECT approval_status FROM projects WHERE id = ?");
    $st->execute([$id]);
    $approval = $st->fetchColumn() ?: null;
    // Told now, with the proposer known (createProject ran before they were stamped).
    if ($approval === 'pending') ProjectsService::proposalEvent($conn, $id, 'project.proposal_submitted');
    return ['project_id' => $id, 'code' => projectCode($id), 'name' => $in['name'], 'approval' => $approval];
}
