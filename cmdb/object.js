/**
 * CMDB Object Detail
 *
 * Renders one configuration item and everything attached to it, and provides
 * inline editing of the lot: name, parent, planned state, every property type,
 * relationships in both directions, and the property DEFINITION modal.
 *
 * Layout notes worth knowing before changing anything here are in the header
 * of object.php — in particular why Impact / Map / Hierarchy / Relationships
 * are one panel, and why its tally counts by KIND rather than by direction.
 */

const API = '../api/cmdb/';
const OBJECT_ID = window.OBJECT_ID;

let obj = null;
let impact = null;            // {descendants, referenced_by_property, referenced_by_relationship}
let blastRadius = null;       // {nodes:[{id,name,class_name,depth,via_kind,via_label,via_name}], truncated, no_impact_edges_configured}
let activity = null;          // {open, closed, total_closed}
let classesById = {};         // class_id -> {name, icon_key}
let classesByName = {};       // class_name -> icon_key  (related objects only carry the name)
let allClasses = [];          // for the property-definition target-class picker
let relationshipTypes = [];   // the verb library, for the add-relationship modal
let showBlanks = false;
let summaryGenerating = false;
let acTimer = null;
let acHighlightedIdx = -1;

/* Property NAMES that mean "this is the field saying how much it matters".
   Checked before the value vocabulary below, so Criticality beats Environment
   on a Server that has both. */
const SIGNAL_HINTS = ['critical', 'severity', 'impact', 'priority', 'tier'];

/* Property VALUES that mean the same thing, for installs that never set an
   option colour. A colour configured in class settings always wins over this. */

const SEVERITY = {
    critical: '#b91c1c',
    urgent:   '#b91c1c',
    high:     '#c2410c',
    major:    '#c2410c',
    medium:   '#a16207',
    moderate: '#a16207',
    low:      '#15803d',
    minor:    '#15803d'
};

const STALE_DAYS = 183; // the audit's six months, in days

/* ---------------- helpers ---------------- */

/** Escapes text for safe use in HTML. */
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/* showToast and showConfirm are the shared components, pulled in by
   includes/header.php -> waffle-menu.php. Not redefined here — a second toast
   implementation one module away from the real one is exactly the divergence
   worth avoiding. */
function toast(msg, isError) {
    showToast(msg, isError ? 'error' : 'success');
}
/** Asks for confirmation through the shared showConfirm dialogue; resolves true when confirmed. */
function confirmAction(opts) {
    return showConfirm(opts);
}

/** Posts a JSON body and returns the parsed JSON reply. */
async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    });
    return res.json();
}

/* created/updated are UTC and get converted; a `date` PROPERTY is naive and
   must never go through the timezone path or it shifts by a day. */
/* Was a top-level `function fmtDateTime` that called itself - it shadowed
   tz.js's global (#1195) - until the stack overflowed, and then showed the raw
   UTC string. A different name, so the shared formatter is the one called. */
function fmtWhen(s) {
    if (!s) return '';
    try { return window.fmtDateTime(s) || s; } catch (e) { return s; }
}

/* "2026-04-15 00:00:00" -> "2026-04-15". Never parsed as a Date: a date
   property is naive, and round-tripping it through a timezone shifts it. */
function dateOnly(v) {
    const s = String(v ?? '');
    return /^\d{4}-\d{2}-\d{2}/.test(s) ? s.slice(0, 10) : s;
}

/** Whole days since a UTC timestamp, or null when there is none or it cannot be read. */
function daysSince(sqlUtc) {
    if (!sqlUtc) return null;
    try {
        const then = parseUTCDate(sqlUtc).getTime();
        return Math.max(0, Math.floor((Date.now() - then) / 86400000));
    } catch (e) { return null; }
}

/** Returns the SVG for an icon key from the Network Mapper icon set ('box' when none), or '' when that set is not loaded. */
function icon(key, size) {
    return window.nmRenderIcon ? window.nmRenderIcon(key || 'box', size || 20) : '';
}
/** Returns the icon for a class by name, for related objects that only carry the class name. */
function iconForClassName(name, size) {
    return icon(classesByName[name] || 'box', size);
}
/** Returns the icon for a class by id. */
function iconForClassId(id, size) {
    const c = classesById[id];
    return icon(c ? c.icon_key : 'box', size);
}

/* ---------------- load ---------------- */

document.addEventListener('DOMContentLoaded', () => {
    if (!OBJECT_ID) {
        document.querySelector('#o2Page .o2-wrap').innerHTML =
            '<div style="padding:60px;text-align:center;color:var(--danger-text,#b91c1c);">' +
            esc(window.t('cmdb.object.missing_id')) + '</div>';
        return;
    }
    Promise.all([loadObject(), loadImpact(), loadActivity(), loadClasses(), loadRelationshipTypes()]).then(() => {
        if (obj) render();
        // GH #201: the asset card's "Show" links here at #o2Blast, which only exists after render().
        if (obj && location.hash === '#o2Blast') { const b = document.getElementById('o2Blast'); if (b) b.scrollIntoView({ block: 'start' }); }
    });
    initPropDefModalDrag();
});

/** Loads the active relationship verbs for the add-relationship modal. */
async function loadRelationshipTypes() {
    try {
        const data = await (await fetch(API + 'get_relationship_types.php')).json();
        if (data.success) relationshipTypes = (data.relationship_types || []).filter(r => r.is_active);
    } catch (e) { /* the add-relationship modal will say none are defined */ }
}

/* Everything that reloads after a write goes through here, so no caller can
   refresh the object but forget the impact panel and leave the blast radius
   and the connection tally disagreeing. */
async function reloadAndRender() {
    await Promise.all([loadObject(), loadImpact(), loadActivity()]);
    render();
}

/** Loads the object (properties, relationships, parent) from get_object.php; on failure the page shows the error instead. */
async function loadObject() {
    try {
        const res = await fetch(API + 'get_object.php?id=' + OBJECT_ID);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || window.t('cmdb.object.load_failed'));
        obj = data.object;
    } catch (err) {
        document.querySelector('#o2Page .o2-wrap').innerHTML =
            '<div style="padding:60px;text-align:center;color:var(--danger-text,#b91c1c);">' + esc(err.message) + '</div>';
    }
}
/** Loads the impact data (descendants, references) and the blast radius from get_object_impact.php. */
async function loadImpact() {
    try {
        const data = await (await fetch(API + 'get_object_impact.php?id=' + OBJECT_ID)).json();
        if (data.success) { impact = data.impact; blastRadius = data.blast_radius || null; }
    } catch (e) { /* panel degrades to its empty state */ }
}
/** Loads the object's open and recently closed tickets from get_object_tickets.php. */
async function loadActivity() {
    try {
        const data = await (await fetch(API + 'get_object_tickets.php?id=' + OBJECT_ID)).json();
        if (data.success) activity = data;
    } catch (e) { /* panel degrades to its empty state */ }
}
/** Loads the classes for icons by id and by name, and the active ones for the target-class picker. */
async function loadClasses() {
    try {
        const data = await (await fetch(API + 'get_classes.php')).json();
        if (!data.success) return;
        allClasses = (data.classes || []).filter(c => c.is_active);
        (data.classes || []).forEach(c => {
            classesById[c.id] = { name: c.name, icon_key: c.icon_key };
            classesByName[c.name] = c.icon_key;
        });
    } catch (e) { /* falls back to the generic box glyph */ }
}

/* ---------------- derived facts ---------------- */

/* The object's own signal colour, read off its data rather than configured
   anywhere: an explicit option colour first, a severity word second. */
function detectSignal() {
    if (!obj) return null;
    // Rank matters: a Server usually has several coloured dropdowns, and
    // "Environment: Production" would otherwise win over "Criticality:
    // Critical" purely by being defined first.
    const cands = (obj.properties || [])
        .filter(p => p.property_type === 'dropdown' && p.value)
        .map(p => {
            const opt = (p.options || []).find(o => o.value === p.value);
            const sev = SEVERITY[String(p.value).toLowerCase()];
            const colour = (opt && opt.colour) || sev || null;
            if (!colour) return null;
            const hay = ((p.property_key || '') + ' ' + (p.label || '')).toLowerCase();
            const named = SIGNAL_HINTS.some(h => hay.indexOf(h) !== -1);
            return { colour: colour, label: p.value, rank: named ? 0 : (sev ? 1 : 2) };
        })
        .filter(Boolean)
        .sort((a, b) => a.rank - b.rank);
    return cands.length ? cands[0] : null;
}

/* One place that counts each kind of connection, so the tally row, the
   headline number and the columns can never disagree with each other.
   `descendants` is the TRANSITIVE list from the impact endpoint, not just
   direct children — a Server → Instance → Database → Job chain must not
   report one descendant. */
