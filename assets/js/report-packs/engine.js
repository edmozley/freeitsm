/**
 * Report Packs - the layout engine.
 *
 * Turns a pack's design (+ each block's data) into PAGES: every line of text,
 * table cell, bar and picture with its exact position in millimetres. Two
 * renderers draw those pages - render-svg.js on screen, render-pdf.js into the
 * PDF - and neither makes a layout decision of its own. That is the whole
 * reason the PDF matches the screen: there is only one place where a line
 * breaks or a table runs onto the next page, and it is here.
 *
 * TEXT IS MEASURED WITH THE PDF'S OWN FONTS
 * ----------------------------------------
 * Widths come from the shared PDF writer (assets/js/pdf-writer.js) - the bundled
 * Noto fonts the PDF is printed in, measured AFTER shaping, so a joined Hindi
 * letter is as wide as it prints, not the sum of its parts (GH #254). The screen
 * then draws each line at the x the engine chose, with textLength pinning its
 * width, so the browser's own font still breaks at the same word.
 *
 * Every language FreeITSM ships prints. hasUnsupported() flags only text no
 * bundled font covers (Chinese, Japanese, Korean) so the designer can say so.
 *
 * Until the writer's fonts have loaded - the first moment on the designer page -
 * widths come from the browser's canvas instead, and the designer lays out again
 * when the fonts arrive (runtime.js prepareFonts).
 *
 * Units: millimetres throughout. Font sizes are points; PT converts.
 *
 * @wiki Report-Packs-Internals-2-The-Layout-Engine
 */
