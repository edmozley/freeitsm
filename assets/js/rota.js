/**
 * Rota Page JS - Weekly staff rota management
 */

const ROTA_API = '../api/tickets/';
const SETTINGS_API = '../api/settings/';

// Rota labels render through the shared formatters in assets/js/tz.js, so
// weekday and month names follow the interface language while the arrangement
// follows the analyst's chosen date format (GH #105). These were four
// Intl.DateTimeFormat instances built from <html lang>, which tied how a date
// LOOKS to which language it is IN.
//
// A rota grid dictates its own shapes — a column heading is a weekday and a day
// number, never a full date — so these use fmtNaiveTemplate rather than
// fmtNaiveDate. Rota dates are date-only wall-clock values, hence naive.
const WEEKDAY_SHORT_FMT = { format: (d) => fmtNaiveWeekday(d, true) };
const MONTH_SHORT_FMT   = { format: (d) => fmtNaiveTemplate(d, 'MON') };
const DAY_NUM_FMT       = { format: (d) => fmtNaiveTemplate(d, 'D') };
const MODAL_DATE_FMT    = { format: (d) => fmtNaiveWeekday(d, false) + ' ' + fmtNaiveTemplate(d, 'D MON') };

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

                // 🔑 Every cell carries WHO and WHEN as data attributes, filled
                // or empty. The right-click menu needs to know which cell it
                // was opened on, and reading it back off the element beats
                // threading three arguments through an oncontextmenu string —
                // where an analyst name with an apostrophe would break out.
                const cellData = `data-analyst="${analyst.id}" data-date="${day.date}" oncontextmenu="return openRotaCellMenu(event, this);"`;

                if (entry) {
                    const locStyle = entry.location_colour
                        ? `style="background:${entry.location_colour}; color:#fff;"`
                        : '';
                    const locLabel = escapeHtml(entry.location_name || '');
                    html += `<div class="rota-cell${todayClass}" ${cellData} data-entry="${entry.id}" onclick="openRotaEntryModal(${analyst.id}, '${day.date}', ${entry.id})">
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
                    html += `<div class="rota-cell${todayClass}" ${cellData} onclick="openRotaEntryModal(${analyst.id}, '${day.date}')">
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
    if (!(await showConfirm({ title: 'Confirm', message: t('tickets.rota.delete_confirm'), okLabel: 'OK', okClass: 'primary' }))) return;

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

// ==================== Copy and paste (Ed) ====================
//
// Two clipboards, both in memory only. Deliberately not sessionStorage: a
// clipboard that outlives the tab means opening the rota tomorrow with a
// half-remembered week still loaded and a Paste button offering to apply it,
// which is a worse failure than having to copy again.
//
// 🔑 A cell holds a SHIFT, not an entry row. What gets copied is what the entry
// says — shift, location, on-call — never its id, because pasting is an upsert
// onto a different (analyst, date) and the source row must not be touched.

let rotaCellClipboard = null;   // { shift_id, location_id, is_on_call, shift_name }
let rotaWeekClipboard = null;   // { week_start, entries: [...], count }
let rotaCtxCell = null;         // the cell the context menu was opened on

function rotaEntryAt(analystId, date) {
    return rotaEntries.find(e => e.analyst_id == analystId && e.rota_date === date) || null;
}

/** Format a YYYY-MM-DD for a message, in the analyst's own date arrangement. */
function rotaDateLabel(date) {
    return MODAL_DATE_FMT.format(new Date(date + 'T00:00:00'));
}

// ---- The cell menu ----------------------------------------------------

function openRotaCellMenu(event, cell) {
    event.preventDefault();
    rotaCtxCell = cell;

    const analystId = cell.dataset.analyst;
    const date      = cell.dataset.date;
    const entry     = rotaEntryAt(analystId, date);
    const analyst   = rotaAnalysts.find(a => a.id == analystId);

    const menu = document.getElementById('rotaContextMenu');
    document.getElementById('rotaCtxHeader').textContent =
        (analyst ? analyst.full_name : '') + ' — ' + rotaDateLabel(date);

    // Copy and Clear only mean something on a cell that has a shift in it.
    document.getElementById('rotaCtxCopy').style.display  = entry ? '' : 'none';
    document.getElementById('rotaCtxClear').style.display = entry ? '' : 'none';

    // Paste is always listed, but says why it cannot be used rather than
    // sitting there as a dead option that appears to do nothing.
    const pasteBtn = document.getElementById('rotaCtxPaste');
    const pasteLbl = document.getElementById('rotaCtxPasteLabel');
    if (rotaCellClipboard) {
        pasteBtn.disabled = false;
        pasteBtn.style.opacity = '';
        pasteLbl.textContent = t('tickets.rota.ctx.paste_cell') + ' — ' + rotaCellClipboard.shift_name;
    } else {
        pasteBtn.disabled = true;
        pasteBtn.style.opacity = '0.5';
        pasteLbl.textContent = t('tickets.rota.ctx.nothing_copied');
    }

    // Position, then nudge back inside the viewport. A cell in the last column
    // sits at the right-hand edge and the menu would otherwise open off screen.
    menu.classList.add('active');
    const r = menu.getBoundingClientRect();
    const x = Math.min(event.clientX, window.innerWidth  - r.width  - 8);
    const y = Math.min(event.clientY, window.innerHeight - r.height - 8);
    menu.style.left = Math.max(8, x) + 'px';
    menu.style.top  = Math.max(8, y) + 'px';
    return false;
}

function closeRotaCellMenu() {
    const menu = document.getElementById('rotaContextMenu');
    if (menu) menu.classList.remove('active');
    rotaCtxCell = null;
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('#rotaContextMenu')) closeRotaCellMenu();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeRotaCellMenu();
});

