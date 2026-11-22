/**
 * Tickets Calendar JavaScript
 * Month / Week / Day views, matching the standalone Calendar module's UX.
 */

// API base path - can be overridden by page before loading this script
const API_BASE = window.API_BASE || 'api/';

// Dates and times render through the shared formatters in assets/js/tz.js
// (fmtNaive* here — scheduled work is a naive wall-clock value, shown as typed
// for every analyst). Month and weekday names follow the interface language;
// the arrangement follows the analyst's chosen format. This file used to derive
// a locale from <html lang> and hand it to Intl, which tied how a date LOOKS to
// which language it is IN — see GH #105.

// Translation lookup with a graceful fallback when the i18n.js bridge isn't loaded.
function tr(key, params) {
    return (typeof window.t === 'function') ? window.t(key, params) : key;
}

// State
let currentView = 'month';
let currentDate = new Date();
let scheduledTickets = [];

// Whose scheduled work is on screen: 'mine' or 'all'. Server-rendered from the
// analyst's saved preference (see tickets/calendar.php) so the first paint is
// already correct rather than flipping a moment after load.
let currentScope = (window.CALENDAR_SCOPE === 'all') ? 'all' : 'mine';

/**
 * A stable colour for an owner.
 *
 * Keyed on the analyst ID, not the name, so it survives a rename and two people
 * called J. Smith never collide. The palette is fixed rather than generated from
 * a hash of the whole colour space: random hues land on unreadable colours and
 * on near-neighbours that look the same, and these ten were picked to stay
 * legible on both the light and dark surfaces.
 *
 * Unassigned is deliberately grey and outside the palette — "nobody" is a real
 * answer here (a ticket can be scheduled with no owner) and it should not look
 * like just another person.
 */
const OWNER_COLOURS = ['#2b88d8', '#107c41', '#8764b8', '#c239b3', '#d83b01',
                       '#00838f', '#7a7574', '#498205', '#a4262c', '#8e562e'];
const OWNER_COLOUR_UNASSIGNED = '#9aa0a6';

// owner_id -> palette colour, rebuilt from the tickets on screen each load.
let ownerColourMap = new Map();

/**
 * Assign colours to the owners actually on screen.
 *
 * 🔴 NOT `id % palette.length`, which is what this was first. Analyst ids 1 and
 * 41 both landed on the same green, so two people were drawn identically while
 * the legend confidently showed them as separate entries — the exact thing the
 * colours exist to prevent. Any two ids ten apart collided, which on a real
 * install is not a rare accident.
 *
 * Assigning by POSITION in the set on screen guarantees distinct colours for up
 * to ten owners. The trade is that somebody's colour can change when the set
 * changes — acceptable because the legend is on screen naming everyone, so it is
 * always readable; an ambiguous colour is not. Sorted by id so the assignment is
 * deterministic for a given set rather than depending on load order.
 */
function assignOwnerColours(tickets) {
    const ids = [...new Set(tickets.map(t => t.owner_id).filter(Boolean))]
        .map(Number)
        .sort((a, b) => a - b);
    ownerColourMap = new Map(ids.map((id, i) => [id, OWNER_COLOURS[i % OWNER_COLOURS.length]]));
}

/**
 * The colour for a ticket owner on the calendar - the unassigned grey for nobody, the
 * colour assigned to them on screen, or a fixed fallback from the palette.
 */
function ownerColour(ownerId) {
    if (!ownerId) return OWNER_COLOUR_UNASSIGNED;   // "nobody" is a real answer
    const id = Number(ownerId);
    // The fallback only reaches an owner who is not on screen, so it cannot
    // collide with anything being drawn.
    return ownerColourMap.get(id) || OWNER_COLOURS[Math.abs(id) % OWNER_COLOURS.length];
}

// Priority owns the event's background in Mine (blue / red / green, from the CSS
// classes). These are the same three colours, needed in JS so the roles can swap.
const PRIORITY_COLOURS = { High: '#d32f2f', Low: '#43a047' };
const PRIORITY_NONE = 'rgba(255,255,255,0.35)';

/**
 * How an event is coloured, which depends on which question the screen is
 * answering.
 *
 * In **Mine** every ticket is yours, so the only useful signal is priority, and
 * it keeps the background it has always had.
 *
 * In **Everyone** the question is "whose is that?" — so the OWNER takes the
 * background and priority moves to the left edge. The roles swap rather than the
 * owner being added as a stripe alongside: a 4px sliver against a full-colour
 * pill is invisible at real density, which is only obvious once the calendar has
 * fifty tickets on it rather than four. Both signals survive; the dominant one
 * is the one the mode is for.
 *
 * Normal priority still gets an edge, just a neutral one, so the pills stay
 * aligned instead of some having a 4px gutter and others none.
 */
function eventColourStyle(ticket) {
    if (currentScope !== 'all') return '';
    const edge = PRIORITY_COLOURS[ticket.priority] || PRIORITY_NONE;
    return `background: ${ownerColour(ticket.owner_id)}; border-left: 4px solid ${edge};`;
}

/**
 * The hover tooltip for an event.
 *
 * In "Everyone" it names the owner, because a colour alone makes you look away
 * to the legend to answer "whose is that?" — and a month cell shows a stripe
 * to a colour, and the owner colour is a fill you still have to match to a legend.
 * In "Mine" the answer is always you, so it stays the subject.
 */
function calendarTicketTitle(ticket) {
    const subject = String(ticket.subject || '');
    if (currentScope !== 'all') return escapeHtml(subject);
    const owner = ticket.owner_name || tr('tickets.calendar.unassigned');
    return escapeHtml(`${owner} — ${subject}`);
}

