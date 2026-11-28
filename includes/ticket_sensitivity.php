<?php
/**
 * Ticket sensitivity: Normal or Confidential (discussion #62, step 1).
 *
 * WHY THIS EXISTS BEFORE MANAGERS DO
 * ----------------------------------
 * Managers are coming to the self-service portal: people who can read tickets
 * raised by the people they manage. Before that ships, a ticket has to be able
 * to say "not for my manager". Someone emailing HR about a diagnosis, or about
 * how to raise a grievance against their own manager, must never have that
 * ticket shown to the manager. Confidential is how a ticket says so; step 3
 * decides what a manager sees of one (default: nothing at all).
 *
 * THE DANGEROUS MOMENT IS BEFORE ANYONE HAS LOOKED
 * ------------------------------------------------
 * An analyst can mark a ticket confidential - but a grievance emailed to HR
 * sits unmarked until someone gets to it. So a ticket also becomes confidential
 * WITHOUT anyone deciding, when:
 *   - the requester ticks Confidential when raising it in the portal;
 *   - it arrives through a mailbox set to confidential (an HR mailbox);
 *   - it is in, or is moved into, a service-desk department set to confidential;
 *   - it is split from, or merged with, a confidential ticket.
 *
 * 🔴 DEFAULTS ONLY EVER RAISE. Nothing automatic ever makes a ticket Normal
 * again: moving a ticket OUT of HR leaves it confidential until a person
 * decides otherwise. Lowering is always a deliberate act by an analyst.
 *
 * 🔴 EVERY PATH THAT CREATES A TICKET MUST CALL ticketSensitivityApplyDefaults()
 * after its insert. There were eleven such paths when this was written and only
 * two went through TicketsService, so a hook in the service alone would have
 * missed nine - including the mailbox ingest, which is where the HR email lands.
 * The list is in the wiki: Confidential-Tickets-Developer-Guide.
 *
 * Confidential does NOT restrict analysts. Who on the service desk can see a
 * ticket is still decided by teams, departments and companies, as before.
 *
 * @wiki Confidential-Tickets-Developer-Guide
 */

const TICKET_SENSITIVITIES = ['normal', 'confidential'];

/**
 * Have the columns been added yet? New code reaches an install before
 * System -> Database Verification runs, and every ticket path calls into here,
 * so a missing column must mean "do nothing", never an error.
 */
function ticketSensitivityReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query("SELECT sensitivity FROM tickets LIMIT 0");
            $conn->query("SELECT default_sensitivity FROM departments LIMIT 0");
            $conn->query("SELECT default_sensitivity FROM target_mailboxes LIMIT 0");
            $ready = true;
        } catch (Throwable) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Anything unrecognised reads as normal - the column's own default.
 *
 * @param mixed $value  a sensitivity as stored or submitted; anything not in TICKET_SENSITIVITIES gives 'normal'
 */
function ticketSensitivityNormalise($value): string
{
    $v = strtolower(trim((string)$value));
    return in_array($v, TICKET_SENSITIVITIES, true) ? $v : 'normal';
}

/**
 * Make a ticket confidential if it is not already, and say why in the audit
 * trail. Returns true if it changed.
 *
 * The audit entry has no analyst (NULL): a rule did this, not a person, and the
 * value names the rule so nobody has to wonder how it got there.
 *
 * @param string $reason  why, in words; stored in the audit row as "Confidential - <reason>"
 */
function ticketSensitivityRaise(PDO $conn, int $ticketId, string $reason): bool
{
    if (!ticketSensitivityReady($conn)) {
        return false;
    }
    $stmt = $conn->prepare("UPDATE tickets SET sensitivity = 'confidential' WHERE id = ? AND sensitivity <> 'confidential'");
    $stmt->execute([$ticketId]);
    if ($stmt->rowCount() === 0) {
        return false;
    }
    $conn->prepare(
        "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
         VALUES (?, NULL, 'Sensitivity', 'Normal', ?, UTC_TIMESTAMP())"
    )->execute([$ticketId, 'Confidential - ' . $reason]);
    // A confidential ticket has no participants (GH #432): nobody outside it is
    // copied on its emails any more. Each removal goes in the history too.
    require_once __DIR__ . '/services/ticket_participants.php';
    TicketParticipants::clearForConfidential($conn, [$ticketId], null);
    // A scheduled ticket may already sit in its owner's synced calendar under
    // its real subject. Re-sync it now rather than at the next edit. A cheap
    // no-op for an unscheduled ticket - which is nearly every one raised here.
    require_once __DIR__ . '/calendar_sync/push.php';
    calendarSyncReconcileTicket($conn, $ticketId);
    return true;
}

/**
 * Apply the automatic defaults to a ticket: its service-desk department's, and
 * the mailbox it arrived through, if there was one. Call after every insert, and
 * after every change of department.
 *
 * @param ?int $mailboxId  the target_mailboxes id the ticket arrived through; null when it did not come by email
 */
