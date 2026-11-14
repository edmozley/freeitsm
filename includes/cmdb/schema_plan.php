<?php
/**
 * CMDB schema plans (GH #313 slice 3): a list of relationship types, classes and
 * properties to ADD, previewed line by line and applied in one go.
 *
 * Two callers: the Starter classes button (includes/cmdb/starter_classes.php) and,
 * later, the CMDB AI assistant, which will hand over a plan in the same shape.
 *
 *   cmdbPlanResolve(PDO, $plan)                         -> what each line would do. Reads only.
 *   cmdbPlanApply(PDO, ActorContext, $plan, $selection) -> creates the ticked lines.
 *
 * Plan shape (every field but the names optional):
 *   {title, relationship_types:[{verb, inverse_verb, impact_direction, description, why}],
 *    classes:[{key, name, description, icon, why,
 *              properties:[{label, key, type, required, options:[..], target, spreads_impact, why}]}],
 *    usage:[{from, verb, to}], parent_child:[{parent, child}]}
 * `target` (object_ref) names a class by key or name - one already there or one in
 * the same plan. `usage` and `parent_child` are guidance only (nothing in the
 * schema records them) and come back for display.
 *
 * 🔑 ADD-ONLY. A plan never changes or removes anything already there:
 *   - a class matching one already there (by key, or by name with simple plural
 *     folding - "Servers" is "Server") is `exists`, and its NEW properties are added
 *     to that class;
 *   - a property matching one in its class (by key or label) is `exists` when the
 *     type is the same and `conflict` when it is not ("already there as Text - not
 *     changed");
 *   - a relationship type matching on verb or inverse verb, either way round, is `exists`.
 * Refused (`invalid`), with the reason: a class named like a record (Person, Team,
 * Location ... - use that property type), an object reference to a class that is
 * neither there nor in the plan, a dropdown with no options, a record type before
 * Database Verification has added value_ref_id, and anything past the caps
 * (12 classes, 15 properties a class, 6 relationship types).
 *
 * Every line has a stable id - 'rt:<n>', 'class:<key>', 'prop:<class key>:<property key>' -
 * which is what the preview's ticks send back as the selection.
 *
 * @wiki CMDB-Developer-Guide
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../services/cmdb_schema.php';
require_once __DIR__ . '/../cmdb_record_refs.php';
require_once __DIR__ . '/../cmdb_impact.php';
require_once __DIR__ . '/../rbac.php';
require_once __DIR__ . '/../i18n.php';

const CMDB_PLAN_MAX_CLASSES = 12;
const CMDB_PLAN_MAX_PROPERTIES = 15;
const CMDB_PLAN_MAX_REL_TYPES = 6;

/**
 * A name folded for matching: lower case, single spaces, and the last word's
 * simple plural taken off ("Servers" -> "server", "Policies" -> "policy",
 * "Switches" -> "switch"). Deliberately simple - a near miss is shown in the
 * preview and can be unticked.
 *
 * @param string $name  a class name or key
 */
function cmdbPlanFold(string $name): string
{
    $s = mb_strtolower(trim(preg_replace('/[\s_]+/u', ' ', $name)));
    if ($s === '') return '';
    if (preg_match('/ies$/', $s)) return substr($s, 0, -3) . 'y';
    if (preg_match('/(ss|sh|ch|x)es$/', $s)) return substr($s, 0, -2);
    if (preg_match('/[^s]s$/', $s)) return substr($s, 0, -1);
    return $s;
}

/** Lower-case, trimmed, single-spaced - how verbs are compared. */
function cmdbPlanVerbNorm(string $v): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v)));
}

/**
 * Class names that are really records FreeITSM already keeps, folded => the
 * property type to use instead.
 */
