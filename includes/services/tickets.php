<?php
/**
 * TicketsService — the shared write rules for tickets: create, the unified
 * update (status/priority/lookups/assignment/booleans/schedule/company-move,
 * with closed_datetime, owner sync, template emails, CSAT auto-trigger and the
 * workflow dispatches), soft delete + restore, notes, and time entries.
 *
 * Shared by the UI endpoints (api/tickets/*.php) and the REST API
 * (api/v1/resources/tickets.php). This is the last + biggest module.
 *
 * TENANCY — tickets are company-scoped (tickets.tenant_id). Every by-id write is
 * gated by the actor's company scope via ctx->companyScope (null = all), the
 * same generic check problems #733 established (canAccessTicket mirrors
 * apiKeyCanAccessTenantRow / analystCanAccessTicket exactly). The acting company
 * for a create / company-move is resolved by the adapter and passed in.
 *
 * TRANSPORT-SPECIFIC BEHAVIOUR is preserved via parameters, so the API stays
 * byte-identical while the UI keeps its exact behaviour:
 *   - $writeAudit: the API writes the ticket_audit trail server-side; the UI
 *     writes it CLIENT-SIDE (JS -> log_ticket_audit.php), so its adapters pass
 *     false for update, and true for delete/restore (which the UI DOES audit
 *     server-side). The shared rules (SQL, closed_datetime, owner sync, template
 *     emails, CSAT, workflow) run either way.
 *   - createTicket($defaultAnalystId, $creationNote): the UI auto-assigns to the
 *     creator and audits "Manual ticket created by <name>"; the API leaves it
 *     unassigned and audits "Created via API (key: X)".
 *   - deleteTimeEntry($restrictToOwner): the UI lets an analyst delete only their
 *     own entry; the API (key-authed) may delete any entry on an accessible
 *     ticket.
 *
 * Canonical behaviour = the API resource's: unknown status/priority/lookup/analyst
 * is a 422, empty note text is a 422, a bad date is a 400, and the update is
 * refused on a trashed ticket (409). The UI converges to these.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class TicketsService
{
    // ======================================================================
    //  Create
    // ======================================================================

    /**
     * Create a ticket (with its initial email row + audit + ticket.created).
     * $tenantId is the adapter-resolved acting company. $defaultAnalystId is the
     * assignee when the caller sends none (UI: the creator; API: null).
     * Returns the new ticket id.
     */
    public static function createTicket(PDO $conn, ActorContext $ctx, int $tenantId, array $in, ?int $defaultAnalystId, string $creationNote): int
    {
        $subject        = trim((string)($in['subject'] ?? ''));
        $description    = trim((string)($in['description'] ?? ''));
        $requesterEmail = strtolower(trim((string)($in['requester_email'] ?? '')));
        $requesterName  = trim((string)($in['requester_name'] ?? ''));

        if ($subject === '') {
            throw new ServiceError('validation', 'missing_field', "'subject' is required.");
        }
        if ($requesterEmail === '' || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
            throw new ServiceError('validation', 'missing_field', "'requester_email' is required and must be a valid email address.");
        }
        if ($requesterName === '') {
            $requesterName = ucfirst(explode('@', $requesterEmail)[0]);
        }

        $statusRes = self::resolveStatus($conn, $in) ?? (function () use ($conn) {
            $row = $conn->query("SELECT id, name, is_closed FROM ticket_statuses WHERE name = 'Open' LIMIT 1")->fetch(PDO::FETCH_ASSOC)
                ?: $conn->query("SELECT id, name, is_closed FROM ticket_statuses WHERE is_default = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            return $row ? [(int)$row['id'], $row['name'], (int)$row['is_closed']] : [null, null, 0];
        })();
        $priorityRes = self::resolvePriority($conn, $in);
        if ($priorityRes === null) {
            $row = $conn->query("SELECT id, name FROM ticket_priorities WHERE name = 'Normal' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $priorityRes = $row ? [(int)$row['id'], $row['name']] : [null, null];
        }

        $departmentId = isset($in['department_id']) && $in['department_id'] !== '' ? (int)$in['department_id'] : null;
        $typeId       = isset($in['ticket_type_id']) && $in['ticket_type_id'] !== '' ? (int)$in['ticket_type_id'] : null;
        $originId     = isset($in['origin_id']) && $in['origin_id'] !== '' ? (int)$in['origin_id'] : null;
        $mailboxId    = isset($in['mailbox_id']) && $in['mailbox_id'] !== '' ? (int)$in['mailbox_id'] : null;

        $analystId = $defaultAnalystId;
        if (isset($in['assigned_analyst_id']) && $in['assigned_analyst_id'] !== '') {
            $analystId = (int)$in['assigned_analyst_id'];
        }

        self::validateLookupId($conn, 'departments', $departmentId, 'department');
        self::validateLookupId($conn, 'ticket_types', $typeId, 'ticket type');
        self::validateLookupId($conn, 'ticket_origins', $originId, 'origin');
        if ($analystId !== null) {
            $aStmt = $conn->prepare("SELECT id FROM analysts WHERE id = ? AND is_active = 1");
            $aStmt->execute([$analystId]);
            if (!$aStmt->fetchColumn()) {
                throw new ServiceError('validation', 'invalid_field', "Unknown or inactive analyst id: {$analystId}");
            }
        }
        if ($mailboxId !== null) {
            $mbStmt = $conn->prepare("SELECT tenant_id FROM target_mailboxes WHERE id = ? AND is_active = 1");
            $mbStmt->execute([$mailboxId]);
            $mb = $mbStmt->fetch(PDO::FETCH_ASSOC);
            $mbOk = (bool)$mb;
            if ($mb && isMultiTenant($conn)) {
                $mbOk = ($mb['tenant_id'] === null) || ((int)$mb['tenant_id'] === $tenantId);
            }
            if (!$mbOk) {
                throw new ServiceError('validation', 'invalid_field', 'The selected mailbox is not available for this company.');
            }
        }

        try {
            $conn->beginTransaction();

            $uStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $uStmt->execute([$requesterEmail]);
            $userId = $uStmt->fetchColumn();
            if ($userId === false) {
                $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, ?, UTC_TIMESTAMP())")
                     ->execute([$requesterEmail, $requesterName]);
                $userId = $conn->lastInsertId();
            }

            $ticketNumber = self::generateTicketNumber($conn);

            $conn->prepare(
                "INSERT INTO tickets (
                    tenant_id, ticket_number, subject, status_id, priority_id, department_id,
                    ticket_type_id, origin_id, assigned_analyst_id, owner_id, user_id,
                    created_datetime, updated_datetime
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([
                $tenantId, $ticketNumber, $subject, $statusRes[0], $priorityRes[0], $departmentId,
                $typeId, $originId, $analystId, $analystId, $userId,
            ]);
            $ticketId = (int)$conn->lastInsertId();

            $bodyHtml    = nl2br(htmlspecialchars($description));
            $bodyPreview = substr(strip_tags($description), 0, 200);
            $conn->prepare(
                "INSERT INTO emails (
                    mailbox_id, subject, from_address, from_name, to_recipients, received_datetime,
                    body_preview, body_content, body_type, has_attachments, importance,
                    is_read, ticket_id, is_initial, direction
                ) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, 'html', 0, 'normal', 1, ?, 1, 'Manual')"
            )->execute([$mailboxId, $subject, $requesterEmail, $requesterName, $requesterEmail, $bodyPreview, $bodyHtml, $ticketId]);

            self::auditWrite($conn, $ticketId, $ctx->actorId, 'Ticket Created', null, $creationNote);

            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }

        try {
            $rb = $conn->prepare("SELECT status_id, priority_id FROM tickets WHERE id = ?");
            $rb->execute([$ticketId]);
            $row = $rb->fetch(PDO::FETCH_ASSOC) ?: [];
            WorkflowEngine::dispatch('ticket.created', [
                'ticket' => [
                    'id'                  => $ticketId,
                    'subject'             => $subject,
                    'priority_id'         => isset($row['priority_id']) ? (int)$row['priority_id'] : null,
                    'status_id'           => isset($row['status_id']) ? (int)$row['status_id'] : null,
                    'department_id'       => $departmentId,
                    'type_id'             => $typeId,
                    'assigned_analyst_id' => $analystId,
                    'created_by'          => $ctx->actorId,
                    'requester_email'     => $requesterEmail,
                ],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in TicketsService create: ' . $wfEx->getMessage());
        }

        return $ticketId;
    }

    // ======================================================================
    //  Update
    // ======================================================================

    /**
     * Apply a partial update to a ticket. $writeAudit toggles the server-side
     * audit trail (API: true; UI: false — it audits client-side). Returns void;
     * the adapter reloads for its response.
     */
    public static function updateTicket(PDO $conn, ActorContext $ctx, int $ticketId, array $in, bool $writeAudit): void
    {
        $current = self::loadTicket($conn, $ctx, $ticketId);   // 404 (unknown / out of scope)
        if ($current['deleted_datetime'] !== null) {
            throw new ServiceError('conflict', 'conflict', 'Ticket is in the trash. Restore it before updating.');
        }
        if (!$in) {
            throw new ServiceError('validation', 'missing_field', 'No fields to update.');
        }
        $actorId = $ctx->actorId;

        $updates = [];
        $args    = [];
        $audits  = [];

        if (array_key_exists('subject', $in)) {
            $subject = trim((string)$in['subject']);
            if ($subject === '') {
                throw new ServiceError('validation', 'invalid_field', "'subject' cannot be empty.");
            }
            if ($subject !== $current['subject']) {
                $updates[] = 'subject = ?';
                $args[]    = $subject;
                $audits[]  = ['Subject', $current['subject'], $subject];
            }
        }

        $oldIsClosed = (int)($current['status_is_closed'] ?? 0);
        $newStatusId = null;
        $newIsClosed = null;
        $statusRes = self::resolveStatus($conn, $in);
        if ($statusRes !== null) {
            [$newStatusId, $newStatusName, $newIsClosed] = $statusRes;
            if ($newStatusId !== (int)$current['status_id']) {
                $updates[] = 'status_id = ?';
                $args[]    = $newStatusId;
                if ($newIsClosed && !$oldIsClosed)  $updates[] = 'closed_datetime = UTC_TIMESTAMP()';
                if (!$newIsClosed && $oldIsClosed)  $updates[] = 'closed_datetime = NULL';
                $audits[] = ['Status', $current['status_name'], $newStatusName];
            } else {
                $newStatusId = null;
            }
        }

        $priorityChanged = false;
        $priorityRes = self::resolvePriority($conn, $in);
        if ($priorityRes !== null) {
            [$newPriorityId, $newPriorityName] = $priorityRes;
            if ($newPriorityId !== ($current['priority_id'] !== null ? (int)$current['priority_id'] : null)) {
                $updates[] = 'priority_id = ?';
                $args[]    = $newPriorityId;
                $audits[]  = ['Priority', $current['priority_name'], $newPriorityName];
                $priorityChanged = true;
            }
        }

        foreach ([
            'department_id'  => ['departments',    'department',  'Department',  'department_name'],
            'ticket_type_id' => ['ticket_types',   'ticket type', 'Ticket Type', 'type_name'],
            'origin_id'      => ['ticket_origins', 'origin',      'Origin',      'origin_name'],
        ] as $field => [$table, $label, $auditField, $currentNameKey]) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $newId = ($in[$field] === '' || $in[$field] === null) ? null : (int)$in[$field];
            $newName = $newId !== null ? self::validateLookupId($conn, $table, $newId, $label) : null;
            if ($newId !== ($current[$field] !== null ? (int)$current[$field] : null)) {
                $updates[] = "$field = ?";
                $args[]    = $newId;
                $audits[]  = [$auditField, $current[$currentNameKey], $newName];
            }
        }

        $oldAnalystId = $current['assigned_analyst_id'] !== null ? (int)$current['assigned_analyst_id'] : null;
        $newAnalystId = null;
        $analystSent  = array_key_exists('assigned_analyst_id', $in);
        if ($analystSent) {
            $newAnalystId = ($in['assigned_analyst_id'] === '' || $in['assigned_analyst_id'] === null)
                ? null : (int)$in['assigned_analyst_id'];
            $newAnalystName = null;
            if ($newAnalystId !== null) {
                $aStmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ? AND is_active = 1");
                $aStmt->execute([$newAnalystId]);
                $newAnalystName = $aStmt->fetchColumn();
                if ($newAnalystName === false) {
                    throw new ServiceError('validation', 'invalid_field', "Unknown or inactive analyst id: {$newAnalystId}");
                }
            }
            if ($newAnalystId !== $oldAnalystId) {
                $updates[] = 'assigned_analyst_id = ?';
                $args[]    = $newAnalystId;
                $updates[] = 'owner_id = ?';
                $args[]    = $newAnalystId;
                $audits[]  = ['Owner', $current['analyst_name'], $newAnalystName];
            } else {
                $analystSent = false;
            }
        }

        foreach (['first_time_fix' => 'First Time Fix', 'it_training_provided' => 'IT Training Provided'] as $field => $auditField) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $newVal = ($in[$field] === null || $in[$field] === '') ? null : ((int)(bool)$in[$field]);
            $oldVal = $current[$field] !== null ? (int)$current[$field] : null;
            if ($newVal !== $oldVal) {
                $updates[] = "$field = ?";
                $args[]    = $newVal;
                $audits[]  = [$auditField, $oldVal === null ? null : ($oldVal ? 'Yes' : 'No'), $newVal === null ? null : ($newVal ? 'Yes' : 'No')];
            }
        }

        if (array_key_exists('work_start_at', $in)) {
            $newWork = ($in['work_start_at'] === null || $in['work_start_at'] === '')
                ? null : self::parseDate((string)$in['work_start_at'], 'work_start_at');
            if ($newWork !== $current['work_start_datetime']) {
                $updates[] = 'work_start_datetime = ?';
                $args[]    = $newWork;
            }
        }

        // Move to another company — gated on the actor's scope for the target.
        if (array_key_exists('company_id', $in) && $in['company_id'] !== '' && $in['company_id'] !== null) {
            $newTenantId = (int)$in['company_id'];
            $curTenantId = $current['tenant_id'] !== null ? (int)$current['tenant_id'] : getDefaultTenantId($conn);
            if ($newTenantId !== $curTenantId) {
                $target = getTenantById($conn, $newTenantId);
                if (!$target) {
                    throw new ServiceError('validation', 'invalid_field', "Unknown company id: {$newTenantId}");
                }
                if (isMultiTenant($conn) && $ctx->companyScope !== null && !in_array($newTenantId, $ctx->companyScope, true)) {
                    throw new ServiceError('forbidden', 'forbidden', 'This API key is not scoped to the target company.');
                }
                $updates[] = 'tenant_id = ?';
                $args[]    = $newTenantId;
                $audits[]  = ['Company', $current['company_name'], $target['name']];
            }
        }

        if (!$updates) {
            return; // idempotent
        }

        $updates[] = 'updated_datetime = UTC_TIMESTAMP()';
        $args[]    = $ticketId;
        $conn->prepare('UPDATE tickets SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

        if ($writeAudit) {
            foreach ($audits as [$field, $old, $new]) {
                self::auditWrite($conn, $ticketId, $actorId, $field, $old, $new);
            }
        }

        // Template emails + CSAT — non-blocking, same as assign_ticket.php.
        try {
            require_once __DIR__ . '/../template_email.php';
            if ($analystSent && $newAnalystId !== null) {
                sendTemplateEmail($conn, $ticketId, 'ticket_assigned');
            }
            if ($newIsClosed && !$oldIsClosed) {
                sendTemplateEmail($conn, $ticketId, 'ticket_closed');
                require_once __DIR__ . '/../csat.php';
                try {
                    if (csatGetSetting($conn, 'csat_mode', 'off') === 'auto') {
                        sendCsatSurvey($conn, $ticketId, $actorId);
                    }
                } catch (Exception $csEx) {
                    error_log('CSAT auto-trigger failed for ticket ' . $ticketId . ': ' . $csEx->getMessage());
                }
            }
        } catch (Exception $tplEx) {
            error_log('Template email error in TicketsService update: ' . $tplEx->getMessage());
        }

        // Workflow dispatches — canonical post-update payload.
        try {
            $rb = $conn->prepare(
                "SELECT t.id, t.subject, t.priority_id, t.status_id, t.department_id,
                        t.ticket_type_id AS type_id, t.assigned_analyst_id, t.owner_id,
                        t.origin_id, t.user_id AS created_by, u.email AS requester_email
                 FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?"
            );
            $rb->execute([$ticketId]);
            $r = $rb->fetch(PDO::FETCH_ASSOC) ?: [];
            $payload = [
                'id'                  => $ticketId,
                'subject'             => $r['subject'] ?? null,
                'priority_id'         => isset($r['priority_id']) ? (int)$r['priority_id'] : null,
                'status_id'           => isset($r['status_id']) ? (int)$r['status_id'] : null,
                'department_id'       => isset($r['department_id']) ? (int)$r['department_id'] : null,
                'type_id'             => isset($r['type_id']) ? (int)$r['type_id'] : null,
                'assigned_analyst_id' => isset($r['assigned_analyst_id']) ? (int)$r['assigned_analyst_id'] : null,
                'owner_id'            => isset($r['owner_id']) ? (int)$r['owner_id'] : null,
                'origin_id'           => isset($r['origin_id']) ? (int)$r['origin_id'] : null,
                'created_by'          => isset($r['created_by']) ? (int)$r['created_by'] : null,
                'requester_email'     => $r['requester_email'] ?? null,
            ];
            $oldStatusId = $current['status_id'] !== null ? (int)$current['status_id'] : null;
            if ($newStatusId !== null && $newStatusId !== $oldStatusId) {
                WorkflowEngine::dispatch('ticket.status_changed', [
                    'ticket' => $payload, 'old_status_id' => $oldStatusId, 'new_status_id' => $newStatusId,
                ]);
            }
            if ($priorityChanged) {
                WorkflowEngine::dispatch('ticket.priority_changed', [
                    'ticket'          => $payload,
                    'old_priority_id' => $current['priority_id'] !== null ? (int)$current['priority_id'] : null,
                    'new_priority_id' => $payload['priority_id'],
                ]);
            }
            if ($analystSent && $newAnalystId !== null && $newAnalystId !== $oldAnalystId) {
                WorkflowEngine::dispatch('ticket.assigned', [
                    'ticket' => $payload, 'analyst_id' => $newAnalystId, 'team_id' => null,
                ]);
            }
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in TicketsService update: ' . $wfEx->getMessage());
        }
    }

    // ======================================================================
    //  Soft delete / restore
    // ======================================================================

    /** Move a ticket to the trash. $writeAudit -> the 'Trash' audit row (UI: true; API: false). */
    public static function deleteTicket(PDO $conn, ActorContext $ctx, int $ticketId, bool $writeAudit): void
    {
        $ticket = self::loadTicket($conn, $ctx, $ticketId);   // 404
        if ($ticket['deleted_datetime'] !== null) {
            throw new ServiceError('conflict', 'conflict', 'Ticket is already in the trash.');
        }
        $conn->prepare("UPDATE tickets SET deleted_datetime = UTC_TIMESTAMP(), deleted_by = ? WHERE id = ?")
             ->execute([$ctx->actorId, $ticketId]);
        if ($writeAudit) {
            self::auditWrite($conn, $ticketId, $ctx->actorId, 'Trash', 'active', 'moved to trash');
        }
        WorkflowEngine::dispatch('ticket.deleted', ['ticket' => self::eventTicket($ticket)]);
    }

    /** Restore a ticket from the trash. $writeAudit -> the 'Trash' audit row. */
    public static function restoreTicket(PDO $conn, ActorContext $ctx, int $ticketId, bool $writeAudit): void
    {
        $ticket = self::loadTicket($conn, $ctx, $ticketId);   // 404
        if ($ticket['deleted_datetime'] === null) {
            throw new ServiceError('conflict', 'conflict', 'Ticket is not in the trash.');
        }
        $conn->prepare("UPDATE tickets SET deleted_datetime = NULL, deleted_by = NULL WHERE id = ?")->execute([$ticketId]);
        if ($writeAudit) {
            self::auditWrite($conn, $ticketId, $ctx->actorId, 'Trash', 'in trash', 'restored');
        }
        WorkflowEngine::dispatch('ticket.restored', ['ticket' => self::eventTicket($ticket)]);
    }

    /** The canonical ticket workflow payload, built from a loaded (joined) row. */
    private static function eventTicket(array $r): array
    {
        return [
            'id'                  => (int)$r['id'],
            'subject'             => $r['subject'] ?? null,
            'priority_id'         => isset($r['priority_id']) ? (int)$r['priority_id'] : null,
            'status_id'           => isset($r['status_id']) ? (int)$r['status_id'] : null,
            'department_id'       => isset($r['department_id']) ? (int)$r['department_id'] : null,
            'type_id'             => isset($r['ticket_type_id']) ? (int)$r['ticket_type_id'] : null,
            'assigned_analyst_id' => isset($r['assigned_analyst_id']) ? (int)$r['assigned_analyst_id'] : null,
            'owner_id'            => isset($r['owner_id']) ? (int)$r['owner_id'] : null,
            'origin_id'           => isset($r['origin_id']) ? (int)$r['origin_id'] : null,
            'created_by'          => isset($r['user_id']) ? (int)$r['user_id'] : null,
            'requester_email'     => $r['requester_email'] ?? null,
        ];
    }

    // ======================================================================
    //  Notes
    // ======================================================================

    /** Add a note. Returns the note id; touches the ticket's updated_datetime. */
    public static function createNote(PDO $conn, ActorContext $ctx, int $ticketId, array $in): int
    {
        self::loadTicket($conn, $ctx, $ticketId);   // 404
        $text = trim((string)($in['text'] ?? ''));
        if ($text === '') {
            throw new ServiceError('validation', 'missing_field', "'text' is required.");
        }
        $isInternal = array_key_exists('is_internal', $in) ? (bool)$in['is_internal'] : true;
        $conn->prepare("INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal) VALUES (?, ?, ?, ?)")
             ->execute([$ticketId, $ctx->actorId, $text, $isInternal ? 1 : 0]);
        $noteId = (int)$conn->lastInsertId();
        $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$ticketId]);
        return $noteId;
    }

    // ======================================================================
    //  Time entries
    // ======================================================================

    /** Log time on a ticket. Returns the entry id. */
    public static function createTimeEntry(PDO $conn, ActorContext $ctx, int $ticketId, array $in): int
    {
        self::loadTicket($conn, $ctx, $ticketId);   // 404
        $minutes = isset($in['minutes']) ? (int)$in['minutes'] : 0;
        if ($minutes <= 0) {
            throw new ServiceError('validation', 'missing_field', "'minutes' is required and must be a positive integer.");
        }
        $notes = trim((string)($in['notes'] ?? ''));
        $entryAt = isset($in['entry_at']) && $in['entry_at'] !== ''
            ? self::parseDate((string)$in['entry_at'], 'entry_at')
            : gmdate('Y-m-d H:i:s');
        $conn->prepare(
            "INSERT INTO ticket_time_entries (ticket_id, analyst_id, notes, time_spent_minutes, entry_datetime)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$ticketId, $ctx->actorId, $notes !== '' ? $notes : null, $minutes, $entryAt]);
        return (int)$conn->lastInsertId();
    }

    /** Edit a time entry (UI-only; only the owning analyst may edit). */
    public static function updateTimeEntry(PDO $conn, ActorContext $ctx, int $entryId, int $ticketId, array $in): void
    {
        self::loadTicket($conn, $ctx, $ticketId);   // 404
        $minutes = isset($in['minutes']) ? (int)$in['minutes'] : 0;
        if ($minutes <= 0) {
            throw new ServiceError('validation', 'invalid_field', 'Time spent must be greater than zero minutes.');
        }
        $notes = trim((string)($in['notes'] ?? ''));
        $entryAt = isset($in['entry_at']) && $in['entry_at'] !== ''
            ? self::parseDate((string)$in['entry_at'], 'entry_at')
            : gmdate('Y-m-d H:i:s');

        $stmt = $conn->prepare("SELECT analyst_id FROM ticket_time_entries WHERE id = ? AND is_active = 1");
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Time entry not found.');
        }
        if ((int)$row['analyst_id'] !== $ctx->actorId) {
            throw new ServiceError('forbidden', 'forbidden', 'You can only edit your own time entries.');
        }
        $conn->prepare("UPDATE ticket_time_entries SET notes = ?, time_spent_minutes = ?, entry_datetime = ? WHERE id = ?")
             ->execute([$notes, $minutes, $entryAt, $entryId]);
    }

    /**
     * Soft-delete a time entry. $ticketId scopes it (API route); pass null to
     * resolve the ticket from the entry (the UI passes only the entry id).
     * $restrictToOwner enforces the UI's "only your own entry" rule.
     */
    public static function deleteTimeEntry(PDO $conn, ActorContext $ctx, int $entryId, ?int $ticketId, bool $restrictToOwner): void
    {
        if ($ticketId !== null) {
            self::loadTicket($conn, $ctx, $ticketId);   // 404
            $stmt = $conn->prepare("SELECT analyst_id FROM ticket_time_entries WHERE id = ? AND ticket_id = ? AND is_active = 1");
            $stmt->execute([$entryId, $ticketId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new ServiceError('not_found', 'not_found', 'Time entry not found.');
            }
        } else {
            $stmt = $conn->prepare("SELECT analyst_id, ticket_id FROM ticket_time_entries WHERE id = ? AND is_active = 1");
            $stmt->execute([$entryId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new ServiceError('not_found', 'not_found', 'Time entry not found.');
            }
            if (!self::canAccessTicket($conn, $ctx, (int)$row['ticket_id'])) {
                throw new ServiceError('not_found', 'not_found', 'Time entry not found.');
            }
        }
        if ($restrictToOwner && (int)$row['analyst_id'] !== $ctx->actorId) {
            throw new ServiceError('forbidden', 'forbidden', 'You can only delete your own time entries.');
        }
        $conn->prepare("UPDATE ticket_time_entries SET is_active = 0 WHERE id = ?")->execute([$entryId]);
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /**
     * Load the joined ticket row, enforcing the actor's company scope; 404 otherwise.
     *
     * PUBLIC rather than private since #912: the merge engine (includes/ticket_merge.php)
     * has to check every ticket in a merge before moving a single row, and it must use
     * THIS scope rule rather than its own copy. A second implementation of "may this
     * actor see this ticket" is exactly the duplication that goes wrong silently —
     * nothing breaks when the copies drift, one of them just quietly shows more.
     * Read-only and side-effect free, so widening it changes no behaviour.
     */
    public static function loadTicket(PDO $conn, ActorContext $ctx, int $ticketId): array
    {
        $stmt = $conn->prepare(
            "SELECT t.*,
                    ts.name AS status_name, ts.is_closed AS status_is_closed,
                    tp.name AS priority_name,
                    tt.name AS type_name,
                    tor.name AS origin_name,
                    d.name AS department_name,
                    a.full_name AS analyst_name,
                    tn.name AS company_name
             FROM tickets t
             LEFT JOIN ticket_statuses   ts  ON ts.id  = t.status_id
             LEFT JOIN ticket_priorities tp  ON tp.id  = t.priority_id
             LEFT JOIN ticket_types      tt  ON tt.id  = t.ticket_type_id
             LEFT JOIN ticket_origins    tor ON tor.id = t.origin_id
             LEFT JOIN departments       d   ON d.id   = t.department_id
             LEFT JOIN analysts          a   ON a.id   = t.assigned_analyst_id
             LEFT JOIN tenants           tn  ON tn.id  = t.tenant_id
             WHERE t.id = ?"
        );
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
        }
        if ($ctx->companyScope !== null && isMultiTenant($conn)) {
            $tid = $row['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
            if (!in_array($tid, $ctx->companyScope, true)) {
                throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
            }
        }
        return $row;
    }

    /** May the actor access this ticket (by its company)? Mirror of apiKeyCanAccessTenantRow. */
    private static function canAccessTicket(PDO $conn, ActorContext $ctx, int $ticketId): bool
    {
        if ($ticketId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("SELECT tenant_id FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ($ctx->companyScope === null || !isMultiTenant($conn)) {
            return true;
        }
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        return in_array($tid, $ctx->companyScope, true);
    }

    private static function auditWrite(PDO $conn, int $ticketId, int $analystId, string $field, ?string $old, ?string $new): void
    {
        $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$ticketId, $analystId, $field, $old, $new]);
    }

    /** Unique random ticket number (XXX-###-#####), same format as the UI. */
    private static function generateTicketNumber(PDO $conn): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
            $ticketNumber = $letters . '-' . rand(100, 999) . '-' . str_pad((string)rand(0, 99999), 5, '0', STR_PAD_LEFT);
            $check = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number = ?");
            $check->execute([$ticketNumber]);
            if (!(int)$check->fetchColumn()) {
                return $ticketNumber;
            }
        }
        throw new Exception('Failed to generate unique ticket number');
    }

    /** Resolve a status by name or id. Returns [id, name, is_closed] or null. */
    private static function resolveStatus(PDO $conn, array $in): ?array
    {
        if (isset($in['status_id']) && $in['status_id'] !== '' && $in['status_id'] !== null) {
            $stmt = $conn->prepare("SELECT id, name, is_closed FROM ticket_statuses WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$in['status_id']]);
        } elseif (isset($in['status']) && trim((string)$in['status']) !== '') {
            $stmt = $conn->prepare("SELECT id, name, is_closed FROM ticket_statuses WHERE name = ? LIMIT 1");
            $stmt->execute([trim((string)$in['status'])]);
        } else {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown status: ' . ($in['status'] ?? $in['status_id']));
        }
        return [(int)$row['id'], $row['name'], (int)$row['is_closed']];
    }

    /** Resolve a priority by name or id. Empty string/null id = clear. */
    private static function resolvePriority(PDO $conn, array $in): ?array
    {
        if (array_key_exists('priority_id', $in)) {
            if ($in['priority_id'] === '' || $in['priority_id'] === null) {
                return [null, null];
            }
            $stmt = $conn->prepare("SELECT id, name FROM ticket_priorities WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$in['priority_id']]);
        } elseif (isset($in['priority']) && trim((string)$in['priority']) !== '') {
            $stmt = $conn->prepare("SELECT id, name FROM ticket_priorities WHERE name = ? LIMIT 1");
            $stmt->execute([trim((string)$in['priority'])]);
        } else {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown priority: ' . ($in['priority'] ?? $in['priority_id']));
        }
        return [(int)$row['id'], $row['name']];
    }

    /** Validate a simple lookup id (returns the row name); 422 on unknown id. Null/'' -> null. */
    private static function validateLookupId(PDO $conn, string $table, $id, string $label): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        $stmt = $conn->prepare("SELECT name FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            throw new ServiceError('validation', 'invalid_field', "Unknown {$label} id: {$id}");
        }
        return $name;
    }

    /** Parse a date to UTC 'Y-m-d H:i:s' (mirrors apiParseDate; 400 on garbage). */
    private static function parseDate(string $value, string $field): string
    {
        $v = trim($value);
        try {
            $dt = new DateTimeImmutable($v, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            throw new ServiceError('bad_request', 'invalid_parameter', "'{$field}' is not a valid date/time. Use ISO 8601, e.g. 2026-07-02T09:00:00Z.");
        }
    }
}
