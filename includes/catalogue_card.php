<?php
/**
 * The shop card of a catalogue item (GH #348): a one-line summary, a price and a
 * picture, shown on the item's card in the portal catalogue - so a person picks a
 * monitor or a mouse the way they would in a shop.
 *
 * WHERE IT LIVES
 * --------------
 *  forms.catalogue_*            the item's card. All NULL by default, which is the
 *                               card as it always was (title and description), so
 *                               every item that predates this is unchanged.
 *  form_submissions.catalogue_* the price AS REQUESTED, copied at submit time. An
 *                               item's price is changed later; a request is not -
 *                               the approver and the ticket see what was asked for.
 *
 * THE PICTURE is stored by api/forms/upload_image.php - the endpoint, the type
 * whitelist and the size limit an image block uses - and served by
 * api/forms/card_image.php, which takes a FORM id, never a path, and reads the path
 * from the row. A new version of the form keeps the picture: the stored path names
 * the version it was uploaded on, so "belongs to this form" means "to this form or
 * one of its earlier versions" (catalogueImageBelongs()).
 *
 * Nothing here runs until Database Verification has added the columns:
 * catalogueCardAvailable() is the one check, and before it is true every item saves,
 * versions and submits exactly as before.
 *
 * @wiki Forms
 */

require_once __DIR__ . '/i18n.php';    // t() - the price is read in the viewer's language
require_once __DIR__ . '/money.php';
require_once __DIR__ . '/uploads.php';   // UPLOAD_TYPES_IMAGE

/** How often the price is paid. NULL in the row is ONCE - the common case needs no value. */
enum CataloguePricePeriod: string
{
    case ONCE  = 'once';
    case MONTH = 'month';
}

/** The card's one line. The long description stays on the item's own page. */
const CATALOGUE_SUMMARY_MAX = 255;
/** Far above any catalogue item, well inside DECIMAL(18,2): a typo, not a price. */
const CATALOGUE_PRICE_MAX = 1000000000;
/** The one shape upload_image.php writes: "<formId>/<32 hex>.<ext>". */
const CATALOGUE_IMAGE_PATH_RE = '~^([0-9]+)/([0-9a-f]{32})\.([a-z0-9]{1,5})$~';

/**
 * Have the columns been added (Database Verification)? Both tables, so a half-run
 * verify never writes a price the request cannot keep.
 */
function catalogueCardAvailable(PDO $conn): bool
{
    static $known = null;
    if ($known !== null) return $known;
    try {
        $q = $conn->query(
            "SELECT (SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'forms' AND column_name = 'catalogue_image_path')
                  + (SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'form_submissions' AND column_name = 'catalogue_price_period')"
        );
        $known = (int)$q->fetchColumn() === 2;
    } catch (Throwable $e) {
        $known = false;
    }
    return $known;
}

/**
 * Is the picture at $ownerId's path this form's own - uploaded on this version or on
 * one before it? The chain is walked from the form back to its first version.
 */
function catalogueImageBelongs(PDO $conn, int $formId, int $ownerId): bool
{
    $s = $conn->prepare('SELECT parent_form_id FROM forms WHERE id = ?');
    $id = $formId;
    for ($i = 0; $i < 500 && $id > 0; $i++) {   // a bound, in case a hand-edited chain loops
        if ($id === $ownerId) return true;
        $s->execute([$id]);
        $parent = $s->fetchColumn();
        $id = $parent !== false && $parent !== null ? (int)$parent : 0;
    }
    return false;
}

/**
 * The card's columns from a save payload: only the keys sent, each checked. A key not
 * sent is not touched - the REST API and the older pages know nothing of the card,
 * and saving a title must never clear a price.
 *
 * @param array $in  catalogue_summary, catalogue_price, catalogue_currency,
 *                   catalogue_price_period, catalogue_image_path - '' or null clears one
 * @return array<string,mixed>  column => value
 */
