<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Approver and reviewer access (#180 Part 3, slice E) - everyday-work permissions in
 * Roles ("Choose approvers", "Design forms"), and a catalogue approver reaching the
 * approvals inbox without the Forms module.
 *
 *   php tests/approver-access.php [base_url]
 *
 * Runs against the real database. Everything it makes is named ZZ and is removed in the
 * finally block: a role, its grant, a form, a change. Settings it switches are put back.
 * Pass a base URL (e.g. http://localhost/freeitsm-app/) to include the HTTP checks.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/services/changes.php';
require_once __DIR__ . '/../includes/services/forms.php';
require_once __DIR__ . '/../includes/catalogue_approvals.php';

$BASE = isset($argv[1]) ? rtrim($argv[1], '/') . '/' : null;
$pass = 0; $fail = 0;
/**
 * Records one check.
 *
 * @param string $what  what is being proved
 * @param bool $cond  true when it holds
 * @param string $detail  shown on failure
 */
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

$conn = connectToDatabase();
$saved = [];
/** Sets one setting, remembering what was there. */
function zzSet(PDO $conn, string $key, string $value): void {
    global $saved;
    if (!array_key_exists($key, $saved)) {
        $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        $saved[$key] = $v === false ? null : $v;
    }
    $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$key]);
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $value]);
}
/** Puts back every setting zzSet() changed. */
function zzRestore(PDO $conn): void {
    global $saved;
    foreach ($saved as $key => $old) {
        $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$key]);
        if ($old !== null) $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $old]);
    }
    $saved = [];
}
$code = function (callable $fn): string {
    try { $fn(); return ''; } catch (ServiceError $e) { return $e->errorCode; }
};

// Two non-admins: one without Forms (the approver), one never asked about before the
// grant (capabilities are cached per request, so the grant must come first for it).
$plain = (int)$conn->query("SELECT a.id FROM analysts a WHERE a.is_active = 1 AND a.is_admin = 0
    AND NOT EXISTS (SELECT 1 FROM analyst_modules m WHERE m.analyst_id = a.id AND m.module_key = 'forms')
    AND a.can_access_all_modules = 0 ORDER BY a.id LIMIT 1")->fetchColumn();
$granted = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 0 AND id <> {$plain} ORDER BY id DESC LIMIT 1")->fetchColumn();
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$plain || !$granted || !$admin) { echo "Needs a non-admin without Forms, a second non-admin and an admin.\n"; exit(1); }
$ctxPlain = new ActorContext($plain, null, 'ui', 'en', 'ZZ plain');
$ctxGranted = new ActorContext($granted, null, 'ui', 'en', 'ZZ granted');
$ctxAdmin = new ActorContext($admin, null, 'ui', 'en', 'ZZ admin');
$made = ['changes' => [], 'forms' => [], 'role' => null];

