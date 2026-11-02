<?php
/**
 * The ticket type a new ticket gets when nobody chose one (GH #230).
 *
 * Ticket types matter from the moment a ticket exists: the number can carry the
 * type ({TYPE}, or a counter per type), and categories and custom fields can
 * belong to a type. So the type is decided BEFORE the ticket is inserted, on
 * every path that creates one, by ticketTypeAutoPick():
 *
 *   1. the company's own rules, top to bottom, then the shared rules (tenant_id
 *      NULL) - the first whose criteria all match and whose type the company can
 *      use wins;
 *   2. otherwise the default type: the company's own (tenant_settings), else the
 *      install-wide one (system_settings), key 'ticket_default_type_id';
 *   3. otherwise no type, as before this existed.
 *
 * A type somebody CHOSE always wins - the callers only ask when they have none.
 * Later changes belong to Workflow ("Set ticket type"), which runs after creation.
 *
 * Criteria (conditions_json; an empty one is ignored, every one given must match):
 *   channels      how it arrived: TICKET_TYPE_CHANNELS
 *   mailboxes     the mailbox it arrived at or will be answered from
 *   origins       its origin (Tickets -> Settings -> Origins), where one is set
 *   departments   its department, where one is set at creation
 *   companies     the ticket's company - shared rules on a multi-company install
 *   subject       words or phrases, one per line; any one found (any case) matches
 *   domains       requester email domains, one per line; a subdomain matches too
 *
 * TRAP: this must never stop a ticket being created. Anything that goes wrong
 *   (the table not made yet, a bad row) means "no type", logged, and the ticket
 *   is raised exactly as before.
 *
 * @wiki Ticket-Types-For-New-Tickets
 */

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/tenant_settings.php';

/**
 * How a ticket can arrive, for the "arrived by" criterion. The caller says which;
 * nothing is inferred from the ticket afterwards. Labels: tickets.type_rules.channel_<key>.
 */
const TICKET_TYPE_CHANNELS = ['email', 'portal', 'form', 'api', 'webchat', 'messaging', 'catalogue', 'workflow', 'phone', 'monitoring', 'internal'];

/** The criteria a rule can hold, in the order they are shown. */
const TICKET_TYPE_CRITERIA = ['channels', 'mailboxes', 'origins', 'departments', 'companies', 'subject', 'domains'];

/** The setting holding the default type's id ('' or '0' = none). */
const TICKET_TYPE_DEFAULT_KEY = 'ticket_default_type_id';

/** Whether Database Verification has made the rules table. */
function ticketTypeRulesReady(PDO $conn): bool
{
    static $ok = [];
    $k = spl_object_id($conn);
    if (isset($ok[$k])) return $ok[$k];
    try { $conn->query("SELECT id FROM ticket_type_rules LIMIT 0"); return $ok[$k] = true; }
    catch (Throwable) { return $ok[$k] = false; }
}

/**
 * The lines of a textarea criterion, trimmed, lower-cased, blanks and repeats dropped.
 *
 * @param bool $domain  strip a leading '@' and any trailing dot, as a domain is typed both ways
 * @return string[]
 */
function ticketTypeRuleLines($text, bool $domain = false): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $l) {
        $l = mb_strtolower(trim($l));
        if ($domain) $l = trim(ltrim($l, '@'), '.');
        if ($l !== '' && !in_array($l, $out, true)) $out[] = mb_substr($l, 0, 200);
    }
    return array_slice($out, 0, 100);
}

/**
 * A rule's criteria, cleaned: unknown keys dropped, id lists as unique ints,
 * channels from the fixed list, text as lines. What is saved and what is matched.
 *
 * @param mixed $c  decoded conditions_json, or what the settings screen sent
 */
function ticketTypeRuleConditions($c): array
{
    $c = is_array($c) ? $c : [];
    $ids = fn($v) => array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []), fn($i) => $i > 0)));
    $out = [
        'channels'    => array_values(array_intersect(TICKET_TYPE_CHANNELS, is_array($c['channels'] ?? null) ? $c['channels'] : [])),
        'mailboxes'   => $ids($c['mailboxes'] ?? []),
        'origins'     => $ids($c['origins'] ?? []),
        'departments' => $ids($c['departments'] ?? []),
        'companies'   => $ids($c['companies'] ?? []),
        'subject'     => ticketTypeRuleLines(is_array($c['subject'] ?? null) ? implode("\n", $c['subject']) : ($c['subject'] ?? '')),
        'domains'     => ticketTypeRuleLines(is_array($c['domains'] ?? null) ? implode("\n", $c['domains']) : ($c['domains'] ?? ''), true),
    ];
    return $out;
}

/** Whether a rule's criteria say anything at all (a rule with none would catch every ticket). */
function ticketTypeRuleHasCriteria(array $c): bool
{
    foreach (TICKET_TYPE_CRITERIA as $k) if (!empty($c[$k])) return true;
    return false;
}

/**
 * Whether one rule's criteria match a new ticket. An empty criterion is ignored;
 * a criterion whose fact is unknown (no department yet, say) does NOT match.
 *
 * @param array $c      ticketTypeRuleConditions()
 * @param array $facts  channel, mailbox_id, origin_id, department_id, tenant_id, subject, requester_email
 */
