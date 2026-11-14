<?php
/**
 * TypedFields — the shared engine for user-defined, strongly-typed fields.
 *
 * One definition somewhere says "this thing has a field called Warranty Expiry,
 * and it is a date". One value row holds the answer in a column matching its
 * type. This file owns everything between those two facts: what the types are,
 * how a raw input becomes a stored value, what counts as valid, and how the
 * required rule works.
 *
 * WHAT THIS FILE DOES NOT OWN
 * ---------------------------
 * - **Which fields a thing has.** Each module answers that with its own query
 *   and hands the result in as canonical definitions (see defFrom* below). The
 *   CMDB resolves them from a class; Assets will resolve them from the field
 *   sets attached to a type plus any attached to the individual asset. Trying to
 *   generalise that query would force one attachment model on both.
 * - **Authorisation.** Multi-tenancy gates, module access and actor scope stay
 *   in the calling service. A security rule living in a generic layer degrades
 *   silently, and not toward safety. CmdbService::assertObjectRefsInCompany()
 *   is the live example: this file checks a reference EXISTS and points at the
 *   right kind of thing; only CmdbService decides whether the actor may see it.
 * - **Reading values for display.** Not yet — see the note on readValues in
 *   docs/design/flexible-asset-fields.md §4.4. The CMDB's read path joins for
 *   reference labels under a tenancy filter, which is module business.
 *
 * SHAPE OF A DEFINITION (what callers pass in)
 * --------------------------------------------
 *   [
 *     'id'         => int,      // the definition row's id
 *     'key'        => string,   // stable machine key (property_key / field_key)
 *     'label'      => string,   // display name, used in error messages
 *     'type'       => string,   // one of self::TYPES
 *     'required'   => bool,
 *     'config'     => array,    // per-type settings; [] where a module has none
 *     'ref_kind'   => ?string,  // 'ref' only: which registry entry
 *     'ref_target' => ?int,     // 'ref' only: optional narrowing (e.g. a class id)
 *   ]
 *
 * SHAPE OF A SCHEMA (where the values live)
 * -----------------------------------------
 *   [
 *     'value_table'  => 'cmdb_object_properties',
 *     'owner_column' => 'object_id',
 *     'def_column'   => 'property_id',
 *     'columns'      => [ storage class => column name ],   // see STORAGE
 *     'options'      => callable(PDO, int $defId): string[],// dropdown values
 *     'unknown_hint' => string,                             // appended to the
 *                                                           // unknown-key error
 *   ]
 *
 * @see docs/design/flexible-asset-fields.md — the design and the reasoning.
 */

require_once __DIR__ . '/service_context.php';

final class TypedFields
{
    /**
     * The field types.
     *
     * 🔑 A presentational variant is a MODE inside `config`; a different storage
     * class is a TYPE. A field's type cannot be changed once values exist, so
     * anything someone might plausibly want to flip later must be a mode —
     * text/textarea is `text` + config.multiline, date/time/datetime is `date` +
     * config.date_mode. FormsService::DATE_MODES learned this first and wrote
     * down why: picking the wrong one otherwise means deleting the field and
     * stranding every value already given to it.
     */
    const TYPES = ['text', 'number', 'date', 'boolean', 'dropdown', 'url', 'email', 'ref'];

    /**
     * Type -> storage class. The schema's `columns` map turns a storage class
     * into an actual column, so two modules may name their columns differently
     * (the CMDB has `value_object_id` where assets will have `value_ref_id`)
     * without the engine caring.
     */
    const STORAGE = [
        'text'     => 'text',
        'dropdown' => 'text',
        'url'      => 'text',
        'email'    => 'text',
        'number'   => 'number',
        'date'     => 'date',
        'boolean'  => 'boolean',
        'ref'      => 'ref',
    ];

    /** @var array<string, array> kind => handler. See registerRefKind(). */
    private static array $refKinds = [];

    // ---------------------------------------------------------------- types --

    /**
     * The storage class a field type is kept in (text, number, date, boolean or ref), or '' for an unknown type.
     *
     * @param string $type  a field type, one of TYPES
     */
    public static function storageClass(string $type): string
    {
        return self::STORAGE[$type] ?? '';
    }

