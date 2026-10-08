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
function projectLabour(PDO $conn, array $projects): array
{
    $out = [];
    foreach ($projects as $id => $_) $out[(int)$id] = ['minutes' => 0, 'priced_minutes' => 0, 'cost' => null, 'unpriced_minutes' => 0];
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
    $st = $conn->prepare("SELECT l.project_id, l.planned_amount, l.actual_amount, c.contract_value, c.currency AS contract_currency
                            FROM project_budget_lines l LEFT JOIN contracts c ON c.id = l.contract_id
                           WHERE l.project_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['project_id'];
        $cur = projectCurrencyOf($conn, $projects[$pid]);
        $o = $out[$pid] ?? ['planned' => 0.0, 'actual' => 0.0, 'currency' => $cur, 'has_budget' => false];
        $o['has_budget'] = true;
        $o['planned'] += (float)($r['planned_amount'] ?? 0);
        $o['actual']  += (float)(projectLineActual($r, $cur)[0] ?? 0);
        $out[$pid] = $o;
    }
    foreach (projectLabour($conn, $projects) as $pid => $l) {
        if ($l['cost'] === null || $l['cost'] == 0) continue;
        $o = $out[$pid] ?? ['planned' => 0.0, 'actual' => 0.0, 'currency' => projectCurrencyOf($conn, $projects[$pid]), 'has_budget' => false];
        $o['actual'] += $l['cost'];
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
    $lines = [];
    $planned = 0.0; $actual = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        [$act, $src, $mismatch] = projectLineActual($r, $currency);
        $planned += (float)($r['planned_amount'] ?? 0);
        $actual += (float)($act ?? 0);
        $lines[] = [
            'id' => (int)$r['id'], 'title' => $r['title'], 'category' => $r['category'], 'notes' => $r['notes'],
            'planned' => $r['planned_amount'] !== null ? (float)$r['planned_amount'] : null,
            'actual_typed' => $r['actual_amount'] !== null ? (float)$r['actual_amount'] : null,
            'actual' => $act, 'actual_source' => $src, 'currency_mismatch' => $mismatch,
            'contract' => $r['contract_id'] ? ['id' => (int)$r['contract_id'], 'title' => $r['contract_title'], 'number' => $r['contract_number'],
                'value' => $r['contract_value'] !== null ? (float)$r['contract_value'] : null, 'currency' => $r['contract_currency']] : null,
            'cost_centre' => $r['cost_centre_id'] ? ['id' => (int)$r['cost_centre_id'], 'code' => $r['cost_centre_code'], 'name' => $r['cost_centre_name']] : null,
        ];
    }
    $labour = projectLabour($conn, [$pid => $project])[$pid];
    if ($labour['cost'] !== null) $actual += $labour['cost'];
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