/**
 * Repaint the legend from the tickets on screen.
 *
 * Built from what was actually loaded rather than from the analyst list: the
 * colours then explain themselves, nobody who has nothing scheduled clutters it,
 * and there is no second source to fall out of step with the events.
 */
function renderOwnerLegend() {
    const el = document.getElementById('ownerLegend');
    if (!el) return;
    if (currentScope !== 'all' || !scheduledTickets.length) {
        el.style.display = 'none';
        el.innerHTML = '';
        return;
    }
    const seen = new Map();
    scheduledTickets.forEach(t => {
        const key = t.owner_id || 0;
        if (!seen.has(key)) seen.set(key, t.owner_name || tr('tickets.calendar.unassigned'));
    });
    const items = [...seen.entries()].sort((a, b) => String(a[1]).localeCompare(String(b[1])));
    el.innerHTML = items.map(([id, name]) =>
        `<span class="owner-legend-item">
            <span class="owner-legend-swatch" style="background:${ownerColour(id)}"></span>
            ${escapeHtml(name)}
         </span>`).join('');
    el.style.display = '';
}

/** Switch between my scheduled work and everyone's, and remember the choice. */
function setScope(scope) {
    if (scope !== 'all') scope = 'mine';
    if (scope === currentScope) return;
    currentScope = scope;
    document.querySelectorAll('.scope-toggle .view-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.scope === scope);
    });
    // Saved per analyst, fire-and-forget: failing to remember the choice must not
    // stop the calendar reloading with it applied now.
    fetch('../api/system/set_user_preference.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: 'tickets_calendar_scope', value: scope })
    }).catch(() => {});
    renderCalendar();
}

// Day order: Monday-first to match UK conventions and the legacy tickets
// calendar behaviour. Index 0 = Monday, index 6 = Sunday.
const WEEKDAY_KEYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
const MONTH_KEYS = ['january', 'february', 'march', 'april', 'may', 'june',
                    'july', 'august', 'september', 'october', 'november', 'december'];

/** The short name of a weekday (Monday = 0) in the analyst's locale. */
function shortWeekdayLabel(weekdayIndex) {
    // Render short labels via Intl to respect locale; weekdayIndex is Monday=0..Sunday=6.
    // Build a reference date for that weekday and format it short.
    const refDayOfWeek = (weekdayIndex + 1) % 7; // Convert to Sun=0..Sat=6
    const ref = new Date(2024, 0, 7 + refDayOfWeek); // 7 Jan 2024 = Sunday
    return fmtNaiveWeekday(ref, true);
}

/** The translated name of a month (January = 0). */
function monthLabel(monthIndex) {
    return tr('common.calendar.months.' + MONTH_KEYS[monthIndex]);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    renderCalendar();
    startCalendarAutoRefresh();
});

// Switch view
function setView(view) {
    currentView = view;
    // ⚠️ Scoped to the VIEW toggle. A bare '.view-btn' also matches the Mine /
    // Everyone buttons beside it, whose dataset.view is undefined — so every view
    // change would quietly clear the scope selection and leave neither lit.
    document.querySelectorAll('.view-toggle:not(.scope-toggle) .view-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.view === view);
    });
    renderCalendar();
}

// Navigate to today
function goToToday() {
    currentDate = new Date();
    renderCalendar();
}

// Navigate to previous period (depends on current view)
function navigatePrev() {
    if (currentView === 'month') {
        currentDate.setMonth(currentDate.getMonth() - 1);
    } else if (currentView === 'week') {
        currentDate.setDate(currentDate.getDate() - 7);
    } else {
        currentDate.setDate(currentDate.getDate() - 1);
    }
    renderCalendar();
}

// Navigate to next period (depends on current view)
function navigateNext() {
    if (currentView === 'month') {
        currentDate.setMonth(currentDate.getMonth() + 1);
    } else if (currentView === 'week') {
        currentDate.setDate(currentDate.getDate() + 7);
    } else {
        currentDate.setDate(currentDate.getDate() + 1);
    }
    renderCalendar();
}

// Legacy month nav (kept for backwards compatibility with anything still calling it)
function changeMonth(delta) {
    currentDate.setMonth(currentDate.getMonth() + delta);
    renderCalendar();
}

// Render the calendar for the current view
async function renderCalendar() {
    updateTitle();
    await loadScheduledTicketsForRange();

    // Before anything paints: the event stripes and the legend must agree, so
    // the assignment happens once, here, rather than inside either of them.
    assignOwnerColours(scheduledTickets);
    renderOwnerLegend();
    const grid = document.getElementById('calendarGrid');
    if (currentView === 'month') {
        renderMonthView(grid);
    } else if (currentView === 'week') {
        renderWeekView(grid);
    } else {
        renderDayView(grid);
    }
}

// Update the calendar title
function updateTitle() {
    const titleEl = document.getElementById('calendarTitle');
    if (currentView === 'month') {
        titleEl.textContent = `${monthLabel(currentDate.getMonth())} ${currentDate.getFullYear()}`;
    } else if (currentView === 'week') {
        const weekStart = getWeekStart(currentDate);
        const weekEnd = new Date(weekStart);
        weekEnd.setDate(weekEnd.getDate() + 6);
        if (weekStart.getMonth() === weekEnd.getMonth()) {
            titleEl.textContent = `${monthLabel(weekStart.getMonth())} ${weekStart.getDate()} – ${weekEnd.getDate()}, ${weekStart.getFullYear()}`;
        } else {
            titleEl.textContent = `${monthLabel(weekStart.getMonth())} ${weekStart.getDate()} – ${monthLabel(weekEnd.getMonth())} ${weekEnd.getDate()}, ${weekEnd.getFullYear()}`;
        }
    } else {
        titleEl.textContent = `${monthLabel(currentDate.getMonth())} ${currentDate.getDate()}, ${currentDate.getFullYear()}`;
    }
}

