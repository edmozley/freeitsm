<?php
/**
 * FreeITSM REST API v1 - what hangs off a ticket (GitHub #328).
 *
 *   apiTicketRelated($conn, $ticketId, $apiKey)   -> ['counts' => [...], 'links' => [...]]
 *   apiTicketAttachments($conn, $ticketId)        -> the list behind GET /tickets/{id}/attachments
 *
 * `related` answers "which of the ticket's sub-endpoints are worth calling, and what is
 * it linked to?" in the one call, instead of five calls that are often empty. It is on
 * GET /tickets/{id}, on GET /tickets with ?include=related (opt-in: a page of 100 would
 * otherwise run 100 sets of these queries), and on the ticket's Full record webhook.
 *
 * WHO SEES WHAT. With an API key, a count is there only if the key has `read` on that
 * sub-endpoint's permission, and a link only if the key may read that kind of record
 * and the record is in one of the key's companies (a linked ticket also through the
 * key's analyst's departments) - so `related` never says more than the key could find
 * out by calling the endpoints itself. A webhook (no key, $apiKey null) gets everything:
 * whoever set up the workflow chose to send the record.
 *
 * Linked tickets carry their number, never their subject: a linked ticket may be
 * confidential, and the webhook path has no per-ticket redaction for the OTHER ticket.
 *
 * @wiki REST-API-Ticket-Related-Developer-Guide
 */

/**
 * The counts and links for one ticket.
 *
 * @param int $ticketId  a ticket the caller has already been allowed to read
 * @param ?array $apiKey  the authenticated key (permissions, company_scope, analyst_id), or null for a webhook
 * @return array{counts: array<string,int>, links: list<array>}
 */
