<?php
/**
 * API: System -> Chart of Accounts (GH #282, slice 1).
 *
 *   GET  ?action=list&company_id=N                    the company's accounts (+ the companies to choose from);
 *                                                     every reachable company's when the header switcher is on "All companies"
 *   POST {action:'save', id?, company_id, code, name, kind, description, parent_id, is_active}
 *   POST {action:'delete', id}
 *   GET  ?action=export&company_id=N&format=xlsx|csv  download the chart
 *   POST multipart: action=import, company_id, file, deactivate_missing=0|1, apply=0|1
 *        apply=0 is the preview: the same run, stopped before it writes.
 *
 * Administrators only. Every rule lives in AccountsService, shared with the REST
 * API's /accounts endpoints. Built as a copy of api/system/cost_centres.php on
 * purpose - the two lists behave identically - with the account's kind added.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // System admins only
require_once '../../includes/functions.php';
require_once '../../includes/services/accounts.php';
require_once '../../includes/spreadsheet.php';

/** The columns of an import/export, and the headings each is recognised by. */
function accountColumns(): array
{
    // Matched with case, spaces and punctuation ignored. German headings too, as for
    // cost centres: an ERP export keeps its own (Konto, Kontonummer, Kontoart...).
    return [
        'code'        => ['code', 'account', 'accountcode', 'accountnumber', 'accountno', 'number', 'no', 'nominalcode', 'glcode', 'glaccount', 'ledgercode', 'konto', 'kontonummer', 'sachkonto', 'nummer'],
        'name'        => ['name', 'accountname', 'title', 'bezeichnung', 'kontobezeichnung'],
        'kind'        => ['kind', 'type', 'accounttype', 'category', 'class', 'kontoart', 'art', 'typ'],
        'description' => ['description', 'desc', 'notes', 'beschreibung'],
        'parent_code' => ['parentcode', 'parent', 'parentaccount', 'parentaccountcode', 'parentnumber', 'uebergeordnet', 'übergeordnet'],
        'is_active'   => ['active', 'isactive', 'status', 'enabled', 'aktiv'],
    ];
}

/** The word written into an export for each kind - and read back by cleanKind(). */
function accountKindWord(string $kind): string
{
    return ['expense' => 'Expense', 'fixed_asset' => 'Fixed asset', 'payroll' => 'Payroll', 'income' => 'Income'][$kind] ?? $kind;
}

/**
 * Sends an HTTP status (200 by default) and {success: false, error} as JSON, then stops.
 *
 * @param string $message  the error shown to the user
 * @param int $status  the HTTP status to send
 */