// Compute the date range needed for the current view and load tickets
async function loadScheduledTicketsForRange() {
    let start, end;

    if (currentView === 'month') {
        // Start from the Monday on or before the 1st, span 6 weeks
        const first = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
        start = getWeekStart(first);
        end = new Date(start);
        end.setDate(end.getDate() + 42);
    } else if (currentView === 'week') {
        start = getWeekStart(currentDate);
        end = new Date(start);
        end.setDate(end.getDate() + 7);
    } else {
        start = new Date(currentDate);
        start.setHours(0, 0, 0, 0);
        end = new Date(start);
        end.setDate(end.getDate() + 1);
    }

    const startStr = formatDateForCompare(start);
    const endStr = formatDateForCompare(end);

    try {
        const response = await fetch(`${API_BASE}get_scheduled_tickets.php?start=${startStr}&end=${endStr}&scope=${currentScope}&_t=${Date.now()}`);
        const data = await response.json();
        if (data.success) {
            scheduledTickets = data.tickets.map(t => ({
                ...t,
                date: t.work_start_datetime.split('T')[0],
                // An all-day ticket is stored as 00:00–23:59 so that anything
                // ignoring the flag still gets a sane block, but showing "12:00 AM"
                // against it would be the flag being ignored HERE.
                time: t.work_all_day
                    ? tr('tickets.calendar.all_day')
                    : fmtNaiveTime(t.work_start_datetime)
            }));
        } else {
            console.error('Error loading tickets:', data.error);
            scheduledTickets = [];
        }
    } catch (error) {
        console.error('Error:', error);
        scheduledTickets = [];
    }
}

// Get start of week as Monday (Monday-first week)
/**
 * How tall a scheduled ticket draws, in pixels — the grid is one minute per pixel.
 *
 * The server always sends `duration_minutes`, resolving the default for a ticket
 * scheduled before end times existed, so there is nothing to guess here. The
 * clamps are about the GRID, not the data:
 *   - a minimum, or a 15-minute ticket renders as an unclickable hairline;
 *   - a cap at midnight, because a block running past the end of the day would
 *     otherwise overflow its column rather than stop at it.
 */
function scheduledBlockHeight(ticket, topMinutes) {
    // An all-day ticket is stored 00:00–23:59 so that anything ignoring the flag
    // still sees a sensible block — but drawn literally that is a 1440px column
    // that swallows the whole day and buries every timed ticket beside it. It is
    // "this day, no particular time", so it gets a slim bar at the top, the way
    // every calendar people already use shows one.
    if (ticket.work_all_day) return ALL_DAY_BAR_PX;
    const minutes = parseInt(ticket.duration_minutes, 10) || 60;
    return Math.max(20, Math.min(minutes, 1440 - topMinutes));
}

/** Height of the all-day bar, and the offset timed events start below it. */
const ALL_DAY_BAR_PX = 22;

/**
 * Where a ticket starts, in minutes-as-pixels down the day column.
 *
 * All-day pins to the very top. Timed events keep their real position, except
 * anything in the first few minutes of the day, which is nudged clear of the
 * all-day bar rather than being hidden underneath it.
 */
function scheduledBlockTop(ticket, startHour, startMinutes, dayHasAllDay) {
    if (ticket.work_all_day) return 0;
    const top = startHour * 60 + startMinutes;
    return dayHasAllDay ? Math.max(top, ALL_DAY_BAR_PX) : top;
}

/** Midnight on the Monday of the week a date falls in. */
function getWeekStart(date) {
    const d = new Date(date);
    const dayOfWeek = d.getDay(); // 0 = Sunday, 1 = Monday, …, 6 = Saturday
    const offsetToMonday = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
    d.setDate(d.getDate() + offsetToMonday);
    d.setHours(0, 0, 0, 0);
    return d;
}

