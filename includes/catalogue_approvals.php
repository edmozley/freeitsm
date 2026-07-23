<?php
/**
 * Catalogue-request approvals (#928).
 *
 * A self-service catalogue item is a form (`is_portal_visible = 1`). When that form is
 * marked `requires_approval` and has an `approver_id`, a portal submission does NOT go
 * straight to a ticket — it waits, `approval_status = 'pending'`, for the designated
 * approver to sign it off. Approve raises the ticket (the same way the portal's own
 * new-ticket path does, so tenancy and requester identity are correct) and stamps
 * `form_submissions.ticket_id`; reject records the decision and raises nothing.
 *
 * WHY A DESIGNATED ANALYST, NOT THE REQUESTER'S MANAGER
 * ----------------------------------------------------
 * The classic business version routes to the requester's line manager, but portal
 * users have no manager relationship in this app yet (only a company). So v1 reuses the
 * Change Management "single approver" idea: one analyst signs a catalogue item off. A
 * manager relationship — and manager-based routing — is a later slice.
 *
 * WHY THE APPROVER IS SNAPSHOTTED
 * -------------------------------
 * `form_submissions.approver_id` is copied from the form AT SUBMIT TIME. Editing the
 * catalogue item later re-routes FUTURE requests, never ones already waiting — the
 * person who was told "your manager will approve this" stays the one who can.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/tenancy.php';

// The workflow engine powers the outcome notifications. Load it if nothing else has;
// dispatch is always guarded, so a missing engine degrades to "no notification", never
// a failed decision.
if (!class_exists('WorkflowEngine')) {
    $__wfEngine = __DIR__ . '/../workflow/includes/engine.php';
    if (is_file($__wfEngine)) {
        require_once $__wfEngine;
    }
}

/**
 * Should a submission of this form be gated behind approval, and by whom?
 *
 * Only PORTAL submissions are gated: the feature is about customer catalogue requests
 * that auto-raise a ticket for the requester. An analyst filling a form internally has
 * no portal requester to raise one for, so it is never gated.
 *
 * A form flagged `requires_approval` but with no approver is treated as UNCONFIGURED —
 * it must never strand a request with nobody able to clear it. Returns the approver id
 * to gate on, or null for "let it through as normal".
 */
function catalogueApprovalGate(array $form, ?int $portalUserId): ?int {
    if ($portalUserId === null) return null;
    if (empty($form['requires_approval'])) return null;
    if (empty($form['approver_id'])) return null;
    return (int) $form['approver_id'];
}

/**
 * Approve or reject a pending catalogue request.
 *
 * @param string $decision 'approved' or 'rejected'
 * @return array { decision, ticket_id, ticket_number }
 */
function catalogueApprovalDecide(PDO $conn, int $actorId, int $submissionId, string $decision, string $comment = ''): array {
    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new Exception('Decision must be approve or reject');
    }

    $stmt = $conn->prepare(
        "SELECT s.*, f.title AS form_title
           FROM form_submissions s
           JOIN forms f ON f.id = s.form_id
          WHERE s.id = ?"
    );
    $stmt->execute([$submissionId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub)                                   throw new Exception('That request was not found');
    if ($sub['approval_status'] !== 'pending')   throw new Exception('That request is not awaiting approval');

    // Only the assigned approver — or an administrator — may decide. The requester was
    // told this specific person would sign it off; letting anyone clear it would make
    // the gate meaningless.
    $isAdmin = function_exists('sessionIsAdmin') ? sessionIsAdmin() : false;
    if ((int)$sub['approver_id'] !== $actorId && !$isAdmin) {
        throw new Exception('Only the assigned approver can decide this request');
    }

    $comment = trim($comment);

    $conn->beginTransaction();
    try {
        $ticketId = null;
        $ticketNumber = null;

        if ($decision === 'approved') {
            [$ticketId, $ticketNumber] = catalogueCreateTicketFromSubmission($conn, $sub);
            $conn->prepare(
                "UPDATE form_submissions
                    SET approval_status = 'approved', approval_decided_by_id = ?,
                        approval_decided_datetime = UTC_TIMESTAMP(), approval_comment = ?, ticket_id = ?
                  WHERE id = ?"
            )->execute([$actorId ?: null, $comment !== '' ? $comment : null, $ticketId, $submissionId]);
        } else {
            $conn->prepare(
                "UPDATE form_submissions
                    SET approval_status = 'rejected', approval_decided_by_id = ?,
                        approval_decided_datetime = UTC_TIMESTAMP(), approval_comment = ?
                  WHERE id = ?"
            )->execute([$actorId ?: null, $comment !== '' ? $comment : null, $submissionId]);
        }

        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }

    // Tell the requester the outcome — best-effort, after commit, never fails a decision.
    if (class_exists('WorkflowEngine')) {
        try {
            WorkflowEngine::dispatch('catalogue_request.' . $decision, [
                'form'    => ['id' => (int)$sub['form_id'], 'name' => $sub['form_title']],
                'request' => [
                    'id'            => $submissionId,
                    'comment'       => $comment,
                    'ticket_id'     => $ticketId,
                    'ticket_number' => $ticketNumber,
                ],
            ]);
        } catch (Exception $e) { /* notification is a bonus, not the mechanism */ }
    }

    return ['decision' => $decision, 'ticket_id' => $ticketId, 'ticket_number' => $ticketNumber];
}

