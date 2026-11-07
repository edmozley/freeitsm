/**
 * FormPdf — turning a form submission into a PDF, and the formatting decisions
 * that go with it.
 *
 * 🔑 WHY THIS FILE EXISTS. All of this began life inside forms/submissions.php,
 * which shows the submissions of ONE form. A collection shows the submissions of
 * SEVERAL, and needs identical documents. Two copies would have drifted the
 * first time a field type changed — the same reasoning that made the row export
 * icon reuse the panel's export rather than grow a twin.
 *
 * ⚠️ THE ONE SIGNATURE CHANGE. Everything here takes an explicit `form`
 * ({ title, fields }) instead of closing over a page-global `formData`. On a
 * collection page every row may belong to a different form, so "the form" can
 * no longer be a property of the page.
 *
 * Depends on: window.FitsmPdf (assets/js/pdf-writer.js), window.t (i18n), window.fmtDate (timezones),
 * FormLogic (lookup labels + naive date values).
 *
 * @wiki Submission-PDF-Export-Developer-Guide
 */
(function () {
    'use strict';

    /* Multi-value answers are stored as a JSON array, but older rows are a
       plain comma-joined string. Both have to read the same on a record. */
    function decodeMultiValue(raw) {
        if (raw === null || raw === undefined || raw === '') return [];
        try {
            var parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) return parsed.filter(function (v) { return v !== '' && v !== null; });
        } catch (e) { /* not JSON — fall through to the legacy shape */ }
        return String(raw).split(',').map(function (v) { return v.trim(); }).filter(Boolean);
    }

    /**
     * One answer, reduced to what both the screen and the PDF need to know:
     * what KIND of thing it is, its text, and whether it is actually empty.
     * `empty` is not the same as falsy — an unticked checkbox has a real answer.
     */
    /**
     * A table's answer in one line, for a list cell or anywhere a whole table
     * will not fit: "3 rows".
     *
     * ⚠️ Deliberately not the contents. A submissions list is one row per
     * submission and every other column is a single value; flattening thirty
     * cells into one of them makes the whole row unreadable and still does not
     * show the table. The count says there is something to open.
     */
    function gridSummary(f, rows) {
        var n = rows.length;
        if (!n) return '';
        return window.t(n === 1 ? 'forms.grid.one_row' : 'forms.grid.n_rows', { n: n });
    }

    /** One cell's stored value as text, by the column's own type. */
    function gridCellText(col, value) {
        var v = (value === null || value === undefined) ? '' : String(value);
        if (col.type === 'checkbox') {
            return v === '1' ? window.t('forms.subs.yes') : window.t('forms.subs.no');
        }
        if (col.type === 'datetime') {
            /* ⚠️ The naive helper, like a datetime ANSWER above: a date somebody
               typed into a cell is a calendar value, not an instant, and must
               not be shifted into the reader's timezone. */
            return (window.FormLogic ? FormLogic.formatDateValue(v) : v) || '';
        }
        return v;
    }

    /**
     * Turns one stored answer into the pieces the PDF draws - a yes/no, a list, a table or plain text - with an empty flag.
     *
     * @param f    the field definition
     * @param raw  the stored value for that field
     */
    function fieldValueParts(f, raw) {
        var val = (raw === null || raw === undefined) ? '' : raw;

        if (f.field_type === 'checkbox') {
            var checked = val === '1';
            return {
                kind: 'bool',
                checked: checked,
                text: checked ? window.t('forms.subs.yes') : window.t('forms.subs.no'),
                empty: false
            };
        }
        if (f.field_type === 'checkboxes') {
            var list = decodeMultiValue(val);
            return { kind: 'list', list: list, text: list.join(', '), empty: list.length === 0 };
        }
        if (f.field_type === 'grid') {
            /* A table's answer: rows of cells keyed by column id.
               🔴 gridColumns(), NOT gridLiveColumns(). Reading a record back
               needs the RETIRED columns too — a value stored against a column
               that has since been withdrawn must still say what it was
               answering, or last year's submission silently loses a field and
               nobody can tell that it ever had one. The filler uses the live
               list; a reader must not. */
            var gcols = window.FormLogic ? FormLogic.gridColumns(f) : [];
            var grows = window.FormLogic ? FormLogic.gridRows(val) : [];
            /* Only the columns this answer actually has something in, plus every
               live one — so a table that gained a column last week does not show
               an empty stripe down every older record, and one that lost a
               column still shows what was answered. */
            var used = {};
            grows.forEach(function (r) {
                Object.keys(r).forEach(function (cid) {
                    if (String(r[cid]) !== '' && r[cid] !== null && r[cid] !== undefined) used[cid] = true;
                });
            });
            var shown = gcols.filter(function (c) { return !c.deleted || used[String(c.id)]; });
            return {
                kind: 'grid',
                columns: shown,
                rows: grows,
                // A one-line summary for anywhere a whole table will not fit.
                text: gridSummary(f, grows),
                empty: grows.length === 0
            };
        }
        if (f.field_type === 'lookup') {
            /* The label the person actually chose. The id stays in the stored
               JSON for anything that wants the record. */
            var lbl = (window.FormLogic ? FormLogic.lookupLabel(val) : '') || '';
            return { kind: 'text', text: lbl, empty: !lbl };
        }
        if (f.field_type === 'datetime') {
            /* ⚠️ FormLogic.formatDateValue, NOT fmtDate: a date ANSWER is a
               naive calendar value somebody typed, so it must not be shifted
               into the reader's timezone. The submission's own date, below, is
               the opposite kind. */
            var shown = (window.FormLogic ? FormLogic.formatDateValue(val) : '') || '';
            return { kind: 'text', text: shown, empty: !shown };
        }
        return { kind: 'text', text: String(val), empty: !val };
    }

    /* The approval outcome, as words and a class. `not_required` is not a
       non-answer — it means nobody had to approve this, which is worth saying
       on a record rather than leaving blank. */
    function approvalParts(sub) {
        var st = sub.approval_status || 'not_required';
        if (st === 'approved') return { st: st, cls: 'da-approved', label: window.t('forms.approval.status_approved') };
        if (st === 'rejected') return { st: st, cls: 'da-rejected', label: window.t('forms.approval.status_rejected') };
        if (st === 'pending')  return { st: st, cls: 'da-pending',  label: window.t('forms.subs.approval_awaiting') };
        return { st: st, cls: '', label: window.t('forms.subs.approval_none') };
    }

    /* A date template is not a filename. DD/MM/YYYY is a perfectly good
       preference and a slash is illegal in a filename everywhere, so separators
       become dots instead of vanishing. Windows also refuses a trailing dot or
       space. */
    function cleanForFileName(v) {
        return String(v || '')
            .replace(/[\/\\]/g, '.')
            .replace(/[<>:"|?*\x00-\x1f]/g, '')
            .replace(/\s+/g, ' ')
            .trim()
            .replace(/[. ]+$/, '');
    }

    /**
     * "Software Request - Ed Mozley - 19.09.2026.pdf"
     *
     * The date is the reader's OWN preference, so a US install files it as
     * 09.19.2026 without this knowing anything about locales.
     *
     * 🔑 fmtDate, not fmtNaiveDate: `submitted_date` is a real instant stamped
     * by the server and converts into the viewer's zone. A rota day is the
     * other kind. Both helpers exist and choosing wrongly is silently wrong for
     * everyone outside UTC.
     */
    function submissionFileName(form, sub) {
        var when = (typeof window.fmtDate === 'function') ? window.fmtDate(sub.submitted_date) : '';
        var parts = [
            cleanForFileName((form && form.title) || 'Submission'),
            cleanForFileName(sub.submitted_by || window.t('forms.subs.unknown_user')),
            cleanForFileName(when)
        ].filter(Boolean);

        /* Long titles are common and 255 is the practical filename limit, so
           leave room for the extension and any "(1)" a browser adds when two
           files collide. */
        var name = parts.join(' - ');
        if (name.length > 180) name = name.slice(0, 180).replace(/[. ]+$/, '');
        return name + '.pdf';
    }

    /** "Software Request - 6 submissions - 19.09.2026.pdf" */
    function bundleFileName(title, n) {
        var when = (typeof window.fmtDate === 'function') ? window.fmtDate(new Date()) : '';
        return cleanForFileName(window.t('forms.subs.bundle_name', {
            title: title || 'Submissions', n: n, date: when
        })) + '.pdf';
    }

    /* The branding image, fetched once per page load however many documents are
       made. Resolves to null if it cannot be loaded — a missing logo must never
       cost somebody their record. */
    var logoPromise = null;
    /**
     * Loads the branding image once per page and caches the promise; resolves to null when there is no URL or it fails to load.
     */
    function loadBrandLogo(url) {
        if (logoPromise) return logoPromise;
        logoPromise = new Promise(function (resolve) {
            if (!url) { resolve(null); return; }
            var img = new Image();
            img.crossOrigin = 'anonymous';
            /** The image loaded - hand it back. */
            img.onload = function () { resolve(img); };
            /** The image failed - carry on without a logo. */
            img.onerror = function () { resolve(null); };
            img.src = url;
        });
        return logoPromise;
    }

    /**
     * A new blank A4 portrait document in millimetres, from the shared PDF writer
     * (assets/js/pdf-writer.js - PDFKit, every language, GH #254). It is handed the
     * submissions' text so it fetches the fonts that text needs (Hindi, Tamil...).
     */
    function newDoc(text) {
        return window.FitsmPdf.create({ unit: 'mm', format: 'a4', orientation: 'portrait' }, text);
    }

    /** Everything a set of submissions will print, for newDoc() - titles, labels, answers, the approval trail. */
    function textOf(items) {
        return JSON.stringify(items.map(function (it) { return [it.form && it.form.title, it.form && it.form.fields, it.sub]; }))
            + ' ' + window.t('forms.subs.pdf_footer', { n: 1, total: 1 });
    }

    /**
     * Draw ONE submission into an existing document, starting at the top of the
     * current page. Everything that decides what a record LOOKS like lives here
     * and nowhere else, so one form's export and a collection bundle can never
     * disagree about what a record contains.
     *
     * @param form  { title, fields } — the form THIS submission belongs to
     * @param num   the number shown on the document ("#3")
     */
    function drawSubmission(doc, form, sub, num, logo) {
        var pageW = doc.internal.pageSize.getWidth();
        var pageH = doc.internal.pageSize.getHeight();
        var margin = 15;
        var contentW = pageW - margin * 2;
        var y = margin;

        /* Start a new page when the next block would run off the foot. Checked
           BEFORE writing each block, or the last line of a long answer lands in
           the margin. */
        function room(needed) {
            if (y + needed > pageH - margin) { doc.addPage(); y = margin; }
        }

        // --- Logo: the operator's own, not a hard-coded file -----------------
        if (logo) {
            var maxH = 12;
            /* alias + compression, both deliberate: the alias means a bundle of
               many submissions embeds the logo ONCE rather than per page, and
               FAST deflates it — an uncompressed branding PNG took a one-page
               document to 1.45 MB, which is a lot for a record you are storing
               by the hundred. */
            doc.addImage(logo, 'PNG', margin, y, maxH * (logo.width / logo.height), maxH, 'brandlogo', 'FAST');
            y += maxH + 6;
        }

        // --- Title -----------------------------------------------------------
        doc.setFontSize(18);
        doc.setFont('helvetica', 'bold');
        doc.setTextColor(30, 30, 30);
        var titleLines = doc.splitTextToSize((form && form.title) || '', contentW);
        doc.text(titleLines, margin, y);
        y += titleLines.length * 7 + 2;

        // --- Meta -------------------------------------------------------------
        doc.setFontSize(9);
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(120, 120, 120);
        var meta = '#' + num + '  |  ' +
            window.t('forms.subs.detail_submitted_by') + ' ' +
            (sub.submitted_by || window.t('forms.subs.unknown_user')) + '  |  ' +
            window.t('forms.subs.detail_date') + ' ' +
            ((typeof window.fmtDate === 'function') ? window.fmtDate(sub.submitted_date) : '');
        doc.text(doc.splitTextToSize(meta, contentW), margin, y);
        y += 8;

        // --- Approval trail ---------------------------------------------------
        // The reason this document exists. A list of answers with no decision on
        // it is a form, not a record.
        var ap = approvalParts(sub);
        room(18);
        doc.setDrawColor(210, 214, 220);
        doc.setFillColor(248, 250, 252);
        var apLines = [window.t('forms.subs.detail_approval') + ': ' + ap.label];
        if (sub.approval_decided_by) {
            apLines.push(window.t('forms.approval.approver') + ': ' + sub.approval_decided_by +
                (sub.approval_decided_datetime
                    ? '  (' + ((typeof window.fmtDate === 'function') ? window.fmtDate(sub.approval_decided_datetime) : '') + ')'
                    : ''));
        }
        if (sub.approval_comment) {
            apLines.push(window.t('forms.subs.approval_comment') + ': ' + sub.approval_comment);
        }
        var apWrapped = [];
        apLines.forEach(function (l) {
            doc.splitTextToSize(l, contentW - 8).forEach(function (wl) { apWrapped.push(wl); });
        });
        var apH = apWrapped.length * 5 + 6;
        doc.roundedRect(margin, y, contentW, apH, 1.5, 1.5, 'FD');
        doc.setFontSize(10);
        doc.setTextColor(60, 60, 60);
        doc.text(apWrapped, margin + 4, y + 6);
        y += apH + 8;

        // --- Fields ------------------------------------------------------------
        ((form && form.fields) || []).forEach(function (f) {
            var p = fieldValueParts(f, sub.data ? sub.data[f.id] : '');
            var valueLines;
            if (p.kind === 'bool') {
                valueLines = [(p.checked ? '[x] ' : '[ ] ') + p.text];
            } else if (p.kind === 'list') {
                valueLines = p.empty
                    ? [window.t('forms.subs.no_response')]
                    : p.list.map(function (v) { return '• ' + v; });
            } else {
                valueLines = doc.splitTextToSize(
                    p.empty ? window.t('forms.subs.no_response') : p.text, contentW);
            }

            var label = (f.label || '') +
                (f.is_deleted == 1 ? ' (' + window.t('forms.subs.retired') + ')' : '');
            var labelLines = doc.splitTextToSize(label, contentW);

            /* A table's answer is drawn AS A TABLE. Flattening thirty cells into
               a paragraph is technically a record and practically unreadable —
               and a purchase requisition's whole point is the table. */
            if (p.kind === 'grid' && !p.empty) {
                room(labelLines.length * 5 + 20);
                doc.setFontSize(9);
                doc.setFont('helvetica', 'bold');
                doc.setTextColor(100, 116, 139);
                doc.text(labelLines, margin, y);
                y += labelLines.length * 5 + 1;

                doc.autoTable({
                    startY: y,
                    /* A retired column is MARKED rather than dropped: the answers
                       under it are real and the reader needs to know the question
                       is no longer asked. */
                    head: [p.columns.map(function (c) {
                        return (c.label || '') + (c.deleted ? ' (' + window.t('forms.subs.retired') + ')' : '');
                    })],
                    body: p.rows.map(function (r) {
                        return p.columns.map(function (c) { return gridCellText(c, r[c.id]); });
                    }),
                    styles: { fontSize: 8, cellPadding: 2, overflow: 'linebreak' },
                    headStyles: { fillColor: [100, 116, 139], textColor: [255, 255, 255], fontStyle: 'bold' },
                    alternateRowStyles: { fillColor: [248, 250, 252] },
                    margin: { left: margin, right: margin }
                });
                // autoTable paginates itself, so take the cursor it ends on.
                y = (doc.lastAutoTable ? doc.lastAutoTable.finalY : y) + 6;
                return;
            }

            room(labelLines.length * 5 + valueLines.length * 5 + 6);

            doc.setFontSize(9);
            doc.setFont('helvetica', 'bold');
            doc.setTextColor(100, 116, 139);
            doc.text(labelLines, margin, y);
            y += labelLines.length * 5;

            doc.setFontSize(11);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(p.empty ? 150 : 30, p.empty ? 150 : 30, p.empty ? 150 : 30);
            // A long answer can outrun a page on its own, so wrap line by line
            // rather than trusting the block to fit.
            valueLines.forEach(function (line) { room(5); doc.text(line, margin, y); y += 5; });
            y += 4;
        });

        return y;
    }

    /* Page numbers, stamped once the document is complete — they cannot be
       written as you go, because you do not know the total until the end. */
    function stampFooters(doc) {
        var pageW = doc.internal.pageSize.getWidth();
        var pageH = doc.internal.pageSize.getHeight();
        var pages = doc.internal.getNumberOfPages();
        for (var i = 1; i <= pages; i++) {
            doc.setPage(i);
            doc.setFontSize(8);
            doc.setTextColor(150, 150, 150);
            doc.text(window.t('forms.subs.pdf_footer', { n: i, total: pages }),
                pageW - 15, pageH - 8, { align: 'right' });
        }
    }

    /** One submission, one file. */
    async function exportOne(form, sub, num, logoUrl) {
        var logo = await loadBrandLogo(logoUrl);
        var doc = await newDoc(textOf([{ form: form, sub: sub }]));
        drawSubmission(doc, form, sub, num, logo);
        stampFooters(doc);
        await doc.save(submissionFileName(form, sub));
    }

    /**
     * Many submissions, one file, a page break between each.
     * @param items [{ form, sub, num }] in the order they should read
     */
    async function exportBundle(items, bundleTitle, logoUrl) {
        var logo = await loadBrandLogo(logoUrl);
        var doc = await newDoc(textOf(items));
        items.forEach(function (it, i) {
            // Each record starts its own page; the first is the page the
            // document already has.
            if (i > 0) doc.addPage();
            drawSubmission(doc, it.form, it.sub, it.num, logo);
        });
        stampFooters(doc);
        await doc.save(bundleFileName(bundleTitle, items.length));
    }

    /**
     * Many submissions, one file each.
     * ⚠️ Fired back to back, browsers drop all but the first few — the downloads
     * are queued by the page, not by the click — so leave a beat between them.
     */
    async function exportSeparate(items, logoUrl) {
        var logo = await loadBrandLogo(logoUrl);
        for (var i = 0; i < items.length; i++) {
            var doc = await newDoc(textOf([items[i]]));
            drawSubmission(doc, items[i].form, items[i].sub, items[i].num, logo);
            stampFooters(doc);
            await doc.save(submissionFileName(items[i].form, items[i].sub));
            await new Promise(function (r) { setTimeout(r, 150); });
        }
    }

    window.FormPdf = {
        decodeMultiValue: decodeMultiValue,
        fieldValueParts: fieldValueParts,
        /* Shared so the submissions table, the detail panel and the CSV all render
           a cell the same way — a date in a cell must not be shifted into the
           reader's timezone in one place and not another. */
        gridCellText: gridCellText,
        gridSummary: gridSummary,
        approvalParts: approvalParts,
        cleanForFileName: cleanForFileName,
        submissionFileName: submissionFileName,
        bundleFileName: bundleFileName,
        loadBrandLogo: loadBrandLogo,
        newDoc: newDoc,
        drawSubmission: drawSubmission,
        stampFooters: stampFooters,
        exportOne: exportOne,
        exportBundle: exportBundle,
        exportSeparate: exportSeparate
    };
})();
