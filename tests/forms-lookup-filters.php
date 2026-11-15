<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Forms - a lookup narrowed by its filters (discussion #330): "Only objects of this
 * kind" on a CMDB lookup. The search and the submit check must agree, and a filter
 * must never apply where it does not belong.
 *
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/forms-lookup-filters.php
 *
 * Two ZZ CMDB classes (Job Position, Department) with objects in each. CONTROLS: the
 * same search and check WITHOUT the filter see both classes, so a narrowed result
 * means "narrowed", not "broken". Everything ZZ is removed in finally.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/forms.php';

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
if (!$expected || $expected !== $conn->query('SELECT DATABASE()')->fetchColumn()) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the throwaway database config.php points at.\n";
    exit(0);
}

$tag = 'zzlf' . bin2hex(random_bytes(3));
$classes = []; $objects = [];
try {
    $mkClass = function (string $name) use ($conn, &$classes, $tag): int {
        $conn->prepare("INSERT INTO cmdb_classes (class_key, name) VALUES (?, ?)")->execute(["{$tag}_" . strtolower(str_replace(' ', '_', $name)), "$tag $name"]);
        return $classes[] = (int)$conn->lastInsertId();
    };
    $mkObj = function (int $class, string $name) use ($conn, &$objects, $tag): int {
        $conn->prepare("INSERT INTO cmdb_objects (class_id, name, tenant_id) VALUES (?, ?, NULL)")->execute([$class, "$tag $name"]);
        return $objects[] = (int)$conn->lastInsertId();
    };
    $pos = $mkClass('Job Position');
    $dep = $mkClass('Department');
    $dev = $mkObj($pos, 'Developer');
    $acc = $mkObj($pos, 'Accountant');
    $it  = $mkObj($dep, 'IT');

    $field = ['field_type' => 'lookup', 'config' => json_encode(['lookup_source' => 'cmdb', 'lookup_filters' => [['type' => 'cmdb_class', 'class_id' => $pos]]])];
    $filters = FormsService::lookupFiltersOf($field);
    $ids = fn(array $rows) => array_map(fn($r) => $r['id'], $rows);

    echo "Reading the filters\n";
    check('a CMDB class filter is read', $filters === [['type' => 'cmdb_class', 'class_id' => $pos]], json_encode($filters));
    check('on an asset lookup the same filter is ignored', FormsService::lookupFiltersOf(['config' => ['lookup_source' => 'asset', 'lookup_filters' => [['type' => 'cmdb_class', 'class_id' => $pos]]]]) === []);
    check('an unknown kind of filter is ignored', FormsService::lookupFiltersOf(['config' => ['lookup_source' => 'cmdb', 'lookup_filters' => [['type' => 'sql', 'where' => '1=1']]]]) === []);

    echo "Searching\n";
    $narrow = $ids(FormsService::lookupSearch($conn, 'cmdb', $tag, null, 50, $filters));
    sort($narrow);
    $want = [$dev, $acc]; sort($want);
    check('only Job Positions are offered', $narrow === $want, json_encode($narrow));
    $all = $ids(FormsService::lookupSearch($conn, 'cmdb', $tag, null, 50));
    check('CONTROL: without the filter the Department is offered too', in_array($it, $all, true) && count($all) === 3, json_encode($all));

    echo "The submit check agrees\n";
    check('a Job Position is accepted', FormsService::lookupValueAllowed($conn, 'cmdb', $dev, null, $filters));
    check('a Department is refused, though it exists and is in scope', !FormsService::lookupValueAllowed($conn, 'cmdb', $it, null, $filters));
    check('CONTROL: without the filter the Department is accepted', FormsService::lookupValueAllowed($conn, 'cmdb', $it, null));

    echo "Saving a form\n";
    $v = new ReflectionMethod(FormsService::class, 'validateFieldConfig');
    $ok = $v->invoke(null, ['lookup_source' => 'cmdb', 'lookup_filters' => [['type' => 'cmdb_class', 'class_id' => (string)$pos]]], 0, [], [], 'lookup');
    check('a class filter is kept, its id an integer', ($ok['lookup_filters'] ?? null) === [['type' => 'cmdb_class', 'class_id' => $pos]], json_encode($ok));
    $any = $v->invoke(null, ['lookup_source' => 'cmdb', 'lookup_filters' => [['type' => 'cmdb_class', 'class_id' => 0]]], 0, [], [], 'lookup');
    check('"Any kind" stores nothing', !array_key_exists('lookup_filters', $any));
    try {
        $v->invoke(null, ['lookup_source' => 'asset', 'lookup_filters' => [['type' => 'cmdb_class', 'class_id' => $pos]]], 0, [], [], 'lookup');
        check('a class filter on an asset lookup is refused', false);
    } catch (ServiceError $e) { check('a class filter on an asset lookup is refused', true); }
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($objects) $conn->exec("DELETE FROM cmdb_objects WHERE id IN (" . implode(',', $objects) . ")");
    if ($classes) $conn->exec("DELETE FROM cmdb_classes WHERE id IN (" . implode(',', $classes) . ")");
    $left = (int)$conn->query("SELECT COUNT(*) FROM cmdb_objects WHERE name LIKE " . $conn->quote("$tag%"))->fetchColumn();
    check('cleaned up', $left === 0);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
