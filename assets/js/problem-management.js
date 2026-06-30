/* Problem Management — list / detail / editor SPA. */
const PM_API = '../api/problem-management/';
let pmStatuses = [];
let pmPriorities = [];
let pmAnalysts = [];
let pmFilterStatus = 'all';
let pmSearchTimer = null;
let pmCurrentId = null;       // open detail
let pmDetailCache = null;     // last loaded detail payload

// Open-in-new-tab and unlink icons (feather-style), used in the linked panels.
const PM_OPEN_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>';
const PM_UNLINK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 17H7A5 5 0 0 1 7 7h2"></path><path d="M15 7h2a5 5 0 0 1 4 8"></path><line x1="8" y1="12" x2="12" y2="12"></line><line x1="2" y1="2" x2="22" y2="22"></line></svg>';

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
    if (params.get('id')) pmOpenDetail(parseInt(params.get('id'), 10));
    if (params.get('new')) pmOpenEditor();
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
    const q = document.getElementById('pmSearch').value.trim();
    const params = new URLSearchParams();
    if (pmFilterStatus !== 'all') params.set('status_id', pmFilterStatus);
    if (q) params.set('q', q);
    try {
        const res = await fetch(PM_API + 'list.php?' + params.toString());
        const data = await res.json();
        if (!data.success) { pmToast(data.error || 'Failed to load', 'error'); return; }
        pmRenderFilters(data.status_counts);
        pmRenderList(data.problems);
        document.getElementById('pmCount').textContent = data.total + (data.total === 1 ? ' problem' : ' problems');
    } catch (e) { pmToast('Failed to load problems', 'error'); }
}

function pmRenderFilters(counts) {
    const wrap = document.getElementById('pmStatusFilters');
    const total = (counts || []).reduce((n, s) => n + (s.cnt || 0), 0);
    let html = `<div class="pm-filter ${pmFilterStatus === 'all' ? 'active' : ''}" data-status="all" onclick="pmFilter('all')"><span>All</span><span class="cnt">${total}</span></div>`;
    html += (counts || []).map(s => `<div class="pm-filter ${String(pmFilterStatus) === String(s.id) ? 'active' : ''}" onclick="pmFilter(${s.id})"><span>${pmEsc(s.name)}</span><span class="cnt">${s.cnt || 0}</span></div>`).join('');
    wrap.innerHTML = html;
}

