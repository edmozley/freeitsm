<?php
/**
 * CMDB - what the assistant may PROPOSE (GH #313 slices 6 and 7).
 *
 * Two proposal types, registered by cmdbAssistProposalTypes() in assistant.php:
 *
 *   schema   propose_schema {title, relationship_types, classes (with properties),
 *            usage, parent_child} - the plan shape of includes/cmdb/schema_plan.php.
 *            check:   cmdbPlanResolve(); refused (the reason goes back to the model)
 *                     when nothing in it would be created. Stores the plan and counts.
 *            preview: the stored plan RE-RESOLVED against the live CMDB, for
 *                     CmdbSchemaPreview (assets/js/cmdb-schema-preview.js).
 *            apply:   cmdbPlanApply() as the analyst - add-only, one transaction,
 *                     per-line capability - then the plan's usage and parent_child
 *                     are kept as `convention` facts (nothing in the schema holds them).
 *
 *   objects  propose_objects {title, objects:[{class_key, name, description,
 *            properties:{key: value}, parent, relationships:[{verb, to}]}]} - the
 *            configuration items it learned about, ALWAYS in the analyst's active
 *            company (the assistant's company), never another.
 *            check:   cmdbAssistObjectsResolve(); refused with every problem (unknown
 *                     class or property, a class named like a record - Person, Location,
 *                     Team... (cmdbPlanRecordClassNames(), the plan engine's list):
 *                     people and places are records a CI points at, never CIs - a
 *                     value of the wrong type, an unknown verb, a
 *                     parent or target that is neither in the proposal nor in this
 *                     company), or when every one is already there. At most 25.
 *            preview: re-resolved, for CmdbObjectPreview (assets/js/cmdb-object-preview.js).
 *            apply:   CmdbService as the analyst (company rules, required properties,
 *                     typed values) in ONE transaction - objects in dependency order,
 *                     then relationships; anything failing rolls everything back. A
 *                     second apply finds them there. cmdb.object.created after commit.
 *
 * Every line has a stable id - 'obj:<n>', 'rel:<n>:<m>' here, the plan engine's
 * own for a schema - which is what the preview's ticks send back as the selection.
 *
 * 🔑 ADD-ONLY, both types. An object whose name is already in that class in this
 * company is `exists` (skipped, never changed). Changes to what is there are advice
 * in the assistant's reply, never a proposal.
 *
 * @wiki CMDB-Developer-Guide
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../rbac.php';
require_once __DIR__ . '/../i18n.php';
require_once __DIR__ . '/../typed_fields.php';
require_once __DIR__ . '/../cmdb_record_refs.php';
require_once __DIR__ . '/../services/cmdb.php';
require_once __DIR__ . '/../services/cmdb_schema.php';
require_once __DIR__ . '/schema_plan.php';

const CMDB_ASSIST_MAX_OBJECTS = 25;   // configuration items in one proposal

/**
 * "3 new classes" / "1 new class": the one/other form of a count.
 *
 * @param string $key  under cmdb.assistant., with _one and _other variants
 * @param int $n  the count
 */
function cmdbAssistCount(string $key, int $n): string
{
    return t('cmdb.assistant.' . $key . ($n === 1 ? '_one' : '_other'), ['count' => $n]);
}

// ─── Schema ─────────────────────────────────────────────────────────────────

/** The propose_schema tool. */
function cmdbAssistSchemaTool(): array
{
    $str = ['type' => 'string'];
    $arr = function (array $props, array $req = []) { return ['type' => 'array', 'items' => ['type' => 'object', 'properties' => (object)$props, 'required' => $req]]; };
    return ['name' => 'propose_schema',
        'description' => 'Propose ONE plan of classes, properties and relationship types to ADD to the CMDB. It goes on a card the user previews and applies or dismisses. '
            . 'Add-only: a class already there gets only the new properties; nothing already there is changed or removed - say in your reply what you would change instead. '
            . 'People, teams, locations, companies, suppliers, contracts and analysts are property types (person, team, location, company, supplier, contract, analyst), never classes. '
            . 'object_ref points at another CI class (target: its key or name, here or in this plan); spreads_impact when its failure affects this CI.',
        'schema' => ['type' => 'object', 'properties' => (object)[
            'title' => ['type' => 'string', 'description' => 'Short: what the plan is for, e.g. "Services and what they run on"'],
            'relationship_types' => $arr([
                'verb' => $str, 'inverse_verb' => $str,
                'impact_direction' => ['type' => 'string', 'enum' => cmdbImpactDirections(), 'description' => 'to_from: if B fails A is affected (A runs on B); from_to: the other way; none'],
                'description' => $str, 'why' => $str,
            ], ['verb', 'inverse_verb', 'impact_direction']),
            'classes' => $arr([
                'key' => ['type' => 'string', 'description' => 'lower_case_with_underscores'], 'name' => $str, 'description' => $str, 'why' => $str,
                'properties' => $arr([
                    'label' => $str, 'key' => ['type' => 'string', 'description' => 'lower_case_with_underscores'],
                    'type' => ['type' => 'string', 'enum' => cmdbPropertyTypes()],
                    'required' => ['type' => 'boolean'],
                    'options' => ['type' => 'array', 'items' => $str, 'description' => 'dropdown only'],
                    'target' => ['type' => 'string', 'description' => 'object_ref only: the class it points at'],
                    'spreads_impact' => ['type' => 'boolean', 'description' => 'object_ref only'],
                    'why' => $str,
                ], ['label', 'type']),
            ], ['name']),
            'usage' => $arr(['from' => $str, 'verb' => $str, 'to' => $str], ['from', 'verb', 'to']),
            'parent_child' => $arr(['parent' => $str, 'child' => $str], ['parent', 'child']),
        ], 'required' => ['title']]];
}