    /**
     * The schema `columns` slot a ref definition is stored in: 'ref' unless the
     * definition names another with `ref_slot` (GH #312 - the CMDB keeps CI links
     * and links to other records in different columns, because everything that
     * reads its CI-link column assumes the id there is a CI).
     *
     * @param array $def  a canonical ref definition
     */
    public static function refSlot(array $def): string
    {
        $slot = $def['ref_slot'] ?? 'ref';
        return (is_string($slot) && $slot !== '') ? $slot : 'ref';
    }

    /**
     * True when $type is one of the field types in TYPES.
     *
     * @param string $type  the field type to check
     */
    public static function isKnownType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    // ----------------------------------------------------- reference kinds --

    /**
     * Register a kind of thing a `ref` field may point at.
     *
     * Same shape as the entity registry in includes/documents.php, and for the
     * same reason: adding "a headset can point at the person who has it" should
     * be a registry entry, not surgery on a switch statement.
     *
     * A handler provides:
     *   'label'    => string                       — for error messages
     *   'exists'   => callable(PDO, int): ?int     — returns the row's "target"
     *                                                discriminator (e.g. class
     *                                                id), or null if no such row
     *   'target_label' => string                   — what the discriminator is
     *                                                called, for error messages
     *
     * ⚠️ A handler answers "is this a real row of the right kind?" and nothing
     * more. It must never answer "may this actor see it" — that is the calling
     * service's job, every time.
     *
     * @param string $kind  the name a definition's ref_kind uses, e.g. 'cmdb_object', 'user', 'asset'
     * @param array $handler  label, target_label, exists and (optionally) labels, as described above
     */
    public static function registerRefKind(string $kind, array $handler): void
    {
        self::$refKinds[$kind] = $handler;
    }

    /**
     * The handler registered for a ref kind by registerRefKind(), or null when none is.
     *
     * @param string $kind  the ref_kind name
     */
    public static function refKind(string $kind): ?array
    {
        return self::$refKinds[$kind] ?? null;
    }

    // ------------------------------------------------------------ required --

    /**
     * Required-field enforcement, with the create/update asymmetry the CMDB
     * settled on: required is checked on create, and on update only for fields
     * actually being written.
     *
     * 🔑 Without the asymmetry, ticking "required" on an existing field makes
     * every older record unsaveable — including by someone editing an unrelated
     * field. Pre-existing violations are surfaced by an audit view instead.
     *
     * @param array $defs Canonical definitions, keyed by field key.
     * @param array $values  [field key => raw value] being written; a key present with null, '' or [] counts as missing
     * @param bool $isCreate  true on create, when a required field absent from $values is also an error
     */
    public static function checkRequired(array $defs, array $values, bool $isCreate): void
    {
        foreach ($defs as $key => $def) {
            if (empty($def['required'])) {
                continue;
            }
            if (array_key_exists($key, $values)) {
                $v = $values[$key];
                if ($v === null || $v === '' || (is_array($v) && empty($v))) {
                    throw new ServiceError('validation', 'missing_field', "Required property missing: {$def['label']}");
                }
            } elseif ($isCreate) {
                throw new ServiceError('validation', 'missing_field', "Required property missing: {$def['label']}");
            }
        }
    }

    // --------------------------------------------------------------- write --