function pmRenderList(problems) {
    const el = document.getElementById('pmList');
    if (!problems.length) { el.innerHTML = '<div class="pm-empty">No problems. Click “New problem” to create one.</div>'; return; }
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
                <span>🎫 ${p.incident_count} incident${p.incident_count == 1 ? '' : 's'}</span>
                ${p.is_known_error == 1 ? '<span class="pm-ke">Known error</span>' : ''}
            </div>
        </div>`).join('');
}

// Sidebar actions must return to the list view — otherwise, when a problem is
// open (e.g. deep-linked via ?id=), they'd update the list while it's hidden
// behind the detail and appear to do nothing.
function pmFilter(statusId) { pmFilterStatus = statusId; pmShowListView(); pmLoadList(); }
function pmDebouncedSearch() { clearTimeout(pmSearchTimer); pmSearchTimer = setTimeout(() => { pmShowListView(); pmLoadList(); }, 300); }

function pmShowListView() {
    pmCurrentId = null;
    document.getElementById('pmDetailView').style.display = 'none';
    document.getElementById('pmListView').style.display = '';
}
function pmBackToList() {
    pmShowListView();
    pmLoadList();
}

async function pmOpenDetail(id) {
    try {
        const res = await fetch(PM_API + 'get.php?id=' + id);
        const data = await res.json();
        if (!data.success) { pmToast(data.error || 'Not found', 'error'); return; }
        pmCurrentId = id; pmDetailCache = data;
        pmRenderDetail(data);
        document.getElementById('pmListView').style.display = 'none';
        const dv = document.getElementById('pmDetailView'); dv.style.display = ''; dv.scrollTop = 0;
    } catch (e) { pmToast('Failed to open problem', 'error'); }
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
                <a class="pm-icon-btn" href="../tickets/index.php?ticket_id=${i.id}" target="_blank" title="Open incident">${PM_OPEN_SVG}</a>
                <button class="pm-icon-btn danger" onclick="pmUnlinkIncident(${i.id})" title="Unlink incident">${PM_UNLINK_SVG}</button>
            </td>
        </tr>`).join('');
    const incidents = `<table class="pm-table"><thead><tr><th>Reference</th><th>Subject</th><th>Status</th><th></th></tr></thead>
        <tbody>${incidentRows || '<tr class="pm-empty-row"><td colspan="4">No incidents linked yet.</td></tr>'}</tbody></table>`;
    const changeRows = (data.changes || []).map(c => `
        <tr>
            <td><a href="../change-management/index.php?change_id=${c.id}" target="_blank">Change #${c.id}</a></td>
            <td>${pmEsc(c.title || '')}</td>
            <td>${pmEsc(c.status || '')}</td>
            <td class="pm-actions">
                <a class="pm-icon-btn" href="../change-management/index.php?change_id=${c.id}" target="_blank" title="Open change">${PM_OPEN_SVG}</a>
                <button class="pm-icon-btn danger" onclick="pmUnlinkChange(${c.id})" title="Unlink change">${PM_UNLINK_SVG}</button>
            </td>
        </tr>`).join('');
    const changes = `<table class="pm-table"><thead><tr><th>Reference</th><th>Title</th><th>Status</th><th></th></tr></thead>
        <tbody>${changeRows || '<tr class="pm-empty-row"><td colspan="4">No change linked yet.</td></tr>'}</tbody></table>`;
    const auditRows = (data.audit || []).map(a => {
        const when = a.created_datetime ? new Date(a.created_datetime.replace(' ', 'T') + 'Z').toLocaleString() : '';
        const what = a.action_type === 'created' ? 'created the problem' : `changed ${pmEsc(a.field_name)}` + (a.new_value ? ` to “${pmEsc(a.new_value)}”` : '');
        return `<tr><td class="pm-when">${when}</td><td>${pmEsc(a.analyst_name || 'Someone')}</td><td>${what}</td></tr>`;
    }).join('');
    const audit = `<table class="pm-table"><thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead>
        <tbody>${auditRows || '<tr class="pm-empty-row"><td colspan="3">No history.</td></tr>'}</tbody></table>`;
    const notes = (data.notes || []).map(n => {
        const when = n.created_datetime ? new Date(n.created_datetime.replace(' ', 'T') + 'Z').toLocaleString() : '';
        return `<div class="pm-note">
            <div class="pm-note-head"><span class="pm-note-who">${pmEsc(n.analyst_name || 'Someone')}</span><span class="pm-note-when">${when}</span></div>
            <div class="pm-note-body">${pmEsc(n.note)}</div>
        </div>`;
    }).join('') || '<div style="color:#9ca3af;font-size:13px;">No notes yet.</div>';

    document.getElementById('pmDetailView').innerHTML = `
        <div class="pm-detail">
            <div class="pm-detail-head">
                <a href="#" onclick="pmBackToList();return false;" style="color:#6a1b9a;text-decoration:none;">← Back</a>
                <span class="pm-num">${pmEsc(p.problem_number || '')}</span>
                <h1>${pmEsc(p.title)}</h1>
                ${statusBadge}
                ${p.is_known_error == 1 ? '<span class="pm-ke">Known error</span>' : ''}
            </div>
            <div style="display:flex;gap:10px;margin:6px 0 4px;flex-wrap:wrap;">
                <button class="pm-btn" onclick="pmEditCurrent()">Edit</button>
                <button class="pm-btn" onclick="pmLinkIncident()">Link incident</button>
                <button class="pm-btn" onclick="pmLinkChange()">Link change</button>
                <button class="pm-btn" onclick="pmAiRootCause()" title="Draft a root cause from the linked incidents">🤖 Draft root cause</button>
                <button class="pm-btn pm-btn-danger" onclick="pmDelete()">Delete</button>
            </div>
            <div class="pm-ai-out" id="pmAiOut"></div>

            <div class="pm-section">
                <h3>Details</h3>
                <div class="pm-grid2">
                    <div><div class="pm-field-label">Priority</div><div class="pm-field-val">${pmEsc(p.priority_name || '—')}</div></div>
                    <div><div class="pm-field-label">Assigned to</div><div class="pm-field-val">${pmEsc(p.assignee_name || '—')}</div></div>
                </div>
                <div class="pm-field-label">Description</div><div class="pm-field-val">${pmEsc(p.description || '—')}</div>
                <div class="pm-field-label">Root cause</div><div class="pm-field-val">${pmEsc(p.root_cause || '—')}</div>
                <div class="pm-field-label">Workaround</div><div class="pm-field-val">${pmEsc(p.workaround || '—')}</div>
            </div>

            <div class="pm-section">
                <h3>Linked incidents (${(data.incidents || []).length})</h3>
                ${incidents}
            </div>
            <div class="pm-section">
                <h3>Fix (linked change)</h3>
                ${changes}
            </div>
            <div class="pm-section">
                <h3>Notes</h3>
                <div class="pm-note-add">
                    <textarea id="pmNoteInput" rows="2" placeholder="Add a note…"></textarea>
                    <button class="pm-btn pm-btn-primary" onclick="pmAddNote()">Add</button>
                </div>
                <div class="pm-notes">${notes}</div>
            </div>
            <div class="pm-section">
                <h3>History</h3>
                ${audit}
            </div>
        </div>`;
}