async function rotaCtxAction(action) {
    const cell = rotaCtxCell;
    closeRotaCellMenu();
    if (!cell) return;

    const analystId = cell.dataset.analyst;
    const date      = cell.dataset.date;
    const entry     = rotaEntryAt(analystId, date);

    if (action === 'copy') {
        if (!entry) return;
        rotaCellClipboard = {
            shift_id:    entry.shift_id,
            location_id: entry.location_id,
            is_on_call:  entry.is_on_call == 1 ? 1 : 0,
            shift_name:  entry.shift_name,
        };
        showToast(t('tickets.rota.copy.cell_copied', { shift: entry.shift_name }), 'success');
        return;
    }

    if (action === 'clear') {
        if (!entry) return;
        const ok = await showConfirm({ title: 'Confirm', message: t('tickets.rota.delete_confirm'), okLabel: 'OK', okClass: 'danger' });
        if (!ok) return;
        await rotaPost('delete_rota_entry.php', { id: parseInt(entry.id) }, 'tickets.rota.toasts.deleted', 'tickets.rota.toasts.delete_failed');
        return;
    }

    if (action === 'paste') {
        if (!rotaCellClipboard) { showToast(t('tickets.rota.copy.nothing_to_paste'), 'error'); return; }

        // Overwriting is the case worth stopping for, and the message names
        // both shifts — "are you sure?" on its own is a dialog people learn to
        // click through without reading.
        if (entry) {
            const analyst = rotaAnalysts.find(a => a.id == analystId);
            const ok = await showConfirm({
                title: t('tickets.rota.copy.cell_confirm_title'),
                message: t('tickets.rota.copy.cell_confirm', {
                    analyst:  analyst ? analyst.full_name : '',
                    existing: entry.shift_name,
                    date:     rotaDateLabel(date),
                    incoming: rotaCellClipboard.shift_name,
                }),
                okLabel: t('tickets.rota.ctx.paste_cell'),
                okClass: 'primary',
            });
            if (!ok) return;
        }

        // No id: save_rota_entry.php upserts on the (analyst, date) unique key,
        // so this is the same call whether the cell was empty or not.
        await rotaPost('save_rota_entry.php', {
            analyst_id:  analystId,
            rota_date:   date,
            shift_id:    rotaCellClipboard.shift_id,
            location_id: rotaCellClipboard.location_id,
            is_on_call:  rotaCellClipboard.is_on_call,
        }, 'tickets.rota.copy.pasted', 'tickets.rota.copy.paste_failed');
    }
}

