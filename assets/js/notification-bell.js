/**
 * The notification bell - ONE script for both the analyst app (discussion #55)
 * and the self-service portal (discussion #62).
 *
 *   NotificationBell.init({
 *       list:       URL returning { unread, notifications } (and just { unread }
 *                   with count_only=1),
 *       markRead:   URL taking { ids } or { all },
 *       clear:      URL taking { ids } or { all, include_unread },
 *       linkPrefix: prepended to each notification's relative link,
 *       seenKey:    sessionStorage key for the chime's baseline
 *   });
 *
 * The markup comes from notificationBellMarkup() (includes/notification_bell.php)
 * and the look from assets/css/notification-bell.css. Everything below moved
 * here from includes/waffle-menu.php unchanged apart from the URLs, so the two
 * bells cannot drift: a fix to one is a fix to both.
 *
 * Needs window.t (i18n.js) for the common.notifications.* strings, and uses
 * showConfirm / showToast when present.
 *
 * @wiki Notifications-Developer-Guide
 */
window.NotificationBell = {
    /** Wires up the bell on this page - badge, panel, mark-read, clear and the 60-second poll - from the URLs in cfg. */
    init: function (cfg) {
        // Where to ask. The analyst bell and the portal bell differ ONLY here.
        const LIST   = cfg.list;
        const COUNT  = cfg.list + (cfg.list.indexOf('?') === -1 ? '?' : '&') + 'count_only=1';
        const MARK   = cfg.markRead;
        const CLEAR  = cfg.clear;
        const EXPLAIN = cfg.explain || null;   // the (i) panel, analyst bell only (5.8.0)
        const PREFIX = cfg.linkPrefix || '';
        const btn   = document.getElementById('nbBtn');
        const panel = document.getElementById('nbPanel');
        const badge = document.getElementById('nbCount');
        const list  = document.getElementById('nbList');
        if (!btn) return;

        /** Escapes a value for safe use in HTML. */
        const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
            ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));

        // Stored UTC without a zone marker; left as-is, Safari and Firefox read it
        // as local time and every notification looks hours old.
        function ago(utc) {
            if (!utc) return '';
            const then = new Date(String(utc).replace(' ', 'T') + 'Z');
            if (isNaN(then)) return '';
            const mins = Math.floor((Date.now() - then.getTime()) / 60000);
            if (mins < 1)  return window.t('common.notifications.just_now');
            if (mins < 60) return window.t('common.notifications.minutes', { n: mins });
            const hrs = Math.floor(mins / 60);
            if (hrs < 24)  return window.t('common.notifications.hours', { n: hrs });
            return window.t('common.notifications.days', { n: Math.floor(hrs / 24) });
        }

        /**
         * Who to name after "by" (GH #365). A workflow names itself - "the workflow
         * 'Route Company A'" - and FreeITSM acting on its own (mail arriving, a scheduled
         * job) is said so, instead of whoever happened to be signed in. Rows from before
         * 5.8.0 have no kind and show the name they kept.
         */
        function actorLabel(n) {
            const tt = (k, p) => { const v = window.t('common.notifications.' + k, p); return v === 'common.notifications.' + k ? '' : v; };
            if (n.actor_kind === 'workflow') return tt('actor_workflow', { name: n.actor_name || '' }) || n.actor_name || '';
            if (n.actor_kind === 'system') return n.source === 'mail_import' ? (tt('actor_email') || 'email') : (tt('actor_system') || 'FreeITSM');
            return n.actor_name || window.t('common.notifications.someone');
        }

        /** The notification's one-line description in the viewer's language, falling back to the actor's name when no string exists for the event type. */
        function describe(n) {
            // Translated at render time, never baked into the row — otherwise a
            // notification written today reads forever in whoever wrote it's language.
            // What it changed TO, where the row carries it (a task's new status or due
            // date, as {"to": ...}). Without it - older rows - the plain wording is used.
            let detail = null;
            if (n.body && n.body.charAt(0) === '{') {
                try { detail = JSON.parse(n.body); } catch (e) { detail = null; }
            }
            let to = detail && detail.to ? String(detail.to) : '';
            if (to && detail.kind === 'date') {
                const d = new Date(to + 'T00:00:00');
                if (!isNaN(d)) to = d.toLocaleDateString();
            }
            const base = 'common.notifications.event.' + n.event_type;
            const key = to || !/(status_changed|due_date_changed)$/.test(n.event_type) ? base : base + '_plain';
            const txt = window.t(key, { actor: actorLabel(n), to: to });
            return txt === key ? actorLabel(n) : txt;
        }

        /** Draws the notification rows into the panel, or the empty message when there are none. */
        function render(items) {
            if (!items.length) {
                list.innerHTML = '<div class="nb-empty">' + esc(window.t('common.notifications.empty')) + '</div>';
                return;
            }
            list.innerHTML = items.map(n => {
                const count = n.event_count > 1
                    ? '<span class="nb-badge-count">' + n.event_count + '</span>' : '';
                const ref = n.entity_ref ? esc(n.entity_ref) : '';
                return `<a class="nb-item${n.is_read ? '' : ' unread'}" href="${n.link ? esc(PREFIX + n.link) : '#'}"
                           data-id="${n.id}">
                    <div class="nb-item-top">
                        <span class="nb-item-ref">${ref}${count}</span>
                        ${EXPLAIN ? `<span class="nb-info" role="button" tabindex="0" aria-expanded="false"
                              title="${esc(window.t('common.notifications.explain.info'))}"
                              aria-label="${esc(window.t('common.notifications.explain.info'))}">i</span>` : ''}
                        <span class="nb-clear" role="button" tabindex="0"
                              title="${esc(window.t('common.notifications.clear_one'))}"
                              aria-label="${esc(window.t('common.notifications.clear_one'))}"><svg viewBox="0 0 10 10" width="8" height="8" aria-hidden="true" focusable="false"><path d="M1.5 1.5l7 7M8.5 1.5l-7 7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
                    </div>
                    <div class="nb-item-title">${esc(n.title || '')}</div>
                    <div class="nb-item-bottom">
                        <span class="nb-item-body">${esc(describe(n))}</span>
                        <span class="nb-item-meta">${esc(ago(n.updated_datetime))}</span>
                    </div>
                </a>`;
            }).join('');
        }

        let lastUnread = 0;
        /** Shows the unread count on the bell (capped at 99+) and hides the badge at zero. */
        function paintBadge(unread) {
            lastUnread = unread;
            badge.textContent = unread > 99 ? '99+' : String(unread);
            badge.hidden = unread === 0;
        }

        // ===== Chime (preference notification_sound, off by default) =====
        // The unread count is normally non-zero when a page loads, so the count
        // alone cannot say "something new arrived" — sounding on it would chime
        // on every navigation for notifications you were told about yesterday.
        // The last count seen is therefore kept per tab, and the first poll of a
        // tab only records a baseline. Private-mode browsers throw on
        // sessionStorage, which costs nothing worse than a silent chime.
        const SEEN_KEY = cfg.seenKey || 'nbSeenUnread';
        /** Stores the unread count as this tab's baseline for the chime. */
        function recordSeen(unread) {
            try { sessionStorage.setItem(SEEN_KEY, String(unread)); } catch (e) { /* ignore */ }
        }
        /** Plays the chime and raises desktop notifications when the unread count has risen since the last poll in this tab. */
        function chimeIfNew(unread) {
            let prev = null;
            try { prev = sessionStorage.getItem(SEEN_KEY); } catch (e) { /* ignore */ }
            recordSeen(unread);
            if (prev === null) return;                      // first poll in this tab
            if (unread > parseInt(prev, 10)) {
                if (typeof window.playNotificationSound === 'function') window.playNotificationSound();
                desktopNotify();
            }
        }

        // Desktop notifications (2.10.0, Preferences -> Notifications). In Edge
        // on Windows these are Windows notifications. They come from this page,
        // so they arrive while FreeITSM is open in a tab - minimised or behind
        // other windows is fine, closed is not.
        //
        // "What is new" is kept in localStorage, which every tab shares, as the
        // newest updated_datetime already shown: three open tabs must not raise
        // the same notification three times. The tag is a second guard - the
        // browser replaces a notification that carries a tag it already shows.
        // The very first run only records where things stand, or switching the
        // setting on would replay every unread notification at once.
        const DESK_KEY = 'nbDesktopShownUntil';
        /** Raises desktop notifications for unread items newer than any already shown, at most three plus a "more" line; the first run only records a baseline. */
        async function desktopNotify() {
            if (!window.DESKTOP_NOTIFICATIONS || !('Notification' in window) || Notification.permission !== 'granted') return;
            let items;
            try {
                const d = await (await fetch(LIST, { credentials: 'same-origin' })).json();
                if (!d.success) return;
                items = (d.notifications || []).filter(n => !n.is_read);
            } catch (e) { return; }
            let shownUntil = null;
            try { shownUntil = localStorage.getItem(DESK_KEY); } catch (e) { /* private mode */ }
            const newest = items.reduce((m, n) => String(n.updated_datetime || '') > m ? String(n.updated_datetime) : m, shownUntil || '');
            try { localStorage.setItem(DESK_KEY, newest); } catch (e) { /* ignore */ }
            if (shownUntil === null) return;                    // first run: baseline only
            const fresh = items.filter(n => String(n.updated_datetime || '') > shownUntil);
            if (!fresh.length) return;

            const iconEl = document.querySelector('link[rel="icon"]');
            const icon   = iconEl ? iconEl.href : undefined;
            // A handful at most; past that, one line saying how many more.
            fresh.slice(0, 3).forEach(n => {
                try {
                    const title = (n.entity_ref ? n.entity_ref + ' - ' : '') + (n.title || '');
                    const note = new Notification(title || describe(n), {
                        body: describe(n),
                        icon: icon,
                        tag: 'freeitsm-nb-' + n.id + '-' + n.updated_datetime
                    });
                    /** Clicking the desktop notification focuses the tab and opens what it was about. */
                    note.onclick = function () {
                        window.focus();
                        if (n.link) location.href = PREFIX + n.link;
                        note.close();
                    };
                } catch (e) { /* a browser refusing is not worth an error on screen */ }
            });
            if (fresh.length > 3) {
                try {
                    const more = new Notification(window.t('common.notifications.desktop_more', { n: fresh.length - 3 }), {
                        icon: icon, tag: 'freeitsm-nb-more'
                    });
                    /** Clicking the "more" notification focuses the tab and opens the bell panel. */
                    more.onclick = function () { window.focus(); open(); more.close(); };
                } catch (e) { /* ignore */ }
            }
        }
        // Record the starting point as soon as the page loads, so the first
        // thing that arrives afterwards is shown rather than taken as the baseline.
        desktopNotify();

        /** Fetches the unread count only, repaints the badge and chimes if something new arrived. */
        async function poll() {
            try {
                const d = await (await fetch(COUNT, { credentials: 'same-origin' })).json();
                if (d.success) { paintBadge(d.unread); chimeIfNew(d.unread); }
                // #233: the scheduler's fallback, analyst bell only. The server says when
                // it is due; starting it is a POST, and the server claims the minute so
                // many open tabs start it once.
                if (d.scheduler_due && cfg.schedulerTick) {
                    fetch(cfg.schedulerTick, { method: 'POST', credentials: 'same-origin' }).catch(function () {});
                }
            } catch (e) { /* a failed poll is not worth telling anyone about */ }
        }

        /** Opens the panel and loads the full list, re-baselining the chime because the viewer is now looking at it. */
        async function open() {
            panel.classList.add('open');
            list.innerHTML = '<div class="nb-empty">' + esc(window.t('common.notifications.loading')) + '</div>';
            try {
                const d = await (await fetch(LIST, { credentials: 'same-origin' })).json();
                // Opening the panel re-baselines rather than chiming: you are
                // looking straight at the list, so anything in it is not news.
                if (d.success) { render(d.notifications || []); paintBadge(d.unread); recordSeen(d.unread); }
            } catch (e) {
                list.innerHTML = '<div class="nb-empty">' + esc(window.t('common.notifications.load_failed')) + '</div>';
            }
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.classList.contains('open') ? panel.classList.remove('open') : open();
        });
        document.addEventListener('click', function (e) {
            if (!panel.contains(e.target) && !btn.contains(e.target)) panel.classList.remove('open');
        });

        // Follow the link AND mark read. Not awaited: making somebody wait on a
        // bookkeeping write before their ticket opens would be the wrong trade.
        list.addEventListener('click', function (e) {
            const item = e.target.closest('.nb-item');
            if (!item) return;
            const id = parseInt(item.dataset.id, 10);

            // The clear button sits INSIDE the row's anchor, so without stopping
            // the event here, clearing a notification would also navigate to the
            // ticket it was about.
            if (e.target.closest('.nb-clear')) {
                e.preventDefault();
                e.stopPropagation();
                if (id) clearOne(id, item);
                return;
            }
            if (e.target.closest('.nb-info')) {
                e.preventDefault();
                e.stopPropagation();
                if (id) toggleExplain(id, item);
                return;
            }

            if (id) {
                fetch(MARK, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: [id] }), keepalive: true
                }).catch(() => {});
            }
        });

        // role="button" is a promise that Enter and Space work. The span is inside
        // an anchor, so Enter would otherwise follow the link instead.
        list.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const info = e.target.closest('.nb-info');
            if (info) {
                const row = info.closest('.nb-item');
                e.preventDefault();
                e.stopPropagation();
                if (row) toggleExplain(parseInt(row.dataset.id, 10), row);
                return;
            }
            const btn = e.target.closest('.nb-clear');
            if (!btn) return;
            const item = btn.closest('.nb-item');
            const id = item && parseInt(item.dataset.id, 10);
            e.preventDefault();
            e.stopPropagation();
            if (id) clearOne(id, item);
        });

        /**
         * "Why is this here?" (5.8.0): what made the change, how, why you were told, and the
         * ticket's own history line beside it - the same answer debug tool D021 gives, from
         * the same code (includes/notification_explain.php). Opens under the row, outside
         * its link, so clicking inside never navigates. Built with textContent only.
         */
        async function toggleExplain(id, item) {
            const open = item.nextElementSibling && item.nextElementSibling.classList.contains('nb-explain') ? item.nextElementSibling : null;
            const btn = item.querySelector('.nb-info');
            if (open) { open.remove(); if (btn) btn.setAttribute('aria-expanded', 'false'); return; }
            const box = document.createElement('div');
            box.className = 'nb-explain';
            const X = (k, p) => window.t('common.notifications.explain.' + k, p);
            box.textContent = X('loading');
            item.after(box);
            if (btn) btn.setAttribute('aria-expanded', 'true');
            let x;
            try {
                const d = await (await fetch(EXPLAIN + (EXPLAIN.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(id), { credentials: 'same-origin' })).json();
                if (!d.success) throw new Error();
                x = d.explain;
            } catch (e) {
                box.textContent = X('failed');
                return;
            }
            box.textContent = '';
            const when = s => { const d = new Date(s); return isNaN(d) ? s : d.toLocaleString(); };
            const row = (label, value, cls) => {
                const r = document.createElement('div'); r.className = 'nb-ex-row' + (cls ? ' ' + cls : '');
                const l = document.createElement('span'); l.className = 'nb-ex-label'; l.textContent = label;
                const v = document.createElement('span'); v.className = 'nb-ex-value'; v.textContent = value;
                r.append(l, v); box.appendChild(r); return r;
            };
            const h = document.createElement('div'); h.className = 'nb-ex-title'; h.textContent = X('title'); box.appendChild(h);

            // Who or what did it.
            const a = x.actor || {};
            let who = actorLabel({ actor_kind: a.kind, actor_name: a.name, source: a.source });
            if (a.kind === 'workflow' || a.kind === 'system') who += ' (' + X('auto') + ')';
            if (a.via_api) who += ' - ' + X('via_api');
            row(X('by'), who);
            if (a.source) row(X('how'), X('source.' + a.source));
            row(X('when'), when(x.time));
            row(X('why'), X('reason.' + (x.reason || 'unknown')));

            // The ticket's own record, and whether it agrees.
            const hist = x.history;
            const byWho = hh => hh.by_kind === 'system' ? X('by_system') : hh.by_kind === 'former' ? X('by_former') : (hh.by_name || '');
            if (hist) {
                const change = hist.kind === 'audit'
                    ? hist.field + ': ' + (hist.old || '-') + ' \u2192 ' + (hist.new || '-')
                    : X('hist_' + hist.kind);
                row(X('history'), change + ', ' + X('hist_by', { who: byWho(hist) }) + ' (' + when(hist.time) + ')');
                const auto = a.kind === 'workflow' || a.kind === 'system';
                const agrees = hist.by_kind === 'system' ? (auto || !a.kind && !a.name)
                             : hist.by_kind === 'analyst' ? (!auto && (a.name || '').toLowerCase() === (hist.by_name || '').toLowerCase())
                             : true;
                const v = row('', agrees ? '\u2713 ' + X('agrees') : '\u26A0 ' + X('differs', { who: byWho(hist) }), agrees ? 'nb-ex-ok' : 'nb-ex-warn');
                v.querySelector('.nb-ex-label').remove();
            } else if (x.history_note && x.history_note !== 'not_ticket') {
                row(X('history'), X('hist_notes.' + x.history_note));
            }
            if (x.workflow_runs && x.workflow_runs.length) {
                row(X('workflows'), x.workflow_runs.map(r => '"' + r.name + '" ' + when(r.time)).join('; '));
            }
            const note = t => { const p = document.createElement('div'); p.className = 'nb-ex-note'; p.textContent = t; box.appendChild(p); };
            if (x.grouped) note(X('grouped', { n: x.event_count }));
            if (!x.recorded) note(X('old_row'));
        }
        // Awaited, unlike mark-read: this one removes something from the screen,
        // so a row must not vanish before the server has agreed that it is gone.
        async function clearOne(id, item) {
            // Asks first, for the same reason Clear all does: the delete is real
            // and there is no undo. The X sits a few pixels from the row itself,
            // so a mis-aimed click would otherwise silently destroy the thing you
            // were reaching for. No tick box here — a single row has no narrower
            // and wider reading for one to choose between.
            const ok = await showConfirm({
                title:   window.t('common.notifications.clear_one_title'),
                message: window.t('common.notifications.clear_one_msg'),
                okLabel: window.t('common.notifications.clear_ok'),
                okClass: 'danger'
            });
            if (!ok) return;
            try {
                const d = await (await fetch(CLEAR, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: [id] })
                })).json();
                if (!d.success) throw new Error(d.error || 'failed');
                item.remove();
                paintBadge(d.unread);
                // Re-baseline, or the chime goes quiet: chimeIfNew only fires when
                // the count rises above the stored figure, and clearing lowers it.
                recordSeen(d.unread);
                if (!list.querySelector('.nb-item')) {
                    list.innerHTML = '<div class="nb-empty">' + esc(window.t('common.notifications.empty')) + '</div>';
                }
            } catch (err) {
                if (typeof window.showToast === 'function') {
                    window.showToast(window.t('common.notifications.clear_failed'), 'error');
                }
            }
        }

        document.getElementById('nbMarkAll').addEventListener('click', async function (e) {
            e.stopPropagation();
            try {
                const d = await (await fetch(MARK, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ all: true })
                })).json();
                if (d.success) { paintBadge(d.unread); recordSeen(d.unread); open(); }
            } catch (err) { /* leave the panel as it is */ }
        });

        // Clear all deletes for good, so it asks first — and by default it spares
        // anything still unread. The tick box is the safety catch: emptying the
        // panel should not be able to bin news nobody has looked at yet, unless
        // that is deliberately asked for.
        document.getElementById('nbClearAll').addEventListener('click', function (e) {
            e.stopPropagation();

            const unread = lastUnread;
            const opts = {
                title:    window.t('common.notifications.clear_title'),
                message:  unread > 0
                            ? window.t('common.notifications.clear_msg_read')
                            : window.t('common.notifications.clear_msg'),
                okLabel:  window.t('common.notifications.clear_ok'),
                okClass:  'danger',
                onConfirm: state => doClearAll(!!state.checked)
            };
            // No unread rows means there is nothing for the catch to protect, and
            // an option that cannot change the outcome is just a thing to read.
            if (unread > 0) {
                opts.checkbox = {
                    label: unread === 1
                        ? window.t('common.notifications.clear_unread_one')
                        : window.t('common.notifications.clear_unread', { n: unread }),
                    checked: false
                };
            }
            showConfirm(opts);
        });

        /** Deletes notifications for good - read ones only unless includeUnread - then reloads the panel, saying so when nothing was cleared. */
        async function doClearAll(includeUnread) {
            try {
                const d = await (await fetch(CLEAR, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ all: true, include_unread: includeUnread })
                })).json();
                if (!d.success) throw new Error(d.error || 'failed');
                paintBadge(d.unread);
                recordSeen(d.unread);
                // Everything unread and the box left unticked: the honest outcome
                // is that nothing happened, and saying so beats a panel that looks
                // like it ignored the click.
                if (d.cleared === 0 && typeof window.showToast === 'function') {
                    window.showToast(window.t('common.notifications.clear_nothing'), 'info');
                }
                open();
            } catch (err) {
                if (typeof window.showToast === 'function') {
                    window.showToast(window.t('common.notifications.clear_failed'), 'error');
                }
            }
        }

        poll();
        setInterval(poll, 60000);
        // GH #267: a new notification shows within one heartbeat (15 seconds by default),
        // not the next minute. The minute poll stays: it also starts the scheduler (#233).
        if (window.LivePulse && !cfg.portal) window.LivePulse.watch('notifications', () => poll());
    }
};
