<?php
/**
 * API: System -> Cost Centres (GH #160, stage 1).
 *
 *   GET  ?action=list&company_id=N                    the company's cost centres (+ the companies to choose from);
 *                                                     every reachable company's when the header switcher is on "All companies"
 *   POST {action:'save', id?, company_id, code, name, description, parent_id, is_active}
 *   POST {action:'delete', id}
 *   GET  ?action=people&company_id=N&q=               active people in that company, for the
 *                                                     Owner and Deputy pickers (GH #160 Part B)
 *   GET  ?action=export&company_id=N&format=xlsx|csv  download the list
 *   POST multipart: action=import, company_id, file, deactivate_missing=0|1, apply=0|1
 *        apply=0 is the preview: the same run, stopped before it writes.
 *
 * Administrators only. Every rule lives in CostCentresService, shared with the
 * REST API's /cost-centres endpoints, so the screen, an import and an API sync
 * can never disagree about what a valid cost centre is.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // System admins only
require_once '../../includes/functions.php';
require_once '../../includes/services/cost_centres.php';
require_once '../../includes/spreadsheet.php';

/** The columns of an import/export, and the headings each is recognised by. */
function costCentreColumns(): array
{
    // Matched with case, spaces and punctuation ignored. "kostenstelle" etc.
    // because the request came from a German-speaking organisation, and an ERP
    // export keeps its own headings.
    return [
        'code'        => ['code', 'costcentre', 'costcenter', 'costcentrecode', 'costcentercode', 'costcentrenumber', 'costcenternumber', 'number', 'no', 'nominalcode', 'kostenstelle', 'kostenstellennummer', 'nummer'],
        'name'        => ['name', 'costcentrename', 'costcentername', 'title', 'bezeichnung'],
        'description' => ['description', 'desc', 'notes', 'beschreibung'],
        'parent_code' => ['parentcode', 'parent', 'parentcostcentre', 'parentcostcenter', 'parentnumber', 'parentcostcentrecode', 'parentcostcentercode', 'uebergeordnet', 'übergeordnet'],
        'is_active'   => ['active', 'isactive', 'status', 'enabled', 'aktiv'],
        // GH #160 Part B: who answers for it, matched by email address / group name.
        'owner_email'  => ['owneremail', 'owner', 'responsibleemail', 'responsible', 'budgetholder', 'budgetholderemail', 'verantwortlicher', 'verantwortlich', 'kostenstellenverantwortlicher'],
        'owner_group'  => ['ownergroup', 'responsiblegroup', 'ownerteam', 'verantwortlichegruppe'],
        'deputy_email' => ['deputyemail', 'deputy', 'substitute', 'substituteemail', 'stellvertreter', 'stellvertretung', 'vertreter'],
    ];
}

/**
 * Sends an HTTP status (200 by default) and {success: false, error} as JSON, then stops.
 *
 * @param string $message  the error shown to the user
 * @param int $status  the HTTP status to send
 */
