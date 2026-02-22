<?php
/**
 * API Endpoint: Get aggregated data for a ticket dashboard widget
 * Params: widget_id (required), status (optional filter value)
 * Returns: {labels, values} for single-series or {labels, series} for multi-series
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$widget_id = $_GET['widget_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';

if (empty($widget_id)) {
    echo json_encode(['success' => false, 'error' => 'widget_id is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get widget definition
    $wStmt = $conn->prepare("SELECT aggregate_property, series_property, is_status_filterable,
                                    default_status, date_range, department_filter, time_grouping
                             FROM ticket_dashboard_widgets WHERE id = ?");
    $wStmt->execute([$widget_id]);
    $widget = $wStmt->fetch(PDO::FETCH_ASSOC);

    if (!$widget) {
        echo json_encode(['success' => false, 'error' => 'Widget not found']);
        exit;
    }

    $prop = $widget['aggregate_property'];
    $seriesProp = $widget['series_property'];
    $dateRange = $widget['date_range'];
    $deptFilter = $widget['department_filter'] ? json_decode($widget['department_filter'], true) : null;
    $timeGrouping = $widget['time_grouping'];

    // Build composable WHERE clauses
    $whereClauses = [];
    $params = [];

    // Status filter
    if (!empty($statusFilter) && $widget['is_status_filterable']) {
        $whereClauses[] = 't.status = ?';
        $params[] = $statusFilter;
    } elseif (!$widget['is_status_filterable'] && $widget['default_status']) {
        $whereClauses[] = 't.status = ?';
        $params[] = $widget['default_status'];
    }

    // Department filter
    [$deptWhere, $deptParams] = buildDepartmentWhere($deptFilter);
    if ($deptWhere) {
        $whereClauses[] = $deptWhere;
        $params = array_merge($params, $deptParams);
    }

    // Date range filter (for categorical aggregates only — time-based handle their own)
    if (!isTimeBased($prop) && !isCreatedVsClosed($prop)) {
        [$dateWhere, $dateParam] = buildDateRangeWhere($dateRange, 'created_datetime');
        if ($dateWhere) {
            $whereClauses[] = $dateWhere;
            $params[] = $dateParam;
        }
    }

    $where = count($whereClauses) > 0 ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    // --- Categorical aggregates (no series) ---
    if (!$seriesProp && !isTimeBased($prop) && !isCreatedVsClosed($prop)) {
        $result = getCategoricalData($conn, $prop, $where, $params);
        echo json_encode(['success' => true, 'labels' => $result['labels'], 'values' => $result['values']]);
        exit;
    }

    // --- Categorical aggregate WITH series breakdown ---
    if ($seriesProp && !isTimeBased($prop) && !isCreatedVsClosed($prop)) {
        $result = getCategoricalWithSeries($conn, $prop, $seriesProp, $where, $params);
        echo json_encode(['success' => true, 'labels' => $result['labels'], 'series' => $result['series']]);
        exit;
    }

    // --- Time-based, single series ---
    if (!$seriesProp && isTimeBased($prop)) {
        $result = getTimeData($conn, $prop, $timeGrouping, $dateRange, $where, $params);
        echo json_encode(['success' => true, 'labels' => $result['labels'], 'values' => $result['values']]);
        exit;
    }

    // --- Time-based with series breakdown ---
    if ($seriesProp && isTimeBased($prop)) {
        $result = getTimeWithSeries($conn, $prop, $seriesProp, $timeGrouping, $dateRange, $where, $params);
        echo json_encode(['success' => true, 'labels' => $result['labels'], 'series' => $result['series']]);
        exit;
    }

    // --- Created vs Closed (inherent 2-series) ---
    if (isCreatedVsClosed($prop)) {
        $result = getCreatedVsClosedData($conn, $timeGrouping, $dateRange, $where, $params);
        echo json_encode(['success' => true, 'labels' => $result['labels'], 'series' => $result['series']]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unsupported aggregate type']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// --- Helper functions ---

function isTimeBased($prop) {
    return in_array($prop, ['created', 'closed']);
}

function isCreatedVsClosed($prop) {
    return $prop === 'created_vs_closed';
}

function getDateField($prop) {
    if ($prop === 'closed') return 'closed_datetime';
    return 'created_datetime';
}

function buildDateRangeWhere($dateRange, $dateField) {
    if (empty($dateRange) || $dateRange === 'all') {
        return ['', null];
    }

    $now = new DateTime('now', new DateTimeZone('UTC'));
    switch ($dateRange) {
        case '7d':         $start = (clone $now)->modify('-7 days')->format('Y-m-d'); break;
        case '30d':        $start = (clone $now)->modify('-30 days')->format('Y-m-d'); break;
        case 'this_month': $start = $now->format('Y-m-01'); break;
        case '3m':         $start = (clone $now)->modify('-3 months')->format('Y-m-d'); break;
        case '6m':         $start = (clone $now)->modify('-6 months')->format('Y-m-d'); break;
        case '12m':        $start = (clone $now)->modify('-12 months')->format('Y-m-d'); break;
        case 'this_year':  $start = $now->format('Y-01-01'); break;
        default:           return ['', null];
    }

    return ["t.{$dateField} >= ?", $start];
}

function buildDepartmentWhere($deptFilter) {
    if (empty($deptFilter) || !is_array($deptFilter)) {
        return ['', []];
    }
    $placeholders = implode(',', array_fill(0, count($deptFilter), '?'));
    return ["t.department_id IN ({$placeholders})", array_map('intval', $deptFilter)];
}

function getDateExpr($timeGrouping, $dateField) {
    switch ($timeGrouping) {
        case 'day':   return "DATE(t.{$dateField})";
        case 'month': return "DATE_FORMAT(t.{$dateField}, '%Y-%m')";
        case 'year':  return "YEAR(t.{$dateField})";
    }
    return "DATE(t.{$dateField})";
}

function getTimeLabels($dateRange, $timeGrouping) {
    $now = new DateTime('now', new DateTimeZone('UTC'));

    switch ($dateRange) {
        case '7d':         $start = (clone $now)->modify('-7 days'); break;
        case '30d':        $start = (clone $now)->modify('-30 days'); break;
        case 'this_month': $start = new DateTime($now->format('Y-m-01'), new DateTimeZone('UTC')); break;
        case '3m':         $start = (clone $now)->modify('-3 months'); break;
        case '6m':         $start = (clone $now)->modify('-6 months'); break;
        case '12m':        $start = (clone $now)->modify('-12 months'); break;
        case 'this_year':  $start = new DateTime($now->format('Y-01-01'), new DateTimeZone('UTC')); break;
        default:           $start = (clone $now)->modify('-12 months'); break;
    }

    $labels = [];
    switch ($timeGrouping) {
        case 'day':
            $cursor = clone $start;
            while ($cursor <= $now) {
                $labels[] = $cursor->format('Y-m-d');
                $cursor->modify('+1 day');
            }
            break;
        case 'month':
            $cursor = new DateTime($start->format('Y-m-01'), new DateTimeZone('UTC'));
            $end = new DateTime($now->format('Y-m-01'), new DateTimeZone('UTC'));
            while ($cursor <= $end) {
                $labels[] = $cursor->format('Y-m');
                $cursor->modify('+1 month');
            }
            break;
        case 'year':
            $startYear = (int)$start->format('Y');
            $endYear = (int)$now->format('Y');
            for ($y = $startYear; $y <= $endYear; $y++) {
                $labels[] = (string)$y;
            }
            break;
    }
    return $labels;
}

function formatLabel($raw, $timeGrouping) {
    switch ($timeGrouping) {
        case 'day':   return (new DateTime($raw))->format('j M');
        case 'month': return (new DateTime($raw . '-01'))->format('M Y');
        case 'year':  return (string)$raw;
    }
    return $raw;
}

function getCategoricalData($conn, $prop, $where, $params) {
    if (in_array($prop, ['status', 'priority'])) {
        $col = $prop === 'status' ? 't.status' : 't.priority';
        $sql = "SELECT COALESCE({$col}, 'Unknown') AS label, COUNT(*) AS value FROM tickets t {$where} GROUP BY {$col} ORDER BY value DESC";
    } elseif ($prop === 'department') {
        $sql = "SELECT COALESCE(d.name, 'Unassigned') AS label, COUNT(*) AS value FROM tickets t LEFT JOIN departments d ON d.id = t.department_id {$where} GROUP BY d.name ORDER BY value DESC";
    } elseif ($prop === 'ticket_type') {
        $sql = "SELECT COALESCE(tt.name, 'Unassigned') AS label, COUNT(*) AS value FROM tickets t LEFT JOIN ticket_types tt ON tt.id = t.ticket_type_id {$where} GROUP BY tt.name ORDER BY value DESC";
    } elseif ($prop === 'analyst') {
        $sql = "SELECT COALESCE(a.full_name, 'Unassigned') AS label, COUNT(*) AS value FROM tickets t LEFT JOIN analysts a ON a.id = t.assigned_analyst_id {$where} GROUP BY a.full_name ORDER BY value DESC";
    } elseif ($prop === 'owner') {
        $sql = "SELECT COALESCE(a.full_name, 'Unassigned') AS label, COUNT(*) AS value FROM tickets t LEFT JOIN analysts a ON a.id = t.owner_id {$where} GROUP BY a.full_name ORDER BY value DESC";
    } elseif ($prop === 'origin') {
        $sql = "SELECT COALESCE(o.name, 'Unknown') AS label, COUNT(*) AS value FROM tickets t LEFT JOIN ticket_origins o ON o.id = t.origin_id {$where} GROUP BY o.name ORDER BY value DESC";
    } elseif ($prop === 'first_time_fix') {
        $sql = "SELECT CASE WHEN t.first_time_fix = 1 THEN 'Yes' WHEN t.first_time_fix = 0 THEN 'No' ELSE 'Not set' END AS label, COUNT(*) AS value FROM tickets t {$where} GROUP BY label ORDER BY value DESC";
    } elseif ($prop === 'training_provided') {
        $sql = "SELECT CASE WHEN t.it_training_provided = 1 THEN 'Yes' WHEN t.it_training_provided = 0 THEN 'No' ELSE 'Not set' END AS label, COUNT(*) AS value FROM tickets t {$where} GROUP BY label ORDER BY value DESC";
    } else {
        return ['labels' => [], 'values' => []];
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'labels' => array_column($data, 'label'),
        'values' => array_map('intval', array_column($data, 'value'))
    ];
}

function getCategoricalWithSeries($conn, $prop, $seriesProp, $where, $params) {
    $labelExpr = '';
    $join = '';

    if ($prop === 'department') {
        $labelExpr = "COALESCE(d.name, 'Unassigned')";
        $join = 'LEFT JOIN departments d ON d.id = t.department_id';
    } elseif ($prop === 'ticket_type') {
        $labelExpr = "COALESCE(tt.name, 'Unassigned')";
        $join = 'LEFT JOIN ticket_types tt ON tt.id = t.ticket_type_id';
    } elseif ($prop === 'analyst') {
        $labelExpr = "COALESCE(a.full_name, 'Unassigned')";
        $join = 'LEFT JOIN analysts a ON a.id = t.assigned_analyst_id';
    } elseif ($prop === 'owner') {
        $labelExpr = "COALESCE(a.full_name, 'Unassigned')";
        $join = 'LEFT JOIN analysts a ON a.id = t.owner_id';
    } elseif ($prop === 'origin') {
        $labelExpr = "COALESCE(o.name, 'Unknown')";
        $join = 'LEFT JOIN ticket_origins o ON o.id = t.origin_id';
    } elseif ($prop === 'priority') {
        $labelExpr = "COALESCE(t.priority, 'Unknown')";
        $join = '';
    } else {
        return ['labels' => [], 'series' => []];
    }

    $seriesCol = $seriesProp === 'status' ? "COALESCE(t.status, 'Unknown')" : "COALESCE(t.priority, 'Unknown')";

    $sql = "SELECT {$labelExpr} AS label, {$seriesCol} AS series_val, COUNT(*) AS value
            FROM tickets t {$join} {$where}
            GROUP BY label, series_val
            ORDER BY label, series_val";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return pivotToSeries($data);
}

function getTimeData($conn, $prop, $timeGrouping, $dateRange, $where, $params) {
    $dateField = getDateField($prop);
    $dateExpr = getDateExpr($timeGrouping, $dateField);

    // Add date range filter
    [$dateWhere, $dateParam] = buildDateRangeWhere($dateRange, $dateField);
    if ($dateWhere) {
        $fullWhere = $where ? $where . " AND {$dateWhere}" : " WHERE {$dateWhere}";
        $fullParams = array_merge($params, [$dateParam]);
    } else {
        $fullWhere = $where;
        $fullParams = $params;
    }

    // For closed, require date field is not null
    if ($dateField === 'closed_datetime') {
        $notNull = "t.{$dateField} IS NOT NULL";
        $fullWhere = $fullWhere ? $fullWhere . " AND {$notNull}" : " WHERE {$notNull}";
    }

    $sql = "SELECT {$dateExpr} AS label, COUNT(*) AS value FROM tickets t {$fullWhere} GROUP BY label ORDER BY label";

    $stmt = $conn->prepare($sql);
    $stmt->execute($fullParams);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fill gaps
    $dataMap = [];
    foreach ($data as $row) {
        $dataMap[(string)$row['label']] = (int)$row['value'];
    }

    $allLabels = getTimeLabels($dateRange, $timeGrouping);
    $labels = [];
    $values = [];
    foreach ($allLabels as $l) {
        $labels[] = formatLabel($l, $timeGrouping);
        $values[] = $dataMap[$l] ?? 0;
    }

    return ['labels' => $labels, 'values' => $values];
}

function getTimeWithSeries($conn, $prop, $seriesProp, $timeGrouping, $dateRange, $where, $params) {
    $dateField = getDateField($prop);
    $dateExpr = getDateExpr($timeGrouping, $dateField);
    $seriesCol = $seriesProp === 'status' ? "COALESCE(t.status, 'Unknown')" : "COALESCE(t.priority, 'Unknown')";

    // Add date range filter
    [$dateWhere, $dateParam] = buildDateRangeWhere($dateRange, $dateField);
    if ($dateWhere) {
        $fullWhere = $where ? $where . " AND {$dateWhere}" : " WHERE {$dateWhere}";
        $fullParams = array_merge($params, [$dateParam]);
    } else {
        $fullWhere = $where;
        $fullParams = $params;
    }

    if ($dateField === 'closed_datetime') {
        $notNull = "t.{$dateField} IS NOT NULL";
        $fullWhere = $fullWhere ? $fullWhere . " AND {$notNull}" : " WHERE {$notNull}";
    }

    $sql = "SELECT {$dateExpr} AS label, {$seriesCol} AS series_val, COUNT(*) AS value
            FROM tickets t {$fullWhere}
            GROUP BY label, series_val
            ORDER BY label, series_val";

    $stmt = $conn->prepare($sql);
    $stmt->execute($fullParams);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all unique series values
    $seriesNames = [];
    $rawMap = [];
    foreach ($data as $row) {
        $seriesNames[(string)$row['series_val']] = true;
        $rawMap[(string)$row['label']][(string)$row['series_val']] = (int)$row['value'];
    }
    $seriesNames = array_keys($seriesNames);

    // Fill gaps in time labels
    $allLabels = getTimeLabels($dateRange, $timeGrouping);
    $labels = [];
    $seriesData = [];
    foreach ($seriesNames as $sn) {
        $seriesData[$sn] = [];
    }

    foreach ($allLabels as $l) {
        $labels[] = formatLabel($l, $timeGrouping);
        foreach ($seriesNames as $sn) {
            $seriesData[$sn][] = $rawMap[$l][$sn] ?? 0;
        }
    }

    $series = [];
    foreach ($seriesNames as $sn) {
        $series[] = ['label' => $sn, 'values' => $seriesData[$sn]];
    }

    return ['labels' => $labels, 'series' => $series];
}

function getCreatedVsClosedData($conn, $timeGrouping, $dateRange, $where, $params) {
    $createdDateExpr = getDateExpr($timeGrouping, 'created_datetime');
    $closedDateExpr = getDateExpr($timeGrouping, 'closed_datetime');

    // Created counts
    [$crDateWhere, $crDateParam] = buildDateRangeWhere($dateRange, 'created_datetime');
    if ($crDateWhere) {
        $createdWhere = $where ? $where . " AND {$crDateWhere}" : " WHERE {$crDateWhere}";
        $createdParams = array_merge($params, [$crDateParam]);
    } else {
        $createdWhere = $where;
        $createdParams = $params;
    }

    $sqlCreated = "SELECT {$createdDateExpr} AS label, COUNT(*) AS value FROM tickets t {$createdWhere} GROUP BY label ORDER BY label";
    $stmt = $conn->prepare($sqlCreated);
    $stmt->execute($createdParams);
    $createdData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Closed counts
    [$clDateWhere, $clDateParam] = buildDateRangeWhere($dateRange, 'closed_datetime');
    $closedNotNull = "t.closed_datetime IS NOT NULL";
    if ($clDateWhere) {
        $closedWhere = $where ? $where . " AND {$clDateWhere} AND {$closedNotNull}" : " WHERE {$clDateWhere} AND {$closedNotNull}";
        $closedParams = array_merge($params, [$clDateParam]);
    } else {
        $closedWhere = $where ? $where . " AND {$closedNotNull}" : " WHERE {$closedNotNull}";
        $closedParams = $params;
    }

    $sqlClosed = "SELECT {$closedDateExpr} AS label, COUNT(*) AS value FROM tickets t {$closedWhere} GROUP BY label ORDER BY label";
    $stmt = $conn->prepare($sqlClosed);
    $stmt->execute($closedParams);
    $closedData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $createdMap = [];
    foreach ($createdData as $row) $createdMap[(string)$row['label']] = (int)$row['value'];
    $closedMap = [];
    foreach ($closedData as $row) $closedMap[(string)$row['label']] = (int)$row['value'];

    $allLabels = getTimeLabels($dateRange, $timeGrouping);
    $labels = [];
    $createdValues = [];
    $closedValues = [];

    foreach ($allLabels as $l) {
        $labels[] = formatLabel($l, $timeGrouping);
        $createdValues[] = $createdMap[$l] ?? 0;
        $closedValues[] = $closedMap[$l] ?? 0;
    }

    return [
        'labels' => $labels,
        'series' => [
            ['label' => 'Created', 'values' => $createdValues],
            ['label' => 'Closed', 'values' => $closedValues]
        ]
    ];
}

function pivotToSeries($data) {
    $labelsSet = [];
    $seriesNames = [];
    $rawMap = [];

    foreach ($data as $row) {
        $labelsSet[$row['label']] = true;
        $seriesNames[$row['series_val']] = true;
        $rawMap[$row['label']][$row['series_val']] = (int)$row['value'];
    }

    $labels = array_keys($labelsSet);
    $seriesNamesList = array_keys($seriesNames);

    $series = [];
    foreach ($seriesNamesList as $sn) {
        $values = [];
        foreach ($labels as $l) {
            $values[] = $rawMap[$l][$sn] ?? 0;
        }
        $series[] = ['label' => $sn, 'values' => $values];
    }

    return ['labels' => $labels, 'series' => $series];
}
?>
