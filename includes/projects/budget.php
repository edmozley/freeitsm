<?php
/**
 * Projects - the budget (3.2.0): planned against actual, in the project's own
 * currency, with labour worked out from the time logged on its tasks.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔑 FLEXIBLE, WITH NO WAY TO PAINT YOURSELF INTO A CORNER (Ed, 2026-10-08:
 * "flexible, but not letting someone get in a tricky situation if they change
 * their mind later"). Every choice here can be changed later without quietly
 * rewriting what is already recorded:
 *
 *  - CURRENCY IS STORED ON THE PROJECT (projects.currency), stamped when it is
 *    created or first budgeted - never derived from the install default on
 *    read. Changing the default later affects new projects only.
 *  - AMOUNTS IN DIFFERENT CURRENCIES ARE NEVER ADDED. Anything that totals
 *    across projects (Report Packs, Watchtower) groups by currency. A contract
 *    in another currency is shown on its line but not counted.
 *  - RATES HAVE AN EFFECTIVE-FROM DATE (project_labour_rates). Time logged on a
 *    day is priced at the rate in force that day, so raising a rate today does
 *    not re-price last month.
 *  - HOW LABOUR IS COSTED IS A SETTING read on every view (hours / rate /
 *    analyst), and labour is never stored as money - switching mode just shows
 *    the same time differently.
 *  - Default and analyst rates are in the INSTALL currency; a project rate is
 *    in the PROJECT's. Time that only an install-currency rate could price, on
 *    a project in another currency, is shown as unpriced hours - never
 *    silently converted.
 *
 * Budget lines hold planned and actual amounts per line. Labour is not a line.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OVER TIME AND THE FORECAST (3.3.0)
 *  - A line may say WHEN: planned_date (when the money is expected to go out)
 *    and spent_date (when it went). Undated planned money counts from the
 *    project's start; undated actual money from the day the line was last saved.
 *  - A line's FORECAST - what it is now expected to cost in the end - is what
 *    somebody typed (forecast_amount), otherwise the larger of planned and
 *    actual: money already spent is not a saving until the line is finished.
 *  - LABOUR STILL TO COME is the hours left on open tasks' estimates (estimate
 *    minus the time logged on that task), priced at the rate in force today -
 *    projectLabourToCome(). Off with project_forecast_labour = 0.
 *  - 🔑 NOTHING IS COUNTED TWICE: a labour-category line with no typed actual is
 *    the PLAN for labour, so the forecast takes the larger of those lines and
 *    labour (logged + still to come) - not both.
 *  - project_cost_basis says whether the cost tolerance measures actual spend
 *    (the 3.2.0 behaviour, the default) or the forecast.
 */

require_once __DIR__ . '/settings.php';

const PROJECT_BUDGET_CATEGORIES = ['hardware', 'software', 'services', 'labour', 'travel', 'other'];

function projectBudgetReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM project_budget_lines LIMIT 0"); $conn->query("SELECT 1 FROM project_labour_rates LIMIT 0"); $conn->query("SELECT currency FROM projects LIMIT 0"); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** The install's currency (Projects -> Settings -> Budget). */
function projectInstallCurrency(PDO $conn): string
{
    return projectSetting($conn, 'project_currency') ?: 'GBP';
}

/** A project's currency: its own, or - not yet stamped - the install's. */
function projectCurrencyOf(PDO $conn, array $p): string
{
    return !empty($p['currency']) ? strtoupper((string)$p['currency']) : projectInstallCurrency($conn);
}

/** Stamp the install currency on a project that has none, so a later change of default cannot relabel it. */
function projectStampCurrency(PDO $conn, int $projectId): void
{
    try {
        $conn->prepare("UPDATE projects SET currency = ? WHERE id = ? AND (currency IS NULL OR currency = '')")->execute([projectInstallCurrency($conn), $projectId]);
    } catch (Throwable $e) { /* before Database Verification */ }
}

