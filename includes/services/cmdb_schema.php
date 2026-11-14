<?php
/**
 * CmdbSchemaService - the write rules for the CMDB's SCHEMA: classes, property
 * definitions and relationship types (GH #313 slice 2).
 *
 * These used to live inside api/cmdb/save_class.php, save_class_property.php and
 * save_relationship_type.php. They moved here because they now have a second
 * caller: the schema plan engine (includes/cmdb/schema_plan.php - Starter classes
 * today, the CMDB AI assistant later), which creates many items in ONE transaction.
 * The endpoints are thin adapters whose responses did not change.
 *
 * Conventions (as CmdbService): each method takes a PDO, an ActorContext and the
 * canonical input, validates, writes, returns ids, and throws ServiceError. It
 * never emits HTTP. Permission (Cap::CMDB_CLASSES / Cap::CMDB_RELATIONSHIP_TYPES)
 * is the CALLER's job - the endpoints check the session, the plan engine checks
 * each line - because the schema is install-wide admin config with no company.
 *
 * $opts:
 *   emit => true   wf_emit() once the write is done. A bulk caller inside its own
 *                  transaction passes false and emits the returned 'events' after
 *                  ITS commit - a workflow must never fire for a row that rolls back.
 *
 * Transactions: a method that needs one (saveProperty: the row + its options)
 * opens it only when the caller has not, so it nests inside a bulk transaction.
 *
 * TRAP: the error messages are the endpoints' old ones, byte for byte, and in the
 *   same order - the settings screen shows them as they come.
 *
 * @wiki CMDB-Developer-Guide
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../cmdb_record_refs.php';   // GH #312: the record property types
require_once __DIR__ . '/../cmdb_impact.php';        // impact direction vocabulary

/**
 * Every CMDB property type, in the order the settings screen offers them: the six
 * basic ones and then the record types (person, analyst, team, location, company,
 * supplier, contract - cmdbRecordTypes(), the one list). The one list for saving a
 * property, the AI wizard and the schema plan engine.
 *
 * @return string[]
 */
function cmdbPropertyTypes(): array
{
    return array_merge(['text', 'number', 'date', 'boolean', 'dropdown', 'object_ref'], array_keys(cmdbRecordTypes()));
}

/**
 * Where a property type keeps its value: a type change that moves to another
 * place - or keeps the id but changes what it names (a person id read as a
 * team) - would strand or misread every value already stored (GH #312).
 *
 * @param string $type  a property type
 */
function cmdbPropertyValueHome(string $type): string
{
    if (cmdbIsRecordType($type)) return 'record:' . $type;
    switch ($type) {
        case 'number': return 'number';
        case 'date': return 'date';
        case 'boolean': return 'boolean';
        case 'object_ref': return 'object';
        default: return 'text';   // text, dropdown
    }
}

/**
 * Turns a name into a lower-case key of letters, digits and underscores, or
 * $fallback when nothing is left ("Web Server" -> "web_server").
 *
 * @param string $name      the name as typed
 * @param string $fallback  'class' or 'property'
 */
function cmdbSchemaSlug(string $name, string $fallback): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug !== '' ? $slug : $fallback;
}

class CmdbSchemaService
{
    /**
     * Emit now, or hand the event back for a bulk caller to emit after its commit.
     *
     * @param array $ev    [entity, action, id, name]
     * @param array $opts  the caller's options (emit)
     */
    private static function event(array $ev, array $opts): array
    {
        if ($opts['emit'] ?? true) {
            wf_emit($ev[0], $ev[1], (int)$ev[2], $ev[3]);
        }
        return [$ev];
    }

    // ======================================================================
    //  Classes
    // ======================================================================

    /**
     * Create a class (any id in $in is ignored). Returns ['id','class_key','created','events'].
     *
     * @param array $in    name, class_key (blank = from the name), description, icon_id, display_order, is_active
     * @param array $opts  emit
     */
    public static function createClass(PDO $conn, ActorContext $ctx, array $in, array $opts = []): array
    {
        unset($in['id']);
        return self::saveClass($conn, $ctx, $in, $opts);
    }