function ticketSensitivityApplyDefaults(PDO $conn, int $ticketId, ?int $mailboxId = null): bool
{
    if (!ticketSensitivityReady($conn)) {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT d.name, d.default_sensitivity
           FROM tickets t JOIN departments d ON d.id = t.department_id
          WHERE t.id = ?"
    );
    $stmt->execute([$ticketId]);
    $dept = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dept && $dept['default_sensitivity'] === 'confidential') {
        return ticketSensitivityRaise($conn, $ticketId, 'the ' . $dept['name'] . ' department is confidential');
    }

    if ($mailboxId) {
        $stmt = $conn->prepare("SELECT name, default_sensitivity FROM target_mailboxes WHERE id = ?");
        $stmt->execute([$mailboxId]);
        $mb = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($mb && $mb['default_sensitivity'] === 'confidential') {
            return ticketSensitivityRaise($conn, $ticketId, 'arrived through the ' . $mb['name'] . ' mailbox');
        }
    }
    return false;
}

/**
 * A ticket made from, or combined with, others is confidential if any of them
 * was. Used by split (one source) and merge (several).
 *
 * @param int[] $sourceIds  the ticket(s) it was split or merged from; empty means nothing to inherit
 * @param string $reason  why, in words, for the audit row if the ticket is raised
 */
function ticketSensitivityInherit(PDO $conn, int $ticketId, array $sourceIds, string $reason): bool
{
    if (!ticketSensitivityReady($conn) || !$sourceIds) {
        return false;
    }
    $ids = array_values(array_unique(array_map('intval', $sourceIds)));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE id IN ($in) AND sensitivity = 'confidential'");
    $stmt->execute($ids);
    if ((int)$stmt->fetchColumn() === 0) {
        return false;
    }
    return ticketSensitivityRaise($conn, $ticketId, $reason);
}

/* ─── Keeping confidential tickets inside FreeITSM (discussion #62) ──────────
 * Confidential keeps a ticket from managers. It also has to keep it from
 * leaving: an AI provider, a Slack channel or a Jira project is somebody else's
 * system, and a diagnosis sent to HR should not turn up in any of them.
 * These are the ONE answers the three kinds of exit ask.
 * ─────────────────────────────────────────────────────────────────────────── */

/** Is this ticket confidential? False before Database Verification (no column). */
function ticketIsConfidential(PDO $conn, int $ticketId): bool
{
    if ($ticketId <= 0 || !ticketSensitivityReady($conn)) return false;
    try {
        $st = $conn->prepare("SELECT sensitivity FROM tickets WHERE id = ?");
        $st->execute([$ticketId]);
        return ticketSensitivityNormalise($st->fetchColumn()) === 'confidential';
    } catch (Throwable) {
        return false;
    }
}

/**
 * Tickets -> Settings -> "Confidential tickets and AI": 'block' (the default -
 * never send) or 'allow'. Never saved means block: an upgrade must not start
 * sending confidential tickets to a provider it was not sending them to.
 */
function ticketAiConfidentialPolicy(PDO $conn): string
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_ai_confidential'");
        $st->execute();
        return $st->fetchColumn() === 'allow' ? 'allow' : 'block';
    } catch (Throwable) {
        return 'block';
    }
}

/** May this ticket's content go to an AI provider? */
function ticketAiAllowed(PDO $conn, int $ticketId): bool
{
    return !ticketIsConfidential($conn, $ticketId) || ticketAiConfidentialPolicy($conn) === 'allow';
}

/** What every AI endpoint says when it refuses. The UI shows it as it is. */
const TICKET_AI_CONFIDENTIAL_ERROR = 'This ticket is confidential, so it is not sent to AI. An administrator can change that in Tickets -> Settings -> General.';

/**
 * For AI features that read MANY tickets (problem root cause, suggested
 * problems, knowledge gap analysis, Warbot): an SQL condition that leaves the
 * confidential ones out, or '' when the policy allows them or the column does
 * not exist yet. Pass the tickets table's alias.
 *
 * @param string $alias  the alias the calling query gives the tickets table
 */
function ticketAiExclusionSql(PDO $conn, string $alias = 't'): string
{
    if (!ticketSensitivityReady($conn) || ticketAiConfidentialPolicy($conn) === 'allow') return '';
    return " AND $alias.sensitivity <> 'confidential'";
}

/**
 * The ticket's subject as an AI may see it: 'Confidential ticket' in place of a
 * confidential one's (under the block policy), so a count or a list stays true
 * while its content stays home. An SQL expression; pass the tickets alias.
 *
 * @param string $alias  the alias the calling query gives the tickets table
 */
function ticketAiSubjectSql(PDO $conn, string $alias = 't'): string
{
    if (!ticketSensitivityReady($conn) || ticketAiConfidentialPolicy($conn) === 'allow') return "$alias.subject";
    return "CASE WHEN $alias.sensitivity = 'confidential' THEN 'Confidential ticket' ELSE $alias.subject END";
}