/** POST, toast the outcome, reload the grid. Shared by every write above. */
async function rotaPost(endpoint, body, okKey, failKey) {
    try {
        const res = await fetch(ROTA_API + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json();
        if (data.success) {
            showToast(t(okKey), 'success');
            loadRota();
            return data;
        }
        showToast(t('tickets.rota.toasts.error', { error: data.error }), 'error');
    } catch (e) {
        showToast(t(failKey), 'error');
    }
    return null;
}

// ---- The whole week ---------------------------------------------------

/** The entries the grid is actually drawing, as {offset, entry} pairs. */
function rotaVisibleEntries(weekStart) {
    const numDays = includeWeekends ? 7 : 5;
    const start = new Date(weekStart + 'T00:00:00');
    const out = [];
    rotaEntries.forEach(e => {
        const offset = Math.round((new Date(e.rota_date + 'T00:00:00') - start) / 86400000);
        if (offset < 0 || offset >= numDays) return;               // a hidden weekend day
        if (!rotaAnalysts.some(a => a.id == e.analyst_id)) return;  // a deactivated analyst
        out.push({ offset: offset, entry: e });
    });
    return out;
}

function copyRotaWeek() {
    // Stored as a DAY OFFSET, not a date. The whole point is to land these on a
    // different week, and the offset is the only part that survives the move.
    const entries = rotaVisibleEntries(currentWeekStart).map(x => ({
        analyst_id:  x.entry.analyst_id,
        day_offset:  x.offset,
        shift_id:    x.entry.shift_id,
        location_id: x.entry.location_id,
        is_on_call:  x.entry.is_on_call == 1 ? 1 : 0,
    }));

    if (!entries.length) {
        showToast(t('tickets.rota.copy.week_empty'), 'error');
        return;
    }

    rotaWeekClipboard = { week_start: currentWeekStart, entries: entries, count: entries.length };
    updatePasteWeekButton();
    showToast(t('tickets.rota.copy.week_copied', { count: entries.length }), 'success');
}

function updatePasteWeekButton() {
    const btn = document.getElementById('rotaPasteWeekBtn');
    if (!btn) return;
    if (!rotaWeekClipboard) { btn.style.display = 'none'; return; }
    btn.style.display = '';
    btn.textContent = t('tickets.rota.copy.paste_week_btn');
    btn.title = t('tickets.rota.copy.clipboard_week', { date: rotaDateLabel(rotaWeekClipboard.week_start) });
}

async function pasteRotaWeek() {
    if (!rotaWeekClipboard) return;

    if (rotaWeekClipboard.week_start === currentWeekStart) {
        showToast(t('tickets.rota.copy.week_same'), 'error');
        return;
    }

    // How much is in the week being pasted over, counted exactly the way the
    // copy counts — so the number in the warning is the number that disappears.
    const existing = rotaVisibleEntries(currentWeekStart).length;

    const ok = await showConfirm({
        title: existing ? t('tickets.rota.copy.week_confirm_title') : t('tickets.rota.copy.paste_week_btn'),
        message: existing
            ? t('tickets.rota.copy.week_confirm', { existing: existing, incoming: rotaWeekClipboard.count })
            : t('tickets.rota.copy.week_confirm_empty', { incoming: rotaWeekClipboard.count }),
        okLabel: t('tickets.rota.copy.paste_week_btn'),
        okClass: existing ? 'danger' : 'primary',
    });
    if (!ok) return;

    try {
        const res = await fetch(ROTA_API + 'paste_rota_week.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ week_start: currentWeekStart, entries: rotaWeekClipboard.entries }),
        });
        const data = await res.json();
        if (!data.success) {
            showToast(t('tickets.rota.toasts.error', { error: data.error }), 'error');
            return;
        }
        showToast(t('tickets.rota.copy.week_pasted', { written: data.written, removed: data.removed }), 'success');
        // Never silent: a shift retired between the copy and the paste drops
        // those rows, and a paste that quietly loses somebody's shift is the
        // worst thing this feature could do.
        if (data.skipped > 0) {
            showToast(t('tickets.rota.copy.week_skipped', { count: data.skipped }), 'error');
        }
        loadRota();
    } catch (e) {
        showToast(t('tickets.rota.copy.paste_failed'), 'error');
    }
}