/**
 * The plan from the model's arguments: only the plan's own fields, lists kept as lists.
 *
 * @param array $a  the tool arguments (untrusted)
 */
function cmdbAssistSchemaPlan(array $a): array
{
    $list = function ($v) { return array_values(array_filter(is_array($v) ? $v : [], 'is_array')); };
    return [
        'title' => mb_substr(trim((string)($a['title'] ?? '')), 0, 150),
        'relationship_types' => $list($a['relationship_types'] ?? []),
        'classes' => $list($a['classes'] ?? []),
        'usage' => $list($a['usage'] ?? []),
        'parent_child' => $list($a['parent_child'] ?? []),
    ];
}

/** How many classes, properties and relationship types a resolved plan would create. */
function cmdbAssistSchemaCounts(array $resolved): array
{
    $n = ['classes' => 0, 'properties' => 0, 'relationship_types' => 0];
    foreach ($resolved['relationship_types'] as $r) if ($r['action'] === 'create') $n['relationship_types']++;
    foreach ($resolved['classes'] as $c) {
        if ($c['action'] === 'create') $n['classes']++;
        foreach ($c['properties'] as $p) if ($p['action'] === 'create') $n['properties']++;
    }
    return $n;
}

/** Each line that will not be created for a reason the model can fix: "Server - Owner: reason". */
function cmdbAssistSchemaProblems(array $resolved): array
{
    $out = [];
    foreach ($resolved['relationship_types'] as $r) if (in_array($r['action'], ['invalid', 'conflict'], true)) $out[] = 'relationship type "' . $r['verb'] . '": ' . $r['reason'];
    foreach ($resolved['classes'] as $c) {
        if (in_array($c['action'], ['invalid', 'conflict'], true)) $out[] = 'class "' . $c['name'] . '": ' . $c['reason'];
        foreach ($c['properties'] as $p) if (in_array($p['action'], ['invalid', 'conflict'], true) && $c['action'] !== 'invalid') $out[] = 'property "' . $c['name'] . ' - ' . $p['label'] . '": ' . $p['reason'];
    }
    return $out;
}

/**
 * The check: resolve the plan; refuse one with nothing to create, saying why.
 *
 * @param array $company  from cmdbAssistCompany()
 * @param array $a  the tool arguments (untrusted)
 */
function cmdbAssistSchemaCheck(PDO $conn, ActorContext $ctx, array $company, array $a)
{
    $plan = cmdbAssistSchemaPlan($a);
    if (!$plan['classes'] && !$plan['relationship_types']) return 'The plan has no classes and no relationship types.';
    $resolved = cmdbPlanResolve($conn, $plan);
    $n = cmdbAssistSchemaCounts($resolved);
    $problems = cmdbAssistSchemaProblems($resolved);
    if (array_sum($n) === 0) {
        return 'nothing in it would be created - ' . ($problems ? implode('; ', $problems) : 'everything in it is already there')
            . '. Fix the plan and propose again, or tell the user it is all there.';
    }
    if ($plan['title'] === '') $plan['title'] = t('cmdb.assistant.schema_default_title');
    return ['plan' => $plan, 'counts' => $n, '_notes' => $problems ? 'These lines will be skipped: ' . implode('; ', $problems) . '.' : ''];
}

/** The card's line: "Services: 3 new classes, 14 properties, 1 relationship type". */
function cmdbAssistSchemaDescribe(array $args): string
{
    $n = (array)($args['counts'] ?? []);
    $parts = [];
    if (!empty($n['classes'])) $parts[] = cmdbAssistCount('n_new_classes', (int)$n['classes']);
    if (!empty($n['properties'])) $parts[] = cmdbAssistCount('n_properties', (int)$n['properties']);
    if (!empty($n['relationship_types'])) $parts[] = cmdbAssistCount('n_rel_types', (int)$n['relationship_types']);
    return trim((string)($args['plan']['title'] ?? '')) . ': ' . implode(', ', $parts);
}

/**
 * The preview: the stored plan resolved against the CMDB as it is NOW.
 *
 * @param array $company  from cmdbAssistCompany()
 * @param array $args  the stored proposal arguments
 */
function cmdbAssistSchemaPreview(PDO $conn, ActorContext $ctx, array $company, array $args): array
{
    return [
        'resolved' => cmdbPlanResolve($conn, (array)($args['plan'] ?? [])),
        'can' => ['relationship_types' => $ctx->actorId > 0 && analystHasCapability($conn, $ctx->actorId, Cap::CMDB_RELATIONSHIP_TYPES)],
        'multi_company' => isMultiTenant($conn),
    ];
}

/**
 * Apply: the ticked lines (all creatable ones when $opts['selection'] is null) through
 * cmdbPlanApply() as the analyst, then the plan's usage and parent_child as convention
 * facts of this company - only those whose classes are both there afterwards.
 *
 * @param array $company  from cmdbAssistCompany()
 * @param array $args  the stored proposal arguments
 * @param array $opts  selection: line ids or null; on_item: the plan engine's test hook
 */
function cmdbAssistSchemaApply(PDO $conn, ActorContext $ctx, array $company, array $args, array $opts = []): array
{
    $plan = (array)($args['plan'] ?? []);
    $sel = $opts['selection'] ?? null;
    if ($sel === null) {
        $resolved = cmdbPlanResolve($conn, $plan);
        $sel = [];
        foreach ($resolved['relationship_types'] as $r) if ($r['action'] === 'create') $sel[] = $r['line'];
        foreach ($resolved['classes'] as $c) {
            if ($c['action'] === 'create') $sel[] = $c['line'];
            foreach ($c['properties'] as $p) if ($p['action'] === 'create') $sel[] = $p['line'];
        }
    }
    $res = cmdbPlanApply($conn, $ctx, $plan, array_values(array_filter((array)$sel, 'is_string')), array_intersect_key($opts, ['on_item' => 1]));
    $res['conventions'] = cmdbAssistSchemaConventions($conn, $ctx, (int)$company['id'], $plan);
    return $res;
}

