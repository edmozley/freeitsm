<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Four access-scope fixes for 5.1.0, each checked against the real functions.
 *
 *   FREEITSM_TEST_DATABASE=<the throwaway database config.php uses> php tests/access-scope-5-1.php
 *
 * 1. A DEACTIVATED team grants nothing: no modules, no module level, no companies (all, or a
 *    listed one), no roles, no Knowledge / Report Pack shares, no change template.
 * 2. A CMDB blast radius in one company never takes in an object with no company: NULL means
 *    the default company, not "whichever company is being walked".
 * 3. (Not a bug, pinned down:) a directory provider with no company cannot match the wrong
 *    company's person by email, because users.email is unique install-wide.
 * 4. A Network Mapper connector only accepts a CMDB relationship between its own two objects.
 *
 * Everything is ZZ-tagged and removed in finally. Separate ZZ analysts are used per case, because
 * the access functions cache per analyst for the request.
 *
 * @wiki Multi-Tenancy-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/cmdb_impact.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/network_mapper.php';
require_once __DIR__ . '/../includes/changes/templates.php';

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
$db = $conn->query('SELECT DATABASE()')->fetchColumn();
if (!$expected || $expected !== $db) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the throwaway database config.php uses ({$db}).\n";
    exit(0);
}

$tag = 'zzacc' . bin2hex(random_bytes(3));
$made = ['analysts' => [], 'teams' => [], 'tenants' => [], 'roles' => [], 'users' => [], 'cmdb_objects' => [], 'classes' => [], 'rels' => [], 'reltypes' => [], 'diagrams' => []];
$oldMode = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'module_permission_mode'")->fetchColumn();
try {
    $conn->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('module_permission_mode', 'most') ON DUPLICATE KEY UPDATE setting_value = 'most'");
    $mkAnalyst = function (string $who) use ($conn, $tag, &$made): int {
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', ?, ?, 1, 0, 0, 0)")
             ->execute(["$tag$who", "ZZ $who", "$tag$who@example.invalid"]);
        return $made['analysts'][] = (int)$conn->lastInsertId();
    };
    $mkTeam = function (string $name, int $active, int $allTenants = 0) use ($conn, $tag, &$made): int {
        $conn->prepare("INSERT INTO teams (name, is_active, can_access_all_modules, can_access_all_tenants) VALUES (?, ?, 0, ?)")->execute(["$tag $name", $active, $allTenants]);
        return $made['teams'][] = (int)$conn->lastInsertId();
    };
    $join = fn(int $analyst, int $team) => $conn->prepare("INSERT INTO analyst_teams (analyst_id, team_id) VALUES (?, ?)")->execute([$analyst, $team]);

    $conn->prepare("INSERT INTO tenants (name) VALUES (?)")->execute(["$tag company"]);
    $zzTenant = $made['tenants'][] = (int)$conn->lastInsertId();
    $default = (int)getDefaultTenantId($conn);

    $conn->prepare("INSERT INTO rbac_roles (name, is_active) VALUES (?, 1)")->execute(["$tag role"]);
    $role = $made['roles'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO rbac_role_capabilities (role_id, capability_key) VALUES (?, ?)")->execute([$role, capKey(Cap::TICKETS_CATEGORIES)]);

    echo "1. A deactivated team grants nothing\n";
    foreach (['active' => 1, 'retired' => 0] as $label => $isActive) {
        $a = $mkAnalyst($label);
        $t = $mkTeam("$label team", $isActive);
        $join($a, $t);
        $conn->prepare("INSERT INTO team_modules (team_id, module_key, access_level) VALUES (?, 'cmdb', 'full')")->execute([$t]);
        $conn->prepare("INSERT INTO team_tenant_access (team_id, tenant_id) VALUES (?, ?)")->execute([$t, $zzTenant]);
        $conn->prepare("INSERT INTO rbac_team_roles (team_id, role_id) VALUES (?, ?)")->execute([$t, $role]);
        $mods = getAnalystAllowedModules($conn, $a);
        $tenants = getAccessibleTenantIds($conn, $a);
        $caps = getAnalystCapabilities($conn, $a);
        $level = analystModuleLevel($conn, $a, 'cmdb');
        $grants = is_array($mods) && in_array('cmdb', $mods, true);
        if ($isActive) {
            check('an ACTIVE team grants its module (control)', $grants, json_encode($mods));
            check('...its module level', $level === 'full', var_export($level, true));
            check('...its listed company', in_array($zzTenant, $tenants, true), json_encode($tenants));
            check('...and its role', in_array(capKey(Cap::TICKETS_CATEGORIES), $caps, true));
        } else {
            check('a RETIRED team grants no module', !$grants, json_encode($mods));
            check('...no module level', $level === null, var_export($level, true));
            check('...no company', !in_array($zzTenant, $tenants, true), json_encode($tenants));
            check('...and no role', !in_array(capKey(Cap::TICKETS_CATEGORIES), $caps, true));
        }
    }
    $a = $mkAnalyst('allcos');
    $join($a, $mkTeam('all companies, retired', 0, 1));
    check('a retired team with "every company" does not give every company', !in_array($zzTenant, getAccessibleTenantIds($conn, $a), true));
    $a = $mkAnalyst('tpl');
    $retired = $mkTeam('template team, retired', 0);
    $join($a, $retired);
    check('a change template for a retired team cannot be used by its old members', !ChangeTemplates::mayUse($conn, ['team_ids' => [$retired]], $a));
    $b = $mkAnalyst('tplok');
    $live = $mkTeam('template team, active', 1);
    $join($b, $live);
    check('...one for an active team can (control)', ChangeTemplates::mayUse($conn, ['team_ids' => [$live]], $b));

    echo "\n2. A CMDB blast radius stays in its company\n";
    $conn->prepare("INSERT INTO cmdb_classes (class_key, name) VALUES (?, 'Server')")->execute([$tag]);
    $cls = $made['classes'][] = (int)$conn->lastInsertId();
    $obj = function (string $name, ?int $tenant, ?int $parent) use ($conn, $cls, &$made): int {
        $conn->prepare("INSERT INTO cmdb_objects (class_id, name, tenant_id, parent_id) VALUES (?, ?, ?, ?)")->execute([$cls, $name, $tenant, $parent]);
        return $made['cmdb_objects'][] = (int)$conn->lastInsertId();
    };
    $root = $obj("ZZ-ROOT-$tag", $zzTenant, null);
    $mine = $obj("ZZ-MINE-$tag", $zzTenant, $root);
    $noCo = $obj("ZZ-NOCOMPANY-$tag", null, $root);
    $ids = array_column(cmdbBlastRadius($conn, $root, $zzTenant)['nodes'], 'id');
    $ids = array_map('intval', $ids);
    check('a child in the same company is reached (control)', in_array($mine, $ids, true), json_encode($ids));
    check('a child with NO company (the default company\'s) is not', !in_array($noCo, $ids, true), json_encode($ids));
    if ($default !== $zzTenant) {
        $rootD = $obj("ZZ-ROOTD-$tag", null, null);
        $childD = $obj("ZZ-CHILDD-$tag", null, $rootD);
        $idsD = array_map('intval', array_column(cmdbBlastRadius($conn, $rootD, $default)['nodes'], 'id'));
        check('...while a default-company walk still reaches objects with no company', in_array($childD, $idsD, true));
    }

    echo "\n3. A directory with no company cannot cross companies by email\n";
    // Reported on 2026-10-14 as a bug: a provider with no company matches email in any company.
    // It cannot pick the WRONG company's person, because an address belongs to one person on the
    // whole install. This pins the reason down so nobody chases it again.
    $uq = $conn->query("SHOW INDEX FROM users WHERE Key_name = 'uq_users_email' AND Non_unique = 0")->fetch();
    check('an email address is unique install-wide (uq_users_email), so an email match finds at most one person', (bool)$uq);

    echo "\n4. A Network Mapper connector and its relationship\n";
    $conn->prepare("INSERT INTO cmdb_relationship_types (verb, inverse_verb, description) VALUES ('runs on', 'hosts', ?)")->execute([$tag]);
    $rt = $made['reltypes'][] = (int)$conn->lastInsertId();
    $x = $obj("ZZ-X-$tag", null, null); $y = $obj("ZZ-Y-$tag", null, null); $z = $obj("ZZ-Z-$tag", null, null);
    $rel = $conn->prepare("INSERT INTO cmdb_object_relationships (from_object_id, to_object_id, relationship_type_id) VALUES (?, ?, ?)");
    $rel->execute([$x, $y, $rt]); $relXY = $made['rels'][] = (int)$conn->lastInsertId();
    $rel->execute([$y, $z, $rt]); $relYZ = $made['rels'][] = (int)$conn->lastInsertId();
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ tester');
    $diagram = function (int $relId) use ($conn, $ctx, $x, $y, $tag, &$made) {
        try {
            $id = NetworkMapperService::createDiagram($conn, $ctx, [
                'title' => "$tag diagram",
                'nodes' => [['cmdb_object_id' => $x], ['cmdb_object_id' => $y]],
                'connectors' => [['from_object_id' => $x, 'to_object_id' => $y, 'cmdb_relationship_id' => $relId]],
            ]);
            $made['diagrams'][] = $id;
            return 'ok';
        } catch (ServiceError $e) {
            $d = $conn->query("SELECT id FROM network_diagrams WHERE title = " . $conn->quote("$tag diagram"))->fetchAll(PDO::FETCH_COLUMN);
            foreach ($d as $i) $made['diagrams'][] = (int)$i;
            return $e->getMessage();
        }
    };
    check('the relationship between the two objects is accepted (control)', $diagram($relXY) === 'ok');
    $r = $diagram($relYZ);
    check('a relationship between OTHER objects is refused', str_contains($r, 'not a relationship between these two objects'), $r);
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach (array_unique($made['diagrams']) as $d) {
        $conn->exec("DELETE FROM network_diagram_connectors WHERE diagram_id = $d");
        $conn->exec("DELETE FROM network_diagram_nodes WHERE diagram_id = $d");
        try { $conn->exec("DELETE FROM network_diagrams WHERE id = $d"); } catch (Throwable) {}
    }
    if ($made['rels']) $conn->exec("DELETE FROM cmdb_object_relationships WHERE id IN (" . implode(',', $made['rels']) . ")");
    foreach (array_reverse($made['cmdb_objects']) as $o) { try { $conn->exec("DELETE FROM cmdb_objects WHERE id = $o"); } catch (Throwable) {} }
    if ($made['reltypes']) $conn->exec("DELETE FROM cmdb_relationship_types WHERE id IN (" . implode(',', $made['reltypes']) . ")");
    if ($made['classes']) $conn->exec("DELETE FROM cmdb_classes WHERE id IN (" . implode(',', $made['classes']) . ")");
    if ($made['users']) $conn->exec("DELETE FROM users WHERE id IN (" . implode(',', $made['users']) . ")");
    if ($made['teams']) {
        $in = implode(',', $made['teams']);
        foreach (['analyst_teams', 'team_modules', 'team_tenant_access', 'rbac_team_roles'] as $t) $conn->exec("DELETE FROM $t WHERE team_id IN ($in)");
        $conn->exec("DELETE FROM teams WHERE id IN ($in)");
    }
    if ($made['roles']) {
        $in = implode(',', $made['roles']);
        $conn->exec("DELETE FROM rbac_role_capabilities WHERE role_id IN ($in)");
        $conn->exec("DELETE FROM rbac_roles WHERE id IN ($in)");
    }
    if ($made['analysts']) $conn->exec("DELETE FROM analysts WHERE id IN (" . implode(',', $made['analysts']) . ")");
    if ($made['tenants']) $conn->exec("DELETE FROM tenants WHERE id IN (" . implode(',', $made['tenants']) . ")");
    if ($oldMode === false) $conn->exec("DELETE FROM system_settings WHERE setting_key = 'module_permission_mode'");
    else $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'module_permission_mode'")->execute([$oldMode]);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
