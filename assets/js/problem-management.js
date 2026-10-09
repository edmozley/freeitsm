/* Problem Management — list / detail / editor SPA. */
const PM_API = '../api/problem-management/';
let pmStatuses = [];
let pmPriorities = [];
let pmAnalysts = [];
let pmFilterStatus = 'all';
let pmCurrentId = null;       // open detail
let pmDetailCache = null;     // last loaded detail payload

// Open-in-new-tab and unlink icons (feather-style), used in the linked panels.
const PM_OPEN_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>';
const PM_UNLINK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 17H7A5 5 0 0 1 7 7h2"></path><path d="M15 7h2a5 5 0 0 1 4 8"></path><line x1="8" y1="12" x2="12" y2="12"></line><line x1="2" y1="2" x2="22" y2="22"></line></svg>';

/**
 * The ⓘ preview badge (#91). Guarded, so that a page which somehow loaded
 * without record-preview.js loses the preview rather than the whole table it
 * would have been drawn into.
 */
function pmPreviewBadge(type, id) {
    return window.FreeITSMPreview ? window.FreeITSMPreview.badge(type, id) : '';
}

function pmEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function pmToast(msg, type) {
    // Use the shared toaster (assets/js/toast.js); fall back to a minimal toast.
    if (window.showToast) { window.showToast(msg, type || 'info'); return; }
    let el = document.getElementById('pmToast');
    if (!el) { el = document.createElement('div'); el.id = 'pmToast'; el.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);padding:10px 18px;border-radius:6px;color:#fff;z-index:2000;font-weight:600;box-shadow:0 2px 10px rgba(0,0,0,.2);'; document.body.appendChild(el); }
    el.style.background = type === 'error' ? '#c62828' : (type === 'success' ? '#2e7d32' : '#374151');
    el.textContent = msg; el.style.display = 'block';
    clearTimeout(el._t); el._t = setTimeout(() => { el.style.display = 'none'; }, 3200);
}

document.addEventListener('DOMContentLoaded', async () => {
    await pmLoadLookups();
    pmLoadList();
    const params = new URLSearchParams(location.search);
    // Canonical deep-link param is ?problem_id=; ?id= is kept as a legacy
    // fallback (e.g. the problem badge in the tickets reading pane).
    const pid = params.get('problem_id') || params.get('id');
    if (pid) {
        // Seed a base "list" entry beneath the detail so the browser Back
        // button returns to the list even on a direct deep-link, and
        // normalise a legacy ?id= to the canonical ?problem_id=.
        history.replaceState({ pmView: 'list' }, '', location.pathname);
        history.pushState({ pmView: 'detail', id: parseInt(pid, 10) }, '', location.pathname + '?problem_id=' + parseInt(pid, 10));
        pmOpenDetail(parseInt(pid, 10), true);
    }
    if (params.get('new')) pmOpenEditor();
});

// Browser back/forward — sync the view to the URL.
window.addEventListener('popstate', () => {
    const sp = new URLSearchParams(location.search);
    const pid = sp.get('problem_id') || sp.get('id');
    if (pid) pmOpenDetail(parseInt(pid, 10), true);
    else { pmShowListView(); pmLoadList(); }
});

async function pmLoadLookups() {
    try {
        const [s, p, a] = await Promise.all([
            fetch(PM_API + 'get_problem_statuses.php').then(r => r.json()),
            fetch(PM_API + 'get_problem_priorities.php').then(r => r.json()),
            fetch('../api/tickets/get_analysts.php').then(r => r.json()).catch(() => ({}))
        ]);
        pmStatuses = s.success ? s.statuses : [];
        pmPriorities = p.success ? p.priorities : [];
        pmAnalysts = (a && a.success && a.analysts) ? a.analysts : [];
    } catch (e) { /* leave empty */ }
}

async function pmLoadList() {
    const params = new URLSearchParams();
    if (pmFilterStatus !== 'all') params.set('status_id', pmFilterStatus);
    try {
        const res = await fetch(PM_API + 'list.php?' + params.toString());
        const data = await res.json();
        if (!data.success) { pmToast(data.error || pmT('list.load_failed', 'Failed to load'), 'error'); return; }
        pmRenderFilters(data.status_counts);
        pmRenderList(data.problems);
        document.getElementById('pmCount').textContent = data.total + (data.total === 1 ? ' problem' : ' problems');
    } catch (e) { pmToast(pmT('list.load_failed_list', 'Failed to load problems'), 'error'); }
}

function pmRenderFilters(counts) {
    const wrap = document.getElementById('pmStatusFilters');
    const total = (counts || []).reduce((n, s) => n + (s.cnt || 0), 0);
    let html = `<div class="pm-filter ${pmFilterStatus === 'all' ? 'active' : ''}" data-status="all" onclick="pmFilter('all')"><span>${pmEsc(pmT('list.all', 'All'))}</span><span class="cnt">${total}</span></div>`;
    html += (counts || []).map(s => `<div class="pm-filter ${String(pmFilterStatus) === String(s.id) ? 'active' : ''}" onclick="pmFilter(${s.id})"><span>${pmEsc(s.name)}</span><span class="cnt">${s.cnt || 0}</span></div>`).join('');
    wrap.innerHTML = html;
}