// Format date as YYYY-MM-DD using local time
function formatDateForCompare(date) {
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

// Render month view
function renderMonthView(container) {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const firstDay = new Date(year, month, 1);
    const startDate = getWeekStart(firstDay);

    let html = '<div class="month-grid">';

    // Header row
    html += '<div class="month-header">';
    for (let i = 0; i < 7; i++) {
        html += `<div class="month-header-cell">${shortWeekdayLabel(i)}</div>`;
    }
    html += '</div>';

    // Days
    html += '<div class="month-body">';
    const current = new Date(startDate);
    for (let i = 0; i < 42; i++) {
        const isOtherMonth = current.getMonth() !== month;
        const isToday = current.getTime() === today.getTime();
        const dayOfWeek = current.getDay();
        const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;
        const dateStr = formatDateForCompare(current);
        const dayTickets = scheduledTickets.filter(t => t.date === dateStr);

        let classes = 'month-day';
        if (isOtherMonth) classes += ' other-month';
        if (isToday) classes += ' today';
        if (isWeekend) classes += ' weekend';

        html += `<div class="${classes}" ondragover="allowDrop(event, this)" ondrop="onDayDrop(event, '${dateStr}')">`;
        html += `<div class="day-number">${current.getDate()}</div>`;
        html += '<div class="day-tickets">';

        const maxDisplay = 3;
        dayTickets.slice(0, maxDisplay).forEach(ticket => {
            let priorityClass = '';
            if (ticket.priority === 'High') priorityClass = ' priority-high';
            else if (ticket.priority === 'Low') priorityClass = ' priority-low';

            html += `<div class="calendar-ticket${priorityClass}" style="${eventColourStyle(ticket)}" draggable="true" ondragstart="onEventDragStart(event, ${ticket.id})" ondragend="onEventDragEnd(event)" onclick="showTicketDetail(${ticket.id})" title="${calendarTicketTitle(ticket)}">
                        <span class="ticket-time">${ticket.time}</span>
                        ${escapeHtml(ticket.ticket_number)}
                     </div>`;
        });

        if (dayTickets.length > maxDisplay) {
            const moreCount = dayTickets.length - maxDisplay;
            html += `<div class="more-tickets" onclick="event.stopPropagation(); setView('day'); currentDate = new Date('${dateStr}T00:00:00'); renderCalendar();">${escapeHtml(tr('tickets.calendar.x_more', { count: moreCount }))}</div>`;
        }

        html += '</div></div>';
        current.setDate(current.getDate() + 1);
    }
    html += '</div></div>';

    container.innerHTML = html;
}

// Render week view (Mon–Sun across the top, 24-hour day on the side)
function renderWeekView(container) {
    const weekStart = getWeekStart(currentDate);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    let html = '<div class="week-grid">';

    // Header row (sticky)
    html += '<div class="week-header"><div class="week-header-time"></div><div class="week-header-days">';
    for (let i = 0; i < 7; i++) {
        const day = new Date(weekStart);
        day.setDate(day.getDate() + i);
        const isToday = day.getTime() === today.getTime();
        const isWeekend = i >= 5;
        html += `<div class="week-header-day${isToday ? ' today' : ''}${isWeekend ? ' weekend' : ''}">
                    <div class="week-day-name">${shortWeekdayLabel(i)}</div>
                    <div class="week-day-number">${day.getDate()}</div>
                 </div>`;
    }
    html += '</div></div>';

    // Body
    html += '<div class="week-body"><div class="week-time-column">';
    for (let hour = 0; hour < 24; hour++) {
        html += `<div class="week-time-slot-label">${formatHourLabel(hour)}</div>`;
    }
    html += '</div><div class="week-days-container">';

    for (let i = 0; i < 7; i++) {
        const day = new Date(weekStart);
        day.setDate(day.getDate() + i);
        const isToday = day.getTime() === today.getTime();
        const isWeekend = i >= 5;
        const dateStr = formatDateForCompare(day);

        html += `<div class="week-day-column${isToday ? ' today' : ''}${isWeekend ? ' weekend' : ''}" ondragover="allowDrop(event, this)" ondrop="onSlotDrop(event, '${dateStr}', this)">`;
        for (let hour = 0; hour < 24; hour++) {
            html += `<div class="week-time-slot"></div>`;
        }

        // Tickets in this day, each drawn at its real start and duration.
        const dayTickets = scheduledTickets.filter(t => t.date === dateStr);
        const dayHasAllDay = dayTickets.some(t => t.work_all_day);
        dayTickets.forEach(ticket => {
            const dt = new Date(ticket.work_start_datetime);
            const startHour = dt.getHours();
            const startMinutes = dt.getMinutes();
            const top = scheduledBlockTop(ticket, startHour, startMinutes, dayHasAllDay);
            const height = scheduledBlockHeight(ticket, top);

            let priorityClass = '';
            if (ticket.priority === 'High') priorityClass = ' priority-high';
            else if (ticket.priority === 'Low') priorityClass = ' priority-low';

            html += `<div class="week-event${priorityClass}${ticket.work_all_day ? ' is-allday' : ''}" style="top: ${top}px; height: ${height}px; ${eventColourStyle(ticket)}" title="${calendarTicketTitle(ticket)}" draggable="true" ondragstart="onEventDragStart(event, ${ticket.id})" ondragend="onEventDragEnd(event)"
                          onclick="showTicketDetail(${ticket.id})" title="${escapeHtml(ticket.subject)}">
                          <div class="week-event-title">${escapeHtml(ticket.ticket_number)}</div>
                          <div class="week-event-time">${ticket.time}</div>
                     </div>`;
        });

        html += '</div>';
    }
    html += '</div></div></div>';

    container.innerHTML = html;
}

// Render day view (single column, 24-hour day)
function renderDayView(container) {
    const viewDate = new Date(currentDate);
    viewDate.setHours(0, 0, 0, 0);
    const dateStr = formatDateForCompare(viewDate);
    const dayTickets = scheduledTickets.filter(t => t.date === dateStr);

    let html = '<div class="day-grid">';

    // Header
    html += '<div class="day-header"><div class="day-header-info">';
    html += `<div class="day-header-date">${currentDate.getDate()}</div>`;
    html += `<div class="day-header-weekday">${fmtNaiveWeekday(currentDate) + ' ' + fmtNaiveMonthYear(currentDate)}</div>`;
    html += '</div></div>';

    // Body
    html += '<div class="day-body"><div class="day-time-column">';
    for (let hour = 0; hour < 24; hour++) {
        html += `<div class="week-time-slot-label">${formatHourLabel(hour)}</div>`;
    }
    html += `</div><div class="day-events-column" ondragover="allowDrop(event, this)" ondrop="onSlotDrop(event, '${dateStr}', this)">`;

    for (let hour = 0; hour < 24; hour++) {
        html += `<div class="day-time-slot"></div>`;
    }

    const dayHasAllDay = dayTickets.some(t => t.work_all_day);
    dayTickets.forEach(ticket => {
        const dt = new Date(ticket.work_start_datetime);
        const startHour = dt.getHours();
        const startMinutes = dt.getMinutes();
        const top = scheduledBlockTop(ticket, startHour, startMinutes, dayHasAllDay);
        const height = scheduledBlockHeight(ticket, top);

        let priorityClass = '';
        if (ticket.priority === 'High') priorityClass = ' priority-high';
        else if (ticket.priority === 'Low') priorityClass = ' priority-low';

        html += `<div class="day-event${priorityClass}${ticket.work_all_day ? ' is-allday' : ''}" style="top: ${top}px; height: ${height}px; ${eventColourStyle(ticket)}" title="${calendarTicketTitle(ticket)}" draggable="true" ondragstart="onEventDragStart(event, ${ticket.id})" ondragend="onEventDragEnd(event)"
                      onclick="showTicketDetail(${ticket.id})">
                      <div class="day-event-title">${escapeHtml(ticket.ticket_number)} — ${escapeHtml(ticket.subject)}</div>
                      <div class="day-event-time">${ticket.time}</div>
                 </div>`;
    });

    html += '</div></div></div>';

    container.innerHTML = html;
}

/**
 * The hour label down the side of the day and week views - "14" or "2 PM", following the
 * analyst's chosen time format.
 */
function formatHourLabel(hour) {
    // The gutter label down the side of a day/week view: "14" on a 24-hour
    // clock, "2 PM" on a 12-hour one. Follows the analyst's chosen time format
    // rather than a locale convention — which is the point of GH #105, since
    // people in the same country disagree about this.
    const ref = new Date();
    ref.setHours(hour, 0, 0, 0);
    const twelveHour = String((window.DATE_FORMAT || {}).timeTemplate || '').indexOf('A') !== -1;
    return fmtNaiveTemplate(ref, twelveHour ? 'h A' : 'HH');
}


// ─── Drag to reschedule ─────────────────────────────────────────────────────
//
// Month view: drop on a day → keeps the time, changes the date.
// Week view:  drop on a day column → changes the day AND the time, from where
//             in the column it landed (the grid is one minute per pixel).
// Day view:   vertical only, so time alone.
//
// 🔑 THE DURATION IS PRESERVED, ALWAYS. Dragging says "start it then", never
// "make it a different length" — a two-hour job dropped at 9am is 9–11, not
// 9am-to-whatever-the-old-end-was.
//
// ⚠️ Rescheduling goes through the SAME endpoint the modal uses, so everything
// downstream — the audit, the workflow dispatch, the push into the owner's
// Outlook calendar — happens exactly as it does anywhere else. A drag is not a
// second way to write a schedule; it is a different way to say the same thing.

let dragTicketId = null;

/** The dragged ticket's length in minutes, so a move never resizes it. */
function draggedDurationMinutes(ticket) {
    const m = parseInt(ticket.duration_minutes, 10);
    return (m && m > 0) ? m : 60;
}

/** Starts dragging a scheduled ticket on the calendar, remembering which one it is. */
function onEventDragStart(e, ticketId) {
    dragTicketId = ticketId;
    // Firefox refuses to start a drag unless something is in the dataTransfer.
    try { e.dataTransfer.setData('text/plain', String(ticketId)); } catch (err) {}
    e.dataTransfer.effectAllowed = 'move';
    if (e.target.classList) e.target.classList.add('is-dragging');
}

/** Clears the dragging style and any highlighted drop slot when a drag ends. */
function onEventDragEnd(e) {
    if (e.target.classList) e.target.classList.remove('is-dragging');
    document.querySelectorAll('.drop-target').forEach(el => el.classList.remove('drop-target'));
}

/** Lets a dragged ticket be dropped on this slot and highlights it - only one slot at a time. */
function allowDrop(e, el) {
    if (dragTicketId === null) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (el && !el.classList.contains('drop-target')) {
        // Only ever one highlighted, or a fast drag leaves a trail of them.
        document.querySelectorAll('.drop-target').forEach(x => x.classList.remove('drop-target'));
        el.classList.add('drop-target');
    }
}

/**
 * Drop onto a whole day (month view). Keeps the clock time.
 *
 * An ALL-DAY ticket stays all-day: it is moved by date and its 00:00–23:59 is
 * rebuilt for the new day rather than carried across, which would otherwise
 * leave the end on the old date.
 */
function onDayDrop(e, dateStr) {
    e.preventDefault();
    const ticket = scheduledTickets.find(t => t.id === dragTicketId);
    dragTicketId = null;
    document.querySelectorAll('.drop-target').forEach(el => el.classList.remove('drop-target'));
    if (!ticket || ticket.date === dateStr) return;         // nothing to do

    if (ticket.work_all_day) {
        applyDrag(ticket, dateStr + ' 00:00:00', dateStr + ' 23:59:59', true);
        return;
    }
    const time = FreeITSMSchedule.parseNaive(ticket.work_start_datetime).time;
    commitDrag(ticket, dateStr, time);
}

/**
 * Drop into a day column at a pixel offset (week / day view).
 *
 * Snapped to 15 minutes: a calendar that books things at 09:07 because that is
 * where the mouse happened to be is worse than one that is slightly less
 * precise, and nobody schedules to the minute.
 */
function onSlotDrop(e, dateStr, columnEl) {
    e.preventDefault();
    const ticket = scheduledTickets.find(t => t.id === dragTicketId);
    dragTicketId = null;
    document.querySelectorAll('.drop-target').forEach(el => el.classList.remove('drop-target'));
    if (!ticket) return;

    if (ticket.work_all_day) {                    // all-day has no time to set
        if (ticket.date !== dateStr) applyDrag(ticket, dateStr + ' 00:00:00', dateStr + ' 23:59:59', true);
        return;
    }
    const rect = columnEl.getBoundingClientRect();
    let minutes = Math.round((e.clientY - rect.top + columnEl.scrollTop) / 15) * 15;
    minutes = Math.max(0, Math.min(minutes, 24 * 60 - 15));
    /** Pads a number to two digits. */
    const p = n => String(n).padStart(2, '0');
    commitDrag(ticket, dateStr, p(Math.floor(minutes / 60)) + ':' + p(minutes % 60));
}

/** Saves a ticket dropped at a date and time, keeping its duration. */
function commitDrag(ticket, dateStr, time) {
    const range = FreeITSMSchedule.toStoredRange(dateStr, time, draggedDurationMinutes(ticket), false);
    applyDrag(ticket, range.start, range.end, false);
}

/**
 * Move it on screen FIRST, then save.
 *
 * The save is not instant — it writes the ticket and then pushes the change to
 * the owner's Outlook calendar, which is a network call to Microsoft. Waiting
 * for that before the block moves would make every drag feel broken. So the
 * grid updates immediately and a failure puts it back, rather than the reverse.
 */
async function applyDrag(ticket, start, end, allDay) {
    const before = {
        start: ticket.work_start_datetime, end: ticket.work_end_datetime,
        allDay: ticket.work_all_day, date: ticket.date, time: ticket.time,
        duration: ticket.duration_minutes
    };

    ticket.work_start_datetime = start.replace(' ', 'T');
    ticket.work_end_datetime   = end.replace(' ', 'T');
    ticket.work_all_day        = allDay;
    ticket.date                = start.slice(0, 10);
    ticket.duration_minutes    = allDay ? 1440
        : Math.round((new Date(end.replace(' ', 'T')) - new Date(start.replace(' ', 'T'))) / 60000);
    ticket.time = allDay ? tr('tickets.calendar.all_day')
        : fmtNaiveTime(ticket.work_start_datetime);
    renderCurrentView();

    // ⚠️ HOLD OFF THE BACKGROUND REFRESH ACROSS THE WHOLE SAVE. dragTicketId is
    // already back to null by now — dragend fires the moment the mouse is
    // released, long before the server answers — so it cannot cover this window.
    // A refresh landing here would either overwrite the optimistic paint or, if
    // the save is refused, resurrect the state the rollback just undid.
    calendarRefreshBusy = true;
    try {
        const r = await fetch(`${API_BASE}schedule_ticket.php`, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: ticket.id, work_start_datetime: start,
                work_end_datetime: end, all_day: allDay ? 1 : 0
            })
        });
        const d = await r.json();
        if (!d.success) throw new Error(d.error || '');
        showToast(tr('tickets.calendar.moved', { ref: ticket.ticket_number }), 'success');
    } catch (err) {
        // Put it back exactly where it was. A block left sitting somewhere the
        // server rejected is a lie about what is scheduled.
        Object.assign(ticket, {
            work_start_datetime: before.start, work_end_datetime: before.end,
            work_all_day: before.allDay, date: before.date, time: before.time,
            duration_minutes: before.duration
        });
        renderCurrentView();
        showToast(tr('tickets.calendar.move_failed'), 'error');
    } finally {
        calendarRefreshBusy = false;
    }
}

