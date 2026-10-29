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
require_once __DIR__ . '/../ticket_numbering.php';
require_once __DIR__ . '/../ticket_categories.php';   // category paths + depth cap (#1540)
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';
require_once __DIR__ . '/../calendar_sync/push.php';   // scheduled work -> the owner's calendar (GH #75)
require_once __DIR__ . '/checklists.php';              // mandatory SOP steps at closure (PR #141)
require_once __DIR__ . '/mandatory_fields.php';        // mandatory ticket fields at closure
require_once __DIR__ . '/ticket_fields.php';           // custom ticket fields (#179)
require_once __DIR__ . '/../ticket_sensitivity.php';   // Normal / Confidential (discussion #62)
require_once __DIR__ . '/../ticket_workspace.php';

class TicketsService
{
    /**
     * How long a scheduled ticket lasts when nobody said.
     *
     * Applies to rows written before work_end_datetime existed, which are NOT
     * backfilled — an upgrade changes nothing on disk, and every reader resolves
     * the same answer from this one constant instead of each inventing its own.
     * Deliberately not a setting: the default only ever reaches a ticket whose
     * duration was never chosen, so there is nothing here for an administrator to
     * be right or wrong about yet.
     */
    public const SCHEDULE_DEFAULT_MINUTES = 60;

    // ======================================================================
    //  Create
    // ======================================================================

    /**
     * Create a ticket (with its initial email row + audit + ticket.created).
     * $tenantId is the adapter-resolved acting company. $defaultAnalystId is the
     * assignee when the caller sends none (UI: the creator; API: null).
     * Returns the new ticket id.
     *
     * @param array $in  subject (required), description, requester_email (required unless user_id), requester_name, user_id
     *     (a requester the caller has already authorised), status_id/status, priority_id/priority, department_id,
     *     ticket_type_id, origin_id, category_id, assigned_analyst_id, assigned_team_id, mailbox_id, sensitivity,
     *     custom_fields ([field_key => value]; PRESENT means this channel can ask, so the fields required when
     *     logging are enforced - the New ticket form and the API pass it, an alert raised by the system does not)
     * @param ?int $defaultAnalystId  the assignee when $in names none (UI: the creator; API: null for unassigned)
     * @param string $creationNote  the text of the 'Ticket Created' audit row, e.g. "Created via API (key: X)"
     */
    public static function createTicket(PDO $conn, ActorContext $ctx, int $tenantId, array $in, ?int $defaultAnalystId, string $creationNote): int
    {
        $subject        = trim((string)($in['subject'] ?? ''));
        $description    = trim((string)($in['description'] ?? ''));
        $requesterEmail = strtolower(trim((string)($in['requester_email'] ?? '')));
        $requesterName  = trim((string)($in['requester_name'] ?? ''));

        // An already-known requester, chosen from the picker (discussion #54).
        // Optional and additive: every existing caller still sends an address and
        // is unaffected. When present it wins, because "this exact person" is a
        // stronger statement than an address that has to be matched.
        //
        // ⚠️ THE CALLER MUST HAVE AUTHORISED THIS ID ALREADY. The service takes
        // it on trust, exactly as it takes tenantId on trust — see
        // api/tickets/create_ticket.php, which calls analystCanAccessUser()
        // before getting here. A scoped picker list is not a check: the id
        // arrives in a request body and can be edited.
        $requesterUserId = isset($in['user_id']) && (int)$in['user_id'] > 0 ? (int)$in['user_id'] : null;

        if ($subject === '') {
            throw new ServiceError('validation', 'missing_field', "'subject' is required.");
        }
        // Same refusal as updateTicket(): never create a ticket someone asked to be
        // confidential as a Normal one because the column is not there yet (#62).
        if (ticketSensitivityNormalise($in['sensitivity'] ?? '') === 'confidential' && !ticketSensitivityReady($conn)) {
            throw new ServiceError('validation', 'invalid_field', 'Run System → Database Verification before marking tickets confidential.');
        }
        // The address is required only when there is no chosen requester to
        // stand in for it — picking somebody from the directory already
        // identifies them, and a directory user may legitimately have no mailbox.
        if ($requesterUserId === null
            && ($requesterEmail === '' || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL))) {
            throw new ServiceError('validation', 'missing_field', "'requester_email' is required and must be a valid email address.");
        }
        if ($requesterName === '' && $requesterEmail !== '') {
            $requesterName = ucfirst(explode('@', $requesterEmail)[0]);
        }