/**
 * Translate, or the English written at the call site.
 *
 * Nearly everything this module says is drawn HERE rather than in PHP, so this
 * one function is most of the module's i18n. window.tf comes from i18n.js,
 * which problem-management/index.php loads with this namespace exported.
 */
function pmT(key, english, params) {
    return window.tf ? window.tf('problem-management.' + key, english, params) : english;
}

function pmRenderList(problems) {
    const el = document.getElementById('pmList');
    if (!problems.length) { el.innerHTML = '<div class="pm-empty">' + pmEsc(pmT('list.empty', 'No problems. Click “New problem” to create one.')) + '</div>'; return; }
    el.innerHTML = problems.map(p => `
        <div class="pm-card" onclick="pmOpenDetail(${p.id})">
            <div class="pm-card-top">
                <span class="pm-num">${pmEsc(p.problem_number || '')}</span>
                <span class="pm-card-title">${pmEsc(p.title)}</span>
                ${p.status_name ? `<span class="pm-badge" style="background:${pmEsc(p.status_colour || '#6b7280')}">${pmEsc(p.status_name)}</span>` : ''}
            </div>
            <div class="pm-meta">
                ${p.priority_name ? `<span>${pmEsc(p.priority_name)}</span>` : ''}
                ${p.assignee_name ? `<span>👤 ${pmEsc(p.assignee_name)}</span>` : ''}
                <span>${pmEsc(p.incident_count == 1
                    ? pmT('list.incidents_one', '🎫 1 incident')
                    : pmT('list.incidents_many', '🎫 {n} incidents', { n: p.incident_count }))}</span>
                ${p.is_known_error == 1 ? '<span class="pm-ke">' + pmEsc(pmT('list.known_error', 'Known error')) + '</span>' : ''}
            </div>
        </div>`).join('');
}

// Sidebar actions must return to the list view — otherwise, when a problem is
// open (e.g. deep-linked via ?id=), they'd update the list while it's hidden
// behind the detail and appear to do nothing.
function pmFilter(statusId) {
    const leavingDetail = pmCurrentId !== null;
    pmFilterStatus = statusId; pmShowListView(); pmLoadList();
    if (leavingDetail) pmSetListUrl();
}

// ============ Search modal (draggable) — mirrors the Change Management search ============
let pmSearchOffsetX = 0, pmSearchOffsetY = 0;

function pmOpenSearchModal() {
    const modal = document.getElementById('pmSearchModal');
    modal.classList.add('active');
    // Position just under the Search button (falls back to top-centre).
    const btn = document.querySelector('.search-btn');
    if (btn) {
        const r = btn.getBoundingClientRect();
        modal.style.left = r.left + 'px';
        modal.style.top = (r.bottom + 10) + 'px';
        modal.style.transform = 'none';
    } else {
        modal.style.left = '50%'; modal.style.top = '100px'; modal.style.transform = 'translateX(-50%)';
    }
    pmInitSearchDrag();
    document.getElementById('pmSearchNumber').focus();
}

function pmCloseSearchModal() { document.getElementById('pmSearchModal').classList.remove('active'); }

// Escape always closes the panel; clicking away closes it only if the analyst
// turned that on (System > Preferences > Display). #144. Clicking a result
// already closes it, via pmSelectSearchResult().
document.addEventListener('DOMContentLoaded', function () {
    if (window.initSearchPanelDismiss && document.getElementById('pmSearchModal')) {
        window.initSearchPanelDismiss({ panelId: 'pmSearchModal', close: pmCloseSearchModal });
    }
});

function pmInitSearchDrag() {
    const header = document.getElementById('pmSearchModalHeader');
    const modal = document.getElementById('pmSearchModal');
    header.onmousedown = function(e) {
        if (e.target.tagName === 'BUTTON') return;
        e.preventDefault();
        const rect = modal.getBoundingClientRect();
        pmSearchOffsetX = e.clientX - rect.left;
        pmSearchOffsetY = e.clientY - rect.top;
        function mm(e) { modal.style.left = (e.clientX - pmSearchOffsetX) + 'px'; modal.style.top = (e.clientY - pmSearchOffsetY) + 'px'; modal.style.transform = 'none'; }
        function mu() { document.removeEventListener('mousemove', mm); document.removeEventListener('mouseup', mu); }
        document.addEventListener('mousemove', mm);
        document.addEventListener('mouseup', mu);
    };
}

async function pmPerformSearch() {
    const num = document.getElementById('pmSearchNumber').value.trim();
    const title = document.getElementById('pmSearchTitle').value.trim();
    if (!num && !title) { pmToast(pmT('search.need_terms', 'Enter a problem number or title to search'), 'error'); return; }
    const results = document.getElementById('pmSearchResults');
    results.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    // list.php?q= searches both title and problem_number, so a single term covers either field.
    const params = new URLSearchParams();
    params.set('q', title || num);
    try {
        const res = await fetch(PM_API + 'list.php?' + params.toString());
        const data = await res.json();
        if (!data.success) { results.innerHTML = `<div class="search-results-empty">${pmEsc(data.error || pmT('search.failed', 'Search failed'))}</div>`; return; }
        pmRenderSearchResults(data.problems || []);
    } catch (e) { results.innerHTML = '<div class="search-results-empty">' + pmEsc(pmT('search.failed_retry', 'Search failed. Please try again.')) + '</div>'; }
}