function connectionTally() {
    const r = (obj && obj.relationships) || { outgoing: [], incoming: [] };
    const desc = (impact && impact.descendants) ? impact.descendants : [];
    const refsIn = (impact && impact.referenced_by_property) ? impact.referenced_by_property : [];
    const refsOut = (obj.properties || []).filter(p => p.property_type === 'object_ref' && p.value_object);
    return {
        descendants: desc,
        incoming: r.incoming || [],
        outgoing: r.outgoing || [],
        refsIn: refsIn,
        refsOut: refsOut,
        hasParent: !!obj.parent_id,
        total: desc.length + (r.incoming || []).length + (r.outgoing || []).length +
               refsIn.length + refsOut.length + (obj.parent_id ? 1 : 0)
    };
}

/* ---------------- render ---------------- */

/**
 * Rebuilds the whole page - breadcrumb, hero, stats, AI summary, blast radius, connections, properties, documents, domains, projects, activity and delete - then wires editing and runs the animations.
 */
function render() {
    const signal = detectSignal();
    const wrap = document.querySelector('#o2Page .o2-wrap');
    // The sections live in their own container so the stagger's :nth-child
    // counts sections, not "sections plus whatever else is in the wrapper".
    wrap.innerHTML =
        breadcrumbHtml() +
        '<div class="o2-secs">' +
            '<div class="o2-sec">' + heroHtml(signal) + '</div>' +
            '<div class="o2-sec">' + statsHtml() + '</div>' +
            '<div class="o2-sec">' + aiHtml() + '</div>' +
            '<div class="o2-sec">' + blastHtml() + '</div>' +
            '<div class="o2-sec">' + connectionsHtml() + '</div>' +
            '<div class="o2-sec">' + propsHtml() + '</div>' +
            // GH #201: the asset this CI is the same equipment as, read live from the asset.
            '<div class="o2-sec"><div id="cmdbAssetLink"></div></div>' +
            // GH #199 Part B: the discovered object this CI is bound to - drawn only when it has one.
            '<div class="o2-sec"><div id="cmdbDiscovery"></div></div>' +
            // Attached documents (discussion #76) — the diagram, the licence, the
            // rack photo. Before activity and danger, which both read as endings.
            '<div class="o2-sec"><div id="cmdbDocuments"></div></div>' +
            // The domains this CI depends on (3.0.0): what breaks if one lapses.
            (window.SHOW_DOMAINS ? '<div class="o2-sec"><div id="cmdbDomains"></div></div>' : '') +
            (window.SHOW_PROJECTS ? '<div class="o2-sec"><div id="cmdbProjects"></div></div>' : '') +
            '<div class="o2-sec">' + activityHtml() + '</div>' +
            '<div class="o2-sec">' + dangerHtml() + '</div>' +
        '</div>';

    // Mounted, not re-pointed: render() rebuilds this wrapper every time.
    if (window.FreeITSMDocuments && obj && obj.id) {
        FreeITSMDocuments.mount(document.getElementById('cmdbDocuments'), {
            parentType: 'cmdb_object',
            parentId:   obj.id,
            apiBase:    '../api/documents/',
            showHeading: true      // every other section here carries its own title
        });
    }

    if (window.CiAssetLink && obj && obj.id) {
        CiAssetLink.mount(document.getElementById('cmdbAssetLink'), {
            side: 'cmdb', id: obj.id, base: window.APP_BASE,
            cardClass: 'o2-card', headClass: 'o2-card-head', titleClass: 'o2-card-title'
        });
    }

    if (obj && obj.id) loadDiscovery(obj.id);

    if (window.SHOW_DOMAINS && window.DomainLinks && obj && obj.id) {
        DomainLinks.mount(document.getElementById('cmdbDomains'), {
            kind: 'cmdb', id: obj.id, base: window.APP_BASE,
            cardClass: 'o2-card', headClass: 'o2-card-head', titleClass: 'o2-card-title'
        });
    }

    if (window.SHOW_PROJECTS && window.ProjectLinks && obj && obj.id) {
        ProjectLinks.mount(document.getElementById('cmdbProjects'), {
            kind: 'cmdb', id: obj.id, base: window.APP_BASE,
            cardClass: 'o2-card', headClass: 'o2-card-head', titleClass: 'o2-card-title'
        });
    }

    if (signal) wrap.style.setProperty('--o2-signal', signal.colour);
    else wrap.style.removeProperty('--o2-signal');

    wireName();
    wireProps();
    animateStats();
    animateMeter();
}

/* GH #199 Part B: "From discovery" - what a source-bound CI is, read live from the virtual
   estate and never copied into its properties, in the same way the Asset card reads the
   asset (GH #201). Nothing is drawn for a CI that was never discovered. */
async function loadDiscovery(id) {
    const host = document.getElementById('cmdbDiscovery');
    if (!host) return;
    let d;
    try { d = await (await fetch(API + 'discovery_source.php?cmdb_object_id=' + id)).json(); } catch (e) { return; }
    if (!d || !d.success || (!d.current && !(d.history || []).length)) { host.closest('.o2-sec').style.display = 'none'; return; }
    const T = (k, p) => window.t('cmdb.discovery.' + k, p);
    const c = d.current;
    let body;
    if (!c) {
        body = '<div class="o2-empty">' + esc(T('ended')) + '</div>';
    } else if (c.restricted) {
        body = '<div class="o2-empty">' + esc(T('restricted')) + '</div>';
    } else {
        const facts = [
            [T('kind'), T('kind_' + c.kind)], [T('source'), c.connection], [T('state'), c.state],
            [T('host'), c.host], [T('cluster'), c.cluster],
            [T('ha'), c.highly_available === null || c.highly_available === undefined ? null : (c.highly_available ? T('yes') : T('no'))],
            [T('cpu'), c.cpu_count], [T('memory'), c.memory_bytes ? Math.round(c.memory_bytes / 1073741824) + ' GB' : null],
            [T('last_seen'), c.last_seen ? fmtWhen(c.last_seen) : null],
        ].filter(f => f[1] !== null && f[1] !== undefined && f[1] !== '');
        body = (c.not_seen_since ? '<div class="o2-grade g-affected">' + esc(T('not_seen', { when: fmtWhen(c.not_seen_since) })) + '</div>' : '')
            + (c.not_confirmed_since ? '<div class="o2-grade g-unknown">' + esc(T('not_confirmed', { when: fmtWhen(c.not_confirmed_since) })) + '</div>' : '')
            + '<div class="o2-disc">' + facts.map(f => '<div><span>' + esc(f[0]) + '</span><b>' + esc(f[1]) + '</b></div>').join('') + '</div>'
            + '<div class="o2-via">' + esc(T('from_discovery')) + '</div>';
    }
    host.innerHTML = '<div class="o2-card"><div class="o2-card-head"><span class="o2-card-title">' + esc(T('title')) + '</span></div>' +
        '<div style="padding: 4px 18px 16px;">' + body + '</div></div>';
}

/** Returns the breadcrumb - Browse, the class, the parent if any, then this object. */
function breadcrumbHtml() {
    return '<div class="o2-breadcrumb">' +
        '<a href="./">' + esc(window.t('cmdb.object.breadcrumb_browse')) + '</a><span class="sep">›</span>' +
        '<a href="./#class-' + obj.class_id + '">' + esc(obj.class_name) + '</a>' +
        (obj.parent_id ? '<span class="sep">›</span><a href="object.php?id=' + obj.parent_id + '">' + esc(obj.parent_name) + '</a>' : '') +
        '<span class="sep">›</span><span class="here">' + esc(obj.name) + '</span>' +
        '</div>';
}

/**
 * Returns the hero - icon, editable name, chips for class, signal, planned state, parent and staleness, and the summary and diagram buttons.
 */
