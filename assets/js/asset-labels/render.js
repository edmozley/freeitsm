/**
 * Asset labels - drawing a label, on screen and into a PDF (GH #229).
 *
 * One list of items in millimetres (includes/asset_label_designs.php) is drawn two
 * ways from the same code:
 *
 *   - on a canvas, for the preview: everything drawn by the browser;
 *   - into a PDF at the stock's exact size (jsPDF): the QR code, lines and boxes as
 *     vector shapes, and every piece of TEXT drawn by the browser on a canvas at
 *     600 dpi and placed as an image.
 *
 * 🔑 Why text is an image. jsPDF's own text uses the standard PDF fonts, which hold
 * only Western European characters, and it cannot join Arabic letters or combine
 * Indic ones. The browser does all of that, with the bundled Noto Sans, any font
 * uploaded for labels, and the system's own fonts for anything neither has (the
 * font list falls back character by character). So every character prints, and
 * what prints is exactly what the preview showed. 600 dpi is twice what most label
 * printers print.
 *
 * 🔑 Black-only printers (`mono`). A tape or roll printer has no grey: its driver
 * turns grey into a pattern of dots, which at label sizes is unreadable (GH #229 -
 * grey captions and a speckled logo). With mono on, every text, line and box prints
 * in solid black and the logo is cut to pure black and white, in the preview too.
 *
 * @wiki Asset-Label-Designer-Design
 */
