/**
 * Change Management Module - Client-side logic
 */

const API_BASE = window.API_BASE || '../api/change-management/';

let changes = [];
let analysts = [];
let currentChange = null;
let currentFilter = 'all';
let searchQuery = '';
// Configurable form layout fetched from get_field_layout.php. Drives the
// editor's section order, headings, field placement, and visibility.
// See refreshFormLayout() for the rendering pass.
let formLayout = { sections: [], fields: [], unplaced: [] };
let fieldByKey = {};   // field_key → layout row
let sectionById = {};  // section.id → section row
let cabEditorMembers = []; // [{analyst_id, name, is_required}]

// Rich-text fields share a single anchored widget (cmRichTextWidget). v1
// limitation: their per-field section_id is honored for *visibility* and
// for picking the widget's host section, but the six tabs are NOT split
// across sections — they always render together inside the widget.
const RICH_TEXT_FIELDS = ['description', 'reason', 'risk', 'testplan', 'rollback', 'pir'];
// Statuses loaded from change_statuses table (active rows). Drives the sidebar
// filter list, the editor's Status dropdown, and the updateCounts() loop.
let changeStatuses = [];

// Lookups for the right-click context menu submenus (active rows only). Loaded
// once at init; the menu is rebuilt from these each time it opens so newly-added
// entries + the current-value tick appear without a page refresh.
let changePriorities = [];
let changeTypes = [];
let changeImpacts = [];
// Companies this analyst can move a change into (multi-company installs only).
let moveCompanies = [];
let isMultiCompany = false;

// TinyMCE editor instances
const editorIds = ['editorDescription', 'editorReason', 'editorRisk', 'editorTestplan', 'editorRollback', 'editorPir'];
let editorsReady = false;

// ============ Initialization ============

document.addEventListener('DOMContentLoaded', function() {
    const layoutReady = loadFormLayout();
    loadAnalysts();
    const statusesReady = loadStatuses();
    // Context-menu lookups (priorities / types / impacts) + accessible companies.
    loadChangePriorities();
    loadChangeTypes();
    loadChangeImpacts();
    loadMoveCompanies();
    loadChanges();
    setupFileUpload();

    // Handle deep links:
    //   ?open=ID                  — shared link to view an existing change
    //   window.openCreateOnLoad   — set by /change-management/new/index.php
    //                               (a thin include wrapper). Opens the
    //                               editor in create mode straight away.
    // Deep link: ?change_id=N is canonical (matches tickets' ?ticket_id=).
    // open/change/id are kept as backward-compat aliases for old shared links.
    const urlParams = new URLSearchParams(window.location.search);
    const openId = urlParams.get('change_id') || urlParams.get('open') || urlParams.get('change') || urlParams.get('id');
    if (openId) {
        viewChange(parseInt(openId, 10));
    } else if (window.openCreateOnLoad) {
        // openCreateChange() needs the Status dropdown populated by
        // loadStatuses() AND the form layout fetched so refreshFormLayout()
        // can place + hide sections correctly on first paint.
        Promise.all([statusesReady, layoutReady]).then(() => openCreateChange());
    }

    // Enter key triggers search in search modal
    document.querySelectorAll('#searchChangeNumber, #searchChangeTitle').forEach(input => {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') performSearch();
        });
    });

    // Status change toggles PIR fields visibility
    const statusSelect = document.getElementById('changeStatus');
    if (statusSelect) {
        statusSelect.addEventListener('change', updatePirVisibility);
    }
});

// ============ Form Layout ============

// Detail view field-to-data mapping (used by renderChangeDetail to know
// which c.<prop> to surface when a given field key is visible).
const DETAIL_FIELD_MAP = {
    impact: 'impact', category: 'category',
    requester: 'requester_name', assigned_to: 'assigned_to_name', approver: 'approver_name',
    work_start: 'work_start_datetime', work_end: 'work_end_datetime',
    outage_start: 'outage_start_datetime', outage_end: 'outage_end_datetime'
};

/**
 * Loads the change form layout (sections, fields and where each is placed) set in Change
 * Management settings.
 */
async function loadFormLayout() {
    try {
        const res = await fetch(API_BASE + 'get_field_layout.php');
        const data = await res.json();
        if (data.success) {
            formLayout = {
                sections: data.sections || [],
                fields:   data.fields   || [],
                unplaced: data.unplaced || []
            };
            fieldByKey = {};
            for (const f of formLayout.fields) fieldByKey[f.key] = f;
            sectionById = {};
            for (const s of formLayout.sections) sectionById[s.id] = s;
        }
    } catch (e) {
        console.error('Error loading form layout:', e);
    }
}

/**
 * Whether a change field is shown, by the form layout. A field the layout does not know
 * about is shown.
 */
function isFieldVisible(fieldKey) {
    const f = fieldByKey[fieldKey];
    // Unknown fields default to visible. Keeps newly-added catalogue keys
    // working before an admin has placed them in the layout.
    return !f || f.is_visible !== false;
}

/**
 * Rebuilds the editor form to match the current formLayout:
 *   - reorders .cm-form-section blocks by section.display_order
 *   - renames each section heading from section.name
 *   - moves .cm-field-wrap blocks between sections based on field.section_id
 *   - shows/hides each wrap based on field.is_visible
 *   - parks the rich-text widget under whichever section contains the
 *     first visible rich-text field (v1 limitation: the six rich-text
 *     tabs are NOT split across sections)
 *   - hides sections that end up with no visible content
 *
 * Called whenever the editor view becomes visible (showView('editor')),
 * which covers both create and edit flows.
 */
function refreshFormLayout() {
    const editorForm = document.querySelector('#changeEditorView .editor-form');
    if (!editorForm) return;

    const sortedSections = [...formLayout.sections].sort(
        (a, b) => (a.display_order - b.display_order) || (a.id - b.id)
    );
    const sortedFields = [...formLayout.fields].sort(
        (a, b) => (a.display_order - b.display_order) || a.key.localeCompare(b.key)
    );

    // section.id → DOM wrapper. Create wrappers for sections that exist in
    // the DB but weren't in the seed HTML (admin-added sections).
    const wrappersById = {};
    editorForm.querySelectorAll('.cm-form-section').forEach(w => {
        const id = parseInt(w.dataset.sectionId, 10);
        if (!isNaN(id)) wrappersById[id] = w;
    });
    sortedSections.forEach(s => {
        if (!wrappersById[s.id]) {
            const w = document.createElement('div');
            w.className = 'cm-form-section';
            w.dataset.sectionId = s.id;
            const h = document.createElement('h3');
            h.className = 'form-section-title';
            w.appendChild(h);
            editorForm.appendChild(w);
            wrappersById[s.id] = w;
        }
    });

    // Re-attach wrappers in DB order, refresh heading text. We keep
    // editor-actions (cancel/save row) at the bottom by inserting before it.
    const editorActions = editorForm.querySelector('.editor-actions');
    sortedSections.forEach(s => {
        const w = wrappersById[s.id];
        const heading = w.querySelector('.form-section-title');
        if (heading) heading.textContent = s.name;
        w.style.display = '';
        if (editorActions) editorForm.insertBefore(w, editorActions);
        else editorForm.appendChild(w);
    });
    // Wrappers whose section no longer exists in DB are hidden (data
    // stays in DOM but won't render — settings UI should have moved
    // fields out before delete, so these are empty shells).
    Object.entries(wrappersById).forEach(([id, w]) => {
        if (!sectionById[id]) w.style.display = 'none';
    });

    // Pick the rich-text widget's host section: first section (by display
    // order) that contains a visible rich-text field.
    let anchorSectionId = null;
    for (const s of sortedSections) {
        const hit = sortedFields.find(f =>
            RICH_TEXT_FIELDS.includes(f.key) && f.section_id === s.id && f.is_visible
        );
        if (hit) { anchorSectionId = s.id; break; }
    }

    // field_key → DOM wrap
    const wrapsByKey = {};
    editorForm.querySelectorAll('.cm-field-wrap').forEach(w => {
        wrapsByKey[w.dataset.fieldKey] = w;
    });

    // Place non-rich-text wraps inside their target section in display order.
    // Stamp each wrap with its preferred width ('half' or 'full') from the
    // catalogue — CSS uses [data-width] to drive flex sizing on each row.
    sortedSections.forEach(s => {
        const sectionEl = wrappersById[s.id];
        if (!sectionEl) return;
        sortedFields
            .filter(f => f.section_id === s.id && !RICH_TEXT_FIELDS.includes(f.key))
            .forEach(f => {
                const wrap = wrapsByKey[f.key];
                if (!wrap) return;
                sectionEl.appendChild(wrap);
                wrap.dataset.width = f.width || 'full';
                wrap.style.display = f.is_visible ? '' : 'none';
            });
    });

    // Rich-text widget + PIR structured wrap both follow the anchor section.
    const richTextWidget = document.getElementById('cmRichTextWidget');
    const pirWrap = wrapsByKey['pir'];
    if (anchorSectionId && wrappersById[anchorSectionId]) {
        const host = wrappersById[anchorSectionId];
        if (richTextWidget) {
            host.appendChild(richTextWidget);
            richTextWidget.style.display = '';
        }
        if (pirWrap) {
            host.appendChild(pirWrap);
            pirWrap.style.display = isFieldVisible('pir') ? '' : 'none';
        }
    } else {
        if (richTextWidget) richTextWidget.style.display = 'none';
        if (pirWrap) pirWrap.style.display = 'none';
    }

    // Per-tab visibility on the rich-text widget. If the active tab is
    // hidden, switch to the first visible one so the user isn't stuck on
    // an empty panel.
    if (richTextWidget) {
        let firstVisible = null;
        richTextWidget.querySelectorAll('.rich-text-tab').forEach(btn => {
            const vis = isFieldVisible(btn.dataset.fieldKey);
            btn.style.display = vis ? '' : 'none';
            if (vis && !firstVisible) firstVisible = btn.dataset.fieldKey;
        });
        richTextWidget.querySelectorAll('.rich-text-panel').forEach(panel => {
            if (!isFieldVisible(panel.dataset.fieldKey)) panel.style.display = 'none';
        });
        const activeTab = richTextWidget.querySelector('.rich-text-tab.active');
        if (activeTab && activeTab.style.display === 'none' && firstVisible) {
            switchTab(firstVisible);
        }
    }

    // Hide sections that ended up with no visible content. A section
    // counts as visible if it has at least one visible .cm-field-wrap OR
    // it hosts the rich-text widget (and the widget itself is visible).
    sortedSections.forEach(s => {
        const wrapper = wrappersById[s.id];
        if (!wrapper) return;
        const visibleWraps = Array.from(wrapper.querySelectorAll('.cm-field-wrap'))
            .filter(w => w.style.display !== 'none');
        const hostsRichText = richTextWidget
            && richTextWidget.parentElement === wrapper
            && richTextWidget.style.display !== 'none';
        if (visibleWraps.length === 0 && !hostsRichText) {
            wrapper.style.display = 'none';
        }
    });

    // Pair up adjacent half-width wraps and flag orphan halves. A half is
    // considered orphaned (and stretches to full width via CSS) when its
    // next visible sibling is NOT also half — i.e. it can't pair with
    // something. Walked per-section so a half at the end of section A
    // doesn't accidentally pair with the first half of section B.
    sortedSections.forEach(s => {
        const wrapper = wrappersById[s.id];
        if (!wrapper || wrapper.style.display === 'none') return;
        const visible = Array.from(wrapper.children).filter(
            el => el.classList.contains('cm-field-wrap') && el.style.display !== 'none'
        );
        let i = 0;
        while (i < visible.length) {
            const a = visible[i];
            const b = visible[i + 1];
            if (a.dataset.width === 'half' && b && b.dataset.width === 'half') {
                a.classList.remove('cm-field-half-orphan');
                b.classList.remove('cm-field-half-orphan');
                i += 2;
            } else {
                if (a.dataset.width === 'half') a.classList.add('cm-field-half-orphan');
                else a.classList.remove('cm-field-half-orphan');
                i += 1;
            }
        }
    });

    // Mark the first VISIBLE section so its heading can drop the top
    // border / padding (we want the section divider on every heading
    // except the one at the top of the form, regardless of which
    // section is currently first after reordering).
    editorForm.querySelectorAll('.cm-form-section--first').forEach(
        el => el.classList.remove('cm-form-section--first')
    );
    const firstVisible = sortedSections
        .map(s => wrappersById[s.id])
        .find(w => w && w.style.display !== 'none');
    if (firstVisible) firstVisible.classList.add('cm-form-section--first');
}

// ============ Data Loading ============

// Pull active statuses from the change_statuses table and use them to drive
// both the sidebar filter list and the editor's Status dropdown. So when
// statuses get added / renamed / deactivated under Change Management →
// Settings → Statuses, both UIs reflect the change on next page load.
async function loadStatuses() {
    try {
        const response = await fetch(API_BASE + 'get_change_statuses.php');
        const data = await response.json();
        if (data.success) {
            changeStatuses = (data.statuses || []).filter(s => s.is_active);
            // Sort by display_order then name so the order matches Settings.
            changeStatuses.sort((a, b) => {
                const o = (a.display_order || 0) - (b.display_order || 0);
                return o !== 0 ? o : String(a.name).localeCompare(String(b.name));
            });
            renderStatusFilters();
            populateStatusDropdown();
            // Counts may have arrived before statuses — re-apply them now
            // so newly-rendered filter rows pick up their current counts.
            if (latestCounts) updateCounts(latestCounts);
        }
    } catch (e) {
        console.error('Error loading change statuses:', e);
    }
}

// Context-menu lookups. Each endpoint returns {success, <key>:[{id,name,colour,
// is_active,display_order,...}]}. We keep active rows only, sorted the same way
// as the sidebar (display_order then name) so the submenus match Settings.
function sortLookup(rows) {
    return rows.sort((a, b) => {
        const o = (a.display_order || 0) - (b.display_order || 0);
        return o !== 0 ? o : String(a.name).localeCompare(String(b.name));
    });
}

/** Loads the active change priorities, in order. */
async function loadChangePriorities() {
    try {
        const res = await fetch(API_BASE + 'get_change_priorities.php');
        const data = await res.json();
        if (data.success) changePriorities = sortLookup((data.priorities || []).filter(p => p.is_active));
    } catch (e) {
        console.error('Error loading change priorities:', e);
    }
}

/** Loads the active change types, in order. */
async function loadChangeTypes() {
    try {
        const res = await fetch(API_BASE + 'get_change_types.php');
        const data = await res.json();
        if (data.success) changeTypes = sortLookup((data.types || []).filter(t => t.is_active));
    } catch (e) {
        console.error('Error loading change types:', e);
    }
}

/** Loads the active change impact levels, in order. */
async function loadChangeImpacts() {
    try {
        const res = await fetch(API_BASE + 'get_change_impacts.php');
        const data = await res.json();
        if (data.success) changeImpacts = sortLookup((data.impacts || []).filter(i => i.is_active));
    } catch (e) {
        console.error('Error loading change impacts:', e);
    }
}

// Companies this analyst can move a change into (multi-company installs only).
// Same source as the tickets "Move to company" submenu; the "Move to company"
// row stays hidden unless there's more than one accessible company.
async function loadMoveCompanies() {
    try {
        const res = await fetch('../api/system/get_tenants.php?accessible=1');
        const data = await res.json();
        if (data.success) {
            moveCompanies = data.companies || [];
            isMultiCompany = moveCompanies.length > 1;
        }
    } catch (e) {
        moveCompanies = [];
        isMultiCompany = false;
    }
}

