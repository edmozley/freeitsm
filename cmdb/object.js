/**
 * CMDB Object Detail Page
 * Renders an object hydrated from get_object.php and provides:
 * - Inline edit of name and each property value (type-aware editors)
 * - Parent picker modal
 * - Children list (read-only — children are managed from their own page)
 * - Outgoing + incoming relationships with add/remove
 * - Delete object (with cascade warning)
 */

const API = '../api/cmdb/';
const OBJECT_ID = window.OBJECT_ID;

let obj = null;
let impact = null; // {descendants, referenced_by_property, referenced_by_relationship}
let activity = null; // {open, closed, total_closed} — tickets that reference this object
let relationshipTypes = [];
let allClasses = []; // cached for the property-edit target-class dropdown
let acTimer = null;
let acHighlightedIdx = -1;
let summaryGenerating = false;

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}
function showInlineToast(msg, isError = false) {
    if (typeof showToast === 'function') showToast(msg, isError ? 'error' : 'success');
    else showToast(msg, 'error');
}
async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    });
    return res.json();
}
function formatDate(s) {
    if (!s) return '';
    try {
        const d = new Date(s.replace(' ', 'T') + 'Z');
        return d.toLocaleString();
    } catch (e) { return s; }
}

document.addEventListener('DOMContentLoaded', () => {
    if (!OBJECT_ID) {
        document.getElementById('objPage').innerHTML = `<div style="padding:40px;text-align:center;color:#b91c1c;">${escapeHtml(window.t('cmdb.object.missing_id'))}</div>`;
        return;
    }
    Promise.all([loadObject(), loadImpact(), loadActivity(), loadRelationshipTypes(), loadAllClasses()]).then(() => {
        if (obj) render();
    });
    initPropDefModalDrag();
});

async function loadImpact() {
    try {
        const res = await fetch(API + 'get_object_impact.php?id=' + OBJECT_ID);
        const data = await res.json();
        if (data.success) impact = data.impact;
    } catch (e) { /* impact panel will just show "computing…" */ }
}

async function loadActivity() {
    try {
        const res = await fetch(API + 'get_object_tickets.php?id=' + OBJECT_ID);
        const data = await res.json();
        if (data.success) activity = data;
    } catch (e) { /* activity panel will just show "computing…" */ }
}

async function loadAllClasses() {
    try {
        const res = await fetch(API + 'get_classes.php');
        const data = await res.json();
        if (data.success) allClasses = (data.classes || []).filter(c => c.is_active);
    } catch (e) { /* ignore — target-class picker will just be empty */ }
}

async function loadObject() {
    try {
        const res = await fetch(API + 'get_object.php?id=' + OBJECT_ID);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || window.t('cmdb.object.load_failed'));
        obj = data.object;
    } catch (err) {
        document.getElementById('objPage').innerHTML = `<div style="padding:40px;text-align:center;color:#b91c1c;">${escapeHtml(window.t('cmdb.object.error_prefix', { message: err.message }))}</div>`;
    }
}

async function loadRelationshipTypes() {
    try {
        const res = await fetch(API + 'get_relationship_types.php');
        const data = await res.json();
        if (data.success) relationshipTypes = (data.relationship_types || []).filter(r => r.is_active);
    } catch (e) { /* ignore */ }
}

// ---------- Render ----------

