/**
 * FreeITSM Tasks — Calendar View
 *
 * Renders parent tasks onto a month grid. How a task that spans several days
 * (start_date earlier than due_date) is drawn depends on the calendar span
 * mode set in Tasks → Settings → Calendar:
 *   deadline — a single chip on the due date only
 *   span     — one continuous bar across the whole range
 *   repeat   — a chip in every day cell of the range
 */

// ── State ──────────────────────────────────────────────────────────
let viewDate = new Date();
viewDate.setHours(0, 0, 0, 0);
viewDate.setDate(1);

let viewMode = 'month';  // 'month' | 'week' | 'day'
let currentFilter = 'my';
let currentFilterTeamId = null;
let currentFilterAnalystId = null;
// '' parent tasks only (the default, as before), 'both', or 'only' (#90).
// Seeded from the stored preference, which the page renders into the markup so
// the select is already right on arrival rather than correcting itself after a
// fetch. Falls back to the old behaviour if the page did not publish one.
let currentSubtaskScope = window.CAL_SUBTASK_SCOPE || '';
let tasks = [];
// Future occurrences of fixed schedules - dates, not rows (#94).
let projected = [];
let statuses = [];
let priorities = [];
let analysts = [];
let teams = [];
let spanMode = 'deadline';
let surfaceTags = false;

// The repeat mark (#94). Deliberately the same shape the detail panel uses, so a
// reader does not have to learn two. White because it sits on the coloured chip.
const CAL_ICON_RECUR = '<svg class="recur-icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>';

// Locale for date formatting — matches the page's i18n locale

// ── Init ───────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
    await loadSettings();
    await Promise.all([loadDropdowns(), loadStatuses(), loadPriorities()]);
    loadTasks();
    TasksQuickPanel.init({
        apiBase: API_BASE,
        getLookups: () => ({ analysts, teams, statuses, priorities }),
        onUpdate: () => loadTasks(),
        fullEditUrl: id => '../index.php?task=' + id,
    });
    // The details card on hovering a bar or chip (month and week views). The day
    // view lists the same details in each row, so it needs none.
    TasksHoverCard.init({ selector: '.cal-bar[data-hc]' });
});

/** Loads the Tasks settings the calendar uses - the span mode and whether tags show on the calendar. */
async function loadSettings() {
    try {
        const d = await fetch(API_BASE + 'get_settings.php').then(r => r.json());
        if (d.success && d.settings.calendar_span_mode) spanMode = d.settings.calendar_span_mode;
        if (d.success && d.settings.tag_settings) surfaceTags = !!d.settings.tag_settings.surface_calendar;
    } catch (e) { console.error('Failed to load settings:', e); }
}

/** Loads the task statuses and redraws the colour legend. */
async function loadStatuses() {
    try {
        const d = await fetch(API_BASE + 'get_task_statuses.php').then(r => r.json());
        if (d.success) {
            statuses = d.statuses || [];
            renderLegend();
        }
    } catch (e) { console.error('Failed to load statuses:', e); }
}

/** Loads the active task priorities for the quick-look panel. */
async function loadPriorities() {
    try {
        const d = await fetch(API_BASE + 'get_task_priorities.php').then(r => r.json());
        if (d.success) priorities = (d.priorities || []).filter(p => p.is_active);
    } catch (e) { console.error('Failed to load priorities:', e); }
}

/** Loads analysts and teams and fills the Analyst and Team filter drop-downs. */
async function loadDropdowns() {
    try {
        const [aRes, tRes] = await Promise.all([
            fetch(API_BASE + 'list.php?analysts=1').then(r => r.json()),
            fetch(API_BASE + 'list.php?teams=1').then(r => r.json())
        ]);
        if (aRes.success) {
            analysts = aRes.analysts;
            document.getElementById('analystFilter').innerHTML =
                '<option value="">' + esc(window.t('tasks.filter.all_analysts')) + '</option>' +
                analysts.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
        }
        if (tRes.success) {
            teams = tRes.teams;
            document.getElementById('teamFilter').innerHTML =
                '<option value="">' + esc(window.t('tasks.filter.all_teams')) + '</option>' +
                teams.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
        }
    } catch (e) { console.error('Failed to load dropdowns:', e); }
}

