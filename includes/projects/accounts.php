<?php
/**
 * Projects - accounts on the budget (GH #282, slice 2): which account(s) of the
 * chart of accounts a budget line counts in, and a project's default accounts.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔑 ONE SHAPE FOR EVERY CASE. A line's accounts are rows in
 * project_budget_line_accounts: (line, account, percent). One account is one row
 * at 100; a split is several rows adding up to 100. Always percentages, so a
 * line's planned, actual and forecast are split the same way ("planned, actual and
 * forecast are all split the same way" - the design). "Split by amount" is how the
 * EDITOR shows it: amounts are turned into exact percentages of the line when it
 * is saved, and project_budget_lines.account_split remembers which way to show it.
 * If the line's amount changes later, a split by amount stays in proportion - it
 * still adds up to the whole line, which is the one thing it must always do.
 *
 * 🔑 NOTHING LEFT UNCOUNTED. A line with no rows counts in the project's OTHER
 * account; time logged counts in its LABOUR account (slice 3 onwards). A project's
 * default is its own (projects.other_account_id / labour_account_id), else the
 * install's (Projects -> Settings -> Budget) - stored as a CODE, because accounts
 * are per company and the same chart is usually loaded into every company. A code
 * the project's company does not have = no default; the breakdown (slice 5) then
 * shows "Not assigned to an account", so it still adds up to the project total.
 *
 * 🔑 COMPANY. A project uses the accounts of its own company (NULL = Default), as
 * it uses its cost centres. New choices must be ACTIVE; a line or project keeps an
 * account that has since been made inactive, as with cost centres.
 *
 * @wiki Chart-of-Accounts-Developer-Guide
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/budget.php';   // projectMoney()

/** At most this many accounts on one line. */
const PROJECT_LINE_ACCOUNTS_MAX = 20;

/** Have Database Verification run for slice 2: the accounts tables and the projects columns? Cached per request. */
function projectAccountsReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query("SELECT 1 FROM accounts LIMIT 0");
            $conn->query("SELECT 1 FROM project_budget_line_accounts LIMIT 0");
            $conn->query("SELECT labour_account_id, other_account_id FROM projects LIMIT 0");
            $conn->query("SELECT account_split FROM project_budget_lines LIMIT 0");
            $ready = true;
        } catch (Throwable) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * The project's company id for accounts: its own, or the Default company when it has none.
 *
 * @param array $project  the projects row - tenant_id is read
 */
function projectAccountsTenant(PDO $conn, array $project): int
{
    return ($project['tenant_id'] ?? null) !== null ? (int)$project['tenant_id'] : (int)getDefaultTenantId($conn);
}

/**
 * One account for the page: id, code, name, kind, is_active.
 *
 * @param array $r  an accounts row (id, code, name, kind, is_active)
 */
function projectAccountShape(array $r): array
{
    return ['id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name'], 'kind' => $r['kind'], 'is_active' => (bool)(int)$r['is_active']];
}

/**
 * The ACTIVE accounts of the project's company, by code - what a line or a default may newly name.
 *
 * @param array $project  the projects row
 */
