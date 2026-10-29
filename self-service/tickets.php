<?php
// FIRST: this page loads an includes/ file before config.php, so the version guard
// must run before that file is read - see includes/php_version_guard.php.
require_once __DIR__ . '/../includes/php_version_guard.php';
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
// Loads assets/js/screen-recorder.js for the reply composer — the same module
// the compose screen uses. Pairs with includes/record-modal.php below.
$needsRecorder = true;
// The read-only answers (discussion #193) use the shared field renderer.
$pageHead = '<link rel="stylesheet" href="../assets/css/ticket-fields.css?v=2">'
          . '<script src="../assets/js/ticket-fields.js?v=2"></script>'
          // Pictures pasted into a shared note sit in its text (GH #224).
          . '<script src="../assets/js/note-images.js?v=1"></script>';

// Which list: the person's own tickets, or - for a manager - their team's
// (discussion #62). Whether they really manage anybody is the endpoint's answer
// (get_team_tickets.php), not this parameter's.
$ssView = (($_GET['view'] ?? '') === 'team') ? 'team' : 'mine';
if ($ssView === 'team') $activeNav = 'team_tickets';
$pageData = ['ticketId' => (int)($_GET['id'] ?? 0), 'view' => $ssView];

$pageStyles = <<<'CSS'
/* Two panes filling the viewport under the 48px header, each scrolling its own
   body — the same structure as the analyst inbox (.main-container). */
