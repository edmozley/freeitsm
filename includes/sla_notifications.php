<?php
/**
 * SLA Breach Notification Engine
 *
 * Called by `cron/sla_breach_check.php` on a schedule (every 5 mins recommended).
 * Walks every open SLA-enabled ticket, decides whether warning / breach
 * notifications should fire for response and / or resolution targets, resolves
 * the matching rule's recipients, and sends the email via the ticket's
 * mailbox (Microsoft Graph or Gmail). Dedups via `sla_notifications_sent`
 * so the same notification fires at most once per (ticket, target, trigger).
 *
 * Public entry: sla_run_breach_check(PDO $conn): array
 *   Returns a summary { sent: [...], skipped: [...], errors: [...] } for
 *   cron logging.
 */

require_once __DIR__ . '/sla.php';
require_once __DIR__ . '/template_email.php';
require_once __DIR__ . '/encryption.php';

/**
 * Main cron entry. Returns a summary so the cron wrapper can log it.
 *
 * QUIET (GH #217): walks exactly the same tickets and triggers, but sends nothing -
 * it records each warning and breach in both fire-once ledgers instead, so the next
 * real run treats them as already announced. Run once when SLA is switched back on
 * for the install and the administrator chose not to send what was missed while it
 * was off; without it the first run after switching on would announce every ticket
 * that crossed a line during the off period, all at once.
 *
 * @param bool $quiet  true to mark, not send - see above
 */
function sla_run_breach_check(PDO $conn, bool $quiet = false): array {
    $summary = ['sent' => [], 'skipped' => [], 'errors' => [], 'marked' => 0];

    // Settings sanity check — if SLA enforcement is off entirely, abort early.
    $settings = sla_load_settings($conn);
    if (empty($settings['sla_enforce_from'])) {
        $summary['skipped'][] = 'SLA enforcement disabled (sla_enforce_from is empty)';
        return $summary;
    }
    $warningThreshold = (float)($settings['sla_warning_threshold_percent'] ?? 80);

    // Find candidate tickets: open (not closed) and SLA-tracked. Closed tickets
    // can't breach further so they're filtered out. Cap the working set so a
    // misconfigured install can't trigger a runaway.
    $stmt = $conn->query("
        SELECT t.id
          FROM tickets t
     LEFT JOIN ticket_statuses s ON s.id = t.status_id
         WHERE COALESCE(s.is_closed, 0) = 0
           AND t.closed_datetime IS NULL
           AND t.deleted_datetime IS NULL
         ORDER BY t.id DESC
         LIMIT 2000
    ");
    $ticketIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ticketIds as $ticketId) {
        try {
            $state = sla_get_state($conn, (int)$ticketId);
            if (empty($state['enabled'])) continue;

            foreach (['response', 'resolution'] as $targetType) {
                $target = $state[$targetType] ?? null;
                if (!$target) continue;

                // Skip if already achieved — clock has stopped, no breach possible
                if ($target['achieved_at'] !== null) continue;

                // Decide which trigger (if any) applies. Order matters: breach
                // takes precedence over warning, but we still record a warning
                // first if it was crossed before the breach (catch-up after
                // cron downtime).
                $triggers = [];
                if ($target['percent'] >= $warningThreshold && $target['percent'] < 100) {
                    $triggers[] = 'warning';
                }
                if ($target['breached']) {
                    // Send the warning catch-up first if it never fired, then breach
                    if (!sla_notification_already_sent($conn, (int)$ticketId, $targetType, 'warning')) {
                        $triggers[] = 'warning';
                    }
                    $triggers[] = 'breach';
                }

                foreach (array_unique($triggers) as $trigger) {
                    // ---- Workflow event -------------------------------------
                    // Emitted BEFORE the email-rule lookup, and gated by its OWN
                    // fire-once ledger, deliberately:
                    //
                    //  * A workflow must fire whether or not anyone configured an
                    //    email rule. Notification rules are about who gets an
                    //    email; they have nothing to say about automation.
                    //  * sla_notifications_sent is only written when an email
                    //    actually goes out (see below — "no matching rule" leaves
                    //    it unmarked), so reusing it as the workflow ledger would
                    //    mean a workflow re-fires forever on any install with no
                    //    notification rules. Which is most of them.
                    //
                    // Free to compute: we're already holding this ticket's SLA
                    // state, so the emitter can't drift from what the emails think.
                    if ($quiet) {
                        sla_mark_workflow_event($conn, (int)$ticketId, $targetType, $trigger, $target);
                        if (!sla_notification_already_sent($conn, (int)$ticketId, $targetType, $trigger)) {
                            sla_mark_notification_sent($conn, (int)$ticketId, $targetType, $trigger, ['(not sent: SLA switched back on)']);
                            $summary['marked']++;
                        }
                        continue;
                    }

                    sla_emit_workflow_event($conn, (int)$ticketId, $targetType, $trigger, $target);

                    if (sla_notification_already_sent($conn, (int)$ticketId, $targetType, $trigger)) {
                        continue;
                    }
                    $rule = sla_find_matching_rule($conn, (int)$ticketId, $trigger, $targetType);
                    if (!$rule) {
                        $summary['skipped'][] = "ticket $ticketId / $targetType / $trigger: no matching rule";
                        continue;
                    }
                    $recipients = sla_resolve_recipients($conn, (int)$ticketId, $rule);
                    if (empty($recipients)) {
                        $summary['skipped'][] = "ticket $ticketId / $targetType / $trigger: rule matched but no resolvable recipients";
                        continue;
                    }
                    sla_send_breach_email($conn, (int)$ticketId, $state, $targetType, $trigger, $recipients);
                    sla_mark_notification_sent($conn, (int)$ticketId, $targetType, $trigger, $recipients);
                    $summary['sent'][] = "ticket $ticketId / $targetType / $trigger to " . implode(', ', $recipients);
                }
            }
        } catch (Exception $e) {
            $summary['errors'][] = "ticket $ticketId: " . $e->getMessage();
        }
    }

    return $summary;
}

