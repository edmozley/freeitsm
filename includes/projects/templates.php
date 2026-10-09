<?php
/**
 * Projects - templates (3.2.0): start a project already planned.
 *
 * Used by api/projects/save.php (a new project with `template`),
 * api/projects/templates.php and api/projects/lookups.php (the picker).
 *
 * A template is a plan with no dates and no people:
 *   methodology, colour, icon, goal, summary, duration_days,
 *   stages  [{name, goal, start_day, end_day, tasks: [{title, description?, due_day?}]}]
 *   tasks   [{title, description?, due_day?}]         - not in any stage
 *   milestones [{name, day, stage?}]                   - stage = index into stages, or null (3.3.0)
 *   items   [{title, description?, moscow?}]          - scope, MoSCoW
 *   raid    [{type, title, description?, probability?, impact?, response?, response_plan?}]
 *   tolerances {time?, risk?}
 *   targets [{name, scope, scope_field?, scope_value?, done_field, done_op, done_value}]
 *   tailoring {tool: bool}
 * Days count from the project's start (day 0), so the same template plans a
 * project starting next week or next spring.
 *
 * 🔑 NOTHING IN A TEMPLATE BELONGS TO A COMPANY OR A PERSON - no analysts, no
 * tickets, no asset ids. One template serves every company on an MSP install.
 * List values in a target's rule (a status, a location) are kept as NAMES and
 * looked up when the template is used; a name that does not exist there drops
 * that one target rather than guessing.
 *
 * Two kinds:
 *   built-in  - written here (projectBuiltinTemplates), keyed "builtin:<key>".
 *               Never in the database, so deleting every template can never
 *               bring them back unasked; Projects -> Settings -> Templates can
 *               hide one (setting project_hidden_templates).
 *   saved     - project_templates rows, keyed "saved:<id>", made with
 *               "Save as template" on a project.
 */

require_once __DIR__ . '/methodologies.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/targets.php';

