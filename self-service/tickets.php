<?php
/**
 * Self-Service Portal — My Tickets (two-pane, Outlook-style).
 *
 * Replaces the dashboard→ticket.php round trip: the list stays on screen and
 * the conversation loads beside it, so moving between tickets doesn't reload
 * the page. ticket.php now redirects here, keeping old links working.
 *
 * The layout is the tickets inbox's shape, one pane lighter: the analyst needs
 * folders because they triage everyone's work — a customer only ever has their
 * own tickets, so a folder pane would be an empty gesture.
 */
$pageTitleKey = 'self-service.tickets.title';
$activeNav    = 'tickets';
// App-shell page: the panes scroll internally, the window does not.
$bodyClass    = 'portal-app';

$pageData = ['ticketId' => (int)($_GET['id'] ?? 0)];

$pageStyles = <<<'CSS'
/* Two panes filling the viewport under the 48px header, each scrolling its own
   body — the same structure as the analyst inbox (.main-container). */
.tk-shell { display: flex; height: calc(100vh - 48px); }

.tk-list {
    width: 380px;
    flex-shrink: 0;
    background: var(--surface, #fff);
    border-right: 1px solid var(--border, #e5e7eb);
    display: flex;
    flex-direction: column;
}
.tk-list-head {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border, #e5e7eb);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.tk-list-head h2 { font-size: 15px; font-weight: 600; margin: 0; color: var(--text, #333); }
.tk-filter {
    padding: 5px 8px;
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 6px;
    background: var(--surface, #fff);
    color: var(--text, #333);
    font-family: inherit;
    font-size: 12px;
    max-width: 170px;
}
.tk-filter:focus { outline: none; border-color: var(--ss-accent, #10b981); }

.tk-list-body { flex: 1; overflow-y: auto; }

.tk-item {
    padding: 12px 18px;
    border-bottom: 1px solid var(--border-soft, #f0f0f0);
    cursor: pointer;
    transition: background 0.15s;
    display: block;
    width: 100%;
    text-align: left;
    background: none;
    border-left: 3px solid transparent;
    font-family: inherit;
}
.tk-item:hover { background: var(--surface-hover, #f8f8f8); }
.tk-item.selected {
    background: var(--ss-accent-soft, #d1fae5);
    border-left-color: var(--ss-accent, #10b981);
}
.tk-item-top { display: flex; align-items: center; gap: 8px; margin-bottom: 3px; }
.tk-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.tk-item-subject {
    font-weight: 600;
    font-size: 13px;
    color: var(--text, #333);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
}
.tk-item-date { font-size: 11px; color: var(--text-faint, #999); flex-shrink: 0; }
.tk-item-preview {
    font-size: 12px;
    color: var(--text-muted, #666);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    margin-left: 16px;
}
.tk-item-meta { font-size: 11px; color: var(--text-faint, #999); margin-left: 16px; margin-top: 3px; }

/* ── Reading pane ─────────────────────────────────────────────────────── */
.tk-read { flex: 1; display: flex; flex-direction: column; min-width: 0; background: var(--app-bg, #f5f5f5); }
.tk-read-empty {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-faint, #999);
    font-size: 14px;
}
.tk-read-head {
    padding: 16px 24px;
    background: var(--surface, #fff);
    border-bottom: 1px solid var(--border, #e5e7eb);
    flex-shrink: 0;
}
.tk-read-subject { font-size: 17px; font-weight: 600; color: var(--text, #333); margin: 0 0 8px 0; }
.tk-read-meta { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; font-size: 12px; color: var(--text-muted, #666); }
.tk-num {
    font-family: 'Consolas', 'Courier New', monospace;
    background: var(--surface-hover, #f3f4f6);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    color: var(--text-muted, #555);
}

.tk-thread { flex: 1; overflow-y: auto; padding: 20px 24px; }

/* One message. Inbound (theirs) and outbound (the desk's) are distinguished by
   the avatar colour and a tinted card, not by left/right alignment — chat
   bubbles read oddly for a support thread that includes system notes. */
.tk-msg { display: flex; gap: 12px; margin-bottom: 18px; }
.tk-avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700;
    background: var(--surface-hover, #e5e7eb);
    color: var(--text-muted, #666);
}
.tk-msg.is-mine .tk-avatar { background: var(--ss-accent, #10b981); color: #fff; }
.tk-msg-body { flex: 1; min-width: 0; }
.tk-msg-head { display: flex; align-items: baseline; gap: 8px; margin-bottom: 4px; }
.tk-msg-who  { font-size: 13px; font-weight: 600; color: var(--text, #333); }
.tk-msg-when { font-size: 11px; color: var(--text-faint, #999); }
.tk-msg-card {
    background: var(--surface, #fff);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px;
    padding: 14px 16px;
    font-size: 14px;
    line-height: 1.6;
    color: var(--text, #333);
    overflow-wrap: break-word;
}
.tk-msg.is-mine .tk-msg-card { background: var(--ss-accent-soft, #d1fae5); border-color: transparent; }
.tk-msg.is-note .tk-msg-card { background: var(--warning-bg, #fef3c7); border-color: transparent; }
.tk-msg-card img { max-width: 100%; height: auto; }
.tk-msg-card table { max-width: 100%; }

.tk-att { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.tk-att a {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 10px;
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 6px;
    background: var(--surface, #fff);
    color: var(--text, #333);
    font-size: 12px;
    text-decoration: none;
    max-width: 260px;
}
.tk-att a:hover { border-color: var(--ss-accent, #10b981); }
.tk-att-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tk-att-size { color: var(--text-muted, #777); flex-shrink: 0; }

/* ── Composer, docked at the bottom of the pane ───────────────────────── */
.tk-composer {
    flex-shrink: 0;
    border-top: 1px solid var(--border, #e5e7eb);
    background: var(--surface, #fff);
    padding: 12px 24px 16px;
}
.tk-composer textarea {
    width: 100%;
    box-sizing: border-box;
    min-height: 74px;
    padding: 10px 12px;
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px;
    background: var(--surface, #fff);
    color: var(--text, #333);
    font-family: inherit;
    font-size: 14px;
    line-height: 1.5;
    resize: vertical;
}
.tk-composer textarea:focus { outline: none; border-color: var(--ss-accent, #10b981); }
.tk-composer-actions { display: flex; align-items: center; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
.tk-composer-files { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.tk-chip {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 5px 10px;
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 6px;
    background: var(--surface-hover, #fafafa);
    font-size: 12px;
    color: var(--text, #333);
}
.tk-chip button { border: none; background: none; cursor: pointer; color: var(--text-muted, #777); font-size: 15px; line-height: 1; padding: 0; }
.tk-chip button:hover { color: var(--danger-text, #c33); }
.tk-note-line { font-size: 12px; color: var(--text-muted, #666); }

@media (max-width: 900px) {
    .tk-shell { flex-direction: column; height: auto; }
    .tk-list  { width: 100%; max-height: 42vh; }
    .tk-read  { min-height: 60vh; }
}
CSS;

$pageScripts = <<<'JS'
let ssTickets = [];
        let ssSelected = null;
        let ssFilter = '';        // '' = every status
        let ssFiles = [];

        document.addEventListener('DOMContentLoaded', function () {
            const sel = document.getElementById('tkFilter');
            if (sel) sel.addEventListener('change', function () { ssFilter = sel.value; renderList(); });
            loadTickets();
        });

        async function loadTickets() {
            try {
                const r = await fetch('../api/self-service/get_tickets.php');
                const d = await r.json();
                ssTickets = d.success ? (d.tickets || []) : [];
                renderFilterOptions();
                renderList();

                // What to show, in order of precedence:
                //   1. whatever is ALREADY selected — this runs again after a
                //      reply, and forgetting the open ticket then (and rewriting
                //      the URL to the first one) is exactly the bug that caused;
                //   2. a deep link, on first load;
                //   3. the first ticket, so the pane is never empty for someone
                //      who does have tickets.
                const first = visibleTickets()[0];
                const wanted = ssSelected || window.PAGE.ticketId || (first ? first.id : 0);
                // Awaited: an un-awaited select here would race the caller's own.
                if (wanted) await selectTicket(wanted);
            } catch (e) {
                document.getElementById('tkList').innerHTML =
                    '<div class="loading-state">' + esc(window.t('self-service.tickets.load_failed')) + '</div>';
            }
        }

        /**
         * The status dropdown is built from the statuses actually present on this
         * person's tickets, not a hardcoded Open/Closed pair — a service desk can
         * configure any statuses it likes ("Awaiting Response", "On Hold", …), and
         * offering only what they have means no choice ever comes back empty.
         * Counts are shown so the list's size is obvious before selecting.
         */
        function renderFilterOptions() {
            const sel = document.getElementById('tkFilter');
            if (!sel) return;

            const order = [];
            const counts = {};
            ssTickets.forEach(t => {
                const s = t.status || '';
                if (!s) return;
                if (order.indexOf(s) === -1) order.push(s);
                counts[s] = (counts[s] || 0) + 1;
            });
            order.sort((a, b) => a.localeCompare(b));

            sel.innerHTML = '<option value="">'
                + esc(window.t('self-service.tickets.filter_all')) + ' (' + ssTickets.length + ')</option>'
                + order.map(s => '<option value="' + esc(s) + '">' + esc(s) + ' (' + counts[s] + ')</option>').join('');
            sel.value = ssFilter;                 // keep the choice across refreshes
            if (sel.value !== ssFilter) ssFilter = '';   // status vanished → fall back to All
        }

        function visibleTickets() {
            if (!ssFilter) return ssTickets;
            return ssTickets.filter(t => (t.status || '') === ssFilter);
        }

        function renderList() {
            const host = document.getElementById('tkList');
            const list = visibleTickets();

            if (!list.length) {
                host.innerHTML = '<div class="loading-state">' + esc(window.t('self-service.tickets.none')) + '</div>';
                return;
            }

            host.innerHTML = list.map(t => {
                const c = t.status_colour || '#0078d4';
                return '<button type="button" class="tk-item' + (ssSelected == t.id ? ' selected' : '') + '" onclick="selectTicket(' + t.id + ')">'
                     +   '<div class="tk-item-top">'
                     +     '<span class="tk-dot" style="background:' + esc(c) + '"></span>'
                     +     '<span class="tk-item-subject">' + esc(t.subject || '') + '</span>'
                     +     '<span class="tk-item-date">' + esc(shortDate(t.updated_datetime || t.created_datetime)) + '</span>'
                     +   '</div>'
                     +   (t.preview ? '<div class="tk-item-preview">' + esc(stripTags(t.preview)) + '</div>' : '')
                     +   '<div class="tk-item-meta">' + esc(t.ticket_number || '') + ' &middot; ' + esc(t.status || '') + '</div>'
                     + '</button>';
            }).join('');
        }

        async function selectTicket(id) {
            ssSelected = id;
            ssFiles = [];
            renderList();
            // Keep the address bar in step so the ticket can be linked/bookmarked.
            // Guarded: history writes throw in some contexts (file:// origins,
            // strict embedders), and a URL nicety must never stop the ticket
            // itself loading.
            try {
                if (window.history && window.history.replaceState) {
                    window.history.replaceState({ ticketId: id }, '', 'tickets.php?id=' + id);
                }
            } catch (e) { /* not fatal */ }

            const pane = document.getElementById('tkRead');
            pane.innerHTML = '<div class="tk-read-empty">' + esc(window.t('self-service.tickets.loading')) + '</div>';

            try {
                const r = await fetch('../api/self-service/get_ticket_detail.php?ticket_id=' + encodeURIComponent(id));
                const d = await r.json();
                if (!d.success) {
                    pane.innerHTML = '<div class="tk-read-empty">' + esc(d.error || window.t('self-service.tickets.load_failed')) + '</div>';
                    return;
                }
                renderTicket(d);
            } catch (e) {
                pane.innerHTML = '<div class="tk-read-empty">' + esc(window.t('self-service.tickets.load_failed')) + '</div>';
            }
        }

        function renderTicket(d) {
            const t = d.ticket;
            const pane = document.getElementById('tkRead');
            const c = t.status_colour || '#0078d4';

            // Merge messages and shared notes into one chronological conversation.
            const items = [];
            (d.thread || []).forEach(m => items.push({ kind: 'msg', data: m, at: m.received_datetime }));
            (d.notes  || []).forEach(n => items.push({ kind: 'note', data: n, at: n.created_datetime }));
            items.sort((a, b) => new Date(a.at) - new Date(b.at));

            const msgs = items.map(item => {
                if (item.kind === 'note') {
                    const n = item.data;
                    return messageHtml({
                        who: n.analyst_name || window.t('self-service.ticket.support'),
                        when: n.created_datetime,
                        html: esc(n.note_text || '').replace(/\n/g, '<br>'),
                        cls: 'is-note',
                        badge: window.t('self-service.ticket.note')
                    });
                }
                const m = item.data;
                const mine = String(m.direction || '').toLowerCase() === 'portal'
                          || String(m.direction || '').toLowerCase() === 'inbound';
                return messageHtml({
                    who: m.from_name || window.t('self-service.ticket.unknown_sender'),
                    when: m.received_datetime,
                    html: safeBody(m.body_content, m.body_type),
                    cls: mine ? 'is-mine' : '',
                    atts: m.attachments || []
                });
            }).join('');

            pane.innerHTML =
                '<div class="tk-read-head">'
              +   '<h1 class="tk-read-subject">' + esc(t.subject || '') + '</h1>'
              +   '<div class="tk-read-meta">'
              +     '<span class="tk-num">' + esc(t.ticket_number || '') + '</span>'
              +     '<span class="status-badge" style="background-color:' + esc(c) + '1f;color:' + esc(c) + ';border:1px solid ' + esc(c) + '33">' + esc(t.status || '') + '</span>'
              +     '<span>' + esc(t.priority || '') + '</span>'
              +     (t.department_name ? '<span>' + esc(t.department_name) + '</span>' : '')
              +     '<span>' + esc(window.t('self-service.ticket.created', { date: fullDate(t.created_datetime) })) + '</span>'
              +   '</div>'
              + '</div>'
              + '<div class="tk-thread" id="tkThread">' + (msgs || '<div class="loading-state">' + esc(window.t('self-service.ticket.no_conversation')) + '</div>') + '</div>'
              + composerHtml();

            wireComposer();
            const thread = document.getElementById('tkThread');
            if (thread) thread.scrollTop = thread.scrollHeight;   // newest first to the eye
        }

        function messageHtml(o) {
            const atts = (o.atts && o.atts.length)
                ? '<div class="tk-att">' + o.atts.map(a =>
                    '<a href="../api/self-service/get_attachment.php?id=' + encodeURIComponent(a.id) + '" target="_blank" rel="noopener">'
                    + '<span class="tk-att-name">' + esc(a.filename || '') + '</span>'
                    + '<span class="tk-att-size">' + bytes(a.file_size) + '</span></a>').join('') + '</div>'
                : '';
            return '<div class="tk-msg ' + (o.cls || '') + '">'
                 +   '<div class="tk-avatar">' + esc(initials(o.who)) + '</div>'
                 +   '<div class="tk-msg-body">'
                 +     '<div class="tk-msg-head"><span class="tk-msg-who">' + esc(o.who) + '</span>'
                 +       '<span class="tk-msg-when">' + esc(fullDate(o.when)) + '</span></div>'
                 +     '<div class="tk-msg-card">' + o.html + atts + '</div>'
                 +   '</div>'
                 + '</div>';
        }

        function composerHtml() {
            return '<div class="tk-composer">'
                 +   '<textarea id="ssReply" placeholder="' + esc(window.t('self-service.ticket.reply_placeholder')) + '"></textarea>'
                 +   '<div class="tk-composer-files" id="ssFiles"></div>'
                 +   '<div class="tk-composer-actions">'
                 +     '<button type="button" class="btn btn-primary" id="ssSend">' + esc(window.t('self-service.ticket.reply_send')) + '</button>'
                 +     '<input type="file" id="ssFileInput" multiple style="display:none">'
                 +     '<button type="button" class="btn btn-secondary" id="ssAttach">' + esc(window.t('self-service.ticket.reply_attach')) + '</button>'
                 +     '<span class="tk-note-line">' + esc(window.t('self-service.ticket.reply_hint')) + '</span>'
                 +   '</div>'
                 + '</div>';
        }

        function wireComposer() {
            const attach = document.getElementById('ssAttach');
            const input  = document.getElementById('ssFileInput');
            const send   = document.getElementById('ssSend');
            if (!attach || !input || !send) return;

            attach.addEventListener('click', () => input.click());
            input.addEventListener('change', async () => {
                for (const f of input.files) {
                    try {
                        ssFiles.push({ name: f.name, type: f.type || 'application/octet-stream', size: f.size, content: await toBase64(f) });
                    } catch (e) { /* skip unreadable file */ }
                }
                input.value = '';
                renderFiles();
            });
            send.addEventListener('click', sendReply);
        }

        function renderFiles() {
            const host = document.getElementById('ssFiles');
            if (!host) return;
            host.innerHTML = ssFiles.map((f, i) =>
                '<span class="tk-chip">' + esc(f.name) + ' <span class="tk-att-size">' + bytes(f.size) + '</span>'
                + '<button type="button" onclick="dropFile(' + i + ')">&times;</button></span>').join('');
        }

        function dropFile(i) { ssFiles.splice(i, 1); renderFiles(); }

        async function sendReply() {
            const box = document.getElementById('ssReply');
            const send = document.getElementById('ssSend');
            const body = (box.value || '').trim();
            if (!body && !ssFiles.length) { flash(window.t('self-service.ticket.reply_empty'), true); return; }

            send.disabled = true;
            send.textContent = window.t('self-service.ticket.reply_sending');
            try {
                const r = await fetch('../api/self-service/reply_ticket.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        ticket_id: ssSelected,
                        body: body,
                        attachments: ssFiles.map(f => ({ name: f.name, type: f.type, content: f.content }))
                    })
                });
                const d = await r.json();
                if (!d.success) { flash(d.error || window.t('self-service.ticket.reply_failed'), true); return; }

                ssFiles = [];
                // Refreshes the list (the status may have changed if the reply
                // reopened the ticket) AND re-renders this ticket, because
                // loadTickets keeps the current selection. No second select here
                // — two of them raced, and the loser left you on another ticket.
                await loadTickets();
                flash(d.reopened ? window.t('self-service.ticket.reply_sent_reopened')
                                 : window.t('self-service.ticket.reply_sent'), false);
            } catch (e) {
                flash(window.t('self-service.ticket.reply_failed'), true);
            } finally {
                const b = document.getElementById('ssSend');
                if (b) { b.disabled = false; b.textContent = window.t('self-service.ticket.reply_send'); }
            }
        }

        /**
         * Confirmations use the APP-WIDE toast (assets/js/toast.js), so the
         * portal behaves like the rest of FreeITSM instead of growing its own
         * message strip.
         *
         * It also fixes a real problem this page had: the strip lived INSIDE the
         * composer, and sending a reply re-renders the whole pane — so the
         * confirmation had to be written after the re-render or it was wiped out
         * by it. A toast lives outside the pane and simply survives.
         *
         * Falls back to alert() if toast.js somehow didn't load: silently
         * swallowing "your reply has been sent" is worse than an ugly box.
         */
        function flash(msg, isError) {
            if (typeof showToast === 'function') {
                showToast(msg, isError ? 'error' : 'success');
                return;
            }
            alert(msg);
        }

        function safeBody(html, type) {
            if (typeof messageBodyHtml !== 'function') {
                console.error('FreeITSM: assets/js/safe-html.js did not load — message shown as plain text.');
                return esc(html || '');
            }
            return messageBodyHtml(html, type);
        }

        function toBase64(file) {
            return new Promise((resolve, reject) => {
                const r = new FileReader();
                r.onload = () => resolve(String(r.result).split(',')[1] || '');
                r.onerror = reject;
                r.readAsDataURL(file);
            });
        }

        function initials(name) {
            const parts = String(name || '?').trim().split(/[\s@.]+/).filter(Boolean);
            return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[1][0] : '')).toUpperCase();
        }
        function bytes(b) {
            const n = Number(b) || 0;
            if (n < 1024) return n + ' B';
            if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
            return (n / 1048576).toFixed(1) + ' MB';
        }
        function stripTags(s) { const d = document.createElement('div'); d.innerHTML = s || ''; return d.textContent || ''; }
        function shortDate(s) {
            if (!s) return '';
            const d = new Date(String(s).replace(' ', 'T') + 'Z');
            if (isNaN(d)) return '';
            const today = new Date();
            const sameDay = d.toDateString() === today.toDateString();
            return sameDay ? d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
                           : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
        }
        function fullDate(s) {
            if (!s) return '';
            const d = new Date(String(s).replace(' ', 'T') + 'Z');
            if (isNaN(d)) return '';
            return d.toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        }
        function esc(t) { const d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
JS;

require_once __DIR__ . '/includes/header.php';
?>
    <div class="tk-shell">
        <div class="tk-list">
            <div class="tk-list-head">
                <h2><?php echo htmlspecialchars(t('self-service.tickets.heading')); ?></h2>
                <!-- Populated from the statuses actually on this person's tickets,
                     so the list never offers a filter that would come back empty. -->
                <select class="tk-filter" id="tkFilter" aria-label="<?php echo htmlspecialchars(t('self-service.tickets.filter_label')); ?>"></select>
            </div>
            <div class="tk-list-body" id="tkList">
                <div class="loading-state"><?php echo htmlspecialchars(t('self-service.tickets.loading')); ?></div>
            </div>
        </div>

        <div class="tk-read" id="tkRead">
            <div class="tk-read-empty"><?php echo htmlspecialchars(t('self-service.tickets.select')); ?></div>
        </div>
    </div>
<?php
require_once __DIR__ . '/includes/footer.php';
