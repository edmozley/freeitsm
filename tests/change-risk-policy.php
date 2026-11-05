<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Change risk policies (#246).
 *
 *   php tests/change-risk-policy.php
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/change-risk-policy.php   (part 2 as well)
 *
 * PART 1 - no database: the built-in 5 x 5 gives exactly the old bands in all 25
 *          cells; a policy's result comes from its matrix, not from multiplying; what
 *          a policy may and may not be saved as.
 * PART 2 - on a throwaway database, through ChangesService: a 4 x 4 policy, changes
 *          rated before and after it, re-assessment, the approval fingerprint,
 *          templates rated on another scale, and the per-company lookup.
 *
 * PART 2 changes the install's risk policy while it runs, which is why it refuses to
 * run anywhere but a database named in FREEITSM_TEST_DATABASE. It puts everything back
 * in the finally block: its changes, its template, its policy versions, and the policy
 * row as it was.
 *
 * Every refusal is paired with a positive control, so a rule that refused everything
 * could not pass for a working one.
 *
 * @wiki Change-Risk-Policy-Design
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/changes.php';
require_once __DIR__ . '/../includes/changes/risk_policy.php';
require_once __DIR__ . '/../includes/changes/approvals.php';
require_once __DIR__ . '/../includes/changes/templates.php';

$pass = 0; $fail = 0; $skip = 0;
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
/** The message a call is refused with, or '' when it is not refused. */
function refused(callable $fn): string {
    try { $fn(); return ''; } catch (ServiceError $e) { return $e->getMessage(); }
}

/** A valid 4 x 4 policy that is NOT multiply-and-band: impact 4 is never below High. */
function zzPolicy4(): array {
    $lv = fn($p) => array_map(fn($w) => ['label' => $w, 'criteria' => "$p $w"], ['gering', 'mittel', 'hoch', 'sehr hoch']);
    return [
        'name' => 'ZZ Informationssicherheit',
        'likelihood' => $lv('L'), 'impact' => $lv('I'),
        'results' => [['label' => 'gering', 'colour' => '#16a34a'], ['label' => 'mittel', 'colour' => '#ca8a04'],
                      ['label' => 'hoch', 'colour' => '#ea580c'], ['label' => 'sehr hoch', 'colour' => '#991b1b']],
        // rows = likelihood 1..4, columns = impact 1..4
        'matrix' => [[0, 0, 1, 2], [0, 1, 1, 2], [1, 1, 2, 3], [1, 2, 3, 3]],
    ];
}

echo "PART 1 - no database\n";
$b = ChangeRiskPolicy::builtin();
$band = fn(int $s) => $s <= 4 ? 'Low' : ($s <= 9 ? 'Medium' : ($s <= 15 ? 'High' : ($s <= 20 ? 'Very High' : 'Critical')));
$same = true; $diff = '';
for ($l = 1; $l <= 5; $l++) for ($i = 1; $i <= 5; $i++) {
    [$score, $level] = ChangeRiskPolicy::assess($b, $l, $i);
    if ($score !== $l * $i || $level !== $band($l * $i)) { $same = false; $diff = "$l x $i gave $score $level"; }
}
ok('the built-in matrix gives exactly the old bands in all 25 cells', $same, $diff);
ok('the built-in has 5 levels on each axis', ChangeRiskPolicy::levels($b, 'likelihood') === 5 && ChangeRiskPolicy::levels($b, 'impact') === 5);
ok('one rating alone gives no score and no result', ChangeRiskPolicy::assess($b, 3, null) === [null, null]);

$p4 = ChangeRiskPolicy::normalise(zzPolicy4());
[$s, $lvl] = ChangeRiskPolicy::assess($p4, 1, 4);
ok('a policy result comes from its matrix: 1 x 4 = 4 is "hoch", not the low band a score of 4 would give', $s === 4 && $lvl === 'hoch', "$s $lvl");
[$s, $lvl] = ChangeRiskPolicy::assess($p4, 4, 4);
ok('4 x 4 is "sehr hoch"', $lvl === 'sehr hoch', (string)$lvl);
ok('control: a 4 rating is accepted on a 4-level policy', ChangeRiskPolicy::rating(4, 'risk_likelihood', $p4, 'likelihood') === 4);
ok('a 5 rating is refused on a 4-level policy, naming the range',
   str_contains(refused(fn() => ChangeRiskPolicy::rating(5, 'risk_likelihood', $p4, 'likelihood')), 'between 1 and 4'));
ok('control: 5 is accepted on the built-in', ChangeRiskPolicy::rating(5, 'risk_likelihood', $b, 'likelihood') === 5);
ok('a fractional rating is refused', refused(fn() => ChangeRiskPolicy::rating('2.5', 'risk_likelihood', $b, 'likelihood')) !== '');
ok('a blank rating clears', ChangeRiskPolicy::rating('', 'risk_likelihood', $b, 'likelihood') === null);

$bad = function (callable $edit): string { $p = zzPolicy4(); $edit($p); return refused(fn() => ChangeRiskPolicy::normalise($p)); };
ok('control: the 4 x 4 policy is accepted as it is', $bad(fn(&$p) => null) === '');
ok('an empty matrix cell is refused', $bad(function (&$p) { $p['matrix'][2][1] = null; }) !== '');
ok('a cell pointing past the results is refused', $bad(function (&$p) { $p['matrix'][0][0] = 4; }) !== '');
ok('a missing matrix row is refused', $bad(function (&$p) { unset($p['matrix'][3]); }) !== '');
ok('one level only is refused', $bad(function (&$p) { $p['impact'] = [['label' => 'x']]; }) !== '');
ok('eleven levels are refused', $bad(function (&$p) { $p['likelihood'] = array_map(fn($n) => ['label' => "L$n"], range(1, 11)); }) !== '');
ok('a bad colour is refused', $bad(function (&$p) { $p['results'][0]['colour'] = 'red'; }) !== '');
ok('a colour carrying markup is refused', $bad(function (&$p) { $p['results'][0]['colour'] = '#fff;background:url(x)'; }) !== '');
ok('two results with one name are refused', $bad(function (&$p) { $p['results'][1]['label'] = 'GERING'; }) !== '');
ok('a blank level label is refused', $bad(function (&$p) { $p['impact'][2]['label'] = '  '; }) !== '');
ok('a result label longer than risk_level holds is refused', $bad(function (&$p) { $p['results'][0]['label'] = str_repeat('x', 51); }) !== '');
ok('no name is refused', $bad(function (&$p) { $p['name'] = ''; }) !== '');
ok('extra keys are not stored', !array_key_exists('extra', ChangeRiskPolicy::normalise(zzPolicy4() + ['extra' => '<script>'])));

echo "\nPART 2 - ChangesService on a throwaway database\n";
$conn = connectToDatabase();
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $dbName) {
    $skip++; echo "  SKIP  PART 2 - set FREEITSM_TEST_DATABASE to the throwaway database config.php points at (this one is $dbName)\n";
} elseif (!ChangeRiskPolicy::ready($conn)) {
    $skip++; echo "  SKIP  PART 2 - run Database Verification on this database first\n";
} else {
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ risk test');
    $tenant = (int)$conn->query("SELECT id FROM tenants ORDER BY is_default DESC, id LIMIT 1")->fetchColumn();
    $rows = $conn->query("SELECT * FROM change_risk_policies")->fetchAll(PDO::FETCH_ASSOC);
    $maxVersion = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM change_risk_policy_versions")->fetchColumn();
    $made = ['changes' => [], 'templates' => []];
    $row = function (int $id) use ($conn): array { $s = $conn->prepare("SELECT * FROM changes WHERE id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC); };
    $new = function (array $in) use ($conn, $ctx, $tenant, &$made): int {
        $id = ChangesService::createChange($conn, $ctx, $tenant, ['title' => 'ZZ risk ' . bin2hex(random_bytes(3))] + $in);
        $made['changes'][] = $id; return $id;
    };
    try {
        // Start from the built-in, whatever this database had.
        $conn->exec("UPDATE change_risk_policies SET current_version_id = NULL");
        $old = $new(['risk_likelihood' => 5, 'risk_impact_score' => 5]);
        $r = $row($old);
        ok('built-in: 5 x 5 is 25 Critical, with no policy version recorded', (int)$r['risk_score'] === 25 && $r['risk_level'] === 'Critical' && $r['risk_policy_version_id'] === null);
        $material = ['risk_likelihood', 'risk_impact_score'];
        $hashBefore = changeApprovalContentHash($r, $material);

        // A template rated on the built-in, approved.
        $conn->prepare("INSERT INTO change_templates (name, risk_likelihood, risk_impact_score, open_fields, created_by_id, created_datetime) VALUES ('ZZ risk template', 5, 4, 'description', ?, UTC_TIMESTAMP())")->execute([$admin]);
        $tplId = (int)$conn->lastInsertId(); $made['templates'][] = $tplId;
        $tvId = ChangeTemplates::submitVersion($conn, $tplId, $admin);
        $conn->prepare("UPDATE change_template_versions SET state = 'approved', approved_by_id = ?, approved_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$admin, $tvId]);
        $conn->prepare("UPDATE change_templates SET current_version_id = ? WHERE id = ?")->execute([$tvId, $tplId]);
        $fromTpl = $new(['template_version_id' => $tvId]);
        ok('control: on the built-in, a built-in template copies its ratings', (int)$row($fromTpl)['risk_likelihood'] === 5 && (int)$row($fromTpl)['risk_impact_score'] === 4);

        // The 4 x 4 arrives.
        $v4 = ChangeRiskPolicy::save($conn, zzPolicy4(), $admin, null);
        $cur = ChangeRiskPolicy::current($conn, $tenant);
        ok('saving makes the policy the one in use, as version 1 or later', $cur['version_id'] === $v4 && $cur['version_no'] >= 1);
        $r = $row($old);
        ok('the change rated on the built-in keeps 5 / 5 / 25 / Critical and no version', (int)$r['risk_likelihood'] === 5 && (int)$r['risk_score'] === 25 && $r['risk_level'] === 'Critical' && $r['risk_policy_version_id'] === null);
        ok('its approval fingerprint is unchanged', changeApprovalContentHash($r, $material) === $hashBefore);

        $n = $new(['risk_likelihood' => 1, 'risk_impact_score' => 4]);
        $r = $row($n);
        ok('a new change is rated on the 4 x 4 and gets its result from the matrix', $r['risk_level'] === 'hoch' && (int)$r['risk_score'] === 4 && (int)$r['risk_policy_version_id'] === $v4, $r['risk_level'] . ' ' . $r['risk_policy_version_id']);
        ok('a 5 is refused on the 4 x 4 when creating', str_contains(refused(fn() => $new(['risk_likelihood' => 5, 'risk_impact_score' => 1])), 'between 1 and 4'));
        $un = $new([]);
        ok('control: an unrated change records no version', $row($un)['risk_policy_version_id'] === null);

        // The full-form save of an old change sends its ratings back unchanged.
        ChangesService::updateChange($conn, $ctx, $old, ['title' => 'ZZ risk renamed', 'risk_likelihood' => '5', 'risk_impact_score' => '5']);
        $r = $row($old);
        ok('saving an older change with its ratings unchanged leaves them on the built-in', (int)$r['risk_likelihood'] === 5 && $r['risk_level'] === 'Critical' && $r['risk_policy_version_id'] === null);
        ok('sending one new rating to an older change is refused', refused(fn() => ChangesService::updateChange($conn, $ctx, $old, ['risk_likelihood' => 2])) !== '');
        ok('a 5 is refused when re-assessing onto the 4 x 4', str_contains(refused(fn() => ChangesService::updateChange($conn, $ctx, $old, ['risk_likelihood' => 5, 'risk_impact_score' => 4])), 'between 1 and 4'));
        ChangesService::updateChange($conn, $ctx, $old, ['risk_likelihood' => 2, 'risk_impact_score' => 4]);
        $r = $row($old);
        ok('both ratings re-assess it onto the 4 x 4', (int)$r['risk_policy_version_id'] === $v4 && $r['risk_level'] === 'hoch', $r['risk_level']);
        $a = $conn->prepare("SELECT old_value, new_value FROM change_audit WHERE change_id = ? AND field_name = 'Risk Policy' ORDER BY id DESC LIMIT 1");
        $a->execute([$old]); $aud = $a->fetch(PDO::FETCH_ASSOC);
        ok('the move is in the audit trail, from the built-in to the policy', $aud && $aud['old_value'] === 'Built-in 5 x 5' && str_contains((string)$aud['new_value'], 'ZZ Informationssicherheit'), json_encode($aud));

        // Same numbers, different scale: still material.
        $mid = $new([]);
        $conn->prepare("UPDATE changes SET risk_likelihood = 3, risk_impact_score = 3, risk_score = 9, risk_level = 'Medium', risk_policy_version_id = NULL WHERE id = ?")->execute([$mid]);
        $h1 = changeApprovalContentHash($row($mid), $material);
        ChangesService::updateChange($conn, $ctx, $mid, ['risk_likelihood' => 3, 'risk_impact_score' => 3, 'risk_reassess' => true]);
        $r = $row($mid);
        ok('risk_reassess with the same numbers moves it onto the 4 x 4 and re-reads the result', (int)$r['risk_policy_version_id'] === $v4 && $r['risk_level'] === 'hoch', $r['risk_level']);
        ok('the same numbers on another scale change the approval fingerprint', changeApprovalContentHash($r, $material) !== $h1);

        // Templates on another scale.
        $fromTpl2 = $new(['template_version_id' => $tvId]);
        $r = $row($fromTpl2);
        ok('on the 4 x 4, a template rated on the built-in does not copy its ratings', $r['risk_likelihood'] === null && $r['risk_impact_score'] === null);
        $v = ChangeTemplates::version($conn, $tvId);
        $cfg = ['templates_enabled' => true] + changeApprovalSettings($conn);
        $fake = ['template_version_id' => $tvId, 'risk_likelihood' => 5, 'risk_impact_score' => 4, 'risk_policy_version_id' => null];
        ok('control: the same ratings on the template\'s own scale are still the recipe', ChangeTemplates::check($conn, $fake, $cfg, $admin)['ok'] === true,
           json_encode(ChangeTemplates::check($conn, $fake, $cfg, $admin)['reason']));
        $fake['risk_policy_version_id'] = $v4;
        $chk = ChangeTemplates::check($conn, $fake, $cfg, $admin);
        ok('the same numbers on another scale are not the recipe', $chk['ok'] === false && $chk['reason'] === 'changed' && in_array('risk_likelihood', $chk['changed_fields'], true));

        // Per company.
        $v4b = ChangeRiskPolicy::save($conn, ['name' => 'ZZ company policy'] + zzPolicy4(), $admin, $tenant);
        ok('a company with its own policy gets it', ChangeRiskPolicy::current($conn, $tenant)['version_id'] === $v4b);
        ok('the install and another company still get the install\'s', ChangeRiskPolicy::current($conn, null)['version_id'] === $v4 && ChangeRiskPolicy::current($conn, 99999999)['version_id'] === $v4);
        $conn->prepare("DELETE FROM change_risk_policies WHERE tenant_id = ?")->execute([$tenant]);

        // Back to the built-in.
        ChangeRiskPolicy::useBuiltin($conn, $admin, null);
        ok('Use built-in puts new assessments back on the 5 x 5', ChangeRiskPolicy::current($conn, $tenant)['version_id'] === null);
        $r = $row($n);
        ok('a change rated on the 4 x 4 keeps it after that', (int)$r['risk_policy_version_id'] === $v4 && $r['risk_level'] === 'hoch');
        ok('its version is still there to draw it from', ChangeRiskPolicy::content($conn, $v4)['name'] === 'ZZ Informationssicherheit');
    } catch (Throwable $e) {
        ok('no unexpected error', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    } finally {
        foreach ($made['changes'] as $id) {
            foreach (['change_audit', 'change_text_revisions', 'change_approvals'] as $t) {
                try { $conn->prepare("DELETE FROM $t WHERE change_id = ?")->execute([$id]); } catch (Throwable $e) { }
            }
            $conn->prepare("DELETE FROM changes WHERE id = ?")->execute([$id]);
        }
        foreach ($made['templates'] as $id) {
            $conn->prepare("DELETE FROM change_template_versions WHERE template_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM change_templates WHERE id = ?")->execute([$id]);
        }
        $conn->prepare("DELETE FROM change_risk_policy_versions WHERE id > ?")->execute([$maxVersion]);
        $conn->exec("DELETE FROM change_risk_policies");
        foreach ($rows as $p) {
            $conn->prepare("INSERT INTO change_risk_policies (id, tenant_id, current_version_id, updated_by_id, updated_datetime) VALUES (?, ?, ?, ?, ?)")
                 ->execute([$p['id'], $p['tenant_id'], $p['current_version_id'], $p['updated_by_id'], $p['updated_datetime']]);
        }
        echo "  (cleaned up: " . count($made['changes']) . " changes, " . count($made['templates']) . " template, the policy put back)\n";
    }
}

echo "\n{$pass} passed, {$fail} failed" . ($skip ? ", {$skip} skipped" : '') . "\n";
exit($fail ? 1 : 0);