function cmdbPlanRecordClassNames(): array
{
    $out = [];
    foreach (array_keys(cmdbRecordTypes()) as $type) {
        $out[cmdbPlanFold($type)] = $type;
        $out[cmdbPlanFold(t('cmdb.record.type_' . $type))] = $type;
    }
    foreach (['user' => 'person', 'people' => 'person', 'staff' => 'person', 'employee' => 'person',
              'tenant' => 'company', 'vendor' => 'supplier', 'site' => 'location'] as $n => $type) {
        $out[$n] = $type;
    }
    return $out;
}

/** A property type's display name ("Person", "Yes/No"). */
function cmdbPlanTypeLabel(string $type): string
{
    if (cmdbIsRecordType($type)) return t('cmdb.record.type_' . $type);
    $k = 'cmdb.settings.prop_type_' . $type;
    $v = t($k);
    return $v === $k ? $type : $v;
}

/**
 * Work out what every line of a plan would do, against the schema as it is now.
 * Reads only - run at preview and again at apply.
 *
 * Returns the plan with, on every relationship type, class and property:
 *   line    the id the selection uses
 *   action  create | exists | conflict | invalid
 *   reason  why, for anything but create ('' for create)
 *   existing_id  the matching row, for exists / conflict
 * plus on a class: id (existing id or null), key (resolved); on a property: key,
 * type_label, options, target_line / target_id / target_name for an object
 * reference, depends_on (the line ids that must also be ticked). Top level:
 * records_ready, counts {create, exists, conflict, invalid}, usage, parent_child.
 *
 * @param array $plan  see the file header
 * @param array $opts  records_ready: override cmdbRecordRefsReady() (it is cached per
 *                     process, so a test cannot otherwise see the not-yet-verified case)
 */