    /**
     * Validate and store values for one owner.
     *
     * Delete-then-insert per field, so an absent or empty value CLEARS it:
     * 🔑 no row means "not set", which is what lets ten televisions carry three
     * smart-TV fields on three of them and nothing at all on the other seven.
     *
     * @param array $schema  where the values live (see SHAPE OF A SCHEMA)
     * @param int $ownerId  the row the values belong to (e.g. the CMDB object or asset id)
     * @param array $defs   Canonical definitions, keyed by field key.
     * @param array $values [field key => raw value]. Unknown keys throw.
     */
    public static function writeValues(PDO $conn, array $schema, int $ownerId, array $defs, array $values): void
    {
        $cols     = $schema['columns'];
        $valTable = $schema['value_table'];
        $ownerCol = $schema['owner_column'];
        $defCol   = $schema['def_column'];

        $ordered  = array_values($cols);              // stable column order
        $colList  = implode(', ', $ordered);
        $placeholders = implode(', ', array_fill(0, count($ordered), '?'));

        $del = $conn->prepare("DELETE FROM {$valTable} WHERE {$ownerCol} = ? AND {$defCol} = ?");
        $ins = $conn->prepare(
            "INSERT INTO {$valTable} ({$ownerCol}, {$defCol}, {$colList}) VALUES (?, ?, {$placeholders})"
        );

        foreach ($values as $key => $rawValue) {
            if (!isset($defs[$key])) {
                $hint = $schema['unknown_hint'] ?? '';
                throw new ServiceError('validation', 'invalid_field',
                    trim("Unknown property '{$key}' for this class. {$hint}"));
            }
            $def = $defs[$key];
            $defId = (int)$def['id'];

            $del->execute([$ownerId, $defId]);

            if ($rawValue === null || $rawValue === '') {
                continue;   // cleared
            }

            $stored = self::coerce($conn, $schema, $def, $rawValue, $ownerId);
            if ($stored === null) {
                continue;   // the coercer decided this clears too (see 'ref')
            }

            $row = [$ownerId, $defId];
            foreach (array_keys($cols) as $storageClass) {
                $row[] = $stored[$storageClass] ?? null;
            }
            $ins->execute($row);
        }
    }

    /**
     * Turn one raw input into [storage class => value], or null to clear.
     *
     * Every message here is user-facing and deliberately unchanged from the
     * CMDB original — they are what the REST API has always returned.
     *
     * @param array $schema  where the values live (see SHAPE OF A SCHEMA); options and self_kind are read here
     * @param array $def  one canonical definition (see SHAPE OF A DEFINITION)
     * @param mixed $rawValue  the value as submitted, not yet trusted; never null or '' (those clear before this)
     * @param int $ownerId  the row the value belongs to, so a ref cannot point at itself
     */
    public static function coerce(PDO $conn, array $schema, array $def, $rawValue, int $ownerId): ?array
    {
        $out = ['text' => null, 'number' => null, 'date' => null, 'boolean' => null, 'ref' => null];

        switch ($def['type']) {
            case 'text':
                $out['text'] = (string)$rawValue;
                return $out;

            case 'url':
            case 'email':
                // Stored as text; format validation belongs with the field's
                // config and arrives with the asset catalogue in slice 2.
                $out['text'] = (string)$rawValue;
                return $out;

            case 'dropdown':
                $out['text'] = (string)$rawValue;
                $allowed = isset($schema['options'])
                    ? ($schema['options'])($conn, (int)$def['id'])
                    : [];
                if ($allowed && !in_array($out['text'], $allowed, true)) {
                    throw new ServiceError('validation', 'invalid_field',
                        "Property '{$def['label']}' must be one of: " . implode(', ', $allowed));
                }
                return $out;

            case 'number':
                if (!is_numeric($rawValue)) {
                    throw new ServiceError('validation', 'invalid_field',
                        "Property '{$def['label']}' expects a number.");
                }
                $out['number'] = (float)$rawValue;
                return $out;

            case 'date':
                $out['date'] = self::parseDate((string)$rawValue, $def['key']);
                return $out;

            case 'boolean':
                $out['boolean'] = ($rawValue === true || $rawValue === 1 || $rawValue === '1' || $rawValue === 'true') ? 1 : 0;
                return $out;

            case 'ref':
                $refId = (int)$rawValue;
                if ($refId <= 0) {
                    return null;    // treated as cleared, as object_ref always was
                }
                if ($refId === $ownerId && ($def['ref_kind'] ?? '') === ($schema['self_kind'] ?? null)) {
                    throw new ServiceError('validation', 'invalid_field',
                        "Property '{$def['label']}' can't reference its own object.");
                }
                self::validateRef($conn, $def, $refId);
                // A definition may keep its reference in another slot of the schema's
                // `columns` (ref_slot) - the CMDB keeps CI links in value_object_id
                // and links to people, teams and so on in value_ref_id (GH #312).
                $out[self::refSlot($def)] = $refId;
                return $out;

            default:
                throw new ServiceError('validation', 'invalid_field',
                    "Unknown property type: {$def['type']}");
        }
    }

