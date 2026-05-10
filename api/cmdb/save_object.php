<?php
/**
 * API: Create or update a CMDB object.
 *
 * Accepts:
 *   - id (null on create)
 *   - class_id (required on create; ignored on update — class can't change)
 *   - name (required)
 *   - parent_id (nullable)
 *   - property_values: [{property_id, value}] — value is the type-appropriate
 *     scalar (string for text/dropdown, number for number, ISO date for date,
 *     boolean for boolean, integer object id for object_ref).
 *
 * Validates required properties. Wipes/re-inserts cmdb_object_properties rows
 * for the supplied property_values inside a transaction.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id        = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;
    $classId   = isset($data['class_id']) && $data['class_id'] !== '' ? (int)$data['class_id'] : null;
    $name      = trim((string)($data['name'] ?? ''));
    $parentId  = isset($data['parent_id']) && $data['parent_id'] !== '' && $data['parent_id'] !== null ? (int)$data['parent_id'] : null;
    $values    = $data['property_values'] ?? [];

    if ($name === '') throw new Exception('Name is required');
    if (mb_strlen($name) > 255) throw new Exception('Name too long (max 255 chars)');
    if ($id === null && $classId === null) throw new Exception('class_id is required when creating a new object');

    $conn = connectToDatabase();

    // Resolve class_id from existing object on update; verify class exists
    if ($id !== null) {
        $cs = $conn->prepare("SELECT class_id FROM cmdb_objects WHERE id = ?");
        $cs->execute([$id]);
        $existingClassId = $cs->fetchColumn();
        if ($existingClassId === false) throw new Exception('Object not found');
        $classId = (int)$existingClassId;
    } else {
        $cs = $conn->prepare("SELECT id FROM cmdb_classes WHERE id = ? AND is_active = 1");
        $cs->execute([$classId]);
        if (!$cs->fetch()) throw new Exception('Class not found or inactive');
    }

    // Verify parent (if supplied) exists and isn't this object itself
    if ($parentId !== null) {
        if ($id !== null && $parentId === $id) throw new Exception("An object can't be its own parent");
        $ps = $conn->prepare("SELECT id FROM cmdb_objects WHERE id = ?");
        $ps->execute([$parentId]);
        if (!$ps->fetch()) throw new Exception('Parent object not found');
        // Cycle prevention: walk up from parent and refuse if we hit $id
        if ($id !== null) {
            $cursor = $parentId;
            $hops = 0;
            while ($cursor !== null && $hops < 100) {
                if ($cursor === $id) throw new Exception('That parent would create a cycle (the parent is a descendant of this object).');
                $u = $conn->prepare("SELECT parent_id FROM cmdb_objects WHERE id = ?");
                $u->execute([$cursor]);
                $next = $u->fetchColumn();
                $cursor = $next ? (int)$next : null;
                $hops++;
            }
        }
    }

    // Load property definitions for the class so we can validate types + required
    $defs = [];
    $defStmt = $conn->prepare(
        "SELECT id, property_key, label, property_type, target_class_id, is_required
           FROM cmdb_class_properties WHERE class_id = ?"
    );
    $defStmt->execute([$classId]);
    foreach ($defStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $defs[(int)$d['id']] = $d;
    }

    // Build value map (only known property_ids) and check required
    $providedValues = [];
    if (is_array($values)) {
        foreach ($values as $entry) {
            if (!is_array($entry)) continue;
            $pid = isset($entry['property_id']) ? (int)$entry['property_id'] : 0;
            if (!isset($defs[$pid])) continue; // ignore unknown / belongs-to-other-class
            $providedValues[$pid] = array_key_exists('value', $entry) ? $entry['value'] : null;
        }
    }

    // Required-property validation:
    //   - On CREATE: every required property must be present and non-empty in the payload
    //   - On UPDATE: only properties the caller is explicitly touching are validated.
    //     If they include a required property and set it empty, reject. If they don't
    //     mention a required property at all (the common inline-edit case), leave it
    //     alone — its DB value is unchanged. Otherwise editing one field on an object
    //     would unnecessarily fail because *some other* required field is missing.
    foreach ($defs as $pid => $d) {
        if ((int)$d['is_required'] !== 1) continue;
        if (array_key_exists($pid, $providedValues)) {
            $v = $providedValues[$pid];
            $isEmpty = ($v === null || $v === '' || (is_array($v) && empty($v)));
            if ($isEmpty) {
                throw new Exception('Required property missing: ' . $d['label']);
            }
        } elseif ($id === null) {
            // Create with no value supplied for this required property
            throw new Exception('Required property missing: ' . $d['label']);
        }
    }

    $conn->beginTransaction();

    if ($id === null) {
        $ins = $conn->prepare(
            "INSERT INTO cmdb_objects (class_id, name, parent_id, created_datetime, updated_datetime)
             VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $ins->execute([$classId, $name, $parentId]);
        $id = (int)$conn->lastInsertId();
    } else {
        $upd = $conn->prepare(
            "UPDATE cmdb_objects SET name = ?, parent_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?"
        );
        $upd->execute([$name, $parentId, $id]);
    }

    // Wipe existing values for the properties we're updating, then insert fresh.
    // (Only touches the property_ids the caller sent — leaves other props alone.)
    if (!empty($providedValues)) {
        $delStmt = $conn->prepare("DELETE FROM cmdb_object_properties WHERE object_id = ? AND property_id = ?");
        $insStmt = $conn->prepare(
            "INSERT INTO cmdb_object_properties
                 (object_id, property_id, value_text, value_number, value_date, value_boolean, value_object_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($providedValues as $pid => $rawValue) {
            $delStmt->execute([$id, $pid]);

            // Skip insert if the value is null/empty — that's "clear this property"
            if ($rawValue === null || $rawValue === '') continue;

            $def = $defs[$pid];
            $vText = null; $vNumber = null; $vDate = null; $vBool = null; $vObj = null;

            switch ($def['property_type']) {
                case 'text':
                    $vText = (string)$rawValue;
                    break;
                case 'dropdown':
                    $vText = (string)$rawValue;
                    break;
                case 'number':
                    if (!is_numeric($rawValue)) throw new Exception('Property "' . $def['label'] . '" expects a number');
                    $vNumber = (float)$rawValue;
                    break;
                case 'date':
                    // Accept ISO yyyy-mm-dd or full datetime; let MySQL parse.
                    $vDate = (string)$rawValue;
                    break;
                case 'boolean':
                    $vBool = ($rawValue === true || $rawValue === 1 || $rawValue === '1' || $rawValue === 'true') ? 1 : 0;
                    break;
                case 'object_ref':
                    $vObj = (int)$rawValue;
                    if ($vObj <= 0) continue 2; // skip empty
                    if ($vObj === $id) throw new Exception('Property "' . $def['label'] . '" can\'t reference its own object');
                    // Verify the referenced object exists and (if target_class_id is set) matches the constraint
                    $rs = $conn->prepare("SELECT class_id FROM cmdb_objects WHERE id = ?");
                    $rs->execute([$vObj]);
                    $refClassId = $rs->fetchColumn();
                    if ($refClassId === false) throw new Exception('Property "' . $def['label'] . '" references an object that doesn\'t exist');
                    if ($def['target_class_id'] !== null && (int)$refClassId !== (int)$def['target_class_id']) {
                        throw new Exception('Property "' . $def['label'] . '" can only reference objects of its target class');
                    }
                    break;
                default:
                    throw new Exception('Unknown property type: ' . $def['property_type']);
            }

            $insStmt->execute([$id, $pid, $vText, $vNumber, $vDate, $vBool, $vObj]);
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
