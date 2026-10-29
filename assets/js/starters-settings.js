/**
 * Starters & leavers -> Settings -> Templates (starters/settings/index.php).
 *
 * Lists the templates by kind and edits one in a dialog: its name, kind, the
 * questions it asks (a form from Forms - optional), when a form's answers pick
 * it, and its steps - each step's team or analyst, how many days from the case's
 * date it is due, a checklist from Tasks, and (with a form) "only if" an answer
 * says so. Saved whole through api/starters/templates.php.
 *
 * Rules are kept by question LABEL (see StartersService::rawAnswers()), so the
 * question lists here come from the form's newest version, by label.
 */
(function () {
    'use strict';
    const { T, esc, api, lookups, modal, stepsLabel } = window.Jml;
    const $ = id => document.getElementById(id);
    let templates = [], look = null, editing = null, m = null;
    let questions = [];      // the chosen form's questions: [{label, type, options}]
    let lastTitle = null;    // the step title box last typed in, for the {Question} chips

    const opts = (rows, chosen, none) => '<option value="">' + esc(none) + '</option>'
        + rows.map(r => '<option value="' + r.id + '"' + (String(r.id) === String(chosen) ? ' selected' : '') + '>' + esc(r.name || r.title) + '</option>').join('');
    const OPS = ['equals', 'not_equals', 'any_of', 'contains', 'is_not_empty', 'is_empty'];
    const icon = d => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + d + '</svg>';

    function drawList() {
        const host = $('jmlTplList');
        if (!templates.length) { host.innerHTML = '<p class="jml-muted">' + esc(T('settings.none')) + '</p>'; return; }
        host.innerHTML = ['starter', 'mover', 'leaver'].map(k => {
            const mine = templates.filter(t => t.kind === k);
            if (!mine.length) return '';
            return '<div class="jml-tpl-group"><h3>' + esc(T('kinds.' + k)) + '</h3>' + mine.map(t =>
                '<div class="jml-tpl' + (t.is_active ? '' : ' off') + '" tabindex="0" data-id="' + t.id + '"><div class="grow"><b>' + esc(t.name) + '</b>'
                + (t.description ? '<div class="jml-dim">' + esc(t.description) + '</div>' : '')
                + (t.form_title ? '<div class="jml-dim">' + esc(T('settings.asks', { form: t.form_title })) + (t.match_rule ? ' &middot; ' + esc(ruleText(t.match_rule)) : '') + '</div>' : '') + '</div>'
                + '<span class="jml-dim">' + esc(stepsLabel(t.steps.length)) + '</span>'
                + (t.is_active ? '' : '<span class="jml-pill">' + esc(T('settings.inactive')) + '</span>') + '</div>').join('') + '</div>';
        }).join('');
        host.querySelectorAll('.jml-tpl').forEach(el => {
            const go = () => edit(templates.find(t => String(t.id) === el.dataset.id));
            el.onclick = go;
            el.onkeydown = e => { if (e.key === 'Enter') go(); };
        });
    }

    /** "Department is Dispute Resolution" - a rule in words, for the list. */
    function ruleText(rule) {
        return (rule.rules || []).map(r => r.question + ' ' + T('settings.op_' + r.op).toLowerCase() + (['is_empty', 'is_not_empty'].includes(r.op) ? '' : ' ' + r.value))
            .join(rule.match === 'any' ? ' / ' : ' + ');
    }

    // ---- a rule: [question] [is] [answer] ------------------------------------
    /** One rule's controls; `none` is the label of the blank question (always / no rule). */
    function ruleControls(rule, none) {
        const r = (rule && rule.rules && rule.rules[0]) || {};
        return '<select data-r="question"><option value="">' + esc(none) + '</option>'
            + questions.map(q => '<option' + (q.label.toLowerCase() === String(r.question || '').toLowerCase() ? ' selected' : '') + '>' + esc(q.label) + '</option>').join('')
            + (r.question && !questions.some(q => q.label.toLowerCase() === r.question.toLowerCase()) ? '<option selected>' + esc(r.question) + '</option>' : '')
            + '</select>'
            + '<select data-r="op"' + (r.question ? '' : ' hidden') + '>' + OPS.map(o => '<option value="' + o + '"' + (o === (r.op || 'equals') ? ' selected' : '') + '>' + esc(T('settings.op_' + o)) + '</option>').join('') + '</select>'
            + '<span data-r-value-host' + (r.question ? '' : ' hidden') + '>' + valueControl(r.question, r.op || 'equals', r.value || '') + '</span>';
    }
    /** The answer box: the question's own choices where it has them, else typed. */
    function valueControl(question, op, value) {
        if (op === 'is_empty' || op === 'is_not_empty') return '';
        const q = questions.find(x => x.label.toLowerCase() === String(question || '').toLowerCase());
        if (q && q.options.length && op !== 'any_of' && op !== 'contains') {
            return '<select data-r="value">' + q.options.map(o => '<option' + (o === value ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>';
        }
        const ph = op === 'any_of' ? T('settings.any_of_ph') : T('settings.value_ph');
        return '<input type="text" data-r="value" autocomplete="off" data-lpignore="true" data-1p-ignore data-bwignore value="' + esc(value) + '" placeholder="' + esc(ph) + '">';
    }
    /** Reads the rule controls inside `host` back into {match, rules} - or null for none. */
    function readRule(host) {
        const q = host.querySelector('[data-r="question"]');
        if (!q || !q.value) return null;
        const v = host.querySelector('[data-r="value"]');
        return { match: 'all', rules: [{ question: q.value, op: host.querySelector('[data-r="op"]').value, value: v ? v.value : '' }] };
    }
    /** Changing the question or the operator redraws the answer box to suit. */
    function onRuleChange(e) {
        const sel = e.target.closest('[data-r="question"], [data-r="op"]');
        if (!sel) return;
        const box = sel.parentElement;
        const cur = box.querySelector('[data-r="value"]');
        const question = box.querySelector('[data-r="question"]').value;
        const host = box.querySelector('[data-r-value-host]');
        host.innerHTML = valueControl(question, box.querySelector('[data-r="op"]').value, cur ? cur.value : '');
        // "Always" / no rule: nothing to compare, so no operator or answer.
        box.querySelector('[data-r="op"]').hidden = !question;
        host.hidden = !question;
    }

    // ---- steps -----------------------------------------------------------------
    function stepGroup(s) {
        return '<tbody class="jml-step-group"><tr>'
            + '<td><input type="text" data-f="title" maxlength="255" autocomplete="off" data-lpignore="true" data-1p-ignore data-bwignore value="' + esc(s.title || '') + '" placeholder="' + esc(T('settings.step_title_ph')) + '"></td>'
            + '<td><select data-f="assigned_team_id">' + opts(look.teams, s.assigned_team_id, T('settings.step_none')) + '</select></td>'
            + '<td><select data-f="assigned_analyst_id">' + opts(look.analysts, s.assigned_analyst_id, T('settings.step_none')) + '</select></td>'
            + '<td><input type="number" data-f="due_offset_days" min="-365" max="365" value="' + (s.due_offset_days || 0) + '" title="' + esc(T('settings.step_days_tip')) + '"></td>'
            + '<td><select data-f="checklist_template_id">' + opts(look.checklists, s.checklist_template_id, T('settings.step_none')) + '</select></td>'
            + '<td class="ops">'
            + '<button type="button" class="jml-icon-btn" data-op="up" title="' + esc(T('settings.step_up')) + '">' + icon('<path d="M18 15l-6-6-6 6"/>') + '</button>'
            + '<button type="button" class="jml-icon-btn" data-op="down" title="' + esc(T('settings.step_down')) + '">' + icon('<path d="M6 9l6 6 6-6"/>') + '</button>'
            + '<button type="button" class="jml-icon-btn danger" data-op="remove" title="' + esc(T('settings.step_remove')) + '">' + icon('<path d="M18 6L6 18M6 6l12 12"/>') + '</button>'
            + '</td></tr>'
            + (questions.length ? '<tr class="jml-cond"><td colspan="6"><div class="jml-rule"><span class="jml-dim">' + esc(T('settings.only_if')) + '</span>'
                + ruleControls(s.condition_rule, T('settings.always')) + '</div></td></tr>' : '')
            + '</tbody>';
    }

    /** The steps as they stand in the dialog, empty rows included (the server drops them). */
    function readSteps() {
        return [...document.querySelectorAll('#jmlTplStepsTable .jml-step-group')].map(g => {
            const s = {};
            g.querySelectorAll('[data-f]').forEach(el => { s[el.dataset.f] = el.value; });
            const cond = g.querySelector('.jml-cond');
            s.condition_rule = cond ? readRule(cond) : null;
            return s;
        });
    }
    function drawSteps(steps) {
        const table = $('jmlTplStepsTable');
        table.querySelectorAll('.jml-step-group').forEach(g => g.remove());
        table.insertAdjacentHTML('beforeend', steps.map(stepGroup).join(''));
    }

    // ---- the form ----------------------------------------------------------------
    /** Loads the chosen form's questions and redraws what depends on them. */
    async function setForm(formId, keep) {
        const steps = keep ? readSteps() : null;
        const match = keep ? readRule($('jmlTplMatch')) : (editing ? editing.match_rule : null);
        questions = [];
        if (formId) {
            try { questions = (await api('form_questions.php?form_id=' + encodeURIComponent(formId))).questions; }
            catch (e) { $('jmlTplError').textContent = e.message; $('jmlTplError').hidden = false; }
        }
        $('jmlTplFormBits').hidden = !formId;
        $('jmlTplMatch').innerHTML = questions.length ? ruleControls(match, T('settings.match_none')) : '';
        $('jmlTplChips').innerHTML = questions.length
            ? '<span class="jml-dim">' + esc(T('settings.chips')) + '</span> ' + questions.map(q => '<button type="button" class="jml-chip" data-chip="' + esc(q.label) + '">{' + esc(q.label) + '}</button>').join('')
            : '';
        drawSteps(steps || (editing && editing.steps.length ? editing.steps : [{}]));
    }

    async function edit(t) {
        editing = t || null;
        $('jmlTplName').value = t ? t.name : '';
        $('jmlTplKind').value = t ? t.kind : 'starter';
        $('jmlTplDesc').value = t ? (t.description || '') : '';
        $('jmlTplActive').checked = t ? t.is_active : true;
        $('jmlTplDelete').hidden = !t;
        $('jmlTplError').hidden = true;
        const sel = $('jmlTplForm');
        sel.innerHTML = '<option value="">' + esc(T('settings.form_none')) + '</option>'
            + (look.forms || []).map(f => '<option value="' + f.id + '">' + esc(f.name) + '</option>').join('')
            // A template keeps an older version's id: show it until it is changed.
            + (t && t.form_id && !(look.forms || []).some(f => f.id === t.form_id) ? '<option value="' + t.form_id + '">' + esc(t.form_title || ('#' + t.form_id)) + '</option>' : '');
        sel.value = t && t.form_id ? String(t.form_id) : '';
        $('jmlTplFormField').hidden = !(look.forms || []).length && !(t && t.form_id);
        await setForm(sel.value, false);
        m.open();
        setTimeout(() => $('jmlTplName').focus(), 60);
    }

    async function save() {
        const btn = $('jmlTplSave');
        btn.disabled = true;
        try {
            await api('templates.php', { action: 'save', template: {
                id: editing ? editing.id : null, name: $('jmlTplName').value, kind: $('jmlTplKind').value,
                description: $('jmlTplDesc').value, is_active: $('jmlTplActive').checked,
                form_id: $('jmlTplForm').value || null, match_rule: readRule($('jmlTplMatch')), steps: readSteps(),
            } });
            m.close();
            window.showToast && showToast(T('settings.saved'), 'success');
            await load();
        } catch (e) {
            $('jmlTplError').textContent = e.message; $('jmlTplError').hidden = false;
        }
        btn.disabled = false;
    }

    async function remove() {
        if (!editing) return;
        const ok = window.showConfirm
            ? await showConfirm({ title: T('settings.delete_title', { name: editing.name }), message: T('settings.delete_body'), okLabel: T('settings.delete'), okClass: 'danger' })
            : confirm(T('settings.delete_title', { name: editing.name }));
        if (!ok) return;
        try {
            await api('templates.php', { action: 'delete', id: editing.id });
            m.close();
            window.showToast && showToast(T('settings.deleted'), 'success');
            await load();
        } catch (e) { $('jmlTplError').textContent = e.message; $('jmlTplError').hidden = false; }
    }

    async function load() {
        templates = (await api('templates.php')).templates;
        drawList();
    }

    document.addEventListener('DOMContentLoaded', async () => {
        if (!$('jmlTplList')) return;   // no permission: the page says so
        m = modal($('jmlTplModal'));
        try { look = await lookups(); } catch (e) { look = { teams: [], analysts: [], checklists: [], forms: [] }; }
        $('jmlTplNew').onclick = () => edit(null);
        $('jmlTplSave').onclick = save;
        $('jmlTplDelete').onclick = remove;
        $('jmlTplForm').onchange = e => setForm(e.target.value, true);
        $('jmlTplModal').addEventListener('change', onRuleChange);
        $('jmlTplAddStep').onclick = () => {
            const steps = readSteps(); steps.push({}); drawSteps(steps);
            const groups = document.querySelectorAll('#jmlTplStepsTable .jml-step-group');
            groups[groups.length - 1].querySelector('input').focus();
        };
        // {Question} chips: put the answer's placeholder where the cursor is.
        $('jmlTplModal').addEventListener('focusin', e => { if (e.target.matches('[data-f="title"]')) lastTitle = e.target; });
        $('jmlTplChips').addEventListener('mousedown', e => {
            const b = e.target.closest('[data-chip]');
            if (!b) return;
            e.preventDefault();   // keep the cursor in the step box
            const box = lastTitle && document.contains(lastTitle) ? lastTitle : document.querySelector('#jmlTplStepsTable [data-f="title"]');
            if (!box) return;
            const text = '{' + b.dataset.chip + '}';
            const at = box.selectionStart ?? box.value.length;
            box.value = box.value.slice(0, at) + text + box.value.slice(box.selectionEnd ?? at);
            box.focus();
            box.setSelectionRange(at + text.length, at + text.length);
        });
        $('jmlTplStepsTable').addEventListener('click', e => {
            const b = e.target.closest('[data-op]');
            if (!b) return;
            const groups = [...document.querySelectorAll('#jmlTplStepsTable .jml-step-group')];
            const i = groups.indexOf(b.closest('.jml-step-group'));
            const steps = readSteps();
            if (b.dataset.op === 'remove') steps.splice(i, 1);
            else if (b.dataset.op === 'up' && i > 0) [steps[i - 1], steps[i]] = [steps[i], steps[i - 1]];
            else if (b.dataset.op === 'down' && i < steps.length - 1) [steps[i + 1], steps[i]] = [steps[i], steps[i + 1]];
            drawSteps(steps.length ? steps : [{}]);
        });
        try { await load(); } catch (e) { $('jmlTplList').innerHTML = '<p class="jml-error">' + esc(e.message) + '</p>'; return; }
        // ?template=<id> - a case page's link straight to the template it used.
        const want = new URLSearchParams(location.search).get('template');
        const t = want && templates.find(x => String(x.id) === want);
        if (t) edit(t);
    });
})();
