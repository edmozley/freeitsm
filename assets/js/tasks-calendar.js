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
let tasks = [];
let statuses = [];
let priorities = [];
let analysts = [];
let teams = [];
let spanMode = 'deadline';
let surfaceTags = false;

// Locale for date formatting — matches the page's i18n locale
const UI_LOCALE = document.documentElement.lang || 'en';

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
});

async function loadSettings() {
    try {
        const d = await fetch(API_BASE + 'get_settings.php').then(r => r.json());
        if (d.success && d.settings.calendar_span_mode) spanMode = d.settings.calendar_span_mode;
        if (d.success && d.settings.tag_settings) surfaceTags = !!d.settings.tag_settings.surface_calendar;
    } catch (e) { console.error('Failed to load settings:', e); }
}

async function loadStatuses() {
    try {
        const d = await fetch(API_BASE + 'get_task_statuses.php').then(r => r.json());
        if (d.success) {
            statuses = d.statuses || [];
            renderLegend();
        }
    } catch (e) { console.error('Failed to load statuses:', e); }
}

async function loadPriorities() {
    try {
        const d = await fetch(API_BASE + 'get_task_priorities.php').then(r => r.json());
        if (d.success) priorities = (d.priorities || []).filter(p => p.is_active);
    } catch (e) { console.error('Failed to load priorities:', e); }
}

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

async function loadTasks() {
    let url = API_BASE + 'list.php?filter=' + currentFilter;
    if (currentFilter === 'team' && currentFilterTeamId) url += '&team_id=' + currentFilterTeamId;
    if (currentFilter === 'analyst' && currentFilterAnalystId) url += '&analyst_id=' + currentFilterAnalystId;
    try {
        const d = await fetch(url).then(r => r.json());
        if (d.success) {
            tasks = d.tasks;
            renderCalendar();
        }
    } catch (e) { console.error('Failed to load tasks:', e); }
}

// ── Filters ────────────────────────────────────────────────────────
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

function setTeamFilter(teamId) {
    if (!teamId) { setFilter('my'); return; }
    currentFilter = 'team';
    currentFilterTeamId = teamId;
    currentFilterAnalystId = null;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('analystFilter').value = '';
    loadTasks();
}

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
function calNext() {
    if (viewMode === 'month') viewDate.setMonth(viewDate.getMonth() + 1);
    else if (viewMode === 'week') viewDate.setDate(viewDate.getDate() + 7);
    else viewDate.setDate(viewDate.getDate() + 1);
    renderCalendar();
}
function calToday() {
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    viewDate = (viewMode === 'month')
        ? new Date(now.getFullYear(), now.getMonth(), 1)
        : now;
    renderCalendar();
}

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
function placedTasks() {
    const placed = [];
    tasks.forEach(t => {
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

function renderMonth() {
    const grid = document.getElementById('calGrid');
    document.getElementById('calWeekdays').style.display = '';
    grid.className = 'cal-grid';

    const year = viewDate.getFullYear();
    const month = viewDate.getMonth();

    document.getElementById('calTitle').textContent =
        viewDate.toLocaleDateString(UI_LOCALE, { month: 'long', year: 'numeric' });

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
    const startLabel = weekStart.toLocaleDateString(UI_LOCALE,
        sameMonth ? { day: 'numeric' } : (sameYear ? { day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: 'numeric' }));
    const endLabel = weekEnd.toLocaleDateString(UI_LOCALE,
        { day: 'numeric', month: 'short', year: 'numeric' });
    document.getElementById('calTitle').textContent = `${startLabel} – ${endLabel}`;

    const placed = placedTasks();
    const todayStr = ymd(new Date());
    const row = renderWeekRow(weekStart, todayStr, placed, null);

    grid.innerHTML = `<div class="cal-week">
        <div class="cal-week-days">${row.cells}</div>
        <div class="cal-week-bars">${row.bars}</div>
    </div>`;
}

function renderDay() {
    const grid = document.getElementById('calGrid');
    document.getElementById('calWeekdays').style.display = 'none';
    grid.className = '';

    document.getElementById('calTitle').textContent =
        viewDate.toLocaleDateString(UI_LOCALE,
            { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

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
        return `<div class="cal-day-task${done ? ' cal-day-task-done' : ''}" onclick="openTask(${t.id})">
            <span class="cal-day-task-dot" style="background:${esc(colour)}"></span>
            <div class="cal-day-task-body">
                <div class="cal-day-task-title">${esc(t.title)}</div>
                ${meta.length ? `<div class="cal-day-task-meta">${meta.join(' · ')}</div>` : ''}
            </div>
            ${tagDots}
        </div>`;
    }).join('');

    grid.innerHTML = `<div class="cal-day-list">${rows}</div>`;
}

function renderBars(iv) {
    const t = iv.p.task;
    const colour = t.status_colour || '#6b7280';
    const done = Number(t.status_is_closed) === 1;
    const top = 28 + iv.lane * 24;
    const title = esc(t.title);
    const tip = esc(t.title + (t.analyst_name ? ' · ' + t.analyst_name : '') +
        ' · ' + (iv.p.start !== iv.p.end ? fmt(iv.p.start) + ' → ' + fmt(iv.p.end) : fmt(iv.p.end)));

    const tagDots = (surfaceTags && t.tags && t.tags.length)
        ? t.tags.slice(0, 4).map(tg =>
            `<span class="mini-tag-dot" style="background:${esc(tg.colour || '#6b7280')}"></span>`).join('')
        : '';

    const make = (col, span, extraCls) => {
        const left = `calc(${col} / 7 * 100% + 3px)`;
        const width = `calc(${span} / 7 * 100% - 6px)`;
        return `<div class="cal-bar ${extraCls}${done ? ' cal-bar-done' : ''}"
            style="left:${left};width:${width};top:${top}px;background:${colour}"
            title="${tip}" onclick="openTask(${t.id})">
            <span class="cal-bar-label">${title}</span>${tagDots}</div>`;
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

function fmt(ds) {
    return new Date(ds + 'T00:00:00').toLocaleDateString(UI_LOCALE,
        { day: 'numeric', month: 'short' });
}

function esc(text) {
    if (text == null) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}