function accountJsonFail(string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

try {
    $conn   = connectToDatabase();
    $actor  = ActorContext::fromSession($conn);
    $method = $_SERVER['REQUEST_METHOD'];
    $json   = $method === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false
        ? (json_decode(file_get_contents('php://input'), true) ?: [])
        : [];
    $action = $method === 'POST' ? ($json['action'] ?? ($_POST['action'] ?? '')) : ($_GET['action'] ?? '');

    $ready = AccountsService::ready($conn);
    $companies = AccountsService::companiesFor($conn, $actor);

    // Which company: as asked, else the one the analyst is working in, else the first.
    $companyId = (int)($json['company_id'] ?? ($_POST['company_id'] ?? ($_GET['company_id'] ?? 0)));
    if ($companyId <= 0) {
        $active = getActiveTenantId($conn, (int)$_SESSION['analyst_id']);
        $companyId = in_array($active, array_column($companies, 'id'), true) ? $active : $companies[0]['id'];
    }

    if ($action !== 'export') header('Content-Type: application/json');
    if (!$ready) {
        echo json_encode(['success' => true, 'ready' => false, 'companies' => $companies, 'company_id' => $companyId, 'accounts' => []]);
        exit;
    }

    if ($method === 'GET' && $action === 'list') {
        // "All companies" in the header switcher: every company this analyst may
        // manage, each row saying which one it is. company_id stays the company a
        // NEW account would go into.
        $all = isActiveTenantAll($conn) && count($companies) > 1;
        $out = [];
        foreach ($companies as $c) {
            if (!$all && $c['id'] !== $companyId) continue;
            foreach (AccountsService::listForCompany($conn, $actor, $c['id']) as $r) {
                $out[] = [
                    'id'           => (int)$r['id'],
                    'company_id'   => $c['id'],
                    'company_name' => $c['name'],
                    'code'         => $r['code'],
                    'name'         => $r['name'],
                    'kind'         => $r['kind'],
                    'description'  => $r['description'],
                    'parent_id'    => $r['parent_id'] === null ? null : (int)$r['parent_id'],
                    'is_active'    => (int)$r['is_active'] === 1,
                    'child_count'  => (int)$r['child_count'],
                ];
            }
        }
        echo json_encode([
            'success' => true, 'ready' => true, 'all' => $all, 'companies' => $companies, 'company_id' => $companyId,
            'kinds' => AccountsService::KINDS, 'accounts' => $out,
        ]);
        exit;
    }

    if ($method === 'POST' && $action === 'save') {
        $in = array_intersect_key($json, array_flip(['code', 'name', 'kind', 'description', 'parent_id', 'is_active']));
        $id = (int)($json['id'] ?? 0);
        if ($id > 0) {
            AccountsService::update($conn, $actor, $id, $in);
        } else {
            $id = AccountsService::create($conn, $actor, $companyId, $in);
        }
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    if ($method === 'POST' && $action === 'delete') {
        AccountsService::delete($conn, $actor, (int)($json['id'] ?? 0));
        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'GET' && $action === 'export') {
        $format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        $rows = AccountsService::listForCompany($conn, $actor, $companyId);
        $header = ['Code', 'Name', 'Kind', 'Description', 'Parent code', 'Active'];
        $data = array_map(fn($r) => [$r['code'], $r['name'], accountKindWord($r['kind']), (string)$r['description'], (string)$r['parent_code'], (int)$r['is_active'] ? 'Yes' : 'No'], $rows);

        $companyName = '';
        foreach ($companies as $c) if ($c['id'] === $companyId) $companyName = $c['name'];
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($companyName)), '-');
        $file = 'chart-of-accounts' . (count($companies) > 1 && $slug !== '' ? '-' . $slug : '') . '-' . gmdate('Y-m-d') . '.' . $format;

        if ($format === 'csv') {
            $body = spreadsheetWriteCsv($header, $data);
            header('Content-Type: text/csv; charset=utf-8');
        } else {
            $body = spreadsheetWriteXlsx('Chart of accounts', $header, $data, [14, 36, 12, 50, 14, 8]);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . strlen($body));
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    }

    if ($method === 'POST' && $action === 'import') {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            accountJsonFail(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'The file is larger than this server accepts.' : 'Choose a file to import.');
        }
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'xlsx', 'xls'], true)) accountJsonFail('Use an Excel workbook (.xlsx) or a CSV file.');

        try {
            $sheet = spreadsheetRead($f['tmp_name'], (string)$f['name']);
        } catch (RuntimeException $e) {
            accountJsonFail($e->getMessage());
        }
        if (count($sheet) < 2) accountJsonFail('The file has no rows under its headings.');

        // Map the heading row onto our columns. Unknown headings are ignored.
        $norm = fn($s) => preg_replace('/[^a-z0-9äöüß]+/u', '', mb_strtolower((string)$s));
        $map = [];
        foreach ($sheet[0] as $i => $heading) {
            foreach (accountColumns() as $field => $names) {
                if (!isset($map[$field]) && in_array($norm($heading), $names, true)) { $map[$field] = $i; break; }
            }
        }
        if (!isset($map['code'])) accountJsonFail('The first row must be headings, and one of them must be Code. Download an export to see the layout.');

        $rows = [];
        foreach (array_slice($sheet, 1) as $n => $line) {
            $r = ['line' => $n + 2];
            foreach ($map as $field => $i) $r[$field] = $line[$i] ?? '';
            $rows[] = $r;
        }
        $report = AccountsService::sync($conn, $actor, $companyId, $rows, [
            'dry_run'            => ($_POST['apply'] ?? '0') !== '1',
            'deactivate_missing' => ($_POST['deactivate_missing'] ?? '0') === '1',
        ]);
        echo json_encode(['success' => true, 'columns' => array_keys($map), 'report' => $report]);
        exit;
    }

    accountJsonFail('Unknown action', 400);
} catch (ServiceError $e) {
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    error_log('accounts: ' . $e->getMessage());
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Something went wrong - nothing has been changed.']);
}
