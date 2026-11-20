/**
 * Copying text to the clipboard, in the one place.
 *
 * ⚠️ THE BUG THIS EXISTS TO FIX. Twelve places called
 * `navigator.clipboard.writeText(x).then(...).catch(...)` directly, and ten of
 * them did not check that `navigator.clipboard` was there at all. It is absent
 * outside a secure context — plain HTTP, or HTTPS with a certificate the phone
 * does not trust, which is exactly the situation of a self-hosted server on an
 * internal name. Reading `.writeText` of `undefined` throws SYNCHRONOUSLY, so
 * the `.catch()` never runs: the fallback never fired, no message appeared, and
 * nothing was copied. Ed hit it sharing a Knowledge link from an iPhone.
 *
 * Two further things this gets right that the old inline copies did not:
 *
 *  1. iOS Safari ignores `input.select()`. Selecting text there needs a Range
 *     over the node plus `setSelectionRange`, and the element must be a real,
 *     rendered, readonly field — not `display: none`, not zero-opacity-with-no-
 *     size. A 16px font stops iOS zooming the page while it is focused.
 *
 *  2. IT NEVER CLAIMS SUCCESS IT DID NOT HAVE. The old fallback ran
 *     `execCommand('copy')`, ignored the false it can return, and showed
 *     "Copied!" regardless. Being told something is on your clipboard when it
 *     is not is worse than being told the copy failed, because you only find
 *     out when you paste somewhere else and lose whatever you were doing.
 *
 * Resolves to true only if the text really was copied.
 */
(function () {
    'use strict';

    /**
     * Copies text with execCommand('copy') through a hidden-but-rendered textarea, then restores the page's selection.
     *
     * @return true only if the browser reports the copy worked
     */
    function legacyCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        // Rendered and on screen — but 1px and transparent, and pinned so the
        // page cannot scroll to it. An element that is display:none or
        // visibility:hidden cannot be selected, so execCommand copies nothing.
        ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;' +
                           'padding:0;border:none;outline:none;box-shadow:none;' +
                           'background:transparent;opacity:0;font-size:var(--font-size-xl, 16px);';
        document.body.appendChild(ta);

        var ok = false;
        try {
            var selection = window.getSelection();
            var saved = selection.rangeCount > 0 ? selection.getRangeAt(0) : null;

            var range = document.createRange();
            range.selectNodeContents(ta);
            selection.removeAllRanges();
            selection.addRange(range);
            ta.setSelectionRange(0, text.length);

            ok = document.execCommand('copy');

            // Put back whatever the person had selected before we borrowed it.
            selection.removeAllRanges();
            if (saved) selection.addRange(saved);
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(ta);
        return ok;
    }

    /**
     * Copies text to the clipboard - the Clipboard API where the page is a secure context, otherwise (or if it is refused) the execCommand fallback.
     *
     * @param  {string} text
     * @return {Promise<boolean>} true only if the text is genuinely on the clipboard
     */
    window.copyToClipboard = function (text) {
        text = String(text === null || text === undefined ? '' : text);
        if (!text) return Promise.resolve(false);

        // ⚠️ Check the object exists BEFORE touching writeText. This guard is
        // the whole point of the file.
        var canUseApi = !!(navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext);
        if (!canUseApi) return Promise.resolve(legacyCopy(text));

        return navigator.clipboard.writeText(text)
            .then(function () { return true; })
            // A rejection is still worth a second attempt: Safari rejects when
            // the write is not close enough to the tap that caused it.
            .catch(function () { return legacyCopy(text); });
    };
})();
