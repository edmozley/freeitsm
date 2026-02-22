<?php
/**
 * Module Colours - shared helper
 * Provides default and database-overridden module colours for dynamic CSS generation.
 * Included by waffle-menu.php and index.php.
 */

$defaultModuleColors = [
    'watchtower'     => ['#1e293b', '#0f172a'],
    'tickets'        => ['#0078d4', '#106ebe'],
    'assets'         => ['#107c10', '#0b5c0b'],
    'knowledge'      => ['#8764b8', '#6b4fa2'],
    'changes'        => ['#00897b', '#00695c'],
    'calendar'       => ['#ef6c00', '#e65100'],
    'morning-checks' => ['#00acc1', '#00838f'],
    'reporting'      => ['#ca5010', '#a5410a'],
    'software'       => ['#5c6bc0', '#3f51b5'],
    'forms'          => ['#00897b', '#00695c'],
    'contracts'      => ['#f59e0b', '#d97706'],
    'service-status' => ['#10b981', '#059669'],
    'wiki'           => ['#c62828', '#b71c1c'],
    'system'        => ['#546e7a', '#37474f'],
];

function getModuleColors() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    global $defaultModuleColors;
    $colors = $defaultModuleColors;

    try {
        if (!function_exists('connectToDatabase')) {
            require_once __DIR__ . '/functions.php';
        }
        $conn = connectToDatabase();
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'module_color_%'");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $moduleKey = substr($row['setting_key'], strlen('module_color_'));
            $parts = explode(',', $row['setting_value']);
            if (count($parts) === 2 && isset($colors[$moduleKey])) {
                $colors[$moduleKey] = [trim($parts[0]), trim($parts[1])];
            }
        }
    } catch (Exception $e) {
        // Fall back to defaults if DB unavailable
    }

    $cached = $colors;
    return $cached;
}
