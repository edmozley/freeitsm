/**
 * Projects - charts (3.3.0): the burn-up on a project's Overview, and "where the
 * money goes" and spend over time on its Budget tab. Plain SVG, no library.
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
                el('text', { x: labelW + w + 6, y: by + BAR - 2, class: 'viz-end' }, svg).textContent = opts.fmt(v) + (j === 1 && r.note ? '  ' + r.note : '');
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

    /**
     * Spend over time (3.3.0): cumulative planned and actual spend as steps, the
     * forecast as a dashed continuation of actual from today to the finish, and
     * the amounts to measure against (the budget, the baseline's budget, the
     * tolerance) as plain reference lines labelled in text colour - they are
     * thresholds, not series, so they take no series colour.
     * opts: {points: [{d, planned, actual|null}], forecast: {from, to, start, end}|null,
     *        refs: [{v, label}], today, target, fmt, fmtTick, fmtDate,
     *        series: {actual, planned, forecast}, labels: {table, chart, date, today, target, at_finish, aria}}
     */
    function spend(container, opts) {
        const pts = opts.points;
        const L = opts.labels, S = opts.series, fc = opts.forecast;
        const f = frame(container, L.table, L.chart, () => {
            const rows = pts.map(p => [opts.fmtDate(p.d), opts.fmt(p.planned), p.actual === null ? '-' : opts.fmt(p.actual)]);
            if (fc) rows.push([L.at_finish + ' (' + opts.fmtDate(fc.to) + ')', '', S.forecast + ': ' + opts.fmt(fc.end)]);
            (opts.refs || []).forEach(r => rows.push([r.label, opts.fmt(r.v), '']));
            return tableView([L.date, S.planned, S.actual], rows);
        });
        const keys = [{ name: S.actual, cls: 'viz-s1', line: true }, { name: S.planned, cls: 'viz-s2', line: true }];
        if (fc) keys.push({ name: S.forecast, cls: 'viz-s1 dash', line: true });
        f.tools.insertBefore(legend(keys), f.tools.firstChild);

        const W = Math.max(280, f.plot.clientWidth || container.clientWidth || 600), H = 250;
        const m = { l: 62, r: 16, t: 16, b: 28 };
        const pw = W - m.l - m.r, ph = H - m.t - m.b;
        const dn = s => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)) / 86400000;
        const x0 = dn(pts[0].d);
        const x1 = Math.max(dn(pts[pts.length - 1].d), fc ? dn(fc.to) : 0, opts.target ? dn(opts.target) : 0, x0 + 1);
        const top = Math.max(...pts.map(p => Math.max(p.planned, p.actual || 0)), fc ? fc.end : 0, ...(opts.refs || []).map(r => r.v), 1);
        const ny = nice(top);
        const X = d => m.l + (dn(d) - x0) / (x1 - x0) * pw;
        const Y = v => m.t + ph - (v / ny.max) * ph;

        const svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img', class: 'prj-viz-svg', tabindex: '0', 'aria-label': L.aria });
        for (let v = 0; v <= ny.max + 1e-9; v += ny.step) {
            el('line', { x1: m.l, x2: m.l + pw, y1: Y(v), y2: Y(v), class: v === 0 ? 'viz-axis' : 'viz-grid' }, svg);
            el('text', { x: m.l - 8, y: Y(v) + 4, 'text-anchor': 'end', class: 'viz-tick' }, svg).textContent = opts.fmtTick(v);
        }
        const ticks = Math.max(1, Math.min(5, Math.floor(pw / 110)));
        for (let i = 0; i <= ticks; i++) {
            const d = x0 + (x1 - x0) * i / ticks;
            const iso = new Date(Math.round(d) * 86400000).toISOString().slice(0, 10);
            el('text', { x: X(iso), y: H - 8, 'text-anchor': i === 0 ? 'start' : (i === ticks ? 'end' : 'middle'), class: 'viz-tick' }, svg).textContent = opts.fmtDate(iso);
        }
        // The amounts to measure against: dotted hairlines, labelled on the left so they never meet the
        // forecast's end. Lines closer than a label's height share one stack ABOVE the topmost of them,
        // in the same top-to-bottom order as the lines, so no label sits on another line.
        const refs = (opts.refs || []).slice().sort((a, b) => b.v - a.v).map(r => ({ r: r, y: Y(r.v) }));
        refs.forEach(x => el('line', { x1: m.l, x2: m.l + pw, y1: x.y, y2: x.y, class: 'viz-ref viz-refline' }, svg));
        const groups = [];
        refs.forEach(x => { const g = groups[groups.length - 1]; if (g && x.y - g[g.length - 1].y < 13) g.push(x); else groups.push([x]); });
        groups.forEach(g => g.forEach((x, i) => {
            el('text', { x: m.l + 6, y: g[0].y - 4 - (g.length - 1 - i) * 13, class: 'viz-reflabel' }, svg).textContent = x.r.label + ' ' + opts.fmt(x.r.v);
        }));
        if (opts.today && dn(opts.today) > x0 && dn(opts.today) < x1) {
            const tx = X(opts.today);
            el('line', { x1: tx, x2: tx, y1: m.t, y2: m.t + ph, class: 'viz-ref' }, svg);
            el('text', { x: tx + 4, y: m.t + ph - 6, class: 'viz-tick' }, svg).textContent = L.today;
        }
        // Steps: the money moves on the day, not in a slope between days.
        const step = (list, key) => list.map((p, i) => i ? 'H' + X(p.d).toFixed(1) + ' V' + Y(p[key]).toFixed(1) : 'M' + X(p.d).toFixed(1) + ' ' + Y(p[key]).toFixed(1)).join(' ');
        const act = pts.filter(p => p.actual !== null);
        // Planned carries on flat to the end of the axis: nothing more is planned after its last date.
        el('path', { d: step(pts, 'planned') + ' H' + (m.l + pw).toFixed(1), class: 'viz-line viz-s2' }, svg);
        if (act.length) {
            el('path', { d: step(act, 'actual') + ' V' + Y(0) + ' H' + X(act[0].d).toFixed(1) + ' Z', class: 'viz-area viz-s1' }, svg);
            el('path', { d: step(act, 'actual'), class: 'viz-line viz-s1' }, svg);
        }
        if (fc) {
            el('path', { d: 'M' + X(fc.from).toFixed(1) + ' ' + Y(fc.start).toFixed(1) + ' L' + X(fc.to).toFixed(1) + ' ' + Y(fc.end).toFixed(1), class: 'viz-line viz-s1 viz-dash' }, svg);
            el('circle', { cx: X(fc.to), cy: Y(fc.end), r: 4, class: 'viz-dot viz-s1' }, svg);
            el('text', { x: X(fc.to) - 6, y: Y(fc.end) - 8, 'text-anchor': 'end', class: 'viz-end halo' }, svg).textContent = opts.fmt(fc.end);
        }
        if (act.length) el('circle', { cx: X(act[act.length - 1].d), cy: Y(act[act.length - 1].actual), r: 4, class: 'viz-dot viz-s1' }, svg);
        const cross = el('line', { y1: m.t, y2: m.t + ph, class: 'viz-cross', visibility: 'hidden' }, svg);
        const hit = el('rect', { x: m.l, y: m.t, width: pw, height: ph, fill: 'transparent' }, svg);
        f.plot.appendChild(svg);
        const tip = tooltip(f.plot);

        // What the crosshair can stop on: every day the money moved, and the forecast's end.
        const stops = pts.map(p => ({ d: p.d, p: p }));
        if (fc && !stops.some(s => s.d === fc.to)) stops.push({ d: fc.to, fc: true });
        stops.sort((a, b) => a.d.localeCompare(b.d));
        const valueAt = d => { let v = pts[0]; pts.forEach(p => { if (p.d <= d) v = p; }); return v; };
        let at = stops.length - 1;
        function show(i) {
            at = Math.max(0, Math.min(stops.length - 1, i));
            const s = stops[at], cx = X(s.d), p = s.p || valueAt(s.d);
            cross.setAttribute('x1', cx); cross.setAttribute('x2', cx); cross.setAttribute('visibility', 'visible');
            tip.textContent = '';
            tip.appendChild(html('div', 'prj-viz-tip-head', opts.fmtDate(s.d)));
            if (p.actual !== null && !s.fc) tipRow(tip, opts.fmt(p.actual), S.actual, 'viz-s1');
            if (s.fc || (fc && s.d === fc.to)) tipRow(tip, opts.fmt(fc.end), S.forecast, 'viz-s1 dash');
            tipRow(tip, opts.fmt(p.planned), S.planned, 'viz-s2');
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
            stops.forEach((s, i) => { const dd = Math.abs(X(s.d) - px); if (dd < dist) { dist = dd; best = i; } });
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

    /** A series class for categorical slot n (1-6), or a neutral ('n', 'n2'). */
    const slot = n => (n >= 1 && n <= 6) ? 'viz-s' + n : (n === 'n2' ? 'viz-n2' : 'viz-n');

    /**
     * Horizontal stacked bars (3.3.0) - one row per group (a stage, a project),
     * segments per series (task statuses). Segments keep a 2px surface gap; the
     * row's total and an optional note sit at its end in text colour.
     * opts: {rows: [{label, values: [n...], note?}], series: [{name, slot}], labels: {table, chart, group, total, aria}, fmt?, max?}
     */
    function stack(container, opts) {
        const L = opts.labels, S = opts.series;
        const fmt = opts.fmt || (v => num(v));
        const f = frame(container, L.table, L.chart, () => tableView([L.group].concat(S.map(s => s.name), [L.total]),
            opts.rows.map(r => [r.label].concat(r.values.map(v => fmt(v)), [fmt(r.values.reduce((a, b) => a + b, 0))]))));
        f.tools.insertBefore(legend(S.map(s => ({ name: s.name, cls: slot(s.slot) }))), f.tools.firstChild);
        const W = Math.max(280, f.plot.clientWidth || container.clientWidth || 600);
        const labelW = Math.min(170, W * 0.3), endW = Math.min(150, W * 0.28), BAR = 18, ROW = 34;
        const H = opts.rows.length * ROW + 6, pw = W - labelW - endW;
        const max = opts.max || Math.max(1, ...opts.rows.map(r => r.values.reduce((a, b) => a + b, 0)));
        const svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img', class: 'prj-viz-svg', 'aria-label': L.aria });
        f.plot.appendChild(svg);
        const tip = tooltip(f.plot);
        opts.rows.forEach((r, i) => {
            const y = i * ROW + 8;
            el('text', { x: labelW - 10, y: y + BAR - 4, 'text-anchor': 'end', class: 'viz-label' }, svg).textContent = r.label;
            let x = labelW;
            const total = r.values.reduce((a, b) => a + b, 0);
            r.values.forEach((v, j) => {
                if (!v) return;
                const w = v / max * pw;
                const x0 = x;
                const seg = el('rect', { x: x, y: y, width: Math.max(1, w - 2), height: BAR, rx: 2, class: 'viz-bar ' + slot(S[j].slot), tabindex: '0' }, svg);
                const on = () => {
                    tip.textContent = '';
                    tip.appendChild(html('div', 'prj-viz-tip-head', r.label));
                    tipRow(tip, fmt(v), S[j].name, slot(S[j].slot));
                    tip.hidden = false;
                    tip.style.left = Math.max(0, Math.min((x0 + w / 2) / W * f.plot.clientWidth, f.plot.clientWidth - tip.offsetWidth - 4)) + 'px';
                    tip.style.top = (y / H * f.plot.clientHeight + 22) + 'px';
                };
                seg.addEventListener('pointerenter', on); seg.addEventListener('focus', on);
                seg.addEventListener('pointerleave', () => { tip.hidden = true; }); seg.addEventListener('blur', () => { tip.hidden = true; });
                x += w;
            });
            el('text', { x: x + 8, y: y + BAR - 4, class: 'viz-end' }, svg).textContent = (opts.totalText ? opts.totalText(r, total) : fmt(total)) + (r.note ? '  ' + r.note : '');
        });
    }

    /**
     * Stacked area over time (3.3.0) - the cumulative flow diagram. Series are
     * stacked bottom-up in the order given; a vertical marker can say where real
     * history starts. Crosshair + one tooltip for every band, as the burn-up.
     * opts: {points: [{d, values: [n...]}], series: [{name, slot}], marker?: {d, label}, fmtDate, labels: {table, chart, date, aria}}
     */
    function flow(container, opts) {
        const pts = opts.points, S = opts.series, L = opts.labels;
        const f = frame(container, L.table, L.chart, () => tableView([L.date].concat(S.map(s => s.name)),
            pts.map(p => [opts.fmtDate(p.d)].concat(p.values.map(v => num(v || 0))))));
        f.tools.insertBefore(legend(S.slice().reverse().map(s => ({ name: s.name, cls: slot(s.slot) }))), f.tools.firstChild);
        const W = Math.max(280, f.plot.clientWidth || container.clientWidth || 600), H = 240;
        const m = { l: 40, r: 14, t: 14, b: 28 };
        const pw = W - m.l - m.r, ph = H - m.t - m.b;
        const dn = s => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)) / 86400000;
        const x0 = dn(pts[0].d), x1 = Math.max(dn(pts[pts.length - 1].d), x0 + 1);
        const tot = pts.map(p => p.values.reduce((a, b) => a + (b || 0), 0));
        const ny = nice(Math.max(1, ...tot));
        const X = d => m.l + (dn(d) - x0) / (x1 - x0) * pw;
        const Y = v => m.t + ph - v / ny.max * ph;
        const svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img', class: 'prj-viz-svg', tabindex: '0', 'aria-label': L.aria });
        for (let v = 0; v <= ny.max + 1e-9; v += ny.step) {
            el('line', { x1: m.l, x2: m.l + pw, y1: Y(v), y2: Y(v), class: v === 0 ? 'viz-axis' : 'viz-grid' }, svg);
            el('text', { x: m.l - 8, y: Y(v) + 4, 'text-anchor': 'end', class: 'viz-tick' }, svg).textContent = num(v);
        }
        const ticks = Math.max(1, Math.min(5, Math.floor(pw / 110)));
        for (let i = 0; i <= ticks; i++) {
            const iso = new Date(Math.round(x0 + (x1 - x0) * i / ticks) * 86400000).toISOString().slice(0, 10);
            el('text', { x: X(iso), y: H - 8, 'text-anchor': i === 0 ? 'start' : (i === ticks ? 'end' : 'middle'), class: 'viz-tick' }, svg).textContent = opts.fmtDate(iso);
        }
        // Bands bottom-up: each the area between the running total below it and above it.
        const below = pts.map(() => 0);
        S.forEach((s, j) => {
            const top = pts.map((p, i) => below[i] + (p.values[j] || 0));
            if (top.every((v, i) => v === below[i])) return;
            const up = pts.map((p, i) => (i ? 'L' : 'M') + X(p.d).toFixed(1) + ' ' + Y(top[i]).toFixed(1)).join(' ');
            const down = pts.slice().reverse().map((p, k) => 'L' + X(p.d).toFixed(1) + ' ' + Y(below[pts.length - 1 - k]).toFixed(1)).join(' ');
            el('path', { d: up + ' ' + down + ' Z', class: 'viz-band ' + slot(s.slot) }, svg);
            top.forEach((v, i) => { below[i] = v; });
        });
        if (opts.marker) {
            const mx = X(opts.marker.d);
            el('line', { x1: mx, x2: mx, y1: m.t, y2: m.t + ph, class: 'viz-ref viz-refline' }, svg);
            // Past two thirds of the way across, the label sits to the left of its line, so it is never cut off.
            const leftSide = mx > m.l + pw * 0.66;
            el('text', { x: leftSide ? mx - 4 : mx + 4, y: m.t + 10, 'text-anchor': leftSide ? 'end' : 'start', class: 'viz-reflabel' }, svg).textContent = opts.marker.label;
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
            for (let j = S.length - 1; j >= 0; j--) if (p.values[j]) tipRow(tip, num(p.values[j]), S[j].name, slot(S[j].slot));
            tip.hidden = false;
            const left = cx / W * f.plot.clientWidth;
            tip.style.left = Math.min(Math.max(0, left + 12), f.plot.clientWidth - tip.offsetWidth - 4) + 'px';
            tip.style.top = '8px';
        }
        const hide = () => { cross.setAttribute('visibility', 'hidden'); tip.hidden = true; };
        hit.addEventListener('pointermove', e => {
            const r = svg.getBoundingClientRect(), px = (e.clientX - r.left) * W / r.width;
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
     * Burndown (3.3.0): the work still to do over time, against an IDEAL line
     * from where it stood on the first day to nothing at the target - above the
     * line is behind. One series (slot 1); the ideal is a dashed reference in ink.
     * opts: {points: [{d, remaining}], target, unit, fmtDate, labels: {table, chart, date, remaining, ideal, target, aria}}
     */
    function burndown(container, opts) {
        const pts = opts.points, L = opts.labels;
        const dn = s => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)) / 86400000;
        const x0 = dn(pts[0].d), xLast = dn(pts[pts.length - 1].d);
        const x1 = Math.max(xLast, opts.target ? dn(opts.target) : xLast, x0 + 1);
        const r0 = pts[0].remaining;
        // The ideal at day n: straight from r0 on the first day to 0 at the target (or the last day).
        const xEnd = opts.target ? dn(opts.target) : xLast;
        const ideal = d => xEnd <= x0 ? 0 : Math.max(0, r0 * (1 - (dn(d) - x0) / (xEnd - x0)));
        const f = frame(container, L.table, L.chart, () => tableView([L.date, L.remaining, L.ideal],
            pts.map(p => [opts.fmtDate(p.d), num(p.remaining) + opts.unit, num(ideal(p.d)) + opts.unit])));
        f.tools.insertBefore(legend([{ name: L.remaining, cls: 'viz-s1', line: true }, { name: L.ideal, cls: 'viz-n2 dash', line: true }]), f.tools.firstChild);
        const W = Math.max(280, f.plot.clientWidth || container.clientWidth || 600), H = 230;
        const m = { l: 44, r: 64, t: 14, b: 28 };
        const pw = W - m.l - m.r, ph = H - m.t - m.b;
        const ny = nice(Math.max(1, ...pts.map(p => p.remaining), r0));
        const X = d => m.l + (dn(d) - x0) / (x1 - x0) * pw;
        const Xn = n => m.l + (n - x0) / (x1 - x0) * pw;
        const Y = v => m.t + ph - (v / ny.max) * ph;
        const svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img', class: 'prj-viz-svg', tabindex: '0', 'aria-label': L.aria });
        for (let v = 0; v <= ny.max + 1e-9; v += ny.step) {
            el('line', { x1: m.l, x2: m.l + pw, y1: Y(v), y2: Y(v), class: v === 0 ? 'viz-axis' : 'viz-grid' }, svg);
            el('text', { x: m.l - 8, y: Y(v) + 4, 'text-anchor': 'end', class: 'viz-tick' }, svg).textContent = num(v);
        }
        const ticks = Math.max(1, Math.min(5, Math.floor(pw / 110)));
        for (let i = 0; i <= ticks; i++) {
            const iso = new Date(Math.round(x0 + (x1 - x0) * i / ticks) * 86400000).toISOString().slice(0, 10);
            el('text', { x: X(iso), y: H - 8, 'text-anchor': i === 0 ? 'start' : (i === ticks ? 'end' : 'middle'), class: 'viz-tick' }, svg).textContent = opts.fmtDate(iso);
        }
        if (opts.target) {
            const tx = X(opts.target);
            el('line', { x1: tx, x2: tx, y1: m.t, y2: m.t + ph, class: 'viz-ref' }, svg);
            el('text', { x: tx - 4, y: m.t + 10, 'text-anchor': 'end', class: 'viz-tick' }, svg).textContent = L.target;
        }
        el('path', { d: 'M' + Xn(x0).toFixed(1) + ' ' + Y(r0).toFixed(1) + ' L' + Xn(xEnd).toFixed(1) + ' ' + Y(0).toFixed(1), class: 'viz-line viz-ideal' }, svg);
        const path = pts.map((p, i) => (i ? 'L' : 'M') + X(p.d).toFixed(1) + ' ' + Y(p.remaining).toFixed(1)).join(' ');
        el('path', { d: path, class: 'viz-line viz-s1' }, svg);
        const last = pts[pts.length - 1];
        el('circle', { cx: X(last.d), cy: Y(last.remaining), r: 4, class: 'viz-dot viz-s1' }, svg);
        el('text', { x: X(last.d) + 8, y: Y(last.remaining) + 4, class: 'viz-end halo' }, svg).textContent = num(last.remaining) + opts.unit;
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
            tipRow(tip, num(p.remaining) + opts.unit, L.remaining, 'viz-s1');
            tipRow(tip, num(Math.round(ideal(p.d) * 10) / 10) + opts.unit, L.ideal, 'viz-n2');
            tip.hidden = false;
            const left = cx / W * f.plot.clientWidth;
            tip.style.left = Math.min(Math.max(0, left + 12), f.plot.clientWidth - tip.offsetWidth - 4) + 'px';
            tip.style.top = '8px';
        }
        const hide = () => { cross.setAttribute('visibility', 'hidden'); tip.hidden = true; };
        hit.addEventListener('pointermove', e => {
            const r = svg.getBoundingClientRect(), px = (e.clientX - r.left) * W / r.width;
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
     * Milestones across rows (3.3.0) - one row per project, a diamond per
     * milestone on a shared calendar, with today marked. State is a STATUS, so it
     * is never colour alone: reached is a filled good diamond, missed a filled
     * critical one, coming up an outline - and the legend, tooltip and table say it in words.
     * opts: {rows: [{label, items: [{d, name, state}]}], today, fmtDate, labels: {table, chart, project, milestone, date, state, states: {done, missed, due}, today, aria}}
     */
    function milestones(container, opts) {
        const L = opts.labels, rows = opts.rows;
        const f = frame(container, L.table, L.chart, () => tableView([L.project, L.milestone, L.date, L.state],
            [].concat(...rows.map(r => r.items.map(i => [r.label, i.name, opts.fmtDate(i.d), L.states[i.state] || i.state])))));
        const key = html('div', 'prj-viz-legend');
        ['done', 'missed', 'due'].forEach(s => { const k = html('span', 'prj-viz-key'); k.appendChild(html('i', 'prj-viz-dia ms-' + s)); k.appendChild(document.createTextNode(L.states[s])); key.appendChild(k); });
        f.tools.insertBefore(key, f.tools.firstChild);
        const W = Math.max(280, f.plot.clientWidth || container.clientWidth || 600);
        const labelW = Math.min(170, W * 0.3), ROW = 30, m = { t: 8, b: 26, r: 14 };
        const H = rows.length * ROW + m.t + m.b, pw = W - labelW - m.r;
        const dn = s => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)) / 86400000;
        const all = [].concat(...rows.map(r => r.items.map(i => dn(i.d)))).concat([dn(opts.today)]);
        const x0 = Math.min(...all) - 3, x1 = Math.max(...all) + 3;
        const X = d => labelW + (dn(d) - x0) / (x1 - x0) * pw;
        const svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img', class: 'prj-viz-svg', 'aria-label': L.aria });
        const ticks = Math.max(1, Math.min(5, Math.floor(pw / 110)));
        for (let i = 0; i <= ticks; i++) {
            const iso = new Date(Math.round(x0 + (x1 - x0) * i / ticks) * 86400000).toISOString().slice(0, 10);
            el('line', { x1: X(iso), x2: X(iso), y1: m.t, y2: H - m.b, class: 'viz-grid' }, svg);
            el('text', { x: X(iso), y: H - 8, 'text-anchor': i === 0 ? 'start' : (i === ticks ? 'end' : 'middle'), class: 'viz-tick' }, svg).textContent = opts.fmtDate(iso);
        }
        const tx = X(opts.today);
        el('line', { x1: tx, x2: tx, y1: m.t, y2: H - m.b, class: 'viz-ref' }, svg);
        f.plot.appendChild(svg);
        const tip = tooltip(f.plot);
        rows.forEach((r, ri) => {
            const y = m.t + ri * ROW + ROW / 2;
            el('line', { x1: labelW, x2: labelW + pw, y1: y, y2: y, class: 'viz-grid' }, svg);
            el('text', { x: labelW - 10, y: y + 4, 'text-anchor': 'end', class: 'viz-label' }, svg).textContent = r.label;
            r.items.forEach(i => {
                const cx = X(i.d), s = 6;
                const g = el('path', { d: 'M' + cx + ' ' + (y - s) + ' L' + (cx + s) + ' ' + y + ' L' + cx + ' ' + (y + s) + ' L' + (cx - s) + ' ' + y + ' Z', class: 'viz-ms ms-' + i.state, tabindex: '0' }, svg);
                const on = () => {
                    tip.textContent = '';
                    tip.appendChild(html('div', 'prj-viz-tip-head', r.label));
                    const row = html('div', 'prj-viz-tip-row');
                    row.appendChild(html('i', 'prj-viz-dia ms-' + i.state));
                    row.appendChild(html('strong', '', i.name));
                    row.appendChild(html('span', '', opts.fmtDate(i.d) + ' - ' + (L.states[i.state] || i.state)));
                    tip.appendChild(row);
                    tip.hidden = false;
                    tip.style.left = Math.max(0, Math.min(cx / W * f.plot.clientWidth + 10, f.plot.clientWidth - tip.offsetWidth - 4)) + 'px';
                    tip.style.top = (y / H * f.plot.clientHeight + 10) + 'px';
                };
                g.addEventListener('pointerenter', on); g.addEventListener('focus', on);
                g.addEventListener('pointerleave', () => { tip.hidden = true; }); g.addEventListener('blur', () => { tip.hidden = true; });
            });
        });
    }

    /**
     * Several lines over time on one money axis (3.3.0) - earned value: planned
     * value, earned value and actual cost. Up to three series (slots 1-3, the
     * all-pairs-safe set); a null value ends that line (earned value stops today).
     * Reference lines as spend(); crosshair + one tooltip for every line.
     * opts: {points: [{d, values: [n|null...]}], series: [{name, slot}], refs?: [{v, label}], today?, fmt, fmtTick, fmtDate, labels: {table, chart, date, today, aria}}
     */
    function lines(container, opts) {
        const pts = opts.points, S = opts.series, L = opts.labels;
        const f = frame(container, L.table, L.chart, () => tableView([L.date].concat(S.map(s => s.name)),
            pts.map(p => [opts.fmtDate(p.d)].concat(p.values.map(v => v === null || v === undefined ? '-' : opts.fmt(v))))));
        f.tools.insertBefore(legend(S.map(s => ({ name: s.name, cls: slot(s.slot), line: true }))), f.tools.firstChild);
        const W = Math.max(280, f.plot.clientWidth || container.clientWidth || 600), H = 250;
        const m = { l: 62, r: 16, t: 16, b: 28 };
        const pw = W - m.l - m.r, ph = H - m.t - m.b;
        const dn = s => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)) / 86400000;
        const x0 = dn(pts[0].d), x1 = Math.max(dn(pts[pts.length - 1].d), x0 + 1);
        const vals = [].concat(...pts.map(p => p.values.filter(v => v !== null && v !== undefined)));
        const ny = nice(Math.max(1, ...vals, ...(opts.refs || []).map(r => r.v)));
        const X = d => m.l + (dn(d) - x0) / (x1 - x0) * pw;
        const Y = v => m.t + ph - (v / ny.max) * ph;
        const svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img', class: 'prj-viz-svg', tabindex: '0', 'aria-label': L.aria });
        for (let v = 0; v <= ny.max + 1e-9; v += ny.step) {
            el('line', { x1: m.l, x2: m.l + pw, y1: Y(v), y2: Y(v), class: v === 0 ? 'viz-axis' : 'viz-grid' }, svg);
            el('text', { x: m.l - 8, y: Y(v) + 4, 'text-anchor': 'end', class: 'viz-tick' }, svg).textContent = opts.fmtTick(v);
        }
        const ticks = Math.max(1, Math.min(5, Math.floor(pw / 110)));
        for (let i = 0; i <= ticks; i++) {
            const iso = new Date(Math.round(x0 + (x1 - x0) * i / ticks) * 86400000).toISOString().slice(0, 10);
            el('text', { x: X(iso), y: H - 8, 'text-anchor': i === 0 ? 'start' : (i === ticks ? 'end' : 'middle'), class: 'viz-tick' }, svg).textContent = opts.fmtDate(iso);
        }
        (opts.refs || []).forEach(r => {
            el('line', { x1: m.l, x2: m.l + pw, y1: Y(r.v), y2: Y(r.v), class: 'viz-ref viz-refline' }, svg);
            el('text', { x: m.l + 6, y: Y(r.v) - 4, class: 'viz-reflabel' }, svg).textContent = r.label + ' ' + opts.fmt(r.v);
        });
        if (opts.today && dn(opts.today) > x0 && dn(opts.today) < x1) {
            const tx = X(opts.today);
            el('line', { x1: tx, x2: tx, y1: m.t, y2: m.t + ph, class: 'viz-ref' }, svg);
            el('text', { x: tx + 4, y: m.t + ph - 6, class: 'viz-tick' }, svg).textContent = L.today;
        }
        S.forEach((s, j) => {
            const seg = pts.filter(p => p.values[j] !== null && p.values[j] !== undefined);
            if (!seg.length) return;
            // opts.step: the values change ON a day (money spent, work finished) - draw steps, not slopes between days.
            const d = opts.step
                ? seg.map((p, i) => i ? 'H' + X(p.d).toFixed(1) + ' V' + Y(p.values[j]).toFixed(1) : 'M' + X(p.d).toFixed(1) + ' ' + Y(p.values[j]).toFixed(1)).join(' ')
                : seg.map((p, i) => (i ? 'L' : 'M') + X(p.d).toFixed(1) + ' ' + Y(p.values[j]).toFixed(1)).join(' ');
            el('path', { d: d, class: 'viz-line ' + slot(s.slot) }, svg);
            const last = seg[seg.length - 1];
            el('circle', { cx: X(last.d), cy: Y(last.values[j]), r: 4, class: 'viz-dot ' + slot(s.slot) }, svg);
        });
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
            S.forEach((s, j) => { if (p.values[j] !== null && p.values[j] !== undefined) tipRow(tip, opts.fmt(p.values[j]), s.name, slot(s.slot)); });
            tip.hidden = false;
            const left = cx / W * f.plot.clientWidth;
            tip.style.left = Math.min(Math.max(0, left + 12), f.plot.clientWidth - tip.offsetWidth - 4) + 'px';
            tip.style.top = '8px';
        }
        const hide = () => { cross.setAttribute('visibility', 'hidden'); tip.hidden = true; };
        hit.addEventListener('pointermove', e => {
            const r = svg.getBoundingClientRect(), px = (e.clientX - r.left) * W / r.width;
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

    window.PrjCharts = { burnup: burnup, burndown: burndown, bars: bars, spend: spend, stack: stack, flow: flow, milestones: milestones, lines: lines, slot: slot };




})();
