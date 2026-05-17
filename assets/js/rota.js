/**
 * Rota Page JS - Weekly staff rota management
 */

const ROTA_API = '../api/tickets/';
const SETTINGS_API = '../api/settings/';

// Locale sourced from <html lang> so all Intl.DateTimeFormat calls render
// dates / weekdays / months in the user's chosen interface language.
const PAGE_LOCALE = document.documentElement.lang || 'en-GB';
const WEEKDAY_SHORT_FMT = new Intl.DateTimeFormat(PAGE_LOCALE, { weekday: 'short' });
const MONTH_SHORT_FMT   = new Intl.DateTimeFormat(PAGE_LOCALE, { month: 'short' });
const DAY_NUM_FMT       = new Intl.DateTimeFormat(PAGE_LOCALE, { day: 'numeric' });
const MODAL_DATE_FMT    = new Intl.DateTimeFormat(PAGE_LOCALE, { weekday: 'long', day: 'numeric', month: 'short' });

let currentWeekStart = null; // YYYY-MM-DD (Monday)
let rotaAnalysts = [];
let rotaShifts = [];
let rotaEntries = [];
let rotaLocations = [];
let includeWeekends = false;

// ==================== Initialisation ====================

document.addEventListener('DOMContentLoaded', function() {
    // Start with the current week
    const today = new Date();
    currentWeekStart = getMonday(today);
    loadRota();
});

function getMonday(d) {
    const date = new Date(d);
    const day = date.getDay(); // 0=Sun 1=Mon...6=Sat
    const diff = day === 0 ? -6 : 1 - day;
    date.setDate(date.getDate() + diff);
    return formatDate(date);
}