/**
 * Keep how the plan's classes link (usage, parent_child) as convention facts. Returns
 * how many were noted. Never throws - the schema is already committed.
 *
 * @param int $tenantId  the company the facts are about
 * @param array $plan  the plan
 */
function cmdbAssistSchemaConventions(PDO $conn, ActorContext $ctx, int $tenantId, array $plan): int
{
    $byFold = [];
    foreach ($conn->query("SELECT class_key, name FROM cmdb_classes")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $byFold[cmdbPlanFold($c['class_key'])] = $c['name'];
        $byFold[cmdbPlanFold($c['name'])] = $c['name'];
    }
    foreach ((array)($plan['classes'] ?? []) as $c) {   // a plan's key names its class
        $k = cmdbPlanFold((string)($c['key'] ?? '')); $n = cmdbPlanFold((string)($c['name'] ?? ''));
        if ($k !== '' && !isset($byFold[$k]) && isset($byFold[$n])) $byFold[$k] = $byFold[$n];
    }
    $name = function ($v) use ($byFold) { return $byFold[cmdbPlanFold((string)$v)] ?? null; };
    $noted = 0;
    $note = function (string $subject, string $statement) use ($conn, $ctx, $tenantId, &$noted) {
        try { cmdbAssistRemember($conn, $tenantId, $ctx->actorId, ['topic' => 'convention', 'subject' => $subject, 'statement' => $statement]); $noted++; }
        catch (Throwable $e) { error_log('cmdb assistant convention: ' . $e->getMessage()); }
    };
    foreach ((array)($plan['usage'] ?? []) as $u) {
        if (!is_array($u)) continue;
        $from = $name($u['from'] ?? ''); $to = $name($u['to'] ?? ''); $verb = trim((string)($u['verb'] ?? ''));
        if ($from === null || $to === null || $verb === '') continue;
        $p = ['from' => $from, 'verb' => $verb, 'to' => $to];
        $note(t('cmdb.assistant.conv_usage_subject', $p), t('cmdb.assistant.conv_usage', $p));
    }
    foreach ((array)($plan['parent_child'] ?? []) as $pc) {
        if (!is_array($pc)) continue;
        $parent = $name($pc['parent'] ?? ''); $child = $name($pc['child'] ?? '');
        if ($parent === null || $child === null) continue;
        $p = ['parent' => $parent, 'child' => $child];
        $note(t('cmdb.assistant.conv_parent_subject', $p), t('cmdb.assistant.conv_parent', $p));
    }
    return $noted;
}

// ─── Configuration items ────────────────────────────────────────────────────

/** The propose_objects tool. */
function cmdbAssistObjectsTool(): array
{
    $str = ['type' => 'string'];
    return ['name' => 'propose_objects',
        'description' => 'Propose configuration items (CIs) to create in THIS company - the services, applications, servers and so on the user told you about. '
            . 'Never invent names: only what the user said. Never people, teams, locations, companies, suppliers or contracts - they are records a CI points at through a property of that type (list_records names them); CIs in a class named like one (Person, Location...) are refused. At most ' . CMDB_ASSIST_MAX_OBJECTS . ' per call. It goes on a card the user previews and applies or dismisses; '
            . 'one whose name is already in that class here is skipped. class_key and property keys from list_classes / get_class; values as text '
            . '(a dropdown: one of its options; a person, team, location, supplier... property: the record\'s name; an object_ref: the CI\'s name). '
            . 'parent and relationship targets name another CI in this proposal or already in this company; verbs from list_relationship_types.',
        'schema' => ['type' => 'object', 'properties' => (object)[
            'title' => ['type' => 'string', 'description' => 'Short, e.g. "Payroll and what it runs on"'],
            'objects' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => (object)[
                'class_key' => $str, 'name' => $str,
                'description' => ['type' => 'string', 'description' => 'One sentence; kept in a "description" property when the class has one'],
                'properties' => ['type' => 'object', 'description' => '{"property_key": "value"}'],
                'parent' => ['type' => 'string', 'description' => 'The name of the CI it sits under'],
                'relationships' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => (object)['verb' => $str, 'to' => $str], 'required' => ['verb', 'to']]],
            ], 'required' => ['class_key', 'name']]],
        ], 'required' => ['title', 'objects']]];
}

/**
 * The proposal from the model's arguments: names trimmed, only the known fields.
 *
 * @param array $a  the tool arguments (untrusted)
 */
function cmdbAssistObjectsClean(array $a): array
{
    $objs = [];
    foreach (array_values(array_filter(is_array($a['objects'] ?? null) ? $a['objects'] : [], 'is_array')) as $o) {
        $props = [];
        $raw = is_array($o['properties'] ?? null) ? $o['properties'] : [];
        foreach ($raw as $k => $v) {
            if (is_array($v) && isset($v['key'])) { $k = (string)$v['key']; $v = $v['value'] ?? null; }   // [{key, value}] too
            if (is_array($v) || is_object($v)) continue;
            $props[trim((string)$k)] = $v;
        }
        $rels = [];
        foreach (array_filter(is_array($o['relationships'] ?? null) ? $o['relationships'] : [], 'is_array') as $r) {
            $rels[] = ['verb' => trim((string)($r['verb'] ?? '')), 'to' => trim((string)($r['to'] ?? ''))];
        }
        $objs[] = [
            'class_key' => trim((string)($o['class_key'] ?? $o['class'] ?? '')),
            'name' => trim(preg_replace('/\s+/u', ' ', (string)($o['name'] ?? ''))),
            'description' => mb_substr(trim((string)($o['description'] ?? '')), 0, 1000),
            'properties' => $props,
            'parent' => trim((string)($o['parent'] ?? '')),
            'relationships' => $rels,
        ];
    }
    return ['title' => mb_substr(trim((string)($a['title'] ?? '')), 0, 150), 'objects' => $objs];
}

