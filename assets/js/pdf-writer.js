/**
 * FreeITSM's one PDF writer (GH #254) - every PDF export goes through this file.
 *
 * WHAT IT IS
 * ----------
 * A small layer with a jsPDF-shaped interface - text(), setFont(), rect(),
 * addImage(), autoTable(), save() - that draws with PDFKit underneath. The
 * exports were written for jsPDF; through this layer each needed only a few
 * lines changed, and the next time the library changes, only this file does.
 *
 * WHY PDFKIT, NOT JSPDF
 * ---------------------
 * jsPDF's built-in fonts hold only Western European letters, and jsPDF has no
 * "shaping engine" - the part that joins letters in Hindi, Tamil, Bengali and
 * the other scripts whose letters change shape with their neighbours. PDFKit
 * has one (fontkit). With the bundled Noto fonts, every language FreeITSM ships
 * prints correctly. Full reasoning: wiki "PDF-Exports-Move-To-PDFKit".
 *
 * HOW TEXT GETS THE RIGHT FONT
 * ----------------------------
 * A font holds the shapes for some scripts only. So a string is split into RUNS,
 * each drawn in the font that has its letters: the main Noto Sans / Serif /
 * Mono for Latin, Greek and Cyrillic, and Noto Sans <Script> for Devanagari,
 * Tamil and the rest. Spaces and punctuation between two letters of the same
 * script stay in that script's run (so a Hindi sentence is shaped as a whole),
 * unless that font lacks the character - then they go to the main font.
 *
 * Script fonts are big, so they are fetched only when the text needs them:
 * create(opts, text) and ready(text) look at the text first. Text drawn later
 * in a script that was not fetched falls back to the main font (empty boxes)
 * and logs a warning - pass the text in.
 *
 * LOADING
 * -------
 * PDFKit is a modern JavaScript MODULE (assets/js/vendor/pdfkit/pdfkit.bundle.js),
 * and this file is a classic script, so it loads PDFKit with import() the first
 * time a PDF is made. Pages include only this file. See wiki
 * "JavaScript-Modules-And-Vendoring-Explained".
 *
 * KNOWN LIMITS
 * ------------
 * Chinese, Japanese and Korean have no bundled font (each is 5-20 MB) and print
 * as empty boxes. Arabic and Hebrew letters join correctly, but punctuation and
 * spaces in a line that mixes right-to-left and left-to-right text can be out of
 * order (there is no bidi pass). Copying Indian-script text OUT of a PDF can
 * give the letters in a different order - the printed text is right.
 *
 * @wiki PDF-Writer-Developer-Guide
 */