function render() {
    const html = `
        <div class="obj-breadcrumb">
            <a href="./">${escapeHtml(window.t('cmdb.object.breadcrumb_browse'))}</a>
            <span class="sep">›</span>
            <a href="./#class-${obj.class_id}">${escapeHtml(obj.class_name)}</a>
            ${obj.parent_id ? `<span class="sep">›</span><a href="object.php?id=${obj.parent_id}">${escapeHtml(obj.parent_name)}</a>` : ''}
            <span class="sep">›</span>
            <span style="color: #1f2937;">${escapeHtml(obj.name)}</span>
        </div>

        <div class="obj-header${obj.is_planned ? ' is-planned' : ''}">
            <input type="text" class="obj-name" id="objName" value="${escapeHtml(obj.name)}" maxlength="255">
            <div class="obj-meta">
                <span class="class-badge">${escapeHtml(obj.class_name)}</span>
                ${obj.is_planned ? `<span class="planned-pill" title="${escapeHtml(window.t('cmdb.object.planned_title'))}">${escapeHtml(window.t('cmdb.list.planned_pill'))}</span>` : ''}
                <span><strong>${escapeHtml(window.t('cmdb.object.parent'))}</strong>
                    ${obj.parent_id
                        ? `<a href="object.php?id=${obj.parent_id}">${escapeHtml(obj.parent_name)}</a> <span style="color:#9ca3af;">(${escapeHtml(obj.parent_class_name || '')})</span>`
                        : `<span style="color:#d1d5db;">${escapeHtml(window.t('cmdb.object.parent_none'))}</span>`}
                    <button class="btn-mini" style="margin-left: 8px;" onclick="openParentModal()">${escapeHtml(window.t('cmdb.object.edit'))}</button>
                </span>
                <span><strong>${escapeHtml(window.t('cmdb.object.planned'))}</strong>
                    <label style="cursor:pointer; display:inline-flex; align-items:center; gap:5px; vertical-align:middle;">
                        <input type="checkbox" id="objIsPlanned" ${obj.is_planned ? 'checked' : ''} onchange="togglePlanned(this.checked)" style="margin:0;">
                        <span style="font-size:12px; color:#666;">${obj.is_planned ? escapeHtml(window.t('cmdb.object.planned_yes')) : escapeHtml(window.t('cmdb.object.planned_no'))}</span>
                    </label>
                </span>
                <span><strong>${escapeHtml(window.t('cmdb.object.created'))}</strong> ${formatDate(obj.created_datetime)}</span>
                <span><strong>${escapeHtml(window.t('cmdb.object.updated'))}</strong> ${formatDate(obj.updated_datetime)}</span>
            </div>
            <div class="obj-actions">
                <button class="btn btn-danger" onclick="deleteObject()">${escapeHtml(window.t('cmdb.object.delete'))}</button>
            </div>
        </div>

        ${renderAiSummaryCard()}

        ${renderImpactPanel()}

        ${renderActivityPanel()}

        <div class="obj-section">
            <h3>${escapeHtml(window.t('cmdb.object.map'))}</h3>
            ${renderMiniGraph()}
        </div>

        <div class="obj-section">
            <h3>${escapeHtml(window.t('cmdb.object.properties'))}</h3>
            ${renderPropertiesTable()}
        </div>

        <div class="obj-section">
            <h3>${escapeHtml(window.t('cmdb.object.hierarchy'))}</h3>
            ${renderHierarchy()}
        </div>

        <div class="obj-section">
            <h3>
                <span>${escapeHtml(window.t('cmdb.object.relationships'))}</span>
                <button class="btn-mini" onclick="openRelModal()">${escapeHtml(window.t('cmdb.object.add_relationship'))}</button>
            </h3>
            <div class="rel-split">
                <div class="rel-col">
                    <h4>${escapeHtml(window.t('cmdb.object.outgoing'))}</h4>
                    ${renderRelationshipList(obj.relationships.outgoing, 'outgoing')}
                </div>
                <div class="rel-col">
                    <h4>${escapeHtml(window.t('cmdb.object.incoming'))}</h4>
                    ${renderRelationshipList(obj.relationships.incoming, 'incoming')}
                </div>
            </div>
        </div>
    `;
    document.getElementById('objPage').innerHTML = html;

    // Wire the name input
    const nameInput = document.getElementById('objName');
    let originalName = obj.name;
    nameInput.addEventListener('blur', () => {
        const newName = nameInput.value.trim();
        if (newName === originalName) return;
        if (newName === '') { nameInput.value = originalName; showInlineToast(window.t('cmdb.object.name_empty'), true); return; }
        savePartial({ name: newName });
        originalName = newName;
    });
    nameInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); nameInput.blur(); }
        if (e.key === 'Escape') { nameInput.value = originalName; nameInput.blur(); }
    });

    // Wire each property's display cell to start editing on click
    document.querySelectorAll('.prop-display').forEach(el => {
        el.addEventListener('click', () => beginEditProperty(parseInt(el.dataset.pid, 10)));
    });
}

function renderAiSummaryCard() {
    const sparkleSvg = `<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg>`;
    const hasSummary = !!obj.ai_summary;
    let body;
    if (summaryGenerating) {
        body = `<div class="ai-summary-empty">${escapeHtml(window.t('cmdb.summary.synthesising'))}
            <span class="ai-summary-spinner-dot"></span><span class="ai-summary-spinner-dot"></span><span class="ai-summary-spinner-dot"></span>
        </div>`;
    } else if (hasSummary) {
        body = `<div class="ai-summary-text">${escapeHtml(obj.ai_summary)}</div>
                <div class="ai-summary-meta">${escapeHtml(window.t('cmdb.summary.generated', { datetime: formatDate(obj.ai_summary_generated_at) }))}</div>`;
    } else {
        body = `<div class="ai-summary-empty">${window.t('cmdb.summary.empty', { generate: `<strong>${escapeHtml(window.t('cmdb.summary.generate'))}</strong>` })}</div>`;
    }
    const btnLabel = hasSummary ? window.t('cmdb.summary.regenerate') : window.t('cmdb.summary.generate');
    return `
        <div class="ai-summary-card">
            <div class="ai-summary-head">
                <span class="ai-summary-label">${sparkleSvg} ${escapeHtml(window.t('cmdb.summary.label'))}</span>
                <button class="btn-mini" onclick="generateSummary()" ${summaryGenerating ? 'disabled' : ''}>${btnLabel}</button>
            </div>
            ${body}
        </div>
    `;
}

function renderImpactPanel() {
    if (!impact) return ''; // suppressed until loaded
    const desc  = impact.descendants || [];
    const props = impact.referenced_by_property || [];
    const rels  = impact.referenced_by_relationship || [];
    const total = desc.length + props.length + rels.length;

    const bucket = (title, items, emptyMsg, renderItem) => `
        <div class="impact-bucket">
            <h4><span>${title}</span><span class="count-badge">${items.length}</span></h4>
            ${items.length === 0
                ? `<div class="empty">${emptyMsg}</div>`
                : `<ul>${items.map(renderItem).join('')}</ul>`}
        </div>
    `;

    return `
        <div class="obj-section">
            <h3>
                <span>${escapeHtml(window.t('cmdb.impact.heading'))}</span>
                <span style="color: #6b7280; font-weight: 400; font-size: 12px;">${total} ${total === 1 ? escapeHtml(window.t('cmdb.impact.item')) : escapeHtml(window.t('cmdb.impact.items'))}</span>
            </h3>
            <div class="impact-grid">
                ${bucket(escapeHtml(window.t('cmdb.impact.descendants')), desc, escapeHtml(window.t('cmdb.impact.descendants_empty')),
                    d => `<li>
                        <a href="object.php?id=${d.id}">${escapeHtml(d.name)}</a>
                        <span class="meta"> ${escapeHtml(d.class_name)}${d.depth > 1 ? ` · ${escapeHtml(window.t('cmdb.impact.levels_deep', { count: d.depth }))}` : ''}</span>
                    </li>`)}
                ${bucket(escapeHtml(window.t('cmdb.impact.referenced_by_property')), props, escapeHtml(window.t('cmdb.impact.referenced_by_property_empty')),
                    p => `<li>
                        <a href="object.php?id=${p.id}">${escapeHtml(p.name)}</a>
                        <span class="meta"> ${escapeHtml(p.class_name)} · ${escapeHtml(window.t('cmdb.impact.via'))} <em>${escapeHtml(p.property_label)}</em></span>
                    </li>`)}
                ${bucket(escapeHtml(window.t('cmdb.impact.things_link_in')), rels, escapeHtml(window.t('cmdb.impact.things_link_in_empty')),
                    r => `<li>
                        <a href="object.php?id=${r.id}">${escapeHtml(r.name)}</a>
                        <span class="meta"> ${escapeHtml(r.class_name)} · ${escapeHtml(r.inverse_verb)} ${escapeHtml(window.t('cmdb.impact.this'))}</span>
                    </li>`)}
            </div>
        </div>
    `;
}