function ticketTypeRuleMatches(array $c, array $facts): bool
{
    $in = fn(array $list, $v) => $v !== null && $v !== '' && in_array((int)$v, $list, true);
    if ($c['channels'] && !in_array((string)($facts['channel'] ?? ''), $c['channels'], true)) return false;
    if ($c['mailboxes'] && !$in($c['mailboxes'], $facts['mailbox_id'] ?? null)) return false;
    if ($c['origins'] && !$in($c['origins'], $facts['origin_id'] ?? null)) return false;
    if ($c['departments'] && !$in($c['departments'], $facts['department_id'] ?? null)) return false;
    if ($c['companies'] && !$in($c['companies'], $facts['tenant_id'] ?? null)) return false;
    if ($c['subject']) {
        $subject = mb_strtolower((string)($facts['subject'] ?? ''));
        $hit = false;
        foreach ($c['subject'] as $w) if ($subject !== '' && mb_strpos($subject, $w) !== false) { $hit = true; break; }
        if (!$hit) return false;
    }
    if ($c['domains']) {
        $email = mb_strtolower(trim((string)($facts['requester_email'] ?? '')));
        $at = mb_strrpos($email, '@');
        $domain = $at === false ? '' : mb_substr($email, $at + 1);
        $hit = false;
        foreach ($c['domains'] as $d) if ($domain !== '' && ($domain === $d || str_ends_with($domain, '.' . $d))) { $hit = true; break; }
        if (!$hit) return false;
    }
    return true;
}

/**
 * The types a company may give a ticket: active, shared and not hidden by it, or
 * its own. A ticket with no company yet (triage) may only have a shared type.
 *
 * @return array<int,string> id => name
 */
function ticketTypeUsable(PDO $conn, ?int $tenantId): array
{
    $rows = $tenantId !== null
        ? getTenantConfigRows($conn, 'ticket_types', 'ticket_type', $tenantId, 'id, name, tenant_id', 'is_active = 1', 'display_order, name')
        : $conn->query("SELECT id, name FROM ticket_types WHERE is_active = 1" . (isMultiTenant($conn) ? " AND tenant_id IS NULL" : "") . " ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['id']] = (string)$r['name'];
    return $out;
}

/**
 * The rules that apply to a company's tickets, in the order they are checked:
 * its own first, then the shared ones. Active only unless $all.
 *
 * @return array<int,array> rows with 'conditions' decoded
 */
function ticketTypeRulesFor(PDO $conn, ?int $tenantId, bool $all = false): array
{
    if (!ticketTypeRulesReady($conn)) return [];
    $active = $all ? '' : ' AND is_active = 1';
    $rows = [];
    if ($tenantId !== null) {
        $s = $conn->prepare("SELECT * FROM ticket_type_rules WHERE tenant_id = ?$active ORDER BY display_order, id");
        $s->execute([$tenantId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    $rows = array_merge($rows, $conn->query("SELECT * FROM ticket_type_rules WHERE tenant_id IS NULL$active ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC));
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['ticket_type_id'] = (int)$r['ticket_type_id'];
        $r['tenant_id'] = $r['tenant_id'] !== null ? (int)$r['tenant_id'] : null;
        $r['is_active'] = (bool)$r['is_active'];
        $r['conditions'] = ticketTypeRuleConditions(json_decode((string)$r['conditions_json'], true));
    }
    unset($r);
    return $rows;
}

/**
 * The type a new ticket should get, and why - or null to leave it with none.
 * Call ONLY when nobody chose a type. Never throws.
 *
 * @param ?int $tenantId  the ticket's company (null = triage)
 * @param array $facts    channel (TICKET_TYPE_CHANNELS), mailbox_id, origin_id, department_id, subject, requester_email
 * @return ?array{type_id:int, type_name:string, rule_id:?int, rule_name:?string}
 */
function ticketTypeAutoPick(PDO $conn, ?int $tenantId, array $facts): ?array
{
    try {
        $facts['tenant_id'] = $tenantId;
        $usable = ticketTypeUsable($conn, $tenantId);
        if (!$usable) return null;
        foreach (ticketTypeRulesFor($conn, $tenantId) as $r) {
            // A rule whose type this company cannot use (hidden, retired, another
            // company's) is passed over, so the next rule or the default decides.
            if (!isset($usable[$r['ticket_type_id']])) continue;
            if (!ticketTypeRuleHasCriteria($r['conditions'])) continue;
            if (ticketTypeRuleMatches($r['conditions'], $facts)) {
                return ['type_id' => $r['ticket_type_id'], 'type_name' => $usable[$r['ticket_type_id']], 'rule_id' => $r['id'], 'rule_name' => (string)$r['name']];
            }
        }
        $default = (int)tenantSetting($conn, $tenantId, TICKET_TYPE_DEFAULT_KEY, '');
        if ($default > 0 && isset($usable[$default])) {
            return ['type_id' => $default, 'type_name' => $usable[$default], 'rule_id' => null, 'rule_name' => null];
        }
    } catch (Throwable $e) {
        error_log('ticketTypeAutoPick: ' . $e->getMessage());
    }
    return null;
}

/**
 * Notes on the ticket's history why it has the type it was born with, so "why is
 * this a service request?" has an answer. Stored in English, like the rest of the
 * trail. Never throws.
 *
 * @param ?array $pick  ticketTypeAutoPick()'s answer; null writes nothing
 */
function ticketTypeAutoRecord(PDO $conn, int $ticketId, ?array $pick): void
{
    if (!$pick) return;
    $why = $pick['rule_name'] !== null ? 'set by the rule "' . $pick['rule_name'] . '"' : 'the default type for new tickets';
    try {
        $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
                        VALUES (?, NULL, 'Ticket Type', NULL, ?, UTC_TIMESTAMP())")
             ->execute([$ticketId, mb_substr($pick['type_name'] . ' (' . $why . ')', 0, 500)]);
    } catch (Throwable $e) {
        error_log('ticketTypeAutoRecord: ' . $e->getMessage());
    }
}