/** Has Database Verification created the table? Built-ins work either way. */
function projectTemplatesReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM project_templates LIMIT 0"); $ready = true; } catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** The templates that ship with FreeITSM. English, like the seeded roles. */
function projectBuiltinTemplates(): array
{
    $t = fn($title, $due = null, $desc = null) => array_filter(['title' => $title, 'due_day' => $due, 'description' => $desc], fn($v) => $v !== null);
    return [
        'office_move' => [
            'name' => 'Office move', 'description' => 'Move a team to a new building: survey, network and phones, the move weekend, and settling in.',
            'methodology' => 'staged', 'colour' => 'amber', 'icon' => 'building', 'duration_days' => 84,
            'goal' => 'Everyone working from the new office on day one, with nothing lost on the way.',
            'stages' => [
                ['name' => 'Survey and plan', 'goal' => 'Know the building and agree the plan', 'start_day' => 0, 'end_day' => 13, 'tasks' => [
                    $t('Survey the new building with the landlord', 5), $t('Agree the desk and room plan', 9), $t('Order the network cabling', 12), $t('Book the removals firm', 13)]],
                ['name' => 'Build the network', 'goal' => 'Network, Wi-Fi and phones working in the new building', 'start_day' => 14, 'end_day' => 48, 'tasks' => [
                    $t('Cabling installed and tested', 34), $t('Switches and Wi-Fi installed', 41), $t('Internet line live', 41), $t('Phone numbers ported or redirected', 45), $t('Printers set up', 47)]],
                ['name' => 'Move weekend', 'goal' => 'Everything moved and working by Monday morning', 'start_day' => 49, 'end_day' => 62, 'tasks' => [
                    $t('Send move instructions to staff', 52), $t('Label every desk and device', 55), $t('Move day', 60), $t('Test every desk before Monday', 61)]],
                ['name' => 'Settle in', 'goal' => 'Snags fixed and the old office handed back', 'start_day' => 63, 'end_day' => 84, 'tasks' => [
                    $t('Collect and fix snags', 70), $t('Hand back the old office keys', 77), $t('Write up lessons learned', 84)]],
            ],
            'items' => [
                ['title' => 'Every desk patched and tested', 'moscow' => 'must'], ['title' => 'Phones working on day one', 'moscow' => 'must'],
                ['title' => 'Meeting room screens', 'moscow' => 'should'], ['title' => 'Digital signage in reception', 'moscow' => 'could'],
                ['title' => 'Replacing the phone system', 'moscow' => 'wont'],
            ],
            'raid' => [
                ['type' => 'risk', 'title' => 'Cabling is not finished before the move date', 'probability' => 3, 'impact' => 5, 'response' => 'reduce', 'response_plan' => 'Weekly check with the installer; agree a cut-off date'],
                ['type' => 'risk', 'title' => 'Phone number porting fails on the day', 'probability' => 2, 'impact' => 4, 'response' => 'transfer', 'response_plan' => 'Keep the old lines live for a week'],
                ['type' => 'assumption', 'title' => 'The landlord finishes the fit-out on time'],
            ],
            'tolerances' => ['time' => 7, 'risk' => 15],
            'milestones' => [['name' => 'Network live in the new building', 'day' => 48, 'stage' => 1], ['name' => 'Move day', 'day' => 55, 'stage' => 2], ['name' => 'Old office handed back', 'day' => 84, 'stage' => 3]],
        ],
        'laptop_refresh' => [
            'name' => 'Laptop refresh', 'description' => 'Replace ageing laptops in waves, then wipe and retire the old ones - tracked live from Assets.',
            'methodology' => 'staged', 'colour' => 'sky', 'icon' => 'laptop', 'duration_days' => 112,
            'goal' => 'Every old laptop replaced, its data moved, and the old one retired.',
            'stages' => [
                ['name' => 'Choose and order', 'goal' => 'A standard laptop chosen and ordered', 'start_day' => 0, 'end_day' => 20, 'tasks' => [
                    $t('List the laptops to replace and link them to this project', 5), $t('Choose the standard model', 10), $t('Build and test the standard image', 18), $t('Place the order', 20)]],
                ['name' => 'Pilot', 'goal' => 'A small group on the new laptops, problems found early', 'start_day' => 21, 'end_day' => 41, 'tasks' => [
                    $t('Pick the pilot group', 23), $t('Swap the pilot laptops', 30), $t('Collect pilot feedback and fix the image', 41)]],
                ['name' => 'Roll out', 'goal' => 'Everyone else swapped, wave by wave', 'start_day' => 42, 'end_day' => 97, 'tasks' => [
                    $t('Publish the wave schedule', 44), $t('Wave 1', 60), $t('Wave 2', 75), $t('Wave 3', 90), $t('Mop up anyone missed', 97)]],
                ['name' => 'Retire the old', 'goal' => 'Old laptops wiped, retired and disposed of', 'start_day' => 98, 'end_day' => 112, 'tasks' => [
                    $t('Wipe the old laptops', 105), $t('Collect the disposal certificates', 110), $t('Write up lessons learned', 112)]],
            ],
            'items' => [
                ['title' => 'Data moved for every user', 'moscow' => 'must'], ['title' => 'Old laptops wiped with a certificate', 'moscow' => 'must'],
                ['title' => 'Docking stations replaced', 'moscow' => 'should'], ['title' => 'New bags for everyone', 'moscow' => 'could'],
            ],
            'raid' => [
                ['type' => 'risk', 'title' => 'Supplier cannot deliver in time', 'probability' => 3, 'impact' => 4, 'response' => 'reduce', 'response_plan' => 'Order the first waves early; agree a second supplier'],
                ['type' => 'risk', 'title' => 'Users lose files that were only on the old laptop', 'probability' => 2, 'impact' => 4, 'response' => 'avoid', 'response_plan' => 'Check every backup before the swap'],
            ],
            'tolerances' => ['time' => 14],
            'milestones' => [['name' => 'Pilot signed off', 'day' => 41, 'stage' => 1], ['name' => 'Last wave swapped', 'day' => 97, 'stage' => 2]],
            'targets' => [
                ['name' => 'Old laptops retired', 'scope' => 'linked', 'done_field' => 'status', 'done_op' => 'is', 'done_value' => 'Retired'],
            ],
        ],
        'mail_migration' => [
            'name' => 'Mail migration to the cloud', 'description' => 'Move mailboxes to a cloud service in batches, then switch the mail records over.',
            'methodology' => 'staged', 'colour' => 'violet', 'icon' => 'mail', 'duration_days' => 70,
            'goal' => 'Every mailbox in the cloud, with no lost mail and no long outage.',
            'stages' => [
                ['name' => 'Prepare', 'goal' => 'The cloud tenant ready and the plan agreed', 'start_day' => 0, 'end_day' => 13, 'tasks' => [
                    $t('Check licences for every user', 4), $t('Verify the mail domain in the cloud tenant', 7), $t('Plan the migration batches', 11), $t('Tell staff what to expect', 13)]],
                ['name' => 'Pilot batch', 'goal' => 'IT moved first, problems found early', 'start_day' => 14, 'end_day' => 27, 'tasks' => [
                    $t('Migrate the IT team', 18), $t('Test phones, shared mailboxes and calendars', 24)]],
                ['name' => 'Migrate', 'goal' => 'Every remaining batch moved', 'start_day' => 28, 'end_day' => 55, 'tasks' => [
                    $t('Batch 1', 35), $t('Batch 2', 42), $t('Batch 3', 49), $t('Shared mailboxes and groups', 55)]],
                ['name' => 'Cut over', 'goal' => 'Mail flowing straight to the cloud; the old server off', 'start_day' => 56, 'end_day' => 70, 'tasks' => [
                    $t('Switch the MX records', 57), $t('Watch mail flow for a week', 64), $t('Turn off the old mail server', 70)]],
            ],
            'items' => [
                ['title' => 'All mail history moved', 'moscow' => 'must'], ['title' => 'Shared mailboxes working', 'moscow' => 'must'],
                ['title' => 'Mobile devices set up again', 'moscow' => 'should'], ['title' => 'Archive old public folders', 'moscow' => 'could'],
            ],
            'raid' => [
                ['type' => 'risk', 'title' => 'A large mailbox takes days to copy', 'probability' => 4, 'impact' => 3, 'response' => 'reduce', 'response_plan' => 'Pre-stage the largest mailboxes a week early'],
                ['type' => 'risk', 'title' => 'Mail is lost during the MX switch', 'probability' => 2, 'impact' => 5, 'response' => 'reduce', 'response_plan' => 'Lower the DNS time-to-live two days before'],
            ],
            'tolerances' => ['time' => 7, 'risk' => 12],
            'milestones' => [['name' => 'Pilot batch moved', 'day' => 27, 'stage' => 1], ['name' => 'Mail records switched', 'day' => 60, 'stage' => 3]],
        ],
        'windows11' => [
            'name' => 'Windows 11 rollout', 'description' => 'Upgrade every Windows machine to Windows 11, counted straight from the inventory.',
            'methodology' => 'simple', 'colour' => 'teal', 'icon' => 'shield', 'duration_days' => 90,
            'goal' => 'Every Windows machine on Windows 11 before support for the old version ends.',
            'stages' => [
                ['name' => 'Check readiness', 'start_day' => 0, 'end_day' => 20, 'tasks' => [
                    $t('List machines that cannot run Windows 11', 7), $t('Test the line-of-business apps', 14), $t('Plan replacements for machines that cannot upgrade', 20)]],
                ['name' => 'Upgrade', 'start_day' => 21, 'end_day' => 76, 'tasks' => [
                    $t('Upgrade IT first', 28), $t('Upgrade in groups of 25', 70), $t('Chase machines that have not upgraded', 76)]],
                ['name' => 'Finish', 'start_day' => 77, 'end_day' => 90, 'tasks' => [
                    $t('Replace the last machines that cannot upgrade', 88), $t('Remove the old upgrade policy', 90)]],
            ],
            'raid' => [
                ['type' => 'risk', 'title' => 'An old app does not run on Windows 11', 'probability' => 3, 'impact' => 4, 'response' => 'reduce', 'response_plan' => 'Test every app in the readiness stage'],
            ],
            'targets' => [
                ['name' => 'On Windows 11', 'scope' => 'filter', 'scope_field' => 'operating_system', 'scope_value' => 'Windows', 'done_field' => 'operating_system', 'done_op' => 'contains', 'done_value' => 'Windows 11'],
            ],
        ],
        'service_desk' => [
            'name' => 'Service desk improvement', 'description' => 'An Agile backlog of service desk improvements, worked through in two-week sprints.',
            'methodology' => 'agile', 'colour' => 'lime', 'icon' => 'users', 'duration_days' => 56,
            'goal' => 'Faster answers and fewer repeat tickets, one sprint at a time.',
            'stages' => [
                ['name' => 'Sprint 1', 'goal' => 'Quick wins', 'start_day' => 0, 'end_day' => 13, 'tasks' => [$t('Write the five most-asked answers as knowledge articles', 13), $t('Add reply templates for common requests', 13)]],
                ['name' => 'Sprint 2', 'goal' => 'Better triage', 'start_day' => 14, 'end_day' => 27, 'tasks' => [$t('Tidy the ticket categories', 27), $t('Set up auto-assignment rules', 27)]],
                ['name' => 'Sprint 3', 'goal' => 'Self-service', 'start_day' => 28, 'end_day' => 41, 'tasks' => [$t('Publish the top request forms on the portal', 41)]],
                ['name' => 'Sprint 4', 'goal' => 'Measure and adjust', 'start_day' => 42, 'end_day' => 56, 'tasks' => [$t('Turn on satisfaction surveys', 50), $t('Review the numbers and plan what is next', 56)]],
            ],
            'items' => [
                ['title' => 'Knowledge articles for the top requests', 'moscow' => 'must'], ['title' => 'Auto-assignment rules', 'moscow' => 'should'],
                ['title' => 'Portal request forms', 'moscow' => 'should'], ['title' => 'A chat channel', 'moscow' => 'could'],
            ],
        ],
    ];
}

