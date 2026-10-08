/**
 * Report Packs - the designer (reporting/packs/designer.php).
 *
 * Office-style: a ribbon (Home, Insert, Layout, Design, Data), a searchable
 * toolbox with live previews, real pages with rulers, a properties pane, drag
 * and drop with snapping to a 12-column grid, resize handles, text edited in
 * place, undo/redo, shortcuts and a right-click menu.
 *
 * STATE AND RENDERING
 *   S.design is the single source of truth. Every change goes through commit(),
 *   which snapshots for undo, marks the pack unsaved and re-renders. Rendering
 *   asks the engine for pages (engine.js) and draws them as SVG (render-svg.js),
 *   with a layer of HTML frames on top for selection, handles and drop guides.
 *   Nothing here measures text or decides a page break.
 *
 * Text from a pack (names, titles, data) is put on the page with textContent;
 * the only HTML this file writes is its own fixed markup.
 */
(function () {
    'use strict';

    const API = window.RP_API;
    const E = window.RPEngine;
    const PX_PER_MM = 96 / 25.4;
    const T = (k, p) => (window.t ? window.t('reporting.packs.' + k, p) : k);
    const app = document.getElementById('rpApp');
    const packId = parseInt(app.dataset.pack, 10) || 0;

    const S = {
        pack: null, role: 'view', design: null, name: '', desc: '', updated: null,
        catalogue: null, presets: [], companies: [], multiCompany: false,
        sel: null,                 // selected block id
        zoom: 1, layout: null, dirty: false, saving: false,
        undo: [], redo: [],
        tab: 'home', query: '',
        editing: null,             // {kind: 'block'|'header'|'footer'|'cover', id}
        drag: null,
    };
    const rt = window.RPRuntime.create(API, window.RP_LOGO);
    const canEdit = () => S.role === 'owner' || S.role === 'edit';

    // ── Small helpers ────────────────────────────────────────────────────
    function h(tag, attrs, ...kids) {
        const e = document.createElement(tag);
        for (const k in attrs || {}) {
            const v = attrs[k];
            if (v === undefined || v === null || v === false) continue;
            if (k === 'class') e.className = v;
            else if (k === 'text') e.textContent = v;
            else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
            else if (k === 'style' && typeof v === 'object') Object.assign(e.style, v);
            else e.setAttribute(k, v === true ? '' : v);
        }
        kids.flat().forEach(c => { if (c !== null && c !== undefined && c !== false) e.append(c.nodeType ? c : document.createTextNode(String(c))); });
        return e;
    }
    const clone = (o) => JSON.parse(JSON.stringify(o));
    const uid = () => 'b' + Math.random().toString(36).slice(2, 9);
    const byId = (id) => S.design.blocks.find(b => b.id === id);
    const idx = (id) => S.design.blocks.findIndex(b => b.id === id);

    async function api(path, body, method) {
        const opts = body === undefined && !method ? {} : { method: method || 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) };
        const res = await fetch(API + path, opts);
        let data = null;
        try { data = await res.json(); } catch (e) { /* below */ }
        if (!data) throw Object.assign(new Error(T('err.load')), { status: res.status });
        if (!data.success) throw Object.assign(new Error(data.error || T('err.load')), { data, status: res.status });
        return data;
    }

    // Icons: small inline SVGs (24x24 viewBox), stroke-based like the rest of FreeITSM.
    const ICON = {
        undo: 'M9 14L4 9l5-5M4 9h10.5a5.5 5.5 0 0 1 0 11H11',
        redo: 'M15 14l5-5-5-5M20 9H9.5a5.5 5.5 0 0 0 0 11H13',
        save: 'M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2zM17 21v-8H7v8M7 3v5h8',
        pdf: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M12 18v-6M9 15l3 3 3-3',
        back: 'M15 18l-6-6 6-6',
        cut: 'M6 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM20 4L8.12 15.88M14.47 14.48L20 20M8.12 8.12L12 12',
        copy: 'M20 9h-9a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2zM5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1',
        paste: 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 2h6v4H9z',
        dup: 'M8 8h12v12H8zM4 16V4h12',
        trash: 'M3 6h18M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6M9 6V4h6v2',
        bold: 'M6 4h8a4 4 0 0 1 0 8H6zM6 12h9a4 4 0 0 1 0 8H6z',
        italic: 'M19 4h-9M14 20H5M15 4L9 20',
        underline: 'M6 3v7a6 6 0 0 0 12 0V3M4 21h16',
        strike: 'M17.3 5.5A4.6 4.6 0 0 0 12.6 4H11a4 4 0 0 0 0 8h2a4 4 0 0 1 0 8h-2.2a4.6 4.6 0 0 1-4.7-1.6M4 12h16',
        ul: 'M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01',
        ol: 'M10 6h11M10 12h11M10 18h11M4 6h1v4M4 10h2M6 18H4c0-1 2-2 2-3s-1-1.5-2-1',
        outdent: 'M21 8H11M21 12H11M21 16H11M7 8l-4 4 4 4',
        indent: 'M21 8H11M21 12H11M21 16H11M3 8l4 4-4 4',
        left: 'M17 10H3M21 6H3M21 14H3M17 18H3',
        center: 'M18 10H6M21 6H3M21 14H3M18 18H6',
        right: 'M21 10H7M21 6H3M21 14H3M21 18H7',
        justify: 'M21 10H3M21 6H3M21 14H3M21 18H3',
        clear: 'M4 7V4h16v3M9 20h6M12 4v16M3 3l18 18',
        cover: 'M4 3h16v18H4zM8 9h8M8 13h5',
        toc: 'M4 6h2M10 6h10M4 12h2M10 12h10M4 18h2M10 18h10',
        pagebreak: 'M4 3v6h16V3M4 21v-6h16v6M2 12h3M9 12h2M15 12h2M21 12h1',
        heading: 'M6 4v16M18 4v16M6 12h12',
        text: 'M4 7V4h16v3M9 20h6M12 4v16',
        line: 'M3 12h18',
        space: 'M3 6h18M3 18h18M12 9v6M9 12h6',
        header: 'M3 3h18v18H3zM3 8h18',
        footer: 'M3 3h18v18H3zM3 16h18',
        field: 'M7 8l-4 4 4 4M17 8l4 4-4 4M14 4l-4 16',
        logo: 'M3 5h18v14H3zM8 11a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM21 15l-5-5L5 19',
        portrait: 'M7 2h10v20H7z',
        landscape: 'M2 7h20v10H2z',
        up: 'M12 19V5M5 12l7-7 7 7',
        down: 'M12 5v14M19 12l-7 7-7-7',
        refresh: 'M23 4v6h-6M1 20v-6h6M3.5 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15',
        search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35',
        zoomIn: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35M11 8v6M8 11h6',
        zoomOut: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35M8 11h6',
        grip: 'M9 5h.01M15 5h.01M9 12h.01M15 12h.01M9 19h.01M15 19h.01',
        chart: 'M18 20V10M12 20V4M6 20v-6',
        table: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18',
        kpi: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
        uptime: 'M3 12h4l3-8 4 16 3-8h4',
        highlight: 'M9 11l-6 6v3h9l3-3M22 12l-4.6 4.6a2 2 0 0 1-2.8 0l-5.2-5.2a2 2 0 0 1 0-2.8L14 4',
        warn: 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01',
    };
    function icon(name, size) {
        const ns = 'http://www.w3.org/2000/svg';
        const s = document.createElementNS(ns, 'svg');
        s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', size || 18); s.setAttribute('height', size || 18);
        s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2');
        s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
        const p = document.createElementNS(ns, 'path'); p.setAttribute('d', ICON[name] || ''); s.appendChild(p);
        return s;
    }

    // ── History ──────────────────────────────────────────────────────────
    /**
     * Every change to the design goes through here.
     * opts.data   the change affects what data is fetched (options, criteria)
     * opts.merge  coalesce with the previous step if it had the same key (typing in a field)
     */
    function commit(fn, opts) {
        if (!canEdit() && !(opts && opts.viewer)) return;
        opts = opts || {};
        const before = JSON.stringify(S.design);
        const crBefore = JSON.stringify(S.design.criteria);
        fn(S.design);
        if (JSON.stringify(S.design) === before) return;
        // New period or company: the header's dates and company name change too,
        // and every data block needs fetching again.
        if (JSON.stringify(S.design.criteria) !== crBefore) {
            opts.data = true;
            rt.loadContext().then(() => { scheduleRender(); refreshRibbonState(); });
        }
        const last = S.undo[S.undo.length - 1];
        if (!(opts.merge && last && last.key === opts.merge && Date.now() - last.at < 1200)) {
            S.undo.push({ snap: before, sel: S.sel, key: opts.merge || null, at: Date.now() });
            if (S.undo.length > 150) S.undo.shift();
        } else last.at = Date.now();
        S.redo = [];
        if (!opts.viewer) setDirty(true);
        rt.design = S.design;
        if (opts.data) loadData();
        scheduleRender();
    }

    function undo() {
        if (RPEditor.isActive()) { RPEditor.close(true); }
        const step = S.undo.pop();
        if (!step) return;
        S.redo.push({ snap: JSON.stringify(S.design), sel: S.sel });
        S.design = JSON.parse(step.snap); rt.design = S.design;
        S.sel = byId(step.sel) ? step.sel : null;
        setDirty(true); loadData(); scheduleRender();
    }
    function redo() {
        const step = S.redo.pop();
        if (!step) return;
        S.undo.push({ snap: JSON.stringify(S.design), sel: S.sel, at: 0 });
        S.design = JSON.parse(step.snap); rt.design = S.design;
        S.sel = byId(step.sel) ? step.sel : null;
        setDirty(true); loadData(); scheduleRender();
    }

    function setDirty(d) {
        S.dirty = d;
        const st = document.getElementById('rpSaveState');
        if (st) {
            st.textContent = S.saving ? T('designer.saving') : d ? T('designer.unsaved') : T('designer.saved');
            st.className = 'rp-save-state' + (d ? ' is-dirty' : '');
        }
    }
    window.addEventListener('beforeunload', (e) => { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });

    function loadData() {
        rt.loadData().then(scheduleRender);
    }

    // ── Boot ─────────────────────────────────────────────────────────────
    async function boot() {
        try {
            const [p, c] = await Promise.all([api('get.php?id=' + packId), api('catalogue.php')]);
            S.pack = p.pack; S.role = p.pack.role; S.design = p.pack.design; S.name = p.pack.name;
            S.desc = p.pack.description || ''; S.updated = p.pack.updated;
            S.catalogue = c.catalogue; S.presets = c.presets; S.companies = c.companies; S.multiCompany = c.multi_company;
        } catch (e) {
            app.replaceChildren(h('div', { class: 'rp-boot rp-boot-error' },
                h('p', { text: e.message }), h('a', { href: './', class: 'btn btn-secondary', text: T('designer.back') })));
            return;
        }
        rt.design = S.design; rt.name = S.name;
        rt.onChange = scheduleRender;
        build();
        await rt.loadLogo();
        await rt.loadContext();
        scheduleRender();
        loadData();
    }

    // ── Layout of the app ────────────────────────────────────────────────
    let els = {};
    function build() {
        els.name = h('input', {
            class: 'rp-title-input', value: S.name, maxlength: 200, 'aria-label': T('list.name'),
            readonly: canEdit() ? null : true,
            oninput: () => { S.name = els.name.value; rt.name = S.name; setDirty(true); scheduleRender(); },
        });
        const titlebar = h('div', { class: 'rp-titlebar' },
            h('a', { class: 'rp-back', href: './', title: T('designer.back') }, icon('back'), h('span', { text: T('list.title') })),
            els.name,
            h('span', { class: 'rp-role rp-role-' + S.role, text: T({ owner: 'list.role_owner', edit: 'list.role_edit', view: 'list.role_view' }[S.role]) }),
            h('span', { id: 'rpSaveState', class: 'rp-save-state', text: T('designer.saved') }),
            h('div', { class: 'rp-title-spacer' }),
            canEdit() ? iconBtn('undo', T('designer.undo') + ' (Ctrl+Z)', undo, 'rpUndo') : null,
            canEdit() ? iconBtn('redo', T('designer.redo') + ' (Ctrl+Y)', redo, 'rpRedo') : null,
            canEdit() ? h('button', { class: 'btn btn-secondary rp-btn', onclick: save, title: 'Ctrl+S' }, icon('save', 16), T('list.save')) : null,
            !canEdit() ? h('button', { class: 'btn btn-secondary rp-btn', onclick: copyPack }, icon('copy', 16), T('list.copy')) : null,
            h('button', { class: 'btn btn-primary rp-btn', onclick: exportPdf, title: 'Ctrl+P' }, icon('pdf', 16), T('designer.export')),
        );

        els.ribbon = h('div', { class: 'rp-ribbon', role: 'toolbar', 'aria-label': T('designer.ribbon') });
        els.toolbox = h('aside', { class: 'rp-toolbox', 'aria-label': T('designer.toolbox') });
        els.canvas = h('div', { class: 'rp-canvas', tabindex: '-1' });
        els.ruler = h('div', { class: 'rp-ruler-h', 'aria-hidden': 'true' });
        els.canvasWrap = h('div', { class: 'rp-canvas-wrap' }, els.ruler, els.canvas);
        els.props = h('aside', { class: 'rp-props', 'aria-label': T('designer.properties') });
        els.status = h('div', { class: 'rp-statusbar' });
        const main = h('div', { class: 'rp-main' + (canEdit() ? '' : ' is-readonly') }, canEdit() ? els.toolbox : null, els.canvasWrap, els.props);

        const banner = canEdit() ? null : h('div', { class: 'rp-banner' }, icon('warn', 16), h('span', { text: T('designer.view_only') }));
        // replaceChildren() would write a missing element as the text "null".
        app.replaceChildren(...[titlebar, els.ribbon, banner, main, els.status].filter(Boolean));

        S.tab = canEdit() ? 'home' : 'data';
        buildRibbon();
        if (canEdit()) buildToolbox();
        bindCanvas();
        bindKeys();
    }

    function iconBtn(name, title, fn, id) {
        return h('button', { class: 'rp-icon-btn', title, 'aria-label': title, onclick: fn, id }, icon(name));
    }

    // ── The ribbon ───────────────────────────────────────────────────────
    function ribbonTabs() {
        const tabs = [
            { id: 'home', label: T('designer.tab.home'), groups: homeGroups },
            { id: 'insert', label: T('designer.tab.insert'), groups: insertGroups },
            { id: 'layout', label: T('designer.tab.layout'), groups: layoutGroups },
            { id: 'design', label: T('designer.tab.design'), groups: designGroups },
            { id: 'data', label: T('designer.tab.data'), groups: dataGroups },
        ];
        return canEdit() ? tabs : tabs.filter(t => t.id === 'data');
    }

    function buildRibbon() {
        const tabs = ribbonTabs();
        const tabBar = h('div', { class: 'rp-tabs', role: 'tablist' }, tabs.map(t =>
            h('button', {
                class: 'rp-tab' + (S.tab === t.id ? ' is-active' : ''), role: 'tab', 'aria-selected': S.tab === t.id ? 'true' : 'false',
                text: t.label, onclick: () => { S.tab = t.id; buildRibbon(); },
            })));
        const active = tabs.find(t => t.id === S.tab) || tabs[0];
        const panel = h('div', { class: 'rp-panel', role: 'tabpanel' }, active.groups().map(g =>
            h('div', { class: 'rp-group' }, h('div', { class: 'rp-group-body' }, g.items), h('div', { class: 'rp-group-label', text: g.label }))));
        els.ribbon.replaceChildren(tabBar, panel);
        refreshRibbonState();
    }

    /** A ribbon button. big = icon over label (Office's large buttons). */
    function rb(iconName, label, fn, opts) {
        opts = opts || {};
        const b = h('button', {
            class: 'rp-rb' + (opts.big ? ' rp-rb-big' : '') + (opts.cls ? ' ' + opts.cls : ''),
            title: opts.title || label, 'aria-label': opts.title || label, 'data-cmd': opts.cmd || null,
            'aria-pressed': opts.pressed === undefined ? null : String(!!opts.pressed),
            disabled: opts.disabled ? true : null,
            onmousedown: (e) => { if (opts.keepFocus) e.preventDefault(); },   // keep the text selection
            onclick: fn,
        }, icon(iconName, opts.big ? 22 : 16), opts.big || opts.showLabel ? h('span', { text: label }) : null);
        return b;
    }

    function sel(options, value, onChange, opts) {
        opts = opts || {};
        const s = h('select', { class: 'rp-rsel' + (opts.cls ? ' ' + opts.cls : ''), 'aria-label': opts.label || '', title: opts.label || '', disabled: opts.disabled ? true : null, onchange: () => onChange(s.value) });
        options.forEach(o => { const op = h('option', { value: o.value, text: o.label }); if (String(o.value) === String(value)) op.selected = true; s.append(op); });
        if (opts.keepFocus) s.addEventListener('mousedown', () => { S.keepEdit = true; });
        return s;
    }

    function colourPick(value, onChange, label, opts) {
        opts = opts || {};
        const wrap = h('label', { class: 'rp-colour', title: label });
        const sw = h('span', { class: 'rp-colour-sw', style: { background: value || '#000' } });
        const inp = h('input', { type: 'color', value: value || '#000000', 'aria-label': label });
        inp.addEventListener('input', () => { sw.style.background = inp.value; if (opts.live) onChange(inp.value); });
        inp.addEventListener('change', () => onChange(inp.value));
        if (opts.keepFocus) inp.addEventListener('mousedown', () => { S.keepEdit = true; });
        wrap.append(...[opts.icon ? icon(opts.icon, 16) : null, sw, inp].filter(Boolean));
        return wrap;
    }

    const textCmd = (name, value) => () => { RPEditor.cmd(name, value); refreshRibbonState(); };

    function homeGroups() {
        const ed = () => !!S.editing;
        const sizes = [7, 8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 32, 36, 48].map(v => ({ value: v, label: String(v) }));
        return [
            { label: T('designer.grp.clipboard'), items: [
                rb('paste', T('designer.paste'), pasteBlock, { big: true, disabled: !clip(), cmd: 'paste' }),
                h('div', { class: 'rp-stack' },
                    rb('cut', T('designer.cut'), cutBlock, { showLabel: true, cmd: 'needsel' }),
                    rb('copy', T('designer.copy'), copyBlock, { showLabel: true, cmd: 'needsel' }),
                    rb('dup', T('designer.duplicate'), duplicateBlock, { showLabel: true, cmd: 'needsel' })),
            ] },
            { label: T('designer.grp.font'), items: [
                h('div', { class: 'rp-stack' },
                    h('div', { class: 'rp-row' },
                        sel([{ value: '', label: T('designer.size') }].concat(sizes), '', v => { if (v) RPEditor.cmd('fontSize', +v); }, { label: T('designer.size'), keepFocus: true, cls: 'rp-needs-edit' })),
                    h('div', { class: 'rp-row' },
                        rb('bold', T('designer.bold') + ' (Ctrl+B)', textCmd('bold'), { cmd: 'bold', keepFocus: true, cls: 'rp-needs-edit' }),
                        rb('italic', T('designer.italic') + ' (Ctrl+I)', textCmd('italic'), { cmd: 'italic', keepFocus: true, cls: 'rp-needs-edit' }),
                        rb('underline', T('designer.underline') + ' (Ctrl+U)', textCmd('underline'), { cmd: 'underline', keepFocus: true, cls: 'rp-needs-edit' }),
                        rb('strike', T('designer.strike'), textCmd('strikeThrough'), { cmd: 'strike', keepFocus: true, cls: 'rp-needs-edit' }),
                        colourPick('#c00000', v => RPEditor.cmd('foreColor', v), T('designer.text_colour'), { keepFocus: true, icon: 'text' }),
                        colourPick('#fff200', v => RPEditor.cmd('hiliteColor', v), T('designer.highlight'), { keepFocus: true, icon: 'highlight' }),
                        rb('clear', T('designer.clear_format'), textCmd('clear'), { keepFocus: true, cls: 'rp-needs-edit' }))),
            ] },
            { label: T('designer.grp.paragraph'), items: [
                h('div', { class: 'rp-stack' },
                    h('div', { class: 'rp-row' },
                        rb('ul', T('designer.bullets'), textCmd('insertUnorderedList'), { cmd: 'ul', keepFocus: true, cls: 'rp-needs-edit' }),
                        rb('ol', T('designer.numbering'), textCmd('insertOrderedList'), { cmd: 'ol', keepFocus: true, cls: 'rp-needs-edit' }),
                        rb('outdent', T('designer.outdent'), textCmd('outdent'), { keepFocus: true, cls: 'rp-needs-edit' }),
                        rb('indent', T('designer.indent'), textCmd('indent'), { keepFocus: true, cls: 'rp-needs-edit' })),
                    h('div', { class: 'rp-row' },
                        rb('left', T('designer.align_left'), textCmd('justifyLeft'), { cmd: 'left', keepFocus: true, cls: 'rp-needs-edit' }),
                        rb('center', T('designer.align_center'), textCmd('justifyCenter'), { cmd: 'center', keepFocus: true, cls: 'rp-needs-edit' }),
                        rb('right', T('designer.align_right'), textCmd('justifyRight'), { cmd: 'right', keepFocus: true, cls: 'rp-needs-edit' }),
                        rb('justify', T('designer.justify'), textCmd('justifyFull'), { cmd: 'justify', keepFocus: true, cls: 'rp-needs-edit' }))),
            ] },
            { label: T('designer.grp.styles'), items: [
                h('div', { class: 'rp-styles' }, [['p', 'designer.style_normal'], ['h1', 'designer.style_h1'], ['h2', 'designer.style_h2'], ['h3', 'designer.style_h3']].map(([tag, key]) =>
                    h('button', {
                        class: 'rp-style-btn rp-style-' + tag + ' rp-needs-edit', 'data-cmd': 'style-' + tag, text: T(key),
                        onmousedown: (e) => e.preventDefault(), onclick: textCmd('block', tag === 'p' ? 'P' : tag.toUpperCase()),
                    }))),
            ] },
        ];
    }

    function insertGroups() {
        const d = S.design;
        const fieldMenu = sel([{ value: '', label: T('designer.insert_field') }].concat(RPEditor.FIELD_KEYS.map(f => ({ value: f, label: T('field.' + f) }))), '',
            v => { if (v) RPEditor.cmd('field', v); buildRibbon(); }, { label: T('designer.insert_field'), keepFocus: true, cls: 'rp-needs-edit' });
        return [
            { label: T('designer.grp.pages'), items: [
                rb('cover', T('designer.cover'), () => commit(x => { x.cover.on = !x.cover.on; }), { big: true, pressed: d.cover.on }),
                rb('toc', T('designer.contents'), () => commit(x => { x.toc.on = !x.toc.on; }), { big: true, pressed: d.toc.on }),
                rb('pagebreak', T('tool.pagebreak.title'), () => insertTool('pagebreak'), { big: true }),
            ] },
            { label: T('designer.grp.text'), items: [
                rb('heading', T('tool.heading.title'), () => insertTool('heading'), { big: true }),
                rb('text', T('tool.text.title'), () => insertTool('text'), { big: true }),
                h('div', { class: 'rp-stack' },
                    rb('line', T('tool.divider.title'), () => insertTool('divider'), { showLabel: true }),
                    rb('space', T('tool.spacer.title'), () => insertTool('spacer'), { showLabel: true })),
            ] },
            { label: T('designer.grp.header_footer'), items: [
                rb('header', T('designer.header'), () => editRegion('header'), { big: true, pressed: d.header.on }),
                rb('footer', T('designer.footer'), () => editRegion('footer'), { big: true, pressed: d.footer.on }),
            ] },
            { label: T('designer.grp.fields'), items: [
                h('div', { class: 'rp-stack' }, fieldMenu,
                    rb('logo', T('designer.logo'), textCmd('logo'), { showLabel: true, keepFocus: true, cls: 'rp-needs-edit' })),
            ] },
            { label: T('designer.grp.data'), items: [
                rb('chart', T('designer.find_block'), () => { const s = document.getElementById('rpToolSearch'); if (s) s.focus(); }, { big: true }),
            ] },
        ];
    }

    const MARGINS = { normal: { t: 18, r: 16, b: 18, l: 16 }, narrow: { t: 12, r: 12, b: 12, l: 12 }, wide: { t: 25, r: 25, b: 25, l: 25 } };
    function marginName(m) {
        for (const k in MARGINS) if (MARGINS[k].t === m.t && MARGINS[k].r === m.r && MARGINS[k].b === m.b && MARGINS[k].l === m.l) return k;
        return 'custom';
    }
    const WIDTHS = [[12, 'designer.w_full'], [9, 'designer.w_three_quarters'], [8, 'designer.w_two_thirds'], [6, 'designer.w_half'], [4, 'designer.w_third'], [3, 'designer.w_quarter']];

    function layoutGroups() {
        const d = S.design, b = S.sel && byId(S.sel);
        const sizable = b && (b.type === 'data' || b.type === 'text' || b.type === 'spacer');
        return [
            { label: T('designer.grp.page_setup'), items: [
                h('div', { class: 'rp-stack' },
                    h('label', { class: 'rp-field-row' }, h('span', { text: T('designer.size_page') }),
                        sel(['A4', 'Letter', 'Legal', 'A3'].map(v => ({ value: v, label: v })), d.page.size, v => commit(x => { x.page.size = v; }), { label: T('designer.size_page') })),
                    h('label', { class: 'rp-field-row' }, h('span', { text: T('designer.margins') }),
                        sel([['normal', 'designer.m_normal'], ['narrow', 'designer.m_narrow'], ['wide', 'designer.m_wide'], ['custom', 'designer.m_custom']].map(([v, k]) => ({ value: v, label: T(k) })),
                            marginName(d.page.margin), v => { if (MARGINS[v]) commit(x => { x.page.margin = clone(MARGINS[v]); }); else { S.sel = null; scheduleRender(); } }, { label: T('designer.margins') }))),
                rb('portrait', T('designer.portrait'), () => commit(x => { x.page.orient = 'portrait'; }), { big: true, pressed: d.page.orient === 'portrait' }),
                rb('landscape', T('designer.landscape'), () => commit(x => { x.page.orient = 'landscape'; }), { big: true, pressed: d.page.orient === 'landscape' }),
            ] },
            { label: T('designer.grp.arrange'), items: [
                h('div', { class: 'rp-widths' }, WIDTHS.map(([span, key]) => h('button', {
                    class: 'rp-width-btn' + (b && b.span === span ? ' is-active' : ''), disabled: sizable ? null : true,
                    title: T(key), 'aria-label': T(key), onclick: () => setSpan(S.sel, span),
                }, widthGlyph(span), h('span', { text: T(key) })))),
                h('div', { class: 'rp-stack' },
                    rb('up', T('designer.move_up'), () => moveBlock(S.sel, -1), { showLabel: true, cmd: 'needsel' }),
                    rb('down', T('designer.move_down'), () => moveBlock(S.sel, 1), { showLabel: true, cmd: 'needsel' }),
                    rb('pagebreak', T('designer.new_row'), () => commit(x => { const bb = x.blocks.find(q => q.id === S.sel); if (bb) bb.newRow = !bb.newRow; }), { showLabel: true, cmd: 'needsel', pressed: b && !!b.newRow })),
            ] },
        ];
    }

    function widthGlyph(span) {
        const g = h('span', { class: 'rp-width-glyph', 'aria-hidden': 'true' });
        g.append(h('i', { style: { width: (span / 12 * 100) + '%' } }));
        return g;
    }

    function designGroups() {
        const th = S.design.theme;
        const set = (k) => (v) => commit(x => { x.theme[k] = v; }, { merge: 'theme-' + k });
        return [
            { label: T('designer.grp.theme'), items: [
                h('div', { class: 'rp-stack' },
                    h('label', { class: 'rp-field-row' }, h('span', { text: T('designer.font') }),
                        sel([['helvetica', 'Helvetica'], ['times', 'Times'], ['courier', 'Courier']].map(([v, l]) => ({ value: v, label: l })), th.font, set('font'), { label: T('designer.font') })),
                    h('label', { class: 'rp-field-row' }, h('span', { text: T('designer.body_size') }),
                        sel([8, 8.5, 9, 9.5, 10, 10.5, 11, 12].map(v => ({ value: v, label: v + ' pt' })), th.size, v => set('size')(+v), { label: T('designer.body_size') }))),
            ] },
            { label: T('designer.grp.colours'), items: [
                h('div', { class: 'rp-colour-grid' },
                    h('span', { text: T('designer.c_headings') }), colourPick(th.heading, set('heading'), T('designer.c_headings')),
                    h('span', { text: T('designer.c_accent') }), colourPick(th.accent, set('accent'), T('designer.c_accent')),
                    h('span', { text: T('designer.c_table_head') }), colourPick(th.th_bg, set('th_bg'), T('designer.c_table_head')),
                    h('span', { text: T('designer.c_table_text') }), colourPick(th.th_fg, set('th_fg'), T('designer.c_table_text'))),
            ] },
            { label: T('designer.grp.charts'), items: [
                h('div', { class: 'rp-palettes' }, Object.keys(window.RPCharts.PALETTES).map(p => h('button', {
                    class: 'rp-palette' + (th.palette === p ? ' is-active' : ''), title: T('designer.palette.' + p), 'aria-label': T('designer.palette.' + p),
                    onclick: () => set('palette')(p),
                }, window.RPCharts.PALETTES[p].slice(0, 5).map(c => h('i', { style: { background: c } }))))),
            ] },
            { label: T('designer.grp.tables'), items: [
                rb('table', T('designer.stripes'), () => commit(x => { x.theme.stripe = !x.theme.stripe; }), { big: true, pressed: th.stripe }),
            ] },
        ];
    }

    function dataGroups() {
        const cr = S.design.criteria;
        const viewerOpts = canEdit() ? {} : { viewer: true };
        const setCr = (fn) => commit(x => fn(x.criteria), Object.assign({ data: true }, viewerOpts));
        const items = [
            h('div', { class: 'rp-stack' },
                h('label', { class: 'rp-field-row' }, h('span', { text: T('designer.period') }),
                    sel(S.presets, cr.range.preset, v => setCr(c => { c.range = { preset: v }; if (v === 'custom') { c.range.from = rt.range ? rt.range.from_date : ''; c.range.to = rt.range ? rt.range.to_date : ''; } afterCriteria(); }), { label: T('designer.period') })),
                cr.range.preset === 'custom' ? h('div', { class: 'rp-row rp-dates' },
                    h('input', { type: 'date', value: cr.range.from || '', 'aria-label': T('designer.from'), onchange: (e) => setCr(c => { c.range.from = e.target.value; afterCriteria(); }) }),
                    h('span', { text: '–' }),
                    h('input', { type: 'date', value: cr.range.to || '', 'aria-label': T('designer.to'), onchange: (e) => setCr(c => { c.range.to = e.target.value; afterCriteria(); }) })) : null,
                h('div', { class: 'rp-period-text', id: 'rpPeriodText' })),
        ];
        const groups = [{ label: T('designer.grp.period'), items }];
        if (S.multiCompany) {
            const opts = [{ value: 'active', label: T('designer.company_active') }, { value: 'all', label: T('designer.company_all') }]
                .concat(S.companies.map(c => ({ value: c.id, label: c.name })));
            groups.push({ label: T('designer.grp.company'), items: [
                sel(opts, cr.tenant, v => setCr(c => { c.tenant = (v === 'active' || v === 'all') ? v : +v; afterCriteria(); }), { label: T('designer.company') }),
            ] });
        }
        groups.push({ label: T('designer.grp.refresh'), items: [rb('refresh', T('designer.refresh'), () => { rt.refresh().then(scheduleRender); }, { big: true })] });
        return groups;
    }

    function afterCriteria() {
        rt.loadContext().then(() => { scheduleRender(); buildRibbon(); });
    }

    /** Pressed/disabled state of the ribbon, from the selection and the cursor. */
    function refreshRibbonState() {
        const st = RPEditor.state();
        els.ribbon.querySelectorAll('.rp-needs-edit').forEach(b => { b.disabled = !S.editing; });
        els.ribbon.querySelectorAll('[data-cmd]').forEach(b => {
            const c = b.dataset.cmd;
            if (c === 'needsel') { b.disabled = !S.sel; return; }
            if (c === 'paste') { b.disabled = !clip(); return; }
            if (st && st[c] !== undefined) b.setAttribute('aria-pressed', String(!!st[c]));
            if (st && c.startsWith('style-')) b.classList.toggle('is-active', st.block === c.slice(6));
        });
        const u = document.getElementById('rpUndo'), r = document.getElementById('rpRedo');
        if (u) u.disabled = !S.undo.length;
        if (r) r.disabled = !S.redo.length;
        const pt = document.getElementById('rpPeriodText');
        if (pt && rt.fields.date_from) pt.textContent = rt.fields.date_from + ' – ' + rt.fields.date_to + (rt.companyError ? ' · ' + rt.companyError : '');
    }
    document.addEventListener('selectionchange', () => { if (S.editing) refreshRibbonState(); });

    // ── The toolbox ──────────────────────────────────────────────────────
    const AREAS = [['layout', 'designer.area.layout'], ['tickets', 'designer.area.tickets'], ['status', 'designer.area.status'], ['software', 'designer.area.software'], ['assets', 'designer.area.assets'], ['projects', 'designer.area.projects']];
    const TOOL_ICON = (t) => t.type === 'heading' ? 'heading' : t.type === 'text' ? 'text' : t.type === 'pagebreak' ? 'pagebreak'
        : t.type === 'spacer' ? 'space' : t.type === 'divider' ? 'line'
        : ({ chart: 'chart', table: 'table', kpi: 'kpi', uptime: 'uptime' }[(S.catalogue.handlers[t.handler] || {}).kind] || 'chart');

    function buildToolbox() {
        const search = h('input', {
            type: 'search', id: 'rpToolSearch', class: 'rp-tool-search', placeholder: T('designer.search_blocks'), 'aria-label': T('designer.search_blocks'),
            oninput: () => { S.query = search.value.trim().toLowerCase(); renderTools(); },
            onkeydown: (e) => { if (e.key === 'ArrowDown') { const f = els.toolList.querySelector('.rp-tool'); if (f) { e.preventDefault(); f.focus(); } } },
        });
        els.toolList = h('div', { class: 'rp-tool-list', role: 'listbox', 'aria-label': T('designer.toolbox') });
        els.toolbox.replaceChildren(
            h('div', { class: 'rp-pane-head' }, h('h2', { text: T('designer.toolbox') })),
            h('div', { class: 'rp-tool-search-wrap' }, icon('search', 15), search),
            h('p', { class: 'rp-tool-hint', text: T('designer.toolbox_hint') }),
            els.toolList);
        renderTools();
    }

    function renderTools() {
        const q = S.query;
        const match = (t) => !q || (t.title + ' ' + t.desc + ' ' + t.keywords + ' ' + T('designer.area.' + t.area)).toLowerCase().includes(q);
        const groups = AREAS.map(([area, key]) => {
            const tools = S.catalogue.tools.filter(t => t.area === area && match(t));
            if (!tools.length) return null;
            return h('div', { class: 'rp-tool-group' },
                h('h3', { text: T(key) }),
                tools.map(t => {
                    const item = h('div', {
                        class: 'rp-tool' + (t.available ? '' : ' is-unavailable'), role: 'option', tabindex: '0', 'data-key': t.key,
                        title: t.available ? t.desc : T('err.no_module', { module: (S.catalogue.handlers[t.handler] || {}).module_name || '' }),
                        'aria-disabled': t.available ? null : 'true',
                    }, h('span', { class: 'rp-tool-icon' }, icon(TOOL_ICON(t), 18)),
                       h('span', { class: 'rp-tool-text' }, h('strong', { text: t.title }), h('small', { text: t.desc })));
                    if (t.available) {
                        item.addEventListener('pointerdown', (e) => startDrag(e, { tool: t }));
                        item.addEventListener('dblclick', () => insertTool(t.key));
                        item.addEventListener('keydown', (e) => {
                            if (e.key === 'Enter') { e.preventDefault(); insertTool(t.key); }
                            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                                e.preventDefault();
                                const all = Array.from(els.toolList.querySelectorAll('.rp-tool'));
                                const n = all[all.indexOf(item) + (e.key === 'ArrowDown' ? 1 : -1)];
                                if (n) n.focus();
                            }
                        });
                        item.addEventListener('mouseenter', () => schedulePreview(t, item));
                        item.addEventListener('focus', () => schedulePreview(t, item));
                        item.addEventListener('mouseleave', hidePreview);
                        item.addEventListener('blur', hidePreview);
                    }
                    return item;
                }));
        }).filter(Boolean);
        els.toolList.replaceChildren(...(groups.length ? groups : [h('p', { class: 'rp-tool-none', text: T('designer.no_blocks') })]));
    }

    /** A block made from a toolbox item. */
    function blockFromTool(key) {
        const t = S.catalogue.tools.find(x => x.key === key);
        if (!t) return null;
        const b = { id: uid(), type: t.type, span: t.span };
        if (t.type === 'heading') Object.assign(b, { text: T('designer.new_heading'), level: 1, newPage: false, toc: true });
        if (t.type === 'text') b.doc = [{ t: 'p', a: 'left', r: [{ x: T('designer.new_text') }] }];
        if (t.type === 'spacer') b.height = 8;
        if (t.type === 'data') Object.assign(b, { handler: t.handler, opts: clone(t.opts || {}), title: t.title, showTitle: true });
        return b;
    }

    /** Double-click / Enter / ribbon: put the block after the selection, or at the end. */
    function insertTool(key, at) {
        if (!canEdit()) return;
        const b = blockFromTool(key);
        if (!b) return;
        commit(d => {
            const i = at !== undefined ? at : (S.sel ? idx(S.sel) + 1 : d.blocks.length);
            d.blocks.splice(i < 0 ? d.blocks.length : i, 0, b);
        }, { data: b.type === 'data' });
        select(b.id, true);
        if (b.type === 'text' || b.type === 'heading') setTimeout(() => editBlock(b.id), 60);
    }

    // Live preview popover
    let previewTimer = null, previewEl = null;
    function schedulePreview(t, item) {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(() => showPreview(t, item), 280);
    }
    function hidePreview() {
        clearTimeout(previewTimer);
        if (previewEl) { previewEl.remove(); previewEl = null; }
    }
    async function showPreview(t, item) {
        if (S.drag) return;
        hidePreview();
        const b = blockFromTool(t.key);
        const box = h('div', { class: 'rp-preview', role: 'tooltip' },
            h('strong', { text: t.title }), h('p', { text: t.desc }), h('div', { class: 'rp-preview-page' }, h('span', { class: 'rp-preview-loading', text: T('designer.loading_block') })));
        document.body.append(box);
        const r = item.getBoundingClientRect();
        box.style.left = (r.right + 10) + 'px';
        box.style.top = Math.max(8, Math.min(window.innerHeight - 330, r.top - 30)) + 'px';
        previewEl = box;
        const entry = b.type === 'data' ? await rt.fetchBlock(b) : null;
        if (previewEl !== box) return;
        // Lay the block out alone on a page the width of this pack's, and show it.
        const mini = clone(S.design);
        mini.header.on = false; mini.footer.on = false; mini.cover.on = false; mini.toc.on = false;
        mini.blocks = [b];
        const dm = {}; if (entry) dm[b.id] = entry;
        const L = E.paginate(mini, dm, rt.ctx());
        const pg = L.pages[0];
        const it = pg.items[0];
        if (!it) return;
        const svg = window.RPRenderSvg.page(pg, L.geometry, { logoUrl: rt.logo && rt.logo.url, chartImage: (x) => rt.chartImage(x, 1.5) });
        const pad = 3;
        const vbH = Math.min(it.h + pad * 2, 140);
        svg.setAttribute('viewBox', (it.x - pad) + ' ' + (it.y - pad) + ' ' + (it.w + pad * 2) + ' ' + vbH);
        svg.setAttribute('width', '100%'); svg.removeAttribute('height');
        box.querySelector('.rp-preview-page').replaceChildren(svg);
    }

    // ── The canvas: pages, rulers, frames ────────────────────────────────
    let renderQueued = false;
    function scheduleRender() {
        if (renderQueued) return;
        renderQueued = true;
        requestAnimationFrame(() => { renderQueued = false; render(); });
    }

    function pxmm() { return PX_PER_MM * S.zoom; }

    function render() {
        if (!S.design) return;
        if (S.editing) { refreshRibbonState(); return; }   // never redraw under the cursor
        const scrollTop = els.canvas.scrollTop;
        S.layout = rt.layout();
        const L = S.layout, G = L.geometry, k = pxmm();
        const chartScale = Math.min(3, Math.round(S.zoom * (window.devicePixelRatio || 1) * 2) / 2 || 1);

        const pages = L.pages.map((pg, pi) => {
            const svg = window.RPRenderSvg.page(pg, G, { logoUrl: rt.logo && rt.logo.url, chartImage: (it) => rt.chartImage(it, chartScale) });
            svg.setAttribute('width', (G.pw * k) + 'px');
            svg.setAttribute('height', (G.ph * k) + 'px');
            const overlay = h('div', { class: 'rp-overlay' });
            const wrap = h('div', { class: 'rp-page', 'data-page': pi, style: { width: (G.pw * k) + 'px', height: (G.ph * k) + 'px' } }, svg, overlay);
            // Margins guide
            overlay.append(h('div', { class: 'rp-margin-guide', style: {
                left: G.m.l * k + 'px', top: G.m.t * k + 'px', width: G.cw * k + 'px', height: (G.ph - G.m.t - G.m.b) * k + 'px' } }));
            // Header / footer / cover hit areas
            if (canEdit()) {
                if (pg.type !== 'cover') {
                    const hh = pg.header ? Math.max(L.headerH, 8) : 10;
                    overlay.append(regionHit('header', G.m.l * k, Math.max(0, (G.m.t - 6)) * k, G.cw * k, (hh + 6) * k));
                    const fh = pg.footer ? Math.max(L.footerH, 8) : 10;
                    overlay.append(regionHit('footer', G.m.l * k, (G.ph - G.m.b - fh) * k, G.cw * k, (fh + 4) * k));
                } else {
                    overlay.append(regionHit('cover', G.m.l * k, G.m.t * k, G.cw * k, (G.ph - G.m.t - G.m.b) * k));
                }
            }
            // Block frames
            pg.items.forEach(it => overlay.append(frame(it, k)));
            // Page number tab, like a word processor's status
            wrap.append(h('div', { class: 'rp-page-tag', text: pg.type === 'cover' ? T('designer.cover') : pg.type === 'toc' ? T('designer.contents') : T('designer.page_n', { n: pg.number }) }));
            wrap.append(vRuler(G, k));
            return wrap;
        });
        const stack = h('div', { class: 'rp-pages', style: { width: (G.pw * k) + 'px' } }, pages);
        if (!L.pages.some(p => p.type === 'body' && p.items.length) && canEdit()) {
            stack.querySelector('.rp-page[data-page="' + L.pages.findIndex(p => p.type === 'body') + '"] .rp-overlay')
                ?.append(h('div', { class: 'rp-empty-hint', style: { top: (G.m.t + L.headerH + 20) * k + 'px' } }, T('designer.empty_page')));
        }
        els.canvas.replaceChildren(stack);
        els.canvas.scrollTop = scrollTop;
        hRuler(G, k);
        renderStatus();
        renderProps();
        refreshRibbonState();
    }

    function regionHit(kind, x, y, w, hgt) {
        return h('div', {
            class: 'rp-region rp-region-' + kind, style: { left: x + 'px', top: y + 'px', width: w + 'px', height: hgt + 'px' },
            title: T('designer.dbl_edit_' + kind), 'data-region': kind,
            ondblclick: (e) => { e.stopPropagation(); editRegion(kind); },
        }, h('span', { class: 'rp-region-label', text: T('designer.' + kind) }));
    }

    function frame(it, k) {
        const selected = S.sel === it.id;
        const f = h('div', {
            class: 'rp-frame' + (selected ? ' is-selected' : '') + (it.type === 'pagebreak' ? ' rp-frame-break' : '') + (it.state === 'error' ? ' is-error' : ''),
            'data-id': it.id, tabindex: it.continued ? '-1' : '0',
            'aria-label': frameLabel(it.block), role: 'button',
            style: { left: (it.x * k) + 'px', top: (it.y * k) + 'px', width: (it.w * k) + 'px', height: Math.max(it.type === 'pagebreak' ? 0 : 6, it.h * k) + 'px' },
        });
        if (it.type === 'pagebreak') f.append(h('span', { class: 'rp-break-label', text: T('tool.pagebreak.title') }));
        if (it.continued) f.append(h('span', { class: 'rp-cont', text: T('designer.continued') }));
        if (selected && canEdit() && !it.continued) {
            f.append(h('div', { class: 'rp-frame-tag' }, icon('grip', 14), h('span', { text: frameLabel(it.block) })));
            const b = it.block;
            if (b.type === 'data' || b.type === 'text' || b.type === 'spacer') {
                f.append(h('div', { class: 'rp-handle rp-handle-e', title: T('designer.drag_width'), 'data-handle': 'e' }));
                f.append(h('div', { class: 'rp-handle rp-handle-w', title: T('designer.drag_width'), 'data-handle': 'w' }));
            }
            if (b.type === 'spacer' || (b.type === 'data' && it.chart)) {
                f.append(h('div', { class: 'rp-handle rp-handle-s', title: T('designer.drag_height'), 'data-handle': 's' }));
            }
        }
        return f;
    }

    function frameLabel(b) {
        if (!b) return '';
        if (b.type === 'data') return b.title || (S.catalogue.tools.find(t => t.handler === b.handler) || {}).title || b.handler;
        if (b.type === 'heading') return b.text || T('tool.heading.title');
        return T('tool.' + b.type + '.title');
    }

    /** The horizontal ruler: centimetres, the margins, and the column grid of the selection's row. */
    function hRuler(G, k) {
        const w = G.pw * k;
        const can = h('canvas', { width: Math.round(w * (window.devicePixelRatio || 1)), height: Math.round(22 * (window.devicePixelRatio || 1)), style: { width: w + 'px', height: '22px' } });
        const c = can.getContext('2d'); const dpr = window.devicePixelRatio || 1;
        c.scale(dpr, dpr);
        const dark = document.documentElement.getAttribute('data-theme-mode') === 'dark' || document.documentElement.getAttribute('data-theme') === 'dark';
        c.fillStyle = dark ? '#2a2f36' : '#f1f3f5'; c.fillRect(0, 0, w, 22);
        c.fillStyle = dark ? '#3a4049' : '#d9dee4';
        c.fillRect(0, 0, G.m.l * k, 22); c.fillRect((G.pw - G.m.r) * k, 0, G.m.r * k, 22);
        // column grid
        c.fillStyle = dark ? 'rgba(202,80,16,.35)' : 'rgba(202,80,16,.18)';
        for (let i = 0; i < 12; i++) c.fillRect((G.m.l + i * (S.layout.colW + S.layout.gutter)) * k, 17, S.layout.colW * k, 5);
        c.strokeStyle = dark ? '#8b949e' : '#7a828c'; c.fillStyle = dark ? '#c9d1d9' : '#4a5058';
        c.font = '10px sans-serif'; c.textAlign = 'center';
        // Measured from the left margin, in centimetres, as a word processor's ruler is.
        for (let r = -Math.floor(G.m.l); r <= G.pw - G.m.l; r++) {
            const x = (G.m.l + r) * k;
            const len = r % 10 === 0 ? 8 : r % 5 === 0 ? 5 : 2.5;
            if (k < 2 && r % 5 !== 0) continue;
            c.beginPath(); c.moveTo(Math.round(x) + .5, 0); c.lineTo(Math.round(x) + .5, len); c.stroke();
            if (r % 10 === 0 && r !== 0 && x > 6 && x < w - 6) c.fillText(String(Math.abs(r) / 10), x, 16);
        }
        els.ruler.replaceChildren(h('div', { class: 'rp-ruler-inner', style: { width: w + 'px' } }, can));
        // Line the ruler up with the page: the canvas has a scrollbar the ruler does not.
        els.ruler.style.paddingRight = (els.canvas.offsetWidth - els.canvas.clientWidth) + 'px';
        els.ruler.scrollLeft = els.canvas.scrollLeft;
    }

    function vRuler(G, k) {
        const hgt = G.ph * k, dpr = window.devicePixelRatio || 1;
        const can = h('canvas', { class: 'rp-ruler-v', width: Math.round(18 * dpr), height: Math.round(hgt * dpr), style: { height: hgt + 'px' }, 'aria-hidden': 'true' });
        const c = can.getContext('2d'); c.scale(dpr, dpr);
        const dark = document.documentElement.getAttribute('data-theme-mode') === 'dark';
        c.fillStyle = dark ? '#2a2f36' : '#f1f3f5'; c.fillRect(0, 0, 18, hgt);
        c.fillStyle = dark ? '#3a4049' : '#d9dee4';
        c.fillRect(0, 0, 18, G.m.t * k); c.fillRect(0, (G.ph - G.m.b) * k, 18, G.m.b * k);
        c.strokeStyle = dark ? '#8b949e' : '#7a828c';
        for (let mm = 0; mm <= G.ph; mm += (k < 2 ? 5 : 1)) {
            const y = mm * k, len = mm % 10 === 0 ? 8 : mm % 5 === 0 ? 5 : 2.5;
            c.beginPath(); c.moveTo(0, Math.round(y) + .5); c.lineTo(len, Math.round(y) + .5); c.stroke();
        }
        return can;
    }

    function renderStatus() {
        const L = S.layout;
        const unsupported = rt.checkUnsupported();
        const zoomPct = Math.round(S.zoom * 100);
        const zr = h('input', { type: 'range', min: 40, max: 200, step: 10, value: zoomPct, 'aria-label': T('designer.zoom'), oninput: (e) => setZoom(+e.target.value / 100) });
        els.status.replaceChildren(...[
            h('span', { text: T('designer.pages_n', { n: L.pages.length }) }),
            h('span', { text: T('designer.blocks_n', { n: S.design.blocks.length }) }),
            rt.fields.date_from ? h('span', { text: rt.fields.date_from + ' – ' + rt.fields.date_to }) : null,
            unsupported ? h('span', { class: 'rp-warn', title: T('designer.unsupported_long') }, icon('warn', 14), T('designer.unsupported')) : null,
            h('div', { class: 'rp-title-spacer' }),
            h('button', { class: 'rp-link', onclick: fitWidth, text: T('designer.fit') }),
            iconBtn('zoomOut', T('designer.zoom_out'), () => setZoom(S.zoom - 0.1)),
            zr,
            iconBtn('zoomIn', T('designer.zoom_in'), () => setZoom(S.zoom + 0.1)),
            h('span', { class: 'rp-zoom-pct', text: zoomPct + '%' }),
        ].filter(Boolean));
    }

    function setZoom(z) {
        S.zoom = Math.max(0.4, Math.min(2, Math.round(z * 10) / 10));
        try { localStorage.setItem('rp.zoom', String(S.zoom)); } catch (e) { /* private mode */ }
        scheduleRender();
    }
    function fitWidth() {
        const G = E.pageGeometry(S.design);
        setZoom((els.canvas.clientWidth - 80) / (G.pw * PX_PER_MM));
    }
    try { const z = parseFloat(localStorage.getItem('rp.zoom')); if (z) S.zoom = z; } catch (e) { /* none */ }

    // ── Selection and the properties pane ────────────────────────────────
    function select(id, scrollTo) {
        S.sel = id;
        scheduleRender();
        if (scrollTo) requestAnimationFrame(() => requestAnimationFrame(() => {
            const f = els.canvas.querySelector('.rp-frame[data-id="' + id + '"]');
            if (f) f.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }));
    }

    function renderProps() {
        const b = S.sel && byId(S.sel);
        const ro = !canEdit();
        const body = [];
        const field = (label, control, hint) => h('div', { class: 'rp-prop' }, h('label', { class: 'rp-prop-label' }, h('span', { text: label }), control), hint ? h('p', { class: 'rp-prop-hint', text: hint }) : null);
        const check = (label, value, onChange) => h('label', { class: 'rp-check' }, h('input', { type: 'checkbox', checked: value ? true : null, disabled: ro ? true : null, onchange: (e) => onChange(e.target.checked) }), h('span', { text: label }));
        const upd = (fn, opts) => commit(d => { const bb = d.blocks.find(x => x.id === S.sel); if (bb) fn(bb); }, opts);

        if (!b) {
            body.push(h('h2', { class: 'rp-props-title', text: T('designer.pack') }));
            body.push(field(T('list.description'), h('textarea', { class: 'rp-input', rows: 3, maxlength: 500, readonly: ro ? true : null, oninput: (e) => { S.desc = e.target.value; setDirty(true); } }, S.desc)));
            const d = S.design;
            body.push(h('h3', { class: 'rp-props-sub', text: T('designer.grp.pages') }));
            body.push(check(T('designer.cover'), d.cover.on, v => commit(x => { x.cover.on = v; })));
            body.push(check(T('designer.contents'), d.toc.on, v => commit(x => { x.toc.on = v; })));
            if (d.toc.on) body.push(field(T('designer.contents_title'), h('input', { class: 'rp-input', value: d.toc.title || '', maxlength: 120, readonly: ro ? true : null, oninput: (e) => commit(x => { x.toc.title = e.target.value; }, { merge: 'toc-title' }) })));
            body.push(check(T('designer.header'), d.header.on, v => commit(x => { x.header.on = v; })));
            if (d.header.on) body.push(check(T('designer.header_first'), d.header.first !== false, v => commit(x => { x.header.first = v; })));
            body.push(check(T('designer.header_rule'), !!d.header.rule, v => commit(x => { x.header.rule = v; })));
            body.push(check(T('designer.footer'), d.footer.on, v => commit(x => { x.footer.on = v; })));
            body.push(check(T('designer.footer_rule'), !!d.footer.rule, v => commit(x => { x.footer.rule = v; })));
            body.push(h('h3', { class: 'rp-props-sub', text: T('designer.margins') }));
            const m = d.page.margin;
            body.push(h('div', { class: 'rp-margin-grid' }, [['t', 'designer.m_top'], ['b', 'designer.m_bottom'], ['l', 'designer.m_left'], ['r', 'designer.m_right']].map(([k, key]) =>
                h('label', {}, h('span', { text: T(key) }), h('input', { type: 'number', class: 'rp-input', min: 5, max: 50, step: 1, value: m[k], readonly: ro ? true : null,
                    onchange: (e) => commit(x => { x.page.margin[k] = Math.max(5, Math.min(50, +e.target.value || m[k])); }) }), h('span', { text: 'mm' })))));
            body.push(h('p', { class: 'rp-prop-hint', text: T('designer.select_hint') }));
        } else {
            body.push(h('h2', { class: 'rp-props-title', text: frameLabel(b) }));
            if (b.type === 'data') {
                const hd = S.catalogue.handlers[b.handler] || {};
                body.push(h('p', { class: 'rp-props-source' }, T('designer.source', { module: hd.module_name || '' })));
                if (hd.available === false) body.push(h('p', { class: 'rp-prop-warn', text: T('err.no_module', { module: hd.module_name || '' }) }));
                body.push(field(T('designer.title'), h('input', { class: 'rp-input', value: b.title || '', maxlength: 200, readonly: ro ? true : null, oninput: (e) => upd(bb => { bb.title = e.target.value; }, { merge: 'title-' + b.id }) })));
                body.push(check(T('designer.show_title'), b.showTitle !== false, v => upd(bb => { bb.showTitle = v; })));
                Object.entries(hd.opts || {}).forEach(([k, def]) => body.push(optionControl(b, k, def, ro, upd)));
                const data = rt.dataMap()[b.id];
                if (data && data.data && data.data.kind === 'chart') {
                    body.push(field(T('designer.height'), h('input', { type: 'number', class: 'rp-input', min: 30, max: 250, value: Math.round(b.height || (b.span >= 12 ? 78 : 68)), readonly: ro ? true : null,
                        onchange: (e) => upd(bb => { bb.height = Math.max(30, Math.min(250, +e.target.value || 70)); }) }), T('designer.height_hint')));
                    if (b.opts && (b.opts.chart === 'doughnut' || b.opts.chart === 'pie')) {
                        body.push(field(T('designer.legend'), selectCtl([['auto', 'designer.legend_auto'], ['right', 'designer.legend_right'], ['bottom', 'designer.legend_bottom'], ['none', 'designer.legend_none']].map(([v, kk]) => ({ value: v, label: T(kk) })),
                            b.legend || 'auto', v => upd(bb => { bb.legend = v; }), ro)));
                    }
                }
            }
            if (b.type === 'heading') {
                body.push(field(T('designer.heading_text'), h('input', { class: 'rp-input', value: b.text || '', maxlength: 300, readonly: ro ? true : null, oninput: (e) => upd(bb => { bb.text = e.target.value; }, { merge: 'h-' + b.id }) })));
                body.push(field(T('designer.level'), selectCtl([1, 2, 3].map(n => ({ value: n, label: T('designer.style_h' + n) })), b.level, v => upd(bb => { bb.level = +v; }), ro)));
                body.push(check(T('designer.new_page'), !!b.newPage, v => upd(bb => { bb.newPage = v; })));
                body.push(check(T('designer.in_contents'), b.toc !== false, v => upd(bb => { bb.toc = v; })));
            }
            if (b.type === 'text') {
                body.push(h('button', { class: 'btn btn-secondary rp-btn-full', disabled: ro ? true : null, onclick: () => editBlock(b.id), text: T('designer.edit_text') }));
                body.push(check(T('designer.box'), !!b.box, v => upd(bb => { bb.box = v; })));
            }
            if (b.type === 'spacer') {
                body.push(field(T('designer.height'), h('input', { type: 'number', class: 'rp-input', min: 2, max: 120, value: b.height || 8, readonly: ro ? true : null, onchange: (e) => upd(bb => { bb.height = Math.max(2, Math.min(120, +e.target.value || 8)); }) })));
            }
            if (b.type === 'data' || b.type === 'text' || b.type === 'spacer') {
                body.push(h('h3', { class: 'rp-props-sub', text: T('designer.width') }));
                body.push(h('div', { class: 'rp-widths rp-widths-props' }, WIDTHS.map(([span, key]) => h('button', {
                    class: 'rp-width-btn' + (b.span === span ? ' is-active' : ''), disabled: ro ? true : null, title: T(key), onclick: () => setSpan(b.id, span),
                }, widthGlyph(span), h('span', { text: T(key) })))));
                body.push(field(T('designer.columns'), h('input', { type: 'number', class: 'rp-input', min: 1, max: 12, value: b.span, readonly: ro ? true : null, onchange: (e) => setSpan(b.id, +e.target.value) }), T('designer.columns_hint')));
                body.push(check(T('designer.new_row'), !!b.newRow, v => upd(bb => { bb.newRow = v; })));
            }
            if (!ro) {
                body.push(h('div', { class: 'rp-prop-actions' },
                    h('button', { class: 'btn btn-secondary', onclick: duplicateBlock, text: T('designer.duplicate') }),
                    h('button', { class: 'btn rp-btn-danger', onclick: () => deleteBlock(b.id), text: T('list.delete') })));
            }
        }
        els.props.replaceChildren(h('div', { class: 'rp-pane-head' }, h('h2', { text: T('designer.properties') })), h('div', { class: 'rp-props-body' }, body));
    }

    function selectCtl(options, value, onChange, ro) {
        const s = h('select', { class: 'rp-input', disabled: ro ? true : null, onchange: () => onChange(s.value) });
        options.forEach(o => { const op = h('option', { value: o.value, text: o.label }); if (String(o.value) === String(value)) op.selected = true; s.append(op); });
        return s;
    }

    function optionControl(b, k, def, ro, upd) {
        const v = (b.opts || {})[k] !== undefined ? b.opts[k] : def.default;
        const set = (val) => upd(bb => { bb.opts = Object.assign({}, bb.opts, { [k]: val }); }, { data: true });
        let ctl;
        if (def.type === 'select') ctl = selectCtl(def.values, v, set, ro);
        else if (def.type === 'int') ctl = h('input', { type: 'number', class: 'rp-input', min: def.min, max: def.max, value: v, readonly: ro ? true : null, onchange: (e) => set(Math.max(def.min, Math.min(def.max, +e.target.value || def.default))) });
        else if (def.type === 'bool') {
            return h('label', { class: 'rp-check' }, h('input', { type: 'checkbox', checked: v ? true : null, disabled: ro ? true : null, onchange: (e) => set(e.target.checked) }), h('span', { text: def.label }));
        } else if (def.type === 'multi') {
            const chosen = new Set(v || []);
            const list = h('div', { class: 'rp-multi' },
                h('p', { class: 'rp-prop-hint', text: chosen.size ? T('designer.chosen_n', { n: chosen.size }) : T('designer.all_items') }),
                (def.choices || []).map(c => h('label', { class: 'rp-check' }, h('input', { type: 'checkbox', checked: chosen.has(c.id) ? true : null, disabled: ro ? true : null,
                    onchange: (e) => { e.target.checked ? chosen.add(c.id) : chosen.delete(c.id); set(Array.from(chosen)); } }), h('span', { text: c.name }))));
            return h('div', { class: 'rp-prop' }, h('span', { class: 'rp-prop-label', text: def.label }), list);
        }
        return h('div', { class: 'rp-prop' }, h('label', { class: 'rp-prop-label' }, h('span', { text: def.label }), ctl));
    }

    // ── Block operations ─────────────────────────────────────────────────
    function setSpan(id, span) {
        span = Math.max(1, Math.min(12, Math.round(span)));
        commit(d => { const b = d.blocks.find(x => x.id === id); if (b && (b.type === 'data' || b.type === 'text' || b.type === 'spacer')) b.span = span; });
    }
    function moveBlock(id, dir) {
        if (!id) return;
        commit(d => {
            const i = d.blocks.findIndex(b => b.id === id), j = i + dir;
            if (i < 0 || j < 0 || j >= d.blocks.length) return;
            const [b] = d.blocks.splice(i, 1);
            d.blocks.splice(j, 0, b);
        });
        select(id, true);
    }
    function deleteBlock(id) {
        if (!id) return;
        const i = idx(id);
        commit(d => { d.blocks = d.blocks.filter(b => b.id !== id); });
        const next = S.design.blocks[Math.min(i, S.design.blocks.length - 1)];
        S.sel = next ? next.id : null;
        scheduleRender();
        announce(T('designer.deleted'));
    }

    // Clipboard: inside the designer, and across packs through localStorage when allowed.
    let memClip = null;
    function clip() {
        if (memClip) return memClip;
        try { const c = JSON.parse(localStorage.getItem('rp.clip') || 'null'); return c && c.type ? c : null; } catch (e) { return null; }
    }
    function copyBlock() {
        const b = S.sel && byId(S.sel);
        if (!b) return;
        memClip = clone(b);
        try { localStorage.setItem('rp.clip', JSON.stringify(memClip)); } catch (e) { /* fine */ }
        refreshRibbonState();
        announce(T('designer.copied'));
    }
    function cutBlock() { if (!S.sel) return; copyBlock(); deleteBlock(S.sel); }
    function pasteBlock() {
        const c = clip();
        if (!c || !canEdit()) return;
        const b = clone(c); b.id = uid();
        commit(d => { const i = S.sel ? idx(S.sel) + 1 : d.blocks.length; d.blocks.splice(i, 0, b); }, { data: b.type === 'data' });
        select(b.id, true);
    }
    function duplicateBlock() {
        const b = S.sel && byId(S.sel);
        if (!b) return;
        const c = clone(b); c.id = uid();
        commit(d => { d.blocks.splice(idx(b.id) + 1, 0, c); });
        select(c.id, true);
    }

    let liveRegion = null;
    function announce(msg) {
        if (!liveRegion) { liveRegion = h('div', { class: 'rp-sr', 'aria-live': 'polite' }); document.body.append(liveRegion); }
        liveRegion.textContent = msg;
    }

    // ── Canvas interaction: select, drag, resize, edit ───────────────────
    function bindCanvas() {
        els.canvas.addEventListener('pointerdown', (e) => {
            if (S.editing) return;
            const handle = e.target.closest('.rp-handle');
            const fr = e.target.closest('.rp-frame');
            if (handle && fr) { startResize(e, fr.dataset.id, handle.dataset.handle); return; }
            if (fr) {
                const id = fr.dataset.id;
                if (S.sel !== id) select(id);
                if (canEdit() && e.button === 0) startDrag(e, { move: id });
                return;
            }
            if (!e.target.closest('.rp-region') && e.button === 0) { S.sel = null; scheduleRender(); }
        });
        els.canvas.addEventListener('dblclick', (e) => {
            const fr = e.target.closest('.rp-frame');
            if (fr && canEdit()) editBlock(fr.dataset.id);
        });
        els.canvas.addEventListener('contextmenu', (e) => {
            const fr = e.target.closest('.rp-frame');
            if (!fr || !canEdit()) return;
            e.preventDefault();
            select(fr.dataset.id);
            contextMenu(e.clientX, e.clientY, fr.dataset.id);
        });
        els.canvas.addEventListener('keydown', (e) => {
            const fr = e.target.closest && e.target.closest('.rp-frame');
            if (fr && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); select(fr.dataset.id); }
        });
        els.canvas.addEventListener('scroll', () => { els.ruler.scrollLeft = els.canvas.scrollLeft; });
        els.canvas.addEventListener('wheel', (e) => {
            if (e.ctrlKey) { e.preventDefault(); setZoom(S.zoom + (e.deltaY < 0 ? 0.1 : -0.1)); }
        }, { passive: false });
    }

    /** Where a drop at (clientX, clientY) would land: {index, side, indicator} */
    function dropTarget(cx, cy, movingId) {
        const pages = Array.from(els.canvas.querySelectorAll('.rp-page'));
        const k = pxmm();
        let page = null, pr = null;
        for (const p of pages) { const r = p.getBoundingClientRect(); if (cy >= r.top - 20 && cy <= r.bottom + 20) { page = p; pr = r; break; } }
        if (!page) {
            // Above the first page or below the last.
            const last = pages[pages.length - 1];
            if (!last) return null;
            return { index: S.design.blocks.length, side: null, page: last, y: null };
        }
        const pi = +page.dataset.page;
        const pg = S.layout.pages[pi];
        const mx = (cx - pr.left) / k, my = (cy - pr.top) / k;
        const items = pg.items.filter(it => it.id !== movingId && it.type !== 'pagebreak');
        if (pg.type !== 'body') return { index: pi === 0 ? 0 : 0, side: null, page, y: S.layout.geometry.m.t + S.layout.headerH, x: S.layout.geometry.m.l, w: S.layout.geometry.cw };
        const sideable = (it) => it.block.type === 'data' || it.block.type === 'text' || it.block.type === 'spacer';
        for (const it of items) {
            if (mx >= it.x && mx <= it.x + it.w && my >= it.y && my <= it.y + it.h) {
                const rel = (mx - it.x) / it.w;
                if (sideable(it) && (rel > 0.72 || rel < 0.28)) {
                    const after = rel > 0.5;
                    return { index: idx(it.id) + (after ? 1 : 0), side: after ? 'after' : 'before', target: it.id, page, x: after ? it.x + it.w + 1.5 : it.x - 3.5, y: it.y, h: it.h, vertical: true };
                }
                const below = my > it.y + it.h / 2;
                // Below = after every block in this row; above = before the row's first block.
                const rowItems = items.filter(o => Math.abs(o.y - it.y) < 0.5);
                const ids = rowItems.map(o => idx(o.id));
                const i = below ? Math.max(...ids) + 1 : Math.min(...ids);
                return { index: i, side: null, newRow: true, page, y: below ? it.y + it.h + 2 : it.y - 2.5, x: S.layout.geometry.m.l, w: S.layout.geometry.cw };
            }
        }
        // Between rows, or after the last block on the page.
        const nextOnPage = items.filter(it => it.y > my).sort((a, b) => a.y - b.y)[0];
        if (nextOnPage) {
            const rowItems = items.filter(o => Math.abs(o.y - nextOnPage.y) < 0.5);
            return { index: Math.min(...rowItems.map(o => idx(o.id))), side: null, newRow: true, page, y: nextOnPage.y - 2.5, x: S.layout.geometry.m.l, w: S.layout.geometry.cw };
        }
        const prev = items.filter(it => it.y <= my).sort((a, b) => (b.y + b.h) - (a.y + a.h))[0];
        if (prev) return { index: idx(prev.id) + 1, side: null, newRow: true, page, y: prev.y + prev.h + 2, x: S.layout.geometry.m.l, w: S.layout.geometry.cw };
        // An empty page: before whatever comes next in the design.
        const later = S.layout.pages.slice(pi + 1).flatMap(p => p.items).find(it => it.id !== movingId);
        return { index: later ? idx(later.id) : S.design.blocks.length, side: null, page, y: S.layout.geometry.m.t + S.layout.headerH + 2, x: S.layout.geometry.m.l, w: S.layout.geometry.cw };
    }

    function startDrag(e, what) {
        if (e.button !== 0 || !canEdit()) return;
        const sx = e.clientX, sy = e.clientY;
        let started = false, ghost = null, guide = null, target = null;
        const label = what.tool ? what.tool.title : frameLabel(byId(what.move));
        const move = (ev) => {
            if (!started) {
                if (Math.hypot(ev.clientX - sx, ev.clientY - sy) < 5) return;
                started = true;
                S.drag = what;
                hidePreview();
                ghost = h('div', { class: 'rp-ghost' }, icon(what.tool ? TOOL_ICON(what.tool) : 'grip', 16), h('span', { text: label }));
                guide = h('div', { class: 'rp-drop-guide' });
                document.body.append(ghost);
                document.body.classList.add('rp-dragging');
                if (what.move) els.canvas.querySelector('.rp-frame[data-id="' + what.move + '"]')?.classList.add('is-moving');
            }
            ghost.style.left = (ev.clientX + 12) + 'px';
            ghost.style.top = (ev.clientY + 8) + 'px';
            autoScroll(ev.clientY);
            target = dropTarget(ev.clientX, ev.clientY, what.move);
            showGuide(guide, target);
        };
        const up = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            stopAutoScroll();
            if (!started) return;
            ghost.remove(); guide.remove();
            document.body.classList.remove('rp-dragging');
            S.drag = null;
            if (target) applyDrop(what, target);
            else scheduleRender();
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
    }

    function showGuide(guide, t) {
        if (!t || !t.page || t.y === null) { guide.remove(); return; }
        const k = pxmm();
        const ov = t.page.querySelector('.rp-overlay');
        if (guide.parentNode !== ov) ov.append(guide);
        guide.className = 'rp-drop-guide' + (t.vertical ? ' is-vertical' : '');
        if (t.vertical) Object.assign(guide.style, { left: (t.x * k) + 'px', top: (t.y * k) + 'px', width: '', height: (t.h * k) + 'px' });
        else Object.assign(guide.style, { left: (t.x * k) + 'px', top: (t.y * k) + 'px', width: (t.w * k) + 'px', height: '' });
    }

    let scrollTimer = null, scrollDir = 0;
    function autoScroll(cy) {
        const r = els.canvas.getBoundingClientRect();
        scrollDir = cy < r.top + 50 ? -1 : cy > r.bottom - 50 ? 1 : 0;
        if (scrollDir && !scrollTimer) scrollTimer = setInterval(() => { els.canvas.scrollTop += scrollDir * 18; }, 16);
        if (!scrollDir) stopAutoScroll();
    }
    function stopAutoScroll() { clearInterval(scrollTimer); scrollTimer = null; }

    /** Put the dragged block where the guide was. Side drops share the row. */
    function applyDrop(what, t) {
        let id;
        commit(d => {
            let b, from = -1;
            if (what.move) {
                from = d.blocks.findIndex(x => x.id === what.move);
                b = d.blocks[from];
                d.blocks.splice(from, 1);
            } else {
                b = blockFromTool(what.tool.key);
            }
            id = b.id;
            let at = t.index;
            if (from >= 0 && from < at) at--;
            at = Math.max(0, Math.min(d.blocks.length, at));
            b.newRow = !!t.newRow && (b.type === 'data' || b.type === 'text' || b.type === 'spacer');
            if (t.side) {
                // Share the row with the target: make room if there is none.
                const target = d.blocks.find(x => x.id === t.target);
                const row = rowOf(d.blocks, t.target);
                const used = row.reduce((s, x) => s + E.blockSpan(x), 0);
                const sideable = b.type === 'data' || b.type === 'text' || b.type === 'spacer';
                if (sideable) {
                    if (used + b.span > 12) {
                        const free = 12 - used;
                        if (free >= 3) b.span = free;
                        else { const half = Math.max(3, Math.floor(target.span / 2)); b.span = Math.max(3, target.span - half); target.span = half; }
                    }
                    if (t.side === 'before') { b.newRow = !!target.newRow; target.newRow = false; }
                    else b.newRow = false;
                }
            }
            d.blocks.splice(at, 0, b);
        }, { data: !what.move });
        select(id);
    }

    /** The blocks sharing a row with `id`, as the engine flows them. */
    function rowOf(blocks, id) {
        let row = [], used = 0, found = null;
        blocks.forEach(b => {
            const span = E.blockSpan(b);
            const solo = b.type !== 'data' && b.type !== 'text' && !(b.type === 'spacer' && span < 12);
            if (!row.length || solo || used + span > 12 || b.newRow || (row[0] && (row[0].type === 'heading' || row[0].type === 'pagebreak' || row[0].type === 'divider'))) {
                if (found) return;
                row = []; used = 0;
            }
            row.push(b); used += span;
            if (b.id === id) found = row;
        });
        return found || [];
    }

    function startResize(e, id, edge) {
        e.preventDefault(); e.stopPropagation();
        const b = byId(id);
        const item = S.layout.pages.flatMap(p => p.items).find(it => it.id === id && !it.continued);
        if (!b || !item) return;
        const k = pxmm(), G = S.layout.geometry;
        const pitch = S.layout.colW + S.layout.gutter;
        const startX = e.clientX, startY = e.clientY;
        const frameEl = e.target.closest('.rp-frame');
        const startSpan = b.span, startH = b.type === 'spacer' ? (b.height || 8) : (item.chart ? item.chart.h : 70);
        const cols = h('div', { class: 'rp-col-guides' });
        for (let i = 0; i < 12; i++) cols.append(h('i', { style: { left: ((G.m.l + i * pitch) * k) + 'px', width: (S.layout.colW * k) + 'px' } }));
        frameEl.closest('.rp-overlay').append(cols);
        const tip = h('div', { class: 'rp-size-tip' });
        document.body.append(tip);
        let span = startSpan, hgt = startH;
        const move = (ev) => {
            if (edge === 's') {
                hgt = Math.round(Math.max(b.type === 'spacer' ? 2 : 30, Math.min(b.type === 'spacer' ? 120 : 250, startH + (ev.clientY - startY) / k)));
                frameEl.style.height = ((item.h - startH + hgt) * k) + 'px';
                tip.textContent = hgt + ' mm';
            } else {
                const dmm = (ev.clientX - startX) / k * (edge === 'w' ? -1 : 1);
                span = Math.max(1, Math.min(12, Math.round((item.w + dmm + S.layout.gutter) / pitch)));
                const w = span * S.layout.colW + (span - 1) * S.layout.gutter;
                frameEl.style.width = (w * k) + 'px';
                if (edge === 'w') frameEl.style.left = ((item.x + item.w - w) * k) + 'px';
                tip.textContent = T('designer.cols_of_12', { n: span });
            }
            tip.style.left = (ev.clientX + 14) + 'px'; tip.style.top = (ev.clientY + 14) + 'px';
        };
        const up = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            cols.remove(); tip.remove();
            if (edge === 's') commit(d => { const x = d.blocks.find(q => q.id === id); if (x) x.height = hgt; });
            else if (span !== startSpan) setSpan(id, span);
            else scheduleRender();
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
    }

    // ── Editing text in place ────────────────────────────────────────────
    function editBlock(id) {
        const b = byId(id);
        if (!b || !canEdit()) return;
        if (b.type === 'heading') { editHeading(b); return; }
        if (b.type !== 'text') { select(id); return; }
        const item = S.layout.pages.flatMap(p => p.items).find(it => it.id === id && !it.continued);
        const pageEl = item && els.canvas.querySelector('.rp-frame[data-id="' + id + '"]')?.closest('.rp-page');
        if (!item || !pageEl) return;
        openEditor({ kind: 'block', id }, pageEl, item.x, item.y, item.w, item.h, b.doc,
            (doc) => commit(d => { const x = d.blocks.find(q => q.id === id); if (x) x.doc = doc; }), T('tool.text.title'));
    }

    function editHeading(b) {
        const fr = els.canvas.querySelector('.rp-frame[data-id="' + b.id + '"]');
        if (!fr) return;
        const input = h('input', { class: 'rp-heading-input', value: b.text || '', maxlength: 300, 'aria-label': T('designer.heading_text') });
        const size = { 1: 15, 2: 12.5, 3: 11 }[b.level] || 15;
        const k = fr.closest('.rp-page').offsetWidth / S.layout.geometry.pw;   // the page as drawn
        Object.assign(input.style, { fontSize: (size * 25.4 / 72 * k) + 'px', fontFamily: E.FONT_CSS[S.design.theme.font], color: S.design.theme.heading });
        fr.append(input);
        input.focus(); input.select();
        S.editing = { kind: 'heading', id: b.id };
        const done = (keep) => {
            if (!S.editing) return;
            S.editing = null;
            const v = input.value;
            input.remove();
            if (keep) commit(d => { const x = d.blocks.find(q => q.id === b.id); if (x) x.text = v; });
            else scheduleRender();
        };
        input.addEventListener('keydown', (e) => { e.stopPropagation(); if (e.key === 'Enter') done(true); if (e.key === 'Escape') done(false); });
        input.addEventListener('blur', () => done(true));
    }

    function editRegion(kind) {
        if (!canEdit()) return;
        // Turning on a header/footer/cover that is off is part of editing it.
        if (!S.design[kind].on) { commit(d => { d[kind].on = true; }); }
        setTimeout(() => {
            S.layout = rt.layout();
            const G = S.layout.geometry;
            let pi = S.layout.pages.findIndex(p => kind === 'cover' ? p.type === 'cover' : p[kind]);
            if (pi < 0) return;
            const page = S.layout.pages[pi];
            const rich = page[kind + 'Rich'];
            const pageEl = els.canvas.querySelector('.rp-page[data-page="' + pi + '"]');
            if (!pageEl || !rich) return;
            pageEl.scrollIntoView({ block: kind === 'footer' ? 'end' : 'start' });
            const height = Math.max(rich.L.height, 12);
            openEditor({ kind }, pageEl, rich.x, rich.y, G.cw, height, S.design[kind].doc,
                (doc) => commit(d => { d[kind].doc = doc; }), T('designer.' + kind));
        }, 40);
    }

    function openEditor(what, pageEl, x, y, w, hgt, doc, onSave, label) {
        // The scale of the page AS DRAWN, not the zoom setting: if a zoom change
        // has not been painted yet, the editor must still sit exactly on the text.
        const k = pageEl.offsetWidth / S.layout.geometry.pw;
        const zoom = k / PX_PER_MM;
        S.editing = what;
        pageEl.classList.add('is-editing');
        buildRibbon();
        if (S.tab !== 'home' && S.tab !== 'insert') { S.tab = 'home'; buildRibbon(); }
        RPEditor.open({
            host: pageEl.querySelector('.rp-overlay'), left: x * k, top: y * k, width: w * k, minHeight: Math.max(8, hgt) * k,
            doc, theme: S.design.theme, zoom, logoUrl: rt.logo && rt.logo.url, label,
            onInput: () => setDirty(true),
            onDone: (newDoc) => {
                S.editing = null;
                pageEl.classList.remove('is-editing');
                onSave(newDoc);
                buildRibbon();
                scheduleRender();
            },
        });
        refreshRibbonState();
        // Clicking anywhere outside the editor (but not on the ribbon) finishes editing.
        setTimeout(() => document.addEventListener('pointerdown', outside, true), 0);
        function outside(e) {
            const ed = RPEditor.element();
            if (!ed) { document.removeEventListener('pointerdown', outside, true); return; }
            if (ed.contains(e.target) || e.target.closest('.rp-ribbon') || e.target.closest('.rp-titlebar')) return;
            document.removeEventListener('pointerdown', outside, true);
            RPEditor.close(true);
        }
    }

    // ── Context menu ─────────────────────────────────────────────────────
    let menuEl = null;
    function contextMenu(x, y, id) {
        closeMenu();
        const b = byId(id);
        const item = (label, fn, opts) => h('button', { class: 'rp-menu-item', role: 'menuitem', disabled: opts && opts.disabled ? true : null, onclick: () => { closeMenu(); fn(); } },
            h('span', { text: label }), opts && opts.key ? h('kbd', { text: opts.key }) : null);
        const sizable = b.type === 'data' || b.type === 'text' || b.type === 'spacer';
        menuEl = h('div', { class: 'rp-menu', role: 'menu' },
            (b.type === 'text' || b.type === 'heading') ? item(T('designer.edit_text'), () => editBlock(id), { key: 'Enter' }) : null,
            item(T('designer.cut'), cutBlock, { key: 'Ctrl+X' }),
            item(T('designer.copy'), copyBlock, { key: 'Ctrl+C' }),
            item(T('designer.paste'), pasteBlock, { key: 'Ctrl+V', disabled: !clip() }),
            item(T('designer.duplicate'), duplicateBlock, { key: 'Ctrl+D' }),
            h('div', { class: 'rp-menu-sep' }),
            item(T('designer.move_up'), () => moveBlock(id, -1), { key: 'Alt+↑' }),
            item(T('designer.move_down'), () => moveBlock(id, 1), { key: 'Alt+↓' }),
            sizable ? h('div', { class: 'rp-menu-sep' }) : null,
            sizable ? WIDTHS.map(([span, key]) => item(T(key), () => setSpan(id, span))) : null,
            sizable ? item(b.newRow ? T('designer.join_row') : T('designer.new_row'), () => commit(d => { const q = d.blocks.find(z => z.id === id); if (q) q.newRow = !q.newRow; })) : null,
            h('div', { class: 'rp-menu-sep' }),
            item(T('list.delete'), () => deleteBlock(id), { key: 'Del' }));
        document.body.append(menuEl);
        const r = menuEl.getBoundingClientRect();
        menuEl.style.left = Math.min(x, window.innerWidth - r.width - 8) + 'px';
        menuEl.style.top = Math.min(y, window.innerHeight - r.height - 8) + 'px';
        menuEl.querySelector('.rp-menu-item:not([disabled])')?.focus();
        setTimeout(() => document.addEventListener('pointerdown', menuOutside, true), 0);
    }
    function menuOutside(e) { if (menuEl && !menuEl.contains(e.target)) closeMenu(); }
    function closeMenu() { if (menuEl) { menuEl.remove(); menuEl = null; } document.removeEventListener('pointerdown', menuOutside, true); }

    // ── Keyboard ─────────────────────────────────────────────────────────
    function bindKeys() {
        document.addEventListener('keydown', (e) => {
            const mod = e.ctrlKey || e.metaKey;
            const inField = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable;
            if (menuEl && e.key === 'Escape') { closeMenu(); return; }
            if (menuEl && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                e.preventDefault();
                const items = Array.from(menuEl.querySelectorAll('.rp-menu-item:not([disabled])'));
                const i = items.indexOf(document.activeElement);
                items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus();
                return;
            }
            if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); if (canEdit()) save(); return; }
            if (mod && e.key.toLowerCase() === 'p') { e.preventDefault(); exportPdf(); return; }
            if (inField || !canEdit()) return;
            if (mod && e.key.toLowerCase() === 'z' && !e.shiftKey) { e.preventDefault(); undo(); return; }
            if (mod && (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey))) { e.preventDefault(); redo(); return; }
            if (mod && e.key.toLowerCase() === 'c') { if (S.sel) { e.preventDefault(); copyBlock(); } return; }
            if (mod && e.key.toLowerCase() === 'x') { if (S.sel) { e.preventDefault(); cutBlock(); } return; }
            if (mod && e.key.toLowerCase() === 'v') { e.preventDefault(); pasteBlock(); return; }
            if (mod && e.key.toLowerCase() === 'd') { if (S.sel) { e.preventDefault(); duplicateBlock(); } return; }
            if (!S.sel) return;
            if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); deleteBlock(S.sel); return; }
            if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) { e.preventDefault(); moveBlock(S.sel, e.key === 'ArrowUp' ? -1 : 1); return; }
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                e.preventDefault();
                const i = idx(S.sel) + (e.key === 'ArrowUp' ? -1 : 1);
                if (S.design.blocks[i]) select(S.design.blocks[i].id, true);
                return;
            }
            if (e.key === 'Enter') { e.preventDefault(); editBlock(S.sel); return; }
            if (e.key === 'Escape') { S.sel = null; scheduleRender(); }
        });
    }

    // ── Save, copy, export ───────────────────────────────────────────────
    async function save(force) {
        if (!canEdit() || S.saving) return;
        if (RPEditor.isActive()) RPEditor.close(true);
        S.saving = true; setDirty(S.dirty);
        try {
            const r = await api('save.php', { id: packId, name: S.name.trim() || S.pack.name, description: S.desc, design: S.design, updated: S.updated, force: force === true });
            S.updated = r.updated;
            // Adopt what the server kept (it tidies as it checks), so the screen and
            // the stored pack can never drift apart.
            if (r.design && JSON.stringify(r.design) !== JSON.stringify(S.design)) {
                S.design = r.design; rt.design = S.design;
                if (S.sel && !byId(S.sel)) S.sel = null;
                scheduleRender();
            }
            S.saving = false;
            setDirty(false);
            announce(T('designer.saved'));
        } catch (e) {
            S.saving = false; setDirty(true);
            if (e.data && e.data.conflict) conflictDialog(e.message);
            else toast(e.message, true);
        }
    }

    function conflictDialog(msg) {
        const m = h('div', { class: 'modal active', role: 'alertdialog', 'aria-modal': 'true' },
            h('div', { class: 'modal-content rp-modal-sm' },
                h('div', { class: 'modal-header', text: T('designer.conflict_title') }),
                h('div', { class: 'modal-body' }, h('p', { text: msg }), h('p', { class: 'rp-hint', text: T('designer.conflict_body') })),
                h('div', { class: 'modal-footer' },
                    h('button', { class: 'btn btn-secondary', text: T('designer.conflict_reload'), onclick: () => { S.dirty = false; location.reload(); } }),
                    h('button', { class: 'btn btn-primary', text: T('designer.conflict_mine'), onclick: () => { m.remove(); save(true); } }))));
        document.body.append(m);
        m.querySelector('.btn-primary').focus();
    }

    async function copyPack() {
        try {
            const r = await api('create.php', { name: T('list.copy_name', { name: S.name }), description: S.desc, copy_of: packId });
            location.href = 'designer.php?id=' + r.id;
        } catch (e) { toast(e.message, true); }
    }

    async function exportPdf() {
        if (RPEditor.isActive()) RPEditor.close(true);
        const over = h('div', { class: 'rp-busy', role: 'status' }, h('div', { class: 'rp-busy-box' }, h('div', { class: 'rp-spinner' }), h('span', { text: T('designer.exporting') })));
        document.body.append(over);
        try {
            rt.name = S.name;
            await rt.exportPdf();
            if (rt.unsupported) toast(T('designer.unsupported_long'), true);
        } catch (e) {
            toast(T('designer.export_failed') + ' ' + (e.message || ''), true);
        } finally { over.remove(); }
    }

    let toastTimer = null;
    function toast(msg, warn) {
        let t = document.querySelector('.rp-toast');
        if (!t) { t = h('div', { class: 'rp-toast', role: 'status' }); document.body.append(t); }
        t.textContent = msg;
        t.classList.toggle('is-warn', !!warn);
        t.classList.add('is-on');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => t.classList.remove('is-on'), 5000);
    }

    // Exposed for tests and for the harness that drives the designer headless.
    window.RPDesigner = { S, rt, commit, undo, redo, select, insertTool, applyDrop, dropTarget, setSpan, moveBlock, deleteBlock, editBlock, editRegion, save, render, setZoom, rowOf };

    boot();
})();
