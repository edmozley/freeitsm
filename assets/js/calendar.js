/**
 * Calendar JavaScript
 */

// API base path - can be overridden by page before loading this script
const API_BASE = window.API_BASE || 'api/';

// Locale for Intl date/time formatting — sourced from <html lang> so it matches
// the user's selected interface language. Falls back to en-GB if the bridge
// hasn't run or the page didn't set <html lang>.
const PAGE_LOCALE = (document.documentElement.lang || 'en-GB');

// Translation lookup with a graceful fallback when the i18n.js bridge isn't loaded
// (e.g. an older page including calendar.js without exporting window.translations).
function tr(key, params) {
    return (typeof window.t === 'function') ? window.t(key, params) : key;
}

let currentDate = new Date();
let scheduledTickets = [];

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    renderCalendar();
});

// Render the calendar for the current month
async function renderCalendar() {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();

    // Update title — month names resolve via the JS i18n bridge
    const monthKeys = ['january', 'february', 'march', 'april', 'may', 'june',
                       'july', 'august', 'september', 'october', 'november', 'december'];
    const monthName = tr('common.calendar.months.' + monthKeys[month]);
    document.getElementById('calendarTitle').textContent = `${monthName} ${year}`;

    // Load scheduled tickets for this month
    await loadScheduledTickets(year, month + 1);

    // Get first day of month and total days
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const totalDays = lastDay.getDate();

    // Get day of week for first day (0 = Sunday, adjust for Monday start)
    let startDay = firstDay.getDay();
    startDay = startDay === 0 ? 6 : startDay - 1; // Convert to Monday = 0

    // Get today for highlighting
    const today = new Date();
    const isCurrentMonth = today.getFullYear() === year && today.getMonth() === month;

    // Build calendar grid
    const grid = document.getElementById('calendarGrid');
    let html = '';

    // Previous month days
    const prevMonth = new Date(year, month, 0);
    const prevMonthDays = prevMonth.getDate();
    for (let i = startDay - 1; i >= 0; i--) {
        const day = prevMonthDays - i;
        const dateStr = formatDateStr(year, month, day);
        html += renderDay(day, dateStr, true, false, false);
    }

    // Current month days
    for (let day = 1; day <= totalDays; day++) {
        const isToday = isCurrentMonth && day === today.getDate();
        const dateStr = formatDateStr(year, month + 1, day);
        const dayOfWeek = new Date(year, month, day).getDay();
        const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;
        html += renderDay(day, dateStr, false, isToday, isWeekend);
    }

    // Next month days (fill remaining cells to complete the grid)
    const totalCells = Math.ceil((startDay + totalDays) / 7) * 7;
    const remainingCells = totalCells - (startDay + totalDays);
    for (let day = 1; day <= remainingCells; day++) {
        const dateStr = formatDateStr(year, month + 2, day);
        html += renderDay(day, dateStr, true, false, false);
    }

    grid.innerHTML = html;
}

// Render a single day cell
function renderDay(day, dateStr, isOtherMonth, isToday, isWeekend) {
    let classes = 'calendar-day';
    if (isOtherMonth) classes += ' other-month';
    if (isToday) classes += ' today';
    if (isWeekend) classes += ' weekend';

    // Get tickets for this day
    const dayTickets = scheduledTickets.filter(t => t.date === dateStr);

    let ticketsHtml = '';
    const maxDisplay = 3;
    dayTickets.slice(0, maxDisplay).forEach(ticket => {
        let priorityClass = '';
        if (ticket.priority === 'High') priorityClass = ' priority-high';
        else if (ticket.priority === 'Low') priorityClass = ' priority-low';

        ticketsHtml += `
            <div class="calendar-ticket${priorityClass}" onclick="showTicketDetail(${ticket.id})" title="${escapeHtml(ticket.subject)}">
                <span class="ticket-time">${ticket.time}</span>
                ${escapeHtml(ticket.ticket_number)}
            </div>
        `;
    });

    if (dayTickets.length > maxDisplay) {
        const moreCount = dayTickets.length - maxDisplay;
        ticketsHtml += `<div class="more-tickets" onclick="showDayTickets('${dateStr}')">${escapeHtml(tr('tickets.calendar.x_more', { count: moreCount }))}</div>`;
    }

    return `
        <div class="${classes}">
            <div class="day-number">${day}</div>
            <div class="day-tickets">${ticketsHtml}</div>
        </div>
    `;
}

// Format date string for comparison
function formatDateStr(year, month, day) {
    // Handle month overflow
    const date = new Date(year, month - 1, day);
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

// Load scheduled tickets for a month
async function loadScheduledTickets(year, month) {
    try {
        const response = await fetch(`${API_BASE}get_scheduled_tickets.php?year=${year}&month=${month}`);
        const data = await response.json();

        if (data.success) {
            scheduledTickets = data.tickets.map(t => {
                const dt = new Date(t.work_start_datetime);
                return {
                    ...t,
                    date: t.work_start_datetime.split('T')[0],
                    time: dt.toLocaleTimeString(PAGE_LOCALE, { hour: '2-digit', minute: '2-digit' })
                };
            });
        } else {
            console.error('Error loading tickets:', data.error);
            scheduledTickets = [];
        }
    } catch (error) {
        console.error('Error:', error);
        scheduledTickets = [];
    }
}

// Change month
function changeMonth(delta) {
    currentDate.setMonth(currentDate.getMonth() + delta);
    renderCalendar();
}

// Go to today
function goToToday() {
    currentDate = new Date();
    renderCalendar();
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
                <div class="ticket-detail-value">${formatDateTime(ticket.work_start_datetime)}</div>
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

    // Set link to open in inbox - use INBOX_URL if set, otherwise default
    const inboxUrl = window.INBOX_URL || 'inbox.php';
    document.getElementById('ticketModalLink').href = `${inboxUrl}?ticket=${ticket.id}`;

    document.getElementById('ticketModal').classList.add('active');
}

// Close ticket modal
function closeTicketModal() {
    document.getElementById('ticketModal').classList.remove('active');
}

// Show all tickets for a day (future enhancement)
function showDayTickets(dateStr) {
    const dayTickets = scheduledTickets.filter(t => t.date === dateStr);
    if (dayTickets.length === 0) return;

    // For now, just show the first unshown ticket
    const ticket = dayTickets[3]; // 4th ticket (0-indexed)
    if (ticket) {
        showTicketDetail(ticket.id);
    }
}

// Utility functions
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    const datePart = date.toLocaleDateString(PAGE_LOCALE, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric'
    });
    const timePart = date.toLocaleTimeString(PAGE_LOCALE, { hour: '2-digit', minute: '2-digit' });
    return tr('tickets.calendar.date_at_time', { date: datePart, time: timePart });
}