/**
 * Future occurrences of fixed-schedule repeats (#94), honouring the same
 * who-is-it-for filter as the real tasks beside them.
 *
 * These have no row behind them, so they cannot be filtered in SQL by the same
 * joins; the endpoint returns the assignee and the filtering happens here.
 * Getting that wrong would be worse than not showing them: a projection under
 * "My tasks" that is not yours is a promise about somebody else's week.
 */
function visibleProjected() {
    if (!projected.length) return [];
    return projected.filter(p => {
        // The page publishes it on <body>, the same source tasks.js reads.
        // window.ANALYST_ID does not exist on this page - reaching for it would
        // have made every projection vanish under "My tasks" and looked like the
        // projections simply not working.
        if (currentFilter === 'my')      return String(p.assigned_analyst_id) === String(document.body.dataset.analystId || '');
        if (currentFilter === 'analyst') return String(p.assigned_analyst_id) === String(currentFilterAnalystId);
        if (currentFilter === 'team')    return false;   // a projection carries no team of its own
        return true;                                     // 'all'
    });
}

/**
 * Loads the projected future occurrences of repeating tasks, from two months before to a year after
 * the month in view; a failure leaves none.
 */
async function loadProjected() {
    // A generous window either side of anything the calendar can show, so paging
    // a month does not need another round trip.
    const from = new Date(viewDate.getFullYear(), viewDate.getMonth() - 2, 1);
    const to   = new Date(viewDate.getFullYear(), viewDate.getMonth() + 12, 0);
    /** Formats a Date as YYYY-MM-DD through toISOString, so in UTC. */
    const ymd  = d => d.toISOString().slice(0, 10);
    try {
        const d = await fetch(`${API_BASE}projected.php?from=${ymd(from)}&to=${ymd(to)}`).then(r => r.json());
        projected = d.success ? (d.projected || []) : [];
    } catch (e) {
        projected = [];   // the calendar still draws every real task
    }
}

/**
 * Loads the tasks for the current filter and subtask scope, then the projected repeats, and redraws
 * the calendar.
 */
async function loadTasks() {
    let url = API_BASE + 'list.php?filter=' + currentFilter;
    if (currentSubtaskScope) url += '&subtasks=' + currentSubtaskScope;
    if (currentFilter === 'team' && currentFilterTeamId) url += '&team_id=' + currentFilterTeamId;
    if (currentFilter === 'analyst' && currentFilterAnalystId) url += '&analyst_id=' + currentFilterAnalystId;
    try {
        const d = await fetch(url).then(r => r.json());
        if (d.success) {
            tasks = d.tasks;
            await loadProjected();
            renderCalendar();
        }
    } catch (e) { console.error('Failed to load tasks:', e); }
}

// ── Filters ────────────────────────────────────────────────────────
function setSubtaskScope(scope) {
    currentSubtaskScope = scope;
    loadTasks();
    // Remember it. The calendar redraws either way, so the save is deliberately
    // not awaited and a failure is silent: this is a view preference, and
    // interrupting someone with a toast because a preference did not persist
    // would be worse than quietly showing them the default next time.
    fetch('../../api/system/set_user_preference.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: 'tasks_calendar_subtasks', value: scope })
    }).catch(() => {});
}

/** Switches to a preset filter (my, all...), clears the team and analyst filters and reloads. */
function setFilter(filter) {
    currentFilter = filter;
    currentFilterTeamId = null;
    currentFilterAnalystId = null;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    const btn = document.querySelector(`.filter-btn[data-filter="${filter}"]`);
    if (btn) btn.classList.add('active');
    document.getElementById('teamFilter').value = '';
    document.getElementById('analystFilter').value = '';
    loadTasks();
}