function pmRenderSearchResults(results) {
    const c = document.getElementById('pmSearchResults');
    if (!results.length) { c.innerHTML = '<div class="search-results-empty">' + pmEsc(pmT('search.no_matches', 'No matching problems.')) + '</div>'; return; }
    let html = '<div class="search-results-count">' + pmEsc(results.length === 1
        ? pmT('search.count_one', '1 result')
        : pmT('search.count_many', '{n} results', { n: results.length })) + '</div>';
    results.forEach(p => {
        html += `<div class="search-result-item" onclick="pmSelectSearchResult(${p.id})">
            <div class="search-result-ticket">${pmEsc(p.problem_number || '')}</div>
            <div class="search-result-subject">${pmEsc(p.title)}</div>
            <div class="search-result-meta">
                ${p.status_name ? '<span>' + pmEsc(p.status_name) + '</span>' : ''}
                ${p.priority_name ? '<span>' + pmEsc(p.priority_name) + '</span>' : ''}
                ${p.assignee_name ? '<span>' + pmEsc(p.assignee_name) + '</span>' : ''}
            </div>
        </div>`;
    });
    c.innerHTML = html;
}

function pmSelectSearchResult(id) { pmCloseSearchModal(); pmOpenDetail(id); }

function pmClearSearch() {
    document.getElementById('pmSearchNumber').value = '';
    document.getElementById('pmSearchTitle').value = '';
    document.getElementById('pmSearchResults').innerHTML = '<div class="search-results-empty">' + pmEsc(pmT('search.prompt', 'Enter a problem number or title above and press Search.')) + '</div>';
}

function pmShowListView() {
    pmCurrentId = null;
    document.getElementById('pmDetailView').style.display = 'none';
    document.getElementById('pmListView').style.display = '';
}
// Drop the ?problem_id= param when returning to the list (adds a history entry
// so Back/Forward step between the list and the problem you were viewing).
function pmSetListUrl() {
    if (location.search) history.pushState({ pmView: 'list' }, '', location.pathname);
}
function pmBackToList() {
    pmShowListView();
    pmLoadList();
    pmSetListUrl();
}

async function pmOpenDetail(id, fromHistory) {
    try {
        const res = await fetch(PM_API + 'get.php?id=' + id);
        const data = await res.json();
        if (!data.success) { pmToast(data.error || pmT('detail.not_found', 'Not found'), 'error'); return; }
        pmCurrentId = id; pmDetailCache = data;
        // The recent trail (#124).
        if (window.trailVisit) window.trailVisit('problem', id);
        pmRenderDetail(data);
        document.getElementById('pmListView').style.display = 'none';
        const dv = document.getElementById('pmDetailView'); dv.style.display = ''; dv.scrollTop = 0;
        // Reflect the open problem in the URL (skip when we're restoring from a
        // load/back-forward navigation, which is already at the right URL).
        if (!fromHistory) history.pushState({ pmView: 'detail', id }, '', location.pathname + '?problem_id=' + id);
    } catch (e) { pmToast(pmT('detail.open_failed', 'Failed to open problem'), 'error'); }
}

