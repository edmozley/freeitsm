<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * GH #194: widening software identity must preserve the installation on a grown
 * database, then admit multiple sources without admitting duplicates of one.
 * MySQL's connection-local TEMPORARY table shadows the real detail table here;
 * it is dropped in finally. Run after Database Verification has added the new
 * inventory schema. No persistent inventory data is modified by this test.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_verify_inventory.php';
$conn=connectToDatabase(); $pass=0; $fail=0;
$ok=function(string $label,bool $cond) use (&$pass,&$fail): void {
    if ($cond) { ++$pass; echo "PASS $label\n"; } else { ++$fail; echo "FAIL $label\n"; }
};
try {
    $conn->exec("CREATE TEMPORARY TABLE software_inventory_detail (
        id INT PRIMARY KEY, host_id INT NOT NULL, app_id INT NOT NULL,
        display_version VARCHAR(100), source VARCHAR(20) NOT NULL DEFAULT 'agent',
        UNIQUE KEY ux_software_detail_host_app (host_id,app_id)) ENGINE=InnoDB");
    $conn->exec("INSERT INTO software_inventory_detail VALUES (123,456,789,'existing','manual')");
    $changes=dbVerifyInventoryUpgrade($conn);
    $ok('grown software identity requires an actual upgrade', count($changes)>=2);
    $row=$conn->query('SELECT * FROM software_inventory_detail WHERE id=123')->fetch(PDO::FETCH_ASSOC);
    $ok('upgrade preserves row id, version and ownership', $row && $row['display_version']==='existing' && $row['source']==='manual');
    $conn->exec("INSERT INTO software_inventory_detail VALUES (124,456,789,'observed','rest:synthetic')");
    $ok('same installation can have another source', (int)$conn->query('SELECT COUNT(*) FROM software_inventory_detail')->fetchColumn()===2);
    $refused=false;
    try { $conn->exec("INSERT INTO software_inventory_detail VALUES (125,456,789,'duplicate','rest:synthetic')"); }
    catch (PDOException $e) { $refused=($e->errorInfo[1] ?? null)===1062; }
    $ok('same source still cannot duplicate an installation', $refused);
    $ok('second upgrade is a no-op', dbVerifyInventoryUpgrade($conn)===[]);
} finally { $conn->exec('DROP TEMPORARY TABLE IF EXISTS software_inventory_detail'); }
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