function apiTicketRelated(PDO $conn, int $ticketId, ?array $apiKey): array
{
    $can = function (string $perm) use ($apiKey): bool {
        if ($apiKey === null) return true;
        return in_array('read', $apiKey['permissions'][$perm] ?? [], true);
    };
    $scalar = function (string $sql) use ($conn, $ticketId): int {
        try {
            $st = $conn->prepare($sql);
            $st->execute(array_fill(0, substr_count($sql, '?'), $ticketId));
            return (int)$st->fetchColumn();
        } catch (Throwable) {
            return 0;   // a table not yet created by Database Verify counts as none
        }
    };

    // ── counts: one per ticket sub-endpoint, under that endpoint's own permission ──
    $counts = [];
    if ($can('ticket_notes'))  $counts['notes']    = $scalar("SELECT COUNT(*) FROM ticket_notes WHERE ticket_id = ?");
    if ($can('ticket_thread')) $counts['messages'] = $scalar("SELECT COUNT(*) FROM emails WHERE ticket_id = ?");
    if ($can('ticket_attachments')) $counts['attachments'] = count(apiTicketAttachments($conn, $ticketId));
    if ($can('ticket_forms')) {
        try {
            require_once dirname(__DIR__, 3) . '/includes/services/task_forms.php';
            $counts['forms'] = TicketForms::columnsReady($conn) ? count(TicketForms::forTicket($conn, $ticketId, 0, false)) : 0;
        } catch (Throwable) { $counts['forms'] = 0; }
    }
    if ($can('ticket_checklists')) $counts['checklists'] = $scalar("SELECT COUNT(*) FROM ticket_checklists WHERE ticket_id = ?");
    if ($can('ticket_watchers')) {
        try {
            require_once dirname(__DIR__, 3) . '/includes/services/ticket_watchers.php';
            $counts['watchers'] = count(TicketWatchers::list($conn, $ticketId));
        } catch (Throwable) { $counts['watchers'] = 0; }
    }
    if ($can('ticket_time_entries')) $counts['time_entries'] = $scalar("SELECT COUNT(*) FROM ticket_time_entries WHERE ticket_id = ? AND is_active = 1");

    // ── links: what the ticket is linked to, each under its record's permission ──
    $links = [];
    $inScope = function (string $table, int $id) use ($conn, $apiKey): bool {
        return $apiKey === null || apiKeyCanAccessTenantRow($conn, $apiKey, $table, $id);
    };
    // [type, permission, table, SQL giving id + reference + name for this ticket]
    $kinds = [
        ['task',        'tasks',        'tasks',        "SELECT id, NULL AS reference, title AS name FROM tasks WHERE ticket_id = ? ORDER BY id"],
        ['problem',     'problems',     'problems',     "SELECT p.id, p.problem_number AS reference, p.title AS name FROM problem_tickets pt JOIN problems p ON p.id = pt.problem_id WHERE pt.ticket_id = ? ORDER BY p.id"],
        ['change',      'changes',      'changes',      "SELECT c.id, NULL AS reference, c.title AS name FROM change_tickets ct JOIN changes c ON c.id = ct.change_id WHERE ct.ticket_id = ? ORDER BY c.id"],
        ['project',     'projects',     'projects',     "SELECT p.id, NULL AS reference, p.name FROM project_tickets pt JOIN projects p ON p.id = pt.project_id WHERE pt.ticket_id = ? ORDER BY p.id"],
        ['asset',       'assets',       'assets',       "SELECT a.id, NULL AS reference, a.hostname AS name FROM ticket_assets ta JOIN assets a ON a.id = ta.asset_id WHERE ta.ticket_id = ? ORDER BY a.id"],
        ['cmdb_object', 'cmdb_objects', 'cmdb_objects', "SELECT o.id, NULL AS reference, o.name FROM ticket_cmdb_objects tc JOIN cmdb_objects o ON o.id = tc.cmdb_object_id WHERE tc.ticket_id = ? ORDER BY o.id"],
    ];
    foreach ($kinds as [$type, $perm, $table, $sql]) {
        if (!$can($perm)) continue;
        try {
            $st = $conn->prepare($sql);
            $st->execute([$ticketId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!$inScope($table, (int)$r['id'])) continue;
                $link = ['type' => $type, 'id' => (int)$r['id']];
                if ($r['reference'] !== null && $r['reference'] !== '') $link['reference'] = (string)$r['reference'];
                $link['name'] = $r['name'];
                $links[] = $link;
            }
        } catch (Throwable) { /* a module's tables not created yet - nothing linked */ }
    }

    // Linked tickets, from THIS ticket's point of view (includes/ticket_links.php), plus
    // the tickets merged into this one. Number only - see the header.
    if ($can('tickets')) {
        require_once dirname(__DIR__, 3) . '/includes/ticket_links.php';
        $okTicket = fn(int $id) => $apiKey === null || apiKeyCanAccessTicket($conn, $apiKey, $id);
        try {
            $g = ticketLinksFor($conn, $ticketId);
            $one = fn(array $t, string $rel) => ['type' => 'ticket', 'id' => $t['ticket_id'], 'reference' => $t['ticket_number'], 'relation' => $rel];
            if ($g['parent'] && $okTicket($g['parent']['ticket_id']))             $links[] = $one($g['parent'], 'child_of');
            foreach ($g['children'] as $t)   if ($okTicket($t['ticket_id']))       $links[] = $one($t, 'parent_of');
            if ($g['duplicate_of'] && $okTicket($g['duplicate_of']['ticket_id'])) $links[] = $one($g['duplicate_of'], 'duplicate_of');
            foreach ($g['duplicates'] as $t) if ($okTicket($t['ticket_id']))       $links[] = $one($t, 'duplicated_by');
            foreach ($g['related'] as $t)    if ($okTicket($t['ticket_id']))       $links[] = $one($t, 'related');
        } catch (Throwable) { /* ticket_links not created yet */ }
        try {
            // A merged ticket keeps its row (in the trash) - its id still answers GET /tickets/{id}?deleted.
            $st = $conn->prepare("SELECT source_ticket_id, source_ticket_number FROM ticket_merges WHERE target_ticket_id = ? ORDER BY id");
            $st->execute([$ticketId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                if (!$okTicket((int)$m['source_ticket_id'])) continue;
                $links[] = ['type' => 'ticket', 'id' => (int)$m['source_ticket_id'], 'reference' => $m['source_ticket_number'], 'relation' => 'merged_into_this'];
            }
        } catch (Throwable) { /* ticket_merges not created yet */ }

        // An issue in an external tracker (Jira, GitHub, ...) - its key and its address there.
        try {
            $st = $conn->prepare(
                "SELECT il.external_key, il.external_id, il.external_url, il.status_name, ic.provider
                   FROM integration_links il JOIN integration_connections ic ON ic.id = il.connection_id
                  WHERE il.entity_type = 'ticket' AND il.entity_id = ? ORDER BY il.id");
            $st->execute([$ticketId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $links[] = ['type' => 'external_issue', 'provider' => $x['provider'], 'reference' => $x['external_key'] ?: $x['external_id'],
                            'url' => $x['external_url'], 'status' => $x['status_name']];
            }
        } catch (Throwable) { /* integrations not set up */ }
    }

    // (object): an empty counts must still be {} in JSON, not [] - it is an object, keyed by name.
    return ['counts' => $counts ?: new stdClass(), 'links' => $links];
}

/**
 * Everything attached to a ticket: files on its emails, and documents on its notes.
 *
 * Inline pictures in an email (a signature's logo, a pasted screenshot shown in the
 * body) are left out - they are part of the message, not attachments, and would bury
 * the real ones. A document that is a link to a file in Files says so (`kind: files`)
 * without the file's name: the name is shown only to someone who can open its folder,
 * and the API does not check Files permissions.
 *
 * @param int $ticketId  a ticket the caller has already been allowed to read
 * @return list<array{source:string, source_id:int, id:int, kind:string, name:?string, content_type:?string, size_bytes:?int, url?:?string, created_at:?string}>
 */
function apiTicketAttachments(PDO $conn, int $ticketId): array
{
    $out = [];
    try {
        $st = $conn->prepare(
            "SELECT ea.id, ea.email_id, ea.filename, ea.content_type, ea.file_size, ea.created_datetime
               FROM email_attachments ea JOIN emails e ON e.id = ea.email_id
              WHERE e.ticket_id = ? AND ea.is_inline = 0
              ORDER BY ea.id");
        $st->execute([$ticketId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $out[] = ['source' => 'message', 'source_id' => (int)$a['email_id'], 'id' => (int)$a['id'], 'kind' => 'file',
                      'name' => $a['filename'], 'content_type' => $a['content_type'], 'size_bytes' => (int)$a['file_size'],
                      'created_at' => apiIsoDate($a['created_datetime'])];
        }
    } catch (Throwable) { /* no mail tables */ }
    try {
        $st = $conn->prepare(
            "SELECT d.id, d.kind, d.title, d.original_name, d.mime_type, d.size_bytes, d.external_url, dl.parent_id AS note_id, dl.created_datetime
               FROM document_links dl
               JOIN documents d ON d.id = dl.document_id
               JOIN ticket_notes n ON n.id = dl.parent_id
              WHERE dl.parent_type = 'ticket_note' AND n.ticket_id = ? AND d.deleted_datetime IS NULL
              ORDER BY dl.id");
        $st->execute([$ticketId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $kind = in_array($d['kind'], ['file', 'link', 'files'], true) ? $d['kind'] : 'file';
            $row = ['source' => 'note', 'source_id' => (int)$d['note_id'], 'id' => (int)$d['id'], 'kind' => $kind,
                    'name' => $kind === 'files' ? null : ($d['original_name'] ?: $d['title']),
                    'content_type' => $kind === 'file' ? $d['mime_type'] : null,
                    'size_bytes' => $kind === 'file' && $d['size_bytes'] !== null ? (int)$d['size_bytes'] : null];
            if ($kind === 'link') $row['url'] = $d['external_url'];
            $row['created_at'] = apiIsoDate($d['created_datetime']);
            $out[] = $row;
        }
    } catch (Throwable) { /* documents not set up */ }
    return $out;
}

/** GET /tickets/{id}/attachments - files on the ticket's messages and documents on its notes. */
function apiTicketAttachmentsList(PDO $conn, array $apiKey, array $params, array $body): void
{
    apiLoadTicket($conn, $apiKey, (int)$params[0]);   // 404 outside the key's companies and departments
    apiRespond(apiTicketAttachments($conn, (int)$params[0]));
}