function costCentreJsonFail(string $message, int $status = 200): void
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

    $ready = CostCentresService::ready($conn);
    $companies = CostCentresService::companiesFor($conn, $actor);

    // Which company: as asked, else the one the analyst is working in, else the first.
    $companyId = (int)($json['company_id'] ?? ($_POST['company_id'] ?? ($_GET['company_id'] ?? 0)));
    if ($companyId <= 0) {
        $active = getActiveTenantId($conn, (int)$_SESSION['analyst_id']);
        $companyId = in_array($active, array_column($companies, 'id'), true) ? $active : $companies[0]['id'];
    }

    if ($action !== 'export') header('Content-Type: application/json');
    if (!$ready) {
        echo json_encode(['success' => true, 'ready' => false, 'companies' => $companies, 'company_id' => $companyId, 'cost_centres' => []]);
        exit;
    }

    if ($method === 'GET' && $action === 'list') {
        // "All companies" in the header switcher (#1554): every company this
        // analyst may manage, each row saying which one it is. Otherwise just the
        // one company. company_id stays the company a NEW one would go into.
        $all = isActiveTenantAll($conn) && count($companies) > 1;
        $out = [];
        foreach ($companies as $c) {
            if (!$all && $c['id'] !== $companyId) continue;
            foreach (CostCentresService::listForCompany($conn, $actor, $c['id']) as $r) {
                $out[] = [
                    'id'           => (int)$r['id'],
                    'company_id'   => $c['id'],
                    'company_name' => $c['name'],
                    'code'         => $r['code'],
                    'name'         => $r['name'],
                    'description'  => $r['description'],
                    'parent_id'    => $r['parent_id'] === null ? null : (int)$r['parent_id'],
                    'is_active'    => (int)$r['is_active'] === 1,
                    'child_count'  => (int)$r['child_count'],
                    // GH #160 Part B: who answers for it. *_left: a person since deactivated -
                    // kept and flagged, never silently removed.
                    'owner'        => !empty($r['owner_user_id']) ? ['id' => (int)$r['owner_user_id'], 'name' => $r['owner_name'] ?: $r['owner_email'], 'email' => $r['owner_email'], 'left' => !(int)$r['owner_active']] : null,
                    'owner_group'  => !empty($r['owner_group_id']) ? ['id' => (int)$r['owner_group_id'], 'name' => $r['owner_group_name'], 'left' => !(int)$r['owner_group_active']] : null,
                    'deputy'       => !empty($r['deputy_user_id']) ? ['id' => (int)$r['deputy_user_id'], 'name' => $r['deputy_name'] ?: $r['deputy_email'], 'email' => $r['deputy_email'], 'left' => !(int)$r['deputy_active']] : null,
                ];
            }
        }
        echo json_encode([
            'success' => true, 'ready' => true, 'all' => $all, 'companies' => $companies, 'company_id' => $companyId,
            'cost_centres' => $out,
            'owners'       => CostCentresService::peopleReady($conn),   // GH #160 Part B - false until Database Verification
            'groups'       => CostCentresService::peopleReady($conn) ? $conn->query("SELECT id, name FROM knowledge_user_groups WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [],
        ]);
        exit;
    }

    if ($method === 'POST' && $action === 'save') {
        $in = array_intersect_key($json, array_flip(['code', 'name', 'description', 'parent_id', 'is_active', 'owner_user_id', 'owner_group_id', 'deputy_user_id']));
        $id = (int)($json['id'] ?? 0);
        if ($id > 0) {
            CostCentresService::update($conn, $actor, $id, $in);
        } else {
            $id = CostCentresService::create($conn, $actor, $companyId, $in);
        }
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    // GH #160 Part B: the Owner and Deputy pickers. Here rather than People's own list so
    // it works with the People module switched off; the same rule the service applies on
    // save (active, in this company - a person with no company is the Default company's).
    if ($method === 'GET' && $action === 'people') {
        CostCentresService::assertCompany($conn, $actor, $companyId);
        $q = trim((string)($_GET['q'] ?? ''));
        $isDefault = $companyId === (int)getDefaultTenantId($conn);
        $sql = "SELECT id, display_name, email FROM users
                 WHERE is_active = 1 AND email IS NOT NULL AND email <> ''
                   AND (tenant_id = ?" . ($isDefault ? " OR tenant_id IS NULL" : "") . ")";
        $args = [$companyId];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $sql .= " AND (display_name LIKE ? OR email LIKE ? OR preferred_name LIKE ?)";
            array_push($args, $like, $like, $like);
        }
        $st = $conn->prepare($sql . " ORDER BY COALESCE(NULLIF(display_name, ''), email) LIMIT 20");
        $st->execute($args);
        $people = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['display_name'] ?: $r['email'], 'email' => $r['email']], $st->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['success' => true, 'people' => $people]);
        exit;
    }

    if ($method === 'POST' && $action === 'delete') {
        CostCentresService::delete($conn, $actor, (int)($json['id'] ?? 0));
        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'GET' && $action === 'export') {
        $format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        $rows = CostCentresService::listForCompany($conn, $actor, $companyId);
        $header = ['Code', 'Name', 'Description', 'Parent code', 'Active'];
        $data = array_map(fn($r) => [$r['code'], $r['name'], (string)$r['description'], (string)$r['parent_code'], (int)$r['is_active'] ? 'Yes' : 'No'], $rows);
        // GH #160 Part B: the owner and deputy, in the shape the import reads back.
        if (CostCentresService::peopleReady($conn)) {
            $header = array_merge($header, ['Owner email', 'Owner group', 'Deputy email']);
            foreach ($rows as $i => $r) $data[$i] = array_merge($data[$i], [(string)($r['owner_email'] ?? ''), (string)($r['owner_group_name'] ?? ''), (string)($r['deputy_email'] ?? '')]);
        }

        $companyName = '';
        foreach ($companies as $c) if ($c['id'] === $companyId) $companyName = $c['name'];
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($companyName)), '-');
        $file = 'cost-centres' . (count($companies) > 1 && $slug !== '' ? '-' . $slug : '') . '-' . gmdate('Y-m-d') . '.' . $format;

        if ($format === 'csv') {
            $body = spreadsheetWriteCsv($header, $data);
            header('Content-Type: text/csv; charset=utf-8');
        } else {
            $body = spreadsheetWriteXlsx('Cost centres', $header, $data, array_slice([14, 36, 50, 14, 8, 30, 24, 30], 0, count($header)));
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
            costCentreJsonFail(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'The file is larger than this server accepts.' : 'Choose a file to import.');
        }
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'xlsx', 'xls'], true)) costCentreJsonFail('Use an Excel workbook (.xlsx) or a CSV file.');

        try {
            $sheet = spreadsheetRead($f['tmp_name'], (string)$f['name']);
        } catch (RuntimeException $e) {
            costCentreJsonFail($e->getMessage());
        }
        if (count($sheet) < 2) costCentreJsonFail('The file has no rows under its headings.');

        // Map the heading row onto our columns. Unknown headings are ignored, so
        // an ERP export with twenty columns works as long as it has a code.
        $norm = fn($s) => preg_replace('/[^a-z0-9äöüß]+/u', '', mb_strtolower((string)$s));
        $map = [];
        foreach ($sheet[0] as $i => $heading) {
            foreach (costCentreColumns() as $field => $names) {
                if (!isset($map[$field]) && in_array($norm($heading), $names, true)) { $map[$field] = $i; break; }
            }
        }
        if (!isset($map['code'])) costCentreJsonFail('The first row must be headings, and one of them must be Code. Download an export to see the layout.');

        $rows = [];
        foreach (array_slice($sheet, 1) as $n => $line) {
            $r = ['line' => $n + 2];
            foreach ($map as $field => $i) $r[$field] = $line[$i] ?? '';
            $rows[] = $r;
        }
        $report = CostCentresService::sync($conn, $actor, $companyId, $rows, [
            'dry_run'            => ($_POST['apply'] ?? '0') !== '1',
            'deactivate_missing' => ($_POST['deactivate_missing'] ?? '0') === '1',
        ]);
        echo json_encode(['success' => true, 'columns' => array_keys($map), 'report' => $report]);
        exit;
    }

    costCentreJsonFail('Unknown action', 400);
} catch (ServiceError $e) {
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    error_log('cost_centres: ' . $e->getMessage());
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Something went wrong - nothing has been changed.']);
}
