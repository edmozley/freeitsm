/**
 * Report Packs - draw the engine's pages into a PDF with the shared PDF writer
 * (assets/js/pdf-writer.js - PDFKit and the bundled Noto fonts, GH #254).
 *
 * The twin of render-svg.js: same primitives, same coordinates, same order.
 * Text is real PDF text in every language FreeITSM ships (searchable, sharp),
 * charts are images at ~300 dpi, and the logo is embedded ONCE under an alias
 * however many pages carry it (see the note in assets/js/form-pdf.js on what an
 * un-aliased logo does to file size).
 *
 * @wiki Report-Packs-Internals-2-The-Layout-Engine
 */
(function () {
    'use strict';

    const PT = 25.4 / 72;

    /** Converts a #rrggbb colour to an [r, g, b] array, falling back to dark grey. */
    function rgb(hex) {
        const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
        const n = m ? parseInt(m[1], 16) : 0x222222;
        return [n >> 16, (n >> 8) & 255, n & 255];
    }

    /**
     * Kept for callers: text goes into the PDF as it is now. Until GH #254 this replaced
     * everything outside Western European (WinAnsi) with "?" - the writer's fonts print it.
     */
    function safe(text) { return String(text); }

    /** Sets the writer's font, style, size and text colour from a run style. */
    function setStyle(doc, st) {
        doc.setFont(st.font || 'helvetica', window.RPEngine.styleKey(st.b, st.i));
        doc.setFontSize(st.size);
        doc.setTextColor(...rgb(st.color || '#222222'));
    }

    /**
     * Draws a run of text at its baseline, with its highlight behind it and underline or
     * strike-through lines.
     */
    function drawText(doc, x, y, text, st, w) {
        if (!text) return;
        if (st.hl) {
            doc.setFillColor(...rgb(st.hl));
            doc.rect(x, y - st.size * PT * 0.82, w, st.size * PT * 1.08, 'F');
        }
        setStyle(doc, st);
        doc.text(String(text), x, y);   // y is the baseline, as the engine measured it
        if (st.u || st.s) {
            doc.setDrawColor(...rgb(st.color || '#222222'));
            doc.setLineWidth(Math.max(0.15, st.size * PT * 0.07));
            if (st.u) doc.line(x, y + st.size * PT * 0.12, x + w, y + st.size * PT * 0.12);
            if (st.s) doc.line(x, y - st.size * PT * 0.28, x + w, y - st.size * PT * 0.28);
        }
    }

    /** Draws one engine primitive (text, rect, line or dot leader) into the PDF. */
    function drawPrim(doc, d) {
        switch (d.k) {
            case 'text': drawText(doc, d.x, d.y, d.text, d.st, d.w); break;
            case 'rect': {
                const fill = d.fill && d.fill !== 'none';
                if (fill) doc.setFillColor(...rgb(d.fill));
                if (d.stroke) { doc.setDrawColor(...rgb(d.stroke)); doc.setLineWidth(0.25); }
                if (d.dash && d.stroke) doc.setLineDashPattern([1.2, 1], 0);
                const mode = fill && d.stroke ? 'FD' : fill ? 'F' : 'S';
                if (!fill && !d.stroke) break;
                if (d.r) doc.roundedRect(d.x, d.y, Math.max(0, d.w), Math.max(0, d.h), d.r, d.r, mode);
                else doc.rect(d.x, d.y, Math.max(0, d.w), Math.max(0, d.h), mode);
                if (d.dash) doc.setLineDashPattern([], 0);
                break;
            }
            case 'line':
                doc.setDrawColor(...rgb(d.color)); doc.setLineWidth(d.width);
                doc.line(d.x1, d.y1, d.x2, d.y2);
                break;
            case 'dots':
                if (d.x2 <= d.x1) break;
                doc.setFillColor(...rgb(d.color));
                for (let x = d.x1; x <= d.x2; x += 1.4) doc.circle(x, d.y, 0.15, 'F');
                break;
        }
    }

    /**
     * Draws laid-out rich text - the logo images (embedded once under the rp-logo alias), list markers
     * and text runs - at its position.
     */
    function drawRich(doc, rich, logo) {
        if (!rich || !rich.L) return;
        const { L, x, y } = rich;
        L.images.forEach(im => {
            if (logo) doc.addImage(logo.dataUrl, 'PNG', x + im.x, y + im.y, im.w, im.h, 'rp-logo', 'FAST');
        });
        L.lines.forEach(line => {
            if (line.marker) drawText(doc, x + line.marker.x, y + line.baseline, line.marker.text, line.marker.st, 0);
            line.items.forEach(it => drawText(doc, x + it.x, y + line.baseline, it.text, it.st, it.w));
        });
    }

    /** Every piece of text on the laid-out pages, so the writer fetches the fonts it needs. */
    function textOf(layout) {
        const out = [];
        const rich = r => { if (r && r.L) r.L.lines.forEach(l => { if (l.marker) out.push(l.marker.text); l.items.forEach(it => out.push(it.text)); }); };
        layout.pages.forEach(pg => {
            rich(pg.headerRich); rich(pg.footerRich); rich(pg.coverRich);
            (pg.toc || []).forEach(d => d.text && out.push(d.text));
            pg.items.forEach(it => { it.draw.forEach(d => d.text && out.push(d.text)); rich(it.rich); });
        });
        return out.join(' ');
    }

    /**
     * Build the PDF. layout = RPEngine.paginate(...) result.
     * opts: {title, logo: {dataUrl}|null, chartImage(item) -> PNG dataURL}
     * Async: resolves to the document (the writer loads PDFKit and fonts first).
     */
    async function build(layout, opts) {
        const G = layout.geometry;
        const doc = await window.FitsmPdf.create({
            unit: 'mm', format: [G.pw, G.ph], orientation: G.pw > G.ph ? 'landscape' : 'portrait',
        }, textOf(layout));
        doc.setProperties({ title: opts.title || 'Report', creator: 'FreeITSM' });

        layout.pages.forEach((pg, i) => {
            if (i > 0) doc.addPage([G.pw, G.ph], G.pw > G.ph ? 'landscape' : 'portrait');
            if (pg.headerRich) {
                drawRich(doc, pg.headerRich, opts.logo);
                if (pg.headerRule) { doc.setDrawColor(51, 51, 51); doc.setLineWidth(0.3); doc.line(G.m.l, pg.headerRule.y, G.pw - G.m.r, pg.headerRule.y); }
            }
            if (pg.footerRich) {
                drawRich(doc, pg.footerRich, opts.logo);
                if (pg.footerRule) { doc.setDrawColor(187, 187, 187); doc.setLineWidth(0.25); doc.line(G.m.l, pg.footerRule.y, G.pw - G.m.r, pg.footerRule.y); }
            }
            if (pg.coverRich) drawRich(doc, pg.coverRich, opts.logo);
            if (pg.toc) pg.toc.forEach(d => drawPrim(doc, d));
            pg.items.forEach(it => {
                it.draw.forEach(d => drawPrim(doc, d));
                if (it.rich) drawRich(doc, it.rich, opts.logo);
                if (it.chart) {
                    const url = opts.chartImage(it);
                    if (url) doc.addImage(url, 'PNG', it.chart.x, it.chart.y, it.chart.w, it.chart.h, undefined, 'FAST');
                }
            });
        });
        return doc;
    }

    window.RPRenderPdf = { build, safe };
})();