/** Shows one team's tasks, or goes back to My tasks when cleared. */
function setTeamFilter(teamId) {
    if (!teamId) { setFilter('my'); return; }
    currentFilter = 'team';
    currentFilterTeamId = teamId;
    currentFilterAnalystId = null;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('analystFilter').value = '';
    loadTasks();
}

/** Shows one analyst's tasks, or goes back to My tasks when cleared. */
function setAnalystFilter(analystId) {
    if (!analystId) { setFilter('my'); return; }
    currentFilter = 'analyst';
    currentFilterAnalystId = analystId;
    currentFilterTeamId = null;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('teamFilter').value = '';
    loadTasks();
}

// ── Navigation ─────────────────────────────────────────────────────
function calPrev() {
    if (viewMode === 'month') viewDate.setMonth(viewDate.getMonth() - 1);
    else if (viewMode === 'week') viewDate.setDate(viewDate.getDate() - 7);
    else viewDate.setDate(viewDate.getDate() - 1);
    renderCalendar();
}
/** Moves forward one month, week or day, depending on the view. */
function calNext() {
    if (viewMode === 'month') viewDate.setMonth(viewDate.getMonth() + 1);
    else if (viewMode === 'week') viewDate.setDate(viewDate.getDate() + 7);
    else viewDate.setDate(viewDate.getDate() + 1);
    renderCalendar();
}
/** Jumps back to today (the 1st of this month in month view). */
function calToday() {
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    viewDate = (viewMode === 'month')
        ? new Date(now.getFullYear(), now.getMonth(), 1)
        : now;
    renderCalendar();
}

/** Switches between month, week and day views. */
function setView(mode) {
    viewMode = mode;
    document.querySelectorAll('.view-btn').forEach(b =>
        b.classList.toggle('active', b.dataset.view === mode));
    // Month view anchors to the 1st; week/day anchors to the day itself.
    if (mode === 'month') viewDate.setDate(1);
    renderCalendar();
}

// ── Rendering ──────────────────────────────────────────────────────
function renderCalendar() {
    document.getElementById('calModeHint').textContent =
        window.t('tasks.calendar.mode_hint', { mode: window.t('tasks.calendar.mode_' + spanMode) });
    if (viewMode === 'week') return renderWeek();
    if (viewMode === 'day')  return renderDay();
    renderMonth();
}

// Resolve each task to the date range it occupies, respecting span mode.
// Projected occurrences (#94) are merged in here rather than rendered
// separately, so they get the same placement, span and week-wrapping logic and
// cannot drift from how real tasks are drawn.
function placedTasks() {
    const placed = [];
    tasks.concat(visibleProjected()).forEach(t => {
        if (!t.due_date) return;
        const end = t.due_date;
        let start = end;
        if (spanMode !== 'deadline' && t.start_date && t.start_date <= end) start = t.start_date;
        placed.push({ task: t, start, end });
    });
    return placed;
}