/** Every rate, [scope][ref] => [[from, rate], ...] newest first. Cached per request. */
function projectLabourRates(PDO $conn): array
{
    if (isset($GLOBALS['__prj_labour_rates'])) return $GLOBALS['__prj_labour_rates'];
    $out = [];
    try {
        foreach ($conn->query("SELECT scope, ref_id, hourly_rate, effective_from FROM project_labour_rates ORDER BY effective_from DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['scope']][(int)($r['ref_id'] ?? 0)][] = [(string)$r['effective_from'], (float)$r['hourly_rate']];
        }
    } catch (Throwable $e) { /* before Database Verification */ }
    return $GLOBALS['__prj_labour_rates'] = $out;
}

/** Forget the cached rates - after a rate is saved in the same request. */
function projectLabourRatesReset(): void
{
    unset($GLOBALS['__prj_labour_rates']);
}

/** The rate in force on $date for one scope/ref, or null. */
function projectRateOn(array $rates, string $scope, int $ref, string $date): ?float
{
    foreach ($rates[$scope][$ref] ?? [] as [$from, $rate]) {
        if ($from <= $date) return $rate;
    }
    return null;
}

/**
 * Labour for projects: time logged on their tasks, and what it cost.
 * @param array $projects id => row (needs id, currency)
 * @return array id => [minutes, priced_minutes, cost (null in hours mode), unpriced_minutes]
 */
function projectLabour(PDO $conn, array $projects, bool $byDay = false): array
{
    $out = [];
    foreach ($projects as $id => $_) $out[(int)$id] = ['minutes' => 0, 'priced_minutes' => 0, 'cost' => null, 'unpriced_minutes' => 0] + ($byDay ? ['days' => []] : []);
    if (!$projects) return $out;
    $mode = projectSetting($conn, 'project_labour_mode') ?: 'hours';
    $install = projectInstallCurrency($conn);
    $rates = projectLabourRates($conn);
    $ids = array_map('intval', array_keys($projects));
    try {
        $st = $conn->prepare("SELECT t.project_id, e.analyst_id, e.time_spent_minutes, DATE(e.entry_datetime) AS d
                                FROM task_time_entries e JOIN tasks t ON t.id = e.task_id
                               WHERE t.project_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND e.is_active = 1");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $out;
    }
    foreach ($rows as $r) {
        $pid = (int)$r['project_id'];
        $min = (int)$r['time_spent_minutes'];
        $out[$pid]['minutes'] += $min;
        if ($mode === 'hours') continue;
        $sameCurrency = projectCurrencyOf($conn, $projects[$pid]) === $install;
        $rate = null;
        if ($mode === 'rate') {
            // The project's own rate first (its currency), then the default (install currency).
            $rate = projectRateOn($rates, 'project', $pid, $r['d']);
            if ($rate === null && $sameCurrency) $rate = projectRateOn($rates, 'default', 0, $r['d']);
        } elseif ($mode === 'analyst' && $sameCurrency) {
            $rate = projectRateOn($rates, 'analyst', (int)$r['analyst_id'], $r['d']) ?? projectRateOn($rates, 'default', 0, $r['d']);
        }
        if ($rate === null) { $out[$pid]['unpriced_minutes'] += $min; continue; }
        $out[$pid]['priced_minutes'] += $min;
        $out[$pid]['cost'] = ($out[$pid]['cost'] ?? 0) + round($min / 60 * $rate, 2);
        if ($byDay) $out[$pid]['days'][$r['d']] = ($out[$pid]['days'][$r['d']] ?? 0) + round($min / 60 * $rate, 2);
    }
    if ($mode !== 'hours') foreach ($out as &$o) if ($o['cost'] === null && $o['minutes'] > 0) $o['cost'] = 0.0;
    unset($o);
    return $out;
}

/**
 * Budget totals for many projects at once - for health, exceptions, reports.
 * @param array $projects id => row (needs currency)
 * @return array id => [planned, actual, currency, has_budget]
 */
function projectBudgetTotals(PDO $conn, array $projects): array
{
    $out = [];
    if (!$projects || !projectBudgetReady($conn)) return $out;
    $ids = array_map('intval', array_keys($projects));
    $st = $conn->prepare("SELECT l.*, c.contract_value, c.currency AS contract_currency
                            FROM project_budget_lines l LEFT JOIN contracts c ON c.id = l.contract_id
                           WHERE l.project_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute($ids);
    $blank = fn($cur) => ['planned' => 0.0, 'actual' => 0.0, 'currency' => $cur, 'has_budget' => false, 'forecast' => 0.0, '_cover' => 0.0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['project_id'];
        $cur = projectCurrencyOf($conn, $projects[$pid]);
        $o = $out[$pid] ?? $blank($cur);
        $o['has_budget'] = true;
        $o['planned'] += (float)($r['planned_amount'] ?? 0);
        $act = projectLineActual($r, $cur)[0];
        $o['actual']  += (float)($act ?? 0);
        $fc = projectLineForecast($r, $act);
        if (projectLineCoversLabour($r)) $o['_cover'] += (float)($fc ?? 0); else $o['forecast'] += (float)($fc ?? 0);
        $out[$pid] = $o;
    }
    $toCome = projectSetting($conn, 'project_forecast_labour') !== '0' ? projectLabourToCome($conn, $projects) : [];
    foreach (projectLabour($conn, $projects) as $pid => $l) {
        $o = $out[$pid] ?? $blank(projectCurrencyOf($conn, $projects[$pid]));
        if ($l['cost'] !== null && $l['cost'] != 0) $o['actual'] += $l['cost'];
        $labour = (float)($l['cost'] ?? 0) + (float)($toCome[$pid]['cost'] ?? 0);
        $o['forecast'] += max($o['_cover'], $labour);
        $out[$pid] = $o;
    }
    $basis = projectSetting($conn, 'project_cost_basis') === 'forecast' ? 'forecast' : 'actual';
    foreach ($out as $pid => $o) {
        unset($o['_cover']);
        $o['forecast'] = round($o['forecast'], 2);
        // What the cost tolerance measures (project_cost_basis).
        $o['basis'] = $basis;
        $o['measured'] = $basis === 'forecast' ? $o['forecast'] : $o['actual'];
        $out[$pid] = $o;
    }
    return $out;
}

/**
 * One line's actual and where it came from: [amount|null, 'typed'|'contract'|null, mismatch?].
 * A typed actual wins; otherwise a linked contract's value - only in the same currency.
 */
function projectLineActual(array $r, string $currency): array
{
    if ($r['actual_amount'] !== null && $r['actual_amount'] !== '') return [(float)$r['actual_amount'], 'typed', false];
    if (isset($r['contract_value']) && $r['contract_value'] !== null) {
        $cc = strtoupper((string)($r['contract_currency'] ?? ''));
        if ($cc === '' || $cc === $currency) return [(float)$r['contract_value'], 'contract', false];
        return [null, null, true];   // another currency: shown, never added
    }
    return [null, null, false];
}

/** A line's forecast: what was typed, else the larger of planned and actual; null when it has neither (3.3.0). */
function projectLineForecast(array $r, ?float $actual): ?float
{
    if (isset($r['forecast_amount']) && $r['forecast_amount'] !== null && $r['forecast_amount'] !== '') return (float)$r['forecast_amount'];
    $planned = $r['planned_amount'] !== null && $r['planned_amount'] !== '' ? (float)$r['planned_amount'] : null;
    if ($planned === null && $actual === null) return null;
    return max($planned ?? 0.0, $actual ?? 0.0);
}

/**
 * A labour line with no typed actual is the PLAN for labour: the time logged on
 * the project's tasks is what it is spent on, so the forecast takes the larger
 * of these lines and labour, never both (3.3.0).
 */
function projectLineCoversLabour(array $r): bool
{
    return ($r['category'] ?? '') === 'labour' && ($r['actual_amount'] === null || $r['actual_amount'] === '');
}

/**
 * Labour still to come (3.3.0): the hours left on open tasks' estimates -
 * each task's estimate less the time logged on that task - priced at the rate
 * in force today, the same rules as projectLabour(). In hours mode, or where no
 * rate applies, the hours are counted and the cost is left out.
 * @return array id => [hours, cost|null, unpriced_hours, open_unestimated]
 */
function projectLabourToCome(PDO $conn, array $projects): array
{
    $out = [];
    foreach ($projects as $id => $_) $out[(int)$id] = ['hours' => 0.0, 'cost' => null, 'unpriced_hours' => 0.0, 'open_unestimated' => 0];
    if (!$projects) return $out;
    $mode = projectSetting($conn, 'project_labour_mode') ?: 'hours';
    $install = projectInstallCurrency($conn);
    $rates = projectLabourRates($conn);
    $today = gmdate('Y-m-d');
    $ids = array_map('intval', array_keys($projects));
    try {
        $st = $conn->prepare("SELECT t.project_id, t.estimate_hours, t.assigned_analyst_id,
                                       (SELECT COALESCE(SUM(e.time_spent_minutes), 0) FROM task_time_entries e WHERE e.task_id = t.id AND e.is_active = 1) AS logged
                                  FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.status_id
                                 WHERE t.project_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND COALESCE(ts.is_closed, 0) = 0");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $out;   // before estimates existed
    }
    foreach ($rows as $r) {
        $pid = (int)$r['project_id'];
        if ($r['estimate_hours'] === null) { $out[$pid]['open_unestimated']++; continue; }
        $left = max(0.0, (float)$r['estimate_hours'] - (int)$r['logged'] / 60);
        if ($left <= 0) continue;
        $out[$pid]['hours'] += $left;
        if ($mode === 'hours') continue;
        $same = projectCurrencyOf($conn, $projects[$pid]) === $install;
        $rate = null;
        if ($mode === 'rate') {
            $rate = projectRateOn($rates, 'project', $pid, $today);
            if ($rate === null && $same) $rate = projectRateOn($rates, 'default', 0, $today);
        } elseif ($same) {
            $rate = ($r['assigned_analyst_id'] ? projectRateOn($rates, 'analyst', (int)$r['assigned_analyst_id'], $today) : null) ?? projectRateOn($rates, 'default', 0, $today);
        }
        if ($rate === null) { $out[$pid]['unpriced_hours'] += $left; continue; }
        $out[$pid]['cost'] = ($out[$pid]['cost'] ?? 0) + round($left * $rate, 2);
    }
    foreach ($out as &$o) { $o['hours'] = round($o['hours'], 2); $o['unpriced_hours'] = round($o['unpriced_hours'], 2); }
    unset($o);
    return $out;
}

/**
 * Spend over time for the chart (3.3.0): cumulative planned and actual by day,
 * with the forecast from today to the target finish and the lines to measure
 * against. Undated planned money counts from the start; undated actual money
 * from the day its line was last saved; labour on the day it was logged.
 */
function projectBudgetTimeline(PDO $conn, array $project, array $lineRows, array $labourDays, float $planned, float $actual, float $forecast): array
{
    $today = gmdate('Y-m-d');
    $start = $project['start_date'] ?: substr((string)$project['created_datetime'], 0, 10);
    $plan = []; $act = []; $undated = 0;
    foreach ($lineRows as $r) {
        if ($r['planned'] !== null) {
            if (!$r['planned_date']) $undated++;
            $d = $r['planned_date'] ?: $start;
            $plan[$d] = ($plan[$d] ?? 0) + $r['planned'];
        }
        if ($r['actual'] !== null && !$r['currency_mismatch']) {
            $d = $r['spent_date'] ?: ($r['saved'] ?: $today);
            $act[$d] = ($act[$d] ?? 0) + $r['actual'];
        }
    }
    foreach ($labourDays as $d => $c) $act[$d] = ($act[$d] ?? 0) + $c;
    $dates = array_unique(array_merge(array_keys($plan), array_keys($act), [$start, $today]));
    sort($dates);
    $points = []; $p = 0.0; $a = 0.0;
    foreach ($dates as $d) {
        $p += $plan[$d] ?? 0; $a += $act[$d] ?? 0;
        $points[] = ['d' => $d, 'planned' => round($p, 2), 'actual' => $d <= $today ? round($a, 2) : null];
    }
    $end = $project['target_end_date'] ?? null;
    return [
        'points'   => $points,
        'today'    => $today,
        'target'   => $end,
        // A dashed line from what has been spent today to the forecast at the finish.
        'forecast' => $end && $end > $today && round($forecast, 2) != round($actual, 2) ? ['from' => $today, 'to' => $end, 'start' => round($actual, 2), 'end' => round($forecast, 2)] : null,
        'undated'  => $undated,
    ];
}

/** Everything the Budget tab shows. */
function projectBudgetDetail(PDO $conn, array $project, int $analystId): array
{
    $pid = (int)$project['id'];
    $currency = projectCurrencyOf($conn, $project);
    $st = $conn->prepare("SELECT l.*, c.title AS contract_title, c.contract_number, c.contract_value, c.currency AS contract_currency,
                                 cc.code AS cost_centre_code, cc.name AS cost_centre_name
                            FROM project_budget_lines l
                       LEFT JOIN contracts c ON c.id = l.contract_id
                       LEFT JOIN cost_centres cc ON cc.id = l.cost_centre_id
                           WHERE l.project_id = ? ORDER BY l.position, l.id");
    $st->execute([$pid]);
    $lines = []; $timelineRows = [];
    $planned = 0.0; $actual = 0.0; $forecast = 0.0; $cover = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        [$act, $src, $mismatch] = projectLineActual($r, $currency);
        $planned += (float)($r['planned_amount'] ?? 0);
        $actual += (float)($act ?? 0);
        $fc = projectLineForecast($r, $act);
        if (projectLineCoversLabour($r)) $cover += (float)($fc ?? 0); else $forecast += (float)($fc ?? 0);
        $timelineRows[] = ['planned' => $r['planned_amount'] !== null ? (float)$r['planned_amount'] : null, 'actual' => $act, 'currency_mismatch' => $mismatch,
            'planned_date' => $r['planned_date'] ?? null, 'spent_date' => $r['spent_date'] ?? null, 'saved' => substr((string)$r['updated_datetime'], 0, 10)];
        $lines[] = [
            // 3.3.0: when, and what it is expected to cost in the end.
            'planned_date' => $r['planned_date'] ?? null, 'spent_date' => $r['spent_date'] ?? null,
            'forecast_typed' => isset($r['forecast_amount']) && $r['forecast_amount'] !== null ? (float)$r['forecast_amount'] : null,
            'forecast' => $fc, 'covers_labour' => projectLineCoversLabour($r),
            'id' => (int)$r['id'], 'title' => $r['title'], 'category' => $r['category'], 'notes' => $r['notes'],
            'planned' => $r['planned_amount'] !== null ? (float)$r['planned_amount'] : null,
            'actual_typed' => $r['actual_amount'] !== null ? (float)$r['actual_amount'] : null,
            'actual' => $act, 'actual_source' => $src, 'currency_mismatch' => $mismatch,
            'contract' => $r['contract_id'] ? ['id' => (int)$r['contract_id'], 'title' => $r['contract_title'], 'number' => $r['contract_number'],
                'value' => $r['contract_value'] !== null ? (float)$r['contract_value'] : null, 'currency' => $r['contract_currency']] : null,
            'cost_centre' => $r['cost_centre_id'] ? ['id' => (int)$r['cost_centre_id'], 'code' => $r['cost_centre_code'], 'name' => $r['cost_centre_name']] : null,
        ];
    }
    $labour = projectLabour($conn, [$pid => $project], true)[$pid];
    if ($labour['cost'] !== null) $actual += $labour['cost'];
    $withToCome = projectSetting($conn, 'project_forecast_labour') !== '0';
    $toCome = projectLabourToCome($conn, [$pid => $project])[$pid];
    $forecast += max($cover, (float)($labour['cost'] ?? 0) + ($withToCome ? (float)($toCome['cost'] ?? 0) : 0));
    $days = $labour['days'] ?? [];
    unset($labour['days']);
    $mode = projectSetting($conn, 'project_labour_mode') ?: 'hours';
    $rates = projectLabourRates($conn);
    $today = gmdate('Y-m-d');
    return [
        'currency'      => $currency,
        'install_currency' => projectInstallCurrency($conn),
        'per_project_currency' => projectSetting($conn, 'project_currency_per_project') === '1',
        'labour_mode'   => $mode,
        'labour'        => $labour,
        // The project's own rate history (rate mode only); never anybody's personal rate.
        'project_rates' => $mode === 'rate' ? array_map(fn($x) => ['from' => $x[0], 'rate' => $x[1]], $rates['project'][$pid] ?? []) : [],
        'default_rate_now' => $mode !== 'hours' ? projectRateOn($rates, 'default', 0, $today) : null,
        'lines'         => $lines,
        'planned'       => round($planned, 2),
        'actual'        => round($actual, 2),
        'remaining'     => round($planned - $actual, 2),
        // 3.3.0: the forecast (estimate at completion) and spend over time.
        'forecast'      => round($forecast, 2),
        'labour_to_come'=> $toCome + ['counted' => $withToCome],
        'cost_basis'    => projectSetting($conn, 'project_cost_basis') === 'forecast' ? 'forecast' : 'actual',
        'timeline'      => projectBudgetTimeline($conn, $project, $timelineRows, $days, $planned, $actual, $forecast),
        'categories'    => PROJECT_BUDGET_CATEGORIES,
        'cost_centres'  => projectBudgetCostCentres($conn, $project),
        'contracts'     => analystCanAccessModule($conn, $analystId, 'contracts') ? projectBudgetContracts($conn, $pid) : null,
    ];
}

/** Active cost centres of the project's company (NULL company = the Default one). */
function projectBudgetCostCentres(PDO $conn, array $project): array
{
    try {
        $tenant = $project['tenant_id'] !== null ? (int)$project['tenant_id'] : (int)getDefaultTenantId($conn);
        $st = $conn->prepare("SELECT id, code, name FROM cost_centres WHERE tenant_id = ? AND is_active = 1 ORDER BY code");
        $st->execute([$tenant]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name']], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

/** The contracts linked to the project on Connections - what a line may name. */
function projectBudgetContracts(PDO $conn, int $projectId): array
{
    try {
        $st = $conn->prepare("SELECT c.id, c.title, c.contract_number, c.contract_value, c.currency
                                FROM project_contracts pc JOIN contracts c ON c.id = pc.contract_id
                               WHERE pc.project_id = ? ORDER BY c.title");
        $st->execute([$projectId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'number' => $r['contract_number'],
            'value' => $r['contract_value'] !== null ? (float)$r['contract_value'] : null, 'currency' => $r['currency']], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

/** A money amount from input: null for empty, else a number with at most two decimals. */
function projectMoney($v): ?float
{
    if ($v === null || $v === '') return null;
    $s = str_replace([',', ' '], '', trim((string)$v));
    if (!preg_match('/^-?\d{1,14}(\.\d{1,2})?$/', $s)) throw new ServiceError('validation', 'invalid_field', 'Enter an amount like 1250 or 1250.50.');
    return (float)$s;
}

/** ISO 4217 code check (three letters). */
function projectValidCurrency(string $c): bool
{
    return (bool)preg_match('/^[A-Z]{3}$/', $c);
}
