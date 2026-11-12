<?php
/**
 * AccountsService - the chart of accounts (GH #282, slice 1), ONCE.
 *
 * Shared by System -> Chart of Accounts (api/system/accounts.php), its spreadsheet
 * import, and the REST API (api/v1/resources/accounts.php). Each caller passes an
 * ActorContext; this layer validates, writes and returns, or throws ServiceError.
 * It never emits HTTP.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT AN ACCOUNT IS HERE
 *
 * The finance department's account codes - "2.0.3.0.4 Software Licences" - the
 * WHAT of a cost, beside the cost centre's WHO. A code, a name, a KIND, an optional
 * description, an optional parent (the hierarchy) and active/inactive. Always ONE
 * company's: two companies can both have 2.0.3.0.4.
 *
 * Deliberately built exactly like cost centres (includes/services/cost_centres.php):
 * the same code rules, the same sync, the same import - so the two lists behave
 * identically for the people who keep them. The one addition is KIND.
 *
 * 🔑 KIND is one of KINDS - expense, fixed_asset, payroll, income - chosen from a
 * list, never typed (the import maps words onto them). It decides which section of
 * a project's By-account breakdown an account lands in (slice 5). A NEW account
 * must have one; in a sync, a new account with none takes its parent's.
 *
 * 🔑 THE CODE IS TEXT. "2.0.3.0.4", "0010" and "N1414" are all real codes. Trimmed
 * and nothing else - never cast, never padded, never upper-cased. Unique per company
 * IGNORING CASE (the column's collation does that, and so does every comparison).
 *
 * 🔑 THE CODE IS THE KEY FOR SYNCING. sync() matches on code, so the same file or
 * API call can be sent every night from the finance system.
 *
 * 🔑 INACTIVE MEANS "NOT FOR NEW ASSIGNMENTS". Retire, don't delete; delete is for a
 * mistake, and is refused while the account has children.
 *
 * COMPANIES: every by-id method starts with load(), which 404s an account in a
 * company the caller cannot reach - never 403, so ids cannot be probed. A parent
 * must be in the same company, and never the account itself or anything below it.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';

class AccountsService
{
    const CODE_MAX = 50;
    const NAME_MAX = 150;
    const DESC_MAX = 500;

    /** The kinds, in the order the breakdown shows them. Stored as these keys. */
    const KINDS = ['expense', 'fixed_asset', 'payroll', 'income'];

    /** Has Database Verification created the table yet? */
    public static function ready(PDO $conn): bool
    {
        try {
            $conn->query("SELECT 1 FROM accounts LIMIT 0");
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The companies this caller may manage accounts for: [[id, name, is_default], ...].
     *
     * @param ActorContext $actor  who is acting; their companyScope decides which companies are listed
     */
    public static function companiesFor(PDO $conn, ActorContext $actor): array
    {
        $out = [];
        foreach (getAllTenants($conn) as $t) {
            if (self::canReach($actor, (int)$t['id'])) {
                $out[] = ['id' => (int)$t['id'], 'name' => $t['name'], 'is_default' => (bool)(int)($t['is_default'] ?? 0)];
            }
        }
        if (!$out) $out[] = ['id' => getDefaultTenantId($conn), 'name' => 'Default', 'is_default' => true];
        return $out;
    }

    /**
     * True when the actor may reach the company.
     *
     * @param ActorContext $actor  who is acting; a null companyScope reaches every company
     */
    private static function canReach(ActorContext $actor, int $tenantId): bool
    {
        return $actor->companyScope === null || in_array($tenantId, array_map('intval', $actor->companyScope), true);
    }

    /**
     * The company exists and this caller may use it - or a ServiceError.
     *
     * @param ActorContext $actor  who is acting
     */
    public static function assertCompany(PDO $conn, ActorContext $actor, int $tenantId): void
    {
        $known = tenancyTablesReady($conn) ? getTenantById($conn, $tenantId) !== null : $tenantId === getDefaultTenantId($conn);
        if (!$known) throw new ServiceError('validation', 'invalid_field', "Unknown company id: {$tenantId}");
        if (!self::canReach($actor, $tenantId)) throw new ServiceError('forbidden', 'forbidden', 'You cannot manage accounts for that company.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Reads
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every account of one company, active and inactive, ordered by code, each with
     * its parent's code and how many children it has.
     *
     * @param ActorContext $actor  who is acting; must reach $tenantId
     */
    public static function listForCompany(PDO $conn, ActorContext $actor, int $tenantId): array
    {
        self::assertCompany($conn, $actor, $tenantId);
        $st = $conn->prepare(
            "SELECT a.*, p.code AS parent_code, p.name AS parent_name,
                    (SELECT COUNT(*) FROM accounts k WHERE k.parent_id = a.id) AS child_count
               FROM accounts a
          LEFT JOIN accounts p ON p.id = a.parent_id
              WHERE a.tenant_id = ?
           ORDER BY a.code"
        );
        $st->execute([$tenantId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * One account, or not_found (also when it is in a company the caller cannot reach).
     *
     * @param ActorContext $actor  who is acting; an account outside their companies reads as not found
     * @param int $id  the accounts row to load
     */
    public static function load(PDO $conn, ActorContext $actor, int $id): array
    {
        $st = $conn->prepare(
            "SELECT a.*, p.code AS parent_code, p.name AS parent_name, t.name AS company_name,
                    (SELECT COUNT(*) FROM accounts k WHERE k.parent_id = a.id) AS child_count
               FROM accounts a
          LEFT JOIN accounts p ON p.id = a.parent_id
          LEFT JOIN tenants t ON t.id = a.tenant_id
              WHERE a.id = ?"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !self::canReach($actor, (int)$row['tenant_id'])) {
            throw new ServiceError('not_found', 'not_found', 'Account not found.');
        }
        return $row;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Writes, one at a time (the screen; POST/PATCH in the API)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add one.
     *
     * @param ActorContext $actor  who is acting; must reach $tenantId
     * @param array $in  code, name and kind (required); description, parent_id or parent_code, is_active
     */
    public static function create(PDO $conn, ActorContext $actor, int $tenantId, array $in): int
    {
        self::assertCompany($conn, $actor, $tenantId);
        $code = self::cleanCode($in['code'] ?? null);
        $name = self::cleanName($in['name'] ?? null);
        $kind = self::cleanKind($in['kind'] ?? null);
        $desc = self::cleanDescription($in['description'] ?? null);
        $active = array_key_exists('is_active', $in) ? self::cleanBool($in['is_active'], 'is_active') : 1;
        self::assertCodeFree($conn, $tenantId, $code, null);
        $parentId = self::resolveParentInput($conn, $tenantId, $in, null);

        $st = $conn->prepare("INSERT INTO accounts (tenant_id, code, name, kind, description, parent_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
        try {
            $st->execute([$tenantId, $code, $name, $kind, $desc, $parentId, $active]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) == 1062) self::codeTaken($code);   // lost a race to the same code
            throw $e;
        }
        return (int)$conn->lastInsertId();
    }

    /**
     * Change one. Only the keys present in $in are touched (PATCH semantics).
     *
     * @param ActorContext $actor  who is acting
     * @param int $id  the accounts row to change
     * @param array $in  any of code, name, kind, description, is_active, parent_id or parent_code; absent keys are left alone
     */
    public static function update(PDO $conn, ActorContext $actor, int $id, array $in): void
    {
        $row = self::load($conn, $actor, $id);
        $tenantId = (int)$row['tenant_id'];
        $set = [];
        if (array_key_exists('code', $in)) {
            $code = self::cleanCode($in['code']);
            self::assertCodeFree($conn, $tenantId, $code, $id);
            $set['code'] = $code;
        }
        if (array_key_exists('name', $in))        $set['name'] = self::cleanName($in['name']);
        if (array_key_exists('kind', $in))        $set['kind'] = self::cleanKind($in['kind']);
        if (array_key_exists('description', $in)) $set['description'] = self::cleanDescription($in['description']);
        if (array_key_exists('is_active', $in))   $set['is_active'] = self::cleanBool($in['is_active'], 'is_active');
        if (array_key_exists('parent_id', $in) || array_key_exists('parent_code', $in)) {
            $set['parent_id'] = self::resolveParentInput($conn, $tenantId, $in, $id);
        }
        if (!$set) return;

        $cols = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
        $st = $conn->prepare("UPDATE accounts SET $cols WHERE id = ?");
        try {
            $st->execute(array_merge(array_values($set), [$id]));
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) == 1062) self::codeTaken((string)$set['code']);
            throw $e;
        }
    }

    /**
     * Delete one - refused while anything sits below it. Make it inactive instead.
     *
     * TRAP: when budget lines and tasks start naming accounts (#282 slices 2-3), add
     * an "is it still used?" check here, as CostCentresService::delete() has - a
     * deleted account would silently drop out of every project's breakdown.
     *
     * @param ActorContext $actor  who is acting
     * @param int $id  the accounts row to delete
     */
    public static function delete(PDO $conn, ActorContext $actor, int $id): void
    {
        $row = self::load($conn, $actor, $id);
        if ((int)$row['child_count'] > 0) {
            throw new ServiceError('conflict', 'conflict',
                "Account {$row['code']} has " . (int)$row['child_count'] . " account(s) below it. Move or delete those first, or make it inactive instead.");
        }
        $conn->prepare("DELETE FROM accounts WHERE id = ?")->execute([$id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Sync - the spreadsheet import and POST /accounts/sync
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Bring a company's chart into line with an incoming one, matched on CODE.
     *
     * $rows: [['line' => n, 'code' => ..., 'name' => ..., 'kind' => ..., 'description' => ...,
     *          'parent_code' => ..., 'is_active' => bool|string], ...]
     * A key that is ABSENT means "leave that field as it is". A key present but empty
     * means empty: parent_code '' = top level, description '' = none, kind '' = as if
     * absent (a kind cannot be emptied).
     *
     * A NEW code needs a name and a kind; with no kind it takes its parent's (in the
     * finished list), and with no parent either it is an error.
     *
     * $opts: dry_run (bool)            work it all out, write nothing
     *        deactivate_missing (bool) make active accounts that are NOT in $rows
     *                                  inactive - for a full chart from the finance
     *                                  system. Never deletes.
     *
     * 🔑 ALL OR NOTHING. If any row has a problem, nothing is written and every problem
     * is reported, with its line.
     *
     * @param ActorContext $actor  who is acting; must reach $tenantId
     * @param array $rows  the incoming list - see above
     * @param array $opts  dry_run and deactivate_missing, both bool - see above
     * @return array{applied: bool, dry_run: bool, counts: array, errors: array, rows: array}
     */
    public static function sync(PDO $conn, ActorContext $actor, int $tenantId, array $rows, array $opts = []): array
    {
        self::assertCompany($conn, $actor, $tenantId);
        $dryRun = !empty($opts['dry_run']);
        $deactivateMissing = !empty($opts['deactivate_missing']);

        $existing = [];   // lower(code) => row
        $byId = [];
        foreach (self::listForCompany($conn, $actor, $tenantId) as $r) {
            $existing[mb_strtolower($r['code'])] = $r;
            $byId[(int)$r['id']] = $r;
        }

        $errors = [];
        $incoming = [];   // lower(code) => normalised row
        foreach (array_values($rows) as $i => $r) {
            $line = (int)($r['line'] ?? ($i + 1));
            $err = function (string $msg, string $code = '') use (&$errors, $line) {
                $errors[] = ['line' => $line, 'code' => $code, 'message' => $msg];
            };
            try {
                $code = self::cleanCode($r['code'] ?? null);
            } catch (ServiceError $e) {
                $err($e->getMessage());
                continue;
            }
            $key = mb_strtolower($code);
            if (isset($incoming[$key])) {
                $err("Code {$code} appears more than once (also line {$incoming[$key]['line']}). Codes are matched ignoring capitals.", $code);
                continue;
            }
            $was = $existing[$key] ?? null;
            $n = ['line' => $line, 'code' => $code, 'was' => $was];
            try {
                if (array_key_exists('name', $r) && trim((string)$r['name']) !== '') {
                    $n['name'] = self::cleanName($r['name']);
                } elseif (!$was) {
                    throw new ServiceError('validation', 'missing_field', "Code {$code} is new, so it needs a name.");
                }
                if (array_key_exists('kind', $r) && trim((string)$r['kind']) !== '') $n['kind'] = self::cleanKind($r['kind']);
                if (array_key_exists('description', $r)) $n['description'] = self::cleanDescription($r['description']);
                if (array_key_exists('is_active', $r) && !($r['is_active'] === '' || $r['is_active'] === null)) {
                    $n['is_active'] = self::cleanBool($r['is_active'], 'active');
                }
                if (array_key_exists('parent_code', $r)) {
                    $pc = trim((string)$r['parent_code']);
                    if ($pc !== '' && mb_strtolower($pc) === $key) throw new ServiceError('validation', 'invalid_field', "Code {$code} cannot be its own parent.");
                    $n['parent_code'] = $pc;
                }
            } catch (ServiceError $e) {
                $err($e->getMessage(), $code);
                continue;
            }
            $incoming[$key] = $n;
        }

        // Every parent must exist once the sync is done; then the tree must have no loops.
        $parentOf = [];   // lower(code) => lower(parent code) | null, for the FINISHED state
        foreach ($existing as $k => $r) {
            $parentOf[$k] = $r['parent_id'] !== null && isset($byId[(int)$r['parent_id']]) ? mb_strtolower($byId[(int)$r['parent_id']]['code']) : null;
        }
        foreach ($incoming as $k => $n) {
            if (!array_key_exists('parent_code', $n)) {
                if (!isset($parentOf[$k])) $parentOf[$k] = null;
                continue;
            }
            if ($n['parent_code'] === '') { $parentOf[$k] = null; continue; }
            $pk = mb_strtolower($n['parent_code']);
            if (!isset($incoming[$pk]) && !isset($existing[$pk])) {
                $errors[] = ['line' => $n['line'], 'code' => $n['code'], 'message' => "Parent {$n['parent_code']} of {$n['code']} is neither in this list nor already an account for this company."];
                $parentOf[$k] = null;
                continue;
            }
            $parentOf[$k] = $pk;
        }
        $looped = [];
        foreach ($incoming as $k => $n) {
            $seen = [$k => true];
            for ($p = $parentOf[$k] ?? null, $hops = 0; $p !== null && $hops < 10000; $p = $parentOf[$p] ?? null, $hops++) {
                if (isset($seen[$p])) {
                    $errors[] = ['line' => $n['line'], 'code' => $n['code'], 'message' => "Code {$n['code']} would end up below itself - the parents go round in a loop."];
                    $looped[$k] = true;
                    break;
                }
                $seen[$p] = true;
            }
        }

        // A new account with no kind takes the nearest ancestor's, in the finished list.
        $kindOf = function (string $k) use (&$incoming, $existing, $parentOf): ?string {
            for ($at = $k, $hops = 0; $at !== null && $hops < 10000; $at = $parentOf[$at] ?? null, $hops++) {
                if (isset($incoming[$at]['kind'])) return $incoming[$at]['kind'];
                if (isset($existing[$at]))         return $existing[$at]['kind'];
            }
            return null;
        };
        foreach ($incoming as $k => $n) {
            if ($n['was'] || isset($n['kind']) || isset($looped[$k])) continue;
            $inherited = $kindOf($k);
            if ($inherited === null) {
                $errors[] = ['line' => $n['line'], 'code' => $n['code'], 'message' => "Code {$n['code']} is new, so it needs a kind (Expense, Fixed asset, Payroll or Income) - or a parent to take one from."];
                continue;
            }
            $incoming[$k]['kind'] = $inherited;
        }

        // What each row will do.
        $plan = [];
        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'deactivate' => 0];
        foreach ($incoming as $k => $n) {
            $was = $n['was'];
            if (!$was) {
                $plan[] = ['line' => $n['line'], 'code' => $n['code'], 'name' => $n['name'], 'kind' => $n['kind'] ?? null, 'action' => 'create', 'changes' => []];
                $counts['create']++;
                continue;
            }
            $changes = [];
            if ($was['code'] !== $n['code']) $changes[] = 'code';
            if (isset($n['name']) && $was['name'] !== $n['name']) $changes[] = 'name';
            if (isset($n['kind']) && $was['kind'] !== $n['kind']) $changes[] = 'kind';
            if (array_key_exists('description', $n) && (string)$was['description'] !== (string)$n['description']) $changes[] = 'description';
            if (isset($n['is_active']) && (int)$was['is_active'] !== $n['is_active']) $changes[] = 'is_active';
            if (array_key_exists('parent_code', $n)) {
                $wasParent = $was['parent_id'] !== null && isset($byId[(int)$was['parent_id']]) ? mb_strtolower($byId[(int)$was['parent_id']]['code']) : null;
                if ($wasParent !== ($parentOf[$k] ?? null)) $changes[] = 'parent';
            }
            $action = $changes ? 'update' : 'unchanged';
            $plan[] = ['line' => $n['line'], 'code' => $n['code'], 'name' => $n['name'] ?? $was['name'], 'kind' => $n['kind'] ?? $was['kind'], 'action' => $action, 'changes' => $changes];
            $counts[$action]++;
        }
        $toDeactivate = [];
        if ($deactivateMissing) {
            foreach ($existing as $k => $r) {
                if (!isset($incoming[$k]) && (int)$r['is_active'] === 1) {
                    $toDeactivate[] = (int)$r['id'];
                    $plan[] = ['line' => null, 'code' => $r['code'], 'name' => $r['name'], 'kind' => $r['kind'], 'action' => 'deactivate', 'changes' => ['is_active']];
                    $counts['deactivate']++;
                }
            }
        }

        $report = ['applied' => false, 'dry_run' => $dryRun, 'counts' => $counts, 'errors' => $errors, 'rows' => $plan];
        if ($errors || $dryRun) return $report;

        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $ids = [];
            foreach ($existing as $k => $r) $ids[$k] = (int)$r['id'];

            $ins = $conn->prepare("INSERT INTO accounts (tenant_id, code, name, kind, description, is_active) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($incoming as $k => $n) {
                if ($n['was']) {
                    $set = ['code' => $n['code']];
                    if (isset($n['name']))                   $set['name'] = $n['name'];
                    if (isset($n['kind']))                   $set['kind'] = $n['kind'];
                    if (array_key_exists('description', $n)) $set['description'] = $n['description'];
                    if (isset($n['is_active']))              $set['is_active'] = $n['is_active'];
                    $cols = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
                    $conn->prepare("UPDATE accounts SET $cols WHERE id = ?")->execute(array_merge(array_values($set), [(int)$n['was']['id']]));
                } else {
                    $ins->execute([$tenantId, $n['code'], $n['name'], $n['kind'], $n['description'] ?? null, $n['is_active'] ?? 1]);
                    $ids[$k] = (int)$conn->lastInsertId();
                }
            }
            $par = $conn->prepare("UPDATE accounts SET parent_id = ? WHERE id = ?");
            foreach ($incoming as $k => $n) {
                if (!array_key_exists('parent_code', $n)) continue;
                $pk = $parentOf[$k] ?? null;
                $par->execute([$pk === null ? null : $ids[$pk], $ids[$k]]);
            }
            if ($toDeactivate) {
                $conn->prepare("UPDATE accounts SET is_active = 0 WHERE tenant_id = ? AND id IN (" . implode(',', array_fill(0, count($toDeactivate), '?')) . ")")
                     ->execute(array_merge([$tenantId], $toDeactivate));
            }
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own) $conn->rollBack();
            throw $e;
        }
        $report['applied'] = true;
        return $report;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Field rules
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A kind key from a key or a word: "fixed_asset", "Fixed asset", "Anlagevermögen".
     * What a spreadsheet or a finance-system export says, mapped onto KINDS.
     *
     * @param mixed $v  the kind as sent
     */
    public static function cleanKind($v): string
    {
        $s = preg_replace('/[^a-z]+/', '', mb_strtolower(trim((string)$v)));
        $map = [
            'expense' => 'expense', 'expenses' => 'expense', 'opex' => 'expense', 'operatingexpense' => 'expense', 'cost' => 'expense', 'aufwand' => 'expense',
            'fixedasset' => 'fixed_asset', 'fixedassets' => 'fixed_asset', 'asset' => 'fixed_asset', 'capex' => 'fixed_asset', 'capital' => 'fixed_asset', 'anlage' => 'fixed_asset', 'anlagevermgen' => 'fixed_asset', 'anlagevermogen' => 'fixed_asset',
            'payroll' => 'payroll', 'salaries' => 'payroll', 'salary' => 'payroll', 'wages' => 'payroll', 'personnel' => 'payroll', 'personal' => 'payroll', 'lohn' => 'payroll',
            'income' => 'income', 'revenue' => 'income', 'ertrag' => 'income', 'erlse' => 'income', 'erlose' => 'income',
        ];
        if ($s === '') throw new ServiceError('validation', 'missing_field', 'A kind is required: Expense, Fixed asset, Payroll or Income.');
        if (!isset($map[$s])) throw new ServiceError('validation', 'invalid_field', "'{$v}' is not a kind of account - use Expense, Fixed asset, Payroll or Income.");
        return $map[$s];
    }

    /**
     * Trimmed, never otherwise changed: "0010" stays "0010".
     *
     * @param mixed $v  the code as sent
     */
    public static function cleanCode($v): string
    {
        $v = trim((string)$v);
        if ($v === '') throw new ServiceError('validation', 'missing_field', 'A code is required.');
        if (preg_match('/[\x00-\x1F\x7F]/', $v)) throw new ServiceError('validation', 'invalid_field', 'The code contains a line break or other control character.');
        if (mb_strlen($v) > self::CODE_MAX) throw new ServiceError('validation', 'invalid_field', "Code {$v} is longer than " . self::CODE_MAX . " characters.");
        return $v;
    }

    /**
     * An account name with control characters removed; throws when blank or too long.
     *
     * @param mixed $v  the name as sent
     */
    private static function cleanName($v): string
    {
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$v));
        if ($v === '') throw new ServiceError('validation', 'missing_field', 'A name is required.');
        if (mb_strlen($v) > self::NAME_MAX) throw new ServiceError('validation', 'invalid_field', 'The name is longer than ' . self::NAME_MAX . ' characters.');
        return $v;
    }

    /**
     * An account description with control characters removed, or null when blank; throws when too long.
     *
     * @param mixed $v  the description as sent; blank gives null
     */
    private static function cleanDescription($v): ?string
    {
        $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', (string)$v));
        if ($v === '') return null;
        if (mb_strlen($v) > self::DESC_MAX) throw new ServiceError('validation', 'invalid_field', 'The description is longer than ' . self::DESC_MAX . ' characters.');
        return $v;
    }

    /**
     * true/false, 1/0, yes/no, active/inactive - what a spreadsheet or an ERP export says.
     *
     * @param mixed $v  a bool, 0/1, or a word such as yes, no, active, inactive (English or German)
     * @param string $field  the field name, used in the error message
     */
    public static function cleanBool($v, string $field): int
    {
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_int($v) && ($v === 0 || $v === 1)) return $v;
        $s = mb_strtolower(trim((string)$v));
        if (in_array($s, ['1', 'true', 'yes', 'y', 'active', 'on', 'ja', 'j', 'aktiv'], true)) return 1;
        if (in_array($s, ['0', 'false', 'no', 'n', 'inactive', 'off', 'nein', 'inaktiv'], true)) return 0;
        throw new ServiceError('validation', 'invalid_field', "'{$v}' is not a valid value for {$field} - use Yes or No.");
    }

    /**
     * Throws the "code already used" conflict.
     *
     * @param string $code  the code that clashed, for the message
     */
    private static function codeTaken(string $code): void
    {
        throw new ServiceError('conflict', 'conflict', "Code {$code} is already used by another account in this company. Codes are matched ignoring capitals.");
    }

    /**
     * Throws when another account in the company has the same code, ignoring case.
     *
     * @param string $code  the code to check, compared ignoring case
     * @param ?int $exceptId  the account allowed to hold it (the one being changed), or null
     */
    private static function assertCodeFree(PDO $conn, int $tenantId, string $code, ?int $exceptId): void
    {
        $st = $conn->prepare("SELECT id FROM accounts WHERE tenant_id = ? AND code = ?" . ($exceptId ? " AND id <> ?" : ''));
        $st->execute($exceptId ? [$tenantId, $code, $exceptId] : [$tenantId, $code]);
        if ($st->fetchColumn() !== false) self::codeTaken($code);
    }

    /**
     * parent_id or parent_code from the input -> a parent id or null, checked: same
     * company, not itself, not below itself.
     *
     * @param array $in  the request fields; parent_id, or parent_code within the same company - blank or 0 means top level
     * @param ?int $selfId  the account being changed, for the loop check; null on create
     */
    private static function resolveParentInput(PDO $conn, int $tenantId, array $in, ?int $selfId): ?int
    {
        if (array_key_exists('parent_id', $in)) {
            $v = $in['parent_id'];
            if ($v === null || $v === '' || (string)$v === '0') return null;
            if (!is_numeric($v)) throw new ServiceError('validation', 'invalid_field', 'parent_id must be a number.');
            $st = $conn->prepare("SELECT id, tenant_id FROM accounts WHERE id = ?");
            $st->execute([(int)$v]);
        } elseif (array_key_exists('parent_code', $in)) {
            $v = trim((string)$in['parent_code']);
            if ($v === '') return null;
            $st = $conn->prepare("SELECT id, tenant_id FROM accounts WHERE tenant_id = ? AND code = ?");
            $st->execute([$tenantId, $v]);
        } else {
            return null;
        }
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || (int)$p['tenant_id'] !== $tenantId) {
            throw new ServiceError('validation', 'invalid_field', 'The parent account was not found in this company.');
        }
        $parentId = (int)$p['id'];
        if ($selfId !== null) {
            if ($parentId === $selfId) throw new ServiceError('validation', 'invalid_field', 'An account cannot be its own parent.');
            $up = $conn->prepare("SELECT parent_id FROM accounts WHERE id = ?");
            for ($at = $parentId, $hops = 0; $at !== null && $hops < 10000; $hops++) {
                $up->execute([$at]);
                $next = $up->fetchColumn();
                $at = ($next === false || $next === null) ? null : (int)$next;
                if ($at === $selfId) throw new ServiceError('validation', 'invalid_field', 'That parent is below this account, which would make a loop.');
            }
        }
        return $parentId;
    }
}
