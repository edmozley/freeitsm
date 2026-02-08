/**
 * ITSM Calendar Module JavaScript
 * Handles calendar views, event CRUD, and category filtering
 */

// State
let currentView = 'month';
let currentDate = new Date();
let events = [];
let categories = [];
let selectedCategories = new Set();
let currentEventId = null;
let draggedEventId = null;

// Day names
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadCategories();
    renderCalendar();
});

// Load categories from API
async function loadCategories() {
    try {
        const response = await fetch(API_BASE + 'get_categories.php?active_only=1');
        const data = await response.json();
        if (data.success) {
            categories = data.categories;
            // By default, all categories are selected
            selectedCategories = new Set(categories.map(c => c.id));
            renderCategoryFilters();
        }
    } catch (error) {
        console.error('Error loading categories:', error);
    }
}

// Render category filter checkboxes
function renderCategoryFilters() {
    const container = document.getElementById('categoryFilterList');
    if (categories.length === 0) {
        container.innerHTML = '<div class="no-categories">No categories found</div>';
        return;
    }

    container.innerHTML = categories.map(cat => `
        <label class="category-filter-item">
            <input type="checkbox" ${selectedCategories.has(cat.id) ? 'checked' : ''}
                   onchange="toggleCategory(${cat.id})">
            <span class="category-color-dot" style="background-color: ${cat.color}"></span>
            <span class="category-filter-name">${escapeHtml(cat.name)}</span>
        </label>
    `).join('');
}

