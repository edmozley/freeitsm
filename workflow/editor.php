<?php
/**
 * Workflows — editor (single workflow).
 *
 * Form-based v1: name + description + trigger + conditions + actions + active.
 * Visual canvas builder + AI co-author will land in subsequent commits — the
 * form-based editor stays as a fallback for power users and headless tooling.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once __DIR__ . '/includes/engine.php';
I18n::initFromSession();

$current_page = 'workflow';
$path_prefix = '../';

$translationNamespaces = ['common', 'workflow'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Catalogues sourced from the engine so the editor never drifts out of sync.
$triggers  = WorkflowEngine::availableTriggers();
$ops       = WorkflowEngine::availableOperators();
$actionDefs = WorkflowEngine::availableActions();
// Build a fields-per-trigger map for the editor's "Field" dropdown to populate
// dynamically when the trigger choice changes.
$fieldsByTrigger = [];
foreach (array_keys($triggers) as $t) {
    $fieldsByTrigger[$t] = WorkflowEngine::availableFields($t);
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($id ? t('workflow.editor.edit_title') : t('workflow.editor.new_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/workflow.css?v=1">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
    <script src="../assets/js/toast.js"></script>
    <style>
        body { overflow: auto; height: auto; }
        .container { max-width: none; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="container">
        <div class="tab-content active">
            <div class="section-header" style="align-items: flex-start;">
                <h2 id="editorTitle"><?php echo htmlspecialchars($id ? t('workflow.editor.edit_title') : t('workflow.editor.new_title')); ?></h2>
                <div style="display: flex; gap: 8px;">
                    <a class="btn btn-secondary" href="index.php" style="text-decoration: none;"><?php echo htmlspecialchars(t('workflow.editor.back')); ?></a>
                    <button class="btn btn-secondary" id="testFireBtn" onclick="WFE.testFire()" title="<?php echo htmlspecialchars(t('workflow.editor.test_fire_hint')); ?>" disabled><?php echo htmlspecialchars(t('workflow.editor.test_fire')); ?></button>
                    <button class="btn btn-primary" onclick="WFE.save()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </div>

            <form id="wfForm" autocomplete="off" onsubmit="event.preventDefault(); WFE.save();" style="display: grid; grid-template-columns: 1fr 320px; gap: 24px;">

                <!-- LEFT — the rule definition -->
                <div>
                    <div class="form-group">
                        <label for="wfName"><?php echo htmlspecialchars(t('workflow.editor.name_label')); ?></label>
                        <input type="text" id="wfName" maxlength="255" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('workflow.editor.name_placeholder')); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="wfDescription"><?php echo htmlspecialchars(t('workflow.editor.description_label')); ?></label>
                        <textarea id="wfDescription" rows="2" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('workflow.editor.description_placeholder')); ?>"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="wfTrigger"><?php echo htmlspecialchars(t('workflow.editor.trigger_label')); ?></label>
                        <select id="wfTrigger" onchange="WFE.onTriggerChange()">
                            <?php foreach ($triggers as $k => $label): ?>
                            <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small style="display: block; color: #666; margin-top: 4px;"><?php echo htmlspecialchars(t('workflow.editor.trigger_hint')); ?></small>
                    </div>

                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('workflow.editor.conditions_label')); ?></label>
                        <small style="display: block; color: #666; margin-bottom: 8px;"><?php echo htmlspecialchars(t('workflow.editor.conditions_hint')); ?></small>
                        <div id="conditionsList"></div>
                        <button type="button" class="btn btn-secondary" style="padding: 6px 14px; font-size: 13px; margin-top: 6px;" onclick="WFE.addCondition()"><?php echo htmlspecialchars(t('workflow.editor.add_condition')); ?></button>
                    </div>

                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('workflow.editor.actions_label')); ?></label>
                        <small style="display: block; color: #666; margin-bottom: 8px;"><?php echo htmlspecialchars(t('workflow.editor.actions_hint')); ?></small>
                        <div id="actionsList"></div>
                        <button type="button" class="btn btn-secondary" style="padding: 6px 14px; font-size: 13px; margin-top: 6px;" onclick="WFE.addAction()"><?php echo htmlspecialchars(t('workflow.editor.add_action')); ?></button>
                    </div>

                    <div class="form-group">
                        <label style="font-weight: normal;">
                            <input type="checkbox" id="wfActive" checked> <?php echo htmlspecialchars(t('workflow.editor.active_label')); ?>
                        </label>
                    </div>
                </div>

                <!-- RIGHT — recent executions -->
                <aside class="wf-runs">
                    <h4 style="margin: 0 0 10px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #888;">Recent runs</h4>
                    <div id="execList" style="font-size: 13px; color: #666;">
                        <em>Save the workflow first, then test-fire it to see runs here.</em>
                    </div>
                </aside>
            </form>
        </div>
    </div>

    <script>
        // PHP-rendered catalogues — keep the JS layer in lockstep with the engine.
        window.WF_TRIGGERS  = <?php echo json_encode($triggers); ?>;
        window.WF_OPS       = <?php echo json_encode($ops); ?>;
        window.WF_ACTION_DEFS = <?php echo json_encode($actionDefs); ?>;
        window.WF_FIELDS_BY_TRIGGER = <?php echo json_encode($fieldsByTrigger); ?>;
        window.WF_ID = <?php echo (int)$id; ?>;
    </script>
    <script>
    const WFE = (() => {
        const API = '../api/workflow/';
        let conditions = [];
        let actions    = [];

        function esc(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }

        function fmtDate(s) {
            if (!s) return '';
            try { return new Date(s.replace(' ', 'T') + 'Z').toLocaleString(); }
            catch (e) { return s; }
        }

        function renderConditions() {
            const host = document.getElementById('conditionsList');
            if (!conditions.length) {
                host.innerHTML = '<div style="font-size: 12px; color: #888; padding: 8px 0;"><em>' + esc(window.t('workflow.editor.no_conditions')) + '</em></div>';
                return;
            }
            const trigger = document.getElementById('wfTrigger').value;
            const fields = window.WF_FIELDS_BY_TRIGGER[trigger] || [];
            host.innerHTML = conditions.map((c, i) => {
                const fieldOptions = fields.map(f => `<option value="${esc(f)}" ${f === c.field ? 'selected' : ''}>${esc(f)}</option>`).join('') +
                                     (c.field && fields.indexOf(c.field) < 0 ? `<option value="${esc(c.field)}" selected>${esc(c.field)} (custom)</option>` : '');
                const opOptions = Object.entries(window.WF_OPS).map(([k, v]) => `<option value="${esc(k)}" ${k === c.op ? 'selected' : ''}>${esc(v)}</option>`).join('');
                const valueDisabled = (c.op === 'is_empty' || c.op === 'is_not_empty') ? 'disabled' : '';
                return `
                <div class="wf-row" data-i="${i}">
                    <select onchange="WFE.updateCondition(${i}, 'field', this.value)" title="Field">
                        ${fieldOptions || '<option value="">— pick a field —</option>'}
                    </select>
                    <select onchange="WFE.updateCondition(${i}, 'op', this.value)" title="Operator">
                        ${opOptions}
                    </select>
                    <input type="text" value="${esc(c.value ?? '')}" oninput="WFE.updateCondition(${i}, 'value', this.value)" placeholder="value" ${valueDisabled}>
                    <button type="button" class="table-action-btn delete" onclick="WFE.removeCondition(${i})" title="Remove">&times;</button>
                </div>`;
            }).join('');
        }

        function renderActions() {
            const host = document.getElementById('actionsList');
            if (!actions.length) {
                host.innerHTML = '<div style="font-size: 12px; color: #888; padding: 8px 0;"><em>' + esc(window.t('workflow.editor.no_actions')) + '</em></div>';
                return;
            }
            host.innerHTML = actions.map((a, i) => {
                const typeOptions = Object.entries(window.WF_ACTION_DEFS).map(
                    ([k, def]) => `<option value="${esc(k)}" ${k === a.type ? 'selected' : ''}>${esc(def.label)}</option>`
                ).join('');
                const argsJson = JSON.stringify(a.args || {}, null, 0);
                return `
                <div class="wf-row" data-i="${i}">
                    <select onchange="WFE.updateActionType(${i}, this.value)" title="Action type" style="flex: 0 0 200px;">
                        ${typeOptions}
                    </select>
                    <input type="text" value="${esc(argsJson)}" oninput="WFE.updateActionArgs(${i}, this.value)" placeholder='{"message": "..."}' title="Arguments JSON" style="flex: 1; font-family: 'Consolas', monospace; font-size: 12px;">
                    <button type="button" class="table-action-btn delete" onclick="WFE.removeAction(${i})" title="Remove">&times;</button>
                </div>`;
            }).join('');
        }

        function onTriggerChange() {
            // Re-render conditions since the available fields may have changed.
            renderConditions();
        }

        function addCondition() {
            const trigger = document.getElementById('wfTrigger').value;
            const fields = window.WF_FIELDS_BY_TRIGGER[trigger] || [];
            conditions.push({ field: fields[0] || '', op: 'equals', value: '' });
            renderConditions();
        }
        function updateCondition(i, key, value) {
            if (!conditions[i]) return;
            conditions[i][key] = value;
            // If the op changed to/from is_empty/is_not_empty we need to redraw
            // so the value input enables/disables correctly.
            if (key === 'op') renderConditions();
        }
        function removeCondition(i) {
            conditions.splice(i, 1);
            renderConditions();
        }

        function addAction() {
            const first = Object.keys(window.WF_ACTION_DEFS)[0];
            const defaults = first === 'log_message' ? { message: '' } : {};
            actions.push({ type: first, args: defaults });
            renderActions();
        }
        function updateActionType(i, value) {
            if (!actions[i]) return;
            actions[i].type = value;
            // Reset args to the new action's defaults so users see a sensible
            // starting JSON rather than the previous action's keys.
            const def = window.WF_ACTION_DEFS[value];
            if (def && value === 'log_message') actions[i].args = { message: '' };
            else actions[i].args = {};
            renderActions();
        }
        function updateActionArgs(i, raw) {
            if (!actions[i]) return;
            try { actions[i].args = raw ? JSON.parse(raw) : {}; }
            catch (e) { /* invalid JSON — keep the old value; the user is mid-typing */ }
        }
        function removeAction(i) {
            actions.splice(i, 1);
            renderActions();
        }

        async function load() {
            if (!window.WF_ID) {
                // Brand new workflow — seed with one log_message action so the
                // user has something visible to start tweaking.
                addAction();
                renderConditions();
                document.getElementById('testFireBtn').disabled = true;
                return;
            }
            try {
                const r = await fetch(API + 'get.php?id=' + window.WF_ID, { credentials: 'same-origin' });
                const d = await r.json();
                if (!d.success) { window.showToast(d.error || 'Load failed', 'error'); return; }
                const w = d.workflow;
                document.getElementById('wfName').value = w.name || '';
                document.getElementById('wfDescription').value = w.description || '';
                document.getElementById('wfTrigger').value = w.trigger_event;
                document.getElementById('wfActive').checked = !!parseInt(w.is_active, 10);
                conditions = Array.isArray(w.conditions) ? w.conditions : [];
                actions    = Array.isArray(w.actions)    ? w.actions    : [];
                renderConditions();
                renderActions();
                renderExecutions(d.executions || []);
                document.getElementById('testFireBtn').disabled = false;
            } catch (e) { window.showToast('Load failed', 'error'); }
        }

        function renderExecutions(execs) {
            const host = document.getElementById('execList');
            if (!execs.length) {
                host.innerHTML = '<em>No runs yet. Test-fire the workflow to see one here.</em>';
                return;
            }
            host.innerHTML = execs.map(e => {
                const pill = e.status === 'success'  ? '<span class="status-badge status-active">' + esc(window.t('workflow.status.success')) + '</span>'
                           : e.status === 'failed'   ? '<span class="status-badge status-inactive">' + esc(window.t('workflow.status.failed'))  + '</span>'
                           : e.status === 'skipped'  ? '<span class="status-badge"   style="background:#fef3c7;color:#92400e;">' + esc(window.t('workflow.status.skipped')) + '</span>'
                           :                           '<span class="status-badge"   style="background:#e0e7ff;color:#3730a3;">' + esc(window.t('workflow.status.running')) + '</span>';
                return `<div style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                    ${pill}
                    <div style="font-size: 12px; color: #888; margin-top: 4px;">${esc(fmtDate(e.started_datetime))}</div>
                    ${e.error_message ? '<div style="font-size: 12px; color: #c33; margin-top: 4px;">' + esc(e.error_message) + '</div>' : ''}
                </div>`;
            }).join('');
        }

        async function save() {
            const name = document.getElementById('wfName').value.trim();
            if (!name) { window.showToast(window.t('workflow.toast.name_required'), 'error'); return; }
            if (!actions.length) { window.showToast(window.t('workflow.toast.actions_required'), 'error'); return; }
            const payload = {
                id: window.WF_ID || null,
                name,
                description: document.getElementById('wfDescription').value.trim(),
                trigger_event: document.getElementById('wfTrigger').value,
                conditions, actions,
                is_active: document.getElementById('wfActive').checked ? 1 : 0,
            };
            try {
                const r = await fetch(API + 'save.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const d = await r.json();
                if (d.success) {
                    window.showToast(window.t('workflow.toast.saved'), 'success');
                    if (!window.WF_ID && d.id) {
                        // Update the URL so refresh stays on this workflow.
                        window.history.replaceState({}, '', 'editor.php?id=' + d.id);
                        window.WF_ID = d.id;
                        document.getElementById('testFireBtn').disabled = false;
                    }
                } else {
                    window.showToast(d.error || 'Save failed', 'error');
                }
            } catch (e) { window.showToast('Save failed', 'error'); }
        }

        async function testFire() {
            if (!window.WF_ID) { window.showToast('Save the workflow first.', 'error'); return; }
            window.showToast(window.t('workflow.toast.fire_started'), 'info');
            try {
                const r = await fetch(API + 'fire.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: window.WF_ID, payload: {} }),
                });
                const d = await r.json();
                if (d.success) {
                    const status = d.result.status;
                    window.showToast(window.t('workflow.toast.fire_done').replace('%s', status), status === 'failed' ? 'error' : 'success');
                    // Refresh the executions sidebar.
                    const re = await fetch(API + 'get.php?id=' + window.WF_ID, { credentials: 'same-origin' });
                    const dd = await re.json();
                    if (dd.success) renderExecutions(dd.executions || []);
                } else {
                    window.showToast(window.t('workflow.toast.fire_failed').replace('%s', d.error || ''), 'error');
                }
            } catch (e) { window.showToast('Test fire failed', 'error'); }
        }

        document.addEventListener('DOMContentLoaded', load);

        return {
            onTriggerChange,
            addCondition, updateCondition, removeCondition,
            addAction, updateActionType, updateActionArgs, removeAction,
            save, testFire,
        };
    })();
    </script>
</body>
</html>
