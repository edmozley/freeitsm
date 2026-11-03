/**
 * Assets - the label designer (GH #229, slice 2).
 *
 * An Office-style frame like the Report Pack designer's: title bar, ribbon, a
 * toolbox to drag things from, the label in the middle with rulers in millimetres,
 * the properties of what is selected on the right, a status bar. Unlike a report,
 * a label is small and every item has an exact place, so items are placed freely,
 * PowerPoint-style: drag, resize with handles, snap to the label's edges and centre
 * and to other items (with the pink guides), nudge with the arrow keys.
 *
 * The label is drawn with the same code that prints it (render.js), with a real
 * asset's values, so what you see is what prints. Saving sends the items to
 * api/assets/label_designs.php (save_items), which cleans every one; a template
 * design becomes a designed one ('custom') when first saved here.
 *
 * State has one way to change: commit(). It takes an undo snapshot first, so every
 * change can be undone; quick repeats of the same change (typing, nudging, one drag)
 * merge into one step.
 *
 * @wiki Asset-Label-Designer-Design
 */
(function () {
    'use strict';
    const B = window.LD;
    const T = (k, p) => window.t('asset-management.label_designer.' + k, p);
    const DPR = () => window.devicePixelRatio || 1;
    const MM_100 = 96 / 25.4;              // pixels per millimetre at 100%

    // ---------------------------------------------------------------- helpers
    /** Builds an element: h('div', {class: 'x', onclick: f}, child, 'text', ...). */
    function h(tag, attrs, ...kids) {
        const el = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => {
            if (v === null || v === undefined || v === false) return;
            if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
            else if (k === 'html') el.innerHTML = v;
            else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
            else el.setAttribute(k, v === true ? '' : v);
        });
        kids.flat().forEach(c => { if (c !== null && c !== undefined && c !== false) el.append(c.nodeType ? c : document.createTextNode(String(c))); });
        return el;
    }
    const svg = d => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + d + '</svg>';
    const ICON = {
        undo: svg('<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-4"/>'),
        redo: svg('<path d="m15 14 5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h4"/>'),
        cut: svg('<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.1 15.9M14.5 14.5 20 20M8.1 8.1 12 12"/>'),
        copy: svg('<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>'),
        paste: svg('<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>'),
        dup: svg('<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M4 16V5a1 1 0 0 1 1-1h11"/>'),
        del: svg('<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>'),
        bold: svg('<path d="M6 4h8a4 4 0 0 1 0 8H6zM6 12h9a4 4 0 0 1 0 8H6z"/>'),
        italic: svg('<path d="M19 4h-9M14 20H5M15 4 9 20"/>'),
        bigger: svg('<path d="M4 20 9 6l5 14M6 15h6M18 10V4M15 7h6"/>'),
        smaller: svg('<path d="M4 20 9 6l5 14M6 15h6M15 7h6"/>'),
        al: svg('<path d="M3 6h18M3 12h12M3 18h16"/>'), ac: svg('<path d="M3 6h18M6 12h12M4 18h16"/>'), ar: svg('<path d="M3 6h18M9 12h12M5 18h16"/>'),
        vt: svg('<path d="M4 4h16M12 8v12M8 12l4-4 4 4"/>'), vm: svg('<path d="M4 12h16M12 4v4M12 16v4"/>'), vb: svg('<path d="M4 20h16M12 4v12M8 12l4 4 4-4"/>'),
        text: svg('<path d="M4 7V4h16v3M9 20h6M12 4v16"/>'),
        field: svg('<rect x="3" y="7" width="18" height="10" rx="2"/><path d="M7 12h6"/>'),
        fields: svg('<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>'),
        qr: svg('<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/>'),
        image: svg('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>'),
        line: svg('<path d="M4 20 20 4"/>'),
        box: svg('<rect x="4" y="5" width="16" height="14" rx="1"/>'),
        aL: svg('<path d="M4 3v18M8 7h12v4H8zM8 14h7v4H8z"/>'), aC: svg('<path d="M12 3v18M6 7h12v4H6zM8 14h8v4H8z"/>'), aR: svg('<path d="M20 3v18M4 7h12v4H4zM9 14h7v4H9z"/>'),
        aT: svg('<path d="M3 4h18M7 8h4v12H7zM14 8h4v7h-4z"/>'), aM: svg('<path d="M3 12h18M7 6h4v12H7zM14 8h4v8h-4z"/>'), aB: svg('<path d="M3 20h18M7 4h4v12H7zM14 9h4v7h-4z"/>'),
        dH: svg('<path d="M4 4v16M20 4v16M10 8h4v8h-4z"/>'), dV: svg('<path d="M4 4h16M4 20h16M8 10h8v4H8z"/>'),
        fwd: svg('<rect x="3" y="3" width="11" height="11"/><rect x="10" y="10" width="11" height="11" fill="currentColor" opacity=".3"/>'),
        back: svg('<rect x="10" y="10" width="11" height="11"/><rect x="3" y="3" width="11" height="11" fill="currentColor" opacity=".3"/>'),
        rot: svg('<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>'),
        grid: svg('<path d="M3 9h18M3 15h18M9 3v18M15 3v18"/>'), snap: svg('<path d="M6 3v7a6 6 0 0 0 12 0V3M6 7h4M14 7h4"/>'),
        zin: svg('<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3M11 8v6M8 11h6"/>'), zout: svg('<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3M8 11h6"/>'),
        fit: svg('<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>'),
        save: svg('<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>'),
        backArrow: svg('<path d="m15 18-6-6 6-6"/>'),
    };

    // ---------------------------------------------------------------- state
    const doc = { name: B.design.name, stock: Object.assign({}, B.design.stock), font: B.design.font, items: JSON.parse(JSON.stringify(B.design.items || [])) };
    let version = B.design.version, template = B.design.template;
    let sel = [];                               // selected item ids; the last is the primary
    let zoom = 6;                               // pixels per millimetre (before the screen's own ratio)
    let grid = true, snapOn = true, dirty = false;
    let sampleIdx = Math.min(1, B.samples.length - 1);
    const past = [], future = [];
    let lastMerge = null, lastMergeAt = 0;
    let logo = null;
    let guides = [], marquee = null, hoverId = null, cursorMm = null;
    const E = {};                               // the elements the redraw reaches into

    const byId = id => doc.items.find(i => i.id === id);
    const selected = () => sel.map(byId).filter(Boolean);
    const primary = () => byId(sel[sel.length - 1]);
    const W = () => +doc.stock.w, H = () => +doc.stock.h;
    const r1 = v => Math.round(v * 10) / 10;
    const newId = () => 'i' + Math.random().toString(36).slice(2, 9);
    const isText = it => it && (it.type === 'text' || it.type === 'field' || it.type === 'fields');

    /**
     * The one way to change the design: snapshot for undo, apply, redraw. A change
     * with the same merge key within 800 ms joins the previous undo step.
     */
    function commit(fn, mergeKey) {
        const now = Date.now();
        if (!(mergeKey && mergeKey === lastMerge && now - lastMergeAt < 800)) {
            past.push(JSON.stringify(doc));
            if (past.length > 150) past.shift();
        }
        lastMerge = mergeKey || null; lastMergeAt = now;
        future.length = 0;
        fn();
        setDirty(true);
        schedule();
    }
    function restore(json) {
        const s = JSON.parse(json);
        Object.assign(doc, s);
        sel = sel.filter(id => byId(id));
        setDirty(true);
        schedule(true);
    }
    function undo() { if (!past.length) return; future.push(JSON.stringify(doc)); restore(past.pop()); lastMerge = null; }
    function redo() { if (!future.length) return; past.push(JSON.stringify(doc)); restore(future.pop()); lastMerge = null; }
    function setDirty(d) {
        dirty = d;
        if (E.state) { E.state.textContent = d ? T('unsaved') : T('saved'); E.state.classList.toggle('is-dirty', d); }
    }

    let queued = false, propsToo = false;
    /** Redraws once per frame; props=true rebuilds the properties pane too. */
    function schedule(props) {
        propsToo = propsToo || !!props;
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            drawLabel(); drawOverlay(); updateRibbon(); updateStatus();
            if (propsToo) { propsToo = false; renderProps(); } else syncProps();
        });
    }

    // ---------------------------------------------------------------- the frame
    function build() {
        const app = document.getElementById('ldApp');
        E.name = h('input', { class: 'ld-name', value: doc.name, maxlength: 100, 'aria-label': T('name'),
            oninput: e => commit(() => { doc.name = e.target.value; }, 'name') });
        E.state = h('span', { class: 'ld-state' }, T('saved'));
        E.undo = h('button', { class: 'ld-icon-btn', title: T('undo') + ' (Ctrl+Z)', html: ICON.undo, onclick: undo });
        E.redo = h('button', { class: 'ld-icon-btn', title: T('redo') + ' (Ctrl+Y)', html: ICON.redo, onclick: redo });
        E.save = h('button', { class: 'btn btn-primary', onclick: save }, T('save'));
        const title = h('div', { class: 'ld-titlebar' },
            h('a', { class: 'ld-back', href: 'settings/?tab=asset-labels', html: ICON.backArrow + '<span>' + escHtml(T('back')) + '</span>' }),
            E.name, E.state, h('span', { class: 'ld-spacer' }), E.undo, E.redo, E.save);
        const banner = template !== 'custom' ? h('div', { class: 'ld-banner' }, T('from_template')) : null;

        E.tabs = h('div', { class: 'ld-tabs', role: 'tablist' });
        E.panel = h('div', { class: 'ld-panel' });
        const ribbon = h('div', { class: 'ld-ribbon' }, E.tabs, E.panel);
        ['home', 'insert', 'arrange', 'label'].forEach((k, i) => E.tabs.append(h('button', { class: 'ld-tab' + (i === 0 ? ' is-active' : ''), 'data-tab': k, role: 'tab', onclick: () => showTab(k) }, T('tab_' + k))));

        E.toolbox = h('div', { class: 'ld-toolbox' });
        E.stage = h('div', { class: 'ld-stage', tabindex: 0 });
        E.props = h('div', { class: 'ld-props' });
        const main = h('div', { class: 'ld-main' }, E.toolbox, E.stage, E.props);
        E.status = h('span', {});
        E.zoomR = h('input', { type: 'range', min: 50, max: 1500, step: 10, 'aria-label': T('zoom'), oninput: e => setZoom(+e.target.value / 100 * MM_100) });
        E.zoomT = h('span', {});
        const status = h('div', { class: 'ld-statusbar' }, E.status, h('span', { class: 'ld-spacer' }), E.zoomT, E.zoomR);
        app.append(title, banner || '', ribbon, main, status);

        buildToolbox();
        buildStage();
        showTab('home');
    }
    const escHtml = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // ---------------------------------------------------------------- the ribbon
    /** The commands the ribbon, the keyboard and the menu share: run, enabled, pressed. */
    const C = {
        undo: { run: undo, on: () => past.length > 0 },
        redo: { run: redo, on: () => future.length > 0 },
        cut: { run: () => { copy(); del(); }, on: () => sel.length > 0 },
        copy: { run: copy, on: () => sel.length > 0 },
        paste: { run: paste, on: () => !!clipboard() },
        dup: { run: duplicate, on: () => sel.length > 0 },
        del: { run: del, on: () => sel.length > 0 },
        bold: { run: () => toggleText('bold'), on: () => selected().some(isText), pressed: () => selected().filter(isText).every(i => i.bold) && selected().some(isText) },
        italic: { run: () => toggleText('italic'), on: () => selected().some(isText), pressed: () => selected().filter(isText).every(i => i.italic) && selected().some(isText) },
        bigger: { run: () => stepSize(1), on: () => selected().some(isText) },
        smaller: { run: () => stepSize(-1), on: () => selected().some(isText) },
        al: alignText('align', 'left'), ac: alignText('align', 'center'), ar: alignText('align', 'right'),
        vt: alignText('valign', 'top'), vm: alignText('valign', 'middle'), vb: alignText('valign', 'bottom'),
        aL: alignItems('L'), aC: alignItems('C'), aR: alignItems('R'), aT: alignItems('T'), aM: alignItems('M'), aB: alignItems('B'),
        dH: { run: () => distribute('x'), on: () => sel.length > 2 }, dV: { run: () => distribute('y'), on: () => sel.length > 2 },
        fwd: { run: () => order(1), on: () => sel.length > 0 }, back: { run: () => order(-1), on: () => sel.length > 0 },
        front: { run: () => order(99), on: () => sel.length > 0 }, sendBack: { run: () => order(-99), on: () => sel.length > 0 },
        rot: { run: rotate, on: () => sel.length > 0 },
        grid: { run: () => { grid = !grid; schedule(); }, on: () => true, pressed: () => grid },
        snap: { run: () => { snapOn = !snapOn; schedule(); }, on: () => true, pressed: () => snapOn },
        zin: { run: () => setZoom(zoom * 1.25), on: () => true }, zout: { run: () => setZoom(zoom / 1.25), on: () => true }, fit: { run: fit, on: () => true },
    };
    ['text', 'field', 'fields', 'qr', 'image', 'line', 'box'].forEach(t => { C['ins_' + t] = { run: () => insert(t), on: () => true }; });

    function rb(cmd, label, big) {
        return h('button', { class: 'ld-rb' + (big ? ' ld-rb-big' : ''), 'data-cmd': cmd, title: label, 'aria-label': label,
            onmousedown: e => e.preventDefault(),     // keep the selection and the text field's focus
            onclick: () => { if (C[cmd].on()) C[cmd].run(); },
            html: (ICON[cmd] || ICON[cmd.replace('ins_', '')] || '') + (big ? '<span>' + escHtml(label) + '</span>' : '') });
    }
    const group = (label, ...kids) => h('div', { class: 'ld-group' }, h('div', { class: 'ld-group-body' }, ...kids), h('div', { class: 'ld-group-label' }, label));
    const row = (...k) => h('div', { class: 'ld-row' }, ...k);
    const stack = (...k) => h('div', { class: 'ld-stack' }, ...k);

    function showTab(k) {
        E.tabs.querySelectorAll('.ld-tab').forEach(b => b.classList.toggle('is-active', b.dataset.tab === k));
        E.panel.innerHTML = '';
        if (k === 'home') {
            E.panel.append(
                group(T('g_clipboard'), rb('paste', T('paste'), true), stack(rb('cut', T('cut')), rb('copy', T('copy')))),
                group(T('g_edit'), stack(row(rb('undo', T('undo')), rb('redo', T('redo'))), row(rb('dup', T('duplicate')), rb('del', T('delete'))))),
                group(T('g_font'), stack(row(rb('bold', T('bold')), rb('italic', T('italic'))), row(rb('bigger', T('bigger')), rb('smaller', T('smaller'))))),
                group(T('g_paragraph'), stack(row(rb('al', T('align_left')), rb('ac', T('align_center')), rb('ar', T('align_right'))),
                                              row(rb('vt', T('valign_top')), rb('vm', T('valign_middle')), rb('vb', T('valign_bottom'))))));
        } else if (k === 'insert') {
            E.panel.append(group(T('g_insert'), ...['text', 'field', 'fields', 'qr', 'image', 'line', 'box'].map(t => rb('ins_' + t, T('item_' + t), true))));
        } else if (k === 'arrange') {
            E.panel.append(
                group(T('g_align'), stack(row(rb('aL', T('a_left')), rb('aC', T('a_center')), rb('aR', T('a_right'))), row(rb('aT', T('a_top')), rb('aM', T('a_middle')), rb('aB', T('a_bottom'))))),
                group(T('g_distribute'), stack(rb('dH', T('dist_h')), rb('dV', T('dist_v')))),
                group(T('g_order'), stack(row(rb('fwd', T('forward')), rb('back', T('backward'))), row(rb('front', T('to_front')), rb('sendBack', T('to_back'))))),
                group(T('g_rotate'), rb('rot', T('rotate'), true)));
            // The order icons, reused for "all the way".
            E.panel.querySelector('[data-cmd="front"]').innerHTML = ICON.fwd; E.panel.querySelector('[data-cmd="sendBack"]').innerHTML = ICON.back;
        } else {
            E.panel.append(
                group(T('g_size'), stack(sizeSelect(), customSize())),
                group(T('g_font_family'), fontSelect()),
                group(T('g_preview'), sampleSelect()),
                group(T('g_view'), stack(row(rb('grid', T('grid')), rb('snap', T('snap'))), row(rb('zout', T('zoom_out')), rb('zin', T('zoom_in')), rb('fit', T('fit'))))));
        }
        updateRibbon();
    }
    function updateRibbon() {
        E.panel.querySelectorAll('[data-cmd]').forEach(b => {
            const c = C[b.dataset.cmd];
            b.disabled = !c.on();
            if (c.pressed) b.setAttribute('aria-pressed', c.pressed() ? 'true' : 'false');
        });
        if (E.undo) { E.undo.disabled = !past.length; E.redo.disabled = !future.length; }
    }
    function sizeSelect() {
        const s = h('select', { class: 'ld-rsel', 'aria-label': T('g_size'), onchange: e => {
            const k = e.target.value;
            const st = B.stocks.find(x => x.key === k);
            commit(() => { doc.stock = k === 'custom' ? { key: 'custom', kind: 'tape', name: '', w: W(), h: H() } : Object.assign({ offset_x: doc.stock.offset_x || 0, offset_y: doc.stock.offset_y || 0 }, st); });
            showTab('label'); fit();
        } }, ...B.stocks.map(st => h('option', { value: st.key, selected: st.key === doc.stock.key }, st.name)), h('option', { value: 'custom', selected: doc.stock.key === 'custom' }, T('size_own')));
        return s;
    }
    function customSize() {
        if (doc.stock.key !== 'custom') return h('span', { class: 'ld-hint' }, r1(W()) + ' x ' + r1(H()) + ' mm');
        const num = (v, set) => h('input', { type: 'number', class: 'ld-rsel', style: { width: '64px' }, min: 6, max: 300, step: 0.5, value: v,
            onchange: e => { const n = Math.max(6, Math.min(300, +e.target.value || 6)); commit(() => set(n)); fit(); } });
        return row(num(W(), n => { doc.stock.w = n; }), ' x ', num(H(), n => { doc.stock.h = n; }), ' mm');
    }
    function fontSelect() {
        return h('select', { class: 'ld-rsel', 'aria-label': T('g_font_family'), onchange: async e => { const v = e.target.value; await window.LabelRender.loadFonts(B.base, [v]); commit(() => { doc.font = v; }); } },
            h('option', { value: 'noto', selected: doc.font === 'noto' }, 'Noto Sans'),
            ...B.fonts.map(f => h('option', { value: 'font:' + f.id, selected: doc.font === 'font:' + f.id }, f.name)));
    }
    function sampleSelect() {
        return h('select', { class: 'ld-rsel', 'aria-label': T('g_preview'), onchange: e => { sampleIdx = +e.target.value; schedule(); } },
            ...B.samples.map((s, i) => h('option', { value: i, selected: i === sampleIdx }, s.label)));
    }

    // ---------------------------------------------------------------- the toolbox
    function buildToolbox() {
        const tool = (type, extra) => h('div', { class: 'ld-tool', 'data-type': type, onpointerdown: e => startToolDrag(e, type, extra),
            html: ICON[type] + '<div>' + escHtml(extra ? (B.fields[extra] || extra) : T('item_' + type)) + (extra ? '' : '<small>' + escHtml(T('item_' + type + '_hint')) + '</small>') + '</div>' });
        E.toolbox.append(h('div', { class: 'ld-pane-head' }, h('h2', {}, T('toolbox'))),
            ...['text', 'field', 'fields', 'qr', 'image', 'line', 'box'].map(t => tool(t)),
            h('div', { class: 'ld-tool-sub' }, T('toolbox_fields')),
            ...Object.keys(B.fields).map(k => tool('field', k)));
    }
    /** Drag from the toolbox: a ghost follows the pointer; dropped on the label, the item lands there. A plain click adds it in the middle. */
    function startToolDrag(e, type, field) {
        e.preventDefault();
        const ghost = h('div', { class: 'ld-ghost' }, field ? (B.fields[field] || field) : T('item_' + type));
        let moved = false;
        const sx = e.clientX, sy = e.clientY;
        const move = ev => {
            if (!moved && Math.hypot(ev.clientX - sx, ev.clientY - sy) < 4) return;
            if (!moved) { moved = true; document.body.append(ghost); }
            ghost.style.left = ev.clientX + 10 + 'px'; ghost.style.top = ev.clientY + 10 + 'px';
        };
        const up = ev => {
            window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up);
            ghost.remove();
            if (!moved) { insert(type, null, field); return; }
            const r = E.overlay.getBoundingClientRect();
            if (ev.clientX >= r.left && ev.clientX <= r.right && ev.clientY >= r.top && ev.clientY <= r.bottom) {
                insert(type, { x: (ev.clientX - r.left) / zoom, y: (ev.clientY - r.top) / zoom }, field);
            }
        };
        window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
    }

    /** A new item of a type, centred on a point (default: the label's middle), held inside the label. */
    function insert(type, at, field) {
        const w = W(), hh = H();
        const text = { size: 9, min: 4, bold: false, italic: false, align: 'left', valign: 'middle', fit: 'shrink', color: '#000000' };
        const q = Math.min(w, hh) * 0.7;
        const it = {
            text: Object.assign({ type: 'text', text: T('new_text'), w: Math.min(30, w * 0.6), h: Math.min(6, hh * 0.4) }, text),
            field: Object.assign({ type: 'field', field: field || 'asset_tag', caption: 'none', w: Math.min(30, w * 0.6), h: Math.min(6, hh * 0.4) }, text, { size: 10, bold: (field || 'asset_tag') === 'asset_tag' }),
            fields: Object.assign({ type: 'fields', fields: ['asset_tag', 'hostname', 'service_tag'], caption: 'none', w: Math.min(32, w * 0.6), h: Math.min(14, hh * 0.8), tag_size: 10 }, text, { size: 7, valign: 'top' }),
            qr: { type: 'qr', logo: false, w: q, h: q },
            image: { type: 'image', src: 'logo', w: Math.min(w * 0.3, 25), h: Math.min(hh * 0.7, 18) },
            line: { type: 'line', w: w * 0.5, h: 0, thick: 0.3, color: '#000000' },
            box: { type: 'box', w: w * 0.5, h: hh * 0.5, thick: 0.3, fill: false, color: '#000000' },
        }[type];
        const c = at || { x: w / 2, y: hh / 2 };
        it.id = newId(); it.rot = 0;
        it.x = r1(Math.max(0, Math.min(w - it.w, c.x - it.w / 2)));
        it.y = r1(Math.max(0, Math.min(hh - it.h, c.y - it.h / 2)));
        it.w = r1(it.w); it.h = r1(it.h);
        commit(() => { doc.items.push(it); sel = [it.id]; });
        schedule(true);
        E.stage.focus();
    }

    // ---------------------------------------------------------------- the stage
    function buildStage() {
        E.canvas = h('canvas', {});
        E.overlay = h('div', { class: 'ld-overlay' });
        E.paper = h('div', { class: 'ld-paper' }, E.canvas, E.overlay);
        E.rulerH = h('canvas', { class: 'ld-ruler-h' });
        E.rulerV = h('canvas', { class: 'ld-ruler-v' });
        E.sheet = h('div', { class: 'ld-sheet' }, E.rulerH, E.rulerV, E.paper);
        E.stage.append(E.sheet);
        E.overlay.addEventListener('pointerdown', onDown);
        E.overlay.addEventListener('pointermove', e => {
            const p = toMm(e); cursorMm = p;
            const hit = hitTest(p);
            const id = hit ? hit.id : null;
            if (id !== hoverId) { hoverId = id; drawOverlay(); }
            updateStatus();
        });
        E.overlay.addEventListener('pointerleave', () => { cursorMm = null; hoverId = null; drawOverlay(); updateStatus(); });
        E.overlay.addEventListener('dblclick', e => {
            const it = hitTest(toMm(e));
            if (it && it.type === 'text') { const ta = E.props.querySelector('textarea'); if (ta) { ta.focus(); ta.select(); } }
        });
        E.overlay.addEventListener('contextmenu', e => {
            e.preventDefault();
            const it = hitTest(toMm(e));
            if (it && !sel.includes(it.id)) { sel = [it.id]; schedule(true); }
            menu(e.clientX, e.clientY);
        });
        E.stage.addEventListener('wheel', e => { if (!e.ctrlKey) return; e.preventDefault(); setZoom(zoom * (e.deltaY < 0 ? 1.1 : 1 / 1.1)); }, { passive: false });
        new ResizeObserver(() => schedule()).observe(E.stage);
    }
    const toMm = e => { const r = E.overlay.getBoundingClientRect(); return { x: (e.clientX - r.left) / zoom, y: (e.clientY - r.top) / zoom }; };
    function hitTest(p) {
        for (let i = doc.items.length - 1; i >= 0; i--) {
            const it = doc.items[i];
            const pad = 1.5 / zoom * 3;   // a few pixels' slack, so thin lines can be caught
            if (p.x >= it.x - pad && p.x <= it.x + it.w + pad && p.y >= it.y - pad && p.y <= it.y + it.h + pad) return it;
        }
        return null;
    }
    function setZoom(z) {
        zoom = Math.max(MM_100 * 0.5, Math.min(MM_100 * 15, z));
        schedule();
    }
    function fit() {
        const r = E.stage.getBoundingClientRect();
        setZoom(Math.min((r.width - 110) / W(), (r.height - 110) / H()));
    }

    /** Draws the label as it prints: white, the grid under it, then every item with the preview asset's values. */
    function drawLabel() {
        const dpr = DPR(), px = zoom * dpr;
        const cw = Math.round(W() * px), ch = Math.round(H() * px);
        if (E.canvas.width !== cw || E.canvas.height !== ch) { E.canvas.width = cw; E.canvas.height = ch; }
        const wcss = W() * zoom + 'px', hcss = H() * zoom + 'px';
        E.canvas.style.width = wcss; E.canvas.style.height = hcss;
        E.paper.style.width = wcss; E.paper.style.height = hcss;
        E.sheet.style.width = wcss;
        const g = E.canvas.getContext('2d');
        g.fillStyle = '#ffffff'; g.fillRect(0, 0, cw, ch);
        if (grid) {
            for (let mm = 1; mm < Math.max(W(), H()); mm++) {
                g.fillStyle = mm % 5 === 0 ? 'rgba(16,124,16,.16)' : 'rgba(0,0,0,.05)';
                if (mm < W()) g.fillRect(Math.round(mm * px), 0, 1, ch);
                if (mm < H()) g.fillRect(0, Math.round(mm * px), cw, 1);
            }
        }
        const s = B.samples[sampleIdx] || { values: {} };
        window.LabelRender.drawLabel(g, { items: doc.items, values: s.values, url: B.sampleUrl, captions: B.captions, noTag: B.noTag, font: doc.font, logo, mono: B.design.mono }, px);
        drawRulers();
    }
    /** Rulers in millimetres along the top and left, a long tick every 5 and a number every 10. */
    function drawRulers() {
        const dpr = DPR();
        const one = (cv, len, horiz) => {
            const L = Math.round(len * zoom);
            cv.width = (horiz ? L : 22) * dpr; cv.height = (horiz ? 22 : L) * dpr;
            cv.style.width = (horiz ? L : 22) + 'px'; cv.style.height = (horiz ? 22 : L) + 'px';
            const g = cv.getContext('2d');
            g.scale(dpr, dpr);
            const css = getComputedStyle(document.body);
            g.fillStyle = css.getPropertyValue('--surface').trim() || '#fff'; g.fillRect(0, 0, horiz ? L : 22, horiz ? 22 : L);
            g.strokeStyle = g.fillStyle = css.getPropertyValue('--text-muted').trim() || '#666';
            g.font = '9px sans-serif'; g.lineWidth = 1;
            const step = zoom < 3 ? 5 : 1;
            for (let mm = 0; mm <= len; mm += step) {
                const p = Math.round(mm * zoom) + 0.5;
                const t = mm % 10 === 0 ? 10 : mm % 5 === 0 ? 6 : 3;
                g.beginPath();
                if (horiz) { g.moveTo(p, 22); g.lineTo(p, 22 - t); } else { g.moveTo(22, p); g.lineTo(22 - t, p); }
                g.stroke();
                if (mm % 10 === 0 && mm > 0) { if (horiz) g.fillText(mm, p + 2, 10); else { g.save(); g.translate(10, p - 2); g.rotate(-Math.PI / 2); g.fillText(mm, 0, 0); g.restore(); } }
            }
        };
        one(E.rulerH, W(), true);
        one(E.rulerV, H(), false);
    }
    /** Selection boxes, handles, the hover outline, guides and the marquee, in an HTML layer over the label. */
    function drawOverlay() {
        const o = E.overlay;
        o.innerHTML = '';
        const box = (it, cls) => h('div', { class: cls, style: { left: it.x * zoom + 'px', top: it.y * zoom + 'px', width: Math.max(1, it.w * zoom) + 'px', height: Math.max(1, it.h * zoom) + 'px' } });
        doc.items.forEach(it => {
            if (it.id === hoverId && !sel.includes(it.id)) o.append(box(it, 'ld-hover'));
            if (it.type === 'image' && !logo) o.append(h('div', { class: 'ld-hover', style: { left: it.x * zoom + 'px', top: it.y * zoom + 'px', width: it.w * zoom + 'px', height: it.h * zoom + 'px',
                display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '11px', color: '#888', textAlign: 'center' } }, T('no_logo')));
        });
        selected().forEach(it => o.append(box(it, 'ld-sel' + (it === primary() ? ' is-primary' : ''))));
        if (sel.length === 1 && primary()) {
            const it = primary();
            const pts = it.type === 'line'
                ? { nw: [it.x, it.y], se: [it.x + it.w, it.y + it.h] }
                : { nw: [it.x, it.y], n: [it.x + it.w / 2, it.y], ne: [it.x + it.w, it.y], e: [it.x + it.w, it.y + it.h / 2],
                    se: [it.x + it.w, it.y + it.h], s: [it.x + it.w / 2, it.y + it.h], sw: [it.x, it.y + it.h], w: [it.x, it.y + it.h / 2] };
            const cursors = { nw: 'nwse-resize', se: 'nwse-resize', ne: 'nesw-resize', sw: 'nesw-resize', n: 'ns-resize', s: 'ns-resize', e: 'ew-resize', w: 'ew-resize' };
            Object.entries(pts).forEach(([k, [x, y]]) => o.append(h('div', { class: 'ld-handle', 'data-h': k, style: { left: x * zoom + 'px', top: y * zoom + 'px', cursor: cursors[k] } })));
        }
        guides.forEach(g => o.append(h('div', { class: 'ld-guide ' + g.d, style: g.d === 'v' ? { left: g.at * zoom + 'px' } : { top: g.at * zoom + 'px' } })));
        if (marquee) o.append(h('div', { class: 'ld-marquee', style: { left: Math.min(marquee.x0, marquee.x1) * zoom + 'px', top: Math.min(marquee.y0, marquee.y1) * zoom + 'px',
            width: Math.abs(marquee.x1 - marquee.x0) * zoom + 'px', height: Math.abs(marquee.y1 - marquee.y0) * zoom + 'px' } }));
    }
    function updateStatus() {
        if (!E.status) return;
        const parts = [];
        if (cursorMm) parts.push(T('status_at', { x: r1(cursorMm.x), y: r1(cursorMm.y) }));
        if (sel.length === 1 && primary()) { const p = primary(); parts.push(T('status_item', { x: r1(p.x), y: r1(p.y), w: r1(p.w), h: r1(p.h) })); }
        else if (sel.length > 1) parts.push(T('status_many', { n: sel.length }));
        parts.push(r1(W()) + ' x ' + r1(H()) + ' mm');
        E.status.textContent = parts.join('   ·   ');
        const pct = Math.round(zoom / MM_100 * 100);
        E.zoomT.textContent = pct + '%';
        if (document.activeElement !== E.zoomR) E.zoomR.value = pct;
    }

    // ---------------------------------------------------------------- dragging
    /** Pointer down on the label: a handle resizes, an item moves (selecting it first), empty space draws a marquee. */
    function onDown(e) {
        if (e.button !== 0) return;
        E.stage.focus();
        const p = toMm(e);
        const handle = e.target.closest('.ld-handle');
        if (handle) return startResize(e, handle.dataset.h, p);
        const it = hitTest(p);
        if (!it) {
            if (!e.shiftKey) sel = [];
            marquee = { x0: p.x, y0: p.y, x1: p.x, y1: p.y, add: e.shiftKey ? sel.slice() : [] };
            const move = ev => { const q = toMm(ev); marquee.x1 = q.x; marquee.y1 = q.y; pickMarquee(); drawOverlay(); };
            const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); marquee = null; schedule(true); };
            window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
            schedule(true);
            return;
        }
        if (e.shiftKey) { sel = sel.includes(it.id) ? sel.filter(x => x !== it.id) : sel.concat(it.id); schedule(true); return; }
        if (!sel.includes(it.id)) { sel = [it.id]; schedule(true); }
        else { sel = sel.filter(x => x !== it.id).concat(it.id); }
        startMove(e, p);
    }
    function pickMarquee() {
        const m = marquee, x0 = Math.min(m.x0, m.x1), x1 = Math.max(m.x0, m.x1), y0 = Math.min(m.y0, m.y1), y1 = Math.max(m.y0, m.y1);
        const hit = doc.items.filter(it => it.x < x1 && it.x + it.w > x0 && it.y < y1 && it.y + it.h > y0).map(it => it.id);
        sel = m.add.concat(hit.filter(id => !m.add.includes(id)));
    }
    /** Snap targets: the label's edges and centre, and every other item's edges and centres. */
    function targets(excl) {
        const xs = [0, W() / 2, W()], ys = [0, H() / 2, H()];
        doc.items.forEach(it => { if (excl.includes(it.id)) return; xs.push(it.x, it.x + it.w / 2, it.x + it.w); ys.push(it.y, it.y + it.h / 2, it.y + it.h); });
        return { xs, ys };
    }
    /** The best snap for a set of edges (positions) against targets, within a few pixels: {delta, at} or null. */
    function snapTo(edges, tgts) {
        const tol = 6 / zoom;
        let best = null;
        edges.forEach(e => tgts.forEach(t => { const d = t - e; if (Math.abs(d) <= tol && (!best || Math.abs(d) < Math.abs(best.delta))) best = { delta: d, at: t }; }));
        return best;
    }
    const gridSnap = v => Math.round(v * 2) / 2;   // half a millimetre

    function startMove(e, p0) {
        const start = selected().map(it => ({ id: it.id, x: it.x, y: it.y }));
        const bb = bbox(selected());
        const tg = targets(sel);
        let moved = false;
        const move = ev => {
            const p = toMm(ev);
            let dx = p.x - p0.x, dy = p.y - p0.y;
            if (!moved && Math.hypot(dx * zoom, dy * zoom) < 3) return;
            if (!moved) { moved = true; past.push(JSON.stringify(doc)); future.length = 0; lastMerge = null; }
            guides = [];
            if (ev.shiftKey) { if (Math.abs(dx) > Math.abs(dy)) dy = 0; else dx = 0; }   // a straight line
            if (snapOn && !ev.altKey) {
                const sx = snapTo([bb.x + dx, bb.x + bb.w / 2 + dx, bb.x + bb.w + dx], tg.xs);
                const sy = snapTo([bb.y + dy, bb.y + bb.h / 2 + dy, bb.y + bb.h + dy], tg.ys);
                if (sx) { dx += sx.delta; guides.push({ d: 'v', at: sx.at }); } else if (grid) dx = gridSnap(bb.x + dx) - bb.x;
                if (sy) { dy += sy.delta; guides.push({ d: 'h', at: sy.at }); } else if (grid) dy = gridSnap(bb.y + dy) - bb.y;
            }
            start.forEach(s => { const it = byId(s.id); it.x = r1(s.x + dx); it.y = r1(s.y + dy); });
            setDirty(true);
            schedule();
        };
        const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); guides = []; schedule(true); };
        window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
    }
    function startResize(e, hd, p0) {
        e.stopPropagation();
        const it = primary();
        const s = { x: it.x, y: it.y, w: it.w, h: it.h };
        const tg = targets([it.id]);
        past.push(JSON.stringify(doc)); future.length = 0; lastMerge = null;
        const square = it.type === 'qr';
        const move = ev => {
            const p = toMm(ev);
            const dx = p.x - p0.x, dy = p.y - p0.y;
            guides = [];
            if (it.type === 'line') {
                // A line's two ends move freely; Shift keeps it level or upright.
                if (hd === 'nw') { it.x = r1(s.x + dx); it.y = r1(s.y + dy); it.w = r1(s.w - dx); it.h = r1(s.h - dy); }
                else { it.w = r1(s.w + dx); it.h = r1(s.h + dy); }
                if (ev.shiftKey) { if (Math.abs(it.w) > Math.abs(it.h)) it.h = 0; else it.w = 0; }
                // TRAP: a line only runs right and down (w, h >= 0) - the server keeps it so, and hit-testing assumes it.
                it.w = Math.max(0, it.w); it.h = Math.max(0, it.h);
                setDirty(true); schedule(); return;
            }
            let x0 = s.x, y0 = s.y, x1 = s.x + s.w, y1 = s.y + s.h;
            if (hd.includes('w')) x0 = s.x + dx;
            if (hd.includes('e')) x1 = s.x + s.w + dx;
            if (hd.includes('n')) y0 = s.y + dy;
            if (hd.includes('s')) y1 = s.y + s.h + dy;
            if (snapOn && !ev.altKey) {
                const edge = (v, list, d) => { const sn = snapTo([v], list); if (sn) { guides.push({ d, at: sn.at }); return v + sn.delta; } return grid ? gridSnap(v) : v; };
                if (hd.includes('w')) x0 = edge(x0, tg.xs, 'v');
                if (hd.includes('e')) x1 = edge(x1, tg.xs, 'v');
                if (hd.includes('n')) y0 = edge(y0, tg.ys, 'h');
                if (hd.includes('s')) y1 = edge(y1, tg.ys, 'h');
            }
            let w = Math.max(1, x1 - x0), hh = Math.max(1, y1 - y0);
            if (square || (ev.shiftKey && hd.length === 2)) {
                // Keep the proportions (a QR code is always square).
                const ratio = square ? 1 : s.w / s.h;
                if (w / hh > ratio) w = hh * ratio; else hh = w / ratio;
                if (hd.includes('w')) x0 = s.x + s.w - w;
                if (hd.includes('n')) y0 = s.y + s.h - hh;
                if (square && hd.length === 1) { if (hd === 'e' || hd === 'w') hh = w; else w = hh; }
            }
            it.x = r1(x0); it.y = r1(y0); it.w = r1(w); it.h = r1(hh);
            setDirty(true); schedule();
        };
        const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); guides = []; schedule(true); };
        window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
    }
    function bbox(list) {
        if (!list.length) return { x: 0, y: 0, w: 0, h: 0 };
        const x0 = Math.min(...list.map(i => i.x)), y0 = Math.min(...list.map(i => i.y));
        const x1 = Math.max(...list.map(i => i.x + i.w)), y1 = Math.max(...list.map(i => i.y + i.h));
        return { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
    }

    // ---------------------------------------------------------------- commands
    const CLIP = 'freeitsm.labelClipboard';
    let pageClip = null;    // where storage is blocked (some private windows), copying still works within this page
    function clipboard() {
        try { const v = JSON.parse(localStorage.getItem(CLIP) || 'null'); if (Array.isArray(v) && v.length) return v; } catch (e) { /* blocked */ }
        return pageClip && pageClip.length ? pageClip : null;
    }
    function copy() {
        pageClip = JSON.parse(JSON.stringify(selected()));
        try { localStorage.setItem(CLIP, JSON.stringify(pageClip)); } catch (e) { /* blocked: pageClip has it */ }
        updateRibbon();
    }
    function paste() {
        const items = clipboard(); if (!items) return;
        commit(() => {
            const fresh = items.map(it => Object.assign(JSON.parse(JSON.stringify(it)), { id: newId(), x: r1(it.x + 2), y: r1(it.y + 2) }));
            doc.items.push(...fresh);
            sel = fresh.map(i => i.id);
        });
        schedule(true);
    }
    function duplicate() { copy(); paste(); }
    function del() { if (!sel.length) return; commit(() => { doc.items = doc.items.filter(i => !sel.includes(i.id)); sel = []; }); schedule(true); }
    function toggleText(k) { const on = !selected().filter(isText).every(i => i[k]); commit(() => selected().filter(isText).forEach(i => { i[k] = on; })); schedule(true); }
    function stepSize(d) {
        commit(() => selected().filter(isText).forEach(i => {
            i.size = Math.max(2, Math.min(96, r1(i.size + d)));
            if (i.tag_size) i.tag_size = Math.max(2, Math.min(96, r1(i.tag_size + d)));
            if (i.min > i.size) i.min = i.size;
        }), 'size');
        schedule(true);
    }
    function alignText(k, v) {
        return { run: () => { commit(() => selected().filter(isText).forEach(i => { i[k] = v; })); schedule(true); },
                 on: () => selected().some(isText), pressed: () => selected().some(isText) && selected().filter(isText).every(i => i[k] === v) };
    }
    /** Align: one item to the label, several to the box around them all. */
    function alignItems(how) {
        return { on: () => sel.length > 0, run: () => {
            const items = selected();
            const ref = items.length === 1 ? { x: 0, y: 0, w: W(), h: H() } : bbox(items);
            commit(() => items.forEach(i => {
                if (how === 'L') i.x = r1(ref.x);
                if (how === 'C') i.x = r1(ref.x + (ref.w - i.w) / 2);
                if (how === 'R') i.x = r1(ref.x + ref.w - i.w);
                if (how === 'T') i.y = r1(ref.y);
                if (how === 'M') i.y = r1(ref.y + (ref.h - i.h) / 2);
                if (how === 'B') i.y = r1(ref.y + ref.h - i.h);
            }));
            schedule(true);
        } };
    }
    /** Equal gaps between three or more items, across (x) or down (y). */
    function distribute(axis) {
        const items = selected().slice().sort((a, b) => a[axis] - b[axis]);
        const size = axis === 'x' ? 'w' : 'h';
        const span = items[items.length - 1][axis] + items[items.length - 1][size] - items[0][axis];
        const gap = (span - items.reduce((s, i) => s + i[size], 0)) / (items.length - 1);
        commit(() => { let p = items[0][axis]; items.forEach(i => { i[axis] = r1(p); p += i[size] + gap; }); });
        schedule(true);
    }
    /** Forward / backward one place, or right to the front / back (big steps). */
    function order(step) {
        commit(() => {
            const ids = new Set(sel);
            let list = doc.items.slice();
            if (Math.abs(step) > 1) {
                const mine = list.filter(i => ids.has(i.id)), rest = list.filter(i => !ids.has(i.id));
                list = step > 0 ? rest.concat(mine) : mine.concat(rest);
            } else {
                const idx = list.map((it, i) => ids.has(it.id) ? i : -1).filter(i => i >= 0);
                (step > 0 ? idx.reverse() : idx).forEach(i => {
                    const j = i + step;
                    if (j >= 0 && j < list.length && !ids.has(list[j].id)) [list[i], list[j]] = [list[j], list[i]];
                });
            }
            doc.items = list;
        });
        schedule(true);
    }
    /** A quarter turn, about the item's centre: its box turns too. */
    function rotate() {
        commit(() => selected().forEach(i => {
            const cx = i.x + i.w / 2, cy = i.y + i.h / 2;
            i.rot = ((+i.rot || 0) + 90) % 360;
            if (i.type !== 'line') { [i.w, i.h] = [i.h, i.w]; i.x = r1(cx - i.w / 2); i.y = r1(cy - i.h / 2); }
        }));
        schedule(true);
    }
    function nudge(dx, dy) {
        commit(() => selected().forEach(i => { i.x = r1(i.x + dx); i.y = r1(i.y + dy); }), 'nudge');
        schedule();
    }

    // ---------------------------------------------------------------- the right-click menu
    function menu(x, y) {
        closeMenu();
        const item = (cmd, label, key) => h('button', { disabled: !C[cmd].on(), onclick: () => { closeMenu(); C[cmd].run(); } }, label, key ? h('kbd', {}, key) : '');
        const m = h('div', { class: 'ld-menu', role: 'menu' },
            item('cut', T('cut'), 'Ctrl+X'), item('copy', T('copy'), 'Ctrl+C'), item('paste', T('paste'), 'Ctrl+V'), item('dup', T('duplicate'), 'Ctrl+D'),
            h('hr'), item('front', T('to_front')), item('fwd', T('forward')), item('back', T('backward')), item('sendBack', T('to_back')),
            h('hr'), item('rot', T('rotate')), item('del', T('delete'), 'Del'));
        document.body.append(m);
        const r = m.getBoundingClientRect();
        m.style.left = Math.min(x, innerWidth - r.width - 8) + 'px'; m.style.top = Math.min(y, innerHeight - r.height - 8) + 'px';
        setTimeout(() => document.addEventListener('pointerdown', closeOnOutside), 0);
    }
    function closeOnOutside(e) { if (!e.target.closest('.ld-menu')) closeMenu(); }
    function closeMenu() { document.querySelectorAll('.ld-menu').forEach(m => m.remove()); document.removeEventListener('pointerdown', closeOnOutside); }

    // ---------------------------------------------------------------- the keyboard
    document.addEventListener('keydown', e => {
        const typing = e.target.closest && e.target.closest('input, textarea, select, [contenteditable]');
        const k = e.key.toLowerCase(), ctrl = e.ctrlKey || e.metaKey;
        if (ctrl && k === 's') { e.preventDefault(); save(); return; }
        if (typing) return;
        if (e.key === 'Escape') { closeMenu(); sel = []; schedule(true); return; }
        if (ctrl && k === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); return; }
        if (ctrl && k === 'y') { e.preventDefault(); redo(); return; }
        if (ctrl && k === 'a') { e.preventDefault(); sel = doc.items.map(i => i.id); schedule(true); return; }
        if (ctrl && k === 'c') { e.preventDefault(); copy(); return; }
        if (ctrl && k === 'x') { e.preventDefault(); copy(); del(); return; }
        if (ctrl && k === 'v') { e.preventDefault(); paste(); return; }
        if (ctrl && k === 'd') { e.preventDefault(); duplicate(); return; }
        if (ctrl && k === 'b') { e.preventDefault(); if (C.bold.on()) C.bold.run(); return; }
        if (ctrl && k === 'i') { e.preventDefault(); if (C.italic.on()) C.italic.run(); return; }
        if (e.key === 'Delete' || e.key === 'Backspace') { if (sel.length) { e.preventDefault(); del(); } return; }
        const step = e.shiftKey ? 2 : 0.5;
        const arrows = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (arrows[e.key] && sel.length) { e.preventDefault(); nudge(...arrows[e.key]); }
    });

    // ---------------------------------------------------------------- the properties pane
    /** Builds the pane for what is selected: nothing (the label), one item, or several. */
    function renderProps() {
        const P = E.props;
        P.innerHTML = '';
        const body = h('div', { class: 'ld-props-body' });
        const items = selected();
        P.append(h('div', { class: 'ld-pane-head' }, h('h2', {}, items.length === 1 ? T('item_' + items[0].type) : items.length ? T('n_selected', { n: items.length }) : T('the_label'))), body);
        if (!items.length) {
            body.append(h('p', { class: 'ld-hint' }, T('label_hint')),
                h('h3', {}, T('g_size')), sizeSelect(), h('div', { style: { marginTop: '6px' } }, customSize()),
                doc.stock.kind === 'sheet' ? offsetFields() : '',
                h('h3', {}, T('g_font_family')), fontSelect(),
                h('h3', {}, T('g_preview')), sampleSelect(), h('p', { class: 'ld-hint' }, T('preview_hint')));
            return;
        }
        if (items.length > 1) { body.append(h('p', { class: 'ld-hint' }, T('many_hint'))); return; }
        const it = items[0];
        body.append(h('h3', {}, T('p_position')), xywh(it));
        if (isText(it)) textProps(body, it);
        if (it.type === 'qr') body.append(h('h3', {}, T('item_qr')), check(it, 'logo', T('p_qr_logo')), h('p', { class: 'ld-hint' }, T('p_qr_hint')));
        if (it.type === 'image') body.append(h('p', { class: 'ld-hint' }, logo ? T('p_image_hint') : T('no_logo_hint')));
        if (it.type === 'line' || it.type === 'box') {
            body.append(h('h3', {}, T('p_style')), num(it, 'thick', T('p_thick'), 0.1, 5, 0.1), colour(it));
            if (it.type === 'box') body.append(check(it, 'fill', T('p_fill')));
        }
    }
    /** Keeps the position boxes in step during a drag, without rebuilding the pane under the cursor. */
    function syncProps() {
        const it = sel.length === 1 ? primary() : null;
        if (!it) return;
        E.props.querySelectorAll('[data-xywh]').forEach(inp => { if (document.activeElement !== inp) inp.value = r1(it[inp.dataset.xywh]); });
    }
    function xywh(it) {
        const f = (k, label) => [h('span', {}, label), h('input', { type: 'number', step: 0.1, value: r1(it[k]), 'data-xywh': k,
            oninput: e => { const v = parseFloat(e.target.value); if (!isNaN(v)) { commit(() => { it[k] = r1(k === 'w' || k === 'h' ? Math.max(it.type === 'line' ? 0 : 0.5, v) : v); }, 'xywh' + k); } } })];
        return h('div', { class: 'ld-xywh' }, ...f('x', 'X'), ...f('y', 'Y'), ...f('w', T('p_w')), ...f('h', T('p_h')));
    }
    function num(it, k, label, min, max, step) {
        return h('label', { class: 'ld-pf' }, h('span', {}, label), h('input', { type: 'number', min, max, step, value: it[k],
            oninput: e => { const v = parseFloat(e.target.value); if (!isNaN(v)) commit(() => { it[k] = Math.max(min, Math.min(max, v)); }, 'p' + k); } }));
    }
    function check(it, k, label) {
        return h('label', { class: 'ld-pf' }, h('input', { type: 'checkbox', checked: !!it[k], onchange: e => commit(() => { it[k] = e.target.checked; }) }), label);
    }
    function colour(it) {
        return h('label', { class: 'ld-pf' }, h('span', {}, T('p_colour')), h('input', { type: 'color', value: it.color || '#000000', oninput: e => commit(() => { it.color = e.target.value; }, 'pcol') }));
    }
    function seg(it, k, opts) {
        return h('div', { class: 'ld-seg' }, ...opts.map(([v, label]) => h('button', { type: 'button', 'aria-pressed': it[k] === v ? 'true' : 'false',
            onclick: () => { commit(() => { it[k] = v; }); schedule(true); } }, label)));
    }
    function select(it, k, opts, label) {
        return h('label', { class: 'ld-pf' }, h('span', {}, label), h('select', { onchange: e => { commit(() => { it[k] = e.target.value; }); schedule(true); } },
            ...opts.map(([v, l]) => h('option', { value: v, selected: it[k] === v }, l))));
    }
    function textProps(body, it) {
        const fieldOpts = Object.entries(B.fields);
        if (it.type === 'text') {
            const ta = h('textarea', { 'aria-label': T('p_text'), oninput: e => commit(() => { it.text = e.target.value; }, 'ptext') });
            ta.value = it.text || '';
            body.append(h('h3', {}, T('p_text')), ta,
                h('label', { class: 'ld-pf' }, h('span', {}, T('p_insert_field')), h('select', { onchange: e => {
                    if (!e.target.value) return;
                    const tok = '{' + e.target.value + '}', a = ta.selectionStart ?? ta.value.length, b = ta.selectionEnd ?? a;
                    ta.value = ta.value.slice(0, a) + tok + ta.value.slice(b);
                    commit(() => { it.text = ta.value; });
                    e.target.value = '';
                    ta.focus();
                } }, h('option', { value: '' }, '-'), ...fieldOpts.map(([k, l]) => h('option', { value: k }, l)))),
                h('p', { class: 'ld-hint' }, T('p_text_hint')));
        }
        if (it.type === 'field') {
            body.append(h('h3', {}, T('item_field')), select(it, 'field', fieldOpts, T('p_field')),
                select(it, 'caption', [['none', T('cap_none')], ['inline', T('cap_inline')], ['above', T('cap_above')]], T('p_caption')));
        }
        if (it.type === 'fields') {
            body.append(h('h3', {}, T('item_fields')), fieldsEditor(it),
                select(it, 'caption', [['none', T('cap_none')], ['inline', T('cap_inline')], ['above', T('cap_above')]], T('p_caption')),
                num(it, 'tag_size', T('p_tag_size'), 2, 96, 0.5));
        }
        body.append(h('h3', {}, T('p_text_style')), num(it, 'size', T('p_size'), 2, 96, 0.5), num(it, 'min', T('p_min'), 2, 96, 0.5),
            h('div', { class: 'ld-pf' }, h('span', {}, T('p_style')), seg(it, 'bold', [[true, 'B']]), seg(it, 'italic', [[true, 'I']])),
            h('div', { class: 'ld-pf' }, h('span', {}, T('p_align')), seg(it, 'align', [['left', T('left')], ['center', T('center')], ['right', T('right')]])),
            h('div', { class: 'ld-pf' }, h('span', {}, T('p_valign')), seg(it, 'valign', [['top', T('top')], ['middle', T('middle')], ['bottom', T('bottom')]])),
            select(it, 'fit', [['shrink', T('fit_shrink')], ['wrap', T('fit_wrap')], ['clip', T('fit_clip')]], T('p_fit')),
            h('p', { class: 'ld-hint' }, T('p_fit_hint')), colour(it));
        // B / I are toggles: a second press turns them off.
        body.querySelectorAll('.ld-seg').forEach(s => {
            const b = s.querySelector('button');
            if (s.children.length === 1) b.onclick = ev => { ev.stopImmediatePropagation(); toggleText(b.textContent === 'B' ? 'bold' : 'italic'); };
        });
    }
    /** The fields in a field list: tick to include, arrows to reorder. */
    function fieldsEditor(it) {
        const chosen = it.fields.slice(), rest = Object.keys(B.fields).filter(k => !chosen.includes(k));
        const list = h('div', { class: 'ld-fieldlist' });
        const set = f => { commit(() => { it.fields = f; }); schedule(true); };
        chosen.concat(rest).forEach(k => {
            const on = chosen.includes(k), i = chosen.indexOf(k);
            list.append(h('label', {}, h('input', { type: 'checkbox', checked: on, onchange: e => set(e.target.checked ? chosen.concat(k) : chosen.filter(x => x !== k)) }),
                B.fields[k] || k,
                on ? h('span', { class: 'ld-mv' },
                    h('button', { type: 'button', title: T('move_up'), disabled: i === 0, onclick: ev => { ev.preventDefault(); const f = chosen.slice(); [f[i - 1], f[i]] = [f[i], f[i - 1]]; set(f); } }, '↑'),
                    h('button', { type: 'button', title: T('move_down'), disabled: i === chosen.length - 1, onclick: ev => { ev.preventDefault(); const f = chosen.slice(); [f[i + 1], f[i]] = [f[i], f[i + 1]]; set(f); } }, '↓')) : ''));
        });
        return list;
    }
    function offsetFields() {
        const f = (k, label) => h('label', { class: 'ld-pf' }, h('span', {}, label), h('input', { type: 'number', min: -10, max: 10, step: 0.1, value: doc.stock[k] || 0,
            oninput: e => commit(() => { doc.stock[k] = Math.max(-10, Math.min(10, parseFloat(e.target.value) || 0)); }, 'off' + k) }), 'mm');
        return h('div', {}, h('h3', {}, T('offset')), f('offset_x', T('offset_x')), f('offset_y', T('offset_y')), h('p', { class: 'ld-hint' }, T('offset_hint')));
    }

    // ---------------------------------------------------------------- saving
    async function save() {
        if (!doc.name.trim()) { showToast(T('name_needed'), 'error'); E.name.focus(); return; }
        E.save.disabled = true;
        try {
            const r = await fetch(B.api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'save_items', id: B.design.id, version, name: doc.name, stock: doc.stock, font: doc.font, items: doc.items }) });
            const d = await r.json();
            if (!d.success) throw new Error(d.error || T('save_failed'));
            version = d.version;
            template = 'custom';
            document.querySelector('.ld-banner')?.remove();
            setDirty(false);
            showToast(T('saved_toast'), 'success');
        } catch (e) {
            showToast(e.message, 'error');
        } finally {
            E.save.disabled = false;
        }
    }
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    // ---------------------------------------------------------------- start
    document.addEventListener('DOMContentLoaded', async () => {
        build();
        const img = B.logo ? await new Promise(res => { const i = new Image(); i.onload = () => res(i); i.onerror = () => res(null); i.src = B.logo; }) : null;
        logo = img;
        await window.LabelRender.loadFonts(B.base, [doc.font]);
        fit();
        schedule(true);
        E.stage.focus();
    });
})();
