/**
 * The help assistant's window (wiki Help-Assistant-Design).
 *
 * A chat window on every page: the question as a bubble on the right, the reply
 * on the left - a short line and the help sections that answer it, as cards.
 * The conversation is kept on the server (api/help_assistant/history.php) so it
 * survives moving between pages, until Reset. Where the window sits and whether
 * it is open are a per-viewer convenience, kept in localStorage (and fine to lose).
 *
 * Desktop: a floating window, dragged by its header, reopened on the next page if
 * it was open. Phone (<= 768px): a full-screen sheet that the device back button
 * closes, never reopened by itself (it would cover the page), and 16px text so
 * iOS does not zoom (Mobile Techniques §3).
 *
 * Everything shown is built with textContent - questions and help text are data.
 * Config comes from window.HELP_ASSISTANT (includes/help_assistant_widget.php).
 */
(function () {
    'use strict';
    var C = window.HELP_ASSISTANT;
    if (!C) return;
    var S = function (k) { return (C.strings && C.strings[k]) || k; };
    var phoneMq = window.matchMedia('(max-width: 768px)');

    var store = {
        get: function (k, d) { try { var v = localStorage.getItem('helpAssistant.' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
        set: function (k, v) { try { localStorage.setItem('helpAssistant.' + k, JSON.stringify(v)); } catch (e) { /* private mode: fine */ } }
    };

    var panel, list, input, sendBtn, loaded = false, busy = false, pushed = false;

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = text;
        return e;
    }

    function iconButton(cls, label, svgPath, onClick) {
        var b = el('button', cls);
        b.type = 'button';
        b.title = label;
        b.setAttribute('aria-label', label);
        b.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + svgPath + '</svg>';
        b.addEventListener('click', onClick);
        return b;
    }

    function build() {
        if (panel) return;
        panel = el('div', 'hlpa-panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', S('title'));
        panel.hidden = true;

        var head = el('div', 'hlpa-head');
        var title = el('div', 'hlpa-title', S('title'));
        var reset = el('button', 'hlpa-reset', S('reset'));
        reset.type = 'button';
        reset.addEventListener('click', doReset);
        var close = iconButton('hlpa-close', S('close'), '<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>', function () { closePanel(false); });
        head.append(title, reset, close);

        var mode = el('div', 'hlpa-mode', S(C.level === 'search_meaning' ? 'mode_meaning' : 'mode_search'));
        list = el('div', 'hlpa-list');
        list.setAttribute('aria-live', 'polite');

        var form = el('form', 'hlpa-form');
        input = el('textarea', 'hlpa-input');
        input.rows = 1;
        input.maxLength = 500;
        input.placeholder = S('placeholder');
        input.setAttribute('aria-label', S('placeholder'));
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
        });
        input.addEventListener('input', grow);
        sendBtn = el('button', 'hlpa-send', S('send'));
        sendBtn.type = 'submit';
        form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
        form.append(input, sendBtn);

        panel.append(head, mode, list, form);
        panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePanel(false); });
        document.body.appendChild(panel);
        makeDraggable(head);
        place();
        window.addEventListener('resize', place);
    }

    function grow() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }

    // ── Where it sits (desktop) ─────────────────────────────────────────────
    function place() {
        if (!panel) return;
        // On a phone, or with nowhere saved, the CSS decides: a full-screen sheet, or bottom right.
        var clear = function () { panel.style.left = panel.style.top = panel.style.right = panel.style.bottom = ''; };
        if (phoneMq.matches) { clear(); return; }
        var pos = store.get('pos', null);
        if (!pos) { clear(); return; }
        var w = panel.offsetWidth || 380, h = panel.offsetHeight || 520;
        var x = Math.max(8, Math.min(pos.x, window.innerWidth - w - 8));
        var y = Math.max(56, Math.min(pos.y, window.innerHeight - h - 8));   // never under the header
        panel.style.left = x + 'px';
        panel.style.top = y + 'px';
        panel.style.right = 'auto';
        panel.style.bottom = 'auto';
    }

    function makeDraggable(handle) {
        handle.addEventListener('pointerdown', function (e) {
            if (phoneMq.matches || e.button !== 0 || e.target.closest('button')) return;
            var r = panel.getBoundingClientRect();
            var dx = e.clientX - r.left, dy = e.clientY - r.top;
            handle.setPointerCapture(e.pointerId);
            panel.classList.add('hlpa-dragging');
            function move(ev) {
                store.set('pos', { x: ev.clientX - dx, y: ev.clientY - dy });
                place();
            }
            function up() {
                panel.classList.remove('hlpa-dragging');
                handle.removeEventListener('pointermove', move);
                handle.removeEventListener('pointerup', up);
                handle.removeEventListener('pointercancel', up);
            }
            handle.addEventListener('pointermove', move);
            handle.addEventListener('pointerup', up);
            handle.addEventListener('pointercancel', up);
        });
    }

    // ── Open / close ────────────────────────────────────────────────────────
    function openPanel() {
        build();
        panel.hidden = false;
        document.getElementById('hlpaLaunch') && document.getElementById('hlpaLaunch').classList.add('hlpa-on');
        store.set('open', true);
        place();
        if (!loaded) loadHistory();
        // On a phone the sheet is a screen of its own: the back button closes it.
        if (phoneMq.matches && !pushed) { history.pushState({ helpAssistant: 1 }, ''); pushed = true; }
        setTimeout(function () { input.focus(); }, 30);
    }

    function closePanel(fromBack) {
        if (!panel || panel.hidden) return;
        panel.hidden = true;
        document.getElementById('hlpaLaunch') && document.getElementById('hlpaLaunch').classList.remove('hlpa-on');
        store.set('open', false);
        if (pushed && !fromBack) { pushed = false; history.back(); }
        pushed = false;
    }

    window.addEventListener('popstate', function () { if (pushed) { pushed = false; closePanel(true); } });
    window.helpAssistantToggle = function () { (panel && !panel.hidden) ? closePanel(false) : openPanel(); };

    // ── The conversation ────────────────────────────────────────────────────
    function scrollDown() { list.scrollTop = list.scrollHeight; }

    function greeting() {
        list.textContent = '';
        list.appendChild(el('div', 'hlpa-msg hlpa-bot hlpa-greeting', S('greeting')));
    }

    function addUser(text) {
        var b = el('div', 'hlpa-msg hlpa-user', text);
        list.appendChild(b);
        return b;
    }

    // ── Opening a result ────────────────────────────────────────────────────
    // TRAP: help pages scroll INSIDE a panel (.help-main) and the page itself is
    // never meant to scroll. A plain #anchor makes the browser scroll every
    // ancestor it can - the page too - so the whole page slid up under the header
    // and chopped the top nav. The help pages' own section links avoid this the
    // same way ("Scroll within the help container, not the page").

    /** The nearest ancestor that scrolls - the help page's panel. */
    function scrollParent(node) {
        for (var p = node.parentElement; p && p !== document.body; p = p.parentElement) {
            var o = getComputedStyle(p).overflowY;
            if ((o === 'auto' || o === 'scroll') && p.scrollHeight > p.clientHeight) return p;
        }
        return null;
    }

    /** Put the page back at the top, then bring a section into view inside its panel. */
    function reveal(id, smooth) {
        var docEl = document.scrollingElement || document.documentElement;
        docEl.scrollTop = 0;
        document.body.scrollTop = 0;
        var target = id ? document.getElementById(id) : null;
        if (!target) return;
        var panelEl = scrollParent(target);
        if (!panelEl) { target.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' }); return; }
        panelEl.scrollTo({ top: panelEl.scrollTop + target.getBoundingClientRect().top - panelEl.getBoundingClientRect().top - 20, behavior: smooth ? 'smooth' : 'auto' });
    }

    /** The same page? Pretty URLs drop .php, so compare without it. */
    function samePage(u) {
        var norm = function (p) { return p.replace(/\/index\.php$/, '/').replace(/\.php$/, '').replace(/\/$/, ''); };
        return u.origin === location.origin && norm(u.pathname) === norm(location.pathname) && u.search === location.search;
    }

    function openResult(e) {
        if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;   // a new tab: leave it to the browser
        var u = new URL(this.href, location.href);
        var id = decodeURIComponent(u.hash.slice(1));
        if (samePage(u)) {
            e.preventDefault();
            history.replaceState(history.state, '', u.hash || location.pathname + location.search);
            reveal(id, true);
        } else {
            store.set('jump', id);   // the next page puts itself right once it has loaded
        }
    }

    function card(r, best) {
        var a = el('a', 'hlpa-card' + (best ? ' hlpa-best' : ''));
        a.href = r.url;
        a.addEventListener('click', openResult);
        a.appendChild(el('div', 'hlpa-card-title', r.title));
        if (r.page_title) a.appendChild(el('div', 'hlpa-card-page', r.page_title));
        if (r.snippet) a.appendChild(el('div', 'hlpa-card-snip', r.snippet));
        a.appendChild(el('div', 'hlpa-card-open', S('open') + ' →'));
        return a;
    }

    function addBot(text, kind, results) {
        var b = el('div', 'hlpa-msg hlpa-bot');
        if (text) b.appendChild(el('div', 'hlpa-bot-text', text));
        results = results || [];
        if (kind === 'clear' && results.length) {
            // One clear winner: it first and larger, the rest underneath.
            b.appendChild(card(results[0], true));
            if (results.length > 1) {
                b.appendChild(el('div', 'hlpa-also', S('also')));
                results.slice(1, 3).forEach(function (r) { b.appendChild(card(r, false)); });
            }
        } else {
            results.slice(0, kind === 'weak' ? 3 : 4).forEach(function (r) { b.appendChild(card(r, false)); });
        }
        list.appendChild(b);
        return b;
    }

    function loadHistory() {
        loaded = true;
        greeting();
        fetch(C.history, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.success || !d.messages || !d.messages.length) return;
            list.textContent = '';
            d.messages.forEach(function (m) {
                if (m.role === 'user') addUser(m.body); else addBot(m.body, m.kind, m.results);
            });
            scrollDown();
        }).catch(function () { /* the greeting stays */ });
    }

    function send() {
        var q = input.value.trim();
        if (!q || busy) return;
        busy = true;
        sendBtn.disabled = true;
        if (list.querySelector('.hlpa-greeting')) list.textContent = '';
        addUser(q);
        input.value = '';
        grow();
        var wait = el('div', 'hlpa-msg hlpa-bot hlpa-wait', S('searching'));
        list.appendChild(wait);
        scrollDown();
        fetch(C.ask, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: q, module: C.module || null })
        }).then(function (r) { return r.json(); }).then(function (d) {
            wait.remove();
            if (!d.success) { addBot(d.error || S('error'), 'none', []); return; }
            addBot(d.reply.text, d.reply.kind, d.reply.results);
        }).catch(function () {
            wait.remove();
            addBot(S('error'), 'none', []);
        }).then(function () {
            busy = false;
            sendBtn.disabled = false;
            scrollDown();
            input.focus();
        });
    }

    function doReset() {
        fetch(C.reset, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: '{}' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.success) greeting(); })
            .catch(function () { /* left as it was */ });
        input.focus();
    }

    // Arrived from a result on another page: the browser has already jumped to the
    // #anchor, page and all - put the page back and scroll the panel instead.
    var jump = store.get('jump', null);
    if (jump !== null) {
        store.set('jump', null);
        if (decodeURIComponent(location.hash.slice(1)) === jump) {
            var fix = function () { reveal(jump, false); };
            if (document.readyState === 'complete') setTimeout(fix, 0); else window.addEventListener('load', function () { setTimeout(fix, 0); });
        }
    }

    // Open on this page if it was open on the last one - desktop only: on a phone
    // the sheet would cover the page the analyst just asked to see.
    if (store.get('open', false) && !phoneMq.matches) openPanel();
})();