/**
 * Record an SLA workflow event as already fired, WITHOUT firing it - the quiet run
 * above (GH #217). Same ledger, entity and fingerprint as sla_emit_workflow_event(),
 * and the same rule as workflowEmitOnce(): nothing is recorded while no workflow is
 * listening, so a workflow switched on later is not silenced by it.
 *
 * @param string $targetType  'response' or 'resolution'
 * @param string $trigger  'warning' or 'breach'
 * @param array $target  that target's state from sla_get_state()
 */
function sla_mark_workflow_event(PDO $conn, int $ticketId, string $targetType, string $trigger, array $target): void
{
    $event = $trigger === 'breach' ? 'sla.breached' : 'sla.warning';
    try {
        $listening = $conn->prepare("SELECT COUNT(*) FROM workflows WHERE trigger_event = ? AND is_active = 1");
        $listening->execute([$event]);
        if ((int)$listening->fetchColumn() === 0) return;
        $conn->prepare(
            "INSERT IGNORE INTO workflow_scheduled_emissions (trigger_event, entity_key, fingerprint, emitted_datetime)
             VALUES (?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$event, 'ticket:' . $ticketId . ':' . $targetType, (string)($target['target_minutes'] ?? 0)]);
    } catch (Exception $e) {
        error_log('[sla quiet resume] ' . $e->getMessage());
    }
}

/**
 * Emit the time-based workflow event for an SLA warning / breach.
 *
 * `$trigger` is the SLA module's own vocabulary ('warning' / 'breach'); the
 * workflow trigger names are `sla.warning` / `sla.breached`.
 *
 * FINGERPRINT = the SLA target in minutes. That is what makes this re-arm
 * correctly: change a ticket's priority and its SLA target changes with it, so
 * the new deadline is a new fingerprint and is allowed to breach and fire again.
 * Fingerprinting on the ticket alone would suppress the second escalation
 * forever — the workflow would go quiet exactly when the ticket got more urgent.
 *
 * The full ticket is loaded into the payload so an escalation can act on the
 * ticket itself (reassign, reprioritise, message about it) — not just announce
 * that a number went red.
 *
 * @param string $targetType  'response' or 'resolution'
 * @param string $trigger  'warning' or 'breach'
 * @param array $target  that target's state from sla_get_state(): target_minutes, elapsed_minutes, remaining_minutes, percent
 */
