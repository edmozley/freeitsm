/**
 * Projects - the portfolio page (3.2.0).
 *
 * Loads every project the analyst may see ONCE and filters in the browser:
 * the views are questions people actually ask (what is live, what do I lead,
 * what needs attention), and the search narrows whichever view is open. The
 * list is already company-scoped by the server, so nothing here decides who
 * may see what.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = P.T, esc = P.esc;

    let all = [];
    let view = 'live';
    let q = '';
    const LIVE = ['proposed', 'active', 'on_hold'];

    // Remember the chosen view per browser - a convenience, never state that matters.
    try { view = localStorage.getItem('freeitsm.projects.view') || 'live'; } catch (e) { /* private mode */ }

    function inView(p, v) {
        switch (v) {
            case 'live':     return LIVE.includes(p.status);
            case 'mine':     return LIVE.includes(p.status) && Number(p.owner_analyst_id) === Number(window.PRJ_ME);
            case 'at_risk':  return LIVE.includes(p.status) && (p.shown_health === 'red' || p.shown_health === 'amber');
            case 'proposed': return p.status === 'proposed';
            case 'on_hold':  return p.status === 'on_hold';
            case 'finished': return p.status === 'closed' || p.status === 'cancelled';
            default:         return true;
        }
    }

    function matches(p) {
        if (!q) return true;
        const hay = ((p.name || '') + ' ' + (p.goal || '') + ' ' + (p.summary || '') + ' ' + (p.code || '') + ' ' + (p.owner_name || '') + ' ' + (p.company_name || '')).toLowerCase();
        return hay.indexOf(q) !== -1;
    }

    function card(p, i) {
        const tp = P.targetPhrase(p);
        const done = p.task_total > 0
            ? T('portfolio.tasks_done', { done: p.task_done, total: p.task_total })
            : T('portfolio.no_tasks');
        const finished = p.status === 'closed' || p.status === 'cancelled';
        return '<a class="prj-card' + (finished ? ' finished' : '') + '" href="' + esc(window.PRJ_BASE + 'projects/view.php?id=' + p.id) + '" style="--i:' + i + '">'
            + '<div class="prj-card-band" style="background:' + P.gradient(p.colour) + '">'
            +   '<span class="prj-card-icon">' + P.icon(p.icon, 22) + '</span>'
            +   '<span class="prj-card-code">' + esc(p.code) + '</span>'
            +   P.statusPill(p.status)
            + '</div>'
            + '<div class="prj-card-body">'
            +   '<div class="prj-card-head">'
            +     '<div class="prj-card-titles">'
            +       '<h3>' + esc(p.name) + '</h3>'
            +       (p.goal ? '<p class="prj-card-goal">' + esc(p.goal) + '</p>' : '')
            +     '</div>'
            +     P.ring(p.progress, p.shown_health, 58)
            +   '</div>'
            +   (p.active_stage_name ? '<div class="prj-card-now"><span class="pulse"></span>' + esc(T('portfolio.now', { stage: p.active_stage_name })) + '</div>' : '')
            // The next date the project promised (3.3.0).
            +   (p.next_milestone && !finished ? '<div class="prj-card-ms" title="' + esc(T('milestones.next')) + '"><span class="prj-ms-dia ms-due" style="--s:9px" aria-hidden="true"></span>'
                    + esc(T('milestones.next_card', { name: p.next_milestone.name, date: P.fmtDate(p.next_milestone.due_date) })) + '</div>' : '')
            +   '<div class="prj-card-meta">'
            +     '<span class="prj-avatar" title="' + esc(p.owner_name ? T('portfolio.led_by', { name: p.owner_name }) : T('portfolio.nobody')) + '">' + esc(p.owner_name ? P.initials(p.owner_name) : '?') + '</span>'
            +     '<span class="prj-target ' + tp.cls + '">' + esc(tp.text) + '</span>'
            +     (p.company_name && window.PRJ_MULTI ? '<span class="prj-card-company">' + esc(p.company_name) + '</span>' : '')
            +   '</div>'
            + '</div>'
            + '<div class="prj-card-foot">'
            +   '<span>' + esc(done) + '</span>'
            +   (p.exceptions && p.exceptions.length ? '<span class="prj-exc-chip">' + esc(T('gates.exception')) + '</span>' : '')
            +   (p.task_overdue > 0 && !finished ? '<span class="prj-overdue">' + esc(T('portfolio.overdue_count', { count: p.task_overdue })) + '</span>' : '')
            +   (p.milestones_missed > 0 && !finished ? '<span class="prj-overdue">' + esc(T('view.milestones_missed', { count: p.milestones_missed })) + '</span>' : '')
            +   (p.raid_escalated > 0 && !finished ? '<span class="prj-exc-chip">' + esc(T('view.escalated', { count: p.raid_escalated })) + '</span>' : '')
            +   '<span class="prj-card-bar"><span style="width:' + p.progress + '%;background:' + P.gradient(p.colour) + '"></span></span>'
            + '</div>'
            + '</a>';
    }

    function render() {
        const grid = document.getElementById('prjGrid');
        const rows = all.filter(p => inView(p, view) && matches(p));

        document.querySelectorAll('#prjViews li').forEach(li => li.classList.toggle('active', li.dataset.view === view));
        document.querySelectorAll('#prjViews [data-count]').forEach(b => {
            const n = all.filter(p => inView(p, b.dataset.count)).length;
            b.textContent = n || '';
        });

        const live = all.filter(p => LIVE.includes(p.status));
        const set = (k, v) => { const el = document.querySelector('[data-tile="' + k + '"]'); if (el) el.textContent = v; };
        set('live', live.length);
        set('at_risk', live.filter(p => p.shown_health === 'red' || p.shown_health === 'amber').length);
        set('due', live.filter(p => { const d = P.daysTo(p.target_end_date); return d !== null && d >= 0 && d <= 30; }).length);
        set('overdue', live.reduce((n, p) => n + (p.task_overdue || 0), 0));

        document.getElementById('prjCount').textContent = rows.length === 1 ? T('portfolio.count_one') : T('portfolio.count', { count: rows.length });
        document.getElementById('prjEmpty').hidden = all.length > 0;
        document.getElementById('prjTiles').hidden = all.length === 0;
        document.querySelector('.prj-toolbar').hidden = all.length === 0;
        document.getElementById('prjFilteredEmpty').hidden = all.length === 0 || rows.length > 0;
        grid.innerHTML = rows.map(card).join('');
    }

    async function load() {
        try {
            const d = await P.api('list.php');
            all = d.projects || [];
            window.PRJ_MULTI = !!d.multi_company;
            // Projects -> Settings -> General may keep creating to people who manage Projects.
            document.getElementById('prjNew').hidden = !d.can_create;
            document.getElementById('prjEmptyNew').hidden = !d.can_create;
            const L = await P.lookups();
            P.setPalette(L.colours);
            render();
        } catch (e) {
            P.toast(e.message, 'error');
        }
    }

    function newProject() {
        P.openProjectForm(null, id => { window.location.href = window.PRJ_BASE + 'projects/view.php?id=' + id + '&new=1'; });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('prjViews').addEventListener('click', e => {
            const li = e.target.closest('li[data-view]'); if (!li) return;
            view = li.dataset.view;
            try { localStorage.setItem('freeitsm.projects.view', view); } catch (err) { /* private mode */ }
            render();
        });
        let timer = null;
        document.getElementById('prjSearch').addEventListener('input', e => {
            clearTimeout(timer);
            timer = setTimeout(() => { q = e.target.value.trim().toLowerCase(); render(); }, 120);
        });
        document.getElementById('prjNew').addEventListener('click', newProject);
        document.getElementById('prjEmptyNew').addEventListener('click', newProject);
        load();
    });
})();
