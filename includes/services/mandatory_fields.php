<?php
/**
 * Mandatory fields at closure.
 *
 * An administrator ticks the ticket properties that must hold a value before a
 * ticket may close (Tickets → Settings → Mandatory fields), and chooses what
 * happens when a close arrives with some of them empty:
 *
 *   warn     the analyst is told and may close anyway
 *   notify   the same, and the addresses on the tab are emailed
 *   block    the close is refused until the fields are filled
 *
 * plus a separate "record it" switch that writes an internal note naming the
 * empty fields and who closed the ticket. Recording only applies to the two
 * warn modes: under block the ticket never closes, so there is nothing to record.
 *
 * ---------------------------------------------------------------------------
 * WHERE IT IS ENFORCED
 * ---------------------------------------------------------------------------
 * TicketsService::updateTicket() - the inbox, bulk actions, assign/schedule and
 * the v1 REST API all close tickets through it - and the workflow engine's
 * set-status action, which writes the status itself. The check runs BEFORE the
 * write, against the ticket as it will be AFTER the save, so a value filled in by
 * the same request that closes the ticket counts.
 *
 * Deliberately NOT enforced on a merge. Merging closes the tickets merged away,
 * and refusing a merge because a ticket that is about to disappear into another
 * one has no category helps nobody.
 *
 * ---------------------------------------------------------------------------
 * A FIELD THE TICKET CANNOT SHOW IS NEVER REQUIRED
 * ---------------------------------------------------------------------------
 * Category, category at close and resolution code each have their own on/off
 * switch per company, and the team picker only exists on an install that has
 * teams. Requiring a field the analyst has no way to fill would trap every
 * ticket behind it, so such a field is skipped for that ticket.
 */

require_once __DIR__ . '/../service_context.php';   // ServiceError, thrown below (GH #288)
require_once __DIR__ . '/../tenant_settings.php';
require_once __DIR__ . '/ticket_fields.php';   // custom fields required at closing (#179)

final class MandatoryFieldsService
{
    /**
     * Every property the tab offers. key => [column, English label].
     *
     * The English label is what the ticket note and the email say; the settings
     * tab and the inbox use the translated label under
     * tickets.settings.mandatory.fields.<key>.
     *
     * ⚠️ OWNER, NOT ANALYST. The ticket stores the assigned person twice
     * (assigned_analyst_id and owner_id); owner_id is the one the screen reads and
     * calls "Owner", and every writer sets both. Offering both here would list one
     * field twice under two names.
     */
    public const FIELDS = [
        'priority'         => ['priority_id',          'Priority'],
        'ticket_type'      => ['ticket_type_id',       'Type'],
        'department'       => ['department_id',        'Department'],
        'category'         => ['category_id',          'Category'],
        'closure_category' => ['closure_category_id',  'Category at close'],
        'resolution_code'  => ['resolution_code_id',   'Resolution code'],
        'team'             => ['assigned_team_id',     'Team'],
        'owner'            => ['owner_id',             'Owner'],
        'requester'        => ['user_id',              'Requester'],
        'origin'           => ['origin_id',            'Origin'],
        'first_time_fix'   => ['first_time_fix',       'First time fix'],
        'it_training'      => ['it_training_provided', 'IT training provided'],
        'work_start'       => ['work_start_datetime',  'Scheduled work'],
    ];

    public const MODES = ['warn', 'notify', 'block'];

    /** Upper bound on notification addresses, so the tab cannot become a mailing list. */
    public const MAX_NOTIFY = 10;

    /**
     * Test seam for the notification email: fn(PDO, int $ticketId, array $to,
     * string $subject, string $html): void. Null sends for real.
     *
     * @var ?callable
     */
    public static $mailer = null;

    /**
     * The stored configuration for a company (null = install-wide).
     *
     * @return array{fields:string[], mode:string, notify:string[], record:bool}
     */
    public static function settings(PDO $conn, ?int $tenantId): array
    {
        $fields = array_values(array_filter(
            array_map('trim', explode(',', (string) tenantSetting($conn, $tenantId, SETTING_TICKET_MANDATORY_FIELDS, ''))),
            fn($k) => isset(self::FIELDS[$k])
        ));
        $mode = (string) tenantSetting($conn, $tenantId, SETTING_TICKET_MANDATORY_MODE, 'warn');
        if (!in_array($mode, self::MODES, true)) $mode = 'warn';
        $notify = self::parseAddresses((string) tenantSetting($conn, $tenantId, SETTING_TICKET_MANDATORY_NOTIFY, ''));
        // Recording defaults ON: a warning that leaves no trace is a gate that
        // reports control which is not there.
        $record = tenantSettingOn($conn, $tenantId, SETTING_TICKET_MANDATORY_RECORD, true);

        return ['fields' => $fields, 'mode' => $mode, 'notify' => $notify, 'record' => $record];
    }