function catalogueCardFromInput(PDO $conn, int $formId, array $in): array
{
    $set = [];
    $blank = fn($v) => $v === null || (is_string($v) && trim($v) === '');

    if (array_key_exists('catalogue_summary', $in)) {
        $v = $in['catalogue_summary'];
        if (!$blank($v) && !is_string($v)) throw new ServiceError('validation', 'invalid_field', "'catalogue_summary' must be text.");
        // Plain text on one line: a card has no room for markup or paragraphs.
        $v = $blank($v) ? null : trim(preg_replace('/\s+/u', ' ', strip_tags($v)));
        if ($v !== null && mb_strlen($v) > CATALOGUE_SUMMARY_MAX) {
            throw new ServiceError('validation', 'invalid_field', "'catalogue_summary' is longer than " . CATALOGUE_SUMMARY_MAX . ' characters.');
        }
        $set['catalogue_summary'] = $v === '' ? null : $v;
    }

    if (array_key_exists('catalogue_price', $in)) {
        $v = $in['catalogue_price'];
        if ($blank($v)) {
            $set['catalogue_price'] = null;
        } else {
            if (!is_numeric($v) || (float)$v < 0 || (float)$v > CATALOGUE_PRICE_MAX) {
                throw new ServiceError('validation', 'invalid_field', "'catalogue_price' must be a number from 0 up.");
            }
            $set['catalogue_price'] = number_format(round((float)$v, 2), 2, '.', '');
        }
    }

    if (array_key_exists('catalogue_currency', $in)) {
        // Blank is the install's currency, read when the item is requested.
        $set['catalogue_currency'] = $blank($in['catalogue_currency']) ? null : moneyServiceCurrency($in['catalogue_currency']);
    }

    if (array_key_exists('catalogue_price_period', $in)) {
        $v = $in['catalogue_price_period'];
        if ($blank($v)) {
            $set['catalogue_price_period'] = null;
        } else {
            $p = is_string($v) ? CataloguePricePeriod::tryFrom($v) : null;
            if ($p === null) throw new ServiceError('validation', 'invalid_field', "'catalogue_price_period' must be 'once' or 'month'.");
            $set['catalogue_price_period'] = $p === CataloguePricePeriod::ONCE ? null : $p->value;
        }
    }

    if (array_key_exists('catalogue_image_path', $in)) {
        $v = $in['catalogue_image_path'];
        if ($blank($v)) {
            $set['catalogue_image_path'] = null;
        } else {
            /* The same two checks an image block's path gets (FormsService), and
               card_image.php checks again before a byte is read: stopping a bad
               value being stored is not the same as stopping one being served. */
            $v = is_string($v) ? trim($v) : '';
            if (!preg_match(CATALOGUE_IMAGE_PATH_RE, $v, $m) || !isset(UPLOAD_TYPES_IMAGE[$m[3]])
                || !catalogueImageBelongs($conn, $formId, (int)$m[1])) {
                throw new ServiceError('validation', 'invalid_field', "'catalogue_image_path' is not a picture uploaded for this form.");
            }
            $set['catalogue_image_path'] = $v;
        }
    }
    return $set;
}

/**
 * Store the card's columns sent in a save (FormsService::saveForm()). Nothing before
 * Database Verification has added them.
 *
 * @param array $in  the save payload - see catalogueCardFromInput()
 */
function catalogueCardSave(PDO $conn, int $formId, array $in): void
{
    if (!catalogueCardAvailable($conn)) return;
    $set = catalogueCardFromInput($conn, $formId, $in);
    if (!$set) return;
    $cols = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
    $conn->prepare("UPDATE forms SET $cols WHERE id = ?")->execute([...array_values($set), $formId]);
}

/**
 * Carry the card to a new version of the form (FormsService::createVersion()). The
 * trap every per-form setting has: one left out of the copy is one that pressing Save
 * deletes - here, the item would lose its price and picture the moment it was edited.
 *
 * @param array $src  the version being copied (its full row)
 */
