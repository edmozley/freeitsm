/**
 * Projects - one project's page (3.2.0).
 *
 * Banner + Overview / Plan / Timeline / History. Everything is drawn from one call to
 * api/projects/get.php and redrawn after each change, so the ring, the counts
 * and the plan can never disagree with each other.
 *
 * The Plan: each phase / stage / sprint is a lane of task rows; a task can be
 * dragged onto another lane (or onto "not in a phase yet"), and a task can be
 * added in place. Task rows open the task on the Tasks board - the task window
 * there is the one place a task is edited, so this page never grows a second one.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = P.T, esc = P.esc;

    const page = document.getElementById('prjPage');
    const projectId = parseInt(page.dataset.projectId, 10) || 0;
    let data = null;      // {project, stages, tasks, history}
    let L = null;         // lookups
    let tab = 'overview';
    let openAdd = null;
    let links = null;     // {links: {kind: [...]}, ready} from api/projects/links.php   // the lane (stage id, '' = not in one) whose add-a-task form is open

    function timeboxKind() {
        const m = (L && L.methodologies || []).find(x => x.key === data.project.methodology);
        return m ? m.timebox : 'phase';
    }
    function methodLabel() {
        const m = (L && L.methodologies || []).find(x => x.key === data.project.methodology);
        return m ? m.label : data.project.methodology;
    }

    // ---- Banner -------------------------------------------------------------------
    function renderBanner() {
        const p = data.project;
        const b = document.getElementById('prjBanner');
        b.style.background = P.gradient(p.colour);
        b.hidden = false;
        document.getElementById('pvIcon').innerHTML = P.icon(p.icon, 34);
        document.getElementById('pvCode').textContent = p.code;
        document.getElementById('pvStatus').outerHTML = P.statusPill(p.status).replace('<span ', '<span id="pvStatus" ');
        document.getElementById('pvMethod').textContent = T('view.method_chip', { method: methodLabel() });
        const comp = document.getElementById('pvCompany');
        comp.hidden = !(L.multi_company && p.company_name);
        comp.textContent = p.company_name || '';
        document.getElementById('pvName').textContent = p.name;
        document.title = document.title.split(' - ')[0] + ' - ' + p.name;
        const goal = document.getElementById('pvGoal');
        goal.textContent = p.goal || '';
        goal.hidden = !p.goal;

        const tp = P.targetPhrase(p);
        const facts = [];
        facts.push('<span class="prj-fact"><span class="prj-avatar light">' + esc(p.owner_name ? P.initials(p.owner_name) : '?') + '</span>' + esc(p.owner_name ? T('portfolio.led_by', { name: p.owner_name }) : T('portfolio.nobody')) + '</span>');
        if (p.start_date && p.target_end_date) facts.push('<span class="prj-fact">' + esc(T('view.dates', { start: P.fmtDate(p.start_date), end: P.fmtDate(p.target_end_date) })) + '</span>');
        else if (p.start_date) facts.push('<span class="prj-fact">' + esc(T('view.starts', { date: P.fmtDate(p.start_date) })) + '</span>');
        else if (p.target_end_date) facts.push('<span class="prj-fact">' + esc(T('view.ends', { date: P.fmtDate(p.target_end_date) })) + '</span>');
        facts.push('<span class="prj-fact strong">' + esc(tp.text) + '</span>');
        document.getElementById('pvFacts').innerHTML = facts.join('');

        const health = p.shown_health;
        document.getElementById('pvRing').innerHTML = P.ring(p.progress, health, 92, true)
            + '<span class="prj-ring-caption">' + esc(health ? T('health.' + health) : T('health.none'))
            + (health ? '<small>' + esc(p.health === 'auto' ? T('health.auto_note') : T('health.manual_note')) + '</small>' : '') + '</span>';
        document.getElementById('prjTabs').hidden = false;
    }

    // ---- Overview -----------------------------------------------------------------
    function renderOverview() {
        const p = data.project;
        const open = p.task_total - p.task_done;
        const d = P.daysTo(p.target_end_date);
        const finished = p.status === 'closed' || p.status === 'cancelled';
        // Effort (3.3.0): estimated against logged, under the progress figure.
        const ef = p.effort || {};
        const effort = ef.estimated_tasks > 0 || ef.logged_hours > 0
            ? T('effort.tile', { est: hrsTxt(ef.estimate_hours || 0), logged: hrsTxt(ef.logged_hours || 0) }) : '';
        const tiles = [
            { n: p.progress + '%', l: T('view.progress'), cls: '', sub: effort },
            { n: open, l: T('view.open_tasks'), cls: '' },
            { n: p.task_overdue, l: T('view.overdue'), cls: p.task_overdue > 0 ? 'bad' : '' },
            (d === null || finished) ? { n: '-', l: T('view.days_left'), cls: '' }
                : d < 0 ? { n: -d, l: T('view.days_late'), cls: 'bad' } : { n: d, l: T('view.days_left'), cls: d <= 14 ? 'warn' : '' },
        ];
        const kind = timeboxKind();
        const active = data.stages.filter(s => s.status === 'active');
        const today = P.todayStr();
        const upcoming = data.tasks.filter(t => !Number(t.is_closed) && t.due_date).sort((a, b) => a.due_date.localeCompare(b.due_date)).slice(0, 6);

        let html = (window.PrjTools && p.exceptions && p.exceptions.length ? window.PrjTools.exceptionsBanner(p.exceptions) : '') + '<div class="prj-ov-tiles">' + tiles.map(t => '<div class="prj-tile ' + t.cls + '"><span class="prj-tile-num">' + esc(t.n) + '</span><span class="prj-tile-label">' + esc(t.l) + '</span>' + (t.sub ? '<span class="prj-tile-sub">' + esc(t.sub) + '</span>' : '') + '</div>').join('') + '</div>';
        // A jump in linked tickets (3.2.0) - why the ring may be amber when the tasks look fine.
        if (p.ticket_spike) html += '<div class="prj-gate-warn"><div class="prj-gate-warn-head">' + P.icon('flag', 16) + '<strong>' + esc(T('gates.ticket_spike', { count: p.tickets_7d })) + '</strong></div></div>';
        // A missed milestone (3.3.0) - the other reason the ring may be amber.
        if (p.milestones_missed > 0 && !finished) html += '<div class="prj-gate-warn"><div class="prj-gate-warn-head">' + P.icon('flag', 16) + '<strong>' + esc(T('view.milestones_missed', { count: p.milestones_missed })) + '</strong></div></div>';
        // RAID (3.3.0): a dependency or decision late (amber), and anything escalated - named, with what is needed.
        if (p.raid_overdue > 0 && !finished) html += '<div class="prj-gate-warn"><div class="prj-gate-warn-head">' + P.icon('flag', 16) + '<strong>' + esc(T('view.raid_late', { count: p.raid_overdue })) + '</strong>'
            + ' <button type="button" class="prj-link" data-goto="raid">' + esc(T('view.see_all')) + '</button></div></div>';
        const escalated = (data.raid || []).filter(r => r.status === 'open' && r.escalated_datetime);
        if (escalated.length && !finished) {
            html += '<div class="prj-gate-warn prj-esc-warn"><div class="prj-gate-warn-head">' + P.icon('flag', 16) + '<strong>' + esc(T('view.escalated', { count: escalated.length })) + '</strong>'
                + ' <button type="button" class="prj-link" data-goto="raid">' + esc(T('view.see_all')) + '</button></div><ul>'
                + escalated.map(r => '<li><strong>' + esc(T('raid.' + r.type)) + ': ' + esc(r.title) + '</strong> - ' + esc(r.escalation_note || '')
                    + ' <span class="prj-muted">(' + esc(T('raid.escalated_by', { name: r.escalated_by_name || T('history.someone'), date: P.fmtDate(String(r.escalated_datetime).slice(0, 10)) })) + ')</span></li>').join('')
                + '</ul></div>';
        }
        // The AI project manager's briefing (3.2.0) - drawn by projects-reports.js after this.
        html += '<div id="pvBriefing" hidden></div>';
        // Milestones (3.3.0) - drawn by projects-milestones.js after this.
        html += '<div id="pvMilestones" hidden></div>';
        // Asset targets (3.2.0) - drawn by projects-targets.js after this.
        html += '<div id="pvTargets" hidden></div>';
        html += '<div class="prj-ov-grid">';

        html += '<div class="prj-panel prj-ov-about"><h3>' + esc(T('view.about')) + '</h3>'
            + (p.goal ? '<p class="prj-ov-goal">' + esc(p.goal) + '</p>' : '<p class="prj-muted">' + esc(T('view.no_goal')) + '</p>')
            + (p.summary ? '<p class="prj-ov-summary">' + esc(p.summary).replace(/\n/g, '<br>') + '</p>' : '')
            + (p.health !== 'auto' && p.health_note ? '<p class="prj-ov-note">' + P.healthBadge(p.health) + ' ' + esc(p.health_note) + '</p>' : '')
            + '</div>';

        html += '<div class="prj-panel"><h3>' + esc(T('view.now')) + '</h3>';
        if (active.length) {
            html += active.map(s => stageMini(s)).join('');
        } else {
            html += '<p class="prj-muted">' + esc(T('view.nothing_now')) + '</p>';
        }
        html += '</div>';

        html += '<div class="prj-panel"><h3>' + esc(T('view.next_up')) + '</h3>';
        if (upcoming.length) {
            html += '<ul class="prj-next">' + upcoming.map(t => {
                const late = t.due_date < today;
                return '<li><a href="' + esc(window.PRJ_BASE + 'tasks/?task=' + t.id) + '"><span class="prj-next-title">' + esc(t.title) + '</span>'
                    + '<span class="prj-next-meta">' + (t.assignee_name ? '<span class="prj-avatar sm" title="' + esc(t.assignee_name) + '">' + esc(P.initials(t.assignee_name)) + '</span>' : '')
                    + '<span class="prj-due' + (late ? ' late' : '') + '">' + esc(P.fmtDate(t.due_date)) + '</span></span></a></li>';
            }).join('') + '</ul>';
        } else {
            html += '<p class="prj-muted">' + esc(T('view.nothing_next')) + '</p>';
        }
        html += '</div>';

        html += '<div class="prj-panel"><h3>' + esc(T('view.recent')) + ' <button type="button" class="prj-link" data-goto="history">' + esc(T('view.see_all')) + '</button></h3>'
            + (data.history.length ? '<ul class="prj-feed">' + data.history.slice(0, 6).map(historyItem).join('') + '</ul>' : '<p class="prj-muted">' + esc(T('view.no_history')) + '</p>')
            + '</div>';

        html += '</div>';
        html += linksSummary();
        document.getElementById('pvOverview').innerHTML = html;
        void kind;
    }

    function stageMini(s) {
        const total = Number(s.task_total), done = Number(s.task_done);
        const pct = total ? Math.round(done * 100 / total) : 0;
        return '<div class="prj-stage-mini"><div class="prj-stage-mini-head"><strong>' + esc(s.name) + '</strong>'
            + '<span>' + esc(T('plan.stage_done', { done: done, total: total })) + '</span></div>'
            + (s.goal ? '<p class="prj-muted">' + esc(s.goal) + '</p>' : '')
            + '<span class="prj-bar"><span style="width:' + pct + '%;background:' + P.gradient(data.project.colour) + '"></span></span></div>';
    }

    // ---- History ------------------------------------------------------------------
    function historyItem(h) {
        const who = h.analyst_name || T('history.someone');
        const f = h.field_name;
        let what = T('history.' + f);
        if (what === 'projects.history.' + f) what = f;
        let detail = '';
        if (f === 'project_created') detail = '';
        else if (f === 'stage_added') detail = h.new_value || '';
        else if (f === 'stage_removed') detail = h.old_value || '';
        else if (f === 'gate') detail = (h.old_value || '') + ': ' + T('gates.' + (h.new_value || ''));
        else if (f === 'tolerances') detail = '';
        else if (f === 'target_saved' || f === 'target_removed') detail = h.new_value || h.old_value || '';
        else if (f.indexOf('raid_') === 0) detail = (h.new_value || h.old_value || '').replace(/^(risk|assumption|issue|dependency|decision|lesson): /, (m, k) => T('raid.' + k) + ': ');
        else if (f === 'link_added' || f === 'link_removed') detail = linkHistoryText(h.new_value || h.old_value || '');
        else if (f === 'milestone_moved') detail = (h.new_value || '').replace(/: (\d{4}-\d{2}-\d{2})$/, (m, d) => ': ' + T('history.from_to', { from: P.fmtDate((h.old_value || '').slice(-10)), to: P.fmtDate(d) }));
        else if (f === 'stage_status') detail = (h.new_value || '').replace(/: (planned|active|closed)$/, (m, s) => ': ' + T('stage_status.' + s));
        else if (f === 'status') detail = T('history.from_to', { from: T('status.' + h.old_value), to: T('status.' + h.new_value) });
        else if (f === 'health') detail = T('history.from_to', { from: T('health.' + h.old_value), to: T('health.' + h.new_value) });
        else if (f === 'methodology') detail = T('history.from_to', { from: T('method.' + h.old_value), to: T('method.' + h.new_value) });
        else if (['start_date', 'target_end_date', 'actual_end_date'].includes(f)) detail = T('history.from_to', { from: h.old_value ? P.fmtDate(h.old_value) : '-', to: h.new_value ? P.fmtDate(h.new_value) : '-' });
        else if (['colour', 'icon', 'tailoring', 'business_case'].includes(f)) detail = '';
        else if (f === 'item_moscow') detail = (h.new_value || '').replace(/: (must|should|could|wont|-)$/, (m, k) => ': ' + (k === '-' ? T('scope.unsorted') : T('scope.' + k)));
        else if (h.new_value) detail = h.old_value ? T('history.from_to', { from: h.old_value, to: h.new_value }) : h.new_value;
        const when = window.fmtDateTime ? window.fmtDateTime(h.created_datetime) : h.created_datetime;
        return '<li><span class="prj-avatar sm">' + esc(P.initials(who)) + '</span><div><span class="prj-feed-line"><strong>' + esc(who) + '</strong> ' + esc(what)
            + (detail ? ' <span class="prj-feed-detail">' + esc(detail) + '</span>' : '') + '</span><span class="prj-feed-when">' + esc(when) + '</span></div></li>';
    }

    function renderHistory() {
        document.getElementById('pvHistory').innerHTML = '<div class="prj-panel">'
            + (data.history.length ? '<ul class="prj-feed big">' + data.history.map(historyItem).join('') + '</ul>' : '<p class="prj-muted">' + esc(T('view.no_history')) + '</p>')
            + '</div>';
    }

    // ---- Plan -----------------------------------------------------------------------
    /** "4h" / "1.5h" in the page's language. */
    function hrsTxt(h) {
        return (Math.round(Number(h) * 10) / 10).toLocaleString(document.documentElement.lang || undefined) + 'h';
    }
    /**
     * A task's estimate on the Plan (3.3.0): a small box to type it in, for those
     * who may change the project; text for everyone else. Time logged shows
     * beside it, red once it is over the estimate.
     */
    function estimateCell(t) {
        const est = t.estimate_hours !== null && t.estimate_hours !== undefined ? Number(t.estimate_hours) : null;
        const logged = Number(t.logged_minutes || 0) / 60;
        const over = est !== null && logged > est;
        const log = logged > 0 ? '<span class="prj-est-logged' + (over ? ' over' : '') + '" title="' + esc(T('effort.logged_tip', { hours: hrsTxt(logged) })) + '">' + esc(hrsTxt(logged)) + '</span>' : '';
        const canEdit = !data.permissions || data.permissions.can_change;
        if (!canEdit) return '<span class="prj-est">' + log + (est !== null ? '<span class="prj-est-val">' + esc(hrsTxt(est)) + '</span>' : '') + '</span>';
        return '<span class="prj-est">' + log + '<input type="number" class="prj-est-input" min="0" max="9999" step="0.25" inputmode="decimal" data-estimate="' + t.id + '"'
            + ' value="' + (est !== null ? est : '') + '" placeholder="' + esc(T('effort.ph')) + '" title="' + esc(T('effort.input_tip')) + '" aria-label="' + esc(T('effort.input_tip')) + '"></span>';
    }

    function taskRow(t) {
        const closed = !!Number(t.is_closed);
        const late = !closed && t.due_date && t.due_date < P.todayStr();
        return '<div class="prj-task' + (closed ? ' done' : '') + '" draggable="true" data-task="' + t.id + '">'
            + '<span class="prj-grip" aria-hidden="true">&#8942;&#8942;</span>'
            + '<span class="prj-task-status" style="background:' + esc(t.status_colour || '#94a3b8') + '" title="' + esc(t.status_name || '') + '"></span>'
            + '<a class="prj-task-title" href="' + esc(window.PRJ_BASE + 'tasks/?task=' + t.id) + '" title="' + esc(T('plan.task_open')) + '">' + esc(t.title) + '</a>'
            + (Number(t.subtask_count) ? '<span class="prj-task-sub">' + esc(t.subtask_count) + '</span>' : '')
            + (t.priority_name && !isDefaultPriority(t.priority_id) ? '<span class="prj-task-prio" style="--c:' + esc(t.priority_colour || '#94a3b8') + '">' + esc(t.priority_name) + '</span>' : '')
            + estimateCell(t)
            + (t.due_date ? '<span class="prj-due' + (late ? ' late' : '') + '">' + esc(P.fmtDate(t.due_date)) + '</span>' : '<span class="prj-due none"></span>')
            + '<span class="prj-avatar sm" title="' + esc(t.assignee_name || t.team_name || T('plan.unassigned_person')) + '">' + esc(t.assignee_name ? P.initials(t.assignee_name) : (t.team_name ? P.initials(t.team_name) : '-')) + '</span>'
            + '<button type="button" class="prj-task-remove" data-remove-task="' + t.id + '" title="' + esc(T('plan.remove_task')) + '" aria-label="' + esc(T('plan.remove_task')) + '">&times;</button>'
            + '</div>';
    }

    /** The default priority is what most tasks have, so its pill is noise: only the exceptions are shown. */
    function isDefaultPriority(id) {
        const p = (L.task_priorities || []).find(x => String(x.id) === String(id));
        return !!(p && Number(p.is_default));
    }

    /**
     * Each lane offers a light "+ Add a task" that opens the form. The lane whose
     * form is open is remembered across redraws, so adding several tasks in a row
     * keeps the box open and focused.
     */
    function addRow(stageId) {
        const key = String(stageId || '');
        if (openAdd !== key) {
            return '<button type="button" class="prj-add-toggle" data-add-open="' + esc(key) + '">+ ' + esc(T('plan.add_task')) + '</button>';
        }
        const opts = '<option value="">' + esc(T('plan.assignee')) + '</option>' + L.analysts.map(a => '<option value="' + a.id + '">' + esc(a.full_name) + '</option>').join('');
        return '<form class="prj-add-task" data-stage="' + (stageId || '') + '">'
            + '<input type="text" name="title" maxlength="255" placeholder="' + esc(T('plan.task_ph')) + '" autocomplete="off">'
            + '<select name="assignee" aria-label="' + esc(T('plan.assignee')) + '">' + opts + '</select>'
            + '<input type="date" name="due" aria-label="' + esc(T('plan.due')) + '">'
            + '<button type="submit" class="btn btn-primary prj-btn sm">' + esc(T('plan.add')) + '</button>'
            + '</form>';
    }

    function lane(stage, tasks) {
        const kind = timeboxKind();
        if (!stage) {
            return '<section class="prj-lane unassigned" data-lane="">'
                + '<header class="prj-lane-head"><div><h3>' + esc(T('plan.unassigned', { timebox: P.timeboxWord(kind) })) + '</h3>'
                + '<p class="prj-muted">' + esc(T('plan.unassigned_hint', { timebox: P.timeboxWord(kind) })) + '</p></div></header>'
                + '<div class="prj-lane-tasks" data-drop="">' + tasks.map(taskRow).join('') + '</div>'
                + addRow(null) + '</section>';
        }
        const total = Number(stage.task_total), done = Number(stage.task_done);
        const pct = total ? Math.round(done * 100 / total) : 0;
        // Effort in this lane (3.3.0), only once something is estimated or logged.
        const est = tasks.reduce((s, t) => s + (t.estimate_hours !== null && t.estimate_hours !== undefined ? Number(t.estimate_hours) : 0), 0);
        const logged = tasks.reduce((s, t) => s + Number(t.logged_minutes || 0), 0) / 60;
        const effort = est > 0 || logged > 0 ? '<span class="prj-lane-count" title="' + esc(T('effort.lane_tip')) + '">' + esc(T('effort.lane', { est: hrsTxt(est), logged: hrsTxt(logged) })) + '</span>' : '';
        const dates = stage.start_date || stage.end_date
            ? '<span class="prj-lane-dates">' + esc((stage.start_date ? P.fmtDate(stage.start_date) : '...') + ' - ' + (stage.end_date ? P.fmtDate(stage.end_date) : '...')) + '</span>' : '';
        const action = stage.status === 'planned'
            ? '<button type="button" class="btn btn-secondary sm" data-stage-start="' + stage.id + '">' + esc(T('plan.start_stage')) + '</button>'
            : stage.status === 'active'
                ? '<button type="button" class="btn btn-primary prj-btn sm" data-stage-finish="' + stage.id + '">' + esc(T('plan.finish_stage')) + '</button>' : '';
        return '<section class="prj-lane st-' + esc(stage.status) + '" data-lane="' + stage.id + '">'
            + '<header class="prj-lane-head">'
            +   '<span class="prj-lane-kind">' + esc(T('timebox.' + (stage.kind || kind))) + '</span>'
            +   '<div class="prj-lane-titles"><h3>' + esc(stage.name) + '</h3>' + (stage.goal ? '<p class="prj-muted">' + esc(stage.goal) + '</p>' : '')
            +     (window.PrjMilestones ? window.PrjMilestones.chips(stage.id) : '') + '</div>'
            +   '<div class="prj-lane-side">' + dates
            +     '<span class="prj-stage-pill sp-' + esc(stage.status) + '">' + esc(T('stage_status.' + stage.status)) + '</span>'
            +     '<span class="prj-lane-count">' + esc(T('plan.stage_done', { done: done, total: total })) + '</span>' + effort
            +     action
            +     '<button type="button" class="prj-icon-btn" data-stage-edit="' + stage.id + '" title="' + esc(T('plan.stage_edit', { timebox: P.timeboxWord(stage.kind || kind) })) + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>'
            +   '</div>'
            + '</header>'
            + '<span class="prj-bar thin"><span style="width:' + pct + '%;background:' + P.gradient(data.project.colour) + '"></span></span>'
            + '<div class="prj-lane-tasks" data-drop="' + stage.id + '">' + tasks.map(taskRow).join('') + '</div>'
            + addRow(stage.id) + '</section>';
    }

    function renderPlan() {
        const kind = timeboxKind();
        const byStage = {};
        data.tasks.forEach(t => { const k = t.project_stage_id || ''; (byStage[k] = byStage[k] || []).push(t); });
        const canEdit = !data.permissions || data.permissions.can_change;
        let html = '<div class="prj-plan-head"><p class="prj-muted">' + esc(T('plan.intro', { timeboxes: P.timeboxWord(kind, true) })) + '</p>'
            + '<div class="prj-plan-actions">'
            + (canEdit ? '<button type="button" class="btn btn-secondary" data-ms-new>+ ' + esc(T('milestones.add')) + '</button>' : '')
            + '<button type="button" class="btn btn-primary prj-btn" id="pvAddStage">+ ' + esc(T('timebox.add_' + kind)) + '</button></div></div>';
        if (window.PrjMilestones) html += window.PrjMilestones.projectStrip();
        if (!data.stages.length) html += '<div class="prj-plan-empty">' + esc(T('plan.no_stages', { timeboxes: P.timeboxWord(kind, true) })) + '</div>';
        html += data.stages.map(s => lane(s, byStage[s.id] || [])).join('');
        html += lane(null, byStage[''] || []);
        document.getElementById('pvPlan').innerHTML = html;
        if (data.permissions && !data.permissions.can_change) document.querySelectorAll('#pvPlan .prj-task').forEach(r => { r.draggable = false; });
    }

    // ---- Drawing everything ---------------------------------------------------------
    function renderAll() {
        // What this analyst may do (Projects -> Settings -> General). The server
        // refuses anything else; this only stops the page offering it.
        const perms = data.permissions || { can_change: true, can_delete: true };
        page.classList.toggle('prj-readonly', !perms.can_change);
        document.getElementById('pvEdit').hidden = !perms.can_change;
        document.getElementById('pvDelete').hidden = !perms.can_delete;
        page.querySelectorAll('.prj-task[draggable]').forEach(r => { r.draggable = !!perms.can_change; });
        const toolCtx = { data: data, L: L, projectId: projectId, refresh: refresh, page: page };
        renderBanner();
        renderOverview();
        if (window.PrjMilestones) window.PrjMilestones.render(toolCtx);
        renderPlan();
        renderConnections();
        renderHistory();
        // Phase 2 tools: a tab per tool the project has switched on (its method's
        // defaults, then its tailoring); a hidden tool's tab is never the open one.
        const tools = data.project.tools || [];
        document.querySelectorAll('#prjTabs [data-tool]').forEach(b => { b.hidden = !tools.includes(b.dataset.tool); });
        const cur = document.querySelector('#prjTabs [data-tab="' + tab + '"]');
        if (!cur || cur.hidden) tab = 'overview';
        if (window.PrjTools) window.PrjTools.render(toolCtx);
        if (window.PrjTargets) window.PrjTargets.render({ data: data, projectId: projectId, refresh: refresh });
        if (window.PrjBudget) window.PrjBudget.render({ data: data, projectId: projectId, refresh: refresh });
        if (window.PrjReports) window.PrjReports.render({ data: data, projectId: projectId, refresh: refresh });
        showTab(tab);
        if (window.PrjTimeline) window.PrjTimeline.render(toolCtx);   // after showTab: it draws only when visible
    }

    function showTab(name) {
        tab = name;
        document.querySelectorAll('#prjTabs [data-tab]').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
        document.querySelectorAll('.prj-tab-panel').forEach(s => { s.hidden = s.dataset.panel !== name; });
        if (name === 'timeline' && window.PrjTimeline) window.PrjTimeline.shown();
        try { history.replaceState(null, '', '#' + name); } catch (e) { /* ignore */ }
    }

    async function load() {
        try {
            const [d, lk, ln] = await Promise.all([P.api('get.php?id=' + projectId), P.lookups(), P.api('links.php?project_id=' + projectId).catch(() => null)]);
            L = lk;
            links = ln;
            P.setPalette(L.colours);
            data = d;
            // A brand-new project has nothing in it yet: open the add box so the
            // first thing on the Plan tab is somewhere to type.
            if (!data.tasks.length && !data.stages.length) openAdd = '';
            renderAll();
            // The recent trail (#124).
            if (window.trailVisit) window.trailVisit('project', projectId);
        } catch (e) {
            document.getElementById('prjNotFound').hidden = false;
            document.querySelectorAll('.prj-tab-panel').forEach(s => { s.hidden = true; });
        }
    }

    async function refresh() {
        const d = await P.api('get.php?id=' + projectId);
        data = d;
        renderAll();
    }

    // ---- Connections ------------------------------------------------------------------
    const LINK_ICONS = {
        asset: 'laptop', change: 'wrench', ticket: 'mail', contract: 'box', cmdb: 'network', article: 'star',
    };

    function linkHistoryText(v) {
        const m = /^(\w+): (.*)$/.exec(v);
        if (!m) return v;
        return T('links.kind.' + m[1]) + ': ' + m[2];
    }

    /** "Connected to" chips on the Overview, one per kind that has links. */
    function linksSummary() {
        if (!links || !links.links) return '';
        const chips = Object.entries(links.links).filter(([, rows]) => rows.length)
            .map(([kind, rows]) => '<button type="button" class="prj-link-chip" data-goto="connections">' + P.icon(LINK_ICONS[kind], 15)
                + '<b>' + rows.length + '</b> ' + esc(T('links.kind.' + kind)) + '</button>');
        if (!chips.length) return '';
        return '<div class="prj-panel prj-ov-links"><h3>' + esc(T('links.summary')) + '</h3><div class="prj-link-chips">' + chips.join('') + '</div></div>';
    }

    function linkRow(kind, r) {
        return '<li class="prj-conn-row' + (r.closed ? ' closed' : '') + '">'
            + '<a href="' + esc(window.PRJ_BASE + r.url) + '" class="prj-conn-main">'
            +   '<span class="prj-conn-label">' + esc(r.label) + '</span>'
            +   (r.sub ? '<span class="prj-conn-sub">' + esc(r.sub) + '</span>' : '')
            + '</a>'
            + (r.status ? '<span class="prj-conn-status"' + (r.status_colour ? ' style="--sc:' + esc(r.status_colour) + '"' : '') + '>' + esc(r.status) + '</span>' : '')
            + '<button type="button" class="prj-task-remove" data-unlink="' + esc(kind) + ':' + r.id + '" title="' + esc(T('links.remove')) + '" aria-label="' + esc(T('links.remove')) + '">&times;</button>'
            + '</li>';
    }

    /**
     * Service Status (3.2.0, going live safely): disruption this project has
     * announced, and the Announce button. data.announcements is null for
     * somebody who cannot open Service Status - then there is no panel at all.
     * Planned maintenance is not an incident until it starts; the server turns
     * it into one at its start and resolves it at its end.
     */
    function announcePanel() {
        const a = data.announcements;
        if (!a || (a.mode === 'off' && !a.list.length)) return '';
        const can = data.permissions && data.permissions.can_change && a.mode !== 'off';
        const when = p => p.end ? T('announce.when_range', { start: window.fmtDateTime(p.start), end: window.fmtDateTime(p.end) })
                                : T('announce.when_open', { start: window.fmtDateTime(p.start) });
        const rows = a.list.map(p => '<li class="prj-conn-row' + (p.state === 'finished' || p.state === 'cancelled' ? ' closed' : '') + '">'
            + '<div class="prj-conn-main"><span class="prj-conn-label">' + esc(p.title) + '</span>'
            + '<span class="prj-conn-sub">' + esc(when(p)) + ' - ' + esc((p.services || []).map(s => s.name + (s.impact ? ' (' + s.impact + ')' : '')).join(', ')) + '</span></div>'
            + '<span class="prj-conn-status prj-ann-' + esc(p.state) + '">' + esc(T('announce.state_' + p.state)) + '</span>'
            + (can && p.state === 'scheduled' ? '<button type="button" class="prj-task-remove" data-withdraw="' + p.id + '" title="' + esc(T('announce.withdraw')) + '" aria-label="' + esc(T('announce.withdraw')) + '">&times;</button>' : '')
            + '</li>').join('');
        return '<section class="prj-panel prj-conn prj-announce">'
            + '<header class="prj-conn-head"><span class="prj-conn-icon">' + P.icon('server', 18) + '</span>'
            + '<div><h4>' + esc(T('announce.title')) + ' <span class="prj-conn-count">' + a.list.length + '</span></h4>'
            + '<p class="prj-muted">' + esc(T('announce.hint_' + a.mode)) + '</p></div>'
            + (can ? '<button type="button" class="btn btn-primary prj-btn sm" data-announce>' + esc(T('announce.button')) + '</button>' : '')
            + '</header>'
            + (rows ? '<ul class="prj-conn-list">' + rows + '</ul>' : '<p class="prj-conn-empty">' + esc(T('announce.none')) + '</p>')
            + '</section>';
    }

    function announceRow(serviceId, impactId) {
        const a = data.announcements;
        // Default impact by meaning: the most serious level that does not count
        // as downtime and is not the all-clear - Maintenance on a stock install.
        const maint = a.impacts.filter(l => !Number(l.counts_as_downtime) && !Number(l.is_default)).sort((x, y) => x.severity_order - y.severity_order)[0];
        const pick = impactId || (maint && maint.id);
        const row = document.createElement('div');
        row.className = 'prj-ann-row';
        row.innerHTML = '<select class="prj-ann-svc">' + a.services.map(s => '<option value="' + s.id + '"' + (String(s.id) === String(serviceId) ? ' selected' : '') + '>' + esc(s.name) + '</option>').join('') + '</select>'
            + '<select class="prj-ann-impact">' + a.impacts.map(l => '<option value="' + l.id + '"' + (String(l.id) === String(pick) ? ' selected' : '') + '>' + esc(l.name) + '</option>').join('') + '</select>'
            + '<button type="button" class="prj-task-remove" aria-label="' + esc(T('announce.remove_service')) + '">&times;</button>';
        row.querySelector('button').addEventListener('click', () => row.remove());
        document.getElementById('paServices').appendChild(row);
    }

    function openAnnounce() {
        const a = data.announcements;
        const planned = a.mode === 'planned';
        document.getElementById('paIntro').textContent = T('announce.intro_' + a.mode);
        document.getElementById('paTitle').value = '';
        document.getElementById('paComment').value = '';
        document.getElementById('paStartWrap').hidden = !planned;
        document.getElementById('paStart').value = '';
        document.getElementById('paEnd').value = '';
        // The stage in progress, as a starting point for the dates.
        const st = (data.stages || []).find(s => s.status === 'active' && s.end_date);
        if (planned && st) document.getElementById('paStart').value = st.end_date + 'T08:00';
        document.getElementById('paServices').innerHTML = '';
        if (a.services.length) announceRow();
        document.getElementById('paError').hidden = true;
        P.openModal('prjAnnounceModal');
    }

    async function saveAnnounce() {
        const err = document.getElementById('paError');
        const services = Array.from(document.querySelectorAll('#paServices .prj-ann-row')).map(r => ({
            service_id: parseInt(r.querySelector('.prj-ann-svc').value, 10), impact_level_id: parseInt(r.querySelector('.prj-ann-impact').value, 10) }));
        try {
            const r = await P.api('tools.php', { action: 'announce', project_id: projectId,
                title: document.getElementById('paTitle').value.trim(), comment: document.getElementById('paComment').value.trim(),
                start: window.inputToUTC(document.getElementById('paStart').value), end: window.inputToUTC(document.getElementById('paEnd').value), services: services });
            data.announcements = r.announcements;
            P.closeModal('prjAnnounceModal');
            P.toast(T('announce.saved'));
            const d = await P.api('get.php?id=' + projectId);   // the history shows it
            data = d;
            renderConnections();
            renderHistory();
        } catch (e) { err.textContent = e.message; err.hidden = false; }
    }

    async function withdrawAnnounce(id) {
        const p = (data.announcements.list || []).find(x => String(x.id) === String(id));
        if (!p) return;
        const ok = window.showConfirm ? await window.showConfirm({ title: T('announce.withdraw_title'), message: T('announce.withdraw_message', { name: p.title }),
            okLabel: T('announce.withdraw'), okClass: 'danger' }) : confirm(T('announce.withdraw_title'));
        if (!ok) return;
        try {
            const r = await P.api('tools.php', { action: 'announce_withdraw', project_id: projectId, id: p.id });
            data.announcements = r.announcements;
            renderConnections();
        } catch (e) { P.toast(e.message, 'error'); }
    }

    function renderConnections() {
        const box = document.getElementById('pvConnections');
        if (!links) { box.innerHTML = ''; return; }
        if (!links.ready) { box.innerHTML = '<div class="prj-plan-empty">' + esc(T('links.not_ready')) + '</div>'; return; }
        const kinds = Object.keys(links.links || {});
        if (!kinds.length) { box.innerHTML = '<div class="prj-plan-empty">' + esc(T('links.none_kinds')) + '</div>'; return; }
        box.innerHTML = '<p class="prj-muted prj-conn-intro">' + esc(T('links.intro')) + '</p>' + announcePanel() + '<div class="prj-conn-grid">'
            + kinds.map(kind => {
                const rows = links.links[kind] || [];
                return '<section class="prj-panel prj-conn" data-kind="' + esc(kind) + '">'
                    + '<header class="prj-conn-head"><span class="prj-conn-icon">' + P.icon(LINK_ICONS[kind], 18) + '</span>'
                    + '<div><h4>' + esc(T('links.kind.' + kind)) + ' <span class="prj-conn-count">' + rows.length + '</span></h4>'
                    + '<p class="prj-muted">' + esc(T('links.hint.' + kind)) + '</p></div></header>'
                    + (rows.length ? '<ul class="prj-conn-list">' + rows.map(r => linkRow(kind, r)).join('') + '</ul>' : '<p class="prj-conn-empty">' + esc(T('links.empty')) + '</p>')
                    + '<div class="prj-conn-add"><input type="text" data-link-search="' + esc(kind) + '" placeholder="' + esc(T('links.add_ph')) + '" autocomplete="off">'
                    + '<ul class="prj-conn-results" hidden></ul></div>'
                    + '</section>';
            }).join('') + '</div>';
    }

    async function reloadLinks() {
        try { links = await P.api('links.php?project_id=' + projectId); } catch (e) { /* keep the last copy */ }
        renderConnections();
        renderOverview();
    }

    let searchTimer = null;
    function searchLinks(input) {
        clearTimeout(searchTimer);
        const kind = input.dataset.linkSearch;
        const list = input.parentNode.querySelector('.prj-conn-results');
        searchTimer = setTimeout(async () => {
            try {
                const d = await P.api('links.php?project_id=' + projectId + '&search=' + encodeURIComponent(kind) + '&q=' + encodeURIComponent(input.value.trim()));
                list.innerHTML = (d.results || []).length
                    ? d.results.map(r => '<li><button type="button" data-link-add="' + esc(kind) + ':' + r.id + '"><span class="prj-conn-label">' + esc(r.label) + '</span>'
                        + (r.sub ? '<span class="prj-conn-sub">' + esc(r.sub) + '</span>' : '') + '</button></li>').join('')
                    : '<li class="prj-conn-noresult">' + esc(T('links.no_results')) + '</li>';
                list.hidden = false;
            } catch (e) { P.toast(e.message, 'error'); }
        }, 180);
    }

    async function linkAction(action, value) {
        const [kind, id] = value.split(':');
        try {
            await P.api('links.php', { action: action, project_id: projectId, kind: kind, target_id: parseInt(id, 10) });
            P.toast(T(action === 'add' ? 'links.linked' : 'links.unlinked'));
            const d = await P.api('get.php?id=' + projectId);   // the history shows the change
            data = d;
            await reloadLinks();
            renderHistory();
            const again = document.querySelector('[data-link-search="' + kind + '"]');
            if (again && action === 'add') again.focus();
        } catch (e) { P.toast(e.message, 'error'); }
    }

    // ---- Stages -------------------------------------------------------------------
    function openStage(stage) {
        const kind = stage ? (stage.kind || timeboxKind()) : timeboxKind();
        const word = P.timeboxWord(kind);
        document.getElementById('psTitle').textContent = stage ? T('plan.stage_edit', { timebox: word }) : T('plan.stage_new', { timebox: word });
        document.getElementById('psId').value = stage ? stage.id : '';
        document.getElementById('psName').value = stage ? stage.name : '';
        document.getElementById('psGoal').value = stage ? (stage.goal || '') : '';
        document.getElementById('psGoal').placeholder = T('plan.stage_goal_ph', { timebox: word });
        document.getElementById('psStart').value = stage ? (stage.start_date || '') : '';
        document.getElementById('psEnd').value = stage ? (stage.end_date || '') : '';
        document.getElementById('psStatus').value = stage ? stage.status : 'planned';
        document.getElementById('psDelete').hidden = !stage;
        document.getElementById('psError').hidden = true;
        P.openModal('prjStageModal');
        setTimeout(() => document.getElementById('psName').focus(), 60);
    }

    async function saveStage() {
        const id = document.getElementById('psId').value;
        const body = {
            project_id: projectId,
            name: document.getElementById('psName').value.trim(),
            goal: document.getElementById('psGoal').value.trim(),
            start_date: document.getElementById('psStart').value || null,
            end_date: document.getElementById('psEnd').value || null,
            status: document.getElementById('psStatus').value,
        };
        if (id) body.id = parseInt(id, 10);
        const was = id ? data.stages.find(s => String(s.id) === id) : null;
        try {
            await P.api('stage_save.php', body);
            P.closeModal('prjStageModal');
            await refresh();
            if (was && was.status !== 'closed' && body.status === 'closed') cheer(body.name, id);
        } catch (e) {
            const er = document.getElementById('psError'); er.textContent = e.message; er.hidden = false;
        }
    }

    async function setStageStatus(id, status) {
        const s = data.stages.find(x => String(x.id) === String(id));
        if (!s) return;
        try {
            await P.api('stage_save.php', { project_id: projectId, id: s.id, name: s.name, status: status });
            await refresh();
            if (status === 'closed') cheer(s.name, s.id);
        } catch (e) { P.toast(e.message, 'error'); }
    }

    function cheer(name, stageId) {
        const el = document.querySelector('[data-lane="' + stageId + '"] .prj-lane-head') || document.getElementById('pvRing');
        P.celebrate(el);
        P.toast(T('celebrate.stage_done', { name: name }));
    }

    async function deleteStage() {
        const id = document.getElementById('psId').value;
        const s = data.stages.find(x => String(x.id) === id);
        if (!s) return;
        const word = P.timeboxWord(s.kind || timeboxKind());
        const ok = await window.showConfirm({ title: T('plan.stage_delete_title', { timebox: word }), message: T('plan.stage_delete_body', { timebox: word }), okLabel: P.TC('delete'), okClass: 'danger' });
        if (!ok) return;
        try {
            await P.api('stage_delete.php', { project_id: projectId, id: s.id });
            P.closeModal('prjStageModal');
            await refresh();
        } catch (e) { P.toast(e.message, 'error'); }
    }

    // ---- Tasks --------------------------------------------------------------------
    async function addTask(form) {
        const title = form.title.value.trim();
        if (!title) { form.title.focus(); return; }
        const btn = form.querySelector('button'); btn.disabled = true;
        try {
            await P.api('task_create.php', {
                project_id: projectId,
                stage_id: form.dataset.stage || null,
                title: title,
                assigned_analyst_id: form.assignee.value || null,
                due_date: form.due.value || null,
            });
            const stageKey = form.dataset.stage;
            await refresh();
            P.toast(T('plan.task_added'));
            const again = document.querySelector('.prj-add-task[data-stage="' + stageKey + '"] input[name="title"]');
            if (again) again.focus();
        } catch (e) {
            P.toast(e.message, 'error');
            btn.disabled = false;
        }
    }

    async function moveTask(taskId, stageId) {
        const t = data.tasks.find(x => String(x.id) === String(taskId));
        if (!t || String(t.project_stage_id || '') === String(stageId || '')) return;
        try {
            await P.api('task_assign.php', { task_id: taskId, project_id: projectId, stage_id: stageId || null });
            await refresh();
        } catch (e) { P.toast(e.message, 'error'); }
    }

    async function removeTask(taskId) {
        const ok = await window.showConfirm({ title: T('plan.remove_task'), message: T('plan.remove_task_body'), okLabel: T('plan.remove'), okClass: 'danger' });
        if (!ok) return;
        try {
            await P.api('task_assign.php', { task_id: taskId, project_id: null });
            await refresh();
        } catch (e) { P.toast(e.message, 'error'); }
    }

    // ---- Project actions -------------------------------------------------------------
    function editProject() {
        const before = data.project.status;
        P.openProjectForm(data.project, async (id, isNew, body) => {
            await P.lookups();
            await refresh();
            if (before !== 'closed' && body.status === 'closed') {
                P.celebrate(document.getElementById('pvRing'));
                P.toast(T('celebrate.project_done'));
            }
        });
    }

    async function deleteProject() {
        const kind = timeboxKind();
        const ok = await window.showConfirm({
            title: T('view.delete_title'),
            message: T('view.delete_body', { timeboxes: P.timeboxWord(kind, true), count: data.tasks.length }),
            okLabel: P.TC('delete'), okClass: 'danger',
        });
        if (!ok) return;
        try {
            await P.api('delete.php', { id: projectId });
            P.toast(T('view.deleted'));
            window.location.href = window.PRJ_BASE + 'projects/';
        } catch (e) { P.toast(e.message, 'error'); }
    }

    // ---- Wiring -----------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        const start = (location.hash || '').replace('#', '');
        if (['overview', 'plan', 'timeline', 'people', 'scope', 'raci', 'raid', 'gates', 'budget', 'reports', 'connections', 'history'].includes(start)) tab = start;
        if (/[?&]new=1/.test(location.search)) tab = 'plan';

        document.getElementById('prjTabs').addEventListener('click', e => {
            const b = e.target.closest('[data-tab]'); if (b) showTab(b.dataset.tab);
        });
        document.getElementById('pvEdit').addEventListener('click', editProject);
        document.getElementById('pvDelete').addEventListener('click', deleteProject);
        document.getElementById('psSave').addEventListener('click', saveStage);
        document.getElementById('paSave').addEventListener('click', saveAnnounce);
        document.getElementById('paAddService').addEventListener('click', () => announceRow());
        document.getElementById('psDelete').addEventListener('click', deleteStage);
        document.getElementById('psName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); saveStage(); } });

        page.addEventListener('click', e => {
            const g = e.target.closest('[data-goto]'); if (g) { showTab(g.dataset.goto); return; }
            if (e.target.closest('#pvAddStage')) { openStage(null); return; }
            const ed = e.target.closest('[data-stage-edit]');
            if (ed) { openStage(data.stages.find(s => String(s.id) === ed.dataset.stageEdit)); return; }
            const st = e.target.closest('[data-stage-start]'); if (st) { setStageStatus(st.dataset.stageStart, 'active'); return; }
            const fi = e.target.closest('[data-stage-finish]'); if (fi) { setStageStatus(fi.dataset.stageFinish, 'closed'); return; }
            const rm = e.target.closest('[data-remove-task]'); if (rm) { e.preventDefault(); removeTask(rm.dataset.removeTask); return; }
            const la = e.target.closest('[data-link-add]'); if (la) { linkAction('add', la.dataset.linkAdd); return; }
            const ul = e.target.closest('[data-unlink]'); if (ul) { linkAction('remove', ul.dataset.unlink); return; }
            if (e.target.closest('[data-announce]')) { openAnnounce(); return; }
            const wd = e.target.closest('[data-withdraw]'); if (wd) { withdrawAnnounce(wd.dataset.withdraw); return; }
            if (!e.target.closest('.prj-conn-add')) document.querySelectorAll('.prj-conn-results').forEach(l => { l.hidden = true; });
            const ao = e.target.closest('[data-add-open]');
            if (ao) {
                openAdd = ao.dataset.addOpen;
                renderPlan();
                const f = document.querySelector('.prj-add-task[data-stage="' + openAdd + '"] input[name="title"]');
                if (f) f.focus();
            }
        });
        // The Plan's estimate box (3.3.0): saved when it is left or Enter is pressed.
        page.addEventListener('change', async e => {
            const box = e.target.closest('[data-estimate]');
            if (!box) return;
            try {
                await P.api('tools.php', { action: 'task_estimate', project_id: projectId, task_id: parseInt(box.dataset.estimate, 10), estimate_hours: box.value === '' ? null : box.value });
                // Tabbing down the list moved focus to the next box before the redraw
                // replaced it: put it back, so a column of estimates can be typed in one go.
                const next = document.activeElement && document.activeElement.dataset ? document.activeElement.dataset.estimate : null;
                await refresh();
                if (next) { const el = document.querySelector('[data-estimate="' + next + '"]'); if (el) { el.focus(); el.select(); } }
            } catch (err) { P.toast(err.message, 'error'); await refresh(); }
        });
        page.addEventListener('input', e => {
            const s = e.target.closest('[data-link-search]'); if (s) searchLinks(s);
        });
        page.addEventListener('focusin', e => {
            const s = e.target.closest('[data-link-search]'); if (s) searchLinks(s);
        });
        page.addEventListener('keydown', e => {
            if (e.key === 'Escape' && e.target.closest('[data-link-search]')) { e.target.parentNode.querySelector('.prj-conn-results').hidden = true; }
            if (e.key === 'Escape' && e.target.closest('.prj-add-task')) { openAdd = null; renderPlan(); }
            if (e.key === 'Enter' && e.target.closest('[data-estimate]')) { e.preventDefault(); e.target.blur(); }
        });
        page.addEventListener('submit', e => {
            const f = e.target.closest('.prj-add-task'); if (!f) return;
            e.preventDefault(); addTask(f);
        });

        // Drag a task between lanes (desktop). On a phone the row's link still opens the task.
        let dragId = null;
        page.addEventListener('dragstart', e => {
            const row = e.target.closest('.prj-task'); if (!row) return;
            dragId = row.dataset.task;
            row.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', dragId); } catch (err) { /* old browsers */ }
        });
        page.addEventListener('dragend', e => {
            const row = e.target.closest('.prj-task'); if (row) row.classList.remove('dragging');
            document.querySelectorAll('.prj-lane.drop-over').forEach(l => l.classList.remove('drop-over'));
        });
        page.addEventListener('dragover', e => {
            const laneEl = e.target.closest('.prj-lane'); if (!laneEl || !dragId) return;
            e.preventDefault();
            document.querySelectorAll('.prj-lane.drop-over').forEach(l => { if (l !== laneEl) l.classList.remove('drop-over'); });
            laneEl.classList.add('drop-over');
        });
        page.addEventListener('drop', e => {
            const laneEl = e.target.closest('.prj-lane'); if (!laneEl || !dragId) return;
            e.preventDefault();
            laneEl.classList.remove('drop-over');
            const id = dragId; dragId = null;
            moveTask(id, laneEl.dataset.lane);
        });

        load();
    });
})();
