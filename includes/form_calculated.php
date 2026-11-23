<?php
/**
 * Calculated answers and option values (GH #160 Part D).
 *
 * A catalogue item in FreeITSM is a form, so an order takes one of three shapes,
 * and all three are one question type, `calculated`, built by choosing rather
 * than typing:
 *
 *   the form is the item     a fixed amount, times the Quantity answer      kind 'fixed'
 *   the form offers items    the value on the chosen option, times Quantity  kind 'option'
 *   the form is a basket     a table: a value column times a quantity column,
 *                            added up down the rows                          kind 'grid'
 *   a bundle                 a catalogue item's OWN price (5.4.0), times a
 *                            quantity - so a price lives in one place        kind 'item'
 *
 * A Calculated question's config:
 *   {"calc_lines": [
 *       {"kind":"fixed",  "amount":"900", "times": <field>|null},
 *       {"kind":"option", "field": <field>, "times": <field>|null},
 *       {"kind":"grid",   "field": <field>, "value_col": 3, "qty_col": 4|null},
 *       {"kind":"item",   "form_id": 57, "qty": "2", "times": <field>|null}],
 *    "calc_currency": "EUR",
 *    "calc_hide": true}                  (absent = shown to the person filling it in)
 * <field> is a form_fields id, or "idx:N" for a question not saved yet - the same
 * scheme conditions use, and remapped in the same two places (syncFields() and
 * createVersion()), because a form's field ids change with every version.
 *
 * Option values live on a dropdown or a choose-one question:
 *   {"option_values": {"Standard": "900", "Pro": "1500"}}
 * keyed by the option's TEXT, because that is what an answer stores and what a
 * condition compares. The builder moves the value when an option is renamed, as it
 * already does for an option marked as an exception.
 *
 * The rules (wiki Financial-Approvals-Design section 7):
 *   - THE SERVER WORKS IT OUT. What the browser sends for a calculated question is
 *     thrown away; otherwise anybody could post a total of 1 and skip the board;
 *   - hidden questions add nothing (they are not in the answers it is given);
 *   - an answer that cannot be read - a table cell that is not a number, an item
 *     with no price or in another currency - leaves the total BLANK, never a guess.
 *     A blank total makes an "Only when" threshold step run, which is the safe way;
 *   - one currency per total, never converted;
 *   - the total is frozen on the request as an ordinary stored answer, written to
 *     the currency's own decimal places with a '.' ("3000.00").
 */

require_once __DIR__ . '/money.php';

final class FormCalc
{
    const LINE_KINDS = ['fixed', 'option', 'grid', 'item'];
    const MAX_LINES  = 30;
    /** The questions a quantity may come from. */
    const QTY_TYPES = ['number'];
    /** The questions whose chosen option can carry a value. */
    const VALUE_TYPES = ['dropdown', 'radio'];

    // ---- Option values ---------------------------------------------------------

    /**
     * A choice question's option values: option text => amount ("900").
     *
     * @param array $field  a form_fields row (config as JSON or array)
     */
    public static function optionValues(array $field): array {
        $cfg = $field['config'] ?? null;
        if (is_string($cfg)) $cfg = json_decode($cfg, true);
        $v = is_array($cfg) ? ($cfg['option_values'] ?? null) : null;
        return is_array($v) ? array_map('strval', $v) : [];
    }

