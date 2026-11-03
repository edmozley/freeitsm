<?php
/**
 * TicketFieldsService - custom fields on tickets (discussion #179).
 *
 * An administrator makes up the fields a kind of ticket needs - "Start date",
 * "Username", "Remote worker" on a New starter - under Tickets -> Settings ->
 * Custom fields, and links each one to some ticket types or to all of them.
 *
 * WHAT LIVES WHERE
 * ----------------
 * - The TYPE rules (what a number accepts, how a date parses, what a dropdown
 *   allows) are includes/typed_fields.php, shared with the CMDB and Assets.
 * - WHICH fields a ticket has is this file: the fields shared by every company
 *   plus the ticket's own company's, that are on every type or linked to the
 *   ticket's type. Resolved in ONE place, fieldsFor(), so the New ticket form,
 *   the ticket panel, the portal, the REST API and the closing rule can never
 *   disagree about it.
 * - Authorisation stays with the caller, as everywhere else: nothing here
 *   decides who may see a ticket.
 *
 * WHEN A FIELD IS REQUIRED - chosen per field
 * -------------------------------------------
 * - required_on_create: the channels that can ASK - the New ticket form, the
 *   REST API, and the portal for fields the requester is asked. Email, chat and
 *   workflows cannot ask anybody anything, so they are never refused for it.
 * - required_on_close: the Mandatory fields rule (MandatoryFieldsService), with
 *   its warn / warn and email / refuse setting, whatever channel raised it.
 *
 * 🔑 CHANGING THE TYPE HIDES, NEVER DELETES. Values are only ever read for the
 * fields that apply now, so an answer to a field the new type does not have is
 * simply not shown - and changing the type back brings it back.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../typed_fields.php';

final class TicketFieldsService
{
    /** Field kinds offered on tickets: every TypedFields type except a link ('ref'), which needs a picker. */
    const TYPES = ['text', 'number', 'date', 'boolean', 'dropdown', 'url', 'email'];

    /** Date modes a date field may use; a mode, not a type, so it can be changed later. */
    const DATE_MODES = ['date', 'datetime', 'time'];

    /** Where ticket field values live, for TypedFields. */
    public static function valueSchema(): array
    {
        return [
            'value_table'  => 'ticket_field_values',
            'owner_column' => 'ticket_id',
            'def_column'   => 'field_id',
            'columns'      => [
                'text'    => 'value_text',
                'number'  => 'value_number',
                'date'    => 'value_date',
                'boolean' => 'value_boolean',
                'ref'     => 'value_ref_id',
            ],
            'options'      => static function (PDO $conn, int $fieldId): array {
                $stmt = $conn->prepare("SELECT option_value FROM ticket_field_options WHERE field_id = ? ORDER BY display_order, id");
                $stmt->execute([$fieldId]);
                return $stmt->fetchAll(PDO::FETCH_COLUMN);
            },
            'unknown_hint' => 'See Tickets -> Settings -> Custom fields.',
        ];
    }

    /** Has Database Verification created the tables yet? Everything here is a no-op until it has. */
    public static function schemaReady(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $conn->query("SELECT 1 FROM ticket_field_values LIMIT 1");
                $conn->query("SELECT ask_requester FROM ticket_fields LIMIT 1");
                $ready = true;
            } catch (Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    // ====================================================================
    //  Which fields a ticket has
    // ====================================================================

    /**
     * The fields a ticket of this company and type has, keyed by field_key, in
     * display order - as TypedFields definitions plus what a screen needs
     * (options, help text, the two required flags, ask_requester).
     *
     * `required` is required_on_create: the rule TypedFields enforces when a
     * ticket is logged, and that stops a filled-in answer being cleared later.
     *
     * @param ?int $tenantId  the ticket's company; null sees only the shared fields
     * @param ?int $typeId  the ticket's type; null sees only the fields on every type
     * @param string $audience  'analyst' (every field) or 'requester' (only those the requester is asked)
     */
    public static function fieldsFor(PDO $conn, ?int $tenantId, ?int $typeId, string $audience = 'analyst'): array
    {
        if (!self::schemaReady($conn)) {
            return [];
        }
        $sql = "SELECT f.* FROM ticket_fields f
                 WHERE f.is_deleted = 0
                   AND (f.tenant_id IS NULL" . ($tenantId !== null ? " OR f.tenant_id = ?" : "") . ")
                   AND (f.all_types = 1"
             . ($typeId !== null ? " OR EXISTS (SELECT 1 FROM ticket_field_types ft WHERE ft.field_id = f.id AND ft.ticket_type_id = ?)" : "")
             . ")" . ($audience === 'requester' ? " AND f.ask_requester = 1" : "")
             . " ORDER BY f.display_order, f.label, f.id";
        $params = [];
        if ($tenantId !== null) $params[] = $tenantId;
        if ($typeId !== null)   $params[] = $typeId;
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }
        $options = self::optionsFor($conn, array_map(fn($r) => (int)$r['id'], $rows));
        $defs = [];
        foreach ($rows as $r) {
            $def = self::toDef($r);
            $def['options'] = $options[(int)$r['id']] ?? [];
            $defs[$def['key']] = $def;
        }
        return $defs;
    }

    /**
     * The fields for an existing ticket row (any row carrying tenant_id and ticket_type_id).
     *
     * @param array $row  the ticket - `tickets` column names
     * @param string $audience  'analyst' or 'requester', as fieldsFor()
     */
    public static function fieldsForTicket(PDO $conn, array $row, string $audience = 'analyst'): array
    {
        return self::fieldsFor(
            $conn,
            isset($row['tenant_id']) && $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,
            isset($row['ticket_type_id']) && $row['ticket_type_id'] !== null && $row['ticket_type_id'] !== '' ? (int)$row['ticket_type_id'] : null,
            $audience
        );
    }

    /**
     * One ticket_fields row -> a TypedFields definition plus presentation.
     *
     * @param array $r  a ticket_fields row
     */
    private static function toDef(array $r): array
    {
        $config = [];
        if (!empty($r['config'])) {
            $decoded = json_decode((string)$r['config'], true);
            if (is_array($decoded)) $config = $decoded;
        }
        return [
            'id'                 => (int)$r['id'],
            'key'                => (string)$r['field_key'],
            'label'              => (string)$r['label'],
            'type'               => (string)$r['field_type'],
            'required'           => (int)$r['required_on_create'] === 1,
            'config'             => $config,
            'ref_kind'           => null,
            'ref_target'         => null,
            'help_text'          => $r['help_text'] ?? null,
            'required_on_create' => (int)$r['required_on_create'] === 1,
            'required_on_close'  => (int)$r['required_on_close'] === 1,
            'ask_requester'      => (int)$r['ask_requester'] === 1,
        ];
    }

    /**
     * Dropdown choices for several fields at once: [field id => [option text, ...]].
     *
     * @param int[] $fieldIds  ticket_fields ids
     */
    private static function optionsFor(PDO $conn, array $fieldIds): array
    {
        $fieldIds = array_values(array_unique(array_filter($fieldIds)));
        if (!$fieldIds) return [];
        $ph = implode(',', array_fill(0, count($fieldIds), '?'));
        $stmt = $conn->prepare("SELECT field_id, option_value FROM ticket_field_options WHERE field_id IN ($ph) ORDER BY display_order, id");
        $stmt->execute($fieldIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $out[(int)$o['field_id']][] = (string)$o['option_value'];
        }
        return $out;
    }

    // ====================================================================
    //  Values
    // ====================================================================

    /**
     * A ticket's answers for the given fields: [field_key => value]. Absent = not answered.
     *
     * @param array $defs  from fieldsFor() / fieldsForTicket()
     */
    public static function values(PDO $conn, int $ticketId, array $defs): array
    {
        if (!$defs || !self::schemaReady($conn)) return [];
        $all = TypedFields::readValues($conn, self::valueSchema(), $defs, [$ticketId]);
        return $all[$ticketId] ?? [];
    }

    /**
     * The fields of a ticket with their answers, ready for a screen or the API:
     * a list of {key, label, type, config, help_text, options, required_on_create,
     * required_on_close, ask_requester, value, display}.
     *
     * @param array $row  the ticket - `tickets` column names, including id
     * @param string $audience  'analyst' or 'requester'
     */
    public static function forTicket(PDO $conn, array $row, string $audience = 'analyst'): array
    {
        $defs = self::fieldsForTicket($conn, $row, $audience);
        if (!$defs) return [];
        $values = self::values($conn, (int)$row['id'], $defs);
        $out = [];
        foreach ($defs as $key => $d) {
            $v = array_key_exists($key, $values) ? $values[$key] : null;
            $out[] = self::present($d) + ['value' => $v, 'display' => self::displayValue($d, $v)];
        }
        return $out;
    }

    /**
     * Where the portal shows a requester the answers they gave (discussion #193):
     * 'off', 'header' (never saved) or 'side'. Tickets -> Settings -> Custom fields.
     */
    public static function portalAnswersPlace(PDO $conn): string
    {
        try {
            $v = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_portal_field_answers'")->fetchColumn();
            return in_array($v, ['off', 'header', 'side'], true) ? $v : 'header';
        } catch (Throwable) {
            return 'header';
        }
    }

    /**
     * Where the portal's New ticket page ASKS the questions (GH #218 part C): 'side'
     * (never saved - the panel beside the editor, as it always was) or 'main' (the
     * main column, between the subject and the description). Install-wide, like the
     * setting above. Tickets -> Settings -> Custom fields.
     */
    public static function portalAskPlace(PDO $conn): string
    {
        try {
            $v = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_portal_field_ask'")->fetchColumn();
            return $v === 'main' ? 'main' : 'side';
        } catch (Throwable) {
            return 'side';
        }
    }

    /**
     * The answers to the fields a ticket asks its requester, as they are now -
     * answered ones only: [{label, type, config, value, display}]. 🔑 The
     * 'requester' audience, so a field only analysts fill in is never returned.
     * Authorisation stays with the caller: only call it for a ticket the portal
     * user has already been allowed to see.
     *
     * @param int $ticketId  the ticket
     */
    public static function portalAnswers(PDO $conn, int $ticketId): array
    {
        $st = $conn->prepare("SELECT id, tenant_id, ticket_type_id FROM tickets WHERE id = ?");
        $st->execute([$ticketId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];
        $out = [];
        foreach (self::forTicket($conn, $row, 'requester') as $f) {
            if ($f['display'] === null) continue;
            $out[] = ['label' => $f['label'], 'type' => $f['type'], 'config' => $f['config'],
                      'value' => $f['value'], 'display' => $f['display']];
        }
        return $out;
    }

    /**
     * The parts of a definition a screen needs, without the engine's internals.
     *
     * @param array $d  a definition from fieldsFor()
     */
    public static function present(array $d): array
    {
        return [
            'key'                => $d['key'],
            'label'              => $d['label'],
            'type'               => $d['type'],
            'config'             => (object)$d['config'],
            'help_text'          => $d['help_text'],
            'options'            => $d['options'] ?? [],
            'required_on_create' => $d['required_on_create'],
            'required_on_close'  => $d['required_on_close'],
            'ask_requester'      => $d['ask_requester'],
        ];
    }

    /**
     * Check values about to be written, without writing anything: unknown keys,
     * the required rule, email and web address formats, and every type rule
     * TypedFields applies. Returns the values tidied (strings trimmed).
     *
     * Call it BEFORE the ticket row is written, so a bad answer never leaves a
     * half-made ticket behind.
     *
     * @param array $defs  the fields that apply, from fieldsFor()
     * @param array $values  [field_key => raw value]; '' or null clears
     * @param bool $isCreate  true when the ticket is being logged: a required field missing altogether is refused too
     */
    public static function validate(PDO $conn, array $defs, array $values, bool $isCreate): array
    {
        $clean = [];
        foreach ($values as $key => $v) {
            $key = (string)$key;
            if (!isset($defs[$key])) {
                throw new ServiceError('validation', 'invalid_field',
                    "'{$key}' is not a custom field this ticket has. See Tickets -> Settings -> Custom fields.");
            }
            if (is_string($v)) $v = trim($v);
            if (is_array($v) || is_object($v)) {
                throw new ServiceError('validation', 'invalid_field', "'{$defs[$key]['label']}' takes a single value.");
            }
            $clean[$key] = $v;
        }

        // `required` on a definition is required_on_create (see toDef()).
        TypedFields::checkRequired($defs, $clean, $isCreate);

        foreach ($clean as $key => $v) {
            if ($v === null || $v === '') continue;
            $d = $defs[$key];
            if ($d['type'] === 'email' && !filter_var((string)$v, FILTER_VALIDATE_EMAIL)) {
                throw new ServiceError('validation', 'invalid_field', "'{$d['label']}' must be an email address.");
            }
            if ($d['type'] === 'url' && !preg_match('~^https?://[^\s]+$~i', (string)$v)) {
                throw new ServiceError('validation', 'invalid_field', "'{$d['label']}' must be a web address starting http:// or https://.");
            }
            if ($d['type'] === 'text' && mb_strlen((string)$v) > 10000) {
                throw new ServiceError('validation', 'invalid_field', "'{$d['label']}' is too long.");
            }
            // The type rules, applied now rather than first discovered mid-write.
            TypedFields::coerce($conn, self::valueSchema(), $d, $v, 0);
        }
        return $clean;
    }

    /**
     * Store answers for one ticket and audit each change as its own history row,
     * whatever channel made it - so a custom field is as accountable as Priority.
     *
     * @param array $defs  the fields that apply, from fieldsFor()
     * @param array $values  [field_key => value], already through validate()
     */
    public static function write(PDO $conn, ActorContext $ctx, int $ticketId, array $defs, array $values): void
    {
        if (!$values) return;
        $before = self::values($conn, $ticketId, $defs);
        TypedFields::writeValues($conn, self::valueSchema(), $ticketId, $defs, $values);
        $after = self::values($conn, $ticketId, $defs);

        $ins = $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        foreach (array_keys($values) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old === $new) continue;
            $d = $defs[$key];
            $ins->execute([
                $ticketId, $ctx->actorId > 0 ? $ctx->actorId : null, mb_substr($d['label'], 0, 100),
                self::auditText($d, $old), self::auditText($d, $new),
            ]);
        }
    }

    /**
     * The answer as a person reads it: Yes / No, a date in its mode, a number
     * with its unit. Null stays null - "not answered".
     *
     * @param array $d  a definition
     * @param mixed $v  a stored value
     */
    public static function displayValue(array $d, $v): ?string
    {
        if ($v === null || $v === '') return null;
        switch ($d['type']) {
            case 'boolean':
                return $v ? 'Yes' : 'No';
            case 'number':
                $dec = isset($d['config']['decimals']) ? max(0, min(4, (int)$d['config']['decimals'])) : null;
                $n = $dec === null ? rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.') : number_format((float)$v, $dec, '.', '');
                return $n . (!empty($d['config']['unit']) ? ' ' . $d['config']['unit'] : '');
            case 'date':
                $mode = $d['config']['date_mode'] ?? 'date';
                $s = (string)$v;
                if ($mode === 'time') return substr($s, 11, 5);
                if ($mode === 'datetime') return substr($s, 0, 16);
                return substr($s, 0, 10);
            default:
                return (string)$v;
        }
    }

    /**
     * An answer as the ticket history stores it: readable, and cut to the column.
     *
     * @param array $d  a definition
     * @param mixed $v  a stored value
     */
    private static function auditText(array $d, $v): ?string
    {
        $s = self::displayValue($d, $v);
        return $s === null ? null : mb_substr($s, 0, 500);
    }

    /**
     * Custom fields required at closing that this ticket would close without -
     * [ 'cf:<key>' => label ] - for MandatoryFieldsService::missing().
     *
     * @param array $row  the ticket as it will be after the save (id, tenant_id, ticket_type_id)
     * @param ?array $pending  [field_key => value] being written in the same save, which counts
     */
    public static function missingOnClose(PDO $conn, array $row, ?array $pending = null): array
    {
        if (!self::schemaReady($conn) || empty($row['id'])) return [];
        $defs = array_filter(self::fieldsForTicket($conn, $row), fn($d) => $d['required_on_close']);
        if (!$defs) return [];
        $values = self::values($conn, (int)$row['id'], $defs);
        $out = [];
        foreach ($defs as $key => $d) {
            $v = ($pending !== null && array_key_exists($key, $pending)) ? $pending[$key] : ($values[$key] ?? null);
            if ($v === null || $v === '') $out['cf:' . $key] = $d['label'];
        }
        return $out;
    }

    // ====================================================================
    //  Settings: designing the fields
    // ====================================================================

    /**
     * Who is designing: [manages shared fields?, the company otherwise].
     * The same rule as ticket types - working in the default company (or on a
     * one-company install) manages the shared fields; working in a client
     * company manages that company's own.
     */
    private static function designScope(PDO $conn, int $analystId): array
    {
        $multi    = isMultiTenant($conn);
        $activeId = $multi ? getActiveTenantId($conn, $analystId) : null;
        $shared   = !$multi || $activeId === null || $activeId === getDefaultTenantId($conn);
        return [$shared, $shared ? null : (int)$activeId];
    }

    /**
     * Every field the settings screen shows: the shared ones and, in a client
     * company, its own - each with its options, linked type ids, how many tickets
     * have answered it, and whether this context may edit it.
     */
    public static function listForSettings(PDO $conn, int $analystId): array
    {
        if (!self::schemaReady($conn)) return [];
        [$shared, $tenantId] = self::designScope($conn, $analystId);
        $sql = "SELECT f.*, (SELECT COUNT(*) FROM ticket_field_values v WHERE v.field_id = f.id) AS answered
                  FROM ticket_fields f
                 WHERE f.is_deleted = 0 AND (f.tenant_id IS NULL" . ($tenantId !== null ? " OR f.tenant_id = ?" : "") . ")
              ORDER BY f.display_order, f.label, f.id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($tenantId !== null ? [$tenantId] : []);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $options = self::optionsFor($conn, $ids);
        $types = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $t = $conn->prepare("SELECT field_id, ticket_type_id FROM ticket_field_types WHERE field_id IN ($ph)");
            $t->execute($ids);
            foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $l) $types[(int)$l['field_id']][] = (int)$l['ticket_type_id'];
        }
        $out = [];
        foreach ($rows as $r) {
            $d = self::toDef($r);
            $owner = $r['tenant_id'] === null ? null : (int)$r['tenant_id'];
            $out[] = self::present($d) + [
                'id'        => (int)$r['id'],
                'all_types' => (int)$r['all_types'] === 1,
                'type_ids'  => $types[(int)$r['id']] ?? [],
                'options'   => $options[(int)$r['id']] ?? [],
                'answered'  => (int)$r['answered'],
                'scope'     => $owner === null ? 'shared' : 'company',
                'editable'  => $shared ? $owner === null : $owner === $tenantId,
            ];
        }
        return $out;
    }

    /**
     * Create or change a field.
     *
     * @param array $in  id (0 = new), label, field_type, config {multiline, decimals, unit, date_mode}, help_text,
     *     options (dropdown choices), all_types, type_ids, required_on_create, required_on_close, ask_requester
     * @return array {id, created}
     */
    public static function saveField(PDO $conn, ActorContext $ctx, array $in): array
    {
        if (!self::schemaReady($conn)) {
            throw new ServiceError('conflict', 'schema_not_ready', 'Run System -> Database Verification before adding custom fields.');
        }
        $id    = (int)($in['id'] ?? 0);
        $label = trim((string)($in['label'] ?? ''));
        if ($label === '') {
            throw new ServiceError('validation', 'missing_field', 'Give the field a name.');
        }
        if (mb_strlen($label) > 150) {
            throw new ServiceError('validation', 'invalid_field', 'The name can be at most 150 characters.');
        }
        $type   = trim((string)($in['field_type'] ?? 'text'));
        if (!in_array($type, self::TYPES, true)) {
            throw new ServiceError('validation', 'invalid_field', 'Choose what kind of field this is.');
        }
        $config = self::cleanConfig($type, is_array($in['config'] ?? null) ? $in['config'] : []);
        $help   = trim((string)($in['help_text'] ?? ''));
        $help   = $help === '' ? null : mb_substr($help, 0, 500);
        $allTypes = !empty($in['all_types']) ? 1 : 0;
        $typeIds  = array_values(array_unique(array_filter(array_map('intval', (array)($in['type_ids'] ?? [])))));
        if (!$allTypes && !$typeIds) {
            throw new ServiceError('validation', 'missing_field', 'Choose the ticket types this field is on, or tick every type.');
        }
        $options = [];
        if ($type === 'dropdown') {
            foreach ((array)($in['options'] ?? []) as $o) {
                $o = trim((string)$o);
                if ($o !== '' && !in_array($o, $options, true)) $options[] = mb_substr($o, 0, 255);
            }
            if (!$options) {
                throw new ServiceError('validation', 'missing_field', 'A dropdown needs at least one option.');
            }
        }
        $reqCreate = !empty($in['required_on_create']) ? 1 : 0;
        $reqClose  = !empty($in['required_on_close']) ? 1 : 0;
        $ask       = !empty($in['ask_requester']) ? 1 : 0;

        [$shared, $tenantId] = self::designScope($conn, $ctx->actorId);
        foreach ($typeIds as $tid) {
            self::assertTypeUsable($conn, $tid, $tenantId);
        }

        $owns = !$conn->inTransaction();
        if ($owns) $conn->beginTransaction();
        try {
            if ($id > 0) {
                $cur = self::loadEditable($conn, $id, $shared, $tenantId);
                if ($type !== $cur['field_type'] && self::answered($conn, $id) > 0) {
                    throw new ServiceError('conflict', 'type_locked',
                        "'{$cur['label']}' already has answers, so its kind can no longer be changed. Retire it and add a new field instead.");
                }
                $conn->prepare(
                    "UPDATE ticket_fields SET label = ?, field_type = ?, config = ?, help_text = ?, all_types = ?,
                            required_on_create = ?, required_on_close = ?, ask_requester = ? WHERE id = ?"
                )->execute([$label, $type, $config ? json_encode($config) : null, $help, $allTypes, $reqCreate, $reqClose, $ask, $id]);
                $created = false;
            } else {
                $key = self::freeKey($conn, $label, $tenantId);
                $order = (int)$conn->query("SELECT COALESCE(MAX(display_order), 0) + 1 FROM ticket_fields")->fetchColumn();
                $conn->prepare(
                    "INSERT INTO ticket_fields (field_key, label, field_type, config, help_text, all_types,
                            required_on_create, required_on_close, ask_requester, display_order, tenant_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([$key, $label, $type, $config ? json_encode($config) : null, $help, $allTypes,
                            $reqCreate, $reqClose, $ask, $order, $tenantId]);
                $id = (int)$conn->lastInsertId();
                $created = true;
            }

            $conn->prepare("DELETE FROM ticket_field_options WHERE field_id = ?")->execute([$id]);
            $insO = $conn->prepare("INSERT INTO ticket_field_options (field_id, option_value, display_order) VALUES (?, ?, ?)");
            foreach ($options as $i => $o) $insO->execute([$id, $o, $i]);

            $conn->prepare("DELETE FROM ticket_field_types WHERE field_id = ?")->execute([$id]);
            if (!$allTypes) {
                $insT = $conn->prepare("INSERT INTO ticket_field_types (field_id, ticket_type_id) VALUES (?, ?)");
                foreach ($typeIds as $tid) $insT->execute([$id, $tid]);
            }
            if ($owns) $conn->commit();
        } catch (Throwable $e) {
            if ($owns && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        return ['id' => $id, 'created' => $created];
    }

    /**
     * Retire a field. SOFT delete, always: the answers stay in the database (the
     * foreign key is RESTRICT), they just stop being shown.
     *
     * @param int $id  the ticket_fields row
     */
    public static function deleteField(PDO $conn, ActorContext $ctx, int $id): void
    {
        [$shared, $tenantId] = self::designScope($conn, $ctx->actorId);
        self::loadEditable($conn, $id, $shared, $tenantId);
        $conn->prepare("UPDATE ticket_fields SET is_deleted = 1 WHERE id = ?")->execute([$id]);
    }

    /**
     * Put fields in the order given; ids this context may not edit are left where they are.
     *
     * @param int[] $ids  ticket_fields ids, first to last
     */
    public static function reorder(PDO $conn, ActorContext $ctx, array $ids): void
    {
        [$shared, $tenantId] = self::designScope($conn, $ctx->actorId);
        $upd = $conn->prepare("UPDATE ticket_fields SET display_order = ? WHERE id = ?");
        foreach (array_values(array_map('intval', $ids)) as $i => $fid) {
            try {
                self::loadEditable($conn, $fid, $shared, $tenantId);
            } catch (ServiceError) {
                continue;
            }
            $upd->execute([$i + 1, $fid]);
        }
    }

    /**
     * Only the settings each kind uses, so nothing stale rides along.
     *
     * @param string $type  the field kind
     * @param array $c  config as sent
     */
    private static function cleanConfig(string $type, array $c): array
    {
        switch ($type) {
            case 'text':
                return !empty($c['multiline']) ? ['multiline' => true] : [];
            case 'number':
                $out = [];
                if (isset($c['decimals']) && $c['decimals'] !== '') $out['decimals'] = max(0, min(4, (int)$c['decimals']));
                $unit = trim((string)($c['unit'] ?? ''));
                if ($unit !== '') $out['unit'] = mb_substr($unit, 0, 20);
                return $out;
            case 'date':
                $mode = (string)($c['date_mode'] ?? 'date');
                return ['date_mode' => in_array($mode, self::DATE_MODES, true) ? $mode : 'date'];
            default:
                return [];
        }
    }

    /**
     * A type may be linked only if this context can see it: a shared type, or
     * this company's own.
     *
     * @param int $typeId  a ticket_types id
     * @param ?int $tenantId  the designing company; null = shared fields
     */
    private static function assertTypeUsable(PDO $conn, int $typeId, ?int $tenantId): void
    {
        $stmt = $conn->prepare("SELECT tenant_id FROM ticket_types WHERE id = ?");
        $stmt->execute([$typeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $ok = $row && ($row['tenant_id'] === null || ($tenantId !== null && (int)$row['tenant_id'] === $tenantId));
        if (!$ok) {
            throw new ServiceError('validation', 'invalid_field', 'One of the chosen ticket types is not available here.');
        }
    }

    /**
     * Load a field this context may change, or explain why not.
     *
     * @param bool $shared  true when the context manages shared fields
     * @param ?int $tenantId  the company, when it does not
     */
    private static function loadEditable(PDO $conn, int $id, bool $shared, ?int $tenantId): array
    {
        $stmt = $conn->prepare("SELECT * FROM ticket_fields WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'That field no longer exists.');
        }
        $owner = $row['tenant_id'] === null ? null : (int)$row['tenant_id'];
        if ($shared && $owner !== null) {
            throw new ServiceError('forbidden', 'forbidden', "That is a company's own field - switch to that company to change it.");
        }
        if (!$shared && $owner === null) {
            throw new ServiceError('forbidden', 'forbidden', 'Shared fields are managed from the default company.');
        }
        if (!$shared && $owner !== $tenantId) {
            throw new ServiceError('forbidden', 'forbidden', 'That field belongs to another company.');
        }
        return $row;
    }

    /** How many tickets have answered a field. */
    private static function answered(PDO $conn, int $id): int
    {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM ticket_field_values WHERE field_id = ?");
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * A key no other field this company can see uses - checked in code because
     * MySQL's unique index does not dedupe the shared (NULL tenant) rows.
     *
     * @param string $label  the field's name, which the key is made from
     * @param ?int $tenantId  the owning company; null = shared
     */
    private static function freeKey(PDO $conn, string $label, ?int $tenantId): string
    {
        $base = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
        if ($base === '' || ctype_digit($base[0])) $base = 'f_' . $base;
        $base = substr($base, 0, 90);
        $chk = $conn->prepare("SELECT 1 FROM ticket_fields WHERE field_key = ? AND (tenant_id IS NULL OR tenant_id <=> ? OR ? IS NULL) LIMIT 1");
        for ($n = 1; ; $n++) {
            $key = $n === 1 ? $base : $base . '_' . $n;
            $chk->execute([$key, $tenantId, $tenantId]);
            if ($chk->fetchColumn() === false) return $key;
        }
    }
}