// Toggle category filter
function toggleCategory(categoryId) {
    if (selectedCategories.has(categoryId)) {
        selectedCategories.delete(categoryId);
    } else {
        selectedCategories.add(categoryId);
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
    await loadEvents();

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

// Load events from API for current date range
async function loadEvents() {
    const range = getDateRange();
    const categoryParam = selectedCategories.size > 0 ?
        `&categories=${Array.from(selectedCategories).join(',')}` : '';

    try {
        const response = await fetch(
            `${API_BASE}get_events.php?start=${range.start}&end=${range.end}${categoryParam}&_t=${Date.now()}`
        );
        const data = await response.json();
        if (data.success) {
            events = data.events;
        }
    } catch (error) {
        console.error('Error loading events:', error);
    }
}

// Get date range for current view
function getDateRange() {
    let start, end;

    if (currentView === 'month') {
        start = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
        start.setDate(start.getDate() - start.getDay()); // Start from Sunday of first week
        end = new Date(start);
        end.setDate(end.getDate() + 42); // 6 weeks = 42 days to cover the full grid
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
    const lastDay = new Date(year, month + 1, 0);
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
    for (let i = 0; i < 42; i++) { // 6 weeks max
        const isOtherMonth = current.getMonth() !== month;
        const isToday = current.getTime() === today.getTime();
        const dateStr = formatDateForCompare(current);
        const dayEvents = getEventsForDate(dateStr);

        let classes = 'month-day';
        if (isOtherMonth) classes += ' other-month';
        if (isToday) classes += ' today';

        html += `<div class="${classes}" data-date="${dateStr}" onclick="openEventModal(null, '${dateStr}')"
                      ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop('${dateStr}', event)">`;
        html += `<div class="day-number">${current.getDate()}</div>`;
        html += '<div class="day-events">';

        const maxDisplay = 3;
        dayEvents.slice(0, maxDisplay).forEach(event => {
            const color = event.category_color || '#ef6c00';
            html += `<div class="event-pill" style="background-color: ${color}" draggable="true"
                         ondragstart="handleDragStart(${event.id}, event)" ondragend="handleDragEnd(event)"
                         onclick="event.stopPropagation(); showEventPopup(${event.id}, event)">
                         ${escapeHtml(event.title)}</div>`;
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
            html += `<div class="week-time-slot" onclick="openEventModal(null, '${dateStr}', ${hour})"></div>`;
        }

        // Add events
        const dayEvents = getEventsForDate(dateStr);
        dayEvents.forEach(event => {
            if (!event.all_day) {
                const startHour = getEventHour(event.start_datetime);
                const endHour = event.end_datetime ? getEventHour(event.end_datetime) : startHour + 1;
                const top = startHour * 60;
                const height = Math.max((endHour - startHour) * 60, 30);
                const color = event.category_color || '#ef6c00';

                html += `<div class="week-event" style="top: ${top}px; height: ${height}px; background-color: ${color};"
                             onclick="event.stopPropagation(); showEventPopup(${event.id}, event)">
                             <div class="week-event-title">${escapeHtml(event.title)}</div>
                             <div class="week-event-time">${formatEventTime(event)}</div>
                         </div>`;
            }
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
    const allDayEvents = dayEvents.filter(e => e.all_day);
    const timedEvents = dayEvents.filter(e => !e.all_day);

    let html = '<div class="day-grid">';

    // Header
    html += '<div class="day-header"><div class="day-header-info">';
    html += `<div class="day-header-date">${currentDate.getDate()}</div>`;
    html += `<div class="day-header-weekday">${DAYS[currentDate.getDay()]}, ${MONTHS[currentDate.getMonth()]} ${currentDate.getFullYear()}</div>`;
    html += '</div></div>';

    // All-day events
    if (allDayEvents.length > 0) {
        html += '<div class="all-day-section"><span class="all-day-label">All day:</span>';
        allDayEvents.forEach(event => {
            const color = event.category_color || '#ef6c00';
            html += `<div class="all-day-event" style="background-color: ${color};"
                         onclick="showEventPopup(${event.id}, event)">
                         ${escapeHtml(event.title)}</div>`;
        });
        html += '</div>';
    }

    // Time slots
    html += '<div class="day-body"><div class="day-time-column">';
    for (let hour = 0; hour < 24; hour++) {
        const label = hour === 0 ? '12 AM' : hour < 12 ? `${hour} AM` : hour === 12 ? '12 PM' : `${hour - 12} PM`;
        html += `<div class="week-time-slot-label">${label}</div>`;
    }
    html += '</div><div class="day-events-column">';

    for (let hour = 0; hour < 24; hour++) {
        html += `<div class="day-time-slot" onclick="openEventModal(null, '${dateStr}', ${hour})"></div>`;
    }

    // Timed events
    timedEvents.forEach(event => {
        const startHour = getEventHour(event.start_datetime);
        const endHour = event.end_datetime ? getEventHour(event.end_datetime) : startHour + 1;
        const top = startHour * 60;
        const height = Math.max((endHour - startHour) * 60, 60);
        const color = event.category_color || '#ef6c00';

        html += `<div class="day-event" style="top: ${top}px; height: ${height}px; background-color: ${color};"
                     onclick="event.stopPropagation(); showEventPopup(${event.id}, event)">
                     <div class="day-event-title">${escapeHtml(event.title)}</div>
                     <div class="day-event-time">${formatEventTime(event)}</div>
                     ${event.location ? `<div class="day-event-location">${escapeHtml(event.location)}</div>` : ''}
                 </div>`;
    });

    html += '</div></div></div>';

    container.innerHTML = html;
}

// Get events for a specific date
function getEventsForDate(dateStr) {
    return events.filter(event => {
        const eventStart = event.start_datetime.slice(0, 10);
        const eventEnd = event.end_datetime ? event.end_datetime.slice(0, 10) : eventStart;
        return dateStr >= eventStart && dateStr <= eventEnd;
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
function formatEventTime(event) {
    const start = new Date(event.start_datetime.replace(' ', 'T'));
    const end = event.end_datetime ? new Date(event.end_datetime.replace(' ', 'T')) : null;

    const formatTime = (d) => {
        let hours = d.getHours();
        const minutes = d.getMinutes();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12;
        return minutes ? `${hours}:${minutes.toString().padStart(2, '0')} ${ampm}` : `${hours} ${ampm}`;
    };

    if (event.all_day) return 'All day';
    if (end && end.getTime() !== start.getTime()) {
        return `${formatTime(start)} - ${formatTime(end)}`;
    }
    return formatTime(start);
}

// Open event modal
function openEventModal(eventId = null, dateStr = null, hour = null) {
    currentEventId = eventId;
    const modal = document.getElementById('eventModal');
    const title = document.getElementById('eventModalTitle');
    const deleteBtn = document.getElementById('deleteEventBtn');

    // Reset form
    document.getElementById('eventId').value = '';
    document.getElementById('eventTitle').value = '';
    document.getElementById('eventCategory').value = '';
    document.getElementById('eventStartDate').value = '';
    document.getElementById('eventStartTime').value = '';
    document.getElementById('eventEndDate').value = '';
    document.getElementById('eventEndTime').value = '';
    document.getElementById('eventAllDay').checked = false;
    document.getElementById('eventLocation').value = '';
    document.getElementById('eventDescription').value = '';

    // Populate category dropdown
    const categorySelect = document.getElementById('eventCategory');
    categorySelect.innerHTML = '<option value="">-- Select category --</option>' +
        categories.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');

    if (eventId) {
        // Edit existing event
        title.textContent = 'Edit Event';
        deleteBtn.style.display = 'block';
        loadEventForEdit(eventId);
    } else {
        // New event
        title.textContent = 'New Event';
        deleteBtn.style.display = 'none';

        if (dateStr) {
            document.getElementById('eventStartDate').value = dateStr;
            document.getElementById('eventEndDate').value = dateStr;
            if (hour !== null) {
                document.getElementById('eventStartTime').value = `${hour.toString().padStart(2, '0')}:00`;
                document.getElementById('eventEndTime').value = `${(hour + 1).toString().padStart(2, '0')}:00`;
            }
        } else {
            const today = formatDateForCompare(new Date());
            document.getElementById('eventStartDate').value = today;
            document.getElementById('eventEndDate').value = today;
        }
    }

    toggleAllDay();
    modal.classList.add('active');
}

// Load event data for editing
async function loadEventForEdit(eventId) {
    try {
        const response = await fetch(`${API_BASE}get_event.php?id=${eventId}`);
        const data = await response.json();
        if (data.success) {
            const event = data.event;
            document.getElementById('eventId').value = event.id;
            document.getElementById('eventTitle').value = event.title;
            document.getElementById('eventCategory').value = event.category_id || '';
            document.getElementById('eventAllDay').checked = event.all_day;
            document.getElementById('eventLocation').value = event.location || '';
            document.getElementById('eventDescription').value = event.description || '';

            // Parse dates
            const start = new Date(event.start_datetime.replace(' ', 'T'));
            document.getElementById('eventStartDate').value = formatDateForCompare(start);
            document.getElementById('eventStartTime').value =
                `${start.getHours().toString().padStart(2, '0')}:${start.getMinutes().toString().padStart(2, '0')}`;

            if (event.end_datetime) {
                const end = new Date(event.end_datetime.replace(' ', 'T'));
                document.getElementById('eventEndDate').value = formatDateForCompare(end);
                document.getElementById('eventEndTime').value =
                    `${end.getHours().toString().padStart(2, '0')}:${end.getMinutes().toString().padStart(2, '0')}`;
            }

            toggleAllDay();
        }
    } catch (error) {
        console.error('Error loading event:', error);
    }
}

// Toggle all-day checkbox
function toggleAllDay() {
    const allDay = document.getElementById('eventAllDay').checked;
    document.getElementById('startTimeGroup').style.display = allDay ? 'none' : 'block';
    document.getElementById('endTimeGroup').style.display = allDay ? 'none' : 'block';
}

// Close event modal
function closeEventModal() {
    document.getElementById('eventModal').classList.remove('active');
    currentEventId = null;
}

// Save event
async function saveEvent() {
    const id = document.getElementById('eventId').value;
    const title = document.getElementById('eventTitle').value.trim();
    const categoryId = document.getElementById('eventCategory').value;
    const allDay = document.getElementById('eventAllDay').checked;
    const startDate = document.getElementById('eventStartDate').value;
    const startTime = document.getElementById('eventStartTime').value;
    const endDate = document.getElementById('eventEndDate').value;
    const endTime = document.getElementById('eventEndTime').value;
    const location = document.getElementById('eventLocation').value.trim();
    const description = document.getElementById('eventDescription').value.trim();

    if (!title) {
        alert('Please enter an event title');
        return;
    }
    if (!startDate) {
        alert('Please enter a start date');
        return;
    }

    // Build datetime strings
    let startDatetime, endDatetime;
    if (allDay) {
        startDatetime = `${startDate} 00:00:00`;
        endDatetime = endDate ? `${endDate} 23:59:59` : `${startDate} 23:59:59`;
    } else {
        startDatetime = `${startDate} ${startTime || '00:00'}:00`;
        endDatetime = endDate && endTime ? `${endDate} ${endTime}:00` : null;
    }

    const payload = {
        id: id || null,
        title,
        category_id: categoryId || null,
        start_datetime: startDatetime,
        end_datetime: endDatetime,
        all_day: allDay,
        location,
        description
    };

    try {
        const response = await fetch(API_BASE + 'save_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (data.success) {
            closeEventModal();
            renderCalendar();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        console.error('Error saving event:', error);
        alert('Error saving event');
    }
}

// Delete event
async function deleteEvent() {
    const id = document.getElementById('eventId').value;
    if (!id) return;

    if (!confirm('Are you sure you want to delete this event?')) return;

    try {
        const response = await fetch(API_BASE + 'delete_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const data = await response.json();
        if (data.success) {
            closeEventModal();
            closeEventPopup();
            renderCalendar();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        console.error('Error deleting event:', error);
        alert('Error deleting event');
    }
}

// Show event popup (quick view)
function showEventPopup(eventId, clickEvent) {
    clickEvent.stopPropagation();
    const event = events.find(e => e.id == eventId);
    if (!event) return;

    const popup = document.getElementById('eventPopup');
    const categoryEl = document.getElementById('popupCategory');
    const titleEl = document.getElementById('popupTitle');
    const timeEl = document.getElementById('popupTime');
    const locationEl = document.getElementById('popupLocation');
    const descriptionEl = document.getElementById('popupDescription');

    categoryEl.textContent = event.category_name || 'Uncategorized';
    categoryEl.style.backgroundColor = event.category_color || '#ef6c00';
    titleEl.textContent = event.title;
    timeEl.textContent = formatEventTime(event);
    locationEl.textContent = event.location || '';
    locationEl.style.display = event.location ? 'block' : 'none';
    descriptionEl.textContent = event.description || '';
    descriptionEl.style.display = event.description ? 'block' : 'none';

    // Position popup near click
    const rect = clickEvent.target.getBoundingClientRect();
    popup.style.top = `${rect.bottom + 10}px`;
    popup.style.left = `${Math.min(rect.left, window.innerWidth - 320)}px`;

    currentEventId = eventId;
    popup.classList.add('active');
}

// Close event popup
function closeEventPopup() {
    document.getElementById('eventPopup').classList.remove('active');
}

// Edit event from popup
function editEventFromPopup() {
    closeEventPopup();
    openEventModal(currentEventId);
}

// Close popup when clicking outside
document.addEventListener('click', function(e) {
    const popup = document.getElementById('eventPopup');
    if (popup.classList.contains('active') && !popup.contains(e.target)) {
        closeEventPopup();
    }
});

// Drag and drop handlers for month view
function handleDragStart(eventId, e) {
    draggedEventId = eventId;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', eventId.toString());
    e.target.classList.add('dragging');
}

function handleDragEnd(e) {
    e.target.classList.remove('dragging');
    document.querySelectorAll('.month-day.drag-over').forEach(el => {
        el.classList.remove('drag-over');
    });
    draggedEventId = null;
}

function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const dayCell = e.target.closest('.month-day');
    if (dayCell && !dayCell.classList.contains('drag-over')) {
        document.querySelectorAll('.month-day.drag-over').forEach(el => {
            el.classList.remove('drag-over');
        });
        dayCell.classList.add('drag-over');
    }
}

function handleDragLeave(e) {
    const dayCell = e.target.closest('.month-day');
    const relatedCell = e.relatedTarget ? e.relatedTarget.closest('.month-day') : null;
    if (dayCell && dayCell !== relatedCell) {
        dayCell.classList.remove('drag-over');
    }
}

async function handleDrop(newDateStr, e) {
    e.preventDefault();
    e.stopPropagation();

    document.querySelectorAll('.month-day.drag-over').forEach(el => {
        el.classList.remove('drag-over');
    });

    const eventId = draggedEventId || parseInt(e.dataTransfer.getData('text/plain'), 10);
    if (!eventId) return;

    const evt = events.find(ev => ev.id == eventId);
    if (!evt) return;

    // Parse old start date
    const oldStartDate = evt.start_datetime.slice(0, 10);

    // If dropped on the same date, do nothing
    if (oldStartDate === newDateStr) {
        draggedEventId = null;
        return;
    }

    // Calculate day difference
    const oldDate = new Date(oldStartDate + 'T00:00:00');
    const newDate = new Date(newDateStr + 'T00:00:00');
    const diffDays = Math.round((newDate - oldDate) / (1000 * 60 * 60 * 24));

    // Shift start datetime
    const oldStart = new Date(evt.start_datetime.replace(' ', 'T'));
    oldStart.setDate(oldStart.getDate() + diffDays);

    // Shift end datetime
    let newEnd = null;
    if (evt.end_datetime) {
        const oldEnd = new Date(evt.end_datetime.replace(' ', 'T'));
        oldEnd.setDate(oldEnd.getDate() + diffDays);
        newEnd = formatLocalDatetime(oldEnd);
    }

    const payload = {
        id: evt.id,
        title: evt.title,
        category_id: evt.category_id || null,
        start_datetime: formatLocalDatetime(oldStart),
        end_datetime: newEnd,
        all_day: evt.all_day,
        location: evt.location || '',
        description: evt.description || ''
    };

    try {
        const response = await fetch(API_BASE + 'save_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (data.success) {
            await renderCalendar();
        } else {
            alert('Error moving event: ' + data.error);
        }
    } catch (error) {
        console.error('Error moving event:', error);
        alert('Error moving event');
    }

    draggedEventId = null;
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