// Render one week's day cells + bars. monthCtx is the month number to dim
// out-of-month cells against (or null in week view, where no dimming applies).
function renderWeekRow(weekStart, todayStr, placed, monthCtx) {
    const weekStartStr = ymd(weekStart);
    const weekEnd = new Date(weekStart);
    weekEnd.setDate(weekStart.getDate() + 6);
    const weekEndStr = ymd(weekEnd);

    const intervals = [];
    placed.forEach(p => {
        if (p.end < weekStartStr || p.start > weekEndStr) return;
        const segStart = p.start < weekStartStr ? weekStartStr : p.start;
        const segEnd = p.end > weekEndStr ? weekEndStr : p.end;
        intervals.push({
            p,
            startCol: dayDiff(weekStartStr, segStart),
            endCol: dayDiff(weekStartStr, segEnd),
            contLeft: p.start < weekStartStr,
            contRight: p.end > weekEndStr
        });
    });

    intervals.sort((a, b) =>
        a.startCol - b.startCol || (b.endCol - b.startCol) - (a.endCol - a.startCol));
    const laneEnd = [];
    intervals.forEach(iv => {
        let lane = 0;
        while (lane < laneEnd.length && laneEnd[lane] >= iv.startCol) lane++;
        laneEnd[lane] = iv.endCol;
        iv.lane = lane;
    });

    let cells = '';
    for (let c = 0; c < 7; c++) {
        const d = new Date(weekStart);
        d.setDate(weekStart.getDate() + c);
        const ds = ymd(d);
        const cls = [
            'cal-day',
            (monthCtx !== null && d.getMonth() !== monthCtx) ? 'cal-out' : '',
            ds === todayStr ? 'cal-today' : '',
            c >= 5 ? 'cal-weekend' : ''
        ].filter(Boolean).join(' ');
        cells += `<div class="${cls}"><span class="cal-daynum">${d.getDate()}</span></div>`;
    }

    let bars = '';
    intervals.forEach(iv => { bars += renderBars(iv); });

    return { cells, bars, lanes: laneEnd.length };
}

/** Draws the month view - one row per week from Monday, each high enough for its lanes of bars. */
function renderMonth() {
    const grid = document.getElementById('calGrid');
    document.getElementById('calWeekdays').style.display = '';
    grid.className = 'cal-grid';

    const year = viewDate.getFullYear();
    const month = viewDate.getMonth();

    document.getElementById('calTitle').textContent =
        fmtNaiveMonthYear(viewDate);

    const first = new Date(year, month, 1);
    const gridStart = new Date(first);
    gridStart.setDate(first.getDate() - mondayCol(first));
    const last = new Date(year, month + 1, 0);
    const gridEnd = new Date(last);
    gridEnd.setDate(last.getDate() + (6 - mondayCol(last)));
    const weekCount = (Math.round((gridEnd - gridStart) / 86400000) + 1) / 7;

    const placed = placedTasks();
    const todayStr = ymd(new Date());
    let html = '';

    for (let w = 0; w < weekCount; w++) {
        const weekStart = new Date(gridStart);
        weekStart.setDate(gridStart.getDate() + w * 7);
        const row = renderWeekRow(weekStart, todayStr, placed, month);
        const weekHeight = Math.max(96, 28 + row.lanes * 24 + 6);
        html += `<div class="cal-week" style="min-height:${weekHeight}px">
            <div class="cal-week-days">${row.cells}</div>
            <div class="cal-week-bars">${row.bars}</div>
        </div>`;
    }

    grid.innerHTML = html;
}

/** Draws the week view for the Monday-to-Sunday week holding the view date, with a compact range title. */
function renderWeek() {
    const grid = document.getElementById('calGrid');
    document.getElementById('calWeekdays').style.display = '';
    grid.className = 'cal-grid cal-week-view';

    // Monday of the week containing viewDate
    const weekStart = new Date(viewDate);
    weekStart.setDate(viewDate.getDate() - mondayCol(viewDate));
    const weekEnd = new Date(weekStart);
    weekEnd.setDate(weekStart.getDate() + 6);

    // Title — "5 – 11 May 2026" or "28 Apr – 4 May 2026"
    const sameMonth = weekStart.getMonth() === weekEnd.getMonth();
    const sameYear = weekStart.getFullYear() === weekEnd.getFullYear();
    // The week title compresses when it can ("5 - 11 May 2026"), so the shape is
    // dictated by the layout rather than by preference - hence fmtNaiveTemplate
    // rather than fmtNaiveDate. Month names still follow the language.
    const startLabel = fmtNaiveTemplate(weekStart,
        sameMonth ? 'D' : (sameYear ? 'D MON' : 'D MON YYYY'));
    const endLabel = fmtNaiveTemplate(weekEnd, 'D MON YYYY');
    document.getElementById('calTitle').textContent = `${startLabel} – ${endLabel}`;

    const placed = placedTasks();
    const todayStr = ymd(new Date());
    const row = renderWeekRow(weekStart, todayStr, placed, null);

    grid.innerHTML = `<div class="cal-week">
        <div class="cal-week-days">${row.cells}</div>
        <div class="cal-week-bars">${row.bars}</div>
    </div>`;
}

