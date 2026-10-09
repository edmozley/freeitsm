/**
 * Projects - the Timeline tab (3.3.0): the plan against the calendar.
 *
 * Each phase / stage / sprint is a BAND across its dates, its tasks are BARS
 * from start to due date, and milestones are DIAMONDS - in their stage's band,
 * or on a "Project milestones" row of their own. A line marks today and a
 * dashed one the target finish.
 *
 * Moving things (mouse only): drag a bar or a band to move it, drag either end
 * to change one date, drag a diamond to move a milestone. Each drop is one
 * ordinary save through the server's rules - task_dates (TasksService, so the
 * Tasks board and its calendar follow), stage_save.php, milestone_save - and the
 * page redraws from the server's answer. Nothing here decides a rule.
 *
 * 🔑 TOUCH SCROLLS, IT NEVER DRAGS. A hand-rolled drag on a phone fights the
 * sideways scroll the chart needs (Mobile-Friendly-Techniques §22). Every bar,
 * band and diamond also opens on a tap - the task, the stage dialog, the
 * milestone dialog - so the dates can still be changed there; the drag is a
 * shortcut, never the only way.
 *
 * Dates are whole days, counted in UTC (dayNum), so a bar never shifts by one
 * when the browser's time zone is ahead of or behind the server's.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = P.T, esc = P.esc;

    const ROW_H = 32, BAND_H = 36, HEAD_H = 50;
    const ZOOMS = { days: { minW: 26, pad: 3 }, weeks: { minW: 11, pad: 7 }, months: { minW: 4, pad: 14 } };
    let ctx = null;
    let zoom = 'weeks';
    let dirty = true;
    let dayW = 26;
    let originDay = 0;   // dayNum of the first column
    let drag = null;
    try { zoom = localStorage.getItem('prjTimelineZoom') || 'weeks'; } catch (e) { /* private window */ }
    if (!ZOOMS[zoom]) zoom = 'weeks';

    // ---- Day arithmetic (UTC whole days) -----------------------------------------------
    const dayNum = s => Math.floor(Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)) / 86400000);
    const ymd = n => new Date(n * 86400000).toISOString().slice(0, 10);
    const locale = document.documentElement.lang || undefined;
    const monthFmt = new Intl.DateTimeFormat(locale, { month: 'short', year: 'numeric', timeZone: 'UTC' });
    const dayFmt = new Intl.DateTimeFormat(locale, { day: 'numeric', timeZone: 'UTC' });

    function labelWidth() {
        return window.matchMedia && window.matchMedia('(max-width: 768px)').matches ? 124 : 230;
    }
    function canChange() {
        return !!(ctx && ctx.data.permissions && ctx.data.permissions.can_change);
    }
    function timeboxKind() {
        const m = (ctx.L && ctx.L.methodologies || []).find(x => x.key === ctx.data.project.methodology);
        return m ? m.timebox : 'phase';
    }

    /** A task's [start, end] as day numbers, or null when it has no date at all. */
    function taskSpan(t) {
        if (!t.start_date && !t.due_date) return null;
        let s = dayNum(t.start_date || t.due_date), e = dayNum(t.due_date || t.start_date);
        if (s > e) [s, e] = [e, s];
        return [s, e];
    }
    function stageSpan(s) {
        return s.start_date && s.end_date ? [dayNum(s.start_date), dayNum(s.end_date)] : null;
    }

    // ---- Drawing ------------------------------------------------------------------------
    function render(c) {
        if (c) ctx = c;
        dirty = true;
        const box = document.getElementById('pvTimeline');
        if (!box || box.hidden || !ctx) return;   // drawn when the tab is shown - it needs a width
        draw(box);
    }

    function draw(box) {
        dirty = false;
        const d = ctx.data, p = d.project;
        const kind = timeboxKind();
        const today = dayNum(P.todayStr());
        const ms = d.milestones || [];
        const days = [today];
        if (p.start_date) days.push(dayNum(p.start_date));
        if (p.target_end_date) days.push(dayNum(p.target_end_date));
        d.stages.forEach(s => { if (s.start_date) days.push(dayNum(s.start_date)); if (s.end_date) days.push(dayNum(s.end_date)); });
        let undated = 0;
        d.tasks.forEach(t => { const sp = taskSpan(t); if (sp) days.push(sp[0], sp[1]); else undated++; });
        ms.forEach(m => days.push(dayNum(m.due_date)));
        const hasDates = days.length > 1;

        // Keep the reader's place across a redraw (a drop redraws everything).
        const old = box.querySelector('.prj-tl-scroll');
        const keep = old ? { x: old.scrollLeft, y: old.scrollTop } : null;

        const z = ZOOMS[zoom];
        const first = Math.min(...days) - z.pad, last = Math.max(...days) + z.pad;
        // Months start the view on the 1st, weeks on a Monday, so the ticks line up.
        let start = first;
        if (zoom === 'weeks') start -= (new Date(first * 86400000).getUTCDay() + 6) % 7;
        if (zoom === 'months') start -= new Date(first * 86400000).getUTCDate() - 1;
        originDay = start;
        const span = last - start + 1;
        const LW = labelWidth();
        const avail = Math.max(200, box.clientWidth - LW - 18);
        dayW = Math.max(z.minW, avail / span);
        const trackW = Math.ceil(span * dayW);
        const x = n => (n - originDay) * dayW;

        // Toolbar
        let html = '<div class="prj-tl-bar">'
            + '<p class="prj-muted">' + esc(T('timeline.intro', { timeboxes: P.timeboxWord(kind, true) })) + '</p>'
            + '<div class="prj-tl-tools">'
            +   '<div class="prj-seg prj-tl-zoom" role="tablist" aria-label="' + esc(T('timeline.zoom')) + '">'
            +     ['days', 'weeks', 'months'].map(k => '<button type="button" data-tl-zoom="' + k + '"' + (k === zoom ? ' class="active"' : '') + '>' + esc(T('timeline.zoom_' + k)) + '</button>').join('')
            +   '</div>'
            +   '<button type="button" class="btn btn-secondary sm" data-tl-today>' + esc(T('timeline.today')) + '</button>'
            +   (canChange() ? '<button type="button" class="btn btn-primary prj-btn sm" data-ms-new>+ ' + esc(T('milestones.add')) + '</button>' : '')
            + '</div></div>';

        if (!hasDates && !d.stages.length && !d.tasks.length && !ms.length) {
            box.innerHTML = html + '<div class="prj-plan-empty">' + esc(T('timeline.empty', { timeboxes: P.timeboxWord(kind, true) })) + '</div>';
            return;
        }

        // Header: months on top; under them days (Days), Mondays (Weeks) or nothing (Months).
        let months = '', ticks = '';
        for (let n = start; n <= last;) {
            const dt = new Date(n * 86400000);
            const next = Math.min(last + 1, Math.floor(Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth() + 1, 1) / 86400000));
            months += '<div class="prj-tl-month" style="left:' + x(n) + 'px;width:' + (next - n) * dayW + 'px"><span>' + esc(monthFmt.format(dt)) + '</span></div>';
            n = next;
        }
        if (zoom !== 'months') {
            for (let n = start; n <= last; n += zoom === 'days' ? 1 : 7) {
                const wd = new Date(n * 86400000).getUTCDay();
                const cls = 'prj-tl-tick' + (zoom === 'days' && (wd === 0 || wd === 6) ? ' we' : '') + (n === today ? ' today' : '');
                ticks += '<div class="' + cls + '" style="left:' + x(n) + 'px;width:' + (zoom === 'days' ? dayW : 7 * dayW) + 'px">' + esc(dayFmt.format(new Date(n * 86400000))) + '</div>';
            }
        }
        // Weekend shading and week lines run down the body behind everything.
        let grid = '';
        if (zoom === 'days') {
            for (let n = start; n <= last; n++) { const wd = new Date(n * 86400000).getUTCDay(); if (wd === 0 || wd === 6) grid += '<div class="prj-tl-we" style="left:' + x(n) + 'px;width:' + dayW + 'px"></div>'; }
        } else if (zoom === 'weeks') {
            for (let n = start; n <= last; n += 7) grid += '<div class="prj-tl-gl" style="left:' + x(n) + 'px"></div>';
        } else {
            for (let n = start; n <= last;) { grid += '<div class="prj-tl-gl" style="left:' + x(n) + 'px"></div>'; const dt = new Date(n * 86400000); n = Math.floor(Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth() + 1, 1) / 86400000); }
        }

        // Rows
        const rows = [];
        const diamonds = list => list.map(m => '<span class="prj-tl-ms ' + 'ms-' + m.state + (canChange() ? ' can' : '') + '" data-tl-ms="' + m.id + '" style="left:' + (x(dayNum(m.due_date)) + dayW / 2) + 'px"'
            + ' title="' + esc(m.name + ' - ' + P.fmtDate(m.due_date) + ' - ' + window.PrjMilestones.phrase(m)) + '"><span class="prj-tl-ms-dia"></span><span class="prj-tl-ms-name">' + esc(m.name) + '</span></span>').join('');
        const projectMs = ms.filter(m => !m.stage_id);
        if (projectMs.length) {
            rows.push('<div class="prj-tl-row band ms-row" style="height:' + BAND_H + 'px"><div class="prj-tl-label" style="width:' + LW + 'px">'
                + window.PrjMilestones.diamond({ state: 'due' }, 10) + '<span class="prj-tl-label-text">' + esc(T('timeline.project_row')) + '</span></div>'
                + '<div class="prj-tl-track" style="width:' + trackW + 'px">' + diamonds(projectMs) + '</div></div>');
        }
        const byStage = {};
        d.tasks.forEach(t => { const k = t.project_stage_id || ''; (byStage[k] = byStage[k] || []).push(t); });
        const taskRow = t => {
            const sp = taskSpan(t);
            const closed = !!Number(t.is_closed);
            const late = !closed && t.due_date && dayNum(t.due_date) < today;
            let bar = '';
            if (sp) {
                const dates = sp[0] === sp[1] ? P.fmtDate(ymd(sp[0])) : P.fmtDate(ymd(sp[0])) + ' - ' + P.fmtDate(ymd(sp[1]));
                bar = '<div class="prj-tl-bar-task' + (closed ? ' done' : '') + (late ? ' late' : '') + (canChange() ? ' can' : '') + '" data-tl-task="' + t.id + '"'
                    + ' data-s="' + sp[0] + '" data-e="' + sp[1] + '" style="left:' + x(sp[0]) + 'px;width:' + Math.max(dayW, (sp[1] - sp[0] + 1) * dayW) + 'px;--bc:' + esc(t.status_colour || '#94a3b8') + '"'
                    + ' title="' + esc(T('timeline.task_tip', { title: t.title, dates: dates }) + (t.assignee_name ? ' - ' + t.assignee_name : '')) + '">'
                    + (canChange() ? '<span class="prj-tl-h l" data-h="l"></span>' : '') + '<span class="prj-tl-bar-text">' + esc(t.title) + '</span>'
                    + (canChange() ? '<span class="prj-tl-h r" data-h="r"></span>' : '') + '</div>';
            }
            return '<div class="prj-tl-row" style="height:' + ROW_H + 'px"><a class="prj-tl-label task" style="width:' + LW + 'px" href="' + esc(window.PRJ_BASE + 'tasks/?task=' + t.id) + '" title="' + esc(t.title) + '">'
                + '<span class="prj-task-status" style="background:' + esc(t.status_colour || '#94a3b8') + '"></span><span class="prj-tl-label-text' + (closed ? ' done' : '') + '">' + esc(t.title) + '</span></a>'
                + '<div class="prj-tl-track" style="width:' + trackW + 'px">' + bar + '</div></div>';
        };
        d.stages.forEach(s => {
            const sp = stageSpan(s);
            const stMs = ms.filter(m => String(m.stage_id) === String(s.id));
            const band = sp ? '<div class="prj-tl-band st-' + esc(s.status) + (canChange() ? ' can' : '') + '" data-tl-stage="' + s.id + '" data-s="' + sp[0] + '" data-e="' + sp[1] + '"'
                + ' style="left:' + x(sp[0]) + 'px;width:' + (sp[1] - sp[0] + 1) * dayW + 'px;background:' + P.gradient(p.colour) + '" title="' + esc(s.name + ' - ' + P.fmtDate(s.start_date) + ' - ' + P.fmtDate(s.end_date)) + '">'
                + (canChange() ? '<span class="prj-tl-h l" data-h="l"></span>' : '') + '<span class="prj-tl-bar-text">' + esc(s.name) + '</span>'
                + (canChange() ? '<span class="prj-tl-h r" data-h="r"></span>' : '') + '</div>' : '';
            rows.push('<div class="prj-tl-row band" style="height:' + BAND_H + 'px"><button type="button" class="prj-tl-label stage" style="width:' + LW + 'px" data-stage-edit="' + s.id + '">'
                + '<span class="prj-lane-kind">' + esc(T('timebox.' + (s.kind || kind))) + '</span><span class="prj-tl-label-text">' + esc(s.name) + '</span></button>'
                + '<div class="prj-tl-track" style="width:' + trackW + 'px">' + band + diamonds(stMs) + '</div></div>');
            (byStage[s.id] || []).forEach(t => rows.push(taskRow(t)));
        });
        if ((byStage[''] || []).length) {
            rows.push('<div class="prj-tl-row band" style="height:' + BAND_H + 'px"><div class="prj-tl-label stage none" style="width:' + LW + 'px"><span class="prj-tl-label-text">'
                + esc(T('timeline.unassigned', { timebox: P.timeboxWord(kind) })) + '</span></div><div class="prj-tl-track" style="width:' + trackW + 'px"></div></div>');
            byStage[''].forEach(t => rows.push(taskRow(t)));
        }

        // Lines: today, and the target finish.
        let lines = '<div class="prj-tl-line today" style="left:' + (LW + x(today) + dayW / 2) + 'px"><span>' + esc(T('timeline.today')) + '</span></div>';
        if (p.target_end_date) lines += '<div class="prj-tl-line target" style="left:' + (LW + x(dayNum(p.target_end_date)) + dayW) + 'px"><span>' + esc(T('timeline.target')) + '</span></div>';

        html += '<div class="prj-tl-scroll"><div class="prj-tl-inner z-' + zoom + '" style="width:' + (LW + trackW) + 'px;--lw:' + LW + 'px">'
            + '<div class="prj-tl-head" style="height:' + HEAD_H + 'px"><div class="prj-tl-label head" style="width:' + LW + 'px">' + esc(T('timeline.col_name')) + '</div>'
            + '<div class="prj-tl-head-track" style="width:' + trackW + 'px"><div class="prj-tl-months">' + months + '</div>' + (ticks ? '<div class="prj-tl-ticks">' + ticks + '</div>' : '') + '</div></div>'
            + '<div class="prj-tl-body"><div class="prj-tl-grid" style="left:' + LW + 'px;width:' + trackW + 'px">' + grid + '</div>' + rows.join('') + '</div>'
            + lines + '</div></div>';
        if (undated) html += '<p class="prj-hint">' + esc(T('timeline.undated', { count: undated })) + '</p>';
        if (canChange()) html += '<p class="prj-hint prj-tl-drag-hint">' + esc(T('timeline.drag_hint')) + '</p>';
        box.innerHTML = html;

        const sc = box.querySelector('.prj-tl-scroll');
        if (keep) { sc.scrollLeft = keep.x; sc.scrollTop = keep.y; }
        else scrollToToday(sc);
    }

    function scrollToToday(sc) {
        sc = sc || document.querySelector('#pvTimeline .prj-tl-scroll');
        if (!sc) return;
        const left = (dayNum(P.todayStr()) - originDay) * dayW;
        sc.scrollLeft = Math.max(0, left - (sc.clientWidth - labelWidth()) / 3);
    }

    // ---- Dragging (mouse only) --------------------------------------------------------------
    function onDown(e) {
        if (e.pointerType !== 'mouse' || e.button !== 0 || !canChange()) return;
        const el = e.target.closest('[data-tl-task], [data-tl-stage], [data-tl-ms]');
        if (!el || !el.closest('#pvTimeline')) return;
        const h = e.target.closest('[data-h]');
        drag = {
            el: el, startX: e.clientX, moved: false, delta: 0,
            mode: el.dataset.tlMs ? 'ms' : (h ? 'resize-' + h.dataset.h : 'move'),
            left: parseFloat(el.style.left), width: el.offsetWidth,
        };
        el.setPointerCapture(e.pointerId);
        el.classList.add('dragging');
        e.preventDefault();
    }

    function onMove(e) {
        if (!drag) return;
        const dx = e.clientX - drag.startX;
        if (Math.abs(dx) > 3) drag.moved = true;
        if (!drag.moved) return;
        drag.delta = Math.round(dx / dayW);
        const snap = drag.delta * dayW;
        const st = drag.el.style;
        if (drag.mode === 'move' || drag.mode === 'ms') st.left = (drag.left + snap) + 'px';
        else if (drag.mode === 'resize-l') { const s = Math.min(snap, drag.width - dayW); st.left = (drag.left + s) + 'px'; st.width = (drag.width - s) + 'px'; }
        else if (drag.mode === 'resize-r') st.width = Math.max(dayW, drag.width + snap) + 'px';
    }

    async function onUp() {
        if (!drag) return;
        const g = drag; drag = null;
        g.el.classList.remove('dragging');
        if (!g.moved || g.delta === 0) {
            if (g.moved) render();                // wiggled and put back: just tidy
            else click(g.el);
            return;
        }
        try {
            if (g.mode === 'ms') {
                const m = (ctx.data.milestones || []).find(x => String(x.id) === g.el.dataset.tlMs);
                await window.PrjMilestones.save({ id: m.id, due_date: ymd(dayNum(m.due_date) + g.delta) });
            } else {
                const s0 = +g.el.dataset.s, e0 = +g.el.dataset.e;
                let s = s0, en = e0;
                if (g.mode === 'move') { s += g.delta; en += g.delta; }
                else if (g.mode === 'resize-l') s = Math.min(e0, s0 + g.delta);
                else en = Math.max(s0, e0 + g.delta);
                if (g.el.dataset.tlTask) {
                    const t = ctx.data.tasks.find(x => String(x.id) === g.el.dataset.tlTask);
                    const body = { action: 'task_dates', project_id: ctx.projectId, task_id: t.id };
                    // A deadline-only task keeps being deadline-only when moved; stretching
                    // it gives it a start, as on the Tasks timeline.
                    if (g.mode === 'move' && !t.start_date) body.due_date = ymd(en);
                    else { body.start_date = ymd(s); body.due_date = ymd(en); }
                    await P.api('tools.php', body);
                } else {
                    const st = ctx.data.stages.find(x => String(x.id) === g.el.dataset.tlStage);
                    await P.api('stage_save.php', { project_id: ctx.projectId, id: st.id, name: st.name, start_date: ymd(s), end_date: ymd(en) });
                }
                await ctx.refresh();
            }
            P.toast(T('timeline.moved'));
        } catch (err) {
            P.toast(err.message, 'error');
            render();
        }
    }

    /** A tap or a click without a drag: open the thing. */
    function click(el) {
        if (el.dataset.tlTask) { window.location.href = window.PRJ_BASE + 'tasks/?task=' + el.dataset.tlTask; return; }
        if (el.dataset.tlMs) { window.PrjMilestones.open((ctx.data.milestones || []).find(m => String(m.id) === el.dataset.tlMs)); return; }
        if (el.dataset.tlStage) {
            const b = document.querySelector('#pvPlan [data-stage-edit="' + el.dataset.tlStage + '"]');
            if (b) b.click();
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const box = document.getElementById('pvTimeline');
        if (!box) return;
        box.addEventListener('pointerdown', onDown);
        box.addEventListener('pointermove', onMove);
        // The end of a press: a drag finishes (or, unmoved, opens what it was on);
        // otherwise - a tap on a phone, or a click by somebody who may not change
        // the plan - opens what was pressed. A finger that scrolls gets a
        // pointercancel instead, so scrolling never opens anything.
        box.addEventListener('pointerup', e => {
            if (drag) { onUp(); return; }
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            const el = e.target.closest('[data-tl-task], [data-tl-stage], [data-tl-ms]');
            if (el) click(el);
        });
        box.addEventListener('pointercancel', () => { if (drag) { drag.el.classList.remove('dragging'); drag = null; render(); } });
        box.addEventListener('click', e => {
            const z = e.target.closest('[data-tl-zoom]');
            if (z) {
                zoom = z.dataset.tlZoom;
                try { localStorage.setItem('prjTimelineZoom', zoom); } catch (err) { /* private window */ }
                const sc = box.querySelector('.prj-tl-scroll');
                if (sc) sc.remove();   // a new zoom starts at today, not at the old scroll
                render();
                return;
            }
            if (e.target.closest('[data-tl-today]')) scrollToToday();
        });
        let rt = null;
        window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => { if (ctx && !box.hidden) render(); }, 150); });
    });

    window.PrjTimeline = {
        render: render,
        /** The tab was shown: draw if anything changed while it was hidden. */
        shown: function () { if (dirty) render(); },
    };
})();