/**
 * Check and tidy template content (from a saved row, the screen or a built-in).
 * Unknown keys are dropped, every list is capped, days are clamped to 0-3650.
 */
function projectTemplateNormalise(array $c): array
{
    $str = fn($v, int $max) => ($v === null || trim((string)$v) === '') ? null : mb_substr(trim((string)$v), 0, $max);
    $day = fn($v) => ($v === null || $v === '' || !is_numeric($v)) ? null : max(0, min(3650, (int)$v));
    $tasks = function ($list) use ($str, $day) {
        $out = [];
        foreach (array_slice(is_array($list) ? $list : [], 0, 200) as $tk) {
            $title = $str($tk['title'] ?? null, 255);
            if ($title === null) continue;
            $out[] = array_filter(['title' => $title, 'description' => $str($tk['description'] ?? null, 5000), 'due_day' => $day($tk['due_day'] ?? null)], fn($v) => $v !== null);
        }
        return $out;
    };
    $methods = projectMethodologies();
    $out = [
        'methodology'   => isset($methods[$c['methodology'] ?? '']) ? $c['methodology'] : 'simple',
        'colour'        => isset(projectColours()[$c['colour'] ?? '']) ? $c['colour'] : 'coral',
        'icon'          => in_array($c['icon'] ?? '', projectIcons(), true) ? $c['icon'] : 'rocket',
        'goal'          => $str($c['goal'] ?? null, 500),
        'summary'       => $str($c['summary'] ?? null, 20000),
        'duration_days' => $day($c['duration_days'] ?? null),
        'stages' => [], 'tasks' => $tasks($c['tasks'] ?? []), 'items' => [], 'raid' => [], 'tolerances' => [], 'targets' => [], 'tailoring' => null,
        'milestones' => [],
    ];
    foreach (array_slice(is_array($c['stages'] ?? null) ? $c['stages'] : [], 0, 40) as $s) {
        $name = $str($s['name'] ?? null, 150);
        if ($name === null) continue;
        $out['stages'][] = ['name' => $name, 'goal' => $str($s['goal'] ?? null, 500), 'start_day' => $day($s['start_day'] ?? null), 'end_day' => $day($s['end_day'] ?? null), 'tasks' => $tasks($s['tasks'] ?? [])];
    }
    foreach (array_slice(is_array($c['milestones'] ?? null) ? $c['milestones'] : [], 0, 100) as $m) {
        $name = $str($m['name'] ?? null, 150);
        if ($name === null || $day($m['day'] ?? null) === null) continue;
        $si = isset($m['stage']) && is_numeric($m['stage']) && (int)$m['stage'] >= 0 && (int)$m['stage'] < count($out['stages']) ? (int)$m['stage'] : null;
        $out['milestones'][] = ['name' => $name, 'day' => $day($m['day']), 'stage' => $si];
    }
    foreach (array_slice(is_array($c['items'] ?? null) ? $c['items'] : [], 0, 200) as $i) {
        $title = $str($i['title'] ?? null, 255);
        if ($title === null) continue;
        $m = in_array($i['moscow'] ?? null, ['must', 'should', 'could', 'wont'], true) ? $i['moscow'] : null;
        $out['items'][] = ['title' => $title, 'description' => $str($i['description'] ?? null, 5000), 'moscow' => $m];
    }
    foreach (array_slice(is_array($c['raid'] ?? null) ? $c['raid'] : [], 0, 100) as $r) {
        $title = $str($r['title'] ?? null, 255);
        if ($title === null || !in_array($r['type'] ?? '', ['risk', 'assumption', 'issue', 'decision', 'lesson'], true)) continue;
        $score = fn($v) => (is_numeric($v) && (int)$v >= 1 && (int)$v <= 5) ? (int)$v : null;
        $out['raid'][] = ['type' => $r['type'], 'title' => $title, 'description' => $str($r['description'] ?? null, 5000),
            'probability' => $r['type'] === 'risk' ? $score($r['probability'] ?? null) : null,
            'impact' => in_array($r['type'], ['risk', 'issue'], true) ? $score($r['impact'] ?? null) : null,
            'response' => in_array($r['response'] ?? null, ['avoid', 'reduce', 'transfer', 'accept', 'share'], true) ? $r['response'] : null,
            'response_plan' => $str($r['response_plan'] ?? null, 5000)];
    }
    $tol = is_array($c['tolerances'] ?? null) ? $c['tolerances'] : [];
    if (isset($tol['time']) && is_numeric($tol['time']) && $tol['time'] >= 0 && $tol['time'] <= 365) $out['tolerances']['time'] = (int)$tol['time'];
    if (isset($tol['risk']) && is_numeric($tol['risk']) && $tol['risk'] >= 1 && $tol['risk'] <= 25) $out['tolerances']['risk'] = (int)$tol['risk'];
    foreach (array_slice(is_array($c['targets'] ?? null) ? $c['targets'] : [], 0, 20) as $tg) {
        // Shape only here - the done value of a list field is a NAME in a template.
        $f = projectTargetDoneFields()[$tg['done_field'] ?? ''] ?? null;
        if (!$f || !in_array($tg['done_op'] ?? '', $f['ops'], true)) continue;
        $out['targets'][] = ['name' => $str($tg['name'] ?? null, 150) ?? 'Target', 'scope' => ($tg['scope'] ?? '') === 'linked' ? 'linked' : 'filter',
            'scope_field' => isset(projectTargetScopeFields()[$tg['scope_field'] ?? '']) ? $tg['scope_field'] : null,
            'scope_value' => $str($tg['scope_value'] ?? null, 100), 'done_field' => $tg['done_field'], 'done_op' => $tg['done_op'],
            'done_value' => (string)$str($tg['done_value'] ?? '', 100)];
    }
    if (is_array($c['tailoring'] ?? null)) {
        $tl = [];
        foreach (projectToolDefinitions() as $k => $_) if (array_key_exists($k, $c['tailoring'])) $tl[$k] = (bool)$c['tailoring'][$k];
        $out['tailoring'] = $tl ?: null;
    }
    return $out;
}