/**
 * What each object and relationship of a proposal would do in this company, now.
 * Reads only - run at check, preview and apply.
 *
 * Returns {title, company: {id, name}, objects: [...], counts: {create, exists, invalid}, notes: [..]}
 * where each object is {line, name, class_key, class_id, class_name, action create | exists |
 * invalid, reason, existing_id, description, parent: {name, line, id} | null, depends_on,
 * properties: [{key, label, type, type_label, value, store, ref_line, note}],
 * relationships: [{line, verb, to, sentence, type_id, forward, to_line, to_id, action, reason, depends_on}]}.
 * `store` is what CmdbService is given (a ref's id; null with ref_line set = a CI in this
 * proposal); a record name that matches nothing is left empty with a note, never refused.
 *
 * @param array $company  from cmdbAssistCompany()
 * @param array $args  cmdbAssistObjectsClean() output
 * @param ?array $reach  the companies the analyst can reach (ActorContext::companyScope), for record lookups
 */
function cmdbAssistObjectsResolve(PDO $conn, array $company, array $args, ?array $reach = null): array
{
    $tid = (int)$company['id'];
    [$scope, $sp] = tenantScopeSqlFor($conn, $tid, 'o.tenant_id');
    $low = function ($s) { return mb_strtolower(trim((string)$s)); };

    $classes = $conn->query("SELECT id, class_key, name FROM cmdb_classes WHERE COALESCE(is_active, 1) = 1")->fetchAll(PDO::FETCH_ASSOC);
    $classByKey = []; $classByFold = []; $classById = [];
    foreach ($classes as $c) {
        $classById[(int)$c['id']] = $c;
        $classByKey[$c['class_key']] = $c;
        $classByFold[cmdbPlanFold($c['name'])] = $classByFold[cmdbPlanFold($c['name'])] ?? $c;
        $classByFold[cmdbPlanFold($c['class_key'])] = $classByFold[cmdbPlanFold($c['class_key'])] ?? $c;
    }
    $defs = [];
    foreach ($conn->query("SELECT id, class_id, property_key, label, property_type, target_class_id, is_required FROM cmdb_class_properties ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $defs[(int)$p['class_id']][] = $p;
    }
    $optionsOf = function (int $pid) use ($conn) {
        $st = $conn->prepare("SELECT option_value FROM cmdb_class_property_options WHERE property_id = ? ORDER BY display_order, id");
        $st->execute([$pid]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    };
    $relTypes = $conn->query("SELECT id, verb, inverse_verb FROM cmdb_relationship_types WHERE COALESCE(is_active, 1) = 1")->fetchAll(PDO::FETCH_ASSOC);
    $recordNames = cmdbPlanRecordClassNames();   // the plan engine's list: classes that are really records
    // CIs of this company by name (any class when $classId is null): [id, ...]
    $findCi = function (string $name, ?int $classId) use ($conn, $scope, $sp): array {
        $st = $conn->prepare("SELECT o.id FROM cmdb_objects o WHERE o.name = ?" . ($classId ? " AND o.class_id = ?" : '') . $scope . " ORDER BY o.id LIMIT 3");
        $st->execute(array_merge([$name], $classId ? [$classId] : [], $sp));
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    };

    $out = ['title' => (string)($args['title'] ?? ''), 'company' => ['id' => $tid, 'name' => (string)($company['name'] ?? '')],
            'objects' => [], 'counts' => ['create' => 0, 'exists' => 0, 'invalid' => 0], 'notes' => []];

    // ---- First pass: what each object is
    $byName = [];   // lower name => index (the first in the proposal)
    $seen = [];
    foreach (array_values((array)($args['objects'] ?? [])) as $i => $o) {
        $name = (string)($o['name'] ?? '');
        $ck = (string)($o['class_key'] ?? '');
        $cls = $ck !== '' ? ($classByKey[$ck] ?? $classByFold[cmdbPlanFold($ck)] ?? null) : null;
        $ob = [
            'line' => 'obj:' . $i, 'name' => $name, 'class_key' => $cls ? $cls['class_key'] : $ck,
            'class_id' => $cls ? (int)$cls['id'] : null, 'class_name' => $cls ? $cls['name'] : $ck,
            'action' => 'create', 'reason' => '', 'existing_id' => null, 'description' => (string)($o['description'] ?? ''),
            'parent' => null, 'depends_on' => [], 'properties' => [], 'relationships' => [],
        ];
        if ($i >= CMDB_ASSIST_MAX_OBJECTS) {
            $ob['action'] = 'invalid'; $ob['reason'] = t('cmdb.assistant.r_too_many', ['max' => CMDB_ASSIST_MAX_OBJECTS]);
        } elseif ($name === '') {
            $ob['action'] = 'invalid'; $ob['reason'] = t('cmdb.plan.reason_no_name');
        } elseif (mb_strlen($name) > 255) {
            $ob['action'] = 'invalid'; $ob['reason'] = t('cmdb.plan.reason_too_long');
        } elseif (!$cls) {
            $ob['action'] = 'invalid'; $ob['reason'] = t('cmdb.assistant.r_no_class', ['class' => $ck]);
        } elseif ($rec = $recordNames[cmdbPlanFold($cls['name'])] ?? $recordNames[cmdbPlanFold($cls['class_key'])] ?? null) {
            // An old "Person" / "Location" class: people and places are records, never CIs.
            $ob['action'] = 'invalid';
            $ob['reason'] = t('cmdb.assistant.r_record_class', ['class' => $cls['name'], 'type' => cmdbPlanTypeLabel($rec)]);
        } elseif (isset($seen[$cls['id'] . ':' . $low($name)])) {
            $ob['action'] = 'invalid'; $ob['reason'] = t('cmdb.plan.reason_listed_twice');
        } elseif ($ids = $findCi($name, (int)$cls['id'])) {
            $ob['action'] = 'exists'; $ob['existing_id'] = $ids[0];
            $ob['reason'] = t('cmdb.assistant.r_ci_exists', ['company' => $out['company']['name']]);
        }
        if ($cls) $seen[$cls['id'] . ':' . $low($name)] = true;
        if ($ob['action'] !== 'invalid' && !isset($byName[$low($name)])) $byName[$low($name)] = $i;
        $out['objects'][$i] = $ob;
        $out['objects'][$i]['_src'] = $o;
    }

    // A CI named $name: one in this proposal (not $self; of $classId when given) or one
    // already in this company. ['line' => ?, 'id' => ?] or a reason string.
    $target = function (string $name, int $self, ?int $classId) use (&$out, $byName, $low, $findCi) {
        $idx = $byName[$low($name)] ?? null;
        if ($idx !== null && $idx !== $self && (!$classId || $out['objects'][$idx]['class_id'] === $classId)) {
            $t = $out['objects'][$idx];
            return $t['action'] === 'exists' ? ['line' => null, 'id' => $t['existing_id']] : ['line' => $t['line'], 'id' => null];
        }
        $ids = $findCi($name, $classId);
        if (count($ids) === 1) return ['line' => null, 'id' => $ids[0]];
        if (count($ids) > 1) return t('cmdb.assistant.r_ci_ambiguous', ['name' => $name]);
        return t('cmdb.assistant.r_no_ci', ['name' => $name]);
    };

    // ---- Second pass: properties, parent, relationships
    foreach ($out['objects'] as $i => &$ob) {
        $src = $ob['_src'];
        unset($ob['_src']);
        if ($ob['action'] === 'invalid') {
            foreach ((array)($src['relationships'] ?? []) as $j => $r) {
                $ob['relationships'][] = ['line' => 'rel:' . $i . ':' . $j, 'verb' => (string)($r['verb'] ?? ''), 'to' => (string)($r['to'] ?? ''), 'sentence' => '',
                    'type_id' => null, 'forward' => true, 'to_line' => null, 'to_id' => null, 'action' => 'invalid', 'reason' => t('cmdb.assistant.r_ci_skipped'), 'depends_on' => []];
            }
            continue;
        }
        $problems = [];
        if ($ob['action'] === 'create') {
            $classDefs = $defs[$ob['class_id']] ?? [];
            $given = (array)($src['properties'] ?? []);
            if ($ob['description'] !== '') {   // into a "description" property when the class has one
                foreach ($classDefs as $d) if ($d['property_key'] === 'description' && !array_key_exists('description', $given) && in_array($d['property_type'], ['text'], true)) $given['description'] = $ob['description'];
            }
            $set = [];
            foreach ($given as $k => $v) {
                $def = null;
                foreach ($classDefs as $d) if ($d['property_key'] === $k || $low($d['label']) === $low($k)) { $def = $d; break; }
                if (!$def) { $problems[] = t('cmdb.assistant.r_no_property', ['class' => $ob['class_name'], 'key' => $k]); continue; }
                if ($v === null || trim((string)(is_bool($v) ? ($v ? '1' : '0') : $v)) === '') continue;
                $type = $def['property_type'];
                $pl = ['key' => $def['property_key'], 'label' => $def['label'], 'type' => $type, 'type_label' => cmdbPlanTypeLabel($type),
                       'value' => is_bool($v) ? ($v ? 'true' : 'false') : (string)$v, 'store' => null, 'ref_line' => null, 'note' => ''];
                $sv = trim($pl['value']);
                if (in_array($type, ['text', 'url', 'email'], true)) {
                    $pl['store'] = mb_substr($sv, 0, 4000);
                } elseif ($type === 'number') {
                    if (!is_numeric($sv)) { $problems[] = t('cmdb.assistant.r_bad_number', ['label' => $def['label']]); continue; }
                    $pl['store'] = $sv;
                } elseif ($type === 'date') {
                    try { TypedFields::parseDate($sv, $def['property_key']); $pl['store'] = $sv; }
                    catch (ServiceError $e) { $problems[] = t('cmdb.assistant.r_bad_date', ['label' => $def['label']]); continue; }
                } elseif ($type === 'boolean') {
                    $b = ['1' => 1, 'true' => 1, 'yes' => 1, 'y' => 1, '0' => 0, 'false' => 0, 'no' => 0, 'n' => 0][$low($sv)] ?? null;
                    if ($b === null) { $problems[] = t('cmdb.assistant.r_bad_boolean', ['label' => $def['label']]); continue; }
                    $pl['store'] = $b; $pl['value'] = $b ? t('cmdb.assistant.yes') : t('cmdb.assistant.no');
                } elseif ($type === 'dropdown') {
                    $opts = $optionsOf((int)$def['id']);
                    $hit = null;
                    foreach ($opts as $op) if ($low($op) === $low($sv)) { $hit = $op; break; }
                    if ($hit === null) { $problems[] = t('cmdb.assistant.r_bad_option', ['label' => $def['label'], 'options' => implode(', ', $opts)]); continue; }
                    $pl['store'] = $hit; $pl['value'] = $hit;
                } elseif ($type === 'object_ref') {
                    $tg = $target($sv, $i, $def['target_class_id'] ? (int)$def['target_class_id'] : null);
                    if (is_string($tg)) { $problems[] = $def['label'] . ': ' . $tg; continue; }
                    $pl['store'] = $tg['id']; $pl['ref_line'] = $tg['line'];
                    if ($tg['line']) $ob['depends_on'][] = $tg['line'];
                } elseif (cmdbIsRecordType($type)) {
                    if (!cmdbRecordRefsReady($conn)) {
                        $pl['note'] = t('cmdb.plan.reason_records_not_ready');
                    } else {
                        $found = cmdbRecordSearch($conn, $type, $sv, $tid, $reach, 10);
                        $exact = array_values(array_filter($found, function ($r) use ($low, $sv) { return $low($r['name']) === $low($sv); }));
                        $pick = count($exact) === 1 ? $exact[0] : (count($found) === 1 && !$exact ? $found[0] : null);
                        if ($pick) { $pl['store'] = $pick['id']; $pl['value'] = $pick['name']; }
                        else $pl['note'] = t(count($found) > 1 ? 'cmdb.assistant.r_record_several' : 'cmdb.assistant.r_record_none', ['type' => cmdbPlanTypeLabel($type), 'name' => $sv]);
                    }
                    if ($pl['note'] !== '') $out['notes'][] = $ob['name'] . ' - ' . $pl['note'];
                } else {
                    $problems[] = t('cmdb.plan.reason_bad_type', ['type' => $type]);
                    continue;
                }
                $set[$def['property_key']] = true;
                $ob['properties'][] = $pl;
            }
            foreach ($classDefs as $d) {
                if ((int)$d['is_required'] !== 1 || isset($set[$d['property_key']])) continue;
                $ok = false;
                foreach ($ob['properties'] as $pl) if ($pl['key'] === $d['property_key'] && ($pl['store'] !== null || $pl['ref_line'])) $ok = true;
                if (!$ok) $problems[] = t('cmdb.assistant.r_required', ['label' => $d['label']]);
            }
            $pn = (string)($src['parent'] ?? '');
            if ($pn !== '') {
                $tg = $target($pn, $i, null);
                if (is_string($tg)) $problems[] = t('cmdb.assistant.r_parent', ['reason' => $tg]);
                else {
                    $ob['parent'] = ['name' => $pn, 'line' => $tg['line'], 'id' => $tg['id']];
                    if ($tg['line']) $ob['depends_on'][] = $tg['line'];
                }
            }
            $ob['depends_on'] = array_values(array_unique($ob['depends_on']));
        }
        if ($problems) { $ob['action'] = 'invalid'; $ob['reason'] = implode('; ', $problems); }

        // Relationships - also of an object already there (add-only: a new link to it)
        $pairs = [];
        foreach (array_values((array)($src['relationships'] ?? [])) as $j => $r) {
            $verb = (string)($r['verb'] ?? ''); $to = (string)($r['to'] ?? '');
            $rl = ['line' => 'rel:' . $i . ':' . $j, 'verb' => $verb, 'to' => $to, 'sentence' => $ob['name'] . ' ' . $verb . ' ' . $to,
                   'type_id' => null, 'forward' => true, 'to_line' => null, 'to_id' => null, 'action' => 'create', 'reason' => '', 'depends_on' => []];
            $rt = null;
            foreach ($relTypes as $t) {
                if (cmdbPlanVerbNorm($t['verb']) === cmdbPlanVerbNorm($verb)) { $rt = $t; $rl['forward'] = true; break; }
                if (cmdbPlanVerbNorm($t['inverse_verb']) === cmdbPlanVerbNorm($verb)) { $rt = $t; $rl['forward'] = false; break; }
            }
            $tg = $to !== '' ? $target($to, $i, null) : t('cmdb.plan.reason_no_name');
            if ($ob['action'] === 'invalid') {
                $rl['action'] = 'invalid'; $rl['reason'] = t('cmdb.assistant.r_ci_skipped');
            } elseif (!$rt) {
                $rl['action'] = 'invalid'; $rl['reason'] = t('cmdb.assistant.r_no_verb', ['verb' => $verb]);
            } elseif (is_string($tg)) {
                $rl['action'] = 'invalid'; $rl['reason'] = $tg;
            } else {
                $rl['type_id'] = (int)$rt['id']; $rl['to_line'] = $tg['line']; $rl['to_id'] = $tg['id'];
                if ($ob['action'] === 'create') $rl['depends_on'][] = $ob['line'];
                if ($tg['line']) $rl['depends_on'][] = $tg['line'];
                $key = ($tg['line'] ?? $tg['id']) . '|' . $rt['id'] . '|' . ($rl['forward'] ? 1 : 0);
                if (isset($pairs[$key])) { $rl['action'] = 'invalid'; $rl['reason'] = t('cmdb.plan.reason_listed_twice'); }
                elseif ($ob['action'] === 'exists' && $tg['id']) {
                    [$f, $tt] = $rl['forward'] ? [$ob['existing_id'], $tg['id']] : [$tg['id'], $ob['existing_id']];
                    $st = $conn->prepare("SELECT id FROM cmdb_object_relationships WHERE from_object_id = ? AND to_object_id = ? AND relationship_type_id = ? LIMIT 1");
                    $st->execute([$f, $tt, $rl['type_id']]);
                    if ($st->fetchColumn()) { $rl['action'] = 'exists'; $rl['reason'] = t('cmdb.plan.reason_prop_exists'); }
                    elseif ($f === $tt) { $rl['action'] = 'invalid'; $rl['reason'] = t('cmdb.assistant.r_self'); }
                }
                $pairs[$key] = true;
            }
            $ob['relationships'][] = $rl;
        }
    }
    unset($ob);

    // Parents that go round in a circle within the proposal
    foreach ($out['objects'] as $i => $ob) {
        $cur = $ob['parent']['line'] ?? null; $hops = 0;
        while ($cur !== null && $hops++ < CMDB_ASSIST_MAX_OBJECTS + 1) {
            if ($cur === $ob['line']) { $out['objects'][$i]['action'] = 'invalid'; $out['objects'][$i]['reason'] = t('cmdb.assistant.r_parent_loop'); break; }
            $idx = (int)substr($cur, 4);
            $cur = $out['objects'][$idx]['parent']['line'] ?? null;
        }
    }
    // A line that needs a skipped one is skipped too
    $invalid = [];
    foreach ($out['objects'] as $ob) if ($ob['action'] === 'invalid') $invalid[$ob['line']] = $ob['name'];
    for ($pass = 0; $pass < 3; $pass++) {
        foreach ($out['objects'] as $i => $ob) {
            if ($ob['action'] !== 'create') continue;
            foreach ($ob['depends_on'] as $d) if (isset($invalid[$d])) {
                $out['objects'][$i]['action'] = 'invalid'; $out['objects'][$i]['reason'] = t('cmdb.plan.reason_target_skipped', ['target' => $invalid[$d]]);
                $invalid[$ob['line']] = $ob['name']; break;
            }
        }
    }
    foreach ($out['objects'] as $i => $ob) {
        foreach ($ob['relationships'] as $j => $rl) {
            if ($rl['action'] !== 'create') continue;
            foreach ($rl['depends_on'] as $d) if (isset($invalid[$d])) {
                $out['objects'][$i]['relationships'][$j]['action'] = 'invalid';
                $out['objects'][$i]['relationships'][$j]['reason'] = t('cmdb.plan.reason_target_skipped', ['target' => $invalid[$d]]);
                break;
            }
        }
    }
    $out['objects'] = array_values($out['objects']);
    foreach ($out['objects'] as $ob) {
        $out['counts'][$ob['action']]++;
        foreach ($ob['relationships'] as $rl) $out['counts'][$rl['action']]++;
    }
    return $out;
}

/** How many objects and relationships a resolved proposal would create. */
function cmdbAssistObjectsCounts(array $resolved): array
{
    $n = ['objects' => 0, 'relationships' => 0];
    foreach ($resolved['objects'] as $ob) {
        if ($ob['action'] === 'create') $n['objects']++;
        foreach ($ob['relationships'] as $rl) if ($rl['action'] === 'create') $n['relationships']++;
    }
    return $n;
}

/**
 * The check: every problem back to the model at once; nothing to create is refused too.
 *
 * @param array $company  from cmdbAssistCompany() - the only company it can propose for
 * @param array $a  the tool arguments (untrusted)
 */
function cmdbAssistObjectsCheck(PDO $conn, ActorContext $ctx, array $company, array $a)
{
    $args = cmdbAssistObjectsClean($a);
    if (!$args['objects']) return 'there are no objects in it.';
    if (count($args['objects']) > CMDB_ASSIST_MAX_OBJECTS) return 'at most ' . CMDB_ASSIST_MAX_OBJECTS . ' configuration items in one proposal - split it.';
    $r = cmdbAssistObjectsResolve($conn, $company, $args, $ctx->companyScope);
    $problems = []; $there = [];
    foreach ($r['objects'] as $ob) {
        if ($ob['action'] === 'invalid') $problems[] = '"' . $ob['name'] . '": ' . $ob['reason'];
        if ($ob['action'] === 'exists') $there[] = $ob['name'];
        foreach ($ob['relationships'] as $rl) if ($rl['action'] === 'invalid' && $ob['action'] !== 'invalid') $problems[] = '"' . $rl['sentence'] . '": ' . $rl['reason'];
    }
    if ($problems) return 'fix these and propose again (or ask the user) - ' . implode('; ', $problems) . '.';
    $n = cmdbAssistObjectsCounts($r);
    if (array_sum($n) === 0) return 'nothing to create - they are all already in this company' . ($there ? ': ' . implode(', ', $there) : '') . '.';
    if ($args['title'] === '') $args['title'] = t('cmdb.assistant.objects_default_title');
    $notes = [];
    if ($there) $notes[] = 'Already in this company, skipped: ' . implode(', ', $there) . '.';
    if ($r['notes']) $notes[] = 'Left empty: ' . implode('; ', $r['notes']) . '.';
    return $args + ['company_id' => (int)$company['id'], 'company_name' => (string)$company['name'], 'counts' => $n, '_notes' => implode(' ', $notes)];
}

/** The card's line: "Payroll: 4 configuration items and 3 relationships in Acme". */
function cmdbAssistObjectsDescribe(array $args): string
{
    $n = (array)($args['counts'] ?? []);
    $what = cmdbAssistCount('n_cis', (int)($n['objects'] ?? 0));
    if (!empty($n['relationships'])) $what = t('cmdb.assistant.and', ['a' => $what, 'b' => cmdbAssistCount('n_relationships', (int)$n['relationships'])]);
    $co = (string)($args['company_name'] ?? '');
    return trim((string)($args['title'] ?? '')) . ': ' . ($co !== '' ? t('cmdb.assistant.in_company', ['what' => $what, 'company' => $co]) : $what);
}

/**
 * The preview: re-resolved against this company now.
 *
 * @param array $company  from cmdbAssistCompany()
 * @param array $args  the stored proposal arguments
 */
function cmdbAssistObjectsPreview(PDO $conn, ActorContext $ctx, array $company, array $args): array
{
    if ((int)($args['company_id'] ?? 0) !== (int)$company['id']) throw new ServiceError('forbidden', 'forbidden', t('cmdb.assistant.err_other_company'));
    return ['resolved' => cmdbAssistObjectsResolve($conn, $company, $args, $ctx->companyScope), 'multi_company' => !empty($company['multi'])];
}

/**
 * Apply: the ticked objects and relationships (every line not refused when $opts['selection']
 * is null) in this company, as the analyst, in ONE transaction. Objects first, each after
 * the ones it needs (its parent, the CIs its properties point at); then relationships.
 * Anything failing rolls everything back and is rethrown. cmdb.object.created fires
 * for each new CI after the commit.
 *
 * Returns ['created' => ['objects' => [ids], 'relationships' => [ids]], 'counts', 'skipped' => [[line, reason]], 'existing' => n].
 *
 * @param array $company  from cmdbAssistCompany() - must be the company it was proposed for
 * @param array $args  the stored proposal arguments
 * @param array $opts  selection: line ids or null; on_item: callable(string $kind, string $line) before each write (tests)
 */
function cmdbAssistObjectsApply(PDO $conn, ActorContext $ctx, array $company, array $args, array $opts = []): array
{
    if ((int)($args['company_id'] ?? 0) !== (int)$company['id']) throw new ServiceError('forbidden', 'forbidden', t('cmdb.assistant.err_other_company'));
    // Creating CIs needs CMDB module access (api/cmdb/save_object.php asks no capability).
    if ($ctx->actorId <= 0 || !function_exists('analystCanAccessModule') || !analystCanAccessModule($conn, $ctx->actorId, 'cmdb')) {
        throw new ServiceError('forbidden', 'forbidden', t('cmdb.assistant.err_no_ci_access'));
    }
    $r = cmdbAssistObjectsResolve($conn, $company, $args, $ctx->companyScope);
    $sel = $opts['selection'] ?? null;
    if ($sel === null) {
        $sel = [];
        foreach ($r['objects'] as $ob) {   // what is there already is counted as such
            if ($ob['action'] !== 'invalid') $sel[] = $ob['line'];
            foreach ($ob['relationships'] as $rl) if ($rl['action'] !== 'invalid') $sel[] = $rl['line'];
        }
    }
    $want = array_flip(array_map('strval', (array)$sel));
    $hook = $opts['on_item'] ?? null;
    $ids = [];        // line => CI id, made now or already there
    $created = ['objects' => [], 'relationships' => []];
    $skipped = []; $existing = 0;
    foreach ($r['objects'] as $ob) if ($ob['action'] === 'exists') $ids[$ob['line']] = (int)$ob['existing_id'];

    $conn->beginTransaction();
    try {
        $todo = [];
        foreach ($r['objects'] as $ob) {
            if (!isset($want[$ob['line']])) continue;
            if ($ob['action'] === 'exists') { $existing++; continue; }
            if ($ob['action'] !== 'create') { $skipped[] = ['line' => $ob['line'], 'reason' => $ob['reason']]; continue; }
            $todo[$ob['line']] = $ob;
        }
        $progress = true;
        while ($todo && $progress) {
            $progress = false;
            foreach ($todo as $line => $ob) {
                $wait = false;
                foreach ($ob['depends_on'] as $d) if (!isset($ids[$d])) { $wait = isset($todo[$d]); if (!$wait) { $skipped[] = ['line' => $line, 'reason' => t('cmdb.plan.reason_parent_not_selected')]; unset($todo[$line]); continue 2; } }
                if ($wait) continue;
                $props = [];
                foreach ($ob['properties'] as $p) {
                    $v = $p['ref_line'] ? ($ids[$p['ref_line']] ?? null) : $p['store'];
                    if ($v !== null) $props[$p['key']] = $v;
                }
                $parent = $ob['parent'] ? ($ob['parent']['line'] ? ($ids[$ob['parent']['line']] ?? null) : $ob['parent']['id']) : null;
                if ($hook) $hook('object', $line);
                $id = CmdbService::createInTransaction($conn, $ctx, [
                    'class_id' => $ob['class_id'], 'name' => $ob['name'], 'parent_id' => $parent,
                    'tenant_id' => (int)$company['id'], 'properties' => $props,
                ]);
                $ids[$line] = $id;
                $created['objects'][] = $id;
                unset($todo[$line]);
                $progress = true;
            }
        }
        foreach ($todo as $line => $ob) $skipped[] = ['line' => $line, 'reason' => t('cmdb.assistant.r_waits')];

        $chk = $conn->prepare("SELECT id FROM cmdb_object_relationships WHERE from_object_id = ? AND to_object_id = ? AND relationship_type_id = ? LIMIT 1");
        foreach ($r['objects'] as $ob) {
            foreach ($ob['relationships'] as $rl) {
                if (!isset($want[$rl['line']])) continue;
                if ($rl['action'] === 'exists') { $existing++; continue; }
                if ($rl['action'] !== 'create') { $skipped[] = ['line' => $rl['line'], 'reason' => $rl['reason']]; continue; }
                $me = $ids[$ob['line']] ?? null;
                $to = $rl['to_line'] ? ($ids[$rl['to_line']] ?? null) : $rl['to_id'];
                if (!$me || !$to) { $skipped[] = ['line' => $rl['line'], 'reason' => t('cmdb.plan.reason_parent_not_selected')]; continue; }
                [$from, $dest] = $rl['forward'] ? [$me, $to] : [$to, $me];
                $chk->execute([$from, $dest, $rl['type_id']]);
                if ($chk->fetchColumn()) { $existing++; continue; }
                if ($hook) $hook('relationship', $rl['line']);
                $res = CmdbService::createRelationship($conn, $ctx, (int)$from, ['to_object_id' => (int)$dest, 'relationship_type_id' => $rl['type_id']]);
                $created['relationships'][] = (int)$res['id'];
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    foreach ($created['objects'] as $id) CmdbService::announceCreated($conn, $id);
    return ['created' => $created, 'counts' => array_map('count', $created), 'skipped' => $skipped, 'existing' => $existing];
}