function heroHtml(signal) {
    const stale = daysSince(obj.updated_datetime);
    const chips = [];
    chips.push('<span class="o2-chip class">' + icon(classesById[obj.class_id] ? classesById[obj.class_id].icon_key : 'box', 13) + esc(obj.class_name) + '</span>');
    if (signal) chips.push('<span class="o2-chip signal">' + esc(signal.label) + '</span>');

    // The state is always stated, and clicking it flips it. When it is the
    // normal case it is quiet; when it is planned it is loud.
    chips.push('<button class="o2-chip act' + (obj.is_planned ? ' planned' : '') + '" onclick="togglePlanned()" ' +
        'title="' + esc(window.t(obj.is_planned ? 'cmdb.object.state_make_real' : 'cmdb.object.state_make_planned')) + '">' +
        esc(window.t(obj.is_planned ? 'cmdb.object.state_planned' : 'cmdb.object.state_real')) + '</button>');

    chips.push(obj.parent_id
        ? '<span class="o2-chip">' + esc(window.t('cmdb.object.part_of')) +
          ' <a href="object.php?id=' + obj.parent_id + '">' + esc(obj.parent_name) + '</a>' +
          '<button class="o2-chip-x" onclick="openParentModal()" title="' + esc(window.t('cmdb.object.parent_change_title')) + '">' +
          esc(window.t('cmdb.object.parent_edit')) + '</button></span>'
        : '<button class="o2-chip act" onclick="openParentModal()" title="' + esc(window.t('cmdb.object.parent_set_title')) + '">' +
          esc(window.t('cmdb.object.parent_set')) + '</button>');
    if (stale !== null && stale > STALE_DAYS) {
        chips.push('<span class="o2-chip stale">' +
            esc(window.t('cmdb.object.stale_months', { count: Math.floor(stale / 30) })) + '</span>');
    }

    const actions =
        '<button class="o2-btn primary" id="o2GenBtn" onclick="generateSummary(this)">' +
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/></svg>' +
            esc(window.t(obj.ai_summary ? 'cmdb.summary.regenerate' : 'cmdb.summary.generate')) +
        '</button>' +
        (window.CAN_MAKE_DIAGRAM
            ? '<button class="o2-btn" onclick="createImpactDiagram(this)">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="18" cy="18" r="3"/><path d="M9 6h4a2 2 0 0 1 2 2v7"/></svg>' +
                esc(window.t('cmdb.impact.open_as_diagram')) + '</button>'
            : '');

    return '<div class="o2-hero' + (obj.is_planned ? ' is-planned' : '') + '">' +
        '<div class="o2-hero-icon">' + iconForClassId(obj.class_id, 40) + '</div>' +
        '<div class="o2-hero-main">' +
            '<input class="o2-name" id="o2Name" value="' + esc(obj.name) + '" aria-label="Object name">' +
            '<div class="o2-chips">' + chips.join('') + '</div>' +
        '</div>' +
        '<div class="o2-hero-actions">' + actions + '</div>' +
    '</div>';
}

/**
 * Returns the four stat tiles - what breaks, open tickets, connections and days since last touched - each jumping to its section.
 */
function statsHtml() {
    // Graded (GH #199 Part C): only what is AFFECTED breaks - the rest may be interrupted,
    // has less redundancy, or is unknown, and the panel below says which.
    const blast = (blastRadius && blastRadius.nodes)
        ? (blastRadius.grades_used ? ((blastRadius.grade_counts || {}).affected || 0) : blastRadius.nodes.length) : 0;
    const open = (activity && activity.open) ? activity.open.length : 0;
    const conns = connectionTally().total;
    const days = daysSince(obj.updated_datetime);

    /** Returns one stat tile; with a target it scrolls to that section when clicked. */
    function tile(val, unit, label, cls, target) {
        const jump = target
            ? ' onclick="document.getElementById(\'' + target + '\').scrollIntoView({behavior:\'smooth\',block:\'start\'})"'
            : '';
        return '<button class="o2-stat ' + cls + (target ? '' : ' is-quiet') + '"' + jump + '>' +
            '<div class="o2-stat-val"><span data-count="' + val + '">0</span>' + (unit ? '<span class="unit">' + esc(unit) + '</span>' : '') + '</div>' +
            '<div class="o2-stat-lbl">' + esc(label) + '</div>' +
        '</button>';
    }

    return '<div class="o2-stats">' +
        tile(blast, '', window.t(blast === 1 ? 'cmdb.stats.breaks_one' : 'cmdb.stats.breaks_other'), blast > 0 ? 'hot' : 'zero', 'o2Blast') +
        tile(open, '', window.t(open === 1 ? 'cmdb.stats.open_tickets_one' : 'cmdb.stats.open_tickets_other'), open > 0 ? 'warm' : 'zero', 'o2Activity') +
        tile(conns, '', window.t(conns === 1 ? 'cmdb.stats.connections_one' : 'cmdb.stats.connections_other'), conns > 0 ? '' : 'zero', 'o2Conn') +
        tile(days === null ? 0 : days, window.t('cmdb.stats.days_unit'), window.t('cmdb.stats.since_touched'),
             (days !== null && days > STALE_DAYS) ? 'warm' : '', null) +
    '</div>';
}

/** Returns the AI summary box - the saved summary with when it was written, or a prompt to generate one. */
function aiHtml() {
    if (obj.ai_summary) {
        return '<div class="o2-ai">' +
            '<div class="o2-ai-ico"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/></svg></div>' +
            '<div><div class="o2-ai-txt" id="o2AiTxt">' + esc(obj.ai_summary) + '</div>' +
            (obj.ai_summary_generated_at
                ? '<div class="o2-ai-when">' + esc(window.t('cmdb.summary.generated', { datetime: fmtWhen(obj.ai_summary_generated_at) })) + '</div>'
                : '') +
            '</div></div>';
    }
    return '<div class="o2-ai">' +
        '<div class="o2-ai-ico"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/></svg></div>' +
        '<div class="o2-ai-txt muted" id="o2AiTxt">' +
            window.t('cmdb.summary.empty_prompt', { generate: '<b>' + esc(window.t('cmdb.summary.generate')) + '</b>' }) +
        '</div>' +
    '</div>';
}

/* The blast radius as a left-to-right chain: root, then one column per hop.
   The grouped list said the same thing; the chain shows the direction a
   failure travels, which is the point of the panel. */
function blastHtml() {
    const head = '<div class="o2-card-head"><span class="o2-card-title">' + esc(window.t('cmdb.impact.if_this_failed')) + '</span>' +
        (blastRadius && blastRadius.truncated ? '<span class="o2-card-sub">' + esc(window.t('cmdb.impact.blast_truncated')) + '</span>' : '') + '</div>';

    if (!blastRadius) return '<div class="o2-card" id="o2Blast">' + head +
        '<div class="o2-empty">' + esc(window.t('cmdb.object.working_it_out')) + '</div></div>';

    const nodes = blastRadius.nodes || [];
    if (!nodes.length) {
        // Two different states that produce an identical empty list and call for
        // opposite responses — reassurance versus go and configure it.
        const msg = blastRadius.no_impact_edges_configured
            ? esc(window.t('cmdb.impact.blast_unconfigured'))
            : esc(window.t('cmdb.impact.blast_none'));
        return '<div class="o2-card" id="o2Blast">' + head + '<div class="o2-empty">' + msg + '</div></div>';
    }

    const maxDepth = Math.max(...nodes.map(n => n.depth));
    let chain = '<div class="o2-hop"><div class="o2-hop-lbl">' + esc(window.t('cmdb.impact.starts_here')) + '</div><div class="o2-hop-items">' +
        '<div class="o2-node root">' +
            '<span class="o2-node-ico">' + iconForClassId(obj.class_id, 20) + '</span>' +
            '<span class="o2-node-txt"><span class="o2-node-name">' + esc(obj.name) + '</span>' +
            '<span class="o2-node-sub">' + esc(obj.class_name) + '</span></span>' +
        '</div></div></div>';

    for (let d = 1; d <= maxDepth; d++) {
        const at = nodes.filter(n => n.depth === d);
        if (!at.length) continue;
        chain += '<div class="o2-arrow"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="12" x2="19" y2="12"/><polyline points="13 6 19 12 13 18"/></svg></div>';
        chain += '<div class="o2-hop"><div class="o2-hop-lbl">' +
            esc(window.t(d === 1 ? 'cmdb.impact.hops_away_one' : 'cmdb.impact.hops_away_other', { count: d })) +
            '</div><div class="o2-hop-items">' +
            at.map(n =>
                '<div>' +
                '<a class="o2-node" href="object.php?id=' + n.id + '">' +
                    '<span class="o2-node-ico">' + iconForClassName(n.class_name, 20) + '</span>' +
                    '<span class="o2-node-txt"><span class="o2-node-name">' + esc(n.name) + '</span>' +
                    '<span class="o2-node-sub">' + esc(n.class_name) + '</span></span>' +
                '</a>' +
                '<div class="o2-via">' + esc(viaText(n)) + '</div>' +
                gradeHtml(n) +
                '</div>'
            ).join('') +
        '</div></div>';
    }

    // Graded (GH #199 Part C): "affected" is now one of the grades, so the headline must not claim it for all.
    const graded = blastRadius.grades_used ? '_graded' : '';
    const lede = nodes.length === 1
        ? window.t('cmdb.impact.blast_headline_one' + graded)
        : window.t('cmdb.impact.blast_headline_other' + graded, { count: nodes.length, depth: maxDepth });

    return '<div class="o2-card" id="o2Blast">' + head +
        '<div class="o2-lede">' + esc(lede) + gradeCountsHtml() + '</div>' +
        '<div class="o2-chain">' + chain + '</div>' +
        limitsHtml() +
    '</div>';
}