/** What the picker shows about a template: counts, without the whole plan. */
function projectTemplateSummary(string $key, string $name, ?string $desc, array $c, bool $builtin, bool $active = true): array
{
    $tasks = count($c['tasks']);
    foreach ($c['stages'] as $s) $tasks += count($s['tasks']);
    return [
        'key' => $key, 'name' => $name, 'description' => $desc, 'builtin' => $builtin, 'active' => $active,
        'methodology' => $c['methodology'], 'colour' => $c['colour'], 'icon' => $c['icon'], 'goal' => $c['goal'],
        'duration_days' => $c['duration_days'],
        'counts' => ['stages' => count($c['stages']), 'tasks' => $tasks, 'items' => count($c['items']), 'risks' => count($c['raid']), 'targets' => count($c['targets']), 'milestones' => count($c['milestones'])],
    ];
}

/**
 * Every template. $all = false: only what the new-project picker offers
 * (built-ins not hidden, saved ones active). true: for the Settings tab.
 */
function projectTemplateList(PDO $conn, bool $all = false): array
{
    $hidden = array_filter(explode(',', projectSetting($conn, 'project_hidden_templates')));
    $out = [];
    foreach (projectBuiltinTemplates() as $k => $tpl) {
        $active = !in_array($k, $hidden, true);
        if (!$all && !$active) continue;
        $out[] = projectTemplateSummary('builtin:' . $k, $tpl['name'], $tpl['description'], projectTemplateNormalise($tpl), true, $active);
    }
    if (projectTemplatesReady($conn)) {
        $rows = $conn->query("SELECT t.id, t.name, t.description, t.content, t.is_active, t.created_datetime, a.full_name AS created_by_name
                                FROM project_templates t LEFT JOIN analysts a ON a.id = t.created_by_analyst_id ORDER BY t.name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (!$all && !(int)$r['is_active']) continue;
            $c = projectTemplateNormalise(json_decode((string)$r['content'], true) ?: []);
            $out[] = projectTemplateSummary('saved:' . $r['id'], $r['name'], $r['description'], $c, false, (bool)(int)$r['is_active'])
                   + ['id' => (int)$r['id'], 'created_by_name' => $r['created_by_name'], 'created_datetime' => $r['created_datetime']];
        }
    }
    return $out;
}