function projectAccountOptions(PDO $conn, array $project): array
{
    if (!projectAccountsReady($conn)) return [];
    $st = $conn->prepare("SELECT id, code, name, kind, is_active FROM accounts WHERE tenant_id = ? AND is_active = 1 ORDER BY code");
    $st->execute([projectAccountsTenant($conn, $project)]);
    return array_map('projectAccountShape', $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * The active COST accounts of the project's company - expense, fixed asset, payroll:
 * what a budget line, a task or a project default may newly name.
 *
 * TRAP (5.1.0, slice 5): never offer an income account for a cost. In the By-account
 * breakdown a cost on an income account would land in the Income section and quietly
 * cut "income less costs". Income lines use projectIncomeAccounts() - the other half.
 *
 * @param array $project  the projects row
 */
function projectCostAccounts(PDO $conn, array $project): array
{
    return array_values(array_filter(projectAccountOptions($conn, $project), fn($a) => $a['kind'] !== 'income'));
}

/**
 * Every line's accounts: line id => [[account..., percent], ...] in position order.
 *
 * @param int[] $lineIds  project_budget_lines ids
 */
function projectLineAccounts(PDO $conn, array $lineIds): array
{
    $out = [];
    $lineIds = array_values(array_filter(array_map('intval', $lineIds)));
    if (!$lineIds || !projectAccountsReady($conn)) return $out;
    $st = $conn->prepare("SELECT la.line_id, la.percent, a.id, a.code, a.name, a.kind, a.is_active
                            FROM project_budget_line_accounts la JOIN accounts a ON a.id = la.account_id
                           WHERE la.line_id IN (" . implode(',', array_fill(0, count($lineIds), '?')) . ")
                        ORDER BY la.line_id, la.position, la.id");
    $st->execute($lineIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['line_id']][] = projectAccountShape($r) + ['percent' => round((float)$r['percent'], 6)];
    }
    return $out;
}

/**
 * Check a line's accounts as sent, and turn them into percentage rows.
 *
 * $accounts: [] (none), or [{account_id, percent}] / [{account_id, amount}], the
 * second with $split 'amount'. One row with no percent or amount = that account for
 * the whole line. Amounts must add up to the line's base - its planned amount, else
 * its actual, else its forecast - and are turned into percentages of it.
 *
 * @param array $project  the projects row - its company decides which accounts are allowed
 * @param int $lineId  the line being saved (0 = new) - it may keep accounts it already has, even inactive ones
 * @param mixed $accounts  the rows as sent (untrusted)
 * @param ?string $split  'percent' (the default) or 'amount'
 * @param ?float $base  the line's planned amount, else actual, else forecast - what amounts must add up to
 * @return array [rows => [[account_id, percent], ...], split => 'percent'|'amount'|null]
 */
function projectValidateLineAccounts(PDO $conn, array $project, int $lineId, $accounts, ?string $split, ?float $base): array
{
    if (!projectAccountsReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification to add accounts to budget lines.');
    // Which accounts may be named: the company's active COST ones, plus any this line already has.
    $allowed = array_column(projectCostAccounts($conn, $project), 'id');
    if ($lineId > 0) {
        $st = $conn->prepare("SELECT account_id FROM project_budget_line_accounts WHERE line_id = ?");
        $st->execute([$lineId]);
        $allowed = array_merge($allowed, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }
    return projectSplitRows($accounts, $split, $base, $allowed);
}

/**
 * THE split rules, once - for budget lines and tasks alike: a list of {account_id, percent}
 * or {account_id, amount}, each account allowed and named once, at most
 * PROJECT_LINE_ACCOUNTS_MAX, adding up to 100% (or to $base, for amounts - turned into
 * percentages of it). One row with no figure = that account for the whole.
 *
 * @param mixed $accounts  the rows as sent (untrusted); null, '' or [] = none
 * @param ?string $split  'percent' (the default) or 'amount'
 * @param ?float $base  what amounts must add up to
 * @param int[] $allowed  the account ids that may be named
 * @return array [rows => [[account_id, percent], ...], split => 'percent'|'amount'|null]
 */
function projectSplitRows($accounts, ?string $split, ?float $base, array $allowed): array
{
    if ($accounts === null || $accounts === '' || $accounts === []) return ['rows' => [], 'split' => null];
    if (is_string($accounts)) $accounts = json_decode($accounts, true);
    if (!is_array($accounts) || array_values($accounts) !== $accounts) throw new ServiceError('validation', 'invalid_field', "'accounts' must be a list of {account_id, percent} or {account_id, amount}.");
    if (count($accounts) > PROJECT_LINE_ACCOUNTS_MAX) throw new ServiceError('validation', 'invalid_field', 'A split can have at most ' . PROJECT_LINE_ACCOUNTS_MAX . ' accounts.');
    $split = $split === 'amount' ? 'amount' : 'percent';

    $seen = [];
    $rows = [];
    foreach ($accounts as $a) {
        if (!is_array($a)) throw new ServiceError('validation', 'invalid_field', 'Each account in the split needs an account_id.');
        $id = (int)($a['account_id'] ?? 0);
        if ($id <= 0 || !in_array($id, $allowed, true)) throw new ServiceError('validation', 'invalid_field', 'Choose an active account of the project\'s company.');
        if (isset($seen[$id])) throw new ServiceError('validation', 'invalid_field', 'The same account is in the split twice.');
        $seen[$id] = true;
        $key = $split === 'amount' ? 'amount' : 'percent';
        $raw = $a[$key] ?? null;
        $rows[] = ['account_id' => $id, 'value' => ($raw === null || $raw === '') ? null : $raw];
    }

    // One account, no figure: the whole line.
    if (count($rows) === 1 && $rows[0]['value'] === null) return ['rows' => [[$rows[0]['account_id'], 100.0]], 'split' => null];

    if ($split === 'amount') {
        if ($base === null || abs($base) < 0.005) throw new ServiceError('validation', 'invalid_field', 'Give the line a planned or actual amount before splitting it by amount - or split it by percentage.');
        $sum = 0.0;
        foreach ($rows as &$r) {
            if ($r['value'] === null) throw new ServiceError('validation', 'missing_field', 'Give every account in the split an amount.');
            $r['value'] = projectMoney($r['value']);
            if ($r['value'] == 0 || ($r['value'] < 0) !== ($base < 0)) throw new ServiceError('validation', 'invalid_field', 'Each amount in the split must be more than nothing, and the same sign as the line.');
            $sum += $r['value'];
        }
        unset($r);
        if (abs($sum - $base) > 0.005) {
            throw new ServiceError('validation', 'invalid_field', 'The split adds up to ' . number_format($sum, 2) . ' but the line is ' . number_format($base, 2) . ' - the amounts must add up to the whole line.');
        }
        return ['rows' => array_map(fn($r) => [$r['account_id'], round($r['value'] / $base * 100, 6)], $rows), 'split' => 'amount'];
    }

    $sum = 0.0;
    foreach ($rows as &$r) {
        if ($r['value'] === null) throw new ServiceError('validation', 'missing_field', 'Give every account in the split a percentage.');
        $s = str_replace([',', ' ', '%'], ['.', '', ''], trim((string)$r['value']));
        if (!preg_match('/^\d{1,3}(\.\d{1,6})?$/', $s) || (float)$s <= 0 || (float)$s > 100) throw new ServiceError('validation', 'invalid_field', 'Each percentage must be more than 0 and no more than 100.');
        $r['value'] = (float)$s;
        $sum += $r['value'];
    }
    unset($r);
    if (abs($sum - 100) > 0.01) throw new ServiceError('validation', 'invalid_field', 'The split adds up to ' . rtrim(rtrim(number_format($sum, 4, '.', ''), '0'), '.') . '% - it must add up to 100%.');
    return ['rows' => array_map(fn($r) => [$r['account_id'], round($r['value'], 6)], $rows), 'split' => count($rows) > 1 ? 'percent' : null];
}

/**
 * Replace a line's accounts with already-checked rows.
 *
 * @param int $lineId  the project_budget_lines id
 * @param array $checked  what projectValidateLineAccounts() returned
 */
function projectSaveLineAccounts(PDO $conn, int $lineId, array $checked): void
{
    $conn->prepare("DELETE FROM project_budget_line_accounts WHERE line_id = ?")->execute([$lineId]);
    $ins = $conn->prepare("INSERT INTO project_budget_line_accounts (line_id, account_id, percent, position) VALUES (?, ?, ?, ?)");
    foreach (array_values($checked['rows']) as $i => [$accountId, $pct]) $ins->execute([$lineId, $accountId, $pct, $i]);
    $conn->prepare("UPDATE project_budget_lines SET account_split = ? WHERE id = ?")->execute([$checked['split'], $lineId]);
}

/**
 * A project's default accounts and where each came from:
 * ['labour' => [account + source]|null, 'other' => ...], source 'project' or 'install'.
 *
 * @param array $project  the projects row - labour_account_id, other_account_id and tenant_id are read
 */
function projectDefaultAccounts(PDO $conn, array $project): array
{
    $out = ['labour' => null, 'other' => null];
    if (!projectAccountsReady($conn)) return $out;
    $tenant = projectAccountsTenant($conn, $project);
    $byId = $conn->prepare("SELECT id, code, name, kind, is_active FROM accounts WHERE id = ? AND tenant_id = ?");
    $byCode = $conn->prepare("SELECT id, code, name, kind, is_active FROM accounts WHERE tenant_id = ? AND code = ?");
    foreach (['labour' => 'labour_account_id', 'other' => 'other_account_id'] as $which => $col) {
        $id = (int)($project[$col] ?? 0);
        if ($id > 0) {
            $byId->execute([$id, $tenant]);
            if ($r = $byId->fetch(PDO::FETCH_ASSOC)) { $out[$which] = projectAccountShape($r) + ['source' => 'project']; continue; }
        }
        // The install's default, by code in the project's company.
        $code = (string)projectSetting($conn, 'project_default_' . $which . '_account');
        if ($code !== '') {
            $byCode->execute([$tenant, $code]);
            if ($r = $byCode->fetch(PDO::FETCH_ASSOC)) $out[$which] = projectAccountShape($r) + ['source' => 'install'];
        }
    }
    return $out;
}

/**
 * Every account in use by projects (budget lines and project defaults), for
 * AccountsService::delete(): [what => count], empty when unused.
 *
 * @param int $accountId  the accounts id
 */
function projectAccountUsage(PDO $conn, int $accountId): array
{
    $out = [];
    try {
        $n = $conn->prepare("SELECT COUNT(*) FROM project_budget_line_accounts WHERE account_id = ?");
        $n->execute([$accountId]);
        if ($c = (int)$n->fetchColumn()) $out['project budget line(s)'] = $c;
        $n = $conn->prepare("SELECT COUNT(*) FROM projects WHERE labour_account_id = ? OR other_account_id = ?");
        $n->execute([$accountId, $accountId]);
        if ($c = (int)$n->fetchColumn()) $out['project default(s)'] = $c;
        // Slice 3: a task's split - in ANY version, because an old version can still price past hours.
        $n = $conn->prepare("SELECT COUNT(DISTINCT v.task_id) FROM task_account_shares s JOIN task_account_versions v ON v.id = s.version_id WHERE s.account_id = ?");
        $n->execute([$accountId]);
        if ($c = (int)$n->fetchColumn()) $out['task(s)'] = $c;
        // Slice 4: income lines.
        $n = $conn->prepare("SELECT COUNT(*) FROM project_income_lines WHERE account_id = ?");
        $n->execute([$accountId]);
        if ($c = (int)$n->fetchColumn()) $out['project income line(s)'] = $c;
    } catch (Throwable) { /* before Database Verification nothing can use it */ }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Slice 3 - accounts on tasks: where the TIME logged on a task counts
// ─────────────────────────────────────────────────────────────────────────────
//
// 🔑 DATED VERSIONS. A task's split is kept as versions (task_account_versions):
// each change is a version from that day; a second change the same day replaces
// it. A version with no shares = "no account" - its time counts in the project's
// account for time. Which version prices an hour is the project_account_resplit
// setting (Ed, 2026-11-12):
//   all        every hour follows the task's LATEST version (the default - "a
//              project's books are re-read, not posted")
//   from_then  each hour follows the version in force on the day it was logged;
//              hours from before the task's first version count in the project's
//              account for time, as they did when they were logged
// Percentages only (Ed): time is logged against a task as a whole.
//
// 🔑 SUBTASKS follow their parent unless they have versions of their own - set once
// per piece of work. Subtasks carry no project_id; they belong through the parent.

/** Has Database Verification created the slice 3 tables? Cached per request. */
function projectTaskAccountsReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM task_account_versions LIMIT 0"); $conn->query("SELECT 1 FROM task_account_shares LIMIT 0"); $ready = projectAccountsReady($conn); }
        catch (Throwable) { $ready = false; }
    }
    return $ready;
}

/**
 * Every version of some tasks' splits, oldest first: task id => [['from' => 'Y-m-d', 'shares' => [[account + percent], ...]], ...].
 *
 * @param int[] $taskIds  tasks ids
 */
function projectTaskAccountVersions(PDO $conn, array $taskIds): array
{
    $out = [];
    $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
    if (!$taskIds || !projectTaskAccountsReady($conn)) return $out;
    $in = implode(',', array_fill(0, count($taskIds), '?'));
    $v = $conn->prepare("SELECT id, task_id, effective_from FROM task_account_versions WHERE task_id IN ($in) ORDER BY task_id, effective_from, id");
    $v->execute($taskIds);
    $versions = $v->fetchAll(PDO::FETCH_ASSOC);
    if (!$versions) return $out;
    $vid = array_map(fn($r) => (int)$r['id'], $versions);
    $s = $conn->prepare("SELECT s.version_id, s.percent, a.id, a.code, a.name, a.kind, a.is_active
                           FROM task_account_shares s JOIN accounts a ON a.id = s.account_id
                          WHERE s.version_id IN (" . implode(',', array_fill(0, count($vid), '?')) . ")
                       ORDER BY s.version_id, s.position, s.id");
    $s->execute($vid);
    $shares = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $shares[(int)$r['version_id']][] = projectAccountShape($r) + ['percent' => round((float)$r['percent'], 6)];
    foreach ($versions as $r) $out[(int)$r['task_id']][] = ['from' => (string)$r['effective_from'], 'shares' => $shares[(int)$r['id']] ?? []];
    return $out;
}

/**
 * The shares that price an hour logged on $date, by the resplit setting - or null when
 * no version applies (then the hour counts in the project's account for time).
 *
 * @param array $versions  one task's versions, oldest first, from projectTaskAccountVersions()
 * @param string $date  the day the hour was logged, 'Y-m-d'
 * @param string $mode  'all' | 'from_then'
 */
function projectTaskSharesOn(array $versions, string $date, string $mode): ?array
{
    if (!$versions) return null;
    if ($mode !== 'from_then') return end($versions)['shares'];
    $hit = null;
    foreach ($versions as $v) { if ($v['from'] <= $date) $hit = $v; else break; }
    return $hit === null ? null : $hit['shares'];
}

/**
 * Check a task's split as sent: [] (no account), [{account_id}] (one), or
 * [{account_id, percent}, ...] adding up to 100. Percentages only (Ed, 2026-11-12):
 * time is logged against a task as a whole.
 *
 * @param array $project  the task's project - its company decides which accounts are allowed
 * @param int $taskId  the task; accounts in any of its versions may be named again even if since made inactive
 * @param mixed $accounts  the rows as sent (untrusted)
 * @return array [[account_id, percent], ...]
 */
function projectValidateTaskAccounts(PDO $conn, array $project, int $taskId, $accounts): array
{
    if (!projectTaskAccountsReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification to add accounts to tasks.');
    if (is_array($accounts)) {
        foreach ($accounts as $a) if (is_array($a) && array_key_exists('amount', $a)) throw new ServiceError('validation', 'invalid_field', 'A task\'s time is split by percentage only.');
    }
    $allowed = array_column(projectCostAccounts($conn, $project), 'id');
    foreach (projectTaskAccountVersions($conn, [$taskId])[$taskId] ?? [] as $v) foreach ($v['shares'] as $sh) $allowed[] = $sh['id'];
    return projectSplitRows($accounts, 'percent', null, $allowed)['rows'];
}

/**
 * Save a task's split as today's version (replacing a version already made today). Does
 * nothing when it is the same as the task's latest version - no empty history.
 *
 * @param int $taskId  the task
 * @param array $rows  [[account_id, percent], ...] from projectValidateTaskAccounts(); [] = no account
 * @return bool  true when a version was written
 */
function projectSaveTaskAccounts(PDO $conn, int $taskId, array $rows): bool
{
    $versions = projectTaskAccountVersions($conn, [$taskId])[$taskId] ?? [];
    $latest = $versions ? end($versions)['shares'] : null;
    $norm = fn(array $shares) => array_map(fn($s) => [(int)$s['id'], round((float)$s['percent'], 4)], $shares);
    if ($latest !== null && $norm($latest) === array_map(fn($r) => [(int)$r[0], round((float)$r[1], 4)], $rows)) return false;
    if ($latest === null && !$rows) return false;   // nothing before, nothing now
    $today = gmdate('Y-m-d');
    $own = !$conn->inTransaction();
    if ($own) $conn->beginTransaction();
    try {
        $conn->prepare("DELETE FROM task_account_versions WHERE task_id = ? AND effective_from = ?")->execute([$taskId, $today]);   // shares cascade
        $conn->prepare("INSERT INTO task_account_versions (task_id, effective_from, created_datetime) VALUES (?, ?, UTC_TIMESTAMP())")->execute([$taskId, $today]);
        $vid = (int)$conn->lastInsertId();
        $ins = $conn->prepare("INSERT INTO task_account_shares (version_id, account_id, percent, position) VALUES (?, ?, ?, ?)");
        foreach (array_values($rows) as $i => [$accountId, $pct]) $ins->execute([$vid, $accountId, $pct, $i]);
        if ($own) $conn->commit();
    } catch (Throwable $e) {
        if ($own) $conn->rollBack();
        throw $e;
    }
    return true;
}

/**
 * Where a project's logged time counts, account by account: the tasks' (and subtasks')
 * splits by the resplit setting, and the project's account for time for the rest.
 * Priced exactly as projectLabour() prices it - minutes always, cost unless hours mode.
 *
 * @param array $project  the projects row (needs id, currency, tenant_id, labour_account_id)
 * @return array [['account' => shape|null, 'minutes' => int, 'cost' => float|null, 'default' => bool], ...]
 *   account null = not assigned to an account (no split and no account for time)
 */
function projectLabourByAccount(PDO $conn, array $project): array
{
    require_once __DIR__ . '/budget.php';
    $pid = (int)$project['id'];
    $entries = projectTimeEntries($conn, [$pid]);
    if (!$entries) return [];
    $mode = projectSetting($conn, 'project_labour_mode') ?: 'hours';
    $resplit = projectSetting($conn, 'project_account_resplit') === 'from_then' ? 'from_then' : 'all';
    $rates = projectLabourRates($conn);
    $same = projectCurrencyOf($conn, $project) === projectInstallCurrency($conn);
    $taskIds = [];
    foreach ($entries as $e) { $taskIds[] = (int)$e['task_id']; if ($e['parent_task_id']) $taskIds[] = (int)$e['parent_task_id']; }
    $versions = projectTaskAccountsReady($conn) ? projectTaskAccountVersions($conn, $taskIds) : [];
    $default = projectDefaultAccounts($conn, $project)['labour'];
    $out = [];   // key => row
    $add = function (?array $acc, float $minutes, ?float $cost, bool $isDefault) use (&$out, $mode) {
        $k = $acc ? (string)$acc['id'] : '-';
        if (!isset($out[$k])) $out[$k] = ['account' => $acc, 'minutes' => 0.0, 'cost' => $mode === 'hours' ? null : 0.0, 'default' => $isDefault];
        $out[$k]['minutes'] += $minutes;
        if ($cost !== null && $out[$k]['cost'] !== null) $out[$k]['cost'] += $cost;
    };
    foreach ($entries as $e) {
        $min = (int)$e['time_spent_minutes'];
        $rate = $mode === 'hours' ? null : projectEntryRate($rates, $mode, $pid, $same, (int)$e['analyst_id'], $e['d']);
        $cost = $rate === null ? null : $min / 60 * $rate;
        // The task's own split; for a subtask with none of its own - no version, or a
        // version with no shares (cleared) - its parent's, by the same rule.
        $shares = projectTaskSharesOn($versions[(int)$e['task_id']] ?? [], $e['d'], $resplit);
        if (!$shares && $e['parent_task_id']) $shares = projectTaskSharesOn($versions[(int)$e['parent_task_id']] ?? [], $e['d'], $resplit);
        if (!$shares) { $add($default ? array_diff_key($default, ['source' => 1]) : null, $min, $cost, true); continue; }
        foreach ($shares as $sh) {
            $f = $sh['percent'] / 100;
            $add(array_diff_key($sh, ['percent' => 1]), $min * $f, $cost === null ? null : $cost * $f, false);
        }
    }
    foreach ($out as &$o) { $o['minutes'] = (int)round($o['minutes']); if ($o['cost'] !== null) $o['cost'] = round($o['cost'], 2); }
    unset($o);
    // By code, "not assigned" last.
    uasort($out, fn($a, $b) => ($a['account'] === null) <=> ($b['account'] === null) ?: strcmp($a['account']['code'] ?? '', $b['account']['code'] ?? ''));
    return array_values($out);
}

// ─────────────────────────────────────────────────────────────────────────────
//  Slice 4 - income lines: money a project brings in
// ─────────────────────────────────────────────────────────────────────────────
//
// 🔑 BESIDE THE BUDGET, NEVER IN IT. The budget's planned / actual / remaining are
// costs; income has its own totals, and the Budget tab shows "income less costs".
// Same currency as everything in a project's budget. An income line's account is an
// INCOME account (kind income) of the project's company - an expense account on an
// income line would land money in the wrong section of the breakdown (slice 5).

/** Has Database Verification created project_income_lines? Cached per request. */
function projectIncomeReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM project_income_lines LIMIT 0"); $ready = true; }
        catch (Throwable) { $ready = false; }
    }
    return $ready;
}

/**
 * The active INCOME accounts of the project's company - what an income line may newly name.
 *
 * @param array $project  the projects row
 */
function projectIncomeAccounts(PDO $conn, array $project): array
{
    return array_values(array_filter(projectAccountOptions($conn, $project), fn($a) => $a['kind'] === 'income'));
}

/**
 * A project's income lines and their totals, in its currency:
 * ['lines' => [...], 'planned' => float, 'received' => float].
 *
 * @param int $projectId  the project
 */
function projectIncome(PDO $conn, int $projectId): array
{
    $out = ['lines' => [], 'planned' => 0.0, 'received' => 0.0];
    if (!projectIncomeReady($conn)) return $out;
    $st = $conn->prepare("SELECT l.*, a.code AS account_code, a.name AS account_name, a.kind AS account_kind, a.is_active AS account_active
                            FROM project_income_lines l LEFT JOIN accounts a ON a.id = l.account_id
                           WHERE l.project_id = ? ORDER BY l.position, l.id");
    $st->execute([$projectId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $planned = $r['planned_amount'] !== null ? (float)$r['planned_amount'] : null;
        $received = $r['received_amount'] !== null ? (float)$r['received_amount'] : null;
        $out['planned'] += (float)$planned;
        $out['received'] += (float)$received;
        $out['lines'][] = [
            'id' => (int)$r['id'], 'title' => $r['title'], 'planned' => $planned, 'received' => $received,
            'planned_date' => $r['planned_date'], 'received_date' => $r['received_date'], 'notes' => $r['notes'],
            'account' => $r['account_id'] ? projectAccountShape(['id' => $r['account_id'], 'code' => $r['account_code'], 'name' => $r['account_name'], 'kind' => $r['account_kind'], 'is_active' => $r['account_active']]) : null,
        ];
    }
    $out['planned'] = round($out['planned'], 2);
    $out['received'] = round($out['received'], 2);
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Slice 5 - the breakdown: a project's costs and income, account by account
// ─────────────────────────────────────────────────────────────────────────────
//
// 🔑 IT ADDS UP TO THE BUDGET TAB. The costs' planned / actual / forecast totals are
// exactly the tiles' (projectBudgetDetail()), worked out by the same functions:
//   planned   the lines' planned amounts, split by their accounts
//   actual    the lines' actuals (projectLineActual - another currency is never
//             added) plus labour as priced (projectLabourByAccount)
//   forecast  the lines' forecasts, plus labour by the "nothing counted twice" rule:
//             a labour line with no typed actual is the PLAN for labour, so the
//             forecast takes the larger of those lines and labour (logged + still to
//             come) - here the same choice is made, then split by account
// A line with no account counts in the project's account for other spending; time
// with none in its account for time; with no such account, "not assigned" - so the
// breakdown always adds up to the project total.
//
// 🔑 HOURS, NEVER AN INVENTED PRICE. In hours mode labour has no money: each row
// carries the hours as well, and only lines and income are money.
//
// Income (slice 4) is its own section and is never netted into the costs.

/**
 * Labour still to come, account by account (5.1.0): the hours left on open tasks'
 * estimates, priced at today's rate exactly as projectLabourToCome() prices them, and
 * split by each task's CURRENT split (future hours follow today's split in either
 * resplit mode); a task with none counts in the project's account for time.
 *
 * @param array $project  the projects row
 * @return array account id or '-' => ['account' => shape|null, 'hours' => float, 'cost' => float|null]
 */
function projectLabourToComeByAccount(PDO $conn, array $project): array
{
    require_once __DIR__ . '/budget.php';
    $pid = (int)$project['id'];
    $mode = projectSetting($conn, 'project_labour_mode') ?: 'hours';
    $rates = projectLabourRates($conn);
    $today = gmdate('Y-m-d');
    $same = projectCurrencyOf($conn, $project) === projectInstallCurrency($conn);
    try {
        $st = $conn->prepare("SELECT t.id, t.estimate_hours, t.assigned_analyst_id,
                                     (SELECT COALESCE(SUM(e.time_spent_minutes), 0) FROM task_time_entries e WHERE e.task_id = t.id AND e.is_active = 1) AS logged
                                FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.status_id
                               WHERE t.project_id = ? AND COALESCE(ts.is_closed, 0) = 0 AND t.estimate_hours IS NOT NULL");
        $st->execute([$pid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    $versions = projectTaskAccountsReady($conn) ? projectTaskAccountVersions($conn, array_column($rows, 'id')) : [];
    $default = projectDefaultAccounts($conn, $project)['labour'];
    $defaultShare = $default ? array_diff_key($default, ['source' => 1]) + ['percent' => 100] : ['_none' => true, 'percent' => 100];
    $out = [];
    foreach ($rows as $r) {
        $left = max(0.0, (float)$r['estimate_hours'] - (int)$r['logged'] / 60);
        if ($left <= 0) continue;
        // The same rate projectLabourToCome() would use: today's.
        $rate = null;
        if ($mode === 'rate') {
            $rate = projectRateOn($rates, 'project', $pid, $today);
            if ($rate === null && $same) $rate = projectRateOn($rates, 'default', 0, $today);
        } elseif ($mode === 'analyst' && $same) {
            $rate = ($r['assigned_analyst_id'] ? projectRateOn($rates, 'analyst', (int)$r['assigned_analyst_id'], $today) : null) ?? projectRateOn($rates, 'default', 0, $today);
        }
        $shares = projectTaskSharesOn($versions[(int)$r['id']] ?? [], $today, 'all') ?: [$defaultShare];
        foreach ($shares as $sh) {
            $acc = isset($sh['_none']) ? null : array_diff_key($sh, ['percent' => 1]);
            $k = $acc ? (string)$acc['id'] : '-';
            $f = $sh['percent'] / 100;
            $out[$k] = $out[$k] ?? ['account' => $acc, 'hours' => 0.0, 'cost' => $mode === 'hours' ? null : 0.0];
            $out[$k]['hours'] += $left * $f;
            if ($rate !== null && $out[$k]['cost'] !== null) $out[$k]['cost'] += round($left * $rate, 2) * $f;
        }
    }
    return $out;
}

/**
 * A project's costs and income by account (GH #282, slice 5) - see the notes above.
 *
 * @param array $project  the projects row
 * @return array [currency, labour_mode, sections => [kind => [rows, planned, actual, forecast, minutes]],
 *   unassigned => row|null, costs => [planned, actual, forecast], income => [planned, actual, forecast],
 *   net => [planned, actual, forecast]]; a row is [account, planned, actual, forecast, minutes]
 */
function projectAccountBreakdown(PDO $conn, array $project): array
{
    require_once __DIR__ . '/budget.php';
    require_once __DIR__ . '/../services/accounts.php';
    $pid = (int)$project['id'];
    $currency = projectCurrencyOf($conn, $project);
    $mode = projectSetting($conn, 'project_labour_mode') ?: 'hours';
    $rows = [];   // key => row
    $row = function (?array $acc) use (&$rows): string {
        $k = $acc ? (string)$acc['id'] : '-';
        if (!isset($rows[$k])) $rows[$k] = ['account' => $acc ? array_diff_key($acc, ['source' => 1, 'percent' => 1]) : null, 'planned' => 0.0, 'actual' => 0.0, 'forecast' => 0.0, 'minutes' => 0.0];
        return $k;
    };
    $defaults = projectDefaultAccounts($conn, $project);
    $otherShare = $defaults['other'] ? $defaults['other'] + ['percent' => 100] : ['_none' => true, 'percent' => 100];

    // Budget lines.
    $st = $conn->prepare("SELECT l.*, c.contract_value, c.currency AS contract_currency
                            FROM project_budget_lines l LEFT JOIN contracts c ON c.id = l.contract_id
                           WHERE l.project_id = ? ORDER BY l.position, l.id");
    $st->execute([$pid]);
    $lines = $st->fetchAll(PDO::FETCH_ASSOC);
    $lineAccounts = projectLineAccounts($conn, array_column($lines, 'id'));
    $cover = 0.0; $coverParts = [];
    foreach ($lines as $l) {
        $shares = ($lineAccounts[(int)$l['id']] ?? []) ?: [$otherShare];
        $act = projectLineActual($l, $currency)[0];
        $fc = projectLineForecast($l, $act);
        $covers = projectLineCoversLabour($l);
        if ($covers) $cover += (float)$fc;
        foreach ($shares as $sh) {
            $k = $row(isset($sh['_none']) ? null : $sh);
            $f = $sh['percent'] / 100;
            $rows[$k]['planned'] += (float)$l['planned_amount'] * $f;
            $rows[$k]['actual'] += (float)$act * $f;
            if ($covers) $coverParts[] = [$k, (float)$fc * $f];
            else $rows[$k]['forecast'] += (float)$fc * $f;
        }
    }

    // Labour logged, and still to come.
    $labourTotal = 0.0; $labourParts = [];
    if (projectTaskAccountsReady($conn)) {
        foreach (projectLabourByAccount($conn, $project) as $lr) {
            $k = $row($lr['account']);
            $rows[$k]['minutes'] += $lr['minutes'];
            if ($lr['cost'] !== null) { $rows[$k]['actual'] += $lr['cost']; $labourTotal += $lr['cost']; $labourParts[] = [$k, $lr['cost']]; }
        }
        if (projectSetting($conn, 'project_forecast_labour') !== '0') {
            foreach (projectLabourToComeByAccount($conn, $project) as $tc) {
                if ($tc['cost'] === null) continue;
                $k = $row($tc['account']);
                $labourTotal += $tc['cost']; $labourParts[] = [$k, $tc['cost']];
            }
        }
    }
    // Nothing counted twice: the larger of the labour plan lines and labour itself.
    foreach (($labourTotal >= $cover ? $labourParts : $coverParts) as [$k, $v]) $rows[$k]['forecast'] += $v;

    // Group by kind; "not assigned" on its own.
    $sections = [];
    foreach (AccountsService::KINDS as $kind) $sections[$kind] = ['rows' => [], 'planned' => 0.0, 'actual' => 0.0, 'forecast' => 0.0, 'minutes' => 0];
    $unassigned = null;
    $costs = ['planned' => 0.0, 'actual' => 0.0, 'forecast' => 0.0];
    $fix = function (array $r): array { foreach (['planned', 'actual', 'forecast'] as $c) $r[$c] = round($r[$c], 2); $r['minutes'] = (int)round($r['minutes']); return $r; };
    uasort($rows, fn($a, $b) => strcmp($a['account']['code'] ?? '', $b['account']['code'] ?? ''));
    // TRAP: totals and subtotals add up the UNROUNDED amounts and are rounded once, so the
    // costs equal the Budget tab's tiles to the penny; a 66.6667% share can leave a row a
    // penny off its share of the total, as in any report that rounds rows.
    foreach ($rows as $r) {
        foreach (['planned', 'actual', 'forecast'] as $c) $costs[$c] += $r[$c];
        if ($r['account'] === null) { $unassigned = $fix($r); continue; }
        $kind = in_array($r['account']['kind'], AccountsService::KINDS, true) ? $r['account']['kind'] : 'expense';
        foreach (['planned', 'actual', 'forecast', 'minutes'] as $c) $sections[$kind][$c] += $r[$c];
        $sections[$kind]['rows'][] = $fix($r);
    }

    // Income - its own section, never netted into the costs.
    $income = ['planned' => 0.0, 'actual' => 0.0, 'forecast' => 0.0];
    if (projectIncomeReady($conn)) {
        $inc = [];
        foreach (projectIncome($conn, $pid)['lines'] as $l) {
            $k = $l['account'] ? (string)$l['account']['id'] : '-';
            $inc[$k] = $inc[$k] ?? ['account' => $l['account'], 'planned' => 0.0, 'actual' => 0.0, 'forecast' => 0.0, 'minutes' => 0];
            $inc[$k]['planned'] += (float)$l['planned'];
            $inc[$k]['actual'] += (float)$l['received'];
            $inc[$k]['forecast'] += max((float)$l['planned'], (float)$l['received']);   // money in hand is not a shortfall
        }
        uasort($inc, fn($a, $b) => ($a['account'] === null) <=> ($b['account'] === null) ?: strcmp($a['account']['code'] ?? '', $b['account']['code'] ?? ''));
        foreach ($inc as $r) {
            $r = $fix($r);
            $sections['income']['rows'][] = $r;
            foreach (['planned', 'actual', 'forecast'] as $c) { $sections['income'][$c] += $r[$c]; $income[$c] += $r[$c]; }
        }
    }
    foreach ($sections as &$s) { foreach (['planned', 'actual', 'forecast'] as $c) $s[$c] = round($s[$c], 2); $s['minutes'] = (int)round($s['minutes']); }
    unset($s);
    $costs = array_map(fn($v) => round($v, 2), $costs);
    $income = array_map(fn($v) => round($v, 2), $income);
    return [
        'currency' => $currency, 'labour_mode' => $mode,
        'sections' => $sections, 'unassigned' => $unassigned,
        'costs' => $costs, 'income' => $income,
        'net' => ['planned' => round($income['planned'] - $costs['planned'], 2), 'actual' => round($income['actual'] - $costs['actual'], 2), 'forecast' => round($income['forecast'] - $costs['forecast'], 2)],
    ];
}

/**
 * Several projects' breakdowns added together, ONE PER CURRENCY - amounts in different
 * currencies are never added. Accounts are matched on code (lower-cased), as the
 * install's default accounts are; the same logic as combine() in projects-accounts.js.
 *
 * @param array $projects  projects rows (id, currency, tenant_id, labour_account_id, other_account_id)
 * @return array currency => [currency, projects, sections, unassigned, costs, income, net]
 */
function projectAccountPortfolio(PDO $conn, array $projects): array
{
    $out = [];
    $blank = fn(string $cur) => ['currency' => $cur, 'projects' => 0, 'sections' => array_fill_keys(AccountsService::KINDS, ['rows' => [], 'planned' => 0.0, 'actual' => 0.0, 'forecast' => 0.0, 'minutes' => 0]),
        'unassigned' => null, 'costs' => ['planned' => 0.0, 'actual' => 0.0, 'forecast' => 0.0], 'income' => ['planned' => 0.0, 'actual' => 0.0, 'forecast' => 0.0], 'net' => ['planned' => 0.0, 'actual' => 0.0, 'forecast' => 0.0]];
    $idx = [];
    foreach ($projects as $proj) {
        $b = projectAccountBreakdown($conn, $proj);
        if (!projectBreakdownHasRows($b)) continue;
        $cur = $b['currency'];
        $out[$cur] = $out[$cur] ?? $blank($cur);
        $o = &$out[$cur];
        $o['projects']++;
        foreach ($b['sections'] as $kind => $sec) {
            foreach ($sec['rows'] as $r) {
                $key = $cur . '|' . $kind . '|' . ($r['account'] ? mb_strtolower($r['account']['code']) : '-');
                if (!isset($idx[$key])) { $o['sections'][$kind]['rows'][] = ['account' => $r['account'], 'planned' => 0.0, 'actual' => 0.0, 'forecast' => 0.0, 'minutes' => 0]; $idx[$key] = array_key_last($o['sections'][$kind]['rows']); }
                $row = &$o['sections'][$kind]['rows'][$idx[$key]];
                foreach (['planned', 'actual', 'forecast', 'minutes'] as $c) $row[$c] += $r[$c];
                unset($row);
            }
            foreach (['planned', 'actual', 'forecast', 'minutes'] as $c) $o['sections'][$kind][$c] += $sec[$c];
        }
        if ($b['unassigned']) {
            $o['unassigned'] = $o['unassigned'] ?? ['account' => null, 'planned' => 0.0, 'actual' => 0.0, 'forecast' => 0.0, 'minutes' => 0];
            foreach (['planned', 'actual', 'forecast', 'minutes'] as $c) $o['unassigned'][$c] += $b['unassigned'][$c];
        }
        foreach (['costs', 'income', 'net'] as $t) foreach (['planned', 'actual', 'forecast'] as $c) $o[$t][$c] += $b[$t][$c];
        unset($o);
    }
    foreach ($out as &$o) {
        foreach ($o['sections'] as &$sec) {
            usort($sec['rows'], fn($a, $b) => strnatcasecmp($a['account']['code'] ?? '~', $b['account']['code'] ?? '~'));
            foreach ($sec['rows'] as &$r) foreach (['planned', 'actual', 'forecast'] as $c) $r[$c] = round($r[$c], 2);
            unset($r);
            foreach (['planned', 'actual', 'forecast'] as $c) $sec[$c] = round($sec[$c], 2);
        }
        unset($sec);
        foreach (['costs', 'income', 'net'] as $t) foreach (['planned', 'actual', 'forecast'] as $c) $o[$t][$c] = round($o[$t][$c], 2);
    }
    unset($o);
    ksort($out);
    return $out;
}

/**
 * Whether a breakdown has anything in it - an account row or "not assigned".
 *
 * @param array $b  from projectAccountBreakdown()
 */
function projectBreakdownHasRows(array $b): bool
{
    foreach ($b['sections'] as $s) if ($s['rows']) return true;
    return $b['unassigned'] !== null && ($b['unassigned']['planned'] != 0 || $b['unassigned']['actual'] != 0 || $b['unassigned']['minutes'] > 0);
}

/**
 * A breakdown as spreadsheet rows: kind, code, name, planned, actual, forecast, hours -
 * each account, a subtotal per kind, "not assigned", total costs, income, income less costs.
 *
 * @param array $b  a breakdown (one project's, or one currency's projectAccountPortfolio())
 * @param callable $T  (key) => text, for projects.budget.* keys
 */
function projectBreakdownSheetRows(array $b, callable $T): array
{
    $money = fn($v) => number_format((float)$v, 2, '.', '');
    $hours = fn($m) => $m > 0 ? number_format($m / 60, 2, '.', '') : '';
    $rows = [];
    $line = fn(string $kind, string $code, string $name, array $r) => [$kind, $code, $name, $money($r['planned']), $money($r['actual']), $money($r['forecast']), $hours($r['minutes'] ?? 0)];
    foreach (['expense', 'fixed_asset', 'payroll'] as $k) {
        if (!$b['sections'][$k]['rows']) continue;
        foreach ($b['sections'][$k]['rows'] as $r) $rows[] = $line($T('kind_' . $k), $r['account']['code'], $r['account']['name'], $r);
        $rows[] = $line($T('kind_' . $k), '', strtr($T('ba_subtotal'), ['{kind}' => $T('kind_' . $k)]), $b['sections'][$k]);
    }
    if ($b['unassigned']) $rows[] = $line($T('not_assigned'), '', $T('not_assigned'), $b['unassigned']);
    $mins = array_sum(array_map(fn($k) => $b['sections'][$k]['minutes'], ['expense', 'fixed_asset', 'payroll'])) + ($b['unassigned']['minutes'] ?? 0);
    $rows[] = $line('', '', $T('ba_costs'), $b['costs'] + ['minutes' => $mins]);
    if ($b['sections']['income']['rows']) {
        foreach ($b['sections']['income']['rows'] as $r) $rows[] = $line($T('kind_income'), $r['account']['code'] ?? '', $r['account']['name'] ?? $T('not_assigned'), $r);
        $rows[] = $line('', '', $T('ba_income'), $b['income']);
        $rows[] = $line('', '', $T('income_less_costs'), $b['net']);
    }
    return $rows;
}