function formatDate(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

// ==================== Week Navigation ====================

function changeWeek(delta) {
    const d = new Date(currentWeekStart + 'T00:00:00');
    d.setDate(d.getDate() + (delta * 7));
    currentWeekStart = formatDate(d);
    loadRota();
}

function goToThisWeek() {
    currentWeekStart = getMonday(new Date());
    loadRota();
}

// ==================== Data Loading ====================

async function loadRota() {
    try {
        const response = await fetch(ROTA_API + 'get_rota.php?week=' + currentWeekStart);
        const data = await response.json();

        if (data.success) {
            rotaAnalysts = data.analysts || [];
            rotaShifts = data.shifts || [];
            rotaEntries = data.entries || [];
            rotaLocations = data.locations || [];
            includeWeekends = data.include_weekends == 1;

            updateTitle(data.week_start, data.week_end);
            renderRotaGrid(data.week_start);
        } else {
            console.error('Error loading rota:', data.error);
        }
    } catch (error) {
        console.error('Error loading rota:', error);
    }
}

function updateTitle(weekStart, weekEnd) {
    const start = new Date(weekStart + 'T00:00:00');
    const end = new Date(weekEnd + 'T00:00:00');

    let endDate = includeWeekends ? end : new Date(start);
    if (!includeWeekends) {
        endDate.setDate(endDate.getDate() + 4); // Friday
    }

    // Format month / day labels through Intl so they come out in the right
    // language and short form for the locale automatically.
    const startMonth = MONTH_SHORT_FMT.format(start);
    const endMonth   = MONTH_SHORT_FMT.format(endDate);

    let label;
    if (start.getMonth() === endDate.getMonth()) {
        label = `${start.getDate()} – ${endDate.getDate()} ${startMonth} ${start.getFullYear()}`;
    } else {
        label = `${start.getDate()} ${startMonth} – ${endDate.getDate()} ${endMonth} ${start.getFullYear()}`;
    }

    document.getElementById('rotaTitle').textContent = label;
}

// ==================== Grid Rendering ====================

function renderRotaGrid(weekStart) {
    const grid = document.getElementById('rotaGrid');
    const numDays = includeWeekends ? 7 : 5;
    grid.className = 'rota-grid days-' + numDays;

    const today = formatDate(new Date());

    // Build day dates for the week. Weekday short names come from Intl so
    // they render natively for every locale (Mon / lun. / Mo / ਸੋਮ / etc.).
    const days = [];
    const startDate = new Date(weekStart + 'T00:00:00');
    for (let i = 0; i < numDays; i++) {
        const d = new Date(startDate);
        d.setDate(d.getDate() + i);
        days.push({
            date: formatDate(d),
            name: WEEKDAY_SHORT_FMT.format(d),
            dayNum: DAY_NUM_FMT.format(d),
            isToday: formatDate(d) === today
        });
    }

    // Build entries lookup: analyst_id -> date -> entry
    const entryMap = {};
    rotaEntries.forEach(e => {
        if (!entryMap[e.analyst_id]) entryMap[e.analyst_id] = {};
        entryMap[e.analyst_id][e.rota_date] = e;
    });

    const analystHeader  = escapeHtml(t('tickets.rota.analyst_col'));
    const onCallBadge    = escapeHtml(t('tickets.rota.on_call_badge'));
    const addEntryTitle  = escapeHtml(t('tickets.rota.add_entry'));

    let html = '';

    // Header row - corner cell + day headers
    html += `<div class="rota-col-header" style="text-align: left; padding-left: 12px;">${analystHeader}</div>`;
    days.forEach(day => {
        html += `<div class="rota-col-header${day.isToday ? ' today' : ''}">
            <span class="day-name">${escapeHtml(day.name)}</span>
            <span class="day-date">${escapeHtml(day.dayNum)}</span>
        </div>`;
    });

    // Analyst rows
    if (rotaAnalysts.length === 0) {
        html += `<div class="rota-empty" style="grid-column: 1 / -1;"><p>${escapeHtml(t('tickets.rota.no_analysts'))}</p></div>`;
    } else {
        rotaAnalysts.forEach(analyst => {
            // Analyst name cell
            html += `<div class="rota-analyst-name">${escapeHtml(analyst.full_name)}</div>`;

            // Day cells
            days.forEach(day => {
                const entry = entryMap[analyst.id] && entryMap[analyst.id][day.date];
                const todayClass = day.isToday ? ' today' : '';

                if (entry) {
                    const locStyle = entry.location_colour
                        ? `style="background:${entry.location_colour}; color:#fff;"`
                        : '';
                    const locLabel = escapeHtml(entry.location_name || '');
                    html += `<div class="rota-cell${todayClass}" onclick="openRotaEntryModal(${analyst.id}, '${day.date}', ${entry.id})">
                        <div class="rota-entry">
                            <div class="shift-name">${escapeHtml(entry.shift_name)}</div>
                            <div class="shift-times">${fmtTime(entry.start_time)} – ${fmtTime(entry.end_time)}</div>
                            <div class="badges">
                                ${locLabel ? `<span class="rota-badge" ${locStyle}>${locLabel}</span>` : ''}
                                ${entry.is_on_call == 1 ? `<span class="rota-badge on-call">${onCallBadge}</span>` : ''}
                            </div>
                        </div>
                    </div>`;
                } else {
                    html += `<div class="rota-cell${todayClass}" onclick="openRotaEntryModal(${analyst.id}, '${day.date}')">
                        <button class="rota-cell-add" title="${addEntryTitle}">+</button>
                    </div>`;
                }
            });
        });
    }

    grid.innerHTML = html;
}

function fmtTime(t) {
    if (!t) return '';
    return t.substring(0, 5);
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ==================== Entry Modal ====================

async function openRotaEntryModal(analystId, date, entryId) {
    // Find analyst name
    const analyst = rotaAnalysts.find(a => a.id == analystId);
    const analystName = analyst ? analyst.full_name : t('tickets.users.unknown_name');

    // Format date for display via Intl so weekday + month render natively
    // for the locale (e.g. fr "lundi 17 mai", de "Montag, 17. Mai").
    const d = new Date(date + 'T00:00:00');
    const dateLabel = MODAL_DATE_FMT.format(d);

    document.getElementById('entryContext').textContent = `${analystName} — ${dateLabel}`;
    document.getElementById('entryAnalystId').value = analystId;
    document.getElementById('entryDate').value = date;
    document.getElementById('entryId').value = '';

    // Populate shift dropdown
    const shiftSelect = document.getElementById('entryShift');
    const shiftPlaceholder = escapeHtml(t('tickets.rota.modal.shift_placeholder'));
    shiftSelect.innerHTML = `<option value="">${shiftPlaceholder}</option>` +
        rotaShifts.map(s => `<option value="${s.id}">${escapeHtml(s.name)} (${fmtTime(s.start_time)} – ${fmtTime(s.end_time)})</option>`).join('');

    // Render dynamic location radios driven by rota_locations lookup
    const locContainer = document.getElementById('entryLocationOptions');
    if (locContainer) {
        const defaultLoc = rotaLocations.find(l => l.is_default) || rotaLocations[0];
        const defaultId = defaultLoc ? defaultLoc.id : '';
        locContainer.innerHTML = rotaLocations.map(l => `
            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                <input type="radio" name="entryLocation" value="${l.id}" ${l.id == defaultId ? 'checked' : ''}>
                ${escapeHtml(l.name)}
            </label>
        `).join('');
    }

    document.getElementById('entryOnCall').checked = false;
    document.getElementById('entryDeleteBtn').style.display = 'none';
    document.getElementById('rotaEntryModalTitle').textContent = t('tickets.rota.modal.add_title');

    // If editing existing entry, populate values
    if (entryId) {
        const entry = rotaEntries.find(e => e.id == entryId);
        if (entry) {
            document.getElementById('entryId').value = entry.id;
            shiftSelect.value = entry.shift_id;
            if (entry.location_id) {
                const locRadio = document.querySelector(`input[name="entryLocation"][value="${entry.location_id}"]`);
                if (locRadio) locRadio.checked = true;
            }
            document.getElementById('entryOnCall').checked = entry.is_on_call == 1;
            document.getElementById('entryDeleteBtn').style.display = '';
            document.getElementById('rotaEntryModalTitle').textContent = t('tickets.rota.modal.edit_title');
        }
    }

    document.getElementById('rotaEntryModal').classList.add('active');
}

function closeRotaEntryModal() {
    document.getElementById('rotaEntryModal').classList.remove('active');
}

// Save entry
document.getElementById('rotaEntryForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const selectedLoc = document.querySelector('input[name="entryLocation"]:checked');
    const entryData = {
        id: document.getElementById('entryId').value || null,
        analyst_id: document.getElementById('entryAnalystId').value,
        rota_date: document.getElementById('entryDate').value,
        shift_id: document.getElementById('entryShift').value,
        location_id: selectedLoc ? parseInt(selectedLoc.value) : null,
        is_on_call: document.getElementById('entryOnCall').checked ? 1 : 0
    };

    try {
        const response = await fetch(ROTA_API + 'save_rota_entry.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(entryData)
        });
        const data = await response.json();
        if (data.success) {
            showToast(t('tickets.rota.toasts.saved'), 'success');
            closeRotaEntryModal();
            loadRota();
        } else {
            showToast(t('tickets.rota.toasts.error', { error: data.error }), 'error');
        }
    } catch (error) {
        showToast(t('tickets.rota.toasts.save_failed'), 'error');
    }
});

// Delete entry
async function deleteRotaEntry() {
    const id = document.getElementById('entryId').value;
    if (!id) return;
    if (!confirm(t('tickets.rota.delete_confirm'))) return;

    try {
        const response = await fetch(ROTA_API + 'delete_rota_entry.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: parseInt(id) })
        });
        const data = await response.json();
        if (data.success) {
            showToast(t('tickets.rota.toasts.deleted'), 'success');
            closeRotaEntryModal();
            loadRota();
        } else {
            showToast(t('tickets.rota.toasts.error', { error: data.error }), 'error');
        }
    } catch (error) {
        showToast(t('tickets.rota.toasts.delete_failed'), 'error');
    }
}