/* GH #199 Part C: each item's grade, when a relationship type in the path is marked as
   redundant membership or placement with failover. Without one every item is simply
   affected, and nothing extra is drawn - the panel reads exactly as it always did. */
function gradeHtml(n) {
    if (!blastRadius || !blastRadius.grades_used || !n.grade) return '';
    let h = '<div class="o2-grade g-' + esc(n.grade) + '">' + esc(window.t('cmdb.impact.grade_' + n.grade)) + '</div>';
    if (n.unknown_via && n.unknown_via.length && n.grade !== 'unknown') {
        h += '<div class="o2-via">' + esc(window.t('cmdb.impact.unknown_via', { names: n.unknown_via.join(', ') })) + '</div>';
    }
    return h;
}

/** "3 affected, 2 may be interrupted, 1 redundancy reduced" beside the headline. */
function gradeCountsHtml() {
    if (!blastRadius || !blastRadius.grades_used) return '';
    const c = blastRadius.grade_counts || {};
    const bits = ['affected', 'may_be_interrupted', 'redundancy_reduced', 'unknown']
        .filter(g => c[g]).map(g => c[g] + ' ' + window.t('cmdb.impact.grade_' + g).toLowerCase());
    return bits.length ? ' <span class="o2-grade-counts">' + esc(bits.join(' · ')) + '</span>' : '';
}

/* What the model does not know - always shown when a redundancy rule was used, never
   dismissible (agreed on #199): "redundancy reduced" is not a promise it keeps working. */
function limitsHtml() {
    if (!blastRadius || !blastRadius.grades_used || !(blastRadius.limits || []).length) return '';
    const items = blastRadius.limits.map(l => typeof l === 'string'
        ? window.t('cmdb.impact.limit_' + l)
        : window.t('cmdb.impact.limit_' + l.code, { names: (l.names || []).join(', ') }));
    return '<div class="o2-limits"><strong>' + esc(window.t('cmdb.impact.limits_title')) + '</strong><ul>' +
        items.map(i => '<li>' + esc(i) + '</li>').join('') + '</ul></div>';
}

/* Every row says how it was reached, so a surprising entry can be traced
   rather than doubted. */
function viaText(n) {
    // The engine emits 'child' / 'relationship' / 'property'. Containment
    // carries no verb (via_label is NULL), so it needs its own sentence or it
    // renders as the literal "null <name>".
    if (n.via_kind === 'child') return window.t('cmdb.impact.via_child', { parent: n.via_name });
    if (n.via_kind === 'property') return window.t('cmdb.impact.via_property', { label: n.via_label, source: n.via_name });
    return window.t('cmdb.impact.via_relationship', { verb: n.via_label, source: n.via_name });
}

/* ONE panel where object.php had four. Impact, Map, Hierarchy and
   Relationships are four framings of "what is this attached to", and on a
   sparse object they produced four separate empty states. */
function connectionsHtml() {
    const tally = connectionTally();

    // Only relationship rows carry an ×. A property reference is unlinked by
    // clearing the field it lives in, and a descendant by re-parenting it —
    // offering an × on those would imply a delete this panel cannot do.
    // TRAP: configured verbs cannot be inflected to fit a translated "this".
    // Name both ends instead. Incoming rows read other -> current, so they need
    // the forward verb too; the inverse would say a VM hosts its host.
    const left = []
        .concat(tally.incoming.map(x => nodeRow(x.other_id, x.other_name, x.other_class_name,
            window.t('cmdb.conn.relationship_statement', { source: x.other_name, verb: x.verb, target: obj.name }), x.id)))
        .concat(tally.refsIn.map(x => nodeRow(x.id, x.name, x.class_name,
            window.t('cmdb.conn.verb_ref_in', { label: x.property_label || window.t('cmdb.conn.a_property') }))));

    const right = []
        .concat(tally.outgoing.map(x => nodeRow(x.other_id, x.other_name, x.other_class_name,
            window.t('cmdb.conn.relationship_statement', { source: obj.name, verb: x.verb, target: x.other_name }), x.id)))
        .concat(tally.refsOut.map(p => nodeRow(p.value_object.id, p.value_object.name, p.value_object.class_name || p.target_class_name, p.label)));

    const centre =
        (obj.parent_id
            ? '<div class="o2-updown">' + nodeRow(obj.parent_id, obj.parent_name, obj.parent_class_name, window.t('cmdb.conn.this_is_part_of')) + upArrow() + '</div>'
            : '') +
        '<div class="o2-centre">' + iconForClassId(obj.class_id, 30) +
            '<div class="o2-centre-name">' + esc(obj.name) + '</div>' +
            '<div class="o2-centre-cls">' + esc(obj.class_name) + '</div>' +
        '</div>' +
        (tally.descendants.length
            ? '<div class="o2-updown">' + downArrow() +
              tally.descendants.map(d => nodeRow(
                  d.id, d.name, d.class_name,
                  d.depth > 1
                      ? window.t('cmdb.conn.inside_levels', { count: d.depth })
                      : window.t('cmdb.conn.contained_by_this')
              )).join('') + '</div>'
            : '');

    /* ⚠️ Always rendered, every figure, including the zeroes. "0 descendants"
       and "we never looked" are different facts and must not both render as
       silence — the same rule the data-quality audit is built on.

       🔑 Counted by KIND, not by direction. The four kinds are created four
       different ways, so a tally split by direction ("points at this" / "this
       points at") cannot be matched to any control — and a single
       "+ Add relationship" button sitting above it implies it covers all four
       when it can only ever create one. Each row now carries its own way in,
       and direction is still legible from the column headers and from the verb
       printed on every row. */
    const nRel = tally.incoming.length + tally.outgoing.length;
    const nRef = tally.refsIn.length + tally.refsOut.length;
    /** Picks the singular or plural tally label for a count. */
    const tk = (base, n) => window.t('cmdb.conn.' + base + (n === 1 ? '_one' : '_other'), { count: n });
    const counts = [
        {
            n: tally.descendants.length,
            label: tk('tally_descendants', tally.descendants.length),
            // Deliberately no button: a descendant is made from the CHILD's
            // page, by naming this object as its parent. A control here would
            // have to create an object, which is a different job.
            hint: window.t('cmdb.conn.hint_descendants')
        },
        {
            n: nRel,
            label: tk('tally_relationships', nRel),
            hint: window.t('cmdb.conn.hint_relationships'),
            action: '<button class="o2-tally-act" onclick="openRelModal()">' + esc(window.t('cmdb.conn.act_add')) + '</button>'
        },
        {
            n: nRef,
            label: tk('tally_property_links', nRef),
            hint: window.t('cmdb.conn.hint_property_links'),
            action: '<button class="o2-tally-act" onclick="jumpToDetails()">' + esc(window.t('cmdb.conn.act_in_details')) + '</button>'
        },
        {
            n: tally.hasParent ? 1 : 0,
            label: window.t('cmdb.conn.tally_parent'),
            hint: window.t('cmdb.conn.hint_parent'),
            action: '<button class="o2-tally-act" onclick="openParentModal()">' +
                    esc(window.t(tally.hasParent ? 'cmdb.conn.act_change' : 'cmdb.conn.act_set')) + '</button>'
        }
    ].map(c =>
        '<span class="o2-tally-item' + (c.n ? '' : ' zero') + '" title="' + esc(c.hint) + '">' +
            '<b>' + c.n + '</b> ' + esc(c.label) + (c.action || '') +
        '</span>'
    ).join('');

    const head = '<div class="o2-card-head"><span class="o2-card-title">' + esc(window.t('cmdb.conn.heading')) + '</span>' +
        '<span class="o2-card-sub">' + esc(window.t('cmdb.conn.in_total', { count: tally.total })) + '</span></div>';

    const tallyRow = '<div class="o2-tally">' + counts + '</div>';

    if (!tally.total) {
        return '<div class="o2-card" id="o2Conn">' + head + tallyRow +
            '<div class="o2-empty">' + esc(window.t('cmdb.conn.nothing_attached')) + '</div></div>';
    }

    return '<div class="o2-card" id="o2Conn">' + head + tallyRow +
        '<div class="o2-conn">' +
            '<div class="o2-conn-col"><div class="o2-conn-lbl">' + esc(window.t('cmdb.conn.points_at_this')) + '</div><div class="o2-conn-list">' +
                (left.length ? left.join('') : '<div class="o2-empty">' + esc(window.t('cmdb.conn.none_points_at_this')) + '</div>') +
            '</div></div>' +
            '<div class="o2-conn-col mid">' + centre +
                (tally.descendants.length ? ''
                    : '<div class="o2-empty" style="text-align:center;">' + esc(window.t('cmdb.impact.descendants_empty')) + '</div>') +
            '</div>' +
            '<div class="o2-conn-col right"><div class="o2-conn-lbl">' + esc(window.t('cmdb.conn.this_points_at')) + '</div><div class="o2-conn-list">' +
                (right.length ? right.join('') : '<div class="o2-empty">' + esc(window.t('cmdb.conn.points_at_nothing')) + '</div>') +
            '</div></div>' +
        '</div>' +
    '</div>';
}

