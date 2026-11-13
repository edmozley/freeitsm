<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A project's budget currency after the install's currency changes. A project keeps the
 * currency it was created in; with "Each project may choose its own currency" off it may
 * still be moved onto the install's currency (and only that one), so it is never stuck in
 * an old default. Through the real service.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/project-currency.php
 *
 * Writes a ZZPCUR project and two settings, and removes or restores them in finally.
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }
/** The kind of ServiceError a call throws, or 'ok'. */
function throws(callable $fn): string { try { $fn(); return 'ok'; } catch (ServiceError $e) { return $e->kind; } }

$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/services/projects.php';
require_once 'includes/services/project_tools.php';
require_once 'includes/projects/budget.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_admin = 1 AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION = ['analyst_id' => $admin, 'analyst_name' => 'ZZPCUR test', 'is_admin' => 1];
$ctx = ActorContext::fromSession($conn);
$projectId = null;
$oldSettings = [];
foreach (['project_currency', 'project_currency_per_project'] as $k) {
    $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $s->execute([$k]); $oldSettings[$k] = $s->fetchColumn();
}
$setSetting = function (string $k, $v) use ($conn) {
    $v === null || $v === false
        ? $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k])
        : $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
    projectSettings($conn, true);
};
$cur = function () use ($conn, &$projectId) { $s = $conn->prepare("SELECT currency FROM projects WHERE id = ?"); $s->execute([$projectId]); return $s->fetchColumn(); };
$set = function (string $code) use ($conn, $ctx, &$projectId) { return throws(fn() => ProjectToolsService::setCurrency($conn, $ctx, $projectId, $code)); };

try {
    $setSetting('project_currency_per_project', '0');
    $setSetting('project_currency', 'EUR');
    $projectId = ProjectsService::createProject($conn, $ctx, ['name' => 'ZZPCUR currency test'], null);
    check('a new project is stamped with the install currency', $cur() === 'EUR', (string)$cur());

    echo "The install's currency changes (one currency for every project)\n";
    $setSetting('project_currency', 'USD');
    check('the project keeps the currency it was created in', $cur() === 'EUR', (string)$cur());
    check('another currency is still refused', $set('GBP') === 'forbidden', $set('GBP'));
    check('the install\'s currency is allowed', $set('USD') === 'ok', $set('USD'));
    check('the project is now in the install\'s currency', $cur() === 'USD', (string)$cur());
    check('moving away from the install\'s currency is refused', $set('EUR') === 'forbidden');
    $a = $conn->prepare("SELECT old_value, new_value FROM project_audit WHERE project_id = ? AND field_name = 'currency' ORDER BY id DESC LIMIT 1");
    $a->execute([$projectId]);
    check('the change is in the project\'s history', $a->fetch(PDO::FETCH_NUM) === ['EUR', 'USD']);

    echo "Each project may choose\n";
    $setSetting('project_currency_per_project', '1');
    check('any currency is allowed', $set('GBP') === 'ok' && $cur() === 'GBP', (string)$cur());
    check('an unknown code is refused', $set('ZZZ') === 'validation');
} finally {
    if ($projectId) {
        try { ProjectsService::deleteProject($conn, $ctx, $projectId); } catch (Throwable $e) { echo "  cleanup: project delete failed: " . $e->getMessage() . "\n"; }
    }
    foreach ($oldSettings as $k => $v) $setSetting($k, $v);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