function cmdbPlanResolve(PDO $conn, array $plan, array $opts = []): array
{
    $ready = array_key_exists('records_ready', $opts) ? (bool)$opts['records_ready'] : cmdbRecordRefsReady($conn);
    $types = cmdbPropertyTypes();
    $recordNames = cmdbPlanRecordClassNames();

    // What is there now
    $existing = $conn->query("SELECT id, class_key, name FROM cmdb_classes")->fetchAll(PDO::FETCH_ASSOC);
    $byKey = []; $byFold = [];
    foreach ($existing as $c) {
        $byKey[$c['class_key']] = $c;
        $byFold[cmdbPlanFold($c['name'])] = $byFold[cmdbPlanFold($c['name'])] ?? $c;
        $byFold[cmdbPlanFold($c['class_key'])] = $byFold[cmdbPlanFold($c['class_key'])] ?? $c;
    }
    $findExisting = function (string $key, string $name) use ($byKey, $byFold): ?array {
        if ($key !== '' && isset($byKey[$key])) return $byKey[$key];
        foreach ([$name, $key] as $n) {
            $f = cmdbPlanFold($n);
            if ($f !== '' && isset($byFold[$f])) return $byFold[$f];
        }
        return null;
    };
    $props = [];
    foreach ($conn->query("SELECT id, class_id, property_key, label, property_type, target_class_id FROM cmdb_class_properties")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $props[(int)$p['class_id']][] = $p;
    }
    $classNameById = [];
    foreach ($existing as $c) $classNameById[(int)$c['id']] = $c['name'];

    $out = [
        'title' => trim((string)($plan['title'] ?? '')),
        'records_ready' => $ready,
        'relationship_types' => [],
        'classes' => [],
        'usage' => array_values(array_filter((array)($plan['usage'] ?? []), 'is_array')),
        'parent_child' => array_values(array_filter((array)($plan['parent_child'] ?? []), 'is_array')),
        'counts' => ['create' => 0, 'exists' => 0, 'conflict' => 0, 'invalid' => 0],
    ];

    // ---- Relationship types
    $rtRows = $conn->query("SELECT id, verb, inverse_verb FROM cmdb_relationship_types")->fetchAll(PDO::FETCH_ASSOC);
    $seenVerbs = [];
    foreach (array_values((array)($plan['relationship_types'] ?? [])) as $i => $rt) {
        if (!is_array($rt)) continue;
        $verb = trim((string)($rt['verb'] ?? ''));
        $inverse = trim((string)($rt['inverse_verb'] ?? ''));
        $dir = (string)($rt['impact_direction'] ?? CMDB_IMPACT_NONE);
        $line = [
            'line' => 'rt:' . $i, 'verb' => $verb, 'inverse_verb' => $inverse, 'impact_direction' => $dir,
            'description' => trim((string)($rt['description'] ?? '')), 'why' => trim((string)($rt['why'] ?? '')),
            'action' => 'create', 'reason' => '', 'existing_id' => null,
        ];
        $mine = array_filter([cmdbPlanVerbNorm($verb), cmdbPlanVerbNorm($inverse)], 'strlen');
        if ($i >= CMDB_PLAN_MAX_REL_TYPES) {
            $line['action'] = 'invalid'; $line['reason'] = t('cmdb.plan.reason_too_many_rel_types', ['max' => CMDB_PLAN_MAX_REL_TYPES]);
        } elseif ($verb === '' || $inverse === '') {
            $line['action'] = 'invalid'; $line['reason'] = t('cmdb.plan.reason_rel_needs_both');
        } elseif (mb_strlen($verb) > 100 || mb_strlen($inverse) > 100) {
            $line['action'] = 'invalid'; $line['reason'] = t('cmdb.plan.reason_too_long');
        } elseif (!in_array($dir, cmdbImpactDirections(), true)) {
            $line['action'] = 'invalid'; $line['reason'] = t('cmdb.plan.reason_bad_direction');
        } else {
            foreach ($rtRows as $r) {
                $theirs = [cmdbPlanVerbNorm((string)$r['verb']), cmdbPlanVerbNorm((string)$r['inverse_verb'])];
                if (array_intersect($mine, $theirs)) {
                    $line['action'] = 'exists'; $line['existing_id'] = (int)$r['id'];
                    $line['reason'] = t('cmdb.plan.reason_rel_exists', ['verb' => $r['verb'], 'inverse' => $r['inverse_verb']]);
                    break;
                }
            }
            if ($line['action'] === 'create' && array_intersect($mine, $seenVerbs)) {
                $line['action'] = 'invalid'; $line['reason'] = t('cmdb.plan.reason_listed_twice');
            }
        }
        $seenVerbs = array_merge($seenVerbs, $mine);
        $out['relationship_types'][] = $line;
    }

    // ---- Classes, first pass: what each class is (targets need the whole list)
    $planClasses = [];      // index => resolved class (without properties yet)
    $planByKey = []; $planByFold = [];
    foreach (array_values((array)($plan['classes'] ?? [])) as $i => $c) {
        if (!is_array($c)) continue;
        $name = trim((string)($c['name'] ?? ''));
        $key = trim((string)($c['key'] ?? ''));
        if ($key === '') $key = cmdbSchemaSlug($name, 'class');
        $line = [
            'line' => 'class:' . $key, 'key' => $key, 'name' => $name,
            'description' => trim((string)($c['description'] ?? '')), 'icon' => trim((string)($c['icon'] ?? '')),
            'why' => trim((string)($c['why'] ?? '')),
            'action' => 'create', 'reason' => '', 'existing_id' => null, 'id' => null, 'properties' => [],
        ];
        $fold = cmdbPlanFold($name);
        if ($i >= CMDB_PLAN_MAX_CLASSES) {
            $line['action'] = 'invalid'; $line['reason'] = t('cmdb.plan.reason_too_many_classes', ['max' => CMDB_PLAN_MAX_CLASSES]);
        } elseif ($name === '') {
            $line['action'] = 'invalid'; $line['reason'] = t('cmdb.plan.reason_no_name');
        } elseif (mb_strlen($name) > 150 || mb_strlen($key) > 100) {
            $line['action'] = 'invalid'; $line['reason'] = t('cmdb.plan.reason_too_long');
        } elseif (!preg_match('/^[a-z0-9_]+$/', $key)) {
            $line['action'] = 'invalid'; $line['reason'] = t('cmdb.plan.reason_bad_key');
        } elseif (isset($recordNames[$fold])) {
            $line['action'] = 'invalid';
            $line['reason'] = t('cmdb.plan.reason_record_class', ['type' => cmdbPlanTypeLabel($recordNames[$fold])]);
        } elseif (isset($planByKey[$key]) || isset($planByFold[$fold])) {
            $line['action'] = 'invalid'; $line['reason'] = t('cmdb.plan.reason_listed_twice');
        } elseif ($ex = $findExisting($key, $name)) {
            $line['action'] = 'exists'; $line['existing_id'] = $line['id'] = (int)$ex['id'];
            $line['reason'] = t('cmdb.plan.reason_class_exists', ['name' => $ex['name']]);
        }
        if ($line['action'] !== 'invalid' || !isset($planByKey[$key])) {
            $planByKey[$key] = $planByKey[$key] ?? $i;
            if ($fold !== '') $planByFold[$fold] = $planByFold[$fold] ?? $i;
        }
        $planClasses[$i] = ['line' => $line, 'src' => $c];
    }

    // Find an object reference's target: a class in this plan, else one already there.
    $resolveTarget = function (string $t) use (&$planClasses, $planByKey, $planByFold, $findExisting): ?array {
        $t = trim($t);
        if ($t === '') return null;
        $idx = $planByKey[$t] ?? $planByFold[cmdbPlanFold($t)] ?? null;
        if ($idx !== null) {
            $pc = $planClasses[$idx]['line'];
            return ['plan' => true, 'line' => $pc['line'], 'action' => $pc['action'], 'id' => $pc['id'], 'name' => $pc['name']];
        }
        $ex = $findExisting($t, $t);
        return $ex ? ['plan' => false, 'line' => null, 'action' => 'exists', 'id' => (int)$ex['id'], 'name' => $ex['name']] : null;
    };

    // ---- Classes, second pass: properties
    foreach ($planClasses as $i => $pcRow) {
        $cl = $pcRow['line'];
        $have = $cl['existing_id'] ? ($props[$cl['existing_id']] ?? []) : [];
        $seen = [];
        foreach (array_values((array)($pcRow['src']['properties'] ?? [])) as $j => $p) {
            if (!is_array($p)) continue;
            $label = trim((string)($p['label'] ?? ''));
            $pkey = trim((string)($p['key'] ?? ''));
            if ($pkey === '') $pkey = cmdbSchemaSlug($label, 'property');
            $type = trim((string)($p['type'] ?? 'text'));
            $options = [];
            foreach ((array)($p['options'] ?? []) as $o) {
                $v = trim((string)(is_array($o) ? ($o['value'] ?? '') : $o));
                if ($v !== '' && !in_array($v, $options, true)) $options[] = $v;
            }
            $pl = [
                'line' => 'prop:' . $cl['key'] . ':' . $pkey, 'label' => $label, 'key' => $pkey, 'type' => $type,
                'type_label' => in_array($type, $types, true) ? cmdbPlanTypeLabel($type) : $type,
                'required' => !empty($p['required']), 'options' => $type === 'dropdown' ? $options : [],
                'target' => trim((string)($p['target'] ?? '')), 'target_line' => null, 'target_id' => null, 'target_name' => null,
                'spreads_impact' => $type === 'object_ref' && !empty($p['spreads_impact']),
                'why' => trim((string)($p['why'] ?? '')),
                'action' => 'create', 'reason' => '', 'existing_id' => null, 'depends_on' => [],
            ];
            if ($cl['action'] === 'create') $pl['depends_on'][] = $cl['line'];

            $target = null;
            if ($cl['action'] === 'invalid' || $cl['action'] === 'conflict') {
                $pl['action'] = 'invalid'; $pl['reason'] = t('cmdb.plan.reason_class_skipped');
            } elseif ($j >= CMDB_PLAN_MAX_PROPERTIES) {
                $pl['action'] = 'invalid'; $pl['reason'] = t('cmdb.plan.reason_too_many_props', ['max' => CMDB_PLAN_MAX_PROPERTIES]);
            } elseif ($label === '') {
                $pl['action'] = 'invalid'; $pl['reason'] = t('cmdb.plan.reason_no_name');
            } elseif (mb_strlen($label) > 150 || mb_strlen($pkey) > 100) {
                $pl['action'] = 'invalid'; $pl['reason'] = t('cmdb.plan.reason_too_long');
            } elseif (!preg_match('/^[a-z0-9_]+$/', $pkey)) {
                $pl['action'] = 'invalid'; $pl['reason'] = t('cmdb.plan.reason_bad_key');
            } elseif (!in_array($type, $types, true)) {
                $pl['action'] = 'invalid'; $pl['reason'] = t('cmdb.plan.reason_bad_type', ['type' => $type]);
            } elseif (cmdbIsRecordType($type) && !$ready) {
                $pl['action'] = 'invalid'; $pl['reason'] = t('cmdb.plan.reason_records_not_ready');
            } elseif ($type === 'dropdown' && !$options) {
                $pl['action'] = 'invalid'; $pl['reason'] = t('cmdb.plan.reason_no_options');
            } elseif ($type === 'object_ref' && !($target = $resolveTarget($pl['target']))) {
                $pl['action'] = 'invalid';
                $rec = $recordNames[cmdbPlanFold($pl['target'])] ?? null;   // "-> Person": the record type, not a CI
                $pl['reason'] = $rec !== null
                    ? t('cmdb.plan.reason_record_target', ['type' => cmdbPlanTypeLabel($rec)])
                    : t('cmdb.plan.reason_no_target', ['target' => $pl['target']]);
            } elseif ($target && $target['action'] === 'invalid') {
                $pl['action'] = 'invalid'; $pl['reason'] = t('cmdb.plan.reason_target_skipped', ['target' => $target['name']]);
            } elseif (isset($seen[$pkey]) || isset($seen['l:' . mb_strtolower($label)])) {
                $pl['action'] = 'invalid'; $pl['reason'] = t('cmdb.plan.reason_listed_twice');
            }
            if ($target) {
                $pl['target_name'] = $target['name'];
                $pl['target_id'] = $target['id'];
                $pl['target_line'] = $target['plan'] ? $target['line'] : null;
                if ($target['plan'] && $target['action'] === 'create') $pl['depends_on'][] = $target['line'];
            }
            if ($pl['action'] === 'create') {
                foreach ($have as $h) {
                    if ($h['property_key'] !== $pkey && mb_strtolower($h['label']) !== mb_strtolower($label)) continue;
                    $pl['existing_id'] = (int)$h['id'];
                    $sameTarget = $type !== 'object_ref' || (int)$h['target_class_id'] === (int)$pl['target_id'];
                    if ($h['property_type'] === $type && $sameTarget) {
                        $pl['action'] = 'exists'; $pl['reason'] = t('cmdb.plan.reason_prop_exists');
                    } elseif ($h['property_type'] === $type) {
                        $pl['action'] = 'conflict';
                        $pl['reason'] = t('cmdb.plan.reason_prop_other_target', ['target' => $classNameById[(int)$h['target_class_id']] ?? '?']);
                    } else {
                        $pl['action'] = 'conflict';
                        $pl['reason'] = t('cmdb.plan.reason_prop_other_type', ['type' => cmdbPlanTypeLabel((string)$h['property_type'])]);
                    }
                    break;
                }
            }
            $seen[$pkey] = true; $seen['l:' . mb_strtolower($label)] = true;
            $cl['properties'][] = $pl;
        }
        $out['classes'][] = $cl;
    }

    foreach ($out['relationship_types'] as $r) $out['counts'][$r['action']]++;
    foreach ($out['classes'] as $c) {
        $out['counts'][$c['action']]++;
        foreach ($c['properties'] as $p) $out['counts'][$p['action']]++;
    }
    return $out;
}

