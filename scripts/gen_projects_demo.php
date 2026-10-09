<?php
/**
 * Generates the Projects part of database/demo-data/tasks.json (3.3.0).
 *   php scripts/gen_projects_demo.php
 * Edit the projects HERE, run it, and commit the JSON it writes - the JSON is what
 * System -> Demo data imports.
 * Keeps every non-project record in the file untouched; replaces projects, their
 * stages, project tasks (+ their comments) and every project_* table.
 *
 * Days are offsets from the day of import (negative = past). A task is
 *   [ref, title, stage, start, due, done|null, assignee, estimate, status-override?, priority?]
 * Its status today follows from those days (done -> Done, started -> In Progress,
 * else To Do); 'Blocked' can be forced. The flow history (project_task_flow) is
 * worked out from the same days, so the cumulative flow diagram agrees with the plan.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$file = __DIR__ . '/../database/demo-data/tasks.json';
$d = json_decode(file_get_contents($file), true);

$D  = fn(int $n) => '__RELATIVE_DATEONLY:' . ($n >= 0 ? '+' : '') . $n . 'd__';
$DT = fn(int $n, int $h = 0) => '__RELATIVE_DATE:' . ($n >= 0 ? '+' : '') . $n . 'd' . ($h ? ($h > 0 ? '+' : '') . $h . 'h' : '') . '__';
$A  = fn(string $u) => '@analysts.' . $u;

// ---- Strip the old project content -------------------------------------------------------------
$d['tier2']['projects'] = [];
$d['tier2']['project_stages'] = [];
$projectTaskRefs = [];
$d['tier2']['tasks'] = array_values(array_filter($d['tier2']['tasks'], function ($t) use (&$projectTaskRefs) {
    if (!empty($t['project_id'])) { if (!empty($t['_ref'])) $projectTaskRefs[] = '@tasks.' . $t['_ref']; return false; }
    return true;
}));
$d['tier2']['task_comments'] = array_values(array_filter($d['tier2']['task_comments'], fn($c) => !in_array($c['task_id'] ?? '', $projectTaskRefs, true)));
foreach (['tier3', 'tier4', 'tier5'] as $tier) foreach (array_keys($d[$tier] ?? []) as $t) if (str_starts_with($t, 'project_') || $t === 'task_dependencies') unset($d[$tier][$t]);
foreach (array_keys($d['tier1']) as $t) if (!in_array($t, ['analysts', 'teams', 'project_roles'], true)) unset($d['tier1'][$t]);

// ---- Things in other modules a project points at (matched, never inserted) ----------------------------
$skip = function (string $table, string $ref, string $by, string $value) use (&$d) {
    $d['tier1'][$table][] = ['_ref' => $ref, '_skip_insert' => true, '_match_by' => $by, '_match_value' => $value];
};
foreach ([['u_alice', 'alice.johnson@example.com'], ['u_bob', 'bob.taylor@example.com'], ['u_claire', 'claire.davies@example.com'], ['u_david', 'david.wilson@example.com'],
          ['u_emma', 'emma.thomas@example.com'], ['u_frank', 'frank.roberts@example.com'], ['u_grace', 'grace.walker@example.com'], ['u_karen', 'karen.hall@example.com']] as [$r, $e]) $skip('users', $r, 'email', $e);
foreach ([['ch_exchange', 'Upgrade Exchange Server to 2019 CU14'], ['ch_wifi', 'Deploy New WiFi Access Points - Building B'], ['ch_files', 'Migrate File Server to Azure Files'],
          ['ch_switch', 'Replace Core Switch - Server Room']] as [$r, $v]) $skip('changes', $r, 'title', $v);
foreach (['LT-001', 'LT-002', 'LT-003', 'LT-004', 'LT-005', 'LT-006'] as $h) $skip('assets', 'as_' . strtolower(str_replace('-', '', $h)), 'hostname', $h);
foreach ([['tk_wifi', 'Wi-Fi keeps disconnecting in meeting room B'], ['tk_vlan', 'Investigate intermittent network timeouts on VLAN 10'], ['tk_mail', 'Email delivery delays to external recipients'],
          ['tk_laptop', 'New laptop setup required for new starter']] as [$r, $v]) $skip('tickets', $r, 'subject', $v);
foreach ([['ct_network', 'Managed Network Services'], ['ct_hardware', 'Hardware Supply Agreement'], ['ct_cloud', 'Cloud Platform Licences']] as [$r, $v]) $skip('contracts', $r, 'title', $v);
foreach ([['ka_wifi', 'Troubleshooting Wi-Fi and Slow Network Connectivity'], ['ka_mail', 'Troubleshooting Email Delivery Delays'], ['ka_mobile', 'Setting Up Company Email on Mobile Devices'],
          ['ka_hardware', 'How to Request IT Hardware and Equipment']] as [$r, $v]) $skip('knowledge_articles', $r, 'title', $v);
foreach ([['ci_sql1', 'SQLSVR01'], ['ci_sql2', 'SQLSVR02']] as [$r, $v]) $skip('cmdb_objects', $r, 'name', $v);

// ---- Builders ------------------------------------------------------------------------------------------
$T = [];    // table => rows
$add = function (string $tier, string $table, array $row) use (&$T) { $T[$tier][$table][] = $row; };
$flowTasks = [];   // project ref => [[created, start, done, blockedFrom]]
$kindOf = ['staged' => 'stage', 'agile' => 'sprint', 'simple' => 'phase'];

$project = function (array $p) use ($add, $D, $DT, $A) {
    $row = [
        '_ref' => $p['ref'], 'name' => $p['name'], 'goal' => $p['goal'], 'summary' => $p['summary'] ?? null, 'methodology' => $p['method'],
        'status' => $p['status'], 'health' => $p['health'] ?? 'auto', 'health_note' => $p['health_note'] ?? null, 'priority' => $p['priority'] ?? 'medium',
        'owner_analyst_id' => $A($p['owner']), 'start_date' => $D($p['start']), 'target_end_date' => $D($p['target']),
        'actual_end_date' => isset($p['actual']) ? $D($p['actual']) : null, 'colour' => $p['colour'], 'icon' => $p['icon'],
        'currency' => 'GBP', 'visibility' => $p['visibility'] ?? 'everyone', 'report_schedule' => $p['schedule'] ?? 'off', 'report_schedule_kind' => $p['schedule_kind'] ?? 'highlight',
        'business_case' => $p['case'] ?? null, 'tailoring' => isset($p['tailoring']) ? json_encode($p['tailoring']) : null,
        'created_by_id' => $A($p['creator'] ?? 'admin'), 'created_datetime' => $DT($p['start'] - 5), 'updated_datetime' => $DT(-1),
        'closed_datetime' => in_array($p['status'], ['closed', 'cancelled'], true) ? $DT($p['actual'] ?? -1) : null,
    ];
    foreach (['estimated_cost', 'estimated_benefit', 'approval_status', 'proposed_by_name', 'proposed_by_email', 'approval_notes'] as $k) if (isset($p[$k])) $row[$k] = $p[$k];
    if (isset($p['approval_by'])) { $row['approval_by_id'] = $A($p['approval_by']); $row['approval_datetime'] = $DT($p['approval_at']); }
    $add('tier2', 'projects', array_filter($row, fn($v) => $v !== null));
    $add('tier3', 'project_audit', ['project_id' => '@projects.' . $p['ref'], 'analyst_id' => $A($p['creator'] ?? 'admin'), 'field_name' => 'project_created', 'new_value' => $p['name'], 'source' => 'app', 'created_datetime' => $DT($p['start'] - 5)]);
};
$stage = function (string $prj, string $method, string $ref, string $name, string $goal, int $pos, int $start, int $end, string $status, array $gate = []) use ($add, $D, $DT, $A, $kindOf) {
    $row = ['_ref' => $ref, 'project_id' => '@projects.' . $prj, 'kind' => $kindOf[$method], 'name' => $name, 'goal' => $goal, 'position' => $pos,
            'status' => $status, 'start_date' => $D($start), 'end_date' => $D($end), 'gate_kind' => $gate['kind'] ?? 'standard',
            'created_datetime' => $DT($start - 3), 'updated_datetime' => $DT(min(-1, $end))];
    if (!empty($gate['decision'])) {
        $row += ['gate_decision' => $gate['decision'], 'gate_notes' => $gate['notes'] ?? null, 'gate_decided_by' => $A($gate['by'] ?? 'admin'), 'gate_decided_datetime' => $DT($end)];
        $add('tier3', 'project_audit', ['project_id' => '@projects.' . $prj, 'analyst_id' => $A($gate['by'] ?? 'admin'), 'field_name' => 'gate', 'new_value' => $name . ': ' . $gate['decision'], 'source' => 'app', 'created_datetime' => $DT($end)]);
    }
    $add('tier2', 'project_stages', array_filter($row, fn($v) => $v !== null));
};
$taskN = 0;
$task = function (string $prj, array $t) use ($add, $D, $DT, $A, &$flowTasks, &$taskN) {
    [$ref, $title, $stg, $start, $due, $done, $who, $est] = $t;
    $status = $t[8] ?? ($done !== null && $done <= 0 ? 'Done' : ($start <= 0 ? 'In Progress' : 'To Do'));
    $created = min($start - 4, -1);
    $row = ['_ref' => $ref, 'title' => $title, 'status' => $status, 'priority' => $t[9] ?? 'Medium', 'assigned_analyst_id' => $who ? $A($who) : null,
            'project_id' => '@projects.' . $prj, 'project_stage_id' => $stg ? '@project_stages.' . $stg : null,
            'start_date' => $D($start), 'due_date' => $D($due), 'estimate_hours' => $est,
            'completed_datetime' => $status === 'Done' ? $DT($done, 3) : null, 'board_position' => ++$taskN,
            'created_by_id' => $A('admin'), 'created_datetime' => $DT($created), 'updated_datetime' => $DT($status === 'Done' ? $done : -1)];
    $add('tier2', 'tasks', array_filter($row, fn($v) => $v !== null));
    $flowTasks[$prj][] = ['created' => $created, 'start' => $start, 'done' => $status === 'Done' ? $done : null, 'blocked' => $status === 'Blocked' ? max($start, -6) : null, 'cancelled' => $status === 'Cancelled'];
};
$dep = fn(string $taskRef, string $onRef, int $lag = 0) => $add('tier3', 'task_dependencies', ['task_id' => '@tasks.' . $taskRef, 'depends_on_id' => '@tasks.' . $onRef, 'lag_days' => $lag, 'created_by_id' => $A('admin'), 'created_datetime' => $DT(-20)]);
$milestone = function (string $prj, ?string $stg, string $name, int $due, ?int $done, int $pos) use ($add, $D, $DT, $A) {
    $add('tier3', 'project_milestones', array_filter(['project_id' => '@projects.' . $prj, 'stage_id' => $stg ? '@project_stages.' . $stg : null, 'name' => $name, 'due_date' => $D($due),
        'done_date' => $done !== null ? $D($done) : null, 'done_by_analyst_id' => $done !== null ? $A('admin') : null, 'position' => $pos,
        'created_by_analyst_id' => $A('admin'), 'created_datetime' => $DT(-30), 'updated_datetime' => $DT(-1)], fn($v) => $v !== null));
};
$member = function (string $prj, string $ref, array $who, string $role, int $pos, array $stake = []) use ($add, $A) {
    $row = ['_ref' => $ref, 'project_id' => '@projects.' . $prj, 'role_id' => '@project_roles.' . $role, 'position' => $pos];
    if (isset($who['analyst'])) $row['analyst_id'] = $A($who['analyst']);
    if (isset($who['team'])) $row['team_id'] = '@teams.' . $who['team'];
    if (isset($who['user'])) { $row['user_id'] = '@users.' . $who['user']; $row['_optional'] = true; }
    foreach (['power', 'interest', 'stance', 'keep_informed'] as $k) if (isset($stake[$k])) $row[$k] = $stake[$k];
    if (!empty($who['notes'])) $row['notes'] = $who['notes'];
    $add('tier3', 'project_members', $row);
};
$raid = function (string $prj, array $r) use ($add, $D, $DT, $A) {
    $row = ['project_id' => '@projects.' . $prj, 'type' => $r['type'], 'title' => $r['title'], 'description' => $r['description'] ?? null,
            'probability' => $r['p'] ?? null, 'impact' => $r['i'] ?? null, 'response' => $r['response'] ?? null, 'response_plan' => $r['plan'] ?? null,
            'owner_analyst_id' => isset($r['owner']) ? $A($r['owner']) : null, 'status' => $r['status'] ?? 'open', 'due_date' => isset($r['due']) ? $D($r['due']) : null,
            'raised_by_id' => $A($r['by'] ?? 'admin'), 'raised_datetime' => $DT($r['raised'] ?? -10), 'updated_datetime' => $DT(-1),
            'closed_datetime' => ($r['status'] ?? 'open') === 'closed' ? $DT($r['closed'] ?? -2) : null,
            'decided_by' => $r['decided_by'] ?? null, 'decided_date' => isset($r['decided']) ? $D($r['decided']) : null, 'rationale' => $r['rationale'] ?? null];
    if (!empty($r['escalated'])) $row += ['escalated_datetime' => $DT($r['escalated']), 'escalated_by_id' => $A($r['owner'] ?? 'admin'), 'escalation_note' => $r['escalation_note'] ?? null];
    if (isset($r['ref'])) $row['_ref'] = $r['ref'];
    $add('tier3', 'project_raid', array_filter($row, fn($v) => $v !== null));
};
$item = fn(string $prj, string $ref, string $title, string $moscow, string $status, int $pos, ?string $stg = null, ?string $ac = null) =>
    $add('tier3', 'project_items', array_filter(['_ref' => $ref, 'project_id' => '@projects.' . $prj, 'title' => $title, 'moscow' => $moscow, 'status' => $status,
        'position' => $pos, 'stage_id' => $stg ? '@project_stages.' . $stg : null, 'acceptance_criteria' => $ac, 'created_datetime' => $DT(-30), 'updated_datetime' => $DT(-2)], fn($v) => $v !== null));
$raci = fn(string $prj, string $itemRef, string $memberRef, string $letter, bool $optional = false) =>
    $add('tier4', 'project_raci', array_filter(['project_id' => '@projects.' . $prj, 'item_id' => '@project_items.' . $itemRef, 'member_id' => '@project_members.' . $memberRef, 'letter' => $letter, '_optional' => $optional ?: null], fn($v) => $v !== null));
$tol = fn(string $prj, string $dim, int $v) => $add('tier3', 'project_tolerances', ['project_id' => '@projects.' . $prj, 'dimension' => $dim, 'value' => $v]);
$line = fn(string $prj, string $title, string $cat, float $planned, ?float $actual, int $plannedDay, ?int $spentDay, ?float $forecast = null, int $pos = 0, ?string $notes = null) =>
    $add('tier3', 'project_budget_lines', array_filter(['project_id' => '@projects.' . $prj, 'title' => $title, 'category' => $cat, 'planned_amount' => $planned, 'actual_amount' => $actual,
        'planned_date' => $D($plannedDay), 'spent_date' => $spentDay !== null ? $D($spentDay) : null, 'forecast_amount' => $forecast, 'notes' => $notes, 'position' => $pos,
        'created_by_id' => $A('admin'), 'created_datetime' => $DT(-30), 'updated_datetime' => $DT(-2)], fn($v) => $v !== null));
$benefit = function (string $prj, string $ref, array $b) use ($add, $D, $DT, $A) {
    $add('tier3', 'project_benefits', array_filter(['_ref' => $ref, 'project_id' => '@projects.' . $prj, 'title' => $b['title'], 'measure' => $b['measure'], 'unit' => $b['unit'],
        'direction' => $b['direction'], 'baseline_value' => $b['baseline'] ?? null, 'target_value' => $b['target'] ?? null, 'target_date' => isset($b['by']) ? $D($b['by']) : null,
        'owner_analyst_id' => isset($b['owner']) ? $A($b['owner']) : null, 'review_date' => isset($b['review']) ? $D($b['review']) : null, 'review_months' => $b['months'] ?? 3,
        'status' => $b['status'] ?? 'open', 'notes' => $b['notes'] ?? null, 'position' => $b['pos'] ?? 1, 'created_by_id' => $A('admin'), 'created_datetime' => $DT(-40), 'updated_datetime' => $DT(-2)], fn($v) => $v !== null));
    foreach ($b['measures'] ?? [] as [$day, $value, $note]) $add('tier4', 'project_benefit_measures', array_filter(['benefit_id' => '@project_benefits.' . $ref, 'value' => $value, 'measured_date' => $D($day), 'note' => $note, 'recorded_by_id' => $A($b['owner'] ?? 'admin'), 'created_datetime' => $DT($day)], fn($v) => $v !== null));
};
$gateItem = fn(string $prj, string $stg, string $kind, string $title, int $pos, ?string $who = null, ?int $doneDay = null, ?string $notes = null) =>
    $add('tier3', 'project_gate_items', array_filter(['project_id' => '@projects.' . $prj, 'stage_id' => '@project_stages.' . $stg, 'kind' => $kind, 'title' => $title,
        'analyst_id' => $who ? $A($who) : null, 'done_by_id' => $doneDay !== null ? $A($who ?? 'admin') : null, 'done_datetime' => $doneDay !== null ? $DT($doneDay) : null,
        'notes' => $notes, 'position' => $pos, 'created_by_id' => $A('admin'), 'created_datetime' => $DT(-12)], fn($v) => $v !== null));
$cr = function (string $prj, string $ref, int $n, array $c) use ($add, $D, $DT, $A) {
    $add('tier3', 'project_change_requests', array_filter(['_ref' => $ref, 'project_id' => '@projects.' . $prj, 'number' => $n, 'title' => $c['title'], 'description' => $c['description'] ?? null,
        'reason' => $c['reason'] ?? null, 'impact_days' => $c['days'] ?? null, 'impact_cost' => $c['cost'] ?? null, 'impact_scope' => $c['scope'] ?? null, 'status' => $c['status'],
        'raised_by_id' => $A($c['by']), 'raised_datetime' => $DT($c['raised']), 'decided_by_id' => isset($c['decided']) ? $A($c['decider'] ?? 'admin') : null,
        'decided_datetime' => isset($c['decided']) ? $DT($c['decided']) : null, 'decision_notes' => $c['notes'] ?? null, 'updated_datetime' => $DT($c['decided'] ?? $c['raised'])], fn($v) => $v !== null));
    $add('tier3', 'project_audit', ['project_id' => '@projects.' . $prj, 'analyst_id' => $A($c['by']), 'field_name' => 'change_raised', 'new_value' => 'CR-' . $n . ': ' . $c['title'], 'source' => 'app', 'created_datetime' => $DT($c['raised'])]);
    if (isset($c['decided'])) $add('tier3', 'project_audit', ['project_id' => '@projects.' . $prj, 'analyst_id' => $A($c['decider'] ?? 'admin'), 'field_name' => 'change_' . $c['status'], 'new_value' => 'CR-' . $n . ': ' . $c['title'], 'source' => 'app', 'created_datetime' => $DT($c['decided'])]);
};
$baseline = fn(string $prj, int $n, string $label, string $reason, int $day, array $recipe, ?string $crRef = null) =>
    $add('tier4', 'project_baselines', array_filter(['project_id' => '@projects.' . $prj, 'number' => $n, 'label' => $label, 'reason' => $reason, 'change_request_id' => $crRef ? '@project_change_requests.' . $crRef : null,
        'snapshot' => json_encode(['_demo' => $recipe]), 'currency' => 'GBP', 'created_by_id' => $A('admin'), 'created_datetime' => $DT($day)], fn($v) => $v !== null));
$report = fn(string $prj, string $kind, string $title, string $body, string $status, ?string $by, int $day, array $x = []) =>
    $add('tier3', 'project_reports', array_filter(['project_id' => '@projects.' . $prj, 'kind' => $kind, 'title' => $title, 'body' => $body, 'status' => $status,
        'ai_drafted' => $x['ai'] ?? 0, 'ai_edited' => $x['edited'] ?? 0, 'ai_model' => $x['model'] ?? null, 'created_by_id' => $by ? $A($by) : null, 'created_datetime' => $DT($day),
        'updated_by_id' => $by ? $A($by) : null, 'updated_datetime' => $DT($day), 'approved_by_id' => $status === 'approved' ? $A($x['approver'] ?? 'admin') : null,
        'approved_datetime' => $status === 'approved' ? $DT($day + 1) : null, 'sent_datetime' => isset($x['sent']) ? $DT($x['sent']) : null,
        'sent_by_id' => isset($x['sent']) ? $A($x['approver'] ?? 'admin') : null, 'sent_to' => $x['sent_to'] ?? null], fn($v) => $v !== null));
$link = fn(string $table, string $col, string $prj, string $target) =>
    $add('tier3', $table, ['project_id' => '@projects.' . $prj, $col => $target, 'created_by_analyst_id' => $A('admin'), 'created_datetime' => $DT(-15), '_optional' => true]);
$audit = fn(string $prj, string $who, string $field, ?string $old, ?string $new, int $day) =>
    $add('tier3', 'project_audit', array_filter(['project_id' => '@projects.' . $prj, 'analyst_id' => $A($who), 'field_name' => $field, 'old_value' => $old, 'new_value' => $new, 'source' => 'app', 'created_datetime' => $DT($day)], fn($v) => $v !== null));

// =====================================================================================================
// 1. Move to the Bradford office - staged, active, high. The fullest example of everything.
// =====================================================================================================
$project(['ref' => 'prj_office', 'name' => 'Move to the Bradford office', 'method' => 'staged', 'status' => 'active', 'priority' => 'high', 'owner' => 'swilliams',
    'goal' => 'All 40 staff working from Bradford by the end of next quarter', 'summary' => 'The Leeds lease ends next year. Survey and design are done; the network is being built now, then move day.',
    'start' => -45, 'target' => +55, 'colour' => 'sunset', 'icon' => 'building', 'schedule' => 'monthly', 'schedule_kind' => 'highlight',
    'case' => "The Leeds lease ends next year and renewing it costs more than the move.\n\nBenefits: one site instead of two, room to grow, and a modern network with no legacy kit.\nCosts: cabling and switches (quoted), two days of disruption on move day.\nMain risks: kit delivery and landlord access - both in the RAID log."]);
$stage('prj_office', 'staged', 'ps_office_design', 'Survey and design', 'A desk plan everyone has agreed', 1, -45, -18, 'closed', ['decision' => 'go', 'notes' => 'Desk plan signed off by every manager. Network kit ordered.']);
$stage('prj_office', 'staged', 'ps_office_network', 'Build the network', 'Cabling, switches, Wi-Fi and phones working in Bradford', 2, -17, +20, 'active');
$stage('prj_office', 'staged', 'ps_office_move', 'Move weekend', 'Everyone moved and working by Monday morning', 3, +21, +38, 'planned', ['kind' => 'golive']);
$stage('prj_office', 'staged', 'ps_office_settle', 'Settle in', 'Snags fixed and the Leeds office handed back', 4, +39, +55, 'planned');
foreach ([
    ['po_survey', 'Survey the new floor with the landlord', 'ps_office_design', -44, -38, -38, 'swilliams', 6],
    ['po_deskplan', 'Agree the desk and room plan with every manager', 'ps_office_design', -38, -25, -24, 'swilliams', 10],
    ['po_order_cable', 'Order the network cabling', 'ps_office_design', -26, -20, -21, 'lbrown', 2],
    ['po_order_switch', 'Order switches and Wi-Fi access points', 'ps_office_design', -26, -20, -19, 'lbrown', 3],
    ['po_cabling', 'Cabling installed and tested on every floor', 'ps_office_network', -15, -4, -3, 'lbrown', 24],
    ['po_switches', 'Switches racked and configured', 'ps_office_network', -3, -1, -1, 'mjones', 16],
    ['po_wifi', 'Wi-Fi survey and access points installed', 'ps_office_network', +3, +12, null, 'mjones', 12],
    ['po_internet', 'Internet line live', 'ps_office_network', -10, +4, null, 'lbrown', 4, 'Blocked', 'High'],
    ['po_phones', 'Phone numbers ported or redirected', 'ps_office_network', +6, +16, null, 'jsmith', 8],
    ['po_printers', 'Printers set up and on the network', 'ps_office_network', +14, +19, null, 'jsmith', 6],
    ['po_instructions', 'Send move instructions to staff', 'ps_office_move', +21, +26, null, 'swilliams', 3],
    ['po_label', 'Label every desk and device', 'ps_office_move', +24, +30, null, 'jsmith', 8],
    ['po_moveday', 'Move day', 'ps_office_move', +31, +33, null, 'swilliams', 30],
    ['po_testdesks', 'Test every desk before Monday', 'ps_office_move', +34, +35, null, 'mjones', 10],
    ['po_snags', 'Collect and fix snags', 'ps_office_settle', +39, +46, null, 'jsmith', 12],
    ['po_keys', 'Hand back the Leeds office keys', 'ps_office_settle', +45, +50, null, 'swilliams', 2],
    ['po_lessons', 'Write up lessons learned', 'ps_office_settle', +50, +55, null, 'swilliams', 3],
] as $t) $task('prj_office', $t);
// An overdue task the digest and the health strip will pick up.
$task('prj_office', ['po_floorplan', 'Publish the final floor plan on the intranet', 'ps_office_network', -12, -4, null, 'jsmith', 2]);
$dep('po_cabling', 'po_order_cable'); $dep('po_switches', 'po_order_switch'); $dep('po_switches', 'po_cabling'); $dep('po_wifi', 'po_switches');
$dep('po_printers', 'po_switches'); $dep('po_moveday', 'po_wifi'); $dep('po_moveday', 'po_label'); $dep('po_testdesks', 'po_moveday'); $dep('po_snags', 'po_testdesks'); $dep('po_keys', 'po_moveday', 7);
// A clash on purpose: phones planned to start before the Wi-Fi they need is finished.
$dep('po_phones', 'po_wifi');
$milestone('prj_office', 'ps_office_design', 'Desk plan signed off', -24, -24, 1);
$milestone('prj_office', 'ps_office_network', 'Network live in Bradford', +20, null, 2);
$milestone('prj_office', 'ps_office_move', 'Move day', +32, null, 3);
$milestone('prj_office', 'ps_office_settle', 'Leeds office handed back', +50, null, 4);
$member('prj_office', 'pm_o_admin', ['analyst' => 'admin'], 'role_exec', 1, ['power' => 5, 'interest' => 4, 'stance' => 'champion', 'keep_informed' => 'Monthly highlight report and a word before each gate']);
$member('prj_office', 'pm_o_sw', ['analyst' => 'swilliams'], 'role_pm', 2, ['power' => 4, 'interest' => 5, 'stance' => 'champion', 'keep_informed' => 'Runs the weekly project meeting']);
$member('prj_office', 'pm_o_lb', ['analyst' => 'lbrown'], 'role_tm', 3, ['power' => 3, 'interest' => 4, 'stance' => 'supporter', 'keep_informed' => 'Weekly project meeting']);
$member('prj_office', 'pm_o_mj', ['analyst' => 'mjones'], 'role_member', 4, ['power' => 2, 'interest' => 3, 'stance' => 'neutral', 'keep_informed' => 'Teams channel']);
$member('prj_office', 'pm_o_sd', ['team' => 'servicedesk'], 'role_member', 5, ['power' => 2, 'interest' => 5, 'stance' => 'supporter', 'keep_informed' => 'Teams channel and the move-day runbook']);
$member('prj_office', 'pm_o_alice', ['user' => 'u_alice', 'notes' => 'Facilities manager'], 'role_su', 6, ['power' => 4, 'interest' => 5, 'stance' => 'supporter', 'keep_informed' => 'Fortnightly call; every highlight report']);
$member('prj_office', 'pm_o_bob', ['user' => 'u_bob', 'notes' => 'Finance director'], 'role_stake', 7, ['power' => 5, 'interest' => 2, 'stance' => 'sceptic', 'keep_informed' => 'Budget summary each month - keep it short']);
$member('prj_office', 'pm_o_claire', ['user' => 'u_claire', 'notes' => 'Office staff representative'], 'role_stake', 8, ['power' => 1, 'interest' => 5, 'stance' => 'neutral', 'keep_informed' => 'Staff newsletter']);
$item('prj_office', 'pi_o_network', 'Network live on every floor', 'must', 'in_progress', 1, 'ps_office_network', 'Every desk patched and tested; Wi-Fi survey passed on all floors.');
$item('prj_office', 'pi_o_phones', 'Phones working on day one', 'must', 'proposed', 2, 'ps_office_network', 'Every direct dial rings in Bradford on the Monday.');
$item('prj_office', 'pi_o_desks', 'Every desk tested before Monday', 'must', 'proposed', 3, 'ps_office_move');
$item('prj_office', 'pi_o_screens', 'Meeting room screens', 'should', 'proposed', 4, 'ps_office_network');
$item('prj_office', 'pi_o_signage', 'Digital signage in reception', 'could', 'proposed', 5);
$item('prj_office', 'pi_o_phonesys', 'Replacing the phone system', 'wont', 'dropped', 6, null, 'A project of its own - see the phone system replacement.');
foreach ([['pi_o_network', 'pm_o_admin', 'A'], ['pi_o_network', 'pm_o_lb', 'R'], ['pi_o_network', 'pm_o_sw', 'C'], ['pi_o_phones', 'pm_o_sw', 'A'], ['pi_o_phones', 'pm_o_mj', 'R'],
          ['pi_o_desks', 'pm_o_sw', 'A'], ['pi_o_desks', 'pm_o_sd', 'R'], ['pi_o_screens', 'pm_o_lb', 'R'], ['pi_o_screens', 'pm_o_sw', 'A']] as [$i, $m, $l]) $raci('prj_office', $i, $m, $l);
foreach ([['pi_o_network', 'pm_o_alice', 'C'], ['pi_o_desks', 'pm_o_alice', 'I'], ['pi_o_desks', 'pm_o_claire', 'I'], ['pi_o_phones', 'pm_o_bob', 'I']] as [$i, $m, $l]) $raci('prj_office', $i, $m, $l, true);
$raid('prj_office', ['type' => 'risk', 'title' => 'Switch delivery slips past the cabling date', 'p' => 3, 'i' => 4, 'response' => 'reduce', 'plan' => 'Order from a second distributor if not shipped by Friday', 'owner' => 'lbrown', 'by' => 'swilliams', 'raised' => -20]);
$raid('prj_office', ['type' => 'risk', 'title' => 'Landlord access restricted at weekends', 'p' => 3, 'i' => 3, 'response' => 'reduce', 'plan' => 'Agree weekend access in writing two weeks before move day', 'owner' => 'swilliams', 'raised' => -30]);
$raid('prj_office', ['type' => 'risk', 'title' => 'Phone number porting fails on the day', 'p' => 2, 'i' => 5, 'response' => 'transfer', 'plan' => 'Keep the Leeds lines live for a week as a fallback', 'owner' => 'jsmith', 'raised' => -25]);
$raid('prj_office', ['ref' => 'pr_o_internet', 'type' => 'issue', 'title' => 'The internet line install date has slipped', 'i' => 4, 'description' => 'The carrier moved the install from last Tuesday to an unconfirmed date. Nothing on the new floor can be tested end to end without it.', 'owner' => 'lbrown', 'raised' => -6, 'escalated' => -3, 'escalation_note' => 'Needs the director to call the carrier\'s account manager - we have been bumped twice.']);
$raid('prj_office', ['type' => 'assumption', 'title' => 'The landlord finishes the fit-out on time', 'owner' => 'swilliams', 'raised' => -40]);
$raid('prj_office', ['type' => 'dependency', 'title' => 'Carrier installs the internet line', 'description' => 'Everything on move weekend depends on it.', 'owner' => 'lbrown', 'due' => +4, 'raised' => -30]);
$raid('prj_office', ['type' => 'decision', 'title' => 'Wi-Fi 6E access points, not Wi-Fi 6', 'decided_by' => 'IT steering group', 'decided' => -22, 'rationale' => 'Only 8% more and good for ten years; the old kit was nine years old.', 'status' => 'closed', 'closed' => -22, 'raised' => -23]);
$raid('prj_office', ['type' => 'lesson', 'title' => 'Book the carrier survey the day the lease is signed', 'description' => 'Their lead time was nine weeks, not the four we assumed.', 'raised' => -5]);
$add('tier4', 'project_raid_tasks', ['raid_id' => '@project_raid.pr_o_internet', 'task_id' => '@tasks.po_internet', 'created_datetime' => $DT(-3)]);
$tol('prj_office', 'time', 7); $tol('prj_office', 'risk', 15); $tol('prj_office', 'cost', 10);
$line('prj_office', 'Network cabling', 'services', 18000, null, +5, null, 17250, 1, 'Paid on completion - the final invoice is due.');
$line('prj_office', 'Switches and Wi-Fi access points', 'hardware', 12000, 13000, -15, -14, null, 2, 'Wi-Fi 6E decision added £1,000.');
$line('prj_office', 'Design consultancy', 'services', 4000, 4000, -25, -24, null, 7);
$line('prj_office', 'Removals firm', 'services', 6500, null, +32, null, 6800, 3);
$line('prj_office', 'Phone number porting', 'services', 900, null, +12, null, null, 4);
$line('prj_office', 'Meeting room screens', 'hardware', 7200, null, +18, null, null, 5);
$line('prj_office', 'Project labour - month 1', 'labour', 3000, 3200, -30, -30, null, 6, 'Our own time, at the project rate.');
$line('prj_office', 'Project labour - month 2', 'labour', 3000, 3400, -2, -2, null, 8);
$line('prj_office', 'Project labour - month 3', 'labour', 3000, null, +28, null, 3200, 9);
$add('tier3', 'project_labour_rates', ['scope' => 'project', 'ref_id' => '@projects.prj_office', 'hourly_rate' => 45, 'effective_from' => $D(-60), 'created_by_id' => $A('admin'), 'created_datetime' => $DT(-45)]);
$benefit('prj_office', 'pb_o_desks', ['title' => 'Every desk working on day one', 'measure' => 'Desks working on the first Monday', 'unit' => '%', 'direction' => 'up', 'target' => 100, 'by' => +40, 'owner' => 'swilliams', 'review' => +40, 'months' => 0, 'pos' => 1]);
$benefit('prj_office', 'pb_o_cost', ['title' => 'Lower running cost', 'measure' => 'Rent and service charge a month', 'unit' => '£', 'direction' => 'down', 'baseline' => 21500, 'target' => 16000, 'by' => +180, 'owner' => 'admin', 'review' => +90, 'months' => 3, 'pos' => 2]);
$benefit('prj_office', 'pb_o_network', ['title' => 'Fewer network problems', 'measure' => 'Network tickets from the office a month', 'unit' => 'tickets', 'direction' => 'down', 'baseline' => 18, 'target' => 5, 'by' => +120, 'owner' => 'lbrown', 'review' => +90, 'months' => 3, 'pos' => 3]);
$gateItem('prj_office', 'ps_office_design', 'signoff', 'Every manager agrees the desk plan', 1, 'admin', -19);
$gateItem('prj_office', 'ps_office_design', 'check', 'Network kit ordered', 2, null, -19);
$gateItem('prj_office', 'ps_office_network', 'check', 'Every floor cabled and tested', 1);
$gateItem('prj_office', 'ps_office_network', 'signoff', 'Network lead happy with the build', 2, 'lbrown');
$gateItem('prj_office', 'ps_office_move', 'signoff', 'User acceptance testing signed off', 1, 'swilliams');
$gateItem('prj_office', 'ps_office_move', 'document', 'Backout plan', 2);
$gateItem('prj_office', 'ps_office_move', 'change', 'Change approved', 3);
$gateItem('prj_office', 'ps_office_move', 'check', 'Support handover agreed', 4);
$gateItem('prj_office', 'ps_office_move', 'check', 'Fallback agreed if the internet line is not live', 5);
$cr('prj_office', 'pc_o_wifi', 1, ['title' => 'Wi-Fi 6E instead of Wi-Fi 6', 'reason' => 'Ten-year life for the new office; the decision log has the detail.', 'cost' => 1000, 'days' => 0, 'scope' => 'Access points and licences', 'status' => 'approved', 'by' => 'lbrown', 'raised' => -24, 'decided' => -22, 'notes' => 'Approved by the steering group.']);
$cr('prj_office', 'pc_o_screens', 2, ['title' => 'Add screens to the two small meeting rooms', 'reason' => 'Staff asked for it in the consultation.', 'cost' => 2400, 'days' => 3, 'scope' => 'Two more screens and their cabling', 'status' => 'proposed', 'by' => 'swilliams', 'raised' => -4]);
$baseline('prj_office', 1, 'Plan agreed', 'The project went active', -45, ['target_shift' => 0, 'budget_delta' => -1000, 'stage_shift' => 0]);
$baseline('prj_office', 2, 'CR-1 approved', 'Wi-Fi 6E', -22, ['target_shift' => 0, 'budget_delta' => 0], 'pc_o_wifi');
$report('prj_office', 'highlight', 'Highlight report - month 1', "## Where the project stands\nOn track overall. Survey and design closed with a **go**; the network build has started.\n\n## Done this month\n- Desk plan signed off by every manager\n- Cabling and switches ordered\n\n## Next\n- Cabling on every floor\n- Switches racked\n\n## Risks and issues\n- **Switch delivery** could slip past the cabling date - second distributor lined up\n\n## Budget\n£23,600 spent of £57,600; the cabling invoice is still to come.", 'approved', 'swilliams', -18, ['approver' => 'admin', 'sent' => -16, 'sent_to' => 'alice.johnson@example.com,bob.taylor@example.com']);
$report('prj_office', 'checkpoint', 'Checkpoint - network build week 2', "## Done\n- Cabling on floors 1 and 2\n\n## In progress\n- Cabling on floor 3\n- Switches being configured\n\n## Next\n- Wi-Fi survey\n\n## Blockers\n- **Internet line** install date slipped - escalated (Lisa)", 'draft', 'swilliams', -3);
$link('project_changes', 'change_id', 'prj_office', '@changes.ch_switch'); $link('project_changes', 'change_id', 'prj_office', '@changes.ch_wifi');
$link('project_tickets', 'ticket_id', 'prj_office', '@tickets.tk_wifi'); $link('project_contracts', 'contract_id', 'prj_office', '@contracts.ct_network');
$link('project_knowledge_articles', 'article_id', 'prj_office', '@knowledge_articles.ka_wifi');
$audit('prj_office', 'swilliams', 'status', 'proposed', 'active', -45); $audit('prj_office', 'admin', 'baseline_taken', null, 'Baseline 1: Plan agreed', -45);
$audit('prj_office', 'lbrown', 'raid_escalated', null, 'The internet line install date has slipped', -3);

// =====================================================================================================
// 2. Windows 11 laptop refresh - agile, active, sprints with estimates (burndown), asset target.
// =====================================================================================================
$project(['ref' => 'prj_win11', 'name' => 'Windows 11 laptop refresh', 'method' => 'agile', 'status' => 'active', 'priority' => 'critical', 'owner' => 'jsmith',
    'goal' => 'Every laptop on Windows 11 before Windows 10 support ends', 'summary' => 'Two-week sprints, one department at a time. The standard image and the IT pilot are done.',
    'start' => -32, 'target' => +26, 'colour' => 'sky', 'icon' => 'laptop', 'tailoring' => ['budget' => true, 'benefits' => true]]);
$stage('prj_win11', 'agile', 'ps_w_s1', 'Sprint 1', 'Standard image built and tested', 1, -32, -19, 'closed');
$stage('prj_win11', 'agile', 'ps_w_s2', 'Sprint 2', 'IT and Finance upgraded', 2, -18, -5, 'closed');
$stage('prj_win11', 'agile', 'ps_w_s3', 'Sprint 3', 'Sales and Marketing upgraded', 3, -4, +9, 'active');
$stage('prj_win11', 'agile', 'ps_w_s4', 'Sprint 4', 'Operations upgraded; stragglers chased', 4, +10, +26, 'planned');
foreach ([
    ['pw_inventory', 'List laptops that cannot run Windows 11', 'ps_w_s1', -31, -27, -27, 'jsmith', 4],
    ['pw_apps', 'Test the line-of-business apps', 'ps_w_s1', -30, -22, -21, 'mjones', 12],
    ['pw_image', 'Build the standard Windows 11 image', 'ps_w_s1', -26, -20, -20, 'jsmith', 16],
    ['pw_pilot', 'Pilot with the IT team', 'ps_w_s1', -19, -19, -19, 'jsmith', 6],
    ['pw_it', 'Upgrade IT (12 laptops)', 'ps_w_s2', -17, -14, -14, 'jsmith', 8],
    ['pw_finance', 'Upgrade Finance (22 laptops)', 'ps_w_s2', -13, -6, -5, 'mjones', 14],
    ['pw_comms', 'Tell Sales and Marketing what to expect', 'ps_w_s2', -10, -6, -7, 'jsmith', 2],
    ['pw_sales', 'Upgrade Sales (30 laptops)', 'ps_w_s3', -3, +5, null, 'jsmith', 18],
    ['pw_marketing', 'Upgrade Marketing (14 laptops)', 'ps_w_s3', -1, +8, null, 'mjones', 10],
    ['pw_replace', 'Order replacements for the 9 laptops that cannot upgrade', 'ps_w_s3', -4, -1, null, 'lbrown', 3, 'In Progress', 'High'],
    ['pw_ops', 'Upgrade Operations (25 laptops)', 'ps_w_s4', +10, +20, null, 'jsmith', 16],
    ['pw_chase', 'Chase laptops that have not checked in', 'ps_w_s4', +21, +24, null, 'mjones', 4],
    ['pw_policy', 'Remove the old upgrade policy', 'ps_w_s4', +25, +26, null, 'jsmith', 1],
] as $t) $task('prj_win11', $t);
$dep('pw_image', 'pw_inventory'); $dep('pw_pilot', 'pw_image'); $dep('pw_pilot', 'pw_apps'); $dep('pw_it', 'pw_pilot'); $dep('pw_finance', 'pw_it');
$dep('pw_sales', 'pw_comms'); $dep('pw_ops', 'pw_sales'); $dep('pw_chase', 'pw_ops'); $dep('pw_policy', 'pw_chase');
$milestone('prj_win11', 'ps_w_s1', 'Standard image signed off', -20, -20, 1);
$milestone('prj_win11', 'ps_w_s2', 'Finance on Windows 11', -8, -5, 2);
$milestone('prj_win11', 'ps_w_s3', 'Half the estate upgraded', +9, null, 3);
$milestone('prj_win11', 'ps_w_s4', 'Every laptop upgraded', +24, null, 4);
$member('prj_win11', 'pm_w_js', ['analyst' => 'jsmith'], 'role_pm', 1, ['power' => 4, 'interest' => 5, 'stance' => 'champion']);
$member('prj_win11', 'pm_w_mj', ['analyst' => 'mjones'], 'role_member', 2);
$member('prj_win11', 'pm_w_lb', ['analyst' => 'lbrown'], 'role_member', 3);
$member('prj_win11', 'pm_w_sd', ['team' => 'servicedesk'], 'role_stake', 4, ['power' => 2, 'interest' => 4, 'stance' => 'supporter', 'keep_informed' => 'Sprint review every other Friday']);
$raid('prj_win11', ['type' => 'risk', 'title' => 'An old finance app does not run on Windows 11', 'p' => 2, 'i' => 4, 'response' => 'reduce', 'plan' => 'Tested in sprint 1 - a newer version works; keep one Windows 10 VM until year end', 'owner' => 'mjones', 'raised' => -30]);
$raid('prj_win11', ['type' => 'risk', 'title' => 'Replacement laptops arrive late', 'p' => 3, 'i' => 3, 'response' => 'reduce', 'plan' => 'Order this week; loan laptops from the spares pool meanwhile', 'owner' => 'lbrown', 'raised' => -4]);
$raid('prj_win11', ['type' => 'decision', 'title' => 'Upgrade in place, do not reimage', 'decided_by' => 'John Smith', 'decided' => -21, 'rationale' => 'The pilot showed in-place upgrades keep user settings and take 40 minutes, not two hours.', 'status' => 'closed', 'closed' => -21, 'raised' => -22]);
$line('prj_win11', 'Replacement laptops (9)', 'hardware', 8100, null, +6, null, 8550, 1);
$line('prj_win11', 'Upgrade licences', 'software', 0, 0, -30, -30, null, 2, 'Included in the existing agreement.');
$benefit('prj_win11', 'pb_w_support', ['title' => 'Every laptop on a supported Windows', 'measure' => 'Laptops on Windows 11', 'unit' => '%', 'direction' => 'up', 'baseline' => 0, 'target' => 100, 'by' => +26, 'owner' => 'jsmith', 'review' => +30, 'months' => 0,
    'measures' => [[-19, 12, 'IT done'], [-5, 41, 'Finance done']]]);
$add('tier3', 'project_asset_targets', ['project_id' => '@projects.prj_win11', 'name' => 'Laptops on Windows 11', 'scope' => 'filter', 'scope_field' => 'operating_system', 'scope_value' => 'Windows',
    'done_field' => 'operating_system', 'done_op' => 'contains', 'done_value' => 'Windows 11', 'target_date' => $D(+26), 'position' => 1, 'created_by_analyst_id' => $A('jsmith'), 'created_datetime' => $DT(-30), 'updated_datetime' => $DT(-30)]);
foreach (['as_lt001', 'as_lt002', 'as_lt003', 'as_lt004', 'as_lt005', 'as_lt006'] as $as) $link('project_assets', 'asset_id', 'prj_win11', '@assets.' . $as);
$link('project_tickets', 'ticket_id', 'prj_win11', '@tickets.tk_laptop'); $link('project_knowledge_articles', 'article_id', 'prj_win11', '@knowledge_articles.ka_hardware');
$report('prj_win11', 'checkpoint', 'Sprint 2 review', "## Done\n- **IT** and **Finance** upgraded - 34 laptops\n- Sales and Marketing told what to expect\n\n## In progress\n- Sprint 3: Sales and Marketing\n\n## Blockers\n- 9 laptops cannot run Windows 11 - replacements being ordered", 'approved', 'jsmith', -4, ['approver' => 'jsmith']);

// =====================================================================================================
// 3. Firewall replacement - simple, proposed, WAITING FOR APPROVAL (intake).
// =====================================================================================================
$project(['ref' => 'prj_firewall', 'name' => 'Firewall replacement', 'method' => 'simple', 'status' => 'proposed', 'priority' => 'high', 'owner' => 'mjones',
    'goal' => 'Both sites behind supported firewalls before the current pair goes end of life', 'summary' => 'The current firewalls go end of support in eight months. Proposed from the change advisory board.',
    'start' => +14, 'target' => +100, 'colour' => 'pink', 'icon' => 'shield', 'estimated_cost' => 38000, 'estimated_benefit' => 'Removes an unsupported security device from both sites and cuts the support contract by £4,000 a year.',
    'approval_status' => 'pending', 'proposed_by_name' => 'Emma Thomas', 'proposed_by_email' => 'emma.thomas@example.com',
    'case' => "The firewall pair at both sites goes end of support in eight months - no more security updates.\n\nOptions: replace like for like (£38k), move to a cloud firewall service (£22k a year), or do nothing (not acceptable).\nRecommendation: like-for-like replacement, which the network team already knows."]);
$stage('prj_firewall', 'simple', 'ps_f_plan', 'Plan and order', 'The new pair chosen and ordered', 1, +14, +40, 'planned');
$stage('prj_firewall', 'simple', 'ps_f_build', 'Build and migrate', 'Rules moved and both sites cut over', 2, +41, +100, 'planned');
foreach ([['pf_rules', 'Export and review the current rule base', 'ps_f_plan', +14, +24, null, 'mjones', 12], ['pf_quote', 'Get three quotes', 'ps_f_plan', +14, +28, null, 'lbrown', 4],
          ['pf_order', 'Order the new pair', 'ps_f_plan', +30, +40, null, 'lbrown', 2], ['pf_leeds', 'Cut over Leeds', 'ps_f_build', +60, +70, null, 'mjones', 16],
          ['pf_bradford', 'Cut over Bradford', 'ps_f_build', +80, +90, null, 'mjones', 16]] as $t) $task('prj_firewall', $t);
$dep('pf_order', 'pf_quote'); $dep('pf_leeds', 'pf_order'); $dep('pf_leeds', 'pf_rules'); $dep('pf_bradford', 'pf_leeds', 7);
$raid('prj_firewall', ['type' => 'risk', 'title' => 'The old firewalls fail before they are replaced', 'p' => 2, 'i' => 5, 'response' => 'accept', 'plan' => 'Spare unit on the shelf; support contract until end of life', 'owner' => 'mjones', 'raised' => -3]);

// =====================================================================================================
// 4. Mail migration to the cloud - staged, active, IN TROUBLE: overdue work, missed milestone,
//    escalated issue, a breached time tolerance, an approved CR that moved the date and a pending one.
// =====================================================================================================
$project(['ref' => 'prj_mail', 'name' => 'Mail migration to the cloud', 'method' => 'staged', 'status' => 'active', 'priority' => 'high', 'owner' => 'lbrown',
    'goal' => 'Every mailbox in the cloud with no lost mail and no long outage', 'summary' => 'Batches of 50 mailboxes a week. Batch 2 hit throttling and the plan moved by two weeks.',
    'start' => -55, 'target' => +18, 'colour' => 'violet', 'icon' => 'mail', 'creator' => 'lbrown']);
$stage('prj_mail', 'staged', 'ps_m_prep', 'Prepare', 'The cloud tenant ready and the batches planned', 1, -55, -41, 'closed', ['decision' => 'go_with_conditions', 'notes' => 'Go, on condition the shared mailbox inventory is finished in the pilot stage.', 'by' => 'admin']);
$stage('prj_mail', 'staged', 'ps_m_pilot', 'Pilot batch', 'IT moved first, problems found early', 2, -40, -27, 'closed', ['decision' => 'go', 'notes' => 'Pilot clean apart from mobile re-sync, now documented.', 'by' => 'admin']);
$stage('prj_mail', 'staged', 'ps_m_migrate', 'Migrate', 'Every remaining batch moved', 3, -26, +2, 'active');
$stage('prj_mail', 'staged', 'ps_m_cutover', 'Cut over', 'Mail flowing straight to the cloud; the old server off', 4, +3, +18, 'planned', ['kind' => 'golive']);
foreach ([
    ['pm_licences', 'Check licences for every user', 'ps_m_prep', -54, -50, -50, 'lbrown', 3],
    ['pm_domain', 'Verify the mail domain in the cloud tenant', 'ps_m_prep', -52, -48, -47, 'mjones', 2],
    ['pm_batches', 'Plan the migration batches', 'ps_m_prep', -49, -42, -42, 'lbrown', 6],
    ['pm_pilot', 'Migrate the IT team', 'ps_m_pilot', -38, -33, -33, 'mjones', 8],
    ['pm_mobile', 'Test phones, shared mailboxes and calendars', 'ps_m_pilot', -32, -28, -27, 'jsmith', 6],
    ['pm_b1', 'Batch 1 - Finance and HR', 'ps_m_migrate', -25, -19, -18, 'mjones', 10],
    ['pm_b2', 'Batch 2 - Sales', 'ps_m_migrate', -18, -10, null, 'mjones', 10, 'In Progress', 'High'],
    ['pm_b3', 'Batch 3 - Operations', 'ps_m_migrate', -9, -2, null, 'mjones', 10, 'Blocked', 'High'],
    ['pm_shared', 'Shared mailboxes and groups', 'ps_m_migrate', -5, +1, null, 'jsmith', 8],
    ['pm_mx', 'Switch the MX records', 'ps_m_cutover', +5, +6, null, 'lbrown', 2, null, 'High'],
    ['pm_watch', 'Watch mail flow for a week', 'ps_m_cutover', +7, +13, null, 'jsmith', 6],
    ['pm_off', 'Turn off the old mail server', 'ps_m_cutover', +15, +18, null, 'mjones', 3],
] as $t) $task('prj_mail', $t);
$dep('pm_batches', 'pm_licences'); $dep('pm_pilot', 'pm_domain'); $dep('pm_pilot', 'pm_batches'); $dep('pm_mobile', 'pm_pilot'); $dep('pm_b1', 'pm_mobile');
$dep('pm_b2', 'pm_b1'); $dep('pm_b3', 'pm_b2'); $dep('pm_shared', 'pm_b1'); $dep('pm_mx', 'pm_b3'); $dep('pm_mx', 'pm_shared'); $dep('pm_watch', 'pm_mx'); $dep('pm_off', 'pm_watch');
$milestone('prj_mail', 'ps_m_pilot', 'Pilot batch moved', -33, -33, 1);
$milestone('prj_mail', 'ps_m_migrate', 'Half the mailboxes moved', -12, null, 2);   // missed
$milestone('prj_mail', 'ps_m_cutover', 'Mail records switched', +6, null, 3);
$member('prj_mail', 'pm_m_admin', ['analyst' => 'admin'], 'role_exec', 1, ['power' => 5, 'interest' => 3, 'stance' => 'supporter', 'keep_informed' => 'Exception report when a tolerance is breached']);
$member('prj_mail', 'pm_m_lb', ['analyst' => 'lbrown'], 'role_pm', 2, ['power' => 4, 'interest' => 5, 'stance' => 'champion']);
$member('prj_mail', 'pm_m_mj', ['analyst' => 'mjones'], 'role_tm', 3);
$member('prj_mail', 'pm_m_js', ['analyst' => 'jsmith'], 'role_member', 4);
$member('prj_mail', 'pm_m_david', ['user' => 'u_david', 'notes' => 'Sales director'], 'role_stake', 5, ['power' => 4, 'interest' => 4, 'stance' => 'blocker', 'keep_informed' => 'A call before his team\'s batch - he wants no disruption at quarter end']);
$item('prj_mail', 'pi_m_history', 'All mail history moved', 'must', 'in_progress', 1);
$item('prj_mail', 'pi_m_shared', 'Shared mailboxes working', 'must', 'in_progress', 2);
$item('prj_mail', 'pi_m_mobile', 'Mobile devices set up again', 'should', 'proposed', 3);
$item('prj_mail', 'pi_m_archive', 'Archive old public folders', 'could', 'proposed', 4);
$raci('prj_mail', 'pi_m_history', 'pm_m_lb', 'A'); $raci('prj_mail', 'pi_m_history', 'pm_m_mj', 'R'); $raci('prj_mail', 'pi_m_shared', 'pm_m_js', 'R'); $raci('prj_mail', 'pi_m_shared', 'pm_m_lb', 'A');
$raci('prj_mail', 'pi_m_history', 'pm_m_david', 'I', true);
$raid('prj_mail', ['ref' => 'pr_m_throttle', 'type' => 'issue', 'title' => 'The cloud service is throttling batch 2', 'i' => 4, 'description' => 'Copies run at a third of the expected speed. Sales mailboxes are the largest.', 'owner' => 'mjones', 'raised' => -12, 'escalated' => -8, 'escalation_note' => 'Need a support case raised at premier level with the cloud provider.']);
$raid('prj_mail', ['type' => 'risk', 'title' => 'Mail is lost during the MX switch', 'p' => 2, 'i' => 5, 'response' => 'reduce', 'plan' => 'Lower the DNS time-to-live two days before', 'owner' => 'lbrown', 'raised' => -45]);
$raid('prj_mail', ['type' => 'risk', 'title' => 'Sales push back on a mid-quarter migration', 'p' => 4, 'i' => 3, 'response' => 'reduce', 'plan' => 'Move Sales over a weekend; agree it with the sales director', 'owner' => 'lbrown', 'raised' => -15]);
$raid('prj_mail', ['type' => 'dependency', 'title' => 'Premier support case resolved by the cloud provider', 'owner' => 'mjones', 'due' => -2, 'raised' => -8]);   // late
$raid('prj_mail', ['type' => 'decision', 'title' => 'Pre-stage the largest mailboxes a week early', 'decided_by' => 'Lisa Brown', 'decided' => -10, 'rationale' => 'Halves the cut-over window for the biggest mailboxes.', 'status' => 'closed', 'closed' => -10, 'raised' => -11]);
$add('tier4', 'project_raid_tasks', ['raid_id' => '@project_raid.pr_m_throttle', 'task_id' => '@tasks.pm_b2', 'created_datetime' => $DT(-8)]);
$tol('prj_mail', 'time', 5); $tol('prj_mail', 'risk', 12); $tol('prj_mail', 'cost', 10);
$line('prj_mail', 'Migration tool licences', 'software', 3500, 3500, -50, -48, null, 1);
$line('prj_mail', 'Cloud mailbox licences - quarter 1', 'software', 7000, 7000, -30, -30, null, 2);
$line('prj_mail', 'Cloud mailbox licences - quarter 2', 'software', 7000, 7000, -1, -1, null, 5);
$line('prj_mail', 'Cloud mailbox licences - quarter 3', 'software', 7000, null, +28, null, null, 6);
$line('prj_mail', 'Consultant days for throttling', 'services', 0, 2400, -8, -6, 4800, 3, 'Not planned - added by CR-1.');
$line('prj_mail', 'Project labour - month 1', 'labour', 3000, 3100, -40, -40, null, 4);
$line('prj_mail', 'Project labour - month 2', 'labour', 3000, 3700, -10, -10, null, 7);
$line('prj_mail', 'Project labour - month 3', 'labour', 3000, null, +15, null, 4400, 8);
$benefit('prj_mail', 'pb_m_outage', ['title' => 'Fewer mail outages', 'measure' => 'Hours of mail outage a quarter', 'unit' => 'hours', 'direction' => 'down', 'baseline' => 14, 'target' => 1, 'by' => +110, 'owner' => 'lbrown', 'review' => +100, 'months' => 3]);
$gateItem('prj_mail', 'ps_m_cutover', 'signoff', 'Service desk lead happy with mail flow', 1, 'jsmith');
$gateItem('prj_mail', 'ps_m_cutover', 'document', 'Rollback plan for the MX switch', 2);
$gateItem('prj_mail', 'ps_m_cutover', 'change', 'Change for the MX switch approved', 3);
$gateItem('prj_mail', 'ps_m_cutover', 'check', 'Old server backed up', 4);
$cr('prj_mail', 'pc_m_throttle', 1, ['title' => 'Two more weeks for throttled batches', 'reason' => 'Batch 2 runs at a third of the expected speed.', 'days' => 14, 'cost' => 4800, 'scope' => 'No change', 'status' => 'approved', 'by' => 'lbrown', 'raised' => -11, 'decided' => -9, 'notes' => 'Approved - target moves two weeks, consultant days added.']);
$cr('prj_mail', 'pc_m_archive', 2, ['title' => 'Drop the public folder archive', 'reason' => 'Nobody has opened them in three years.', 'days' => -2, 'cost' => -800, 'scope' => 'Removes "Archive old public folders" (could have)', 'status' => 'proposed', 'by' => 'mjones', 'raised' => -4]);
$cr('prj_mail', 'pc_m_weekend', 3, ['title' => 'Migrate Sales over a bank holiday weekend', 'reason' => 'Sales director will not accept weekday disruption.', 'days' => 0, 'cost' => 1200, 'status' => 'rejected', 'by' => 'lbrown', 'raised' => -14, 'decided' => -13, 'notes' => 'Rejected - overtime cost too high; an ordinary weekend will do.']);
$baseline('prj_mail', 1, 'Plan agreed', 'The project went active', -55, ['target_shift' => -14, 'budget_delta' => -2400, 'stage_shift' => -14]);
$baseline('prj_mail', 2, 'CR-1 approved', 'Two more weeks for throttled batches', -9, ['target_shift' => 0, 'budget_delta' => 0], 'pc_m_throttle');
$report('prj_mail', 'exception', 'Exception report - time tolerance', "## The situation\nBatch 2 is running at a third of the expected speed because the cloud service is throttling it. The **time tolerance (5 days)** will be exceeded.\n\n## The cause\nThe largest mailboxes are in Sales; throttling limits are per tenant.\n\n## If nothing changes\nCut-over slips about three weeks.\n\n## Options\n- Wait it out (3 weeks late)\n- Pre-stage large mailboxes and add consultant days (2 weeks late, £4,800)\n- Split Sales across two batches (2.5 weeks late)\n\n## Recommendation\nOption 2 - raised as CR-1.", 'approved', 'lbrown', -10, ['approver' => 'admin', 'ai' => 1, 'edited' => 1, 'model' => 'demo']);
$link('project_changes', 'change_id', 'prj_mail', '@changes.ch_exchange'); $link('project_tickets', 'ticket_id', 'prj_mail', '@tickets.tk_mail');
$link('project_knowledge_articles', 'article_id', 'prj_mail', '@knowledge_articles.ka_mail'); $link('project_knowledge_articles', 'article_id', 'prj_mail', '@knowledge_articles.ka_mobile');
$link('project_contracts', 'contract_id', 'prj_mail', '@contracts.ct_cloud');
$audit('prj_mail', 'mjones', 'raid_escalated', null, 'The cloud service is throttling batch 2', -8); $audit('prj_mail', 'admin', 'baseline_taken', null, 'Baseline 2: CR-1 approved', -9);


// =====================================================================================================
// 5. Backup platform replacement - staged, CLOSED: closure report, benefits measured and achieved.
// =====================================================================================================
$project(['ref' => 'prj_backup', 'name' => 'Backup platform replacement', 'method' => 'staged', 'status' => 'closed', 'priority' => 'medium', 'owner' => 'mjones',
    'goal' => 'Every server backed up nightly to the new platform, restores tested monthly', 'summary' => 'Replaced tape with disk-to-cloud backup. Closed on time and under budget.',
    'start' => -210, 'target' => -60, 'actual' => -62, 'colour' => 'teal', 'icon' => 'database']);
$stage('prj_backup', 'staged', 'ps_b_design', 'Design', 'The platform chosen', 1, -210, -170, 'closed', ['decision' => 'go', 'notes' => 'Disk-to-cloud chosen over a new tape library.']);
$stage('prj_backup', 'staged', 'ps_b_build', 'Build', 'Every server backed up to the new platform', 2, -169, -95, 'closed', ['decision' => 'go', 'notes' => 'All 42 servers protected; first restore test passed.']);
$stage('prj_backup', 'staged', 'ps_b_retire', 'Retire tape', 'The tape library gone', 3, -94, -60, 'closed', ['decision' => 'go', 'notes' => 'Project closed.']);
foreach ([['pb_choose', 'Choose the platform', 'ps_b_design', -205, -175, -176, 'mjones', 20], ['pb_install', 'Install the backup appliance', 'ps_b_build', -165, -150, -152, 'mjones', 16],
          ['pb_servers', 'Protect every server (42)', 'ps_b_build', -149, -110, -108, 'lbrown', 60], ['pb_restore', 'First full restore test', 'ps_b_build', -105, -98, -99, 'mjones', 8],
          ['pb_tapes', 'Send the old tapes for secure destruction', 'ps_b_retire', -90, -70, -68, 'lbrown', 4], ['pb_runbook', 'Write the restore runbook', 'ps_b_retire', -80, -65, -64, 'mjones', 6]] as $t) $task('prj_backup', $t);
$dep('pb_install', 'pb_choose'); $dep('pb_servers', 'pb_install'); $dep('pb_restore', 'pb_servers'); $dep('pb_tapes', 'pb_restore');
$milestone('prj_backup', 'ps_b_build', 'Every server protected', -110, -108, 1);
$milestone('prj_backup', 'ps_b_retire', 'Tape library removed', -65, -66, 2);
$member('prj_backup', 'pm_b_mj', ['analyst' => 'mjones'], 'role_pm', 1); $member('prj_backup', 'pm_b_lb', ['analyst' => 'lbrown'], 'role_member', 2);
$raid('prj_backup', ['type' => 'lesson', 'title' => 'Test a restore before retiring anything', 'description' => 'The first restore test found two servers with the wrong retention policy.', 'raised' => -98, 'status' => 'closed', 'closed' => -62]);
$raid('prj_backup', ['type' => 'lesson', 'title' => 'Seed the first cloud copy from a disk, not over the line', 'description' => 'Saved three weeks.', 'raised' => -140, 'status' => 'closed', 'closed' => -62]);
$raid('prj_backup', ['type' => 'risk', 'title' => 'The first cloud copy saturates the internet line', 'p' => 4, 'i' => 3, 'response' => 'avoid', 'plan' => 'Seed from a shipped disk', 'status' => 'closed', 'closed' => -130, 'owner' => 'mjones', 'raised' => -160]);
$line('prj_backup', 'Backup appliance', 'hardware', 16000, 15400, -160, -158, null, 1);
$line('prj_backup', 'Cloud storage (first year)', 'services', 7200, 6900, -150, -120, null, 2);
$line('prj_backup', 'Secure tape destruction', 'services', 600, 450, -70, -68, null, 3);
$benefit('prj_backup', 'pb_b_restore', ['title' => 'Faster restores', 'measure' => 'Hours to restore a file server', 'unit' => 'hours', 'direction' => 'down', 'baseline' => 18, 'target' => 2, 'by' => -50, 'owner' => 'mjones', 'review' => +30, 'months' => 3,
    'measures' => [[-99, 3.5, 'First test'], [-60, 1.5, 'Monthly test'], [-30, 1.2, 'Monthly test']]]);
$benefit('prj_backup', 'pb_b_success', ['title' => 'Backups that work', 'measure' => 'Nightly backups that succeed', 'unit' => '%', 'direction' => 'up', 'baseline' => 91, 'target' => 99, 'by' => -40, 'owner' => 'lbrown', 'review' => -2, 'months' => 1, 'pos' => 2,
    'measures' => [[-90, 97.5, ''], [-60, 99.1, ''], [-30, 99.4, '']]]);
$benefit('prj_backup', 'pb_b_cost', ['title' => 'Lower backup cost', 'measure' => 'Backup running cost a year', 'unit' => '£', 'direction' => 'down', 'baseline' => 14000, 'target' => 9000, 'by' => +120, 'owner' => 'admin', 'review' => +60, 'months' => 6, 'pos' => 3,
    'measures' => [[-30, 10200, 'Tape contract ended; cloud storage up a little']]]);
$report('prj_backup', 'closure', 'Closure report', "## What was delivered\nEvery server backed up nightly to disk and the cloud; the tape library is gone.\n\n## Against the plan\n- Finished **2 days early**\n- £22,750 spent of £23,800 - **£1,050 under**\n\n## Benefits\n- **Faster restores**: 18 hours to 1.2 - achieved\n- **Backups that work**: 91% to 99.4% - achieved\n- **Lower cost**: on the way - £10,200 against a target of £9,000; reviewed in six months (owner: Administrator)\n\n## Lessons\n- Test a restore before retiring anything\n- Seed the first cloud copy from a disk\n\n## Handover\nThe restore runbook is in Knowledge; monthly restore tests are a recurring task for the infrastructure team.", 'approved', 'mjones', -63, ['approver' => 'admin', 'sent' => -61, 'sent_to' => 'frank.roberts@example.com']);
$link('project_cmdb_objects', 'cmdb_object_id', 'prj_backup', '@cmdb_objects.ci_sql1'); $link('project_cmdb_objects', 'cmdb_object_id', 'prj_backup', '@cmdb_objects.ci_sql2');
$link('project_changes', 'change_id', 'prj_backup', '@changes.ch_files');
$audit('prj_backup', 'mjones', 'status', 'active', 'closed', -62);

// =====================================================================================================
// 6. Service desk improvement - agile, active, benefits reviewed monthly (one due now).
// =====================================================================================================
$project(['ref' => 'prj_sd', 'name' => 'Service desk improvement', 'method' => 'agile', 'status' => 'active', 'priority' => 'medium', 'owner' => 'admin',
    'goal' => 'Faster answers and fewer repeat tickets, one sprint at a time', 'summary' => 'An improvement backlog worked through in two-week sprints, measured monthly.',
    'start' => -28, 'target' => +28, 'colour' => 'lime', 'icon' => 'users', 'tailoring' => ['benefits' => true, 'raid' => true]]);
$stage('prj_sd', 'agile', 'ps_sd_1', 'Sprint 1', 'Quick wins', 1, -28, -15, 'closed');
$stage('prj_sd', 'agile', 'ps_sd_2', 'Sprint 2', 'Better triage', 2, -14, -1, 'closed');
$stage('prj_sd', 'agile', 'ps_sd_3', 'Sprint 3', 'Self-service', 3, 0, +13, 'active');
$stage('prj_sd', 'agile', 'ps_sd_4', 'Sprint 4', 'Measure and adjust', 4, +14, +28, 'planned');
foreach ([['psd_kb', 'Write the five most-asked answers as knowledge articles', 'ps_sd_1', -27, -16, -17, 'jsmith', 10],
          ['psd_templates', 'Add reply templates for common requests', 'ps_sd_1', -25, -16, -15, 'swilliams', 6],
          ['psd_categories', 'Tidy the ticket categories', 'ps_sd_2', -13, -5, -4, 'jsmith', 8],
          ['psd_routing', 'Set up auto-assignment rules', 'ps_sd_2', -4, -2, null, 'swilliams', 8, 'In Progress'],
          ['psd_forms', 'Publish the top request forms on the portal', 'ps_sd_3', 0, +10, null, 'jsmith', 12],
          ['psd_chat', 'Try a chat channel for an hour a day', 'ps_sd_3', +2, +12, null, 'swilliams', 6],
          ['psd_csat', 'Turn on satisfaction surveys', 'ps_sd_4', +14, +20, null, 'jsmith', 3],
          ['psd_review', 'Review the numbers and plan what is next', 'ps_sd_4', +24, +28, null, 'admin', 3]] as $t) $task('prj_sd', $t);
$dep('psd_routing', 'psd_categories'); $dep('psd_csat', 'psd_forms');
$member('prj_sd', 'pm_sd_admin', ['analyst' => 'admin'], 'role_pm', 1); $member('prj_sd', 'pm_sd_team', ['team' => 'servicedesk'], 'role_member', 2, ['power' => 3, 'interest' => 5, 'stance' => 'champion', 'keep_informed' => 'Sprint review in the team meeting']);
$member('prj_sd', 'pm_sd_karen', ['user' => 'u_karen', 'notes' => 'Head of customer experience'], 'role_su', 3, ['power' => 4, 'interest' => 4, 'stance' => 'supporter', 'keep_informed' => 'The monthly numbers']);
$benefit('prj_sd', 'pb_sd_first', ['title' => 'Faster first response', 'measure' => 'Average hours to first response', 'unit' => 'hours', 'direction' => 'down', 'baseline' => 6.5, 'target' => 2, 'by' => +60, 'owner' => 'admin', 'review' => -2, 'months' => 1,
    'measures' => [[-28, 6.5, 'Before the project'], [-14, 5.1, ''], [-1, 3.8, 'Auto-assignment half done']]]);
$benefit('prj_sd', 'pb_sd_repeat', ['title' => 'Fewer repeat tickets', 'measure' => 'Tickets reopened a month', 'unit' => 'tickets', 'direction' => 'down', 'baseline' => 42, 'target' => 20, 'by' => +60, 'owner' => 'jsmith', 'review' => +5, 'months' => 1, 'pos' => 2,
    'measures' => [[-28, 42, ''], [-1, 35, '']]]);
$raid('prj_sd', ['type' => 'risk', 'title' => 'The desk is too busy to try the chat channel', 'p' => 3, 'i' => 2, 'response' => 'accept', 'owner' => 'swilliams', 'raised' => -5]);

// =====================================================================================================
// 7. HR system access review - simple, active, MEMBERS-ONLY.
// =====================================================================================================
$project(['ref' => 'prj_hr', 'name' => 'Restructure - HR system access changes', 'method' => 'simple', 'status' => 'active', 'priority' => 'high', 'owner' => 'admin',
    'goal' => 'Access changed for every role in the new structure on the day it is announced', 'summary' => 'Confidential until the announcement. Members only.',
    'start' => -10, 'target' => +12, 'colour' => 'slate', 'icon' => 'shield', 'visibility' => 'members']);
$stage('prj_hr', 'simple', 'ps_hr_prep', 'Prepare', 'Every change scripted and checked', 1, -10, +4, 'active');
$stage('prj_hr', 'simple', 'ps_hr_day', 'Announcement day', 'Changes applied in one go', 2, +5, +12, 'planned');
foreach ([['phr_list', 'List every role change from HR', 'ps_hr_prep', -9, -5, -5, 'lbrown', 3], ['phr_script', 'Script the group changes', 'ps_hr_prep', -4, +2, null, 'lbrown', 8],
          ['phr_apply', 'Apply the changes after the announcement', 'ps_hr_day', +6, +6, null, 'lbrown', 4], ['phr_check', 'Check every changed account', 'ps_hr_day', +7, +8, null, 'admin', 3]] as $t) $task('prj_hr', $t);
$dep('phr_script', 'phr_list'); $dep('phr_apply', 'phr_script'); $dep('phr_check', 'phr_apply');
$member('prj_hr', 'pm_hr_admin', ['analyst' => 'admin'], 'role_pm', 1); $member('prj_hr', 'pm_hr_lb', ['analyst' => 'lbrown'], 'role_member', 2);
$member('prj_hr', 'pm_hr_grace', ['user' => 'u_grace', 'notes' => 'HR director'], 'role_exec', 3, ['power' => 5, 'interest' => 5, 'stance' => 'champion', 'keep_informed' => 'Daily on the day; nothing in writing before']);

// =====================================================================================================
// 8. Phone system replacement - staged, ON HOLD, red by hand, a rejected change request.
// =====================================================================================================
$project(['ref' => 'prj_phones', 'name' => 'Phone system replacement', 'method' => 'staged', 'status' => 'on_hold', 'priority' => 'low', 'owner' => 'swilliams',
    'goal' => 'Move every line to a cloud phone system', 'summary' => 'On hold until the office move is done - the same people are needed for both.',
    'start' => -70, 'target' => +90, 'colour' => 'amber', 'icon' => 'phone', 'health' => 'red', 'health_note' => 'Paused - waiting for the office move to finish']);
$stage('prj_phones', 'staged', 'ps_p_select', 'Select a supplier', 'A supplier chosen', 1, -70, -30, 'closed', ['decision' => 'go', 'notes' => 'Supplier chosen; contract not signed until the move is done.']);
$stage('prj_phones', 'staged', 'ps_p_port', 'Port the numbers', 'Every number on the new system', 2, -29, +60, 'active');
foreach ([['pp_rfp', 'Send the requirements to four suppliers', 'ps_p_select', -68, -55, -56, 'swilliams', 8], ['pp_choose', 'Score the bids and choose', 'ps_p_select', -50, -32, -31, 'swilliams', 10],
          ['pp_contract', 'Sign the contract', 'ps_p_port', -25, -10, null, 'admin', 2, 'Blocked'], ['pp_port', 'Port the first 50 numbers', 'ps_p_port', +40, +55, null, 'jsmith', 10]] as $t) $task('prj_phones', $t);
$dep('pp_choose', 'pp_rfp'); $dep('pp_port', 'pp_contract');
$member('prj_phones', 'pm_p_sw', ['analyst' => 'swilliams'], 'role_pm', 1);
$raid('prj_phones', ['type' => 'issue', 'title' => 'The same engineers are needed for the office move', 'i' => 3, 'owner' => 'admin', 'raised' => -20]);
$cr('prj_phones', 'pc_p_now', 1, ['title' => 'Start porting before the move', 'reason' => 'The supplier offers a discount for signing this month.', 'days' => -20, 'cost' => -1500, 'status' => 'rejected', 'by' => 'swilliams', 'raised' => -18, 'decided' => -15, 'notes' => 'Rejected - nobody free to do it until the move is done.']);
$tol('prj_phones', 'time', 10);
$line('prj_phones', 'Cloud phone licences (first year)', 'software', 11000, null, +50, null, null, 1);

// =====================================================================================================
// 9. Intranet refresh - simple, CANCELLED.
// =====================================================================================================
$project(['ref' => 'prj_intranet', 'name' => 'Intranet refresh', 'method' => 'simple', 'status' => 'cancelled', 'priority' => 'low', 'owner' => 'jsmith',
    'goal' => 'A new intranet the staff actually use', 'summary' => 'Cancelled - the company is moving to the collaboration suite\'s own intranet instead.',
    'start' => -120, 'target' => -20, 'actual' => -80, 'colour' => 'indigo', 'icon' => 'cloud']);
$stage('prj_intranet', 'simple', 'ps_i_discover', 'Discover', 'What staff want from it', 1, -120, -90, 'closed');
foreach ([['pi_survey', 'Survey staff on the current intranet', 'ps_i_discover', -118, -100, -101, 'jsmith', 6], ['pi_options', 'Compare three intranet products', 'ps_i_discover', -100, -85, null, 'jsmith', 8, 'Cancelled']] as $t) $task('prj_intranet', $t);
$raid('prj_intranet', ['type' => 'decision', 'title' => 'Stop the project; use the collaboration suite\'s intranet', 'decided_by' => 'IT steering group', 'decided' => -80, 'rationale' => 'Licensed already; one less system to run.', 'status' => 'closed', 'closed' => -80, 'raised' => -82]);
$raid('prj_intranet', ['type' => 'lesson', 'title' => 'Check what existing licences include before starting', 'raised' => -80, 'status' => 'closed', 'closed' => -80]);
$audit('prj_intranet', 'jsmith', 'status', 'active', 'cancelled', -80);

// ---- Clash check: a task must start after what it waits for is due (+ lag). One clash is on purpose.
$byRef = [];
foreach ($T['tier2']['tasks'] as $t) $byRef[$t['_ref']] = $t;
$num = fn($s) => (int)preg_replace('/^__RELATIVE_DATEONLY:([+-]?\d+)d__$/', '$1', $s);
foreach ($T['tier3']['task_dependencies'] as $dp) {
    $w = $byRef[substr($dp['task_id'], 7)]; $o = $byRef[substr($dp['depends_on_id'], 7)];
    if ($num($w['start_date']) <= $num($o['due_date']) + $dp['lag_days']) echo 'clash: ' . $w['_ref'] . ' waits for ' . $o['_ref'] . "\n";
}

// ---- Flow history: day by day from 40 days ago to yesterday, from the tasks' own days -------------------------------
$names = ['todo' => 'To Do', 'doing' => 'In Progress', 'blocked' => 'Blocked', 'done' => 'Done', 'cancelled' => 'Cancelled'];
foreach ($flowTasks as $prj => $list) {
    if (in_array($prj, ['prj_intranet', 'prj_firewall', 'prj_backup'], true)) continue;   // not live, or not started
    $first = null;
    for ($day = -40; $day <= -1; $day++) {
        $n = array_fill_keys(array_keys($names), 0);
        foreach ($list as $t) {
            if ($t['created'] > $day) continue;
            if ($t['cancelled']) $k = 'cancelled';
            elseif ($t['done'] !== null && $t['done'] <= $day) $k = 'done';
            elseif ($t['blocked'] !== null && $t['blocked'] <= $day) $k = 'blocked';
            elseif ($t['start'] <= $day) $k = 'doing';
            else $k = 'todo';
            $n[$k]++;
        }
        if (array_sum($n) === 0) continue;
        foreach ($n as $k => $c) if ($c > 0) $add('tier3', 'project_task_flow', ['project_id' => '@projects.' . $prj, 'day' => $D($day), 'status' => $names[$k], 'task_count' => $c]);
    }
}

// ---- Put it together -----------------------------------------------------------------------------------------
foreach (['tier2' => ['projects', 'project_stages', 'tasks']] as $tier => $tables) foreach ($tables as $t) $d[$tier][$t] = array_merge($d[$tier][$t], $T[$tier][$t] ?? []);
$order3 = ['project_members', 'project_items', 'project_raid', 'project_tolerances', 'project_milestones', 'project_budget_lines', 'project_labour_rates', 'project_benefits',
           'project_gate_items', 'project_change_requests', 'task_dependencies', 'project_task_flow', 'project_reports', 'project_asset_targets', 'project_assets', 'project_changes',
           'project_tickets', 'project_contracts', 'project_cmdb_objects', 'project_knowledge_articles', 'project_problems', 'project_audit'];
foreach ($order3 as $t) if (!empty($T['tier3'][$t])) $d['tier3'][$t] = $T['tier3'][$t];
$d['tier4'] = $d['tier4'] ?? [];
foreach (['project_raci', 'project_baselines', 'project_benefit_measures', 'project_raid_tasks'] as $t) if (!empty($T['tier4'][$t])) $d['tier4'][$t] = $T['tier4'][$t];
// Every tier3 table produced must be in the order list.
foreach (array_keys($T['tier3']) as $t) if (!in_array($t, $order3, true)) die("unordered $t\n");

file_put_contents($file, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
$count = 0; foreach (['tier1', 'tier2', 'tier3', 'tier4'] as $t) foreach ($d[$t] as $tb => $rows) foreach ($rows as $r) if (empty($r['_skip_insert'])) $count++;
echo "written: " . count($T['tier2']['projects']) . " projects, " . count($T['tier2']['tasks']) . " project tasks, $count rows in all\n";
foreach (array_merge($T['tier3'], $T['tier4']) as $tb => $rows) echo "  $tb: " . count($rows) . "\n";
