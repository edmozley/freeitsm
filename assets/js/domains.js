/**
 * Domains module (#154) — helpers every Domains page shares.
 *
 * window.Dom: api(), esc(), and the little renderers (grade badge, countdown
 * pill, status, locks, a check finding) so the register, the domain page, the
 * dashboard and the table view draw a domain the same way.
 *
 * Every URL is built from window.DOM_API (set by the page from BASE_URL) —
 * never a root-relative "/api/…", which 404s on an install in a sub-directory.
 *
 * @wiki Domains-Developer-Guide
 */
(function () {
    'use strict';

    /** Translates a key from the 'domains' namespace, or returns the key when no translator is loaded. */
    const T = (k, p) => (window.t ? window.t('domains.' + k, p) : k);

    /** HTML-escapes a value; null and undefined become an empty string. */
    function esc(v) {
        if (v === null || v === undefined) return '';
        return String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    /**
     * Calls a Domains endpoint under window.DOM_API - a GET, or a JSON POST when a body is given.
     *
     * @return the decoded reply; throws a translated error on a network fault, a bad reply or success false
     */
    async function api(path, body) {
        const opts = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
        let res;
        try {
            res = await fetch(window.DOM_API + path, opts);
        } catch (e) {
            throw new Error(T('err.network'));
        }
        let data;
        try { data = await res.json(); } catch (e) { throw new Error(T('err.bad_response')); }
        if (!data.success) throw new Error(data.error || T('err.generic'));
        return data;
    }

    /** Formats a stored date as a calendar date in the analyst's format, never shifted by time zone. */
    function fmtDate(d) {
        if (!d) return '';
        return window.fmtNaiveDate ? window.fmtNaiveDate(String(d).slice(0, 10) + 'T00:00:00') : String(d).slice(0, 10);
    }

    /** Draws the security grade badge (a dash when not graded); big for the domain page's hero. */
    function grade(g, big) {
        if (!g) return '<span class="dom-grade' + (big ? ' big' : '') + '" title="' + esc(T('grade.none')) + '">–</span>';
        const cls = 'g-' + g.replace('+', 'plus');
        return '<span class="dom-grade ' + cls + (big ? ' big' : '') + '" title="' + esc(T('grade.title', { grade: g })) + '">' + esc(g) + '</span>';
    }

    /**
     * An internal domain's health badge (GH #203), in the grade's place: Healthy,
     * Problem, Not checked or Stale. When the state is not the last known result -
     * stale, or checks since have not completed - the tooltip says what was last
     * known and when, so a known problem never hides behind "Not checked".
     *
     * @param {object} h     the row's health: state, known, last_completed, last_healthy
     * @param {boolean} big  the domain page's hero size
     */
    function health(h, big) {
        h = h || { state: 'not_checked' };
        const cls = { healthy: 'green', problem: 'red', not_checked: 'grey', stale: 'amber' }[h.state] || 'grey';
        let tip = T('health.title', { state: T('health.' + h.state) });
        if (h.known && h.known !== h.state) tip += ' - ' + T('health.known_after', { state: T('health.' + h.known), date: fmtDate(h.last_completed) });
        return '<span class="dom-pill dom-health ' + cls + (big ? ' big' : '') + '" title="' + esc(tip) + '">' + esc(T('health.' + h.state)) + '</span>';
    }

    /** The grade for a public domain, the health badge for an internal one (GH #203). */
    function gradeOrHealth(r, big) {
        return r && r.scope === 'internal' ? health(r.health, big) : grade(r ? r.security_grade : null, big);
    }

    /** "in 12 days" / "expired 3 days ago", coloured by urgency. */
    function daysPill(days, warnAt) {
        if (days === null || days === undefined) return '<span class="dom-pill grey">' + esc(T('days.unknown')) + '</span>';
        warnAt = warnAt || 30;
        let cls = 'green';
        if (days < 0) cls = 'red'; else if (days <= 7) cls = 'red'; else if (days <= warnAt) cls = 'amber'; else if (days <= 90) cls = 'blue';
        let label;
        if (days < 0) label = T('days.expired_ago', { n: -days });
        else if (days === 0) label = T('days.today');
        else if (days === 1) label = T('days.tomorrow');
        else if (days > 730) label = T('days.years', { n: Math.round(days / 365) });
        else label = T('days.in', { n: days });
        return '<span class="dom-pill ' + cls + '">' + esc(label) + '</span>';
    }

    /** Draws a domain status with its coloured dot, or "no status". */
    function status(name, colour) {
        if (!name) return '<span class="dom-status"><span class="dot"></span>' + esc(T('status.none')) + '</span>';
        return '<span class="dom-status"><span class="dot" style="background:' + esc(colour || '#94a3b8') + '"></span>' + esc(name) + '</span>';
    }

    const ICON_LOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
    const ICON_UNLOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/></svg>';
    const ICON_SHIELD = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';
    const ICON_KEY = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/></svg>';

    /** Draws one lock icon as on, off or unknown, with a tooltip naming the lock and its state. */
    function lockIcon(state, icon, labelKey) {
        const cls = state === true ? 'on' : (state === false ? 'off' : 'unknown');
        const word = state === true ? T('lock.on') : (state === false ? T('lock.off') : T('lock.unknown'));
        return '<span class="dom-lock ' + cls + '" title="' + esc(T(labelKey) + ': ' + word) + '">' + icon + '</span>';
    }

    /** Transfer lock, registry lock, DNSSEC — three glances. */
    function locks(r) {
        return '<span class="dom-locks">'
            + lockIcon(r.transfer_lock, r.transfer_lock === false ? ICON_UNLOCK : ICON_LOCK, 'field.transfer_lock')
            // No registry lock is normal (most domains have none), so it greys out rather than going red.
            + lockIcon(r.registry_lock === true ? true : null, ICON_SHIELD, 'field.registry_lock')
            + lockIcon(r.dnssec, ICON_KEY, 'field.dnssec')
            + '</span>';
    }

    /** Returns the translated name of a domain purpose. */
    function purpose(p) { return T('purpose.' + p); }
    /** Returns the translated name of a renewal mode. */
    function renewal(m) { return T('renewal.' + m); }

    /** Draws a comma-separated tag list as tag chips. */
    function tags(s) {
        if (!s) return '';
        return String(s).split(',').map(x => x.trim()).filter(Boolean).map(x => '<span class="dom-tag">' + esc(x) + '</span>').join('');
    }

    /**
     * One check finding. Its words come from lang/…/domains.php
     * (check.<key>.title / .advice) with the finding's params filled in; the
     * stored JSON carries no English, so a German analyst reads German.
     */
    function finding(f) {
        const p = Object.assign({}, f.params || {});
        Object.keys(p).forEach(k => { p[k] = esc(p[k]); });
        const icon = { pass: '✓', info: 'i', warn: '!', fail: '✕' }[f.level] || '?';
        let advice = window.t ? window.t('domains.check.' + f.key + '.advice', p) : '';
        if (advice === 'domains.check.' + f.key + '.advice') advice = '';
        let title = window.t ? window.t('domains.check.' + f.key + '.title', p) : f.key;
        if (title === 'domains.check.' + f.key + '.title') title = f.key;
        // Advice strings may carry `backticks` for records to copy — render as code.
        advice = advice.replace(/`([^`]+)`/g, '<code>$1</code>');
        return '<div class="dom-finding ' + esc(f.level) + '"><span class="ic">' + icon + '</span><div style="flex:1;min-width:0">'
            + '<div class="t">' + title + '</div>' + (advice ? '<div class="a">' + advice + '</div>' : '') + '</div>'
            + (f.weight ? '<span class="pts">−' + esc(f.weight) + '</span>' : '') + '</div>';
    }

    /** Formats an amount in a currency (the install's default when none is given); blank for no amount. */
    function money(v, cur) {
        if (v === null || v === undefined || v === '') return '';
        // System -> Currencies and the viewer's own format (GH #189), on pages that load money.js.
        if (window.Money) return window.Money.format(v, cur);
        try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur || 'GBP' }).format(v); }
        catch (e) { return (cur ? cur + ' ' : '') + Number(v).toFixed(2); }
    }

    /**
     * The no-cron fallback: ask the server to do a short scheduled batch. Fire
     * and forget — the server decides whether an hour has passed, and the page
     * never waits on it.
     */
    function tick() {
        try { fetch(window.DOM_API + 'tick.php', { method: 'POST', keepalive: true }).catch(() => {}); } catch (e) {}
    }

    /** Opens a dialog by id. */
    function openModal(id) { const m = document.getElementById(id); if (m) m.classList.add('active'); }
    /** Closes a dialog by id. */
    function closeModal(id) { const m = document.getElementById(id); if (m) m.classList.remove('active'); }

    /** Fill a <select> from a list of {id,name}. */
    function fillSelect(sel, list, opts) {
        opts = opts || {};
        const el = typeof sel === 'string' ? document.getElementById(sel) : sel;
        if (!el) return;
        const keep = el.value;
        let html = opts.blank !== undefined ? '<option value="">' + esc(opts.blank) + '</option>' : '';
        (list || []).forEach(o => {
            const v = typeof o === 'object' ? o.id : o;
            const l = typeof o === 'object' ? (o.name || o.account_name) : (opts.label ? opts.label(o) : o);
            html += '<option value="' + esc(v) + '">' + esc(l) + '</option>';
        });
        el.innerHTML = html;
        if (keep !== '' && [...el.options].some(o => o.value === keep)) el.value = keep;
    }

    window.Dom = { T, esc, api, fmtDate, grade, health, gradeOrHealth, daysPill, status, locks, purpose, renewal, tags, finding, money, tick, openModal, closeModal, fillSelect };
})();
