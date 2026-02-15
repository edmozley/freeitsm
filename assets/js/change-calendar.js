/**
 * Change Management Calendar JavaScript
 * Read-only calendar view of scheduled changes, filtered by status
 * Adapted from itsm_calendar.js
 */

// State
let currentView = 'month';
let currentDate = new Date();
let events = [];
let selectedStatuses = new Set();

// Status definitions with colors
const STATUSES = [
    { name: 'Draft',            color: '#9e9e9e' },
    { name: 'Pending Approval', color: '#e65100' },
    { name: 'Approved',         color: '#2e7d32' },
    { name: 'In Progress',      color: '#1565c0' },
    { name: 'Completed',        color: '#1b5e20' },
    { name: 'Failed',           color: '#c62828' },
    { name: 'Cancelled',        color: '#bdbdbd' }
];

// Day and month names
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadStatuses();
    renderCalendar();
});

// Load status filters (hardcoded, not from API)
function loadStatuses() {
    selectedStatuses = new Set(STATUSES.map(s => s.name));
    renderStatusFilters();
}

// Render status filter checkboxes
function renderStatusFilters() {
    const container = document.getElementById('statusFilterList');
    container.innerHTML = STATUSES.map(s => `
        <label class="category-filter-item">
            <input type="checkbox" ${selectedStatuses.has(s.name) ? 'checked' : ''}
                   onchange="toggleStatus('${s.name}')">
            <span class="category-color-dot" style="background-color: ${s.color}"></span>
            <span class="category-filter-name">${escapeHtml(s.name)}</span>
        </label>
    `).join('');
}

// Toggle status filter
function toggleStatus(statusName) {
    if (selectedStatuses.has(statusName)) {
        selectedStatuses.delete(statusName);
    } else {
        selectedStatuses.add(statusName);
    }
    renderCalendar();
}

// Set the current view
function setView(view) {
    currentView = view;
    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.view === view);
    });
    renderCalendar();
}

// Navigate to today
function goToToday() {
    currentDate = new Date();
    renderCalendar();
}

// Navigate to previous period
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

// Navigate to next period
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

// Render the calendar based on current view
async function renderCalendar() {
    updateTitle();
    await loadChanges();

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
        titleEl.textContent = `${MONTHS[currentDate.getMonth()]} ${currentDate.getFullYear()}`;
    } else if (currentView === 'week') {
        const weekStart = getWeekStart(currentDate);
        const weekEnd = new Date(weekStart);
        weekEnd.setDate(weekEnd.getDate() + 6);
        if (weekStart.getMonth() === weekEnd.getMonth()) {
            titleEl.textContent = `${MONTHS[weekStart.getMonth()]} ${weekStart.getDate()} - ${weekEnd.getDate()}, ${weekStart.getFullYear()}`;
        } else {
            titleEl.textContent = `${MONTHS[weekStart.getMonth()]} ${weekStart.getDate()} - ${MONTHS[weekEnd.getMonth()]} ${weekEnd.getDate()}, ${weekEnd.getFullYear()}`;
        }
    } else {
        titleEl.textContent = `${MONTHS[currentDate.getMonth()]} ${currentDate.getDate()}, ${currentDate.getFullYear()}`;
    }
}

// Load changes from API for current date range
async function loadChanges() {
    const range = getDateRange();
    const statusParam = selectedStatuses.size > 0 ?
        `&statuses=${encodeURIComponent(Array.from(selectedStatuses).join(','))}` : '';

    try {
        const response = await fetch(
            `${API_BASE}get_calendar_changes.php?start=${range.start}&end=${range.end}${statusParam}&_t=${Date.now()}`
        );
        const data = await response.json();
        if (data.success) {
            events = data.events;
        }
    } catch (error) {
        console.error('Error loading changes:', error);
    }
}