    /**
     * Split and validate an address list. Invalid entries are dropped, so the
     * saved list and the one actually mailed can never disagree.
     *
     * @param string $raw  email addresses as typed, separated by spaces, commas or semicolons
     * @return string[]
     */
    public static function parseAddresses(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $a) {
            $a = trim($a);
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) && !in_array(strtolower($a), array_map('strtolower', $out), true)) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /**
     * The required fields this ticket has left empty.
     *
     * Custom fields (#179) come after the built-in ones, keyed `cf:<key>` with
     * their own name as the label. They are required by the field itself -
     * "Required when closing" on Tickets -> Settings -> Custom fields - so they
     * count even when no built-in field is ticked here, and they follow this
     * tab's warn / warn and email / refuse setting like everything else.
     *
     * @param array $row the ticket as it will be after the save - `tickets`
     *                   column names, any other keys ignored
     * @param ?array $pendingCustom custom field answers written by the same
     *                   save, [field_key => value], which count
     * @return array<string,string> key => English label, in tab order
     */
    public static function missing(PDO $conn, ?int $tenantId, array $row, ?array $pendingCustom = null): array
    {
        $cfg = self::settings($conn, $tenantId);
        $custom = TicketFieldsService::missingOnClose($conn, $row, $pendingCustom);
        if (!$cfg['fields']) return $custom;

        $out = [];
        foreach (self::FIELDS as $key => [$column, $label]) {
            if (!in_array($key, $cfg['fields'], true)) continue;
            if (!self::fieldAvailable($conn, $tenantId, $key)) continue;
            $v = $row[$column] ?? null;
            if ($v === null || $v === '') {
                $out[$key] = $label;
            }
        }
        return $out + $custom;
    }

    /**
     * Can this field be filled in on a ticket belonging to this company?
     *
     * @param string $key  a FIELDS key, e.g. 'category' or 'team'
     */
    public static function fieldAvailable(PDO $conn, ?int $tenantId, string $key): bool
    {
        switch ($key) {
            case 'category':         return ticketCategoryOn($conn, $tenantId);
            case 'closure_category': return ticketClosureCategoryOn($conn, $tenantId);
            case 'resolution_code':  return ticketResolutionCodeOn($conn, $tenantId);
            case 'team':
                // The inbox draws the team picker only when active teams exist.
                static $hasTeams = null;
                if ($hasTeams === null) {
                    try {
                        $hasTeams = (int) $conn->query("SELECT COUNT(*) FROM teams WHERE is_active = 1 OR is_active IS NULL")->fetchColumn() > 0;
                    } catch (Throwable) {
                        $hasTeams = false;
                    }
                }
                return $hasTeams;
            default:
                return true;
        }
    }

    /**
     * Refuse the close under 'block'. Call BEFORE anything is written: thrown
     * after the update, the ticket would already be closed and the caller would
     * report a failure the database disagrees with.
     *
     * @param array<string,string> $missing from missing()
     */
    public static function assertClosureAllowed(PDO $conn, ?int $tenantId, array $missing): void
    {
        if (!$missing) return;
        if (self::settings($conn, $tenantId)['mode'] !== 'block') return;

        throw new ServiceError(
            'validation',
            'mandatory_fields_missing',
            'This ticket cannot be closed until these fields are filled in: ' . implode(', ', $missing)
        );
    }

    /**
     * After a close that went ahead with fields empty: write the note and send
     * the email, as configured. Never throws - a failed note or email must not
     * turn a close that happened into an error.
     *
     * @param array<string,string> $missing from missing(), computed before the write
     */
    public static function afterClosure(PDO $conn, ActorContext $ctx, int $ticketId, ?int $tenantId, array $missing): void
    {
        if (!$missing) return;
        $cfg = self::settings($conn, $tenantId);
        if ($cfg['mode'] === 'block') return;   // unreachable with fields missing, but never act on it

        $closedBy = self::closedByLine($ctx);

        if ($cfg['record']) {
            $note = "⚠️ Ticket closed with " . count($missing) . " mandatory field(s) empty.\n"
                  . $closedBy . "\n\n"
                  . implode("\n", array_map(fn($l) => '  • ' . $l, $missing));
            try {
                self::writeNote($conn, $ctx, $ticketId, $note);
            } catch (Throwable $e) {
                error_log('[mandatory-fields] could not record closure note for ticket ' . $ticketId . ': ' . $e->getMessage());
            }
        }

        if ($cfg['mode'] === 'notify' && $cfg['notify']) {
            try {
                self::notify($conn, $ticketId, $cfg['notify'], $missing, $closedBy);
            } catch (Throwable $e) {
                // Each recipient is already in the email send log, failures included.
                error_log('[mandatory-fields] notification failed for ticket ' . $ticketId . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * An internal note from an analyst, or a 'Workflow Note' audit entry when
     * nobody is acting. ticket_notes.analyst_id is NOT NULL with a foreign key to
     * analysts, so a workflow (actor 0) cannot write there - which is why the
     * workflow engine's own "add note" action writes to ticket_audit with a NULL
     * analyst, and this follows it. UTC at rest (GH #126).
     *
     * @param string $note  the note text
     */
    public static function writeNote(PDO $conn, ActorContext $ctx, int $ticketId, string $note): void
    {
        if ($ctx->actorId > 0) {
            $conn->prepare(
                "INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal, created_datetime)
                 VALUES (?, ?, ?, 1, UTC_TIMESTAMP())"
            )->execute([$ticketId, $ctx->actorId, $note]);
            return;
        }
        $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, NULL, 'Workflow Note', NULL, ?, UTC_TIMESTAMP())"
        )->execute([$ticketId, $note]);
    }

    /** "Closed by Jo Bloggs via the API." / "Closed by a workflow." */
    public static function closedByLine(ActorContext $ctx): string
    {
        if ($ctx->source === 'workflow') {
            return 'Closed by a workflow.';
        }
        $who = $ctx->actorName !== '' ? $ctx->actorName : 'analyst #' . $ctx->actorId;
        $via = $ctx->source === 'api' ? 'the API' : 'the web interface';
        return "Closed by {$who} via {$via}.";
    }

    /**
     * Emails the chosen people that a ticket was closed with mandatory fields empty, listing them.
     *
     * @param array $to  the email addresses to tell, from parseAddresses()
     * @param array $missing  [field key => English label] of the fields left empty
     * @param string $closedBy  who closed it, from closedByLine()
     */
    private static function notify(PDO $conn, int $ticketId, array $to, array $missing, string $closedBy): void
    {
        require_once __DIR__ . '/../template_email.php';
        $merge = buildTicketMergeData($conn, $ticketId) ?: [];
        $ref   = (string) ($merge['ticket_reference'] ?? ('#' . $ticketId));
        $subj  = (string) ($merge['ticket_subject'] ?? '');

        $subject = "[Closed with fields missing] {$ref}" . ($subj !== '' ? " - {$subj}" : '');
        $items = implode('', array_map(fn($l) => '<li>' . htmlspecialchars($l) . '</li>', $missing));
        $html = '
<div style="font-family: Arial, sans-serif; color: #333; line-height: 1.5; max-width: 600px;">
    <div style="background:#f59e0b;color:white;padding:14px 18px;border-radius:4px 4px 0 0;font-weight:600;font-size:15px;">
        A ticket was closed with mandatory fields empty.
    </div>
    <div style="border:1px solid #e5e7eb;border-top:none;padding:18px;border-radius:0 0 4px 4px;font-size:13px;">
        <p style="margin:0 0 10px;"><strong>' . htmlspecialchars($ref) . '</strong> &mdash; ' . htmlspecialchars($subj) . '</p>
        <p style="margin:0 0 6px;">' . htmlspecialchars($closedBy) . ' Empty:</p>
        <ul style="margin:0;">' . $items . '</ul>
    </div>
    <div style="margin-top:12px;font-size:11px;color:#999;">
        Sent because Tickets &rsaquo; Settings &rsaquo; Mandatory fields is set to warn and notify.
    </div>
</div>';

        if (self::$mailer) {
            (self::$mailer)($conn, $ticketId, $to, $subject, $html);
            return;
        }
        internalTicketEmail($conn, $ticketId, $to, $subject, $html, 'closure_alert');
    }
}