    /**
     * Existence + target check for a `ref` value, via the registry.
     *
     * @param array $def  the ref field's canonical definition (ref_kind, ref_target, label)
     * @param int $refId  the id the value points at, above 0
     */
    private static function validateRef(PDO $conn, array $def, int $refId): void
    {
        $kind    = $def['ref_kind'] ?? '';
        $handler = self::refKind($kind);
        if ($handler === null) {
            throw new ServiceError('validation', 'invalid_field',
                "Property '{$def['label']}' has an unknown reference kind: {$kind}");
        }

        $actualTarget = ($handler['exists'])($conn, $refId);
        if ($actualTarget === false) {
            throw new ServiceError('validation', 'invalid_field',
                "Property '{$def['label']}' references an object that doesn't exist.");
        }

        $wanted = $def['ref_target'] ?? null;
        if ($wanted !== null && (int)$actualTarget !== (int)$wanted) {
            throw new ServiceError('validation', 'invalid_field',
                "Property '{$def['label']}' can only reference objects of its target class.");
        }
    }

    // ---------------------------------------------------------------- read --

    /**
     * Values for MANY owners in ONE query, pivoted to [ownerId => [key => value]].
     *
     * ⚠️ There is no per-owner variant on purpose. A list of 500 assets must cost
     * one query, not 500 — see docs/design/flexible-asset-fields.md §4.4. If you
     * find yourself calling this in a loop, collect the ids and call it once.
     *
     * 🔑 Owners with no values simply do not appear in the result, and fields
     * with no value do not appear in an owner's map. Absent means NOT SET, and
     * callers must keep it distinguishable from "no" / zero / empty — never
     * `?? false` a boolean field into existence. See §4.5.
     *
     * Reference fields come back as the raw target id. Turning that into a NAME
     * is the module's job, deliberately: the CMDB filters those joins by company,
     * and a generic labeller would happily read across one.
     *
     * @param array $schema  where the values live (see SHAPE OF A SCHEMA)
     * @param array $defs      Canonical definitions, keyed by field key.
     * @param array $ownerIds  Owner row ids.
     * @return array [ownerId => [field key => value]]
     */
    public static function readValues(PDO $conn, array $schema, array $defs, array $ownerIds): array
    {
        $ownerIds = array_values(array_unique(array_map('intval', $ownerIds)));
        if (!$ownerIds || !$defs) {
            return [];
        }

        $byId = [];
        foreach ($defs as $def) {
            $byId[(int)$def['id']] = $def;
        }

        $cols     = $schema['columns'];
        $valTable = $schema['value_table'];
        $ownerCol = $schema['owner_column'];
        $defCol   = $schema['def_column'];
        $select   = implode(', ', array_map(fn($c) => "`{$c}`", array_values($cols)));

        $ph = implode(',', array_fill(0, count($ownerIds), '?'));
        $stmt = $conn->prepare(
            "SELECT `{$ownerCol}` AS owner_id, `{$defCol}` AS def_id, {$select}
               FROM `{$valTable}` WHERE `{$ownerCol}` IN ({$ph})"
        );
        $stmt->execute($ownerIds);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $defId = (int)$row['def_id'];
            if (!isset($byId[$defId])) {
                continue;   // a value for a field no longer attached here
            }
            $def   = $byId[$defId];
            $class = $def['type'] === 'ref' ? self::refSlot($def) : self::storageClass($def['type']);
            $raw   = $row[$cols[$class] ?? ''] ?? null;
            if ($raw === null) {
                continue;
            }
            $out[(int)$row['owner_id']][$def['key']] = self::castOut($def['type'], $raw);
        }
        return $out;
    }

    /**
     * Stored string -> the PHP type a caller expects.
     *
     * @param string $type  the field type
     * @param mixed $raw  the stored column value, not null
     */
    private static function castOut(string $type, $raw)
    {
        switch ($type) {
            case 'number':  return (float)$raw;
            case 'boolean': return ((int)$raw === 1);
            case 'ref':     return (int)$raw;
            default:        return (string)$raw;
        }
    }

    /**
     * Display labels for reference values, by kind.
     *
     * 🔒 Opt-in and caller-driven, NOT folded into readValues(): resolving a
     * reference to a name reads another table, and whether the caller may see
     * those rows is the caller's question. Pass only ids you have already
     * decided the actor may see.
     *
     * @param string $kind  the ref_kind name; an unregistered kind, or one with no labels callable, gives []
     * @param array $ids  the referenced ids, already cleared as visible to the actor
     * @return array [id => label] — ids with no row are simply absent.
     */
    public static function refLabels(PDO $conn, string $kind, array $ids): array
    {
        $handler = self::refKind($kind);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$handler || !$ids || empty($handler['labels'])) {
            return [];
        }
        return ($handler['labels'])($conn, $ids);
    }

    // ---------------------------------------------------------------- dates --

    /**
     * Parse a date/time to 'Y-m-d H:i:s' UTC (throwing twin of apiParseDate;
     * 400 on bad input).
     *
     * @param string $value  the submitted date or date-time, anything DateTimeImmutable reads; no zone means UTC
     * @param string $field  the field key, named in the error message
     */
    public static function parseDate(string $value, string $field): string
    {
        $v = trim($value);
        try {
            $dt = new DateTimeImmutable($v, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception) {
            throw new ServiceError('bad_request', 'invalid_parameter',
                "'{$field}' is not a valid date/time. Use ISO 8601, e.g. 2026-07-02T09:00:00Z.");
        }
    }
}

