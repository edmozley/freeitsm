/* ============================================================================
   mobile.js  —  Mobile-only inbox master-detail behaviour (Outlook-style pane
   stack). Paired with mobile.css (LAYER 2).

   HARD RULE mirror of the CSS: every behaviour here is gated on
   matchMedia('(max-width: 768px)'), so on desktop it is inert — no pane
   switching, and the injected sub-bar is display:none. Desktop is untouched.

   Loaded AFTER inbox.js so it can wrap the global selectEmail / selectFolder
   handlers that the list rows and folder items already call.
   ========================================================================== */
(function () {
    'use strict';

    var mq = window.matchMedia('(max-width: 768px)');
    var mc = document.querySelector('.main-container');
    if (!mc) return;   // not the inbox page — nothing to do

    // ---- pane state, mirrored on <body> so CSS ancestor selectors can react ----
    function setPane(p) { document.body.setAttribute('data-mobile-pane', p); }
    function currentPane() { return document.body.getAttribute('data-mobile-pane') || 'list'; }

    // Navigate INTO a pane, pushing a history entry so the device Back button
    // (and our Back chevron) pops back out of it.
    function pushPane(p) {
        setPane(p);
        if (mq.matches) history.pushState({ nmPane: p }, '');
    }

    setPane('list');

    window.addEventListener('popstate', function (e) {
        if (!mq.matches) return;
        setPane((e.state && e.state.nmPane) ? e.state.nmPane : 'list');
    });

    // ---- wrap the globals inbox.js already exposes (don't edit inbox.js) ----
    if (typeof window.selectEmail === 'function') {
        var _selectEmail = window.selectEmail;
        window.selectEmail = function () {
            var r = _selectEmail.apply(this, arguments);
            // Push only when genuinely navigating list -> ticket. selectEmail is
            // also called to REFRESH an already-open ticket; those must not stack.
            if (mq.matches && currentPane() !== 'reading') pushPane('reading');
            // Once the ticket has rendered, move the link strips + properties
            // into their own sheets and apply the reading-pane refinements
            // (mobile only — see afterTicketRender).
            if (r && typeof r.then === 'function') r.then(afterTicketRender);
            else afterTicketRender();
            return r;
        };
    }

    if (typeof window.selectFolder === 'function') {
        var _selectFolder = window.selectFolder;
        window.selectFolder = function () {
            var r = _selectFolder.apply(this, arguments);
            // Picking a folder drops back to the list; pop the folders entry so
            // Back doesn't reopen the folder drawer.
            if (mq.matches && currentPane() === 'folders') history.back();
            return r;
        };
    }

    // The desktop "pop-out" (full-screen reading pane) mode is meaningless on a
    // phone — the reading pane is already full-screen via the master-detail
    // stack — and body.ticket-popout HIDES the email list (breaking Back) and
    // pads the reading pane by 340px. inbox.js re-applies it on every ticket
    // open when the saved pref is on, so strip it right after each sync here.
    if (typeof window.syncPopoutToTicketState === 'function') {
        var _syncPopout = window.syncPopoutToTicketState;
        window.syncPopoutToTicketState = function () {
            var r = _syncPopout.apply(this, arguments);
            if (mq.matches) document.body.classList.remove('ticket-popout');
            return r;
        };
    }

    // Attachments load async after the ticket renders; when the info bar is
    // (re)rendered, refresh the compact mobile badge that replaces it.
    if (typeof window.renderAttachmentInfoBar === 'function') {
        var _renderAttach = window.renderAttachmentInfoBar;
        window.renderAttachmentInfoBar = function () {
            var r = _renderAttach.apply(this, arguments);
            if (mq.matches) syncAttachBadge();
            return r;
        };
    }

    // ---- inject the sub-bar (Back / Folders), sitting above the pane area ----
    var bar = document.createElement('div');
    bar.className = 'mobile-subbar';
    bar.innerHTML =
        '<button type="button" class="msb-back" aria-label="Back">‹ Back</button>' +
        '<button type="button" class="msb-folders" aria-label="Folders">☰ Folders</button>' +
        '<span class="msb-ref" aria-label="Ticket reference"></span>';
    mc.parentNode.insertBefore(bar, mc);

    bar.querySelector('.msb-back').addEventListener('click', function () {
        if (currentPane() === 'list') return;
        // Force the list pane directly (guaranteed regardless of the history
        // stack), then pop the entry we pushed so the device Back button stays
        // in sync. Leading with setPane makes Back reliable even if history.back
        // has nothing to pop.
        setPane('list');
        if (history.state && history.state.nmPane) history.back();
    });
    bar.querySelector('.msb-folders').addEventListener('click', function () { pushPane('folders'); });

    // ---- Views hamburger (top-right) -> right-side slide-in drawer ----
    // The tickets sub-views (Inbox/Dashboard/Users/Calendar/...) live in
    // .header-nav; on mobile that becomes a right drawer opened by this button.
    var headerEl = document.querySelector('.header');
    if (headerEl && document.querySelector('.header-nav')) {
        var vBtn = document.createElement('button');
        vBtn.type = 'button';
        vBtn.className = 'mobile-views-btn';
        vBtn.setAttribute('aria-label', 'Views');
        vBtn.textContent = '☰';           // ☰
        headerEl.appendChild(vBtn);

        var vOverlay = document.createElement('div');
        vOverlay.className = 'mobile-views-overlay';
        document.body.appendChild(vOverlay);

        vBtn.addEventListener('click', function () { document.body.classList.toggle('mobile-views-open'); });
        vOverlay.addEventListener('click', function () { document.body.classList.remove('mobile-views-open'); });
    }

    // ---- Company switcher -> into the module (waffle) drawer on mobile ----
    // Declutters the tight top bar. The switcher only exists on multi-company
    // installs (renderTenantSwitcher emits nothing at N=1), so this is a no-op
    // on single-company setups. Styled for the light drawer in mobile.css.
    if (mq.matches) {
        var wafflePanel = document.getElementById('wafflePanel');
        var tenant = document.querySelector('.tenant-switcher');
        if (wafflePanel && tenant) {
            var wHead = wafflePanel.querySelector('.waffle-panel-header');
            if (wHead) wHead.insertAdjacentElement('afterend', tenant);
            else wafflePanel.insertBefore(tenant, wafflePanel.firstChild);
        }
    }

    // ---- Gmail-style collapsible ticket header ----
    // The reading pane re-renders on each open, so delegate off the document.
    // The header starts collapsed (CSS default on mobile); tapping the subject
    // row toggles the full From / To / Date / Cc meta block.
    document.addEventListener('click', function (e) {
        if (!mq.matches || !e.target.closest) return;
        var line = e.target.closest('.email-subject-line');
        if (!line || e.target.closest('.ticket-popout-toggle')) return;
        var header = line.closest('.email-header');
        if (header) header.classList.toggle('meta-open');
    });

    // ---- Section sheets: crowded reading-pane sections get their own panel ----
    // On a phone, sections that don't fit (problem/change links, properties,
    // time entries, affected CMDB objects) are moved out of the ticket into a
    // full-screen sheet, each opened by a button added to the action toolbar.
    // Each sheet lives in the DOM (display:none until opened); on desktop nothing
    // is relocated or shown (relocateSections is mq-gated), so desktop is intact.
    var SECTIONS = [
        { cls: 'links', title: 'Links',            icon: '🔗', label: 'Links',      sel: '.problem-strip',             all: true  },
        { cls: 'props', title: 'Properties',       icon: '⚙',  label: 'Properties', sel: '#ticketPropertiesContainer', all: false },
        { cls: 'time',  title: 'Time',             icon: '⏱',  label: 'Time',       sel: '#timeEntriesContainer',      all: false },
        { cls: 'cmdb',  title: 'Objects',          icon: '🖥', label: 'Objects',    sel: '#cmdbObjectsContainer',      all: false }
    ];
    SECTIONS.forEach(function (def) {
        var sheet = document.createElement('div');
        sheet.className = 'mobile-sheet mobile-sheet-' + def.cls;
        sheet.style.display = 'none';
        sheet.innerHTML =
            '<div class="ms-head"><span>' + def.title + '</span>' +
            '<button type="button" class="ms-close" aria-label="Close">Close</button></div>' +
            '<div class="ms-body"></div>';
        document.body.appendChild(sheet);
        def.sheet = sheet;
        def.body = sheet.querySelector('.ms-body');
        sheet.querySelector('.ms-close').addEventListener('click', function () { sheet.style.display = 'none'; });
    });

    // Move each section's node(s) into its sheet and add its toolbar button.
    // Runs after every ticket render (the reading pane is rebuilt each time).
    // Time/CMDB containers may still be empty (populated async) — relocating the
    // container node is fine, its async loader finds it again by id.
    function relocateSections() {
        if (!mq.matches) return;
        var rp = document.getElementById('readingPane');
        if (!rp) return;
        var toolbar = rp.querySelector('.action-toolbar');
        if (!toolbar) return;
        SECTIONS.forEach(function (def) {
            var one = def.all ? null : rp.querySelector(def.sel);
            var nodes = def.all ? rp.querySelectorAll(def.sel) : (one ? [one] : []);
            if (!nodes.length) return;
            def.body.innerHTML = '';
            Array.prototype.forEach.call(nodes, function (n) { def.body.appendChild(n); });
            if (!toolbar.querySelector('.mobile-sheet-btn-' + def.cls)) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'action-btn mobile-sheet-btn mobile-sheet-btn-' + def.cls;
                btn.innerHTML = '<span class="action-btn-icon">' + def.icon + '</span><span>' + def.label + '</span>';
                btn.addEventListener('click', function () { def.sheet.style.display = 'flex'; });
                toolbar.appendChild(btn);
            }
        });
    }

    // ---- Opened-ticket refinements ----------------------------------------
    // Run after every ticket render: relocate the section sheets, then apply
    // the reading-pane tidy-ups (subject-only heading + reference in the sub-bar,
    // attachment badge, single-row action bar with a "…" overflow).
    function afterTicketRender() {
        relocateSections();
        decorateReadingPane();
    }

    // inbox.js keeps the open ticket in the top-level `currentEmail` binding
    // (shared across classic scripts). Read it defensively.
    function getCurrentEmail() {
        return (typeof currentEmail !== 'undefined') ? currentEmail : null;
    }

    function decorateReadingPane() {
        if (!mq.matches) return;
        var rp = document.getElementById('readingPane');
        if (!rp) return;
        var email = getCurrentEmail();

        // (1) Drop the "Ticket <ref> - " prefix from the heading (leave the bare
        //     subject) and pin the reference to the right of the sub-bar.
        var subj = rp.querySelector('.email-subject-text');
        if (subj && email) subj.textContent = email.subject || '';
        var ref = bar.querySelector('.msb-ref');
        if (ref) ref.textContent = email ? (email.ticket_number || '') : '';

        // (2) Attachment badge (also refreshed async once attachments arrive).
        syncAttachBadge();

        // (3) Collapse the action bar to five icons + a "…" overflow.
        buildToolbarOverflow();
    }

    // Compact yellow attachment badge on the subject row, replacing the full
    // "…has N attachments" bar (hidden on mobile). Tapping it opens the list.
    function syncAttachBadge() {
        if (!mq.matches) return;
        var rp = document.getElementById('readingPane');
        if (!rp) return;
        var line = rp.querySelector('.email-subject-line');
        if (!line) return;
        var atts = (typeof ticketAttachments !== 'undefined' && ticketAttachments) ? ticketAttachments : [];
        var badge = line.querySelector('.mobile-attach-badge');
        if (!atts.length) { if (badge) badge.style.display = 'none'; return; }
        var regular = atts.filter(function (a) { return !a.is_inline; }).length;
        var count = regular > 0 ? regular : atts.length;
        if (!badge) {
            badge = document.createElement('button');
            badge.type = 'button';
            badge.className = 'mobile-attach-badge';
            badge.addEventListener('click', function (e) {
                e.stopPropagation();          // don't toggle the header meta
                if (typeof showAttachmentList === 'function') showAttachmentList();
            });
            line.appendChild(badge);          // last real child → rides on the right
        }
        badge.style.display = 'inline-flex';
        badge.innerHTML = '<span class="mab-clip">📎</span><span class="mab-count">' + count + '</span>';
        badge.setAttribute('aria-label', count + ' attachment' + (count === 1 ? '' : 's'));
        badge.title = count + ' attachment' + (count === 1 ? '' : 's');
    }

    // Keep the action bar to a single row: five icons + a "…" button whose panel
    // holds the rest (with their word labels). The toolbar is rebuilt on every
    // render, so this re-collapses each time.
    function buildToolbarOverflow() {
        if (!mq.matches) return;
        var rp = document.getElementById('readingPane');
        if (!rp) return;
        var toolbar = rp.querySelector('.action-toolbar');
        if (!toolbar || toolbar.querySelector('.mobile-more-btn')) return;

        var btns = Array.prototype.filter.call(toolbar.children, function (el) {
            return el.classList && el.classList.contains('action-btn');
        });
        var KEEP = 5;
        if (btns.length <= KEEP + 1) return;   // already fits in one row

        var panel = document.createElement('div');
        panel.className = 'mobile-more-panel';
        panel.style.display = 'none';

        var moreBtn = document.createElement('button');
        moreBtn.type = 'button';
        moreBtn.className = 'action-btn mobile-more-btn';
        moreBtn.setAttribute('aria-label', 'More actions');
        moreBtn.innerHTML = '<span class="action-btn-icon">⋯</span>';
        moreBtn.addEventListener('click', function () {
            panel.style.display = (panel.style.display === 'none') ? 'flex' : 'none';
        });

        btns.slice(KEEP).forEach(function (b) {
            b.addEventListener('click', function () { panel.style.display = 'none'; });
            panel.appendChild(b);
        });

        toolbar.appendChild(moreBtn);
        toolbar.appendChild(panel);
    }

    // ---- Audit history: its own full-screen sheet (LAYER 10) ---------------
    // The desktop path (showAuditHistory) builds a 5-column table in a centred
    // .modal-overlay. On a phone that table is wider than the screen, which on
    // iOS makes Safari widen the layout to a desktop width — and at that width
    // the max-width:768px rules switch off, so the modal falls back to the
    // centred desktop box (the same "spills wide → reflows to desktop" failure
    // seen with the reply modal). Rather than fight that, mobile routes audit
    // through the SAME .mobile-sheet mechanism the Links/Properties/Time/Objects
    // sheets use — a position:fixed; inset:0 panel that's always full-screen —
    // and fills it with the narrow day-grouped feed, which can never spill.
    // Audit history isn't in the reading pane to relocate, so it's fetched on
    // demand (the same endpoint inbox.js uses). Desktop is untouched.
    var auditSheet = document.createElement('div');
    auditSheet.className = 'mobile-sheet mobile-sheet-audit';
    auditSheet.style.display = 'none';
    auditSheet.innerHTML =
        '<div class="ms-head"><span>History</span>' +
        '<button type="button" class="ms-close" aria-label="Close">Close</button></div>' +
        '<div class="ms-body"></div>';
    document.body.appendChild(auditSheet);
    var auditBody = auditSheet.querySelector('.ms-body');
    auditSheet.querySelector('.ms-close').addEventListener('click', function () { auditSheet.style.display = 'none'; });

    // On mobile, intercept the audit action entirely: open our sheet instead of
    // letting inbox.js build the desktop table modal. Desktop calls straight
    // through, unchanged.
    if (typeof window.showAuditHistory === 'function') {
        var _showAudit = window.showAuditHistory;
        window.showAuditHistory = function () {
            if (mq.matches) { openAuditSheet(); return; }
            return _showAudit.apply(this, arguments);
        };
    }

    function openAuditSheet() {
        var email = getCurrentEmail();
        if (!email || !email.ticket_id) return;
        auditBody.innerHTML = '<p class="ma-note">Loading…</p>';
        auditSheet.style.display = 'flex';
        var base = (typeof API_BASE !== 'undefined') ? API_BASE : 'api/';
        fetch(base + 'get_ticket_audit.php?ticket_id=' + encodeURIComponent(email.ticket_id))
            .then(function (r) { return r.json(); })
            .then(function (data) { renderAuditFeed((data && data.success && data.audit) ? data.audit : []); })
            .catch(function () { auditBody.innerHTML = '<p class="ma-note error">Failed to load history.</p>'; });
    }

    // Split "Mon, 14 Jul 2026 09:32 AM" (formatFullDateTime's shape) into the
    // day — said once, as a sticky heading — and the time, kept per entry. If
    // the format ever changes and the time can't be found, the whole stamp
    // rides in the time slot and the day headings simply don't appear.
    function splitStamp(text) {
        var m = /^(.*?)[\s,]*(\d{1,2}:\d{2}(?:\s?[AP]M)?)$/i.exec((text || '').trim());
        return m ? { day: m[1].trim(), time: m[2] } : { day: '', time: (text || '').trim() };
    }

    function span(cls, text) {
        var el = document.createElement('span');
        el.className = cls;
        el.textContent = text;         // textContent — safe, no manual escaping
        return el;
    }

    // Build the day-grouped card feed from the audit rows (newest first, as the
    // endpoint returns them). One card per change: field + time on top, old →
    // new beneath, who did it under that; the date is a sticky heading said
    // once per day.
    function renderAuditFeed(entries) {
        auditBody.innerHTML = '';
        if (!entries.length) {
            auditBody.appendChild(span('ma-note', 'No history for this ticket.'));
            return;
        }
        var lastDay = null;
        entries.forEach(function (e) {
            var stampText = (typeof formatFullDateTime === 'function')
                ? formatFullDateTime(e.created_datetime) : (e.created_datetime || '');
            var stamp = splitStamp(stampText);
            var field = (e.field_name || '').trim();
            var oldV  = (e.old_value || '').trim();
            var newV  = (e.new_value || '').trim();
            var who   = (e.analyst_name || 'Unknown').trim();

            if (stamp.day && stamp.day !== lastDay) {
                lastDay = stamp.day;
                auditBody.appendChild(span('ma-day', stamp.day));
            }

            var entry = document.createElement('div');
            entry.className = 'ma-entry';

            var top = document.createElement('div');
            top.className = 'ma-top';
            top.appendChild(span('ma-field', field));
            top.appendChild(span('ma-time', stamp.time));
            entry.appendChild(top);

            // A first-time set (old value "-") reads better as just the new
            // value than as "- → Open".
            var vals = document.createElement('div');
            vals.className = 'ma-vals';
            if (oldV && oldV !== '-' && oldV !== '') {
                vals.appendChild(span('ma-old', oldV));
                vals.appendChild(span('ma-arrow', '→'));
            }
            vals.appendChild(span('ma-new', (newV && newV !== '-') ? newV : '—'));
            entry.appendChild(vals);

            entry.appendChild(span('ma-who', who));
            auditBody.appendChild(entry);
        });
    }

    // Close the overflow panel when tapping outside it (or its button).
    document.addEventListener('click', function (e) {
        if (!mq.matches || !e.target.closest) return;
        var panel = document.querySelector('.mobile-more-panel');
        if (!panel || panel.style.display === 'none') return;
        if (e.target.closest('.mobile-more-panel') || e.target.closest('.mobile-more-btn')) return;
        panel.style.display = 'none';
    });

    // Injected chrome (sub-bar + views hamburger) is mobile-only; keep it out of
    // desktop entirely (belt-and-suspenders alongside the @media-only styling).
    function syncBar() {
        var on = mq.matches;
        bar.style.display = on ? 'flex' : 'none';
        var vb = document.querySelector('.mobile-views-btn');
        if (vb) vb.style.display = on ? '' : 'none';
        if (!on) document.body.classList.remove('mobile-views-open');   // reset on resize→desktop
    }
    syncBar();
    if (mq.addEventListener) { mq.addEventListener('change', syncBar); }
    else if (mq.addListener) { mq.addListener(syncBar); }
})();
