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
    $wStmt = $conn->prepare("SELECT aggregate_property, series_property, is_status_filterable, default_status FROM ticket_dashboard_widgets WHERE id = ?");
    $wStmt->execute([$widget_id]);
    $widget = $wStmt->fetch(PDO::FETCH_ASSOC);

    if (!$widget) {
        echo json_encode(['success' => false, 'error' => 'Widget not found']);
        exit;
    }

    $prop = $widget['aggregate_property'];
    $seriesProp = $widget['series_property'];
    $params = [];
    $where = '';

    // Apply status filter
    if (!empty($statusFilter) && $widget['is_status_filterable']) {
        $where = ' WHERE t.status = ?';
        $params[] = $statusFilter;
    } elseif (!$widget['is_status_filterable'] && $widget['default_status']) {
        $where = ' WHERE t.status = ?';
        $params[] = $widget['default_status'];
    }

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

    // --- Time-based (daily/monthly), single series ---
    if (!$seriesProp && isTimeBased($prop)) {
        $result = getTimeData($conn, $prop, $where, $params);
        echo json_encode(['success' => true, 'labels' => $result['labels'], 'values' => $result['values']]);
        exit;
    }

    // --- Time-based with series breakdown ---
    if ($seriesProp && isTimeBased($prop)) {
        $result = getTimeWithSeries($conn, $prop, $seriesProp, $where, $params);
        echo json_encode(['success' => true, 'labels' => $result['labels'], 'series' => $result['series']]);
        exit;
    }

    // --- Created vs Closed (inherent 2-series) ---
    if (isCreatedVsClosed($prop)) {
        $result = getCreatedVsClosedData($conn, $prop, $where, $params);
        echo json_encode(['success' => true, 'labels' => $result['labels'], 'series' => $result['series']]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unsupported aggregate type']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// --- Helper functions ---

function isTimeBased($prop) {
    return in_array($prop, ['created_daily', 'created_monthly', 'closed_daily', 'closed_monthly']);
}

function isCreatedVsClosed($prop) {
    return in_array($prop, ['created_vs_closed_daily', 'created_vs_closed_monthly']);
}

function getDateField($prop) {
    if (strpos($prop, 'closed') === 0) return 'closed_datetime';
    return 'created_datetime';
}

function isDaily($prop) {
    return strpos($prop, 'daily') !== false;
}

function getCategoricalData($conn, $prop, $where, $params) {
    $allowedSimple = ['status', 'priority'];
    $allowedJoin = ['department', 'ticket_type', 'analyst', 'owner', 'origin'];
    $allowedBool = ['first_time_fix', 'training_provided'];

    if (in_array($prop, $allowedSimple)) {
        $col = $prop === 'status' ? 't.status' : 't.priority';
        $sql = "SELECT COALESCE({$col}, 'Unknown') AS label, COUNT(*) AS value FROM tickets t {$where} GROUP BY {$col} ORDER BY value DESC";
    } elseif ($prop === 'department') {
        $sql = "SELECT COALESCE(d.name, 'Unassigned') AS label, COUNT(*) AS value FROM tickets t LEFT JOIN departments d ON d.id = t.department_id {$where} GROUP BY d.name ORDER BY value DESC";
    } elseif ($prop === 'ticket_type') {
        $sql = "SELECT COALESCE(tt.name, 'Unassigned') AS label, COUNT(*) AS value FROM tickets t LEFT JOIN ticket_types tt ON tt.id = t.ticket_type_id {$where} GROUP BY tt.name ORDER BY value DESC";
    } elseif ($prop === 'analyst') {
        $sql = "SELECT COALESCE(a.name, 'Unassigned') AS label, COUNT(*) AS value FROM tickets t LEFT JOIN analysts a ON a.id = t.assigned_analyst_id {$where} GROUP BY a.name ORDER BY value DESC";
    } elseif ($prop === 'owner') {
        $sql = "SELECT COALESCE(a.name, 'Unassigned') AS label, COUNT(*) AS value FROM tickets t LEFT JOIN analysts a ON a.id = t.owner_id {$where} GROUP BY a.name ORDER BY value DESC";
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
    // Get the label expression and JOIN for the primary aggregate
    $labelExpr = '';
    $join = '';
    $groupCol = '';

    if ($prop === 'department') {
        $labelExpr = "COALESCE(d.name, 'Unassigned')";
        $join = 'LEFT JOIN departments d ON d.id = t.department_id';
        $groupCol = 'd.name';
    } elseif ($prop === 'ticket_type') {
        $labelExpr = "COALESCE(tt.name, 'Unassigned')";
        $join = 'LEFT JOIN ticket_types tt ON tt.id = t.ticket_type_id';
        $groupCol = 'tt.name';
    } elseif ($prop === 'analyst') {
        $labelExpr = "COALESCE(a.name, 'Unassigned')";
        $join = 'LEFT JOIN analysts a ON a.id = t.assigned_analyst_id';
        $groupCol = 'a.name';
    } elseif ($prop === 'owner') {
        $labelExpr = "COALESCE(a.name, 'Unassigned')";
        $join = 'LEFT JOIN analysts a ON a.id = t.owner_id';
        $groupCol = 'a.name';
    } elseif ($prop === 'origin') {
        $labelExpr = "COALESCE(o.name, 'Unknown')";
        $join = 'LEFT JOIN ticket_origins o ON o.id = t.origin_id';
        $groupCol = 'o.name';
    } elseif ($prop === 'priority') {
        $labelExpr = "COALESCE(t.priority, 'Unknown')";
        $join = '';
        $groupCol = 't.priority';
    } else {
        return ['labels' => [], 'series' => []];
    }

    // Series column
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

function getTimeData($conn, $prop, $where, $params) {
    $dateField = getDateField($prop);
    $daily = isDaily($prop);

    if ($daily) {
        $firstOfMonth = date('Y-m-01');
        $dateExpr = "DATE(t.{$dateField})";
        $dateWhere = "t.{$dateField} >= ?";
        $dateParams = array_merge($params, [$firstOfMonth]);
        // Adjust WHERE
        if ($where) {
            $fullWhere = $where . " AND {$dateWhere}";
        } else {
            $fullWhere = " WHERE {$dateWhere}";
        }
        // For closed_daily, also require date field is not null
        if ($dateField === 'closed_datetime') {
            $fullWhere .= " AND t.{$dateField} IS NOT NULL";
        }
    } else {
        $twelveMonthsAgo = date('Y-m-01', strtotime('-11 months'));
        $dateExpr = "DATE_FORMAT(t.{$dateField}, '%Y-%m')";
        $dateWhere = "t.{$dateField} >= ?";
        $dateParams = array_merge($params, [$twelveMonthsAgo]);
        if ($where) {
            $fullWhere = $where . " AND {$dateWhere}";
        } else {
            $fullWhere = " WHERE {$dateWhere}";
        }
        if ($dateField === 'closed_datetime') {
            $fullWhere .= " AND t.{$dateField} IS NOT NULL";
        }
    }

    $sql = "SELECT {$dateExpr} AS label, COUNT(*) AS value FROM tickets t {$fullWhere} GROUP BY label ORDER BY label";

    $stmt = $conn->prepare($sql);
    $stmt->execute($dateParams);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fill gaps
    $dataMap = [];
    foreach ($data as $row) {
        $dataMap[$row['label']] = (int)$row['value'];
    }

    $allLabels = $daily ? getDailyLabels() : getMonthlyLabels();
    $labels = [];
    $values = [];
    foreach ($allLabels as $l) {
        $labels[] = $daily ? formatDailyLabel($l) : formatMonthlyLabel($l);
        $values[] = $dataMap[$l] ?? 0;
    }

    return ['labels' => $labels, 'values' => $values];
}

function getTimeWithSeries($conn, $prop, $seriesProp, $where, $params) {
    $dateField = getDateField($prop);
    $daily = isDaily($prop);
    $seriesCol = $seriesProp === 'status' ? "COALESCE(t.status, 'Unknown')" : "COALESCE(t.priority, 'Unknown')";

    if ($daily) {
        $firstOfMonth = date('Y-m-01');
        $dateExpr = "DATE(t.{$dateField})";
        $dateWhere = "t.{$dateField} >= ?";
        $dateParams = array_merge($params, [$firstOfMonth]);
    } else {
        $twelveMonthsAgo = date('Y-m-01', strtotime('-11 months'));
        $dateExpr = "DATE_FORMAT(t.{$dateField}, '%Y-%m')";
        $dateWhere = "t.{$dateField} >= ?";
        $dateParams = array_merge($params, [$twelveMonthsAgo]);
    }

    if ($where) {
        $fullWhere = $where . " AND {$dateWhere}";
    } else {
        $fullWhere = " WHERE {$dateWhere}";
    }
    if ($dateField === 'closed_datetime') {
        $fullWhere .= " AND t.{$dateField} IS NOT NULL";
    }

    $sql = "SELECT {$dateExpr} AS label, {$seriesCol} AS series_val, COUNT(*) AS value
            FROM tickets t {$fullWhere}
            GROUP BY label, series_val
            ORDER BY label, series_val";

    $stmt = $conn->prepare($sql);
    $stmt->execute($dateParams);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all unique series values
    $seriesNames = [];
    $rawMap = [];
    foreach ($data as $row) {
        $seriesNames[$row['series_val']] = true;
        $rawMap[$row['label']][$row['series_val']] = (int)$row['value'];
    }
    $seriesNames = array_keys($seriesNames);

    // Fill gaps in time labels
    $allLabels = $daily ? getDailyLabels() : getMonthlyLabels();
    $labels = [];
    $seriesData = [];
    foreach ($seriesNames as $sn) {
        $seriesData[$sn] = [];
    }

    foreach ($allLabels as $l) {
        $labels[] = $daily ? formatDailyLabel($l) : formatMonthlyLabel($l);
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

function getCreatedVsClosedData($conn, $prop, $where, $params) {
    $daily = isDaily($prop);

    if ($daily) {
        $start = date('Y-m-01');
        $dateExpr = "DATE(t.%s)";
        $dateWhere = "t.%s >= ?";
    } else {
        $start = date('Y-m-01', strtotime('-11 months'));
        $dateExpr = "DATE_FORMAT(t.%s, '%%Y-%%m')";
        $dateWhere = "t.%s >= ?";
    }

    // Created counts
    $createdWhere = $where ? $where . " AND " . sprintf($dateWhere, 'created_datetime') : " WHERE " . sprintf($dateWhere, 'created_datetime');
    $sqlCreated = "SELECT " . sprintf($dateExpr, 'created_datetime') . " AS label, COUNT(*) AS value FROM tickets t {$createdWhere} GROUP BY label ORDER BY label";
    $stmt = $conn->prepare($sqlCreated);
    $stmt->execute(array_merge($params, [$start]));
    $createdData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Closed counts
    $closedWhere = $where ? $where . " AND " . sprintf($dateWhere, 'closed_datetime') . " AND t.closed_datetime IS NOT NULL" : " WHERE " . sprintf($dateWhere, 'closed_datetime') . " AND t.closed_datetime IS NOT NULL";
    $sqlClosed = "SELECT " . sprintf($dateExpr, 'closed_datetime') . " AS label, COUNT(*) AS value FROM tickets t {$closedWhere} GROUP BY label ORDER BY label";
    $stmt = $conn->prepare($sqlClosed);
    $stmt->execute(array_merge($params, [$start]));
    $closedData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $createdMap = [];
    foreach ($createdData as $row) $createdMap[$row['label']] = (int)$row['value'];
    $closedMap = [];
    foreach ($closedData as $row) $closedMap[$row['label']] = (int)$row['value'];

    $allLabels = $daily ? getDailyLabels() : getMonthlyLabels();
    $labels = [];
    $createdValues = [];
    $closedValues = [];

    foreach ($allLabels as $l) {
        $labels[] = $daily ? formatDailyLabel($l) : formatMonthlyLabel($l);
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

function getDailyLabels() {
    $labels = [];
    $start = new DateTime(date('Y-m-01'));
    $end = new DateTime();
    while ($start <= $end) {
        $labels[] = $start->format('Y-m-d');
        $start->modify('+1 day');
    }
    return $labels;
}

function getMonthlyLabels() {
    $labels = [];
    $start = new DateTime(date('Y-m-01', strtotime('-11 months')));
    $end = new DateTime(date('Y-m-01'));
    while ($start <= $end) {
        $labels[] = $start->format('Y-m');
        $start->modify('+1 month');
    }
    return $labels;
}

function formatDailyLabel($dateStr) {
    $d = new DateTime($dateStr);
    return $d->format('j M');
}

function formatMonthlyLabel($dateStr) {
    $d = new DateTime($dateStr . '-01');
    return $d->format('M Y');
}
?>
