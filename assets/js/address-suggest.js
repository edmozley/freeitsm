/**
 * Suggestions as you type in an address box - To and Cc when emailing from a ticket (GH #307).
 *
 *   AddressSuggest.attach(input, { fetch: q => Promise<[{email, name, source, detail}]> })
 *
 * The box stays a plain text box: several addresses separated by ; or , and anything can
 * still be typed. Only the address being typed (after the last separator) is looked up;
 * picking a suggestion replaces it with the address and "; ", ready for the next one.
 * Arrow keys move, Enter or Tab picks, Escape closes. What is suggested, and who may see
 * it, is decided by the server (api/tickets/address_suggest.php).
 *
 * @wiki Ticket-Address-Suggestions-Developer-Guide
 */
(function () {
    'use strict';
    if (window.AddressSuggest) return;

    /** A translated string under tickets.address_suggest, English as the fallback. */
    function T(key, en) {
        var full = 'tickets.address_suggest.' + key;
        var got = window.t ? window.t(full) : full;
        return got === full ? en : got;
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    var SOURCE = function (s) {
        return { service_desk: T('service_desk', 'Service desk'), contact: T('contact', 'Supplier contact'),
                 person: T('person', 'Person'), recent: T('recent', 'Recently emailed') }[s] || '';
    };

    /** Where the address being typed starts: just after the last ; or , (and any spaces). */
    function tokenStart(v) {
        var i = Math.max(v.lastIndexOf(';'), v.lastIndexOf(','));
        var j = i + 1;
        while (j < v.length && v.charAt(j) === ' ') j++;
        return j;
    }

    function attach(input, opts) {
        if (!input || input.dataset.addressSuggest) return;
        input.dataset.addressSuggest = '1';
        var box = document.createElement('div');
        box.className = 'addr-suggest';
        box.setAttribute('role', 'listbox');
        box.hidden = true;
        // Inside the input's own wrapper, so it scrolls and moves with the dialog.
        var host = input.parentNode;
        if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
        host.appendChild(box);
        input.setAttribute('aria-autocomplete', 'list');

        var items = [], active = -1, timer = null, seq = 0;

        function close() { box.hidden = true; items = []; active = -1; input.removeAttribute('aria-activedescendant'); }
        function paint() {
            box.innerHTML = items.map(function (it, i) {
                var label = it.source === 'service_desk'
                    ? T('service_desk_of', '{name} service desk').replace('{name}', it.name)
                    : (it.name || it.email);
                // The address under the name (when the name is not the address), and a contact's supplier.
                var sub = [it.name ? it.email : '', it.source === 'contact' ? it.detail : ''].filter(Boolean).join(' - ');
                return '<div class="addr-suggest-item' + (i === active ? ' on' : '') + '" role="option" id="addrSug' + i + '" data-i="' + i + '">' +
                    '<div class="addr-suggest-main"><span class="addr-suggest-name">' + esc(label) + '</span>' +
                    (sub ? '<span class="addr-suggest-sub">' + esc(sub) + '</span>' : '') + '</div>' +
                    '<span class="addr-suggest-tag addr-suggest-' + esc(it.source) + '">' + esc(SOURCE(it.source)) + '</span></div>';
            }).join('');
            // Under the box itself - in the email dialog its label sits beside it, in the same wrapper.
            box.style.left = input.offsetLeft + 'px';
            box.style.right = 'auto';
            box.style.width = input.offsetWidth + 'px';
            box.hidden = !items.length;
            if (active >= 0) input.setAttribute('aria-activedescendant', 'addrSug' + active);
        }
        function pick(i) {
            var it = items[i];
            if (!it) return;
            var v = input.value, s = tokenStart(v);
            input.value = v.slice(0, s) + it.email + '; ';
            close();
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
            input.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
        }
        function lookup() {
            var v = input.value;
            // Only when the cursor is at the end - editing the middle of the list is not "typing a new address".
            if (input.selectionStart !== v.length) { close(); return; }
            var q = v.slice(tokenStart(v)).trim();
            if (q.length < 2) { close(); return; }
            var mine = ++seq;
            opts.fetch(q).then(function (list) {
                if (mine !== seq) return;   // a newer keystroke has already asked
                items = (list || []).filter(function (it) { return v.toLowerCase().indexOf(it.email.toLowerCase() + ';') < 0; });
                active = items.length ? 0 : -1;
                paint();
            }).catch(close);
        }

        input.addEventListener('input', function (ev) {
            clearTimeout(timer);
            timer = setTimeout(lookup, 180);
        });
        input.addEventListener('keydown', function (ev) {
            if (box.hidden) return;
            if (ev.key === 'ArrowDown') { active = (active + 1) % items.length; paint(); ev.preventDefault(); }
            else if (ev.key === 'ArrowUp') { active = (active - 1 + items.length) % items.length; paint(); ev.preventDefault(); }
            else if ((ev.key === 'Enter' || ev.key === 'Tab') && active >= 0) { pick(active); ev.preventDefault(); }
            else if (ev.key === 'Escape') { close(); ev.stopPropagation(); ev.preventDefault(); }
        });
        // mousedown, not click: a click lands after the box has lost focus and closed.
        box.addEventListener('mousedown', function (ev) {
            var row = ev.target.closest('.addr-suggest-item');
            if (row) { ev.preventDefault(); pick(+row.dataset.i); }
        });
        input.addEventListener('blur', function () { setTimeout(close, 120); });
    }

    window.AddressSuggest = { attach: attach };
})();
