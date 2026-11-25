/**
 * Network Mapper — the shape library and the style renderer (GitHub #433).
 *
 * Freestanding shapes (rectangles, flowchart symbols, arrows, zones, text, notes) that sit on
 * a diagram beside the CMDB nodes. Each shape is drawn at its REAL size (w × h in canvas px)
 * rather than a stretched 100×100 picture, so curves, corners and borders keep their proportions
 * however a shape is resized.
 *
 *   window.NM_SHAPES          key -> { cat, w, h, draw(w,h,r) -> {d, lines?}, text?(w,h) -> box,
 *                                      rounded?, style? (defaults that differ from SHAPE_STYLE) }
 *   window.NM_SHAPE_CATS      the palette's groups, in order
 *   window.nmShapeStyle(type, style)   the full style: library defaults + the shape's own
 *   window.nmShapeSvg(shape, idPrefix) the <svg> markup for one shape (fill, gradient, border, shadow)
 *   window.nmShapePreview(type, size)  a small palette preview
 *   window.nmTextCss(style)            inline CSS for the shape's (or a node label's) text
 *   window.nmNodeBoxCss(style)         inline CSS for a CMDB node box styled with the same keys
 *
 * Style keys (the server keeps only these - NetworkMapperService::sanitiseStyle):
 *   fill none|solid|gradient, fillColour, fillColour2, gradientDir down|right|diag|radial,
 *   stroke none|solid|dashed|dotted, strokeColour, strokeWidth, opacity 0-100, shadow,
 *   rounded (px), font, fontSize, fontColour, bold, italic, underline, align, valign.
 *
 * An unknown shape_type draws as a rectangle, so a diagram saved by a newer version still opens.
 */