// Get date range for current view
function getDateRange() {
    let start, end;

    if (currentView === 'month') {
        start = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
        start.setDate(start.getDate() - start.getDay());
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

    return {
        start: formatDateForAPI(start),
        end: formatDateForAPI(end)
    };
}

// Get start of week (Sunday)
function getWeekStart(date) {
    const d = new Date(date);
    d.setDate(d.getDate() - d.getDay());
    d.setHours(0, 0, 0, 0);
    return d;
}

// Format date for API using local time
function formatDateForAPI(date) {
    return formatLocalDatetime(date);
}

// Render month view
function renderMonthView(container) {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const firstDay = new Date(year, month, 1);
    const startDate = new Date(firstDay);
    startDate.setDate(startDate.getDate() - firstDay.getDay());

    let html = '<div class="month-grid">';

    // Header row
    html += '<div class="month-header">';
    DAYS.forEach(day => {
        html += `<div class="month-header-cell">${day}</div>`;
    });
    html += '</div>';

    // Days
    html += '<div class="month-body">';
    const current = new Date(startDate);
    for (let i = 0; i < 42; i++) {
        const isOtherMonth = current.getMonth() !== month;
        const isToday = current.getTime() === today.getTime();
        const dateStr = formatDateForCompare(current);
        const dayEvents = getEventsForDate(dateStr);

        let classes = 'month-day';
        if (isOtherMonth) classes += ' other-month';
        if (isToday) classes += ' today';

        html += `<div class="${classes}" data-date="${dateStr}">`;
        html += `<div class="day-number">${current.getDate()}</div>`;
        html += '<div class="day-events">';

        const maxDisplay = 3;
        dayEvents.slice(0, maxDisplay).forEach(evt => {
            const color = evt.status_color || '#9e9e9e';
            html += `<div class="event-pill" style="background-color: ${color}"
                         onclick="event.stopPropagation(); showChangePopup(${evt.id}, event)">
                         ${escapeHtml(evt.title)}</div>`;
        });

        if (dayEvents.length > maxDisplay) {
            html += `<div class="more-events" onclick="event.stopPropagation(); setView('day'); currentDate = new Date('${dateStr}'); renderCalendar();">
                     +${dayEvents.length - maxDisplay} more</div>`;
        }

        html += '</div></div>';
        current.setDate(current.getDate() + 1);
    }
    html += '</div></div>';

    container.innerHTML = html;
}

// Render week view
function renderWeekView(container) {
    const weekStart = getWeekStart(currentDate);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    let html = '<div class="week-grid">';

    // Header
    html += '<div class="week-header"><div class="week-header-time"></div><div class="week-header-days">';
    for (let i = 0; i < 7; i++) {
        const day = new Date(weekStart);
        day.setDate(day.getDate() + i);
        const isToday = day.getTime() === today.getTime();
        html += `<div class="week-header-day ${isToday ? 'today' : ''}">
                    <div class="week-day-name">${DAYS[i]}</div>
                    <div class="week-day-number">${day.getDate()}</div>
                 </div>`;
    }
    html += '</div></div>';

    // Body
    html += '<div class="week-body"><div class="week-time-column">';
    for (let hour = 0; hour < 24; hour++) {
        const label = hour === 0 ? '12 AM' : hour < 12 ? `${hour} AM` : hour === 12 ? '12 PM' : `${hour - 12} PM`;
        html += `<div class="week-time-slot-label">${label}</div>`;
    }
    html += '</div><div class="week-days-container">';

    for (let i = 0; i < 7; i++) {
        const day = new Date(weekStart);
        day.setDate(day.getDate() + i);
        const isToday = day.getTime() === today.getTime();
        const dateStr = formatDateForCompare(day);

        html += `<div class="week-day-column ${isToday ? 'today' : ''}" data-date="${dateStr}">`;
        for (let hour = 0; hour < 24; hour++) {
            html += `<div class="week-time-slot"></div>`;
        }

        // Add events
        const dayEvents = getEventsForDate(dateStr);
        dayEvents.forEach(evt => {
            const startHour = getEventHour(evt.start_datetime);
            const endHour = evt.end_datetime ? getEventHour(evt.end_datetime) : startHour + 1;
            const top = startHour * 60;
            const height = Math.max((endHour - startHour) * 60, 30);
            const color = evt.status_color || '#9e9e9e';

            html += `<div class="week-event" style="top: ${top}px; height: ${height}px; background-color: ${color};"
                         onclick="event.stopPropagation(); showChangePopup(${evt.id}, event)">
                         <div class="week-event-title">${escapeHtml(evt.title)}</div>
                         <div class="week-event-time">${formatEventTime(evt)}</div>
                     </div>`;
        });

        html += '</div>';
    }
    html += '</div></div></div>';

    container.innerHTML = html;
}

// Render day view
function renderDayView(container) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const viewDate = new Date(currentDate);
    viewDate.setHours(0, 0, 0, 0);
    const dateStr = formatDateForCompare(viewDate);
    const dayEvents = getEventsForDate(dateStr);
    const timedEvents = dayEvents;

    let html = '<div class="day-grid">';

    // Header
    html += '<div class="day-header"><div class="day-header-info">';
    html += `<div class="day-header-date">${currentDate.getDate()}</div>`;
    html += `<div class="day-header-weekday">${DAYS[currentDate.getDay()]}, ${MONTHS[currentDate.getMonth()]} ${currentDate.getFullYear()}</div>`;
    html += '</div></div>';

    // Time slots
    html += '<div class="day-body"><div class="day-time-column">';
    for (let hour = 0; hour < 24; hour++) {
        const label = hour === 0 ? '12 AM' : hour < 12 ? `${hour} AM` : hour === 12 ? '12 PM' : `${hour - 12} PM`;
        html += `<div class="week-time-slot-label">${label}</div>`;
    }
    html += '</div><div class="day-events-column">';

    for (let hour = 0; hour < 24; hour++) {
        html += `<div class="day-time-slot"></div>`;
    }

    // Timed events
    timedEvents.forEach(evt => {
        const startHour = getEventHour(evt.start_datetime);
        const endHour = evt.end_datetime ? getEventHour(evt.end_datetime) : startHour + 1;
        const top = startHour * 60;
        const height = Math.max((endHour - startHour) * 60, 60);
        const color = evt.status_color || '#9e9e9e';

        html += `<div class="day-event" style="top: ${top}px; height: ${height}px; background-color: ${color};"
                     onclick="event.stopPropagation(); showChangePopup(${evt.id}, event)">
                     <div class="day-event-title">${escapeHtml(evt.title)}</div>
                     <div class="day-event-time">${formatEventTime(evt)}</div>
                     ${evt.assigned_to_name ? `<div class="day-event-location">${escapeHtml(evt.assigned_to_name)}</div>` : ''}
                 </div>`;
    });

    html += '</div></div></div>';

    container.innerHTML = html;
}

