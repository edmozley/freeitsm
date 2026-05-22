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

let currentFilter = 'my';
let currentFilterTeamId = null;
let currentFilterAnalystId = null;
let tasks = [];
let statuses = [];
let spanMode = 'deadline';
let surfaceTags = false;

// Locale for date formatting — matches the page's i18n locale
const UI_LOCALE = document.documentElement.lang || 'en';

// ── Init ───────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
    await loadSettings();
    await Promise.all([loadDropdowns(), loadStatuses()]);
    loadTasks();
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

async function loadDropdowns() {
    try {
        const [aRes, tRes] = await Promise.all([
            fetch(API_BASE + 'list.php?analysts=1').then(r => r.json()),
            fetch(API_BASE + 'list.php?teams=1').then(r => r.json())
        ]);
        if (aRes.success) {
            document.getElementById('analystFilter').innerHTML =
                '<option value="">' + esc(window.t('tasks.filter.all_analysts')) + '</option>' +
                aRes.analysts.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
        }
        if (tRes.success) {
            document.getElementById('teamFilter').innerHTML =
                '<option value="">' + esc(window.t('tasks.filter.all_teams')) + '</option>' +
                tRes.teams.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
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

// ── Month navigation ───────────────────────────────────────────────
function calPrev() { viewDate.setMonth(viewDate.getMonth() - 1); renderCalendar(); }
function calNext() { viewDate.setMonth(viewDate.getMonth() + 1); renderCalendar(); }
function calToday() {
    const now = new Date();
    viewDate = new Date(now.getFullYear(), now.getMonth(), 1);
    renderCalendar();
}

// ── Rendering ──────────────────────────────────────────────────────
function renderCalendar() {
    const grid = document.getElementById('calGrid');
    const year = viewDate.getFullYear();
    const month = viewDate.getMonth();

    document.getElementById('calTitle').textContent =
        viewDate.toLocaleDateString(UI_LOCALE, { month: 'long', year: 'numeric' });
    document.getElementById('calModeHint').textContent =
        window.t('tasks.calendar.mode_hint', { mode: window.t('tasks.calendar.mode_' + spanMode) });

    // Grid runs from the Monday on/before the 1st to the Sunday on/after the last
    const first = new Date(year, month, 1);
    const gridStart = new Date(first);
    gridStart.setDate(first.getDate() - mondayCol(first));
    const last = new Date(year, month + 1, 0);
    const gridEnd = new Date(last);
    gridEnd.setDate(last.getDate() + (6 - mondayCol(last)));
    const weekCount = (Math.round((gridEnd - gridStart) / 86400000) + 1) / 7;

    // Resolve each task to the date range it occupies
    const placed = [];
    tasks.forEach(t => {
        if (!t.due_date) return;
        const end = t.due_date;
        let start = end;
        if (spanMode !== 'deadline' && t.start_date && t.start_date <= end) start = t.start_date;
        placed.push({ task: t, start, end });
    });

    const todayStr = ymd(new Date());
    let html = '';

    for (let w = 0; w < weekCount; w++) {
        const weekStart = new Date(gridStart);
        weekStart.setDate(gridStart.getDate() + w * 7);
        const weekStartStr = ymd(weekStart);
        const weekEnd = new Date(weekStart);
        weekEnd.setDate(weekStart.getDate() + 6);
        const weekEndStr = ymd(weekEnd);

        // Tasks intersecting this week → column intervals
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

        // Greedy lane allocation so bars never overlap
        intervals.sort((a, b) =>
            a.startCol - b.startCol || (b.endCol - b.startCol) - (a.endCol - a.startCol));
        const laneEnd = [];
        intervals.forEach(iv => {
            let lane = 0;
            while (lane < laneEnd.length && laneEnd[lane] >= iv.startCol) lane++;
            laneEnd[lane] = iv.endCol;
            iv.lane = lane;
        });

        // Day cells
        let cells = '';
        for (let c = 0; c < 7; c++) {
            const d = new Date(weekStart);
            d.setDate(weekStart.getDate() + c);
            const ds = ymd(d);
            const cls = [
                'cal-day',
                d.getMonth() === month ? '' : 'cal-out',
                ds === todayStr ? 'cal-today' : '',
                c >= 5 ? 'cal-weekend' : ''
            ].filter(Boolean).join(' ');
            cells += `<div class="${cls}"><span class="cal-daynum">${d.getDate()}</span></div>`;
        }

        // Bars
        let bars = '';
        intervals.forEach(iv => { bars += renderBars(iv); });

        const weekHeight = Math.max(96, 28 + laneEnd.length * 24 + 6);
        html += `<div class="cal-week" style="min-height:${weekHeight}px">
            <div class="cal-week-days">${cells}</div>
            <div class="cal-week-bars">${bars}</div>
        </div>`;
    }

    grid.innerHTML = html;
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

function openTask(id) {
    window.location.href = '../index.php?task=' + id;
}

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