/** Returns one linked object row with its verb, and an unlink button when it is a relationship row. */
function nodeRow(id, name, className, verb, relId) {
    return '<div class="o2-noderow">' +
        (verb ? '<div class="o2-rel-verb">' + esc(verb) + '</div>' : '') +
        '<div class="o2-node-wrap">' +
            '<a class="o2-node" href="object.php?id=' + id + '">' +
                '<span class="o2-node-ico">' + iconForClassName(className, 18) + '</span>' +
                '<span class="o2-node-txt"><span class="o2-node-name">' + esc(name) + '</span>' +
                '<span class="o2-node-sub">' + esc(className || '') + '</span></span>' +
            '</a>' +
            (relId ? '<button class="o2-unlink" onclick="deleteRelationship(' + relId + ')" title="' +
                esc(window.t('cmdb.conn.unlink_title')) + '">×</button>' : '') +
        '</div></div>';
}
/** Returns the up-arrow SVG drawn between the parent and this object. */
function upArrow() {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-faint,#d1d5db)"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="6 11 12 5 18 11"/></svg>';
}
/** Returns the down-arrow SVG drawn between this object and its descendants. */
function downArrow() {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-faint,#d1d5db)"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="6 13 12 19 18 13"/></svg>';
}

/* Filled properties first as cards; blanks collapse to one line. A row per
   empty field is how the old page ended up mostly saying "(not set)". */
function propsHtml() {
    const props = obj.properties || [];
    const filled = props.filter(p => p.value !== null && p.value !== '' && p.value !== undefined);
    const blank = props.filter(p => !(p.value !== null && p.value !== '' && p.value !== undefined));

    const head = '<div class="o2-card-head"><span class="o2-card-title">' + esc(window.t('cmdb.details.heading')) + '</span>' +
        '<span class="o2-card-sub">' + esc(window.t('cmdb.details.filled_of', { filled: filled.length, total: props.length })) + '</span>' +
        '<div class="o2-meter"><i id="o2Meter" style="transform:scaleX(0)"></i></div></div>';

    if (!props.length) {
        return '<div class="o2-card" id="o2Details">' + head +
            '<div class="o2-empty">' + esc(window.t('cmdb.details.no_props_defined')) + '</div></div>';
    }

    const cards = (showBlanks ? filled.concat(blank) : filled).map(propCard).join('');

    let blanksLine = '';
    if (blank.length) {
        blanksLine = '<div class="o2-blanks">' +
            (showBlanks
                ? '<button onclick="toggleBlanks()">' +
                    esc(window.t(blank.length === 1 ? 'cmdb.details.hide_empty_one' : 'cmdb.details.hide_empty_other', { count: blank.length })) +
                  '</button>'
                : esc(window.t(blank.length === 1 ? 'cmdb.details.empty_count_one' : 'cmdb.details.empty_count_other', { count: blank.length })) +
                  ' <button onclick="toggleBlanks()">' +
                    esc(window.t(blank.length === 1 ? 'cmdb.details.show_it' : 'cmdb.details.show_them')) +
                  '</button>') +
        '</div>';
    }

    return '<div class="o2-card" id="o2Details">' + head +
        (cards ? '<div class="o2-props">' + cards + '</div>'
               : '<div class="o2-empty">' + esc(window.t('cmdb.details.nothing_filled')) + '</div>') +
        blanksLine +
        // The in-service state is stated positively, always. The hero only
        // chips PLANNED because that is the exception; saying nothing at all
        // for the normal case leaves the reader to assume it.
        '<div style="margin-top:14px;"><span class="o2-stat-lbl">' +
        esc(window.t(obj.is_planned ? 'cmdb.object.state_planned' : 'cmdb.object.state_real')) +
        ' · ' + esc(window.t('cmdb.details.added_on', { datetime: fmtWhen(obj.created_datetime) })) +
        ' · ' + esc(window.t('cmdb.details.last_change', { datetime: fmtWhen(obj.updated_datetime) })) +
        '</span></div>' +
    '</div>';
}

/**
 * Returns one property card - label, required mark, the cog that edits the property definition, and the value (click to edit).
 */
function propCard(p) {
    const empty = !(p.value !== null && p.value !== '' && p.value !== undefined);
    // The cog edits the property DEFINITION (label, type, options, required),
    // not this object's value — same modal as the current page. It is on hover
    // rather than permanent, because it is schema work and most visits are not.
    return '<div class="o2-prop">' +
        '<div class="o2-prop-lbl">' +
            '<span>' + esc(p.label) + (p.is_required ? '<span class="req" title="' + esc(window.t('cmdb.prop_def.required')) + '">*</span>' : '') + '</span>' +
            '<button class="o2-cog" onclick="openPropDefModal(' + p.property_id + ')" title=' + '"' + esc(window.t('cmdb.object.edit_prop_title')) + '">' +
                '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>' +
            '</button>' +
        '</div>' +
        '<div class="o2-prop-val" id="o2p_' + p.property_id + '" data-pid="' + p.property_id + '">' +
            (empty ? '<span style="color:var(--text-faint,#d1d5db);font-weight:400;">' + esc(window.t('cmdb.details.not_set')) + '</span>' : propValueHtml(p)) +
        '</div>' +
    '</div>';
}

/**
 * Returns a property value for display - a linked object, Yes/No, a coloured drop-down pill, a number, a date without time, or text.
 */
function propValueHtml(p) {
    if (p.property_type === 'object_ref') {
        if (!p.value_object) return esc(p.value);
        return '<a class="o2-ref" href="object.php?id=' + p.value_object.id + '">' +
            iconForClassName(p.value_object.class_name || p.target_class_name, 15) +
            esc(p.value_object.name) + '</a>';
    }
    if (p.property_type === 'boolean') return esc(String(p.value) === '1' || p.value === true ? window.t('cmdb.object.yes') : window.t('cmdb.object.no'));
    if (p.property_type === 'dropdown') {
        const opt = (p.options || []).find(o => o.value === p.value);
        const sev = SEVERITY[String(p.value).toLowerCase()];
        const col = (opt && opt.colour) || sev;
        const style = col ? ' style="background:' + esc(col) + ';color:#fff;"' : '';
        return '<span class="o2-pill"' + style + '>' + esc(p.value) + '</span>';
    }
    if (p.property_type === 'number') return esc(String(p.value));
    // A date is naive — never timezone-converted — but it can come back with a
    // 00:00:00 tail, which reads as spurious precision on a date-only field.
    if (p.property_type === 'date') return esc(dateOnly(p.value));
    return esc(p.value);
}

/** Shows or hides the empty properties, then re-renders. */
function toggleBlanks() {
    showBlanks = !showBlanks;
    render();
}

/** Returns the Activity card - open tickets against this object, then the recently closed ones. */
function activityHtml() {
    const head = '<div class="o2-card-head"><span class="o2-card-title">' + esc(window.t('cmdb.activity.heading')) + '</span></div>';
    if (!activity) return '<div class="o2-card" id="o2Activity">' + head + '<div class="o2-empty">' + esc(window.t('cmdb.object.working_it_out')) + '</div></div>';

    const open = activity.open || [];
    const closed = activity.closed || [];
    if (!open.length && !closed.length) {
        return '<div class="o2-card" id="o2Activity">' + head +
            '<div class="o2-empty">' + esc(window.t('cmdb.activity.empty')) + '</div></div>';
    }

    /** Returns one ticket link with its number, subject, status and priority. */
    const row = (t, isOpen) =>
        '<a class="o2-tik" href="../tickets/?ticket_id=' + t.id + '">' +
            '<span class="o2-tik-ref">' + esc(t.ticket_number || ('#' + t.id)) + '</span>' +
            '<span class="o2-tik-sub">' + esc(t.subject || window.t('cmdb.activity.no_subject')) + '</span>' +
            '<span class="o2-tik-meta">' + esc(t.status || window.t('cmdb.activity.unknown_status')) +
            (t.priority ? ' · ' + esc(t.priority) : '') + '</span>' +
        '</a>';

    return '<div class="o2-card" id="o2Activity">' + head +
        (open.length ? '<div class="o2-lede">' + esc(window.t(open.length === 1 ? 'cmdb.stats.open_tickets_count_one' : 'cmdb.stats.open_tickets_count_other', { count: open.length })) + '</div>' +
            '<div class="o2-tix">' + open.map(t => row(t, true)).join('') + '</div>' : '') +
        (closed.length ? '<div class="o2-conn-lbl" style="margin-top:14px;">' + esc(activity.total_closed > closed.length
                ? window.t('cmdb.activity.recent_closed_showing', { shown: closed.length, total: activity.total_closed })
                : window.t('cmdb.activity.recent_closed')) + '</div>' +
            '<div class="o2-tix">' + closed.map(t => row(t, false)).join('') + '</div>' : '') +
    '</div>';
}