    /**
     * Create (no id) or update (id) a class. On create a blank key comes from the
     * name and a key already in use gets _2, _3 ...; on update a key another class
     * uses is refused. Returns ['id','class_key','created','events'].
     *
     * @param array $in    id, name, class_key, description, icon_id, display_order, is_active
     * @param array $opts  emit
     */
    public static function saveClass(PDO $conn, ActorContext $ctx, array $in, array $opts = []): array
    {
        $id = isset($in['id']) && $in['id'] !== '' && $in['id'] !== null ? (int)$in['id'] : null;
        $name = trim((string)($in['name'] ?? ''));
        $key = trim((string)($in['class_key'] ?? ''));
        $description = trim((string)($in['description'] ?? ''));
        $iconId = isset($in['icon_id']) && $in['icon_id'] !== '' && $in['icon_id'] !== null ? (int)$in['icon_id'] : null;
        $displayOrder = isset($in['display_order']) ? (int)$in['display_order'] : 0;
        $isActive = !empty($in['is_active']) ? 1 : 0;

        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Name is required');
        if (mb_strlen($name) > 150) throw new ServiceError('validation', 'invalid_field', 'Name too long (max 150 chars)');

        if ($key === '') {
            $key = cmdbSchemaSlug($name, 'class');
        } elseif (!preg_match('/^[a-z0-9_]+$/', $key)) {
            throw new ServiceError('validation', 'invalid_field', 'Key may only contain lowercase letters, numbers, and underscores');
        }
        if (mb_strlen($key) > 100) throw new ServiceError('validation', 'invalid_field', 'Key too long (max 100 chars)');

        if ($id === null) {
            // Auto-resolve key collisions on create by appending _N
            $base = $key; $n = 2;
            $check = $conn->prepare("SELECT id FROM cmdb_classes WHERE class_key = ?");
            while (true) {
                $check->execute([$key]);
                if (!$check->fetch()) break;
                $key = $base . '_' . $n++;
                if ($n > 50) throw new ServiceError('conflict', 'conflict', 'Could not generate a unique key — please supply one explicitly');
            }
            $conn->prepare(
                "INSERT INTO cmdb_classes (class_key, name, description, icon_id, display_order, is_active, created_datetime, updated_datetime)
                 VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([$key, $name, $description ?: null, $iconId, $displayOrder, $isActive]);
            $newId = (int)$conn->lastInsertId();
            return ['id' => $newId, 'class_key' => $key, 'created' => true,
                    'events' => self::event(['cmdb_class', 'created', $newId, $name], $opts)];
        }

        // Refuse a key change that would collide with another class
        $check = $conn->prepare("SELECT id FROM cmdb_classes WHERE class_key = ? AND id <> ?");
        $check->execute([$key, $id]);
        if ($check->fetch()) {
            throw new ServiceError('conflict', 'conflict', 'Another class already uses that key');
        }
        $conn->prepare(
            "UPDATE cmdb_classes
                SET class_key = ?, name = ?, description = ?, icon_id = ?, display_order = ?, is_active = ?, updated_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute([$key, $name, $description ?: null, $iconId, $displayOrder, $isActive, $id]);
        return ['id' => $id, 'class_key' => $key, 'created' => false,
                'events' => self::event(['cmdb_class', 'updated', $id, $name], $opts)];
    }

    // ======================================================================
    //  Property definitions
    // ======================================================================

    /**
     * Create (no id) or update (id) a property definition, with its dropdown
     * options. Returns ['id','property_key','created','events'].
     *
     * Rules: a valid type; an object reference needs a target class (other types
     * lose any target); only an object reference can spread impact; a key already
     * used in the class gets _2, _3 ... on create and is refused on update; a type
     * change that would misread stored values is refused (GH #312); option colours
     * are #RGB or #RRGGBB. Options are replaced wholesale when sent for a dropdown,
     * and cleared when the type is not a dropdown.
     *
     * @param array $in    id, class_id, label, property_key, property_type, target_class_id,
     *                     is_required, spreads_impact, display_order, options (strings or {value, colour})
     * @param array $opts  emit
     */
    public static function saveProperty(PDO $conn, ActorContext $ctx, array $in, array $opts = []): array
    {
        $id = isset($in['id']) && $in['id'] !== '' && $in['id'] !== null ? (int)$in['id'] : null;
        $classId = isset($in['class_id']) ? (int)$in['class_id'] : 0;
        $label = trim((string)($in['label'] ?? ''));
        $key = trim((string)($in['property_key'] ?? ''));
        $type = trim((string)($in['property_type'] ?? ''));
        $targetClassId = isset($in['target_class_id']) && $in['target_class_id'] !== '' && $in['target_class_id'] !== null ? (int)$in['target_class_id'] : null;
        $isRequired = !empty($in['is_required']) ? 1 : 0;
        $spreadsImpact = !empty($in['spreads_impact']) ? 1 : 0;
        $displayOrder = isset($in['display_order']) ? (int)$in['display_order'] : 0;
        $options = $in['options'] ?? [];

        if ($classId <= 0) throw new ServiceError('validation', 'missing_field', 'class_id is required');
        if ($label === '') throw new ServiceError('validation', 'missing_field', 'Label is required');
        if (!in_array($type, cmdbPropertyTypes(), true)) throw new ServiceError('validation', 'invalid_field', 'Invalid property type');
        if ($type === 'object_ref' && $targetClassId === null) {
            throw new ServiceError('validation', 'missing_field', 'Object reference properties need a target class');
        }
        if ($type !== 'object_ref') $targetClassId = null;
        // Only an object reference can be a dependency. Clearing the flag when the
        // type changes stops a stale 1 from quietly feeding the blast radius from a
        // property that no longer points at anything.
        if ($type !== 'object_ref') $spreadsImpact = 0;

        if ($key === '') {
            $key = cmdbSchemaSlug($label, 'property');
        } elseif (!preg_match('/^[a-z0-9_]+$/', $key)) {
            throw new ServiceError('validation', 'invalid_field', 'Key may only contain lowercase letters, numbers, and underscores');
        }

        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            if ($id === null) {
                // Auto-resolve key collisions inside the same class
                $base = $key; $n = 2;
                $check = $conn->prepare("SELECT id FROM cmdb_class_properties WHERE class_id = ? AND property_key = ?");
                while (true) {
                    $check->execute([$classId, $key]);
                    if (!$check->fetch()) break;
                    $key = $base . '_' . $n++;
                    if ($n > 50) throw new ServiceError('conflict', 'conflict', 'Could not generate a unique key — please supply one explicitly');
                }
                $conn->prepare(
                    "INSERT INTO cmdb_class_properties
                         (class_id, property_key, label, property_type, target_class_id, is_required, spreads_impact, display_order, created_datetime)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
                )->execute([$classId, $key, $label, $type, $targetClassId, $isRequired, $spreadsImpact, $displayOrder]);
                $newId = (int)$conn->lastInsertId();
            } else {
                // GH #312: refuse a type change that would misread the values already
                // stored (text -> dropdown keeps its text and is fine; person -> team is not).
                $cur = $conn->prepare("SELECT property_type FROM cmdb_class_properties WHERE id = ? AND class_id = ?");
                $cur->execute([$id, $classId]);
                $oldType = (string)$cur->fetchColumn();
                if ($oldType !== '' && cmdbPropertyValueHome($oldType) !== cmdbPropertyValueHome($type)) {
                    $used = $conn->prepare("SELECT COUNT(*) FROM cmdb_object_properties WHERE property_id = ?");
                    $used->execute([$id]);
                    if ((int)$used->fetchColumn() > 0) {
                        throw new ServiceError('conflict', 'type_change_refused', 'This property already has values, so its type cannot change to one that stores them differently. Add a new property instead.');
                    }
                }

                // Refuse a key change that would collide
                $check = $conn->prepare("SELECT id FROM cmdb_class_properties WHERE class_id = ? AND property_key = ? AND id <> ?");
                $check->execute([$classId, $key, $id]);
                if ($check->fetch()) {
                    throw new ServiceError('conflict', 'conflict', 'Another property in this class already uses that key');
                }
                $conn->prepare(
                    "UPDATE cmdb_class_properties
                        SET property_key = ?, label = ?, property_type = ?, target_class_id = ?, is_required = ?, spreads_impact = ?, display_order = ?
                      WHERE id = ? AND class_id = ?"
                )->execute([$key, $label, $type, $targetClassId, $isRequired, $spreadsImpact, $displayOrder, $id, $classId]);
                $newId = $id;
            }

            // Refresh dropdown options: wipe + reinsert, inside the transaction.
            // Each option may be a plain string (the AI suggest flow, the plan engine)
            // or an object {value, colour} from the row-based editor.
            if ($type === 'dropdown' && is_array($options)) {
                $conn->prepare("DELETE FROM cmdb_class_property_options WHERE property_id = ?")->execute([$newId]);
                $insOpt = $conn->prepare("INSERT INTO cmdb_class_property_options (property_id, option_value, colour, display_order) VALUES (?, ?, ?, ?)");
                $order = 0;
                foreach ($options as $opt) {
                    if (is_array($opt)) {
                        $value  = trim((string)($opt['value']  ?? ''));
                        $colour = trim((string)($opt['colour'] ?? ''));
                    } else {
                        $value  = trim((string)$opt);
                        $colour = '';
                    }
                    if ($value === '') continue;
                    // Validate colour as #RGB or #RRGGBB; otherwise store as null
                    if ($colour !== '' && !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $colour)) {
                        throw new ServiceError('validation', 'invalid_field', 'Invalid colour for option "' . $value . '" (must be a #RGB or #RRGGBB hex)');
                    }
                    $insOpt->execute([$newId, $value, $colour !== '' ? $colour : null, $order]);
                    $order += 10;
                }
            } elseif ($type !== 'dropdown') {
                // Switching away from dropdown - clear any leftover options.
                $conn->prepare("DELETE FROM cmdb_class_property_options WHERE property_id = ?")->execute([$newId]);
            }

            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }

        return ['id' => $newId, 'property_key' => $key, 'created' => $id === null,
                'events' => self::event(['cmdb_property', $id === null ? 'created' : 'updated', $newId, $label], $opts)];
    }