(function () {
    'use strict';

    const PT = 25.4 / 72;                 // mm per point
    const LINE = 1.28;                    // line height, as a multiple of font size
    const GUTTER = 5;                     // mm between grid columns
    const ROW_GAP = 4.5;                  // mm between rows of blocks
    const PAGE_SIZES = { A4: [210, 297], Letter: [215.9, 279.4], Legal: [215.9, 355.6], A3: [297, 420] };
    const FONT_CSS = {
        helvetica: 'Helvetica, Arial, "Liberation Sans", sans-serif',
        times: '"Times New Roman", Times, "Liberation Serif", serif',
        courier: '"Courier New", Courier, "Liberation Mono", monospace',
    };

    // ── Measurement ──────────────────────────────────────────────────────
    const widthCache = new Map();         // "font|style|text" -> width in points at size 1
    let probe = null;                     // a canvas, for widths before the writer is ready

    /** Returns the font style name (normal, bold, italic, bolditalic) for bold / italic flags, as the PDF writer takes it. */
    function styleKey(b, i) { return b && i ? 'bolditalic' : b ? 'bold' : i ? 'italic' : 'normal'; }

    /**
     * Width in mm of `str` set in this style - the whole string, SHAPED, from the PDF
     * writer (cached per font, style and text at size 1; width scales with size).
     *
     * TRAP: never add up single characters. That was right for Helvetica and is wrong
     * for any script whose letters join - क्ष is narrower than क + ् + ष (GH #254).
     */
    function textWidth(str, st) {
        const style = styleKey(st.b, st.i);
        const W = window.FitsmPdf;
        if (W && W.isReady()) {
            const key = st.font + '|' + style + '|' + str;
            let u = widthCache.get(key);
            if (u === undefined) {
                u = W.measure(str, st.font, style, 1);
                if (widthCache.size > 20000) widthCache.clear();
                widthCache.set(key, u);
            }
            return u * st.size * PT;
        }
        // Not ready yet: the browser's measure, in the matching family. Replaced by the
        // real widths on the next layout, once the fonts have loaded.
        probe = probe || document.createElement('canvas').getContext('2d');
        probe.font = (st.i ? 'italic ' : '') + (st.b ? 'bold ' : '') + st.size + 'pt ' + (FONT_CSS[st.font] || FONT_CSS.helvetica);
        return probe.measureText(str).width * 25.4 / 96;
    }

    /** Whether a string holds text no bundled font covers (Chinese, Japanese, Korean...) - everything else prints. */
    function hasUnsupported(str) {
        return window.FitsmPdf ? !window.FitsmPdf.supported(str) : false;
    }

    /** A string's characters as people see them - never half of a joined letter (Intl.Segmenter where there is one). */
    function graphemes(s) {
        return (typeof Intl !== 'undefined' && Intl.Segmenter)
            ? Array.from(new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(s), x => x.segment)
            : Array.from(s);
    }

    // ── Rich text ────────────────────────────────────────────────────────
    //
    // Paragraph model (see includes/report_packs/design.php):
    //   {t: p|h1|h2|h3|li|img, a: left|center|right|justify, l: ul|ol, lv: 0-4,
    //    r: [{x | fld, b, i, u, s, c, hl, sz}], w (img)}

    const HEADING_SCALE = { h1: 1.8, h2: 1.45, h3: 1.2 };

    /**
     * Lay out a rich-text document in a box `width` mm wide.
     * ctx: {font, size, color, fields: {page, pages, ...}, logo: {w, h} | null}
     * Returns {height, lines: [...], images: [...]} with y measured from the top.
     */
    function layoutDoc(doc, width, ctx) {
        const lines = [], images = [];
        let y = 0;
        const counters = {};             // ordered-list numbering per level
        let prevType = null;

        (doc || []).forEach((p, pi) => {
            if (p.t === 'img') {
                const logo = ctx.logo;
                const w = Math.min(p.w || 30, width);
                const h = logo && logo.w ? w * (logo.h / logo.w) : w * 0.35;
                const x = p.a === 'center' ? (width - w) / 2 : p.a === 'right' ? width - w : 0;
                images.push({ src: 'logo', x, y, w, h });
                y += h + 1.5;
                prevType = 'img';
                return;
            }

            const scale = HEADING_SCALE[p.t] || 1;
            const base = {
                font: ctx.font, size: ctx.size * scale, color: ctx.color,
                b: !!HEADING_SCALE[p.t], i: false, u: false, s: false, hl: null,
            };
            if (HEADING_SCALE[p.t] && ctx.headingColor) base.color = ctx.headingColor;

            // Space above headings, as a word processor does.
            if (HEADING_SCALE[p.t] && pi > 0) y += base.size * PT * 0.6;

            const indent = (p.lv || 0) * 6;
            let marker = null, textX = indent;
            if (p.t === 'li') {
                const lvl = p.lv || 0;
                if (prevType !== 'li') Object.keys(counters).forEach(k => delete counters[k]);
                Object.keys(counters).forEach(k => { if (+k > lvl) delete counters[k]; });
                counters[lvl] = (counters[lvl] || 0) + 1;
                marker = p.l === 'ol' ? counters[lvl] + '.' : (lvl % 2 ? '◦' : '•');
                textX = indent + 5;
            }
            prevType = p.t;

            const tokens = tokenise(p.r || [], base, ctx);
            const avail = Math.max(10, width - textX);
            const paraLines = breakLines(tokens, avail);
            if (!paraLines.length) paraLines.push({ items: [], width: 0, size: base.size, hard: true });

            paraLines.forEach((ln, li) => {
                const size = Math.max(base.size, ...ln.items.map(t => t.st.size));
                const h = size * PT * LINE;
                const baseline = y + (h - size * PT) / 2 + size * PT * 0.8;
                const slack = avail - ln.width;
                let x0 = textX;
                if (p.a === 'center') x0 += slack / 2;
                else if (p.a === 'right') x0 += slack;

                // Justify: share the slack between the spaces, except on a paragraph's
                // last line (and lines ended by a hard break), as every editor does.
                const spaces = ln.items.filter(t => t.space).length;
                const stretch = (p.a === 'justify' && !ln.hard && li < paraLines.length - 1 && spaces) ? slack / spaces : 0;

                const items = [];
                let x = x0;
                for (const t of ln.items) {
                    const w = t.w + (t.space ? stretch : 0);
                    if (!t.space || t.st.u || t.st.s || t.st.hl) items.push({ x, w, text: t.text, st: t.st, space: t.space });
                    x += w;
                }
                const line = { y, h, baseline, items: mergeRuns(items) };
                if (marker && li === 0) {
                    line.marker = { x: indent, text: marker, st: Object.assign({}, base, { b: false }) };
                }
                lines.push(line);
                y += h;
            });

            // Space after: a little for body text, less between list items.
            y += p.t === 'li' ? base.size * PT * 0.15 : base.size * PT * 0.45;
        });

        return { height: y, lines, images };
    }

    /** Runs -> tokens: words, spaces and hard breaks, each with its style and width. */
    function tokenise(runs, base, ctx) {
        const out = [];
        runs.forEach(r => {
            const st = Object.assign({}, base);
            if (r.b) st.b = true;
            if (r.i) st.i = true;
            if (r.u) st.u = true;
            if (r.s) st.s = true;
            if (r.c) st.color = r.c;
            if (r.hl) st.hl = r.hl;
            if (r.sz) st.size = r.sz * (base.size / ctx.size);
            let text = r.fld ? fieldValue(r.fld, ctx.fields) : (r.x || '');
            text.split(/(\n)/).forEach(part => {
                if (part === '\n') { out.push({ br: true, st }); return; }
                part.split(/(\s+)/).forEach(w => {
                    if (!w) return;
                    const space = /^\s+$/.test(w);
                    const tx = space ? ' ' : w;
                    out.push({ text: tx, st, space, w: textWidth(tx, st) });
                });
            });
        });
        return out;
    }

    /** Returns a field's value (page, pages, title...) as a string, or empty when it is not set. */
    function fieldValue(name, fields) {
        const v = fields && fields[name];
        return v === undefined || v === null ? '' : String(v);
    }

    /** Greedy line breaking. A word wider than the line is split by characters. */
    function breakLines(tokens, avail) {
        const lines = [];
        let cur = { items: [], width: 0 };
        /**
         * Ends the current line (dropping trailing spaces), records whether a hard break ended it, and
         * starts a new one.
         */
        const push = (hard) => {
            while (cur.items.length && cur.items[cur.items.length - 1].space) {
                cur.width -= cur.items.pop().w;           // no trailing space
            }
            cur.hard = hard;
            lines.push(cur);
            cur = { items: [], width: 0 };
        };
        for (const t of tokens) {
            if (t.br) { push(true); continue; }
            if (t.space) {
                if (!cur.items.length) continue;           // no leading space on a line
                cur.items.push(t); cur.width += t.w;
                continue;
            }
            if (cur.width + t.w <= avail + 0.01) { cur.items.push(t); cur.width += t.w; continue; }
            if (cur.items.length) push(false);
            if (t.w <= avail) { cur.items.push(t); cur.width += t.w; continue; }
            // A single word longer than the line: break it where it has to - between
            // whole characters, never inside a joined letter.
            let piece = '';
            for (const ch of graphemes(t.text)) {
                const w = textWidth(piece + ch, t.st);
                if (w > avail && piece) {
                    const pw = textWidth(piece, t.st);
                    cur.items.push({ text: piece, st: t.st, w: pw }); cur.width += pw;
                    push(false);
                    piece = ch;
                } else piece += ch;
            }
            if (piece) { const pw = textWidth(piece, t.st); cur.items.push({ text: piece, st: t.st, w: pw }); cur.width += pw; }
        }
        if (cur.items.length) push(true);
        return lines;
    }

    /** Join neighbouring tokens of identical style into one run (fewer SVG/PDF calls). */
    function mergeRuns(items) {
        const out = [];
        for (const it of items) {
            const last = out[out.length - 1];
            if (last && sameStyle(last.st, it.st) && Math.abs(last.x + last.w - it.x) < 0.01) {
                last.text += it.text; last.w += it.w;
            } else out.push(Object.assign({}, it));
        }
        return out.filter(i => i.text.trim() !== '' || i.st.u || i.st.s || i.st.hl);
    }
    /** Whether two run styles match in font, size, bold, italic, underline, strike, colour and highlight. */
    function sameStyle(a, b) {
        return a.font === b.font && a.size === b.size && a.b === b.b && a.i === b.i && a.u === b.u
            && a.s === b.s && a.color === b.color && a.hl === b.hl;
    }

    /** Plain text wrapped into lines - for table cells and captions. */
    function wrapPlain(text, width, st) {
        const doc = String(text || '').split('\n').map(line => ({ t: 'p', a: 'left', r: [{ x: line }] }));
        const lines = [];
        for (const para of doc) {
            const toks = tokenise(para.r, st, { size: st.size, font: st.font });
            const ls = breakLines(toks, width);
            if (!ls.length) ls.push({ items: [], width: 0 });
            ls.forEach(l => lines.push({ text: l.items.map(i => i.text).join(''), width: l.width }));
        }
        return lines;
    }

    // ── Blocks ───────────────────────────────────────────────────────────
    //
    // Each block kind has a measure(block, data, width, theme) returning
    //   {h, draw: [...primitives relative to the block's top-left], units?}
    // where a SPLITTABLE block also returns `units` (the y at which it may be cut)
    // so the paginator can carry the rest onto the next page.

    /**
     * Returns a text style from the theme's font and size in dark grey, with any extra properties laid
     * over it.
     */
    function themeStyle(theme, extra) {
        return Object.assign({ font: theme.font, size: theme.size, color: '#222222', b: false, i: false, u: false, s: false, hl: null }, extra || {});
    }

    /** A line of plain text as a draw primitive. */
    function txt(x, baseline, text, st, w) {
        return { k: 'text', x, y: baseline, text, st, w: w === undefined ? textWidth(text, st) : w };
    }
    /** Returns a rectangle draw primitive, with any extra properties (stroke, radius, dash) merged in. */
    function rect(x, y, w, h, fill, extra) { return Object.assign({ k: 'rect', x, y, w, h, fill }, extra || {}); }
    /**
     * Returns a horizontal line draw primitive; a null x2 means the full block width, filled in by
     * offset().
     */
    function hline(x1, x2, y, color, width) { return { k: 'line', x1, y1: y, x2, y2: y, color, width: width || 0.25 }; }

    /** Returns the text baseline for a line of the given point size whose top is at top (mm). */
    function baselineFor(top, size) { return top + (size * PT * LINE - size * PT) / 2 + size * PT * 0.8; }

    /** Contrast text for a coloured pill. */
    function onColour(hex) {
        const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
        if (!m) return '#ffffff';
        const n = parseInt(m[1], 16), r = n >> 16, g = (n >> 8) & 255, b = n & 255;
        return (0.299 * r + 0.587 * g + 0.114 * b) > 160 ? '#1a1a1a' : '#ffffff';
    }

    /** Measures and draws a rounded coloured pill with bold contrasting text; returns {w, h, draw}. */
    function pill(x, top, text, colour, size, theme) {
        const st = themeStyle(theme, { size, b: true, color: onColour(colour) });
        const w = textWidth(text, st) + 4;
        const h = size * PT * 1.5;
        return { w, h, draw: [
            rect(x, top, w, h, colour || '#6b7280', { r: h / 2 }),
            txt(x + 2, top + h / 2 + size * PT * 0.32, text, st),
        ] };
    }

    /**
     * Measures a heading block - its text at the level's size in the heading colour, with a rule under
     * level 1.
     */
    function measureHeading(b, theme) {
        const size = { 1: 15, 2: 12.5, 3: 11 }[b.level] || 15;
        const st = themeStyle(theme, { size, b: true, color: theme.heading });
        const lh = size * PT * LINE;
        const draw = [txt(0, baselineFor(0, size), b.text || '', st)];
        let h = lh;
        if (b.level === 1) { draw.push(hline(0, null, lh + 0.8, '#222222', 0.45)); h += 2.2; }
        return { h: h + 1.5, draw, fullWidth: true };
    }

    /**
     * Measures a text block by laying out its rich text (inside a shaded box when box is set).
     *
     * Returns each line's bottom as units so the block can be split across pages.
     */
    function measureText(b, width, theme, ctx) {
        const L = layoutDoc(b.doc, width - (b.box ? 6 : 0), {
            font: theme.font, size: theme.size, color: '#222222', headingColor: theme.heading,
            fields: ctx.fields, logo: ctx.logo,
        });
        const pad = b.box ? 3 : 0;
        const draw = [];
        if (b.box) draw.push(rect(0, 0, width, L.height + pad * 2, '#f6f7f9', { stroke: '#d9dde3' }));
        // Splittable by line: each line's bottom is a place it may be cut.
        const units = L.lines.map(l => l.y + l.h + pad);
        return { h: L.height + pad * 2, rich: L, pad, draw, units };
    }

    /** The block's title line (data blocks), with the "snapshot" caption under it. */
    function titleDraw(b, data, width, theme) {
        const out = { h: 0, draw: [] };
        if (b.showTitle === false) return out;
        const title = b.title || '';
        if (title) {
            const st = themeStyle(theme, { size: theme.size + 1.5, b: true, color: theme.heading });
            out.draw.push(txt(0, baselineFor(0, st.size), title, st));
            out.h += st.size * PT * LINE + 0.8;
        }
        if (data && data.snapshot && data.snapshotNote) {
            const st = themeStyle(theme, { size: theme.size - 2.5, i: true, color: '#777777' });
            out.draw.push(txt(0, baselineFor(out.h, st.size), data.snapshotNote, st));
            out.h += st.size * PT * LINE + 0.6;
        }
        if (out.h) out.h += 1.2;
        return out;
    }

    /**
     * Measures a KPI block - tiles of value, label and optional hint, as many to a row as fit at about
     * 33mm each.
     */
    function measureKpi(data, width, theme, top) {
        const tiles = data.tiles || [];
        const per = Math.max(1, Math.min(tiles.length || 1, Math.floor((width + 3) / 33)));
        const tw = (width - (per - 1) * 3) / per;
        const th = 19;
        const draw = [];
        tiles.forEach((t, i) => {
            const col = i % per, row = Math.floor(i / per);
            const x = col * (tw + 3), y = top + row * (th + 3);
            draw.push(rect(x, y, tw, th, '#f5f7fa', { stroke: '#e1e5ea', r: 1.5 }));
            draw.push(rect(x, y, 1.1, th, theme.accent));
            const vs = themeStyle(theme, { size: theme.size + 8, b: true, color: theme.heading });
            const ls = themeStyle(theme, { size: theme.size - 1.5, color: '#555555' });
            const hs = themeStyle(theme, { size: theme.size - 3, color: '#888888' });
            draw.push(txt(x + 4, y + 9, fit(t.value, tw - 6, vs), vs));
            draw.push(txt(x + 4, y + 13.8, fit(t.label, tw - 6, ls), ls));
            if (t.hint) draw.push(txt(x + 4, y + 17.2, fit(t.hint, tw - 6, hs), hs));
        });
        const rows = Math.ceil(tiles.length / per);
        return { h: rows ? rows * th + (rows - 1) * 3 : 0, draw };
    }

    /** Truncate with an ellipsis to fit a width. */
    function fit(text, width, st) {
        text = String(text === undefined || text === null ? '' : text);
        if (textWidth(text, st) <= width) return text;
        let t = text;
        while (t.length > 1 && textWidth(t + '…', st) > width) t = t.slice(0, -1);
        return t + '…';
    }

    /**
     * Tables. Rows are the units a table splits on; the header repeats at the
     * top of every page it continues onto.
     */
    function measureTable(data, width, theme, top) {
        const cols = data.columns || [];
        const total = cols.reduce((s, c) => s + (c.w || 10), 0) || 1;
        const widths = cols.map(c => width * (c.w || 10) / total);
        const xs = []; widths.reduce((x, w, i) => (xs[i] = x, x + w), 0);
        const pad = 1.4;
        const fs = theme.size - 1.5;
        const cellSt = themeStyle(theme, { size: fs });
        const headSt = themeStyle(theme, { size: fs, b: true, color: theme.th_fg });
        const lh = fs * PT * LINE;

        // Header
        let headH = 0;
        const headDraw = [];
        const headLines = cols.map((c, i) => wrapPlain(c.label, widths[i] - pad * 2, headSt));
        headH = Math.max(1, ...headLines.map(l => l.length)) * lh + pad * 2;
        headDraw.push(rect(0, 0, width, headH, theme.th_bg));
        headLines.forEach((ls, i) => ls.forEach((l, j) => {
            const x = cols[i].align === 'right' ? xs[i] + widths[i] - pad - l.width : xs[i] + pad;
            headDraw.push(txt(x, baselineFor(pad + j * lh, fs), l.text, headSt, l.width));
        }));

        const rows = [];
        (data.rows || []).forEach((r, ri) => {
            const draw = [];
            let h = 0;
            cols.forEach((c, i) => {
                const v = r[c.key];
                const x = xs[i] + pad, w = widths[i] - pad * 2;
                if (v && typeof v === 'object' && v.pill !== undefined) {
                    if (v.pill === '') return;
                    const p = pill(x, pad, fit(v.pill, w - 4, themeStyle(theme, { size: fs - 1, b: true })), v.colour, fs - 1, theme);
                    draw.push(...p.draw);
                    h = Math.max(h, p.h);
                } else if (v && typeof v === 'object' && Array.isArray(v.chips)) {
                    let cx = x, cy = pad, rowH = 0;
                    v.chips.forEach(ch => {
                        const p = pill(0, 0, fit(ch.text, w - 4, themeStyle(theme, { size: fs - 1.5, b: true })), ch.colour, fs - 1.5, theme);
                        if (cx + p.w > x + w && cx > x) { cx = x; cy += rowH + 0.8; rowH = 0; }
                        p.draw.forEach(d => { d = Object.assign({}, d); d.x += cx; d.y += cy; draw.push(d); });
                        cx += p.w + 1; rowH = Math.max(rowH, p.h);
                    });
                    h = Math.max(h, cy + rowH - pad);
                } else {
                    const ls = wrapPlain(v === undefined || v === null ? '' : v, w, cellSt);
                    ls.forEach((l, j) => {
                        const lx = c.align === 'right' ? xs[i] + widths[i] - pad - l.width : x;
                        draw.push(txt(lx, baselineFor(pad + j * lh, fs), l.text, cellSt, l.width));
                    });
                    h = Math.max(h, ls.length * lh);
                }
            });
            h += pad * 2;
            if (r._detail) {
                // The comment under the row, from the second column to the end.
                const dx = cols.length > 1 ? xs[1] + pad : pad;
                const dSt = themeStyle(theme, { size: fs, color: '#333333' });
                const ls = wrapPlain(r._detail, width - dx - pad, dSt);
                ls.forEach((l, j) => draw.push(txt(dx, baselineFor(h - pad + j * lh, fs), l.text, dSt, l.width)));
                h += ls.length * lh + pad * 0.5;
            }
            const bg = theme.stripe && ri % 2 === 1 ? '#f4f6f8' : null;
            rows.push({ h, draw, bg });
        });

        let footDraw = [], footH = 0;
        const footNote = data.note || (!rows.length ? data.empty : null);
        if (footNote) {
            const st = themeStyle(theme, { size: fs, i: true, color: '#666666' });
            footDraw = [txt(0, baselineFor(1, fs), footNote, st)];
            footH = lh + 2;
        }
        return { table: true, top, headH, headDraw, rows, footDraw, footH, width };
    }

    /** One table "part": the header plus rows [from, to), positioned from y=0. */
    function tableDraw(T, from, to, withFoot) {
        const draw = [];
        let y = T.top;
        T.headDraw.forEach(d => draw.push(shift(d, y)));
        y += T.headH;
        for (let i = from; i < to; i++) {
            const r = T.rows[i];
            if (r.bg) draw.push(rect(0, y, T.width, r.h, r.bg));
            draw.push(hline(0, T.width, y + r.h, '#e3e6ea', 0.2));
            r.draw.forEach(d => draw.push(shift(d, y)));
            y += r.h;
        }
        if (withFoot && T.footH) { T.footDraw.forEach(d => draw.push(shift(d, y))); y += T.footH; }
        return { draw, h: y };
    }

    /** Returns a copy of a draw primitive moved down by dy. */
    function shift(d, dy) {
        const o = Object.assign({}, d);
        if (o.k === 'line') { o.y1 += dy; o.y2 += dy; } else o.y += dy;
        return o;
    }

    /** Service uptime: one unit per service - name, uptime, daily bars, incidents. */
    function measureUptime(data, width, theme, top) {
        const units = [];
        const fs = theme.size - 1;
        (data.services || []).forEach(svc => {
            const draw = [];
            let y = 0;
            const nameSt = themeStyle(theme, { size: fs + 0.5, b: true });
            draw.push(txt(0, baselineFor(y, nameSt.size), fit(svc.name, width, nameSt), nameSt));
            y += nameSt.size * PT * LINE;
            if (svc.description) {
                const ds = themeStyle(theme, { size: fs - 2, color: '#777777' });
                draw.push(txt(0, baselineFor(y, ds.size), fit(svc.description, width, ds), ds));
                y += ds.size * PT * LINE;
            }
            const us = themeStyle(theme, { size: fs + 3, b: true });
            draw.push(txt(0, baselineFor(y + 0.5, us.size), svc.uptime, us));
            const uw = textWidth(svc.uptime, us);
            const ls = themeStyle(theme, { size: fs - 2, color: '#666666' });
            const lab = (data.uptimeLabel || 'uptime') + (svc.downtime ? '  ·  ' + svc.downtime + ' ' + (data.downtimeLabel || 'down') : '');
            draw.push(txt(uw + 2, baselineFor(y + 0.5, us.size), lab, ls));
            y += us.size * PT * LINE + 1;

            if (svc.strip && svc.strip.length) {
                const n = svc.strip.length, gap = n > 60 ? 0.15 : 0.4;
                const bw = (width - gap * (n - 1)) / n, bh = 5.5;
                svc.strip.forEach((d, i) => {
                    const fill = d.s === 'down' ? (d.c || '#d93025') : d.s === 'info' ? (d.c || '#4a90d9') : d.s === 'future' ? '#eef0f2' : '#c8ecd6';
                    draw.push(rect(i * (bw + gap), y, bw, bh, fill));
                });
                y += bh + 0.8;
                const cs = themeStyle(theme, { size: fs - 3, color: '#999999' });
                draw.push(txt(0, baselineFor(y, cs.size), data.from || '', cs));
                const tw = textWidth(data.to || '', cs);
                draw.push(txt(width - tw, baselineFor(y, cs.size), data.to || '', cs));
                y += cs.size * PT * LINE;
            }
            (svc.incidents || []).forEach(inc => {
                const is = themeStyle(theme, { size: fs - 1.5 });
                const rowH = is.size * PT * 1.9;
                draw.push(hline(0, width, y, '#eceef1', 0.2));
                draw.push(txt(1, baselineFor(y + 0.6, is.size), inc.started, is));
                const p = pill(width * 0.28, y + 0.9, fit(inc.impact, width * 0.16, themeStyle(theme, { size: is.size - 1, b: true })), inc.colour, is.size - 1, theme);
                draw.push(...p.draw);
                draw.push(txt(width * 0.47, baselineFor(y + 0.6, is.size), inc.duration, is));
                draw.push(txt(width * 0.6, baselineFor(y + 0.6, is.size), fit(inc.title, width * 0.4, is), is));
                y += rowH;
            });
            y += 3;
            draw.push(hline(0, width, y - 1.2, '#d5d9de', 0.25));
            units.push({ h: y, draw });
        });
        let empty = null;
        if (!units.length) {
            const st = themeStyle(theme, { size: fs, i: true, color: '#666666' });
            empty = { h: fs * PT * LINE + 2, draw: [txt(0, baselineFor(1, fs), data.empty || '', st)] };
        }
        return { uptime: true, top, units, empty };
    }

    // ── Pagination ───────────────────────────────────────────────────────

    /**
     * Returns the page width and height for the design's size and orientation, its margins, and the
     * content width.
     */
    function pageGeometry(design) {
        const [w0, h0] = PAGE_SIZES[design.page.size] || PAGE_SIZES.A4;
        const land = design.page.orient === 'landscape';
        const pw = land ? h0 : w0, ph = land ? w0 : h0;
        const m = design.page.margin;
        return { pw, ph, m, cw: pw - m.l - m.r };
    }

    /** Header/footer/cover laid out for one page's fields. */
    function layoutPart(part, width, theme, ctx) {
        if (!part || !part.on) return null;
        return layoutDoc(part.doc, width, {
            font: theme.font, size: theme.size, color: '#222222', headingColor: theme.heading,
            fields: ctx.fields, logo: ctx.logo,
        });
    }

    /**
     * The whole pack as pages.
     *  design   the cleaned design
     *  dataMap  block id -> {data} | {error} | undefined (still loading)
     *  ctx      {fields: {...}, logo: {w,h}|null, title, labels: {...}}
     *
     * Returns {pages: [{type, items, header, footer}], geometry, toc}
     *   item = {id, type, x, y, w, h, draw: [...] (absolute mm), block}
     */
    function paginate(design, dataMap, ctx) {
        const G = pageGeometry(design);
        const theme = design.theme;
        /** Returns the field values for a given page number and page total, plus the pack title. */
        const fieldsAt = (n, total) => Object.assign({}, ctx.fields, { page: n, pages: total, title: ctx.title });

        // Header and footer heights, measured with wide page numbers so they never grow later.
        const hdrProbe = layoutPart(design.header, G.cw, theme, { fields: fieldsAt(888, 888), logo: ctx.logo });
        const ftrProbe = layoutPart(design.footer, G.cw, theme, { fields: fieldsAt(888, 888), logo: ctx.logo });
        const headerH = hdrProbe ? hdrProbe.height + (design.header.rule ? 2.5 : 0) + 5 : 0;
        const footerH = ftrProbe ? ftrProbe.height + (design.footer.rule ? 2.5 : 0) + 4 : 0;

        const colW = (G.cw - GUTTER * 11) / 12;
        /** Returns the width in mm of a block spanning that many of the 12 grid columns. */
        const spanW = span => span * colW + (span - 1) * GUTTER;

        // 1. Measure every block at its width.
        const measured = design.blocks.map(b => measureBlock(b, dataMap[b.id], spanW(blockSpan(b)), theme, ctx));

        // 2. Flow them into rows of up to 12 columns.
        const rows = [];
        let row = null;
        design.blocks.forEach((b, i) => {
            const span = blockSpan(b);
            const solo = b.type !== 'data' && b.type !== 'text' && !(b.type === 'spacer' && span < 12);
            // `newRow` is how "drop it BELOW" survives a flow layout: without it a
            // half-width block dropped under another would slide up beside it.
            if (!row || solo || row.solo || row.used + span > 12 || b.newRow) {
                row = { items: [], used: 0, solo };
                rows.push(row);
            }
            row.items.push({ b, m: measured[i], col: row.used, span });
            row.used += span;
        });

        // 3. Place rows on pages.
        const pages = [];
        /** Returns where the body starts on a page - below the header when the page has one. */
        const bodyTop = (p) => G.m.t + (p.header ? headerH : 0);
        /** Returns where the body ends - above the footer. */
        const bodyBottom = () => G.ph - G.m.b - footerH;
        let page = null, y = 0;
        /**
         * Starts a new body page and moves y to its top; the header shows unless it is turned off for
         * the first page.
         */
        const newPage = () => {
            const showHeader = !!design.header.on && (design.header.first !== false || pages.filter(p => p.type === 'body').length > 0);
            page = { type: 'body', items: [], header: showHeader, footer: !!design.footer.on };
            pages.push(page);
            y = bodyTop(page);
        };
        newPage();

        const headings = [];
        rows.forEach((r, ri) => {
            const first = r.items[0];
            if (first.b.type === 'pagebreak') {
                if (page.items.length) newPage();
                page.items.push({ id: first.b.id, type: 'pagebreak', x: G.m.l, y, w: G.cw, h: 0, draw: [], block: first.b, marker: true });
                return;
            }
            if (first.b.type === 'heading' && first.b.newPage && page.items.length) newPage();

            const rowH = Math.max(...r.items.map(it => it.m.h));
            if (y > bodyTop(page) + 0.01) y += ROW_GAP;

            // Keep a heading with what follows it.
            if (first.b.type === 'heading' && ri + 1 < rows.length) {
                const next = rows[ri + 1];
                const nextMin = Math.min(...next.items.map(it => it.m.minH || it.m.h));
                if (y + rowH + ROW_GAP + Math.min(nextMin, 25) > bodyBottom() && page.items.length) newPage();
            }

            const split = r.items.length === 1 && r.items[0].m.split;
            if (y + rowH <= bodyBottom() + 0.01 || (!split && !page.items.length)) {
                if (y + rowH > bodyBottom() + 0.01 && page.items.length) newPage();
                placeRow(r, null);
                return;
            }
            if (!split) { newPage(); placeRow(r, null); return; }

            // A table, uptime list or long text: put as much as fits here, the rest overleaf.
            const it = r.items[0];
            let state = it.m.split.start();
            while (state) {
                let room = bodyBottom() - y;
                let part = it.m.split.take(state, room);
                if (!part.n && page.items.length) { newPage(); room = bodyBottom() - y; part = it.m.split.take(state, room); }
                if (!part.n) part = it.m.split.take(state, Infinity, 1);   // a single unit taller than a page
                place(it, { draw: part.draw, h: part.h, continued: part.continued, rich: part.richPart || null, more: !!part.rest });
                y += part.h;
                state = part.rest;
                if (state) { newPage(); }
            }
        });

        /**
         * Places every block of a row side by side at its column on the current page, then moves y
         * past the tallest.
         */
        function placeRow(r) {
            const rowH = Math.max(...r.items.map(it => it.m.h));
            r.items.forEach(it => {
                place(it, { draw: it.m.draw, h: it.m.h, continued: false, rich: it.m.rich || null },
                      G.m.l + it.col * (colW + GUTTER));
            });
            y += rowH;
        }

        /** Put a block (or one page's part of it) on the current page at y. */
        function place(it, part, x) {
            x = x === undefined ? G.m.l : x;
            const w = spanW(it.span);
            const top = y;
            page.items.push({
                id: it.b.id, type: it.b.type, block: it.b, x, y: top, w, h: part.h,
                continued: part.continued, more: !!part.more,
                draw: (part.draw || []).map(d => offset(d, x, top, w)),
                chart: it.m.chart ? Object.assign({}, it.m.chart, { x: x + it.m.chart.x, y: top + it.m.chart.y }) : null,
                rich: part.rich ? { L: part.rich, x: x + (it.m.pad || 0), y: top + (it.m.pad || 0) } : null,
                state: it.m.state || null,
            });
            const continued = part.continued;
            if (it.b.type === 'heading' && it.b.toc !== false && !continued) {
                headings.push({ text: it.b.text, level: it.b.level, page: pages.length - 1 });
            }
        }

        // 4. Cover and contents pages go in front.
        const front = [];
        if (design.cover && design.cover.on) front.push({ type: 'cover', items: [], header: false, footer: false });
        let tocPages = 0;
        if (design.toc && design.toc.on) {
            const lh = theme.size * PT * 1.9;
            const usable = G.ph - G.m.t - G.m.b - headerH - footerH - 14;
            tocPages = Math.max(1, Math.ceil(headings.length * lh / usable));
            for (let i = 0; i < tocPages; i++) front.push({ type: 'toc', items: [], header: !!design.header.on, footer: !!design.footer.on, tocPart: i });
        }
        const all = front.concat(pages);
        const total = all.length;
        const bodyOffset = front.length;

        // 5. Now page numbers are known, lay out each page's header, footer, cover and contents.
        all.forEach((p, i) => {
            const fields = fieldsAt(i + 1, total);
            p.number = i + 1;
            if (p.header) {
                const L = layoutPart(design.header, G.cw, theme, { fields, logo: ctx.logo });
                p.headerRich = { L, x: G.m.l, y: G.m.t };
                if (design.header.rule) p.headerRule = { y: G.m.t + L.height + 1.5 };
            }
            if (p.footer) {
                const L = layoutPart(design.footer, G.cw, theme, { fields, logo: ctx.logo });
                const fy = G.ph - G.m.b - L.height;
                p.footerRich = { L, x: G.m.l, y: fy };
                if (design.footer.rule) p.footerRule = { y: fy - 2 };
            }
            if (p.type === 'cover') {
                const L = layoutPart(design.cover, G.cw, theme, { fields, logo: ctx.logo }) || { height: 0, lines: [], images: [] };
                p.coverRich = { L, x: G.m.l, y: Math.max(G.m.t, (G.ph - L.height) / 2.6) };
            }
            if (p.type === 'toc') p.toc = tocDraw(design, theme, headings, bodyOffset, p.tocPart, G, headerH, footerH);
        });

        return { pages: all, geometry: G, headings, headerH, footerH, colW, gutter: GUTTER, spanW };
    }

    /**
     * Draws one contents page - the title on the first part, then its share of the headings with dot
     * leaders and page numbers.
     */
    function tocDraw(design, theme, headings, offset, part, G, headerH, footerH) {
        const draw = [];
        let y = G.m.t + headerH;
        const lh = theme.size * PT * 1.9;
        const usable = G.ph - G.m.t - G.m.b - headerH - footerH - 14;
        const per = Math.max(1, Math.floor(usable / lh));
        if (part === 0) {
            const st = themeStyle(theme, { size: 18, b: true, color: theme.heading });
            draw.push(txt(G.m.l, baselineFor(y, 18), design.toc.title || 'Contents', st));
            y += 14;
        }
        headings.slice(part * per, (part + 1) * per).forEach(h => {
            const st = themeStyle(theme, { size: theme.size + (h.level === 1 ? 0.5 : 0), b: h.level === 1 });
            const indent = (h.level - 1) * 6;
            const num = String(h.page + offset + 1);
            const nw = textWidth(num, st);
            const text = fit(h.text, G.cw - indent - nw - 12, st);
            const tw = textWidth(text, st);
            const base = baselineFor(y, st.size);
            draw.push(txt(G.m.l + indent, base, text, st));
            draw.push({ k: 'dots', x1: G.m.l + indent + tw + 2, x2: G.m.l + G.cw - nw - 2, y: base - 0.4, color: '#999999' });
            draw.push(txt(G.m.l + G.cw - nw, base, num, st));
            y += lh;
        });
        return draw;
    }

    /**
     * Returns how many of the 12 columns a block spans - data, text and spacer blocks keep their own
     * span, the rest are full width.
     */
    function blockSpan(b) {
        return (b.type === 'data' || b.type === 'text' || b.type === 'spacer') ? Math.max(1, Math.min(12, b.span || 12)) : 12;
    }

    /** Move a primitive from block coordinates to page coordinates. */
    function offset(d, x, y, w) {
        const o = Object.assign({}, d);
        if (o.k === 'line') {
            o.x1 += x; o.x2 = (o.x2 === null ? w : o.x2) + x; o.y1 += y; o.y2 += y;
        } else if (o.k === 'dots') {
            o.x1 += x; o.x2 += x; o.y += y;
        } else { o.x += x; o.y += y; }
        return o;
    }

    /** Measure one block. Returns {h, draw, split?, chart?, rich?, minH?, state?}. */
    function measureBlock(b, entry, width, theme, ctx) {
        switch (b.type) {
            case 'heading': return measureHeading(b, theme);
            case 'divider': return { h: 3, draw: [hline(0, null, 1.5, '#c9ced6', 0.3)] };
            case 'spacer': return { h: b.height || 8, draw: [] };
            case 'pagebreak': return { h: 0, draw: [] };
            case 'text': {
                const m = measureText(b, width, theme, ctx);
                if (m.units.length > 2) m.split = textSplitter(m, width);
                m.minH = m.units.length ? Math.min(m.units[Math.min(1, m.units.length - 1)], m.h) : m.h;
                return m;
            }
        }
        // Data blocks
        const data = entry && entry.data;
        const t = titleDraw(b, data ? Object.assign({ snapshotNote: ctx.labels && ctx.labels.snapshot }, data) : null, width, theme);
        if (!entry || (!entry.data && !entry.error)) {
            const h = t.h + (b.height || 30);
            return { h, draw: t.draw.concat(placeholder(width, t.h, h - t.h, ctx.labels && ctx.labels.loading, theme)), state: 'loading' };
        }
        if (entry.error) {
            const h = t.h + 22;
            return { h, draw: t.draw.concat(placeholder(width, t.h, 22, entry.error, theme, true)), state: 'error' };
        }
        switch (data.kind) {
            case 'chart': {
                const ch = b.height || (blockSpan(b) >= 12 ? 78 : 68);
                return { h: t.h + ch, draw: t.draw, chart: { x: 0, y: t.h, w: width, h: ch, data, block: b } };
            }
            case 'kpi': {
                const k = measureKpi(data, width, theme, t.h);
                return { h: t.h + k.h, draw: t.draw.concat(k.draw) };
            }
            case 'table': {
                const T = measureTable(data, width, theme, t.h);
                const whole = tableDraw(T, 0, T.rows.length, true);
                const m = { h: whole.h, draw: t.draw.concat(whole.draw) };
                m.minH = t.h + T.headH + (T.rows[0] ? T.rows[0].h : 0);
                if (T.rows.length > 1) m.split = tableSplitter(T, t);
                return m;
            }
            case 'uptime': {
                const U = measureUptime(data, width, theme, t.h);
                const parts = U.units.length ? U.units : (U.empty ? [U.empty] : []);
                let y = t.h; const draw = t.draw.slice();
                parts.forEach(u => { u.draw.forEach(d => draw.push(shift(d, y))); y += u.h; });
                const m = { h: y, draw };
                m.minH = t.h + (parts[0] ? parts[0].h : 0);
                if (U.units.length > 1) m.split = unitSplitter(U.units, t);
                return m;
            }
        }
        return { h: t.h + 10, draw: t.draw };
    }

    /** Draws the box a data block shows while it loads, or in red when its data failed. */
    function placeholder(width, top, h, text, theme, isError) {
        const st = themeStyle(theme, { size: theme.size - 1, i: !isError, color: isError ? '#b42318' : '#8a8f98' });
        return [
            rect(0, top, width, h, isError ? '#fdf1f0' : '#f6f7f9', { stroke: isError ? '#f1c7c3' : '#e3e6ea', dash: !isError }),
            txt(4, top + h / 2 + 1.2, fit(text || '', width - 8, st), st),
        ];
    }

    /** Splitters: start() -> state; take(state, room, minUnits?) -> {n, h, draw, rest, continued} */
    function tableSplitter(T, title) {
        return {
            start: () => ({ from: 0, first: true }),
            /**
             * Takes as many table rows as fit in room (at least one when forced), with the header
             * repeated; returns the drawn part and the rest.
             */
            take(s, room, force) {
                let h = (s.first ? title.h : 0) + T.headH, i = s.from;
                const top0 = s.first ? title.h : 0;
                while (i < T.rows.length && (h + T.rows[i].h <= room || (force && i === s.from))) { h += T.rows[i].h; i++; }
                const n = i - s.from;
                if (!n) return { n: 0 };
                const last = i >= T.rows.length;
                const part = tableDraw(Object.assign({}, T, { top: top0 }), s.from, i, last);
                const draw = (s.first ? title.draw : []).concat(part.draw);
                return { n, h: part.h, draw, rest: last ? null : { from: i, first: false }, continued: !s.first };
            },
        };
    }

    /**
     * Returns a splitter that breaks a list of units (one per uptime service) across pages, the title
     * only on the first part.
     */
    function unitSplitter(units, title) {
        return {
            start: () => ({ from: 0, first: true }),
            /**
             * Takes as many units as fit in room (at least one when forced) and returns the drawn part
             * and the rest.
             */
            take(s, room, force) {
                let h = s.first ? title.h : 0, i = s.from;
                const draw = s.first ? title.draw.slice() : [];
                while (i < units.length && (h + units[i].h <= room || (force && i === s.from))) {
                    units[i].draw.forEach(d => draw.push(shift(d, h)));
                    h += units[i].h; i++;
                }
                const n = i - s.from;
                if (!n) return { n: 0 };
                return { n, h, draw, rest: i < units.length ? { from: i, first: false } : null, continued: !s.first };
            },
        };
    }

    /** Returns a splitter that breaks a text block's laid-out lines across pages. */
    function textSplitter(m) {
        const L = m.rich;
        return {
            start: () => ({ from: 0 }),
            /**
             * Takes as many lines as fit in room (at least one when forced), with their images,
             * rebased to the top of the part.
             */
            take(s, room, force) {
                const top = s.from ? L.lines[s.from].y : 0;
                let i = s.from;
                while (i < L.lines.length && ((L.lines[i].y + L.lines[i].h - top) <= room || (force && i === s.from))) i++;
                const n = i - s.from;
                if (!n) return { n: 0 };
                const lines = L.lines.slice(s.from, i).map(l => Object.assign({}, l, { y: l.y - top, baseline: l.baseline - top }));
                const images = L.images.filter(im => im.y >= top && im.y < (i < L.lines.length ? L.lines[i].y : Infinity)).map(im => Object.assign({}, im, { y: im.y - top }));
                const h = (i < L.lines.length ? L.lines[i].y : L.height) - top;
                return { n, h, draw: [], richPart: { height: h, lines, images }, rest: i < L.lines.length ? { from: i } : null, continued: s.from > 0 };
            },
        };
    }

    window.RPEngine = {
        PT, LINE, GUTTER, PAGE_SIZES, FONT_CSS,
        textWidth, hasUnsupported, layoutDoc, wrapPlain, paginate, pageGeometry, blockSpan, onColour, styleKey,
    };
})();