/** [name, content] for a key, or null when there is no such (usable) template. */
function projectTemplateLoad(PDO $conn, string $key, bool $evenHidden = false): ?array
{
    if (strpos($key, 'builtin:') === 0) {
        $k = substr($key, 8);
        $all = projectBuiltinTemplates();
        if (!isset($all[$k])) return null;
        if (!$evenHidden && in_array($k, array_filter(explode(',', projectSetting($conn, 'project_hidden_templates'))), true)) return null;
        return [$all[$k]['name'], projectTemplateNormalise($all[$k])];
    }
    if (strpos($key, 'saved:') === 0 && projectTemplatesReady($conn)) {
        $st = $conn->prepare("SELECT name, content, is_active FROM project_templates WHERE id = ?");
        $st->execute([(int)substr($key, 6)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || (!$evenHidden && !(int)$r['is_active'])) return null;
        return [$r['name'], projectTemplateNormalise(json_decode((string)$r['content'], true) ?: [])];
    }
    return null;
}

/**
 * A project as a template: its plan with dates turned into days from its start,
 * and none of its people, companies or links. $parts picks what to keep:
 * plan, scope, raid, tolerances, targets.
 */
function projectTemplateCapture(PDO $conn, array $project, array $parts): array
{
    $pid = (int)$project['id'];
    // Day 0: the project's start, else its earliest stage start, else when it was made.
    $base = $project['start_date'];
    if (!$base) {
        $st = $conn->prepare("SELECT MIN(start_date) FROM project_stages WHERE project_id = ?");
        $st->execute([$pid]);
        $base = $st->fetchColumn() ?: substr((string)$project['created_datetime'], 0, 10);
    }
    $d0 = strtotime($base . ' 00:00:00 UTC');
    $day = fn($d) => $d ? max(0, (int)round((strtotime(substr((string)$d, 0, 10) . ' 00:00:00 UTC') - $d0) / 86400)) : null;
    $c = [
        'methodology' => $project['methodology'], 'colour' => $project['colour'], 'icon' => $project['icon'],
        'goal' => $project['goal'], 'summary' => $project['summary'],
        'duration_days' => $project['target_end_date'] ? $day($project['target_end_date']) : null,
        'tailoring' => $project['tailoring'] ? json_decode((string)$project['tailoring'], true) : null,
        'stages' => [], 'tasks' => [],
    ];
    if (in_array('plan', $parts, true)) {
        $stages = $conn->prepare("SELECT id, name, goal, start_date, end_date FROM project_stages WHERE project_id = ? ORDER BY position, id");
        $stages->execute([$pid]);
        $tasks = $conn->prepare("SELECT title, description, due_date, project_stage_id FROM tasks WHERE project_id = ? AND parent_task_id IS NULL ORDER BY board_position, id");
        $tasks->execute([$pid]);
        $byStage = [];
        foreach ($tasks->fetchAll(PDO::FETCH_ASSOC) as $tk) {
            $row = ['title' => $tk['title'], 'description' => $tk['description'], 'due_day' => $day($tk['due_date'])];
            if ($tk['project_stage_id']) $byStage[(int)$tk['project_stage_id']][] = $row; else $c['tasks'][] = $row;
        }
        $index = [];
        foreach ($stages->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $index[(int)$s['id']] = count($c['stages']);
            $c['stages'][] = ['name' => $s['name'], 'goal' => $s['goal'], 'start_day' => $day($s['start_date']), 'end_day' => $day($s['end_date']), 'tasks' => $byStage[(int)$s['id']] ?? []];
        }
        // Milestones (3.3.0) - their dates as days, their stage as its place in the list.
        require_once __DIR__ . '/milestones.php';
        if (projectMilestonesReady($conn)) {
            $st = $conn->prepare("SELECT name, due_date, stage_id FROM project_milestones WHERE project_id = ? ORDER BY due_date, position, id");
            $st->execute([$pid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $c['milestones'][] = ['name' => $m['name'], 'day' => $day($m['due_date']), 'stage' => $m['stage_id'] !== null ? ($index[(int)$m['stage_id']] ?? null) : null];
            }
        }
    }
    if (in_array('scope', $parts, true)) {
        $st = $conn->prepare("SELECT title, description, moscow FROM project_items WHERE project_id = ? AND status <> 'dropped' ORDER BY position, id");
        $st->execute([$pid]);
        $c['items'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if (in_array('raid', $parts, true)) {
        // Risks and assumptions carry over to the next project; issues, decisions
        // and lessons were about this one.
        $st = $conn->prepare("SELECT type, title, description, probability, impact, response, response_plan FROM project_raid WHERE project_id = ? AND type IN ('risk', 'assumption') ORDER BY id");
        $st->execute([$pid]);
        $c['raid'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if (in_array('tolerances', $parts, true)) {
        require_once __DIR__ . '/../services/project_tools.php';
        $c['tolerances'] = array_filter(ProjectToolsService::tolerances($conn, $pid), fn($v) => $v !== null);
    }
    if (in_array('targets', $parts, true) && projectTargetsReady($conn)) {
        $st = $conn->prepare("SELECT name, scope, scope_field, scope_value, done_field, done_op, done_value FROM project_asset_targets WHERE project_id = ? ORDER BY position, id");
        $st->execute([$pid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // A list value becomes its NAME (status 6 -> "Retired") so it means the same on another install or company.
        $names = projectTargetValueNames($conn, $rows);
        foreach ($rows as &$r) if (in_array($r['done_field'], ['status', 'location'], true)) $r['done_value'] = $names[$r['done_field'] . ':' . $r['done_value']] ?? '';
        unset($r);
        // A type is a list of the company's own - left out; the rule keeps its field match.
        $c['targets'] = array_values(array_filter($rows, fn($r) => $r['done_value'] !== '' || !in_array($r['done_field'], ['status', 'location'], true)));
    }
    return projectTemplateNormalise($c);
}