/**
 * Raise the ticket an approved request becomes.
 *
 * Deliberately mirrors api/self-service/create_ticket.php — the CORRECT portal path:
 * requester resolved by users.id (never an email string), company taken from the
 * requester's own record, status 'Open', the install's default priority. The body is
 * the submitted answers rendered as an escaped table, so a customer's field values can
 * never inject markup into the analyst's reading pane.
 *
 * @return array [int ticketId, string ticketNumber]
 */
function catalogueCreateTicketFromSubmission(PDO $conn, array $sub): array {
    $userId = (int)($sub['submitted_by_user_id'] ?? 0);
    if (!$userId) throw new Exception('This request has no portal requester to raise a ticket for');

    $u = $conn->prepare("SELECT email, username, display_name, tenant_id FROM users WHERE id = ?");
    $u->execute([$userId]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new Exception('The requester account no longer exists');

    // NULL, not '' — a directory requester with no mailbox has no address, and '' would
    // look like one everywhere downstream. The name carries their identity.
    $fromEmail = ($user['email'] !== null && $user['email'] !== '') ? $user['email'] : null;
    $fromName  = $user['display_name'] ?: ($user['username'] ?: ($fromEmail ?: ('#' . $userId)));

    // Company = whose account raised it. No company on file → NULL (triage) on a
    // multi-company install, Default on a single-company one — exactly as the portal's
    // own new-ticket path decides.
    $tenantId = $user['tenant_id'] !== null
        ? (int) $user['tenant_id']
        : (isMultiTenant($conn) ? null : getDefaultTenantId($conn));

    $subject = trim((string)($sub['form_title'] ?? '')) ?: 'Service request';
    if (mb_strlen($subject) > 255) $subject = mb_substr($subject, 0, 255);
    $bodyHtml    = catalogueSubmissionBodyHtml($conn, (int)$sub['id'], $subject);
    $bodyPreview = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($bodyHtml))), 0, 200);

    $ticketNumber = catalogueGenerateTicketNumber($conn);

    $conn->prepare(
        "INSERT INTO tickets (ticket_number, subject, status_id, priority_id, user_id, tenant_id, created_datetime, updated_datetime)
         VALUES (?, ?,
                 (SELECT id FROM ticket_statuses   WHERE name = 'Open' LIMIT 1),
                 (SELECT id FROM ticket_priorities WHERE is_default = 1 LIMIT 1),
                 ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    )->execute([$ticketNumber, $subject, $userId, $tenantId]);
    $ticketId = (int)$conn->lastInsertId();

    $conn->prepare(
        "INSERT INTO emails (subject, from_address, from_name, to_recipients, received_datetime,
             body_preview, body_content, body_type, has_attachments, importance, is_read, ticket_id, is_initial, direction)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, 'html', 0, 'normal', 0, ?, 1, 'Portal')"
    )->execute([$subject, $fromEmail, $fromName, $fromEmail, $bodyPreview, $bodyHtml, $ticketId]);

    return [$ticketId, $ticketNumber];
}