function renderActivityPanel() {
    if (!activity) return '';
    const open = activity.open || [];
    const closed = activity.closed || [];
    const totalClosed = activity.total_closed || 0;

    if (open.length === 0 && closed.length === 0) {
        return `<div class="obj-section">
            <h3>${escapeHtml(window.t('cmdb.activity.heading'))}</h3>
            <div class="activity-empty">${escapeHtml(window.t('cmdb.activity.empty'))}</div>
        </div>`;
    }

    const renderTicket = (t, isClosed = false) => {
        const status = t.status || window.t('cmdb.activity.unknown_status');
        const colour = t.status_colour || '#6b7280';
        const styleAttr = `style="background:${colour}22; color:${colour}; border-color:${colour}55;"`;
        const ageText = formatDate(isClosed ? t.closed_datetime : t.updated_datetime);
        return `<a class="ticket-card ${isClosed ? 'closed' : ''}" href="../tickets/?ticket_id=${t.id}">
            <div class="ticket-card-body">
                <div class="ticket-card-line1">
                    <span class="ticket-card-number">${escapeHtml(t.ticket_number || '')}</span>
                    <span class="ticket-card-subject">${escapeHtml(t.subject || window.t('cmdb.activity.no_subject'))}</span>
                </div>
                <div class="ticket-card-meta">
                    <span class="ticket-status-pill" ${styleAttr}>${escapeHtml(status)}</span>
                    ${t.priority ? `<span>${escapeHtml(t.priority)}</span>` : ''}
                    ${t.assigned_to ? `<span>${escapeHtml(window.t('cmdb.activity.assigned_to'))} <strong>${escapeHtml(t.assigned_to)}</strong></span>` : `<span style="color:#d1d5db;">${escapeHtml(window.t('cmdb.activity.unassigned'))}</span>`}
                    ${t.department_name ? `<span>${escapeHtml(t.department_name)}</span>` : ''}
                    <span style="color:#9ca3af;">${isClosed ? escapeHtml(window.t('cmdb.activity.closed')) : escapeHtml(window.t('cmdb.activity.updated'))} ${ageText}</span>
                </div>
            </div>
        </a>`;
    };

    let html = '';
    if (open.length > 0) {
        html += `<div style="margin-bottom: ${closed.length > 0 ? '20px' : '0'};">
            <div class="activity-bucket-head">
                <span>${escapeHtml(window.t('cmdb.activity.open_tickets'))}</span>
                <span class="count-badge">${open.length}</span>
            </div>
            <div>${open.map(t => renderTicket(t, false)).join('')}</div>
        </div>`;
    }
    if (closed.length > 0) {
        html += `<div>
            <div class="activity-bucket-head">
                <span>${totalClosed > closed.length ? escapeHtml(window.t('cmdb.activity.recent_closed_showing', { shown: closed.length, total: totalClosed })) : escapeHtml(window.t('cmdb.activity.recent_closed'))}</span>
                <span class="count-badge">${totalClosed}</span>
            </div>
            <div>${closed.map(t => renderTicket(t, true)).join('')}</div>
        </div>`;
    }

    return `<div class="obj-section">
        <h3>${escapeHtml(window.t('cmdb.activity.heading'))}</h3>
        ${html}
    </div>`;
}

