<?php
/**
 * Workflows and companies (GH #209).
 *
 * A workflow is SHARED BY DESIGN, like a Knowledge article - the opposite of
 * scoped data such as tickets:
 *
 *   workflows.tenant_id NULL  = shared: runs for every company's events. Every
 *                               workflow from before companies is one, so an
 *                               upgrade changes nothing that already runs.
 *   workflows.tenant_id set   = runs only for that company's events, and its
 *                               actions may only touch that company's records
 *                               (enforced in the engine, never by a condition).
 *
 *   workflow_executions.tenant_id = the company the EVENT belonged to. A run
 *                               stores its whole payload - a ticket's subject,
 *                               requester and the rest - so who may read it
 *                               follows that company. NULL = no company known (a
 *                               settings event, or a run from before the column):
 *                               only someone who can see EVERY company reads it.
 *                               Fail closed, so old history cannot leak.
 *
 * ⚠️ Do not reach for activeTenantFilter() here: it reads NULL as "the Default
 * company's", which would hide every shared workflow from every client company.
 *
 * Who may change a workflow: a shared one runs everywhere, so only someone who
 * can see every company; a company's own, anyone who can see that company. Every
 * helper is a no-op on a single-company install and before Database Verification
 * has added the columns (workflowTenancyReady()), so behaviour there is unchanged.
 */

require_once __DIR__ . '/tenancy.php';

/** Are the #209 columns there? Until Database Verification runs they are not, and nothing here applies. */
function workflowTenancyReady(PDO $conn): bool
{
    return tenancyColumnExists($conn, 'workflows', 'tenant_id')
        && tenancyColumnExists($conn, 'workflow_executions', 'tenant_id');
}

/** Is company scoping live for workflows? False at N=1 and before Database Verification. */
function workflowTenancyActive(PDO $conn): bool
{
    return isMultiTenant($conn) && workflowTenancyReady($conn);
}

/**
 * The records an event can be ABOUT, in the order they are looked for, with the
 * table that says which company each belongs to and what NULL means there.
 *
 * 'default' = scoped data, NULL is the Default company's (tickets, tasks...).
 * 'shared'  = NULL belongs to no company (a shared article, a shared config row).
 *
 * The ticket comes first: sla.*, tracker.* and catalogue requests that raised a
 * ticket are about that ticket. Payload keys are the ones availableFields()
 * documents for each trigger.
 */
function workflowEventRecordTables(): array
{
    return [
        'ticket'         => ['tickets',            'default'],
        'task'           => ['tasks',              'default'],
        'change'         => ['changes',            'default'],
        'problem'        => ['problems',           'default'],
        'asset'          => ['assets',             'default'],
        'object'         => ['cmdb_objects',       'default'],   // cmdb.object.*
        'project'        => ['projects',           'default'],
        'jml_case'       => ['jml_cases',          'default'],   // jml.* - Starters & leavers
        'domain'         => ['domains',            'default'],
        'contract'       => ['contracts',          'default'],
        'submission'     => ['form_submissions',   'default'],   // form.submitted, catalogue_request.*
        'article'        => ['knowledge_articles', 'shared'],
        // Settings that are per company (a company's own type or origin); a shared one is no company's.
        'ticket_type'    => ['ticket_types',       'shared'],
        'ticket_origin'  => ['ticket_origins',     'shared'],
        'ticket_category'=> ['ticket_categories',  'shared'],
        'asset_type'     => ['asset_types',        'shared'],
        'asset_status'   => ['asset_status_types', 'shared'],
        'asset_location' => ['asset_locations',    'default'],
    ];
}

/**
 * Which company an event belongs to, or null when it belongs to none.
 *
 * Read from the database, not from the payload: Test fire and the REST fire route
 * take a payload the caller wrote, and a company read out of it would let a run
 * be filed under any company the caller liked. A record that is already gone
 * (a .deleted event after a hard delete) falls back to a `tenant_id` the host
 * module put on the payload, then to no company.
 *
 * @param array $payload  the event payload, as dispatch() receives it
 */
