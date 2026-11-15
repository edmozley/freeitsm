/**
 * FreeITSM's own date and time picker (GH #355).
 *
 * The browser's <input type="date" | "datetime-local" | "time"> writes dates
 * the way the BROWSER's language does, whatever System -> Date and time formats
 * says - a US-English browser shows 11/15/2026 and 10:00 AM to a desk that chose
 * 15 Nov 2026 and the 24-hour clock. This script upgrades every such box on the
 * page, including boxes added later, to a text box in the chosen format plus a
 * calendar to click.
 *
 * 🔑 THE BROWSER'S BOX STAYS, hidden, and keeps holding the value exactly as it
 * always did ('2026-08-05', '2026-08-05T14:30', '14:30'). So every screen that
 * reads, saves or fills a date keeps working unchanged:
 *   - a pick sets the hidden box and fires its 'input' and 'change' events;
 *   - code that sets hiddenBox.value is caught, and the visible box follows;
 *   - code that hides, disables or requires the hidden box is mirrored.
 *
 * Not upgraded: touch-only devices (their own picker is better there), and any
 * box with data-native-date (on it or an ancestor).
 *
 * The formats, month and weekday names, the first day of the week and this
 * picker's own words all come from window.DATE_FORMAT (DateFmt::jsPayload(),
 * published by Tz::scriptTag(), which also loads this file).
 *
 * Design: wiki Date-Picker-Design.
 */