(function () {
    'use strict';

    // ---- geometry helpers ------------------------------------------------------------------
    const f = n => Math.round(n * 100) / 100;
    /** A closed polygon through [x, y] points. */
    function poly(pts) { return 'M' + pts.map(p => f(p[0]) + ',' + f(p[1])).join(' L') + ' Z'; }
    /** A rectangle with corner radius r (clamped to half the shorter side). */
    function rrect(x, y, w, h, r) {
        r = Math.max(0, Math.min(r || 0, w / 2, h / 2));
        if (!r) return poly([[x, y], [x + w, y], [x + w, y + h], [x, y + h]]);
        return 'M' + f(x + r) + ',' + f(y) + ' H' + f(x + w - r) + ' A' + f(r) + ',' + f(r) + ' 0 0 1 ' + f(x + w) + ',' + f(y + r)
            + ' V' + f(y + h - r) + ' A' + f(r) + ',' + f(r) + ' 0 0 1 ' + f(x + w - r) + ',' + f(y + h)
            + ' H' + f(x + r) + ' A' + f(r) + ',' + f(r) + ' 0 0 1 ' + f(x) + ',' + f(y + h - r)
            + ' V' + f(y + r) + ' A' + f(r) + ',' + f(r) + ' 0 0 1 ' + f(x + r) + ',' + f(y) + ' Z';
    }
    function ellipse(x, y, w, h) {
        const rx = w / 2, ry = h / 2;
        return 'M' + f(x) + ',' + f(y + ry) + ' A' + f(rx) + ',' + f(ry) + ' 0 1 0 ' + f(x + w) + ',' + f(y + ry)
            + ' A' + f(rx) + ',' + f(ry) + ' 0 1 0 ' + f(x) + ',' + f(y + ry) + ' Z';
    }
    /**
     * Scales a path written in a 100 × bh box (absolute M / L / C / Q / Z commands only, numbers in
     * x,y pairs) to w × h. Used for the organic shapes - cloud, heart, document waves.
     */
    function scaled(tpl, w, h, bh) {
        const sx = w / 100, sy = h / (bh || 100);
        let i = 0;
        return tpl.replace(/-?\d+(\.\d+)?/g, m => f(parseFloat(m) * (i++ % 2 === 0 ? sx : sy)));
    }
    /** A right-pointing block arrow in a len × thick box, as points. */
    function arrowPts(len, thick) {
        const a = Math.min(len * 0.45, thick), t = thick * 0.5;
        return [[0, thick / 2 - t / 2], [len - a, thick / 2 - t / 2], [len - a, 0], [len, thick / 2],
                [len - a, thick], [len - a, thick / 2 + t / 2], [0, thick / 2 + t / 2]];
    }
    function star(w, h, n, inner) {
        const pts = [];
        for (let i = 0; i < n * 2; i++) {
            const a = -Math.PI / 2 + i * Math.PI / n;
            const rr = i % 2 === 0 ? 1 : inner;
            pts.push([w / 2 + Math.cos(a) * rr * w / 2, h / 2 + Math.sin(a) * rr * h / 2 + (n === 5 ? h * 0.05 : 0)]);
        }
        return poly(pts);
    }
    const box = (x, y, w, h) => ({ x, y, w, h });

    // ---- the library ------------------------------------------------------------------------
    // w/h: the size a shape is dropped at. text(w,h): where its text sits (default: the whole
    // shape, inset). rounded: the Rounded corners control applies. style: defaults that differ
    // from SHAPE_STYLE below.
    const S = {};
    const add = (key, def) => { S[key] = def; };

    // Basic
    add('rectangle',         { cat: 'basic', w: 120, h: 70, rounded: true, draw: (w, h, r) => ({ d: rrect(0, 0, w, h, r) }) });
    add('rounded_rectangle', { cat: 'basic', w: 120, h: 70, rounded: true, style: { rounded: 12 }, draw: (w, h, r) => ({ d: rrect(0, 0, w, h, r) }) });
    add('ellipse',           { cat: 'basic', w: 120, h: 80, draw: (w, h) => ({ d: ellipse(0, 0, w, h) }), text: (w, h) => box(w * 0.15, h * 0.15, w * 0.7, h * 0.7) });
    add('circle',            { cat: 'basic', w: 80, h: 80, draw: (w, h) => ({ d: ellipse(0, 0, w, h) }), text: (w, h) => box(w * 0.15, h * 0.15, w * 0.7, h * 0.7) });
    add('triangle',          { cat: 'basic', w: 100, h: 90, draw: (w, h) => ({ d: poly([[w / 2, 0], [w, h], [0, h]]) }), text: (w, h) => box(w * 0.25, h * 0.45, w * 0.5, h * 0.5) });
    add('right_triangle',    { cat: 'basic', w: 90, h: 90, draw: (w, h) => ({ d: poly([[0, 0], [w, h], [0, h]]) }), text: (w, h) => box(0, h * 0.5, w * 0.5, h * 0.5) });
    add('diamond',           { cat: 'basic', w: 110, h: 90, draw: (w, h) => ({ d: poly([[w / 2, 0], [w, h / 2], [w / 2, h], [0, h / 2]]) }), text: (w, h) => box(w * 0.2, h * 0.2, w * 0.6, h * 0.6) });
    add('pentagon',          { cat: 'basic', w: 100, h: 95, draw: (w, h) => ({ d: poly([[w / 2, 0], [w, h * 0.38], [w * 0.81, h], [w * 0.19, h], [0, h * 0.38]]) }) });
    add('hexagon',           { cat: 'basic', w: 110, h: 90, draw: (w, h) => ({ d: poly([[w * 0.25, 0], [w * 0.75, 0], [w, h / 2], [w * 0.75, h], [w * 0.25, h], [0, h / 2]]) }), text: (w, h) => box(w * 0.2, 0, w * 0.6, h) });
    add('octagon',           { cat: 'basic', w: 100, h: 100, draw: (w, h) => { const k = Math.min(w, h) * 0.29; return { d: poly([[k, 0], [w - k, 0], [w, k], [w, h - k], [w - k, h], [k, h], [0, h - k], [0, k]]) }; } });
    add('parallelogram',     { cat: 'basic', w: 130, h: 70, draw: (w, h) => { const s = Math.min(w * 0.25, h * 0.5); return { d: poly([[s, 0], [w, 0], [w - s, h], [0, h]]) }; }, text: (w, h) => box(Math.min(w * 0.25, h * 0.5), 0, w - 2 * Math.min(w * 0.25, h * 0.5), h) });
    add('trapezoid',         { cat: 'basic', w: 130, h: 70, draw: (w, h) => ({ d: poly([[w * 0.2, 0], [w * 0.8, 0], [w, h], [0, h]]) }) });
    add('star',              { cat: 'basic', w: 100, h: 95, draw: (w, h) => ({ d: star(w, h, 5, 0.42) }), text: (w, h) => box(w * 0.3, h * 0.35, w * 0.4, h * 0.35) });
    add('cross',             { cat: 'basic', w: 90, h: 90, draw: (w, h) => { const tx = w / 3, ty = h / 3; return { d: poly([[tx, 0], [w - tx, 0], [w - tx, ty], [w, ty], [w, h - ty], [w - tx, h - ty], [w - tx, h], [tx, h], [tx, h - ty], [0, h - ty], [0, ty], [tx, ty]]) }; } });
    add('cloud',             { cat: 'basic', w: 140, h: 90, draw: (w, h) => ({ d: scaled('M25,60 C10,60 0,51 0,40 C0,29 9,21 21,22 C23,10 35,0 49,0 C62,0 72,8 76,18 C89,16 100,27 100,39 C100,51 90,60 78,60 Z', w, h, 60) }), text: (w, h) => box(w * 0.15, h * 0.3, w * 0.7, h * 0.6) });
    add('heart',             { cat: 'basic', w: 90, h: 85, draw: (w, h) => ({ d: scaled('M50,95 C20,75 0,55 0,32 C0,14 14,0 30,0 C40,0 47,6 50,14 C53,6 60,0 70,0 C86,0 100,14 100,32 C100,55 80,75 50,95 Z', w, h, 95) }), text: (w, h) => box(w * 0.2, h * 0.2, w * 0.6, h * 0.45) });
    add('cylinder',          { cat: 'basic', w: 90, h: 110, draw: (w, h) => { const e = Math.min(h * 0.15, w * 0.25, 22); return { d: 'M0,' + f(e) + ' A' + f(w / 2) + ',' + f(e) + ' 0 0 1 ' + f(w) + ',' + f(e) + ' V' + f(h - e) + ' A' + f(w / 2) + ',' + f(e) + ' 0 0 1 0,' + f(h - e) + ' Z', lines: 'M0,' + f(e) + ' A' + f(w / 2) + ',' + f(e) + ' 0 0 0 ' + f(w) + ',' + f(e) }; }, text: (w, h) => { const e = Math.min(h * 0.15, w * 0.25, 22); return box(0, e * 2, w, h - e * 3); } });
    add('cube',              { cat: 'basic', w: 110, h: 100, draw: (w, h) => { const d = Math.min(w, h) * 0.2; return { d: poly([[0, d], [d, 0], [w, 0], [w, h - d], [w - d, h], [0, h]]), lines: 'M0,' + f(d) + ' L' + f(w - d) + ',' + f(d) + ' L' + f(w) + ',0 M' + f(w - d) + ',' + f(d) + ' L' + f(w - d) + ',' + f(h) }; }, text: (w, h) => { const d = Math.min(w, h) * 0.2; return box(0, d, w - d, h - d); } });

    // Flowchart
    add('process',           { cat: 'flow', w: 120, h: 60, rounded: true, draw: (w, h, r) => ({ d: rrect(0, 0, w, h, r) }) });
    add('decision',          { cat: 'flow', w: 120, h: 80, draw: (w, h) => ({ d: poly([[w / 2, 0], [w, h / 2], [w / 2, h], [0, h / 2]]) }), text: (w, h) => box(w * 0.2, h * 0.2, w * 0.6, h * 0.6) });
    add('terminator',        { cat: 'flow', w: 120, h: 50, draw: (w, h) => ({ d: rrect(0, 0, w, h, h / 2) }), text: (w, h) => box(h * 0.3, 0, w - h * 0.6, h) });
    add('data',              { cat: 'flow', w: 130, h: 60, draw: (w, h) => { const s = Math.min(w * 0.2, h * 0.5); return { d: poly([[s, 0], [w, 0], [w - s, h], [0, h]]) }; }, text: (w, h) => box(Math.min(w * 0.2, h * 0.5), 0, w - 2 * Math.min(w * 0.2, h * 0.5), h) });
    add('predefined_process', { cat: 'flow', w: 130, h: 60, draw: (w, h) => { const k = Math.min(w * 0.1, 14); return { d: poly([[0, 0], [w, 0], [w, h], [0, h]]), lines: 'M' + f(k) + ',0 V' + f(h) + ' M' + f(w - k) + ',0 V' + f(h) }; }, text: (w, h) => { const k = Math.min(w * 0.1, 14); return box(k, 0, w - 2 * k, h); } });
    add('document',          { cat: 'flow', w: 120, h: 80, draw: (w, h) => ({ d: scaled('M0,0 L100,0 L100,85 C75,70 50,100 25,92 C15,89 7,86 0,88 Z', w, h) }), text: (w, h) => box(0, 0, w, h * 0.8) });
    add('multi_document',    { cat: 'flow', w: 130, h: 90, draw: (w, h) => { const o = Math.min(w, h) * 0.08; return { d: scaled('M0,' + 0 + ' L100,0 L100,85 C75,70 50,100 25,92 C15,89 7,86 0,88 Z', w - 2 * o, h - 2 * o).replace(/-?\d+(\.\d+)?,-?\d+(\.\d+)?/g, p => { const [x, y] = p.split(',').map(Number); return f(x) + ',' + f(y + 2 * o); }), lines: 'M' + f(o) + ',' + f(2 * o) + ' V' + f(o) + ' H' + f(w - o) + ' V' + f((h - 2 * o) * 0.85 + o) + ' H' + f(w - 2 * o) + ' M' + f(2 * o) + ',' + f(o) + ' V0 H' + f(w) + ' V' + f((h - 2 * o) * 0.85) + ' H' + f(w - o) }; }, text: (w, h) => { const o = Math.min(w, h) * 0.08; return box(0, 2 * o, w - 2 * o, (h - 2 * o) * 0.8); } });
    add('manual_input',      { cat: 'flow', w: 120, h: 70, draw: (w, h) => ({ d: poly([[0, h * 0.3], [w, 0], [w, h], [0, h]]) }), text: (w, h) => box(0, h * 0.3, w, h * 0.7) });
    add('manual_operation',  { cat: 'flow', w: 130, h: 60, draw: (w, h) => ({ d: poly([[0, 0], [w, 0], [w * 0.8, h], [w * 0.2, h]]) }), text: (w, h) => box(w * 0.15, 0, w * 0.7, h) });
    add('preparation',       { cat: 'flow', w: 130, h: 60, draw: (w, h) => { const s = Math.min(w * 0.15, h * 0.5); return { d: poly([[s, 0], [w - s, 0], [w, h / 2], [w - s, h], [s, h], [0, h / 2]]) }; } });
    add('delay',             { cat: 'flow', w: 110, h: 60, draw: (w, h) => { const rx = Math.min(w * 0.5, h / 2), x0 = w - rx; return { d: 'M0,0 H' + f(x0) + ' A' + f(rx) + ',' + f(h / 2) + ' 0 0 1 ' + f(x0) + ',' + f(h) + ' H0 Z' }; } });
    add('display',           { cat: 'flow', w: 130, h: 60, draw: (w, h) => { const k = Math.min(w * 0.15, h); return { d: 'M0,' + f(h / 2) + ' L' + f(k) + ',0 H' + f(w - k) + ' A' + f(k) + ',' + f(h / 2) + ' 0 0 1 ' + f(w - k) + ',' + f(h) + ' H' + f(k) + ' Z' }; }, text: (w, h) => box(w * 0.15, 0, w * 0.7, h) });
    add('stored_data',       { cat: 'flow', w: 130, h: 60, draw: (w, h) => { const k = Math.min(w * 0.12, h * 0.3); return { d: 'M' + f(k) + ',0 H' + f(w) + ' A' + f(k) + ',' + f(h / 2) + ' 0 0 0 ' + f(w) + ',' + f(h) + ' H' + f(k) + ' A' + f(k) + ',' + f(h / 2) + ' 0 0 1 ' + f(k) + ',0 Z' }; }, text: (w, h) => box(w * 0.12, 0, w * 0.76, h) });
    add('internal_storage',  { cat: 'flow', w: 110, h: 80, draw: (w, h) => { const kx = Math.min(w * 0.15, 16), ky = Math.min(h * 0.2, 16); return { d: poly([[0, 0], [w, 0], [w, h], [0, h]]), lines: 'M' + f(kx) + ',0 V' + f(h) + ' M0,' + f(ky) + ' H' + f(w) }; }, text: (w, h) => box(Math.min(w * 0.15, 16), Math.min(h * 0.2, 16), w - Math.min(w * 0.15, 16), h - Math.min(h * 0.2, 16)) });
    add('off_page',          { cat: 'flow', w: 80, h: 80, draw: (w, h) => ({ d: poly([[0, 0], [w, 0], [w, h * 0.7], [w / 2, h], [0, h * 0.7]]) }), text: (w, h) => box(0, 0, w, h * 0.7) });
    add('connector',         { cat: 'flow', w: 44, h: 44, draw: (w, h) => ({ d: ellipse(0, 0, w, h) }) });
    add('merge',             { cat: 'flow', w: 100, h: 80, draw: (w, h) => ({ d: poly([[0, 0], [w, 0], [w / 2, h]]) }), text: (w, h) => box(w * 0.25, 0, w * 0.5, h * 0.5) });
    add('card',              { cat: 'flow', w: 110, h: 70, draw: (w, h) => { const c = Math.min(w, h) * 0.25; return { d: poly([[c, 0], [w, 0], [w, h], [0, h], [0, c]]) }; } });
    add('punched_tape',      { cat: 'flow', w: 120, h: 80, draw: (w, h) => ({ d: scaled('M0,10 C25,25 25,-5 50,10 C75,25 75,-5 100,10 L100,90 C75,75 75,105 50,90 C25,75 25,105 0,90 Z', w, h) }), text: (w, h) => box(0, h * 0.15, w, h * 0.7) });
    add('or',                { cat: 'flow', w: 60, h: 60, draw: (w, h) => ({ d: ellipse(0, 0, w, h), lines: 'M' + f(w / 2) + ',0 V' + f(h) + ' M0,' + f(h / 2) + ' H' + f(w) }), text: () => box(0, 0, 0, 0) });
    add('summing_junction',  { cat: 'flow', w: 60, h: 60, draw: (w, h) => { const k = 0.1464; return { d: ellipse(0, 0, w, h), lines: 'M' + f(w * k) + ',' + f(h * k) + ' L' + f(w * (1 - k)) + ',' + f(h * (1 - k)) + ' M' + f(w * (1 - k)) + ',' + f(h * k) + ' L' + f(w * k) + ',' + f(h * (1 - k)) }; }, text: () => box(0, 0, 0, 0) });
    add('collate',           { cat: 'flow', w: 80, h: 80, draw: (w, h) => ({ d: 'M0,0 L' + f(w) + ',0 L0,' + f(h) + ' L' + f(w) + ',' + f(h) + ' Z' }), text: () => box(0, 0, 0, 0) });
    add('sort',              { cat: 'flow', w: 90, h: 90, draw: (w, h) => ({ d: poly([[w / 2, 0], [w, h / 2], [w / 2, h], [0, h / 2]]), lines: 'M0,' + f(h / 2) + ' H' + f(w) }), text: () => box(0, 0, 0, 0) });
    add('loop_limit',        { cat: 'flow', w: 120, h: 60, draw: (w, h) => { const c = Math.min(w, h) * 0.3; return { d: poly([[c, 0], [w - c, 0], [w, c], [w, h], [0, h], [0, c]]) }; } });

    // Arrows
    add('arrow_right',       { cat: 'arrows', w: 120, h: 60, draw: (w, h) => ({ d: poly(arrowPts(w, h)) }), text: (w, h) => box(0, h * 0.25, w * 0.6, h * 0.5) });
    add('arrow_left',        { cat: 'arrows', w: 120, h: 60, draw: (w, h) => ({ d: poly(arrowPts(w, h).map(p => [w - p[0], p[1]])) }), text: (w, h) => box(w * 0.4, h * 0.25, w * 0.6, h * 0.5) });
    add('arrow_up',          { cat: 'arrows', w: 60, h: 120, draw: (w, h) => ({ d: poly(arrowPts(h, w).map(p => [p[1], h - p[0]])) }), text: (w, h) => box(w * 0.25, h * 0.4, w * 0.5, h * 0.6) });
    add('arrow_down',        { cat: 'arrows', w: 60, h: 120, draw: (w, h) => ({ d: poly(arrowPts(h, w).map(p => [p[1], p[0]])) }), text: (w, h) => box(w * 0.25, 0, w * 0.5, h * 0.6) });
    add('arrow_double',      { cat: 'arrows', w: 140, h: 60, draw: (w, h) => { const a = Math.min(w * 0.3, h), t = h * 0.5; return { d: poly([[0, h / 2], [a, 0], [a, h / 2 - t / 2], [w - a, h / 2 - t / 2], [w - a, 0], [w, h / 2], [w - a, h], [w - a, h / 2 + t / 2], [a, h / 2 + t / 2], [a, h]]) }; }, text: (w, h) => box(w * 0.25, h * 0.25, w * 0.5, h * 0.5) });
    add('arrow_double_v',    { cat: 'arrows', w: 60, h: 140, draw: (w, h) => { const a = Math.min(h * 0.3, w), t = w * 0.5; return { d: poly([[w / 2, 0], [w, a], [w / 2 + t / 2, a], [w / 2 + t / 2, h - a], [w, h - a], [w / 2, h], [0, h - a], [w / 2 - t / 2, h - a], [w / 2 - t / 2, a], [0, a]]) }; }, text: (w, h) => box(w * 0.25, h * 0.25, w * 0.5, h * 0.5) });
    add('chevron',           { cat: 'arrows', w: 110, h: 60, draw: (w, h) => { const n = Math.min(w * 0.3, h / 2); return { d: poly([[0, 0], [w - n, 0], [w, h / 2], [w - n, h], [0, h], [n, h / 2]]) }; }, text: (w, h) => box(Math.min(w * 0.3, h / 2), 0, w - 2 * Math.min(w * 0.3, h / 2), h) });
    add('step',              { cat: 'arrows', w: 120, h: 60, draw: (w, h) => { const n = Math.min(w * 0.25, h / 2); return { d: poly([[0, 0], [w - n, 0], [w, h / 2], [w - n, h], [0, h]]) }; }, text: (w, h) => box(0, 0, w - Math.min(w * 0.25, h / 2), h) });

    // Containers - zones for a site, a VLAN, a rack; draw them first and put nodes on top
    add('zone',              { cat: 'containers', w: 320, h: 220, rounded: true, style: { fill: 'solid', fillColour: '#eff6ff', stroke: 'dashed', strokeColour: '#3b82f6', strokeWidth: 1.5, rounded: 10, align: 'left', valign: 'top', bold: true, fontColour: '#1d4ed8' }, draw: (w, h, r) => ({ d: rrect(0, 0, w, h, r) }), text: (w, h) => box(10, 6, w - 20, h - 12) });
    add('frame',             { cat: 'containers', w: 320, h: 220, rounded: true, style: { fill: 'none', stroke: 'solid', strokeColour: '#475569', strokeWidth: 1.5, align: 'left', valign: 'top', bold: true }, draw: (w, h, r) => ({ d: rrect(0, 0, w, h, r) }), text: (w, h) => box(10, 6, w - 20, h - 12) });
    add('swimlane',          { cat: 'containers', w: 320, h: 220, style: { fill: 'solid', fillColour: '#f8fafc', stroke: 'solid', strokeColour: '#475569', strokeWidth: 1.5, valign: 'middle', bold: true }, draw: (w, h) => { const hh = Math.min(30, h * 0.3); return { d: poly([[0, 0], [w, 0], [w, h], [0, h]]), lines: 'M0,' + f(hh) + ' H' + f(w) }; }, text: (w, h) => box(6, 0, w - 12, Math.min(30, h * 0.3)) });
    add('pool_vertical',     { cat: 'containers', w: 220, h: 320, style: { fill: 'solid', fillColour: '#f8fafc', stroke: 'solid', strokeColour: '#475569', strokeWidth: 1.5, bold: true }, draw: (w, h) => { const hw = Math.min(30, w * 0.3); return { d: poly([[0, 0], [w, 0], [w, h], [0, h]]), lines: 'M' + f(hw) + ',0 V' + f(h) }; }, text: (w, h) => box(0, 6, Math.min(30, w * 0.3), h - 12) });

    // Text and notes
    add('text',              { cat: 'text', w: 140, h: 40, style: { fill: 'none', stroke: 'none' }, draw: (w, h) => ({ d: poly([[0, 0], [w, 0], [w, h], [0, h]]) }) });
    add('heading',           { cat: 'text', w: 240, h: 44, style: { fill: 'none', stroke: 'none', fontSize: 22, bold: true, align: 'left' }, draw: (w, h) => ({ d: poly([[0, 0], [w, 0], [w, h], [0, h]]) }) });
    add('note',              { cat: 'text', w: 140, h: 110, style: { fill: 'solid', fillColour: '#fef3c7', stroke: 'solid', strokeColour: '#d97706', strokeWidth: 1, shadow: true, align: 'left', valign: 'top' }, draw: (w, h) => { const c = Math.min(w, h) * 0.18; return { d: poly([[0, 0], [w - c, 0], [w, c], [w, h], [0, h]]), lines: 'M' + f(w - c) + ',0 V' + f(c) + ' H' + f(w) }; }, text: (w, h) => box(8, 8, w - 16 - Math.min(w, h) * 0.1, h - 16) });
    add('callout',           { cat: 'text', w: 150, h: 90, draw: (w, h) => { const bh = h * 0.75; return { d: poly([[0, 0], [w, 0], [w, bh], [w * 0.45, bh], [w * 0.2, h], [w * 0.25, bh], [0, bh]]) }; }, text: (w, h) => box(6, 0, w - 12, h * 0.75) });
    add('label',             { cat: 'text', w: 110, h: 34, style: { fill: 'solid', fillColour: '#e0f2fe', stroke: 'solid', strokeColour: '#0284c7', strokeWidth: 1, fontColour: '#075985', bold: true, fontSize: 12 }, draw: (w, h) => ({ d: rrect(0, 0, w, h, h / 2) }), text: (w, h) => box(h * 0.3, 0, w - h * 0.6, h) });
    add('line_h',            { cat: 'text', w: 200, h: 20, style: { fill: 'none', stroke: 'solid', strokeColour: '#475569', strokeWidth: 2 }, draw: (w, h) => ({ d: '', lines: 'M0,' + f(h / 2) + ' H' + f(w) }), text: () => box(0, 0, 0, 0) });
    add('line_v',            { cat: 'text', w: 20, h: 200, style: { fill: 'none', stroke: 'solid', strokeColour: '#475569', strokeWidth: 2 }, draw: (w, h) => ({ d: '', lines: 'M' + f(w / 2) + ',0 V' + f(h) }), text: () => box(0, 0, 0, 0) });

    // An icon from an imported library (GH #433): no drawn outline by default, the picture is an
    // <img> the editor adds, and the label sits underneath like draw.io's.
    add('icon',              { cat: 'library', w: 64, h: 64, rounded: true, style: { fill: 'none', stroke: 'none', valign: 'top', fontSize: 12 }, draw: (w, h, r) => ({ d: rrect(0, 0, w, h, r) }), text: (w, h) => box(-40, h + 2, w + 80, 44) });

    window.NM_SHAPES = S;
    window.NM_SHAPE_CATS = ['basic', 'flow', 'arrows', 'containers', 'text'];

    // ---- style -------------------------------------------------------------------------------
    // The look a new shape gets before its own defaults (S[type].style) and its own style.
    const SHAPE_STYLE = {
        fill: 'solid', fillColour: '#ffffff', fillColour2: '#dbeafe', gradientDir: 'down',
        stroke: 'solid', strokeColour: '#64748b', strokeWidth: 1.5,
        opacity: 100, shadow: false, rounded: 0,
        font: 'sans', fontSize: 13, fontColour: '#1f2937', bold: false, italic: false, underline: false,
        align: 'center', valign: 'middle'
    };
    const FONTS = {
        sans: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        serif: 'Georgia, "Times New Roman", serif',
        mono: 'Consolas, "Courier New", monospace',
        arial: 'Arial, Helvetica, sans-serif',
        verdana: 'Verdana, Geneva, sans-serif',
        tahoma: 'Tahoma, Geneva, sans-serif',
        trebuchet: '"Trebuchet MS", Helvetica, sans-serif',
        georgia: 'Georgia, serif',
        times: '"Times New Roman", Times, serif',
        courier: '"Courier New", Courier, monospace',
        comic: '"Comic Sans MS", "Comic Sans", cursive'
    };
    window.NM_FONTS = Object.keys(FONTS);
    window.NM_SHAPE_STYLE_DEFAULTS = SHAPE_STYLE;

    /** The full style for a shape: library defaults, then the shape type's, then its own. */
    window.nmShapeStyle = function (type, style) {
        const def = S[type] || S.rectangle;
        return Object.assign({}, SHAPE_STYLE, def.style || {}, style || {});
    };

    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const hex = v => (/^#[0-9a-f]{6}$/i.test(v || '') ? v : null);

    /** SVG gradient coordinates for a direction. */
    function gradAttrs(dir) {
        if (dir === 'right') return 'x1="0" y1="0" x2="1" y2="0"';
        if (dir === 'diag') return 'x1="0" y1="0" x2="1" y2="1"';
        return 'x1="0" y1="0" x2="0" y2="1"';
    }
    function dash(stroke, sw) {
        if (stroke === 'dashed') return ' stroke-dasharray="' + f(sw * 4 + 2) + ' ' + f(sw * 2.5 + 2) + '"';
        if (stroke === 'dotted') return ' stroke-dasharray="' + f(Math.max(sw, 1)) + ' ' + f(sw * 2 + 2) + '" stroke-linecap="round"';
        return '';
    }

    /**
     * The <svg> for one shape {shape_type, w, h, style}. idPrefix keeps gradient/filter ids unique
     * on the page. Everything is our own markup - the only values from the user are colours that
     * have passed the hex test and numbers.
     */
    window.nmShapeSvg = function (shape, idPrefix) {
        const def = S[shape.shape_type] || S.rectangle;
        const st = window.nmShapeStyle(shape.shape_type, shape.style);
        const w = Math.max(1, shape.w), h = Math.max(1, shape.h);
        const g = def.draw(w, h, def.rounded ? (+st.rounded || 0) : 0);
        let defs = '';
        let fill = 'none';
        if (st.fill === 'solid') fill = hex(st.fillColour) || '#ffffff';
        if (st.fill === 'gradient') {
            const c1 = hex(st.fillColour) || '#ffffff', c2 = hex(st.fillColour2) || '#dbeafe';
            const gid = idPrefix + '-g';
            defs += st.gradientDir === 'radial'
                ? '<radialGradient id="' + gid + '" cx="0.5" cy="0.5" r="0.65"><stop offset="0" stop-color="' + c1 + '"/><stop offset="1" stop-color="' + c2 + '"/></radialGradient>'
                : '<linearGradient id="' + gid + '" ' + gradAttrs(st.gradientDir) + '><stop offset="0" stop-color="' + c1 + '"/><stop offset="1" stop-color="' + c2 + '"/></linearGradient>';
            fill = 'url(#' + gid + ')';
        }
        const sw = st.stroke === 'none' ? 0 : Math.max(0, +st.strokeWidth || 0);
        const strokeAttr = sw ? ' stroke="' + (hex(st.strokeColour) || '#64748b') + '" stroke-width="' + f(sw) + '"' + dash(st.stroke, sw) : ' stroke="none"';
        let filter = '';
        if (st.shadow) {
            const sid = idPrefix + '-s';
            defs += '<filter id="' + sid + '" x="-20%" y="-20%" width="150%" height="150%"><feDropShadow dx="2" dy="3" stdDeviation="3" flood-color="#000" flood-opacity="0.28"/></filter>';
            filter = ' filter="url(#' + sid + ')"';
        }
        let body = '';
        if (g.d) body += '<path d="' + g.d + '" fill="' + fill + '"' + strokeAttr + ' stroke-linejoin="round"' + filter + '/>';
        if (g.tail) body += '<path d="' + g.tail + '" fill="' + fill + '"' + strokeAttr + ' stroke-linejoin="round"/>';
        if (g.lines) body += '<path d="' + g.lines + '" fill="none"' + (sw ? strokeAttr : ' stroke="' + (hex(st.strokeColour) || '#64748b') + '" stroke-width="1.5"') + ' stroke-linejoin="round"' + (g.d ? '' : filter) + '/>';
        return '<svg class="nm-shape-svg" width="' + f(w) + '" height="' + f(h) + '" viewBox="0 0 ' + f(w) + ' ' + f(h) + '" overflow="visible" xmlns="http://www.w3.org/2000/svg">'
            + (defs ? '<defs>' + defs + '</defs>' : '') + body + '</svg>';
    };

    /** Where a shape's text goes, in its own px. */
    window.nmShapeTextBox = function (shape) {
        const def = S[shape.shape_type] || S.rectangle;
        const b = def.text ? def.text(shape.w, shape.h) : box(0, 0, shape.w, shape.h);
        const inset = 4;
        return { x: b.x + inset, y: b.y + inset, w: Math.max(0, b.w - inset * 2), h: Math.max(0, b.h - inset * 2) };
    };

    /** A small preview of a shape for the palette. */
    window.nmShapePreview = function (type, size) {
        const def = S[type] || S.rectangle;
        const k = Math.min(size / def.w, size / def.h);
        const w = def.w * k, h = def.h * k;
        const st = Object.assign({}, def.style || {});
        if (type === 'text' || type === 'heading') { st.stroke = 'dashed'; st.strokeColour = '#94a3b8'; st.strokeWidth = 1; }
        if (st.rounded) st.rounded = st.rounded * k;
        st.shadow = false;
        st.strokeWidth = Math.min(st.strokeWidth || 1.5, 1.5);
        return window.nmShapeSvg({ shape_type: type, w: w, h: h, style: st }, 'nmpv-' + type);
    };

    /** Inline CSS for text with these style keys (a shape's text, or a node's label). */
    window.nmTextCss = function (st) {
        let css = '';
        if (st.font && FONTS[st.font]) css += 'font-family:' + FONTS[st.font] + ';';
        if (st.fontSize) css += 'font-size:' + (+st.fontSize) + 'px;';
        if (hex(st.fontColour)) css += 'color:' + st.fontColour + ';';
        if (st.bold != null) css += 'font-weight:' + (st.bold ? 700 : 400) + ';';
        if (st.italic != null) css += 'font-style:' + (st.italic ? 'italic' : 'normal') + ';';
        if (st.underline) css += 'text-decoration:underline;';
        if (st.align) css += 'text-align:' + st.align + ';';
        return css;
    };

    /**
     * Inline CSS for a CMDB node's box from the same style keys - only the keys the node actually
     * has, so an unstyled node keeps the normal look. Border colour and shadow go through custom
     * properties so the selected ring still wins (see .nm-node.has-style in diagram.php).
     */
    window.nmNodeBoxCss = function (st) {
        if (!st) return '';
        let css = '';
        if (st.fill === 'none') css += 'background:transparent;';
        else if (st.fill === 'solid' && hex(st.fillColour)) css += 'background:' + st.fillColour + ';';
        else if (st.fill === 'gradient') {
            const c1 = hex(st.fillColour) || '#ffffff', c2 = hex(st.fillColour2) || '#dbeafe';
            const dir = st.gradientDir === 'right' ? 'to right' : st.gradientDir === 'diag' ? 'to bottom right' : 'to bottom';
            css += 'background:' + (st.gradientDir === 'radial' ? 'radial-gradient(circle, ' + c1 + ', ' + c2 + ')' : 'linear-gradient(' + dir + ', ' + c1 + ', ' + c2 + ')') + ';';
        }
        if (st.stroke === 'none') css += '--nms-bw:0px;';
        else if (st.stroke) {
            css += '--nms-bs:' + st.stroke + ';';
            if (st.strokeWidth != null) css += '--nms-bw:' + (+st.strokeWidth) + 'px;';
            if (hex(st.strokeColour)) css += '--nms-bc:' + st.strokeColour + ';';
        }
        if (st.rounded != null) css += 'border-radius:' + (+st.rounded) + 'px;';
        if (st.shadow) css += '--nms-shadow:0 3px 9px rgba(0,0,0,0.28);';
        if (st.opacity != null && +st.opacity < 100) css += 'opacity:' + (+st.opacity / 100) + ';';
        return css;
    };

    window.nmEscapeShapeText = esc;
})();