echo "\nApprover and reviewer access (#180 Part 3)\n" . str_repeat('=', 70) . "\n";
try {
    echo "\nEveryday work - off until switched on\n";
    ok('Choose approvers is not restricted on a fresh install', !capActionRestricted($conn, Cap::CHANGES_CHOOSE_APPROVERS));
    ok('Design forms is not restricted either', !capActionRestricted($conn, Cap::FORMS_DESIGN));
    ok('so a plain analyst may still do both', analystMayAct($conn, $plain, Cap::CHANGES_CHOOSE_APPROVERS) && analystMayAct($conn, $plain, Cap::FORMS_DESIGN));
    ok('Roles lists them as everyday work', capIsEveryday(capKey(Cap::CHANGES_CHOOSE_APPROVERS)) && capIsEveryday(capKey(Cap::FORMS_DESIGN)));
    ok('the capability registry is healthy', capSelfCheck() === [], implode('; ', capSelfCheck()));

    // A ZZ role holding both, given to the second analyst.
    $conn->prepare("INSERT INTO rbac_roles (name, description, is_active) VALUES ('ZZ approver-access test', 'temporary', 1)")->execute();
    $made['role'] = $roleId = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO rbac_role_capabilities (role_id, capability_key) VALUES (?, ?), (?, ?)")
         ->execute([$roleId, capKey(Cap::CHANGES_CHOOSE_APPROVERS), $roleId, capKey(Cap::FORMS_DESIGN)]);
    $conn->prepare("INSERT INTO rbac_analyst_roles (analyst_id, role_id) VALUES (?, ?)")->execute([$granted, $roleId]);

    echo "\nChoose approvers, restricted\n";
    zzSet($conn, 'change_approval_choose_approvers', 'permission');
    changeApprovalSettings($conn, true);
    ok('the restriction is on', capActionRestricted($conn, Cap::CHANGES_CHOOSE_APPROVERS));
    ok('a plain analyst cannot raise a change with an approver', $code(fn() => ChangesService::createChange($conn, $ctxPlain, 1, ['title' => 'ZZ-ACC refused', 'approver_id' => $admin])) === 'forbidden');
    $id = ChangesService::createChange($conn, $ctxPlain, 1, ['title' => 'ZZ-ACC plain']);
    $made['changes'][] = $id;
    ok('POSITIVE CONTROL: but can raise one with nobody chosen', $id > 0);
    ok('nor set the approver on it', $code(fn() => ChangesService::updateChange($conn, $ctxPlain, $id, ['approver_id' => $admin])) === 'forbidden');
    ok('nor the CAB', $code(fn() => ChangesService::saveCab($conn, $ctxPlain, $id, [['analyst_id' => $admin, 'is_required' => true]])) === 'forbidden');
    ok('POSITIVE CONTROL: a non-approval edit by them still saves', $code(fn() => ChangesService::updateChange($conn, $ctxPlain, $id, ['title' => 'ZZ-ACC plain, renamed'])) === '');
    ChangesService::updateChange($conn, $ctxGranted, $id, ['approver_id' => $admin]);
    ok('the analyst given the role sets the approver', (int)$conn->query("SELECT approver_id FROM changes WHERE id = {$id}")->fetchColumn() === $admin);
    ok('an administrator always can', $code(fn() => ChangesService::updateChange($conn, $ctxAdmin, $id, ['approver_id' => $granted])) === '');

    echo "\nDesign forms, restricted\n";
    zzSet($conn, 'forms_design_restricted', '1');
    ok('a plain analyst cannot create a form', $code(fn() => FormsService::saveForm($conn, $ctxPlain, ['title' => 'ZZ-ACC form refused', 'fields' => []])) === 'forbidden');
    $f = FormsService::saveForm($conn, $ctxGranted, ['title' => 'ZZ-ACC form', 'fields' => []]);
    $formId = (int)($f['id'] ?? 0);
    if ($formId) $made['forms'][] = $formId;
    ok('the analyst given Design forms can', $formId > 0, json_encode($f));
    ok('a plain analyst cannot delete it', $code(fn() => FormsService::deleteForm($conn, $ctxPlain, $formId)) === 'forbidden');
    zzRestore($conn);
    changeApprovalSettings($conn, true);
    ok('NEGATIVE CONTROL: with the restriction off, the plain analyst may design again', analystMayAct($conn, $plain, Cap::FORMS_DESIGN));

    echo "\nThe approver's own way into Forms\n";
    ok('without Forms, and named nowhere, the analyst cannot enter the inbox', !catalogueApproverCanEnter($conn, $plain));
    $conn->prepare("UPDATE forms SET requires_approval = 1, approver_id = ? WHERE id = ? AND title LIKE 'ZZ-%'")->execute([$plain, $formId]);
    ok('named as a form\'s approver, they can', catalogueApproverCanEnter($conn, $plain));
    ok('POSITIVE CONTROL: they still have no Forms module', !analystCanAccessModule($conn, $plain, 'forms'));

    if ($BASE !== null) {
        $sess = 'zzacc' . bin2hex(random_bytes(4));
        $name = 'ZZ plain';
        file_put_contents(session_save_path() . '/sess_' . $sess, 'analyst_id|i:' . $plain . ';analyst_name|s:' . strlen($name) . ':"' . $name . '";');
        $get = function (string $path) use ($BASE, $sess) {
            $ch = curl_init($BASE . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sess, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => false]);
            $body = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return [$code, $body];
        };
        [$c, $b] = $get('api/forms/catalogue_approvals.php?filter=mine');
        ok('HTTP: their inbox loads ("For me")', $c === 200 && str_contains($b, '"success":true'), substr($b, 0, 120));
        ok('HTTP: and does not count every pending request', str_contains($b, '"all":null'));
        [$c, $b] = $get('api/forms/catalogue_approvals.php?filter=all');
        ok('HTTP: "All pending" is refused', $c === 403);
        [$c, $b] = $get('forms/approvals.php');
        ok('HTTP: the approvals page opens', $c === 200 && str_contains($b, 'ca-container') && !str_contains($b, 'data-filter="all"'));
        [$c, $b] = $get('forms/');
        ok('HTTP: the rest of Forms still sends them away', $c === 302);
        @unlink(session_save_path() . '/sess_' . $sess);
    } else {
        echo "  SKIP  HTTP checks (no base URL)\n";
    }

} catch (Throwable $e) {
    ok('no unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    zzRestore($conn);
    foreach ($made['changes'] as $cid) {
        $conn->prepare("UPDATE changes SET approval_id = NULL WHERE id = ? AND title LIKE 'ZZ-%'")->execute([$cid]);
        $conn->prepare("DELETE FROM change_approvals WHERE change_id = ?")->execute([$cid]);
        $conn->prepare("DELETE FROM changes WHERE id = ? AND title LIKE 'ZZ-%'")->execute([$cid]);
    }
    if (!empty($made['grant'])) {
        $conn->prepare("DELETE FROM analyst_modules WHERE analyst_id = ? AND module_key = 'changes' AND access_level = 'read'")->execute([$plain]);
    }
    foreach ($made['forms'] as $fid) {
        $conn->prepare("DELETE FROM forms WHERE id = ? AND title LIKE 'ZZ-%'")->execute([$fid]);
    }
    if ($made['role']) {
        $conn->prepare("DELETE FROM rbac_analyst_roles WHERE role_id = ?")->execute([$made['role']]);
        $conn->prepare("DELETE FROM rbac_role_capabilities WHERE role_id = ?")->execute([$made['role']]);
        $conn->prepare("DELETE FROM rbac_roles WHERE id = ? AND name LIKE 'ZZ %'")->execute([$made['role']]);
    }
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM changes WHERE title LIKE 'ZZ-%') + (SELECT COUNT(*) FROM forms WHERE title LIKE 'ZZ-%')
                                    + (SELECT COUNT(*) FROM rbac_roles WHERE name LIKE 'ZZ %')")->fetchColumn();
    echo "\nTidy: " . ($left === 0 ? 'nothing left behind' : "LEFT BEHIND: {$left} row(s)") . "\n";
}

echo "\n" . str_repeat('-', 70) . "\n  {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