/**
 * A workflow event's payload as it may leave for a webhook, Slack or Teams
 * (discussion #62). Unchanged for a normal ticket. For a confidential one it is
 * cut down to an ALLOW-LIST - identifiers, number, status, priority, department,
 * team, dates - because a deny-list of "content" fields would miss the next one
 * somebody adds. The subject becomes "Confidential ticket" and `confidential` is
 * set, so a message template still reads sensibly.
 *
 * Never a setting: unlike AI, nobody chooses to post an HR ticket's subject into
 * a team channel, and a chat post cannot be taken back.
 *
 * @param array $payload  the workflow event payload; the ticket id is read from ticket.id or ticket_id
 */
function ticketRedactForOutbound(PDO $conn, array $payload): array
{
    $tid = (int)($payload['ticket']['id'] ?? ($payload['ticket_id'] ?? 0));
    if ($tid <= 0 || !ticketIsConfidential($conn, $tid)) return $payload;

    $keepTicket = '/^(id|ticket_id|ticket_number|number|ref|reference|status|status_id|status_name|priority|priority_id|priority_name|'
                . 'department_id|department|department_name|type_id|ticket_type_id|type|type_name|category_id|category|'
                . 'assigned_analyst_id|assigned_analyst|assigned_analyst_name|assigned_team_id|assigned_team|team_id|team|'
                . 'owner_id|owner|owner_name|origin_id|created_datetime|updated_datetime|closed_datetime|url|link|tenant_id)$/';
    $ticket = [];
    foreach ((array)($payload['ticket'] ?? []) as $k => $v) {
        if (preg_match($keepTicket, (string)$k) && !is_array($v)) $ticket[$k] = $v;
    }
    $ticket['id'] = $tid;
    $ticket['subject'] = 'Confidential ticket';
    $ticket['sensitivity'] = 'confidential';

    $keepTop = '/^(event|event_type|trigger|source|note_id|is_internal|occurred_at|timestamp)$/';
    $out = ['ticket' => $ticket, 'confidential' => true];
    foreach ($payload as $k => $v) {
        if ($k !== 'ticket' && preg_match($keepTop, (string)$k) && !is_array($v)) $out[$k] = $v;
    }
    return $out;
}

/* ─── Email and calendars (discussion #62) ───────────────────────────────────
 * The two exits the list above did not cover. Both are decided here so the
 * three callers (the workflow Send email action, the calendar push and the
 * .ics feed) cannot each grow their own idea of what is allowed.
 * ─────────────────────────────────────────────────────────────────────────── */

/** What a confidential ticket is called wherever its subject would have gone. */
const TICKET_CONFIDENTIAL_SUBJECT = 'Confidential ticket';

/**
 * May a workflow email about this ticket go to these addresses?
 *
 * For a normal ticket, always. For a confidential one, only when EVERY address
 * is the ticket's own requester or an active analyst: the requester already
 * knows what they wrote, and confidential does not restrict the service desk.
 * Anybody else - a manager, a distribution list, an outside contact - is
 * exactly who the flag exists to keep it from, and an email cannot be taken
 * back. A deny-list could not work here: nobody can list who must not see it.
 *
 * @param string $to one address, or several separated by commas or semicolons
 */
function ticketEmailRecipientsAllowed(PDO $conn, int $ticketId, string $to): bool
{
    if (!ticketIsConfidential($conn, $ticketId)) return true;
    $addrs = array_values(array_filter(array_map(
        fn($a) => strtolower(trim($a)), preg_split('/[,;]/', $to) ?: []
    ), fn($a) => $a !== ''));
    if (!$addrs) return false;
    try {
        $st = $conn->prepare("SELECT LOWER(u.email) FROM tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?");
        $st->execute([$ticketId]);
        $requester = trim((string)$st->fetchColumn());
        $in = implode(',', array_fill(0, count($addrs), '?'));
        $st = $conn->prepare("SELECT LOWER(email) FROM analysts WHERE is_active = 1 AND LOWER(email) IN ($in)");
        $st->execute($addrs);
        $analysts = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        return false;                                  // could not check: do not send
    }
    foreach ($addrs as $a) {
        if ($a !== $requester && !in_array($a, $analysts, true)) return false;
    }
    return true;
}

/** What the workflow run log says when Send email is held back. */
const TICKET_EMAIL_CONFIDENTIAL_SKIP = 'Not sent: the ticket is confidential, and a workflow only emails a confidential ticket to its requester or to analysts.';

/**
 * The SELECT expression for a ticket's sensitivity that is safe before
 * Database Verification has added the column. Pass the tickets alias.
 *
 * @param string $alias  the alias the calling query gives the tickets table
 */
function ticketSensitivitySelectSql(PDO $conn, string $alias = 't'): string
{
    return ticketSensitivityReady($conn) ? "$alias.sensitivity" : "'normal'";
}
