/**
 * Projects - charts (3.3.0): the burn-up on a project's Overview and "where the
 * money goes" on its Budget tab. Plain SVG, no library.
 *
 * The rules they follow (the dataviz method, checked 2026-10-09):
 *  - Two categorical series, slots 1 and 2 (blue, orange) - validated for
 *    colour-blind separation and contrast on the app's own light (#fff) and
 *    dark (#1e2228) surfaces; the dark steps are chosen, not flipped
 *    (--prj-viz-1 / --prj-viz-2 in projects.css).
 *  - Thin marks: 2px lines, bars <= 24px with rounded data ends, 4px end dots
 *    with a 2px surface ring; hairline solid gridlines.
 *  - A legend for every two-series chart; values selectively (line ends, bar
 *    tips); text in text colours, never the series colour.
 *  - A hover layer (crosshair + one tooltip for both series on the line; per
 *    bar on the bars), the same on keyboard focus, and a table view - the
 *    tooltip never holds a value nobody can reach otherwise.
 *  - Names go in with textContent (they are users' words).
 */
(function () {
    'use strict';
    const NS = 'http://www.w3.org/2000/svg';
    const lang = document.documentElement.lang || undefined;
    const num = v => (Math.round(v * 10) / 10).toLocaleString(lang);

    function el(name, attrs, parent) {
        const e = document.createElementNS(NS, name);
        Object.entries(attrs || {}).forEach(([k, v]) => e.setAttribute(k, v));
        if (parent) parent.appendChild(e);
        return e;
    }
    function html(tag, cls, text) {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;
        return e;
    }
    /** A clean axis maximum and step: 0 / 5 / 10 / 15, 0 / 2,000 / 4,000. */
    function nice(max) {
        if (max <= 0) return { max: 1, step: 1 };
        const raw = max / 4, pow = Math.pow(10, Math.floor(Math.log10(raw)));
        const step = [1, 2, 2.5, 5, 10].map(m => m * pow).find(s => s >= raw) || 10 * pow;
        return { max: Math.ceil(max / step) * step, step: step };
    }
    function legend(series) {
        const box = html('div', 'prj-viz-legend');
        series.forEach(s => {
            const item = html('span', 'prj-viz-key');
            item.appendChild(html('i', 'prj-viz-swatch ' + s.cls + (s.line ? ' line' : '')));
            item.appendChild(document.createTextNode(s.name));
            box.appendChild(item);
        });
        return box;
    }
    function tableView(head, rows) {
        const wrap = html('div', 'prj-viz-table');
        const t = html('table');
        const tr = html('tr');
        head.forEach((h, i) => tr.appendChild(html('th', i ? 'num' : '', h)));
        const thead = html('thead'); thead.appendChild(tr); t.appendChild(thead);
        const tb = html('tbody');
        rows.forEach(r => { const row = html('tr'); r.forEach((c, i) => row.appendChild(html('td', i ? 'num' : '', c))); tb.appendChild(row); });
        t.appendChild(tb); wrap.appendChild(t);
        return wrap;
    }
    /** The chart / table switch, and where the chart draws. */
    function frame(container, tableLabel, chartLabel, buildTable) {
        container.textContent = '';
        const tools = html('div', 'prj-viz-tools');
        const toggle = html('button', 'prj-link prj-viz-toggle', tableLabel);
        toggle.type = 'button';
        tools.appendChild(toggle);
        const plot = html('div', 'prj-viz-plot');
        container.appendChild(tools);
        container.appendChild(plot);
        let table = null;
        toggle.addEventListener('click', () => {
            const showTable = !table;
            if (showTable) { table = buildTable(); container.appendChild(table); } else { table.remove(); table = null; }
            plot.hidden = showTable;
            toggle.textContent = showTable ? chartLabel : tableLabel;
        });
        return { tools: tools, plot: plot };
    }
    function tooltip(plot) {
        const tip = html('div', 'prj-viz-tip');
        tip.hidden = true;
        plot.appendChild(tip);
        return tip;
    }
    function tipRow(tip, value, name, cls) {
        const row = html('div', 'prj-viz-tip-row');
        row.appendChild(html('i', 'prj-viz-swatch line ' + cls));
        row.appendChild(html('strong', '', value));
        row.appendChild(html('span', '', name));
        tip.appendChild(row);
    }

    /**
     * Burn-up: scope (everything in the project so far) and done, over time, with
     * the target finish as a reference line.
     * opts: {points: [{d: 'Y-m-d', scope, done}], target, series: [scopeName, doneName],
     *        unit: '' | 'h', fmtDate, labels: {table, chart, date, target, aria}}
     */
    function burnup(container, opts) {
        const pts = opts.points;
        const L = opts.labels;
        const f = frame(container, L.table, L.chart, () => tableView([L.date, opts.series[0], opts.series[1]],
            pts.map(p => [opts.fmtDate(p.d), num(p.scope) + opts.unit, num(p.done) + opts.unit])));
        f.tools.insertBefore(legend([{ name: opts.series[1], cls: 'viz-s1', line: true }, { name: opts.series[0], cls: 'viz-s2', line: true }]), f.tools.firstChild);

        const W = Math.max(280, f.plot.clientWidth || container.clientWidth || 600), H = 230;
        const m = { l: 44, r: 64, t: 14, b: 28 };
        const pw = W - m.l - m.r, ph = H - m.t - m.b;
        const dn = s => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)) / 86400000;
        const x0 = dn(pts[0].d), xLast = dn(pts[pts.length - 1].d);
        const x1 = Math.max(xLast, opts.target ? dn(opts.target) : xLast, x0 + 1);
        const ny = nice(Math.max(...pts.map(p => p.scope), 1));
        const X = d => m.l + (dn(d) - x0) / (x1 - x0) * pw;
        const Y = v => m.t + ph - (v / ny.max) * ph;

        const svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img', class: 'prj-viz-svg', tabindex: '0', 'aria-label': L.aria });
        for (let v = 0; v <= ny.max + 1e-9; v += ny.step) {
            el('line', { x1: m.l, x2: m.l + pw, y1: Y(v), y2: Y(v), class: v === 0 ? 'viz-axis' : 'viz-grid' }, svg);
            el('text', { x: m.l - 8, y: Y(v) + 4, 'text-anchor': 'end', class: 'viz-tick' }, svg).textContent = num(v);
        }
        // As many date labels as fit - about one per 110px - so they never collide on a phone.
        const ticks = Math.max(1, Math.min(5, Math.floor(pw / 110)));
        for (let i = 0; i <= ticks; i++) {
            const d = x0 + (x1 - x0) * i / ticks;
            const iso = new Date(Math.round(d) * 86400000).toISOString().slice(0, 10);
            el('text', { x: X(iso), y: H - 8, 'text-anchor': i === 0 ? 'start' : (i === ticks ? 'end' : 'middle'), class: 'viz-tick' }, svg).textContent = opts.fmtDate(iso);
        }
        if (opts.target) {
            const tx = X(opts.target);
            el('line', { x1: tx, x2: tx, y1: m.t, y2: m.t + ph, class: 'viz-ref' }, svg);
            el('text', { x: tx - 4, y: m.t + 10, 'text-anchor': 'end', class: 'viz-tick' }, svg).textContent = L.target;
        }
        const path = key => pts.map((p, i) => (i ? 'L' : 'M') + X(p.d).toFixed(1) + ' ' + Y(p[key]).toFixed(1)).join(' ');
        el('path', { d: path('done') + ' L' + X(pts[pts.length - 1].d).toFixed(1) + ' ' + Y(0) + ' L' + X(pts[0].d).toFixed(1) + ' ' + Y(0) + ' Z', class: 'viz-area viz-s1' }, svg);
        el('path', { d: path('scope'), class: 'viz-line viz-s2' }, svg);
        el('path', { d: path('done'), class: 'viz-line viz-s1' }, svg);
        const last = pts[pts.length - 1];
        [['scope', 'viz-s2'], ['done', 'viz-s1']].forEach(([k, cls]) => el('circle', { cx: X(last.d), cy: Y(last[k]), r: 4, class: 'viz-dot ' + cls }, svg));
        // End labels only when they stand apart; otherwise the legend and tooltip carry them.
        if (Math.abs(Y(last.scope) - Y(last.done)) >= 14) {
            [['scope', 0], ['done', 1]].forEach(([k, i]) => el('text', { x: X(last.d) + 8, y: Y(last[k]) + 4, class: 'viz-end' }, svg).textContent = num(last[k]) + opts.unit);
        }
        const cross = el('line', { y1: m.t, y2: m.t + ph, class: 'viz-cross', visibility: 'hidden' }, svg);
        const hit = el('rect', { x: m.l, y: m.t, width: pw, height: ph, fill: 'transparent' }, svg);
        f.plot.appendChild(svg);
        const tip = tooltip(f.plot);

        let at = pts.length - 1;
        function show(i) {
            at = Math.max(0, Math.min(pts.length - 1, i));
            const p = pts[at], cx = X(p.d);
            cross.setAttribute('x1', cx); cross.setAttribute('x2', cx); cross.setAttribute('visibility', 'visible');
            tip.textContent = '';
            tip.appendChild(html('div', 'prj-viz-tip-head', opts.fmtDate(p.d)));
            tipRow(tip, num(p.done) + opts.unit, opts.series[1], 'viz-s1');
            tipRow(tip, num(p.scope) + opts.unit, opts.series[0], 'viz-s2');
            tip.hidden = false;
            const left = cx / W * f.plot.clientWidth;
            tip.style.left = Math.min(Math.max(0, left + 12), f.plot.clientWidth - tip.offsetWidth - 4) + 'px';
            tip.style.top = '8px';
        }
        function hide() { cross.setAttribute('visibility', 'hidden'); tip.hidden = true; }
        hit.addEventListener('pointermove', e => {
            const r = svg.getBoundingClientRect();
            const px = (e.clientX - r.left) * W / r.width;
            let best = 0, dist = Infinity;
            pts.forEach((p, i) => { const dd = Math.abs(X(p.d) - px); if (dd < dist) { dist = dd; best = i; } });
            show(best);
        });
        hit.addEventListener('pointerleave', hide);
        svg.addEventListener('focus', () => show(at));
        svg.addEventListener('blur', hide);
        svg.addEventListener('keydown', e => {
            if (e.key === 'ArrowLeft') { e.preventDefault(); show(at - 1); }
            if (e.key === 'ArrowRight') { e.preventDefault(); show(at + 1); }
        });
    }

    /**
     * Grouped horizontal bars - two values per category (planned, actual).
     * opts: {rows: [{label, a, b}], series: [aName, bName], fmt: v => text, labels: {table, chart, category, aria}}
     * A missing value (null) draws no bar and reads "-".
     */
    function bars(container, opts) {
        const L = opts.labels;
        const f = frame(container, L.table, L.chart, () => tableView([L.category, opts.series[0], opts.series[1]],
            opts.rows.map(r => [r.label, r.a === null ? '-' : opts.fmt(r.a), r.b === null ? '-' : opts.fmt(r.b)])));
        f.tools.insertBefore(legend([{ name: opts.series[0], cls: 'viz-s1' }, { name: opts.series[1], cls: 'viz-s2' }]), f.tools.firstChild);
        const W = Math.max(280, f.plot.clientWidth || container.clientWidth || 600);
        const labelW = Math.min(150, W * 0.3), valW = 92, BAR = 12, GAP = 2, ROW = BAR * 2 + GAP + 18;
        const H = opts.rows.length * ROW + 8;
        const pw = W - labelW - valW;
        const nx = nice(Math.max(...opts.rows.map(r => Math.max(r.a || 0, r.b || 0)), 1));
        const svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img', class: 'prj-viz-svg', 'aria-label': L.aria });
        for (let v = 0; v <= nx.max + 1e-9; v += nx.step) {
            const gx = labelW + v / nx.max * pw;
            el('line', { x1: gx, x2: gx, y1: 0, y2: H - 4, class: v === 0 ? 'viz-axis' : 'viz-grid' }, svg);
        }
        f.plot.appendChild(svg);
        const tip = tooltip(f.plot);
        opts.rows.forEach((r, i) => {
            const y = i * ROW + 6;
            el('text', { x: labelW - 10, y: y + BAR + 4, 'text-anchor': 'end', class: 'viz-label' }, svg).textContent = r.label;
            [['a', 'viz-s1', 0], ['b', 'viz-s2', 1]].forEach(([k, cls, j]) => {
                const v = r[k];
                if (v === null || v === undefined) return;
                const w = Math.max(v > 0 ? 3 : 0, v / nx.max * pw);
                const by = y + j * (BAR + GAP);
                // Square at the baseline, a 4px rounded data end.
                const rad = Math.min(4, w / 2);
                el('path', { d: 'M' + labelW + ' ' + by + ' h' + (w - rad) + ' a' + rad + ' ' + rad + ' 0 0 1 ' + rad + ' ' + rad + ' v' + (BAR - 2 * rad) + ' a' + rad + ' ' + rad + ' 0 0 1 -' + rad + ' ' + rad + ' h-' + (w - rad) + ' Z', class: 'viz-bar ' + cls }, svg);
                el('text', { x: labelW + w + 6, y: by + BAR - 2, class: 'viz-end' }, svg).textContent = opts.fmt(v);
                const hit = el('rect', { x: labelW, y: by - 1, width: Math.max(w + 70, 24), height: BAR + 2, fill: 'transparent', tabindex: '0', class: 'viz-hit' }, svg);
                const on = () => {
                    tip.textContent = '';
                    tip.appendChild(html('div', 'prj-viz-tip-head', r.label));
                    tipRow(tip, opts.fmt(v), opts.series[j], cls);
                    tip.hidden = false;
                    tip.style.left = Math.min((labelW + w + 10) / W * f.plot.clientWidth, f.plot.clientWidth - tip.offsetWidth - 4) + 'px';
                    tip.style.top = (by / H * f.plot.clientHeight + 16) + 'px';
                };
                hit.addEventListener('pointerenter', on); hit.addEventListener('focus', on);
                hit.addEventListener('pointerleave', () => { tip.hidden = true; }); hit.addEventListener('blur', () => { tip.hidden = true; });
            });
        });
    }

    window.PrjCharts = { burnup: burnup, bars: bars };
})();