function workflowEventTenantId(PDO $conn, array $payload): ?int
{
    if (!isMultiTenant($conn)) return null;
    foreach (workflowEventRecordTables() as $key => [$table, $nullMeans]) {
        $rec = $payload[$key] ?? null;
        if (!is_array($rec) || !isset($rec['id']) || (int)$rec['id'] <= 0) continue;
        try {
            $stmt = $conn->prepare("SELECT tenant_id FROM `$table` WHERE id = ?");
            $stmt->execute([(int)$rec['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception) {
            $row = false;   // a part-migrated table: no company to read
        }
        if ($row) {
            if ($row['tenant_id'] !== null) return (int)$row['tenant_id'];
            return $nullMeans === 'default' ? getDefaultTenantId($conn) : null;
        }
        $hint = $rec['tenant_id'] ?? ($rec['company_id'] ?? null);
        if ($hint !== null && (int)$hint > 0) return (int)$hint;
        return $nullMeans === 'default' ? getDefaultTenantId($conn) : null;
    }
    return null;
}

/**
 * Which workflows an analyst sees in a LIST: the shared ones, plus the company
 * they have switched to (or, in the All companies view, every company they can
 * see). Follows the header's company switcher, like every other module.
 *
 * @param string $qualified  the already-qualified column, e.g. "w.tenant_id"
 * @return array [sqlFragment, params]
 */
function workflowListFilter(PDO $conn, int $analystId, string $qualified = 'tenant_id'): array
{
    if (!workflowTenancyActive($conn)) return ['', []];
    if (isActiveTenantAll($conn)) {
        $ids = array_values(array_unique(array_map('intval', getAccessibleTenantIds($conn, $analystId))));
        if (!$ids) return [" AND $qualified IS NULL", []];
        return [" AND ($qualified IS NULL OR $qualified IN (" . implode(',', array_fill(0, count($ids), '?')) . "))", $ids];
    }
    $active = getActiveTenantId($conn, $analystId);
    if (!analystCanAccessTenant($conn, $analystId, $active)) return [" AND $qualified IS NULL", []];
    return [" AND ($qualified IS NULL OR $qualified = ?)", [$active]];
}

/**
 * Which RUNS an analyst may read: those whose event belonged to a company they
 * can see. A run with no company known only for someone who can see them all.
 *
 * Every company they can see, not just the switched-to one: the run log is a
 * history across workflows, and a shared workflow's run for company B would
 * otherwise vanish the moment the analyst switched to company A.
 *
 * @param string $qualified  the already-qualified column, e.g. "e.tenant_id"
 * @return array [sqlFragment, params]
 */
function workflowExecutionFilter(PDO $conn, int $analystId, string $qualified = 'tenant_id'): array
{
    if (!workflowTenancyActive($conn)) return ['', []];
    if (analystHasAllTenantAccess($conn, $analystId)) return ['', []];
    $ids = array_values(array_unique(array_map('intval', getAccessibleTenantIds($conn, $analystId))));
    if (!$ids) return [' AND 1 = 0', []];
    return [" AND $qualified IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids];
}

/**
 * May this analyst READ this workflow? Shared ones, yes; a company's own, when
 * they can see that company. Unknown id = false.
 *
 * @param array|int $wf  the workflows row (with tenant_id), or its id
 */
function analystCanReadWorkflow(PDO $conn, int $analystId, $wf): bool
{
    $row = is_array($wf) ? $wf : workflowRowTenant($conn, (int)$wf);
    if ($row === null) return false;
    if (!workflowTenancyActive($conn)) return true;
    $tid = $row['tenant_id'] ?? null;
    return $tid === null || analystCanAccessTenant($conn, $analystId, (int)$tid);
}

/**
 * May this analyst create, change, delete or fire a workflow FOR this company?
 * null = shared, which runs for every company, so only someone who can see them
 * all. Used for the workflow as stored AND for the company it is being moved to.
 *
 * @param ?int $tenantId  the workflow's company; null = shared
 */
function analystCanWriteWorkflowFor(PDO $conn, int $analystId, ?int $tenantId): bool
{
    if (!workflowTenancyActive($conn)) return true;
    if ($tenantId === null) return analystHasAllTenantAccess($conn, $analystId);
    return analystCanAccessTenant($conn, $analystId, $tenantId);
}

/**
 * May this analyst READ this run? See workflowExecutionFilter().
 *
 * @param ?int $tenantId  the run's tenant_id (the event's company); null = none known
 */
function analystCanReadWorkflowRun(PDO $conn, int $analystId, ?int $tenantId): bool
{
    if (!workflowTenancyActive($conn)) return true;
    if (analystHasAllTenantAccess($conn, $analystId)) return true;
    return $tenantId !== null && analystCanAccessTenant($conn, $analystId, $tenantId);
}

/**
 * The workflow's id and company, or null when there is no such workflow.
 *
 * @param int $workflowId  the workflows id
 * @return ?array{id:int, tenant_id:?int}
 */
function workflowRowTenant(PDO $conn, int $workflowId): ?array
{
    if ($workflowId <= 0) return null;
    $cols = workflowTenancyReady($conn) ? 'id, tenant_id' : 'id, NULL AS tenant_id';
    $stmt = $conn->prepare("SELECT $cols FROM workflows WHERE id = ?");
    $stmt->execute([$workflowId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return ['id' => (int)$row['id'], 'tenant_id' => $row['tenant_id'] === null ? null : (int)$row['tenant_id']];
}

/**
 * The company a request names for a workflow, checked: '' / null / 'shared' =
 * shared; otherwise a company id that must exist. Returns [ok, tenantId|null, error].
 *
 * @param mixed $raw  the request's company_id / tenant_id value, untrusted
 */
function workflowParseCompany(PDO $conn, $raw): array
{
    if ($raw === null || $raw === '' || $raw === 'shared' || $raw === 0 || $raw === '0') return [true, null, null];
    $id = (int)$raw;
    if ($id <= 0 || !getTenantById($conn, $id)) return [false, null, 'Unknown company'];
    return [true, $id, null];
}

/** The company's name for a message, or '' when unknown. */
function workflowCompanyName(PDO $conn, ?int $tenantId): string
{
    if ($tenantId === null) return '';
    $t = getTenantById($conn, $tenantId);
    return $t ? (string)$t['name'] : '';
}
