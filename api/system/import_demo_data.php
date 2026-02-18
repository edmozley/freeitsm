<?php
/**
 * API Endpoint: Import Demo Data
 * Imports sample data for a specific module from database/demo-data/{module}.json
 * Accepts POST parameter: module (required)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$allowedModules = ['core', 'tickets', 'assets', 'knowledge', 'changes', 'calendar', 'checks', 'contracts', 'services', 'software', 'forms', 'software-assets'];
$module = $_POST['module'] ?? '';
if (!in_array($module, $allowedModules)) {
    echo json_encode(['success' => false, 'error' => 'Invalid module: ' . $module]);
    exit;
}

// Helper: get primary key column name for a table
function getPrimaryKeyColumn($tableName) {
    $special = [
        'morningChecks_Checks' => 'CheckID',
        'morningChecks_Results' => 'ResultID',
        'knowledge_article_tags' => null
    ];
    return array_key_exists($tableName, $special) ? $special[$tableName] : 'id';
}

// Helper: resolve @table.ref references to real IDs
function resolveReferences($record, $idMap) {
    foreach ($record as $key => $value) {
        if (is_string($value) && strpos($value, '@') === 0) {
            $refKey = substr($value, 1);
            if (!isset($idMap[$refKey])) {
                throw new Exception("Unresolved reference: $value (field: $key)");
            }
            $record[$key] = $idMap[$refKey];
        }
    }
    return $record;
}

// Helper: resolve special tokens
function resolveTokens($record, $conn) {
    foreach ($record as $key => $value) {
        if (!is_string($value)) continue;

        if ($value === '__GENERATE__') {
            $record[$key] = generateDemoTicketNumber($conn);
        } elseif ($value === '__NOW__') {
            $record[$key] = gmdate('Y-m-d H:i:s');
        } elseif (strpos($value, '__UNIQUE__') !== false) {
            $record[$key] = str_replace('__UNIQUE__', uniqid(), $value);
        } elseif (preg_match('/^__BCRYPT:(.+)__$/', $value, $m)) {
            $record[$key] = password_hash($m[1], PASSWORD_DEFAULT);
        } elseif (preg_match('/^__RELATIVE_DATE:([+-]?\d+)d(?:([+-]\d+)h)?__$/', $value, $m)) {
            $dt = new DateTime('now', new DateTimeZone('UTC'));
            $days = (int)$m[1];
            $dt->modify("$days days");
            if (!empty($m[2])) {
                $hours = (int)$m[2];
                $dt->modify("$hours hours");
            }
            $record[$key] = $dt->format('Y-m-d H:i:s');
        } elseif (preg_match('/^__RELATIVE_DATEONLY:([+-]?\d+)d__$/', $value, $m)) {
            $dt = new DateTime('now', new DateTimeZone('UTC'));
            $days = (int)$m[1];
            $dt->modify("$days days");
            $record[$key] = $dt->format('Y-m-d');
        }
    }
    return $record;
}

// Helper: generate unique ticket number
function generateDemoTicketNumber($conn) {
    for ($i = 0; $i < 20; $i++) {
        $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
        $num1 = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
        $num2 = str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
        $ticketNumber = "$letters-$num1-$num2";
        $stmt = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number = ?");
        $stmt->execute([$ticketNumber]);
        if (!$stmt->fetchColumn()) return $ticketNumber;
    }
    throw new Exception('Failed to generate unique ticket number');
}

try {
    $jsonPath = __DIR__ . "/../../database/demo-data/{$module}.json";
    if (!file_exists($jsonPath)) {
        throw new Exception("Demo data file not found: database/demo-data/{$module}.json");
    }

    $demoData = json_decode(file_get_contents($jsonPath), true);
    if (!$demoData) {
        throw new Exception('Failed to parse demo data JSON: ' . json_last_error_msg());
    }

    $conn = connectToDatabase();
    $conn->beginTransaction();

    // Pre-scan: find tables with insertable records and collect skip-insert criteria
    $tiers = ['tier1', 'tier2', 'tier3', 'tier4', 'tier5'];
    $tablesToClean = [];
    $skipCriteria = [];

    foreach ($tiers as $tierKey) {
        if (!isset($demoData[$tierKey])) continue;
        foreach ($demoData[$tierKey] as $tableName => $records) {
            $hasInserts = false;
            foreach ($records as $record) {
                if (!empty($record['_skip_insert'])) {
                    $skipCriteria[$tableName][] = [
                        'column' => $record['_match_by'],
                        'value' => $record['_match_value']
                    ];
                } else {
                    $hasInserts = true;
                }
            }
            if ($hasInserts && !in_array($tableName, $tablesToClean)) {
                $tablesToClean[] = $tableName;
            }
        }
    }

    // Delete existing demo data (reverse order for FK dependencies)
    $conn->exec("SET FOREIGN_KEY_CHECKS = 0");
    foreach (array_reverse($tablesToClean) as $tableName) {
        if (!empty($skipCriteria[$tableName])) {
            // Keep rows that match skip-insert criteria (e.g. admin account)
            $conditions = [];
            $params = [];
            foreach ($skipCriteria[$tableName] as $criteria) {
                $conditions[] = "`{$criteria['column']}` = ?";
                $params[] = $criteria['value'];
            }
            $sql = "DELETE FROM `$tableName` WHERE NOT (" . implode(' OR ', $conditions) . ")";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
        } else {
            $conn->exec("DELETE FROM `$tableName`");
        }
    }
    $conn->exec("SET FOREIGN_KEY_CHECKS = 1");

    $idMap = [];
    $counts = [];

    foreach ($tiers as $tierKey) {
        if (!isset($demoData[$tierKey])) continue;

        foreach ($demoData[$tierKey] as $tableName => $records) {
            if (!isset($counts[$tableName])) $counts[$tableName] = 0;

            foreach ($records as $record) {
                $ref = $record['_ref'] ?? null;

                // Handle existing records (skip insert, just map the ID)
                if (!empty($record['_skip_insert'])) {
                    $matchBy = $record['_match_by'];
                    $matchValue = $record['_match_value'];
                    $stmt = $conn->prepare("SELECT * FROM `$tableName` WHERE `$matchBy` = ? LIMIT 1");
                    $stmt->execute([$matchValue]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && $ref) {
                        $pkCol = getPrimaryKeyColumn($tableName);
                        if ($pkCol) {
                            $idMap["$tableName.$ref"] = $row[$pkCol];
                        }
                    }
                    continue;
                }

                // Resolve references and tokens
                $record = resolveReferences($record, $idMap);
                $record = resolveTokens($record, $conn);

                // Remove meta fields
                unset($record['_ref'], $record['_match_by'], $record['_match_value'], $record['_skip_insert']);

                // Build and execute INSERT
                $columns = array_keys($record);
                $placeholders = array_fill(0, count($columns), '?');
                $sql = "INSERT INTO `$tableName` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_values($record));

                // Map the ref to the new ID
                if ($ref) {
                    $pkCol = getPrimaryKeyColumn($tableName);
                    if ($pkCol) {
                        $idMap["$tableName.$ref"] = $conn->lastInsertId();
                    }
                }

                $counts[$tableName]++;
            }
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'module' => $module,
        'imported' => $counts,
        'total' => array_sum($counts)
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