/** The submitted answers as a safe (fully-escaped) HTML summary for the ticket body. */
function catalogueSubmissionBodyHtml(PDO $conn, int $submissionId, string $formTitle): string {
    $stmt = $conn->prepare(
        "SELECT ff.label, sd.field_value
           FROM form_submission_data sd
           JOIN form_fields ff ON ff.id = sd.field_id
          WHERE sd.submission_id = ?
       ORDER BY ff.sort_order, ff.id"
    );
    $stmt->execute([$submissionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $esc  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $html = '<p>Service request raised from the catalogue: <strong>' . $esc($formTitle) . '</strong></p>';
    $html .= '<table style="border-collapse:collapse;">';
    foreach ($rows as $r) {
        $val     = (string)$r['field_value'];
        $decoded = json_decode($val, true);      // checkboxes are stored as a JSON array
        if (is_array($decoded)) $val = implode(', ', $decoded);
        $html .= '<tr>'
               . '<td style="padding:4px 14px 4px 0;vertical-align:top;color:#666;"><strong>' . $esc($r['label']) . '</strong></td>'
               . '<td style="padding:4px 0;">' . nl2br($esc($val)) . '</td>'
               . '</tr>';
    }
    $html .= '</table>';
    return $html;
}

/** A unique XXX-000-00000 ticket number. Mirrors the portal path's generator. */
function catalogueGenerateTicketNumber(PDO $conn): string {
    for ($i = 0; $i < 10; $i++) {
        $number = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90))
                . '-' . rand(100, 999) . '-' . str_pad((string)rand(0, 99999), 5, '0', STR_PAD_LEFT);
        $c = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number = ?");
        $c->execute([$number]);
        if (!(int)$c->fetchColumn()) return $number;
    }
    throw new Exception('Failed to generate a ticket number');
}

/**
 * The approver's inbox list.
 *
 * @param string $filter 'mine' (pending, assigned to me) | 'all' (every pending) |
 *                       'decided' (recently decided by me)
 * @return array { items: [...], counts: { mine, all, decided } }
 */
function catalogueApprovalsList(PDO $conn, int $analystId, string $filter = 'mine'): array {
    $base =
        "SELECT s.id, s.form_id, f.title AS form_title, s.submitted_date,
                s.approval_status, s.approver_id, s.approval_decided_datetime, s.approval_comment,
                s.ticket_id, t.ticket_number,
                u.display_name AS requester_name, u.username AS requester_username,
                ap.full_name AS approver_name
           FROM form_submissions s
           JOIN forms f       ON f.id = s.form_id
           LEFT JOIN users u  ON u.id = s.submitted_by_user_id
           LEFT JOIN analysts ap ON ap.id = s.approver_id
           LEFT JOIN tickets t   ON t.id = s.ticket_id ";

    if ($filter === 'all') {
        $where = "WHERE s.approval_status = 'pending' ORDER BY s.submitted_date ASC";
        $params = [];
    } elseif ($filter === 'decided') {
        $where = "WHERE s.approval_status IN ('approved','rejected') AND s.approval_decided_by_id = ?
                  ORDER BY s.approval_decided_datetime DESC LIMIT 100";
        $params = [$analystId];
    } else { // mine
        $where = "WHERE s.approval_status = 'pending' AND s.approver_id = ? ORDER BY s.submitted_date ASC";
        $params = [$analystId];
    }

    $stmt = $conn->prepare($base . $where);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach each request's answers so the card can show what was asked for. Pending
    // queues are short by nature (someone is waiting on each one), so the per-row fetch
    // is fine here.
    foreach ($items as &$it) {
        $it['answers'] = catalogueSubmissionAnswers($conn, (int)$it['id']);
    }
    unset($it);

    $counts = [
        'mine'    => (int)catalogueScalar($conn, "SELECT COUNT(*) FROM form_submissions WHERE approval_status='pending' AND approver_id = ?", [$analystId]),
        'all'     => (int)catalogueScalar($conn, "SELECT COUNT(*) FROM form_submissions WHERE approval_status='pending'", []),
        'decided' => (int)catalogueScalar($conn, "SELECT COUNT(*) FROM form_submissions WHERE approval_status IN ('approved','rejected') AND approval_decided_by_id = ?", [$analystId]),
    ];

    return ['items' => $items, 'counts' => $counts];
}

/** label + value pairs for one submission (checkboxes flattened). */
function catalogueSubmissionAnswers(PDO $conn, int $submissionId): array {
    $stmt = $conn->prepare(
        "SELECT ff.label, sd.field_value
           FROM form_submission_data sd
           JOIN form_fields ff ON ff.id = sd.field_id
          WHERE sd.submission_id = ?
       ORDER BY ff.sort_order, ff.id"
    );
    $stmt->execute([$submissionId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $val = (string)$r['field_value'];
        $decoded = json_decode($val, true);
        if (is_array($decoded)) $val = implode(', ', $decoded);
        $out[] = ['label' => $r['label'], 'value' => $val];
    }
    return $out;
}

function catalogueScalar(PDO $conn, string $sql, array $params) {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