/** Returns the delete strip, warning when the object has children. */
function dangerHtml() {
    const kids = (obj.children || []).length;
    return '<div class="o2-danger">' +
        '<div class="o2-danger-txt">' + window.t(kids ? 'cmdb.danger.note_children' : 'cmdb.danger.note') + '</div>' +
        '<button class="o2-btn danger" onclick="deleteObject()">' + esc(window.t('cmdb.danger.delete')) + '</button>' +
    '</div>';
}

/* ---------------- animation ---------------- */

/* A detail page is opened occasionally, not hundreds of times a day, so the
   numbers can afford to count up. Kept to 460ms and skipped entirely under
   prefers-reduced-motion. */
function animateStats() {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        document.querySelectorAll('[data-count]').forEach(el => { el.textContent = el.dataset.count; });
        return;
    }
    document.querySelectorAll('[data-count]').forEach(el => {
        const target = parseInt(el.dataset.count, 10) || 0;
        if (target === 0) { el.textContent = '0'; return; }
        const dur = 460, start = performance.now();
        /** Advances one stat's count-up for this animation frame (ease-out cubic over 460ms). */
        function step(now) {
            const t = Math.min(1, (now - start) / dur);
            const eased = 1 - Math.pow(1 - t, 3); // ease-out cubic
            el.textContent = Math.round(target * eased);
            if (t < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    });
}

/** Animates the details meter to the share of properties that are filled. */
function animateMeter() {
    const el = document.getElementById('o2Meter');
    if (!el) return;
    const props = obj.properties || [];
    const filled = props.filter(p => p.value !== null && p.value !== '' && p.value !== undefined).length;
    const pct = props.length ? filled / props.length : 1;
    requestAnimationFrame(() => { el.style.transform = 'scaleX(' + pct + ')'; });
}

/* ---------------- editing ---------------- */

/** Makes the hero name editable - Enter saves, Escape reverts, blur saves when changed. */
function wireName() {
    const el = document.getElementById('o2Name');
    if (!el) return;
    let original = obj.name;
    el.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); el.blur(); }
        if (e.key === 'Escape') { el.value = original; el.blur(); }
    });
    el.addEventListener('blur', async () => {
        const v = el.value.trim();
        if (!v || v === original) { el.value = original; return; }
        await savePartial({ name: v });
    });
}

/** Makes each property value open its inline editor on click (links inside it still navigate). */
function wireProps() {
    document.querySelectorAll('.o2-prop-val').forEach(cell => {
        cell.addEventListener('click', e => {
            if (e.target.closest('a')) return;          // following a reference, not editing
            if (cell.querySelector('input, select')) return;
            beginEdit(cell);
        });
    });
}

/**
 * Swaps a property value for an inline editor by type (object references use their own search) and saves it on blur or Enter; Escape cancels.
 */
function beginEdit(cell) {
    const pid = parseInt(cell.dataset.pid, 10);
    const p = (obj.properties || []).find(x => x.property_id === pid);
    if (!p) return;
    if (p.property_type === 'object_ref') { beginEditObjectRef(cell, p); return; }

    let editor;
    if (p.property_type === 'dropdown') {
        editor = document.createElement('select');
        editor.innerHTML = '<option value=""></option>' +
            (p.options || []).map(o => '<option value="' + esc(o.value) + '"' + (o.value === p.value ? ' selected' : '') + '>' + esc(o.value) + '</option>').join('');
    } else if (p.property_type === 'boolean') {
        editor = document.createElement('select');
        const isYes = String(p.value) === '1' || p.value === true;
        editor.innerHTML = '<option value=""></option>' +
            '<option value="1"' + (isYes ? ' selected' : '') + '>Yes</option>' +
            '<option value="0"' + (p.value !== null && !isYes ? ' selected' : '') + '>No</option>';
    } else {
        editor = document.createElement('input');
        editor.type = p.property_type === 'number' ? 'number' : (p.property_type === 'date' ? 'date' : 'text');
        // <input type="date"> silently refuses a value with a time on it.
        editor.value = p.property_type === 'date' ? dateOnly(p.value) : (p.value ?? '');
    }

    const before = cell.innerHTML;
    cell.innerHTML = '';
    cell.appendChild(editor);
    editor.focus();
    if (editor.select) editor.select();

    let done = false;
    /** Saves the edited value unless it is unchanged, then restores or re-renders the cell. */
    const commit = async () => {
        if (done) return;
        done = true;
        const raw = editor.value === '' ? null : editor.value;
        const unchanged = (raw === null && (p.value === null || p.value === '')) || String(raw) === String(p.value);
        if (unchanged) { cell.innerHTML = before; return; }
        await saveProperty(p, raw, cell, before);
    };
    editor.addEventListener('blur', commit);
    editor.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); editor.blur(); }
        if (e.key === 'Escape') { done = true; cell.innerHTML = before; }
    });
    if (editor.tagName === 'SELECT') editor.addEventListener('change', () => editor.blur());
}

/** Saves one property value through save_object.php and reloads the page data; puts the old value back on failure. */
async function saveProperty(p, raw, cell, before) {
    try {
        const data = await postJson(API + 'save_object.php', {
            id: obj.id,
            name: obj.name,
            parent_id: obj.parent_id,
            property_values: [{ property_id: p.property_id, value: raw }]
        });
        if (!data.success) throw new Error(data.error || window.t('cmdb.object.save_failed'));
        await reloadAndRender();
        toast(window.t('cmdb.details.field_saved', { label: p.label }));
    } catch (err) {
        cell.innerHTML = before;
        toast(err.message, true);
    }
}

/** Saves the name, parent or planned flag through save_object.php (no property values) and reloads the page data. */
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
        await reloadAndRender();
        toast(window.t('cmdb.object.saved'));
    } catch (err) {
        await loadObject();
        render();
        toast(err.message, true);
    }
}

/* ---------------- actions ---------------- */

/** Asks generate_object_summary.php to write the object's AI summary, then reloads and re-renders. */
async function generateSummary(btn) {
    if (summaryGenerating) return;
    summaryGenerating = true;
    const txt = document.getElementById('o2AiTxt');
    if (btn) btn.disabled = true;
    if (txt) { txt.classList.add('muted'); txt.textContent = window.t('cmdb.summary.synthesising'); }
    try {
        const data = await postJson(API + 'generate_object_summary.php', { id: OBJECT_ID });
        if (!data.success) throw new Error(data.error || window.t('cmdb.summary.generate_failed'));
        await loadObject();
        render();
        toast(window.t('cmdb.summary.written'));
    } catch (err) {
        toast(err.message, true);
        await loadObject();
        render();
    } finally {
        summaryGenerating = false;
    }
}

/** Builds a Network Mapper diagram of the blast radius through create_impact_diagram.php and opens it. */
async function createImpactDiagram(btn) {
    if (btn) { btn.disabled = true; }
    try {
        // NB the endpoint takes object_id (not id), and signals the empty case
        // through `error` rather than a separate code.
        const data = await postJson(API + 'create_impact_diagram.php', { object_id: OBJECT_ID });
        if (!data.success) {
            throw new Error(data.error === 'empty_blast_radius'
                ? window.t('cmdb.impact.diagram_empty')
                : (data.error || window.t('cmdb.impact.diagram_failed')));
        }
        window.location.href = '../network-mapper/diagram.php?id=' + data.diagram_id + '&fit=1';
    } catch (err) {
        toast(err.message, true);
        if (btn) btn.disabled = false;
    }
}

/* ---------------- object_ref editing ---------------- */

/* Search is scoped to the property's target class and excludes this object, so
   a field can never be pointed at the thing that holds it. */