(function () {
    'use strict';
    const PT = 0.3528;                 // one point in millimetres
    const LINE = 1.18;                 // line height, as a multiple of the font size
    const DPI = 600;
    const PX_PER_MM_PDF = DPI / 25.4;

    /** The font families to try, in order: the design's font, Noto Sans, then the system's. */
    function familyFor(fontRef) {
        const m = /^font:(\d+)$/.exec(fontRef || '');
        return (m ? '"FITSM Label Font ' + m[1] + '", ' : '') + '"FITSM Noto Sans", sans-serif';
    }

    /**
     * Loads the bundled Noto Sans (four styles) and any uploaded fonts a design uses,
     * so canvas text is drawn with them. Safe to call more than once.
     *
     * @param {string} base     the app's base URL, ending in /
     * @param {string[]} refs   the designs' font references ('noto' or 'font:<id>')
     */
    const loaded = new Set();
    async function loadFonts(base, refs) {
        if (!('FontFace' in window)) return;
        const faces = [];
        const add = (key, family, url, desc) => {
            if (loaded.has(key)) return;
            loaded.add(key);
            const f = new FontFace(family, 'url(' + url + ')', desc);
            faces.push(f.load().then(ff => document.fonts.add(ff)).catch(() => { /* a broken font falls back to the next */ }));
        };
        const n = base + 'assets/fonts/noto-sans/NotoSans-';
        add('noto-r', 'FITSM Noto Sans', n + 'Regular.ttf', { weight: '400', style: 'normal' });
        add('noto-b', 'FITSM Noto Sans', n + 'Bold.ttf', { weight: '700', style: 'normal' });
        add('noto-i', 'FITSM Noto Sans', n + 'Italic.ttf', { weight: '400', style: 'italic' });
        add('noto-bi', 'FITSM Noto Sans', n + 'BoldItalic.ttf', { weight: '700', style: 'italic' });
        (refs || []).forEach(r => {
            const m = /^font:(\d+)$/.exec(r || '');
            if (m) add('font-' + m[1], 'FITSM Label Font ' + m[1], base + 'api/assets/label_font.php?id=' + m[1], {});
        });
        await Promise.all(faces);
    }

    /** "Serial: {service_tag}" with the asset's values in place of the {keys}. */
    function fillTokens(text, values) {
        return String(text || '').replace(/\{([a-z0-9_]+)\}/gi, (all, k) => (values[k] !== undefined ? values[k] : ''));
    }

    /** The lines a text-like item shows for one asset: [{text, size, bold, color}]. */
    function linesFor(it, ctx) {
        const v = ctx.values || {};
        const base = { size: it.size || 8, bold: !!it.bold, italic: !!it.italic, color: it.color || '#000000' };
        if (it.type === 'text') return [Object.assign({}, base, { text: fillTokens(it.text, v) })];
        const one = (key, size, bold) => {
            const val = v[key] || '';
            const cap = ctx.captions[key] || '';
            if (key === 'asset_tag' && val === '') return [Object.assign({}, base, { text: ctx.noTag, size, bold: false, color: '#888888' })];
            if (val === '') return [];
            // TRAP: the caption is in the item's own colour and never below 5 pt (or the value's
            // size, if that is smaller). Grey at 55% printed as dots on a tape printer (GH #229).
            if (it.caption === 'above' && cap) return [Object.assign({}, base, { text: cap, size: Math.max(size * 0.7, Math.min(size, 5)), bold: false }),
                                                       Object.assign({}, base, { text: val, size, bold })];
            if ((it.caption === 'inline' || it.caption === true) && cap && key !== 'asset_tag') return [Object.assign({}, base, { text: cap + ': ' + val, size, bold })];
            return [Object.assign({}, base, { text: val, size, bold })];
        };
        if (it.type === 'field') return one(it.field, base.size, base.bold);
        // 'fields': a list, the tag first and larger, empty values skipped - today's label.
        const out = [];
        (it.fields || []).forEach(k => {
            out.push(...one(k, k === 'asset_tag' ? (it.tag_size || base.size) : base.size, k === 'asset_tag' ? true : base.bold));
        });
        return out;
    }

    /** A canvas font string for a line, at px pixels per millimetre. */
    function fontOf(line, px, family) {
        return (line.italic ? 'italic ' : '') + (line.bold ? '700 ' : '400 ') + (line.size * PT * px).toFixed(2) + 'px ' + family;
    }

    /** text cut to fit maxW with "...", measured with the context's current font. */
    function ellipsize(g, text, maxW) {
        if (g.measureText(text).width <= maxW) return text;
        let lo = 0, hi = text.length;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (g.measureText(text.slice(0, mid) + '…').width <= maxW) lo = mid; else hi = mid - 1;
        }
        return lo > 0 ? text.slice(0, lo) + '…' : '';
    }

    /** Word-wraps text to maxW; a single word wider than the box is left for ellipsize. */
    function wrap(g, text, maxW) {
        const words = text.split(/\s+/).filter(Boolean);
        const lines = [];
        let cur = '';
        words.forEach(w => {
            const t = cur ? cur + ' ' + w : w;
            if (cur && g.measureText(t).width > maxW) { lines.push(cur); cur = w; } else cur = t;
        });
        if (cur) lines.push(cur);
        return lines.length ? lines : [''];
    }

    /**
     * Draws a text-like item into its box. g is a canvas context whose origin is the
     * box's top-left corner and whose scale is px pixels per millimetre. Long values
     * follow the item's fit: shrink (down to min), wrap, or clip with "...". Nothing
     * is drawn outside the box. Returns whether anything was drawn.
     */
    function drawText(g, it, ctx, px) {
        let lines = linesFor(it, ctx).filter(l => l.text !== '');
        if (!lines.length) return false;
        const W = it.w * px, H = it.h * px;
        const family = familyFor(ctx.font);
        const min = it.min || 4;
        // The HEIGHT is shared: every line shrinks together until they all fit the box's
        // height. The WIDTH is each line's own: a long value shrinks only its own line
        // (shrink), so one long model name does not make the whole label smaller.
        let k = 1;
        const fitsHeight = (kk) => lines.reduce((s, l) => s + l.size * kk * PT * px * LINE, 0) <= H + 0.5;
        if (it.fit === 'shrink' || it.fit === 'wrap' || !it.fit) {
            const smallest = Math.min(...lines.map(l => min / l.size));
            while (k > smallest && !fitsHeight(k)) k -= 0.04;
            k = Math.max(k, Math.min(1, smallest));
        }
        // Lay out: wrapped lines where asked, every line cut to the width.
        const laid = [];
        lines.forEach(l0 => {
            const l = Object.assign({}, l0, { size: l0.size * k });
            g.font = fontOf(l, px, family);
            if (it.fit === 'shrink' || !it.fit) {
                const wNow = g.measureText(l.text).width;
                if (wNow > W) {
                    l.size = Math.max(min, l.size * W / wNow);
                    g.font = fontOf(l, px, family);
                }
            }
            const parts = it.fit === 'wrap' ? wrap(g, l.text, W) : [l.text];
            parts.forEach(p => laid.push(Object.assign({}, l, { text: ellipsize(g, p, W) })));
        });
        let total = laid.reduce((s, l) => s + l.size * PT * px * LINE, 0);
        while (laid.length > 1 && total > H + 0.5) { const l = laid.pop(); total -= l.size * PT * px * LINE; }
        let y = it.valign === 'top' ? 0 : it.valign === 'bottom' ? H - total : (H - total) / 2;
        g.save();
        g.beginPath(); g.rect(0, 0, W, H); g.clip();
        g.textBaseline = 'middle';
        laid.forEach(l => {
            const lh = l.size * PT * px * LINE;
            g.font = fontOf(l, px, family);
            g.fillStyle = ctx.mono ? '#000000' : (l.color || '#000000');
            g.textAlign = it.align === 'center' ? 'center' : it.align === 'right' ? 'right' : 'left';
            const x = it.align === 'center' ? W / 2 : it.align === 'right' ? W : 0;
            g.fillText(l.text, x, y + lh / 2);
            y += lh;
        });
        g.restore();
        return true;
    }

    /** The QR's dark squares for an address: {n, dark(r, c)}. */
    function qrMatrix(url, ec) {
        const q = window.qrcode(0, ec);
        q.addData(url);
        q.make();
        return { n: q.getModuleCount(), dark: (r, c) => q.isDark(r, c) };
    }

    /** Draws an image to fit inside w x h (mm) at x, y, keeping its proportions. */
    function containRect(img, x, y, w, h) {
        const r = Math.min(w / img.naturalWidth, h / img.naturalHeight);
        const dw = img.naturalWidth * r, dh = img.naturalHeight * r;
        return { x: x + (w - dw) / 2, y: y + (h - dh) / 2, w: dw, h: dh };
    }

    /** Does a design print in solid black only? Unset follows the size: tape and roll printers are black-only. */
    function monoFor(design, stock) {
        return typeof (design || {}).mono === 'boolean' ? design.mono : (stock || {}).kind !== 'sheet';
    }

    /**
     * The logo cut to pure black and white, for a black-only printer: each pixel, laid
     * on white, is black if darker than about 60% grey, otherwise left clear. Enlarged
     * first (to 1200 px on its long side) so a small logo keeps smooth edges. The
     * result is a canvas that carries naturalWidth / naturalHeight like the image did.
     */
    const monoCache = new WeakMap();
    function monoImage(img) {
        if (!img) return null;
        if (monoCache.has(img)) return monoCache.get(img);
        const nw = img.naturalWidth || img.width, nh = img.naturalHeight || img.height;
        if (!nw || !nh) return img;
        const r = 1200 / Math.max(nw, nh);
        const cv = document.createElement('canvas');
        cv.width = Math.max(1, Math.round(nw * r)); cv.height = Math.max(1, Math.round(nh * r));
        const g = cv.getContext('2d');
        g.drawImage(img, 0, 0, cv.width, cv.height);
        let out = cv;
        try {
            const d = g.getImageData(0, 0, cv.width, cv.height), p = d.data;
            for (let i = 0; i < p.length; i += 4) {
                const a = p[i + 3] / 255;
                const lum = (0.299 * p[i] + 0.587 * p[i + 1] + 0.114 * p[i + 2]) * a + 255 * (1 - a);
                p[i] = p[i + 1] = p[i + 2] = 0;
                p[i + 3] = lum < 160 ? 255 : 0;
            }
            g.putImageData(d, 0, 0);
            cv.naturalWidth = cv.width; cv.naturalHeight = cv.height;
        } catch (e) {
            out = img;   // a logo from another site cannot be read back: print it as it is
        }
        monoCache.set(img, out);
        return out;
    }

    /** Runs a rotated item: rot 90 / 180 / 270 turns the box about its centre. */
    function rotated(g, it, px, draw) {
        const rot = (+it.rot || 0) % 360;
        if (!rot) { g.save(); g.translate(it.x * px, it.y * px); draw(it); g.restore(); return; }
        g.save();
        g.translate((it.x + it.w / 2) * px, (it.y + it.h / 2) * px);
        g.rotate(rot * Math.PI / 180);
        const swap = rot === 90 || rot === 270;
        const inner = Object.assign({}, it, { w: swap ? it.h : it.w, h: swap ? it.w : it.h });
        g.translate(-inner.w / 2 * px, -inner.h / 2 * px);
        draw(inner);
        g.restore();
    }

    /**
     * Draws one whole label on a canvas context, px pixels per millimetre, for the
     * preview. ctx: {items, values, url, captions, noTag, font, logo (an Image or null), mono}.
     */
    function drawLabel(g, ctx, px) {
        if (ctx.mono && ctx.logo) ctx = Object.assign({}, ctx, { logo: monoImage(ctx.logo) });
        (ctx.items || []).forEach(it => {
            if (it.type === 'qr') {
                if (!ctx.url) return;
                const m = qrMatrix(ctx.url, it.logo && ctx.logo ? 'H' : 'M');
                const s = Math.min(it.w, it.h) * px / m.n;
                g.fillStyle = '#000000';
                for (let r = 0; r < m.n; r++) for (let c = 0; c < m.n; c++) {
                    if (m.dark(r, c)) g.fillRect(it.x * px + c * s, it.y * px + r * s, Math.ceil(s), Math.ceil(s));
                }
                if (it.logo && ctx.logo) {
                    const side = Math.min(it.w, it.h) * 0.22, bx = it.x + (Math.min(it.w, it.h) - side) / 2, by = it.y + (Math.min(it.w, it.h) - side) / 2;
                    g.fillStyle = '#ffffff';
                    g.fillRect(bx * px, by * px, side * px, side * px);
                    const r = containRect(ctx.logo, bx + side * 0.04, by + side * 0.04, side * 0.92, side * 0.92);
                    g.drawImage(ctx.logo, r.x * px, r.y * px, r.w * px, r.h * px);
                }
            } else if (it.type === 'image') {
                if (!ctx.logo) return;
                rotated(g, it, px, b => { const r = containRect(ctx.logo, 0, 0, b.w, b.h); g.drawImage(ctx.logo, r.x * px, r.y * px, r.w * px, r.h * px); });
            } else if (it.type === 'line' || it.type === 'box') {
                const ink = ctx.mono ? '#000000' : (it.color || '#000000');
                g.strokeStyle = ink;
                g.lineWidth = (it.thick || 0.3) * px;
                if (it.type === 'line') { g.beginPath(); g.moveTo(it.x * px, it.y * px); g.lineTo((it.x + it.w) * px, (it.y + it.h) * px); g.stroke(); }
                else if (it.fill) { g.fillStyle = ink; g.fillRect(it.x * px, it.y * px, it.w * px, it.h * px); }
                else g.strokeRect(it.x * px, it.y * px, it.w * px, it.h * px);
            } else {
                rotated(g, it, px, b => drawText(g, b, ctx, px));
            }
        });
    }

    /**
     * Builds the PDF: one page per label on a tape, labels laid out on pages for a
     * sheet. job: {stock, items, labels:[{values, url}], captions, noTag, font, logo,
     * mono, onProgress(done, total)}. Returns the jsPDF document.
     */
    async function pdf(job) {
        const st = job.stock;
        const J = window.jspdf.jsPDF;
        const tape = st.kind !== 'sheet';
        const pw = tape ? st.w : st.page_w, ph = tape ? st.h : st.page_h;
        const doc = new J({ unit: 'mm', format: [pw, ph], orientation: pw >= ph ? 'l' : 'p', compress: true });
        const perPage = tape ? 1 : st.cols * st.rows;
        const logo = job.mono ? monoImage(job.logo) : job.logo;
        const logoUrl = logo ? imageData(logo, 1200) : null;
        const staticCache = new Map();   // the same text on every label is drawn once

        for (let i = 0; i < job.labels.length; i++) {
            const lab = job.labels[i];
            if (i > 0 && i % perPage === 0) doc.addPage([pw, ph], pw >= ph ? 'l' : 'p');
            const slot = i % perPage;
            const ox = tape ? 0 : st.left + (slot % st.cols) * st.h_pitch + (st.offset_x || 0);
            const oy = tape ? 0 : st.top + Math.floor(slot / st.cols) * st.v_pitch + (st.offset_y || 0);
            const ctx = { values: lab.values, captions: job.captions, noTag: job.noTag, font: job.font, mono: !!job.mono };
            for (const it of job.items) {
                const x = ox + it.x, y = oy + it.y;
                if (it.type === 'qr') {
                    if (!lab.url) continue;
                    const m = qrMatrix(lab.url, it.logo && job.logo ? 'H' : 'M');
                    const s = Math.min(it.w, it.h) / m.n;
                    doc.setFillColor(0, 0, 0);
                    for (let r = 0; r < m.n; r++) {
                        let c = 0;
                        while (c < m.n) {
                            if (!m.dark(r, c)) { c++; continue; }
                            let e = c;
                            while (e < m.n && m.dark(r, e)) e++;
                            doc.rect(x + c * s, y + r * s, (e - c) * s, s, 'F');   // one rectangle per run of squares
                            c = e;
                        }
                    }
                    if (it.logo && logoUrl) {
                        const side = Math.min(it.w, it.h) * 0.22, bx = x + (Math.min(it.w, it.h) - side) / 2, by = y + (Math.min(it.w, it.h) - side) / 2;
                        doc.setFillColor(255, 255, 255);
                        doc.rect(bx, by, side, side, 'F');
                        const r = containRect(logo,bx + side * 0.04, by + side * 0.04, side * 0.92, side * 0.92);
                        doc.addImage(logoUrl, 'PNG', r.x, r.y, r.w, r.h, 'label-logo', 'FAST');
                    }
                } else if (it.type === 'image') {
                    if (!logoUrl) continue;
                    const img = renderToCanvas(it, b => (g, px) => { const r = containRect(logo, 0, 0, b.w, b.h); g.drawImage(logo, r.x * px, r.y * px, r.w * px, r.h * px); return true; });
                    if (img) doc.addImage(img, 'PNG', x, y, it.w, it.h, 'label-image-' + it.id, 'FAST');
                } else if (it.type === 'line' || it.type === 'box') {
                    const c = job.mono ? [0, 0, 0] : hexRgb(it.color);
                    doc.setDrawColor(c[0], c[1], c[2]); doc.setFillColor(c[0], c[1], c[2]);
                    doc.setLineWidth(it.thick || 0.3);
                    if (it.type === 'line') doc.line(x, y, x + it.w, y + it.h);
                    else doc.rect(x, y, it.w, it.h, it.fill ? 'F' : 'S');
                } else {
                    // Text: drawn by the browser at 600 dpi. Text with no field in it is the
                    // same on every label, so it is drawn once and the image reused.
                    const isStatic = it.type === 'text' && !/\{[a-z0-9_]+\}/i.test(it.text || '');
                    const key = 'label-text-' + it.id;
                    let data = isStatic ? staticCache.get(key) : undefined;
                    if (data === undefined) {
                        data = renderToCanvas(it, b => (g, px) => drawText(g, b, ctx, px));
                        if (isStatic) staticCache.set(key, data);
                    }
                    if (data) doc.addImage(data, 'PNG', x, y, it.w, it.h, isStatic ? key : undefined, 'FAST');
                }
            }
            if (job.onProgress && (i % 10 === 9 || i === job.labels.length - 1)) {
                job.onProgress(i + 1, job.labels.length);
                await new Promise(r => setTimeout(r));   // let the page repaint between batches
            }
        }
        return doc;
    }

    /**
     * Draws one item, alone, onto a transparent canvas the size of its box at 600 dpi,
     * honouring its rotation. Returns a PNG data URL, or null when nothing was drawn.
     */
    function renderToCanvas(it, make) {
        const px = PX_PER_MM_PDF;
        const cv = document.createElement('canvas');
        cv.width = Math.max(1, Math.round(it.w * px));
        cv.height = Math.max(1, Math.round(it.h * px));
        const g = cv.getContext('2d');
        let drew = false;
        rotated(g, Object.assign({}, it, { x: 0, y: 0 }), px, b => { drew = make(b)(g, px) || drew; });
        return drew ? cv.toDataURL('image/png') : null;
    }

    /** A loaded image as a PNG data URL, no wider than maxPx (an SVG logo becomes pixels). */
    function imageData(img, maxPx) {
        const r = Math.min(1, maxPx / Math.max(img.naturalWidth, img.naturalHeight));
        const cv = document.createElement('canvas');
        cv.width = Math.max(1, Math.round(img.naturalWidth * r));
        cv.height = Math.max(1, Math.round(img.naturalHeight * r));
        cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
        return cv.toDataURL('image/png');
    }

    /** '#rrggbb' as [r, g, b]; black for anything else. */
    function hexRgb(h) {
        const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(h || '');
        return m ? [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)] : [0, 0, 0];
    }

    window.LabelRender = { loadFonts, drawLabel, pdf, familyFor, monoFor };
})();
