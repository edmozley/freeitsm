<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * php tests/change-editor-lookups.php
 * Actual editor selects and change-management.js with synthetic lookup responses.
 * Exercises create/edit/template/save and delayed/failed loading in a real browser.
 * Unrelated rich text, risk rendering and list loading are stubbed. No database writes.
 */
require_once __DIR__ . '/support/ticket-workspace-browser.php';
$page = file_get_contents(__DIR__ . '/../change-management/index.php');
$js = file_get_contents(__DIR__ . '/../assets/js/change-management.js');
$html = '<!doctype html><html><meta charset="utf-8"><body>';
foreach (['changeType', 'changePriority', 'changeImpact'] as $id) {
    if (!preg_match('/<select\b[^>]*id="' . $id . '"[^>]*>.*?<\/select>/s', $page, $m)) throw new RuntimeException('Missing select: ' . $id);
    $html .= preg_replace('/<\?php.*?\?>/s', 'Label', $m[0]);
}
preg_match_all('/getElementById\(\x27([\w-]+)\x27\)/', $js, $ids);
foreach (array_unique($ids[1]) as $id) {
    if (!in_array($id, ['changeType', 'changePriority', 'changeImpact'], true)) $html .= '<input id="' . $id . '">';
}
$html .= <<<'HTML'
<script>
const initializers = [], realAdd = document.addEventListener.bind(document);
document.addEventListener = (name, fn, ...rest) => name === 'DOMContentLoaded' ? initializers.push(fn) : realAdd(name, fn, ...rest);
window.t = key => key;
window.ChangeRisk = {currentId: () => 0};
</script><script src="change-management.js"></script><script>
document.addEventListener = realAdd;
window.runChecks = async () => {
    const checks = [], check = (label, pass) => checks.push({label, pass});
    const el = id => document.getElementById(id), value = id => el(id).value;
    const tick = () => new Promise(resolve => setTimeout(resolve, 0));
    let views = 0, lastToast = '', sent = null, release, failLookup = false;
    const lookupWait = new Promise(resolve => release = resolve);
    const rows = {
        priorities: [{id: 3, name: 'Hoch', is_active: true, is_default: true, display_order: 2}, {id: 9, name: 'Legacy', is_active: false, is_default: true, display_order: 0}, {id: 8, name: 'Sonderfall <b>&</b>', is_active: true, display_order: 1}],
        types: [{id: 5, name: 'Notfall', is_active: true, is_default: true}, {id: 6, name: 'Routine', is_active: true}],
        impacts: [{id: 4, name: 'Sehr hoch', is_active: true, is_default: true}, {id: 5, name: 'Gering', is_active: true}]
    };
    window.fetch = async (url, options) => {
        for (const key of Object.keys(rows)) if (url.endsWith('get_change_' + key + '.php')) {
            await lookupWait;
            return {json: async () => failLookup ? {success: false} : {success: true, [key]: rows[key]}};
        }
        if (url.endsWith('save.php')) { sent = JSON.parse(options.body); return {json: async () => ({success: false, error: 'Fixture: no persistence'})}; }
        throw new Error('Unexpected request: ' + url);
    };
    loadFormLayout = async () => {};
    loadStatuses = async () => { changeStatuses = [{name: 'Entwurf', is_default: true}]; };
    loadAnalysts = loadMoveCompanies = loadChanges = setupFileUpload = () => {};
    loadChangeTemplatePicker = initEditors = setEditorContent = renderEditorAttachments = renderCabMemberChips = () => {};
    setEditorRiskScale = updateRiskScore = updatePirVisibility = () => {};
    getEditorContent = () => '';
    showView = () => views++;
    showToast = message => lastToast = message;
    window.openCreateOnLoad = true;
    initializers[0]();
    await tick();
    check('direct new-change route waits for lookup responses', views === 0);
    release(); await tick(); await tick();
    await openCreateChange();
    check('configured defaults replace English constants', value('changePriority') === 'Hoch' && value('changeType') === 'Notfall' && value('changeImpact') === 'Sehr hoch');
    check('active entries are sorted and inactive defaults excluded', Array.from(el('changePriority').options).map(o => o.value).join('|') === 'Sonderfall <b>&</b>|Hoch');
    check('configured names are literal text, not HTML', !el('changePriority').querySelector('b') && el('changePriority').options[0].textContent === 'Sonderfall <b>&</b>');
    el('changeTitle').value = 'Synthetic change';
    await saveChange();
    check('save sends configured names for all three fields', sent?.priority === 'Hoch' && sent?.change_type === 'Notfall' && sent?.impact === 'Sehr hoch');
    currentChange = {id: 42, title: 'Synthetic change', priority: 'Legacy', change_type: 'Routine', impact: 'Gering'};
    await editCurrentChange();
    check('editing retains configured values and an inactive current priority', value('changePriority') === 'Legacy' && value('changeType') === 'Routine' && value('changeImpact') === 'Gering');
    await saveChange();
    check('unrelated edits keep the inactive priority in the payload', sent?.priority === 'Legacy');
    currentChange.approval = {id: 1, state: 'approved'};
    currentChange.approval_rules = {edit_withdraws: true, material: ['priority_id', 'change_type_id', 'impact_id']};
    check('unchanged configured lookups do not withdraw approval', !savingWithdrawsApproval(sent));
    check('a real priority change still requires approval withdrawal', savingWithdrawsApproval({...sent, priority: 'Hoch'}));
    currentChange = null;
    await openCreateChange();
    check('new changes do not inherit an inactive entry from the previous editor', !Array.from(el('changePriority').options).some(o => o.value === 'Legacy'));
    usableTemplates = [{version_id: 11, values: {priority_id: 8, change_type_id: 6, impact_id: 5}}];
    applyChangeTemplate(11);
    check('template lookup IDs select the configured names', value('changePriority') === 'Sonderfall <b>&</b>' && value('changeType') === 'Routine' && value('changeImpact') === 'Gering');
    changePriorities = [rows.priorities[2]];
    await openCreateChange();
    check('without a default, the first active value is selected', value('changePriority') === 'Sonderfall <b>&</b>');
    const before = views; failLookup = true; initializers[0](); await tick(); await tick(); await openCreateChange();
    check('failed lookup requests do not open an editor with misleading choices', views === before && lastToast === 'change-management.toast.lookup_load_error');
    return JSON.stringify(checks);
};
</script></body></html>
HTML;
exit(ticketWorkspaceBrowser(['index.html' => $html, 'change-management.js' => $js], ['verify' => function (string $ws): array {
    try { return json_decode(cdpEvaluate($ws, 'runChecks()', 25), true); }
    finally { try { cdpEvaluate($ws, '', 5, ['method' => 'Browser.close', 'params' => []]); } catch (Throwable $ignored) {} }
}]));