function catalogueCardCopy(PDO $conn, array $src, int $newId): void
{
    if (!catalogueCardAvailable($conn) || !array_key_exists('catalogue_image_path', $src)) return;
    $conn->prepare(
        'UPDATE forms SET catalogue_summary = ?, catalogue_price = ?, catalogue_currency = ?,
                          catalogue_price_period = ?, catalogue_image_path = ? WHERE id = ?'
    )->execute([
        $src['catalogue_summary'], $src['catalogue_price'], $src['catalogue_currency'],
        $src['catalogue_price_period'], $src['catalogue_image_path'], $newId,
    ]);
}

/**
 * The price to keep on a request, from the item as it is now - or null for an item
 * with no price. A blank currency is the install's, fixed now: a later change of the
 * install's currency must not turn last year's £40 into €40.
 *
 * @param array $form  the form row (FormsService::loadFormRow())
 * @return ?array{0:string,1:string,2:?string}  [price, currency, period]
 */
function catalogueRequestPrice(array $form): ?array
{
    $price = $form['catalogue_price'] ?? null;
    if ($price === null || $price === '') return null;
    $currency = ($form['catalogue_currency'] ?? '') !== '' ? (string)$form['catalogue_currency'] : Money::defaultCurrency();
    $period = ($form['catalogue_price_period'] ?? '') !== '' ? (string)$form['catalogue_price_period'] : null;
    return [(string)$price, $currency, $period];
}

/**
 * The price for a person to read: "£25.00", "£10.00 a month", "Free". '' when there is
 * no price - the card then shows none.
 *
 * @param mixed   $price     the amount, or null
 * @param ?string $currency  null or '' = the install's
 * @param ?string $period    a CataloguePricePeriod value; null = once
 */
function cataloguePriceLabel($price, ?string $currency, ?string $period): string
{
    if ($price === null || $price === '' || !is_numeric($price)) return '';
    if ((float)$price == 0.0) return t('common.catalogue_price.free');
    $amount = Money::format($price, $currency ?: null);
    return $period === CataloguePricePeriod::MONTH->value
        ? t('common.catalogue_price.month', ['price' => $amount])
        : $amount;
}

/**
 * The price each request was made at, ready to read - for the lists that show
 * requests (the approvals inbox, the requester's own list). One query for them all.
 *
 * @param int[] $ids  form_submissions ids
 * @return array<int,string>  id => label; requests with no price are left out
 */
function catalogueRequestPriceLabels(PDO $conn, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !catalogueCardAvailable($conn)) return [];
    $s = $conn->prepare(
        'SELECT id, catalogue_price, catalogue_currency, catalogue_price_period FROM form_submissions
          WHERE catalogue_price IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
    );
    $s->execute($ids);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = cataloguePriceLabel($r['catalogue_price'], $r['catalogue_currency'], $r['catalogue_price_period']);
    }
    return $out;
}

/**
 * What the portal shows of an item's card. The summary falls back to the start of the
 * description, so an item with neither keeps the card it had.
 *
 * @param array  $form  a forms row with id, description and (once verified) catalogue_*
 * @param string $base  the path to the install root from the page asking
 * @return array{summary:string, price_label:string, image_url:string}
 */
function catalogueCardForPortal(array $form, string $base = '../'): array
{
    $summary = trim((string)($form['catalogue_summary'] ?? ''));
    if ($summary === '') $summary = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($form['description'] ?? ''))));
    return [
        'summary'     => $summary,
        'price_label' => cataloguePriceLabel($form['catalogue_price'] ?? null, $form['catalogue_currency'] ?? null, $form['catalogue_price_period'] ?? null),
        // &v= changes with the picture: the URL is cached for an hour, and a new
        // picture must show at once rather than when the old one expires.
        'image_url'   => !empty($form['catalogue_image_path'])
            ? $base . 'api/forms/card_image.php?form=' . (int)$form['id'] . '&v=' . substr(md5((string)$form['catalogue_image_path']), 0, 8)
            : '',
    ];
}