function renderMiniGraph() {
    const parent = obj.parent_id ? {
        id: obj.parent_id, name: obj.parent_name, class_name: obj.parent_class_name
    } : null;
    const children = obj.children || [];
    const outgoing = (obj.relationships && obj.relationships.outgoing) || [];
    const incoming = (obj.relationships && obj.relationships.incoming) || [];

    // Cap nodes shown so the graph stays readable; overflow hint at the bottom
    const CAP = 6;
    const childrenShown = children.slice(0, CAP);
    const outgoingShown = outgoing.slice(0, CAP);
    const incomingShown = incoming.slice(0, CAP);
    const overflowParts = [];
    if (children.length > CAP) overflowParts.push(window.t('cmdb.map.more_children', { count: children.length - CAP }));
    if (outgoing.length > CAP) overflowParts.push(window.t('cmdb.map.more_outgoing', { count: outgoing.length - CAP }));
    if (incoming.length > CAP) overflowParts.push(window.t('cmdb.map.more_incoming', { count: incoming.length - CAP }));

    const node = (o, isThis = false) => `
        <a class="mg-node ${isThis ? 'this' : ''}" ${isThis ? '' : `href="object.php?id=${o.id}"`}>
            <span class="mg-node-name">${escapeHtml(o.name)}</span>
            <span class="mg-class">${escapeHtml(o.class_name || '')}</span>
        </a>
    `;

    let html = '';
    if (parent) {
        html += `<div class="mg-row">${node(parent)}</div>`;
        html += `<div class="mg-connector"></div>`;
    }
    html += `<div class="mg-row">${node({ name: obj.name, class_name: obj.class_name }, true)}</div>`;
    if (childrenShown.length > 0) {
        html += `<div class="mg-connector"></div>`;
        html += `<div class="mg-row">${childrenShown.map(c => node(c)).join('')}</div>`;
    }
    if (outgoingShown.length > 0 || incomingShown.length > 0) {
        html += `<div class="mg-side-rels">
            <div class="mg-side">
                <div class="mg-side-label">${escapeHtml(window.t('cmdb.map.outgoing'))}</div>
                ${outgoingShown.length === 0
                    ? `<div class="empty-row" style="padding: 0; font-size: 12px;">${escapeHtml(window.t('cmdb.map.none'))}</div>`
                    : outgoingShown.map(r => `
                        <a class="mg-rel-link" href="object.php?id=${r.other_id}">
                            <span class="mg-rel-verb">${escapeHtml(r.verb)}</span>
                            <strong>${escapeHtml(r.other_name)}</strong>
                            <span style="color:#9ca3af; font-size: 10px;">${escapeHtml(r.other_class_name)}</span>
                        </a>`).join('')
                }
            </div>
            <div class="mg-side">
                <div class="mg-side-label">${escapeHtml(window.t('cmdb.map.incoming'))}</div>
                ${incomingShown.length === 0
                    ? `<div class="empty-row" style="padding: 0; font-size: 12px;">${escapeHtml(window.t('cmdb.map.none'))}</div>`
                    : incomingShown.map(r => `
                        <a class="mg-rel-link" href="object.php?id=${r.other_id}">
                            <span class="mg-rel-verb">${escapeHtml(r.inverse_verb)}</span>
                            <strong>${escapeHtml(r.other_name)}</strong>
                            <span style="color:#9ca3af; font-size: 10px;">${escapeHtml(r.other_class_name)}</span>
                        </a>`).join('')
                }
            </div>
        </div>`;
    }
    if (overflowParts.length > 0) {
        html += `<div style="margin-top: 10px; color: #9ca3af; font-size: 11px;">${overflowParts.join(' · ')}</div>`;
    }

    return `<div class="mini-graph">${html}</div>`;
}

async function generateSummary() {
    summaryGenerating = true;
    render();
    try {
        const data = await postJson(API + 'generate_object_summary.php', { id: obj.id });
        if (!data.success) throw new Error(data.error || window.t('cmdb.summary.generate_failed'));
        obj.ai_summary = data.summary;
        obj.ai_summary_generated_at = data.generated_at;
    } catch (err) {
        showInlineToast(window.t('cmdb.summary.error_prefix', { message: err.message }), true);
    } finally {
        summaryGenerating = false;
        render();
    }
}

function renderPropertiesTable() {
    if (!obj.properties.length) {
        return `<div class="empty-row">${window.t('cmdb.object.props_empty', { link: `<a href="settings/" style="color:#be185d;">${escapeHtml(window.t('cmdb.object.props_empty_link'))}</a>` })}</div>`;
    }
    return `
        <table class="props-table">
            <tbody>
                ${obj.properties.map(p => `
                    <tr>
                        <td class="prop-label">
                            ${escapeHtml(p.label)}${p.is_required ? '<span class="req">*</span>' : ''}
                            <span class="prop-type-tag">${escapeHtml(p.property_type)}</span>
                            <button class="prop-cog" title="${escapeHtml(window.t('cmdb.object.edit_prop_title'))}" onclick="openPropDefModal(${p.property_id})">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            </button>
                        </td>
                        <td class="prop-value" id="propCell_${p.property_id}">
                            ${renderPropertyDisplay(p)}
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
}

function renderPropertyDisplay(p) {
    if (p.property_type === 'object_ref') {
        if (p.value_object) {
            return `<a class="obj-ref-pill" href="object.php?id=${p.value_object.id}">
                ${escapeHtml(p.value_object.name)}
                <span class="pill-class">${escapeHtml(p.value_object.class_name || '')}</span>
            </a>
            <button class="btn-mini" style="margin-left: 8px;" data-pid="${p.property_id}" onclick="beginEditProperty(${p.property_id})">${escapeHtml(window.t('cmdb.object.change'))}</button>
            <button class="btn-mini" style="margin-left: 4px;" data-pid="${p.property_id}" onclick="clearProperty(${p.property_id})">${escapeHtml(window.t('cmdb.object.clear'))}</button>`;
        }
        return `<span class="prop-display empty" data-pid="${p.property_id}">${escapeHtml(window.t('cmdb.object.not_set'))}</span>`;
    }
    if (p.value === null || p.value === undefined || p.value === '') {
        return `<span class="prop-display empty" data-pid="${p.property_id}">${escapeHtml(window.t('cmdb.object.not_set'))}</span>`;
    }

    // Dropdown: render as a coloured pill if the matched option has a colour set
    if (p.property_type === 'dropdown') {
        const matched = (p.options || []).find(o => (o && typeof o === 'object' ? o.value : o) === p.value);
        const colour = matched && typeof matched === 'object' ? matched.colour : null;
        if (colour) {
            // Tinted background + matching-colour text — same pattern as ticket status badges
            return `<span class="prop-display dropdown-pill" data-pid="${p.property_id}"
                          style="background:${colour}22; color:${colour}; border-color:${colour}55;">
                ${escapeHtml(p.value)}
            </span>`;
        }
        return `<span class="prop-display" data-pid="${p.property_id}">${escapeHtml(p.value)}</span>`;
    }

    let displayValue = '';
    if (p.property_type === 'boolean') displayValue = p.value ? window.t('cmdb.object.yes') : window.t('cmdb.object.no');
    else if (p.property_type === 'date') displayValue = String(p.value).substring(0, 10);
    else displayValue = String(p.value);
    return `<span class="prop-display" data-pid="${p.property_id}">${escapeHtml(displayValue)}</span>`;
}