        // No status/priority supplied → the CONFIGURED default. Never the literal
        // names 'Open'/'Normal': those are display names an admin may rename or
        // translate, and a missed match silently left the field NULL (#79).
        $statusRes = self::resolveStatus($conn, $in) ?? (function () use ($conn) {
            $row = $conn->query("SELECT id, name, is_closed FROM ticket_statuses WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            return $row ? [(int)$row['id'], $row['name'], (int)$row['is_closed']] : [null, null, 0];
        })();
        $priorityRes = self::resolvePriority($conn, $in);
        if ($priorityRes === null) {
            $row = $conn->query("SELECT id, name FROM ticket_priorities WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $priorityRes = $row ? [(int)$row['id'], $row['name']] : [null, null];
        }

        $departmentId = isset($in['department_id']) && $in['department_id'] !== '' ? (int)$in['department_id'] : null;
        $typeId       = isset($in['ticket_type_id']) && $in['ticket_type_id'] !== '' ? (int)$in['ticket_type_id'] : null;
        $originId     = isset($in['origin_id']) && $in['origin_id'] !== '' ? (int)$in['origin_id'] : null;
        $mailboxId    = isset($in['mailbox_id']) && $in['mailbox_id'] !== '' ? (int)$in['mailbox_id'] : null;
        // #1540. Only the "reported as" category is settable at creation — a
        // closure category and a resolution code describe an ENDING and would be
        // nonsense on a ticket that has not started.
        $categoryId   = isset($in['category_id']) && $in['category_id'] !== '' ? (int)$in['category_id'] : null;
        // Which team owns it from the moment it exists (#1566). Used by the
        // workflow "Create a ticket" action, so a rule can drop work straight
        // into a queue — "guest Wi-Fi requests go to Infrastructure" — without
        // naming a person who may be on holiday.
        $teamId       = isset($in['assigned_team_id']) && $in['assigned_team_id'] !== '' ? (int)$in['assigned_team_id'] : null;

        $analystId = $defaultAnalystId;
        if (isset($in['assigned_analyst_id']) && $in['assigned_analyst_id'] !== '') {
            $analystId = (int)$in['assigned_analyst_id'];
        }

        self::validateLookupId($conn, 'departments', $departmentId, 'department');
        self::validateLookupId($conn, 'ticket_types', $typeId, 'ticket type');
        self::validateLookupId($conn, 'ticket_origins', $originId, 'origin');
        self::validateLookupId($conn, 'ticket_categories', $categoryId, 'category');
        // 422 rather than a raw foreign-key 500, same as every other lookup here.
        self::validateLookupId($conn, 'teams', $teamId, 'team');
        // ⚠️ A category tied to a DIFFERENT ticket type is refused outright here,
        // rather than silently dropped. On an update the pair can become mismatched
        // by a later type change and clearing it is the only sane repair, but at
        // CREATION both values are in front of the caller in the same request —
        // accepting one and discarding the other would be answering a question
        // nobody asked.
        if ($categoryId !== null) {
            $boundType = self::categoryEffectiveTypeId($conn, $categoryId);
            if ($boundType !== null && $boundType !== $typeId) {
                throw new ServiceError('validation', 'invalid_field',
                    "Category {$categoryId} belongs to a different ticket type.");
            }
        }
        if ($analystId !== null) {
            $aStmt = $conn->prepare("SELECT id FROM analysts WHERE id = ? AND is_active = 1");
            $aStmt->execute([$analystId]);
            if (!$aStmt->fetchColumn()) {
                throw new ServiceError('validation', 'invalid_field', "Unknown or inactive analyst id: {$analystId}");
            }
        }
        // Custom fields (#179): checked in full BEFORE the ticket exists, so a
        // missing or malformed answer never leaves a half-made ticket behind.
        $customDefs = [];
        $customValues = [];
        if (array_key_exists('custom_fields', $in)) {
            if (!is_array($in['custom_fields'])) {
                throw new ServiceError('validation', 'invalid_field', "'custom_fields' must be an object of field key => value.");
            }
            $customDefs   = TicketFieldsService::fieldsFor($conn, $tenantId, $typeId);
            $customValues = TicketFieldsService::validate($conn, $customDefs, $in['custom_fields'], true);
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

            if ($requesterUserId !== null) {
                // Chosen from the picker. Confirm the row still exists — an id
                // can be stale by the time it arrives — and read the address back
                // FROM the record rather than from the request, so the ticket and
                // its initial email row describe the person that was picked.
                $pStmt = $conn->prepare("SELECT id, email, display_name FROM users WHERE id = ?");
                $pStmt->execute([$requesterUserId]);
                $picked = $pStmt->fetch(PDO::FETCH_ASSOC);
                if (!$picked) {
                    throw new ServiceError('validation', 'invalid_field', 'That requester no longer exists.');
                }
                $userId         = (int)$picked['id'];
                $requesterEmail = (string)($picked['email'] ?? '');
                if ($requesterName === '') {
                    $requesterName = (string)($picked['display_name'] ?? '');
                }
            } else {
                $uStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $uStmt->execute([$requesterEmail]);
                $userId = $uStmt->fetchColumn();
                if ($userId === false) {
                    // ⚠️ tenant_id was missing from this INSERT entirely, so a
                    // requester born from a manual ticket had NO company — while
                    // the very same person created through the Users screen, LDAP
                    // or SSO gets one resolved from their email domain. Three
                    // paths did it properly and the fourth quietly did not, which
                    // on a multi-company install means their tickets land in
                    // triage forever and they are invisible to a scoped analyst.
                    //
                    // resolveTenantForNewUser() returns null when the domain maps
                    // to nothing, which is the correct "unknown, send to triage"
                    // answer rather than a guess.
                    $newTenantId = function_exists('resolveTenantForNewUser')
                        ? resolveTenantForNewUser($conn, $requesterEmail)
                        : null;
                    $conn->prepare("INSERT INTO users (email, display_name, tenant_id, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())")
                         ->execute([$requesterEmail, $requesterName, $newTenantId]);
                    $userId = $conn->lastInsertId();
                }
            }

            // The ticket type and company are passed so the per-type and
            // per-company sequence options can actually use them.
            // ⚠️ $tenantId, not a guessed name. An isset() on a variable that
            // does not exist here would quietly pass null and put every company
            // on the same counter — working, but wrong, and invisible.
            $ticketNumber = TicketNumbering::next($conn, $typeId, $tenantId);

            $conn->prepare(
                "INSERT INTO tickets (
                    tenant_id, ticket_number, subject, status_id, priority_id, department_id,
                    ticket_type_id, category_id, origin_id, assigned_team_id,
                    assigned_analyst_id, owner_id, user_id,
                    created_datetime, updated_datetime
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([
                $tenantId, $ticketNumber, $subject, $statusRes[0], $priorityRes[0], $departmentId,
                $typeId, $categoryId, $originId, $teamId,
                $analystId, $analystId, $userId,
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

            if ($customValues) {
                TicketFieldsService::write($conn, $ctx, $ticketId, $customDefs, $customValues);
            }

            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }

        // Announced through the shared helper, which is also what the portal and
        // the mailbox ingest call. The payload is built there from the stored
        // row so all three channels announce identically — see the header of
        // includes/ticket_events.php for why that stopped being true.
        // Confidential (discussion #62): asked for outright, or by the department
        // or mailbox defaults. Raise-only, so the order does not matter.
        if (ticketSensitivityNormalise($in['sensitivity'] ?? '') === 'confidential') {
            ticketSensitivityRaise($conn, $ticketId, 'set when the ticket was raised');
        }
        ticketSensitivityApplyDefaults($conn, $ticketId, $mailboxId !== null ? (int)$mailboxId : null);

        require_once dirname(__DIR__) . '/ticket_events.php';
        ticketDispatchCreated($conn, (int)$ticketId, $ctx->actorId, $requesterEmail);

        return $ticketId;
    }

    // ======================================================================
    //  Update
    // ======================================================================

    /**
     * Apply a partial update to a ticket. $writeAudit toggles the server-side
     * audit trail (API: true; UI: false — it audits client-side). Returns void;
     * the adapter reloads for its response.
     *
     * @param array $in  only the fields to change: subject, status/priority (by id or name), the lookup ids, assigned_analyst_id,
     *     assigned_team_id, company_id, sensitivity, closed_message, work_start_at, work_end_at, work_all_day,
     *     custom_fields ([field_key => value]; '' clears; only the keys sent are touched)
     * @param bool $writeAudit  true writes the ticket_audit rows here (API); false when the caller audits itself (UI).
     *     Custom field changes are always audited here, by whichever channel made them.
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

        // A presentation choice, shared by every channel. Always audit it here,
        // including UI calls, so changing layout cannot lose its history.
        $workspaceChanged = false;
        if (array_key_exists('workspace', $in)) {
            if (!is_bool($in['workspace'])) {
                throw new ServiceError('validation', 'invalid_field', "'workspace' must be a boolean.");
            }
            if (!ticketWorkspaceReady($conn)) {
                throw new ServiceError('validation', 'invalid_field', 'Run System → Database Verification before changing the workspace flag.');
            }
            if ($in['workspace'] !== (bool)($current['is_workspace'] ?? false)) {
                $updates[] = 'is_workspace = ?';
                $args[] = (int)$in['workspace'];
                $audits[] = ['Workspace', empty($current['is_workspace']) ? 'off' : 'on', $in['workspace'] ? 'on' : 'off', true];
                $workspaceChanged = true;
            }
        }

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
            // SOP checklists (PR #141): on a 'block' install, refuse the closure
            // BEFORE anything is written. Checked here, not after the update, or
            // the throw would arrive with the ticket already closed.
            if ($newIsClosed && !$oldIsClosed && $newStatusId !== (int)$current['status_id']) {
                ChecklistsService::assertClosureAllowed(
                    $conn, $ticketId,
                    isset($current['tenant_id']) && $current['tenant_id'] !== null ? (int)$current['tenant_id'] : null
                );
            }
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

        // Ticket classification (#1540): category, category at close, resolution code.
        //
        // Handled apart from the generic lookup loop above because the loop reads
        // its "old" name out of a joined column on $current, and a category's name
        // is not enough on its own — "Printer" is ambiguous the moment two roots
        // both have one. The audit trail records the FULL PATH instead, which
        // ticketCategoryPathLabel() derives from parent_id.
        //
        // ⚠️ Deliberately NO is_active check. A retired category must stay
        // writable, because reopening a closed ticket and saving it again would
        // otherwise fail on a value the ticket already held.
        foreach ([
            'category_id'         => ['Category',         'category'],
            'closure_category_id' => ['Category at Close', 'category'],
            'resolution_code_id'  => ['Resolution Code',   'resolution code'],
        ] as $field => [$auditField, $label]) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $table = ($field === 'resolution_code_id') ? 'ticket_resolution_codes' : 'ticket_categories';
            $newId = ($in[$field] === '' || $in[$field] === null) ? null : (int)$in[$field];
            if ($newId !== null) {
                self::validateLookupId($conn, $table, $newId, $label);
            }
            $oldId = $current[$field] ?? null;
            $oldId = ($oldId === null || $oldId === '') ? null : (int)$oldId;
            if ($newId === $oldId) {
                continue;
            }
            $updates[] = "$field = ?";
            $args[]    = $newId;
            $audits[]  = ($field === 'resolution_code_id')
                ? [$auditField, ticketResolutionCodeName($conn, $oldId), ticketResolutionCodeName($conn, $newId)]
                : [$auditField, ticketCategoryPathLabel($conn, $oldId), ticketCategoryPathLabel($conn, $newId)];
        }

        // ⚠️ CHANGING THE TYPE CAN ORPHAN THE CATEGORY.
        // A category may be tied to one ticket type, and its children inherit that
        // tie. Move the ticket to a different type and the category it is carrying
        // may no longer belong there at all — leaving "Incident / User onboarding"
        // sitting on the ticket, wrong, and invisible until a report is read.
        // So it is CLEARED rather than kept, and the audit trail says so. The
        // caller is told via the returned audit entry, which the UI surfaces.
        if (array_key_exists('ticket_type_id', $in)) {
            $newTypeId = ($in['ticket_type_id'] === '' || $in['ticket_type_id'] === null) ? null : (int)$in['ticket_type_id'];
            foreach (['category_id', 'closure_category_id'] as $catField) {
                // Only if the caller has not set this field itself in the same
                // request — an explicit value always wins over this cleanup.
                if (array_key_exists($catField, $in)) {
                    continue;
                }
                $catId = $current[$catField] ?? null;
                if ($catId === null || $catId === '') {
                    continue;
                }
                $boundType = self::categoryEffectiveTypeId($conn, (int)$catId);
                // NULL = the category is offered whatever the type is, so it survives.
                if ($boundType === null || $boundType === $newTypeId) {
                    continue;
                }
                $updates[] = "$catField = ?";
                $args[]    = null;
                $audits[]  = [
                    $catField === 'category_id' ? 'Category' : 'Category at Close',
                    ticketCategoryPathLabel($conn, (int)$catId),
                    null,
                    true,   // forced: audited even when the caller audits client-side
                ];
            }
        }

        // Which TEAM owns the ticket (#1566).
        //
        // 🔴 HANDLED ENTIRELY ON ITS OWN, and deliberately NOT folded into the
        // analyst block below. Setting an analyst must never set or clear the
        // team, and setting a team must never touch the analyst — those two
        // answer different questions ("who is doing it" / "which queue owns
        // it"), and the moment one writes the other as a side effect they start
        // drifting. That is precisely how `owner_id` ended up disagreeing with
        // `assigned_analyst_id` on 93 of 110 rows: it is written as a side
        // effect a few lines further down, and nothing keeps it honest.
        //
        // The consequence that matters on screen: clearing the analyst LEAVES
        // the team, so a ticket falls back into its queue rather than into the
        // void when somebody goes on holiday.
        $teamChangedTo = null;   // set when a NEW team takes ownership — drives the notification
        if (array_key_exists('assigned_team_id', $in)) {
            $newTeamId = ($in['assigned_team_id'] === '' || $in['assigned_team_id'] === null)
                ? null : (int)$in['assigned_team_id'];
            $newTeamName = null;
            if ($newTeamId !== null) {
                $tStmt = $conn->prepare("SELECT name FROM teams WHERE id = ?");
                $tStmt->execute([$newTeamId]);
                $newTeamName = $tStmt->fetchColumn();
                if ($newTeamName === false) {
                    throw new ServiceError('validation', 'invalid_field', "Unknown team id: {$newTeamId}");
                }
            }
            $oldTeamId = $current['assigned_team_id'] !== null ? (int)$current['assigned_team_id'] : null;
            if ($newTeamId !== $oldTeamId) {
                $updates[] = 'assigned_team_id = ?';
                $args[]    = $newTeamId;
                $audits[]  = ['Team', self::teamName($conn, $oldTeamId), $newTeamName];
                // Only a team GAINING a ticket is news. Losing one is not — the
                // people who need to know are the ones now expected to act.
                if ($newTeamId !== null) {
                    $teamChangedTo = $newTeamId;
                }
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
            // Clearing the schedule clears the whole schedule. Leaving an end time
            // and an all-day flag behind on a ticket with no start would describe a
            // block of work that no longer exists, and the calendar reads the end
            // relative to the start — so a stale one is not merely untidy.
            if ($newWork === null) {
                $updates[] = 'work_end_datetime = ?';
                $args[]    = null;
                $updates[] = 'work_all_day = ?';
                $args[]    = 0;
            }
        }

        // End of the scheduled work. The UI computes this from a duration (see
        // freeitsm.sql), but the service takes a datetime like every other date
        // field, so the REST API and any future caller are not made to think in
        // durations. Only honoured alongside a start that exists — an end on an
        // unscheduled ticket is meaningless, and silently storing one would put a
        // ticket on the calendar with nothing to anchor it to.
        if (array_key_exists('work_end_at', $in) && !in_array('work_end_datetime = ?', $updates, true)) {
            $newEnd = ($in['work_end_at'] === null || $in['work_end_at'] === '')
                ? null : self::parseDate((string)$in['work_end_at'], 'work_end_at');
            $effectiveStart = array_key_exists('work_start_at', $in)
                ? $newWork : ($current['work_start_datetime'] ?? null);
            if ($newEnd !== null && $effectiveStart === null) {
                throw new ServiceError('validation', 'invalid_field',
                    "'work_end_at' needs a 'work_start_at' — a ticket cannot finish work it is not scheduled for.");
            }
            if ($newEnd !== null && $newEnd < $effectiveStart) {
                throw new ServiceError('validation', 'invalid_field',
                    "'work_end_at' cannot be before 'work_start_at'.");
            }
            if ($newEnd !== ($current['work_end_datetime'] ?? null)) {
                $updates[] = 'work_end_datetime = ?';
                $args[]    = $newEnd;
            }
        }

        if (array_key_exists('work_all_day', $in) && !in_array('work_all_day = ?', $updates, true)) {
            $newAllDay = (int)(bool)$in['work_all_day'];
            if ($newAllDay !== (int)($current['work_all_day'] ?? 0)) {
                $updates[] = 'work_all_day = ?';
                $args[]    = $newAllDay;
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

        // Sensitivity (discussion #62). The one way a ticket is made Normal again:
        // a person choosing it. Nothing automatic ever lowers it.
        $departmentChanged = false;
        foreach ($updates as $u) {
            if (str_starts_with($u, 'department_id = ')) { $departmentChanged = true; }
        }
        // ⚠️ Refuse rather than ignore. Before Database Verification there is
        // nowhere to store it, and quietly saving nothing would leave someone
        // believing a ticket is confidential when it is not.
        if (array_key_exists('sensitivity', $in) && !ticketSensitivityReady($conn)
            && ticketSensitivityNormalise($in['sensitivity']) === 'confidential') {
            throw new ServiceError('validation', 'invalid_field', 'Run System → Database Verification before marking tickets confidential.');
        }
        if (array_key_exists('sensitivity', $in) && ticketSensitivityReady($conn)) {
            $newSens = strtolower(trim((string)$in['sensitivity']));
            if (!in_array($newSens, TICKET_SENSITIVITIES, true)) {
                throw new ServiceError('validation', 'invalid_field', "'sensitivity' must be 'normal' or 'confidential'.");
            }
            $oldSens = ticketSensitivityNormalise($current['sensitivity'] ?? 'normal');
            if ($newSens !== $oldSens) {
                $updates[] = 'sensitivity = ?';
                $args[]    = $newSens;
                $audits[]  = ['Sensitivity', ucfirst($oldSens), ucfirst($newSens)];
            }
        }

        // Custom fields (#179). Resolved against the ticket as it will be after
        // this save - a type changed in the same request decides which fields
        // apply - and validated now, before anything is written.
        $customDefs = [];
        $customValues = null;
        if (array_key_exists('custom_fields', $in)) {
            if (!is_array($in['custom_fields'])) {
                throw new ServiceError('validation', 'invalid_field', "'custom_fields' must be an object of field key => value.");
            }
            $customDefs   = TicketFieldsService::fieldsForTicket($conn, self::rowAfterUpdates($current, $updates, $args));
            $customValues = TicketFieldsService::validate($conn, $customDefs, $in['custom_fields'], false);
            if (!$customValues) $customValues = null;
        }

        if (!$updates && $customValues === null) {
            return; // idempotent
        }

        // Mandatory fields at closure. Checked HERE - after every field above has
        // been resolved, before anything is written - and against the ticket as it
        // will be once this save lands, so a resolution code picked in the same
        // request that closes the ticket counts, and a category the type change
        // above has just cleared does not.
        // The company comes from the same row, so a ticket moved to another
        // company in this save is judged by that company's rules.
        $closing      = $newStatusId !== null && $newIsClosed && !$oldIsClosed;
        $afterRow     = self::rowAfterUpdates($current, $updates, $args);
        $closeTenant  = ($afterRow['tenant_id'] ?? null) !== null ? (int)$afterRow['tenant_id'] : null;
        $emptyOnClose = [];
        if ($closing) {
            $emptyOnClose = MandatoryFieldsService::missing($conn, $closeTenant, $afterRow, $customValues);
            MandatoryFieldsService::assertClosureAllowed($conn, $closeTenant, $emptyOnClose);
        }

        // TRAP: test what actually changed, not just which keys the client sent.
        // A mixed save still runs its ordinary notifications/workflows, but a
        // layout-only save must not reconcile calendars or trigger automation.
        $workspaceOnly = $workspaceChanged && count($updates) === 1 && $customValues === null;
        $ownWorkspaceTransaction = $workspaceChanged && !$conn->inTransaction();
        if ($ownWorkspaceTransaction) $conn->beginTransaction();
        try {
            $updates[] = 'updated_datetime = UTC_TIMESTAMP()';
            $args[]    = $ticketId;
            $conn->prepare('UPDATE tickets SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);
            if (in_array('tenant_id = ?', $updates, true)) {
                ticketsFollowCompanyMove($conn, [$ticketId], (int)$in['company_id']);   // its tasks and search entries
            }

            if ($customValues !== null) {
                TicketFieldsService::write($conn, $ctx, $ticketId, $customDefs, $customValues);
            }

            // ⚠️ AN ENTRY FLAGGED `forced` IS WRITTEN EVEN WHEN $writeAudit IS FALSE.
            //
            // The UI normally audits client-side, so this service stays quiet for it.
            // That works because the UI knows what it asked for — but the category
            // auto-clear (#1540) is a change the SERVER makes on its own, when a new
            // ticket type orphans the category the ticket was carrying. The client
            // never asked for it and cannot log what it does not know happened, so
            // the field would empty itself with nothing in the trail to say why.
            foreach ($audits as $entry) {
                [$field, $old, $new] = $entry;
                if ($writeAudit || !empty($entry[3])) {
                    self::auditWrite($conn, $ticketId, $actorId, $field, $old, $new);
                }
            }

            // Moved into a confidential department: raise it. Writes its own audit
            // entry whatever $writeAudit says, because the UI asked only for the move
            // and cannot log a change it does not know the server made.
            if ($departmentChanged) {
                ticketSensitivityApplyDefaults($conn, $ticketId);
            }
            if ($ownWorkspaceTransaction) $conn->commit();
        } catch (Throwable $e) {
            if ($ownWorkspaceTransaction && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        if ($workspaceOnly) return;

        // Template emails + CSAT — non-blocking, same as assign_ticket.php.
        try {
            require_once __DIR__ . '/../template_email.php';
            if ($analystSent && $newAnalystId !== null) {
                // TWO AUDIENCES, TWO EVENTS (#148).
                //
                // 'ticket_assigned' tells the REQUESTER their ticket is moving -
                // "assigned to Sam, we will update you soon". That is what every
                // install's template says, because that is who it has always
                // gone to, and it is not changing.
                //
                // 'analyst_assigned' tells the ANALYST they have just been given
                // work. Both fire; each is silent unless somebody configured a
                // template for it, so an install that wants neither gets neither.
                sendTemplateEmail($conn, $ticketId, 'ticket_assigned');

                // ⚠️ Not when you assign a ticket to YOURSELF. An email telling
                // somebody what they did three seconds ago is the kind of noise
                // that gets a whole notification switched off, and picking a
                // ticket up off the queue is the commonest assignment there is.
                if ($newAnalystId !== $actorId) {
                    sendTemplateEmail($conn, $ticketId, 'analyst_assigned');
                }
            }
            if ($newIsClosed && !$oldIsClosed) {
                // A one-off note for THIS closure, typed by the analyst as they
                // close (#142, asked for by mbsouth). Not a setting and not
                // stored: it exists only for this email.
                //
                // 🔴 THE KEY IS ALWAYS PASSED, EVEN EMPTY. resolveMergeCodes()
                // only substitutes codes it is handed and there is no
                // strip-unknown pass, so a template containing
                // [ticket_closed_message] would otherwise mail that literal
                // string to the customer on every close that did not type one —
                // bulk closes, the REST API and workflow closes included, none
                // of which can prompt for anything.
                sendTemplateEmail($conn, $ticketId, 'ticket_closed', [
                    'ticket_closed_message' => trim((string)($in['closed_message'] ?? '')),
                ]);
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

        // SOP checklists (PR #141): if this transition closed the ticket while
        // mandatory steps were still outstanding, record the override. One call
        // here covers every path that can close a ticket — the inbox, bulk
        // actions, the v1 REST API and assign/schedule — because they all come
        // through updateTicket(). The rule itself lives in ChecklistsService;
        // this is only the hook, so tickets does not learn the checklist rules.
        if ($newStatusId !== null && $newIsClosed && !$oldIsClosed) {
            ChecklistsService::recordClosureOverride($conn, $ctx, $ticketId, $closeTenant);
            // Same hook, same reasoning: the note and the email for a close that
            // went ahead with mandatory fields empty.
            MandatoryFieldsService::afterClosure($conn, $ctx, $ticketId, $closeTenant, $emptyOnClose);
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
            // #1566. Its own event, not a variant of ticket.assigned: the audience
            // is everyone in the receiving team rather than one named person, and
            // an analyst may legitimately want one and not the other.
            if ($teamChangedTo !== null) {
                WorkflowEngine::dispatch('ticket.team_assigned', [
                    'ticket' => $payload, 'team_id' => $teamChangedTo, 'analyst_id' => null,
                ]);
            }
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in TicketsService update: ' . $wfEx->getMessage());
        }

        // Put the ticket in (or take it out of) its owner's calendar (GH #75).
        //
        // ⚠️ AFTER THE COMMIT, deliberately. This makes a network call to
        // Microsoft, and holding row locks open across one is how a slow third
        // party becomes a database problem.
        //
        // 🔑 Called unconditionally rather than only when the schedule changed,
        // because it RECONCILES: a reassignment, or a status change that closes
        // the ticket, alters what should be in whose calendar without
        // work_start_datetime moving at all. It is a cheap no-op when there is
        // nothing to do — it does not reach the network unless something needs
        // writing.
        calendarSyncReconcileTicket($conn, $ticketId);
    }

    // ======================================================================
    //  Soft delete / restore
    // ======================================================================

    /**
     * Move a ticket to the trash. $writeAudit -> the 'Trash' audit row (UI: true; API: false).
     *
     * @param bool $writeAudit  true writes the 'Trash' audit row (UI); false for the API
     */
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
        // Out of the trash-bound ticket's owner's calendar too — a job nobody is
        // going to do should not still be sitting in somebody's Tuesday.
        calendarSyncReconcileTicket($conn, $ticketId);
    }

    /**
     * Restore a ticket from the trash. $writeAudit -> the 'Trash' audit row.
     *
     * @param bool $writeAudit  true writes the 'Trash' audit row (UI); false for the API
     */
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
        // And back in, if it is still scheduled and its owner still wants it —
        // the same reconcile decides, so restore needs no logic of its own.
        calendarSyncReconcileTicket($conn, $ticketId);
    }

    /**
     * The canonical ticket workflow payload, built from a loaded (joined) row.
     *
     * @param array $r  the ticket row, as loaded by loadTicket() or rowAfterUpdates()
     */
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

    /**
     * Add a note. Returns the note id; touches the ticket's updated_datetime.
     *
     * @param array $in  text (required), is_internal (default true; false shares it with the requester and may email them)
     */
    public static function createNote(PDO $conn, ActorContext $ctx, int $ticketId, array $in): int
    {
        $ticketRow = self::loadTicket($conn, $ctx, $ticketId);   // 404
        $text = trim((string)($in['text'] ?? ''));
        if ($text === '') {
            throw new ServiceError('validation', 'missing_field', "'text' is required.");
        }
        $isInternal = array_key_exists('is_internal', $in) ? (bool)$in['is_internal'] : true;
        // 🔴 created_datetime IS NAMED HERE ON PURPOSE (GH #126). Leaving it out
        // let the column's `DEFAULT CURRENT_TIMESTAMP` fire, and MySQL evaluates
        // that in the CONNECTION'S SESSION ZONE — which is `SYSTEM`, the server's
        // own clock. So the one datetime in the product that a person reads off
        // a ticket was being written as a LOCAL WALL CLOCK while the reader
        // converts it as a UTC instant, and the note landed the server's offset
        // into the future: +2h in Vienna, +1h in the UK in summer, and 0 on a
        // server running UTC, which is why it survived.
        //
        // ⭐ The tell was two lines below this one: `updated_datetime =
        // UTC_TIMESTAMP()` in the same method, in the same transaction. The
        // right answer was already here — the INSERT just did not list the
        // column, so nothing overrode the default.
        $conn->prepare("INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal, created_datetime) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$ticketId, $ctx->actorId, $text, $isInternal ? 1 : 0]);
        $noteId = (int)$conn->lastInsertId();
        $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$ticketId]);

        // ticket.note_added (discussion #55). A colleague adding a note to your
        // ticket is one of the things people most want to be told about, and
        // until now nothing announced it at all — so workflows could not act on
        // it either. Non-fatal: a note is saved whether or not anyone hears.
        try {
            WorkflowEngine::dispatch('ticket.note_added', [
                'ticket'      => self::eventTicket($ticketRow),
                'note_id'     => $noteId,
                'is_internal' => $isInternal,
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in TicketsService createNote: ' . $wfEx->getMessage());
        }

        // Email the requester, IF an administrator has configured a template for
        // it (discussion #103). A shared note appears in the self-service portal,
        // which reaches nobody who never signs into the portal — and for a
        // requester who only ever emails, "Share with the requester" reached
        // nobody at all.
        //
        // 🔴 SHARED NOTES ONLY. An internal note is a private remark between
        // colleagues; emailing one to the customer is the single worst thing
        // this file could do. The guard is the first condition, and the trigger
        // is named `note_shared` rather than `note_added` so that an
        // administrator reading the dropdown cannot mistake what fires it.
        //
        // Off unless configured: sendTemplateEmail() is a no-op when no ACTIVE
        // template carries this trigger, so an existing installation sends
        // nothing new until somebody deliberately writes one.
        //
        // [note_text] is passed the way CSAT passes [csat_link] — so the template
        // decides whether the mail carries the note itself or simply says there
        // is an update and points at [ticket_url]. Attachments are deliberately
        // NOT emailed: the portal link already reaches them, and a file is the
        // part worth not sending to an address that may be a shared mailbox.
        if (!$isInternal) {
            try {
                require_once __DIR__ . '/../template_email.php';
                require_once __DIR__ . '/../note_images.php';
                // A pasted picture's marker means nothing in an inbox (GH #224);
                // the picture itself is in the portal with the rest of the note.
                sendTemplateEmail($conn, $ticketId, 'note_shared', [
                    'note_text' => noteImagesPlainText($text),
                ]);
            } catch (Exception $mailEx) {
                // Non-fatal, like the dispatch above: the note is saved whether
                // or not the mail goes. It is already visible in the portal.
                error_log('note_shared template email failed for ticket ' . $ticketId
                        . ': ' . $mailEx->getMessage());
            }
        }

        return $noteId;
    }

    // ======================================================================
    //  Time entries
    // ======================================================================

    /**
     * Log time on a ticket. Returns the entry id.
     *
     * @param array $in  minutes (required, above 0), notes, entry_at (ISO 8601; default now in UTC)
     */
    /** Refuse a time-entry write while Time tracking is switched off for the install (GH #217). */
    private static function requireTimeTrackingOn(PDO $conn): void
    {
        if (function_exists('moduleIsEnabled') && !moduleIsEnabled($conn, 'time-tracking')) {
            throw new ServiceError('forbidden', 'module_disabled', moduleDisabledMessage('time-tracking'));
        }
    }

    public static function createTimeEntry(PDO $conn, ActorContext $ctx, int $ticketId, array $in): int
    {
        // Time tracking switched off for the install (GH #217): refused here, in the
        // service, so no caller can skip it - deleting an entry was never gated.
        self::requireTimeTrackingOn($conn);
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

    /**
     * Edit a time entry (UI-only; only the owning analyst may edit).
     *
     * @param int $entryId  the time entry (ticket_time_entries.id) to edit; must be the actor's own
     * @param array $in  minutes (required, above 0), notes, entry_at (ISO 8601; default now in UTC)
     */
    public static function updateTimeEntry(PDO $conn, ActorContext $ctx, int $entryId, int $ticketId, array $in): void
    {
        self::requireTimeTrackingOn($conn);   // GH #217
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
     *
     * @param int $entryId  the time entry (ticket_time_entries.id) to remove
     * @param bool $restrictToOwner  true allows only the analyst who logged it (UI); false allows any entry on a reachable ticket (API)
     */
    public static function deleteTimeEntry(PDO $conn, ActorContext $ctx, int $entryId, ?int $ticketId, bool $restrictToOwner): void
    {
        self::requireTimeTrackingOn($conn);   // GH #217
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
     * The ticket row as it will be once an UPDATE built by updateTicket() lands.
     *
     * Read back out of the SET list rather than re-derived from the input, so it
     * includes what the service decides on its own - the category a type change
     * clears, the owner written alongside the analyst - and cannot drift from what
     * is actually written. Entries with no placeholder (closed_datetime =
     * UTC_TIMESTAMP()) consume no argument.
     *
     * @param array $current  the ticket row before the update, as loaded by loadTicket()
     * @param string[] $updates  "column = ?" / "column = <expr>" fragments
     * @param array    $args     the placeholder values, in order
     */
    private static function rowAfterUpdates(array $current, array $updates, array $args): array
    {
        $row = $current;
        $i = 0;
        foreach ($updates as $frag) {
            if (preg_match('/^\s*([a-z_]+)\s*=\s*\?\s*$/', $frag, $m)) {
                $row[$m[1]] = $args[$i] ?? null;
                $i++;
            } else {
                // Any other shape: skip its value, but keep the arguments aligned.
                $i += substr_count($frag, '?');
            }
        }
        return $row;
    }

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

    /**
     * Records one field change in the ticket's audit trail.
     *
     * @param string $field  the audit field label, e.g. 'Status', 'Team', 'Trash', 'Ticket Created'
     * @param ?string $old  the value before, or null
     * @param ?string $new  the value after, or null
     */
    private static function auditWrite(PDO $conn, int $ticketId, int $analystId, string $field, ?string $old, ?string $new): void
    {
        $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        // 🔴 NULL, not 0, when there is no analyst. A portal user closing their
        // own ticket acts through ActorContext::fromPortalUser(), whose actorId
        // is 0 because a requester is not a member of staff. Storing 0 would
        // point the trail at an analyst row that does not exist; storing NULL
        // says plainly that nobody on the desk did this, and the field name
        // beside it says who did.
        )->execute([$ticketId, $analystId > 0 ? $analystId : null, $field, $old, $new]);
    }

    // generateTicketNumber() moved to includes/ticket_numbering.php (GH #71).
    // It existed here AND in api/self-service/create_ticket.php AND in
    // api/tickets/check_mailbox_email.php — three copies, so a configurable
    // format reaching one of them would have given analyst-raised, portal and
    // emailed tickets three different schemes.

    /**
     * Resolve a status by name or id. Returns [id, name, is_closed] or null.
     *
     * @param array $in  the request: status_id wins over status (a name); neither gives null, unknown throws
     */
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

    /**
     * Resolve a priority by name or id. Empty string/null id = clear.
     *
     * @param array $in  the request: priority_id (null or '' clears) wins over priority (a name); neither gives null
     */
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
    /**
     * The ticket type a category is tied to, walking up to its ROOT (#1540).
     *
     * The type link is only meaningful on a root; a sub-category inherits it. So
     * "is this category allowed on this ticket type?" can never be answered from
     * the row itself — it has to climb. NULL means "offered whatever the type is".
     */
    /**
     * A team's name for the audit trail, or null. Never throws — this is a label.
     *
     * @param ?int $teamId  the team (teams.id); null or 0 gives null
     */
    private static function teamName(PDO $conn, ?int $teamId): ?string
    {
        if (!$teamId) return null;
        try {
            $s = $conn->prepare("SELECT name FROM teams WHERE id = ?");
            $s->execute([$teamId]);
            $v = $s->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The ticket type a category is bound to, taken from its top-level category; null when
     * unbound or it cannot be worked out.
     *
     * @param int $categoryId  the ticket category (ticket_categories.id) to start from
     */
    private static function categoryEffectiveTypeId(PDO $conn, int $categoryId): ?int
    {
        $cur   = $categoryId;
        $steps = 0;
        try {
            $st = $conn->prepare("SELECT parent_id, ticket_type_id FROM ticket_categories WHERE id = ?");
            while ($cur !== null && $steps < TICKET_CATEGORY_MAX_DEPTH + 2) {
                $st->execute([$cur]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return null;
                }
                if ($row['parent_id'] === null) {
                    return $row['ticket_type_id'] === null ? null : (int)$row['ticket_type_id'];
                }
                $cur = (int)$row['parent_id'];
                $steps++;
            }
        } catch (Throwable) {
            return null;   // cannot resolve → treat as unbound, never clear blindly
        }
        return null;
    }

    /**
     * The name of a row in a lookup table, or null for no id; throws for an unknown id.
     *
     * @param string $table  the lookup table, e.g. 'departments', 'ticket_types', 'teams' - a developer literal
     * @param mixed $id  the id as sent; null or '' gives null
     * @param string $label  what the lookup is called in the error message, e.g. 'ticket type'
     */
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

    /**
     * Parse a date to UTC 'Y-m-d H:i:s' (mirrors apiParseDate; 400 on garbage).
     *
     * @param string $value  a date and time in any form DateTimeImmutable reads; with no zone it is read as UTC
     * @param string $field  the field name, used in the error message
     */
    private static function parseDate(string $value, string $field): string
    {
        $v = trim($value);
        try {
            $dt = new DateTimeImmutable($v, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception) {
            throw new ServiceError('bad_request', 'invalid_parameter', "'{$field}' is not a valid date/time. Use ISO 8601, e.g. 2026-07-02T09:00:00Z.");
        }
    }
}
