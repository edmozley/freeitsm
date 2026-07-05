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

// TinyMCE editor instances
const editorIds = ['editorDescription', 'editorReason', 'editorRisk', 'editorTestplan', 'editorRollback', 'editorPir'];
let editorsReady = false;

// ============ Initialization ============

document.addEventListener('DOMContentLoaded', function() {
    const layoutReady = loadFormLayout();
    loadAnalysts();
    const statusesReady = loadStatuses();
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
            <div class="change-card" onclick="viewChange(${c.id})">
                <div class="change-card-ref">${ref}</div>
                <div class="change-card-info">
                    <div class="change-card-title">${escapeHtml(c.title)}</div>
                    <div class="change-card-meta">
                        <span>${assignedName}</span>
                        ${workStart ? `<span>${window.t('change-management.list.work', { date: workStart })}</span>` : ''}
                    </div>
                </div>
                <div class="change-card-badges">
                    ${c.risk_level ? `<span class="risk-badge risk-${c.risk_level.toLowerCase().replace(/\s+/g, '-')}">${c.risk_level}</span>` : ''}
                    <span class="type-badge ${typeClass}">${c.change_type}</span>
                    <span class="priority-badge ${priorityClass}">${c.priority}</span>
                    <span class="status-badge ${statusClass}">${c.status}</span>
                </div>
            </div>
        `;
    }).join('');
}

// ============ View Change Detail ============

async function viewChange(id) {
    try {
        const response = await fetch(API_BASE + 'get.php?id=' + id);
        const data = await response.json();

        if (!data.success) {
            showToast(window.t('change-management.toast.error_prefix', { message: data.error }), 'success');
            return;
        }

        currentChange = data.change;
        renderChangeDetail();
        showView('detail');
    } catch (error) {
        console.error('Error loading change:', error);
        showToast(window.t('change-management.toast.error_loading'), 'success');
    }
}

function renderChangeDetail() {
    const c = currentChange;
    const ref = 'CHG-' + String(c.id).padStart(4, '0');
    const statusClass = c.status.toLowerCase().replace(/\s+/g, '-');
    const typeClass = c.change_type.toLowerCase();
    const priorityClass = c.priority.toLowerCase();
    const v = isFieldVisible;

    // Build badges
    let badgesHtml = '';
    if (v('status')) badgesHtml += `<span class="status-badge ${statusClass}">${c.status}</span>`;
    if (v('change_type')) badgesHtml += `<span class="type-badge ${typeClass}">${c.change_type}</span>`;
    if (v('priority')) badgesHtml += `<span class="priority-badge ${priorityClass}">${c.priority}</span>`;

    // Build meta grid — only include visible fields
    let metaItems = '';
    if (v('impact')) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.impact')}</span><span class="detail-meta-value">${c.impact}</span></div>`;
    if (v('category') && c.category) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.category')}</span><span class="detail-meta-value">${escapeHtml(c.category)}</span></div>`;
    if (v('requester')) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.requester')}</span><span class="detail-meta-value">${c.requester_name || window.t('change-management.detail.not_set')}</span></div>`;
    if (v('assigned_to')) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.assigned_to')}</span><span class="detail-meta-value">${c.assigned_to_name || window.t('change-management.detail.not_set')}</span></div>`;
    if (v('approver')) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.approver')}</span><span class="detail-meta-value">${c.approver_name || window.t('change-management.detail.not_set')}</span></div>`;
    if (v('approver') && c.approval_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.approved')}</span><span class="detail-meta-value">${formatDateTime(c.approval_datetime)}</span></div>`;
    if (v('work_start') && c.work_start_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.work_start')}</span><span class="detail-meta-value">${formatNaiveDateTime(c.work_start_datetime)}</span></div>`;
    if (v('work_end') && c.work_end_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.work_end')}</span><span class="detail-meta-value">${formatNaiveDateTime(c.work_end_datetime)}</span></div>`;
    if (v('outage_start') && c.outage_start_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.outage_start')}</span><span class="detail-meta-value">${formatNaiveDateTime(c.outage_start_datetime)}</span></div>`;
    if (v('outage_end') && c.outage_end_datetime) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.outage_end')}</span><span class="detail-meta-value">${formatNaiveDateTime(c.outage_end_datetime)}</span></div>`;
    if (v('risk') && c.risk_level) metaItems += `<div class="detail-meta-item"><span class="detail-meta-label">${window.t('change-management.detail.risk_level')}</span><span class="detail-meta-value"><span class="risk-badge risk-${c.risk_level.toLowerCase().replace(/\s+/g, '-')}">${c.risk_level} (${c.risk_score})</span></span></div>`;
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
                    <button class="btn btn-danger" onclick="deleteCurrentChange()">${window.t('change-management.detail.delete')}</button>
                </div>
            </div>
            <div class="sticky-header-top">
                <div class="change-detail-ref">${ref}</div>
                ${badgesHtml ? `<div class="sticky-header-badges">${badgesHtml}</div>` : ''}
            </div>
            ${v('title') ? `<div class="change-detail-title">${escapeHtml(c.title)}</div>` : ''}
            ${metaItems ? `<div class="detail-meta-grid">${metaItems}</div>` : ''}
        </div>
    `;

    // CAB Review panel
    html += renderCabReviewPanel(c);

    // Detail sections — only include visible fields
    let sections = '';
    if (v('description')) sections += renderDetailSection(window.t('change-management.detail.description'), c.description);
    if (v('reason')) sections += renderDetailSection(window.t('change-management.detail.reason'), c.reason_for_change);
    if (v('risk')) sections += renderDetailSection(window.t('change-management.detail.risk_eval'), c.risk_evaluation);
    if (v('testplan')) sections += renderDetailSection(window.t('change-management.detail.test_plan'), c.test_plan);
    if (v('rollback')) sections += renderDetailSection(window.t('change-management.detail.rollback_plan'), c.rollback_plan);
    if (v('pir')) sections += renderDetailSection(window.t('change-management.detail.pir'), c.post_implementation_review);

    if (sections) html += `<div class="detail-sections">${sections}</div>`;

    // Risk Matrix (shown when risk scores exist)
    if (v('risk') && c.risk_likelihood && c.risk_impact_score) {
        html += renderRiskMatrix(c.risk_likelihood, c.risk_impact_score, c.risk_score, c.risk_level);
    }

    // PIR Structured Data (for Completed/Failed changes)
    if (v('pir') && (c.status === 'Completed' || c.status === 'Failed') && (c.pir_was_successful !== null || c.pir_actual_start || c.pir_lessons_learned)) {
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

    document.getElementById('changeDetailContent').innerHTML = html;

    // Load activity timeline asynchronously
    loadActivityTimeline(c.id);
}

function renderDetailSection(title, content) {
    if (!content || content.trim() === '') {
        return `
            <div class="detail-section">
                <h3>${title}</h3>
                <div class="detail-section-body"><span class="detail-section-empty">${window.t('change-management.detail.not_provided')}</span></div>
            </div>
        `;
    }
    return `
        <div class="detail-section">
            <h3>${title}</h3>
            <div class="detail-section-body">${content}</div>
        </div>
    `;
}

// ============ Risk Matrix ============

function renderRiskMatrix(likelihood, impact, score, level) {
    const riskClass = level ? level.toLowerCase().replace(/\s+/g, '-') : '';

    // 5x5 grid: rows = likelihood (5 top to 1 bottom), cols = impact (1 left to 5 right)
    const cellColors = [
        // [likelihood][impact] => color class
        // Row 1 (likelihood=1): impacts 1-5
        ['low','low','low','medium','medium'],
        // Row 2 (likelihood=2): impacts 1-5
        ['low','low','medium','medium','high'],
        // Row 3 (likelihood=3): impacts 1-5
        ['low','medium','medium','high','high'],
        // Row 4 (likelihood=4): impacts 1-5
        ['medium','medium','high','very-high','very-high'],
        // Row 5 (likelihood=5): impacts 1-5
        ['medium','high','high','very-high','critical'],
    ];

    let gridHtml = '';
    // Render rows from top (likelihood=5) to bottom (likelihood=1)
    for (let l = 5; l >= 1; l--) {
        gridHtml += `<div class="risk-matrix-label-y">${l}</div>`;
        for (let i = 1; i <= 5; i++) {
            const cellClass = cellColors[l-1][i-1];
            const isActive = (l === parseInt(likelihood) && i === parseInt(impact));
            gridHtml += `<div class="risk-matrix-cell ${cellClass}${isActive ? ' active' : ''}">${l * i}</div>`;
        }
    }

    return `
        <div class="risk-matrix-section">
            <h3>${window.t('change-management.risk.assessment')}</h3>
            <div class="risk-matrix-wrapper">
                <div class="risk-matrix-info">
                    <div class="risk-score-badge ${riskClass}">
                        <span class="risk-score-value">${score}</span>
                        <span class="risk-score-label">${level}</span>
                    </div>
                    <div class="risk-matrix-details">
                        <div><strong>${window.t('change-management.risk.likelihood')}:</strong> ${likelihood} / 5</div>
                        <div><strong>${window.t('change-management.risk.impact')}:</strong> ${impact} / 5</div>
                    </div>
                </div>
                <div class="risk-matrix-grid-container">
                    <div class="risk-matrix-y-label">${window.t('change-management.risk.likelihood')}</div>
                    <div class="risk-matrix-grid">
                        ${gridHtml}
                        <div class="risk-matrix-spacer"></div>
                        <div class="risk-matrix-label-x">1</div>
                        <div class="risk-matrix-label-x">2</div>
                        <div class="risk-matrix-label-x">3</div>
                        <div class="risk-matrix-label-x">4</div>
                        <div class="risk-matrix-label-x">5</div>
                    </div>
                    <div class="risk-matrix-x-label">${window.t('change-management.risk.impact')}</div>
                </div>
            </div>
        </div>
    `;
}

// ============ PIR Detail ============

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
            } else if (entry.action_type === 'cab_vote') {
                description = `<strong>${escapeHtml(entry.field_name || 'CAB')}</strong>`;
                if (entry.new_value) description += `: <span class="new-val">${escapeHtml(entry.new_value)}</span>`;
                if (entry.old_value) description += ` <span class="old-val">(${escapeHtml(entry.old_value)})</span>`;
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

function closeSearchModal() {
    document.getElementById('searchModal').classList.remove('active');
}

function initSearchModalDrag() {
    const header = document.getElementById('searchModalHeader');
    const modal = document.getElementById('searchModal');

    header.onmousedown = function(e) {
        if (e.target.tagName === 'BUTTON') return;
        e.preventDefault();
        const rect = modal.getBoundingClientRect();
        searchModalOffsetX = e.clientX - rect.left;
        searchModalOffsetY = e.clientY - rect.top;

        function onMouseMove(e) {
            modal.style.left = (e.clientX - searchModalOffsetX) + 'px';
            modal.style.top = (e.clientY - searchModalOffsetY) + 'px';
            modal.style.transform = 'none';
        }
        function onMouseUp() {
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
        }
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    };
}

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

function selectSearchResult(changeId) {
    closeSearchModal();
    viewChange(changeId);
}

function clearSearch() {
    document.getElementById('searchChangeNumber').value = '';
    document.getElementById('searchChangeTitle').value = '';
    document.getElementById('searchResults').innerHTML = `<div class="search-results-empty">${window.t('change-management.search.empty')}</div>`;
}

// ============ Create / Edit ============

function openCreateChange() {
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

    // Risk scoring
    document.getElementById('riskLikelihood').value = '';
    document.getElementById('riskImpactScore').value = '';
    document.getElementById('riskScoreDisplay').textContent = '-';
    document.getElementById('riskScoreDisplay').className = 'risk-score-display';

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

function editCurrentChange() {
    if (!currentChange) return;
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

    // Risk scoring
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
}

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

async function saveChange() {
    const title = document.getElementById('changeTitle').value.trim();
    if (!title) {
        showToast(window.t('change-management.toast.title_required'), 'success');
        return;
    }

    const changeId = document.getElementById('editChangeId').value;

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
        pir_was_successful: (() => { const r = document.querySelector('input[name="pirWasSuccessful"]:checked'); return r ? r.value : null; })(),
        pir_actual_start: document.getElementById('pirActualStart').value || null,
        pir_actual_end: document.getElementById('pirActualEnd').value || null,
        pir_lessons_learned: document.getElementById('pirLessonsLearned').value || null,
        pir_follow_up: document.getElementById('pirFollowUp').value || null,
        cab_required: document.getElementById('cabRequired').checked ? 1 : 0,
        cab_approval_type: document.getElementById('cabApprovalType').value
    };

    try {
        const response = await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();

        if (data.success) {
            // Save CAB members if CAB is enabled
            if (payload.cab_required) {
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

function closeDeleteModal() {
    document.getElementById('deleteModal').innerHTML = '';
}

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

function downloadAttachment(id) {
    window.open(API_BASE + 'get_attachment.php?id=' + id, '_blank');
}

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
            selector: '#' + id,
            license_key: 'gpl',
            height: 300,
            menubar: false,
            skin: isDark ? 'oxide-dark' : 'oxide',
            content_css: isDark ? 'dark' : 'default',
            plugins: ['advlist', 'autolink', 'lists', 'link', 'table', 'wordcount'],
            toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link table | removeformat',
            content_style: 'body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; }',
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

function destroyEditors() {
    editorsReady = false;
    editorIds.forEach(id => {
        const editor = tinymce.get(id);
        if (editor) editor.remove();
    });
}

function getEditorContent(id) {
    const editor = tinymce.get(id);
    return editor ? editor.getContent() : '';
}

function setEditorContent(id, content) {
    const editor = tinymce.get(id);
    if (editor) {
        editor.setContent(content || '');
    }
}

// ============ Rich Text Tabs ============

function switchTab(tab) {
    // Update tab buttons
    document.querySelectorAll('.rich-text-tab').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');

    // Show correct panel
    document.querySelectorAll('.rich-text-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + tab).classList.add('active');
}

// ============ Risk Scoring & PIR Visibility ============

function updateRiskScore() {
    const likelihood = parseInt(document.getElementById('riskLikelihood').value) || 0;
    const impact = parseInt(document.getElementById('riskImpactScore').value) || 0;
    const display = document.getElementById('riskScoreDisplay');

    if (likelihood && impact) {
        const score = likelihood * impact;
        let level, cssClass;
        if (score <= 4) { level = 'Low'; cssClass = 'risk-low'; }
        else if (score <= 9) { level = 'Medium'; cssClass = 'risk-medium'; }
        else if (score <= 15) { level = 'High'; cssClass = 'risk-high'; }
        else if (score <= 20) { level = 'Very High'; cssClass = 'risk-very-high'; }
        else { level = 'Critical'; cssClass = 'risk-critical'; }
        display.textContent = score + ' - ' + level;
        display.className = 'risk-score-display ' + cssClass;
    } else {
        display.textContent = '-';
        display.className = 'risk-score-display';
    }
}

function updatePirVisibility() {
    const status = document.getElementById('changeStatus').value;
    const pirSection = document.getElementById('pirStructuredFields');
    if (pirSection) {
        pirSection.style.display = (status === 'Completed' || status === 'Failed') ? '' : 'none';
    }
}

// ============ CAB Editor Functions ============

function toggleCabConfig() {
    const checked = document.getElementById('cabRequired').checked;
    document.getElementById('cabConfigSection').style.display = checked ? '' : 'none';
}

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

function removeCabMember(analystId) {
    cabEditorMembers = cabEditorMembers.filter(m => m.analyst_id !== analystId);
    renderCabMemberChips();
}

function toggleCabMemberRequired(analystId) {
    const member = cabEditorMembers.find(m => m.analyst_id === analystId);
    if (member) {
        member.is_required = member.is_required ? 0 : 1;
        renderCabMemberChips();
    }
}

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

function renderCabReviewPanel(c) {
    if (!parseInt(c.cab_required) || !c.cab_members) return '';

    const members = c.cab_members;
    const requiredMembers = members.filter(m => parseInt(m.is_required));
    const requiredApproved = requiredMembers.filter(m => m.vote === 'Approve').length;
    const approvalType = c.cab_approval_type === 'majority' ? window.t('change-management.cab.type_majority') : window.t('change-management.cab.type_all');
    const currentAnalystId = parseInt(document.body.dataset.analystId) || 0;
    const myMembership = members.find(m => parseInt(m.analyst_id) === currentAnalystId);
    const canVote = myMembership && !myMembership.vote && c.status === 'Pending Approval';

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
                vote_comment: comment
            })
        });
        const data = await response.json();

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

function backToList() {
    currentChange = null;
    showView('list');
    loadChanges();
}

// ============ Utility Functions ============

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// NAIVE wall-clock scheduling date (only caller is work_start on the list card)
// — shown exactly as typed, no zone conversion (parseNaiveDate from tz.js).
function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = parseNaiveDate(dateStr);
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

// Server-stamped UTC timestamp (created / modified / approved / CAB vote /
// history "when") → analyst display zone (parseUTCDate / tzOpts from tz.js).
function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const d = parseUTCDate(dateStr);
    return d.toLocaleDateString('en-GB', tzOpts({ day: '2-digit', month: 'short', year: 'numeric' })) +
           ' ' + d.toLocaleTimeString('en-GB', tzOpts({ hour: '2-digit', minute: '2-digit' }));
}

// NAIVE wall-clock scheduling datetime (work / outage / PIR windows) — shown
// exactly as typed for every analyst, no zone conversion. Same output shape as
// formatDateTime so the two are drop-in interchangeable at each call site.
function formatNaiveDateTime(dateStr) {
    if (!dateStr) return '';
    const d = parseNaiveDate(dateStr);
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) +
           ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

function toDatetimeLocal(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return '';
    // Format as YYYY-MM-DDTHH:MM for datetime-local input
    const pad = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
           'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

function formatFileSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}


// ============ Share Functions ============

function toggleShareDropdown() {
    const menu = document.getElementById('shareDropdownMenu');
    menu.classList.toggle('active');
}

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

function getChangeRef() {
    if (!currentChange) return '';
    return 'CHG-' + String(currentChange.id).padStart(4, '0');
}

function getChangeUrl() {
    return window.location.origin + window.location.pathname + '?change_id=' + currentChange.id;
}

function shareChangeLink() {
    closeShareDropdown();
    if (!currentChange) return;

    const url = getChangeUrl();
    navigator.clipboard.writeText(url).then(() => {
        showToast(window.t('change-management.toast.link_copied'), 'success');
    }).catch(() => {
        // Fallback for older browsers
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showToast(window.t('change-management.toast.link_copied'), 'success');
    });
}

function shareChangePdf() {
    closeShareDropdown();
    if (!currentChange) return;

    const pdfContent = buildPdfContent();
    const ref = getChangeRef();

    const opt = {
        margin: 10,
        filename: `${ref}_${currentChange.title.replace(/[^a-z0-9]/gi, '_')}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(pdfContent).save();
}

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

function closeShareEmailModal() {
    document.getElementById('shareEmailModal').classList.remove('active');
}

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
        const pdfContent = buildPdfContent();

        const opt = {
            margin: 10,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2 },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };

        try {
            const pdfBlob = await html2pdf().set(opt).from(pdfContent).outputPdf('blob');
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
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.status}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; width: 25%;"><strong>${window.t('change-management.pdf.change_type')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.change_type}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.priority')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.priority}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.impact')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.impact}</td>
                </tr>
                ${c.category ? `<tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.category')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;" colspan="3">${escapeHtml(c.category)}</td>
                </tr>` : ''}
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.requester')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.requester_name || window.t('change-management.pdf.not_set')}</td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.assigned_to')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${c.assigned_to_name || window.t('change-management.pdf.not_set')}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9;"><strong>${window.t('change-management.pdf.approver')}</strong></td>
                    <td style="padding: 8px; border: 1px solid #ddd;" colspan="3">${c.approver_name || window.t('change-management.pdf.not_set')}</td>
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
                <div style="line-height: 1.6;">${section.content}</div>
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