function beginEditObjectRef(cell, p) {
    const before = cell.innerHTML;
    cell.innerHTML =
        '<div class="autocomplete-wrap">' +
            '<input type="text" id="o2ref_' + p.property_id + '" autocomplete="off" ' +
                'placeholder="' + esc(window.t('cmdb.object.search_placeholder', { name: p.target_class_name || window.t('cmdb.new_object.objects') })) + '" ' +
                'value="' + (p.value_object ? esc(p.value_object.name) : '') + '">' +
            '<div class="autocomplete-results" id="o2refres_' + p.property_id + '"></div>' +
        '</div>';

    const input = document.getElementById('o2ref_' + p.property_id);
    const results = document.getElementById('o2refres_' + p.property_id);
    input.focus();
    input.select();

    let done = false;
    /** Saves the chosen reference (or null to clear it), once. */
    const finish = async raw => { if (done) return; done = true; await saveProperty(p, raw, cell, before); };
    /** Puts the old value back without saving, once. */
    const cancel = () => { if (done) return; done = true; cell.innerHTML = before; wireProps(); };

    wireAutocomplete(input, results, { class_id: p.target_class_id, exclude_id: obj.id }, picked => {
        input.value = picked.name;
        results.classList.remove('active');
        finish(picked.id);
    });

    input.addEventListener('keydown', e => { if (e.key === 'Escape') cancel(); });
    input.addEventListener('blur', () => {
        // Let a click on a result land before deciding what the blur meant.
        setTimeout(() => {
            if (done) return;
            if (input.value.trim() === '') finish(null);   // emptied = cleared
            else cancel();                                  // typed but picked nothing
        }, 200);
    });
}

/* ---------------- shared autocomplete ---------------- */

/**
 * Wires an input to search_objects.php (optionally limited to a class and excluding an object) with a keyboard-navigable result list; onPick gets the chosen object.
 */
function wireAutocomplete(inputEl, resultsEl, params, onPick) {
    let lastQuery = '';
    let current = [];
    acHighlightedIdx = -1;

    inputEl.addEventListener('input', () => {
        const q = inputEl.value.trim();
        if (q === lastQuery) return;
        lastQuery = q;
        if (acTimer) clearTimeout(acTimer);
        if (q === '') { resultsEl.classList.remove('active'); return; }
        acTimer = setTimeout(async () => {
            try {
                const url = API + 'search_objects.php?q=' + encodeURIComponent(q) +
                    (params.class_id ? '&class_id=' + params.class_id : '') +
                    (params.exclude_id ? '&exclude_id=' + params.exclude_id : '');
                const data = await (await fetch(url)).json();
                if (!data.success) return;
                current = data.results || [];
                acHighlightedIdx = -1;
                draw();
            } catch (e) { /* silent — an empty dropdown is the failure state */ }
        }, 200);
    });

    inputEl.addEventListener('keydown', e => {
        if (!resultsEl.classList.contains('active')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); acHighlightedIdx = Math.min(current.length - 1, acHighlightedIdx + 1); draw(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); acHighlightedIdx = Math.max(0, acHighlightedIdx - 1); draw(); }
        else if (e.key === 'Enter' && acHighlightedIdx >= 0) { e.preventDefault(); onPick(current[acHighlightedIdx]); }
        else if (e.key === 'Escape') { resultsEl.classList.remove('active'); }
    });

    /** Draws the look-up results (or "no matches") with the highlighted row marked. */
    function draw() {
        if (!current.length) {
            resultsEl.innerHTML = '<div class="ac-empty">' + esc(window.t('cmdb.new_object.no_matches')) + '</div>';
            resultsEl.classList.add('active');
            return;
        }
        resultsEl.innerHTML = current.map((r, i) =>
            '<div class="ac-result' + (i === acHighlightedIdx ? ' highlighted' : '') + '" data-idx="' + i + '">' +
                '<span>' + esc(r.name) + '</span><span class="ac-class">' + esc(r.class_name) + '</span>' +
            '</div>').join('');
        resultsEl.classList.add('active');
        resultsEl.querySelectorAll('.ac-result').forEach(el => {
            // mousedown, so it beats the input's blur handler
            el.addEventListener('mousedown', e => { e.preventDefault(); onPick(current[parseInt(el.dataset.idx, 10)]); });
        });
    }
}

/* ---------------- planned / in service ---------------- */

/** Flips the object between planned and in service. */
async function togglePlanned() {
    await savePartial({ is_planned: !obj.is_planned });
}

/* ---------------- parent picker ---------------- */

/** Opens the parent picker with the current parent filled in and an object search that excludes this object. */
function openParentModal() {
    document.getElementById('o2ParentInput').value = obj.parent_name || '';
    document.getElementById('o2ParentId').value = obj.parent_id || '';
    document.getElementById('o2ParentResults').classList.remove('active');
    document.getElementById('o2ParentModal').classList.add('active');
    setTimeout(() => document.getElementById('o2ParentInput').focus(), 0);

    wireAutocomplete(
        document.getElementById('o2ParentInput'),
        document.getElementById('o2ParentResults'),
        { exclude_id: obj.id },
        picked => {
            document.getElementById('o2ParentId').value = picked.id;
            document.getElementById('o2ParentInput').value = picked.name;
            document.getElementById('o2ParentResults').classList.remove('active');
        }
    );
}
/** Closes the parent picker. */
function closeParentModal() { document.getElementById('o2ParentModal').classList.remove('active'); }

/** Saves the picked parent (an emptied box clears it); the server rejects a parent that would make a cycle. */
async function saveParent() {
    const newId = document.getElementById('o2ParentId').value;
    const text = document.getElementById('o2ParentInput').value.trim();
    if (text === '') { closeParentModal(); await savePartial({ parent_id: null }); return; }
    if (!newId) { toast(window.t('cmdb.parent_modal.pick_suggestion'), true); return; }
    closeParentModal();
    // The server cycle-checks this — a parent that is its own descendant comes
    // back as an error rather than being silently accepted.
    await savePartial({ parent_id: parseInt(newId, 10) });
}

/** Closes the parent picker and removes the parent, if there is one. */
async function clearParent() {
    closeParentModal();
    if (!obj.parent_id) return;
    await savePartial({ parent_id: null });
}

/* ---------------- relationships ---------------- */

/* 'out' = this object is the subject (from → to).
   'in'  = the other object is the subject; from/to are swapped on save.
   Without this, "SolarWinds monitors this server" cannot be recorded from the
   server at all — you have to navigate to SolarWinds and add it there. Every
   verb in the library is directional and already stores its own inverse, so
   the row is identical either way; only which end is `from` changes. */
let relDirection = 'out';
let relTargetName = '';

/**
 * Opens the add-relationship modal with the verb list, outgoing direction and an object search; warns when no verbs are defined.
 */
function openRelModal() {
    if (!relationshipTypes.length) {
        toast(window.t('cmdb.rel_modal.none_defined'), true);
        return;
    }
    relDirection = 'out';
    relTargetName = '';
    const sel = document.getElementById('o2RelType');
    sel.innerHTML = relationshipTypes.map(rt => '<option value="' + rt.id + '">' + esc(rt.verb) + '</option>').join('');
    sel.onchange = updateRelPreview;
    document.getElementById('o2RelTarget').value = '';
    document.getElementById('o2RelTargetId').value = '';
    document.getElementById('o2RelResults').classList.remove('active');
    setRelDirection('out');
    document.getElementById('o2RelModal').classList.add('active');
    setTimeout(() => document.getElementById('o2RelTarget').focus(), 0);

    wireAutocomplete(
        document.getElementById('o2RelTarget'),
        document.getElementById('o2RelResults'),
        { exclude_id: obj.id },
        picked => {
            document.getElementById('o2RelTargetId').value = picked.id;
            document.getElementById('o2RelTarget').value = picked.name;
            relTargetName = picked.name;
            document.getElementById('o2RelResults').classList.remove('active');
            updateRelPreview();
        }
    );
}
/** Closes the add-relationship modal. */
function closeRelModal() { document.getElementById('o2RelModal').classList.remove('active'); }

/** Sets whether this object is the subject ('out') or the other object is ('in'), and refreshes the sentence preview. */
function setRelDirection(dir) {
    relDirection = dir;
    document.getElementById('o2RelDirOut').classList.toggle('on', dir === 'out');
    document.getElementById('o2RelDirIn').classList.toggle('on', dir === 'in');
    updateRelPreview();
}

/* Writes out the sentence that will actually be stored, both ways round, so
   nobody has to hold the direction in their head while picking a verb. */