/**
 * Draws one filter row per change status (with its colour and a count) under the
 * always-present All row.
 */
function renderStatusFilters() {
    const container = document.getElementById('statusFilterList');
    if (!container) return;
    // Remove all .status-filter rows except the always-present "All" one.
    container.querySelectorAll('.status-filter:not([data-status="all"])').forEach(el => el.remove());

    changeStatuses.forEach(s => {
        const safeName = String(s.name).replace(/'/g, "\\'");
        const swatch = s.colour
            ? `<span class="status-swatch" style="background:${escapeHtml(s.colour)};display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:middle;"></span>`
            : '';
        const row = document.createElement('div');
        row.className = 'status-filter';
        row.dataset.status = s.name;
        row.setAttribute('onclick', `filterByStatus('${safeName}')`);
        row.innerHTML = `<span>${swatch}${escapeHtml(s.name)}</span><span class="filter-count" data-status-count="${escapeHtml(s.name)}">0</span>`;
        container.appendChild(row);
    });
}

/** Fills the editor's status dropdown, keeping the current choice when it still exists. */
function populateStatusDropdown() {
    const sel = document.getElementById('changeStatus');
    if (!sel) return;
    // Preserve current selection if it still exists (e.g. when the dropdown
    // gets rebuilt while editing an existing change).
    const currentValue = sel.value;
    sel.innerHTML = changeStatuses.map(s =>
        `<option value="${escapeHtml(s.name)}">${escapeHtml(s.name)}</option>`
    ).join('');
    if (currentValue) sel.value = currentValue;
}

/** Loads the analysts and fills every analyst dropdown. */
async function loadAnalysts() {
    try {
        const response = await fetch(API_BASE + 'list.php?analysts=1');
        const data = await response.json();
        if (data.success) {
            analysts = data.analysts;
            populateAnalystDropdowns();
        }
    } catch (error) {
        console.error('Error loading analysts:', error);
    }
}

/** Fills the requester, assigned-to, approver and CAB member dropdowns with the analysts. */
function populateAnalystDropdowns() {
    const dropdowns = ['changeRequester', 'changeAssignedTo', 'changeApprover', 'cabMemberSelect'];
    dropdowns.forEach(id => {
        const select = document.getElementById(id);
        if (!select) return;
        // Keep the first "-- Select --" option
        select.innerHTML = '<option value="">-- Select --</option>';
        analysts.forEach(a => {
            const opt = document.createElement('option');
            opt.value = a.id;
            opt.textContent = a.name;
            select.appendChild(opt);
        });
    });
}

/** Loads the change list for the current status filter and search, and updates the counts. */
async function loadChanges() {
    try {
        let url = API_BASE + 'list.php?';
        if (currentFilter !== 'all') {
            url += 'status=' + encodeURIComponent(currentFilter) + '&';
        }
        if (searchQuery) {
            url += 'search=' + encodeURIComponent(searchQuery) + '&';
        }

        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            changes = data.changes;
            updateCounts(data.counts);
            renderChangeList();
        }
    } catch (error) {
        console.error('Error loading changes:', error);
        document.getElementById('changeList').innerHTML = `<div class="empty-state"><p>${window.t('change-management.list.error')}</p></div>`;
    }
}

// Last counts payload from list.php — cached so loadStatuses() can re-apply
// them if the status list lands after the first counts response.
let latestCounts = null;
/**
 * Writes the total and per-status counts into the filter rows, and keeps them so they
 * can be reapplied when the status list arrives later.
 */
function updateCounts(counts) {
    if (!counts) return;
    latestCounts = counts;
    const allEl = document.getElementById('countAll');
    if (allEl) allEl.textContent = counts.total || 0;
    // Each filter row owns a [data-status-count="StatusName"] count span;
    // iterate them and look the value up by the same status-name key the
    // backend uses for its counts object.
    document.querySelectorAll('[data-status-count]').forEach(span => {
        const name = span.getAttribute('data-status-count');
        span.textContent = counts[name] || 0;
    });
}

// ============ Rendering ============

/**
 * Draws the change cards - reference, title, assignee, work start and risk, type,
 * priority and status badges.
 */
function renderChangeList() {
    const container = document.getElementById('changeList');
    const countEl = document.getElementById('changeCount');

    if (!changes.length) {
        container.innerHTML = `<div class="empty-state"><div class="empty-state-icon">&#128221;</div><div class="empty-state-text">${window.t('change-management.list.no_changes')}</div></div>`;
        countEl.textContent = '';
        return;
    }

    countEl.textContent = changes.length === 1
        ? window.t('change-management.list.count', { count: changes.length })
        : window.t('change-management.list.count_plural', { count: changes.length });

    container.innerHTML = changes.map(c => {
        const ref = 'CHG-' + String(c.id).padStart(4, '0');
        const statusClass = c.status.toLowerCase().replace(/\s+/g, '-');
        const typeClass = c.change_type.toLowerCase();
        const priorityClass = c.priority.toLowerCase();
        const assignedName = c.assigned_to_name || window.t('change-management.list.unassigned');
        const workStart = c.work_start_datetime ? formatDate(c.work_start_datetime) : '';

        return `
            <div class="change-card" data-id="${c.id}" onclick="viewChange(${c.id})" oncontextmenu="openChangeContextMenu(event, ${c.id}); return false;">
                <div class="change-card-ref">${ref}</div>
                <div class="change-card-info">
                    <div class="change-card-title">${escapeHtml(c.title)}</div>
                    <div class="change-card-meta">
                        <span>${assignedName}</span>
                        ${workStart ? `<span>${window.t('change-management.list.work', { date: workStart })}</span>` : ''}
                    </div>
                </div>
                <div class="change-card-badges">
                    ${ChangeRisk.badge(c)}
                    <span class="type-badge ${typeClass}">${escapeHtml(c.change_type)}</span>
                    <span class="priority-badge ${priorityClass}">${escapeHtml(c.priority)}</span>
                    <span class="status-badge ${statusClass}">${escapeHtml(c.status)}</span>
                </div>
            </div>
        `;
    }).join('');
}

// ============ View Change Detail ============

/** Loads one change, records it in the recent trail and shows its detail view. */
async function viewChange(id) {
    try {
        const response = await fetch(API_BASE + 'get.php?id=' + id);
        const data = await response.json();

        if (!data.success) {
            showToast(window.t('change-management.toast.error_prefix', { message: data.error }), 'success');
            return;
        }

        currentChange = data.change;
        // The recent trail (#124).
        if (window.trailVisit) window.trailVisit('change', id);
        renderChangeDetail();
        showView('detail');
    } catch (error) {
        console.error('Error loading change:', error);
        showToast(window.t('change-management.toast.error_loading'), 'success');
    }
}

/**
 * Draws the detail view of the current change - header, badges and the fields the form
 * layout shows, CAB review, rich-text sections, risk matrix, review results,
 * attachments, linked incidents and activity.
 */
