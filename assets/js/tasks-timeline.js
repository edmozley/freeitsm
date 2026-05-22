/**
 * FreeITSM Tasks — Timeline (Gantt) View
 *
 * Each parent task with at least one date is drawn as a horizontal bar from
 * its start_date to its due_date (a task with only a due_date is a single-day
 * bar). Rows are grouped by assignee, status, or shown flat.
 */

// ── Constants ──────────────────────────────────────────────────────
const LABEL_W = 220;
const ROW_H = 36;
const GROUP_H = 30;
const HEADER_H = 48;
const DAY_WIDTHS = [14, 22, 34, 52];
const STATUS_ORDER = ['To Do', 'In Progress', 'Blocked', 'Done', 'Cancelled'];

// Locale for date formatting — matches the page's i18n locale
const UI_LOCALE = document.documentElement.lang || 'en';

// ── State ──────────────────────────────────────────────────────────
let currentFilter = 'my';
let currentFilterTeamId = null;
let currentFilterAnalystId = null;
let tasks = [];
let groupBy = 'analyst';
let zoomIndex = 2;
let surfaceTags = false;

// ── Init ───────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
    await loadDropdowns();
    await loadSettings();
    loadTasks();
});

async function loadSettings() {
    try {
        const d = await fetch(API_BASE + 'get_settings.php').then(r => r.json());
        if (d.success && d.settings.tag_settings) {
            surfaceTags = !!d.settings.tag_settings.surface_calendar;
        }
    } catch (e) { console.error('Failed to load settings:', e); }
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
            render();
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

function setGroupBy(value) { groupBy = value; render(); }

function zoom(dir) {
    const next = zoomIndex + dir;
    if (next < 0 || next >= DAY_WIDTHS.length) return;
    zoomIndex = next;
    render();
}

// ── Rendering ──────────────────────────────────────────────────────
function render() {
    const host = document.getElementById('tlScroll');
    const dayW = DAY_WIDTHS[zoomIndex];

    // Resolve each task to a [start, end] range
    const items = [];
    tasks.forEach(t => {
        if (!t.due_date && !t.start_date) return;
        let s = t.start_date || t.due_date;
        let e = t.due_date || t.start_date;
        if (s > e) { const tmp = s; s = e; e = tmp; }
        items.push({ task: t, start: s, end: e });
    });

    if (items.length === 0) {
        host.innerHTML = `<div class="cal-loading">${esc(window.t('tasks.timeline.empty'))}</div>`;
        document.getElementById('tlRange').textContent = '';
        return;
    }

    // Date range — span the tasks plus today, padded a few days each side
    const todayStr = ymd(new Date());
    let minStr = todayStr, maxStr = todayStr;
    items.forEach(it => {
        if (it.start < minStr) minStr = it.start;
        if (it.end > maxStr) maxStr = it.end;
    });
    const rangeStart = addDays(minStr, -3);
    const rangeEnd = addDays(maxStr, 4);
    const rangeStartStr = ymd(rangeStart);
    const rangeDays = dayDiff(rangeStartStr, ymd(rangeEnd)) + 1;
    const trackW = rangeDays * dayW;
    const innerW = LABEL_W + trackW;

    document.getElementById('tlRange').textContent =
        fmt(rangeStartStr) + ' – ' + fmt(ymd(rangeEnd));

    // ── Header: month band + day band ──
    let monthCells = '';
    let m = -1, mStart = 0;
    for (let i = 0; i <= rangeDays; i++) {
        const d = i < rangeDays ? addDays(rangeStartStr, i) : null;
        const mm = d ? d.getFullYear() * 12 + d.getMonth() : -999;
        if (mm !== m) {
            if (m !== -1) {
                const cd = addDays(rangeStartStr, mStart);
                monthCells += `<div class="tl-month" style="width:${(i - mStart) * dayW}px">` +
                    cd.toLocaleDateString(UI_LOCALE, { month: 'long', year: 'numeric' }) + '</div>';
            }
            m = mm; mStart = i;
        }
    }
    let dayCells = '';
    for (let i = 0; i < rangeDays; i++) {
        const d = addDays(rangeStartStr, i);
        const ds = ymd(d);
        const cls = [
            'tl-day',
            (d.getDay() === 0 || d.getDay() === 6) ? 'tl-weekend' : '',
            ds === todayStr ? 'tl-day-today' : ''
        ].filter(Boolean).join(' ');
        dayCells += `<div class="${cls}" style="width:${dayW}px">${d.getDate()}</div>`;
    }

    // ── Body: grouped rows ──
    const groups = buildGroups(items);
    let body = '';
    groups.forEach(g => {
        body += `<div class="tl-group" style="width:${innerW}px">
            <div class="tl-group-label" style="width:${LABEL_W}px">${esc(g.name)}
                <span class="tl-group-count">${g.items.length}</span></div>
        </div>`;
        g.items.forEach(it => {
            const t = it.task;
            const colour = t.status_colour || '#6b7280';
            const done = Number(t.status_is_closed) === 1;
            const left = dayDiff(rangeStartStr, it.start) * dayW;
            const width = (dayDiff(it.start, it.end) + 1) * dayW;
            const dateText = it.start !== it.end
                ? fmt(it.start) + ' → ' + fmt(it.end) : fmt(it.end);
            const tip = esc(t.title + ' · ' + (t.status || '') + ' · ' + dateText);
            const tagDots = (surfaceTags && t.tags && t.tags.length)
                ? t.tags.slice(0, 5).map(tg =>
                    `<span class="mini-tag-dot" style="background:${esc(tg.colour || '#6b7280')}"></span>`).join('')
                : '';
            body += `<div class="tl-row" style="width:${innerW}px">
                <div class="tl-row-label" style="width:${LABEL_W}px" title="${esc(t.title)}"
                     onclick="openTask(${t.id})">
                    <span class="priority-dot ${(t.priority || 'medium').toLowerCase()}"></span>
                    <span class="tl-row-title">${esc(t.title)}</span>
                </div>
                <div class="tl-row-track" style="width:${trackW}px">
                    <div class="tl-bar${done ? ' tl-bar-done' : ''}"
                         style="left:${left}px;width:${width}px;background:${colour}"
                         title="${tip}" onclick="openTask(${t.id})">
                        <span class="tl-bar-label">${esc(t.title)}</span>${tagDots}
                    </div>
                </div>
            </div>`;
        });
    });

    // ── Today marker ──
    const todayLeft = LABEL_W + dayDiff(rangeStartStr, todayStr) * dayW;
    const todayMarker = `<div class="tl-today" style="left:${todayLeft}px;width:${dayW}px">
        <span class="tl-today-flag">${esc(window.t('tasks.timeline.today'))}</span></div>`;

    host.innerHTML = `<div class="tl-inner" style="width:${innerW}px">
        <div class="tl-head" style="height:${HEADER_H}px">
            <div class="tl-head-label" style="width:${LABEL_W}px">${esc(window.t('tasks.timeline.col_task'))}</div>
            <div class="tl-head-cols" style="width:${trackW}px">
                <div class="tl-months">${monthCells}</div>
                <div class="tl-days">${dayCells}</div>
            </div>
        </div>
        <div class="tl-body">${body}</div>
        ${todayMarker}
    </div>`;

    scrollToToday();
}

function buildGroups(items) {
    const map = new Map();
    const unassigned = window.t('tasks.timeline.unassigned');
    items.forEach(it => {
        let key;
        if (groupBy === 'analyst') key = it.task.analyst_name || unassigned;
        else if (groupBy === 'status') key = it.task.status || window.t('tasks.timeline.no_status');
        else key = window.t('tasks.timeline.all_tasks');
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(it);
    });
    const groups = [...map.entries()].map(([name, list]) => {
        list.sort((a, b) => a.start < b.start ? -1 : a.start > b.start ? 1 : 0);
        return { name, items: list };
    });
    groups.sort((a, b) => {
        if (groupBy === 'status') {
            const ia = STATUS_ORDER.indexOf(a.name), ib = STATUS_ORDER.indexOf(b.name);
            return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib) || a.name.localeCompare(b.name);
        }
        // assignee — push Unassigned to the bottom, otherwise alphabetical
        if (a.name === unassigned) return 1;
        if (b.name === unassigned) return -1;
        return a.name.localeCompare(b.name);
    });
    return groups;
}

function scrollToToday() {
    const host = document.getElementById('tlScroll');
    const marker = host.querySelector('.tl-today');
    if (!marker) return;
    const left = parseFloat(marker.style.left);
    host.scrollLeft = Math.max(0, left - host.clientWidth / 3);
}

function openTask(id) {
    window.location.href = '../index.php?task=' + id;
}

// ── Utilities ──────────────────────────────────────────────────────
function ymd(d) {
    return d.getFullYear() + '-' +
        String(d.getMonth() + 1).padStart(2, '0') + '-' +
        String(d.getDate()).padStart(2, '0');
}

function addDays(ds, n) {
    const d = new Date(ds + 'T00:00:00');
    d.setDate(d.getDate() + n);
    return d;
}

function dayDiff(a, b) {
    return Math.round((new Date(b + 'T00:00:00') - new Date(a + 'T00:00:00')) / 86400000);
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