(function () {
    'use strict';
    if (window.FreeitsmDateInput) return;
    var F = window.DATE_FORMAT;
    if (!F || !F.dateTemplate) return;
    // Touch-only screens keep their own picker (Ed, 16 Nov 2026).
    var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    var fine = window.matchMedia && window.matchMedia('(any-pointer: fine)').matches;
    if (coarse && !fine) return;

    var P = F.picker || {};
    var TYPES = { 'date': 1, 'datetime-local': 1, 'time': 1 };
    var NATIVE_VALUE = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    var registry = new WeakMap();   // native input -> state
    var is12h = /A/.test(F.timeTemplate || '');
    var weekStart = F.weekStart === 'sunday' ? 6 : 0;   // index into Monday-first weekday names

    // ---------------------------------------------------------------- formats
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function fmtDate(y, m, d) {
        return F.dateTemplate.replace(/YYYY|YY|MONTH|MON|MM|DD|D/g, function (t) {
            switch (t) {
                case 'YYYY': return String(y);
                case 'YY': return pad(y % 100);
                case 'MONTH': return (F.monthsInDate || F.months || [])[m - 1] || pad(m);
                case 'MON': return (F.monthsShort || [])[m - 1] || pad(m);
                case 'MM': return pad(m);
                case 'DD': return pad(d);
                default: return String(d);
            }
        });
    }
    function fmtTime(h, mi) {
        return F.timeTemplate.replace(/HH|h|mi|A/g, function (t) {
            switch (t) {
                case 'HH': return pad(h);
                case 'h': return String(h % 12 || 12);
                case 'mi': return pad(mi);
                default: return h < 12 ? 'AM' : 'PM';
            }
        });
    }
    /** ISO value -> parts, or null. */
    function split(type, v) {
        if (!v) return null;
        var m;
        if (type === 'time') {
            m = /^(\d{2}):(\d{2})/.exec(v);
            return m ? { h: +m[1], mi: +m[2] } : null;
        }
        m = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(v);
        if (!m) return null;
        return { y: +m[1], m: +m[2], d: +m[3], h: m[4] != null ? +m[4] : null, mi: m[5] != null ? +m[5] : null };
    }
    function display(type, v) {
        var p = split(type, v);
        if (!p) return '';
        if (type === 'time') return fmtTime(p.h, p.mi);
        var s = fmtDate(p.y, p.m, p.d);
        if (type === 'datetime-local') s += ' ' + fmtTime(p.h || 0, p.mi || 0);
        return s;
    }
    function iso(type, p) {
        if (type === 'time') return pad(p.h) + ':' + pad(p.mi);
        var d = p.y + '-' + pad(p.m) + '-' + pad(p.d);
        return type === 'datetime-local' ? d + 'T' + pad(p.h || 0) + ':' + pad(p.mi || 0) : d;
    }
    function validDay(y, m, d) {
        var dt = new Date(y, m - 1, d);
        return dt.getFullYear() === y && dt.getMonth() === m - 1 && dt.getDate() === d;
    }

    // ----------------------------------------------------------------- parsing
    function monthFromWord(w) {
        w = w.toLowerCase().replace(/\./g, '');
        var lists = [F.months, F.monthsShort, F.monthsInDate,
            ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december']];
        for (var l = 0; l < lists.length; l++) {
            var list = lists[l] || [];
            for (var i = 0; i < list.length; i++) {
                var name = String(list[i]).toLowerCase().replace(/\./g, '');
                if (name === w || (w.length >= 3 && name.indexOf(w) === 0)) return i + 1;
            }
        }
        return 0;
    }
    /** Read a date typed in the chosen format (or ISO). -> {y,m,d} or null. */
    function parseDate(text) {
        text = String(text).trim();
        var m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(text);
        if (m) return validDay(+m[1], +m[2], +m[3]) ? { y: +m[1], m: +m[2], d: +m[3] } : null;
        var order = [];
        F.dateTemplate.replace(/YYYY|YY|MONTH|MON|MM|DD|D/g, function (t) {
            order.push(t === 'YYYY' || t === 'YY' ? 'y' : (t === 'DD' || t === 'D' ? 'd' : 'm'));
            return t;
        });
        var parts = text.match(/\d+|[^\s\d.,\/\-]+/g) || [];
        var nums = [], month = 0;
        parts.forEach(function (p) {
            if (/^\d+$/.test(p)) nums.push(+p);
            else if (!month) month = monthFromWord(p);
        });
        var want = order.filter(function (k) { return !(k === 'm' && month); });
        if (nums.length !== want.length) return null;
        var out = { y: 0, m: month, d: 0 };
        want.forEach(function (k, i) { out[k] = nums[i]; });
        if (out.y < 100) out.y += 2000;
        return validDay(out.y, out.m, out.d) ? out : null;
    }
    /** Read a time: 14:30, 14.30, 1430, 2:30 pm, 2pm. -> {h,mi} or null. */
    function parseTime(text) {
        var m = /^\s*(\d{1,2})(?:[:.h]?(\d{2}))?\s*([ap])?\.?\s*m?\.?\s*$/i.exec(String(text));
        if (!m) return null;
        var h = +m[1], mi = m[2] != null ? +m[2] : 0;
        if (m[3]) {
            if (h < 1 || h > 12) return null;
            h = (h % 12) + (m[3].toLowerCase() === 'p' ? 12 : 0);
        }
        return h < 24 && mi < 60 ? { h: h, mi: mi } : null;
    }
    function parse(type, text) {
        text = String(text).trim();
        if (type === 'time') return parseTime(text);
        if (type === 'date') return parseDate(text);
        // A date and a time: the time is the last group that looks like one.
        var m = /\s+(\d{1,2}(?:[:.]\d{2})?\s*(?:[ap]\.?m?\.?)?)\s*$/i.exec(text);
        var t = m ? parseTime(m[1]) : null;
        var d = parseDate(m && t ? text.slice(0, m.index) : text);
        if (!d) return null;
        return { y: d.y, m: d.m, d: d.d, h: t ? t.h : 0, mi: t ? t.mi : 0 };
    }

    // -------------------------------------------------------------------- CSS
    var css = document.createElement('style');
    css.textContent =
        '.di-wrap{display:contents}' +
        '.di-native{position:absolute!important;opacity:0!important;pointer-events:none!important;width:1px!important;height:1px!important;min-width:0!important;padding:0!important;border:0!important;margin:0!important}' +
        '.di-text.di-invalid{border-color:var(--danger-border,#e57373)!important;outline-color:var(--danger-border,#e57373)}' +
        '.di-pop{position:fixed;z-index:10050;width:268px;box-sizing:border-box;background:var(--surface,#fff);color:var(--text,#222);border:1px solid var(--border,#d1d5db);border-radius:8px;box-shadow:0 10px 28px rgba(0,0,0,.2);padding:10px;font-size:13px;font-family:inherit;line-height:1.3}' +
        '.di-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;font-weight:600}' +
        '.di-nav{background:none;border:1px solid transparent;border-radius:5px;cursor:pointer;color:inherit;font-size:16px;width:28px;height:28px;line-height:1}' +
        '.di-nav:hover{background:var(--surface-hover,#f3f4f6)}' +
        '.di-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center}' +
        '.di-dow{font-size:11px;color:var(--text-dim,#888);padding:3px 0;font-weight:600}' +
        '.di-day{background:none;border:1px solid transparent;border-radius:5px;padding:5px 0;cursor:pointer;color:inherit;font:inherit}' +
        '.di-day:hover{background:var(--surface-hover,#f3f4f6)}' +
        '.di-day.di-out{color:var(--text-faint,#bbb)}' +
        '.di-day.di-today{border-color:var(--border,#d1d5db)}' +
        '.di-day.di-sel{background:var(--accent,#2563eb);color:var(--on-accent,#fff)}' +
        '.di-day:disabled{opacity:.35;cursor:default}' +
        '.di-time{display:flex;gap:6px;align-items:center;margin-top:8px}' +
        '.di-time select{flex:1;padding:4px;border:1px solid var(--border,#d1d5db);border-radius:5px;background:var(--surface,#fff);color:inherit;font:inherit}' +
        '.di-foot{display:flex;justify-content:space-between;margin-top:8px}' +
        '.di-foot button{background:none;border:1px solid var(--border,#d1d5db);border-radius:5px;padding:4px 10px;cursor:pointer;color:inherit;font:inherit}' +
        '.di-foot button:hover{background:var(--surface-hover,#f3f4f6)}';
    (document.head || document.documentElement).appendChild(css);

    // ------------------------------------------------------------------ popup
    var pop = null, popFor = null, view = null;   // view: {y, m}
    function closePop() {
        if (pop) { pop.remove(); pop = null; popFor = null; }
    }
    function placePop(st) {
        var r = st.text.getBoundingClientRect();
        var h = pop.offsetHeight, w = pop.offsetWidth;
        var top = r.bottom + 4;
        if (top + h > window.innerHeight - 8 && r.top - h - 4 > 8) top = r.top - h - 4;
        var left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8);
        pop.style.top = top + 'px';
        pop.style.left = left + 'px';
    }
    function current(st) {
        return split(st.type, NATIVE_VALUE.get.call(st.native)) || null;
    }
    function inRange(st, y, m, d) {
        var v = y + '-' + pad(m) + '-' + pad(d);
        var min = (st.native.min || '').slice(0, 10), max = (st.native.max || '').slice(0, 10);
        return (!min || v >= min) && (!max || v <= max);
    }
    function openPop(st) {
        if (st.native.disabled || st.native.readOnly) return;
        if (popFor === st && pop) return;
        closePop();
        popFor = st;
        var cur = current(st);
        var now = new Date();
        view = cur && cur.y ? { y: cur.y, m: cur.m } : { y: now.getFullYear(), m: now.getMonth() + 1 };
        pop = document.createElement('div');
        pop.className = 'di-pop';
        pop.setAttribute('role', 'dialog');
        // Clicks inside never take the focus from the text box, so it never blurs mid-pick.
        pop.addEventListener('mousedown', function (e) { if (e.target.tagName !== 'SELECT') e.preventDefault(); });
        pop.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            e.stopPropagation();
            var back = popFor;
            closePop();
            if (back) { back.skipOpen = true; back.text.focus(); }
        });
        document.body.appendChild(pop);
        drawPop();
    }
    function drawPop() {
        var st = popFor;
        if (!st || !pop) return;
        var cur = current(st);
        var html = '';
        if (st.type !== 'time') {
            var title = ((F.months || [])[view.m - 1] || pad(view.m)) + ' ' + view.y;
            html += '<div class="di-head"><button type="button" class="di-nav" data-nav="-1" aria-label="' + esc(P.prev_month || 'Previous month') + '">&lsaquo;</button>'
                + '<span>' + esc(title) + '</span>'
                + '<button type="button" class="di-nav" data-nav="1" aria-label="' + esc(P.next_month || 'Next month') + '">&rsaquo;</button></div>';
            html += '<div class="di-grid">';
            var names = F.weekdaysShort || ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            for (var i = 0; i < 7; i++) html += '<div class="di-dow">' + esc(names[(i + weekStart) % 7]) + '</div>';
            var first = new Date(view.y, view.m - 1, 1);
            var mondayIdx = (first.getDay() + 6) % 7;            // 0 = Monday
            var lead = (mondayIdx - weekStart + 7) % 7;
            var start = new Date(view.y, view.m - 1, 1 - lead);
            var today = new Date();
            for (var c = 0; c < 42; c++) {
                var dt = new Date(start.getFullYear(), start.getMonth(), start.getDate() + c);
                var y = dt.getFullYear(), m = dt.getMonth() + 1, d = dt.getDate();
                var cls = 'di-day' + (m !== view.m ? ' di-out' : '')
                    + (y === today.getFullYear() && m === today.getMonth() + 1 && d === today.getDate() ? ' di-today' : '')
                    + (cur && cur.y === y && cur.m === m && cur.d === d ? ' di-sel' : '');
                html += '<button type="button" class="' + cls + '" data-day="' + y + '-' + m + '-' + d + '"'
                    + (inRange(st, y, m, d) ? '' : ' disabled') + '>' + d + '</button>';
            }
            html += '</div>';
        }
        if (st.type !== 'date') {
            var h = cur && cur.h != null ? cur.h : null, mi = cur && cur.mi != null ? cur.mi : null;
            html += '<div class="di-time"><select data-part="h" aria-label="' + esc(P.hour || 'Hour') + '"><option value=""></option>';
            for (var hh = 0; hh < 24; hh++) {
                var label = is12h ? fmtTime(hh, 0).replace(/:00/, '') : pad(hh);
                html += '<option value="' + hh + '"' + (h === hh ? ' selected' : '') + '>' + esc(label) + '</option>';
            }
            html += '</select><select data-part="mi" aria-label="' + esc(P.minute || 'Minute') + '"><option value=""></option>';
            var mins = [];
            for (var mm = 0; mm < 60; mm += 5) mins.push(mm);
            if (mi != null && mins.indexOf(mi) < 0) { mins.push(mi); mins.sort(function (a, b) { return a - b; }); }
            mins.forEach(function (x) { html += '<option value="' + x + '"' + (mi === x ? ' selected' : '') + '>' + pad(x) + '</option>'; });
            html += '</select></div>';
        }
        html += '<div class="di-foot"><button type="button" data-act="today">' + esc(st.type === 'date' ? (P.today || 'Today') : (P.now || 'Now')) + '</button>'
            + '<button type="button" data-act="clear">' + esc(P.clear || 'Clear') + '</button></div>';
        pop.innerHTML = html;
        pop.onclick = function (e) {
            var b = e.target.closest('button');
            if (!b) return;
            if (b.dataset.nav) {
                view.m += +b.dataset.nav;
                if (view.m < 1) { view.m = 12; view.y--; }
                if (view.m > 12) { view.m = 1; view.y++; }
                drawPop();
            } else if (b.dataset.day) {
                var p = b.dataset.day.split('-').map(Number);
                var c0 = current(st) || {};
                var next = { y: p[0], m: p[1], d: p[2], h: c0.h != null ? c0.h : 9, mi: c0.mi != null ? c0.mi : 0 };
                commit(st, iso(st.type, next));
                if (st.type === 'date') closePop(); else drawPop();
            } else if (b.dataset.act === 'today') {
                var n = new Date();
                commit(st, iso(st.type, { y: n.getFullYear(), m: n.getMonth() + 1, d: n.getDate(), h: n.getHours(), mi: n.getMinutes() - n.getMinutes() % 5 }));
                closePop();
            } else if (b.dataset.act === 'clear') {
                commit(st, '');
                closePop();
            }
        };
        pop.onchange = function (e) {
            var sel = e.target.closest('select');
            if (!sel) return;
            var hs = pop.querySelector('select[data-part="h"]'), ms = pop.querySelector('select[data-part="mi"]');
            var c1 = current(st) || {};
            var h2 = hs.value === '' ? null : +hs.value, m2 = ms.value === '' ? null : +ms.value;
            if (h2 == null && m2 == null) return;
            h2 = h2 == null ? 0 : h2; m2 = m2 == null ? 0 : m2;
            if (st.type === 'time') { commit(st, iso('time', { h: h2, mi: m2 })); return; }
            var n2 = new Date();
            var base = c1.y ? c1 : { y: n2.getFullYear(), m: n2.getMonth() + 1, d: n2.getDate() };
            commit(st, iso(st.type, { y: base.y, m: base.m, d: base.d, h: h2, mi: m2 }));
        };
        placePop(st);
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ------------------------------------------------------------ the boxes
    function render(st) {
        st.text.value = display(st.type, NATIVE_VALUE.get.call(st.native));
        st.text.classList.remove('di-invalid');
        st.text.setCustomValidity('');
    }
    function commit(st, value) {
        var before = NATIVE_VALUE.get.call(st.native);
        NATIVE_VALUE.set.call(st.native, value);
        render(st);
        if (before !== value) {
            st.native.dispatchEvent(new Event('input', { bubbles: true }));
            st.native.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
    function example(type) {
        if (type === 'time') return fmtTime(14, 30);
        var s = fmtDate(2026, 8, 5);
        return type === 'datetime-local' ? s + ' ' + fmtTime(14, 30) : s;
    }
    /**
     * The pattern shown in an empty box - dd mmm yyyy, hh:mm - as the browser's own
     * box shows mm/dd/yyyy. A pattern, not an example date, so it cannot be
     * mistaken for a value.
     */
    function hint(type) {
        var map = { YYYY: 'yyyy', YY: 'yy', MONTH: 'mmmm', MON: 'mmm', MM: 'mm', DD: 'dd', D: 'd', HH: 'hh', h: 'h', mi: 'mm', A: 'am\/pm' };
        var d = F.dateTemplate.replace(/YYYY|YY|MONTH|MON|MM|DD|D/g, function (x) { return map[x]; });
        var t = F.timeTemplate.replace(/HH|h|mi|A/g, function (x) { return map[x]; });
        return type === 'time' ? t : (type === 'datetime-local' ? d + ' ' + t : d);
    }
    /** Read what was typed: commit it, or mark the box invalid and say how to write it. */
    function readTyped(st) {
        var text = st.text.value.trim();
        var shown = display(st.type, NATIVE_VALUE.get.call(st.native));
        if (text === shown) { render(st); return true; }
        if (text === '') { commit(st, ''); return true; }
        var p = parse(st.type, text);
        if (p && (st.type === 'time' || inRange(st, p.y, p.m, p.d))) { commit(st, iso(st.type, p)); return true; }
        var msg = String(st.type === 'time' ? (P.invalid_time || 'Type a time like {example}, or pick one.') : (P.invalid || 'Type a date like {example}, or pick one.'))
            .replace('{example}', example(st.type));
        st.text.setCustomValidity(msg);
        st.text.classList.add('di-invalid');
        return false;
    }
    function mirror(st) {
        var n = st.native, t = st.text;
        t.disabled = n.disabled;
        t.readOnly = n.readOnly;
        t.required = n.required;
        var cls = (n.getAttribute('class') || '').split(/\s+/).filter(function (c) { return c && c !== 'di-native'; });
        // di-type-*: the page's own rules for input[type=date] etc. name it too (GH #355).
        t.className = 'di-text di-type-' + st.type + (cls.length ? ' ' + cls.join(' ') : '');
        if (n.hidden || n.style.display === 'none') t.style.display = 'none';
        else t.style.display = st.textDisplay;
        if (n.title) t.title = n.title;
    }
    function enhance(n) {
        if (registry.has(n) || !TYPES[n.type] || n.closest('[data-native-date]')) return;
        var wrap = n.parentElement && n.parentElement.classList.contains('di-wrap') ? n.parentElement : null;
        if (wrap) {
            // A copy of an upgraded box (cloneNode): drop the copied text box and start again.
            Array.prototype.forEach.call(wrap.querySelectorAll('.di-text'), function (x) { x.remove(); });
        } else {
            wrap = document.createElement('span');
            wrap.className = 'di-wrap';
            n.parentNode.insertBefore(wrap, n);
            wrap.appendChild(n);
        }
        var t = document.createElement('input');
        t.type = 'text';
        t.autocomplete = 'off';
        t.spellcheck = false;
        t.style.cssText = n.style.cssText;
        var st = { native: n, text: t, type: n.type, textDisplay: n.style.display === 'none' ? '' : n.style.display };
        t.placeholder = hint(st.type);
        t.setAttribute('aria-label', n.getAttribute('aria-label') || (n.labels && n.labels[0] ? n.labels[0].textContent.trim() : ''));
        // The visible box goes FIRST, so a "please fill in" message points at it.
        wrap.insertBefore(t, n);
        n.classList.add('di-native');
        n.tabIndex = -1;
        registry.set(n, st);
        mirror(st);
        render(st);

        // Code that sets the hidden box's value is caught, and the visible one follows.
        Object.defineProperty(n, 'value', {
            configurable: true,
            get: function () { return NATIVE_VALUE.get.call(this); },
            set: function (v) { NATIVE_VALUE.set.call(this, v); render(st); }
        });
        // Focus on the hidden box (a <label for>, or code) goes to the visible one.
        n.addEventListener('focus', function () { t.focus(); });

        t.addEventListener('focus', function () {
            if (st.skipOpen) { st.skipOpen = false; return; }   // back from Escape: stay closed
            openPop(st);
        });
        t.addEventListener('click', function () { openPop(st); });
        t.addEventListener('input', function () { t.classList.remove('di-invalid'); t.setCustomValidity(''); });
        t.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                if (!readTyped(st)) { e.preventDefault(); t.reportValidity(); return; }
                closePop();
            } else if (e.key === 'Escape') {
                if (pop && popFor === st) { e.preventDefault(); e.stopPropagation(); closePop(); }
            } else if (e.key === 'ArrowDown' && e.altKey) {
                openPop(st);
            } else if (e.key === 'Tab') {
                closePop();
            }
        });
        t.addEventListener('blur', function () {
            readTyped(st);
            // Focus moving into the calendar (its hour and minute lists) is not leaving it.
            setTimeout(function () { if (popFor === st && document.activeElement !== t && !(pop && pop.contains(document.activeElement))) closePop(); }, 150);
        });
        // A form reset puts the hidden box back without its setter.
        if (n.form) n.form.addEventListener('reset', function () { setTimeout(function () { render(st); }, 0); });

        new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                if (muts[i].attributeName === 'type') {
                    if (!TYPES[n.type]) { unwrap(st); return; }
                    st.type = n.type;
                    t.placeholder = hint(st.type);
                    render(st);
                }
            }
            mirror(st);
        }).observe(n, { attributes: true, attributeFilter: ['disabled', 'readonly', 'required', 'class', 'style', 'hidden', 'type', 'title', 'min', 'max'] });
    }
    function unwrap(st) {
        if (popFor === st) closePop();
        st.text.remove();
        st.native.classList.remove('di-native');
        st.native.removeAttribute('tabindex');
        delete st.native.value;
        registry.delete(st.native);
    }

    function scan(root) {
        if (!root || !root.querySelectorAll) return;
        if (root.tagName === 'INPUT') { enhance(root); return; }
        Array.prototype.forEach.call(root.querySelectorAll('input[type="date"], input[type="datetime-local"], input[type="time"]'), enhance);
    }
    function start() {
        scan(document.body);
        new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                for (var j = 0; j < muts[i].addedNodes.length; j++) {
                    var node = muts[i].addedNodes[j];
                    if (node.nodeType === 1) scan(node);
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
        document.addEventListener('mousedown', function (e) {
            if (pop && !pop.contains(e.target) && (!popFor || e.target !== popFor.text)) closePop();
        }, true);
        window.addEventListener('resize', closePop);
        document.addEventListener('scroll', function (e) {
            if (pop && !pop.contains(e.target)) closePop();
        }, true);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

    // For tests and the rare page that builds its own boxes off-DOM.
    window.FreeitsmDateInput = { scan: scan, parse: parse, display: display };
})();
