/**
 * Projects - the portfolio page (3.2.0).
 *
 * Loads every project the analyst may see ONCE and filters in the browser:
 * the views are questions people actually ask (what is live, what do I lead,
 * what needs attention), and the search narrows whichever view is open. The
 * list is already company-scoped by the server, so nothing here decides who
 * may see what.
 *
 * @wiki Projects-Internals-1-Architecture
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = P.T, esc = P.esc;

    let all = [];
    let view = 'live';
    let q = '';
    let sort = null;        // null until lookups say the install's default (Projects -> Settings -> General)
    let layout = 'cards';   // cards | roadmap (3.3.0)
    try { sort = localStorage.getItem('freeitsm.projects.sort'); layout = localStorage.getItem('freeitsm.projects.layout') || 'cards'; } catch (e) { /* private mode */ }
    const LIVE = ['proposed', 'active', 'on_hold'];

    // Remember the chosen view per browser - a convenience, never state that matters.
    try { view = localStorage.getItem('freeitsm.projects.view') || 'live'; } catch (e) { /* private mode */ }

    /** True when a project belongs in view v - live, mine, at risk, proposed, waiting for approval, on hold, finished or all. */
    function inView(p, v) {
        switch (v) {
            case 'live':     return LIVE.includes(p.status);
            case 'mine':     return LIVE.includes(p.status) && Number(p.owner_analyst_id) === Number(window.PRJ_ME);
            case 'at_risk':  return LIVE.includes(p.status) && (p.shown_health === 'red' || p.shown_health === 'amber');
            case 'proposed': return p.status === 'proposed';
            case 'approval': return p.approval_status === 'pending';   // 3.3.0 intake
            case 'on_hold':  return p.status === 'on_hold';
            case 'finished': return p.status === 'closed' || p.status === 'cancelled';
            default:         return true;
        }
    }

    // ---- Order (3.3.0) --------------------------------------------------------------
    const PRIO = { critical: 0, high: 1, medium: 2, low: 3 };
    const HEALTH = { red: 0, amber: 1, green: 2 };
    /** Sort order by target finish (projects with none last), then by name. */
    const byTarget = (a, b) => (a.target_end_date || '9999').localeCompare(b.target_end_date || '9999') || a.name.localeCompare(b.name);
    /** The rows in the chosen order - priority, health or name, each then by target; 'target' keeps the server's order. */
    function sorted(rows) {
        const r = rows.slice();
        if (sort === 'priority') r.sort((a, b) => (PRIO[a.priority || 'medium'] - PRIO[b.priority || 'medium']) || byTarget(a, b));
        else if (sort === 'health') r.sort((a, b) => ((HEALTH[a.shown_health] ?? 3) - (HEALTH[b.shown_health] ?? 3)) || byTarget(a, b));
        else if (sort === 'name') r.sort((a, b) => a.name.localeCompare(b.name));
        // 'target' keeps the server's order: live before finished, then by target date.
        return r;
    }

    // ---- How the live projects stand (3.3.0) ------------------------------------------------
    /**
     * One stacked bar, part to whole: how many live projects are on track, at
     * risk, off track, or have no health yet. Health colours are STATUS colours,
     * and each segment carries its word and count in the legend (and a tooltip),
     * so colour never carries the meaning alone - green and amber sit close for
     * protan vision (palette validator, 2026-10-09).
     */
    function healthStrip() {
        const box = document.getElementById('prjHealthStrip');
        const live = all.filter(p => LIVE.includes(p.status));
        box.hidden = live.length === 0;
        if (!live.length) { box.innerHTML = ''; return; }
        const parts = [['green', live.filter(p => p.shown_health === 'green').length], ['amber', live.filter(p => p.shown_health === 'amber').length],
                       ['red', live.filter(p => p.shown_health === 'red').length], ['none', live.filter(p => !p.shown_health).length]];
        // 'none' is a live project with no health worked out - not the ring's "Finished"; it only shows when there is one.
        const word = k => k === 'none' ? T('portfolio.health_unknown') : T('health.' + k);
        box.innerHTML = '<div class="prj-hstrip-head"><h3>' + esc(T('portfolio.health_title')) + '</h3></div>'
            + '<div class="prj-hstrip" role="img" aria-label="' + esc(parts.filter(x => x[1]).map(x => word(x[0]) + ': ' + x[1]).join(', ')) + '">'
            + parts.filter(x => x[1]).map(([k, n]) => '<span class="prj-hseg h-' + k + '" style="flex-grow:' + n + '" tabindex="0" title="' + esc(word(k) + ': ' + n) + '"></span>').join('')
            + '</div><div class="prj-hlegend">'
            + parts.filter(([k, n]) => k !== 'none' || n).map(([k, n]) => '<span class="' + (n ? '' : 'zero') + '"><i class="h-' + k + '"></i>' + esc(word(k)) + ' <b>' + n + '</b></span>').join('') + '</div>';
    }

    // ---- The roadmap (3.3.0) -------------------------------------------------------------------
    /**
     * Every project in the view as a bar from its start to its target finish, in
     * its own colour (identity), filled to its progress, with its next milestone
     * as a diamond, a line for today. Health is on the row's label as a word, not
     * on the bar - the bar's colour already means "which project". The cards are
     * the table twin. Built from the Timeline's own classes (.prj-tl-*).
     */
    function roadmap(rows) {
        const box = document.getElementById('prjRoadmap');
        /** A Y-m-d date as a whole day number (days since 1970, UTC). */
        const dn = s => Math.floor(Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)) / 86400000);
        const today = dn(P.todayStr());
        const spans = rows.map(p => {
            const s = p.start_date || (p.created_datetime || '').slice(0, 10) || null;
            const e = p.actual_end_date || p.target_end_date || null;
            return s && e ? [dn(s), Math.max(dn(s), dn(e))] : null;
        });
        const days = [today]; spans.forEach(sp => { if (sp) days.push(sp[0], sp[1]); });
        rows.forEach(p => { if (p.next_milestone) days.push(dn(p.next_milestone.due_date)); });
        const first = Math.min(...days) - 7, last = Math.max(...days) + 14;
        const start = first - (new Date(first * 86400000).getUTCDate() - 1);   // the 1st of a month
        const LW = window.matchMedia && window.matchMedia('(max-width: 768px)').matches ? 140 : 280;
        const avail = Math.max(300, box.parentNode.clientWidth - LW - 20);
        const dayW = Math.max(2, avail / (last - start + 1));
        /** The x position of a day number on the roadmap track. */
        const x = n => (n - start) * dayW;
        const trackW = Math.ceil((last - start + 1) * dayW);
        const fmt = new Intl.DateTimeFormat(document.documentElement.lang || undefined, { month: 'short', year: 'numeric', timeZone: 'UTC' });
        let months = '';
        for (let n = start; n <= last;) {
            const d = new Date(n * 86400000);
            const next = Math.min(last + 1, Math.floor(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 1) / 86400000));
            months += '<div class="prj-tl-month" style="left:' + x(n) + 'px;width:' + (next - n) * dayW + 'px"><span>' + esc(fmt.format(d)) + '</span></div>';
            n = next;
        }
        const rowsHtml = rows.map((p, i) => {
            const sp = spans[i];
            const url = window.PRJ_BASE + 'projects/view.php?id=' + p.id;
            const health = p.shown_health ? '<span class="prj-rm-health h-' + esc(p.shown_health) + '">' + esc(T('health.' + p.shown_health)) + '</span>' : '';
            let bar = '<span class="prj-rm-nodates">' + esc(T('portfolio.roadmap_no_dates')) + '</span>';
            if (sp) {
                const tip = p.code + ' ' + p.name + ' - ' + P.fmtDate(p.start_date || (p.created_datetime || '').slice(0, 10)) + ' - ' + P.fmtDate(p.actual_end_date || p.target_end_date) + ' - ' + p.progress + '%';
                bar = '<a class="prj-tl-band prj-rm-bar" href="' + esc(url) + '" style="left:' + x(sp[0]) + 'px;width:' + Math.max(dayW, (sp[1] - sp[0] + 1) * dayW) + 'px;background:' + P.gradient(p.colour) + '" title="' + esc(tip) + '">'
                    + '<span class="prj-rm-progress" style="width:' + p.progress + '%"></span><span class="prj-tl-bar-text">' + esc(p.progress + '%') + '</span></a>';
            }
            const ms = p.next_milestone ? '<span class="prj-tl-ms ms-due" style="left:' + (x(dn(p.next_milestone.due_date)) + dayW / 2) + 'px" title="' + esc(T('milestones.next') + ': ' + p.next_milestone.name + ' - ' + P.fmtDate(p.next_milestone.due_date)) + '"><span class="prj-tl-ms-dia"></span></span>' : '';
            return '<div class="prj-tl-row band" style="height:40px"><a class="prj-tl-label task prj-rm-label" style="width:' + LW + 'px" href="' + esc(url) + '" title="' + esc(p.code + ' ' + p.name) + '">'
                + '<span class="prj-card-icon-dot" style="background:' + P.gradient(p.colour) + '"></span><span class="prj-tl-label-text">' + esc(p.name) + '</span>' + P.priorityChip(p.priority) + health + '</a>'
                + '<div class="prj-tl-track" style="width:' + trackW + 'px">' + bar + ms + '</div></div>';
        }).join('');
        box.innerHTML = '<div class="prj-tl-scroll prj-rm-scroll"><div class="prj-tl-inner z-months" style="width:' + (LW + trackW) + 'px;--lw:' + LW + 'px">'
            + '<div class="prj-tl-head" style="height:34px"><div class="prj-tl-label head" style="width:' + LW + 'px">' + esc(T('portfolio.roadmap_col')) + '</div>'
            + '<div class="prj-tl-head-track" style="width:' + trackW + 'px"><div class="prj-tl-months" style="height:34px">' + months + '</div></div></div>'
            + '<div class="prj-tl-body">' + rowsHtml + '</div>'
            + '<div class="prj-tl-line today" style="top:34px;left:' + (LW + x(today) + dayW / 2) + 'px"><span>' + esc(T('timeline.today')) + '</span></div>'
            + '</div></div><p class="prj-hint">' + esc(T('portfolio.roadmap_hint')) + '</p>';
        const sc = box.querySelector('.prj-tl-scroll');
        sc.scrollLeft = Math.max(0, x(today) - (sc.clientWidth - LW) / 3);
    }

    /** True when a project matches the search text - name, goal, summary, code, owner or company. */
    function matches(p) {
        if (!q) return true;
        const hay = ((p.name || '') + ' ' + (p.goal || '') + ' ' + (p.summary || '') + ' ' + (p.code || '') + ' ' + (p.owner_name || '') + ' ' + (p.company_name || '')).toLowerCase();
        return hay.indexOf(q) !== -1;
    }

    /** One project's card on the portfolio - colour band, code, priority, status, name, goal, progress ring, current stage, next milestone, owner, target, and chips for what needs attention. */
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
            +   (p.visibility === 'members' ? '<span class="prj-private" title="' + esc(T('visibility.members_hint')) + '">' + esc(T('visibility.badge')) + '</span>' : '')
            +   P.priorityChip(p.priority) + P.statusPill(p.status)
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
            +   (p.benefits_due > 0 && (p.tools || []).includes('benefits') ? '<span class="prj-ctl-chip">' + esc(T('benefits.due_chip', { count: p.benefits_due })) + '</span>' : '')
            +   (p.approval_status === 'pending' ? '<span class="prj-ctl-chip">' + esc(T('intake.waiting')) + '</span>' : '')
            +   (p.changes_pending > 0 && !finished && (p.tools || []).includes('control') ? '<span class="prj-ctl-chip">' + esc(T('control.waiting', { count: p.changes_pending })) + '</span>' : '')
            +   '<span class="prj-card-bar"><span style="width:' + p.progress + '%;background:' + P.gradient(p.colour) + '"></span></span>'
            + '</div>'
            + '</a>';
    }

    /** Redraws the portfolio for the current view, search, sort and layout - view counts, the summary tiles, the health strip and the cards, roadmap or charts. */
    function render() {
        const grid = document.getElementById('prjGrid');
        const rows = all.filter(p => inView(p, view) && matches(p));

        document.querySelectorAll('#prjViews li').forEach(li => li.classList.toggle('active', li.dataset.view === view));
        document.querySelectorAll('#prjViews [data-count]').forEach(b => {
            const n = all.filter(p => inView(p, b.dataset.count)).length;
            b.textContent = n || '';
        });

        const live = all.filter(p => LIVE.includes(p.status));
        /** Writes a value into the summary tile with this key. */
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
        const ordered = sorted(rows);
        document.getElementById('prjSort').value = sort;
        document.querySelectorAll('[data-layout]').forEach(b => b.classList.toggle('active', b.dataset.layout === layout));
        healthStrip();
        grid.hidden = layout !== 'cards';
        document.getElementById('prjRoadmap').hidden = layout !== 'roadmap' || !ordered.length;
        document.getElementById('prjCharts').hidden = layout !== 'charts' || !ordered.length;
        if (layout === 'roadmap') { grid.innerHTML = ''; if (ordered.length) roadmap(ordered); }
        else if (layout === 'charts') { grid.innerHTML = ''; if (ordered.length) charts(ordered); }
        else grid.innerHTML = ordered.map(card).join('');
    }

    // ---- Charts across projects (3.3.0) ---------------------------------------------------
    // The projects in the view, as four charts: progress, budget against actual,
    // a risk heat map of every open risk, and every milestone on one calendar.
    // Their data beyond list.php comes once from portfolio_charts.php.
    let chartData = null, heatCell = null;
    /** A name cut to 28 characters with an ellipsis, for chart labels. */
    const short = s => s.length > 28 ? s.slice(0, 27) + '…' : s;

    /** The Charts layout - progress per project, budget against actual (one chart per currency), a risk heat map whose cells list their risks, and every milestone on one calendar; the extra data is fetched once from portfolio_charts.php. */
    async function charts(ordered) {
        const box = document.getElementById('prjCharts');
        if (!chartData) {
            box.innerHTML = '<p class="prj-muted">' + esc(T('portfolio.charts_loading')) + '</p>';
            try { chartData = await P.api('portfolio_charts.php'); } catch (e) { box.innerHTML = '<p class="prj-muted">' + esc(e.message) + '</p>'; return; }
        }
        const L = await P.lookups();
        const inView = new Set(ordered.map(p => p.id));
        /** A project's short name for chart labels, or #id when it is not in the view. */
        const name = id => { const p = ordered.find(x => x.id === id); return p ? short(p.name) : '#' + id; };
        box.innerHTML = '<div class="prj-panel prj-pc"><h3>' + esc(T('portfolio.chart_progress')) + '</h3><div class="prj-pc-progress"></div></div>'
            + '<div class="prj-panel prj-pc"><h3>' + esc(T('portfolio.chart_budget')) + '</h3><p class="prj-muted sm prj-pc-budgetnote"></p><div class="prj-pc-budget"></div></div>'
            // #282 slice 5: costs and income by account, added up across the projects in view.
            + '<div class="prj-panel prj-pc prj-pc-accounts" hidden><div class="prj-panel-head"><h3>' + esc(T('budget.ba_title')) + '</h3><span class="prj-acc-export"></span></div><p class="prj-muted sm">' + esc(T('portfolio.ba_note')) + '</p><div class="prj-pc-acc"></div></div>'
            + '<div class="prj-pc-two"><div class="prj-panel prj-pc"><h3>' + esc(T('portfolio.chart_risks')) + '</h3><p class="prj-muted sm">' + esc(T('portfolio.chart_risks_hint')) + '</p><div class="prj-pc-heat"></div></div>'
            + '<div class="prj-panel prj-pc"><h3>' + esc(T('portfolio.chart_milestones')) + '</h3><div class="prj-pc-ms"></div></div></div>';
        const C = window.PrjCharts;
        // Progress: tasks done against not done, per project.
        const prog = ordered.filter(p => p.task_total > 0);
        const pbox = box.querySelector('.prj-pc-progress');
        if (prog.length) C.stack(pbox, {
            rows: prog.map(p => ({ label: short(p.name), values: [p.task_done, p.task_total - p.task_done] })),
            series: [{ name: T('portfolio.done'), slot: 1 }, { name: T('portfolio.not_done'), slot: 'n' }],
            totalText: (r, total) => (total ? Math.round(r.values[0] * 100 / total) : 0) + '%',
            labels: { table: T('portfolio.table'), chart: T('portfolio.chart'), group: T('portfolio.project'), total: T('portfolio.tasks'), aria: T('portfolio.chart_progress') },
        }); else pbox.innerHTML = '<p class="prj-muted">' + esc(T('portfolio.no_tasks')) + '</p>';
        // Budget against actual, one chart per currency - amounts in different currencies are never on one axis.
        const bud = Object.entries(chartData.budgets || {}).map(([id, b]) => Object.assign({ id: Number(id) }, b)).filter(b => inView.has(b.id) && (b.planned > 0 || b.actual > 0));
        const bbox = box.querySelector('.prj-pc-budget');
        const curs = [...new Set(bud.map(b => b.currency))];
        box.querySelector('.prj-pc-budgetnote').textContent = bud.length ? T('portfolio.chart_budget_note') : '';
        if (!bud.length) bbox.innerHTML = '<p class="prj-muted">' + esc(T('portfolio.no_budgets')) + '</p>';
        curs.forEach(cur => {
            /** An amount in this chart's currency, whole units, for the viewer's locale. */
            const money = v => { if (window.Money) return Money.format(v, cur, Money.formatFor(cur).replace(/:[^:]*$/, ':0')); try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur, maximumFractionDigits: 0 }).format(v); } catch (e) { return cur + ' ' + Math.round(v); } };
            const host = document.createElement('div');
            if (curs.length > 1) { const h = document.createElement('h4'); h.className = 'prj-pc-cur'; h.textContent = cur; bbox.appendChild(h); }
            bbox.appendChild(host);
            const list = bud.filter(b => b.currency === cur).sort((a, b) => (b.actual / (b.planned || 1)) - (a.actual / (a.planned || 1)));
            C.bars(host, {
                rows: list.map(b => ({ label: name(b.id), a: b.planned || null, b: b.actual || null,
                    note: b.planned > 0 && b.actual > b.planned ? T('portfolio.over', { pct: Math.round((b.actual / b.planned - 1) * 100) })
                        : (b.planned > 0 && b.forecast > b.planned ? T('portfolio.forecast_over', { pct: Math.round((b.forecast / b.planned - 1) * 100) }) : '') })),
                series: [T('portfolio.planned'), T('portfolio.actual')], fmt: money,
                labels: { table: T('portfolio.table'), chart: T('portfolio.chart'), category: T('portfolio.project'), aria: T('portfolio.chart_budget') },
            });
        });
        // Costs and income by account (#282): the projects in view, one table per currency.
        const accs = Object.entries(chartData.accounts || {}).map(([id, b]) => Object.assign({ id: Number(id) }, b)).filter(b => inView.has(b.id));
        const apanel = box.querySelector('.prj-pc-accounts');
        if (accs.length && window.PrjAccounts) {
            apanel.hidden = false;
            const ab = apanel.querySelector('.prj-pc-acc');
            const acurs = [...new Set(accs.map(b => b.currency))].sort();
            /** An amount in a currency, for the viewer's locale. */
            const amoney = (v, c) => { if (v === null || v === undefined) return '-'; if (window.Money) return Money.format(v, c); try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: c }).format(v); } catch (e) { return c + ' ' + Number(v).toFixed(2); } };
            const ahours = m => (Math.round((m || 0) / 6) / 10).toLocaleString() + ' h';
            ab.innerHTML = acurs.map(c => (acurs.length > 1 ? '<h4 class="prj-pc-cur">' + esc(c) + '</h4>' : '')
                + window.PrjAccounts.table(window.PrjAccounts.combine(accs.filter(b => b.currency === c)), { esc, T: (k, p) => T('budget.' + k, p), money: amoney, hours: ahours })).join('');
            const ids = ordered.map(p => p.id).join(',');
            const ex = f => window.PRJ_BASE + 'api/projects/export.php?what=accounts_portfolio&ids=' + ids + '&format=' + f;
            apanel.querySelector('.prj-acc-export').innerHTML = '<a class="btn btn-secondary sm" href="' + esc(ex('xlsx')) + '">' + esc(T('budget.ba_export_xlsx')) + '</a> <a class="btn btn-secondary sm" href="' + esc(ex('csv')) + '">CSV</a>';
        }
        // Risk heat map of every open risk in view; a cell lists its risks.
        const risks = (chartData.risks || []).filter(r => inView.has(r.project_id));
        /** The heat map colour class for a probability x impact score - high from 15, medium from 8. */
        const score = s => s >= 15 ? 'sc-high' : (s >= 8 ? 'sc-mid' : 'sc-low');
        let h = '<div class="prj-heat-wrap"><div class="prj-heat-y">' + esc(T('raid.probability')) + '</div><div class="prj-heat">';
        for (let p = 5; p >= 1; p--) {
            h += '<div class="prj-heat-label y">' + esc((L.probability_labels || [])[p - 1] || p) + '</div>';
            for (let i = 1; i <= 5; i++) {
                const n = risks.filter(r => r.probability === p && r.impact === i).length;
                h += '<button type="button" class="prj-heat-cell ' + score(p * i) + (n ? ' has' : '') + (heatCell === p + ':' + i ? ' sel' : '') + '" data-pheat="' + p + ':' + i + '" title="' + esc(T('raid.score', { score: p * i })) + '">' + (n ? '<b>' + n + '</b>' : '') + '</button>';
            }
        }
        h += '<div></div>' + [1, 2, 3, 4, 5].map(i => '<div class="prj-heat-label x">' + esc((L.impact_labels || [])[i - 1] || i) + '</div>').join('') + '</div></div><div class="prj-heat-x">' + esc(T('raid.impact')) + '</div>';
        if (heatCell) {
            const [cp, ci] = heatCell.split(':').map(Number);
            const sel = risks.filter(r => r.probability === cp && r.impact === ci);
            h += '<ul class="prj-pc-risks">' + (sel.length ? sel.map(r => '<li><a href="' + esc(window.PRJ_BASE + 'projects/view.php?id=' + r.project_id + '#raid') + '">' + esc(name(r.project_id)) + '</a> ' + esc(r.title) + '</li>').join('') : '<li class="prj-muted">' + esc(T('portfolio.no_risks_cell')) + '</li>') + '</ul>';
        }
        box.querySelector('.prj-pc-heat').innerHTML = risks.length ? h : '<p class="prj-muted">' + esc(T('portfolio.no_risks')) + '</p>';
        // Every milestone, one row per project.
        const ms = (chartData.milestones || []).filter(m => inView.has(m.project_id));
        const mrows = ordered.filter(p => ms.some(m => m.project_id === p.id)).map(p => ({ label: short(p.name), items: ms.filter(m => m.project_id === p.id).map(m => ({ d: m.due_date, name: m.name, state: m.state })) }));
        const mbox = box.querySelector('.prj-pc-ms');
        if (mrows.length) C.milestones(mbox, {
            rows: mrows, today: P.todayStr(), fmtDate: P.fmtDate,
            labels: { table: T('portfolio.table'), chart: T('portfolio.chart'), project: T('portfolio.project'), milestone: T('portfolio.milestone'), date: T('portfolio.date'), state: T('portfolio.state'),
                states: { done: T('portfolio.ms_done'), missed: T('portfolio.ms_missed'), due: T('portfolio.ms_due') }, today: T('portfolio.today'), aria: T('portfolio.chart_milestones') },
        }); else mbox.innerHTML = '<p class="prj-muted">' + esc(T('portfolio.no_milestones')) + '</p>';
    }

    /** Loads every project the analyst may see, shows New project only to those who may create, applies the colour palette, priority labels and default sort, and draws the page. */
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
            P.setPriorityLabels(L.priority_labels);
            if (!['target', 'priority', 'health', 'name'].includes(sort)) sort = L.portfolio_sort || 'target';
            render();
        } catch (e) {
            P.toast(e.message, 'error');
        }
    }

    /** Opens the new project form and goes to the new project's page once it is saved. */
    function newProject() {
        P.openProjectForm(null, id => { window.location.href = window.PRJ_BASE + 'projects/view.php?id=' + id + '&new=1'; });
    }

    /** The projects in the view, in the view's order, as a spreadsheet (3.3.0). */
    function exportView(format) {
        const ids = sorted(all.filter(p => inView(p, view) && matches(p))).map(p => p.id);
        window.location.href = window.PRJ_BASE + 'api/projects/export.php?what=portfolio&format=' + format + '&ids=' + ids.join(',');
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('prjExport').addEventListener('click', e => {
            const b = e.target.closest('[data-export]'); if (!b) return;
            e.currentTarget.open = false;
            exportView(b.dataset.export);
        });
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
        document.getElementById('prjSort').addEventListener('change', e => {
            sort = e.target.value;
            try { localStorage.setItem('freeitsm.projects.sort', sort); } catch (err) { /* private mode */ }
            render();
        });
        document.querySelector('.prj-layout-seg').addEventListener('click', e => {
            const b = e.target.closest('[data-layout]'); if (!b) return;
            layout = b.dataset.layout;
            try { localStorage.setItem('freeitsm.projects.layout', layout); } catch (err) { /* private mode */ }
            render();
        });
        let rt = null;
        window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => { if ((layout === 'roadmap' || layout === 'charts') && all.length) render(); }, 150); });
        // A heat map cell lists its risks (3.3.0); clicking it again clears.
        document.getElementById('prjCharts').addEventListener('click', e => {
            const c = e.target.closest('[data-pheat]'); if (!c) return;
            heatCell = heatCell === c.dataset.pheat ? null : c.dataset.pheat;
            render();
        });
        document.getElementById('prjNew').addEventListener('click', newProject);
        document.getElementById('prjEmptyNew').addEventListener('click', newProject);
        load();
    });
})();