(function () {
    'use strict';

    // The app root, from this script's own URL: .../assets/js/pdf-writer.js?v=1
    const SELF = (document.currentScript && document.currentScript.src) || '';
    const ROOT = SELF.replace(/assets\/js\/pdf-writer\.js.*$/, '');
    const FONT_DIR = ROOT + 'assets/fonts/';

    // ── Fonts ─────────────────────────────────────────────────────────────
    const BASE_FILES = {
        'sans-normal': 'noto-sans/NotoSans-Regular.ttf',
        'sans-bold': 'noto-sans/NotoSans-Bold.ttf',
        'sans-italic': 'noto-sans/NotoSans-Italic.ttf',
        'sans-bolditalic': 'noto-sans/NotoSans-BoldItalic.ttf',
        'serif-normal': 'noto-serif/NotoSerif-Regular.ttf',
        'serif-bold': 'noto-serif/NotoSerif-Bold.ttf',
        'serif-italic': 'noto-serif/NotoSerif-Italic.ttf',
        'serif-bolditalic': 'noto-serif/NotoSerif-BoldItalic.ttf',
        'mono-normal': 'noto-sans-mono/NotoSansMono-Regular.ttf',
        'mono-bold': 'noto-sans-mono/NotoSansMono-Bold.ttf',
    };

    /** Each script FreeITSM has a font for: its Unicode ranges and its Noto family. */
    const SCRIPTS = {
        deva: { file: 'Devanagari', ranges: [[0x0900, 0x097F], [0xA8E0, 0xA8FF], [0x1CD0, 0x1CFF]] },
        beng: { file: 'Bengali', ranges: [[0x0980, 0x09FF]] },
        guru: { file: 'Gurmukhi', ranges: [[0x0A00, 0x0A7F]] },
        gujr: { file: 'Gujarati', ranges: [[0x0A80, 0x0AFF]] },
        taml: { file: 'Tamil', ranges: [[0x0B80, 0x0BFF]] },
        telu: { file: 'Telugu', ranges: [[0x0C00, 0x0C7F]] },
        knda: { file: 'Kannada', ranges: [[0x0C80, 0x0CFF]] },
        mlym: { file: 'Malayalam', ranges: [[0x0D00, 0x0D7F]] },
        thai: { file: 'Thai', ranges: [[0x0E00, 0x0E7F]] },
        hebr: { file: 'Hebrew', ranges: [[0x0590, 0x05FF], [0xFB1D, 0xFB4F]] },
        arab: { file: 'Arabic', ranges: [[0x0600, 0x06FF], [0x0750, 0x077F], [0x08A0, 0x08FF], [0xFB50, 0xFDFF], [0xFE70, 0xFEFF]] },
    };
    /** The script of each FreeITSM interface language that needs one - its own words (footers, labels) are in it. */
    const LOCALE_SCRIPT = { hi: 'deva', mr: 'deva', ne: 'deva', bn: 'beng', pa: 'guru', gu: 'gujr', ta: 'taml', te: 'telu', kn: 'knda', ml: 'mlym', th: 'thai', he: 'hebr', ar: 'arab', ur: 'arab', fa: 'arab' };

    /** Which of SCRIPTS a code point belongs to, or null (Latin, Greek, Cyrillic, punctuation...). */
    function scriptOf(cp) {
        if (cp < 0x0590) return null;
        for (const k in SCRIPTS) {
            for (const r of SCRIPTS[k].ranges) if (cp >= r[0] && cp <= r[1]) return k;
        }
        return null;
    }

    /** Characters that take the script of their neighbours: spaces, punctuation, digits, joiners, dandas. */
    function isNeutral(cp) {
        return cp === 0x20 || cp === 0xA0 || (cp >= 0x21 && cp <= 0x40) || (cp >= 0x5B && cp <= 0x60) || (cp >= 0x7B && cp <= 0x7E)
            || cp === 0x200C || cp === 0x200D || cp === 0x0964 || cp === 0x0965 || (cp >= 0x2000 && cp <= 0x206F);
    }

    /** The scripts a text needs (and the interface language's), as a Set of SCRIPTS keys. */
    function scriptsIn(text) {
        const out = new Set();
        const lang = (document.documentElement.lang || '').toLowerCase().split('-')[0];
        if (LOCALE_SCRIPT[lang]) out.add(LOCALE_SCRIPT[lang]);
        for (const ch of String(text || '')) {
            const s = scriptOf(ch.codePointAt(0));
            if (s) out.add(s);
        }
        return out;
    }

    /** Is every character of this text in a script some bundled font covers? (False for Chinese, Japanese, Korean...) */
    function supported(text) {
        for (const ch of String(text || '')) {
            const cp = ch.codePointAt(0);
            if (cp < 0x0590 || scriptOf(cp)) continue;
            if ((cp >= 0x1E00 && cp <= 0x1FFF) || (cp >= 0x2000 && cp <= 0x2BFF)) continue;   // Latin extended, Greek extended, punctuation and symbols
            return false;
        }
        return true;
    }

    // Font files are fetched from THIS server (assets/fonts/), never the internet,
    // so an air-gapped install has everything it needs.
    const bytes = new Map();            // font file -> Promise<Uint8Array>, kept for the page's lifetime
    const fontData = new Map();         // font file -> Uint8Array, once fetched (documents read it synchronously)
    /** Fetches a font file once per page. */
    function fetchFont(file) {
        if (!bytes.has(file)) {
            bytes.set(file, fetch(FONT_DIR + file).then(r => {
                if (!r.ok) throw new Error('Font not found: ' + file);
                return r.arrayBuffer();
            }).then(b => { const u = new Uint8Array(b); fontData.set(file, u); return u; }));
        }
        return bytes.get(file);
    }

    let lib = null;                     // the PDFKit module, once imported
    let metrics = null;                 // a PDFKit document used only to measure text
    const loadedScripts = new Set();
    let ready_ = null;

    /** The font files a set of scripts needs: Regular and Bold of each. */
    function scriptFiles(scripts) {
        const files = {};
        scripts.forEach(s => {
            files[s + '-normal'] = 'noto-sans-scripts/NotoSans' + SCRIPTS[s].file + '-Regular.ttf';
            files[s + '-bold'] = 'noto-sans-scripts/NotoSans' + SCRIPTS[s].file + '-Bold.ttf';
        });
        return files;
    }

    /**
     * Loads PDFKit and the fonts a text needs. Resolves when measure() and create()
     * can be used for it. Safe to call again with more text - only new scripts are fetched.
     *
     * @param {string} [text]  everything the PDF (or layout) will contain, for its scripts
     */
    async function ready(text) {
        if (!ready_) {
            ready_ = (async () => {
                lib = await import(ROOT + 'assets/js/vendor/pdfkit/pdfkit.bundle.js');
                const entries = await Promise.all(Object.entries(BASE_FILES).map(async ([k, f]) => [k, await fetchFont(f)]));
                metrics = new lib.PDFDocument({ font: null, autoFirstPage: false });
                entries.forEach(([k, b]) => metrics.registerFont(k, b));
            })();
        }
        await ready_;
        const need = [...scriptsIn(text)].filter(s => !loadedScripts.has(s));
        if (need.length) {
            const files = scriptFiles(need);
            const entries = await Promise.all(Object.entries(files).map(async ([k, f]) => [k, await fetchFont(f)]));
            entries.forEach(([k, b]) => metrics.registerFont(k, b));
            need.forEach(s => loadedScripts.add(s));
        }
    }

    /** True once ready() has finished at least once - measure() can be called. */
    function isReady() { return metrics !== null; }

    // ── Fonts by name ─────────────────────────────────────────────────────
    const FAMILY = { helvetica: 'sans', arial: 'sans', sans: 'sans', 'sans-serif': 'sans', times: 'serif', serif: 'serif', courier: 'mono', mono: 'mono', monospace: 'mono' };

    /** jsPDF's style names (normal, bold, italic, bolditalic, and their spellings) as ours. */
    function styleName(style) {
        const s = String(style || 'normal').toLowerCase().replace(/[\s_-]/g, '');
        if (s === 'bolditalic' || s === 'bolditalics' || s === 'italicbold' || s === 'boldoblique') return 'bolditalic';
        if (s === 'bold') return 'bold';
        if (s === 'italic' || s === 'oblique') return 'italic';
        return 'normal';
    }

    /** The registered name of the main font for a family and style (mono has no italic). */
    function baseFont(family, style) {
        const fam = FAMILY[String(family || 'sans').toLowerCase()] || 'sans';
        let st = styleName(style);
        if (fam === 'mono') st = (st === 'bold' || st === 'bolditalic') ? 'bold' : 'normal';
        return fam + '-' + st;
    }

    /** The registered name of a script's font for a style - script fonts are Regular and Bold only. */
    function scriptFont(script, style) {
        const st = styleName(style);
        return script + '-' + (st === 'bold' || st === 'bolditalic' ? 'bold' : 'normal');
    }

    const glyphCache = new Map();
    /**
     * Does a registered font have a glyph for this code point?
     * TRAP: reads PDFKit's internal `_font.font` - the fontkit font behind an embedded
     * font. PDFKit has no public "has this character" call. If an upgrade renames it,
     * this answers true and a missing character shows as an empty box - the probe in
     * tests/pdf-writer.html checks it.
     */
    function hasGlyph(fontName, cp) {
        const key = fontName + ':' + cp;
        if (glyphCache.has(key)) return glyphCache.get(key);
        let ok = true;
        try {
            metrics.font(fontName);
            const f = metrics._font && metrics._font.font;
            if (f && typeof f.hasGlyphForCodePoint === 'function') ok = f.hasGlyphForCodePoint(cp);
        } catch (e) { ok = false; }
        glyphCache.set(key, ok);
        return ok;
    }

    /**
     * Splits a string into runs, each with the registered font that has its letters.
     * See "HOW TEXT GETS THE RIGHT FONT" at the top.
     *
     * @return {Array<{text:string, font:string}>}
     */
    function runs(str, family, style) {
        const chars = Array.from(String(str));
        if (!chars.length) return [];
        const base = baseFont(family, style);
        const cps = chars.map(c => c.codePointAt(0));
        const scr = cps.map(cp => (isNeutral(cp) ? undefined : scriptOf(cp)));   // undefined = neutral, null = main font
        const fonts = new Array(chars.length);
        for (let i = 0; i < chars.length; i++) {
            let s = scr[i];
            if (s === undefined) {
                // A neutral character takes the script of the letters around it: both sides
                // if they agree, or the only side there is (a "।" ending a Hindi sentence).
                // Then the first font that actually HAS it - that script's, or the main font.
                let p = i - 1; while (p >= 0 && scr[p] === undefined) p--;
                let n = i + 1; while (n < chars.length && scr[n] === undefined) n++;
                const ps = p >= 0 ? scr[p] : null, ns = n < chars.length ? scr[n] : null;
                const wanted = (ps && ns) ? (ps === ns ? ps : null) : (ps || ns);
                const tryOrder = [wanted, null, ps, ns].filter((v, j, a) => a.indexOf(v) === j);
                s = null;
                for (const cand of tryOrder) {
                    if (cand === null) { if (hasGlyph(base, cps[i])) { s = null; break; } continue; }
                    if (loadedScripts.has(cand) && hasGlyph(scriptFont(cand, style), cps[i])) { s = cand; break; }
                }
            }
            if (s && !loadedScripts.has(s)) {
                if (!runs.warned) { console.warn('pdf-writer: text in a script whose font was not loaded - pass the text to create() or ready()'); runs.warned = true; }
                s = null;
            }
            fonts[i] = s ? scriptFont(s, style) : base;
        }
        const out = [];
        for (let i = 0; i < chars.length; i++) {
            if (out.length && out[out.length - 1].font === fonts[i]) out[out.length - 1].text += chars[i];
            else out.push({ text: chars[i], font: fonts[i] });
        }
        return out;
    }

    /**
     * The width of a string in POINTS, shaped - joined letters measured as they print.
     * Synchronous; ready() must have finished first.
     *
     * @param {string} str
     * @param {string} family  'helvetica'|'sans', 'times'|'serif', 'courier'|'mono'
     * @param {string} style   'normal', 'bold', 'italic', 'bolditalic'
     * @param {number} size    font size in points
     */
    function measure(str, family, style, size) {
        if (!metrics) throw new Error('pdf-writer: call FitsmPdf.ready() before measure()');
        let w = 0;
        for (const r of runs(str, family, style)) w += metrics.font(r.font).fontSize(size).widthOfString(r.text);
        return w;
    }

    // ── The document ──────────────────────────────────────────────────────
    const UNITS = { pt: 1, mm: 72 / 25.4, cm: 72 / 2.54, in: 72, px: 0.75 };
    const PAPER = { a3: [841.89, 1190.55], a4: [595.28, 841.89], a5: [419.53, 595.28], letter: [612, 792], legal: [612, 1008], tabloid: [792, 1224] };
    const LINE_FACTOR = 1.15;            // jsPDF's line height for an array of lines

    /** A colour from jsPDF's forms - (r, g, b), (grey), ('#rrggbb'), ([r, g, b]) - as [r, g, b]. */
    function colour(a, b, c) {
        if (Array.isArray(a)) return a.slice(0, 3);
        if (typeof a === 'string') {
            const m = /^#?([0-9a-f]{6})$/i.exec(a);
            if (m) { const n = parseInt(m[1], 16); return [n >> 16, (n >> 8) & 255, n & 255]; }
            const s = /^#?([0-9a-f]{3})$/i.exec(a);
            if (s) return s[1].split('').map(h => parseInt(h + h, 16));
            return [0, 0, 0];
        }
        if (b === undefined) return [a, a, a];
        return [a, b, c];
    }

    /** An image source an export hands over - data URL, <img>, <canvas> - as a data URL PDFKit can read. */
    function imageData(src, format) {
        if (typeof src === 'string') return src;
        if (src && typeof src.toDataURL === 'function') return src.toDataURL(String(format || '').toUpperCase() === 'JPEG' ? 'image/jpeg' : 'image/png');
        if (src && src.naturalWidth !== undefined) {
            const cv = document.createElement('canvas');
            cv.width = src.naturalWidth || src.width; cv.height = src.naturalHeight || src.height;
            cv.getContext('2d').drawImage(src, 0, 0);
            return cv.toDataURL('image/png');
        }
        throw new Error('pdf-writer: unsupported image');
    }

    /** Page size in points from jsPDF's format and orientation. */
    function pageSize(format, orientation, k) {
        let wh;
        if (Array.isArray(format)) wh = [format[0] * k, format[1] * k];
        else wh = (PAPER[String(format || 'a4').toLowerCase()] || PAPER.a4).slice();
        const o = String(orientation || '').toLowerCase();
        if ((o === 'l' || o === 'landscape') && wh[0] < wh[1]) wh = [wh[1], wh[0]];
        if ((o === 'p' || o === 'portrait') && wh[0] > wh[1]) wh = [wh[1], wh[0]];
        return wh;
    }

    /**
     * One PDF. Coordinates and sizes are in the document's unit (mm, pt...), text y
     * is the BASELINE - exactly as in jsPDF, so the exports' arithmetic is unchanged.
     */
    class PdfDoc {
        constructor(opts) {
            opts = opts || {};
            this.k = UNITS[opts.unit || 'mm'] || UNITS.mm;
            this.size = pageSize(opts.format, opts.orientation, this.k);
            this.pk = new lib.PDFDocument({ size: this.size, margin: 0, font: null, bufferPages: true, compress: true, autoFirstPage: true });
            this.chunks = [];
            this.pk.on('data', c => this.chunks.push(c));
            // TRAP: listen for 'error' too. A failure while PDFKit finishes the file (a bad
            // image, say) emits 'error' and never 'end' - without this, save() waits forever.
            this.ended = new Promise((resolve, reject) => { this.pk.on('end', resolve); this.pk.on('error', reject); });
            Object.entries(BASE_FILES).forEach(([k, file]) => this.pk.registerFont(k, fontData.get(file)));
            this._registered = new Set(Object.keys(BASE_FILES));
            this.font = { family: 'sans', style: 'normal', size: 16 };
            this.textCol = [0, 0, 0]; this.drawCol = [0, 0, 0]; this.fillCol = [0, 0, 0];
            this.lineW = 0.2 * UNITS.mm; this.dashArr = null;
            this.aliases = new Map();
            this.lastAutoTable = null;
            const self = this;
            this.internal = {
                pageSize: { getWidth: () => self.size[0] / self.k, getHeight: () => self.size[1] / self.k },
                getNumberOfPages: () => self.getNumberOfPages(),
            };
        }

        /** Registers a script font in this document the first time a run needs it. */
        _use(fontName) {
            if (!this._registered.has(fontName)) {
                const s = fontName.split('-')[0];
                const file = scriptFiles([s])[fontName];
                this.pk.registerFont(fontName, fontData.get(file));
                this._registered.add(fontName);
            }
            return fontName;
        }

        // Fonts and colours ------------------------------------------------
        setFont(family, style) { if (family) this.font.family = family; this.font.style = style || 'normal'; return this; }
        setFontSize(size) { this.font.size = size; return this; }
        getFontSize() { return this.font.size; }
        setTextColor(a, b, c) { this.textCol = colour(a, b, c); return this; }
        setDrawColor(a, b, c) { this.drawCol = colour(a, b, c); return this; }
        setFillColor(a, b, c) { this.fillCol = colour(a, b, c); return this; }
        setLineWidth(w) { this.lineW = w * this.k; return this; }
        setLineDashPattern(arr, phase) { this.dashArr = arr && arr.length ? [arr.map(v => v * this.k), (phase || 0) * this.k] : null; return this; }
        setProperties(p) { if (p && p.title) this.pk.info.Title = p.title; if (p && p.subject) this.pk.info.Subject = p.subject; if (p && p.author) this.pk.info.Author = p.author; this.pk.info.Creator = (p && p.creator) || 'FreeITSM'; return this; }

        // Text ----------------------------------------------------------------
        /** Width of a string in the document's unit, in the current font. */
        getTextWidth(str) { return measure(str, this.font.family, this.font.style, this.font.size) / this.k; }
        /** jsPDF's unitless width (width / font size) - kept for old callers. */
        getStringUnitWidth(str) { return measure(str, this.font.family, this.font.style, this.font.size) / this.font.size; }

        /**
         * Wraps text to a width (in the document's unit) and returns the lines. Breaks at
         * spaces; a word too long for a line is broken between characters - whole
         * characters as people see them (Intl.Segmenter), never inside a joined letter.
         */
        splitTextToSize(text, maxW) {
            const max = maxW;
            const out = [];
            const fits = s => this.getTextWidth(s) <= max;
            const graphemes = s => (typeof Intl !== 'undefined' && Intl.Segmenter)
                ? Array.from(new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(s), x => x.segment)
                : Array.from(s);
            String(text == null ? '' : text).split(/\r?\n/).forEach(para => {
                const words = para.split(/ +/);
                let line = '';
                words.forEach(word => {
                    const t = line ? line + ' ' + word : word;
                    if (fits(t)) { line = t; return; }
                    if (line) { out.push(line); line = ''; }
                    if (fits(word)) { line = word; return; }
                    // A word longer than the line: break it between characters.
                    let piece = '';
                    graphemes(word).forEach(g => {
                        if (piece && !fits(piece + g)) { out.push(piece); piece = g; } else piece += g;
                    });
                    line = piece;
                });
                out.push(line);
            });
            return out;
        }

        /**
         * Draws text at x, y (y is the baseline). text may be an array of lines, drawn one
         * under another. opts.align: 'left' (default), 'center', 'right' - x is then the
         * centre or the right edge, as in jsPDF.
         */
        text(text, x, y, opts) {
            opts = opts || {};
            const lines = Array.isArray(text) ? text : String(text == null ? '' : text).split(/\r?\n/);
            const lh = this.font.size * LINE_FACTOR / this.k;
            lines.forEach((ln, i) => this._line(String(ln), x, y + i * lh, opts.align));
            return this;
        }

        _line(str, x, y, align) {
            if (str === '') return;
            const parts = runs(str, this.font.family, this.font.style);
            const sz = this.font.size;
            const widths = parts.map(r => this.pk.font(this._use(r.font)).fontSize(sz).widthOfString(r.text));
            const total = widths.reduce((a, b) => a + b, 0);
            let px = x * this.k;
            if (align === 'center') px -= total / 2;
            else if (align === 'right') px -= total;
            const py = y * this.k;
            parts.forEach((r, i) => {
                this.pk.font(r.font).fontSize(sz).fillColor(this.textCol)
                    .text(r.text, px, py, { lineBreak: false, baseline: 'alphabetic' });
                px += widths[i];
            });
        }

        // Shapes ----------------------------------------------------------------
        _paint(style) {
            const s = String(style || 'S').toUpperCase();
            const fill = s.indexOf('F') >= 0, stroke = s.indexOf('D') >= 0 || s === 'S' || s === '';
            this.pk.lineWidth(this.lineW);
            if (this.dashArr) this.pk.dash(this.dashArr[0][0], { space: this.dashArr[0][1] !== undefined ? this.dashArr[0][1] : this.dashArr[0][0], phase: this.dashArr[1] }); else this.pk.undash();
            if (fill && stroke) this.pk.fillAndStroke(this.fillCol, this.drawCol);
            else if (fill) this.pk.fill(this.fillCol);
            else this.pk.stroke(this.drawCol);
        }
        rect(x, y, w, h, style) { this.pk.rect(x * this.k, y * this.k, w * this.k, h * this.k); this._paint(style); return this; }
        roundedRect(x, y, w, h, rx, ry, style) { this.pk.roundedRect(x * this.k, y * this.k, w * this.k, h * this.k, Math.min(rx, ry) * this.k); this._paint(style); return this; }
        line(x1, y1, x2, y2) { this.pk.moveTo(x1 * this.k, y1 * this.k).lineTo(x2 * this.k, y2 * this.k); this._paint('S'); return this; }
        circle(x, y, r, style) { this.pk.circle(x * this.k, y * this.k, r * this.k); this._paint(style); return this; }

        // Images ----------------------------------------------------------------
        /**
         * Places an image. jsPDF's form: (src, format, x, y, w, h, alias). The same image
         * (same data, or the same alias) is stored in the file ONCE however many times it
         * is placed - PDFKit keeps one copy per source string.
         */
        addImage(src, format, x, y, w, h, alias) {
            let data;
            if (alias && this.aliases.has(alias)) data = this.aliases.get(alias);
            else { data = imageData(src, format); if (alias) this.aliases.set(alias, data); }
            this.pk.image(data, x * this.k, y * this.k, { width: w * this.k, height: h * this.k });
            return this;
        }

        // Pages -----------------------------------------------------------------
        addPage(format, orientation) {
            const size = format ? pageSize(format, orientation, this.k) : this.size;
            this.size = size;
            this.pk.addPage({ size, margin: 0 });
            return this;
        }
        getNumberOfPages() { const r = this.pk.bufferedPageRange(); return r.count; }
        setPage(n) { this.pk.switchToPage(n - 1); return this; }

        // Tables ------------------------------------------------------------------
        /**
         * Draws a table - the subset of the jsPDF-AutoTable options FreeITSM's exports use:
         * startY, head, body, margin {left,right,top,bottom}, styles / headStyles /
         * alternateRowStyles / columnStyles {fontSize, cellPadding, fontStyle, textColor,
         * fillColor, halign, cellWidth}, and didParseCell(data). Columns share the width by
         * content; the head repeats on every page; doc.lastAutoTable.finalY is where it ended.
         */
        autoTable(o) {
            o = o || {};
            const k = this.k;
            const pw = this.internal.pageSize.getWidth(), ph = this.internal.pageSize.getHeight();
            const m = Object.assign({ left: 40 / k, right: 40 / k, top: 40 / k, bottom: 40 / k }, o.margin || {});
            const avail = pw - m.left - m.right;
            const base = Object.assign({ fontSize: 10, cellPadding: 5 / k, fontStyle: 'normal', textColor: [40, 40, 40], fillColor: null, halign: 'left' }, o.styles || {});
            const headBase = Object.assign({}, base, { fontStyle: 'bold', fillColor: [41, 128, 185], textColor: [255, 255, 255] }, o.headStyles || {});
            const altFill = o.alternateRowStyles && o.alternateRowStyles.fillColor;
            const colStyles = o.columnStyles || {};
            const head = (o.head || []).map(r => r.map(c => (c == null ? '' : String(c))));
            const body = (o.body || []).map(r => r.map(c => (c == null ? '' : String(c))));
            const nCols = Math.max(0, ...head.map(r => r.length), ...body.map(r => r.length));
            if (!nCols) { this.lastAutoTable = { finalY: o.startY || m.top }; return this; }

            // Every cell's style, with didParseCell's say.
            const cellOf = (raw, section, ri, ci) => {
                const st = Object.assign({}, section === 'head' ? headBase : base, colStyles[ci] || {});
                if (section === 'head') Object.assign(st, o.headStyles || {});
                if (section === 'body' && altFill && ri % 2 === 1 && !(colStyles[ci] && colStyles[ci].fillColor)) st.fillColor = altFill;
                const cell = { raw, text: [raw], styles: st };
                if (typeof o.didParseCell === 'function') o.didParseCell({ section, row: { index: ri }, column: { index: ci }, cell });
                return cell;
            };
            const H = head.map((r, ri) => Array.from({ length: nCols }, (_, ci) => cellOf(r[ci] || '', 'head', ri, ci)));
            const B = body.map((r, ri) => Array.from({ length: nCols }, (_, ci) => cellOf(r[ci] || '', 'body', ri, ci)));

            // Column widths: fixed where given, the rest shared by content.
            const pad = c => (typeof c.styles.cellPadding === 'number' ? c.styles.cellPadding : 5 / k);
            const width = (s, c) => measure(s, this.font.family, c.styles.fontStyle, c.styles.fontSize) / k;
            const natural = new Array(nCols).fill(0), minimum = new Array(nCols).fill(0), fixed = new Array(nCols).fill(null);
            for (let ci = 0; ci < nCols; ci++) {
                if (colStyles[ci] && typeof colStyles[ci].cellWidth === 'number') { fixed[ci] = colStyles[ci].cellWidth; continue; }
                [...H, ...B].forEach(row => {
                    const c = row[ci]; const p = pad(c) * 2;
                    natural[ci] = Math.max(natural[ci], width(c.raw, c) + p);
                    const longest = c.raw.split(/\s+/).reduce((a, w) => Math.max(a, width(w, c)), 0);
                    minimum[ci] = Math.max(minimum[ci], Math.min(longest, avail / nCols) + p);
                });
            }
            const fixedTotal = fixed.reduce((a, f) => a + (f || 0), 0);
            const flex = avail - fixedTotal;
            const natTotal = natural.reduce((a, n, i) => a + (fixed[i] === null ? n : 0), 0) || 1;
            const widths = natural.map((n, i) => {
                if (fixed[i] !== null) return fixed[i];
                return Math.max(minimum[i], n * flex / natTotal);
            });
            // If the minimums pushed it over, shrink the flexible columns to fit exactly.
            const over = widths.reduce((a, b) => a + b, 0) - avail;
            if (over > 0.01) {
                const flexW = widths.reduce((a, w, i) => a + (fixed[i] === null ? w : 0), 0) || 1;
                for (let i = 0; i < nCols; i++) if (fixed[i] === null) widths[i] -= over * widths[i] / flexW;
            }

            // Row heights.
            const lineH = c => c.styles.fontSize * LINE_FACTOR / k;
            const layout = row => {
                let h = 0;
                row.forEach((c, ci) => {
                    this.setFont(this.font.family, c.styles.fontStyle).setFontSize(c.styles.fontSize);
                    c.lines = this.splitTextToSize(c.raw, Math.max(1, widths[ci] - pad(c) * 2));
                    h = Math.max(h, c.lines.length * lineH(c) + pad(c) * 2);
                });
                return h;
            };
            const keepFont = Object.assign({}, this.font), keepText = this.textCol, keepFill = this.fillCol;

            const drawRow = (row, y, h) => {
                let x = m.left;
                row.forEach((c, ci) => {
                    const w = widths[ci];
                    if (c.styles.fillColor) { this.setFillColor(c.styles.fillColor); this.rect(x, y, w, h, 'F'); }
                    this.setFont(this.font.family, c.styles.fontStyle).setFontSize(c.styles.fontSize).setTextColor(c.styles.textColor);
                    const p = pad(c), asc = c.styles.fontSize * 0.8 / k;
                    c.lines.forEach((ln, li) => {
                        const by = y + p + asc + li * lineH(c);
                        const al = c.styles.halign;
                        const tx = al === 'center' ? x + w / 2 : al === 'right' ? x + w - p : x + p;
                        this.text(ln, tx, by, { align: al === 'center' || al === 'right' ? al : 'left' });
                    });
                    x += w;
                });
            };

            const headH = H.map(layout), bodyH = B.map(layout);
            let y = typeof o.startY === 'number' ? o.startY : m.top;
            const drawHead = () => { H.forEach((r, i) => { drawRow(r, y, headH[i]); y += headH[i]; }); };
            drawHead();
            B.forEach((r, i) => {
                if (y + bodyH[i] > ph - m.bottom && y > m.top + 0.01) {
                    this.addPage();
                    y = m.top;
                    drawHead();
                }
                drawRow(r, y, bodyH[i]);
                y += bodyH[i];
            });
            this.font = keepFont; this.textCol = keepText; this.fillCol = keepFill;
            this.lastAutoTable = { finalY: y };
            return this;
        }

        // Output ------------------------------------------------------------------
        /** Finishes the document once and resolves to it as a Blob. */
        _finish() {
            if (!this._done) {
                this._done = this.ended.then(() => new Blob(this.chunks, { type: 'application/pdf' }));
                this.pk.end();
            }
            return this._done;
        }

        /**
         * The finished PDF. Unlike jsPDF this is ASYNC - await it:
         * 'blob' (default), 'arraybuffer', 'datauristring'.
         */
        async output(type) {
            const blob = await this._finish();
            if (type === 'arraybuffer') return blob.arrayBuffer();
            if (type === 'datauristring' || type === 'datauri') {
                return new Promise(res => { const r = new FileReader(); r.onload = () => res(r.result); r.readAsDataURL(blob); });
            }
            return blob;
        }

        /** Downloads the PDF. Resolves when the download has been handed to the browser. */
        async save(filename) {
            const blob = await this._finish();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = filename || 'document.pdf';
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 30000);
            return this;
        }
    }

    /**
     * Makes a new document - jsPDF's options: unit ('mm' default, 'pt', 'in'...),
     * format ('a4', 'letter', [w, h] in the unit...), orientation ('p' / 'l').
     *
     * @param {object} opts
     * @param {string} [text]  everything the document will contain, so the right fonts are fetched first
     * @return {Promise<PdfDoc>}
     */
    async function create(opts, text) {
        await ready(text);
        return new PdfDoc(opts);
    }

    window.FitsmPdf = { ready, isReady, create, measure, supported, scriptsIn, runs };
})();