function pmRenderDetail(data) {
    const p = data.problem;
    const statusBadge = p.status_name ? `<span class="pm-badge" style="background:#6a1b9a">${pmEsc(p.status_name)}</span>` : '';
    const incidentRows = (data.incidents || []).map(i => `
        <tr>
            <td><a href="../tickets/index.php?ticket_id=${i.id}" target="_blank">${pmEsc(i.ticket_number || ('#' + i.id))}</a></td>
            <td>${pmEsc(i.subject || '')}</td>
            <td>${pmEsc(i.status || '')}</td>
            <td class="pm-actions">
                ${pmPreviewBadge('ticket', i.id)}
                <a class="pm-icon-btn" href="../tickets/index.php?ticket_id=${i.id}" target="_blank" title="${pmEsc(pmT('detail.open_incident', 'Open incident'))}">${PM_OPEN_SVG}</a>
                <button class="pm-icon-btn danger" onclick="pmUnlinkIncident(${i.id})" title="${pmEsc(pmT('detail.unlink_incident', 'Unlink incident'))}">${PM_UNLINK_SVG}</button>
            </td>
        </tr>`).join('');
    const incidents = `<table class="pm-table"><thead><tr><th>${pmEsc(pmT('detail.col_reference', 'Reference'))}</th><th>${pmEsc(pmT('detail.col_subject', 'Subject'))}</th><th>${pmEsc(pmT('detail.col_status', 'Status'))}</th><th></th></tr></thead>
        <tbody>${incidentRows || '<tr class="pm-empty-row"><td colspan="4">' + pmEsc(pmT('detail.no_incidents', 'No incidents linked yet.')) + '</td></tr>'}</tbody></table>`;
    const changeRows = (data.changes || []).map(c => `
        <tr>
            <td><a href="../change-management/index.php?change_id=${c.id}" target="_blank">${pmEsc(pmT('detail.change_ref', 'Change #{id}', { id: c.id }))}</a></td>
            <td>${pmEsc(c.title || '')}</td>
            <td>${pmEsc(c.status || '')}</td>
            <td class="pm-actions">
                ${pmPreviewBadge('change', c.id)}
                <a class="pm-icon-btn" href="../change-management/index.php?change_id=${c.id}" target="_blank" title="${pmEsc(pmT('detail.open_change', 'Open change'))}">${PM_OPEN_SVG}</a>
                <button class="pm-icon-btn danger" onclick="pmUnlinkChange(${c.id})" title="${pmEsc(pmT('detail.unlink_change', 'Unlink change'))}">${PM_UNLINK_SVG}</button>
            </td>
        </tr>`).join('');
    const changes = `<table class="pm-table"><thead><tr><th>${pmEsc(pmT('detail.col_reference', 'Reference'))}</th><th>${pmEsc(pmT('detail.col_title', 'Title'))}</th><th>${pmEsc(pmT('detail.col_status', 'Status'))}</th><th></th></tr></thead>
        <tbody>${changeRows || '<tr class="pm-empty-row"><td colspan="4">' + pmEsc(pmT('detail.no_change', 'No change linked yet.')) + '</td></tr>'}</tbody></table>`;
    const auditRows = (data.audit || []).map(a => {
        const when = a.created_datetime ? fmtDateTime(a.created_datetime) : '';
        // One sentence, not a verb concatenated onto a field name: "changed
        // Priority to High" does not survive being assembled left to right in
        // a language that puts the verb last.
        const what = a.action_type === 'created'
            ? pmEsc(pmT('detail.audit_created', 'created the problem'))
            : pmEsc(a.new_value
                ? pmT('detail.audit_changed_to', 'changed {field} to “{value}”', { field: a.field_name, value: a.new_value })
                : pmT('detail.audit_changed', 'changed {field}', { field: a.field_name }));
        return `<tr><td class="pm-when">${when}</td><td>${pmEsc(a.analyst_name || pmT('detail.someone', 'Someone'))}</td><td>${what}</td></tr>`;
    }).join('');
    const audit = `<table class="pm-table"><thead><tr><th>${pmEsc(pmT('detail.col_when', 'When'))}</th><th>${pmEsc(pmT('detail.col_who', 'Who'))}</th><th>${pmEsc(pmT('detail.col_what', 'What'))}</th></tr></thead>
        <tbody>${auditRows || '<tr class="pm-empty-row"><td colspan="3">' + pmEsc(pmT('detail.no_history', 'No history.')) + '</td></tr>'}</tbody></table>`;
    const notes = (data.notes || []).map(n => {
        const when = n.created_datetime ? fmtDateTime(n.created_datetime) : '';
        return `<div class="pm-note">
            <div class="pm-note-head"><span class="pm-note-who">${pmEsc(n.analyst_name || pmT('detail.someone', 'Someone'))}</span><span class="pm-note-when">${when}</span></div>
            <div class="pm-note-body">${pmEsc(n.note)}</div>
        </div>`;
    }).join('') || '<div style="color:#9ca3af;font-size:13px;">' + pmEsc(pmT('detail.no_notes', 'No notes yet.')) + '</div>';

    document.getElementById('pmDetailView').innerHTML = `
        <div class="pm-detail">
            <div class="pm-detail-head">
                <a href="#" onclick="pmBackToList();return false;" style="color:#6a1b9a;text-decoration:none;">${pmEsc(pmT('detail.back', '← Back'))}</a>
                <span class="pm-num">${pmEsc(p.problem_number || '')}</span>
                <h1>${pmEsc(p.title)}</h1>
                ${statusBadge}
                ${p.is_known_error == 1 ? '<span class="pm-ke">' + pmEsc(pmT('list.known_error', 'Known error')) + '</span>' : ''}
            </div>
            <div style="display:flex;gap:10px;margin:6px 0 4px;flex-wrap:wrap;">
                <button class="pm-btn" onclick="pmEditCurrent()">${pmEsc(pmT('detail.edit', 'Edit'))}</button>
                <button class="pm-btn" onclick="pmLinkIncident()">${pmEsc(pmT('detail.link_incident', 'Link incident'))}</button>
                <button class="pm-btn" onclick="pmLinkChange()">${pmEsc(pmT('detail.link_change', 'Link change'))}</button>
                <button class="pm-btn" onclick="pmAiRootCause()" title="${pmEsc(pmT('detail.draft_title', 'Draft a root cause from the linked incidents'))}">${pmEsc(pmT('detail.draft_cause', '🤖 Draft root cause'))}</button>
                <button class="pm-btn pm-btn-danger" onclick="pmDelete()">${pmEsc(pmT('detail.delete', 'Delete'))}</button>
            </div>
            <div class="pm-ai-out" id="pmAiOut"></div>

            <div class="pm-section">
                <h3>${pmEsc(pmT('detail.details', 'Details'))}</h3>
                <div class="pm-grid2">
                    <div><div class="pm-field-label">${pmEsc(pmT('detail.priority', 'Priority'))}</div><div class="pm-field-val">${pmEsc(p.priority_name || '—')}</div></div>
                    <div><div class="pm-field-label">${pmEsc(pmT('detail.assigned_to', 'Assigned to'))}</div><div class="pm-field-val">${pmEsc(p.assignee_name || '—')}</div></div>
                </div>
                <div class="pm-field-label">${pmEsc(pmT('detail.description', 'Description'))}</div><div class="pm-field-val">${pmEsc(p.description || '—')}</div>
                <div class="pm-field-label">${pmEsc(pmT('detail.root_cause', 'Root cause'))}</div><div class="pm-field-val">${pmEsc(p.root_cause || '—')}</div>
                <div class="pm-field-label">${pmEsc(pmT('detail.workaround', 'Workaround'))}</div><div class="pm-field-val">${pmEsc(p.workaround || '—')}</div>
            </div>

            <div class="pm-section">
                <h3>${pmEsc(pmT('detail.incidents', 'Linked incidents ({n})', { n: (data.incidents || []).length }))}</h3>
                ${incidents}
            </div>
            <div class="pm-section">
                <h3>${pmEsc(pmT('detail.fix', 'Fix (linked change)'))}</h3>
                ${changes}
            </div>
            ${window.PM_SHOW_PROJECTS ? '<div class="pm-section"><h3>' + pmEsc(pmT('detail.projects', 'Projects')) + '</h3><div id="pmProjects"></div></div>' : ''}
            <div class="pm-section">
                <h3>${pmEsc(pmT('detail.notes', 'Notes'))}</h3>
                <div class="pm-note-add">
                    <textarea id="pmNoteInput" rows="2" placeholder="${pmEsc(pmT('detail.note_ph', 'Add a note…'))}"></textarea>
                    <button class="pm-btn pm-btn-primary" onclick="pmAddNote()">${pmEsc(pmT('detail.note_add', 'Add'))}</button>
                </div>
                <div class="pm-notes">${notes}</div>
            </div>
            <div class="pm-section">
                <h3>${pmEsc(pmT('detail.history', 'History'))}</h3>
                ${audit}
            </div>
            <div class="pm-section">
                <h3>${pmEsc(pmT('detail.documents', 'Documents'))}</h3>
                <div id="pmDocuments"></div>
            </div>
        </div>`;

    // The projects this problem is part of (3.3.0) - the same panel changes show.
    if (window.PM_SHOW_PROJECTS && window.ProjectLinks) ProjectLinks.mount(document.getElementById('pmProjects'), { kind: 'problem', id: p.id, base: window.PM_BASE, bare: true });

    // Attached documents (discussion #76). Mounted, not re-pointed: this view is
    // rebuilt for every problem, so the previous element is already gone.
    if (window.FreeITSMDocuments) {
        FreeITSMDocuments.mount(document.getElementById('pmDocuments'), {
            parentType: 'problem',
            parentId:   p.id,
            apiBase:    '../api/documents/'
        });
    }
}

