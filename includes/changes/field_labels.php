<?php
/**
 * A change form field's own name (GH #435).
 *
 * Every field on the change form has a standard name from the language files
 * ("Description", "Priority"...), shown in each analyst's language. An admin can give a
 * field its own name in Changes -> Settings -> Fields ("Implementation Plan"); it is kept in
 * change_field_layout.label and shown, as typed, wherever the field's name appears - the
 * form, the detail view, the PDF, the list and table columns, the change history and the
 * approval and template settings. Blank = the standard translated name, as before.
 *
 * Only the NAME changes. The field is the same field underneath: the calendar, approvals,
 * templates, filters, the REST API and workflows keep using it exactly as before - which is
 * why renaming is the answer to "we call it something else", and hiding a built-in field to
 * add a look-alike is not.
 *
 * A custom name is one language: the word the admin typed, for everyone. That is how type,
 * status, priority, impact, category and section names already work.
 */

// The standard names are translated: endpoints do not load the translator themselves.
require_once __DIR__ . '/../i18n.php';

/**
 * The fields that can be given their own name, and the translation key of each one's
 * standard name. CAB and Attachments are left out: they are composite widgets, not a box
 * with a name.
 */
const CHANGE_FIELD_STANDARD_NAMES = [
    'title'        => 'change-management.editor.title_label',
    'change_type'  => 'change-management.editor.change_type',
    'status'       => 'change-management.editor.status',
    'priority'     => 'change-management.editor.priority',
    'impact'       => 'change-management.editor.impact',
    'category'     => 'change-management.editor.category',
    'requester'    => 'change-management.editor.requester',
    'assigned_to'  => 'change-management.editor.assigned_to',
    'approver'     => 'change-management.editor.approver',
    'work_start'   => 'change-management.editor.work_start',
    'work_end'     => 'change-management.editor.work_end',
    'outage_start' => 'change-management.editor.outage_start',
    'outage_end'   => 'change-management.editor.outage_end',
    'description'  => 'change-management.editor.tab_description',
    'reason'       => 'change-management.editor.tab_reason',
    'risk'         => 'change-management.editor.tab_risk',
    'testplan'     => 'change-management.editor.tab_testplan',
    'rollback'     => 'change-management.editor.tab_rollback',
    'pir'          => 'change-management.editor.tab_pir',
];

/**
 * Other names the same field goes by - a database column, an approval setting key, a
 * table column - so every screen can look a label up by whatever key it already has.
 */
const CHANGE_FIELD_ALIASES = [
    'change_type_id' => 'change_type', 'type' => 'change_type', 'status_id' => 'status',
    'priority_id' => 'priority', 'impact_id' => 'impact', 'category_id' => 'category',
    'requester_id' => 'requester', 'requester_name' => 'requester', 'assigned_to_id' => 'assigned_to',
    'approver_id' => 'approver', 'work_start_datetime' => 'work_start', 'work_end_datetime' => 'work_end',
    'outage_start_datetime' => 'outage_start', 'outage_end_datetime' => 'outage_end',
    'reason_for_change' => 'reason', 'risk_evaluation' => 'risk', 'test_plan' => 'testplan',
    'rollback_plan' => 'rollback', 'post_implementation_review' => 'pir',
];

/** Custom names, field key => name; only fields that have one. Empty when the column is not there yet. */
function changeFieldCustomLabels(PDO $conn): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach ($conn->query("SELECT field_key, label FROM change_field_layout WHERE label IS NOT NULL AND label <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset(CHANGE_FIELD_STANDARD_NAMES[$r['field_key']])) $cache[$r['field_key']] = (string)$r['label'];
        }
    } catch (PDOException $e) {
        // label column not added yet (Database Verification not run) - standard names
    }
    return $cache;
}

/**
 * A field's name to show: its custom name, or its standard name in the reader's language.
 *
 * @param string $key  a field key, or an alias (a column such as priority_id)
 */
function changeFieldLabel(PDO $conn, string $key): string
{
    $k = CHANGE_FIELD_ALIASES[$key] ?? $key;
    $custom = changeFieldCustomLabels($conn)[$k] ?? null;
    if ($custom !== null) return $custom;
    return isset(CHANGE_FIELD_STANDARD_NAMES[$k]) ? t(CHANGE_FIELD_STANDARD_NAMES[$k]) : $key;
}

/**
 * The script that gives a page's JavaScript the custom names (window.CM_FIELD_LABELS) and
 * the alias map (window.CM_FIELD_ALIASES); cmLabel() in the change scripts reads them.
 */
function changeFieldLabelsScript(PDO $conn): string
{
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    return '<script>window.CM_FIELD_LABELS = ' . json_encode((object)changeFieldCustomLabels($conn), $flags)
         . '; window.CM_FIELD_ALIASES = ' . json_encode(CHANGE_FIELD_ALIASES, $flags) . ";\n"
         . "/** A change field's name: its custom one (Settings -> Fields), else the standard one passed in. */\n"
         . "window.cmLabel = function (key, standard) { var k = (window.CM_FIELD_ALIASES || {})[key] || key; var c = (window.CM_FIELD_LABELS || {})[k]; return c ? c : standard; };</script>\n";
}
