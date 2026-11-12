<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * CMDB relationships read the right way round everywhere they are put into words (5.1.0).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=<that install's URL> php tests/cmdb-relationship-direction.php
 *
 * A relationship is stored source -> target with a verb ("runs on") and an inverse ("hosts").
 * PR #297 fixed the object page, which had labelled a host's VM "hosts this". The same mistake
 * was in two more places, fixed here:
 *   - the AI summary of an object (includes/cmdb_summary_prompt.php) told the AI "VM-A - hosts
 *     this", under a heading that called every incoming link a dependency;
 *   - Network Mapper's "related objects" picker (api/network-mapper/get_related_objects.php)
 *     labelled an incoming link with the inverse, and the connector it drew went VM -> host, so
 *     the arrow read "VM-A hosts HOST-A".
 * Makes a ZZ class, three ZZ objects, a ZZ relationship type and two links, plus a ZZ analyst
 * with a session file; all removed in finally.
 *
 * @wiki CMDB-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cmdb_summary_prompt.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
$url = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
$db = $conn->query('SELECT DATABASE()')->fetchColumn();
if (!$expected || $expected !== $db || $url === '') {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE (the throwaway database config.php uses, {$db}) and FREEITSM_TEST_URL.\n";
    exit(0);
}

$tag = 'zzrel' . bin2hex(random_bytes(3));
$sessDir = getenv('FREEITSM_SESS_DIR') ?: 'C:/wamp64/tmp';
$classId = 0; $objs = []; $typeId = 0; $analyst = 0;
try {
    $conn->prepare("INSERT INTO cmdb_classes (class_key, name) VALUES (?, 'Server')")->execute([$tag]);
    $classId = (int)$conn->lastInsertId();
    $mk = function (string $name) use ($conn, $classId, &$objs): int {
        $conn->prepare("INSERT INTO cmdb_objects (class_id, name) VALUES (?, ?)")->execute([$classId, $name]);
        return $objs[] = (int)$conn->lastInsertId();
    };
    $host = $mk("ZZ-HOST-$tag");
    $vm   = $mk("ZZ-VM-$tag");
    $app  = $mk("ZZ-APP-$tag");
    $conn->prepare("INSERT INTO cmdb_relationship_types (verb, inverse_verb, description) VALUES ('runs on', 'hosts', ?)")->execute([$tag]);
    $typeId = (int)$conn->lastInsertId();
    $rel = $conn->prepare("INSERT INTO cmdb_object_relationships (from_object_id, to_object_id, relationship_type_id) VALUES (?, ?, ?)");
    $rel->execute([$vm, $host, $typeId]);    // the VM runs on the host
    $rel->execute([$app, $vm, $typeId]);     // the app runs on the VM

    echo "What the AI is told\n";
    $forHost = cmdbObjectSummaryMessage($conn, $host);
    check('the host: "ZZ-VM runs on ZZ-HOST" - source first, forward verb', str_contains($forHost, "ZZ-VM-$tag (Server) runs on ZZ-HOST-$tag"), $forHost);
    check('...never "hosts this" (the inverse fitted round "this")', !str_contains($forHost, 'hosts this'));
    check('...and incoming links are not all called dependencies', !str_contains($forHost, 'What depends on this object'));
    $forVm = cmdbObjectSummaryMessage($conn, $vm);
    check('the VM, its own link: "ZZ-VM runs on ZZ-HOST"', str_contains($forVm, "ZZ-VM-$tag runs on ZZ-HOST-$tag (Server)"), $forVm);
    check('...and the link into it: "ZZ-APP runs on ZZ-VM"', str_contains($forVm, "ZZ-APP-$tag (Server) runs on ZZ-VM-$tag"), $forVm);
    $sys = file_get_contents(__DIR__ . '/../api/cmdb/generate_object_summary.php');
    check('the AI is told to keep each sentence\'s direction', str_contains($sys, 'never reverse it'));

    echo "\nNetwork Mapper's related objects (the real endpoint)\n";
    $conn->exec("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES ('$tag', '!', 'ZZ rel', '$tag@example.invalid', 1, 1, 1, 1)");
    $analyst = (int)$conn->lastInsertId();
    file_put_contents("$sessDir/sess_$tag", 'analyst_id|i:' . $analyst . ';analyst_username|s:' . strlen($tag) . ':"' . $tag . '";analyst_name|s:6:"ZZ rel";csrf_token|s:1:"x";');
    $ch = curl_init("$url/api/network-mapper/get_related_objects.php?object_id=$host");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => "PHPSESSID=$tag", CURLOPT_TIMEOUT => 20]);
    $body = (string)curl_exec($ch);
    $d = json_decode($body, true);
    $rows = array_column($d['related'] ?? $d['objects'] ?? [], null, 'object_id');
    $vmRow = $rows[$vm] ?? null;
    check('the VM is listed under incoming (others -> this object)', ($vmRow['kind'] ?? '') === 'incoming', substr($body, 0, 300));
    check('...labelled "runs on", so the connector VM -> host reads "runs on"', ($vmRow['label'] ?? '') === 'runs on', json_encode($vmRow));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($objs) {
        $in = implode(',', $objs);
        $conn->exec("DELETE FROM cmdb_object_relationships WHERE from_object_id IN ($in) OR to_object_id IN ($in)");
        $conn->exec("DELETE FROM cmdb_objects WHERE id IN ($in)");
    }
    if ($typeId) $conn->exec("DELETE FROM cmdb_relationship_types WHERE id = $typeId");
    if ($classId) $conn->exec("DELETE FROM cmdb_classes WHERE id = $classId");
    if ($analyst) $conn->exec("DELETE FROM analysts WHERE id = $analyst");
    @unlink("$sessDir/sess_$tag");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