/**
 * Create the ticked lines of a plan, in ONE transaction: relationship types, then
 * classes, then properties (so an object reference finds its new target's id).
 *
 * Each line is checked against the actor's capability - Cap::CMDB_CLASSES for
 * classes and properties, Cap::CMDB_RELATIONSHIP_TYPES for relationship types -
 * and a line they may not create is skipped with the reason, not an error. The
 * plan is resolved again first, so anything created since the preview counts as
 * already there; a line whose class or target is not ticked is skipped too.
 * A ServiceError (or anything else) part-way rolls EVERYTHING back and is rethrown.
 * Running the same plan twice creates nothing the second time. wf_emit runs after
 * the commit.
 *
 * Returns ['created' => ['relationship_types' => [ids], 'classes' => [ids], 'properties' => [ids]],
 *          'counts' => [same keys => n], 'skipped' => [['line', 'reason']], 'existing' => n].
 *
 * @param array    $selection  line ids to create (from cmdbPlanResolve()'s `line`)
 * @param array    $opts       on_item: callable(string $kind, string $line) run before each write -
 *                             a test throws from it to prove the rollback; the assistant may report progress.
 *                             records_ready: passed to cmdbPlanResolve()
 */
function cmdbPlanApply(PDO $conn, ActorContext $ctx, array $plan, array $selection, array $opts = []): array
{
    $resolved = cmdbPlanResolve($conn, $plan, array_intersect_key($opts, ['records_ready' => 1]));
    $want = array_flip(array_map('strval', $selection));
    $canClasses = $ctx->actorId > 0 && analystHasCapability($conn, $ctx->actorId, Cap::CMDB_CLASSES);
    $canRel = $ctx->actorId > 0 && analystHasCapability($conn, $ctx->actorId, Cap::CMDB_RELATIONSHIP_TYPES);
    $hook = $opts['on_item'] ?? null;

    $created = ['relationship_types' => [], 'classes' => [], 'properties' => []];
    $skipped = [];
    $existing = 0;
    $events = [];
    $no = ['emit' => false];

    $iconIds = [];
    foreach ($conn->query("SELECT id, icon_key FROM cmdb_icons")->fetchAll(PDO::FETCH_ASSOC) as $r) $iconIds[$r['icon_key']] = (int)$r['id'];

    // A line is wanted when ticked and creatable; anything else ticked is reported.
    $take = function (array $l, bool $allowed) use ($want, &$skipped, &$existing): bool {
        if (!isset($want[$l['line']])) return false;
        if ($l['action'] === 'exists') { $existing++; return false; }
        if ($l['action'] !== 'create') { $skipped[] = ['line' => $l['line'], 'reason' => $l['reason']]; return false; }
        if (!$allowed) { $skipped[] = ['line' => $l['line'], 'reason' => t('cmdb.plan.reason_no_permission')]; return false; }
        return true;
    };

    $conn->beginTransaction();
    try {
        // ---- Relationship types
        $order = (int)$conn->query("SELECT COALESCE(MAX(display_order), 0) FROM cmdb_relationship_types")->fetchColumn();
        $chk = $conn->prepare("SELECT id FROM cmdb_relationship_types WHERE LOWER(verb) IN (?, ?) OR LOWER(inverse_verb) IN (?, ?)");
        foreach ($resolved['relationship_types'] as $rt) {
            if (!$take($rt, $canRel)) continue;
            $v = cmdbPlanVerbNorm($rt['verb']); $iv = cmdbPlanVerbNorm($rt['inverse_verb']);
            $chk->execute([$v, $iv, $v, $iv]);
            if ($chk->fetchColumn()) { $existing++; continue; }   // created since the preview
            if ($hook) $hook('relationship_type', $rt['line']);
            $order += 10;
            $r = CmdbSchemaService::saveRelationshipType($conn, $ctx, [
                'verb' => $rt['verb'], 'inverse_verb' => $rt['inverse_verb'], 'description' => $rt['description'],
                'impact_direction' => $rt['impact_direction'], 'display_order' => $order, 'is_active' => 1,
            ], $no);
            $created['relationship_types'][] = $r['id'];
            $events = array_merge($events, $r['events']);
        }

        // ---- Classes
        $classId = [];   // line => id, for properties and targets
        $order = (int)$conn->query("SELECT COALESCE(MAX(display_order), 0) FROM cmdb_classes")->fetchColumn();
        $chk = $conn->prepare("SELECT id FROM cmdb_classes WHERE class_key = ?");
        foreach ($resolved['classes'] as $c) {
            if ($c['action'] === 'exists') { $classId[$c['line']] = $c['id']; }
            if (!$take($c, $canClasses)) continue;
            $chk->execute([$c['key']]);
            if ($id = $chk->fetchColumn()) { $existing++; $classId[$c['line']] = (int)$id; continue; }
            if ($hook) $hook('class', $c['line']);
            $order += 10;
            $r = CmdbSchemaService::createClass($conn, $ctx, [
                'name' => $c['name'], 'class_key' => $c['key'], 'description' => $c['description'],
                'icon_id' => $iconIds[$c['icon']] ?? null, 'display_order' => $order, 'is_active' => 1,
            ], $no);
            $classId[$c['line']] = $r['id'];
            $created['classes'][] = $r['id'];
            $events = array_merge($events, $r['events']);
        }

        // ---- Properties
        $chk = $conn->prepare("SELECT id FROM cmdb_class_properties WHERE class_id = ? AND (property_key = ? OR LOWER(label) = ?)");
        foreach ($resolved['classes'] as $c) {
            $cid = $classId[$c['line']] ?? null;
            $order = $cid ? (int)$conn->query("SELECT COALESCE(MAX(display_order), 0) FROM cmdb_class_properties WHERE class_id = " . (int)$cid)->fetchColumn() : 0;
            foreach ($c['properties'] as $p) {
                if (!$take($p, $canClasses)) continue;
                $target = null;
                if ($p['type'] === 'object_ref') {
                    $target = $p['target_line'] !== null ? ($classId[$p['target_line']] ?? null) : $p['target_id'];
                }
                if (!$cid || ($p['type'] === 'object_ref' && !$target)) {
                    $skipped[] = ['line' => $p['line'], 'reason' => t('cmdb.plan.reason_parent_not_selected')];
                    continue;
                }
                $chk->execute([$cid, $p['key'], mb_strtolower($p['label'])]);
                if ($chk->fetchColumn()) { $existing++; continue; }
                if ($hook) $hook('property', $p['line']);
                $order += 10;
                $r = CmdbSchemaService::saveProperty($conn, $ctx, [
                    'class_id' => $cid, 'label' => $p['label'], 'property_key' => $p['key'], 'property_type' => $p['type'],
                    'target_class_id' => $target, 'is_required' => $p['required'], 'spreads_impact' => $p['spreads_impact'],
                    'display_order' => $order, 'options' => $p['options'],
                ], $no);
                $created['properties'][] = $r['id'];
                $events = array_merge($events, $r['events']);
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }

    foreach ($events as $ev) wf_emit($ev[0], $ev[1], (int)$ev[2], $ev[3]);

    return [
        'created' => $created,
        'counts' => array_map('count', $created),
        'skipped' => $skipped,
        'existing' => $existing,
    ];
}
