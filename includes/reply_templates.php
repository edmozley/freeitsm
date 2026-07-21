<?php
/**
 * Canned responses — the reply text an analyst inserts instead of retyping it.
 *
 * WHY THIS IS SHARED
 * ------------------
 * Three callers need the same two answers ("which templates may this analyst see?"
 * and "what does this one say for this ticket?"): the settings tab, the reply box
 * picker, and the render endpoint behind it. Two copies of the visibility rule is
 * the dangerous duplication — nothing breaks when they drift, one of them just
 * quietly shows an analyst somebody else's private templates.
 *
 * MERGE CODES ARE DELIBERATELY THE SAME ONES AS THE AUTOMATED EMAILS
 * -----------------------------------------------------------------
 * [requester_first_name], [ticket_reference] and friends already exist for
 * ticket_email_templates, resolved by buildTicketMergeData() in template_email.php.
 * This reuses that builder rather than defining a second vocabulary — an admin who
 * has written one kind of template can write the other without relearning anything,
 * and a merge code added there works here for free.
 *
 * ...BUT THE ESCAPING IS NOT THE SAME, AND THAT IS THE WHOLE POINT
 * ---------------------------------------------------------------
 * resolveMergeCodes() in template_email.php substitutes raw. That is survivable for
 * an outbound email whose only reader is the requester themselves. It is NOT
 * survivable here: a canned response is merged and then dropped into the analyst's
 * TinyMCE editor and re-rendered in the inbox, and `requester_name` ultimately comes
 * from the From header of an email a stranger sent us. A requester called
 * `<img src=x onerror=...>` would otherwise be running script in the analyst's
 * browser at the moment they try to answer them. So every VALUE is escaped before it
 * is merged into the template's HTML — the template body is trusted (an analyst
 * authored it), the values substituted into it never are.
 */

require_once __DIR__ . '/template_email.php';
require_once __DIR__ . '/tenancy.php';

/**
 * The merge codes offered in the UI, in the order they are listed.
 *
 * A subset of buildTicketMergeData()'s keys: the ones that make sense mid-conversation
 * in something a human is about to send. [closed_date] is omitted on purpose — you are
 * not usually typing a canned response into a ticket that is already closed.
 */
function replyTemplateMergeCodes(): array {
    return [
        'requester_first_name' => 'Requester first name',
        'requester_name'       => 'Requester full name',
        'requester_email'      => 'Requester email',
        'ticket_reference'     => 'Ticket reference',
        'ticket_subject'       => 'Ticket subject',
        'ticket_status'        => 'Ticket status',
        'ticket_priority'      => 'Ticket priority',
        'analyst_name'         => 'Your name',
        'department_name'      => 'Department',
        'created_date'         => 'Date raised',
    ];
}

/**
 * Resolve a template body against a ticket, escaping every substituted value.
 *
 * Returns the body unchanged when the ticket can't be read — a template full of
 * literal [requester_first_name] is obviously wrong to the analyst about to send it,
 * whereas silently blanking the codes looks like a finished sentence and gets sent.
 */
function renderReplyTemplate(PDO $conn, string $body, int $ticketId): string {
    $merge = buildTicketMergeData($conn, $ticketId);
    if (!$merge) {
        return $body;
    }

    foreach ($merge as $code => $value) {
        // See the header: the template is trusted, the value is not.
        $safe = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $body = str_replace("[$code]", $safe, $body);
    }
    return $body;
}

/**
 * Every template this analyst may insert, shared first then their own.
 *
 * The visibility rule, in one place:
 *   - SHARED (analyst_id IS NULL) — resolved through getTenantConfigRows() like any
 *     other per-company config list, so a company sees the global defaults it has not
 *     hidden plus the ones it added. Degrades to "all rows" on a single-company
 *     install, which is exactly today's behaviour.
 *   - PRIVATE (analyst_id = me) — never filtered by tenant: it is one person's own
 *     text, it follows them to whichever company they are looking at.
 *
 * An administrator gets no special sight of other people's private templates. There is
 * deliberately no branch here that could give them any.
 *
 * @return array rows with an extra 'scope' => 'shared'|'mine'
 */
function replyTemplatesVisibleTo(PDO $conn, int $analystId, bool $activeOnly = true): array {
    $activeWhere = $activeOnly ? 'is_active = 1 AND analyst_id IS NULL' : 'analyst_id IS NULL';

    $shared = getTenantConfigRows(
        $conn,
        'ticket_reply_templates',
        'reply_template',
        getActiveTenantId($conn, $analystId),
        'id, name, body, display_order, is_active',
        $activeWhere,
        'display_order, name'
    );
    foreach ($shared as &$row) { $row['scope'] = 'shared'; }
    unset($row);

    $sql = "SELECT id, name, body, display_order, is_active
            FROM ticket_reply_templates
            WHERE analyst_id = ?" . ($activeOnly ? " AND is_active = 1" : "") . "
            ORDER BY display_order, name";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$analystId]);
        $mine = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $mine = [];
    }
    foreach ($mine as &$row) { $row['scope'] = 'mine'; }
    unset($row);

    return array_merge($shared, $mine);
}

/**
 * May this analyst write to this template?
 *
 * Returns one of: 'shared' (needs the capability), 'mine' (owns it), or null (must be
 * refused). Kept as a lookup rather than a boolean so the caller can tell "you may not
 * touch this" apart from "you need the settings permission for this one", and so the
 * privilege-escalation case has a single home: a template you own is yours to edit,
 * but turning it INTO a shared one is a settings action and re-checks the capability
 * at the call site.
 */
function replyTemplateWriteScope(PDO $conn, int $analystId, int $templateId): ?string {
    try {
        $stmt = $conn->prepare("SELECT analyst_id FROM ticket_reply_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
    if (!$row) return null;

    if ($row['analyst_id'] === null)              return 'shared';
    if ((int)$row['analyst_id'] === $analystId)   return 'mine';
    return null;   // somebody else's private template — invisible AND untouchable
}