.tk-shell { display: flex; height: calc(100vh - var(--ss-chrome, 48px)); }   /* the bar + any logo band */

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
/* Team tickets: who and which status, side by side under the heading (#62). */
.tk-list-filters { display: flex; gap: 8px; padding: 10px 18px; border-bottom: 1px solid var(--border, #e5e7eb); }
.tk-list-filters .tk-filter { flex: 1; min-width: 0; max-width: none; }
.tk-more { display: block; width: calc(100% - 36px); margin: 12px 18px 16px; padding: 8px 12px; border: 1px solid var(--border, #e5e7eb);
    border-radius: 6px; background: var(--surface, #fff); color: var(--text, #333); font-family: inherit; font-size: 13px; cursor: pointer; }
.tk-more:hover { border-color: var(--ss-accent, #10b981); }
.tk-more small { display: block; color: var(--text-muted, #666); font-size: 11px; margin-top: 2px; }

/* The line between tickets. Translucent so it takes its weight from the
   surface beneath it, and keyed on the theme MODE so any dark palette is
   covered, not just the one called "dark". */
:root { --tk-divider: rgba(0, 0, 0, 0.055); }
[data-theme-mode="dark"] { --tk-divider: rgba(255, 255, 255, 0.05); }

.tk-list-body { flex: 1; overflow-y: auto; }

.tk-item {
    padding: 12px 18px;
    border-bottom: 1px solid var(--tk-divider);
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
    /* The subject and meta stack; the close button sits to the right of
       both, away from Reply. Deliberately NOT beside the reply button:
       'send' and 'this is over' next to each other is a misclick waiting
       to happen, and they are opposite intentions. */
    display: flex; align-items: flex-start; gap: 16px;
}
.tk-read-head > div:first-child, .tk-read-head > h1 { min-width: 0; }
.tk-read-head .tk-read-subject, .tk-read-head .tk-read-meta { flex: 1 1 auto; }
/* Quiet by default. Closing your own ticket is a useful escape hatch, not
   something the page should be encouraging on every visit. */
.tk-close-btn {
    /* The far end of the action row, away from Send: it shares the row for
       consistency but is the one action a requester cannot casually undo. */
    margin-left: auto;
    flex-shrink: 0;
    align-self: center;
    /* Same box as .btn beside it. `.btn` is padding 10/20 with NO border, so
       9/19 plus a 1px border comes to the same height and width - matching the
       padding alone would have made this one 2px taller than its neighbours. */
    padding: 9px 19px;
    border-radius: 4px;
    font-size: 14px;
    font-weight: 500;
    background: transparent;
    color: var(--text-muted, #666);
    border: 1px solid var(--border, #e5e7eb);
    cursor: pointer;
}
.tk-close-btn:hover:not(:disabled) {
    border-color: var(--danger-border, #f5c6c2);
    color: var(--danger-text, #b3261e);
    background: var(--danger-bg, #fdecea);
}
.tk-close-btn:disabled { opacity: .55; cursor: progress; }
/* Managers (discussion #62, step 3): whose ticket this is, and a stub. */
.tk-mgr-banner { flex-shrink: 0; padding: 10px 24px; font-size: 13px; background: var(--info-bg, #e3f2fd);
    color: var(--info-text, #0d47a1); border-bottom: 1px solid var(--info-border, #90caf9); }
.tk-stub { margin: 40px auto; max-width: 440px; text-align: center; color: var(--text-muted, #666); padding: 0 24px; }
.tk-stub .tk-lock { width: 28px; height: 28px; color: var(--danger-text, #b3261e); margin: 0 0 8px; }
.tk-stub h1 { font-size: 18px; margin: 0 0 8px; color: var(--text, #333); }
.tk-stub-meta { font-size: 12px; color: var(--text-faint, #999); }
.tk-item-subject.is-stub { font-style: italic; color: var(--text-muted, #666); }
/* Who has seen this ticket (discussion #62, step 2). Quiet when nobody has;
   it is information, not an alarm. */
.tk-seen { margin: 12px 24px 0; flex-shrink: 0; padding: 8px 12px; border: 1px solid var(--border, #e5e7eb); border-radius: 8px;
    background: var(--surface-2, #f7f9fa); font-size: 12px; color: var(--text-muted, #666); }
.tk-seen-title { display: flex; align-items: center; gap: 6px; font-weight: 600; color: var(--text, #333); margin-bottom: 2px; }
.tk-seen-icon { width: 14px; height: 14px; flex-shrink: 0; }
.tk-seen-list { list-style: none; margin: 4px 0 0; padding: 0; }
.tk-seen-list li { padding: 2px 0; }
.tk-seen-list strong { color: var(--text, #333); font-weight: 600; }
/* Confidential (discussion #62) - danger tones from the theme. */
.tk-lock { width: 12px; height: 12px; vertical-align: -1px; margin-right: 4px; flex-shrink: 0; }
.tk-item-subject .tk-lock { color: var(--danger-text, #b3261e); }
.tk-confidential { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 10px; font-weight: 600;
    background: var(--danger-bg, #fdecea); color: var(--danger-text, #b3261e); border: 1px solid var(--danger-border, #f5c6c2); }
.tk-mark-confidential { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-family: inherit;
    background: transparent; color: var(--text-muted, #666); border: 1px dashed var(--border, #e5e7eb); cursor: pointer; }
.tk-mark-confidential:hover:not(:disabled) { color: var(--danger-text, #b3261e); border-color: var(--danger-border, #f5c6c2); background: var(--danger-bg, #fdecea); }
.tk-mark-confidential:disabled { opacity: .55; cursor: progress; }
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

/* A picture pasted into a shared note, where it sits in the text (GH #224). */
.note-inline-image { display: inline-block; max-width: 100%; margin: 4px 0; vertical-align: top; }
.note-inline-image img { display: block; max-width: 100%; max-height: 360px; height: auto; border-radius: 6px; border: 1px solid var(--border, #e5e7eb); }
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

@media (max-width: 900px) {
    /* ⚠️ RELEASE THE APP-SHELL LOCK. `body.portal-app` in self-service.css is
       `height: 100vh; overflow: hidden` so that, on a desktop, the window stays
       put and each pane scrolls itself. The rules below stack those panes into
       ONE column taller than the window (42vh + 60vh + the header), and nothing
       was undoing the lock — so the column was clipped at the bottom of the
       viewport with no scroller anywhere on the page. Opening a ticket looked
       broken and could not be scrolled.
       This sits here rather than in self-service.css because it is the rule
       above that creates the need, and the two must change together. Page CSS
       is emitted after the stylesheet, so it wins without !important. */
    body.portal-app { height: auto; overflow: visible; }

    .tk-shell { flex-direction: column; height: auto; }
    .tk-list  { width: 100%; max-height: 42vh; }
    .tk-read  { min-height: 60vh; }
}

@media (max-width: 768px) {
    /* On a phone, ONE scroller: the page. A list that scrolls inside a page
       that also scrolls is the thing people complain about when a thumb swipe
       moves the wrong layer. */
    .tk-list      { max-height: none; }
    .tk-list-body { overflow-y: visible; }

    /* ── Outlook-style master and detail ──────────────────────────────────
       One at a time, not both stacked. Stacking them meant that opening a
       ticket left you looking at the list, with the ticket you had just asked
       for somewhere below the fold — you had to scroll past everything to
       reach it. Same shape as the analyst inbox on a phone: the list IS the
       screen, a ticket replaces it, and Back returns.
       Driven by a class on <body>, so the pane markup is untouched and the
       desktop two-pane layout is not involved at all. */
    body.tk-reading .tk-list { display: none; }
    body.tk-reading .tk-back { display: block; }
    body:not(.tk-reading) .tk-read { display: none; }

    .tk-read { min-height: 0; }

    .tk-back {
        display: none;              /* revealed by body.tk-reading, above */
        width: 100%;
        text-align: left;
        padding: 12px 14px;
        min-height: 44px;
        border: none;
        border-bottom: 1px solid var(--border-soft, #eee);
        background: var(--surface, #fff);
        color: var(--accent, #2e7d32);
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
    }
}

/* The back control is a phone affordance only; the desktop layout shows both
   panes at once and has nothing to go back to. */
.tk-back { display: none; }

/* The answers the requester gave (discussion #193). Under the title, or in a
   panel beside the conversation - Tickets -> Settings -> Custom fields. */
.tk-answers-head { padding: 12px 24px; background: var(--surface, #fff); border-bottom: 1px solid var(--border, #e5e7eb); flex-shrink: 0; }
.tk-answers-title { font-size: 12px; font-weight: 600; color: var(--text-muted, #666); text-transform: uppercase; letter-spacing: .4px; margin: 0 0 8px; }
.tk-read-body { flex: 1; display: flex; min-height: 0; }
.tk-read-main { flex: 1; display: flex; flex-direction: column; min-width: 0; min-height: 0; }
.tk-answers-side { width: 260px; flex-shrink: 0; overflow-y: auto; padding: 16px 18px; background: var(--surface, #fff); border-left: 1px solid var(--border, #e5e7eb); }
.tk-answers-side .tf-answers { grid-template-columns: 1fr; gap: 12px; }
/* The panel only where there is room for it; anything narrower shows the same
   answers under the title instead. */
.tk-answers-head.tk-answers-narrow { display: none; }
@media (max-width: 900px) {
    .tk-answers-side { display: none; }
    .tk-answers-head.tk-answers-narrow { display: block; }
    .tk-read-body { display: block; }
}
@media (max-width: 768px) {
    .tk-answers-head { padding: 10px 14px; }
}
CSS;

$pageScripts = <<<'JS'
let ssTickets = [];
        let ssSelected = null;
        let ssFilter = '';        // '' = every status
        let ssFiles = [];
        let ssRecordings = [];    // [{recording_id, name, size_bytes, duration_seconds}]

        // The recorder is the SAME module the compose screen uses
        // (assets/js/screen-recorder.js) — capture, preview and upload are
        // identical whether you are opening a ticket or answering one. All that
        // differs is what a claimed recording is attached to, which is this.
        //
        // init() runs at parse time, so screen-recorder.js must load first;
        // footer.php emits it before this block when $needsRecorder is set.
        ScreenRecorder.init({
            uploadUrl: '../api/self-service/upload_recording.php',
            onClaimed: function (rec) {
                ssRecordings.push(rec);
                renderFiles();
                flash(window.t('self-service.recorder.attached'), false);
            }
        });

        document.addEventListener('DOMContentLoaded', function () {
            const sel = document.getElementById('tkFilter');
            // Team tickets filter on the SERVER (they are paged), My tickets in the page.
            if (sel) sel.addEventListener('change', function () { ssFilter = sel.value; ssTeam ? loadTickets() : renderList(); });
            const who = document.getElementById('tkPerson');
            if (who) who.addEventListener('change', function () { ssPerson = who.value; ssFilter = ''; loadTickets(); });
            loadTickets();
        });

        async function loadTickets() {
            try {
                // A manager's team view reads a different list; everything after
                // this - the reading pane, the files, replying - is the same page,
                // and every endpoint behind it decides for itself (#62).
                const r = await fetch(ssTeam ? teamUrl(1) : '../api/self-service/get_tickets.php');
                const d = await r.json();
                ssTickets = d.success ? (d.tickets || []) : [];
                if (ssTeam) { ssTeamData = d.success ? d : null; ssTeamPage = 1; }
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
                // reveal ONLY for a deep link — see selectTicket.
                if (wanted) await selectTicket(wanted, !!window.PAGE.ticketId);
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
            if (ssTeam) { renderTeamFilters(sel); return; }

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

        // ── Team tickets: paged and filtered on the server (#62) ─────────────
        // A head of department's team can have thousands of tickets, so the list
        // arrives 50 at a time with a Load more, and both dropdowns ask the
        // server again rather than filtering what happens to be loaded.
        const ssTeam = window.PAGE.view === 'team';
        let ssTeamData = null, ssTeamPage = 1, ssPerson = '';
        function teamUrl(page) {
            return '../api/self-service/get_team_tickets.php?' + new URLSearchParams({ page: page, person: ssPerson, status: ssFilter });
        }
        function renderTeamFilters(sel) {
            const d = ssTeamData || { people: [], statuses: [] };
            const sum = a => a.reduce((n, x) => n + x.count, 0);
            const who = document.getElementById('tkPerson');
            if (who) {
                who.innerHTML = '<option value="">' + esc(window.t('self-service.tickets.team_person_all')) + ' (' + sum(d.people) + ')</option>'
                    + d.people.map(p => '<option value="' + p.id + '">' + esc(p.name) + ' (' + p.count + ')</option>').join('');
                who.value = ssPerson;
                if (who.value !== String(ssPerson)) ssPerson = '';
            }
            sel.innerHTML = '<option value="">' + esc(window.t('self-service.tickets.filter_all')) + ' (' + sum(d.statuses) + ')</option>'
                + d.statuses.map(s => '<option value="' + esc(s.name) + '">' + esc(s.name) + ' (' + s.count + ')</option>').join('');
            sel.value = ssFilter;
            if (sel.value !== ssFilter) ssFilter = '';
        }
        async function ssLoadMore(btn) {
            if (btn) btn.disabled = true;
            try {
                const d = await (await fetch(teamUrl(ssTeamPage + 1))).json();
                if (!d.success) return;
                ssTeamPage = d.page;
                ssTeamData.has_more = d.has_more;
                ssTickets = ssTickets.concat(d.tickets || []);
                // Keep the reader where they were: the list only grows below them.
                const host = document.getElementById('tkList'), top = host.scrollTop;
                renderList();
                host.scrollTop = top;
            } finally { if (btn) btn.disabled = false; }
        }

        function visibleTickets() {
            if (ssTeam) return ssTickets;          // already filtered by the server
            if (!ssFilter) return ssTickets;
            return ssTickets.filter(t => (t.status || '') === ssFilter);
        }

        function renderList() {
            const host = document.getElementById('tkList');
            const list = visibleTickets();

            if (!list.length) {
                host.innerHTML = '<div class="loading-state">' + esc(window.t(window.PAGE.view === 'team'
                    ? 'self-service.tickets.team_none' : 'self-service.tickets.none')) + '</div>';
                return;
            }

            host.innerHTML = list.map(t => {
                const c = t.status_colour || '#0078d4';
                return '<button type="button" class="tk-item' + (ssSelected == t.id ? ' selected' : '') + '" onclick="selectTicket(' + t.id + ')">'
                     +   '<div class="tk-item-top">'
                     +     '<span class="tk-dot" style="background:' + esc(c) + '"></span>'
                     +     '<span class="tk-item-subject' + (t.stub ? ' is-stub' : '') + '">' + (t.sensitivity === 'confidential' ? SS_LOCK_ICON : '')
                     +       esc(t.stub ? window.t('self-service.tickets.stub_subject') : (t.subject || '')) + '</span>'
                     +     '<span class="tk-item-date">' + esc(shortDate(t.updated_datetime || t.created_datetime)) + '</span>'
                     +   '</div>'
                     +   (t.preview ? '<div class="tk-item-preview">' + esc(stripTags(t.preview)) + '</div>' : '')
                     +   '<div class="tk-item-meta">' + (t.requester_name ? '<strong>' + esc(t.requester_name) + '</strong> &middot; ' : '')
                     +     esc(t.ticket_number || '') + ' &middot; ' + esc(t.status || '') + '</div>'
                     + '</button>';
            }).join('')
            + ((ssTeam && ssTeamData && ssTeamData.has_more)
                ? '<button type="button" class="tk-more" onclick="ssLoadMore(this)">' + esc(window.t('self-service.tickets.load_more'))
                  + '<small>' + esc(window.t('self-service.tickets.showing_of', { shown: list.length, total: ssTeamData.total })) + '</small></button>'
                : '');
        }

        // ── Phone master/detail ───────────────────────────────────────────
        // The panes are one at a time on a phone (see the CSS). This is the
        // only state: a class on <body>. matchMedia rather than a width read,
        // so rotating the handset re-evaluates without a reload.
        const ssPhone = window.matchMedia('(max-width: 768px)');

        function ssSetReading(on) {
            document.body.classList.toggle('tk-reading', !!on && ssPhone.matches);
        }

        // Back to the list. Prefers history.back() so the DEVICE back button and
        // this button are the same action rather than two that disagree —
        // otherwise tapping Back leaves a stale entry and the phone's own back
        // gesture then appears to do nothing.
        function ssBackToList() {
            if (history.state && history.state.ssReading) { history.back(); return; }
            ssSetReading(false);
        }

        // The phone's back gesture returns to the list rather than leaving the
        // portal, which is what every mail app does.
        window.addEventListener('popstate', function (e) {
            ssSetReading(!!(e.state && e.state.ssReading));
        });

        // Rotating to landscape can cross the breakpoint: if the class is left
        // on, the desktop two-pane layout would come back with the list hidden.
        (ssPhone.addEventListener ? ssPhone.addEventListener.bind(ssPhone, 'change')
                                  : ssPhone.addListener.bind(ssPhone))(function () {
            if (!ssPhone.matches) document.body.classList.remove('tk-reading');
        });

        // `reveal` = "the person asked for this ticket", so on a phone we should
        // switch from the list to the ticket. Defaults TRUE because every caller
        // that is a tap wants that.
        //
        // ⚠️ The load path passes FALSE. It auto-selects the first ticket so the
        // desktop's second pane is never empty — right there, and wrong on a
        // phone, where it landed you inside a ticket you never chose instead of
        // on your list. A deep link (?id=) is a real request, so that still
        // reveals.
        async function selectTicket(id, reveal) {
            const wantsReveal = (reveal === undefined) ? true : !!reveal;
            ssSelected = id;
            ssFiles = [];
            renderList();
            if (wantsReveal) ssSetReading(true);
            // Keep the address bar in step so the ticket can be linked/bookmarked.
            // Guarded: history writes throw in some contexts (file:// origins,
            // strict embedders), and a URL nicety must never stop the ticket
            // itself loading.
            try {
                if (window.history && window.history.replaceState) {
                    // ⚠️ PUSH on a phone, REPLACE on a desktop, and the difference
                    // is deliberate. Pushing is what gives the handset's own back
                    // gesture something to pop, so it returns to the list like a
                    // mail app. On a desktop both panes are on screen and there is
                    // nothing to go back FROM, so pushing would only litter the
                    // history with one entry per ticket read.
                    if (wantsReveal && ssPhone.matches && !(history.state && history.state.ssReading)) {
                        window.history.pushState({ ticketId: id, ssReading: true }, '', ssTicketUrl(id));
                    } else {
                        window.history.replaceState(
                            { ticketId: id, ssReading: wantsReveal && ssPhone.matches }, '', ssTicketUrl(id));
                    }
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
            // What this viewer may do, as the SERVER says - the requester, or a
            // manager with whatever System -> Managers allows (#62).
            ssViewer = d.viewer || { role: 'requester', can_reply: true, can_close: true, stub: false };

            if (ssViewer.stub) {
                ssCurrentTicketId = Number(t.id) || 0;
                pane.innerHTML = managerBannerHtml()
                  + '<div class="tk-stub">' + SS_LOCK_ICON
                  +   '<h1>' + esc(window.t('self-service.ticket.stub_title')) + '</h1>'
                  +   '<p>' + esc(window.t('self-service.ticket.stub_body')) + '</p>'
                  +   '<p class="tk-stub-meta">' + esc(t.ticket_number || '') + ' &middot; ' + esc(t.status || '')
                  +     ' &middot; ' + esc(window.t('self-service.ticket.created', { date: fullDate(t.created_datetime) })) + '</p>'
                  + '</div>';
                return;
            }

            // Screen recordings, bucketed by the message they were recorded with.
            // A recording with no email_id came with the ticket's OPENING message
            // — which is every recording made before replies could carry one, so
            // old tickets keep showing theirs in the right place with no
            // migration.
            const recsByEmail = {};
            let firstEmailId = null;
            (d.thread || []).forEach(m => { if (firstEmailId === null) firstEmailId = m.id; });
            (d.recordings || []).forEach(r => {
                const key = (r.email_id === null || r.email_id === undefined) ? firstEmailId : r.email_id;
                (recsByEmail[key] = recsByEmail[key] || []).push(r);
            });

            // Merge messages and shared notes into one chronological conversation.
            const items = [];
            (d.thread || []).forEach(m => items.push({ kind: 'msg', data: m, at: m.received_datetime }));
            (d.notes  || []).forEach(n => items.push({ kind: 'note', data: n, at: n.created_datetime }));
            items.sort((a, b) => new Date(a.at) - new Date(b.at));

            const msgs = items.map(item => {
                if (item.kind === 'note') {
                    const n = item.data;
                    // A picture pasted into the note is drawn where it sits, and
                    // left out of the file list below it (GH #224). Served by the
                    // same route as the note's other files, which re-checks that
                    // the note is shared.
                    const body = window.NoteImages.render(n.note_text || '', n.documents || [], {
                        url: dd => '../api/self-service/get_document.php?id=' + encodeURIComponent(dd.id),
                        attrs: () => 'target="_blank" rel="noopener noreferrer"'
                    });
                    return messageHtml({
                        who: n.analyst_name || window.t('self-service.ticket.support'),
                        when: n.created_datetime,
                        html: body.html.replace(/\n/g, '<br>'),
                        docs: (n.documents || []).filter(dd => !body.used[String(dd.id)]),
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
                    atts: m.attachments || [],
                    recs: recsByEmail[m.id] || []
                });
            }).join('');

            pane.innerHTML =
                managerBannerHtml()
              + '<div class="tk-read-head">'
              +   '<h1 class="tk-read-subject">' + esc(t.subject || '') + '</h1>'
              +   '<div class="tk-read-meta">'
              +     '<span class="tk-num">' + esc(t.ticket_number || '') + '</span>'
              +     '<span class="status-badge" style="background-color:' + esc(c) + '1f;color:' + esc(c) + ';border:1px solid ' + esc(c) + '33">' + esc(t.status || '') + '</span>'
              +     '<span>' + esc(t.priority || '') + '</span>'
              +     (t.department_name ? '<span>' + esc(t.department_name) + '</span>' : '')
              +     confidentialHtml(t)
              +     '<span>' + esc(window.t('self-service.ticket.created', { date: fullDate(t.created_datetime) })) + '</span>'
              +   '</div>'
              + '</div>'
              + answersHtml(d.field_answers, 'head')
              + (answersPlace(d.field_answers) === 'side' ? '<div class="tk-read-body"><div class="tk-read-main">' : '')
              + seenByHtml(d.seen_by)
              + '<div class="tk-thread" id="tkThread">' + (msgs || '<div class="loading-state">' + esc(window.t('self-service.ticket.no_conversation')) + '</div>') + '</div>'
              + (ssViewer.can_reply ? composerHtml(t) : (ssViewer.can_close ? '<div class="tk-composer"><div class="tk-composer-actions">' + closeButtonHtml(t) + '</div></div>' : ''))
              + (answersPlace(d.field_answers) === 'side' ? '</div>' + answersHtml(d.field_answers, 'side') + '</div>' : '');

            ssCurrentTicketId = Number(t.id) || 0;
            wireComposer();
            const closeBtn = document.getElementById('ssSelfClose');
            if (closeBtn) closeBtn.addEventListener('click', selfCloseTicket);
            const confBtn = document.getElementById('ssMarkConfidential');
            if (confBtn) confBtn.addEventListener('click', markConfidential);
            const thread = document.getElementById('tkThread');
            if (thread) thread.scrollTop = thread.scrollHeight;   // newest first to the eye
        }

        /**
         * Where the requester's answers go (discussion #193): 'off', 'header' or
         * 'side'; 'off' too when there are none to show.
         */
        function answersPlace(fa) {
            return (fa && fa.items && fa.items.length && window.TicketFields) ? fa.place : 'off';
        }

        /**
         * The answers block. 'head' draws under the title - for the header
         * setting, and for the side setting on a screen too narrow for the
         * panel; 'side' draws the panel.
         */
        function answersHtml(fa, where) {
            const place = answersPlace(fa);
            if (place === 'off') return '';
            const title = '<p class="tk-answers-title">' + esc(window.t('self-service.ticket.details_heading')) + '</p>';
            const list = window.TicketFields.readOnly(fa.items);
            if (where === 'side') return place === 'side' ? '<aside class="tk-answers-side">' + title + list + '</aside>' : '';
            return '<div class="tk-answers-head' + (place === 'side' ? ' tk-answers-narrow' : '') + '">' + title + list + '</div>';
        }

        function messageHtml(o) {
            const atts = (o.atts && o.atts.length)
                ? '<div class="tk-att">' + o.atts.map(a =>
                    '<a href="../api/self-service/get_attachment.php?id=' + encodeURIComponent(a.id) + '" target="_blank" rel="noopener">'
                    + '<span class="tk-att-name">' + esc(a.filename || '') + '</span>'
                    + '<span class="tk-att-size">' + bytes(a.file_size) + '</span></a>').join('') + '</div>'
                : '';

            // Screen recordings play inline. get_recording.php is range-aware, so
            // <video> can seek without pulling the whole file down first.
            const recs = (o.recs && o.recs.length)
                ? o.recs.map(r => {
                    const meta = [];
                    if (r.duration_seconds) meta.push(ScreenRecorder.formatDuration(r.duration_seconds));
                    if (r.file_size) meta.push(bytes(r.file_size));
                    return '<div class="tk-recording">'
                         +   '<video controls preload="metadata" src="../api/self-service/get_recording.php?id='
                         +     encodeURIComponent(r.id) + '"></video>'
                         +   '<div class="tk-recording-meta"><span>🎥 '
                         +     esc(window.t('self-service.ticket.screen_recording')) + '</span>'
                         +     (meta.length ? '<span>' + esc(meta.join(' · ')) + '</span>' : '')
                         +   '</div>'
                         + '</div>';
                }).join('')
                : '';

            // Files attached to a SHARED note (discussion #103). Same .tk-att
            // markup as an email attachment, because to the person reading it
            // there is no difference — it is a file their support desk sent.
            // Different endpoint, because a note's file lives in the documents
            // store rather than the mail store, and a DMS entry is a link out
            // rather than something we hold.
            const docs = (o.docs && o.docs.length)
                ? '<div class="tk-att">' + o.docs.map(dd => {
                    const name = dd.original_name || dd.title || '';
                    const isLink = dd.kind === 'link';
                    const href = isLink
                        ? safeExternalUrl(dd.external_url)
                        : '../api/self-service/get_document.php?id=' + encodeURIComponent(dd.id);
                    if (!href) return '';
                    return '<a href="' + esc(href) + '" target="_blank" rel="noopener noreferrer">'
                         + '<span class="tk-att-name">' + esc(name) + '</span>'
                         + '<span class="tk-att-size">'
                         +   (isLink ? esc(window.t('self-service.ticket.note_file_link')) : bytes(dd.size_bytes))
                         + '</span></a>';
                  }).join('') + '</div>'
                : '';

            return '<div class="tk-msg ' + (o.cls || '') + '">'
                 +   '<div class="tk-avatar">' + esc(initials(o.who)) + '</div>'
                 +   '<div class="tk-msg-body">'
                 +     '<div class="tk-msg-head"><span class="tk-msg-who">' + esc(o.who) + '</span>'
                 +       '<span class="tk-msg-when">' + esc(fullDate(o.when)) + '</span></div>'
                 +     '<div class="tk-msg-card">' + o.html + atts + docs + recs + '</div>'
                 +   '</div>'
                 + '</div>';
        }

        // Which ticket the read pane is showing. renderTicket() sets it; the
        // close handler is the only thing that needs it, and passing it through
        // three layers of markup-building string concatenation to get there
        // would be worse than one variable beside them.
        let ssCurrentTicketId = 0;

        // ── Managers (discussion #62, step 3) ─────────────────────────────
        let ssViewer = { role: 'requester', can_reply: true, can_close: true, stub: false };

        function ssTicketUrl(id) {
            return 'tickets.php?' + (window.PAGE.view === 'team' ? 'view=team&' : '') + 'id=' + id;
        }

        // Said up front on a team ticket: whose it is, and what a manager may do
        // here - signposted, so nobody wonders why there is no reply box.
        function managerBannerHtml() {
            if (ssViewer.role !== 'manager') return '';
            const can = ssViewer.can_reply && ssViewer.can_close ? 'mgr_can_both'
                      : ssViewer.can_reply ? 'mgr_can_reply'
                      : ssViewer.can_close ? 'mgr_can_close' : 'mgr_can_view';
            return '<div class="tk-mgr-banner">'
                 +   '<strong>' + esc(window.t('self-service.ticket.mgr_banner', { name: ssViewer.requester_name || '' })) + '</strong> '
                 +   (ssViewer.stub ? '' : esc(window.t('self-service.ticket.' + can)))
                 + '</div>';
        }

        // ── Who has seen this ticket (discussion #62, step 2) ─────────────
        // Shown by default, not behind a button: if someone other than the
        // requester can read their ticket, the requester is owed knowing when
        // they did. Portal viewers only - the service desk is deliberately left
        // out (see includes/ticket_views.php). null = not tracked yet on this
        // install (Database Verification not run), so no panel at all rather
        // than a reassurance nothing is backing up.
        function seenByHtml(seen) {
            if (!Array.isArray(seen)) return '';
            const icon = '<svg class="tk-seen-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
            const body = seen.length === 0
                ? '<span class="tk-seen-none">' + esc(window.t('self-service.ticket.seen_nobody')) + '</span>'
                : '<ul class="tk-seen-list">' + seen.map(v =>
                      '<li><strong>' + esc(v.name) + '</strong> <span>' + esc(fullDate(v.last))
                    + (v.count > 1 ? ' &middot; ' + esc(window.t('self-service.ticket.seen_times', { count: v.count })) : '')
                    + '</span></li>').join('') + '</ul>';
            return '<div class="tk-seen" title="' + esc(window.t('self-service.ticket.seen_help')) + '">'
                 +   '<div class="tk-seen-title">' + icon + esc(window.t('self-service.ticket.seen_title')) + '</div>'
                 +   body
                 + '</div>';
        }

        // ── Confidential (discussion #62) ─────────────────────────────────
        // A badge when the ticket is confidential; otherwise a quiet button to
        // make it so. ONE WAY: only the service desk makes a ticket Normal again
        // (api/self-service/mark_confidential.php enforces it, not this button).
        const SS_LOCK_ICON = '<svg class="tk-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';

        function confidentialHtml(t) {
            // Only the requester can mark their ticket confidential; a manager just
            // sees whether it is (#62).
            if (ssViewer.role === 'manager' && t.sensitivity !== 'confidential') return '';
            if (t.sensitivity === 'confidential') {
                return '<span class="tk-confidential" title="' + esc(window.t('self-service.ticket.confidential_hint')) + '">'
                     + SS_LOCK_ICON + esc(window.t('self-service.ticket.confidential')) + '</span>';
            }
            return '<button type="button" class="tk-mark-confidential" id="ssMarkConfidential" title="'
                 + esc(window.t('self-service.ticket.confidential_hint')) + '">'
                 + SS_LOCK_ICON + esc(window.t('self-service.ticket.mark_confidential')) + '</button>';
        }

        async function markConfidential() {
            const ok = await showConfirm({
                title:   window.t('self-service.ticket.mark_confidential_title'),
                message: window.t('self-service.ticket.mark_confidential_confirm'),
                okLabel: window.t('self-service.ticket.mark_confidential')
            });
            if (!ok) return;
            const btn = document.getElementById('ssMarkConfidential');
            if (btn) btn.disabled = true;
            try {
                const r = await fetch(API_BASE + 'mark_confidential.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ticket_id: ssCurrentTicketId })
                });
                const d = await r.json();
                if (!d.success) {
                    showToast(d.error || window.t('self-service.ticket.mark_confidential_failed'), 'error');
                    if (btn) btn.disabled = false;
                    return;
                }
                showToast(window.t('self-service.ticket.mark_confidential_done'), 'success');
                // Swap the button for the badge in place, and put the padlock in the list.
                if (btn) btn.outerHTML = confidentialHtml({ sensitivity: 'confidential' });
                markListRowConfidential(ssCurrentTicketId);
            } catch (e) {
                showToast(window.t('self-service.ticket.mark_confidential_failed'), 'error');
                if (btn) btn.disabled = false;
            }
        }

        function markListRowConfidential(id) {
            const row = ssTickets.find(x => Number(x.id) === Number(id));
            if (row) { row.sensitivity = 'confidential'; renderList(); }
        }

        /**
         * "No longer needed", offered only when the administrator has switched
         * it on AND the ticket is still open.
         *
         * 🔑 This decides what is DRAWN. api/self-service/close_ticket.php
         * re-checks the switch and re-checks that the ticket belongs to the
         * person asking — a button that is not on the page is not a rule.
         */
        function closeButtonHtml(t) {
            // A requester needs the portal's self-close switch; a manager, the
            // managers' one - which the server has already folded into can_close.
            if (ssViewer.role === 'manager' ? !ssViewer.can_close
                                            : !(window.SS_PORTAL && window.SS_PORTAL.allow_self_close)) return '';
            if (Number(t.is_closed) === 1) return '';
            return '<button type="button" class="btn tk-close-btn" id="ssSelfClose" title="'
                 + esc(window.t('self-service.ticket.self_close_hint')) + '">'
                 + esc(window.t('self-service.ticket.self_close')) + '</button>';
        }

        async function selfCloseTicket() {
            // The shared OK/Cancel dialog rather than the browser's confirm():
            // closing cannot be undone from the portal, so it is worth one
            // deliberate tap — and the textarea is how the requester says WHY,
            // which is the part an analyst actually wants.
            const ok = await showConfirm({
                title:    window.t('self-service.ticket.self_close_title'),
                message:  window.t('self-service.ticket.self_close_confirm'),
                okLabel:  window.t('self-service.ticket.self_close'),
                okClass:  'danger',
                textarea: { placeholder: window.t('self-service.ticket.self_close_reason'),
                            label:       window.t('self-service.ticket.self_close_reason_label') }
            });
            if (!ok) return;

            const btn = document.getElementById('ssSelfClose');
            if (btn) btn.disabled = true;
            try {
                const r = await fetch(API_BASE + 'close_ticket.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ticket_id: ssCurrentTicketId, reason: (ok && ok.text) || '' })
                });
                const d = await r.json();
                if (!d.success) {
                    showToast(d.error || window.t('self-service.ticket.self_close_failed'), 'error');
                    if (btn) btn.disabled = false;
                    return;
                }
                showToast(window.t('self-service.ticket.self_close_done'), 'success');
                // Reloaded rather than patched by hand: closing changes the
                // status badge, the counts in the list beside it and whether a
                // reply box belongs there at all, and the server knows all three.
                window.location.reload();
            } catch (e) {
                showToast(window.t('self-service.ticket.self_close_failed'), 'error');
                if (btn) btn.disabled = false;
            }
        }

        function composerHtml(t) {
            return '<div class="tk-composer">'
                 +   '<textarea id="ssReply" placeholder="' + esc(window.t('self-service.ticket.reply_placeholder')) + '"></textarea>'
                 +   '<div class="tk-composer-files" id="ssFiles"></div>'
                 +   '<div class="tk-composer-actions">'
                 +     '<button type="button" class="btn btn-primary" id="ssSend">' + esc(window.t('self-service.ticket.reply_send')) + '</button>'
                 // Closing sits in this row for consistency but at the far END
                 // of it (margin-left:auto in the stylesheet), because Send is
                 // pressed constantly and this is the one action a requester
                 // cannot casually undo.
                 +     closeButtonHtml(t)
                 +     '<input type="file" id="ssFileInput" multiple style="display:none">'
                 // The "attach screenshots, logs or documents" line is the
                 // button's TOOLTIP rather than a line of body text beside it:
                 // it explains the button, and as standing text it was just
                 // clutter under every ticket. Same treatment as the dashboard
                 // action buttons.
                 +     '<button type="button" class="btn btn-secondary" id="ssAttach" title="'
                 +       esc(window.t('self-service.ticket.reply_hint')) + '">'
                 +       esc(window.t('self-service.ticket.reply_attach')) + '</button>'
                 // Hidden where the browser can't screen-capture (iOS Safari has
                 // no getDisplayMedia), rather than offering a button that fails.
                 +     (ScreenRecorder.isSupported()
                        ? '<button type="button" class="btn btn-secondary" id="ssRecord" title="'
                          + esc(window.t('self-service.recorder.tooltip')) + '">'
                          + esc(window.t('self-service.recorder.button')) + '</button>'
                        : '')
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

            const record = document.getElementById('ssRecord');
            if (record) record.addEventListener('click', () => ScreenRecorder.open());
        }

        /**
         * Pending recordings and picked files render in the SAME chip strip:
         * to the person replying they are both just "things going with this
         * message", and splitting them into two lists would be a distinction
         * only the database cares about.
         */
        function renderFiles() {
            const host = document.getElementById('ssFiles');
            if (!host) return;
            const files = ssFiles.map((f, i) =>
                '<span class="tk-chip">' + esc(f.name) + ' <span class="tk-att-size">' + bytes(f.size) + '</span>'
                + '<button type="button" onclick="dropFile(' + i + ')">&times;</button></span>').join('');
            const recs = ssRecordings.map((r, i) =>
                '<span class="tk-chip">🎥 ' + esc(window.t('self-service.ticket.screen_recording'))
                + ' <span class="tk-att-size">' + ScreenRecorder.formatDuration(r.duration_seconds || 0) + '</span>'
                + '<button type="button" onclick="dropRecording(' + i + ')">&times;</button></span>').join('');
            host.innerHTML = files + recs;
        }

        function dropFile(i) { ssFiles.splice(i, 1); renderFiles(); }

        /**
         * Dropping a recording only unhooks it from THIS reply — the uploaded row
         * stays pending and unclaimed, exactly as it would if the page were
         * closed. Deleting server-side from here would need a second endpoint
         * whose whole job is destroying things, for no gain.
         */
        function dropRecording(i) { ssRecordings.splice(i, 1); renderFiles(); }

        async function sendReply() {
            const box = document.getElementById('ssReply');
            const send = document.getElementById('ssSend');
            const body = (box.value || '').trim();
            if (!body && !ssFiles.length && !ssRecordings.length) {
                flash(window.t('self-service.ticket.reply_empty'), true);
                return;
            }

            // Same guard the compose screen uses: a recorded-but-unclaimed video
            // is sitting in the modal's preview, and sending now would silently
            // lose it. Put the Use this / Discard choice back in front of them.
            if (ScreenRecorder.hasUnclaimed()) {
                ScreenRecorder.open();
                const status = document.getElementById('recStatus');
                if (status) {
                    status.style.color = '#dc2626';
                    status.style.fontWeight = '600';
                    status.innerHTML = window.t('self-service.recorder.claim_prompt', {
                        use: '<strong>' + esc(window.t('self-service.recorder.use')) + '</strong>',
                        discard: '<strong>' + esc(window.t('self-service.recorder.discard')) + '</strong>'
                    });
                }
                return;
            }

            send.disabled = true;
            send.textContent = window.t('self-service.ticket.reply_sending');
            try {
                const r = await fetch('../api/self-service/reply_ticket.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        ticket_id: ssSelected,
                        body: body,
                        attachments: ssFiles.map(f => ({ name: f.name, type: f.type, content: f.content })),
                        recording_ids: ssRecordings.map(r => r.recording_id)
                    })
                });
                const d = await r.json();
                if (!d.success) { flash(d.error || window.t('self-service.ticket.reply_failed'), true); return; }

                ssFiles = [];
                ssRecordings = [];
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
        // A DMS entry's URL was typed by an analyst, so it reaches the CUSTOMER's
        // browser as data and must never become an href on trust — `javascript:`
        // in that box would otherwise run for every requester who opens the
        // ticket. Same allowlist as the analyst side (inbox.js, documents.js);
        // restated rather than shared because it is one regex and the
        // alternative is a cross-context util contract for two lines.
        function safeExternalUrl(url) {
            return /^https?:\/\//i.test(String(url || '')) ? String(url) : '';
        }
        function stripTags(s) { const d = document.createElement('div'); d.innerHTML = s || ''; return d.textContent || ''; }
        function shortDate(s) {
            if (!s) return '';
            const d = new Date(String(s).replace(' ', 'T') + 'Z');
            if (isNaN(d)) return '';
            const today = new Date();
            // Bucket on the DISPLAY zone (ymdInZone, a machine format) rather than
            // the browser's own day, so "today" agrees with the time shown.
            const sameDay = ymdInZone(d) === ymdInZone(today);
            return sameDay ? fmtTime(d) : fmtDayMonth(d);
        }
        function fullDate(s) {
            if (!s) return '';
            const d = new Date(String(s).replace(' ', 'T') + 'Z');
            if (isNaN(d)) return '';
            return fmtDateTime(d);
        }
        function esc(t) { const d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
JS;

require_once __DIR__ . '/includes/header.php';
?>
    <div class="tk-shell">
        <div class="tk-list">
            <div class="tk-list-head">
                <h2><?php echo htmlspecialchars(t($ssView === 'team' ? 'self-service.tickets.team_heading' : 'self-service.tickets.heading')); ?></h2>
                <?php if ($ssView !== 'team'): ?>
                <!-- Populated from the statuses actually on this person's tickets,
                     so the list never offers a filter that would come back empty. -->
                <select class="tk-filter" id="tkFilter" aria-label="<?php echo htmlspecialchars(t('self-service.tickets.filter_label')); ?>"></select>
                <?php endif; ?>
            </div>
            <?php if ($ssView === 'team'): ?>
            <?php /* Who, and which status - both with counts from the server. */ ?>
            <div class="tk-list-filters">
                <select class="tk-filter" id="tkPerson" aria-label="<?php echo htmlspecialchars(t('self-service.tickets.team_person_label')); ?>"></select>
                <select class="tk-filter" id="tkFilter" aria-label="<?php echo htmlspecialchars(t('self-service.tickets.filter_label')); ?>"></select>
            </div>
            <?php endif; ?>
            <div class="tk-list-body" id="tkList">
                <div class="loading-state"><?php echo htmlspecialchars(t('self-service.tickets.loading')); ?></div>
            </div>
        </div>

        <!-- Phone-only "back to the list", shown by CSS when body.tk-reading is
             set. It sits OUTSIDE #tkRead deliberately: that element's innerHTML
             is replaced on every selection, so a button inside it would have to
             be re-created each time and would go missing the first time somebody
             changed the renderer. -->
        <button type="button" class="tk-back" id="tkBack" onclick="ssBackToList()">
            &#8592; <?php echo htmlspecialchars(t('self-service.tickets.back_to_list')); ?>
        </button>

        <div class="tk-read" id="tkRead">
            <div class="tk-read-empty"><?php echo htmlspecialchars(t('self-service.tickets.select')); ?></div>
        </div>
    </div>

    <?php require __DIR__ . '/includes/record-modal.php'; ?>
<?php
require_once __DIR__ . '/includes/footer.php';
