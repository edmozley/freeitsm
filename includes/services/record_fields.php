<?php
/**
 * RecordFieldsService - custom fields on companies and people (GH #279, 5.4.0).
 *
 * An administrator makes up the fields a company or a person needs - an
 * account manager's name, a support tier, a badge number - under System ->
 * Custom fields. They are on the shared TypedFields engine (includes/typed_fields.php)
 * that ticket, CMDB and asset fields use, so the type rules are the same
 * everywhere, and the screens draw them with the same renderer
 * (assets/js/ticket-fields.js).
 *
 * ONE service, three kinds of record, described by ENTITIES below:
 *   - company: one list of fields for the whole install (Ed, 15 Nov 2026: a
 *     field "belonging to" one company would only ever be on that company's own
 *     record - a note, not a field);
 *   - person: fields shared by every company (tenant_id NULL) plus a company's
 *     own, so one client can have a "Badge number" the others do not;
 *   - consumable: an item in Assets -> Consumables (5.8.0) - fields shared by every
 *     consumable type (consumable_type_id NULL) plus a type's own, so toner can have
 *     a "Yield (pages)" and paper a "Weight (gsm)".
 * A kind's SCOPE ('scope_col') is the column that narrows its fields: people by
 * company, consumables by type, companies by nothing.
 *
 * 🔑 A PERSON WHO MOVES COMPANY keeps the old company's answers, hidden: values
 * are only ever read for the fields that apply now, so moving back brings them
 * back - the same rule as a ticket that changes type or company.
 *
 * Every change is recorded: a company's in company_audit, a person's in
 * user_audit. Directory sync never touches these values.
 *
 * Design and decisions: wiki Custom-Fields-Companies-People-Design.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../typed_fields.php';

final class RecordFieldsService
{
    /** Field kinds offered: every TypedFields type except a link ('ref'), as on tickets. */
    const TYPES = ['text', 'number', 'date', 'boolean', 'dropdown', 'url', 'email'];

    /** Date modes a date field may use. */
    const DATE_MODES = ['date', 'datetime', 'time'];

    /** The two kinds of record, and where each keeps its fields, values and history. */
    const ENTITIES = [
        'company' => [
            'defs'       => 'company_fields',
            'options'    => 'company_field_options',
            'values'     => 'company_field_values',
            'owner'      => 'tenant_id',
            'per_tenant' => false,
            'scope_col'  => null,
            'audit'      => 'company_audit',
            'audit_fk'   => 'tenant_id',
            'noun'       => 'company',
        ],
        'person' => [
            'defs'       => 'person_fields',
            'options'    => 'person_field_options',
            'values'     => 'person_field_values',
            'owner'      => 'user_id',
            'per_tenant' => true,
            'scope_col'  => 'tenant_id',
            'audit'      => 'user_audit',
            'audit_fk'   => 'user_id',
            'noun'       => 'person',
        ],
        'consumable' => [
            'defs'        => 'consumable_fields',
            'options'     => 'consumable_field_options',
            'values'      => 'consumable_field_values',
            'owner'       => 'item_id',
            'per_tenant'  => false,
            'scope_col'   => 'consumable_type_id',   // NULL = every type
            'scope_table' => 'consumable_types',
            'audit'       => 'consumable_audit',
            'audit_fk'    => 'item_id',
            'noun'        => 'consumable item',
        ],
    ];

    /**
     * @param string $entity  'company', 'person' or 'consumable'
     */
    private static function cfg(string $entity): array
    {
        if (!isset(self::ENTITIES[$entity])) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown kind of record.');
        }
        return self::ENTITIES[$entity];
    }

    /** Where this kind's values live, for TypedFields. */
    public static function valueSchema(string $entity): array
    {
        $c = self::cfg($entity);
        return [
            'value_table'  => $c['values'],
            'owner_column' => $c['owner'],
            'def_column'   => 'field_id',
            'columns'      => [
                'text'    => 'value_text',
                'number'  => 'value_number',
                'date'    => 'value_date',
                'boolean' => 'value_boolean',
                'ref'     => 'value_ref_id',
            ],
            'options'      => static function (PDO $conn, int $fieldId) use ($c): array {
                $stmt = $conn->prepare("SELECT option_value FROM `{$c['options']}` WHERE field_id = ? ORDER BY display_order, id");
                $stmt->execute([$fieldId]);
                return $stmt->fetchAll(PDO::FETCH_COLUMN);
            },
            'unknown_hint' => 'See System -> Custom fields.',
        ];
    }

    /** Has Database Verification created this kind's tables? Everything is a no-op until it has. */
    public static function schemaReady(PDO $conn, string $entity): bool
    {
        static $ready = [];
        if (!array_key_exists($entity, $ready)) {
            $c = self::cfg($entity);
            try {
                $conn->query("SELECT 1 FROM `{$c['values']}` LIMIT 1");
                $conn->query("SELECT 1 FROM `{$c['defs']}` LIMIT 1");
                if ($entity === 'company') $conn->query("SELECT 1 FROM company_audit LIMIT 1");
                $ready[$entity] = true;
            } catch (Throwable) {
                $ready[$entity] = false;
            }
        }
        return $ready[$entity];
    }

    // ====================================================================
    //  Which fields a record has
    // ====================================================================

    /**
     * The fields a record has, keyed by field_key, in display order: TypedFields
     * definitions plus options and help text. A company has every company field;
     * a person has the shared person fields plus their own company's.
     *
     * @param ?int $tenantId  the scope: a person's company (null = the Default company); a consumable
     *                        item's type (null = only the fields every type has); ignored for a company
     */
    public static function fieldsFor(PDO $conn, string $entity, ?int $tenantId = null): array
    {
        if (!self::schemaReady($conn, $entity)) return [];
        $c = self::cfg($entity);
        $params = [];
        $where = "f.is_deleted = 0";
        if ($c['per_tenant']) {
            // A person with no company belongs to the Default company, as everywhere.
            $tenantId ??= getDefaultTenantId($conn);
            $where .= " AND (f.tenant_id IS NULL OR f.tenant_id = ?)";
            $params[] = $tenantId;
        } elseif ($c['scope_col'] !== null) {
            $col = $c['scope_col'];
            if ($tenantId === null) {
                $where .= " AND f.`$col` IS NULL";
            } else {
                $where .= " AND (f.`$col` IS NULL OR f.`$col` = ?)";
                $params[] = $tenantId;
            }
        }
        $stmt = $conn->prepare("SELECT f.* FROM `{$c['defs']}` f WHERE $where ORDER BY f.display_order, f.label, f.id");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $options = self::optionsFor($conn, $entity, array_map(fn($r) => (int)$r['id'], $rows));
        $defs = [];
        foreach ($rows as $r) {
            $d = self::toDef($r);
            $d['options'] = $options[(int)$r['id']] ?? [];
            $defs[$d['key']] = $d;
        }
        return $defs;
    }

    /** One definitions row -> a TypedFields definition plus presentation. */
    private static function toDef(array $r): array
    {
        $config = [];
        if (!empty($r['config'])) {
            $decoded = json_decode((string)$r['config'], true);
            if (is_array($decoded)) $config = $decoded;
        }
        return [
            'id'         => (int)$r['id'],
            'key'        => (string)$r['field_key'],
            'label'      => (string)$r['label'],
            'type'       => (string)$r['field_type'],
            'required'   => (int)$r['is_required'] === 1,
            'config'     => $config,
            'ref_kind'   => null,
            'ref_target' => null,
            'help_text'  => $r['help_text'] ?? null,
            'tenant_id'  => array_key_exists('tenant_id', $r) && $r['tenant_id'] !== null ? (int)$r['tenant_id'] : null,
            'scope_id'   => array_key_exists('consumable_type_id', $r) && $r['consumable_type_id'] !== null ? (int)$r['consumable_type_id']
                            : (array_key_exists('tenant_id', $r) && $r['tenant_id'] !== null ? (int)$r['tenant_id'] : null),
        ];
    }

    /** Dropdown choices for several fields at once: [field id => [option, ...]]. */
    private static function optionsFor(PDO $conn, string $entity, array $fieldIds): array
    {
        $fieldIds = array_values(array_unique(array_filter($fieldIds)));
        if (!$fieldIds) return [];
        $c = self::cfg($entity);
        $ph = implode(',', array_fill(0, count($fieldIds), '?'));
        $stmt = $conn->prepare("SELECT field_id, option_value FROM `{$c['options']}` WHERE field_id IN ($ph) ORDER BY display_order, id");
        $stmt->execute($fieldIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) $out[(int)$o['field_id']][] = (string)$o['option_value'];
        return $out;
    }

    // ====================================================================
    //  Values
    // ====================================================================

    /** A record's answers for the given fields: [field_key => value]. Absent = not answered. */
    public static function values(PDO $conn, string $entity, int $ownerId, array $defs): array
    {
        if (!$defs || !self::schemaReady($conn, $entity)) return [];
        $all = TypedFields::readValues($conn, self::valueSchema($entity), $defs, [$ownerId]);
        return $all[$ownerId] ?? [];
    }

    /** Many records' answers at once, for a list or the REST API: [owner id => [key => value]]. */
    public static function valuesMany(PDO $conn, string $entity, array $ownerIds, array $defs): array
    {
        if (!$defs || !$ownerIds || !self::schemaReady($conn, $entity)) return [];
        return TypedFields::readValues($conn, self::valueSchema($entity), $defs, array_values(array_unique(array_map('intval', $ownerIds))));
    }

    /**
     * The fields of one record with their answers, for a screen: a list of
     * {key, label, type, config, help_text, options, required_on_create, value, display}.
     * `required_on_create` is the name the shared renderer reads.
     *
     * @param ?int $tenantId  a person's company; ignored for a company
     */
    public static function forRecord(PDO $conn, string $entity, int $ownerId, ?int $tenantId = null): array
    {
        $defs = self::fieldsFor($conn, $entity, $tenantId);
        if (!$defs) return [];
        $values = $ownerId > 0 ? self::values($conn, $entity, $ownerId, $defs) : [];
        $out = [];
        foreach ($defs as $key => $d) {
            $v = array_key_exists($key, $values) ? $values[$key] : null;
            $out[] = self::present($d) + ['value' => $v, 'display' => self::displayValue($d, $v)];
        }
        return $out;
    }

    /** What the REST API returns: {field_key: value|null} for every field the record has. */
    public static function apiMap(PDO $conn, string $entity, int $ownerId, ?int $tenantId = null): object
    {
        $defs = self::fieldsFor($conn, $entity, $tenantId);
        $values = $defs ? self::values($conn, $entity, $ownerId, $defs) : [];
        $out = [];
        foreach (array_keys($defs) as $key) $out[$key] = $values[$key] ?? null;
        return (object)$out;
    }

    /** The parts of a definition a screen needs. */
    public static function present(array $d): array
    {
        return [
            'key'                => $d['key'],
            'label'              => $d['label'],
            'type'               => $d['type'],
            'config'             => (object)$d['config'],
            'help_text'          => $d['help_text'],
            'options'            => $d['options'] ?? [],
            'required_on_create' => $d['required'],
        ];
    }

    /**
     * Check values about to be written, without writing anything: unknown keys,
     * required fields, email and web address formats, and every TypedFields rule.
     * Returns the values tidied. Call it BEFORE the record is written.
     *
     * @param array $values  [field_key => raw value]; '' or null clears
     * @param bool $isCreate  a new record: a required field missing altogether is refused too
     */
    public static function validate(PDO $conn, string $entity, array $defs, array $values, bool $isCreate): array
    {
        $noun = self::cfg($entity)['noun'];
        $clean = [];
        foreach ($values as $key => $v) {
            $key = (string)$key;
            if (!isset($defs[$key])) {
                throw new ServiceError('validation', 'invalid_field', "'{$key}' is not a custom field this {$noun} has. See System -> Custom fields.");
            }
            if (is_string($v)) $v = trim($v);
            if (is_array($v) || is_object($v)) {
                throw new ServiceError('validation', 'invalid_field', "'{$defs[$key]['label']}' takes a single value.");
            }
            $clean[$key] = $v;
        }
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
            TypedFields::coerce($conn, self::valueSchema($entity), $d, $v, 0);
        }
        return $clean;
    }

    /**
     * Store answers for one record and record each change in its history.
     *
     * @param array $values  [field_key => value], already through validate()
     * @param string $source  'app' or 'api' (company history only)
     */
    public static function write(PDO $conn, ActorContext $ctx, string $entity, int $ownerId, array $defs, array $values, string $source = 'app'): void
    {
        if (!$values) return;
        $before = self::values($conn, $entity, $ownerId, $defs);
        TypedFields::writeValues($conn, self::valueSchema($entity), $ownerId, $defs, $values);
        $after = self::values($conn, $entity, $ownerId, $defs);
        $ins = match ($entity) {
            'company'    => $conn->prepare("INSERT INTO company_audit (tenant_id, analyst_id, field_name, old_value, new_value, source, created_datetime) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"),
            'consumable' => $conn->prepare("INSERT INTO consumable_audit (item_id, analyst_id, field_name, old_value, new_value, source, created_datetime) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"),
            default      => $conn->prepare("INSERT INTO user_audit (user_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"),
        };
        foreach (array_keys($values) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old === $new) continue;
            $d = $defs[$key];
            $args = [$ownerId, $ctx->actorId > 0 ? $ctx->actorId : null, mb_substr($d['label'], 0, 100),
                     self::auditText($d, $old), self::auditText($d, $new)];
            if ($entity !== 'person') $args[] = in_array($source, ['api', 'ticket'], true) ? $source : 'app';
            $ins->execute($args);
        }
    }

    /** The answer as a person reads it. Null stays null - "not answered". */
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

    private static function auditText(array $d, $v): ?string
    {
        $s = self::displayValue($d, $v);
        return $s === null ? null : mb_substr($s, 0, 1000);
    }

    // ====================================================================
    //  Settings: designing the fields (System -> Custom fields, administrators)
    // ====================================================================

    /**
     * Every field of a kind, for the settings screen: with options, how many
     * records have answered it, and which company it belongs to (people only).
     *
     * @param ?int $tenantId  people only: null = every field (shared and every company's), else the shared ones and that company's
     */
    public static function listForSettings(PDO $conn, string $entity, ?int $tenantId = null): array
    {
        if (!self::schemaReady($conn, $entity)) return [];
        $c = self::cfg($entity);
        $params = [];
        $where = "f.is_deleted = 0";
        if ($c['per_tenant'] && $tenantId !== null) {
            $where .= " AND (f.tenant_id IS NULL OR f.tenant_id = ?)";
            $params[] = $tenantId;
        }
        $typed = !$c['per_tenant'] && !empty($c['scope_table']);
        $stmt = $conn->prepare(
            "SELECT f.*, (SELECT COUNT(*) FROM `{$c['values']}` v WHERE v.field_id = f.id) AS answered"
            . ($c['per_tenant'] ? ", tn.name AS company_name" : "")
            . ($typed ? ", st.name AS scope_name" : "")
            . " FROM `{$c['defs']}` f"
            . ($c['per_tenant'] ? " LEFT JOIN tenants tn ON tn.id = f.tenant_id" : "")
            . ($typed ? " LEFT JOIN `{$c['scope_table']}` st ON st.id = f.`{$c['scope_col']}`" : "")
            . " WHERE $where ORDER BY f.display_order, f.label, f.id"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $options = self::optionsFor($conn, $entity, array_map(fn($r) => (int)$r['id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $d = self::toDef($r);
            $d['options'] = $options[(int)$r['id']] ?? [];
            $out[] = self::present($d) + [
                'id'           => (int)$r['id'],
                'answered'     => (int)$r['answered'],
                'tenant_id'    => $d['tenant_id'],
                'company_name' => $r['company_name'] ?? null,
                'scope_id'     => $d['scope_id'],
                'scope_name'   => $r['scope_name'] ?? null,   // consumables: the type's name; null = every type
            ];
        }
        return $out;
    }

    /**
     * Create or change a field.
     *
     * @param array $in  id (0 = new), label, field_type, config {multiline, decimals, unit, date_mode},
     *     help_text, options (dropdown choices), is_required, tenant_id (people only: null = every company)
     * @return array {id, created, key}
     */
    public static function saveField(PDO $conn, ActorContext $ctx, string $entity, array $in): array
    {
        if (!self::schemaReady($conn, $entity)) {
            throw new ServiceError('conflict', 'schema_not_ready', 'Run System -> Database Verification before adding custom fields.');
        }
        $c = self::cfg($entity);
        $id    = (int)($in['id'] ?? 0);
        $label = trim((string)($in['label'] ?? ''));
        if ($label === '') throw new ServiceError('validation', 'missing_field', 'Give the field a name.');
        if (mb_strlen($label) > 150) throw new ServiceError('validation', 'invalid_field', 'The name can be at most 150 characters.');
        $type = trim((string)($in['field_type'] ?? 'text'));
        if (!in_array($type, self::TYPES, true)) throw new ServiceError('validation', 'invalid_field', 'Choose what kind of field this is.');
        $config = self::cleanConfig($type, is_array($in['config'] ?? null) ? $in['config'] : []);
        $help = trim((string)($in['help_text'] ?? ''));
        $help = $help === '' ? null : mb_substr($help, 0, 500);
        $options = [];
        if ($type === 'dropdown') {
            foreach ((array)($in['options'] ?? []) as $o) {
                $o = trim((string)$o);
                if ($o !== '' && !in_array($o, $options, true)) $options[] = mb_substr($o, 0, 255);
            }
            if (!$options) throw new ServiceError('validation', 'missing_field', 'A dropdown needs at least one option.');
        }
        $required = !empty($in['is_required']) ? 1 : 0;
        $tenantId = null;
        if ($c['per_tenant'] && isset($in['tenant_id']) && $in['tenant_id'] !== '' && $in['tenant_id'] !== null) {
            $tenantId = (int)$in['tenant_id'];
            if (!getTenantById($conn, $tenantId)) throw new ServiceError('validation', 'invalid_field', 'Unknown company.');
        }
        $scopeId = null;
        if (!$c['per_tenant'] && !empty($c['scope_table']) && isset($in['scope_id']) && $in['scope_id'] !== '' && $in['scope_id'] !== null) {
            $scopeId = (int)$in['scope_id'];
            $chk = $conn->prepare("SELECT 1 FROM `{$c['scope_table']}` WHERE id = ?");
            $chk->execute([$scopeId]);
            if (!$chk->fetchColumn()) throw new ServiceError('validation', 'invalid_field', 'Unknown type.');
        }

        $owns = !$conn->inTransaction();
        if ($owns) $conn->beginTransaction();
        try {
            if ($id > 0) {
                $cur = self::loadField($conn, $entity, $id);
                if ($type !== $cur['field_type'] && self::answered($conn, $entity, $id) > 0) {
                    throw new ServiceError('conflict', 'type_locked',
                        "'{$cur['label']}' already has answers, so its kind can no longer be changed. Delete it and add a new field instead.");
                }
                // The company a people field belongs to is fixed once made: moving it would
                // hand one company's answers to another's screens.
                $conn->prepare("UPDATE `{$c['defs']}` SET label = ?, field_type = ?, config = ?, help_text = ?, is_required = ? WHERE id = ?")
                     ->execute([$label, $type, $config ? json_encode($config) : null, $help, $required, $id]);
                $key = (string)$cur['field_key'];
                $created = false;
            } else {
                $key = self::freeKey($conn, $entity, $label);
                $order = (int)$conn->query("SELECT COALESCE(MAX(display_order), 0) + 1 FROM `{$c['defs']}`")->fetchColumn();
                if ($c['per_tenant'] || $c['scope_col'] !== null) {
                    $col = $c['scope_col'];
                    $conn->prepare("INSERT INTO `{$c['defs']}` (field_key, label, field_type, config, help_text, is_required, display_order, `$col`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                         ->execute([$key, $label, $type, $config ? json_encode($config) : null, $help, $required, $order, $c['per_tenant'] ? $tenantId : $scopeId]);
                } else {
                    $conn->prepare("INSERT INTO `{$c['defs']}` (field_key, label, field_type, config, help_text, is_required, display_order) VALUES (?, ?, ?, ?, ?, ?, ?)")
                         ->execute([$key, $label, $type, $config ? json_encode($config) : null, $help, $required, $order]);
                }
                $id = (int)$conn->lastInsertId();
                $created = true;
            }
            $conn->prepare("DELETE FROM `{$c['options']}` WHERE field_id = ?")->execute([$id]);
            $insO = $conn->prepare("INSERT INTO `{$c['options']}` (field_id, option_value, display_order) VALUES (?, ?, ?)");
            foreach ($options as $i => $o) $insO->execute([$id, $o, $i]);
            if ($owns) $conn->commit();
        } catch (Throwable $e) {
            if ($owns && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        return ['id' => $id, 'created' => $created, 'key' => $key];
    }

    /** Delete a field: SOFT, always - the answers stay in the database, unseen. */
    public static function deleteField(PDO $conn, ActorContext $ctx, string $entity, int $id): void
    {
        $c = self::cfg($entity);
        self::loadField($conn, $entity, $id);
        $conn->prepare("UPDATE `{$c['defs']}` SET is_deleted = 1 WHERE id = ?")->execute([$id]);
    }

    /** Put fields in the order given. */
    public static function reorder(PDO $conn, ActorContext $ctx, string $entity, array $ids): void
    {
        $c = self::cfg($entity);
        $upd = $conn->prepare("UPDATE `{$c['defs']}` SET display_order = ? WHERE id = ? AND is_deleted = 0");
        foreach (array_values(array_map('intval', $ids)) as $i => $fid) $upd->execute([$i + 1, $fid]);
    }

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

    private static function loadField(PDO $conn, string $entity, int $id): array
    {
        $c = self::cfg($entity);
        $stmt = $conn->prepare("SELECT * FROM `{$c['defs']}` WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'That field no longer exists.');
        return $row;
    }

    private static function answered(PDO $conn, string $entity, int $id): int
    {
        $c = self::cfg($entity);
        $stmt = $conn->prepare("SELECT COUNT(*) FROM `{$c['values']}` WHERE field_id = ?");
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * A key no other field of this kind uses - across every company, deleted
     * fields included, so the API's keys are never ambiguous or reused.
     */
    private static function freeKey(PDO $conn, string $entity, string $label): string
    {
        $c = self::cfg($entity);
        $base = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
        if ($base === '' || ctype_digit($base[0])) $base = 'f_' . $base;
        $base = substr($base, 0, 90);
        $chk = $conn->prepare("SELECT 1 FROM `{$c['defs']}` WHERE field_key = ? LIMIT 1");
        for ($n = 1; ; $n++) {
            $key = $n === 1 ? $base : $base . '_' . $n;
            $chk->execute([$key]);
            if ($chk->fetchColumn() === false) return $key;
        }
    }
}
