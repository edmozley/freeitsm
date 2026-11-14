<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables - see tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The shop card of a catalogue item (GH #348): a short description, a price and a
 * picture - includes/catalogue_card.php.
 *
 * Covers:
 *   - an item with no card is unchanged: no columns written, the portal card is the
 *     title and description, a request keeps no price;
 *   - saving the card, each key checked (a long line, a negative price, an unknown
 *     currency or period, a picture path that is not ours or not this form's);
 *   - a save that does not send the card never clears it;
 *   - a new version keeps the card, picture included, and the picture still counts
 *     as the form's own;
 *   - a portal request keeps the price as it was, with the currency fixed then; an
 *     analyst's own submission keeps none; changing the price later changes no request;
 *   - the price as read: "a month", Free, nothing for no price;
 *   - the approvals inbox, the requester's list and the ticket body show the price;
 *   - an item that asks nothing (a mouse at a price) is saved, requested and approved.
 *
 * Writes, so it runs only when FREEITSM_TEST_DATABASE names the database config.php
 * points at. Everything is named ZZCARD and removed at the end.
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/catalogue-card.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/forms.php';
require_once __DIR__ . '/../includes/catalogue_approvals.php';
require_once __DIR__ . '/../includes/catalogue_card.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  - $detail" : '') . "\n"; }
}
/** Run $fn and return the exception message it threw, or '' if it threw nothing. */
function refused(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}

echo "Catalogue card (GH #348)\n" . str_repeat('=', 64) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes forms, requests and tickets.\n");
}
if (!catalogueCardAvailable($conn)) { echo "  SKIPPED - run Database Verification first.\n"; exit(0); }

$made = ['analysts' => [], 'users' => [], 'forms' => []];
$tag = 'zzcard' . bin2hex(random_bytes(3));