function renderHierarchy() {
    let html = '';
    if (obj.parent_id) {
        html += `<div style="margin-bottom: 12px;"><strong style="color:#6b7280; font-size:12px; text-transform:uppercase;">${escapeHtml(window.t('cmdb.object.hierarchy_parent'))}</strong>
            <ul class="item-list" style="margin-top: 6px;">
                <li><span><a href="object.php?id=${obj.parent_id}">${escapeHtml(obj.parent_name)}</a> <span class="meta">${escapeHtml(obj.parent_class_name || '')}</span></span></li>
            </ul>
        </div>`;
    } else {
        html += `<div style="margin-bottom: 12px; color: #9ca3af; font-style: italic; font-size: 13px;">${escapeHtml(window.t('cmdb.object.hierarchy_no_parent'))}</div>`;
    }
    html += `<div><strong style="color:#6b7280; font-size:12px; text-transform:uppercase;">${escapeHtml(window.t('cmdb.object.hierarchy_children', { count: obj.children.length }))}</strong>`;
    if (obj.children.length === 0) {
        html += `<div class="empty-row" style="margin-top: 6px;">${escapeHtml(window.t('cmdb.object.hierarchy_children_empty'))}</div>`;
    } else {
        html += `<ul class="item-list" style="margin-top: 6px;">
            ${obj.children.map(ch => `<li><span><a href="object.php?id=${ch.id}">${escapeHtml(ch.name)}</a> <span class="meta">${escapeHtml(ch.class_name)}</span></span></li>`).join('')}
        </ul>`;
    }
    html += `</div>`;
    return html;
}

function renderRelationshipList(rels, direction) {
    if (rels.length === 0) {
        return `<div class="empty-row">${escapeHtml(window.t('cmdb.object.rel_none'))}</div>`;
    }
    return `<ul class="item-list">
        ${rels.map(r => `
            <li>
                <span>
                    <span class="verb">${escapeHtml(direction === 'outgoing' ? r.verb : r.inverse_verb)}</span>
                    <a href="object.php?id=${r.other_id}">${escapeHtml(r.other_name)}</a>
                    <span class="meta">${escapeHtml(r.other_class_name)}</span>
                </span>
                <button class="x-btn" title="${escapeHtml(window.t('cmdb.object.remove'))}" onclick="deleteRelationship(${r.id})">×</button>
            </li>
        `).join('')}
    </ul>`;
}

// ---------- Inline property editing ----------

function beginEditProperty(propertyId) {
    const p = obj.properties.find(x => x.property_id === propertyId);
    if (!p) return;
    const cell = document.getElementById('propCell_' + propertyId);
    if (!cell) return;

    let editorHtml = '';
    const v = p.value;

    switch (p.property_type) {
        case 'text':
            editorHtml = `<input type="text" class="prop-edit" id="propEdit_${propertyId}" value="${escapeHtml(v ?? '')}">`;
            break;
        case 'number':
            editorHtml = `<input type="number" class="prop-edit" id="propEdit_${propertyId}" value="${v ?? ''}" step="any">`;
            break;
        case 'date':
            editorHtml = `<input type="date" class="prop-edit" id="propEdit_${propertyId}" value="${(v ?? '').substring(0, 10)}">`;
            break;
        case 'boolean':
            editorHtml = `<select class="prop-edit" id="propEdit_${propertyId}">
                <option value="">${escapeHtml(window.t('cmdb.object.not_set_option'))}</option>
                <option value="1" ${v === true ? 'selected' : ''}>${escapeHtml(window.t('cmdb.object.yes'))}</option>
                <option value="0" ${v === false ? 'selected' : ''}>${escapeHtml(window.t('cmdb.object.no'))}</option>
            </select>`;
            break;
        case 'dropdown':
            editorHtml = `<select class="prop-edit" id="propEdit_${propertyId}">
                <option value="">${escapeHtml(window.t('cmdb.object.not_set_option'))}</option>
                ${p.options.map(opt => {
                    const val = (opt && typeof opt === 'object') ? opt.value : opt;
                    return `<option value="${escapeHtml(val)}" ${v === val ? 'selected' : ''}>${escapeHtml(val)}</option>`;
                }).join('')}
            </select>`;
            break;
        case 'object_ref':
            editorHtml = `<div class="autocomplete-wrap">
                <input type="text" class="prop-edit" id="propEdit_${propertyId}" autocomplete="off"
                       placeholder="${escapeHtml(window.t('cmdb.object.search_placeholder', { name: p.target_class_name || window.t('cmdb.new_object.objects') }))}"
                       value="${p.value_object ? escapeHtml(p.value_object.name) : ''}">
                <input type="hidden" id="propEditId_${propertyId}" value="${p.value_object ? p.value_object.id : ''}">
                <div class="autocomplete-results" id="propEditResults_${propertyId}"></div>
            </div>`;
            break;
    }

    cell.innerHTML = editorHtml;
    const editor = document.getElementById('propEdit_' + propertyId);
    if (!editor) return;
    editor.focus();
    if (editor.select) editor.select();

    let saved = false;

    const commit = async (newRawValue) => {
        if (saved) return;
        saved = true;
        await savePropertyValue(p, newRawValue);
    };
    const cancel = () => {
        if (saved) return;
        saved = true;
        cell.innerHTML = renderPropertyDisplay(p);
        wirePropDisplay(cell, propertyId);
    };

    if (p.property_type === 'object_ref') {
        const idInput = document.getElementById('propEditId_' + propertyId);
        wireAutocomplete(editor, document.getElementById('propEditResults_' + propertyId),
            { class_id: p.target_class_id, exclude_id: obj.id },
            (picked) => {
                idInput.value = picked.id;
                editor.value = picked.name;
                commit(picked.id);
            }
        );
        editor.addEventListener('blur', () => {
            // Allow click on a result before bailing out
            setTimeout(() => {
                if (saved) return;
                if (editor.value.trim() === '') {
                    commit(null); // cleared
                } else if (idInput.value) {
                    // already picked — commit was called; do nothing
                } else {
                    cancel(); // typed text but didn't pick anything — cancel
                }
            }, 200);
        });
        editor.addEventListener('keydown', e => { if (e.key === 'Escape') cancel(); });
    } else {
        editor.addEventListener('blur', () => commit(rawFromEditor(editor, p.property_type)));
        editor.addEventListener('keydown', e => {
            if (e.key === 'Enter' && p.property_type !== 'date') { e.preventDefault(); editor.blur(); }
            if (e.key === 'Escape') cancel();
        });
    }
}

