<?php
/**
 * The asset handover document's stylesheets, kept as PHP strings.
 *
 * handoverDocumentCss() styles the document itself and is shared by the printable page, the
 * designer's live preview and the emailed copy; handoverPrintCss() adds the print-only
 * rules.
 *
 * @wiki Asset-Handover-Developer-Guide
 */

/**
 * The handover document's stylesheet, in one place (discussion #56).
 *
 * Shared by the printable page, the designer's live preview and the emailed
 * copy. Kept as a PHP function rather than a .css file for one reason: the
 * emailed version has to carry its styles inline in the message, so the same
 * text has to be available as a string, not only as a URL a mail client would
 * refuse to fetch.
 *
 * ⚠️ Deliberately NOT themed. A handover is printed and filed, so it is always
 * dark ink on white paper — a dark-mode print wastes toner and reads badly, and
 * the analyst's theme is not the document's business.
 */
function handoverDocumentCss(): string
{
    return <<<CSS
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    .hb-doc {
        color: #1a1a1a;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        font-size: var(--font-size-lg, 14px);
        line-height: 1.5;
    }
    .hb-doc .hb-logo { text-align: right; margin-bottom: 8px; }
    .hb-doc .hb-logo img { max-height: 56px; max-width: 200px; }
    .hb-doc .hb-title-wrap { padding-bottom: 18px; border-bottom: 2px solid #1a1a1a; }
    .hb-doc .doc-title { font-size: var(--font-size-4xl, 24px); font-weight: 700; margin: 0 0 4px; }
    .hb-doc .doc-sub { color: #555; font-size: var(--font-size-md, 13px); }
    .hb-doc .hb-intro { margin: 18px 0 0; color: #333; }
    .hb-doc .section-title {
        font-size: var(--font-size-xs, 11px); text-transform: uppercase; letter-spacing: 0.6px;
        color: #666; font-weight: 700; margin: 26px 0 8px;
    }
    .hb-doc .who { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 28px; }
    .hb-doc .field-label { font-size: var(--font-size-xs, 11px); color: #666; text-transform: uppercase; letter-spacing: 0.4px; }
    .hb-doc .field-value { font-size: var(--font-size-xl, 16px); font-weight: 600; }
    .hb-doc table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    .hb-doc th {
        text-align: left; font-size: var(--font-size-xs, 11px); text-transform: uppercase; letter-spacing: 0.4px;
        color: #555; padding: 8px 6px; border-bottom: 2px solid #1a1a1a; white-space: nowrap;
    }
    .hb-doc td { padding: 9px 6px; border-bottom: 1px solid #e2e2e2; vertical-align: top; }
    .hb-doc td.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: var(--font-size-sm, 12px); }
    .hb-doc .none-row { text-align: center; padding: 26px; color: #666; }
    .hb-doc .declaration {
        margin-top: 26px; padding: 14px 16px;
        background: #f7f8fa; border-left: 3px solid #1a1a1a;
        font-size: var(--font-size-md, 13px); color: #333;
    }
    .hb-doc .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 34px; }
    .hb-doc .sig-line { border-bottom: 1px solid #1a1a1a; height: 46px; }
    .hb-doc .sig-label { font-size: var(--font-size-xs, 11px); color: #555; margin-top: 6px; }
    .hb-doc .sig-name { font-size: var(--font-size-md, 13px); font-weight: 600; margin-bottom: 2px; }
    .hb-doc .doc-foot {
        margin-top: 34px; padding-top: 12px; border-top: 1px solid #ddd;
        font-size: var(--font-size-xs, 11px); color: #777; display: flex; justify-content: space-between; gap: 12px;
    }
CSS;
}

/** Print rules — only the printable page needs these. */
function handoverPrintCss(): string
{
    return <<<CSS
    @media print {
        body { background: #fff; padding: 0; font-size: var(--font-size-sm, 12px); }
        .toolbar { display: none !important; }
        .sheet { box-shadow: none; border-radius: 0; max-width: none; padding: 0; }
        /* Never split a signature block or a table row across two pages — a
           signature on its own on page 2 is not a signed document. */
        .hb-doc .signatures, .hb-doc .declaration, .hb-doc tr { break-inside: avoid; page-break-inside: avoid; }
        .hb-doc thead { display: table-header-group; }
    }
CSS;
}
