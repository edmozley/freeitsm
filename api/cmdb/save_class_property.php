<?php
/**
 * API: Create or update a property definition on a class.
 * Handles dropdown options inline (passed as `options` array; we wipe and re-insert).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');
requireCapabilityJson(Cap::CMDB_CLASSES);   // settings tab — see docs/design/rbac.md

require_once '../../includes/cmdb_record_refs.php';

// GH #312: plus the record types - person, analyst, team, location, company,
// supplier, contract (cmdbRecordTypes(), the one list).
define('VALID_PROPERTY_TYPES', array_merge(['text', 'number', 'date', 'boolean', 'dropdown', 'object_ref'], array_keys(cmdbRecordTypes())));

/**
 * Where a property type keeps its value: a type change that moves to another
 * place - or keeps the id but changes what it names (a person id read as a
 * team) - would strand or misread every value already stored.
 */
function propertyValueHome(string $type): string {
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
 * Turns a property name into a lower-case key of letters, digits and underscores,
 * falling back to 'property' when nothing is left.
 *
 * @param mixed $name  the property name as typed
 */
function slugifyProp($name) {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug !== '' ? $slug : 'property';
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;
    $classId = isset($data['class_id']) ? (int)$data['class_id'] : 0;
    $label = trim((string)($data['label'] ?? ''));
    $key = trim((string)($data['property_key'] ?? ''));
    $type = trim((string)($data['property_type'] ?? ''));
    $targetClassId = isset($data['target_class_id']) && $data['target_class_id'] !== '' ? (int)$data['target_class_id'] : null;
    $isRequired = !empty($data['is_required']) ? 1 : 0;
    $spreadsImpact = !empty($data['spreads_impact']) ? 1 : 0;
    $displayOrder = isset($data['display_order']) ? (int)$data['display_order'] : 0;
    $options = $data['options'] ?? [];

    if ($classId <= 0) throw new Exception('class_id is required');
    if ($label === '') throw new Exception('Label is required');
    if (!in_array($type, VALID_PROPERTY_TYPES, true)) throw new Exception('Invalid property type');
    if ($type === 'object_ref' && $targetClassId === null) {
        throw new Exception('Object reference properties need a target class');
    }
    if ($type !== 'object_ref') $targetClassId = null;
    // Only an object reference can be a dependency. Clearing the flag when the
    // type changes stops a stale 1 from quietly feeding the blast radius from a
    // property that no longer points at anything.
    if ($type !== 'object_ref') $spreadsImpact = 0;

    if ($key === '') {
        $key = slugifyProp($label);
    } else {
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            throw new Exception('Key may only contain lowercase letters, numbers, and underscores');
        }
    }

    $conn = connectToDatabase();
    $conn->beginTransaction();

    if ($id === null) {
        // Auto-resolve key collisions inside the same class
        $base = $key; $n = 2;
        $check = $conn->prepare("SELECT id FROM cmdb_class_properties WHERE class_id = ? AND property_key = ?");
        while (true) {
            $check->execute([$classId, $key]);
            if (!$check->fetch()) break;
            $key = $base . '_' . $n++;
            if ($n > 50) throw new Exception('Could not generate a unique key — please supply one explicitly');
        }

        $stmt = $conn->prepare(
            "INSERT INTO cmdb_class_properties
                 (class_id, property_key, label, property_type, target_class_id, is_required, spreads_impact, display_order, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([$classId, $key, $label, $type, $targetClassId, $isRequired, $spreadsImpact, $displayOrder]);
        $newId = (int)$conn->lastInsertId();
    } else {
        // GH #312: refuse a type change that would misread the values already
        // stored (text -> dropdown keeps its text and is fine; person -> team is not).
        $cur = $conn->prepare("SELECT property_type FROM cmdb_class_properties WHERE id = ? AND class_id = ?");
        $cur->execute([$id, $classId]);
        $oldType = (string)$cur->fetchColumn();
        if ($oldType !== '' && propertyValueHome($oldType) !== propertyValueHome($type)) {
            $used = $conn->prepare("SELECT COUNT(*) FROM cmdb_object_properties WHERE property_id = ?");
            $used->execute([$id]);
            if ((int)$used->fetchColumn() > 0) {
                throw new Exception('This property already has values, so its type cannot change to one that stores them differently. Add a new property instead.');
            }
        }

        // Refuse a key change that would collide
        $check = $conn->prepare("SELECT id FROM cmdb_class_properties WHERE class_id = ? AND property_key = ? AND id <> ?");
        $check->execute([$classId, $key, $id]);
        if ($check->fetch()) {
            throw new Exception('Another property in this class already uses that key');
        }

        $stmt = $conn->prepare(
            "UPDATE cmdb_class_properties
                SET property_key = ?, label = ?, property_type = ?, target_class_id = ?, is_required = ?, spreads_impact = ?, display_order = ?
              WHERE id = ? AND class_id = ?"
        );
        $stmt->execute([$key, $label, $type, $targetClassId, $isRequired, $spreadsImpact, $displayOrder, $id, $classId]);
        $newId = $id;
    }

    // Refresh dropdown options. Wipe + reinsert is simplest and safe inside the txn.
    // Each option may be either a plain string (legacy / from AI suggest flow)
    // or an object {value, colour} from the new row-based editor.
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
                throw new Exception('Invalid colour for option "' . $value . '" (must be a #RGB or #RRGGBB hex)');
            }
            $insOpt->execute([$newId, $value, $colour !== '' ? $colour : null, $order]);
            $order += 10;
        }
    } elseif ($type !== 'dropdown') {
        // Switching away from dropdown — clear any leftover options.
        $conn->prepare("DELETE FROM cmdb_class_property_options WHERE property_id = ?")->execute([$newId]);
    }

    $conn->commit();
    wf_emit('cmdb_property', $id === null ? 'created' : 'updated', $newId, $label);
    echo json_encode(['success' => true, 'id' => $newId, 'property_key' => $key]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