function renderChangeDetail() {
    const c = currentChange;
    const ref = 'CHG-' + String(c.id).padStart(4, '0');
    const statusClass = c.status.toLowerCase().replace(/\s+/g, '-');
    const typeClass = c.change_type.toLowerCase();
    const priorityClass = c.priority.toLowerCase();
    const v = isFieldVisible;

    // Build badges
    let badgesHtml = '';
    if (v('status')) badgesHtml += `<span class="status-badge ${statusClass}">${escapeHtml(c.status)}</span>`;
    if (v('change_type')) badgesHtml += `<span class="type-badge ${typeClass}">${escapeHtml(c.change_type)}</span>`;
    if (v('priority')) badgesHtml += `<span class="priority-badge ${priorityClass}">${escapeHtml(c.priority)}</span>`;

    // Build meta grid — only include visible fields
    let metaItems = '';
    if (v('impact')) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.impact')}</span><span class="detail-meta-value">${escapeHtml(c.impact)}</span></div>`;
    if (v('category') && c.category) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.category')}</span><span class="detail-meta-value">${escapeHtml(c.category)}</span></div>`;
    if (v('requester')) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.requester')}</span><span class="detail-meta-value">${c.requester_name ? escapeHtml(c.requester_name) : window.t('change-management.detail.not_set')}</span></div>`;
    if (v('assigned_to')) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.assigned_to')}</span><span class="detail-meta-value">${c.assigned_to_name ? escapeHtml(c.assigned_to_name) : window.t('change-management.detail.not_set')}</span></div>`;
    if (v('approver')) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.approver')}</span><span class="detail-meta-value">${c.approver_name ? escapeHtml(c.approver_name) : window.t('change-management.detail.not_set')}</span></div>`;
    if (v('approver') && c.approval_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.approved')}</span><span class="detail-meta-value">${formatDateTime(c.approval_datetime)}</span></div>`;
    if (v('work_start') && c.work_start_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.work_start')}</span><span class="detail-meta-value">${formatNaiveDateTime(c.work_start_datetime)}</span></div>`;
    if (v('work_end') && c.work_end_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.work_end')}</span><span class="detail-meta-value">${formatNaiveDateTime(c.work_end_datetime)}</span></div>`;
    if (v('outage_start') && c.outage_start_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.outage_start')}</span><span class="detail-meta-value">${formatNaiveDateTime(c.outage_start_datetime)}</span></div>`;
    if (v('outage_end') && c.outage_end_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.outage_end')}</span><span class="detail-meta-value">${formatNaiveDateTime(c.outage_end_datetime)}</span></div>`;
    if (v('risk') && c.risk_level) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.risk_level')}</span><span class="detail-meta-value">${ChangeRisk.badge(c, true)}</span></div>`;
    metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.created')}</span><span class="detail-meta-value">${c.created_by_name ? window.t('change-management.detail.created_by', { datetime: formatDateTime(c.created_datetime), name: c.created_by_name }) : formatDateTime(c.created_datetime)}</span></div>`;
    metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.last_modified')}</span><span class="detail-meta-value">${formatDateTime(c.modified_datetime)}</span></div>`;

    // Build sticky header with buttons, title, badges, and meta grid
    let html = `
        <div class="change-detail-sticky-header">
            <div class="change-detail-header">
                <button class="btn btn-secondary" onclick="backToList()">${window.t('change-management.detail.back')}</button>
                <div class="change-detail-actions">
                    <div class="share-dropdown">
                        <button class="btn btn-share" onclick="toggleShareDropdown()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="18" cy="5" r="3"></circle>
                                <circle cx="6" cy="12" r="3"></circle>
                                <circle cx="18" cy="19" r="3"></circle>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                                <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                            </svg>
                            ${window.t('change-management.detail.share')}
                        </button>
                        <div class="share-dropdown-menu" id="shareDropdownMenu">
                            <a href="#" onclick="shareChangeLink(); return false;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                </svg>
                                ${window.t('change-management.detail.copy_link')}
                            </a>
                            <a href="#" onclick="shareChangePdf(); return false;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                                ${window.t('change-management.detail.export_pdf')}
                            </a>
                            <a href="${window.CHG_BASE}change-management/evidence.php?id=${c.id}" target="_blank" rel="noopener">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                                    <polyline points="9 12 11 14 15 10"></polyline>
                                </svg>
                                ${window.t('change-management.detail.export_evidence')}
                            </a>
                            <a href="#" onclick="shareChangeBoth(); return false;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                                ${window.t('change-management.detail.email_link_pdf')}
                            </a>
                        </div>
                    </div>
                    <button class="btn btn-primary" onclick="editCurrentChange()">${window.t('change-management.detail.edit')}</button>
                    ${c.approval_rules && c.approval_rules.delete_refused
                        ? (c.status_stage !== 'stopped' ? `<button class="btn btn-danger" onclick="cancelCurrentChange()">${window.t('change-management.approval_panel.cancel')}</button>` : '')
                        : `<button class="btn btn-danger" onclick="deleteCurrentChange()">${window.t('change-management.detail.delete')}</button>`}
                </div>
            </div>
            <div class="sticky-header-top">
                <div class="change-detail-ref">${ref}${window.MODULE_LEVEL === 'read' ? ` <span class="read-only-badge">${escapeHtml(window.t('common.module_read_only_badge'))}</span>` : ''}</div>
                ${badgesHtml ? `<div class="sticky-header-badges">${badgesHtml}</div>` : ''}
            </div>
            ${v('title') ? `<div class="change-detail-title">${escapeHtml(c.title)}</div>` : ''}
            ${metaItems ? `<div class="detail-meta-grid">${metaItems}</div>` : ''}
        </div>
    `;

    // Approval panel (#180): the current round, the CAB or the approver, earlier rounds.
    html += renderApprovalPanel(c);

    // Projects (3.2.0): the projects this change is part of.
    if (window.CHG_SHOW_PROJECTS) html += '<div id="changeProjects" class="chg-projects"></div>';

    // Detail sections — only include visible fields
    let sections = '';
    if (v('description')) sections += renderDetailSection(window.t('change-management.detail.description'), c.description, 'description');
    if (v('reason')) sections += renderDetailSection(window.t('change-management.detail.reason'), c.reason_for_change, 'reason_for_change');
    if (v('risk')) sections += renderDetailSection(window.t('change-management.detail.risk_eval'), c.risk_evaluation, 'risk_evaluation');
    if (v('testplan')) sections += renderDetailSection(window.t('change-management.detail.test_plan'), c.test_plan, 'test_plan');
    if (v('rollback')) sections += renderDetailSection(window.t('change-management.detail.rollback_plan'), c.rollback_plan, 'rollback_plan');
    if (v('pir')) sections += renderDetailSection(window.t('change-management.detail.pir'), c.post_implementation_review, 'post_implementation_review');

    if (sections) html += `<div class="detail-sections">${sections}</div>`;

    // Risk Matrix (shown when risk scores exist)
    if (v('risk') && c.risk_likelihood && c.risk_impact_score) {
        html += ChangeRisk.matrix(c);
    }

    // PIR Structured Data (for changes in the Done stage - Completed, Failed, or any renamed equivalent)
    if (v('pir') && c.status_stage === 'done' && (c.pir_was_successful !== null || c.pir_actual_start || c.pir_lessons_learned)) {
        html += renderPirDetail(c);
    }

    // Attachments
    if (v('attachments') && c.attachments && c.attachments.length) {
        html += `
            <div class="attachments-section">
                <h3>${window.t('change-management.detail.attachments', { count: c.attachments.length })}</h3>
                <div class="attachment-list">
                    ${c.attachments.map(a => `
                        <div class="attachment-item">
                            <div class="attachment-info">
                                <span class="attachment-icon">&#128206;</span>
                                <span class="attachment-name">${escapeHtml(a.file_name)}</span>
                                <span class="attachment-size">${formatFileSize(a.file_size)}</span>
                            </div>
                            <div class="attachment-actions">
                                <button onclick="downloadAttachment(${a.id})" title="${window.t('change-management.detail.download')}">&#8595;</button>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    // Linked incidents section
    html += renderLinkedIncidents(c);

    // Activity section (comments + audit trail)
    html += `
        <div class="activity-section">
            <h3>${window.t('change-management.detail.activity')}</h3>
            <div class="comment-input-area">
                <textarea class="form-input" id="commentInput" rows="2" placeholder="${window.t('change-management.detail.add_comment')}"></textarea>
                <button class="btn btn-primary btn-sm" onclick="postComment()">${window.t('change-management.detail.post')}</button>
            </div>
            <div class="activity-timeline" id="activityTimeline">
                <div class="loading"><div class="spinner"></div></div>
            </div>
        </div>
    `;

    html += '<div class="detail-section"><h3>Documents</h3><div id="chgDocuments"></div></div>';

    document.getElementById('changeDetailContent').innerHTML = html;
    if (window.CHG_SHOW_PROJECTS && window.ProjectLinks) ProjectLinks.mount(document.getElementById('changeProjects'), { kind: 'change', id: c.id, base: window.CHG_BASE });

    // Attached documents (discussion #76). Mounted, not re-pointed: this content
    // is rebuilt for every change, so the previous element is already gone.
    if (window.FreeITSMDocuments) {
        FreeITSMDocuments.mount(document.getElementById('chgDocuments'), {
            parentType: 'change',
            parentId:   c.id,
            apiBase:    '../api/documents/'
        });
    }

    // Load activity timeline asynchronously
    loadActivityTimeline(c.id);
}

// ============ Linked incidents ============

// Small inline icons for the linked-incidents rows.
const CM_OPEN_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
const CM_UNLINK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

/**
 * The ⓘ preview badge (#91). Guarded, so a page that somehow loaded without
 * record-preview.js loses the preview rather than the table it belongs to.
 */
function cmPreviewBadge(type, id) {
    return window.FreeITSMPreview ? window.FreeITSMPreview.badge(type, id) : '';
}

/**
 * The linked tickets table for a change - each with its type, a link, a preview badge and
 * Unlink - and the button to link another. Any ticket type can be linked, so the wording is
 * "tickets", and the type beside each one says which kind it is (GH #245).
 */
function renderLinkedIncidents(c) {
    const incidents = c.incidents || [];
    const rows = incidents.map(i => `
        <tr>
            <td><a href="../tickets/index.php?ticket_id=${i.id}" target="_blank" rel="noopener">${escapeHtml(i.ticket_number || ('#' + i.id))}</a>${i.ticket_type ? ` <span class="linked-ticket-type">${escapeHtml(i.ticket_type)}</span>` : ''}</td>
            <td>${escapeHtml(i.subject || '')}</td>
            <td>${escapeHtml(i.status || '')}</td>
            <td class="linked-incidents-actions">
                ${cmPreviewBadge('ticket', i.id)}
                <a class="linked-incident-btn" href="../tickets/index.php?ticket_id=${i.id}" target="_blank" rel="noopener" title="${escapeHtml(window.t('change-management.detail.open_incident'))}">${CM_OPEN_SVG}</a>
                <button class="linked-incident-btn danger" onclick="unlinkChangeIncident(${i.id})" title="${escapeHtml(window.t('change-management.detail.unlink'))}">${CM_UNLINK_SVG}</button>
            </td>
        </tr>`).join('');
    const body = rows || `<tr class="linked-incidents-empty"><td colspan="4">${window.t('change-management.detail.no_incidents')}</td></tr>`;
    return `
        <div class="linked-incidents-section">
            <div class="linked-incidents-head">
                <h3>${window.t('change-management.detail.linked_incidents')} (${incidents.length})</h3>
                <button class="btn btn-secondary btn-sm" onclick="openLinkIncidentModal()">${window.t('change-management.detail.link_incident')}</button>
            </div>
            <table class="linked-incidents-table">
                <tbody>${body}</tbody>
            </table>
        </div>
    `;
}

let cmLinkIncidentSearchTimer = null;
/** Opens the dialog for linking an incident to the current change. */
function openLinkIncidentModal() {
    if (!currentChange) return;
    const search = document.getElementById('linkIncidentSearch');
    if (search) search.value = '';
    document.getElementById('linkIncidentModal').classList.add('active');
    loadLinkableIncidents();
}
/** Closes the link-an-incident dialog. */
function closeLinkIncidentModal() {
    document.getElementById('linkIncidentModal').classList.remove('active');
}
/** Re-runs the incident search 250 ms after the last keystroke. */
function linkIncidentSearchDebounced() {
    clearTimeout(cmLinkIncidentSearchTimer);
    cmLinkIncidentSearchTimer = setTimeout(loadLinkableIncidents, 250);
}
/** Lists the tickets that can be linked to the current change, matching the search box. */
async function loadLinkableIncidents() {
    if (!currentChange) return;
    const list = document.getElementById('linkIncidentList');
    const q = (document.getElementById('linkIncidentSearch') || {}).value || '';
    list.innerHTML = `<div class="link-incident-empty">${window.t('change-management.detail.loading')}</div>`;
    try {
        const res = await fetch(API_BASE + 'list_linkable_tickets.php?change_id=' + currentChange.id + '&q=' + encodeURIComponent(q.trim()));
        const data = await res.json();
        if (!data.success) { list.innerHTML = `<div class="link-incident-empty">${escapeHtml(data.error || '')}</div>`; return; }
        if (!data.tickets.length) { list.innerHTML = `<div class="link-incident-empty">${window.t('change-management.detail.no_linkable')}</div>`; return; }
        list.innerHTML = data.tickets.map(t => `
            <button type="button" class="link-incident-row" onclick="linkChangeIncident(${t.id})">
                <span class="link-incident-title">${escapeHtml(t.subject || '(no subject)')}</span>
                <span class="link-incident-meta">${escapeHtml(t.ticket_number || '')}${t.ticket_type ? ' &middot; ' + escapeHtml(t.ticket_type) : ''}${t.status ? ' &middot; ' + escapeHtml(t.status) : ''}${t.requester ? ' &middot; ' + escapeHtml(t.requester) : ''}</span>
            </button>`).join('');
    } catch (e) {
        list.innerHTML = `<div class="link-incident-empty">${window.t('change-management.toast.load_incidents_failed')}</div>`;
    }
}
/** Links a ticket to the current change and reloads it. */
async function linkChangeIncident(ticketId) {
    if (!currentChange) return;
    try {
        const res = await fetch(API_BASE + 'link_ticket.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ change_id: currentChange.id, ticket_id: ticketId }) });
        const data = await res.json();
        if (!data.success) { showToast(data.error || window.t('change-management.toast.link_failed'), 'error'); return; }
        closeLinkIncidentModal();
        showToast(window.t('change-management.toast.incident_linked'), 'success');
        viewChange(currentChange.id);
    } catch (e) { showToast(window.t('change-management.toast.link_failed'), 'error'); }
}
/** Asks for confirmation, then unlinks a ticket from the current change and reloads it. */
async function unlinkChangeIncident(ticketId) {
    if (!currentChange) return;
    const ok = window.showConfirm
        ? await showConfirm({ title: window.t('change-management.detail.unlink'), message: window.t('change-management.detail.unlink_incident_confirm'), okLabel: window.t('change-management.detail.unlink'), okClass: 'danger' })
        : confirm(window.t('change-management.detail.unlink_incident_confirm'));
    if (!ok) return;
    try {
        const res = await fetch(API_BASE + 'unlink_ticket.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ change_id: currentChange.id, ticket_id: ticketId }) });
        const data = await res.json();
        if (!data.success) { showToast(data.error || window.t('change-management.toast.unlink_failed'), 'error'); return; }
        showToast(window.t('change-management.toast.incident_unlinked'), 'success');
        viewChange(currentChange.id);
    } catch (e) { showToast(window.t('change-management.toast.unlink_failed'), 'error'); }
}

/**
 * The editor HTML of a change's long-text fields, cleaned for display.
 *
 * TRAP: change text is stored as the editor sent it, and anybody with Change Management
 * (or an API key) can write it, so it is untrusted. Every place it reaches innerHTML -
 * the detail view AND the PDF export - must come through here. Fails closed: if
 * safe-html.js did not load, the markup is shown as inert text.
 */
function safeChangeHtml(html) {
    if (typeof safeHtmlFragment !== 'function') {
        console.error('FreeITSM: assets/js/safe-html.js did not load - change text shown as plain text.');
        return escapeHtml(html || '');
    }
    return safeHtmlFragment(html || '');
}

/**
 * One titled rich-text section of the detail view, or a "not provided" placeholder when
 * it is empty. The content is the editor's HTML, cleaned by safeChangeHtml().
 */
function renderDetailSection(title, content, field) {
    // Every rich field keeps its full history (#180); the link opens the viewer.
    const history = field && currentChange
        ? `<button type="button" class="detail-history-link" onclick="openRevisionViewer(${currentChange.id}, '${field}')">${escapeHtml(window.t('change-management.revisions.versions'))}</button>`
        : '';
    if (!content || content.trim() === '') {
        return `
            <div class="detail-section">
                <h3>${title}${history}</h3>
                <div class="detail-section-body"><span class="detail-section-empty">${window.t('change-management.detail.not_provided')}</span></div>
            </div>
        `;
    }
    return `
        <div class="detail-section">
            <h3>${title}${history}</h3>
            <div class="detail-section-body">${safeChangeHtml(content)}</div>
        </div>
    `;
}

// ============ Risk Matrix ============

// Drawn by ChangeRisk.matrix() (assets/js/change-risk.js), on the policy version the change
// was rated under (#246).

// ============ PIR Detail ============

/**
 * The post-implementation review block - whether it succeeded, actual start and end,
 * lessons learned and follow-up.
 */
function renderPirDetail(c) {
    let html = `<div class="pir-detail-section"><h3>${window.t('change-management.pir.heading')}</h3><div class="pir-detail-grid">`;

    if (c.pir_was_successful !== null && c.pir_was_successful !== '') {
        const successText = parseInt(c.pir_was_successful) === 1 ? window.t('change-management.pir.yes') : window.t('change-management.pir.no');
        const successClass = parseInt(c.pir_was_successful) === 1 ? 'pir-success' : 'pir-fail';
        html += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.pir.successful')}</span><span class="detail-meta-value ${successClass}">${successText}</span></div>`;
    }
    if (c.pir_actual_start) {
        html += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.pir.actual_start')}</span><span class="detail-meta-value">${formatNaiveDateTime(c.pir_actual_start)}</span></div>`;
    }
    if (c.pir_actual_end) {
        html += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.pir.actual_end')}</span><span class="detail-meta-value">${formatNaiveDateTime(c.pir_actual_end)}</span></div>`;
    }
    html += '</div>';

    if (c.pir_lessons_learned) {
        html += `<div class="pir-text-block"><strong>${window.t('change-management.pir.lessons')}</strong><p>${escapeHtml(c.pir_lessons_learned)}</p></div>`;
    }
    if (c.pir_follow_up) {
        html += `<div class="pir-text-block"><strong>${window.t('change-management.pir.follow_up')}</strong><p>${escapeHtml(c.pir_follow_up)}</p></div>`;
    }

    html += '</div>';
    return html;
}

// ============ Activity Timeline ============

/** Loads a change's audit trail and comments together and shows them as one timeline, newest first. */
async function loadActivityTimeline(changeId) {
    const container = document.getElementById('activityTimeline');
    if (!container) return;

    try {
        // Fetch audit trail and comments in parallel
        const [auditRes, commentsRes] = await Promise.all([
            fetch(API_BASE + 'get_audit.php?change_id=' + changeId),
            fetch(API_BASE + 'get_comments.php?change_id=' + changeId)
        ]);

        const auditData = await auditRes.json();
        const commentsData = await commentsRes.json();

        const auditEntries = (auditData.success ? auditData.entries : []).map(e => ({
            type: 'audit',
            datetime: e.created_datetime,
            analyst: e.analyst_name || 'System', // data value (analyst display name fallback)
            action_type: e.action_type,
            field_name: e.field_name,
            old_value: e.old_value,
            new_value: e.new_value
        }));

        const commentEntries = (commentsData.success ? commentsData.comments : []).map(e => ({
            type: 'comment',
            id: e.id,
            datetime: e.created_datetime,
            analyst: e.analyst_name || 'Unknown',
            text: e.comment_text
        }));

        // Merge and sort by datetime descending
        const timeline = [...auditEntries, ...commentEntries]
            .sort((a, b) => new Date(b.datetime) - new Date(a.datetime));

        renderActivityTimeline(timeline, container);
    } catch (error) {
        console.error('Error loading activity:', error);
        container.innerHTML = `<div class="timeline-empty">${window.t('change-management.detail.activity_error')}</div>`;
    }
}

/**
 * Draws the activity timeline - comments, status changes, CAB votes, incident links and
 * field edits.
 */
function renderActivityTimeline(timeline, container) {
    if (!timeline.length) {
        container.innerHTML = `<div class="timeline-empty">${window.t('change-management.detail.no_activity')}</div>`;
        return;
    }

    container.innerHTML = timeline.map(entry => {
        if (entry.type === 'comment') {
            return `
                <div class="timeline-item timeline-comment">
                    <div class="timeline-icon comment-icon">&#128172;</div>
                    <div class="timeline-content">
                        <div class="timeline-header">
                            <strong>${escapeHtml(entry.analyst)}</strong> ${window.t('change-management.detail.commented')}
                            <span class="timeline-date">${formatDateTime(entry.datetime)}</span>
                        </div>
                        <div class="timeline-body">${escapeHtml(entry.text)}</div>
                    </div>
                </div>
            `;
        } else {
            // Audit entry
            let description = '';
            if (entry.action_type === 'status_change') {
                description = window.t('change-management.detail.changed_status', {
                    old: escapeHtml(entry.old_value || ''),
                    new: escapeHtml(entry.new_value || '')
                });
            } else if (entry.action_type === 'comment') {
                return ''; // Already shown as comment entry
            } else if (entry.action_type === 'text_edit' || entry.action_type === 'comment_deleted') {
                // A long field's edit, or a deleted comment: the full text is a revision
                // (#180), and the audit row's new_value names it - "Revision #812".
                const revId = parseInt(String(entry.new_value || '').replace(/\D+/g, ''), 10) || null;
                const field = entry.action_type === 'comment_deleted' ? 'comment' : (REVISION_FIELD_BY_LABEL[entry.field_name] || null);
                description = entry.action_type === 'comment_deleted'
                    ? escapeHtml(window.t('change-management.timeline.deleted_comment'))
                    : window.t('change-management.timeline.edited_text', {
                        field: `<strong>${escapeHtml(field ? window.t('change-management.revisions.field.' + field) : (entry.field_name || ''))}</strong>`
                    });
                if (field && revId && currentChange) {
                    description += ` <button type="button" class="timeline-view-link" onclick="openRevisionViewer(${currentChange.id}, '${field}', ${revId})">${escapeHtml(window.t('change-management.revisions.view'))}</button>`;
                }
            } else if (entry.action_type === 'evidence_export') {
                description = escapeHtml(window.t('change-management.timeline.exported_evidence'));
            } else if (entry.action_type === 'attachment') {
                description = entry.new_value
                    ? escapeHtml(window.t('change-management.timeline.attached', { file: entry.new_value }))
                    : escapeHtml(window.t('change-management.timeline.removed_attachment', { file: entry.old_value || '' }));
            } else if (entry.action_type === 'cab_vote') {
                description = `<strong>${escapeHtml(entry.field_name || 'CAB')}</strong>`;
                if (entry.new_value) description += `: <span class="new-val">${escapeHtml(entry.new_value)}</span>`;
                if (entry.old_value) description += ` <span class="old-val">(${escapeHtml(entry.old_value)})</span>`;
            } else if (entry.field_name === 'Linked incident') {
                description = entry.new_value
                    ? `${window.t('change-management.detail.linked_incident')} <span class="new-val">${escapeHtml(entry.new_value)}</span>`
                    : `${window.t('change-management.detail.unlinked_incident')} <span class="old-val">${escapeHtml(entry.old_value)}</span>`;
            } else {
                description = `changed <strong>${escapeHtml(entry.field_name || '')}</strong>`;
                if (entry.old_value && entry.new_value) {
                    description += ` from <span class="old-val">${escapeHtml(entry.old_value)}</span> to <span class="new-val">${escapeHtml(entry.new_value)}</span>`;
                } else if (entry.new_value) {
                    description += ` to <span class="new-val">${escapeHtml(entry.new_value)}</span>`;
                } else if (entry.old_value) {
                    description += ` (was <span class="old-val">${escapeHtml(entry.old_value)}</span>)`;
                }
            }

            const icon = entry.action_type === 'status_change' ? '&#9654;' : entry.action_type === 'cab_vote' ? '&#9734;' : '&#9998;';
            const itemClass = entry.action_type === 'status_change' ? 'timeline-status' : entry.action_type === 'cab_vote' ? 'timeline-cab' : 'timeline-field';

            return `
                <div class="timeline-item ${itemClass}">
                    <div class="timeline-icon">${icon}</div>
                    <div class="timeline-content">
                        <div class="timeline-header">
                            <strong>${escapeHtml(entry.analyst)}</strong> ${description}
                            <span class="timeline-date">${formatDateTime(entry.datetime)}</span>
                        </div>
                    </div>
                </div>
            `;
        }
    }).join('');
}

/** Adds the typed comment to the current change and reloads the timeline. */
async function postComment() {
    if (!currentChange) return;

    const input = document.getElementById('commentInput');
    const text = input.value.trim();
    if (!text) {
        showToast(window.t('change-management.toast.comment_empty'), 'success');
        return;
    }

    try {
        const response = await fetch(API_BASE + 'save_comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ change_id: currentChange.id, comment_text: text })
        });
        const data = await response.json();

        if (data.success) {
            input.value = '';
            showToast(window.t('change-management.toast.comment_added'), 'success');
            loadActivityTimeline(currentChange.id);
        } else {
            showToast(window.t('change-management.toast.error_prefix', { message: data.error }), 'success');
        }
    } catch (error) {
        showToast(window.t('change-management.toast.comment_error'), 'success');
    }
}

// ============ Filtering & Search ============

/** Filters the change list to one status (or all) and highlights its filter row. */
function filterByStatus(status) {
    currentFilter = status;
    document.querySelectorAll('.status-filter').forEach(el => {
        el.classList.toggle('active', el.dataset.status === status);
    });
    loadChanges();
}

// ============ Search Modal ============

let searchModalOffsetX = 0;
let searchModalOffsetY = 0;

/** Opens the search panel next to the search button and focuses the change number box. */
function openSearchModal() {
    const modal = document.getElementById('searchModal');
    modal.classList.add('active');

    // Position near the search button
    const searchBtn = document.querySelector('.search-btn');
    if (searchBtn) {
        const btnRect = searchBtn.getBoundingClientRect();
        modal.style.left = btnRect.left + 'px';
        modal.style.top = (btnRect.bottom + 10) + 'px';
        modal.style.transform = 'none';
    } else {
        modal.style.left = '50%';
        modal.style.top = '100px';
        modal.style.transform = 'translateX(-50%)';
    }

    initSearchModalDrag();
    document.getElementById('searchChangeNumber').focus();
}

/** Closes the search panel. */
function closeSearchModal() {
    document.getElementById('searchModal').classList.remove('active');
}

// Escape always closes the panel; clicking away closes it only if the analyst
// turned that on (System > Preferences > Display). #144. No reading-pane
// exception here: clicking a result opens the change in a detail view that
// covers the screen, and closeSearchModal() is already called for it.
document.addEventListener('DOMContentLoaded', function () {
    if (window.initSearchPanelDismiss && document.getElementById('searchModal')) {
        window.initSearchPanelDismiss({ panelId: 'searchModal', close: closeSearchModal });
    }
});

/** Lets the search panel be dragged by its header. */
function initSearchModalDrag() {
    const header = document.getElementById('searchModalHeader');
    const modal = document.getElementById('searchModal');

    /** Starts dragging the panel from where the header was pressed (not on a button). */
    header.onmousedown = function(e) {
        if (e.target.tagName === 'BUTTON') return;
        e.preventDefault();
        const rect = modal.getBoundingClientRect();
        searchModalOffsetX = e.clientX - rect.left;
        searchModalOffsetY = e.clientY - rect.top;

        /** Moves the panel with the mouse. */
        function onMouseMove(e) {
            modal.style.left = (e.clientX - searchModalOffsetX) + 'px';
            modal.style.top = (e.clientY - searchModalOffsetY) + 'px';
            modal.style.transform = 'none';
        }
        /** Ends the drag. */
        function onMouseUp() {
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
        }
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    };
}

/** Searches changes by number (CHG-0001 or plain digits) or title and shows the results. */
async function performSearch() {
    const changeNumber = document.getElementById('searchChangeNumber').value.trim();
    const title = document.getElementById('searchChangeTitle').value.trim();

    if (!changeNumber && !title) {
        showToast(window.t('change-management.search.need_criterion'), 'error');
        return;
    }

    const resultsContainer = document.getElementById('searchResults');
    resultsContainer.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

    // Build search query — extract numeric ID from CHG-0001 format
    let searchId = '';
    if (changeNumber) {
        searchId = changeNumber.replace(/^CHG-0*/i, '').replace(/^0+/, '') || changeNumber;
    }

    try {
        let url = API_BASE + 'list.php?';
        if (searchId || title) {
            url += 'search=' + encodeURIComponent(title || searchId);
        }

        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            let results = data.changes || [];

            // If searching by change number, filter more precisely
            if (searchId && !title) {
                results = results.filter(c => String(c.id) === searchId || String(c.id).includes(searchId));
            }

            renderSearchResults(results);
        } else {
            resultsContainer.innerHTML = `<div class="search-results-empty">${window.t('change-management.search.error', { message: data.error || 'Unknown' })}</div>`;
        }
    } catch (e) {
        console.error(e);
        resultsContainer.innerHTML = `<div class="search-results-empty">${window.t('change-management.search.failed')}</div>`;
    }
}

/** Draws the search results with their count. */
function renderSearchResults(results) {
    const container = document.getElementById('searchResults');

    if (!results || results.length === 0) {
        container.innerHTML = `<div class="search-results-empty">${window.t('change-management.search.no_results')}</div>`;
        return;
    }

    let html = '<div class="search-results-count">' + (results.length === 1
        ? window.t('change-management.search.count', { count: results.length })
        : window.t('change-management.search.count_plural', { count: results.length })) + '</div>';

    results.forEach(c => {
        const ref = 'CHG-' + String(c.id).padStart(4, '0');
        html += `
            <div class="search-result-item" onclick="selectSearchResult(${c.id})">
                <div class="search-result-ticket">${ref}</div>
                <div class="search-result-subject">${escapeHtml(c.title)}</div>
                <div class="search-result-meta">
                    <span>${escapeHtml(c.status)}</span>
                    <span>${escapeHtml(c.change_type)}</span>
                    ${c.assigned_to_name ? '<span>' + escapeHtml(c.assigned_to_name) + '</span>' : ''}
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

/** Closes the search panel and opens the chosen change. */
function selectSearchResult(changeId) {
    closeSearchModal();
    viewChange(changeId);
}

/** Empties the search boxes and results. */
function clearSearch() {
    document.getElementById('searchChangeNumber').value = '';
    document.getElementById('searchChangeTitle').value = '';
    document.getElementById('searchResults').innerHTML = `<div class="search-results-empty">${window.t('change-management.search.empty')}</div>`;
}

// ============ Create / Edit ============

/** Opens a blank change editor with the default status and empty risk, review and CAB fields. */
function openCreateChange() {
    if (window.MODULE_LEVEL === 'read') return;   // Read only (#180): nothing to create
    applyApprovalEditorRules(null);
    loadChangeTemplatePicker();
    document.getElementById('editChangeId').value = '';
    document.getElementById('editorTitle').textContent = window.t('change-management.editor.new');
    document.getElementById('changeTitle').value = '';
    document.getElementById('changeType').value = 'Normal';
    // Prefer the status flagged as default in change_statuses; fall back to
    // the first active status, then to 'Draft' as a last resort.
    const defaultStatus = changeStatuses.find(s => s.is_default);
    const fallbackStatus = defaultStatus ? defaultStatus.name : (changeStatuses[0] ? changeStatuses[0].name : 'Draft');
    document.getElementById('changeStatus').value = fallbackStatus;
    document.getElementById('changePriority').value = 'Medium';
    document.getElementById('changeImpact').value = 'Medium';
    document.getElementById('changeCategory').value = '';
    document.getElementById('changeRequester').value = '';
    document.getElementById('changeAssignedTo').value = '';
    document.getElementById('changeApprover').value = '';
    document.getElementById('changeWorkStart').value = '';
    document.getElementById('changeWorkEnd').value = '';
    document.getElementById('changeOutageStart').value = '';
    document.getElementById('changeOutageEnd').value = '';
    document.getElementById('editorAttachmentList').innerHTML = '';

    // Risk scoring: a new change is rated on the policy in use (#246)
    setEditorRiskScale(ChangeRisk.currentId(), false);
    document.getElementById('riskLikelihood').value = '';
    document.getElementById('riskImpactScore').value = '';
    updateRiskScore();

    // PIR fields
    document.querySelectorAll('input[name="pirWasSuccessful"]').forEach(r => r.checked = false);
    document.getElementById('pirActualStart').value = '';
    document.getElementById('pirActualEnd').value = '';
    document.getElementById('pirLessonsLearned').value = '';
    document.getElementById('pirFollowUp').value = '';
    document.getElementById('pirStructuredFields').style.display = 'none';

    // CAB fields
    document.getElementById('cabRequired').checked = false;
    document.getElementById('cabConfigSection').style.display = 'none';
    document.getElementById('cabApprovalType').value = 'all';
    cabEditorMembers = [];
    renderCabMemberChips();

    initEditors(() => {
        editorIds.forEach(id => {
            const editor = tinymce.get(id);
            if (editor) editor.setContent('');
        });
    });

    showView('editor');
    updatePirVisibility();
}

/**
 * Opens the editor filled with the current change - fields, attachments, risk, review,
 * CAB members and the rich-text sections.
 */
function editCurrentChange() {
    if (!currentChange || window.MODULE_LEVEL === 'read') return;   // Read only (#180)
    document.getElementById('templatePickerWrap').style.display = 'none';   // a template is chosen when a change is raised
    document.getElementById('templateVersionId').value = '';
    const c = currentChange;

    document.getElementById('editChangeId').value = c.id;
    document.getElementById('editorTitle').textContent = window.t('change-management.editor.edit', { ref: String(c.id).padStart(4, '0') });
    document.getElementById('changeTitle').value = c.title || '';
    document.getElementById('changeType').value = c.change_type || 'Normal';
    document.getElementById('changeStatus').value = c.status || 'Draft';
    document.getElementById('changePriority').value = c.priority || 'Medium';
    document.getElementById('changeImpact').value = c.impact || 'Medium';
    document.getElementById('changeCategory').value = c.category || '';
    document.getElementById('changeRequester').value = c.requester_id || '';
    document.getElementById('changeAssignedTo').value = c.assigned_to_id || '';
    document.getElementById('changeApprover').value = c.approver_id || '';
    document.getElementById('changeWorkStart').value = toDatetimeLocal(c.work_start_datetime);
    document.getElementById('changeWorkEnd').value = toDatetimeLocal(c.work_end_datetime);
    document.getElementById('changeOutageStart').value = toDatetimeLocal(c.outage_start_datetime);
    document.getElementById('changeOutageEnd').value = toDatetimeLocal(c.outage_end_datetime);

    // Render existing attachments with delete buttons
    renderEditorAttachments(c.attachments || []);

    // Risk scoring: on the scale it was rated on; an unrated change on the policy in use (#246)
    const rated = !!(c.risk_likelihood || c.risk_impact_score);
    setEditorRiskScale(rated ? (c.risk_policy_version_id || 0) : ChangeRisk.currentId(), false);
    document.getElementById('riskLikelihood').value = c.risk_likelihood || '';
    document.getElementById('riskImpactScore').value = c.risk_impact_score || '';
    updateRiskScore();

    // PIR fields
    document.querySelectorAll('input[name="pirWasSuccessful"]').forEach(r => {
        r.checked = (c.pir_was_successful !== null && c.pir_was_successful !== '' && String(r.value) === String(c.pir_was_successful));
    });
    document.getElementById('pirActualStart').value = toDatetimeLocal(c.pir_actual_start);
    document.getElementById('pirActualEnd').value = toDatetimeLocal(c.pir_actual_end);
    document.getElementById('pirLessonsLearned').value = c.pir_lessons_learned || '';
    document.getElementById('pirFollowUp').value = c.pir_follow_up || '';

    // CAB fields
    const cabReq = parseInt(c.cab_required) === 1;
    document.getElementById('cabRequired').checked = cabReq;
    document.getElementById('cabConfigSection').style.display = cabReq ? '' : 'none';
    document.getElementById('cabApprovalType').value = c.cab_approval_type || 'all';
    cabEditorMembers = (c.cab_members || []).map(m => ({
        analyst_id: parseInt(m.analyst_id),
        name: m.analyst_name || 'Unknown',
        is_required: parseInt(m.is_required)
    }));
    renderCabMemberChips();

    initEditors(() => {
        setEditorContent('editorDescription', c.description || '');
        setEditorContent('editorReason', c.reason_for_change || '');
        setEditorContent('editorRisk', c.risk_evaluation || '');
        setEditorContent('editorTestplan', c.test_plan || '');
        setEditorContent('editorRollback', c.rollback_plan || '');
        setEditorContent('editorPir', c.post_implementation_review || '');
    });

    showView('editor');
    updatePirVisibility();
    applyApprovalEditorRules(c);
}

// The approved templates this analyst may use (#180 §8), for the picker.
let usableTemplates = [];

/** Fills the "From a template" picker on a new change, if any approved template is usable. */
async function loadChangeTemplatePicker() {
    const wrap = document.getElementById('templatePickerWrap');
    document.getElementById('templateVersionId').value = '';
    document.getElementById('templateScope').textContent = '';
    wrap.style.display = 'none';
    try {
        const res = await fetch(API_BASE + 'get_usable_templates.php');
        const data = await res.json();
        usableTemplates = data.success ? data.templates : [];
    } catch (e) { usableTemplates = []; }
    if (!usableTemplates.length) return;
    const T = k => window.t('change-management.templates.' + k);
    document.getElementById('templatePicker').innerHTML = `<option value="">${escapeHtml(T('none_option'))}</option>`
        + usableTemplates.map(t => `<option value="${t.version_id}">${escapeHtml(t.name)} (v${t.version_no})</option>`).join('');
    wrap.style.display = '';
}

/**
 * Fill the editor from a template version. The fields stay editable - but changing a fixed
 * one means the change is no longer the recipe, and it goes to normal approval (the server
 * decides that on submission, comparing the words).
 */
function applyChangeTemplate(versionId) {
    const tpl = usableTemplates.find(t => String(t.version_id) === String(versionId));
    document.getElementById('templateVersionId').value = tpl ? tpl.version_id : '';
    const scope = document.getElementById('templateScope');
    scope.textContent = tpl ? [tpl.scope_use, tpl.scope_not ? '✗ ' + tpl.scope_not : ''].filter(Boolean).join('  ·  ') : '';
    if (!tpl) return;
    const v = tpl.values || {};
    const nameOf = (rows, id) => { const r = rows.find(x => String(x.id) === String(id)); return r ? r.name : null; };
    if (v.change_type_id && nameOf(changeTypes, v.change_type_id)) document.getElementById('changeType').value = nameOf(changeTypes, v.change_type_id);
    if (v.priority_id && nameOf(changePriorities, v.priority_id)) document.getElementById('changePriority').value = nameOf(changePriorities, v.priority_id);
    if (v.impact_id && nameOf(changeImpacts, v.impact_id)) document.getElementById('changeImpact').value = nameOf(changeImpacts, v.impact_id);
    // A template's ratings belong to the policy they were given under (#246): on another
    // scale they are left for the analyst, and the server leaves them out the same way.
    if ((tpl.risk_policy_version_id || 0) === (editorRisk.version || 0)) {
        if (v.risk_likelihood) document.getElementById('riskLikelihood').value = v.risk_likelihood;
        if (v.risk_impact_score) document.getElementById('riskImpactScore').value = v.risk_impact_score;
    } else if (v.risk_likelihood || v.risk_impact_score) {
        showToast(window.t('change-management.risk_policy.template_other_scale'), 'info');
    }
    updateRiskScore();
    const set = (id, val) => { if (val) setEditorContent(id, val); };
    set('editorDescription', v.description);
    set('editorReason', v.reason_for_change);
    set('editorRisk', v.risk_evaluation);
    set('editorTestplan', v.test_plan);
    set('editorRollback', v.rollback_plan);
}

/**
 * Enforce mode in the editor (#180): offer only the statuses the guard would accept, and
 * show the approval set-up read-only once the change is submitted. Comfort, not the
 * control - ChangesService refuses the same moves, and the API meets the same guard.
 * Called with null for a new change, which resets everything.
 */
function applyApprovalEditorRules(c) {
    const allowed = c && Array.isArray(c.allowed_status_ids) ? c.allowed_status_ids : null;
    document.querySelectorAll('#changeStatus option').forEach(opt => {
        const st = changeStatuses.find(s => s.name === opt.value);
        opt.disabled = !!(allowed && st && !allowed.includes(parseInt(st.id)));
    });
    const mayChoose = c && c.approval_rules ? c.approval_rules.can_choose_approvers !== false : window.CHG_CAN_CHOOSE_APPROVERS !== false;
    const locked = !!(c && c.approval_rules && c.approval_rules.setup_locked) || !mayChoose;
    ['cabRequired', 'cabApprovalType', 'changeApprover', 'cabMemberSelect'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = locked;
    });
    document.querySelectorAll('#cabConfigSection button, #cabMembersList button').forEach(b => { b.disabled = locked; });
    let note = document.getElementById('approvalLockedNote');
    if (locked && !note) {
        note = document.createElement('div');
        note.id = 'approvalLockedNote';
        note.className = 'approval-note';
        note.textContent = window.t(mayChoose ? 'change-management.approval_panel.locked_note' : 'change-management.approval.cannot_choose_approvers');
        const host = document.getElementById('cabRequired');
        if (host && host.closest('.form-group')) host.closest('.form-group').appendChild(note);
    } else if (!locked && note) {
        note.remove();
    }
}

/**
 * Would this save change a field the approval was given against? Then, where an edit
 * withdraws the approval (Enforce, or Settings say so), the person is told before saving.
 */
function savingWithdrawsApproval(payload) {
    const c = currentChange;
    if (!c || !c.approval || !c.approval.id || !c.approval_rules || !c.approval_rules.edit_withdraws) return false;
    if (!['open', 'approved'].includes(c.approval.state)) return false;
    const norm = v => (v === null || v === undefined) ? '' : String(v).trim();
    const map = {
        title: [payload.title, c.title], description: [payload.description, c.description],
        reason_for_change: [payload.reason_for_change, c.reason_for_change], risk_evaluation: [payload.risk_evaluation, c.risk_evaluation],
        test_plan: [payload.test_plan, c.test_plan], rollback_plan: [payload.rollback_plan, c.rollback_plan],
        risk_likelihood: [payload.risk_likelihood, c.risk_likelihood], risk_impact_score: [payload.risk_impact_score, c.risk_impact_score],
        change_type_id: [payload.change_type, c.change_type], impact_id: [payload.impact, c.impact], priority_id: [payload.priority, c.priority],
        assigned_to_id: [payload.assigned_to_id, c.assigned_to_id],
        work_window: [norm(payload.work_start_datetime) + '|' + norm(payload.work_end_datetime), toDatetimeLocal(c.work_start_datetime) + '|' + toDatetimeLocal(c.work_end_datetime)],
        outage_window: [norm(payload.outage_start_datetime) + '|' + norm(payload.outage_end_datetime), toDatetimeLocal(c.outage_start_datetime) + '|' + toDatetimeLocal(c.outage_end_datetime)],
    };
    return (c.approval_rules.material || []).some(k => map[k] && norm(map[k][0]) !== norm(map[k][1]));
}

/**
 * Leaves the editor without saving, back to the change (or the list), and tidies the
 * address if it was the new-change route.
 */
function cancelEdit() {
    destroyEditors();
    if (currentChange) {
        showView('detail');
    } else {
        showView('list');
    }
    // If we came in via /change-management/new/, swap the URL back to the
    // canonical landing path so it no longer points at the create route
    // after the user has left the editor.
    restoreUrlFromNewRoute();
}

// URL-tidying helper for the /change-management/new/ pretty-URL route.
// Called from cancelEdit() and from the save flow once the editor closes.
function restoreUrlFromNewRoute() {
    const p = window.location.pathname;
    if (p.endsWith('/new/') || p.endsWith('/new/index.php')) {
        try { history.replaceState(null, '', '../'); } catch (e) {}
    }
}

// ============ Save ============

/**
 * Saves the change from the editor, then its CAB members when CAB approval is on, and
 * opens the saved change.
 */
async function saveChange() {
    const title = document.getElementById('changeTitle').value.trim();
    if (!title) {
        showToast(window.t('change-management.toast.title_required'), 'success');
        return;
    }

    const changeId = document.getElementById('editChangeId').value;
    const setupLocked = !!(changeId && currentChange && currentChange.approval_rules
        && (currentChange.approval_rules.setup_locked || currentChange.approval_rules.can_choose_approvers === false))
        || (!changeId && window.CHG_CAN_CHOOSE_APPROVERS === false);

    const payload = {
        id: changeId ? parseInt(changeId) : null,
        title: title,
        change_type: document.getElementById('changeType').value,
        status: document.getElementById('changeStatus').value,
        priority: document.getElementById('changePriority').value,
        impact: document.getElementById('changeImpact').value,
        category: document.getElementById('changeCategory').value,
        requester_id: document.getElementById('changeRequester').value || null,
        assigned_to_id: document.getElementById('changeAssignedTo').value || null,
        approver_id: document.getElementById('changeApprover').value || null,
        work_start_datetime: document.getElementById('changeWorkStart').value || null,
        work_end_datetime: document.getElementById('changeWorkEnd').value || null,
        outage_start_datetime: document.getElementById('changeOutageStart').value || null,
        outage_end_datetime: document.getElementById('changeOutageEnd').value || null,
        description: getEditorContent('editorDescription'),
        reason_for_change: getEditorContent('editorReason'),
        risk_evaluation: getEditorContent('editorRisk'),
        test_plan: getEditorContent('editorTestplan'),
        rollback_plan: getEditorContent('editorRollback'),
        post_implementation_review: getEditorContent('editorPir'),
        risk_likelihood: document.getElementById('riskLikelihood').value || null,
        risk_impact_score: document.getElementById('riskImpactScore').value || null,
        risk_reassess: editorRisk.reassess || undefined,
        pir_was_successful: (() => { const r = document.querySelector('input[name="pirWasSuccessful"]:checked'); return r ? r.value : null; })(),
        pir_actual_start: document.getElementById('pirActualStart').value || null,
        pir_actual_end: document.getElementById('pirActualEnd').value || null,
        pir_lessons_learned: document.getElementById('pirLessonsLearned').value || null,
        pir_follow_up: document.getElementById('pirFollowUp').value || null,
        cab_required: document.getElementById('cabRequired').checked ? 1 : 0,
        cab_approval_type: document.getElementById('cabApprovalType').value
    };
    // Raised from a template (#180 §8): only on a new change.
    if (!changeId && document.getElementById('templateVersionId').value) {
        payload.template_version_id = parseInt(document.getElementById('templateVersionId').value);
    }

    if (changeId && savingWithdrawsApproval(payload)
        && !(await showConfirm({ title: window.t('change-management.approval_panel.heading'),
                                 message: window.t('change-management.approval.material_edit_confirm'),
                                 okLabel: window.t('change-management.editor.save') }))) {
        return;
    }

    try {
        const response = await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();

        if (data.success) {
            // Save CAB members if CAB is enabled - not while the set-up is locked (the
            // service would refuse it anyway, Enforce mode, #180).
            if (payload.cab_required && !setupLocked) {
                await fetch(API_BASE + 'save_cab_members.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        change_id: data.change_id,
                        members: cabEditorMembers.map(m => ({
                            analyst_id: m.analyst_id,
                            is_required: m.is_required
                        }))
                    })
                });
            }

            showToast(window.t('change-management.toast.change_saved'), 'success');
            destroyEditors();
            // If we came in via /change-management/new/, drop the create-route
            // path now the change is saved — viewChange() will show the detail
            // of the just-saved change at the canonical /change-management/ path.
            restoreUrlFromNewRoute();
            // Reload and view the saved change
            await loadChanges();
            await viewChange(data.change_id);
        } else {
            showToast(window.t('change-management.toast.error_prefix', { message: data.error }), 'success');
        }
    } catch (error) {
        console.error('Error saving change:', error);
        showToast(window.t('change-management.toast.error_saving'), 'success');
    }
}

// ============ Delete ============

/** Shows the confirmation dialog for deleting the current change. */
function deleteCurrentChange() {
    if (!currentChange) return;
    const ref = 'CHG-' + String(currentChange.id).padStart(4, '0');

    document.getElementById('deleteModal').innerHTML = `
        <div class="modal-overlay" onclick="closeDeleteModal()">
            <div class="modal-box" onclick="event.stopPropagation()">
                <h3>${window.t('change-management.delete.heading')}</h3>
                <p>${window.t('change-management.delete.confirm', { ref: ref, title: escapeHtml(currentChange.title) })}</p>
                <div class="modal-box-actions">
                    <button class="btn btn-secondary" onclick="closeDeleteModal()">${window.t('change-management.delete.cancel')}</button>
                    <button class="btn btn-danger" onclick="confirmDelete(${currentChange.id})">${window.t('change-management.delete.delete')}</button>
                </div>
            </div>
        </div>
    `;
}

/** Closes the delete confirmation dialog. */
function closeDeleteModal() {
    document.getElementById('deleteModal').innerHTML = '';
}

/** Deletes the change and goes back to the list. */
async function confirmDelete(id) {
    try {
        const response = await fetch(API_BASE + 'delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        const data = await response.json();

        if (data.success) {
            showToast(window.t('change-management.toast.change_deleted'), 'success');
            closeDeleteModal();
            currentChange = null;
            showView('list');
            loadChanges();
        } else {
            showToast(window.t('change-management.toast.error_prefix', { message: data.error }), 'success');
        }
    } catch (error) {
        showToast(window.t('change-management.toast.error_deleting'), 'success');
    }
}

// ============ Attachments ============

/** Wires the attachment area - click to choose files, or drag and drop them. */
function setupFileUpload() {
    const area = document.getElementById('fileUploadArea');
    const input = document.getElementById('fileInput');

    area.addEventListener('click', () => input.click());

    area.addEventListener('dragover', (e) => {
        e.preventDefault();
        area.classList.add('drag-over');
    });

    area.addEventListener('dragleave', () => {
        area.classList.remove('drag-over');
    });

    area.addEventListener('drop', (e) => {
        e.preventDefault();
        area.classList.remove('drag-over');
        if (e.dataTransfer.files.length) {
            uploadFiles(e.dataTransfer.files);
        }
    });

    input.addEventListener('change', () => {
        if (input.files.length) {
            uploadFiles(input.files);
            input.value = '';
        }
    });
}

/**
 * Uploads files to the change being edited one at a time, refreshing its attachment list
 * after each. A new change has to be saved first.
 */
async function uploadFiles(files) {
    const changeId = document.getElementById('editChangeId').value;
    if (!changeId) {
        showToast(window.t('change-management.toast.save_first'), 'success');
        return;
    }

    for (const file of files) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('change_id', changeId);

        try {
            const response = await fetch(API_BASE + 'upload_attachment.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                showToast(window.t('change-management.toast.file_uploaded', { name: file.name }), 'success');
                // Refresh the change to get updated attachments
                const refreshResp = await fetch(API_BASE + 'get.php?id=' + changeId);
                const refreshData = await refreshResp.json();
                if (refreshData.success) {
                    currentChange = refreshData.change;
                    renderEditorAttachments(currentChange.attachments || []);
                }
            } else {
                showToast(window.t('change-management.toast.upload_failed', { message: data.error }), 'success');
            }
        } catch (error) {
            showToast(window.t('change-management.toast.upload_error', { name: file.name }), 'success');
        }
    }
}

/** Lists the change's attachments in the editor with download and delete buttons. */
function renderEditorAttachments(attachments) {
    const container = document.getElementById('editorAttachmentList');
    if (!attachments.length) {
        container.innerHTML = '';
        return;
    }

    container.innerHTML = attachments.map(a => `
        <div class="attachment-item">
            <div class="attachment-info">
                <span class="attachment-icon">&#128206;</span>
                <span class="attachment-name">${escapeHtml(a.file_name)}</span>
                <span class="attachment-size">${formatFileSize(a.file_size)}</span>
            </div>
            <div class="attachment-actions">
                <button onclick="downloadAttachment(${a.id})" title="${window.t('change-management.detail.download')}">&#8595;</button>
                <button class="delete-btn" onclick="deleteAttachment(${a.id})" title="${window.t('change-management.detail.delete_attachment')}">&#10005;</button>
            </div>
        </div>
    `).join('');
}

/** Opens an attachment in a new tab. */
function downloadAttachment(id) {
    window.open(API_BASE + 'get_attachment.php?id=' + id, '_blank');
}

/** Asks for confirmation, deletes an attachment and refreshes the list. */
async function deleteAttachment(id) {
    if (!(await showConfirm({ title: window.t('change-management.delete.delete'), message: window.t('change-management.delete.attachment_confirm'), okLabel: window.t('change-management.delete.delete'), okClass: 'danger' }))) return;

    try {
        const response = await fetch(API_BASE + 'delete_attachment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        const data = await response.json();

        if (data.success) {
            showToast(window.t('change-management.toast.attachment_deleted'), 'success');
            // Refresh attachments
            const changeId = document.getElementById('editChangeId').value;
            if (changeId) {
                const refreshResp = await fetch(API_BASE + 'get.php?id=' + changeId);
                const refreshData = await refreshResp.json();
                if (refreshData.success) {
                    currentChange = refreshData.change;
                    renderEditorAttachments(currentChange.attachments || []);
                }
            }
        } else {
            showToast(window.t('change-management.toast.error_prefix', { message: data.error }), 'success');
        }
    } catch (error) {
        showToast(window.t('change-management.toast.attachment_error'), 'success');
    }
}

// ============ TinyMCE ============

/**
 * Starts a TinyMCE editor for each rich-text field, matching the light or dark theme,
 * and calls callback once they are all ready.
 */
function initEditors(callback) {
    // Destroy existing instances first
    destroyEditors();

    let initialized = 0;
    const total = editorIds.length;

    // Match the editor chrome + content area to the active palette. TinyMCE
    // renders in an iframe, so we swap its bundled oxide-dark UI skin + dark
    // content CSS by the palette's declared mode (data-theme-mode on <html>) —
    // same approach as the tickets reply editor (inbox.js). Any new palette
    // works with no change here.
    const isDark = (document.documentElement.getAttribute('data-theme-mode') || 'light') === 'dark';

    editorIds.forEach(id => {
        tinymce.init({
            // Plugins and skins load without the page's ?v=, so a browser could pair a new
            // editor with old cached plugins after an update. TinyMCE's own version stamps them.
            cache_suffix: '?v=' + tinymce.majorVersion + '.' + tinymce.minorVersion,
            selector: '#' + id,
            license_key: 'gpl',
            height: 300,
            menubar: false,
            skin: isDark ? 'oxide-dark' : 'oxide',
            content_css: isDark ? 'dark' : 'default',
            plugins: ['advlist', 'autolink', 'lists', 'link', 'table', 'wordcount'],
            toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link table | removeformat',
            content_style: 'body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; } @media (pointer: coarse) { body { font-size: 16px; } }',
            /**
             * Counts each editor as it finishes starting, and runs the callback when the
             * last one is ready.
             */
            setup: function(editor) {
                editor.on('init', function() {
                    initialized++;
                    if (initialized === total) {
                        editorsReady = true;
                        if (callback) callback();
                    }
                });
            }
        });
    });
}

/** Removes every TinyMCE editor. */
function destroyEditors() {
    editorsReady = false;
    editorIds.forEach(id => {
        const editor = tinymce.get(id);
        if (editor) editor.remove();
    });
}

/** The HTML in one rich-text editor - empty when it is not running. */
function getEditorContent(id) {
    const editor = tinymce.get(id);
    return editor ? editor.getContent() : '';
}

/** Puts HTML into one rich-text editor. */
function setEditorContent(id, content) {
    const editor = tinymce.get(id);
    if (editor) {
        editor.setContent(content || '');
    }
}

// ============ Rich Text Tabs ============

/** Shows one rich-text tab and its panel. */
function switchTab(tab) {
    // Update tab buttons
    document.querySelectorAll('.rich-text-tab').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');

    // Show correct panel
    document.querySelectorAll('.rich-text-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + tab).classList.add('active');
}

// ============ Risk Scoring & PIR Visibility ============

/**
 * The scale the editor is rating on (#246): the policy version a change was rated under,
 * or the one in use for a new or unrated change. `reassess` is set by Re-assess, which
 * moves an older change onto the current policy deliberately - the server needs both
 * ratings then, and records the move.
 */
let editorRisk = { version: 0, reassess: false };

/** Puts a version's levels in the two selects and its criteria in the guide, with the "older policy" note. */
function setEditorRiskScale(versionId, reassess) {
    editorRisk = { version: versionId || 0, reassess: !!reassess };
    ChangeRisk.fillSelects(editorRisk.version, document.getElementById('riskLikelihood'), document.getElementById('riskImpactScore'));
    const body = document.getElementById('riskGuideRows');
    if (body) body.innerHTML = ChangeRisk.guideRows(editorRisk.version);
    const p = ChangeRisk.policy(editorRisk.version);
    const note = document.getElementById('riskGuideNote');
    if (note) note.textContent = ChangeRisk.isBuiltin(p)
        ? window.t('change-management.editor.risk_help_note')
        : window.t('change-management.risk_policy.guide_note', { name: p.name || '' });
    const example = document.getElementById('riskGuideExample');
    if (example) example.style.display = ChangeRisk.isBuiltin(p) ? '' : 'none';
    const older = document.getElementById('riskOlderPolicy');
    if (older) {
        const isOlder = editorRisk.version !== (ChangeRisk.currentId() || 0);
        older.hidden = !isOlder;
        if (isOlder) document.getElementById('riskOlderPolicyText').textContent = window.t('change-management.risk_policy.older_note', {
            name: ChangeRisk.isBuiltin(p) ? window.t('change-management.risk_policy.builtin_name') : (p.name || '') });
    }
}

/** Re-assess: clear the ratings and offer the policy in use now. */
function reassessRisk() {
    document.getElementById('riskLikelihood').value = '';
    document.getElementById('riskImpactScore').value = '';
    setEditorRiskScale(ChangeRisk.currentId(), true);
    updateRiskScore();
    document.getElementById('riskLikelihood').focus();
}

/**
 * GH #231: the ? beside Risk likelihood and Risk impact opens the rating guide
 * under them, with that field's explanation marked; pressed again, or Escape, or
 * the close button, it closes and focus goes back to the button.
 */
(function () {
    let opener = null;
    function close() {
        const g = document.getElementById('riskGuide');
        if (!g || g.hidden) return;
        g.hidden = true;
        document.querySelectorAll('.cm-risk-help-btn').forEach(b => b.setAttribute('aria-expanded', 'false'));
        if (opener) opener.focus();
        opener = null;
    }
    function open(btn) {
        const g = document.getElementById('riskGuide');
        const part = btn.dataset.riskHelp;
        g.hidden = false;
        document.querySelectorAll('.cm-risk-help-btn').forEach(b => b.setAttribute('aria-expanded', b === btn ? 'true' : 'false'));
        g.querySelectorAll('[data-risk-part]').forEach(p => p.classList.toggle('is-current', p.dataset.riskPart === part));
        opener = btn;
        g.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
    document.addEventListener('click', e => {
        const btn = e.target.closest('.cm-risk-help-btn');
        if (btn) {
            const g = document.getElementById('riskGuide');
            if (!g.hidden && opener === btn) close(); else open(btn);
            return;
        }
        if (e.target.closest('.cm-risk-guide-close')) close();
    });
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        const g = document.getElementById('riskGuide');
        if (g && !g.hidden && (g.contains(document.activeElement) || document.activeElement === opener)) { e.stopPropagation(); close(); }
    }, true);
})();

/** Shows the score and the result the policy's matrix gives the two ratings - never the score banded. */
function updateRiskScore() {
    const likelihood = parseInt(document.getElementById('riskLikelihood').value) || 0;
    const impact = parseInt(document.getElementById('riskImpactScore').value) || 0;
    const display = document.getElementById('riskScoreDisplay');
    const p = ChangeRisk.policy(editorRisk.version);
    const r = likelihood && impact ? ChangeRisk.resultFor(p, likelihood, impact) : null;
    if (r) {
        const pt = ChangeRisk.paint(r);
        display.textContent = (likelihood * impact) + ' - ' + ChangeRisk.resultName(r);
        display.className = 'risk-score-display ' + pt.cls;
        display.style.cssText = pt.style;
    } else {
        display.textContent = '-';
        display.className = 'risk-score-display';
        display.style.cssText = '';
    }
}

/** Shows the post-implementation review fields only when the chosen status is in the Done stage. */
function updatePirVisibility() {
    const status = document.getElementById('changeStatus').value;
    const pirSection = document.getElementById('pirStructuredFields');
    if (pirSection) {
        pirSection.style.display = statusStage(status) === 'done' ? '' : 'none';
    }
}

/**
 * The stage of a status, by name, from the loaded statuses (draft, awaiting, approved,
 * implementing, done, stopped), or null when it has none. TRAP: decide by the stage,
 * never by a status name - names are editable in Settings.
 */
function statusStage(name) {
    const s = changeStatuses.find(x => x.name === name);
    return s && s.stage ? s.stage : null;
}

// ============ CAB Editor Functions ============

/** Shows or hides the CAB settings with the CAB required tick. */
function toggleCabConfig() {
    const checked = document.getElementById('cabRequired').checked;
    document.getElementById('cabConfigSection').style.display = checked ? '' : 'none';
}

/** Adds the analyst chosen in the dropdown to the CAB members, as required. */
function addCabMember() {
    const select = document.getElementById('cabMemberSelect');
    const analystId = parseInt(select.value);
    if (!analystId) return;

    // Check if already added
    if (cabEditorMembers.some(m => m.analyst_id === analystId)) {
        showToast(window.t('change-management.toast.already_added'), 'success');
        return;
    }

    const name = select.options[select.selectedIndex].text;
    cabEditorMembers.push({ analyst_id: analystId, name: name, is_required: 1 });
    select.value = '';
    renderCabMemberChips();
}

/** Removes an analyst from the CAB members. */
function removeCabMember(analystId) {
    cabEditorMembers = cabEditorMembers.filter(m => m.analyst_id !== analystId);
    renderCabMemberChips();
}

/** Switches a CAB member between required and optional. */
function toggleCabMemberRequired(analystId) {
    const member = cabEditorMembers.find(m => m.analyst_id === analystId);
    if (member) {
        member.is_required = member.is_required ? 0 : 1;
        renderCabMemberChips();
    }
}

/** Draws the CAB members as chips with their required switch and a remove button. */
function renderCabMemberChips() {
    const container = document.getElementById('cabMembersList');
    if (!container) return;

    if (!cabEditorMembers.length) {
        container.innerHTML = `<div style="color: #999; font-size: 13px; padding: 8px 0;">${window.t('change-management.cab.no_members')}</div>`;
        return;
    }

    container.innerHTML = cabEditorMembers.map(m => `
        <div class="cab-member-chip">
            <span class="cab-member-name">${escapeHtml(m.name)}</span>
            <button type="button" class="cab-member-toggle ${m.is_required ? 'required' : 'optional'}" onclick="toggleCabMemberRequired(${m.analyst_id})" title="${window.t('change-management.cab.toggle_required')}">
                ${m.is_required ? window.t('change-management.cab.required') : window.t('change-management.cab.optional')}
            </button>
            <button type="button" class="cab-member-remove" onclick="removeCabMember(${m.analyst_id})" title="${window.t('change-management.cab.remove')}">&times;</button>
        </div>
    `).join('');
}

// ============ CAB Review Panel (Detail View) ============

/**
 * The CAB review panel on the detail view - each member's vote and, for a member who has
 * not voted on a change pending approval, the voting form. Empty when CAB is not
 * required.
 */
/**
 * The Approval panel (#180): the current round (its route and state, and whether the
 * content has moved on since it opened), the CAB vote or the named approver's decision,
 * and the earlier rounds, collapsed. Everything here is drawn from get.php's approval /
 * approval_rounds; the rules it shows are enforced by ChangesService, not here.
 */
function renderApprovalPanel(c) {
    const rounds = c.approval_rounds || [];
    const live = c.approval || {};
    const hasSetup = parseInt(c.cab_required) || c.approver_id || c.template;
    if (!rounds.length && !hasSetup) return '';

    const T = (k, p) => window.t('change-management.approval_panel.' + k, p);
    const current = rounds.find(r => r.id === live.id) || null;
    const me = parseInt(document.body.dataset.analystId) || 0;
    let html = `<div class="approval-section"><div class="approval-header"><h3>${escapeHtml(T('heading'))}</h3>`;
    if (current) {
        html += `<div class="approval-badges"><span class="approval-badge route">${escapeHtml(T('route_' + current.route))}</span>`
            + `<span class="approval-badge state-${current.state}">${escapeHtml(T('state_' + current.state))}</span>`
            + (current.is_independent === false ? `<span class="approval-badge not-independent">${escapeHtml(T('not_independent'))}</span>` : '')
            + `<span class="approval-round-no">${escapeHtml(T('round', { n: current.round_no }))}</span></div>`;
    } else {
        html += `<div class="approval-badges"><span class="approval-badge">${escapeHtml(T('none_yet'))}</span></div>`;
    }
    html += '</div>';

    if (window._staleNotice) {
        html += window._staleNotice;
    }

    if (current && live.content_changed) {
        const fields = Object.entries(live.changed_fields || {}).map(([field, since]) => ({ field, since }));
        html += `<div class="approval-earlier">${escapeHtml(T(current.state === 'approved' ? 'approved_earlier' : 'edited_since'))}`
            + fields.map(f => ` <button type="button" class="timeline-view-link" onclick="openRevisionViewer(${c.id}, '${f.field}', null, ${f.since})">${escapeHtml(window.t('change-management.revisions.field.' + f.field))}</button>`).join('')
            + '</div>';
    }
    if (c.template) {
        const TM = (k, p) => window.t('change-management.templates.' + k, p);
        const why = c.template.check;
        let line = TM('raised_from', { name: c.template.name, n: c.template.version_no });
        if (current && current.route === 'pre_authorised') line += ' ' + TM('pre_authorised');
        else if (why && why.reason === 'changed') line += ' ' + TM('lost_changed', { fields: (why.changed_fields || []).map(f => window.t('change-management.approval_settings.field_' + f)).join(', ') });
        else if (why && why.reason === 'switched_off') line += ' ' + TM('lost_switched_off');
        else if (why && why.reason === 'version_not_current') line += ' ' + TM('lost_version');
        else if (why && why.reason === 'not_allowed') line += ' ' + TM('lost_not_allowed');
        html += `<div class="approval-note">${escapeHtml(line)}${c.template.scope_use ? `<br><em>${escapeHtml(c.template.scope_use)}</em>` : ''}</div>`;
    }
    if (current && current.route === 'legacy') {
        html += `<div class="approval-note">${escapeHtml(T(current.state === 'open' ? 'legacy_open' : 'legacy_approved'))}</div>`;
    }

    // The CAB, for a CAB round.
    if (parseInt(c.cab_required) && c.cab_members) {
        html += renderCabReviewPanel(c, current);
    }

    // The named approver's own decision (until #180 the Approver was only a label).
    if (current && current.state === 'open' && current.route === 'approver' && c.status_stage === 'awaiting') {
        if (parseInt(c.approver_id) === me) {
            html += `<div class="cab-vote-form">
                <textarea class="form-input" id="approverNote" rows="2" placeholder="${escapeHtml(T('note_placeholder'))}"></textarea>
                <div class="cab-vote-buttons">
                    <button class="btn cab-btn-approve" onclick="submitApproverDecision('approve')">${window.t('change-management.cab.approve')}</button>
                    <button class="btn cab-btn-reject" onclick="submitApproverDecision('reject')">${window.t('change-management.cab.reject')}</button>
                </div></div>`;
        } else {
            html += `<div class="approval-note">${escapeHtml(T('waiting_for', { name: c.approver_name || '' }))}</div>`;
        }
    }
    // The administrator override (Settings → Approvals), never the default action.
    if (current && current.state === 'open' && c.approval_rules && c.approval_rules.override_allowed) {
        html += `<div class="approval-override"><button type="button" class="btn btn-secondary" onclick="openOverrideDialog()">${escapeHtml(T('override'))}</button></div>`;
    }
    if (current && current.state === 'approved' && current.decided_by_name) {
        html += `<div class="approval-note">${escapeHtml(T('decided', { name: current.decided_by_name, date: formatDateTime(current.decided_at) }))}`
            + (current.decision_note ? `: ${escapeHtml(current.decision_note)}` : '') + '</div>';
    }

    // Earlier rounds, collapsed: the history reads approved, edited, re-approved.
    const earlier = rounds.filter(r => !current || r.id !== current.id).reverse();
    if (earlier.length) {
        html += `<details class="approval-history"><summary>${escapeHtml(T('earlier', { n: earlier.length }))}</summary><ul>`
            + earlier.map(r => `<li><strong>${escapeHtml(T('round', { n: r.round_no }))}</strong> · ${escapeHtml(T('route_' + r.route))} · ${escapeHtml(T('state_' + r.state))}`
                + (r.decided_by_name ? ` · ${escapeHtml(T('decided', { name: r.decided_by_name, date: formatDateTime(r.decided_at) }))}` : '')
                + (r.closed_reason ? ` · ${escapeHtml(r.closed_reason)}` : '')
                + (r.votes.length ? ` · ${escapeHtml(T('votes', { n: r.votes.length }))}` : '') + '</li>').join('')
            + '</ul></details>';
    }
    html += '</div>';
    return html;
}


/** The named approver approves or rejects, against the version this page was drawn with. */
async function submitApproverDecision(decision) {
    if (!currentChange) return;
    const note = document.getElementById('approverNote')?.value?.trim() || '';
    try {
        const res = await fetch(API_BASE + 'decide_approval.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                change_id: currentChange.id, decision, note,
                approval_id: currentChange.approval ? currentChange.approval.id : null,
                content_hash: currentChange.approval ? currentChange.approval.current_version : null
            })
        });
        const data = await res.json();
        if (!data.success && data.code === 'approval_stale') { await handleStaleApproval(note, 'approverNote'); return; }
        if (data.success) {
            showToast(window.t('change-management.approval_panel.decided_toast'), 'success');
            await viewChange(currentChange.id);
            loadChanges();
        } else {
            showToast(window.t('change-management.toast.error_prefix', { message: data.error }), 'error');
        }
    } catch (e) {
        showToast(window.t('change-management.toast.vote_error'), 'error');
    }
}

/**
 * A vote or decision refused because the change was edited after this page was drawn
 * (#180 §5): reload it, say what changed, keep what they had typed, and let them vote
 * again - knowingly - against the new version.
 */
async function handleStaleApproval(typed, inputId) {
    const before = currentChange;
    await viewChange(before.id);
    const after = currentChange;
    const longFields = ['description', 'reason_for_change', 'risk_evaluation', 'test_plan', 'rollback_plan'];
    const changed = longFields.filter(f => (before[f] || '') !== (after[f] || ''));
    const T = (k, p) => window.t('change-management.approval_panel.' + k, p);
    window._staleNotice = `<div class="approval-stale">${escapeHtml(T('stale'))}`
        + changed.map(f => ` <button type="button" class="timeline-view-link" onclick="openRevisionViewer(${after.id}, '${f}')">${escapeHtml(window.t('change-management.revisions.field.' + f))}</button>`).join('')
        + '</div>';
    await viewChange(after.id);
    window._staleNotice = '';
    const box = document.getElementById(inputId);
    if (box) box.value = typed;
}

/** The administrator override dialog: a written reason is required and recorded. */
function openOverrideDialog() {
    if (!currentChange) return;
    const T = (k, p) => window.t('change-management.approval_panel.' + k, p);
    document.getElementById('deleteModal').innerHTML = `
        <div class="modal-overlay" onclick="closeDeleteModal()">
            <div class="modal-box" onclick="event.stopPropagation()">
                <h3>${escapeHtml(T('override_heading'))}</h3>
                <p>${escapeHtml(T('override_explain'))}</p>
                <textarea class="form-input" id="overrideReason" rows="3" placeholder="${escapeHtml(T('override_reason'))}"></textarea>
                <div class="modal-box-actions">
                    <button class="btn btn-secondary" onclick="closeDeleteModal()">${escapeHtml(T('back'))}</button>
                    <button class="btn btn-primary" onclick="confirmOverride(${currentChange.id})">${escapeHtml(T('override'))}</button>
                </div>
            </div>
        </div>`;
}

/** Sends the override and reloads the change. */
async function confirmOverride(id) {
    const reason = document.getElementById('overrideReason')?.value?.trim() || '';
    try {
        const res = await fetch(API_BASE + 'override_approval.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ change_id: id, reason })
        });
        const data = await res.json();
        if (data.success) {
            closeDeleteModal();
            showToast(window.t('change-management.approval_panel.override_toast'), 'success');
            await viewChange(id);
            loadChanges();
        } else {
            showToast(window.t('change-management.toast.error_prefix', { message: data.error }), 'error');
        }
    } catch (e) {
        showToast(window.t('change-management.toast.vote_error'), 'error');
    }
}

/** Cancel instead of Delete: the change keeps every record (#180 §4). */
function cancelCurrentChange() {
    if (!currentChange) return;
    const T = (k, p) => window.t('change-management.approval_panel.' + k, p);
    document.getElementById('deleteModal').innerHTML = `
        <div class="modal-overlay" onclick="closeDeleteModal()">
            <div class="modal-box" onclick="event.stopPropagation()">
                <h3>${escapeHtml(T('cancel_heading'))}</h3>
                <p>${escapeHtml(T('cancel_explain'))}</p>
                <textarea class="form-input" id="cancelReason" rows="2" placeholder="${escapeHtml(T('cancel_reason'))}"></textarea>
                <div class="modal-box-actions">
                    <button class="btn btn-secondary" onclick="closeDeleteModal()">${escapeHtml(T('back'))}</button>
                    <button class="btn btn-danger" onclick="confirmCancelChange(${currentChange.id})">${escapeHtml(T('cancel'))}</button>
                </div>
            </div>
        </div>`;
}

/** Sends the cancel and reloads the change. */
async function confirmCancelChange(id) {
    const reason = document.getElementById('cancelReason')?.value?.trim() || '';
    try {
        const res = await fetch(API_BASE + 'cancel.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ change_id: id, reason })
        });
        const data = await res.json();
        closeDeleteModal();
        if (data.success) {
            showToast(window.t('change-management.approval_panel.cancelled_toast'), 'success');
            await viewChange(id);
            loadChanges();
        } else {
            showToast(window.t('change-management.toast.error_prefix', { message: data.error }), 'error');
        }
    } catch (e) {
        showToast(window.t('change-management.toast.error_deleting'), 'error');
    }
}

function renderCabReviewPanel(c, round) {
    if (!parseInt(c.cab_required) || !c.cab_members) return '';
    // This round's votes, by voter: whether each counted and was cast on the current version.
    const roundVotes = {};
    ((round && round.votes) || []).forEach(v => { roundVotes[v.analyst_id] = v; });

    const members = c.cab_members;
    const requiredMembers = members.filter(m => parseInt(m.is_required));
    const requiredApproved = requiredMembers.filter(m => m.vote === 'Approve').length;
    const approvalType = c.cab_approval_type === 'majority' ? window.t('change-management.cab.type_majority') : window.t('change-management.cab.type_all');
    const currentAnalystId = parseInt(document.body.dataset.analystId) || 0;
    const myMembership = members.find(m => parseInt(m.analyst_id) === currentAnalystId);
    const canVote = myMembership && !myMembership.vote && c.status_stage === 'awaiting' && round && round.state === 'open';

    let html = `
        <div class="cab-review-section">
            <div class="cab-review-header">
                <h3>${window.t('change-management.cab.review')}</h3>
                <div class="cab-review-meta">
                    <span class="cab-progress-badge">${window.t('change-management.cab.progress', { approved: requiredApproved, total: requiredMembers.length })}</span>
                    <span class="cab-approval-type">${approvalType}</span>
                </div>
            </div>
            <div class="cab-members-grid">
    `;

    members.forEach(m => {
        const isReq = parseInt(m.is_required);
        const voteClass = m.vote ? m.vote.toLowerCase() : 'pending';
        const voteLabel = m.vote || window.t('change-management.cab.pending');

        html += `
            <div class="cab-member-card vote-${voteClass}">
                <div class="cab-member-card-header">
                    <span class="cab-member-card-name">${escapeHtml(m.analyst_name || 'Unknown')}</span>
                    <span class="cab-member-badge ${isReq ? 'required' : 'optional'}">${isReq ? window.t('change-management.cab.required') : window.t('change-management.cab.optional')}</span>
                </div>
                <div class="cab-member-card-vote">
                    <span class="cab-vote-status vote-${voteClass}">${voteLabel}</span>
                    ${m.vote_datetime ? `<span class="cab-vote-date">${formatDateTime(m.vote_datetime)}</span>` : ''}
                </div>
                ${m.vote_comment ? `<div class="cab-vote-comment">${escapeHtml(m.vote_comment)}</div>` : ''}
                ${m.vote && roundVotes[m.analyst_id] && !roundVotes[m.analyst_id].is_counted ? `<div class="cab-vote-flag">${escapeHtml(window.t('change-management.approval_panel.not_counted'))}</div>` : ''}
                ${m.vote && roundVotes[m.analyst_id] && roundVotes[m.analyst_id].was_current === false ? `<div class="cab-vote-flag">${escapeHtml(window.t('change-management.approval_panel.earlier_version_vote'))}</div>` : ''}
            </div>
        `;
    });

    html += '</div>';

    // Vote form for current user
    if (canVote) {
        html += `
            <div class="cab-vote-form">
                <textarea class="form-input" id="cabVoteComment" rows="2" placeholder="${window.t('change-management.cab.vote_placeholder')}"></textarea>
                <div class="cab-vote-buttons">
                    <button class="btn cab-btn-approve" onclick="submitCabVote('Approve')">${window.t('change-management.cab.approve')}</button>
                    <button class="btn cab-btn-reject" onclick="submitCabVote('Reject')">${window.t('change-management.cab.reject')}</button>
                    <button class="btn cab-btn-abstain" onclick="submitCabVote('Abstain')">${window.t('change-management.cab.abstain')}</button>
                </div>
            </div>
        `;
    }

    html += '</div>';
    return html;
}

/** Records the signed-in analyst's CAB vote with an optional comment and reloads the change. */
async function submitCabVote(vote) {
    if (!currentChange) return;

    const comment = document.getElementById('cabVoteComment')?.value?.trim() || '';

    try {
        const response = await fetch(API_BASE + 'submit_cab_vote.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                change_id: currentChange.id,
                vote: vote,
                vote_comment: comment,
                // TRAP: the values this page was DRAWN with - never re-fetched before
                // posting, or the check becomes "approve whatever is current" (#180 §5).
                approval_id: currentChange.approval ? currentChange.approval.id : null,
                content_hash: currentChange.approval ? currentChange.approval.current_version : null
            })
        });
        const data = await response.json();

        if (!data.success && data.code === 'approval_stale') {
            await handleStaleApproval(comment, 'cabVoteComment');
            return;
        }
        if (data.success) {
            showToast(window.t('change-management.toast.vote_recorded', { vote: vote }), 'success');
            // Refresh the change detail
            await viewChange(currentChange.id);
            if (data.status_changed) {
                loadChanges();
            }
        } else {
            showToast(window.t('change-management.toast.error_prefix', { message: data.error }), 'success');
        }
    } catch (error) {
        showToast(window.t('change-management.toast.vote_error'), 'success');
    }
}

// ============ View Management ============

/** Shows the list, detail or editor view and hides the others. */
function showView(view) {
    document.getElementById('changeListView').style.display = view === 'list' ? '' : 'none';
    document.getElementById('changeDetailView').style.display = view === 'detail' ? '' : 'none';
    // Editor view uses flex column when visible — set display explicitly
    // so the layout works on first show (CSS doesn't see the bare `display`
    // toggle); body class drives the changes-main override CSS that pins
    // the sticky header / footer.
    const editorEl = document.getElementById('changeEditorView');
    editorEl.style.display = view === 'editor' ? 'flex' : 'none';
    document.body.classList.toggle('cm-editor-open', view === 'editor');
    if (view === 'editor') {
        refreshFormLayout();
    }
}

/** Goes back to the change list and reloads it. */
function backToList() {
    currentChange = null;
    showView('list');
    loadChanges();
}

// ============ Utility Functions ============

// change_audit.field_name of a text edit is the field's English label
// (ChangeRevisions::FIELDS in includes/changes/revisions.php); this maps it back to
// the field key the viewer and the translations use.
const REVISION_FIELD_BY_LABEL = {
    'Description': 'description',
    'Reason for change': 'reason_for_change',
    'Risk evaluation': 'risk_evaluation',
    'Test plan': 'test_plan',
    'Rollback plan': 'rollback_plan',
    'Post-implementation review': 'post_implementation_review',
    'Lessons learned': 'pir_lessons_learned',
    'Follow-up': 'pir_follow_up',
};

/** Escapes text for safe use inside HTML. */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// NAIVE wall-clock scheduling date (only caller is work_start on the list card)
// — shown exactly as typed, no zone conversion (parseNaiveDate from tz.js).
function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = parseNaiveDate(dateStr);
    return fmtNaiveDate(d);
}

// Server-stamped UTC timestamp (created / modified / approved / CAB vote /
// history "when") → analyst display zone (parseUTCDate / tzOpts from tz.js).
function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const d = parseUTCDate(dateStr);
    return fmtDateTime(d);
}

// NAIVE wall-clock scheduling datetime (work / outage / PIR windows) — shown
// exactly as typed for every analyst, no zone conversion. Same output shape as
// formatDateTime so the two are drop-in interchangeable at each call site.
function formatNaiveDateTime(dateStr) {
    if (!dateStr) return '';
    const d = parseNaiveDate(dateStr);
    return fmtNaiveDateTime(d);
}

/**
 * A stored date-time in the YYYY-MM-DDTHH:MM form a datetime-local input expects - blank
 * when it cannot be read.
 */
function toDatetimeLocal(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return '';
    // Format as YYYY-MM-DDTHH:MM for datetime-local input
    const pad = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
           'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

/** A byte count as B, KB or MB. */
function formatFileSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}


// ============ Share Functions ============

/** Opens or closes the Share menu. */
function toggleShareDropdown() {
    const menu = document.getElementById('shareDropdownMenu');
    menu.classList.toggle('active');
}

/** Closes the Share menu. */
function closeShareDropdown() {
    const menu = document.getElementById('shareDropdownMenu');
    if (menu) menu.classList.remove('active');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.querySelector('.share-dropdown');
    if (dropdown && !dropdown.contains(e.target)) {
        closeShareDropdown();
    }
});

/** The current change's reference, such as CHG-0042. */
function getChangeRef() {
    if (!currentChange) return '';
    return 'CHG-' + String(currentChange.id).padStart(4, '0');
}

/** A link straight to the current change. */
function getChangeUrl() {
    return window.location.origin + window.location.pathname + '?change_id=' + currentChange.id;
}

/** Copies the link to the current change to the clipboard. */
function shareChangeLink() {
    closeShareDropdown();
    if (!currentChange) return;
    const url = getChangeUrl();
    // copyToClipboard(), not navigator.clipboard directly — see clipboard.js.
    copyToClipboard(url).then(ok => {
        showToast(
            ok ? window.t('change-management.toast.link_copied')
               : window.t('change-management.toast.link_copy_failed'),
            ok ? 'success' : 'error');
    });
}

/**
 * The change as an A4 PDF, laid out as html2pdf laid it out until 4.1.0: the
 * content drawn by html2canvas at the page's printable width, then cut into
 * page-high slices, each a JPEG on its own page inside 10mm margins.
 *
 * html2pdf did exactly this with a copy of jsPDF bundled inside it, which fell
 * behind the install's own (and its security fixes). Doing the three steps here
 * keeps one jsPDF.
 *
 * TRAP: the content must be IN the document, at the printable width, while it is
 * drawn - html2canvas renders laid-out elements, and a detached one has no size.
 *
 * @param {HTMLElement} content  what buildPdfContent() returns (detached)
 * @returns {Promise<object>} the jsPDF document
 */
async function changePdfDocument(content) {
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
    const margin = 10;
    const pageW = pdf.internal.pageSize.getWidth() - margin * 2;
    const pageH = pdf.internal.pageSize.getHeight() - margin * 2;

    const holder = document.createElement('div');
    // A PDF page is always white, so the text colour is set here rather than
    // inherited from the screen's theme - in dark mode it would be pale grey on white.
    holder.style.cssText = 'position: fixed; left: -10000px; top: 0; background: #fff; color: #333; width: '
        + Math.round(pageW * 96 / 25.4) + 'px;';   // the printable width at 96 CSS px per inch
    holder.appendChild(content);
    document.body.appendChild(holder);
    try {
        const canvas = await html2canvas(content, { scale: 2, backgroundColor: '#ffffff' });
        const pxPerMm = canvas.width / pageW;
        const slice = Math.floor(pageH * pxPerMm);
        const page = document.createElement('canvas');
        page.width = canvas.width;
        for (let y = 0, n = 0; y < canvas.height; y += slice, n++) {
            const h = Math.min(slice, canvas.height - y);
            page.height = h;
            const ctx = page.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, page.width, h);
            ctx.drawImage(canvas, 0, y, canvas.width, h, 0, 0, canvas.width, h);
            if (n > 0) pdf.addPage();
            pdf.addImage(page.toDataURL('image/jpeg', 0.98), 'JPEG', margin, margin, pageW, h / pxPerMm);
        }
    } finally {
        holder.remove();
    }
    return pdf;
}

/** Downloads the current change as a PDF. */
async function shareChangePdf() {
    closeShareDropdown();
    if (!currentChange) return;

    const ref = getChangeRef();
    try {
        const pdf = await changePdfDocument(buildPdfContent());
        pdf.save(`${ref}_${currentChange.title.replace(/[^a-z0-9]/gi, '_')}.pdf`);
    } catch (error) {
        console.error('Error generating PDF:', error);
        showToast(window.t('change-management.toast.pdf_error'), 'error');
    }
}

/** Opens the email dialog for sharing the current change as a link, a PDF or both. */
function shareChangeBoth() {
    closeShareDropdown();
    if (!currentChange) return;

    // Reset form
    document.getElementById('shareEmailTo').value = '';
    document.getElementById('shareEmailMessage').value = '';
    document.getElementById('shareIncludeLink').checked = true;
    document.getElementById('shareIncludePdf').checked = true;

    // Show modal
    document.getElementById('shareEmailModal').classList.add('active');
}

/** Closes the share-by-email dialog. */
function closeShareEmailModal() {
    document.getElementById('shareEmailModal').classList.remove('active');
}

/**
 * Emails the current change to the address given, with the link, the PDF or both, and an
 * optional message.
 */
async function sendShareEmail() {
    const toEmail = document.getElementById('shareEmailTo').value.trim();
    const message = document.getElementById('shareEmailMessage').value.trim();
    const includeLink = document.getElementById('shareIncludeLink').checked;
    const includePdf = document.getElementById('shareIncludePdf').checked;

    if (!toEmail) {
        showToast(window.t('change-management.toast.email_recipient_required'), 'error');
        return;
    }

    if (!includeLink && !includePdf) {
        showToast(window.t('change-management.toast.email_select_one'), 'error');
        return;
    }

    // Generate PDF if needed
    let pdfBase64 = null;
    const ref = getChangeRef();
    const pdfFilename = `${ref}_${currentChange.title.replace(/[^a-z0-9]/gi, '_')}.pdf`;

    if (includePdf) {
        try {
            const pdfBlob = (await changePdfDocument(buildPdfContent())).output('blob');
            pdfBase64 = await blobToBase64(pdfBlob);
        } catch (error) {
            console.error('Error generating PDF:', error);
            showToast(window.t('change-management.toast.pdf_error'), 'error');
            return;
        }
    }

    // Send email via API
    try {
        const response = await fetch(API_BASE + 'send_share_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                to_email: toEmail,
                change_title: currentChange.title,
                change_ref: ref,
                change_url: includeLink ? getChangeUrl() : null,
                message: message,
                pdf_data: pdfBase64,
                pdf_filename: includePdf ? pdfFilename : null
            })
        });

        const data = await response.json();

        if (data.success) {
            closeShareEmailModal();
            showToast(window.t('change-management.toast.email_sent'), 'success');
        } else {
            showToast(window.t('change-management.toast.error_prefix', { message: data.error }), 'error');
        }
    } catch (error) {
        console.error('Error sending email:', error);
        showToast(window.t('change-management.toast.email_error', { message: error.message }), 'error');
    }
}

/** Reads a Blob as base64, without the data: prefix. */
function blobToBase64(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onloadend = () => {
            const base64 = reader.result.split(',')[1];
            resolve(base64);
        };
        reader.onerror = reject;
        reader.readAsDataURL(blob);
    });
}

/**
 * Builds the HTML the change's PDF is made from - header, details and each rich-text
 * section that has content.
 */
function buildPdfContent() {
    const c = currentChange;
    const ref = getChangeRef();

    let html = `
        <div style="font-family: 'Segoe UI', Tahoma, sans-serif; padding: 20px;">
            <h1 style="color: #00897b; margin-bottom: 5px;">${ref}</h1>
            <h2 style="color: #333; margin-top: 0; margin-bottom: 20px;">${escapeHtml(c.title)}</h2>

            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; width: 25%;"><strong>${window.t('change-management.pdf.status')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${escapeHtml(c.status)}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; width: 25%;"><strong>${window.t('change-management.pdf.change_type')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${escapeHtml(c.change_type)}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.priority')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${escapeHtml(c.priority)}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.impact')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${escapeHtml(c.impact)}</td>
                </tr>
                ${c.category ? `<tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.category')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;" colspan="3">${escapeHtml(c.category)}</td>
                </tr>` : ''}
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.requester')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.requester_name ? escapeHtml(c.requester_name) : window.t('change-management.pdf.not_set')}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.assigned_to')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.assigned_to_name ? escapeHtml(c.assigned_to_name) : window.t('change-management.pdf.not_set')}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.approver')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;" colspan="3">${c.approver_name ? escapeHtml(c.approver_name) : window.t('change-management.pdf.not_set')}</td>
                </tr>
                ${c.work_start_datetime || c.work_end_datetime ? `<tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.work_start')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.work_start_datetime ? formatNaiveDateTime(c.work_start_datetime) : window.t('change-management.pdf.not_set')}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.work_end')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.work_end_datetime ? formatNaiveDateTime(c.work_end_datetime) : window.t('change-management.pdf.not_set')}</td>
                </tr>` : ''}
                ${c.outage_start_datetime || c.outage_end_datetime ? `<tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.outage_start')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.outage_start_datetime ? formatNaiveDateTime(c.outage_start_datetime) : window.t('change-management.pdf.not_set')}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.outage_end')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.outage_end_datetime ? formatNaiveDateTime(c.outage_end_datetime) : window.t('change-management.pdf.not_set')}</td>
                </tr>` : ''}
            </table>
    `;

    // Add detail sections
    const sections = [
        { title: window.t('change-management.pdf.description'), content: c.description },
        { title: window.t('change-management.pdf.reason'), content: c.reason_for_change },
        { title: window.t('change-management.pdf.risk_eval'), content: c.risk_evaluation },
        { title: window.t('change-management.pdf.test_plan'), content: c.test_plan },
        { title: window.t('change-management.pdf.rollback_plan'), content: c.rollback_plan },
        { title: window.t('change-management.pdf.pir'), content: c.post_implementation_review }
    ];

    sections.forEach(section => {
        if (section.content && section.content.trim()) {
            html += `
                <h3 style="color: #00897b; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 20px;">${section.title}</h3>
                <div style="line-height: 1.6;">${safeChangeHtml(section.content)}</div>
            `;
        }
    });

    html += `
            <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0 15px 0;">
            <p style="font-size: 11px; color: #888;">
                ${c.created_by_name ? window.t('change-management.pdf.created_by', { datetime: formatDateTime(c.created_datetime), name: c.created_by_name }) : window.t('change-management.pdf.created', { datetime: formatDateTime(c.created_datetime) })}<br>
                ${window.t('change-management.pdf.last_modified', { datetime: formatDateTime(c.modified_datetime) })}
            </p>
        </div>
    `;

    const container = document.createElement('div');
    container.innerHTML = html;
    return container;
}

// Deep-link handling (?change_id= and legacy aliases) lives in the
// DOMContentLoaded handler near the top of this file.

/* --- Right-click context menu for change cards ----------------------------
 * Mirrors the tickets inbox context menu (openTicketContextMenu). Right-click
 * a change card to set its Status / Priority / Type / Impact — or, on
 * multi-company installs, move it to another company — without opening it.
 * Submenus are rebuilt each open from the loaded lookups so newly-added
 * entries + the current-value tick always reflect the latest data.
 */
let ctxTargetChangeId = null;

/**
 * Opens the right-click menu for a change card at the cursor, rebuilding its submenus
 * and keeping it on screen.
 */
function openChangeContextMenu(event, changeId) {
    event.preventDefault();
    if (window.MODULE_LEVEL === 'read') return;   // every item in it is a write (#180)
    ctxTargetChangeId = changeId;
    const menu = document.getElementById('changeContextMenu');
    if (!menu) return;

    const ref = 'CHG-' + String(changeId).padStart(4, '0');
    const header = document.getElementById('changeContextMenuHeader');
    if (header) header.textContent = ref;

    // Rebuild each submenu so newly-added lookups + the current tick appear.
    populateContextChangeStatusSubmenu();
    populateContextChangePrioritySubmenu();
    populateContextChangeTypeSubmenu();
    populateContextChangeImpactSubmenu();
    populateContextChangeCompanySubmenu();

    // Position at cursor — flip if it would overflow the viewport.
    menu.classList.add('active');
    const rect = menu.getBoundingClientRect();
    let x = event.clientX;
    let y = event.clientY;
    if (x + rect.width  > window.innerWidth)  x = window.innerWidth  - rect.width  - 4;
    if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - 4;
    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';

    // Flip the submenu leftward when the parent menu sits near the right edge.
    const SUBMENU_W = 220;
    menu.classList.toggle('flip-sub', x + rect.width + SUBMENU_W > window.innerWidth);
}

/** Closes the change right-click menu. */
function closeChangeContextMenu() {
    const menu = document.getElementById('changeContextMenu');
    if (menu) menu.classList.remove('active');
}

// The change record backing the current context target (for the ✓ tick).
function ctxTargetChange() {
    return changes.find(c => c.id == ctxTargetChangeId) || null;
}

// Shared submenu HTML builder. rows = active lookup rows; currentName = the
// change's current value for this field (name string) so we can tick it.
function renderCtxLookupSubmenu(sub, rows, currentName, emptyKey, onclickFn) {
    if (!sub) return;
    if (!rows.length) {
        sub.innerHTML = '<div class="ticket-context-submenu-item" style="color:#999; font-style: italic; cursor: default;">' + escapeHtml(window.t('change-management.context.' + emptyKey)) + '</div>';
        return;
    }
    sub.innerHTML = rows.map(r => {
        const isCurrent = (r.name === currentName);
        const swatch = r.colour
            ? `<span class="ctx-status-swatch" style="background: ${escapeHtml(r.colour)};"></span>`
            : '<span class="ctx-status-swatch" style="background:#ddd;"></span>';
        const safeName = String(r.name).replace(/'/g, "\\'");
        return `<div class="ticket-context-submenu-item" onclick="${onclickFn}('${safeName}')">
            ${swatch}<span class="ctx-status-name">${escapeHtml(r.name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

/** Fills the right-click Status submenu, ticking the change's current status. */
function populateContextChangeStatusSubmenu() {
    const c = ctxTargetChange();
    renderCtxLookupSubmenu(document.getElementById('ctxChangeStatusSubmenu'), changeStatuses, c ? c.status : '', 'no_statuses', 'setChangeStatusFromContext');
}

/** Fills the right-click Priority submenu, ticking the change's current priority. */
function populateContextChangePrioritySubmenu() {
    const c = ctxTargetChange();
    renderCtxLookupSubmenu(document.getElementById('ctxChangePrioritySubmenu'), changePriorities, c ? c.priority : '', 'no_priorities', 'setChangePriorityFromContext');
}

/** Fills the right-click Type submenu, ticking the change's current type. */
function populateContextChangeTypeSubmenu() {
    const c = ctxTargetChange();
    renderCtxLookupSubmenu(document.getElementById('ctxChangeTypeSubmenu'), changeTypes, c ? c.change_type : '', 'no_types', 'setChangeTypeFromContext');
}

/** Fills the right-click Impact submenu, ticking the change's current impact. */
function populateContextChangeImpactSubmenu() {
    const c = ctxTargetChange();
    renderCtxLookupSubmenu(document.getElementById('ctxChangeImpactSubmenu'), changeImpacts, c ? c.impact : '', 'no_impacts', 'setChangeImpactFromContext');
}

// Build the Move-to-company submenu (multi-company installs only; hidden at N=1).
function populateContextChangeCompanySubmenu() {
    const parent = document.getElementById('ctxChangeCompanyParent');
    const sub = document.getElementById('ctxChangeCompanySubmenu');
    if (!parent || !sub) return;
    if (!isMultiCompany || !moveCompanies.length) {
        parent.style.display = 'none';
        return;
    }
    parent.style.display = '';
    const c = ctxTargetChange();
    const currentTid = c ? (c.tenant_id ?? null) : null;
    sub.innerHTML = moveCompanies.map(co => {
        const isCurrent = (currentTid != null && String(co.id) === String(currentTid));
        return `<div class="ticket-context-submenu-item" onclick="moveChangeToCompanyFromContext(${co.id})">
            <span class="ctx-status-swatch" style="background:#ede7f6; border:none;"></span><span class="ctx-status-name">${escapeHtml(co.name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Field setters. All post to save.php with {id, <field>: <name>}; the change
// service resolves the lookup by name and applies status side-effects.
async function saveChangeFieldFromContext(field, value, toastKey) {
    closeChangeContextMenu();
    const id = ctxTargetChangeId;
    if (!id) return;
    try {
        const res = await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, [field]: value })
        });
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || window.t('change-management.context.update_error'), 'error');
            return;
        }
        showToast(window.t('change-management.context.' + toastKey), 'success');
        loadChanges();
    } catch (e) {
        showToast(window.t('change-management.context.update_error'), 'error');
    }
}

/** Sets the right-clicked change's status. */
function setChangeStatusFromContext(name)   { saveChangeFieldFromContext('status', name, 'status_set'); }
/** Sets the right-clicked change's priority. */
function setChangePriorityFromContext(name) { saveChangeFieldFromContext('priority', name, 'priority_set'); }
/** Sets the right-clicked change's type. */
function setChangeTypeFromContext(name)     { saveChangeFieldFromContext('change_type', name, 'type_set'); }
/** Sets the right-clicked change's impact. */
function setChangeImpactFromContext(name)   { saveChangeFieldFromContext('impact', name, 'impact_set'); }

// Move a change to another company. The endpoint enforces access + writes the
// audit entry server-side, then we reload the list and toast its message.
async function moveChangeToCompanyFromContext(tenantId) {
    closeChangeContextMenu();
    const id = ctxTargetChangeId;
    if (!id) return;
    try {
        const res = await fetch(API_BASE + 'move_to_company.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ change_id: id, tenant_id: tenantId })
        });
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || window.t('change-management.context.move_error'), 'error');
            return;
        }
        showToast(data.message || window.t('change-management.context.move_error'), 'success');
        loadChanges();
    } catch (e) {
        showToast(window.t('change-management.context.move_error'), 'error');
    }
}

// Dismiss the menu on outside-click / scroll / Escape / resize (mirrors tickets).
document.addEventListener('click', function(e) {
    const menu = document.getElementById('changeContextMenu');
    if (menu && menu.classList.contains('active') && !menu.contains(e.target)) {
        closeChangeContextMenu();
    }
});
document.addEventListener('scroll', closeChangeContextMenu, true);
window.addEventListener('resize', closeChangeContextMenu);
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeChangeContextMenu();
});
