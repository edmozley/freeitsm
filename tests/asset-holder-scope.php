<?php
/* Holder changes check the asset's company and the person's. Run on a TEST install only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('FREEITSM_ROOT') ?: dirname(__DIR__);
chdir($root);
require 'config.php'; require 'includes/functions.php'; require 'includes/tenancy.php'; require 'includes/services/assets.php';
$c = connectToDatabase();
$db = (string)$c->query('SELECT DATABASE()')->fetchColumn(); echo "database: $db\n";
if (!preg_match('/test/i', $db)) exit("STOP - test install only\n");
$pass = 0; $fail = 0;
function ok(string $w, bool $b, string $d = ''): void { global $pass, $fail; if ($b) { $pass++; echo "  PASS  $w\n"; } else { $fail++; echo "  FAIL  $w" . ($d ? "  <- $d" : '') . "\n"; } }
function refused(callable $f): bool { try { $f(); return false; } catch (ServiceError $e) { return true; } }
$t = 0; $users = []; $assets = [];
try {
    $c->exec("INSERT INTO tenants (name) VALUES ('ZZ holder scope test')"); $t = (int)$c->lastInsertId();
    $def = getDefaultTenantId($c);
    $mkA = function (string $h, int $tid) use ($c, &$assets) { $c->prepare("INSERT INTO assets (hostname, tenant_id) VALUES (?, ?)")->execute([$h, $tid]); return $assets[] = (int)$c->lastInsertId(); };
    $mkU = function (string $e, int $tid) use ($c, &$users) { $c->prepare("INSERT INTO users (email, display_name, tenant_id, is_active, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())")->execute([$e, $e, $tid]); return $users[] = (int)$c->lastInsertId(); };
    $assetHome = $mkA('ZZ-HS-HOME', $def); $assetOther = $mkA('ZZ-HS-OTHER', $t);
    $userHome = $mkU('zz-hs-home@example.invalid', $def); $userOther = $mkU('zz-hs-other@example.invalid', $t);
    $limited = ActorContext::fromApiKey(['analyst_id' => 1, 'company_scope' => [$def], 'analyst_name' => 'test']);
    $all     = ActorContext::fromApiKey(['analyst_id' => 1, 'company_scope' => null,   'analyst_name' => 'test']);

    echo "\nA caller limited to one company\n";
    ok("cannot assign a person to another company's asset", refused(fn() => AssetsService::assignUser($c, $limited, $assetOther, ['user_id' => $userHome])));
    ok("cannot assign a person from another company (by id)", refused(fn() => AssetsService::assignUser($c, $limited, $assetHome, ['user_id' => $userOther])));
    ok("cannot find a person from another company by email", refused(fn() => AssetsService::assignUser($c, $limited, $assetHome, ['user_email' => 'zz-hs-other@example.invalid'])));
    AssetsService::assignUser($c, $all, $assetOther, ['user_id' => $userOther]);
    ok("cannot unassign a holder of another company's asset", refused(fn() => AssetsService::unassignUser($c, $limited, $assetOther, $userOther)));
    ok("cannot assign an analyst to another company's asset", refused(fn() => AssetsService::assignAnalyst($c, $limited, $assetOther, ['analyst_id' => 1])));
    ok("cannot unassign an analyst from another company's asset", refused(fn() => AssetsService::unassignAnalyst($c, $limited, $assetOther, 1)));
    echo "\nStill works where it should\n";
    ok("assigns a person in its own company to its own asset", !refused(fn() => AssetsService::assignUser($c, $limited, $assetHome, ['user_id' => $userHome])));
    ok("unassigns them again", !refused(fn() => AssetsService::unassignUser($c, $limited, $assetHome, $userHome)));
    ok("an all-companies caller can unassign across companies", !refused(fn() => AssetsService::unassignUser($c, $all, $assetOther, $userOther)));
} finally {
    if ($assets) { $in = implode(',', array_map('intval', $assets));
        foreach (['users_assets', 'asset_custody_log', 'asset_history', 'analysts_assets'] as $tb) { try { $c->exec("DELETE FROM $tb WHERE asset_id IN ($in)"); } catch (Throwable $e) {} }
        $c->exec("DELETE FROM assets WHERE id IN ($in)"); }
    if ($users) $c->exec("DELETE FROM users WHERE id IN (" . implode(',', array_map('intval', $users)) . ")");
    if ($t) $c->prepare("DELETE FROM tenants WHERE id = ?")->execute([$t]);
    $left = (int)$c->query("SELECT (SELECT COUNT(*) FROM tenants WHERE name='ZZ holder scope test')+(SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZ-HS-%')+(SELECT COUNT(*) FROM users WHERE email LIKE 'zz-hs-%@example.invalid')")->fetchColumn();
    echo "\n"; ok('everything the test wrote is gone', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