// Get events for a specific date
function getEventsForDate(dateStr) {
    return events.filter(evt => {
        const evtStart = evt.start_datetime.slice(0, 10);
        const evtEnd = evt.end_datetime ? evt.end_datetime.slice(0, 10) : evtStart;
        return dateStr >= evtStart && dateStr <= evtEnd;
    });
}

// Get hour from datetime string
function getEventHour(datetime) {
    const parts = datetime.split(' ')[1];
    if (!parts) return 0;
    return parseInt(parts.split(':')[0], 10);
}

// Format date for comparison (YYYY-MM-DD) using local time
function formatDateForCompare(date) {
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

// Format event time for display
function formatEventTime(evt) {
    const start = new Date(evt.start_datetime.replace(' ', 'T'));
    const end = evt.end_datetime ? new Date(evt.end_datetime.replace(' ', 'T')) : null;

    const formatTime = (d) => {
        let hours = d.getHours();
        const minutes = d.getMinutes();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12;
        return minutes ? `${hours}:${minutes.toString().padStart(2, '0')} ${ampm}` : `${hours} ${ampm}`;
    };

    if (end && end.getTime() !== start.getTime()) {
        return `${formatTime(start)} - ${formatTime(end)}`;
    }
    return formatTime(start);
}

// Format a datetime range for display in popup
function formatDatetimeRange(startStr, endStr) {
    if (!startStr) return '';
    const start = new Date(startStr.replace(' ', 'T'));
    const end = endStr ? new Date(endStr.replace(' ', 'T')) : null;

    const formatDt = (d) => {
        const month = MONTHS[d.getMonth()].slice(0, 3);
        const day = d.getDate();
        let hours = d.getHours();
        const minutes = d.getMinutes();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12 || 12;
        const time = minutes ? `${hours}:${minutes.toString().padStart(2, '0')} ${ampm}` : `${hours} ${ampm}`;
        return `${month} ${day}, ${time}`;
    };

    if (end) {
        return `${formatDt(start)} — ${formatDt(end)}`;
    }
    return formatDt(start);
}

// Show change detail popup
function showChangePopup(changeId, clickEvent) {
    clickEvent.stopPropagation();
    const change = events.find(e => e.id == changeId);
    if (!change) return;

    const popup = document.getElementById('changePopup');

    // Build popup content
    const typeBadgeClass = change.change_type ? change.change_type.toLowerCase().replace(' ', '-') : 'normal';
    const statusBadgeClass = change.status.toLowerCase().replace(' ', '-');

    let html = '<div class="change-popup-badges">';
    html += `<span class="change-type-badge ${typeBadgeClass}">${escapeHtml(change.change_type)}</span>`;
    html += `<span class="change-status-badge ${statusBadgeClass}">${escapeHtml(change.status)}</span>`;
    html += '</div>';
    html += `<h4 class="change-popup-title">${escapeHtml(change.title)}</h4>`;
    html += '<div class="change-popup-details">';

    // Work window
    html += `<div class="change-popup-row"><span class="change-popup-label">Work Window</span><span>${formatDatetimeRange(change.start_datetime, change.end_datetime)}</span></div>`;

    // Outage window (if set)
    if (change.outage_start_datetime) {
        html += `<div class="change-popup-row"><span class="change-popup-label">Outage Window</span><span>${formatDatetimeRange(change.outage_start_datetime, change.outage_end_datetime)}</span></div>`;
    }

    // Priority & Impact
    html += `<div class="change-popup-row"><span class="change-popup-label">Priority</span><span>${escapeHtml(change.priority)}</span></div>`;
    html += `<div class="change-popup-row"><span class="change-popup-label">Impact</span><span>${escapeHtml(change.impact)}</span></div>`;

    // Assigned to
    if (change.assigned_to_name) {
        html += `<div class="change-popup-row"><span class="change-popup-label">Assigned To</span><span>${escapeHtml(change.assigned_to_name)}</span></div>`;
    }

    html += '</div>';
    html += '<div class="change-popup-actions"><button class="btn btn-primary btn-sm" onclick="openChange(' + change.id + ')">Open Change</button></div>';

    document.getElementById('changePopupContent').innerHTML = html;

    // Position popup near click
    const rect = clickEvent.target.getBoundingClientRect();
    popup.style.top = `${rect.bottom + 10}px`;
    popup.style.left = `${Math.min(rect.left, window.innerWidth - 340)}px`;

    popup.classList.add('active');
}

// Close change popup
function closeChangePopup() {
    document.getElementById('changePopup').classList.remove('active');
}

// Close popup when clicking outside
document.addEventListener('click', function(e) {
    const popup = document.getElementById('changePopup');
    if (popup && popup.classList.contains('active') && !popup.contains(e.target)) {
        closeChangePopup();
    }
});

// Navigate to change in main view
function openChange(id) {
    window.location.href = '../change-management/?open=' + id;
}

// Format a Date object as local datetime string (YYYY-MM-DD HH:MM:SS)
function formatLocalDatetime(d) {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    const hh = String(d.getHours()).padStart(2, '0');
    const min = String(d.getMinutes()).padStart(2, '0');
    const ss = String(d.getSeconds()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
}

// Escape HTML for XSS prevention
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