/** Repaint the grid from local state, without re-fetching. */
function renderCurrentView() {
    const grid = document.getElementById('calendarGrid');
    if (currentView === 'month') renderMonthView(grid);
    else if (currentView === 'week') renderWeekView(grid);
    else renderDayView(grid);
}

// Show ticket detail modal
function showTicketDetail(ticketId) {
    const ticket = scheduledTickets.find(t => t.id === ticketId);
    if (!ticket) return;

    document.getElementById('ticketModalTitle').textContent = ticket.ticket_number;

    const body = document.getElementById('ticketModalBody');
    body.innerHTML = `
        <div class="ticket-detail-subject">${escapeHtml(ticket.subject)}</div>
        <div class="ticket-detail">
            <div class="ticket-detail-row">
                <div class="ticket-detail-label">${escapeHtml(tr('tickets.calendar.modal.scheduled'))}</div>
                <div class="ticket-detail-value">${escapeHtml(formatScheduleRange(ticket))}</div>
            </div>
            <div class="ticket-detail-row">
                <div class="ticket-detail-label">${escapeHtml(tr('tickets.calendar.modal.status'))}</div>
                <div class="ticket-detail-value">${ticket.status}</div>
            </div>
            <div class="ticket-detail-row">
                <div class="ticket-detail-label">${escapeHtml(tr('tickets.calendar.modal.priority'))}</div>
                <div class="ticket-detail-value">${ticket.priority}</div>
            </div>
            <div class="ticket-detail-row">
                <div class="ticket-detail-label">${escapeHtml(tr('tickets.calendar.modal.requester'))}</div>
                <div class="ticket-detail-value">${escapeHtml(ticket.requester_name || ticket.requester_email || tr('tickets.calendar.na'))}</div>
            </div>
            <div class="ticket-detail-row">
                <div class="ticket-detail-label">${escapeHtml(tr('tickets.calendar.modal.department'))}</div>
                <div class="ticket-detail-value">${escapeHtml(ticket.department_name || tr('tickets.calendar.unassigned'))}</div>
            </div>
            <div class="ticket-detail-row">
                <div class="ticket-detail-label">${escapeHtml(tr('tickets.calendar.modal.owner'))}</div>
                <div class="ticket-detail-value">${escapeHtml(ticket.owner_name || tr('tickets.calendar.unassigned'))}</div>
            </div>
        </div>
    `;

    const inboxUrl = window.INBOX_URL || 'inbox.php';
    document.getElementById('ticketModalLink').href = `${inboxUrl}?ticket_id=${ticket.id}`;

    // Load the reschedule fields from THIS ticket.
    schedTargetTicket = ticket;
    const start = FreeITSMSchedule.parseNaive(ticket.work_start_datetime);
    document.getElementById('calSchedDate').value = start ? start.date : '';
    document.getElementById('calSchedTime').value = start ? start.time : '09:00';
    document.getElementById('calSchedAllDay').checked = !!ticket.work_all_day;
    setCalScheduleDuration(
        FreeITSMSchedule.durationMinutes(ticket.work_start_datetime, ticket.work_end_datetime)
    );
    syncCalScheduleAllDay();

    document.getElementById('ticketModal').classList.add('active');
}

