/**
 * Projects - the Capacity page (3.3.0).
 *
 * One row per person, one cell per week: their load - project work plus
 * service-desk duty - against the hours they work in a week, coloured green,
 * amber or red. A row opens to show the project tasks behind the numbers.
 * Everything is worked out by the server (includes/projects/capacity.php);
 * this file only draws it.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = P.T, esc = P.esc;
    let weeks = 4;
    let data = null;
    const open = new Set();   // analyst ids whose detail is showing
    try { const w = parseInt(localStorage.getItem('prjCapacityWeeks'), 10); if ([4, 8, 12].includes(w)) weeks = w; } catch (e) { /* private window */ }

    const hrs = h => (Math.round(h * 10) / 10).toLocaleString(document.documentElement.lang || undefined) + 'h';

    function tiles(c) {
        const now = c.rows.map(r => r.weeks[0]);
        const red = now.filter(w => w.state === 'red').length;
        const amber = now.filter(w => w.state === 'amber').length;
        const several = c.rows.filter(r => r.several).length;
        const noEst = c.rows.reduce((n, r) => n + r.no_estimate, 0);
        const tile = (n, label, cls) => '<div class="prj-tile ' + cls + '"><span class="prj-tile-num">' + esc(n) + '</span><span class="prj-tile-label">' + esc(label) + '</span></div>';
        return tile(red, T('capacity.tile_over'), red ? 'bad' : '')
            + tile(amber, T('capacity.tile_stretched'), amber ? 'warn' : '')
            + tile(several, T('capacity.tile_several', { n: c.several }), several ? 'warn' : '')
            + tile(noEst + (c.team_tasks ? ' / ' + c.team_tasks : ''), c.team_tasks ? T('capacity.tile_noest_team') : T('capacity.tile_noest'), noEst || c.team_tasks ? 'warn' : '');
    }

    function cell(w, c) {
        const width = Math.min(100, w.load_pct);
        const tip = T('capacity.cell_tip', { project: hrs(w.project), desk: hrs(w.desk), hours: hrs(c.hours_per_week), pct: w.load_pct });
        return '<td class="prj-cap-cell s-' + w.state + '" title="' + esc(tip) + '">'
            + '<span class="prj-cap-bar"><span class="prj-cap-fill" style="width:' + width + '%"></span>'
            + (w.desk > 0 ? '<span class="prj-cap-desk" style="width:' + Math.min(100, Math.round(w.desk * 100 / c.hours_per_week)) + '%"></span>' : '') + '</span>'
            + '<span class="prj-cap-pct">' + esc(w.load_pct) + '%</span></td>';
    }

    function detail(r, c) {
        if (!r.tasks.length) return '<p class="prj-muted">' + esc(T('capacity.no_tasks')) + '</p>';
        const head = '<tr><th>' + esc(T('capacity.col_task')) + '</th><th>' + esc(T('capacity.col_project')) + '</th><th>' + esc(T('capacity.col_left')) + '</th><th>' + esc(T('capacity.col_due')) + '</th>'
            + c.weeks.map(w => '<th class="num">' + esc(P.fmtDate(w.start)) + '</th>').join('') + '</tr>';
        const rows = r.tasks.map(t => {
            const left = t.state === 'no_estimate' ? '<span class="prj-cap-flag">' + esc(T('capacity.no_estimate')) + '</span>'
                : (t.estimate_hours !== null ? esc(hrs(t.remaining_hours)) + ' <span class="prj-muted">/ ' + esc(hrs(t.estimate_hours)) + '</span>' : '');
            const due = t.due_date ? '<span class="' + (t.late ? 'prj-due late' : 'prj-due') + '">' + esc(P.fmtDate(t.due_date)) + '</span>' : '<span class="prj-cap-flag">' + esc(T('capacity.no_due')) + '</span>';
            return '<tr><td><a href="' + esc(window.PRJ_BASE + 'tasks/?task=' + t.id) + '">' + esc(t.title) + '</a></td>'
                + '<td><a href="' + esc(window.PRJ_BASE + 'projects/view.php?id=' + t.project_id) + '" title="' + esc(t.project_name) + '">' + esc(t.project_code) + '</a></td>'
                + '<td>' + left + '</td><td>' + due + '</td>'
                + t.weeks.map(h => '<td class="num">' + (h > 0 ? esc(hrs(h)) : '<span class="prj-muted">-</span>') + '</td>').join('') + '</tr>';
        }).join('');
        const notes = [];
        if (r.unscheduled > 0) notes.push(T('capacity.note_unscheduled', { hours: hrs(r.unscheduled) }));
        if (r.later > 0) notes.push(T('capacity.note_later', { hours: hrs(r.later) }));
        if (r.late > 0) notes.push(T('capacity.note_late', { count: r.late }));
        return '<div class="prj-cap-detail-scroll"><table class="prj-cap-detail"><thead>' + head + '</thead><tbody>' + rows + '</tbody></table></div>'
            + (notes.length ? '<p class="prj-hint">' + esc(notes.join(' ')) + '</p>' : '');
    }

    function render() {
        const c = data;
        const body = document.getElementById('capBody');
        document.getElementById('capIntro').textContent = T(c.can_tickets && c.count_desk ? 'capacity.intro' : 'capacity.intro_no_desk', { hours: hrs(c.hours_per_week), amber: c.amber });
        document.querySelectorAll('[data-weeks]').forEach(b => b.classList.toggle('active', Number(b.dataset.weeks) === weeks));
        if (!c.estimates_ready) { body.innerHTML = '<div class="prj-plan-empty">' + esc(T('capacity.not_ready')) + '</div>'; document.getElementById('capTiles').innerHTML = ''; return; }
        if (!c.rows.length) {
            document.getElementById('capTiles').innerHTML = '';
            body.innerHTML = '<div class="prj-plan-empty">' + esc(T('capacity.empty')) + '</div>';
            return;
        }
        document.getElementById('capTiles').innerHTML = tiles(c);
        const cols = c.weeks.length + 2 + (c.can_tickets ? 1 : 0) + 1;
        let html = '<div class="prj-cap-scroll"><table class="prj-cap"><thead><tr><th class="who">' + esc(T('capacity.col_person')) + '</th>'
            + c.weeks.map((w, i) => '<th class="wk">' + esc(i === 0 ? T('capacity.this_week') : T('capacity.week_of', { date: P.fmtDate(w.start) })) + '</th>').join('')
            + '<th class="num">' + esc(T('capacity.col_projects')) + '</th>'
            + (c.can_tickets ? '<th class="num">' + esc(T('capacity.col_tickets')) + '</th>' : '')
            + '<th class="num">' + esc(T('capacity.col_unscheduled')) + '</th></tr></thead><tbody>';
        c.rows.forEach(r => {
            const isOpen = open.has(r.analyst_id);
            html += '<tr class="prj-cap-row' + (isOpen ? ' open' : '') + '" data-cap="' + r.analyst_id + '" tabindex="0" aria-expanded="' + isOpen + '">'
                + '<th class="who" scope="row"><span class="prj-cap-caret" aria-hidden="true"></span><span class="prj-avatar sm">' + esc(P.initials(r.name)) + '</span><span class="prj-cap-name">' + esc(r.name) + '</span>'
                + (r.no_estimate ? '<span class="prj-cap-flag" title="' + esc(T('capacity.noest_tip', { count: r.no_estimate })) + '">?' + r.no_estimate + '</span>' : '') + '</th>'
                + r.weeks.map(w => cell(w, c)).join('')
                + '<td class="num' + (r.several ? ' warn' : '') + '" title="' + esc(r.projects.map(p => p.code + ' ' + p.name).join(', ')) + '">' + esc(r.projects.length) + '</td>'
                + (c.can_tickets ? '<td class="num">' + esc(r.open_tickets ?? 0) + '</td>' : '')
                + '<td class="num">' + (r.unscheduled > 0 ? esc(hrs(r.unscheduled)) : '<span class="prj-muted">-</span>') + '</td></tr>';
            if (isOpen) html += '<tr class="prj-cap-more"><td colspan="' + cols + '">' + detail(r, c) + '</td></tr>';
        });
        html += '</tbody></table></div>';
        html += '<div class="prj-cap-legend"><span><i class="s-green"></i>' + esc(T('capacity.legend_ok')) + '</span><span><i class="s-amber"></i>' + esc(T('capacity.legend_amber', { amber: c.amber }))
            + '</span><span><i class="s-red"></i>' + esc(T('capacity.legend_red')) + '</span>' + (c.can_tickets && c.count_desk ? '<span><i class="desk"></i>' + esc(T('capacity.legend_desk')) + '</span>' : '') + '</div>';
        if (c.team_tasks) html += '<p class="prj-hint">' + esc(T('capacity.team_note', { count: c.team_tasks, hours: hrs(c.team_hours) })) + '</p>';
        // Contractors (3.3.0): suppliers' open work, kept out of everybody's load above.
        if ((c.contractors || []).length) {
            html += '<h3 class="prj-cap-ctr-h">' + esc(T('capacity.contractors')) + '</h3><p class="prj-hint" style="margin-top:0">' + esc(T('capacity.contractors_intro')) + '</p>'
                + '<div class="prj-table-wrap"><table class="prj-budget-table"><thead><tr><th>' + esc(T('contractors.supplier')) + '</th><th class="num">' + esc(T('capacity.ctr_tasks')) + '</th><th class="num">' + esc(T('capacity.ctr_hours')) + '</th><th class="num">' + esc(T('capacity.ctr_late')) + '</th></tr></thead><tbody>'
                + c.contractors.map(x => '<tr><td>' + esc(x.name) + '</td><td class="num">' + esc(x.tasks) + '</td><td class="num">' + (x.hours > 0 ? esc(hrs(x.hours)) : '<span class="prj-muted">-</span>') + (x.no_estimate ? ' <small class="prj-muted">' + esc(T('capacity.ctr_noest', { count: x.no_estimate })) + '</small>' : '') + '</td><td class="num' + (x.late ? ' warn' : '') + '">' + esc(x.late) + '</td></tr>').join('')
                + '</tbody></table></div>';
        }
        html += '<p class="prj-hint">' + esc(T('capacity.how')) + '</p>';
        body.innerHTML = html;
    }

    async function load() {
        try {
            const d = await P.api('capacity.php?weeks=' + weeks);
            data = d.capacity;
            render();
        } catch (e) {
            document.getElementById('capBody').innerHTML = '<div class="prj-plan-empty">' + esc(e.message) + '</div>';
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const page = document.getElementById('prjCapacity');
        page.addEventListener('click', e => {
            const w = e.target.closest('[data-weeks]');
            if (w) {
                weeks = Number(w.dataset.weeks);
                try { localStorage.setItem('prjCapacityWeeks', String(weeks)); } catch (err) { /* ignore */ }
                load();
                return;
            }
            const row = e.target.closest('[data-cap]');
            if (row && !e.target.closest('a')) {
                const id = Number(row.dataset.cap);
                if (open.has(id)) open.delete(id); else open.add(id);
                render();
                // A keyboard press (detail 0) keeps its place on the redrawn row.
                if (e.detail === 0) { const again = page.querySelector('[data-cap="' + id + '"]'); if (again) again.focus(); }
            }
        });
        page.addEventListener('keydown', e => {
            const row = e.target.closest('[data-cap]');
            if (row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); row.click(); }
        });
        load();
    });
})();
