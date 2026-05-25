<?php
/**
 * API: List all IANA timezones grouped by region, for the calendar modal dropdown.
 *
 * Returns: { groups: { 'Europe': ['Europe/London', 'Europe/Paris', ...], 'America': [...], ... } }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$all = timezone_identifiers_list();
$groups = [];
foreach ($all as $tz) {
    $parts = explode('/', $tz, 2);
    $region = count($parts) === 2 ? $parts[0] : 'Other';
    $groups[$region][] = $tz;
}
ksort($groups);
foreach ($groups as &$g) sort($g);

echo json_encode(['success' => true, 'groups' => $groups]);