// ----- Editor -----
function pmFillSelect(sel, items, selected, blank) {
    sel.innerHTML = (blank ? '<option value=""></option>' : '') + items.map(i =>
        `<option value="${i.id}" ${String(selected) === String(i.id) ? 'selected' : ''}>${pmEsc(i.name || i.full_name)}</option>`).join('');
}

function pmOpenEditor(problem) {
    const p = problem || {};
    document.getElementById('pmId').value = p.id || '';
    document.getElementById('pmModalTitle').textContent = p.id
        ? (p.problem_number
            ? pmT('editor.edit', 'Edit {number}', { number: p.problem_number })
            : pmT('editor.edit_generic', 'Edit problem'))
        : pmT('editor.new', 'New problem');
    document.getElementById('pmTitle').value = p.title || '';
    document.getElementById('pmDescription').value = p.description || '';
    document.getElementById('pmRootCause').value = p.root_cause || '';
    document.getElementById('pmWorkaround').value = p.workaround || '';
    document.getElementById('pmKnownError').checked = p.is_known_error == 1;
    pmFillSelect(document.getElementById('pmStatus'), pmStatuses, p.status_id, false);
    pmFillSelect(document.getElementById('pmPriority'), pmPriorities, p.priority_id, true);
    pmFillSelect(document.getElementById('pmAssignee'), pmAnalysts, p.assigned_analyst_id, true);
    document.getElementById('pmModal').classList.add('active');
}
function pmEditCurrent() { if (pmDetailCache) pmOpenEditor(pmDetailCache.problem); }
function pmCloseEditor() { document.getElementById('pmModal').classList.remove('active'); }

