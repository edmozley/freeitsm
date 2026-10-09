/**
 * Projects - the Overview's status and flow charts (3.3.0):
 *   Task status       a stacked bar per stage (and the whole project): how many
 *                     tasks sit in each of the install's task statuses, with how
 *                     many are overdue and how many wait on another task
 *   Progress by stage done against not done, per stage, with the percentage
 *   Flow over time    the cumulative flow diagram (includes/projects/flow.php)
 *
 * 🔑 A status keeps ITS colour in both charts: its slot is its place in the
 * board's order, never its place in a chart (color follows the entity). Six
 * validated slots; a seventh status and on share the neutral. Days before the
 * flow was first recorded are two neutral bands - finished, and open - with a
 * marker where real history starts.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = (k, p) => P.T('insights.' + k, p);
    const esc = P.esc;
    let ctx = null;

    const slotOf = i => i < 6 ? i + 1 : 'n';

    function render(c) {
        ctx = c;
        const box = document.getElementById('pvInsights');
        if (!box) return;
        if (!(ctx.data.tasks || []).length) { box.innerHTML = ''; return; }
        box.innerHTML = '<div class="prj-panel prj-ins"><h3>' + esc(T('status_title')) + '</h3><p class="prj-muted sm prj-ins-note"></p><div class="prj-ins-status"></div></div>'
            + '<div class="prj-panel prj-ins"><h3>' + esc(T('progress_title')) + '</h3><div class="prj-ins-progress"></div></div>'
            + '<div class="prj-panel prj-ins"><h3>' + esc(T('flow_title')) + '</h3><p class="prj-muted sm prj-ins-flownote"></p><div class="prj-ins-flow"></div></div>';
        draw();
    }

    /** Drawn when the Overview is showing - the charts measure their width. */
    function draw() {
        const box = document.getElementById('pvInsights');
        const panel = document.getElementById('pvOverview');
        if (!box || !ctx || !window.PrjCharts || !box.firstChild || (panel && panel.hidden)) return;
        const d = ctx.data;
        const statuses = (d.flow && d.flow.statuses) || [];
        const today = P.todayStr();
        const tasks = d.tasks || [];
        // ---- Task status, per stage.
        const groups = [{ label: T('whole'), tasks: tasks }].concat(d.stages.map(s => ({ label: s.name, tasks: tasks.filter(t => String(t.project_stage_id) === String(s.id)) })))
            .concat(tasks.some(t => !t.project_stage_id) && d.stages.length ? [{ label: T('no_stage'), tasks: tasks.filter(t => !t.project_stage_id) }] : [])
            .filter(g => g.tasks.length);
        const series = statuses.map((s, i) => ({ name: s.name, slot: slotOf(i) }));
        const rows = groups.map(g => {
            const overdue = g.tasks.filter(t => !Number(t.is_closed) && t.due_date && t.due_date < today).length;
            const waiting = g.tasks.filter(t => !Number(t.is_closed) && (t.waiting_on || []).length).length;
            const note = [overdue ? T('overdue', { count: overdue }) : '', waiting ? T('waiting', { count: waiting }) : ''].filter(Boolean).join(' - ');
            return { label: g.label, values: statuses.map(s => g.tasks.filter(t => Number(t.status_id) === s.id).length), note: note };
        });
        const all = rows[0] || { values: [] };
        const overdueAll = tasks.filter(t => !Number(t.is_closed) && t.due_date && t.due_date < today).length;
        box.querySelector('.prj-ins-note').textContent = T('status_note', { total: tasks.length, overdue: overdueAll });
        window.PrjCharts.stack(box.querySelector('.prj-ins-status'), {
            rows: rows, series: series,
            labels: { table: T('table'), chart: T('chart'), group: T('col_stage'), total: T('col_total'), aria: T('status_aria', { total: tasks.length }) },
        });
        // ---- Progress by stage: done against not done.
        const prog = groups.map(g => {
            const done = g.tasks.filter(t => Number(t.is_closed)).length;
            return { label: g.label, values: [done, g.tasks.length - done] };
        });
        window.PrjCharts.stack(box.querySelector('.prj-ins-progress'), {
            rows: prog, series: [{ name: T('done'), slot: 1 }, { name: T('not_done'), slot: 'n' }],
            totalText: (r, total) => (total ? Math.round(r.values[0] * 100 / total) : 0) + '%',
            labels: { table: T('table'), chart: T('chart'), group: T('col_stage'), total: T('col_total'), aria: T('progress_aria') },
        });
        // ---- Flow over time.
        const fl = d.flow;
        const fbox = box.querySelector('.prj-ins-flow');
        const fnote = box.querySelector('.prj-ins-flownote');
        if (!fl || !fl.points || fl.points.length < 2) { fnote.textContent = T('flow_soon'); fbox.textContent = ''; return; }
        // Bottom-up: finished statuses, then "finished before history"; open statuses last-first, then "open before history".
        const closed = statuses.map((s, i) => ({ s: s, i: i })).filter(x => x.s.closed).reverse();
        const open = statuses.map((s, i) => ({ s: s, i: i })).filter(x => !x.s.closed).reverse();
        const pre = fl.points.some(p => !p.recorded);
        const order = closed.map(x => ({ key: x.s.id, name: x.s.name, slot: slotOf(x.i) }))
            .concat(pre ? [{ key: 'done', name: T('pre_done'), slot: 'n2' }] : [])
            .concat(open.map(x => ({ key: x.s.id, name: x.s.name, slot: slotOf(x.i) })))
            .concat(pre ? [{ key: 'open', name: T('pre_open'), slot: 'n' }] : []);
        const points = fl.points.map(p => ({ d: p.d, values: order.map(o => Number((p.counts || {})[o.key] || 0)) }));
        fnote.textContent = fl.from ? T(pre ? 'flow_note_pre' : 'flow_note', { date: P.fmtDate(fl.from) }) : T('flow_soon');
        window.PrjCharts.flow(fbox, {
            points: points, series: order, fmtDate: P.fmtDate,
            marker: pre && fl.from ? { d: fl.from, label: T('history_from') } : null,
            labels: { table: T('table'), chart: T('chart'), date: T('col_date'), aria: T('flow_aria') },
        });
    }

    window.PrjInsights = { render: render, draw: draw };
})();
