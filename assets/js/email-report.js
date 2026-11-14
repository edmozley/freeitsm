/**
 * Email a PDF the page just built to the analyst who is signed in (5.3.0).
 *
 * For installs that are not reachable from the internet: a "download" link in
 * an email cannot be opened there, so the file travels IN the email. It only
 * ever goes to the signed-in analyst's own address - there is no recipient.
 *
 *   const r = await emailReportToMe({
 *       subject:  'What if: delete directory source "Contoso AD"',
 *       html:     reportHtml,   // the HTML version of the report, for the body (optional)
 *       filename: 'what-if-contoso-ad.pdf',
 *       pdf:      doc,          // a pdf-writer.js document, a Blob, an ArrayBuffer or a Uint8Array
 *   });
 *   if (r.ok) showToast(r.message); else showToast(r.error, 'error');
 *
 * WHAT the email carries is not the caller's choice: System -> Emailed reports
 * ticks any of body (the html), attachment (the PDF) and download link, and the
 * server applies it - so always pass both the PDF and, when there is one, the HTML.
 *
 * Resolves to { ok: true, sentTo, delivered: {body, attachment, link}, message }
 * or { ok: false, code, error }. It
 * never rejects, so a dialog can await it without a try. `error` and `message`
 * are already translated (common.email_report.*; tf() carries the English for
 * pages that do not load the common namespace). Codes: not_signed_in,
 * bad_request, not_pdf, too_large, no_email, no_mailbox, send_failed, network.
 *
 * Posts to api/system/email_report.php, found next to this file's own URL so it
 * works from any page depth. csrf.js adds the token; it is also set here.
 *
 * @wiki Outbound-Email-Attachments-Developer-Guide
 */
(function () {
    'use strict';
    if (window.emailReportToMe) return;

    // TRAP: document.currentScript is only set while this file first runs, so
    // the endpoint URL is worked out now, not inside the function.
    var src = (document.currentScript && document.currentScript.src) || '';
    var cut = src.indexOf('assets/js/email-report.js');
    var ENDPOINT = (cut >= 0 ? src.slice(0, cut) : '../') + 'api/system/email_report.php';

    // The server's limit (SYSTEM_EMAIL_ATTACH_MAX_BYTES), checked here too so a
    // large report is refused before it is uploaded.
    var MAX_BYTES = 3 * 1024 * 1024;

    var ENGLISH = {
        sent:          'Emailed to {email}.',
        not_signed_in: 'You are no longer signed in. Sign in again and retry.',
        bad_request:   'The report could not be sent.',
        not_pdf:       'Only a PDF can be emailed this way.',
        too_large:     'The report is too large to email (the limit is 3 MB). Download it instead.',
        no_email:      'Your account has no valid email address. Add one in My Account, or download the report instead.',
        no_mailbox:    'Email is not set up on this system. Download the report instead.',
        send_failed:   'The email could not be sent. The reason is in the email send log.',
        network:       'The report could not be sent - the server did not answer.'
    };
    function tr(key, params) {
        var english = ENGLISH[key] || ENGLISH.send_failed;
        return window.tf ? window.tf('common.email_report.' + key, english, params)
            : english.replace(/\{(\w+)\}/g, function (m, k) { return params && k in params ? String(params[k]) : m; });
    }
    function fail(code) { return { ok: false, code: code, error: tr(code) }; }

    /** Any of the accepted PDF shapes, as a Blob. */
    function toBlob(pdf) {
        if (pdf && typeof pdf.output === 'function') return Promise.resolve(pdf.output('blob'));
        if (pdf instanceof Blob) return Promise.resolve(pdf);
        if (pdf instanceof ArrayBuffer || ArrayBuffer.isView(pdf)) return Promise.resolve(new Blob([pdf], { type: 'application/pdf' }));
        return Promise.reject(new Error('no pdf'));
    }
    /** A Blob as plain base64 (no "data:" prefix). */
    function toBase64(blob) {
        return new Promise(function (resolve, reject) {
            var r = new FileReader();
            r.onload = function () { var s = String(r.result); resolve(s.slice(s.indexOf(',') + 1)); };
            r.onerror = function () { reject(r.error); };
            r.readAsDataURL(blob);
        });
    }

    /**
     * @param {{subject: string, html?: string, filename: string, pdf: (Blob|ArrayBuffer|Uint8Array|object)}} opts
     *        `pdfBytes` and `blob` are accepted as other names for `pdf`.
     * @returns {Promise<{ok: boolean, sentTo?: string, message?: string, code?: string, error?: string}>}
     */
    window.emailReportToMe = function (opts) {
        opts = opts || {};
        var pdf = opts.pdf || opts.pdfBytes || opts.blob;
        return toBlob(pdf).then(function (blob) {
            if (!blob || !blob.size) return fail('bad_request');
            if (blob.size > MAX_BYTES) return fail('too_large');
            return toBase64(blob).then(function (b64) {
                var meta = document.querySelector('meta[name="csrf-token"]');
                var headers = { 'Content-Type': 'application/json' };
                if (meta && meta.content) headers['X-CSRF-Token'] = meta.content;
                return fetch(ENDPOINT, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: headers,
                    body: JSON.stringify({
                        subject: String(opts.subject || ''),
                        html: String(opts.html || ''),
                        filename: String(opts.filename || 'report.pdf'),
                        pdf_base64: b64
                    })
                }).then(function (r) {
                    return r.json().catch(function () { return { success: false, code: r.status === 403 ? 'not_signed_in' : 'send_failed' }; });
                }).then(function (d) {
                    if (d && d.success) return { ok: true, sentTo: d.sent_to, delivered: d.delivered || {}, message: tr('sent', { email: d.sent_to }) };
                    // A CSRF refusal says "reload the page" in its own words - keep them.
                    if (d && typeof d.code === 'string' && d.code.indexOf('csrf_') === 0) return { ok: false, code: d.code, error: d.error };
                    return fail(d && ENGLISH[d.code] ? d.code : 'send_failed');
                }, function () { return fail('network'); });
            });
        }).catch(function () { return fail('bad_request'); });
    };
})();