function rawFromEditor(editor, type) {
    const v = editor.value;
    if (v === '' || v === null) return null;
    if (type === 'boolean') return v === '1';
    if (type === 'number') return v === '' ? null : Number(v);
    return v;
}

function wirePropDisplay(cell, propertyId) {
    const span = cell.querySelector('.prop-display');
    if (span) span.addEventListener('click', () => beginEditProperty(propertyId));
}

async function savePropertyValue(prop, newRawValue) {
    try {
        const data = await postJson(API + 'save_object.php', {
            id: obj.id,
            name: obj.name,
            parent_id: obj.parent_id,
            property_values: [{ property_id: prop.property_id, value: newRawValue }]
        });
        if (!data.success) throw new Error(data.error || window.t('cmdb.object.save_failed'));
        // Reload to pick up the rendered value (esp. object_ref hydration)
        await Promise.all([loadObject(), loadImpact(), loadActivity()]);
        render();
    } catch (err) {
        showInlineToast(window.t('cmdb.object.error_saving', { label: prop.label, message: err.message }), true);
        // Restore the cell
        const cell = document.getElementById('propCell_' + prop.property_id);
        if (cell) {
            cell.innerHTML = renderPropertyDisplay(prop);
            wirePropDisplay(cell, prop.property_id);
        }
    }
}

async function clearProperty(propertyId) {
    const p = obj.properties.find(x => x.property_id === propertyId);
    if (!p) return;
    await savePropertyValue(p, null);
}

// ---------- Save partial (just name / parent) ----------

async function savePartial(patch) {
    try {
        const payload = {
            id: obj.id,
            name: patch.name ?? obj.name,
            parent_id: patch.parent_id !== undefined ? patch.parent_id : obj.parent_id,
            property_values: []
        };
        if (patch.is_planned !== undefined) payload.is_planned = patch.is_planned;
        const data = await postJson(API + 'save_object.php', payload);
        if (!data.success) throw new Error(data.error || window.t('cmdb.object.save_failed'));
        await Promise.all([loadObject(), loadImpact(), loadActivity()]);
        render();
        showInlineToast(window.t('cmdb.object.saved'));
    } catch (err) {
        showInlineToast(window.t('cmdb.object.error_prefix', { message: err.message }), true);
        // Reload from DB to discard the optimistic edit
        await Promise.all([loadObject(), loadImpact(), loadActivity()]);
        render();
    }
}

// Toggle the planned/real state from the header checkbox.
async function togglePlanned(isPlanned) {
    await savePartial({ is_planned: !!isPlanned });
}

// ---------- Parent picker ----------

function openParentModal() {
    document.getElementById('parentInput').value = obj.parent_name || '';
    document.getElementById('parentId').value = obj.parent_id || '';
    document.getElementById('parentResults').classList.remove('active');
    document.getElementById('parentModal').classList.add('active');
    setTimeout(() => document.getElementById('parentInput').focus(), 0);

    wireAutocomplete(
        document.getElementById('parentInput'),
        document.getElementById('parentResults'),
        { exclude_id: obj.id },
        (picked) => {
            document.getElementById('parentId').value = picked.id;
            document.getElementById('parentInput').value = picked.name;
            document.getElementById('parentResults').classList.remove('active');
        }
    );
}
function closeParentModal() { document.getElementById('parentModal').classList.remove('active'); }

async function saveParent() {
    const newId = document.getElementById('parentId').value;
    const text = document.getElementById('parentInput').value.trim();
    if (text === '') {
        // user cleared the box → treat as clear
        closeParentModal();
        await savePartial({ parent_id: null });
        return;
    }
    if (!newId) {
        showInlineToast(window.t('cmdb.parent_modal.pick_suggestion'), true);
        return;
    }
    closeParentModal();
    await savePartial({ parent_id: parseInt(newId, 10) });
}

async function clearParent() {
    closeParentModal();
    if (!obj.parent_id) return;
    await savePartial({ parent_id: null });
}

// ---------- Relationships ----------

function openRelModal() {
    if (relationshipTypes.length === 0) {
        showInlineToast(window.t('cmdb.rel_modal.none_defined'), true);
        return;
    }
    const sel = document.getElementById('relTypeSelect');
    sel.innerHTML = relationshipTypes.map(rt => `<option value="${rt.id}">${escapeHtml(rt.verb)}</option>`).join('');
    updateRelInverseHint();
    sel.onchange = updateRelInverseHint;
    document.getElementById('relTargetInput').value = '';
    document.getElementById('relTargetId').value = '';
    document.getElementById('relTargetResults').classList.remove('active');
    document.getElementById('relModal').classList.add('active');
    setTimeout(() => document.getElementById('relTargetInput').focus(), 0);

    wireAutocomplete(
        document.getElementById('relTargetInput'),
        document.getElementById('relTargetResults'),
        { exclude_id: obj.id },
        (picked) => {
            document.getElementById('relTargetId').value = picked.id;
            document.getElementById('relTargetInput').value = picked.name;
            document.getElementById('relTargetResults').classList.remove('active');
        }
    );
}
function closeRelModal() { document.getElementById('relModal').classList.remove('active'); }

