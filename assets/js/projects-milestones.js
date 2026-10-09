/**
 * Projects - milestones on a project's page (3.3.0).
 *
 * A milestone is a named date the project promises ("Move day"). This file
 * draws them in three places and owns the one dialog that edits them:
 *   Overview  #pvMilestones - what is coming up and what was missed, with Done
 *   Plan      diamonds in each lane's head (chips()) and a strip for the whole
 *             project's ones (projectStrip()), plus the Milestone button
 *   Timeline  projects-timeline.js draws them and calls open() / save()
 *
 * Done, missed or due is worked out by the server (includes/projects/milestones.php)
 * and sent as `state`; this file never decides it, so the page, the health and the
 * bell always agree.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = P.T, esc = P.esc;
    let ctx = null;   // {data, projectId, refresh}

    const STATE_CLASS = { done: 'ms-done', missed: 'ms-missed', due: 'ms-due' };

    function canChange() {
        return !!(ctx && ctx.data.permissions && ctx.data.permissions.can_change);
    }
    function list() {
        return (ctx && ctx.data.milestones) || [];
    }

    /** The diamond, coloured by state. */
    function diamond(m, size) {
        return '<span class="prj-ms-dia ' + STATE_CLASS[m.state] + '" style="--s:' + (size || 12) + 'px" aria-hidden="true"></span>';
    }

    const daysFromToday = d => P.daysTo(d);

    /** "Reached on time" / "Missed" / "In 5 days" - a phrase for the state. */
    function phrase(m) {
        if (m.state === 'done') {
            const late = daysFromToday(m.done_date) - daysFromToday(m.due_date);
            return late > 0 ? T('milestones.state_late', { days: late }) : T('milestones.state_met');
        }
        if (m.state === 'missed') return T('milestones.state_missed') + ' - ' + T('milestones.days_ago', { days: -daysFromToday(m.due_date) });
        const d = daysFromToday(m.due_date);
        return d === 0 ? T('milestones.today') : d === 1 ? T('milestones.tomorrow') : T('milestones.in_days', { days: d });
    }

    /** A clickable chip: diamond, name, date. */
    function chip(m) {
        return '<button type="button" class="prj-ms-chip ' + STATE_CLASS[m.state] + '" data-ms-edit="' + m.id + '" title="' + esc(m.name + ' - ' + P.fmtDate(m.due_date) + ' - ' + phrase(m)) + '">'
            + diamond(m, 10) + '<span class="prj-ms-chip-name">' + esc(m.name) + '</span><span class="prj-ms-chip-date">' + esc(P.fmtDate(m.due_date)) + '</span></button>';
    }

    /** The diamonds in one lane's head (stageId) - '' when it has none. */
    function chips(stageId) {
        const rows = list().filter(m => String(m.stage_id || '') === String(stageId || ''));
        return rows.length ? '<div class="prj-ms-chips">' + rows.map(chip).join('') + '</div>' : '';
    }

    /** The whole project's milestones, above the lanes on the Plan. */
    function projectStrip() {
        const rows = list().filter(m => !m.stage_id);
        if (!rows.length) return '';
        return '<div class="prj-ms-strip"><span class="prj-ms-strip-label">' + esc(T('timeline.project_row')) + '</span>' + rows.map(chip).join('') + '</div>';
    }

    // ---- Overview -----------------------------------------------------------------
    function renderOverview() {
        const box = document.getElementById('pvMilestones');
        if (!box) return;
        const rows = list();
        const finished = ['closed', 'cancelled'].includes(ctx.data.project.status);
        if (!rows.length && (finished || !canChange())) { box.hidden = true; return; }
        // Missed first, then what is coming, then the last few reached.
        const missed = rows.filter(m => m.state === 'missed');
        const due = rows.filter(m => m.state === 'due').slice(0, 4);
        const done = rows.filter(m => m.state === 'done').slice(-2);
        const item = m => '<li class="prj-ms-row ' + STATE_CLASS[m.state] + '">' + diamond(m, 13)
            + '<button type="button" class="prj-ms-row-main" data-ms-edit="' + m.id + '"><span class="prj-ms-row-name">' + esc(m.name) + '</span>'
            + '<span class="prj-ms-row-sub">' + esc(P.fmtDate(m.due_date)) + (m.stage_name ? ' &middot; ' + esc(m.stage_name) : '') + '</span></button>'
            + '<span class="prj-ms-row-state">' + esc(phrase(m)) + '</span>'
            + (m.state !== 'done' && canChange() ? '<button type="button" class="btn btn-secondary sm" data-ms-done="' + m.id + '">' + esc(T('milestones.mark_done')) + '</button>' : '')
            + '</li>';
        box.hidden = false;
        box.innerHTML = '<div class="prj-panel prj-ms-panel"><h3>' + P.icon('flag', 16) + ' ' + esc(T('milestones.heading'))
            + (canChange() && !finished ? ' <button type="button" class="prj-link" data-ms-new>+ ' + esc(T('milestones.add')) + '</button>' : '') + '</h3>'
            + (rows.length
                ? '<ul class="prj-ms-list">' + missed.concat(due, done).map(item).join('') + '</ul>'
                : '<p class="prj-muted">' + esc(T('milestones.none')) + '</p>')
            + '</div>';
    }

    // ---- The dialog ------------------------------------------------------------------
    function stageOptions(selected) {
        return '<option value="">' + esc(T('milestones.whole_project')) + '</option>'
            + (ctx.data.stages || []).map(s => '<option value="' + s.id + '"' + (String(s.id) === String(selected || '') ? ' selected' : '') + '>' + esc(s.name) + '</option>').join('');
    }

    /** Open the dialog for milestone m, or a new one (defaults: {stage_id, due_date}). */
    function open(m, defaults) {
        const d = defaults || {};
        document.getElementById('pmsTitle').textContent = m ? T('milestones.edit_title') : T('milestones.new_title');
        document.getElementById('pmsId').value = m ? m.id : '';
        document.getElementById('pmsName').value = m ? m.name : '';
        document.getElementById('pmsDate').value = m ? m.due_date : (d.due_date || '');
        document.getElementById('pmsStage').innerHTML = stageOptions(m ? m.stage_id : d.stage_id);
        document.getElementById('pmsNotes').value = m ? (m.notes || '') : '';
        const done = document.getElementById('pmsDone');
        done.checked = !!(m && m.done_date);
        document.getElementById('pmsDoneDate').value = m && m.done_date ? m.done_date : P.todayStr();
        document.getElementById('pmsDoneDate').max = P.todayStr();
        document.getElementById('pmsDoneWrap').hidden = !done.checked;
        document.getElementById('pmsDelete').hidden = !m || !canChange();
        document.getElementById('pmsSave').hidden = !canChange();
        document.getElementById('pmsError').hidden = true;
        P.openModal('prjMilestoneModal');
        if (canChange()) setTimeout(() => document.getElementById('pmsName').focus(), 60);
    }

    /** Write a milestone (a partial body is fine) and redraw. Returns the server's reply. */
    async function save(body) {
        const was = body.id ? list().find(x => String(x.id) === String(body.id)) : null;
        const r = await P.api('tools.php', Object.assign({ action: 'milestone_save', project_id: ctx.projectId }, body));
        await ctx.refresh();
        if (body.done && !(was && was.done_date)) {
            const name = body.name || (was && was.name) || '';
            P.celebrate(document.querySelector('[data-ms-edit="' + (body.id || r.id) + '"]') || document.getElementById('pvRing'));
            P.toast(T('milestones.reached', { name: name }));
        }
        return r;
    }

    async function saveDialog() {
        const id = document.getElementById('pmsId').value;
        const err = document.getElementById('pmsError');
        const done = document.getElementById('pmsDone').checked;
        const body = {
            name: document.getElementById('pmsName').value.trim(),
            due_date: document.getElementById('pmsDate').value || null,
            stage_id: document.getElementById('pmsStage').value || null,
            notes: document.getElementById('pmsNotes').value.trim(),
            done: done,
            done_date: done ? (document.getElementById('pmsDoneDate').value || null) : null,
        };
        if (id) body.id = parseInt(id, 10);
        const wasDone = !!(id && list().find(x => String(x.id) === id && x.done_date));
        try {
            P.closeModal('prjMilestoneModal');
            await save(body);
            if (!done || wasDone) P.toast(T('milestones.saved'));   // a newly reached one already cheered
        } catch (e) {
            P.openModal('prjMilestoneModal');
            err.textContent = e.message; err.hidden = false;
        }
    }

    async function remove() {
        const id = document.getElementById('pmsId').value;
        const m = list().find(x => String(x.id) === id);
        if (!m) return;
        const ok = await window.showConfirm({ title: T('milestones.delete_title'), message: T('milestones.delete_body', { name: m.name }), okLabel: P.TC('delete'), okClass: 'danger' });
        if (!ok) return;
        try {
            await P.api('tools.php', { action: 'milestone_delete', project_id: ctx.projectId, id: m.id });
            P.closeModal('prjMilestoneModal');
            await ctx.refresh();
        } catch (e) { P.toast(e.message, 'error'); }
    }

    // ---- Wiring ---------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('pmsSave').addEventListener('click', saveDialog);
        document.getElementById('pmsDelete').addEventListener('click', remove);
        document.getElementById('pmsDone').addEventListener('change', e => { document.getElementById('pmsDoneWrap').hidden = !e.target.checked; });
        document.getElementById('pmsName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); saveDialog(); } });
        document.addEventListener('click', e => {
            if (!ctx) return;
            const ed = e.target.closest('[data-ms-edit]');
            if (ed && !ed.closest('.modal')) { e.preventDefault(); open(list().find(m => String(m.id) === ed.dataset.msEdit)); return; }
            const nw = e.target.closest('[data-ms-new]');
            if (nw) { e.preventDefault(); open(null, { stage_id: nw.dataset.msNew || '' }); return; }
            const dn = e.target.closest('[data-ms-done]');
            if (dn) {
                e.preventDefault();
                dn.disabled = true;
                save({ id: parseInt(dn.dataset.msDone, 10), done: true }).catch(err => { dn.disabled = false; P.toast(err.message, 'error'); });
            }
        });
    });

    window.PrjMilestones = {
        render: function (c) { ctx = c; renderOverview(); },
        chips: chips,
        projectStrip: projectStrip,
        diamond: diamond,
        phrase: phrase,
        open: open,
        save: save,
        list: list,
    };
})();