async function pmSave() {
    const payload = {
        id: document.getElementById('pmId').value || 0,
        title: document.getElementById('pmTitle').value.trim(),
        description: document.getElementById('pmDescription').value,
        status_id: document.getElementById('pmStatus').value,
        priority_id: document.getElementById('pmPriority').value,
        assigned_analyst_id: document.getElementById('pmAssignee').value,
        root_cause: document.getElementById('pmRootCause').value,
        workaround: document.getElementById('pmWorkaround').value,
        is_known_error: document.getElementById('pmKnownError').checked ? 1 : 0
    };
    if (!payload.title) { pmToast(pmT('editor.title_required', 'Title is required'), 'error'); return; }
    try {
        const res = await fetch(PM_API + 'save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const data = await res.json();
        if (!data.success) { pmToast(data.error || pmT('editor.save_failed', 'Save failed'), 'error'); return; }
        pmToast(data.message || pmT('editor.saved', 'Saved'), 'success');
        pmCloseEditor();
        // Open the problem we just saved — the edited one (payload.id) or, for a
        // new problem, the id the server returns. Keying off pmCurrentId was wrong:
        // creating a problem while another was open reopened the old one.
        pmOpenDetail((payload.id && payload.id != 0) ? payload.id : data.id);
    } catch (e) { pmToast(pmT('editor.save_failed', 'Save failed'), 'error'); }
}

async function pmDelete() {
    if (!pmCurrentId) return;
    const ok = window.showConfirm
        ? await showConfirm({
            title: pmT('editor.delete_title', 'Delete problem?'),
            message: pmT('editor.delete_message', 'Linked incidents are not deleted; they just lose the link. This cannot be undone.'),
            okLabel: pmT('detail.delete', 'Delete'),
            okClass: 'danger'
          })
        : confirm(pmT('editor.delete_fallback', 'Delete this problem? Linked incidents are not deleted; they just lose the link.'));
    if (!ok) return;
    try {
        const res = await fetch(PM_API + 'delete.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: pmCurrentId }) });
        const data = await res.json();
        if (!data.success) { pmToast(data.error || pmT('editor.delete_failed', 'Delete failed'), 'error'); return; }
        pmToast(pmT('editor.deleted', 'Problem deleted'), 'success'); pmBackToList();
    } catch (e) { pmToast(pmT('editor.delete_failed', 'Delete failed'), 'error'); }
}

// ----- Linking (endpoints added in phases B/C) -----
let pmLinkSearchTimer = null;
function pmLinkIncident() {
    if (!pmCurrentId) return;
    const search = document.getElementById('pmLinkSearch'); if (search) search.value = '';
    const all = document.getElementById('pmLinkAll'); if (all) all.checked = false;
    document.getElementById('pmLinkModal').classList.add('active');
    pmLoadLinkable();
}
function pmLinkSearchDebounced() {
    clearTimeout(pmLinkSearchTimer);
    pmLinkSearchTimer = setTimeout(pmLoadLinkable, 250);
}
async function pmLoadLinkable() {
    const list = document.getElementById('pmLinkList');
    const q = (document.getElementById('pmLinkSearch') || {}).value || '';
    list.innerHTML = '<div class="pm-empty">' + pmEsc(pmT('list.loading', 'Loading…')) + '</div>';
    try {
        const res = await fetch(PM_API + 'list_linkable_tickets.php?problem_id=' + pmCurrentId + '&q=' + encodeURIComponent(q.trim()));
        const data = await res.json();
        if (!data.success) { list.innerHTML = '<div class="pm-empty">' + pmEsc(data.error || pmT('list.load_failed', 'Failed to load')) + '</div>'; return; }
        if (!data.tickets.length) { list.innerHTML = '<div class="pm-empty">' + pmEsc(q.trim() ? pmT('link.no_matching_incidents', 'No matching open incidents.') : pmT('link.none_linkable', 'No open incidents available to link.')) + '</div>'; return; }
        list.innerHTML = data.tickets.map(t => `
            <label class="pm-pick-row">
                <input type="checkbox" class="pm-pick-cb" value="${t.id}">
                <span class="pm-pick-main">
                    <span class="pm-pick-title">${pmEsc(t.subject || pmT('link.no_subject', '(no subject)'))}</span>
                    <span class="pm-pick-meta"><span class="pm-pick-num">${pmEsc(t.ticket_number)}</span>${t.status ? ' · ' + pmEsc(t.status) : ''}${t.requester ? ' · ' + pmEsc(t.requester) : ''}</span>
                </span>
            </label>`).join('');
    } catch (e) { list.innerHTML = '<div class="pm-empty">' + pmEsc(pmT('link.incidents_failed', 'Failed to load incidents')) + '</div>'; }
}
function pmToggleAllLinkable(checked) {
    document.querySelectorAll('#pmLinkList .pm-pick-cb').forEach(cb => cb.checked = checked);
}
async function pmLinkSelected() {
    const ids = Array.from(document.querySelectorAll('#pmLinkList .pm-pick-cb:checked')).map(cb => cb.value);
    if (!ids.length) { pmToast(pmT('link.need_incident', 'Select at least one incident'), 'warning'); return; }
    const btn = document.getElementById('pmLinkSelBtn');
    btn.disabled = true; const orig = btn.textContent; btn.textContent = pmT('link.linking', 'Linking…');
    let ok = 0, fail = 0;
    for (const id of ids) {
        try {
            const res = await fetch(PM_API + 'link_ticket.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId, ticket_id: parseInt(id, 10) }) });
            const data = await res.json();
            if (data.success) ok++; else fail++;
        } catch (e) { fail++; }
    }
    btn.disabled = false; btn.textContent = orig;
    document.getElementById('pmLinkModal').classList.remove('active');
    if (ok) {
        const done = ok === 1 ? pmT('link.linked_incident_one', '1 incident linked')
                             : pmT('link.linked_incident_many', '{n} incidents linked', { n: ok });
        pmToast(fail ? pmT('link.and_failed', '{done}, {n} failed', { done: done, n: fail }) : done,
                fail ? 'warning' : 'success');
    }
    else pmToast(pmT('link.failed', 'Link failed'), 'error');
    pmOpenDetail(pmCurrentId);
}
async function pmUnlinkIncident(ticketId) {
    const ok = await showConfirm({
        title: pmT('link.unlink_incident_title', 'Unlink incident?'),
        message: pmT('link.unlink_incident_message', 'This removes the link to this problem. The incident itself is not deleted.'),
        okLabel: pmT('link.unlink', 'Unlink'), okClass: 'danger'
    });
    if (!ok) return;
    try {
        const res = await fetch(PM_API + 'unlink_ticket.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId, ticket_id: ticketId }) });
        const data = await res.json();
        if (data.success) { pmToast(pmT('link.unlinked', 'Unlinked'), 'success'); pmOpenDetail(pmCurrentId); } else pmToast(data.error || pmT('link.unlink_failed', 'Failed'), 'error');
    } catch (e) { pmToast(pmT('link.unlink_failed', 'Failed'), 'error'); }
}
let pmLinkChangeSearchTimer = null;
function pmLinkChange() {
    if (!pmCurrentId) return;
    const search = document.getElementById('pmLinkChangeSearch'); if (search) search.value = '';
    const all = document.getElementById('pmLinkChangeAll'); if (all) all.checked = false;
    document.getElementById('pmLinkChangeModal').classList.add('active');
    pmLoadLinkableChanges();
}
function pmLinkChangeSearchDebounced() {
    clearTimeout(pmLinkChangeSearchTimer);
    pmLinkChangeSearchTimer = setTimeout(pmLoadLinkableChanges, 250);
}
async function pmLoadLinkableChanges() {
    const list = document.getElementById('pmLinkChangeList');
    const q = (document.getElementById('pmLinkChangeSearch') || {}).value || '';
    list.innerHTML = '<div class="pm-empty">' + pmEsc(pmT('list.loading', 'Loading…')) + '</div>';
    try {
        const res = await fetch(PM_API + 'list_linkable_changes.php?problem_id=' + pmCurrentId + '&q=' + encodeURIComponent(q.trim()));
        const data = await res.json();
        if (!data.success) { list.innerHTML = '<div class="pm-empty">' + pmEsc(data.error || pmT('list.load_failed', 'Failed to load')) + '</div>'; return; }
        if (!data.changes.length) { list.innerHTML = '<div class="pm-empty">' + pmEsc(q.trim() ? pmT('link.no_matching_changes', 'No matching changes.') : pmT('link.no_changes', 'No changes available to link.')) + '</div>'; return; }
        list.innerHTML = data.changes.map(c => `
            <label class="pm-pick-row">
                <input type="checkbox" class="pm-pick-cb" value="${c.id}">
                <span class="pm-pick-main">
                    <span class="pm-pick-title">${pmEsc(c.title || '(no title)')}</span>
                    <span class="pm-pick-meta"><span class="pm-pick-num">#${c.id}</span>${c.status ? ' · ' + pmEsc(c.status) : ''}${c.priority ? ' · ' + pmEsc(c.priority) : ''}</span>
                </span>
            </label>`).join('');
    } catch (e) { list.innerHTML = '<div class="pm-empty">' + pmEsc(pmT('link.changes_failed', 'Failed to load changes')) + '</div>'; }
}
function pmToggleAllLinkableChanges(checked) {
    document.querySelectorAll('#pmLinkChangeList .pm-pick-cb').forEach(cb => cb.checked = checked);
}
async function pmLinkChangeSelected() {
    const ids = Array.from(document.querySelectorAll('#pmLinkChangeList .pm-pick-cb:checked')).map(cb => cb.value);
    if (!ids.length) { pmToast(pmT('link.need_change', 'Select at least one change'), 'warning'); return; }
    const btn = document.getElementById('pmLinkChangeSelBtn');
    btn.disabled = true; const orig = btn.textContent; btn.textContent = pmT('link.linking', 'Linking…');
    let ok = 0, fail = 0;
    for (const id of ids) {
        try {
            const res = await fetch(PM_API + 'link_change.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId, change_id: parseInt(id, 10) }) });
            const data = await res.json();
            if (data.success) ok++; else fail++;
        } catch (e) { fail++; }
    }
    btn.disabled = false; btn.textContent = orig;
    document.getElementById('pmLinkChangeModal').classList.remove('active');
    if (ok) {
        const done = ok === 1 ? pmT('link.linked_change_one', '1 change linked')
                             : pmT('link.linked_change_many', '{n} changes linked', { n: ok });
        pmToast(fail ? pmT('link.and_failed', '{done}, {n} failed', { done: done, n: fail }) : done,
                fail ? 'warning' : 'success');
    }
    else pmToast(pmT('link.failed', 'Link failed'), 'error');
    pmOpenDetail(pmCurrentId);
}
async function pmUnlinkChange(changeId) {
    const ok = await showConfirm({
        title: pmT('link.unlink_change_title', 'Unlink change?'),
        message: pmT('link.unlink_change_message', 'This removes the link to this problem. The change itself is not deleted.'),
        okLabel: pmT('link.unlink', 'Unlink'), okClass: 'danger'
    });
    if (!ok) return;
    try {
        const res = await fetch(PM_API + 'unlink_change.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId, change_id: changeId }) });
        const data = await res.json();
        if (data.success) { pmToast(pmT('link.unlinked', 'Unlinked'), 'success'); pmOpenDetail(pmCurrentId); } else pmToast(data.error || pmT('link.unlink_failed', 'Failed'), 'error');
    } catch (e) { pmToast(pmT('link.unlink_failed', 'Failed'), 'error'); }
}

async function pmAddNote() {
    const ta = document.getElementById('pmNoteInput');
    const note = ((ta && ta.value) || '').trim();
    if (!note) { pmToast(pmT('detail.note_empty', 'Enter a note first'), 'warning'); return; }
    try {
        const res = await fetch(PM_API + 'add_note.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId, note }) });
        const data = await res.json();
        if (!data.success) { pmToast(data.error || pmT('detail.note_failed', 'Failed to add note'), 'error'); return; }
        pmToast(pmT('detail.note_added', 'Note added'), 'success'); pmOpenDetail(pmCurrentId);
    } catch (e) { pmToast('Failed to add note', 'error'); }
}

// ----- AI (endpoint added in phase D) -----
async function pmAiRootCause() {
    const out = document.getElementById('pmAiOut');
    out.style.display = 'block'; out.textContent = pmT('ai.analysing', 'Analysing the linked incidents…');
    try {
        const res = await fetch(PM_API + 'ai_root_cause.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId }) });
        const data = await res.json();
        if (!data.success) { out.textContent = pmT('ai.prefix', 'AI: {message}', { message: data.error || pmT('ai.failed_word', 'failed') }); return; }
        out.innerHTML = `<strong>${pmT('ai.draft_heading', 'Suggested root cause &amp; workaround (review before saving):')}</strong>\n\n${pmEsc(data.draft || '')}\n\n<button class="pm-btn" onclick="pmApplyAiDraft()">${pmEsc(pmT('ai.open_in_editor', 'Open in editor'))}</button>`;
        out._draft = data;
    } catch (e) { out.textContent = pmT('ai.request_failed', 'AI request failed'); }
}
// ----- AI: detect recurring-incident problems -----
let pmSuggestions = [];
async function pmSuggest() {
    const modal = document.getElementById('pmSuggestModal');
    const body = document.getElementById('pmSuggestBody');
    modal.classList.add('active');
    body.innerHTML = pmEsc(pmT('ai.scanning', 'Scanning recent open incidents…'));
    try {
        const res = await fetch(PM_API + 'ai_suggest_problem.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({}) });
        const data = await res.json();
        if (!data.success) { body.innerHTML = '<div style="color:#c62828;">' + pmEsc(data.error || pmT('ai.failed', 'Failed')) + '</div>'; return; }
        pmSuggestions = data.suggestions || [];
        if (!pmSuggestions.length) { body.innerHTML = `<div style="color:#6b7280;">${pmEsc(pmT('ai.none_found', 'No recurring patterns found across {n} open incidents.', { n: data.scanned }))}</div>`; return; }
        body.innerHTML = pmSuggestions.map((s, i) => `
            <div class="pm-section" style="margin:0 0 12px;">
                <div style="font-weight:600;">${pmEsc(s.title || pmT('ai.untitled', 'Untitled'))}</div>
                <div style="color:#6b7280;font-size:13px;margin:4px 0;">${pmEsc(s.rationale || '')}</div>
                <div style="font-size:12px;margin-bottom:8px;">${(s.ticket_numbers || []).map(t => `<span class="pm-num">${pmEsc(t)}</span>`).join(', ')}</div>
                <button class="pm-btn pm-btn-primary" onclick="pmCreateFromSuggestion(${i})">${pmT('ai.create_and_link', 'Create problem &amp; link these')}</button>
            </div>`).join('');
    } catch (e) { body.innerHTML = '<div style="color:#c62828;">' + pmEsc(pmT('ai.request_error', 'Request failed')) + '</div>'; }
}
async function pmCreateFromSuggestion(i) {
    const s = pmSuggestions[i];
    if (!s) return;
    try {
        const cr = await fetch(PM_API + 'save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ title: s.title || pmT('ai.default_title', 'Recurring problem'), description: s.rationale || '' }) }).then(r => r.json());
        if (!cr.success) { pmToast(cr.error || pmT('ai.create_failed', 'Create failed'), 'error'); return; }
        for (const num of (s.ticket_numbers || [])) {
            await fetch(PM_API + 'link_ticket.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: cr.id, ticket_number: num }) });
        }
        document.getElementById('pmSuggestModal').classList.remove('active');
        pmToast(pmT('ai.created', 'Problem created from suggestion'), 'success');
        pmOpenDetail(cr.id);
    } catch (e) { pmToast(pmT('ai.failed', 'Failed'), 'error'); }
}

function pmApplyAiDraft() {
    const out = document.getElementById('pmAiOut');
    if (!pmDetailCache) return;
    pmOpenEditor(pmDetailCache.problem);
    if (out._draft) {
        if (out._draft.root_cause) document.getElementById('pmRootCause').value = out._draft.root_cause;
        if (out._draft.workaround) document.getElementById('pmWorkaround').value = out._draft.workaround;
    }
}