try {
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin) VALUES (?, 'x', 'ZZCARD admin', ?, 1, 1)")
         ->execute([$tag . 'admin', $tag . 'admin@example.invalid']);
    $admin = $made['analysts'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, 'ZZCARD Uma', UTC_TIMESTAMP())")
         ->execute([$tag . 'uma@example.invalid']);
    $uma = $made['users'][] = (int)$conn->lastInsertId();

    $ctx = new ActorContext(actorId: $admin, source: 'ui');
    $_SESSION = ['analyst_id' => $admin];
    $row = fn(string $t, int $id) => $conn->query("SELECT * FROM $t WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    $mkForm = function (array $extra = []) use ($conn, $ctx, &$made) {
        $id = (int)FormsService::saveForm($conn, $ctx, ['title' => 'ZZCARD form', 'description' => 'ZZCARD the <b>long</b> description',
            'is_portal_visible' => 1, 'fields' => [['field_type' => 'text', 'label' => 'ZZCARD what']]] + $extra)['id'];
        return $made['forms'][] = $id;
    };
    $fieldOf = fn(int $formId) => (int)$conn->query("SELECT id FROM form_fields WHERE form_id = $formId LIMIT 1")->fetchColumn();
    $submit = fn(int $formId, ?int $user) => FormsService::submitForm($conn,
        $user === null ? $ctx : new ActorContext(actorId: 0, source: 'portal'), $formId, [$fieldOf($formId) => 'ZZCARD thing'], $user);
    $save = fn(int $id, array $in) => FormsService::saveForm($conn, $ctx, ['id' => $id] + $in);
    $default = Money::defaultCurrency();

    // ---- 1. No card: as before ----
    $plain = $mkForm();
    $r = $row('forms', $plain);
    check('an item with no card stores none', $r['catalogue_summary'] === null && $r['catalogue_price'] === null && $r['catalogue_image_path'] === null);
    $card = catalogueCardForPortal($r);
    check('...its portal card is the title and description, no price, no picture',
        $card['summary'] === 'ZZCARD the long description' && $card['price_label'] === '' && $card['image_url'] === '', json_encode($card));
    $s = $submit($plain, $uma);
    check('...and a request for it keeps no price', $row('form_submissions', $s)['catalogue_price'] === null);

    // ---- 2. Saving the card, each key checked ----
    $f = $mkForm(['catalogue_summary' => "  A 27\" monitor,\n for the desk  ", 'catalogue_price' => '199.999', 'catalogue_currency' => 'eur']);
    $r = $row('forms', $f);
    check('a card set on create: one line, the price to 2 places, the currency as its code',
        $r['catalogue_summary'] === 'A 27" monitor, for the desk' && $r['catalogue_price'] === '200.00' && $r['catalogue_currency'] === 'EUR'
        && $r['catalogue_price_period'] === null, json_encode([$r['catalogue_summary'], $r['catalogue_price'], $r['catalogue_currency']]));
    check('a line longer than 255 is refused', str_contains(refused(fn() => $save($f, ['catalogue_summary' => str_repeat('x', 256)])), 'longer than'));
    check('a negative price is refused', str_contains(refused(fn() => $save($f, ['catalogue_price' => '-1'])), 'from 0 up'));
    check('a price that is not a number is refused', str_contains(refused(fn() => $save($f, ['catalogue_price' => '12,50 EUR'])), 'from 0 up'));
    check('an unknown currency is refused', refused(fn() => $save($f, ['catalogue_currency' => 'ZZZ'])) !== '');
    check('an unknown period is refused', str_contains(refused(fn() => $save($f, ['catalogue_price_period' => 'weekly'])), "'once' or 'month'"));
    check('a picture path of any other shape is refused', str_contains(refused(fn() => $save($f, ['catalogue_image_path' => $f . '/../../config.php'])), 'not a picture'));
    check('a picture uploaded for another form is refused', str_contains(refused(fn() => $save($f, ['catalogue_image_path' => $plain . '/' . str_repeat('a', 32) . '.png'])), 'not a picture'));
    check('a picture of a type that is not an image is refused', str_contains(refused(fn() => $save($f, ['catalogue_image_path' => $f . '/' . str_repeat('a', 32) . '.php'])), 'not a picture'));
    $pic = $f . '/' . str_repeat('b', 32) . '.png';
    $save($f, ['catalogue_image_path' => $pic, 'catalogue_price_period' => 'month']);
    $r = $row('forms', $f);
    check("this form's own picture, and a monthly price, are stored", $r['catalogue_image_path'] === $pic && $r['catalogue_price_period'] === 'month');
    $save($f, ['title' => 'ZZCARD form']);
    $r = $row('forms', $f);
    check('a save that does not send the card leaves it alone', $r['catalogue_price'] === '200.00' && $r['catalogue_image_path'] === $pic && $r['catalogue_summary'] !== null);
    $save($f, ['catalogue_price_period' => 'once']);
    check("'once' is stored as NULL - the common case needs no value", $row('forms', $f)['catalogue_price_period'] === null);
    $save($f, ['catalogue_price_period' => 'month']);

    // ---- 3. A new version keeps it ----
    $v = (int)FormsService::createVersion($conn, $ctx, $f)['id'];
    $made['forms'][] = $v;
    $r = $row('forms', $v);
    check('a new version keeps the card, picture included',
        $r['catalogue_summary'] === 'A 27" monitor, for the desk' && $r['catalogue_price'] === '200.00' && $r['catalogue_currency'] === 'EUR'
        && $r['catalogue_price_period'] === 'month' && $r['catalogue_image_path'] === $pic);
    check("...and the picture, uploaded on the version before, is still the form's own", catalogueImageBelongs($conn, $v, $f) && !catalogueImageBelongs($conn, $f, $v));
    check('...so saving it again on the new version is allowed', refused(fn() => $save($v, ['catalogue_image_path' => $pic])) === '');
    check('the portal card links the picture by form id, never by path',
        str_starts_with(catalogueCardForPortal($r)['image_url'], '../api/forms/card_image.php?form=' . $v . '&v=') && !str_contains(catalogueCardForPortal($r)['image_url'], 'bbbb'));

    // ---- 4. A request keeps the price it was made at ----
    $s1 = $submit($v, $uma);
    $q = $row('form_submissions', $s1);
    check('a portal request keeps the price, the currency and the period',
        $q['catalogue_price'] === '200.00' && $q['catalogue_currency'] === 'EUR' && $q['catalogue_price_period'] === 'month', json_encode($q));
    $save($v, ['catalogue_price' => '250']);
    check('changing the price later changes no request', $row('form_submissions', $s1)['catalogue_price'] === '200.00');
    $save($v, ['catalogue_currency' => '']);
    $s2 = $submit($v, $uma);
    check("a blank currency is the install's, fixed on the request", $row('form_submissions', $s2)['catalogue_currency'] === $default);
    $s3 = $submit($v, null);
    check("an analyst's own submission keeps no price - only a catalogue request is buying", $row('form_submissions', $s3)['catalogue_price'] === null);

    // ---- 5. The price as read ----
    check('a monthly price says so', cataloguePriceLabel('10', 'GBP', 'month') === t('common.catalogue_price.month', ['price' => Money::format('10', 'GBP')]));
    check('a one-off price is the amount', cataloguePriceLabel('10', 'GBP', null) === Money::format('10', 'GBP'));
    check('0 is Free', cataloguePriceLabel('0.00', 'GBP', null) === t('common.catalogue_price.free'));
    check('no price is nothing', cataloguePriceLabel(null, 'GBP', null) === '' && cataloguePriceLabel('', null, null) === '');
    $labels = catalogueRequestPriceLabels($conn, [$s1, $s3]);
    check('the request lists get the price of each - and nothing for one with none',
        isset($labels[$s1]) && !isset($labels[$s3]) && $labels[$s1] === cataloguePriceLabel('200.00', 'EUR', 'month'), json_encode($labels));

    // ---- 6. The approver and the ticket see it ----
    $g = $mkForm(['requires_approval' => 1, 'approver_id' => $admin, 'catalogue_price' => '35', 'catalogue_currency' => 'GBP']);
    $sg = $submit($g, $uma);
    $inbox = catalogueApprovalsList($conn, $admin, 'mine');
    $item = null;
    foreach ($inbox['items'] ?? $inbox as $it) if ((int)($it['id'] ?? 0) === $sg) $item = $it;
    check('the approvals inbox shows the price asked for', $item && $item['price_label'] === Money::format('35', 'GBP'), json_encode($item['price_label'] ?? null));
    $body = catalogueSubmissionBodyHtml($conn, $sg, 'ZZCARD form');
    check('the ticket an approval raises says the price', str_contains($body, htmlspecialchars(Money::format('35', 'GBP'), ENT_QUOTES, 'UTF-8')), $body);
    check("...and a request with no price adds no line", !str_contains(catalogueSubmissionBodyHtml($conn, $s, 'ZZCARD form'), t('common.catalogue_price.label')));

    // ---- 7. An item that asks nothing: a mouse at a price ----
    $mouse = $made['forms'][] = (int)FormsService::saveForm($conn, $ctx, ['title' => 'ZZCARD form', 'is_portal_visible' => 1,
        'requires_approval' => 1, 'approver_id' => $admin, 'catalogue_price' => '12.50', 'catalogue_currency' => 'GBP', 'fields' => []])['id'];
    check('an item with no questions can be saved', $mouse > 0 && (int)$conn->query("SELECT COUNT(*) FROM form_fields WHERE form_id = $mouse")->fetchColumn() === 0);
    $sm = FormsService::submitForm($conn, new ActorContext(actorId: 0, source: 'portal'), $mouse, [], $uma);
    $q = $row('form_submissions', $sm);
    check('...requested from the portal with no answers, waiting for approval, at its price',
        $q['approval_status'] === 'pending' && $q['catalogue_price'] === '12.50', json_encode([$q['approval_status'], $q['catalogue_price']]));
    $body = catalogueSubmissionBodyHtml($conn, $sm, 'ZZCARD form');
    check('...and its ticket says what and the price, with no empty answer table', !str_contains($body, '<table') && str_contains($body, 'ZZCARD form')
        && str_contains($body, htmlspecialchars(Money::format('12.50', 'GBP'), ENT_QUOTES, 'UTF-8')), $body);
    $res = catalogueApprovalDecide($conn, $admin, $sm, 'approved');
    check('...approving it raises the ticket', $res['decision'] === 'approved' && !empty($res['ticket_id']));
} catch (Throwable $e) {
    check('no unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($made['forms'] as $fid) {
        foreach ($conn->query("SELECT ticket_id FROM form_submissions WHERE form_id = " . (int)$fid . " AND ticket_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            foreach (['emails', 'ticket_audit'] as $tb) { try { $conn->prepare("DELETE FROM $tb WHERE ticket_id = ?")->execute([$tid]); } catch (Throwable $e) {} }
            $conn->prepare("UPDATE form_submissions SET ticket_id = NULL WHERE ticket_id = ?")->execute([$tid]);
            try { $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$tid]); } catch (Throwable $e) {}
        }
        $conn->prepare("DELETE sd FROM form_submission_data sd JOIN form_submissions s ON s.id = sd.submission_id WHERE s.form_id = ?")->execute([$fid]);
        $conn->prepare("DELETE FROM form_submissions WHERE form_id = ?")->execute([$fid]);
    }
    foreach (array_reverse($made['forms']) as $fid) {
        $conn->prepare("DELETE FROM form_fields WHERE form_id = ?")->execute([$fid]);
        try { $conn->prepare("DELETE FROM forms WHERE id = ?")->execute([$fid]); } catch (Throwable $e) {}
    }
    foreach ($made['users'] as $uid) $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
    foreach ($made['analysts'] as $aid) { try { $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$aid]); } catch (Throwable $e) {} }
    $left = (int)$conn->query("SELECT COUNT(*) FROM forms WHERE title = 'ZZCARD form'")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM analysts WHERE username LIKE '$tag%'")->fetchColumn();
    check('cleanup removed everything it made', $left === 0, "$left left");
    echo str_repeat('-', 64) . "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
