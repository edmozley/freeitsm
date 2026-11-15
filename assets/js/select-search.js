/**
 * Searchable company lists (GH #358).
 *
 * An install looking after 3,000 companies cannot pick one from a plain <select>.
 * This script upgrades long company lists on every page - including lists filled in
 * or refilled later - to a box that opens a search field and the first 10 matches.
 *
 * 🔑 THE REAL <select> STAYS, hidden, and keeps the value. So every screen that
 * reads, fills or saves a company keeps working unchanged:
 *   - a pick sets select.value and fires its 'input' and 'change' events;
 *   - code that sets select.value / selectedIndex is caught, and the box follows;
 *   - options added or replaced later are followed (a MutationObserver);
 *   - code that hides or disables the select is mirrored.
 *
 * WHICH LISTS: a <select> whose id or name mentions company or tenant, or any with
 * data-searchable. Never a multiple select, never one with data-native-select (on it
 * or an ancestor). Upgraded only once it has MORE options than the System setting
 * (window.SELECT_SEARCH.after, default 15) - a small install sees no change at all.
 *
 * Loaded on every page by Tz::scriptTag() (includes/timezone.php), with its settings
 * and words in window.SELECT_SEARCH. Design: wiki Company-Picker-Design.
 */
(function () {
    'use strict';
    if (window.FreeitsmSelectSearch) return;
    var C = window.SELECT_SEARCH || {};
    var AFTER = Math.max(0, parseInt(C.after, 10) || 15);
    var SHOW = 10;
    var T = C.text || {};
    function say(k, d, p) {
        var s = T[k] || d;
        if (p) Object.keys(p).forEach(function (n) { s = s.split('{' + n + '}').join(p[n]); });
        return s;
    }
    var NATIVE_VALUE = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
    var NATIVE_INDEX = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');
    var registry = new WeakMap();
    var open = null;   // the state whose list is showing

    function wanted(sel) {
        if (sel.multiple || sel.closest('[data-native-select]')) return false;
        if (sel.hasAttribute('data-searchable')) return true;
        return /compan|tenant/i.test((sel.id || '') + ' ' + (sel.name || ''));
    }
    function fold(s) {
        s = String(s || '').toLowerCase();
        return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
    }

    // ------------------------------------------------------------------ CSS
    var css = document.createElement('style');
    css.textContent =
        'select.ss-native{display:none!important}' +
        '.ss-wrap{display:inline-block;position:relative;max-width:100%;vertical-align:middle}' +
        '.ss-wrap.ss-block{display:block}' +
        '.ss-box{display:flex;align-items:center;gap:6px;width:100%;box-sizing:border-box;text-align:left;cursor:pointer;overflow:hidden;white-space:nowrap}' +
        '.ss-box .ss-label{flex:1;overflow:hidden;text-overflow:ellipsis}' +
        '.ss-box .ss-caret{flex:none;opacity:.6;font-size:10px}' +
        '.ss-box:disabled{cursor:default;opacity:.6}' +
        '.ss-pop{position:fixed;z-index:10050;box-sizing:border-box;min-width:220px;max-width:min(420px,calc(100vw - 16px));background:var(--surface,#fff);color:var(--text,#222);border:1px solid var(--border,#d1d5db);border-radius:8px;box-shadow:0 10px 28px rgba(0,0,0,.2);padding:6px;font-size:13px;font-family:inherit}' +
        '.ss-pop input.ss-find{width:100%;box-sizing:border-box;padding:7px 9px;border:1px solid var(--border,#d1d5db);border-radius:6px;background:var(--surface,#fff);color:var(--text,#222);font:inherit;margin:0 0 4px}' +
        '.ss-pop input.ss-find:focus{outline:none;border-color:var(--accent,#0078d4);box-shadow:0 0 0 3px var(--accent-soft,rgba(0,120,212,.15))}' +
        '.ss-pop ul{list-style:none;margin:0;padding:0}' +
        '.ss-pop li{padding:7px 9px;border-radius:5px;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
        '.ss-pop li.ss-on{background:var(--accent-soft,#e8f1fb)}' +
        '.ss-pop li.ss-sel{font-weight:600}' +
        '.ss-pop li.ss-off{opacity:.45;cursor:default}' +
        '.ss-pop .ss-more{padding:6px 9px 2px;color:var(--text-dim,#888);font-size:12px}';
    (document.head || document.documentElement).appendChild(css);

    // ------------------------------------------------------------------ upgrade
    function upgrade(sel) {
        if (registry.has(sel)) return refresh(registry.get(sel));
        if (!wanted(sel) || sel.options.length <= AFTER) return;

        var wrap = document.createElement('span');
        wrap.className = 'ss-wrap';
        var cs = window.getComputedStyle(sel);
        if (cs.display === 'block' || cs.width === '100%' || sel.style.width === '100%') wrap.classList.add('ss-block');
        if (sel.style.width) wrap.style.width = sel.style.width;
        var box = document.createElement('button');
        box.type = 'button';
        // The select's own classes, so the box looks like the list it replaces.
        box.className = (sel.className ? sel.className + ' ' : '') + 'ss-box';
        box.setAttribute('aria-haspopup', 'listbox');
        if (sel.id) box.setAttribute('data-ss-for', sel.id);
        var labelFor = sel.id ? document.querySelector('label[for="' + CSS.escape(sel.id) + '"]') : null;
        if (labelFor) box.setAttribute('aria-label', labelFor.textContent.trim());
        box.innerHTML = '<span class="ss-label"></span><span class="ss-caret" aria-hidden="true">&#9660;</span>';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(box);
        wrap.appendChild(sel);
        // Hidden by a class, not an inline style: page code that sets style.display to
        // show or hide the list is then read as "show or hide the box" (refresh()).
        sel.classList.add('ss-native');
        sel.setAttribute('data-ss-upgraded', '1');

        var st = { sel: sel, wrap: wrap, box: box };
        registry.set(sel, st);

        // Code that sets the value or index is followed.
        Object.defineProperty(sel, 'value', {
            configurable: true,
            get: function () { return NATIVE_VALUE.get.call(sel); },
            set: function (v) { NATIVE_VALUE.set.call(sel, v); refresh(st); }
        });
        Object.defineProperty(sel, 'selectedIndex', {
            configurable: true,
            get: function () { return NATIVE_INDEX.get.call(sel); },
            set: function (v) { NATIVE_INDEX.set.call(sel, v); refresh(st); }
        });
        new MutationObserver(function () { refresh(st); })
            .observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'hidden', 'style'] });
        sel.addEventListener('change', function () { refresh(st); });
        box.addEventListener('click', function () { if (open === st) close(); else show(st); });
        box.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ' || (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey)) {
                e.preventDefault();
                show(st, e.key.length === 1 && e.key !== ' ' ? e.key : '');
            }
        });
        refresh(st);
    }

    function refresh(st) {
        var sel = st.sel;
        var o = sel.options[NATIVE_INDEX.get.call(sel)];
        st.box.querySelector('.ss-label').textContent = o ? o.text : '';
        st.box.disabled = sel.disabled;
        st.wrap.hidden = sel.hidden || sel.style.display === 'none';
        if (open === st) render(st);
    }

    // ------------------------------------------------------------------ the list
    var pop = null, find = null, list = null, more = null, matches = [], active = -1;
    function build() {
        pop = document.createElement('div');
        pop.className = 'ss-pop';
        pop.setAttribute('role', 'dialog');
        find = document.createElement('input');
        find.type = 'text';
        find.className = 'ss-find';
        find.setAttribute('autocomplete', 'off');
        find.setAttribute('role', 'combobox');
        find.setAttribute('aria-autocomplete', 'list');
        find.placeholder = say('search', 'Type to search');
        list = document.createElement('ul');
        list.setAttribute('role', 'listbox');
        more = document.createElement('div');
        more.className = 'ss-more';
        pop.appendChild(find); pop.appendChild(list); pop.appendChild(more);
        find.addEventListener('input', function () { if (open) render(open); });
        find.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
            else if (e.key === 'Enter') { e.preventDefault(); if (matches[active]) pick(matches[active]); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); var b = open && open.box; close(); if (b) b.focus(); }
            else if (e.key === 'Tab') close();
        });
        list.addEventListener('mousedown', function (e) { e.preventDefault(); });
        list.addEventListener('click', function (e) {
            var li = e.target.closest('li[data-i]');
            if (li && matches[+li.getAttribute('data-i')]) pick(matches[+li.getAttribute('data-i')]);
        });
        document.addEventListener('mousedown', function (e) {
            if (open && !pop.contains(e.target) && !open.wrap.contains(e.target)) close();
        }, true);
        window.addEventListener('resize', function () { if (open) place(open); });
        window.addEventListener('scroll', function (e) { if (open && !pop.contains(e.target)) place(open); }, true);
    }
    function show(st, typed) {
        if (st.sel.disabled) return;
        if (!pop) build();
        close();
        open = st;
        document.body.appendChild(pop);
        find.value = typed || '';
        render(st);
        place(st);
        st.box.setAttribute('aria-expanded', 'true');
        find.focus();
    }
    function close() {
        if (!open) return;
        open.box.setAttribute('aria-expanded', 'false');
        open = null;
        if (pop && pop.parentNode) pop.parentNode.removeChild(pop);
    }
    function place(st) {
        var r = st.box.getBoundingClientRect();
        pop.style.minWidth = Math.max(220, r.width) + 'px';
        var h = pop.offsetHeight || 360;
        var top = r.bottom + 4;
        if (top + h > window.innerHeight - 8 && r.top - h - 4 > 8) top = r.top - h - 4;
        pop.style.top = Math.max(8, top) + 'px';
        pop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - pop.offsetWidth - 8)) + 'px';
    }
    function render(st) {
        var q = fold(find.value.trim());
        var opts = st.sel.options, all = [], total = 0;
        for (var i = 0; i < opts.length; i++) {
            var o = opts[i];
            if (o.hidden) continue;
            total++;
            if (q === '' || fold(o.text).indexOf(q) !== -1) all.push(i);
        }
        matches = all.slice(0, SHOW);
        var cur = NATIVE_INDEX.get.call(st.sel);
        active = Math.max(0, matches.indexOf(cur));
        list.innerHTML = '';
        matches.forEach(function (idx, n) {
            var o = opts[idx];
            var li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.setAttribute('data-i', String(n));
            li.textContent = o.text || ' ';
            if (idx === cur) { li.classList.add('ss-sel'); li.setAttribute('aria-selected', 'true'); }
            if (o.disabled) li.classList.add('ss-off');
            if (n === active) li.classList.add('ss-on');
            list.appendChild(li);
        });
        more.textContent = !all.length ? say('none', 'No matches.')
            : all.length > SHOW ? say('more', 'Showing {n} of {total} - type to narrow.', { n: SHOW, total: all.length.toLocaleString() })
            : '';
        more.hidden = !more.textContent;
    }
    function move(d) {
        if (!matches.length) return;
        active = (active + d + matches.length) % matches.length;
        list.querySelectorAll('li').forEach(function (li, n) { li.classList.toggle('ss-on', n === active); });
    }
    function pick(idx) {
        var st = open, sel = st.sel, o = sel.options[idx];
        if (!o || o.disabled) return;
        close();
        if (NATIVE_INDEX.get.call(sel) !== idx) {
            NATIVE_INDEX.set.call(sel, idx);
            refresh(st);
            sel.dispatchEvent(new Event('input', { bubbles: true }));
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
        st.box.focus();
    }

    // ------------------------------------------------------------------ discovery
    function scan(root) {
        if (root.tagName === 'SELECT') { upgrade(root); return; }
        if (root.querySelectorAll) root.querySelectorAll('select').forEach(upgrade);
    }
    function start() {
        scan(document);
        new MutationObserver(function (ms) {
            ms.forEach(function (m) {
                m.addedNodes.forEach(function (n) { if (n.nodeType === 1) scan(n); });
                // Options filled into a select already on the page: it may now be long enough.
                if (m.target && m.target.tagName === 'SELECT') upgrade(m.target);
                else if (m.target && m.target.parentNode && m.target.parentNode.tagName === 'SELECT') upgrade(m.target.parentNode);
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
    // ------------------------------------------------------------------ lists that are not <select>s
    /**
     * A search box at the top of a list of companies that is NOT a <select>: a
     * right-click "Move to company" submenu, or a list of company tickboxes. Call it
     * after (re)building the list. Below the threshold it does nothing.
     *
     * @param {Element} container  holds the items
     * @param {string} itemSel     one company's element, e.g. '.ticket-context-submenu-item'
     * @param {{cap?: number}} opts cap: show at most this many (a menu: 10). 0 = no cap -
     *                              a tick list, where every row must stay reachable.
     */
    function filterList(container, itemSel, opts) {
        if (!container) return;
        opts = opts || {};
        var cap = opts.cap || 0;
        var old = container.querySelector('.ss-filter');
        if (old) old.parentNode.removeChild(old);
        var items = Array.prototype.slice.call(container.querySelectorAll(itemSel));
        if (items.length <= AFTER) return;
        var wrap = document.createElement('div');
        wrap.className = 'ss-filter';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'ss-find';
        input.setAttribute('autocomplete', 'off');
        input.placeholder = say('search', 'Type to search');
        var note = document.createElement('div');
        note.className = 'ss-more';
        wrap.appendChild(input); wrap.appendChild(note);
        container.insertBefore(wrap, container.firstChild);
        function apply() {
            var q = fold(input.value.trim()), shown = 0, hits = 0;
            items.forEach(function (it) {
                var m = q === '' || fold(it.textContent).indexOf(q) !== -1;
                if (m) hits++;
                var show = m && (!cap || shown < cap);
                if (show) shown++;
                it.style.display = show ? '' : 'none';
            });
            note.textContent = !hits ? say('none', 'No matches.')
                : (cap && hits > cap) ? say('more', 'Showing {n} of {total} - type to narrow.', { n: cap, total: hits.toLocaleString() }) : '';
            note.hidden = !note.textContent;
        }
        input.addEventListener('input', apply);
        // Keys and clicks stay in the box: a menu must not act on them or close.
        ['click', 'mousedown', 'mouseup', 'contextmenu'].forEach(function (ev) { wrap.addEventListener(ev, function (e) { e.stopPropagation(); }); });
        input.addEventListener('keydown', function (e) {
            e.stopPropagation();
            // Enter picks in a menu; in a tick list it would tick something by accident.
            if (e.key === 'Enter') {
                e.preventDefault();
                if (!cap) return;
                var first = items.filter(function (it) { return it.style.display !== 'none'; })[0];
                if (first) { var target = first.querySelector('input[type=checkbox]') || first; target.click(); }
            }
        });
        apply();
    }
    var css2 = document.createElement('style');
    css2.textContent =
        '.ss-filter{padding:6px 6px 2px;position:sticky;top:0;background:inherit;z-index:1}' +
        '.ss-filter input.ss-find{width:100%;box-sizing:border-box;padding:6px 8px;border:1px solid var(--border,#d1d5db);border-radius:6px;background:var(--surface,#fff);color:var(--text,#222);font:inherit;font-size:13px}' +
        '.ss-filter input.ss-find:focus{outline:none;border-color:var(--accent,#0078d4)}' +
        '.ss-filter .ss-more{padding:4px 2px 0;color:var(--text-dim,#888);font-size:12px}';
    (document.head || document.documentElement).appendChild(css2);

    window.FreeitsmSelectSearch = { upgrade: upgrade, filterList: filterList, after: AFTER };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