/**
 * Draws the day view as a list of the tasks on that date, open before done, with status, assignee,
 * dates, parent and tags.
 */
function renderDay() {
    const grid = document.getElementById('calGrid');
    document.getElementById('calWeekdays').style.display = 'none';
    grid.className = '';

    document.getElementById('calTitle').textContent =
        fmtNaiveWeekday(viewDate) + ' ' + fmtNaiveTemplate(viewDate, 'D MONTH YYYY');

    const dayStr = ymd(viewDate);
    const placed = placedTasks().filter(p => p.start <= dayStr && dayStr <= p.end);

    // Sort: open before done, then by title
    placed.sort((a, b) => {
        const ad = Number(a.task.status_is_closed) === 1 ? 1 : 0;
        const bd = Number(b.task.status_is_closed) === 1 ? 1 : 0;
        return ad - bd || a.task.title.localeCompare(b.task.title);
    });

    if (!placed.length) {
        grid.innerHTML = `<div class="cal-day-list"><div class="cal-day-empty">${esc(window.t('tasks.calendar.day_empty'))}</div></div>`;
        return;
    }

    const rows = placed.map(p => {
        const t = p.task;
        const done = Number(t.status_is_closed) === 1;
        const colour = t.status_colour || '#6b7280';
        const meta = [];
        if (t.status_name) meta.push(esc(t.status_name));
        if (t.analyst_name) meta.push(esc(t.analyst_name));
        if (p.start !== p.end) meta.push(esc(fmt(p.start) + ' → ' + fmt(p.end)));
        else if (t.due_date) meta.push(esc(fmt(t.due_date)));
        const tagDots = (surfaceTags && t.tags && t.tags.length)
            ? `<span class="cal-day-task-tags">${t.tags.slice(0, 4).map(tg =>
                `<span class="mini-tag-dot" style="background:${esc(tg.colour || '#6b7280')}"></span>`).join('')}</span>`
            : '';
        // In the day list there is room to say which task a subtask belongs to,
        // so it says it rather than relying on a tooltip (#90).
        if (t.parent_task_id && t.parent_title) meta.unshift(esc(t.parent_title));
        // A projection says so in words here. On the month grid there is only a
        // tooltip and a faded chip; in a list, "not created yet" is worth stating,
        // because otherwise it looks like a task you could open and edit.
        if (t.projected) meta.push(esc(window.t('tasks.recur.projected')));
        return `<div class="cal-day-task${done ? ' cal-day-task-done' : ''}${t.parent_task_id ? ' cal-day-task-subtask' : ''}${t.recurrence_id ? ' cal-day-task-recur' : ''}${t.projected ? ' cal-day-task-projected' : ''}" onclick="openTask(${t.projected ? t.source_task_id : t.id})">
            <span class="cal-day-task-dot" style="background:${esc(colour)}"></span>
            <div class="cal-day-task-body">
                <div class="cal-day-task-title">${t.recurrence_id ? CAL_ICON_RECUR : ''}${esc(t.title)}</div>
                ${meta.length ? `<div class="cal-day-task-meta">${meta.join(' · ')}</div>` : ''}
            </div>
            ${tagDots}
        </div>`;
    }).join('');

    grid.innerHTML = `<div class="cal-day-list">${rows}</div>`;
}

/**
 * Returns the bar markup for one task's interval in a week row - one spanning bar in span mode,
 * otherwise a chip per day.
 *
 * Clicking a projected repeat opens the task its series comes from.
 */
