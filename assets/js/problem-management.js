/* Problem Management — list / detail / editor SPA. */
const PM_API = '../api/problem-management/';
let pmStatuses = [];
let pmPriorities = [];
let pmAnalysts = [];
let pmFilterStatus = 'all';
let pmSearchTimer = null;
let pmCurrentId = null;       // open detail
let pmDetailCache = null;     // last loaded detail payload

function pmEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function pmToast(msg, type) {
    // Lightweight toast (the module loads inbox.css; reuse its toast if present, else alert-lite).
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

function pmFilter(statusId) { pmFilterStatus = statusId; pmLoadList(); }
function pmDebouncedSearch() { clearTimeout(pmSearchTimer); pmSearchTimer = setTimeout(pmLoadList, 300); }

function pmBackToList() {
    pmCurrentId = null;
    document.getElementById('pmDetailView').style.display = 'none';
    document.getElementById('pmListView').style.display = '';
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
    const incidents = (data.incidents || []).map(i => `
        <div class="pm-link-row">
            <a href="../tickets/index.php?ticket_id=${i.id}" target="_blank">${pmEsc(i.ticket_number || ('#' + i.id))}</a>
            <span style="flex:1;">${pmEsc(i.subject || '')}</span>
            <span style="color:#6b7280;font-size:12px;">${pmEsc(i.status || '')}</span>
            <a href="#" onclick="pmUnlinkIncident(${i.id});return false;" style="color:#c62828;" title="Unlink">✕</a>
        </div>`).join('') || '<div style="color:#9ca3af;font-size:13px;">No incidents linked yet.</div>';
    const changes = (data.changes || []).map(c => `
        <div class="pm-link-row">
            <a href="../change-management/index.php?id=${c.id}" target="_blank">Change #${c.id}</a>
            <span style="flex:1;">${pmEsc(c.title || '')}</span>
            <span style="color:#6b7280;font-size:12px;">${pmEsc(c.status || '')}</span>
            <a href="#" onclick="pmUnlinkChange(${c.id});return false;" style="color:#c62828;" title="Unlink">✕</a>
        </div>`).join('') || '<div style="color:#9ca3af;font-size:13px;">No change linked yet.</div>';
    const audit = (data.audit || []).map(a => {
        const when = a.created_datetime ? new Date(a.created_datetime.replace(' ', 'T') + 'Z').toLocaleString() : '';
        const what = a.action_type === 'created' ? 'created the problem' : `changed ${pmEsc(a.field_name)}` + (a.new_value ? ` to “${pmEsc(a.new_value)}”` : '');
        return `<div class="pm-audit">${when} — ${pmEsc(a.analyst_name || 'Someone')} ${what}</div>`;
    }).join('');

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
                <h3>History</h3>
                ${audit || '<div style="color:#9ca3af;font-size:13px;">No history.</div>'}
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
        if (pmCurrentId) pmOpenDetail(pmCurrentId); else pmOpenDetail(data.id);
    } catch (e) { pmToast('Save failed', 'error'); }
}

async function pmDelete() {
    if (!pmCurrentId || !confirm('Delete this problem? Linked incidents are not deleted; they just lose the link.')) return;
    try {
        const res = await fetch(PM_API + 'delete.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: pmCurrentId }) });
        const data = await res.json();
        if (!data.success) { pmToast(data.error || 'Delete failed', 'error'); return; }
        pmToast('Problem deleted', 'success'); pmBackToList();
    } catch (e) { pmToast('Delete failed', 'error'); }
}

// ----- Linking (endpoints added in phases B/C) -----
async function pmLinkIncident() {
    const num = prompt('Link an incident — enter its ticket number (e.g. ABC-123-45678):');
    if (!num) return;
    try {
        const res = await fetch(PM_API + 'link_ticket.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId, ticket_number: num.trim() }) });
        const data = await res.json();
        if (!data.success) { pmToast(data.error || 'Link failed', 'error'); return; }
        pmToast('Incident linked', 'success'); pmOpenDetail(pmCurrentId);
    } catch (e) { pmToast('Link failed', 'error'); }
}
async function pmUnlinkIncident(ticketId) {
    try {
        const res = await fetch(PM_API + 'unlink_ticket.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId, ticket_id: ticketId }) });
        const data = await res.json();
        if (data.success) { pmToast('Unlinked', 'success'); pmOpenDetail(pmCurrentId); } else pmToast(data.error || 'Failed', 'error');
    } catch (e) { pmToast('Failed', 'error'); }
}
async function pmLinkChange() {
    const cid = prompt('Link the change that fixes this — enter the Change ID (the number in its URL):');
    if (!cid) return;
    try {
        const res = await fetch(PM_API + 'link_change.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId, change_id: parseInt(cid, 10) }) });
        const data = await res.json();
        if (!data.success) { pmToast(data.error || 'Link failed', 'error'); return; }
        pmToast('Change linked', 'success'); pmOpenDetail(pmCurrentId);
    } catch (e) { pmToast('Link failed', 'error'); }
}
async function pmUnlinkChange(changeId) {
    try {
        const res = await fetch(PM_API + 'unlink_change.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ problem_id: pmCurrentId, change_id: changeId }) });
        const data = await res.json();
        if (data.success) { pmToast('Unlinked', 'success'); pmOpenDetail(pmCurrentId); } else pmToast(data.error || 'Failed', 'error');
    } catch (e) { pmToast('Failed', 'error'); }
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
function pmApplyAiDraft() {
    const out = document.getElementById('pmAiOut');
    if (!pmDetailCache) return;
    pmOpenEditor(pmDetailCache.problem);
    if (out._draft) {
        if (out._draft.root_cause) document.getElementById('pmRootCause').value = out._draft.root_cause;
        if (out._draft.workaround) document.getElementById('pmWorkaround').value = out._draft.workaround;
    }
}