// Which ticket the open modal is editing.
let schedTargetTicket = null;

/** All day means no start time and no duration, so both fields go away. */
function syncCalScheduleAllDay() {
    const allDay = document.getElementById('calSchedAllDay').checked;
    document.getElementById('calSchedTimeRow').style.display = allDay ? 'none' : '';
}

/**
 * Put `minutes` in the duration list.
 *
 * A value the list cannot express — set through the REST API, or by an inbox
 * that offered a different set — gets an option of its own rather than being
 * snapped to the nearest. Snapping would silently rewrite somebody's duration
 * the moment they opened the ticket to change its date. Same rule as the inbox.
 */
function setCalScheduleDuration(minutes) {
    const sel = document.getElementById('calSchedDuration');
    if (!sel) return;
    const custom = sel.querySelector('option[data-custom]');
    if (custom) custom.remove();
    if (![...sel.options].some(o => parseInt(o.value, 10) === minutes)) {
        const opt = document.createElement('option');
        opt.value = String(minutes);
        opt.dataset.custom = '1';
        opt.textContent = tr('tickets.schedule_modal.dur_custom', { n: minutes });
        sel.appendChild(opt);
    }
    sel.value = String(minutes);
}

/** POST a schedule change for the open ticket and repaint. */
async function postSchedule(body, okKey) {
    try {
        const r = await fetch(`${API_BASE}schedule_ticket.php`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const d = await r.json();
        if (!d.success) {
            showToast(d.error || tr('tickets.calendar.save_failed'), 'error');
            return false;
        }
        closeTicketModal();
        showToast(tr(okKey), 'success');
        // Reload rather than patching the local copy: a change of date can move
        // the ticket out of the range on screen entirely, and a stale block left
        // behind where it used to be is worse than a moment's redraw.
        await renderCalendar();
        return true;
    } catch (e) {
        showToast(tr('tickets.calendar.save_failed'), 'error');
        return false;
    }
}

/**
 * Saves the date, time, duration and all-day choice from the calendar's schedule dialog
 * for the selected ticket.
 */
async function saveScheduleFromCalendar() {
    if (!schedTargetTicket) return;
    const date   = document.getElementById('calSchedDate').value;
    const time   = document.getElementById('calSchedTime').value;
    const allDay = document.getElementById('calSchedAllDay').checked;
    if (!date || (!allDay && !time)) {
        showToast(tr('tickets.calendar.need_date_time'), 'error');
        return;
    }
    const range = FreeITSMSchedule.toStoredRange(
        date, time, document.getElementById('calSchedDuration').value, allDay
    );
    await postSchedule({
        ticket_id: schedTargetTicket.id,
        work_start_datetime: range.start,
        work_end_datetime: range.end,
        all_day: range.allDay
    }, 'tickets.calendar.rescheduled');
}

/**
 * Asks for confirmation, then clears the selected ticket's scheduled time so it leaves
 * the calendar.
 */
async function unscheduleFromCalendar() {
    if (!schedTargetTicket) return;
    // Worth a confirm: the ticket leaves the calendar entirely and the times it
    // had are gone, so there is nothing on this screen left to undo it from.
    const ok = await showConfirm({
        title:    tr('tickets.schedule_modal.clear_schedule'),
        message:  tr('tickets.calendar.unschedule_confirm', { ref: schedTargetTicket.ticket_number }),
        okLabel:  tr('tickets.schedule_modal.clear_schedule'),
        okClass:  'danger'
    });
    if (!ok) return;
    await postSchedule({
        ticket_id: schedTargetTicket.id,
        work_start_datetime: null
    }, 'tickets.calendar.unscheduled');
}

// Close ticket modal
function closeTicketModal() {
    document.getElementById('ticketModal').classList.remove('active');
    // Refreshing was held off for as long as this was open, so catch up now
    // rather than leaving up to half a minute of staleness on screen.
    calendarAutoRefresh();
}

/* ────────────────────────────────────────────────────────────────────────────
 * Keeping the calendar current
 *
 * A shared calendar goes stale while you look at it. A colleague schedules
 * something, an analyst drags a job to Thursday, a ticket gets closed — and the
 * screen in front of you quietly stops being true.
 *
 * 🔑 THE WHOLE TRICK IS NOT REPAINTING. A poll that rebuilt the grid on every
 * tick would be worse than no poll at all: it would drop hover states, close
 * tooltips and flicker the whole month, six times a minute, almost always to
 * draw exactly what was already there. So the fetch happens on a timer, and the
 * REPAINT happens only when the data genuinely differs. In the ordinary case
 * nothing on screen is touched at all.
 *
 * That separation is also what makes the interval a free choice rather than a
 * trade-off: because the cost of a tick that finds nothing is a single small
 * query and no DOM work whatsoever, polling more often buys freshness without
 * buying flicker.
 *
 * ⚠️ AND IT MUST NEVER INTERRUPT. Four situations where a refresh would be felt
 * as a bug, each skipped rather than queued — the timer simply comes round
 * again, so nothing accumulates:
 *
 *   - A MODAL IS OPEN. Beyond the visible rudeness, showTicketDetail holds a
 *     reference INTO scheduledTickets, and refreshing replaces that array. The
 *     modal would go on editing an object no longer connected to the grid, and
 *     the save would look successful while changing nothing on screen.
 *   - A DRAG IS IN PROGRESS. Re-rendering mid-drag destroys the element being
 *     dragged, which ends the drag wherever the pointer happens to be.
 *   - A DRAG IS SAVING. applyDrag paints optimistically and rolls back if the
 *     server refuses; a refresh landing in that window would either overwrite
 *     the optimistic state or resurrect the rolled-back one.
 *   - THE TAB IS HIDDEN. Nobody is looking, so this is pure traffic — every open
 *     calendar tab in the building, all day.
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * Ten seconds, which is only affordable because of the two rules above: the
 * repaint is decoupled from the poll, so a shorter interval costs no extra
 * flicker whatsoever, and a hidden tab polls nothing at all. What is left is one
 * small date-scoped query per calendar somebody is actually looking at.
 *
 * A slow server cannot make this pile up — calendarRefreshBusy means a request
 * still in flight when the timer comes round is skipped rather than stacked, so
 * a 12-second response quietly degrades to "as often as it can" instead of
 * queueing requests behind each other.
 */
const CALENDAR_REFRESH_MS = 10000;
let calendarRefreshTimer = null;
let calendarRefreshBusy  = false;

/**
 * A fingerprint of what is currently drawn.
 *
 * Sorted, so the server returning the same tickets in a different order is not
 * mistaken for a change — that would repaint every time and defeat the point.
 * It covers exactly the fields the grid renders: change one of those and the
 * screen must be redrawn, change anything else and it need not be.
 */
function calendarSignature(list) {
    return (list || []).map(t => [
        t.id, t.work_start_datetime, t.work_end_datetime, t.work_all_day,
        t.duration_minutes, t.status, t.priority, t.owner_id, t.subject,
        t.ticket_number
    ].join('')).sort().join('');
}

/** Why a refresh is being skipped, or null if it may go ahead. */
function calendarRefreshBlocked() {
    if (document.hidden)          return 'hidden';
    if (calendarRefreshBusy)      return 'busy';
    if (dragTicketId !== null)    return 'dragging';
    if (document.querySelector('.modal.active')) return 'modal';
    return null;
}

/**
 * The background refresh: reloads the scheduled tickets for the range shown and redraws
 * only when something has changed. Skipped while the tab is hidden, a ticket is being
 * dragged, a dialog is open or another refresh is running; a failure is logged, not
 * shown.
 */
async function calendarAutoRefresh() {
    if (calendarRefreshBlocked()) return;

    calendarRefreshBusy = true;
    try {
        const before = calendarSignature(scheduledTickets);
        await loadScheduledTicketsForRange();
        if (calendarSignature(scheduledTickets) === before) return;

        // Something actually moved. Re-assign colours first: an owner appearing
        // or leaving changes the legend, and the stripes and the legend have to
        // agree or the colours mean nothing.
        assignOwnerColours(scheduledTickets);
        renderOwnerLegend();
        renderCurrentView();
    } catch (err) {
        // A failed background refresh is not worth a toast. The screen keeps
        // showing the last good data, which is the correct fallback, and the
        // next tick tries again.
        console.error('Calendar auto-refresh failed:', err);
    } finally {
        calendarRefreshBusy = false;
    }
}

/**
 * Starts the background refresh timer, and refreshes straight away whenever the tab
 * becomes visible again.
 */
function startCalendarAutoRefresh() {
    if (calendarRefreshTimer) clearInterval(calendarRefreshTimer);
    calendarRefreshTimer = setInterval(calendarAutoRefresh, CALENDAR_REFRESH_MS);

    // Coming back to the tab should show current data immediately rather than
    // whatever was true when you left, for up to another 10 seconds.
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) calendarAutoRefresh();
    });
}