// ----- Editor -----
function pmFillSelect(sel, items, selected, blank) {
    sel.innerHTML = (blank ? '<option value=""></option>' : '') + items.map(i =>
        `<option value="${i.id}" ${String(selected) === String(i.id) ? 'selected' : ''}>${pmEsc(i.name || i.full_name)}</option>`).join('');
}

function pmOpenEditor(problem) {
    const p = problem || {};
    document.getElementById('pmId').value = p.id || '';
    document.getElementById('pmModalTitle').textContent = p.id ? ('Edit ' + (p.problem_number || 'problem')) : 'New problem';
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
    if (!payload.title) { pmToast('Title is required', 'error'); return; }
    try {
        const res = await fetch(PM_API + 'save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const data = await res.json();
        if (!data.success) { pmToast(data.error || 'Save failed', 'error'); return; }
        pmToast(data.message || 'Saved', 'success');
        pmCloseEditor();
        // Open the problem we just saved — the edited one (payload.id) or, for a
        // new problem, the id the server returns. Keying off pmCurrentId was wrong:
        // creating a problem while another was open reopened the old one.
        pmOpenDetail((payload.id && payload.id != 0) ? payload.id : data.id);
    } catch (e) { pmToast('Save failed', 'error'); }
}

async function pmDelete() {
    if (!pmCurrentId) return;
    const ok = window.showConfirm
        ? await showConfirm({ title: 'Delete problem?', message: 'Linked incidents are not deleted; they just lose the link. This cannot be undone.', okLabel: 'Delete', okClass: 'danger' })
        : confirm('Delete this problem? Linked incidents are not deleted; they just lose the link.');
    if (!ok) return;
    try {
        const res = await fetch(PM_API + 'delete.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: pmCurrentId }) });
        const data = await res.json();
        if (!data.success) { pmToast(data.error || 'Delete failed', 'error'); return; }
        pmToast('Problem deleted', 'success'); pmBackToList();
    } catch (e) { pmToast('Delete failed', 'error'); }
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
    list.innerHTML = '<div class="pm-empty">Loading…</div>';
    try {
        const res = await fetch(PM_API + 'list_linkable_tickets.php?problem_id=' + pmCurrentId + '&q=' + encodeURIComponent(q.trim()));
        const data = await res.json();
        if (!data.success) { list.innerHTML = '<div class="pm-empty">' + pmEsc(data.error || 'Failed to load') + '</div>'; return; }
        if (!data.tickets.length) { list.innerHTML = '<div class="pm-empty">' + (q.trim() ? 'No matching open incidents.' : 'No open incidents available to link.') + '</div>'; return; }
        list.innerHTML = data.tickets.map(t => `
            <label class="pm-pick-row">
                <input type="checkbox" class="pm-pick-cb" value="${t.id}">
                <span class="pm-pick-main">
                    <span class="pm-pick-title">${pmEsc(t.subject || '(no subject)')}</span>
                    <span class="pm-pick-meta"><span class="pm-pick-num">${pmEsc(t.ticket_number)}</span>${t.status ? ' · ' + pmEsc(t.status) : ''}${t.requester ? ' · ' + pmEsc(t.requester) : ''}</span>
                </span>
            </label>`).join('');
    } catch (e) { list.innerHTML = '<div class="pm-empty">Failed to load incidents</div>'; }
}
function pmToggleAllLinkable(checked) {
    document.querySelectorAll('#pmLinkList .pm-pick-cb').forEach(cb => cb.checked = checked);
}
async function pmLinkSelected() {
    const ids = Array.from(document.querySelectorAll('#pmLinkList .pm-pick-cb:checked')).map(cb => cb.value);
    if (!ids.length) { pmToast('Select at least one incident', 'warning'); return; }
    const btn = document.getElementById('pmLinkSelBtn');
    btn.disabled = true; const orig = btn.textContent; btn.textContent = 'Linking…';
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
    if (ok) pmToast(ok + (ok === 1 ? ' incident linked' : ' incidents linked') + (fail ? ', ' + fail + ' failed' : ''), fail ? 'warning' : 'success');
    else pmToast('Link failed', 'error');
    pmOpenDetail(pmCurrentId);
}
async function pmUnlinkIncident(ticketId) {
    const ok = await showConfirm({ title: 'Unlink incident?', message: 'This removes the link to this problem. The incident itself is not deleted.', okLabel: 'Unlink', okClass: 'danger' });
    if (!ok) return;
    try {
        const res = await fetch(PM_API + 'unlink_ticket.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId, ticket_id: ticketId }) });
        const data = await res.json();
        if (data.success) { pmToast('Unlinked', 'success'); pmOpenDetail(pmCurrentId); } else pmToast(data.error || 'Failed', 'error');
    } catch (e) { pmToast('Failed', 'error'); }
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
    list.innerHTML = '<div class="pm-empty">Loading…</div>';
    try {
        const res = await fetch(PM_API + 'list_linkable_changes.php?problem_id=' + pmCurrentId + '&q=' + encodeURIComponent(q.trim()));
        const data = await res.json();
        if (!data.success) { list.innerHTML = '<div class="pm-empty">' + pmEsc(data.error || 'Failed to load') + '</div>'; return; }
        if (!data.changes.length) { list.innerHTML = '<div class="pm-empty">' + (q.trim() ? 'No matching changes.' : 'No changes available to link.') + '</div>'; return; }
        list.innerHTML = data.changes.map(c => `
            <label class="pm-pick-row">
                <input type="checkbox" class="pm-pick-cb" value="${c.id}">
                <span class="pm-pick-main">
                    <span class="pm-pick-title">${pmEsc(c.title || '(no title)')}</span>
                    <span class="pm-pick-meta"><span class="pm-pick-num">#${c.id}</span>${c.status ? ' · ' + pmEsc(c.status) : ''}${c.priority ? ' · ' + pmEsc(c.priority) : ''}</span>
                </span>
            </label>`).join('');
    } catch (e) { list.innerHTML = '<div class="pm-empty">Failed to load changes</div>'; }
}
function pmToggleAllLinkableChanges(checked) {
    document.querySelectorAll('#pmLinkChangeList .pm-pick-cb').forEach(cb => cb.checked = checked);
}
async function pmLinkChangeSelected() {
    const ids = Array.from(document.querySelectorAll('#pmLinkChangeList .pm-pick-cb:checked')).map(cb => cb.value);
    if (!ids.length) { pmToast('Select at least one change', 'warning'); return; }
    const btn = document.getElementById('pmLinkChangeSelBtn');
    btn.disabled = true; const orig = btn.textContent; btn.textContent = 'Linking…';
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
    if (ok) pmToast(ok + (ok === 1 ? ' change linked' : ' changes linked') + (fail ? ', ' + fail + ' failed' : ''), fail ? 'warning' : 'success');
    else pmToast('Link failed', 'error');
    pmOpenDetail(pmCurrentId);
}
async function pmUnlinkChange(changeId) {
    const ok = await showConfirm({ title: 'Unlink change?', message: 'This removes the link to this problem. The change itself is not deleted.', okLabel: 'Unlink', okClass: 'danger' });
    if (!ok) return;
    try {
        const res = await fetch(PM_API + 'unlink_change.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId, change_id: changeId }) });
        const data = await res.json();
        if (data.success) { pmToast('Unlinked', 'success'); pmOpenDetail(pmCurrentId); } else pmToast(data.error || 'Failed', 'error');
    } catch (e) { pmToast('Failed', 'error'); }
}

async function pmAddNote() {
    const ta = document.getElementById('pmNoteInput');
    const note = ((ta && ta.value) || '').trim();
    if (!note) { pmToast('Enter a note first', 'warning'); return; }
    try {
        const res = await fetch(PM_API + 'add_note.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId, note }) });
        const data = await res.json();
        if (!data.success) { pmToast(data.error || 'Failed to add note', 'error'); return; }
        pmToast('Note added', 'success'); pmOpenDetail(pmCurrentId);
    } catch (e) { pmToast('Failed to add note', 'error'); }
}

// ----- AI (endpoint added in phase D) -----
async function pmAiRootCause() {
    const out = document.getElementById('pmAiOut');
    out.style.display = 'block'; out.textContent = 'Analysing the linked incidents…';
    try {
        const res = await fetch(PM_API + 'ai_root_cause.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId }) });
        const data = await res.json();
        if (!data.success) { out.textContent = 'AI: ' + (data.error || 'failed'); return; }
        out.innerHTML = `<strong>Suggested root cause &amp; workaround (review before saving):</strong>\n\n${pmEsc(data.draft || '')}\n\n<button class="pm-btn" onclick="pmApplyAiDraft()">Open in editor</button>`;
        out._draft = data;
    } catch (e) { out.textContent = 'AI request failed'; }
}
// ----- AI: detect recurring-incident problems -----
let pmSuggestions = [];
async function pmSuggest() {
    const modal = document.getElementById('pmSuggestModal');
    const body = document.getElementById('pmSuggestBody');
    modal.classList.add('active');
    body.innerHTML = 'Scanning recent open incidents…';
    try {
        const res = await fetch(PM_API + 'ai_suggest_problem.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({}) });
        const data = await res.json();
        if (!data.success) { body.innerHTML = '<div style="color:#c62828;">' + pmEsc(data.error || 'Failed') + '</div>'; return; }
        pmSuggestions = data.suggestions || [];
        if (!pmSuggestions.length) { body.innerHTML = `<div style="color:#6b7280;">No recurring patterns found across ${data.scanned} open incidents.</div>`; return; }
        body.innerHTML = pmSuggestions.map((s, i) => `
            <div class="pm-section" style="margin:0 0 12px;">
                <div style="font-weight:600;">${pmEsc(s.title || 'Untitled')}</div>
                <div style="color:#6b7280;font-size:13px;margin:4px 0;">${pmEsc(s.rationale || '')}</div>
                <div style="font-size:12px;margin-bottom:8px;">${(s.ticket_numbers || []).map(t => `<span class="pm-num">${pmEsc(t)}</span>`).join(', ')}</div>
                <button class="pm-btn pm-btn-primary" onclick="pmCreateFromSuggestion(${i})">Create problem &amp; link these</button>
            </div>`).join('');
    } catch (e) { body.innerHTML = '<div style="color:#c62828;">Request failed</div>'; }
}
async function pmCreateFromSuggestion(i) {
    const s = pmSuggestions[i];
    if (!s) return;
    try {
        const cr = await fetch(PM_API + 'save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ title: s.title || 'Recurring problem', description: s.rationale || '' }) }).then(r => r.json());
        if (!cr.success) { pmToast(cr.error || 'Create failed', 'error'); return; }
        for (const num of (s.ticket_numbers || [])) {
            await fetch(PM_API + 'link_ticket.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: cr.id, ticket_number: num }) });
        }
        document.getElementById('pmSuggestModal').classList.remove('active');
        pmToast('Problem created from suggestion', 'success');
        pmOpenDetail(cr.id);
    } catch (e) { pmToast('Failed', 'error'); }
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