function renderBars(iv) {
    const t = iv.p.task;
    const colour = t.status_colour || '#6b7280';
    const done = Number(t.status_is_closed) === 1;
    const top = 28 + iv.lane * 24;
    const title = esc(t.title);
    // A subtask's hover card names its parent: on a month grid the title
    // alone ("Rack it") rarely says which piece of work it belongs to (#90).
    const isSub = !!t.parent_task_id;
    // A task that is one of a repeating series carries the same mark here as in
    // the detail panel (#94). One shape wherever it appears.
    const isRecur = !!t.recurrence_id;
    // A projection has no row behind it: it is drawn faintly and clicking it
    // opens the task the series comes from, because there is nothing else to open.
    const isProj = !!t.projected;

    const tagDots = (surfaceTags && t.tags && t.tags.length)
        ? t.tags.slice(0, 4).map(tg =>
            `<span class="mini-tag-dot" style="background:${esc(tg.colour || '#6b7280')}"></span>`).join('')
        : '';

    /**
     * Returns one bar or chip at a column and span, coloured by status and marked for done, subtask,
     * repeating and projected.
     */
    const make = (col, span, extraCls) => {
        const left = `calc(${col} / 7 * 100% + 3px)`;
        const width = `calc(${span} / 7 * 100% - 6px)`;
        return `<div class="cal-bar ${extraCls}${done ? ' cal-bar-done' : ''}${isSub ? ' cal-bar-subtask' : ''}${isRecur ? ' cal-bar-recur' : ''}${isProj ? ' cal-bar-projected' : ''}"
            style="left:${left};width:${width};top:${top}px;background:${colour}"
            data-hc="${TasksHoverCard.ref(t)}" onclick="openTask(${isProj ? t.source_task_id : t.id})">
            <span class="cal-bar-label">${isRecur ? CAL_ICON_RECUR : ''}${title}</span>${tagDots}</div>`;
    };

    if (spanMode === 'span') {
        let cls = 'cal-bar-span';
        if (iv.contLeft) cls += ' cont-l';
        if (iv.contRight) cls += ' cont-r';
        return make(iv.startCol, iv.endCol - iv.startCol + 1, cls);
    }
    // deadline / repeat — one chip per day in the interval
    let out = '';
    for (let c = iv.startCol; c <= iv.endCol; c++) out += make(c, 1, 'cal-bar-chip');
    return out;
}

/** Draws the legend of active statuses and their colours. */
function renderLegend() {
    const el = document.getElementById('calLegend');
    if (!el) return;
    el.innerHTML = statuses.filter(s => s.is_active).map(s =>
        `<div class="cal-legend-item">
            <span class="cal-legend-dot" style="background:${esc(s.colour || '#6b7280')}"></span>
            ${esc(s.name)}
        </div>`).join('');
}

// Bars and day-view rows open the shared quick-look panel
function openTask(id) { TasksQuickPanel.open(id); }

// ── Utilities ──────────────────────────────────────────────────────
// Monday-based column index (Mon=0 … Sun=6)
function mondayCol(d) { return (d.getDay() + 6) % 7; }

/** Formats a Date as a local YYYY-MM-DD. */
function ymd(d) {
    return d.getFullYear() + '-' +
        String(d.getMonth() + 1).padStart(2, '0') + '-' +
        String(d.getDate()).padStart(2, '0');
}

// Whole days from date string a to date string b (b assumed >= a)
function dayDiff(a, b) {
    const da = new Date(a + 'T00:00:00');
    const db = new Date(b + 'T00:00:00');
    return Math.round((db - da) / 86400000);
}

/** Formats a YYYY-MM-DD as a short day and month. */
function fmt(ds) {
    return fmtNaiveDayMonth(new Date(ds + 'T00:00:00'));
}

/** HTML-escapes a value through a text node; null and undefined become an empty string. */
function esc(text) {
    if (text == null) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