// Utility functions
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/**
 * "Tue 25 Aug 2026, 14:00 – 15:00", or the date alone when it is an all-day
 * ticket — a time range on something explicitly marked all-day reads as a
 * contradiction, and 00:00–23:59 is storage detail, not information.
 */
function formatScheduleRange(ticket) {
    if (!ticket.work_start_datetime) return '';
    if (ticket.work_all_day) {
        // The DATE is rebuilt from the Date object, never carved out of the
        // formatted "date at time" string: splitting that on its first comma
        // turned "Tue, Sep 1, 2026 at 02:00 PM" into "Tue" and threw the date
        // away — and it would have failed differently in every locale.
        const d = fmtNaiveWeekday(ticket.work_start_datetime, true) + ' ' + fmtNaiveDate(ticket.work_start_datetime);
        return `${d} · ${tr('tickets.calendar.all_day')}`;
    }
    const full = formatDateTime(ticket.work_start_datetime);
    if (!ticket.work_end_datetime) return full;
    const end = fmtNaiveTime(ticket.work_end_datetime);
    return `${full} – ${end}`;
}

/** A date and time as "weekday date at time", in the analyst's formats. */
function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    const datePart = fmtNaiveWeekday(date, true) + ' ' + fmtNaiveDate(date);
    const timePart = fmtNaiveTime(date);
    return tr('tickets.calendar.date_at_time', { date: datePart, time: timePart });
}