function sla_emit_workflow_event(PDO $conn, int $ticketId, string $targetType, string $trigger, array $target): void
{
    try {
        require_once __DIR__ . '/workflow_scheduled.php';

        $event = $trigger === 'breach' ? 'sla.breached' : 'sla.warning';

        // ⚠️ `type_id` and `created_by` are NOT columns on `tickets` — they are
        // aliases for `ticket_type_id` and `user_id`. Without the aliases this
        // query threw, the catch below swallowed it, and workflowEmitOnce() was
        // never reached: sla.warning and sla.breached had NEVER fired, on any
        // install, despite being registered triggers with a documented feature
        // page. Keep this in step with the canonical payload query in
        // includes/services/tickets.php.
        $t = $conn->prepare(
            "SELECT id, subject, priority_id, status_id, department_id,
                    ticket_type_id AS type_id, assigned_analyst_id, owner_id,
                    origin_id, user_id AS created_by
               FROM tickets WHERE id = ? LIMIT 1"
        );
        $t->execute([$ticketId]);
        $ticket = $t->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) return;

        // Requester email lives on the ticket's originating email row.
        // ⚠️ The column is `from_address`, not `from_email` — the third wrong
        // column name in this one function, all three undiscovered because the
        // duplicate sla_format_minutes() fataled the cron before it ever ran.
        $r = $conn->prepare("SELECT from_address FROM emails WHERE ticket_id = ? ORDER BY id ASC LIMIT 1");
        $r->execute([$ticketId]);
        $ticket['requester_email'] = $r->fetchColumn() ?: null;

        $sla = [
            'target'         => $targetType,                       // 'response' | 'resolution'
            'target_minutes' => (int)($target['target_minutes'] ?? 0),
            'percent'        => (float)($target['percent'] ?? 0),
        ];
        if ($event === 'sla.breached') {
            $sla['overdue_minutes'] = max(0, (int)($target['elapsed_minutes'] ?? 0) - (int)($target['target_minutes'] ?? 0));
        } else {
            $sla['remaining_minutes'] = (int)($target['remaining_minutes'] ?? 0);
        }

        workflowEmitOnce(
            $conn,
            $event,
            'ticket:' . $ticketId . ':' . $targetType,
            (string)($target['target_minutes'] ?? 0),   // re-arms if the SLA target changes
            ['ticket' => $ticket, 'sla' => $sla]
        );
    } catch (Exception $e) {
        // Never let workflow emission break the SLA email run.
        error_log('[sla_emit_workflow_event] ticket ' . $ticketId . ': ' . $e->getMessage());
    }
}

/**
 * Find the most-specific rule matching this ticket + trigger + target.
 *
 * Resolution order:
 *   1. Per-dept rule with this exact target ('response' or 'resolution')
 *   2. Per-dept rule with target='both'
 *   3. Default rule (NULL dept) with this exact target
 *   4. Default rule (NULL dept) with target='both'
 *
 * 'both' rules match either target — they're the "applies to all SLAs"
 * convenience shortcut.
 *
 * @param string $trigger  'warning' or 'breach'
 * @param string $targetType  'response' or 'resolution'
 */
function sla_find_matching_rule(PDO $conn, int $ticketId, string $trigger, string $targetType): ?array {
    $stmt = $conn->prepare("SELECT department_id FROM tickets WHERE id = ?");
    $stmt->execute([$ticketId]);
    $ticketDept = $stmt->fetchColumn();
    $ticketDept = $ticketDept ? (int)$ticketDept : null;

    // Pull all matching candidate rules for this trigger, order them by specificity
    $sql = "SELECT *
              FROM sla_notification_rules
             WHERE is_active = 1
               AND trigger_type = ?
               AND target_type IN (?, 'both')
               AND (department_id = ? OR department_id IS NULL)
          ORDER BY (department_id IS NOT NULL) DESC,
                   (target_type = ?) DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$trigger, $targetType, $ticketDept, $targetType]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);
    return $rule ?: null;
}

/**
 * Expand a rule into a unique list of recipient email addresses.
 * Resolves assignee, department-team members, named analyst, and the
 * explicit emails list; dedupes case-insensitively.
 *
 * @param array $rule  the sla_notification_rules row: notify_assignee, notify_department_teams, notify_analyst_id, notify_emails
 */