    /**
     * Tidy option values on save: only this question's current options, only
     * amounts of zero or more. Empty means none, and is not stored.
     *
     * @param array $config  the field's config, edited in place
     * @param array $options  the question's options (texts)
     */
    public static function cleanOptionValues(array &$config, array $options, string $type, int $i): void {
        if (!in_array($type, self::VALUE_TYPES, true) || empty($config['option_values']) || !is_array($config['option_values'])) {
            unset($config['option_values']);
            return;
        }
        $opts = array_map('strval', $options);
        $clean = [];
        foreach ($config['option_values'] as $opt => $amount) {
            $opt = (string)$opt;
            $amount = trim((string)$amount);
            if ($amount === '' || !in_array($opt, $opts, true)) continue;
            if (!is_numeric($amount) || (float)$amount < 0) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: the value for \"{$opt}\" must be a number of 0 or more.");
            }
            $clean[$opt] = self::plain($amount);
        }
        if ($clean) $config['option_values'] = $clean; else unset($config['option_values']);
    }

    // ---- A Calculated question's settings ----------------------------------------

    /**
     * Check and tidy a Calculated question's config on save (validateFieldConfig()).
     * Unknown keys inside a line are dropped: lines are rebuilt, never passed through.
     *
     * @param array $config  the field's config
     * @param int $i  this question's position in the payload
     * @param array $fields  the whole payload, to check what each line points at
     * @param array $indexById  saved field id => position
     */
    public static function cleanConfig(?PDO $conn, array $config, int $i, array $fields, array $indexById): array {
        $lines = $config['calc_lines'] ?? null;
        if (!is_array($lines) || !$lines) {
            throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: a calculated answer needs at least one line.");
        }
        if (count($lines) > self::MAX_LINES) {
            throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: a calculated answer may have at most " . self::MAX_LINES . ' lines.');
        }
        $clean = [];
        foreach (array_values($lines) as $n => $l) {
            $human = $n + 1;
            if (!is_array($l)) throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: line {$human} is not a line.");
            $kind = (string)($l['kind'] ?? '');
            if (!in_array($kind, self::LINE_KINDS, true)) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: line {$human} - choose what it adds up.");
            }
            $out = ['kind' => $kind];
            if ($kind === 'fixed') {
                $amount = trim((string)($l['amount'] ?? ''));
                if ($amount === '' || !is_numeric($amount) || (float)$amount < 0) {
                    throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: line {$human} needs an amount of 0 or more.");
                }
                $out['amount'] = self::plain($amount);
            } elseif ($kind === 'option') {
                $out['field'] = self::ref($l['field'] ?? null, $i, $fields, $indexById, self::VALUE_TYPES, "line {$human}");
            } elseif ($kind === 'grid') {
                $out['field'] = self::ref($l['field'] ?? null, $i, $fields, $indexById, ['grid'], "line {$human}");
                $grid = $fields[self::refPosition($out['field'], $indexById)] ?? [];
                $cols = [];
                foreach ((array)(self::cfg($grid)['columns'] ?? []) as $c) {
                    if (is_array($c) && empty($c['deleted']) && ($c['type'] ?? '') === 'number') $cols[(int)$c['id']] = true;
                }
                $vc = (int)($l['value_col'] ?? 0);
                if (!isset($cols[$vc])) throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: line {$human} - choose the table's amount column (a number column).");
                $out['value_col'] = $vc;
                $qc = (int)($l['qty_col'] ?? 0);
                if ($qc > 0) {
                    if (!isset($cols[$qc])) throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: line {$human} - the quantity column must be a number column.");
                    $out['qty_col'] = $qc;
                }
            } else { // item
                $fid = (int)($l['form_id'] ?? 0);
                if ($fid <= 0) throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: line {$human} - choose a catalogue item.");
                if ($conn) {
                    $st = $conn->prepare('SELECT 1 FROM forms WHERE id = ?');
                    $st->execute([$fid]);
                    if (!$st->fetchColumn()) throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: line {$human} - that catalogue item was not found.");
                }
                $out['form_id'] = $fid;
                $qty = trim((string)($l['qty'] ?? '1'));
                if ($qty === '' || !is_numeric($qty) || (float)$qty <= 0) {
                    throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: line {$human} needs a quantity of more than 0.");
                }
                $out['qty'] = self::plain($qty);
            }
            if (in_array($kind, ['fixed', 'option', 'item'], true) && isset($l['times']) && $l['times'] !== '' && $l['times'] !== null) {
                $out['times'] = self::ref($l['times'], $i, $fields, $indexById, self::QTY_TYPES, "line {$human}'s quantity");
            }
            $clean[] = $out;
        }
        try {
            $cur = Money::normalise($config['calc_currency'] ?? '') ?? Money::defaultCurrency();
        } catch (InvalidArgumentException $e) {
            throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: " . $e->getMessage());
        }
        $out = ['calc_lines' => $clean, 'calc_currency' => $cur];
        if (!empty($config['calc_hide'])) $out['calc_hide'] = true;
        // Everything else a field may carry (width, label position, visible_if) is kept.
        foreach ($config as $k => $v) {
            if (!in_array($k, ['calc_lines', 'calc_currency', 'calc_hide'], true)) $out[$k] = $v;
        }
        return $out;
    }

    /** Drop every calculated key from a question that is not one. */
    public static function stripConfig(array &$config): void {
        unset($config['calc_lines'], $config['calc_currency'], $config['calc_hide']);
    }

    /**
     * A line's pointer at another question, checked: on this form, not itself, of a
     * type that can supply it. Returned as the id, or "idx:N" for an unsaved one.
     */
    private static function ref($ref, int $i, array $fields, array $indexById, array $types, string $what) {
        if (is_string($ref) && str_starts_with($ref, 'idx:')) {
            $pos = (int)substr($ref, 4);
            $keep = 'idx:' . $pos;
        } else {
            $id = (int)$ref;
            if ($id <= 0 || !isset($indexById[$id])) {
                throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: {$what} - choose a question on this form.");
            }
            $pos = $indexById[$id];
            $keep = $id;
        }
        if (!isset($fields[$pos]) || $pos === $i) {
            throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: {$what} - choose a question on this form.");
        }
        $t = (string)($fields[$pos]['field_type'] ?? '');
        if (!in_array($t, $types, true)) {
            throw new ServiceError('validation', 'invalid_field', "fields[{$i}]: {$what} cannot come from a '{$t}' question.");
        }
        return $keep;
    }

    private static function refPosition($ref, array $indexById): int {
        return is_string($ref) && str_starts_with($ref, 'idx:') ? (int)substr($ref, 4) : ($indexById[(int)$ref] ?? -1);
    }

    /**
     * Rewrite every question pointer in a Calculated config - "idx:N" to the id it was
     * saved as (syncFields()), or an old version's id to the new one (createVersion()).
     *
     * @param callable $map  fn(mixed $ref): int
     */
    public static function remapRefs(array $config, callable $map): array {
        if (empty($config['calc_lines']) || !is_array($config['calc_lines'])) return $config;
        foreach ($config['calc_lines'] as $n => $l) {
            foreach (['field', 'times'] as $k) {
                if (isset($l[$k]) && $l[$k] !== null && $l[$k] !== '') $config['calc_lines'][$n][$k] = $map($l[$k]);
            }
        }
        return $config;
    }

    // ---- Working it out --------------------------------------------------------

    /** The currency a Calculated question is in. */
    public static function currencyOf(array $field): string {
        $c = (string)(self::cfg($field)['calc_currency'] ?? '');
        return $c !== '' ? $c : Money::defaultCurrency();
    }

    /** Is the total shown to the person filling the form in? (It is worked out either way.) */
    public static function shown(array $field): bool {
        return empty(self::cfg($field)['calc_hide']);
    }

    /**
     * The total, from the answers given - or null when an answer cannot be read.
     *
     * @param array $field  the Calculated question
     * @param array $fieldsById  the form's questions keyed by id (config, options, type)
     * @param array $values  field id => stored answer, ONLY for questions that were asked
     * @return ?string  e.g. "3000.00" (the currency's own decimal places)
     */
    public static function compute(PDO $conn, array $field, array $fieldsById, array $values): ?string {
        $cur = self::currencyOf($field);
        $dp = (int)(Money::registry()[$cur][2] ?? 2);
        $scale = 10 ** $dp;
        $total = 0;
        $prices = null;
        foreach ((array)(self::cfg($field)['calc_lines'] ?? []) as $l) {
            $kind = $l['kind'] ?? '';
            $times = 1.0;
            if (!empty($l['times'])) {
                $q = self::numberAnswer($values, (int)$l['times']);
                if ($q === null) return null;
                $times = $q;
            }
            if ($kind === 'fixed') {
                $total += (int)round(self::minor((string)$l['amount'], $scale) * $times);
            } elseif ($kind === 'option') {
                $fid = (int)($l['field'] ?? 0);
                $chosen = trim((string)($values[$fid] ?? ''));
                if ($chosen === '') continue;   // not answered, or hidden: adds nothing
                $ov = self::optionValues($fieldsById[$fid] ?? []);
                if (!isset($ov[$chosen])) continue;   // an option with no value adds nothing
                $total += (int)round(self::minor($ov[$chosen], $scale) * $times);
            } elseif ($kind === 'grid') {
                $fid = (int)($l['field'] ?? 0);
                if (!array_key_exists($fid, $values)) continue;
                $rows = json_decode((string)$values[$fid], true);
                if (!is_array($rows)) continue;
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $v = trim((string)($row[$l['value_col']] ?? ($row[(string)$l['value_col']] ?? '')));
                    if ($v === '') continue;
                    if (!is_numeric($v)) return null;
                    $q = 1.0;
                    if (!empty($l['qty_col'])) {
                        $qs = trim((string)($row[$l['qty_col']] ?? ($row[(string)$l['qty_col']] ?? '')));
                        if ($qs === '') continue;   // no quantity on the row: nothing ordered
                        if (!is_numeric($qs)) return null;
                        $q = (float)$qs;
                    }
                    $total += (int)round(self::minor($v, $scale) * $q);
                }
            } elseif ($kind === 'item') {
                $prices ??= self::itemPrices($conn, $field);
                $p = $prices[(int)$l['form_id']] ?? null;
                // No price, or a price in another currency: the total cannot be told. Never converted.
                if (!$p || $p['price'] === null || $p['currency'] !== $cur) return null;
                $total += (int)round(self::minor($p['price'], $scale) * (float)$l['qty'] * $times);
            }
        }
        $neg = $total < 0;
        $abs = abs($total);
        $s = $dp > 0 ? intdiv($abs, $scale) . '.' . str_pad((string)($abs % $scale), $dp, '0', STR_PAD_LEFT) : (string)$abs;
        return ($neg ? '-' : '') . $s;
    }

    /**
     * Each bundle line's catalogue item, as it is NOW: the current version of the item
     * the line names, its price and currency. Keyed by the form id the line holds.
     *
     * @return array<int, array{form_id:int, title:string, price:?string, currency:string, period:?string}>
     */
    public static function itemPrices(PDO $conn, array $field): array {
        $out = [];
        foreach ((array)(self::cfg($field)['calc_lines'] ?? []) as $l) {
            if (($l['kind'] ?? '') !== 'item' || empty($l['form_id'])) continue;
            $id = (int)$l['form_id'];
            if (isset($out[$id])) continue;
            $leaf = self::leafOf($conn, $id);
            $st = $conn->prepare('SELECT id, title, catalogue_price, catalogue_currency, catalogue_price_period FROM forms WHERE id = ?');
            $st->execute([$leaf]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) { $out[$id] = ['form_id' => $id, 'title' => '', 'price' => null, 'currency' => '', 'period' => null]; continue; }
            $out[$id] = [
                'form_id'  => (int)$r['id'],
                'title'    => (string)$r['title'],
                'price'    => ($r['catalogue_price'] ?? null) !== null && $r['catalogue_price'] !== '' ? (string)$r['catalogue_price'] : null,
                'currency' => ($r['catalogue_currency'] ?? '') !== '' ? (string)$r['catalogue_currency'] : Money::defaultCurrency(),
                'period'   => ($r['catalogue_price_period'] ?? '') !== '' ? (string)$r['catalogue_price_period'] : null,
            ];
        }
        return $out;
    }

    /**
     * The current version of a form: follow its newer versions to the one that has
     * none. A bundle names an item once, and keeps following it as it is edited.
     */
    public static function leafOf(PDO $conn, int $formId): int {
        $st = $conn->prepare('SELECT id FROM forms WHERE parent_form_id = ? ORDER BY id DESC LIMIT 1');
        for ($n = 0; $n < 100; $n++) {
            $st->execute([$formId]);
            $child = $st->fetchColumn();
            if ($child === false) break;
            $formId = (int)$child;
        }
        return $formId;
    }

    /**
     * Add what a filler needs to show the live total to each Calculated question:
     * the bundle items' current prices (`calc_prices`). Called by the endpoints that
     * send a form to the analyst filler and the portal.
     *
     * @param array $fields  form_fields rows, edited in place
     */
    public static function annotate(PDO $conn, array &$fields): void {
        foreach ($fields as &$f) {
            if (($f['field_type'] ?? '') !== 'calculated') continue;
            $p = self::itemPrices($conn, $f);
            $f['calc_prices'] = (object)array_map(fn($x) => ['price' => $x['price'], 'currency' => $x['currency'], 'title' => $x['title']], $p);
        }
        unset($f);
    }

    /** A stored total for a person to read: "3.000,00 €". '' for a blank total. */
    public static function text(?string $raw, array $field): string {
        $raw = trim((string)$raw);
        if ($raw === '' || !is_numeric($raw)) return '';
        return Money::format($raw, self::currencyOf($field));
    }

    // ---- Helpers --------------------------------------------------------------

    /** A visible question's answer as a number: 0 when blank or not asked, null when unreadable. */
    private static function numberAnswer(array $values, int $fid): ?float {
        $v = trim((string)($values[$fid] ?? ''));
        if ($v === '') return 0.0;
        return is_numeric($v) ? (float)$v : null;
    }

    /** An amount in minor units (pence, cents) as a float, from its decimal text - no binary rounding on the way in. */
    private static function minor(string $amount, int $scale): float {
        $amount = trim($amount);
        $neg = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$int, $frac] = array_pad(explode('.', $amount, 2), 2, '');
        $dp = strlen((string)($scale - 1));   // 100 -> 2, 1 -> 0 (strlen('0') = 1, handled below)
        if ($scale === 1) $dp = 0;
        $frac = substr(str_pad($frac, $dp + 6, '0'), 0, $dp + 6);   // keep a little more than the places, then round
        $whole = (float)(($int === '' ? '0' : $int) . substr($frac, 0, $dp));
        $rest = (float)('0.' . substr($frac, $dp));
        $v = $whole + $rest;
        return $neg ? -$v : $v;
    }

    /** A number as plain decimal text: "1500", "0.5". */
    private static function plain(string $n): string {
        $n = trim($n);
        if (stripos($n, 'e') !== false) $n = rtrim(rtrim(sprintf('%.6F', (float)$n), '0'), '.');   // 1e3 -> 1000
        if (str_contains($n, '.')) $n = rtrim(rtrim($n, '0'), '.');
        return $n === '' || $n === '-' ? '0' : (str_starts_with($n, '.') ? '0' . $n : $n);
    }

    private static function cfg(array $field): array {
        $cfg = $field['config'] ?? null;
        if (is_string($cfg)) $cfg = json_decode($cfg, true);
        return is_array($cfg) ? $cfg : [];
    }
}
