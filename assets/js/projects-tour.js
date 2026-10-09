/**
 * Projects help - "How it fits together" (3.3.0): an interactive map of the
 * parts of a project and what feeds what, with a 12-step walk through an office
 * move, and a filter showing what each way of running starts with.
 *
 * Mounted into #prjTour by projects/tutorial.php - its own full-width page since
 * 3.3.0 (?walk=1 / data-start="walk" opens on the walk; Left / Right step it). The words are projects.tour.*;
 * the tools each method switches on come from the presets
 * (window.PRJ_TOUR = {methods: {simple: [...], ...}, labels: {simple: 'Simple', ...}}),
 * so the map can never disagree with includes/projects/methodologies.php.
 *
 * Accessible without the picture: every box is a button, and the panel beside
 * the map lists in words what the chosen part feeds and is fed by (each a
 * button too). Lines are decoration, redrawn on resize.
 */
(function () {
    'use strict';
    const T = (k, p) => (window.t ? window.t('projects.tour.' + k, p) : k);
    const TT = k => (window.t ? window.t('projects.tools.' + k) : k);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // id, group, the tool that switches it on (null = every project has it)
    const NODES = [
        ['goal', 'why', null], ['case', 'why', 'gates'], ['benefits', 'why', 'benefits'],
        ['scope', 'what', 'scope'], ['milestones', 'what', null],
        ['stages', 'when', null], ['tasks', 'when', null], ['deps', 'when', null], ['timeline', 'when', null], ['capacity', 'when', null],
        ['people', 'who', 'people'], ['raci', 'who', 'raci'], ['stakeholders', 'who', 'people'],
        ['raid', 'control', 'raid'], ['gates', 'control', 'gates'], ['tolerances', 'control', 'gates'], ['health', 'control', null],
        ['budget', 'control', 'budget'], ['earned', 'control', 'budget'], ['change', 'control', 'control'],
        ['reports', 'tell', null], ['alerts', 'tell', null], ['portfolio', 'tell', null], ['connections', 'tell', null],
    ];
    const GROUPS = ['why', 'what', 'when', 'who', 'control', 'tell'];
    // from -> to: "from feeds to"
    const EDGES = [
        ['goal', 'scope'], ['goal', 'case'], ['case', 'benefits'], ['case', 'gates'],
        ['scope', 'raci'], ['people', 'raci'], ['scope', 'change'],
        ['stages', 'tasks'], ['stages', 'milestones'], ['stages', 'gates'], ['tasks', 'deps'], ['deps', 'timeline'], ['milestones', 'timeline'], ['stages', 'timeline'],
        ['tasks', 'capacity'], ['people', 'capacity'], ['people', 'stakeholders'],
        ['tasks', 'health'], ['milestones', 'health'], ['raid', 'health'], ['tolerances', 'health'], ['connections', 'health'],
        ['tolerances', 'gates'], ['raid', 'gates'], ['gates', 'stages'],
        ['budget', 'earned'], ['tasks', 'earned'], ['budget', 'tolerances'],
        ['change', 'timeline'], ['change', 'budget'],
        ['health', 'portfolio'], ['health', 'alerts'], ['milestones', 'alerts'], ['change', 'alerts'], ['gates', 'alerts'],
        ['health', 'reports'], ['raid', 'reports'], ['budget', 'reports'], ['earned', 'reports'], ['benefits', 'reports'], ['stakeholders', 'reports'], ['deps', 'reports'],
    ];
    const STEPS = [
        ['goal', 'case', 'benefits'], ['stages', 'milestones'], ['tasks', 'deps', 'timeline'], ['people', 'capacity', 'raci', 'stakeholders'], ['scope'],
        ['raid', 'tolerances', 'health'], ['budget', 'earned'], ['gates'], ['change'], ['reports', 'alerts', 'portfolio'], ['connections'], ['benefits', 'reports'],
    ];

    let root, map, svg, panel, sel = null, mode = 'explore', step = 0, method = 'all';
    const cfg = () => window.PRJ_TOUR || { methods: {}, labels: {} };

    function mount(el) {
        root = el;
        const methods = Object.keys(cfg().methods);
        root.innerHTML = '<div class="prj-tour-bar">'
            + '<div class="prj-seg" role="tablist"><button type="button" data-tmode="explore" class="active">' + esc(T('explore')) + '</button>'
            + '<button type="button" data-tmode="walk">' + esc(T('walk')) + '</button></div>'
            + '<label class="prj-tour-method">' + esc(T('method')) + ' <select data-tmethod><option value="all">' + esc(T('m_all')) + '</option>'
            + methods.map(m => '<option value="' + esc(m) + '">' + esc(cfg().labels[m] || m) + '</option>').join('') + '</select></label></div>'
            + '<div class="prj-tour-body"><div class="prj-tour-map" role="group" aria-label="' + esc(T('aria_map')) + '"><svg class="prj-tour-lines" aria-hidden="true"></svg>'
            + GROUPS.map(g => '<div class="prj-tour-col"><div class="prj-tour-gh">' + esc(T('g_' + g)) + '</div>'
                + NODES.filter(n => n[1] === g).map(n => '<button type="button" class="prj-tour-node" data-node="' + n[0] + '" aria-pressed="false">' + esc(T('n_' + n[0])) + '</button>').join('') + '</div>').join('')
            + '</div><div class="prj-tour-panel" aria-live="polite"></div></div>';
        map = root.querySelector('.prj-tour-map'); svg = root.querySelector('.prj-tour-lines'); panel = root.querySelector('.prj-tour-panel');
        root.addEventListener('click', onClick);
        root.querySelector('[data-tmethod]').addEventListener('change', e => { method = e.target.value; draw(); });
        window.addEventListener('resize', () => lines());
        // Arrow keys step the walk (not while typing in the method filter).
        document.addEventListener('keydown', e => {
            if (mode !== 'walk' || (e.target.closest && e.target.closest('select, input, textarea'))) return;
            if (e.key === 'ArrowRight' && step < STEPS.length - 1) { step++; draw(); }
            else if (e.key === 'ArrowLeft' && step > 0) { step--; draw(); }
        });
        if (root.dataset.start === 'walk') { mode = 'walk'; syncMode(); }
        draw();
    }

    function onClick(e) {
        const n = e.target.closest('[data-node]');
        if (n) { if (mode === 'walk') { mode = 'explore'; syncMode(); } sel = sel === n.dataset.node ? null : n.dataset.node; draw(); return; }
        const m = e.target.closest('[data-tmode]');
        if (m) { mode = m.dataset.tmode; step = 0; sel = null; syncMode(); draw(); return; }
        const s = e.target.closest('[data-tstep]');
        if (s) { const v = s.dataset.tstep; step = v === 'next' ? Math.min(STEPS.length - 1, step + 1) : v === 'back' ? Math.max(0, step - 1) : 0; draw(); }
    }
    function syncMode() { root.querySelectorAll('[data-tmode]').forEach(b => b.classList.toggle('active', b.dataset.tmode === mode)); }

    function isOff(tool) { return method !== 'all' && tool !== null && !(cfg().methods[method] || []).includes(tool); }
    const related = id => ({ feeds: EDGES.filter(e => e[0] === id).map(e => e[1]), fed: EDGES.filter(e => e[1] === id).map(e => e[0]) });

    function draw() {
        const lit = mode === 'walk' ? STEPS[step] : (sel ? [sel] : []);
        root.classList.toggle('is-walk', mode === 'walk');   // a phone puts the step text above the map
        const rel = sel && mode === 'explore' ? related(sel) : { feeds: [], fed: [] };
        root.querySelectorAll('[data-node]').forEach(b => {
            const id = b.dataset.node, tool = NODES.find(n => n[0] === id)[2];
            b.classList.toggle('lit', lit.includes(id));
            b.classList.toggle('rel', rel.feeds.includes(id) || rel.fed.includes(id));
            b.classList.toggle('dim', (lit.length > 0 && !lit.includes(id) && !rel.feeds.includes(id) && !rel.fed.includes(id)));
            b.classList.toggle('off', isOff(tool));
            b.setAttribute('aria-pressed', lit.includes(id) ? 'true' : 'false');
        });
        panel.innerHTML = mode === 'walk' ? walkPanel() : (sel ? nodePanel(sel) : '<p class="prj-muted">' + esc(T('pick')) + '</p>');
        lines();
    }

    function nodePanel(id) {
        const tool = NODES.find(n => n[0] === id)[2];
        const r = related(id);
        const inMethods = tool ? Object.keys(cfg().methods).filter(m => cfg().methods[m].includes(tool)).map(m => cfg().labels[m] || m) : [];
        const chips = list => list.map(x => '<button type="button" class="prj-tour-chip" data-node="' + x + '">' + esc(T('n_' + x)) + '</button>').join('');
        return '<h4>' + esc(T('n_' + id)) + '</h4><p>' + esc(T('d_' + id)) + '</p>'
            + '<p class="prj-muted"><strong>' + esc(T('where')) + ':</strong> ' + esc(T('w_' + id)) + '</p>'
            + '<p class="prj-muted">' + esc(tool ? T('tool_on', { tool: TT(tool), methods: inMethods.join(', ') || T('tool_none') }) : T('tool_core')) + '</p>'
            + (isOff(tool) ? '<p class="prj-tour-off">' + esc(T('off')) + '</p>' : '')
            + (r.feeds.length ? '<div class="prj-tour-rel"><span>' + esc(T('feeds')) + '</span>' + chips(r.feeds) + '</div>' : '')
            + (r.fed.length ? '<div class="prj-tour-rel"><span>' + esc(T('fed_by')) + '</span>' + chips(r.fed) + '</div>' : '');
    }

    function walkPanel() {
        const n = step + 1, last = n === STEPS.length;
        return '<div class="prj-tour-step">' + esc(T('step', { n: n, total: STEPS.length })) + '</div>'
            + '<div class="prj-tour-dots" aria-hidden="true">' + STEPS.map((_, i) => '<span class="' + (i === step ? 'on' : i < step ? 'past' : '') + '"></span>').join('') + '</div>'
            + '<h4>' + esc(T('s' + n + '_title')) + '</h4><p>' + esc(T('s' + n + '_text')) + '</p>'
            + '<div class="prj-tour-nav"><button type="button" class="btn btn-secondary" data-tstep="back"' + (step === 0 ? ' disabled' : '') + '>' + esc(T('back')) + '</button>'
            + (last ? '<button type="button" class="btn btn-primary prj-btn" data-tstep="restart">' + esc(T('restart')) + '</button>'
                    : '<button type="button" class="btn btn-primary prj-btn" data-tstep="next">' + esc(T('next')) + '</button>') + '</div>';
    }

    /** Lines between the lit box and what it feeds / is fed by (explore), or between the step's boxes (walk). */
    function lines() {
        if (!svg) return;
        const box = map.getBoundingClientRect();
        svg.setAttribute('width', box.width); svg.setAttribute('height', box.height);
        svg.setAttribute('viewBox', '0 0 ' + box.width + ' ' + box.height);
        const rect = id => { const r = root.querySelector('[data-node="' + id + '"]').getBoundingClientRect(); return { l: r.left - box.left, r: r.right - box.left, t: r.top - box.top, b: r.bottom - box.top, cx: r.left - box.left + r.width / 2, cy: r.top - box.top + r.height / 2 }; };
        // From edge to edge, so the arrowhead is never under a box: side by side across columns, else top/bottom.
        const ends = (a, b) => {
            const A = rect(a), B = rect(b);
            if (B.l > A.r) return [{ x: A.r, y: A.cy }, { x: B.l, y: B.cy }, 'h'];
            if (B.r < A.l) return [{ x: A.l, y: A.cy }, { x: B.r, y: B.cy }, 'h'];
            return B.t > A.b ? [{ x: A.cx, y: A.b }, { x: B.cx, y: B.t }, 'v'] : [{ x: A.cx, y: A.t }, { x: B.cx, y: B.b }, 'v'];
        };
        let pairs = [];
        if (mode === 'explore' && sel) pairs = EDGES.filter(e => e[0] === sel || e[1] === sel);
        if (mode === 'walk') pairs = EDGES.filter(e => STEPS[step].includes(e[0]) && STEPS[step].includes(e[1]));
        svg.innerHTML = '<defs><marker id="prjTourArrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" class="prj-tour-arrowhead"/></marker></defs>'
            + pairs.map(([a, b]) => {
                const [p, q, dir] = ends(a, b), f = v => v.toFixed(1);
                const c = dir === 'h' ? [(p.x + q.x) / 2, p.y, (p.x + q.x) / 2, q.y] : [p.x, (p.y + q.y) / 2, q.x, (p.y + q.y) / 2];
                return '<path class="prj-tour-line" marker-end="url(#prjTourArrow)" d="M' + f(p.x) + ' ' + f(p.y) + ' C' + c.map(f).join(' ') + ' ' + f(q.x) + ' ' + f(q.y) + '"/>';
            }).join('');
    }

    document.addEventListener('DOMContentLoaded', () => { const el = document.getElementById('prjTour'); if (el) mount(el); });
})();
