/**
 * Live updates (GitHub #267) - ONE heartbeat for the whole page.
 *
 * Each part of a page says what it shows and is told when that changed:
 *
 *   const stop = LivePulse.watch('ticket:42', (topic, info) => { ... });
 *   stop();   // when it is no longer on screen
 *
 * Topics: 'tickets', 'ticket:N', 'tasks', 'task:N', 'notifications' - see
 * includes/live_pulse.php, which answers api/system/pulse.php with a hash per topic.
 * The first answer for a topic is its baseline; the callback runs when the hash moves.
 *
 * How it keeps the page joined up without getting in the way:
 *  - one request for everything on screen, every 15 seconds (Preferences → Live updates:
 *    15, 30, 60 seconds or off), and none at all while the tab is in the background;
 *  - coming back to the tab, or to the window, checks straight away;
 *  - a save in one tab pokes your other tabs, so they catch up within a second;
 *  - info.mine is true when this page itself saved something since the last check: the
 *    part that saved has usually redrawn already, so it can skip a second redraw.
 *
 * Callers own the "never wipe what somebody is doing" rule - LivePulse.busyChip() shows
 * the shared "Updated just now · Refresh" prompt when they cannot redraw.
 *
 * @wiki Live-Updates
 */
(function () {
    if (window.LivePulse) return;
    const cfg = window.FREEITSM_LIVE || {};
    const seconds = Number.isFinite(cfg.seconds) ? cfg.seconds : 15;
    const url = (cfg.url || '') + 'api/system/pulse.php';
    const watchers = new Map();   // topic -> Set(callback)
    const sigs = new Map();       // topic -> last hash seen (undefined = not yet baselined)
    let timer = null, inFlight = false, again = false, soonTimer = null;
    let wroteSince = false;       // this page saved something since the last check

    const T = (k, fb) => (typeof window.t === 'function' ? window.t(k) : null) || fb;

    async function pulse() {
        if (seconds <= 0 || !watchers.size || document.hidden) return;   // off means off
        if (inFlight) { again = true; return; }
        inFlight = true;
        const mine = wroteSince;
        wroteSince = false;
        try {
            const topics = [...watchers.keys()];
            const r = await fetch(url + '?topics=' + encodeURIComponent(topics.join(',')), { credentials: 'same-origin' });
            const d = await r.json();
            if (d && d.success && d.sig) {
                for (const topic of topics) {
                    if (!(topic in d.sig)) continue;
                    const now = d.sig[topic];
                    const before = sigs.get(topic);
                    sigs.set(topic, now);
                    if (before === undefined || before === now || now === null) continue;
                    for (const cb of [...(watchers.get(topic) || [])]) {
                        try { cb(topic, { mine }); } catch (e) { console.error('[LivePulse]', topic, e); }
                    }
                }
            }
        } catch (e) { /* offline or signed out: the next beat tries again */ }
        inFlight = false;
        if (again) { again = false; soon(400); }
    }

    /** Check shortly - collapses a burst of reasons into one request. */
    function soon(ms) {
        clearTimeout(soonTimer);
        soonTimer = setTimeout(pulse, ms);
    }

    function start() {
        if (timer || seconds <= 0) return;
        timer = setInterval(pulse, seconds * 1000);
    }

    /**
     * Watch a topic. Returns a function that stops watching. A new topic is baselined at
     * once, so a change right after the part was drawn is not missed.
     */
    function watch(topic, cb) {
        if (!topic || typeof cb !== 'function') return () => {};
        if (!watchers.has(topic)) { watchers.set(topic, new Set()); sigs.delete(topic); soon(300); }
        watchers.get(topic).add(cb);
        start();
        return () => unwatch(topic, cb);
    }

    function unwatch(topic, cb) {
        const set = watchers.get(topic);
        if (!set) return;
        set.delete(cb);
        if (!set.size) { watchers.delete(topic); sigs.delete(topic); }
    }

    /** After a part redrew itself from fresh data: adopt the current hash without firing. */
    async function rebaseline(topic) {
        sigs.delete(topic);
        soon(300);
    }

    // Visible again, or the window came back to the front: catch up now.
    document.addEventListener('visibilitychange', () => { if (!document.hidden) soon(150); });
    window.addEventListener('focus', () => soon(150));

    // A save anywhere on this page - a same-origin write to /api/ - marks the next check as
    // "mine" and pokes this analyst's other tabs. Wraps fetch once, after any wrapper the
    // page installed first (the ticket workspace counts writes the same way).
    const channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel('freeitsm-live') : null;
    if (channel) channel.onmessage = (e) => { if (e.data === 'poke') soon(700); };
    const BACKGROUND_WRITES = /\/(pulse|ticket_presence|check_mailbox_email|scheduler_fallback|recent_trail_visit|set_user_preference|mark_read|clear)\.php$/;
    const realFetch = window.fetch;
    window.fetch = function (input, init) {
        const method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        const p = realFetch.apply(this, arguments);
        if (method !== 'GET' && method !== 'HEAD') {
            let target = '';
            try { target = new URL(typeof input === 'string' ? input : input.url, location.href).pathname; } catch (e) { /* ignore */ }
            // TRAP: housekeeping writes are not "you changed something". The presence
            // heartbeat posts every 10 seconds, so counting it made every check "mine" and
            // the open ticket never followed anybody else's change. A new background
            // writer belongs in this list.
            if (target.includes('/api/') && !BACKGROUND_WRITES.test(target)) {
                p.then(res => {
                    if (!res || !res.ok) return;
                    wroteSince = true;
                    if (channel) channel.postMessage('poke');
                    soon(1200);   // others' screens settle on this; ours learns it was mine
                }).catch(() => {});
            }
        }
        return p;
    };

    /**
     * The shared prompt for "this changed, but you are in the middle of something": a small
     * pill at the top of `host` with Refresh. One per host; calling again just keeps it.
     *
     * @param {HTMLElement} host  where it goes (prepended)
     * @param {Function} onRefresh  what Refresh does; the pill removes itself first
     */
    function busyChip(host, onRefresh) {
        if (!host) return;
        if (host.querySelector(':scope > .live-pulse-chip')) return;
        const chip = document.createElement('div');
        chip.className = 'live-pulse-chip';
        chip.setAttribute('role', 'status');
        chip.style.cssText = 'display:flex;align-items:center;gap:10px;margin:6px 0 8px;padding:6px 12px;border-radius:999px;'
            + 'background:rgba(37,99,235,.10);color:var(--text,#1e293b);font-size:13px;width:fit-content;max-width:100%;';
        const msg = document.createElement('span');
        msg.textContent = T('common.live.updated', 'Updated by someone else just now.');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-primary';
        btn.style.cssText = 'padding:3px 12px;font-size:12px;';
        btn.textContent = T('common.live.refresh', 'Refresh');
        btn.onclick = () => { chip.remove(); onRefresh(); };
        chip.append(msg, btn);
        host.prepend(chip);
    }
    function clearChip(host) { host?.querySelector(':scope > .live-pulse-chip')?.remove(); }

    window.LivePulse = {
        watch, unwatch, rebaseline, busyChip, clearChip,
        now: () => soon(50),
        enabled: () => seconds > 0,
        seconds: () => seconds,
    };
})();
