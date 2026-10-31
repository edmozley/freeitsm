<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Forms - a lookup answer posted through the portal, end to end.
 *
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/forms-lookup-portal-submit.php
 *
 * tests/forms-lookup/run.php proves lookupValueAllowed() is right when it is
 * given the right companies. This proves FormsService::submitForm() GIVES it the
 * right companies when a requester submits: a crafted post is refused, and what
 * is stored is the record's own name rather than whatever the post said.
 *
 * It writes real rows through the real submit path, so it runs only when
 * FREEITSM_TEST_DATABASE names the database config.php points at. Its rows are
 * named zz-... and removed at the end.
 *
 * Controls: a requester's own record IS accepted and a member of staff keeps
 * every company they can reach, so a refusal means "refused", not "broken".
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
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the throwaway database config.php points at (this one is {$dbName}).\n";
    exit(0);
}

$tag = 'zz-' . bin2hex(random_bytes(3));
$default = (int)getDefaultTenantId($conn);
$conn->prepare("INSERT INTO tenants (name, slug) VALUES (?, ?)")->execute([$tag . ' company', $tag]);
$other = (int)$conn->lastInsertId();

$rows = ['assets' => [], 'users' => []];
$formId = 0;

$asset = function (string $name, ?int $tenant) use ($conn, &$rows): int {
    $conn->prepare("INSERT INTO assets (hostname, tenant_id) VALUES (?, ?)")->execute([$name, $tenant]);
    return $rows['assets'][] = (int)$conn->lastInsertId();
};
$person = function (string $name, ?int $tenant) use ($conn, &$rows, $tag): int {
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id) VALUES (?, ?, ?)")
         ->execute(["$name.$tag@example.test", "$name $tag", $tenant]);
    return $rows['users'][] = (int)$conn->lastInsertId();
};
$lookupField = function (string $label, array $config, int $order) use ($conn, &$formId): int {
    $conn->prepare("INSERT INTO form_fields (form_id, field_type, label, is_required, sort_order, config) VALUES (?, 'lookup', ?, 0, ?, ?)")
         ->execute([$formId, $label, $order, json_encode($config)]);
    return (int)$conn->lastInsertId();
};

try {
    $conn->prepare("INSERT INTO forms (title, is_active, is_portal_visible, created_date, modified_date) VALUES (?, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
         ->execute([$tag . ' lookup test']);
    $formId = (int)$conn->lastInsertId();

    $ticked   = $lookupField('Which machine', ['lookup_source' => 'asset', 'portal_lookup' => true], 1);
    $unticked = $lookupField('Staff machine', ['lookup_source' => 'asset'], 2);
    $people   = $lookupField('Which person',  ['lookup_source' => 'user', 'portal_lookup' => true], 3);

    $mineAsset    = $asset($tag . '-mine', $default);
    $theirAsset   = $asset($tag . '-theirs', $other);
    $defaultAsset = $asset($tag . '-null', null);
    $requester    = $person('req', $default);
    $noCompany    = $person('none', null);
    $colleague    = $person('peer', $default);

    $portalCtx = ActorContext::fromPortalUser('test');
    $answer = fn(int $id, string $label = 'Forged label') => json_encode(['id' => $id, 'label' => $label]);

    $portalPost = function (int $field, string $value, int $user) use ($conn, $portalCtx, $formId): array {
        try {
            return [true, FormsService::submitForm($conn, $portalCtx, $formId, [$field => $value], $user)];
        } catch (ServiceError $e) {
            return [false, $e->getMessage()];
        }
    };
    $stored = function (int $submission, int $field) use ($conn): ?array {
        $q = $conn->prepare("SELECT field_value FROM form_submission_data WHERE submission_id = ? AND field_id = ?");
        $q->execute([$submission, $field]);
        return json_decode((string)$q->fetchColumn(), true);
    };

    echo "\n1. A requester's own company\n";
    [$ok, $sub] = $portalPost($ticked, $answer($mineAsset), $requester);
    check('their own company\'s record is accepted (control)', $ok, (string)$sub);
    if ($ok) {
        $row = $stored((int)$sub, $ticked);
        check('the id is kept', (int)($row['id'] ?? 0) === $mineAsset);
        check('the stored name is the record\'s own, not the posted one',
              ($row['label'] ?? '') === $tag . '-mine', (string)($row['label'] ?? ''));
    }
    [$ok] = $portalPost($ticked, $answer($theirAsset), $requester);
    check('another company\'s record is refused', !$ok);
    [$ok] = $portalPost($ticked, $answer(999999999), $requester);
    check('a record that does not exist is refused', !$ok);

    echo "\n2. A requester with no company falls to the Default company\n";
    [$ok, $sub] = $portalPost($ticked, $answer($defaultAsset), $noCompany);
    check('a Default company record is accepted', $ok, (string)$sub);
    [$ok] = $portalPost($ticked, $answer($theirAsset), $noCompany);
    check('another company\'s record is refused', !$ok);

    echo "\n3. The two portal gates hold at submit, not only in the search\n";
    [$ok] = $portalPost($unticked, $answer($mineAsset), $requester);
    check('a field not ticked for customers is refused', !$ok);
    [$ok] = $portalPost($people, $answer($colleague), $requester);
    check('a source that is never for customers is refused, ticked or not', !$ok);

    echo "\n4. Staff keep every company they can reach\n";
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $staff = new ActorContext(actorId: $admin, companyScope: null, source: 'api', actorName: 'test');
    try {
        $sub = FormsService::submitForm($conn, $staff, $formId, [$unticked => $answer($theirAsset)]);
        check('an unrestricted member of staff may pick any company\'s record (control)', true);
        check('and their stored name is the record\'s own too',
              ($stored($sub, $unticked)['label'] ?? '') === $tag . '-theirs');
    } catch (ServiceError $e) {
        check('an unrestricted member of staff may pick any company\'s record (control)', false, $e->getMessage());
    }
    $limited = new ActorContext(actorId: $admin, companyScope: [$default], source: 'api', actorName: 'test');
    try {
        FormsService::submitForm($conn, $limited, $formId, [$unticked => $answer($theirAsset)]);
        check('a member of staff limited to one company is refused another\'s', false);
    } catch (ServiceError $e) {
        check('a member of staff limited to one company is refused another\'s', true);
    }

    echo "\n5. The search and the submit agree on a requester's company\n";
    check('own company', FormsService::lookupPortalScope($conn, $requester) === [$default]);
    check('no company means Default, never everything', FormsService::lookupPortalScope($conn, $noCompany) === [$default]);
} finally {
    if ($formId) {
        $conn->prepare("DELETE d FROM form_submission_data d JOIN form_submissions s ON s.id = d.submission_id WHERE s.form_id = ?")->execute([$formId]);
        $conn->prepare("DELETE FROM form_submissions WHERE form_id = ?")->execute([$formId]);
        $conn->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }
    foreach ($rows as $table => $ids) {
        foreach ($ids as $id) {
            $conn->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
        }
    }
    $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$other]);
}

$left = $conn->prepare("SELECT (SELECT COUNT(*) FROM assets WHERE hostname LIKE ?) + (SELECT COUNT(*) FROM users WHERE email LIKE ?)");
$left->execute([$tag . '%', '%' . $tag . '%']);
check('nothing left behind', (int)$left->fetchColumn() === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