    // ======================================================================
    //  Relationship types
    // ======================================================================

    /**
     * Create (no id) or update (id) a relationship type. Returns ['id','created','events'].
     *
     * Rules: verb and inverse verb required, at most 100 characters; no other type
     * may use the verb; an unknown impact direction is stored as 'none' (an unknown
     * value would silently drop the edge from the blast radius); a redundancy rule
     * (GH #199 Part C) is only changed when sent, and needs an impact direction.
     *
     * @param array $in    id, verb, inverse_verb, description, impact_direction, redundancy, display_order, is_active
     * @param array $opts  emit
     */
    public static function saveRelationshipType(PDO $conn, ActorContext $ctx, array $in, array $opts = []): array
    {
        $id = isset($in['id']) && $in['id'] !== '' && $in['id'] !== null ? (int)$in['id'] : null;
        $verb = trim((string)($in['verb'] ?? ''));
        $inverse = trim((string)($in['inverse_verb'] ?? ''));
        $description = trim((string)($in['description'] ?? ''));
        $displayOrder = isset($in['display_order']) ? (int)$in['display_order'] : 0;
        $isActive = !empty($in['is_active']) ? 1 : 0;

        $impactDirection = (string)($in['impact_direction'] ?? CMDB_IMPACT_NONE);
        if (!in_array($impactDirection, cmdbImpactDirections(), true)) {
            $impactDirection = CMDB_IMPACT_NONE;
        }

        // none | membership | placement. Only when sent, so an older caller cannot
        // reset it. A redundancy rule shapes how a failure travels, so it needs one to.
        $redundancy = null;
        if (array_key_exists('redundancy', $in)) {
            $redundancy = (string)$in['redundancy'];
            if (!in_array($redundancy, ['none', 'membership', 'placement'], true)) $redundancy = 'none';
            if ($redundancy !== 'none' && $impactDirection === CMDB_IMPACT_NONE) {
                throw new ServiceError('validation', 'invalid_field', 'A redundancy rule needs the relationship to spread impact - choose which way first.');
            }
        }

        if ($verb === '') throw new ServiceError('validation', 'missing_field', 'Verb is required');
        if ($inverse === '') throw new ServiceError('validation', 'missing_field', 'Inverse verb is required');
        if (mb_strlen($verb) > 100) throw new ServiceError('validation', 'invalid_field', 'Verb too long (max 100 chars)');
        if (mb_strlen($inverse) > 100) throw new ServiceError('validation', 'invalid_field', 'Inverse verb too long (max 100 chars)');

        // Refuse duplicate verbs - any OTHER type with this verb. On create there is
        // no id yet, and 0 matches no row (GH #163).
        $check = $conn->prepare("SELECT id FROM cmdb_relationship_types WHERE verb = ? AND id <> ?");
        $check->execute([$verb, $id ?? 0]);
        if ($check->fetch()) {
            throw new ServiceError('conflict', 'conflict', 'Another relationship type already uses that verb');
        }

        if ($id === null) {
            $conn->prepare(
                "INSERT INTO cmdb_relationship_types (verb, inverse_verb, description, impact_direction, display_order, is_active, created_datetime)
                 VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
            )->execute([$verb, $inverse, $description ?: null, $impactDirection, $displayOrder, $isActive]);
            $newId = (int)$conn->lastInsertId();
            if ($redundancy !== null) { $conn->prepare("UPDATE cmdb_relationship_types SET redundancy = ? WHERE id = ?")->execute([$redundancy, $newId]); }
            return ['id' => $newId, 'created' => true,
                    'events' => self::event(['cmdb_relationship_type', 'created', $newId, $verb], $opts)];
        }

        $conn->prepare(
            "UPDATE cmdb_relationship_types
                SET verb = ?, inverse_verb = ?, description = ?, impact_direction = ?, display_order = ?, is_active = ?
              WHERE id = ?"
        )->execute([$verb, $inverse, $description ?: null, $impactDirection, $displayOrder, $isActive, $id]);
        if ($redundancy !== null) { $conn->prepare("UPDATE cmdb_relationship_types SET redundancy = ? WHERE id = ?")->execute([$redundancy, $id]); }
        return ['id' => $id, 'created' => false,
                'events' => self::event(['cmdb_relationship_type', 'updated', $id, $verb], $opts)];
    }
}