function updateRelInverseHint() {
    const id = parseInt(document.getElementById('relTypeSelect').value, 10);
    const rt = relationshipTypes.find(r => r.id === id);
    const hint = document.getElementById('relInverseHint');
    if (rt) hint.textContent = window.t('cmdb.rel_modal.inverse_hint', { verb: rt.inverse_verb });
    else hint.textContent = '';
}

async function saveRelationship() {
    const typeId = parseInt(document.getElementById('relTypeSelect').value, 10);
    const toId = document.getElementById('relTargetId').value;
    if (!typeId || !toId) {
        showInlineToast(window.t('cmdb.rel_modal.pick_verb_object'), true);
        return;
    }
    try {
        const data = await postJson(API + 'save_object_relationship.php', {
            from_object_id: obj.id,
            to_object_id: parseInt(toId, 10),
            relationship_type_id: typeId
        });
        if (!data.success) throw new Error(data.error || window.t('cmdb.rel_modal.save_failed'));
        closeRelModal();
        showInlineToast(window.t('cmdb.rel_modal.added'));
        await Promise.all([loadObject(), loadImpact(), loadActivity()]);
        render();
    } catch (err) {
        showInlineToast(window.t('cmdb.rel_modal.error_prefix', { message: err.message }), true);
    }
}

async function deleteRelationship(id) {
    if (!(await showConfirm({ title: window.t('cmdb.rel_modal.delete_title'), message: window.t('cmdb.rel_modal.delete_confirm'), okLabel: window.t('cmdb.rel_modal.delete_ok'), okClass: 'danger' }))) return;
    try {
        const data = await postJson(API + 'delete_object_relationship.php', { id });
        if (!data.success) throw new Error(data.error || window.t('cmdb.rel_modal.delete_failed'));
        showInlineToast(window.t('cmdb.rel_modal.removed'));
        await Promise.all([loadObject(), loadImpact(), loadActivity()]);
        render();
    } catch (err) {
        showInlineToast(window.t('cmdb.rel_modal.error_prefix', { message: err.message }), true);
    }
}

// ---------- Delete object ----------

async function deleteObject() {
    let msg = window.t('cmdb.object.delete_confirm', { name: obj.name });
    if (obj.children.length > 0) {
        msg += '\n\n' + window.t('cmdb.object.delete_confirm_children', { count: obj.children.length });
    }
    msg += '\n\n' + window.t('cmdb.object.delete_confirm_undone');
    if (!(await showConfirm({ title: window.t('cmdb.object.delete_confirm_title'), message: msg, okLabel: window.t('cmdb.object.delete_confirm_ok'), okClass: 'primary' }))) return;

    try {
        const data = await postJson(API + 'delete_object.php', { id: obj.id });
        if (!data.success) throw new Error(data.error || window.t('cmdb.object.delete_failed'));
        const toastMsg = data.deleted_descendants > 0
            ? window.t('cmdb.object.deleted_with_descendants', {
                count: data.deleted_descendants,
                word: data.deleted_descendants === 1 ? window.t('cmdb.object.descendant') : window.t('cmdb.object.descendants')
              })
            : window.t('cmdb.object.deleted');
        showInlineToast(toastMsg);
        // Navigate back to browse
        setTimeout(() => { window.location.href = './'; }, 600);
    } catch (err) {
        showInlineToast(window.t('cmdb.object.error_prefix', { message: err.message }), true);
    }
}

// ---------- Property-definition edit (floating draggable modal) ----------

let propDefDrag = { active: false, offsetX: 0, offsetY: 0 };

function initPropDefModalDrag() {
    const modal = document.getElementById('propDefModal');
    const header = document.getElementById('propDefModalHeader');
    if (!modal || !header) return;

    header.addEventListener('mousedown', (e) => {
        // Ignore drags that start on the close button
        if (e.target.classList.contains('float-modal-close')) return;
        propDefDrag.active = true;

        // Switch from transform-centred to absolute positioning before the drag starts
        const rect = modal.getBoundingClientRect();
        modal.style.transform = 'none';
        modal.style.left = rect.left + 'px';
        modal.style.top  = rect.top  + 'px';
        propDefDrag.offsetX = e.clientX - rect.left;
        propDefDrag.offsetY = e.clientY - rect.top;

        document.onmousemove = (ev) => {
            if (!propDefDrag.active) return;
            let nx = ev.clientX - propDefDrag.offsetX;
            let ny = ev.clientY - propDefDrag.offsetY;
            nx = Math.max(0, Math.min(nx, window.innerWidth  - modal.offsetWidth));
            ny = Math.max(0, Math.min(ny, window.innerHeight - modal.offsetHeight));
            modal.style.left = nx + 'px';
            modal.style.top  = ny + 'px';
        };
        document.onmouseup = () => {
            propDefDrag.active = false;
            document.onmousemove = null;
            document.onmouseup = null;
        };
    });
}