function sla_resolve_recipients(PDO $conn, int $ticketId, array $rule): array {
    $out = [];

    if (!empty($rule['notify_assignee'])) {
        $stmt = $conn->prepare("
            SELECT a.email
              FROM tickets t
              JOIN analysts a ON a.id = t.assigned_analyst_id
             WHERE t.id = ? AND a.email IS NOT NULL AND a.email <> ''
        ");
        $stmt->execute([$ticketId]);
        $email = $stmt->fetchColumn();
        if ($email) $out[strtolower($email)] = $email;
    }

    if (!empty($rule['notify_department_teams'])) {
        $stmt = $conn->prepare("
            SELECT DISTINCT a.email
              FROM tickets t
              JOIN department_teams dt ON dt.department_id = t.department_id
              JOIN analyst_teams at    ON at.team_id = dt.team_id
              JOIN analysts a          ON a.id = at.analyst_id
             WHERE t.id = ? AND a.is_active = 1 AND a.email IS NOT NULL AND a.email <> ''
        ");
        $stmt->execute([$ticketId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
            $out[strtolower($email)] = $email;
        }
    }

    if (!empty($rule['notify_analyst_id'])) {
        $stmt = $conn->prepare("SELECT email FROM analysts WHERE id = ? AND email IS NOT NULL AND email <> ''");
        $stmt->execute([(int)$rule['notify_analyst_id']]);
        $email = $stmt->fetchColumn();
        if ($email) $out[strtolower($email)] = $email;
    }

    if (!empty($rule['notify_emails'])) {
        foreach (preg_split('/[,;\s]+/', $rule['notify_emails']) ?: [] as $email) {
            $email = trim($email);
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[strtolower($email)] = $email;
            }
        }
    }

    return array_values($out);
}

/**
 * Send a breach / warning email via the ticket's originating mailbox.
 * Falls back to the first active mailbox if the ticket has none (e.g.
 * manually-created ticket).
 *
 * @param array $state  the ticket's SLA state from sla_get_state()
 * @param string $targetType  'response' or 'resolution' - which part of $state the email is about
 * @param string $trigger  'warning' or 'breach'
 * @param array $recipients  the email addresses to send to, from sla_resolve_recipients()
 */
function sla_send_breach_email(PDO $conn, int $ticketId, array $state, string $targetType, string $trigger, array $recipients): void {
    $merge = buildTicketMergeData($conn, $ticketId);
    if (!$merge) {
        throw new Exception("could not build merge data for ticket $ticketId");
    }

    $target = $state[$targetType];
    $priority = $state['priority']['name'] ?? '';
    $ticketRef = $merge['ticket_reference'];
    $targetLabel = $targetType === 'response' ? 'Response' : 'Resolution';

    if ($trigger === 'breach') {
        $subject = "[SLA BREACH] $ticketRef &mdash; $priority $targetLabel SLA breached";
        $headline = "$targetLabel SLA has been <strong>breached</strong> on this ticket.";
    } else {
        $subject = "[SLA WARNING] $ticketRef &mdash; $priority $targetLabel SLA approaching breach";
        $headline = "$targetLabel SLA is <strong>approaching breach</strong> on this ticket.";
    }
    // Decode the &mdash; for the subject line (Graph/Gmail expect plain text subjects)
    $subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5);

    $body = sla_build_breach_email_body($merge, $target, $targetLabel, $headline, $trigger);

    // Mailbox choice, token refresh, the three providers and the per-recipient
    // send log live in internalTicketEmail(), shared with the mandatory-fields
    // closure alert. Logged per recipient: one address failing (a typo in an
    // escalation list, say) should not read as the whole alert having failed.
    internalTicketEmail($conn, $ticketId, $recipients, $subject, $body, 'sla');
}

/**
 * Render the HTML body for a breach / warning email.
 *
 * @param array $merge  the ticket merge data from buildTicketMergeData()
 * @param array $target  that target's state: target_minutes, elapsed_minutes, remaining_minutes
 * @param string $targetLabel  'Response' or 'Resolution'
 * @param string $headline  the banner text - trusted HTML, written into the email as it is
 * @param string $trigger  'warning' or 'breach'; sets the banner colour and the status line
 */
function sla_build_breach_email_body(array $merge, array $target, string $targetLabel, string $headline, string $trigger): string {
    $bannerColour = $trigger === 'breach' ? '#dc2626' : '#f59e0b';
    $statusLine = $trigger === 'breach'
        ? "Over by " . sla_format_minutes(abs((int)$target['remaining_minutes']))
        : sla_format_minutes((int)$target['remaining_minutes']) . " remaining";
    $assignee = $merge['analyst_name'] !== '' ? htmlspecialchars($merge['analyst_name']) : '<em>Unassigned</em>';

    return '
<div style="font-family: Arial, sans-serif; color: #333; line-height: 1.5; max-width: 600px;">
    <div style="background:' . $bannerColour . ';color:white;padding:14px 18px;border-radius:4px 4px 0 0;font-weight:600;font-size:15px;">
        ' . $headline . '
    </div>
    <div style="border:1px solid #e5e7eb;border-top:none;padding:18px;border-radius:0 0 4px 4px;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <tr><td style="padding:4px 0;color:#666;width:140px;">Ticket</td><td><strong>' . htmlspecialchars($merge['ticket_reference']) . '</strong> &mdash; ' . htmlspecialchars($merge['ticket_subject']) . '</td></tr>
            <tr><td style="padding:4px 0;color:#666;">Priority</td><td>' . htmlspecialchars($merge['ticket_priority']) . '</td></tr>
            <tr><td style="padding:4px 0;color:#666;">Department</td><td>' . htmlspecialchars($merge['department_name']) . '</td></tr>
            <tr><td style="padding:4px 0;color:#666;">Assignee</td><td>' . $assignee . '</td></tr>
            <tr><td style="padding:4px 0;color:#666;">Requester</td><td>' . htmlspecialchars($merge['requester_name']) . '</td></tr>
            <tr><td style="padding:4px 0;color:#666;">Created</td><td>' . htmlspecialchars($merge['created_date']) . '</td></tr>
        </table>
        <div style="margin-top:14px;padding:12px 14px;background:#f9fafb;border-radius:4px;font-size:13px;">
            <strong>' . htmlspecialchars($targetLabel) . ' SLA</strong> &middot;
            Target ' . sla_format_minutes((int)$target['target_minutes']) . ' &middot;
            Elapsed ' . sla_format_minutes((int)$target['elapsed_minutes']) . ' &middot;
            <strong style="color:' . $bannerColour . ';">' . $statusLine . '</strong>
        </div>
    </div>
    <div style="margin-top:12px;font-size:11px;color:#999;">
        This is an automated SLA notification. Configure recipients under Tickets &rsaquo; Settings &rsaquo; SLA &rsaquo; Breach Notifications.
    </div>
</div>';
}

// ⚠️ sla_format_minutes() USED TO BE DECLARED HERE, and it is already declared
// in includes/sla.php — which this file requires at the top. Two unguarded
// declarations of the same function is a FATAL, so requiring this file killed
// the request outright: cron/sla_breach_check.php could not run at all, which
// means SLA warning and breach emails never sent and sla.warning / sla.breached
// workflows never fired.
//
// The two bodies were equivalent, so this one is simply gone. Do not
// reintroduce it — call the one in sla.php.

/**
 * Check if we've already fired this notification for (ticket, target, trigger).
 *
 * @param string $targetType  'response' or 'resolution'
 * @param string $trigger  'warning' or 'breach'
 */
function sla_notification_already_sent(PDO $conn, int $ticketId, string $targetType, string $trigger): bool {
    $stmt = $conn->prepare("SELECT 1 FROM sla_notifications_sent WHERE ticket_id = ? AND target_type = ? AND trigger_type = ?");
    $stmt->execute([$ticketId, $targetType, $trigger]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Records that an SLA notification went out for a ticket, so it is not sent twice.
 *
 * @param string $targetType  'response' or 'resolution'
 * @param string $trigger  'warning' or 'breach'
 * @param array $recipients  the addresses it went to, stored comma-separated
 */
function sla_mark_notification_sent(PDO $conn, int $ticketId, string $targetType, string $trigger, array $recipients): void {
    $stmt = $conn->prepare("INSERT IGNORE INTO sla_notifications_sent (ticket_id, target_type, trigger_type, recipients) VALUES (?, ?, ?, ?)");
    $stmt->execute([$ticketId, $targetType, $trigger, implode(',', $recipients)]);
}

/** The active mailbox with the lowest id, decrypted; null when there is none. */
function sla_get_first_active_mailbox(PDO $conn): ?array {
    $stmt = $conn->query("SELECT * FROM target_mailboxes WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
    $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mailbox) return null;
    return decryptMailboxRow($mailbox);
}
