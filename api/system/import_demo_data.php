<?php
/**
 * API Endpoint: Import Demo Data
 * Imports sample data for a specific module from database/demo-data/{module}.json
 * Accepts POST parameter: module (required)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$allowedModules = ['core', 'tickets', 'assets', 'knowledge', 'changes', 'calendar', 'checks', 'contracts', 'services', 'software', 'forms', 'software-assets', 'dashboards', 'tasks', 'process-mapper', 'cmdb'];
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

// Helper: pick canvas-default size for a step type
function processMapperDefaultSize($type) {
    switch ($type) {
        case 'decision': return [140, 140];
        case 'start':    return [160, 50];
        case 'document': return [160, 80];
        default:         return [160, 80];
    }
}

// Auto-layout for the process-mapper module: writes x/y/width/height onto each step
// in $demoData['tier2']['process_steps'] using a left-to-right layered placement.
// Each process is laid out independently. Edges that point "backwards" in JSON
// authoring order are treated as cycle-closing back-edges and ignored for ranking.
function autoLayoutProcessMapper(array &$demoData) {
    if (empty($demoData['tier2']['process_steps'])) return;
    $allSteps = &$demoData['tier2']['process_steps'];
    $conns    = $demoData['tier3']['process_connectors'] ?? [];

    // Group step indexes by their process_id ref string (e.g. "@processes.p_incident")
    $byProcess = [];
    foreach ($allSteps as $i => $step) {
        $proc = $step['process_id'] ?? '';
        if ($proc === '') continue;
        $byProcess[$proc][] = $i;
    }

    // Group connectors the same way
    $connsByProcess = [];
    foreach ($conns as $c) {
        $proc = $c['process_id'] ?? '';
        if ($proc === '') continue;
        $connsByProcess[$proc][] = $c;
    }

    // Layout constants
    $LEFT_PAD   = 60;
    $BASELINE_Y = 320;
    $COL_W      = 220;
    $ROW_H      = 160;
    $SLOT_W     = 160; // canvas process-step width — narrower types are centered within

    foreach ($byProcess as $procRef => $stepIdxs) {
        // Build ref → array index map and capture JSON authoring order
        $refToIdx  = [];
        $jsonOrder = [];
        $orderN = 0;
        foreach ($stepIdxs as $idx) {
            $ref = $allSteps[$idx]['_ref'] ?? null;
            if ($ref === null) continue;
            $refToIdx[$ref]  = $idx;
            $jsonOrder[$ref] = $orderN++;
        }
        if (empty($refToIdx)) continue;

        // Build forward adjacency, dropping back-edges (where target appears earlier in JSON)
        $adj = [];
        $hasIncoming = array_fill_keys(array_keys($refToIdx), false);
        $procConns = $connsByProcess[$procRef] ?? [];
        foreach ($procConns as $c) {
            $fromRef = '';
            $toRef   = '';
            if (preg_match('/^@process_steps\.(.+)$/', $c['from_step_id'] ?? '', $m)) $fromRef = $m[1];
            if (preg_match('/^@process_steps\.(.+)$/', $c['to_step_id']   ?? '', $m)) $toRef   = $m[1];
            if (!isset($refToIdx[$fromRef]) || !isset($refToIdx[$toRef])) continue;
            if ($jsonOrder[$toRef] <= $jsonOrder[$fromRef]) continue; // back-edge → skip for ranking
            $adj[$fromRef][] = $toRef;
            $hasIncoming[$toRef] = true;
        }

        // Initialise rank: 0 for nodes with no forward incoming edges, else 0 (relaxed below)
        $rank = array_fill_keys(array_keys($refToIdx), 0);

        // Longest-path relaxation along forward edges (DAG by construction → terminates)
        $iter = 0;
        $maxIter = count($refToIdx) + 2;
        do {
            $changed = false;
            foreach ($adj as $u => $vs) {
                foreach ($vs as $v) {
                    $newRank = $rank[$u] + 1;
                    if ($newRank > $rank[$v]) {
                        $rank[$v] = $newRank;
                        $changed = true;
                    }
                }
            }
        } while ($changed && ++$iter < $maxIter);

        // Group refs by rank in their JSON authoring order (stable top-to-bottom slotting)
        $byRank = [];
        foreach ($refToIdx as $ref => $_) $byRank[$rank[$ref]][] = $ref;
        ksort($byRank);

        // Place each step: x by rank column, y stacked & vertically centered around BASELINE_Y
        foreach ($byRank as $r => $refs) {
            $n = count($refs);
            $slot = 0;
            foreach ($refs as $ref) {
                $idx = $refToIdx[$ref];
                // Fill in width/height defaults from type if not explicitly set
                $type = $allSteps[$idx]['type'] ?? 'process';
                if (!isset($allSteps[$idx]['width']) || !isset($allSteps[$idx]['height'])) {
                    [$dw, $dh] = processMapperDefaultSize($type);
                    if (!isset($allSteps[$idx]['width']))  $allSteps[$idx]['width']  = $dw;
                    if (!isset($allSteps[$idx]['height'])) $allSteps[$idx]['height'] = $dh;
                }
                $w = (int)$allSteps[$idx]['width'];
                $h = (int)$allSteps[$idx]['height'];
                $x = $LEFT_PAD + $r * $COL_W + (int)(($SLOT_W - $w) / 2);
                $y = $BASELINE_Y + (int)(($slot - ($n - 1) / 2) * $ROW_H) - (int)($h / 2);
                $allSteps[$idx]['x'] = $x;
                $allSteps[$idx]['y'] = $y;
                $slot++;
            }
        }
    }
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

    // Module-specific preprocessing: process-mapper auto-layouts steps into a
    // left-to-right layered diagram so the JSON can omit x/y coordinates.
    if ($module === 'process-mapper') {
        autoLayoutProcessMapper($demoData);
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

                // Legacy string field -> FK id translations for tables that have been
                // normalised. Demo JSON still uses human-readable strings ("Open",
                // "High", "Normal") — look each one up in its lookup table and swap
                // for the matching id. Each entry: [str_col, id_col, lookup_table].
                $lookupTranslations = [
                    'tickets' => [
                        ['status',      'status_id',      'ticket_statuses'],
                        ['priority',    'priority_id',    'ticket_priorities'],
                    ],
                    'changes' => [
                        ['change_type', 'change_type_id', 'change_types'],
                        ['status',      'status_id',      'change_statuses'],
                        ['priority',    'priority_id',    'change_priorities'],
                        ['impact',      'impact_id',      'change_impacts'],
                    ],
                    'tasks' => [
                        ['status',      'status_id',      'task_statuses'],
                        ['priority',    'priority_id',    'task_priorities'],
                    ],
                    'status_incidents' => [
                        ['status',      'status_id',      'service_incident_statuses'],
                    ],
                    'status_incident_services' => [
                        ['impact_level','impact_level_id','service_impact_levels'],
                    ],
                ];
                if (isset($lookupTranslations[$tableName])) {
                    foreach ($lookupTranslations[$tableName] as [$strCol, $idCol, $lkTbl]) {
                        if (!array_key_exists($strCol, $record)) continue;
                        if ($record[$strCol] !== null && $record[$strCol] !== '') {
                            $lk = $conn->prepare("SELECT id FROM `$lkTbl` WHERE name = ? LIMIT 1");
                            $lk->execute([$record[$strCol]]);
                            $id = $lk->fetchColumn();
                            if (!$id) {
                                throw new Exception("Demo $tableName has unknown $strCol: " . $record[$strCol]);
                            }
                            $record[$idCol] = (int)$id;
                        }
                        unset($record[$strCol]);
                    }
                }

                // morningChecks_Results uses StatusID (not status_id) and looks up via
                // Label (not name) — different column naming convention. Status is kept
                // as a nullable label snapshot but StatusID is the source of truth, so
                // we resolve the label to an id and null out Status.
                if ($tableName === 'morningChecks_Results' && array_key_exists('Status', $record)) {
                    if ($record['Status'] !== null && $record['Status'] !== '') {
                        $lk = $conn->prepare("SELECT StatusID FROM morningChecks_Statuses WHERE Label = ? LIMIT 1");
                        $lk->execute([$record['Status']]);
                        $sid = $lk->fetchColumn();
                        if (!$sid) {
                            throw new Exception("Demo morningChecks_Results has unknown Status: " . $record['Status']);
                        }
                        $record['StatusID'] = (int)$sid;
                        $record['Status'] = null;
                    }
                }

                // Tickets-specific: drop legacy requester_email/requester_name (now
                // sourced from users via user_id).
                if ($tableName === 'tickets') {
                    unset($record['requester_email'], $record['requester_name']);
                }

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