function updateRelPreview() {
    const rt = relationshipTypes.find(r => r.id === parseInt(document.getElementById('o2RelType').value, 10));
    const el = document.getElementById('o2RelHint');
    if (!rt) { el.innerHTML = ''; return; }
    const other = relTargetName || window.t('cmdb.rel_modal.the_other_object');
    const subject = relDirection === 'out' ? obj.name : other;
    const object  = relDirection === 'out' ? other : obj.name;
    el.innerHTML =
        '<b>' + esc(subject) + '</b> ' + esc(rt.verb) + ' <b>' + esc(object) + '</b>' +
        '<br>' + esc(window.t('cmdb.rel_modal.preview_other_end')) + ' <b>' + esc(object) + '</b> ' + esc(rt.inverse_verb) + ' <b>' + esc(subject) + '</b>';
}

/**
 * Saves the relationship through save_object_relationship.php, swapping from and to for the 'in' direction, then reloads.
 */
async function saveRelationship() {
    const typeId = parseInt(document.getElementById('o2RelType').value, 10);
    const targetId = parseInt(document.getElementById('o2RelTargetId').value, 10);
    if (!typeId || !targetId) { toast(window.t('cmdb.rel_modal.pick_verb_object'), true); return; }
    try {
        const data = await postJson(API + 'save_object_relationship.php', {
            from_object_id: relDirection === 'out' ? obj.id : targetId,
            to_object_id:   relDirection === 'out' ? targetId : obj.id,
            relationship_type_id: typeId
        });
        if (!data.success) throw new Error(data.error || window.t('cmdb.rel_modal.save_failed'));
        closeRelModal();
        await reloadAndRender();
        toast(window.t('cmdb.rel_modal.added'));
    } catch (err) { toast(err.message, true); }
}

/** Scrolls to the Details card, where property links are edited. */
function jumpToDetails() {
    const el = document.getElementById('o2Details');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/** Asks for confirmation, then deletes a relationship through delete_object_relationship.php and reloads. */
async function deleteRelationship(id) {
    const ok = await confirmAction({
        title: window.t('cmdb.rel_modal.delete_title'),
        message: window.t('cmdb.rel_modal.delete_confirm'),
        okLabel: window.t('cmdb.rel_modal.delete_ok'),
        okClass: 'danger'
    });
    if (!ok) return;
    try {
        const data = await postJson(API + 'delete_object_relationship.php', { id: id });
        if (!data.success) throw new Error(data.error || window.t('cmdb.rel_modal.delete_failed'));
        await reloadAndRender();
        toast(window.t('cmdb.rel_modal.removed'));
    } catch (err) { toast(err.message, true); }
}

/* ---------------- property definition (floating modal) ---------------- */

let pdDrag = { active: false, dx: 0, dy: 0 };

/** Makes the property definition modal draggable by its header. */
function initPropDefModalDrag() {
    const head = document.getElementById('o2PdHeader');
    const modal = document.getElementById('o2PdModal');
    if (!head || !modal) return;
    head.addEventListener('mousedown', e => {
        pdDrag.active = true;
        const r = modal.getBoundingClientRect();
        // Drop the centring transform on first drag, or the modal jumps by half
        // its own width the moment it is grabbed.
        modal.style.transform = 'none';
        modal.style.left = r.left + 'px';
        modal.style.top = r.top + 'px';
        pdDrag.dx = e.clientX - r.left;
        pdDrag.dy = e.clientY - r.top;
        e.preventDefault();
    });
    document.addEventListener('mousemove', e => {
        if (!pdDrag.active) return;
        modal.style.left = (e.clientX - pdDrag.dx) + 'px';
        modal.style.top = (e.clientY - pdDrag.dy) + 'px';
    });
    document.addEventListener('mouseup', () => { pdDrag.active = false; });
}

/**
 * Opens the property definition modal for one of this class's properties, filled with its label, key, type, order, flags, target class and options.
 */
function openPropDefModal(propertyId) {
    const p = (obj.properties || []).find(x => x.property_id === propertyId);
    if (!p) return;
    document.getElementById('o2PdTitle').textContent = window.t('cmdb.prop_def.title_named', { label: p.label });
    document.getElementById('o2PdId').value = p.property_id;
    document.getElementById('o2PdLabel').value = p.label;
    document.getElementById('o2PdKey').value = p.property_key;
    document.getElementById('o2PdType').value = p.property_type;
    document.getElementById('o2PdOrder').value = p.display_order;
    document.getElementById('o2PdRequired').checked = !!p.is_required;
    document.getElementById('o2PdSpreads').checked = !!Number(p.spreads_impact);

    document.getElementById('o2PdTargetClass').innerHTML = '<option value="">' + esc(window.t('cmdb.prop_def.select')) + '</option>' +
        allClasses.map(c => '<option value="' + c.id + '"' + (p.target_class_id === c.id ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('');

    if (typeof renderOptionsEditor === 'function') renderOptionsEditor('o2PdOptions', p.options || []);
    onPropDefTypeChange();

    const modal = document.getElementById('o2PdModal');
    modal.style.left = '50%';
    modal.style.top = '90px';
    modal.style.transform = 'translateX(-50%)';
    modal.classList.add('active');
    setTimeout(() => document.getElementById('o2PdLabel').focus(), 0);
}
/** Closes the property definition modal. */
function closePropDefModal() { document.getElementById('o2PdModal').classList.remove('active'); }

/**
 * Shows the target-class and spreads-impact fields only for object references, and the options editor only for drop-downs.
 */
function onPropDefTypeChange() {
    const t = document.getElementById('o2PdType').value;
    document.getElementById('o2PdTargetGroup').style.display = t === 'object_ref' ? 'block' : 'none';
    document.getElementById('o2PdSpreadsGroup').style.display = t === 'object_ref' ? 'block' : 'none';
    document.getElementById('o2PdOptionsGroup').style.display = t === 'dropdown' ? 'block' : 'none';
}

/**
 * Validates and saves the property definition through save_class_property.php, then reloads, since it changes every object of the class.
 */
async function savePropDef() {
    const type = document.getElementById('o2PdType').value;
    const options = (type === 'dropdown' && typeof collectOptionsFromEditor === 'function')
        ? collectOptionsFromEditor('o2PdOptions') : [];
    const targetClassId = document.getElementById('o2PdTargetClass').value;

    if (type === 'object_ref' && !targetClassId) { toast(window.t('cmdb.prop_def.pick_target'), true); return; }
    if (type === 'dropdown' && !options.length) { toast(window.t('cmdb.prop_def.add_option'), true); return; }

    const payload = {
        id: document.getElementById('o2PdId').value || null,
        class_id: obj.class_id,
        label: document.getElementById('o2PdLabel').value,
        property_key: document.getElementById('o2PdKey').value,
        property_type: type,
        target_class_id: type === 'object_ref' ? targetClassId : null,
        is_required: document.getElementById('o2PdRequired').checked,
        spreads_impact: type === 'object_ref' && document.getElementById('o2PdSpreads').checked,
        display_order: parseInt(document.getElementById('o2PdOrder').value, 10) || 0,
        options: options
    };
    try {
        const data = await postJson(API + 'save_class_property.php', payload);
        if (!data.success) throw new Error(data.error || window.t('cmdb.prop_def.save_failed'));
        closePropDefModal();
        // Reload rather than patch — a type or options change rewrites how every
        // object of this class renders, not just this one.
        await reloadAndRender();
        toast(window.t('cmdb.prop_def.updated'));
    } catch (err) { toast(err.message, true); }
}

/**
 * Confirms (counting the whole subtree that will go with it), deletes the object through delete_object.php, and returns to Browse.
 */
async function deleteObject() {
    // Count the WHOLE subtree, not just direct children — the delete cascades
    // all the way down, so a warning that says "2 objects" when it will remove
    // nine is worse than no warning.
    const desc = (impact && impact.descendants) ? impact.descendants.length : (obj.children || []).length;
    const msg = desc
        ? window.t(desc === 1 ? 'cmdb.object.delete_confirm_children_one' : 'cmdb.object.delete_confirm_children_other', { count: desc })
        : window.t('cmdb.object.delete_confirm_undone');
    const ok = await confirmAction({
        title: window.t('cmdb.object.delete_confirm', { name: obj.name }),
        message: msg,
        okLabel: window.t('cmdb.danger.delete'),
        okClass: 'danger'
    });
    if (!ok) return;
    try {
        const data = await postJson(API + 'delete_object.php', { id: obj.id });
        if (!data.success) throw new Error(data.error || window.t('cmdb.object.delete_failed'));
        toast(data.deleted_descendants > 0
            ? window.t('cmdb.object.deleted_with_descendants', {
                count: data.deleted_descendants,
                word: window.t(data.deleted_descendants === 1 ? 'cmdb.object.descendant' : 'cmdb.object.descendants')
              })
            : window.t('cmdb.object.deleted'));
        setTimeout(() => { window.location.href = './'; }, 600);
    } catch (err) {
        toast(err.message, true);
    }
}
