/**
 * War room — the client half.
 *
 * 🔒 EVERY PIECE OF MESSAGE TEXT IS INSERTED WITH textContent. Never innerHTML,
 * never a template string built from a message body, never an attachment's
 * filename dropped into markup. A chat box that renders other people's text as
 * HTML is a stored-XSS hole that every analyst walks into during an incident,
 * which is the worst possible moment for it. There is no sanitiser here on
 * purpose: not producing HTML is stronger than cleaning it afterwards.
 *
 * ⚠️ NOTHING ON THIS PAGE MAY LOAD FROM THE INTERNET. No CDN, no font, no remote
 * image. If it cannot be served from this box, it does not belong here.
 *
 * The poll is the module's heartbeat: it fetches new messages, records presence,
 * reports who else is here, and refreshes the channel list with its unread
 * counts — one request rather than four. See api/war-room/poll.php for why it is
 * a poll and not an EventSource.
 */
(function () {
    'use strict';

    var POLL_MS = 3000;

    var api       = window.API_BASE;
    var activeId  = window.WR_ACTIVE || 0;
    var maxFiles  = window.WR_MAX_FILES || 5;
    var directory = window.WR_DIRECTORY || [];
    var me        = window.WR_ME || 0;
    var myName    = window.WR_MY_NAME || '';
    var canManage = !!window.WR_CAN_MANAGE;

    // Name lookups for highlighting. Built once: the directory does not change
    // while the page is open, and rebuilding it per message would be per-message
    // work for a per-session fact.
    var allNames = directory.concat([{ id: me, name: myName }]).map(function (p) {
        var full = String(p.name || '').toLowerCase().trim();
        return { id: p.id, full: full, first: full.split(/\s+/)[0] || '' };
    });
    var myNames = allNames.filter(function (p) { return p.id === me; })
                          .reduce(function (acc, p) { return acc.concat([p.full, p.first]); }, []);

    var mentionStyle = window.WR_MENTION_STYLE || 'short';

    // How many people answer to each first name. This is the whole basis of the
    // 'short' style: in a war room a first name is nearly always unique, so the
    // surname is friction you are carrying for a collision that does not exist.
    var firstNameCounts = {};
    allNames.forEach(function (p) {
        firstNameCounts[p.first] = (firstNameCounts[p.first] || 0) + 1;
    });

    /** What the picker actually types into the box for this person. */
    function insertedForm(person) {
        var full  = String(person.name || '');
        var first = full.split(/\s+/)[0] || full;
        if (mentionStyle === 'short' && firstNameCounts[first.toLowerCase()] === 1) return first;
        return full;                         // 'full', 'strip', or an ambiguous first name
    }

    var lastId    = 0;
    var timer     = null;
    var offline   = false;
    var channels  = [];
    var pending   = [];      // files chosen but not yet sent
    var quoting   = null;    // the message the next one quotes (5.9.0), or null
    var focusId   = window.WR_FOCUS || 0;   // a permanent link's message, until it is shown

    // The ten reactions offered first (wiki War-Room-Message-Actions-Design). The
    // full list ships with FreeITSM - nothing comes from the internet.
    var QUICK_EMOJI = ['\uD83D\uDC4D', '\u2764\uFE0F', '\uD83D\uDE02', '\uD83D\uDE2E', '\uD83D\uDE22',
                       '\uD83C\uDF89', '\uD83D\uDC40', '\u2705', '\uD83D\uDE4F', '\uD83D\uDD25'];
    var EMOJI_URL = (window.WR_LINK_BASE || '../war-room/').replace(/war-room\/$/, '') + 'assets/js/war-room-emoji.json?v=1';

    var els = {};
    ['wrChannels', 'wrMessages', 'wrEmpty', 'wrComposer', 'wrBody', 'wrSend', 'wrPresence',
     'wrTitle', 'wrTopic', 'wrPanel', 'wrPanelTitle', 'wrPanelBody', 'wrPanelClose',
     'wrModal', 'wrModalTitle', 'wrModalBody', 'wrModalClose', 'wrSearchBtn', 'wrSitrepBtn',
     'wrManageBtn', 'wrNewChannel', 'wrNewDm', 'wrFiles', 'wrPending', 'wrArchivedNote',
     'wrAttachLabel'].forEach(function (id) { els[id] = document.getElementById(id); });

    /** Translates a key through window.t, or returns the key when no translator is loaded. */
    function t(key, vars) {
        return (typeof window.t === 'function') ? window.t(key, vars) : key;
    }

    /* ── small DOM helpers ────────────────────────────────────────────────────
       el() takes TEXT, not markup, which is what keeps the XSS rule above from
       depending on anybody remembering it at each call site. */
    function el(tag, className, text) {
        var n = document.createElement(tag);
        if (className) n.className = className;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }
    /** Removes every child of a DOM node. */
    function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }

    /** Local time from the server's UTC string, via the app's shared tz helper. */
    function localTime(utc) {
        if (window.TZ && typeof window.TZ.time === 'function') return window.TZ.time(utc);
        var d = new Date(String(utc).replace(' ', 'T') + 'Z');
        return isNaN(d) ? '' : fmtTime(d);
    }

    /** Formats a byte count as B, KB or MB for an attachment chip. */
    function bytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1048576) return Math.round(n / 1024) + ' KB';
        return (n / 1048576).toFixed(1) + ' MB';
    }

    /* ── channel list ──────────────────────────────────────────────────────── */

    var GROUPS = [
        { kind: 'team',   key: 'war-room.channel.teams' },
        { kind: 'custom', key: 'war-room.channel.channels' },
        { kind: 'dm',     key: 'war-room.channel.direct' }
    ];

    /** Redraws the channel list - the all-hands channel first, then the Teams, Channels and Direct groups. */
    function renderChannels() {
        clear(els.wrChannels);

        channels.filter(function (c) { return c.kind === 'all'; }).forEach(addChannelButton);
        GROUPS.forEach(function (g) {
            var inGroup = channels.filter(function (c) { return c.kind === g.kind; });
            if (!inGroup.length) return;
            els.wrChannels.appendChild(el('div', 'wr-group', t(g.key)));
            inGroup.forEach(addChannelButton);
        });
    }

    /**
     * Appends one channel button with its private marker and its mention or unread badge (neither shows for the open channel).
     */
    function addChannelButton(c) {
        var btn = el('button', 'wr-channel' + (c.id === activeId ? ' active' : ''));
        btn.type = 'button';
        btn.setAttribute('data-channel-id', c.id);
        btn.setAttribute('data-kind', c.kind);
        btn.appendChild(el('span', 'wr-channel-name', c.name + (c.archived ? ' (' + t('war-room.channel.archived') + ')' : '')));
        if (c.is_private && c.kind === 'custom') btn.appendChild(el('span', 'wr-lock', '•'));
        // Both counts are suppressed for the channel you are looking at: they
        // would tick up and vanish on every poll, which reads as a bug.
        if (c.id !== activeId) {
            // A mention badge REPLACES the unread badge rather than sitting beside
            // it. Two numbers on one row is a puzzle; the mention is strictly the
            // more urgent of the two, so it is the one that shows.
            if (c.mentions > 0) {
                btn.appendChild(el('span', 'wr-mention-badge', '@' + (c.mentions > 9 ? '9+' : c.mentions)));
            } else if (c.unread > 0) {
                btn.appendChild(el('span', 'wr-unread', c.unread > 99 ? '99+' : c.unread));
            }
        }
        els.wrChannels.appendChild(btn);
    }

    /** Returns the channel object for the open channel, or null. */
    function currentChannel() {
        for (var i = 0; i < channels.length; i++) if (channels[i].id === activeId) return channels[i];
        return null;
    }

    /**
     * Fills the title and topic for the open channel, swaps the composer for the archived note when archived, and shows Manage only for custom channels.
     */
    function applyChannelHeader() {
        var c = currentChannel();
        if (!c) return;
        els.wrTitle.textContent = c.name;
        els.wrTopic.textContent = c.topic || '';
        els.wrTopic.hidden = !c.topic;

        // An archived channel stays readable; only posting stops. Showing the
        // composer greyed with an explanation beats hiding it, which would look
        // like the page had failed to load.
        var archived = !!c.archived;
        els.wrArchivedNote.hidden = !archived;
        els.wrComposer.hidden = archived;
        els.wrManageBtn.hidden = (c.kind !== 'custom');
    }

    /** Opens another channel - clears the message list, resets the since_id watermark and polls at once. */
    function switchChannel(id) {
        if (id === activeId) return;
        activeId = id;
        lastId = 0;
        clear(els.wrMessages);
        els.wrMessages.appendChild(els.wrEmpty);
        els.wrEmpty.hidden = false;
        renderChannels();
        applyChannelHeader();
        closePanel();
        closeMenu();
        clearQuote();
        poll();
    }

    els.wrChannels.addEventListener('click', function (e) {
        var btn = e.target.closest('.wr-channel');
        if (btn) switchChannel(parseInt(btn.getAttribute('data-channel-id'), 10));
    });

    /* ── messages ──────────────────────────────────────────────────────────── */

    /**
     * Builds one message row - author, Warbot tag, time, edited flag, body with @mentions, edit/delete buttons and attachments - all as text nodes.
     */
    function renderMessage(m) {
        var row = el('div', 'wr-msg' + (m.deleted ? ' wr-msg-deleted' : '') + (m.is_bot ? ' wr-msg-bot' : ''));
        row.setAttribute('data-msg-id', m.id);

        var head = el('div', 'wr-msg-head');
        head.appendChild(el('span', 'wr-msg-author', m.author));
        // Labelled, always. Somebody arriving mid-incident must not mistake a
        // machine's answer for a colleague's — especially when the answer is
        // about to be repeated to the business.
        if (m.is_bot) head.appendChild(el('span', 'wr-bot-tag', t('war-room.warbot.tag')));
        head.appendChild(el('span', 'wr-msg-time', localTime(m.created)));
        // Both are stated, never silent: this is the record of an incident, and a
        // reader has to be able to tell a message that changed from one that did not.
        if (m.edited && !m.deleted) head.appendChild(el('span', 'wr-msg-flag', t('war-room.message.edited')));
        row.appendChild(head);

        if (m.deleted) {
            row.appendChild(el('div', 'wr-msg-body wr-tombstone',
                t('war-room.message.deleted_by', { name: m.deleted_by || t('war-room.former_analyst') })));
            return row;
        }

        // The message this one quotes (5.9.0) - drawn from the reference, never
        // pasted text, so it says so if the original was deleted or has gone.
        if (m.quote) row.appendChild(renderQuote(m.quote));

        var body = el('div', 'wr-msg-body');
        renderBodyWithMentions(body, m.body);
        row.appendChild(body);

        // Edit and delete belong to the author; delete also to war_room.manage.
        // Rendered per message as well as in the menu, because a message you can act
        // on and one you cannot should not look identical. Nobody owns Warbot's
        // messages, so only a moderator gets Delete on one.
        var acts = el('div', 'wr-msg-acts');
        if (mayEdit(m)) {
            var ed = el('button', 'wr-msg-act', t('war-room.message.edit'));
            ed.type = 'button';
            ed.addEventListener('click', function () { openEdit(m); });
            acts.appendChild(ed);
        }
        if (mayDelete(m)) {
            var del = el('button', 'wr-msg-act', t('war-room.message.delete'));
            del.type = 'button';
            del.addEventListener('click', function () { openDelete(m); });
            acts.appendChild(del);
        }
        // The menu's keyboard and touch way in: right-click is not the only door.
        var more = el('button', 'wr-msg-act wr-msg-more', '\u22EF');
        more.type = 'button';
        more.setAttribute('aria-label', t('war-room.menu.label'));
        more.setAttribute('aria-haspopup', 'menu');
        more.addEventListener('click', function () {
            var r = more.getBoundingClientRect();
            openMenu(m, r.right, r.bottom + 4, more);
        });
        acts.appendChild(more);
        row.appendChild(acts);

        row.addEventListener('contextmenu', function (e) {
            // Selected text keeps the browser's own menu, so Copy still works on part of a message.
            var sel = window.getSelection ? String(window.getSelection()) : '';
            if (sel && row.contains(window.getSelection().anchorNode)) return;
            e.preventDefault();
            openMenu(m, e.clientX, e.clientY, null);
        });
        addLongPress(row, m);

        if (m.attachments && m.attachments.length) {
            var wrap = el('div', 'wr-attachments');
            m.attachments.forEach(function (a) {
                var href = api + 'attachment.php?id=' + encodeURIComponent(a.id);
                if (a.inline && /^image\//.test(guessKind(a.name))) {
                    // Inline previews are only ever for types OUR OWN map marks
                    // renderable (see attachmentServeRules) — the flag comes from
                    // the server, never from the uploader.
                    var link = el('a', 'wr-thumb');
                    link.href = href; link.target = '_blank'; link.rel = 'noopener';
                    viewOnClick(link, a, href);
                    var img = document.createElement('img');
                    img.src = href; img.alt = a.name; img.loading = 'lazy';
                    link.appendChild(img);
                    wrap.appendChild(link);
                } else {
                    var f = el('a', 'wr-file');
                    f.href = href; f.target = '_blank'; f.rel = 'noopener';
                    viewOnClick(f, a, href);
                    f.appendChild(el('span', 'wr-file-name', a.name));
                    f.appendChild(el('span', 'wr-file-size', bytes(a.size)));
                    wrap.appendChild(f);
                }
            });
            row.appendChild(wrap);
        }
        if (m.reactions && m.reactions.length) row.appendChild(renderReactions(m));
        return row;
    }

    /**
     * Write a message body into `node`, marking the @names inside it.
     *
     * 🔒 STILL NO innerHTML. The body is split into runs and each run appended as
     * its own text node or <span> — so a message containing markup is as inert here
     * as it is anywhere else in this file. Highlighting text is not a good enough
     * reason to start building HTML from user input.
     *
     * This is presentation only. Who was actually notified was decided server-side
     * when the message was sent, and the two can differ: a name typed for somebody
     * who cannot see the channel is highlighted here but was never notified.
     */
    function renderBodyWithMentions(node, text) {
        // ⚠️ TWO WORDS FIRST, THEN ONE. The capture has to allow a surname, but a
        // greedy two-word match turns "@James hello abc" into the name "James
        // hello", which matches nobody and highlights nothing. Falling back to the
        // first word mirrors what the server does when it decides who to notify —
        // and the two must agree, or a message shows no highlight while somebody
        // is being notified by it.
        var re = /@(everyone|[\p{L}][\p{L}'’-]*(?:\s+[\p{L}][\p{L}'’-]*)?)/giu;
        var last = 0, match;
        while ((match = re.exec(text)) !== null) {
            if (match.index > last) node.appendChild(document.createTextNode(text.slice(last, match.index)));

            var name = match[1];
            var label = match[0];
            if (!/^everyone$/i.test(name) && !nameKnown(name)) {
                var firstWord = name.split(/\s+/)[0];
                if (nameKnown(firstWord)) { name = firstWord; label = '@' + firstWord; }
            }

            if (/^everyone$/i.test(name) || nameKnown(name)) {
                node.appendChild(el('span', mentionsMe(name) ? 'wr-at wr-at-me' : 'wr-at', label));
            } else {
                node.appendChild(document.createTextNode(label));
            }
            // Continue from the end of what we actually marked, which after the
            // fallback is shorter than the regex consumed.
            last = match.index + label.length;
            re.lastIndex = last;
        }
        if (last < text.length) node.appendChild(document.createTextNode(text.slice(last)));
    }

    /** True when a name (full or first) belongs to someone in the directory, the current analyst, or Warbot. */
    function nameKnown(name) {
        var n = name.toLowerCase().trim();
        if (n === 'warbot') return true;          // highlighted like any other name
        return allNames.some(function (p) {
            return p.full === n || p.first === n;
        });
    }
    /** True when a mentioned name is the current analyst's full or first name. */
    function mentionsMe(name) {
        var n = name.toLowerCase().trim();
        return myNames.indexOf(n) >= 0;
    }

    /** Only used to decide thumbnail vs link; the server decides what is served. */
    function guessKind(name) {
        var ext = String(name).split('.').pop().toLowerCase();
        return ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'].indexOf(ext) >= 0 ? 'image/' + ext : 'other';
    }

    /**
     * Adds polled messages to the list, replacing any already shown with the same id, and scrolls down only for new ones when the reader was at the bottom.
     */
    function appendMessages(list) {
        if (!list.length) return;
        els.wrEmpty.hidden = true;
        // "Was the reader at the bottom before we appended?" has to be asked
        // BEFORE appending, or the answer is always no and the view jumps away
        // from whatever they had scrolled back to read.
        var atBottom = (els.wrMessages.scrollHeight - els.wrMessages.scrollTop - els.wrMessages.clientHeight) < 80;
        var added = false;

        list.forEach(function (m) {
            messageById[m.id] = m;
            var node = renderMessage(m);
            // ⚠️ UPSERT BY ID, NEVER BLIND APPEND. The poll deliberately re-sends
            // a message that was edited or deleted in the last 30 seconds, because
            // its id is below the since_id watermark and it would otherwise never
            // come back. Appending that made the same tombstone pile up once every
            // three seconds — a wall of "Message deleted by …" that Ed watched
            // happen. Replacing in place makes re-delivery idempotent, which is
            // what a poll that can repeat itself requires.
            var existing = els.wrMessages.querySelector('[data-msg-id="' + m.id + '"]');
            if (existing) {
                existing.parentNode.replaceChild(node, existing);
            } else {
                els.wrMessages.appendChild(node);
                added = true;
            }
            if (m.id > lastId) lastId = m.id;
        });

        // Only scroll for genuinely NEW messages. Following an edit to the bottom
        // would yank the reader away from whatever they had scrolled back to.
        if (atBottom && added) els.wrMessages.scrollTop = els.wrMessages.scrollHeight;
    }

    /* ── presence ──────────────────────────────────────────────────────────── */

    /** Draws the presence panel - who is in this channel, who is elsewhere in the war room, or an offline warning. */
    function renderPresence(p) {
        clear(els.wrPresence);
        if (offline) {
            els.wrPresence.appendChild(el('div', 'wr-presence-offline', t('war-room.error.offline')));
            return;
        }
        var here = (p && p.here) || [], away = (p && p.elsewhere) || [];
        if (!here.length && !away.length) {
            els.wrPresence.appendChild(el('div', 'wr-presence-none', t('war-room.presence.nobody')));
            return;
        }
        if (here.length) {
            els.wrPresence.appendChild(el('div', 'wr-presence-label', t('war-room.presence.here')));
            here.forEach(function (n) { els.wrPresence.appendChild(el('div', 'wr-presence-name', n)); });
        }
        if (away.length) {
            els.wrPresence.appendChild(el('div', 'wr-presence-label', t('war-room.presence.elsewhere')));
            away.forEach(function (n) { els.wrPresence.appendChild(el('div', 'wr-presence-name wr-away', n)); });
        }
    }

    /* ── the poll ──────────────────────────────────────────────────────────── */

    /**
     * The heartbeat - fetches poll.php for new messages, channel list with counts and presence, and flags the page offline when it fails.
     */
    function poll() {
        var url = api + 'poll.php?channel_id=' + encodeURIComponent(activeId) +
                  '&since_id=' + encodeURIComponent(lastId) + '&read=1' +
                  (focusId && lastId === 0 ? '&around=' + encodeURIComponent(focusId) : '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) throw new Error('poll failed');
                offline = false;
                appendMessages(d.messages || []);
                if (focusId) { var f = focusId; focusId = 0; highlightMessage(f, true); }
                channels = d.channels || [];
                renderChannels();
                applyChannelHeader();
                renderPresence(d.present);
            })
            .catch(function () {
                // ⚠️ Say so. The one thing this module must never do is let
                // somebody believe their message is being delivered when it is
                // not — silence would look exactly like a quiet channel.
                offline = true;
                renderPresence(null);
            });
    }

    /** Starts (or restarts) the poll every POLL_MS milliseconds. */
    function startPolling() {
        if (timer) clearInterval(timer);
        timer = setInterval(poll, POLL_MS);
    }

    /* ── sending ───────────────────────────────────────────────────────────── */

    /** Draws a removable chip for each file chosen but not yet sent. */
    function renderPending() {
        clear(els.wrPending);
        els.wrPending.hidden = !pending.length;
        pending.forEach(function (f, i) {
            var chip = el('span', 'wr-chip');
            chip.appendChild(el('span', null, f.name));
            var x = el('button', 'wr-chip-x', '×');
            x.type = 'button';
            x.addEventListener('click', function () { pending.splice(i, 1); renderPending(); });
            chip.appendChild(x);
            els.wrPending.appendChild(chip);
        });
    }

    els.wrFiles.addEventListener('change', function () {
        var chosen = Array.prototype.slice.call(els.wrFiles.files || []);
        if (pending.length + chosen.length > maxFiles) {
            alert(t('war-room.attach.too_many', { count: maxFiles }));
            chosen = chosen.slice(0, Math.max(0, maxFiles - pending.length));
        }
        pending = pending.concat(chosen);
        els.wrFiles.value = '';
        renderPending();
    });

    els.wrComposer.addEventListener('submit', function (e) {
        e.preventDefault();
        send();
    });

    /* ── @ autocomplete ────────────────────────────────────────────────────────
       Type @, start typing, and pick a name — or ignore the list and keep typing.
       Accepting inserts the FULL name; you can then backspace back to just the
       first name and it still resolves, because the server matches on first names
       too. That is the whole reason mentions are resolved from the text rather
       than from a list of ids this picker hands over: the moment you edit the
       text, any id captured here would be wrong. */

    var acIndex = -1, acMatches = [], acStart = -1;
    var acBox = el('div', 'wr-ac');
    acBox.hidden = true;
    els.wrComposer.appendChild(acBox);

    /** The @word being typed at the caret, or null. */
    function acContext() {
        var v = els.wrBody.value, pos = els.wrBody.selectionStart;
        var upto = v.slice(0, pos);
        var at = upto.lastIndexOf('@');
        if (at < 0) return null;
        // Only immediately after whitespace or at the very start: an email address
        // must not open the picker.
        if (at > 0 && !/\s/.test(upto.charAt(at - 1))) return null;
        var typed = upto.slice(at + 1);
        // One space is allowed, so "@Sarah Wil" still matches a full name.
        if (!/^[\p{L}'’-]*(\s[\p{L}'’-]*)?$/u.test(typed)) return null;
        return { at: at, typed: typed };
    }

    /**
     * Shows the @ autocomplete for the word at the caret - up to six directory matches, plus @everyone and Warbot when they fit.
     */
    function acRender() {
        var ctx = acContext();
        if (!ctx) { acHide(); return; }
        var q = ctx.typed.toLowerCase();
        acStart = ctx.at;
        acMatches = directory.filter(function (p) {
            return !q || p.name.toLowerCase().indexOf(q) === 0
                || p.name.toLowerCase().split(/\s+/).some(function (w) { return w.indexOf(q) === 0; });
        }).slice(0, 6);
        // @everyone is offered alongside people, because in an outage it is the one
        // you most often want and hunting for it in a menu is the wrong cost.
        if (!q || 'everyone'.indexOf(q) === 0) acMatches.unshift({ id: 0, name: 'everyone', all: true });
        // Warbot sits at the top: it is the name most often typed and the one
        // people will not think to look for.
        if (!q || 'warbot'.indexOf(q) === 0) acMatches.unshift({ id: -1, name: 'Warbot', bot: true });

        if (!acMatches.length) { acHide(); return; }
        acIndex = 0;
        clear(acBox);
        acMatches.forEach(function (p, i) {
            var opt = el('button', 'wr-ac-item' + (i === 0 ? ' active' : ''));
            opt.type = 'button';
            opt.appendChild(el('span', null, p.all ? '@everyone' : p.name));
            if (p.bot)  opt.appendChild(el('span', 'wr-bot-tag', t('war-room.warbot.tag')));
            if (p.here) opt.appendChild(el('span', 'wr-here-dot', '•'));
            opt.addEventListener('mousedown', function (e) { e.preventDefault(); acAccept(i); });
            acBox.appendChild(opt);
        });
        acBox.hidden = false;
    }

    /** Hides the @ autocomplete and forgets its matches. */
    function acHide() { acBox.hidden = true; acMatches = []; acIndex = -1; }

    /** Replaces the typed @word with the chosen name in the analyst's mention style, then closes the picker. */
    function acAccept(i) {
        var p = acMatches[i];
        if (!p) return;
        var v = els.wrBody.value, pos = els.wrBody.selectionStart;
        // The list shows the full name so you know who you picked; what gets typed
        // is whatever the chosen style calls for.
        var insert = '@' + (p.all ? 'everyone' : insertedForm(p)) + ' ';
        els.wrBody.value = v.slice(0, acStart) + insert + v.slice(pos);
        var caret = acStart + insert.length;
        els.wrBody.setSelectionRange(caret, caret);
        acHide();
    }

    /**
     * The mention immediately before the caret, if there is one.
     *
     * Used to make backspace behave the way every other chat tool behaves. Slack,
     * Discord and Teams all make a mention ATOMIC — one press removes the whole
     * thing — but they do it by putting a pill object in a contenteditable box.
     * We keep a plain textarea on purpose (a pill would mean storing `@[39]` in
     * the message, which then leaks into search results and into the transcript
     * the AI summarises), so the atomicity is done here in the key handler
     * instead. Same feel, none of the cost.
     *
     * @return {{start:number, end:number, hasSurname:boolean}|null}
     */
    function mentionBeforeCaret() {
        var v = els.wrBody.value, pos = els.wrBody.selectionStart;
        if (pos !== els.wrBody.selectionEnd) return null;      // a selection: leave it alone

        // The picker leaves a trailing space, so the caret is usually just past it.
        var end = pos;
        if (end > 0 && v.charAt(end - 1) === ' ') end--;
        if (end <= 0) return null;

        var upto = v.slice(0, end);
        var at = upto.lastIndexOf('@');
        if (at < 0) return null;
        if (at > 0 && !/\s/.test(upto.charAt(at - 1))) return null;   // an email address

        var typed = upto.slice(at + 1);
        if (!/^[\p{L}][\p{L}'’-]*(\s[\p{L}][\p{L}'’-]*)?$/u.test(typed)) return null;

        var isName = /^everyone$/i.test(typed) || nameKnown(typed);
        if (!isName) return null;

        return { start: at, end: pos, hasSurname: /\s/.test(typed) };
    }

    els.wrBody.addEventListener('input', acRender);
    els.wrBody.addEventListener('blur', function () { setTimeout(acHide, 120); });

    // Enter sends, Shift+Enter starts a new line — the convention every chat tool
    // uses, so muscle memory works on the day somebody first opens this.
    els.wrBody.addEventListener('keydown', function (e) {
        if (!acBox.hidden && acMatches.length) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                acIndex = (acIndex + (e.key === 'ArrowDown' ? 1 : -1) + acMatches.length) % acMatches.length;
                Array.prototype.forEach.call(acBox.children, function (c, i) {
                    c.classList.toggle('active', i === acIndex);
                });
                return;
            }
            // Enter and Tab accept the highlighted name. Enter must NOT send while
            // the picker is open, or picking a name posts a half-written message.
            if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); acAccept(acIndex); return; }
            if (e.key === 'Escape') { e.preventDefault(); acHide(); return; }
        }

        // Backspace at the end of a mention. Without this you delete a name one
        // letter at a time, which was the thing that felt clunky — and which no
        // other chat tool makes you do.
        if (e.key === 'Backspace') {
            var m = mentionBeforeCaret();
            if (m) {
                var v = els.wrBody.value;

                // 'strip' keeps the two-stage behaviour on purpose: the first press
                // drops the surname and leaves the short form, the second clears it.
                if (mentionStyle === 'strip' && m.hasSurname) {
                    var name  = v.slice(m.start + 1, m.end).trim();
                    var short = '@' + name.split(/\s+/)[0] + ' ';
                    e.preventDefault();
                    els.wrBody.value = v.slice(0, m.start) + short + v.slice(m.end);
                    var c1 = m.start + short.length;
                    els.wrBody.setSelectionRange(c1, c1);
                    return;
                }

                e.preventDefault();
                els.wrBody.value = v.slice(0, m.start) + v.slice(m.end);
                els.wrBody.setSelectionRange(m.start, m.start);
                return;
            }
        }

        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });

    /* ── mention style preference ─────────────────────────────────────────────── */

    var styleSel = document.getElementById('wrMentionStyle');
    if (styleSel) {
        styleSel.addEventListener('change', function () {
            mentionStyle = styleSel.value;
            fetch(window.WR_PREF_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key: 'warroom_mention_style', value: mentionStyle })
            });
        });
    }

    /* ── doing things with a message (5.9.0) ──────────────────────────────────
       wiki War-Room-Message-Actions-Design. Right-click, the ⋯ button, or a long
       press on a phone opens one menu: ten quick reactions and + for the full
       picker, then Quote, Copy message, Copy link, Edit, Delete. Everything here
       is still built from text nodes - no message text ever becomes markup. */

    /** True when the analyst may edit this message: their own, never Warbot's, never a deleted one. */
    function mayEdit(m) { return !m.deleted && !m.is_bot && m.analyst_id === me; }
    /** True when the analyst may delete it: their own, or anybody's with war_room.manage. */
    function mayDelete(m) { return !m.deleted && (canManage || (!m.is_bot && m.analyst_id === me)); }

    /** The message object currently drawn with this id - the poll keeps them fresh. */
    var messageById = {};

    var menuEl = null, menuReturn = null;

    /** Closes the message menu and the emoji picker, handing focus back where it came from. */
    function closeMenu() {
        if (menuEl) { menuEl.remove(); menuEl = null; }
        closePicker();
        if (menuReturn && document.contains(menuReturn)) menuReturn.focus();
        menuReturn = null;
    }

    /** Places a floating box at (x, y), kept inside the window. */
    function placeBox(box, x, y, alignRight) {
        box.style.left = '0px'; box.style.top = '0px';
        document.body.appendChild(box);
        var w = box.offsetWidth, h = box.offsetHeight;
        var left = alignRight ? x - w : x;
        left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
        var top = y + h > window.innerHeight - 8 ? Math.max(8, y - h) : y;
        box.style.left = left + 'px';
        box.style.top = top + 'px';
    }

    /**
     * Opens the message menu at (x, y).
     * @param {Object} m  the message, as the poll sent it
     * @param {HTMLElement|null} from  the ⋯ button that opened it, for focus to return to
     */
    function openMenu(m, x, y, from) {
        closeMenu();
        if (m.deleted) return;
        m = messageById[m.id] || m;
        menuReturn = from;
        menuEl = el('div', 'wr-menu');
        menuEl.setAttribute('role', 'menu');
        menuEl.setAttribute('aria-label', t('war-room.menu.label'));

        var canPost = !els.wrComposer.hidden;   // an archived channel is read-only
        if (canPost) {
            var quick = el('div', 'wr-menu-emoji');
            QUICK_EMOJI.forEach(function (e) {
                var b = el('button', 'wr-menu-emo' + (myReaction(m, e) ? ' is-mine' : ''), e);
                b.type = 'button';
                b.setAttribute('role', 'menuitem');
                b.setAttribute('aria-label', t('war-room.menu.react', { emoji: e }));
                b.addEventListener('click', function () { closeMenu(); react(m, e, !myReaction(m, e)); });
                quick.appendChild(b);
            });
            var plus = el('button', 'wr-menu-emo wr-menu-plus', '+');
            plus.type = 'button';
            plus.setAttribute('role', 'menuitem');
            plus.setAttribute('aria-label', t('war-room.menu.more_reactions'));
            plus.title = t('war-room.menu.more_reactions');
            plus.addEventListener('click', function () {
                var r = menuEl.getBoundingClientRect();
                var keep = m;
                closeMenu();
                openPicker(keep, r.left, r.top);
            });
            quick.appendChild(plus);
            menuEl.appendChild(quick);
        }

        /** Adds one text item to the menu. */
        function item(label, fn) {
            var b = el('button', 'wr-menu-item', label);
            b.type = 'button';
            b.setAttribute('role', 'menuitem');
            b.addEventListener('click', function () { closeMenu(); fn(); });
            menuEl.appendChild(b);
        }
        if (canPost) item(t('war-room.menu.quote'), function () { startQuote(m); });
        item(t('war-room.menu.copy'), function () { copyMessage(m); });
        item(t('war-room.menu.copy_link'), function () { copyLink(m); });
        if (mayEdit(m) || mayDelete(m)) menuEl.appendChild(el('div', 'wr-menu-sep'));
        if (mayEdit(m)) item(t('war-room.message.edit'), function () { openEdit(m); });
        if (mayDelete(m)) item(t('war-room.message.delete'), function () { openDelete(m); });

        menuEl.addEventListener('keydown', menuKeys);
        placeBox(menuEl, x, y, !!from);
        var first = menuEl.querySelector('button');
        if (first) first.focus();
    }

    /** Arrow keys move through the menu; Escape closes it. */
    function menuKeys(e) {
        var items = Array.prototype.slice.call(menuEl.querySelectorAll('button'));
        var i = items.indexOf(document.activeElement);
        if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
        else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
        else if (e.key === 'Escape') { e.preventDefault(); closeMenu(); }
        else if (e.key === 'Tab') { closeMenu(); }
    }

    document.addEventListener('mousedown', function (e) {
        if (menuEl && !menuEl.contains(e.target)) closeMenu();
        if (pickerEl && !pickerEl.contains(e.target)) closePicker();
    });
    window.addEventListener('resize', closeMenu);
    els.wrMessages.addEventListener('scroll', function () { if (menuEl) closeMenu(); });

    /** A long press opens the menu on a phone, where there is no right-click. Moving cancels it. */
    function addLongPress(row, m) {
        var timer = null, sx = 0, sy = 0;
        row.addEventListener('touchstart', function (e) {
            if (e.touches.length !== 1) return;
            sx = e.touches[0].clientX; sy = e.touches[0].clientY;
            timer = setTimeout(function () { timer = null; openMenu(m, sx, sy, null); }, 550);
        }, { passive: true });
        /** Cancels a pending long press. */
        var cancel = function () { if (timer) { clearTimeout(timer); timer = null; } };
        row.addEventListener('touchmove', function (e) {
            if (Math.abs(e.touches[0].clientX - sx) > 10 || Math.abs(e.touches[0].clientY - sy) > 10) cancel();
        }, { passive: true });
        row.addEventListener('touchend', cancel);
        row.addEventListener('touchcancel', cancel);
    }

    /* ── reactions ── */

    /** True when the analyst has already given this emoji to the message. */
    function myReaction(m, emoji) {
        return (m.reactions || []).some(function (r) { return r.emoji === emoji && r.mine; });
    }

    /** The row of reactions under a message: each emoji, its count, and who on hover. Click to add or take away yours. */
    function renderReactions(m) {
        var wrap = el('div', 'wr-reactions');
        m.reactions.forEach(function (r) {
            var b = el('button', 'wr-reaction' + (r.mine ? ' is-mine' : ''));
            b.type = 'button';
            b.appendChild(el('span', 'wr-reaction-emo', r.emoji));
            b.appendChild(el('span', 'wr-reaction-n', String(r.count)));
            b.title = (r.names || []).join(', ');
            b.setAttribute('aria-pressed', r.mine ? 'true' : 'false');
            b.setAttribute('aria-label', t('war-room.reactions.label', { emoji: r.emoji, names: (r.names || []).join(', ') }));
            b.disabled = els.wrComposer.hidden;   // archived: read-only
            b.addEventListener('click', function () { react(m, r.emoji, !r.mine); });
            wrap.appendChild(b);
        });
        return wrap;
    }

    /** Adds (on) or takes away a reaction, then polls so it shows at once; the poll brings everybody else's. */
    function react(m, emoji, on) {
        if (on) rememberEmoji(emoji);
        fetch(api + 'message.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'react', id: m.id, emoji: emoji, on: on ? 1 : 0 })
        }).then(function (r) { return r.json(); })
          .then(function (d) { if (!d || !d.success) throw new Error('failed'); poll(); })
          .catch(function () { toast(t('war-room.reactions.failed')); });
    }

    /* ── the full emoji picker ── */

    var pickerEl = null, emojiData = null;
    var RECENT_KEY = 'freeitsm.warroom.recentEmoji.' + me;

    /** Recently used emoji, newest first - a convenience kept in this browser only. */
    function recentEmoji() {
        try { var v = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); return Array.isArray(v) ? v.slice(0, 24) : []; }
        catch (e) { return []; }
    }
    /** Puts an emoji at the front of the recently used list. */
    function rememberEmoji(e) {
        try {
            var list = recentEmoji().filter(function (x) { return x !== e; });
            list.unshift(e);
            localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 24)));
        } catch (err) { /* private window, storage off: recent simply stays empty */ }
    }

    /** Fetches the emoji list once (it ships with FreeITSM). */
    function loadEmoji() {
        if (emojiData) return Promise.resolve(emojiData);
        return fetch(EMOJI_URL, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { emojiData = d; return d; });
    }

    /** Closes the full emoji picker. */
    function closePicker() { if (pickerEl) { pickerEl.remove(); pickerEl = null; } }

    /** Opens the full emoji picker for a message: search, a tab per group, recently used first. */
    function openPicker(m, x, y) {
        closePicker();
        pickerEl = el('div', 'wr-picker');
        pickerEl.setAttribute('role', 'dialog');
        pickerEl.setAttribute('aria-label', t('war-room.emoji.title'));
        var search = el('input', 'wr-input wr-picker-search');
        search.type = 'search';
        search.placeholder = t('war-room.emoji.search');
        search.setAttribute('aria-label', t('war-room.emoji.search'));
        pickerEl.appendChild(search);
        var tabs = el('div', 'wr-picker-tabs');
        pickerEl.appendChild(tabs);
        var grid = el('div', 'wr-picker-grid');
        grid.appendChild(el('div', 'wr-muted', t('war-room.emoji.loading')));
        pickerEl.appendChild(grid);
        pickerEl.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); closePicker(); } });
        placeBox(pickerEl, x, y, false);
        search.focus();

        /** One emoji button: click reacts and closes. */
        function emoButton(e, name) {
            var b = el('button', 'wr-picker-emo', e);
            b.type = 'button';
            b.title = name || e;
            b.setAttribute('aria-label', name || e);
            b.addEventListener('click', function () { closePicker(); react(m, e, !myReaction(m, e)); });
            return b;
        }
        /** Draws a titled section of emoji into the grid. */
        function section(title, list, id) {
            var h = el('div', 'wr-picker-head', title);
            if (id) h.id = id;
            grid.appendChild(h);
            var box = el('div', 'wr-picker-set');
            list.forEach(function (p) { box.appendChild(emoButton(p[0], p[1])); });
            grid.appendChild(box);
        }

        loadEmoji().then(function (data) {
            if (!pickerEl) return;
            var names = {};
            data.forEach(function (g) { g.emoji.forEach(function (p) { names[p[0]] = p[1]; }); });
            /** Draws everything, or only the emoji whose English name contains q. */
            function draw(q) {
                clear(grid);
                q = q.trim().toLowerCase();
                if (q) {
                    var hits = [];
                    data.forEach(function (g) { g.emoji.forEach(function (p) { if (p[1].indexOf(q) >= 0) hits.push(p); }); });
                    if (hits.length) section(t('war-room.emoji.results'), hits.slice(0, 300));
                    else grid.appendChild(el('div', 'wr-muted', t('war-room.emoji.none')));
                    return;
                }
                var recent = recentEmoji();
                if (recent.length) section(t('war-room.emoji.recent'), recent.map(function (e) { return [e, names[e] || e]; }), 'wrPickRecent');
                data.forEach(function (g) { section(t('war-room.emoji.group.' + g.group), g.emoji, 'wrPick-' + g.group); });
            }
            clear(tabs);
            data.forEach(function (g) {
                var b = el('button', 'wr-picker-tab', g.emoji[0][0]);
                b.type = 'button';
                b.title = t('war-room.emoji.group.' + g.group);
                b.setAttribute('aria-label', t('war-room.emoji.group.' + g.group));
                b.addEventListener('click', function () {
                    search.value = ''; draw('');
                    var h = document.getElementById('wrPick-' + g.group);
                    if (h) grid.scrollTop = h.offsetTop - grid.offsetTop;
                });
                tabs.appendChild(b);
            });
            draw('');
            search.addEventListener('input', function () { draw(search.value); });
        }).catch(function () {
            clear(grid);
            grid.appendChild(el('div', 'wr-muted', t('war-room.emoji.failed')));
        });
    }

    /* ── quoting ── */

    /** The quote box at the top of a message: author, time and the start of the original, or why it is not there. */
    function renderQuote(q) {
        var box = el('button', 'wr-quote');
        box.type = 'button';
        if (q.gone) {
            box.appendChild(el('span', 'wr-quote-text wr-tombstone', t('war-room.quote.gone')));
            box.disabled = true;
            return box;
        }
        var head = el('span', 'wr-quote-head');
        head.appendChild(el('span', 'wr-quote-author', q.author));
        head.appendChild(el('span', 'wr-msg-time', localTime(q.created)));
        box.appendChild(head);
        box.appendChild(el('span', 'wr-quote-text' + (q.deleted ? ' wr-tombstone' : ''),
            q.deleted ? t('war-room.quote.deleted') : q.text));
        box.title = t('war-room.quote.jump');
        box.addEventListener('click', function () { highlightMessage(q.id, false); });
        return box;
    }

    var quotingEl = el('div', 'wr-quoting');
    quotingEl.hidden = true;
    els.wrComposer.parentNode.insertBefore(quotingEl, els.wrComposer);

    /** Starts quoting a message: a bar above the box says so, with a cross to stop. */
    function startQuote(m) {
        quoting = m;
        clear(quotingEl);
        quotingEl.appendChild(el('span', 'wr-quoting-label', t('war-room.quote.quoting', { name: m.author })));
        quotingEl.appendChild(el('span', 'wr-quoting-text', String(m.body || '').slice(0, 120)));
        var x = el('button', 'wr-chip-x', '\u00D7');
        x.type = 'button';
        x.setAttribute('aria-label', t('war-room.quote.cancel'));
        x.addEventListener('click', clearQuote);
        quotingEl.appendChild(x);
        quotingEl.hidden = false;
        els.wrBody.focus();
    }
    /** Stops quoting. */
    function clearQuote() { quoting = null; quotingEl.hidden = true; clear(quotingEl); }

    /* ── copying ── */

    /** Copies through the app's shared helper (assets/js/clipboard.js), which also works on plain HTTP. */
    function copyText(text, doneKey) {
        var p = (typeof window.copyToClipboard === 'function') ? window.copyToClipboard(text)
              : (navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(text).then(function () { return true; }) : Promise.resolve(false));
        p.then(function (ok) { toast(t(ok ? doneKey : 'war-room.menu.copy_failed')); }, function () { toast(t('war-room.menu.copy_failed')); });
    }
    /** Copies the message's text with its author and time; attachments by name. */
    function copyMessage(m) {
        var text = m.author + ' (' + localTime(m.created) + '):\n' + String(m.body || '').trim();
        (m.attachments || []).forEach(function (a) { text += '\n[' + a.name + ']'; });
        copyText(text, 'war-room.menu.copied');
    }
    /** Copies a permanent link to the message, by database id. */
    function copyLink(m) {
        copyText((window.WR_LINK_BASE || '') + '?channel=' + activeId + '&message=' + m.id, 'war-room.menu.link_copied');
    }

    /* ── showing one message ── */

    /**
     * Scrolls to a message and highlights it. If it is not on the page (older than
     * what was loaded), the channel is reloaded around it.
     * @param {boolean} fromLink  true on arrival from a permanent link
     */
    function highlightMessage(id, fromLink) {
        var node = els.wrMessages.querySelector('[data-msg-id="' + id + '"]');
        if (!node) {
            if (fromLink) { toast(t('war-room.link.missing')); return; }
            focusId = id; lastId = 0;
            clear(els.wrMessages); els.wrMessages.appendChild(els.wrEmpty);
            poll();
            return;
        }
        node.scrollIntoView({ block: 'center' });
        node.classList.remove('wr-msg-flash');
        void node.offsetWidth;   // restart the animation if it is already running
        node.classList.add('wr-msg-flash');
        setTimeout(function () { node.classList.remove('wr-msg-flash'); }, 2600);
    }

    /* ── opening attachments ── */

    /**
     * A click on an attachment opens it in the shared viewer (FilesViewer, as Files and
     * Tickets do): PDFs, Word, Excel, images, media, text, zips - in a window you can
     * move and resize, with Download beside it. A modified click, or a type the viewer
     * cannot show, still follows the link.
     */
    function viewOnClick(link, a, href) {
        link.addEventListener('click', function (e) {
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            if (!window.FilesViewer || !window.FilesViewer.openUrl) return;
            var w = window.FilesViewer.openUrl({ name: a.name, src: href, downloadUrl: href, size: a.size, key: 'wr-att-' + a.id });
            if (w) e.preventDefault();
        });
    }

    /* ── a passing note ── */

    var toastEl = null, toastTimer = null;
    /** Shows a short note at the bottom of the window for a couple of seconds. */
    function toast(text) {
        if (!toastEl) {
            toastEl = el('div', 'wr-toast');
            toastEl.setAttribute('role', 'status');
            document.body.appendChild(toastEl);
        }
        toastEl.textContent = text;
        toastEl.classList.add('is-on');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('is-on'); }, 2400);
    }

    /* ── edit and delete ─────────────────────────────────────────────────────── */

    /** Opens the edit modal for one of the analyst's own messages; Save sends the new body through messageAction. */
    function openEdit(m) {
        var body = openModal(t('war-room.message.edit_heading'));
        var ta = document.createElement('textarea');
        ta.className = 'wr-input wr-edit-area';
        ta.rows = 5;
        ta.value = m.body;
        body.appendChild(ta);
        body.appendChild(el('div', 'wr-hint', t('war-room.message.edit_hint')));

        var actions = el('div', 'wr-modal-actions');
        var save = el('button', 'btn btn-primary', t('war-room.manage.save'));
        save.type = 'button';
        save.addEventListener('click', function () {
            messageAction('edit', { id: m.id, body: ta.value });
        });
        actions.appendChild(save);
        var cancel = el('button', 'btn', t('war-room.create.cancel'));
        cancel.type = 'button';
        cancel.addEventListener('click', closeModal);
        actions.appendChild(cancel);
        body.appendChild(actions);
        ta.focus();
    }

    /** Opens the delete confirmation for a message; confirming sends the delete through messageAction. */
    function openDelete(m) {
        var body = openModal(t('war-room.message.delete_heading'));
        body.appendChild(el('p', 'wr-muted', t('war-room.message.delete_confirm')));
        // Said out loud, because it is not what most chat tools do and somebody
        // deleting a pasted password deserves to know what will remain.
        body.appendChild(el('div', 'wr-hint', t('war-room.message.delete_hint')));

        var actions = el('div', 'wr-modal-actions');
        var go = el('button', 'btn btn-primary', t('war-room.message.delete'));
        go.type = 'button';
        go.addEventListener('click', function () { messageAction('delete', { id: m.id }); });
        actions.appendChild(go);
        var cancel = el('button', 'btn', t('war-room.create.cancel'));
        cancel.type = 'button';
        cancel.addEventListener('click', closeModal);
        actions.appendChild(cancel);
        body.appendChild(actions);
    }

    /**
     * Posts an edit or delete to message.php, then reloads the whole channel because the changed message is behind the poll watermark.
     */
    function messageAction(action, payload) {
        payload.action = action;
        fetch(api + 'message.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) throw new Error('failed');
                closeModal();
                // An edit or a delete changes a message the page already holds, so
                // reload the channel rather than appending: the poll's since_id
                // watermark is past it.
                lastId = 0;
                clear(els.wrMessages);
                els.wrMessages.appendChild(els.wrEmpty);
                poll();
            })
            .catch(function () { alert(t('war-room.message.failed')); });
    }

    /**
     * Sends the composer text and any pending files to send.php (multipart when files are attached), reports refused files, and asks Warbot when addressed.
     */
    function send() {
        var body = els.wrBody.value;
        if (!body.trim() && !pending.length) return;

        els.wrSend.disabled = true;
        /** Re-enables the Send button when the request settles. */
        var done = function () { els.wrSend.disabled = false; };

        var request;
        if (pending.length) {
            var fd = new FormData();
            fd.append('channel_id', activeId);
            fd.append('body', body);
            if (quoting) fd.append('quote_of_id', quoting.id);
            pending.forEach(function (f) { fd.append('files[]', f); });
            request = fetch(api + 'send.php', { method: 'POST', credentials: 'same-origin', body: fd });
        } else {
            request = fetch(api + 'send.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ channel_id: activeId, body: body, quote_of_id: quoting ? quoting.id : 0 })
            });
        }

        request.then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) throw new Error(d && d.error || 'send failed');
                els.wrBody.value = '';
                pending = [];
                renderPending();
                clearQuote();
                // A file we refused is reported by name. Posting the message and
                // silently dropping the screenshot would be the worst outcome:
                // the sender believes everyone can see it.
                if (d.rejected && d.rejected.length) {
                    alert(t('war-room.attach.rejected', {
                        names: d.rejected.map(function (r) { return r.name + ' — ' + r.reason; }).join('; ')
                    }));
                }
                poll();
                // Warbot is asked for AFTER the send returns, not inside it: an
                // answer can take several model round trips, and no message in the
                // room should wait for one. The endpoint re-checks everything and
                // refuses to answer the same message twice, so triggering it from
                // here is safe even though anyone could.
                if (addressesWarbot(body) && d.id) askWarbot(d.id);
            })
            .catch(function () { alert(t('war-room.error.send')); })
            .then(done, done);
    }

    /* ── Warbot ────────────────────────────────────────────────────────────── */

    // Kept in step with warbotIsAddressed() and warbotCommands() on the server.
    // If they ever disagree the server wins: it re-checks before answering, so
    // the worst a mismatch here causes is a wasted request or a missed trigger.
    var WARBOT_COMMANDS = /^\s*\/(p1|open|status|changes|oncall|asset|impact|kb|help)\b/i;
    /** True when the text mentions @warbot or starts with a Warbot slash command. */
    function addressesWarbot(text) {
        return /@warbot\b/i.test(text) || WARBOT_COMMANDS.test(text);
    }

    /** Shows a "thinking" placeholder and asks warbot.php to answer a message; the next poll brings in the reply. */
    function askWarbot(messageId) {
        // A "thinking" line, removed by the next poll when the real answer lands.
        // Without it the room looks like Warbot ignored you for several seconds,
        // which during an incident is exactly when people give up on a tool.
        var pending = el('div', 'wr-msg wr-msg-bot wr-bot-thinking');
        var head = el('div', 'wr-msg-head');
        head.appendChild(el('span', 'wr-msg-author', 'Warbot'));
        head.appendChild(el('span', 'wr-bot-tag', t('war-room.warbot.tag')));
        pending.appendChild(head);
        pending.appendChild(el('div', 'wr-msg-body wr-muted', t('war-room.warbot.thinking')));
        els.wrMessages.appendChild(pending);
        els.wrMessages.scrollTop = els.wrMessages.scrollHeight;

        fetch(api + 'warbot.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message_id: messageId })
        })
            .then(function (r) { return r.json(); })
            .catch(function () { /* the poll will show whatever did or did not land */ })
            .then(function () {
                if (pending.parentNode) pending.parentNode.removeChild(pending);
                poll();
            });
    }

    /* ── panels (search, situation report) ─────────────────────────────────── */

    /** Opens the side panel (search or situation report) with a title and returns its empty body. */
    function openPanel(title) {
        els.wrPanelTitle.textContent = title;
        clear(els.wrPanelBody);
        els.wrPanel.hidden = false;
        return els.wrPanelBody;
    }
    /** Hides the side panel. */
    function closePanel() { els.wrPanel.hidden = true; }
    els.wrPanelClose.addEventListener('click', closePanel);

    els.wrSearchBtn.addEventListener('click', function () {
        var body = openPanel(t('war-room.search.heading'));

        var input = document.createElement('input');
        input.type = 'search';
        input.className = 'wr-search-input';
        input.placeholder = t('war-room.search.placeholder');
        body.appendChild(input);

        var scope = el('label', 'wr-scope');
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        scope.appendChild(cb);
        scope.appendChild(el('span', null, t('war-room.search.this_channel')));
        body.appendChild(scope);

        var results = el('div', 'wr-results');
        body.appendChild(results);

        var debounce = null;
        /**
         * Runs the message search through search.php (all channels, or this one when ticked) and lists the hits, each one switching to its channel.
         */
        function run() {
            var q = input.value.trim();
            clear(results);
            if (!q) return;
            results.appendChild(el('div', 'wr-muted', t('war-room.search.searching')));
            var url = api + 'search.php?q=' + encodeURIComponent(q) +
                      (cb.checked ? '&channel_id=' + encodeURIComponent(activeId) : '');
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    clear(results);
                    if (!d || !d.success) throw new Error('search failed');
                    if (!d.results.length) { results.appendChild(el('div', 'wr-muted', t('war-room.search.no_results'))); return; }
                    results.appendChild(el('div', 'wr-muted', t('war-room.search.results', { count: d.results.length })));
                    d.results.forEach(function (r) {
                        var item = el('button', 'wr-result');
                        item.type = 'button';
                        var head = el('div', 'wr-result-head');
                        head.appendChild(el('span', 'wr-result-channel', r.channel));
                        head.appendChild(el('span', 'wr-result-meta', r.author + ' · ' + localTime(r.created)));
                        item.appendChild(head);
                        item.appendChild(el('div', 'wr-result-snippet', r.snippet));
                        item.addEventListener('click', function () { switchChannel(r.channel_id); });
                        results.appendChild(item);
                    });
                })
                .catch(function () { clear(results); results.appendChild(el('div', 'wr-muted', t('war-room.search.failed'))); });
        }
        input.addEventListener('input', function () { clearTimeout(debounce); debounce = setTimeout(run, 250); });
        cb.addEventListener('change', run);
        input.focus();
    });

    els.wrSitrepBtn.addEventListener('click', function () {
        var body = openPanel(t('war-room.sitrep.heading'));
        body.appendChild(el('p', 'wr-muted', t('war-room.sitrep.intro')));

        var form = el('div', 'wr-sitrep-form');
        form.appendChild(el('label', 'wr-field-label', t('war-room.sitrep.since')));
        var hours = document.createElement('select');
        [[0.5, t('war-room.sitrep.minutes', { count: 30 })],
         [1, t('war-room.sitrep.hour')],
         [2, t('war-room.sitrep.hours', { count: 2 })],
         [4, t('war-room.sitrep.hours', { count: 4 })],
         [8, t('war-room.sitrep.hours', { count: 8 })],
         [24, t('war-room.sitrep.hours', { count: 24 })]].forEach(function (o) {
            var opt = document.createElement('option');
            opt.value = o[0]; opt.textContent = o[1];
            if (o[0] === 4) opt.selected = true;
            hours.appendChild(opt);
        });
        form.appendChild(hours);

        var scope = document.createElement('select');
        [['', t('war-room.sitrep.scope_all')], ['this', t('war-room.sitrep.scope_this')]].forEach(function (o) {
            var opt = document.createElement('option');
            opt.value = o[0]; opt.textContent = o[1];
            scope.appendChild(opt);
        });
        form.appendChild(scope);

        var go = el('button', 'btn btn-primary wr-sitrep-go', t('war-room.sitrep.generate'));
        go.type = 'button';
        form.appendChild(go);
        body.appendChild(form);

        var out = el('div', 'wr-sitrep-out');
        body.appendChild(out);

        go.addEventListener('click', function () {
            clear(out);
            out.appendChild(el('div', 'wr-muted', t('war-room.sitrep.working')));
            go.disabled = true;
            fetch(api + 'sitrep.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    hours: parseFloat(hours.value),
                    channel_id: scope.value === 'this' ? activeId : null
                })
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    clear(out);
                    if (d && d.success && d.empty) { out.appendChild(el('div', 'wr-muted', t('war-room.sitrep.empty'))); return; }
                    if (!d || !d.success) {
                        var key = d && d.error === 'not_configured' ? 'war-room.sitrep.not_configured'
                                : d && d.error === 'provider_unreachable' ? 'war-room.sitrep.unreachable'
                                : 'war-room.sitrep.failed';
                        out.appendChild(el('div', 'wr-warn', t(key)));
                        return;
                    }
                    // The report is the model's prose. It is inserted as TEXT in a
                    // pre-wrap block — no markdown rendering — because turning
                    // model output into HTML is exactly the shortcut that would
                    // undo the rule at the top of this file.
                    out.appendChild(el('div', 'wr-report', d.report));

                    var foot = el('div', 'wr-report-foot');
                    foot.appendChild(el('span', null, t('war-room.sitrep.footer', { messages: d.messages, model: d.model })));
                    var copy = el('button', 'wr-tool', t('war-room.sitrep.copy'));
                    copy.type = 'button';
                    copy.addEventListener('click', function () {
                        // One helper - and only say "Copied" if it was.
                        copyToClipboard(d.report).then(function (ok) {
                            if (ok) copy.textContent = t('war-room.sitrep.copied');
                        });
                    });
                    foot.appendChild(copy);
                    out.appendChild(foot);
                })
                .catch(function () { clear(out); out.appendChild(el('div', 'wr-warn', t('war-room.sitrep.failed'))); })
                .then(function () { go.disabled = false; }, function () { go.disabled = false; });
        });
    });

    /* ── dialogs (new channel, new DM, manage) ─────────────────────────────── */

    /** Opens the shared dialog with a title and returns its empty body. */
    function openModal(title) {
        els.wrModalTitle.textContent = title;
        clear(els.wrModalBody);
        els.wrModal.hidden = false;
        return els.wrModalBody;
    }
    /** Hides the shared dialog. */
    function closeModal() { els.wrModal.hidden = true; }
    els.wrModalClose.addEventListener('click', closeModal);
    els.wrModal.addEventListener('click', function (e) { if (e.target === els.wrModal) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeModal(); closePanel(); }
    });

    /** Appends a label and a control to a dialog body and returns the control. */
    function field(parent, labelText, node) {
        parent.appendChild(el('label', 'wr-field-label', labelText));
        parent.appendChild(node);
        return node;
    }

    /**
     * Posts a channel action (create, dm, update, archive, restore) to channels.php; calls onOk on success, alerts with failKey on failure.
     */
    function post(action, payload, onOk, failKey) {
        payload.action = action;
        fetch(api + 'channels.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) throw new Error(d && d.error || 'failed');
                onOk(d);
            })
            .catch(function (err) {
                alert(err.message === 'name_required' ? t('war-room.create.name_required') : t(failKey));
            });
    }

    els.wrNewChannel.addEventListener('click', function () {
        var body = openModal(t('war-room.create.heading'));

        var name = document.createElement('input');
        name.type = 'text'; name.className = 'wr-input'; name.maxLength = 120;
        field(body, t('war-room.create.name'), name);
        body.appendChild(el('div', 'wr-hint', t('war-room.create.name_hint')));

        var topic = document.createElement('input');
        topic.type = 'text'; topic.className = 'wr-input'; topic.maxLength = 255;
        field(body, t('war-room.create.topic'), topic);

        var privWrap = el('label', 'wr-scope');
        var priv = document.createElement('input');
        priv.type = 'checkbox';
        privWrap.appendChild(priv);
        privWrap.appendChild(el('span', null, t('war-room.create.private')));
        body.appendChild(privWrap);

        // The member picker only appears for a private channel, because for a
        // public one the answer is "everybody" and offering a list would imply
        // otherwise.
        var members = el('div', 'wr-members');
        members.hidden = true;
        directory.forEach(function (p) {
            var row = el('label', 'wr-member');
            var cb = document.createElement('input');
            cb.type = 'checkbox'; cb.value = p.id;
            row.appendChild(cb);
            row.appendChild(el('span', null, p.name));
            if (p.here) row.appendChild(el('span', 'wr-here-dot', '•'));
            members.appendChild(row);
        });
        body.appendChild(members);
        priv.addEventListener('change', function () { members.hidden = !priv.checked; });

        var actions = el('div', 'wr-modal-actions');
        var create = el('button', 'btn btn-primary', t('war-room.create.create'));
        create.type = 'button';
        create.addEventListener('click', function () {
            var chosen = Array.prototype.slice.call(members.querySelectorAll('input:checked'))
                .map(function (c) { return parseInt(c.value, 10); });
            post('create', {
                name: name.value, topic: topic.value,
                is_private: priv.checked, members: chosen
            }, function (d) {
                closeModal();
                poll();
                switchChannel(d.channel_id);
            }, 'war-room.create.failed');
        });
        actions.appendChild(create);
        var cancel = el('button', 'btn', t('war-room.create.cancel'));
        cancel.type = 'button';
        cancel.addEventListener('click', closeModal);
        actions.appendChild(cancel);
        body.appendChild(actions);
        name.focus();
    });

    els.wrNewDm.addEventListener('click', function () {
        var body = openModal(t('war-room.dm.heading'));
        if (!directory.length) { body.appendChild(el('div', 'wr-muted', t('war-room.dm.nobody'))); return; }

        var search = document.createElement('input');
        search.type = 'search'; search.className = 'wr-input';
        search.placeholder = t('war-room.dm.search');
        body.appendChild(search);

        var list = el('div', 'wr-members');
        body.appendChild(list);

        /** Lists the directory people matching the filter, each a button that opens a direct message with them. */
        function draw(filter) {
            clear(list);
            directory.filter(function (p) {
                return !filter || p.name.toLowerCase().indexOf(filter) >= 0;
            }).forEach(function (p) {
                var row = el('button', 'wr-member wr-member-btn');
                row.type = 'button';
                row.appendChild(el('span', null, p.name));
                if (p.here) row.appendChild(el('span', 'wr-here-dot', '• ' + t('war-room.dm.here_now')));
                row.addEventListener('click', function () {
                    /** Opens (or reuses) the direct-message channel with this analyst, then switches to it. */
                    post('dm', { analyst_id: p.id }, function (d) {
                        closeModal();
                        poll();
                        switchChannel(d.channel_id);
                    }, 'war-room.dm.failed');
                });
                list.appendChild(row);
            });
        }
        draw('');
        search.addEventListener('input', function () { draw(search.value.trim().toLowerCase()); });
        search.focus();
    });

    els.wrManageBtn.addEventListener('click', function () {
        var c = currentChannel();
        if (!c || c.kind !== 'custom') return;
        var body = openModal(t('war-room.manage.heading'));

        var name = document.createElement('input');
        name.type = 'text'; name.className = 'wr-input'; name.maxLength = 120; name.value = c.name;
        field(body, t('war-room.create.name'), name);

        var topic = document.createElement('input');
        topic.type = 'text'; topic.className = 'wr-input'; topic.maxLength = 255; topic.value = c.topic || '';
        field(body, t('war-room.create.topic'), topic);

        body.appendChild(el('div', 'wr-hint', t('war-room.manage.archive_hint')));

        var actions = el('div', 'wr-modal-actions');
        var save = el('button', 'btn btn-primary', t('war-room.manage.save'));
        save.type = 'button';
        save.addEventListener('click', function () {
            post('update', { channel_id: c.id, name: name.value, topic: topic.value },
                function () { closeModal(); poll(); }, 'war-room.manage.failed');
        });
        actions.appendChild(save);

        var arch = el('button', 'btn', c.archived ? t('war-room.manage.restore') : t('war-room.manage.archive'));
        arch.type = 'button';
        arch.addEventListener('click', function () {
            post(c.archived ? 'restore' : 'archive', { channel_id: c.id },
                function () { closeModal(); poll(); }, 'war-room.manage.failed');
        });
        actions.appendChild(arch);

        var cancel = el('button', 'btn', t('war-room.create.cancel'));
        cancel.type = 'button';
        cancel.addEventListener('click', closeModal);
        actions.appendChild(cancel);
        body.appendChild(actions);
    });

    /* ── desktop notifications (per-analyst, off by default) ─────────────────── */

    var alertsBox = document.getElementById('wrDesktopAlerts');
    if (alertsBox) {
        alertsBox.addEventListener('change', function () {
            var on = alertsBox.checked;
            /** Saves the analyst's desktop-alerts preference (warroom_desktop_alerts) through WR_PREF_URL. */
            var save = function (value) {
                fetch(window.WR_PREF_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ key: 'warroom_desktop_alerts', value: value ? '1' : '0' })
                });
            };
            if (!on) { save(false); return; }

            // Permission is asked for HERE, on a deliberate click, not on page
            // load: a browser refuses a prompt that was not user-initiated, and
            // an unprompted permission popup is what teaches people to click Block.
            if (!('Notification' in window)) { alertsBox.checked = false; return; }
            if (Notification.permission === 'granted') { save(true); return; }
            if (Notification.permission === 'denied') {
                alertsBox.checked = false;
                alert(t('war-room.mention.desktop_blocked'));
                return;
            }
            Notification.requestPermission().then(function (p) {
                var ok = (p === 'granted');
                alertsBox.checked = ok;
                save(ok);
                if (!ok) alert(t('war-room.mention.desktop_blocked'));
            });
        });
    }

    /* ── go ────────────────────────────────────────────────────────────────── */

    if (window.WR_FOCUS_MISSING) toast(t('war-room.link.missing'));

    poll();
    startPolling();

    // Polling stops while the tab is hidden and resumes with an immediate fetch,
    // so twenty backgrounded tabs are not quietly hammering the server through an
    // incident — the exact moment it can least afford it.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { clearInterval(timer); timer = null; }
        else { poll(); startPolling(); }
    });
})();
