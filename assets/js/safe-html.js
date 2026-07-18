/**
 * safeHtmlFragment() — the one cleaner for untrusted HTML we have to display.
 *
 * WHAT COUNTS AS UNTRUSTED
 * ------------------------
 * Email bodies and chat messages. Anyone can email a service desk, so this
 * content is written by people outside the organisation, and it is rendered
 * inside a signed-in session — an analyst's reading pane, or a requester's own
 * ticket page in the self-service portal.
 *
 * WHY THIS IS SHARED
 * ------------------
 * The analyst inbox and the portal both display the same email bodies. A
 * security control kept in two copies drifts, and the copy that falls behind is
 * the one nobody is looking at. One function, both surfaces.
 *
 * THE MISTAKE THIS FIXES
 * ----------------------
 * The inbox's original cleaner was written for LAYOUT hygiene — balancing
 * unclosed tags and stripping <style> blocks that bled into the app's chrome —
 * and it reasoned about <script> correctly: scripts genuinely do NOT execute
 * when HTML is assigned via innerHTML. But that is only true of <script>.
 * Inline event handlers DO fire:
 *
 *     <img src=x onerror="...">        fires on insertion, every browser
 *     <svg onload="...">               same
 *
 * Shadow DOM does not help either — it isolates CSS, not script execution. So
 * an email could run code in the reader's session. Removing <script> alone is
 * not a defence; the handlers are the actual hole.
 *
 * WHAT IT DOES
 * ------------
 *   1. Parses in an inert DOMParser document, so nothing executes and nothing
 *      touches the live DOM while we clean it. This also balances the unclosed
 *      tags real email is full of — without that, a runaway <div> swallows the
 *      siblings that follow it in the reading pane.
 *   2. Drops tags that execute, navigate, or leak styling into the page.
 *   3. Drops EVERY inline event handler (any attribute starting "on").
 *   4. Drops javascript: and data:text/html URLs, which are script by another
 *      route. Whitespace is stripped before the check because "java\tscript:"
 *      is parsed as javascript: by browsers but not by a naive startsWith.
 *
 * It deliberately keeps ordinary formatting, links and images: this has to stay
 * usable for reading genuine mail, and a cleaner so aggressive that people stop
 * trusting the reading pane gets worked around.
 *
 * @param {string} html
 * @param {{attachmentBase?: string}} [options]
 *        attachmentBase — rewrite inline-image (cid:) URLs that the importer
 *        pointed at get_attachment.php onto this base, so they resolve wherever
 *        the app is mounted rather than at the web root.
 * @returns {string} cleaned HTML, safe to assign to innerHTML
 */
function safeHtmlFragment(html, options) {
    if (!html) return '';
    var opts = options || {};

    try {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        if (!doc.body) return '';

        // Executes, navigates, or leaks styling into the host page.
        doc.querySelectorAll('script, style, link, base, meta, iframe, frame, frameset, object, embed, form')
           .forEach(function (el) { el.remove(); });

        doc.body.querySelectorAll('*').forEach(function (el) {
            // Copy the list: removing attributes mutates it as we iterate.
            Array.prototype.slice.call(el.attributes).forEach(function (attr) {
                var name = attr.name.toLowerCase();
                var value = (attr.value || '').replace(/\s+/g, '').toLowerCase();

                if (name.indexOf('on') === 0) {
                    el.removeAttribute(attr.name);          // onerror, onload, onclick, ...
                } else if (name === 'srcdoc' || name === 'formaction') {
                    el.removeAttribute(attr.name);          // script by another name
                } else if ((name === 'href' || name === 'src' || name === 'xlink:href' || name === 'action')
                           && (value.indexOf('javascript:') === 0 || value.indexOf('data:text/html') === 0)) {
                    el.removeAttribute(attr.name);
                }
            });
        });

        // Inbound inline images (cid:) are saved server-side and their <img src>
        // rewritten to a get_attachment.php URL. That rewrite emits a ROOT-ABSOLUTE
        // path that ignores the app's deployment sub-path, so on any install not
        // served from the web root the images 404. Normalise them onto the caller's
        // base. Idempotent — already-relative URLs pass straight through.
        if (opts.attachmentBase) {
            doc.querySelectorAll('img[src*="get_attachment.php"]').forEach(function (img) {
                var raw = img.getAttribute('src') || '';
                var qs = raw.indexOf('?');
                img.setAttribute('src', opts.attachmentBase + 'get_attachment.php' + (qs >= 0 ? raw.slice(qs) : ''));
            });
        }

        return doc.body.innerHTML;
    } catch (e) {
        return '';
    }
}

/**
 * Fallback for callers when this file failed to load. Returns the text with all
 * markup escaped — visible but inert. Fails CLOSED: a missing security control
 * must never mean "render it raw".
 */
function escapeHtmlText(text) {
    var div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

/**
 * Render one message body, honouring what the message actually IS.
 *
 * `emails.body_type` has always recorded this, and every caller ignored it:
 * bodies were fed through the HTML cleaner regardless. Web chat and WhatsApp
 * write body_type='text' with the sender's message stored verbatim, so their
 * messages were being INTERPRETED AS MARKUP.
 *
 * That is both a security problem and a correctness one:
 *   - security: a web-chat visitor is anonymous — they need no mailbox and no
 *     account, just one POST to a public endpoint — so treating their text as
 *     markup was the shortest path to running code in an analyst's session.
 *   - correctness: a visitor typing "a < b" or "<3" had part of their message
 *     silently swallowed by the HTML parser.
 *
 * Escaping text is strictly better than cleaning it: there is no cleaner to
 * outsmart, because nothing is ever parsed as markup in the first place.
 *
 * @param {string} body
 * @param {string} bodyType  'text' | 'html' (anything not 'text' is treated as HTML)
 * @param {{attachmentBase?: string}} [options]
 */
function messageBodyHtml(body, bodyType, options) {
    if (!body) return '';
    if (String(bodyType || '').toLowerCase() === 'text') {
        // Escape everything, then honour the line breaks the sender typed.
        return escapeHtmlText(body).replace(/\r\n|\r|\n/g, '<br>');
    }
    return safeHtmlFragment(body, options);
}