// ---------------------------------------------------------------------------
// Reference kinds
//
// Registered here rather than by each module, so that "what can a field point
// at?" is answerable by reading one file — the same choice includes/documents.php
// made for its 13 entity types.
// ---------------------------------------------------------------------------

/**
 * A CMDB configuration item. The `target` discriminator is its class id, which
 * is what cmdb_class_properties.target_class_id narrows against.
 *
 * ⚠️ Existence only. Whether the actor may SEE this CI, and whether it is in the
 * same company as the item being written, is decided by
 * CmdbService::assertObjectRefsInCompany() — deliberately not here.
 */
TypedFields::registerRefKind('cmdb_object', [
    'label'        => 'configuration item',
    'target_label' => 'class',
    'exists'       => function (PDO $conn, int $id) {
        $stmt = $conn->prepare("SELECT class_id FROM cmdb_objects WHERE id = ?");
        $stmt->execute([$id]);
        $classId = $stmt->fetchColumn();
        return ($classId === false) ? false : (int)$classId;
    },
    'labels'       => function (PDO $conn, array $ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT id, name FROM cmdb_objects WHERE id IN ({$ph})");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    },
]);

/**
 * A person in the directory — "who has this headset", "who owns this TV".
 *
 * No target discriminator: a user is a user. `exists` therefore returns 0 (a
 * real "found, nothing to narrow on") rather than the id, so that a field with
 * no ref_target passes and one with a ref_target set can never match — there is
 * nothing sensible to narrow a user by.
 */
TypedFields::registerRefKind('user', [
    'label'        => 'person',
    'target_label' => '',
    'exists'       => function (PDO $conn, int $id) {
        $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() === false ? false : 0;
    },
    'labels'       => function (PDO $conn, array $ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "SELECT id, COALESCE(NULLIF(display_name, ''), email) FROM users WHERE id IN ({$ph})"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    },
]);

/**
 * Another asset — "the dock this monitor is plugged into". The discriminator is
 * the asset's TYPE, so a field can be narrowed to "must point at a Docking
 * Station" the same way a CMDB property narrows to a class.
 */
TypedFields::registerRefKind('asset', [
    'label'        => 'asset',
    'target_label' => 'asset type',
    'exists'       => function (PDO $conn, int $id) {
        $stmt = $conn->prepare("SELECT asset_type_id FROM assets WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return ($row === false) ? false : (int)($row[0] ?? 0);
    },
    'labels'       => function (PDO $conn, array $ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "SELECT id, COALESCE(NULLIF(hostname, ''), CONCAT('#', id)) FROM assets WHERE id IN ({$ph})"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    },
]);
