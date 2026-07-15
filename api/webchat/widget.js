/*
 * FreeITSM web chat widget loader.
 *
 * Dropped onto any website via the embed snippet:
 *   <script src=".../api/webchat/widget.js" data-freeitsm-widget="wc_..."></script>
 *
 * It reads its own key + API base from the script tag, pulls the widget's look-and-feel
 * from config.php, and renders a floating launcher + chat panel inside a Shadow DOM (so
 * the host site's CSS can't touch it and vice-versa). Messages go to send.php; analyst
 * replies arrive by polling poll.php. The per-conversation token lives in sessionStorage,
 * so a page navigation within the visit keeps the same chat.
 *
 * Vanilla JS, no dependencies, no cookies.
 */
(function () {
    'use strict';

    var self = document.currentScript || document.querySelector('script[data-freeitsm-widget]');
    if (!self || self.__freeitsmInit) { return; }
    self.__freeitsmInit = true;

    var KEY = self.getAttribute('data-freeitsm-widget');
    if (!KEY) { return; }

    // .../api/webchat/widget.js  ->  .../api/webchat/
    var BASE = self.src.replace(/widget\.js(\?.*)?$/, '');
    var STORE_KEY = 'freeitsm_wc_' + KEY;

    var cfg = null;
    var token = null;
    try { token = sessionStorage.getItem(STORE_KEY); } catch (e) { token = null; }
    var lastId = 0;
    var pollTimer = null;
    var pollInFlight = false;
    var opened = false;
    var started = false;      // conversation created (have a token) and chat view shown
    var closed = false;

    function api(path) { return BASE + path; }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ---- shadow-DOM shell + styles ---------------------------------------
    var host = document.createElement('div');
    host.setAttribute('data-freeitsm-webchat', KEY);
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    document.body.appendChild(host);

    var accent = '#2563eb';

    function styles() {
        return '' +
        ':host, * { box-sizing: border-box; }' +
        '.launcher { position: fixed; right: 20px; bottom: 20px; z-index: 2147483000;' +
        '  display: inline-flex; align-items: center; gap: 8px; border: 0; cursor: pointer;' +
        '  padding: 12px 18px; border-radius: 999px; color: #fff; background: ' + accent + ';' +
        '  font: 600 15px/1.2 -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;' +
        '  box-shadow: 0 6px 24px rgba(0,0,0,.22); transition: transform .12s ease; }' +
        '.launcher:hover { transform: translateY(-2px); }' +
        '.launcher svg { width: 20px; height: 20px; }' +
        '.panel { position: fixed; right: 20px; bottom: 20px; z-index: 2147483000;' +
        '  width: 370px; max-width: calc(100vw - 32px); height: 560px; max-height: calc(100vh - 40px);' +
        '  display: none; flex-direction: column; overflow: hidden; border-radius: 16px; background: #fff;' +
        '  box-shadow: 0 12px 48px rgba(0,0,0,.28);' +
        '  font: 14px/1.45 -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #1a1a1a; }' +
        '.panel.open { display: flex; }' +
        '.head { background: ' + accent + '; color: #fff; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; }' +
        '.head .title { font-weight: 700; font-size: 15px; }' +
        '.head button { background: transparent; border: 0; color: #fff; cursor: pointer; font-size: 22px; line-height: 1; padding: 0 4px; opacity: .9; }' +
        '.head button:hover { opacity: 1; }' +
        // .scroll fills the space between header and the pinned composer, and is itself a
        // flex column so .body can scroll while .foot stays put. min-height:0 is required
        // for a flex child to actually scroll rather than grow past its parent.
        '.scroll { flex: 1; display: flex; flex-direction: column; min-height: 0; }' +
        '.body { flex: 1; min-height: 0; overflow-y: auto; padding: 14px; background: #f6f7f9; }' +
        '.msg { display: flex; margin-bottom: 10px; }' +
        '.msg .bubble { max-width: 78%; padding: 9px 12px; border-radius: 14px; white-space: pre-wrap; word-wrap: break-word; }' +
        '.msg.visitor { justify-content: flex-end; }' +
        '.msg.visitor .bubble { background: ' + accent + '; color: #fff; border-bottom-right-radius: 4px; }' +
        '.msg.agent .bubble { background: #fff; color: #1a1a1a; border: 1px solid #e3e6ea; border-bottom-left-radius: 4px; }' +
        '.msg .who { display: block; font-size: 11px; opacity: .6; margin: 0 0 2px 2px; }' +
        '.foot { border-top: 1px solid #e8eaed; padding: 10px; background: #fff; }' +
        '.foot form { display: flex; gap: 8px; align-items: flex-end; }' +
        '.foot textarea { flex: 1; resize: none; border: 1px solid #d3d7dd; border-radius: 10px; padding: 9px 11px;' +
        '  font: inherit; max-height: 96px; }' +
        '.foot textarea:focus { outline: none; border-color: ' + accent + '; }' +
        '.foot .send { border: 0; background: ' + accent + '; color: #fff; border-radius: 10px; padding: 9px 14px; cursor: pointer; font: 600 14px inherit; }' +
        '.foot .send:disabled { opacity: .5; cursor: default; }' +
        // Escalation bar (AI widgets) — sits just above the composer.
        '.esc { display: flex; gap: 8px; padding: 8px 10px; border-top: 1px solid #eef0f3; background: #fff; }' +
        '.esc button { flex: 1; border: 1px solid ' + accent + '; background: #fff; color: ' + accent + ';' +
        '  border-radius: 10px; padding: 8px 10px; cursor: pointer; font: 600 13px inherit; }' +
        '.esc button:hover { background: #f2f6ff; }' +
        '.esc button:disabled { opacity: .5; cursor: default; }' +
        '.intro { padding: 16px; }' +
        '.intro p { margin: 0 0 12px; color: #444; }' +
        '.intro label { display: block; font-size: 12px; font-weight: 600; color: #333; margin: 10px 0 4px; }' +
        '.intro input { width: 100%; border: 1px solid #d3d7dd; border-radius: 10px; padding: 10px 11px; font: inherit; }' +
        '.intro input:focus { outline: none; border-color: ' + accent + '; }' +
        '.intro .start { margin-top: 16px; width: 100%; border: 0; background: ' + accent + '; color: #fff; border-radius: 10px; padding: 11px; cursor: pointer; font: 600 15px inherit; }' +
        '.err { color: #c0392b; font-size: 12px; margin-top: 8px; min-height: 14px; }' +
        '.notice { text-align: center; color: #666; font-size: 12px; padding: 8px; }';
    }

    function build() {
        var wrap = document.createElement('div');
        wrap.innerHTML =
            '<style>' + styles() + '</style>' +
            '<button class="launcher" part="launcher">' +
            '  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>' +
            '  <span class="launcher-text"></span>' +
            '</button>' +
            '<div class="panel">' +
            '  <div class="head"><span class="title"></span><button class="close" aria-label="Close">&times;</button></div>' +
            '  <div class="scroll"></div>' +
            '</div>';
        root.appendChild(wrap);
    }

    function el(sel) { return root.querySelector(sel); }

    function scrollDown() {
        var b = el('.body');
        if (b) { b.scrollTop = b.scrollHeight; }
    }

    function escBarHtml() {
        if (!cfg || !cfg.ai_enabled || (!cfg.ai_offer_agent && !cfg.ai_offer_email)) { return ''; }
        var b = '<div class="esc">';
        if (cfg.ai_offer_agent) { b += '<button class="esc-agent">Talk to a person</button>'; }
        if (cfg.ai_offer_email) { b += '<button class="esc-email">Email me back</button>'; }
        return b + '</div>';
    }

    function renderChatShell() {
        el('.scroll').innerHTML =
            '<div class="body"></div>' +
            escBarHtml() +
            '<div class="foot"><form>' +
            '  <textarea rows="1" placeholder="Type a message…" maxlength="5000"></textarea>' +
            '  <button type="submit" class="send">Send</button>' +
            '</form></div>';
        var form = el('.foot form');
        form.addEventListener('submit', onSend);
        var ta = el('.foot textarea');
        ta.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); onSend(e); }
        });
        var ea = el('.esc-agent'); if (ea) { ea.addEventListener('click', function () { onEscalate('agent'); }); }
        var ee = el('.esc-email'); if (ee) { ee.addEventListener('click', function () { onEscalate('email'); }); }
        ta.focus();
        if (cfg && cfg.greeting) {
            addMessage({ from: 'agent', name: cfg.name, body: cfg.greeting }, false);
        }
        // Out of hours — let the visitor know before they type.
        if (cfg && cfg.is_open === false && cfg.offline_message) {
            addMessage({ kind: 'system', body: cfg.offline_message }, false);
        }
    }

    function hideEscBar() {
        var bar = el('.esc');
        if (bar && bar.parentNode) { bar.parentNode.removeChild(bar); }
    }

    function renderIntro() {
        el('.scroll').innerHTML =
            '<div class="intro">' +
            '  <p>' + esc((cfg && cfg.greeting) || 'Hi! Tell us who you are and we\'ll get chatting.') + '</p>' +
            '  <label>Your name</label><input class="in-name" type="text" autocomplete="name">' +
            '  <label>Email</label><input class="in-email" type="email" autocomplete="email">' +
            '  <div class="err"></div>' +
            '  <button class="start">Start chat</button>' +
            '</div>';
        el('.start').addEventListener('click', onStart);
    }

    function addMessage(m, scroll) {
        var body = el('.body');
        if (!body) { return; }
        if (m.kind === 'system') {
            var n = document.createElement('div');
            n.className = 'notice';
            n.textContent = m.body;
            body.appendChild(n);
            if (scroll !== false) { scrollDown(); }
            return;
        }
        var row = document.createElement('div');
        row.className = 'msg ' + (m.from === 'agent' ? 'agent' : 'visitor');
        var who = (m.from === 'agent' && m.name) ? '<span class="who">' + esc(m.name) + '</span>' : '';
        row.innerHTML = '<div class="bubble">' + who + esc(m.body) + '</div>';
        body.appendChild(row);
        if (scroll !== false) { scrollDown(); }
    }

    function onEscalate(mode) {
        if (!token) { return; }
        var ea = el('.esc-agent'); var ee = el('.esc-email');
        if (ea) { ea.disabled = true; } if (ee) { ee.disabled = true; }
        fetch(api('escalate.php'), {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: KEY, token: token, mode: mode })
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.success) {
                if (ea) { ea.disabled = false; } if (ee) { ee.disabled = false; }
                addMessage({ kind: 'system', body: d.error || 'Could not do that just now.' });
                return;
            }
            hideEscBar();     // the choice has been made — clear the offer
            poll();           // pulls the server's confirmation note from the transcript
        }).catch(function () {
            if (ea) { ea.disabled = false; } if (ee) { ee.disabled = false; }
            addMessage({ kind: 'system', body: 'Network error — please try again.' });
        });
    }

    // ---- networking ------------------------------------------------------
    function loadConfig() {
        return fetch(api('config.php?key=' + encodeURIComponent(KEY)))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { throw new Error(d.error || 'config'); }
                cfg = d.config;
                accent = cfg.accent || accent;
                return cfg;
            });
    }

    function onStart(e) {
        if (e) { e.preventDefault(); }
        var name = el('.in-name') ? el('.in-name').value.trim() : '';
        var email = el('.in-email') ? el('.in-email').value.trim() : '';
        var errEl = el('.err');
        var btn = el('.start');
        if (btn) { btn.disabled = true; }
        fetch(api('start.php'), {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: KEY, name: name, email: email })
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.success) {
                if (errEl) { errEl.textContent = d.error || 'Could not start the chat.'; }
                if (btn) { btn.disabled = false; }
                return;
            }
            token = d.token;
            try { sessionStorage.setItem(STORE_KEY, token); } catch (e2) {}
            started = true;
            renderChatShell();
            startPolling();
        }).catch(function () {
            if (errEl) { errEl.textContent = 'Network error — please try again.'; }
            if (btn) { btn.disabled = false; }
        });
    }

    function onSend(e) {
        if (e) { e.preventDefault(); }
        var ta = el('.foot textarea');
        if (!ta) { return; }
        var text = ta.value.trim();
        if (!text || !token) { return; }
        ta.value = '';
        var btn = el('.foot .send');
        if (btn) { btn.disabled = true; }
        fetch(api('send.php'), {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: KEY, token: token, message: text })
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (btn) { btn.disabled = false; }
            if (!d.success) {
                addMessage({ from: 'agent', name: '', body: d.error || 'Message could not be sent.' });
                return;
            }
            if (d.notice) { addMessage({ kind: 'system', body: d.notice }); }
            poll(); // pull the just-stored message(s) straight back so they show immediately
        }).catch(function () {
            if (btn) { btn.disabled = false; }
            addMessage({ from: 'agent', name: '', body: 'Network error — message not sent.' });
        });
    }

    function poll() {
        // Guard against overlapping polls: the immediate poll() fired after a send or an
        // escalation can otherwise race the 3s interval poll, and both append the same new
        // messages (they share one `after` cursor) — which showed as duplicate bubbles.
        if (!token || pollInFlight) { return; }
        pollInFlight = true;
        fetch(api('poll.php?key=' + encodeURIComponent(KEY) + '&token=' + encodeURIComponent(token) + '&after=' + lastId))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                pollInFlight = false;
                if (!d.success) { return; }
                (d.messages || []).forEach(function (m) { addMessage(m); });
                if (typeof d.last_id === 'number') { lastId = d.last_id; }
                if (d.closed && !closed) {
                    closed = true;
                    var body = el('.body');
                    if (body) {
                        var n = document.createElement('div');
                        n.className = 'notice';
                        n.textContent = 'This conversation has been closed.';
                        body.appendChild(n);
                        scrollDown();
                    }
                    var ta = el('.foot textarea'); if (ta) { ta.disabled = true; }
                    var sb = el('.foot .send'); if (sb) { sb.disabled = true; }
                    stopPolling();
                }
            }).catch(function () { pollInFlight = false; /* transient */ });
    }

    function startPolling() {
        poll();
        stopPolling();
        pollTimer = setInterval(poll, 3000);
    }
    function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    // ---- open / close ----------------------------------------------------
    function openPanel() {
        opened = true;
        el('.launcher').style.display = 'none';
        el('.panel').classList.add('open');
        el('.title').textContent = (cfg && cfg.name) || 'Chat';
        if (token) {
            started = true;
            renderChatShell();
            startPolling();
        } else if (cfg && cfg.require_email) {
            renderIntro();
        } else {
            // No identity needed — open a conversation straight away.
            onStart();
        }
    }

    function closePanel() {
        opened = false;
        el('.panel').classList.remove('open');
        el('.launcher').style.display = '';
        stopPolling();
    }

    // ---- boot ------------------------------------------------------------
    loadConfig().then(function () {
        build();
        el('.launcher-text').textContent = (cfg && cfg.launcher_text) ? cfg.launcher_text : 'Chat';
        el('.launcher').addEventListener('click', openPanel);
        el('.close').addEventListener('click', closePanel);
    }).catch(function () {
        // Config failed (bad key / disallowed origin) — render nothing at all.
    });
})();
