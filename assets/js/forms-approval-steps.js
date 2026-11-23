/**
 * The approval step list (GH #160 Part C1) - one editor for a form's own steps
 * (Approval settings, forms/index.php) and for a named chain (Forms -> Settings ->
 * Approvals). The server is the judge (catalogueNormaliseSteps() in
 * includes/catalogue_approval_steps.php); this only builds the list and says the
 * obvious problems before the round trip.
 *
 *   const ed = ApprovalSteps.mount(el, { steps, analysts, teams, questions });
 *   const { steps, error } = ed.read();
 *
 * steps      the stored list (or [] for one empty step)
 * analysts   [{id, full_name}]           who can be "A person"
 * teams      [{id, name, member_count}]  who can be "A team"
 * questions  ['Order total', ...]        labels an "Only when" condition can look at.
 *            A condition names its question by LABEL, because a chain is shared by
 *            many forms and a form's field ids change with each version.
 * ccQuestions ['Cost centre', ...]       labels of cost-centre questions, for "The owner
 *            of a cost centre" (GH #160 Part C2). Blank = the requester's own.
 * people     true when the install can route to the requester's manager and a cost
 *            centre's owner (Database Verification has run). A fallback is always staff.
 */
(function () {
    'use strict';
    const T = (k, p) => window.t('forms.steps.' + k, p);
    const OPS = ['equals', 'not_equals', 'contains', 'is_empty', 'is_not_empty', 'greater_than', 'less_than', 'is_after', 'is_before'];
    const NO_VALUE = ['is_empty', 'is_not_empty'];
    const MAX = 10;
    // Escapes quotes too: every value here lands inside an attribute somewhere.
    const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    function blankStep() {
        return { name: '', who: 'person', approver_id: null, exclude_requester: 0, team_id: null,
                 approvals_required: 1, rejections_required: 1, when: null, fallback: null };
    }

    function mount(el, opts) {
        const analysts = opts.analysts || [];
        const teams = opts.teams || [];
        const questions = (opts.questions || []).slice();
        const ccQuestions = (opts.ccQuestions || []).slice();
        const people = opts.people !== false;
        let steps = (opts.steps && opts.steps.length ? opts.steps : [blankStep()]).map(s => JSON.parse(JSON.stringify(s)));
        // An owner step naming a question this list does not know is still shown.
        steps.forEach(s => { if (s.cc_question && ccQuestions.indexOf(s.cc_question) === -1) ccQuestions.push(s.cc_question); });
        // A condition on a question this list does not know (renamed, or from another
        // form on a chain) is still shown, so it is visible rather than silently lost.
        steps.forEach(s => (s.when && s.when.rules || []).forEach(r => {
            if (r.question && questions.indexOf(r.question) === -1) questions.push(r.question);
        }));

        const personOptions = sel => '<option value="">' + esc(window.t('forms.approval.approver_none')) + '</option>'
            + analysts.map(a => '<option value="' + a.id + '"' + (String(sel) === String(a.id) ? ' selected' : '') + '>' + esc(a.full_name || a.username || ('#' + a.id)) + '</option>').join('');
        const teamOptions = sel => '<option value="">' + esc(window.t('forms.approval.team_none')) + '</option>'
            + teams.map(tm => '<option value="' + tm.id + '"' + (String(sel) === String(tm.id) ? ' selected' : '') + '>' + esc(tm.name) + ' (' + esc(window.t('forms.approval.team_members', { n: tm.member_count })) + ')</option>').join('');

        function whoHtml(prefix, p, withExclude) {
            const team = p.who === 'team';
            // GH #160 Part C2: the requester's manager and a cost centre's owner - steps
            // only (withExclude), never a fallback, which must be somebody fixed.
            const opt = (v, label) => '<option value="' + v + '"' + (p.who === v ? ' selected' : '') + '>' + esc(label) + '</option>';
            const kinds = opt('person', window.t('forms.approval.mode_person')) + opt('team', window.t('forms.approval.mode_team'))
                + (withExclude && (people || p.who === 'manager' || p.who === 'cc_owner')
                    ? opt('manager', T('mode_manager')) + opt('cc_owner', T('mode_cc_owner')) : '');
            if (withExclude && p.who === 'manager') {
                return '<div class="as-who"><select data-k="' + prefix + 'who" class="as-sel-who">' + kinds + '</select>'
                    + '<small class="ca-hint">' + esc(T('manager_hint')) + '</small></div>';
            }
            if (withExclude && p.who === 'cc_owner') {
                const ccs = '<option value="">' + esc(T('cc_own')) + '</option>'
                    + ccQuestions.map(q => '<option value="' + esc(q) + '"' + (q === p.cc_question ? ' selected' : '') + '>' + esc(T('cc_question', { q })) + '</option>').join('');
                return '<div class="as-who"><select data-k="' + prefix + 'who" class="as-sel-who">' + kinds + '</select>'
                    + '<select data-k="cc_question">' + ccs + '</select>'
                    + '<small class="ca-hint">' + esc(T('cc_owner_hint')) + '</small></div>';
            }
            return '<div class="as-who">'
                + '<select data-k="' + prefix + 'who" class="as-sel-who">' + kinds + '</select>'
                + (team
                    ? '<select data-k="' + prefix + 'team_id">' + teamOptions(p.team_id) + '</select>'
                      + '<label class="as-num">' + esc(window.t('forms.approval.approvals_needed')) + ' <input type="number" min="1" max="20" data-k="' + prefix + 'approvals_required" value="' + esc(p.approvals_required || 1) + '"></label>'
                      + '<label class="as-num">' + esc(window.t('forms.approval.rejections_needed')) + ' <input type="number" min="1" max="20" data-k="' + prefix + 'rejections_required" value="' + esc(p.rejections_required || 1) + '"></label>'
                      + (function () {
                          // Say so before saving if the team cannot supply the approvals asked for (GH #220).
                          const tm = teams.find(x => String(x.id) === String(p.team_id));
                          return tm && tm.member_count < (Number(p.approvals_required) || 1)
                              ? '<p class="ca-note as-warn">' + esc(window.t('forms.approval.team_too_small', { n: tm.member_count })) + '</p>' : '';
                      })()
                    : '<select data-k="' + prefix + 'approver_id">' + personOptions(p.approver_id) + '</select>'
                      + (withExclude ? '<label class="ca-check as-tick"><input type="checkbox" data-k="exclude_requester"' + (Number(p.exclude_requester) ? ' checked' : '') + '> <span>' + esc(window.t('forms.approval.exclude_requester_label')) + '</span></label>' : ''))
                + '</div>';
        }

        function ruleHtml(r, j) {
            const qs = '<option value="">' + esc(T('cond_question')) + '</option>'
                + questions.map(q => '<option value="' + esc(q) + '"' + (q === r.question ? ' selected' : '') + '>' + esc(q) + '</option>').join('');
            const ops = OPS.map(o => '<option value="' + o + '"' + (o === r.op ? ' selected' : '') + '>' + esc(window.t('forms.cond.op.' + o)) + '</option>').join('');
            return '<div class="as-rule" data-rule="' + j + '">'
                + '<select data-r="question">' + qs + '</select>'
                + '<select data-r="op">' + ops + '</select>'
                + '<input type="text" data-r="value" value="' + esc(r.value || '') + '" placeholder="' + esc(window.t('forms.cond.value_ph')) + '"' + (NO_VALUE.indexOf(r.op) !== -1 ? ' hidden' : '') + '>'
                + '<button type="button" class="as-icon" data-act="rule-remove" title="' + esc(window.t('forms.cond.remove')) + '" aria-label="' + esc(window.t('forms.cond.remove')) + '">&times;</button>'
                + '</div>';
        }

        function stepHtml(s, i) {
            const when = s.when && s.when.rules && s.when.rules.length ? s.when : null;
            const fb = s.fallback && s.fallback.who ? s.fallback : null;
            return '<div class="as-step" data-i="' + i + '">'
                + '<div class="as-step-head">'
                + '<span class="as-step-no">' + esc(T('step_n', { n: i + 1 })) + '</span>'
                + '<input type="text" data-k="name" maxlength="100" value="' + esc(s.name || '') + '" placeholder="' + esc(T('name_ph')) + '">'
                + '<button type="button" class="as-icon" data-act="up" title="' + esc(T('up')) + '" aria-label="' + esc(T('up')) + '"' + (i === 0 ? ' disabled' : '') + '>&#8593;</button>'
                + '<button type="button" class="as-icon" data-act="down" title="' + esc(T('down')) + '" aria-label="' + esc(T('down')) + '"' + (i === steps.length - 1 ? ' disabled' : '') + '>&#8595;</button>'
                + '<button type="button" class="as-icon" data-act="remove" title="' + esc(T('remove')) + '" aria-label="' + esc(T('remove')) + '"' + (steps.length === 1 ? ' disabled' : '') + '>&times;</button>'
                + '</div>'
                + '<div class="as-row"><span class="as-label">' + esc(window.t('forms.approval.who_decides')) + '</span>' + whoHtml('', s, true) + '</div>'
                + '<div class="as-row"><span class="as-label">' + esc(T('only_when')) + '</span><div class="as-when">'
                + '<select data-k="when_on"><option value="">' + esc(T('always')) + '</option><option value="1"' + (when ? ' selected' : '') + '>' + esc(T('conditional')) + '</option></select>'
                + (when
                    ? (when.rules.length > 1 ? '<select data-k="when_match"><option value="all"' + (when.match !== 'any' ? ' selected' : '') + '>' + esc(window.t('forms.cond.match_all')) + '</option><option value="any"' + (when.match === 'any' ? ' selected' : '') + '>' + esc(window.t('forms.cond.match_any')) + '</option></select>' : '')
                      + when.rules.map(ruleHtml).join('')
                      + (when.rules.length < 10 ? '<button type="button" class="as-link" data-act="rule-add">' + esc(window.t('forms.cond.add')) + '</button>' : '')
                      + '<small class="ca-hint">' + esc(T('cond_hint')) + '</small>'
                    : '')
                + '</div></div>'
                + '<div class="as-row"><span class="as-label">' + esc(T('fallback')) + '</span><div class="as-fallback">'
                + '<select data-k="fb_on"><option value="">' + esc(T('fallback_default')) + '</option><option value="1"' + (fb ? ' selected' : '') + '>' + esc(T('fallback_choose')) + '</option></select>'
                + (fb ? whoHtml('fb_', fb, false) : '')
                + '<small class="ca-hint">' + esc(T('fallback_hint')) + '</small>'
                + '</div></div>'
                + '</div>';
        }

        function render() {
            el.innerHTML = '<div class="as-list">' + steps.map(stepHtml).join('') + '</div>'
                + (steps.length < MAX ? '<button type="button" class="ca-btn ca-btn-secondary as-add" data-act="add">' + esc(T('add')) + '</button>' : '');
        }

        // Read what is on screen back into `steps` - before any re-render, so a
        // structural change (add, move, Person to Team) never loses what was typed.
        function sync() {
            el.querySelectorAll('.as-step').forEach(card => {
                const s = steps[Number(card.dataset.i)];
                const v = k => { const f = card.querySelector('[data-k="' + k + '"]'); return f ? (f.type === 'checkbox' ? (f.checked ? 1 : 0) : f.value) : undefined; };
                s.name = v('name') || '';
                s.who = v('who') || 'person';
                if (s.who === 'cc_owner') {
                    s.cc_question = v('cc_question') || '';
                } else if (s.who === 'manager') {
                    // nothing to choose: the requester's manager, worked out at each decision
                } else if (s.who === 'team') {
                    s.team_id = v('team_id') ? Number(v('team_id')) : null;
                    s.approvals_required = parseInt(v('approvals_required'), 10) || 1;
                    s.rejections_required = parseInt(v('rejections_required'), 10) || 1;
                } else {
                    s.approver_id = v('approver_id') ? Number(v('approver_id')) : null;
                    s.exclude_requester = v('exclude_requester') || 0;
                }
                if (v('when_on') === '1') {
                    const rules = [];
                    card.querySelectorAll('.as-rule').forEach(r => rules.push({
                        question: r.querySelector('[data-r="question"]').value,
                        op: r.querySelector('[data-r="op"]').value,
                        value: r.querySelector('[data-r="value"]').value,
                    }));
                    s.when = { match: v('when_match') === 'any' ? 'any' : 'all', rules: rules.length ? rules : [{ question: '', op: 'greater_than', value: '' }] };
                } else {
                    s.when = null;
                }
                if (v('fb_on') === '1') {
                    const fwho = v('fb_who') || 'person';
                    s.fallback = fwho === 'team'
                        ? { who: 'team', team_id: v('fb_team_id') ? Number(v('fb_team_id')) : null,
                            approvals_required: parseInt(v('fb_approvals_required'), 10) || 1, rejections_required: parseInt(v('fb_rejections_required'), 10) || 1 }
                        : { who: 'person', approver_id: v('fb_approver_id') ? Number(v('fb_approver_id')) : null };
                } else {
                    s.fallback = null;
                }
            });
        }

        el.addEventListener('click', e => {
            const btn = e.target.closest('[data-act]');
            if (!btn || btn.disabled) return;
            sync();
            const card = btn.closest('.as-step');
            const i = card ? Number(card.dataset.i) : -1;
            switch (btn.dataset.act) {
                case 'add': if (steps.length < MAX) steps.push(blankStep()); break;
                case 'remove': if (steps.length > 1) steps.splice(i, 1); break;
                case 'up': if (i > 0) steps.splice(i - 1, 0, steps.splice(i, 1)[0]); break;
                case 'down': if (i < steps.length - 1) steps.splice(i + 1, 0, steps.splice(i, 1)[0]); break;
                case 'rule-add': steps[i].when.rules.push({ question: '', op: 'equals', value: '' }); break;
                case 'rule-remove': {
                    const j = Number(btn.closest('.as-rule').dataset.rule);
                    steps[i].when.rules.splice(j, 1);
                    if (!steps[i].when.rules.length) steps[i].when = null;
                    break;
                }
            }
            render();
        });
        el.addEventListener('change', e => {
            const k = e.target.dataset.k, r = e.target.dataset.r;
            if (['who', 'fb_who', 'when_on', 'fb_on', 'team_id', 'fb_team_id', 'approvals_required', 'fb_approvals_required'].indexOf(k) !== -1) { sync(); render(); return; }
            if (r === 'op') {
                const val = e.target.closest('.as-rule').querySelector('[data-r="value"]');
                val.hidden = NO_VALUE.indexOf(e.target.value) !== -1;
            }
        });

        render();
        return {
            read() {
                sync();
                for (let i = 0; i < steps.length; i++) {
                    const s = steps[i], n = i + 1;
                    if (s.who === 'team' ? !s.team_id : (s.who === 'person' && !s.approver_id)) return { steps, error: T('need_who', { n }) };
                    if (s.when && s.when.rules.some(r => !r.question)) return { steps, error: T('need_question', { n }) };
                    if (s.fallback && (s.fallback.who === 'team' ? !s.fallback.team_id : !s.fallback.approver_id)) return { steps, error: T('need_fallback', { n }) };
                }
                return { steps: JSON.parse(JSON.stringify(steps)), error: null };
            },
        };
    }

    /** One step that is exactly the old single policy - saved as the old columns, so nothing changes. */
    function isPlain(steps) {
        if (steps.length !== 1) return false;
        const s = steps[0];
        // A manager's or an owner's step is never "plain": only steps can hold it.
        return !String(s.name || '').trim() && !s.when && !s.fallback && (s.who === 'person' || s.who === 'team');
    }

    window.ApprovalSteps = { mount, isPlain, blankStep };
})();