function openPropDefModal(propertyId) {
    const p = obj.properties.find(x => x.property_id === propertyId);
    if (!p) return;

    document.getElementById('propDefModalTitle').textContent = window.t('cmdb.prop_def.title_named', { label: p.label });
    document.getElementById('pdId').value = p.property_id;
    document.getElementById('pdLabel').value = p.label;
    document.getElementById('pdKey').value = p.property_key;
    document.getElementById('pdType').value = p.property_type;
    document.getElementById('pdDisplayOrder').value = p.display_order;
    document.getElementById('pdIsRequired').checked = p.is_required;

    // Populate target-class dropdown from cached allClasses
    const tcSel = document.getElementById('pdTargetClass');
    tcSel.innerHTML = `<option value="">${escapeHtml(window.t('cmdb.prop_def.select'))}</option>` + allClasses.map(c =>
        `<option value="${c.id}" ${p.target_class_id === c.id ? 'selected' : ''}>${escapeHtml(c.name)}</option>`
    ).join('');

    // Render the row-based options editor (only visible when type === dropdown)
    if (typeof renderOptionsEditor === 'function') {
        renderOptionsEditor('pdOptionsContainer', p.options || []);
    }

    onPropDefTypeChange();

    // Reset position to centred each time it's opened (otherwise it'd drift across reopens)
    const modal = document.getElementById('propDefModal');
    modal.style.left = '50%';
    modal.style.top = '100px';
    modal.style.transform = 'translateX(-50%)';
    modal.classList.add('active');

    setTimeout(() => document.getElementById('pdLabel').focus(), 0);
}

function closePropDefModal() {
    document.getElementById('propDefModal').classList.remove('active');
}

function onPropDefTypeChange() {
    const t = document.getElementById('pdType').value;
    document.getElementById('pdTargetClassGroup').style.display = t === 'object_ref' ? 'block' : 'none';
    document.getElementById('pdOptionsGroup').style.display = t === 'dropdown' ? 'block' : 'none';
}

async function savePropDef() {
    const id = document.getElementById('pdId').value;
    const type = document.getElementById('pdType').value;
    const options = type === 'dropdown' && typeof collectOptionsFromEditor === 'function'
        ? collectOptionsFromEditor('pdOptionsContainer')
        : [];
    const targetClassId = document.getElementById('pdTargetClass').value;

    const payload = {
        id: id || null,
        class_id: obj.class_id,
        label: document.getElementById('pdLabel').value,
        property_key: document.getElementById('pdKey').value,
        property_type: type,
        target_class_id: type === 'object_ref' ? (targetClassId || null) : null,
        is_required: document.getElementById('pdIsRequired').checked,
        display_order: parseInt(document.getElementById('pdDisplayOrder').value, 10) || 0,
        options
    };

    if (type === 'object_ref' && !payload.target_class_id) {
        showInlineToast(window.t('cmdb.prop_def.pick_target'), true);
        return;
    }
    if (type === 'dropdown' && options.length === 0) {
        showInlineToast(window.t('cmdb.prop_def.add_option'), true);
        return;
    }

    try {
        const data = await postJson(API + 'save_class_property.php', payload);
        if (!data.success) throw new Error(data.error || window.t('cmdb.prop_def.save_failed'));
        closePropDefModal();
        showInlineToast(window.t('cmdb.prop_def.updated'));
        // Reload to pick up new options / type changes
        await Promise.all([loadObject(), loadImpact(), loadActivity()]);
        render();
    } catch (err) {
        showInlineToast(window.t('cmdb.prop_def.error_prefix', { message: err.message }), true);
    }
}

// ---------- Autocomplete helper ----------

function wireAutocomplete(inputEl, resultsEl, params, onPick) {
    let lastQuery = '';
    let currentResults = [];
    acHighlightedIdx = -1;

    inputEl.addEventListener('input', () => {
        const q = inputEl.value.trim();
        if (q === lastQuery) return;
        lastQuery = q;
        if (acTimer) clearTimeout(acTimer);
        if (q === '') {
            resultsEl.classList.remove('active');
            return;
        }
        acTimer = setTimeout(async () => {
            try {
                const url = API + 'search_objects.php?q=' + encodeURIComponent(q)
                    + (params.class_id ? '&class_id=' + params.class_id : '')
                    + (params.exclude_id ? '&exclude_id=' + params.exclude_id : '');
                const res = await fetch(url);
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Search failed');
                currentResults = data.results || [];
                acHighlightedIdx = -1;
                renderAcResults();
            } catch (e) { /* silent */ }
        }, 200);
    });

    inputEl.addEventListener('keydown', e => {
        if (!resultsEl.classList.contains('active')) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            acHighlightedIdx = Math.min(currentResults.length - 1, acHighlightedIdx + 1);
            renderAcResults();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            acHighlightedIdx = Math.max(0, acHighlightedIdx - 1);
            renderAcResults();
        } else if (e.key === 'Enter' && acHighlightedIdx >= 0) {
            e.preventDefault();
            onPick(currentResults[acHighlightedIdx]);
        } else if (e.key === 'Escape') {
            resultsEl.classList.remove('active');
        }
    });

    function renderAcResults() {
        if (currentResults.length === 0) {
            resultsEl.innerHTML = `<div class="ac-empty">${escapeHtml(window.t('cmdb.new_object.no_matches'))}</div>`;
            resultsEl.classList.add('active');
            return;
        }
        resultsEl.innerHTML = currentResults.map((r, i) => `
            <div class="ac-result ${i === acHighlightedIdx ? 'highlighted' : ''}" data-idx="${i}">
                <span>${escapeHtml(r.name)}</span>
                <span class="ac-class">${escapeHtml(r.class_name)}</span>
            </div>
        `).join('');
        resultsEl.classList.add('active');
        resultsEl.querySelectorAll('.ac-result').forEach(el => {
            // mousedown so it fires before the input's blur handler
            el.addEventListener('mousedown', e => {
                e.preventDefault();
                onPick(currentResults[parseInt(el.dataset.idx, 10)]);
            });
        });
    }
}
