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

    /**
     * The views hamburger (top-right) -> right-side slide-in drawer.
     *
     * Shared, because every module header is the same component: `.header` with
     * a `.header-nav` of sub-views. Assets needs it as much as Tickets does, so
     * it is a function rather than a copy.
     */
    function injectViewsHamburger() {
        var headerEl = document.querySelector('.header');
        if (!headerEl || !document.querySelector('.header-nav')) return;
        if (document.querySelector('.mobile-views-btn')) return;   // idempotent

        var vBtn = document.createElement('button');
        vBtn.type = 'button';
        vBtn.className = 'mobile-views-btn';
        vBtn.setAttribute('aria-label', 'Views');
        vBtn.textContent = '☰';
        headerEl.appendChild(vBtn);

        var vOverlay = document.createElement('div');
        vOverlay.className = 'mobile-views-overlay';
        document.body.appendChild(vOverlay);

        vBtn.addEventListener('click', function () { document.body.classList.toggle('mobile-views-open'); });
        vOverlay.addEventListener('click', function () { document.body.classList.remove('mobile-views-open'); });
    }

    /** Company switcher into the waffle drawer — also shared, also a no-op at N=1. */
    function moveTenantIntoWaffle() {
        if (!mq.matches) return;
        var wafflePanel = document.getElementById('wafflePanel');
        var tenant = document.querySelector('.tenant-switcher');
        if (!wafflePanel || !tenant) return;
        var wHead = wafflePanel.querySelector('.waffle-panel-header');
        if (wHead) wHead.insertAdjacentElement('afterend', tenant);
        else wafflePanel.insertBefore(tenant, wafflePanel.firstChild);
    }

    // ------------------------------------------------------------------
    // The shell every opted-in page gets (#937).
    //
    // Modules via the waffle on the left, the module's own views via an
    // injected hamburger on the right, company switcher tucked into the waffle
    // drawer. That is true of the inbox, of Assets, and of the flat pages
    // (table view / dashboard / settings / servers) alike, so it runs before
    // any page-specific branch rather than inside each one.
    // ------------------------------------------------------------------
    injectViewsHamburger();
    moveTenantIntoWaffle();
    syncMorningChecksControls();

    /**
     * MORNING CHECKS (#1270) — the date picker, Today and Save to PDF move into
     * the views drawer on a phone.
     *
     * Measured on a 640px screen before this: the controls took **231px** and
     * the checks themselves got **177px**. The page is a checklist; the list is
     * the point, and it was the smallest thing on screen.
     *
     * The date HEADING stays put — you have to know which morning you are
     * looking at — and so does the All/Mine filter, which is 40px and changes
     * what the list contains. Only the 178px block of controls moves.
     *
     * ⚠️ Moved BACK on the way to desktop, not merely re-hidden: the drawer is
     * display:none above 768px, so a control left inside it would vanish
     * entirely for anyone who rotates a tablet or resizes a window.
     */
    function syncMorningChecksControls() {
        var sel = document.querySelector('.date-selector-container');
        var nav = document.querySelector('.header-nav');
        var home = document.querySelector('.date-display');
        if (!sel || !nav || !home) return;              // not this module

        if (mq.matches) {
            if (sel.parentElement !== nav) {
                sel.classList.add('mc-in-drawer');
                nav.appendChild(sel);
            }
        } else if (sel.parentElement === nav) {
            sel.classList.remove('mc-in-drawer');
            home.appendChild(sel);
        }
    }

    function syncShell() {
        var vb = document.querySelector('.mobile-views-btn');
        if (vb) vb.style.display = mq.matches ? '' : 'none';
        if (!mq.matches) document.body.classList.remove('mobile-views-open');
        syncMorningChecksControls();
    }
    syncShell();
    if (mq.addEventListener) { mq.addEventListener('change', syncShell); }
    else if (mq.addListener) { mq.addListener(syncShell); }

    // ------------------------------------------------------------------
    // ASSETS (#936) — the second module brought along.
    //
    // Two panes, not three, so the stack is list <-> detail with no folder
    // tree and no Folders button. Everything below the branch is inbox-only,
    // hence the early return: running the ticket wiring on this page would
    // wrap functions that don't exist and inject a Folders button that leads
    // nowhere.
    // ------------------------------------------------------------------
    if (document.querySelector('.assets-container')) { initAssetsMobile(); return; }

    // ------------------------------------------------------------------
    // CALENDAR (#998) — the third module.
    //
    // No pane stack at all: a calendar is one surface. The mobile job is to
    // get the sidebar off the screen (into a sheet), and to turn a tapped day
    // into an agenda, because LAYER 16b renders month events as dots with no
    // text. Guarded on #calendarGrid, not just .calendar-container, because
    // the module's other pages (table / settings) share the header but have
    // no grid to drive.
    // ------------------------------------------------------------------
    if (document.getElementById('calendarGrid')) { initCalendarMobile(); return; }

    // ------------------------------------------------------------------
    // KNOWLEDGE (#1000) — the fourth module.
    //
    // No pane stack either, and for a better reason than the calendar's:
    // `.knowledge-main` ALREADY shows one of three views at a time (list /
    // detail / editor), toggled by showView(). Nothing to slide. What that
    // state doesn't do is reach CSS, so the wrap below mirrors it onto
    // body[data-kb-view]. Guarded on .knowledge-container so the module's
    // other pages (review / assistant / settings / help) take the shell only.
    // ------------------------------------------------------------------
    if (document.querySelector('.knowledge-container')) { initKnowledgeMobile(); return; }

    // ------------------------------------------------------------------
    // SERVICE STATUS (#1003 shipped CSS-only; #1004 added this branch).
    //
    // The board needed no JS at first. Two of Ed's follow-ups do need it —
    // splitting Services and Incidents onto their own screens needs a
    // switcher that doesn't exist on desktop, and "tap anywhere on the card"
    // needs a delegated handler. Both are mq-gated, so desktop is untouched.
    // ------------------------------------------------------------------
    if (document.querySelector('.status-layout')) { initStatusMobile(); return; }

    // ------------------------------------------------------------------
    // PROBLEM MANAGEMENT (#1181).
    //
    // The module already swaps panes in place — pmOpenDetail hides #pmListView
    // and shows #pmDetailView — so it is master-detail before we touch it. The
    // only thing missing on a phone is that the sidebar (search, New, the
    // status chips) stays on screen while you are reading a problem, spending
    // ~140px of a 640px screen on controls for the list you just left.
    //
    // Wrapped rather than edited: both are top-level function declarations, so
    // they are properties of the global object and can be replaced from here.
    // A body attribute carries the state and mobile.css does the hiding — no
    // change to problem-management.js, and desktop never sees the attribute
    // because both wrappers check mq.matches before setting it.
    // ------------------------------------------------------------------
    if (document.querySelector('.pm-container')) { initProblemsMobile(); return; }

    // ------------------------------------------------------------------
    // CHANGE MANAGEMENT (#1184).
    //
    // Same need as Problem Management — the sidebar should step aside while
    // you read or edit a change — but a cleaner hook: showView('list' |
    // 'detail' | 'editor') is a single synchronous function, so one wrapper
    // covers every state and there is no promise to wait on.
    //
    // The approvals page has no view switching at all, so it falls through to
    // the shared shell with only CSS. Its own `.approvals-container` is caught
    // by the same test purely so the shell still initialises.
    // ------------------------------------------------------------------
    if (document.querySelector('.changes-container')) { initChangesMobile(); return; }
    if (document.querySelector('.approvals-container')) { return; }

    // Flat pages (Assets' table view, dashboard, settings, servers — #937) have
    // no pane stack: the shell above is the whole of their JS. The servers page
    // is the reason this test isn't just `!mc` — it DOES carry .main-container
    // (as .servers-container) but has no email list, and letting it fall into
    // the inbox wiring below would inject a Folders button onto a flat page.
    if (!mc || !document.querySelector('.email-list-container')) return;

    function initAssetsMobile() {
        function setPane(p) { document.body.setAttribute('data-mobile-pane', p); }
        function currentPane() { return document.body.getAttribute('data-mobile-pane') || 'list'; }
        function pushPane(p) {
            setPane(p);
            if (mq.matches) history.pushState({ nmPane: p }, '');
        }
        setPane('list');

        window.addEventListener('popstate', function (e) {
            if (!mq.matches) return;
            setPane((e.state && e.state.nmPane) ? e.state.nmPane : 'list');
        });

        // Sub-bar: Back only. The asset's name goes on the right so you can see
        // what you're looking at once the list has slid away.
        var aBar = document.createElement('div');
        aBar.className = 'mobile-subbar';
        aBar.innerHTML =
            '<button type="button" class="msb-back" aria-label="Back">‹ Back</button>' +
            '<span class="msb-ref" aria-label="Asset"></span>';
        mc.parentNode.insertBefore(aBar, mc);

        aBar.querySelector('.msb-back').addEventListener('click', function () {
            if (currentPane() === 'list') return;
            // Force the pane first so Back works even with nothing to pop.
            setPane('list');
            if (history.state && history.state.nmPane) history.back();
        });

        // Wrap selectAsset — never edit the module's own renderer.
        if (typeof window.selectAsset === 'function') {
            var _selectAsset = window.selectAsset;
            window.selectAsset = function (assetId) {
                var r = _selectAsset.apply(this, arguments);
                // Only when genuinely navigating list -> detail. selectAsset is
                // also called to re-render in place, and those must not stack
                // history entries.
                if (mq.matches && currentPane() !== 'detail') pushPane('detail');
                var show = function () {
                    var name = document.querySelector('.asset-detail-hostname');
                    var ref  = aBar.querySelector('.msb-ref');
                    if (ref) ref.textContent = name ? name.textContent.trim() : '';
                };
                if (r && typeof r.then === 'function') r.then(show); else show();
                return r;
            };
        }

        function syncAssetsBar() {
            var on = mq.matches;
            aBar.style.display = on ? 'flex' : 'none';
            var vb = document.querySelector('.mobile-views-btn');
            if (vb) vb.style.display = on ? '' : 'none';
            if (!on) {
                document.body.classList.remove('mobile-views-open');
                document.body.removeAttribute('data-mobile-pane');   // desktop shows both panes
            } else if (!document.body.getAttribute('data-mobile-pane')) {
                setPane('list');
            }
        }
        syncAssetsBar();
        if (mq.addEventListener) { mq.addEventListener('change', syncAssetsBar); }
        else if (mq.addListener) { mq.addListener(syncAssetsBar); }
    }

    /* ==================================================================
       CALENDAR (#998)

       Paired with mobile.css LAYER 16. Same wrap-don't-edit contract as the
       other two modules: itsm_calendar.js is never touched. It is a classic
       script, so its top-level `let`/`const` (currentView, events, MONTHS)
       are readable here as bare identifiers, and its `function` declarations
       (openEventModal, getEventsForDate, …) are window properties we can wrap.

       Three pieces:
         1. a sub-bar carrying the two actions the hidden sidebar owned;
         2. an OPTIONS sheet holding the relocated sidebar itself;
         3. an AGENDA sheet — the other half of the dots decision. A month
            cell shows coloured dots and no text, so tapping the day has to
            answer "what are they?". It replaces the desktop behaviour of
            tapping a day (which opens a blank New-event form) — that action
            moves to a button inside the agenda, pre-filled with the day.
       ================================================================== */
    function initCalendarMobile() {
        var container = document.querySelector('.calendar-container');
        if (!container) return;

        /* Prefer the module's own translations; fall back only if a key is
           missing (i18n's lookup echoes the key back when it can't resolve). */
        function tr(key, fallback) {
            if (typeof window.t !== 'function') return fallback;
            var v = window.t(key);
            return (!v || v === key) ? fallback : v;
        }

        // ---- sub-bar: the two actions the hidden sidebar used to carry ----
        var bar = document.createElement('div');
        bar.className = 'mobile-subbar';
        bar.style.display = 'none';          // @media CSS can't hide injected chrome
        var optLabel = tr('calendar.sidebar.categories', 'Categories');
        var newLabel = tr('calendar.sidebar.new_event', 'New event');
        bar.innerHTML =
            '<button type="button" class="msb-calopts">⚙ <span></span></button>' +
            '<button type="button" class="msb-new">+ <span></span></button>';
        bar.querySelector('.msb-calopts span').textContent = optLabel;
        bar.querySelector('.msb-new span').textContent = newLabel;
        bar.querySelector('.msb-calopts').setAttribute('aria-label', optLabel);
        bar.querySelector('.msb-new').setAttribute('aria-label', newLabel);
        container.parentNode.insertBefore(bar, container);

        // ---- sheet chrome (LAYER 7's .mobile-sheet, built twice) ----
        function buildSheet(cls, title) {
            var s = document.createElement('div');
            s.className = 'mobile-sheet mobile-sheet-' + cls;
            s.style.display = 'none';        // as above — inline, not @media
            s.innerHTML =
                '<div class="ms-head"><span class="ms-title"></span>' +
                '<button type="button" class="ms-close"></button></div>' +
                '<div class="ms-body"></div>';
            s.querySelector('.ms-title').textContent = title;
            s.querySelector('.ms-close').textContent = tr('calendar.subscribe.close', 'Close');
            s.querySelector('.ms-close').addEventListener('click', closeSheet);
            document.body.appendChild(s);
            return s;
        }
        var optsSheet = buildSheet('calopts', optLabel);
        var daySheet  = buildSheet('calday', '');

        /* Opening a sheet pushes a history entry so the DEVICE BACK BUTTON
           closes it, the same move that makes the ticket pane stack feel
           native rather than like a resized website. */
        function openSheet(el) {
            el.style.display = 'flex';
            history.pushState({ calSheet: true }, '');
        }
        function hideSheets() {
            optsSheet.style.display = 'none';
            daySheet.style.display = 'none';
        }
        function closeSheet() {
            if (history.state && history.state.calSheet) history.back();
            else hideSheets();
        }
        window.addEventListener('popstate', function () { hideSheets(); });

        // ---- 1. options sheet = the real sidebar, moved ----
        /* Relocated rather than rebuilt so `#categoryFilterList` keeps its id
           and renderCategoryFilters() still finds it, and so the subscribe
           block keeps its own wiring. Moved lazily on first open and moved
           BACK when the viewport leaves mobile, so resizing a desktop browser
           through the breakpoint can't strand the sidebar inside a hidden
           sheet (16a hides it in the container). */
        function sidebarIntoSheet() {
            var sb = container.querySelector('.calendar-sidebar');
            if (!sb) return;                                  // already moved
            // The sidebar's own New-event button duplicates the sub-bar's.
            var dup = sb.querySelector('.sidebar-section .btn-full[onclick*="openEventModal"]');
            if (dup && dup.parentNode) dup.parentNode.classList.add('mc-dup');
            optsSheet.querySelector('.ms-body').appendChild(sb);
        }
        function sidebarBackToPage() {
            var sb = optsSheet.querySelector('.calendar-sidebar');
            if (sb) container.insertBefore(sb, container.firstChild);
        }
        bar.querySelector('.msb-calopts').addEventListener('click', function () {
            sidebarIntoSheet();
            openSheet(optsSheet);
        });

        // ---- 2. New event: straight through to the module's own modal ----
        bar.querySelector('.msb-new').addEventListener('click', function () {
            if (typeof _openEventModal === 'function') _openEventModal();
        });

        // ---- 3. agenda sheet for a tapped day ----
        var agendaDate = null;

        function localDateLabel(dateStr) {
            var d = new Date(dateStr + 'T00:00:00');
            if (isNaN(d.getTime())) return dateStr;
            /* Renders through the shared formatters (assets/js/tz.js), so the
               weekday and month names follow the interface language and the
               arrangement follows the analyst's chosen date format - rather
               than the module's hardcoded English DAYS/MONTHS arrays. The
               value is date-only, so it is formatted NAIVELY. */
            try {
                return fmtNaiveWeekday(d, true) + ' ' + fmtNaiveTemplate(d, 'D MONTH');
            } catch (e) {
                return dateStr;
            }
        }

        function renderAgenda() {
            if (!agendaDate) return;
            var body = daySheet.querySelector('.ms-body');
            body.innerHTML = '';
            daySheet.querySelector('.ms-title').textContent = localDateLabel(agendaDate);

            var list = (typeof window.getEventsForDate === 'function')
                ? window.getEventsForDate(agendaDate) : [];

            list.forEach(function (ev) {
                var row = document.createElement('button');
                row.type = 'button';
                row.className = 'mc-ag-item';

                var dot = document.createElement('span');
                dot.className = 'mc-ag-dot';
                dot.style.backgroundColor = ev.category_color || '#ef6c00';
                row.appendChild(dot);

                var main = document.createElement('div');
                main.className = 'mc-ag-main';
                /* textContent throughout — no escapeHtml/innerHTML round trip. */
                var title = document.createElement('div');
                title.className = 'mc-ag-title';
                title.textContent = ev.title || '';
                main.appendChild(title);

                if (typeof window.formatEventTime === 'function') {
                    var time = document.createElement('div');
                    time.className = 'mc-ag-time';
                    // The module's own formatter, so the agenda reads exactly
                    // like the rest of the calendar (one formatter, not two).
                    time.textContent = window.formatEventTime(ev);
                    main.appendChild(time);
                }
                if (ev.location) {
                    var loc = document.createElement('div');
                    loc.className = 'mc-ag-loc';
                    loc.textContent = ev.location;
                    main.appendChild(loc);
                }
                if (ev.category_name) {
                    var cat = document.createElement('div');
                    cat.className = 'mc-ag-cat';
                    cat.textContent = ev.category_name;
                    main.appendChild(cat);
                }
                row.appendChild(main);

                // Tapping a row opens the module's edit modal ON TOP of the
                // sheet (.modal is z-index 2000 vs the sheet's 1500), so
                // closing it drops you back into the agenda you came from.
                row.addEventListener('click', function () {
                    if (typeof _openEventModal === 'function') _openEventModal(ev.id);
                });
                body.appendChild(row);
            });

            /* No "no events" line: it would be a new string, and an EN-only
               key falls back silently in the other 23 locales. On an empty day
               the date heading plus this button say it well enough. */
            var add = document.createElement('button');
            add.type = 'button';
            add.className = 'mc-ag-new';
            add.textContent = '+ ' + newLabel;
            add.addEventListener('click', function () {
                if (typeof _openEventModal === 'function') _openEventModal(null, agendaDate);
            });
            body.appendChild(add);
        }

        function openDaySheet(dateStr) {
            agendaDate = dateStr;
            renderAgenda();
            openSheet(daySheet);
        }

        // ---- wrap the module's globals (never edit itsm_calendar.js) ----
        var _openEventModal = window.openEventModal;
        if (typeof _openEventModal === 'function') {
            window.openEventModal = function (eventId, dateStr, hour) {
                /* Only the month grid's day-cell click is redirected: it is the
                   one call that means "I tapped a day", and on mobile that has
                   to answer the dots rather than open a blank form. Every other
                   caller passes an id (edit), an hour (a week/day time slot) or
                   nothing at all (New event) and goes straight through. */
                if (mq.matches && !eventId && dateStr &&
                    (hour === null || hour === undefined) &&
                    typeof currentView !== 'undefined' && currentView === 'month') {
                    openDaySheet(dateStr);
                    return;
                }
                return _openEventModal.apply(this, arguments);
            };
        }

        /* Week and day views are 24 rows of 60px and open at the top, so a
           phone lands on 12 AM — three screens above anything that happens in
           a working day. On a desktop pane you at least see through to ~10 AM;
           at 360px you see 12 AM to 6 AM and nothing else. Scroll to 7 AM after
           a render. Mobile only: the desktop start position is untouched. */
        function scrollToWorkingHours() {
            if (!mq.matches) return;
            var body = document.querySelector('.week-body, .day-body');
            if (body && body.scrollTop === 0) body.scrollTop = 7 * 60;
        }

        // Saving, deleting or filtering re-renders the calendar and reloads
        // `events`; if the agenda is open behind the modal it would still be
        // showing the old list, so refresh it off the same promise.
        if (typeof window.renderCalendar === 'function') {
            var _renderCalendar = window.renderCalendar;
            window.renderCalendar = function () {
                var r = _renderCalendar.apply(this, arguments);
                var after = function () {
                    if (mq.matches && daySheet.style.display === 'flex') renderAgenda();
                    scrollToWorkingHours();
                };
                if (r && typeof r.then === 'function') r.then(after); else after();
                return r;
            };
        }

        function syncCalendarBar() {
            var on = mq.matches;
            bar.style.display = on ? 'flex' : 'none';
            var vb = document.querySelector('.mobile-views-btn');
            if (vb) vb.style.display = on ? '' : 'none';
            if (!on) {
                document.body.classList.remove('mobile-views-open');
                hideSheets();
                sidebarBackToPage();
            }
        }
        syncCalendarBar();
        if (mq.addEventListener) { mq.addEventListener('change', syncCalendarBar); }
        else if (mq.addListener) { mq.addListener(syncCalendarBar); }
    }

    /* ==================================================================
       SERVICE STATUS (#1004)

       Two behaviours, both additive:
         1. a Services / Incidents switcher, because a board plus a feed on
            one scroll is a lot of thumb;
         2. the whole incident card opens the incident, not just its title.
       ================================================================== */
    function initChangesMobile() {
        /* Local, like every other module branch has: the sibling `tr`s are
           nested inside THEIR functions and are not in scope here. A missing
           one is a runtime ReferenceError that no parse check would catch. */
        function tr(key, fallback) {
            if (typeof window.t !== 'function') return fallback;
            var v = window.t(key);
            return (!v || v === key) ? fallback : v;
        }

        function setPane(name) {
            if (!mq.matches) { document.body.removeAttribute('data-cm-pane'); return; }
            document.body.setAttribute('data-cm-pane', name);
        }

        // Wrap, don't edit. showView is a top-level declaration, so it is a
        // property of the global object; the original does the real work and
        // this only records which pane won.
        var showView = window.showView;
        if (typeof showView === 'function') {
            window.showView = function (view) {
                var out = showView.apply(this, arguments);
                setPane(view === 'detail' || view === 'editor' ? view : 'list');
                return out;
            };
        }

        /* =================================================================
           Rich text on a phone: CARDS, and ONE editor at a time  (#1189)
           =================================================================
           #1187 put the whole tabbed widget full screen. That fixed the
           typing but left six TinyMCE instances living in the form, and Ed
           came back with "the tinymce editor is still causing a bit of havoc
           with the screen layout" — which it was: six iframes, each with its
           own toolbar, sizing themselves independently inside a 312px column.

           So on a phone the widget becomes a list of read-only CARDS — field
           name, a plain-text excerpt, Edit — and TinyMCE is initialised for
           ONE field only, on demand, straight into the full-screen panel.
           Nothing rich-text renders in the form itself.

           🔴 THE TRAP, and the reason this needs care rather than a display
           rule. `saveChange()` reads all six through `getEditorContent(id)`,
           which returns '' when `tinymce.get(id)` finds nothing — and
           `editorsReady` is set but NEVER READ, so nothing guards it. Simply
           not initialising the editors would make Save silently blank all six
           fields. The textarea therefore becomes the source of truth on
           mobile, and the two accessors are wrapped to use it.

           Everything here is wrap-don't-edit: initEditors, destroyEditors,
           setEditorContent and getEditorContent are all top-level
           declarations, so they are properties of the global object.
           `editorIds` is a top-level `const` and is NOT — the field list is
           read from the DOM instead. */
        function wireRichTextCards() {
            if (!mq.matches) return;
            var widget = document.getElementById('cmRichTextWidget');
            if (!widget || widget.dataset.cmCards) return;
            widget.dataset.cmCards = '1';

            /* All three keys already exist — the detail view says exactly
               these words about exactly these fields, so a phone borrows them
               rather than adding three more strings to 24 locales. */
            var editLabel = tr('change-management.detail.edit', 'Edit');
            var emptyLabel = tr('change-management.detail.not_provided', 'Not provided');
            var closeLabel = tr('common.close', 'Close');

            function panels() {
                return [].slice.call(widget.querySelectorAll('.rich-text-panel'));
            }
            function tabFor(key) {
                return widget.querySelector('.rich-text-tab[data-field-key="' + key + '"]');
            }
            function areaFor(key) {
                var p = widget.querySelector('.rich-text-panel[data-field-key="' + key + '"]');
                return p && p.querySelector('textarea');
            }
            function liveEditor(id) {
                return (id && window.tinymce && window.tinymce.get(id)) || null;
            }

            /* The card shows an EXCERPT, never the stored markup. Assigning it
               through textContent means no author-written HTML is ever parsed
               into this page, so the card needs no sanitiser — see the
               safe-html rule. `innerHTML` on a detached div is only used to
               let the browser do entity decoding and tag stripping for us. */
            function excerpt(html) {
                var box = document.createElement('div');
                box.innerHTML = html || '';
                var text = (box.textContent || '').replace(/\s+/g, ' ').trim();
                return text.length > 140 ? text.slice(0, 140) + '…' : text;
            }

            /* ---- the full-screen panel (kept from #1187, now single-field) -- */
            var barTitle = document.createElement('span');
            barTitle.className = 'cm-fs-title';
            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'ms-close';
            closeBtn.textContent = closeLabel;
            var bar = document.createElement('div');
            bar.className = 'cm-fs-bar';
            bar.appendChild(barTitle);
            bar.appendChild(closeBtn);

            var list = document.createElement('div');
            list.className = 'cm-rt-cards';

            /* Both go INSIDE the widget. `refreshFormLayout()` re-parents it
               with `host.appendChild(richTextWidget)` so it follows the anchor
               section, and hides it outright when no section anchors it —
               anything left outside would be stranded in the old section, or
               left offering an editor for a widget that is `display: none`. */
            widget.insertBefore(bar, widget.firstChild);
            widget.insertBefore(list, bar.nextSibling);

            var openKey = null;

            function renderCards() {
                list.innerHTML = '';
                panels().forEach(function (panel) {
                    var key = panel.dataset.fieldKey;
                    var tab = tabFor(key);
                    /* Respect the module's own per-field visibility. A field
                       switched off in Form fields settings hides its TAB, and
                       that is the only place the flag is expressed. */
                    if (!tab || tab.style.display === 'none') return;
                    var area = areaFor(key);
                    if (!area) return;

                    var card = document.createElement('button');
                    card.type = 'button';                 // never submits the form
                    card.className = 'cm-rt-card';
                    card.dataset.fieldKey = key;

                    var head = document.createElement('span');
                    head.className = 'cm-rt-card-head';
                    var name = document.createElement('span');
                    name.className = 'cm-rt-card-name';
                    name.textContent = tab.textContent.trim();
                    var act = document.createElement('span');
                    act.className = 'cm-rt-card-edit';
                    act.textContent = editLabel;
                    head.appendChild(name);
                    head.appendChild(act);

                    var body = document.createElement('span');
                    var text = excerpt(currentValue(key));
                    body.className = 'cm-rt-card-body' + (text ? '' : ' cm-rt-card-empty');
                    body.textContent = text || emptyLabel;

                    card.appendChild(head);
                    card.appendChild(body);
                    /* The WHOLE card opens the field, not just the Edit word —
                       the same call made for the incident cards in LAYER 23. */
                    card.addEventListener('click', function () { openField(key); });
                    list.appendChild(card);
                });
            }

            function currentValue(key) {
                var area = areaFor(key);
                if (!area) return '';
                var ed = liveEditor(area.id);
                return ed ? ed.getContent() : area.value;
            }

            function openField(key) {
                var area = areaFor(key);
                if (!area) return;
                openKey = key;

                panels().forEach(function (p) { p.classList.toggle('active', p.dataset.fieldKey === key); });
                var tab = tabFor(key);
                barTitle.textContent = tab ? tab.textContent.trim() : '';

                document.body.classList.add('cm-editor-full');
                history.pushState({ cmFull: true }, '');

                /* One editor, created here and destroyed on the way out. The
                   init mirrors change-management.js's own so the phone gets
                   the same toolbar and the same dark-mode skin. */
                if (!liveEditor(area.id) && window.tinymce) {
                    var dark = (document.documentElement.getAttribute('data-theme-mode') || 'light') === 'dark';
                    window.tinymce.init({
                        selector: '#' + area.id,
                        license_key: 'gpl',
                        menubar: false,
                        statusbar: false,
                        skin: dark ? 'oxide-dark' : 'oxide',
                        content_css: dark ? 'dark' : 'default',
                        plugins: ['advlist', 'autolink', 'lists', 'link', 'wordcount'],
                        toolbar: 'undo redo | bold italic underline | bullist numlist | link | removeformat',
                        content_style: 'body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 16px; }',
                        setup: function (editor) {
                            editor.on('init', function () {
                                editor.setContent(area.value || '');
                                window.dispatchEvent(new Event('resize'));
                            });
                        }
                    });
                }
            }

            function closeField() {
                document.body.classList.remove('cm-editor-full');
                if (openKey) {
                    var area = areaFor(openKey);
                    var ed = area && liveEditor(area.id);
                    /* Write back BEFORE removing — `editor.remove()` is what
                       would otherwise be the last chance to read it. */
                    if (ed && area) { area.value = ed.getContent(); ed.remove(); }
                    openKey = null;
                }
                renderCards();
            }

            closeBtn.addEventListener('click', function () {
                if (history.state && history.state.cmFull) history.back();
                else closeField();
            });
            window.addEventListener('popstate', function () {
                if (document.body.classList.contains('cm-editor-full')) closeField();
            });

            /* ---- the four wrapped accessors ---------------------------------
               With no editors in the form, the textarea IS the field. */
            var _init = window.initEditors, _destroy = window.destroyEditors;
            var _set = window.setEditorContent, _get = window.getEditorContent;

            if (typeof _init === 'function') {
                window.initEditors = function (callback) {
                    if (!mq.matches) return _init.apply(this, arguments);
                    /* 🔴 The create path clears through `tinymce.get(id)`
                       directly rather than through setEditorContent, so with
                       no editors it would clear NOTHING and a new change would
                       open pre-filled with the last one's text. Clearing here
                       covers both callers. */
                    panels().forEach(function (p) {
                        var a = p.querySelector('textarea');
                        if (a) { var e = liveEditor(a.id); if (e) e.remove(); a.value = ''; }
                    });
                    if (callback) callback();
                    renderCards();
                    return undefined;
                };
            }
            if (typeof _destroy === 'function') {
                window.destroyEditors = function () {
                    if (!mq.matches) return _destroy.apply(this, arguments);
                    if (document.body.classList.contains('cm-editor-full')) closeField();
                    return _destroy.apply(this, arguments);   // safe: it no-ops when nothing is live
                };
            }
            if (typeof _set === 'function') {
                window.setEditorContent = function (id, content) {
                    if (!mq.matches || liveEditor(id)) return _set.apply(this, arguments);
                    var a = document.getElementById(id);
                    if (a) a.value = content || '';
                    renderCards();
                };
            }
            if (typeof _get === 'function') {
                window.getEditorContent = function (id) {
                    var ed = liveEditor(id);
                    if (ed) return ed.getContent();          // mid-edit: the editor is ahead of the textarea
                    if (!mq.matches) return _get.apply(this, arguments);
                    var a = document.getElementById(id);
                    return a ? a.value : '';
                };
            }

            /* refreshFormLayout() decides which fields are visible and runs on
               every editor open, so the cards are rebuilt behind it. */
            var _refresh = window.refreshFormLayout;
            if (typeof _refresh === 'function') {
                window.refreshFormLayout = function () {
                    var out = _refresh.apply(this, arguments);
                    if (mq.matches) renderCards();
                    return out;
                };
            }

            renderCards();
        }

        setPane('list');
        wireRichTextCards();
        var sync = function () {
            if (!mq.matches) {
                document.body.removeAttribute('data-cm-pane');
                document.body.classList.remove('cm-editor-full');   // never strand it on desktop
            }
        };
        if (mq.addEventListener) { mq.addEventListener('change', sync); }
        else if (mq.addListener) { mq.addListener(sync); }
    }

    function initProblemsMobile() {
        function setPane(name) {
            if (!mq.matches) { document.body.removeAttribute('data-pm-pane'); return; }
            document.body.setAttribute('data-pm-pane', name);
        }

        // Wrap, don't edit. Keep the original and call it, so every behaviour
        // the module already has — history, scroll reset, caching — is intact.
        var openDetail = window.pmOpenDetail;
        if (typeof openDetail === 'function') {
            window.pmOpenDetail = function () {
                var out = openDetail.apply(this, arguments);
                // pmOpenDetail is async and bails on a failed fetch, so the pane
                // is only marked once it has actually resolved. Marking it up
                // front would strand the sidebar hidden behind an error toast.
                Promise.resolve(out).then(function () {
                    var dv = document.getElementById('pmDetailView');
                    if (dv && dv.style.display !== 'none') setPane('detail');
                }).catch(function () { /* module already toasts */ });
                return out;
            };
        }

        var backToList = window.pmBackToList;
        if (typeof backToList === 'function') {
            window.pmBackToList = function () {
                setPane('list');
                return backToList.apply(this, arguments);
            };
        }

        setPane('list');
        // Rotating to a desktop width must not leave the sidebar hidden.
        var sync = function () { if (!mq.matches) document.body.removeAttribute('data-pm-pane'); };
        if (mq.addEventListener) { mq.addEventListener('change', sync); }
        else if (mq.addListener) { mq.addListener(sync); }
    }

    function initStatusMobile() {
        var layout = document.querySelector('.status-layout');
        if (!layout) return;

        function tr(key, fallback) {
            if (typeof window.t !== 'function') return fallback;
            var v = window.t(key);
            return (!v || v === key) ? fallback : v;
        }

        /* The services heading and grid are siblings with nothing wrapping
           them, so they are MARKED rather than restructured — CSS can then
           hide them as a unit. Marking beats `:first-of-type` here: a heading
           added above would silently re-point a positional selector, whereas
           a class says which nodes are meant. */
        var grid = layout.querySelector('.service-grid');
        var firstTitle = layout.querySelector('.section-title');
        if (grid) grid.classList.add('ss-services-part');
        if (firstTitle) firstTitle.classList.add('ss-services-part');

        var switcher = document.createElement('div');
        switcher.className = 'ss-switch';
        switcher.style.display = 'none';         // @media CSS can't hide injected chrome
        switcher.innerHTML = '<button type="button" data-ss="services"></button>' +
                             '<button type="button" data-ss="incidents"></button>';
        var btns = switcher.querySelectorAll('button');
        btns[0].textContent = tr('service-status.board.services', 'Services');
        btns[1].textContent = tr('service-status.board.incidents', 'Incidents');
        layout.insertBefore(switcher, layout.firstChild);

        function setTab(name) {
            document.body.setAttribute('data-ss-tab', name);
            btns.forEach(function (b) {
                var on = b.dataset.ss === name;
                b.classList.toggle('active', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            layout.scrollTop = 0;
        }
        btns.forEach(function (b) {
            b.addEventListener('click', function () { setTab(b.dataset.ss); });
        });

        /* Tap anywhere on an incident card. Delegated, because the rows are
           re-rendered on every poll. It CLICKS THE TITLE rather than calling
           editIncident(id) directly: the id lives only in that element's
           inline handler, so going through it means there is still exactly
           one place that knows how to open an incident. */
        document.addEventListener('click', function (e) {
            if (!mq.matches) return;
            var row = e.target.closest && e.target.closest('#incidentList tr');
            if (!row) return;
            // The title has its own handler — let it do its job, don't double-fire.
            if (e.target.closest('.incident-title')) return;
            var title = row.querySelector('.incident-title');
            if (title) title.click();
        });

        function syncStatusBar() {
            var on = mq.matches;
            switcher.style.display = on ? 'flex' : 'none';
            var vb = document.querySelector('.mobile-views-btn');
            if (vb) vb.style.display = on ? '' : 'none';
            if (on) {
                if (!document.body.getAttribute('data-ss-tab')) setTab('services');
            } else {
                document.body.classList.remove('mobile-views-open');
                // Desktop shows both halves — never leave one hidden.
                document.body.removeAttribute('data-ss-tab');
            }
        }
        syncStatusBar();
        if (mq.addEventListener) { mq.addEventListener('change', syncStatusBar); }
        else if (mq.addListener) { mq.addListener(syncStatusBar); }
    }

    /* ==================================================================
       KNOWLEDGE (#1000)

       Paired with mobile.css LAYER 17. knowledge.js is not edited here — the
       one change it needed (16px inside the TinyMCE iframe, which CSS cannot
       reach) is a `@media (pointer: coarse)` block in its `content_style`,
       the same single justified edit inbox.js took in #766.

       Three pieces:
         1. the search box, MOVED into the sub-bar — on a phone the primary
            action in a knowledge base is finding one article, so it must not
            be behind a button. The tag filters and the two buttons go into a
            sheet; search does not.
         2. showView() mirrored onto body[data-kb-view] so CSS can react.
         3. the editor pop-out neutralised — a localStorage desktop mode, the
            exact shape of the #762 tickets bug.
       ================================================================== */
    function initKnowledgeMobile() {
        var container = document.querySelector('.knowledge-container');
        if (!container) return;

        function tr(key, fallback) {
            if (typeof window.t !== 'function') return fallback;
            var v = window.t(key);
            return (!v || v === key) ? fallback : v;
        }
        var tagsLabel = tr('knowledge.editor.field_tags', 'Tags');

        // ---- sub-bar: the real search input + the sheet button ----
        var bar = document.createElement('div');
        bar.className = 'mobile-subbar';
        bar.style.display = 'none';          // @media CSS can't hide injected chrome
        bar.innerHTML = '<button type="button" class="msb-kbopts">☰ <span></span></button>';
        bar.querySelector('.msb-kbopts span').textContent = tagsLabel;
        bar.querySelector('.msb-kbopts').setAttribute('aria-label', tagsLabel);
        container.parentNode.insertBefore(bar, container);

        // ---- sheet chrome (LAYER 7's .mobile-sheet) ----
        var sheet = document.createElement('div');
        sheet.className = 'mobile-sheet mobile-sheet-kbopts';
        sheet.style.display = 'none';
        sheet.innerHTML =
            '<div class="ms-head"><span class="ms-title"></span>' +
            '<button type="button" class="ms-close"></button></div>' +
            '<div class="ms-body"></div>';
        sheet.querySelector('.ms-title').textContent = tagsLabel;
        sheet.querySelector('.ms-close').textContent = tr('knowledge.modal.close', tr('common.close', 'Close'));
        sheet.querySelector('.ms-close').addEventListener('click', closeSheet);
        document.body.appendChild(sheet);

        function openSheet() {
            sheet.style.display = 'flex';
            history.pushState({ kbSheet: true }, '');
        }
        function hideSheet() { sheet.style.display = 'none'; }
        function closeSheet() {
            if (history.state && history.state.kbSheet) history.back();
            else hideSheet();
        }
        window.addEventListener('popstate', function () { hideSheet(); });

        /* The search box is MOVED, not copied — `#articleSearch` keeps its id
           and its inline `onkeyup="debounceSearch()"`, so the module's own
           search keeps working with no rewiring. Its now-empty section in the
           sidebar is marked rather than found by position. */
        function sidebarIntoPlace() {
            var sb = container.querySelector('.knowledge-sidebar');
            if (!sb) return;                                  // already moved
            var box = sb.querySelector('.search-box');
            if (box) {
                var sec = box.closest('.sidebar-section');
                if (sec) sec.classList.add('kb-dup');          // heading with nothing under it
                bar.insertBefore(box, bar.firstChild);
            }
            sheet.querySelector('.ms-body').appendChild(sb);
        }
        function sidebarBackToPage() {
            var sb = sheet.querySelector('.knowledge-sidebar');
            if (!sb) return;
            var box = bar.querySelector('.search-box');
            var sec = sb.querySelector('.sidebar-section.kb-dup');
            if (box && sec) { sec.classList.remove('kb-dup'); sec.appendChild(box); }
            container.insertBefore(sb, container.firstChild);
        }

        bar.querySelector('.msb-kbopts').addEventListener('click', function () {
            sidebarIntoPlace();
            openSheet();
        });
        // Picking a tag filters the list behind the sheet; close it so you can
        // see what you just did.
        sheet.addEventListener('click', function (e) {
            if (e.target.closest('.tag-filter, .btn-full')) closeSheet();
        });

        /* ---- "Back to list" -> "Back" ----
           Four buttons share one row on a phone, and the long label is what
           stops them fitting. `common.back` was added for this and harvested
           from each locale's existing translation of the same word, so no
           locale falls back to English. The desktop label is restored when
           the viewport leaves mobile — the element is shared, not duplicated. */
        var backLink = document.querySelector('.article-detail-header > .btn');
        var backLong = backLink ? backLink.textContent.trim() : '';
        var backShort = tr('common.back', backLong);
        function syncBackLabel() {
            if (!backLink) return;
            backLink.textContent = mq.matches ? backShort : backLong;
        }

        /* ---- the collapsible meta block (Gmail-style) ----
           The whole meta row is the control: a bigger target than a chevron,
           and its accessible name is the visible "Modified: …" text, so the
           toggle needs no label string in 24 languages. The reading pane is
           rebuilt on every article open, so this re-runs after each render
           and is idempotent. */
        function wireMetaToggle() {
            if (!mq.matches) return;
            var head = document.querySelector('.article-content-header');
            var meta = head && head.querySelector('.article-content-meta');
            if (!meta || meta.dataset.kbToggle) return;      // idempotent
            meta.dataset.kbToggle = '1';
            meta.setAttribute('role', 'button');
            meta.setAttribute('tabindex', '0');
            meta.setAttribute('aria-expanded', 'false');
            function toggle() {
                var open = head.classList.toggle('kb-meta-open');
                meta.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            meta.addEventListener('click', toggle);
            meta.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
            });
        }

        if (typeof window.renderArticleDetail === 'function') {
            var _renderArticleDetail = window.renderArticleDetail;
            window.renderArticleDetail = function () {
                var r = _renderArticleDetail.apply(this, arguments);
                wireMetaToggle();
                return r;
            };
        }
        wireMetaToggle();          // an article opened straight from a ?article= URL

        /* ---- full-screen text editing ----
           An "expand" control above the editor, and a Close bar inside it.
           Both labels reuse existing translated keys, so no new strings:
           `knowledge.editor.popout_title` already reads "Toggle full-screen
           view" in all 24 locales (it labels the desktop pop-out button,
           which is hidden on mobile), and the sheets' Close does the rest.
           Built after the editor exists, and idempotently — the editor view
           is not re-rendered, but syncKnowledgeBar can run again on resize. */
        function wireFullScreenEditor() {
            if (!mq.matches) return;
            var content = document.querySelector('.editor-content');
            if (!content || content.dataset.kbFs) return;
            content.dataset.kbFs = '1';

            var label = tr('knowledge.editor.popout_title', 'Full screen');
            var closeLabel = tr('knowledge.modal.close', tr('common.close', 'Close'));

            var openBtn = document.createElement('button');
            openBtn.type = 'button';
            openBtn.className = 'kb-fs-open';
            openBtn.textContent = '⤢  ' + label;

            /* The bar shows the ARTICLE's title rather than repeating the
               button's label — once you are in full screen, "Toggle
               full-screen view" tells you nothing you don't know, whereas
               what you are editing is genuinely useful. Read live from the
               Title field, so it is right for a new article too, and it
               needs no string of its own. */
            var barTitle = document.createElement('span');
            barTitle.className = 'kb-fs-title';
            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'ms-close';
            closeBtn.textContent = closeLabel;
            var bar = document.createElement('div');
            bar.className = 'kb-fs-bar';
            bar.appendChild(barTitle);
            bar.appendChild(closeBtn);

            content.insertBefore(bar, content.firstChild);
            content.insertBefore(openBtn, bar);

            function setFull(on) {
                if (on) {
                    var titleField = document.getElementById('articleTitle');
                    var t = titleField && titleField.value.trim();
                    barTitle.textContent = t || label;
                }
                document.body.classList.toggle('kb-editor-full', on);
                openBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
                /* TinyMCE lays the iframe out to a pixel height it worked out
                   when the container was small. Nudge it after the class flip
                   so it re-measures against the new box — without this the
                   editor is full-screen but the typing area is still 200px. */
                var ed = window.tinymce && window.tinymce.get('articleBody');
                if (ed) { setTimeout(function () { try { ed.execCommand('mceAutoResize'); } catch (e) {} window.dispatchEvent(new Event('resize')); }, 30); }
            }
            openBtn.addEventListener('click', function () {
                setFull(true);
                history.pushState({ kbFull: true }, '');
            });
            closeBtn.addEventListener('click', function () {
                if (history.state && history.state.kbFull) history.back();
                else setFull(false);
            });
            window.addEventListener('popstate', function () { setFull(false); });
        }

        // ---- mirror the current view onto <body> for CSS ----
        function readView() {
            var d = document.getElementById('articleDetailView');
            var e = document.getElementById('articleEditorView');
            if (e && e.style.display !== 'none' && e.style.display !== '') return 'editor';
            if (d && d.style.display !== 'none' && d.style.display !== '') return 'detail';
            return 'list';
        }
        // Named setKbView, not setView: the inbox branch has a top-level
        // setView-alike (`setPane`) and a plain `setView` here would read as
        // the calendar module's view toggle.
        function setKbView(v) { document.body.setAttribute('data-kb-view', v); }
        setKbView(readView());

        if (typeof window.showView === 'function') {
            var _showView = window.showView;
            window.showView = function (view) {
                var r = _showView.apply(this, arguments);
                setKbView(view || readView());
                stripEditorPopout();
                wireFullScreenEditor();
                // Leaving the editor must not strand the page in the overlay.
                if (view !== 'editor') document.body.classList.remove('kb-editor-full');
                return r;
            };
        }

        /* ⚠️ The #762 trap, second sighting. `applyEditorPopoutFromPref()`
           reads localStorage on every edit and re-applies `.editor-popout`,
           which gives the form a FIXED 340px property panel — the whole screen
           at 360px, leaving the editor itself nothing. Neutralise at the
           source (the CSS in 17d is only the backstop), and leave the stored
           preference alone so the desktop behaviour is unchanged. */
        function stripEditorPopout() {
            if (mq.matches) container.classList.remove('editor-popout');
        }
        ['applyEditorPopoutFromPref', 'toggleEditorPopout'].forEach(function (fn) {
            if (typeof window[fn] !== 'function') return;
            var _orig = window[fn];
            window[fn] = function () {
                var r = _orig.apply(this, arguments);
                stripEditorPopout();
                return r;
            };
        });
        stripEditorPopout();

        function syncKnowledgeBar() {
            var on = mq.matches;
            bar.style.display = on ? 'flex' : 'none';
            var vb = document.querySelector('.mobile-views-btn');
            if (vb) vb.style.display = on ? '' : 'none';
            syncBackLabel();
            if (on) { sidebarIntoPlace(); wireMetaToggle(); wireFullScreenEditor(); }
            else {
                // Leaving mobile: neither the meta block nor the full-screen
                // editor may be left in a mobile-only state on a desktop page.
                var head = document.querySelector('.article-content-header');
                if (head) head.classList.remove('kb-meta-open');
                document.body.classList.remove('kb-editor-full');
                document.body.classList.remove('mobile-views-open');
                hideSheet();
                sidebarBackToPage();
                document.body.removeAttribute('data-kb-view');
            }
        }
        syncKnowledgeBar();
        if (mq.addEventListener) { mq.addEventListener('change', syncKnowledgeBar); }
        else if (mq.addListener) { mq.addListener(syncKnowledgeBar); }
    }

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

    // The other way a ticket opens: by id. The ?ticket_id= deep link (Users ->
    // a user's ticket, notifications, Calendar's "Open in inbox") and the
    // linked-ticket pills all call loadTicketById, which renders via
    // displayEmail and never touches selectEmail - so on a phone the ticket
    // loaded behind the list and you were left looking at the inbox. Same
    // treatment as selectEmail: show the reading pane, then the refinements.
    if (typeof window.loadTicketById === 'function') {
        var _loadTicketById = window.loadTicketById;
        window.loadTicketById = function () {
            if (mq.matches && currentPane() !== 'reading') pushPane('reading');
            var r = _loadTicketById.apply(this, arguments);
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

    // The views hamburger (top-right -> right drawer) and the company switcher
    // move are part of the shared shell now (#937) — see the top of the file.

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
                btn.innerHTML = '<span class="action-btn-icon">' + def.icon + '</span><span class="msb-label">' + def.label + '</span>';
                btn.addEventListener('click', function () { def.sheet.style.display = 'flex'; });
                toolbar.appendChild(btn);
            }
        });
        syncLinksCount();
    }

    // "Links (2)" on the Links button. The word only shows in the "…" panel
    // (the bar is icon-only), but the count is set either way. Equipment, CMDB,
    // domain and task pills arrive by their own fetches AFTER the strip is drawn,
    // and unlinking redraws a group in place, so a one-off count at relocate time
    // would be wrong - the observer recounts whenever the strip's pills change.
    // Every pill in the strip, early or late, is an `a.pm-ticket-badge`.
    var linksDef = SECTIONS[0];
    function syncLinksCount() {
        var btn = document.querySelector('.mobile-sheet-btn-links .msb-label');
        if (!btn) return;
        var n = linksDef.body.querySelectorAll('.links-strip .pm-ticket-badge').length;
        btn.textContent = n ? linksDef.label + ' (' + n + ')' : linksDef.label;
    }
    new MutationObserver(syncLinksCount).observe(linksDef.body, { childList: true, subtree: true });

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
            // Same three-way split the inbox uses: the endpoint says which case
            // an unresolved author is, so a workflow-written entry reads as
            // "System" rather than "Unknown" (GH #120).
            var who = (e.analyst_name
                || (typeof t === 'function'
                    ? t(e.author_kind === 'system' ? 'tickets.note_author.system'
                                                   : 'tickets.note_author.former')
                    : '')).trim();

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

/* ====================================================================
   TASKS — which board column am I on (#1205)

   A SEPARATE top-level IIFE, deliberately. The block above returns
   early on any page without an .email-list-container — it is the
   tickets inbox wiring — so anything appended inside it never runs
   anywhere else. The first version of this was written in there and
   simply did nothing: the dots were absent rather than broken, which
   is the quiet kind of failure. Its own IIFE, its own mq.
   ==================================================================== */
(function () {
    var mq = window.matchMedia('(max-width: 768px)');
    var board = document.getElementById('boardView');
    if (!board) return;                       // not the Tasks board page

    var dots = document.createElement('div');
    dots.className = 'tsk-board-dots';
    // ⚠️ Created hidden inline. mobile.css is @media-only, so it CANNOT
    // supply a desktop default of display:none — the element would show
    // on a wide screen. sync() below is what turns it on.
    dots.style.display = 'none';
    board.parentNode.insertBefore(dots, board.nextSibling);

    function columns() {
        return board.querySelectorAll('.board-column');
    }

    function build() {
        var n = columns().length;
        if (dots.childElementCount !== n) {
            dots.innerHTML = '';
            for (var i = 0; i < n; i++) {
                var d = document.createElement('span');
                d.className = 'tsk-board-dot';
                dots.appendChild(d);
            }
        }
        // One column is not a carousel — nothing to say, so say nothing.
        dots.style.display = (mq.matches && n > 1) ? 'flex' : 'none';
        mark();
    }

    function mark() {
        var cols = columns();
        if (!cols.length) return;
        // Which column is nearest the left edge of the scroller. Derived
        // from scrollLeft rather than a stored index, so it stays right
        // whether the board was swiped, scrolled or jumped to.
        var step = board.scrollWidth / cols.length;
        var at   = Math.round(board.scrollLeft / step);
        if (at < 0) at = 0;
        if (at > cols.length - 1) at = cols.length - 1;
        for (var i = 0; i < dots.children.length; i++) {
            dots.children[i].classList.toggle('is-current', i === at);
        }
    }

    var tick = null;
    board.addEventListener('scroll', function () {
        if (tick) return;
        tick = requestAnimationFrame(function () { tick = null; mark(); });
    }, { passive: true });

    // Wrap the global tasks.js already exposes. Guarded: if the board page
    // ever stops defining it, the dots simply never rebuild rather than
    // the whole mobile bundle throwing on load.
    if (typeof window.renderBoard === 'function') {
        var realRenderBoard = window.renderBoard;
        window.renderBoard = function () {
            var r = realRenderBoard.apply(this, arguments);
            build();
            return r;
        };
    }

    function sync() { build(); }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ====================================================================
   FORMS — give a card feed its column labels back (#1289)

   ITS OWN top-level IIFE, for the reason the block above documents.

   Every card feed in this rollout so far has had to drop its column
   labels, because the only pure-CSS way to put one back is
   `td::before { content: "Submissions" }` — a hardcoded English string
   in a product that ships in 24 languages. So the rule became "reading
   order carries the meaning instead", which works when the values are
   self-describing (a name, a date, a status pill) and fails completely
   when they are not.

   The forms list is the case where it fails: two of its eight columns
   are BARE COUNTS, and "New Starter Request / v1 / Active / 7 / 10"
   tells you nothing about what 7 and 10 are.

   ⭐ But the labels are already on the page, already translated, in the
   `<thead>` the feed hides. So copy them onto the cells as a data
   attribute and let CSS print them with `attr()`. Zero invented
   strings, zero new locale keys, and it works for any table in any
   module — the same trick as harvesting `common.back` from a locale
   that already had the word, one level up.

   Desktop is untouched: the attribute is inert without the @media rule
   that prints it, and `thead` is only hidden below 768px.
   ==================================================================== */
(function () {
    var mq = window.matchMedia('(max-width: 768px)');

    /* Which columns get a label, per table. Deliberately a LIST rather
       than "all of them": a card whose every line is prefixed reads like
       a spreadsheet, and most of these columns are self-describing. Only
       the ones that are meaningless bare want one.

       Indexes are into the header row, zero-based:
         3 = the field count, 4 = the submission count. */
    var FEEDS = [
        { table: '#formsTable', columns: [3, 4] },

        /* ---- Tickets subsections (LAYER 38, #17xx) ----
           Two feeds, and in both cases the columns that cannot speak for
           themselves are the NAMES and the bare words.

           Triage: sender is an email and announces itself; subject is the
           headline. Domain ("acme.co.uk"), mailbox (a NAME sitting right
           under a person's name) and received (a bare date) do not. */
        { table: 'body[data-mobile-page="tickets-triage"] table.triage',
          columns: [1, 3, 4] },          /* domain, mailbox, received */

        /* Activity: from is an email, subject is the headline, and the
           datetime leads the card so it reads as the timestamp it is.
           Mailbox is the second name on the card; action ("Ignored",
           "Created") and reason are bare words that could be anything. */
        { table: 'body[data-mobile-page="tickets-activity"] .activity-main table',
          columns: [1, 4, 5] },          /* mailbox, action, reason */
        /* ---- Contracts (LAYER 28c, #1362) ----
           Six card feeds, and the list below is the whole argument for §21
           existing. Under §11 alone a seven-column contracts list was a
           marginal call and an EIGHT-column RFP list was not a feed at all;
           the only objection in both cases was that some columns cannot
           speak for themselves. These are those columns and no others.

           ⚠️ Two names in a row is the commonest case here, and it is not
           obvious from a column count. The contracts list puts Supplier
           beside Owner — a company and a person, both rendered as plain
           text — and the suppliers list puts Legal name beside Trading
           name, which are two names for the SAME organisation. Unlabelled,
           the second of each pair is unreadable. */
        { table: 'body[data-mobile-page="contracts-list"] .section-card table',
          columns: [2, 3, 4] },          /* supplier, owner, end date */
        { table: 'body[data-mobile-page="contracts-contacts"] .section-card table',
          columns: [4] },                /* supplier — an email and a mobile
                                            announce themselves by shape; a
                                            company name after a person's
                                            name does not */
        { table: 'body[data-mobile-page="contracts-suppliers"] .section-card table',
          columns: [1, 2, 4] },          /* trading name, type, city */
        { table: 'body[data-mobile-page="rfp-list"] .section-card table',
          columns: [2, 3, 4, 5, 6] },    /* three bare counts (docs, reqs,
                                            suppliers) and TWO bare dates —
                                            created and updated are
                                            indistinguishable side by side */
        { table: 'body[data-mobile-page="rfp-documents"] .page-wrap table',
          columns: [1, 4, 5] },          /* department, requirement count,
                                            uploaded. NOT size: "1.2 MB"
                                            says what it is */
        { table: 'body[data-mobile-page="rfp-extracted"] .page-wrap table',
          columns: [3] },                /* confidence. A bare "87%" is
                                            §21's own example of a figure
                                            that means nothing alone, while
                                            Department and Type read as the
                                            tags they are */

        /* ---- LMS (LAYER 29b, #1392; progress added #1401) ----
           FOUR feeds on one console, told apart by the tbody ids the page
           already gives them. Progress was a scroller until Ed asked for no
           sideways scrolling — see the note in mobile.css, and note it is by
           some way the hungriest for labels here: seven columns, of which two
           are names side by side and two are dates side by side. */
        { table: 'body[data-mobile-module="lms"] .lms-table:has(#coursesBody)',
          columns: [2] },                /* uploaded. A bare date. The version
                                            column beside it is a badge
                                            reading "Authored" or "SCORM 2004",
                                            which says what it is */
        { table: 'body[data-mobile-module="lms"] .lms-table:has(#groupsBody)',
          columns: [2] },                /* member count — a bare number */
        { table: 'body[data-mobile-module="lms"] .lms-table:has(#assignmentsBody)',
          columns: [1, 2, 3] },          /* group, deadline, assigned by.
                                            Course and Group are TWO NAMES
                                            side by side and "assigned by" a
                                            third — the contracts round's
                                            finding that a pair of names is
                                            unreadable unlabelled, whatever
                                            the column count says */
        { table: 'body[data-mobile-module="lms"] .lms-table:has(#progressBody)',
          columns: [1, 2, 4, 5, 6] }     /* course, group, score, deadline,
                                            last access. The analyst is the
                                            card's heading and the status is a
                                            pill, so those two speak for
                                            themselves; the other five are two
                                            names, a bare number and two dates
                                            — nothing a reader can place once
                                            the header row is gone */,
        /* ---- PROCESS MAPPER settings (LAYER 30e, #1415) ----
           Shape · Name · Colour · Order · Active · Actions. The swatch and
           the name speak for themselves; a bare `10` and a bare `Yes` do
           not, and the colour is a hex code that could be anything. */
        { table: 'body[data-mobile-module="process-mapper"] table:has(#pmsRows)',
          columns: [2, 3, 4] },

        /* ---- WORKFLOW (LAYER 32e / 32f, #1469) ----
           The list: Name · Trigger · Actions · Last run · Status · actions.
           Only two need a label. The Actions cell renders "3 runs", which
           says what it is, and Status is a pill — labelling either would be
           the spreadsheet effect §21 warns about, where every line is
           prefixed and the card stops reading like a card. */
        { table: 'body[data-mobile-page="wf-list"] table',
          columns: [1, 3] },             /* trigger — a bare `ticket.created`
                                            in a code font — and last run,
                                            which is a bare date AND is empty
                                            on a workflow that has never run,
                                            so the harvester's blank-cell
                                            guard is doing real work here */

        /* The execution log: Status · Workflow · Trigger · When · Took ·
           Detail. Three labels: the trigger slug again, a bare date, and a
           bare duration sitting directly beside that date — the pair §21
           exists for, since neither says which of the two it is. */
        { table: 'body[data-mobile-page="wf-executions"] table',
          columns: [2, 3, 4] },

        /* ---- SYSTEM: webhook deliveries (LAYER 33b, #1471) ----
           Nine columns, and SIX of them need a label — which is a lot, and
           is right: When, Format, URL, Attempts, Last code and Next retry
           are every one of them a bare value (a date, a one-word preset, a
           bare hostname, "1/5", "200", another date). Only three speak for
           themselves — the workflow name is the card's heading, the status
           is a pill and the actions are buttons.

           ⚠️ `table.wh` and not `table`, because the same page carries two
           `table.mini` summaries which stay tables: three columns of
           figures you genuinely do read down, and they already fit. */
        { table: 'body[data-mobile-page="webhooks"] table.wh',
          columns: [0, 2, 3, 5, 6, 7] },

        /* ---- REPORTING: system logs (LAYER 35e, #1482) ----
           🔴 THE FIRST TWO FEEDS WHOSE TABLE DOES NOT EXIST AT LOAD. Both
           log tabs render their whole `<table>` into `#logsTableContainer`
           from a fetch, so `querySelector` at load finds nothing — see the
           `watch` note where the observer is set up. Same for the Intune
           drill-down below.

           ⚠️ And they are two DIFFERENT tables sharing one class in one
           container, told apart by the `data-log-type` their own renderers
           now stamp: a selector had no other way to know which was on
           screen, and they do not want the same columns labelled.

           Login attempts — Date/time · Username · Status · IP · User agent.
           The username is the card's heading and the status is a pill, so
           the other three take labels: two of them are a bare timestamp and
           a bare dotted-quad sitting one above the other, and the third is
           a user agent, which without its heading is just a long string. */
        { table: 'body[data-mobile-page="rep-logs"] .logs-table[data-log-type="login"]',
          columns: [0, 3, 4],
          watch: '#logsTableContainer' },

        /* Email import — Date/time · From · Subject · Type · Attachments.
           Only two. The subject leads, the type is a pill, and From renders
           a name above an address, which says what it is. A bare date does
           not, and neither does a bare "None" under it — which is the
           attachments cell when there were none, and §21's own case. */
        { table: 'body[data-mobile-page="rep-logs"] .logs-table[data-log-type="email"]',
          columns: [0, 4],
          watch: '#logsTableContainer' },

        /* ---- REPORTING: the Intune drill-down (LAYER 35j, #1482) ----
           Device · User · OS · Compliance · Encrypted · Last sync. The
           device name is the heading and compliance is a pill; the other
           four are a bare person's name, a bare version string, a bare
           "Yes"/"No" and a bare date. Encrypted is the clearest example in
           the rollout of a cell that is meaningless without its heading —
           "Yes" on its own line answers a question the card never asked. */
        { table: 'body[data-mobile-page="rep-intune"] .drill-body table',
          columns: [1, 2, 4, 5],
          watch: '#drillBody' },

        /* ---- SYSTEM WIKI (LAYER 36, #1487) ----
           ⭐ NO `watch` ON ANY OF THESE THREE, and the difference from the
           Reporting entries above is worth being precise about, because
           getting it wrong is silent either way. Reporting's renderers
           replace their CONTAINER's innerHTML, so the `<table>` itself does
           not exist at load and the observer has to be given a box that
           does. The wiki's three tables are STATIC markup — `<table
           class="file-table">` with a `<tbody id="fileTableBody">` inside —
           and only the tbody's contents are replaced. The default (observe
           the table, subtree) therefore already sees every re-render.

           🔴 The first draft added `watch: '#tablesBody'` anyway. That id
           does not exist — it is `#tableBody`, singular — and a `watch`
           that resolves to null drops the feed from `watched` entirely, so
           the labels would have been applied once at load, against the
           loading row, and never again. A whole entry with precisely zero
           visible effect, which is LAYER 33b's `#deliveries` mistake for
           the second time. **Read the markup for the selector.**

           The file list — File · Type · Lines · Functions · Description.
           The file name is the card's heading, the type is a badge and the
           description is a whole sentence that does not need telling what
           it is. The two in the middle are bare numbers sitting next to
           each other, which is §21's own case: `1,284` and `17` say
           nothing about which is lines and which is functions. */
        { table: 'body[data-mobile-page="wiki-browse"] .file-table',
          columns: [2, 3] },

        /* Database tables — Name · Files · Total refs · SELECT · INSERT ·
           UPDATE · DELETE · JOIN. **Seven of eight**, which is the most
           this list has ever needed and is right: every column but the
           table's own name is a bare count, and `tickets / 96 / 412 / 268 /
           21 / 94 / 6 / 23` is unreadable without them. The colour on each
           operation badge distinguishes them for anyone who can see it;
           the label is what makes that true for everyone else. */
        { table: 'body[data-mobile-page="wiki-tables"] .tables-table',
          columns: [1, 2, 3, 4, 5, 6, 7] },

        /* Scan history — Date · Status · Duration · Files · Functions ·
           Classes · Scanned by. Four labels: the date leads, the status is
           a badge and the person's name reads as one, but a duration and
           three counts in a row are four bare figures.
           ⚠️ This table arrives with `thead { display: none }` ALREADY
           applied, from LAYER 15b's Assets card feed — `.history-table` is
           unscoped there (§15). The heading text is still in the DOM, which
           is all the harvester needs, and is exactly why hiding a head is
           not the same as removing it. */
        { table: 'body[data-mobile-page="wiki-scan"] .history-table',
          columns: [2, 3, 4, 5] },

        /* ---- Domains (LAYER 41) ----
           Register: the name, status and grade speak for themselves; the
           expiry countdown, renewal mode, registrar, protection, certificate
           and owner do not. The table is in the markup and only its tbody is
           replaced, so watching the table is enough. */
        { table: 'body[data-mobile-page="domains-register"] #domTable',
          columns: [3, 4, 5, 7, 8, 9] },
        /* Accounts: everything after the account's own name - a reference, a
           person, a bare domain count and a date mean nothing alone. */
        { table: 'body[data-mobile-page="domains-accounts"] .dom-table',
          columns: [1, 2, 3, 4, 5, 6] },

        /* ---- Assets > Users (LAYER 43c) ----
           A person's equipment: serial, asset tag and the date it was
           issued are three bare values side by side - two codes that look
           alike and a date that could mean anything. The table is rebuilt
           with the whole detail pane, so it does not exist at load: watch
           the pane. */
        { table: 'body[data-mobile-page="assets-users"] .au-table',
          columns: [3, 4, 5], watch: '#auDetail' },

        /* ---- LMS -> Tests (LAYER 29t) ----
           All three tables are built whole by lms-tests.js into a container,
           so each watches its container (the Reporting rule above).
           Tests: two bare counts - questions and candidates. */
        { table: 'body[data-mobile-page="lms-tests"] #testsList .lms-table',
          columns: [2, 3], watch: '#testsList' },
        /* Candidates, all tests: the test name (a second name under a
           person's), a bare score and two bare dates - sent and finished,
           which are indistinguishable unlabelled. Status is a pill. */
        { table: 'body[data-mobile-page="lms-tests"] #candList .lms-table',
          columns: [1, 3, 4, 5], watch: '#candList' },
        /* The same table in the builder, one test so no Test column. */
        { table: 'body[data-mobile-page="lms-tests-edit"] #sittings .lms-table',
          columns: [2, 3, 4], watch: '#sittings' }
    ];

    function labelCardFeed(table, columns) {
        var head = table.tHead;
        if (!head || !head.rows.length) return;
        var headCells = head.rows[0].cells;
        var body = table.tBodies[0];
        if (!body) return;

        for (var r = 0; r < body.rows.length; r++) {
            var row = body.rows[r];
            for (var c = 0; c < columns.length; c++) {
                var i = columns[c];
                var cell = row.cells[i];
                if (!cell || !headCells[i]) continue;
                // ⚠️ The empty / loading / error row is a single
                // `<td colspan="8">`, so row.cells[3] on it is undefined
                // — but a two-cell variant would put the label on the
                // wrong thing. Skip any row that is not the full width.
                if (row.cells.length !== headCells.length) continue;

                // 🔴 An EMPTY cell must not be labelled. A progress row for
                // someone who has not started has no score, no deadline and
                // no last access, and a label on nothing renders as the word
                // "Score" followed by silence — a heading for a fact that is
                // not there. Every feed before this one happened to have no
                // empty labelled columns, which is why it never showed.
                if (!(cell.textContent || '').trim()) {
                    cell.removeAttribute('data-mobile-label');
                    continue;
                }

                // The header carries a sort-arrow span; take the text
                // nodes only so the arrow glyphs do not come with it.
                var text = '';
                var kids = headCells[i].childNodes;
                for (var k = 0; k < kids.length; k++) {
                    if (kids[k].nodeType === 3) text += kids[k].nodeValue;
                }
                text = text.replace(/\s+/g, ' ').trim();
                if (!text) continue;
                cell.setAttribute('data-mobile-label', text);
            }
        }
    }

    function apply() {
        if (!mq.matches) return;
        for (var i = 0; i < FEEDS.length; i++) {
            var t = document.querySelector(FEEDS[i].table);
            if (t) labelCardFeed(t, FEEDS[i].columns);
        }
    }

    // The list is re-rendered by the page's own renderForms() on load,
    // on search and on every sort, and it replaces the tbody's innerHTML
    // — so a one-shot pass at load would be undone by the first fetch
    // that resolves after it. Observing the table is cheaper and safer
    // than wrapping four call sites, and it cannot get out of step with
    // a renderer this file does not own.
    // 🔴 Observe the STABLE ANCESTOR, not the table, wherever the page names
    // one. Every feed up to LAYER 33 shipped its `<table>` in the markup and
    // filled the tbody from a fetch, so watching the table itself was both
    // correct and the tightest scope available. Reporting breaks that: both
    // log tabs and the Intune drill-down replace their container's entire
    // innerHTML, table and all, so at load `querySelector(FEEDS[i].table)`
    // finds nothing, `watched` comes out empty and this whole IIFE returns
    // before it has done anything — a feed that renders correctly forever
    // and never once gets a label, with nothing anywhere to say why.
    // `watch` names the box that IS there at load; without one the behaviour
    // is exactly as before.
    var watched = [];
    for (var i = 0; i < FEEDS.length; i++) {
        var el = FEEDS[i].watch ? document.querySelector(FEEDS[i].watch)
                                : document.querySelector(FEEDS[i].table);
        if (el && watched.indexOf(el) === -1) watched.push(el);
    }
    if (!watched.length) return;              // not a page with a labelled feed

    if (window.MutationObserver) {
        var obs = new MutationObserver(function () { apply(); });
        for (var j = 0; j < watched.length; j++) {
            obs.observe(watched[j], { childList: true, subtree: true });
        }
    }

    apply();
    if (mq.addEventListener) { mq.addEventListener('change', apply); }
    else if (mq.addListener) { mq.addListener(apply); }
})();

/* ====================================================================
   FORMS EDITOR — the four inspection tools join Save and Cancel in one
   bottom action bar, with a "…" overflow  (#1290, Ed's request)

   ITS OWN top-level IIFE, for the reason the blocks above document.

   On desktop the editor has two groups of controls: AI Assist / Versions
   / Save as new version / Properties in the top toolbar, and Cancel /
   Save in a sticky footer. That is a sound desktop split — inspection at
   the top, completion at the bottom. On a phone LAYER 27g stacked the
   top four into four full-width rows, which spent ~150px before a single
   form field. Ed asked for the tickets treatment instead: one row of
   icons at the bottom, and a "…" for whatever will not fit.

   What this does, and only when `mq.matches`:
     1. wraps each button's bare label text in a `.fm-label` span, so CSS
        can hide it on the bar and show it again in the overflow panel;
     2. moves the four toolbar buttons into the footer, before Save;
     3. measures, and pushes whatever does not fit into a
        `.mobile-more-panel` behind a "…" — LAYER 5's own class;
     4. puts every one of them back where it came from if the viewport
        goes wide again.

   Desktop is untouched: nothing runs, and step 4 makes a mid-session
   resize back to desktop exact rather than approximately right.
   ==================================================================== */
(function () {
    var mq = window.matchMedia('(max-width: 768px)');

    var footer = document.querySelector('.forms-edit-page .editor-footer');
    var toolbarActions = document.querySelector('.forms-edit-page .editor-toolbar-actions');
    if (!footer || !toolbarActions) return;          // not the form builder

    var saveBtn = footer.querySelector('.save-btn');

    // The four, in the order Ed asked for them. Each is looked up rather
    // than taken as "every child", because the toolbar also holds the
    // versions dropdown, which travels with its wrapper and must not be
    // treated as a button in its own right.
    var MOVERS = ['.btn-ai-assist', '#versionsWrap', '#newVersionBtn', '#propertiesBtn'];

    var moved = [];          // { el, parent, next } so the move is reversible

    // ---- 1. the label ---------------------------------------------------
    // ⚠️ These buttons carry their label as a BARE TEXT NODE beside an
    // <svg>, so `span:not(.action-btn-icon)` — LAYER 5's way of hiding a
    // label — has nothing to match. Wrap it once, idempotently.
    function wrapLabel(btn) {
        if (!btn || btn.querySelector('.fm-label')) return;
        var kids = Array.prototype.slice.call(btn.childNodes);
        var span = null;
        kids.forEach(function (n) {
            if (n.nodeType !== 3 || !n.nodeValue.trim()) return;
            if (!span) {
                span = document.createElement('span');
                span.className = 'fm-label';
                btn.insertBefore(span, n);
            }
            span.appendChild(n);          // moves the text node into the span
        });
        // Keep the accessible name intact: the label is now hidden by CSS on
        // the bar, so a button with no title would be an unlabelled icon.
        if (span && !btn.getAttribute('aria-label')) {
            btn.setAttribute('aria-label', span.textContent.trim());
        }
    }

    // ---- 3. the overflow -------------------------------------------------
    function clearOverflow() {
        var panel = footer.querySelector('.mobile-more-panel');
        var btn = footer.querySelector('.mobile-more-btn');
        if (panel) {
            // Put its contents back on the bar before removing it, or they
            // would be destroyed along with it.
            while (panel.firstChild) footer.insertBefore(panel.firstChild, panel);
            panel.parentNode.removeChild(panel);
        }
        if (btn) btn.parentNode.removeChild(btn);
    }

    function buildOverflow() {
        clearOverflow();

        // Only VISIBLE controls count. Versions is hidden for a brand-new
        // form and Save as new version for a frozen snapshot, and a hidden
        // button must not push a visible one into the overflow.
        var items = Array.prototype.filter.call(footer.children, function (el) {
            return el.offsetParent !== null && !el.classList.contains('mobile-more-panel');
        });
        if (items.length < 2) return;

        // Measure rather than assume a count: the bar's capacity depends on
        // the screen, and how many controls are showing depends on the form.
        var style = window.getComputedStyle(footer);
        var avail = footer.clientWidth
            - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        var gap = parseFloat(style.columnGap || style.gap) || 6;

        var used = 0, overflow = [];
        for (var i = 0; i < items.length; i++) {
            var w = items[i].getBoundingClientRect().width;
            var next = used + (used ? gap : 0) + w;
            // Reserve room for the "…" itself from the moment one is needed.
            var budget = (i === items.length - 1 && !overflow.length) ? avail : avail - (46 + gap);
            if (next > budget && items[i] !== saveBtn) {
                overflow.push(items[i]);
            } else {
                used = next;
            }
        }
        if (!overflow.length) return;      // everything fits — no "…" at all

        var panel = document.createElement('div');
        panel.className = 'mobile-more-panel';
        // ⚠️ Inline `display:none`. mobile.css is @media-only, so it cannot
        // give an injected node a desktop default — the documented trap.
        panel.style.display = 'none';

        var moreBtn = document.createElement('button');
        moreBtn.type = 'button';
        moreBtn.className = 'btn btn-secondary mobile-more-btn';
        moreBtn.innerHTML = '<span class="action-btn-icon">⋯</span>';
        // English, deliberately: LAYER 5's tickets bar labels its own "…" the
        // same way, there is no translated "More" anywhere in lang/ to
        // harvest, and inventing a key here would put ONE English string in
        // 23 locale files. Matching the existing button keeps it to one thing
        // to fix if a key is ever added. It is an aria-label, never rendered.
        moreBtn.setAttribute('aria-label', 'More actions');
        moreBtn.title = 'More actions';
        moreBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.style.display = (panel.style.display === 'none') ? 'flex' : 'none';
        });

        overflow.forEach(function (el) {
            el.addEventListener('click', function () { panel.style.display = 'none'; });
            panel.appendChild(el);
        });

        // Before Save, so Save stays the rightmost thing on the bar.
        footer.insertBefore(moreBtn, saveBtn || null);
        footer.appendChild(panel);
    }

    // ⚠️ LAYER 5's tap-outside-to-close handler lives in the main IIFE,
    // which returns early on any page without an .email-list-container. It
    // never runs here, so this block needs its own — the failure would have
    // been silent, the panel opening happily and never closing.
    document.addEventListener('click', function (e) {
        if (!mq.matches || !e.target.closest) return;
        var panel = footer.querySelector('.mobile-more-panel');
        if (!panel || panel.style.display === 'none') return;
        if (e.target.closest('.mobile-more-panel') || e.target.closest('.mobile-more-btn')) return;
        panel.style.display = 'none';
    });

    // ---- 2 & 4. move in, and put back ------------------------------------
    // ⚠️ Cancel is the ONE control here with no <svg> — it is a plain
    // `<button>Cancel</button>` — so hiding its label leaves an empty grey
    // rectangle on the bar. It looked like a broken button, because that is
    // exactly what it was. Anything without an icon gets one.
    // 🔑 The general rule: **before hiding a label, check every button
    // actually has something left to show.**
    var GLYPHS = { cancelEdit: '✕' };          // ✕
    function ensureIcon(btn) {
        if (!btn || btn.querySelector('svg') || btn.querySelector('.fm-glyph')) return;
        var on = btn.getAttribute('onclick') || '';
        var glyph = null;
        Object.keys(GLYPHS).forEach(function (fn) { if (on.indexOf(fn) === 0) glyph = GLYPHS[fn]; });
        if (!glyph) return;
        var s = document.createElement('span');
        s.className = 'fm-glyph';
        s.textContent = glyph;
        btn.insertBefore(s, btn.firstChild);
    }

    function moveIn() {
        // The first original child is Cancel; everything moved in goes BEFORE
        // it, in the order MOVERS lists, so the bar reads left to right the
        // way Ed asked for it. Inserting each at `firstChild` instead — which
        // is what the first version did — silently REVERSES the list.
        var anchor = footer.firstElementChild;
        MOVERS.forEach(function (sel) {
            var el = document.querySelector('.forms-edit-page ' + sel);
            if (!el || el.parentNode === footer) return;
            moved.push({ el: el, parent: el.parentNode, next: el.nextSibling });
            wrapLabel(el.classList.contains('versions-wrap') ? el.querySelector('.btn') : el);
            footer.insertBefore(el, anchor);
        });
        // Cancel and Save carry labels too, and Save's accent is what
        // identifies it once the bar is icons.
        Array.prototype.forEach.call(footer.querySelectorAll('.btn'), function (b) {
            wrapLabel(b);
            ensureIcon(b);
        });
    }

    function moveOut() {
        clearOverflow();
        moved.forEach(function (m) {
            if (m.next && m.next.parentNode === m.parent) m.parent.insertBefore(m.el, m.next);
            else m.parent.appendChild(m.el);
        });
        moved = [];
        // The .fm-label spans are left in place deliberately: they are inert
        // (the hiding rule is inside the @media block) and removing them
        // would mean unwrapping text nodes the page's own renderer may since
        // have replaced.
    }

    function sync() {
        if (mq.matches) {
            if (!moved.length) moveIn();
            buildOverflow();
        } else if (moved.length) {
            moveOut();
        }
    }

    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }

    // The Versions and Save-as-new-version buttons are shown/hidden by the
    // page's own JS once the form has loaded, which changes how many controls
    // the bar is carrying. Re-measure when that happens rather than guessing
    // at load time, when both are still hidden.
    if (window.MutationObserver) {
        var reflow = null;
        new MutationObserver(function () {
            if (!mq.matches || reflow) return;
            reflow = setTimeout(function () { reflow = null; buildOverflow(); }, 60);
        }).observe(footer, { attributes: true, attributeFilter: ['style'], subtree: true });
    }
})();

/* ====================================================================
   CONTRACTS — name the icon-only actions in the bottom bar  (#1369)

   ITS OWN top-level IIFE, for the reason the blocks above document.

   LAYER 28j turns a contract's five actions into an icon bar pinned to
   the bottom of the screen and hides their text labels. `display: none`
   takes an element out of the ACCESSIBILITY TREE as well as off the
   screen, so at that point the buttons have no accessible name at all —
   five unlabelled controls to a screen reader.

   A `title` restores it, and this is where it belongs rather than in
   view.php's markup. It was in the markup first, and that put a hover
   tooltip on five desktop buttons that never had one — a small thing,
   but the rollout's one hard rule is that mobile work changes NOTHING on
   a desktop, and "small" is how a rule stops being a rule. Ed's point,
   and it is a good one: anything that leaks doubles the surface he has
   to re-check.

   ⭐ The label is harvested from the button's own `.cv-act-label`, so
   there are no invented strings and it is correct in all 24 locales —
   the same trick LAYER 27c uses to give a card feed its column headings
   back from the table's own <thead>.

   The card is rendered by view.php's own renderContract() after a fetch,
   and re-rendered whenever the contract is reloaded, so this observes the
   container rather than wrapping a function it does not own — the reason
   27c gives for preferring a MutationObserver to four wraps.
   ==================================================================== */
(function () {
    var host = document.getElementById('contractCard');
    if (!host) return;                       // not the contract detail page

    var mq = window.matchMedia('(max-width: 768px)');

    function apply() {
        if (!mq.matches) return;             // desktop: never write the attribute
        var btns = host.querySelectorAll('.contract-card-header .actions .btn');
        for (var i = 0; i < btns.length; i++) {
            if (btns[i].getAttribute('title')) continue;
            var label = btns[i].querySelector('.cv-act-label');
            var text  = label ? (label.textContent || '').replace(/\s+/g, ' ').trim() : '';
            if (text) btns[i].setAttribute('title', text);
        }
    }

    /* ⚠️ And take it away again when the viewport leaves mobile, so a
       desktop browser dragged wide is left exactly as it would have been
       had it never been narrow. The Calendar round set the precedent for
       restoring rather than one-way conversion (LAYER 16). It also means
       the desktop control can assert `[title]` finds nothing here, which
       is a stronger check than asserting a tooltip does not show. */
    function clear() {
        if (mq.matches) return;
        var btns = host.querySelectorAll('.contract-card-header .actions .btn[title]');
        for (var i = 0; i < btns.length; i++) {
            if (btns[i].querySelector('.cv-act-label')) btns[i].removeAttribute('title');
        }
    }

    if (window.MutationObserver) {
        new MutationObserver(function () { apply(); }).observe(host, { childList: true, subtree: true });
    }

    apply();
    var onChange = function () { apply(); clear(); };
    if (mq.addEventListener) { mq.addEventListener('change', onChange); }
    else if (mq.addListener) { mq.addListener(onChange); }
})();

/* ====================================================================
   CONTRACTS — Overview / directory on the suppliers and contacts
   screens, with a search pinned to the bottom  (#1377, Ed's request)

   ITS OWN top-level IIFE, for the reason the blocks above document.

   > "for suppliers and contacts screen I want there to be a dashboard
   >  view which has the key info and also a directory view which uses
   >  the full screen and has a search bar sticky at the bottom which
   >  searches just suppliers or just contacts."

   Two views over the same page, toggled by a two-button switch:

     OVERVIEW  — the figures at the top and the list below them, i.e.
                 what the page already was. The default, because
                 landing on a screen should tell you where you are.
     DIRECTORY — the strip goes, the list gets the whole screen, and a
                 search bar sits along the bottom filtering that page's
                 records and nothing else.

   ⭐ ZERO NEW TRANSLATION KEYS, and the labels came out better for it.
   The obvious pair is "Dashboard / Directory", neither of which exists
   in the locale files and both of which would have meant a fan-out to
   24 languages. What DOES exist is `contracts.list.overview` and the
   nav labels — so the switch reads **Overview | Suppliers** on one page
   and **Overview | Contacts** on the other, which names the thing you
   are about to browse instead of describing the layout. Same trick as
   the Calendar round, which shipped its agenda with no new keys at all.

   🔑 INJECTED, not shipped in the markup. Both pages would otherwise
   need the switch, the search bar and a `display: none` for each — and
   the hidden-at-source rule (§25) only pays for itself when the desktop
   NEEDS the element. Here it never does, so nothing is added to the
   page at all and there is no desktop render to re-check.
   ==================================================================== */
(function () {
    var PAGES = {
        'contracts-suppliers': { list: 'suppliersList', label: 'contracts.nav.suppliers' },
        'contracts-contacts':  { list: 'contactsList',  label: 'contracts.nav.contacts'  }
    };

    var cfg = PAGES[document.body.getAttribute('data-mobile-page')];
    if (!cfg) return;                                   // not one of the two pages

    var tbody = document.getElementById(cfg.list);
    var main  = document.querySelector('.contracts-main');
    if (!tbody || !main) return;

    var mq = window.matchMedia('(max-width: 768px)');

    function t(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var got = window.t(key);
        return (got && got !== key) ? got : fallback;
    }

    /* ---- the switch ------------------------------------------------ */
    var sw = document.createElement('div');
    sw.className = 'con-viewswitch';
    sw.style.display = 'none';        // the corollary: injected chrome is
                                      // hidden until syncChrome() reveals it
    var bOverview  = document.createElement('button');
    var bDirectory = document.createElement('button');
    bOverview.type = bDirectory.type = 'button';
    bOverview.className  = 'con-vs-btn';
    bDirectory.className = 'con-vs-btn';
    bOverview.textContent  = t('contracts.list.overview', 'Overview');
    bDirectory.textContent = t(cfg.label, 'Directory');
    sw.appendChild(bOverview);
    sw.appendChild(bDirectory);
    /* ⚠️ ABOVE the figures, not between them and the list. It went in before
       `.contracts-main` first, which put it after the strip — so the control
       that decides whether the strip is showing sat underneath the strip. A
       switch belongs above everything it switches. `.contracts-layout` is the
       flex column holding both panes, so its first child is the top of the
       page proper. */
    var layout = document.querySelector('.contracts-layout');
    if (layout) { layout.insertBefore(sw, layout.firstChild); }
    else { main.parentNode.insertBefore(sw, main); }

    /* ---- the search bar -------------------------------------------- */
    var bar = document.createElement('div');
    bar.className = 'con-dirsearch';
    bar.style.display = 'none';
    var input = document.createElement('input');
    input.type = 'search';
    input.className = 'con-ds-input';
    input.setAttribute('autocomplete', 'off');
    // `common.search` = "Search" — already translated everywhere.
    input.placeholder = t('common.search', 'Search');
    // The switch's own label names the set being searched, so the input
    // does not repeat it; but a screen reader has no switch in view.
    input.setAttribute('aria-label', bDirectory.textContent + ' — ' + input.placeholder);
    bar.appendChild(input);
    document.body.appendChild(bar);

    /* ---- filtering -------------------------------------------------
       Reads the rendered rows rather than the data behind them, so it
       cannot get out of step with renderSuppliers()/renderContacts() and
       needs nothing from either. */
    var empty = null;

    function rowIsRecord(tr) {
        // ⚠️ The loading / empty / error row is a single `<td colspan>`.
        // Filtering it would hide the page's own message and leave a
        // blank panel — §11's empty-state warning, in a new place.
        return tr.cells.length > 1;
    }

    function filter() {
        var q = (input.value || '').trim().toLowerCase();
        var rows = tbody.rows, shown = 0, any = false;
        for (var i = 0; i < rows.length; i++) {
            if (!rowIsRecord(rows[i])) continue;
            any = true;
            var hit = !q || (rows[i].textContent || '').toLowerCase().indexOf(q) !== -1;
            rows[i].style.display = hit ? '' : 'none';
            if (hit) shown++;
        }
        if (!empty) {
            empty = document.createElement('div');
            empty.className = 'con-ds-empty';
            empty.textContent = t('contracts.list.no_results', 'No results found');
            tbody.parentNode.parentNode.appendChild(empty);
        }
        empty.style.display = (any && q && shown === 0) ? 'block' : 'none';
    }

    function clearFilter() {
        var rows = tbody.rows;
        for (var i = 0; i < rows.length; i++) rows[i].style.display = '';
        if (empty) empty.style.display = 'none';
    }

    /* ---- view state ------------------------------------------------- */
    function setView(v) {
        document.body.setAttribute('data-contracts-view', v);
        bOverview.classList.toggle('active',  v === 'overview');
        bDirectory.classList.toggle('active', v === 'directory');
        bOverview.setAttribute('aria-pressed',  v === 'overview'  ? 'true' : 'false');
        bDirectory.setAttribute('aria-pressed', v === 'directory' ? 'true' : 'false');
        if (v === 'directory') { filter(); }
        else { clearFilter(); }
    }

    bOverview.addEventListener('click',  function () { setView('overview'); });
    bDirectory.addEventListener('click', function () { setView('directory'); });
    input.addEventListener('input', filter);

    /* The list is rebuilt by the page's own renderer after every fetch,
       which would undo the row-level `display` the filter sets. Observing
       is one line and cannot get out of step with call sites this file
       does not own — 27c's reasoning, and it applies to a filter exactly
       as it does to a label. */
    if (window.MutationObserver) {
        new MutationObserver(function () {
            if (mq.matches && document.body.getAttribute('data-contracts-view') === 'directory') filter();
        }).observe(tbody, { childList: true, subtree: true });
    }

    /* ---- and it all goes away above 768px --------------------------
       Injected chrome must be hidden off-mobile, and the body attribute
       has to go with it or a desktop resize would leave the page in a
       view whose CSS no longer exists — the strip would stay hidden with
       no switch left to bring it back. The Calendar round set the
       precedent for restoring rather than converting one way (LAYER 16),
       and this is the case that makes it non-optional. */
    function syncChrome() {
        if (mq.matches) {
            sw.style.display = '';
            if (!document.body.getAttribute('data-contracts-view')) setView('overview');
            bar.style.display = '';
        } else {
            sw.style.display = 'none';
            bar.style.display = 'none';
            document.body.removeAttribute('data-contracts-view');
            clearFilter();
        }
    }

    syncChrome();
    if (mq.addEventListener) { mq.addEventListener('change', syncChrome); }
    else if (mq.addListener) { mq.addListener(syncChrome); }
})();

/* ====================================================================
   LMS — give back the two panels lms.css deletes below 900px  (#1392)

   ITS OWN top-level IIFE, for the reason the blocks above document.

   `lms.css` carries a pre-rollout `@media (max-width: 900px)` that sets
   `.lms-editor-side, .lms-native-toc { display: none }`. Those are the
   editor's LESSON LIST (which is also where "Add lesson" lives) and the
   player's TABLE OF CONTENTS — primary navigation in both cases.

   LAYER 29d brings them back as a slide-in sheet. This is the control
   that opens it, and the scrim that closes it.

   ⭐ ZERO NEW TRANSLATION KEYS. The button's label is harvested from the
   panel's own heading — the editor's side panel is headed "Lessons" and
   the player's is the course contents — so it reads correctly in all 24
   languages and says the right thing on each of the two screens without
   this file knowing which is which. Same trick as §21's column headings.
   ==================================================================== */
(function () {
    var panel = document.querySelector('.lms-editor-side, .lms-native-toc');
    if (!panel) return;                       // not the editor or the player

    var mq = window.matchMedia('(max-width: 768px)');

    /* Where the button goes: the bar at the top of whichever screen this is.
       Both have one; neither has an id, so this takes the first thing that
       looks like the page's own header row rather than inventing markup. */
    var bar = document.querySelector('.lms-native-nav, .lms-editor-bar, .lms-editor-main');
    if (!bar) return;

    /* The panel's own heading is the honest label for the button that opens
       it — the editor's side panel is headed "Lessons" already, in whatever
       language the reader is using.
     *
     * ⚠️ THE PLAYER'S PANEL HAS NO HEADING (it is a bare `<nav id="toc">`), so
     * the fallback is not decoration — it is the label on one of the two
     * screens. The first version asked for `lms.player.contents`, which does
     * not exist, and the button rendered as the literal string
     * "☰ lms.player.contents".
     *
     * 🔑 A MISSING KEY RETURNS THE KEY, WHICH IS TRUTHY. `x || 'Contents'`
     * therefore never fired: the guard has to compare against the key itself,
     * which is the shape documents.js already uses. Caught by reading the
     * rendered button rather than trusting the fallback to be a fallback.
     *
     * `lms.editor.lessons` is used for both, and is accurate for both: a
     * course's contents IS its list of lessons. No new locale keys. */
    function tr(key, fallback) {
        if (typeof t !== 'function') return fallback;
        var got = t(key);
        return (got && got !== key) ? got : fallback;
    }
    function panelLabel() {
        var h = panel.querySelector('h1, h2, h3, h4, .lms-editor-side-head');
        var text = h ? (h.textContent || '').replace(/\s+/g, ' ').trim() : '';
        if (text) return text;
        return tr('lms.editor.lessons', 'Lessons');
    }

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'lms-panel-btn';
    btn.textContent = '☰ ' + panelLabel();   // ☰ — the same glyph the views drawer uses
    btn.setAttribute('aria-expanded', 'false');
    btn.style.display = 'none';                   // injected chrome: hidden off-mobile
    bar.insertBefore(btn, bar.firstChild);

    var scrim = null;

    function open() {
        document.body.setAttribute('data-lms-panel', 'open');
        btn.setAttribute('aria-expanded', 'true');
        if (!scrim) {
            scrim = document.createElement('div');
            scrim.className = 'lms-panel-scrim';
            scrim.addEventListener('click', close);
            document.body.appendChild(scrim);
        }
        scrim.style.display = '';
    }
    function close() {
        document.body.removeAttribute('data-lms-panel');
        btn.setAttribute('aria-expanded', 'false');
        if (scrim) scrim.style.display = 'none';
    }
    btn.addEventListener('click', function () {
        if (document.body.getAttribute('data-lms-panel') === 'open') close(); else open();
    });

    /* Choosing a lesson should close the sheet — otherwise you tap a lesson
       and the panel stays over the thing you just asked to read. Delegated,
       because both panels rebuild their contents from a fetch and neither
       call site belongs to this file.

       The panel's two BUTTONS close it for the same reason (Ed: "when you
       click 'AI: draft an outline' can you make the panel close so you are
       then on the 'AI: draft an outline' screen"). Both of them take you
       somewhere else — one opens the outline dialogue, the other starts a new
       lesson in the pane behind — so leaving the panel up puts a sheet over
       the thing you just asked for. */
    panel.addEventListener('click', function (e) {
        if (!mq.matches) return;
        var hit = e.target.closest('a, .lms-toc-item, .lms-lesson-item, .lms-editor-side-actions .btn');
        // The delete button lives inside a lesson row; closing on it would
        // hide the list you are tidying up.
        if (hit && !e.target.closest('.lms-lesson-del')) close();
    });

    /* ⚠️ And it all goes away above 768px: the button hides and the state
       attribute is removed, or a desktop resize would leave the page with a
       panel pinned open over the content and the CSS that positions it gone.
       The Calendar round set the precedent for restoring rather than
       converting one way. */
    function sync() {
        if (mq.matches) {
            btn.style.display = '';
        } else {
            btn.style.display = 'none';
            close();
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ====================================================================
   LMS EDITOR — one lesson, two pages, and the actions along the bottom
   (#1396, Ed's request)

   ITS OWN top-level IIFE, for the reason the blocks above document.

   > "each lesson should have 1 page for the main text and one page for
   >  the questions ... and can we have course settings and preview and
   >  ai write this lesson as buttons at the bottom"

   On a desktop the lesson pane is one long column: title, editor, save,
   then the questions section under it. That is fine with 900px of height
   and unreadable with 400 — you scroll past a full rich-text editor to
   reach the questions, and back again.

   Two things happen here, both gated on `mq.matches`:

     1. the pane splits into TWO PAGES behind a switch — the lesson text,
        and its questions;
     2. Course settings / Preview / AI-write-this-lesson are RELOCATED
        into a bar pinned to the bottom of the screen. They are scattered
        across two rows on the desktop layout (the first two live in the
        top bar, the third inside the pane), which is why this moves the
        nodes rather than restyling them where they sit.

   ⭐ THE FIRST TAB IS LABELLED WITH THE LESSON'S OWN NAME, and that is a
   design decision rather than a way of dodging a translation. There is no
   existing key for "text"/"content", and adding one would mean a
   fan-out to 24 locales for a single word. But on a phone the lesson list
   is behind a sheet, so the screen otherwise never says WHICH lesson you
   are editing — putting the name in the tab answers that at the same
   time. `lms.editor.questions` supplies the other tab, already
   translated. Zero new keys, and a better bar than a generic one.
   ==================================================================== */
(function () {
    var pane = document.getElementById('lessonPane');
    if (!pane) return;                       // not the course editor

    var mq = window.matchMedia('(max-width: 768px)');
    var questions = pane.querySelector('.lms-questions');
    var titleEl   = document.getElementById('lessonTitle');
    if (!questions) return;

    /* ---- the two-page switch ---- */
    var tabs = document.createElement('div');
    tabs.className = 'lms-pane-tabs';
    tabs.style.display = 'none';             // injected chrome: hidden off-mobile
    var tText = document.createElement('button');
    var tQs   = document.createElement('button');
    tText.type = tQs.type = 'button';
    tText.className = 'lms-pane-tab active';
    tQs.className   = 'lms-pane-tab';
    tQs.textContent = (typeof t === 'function' ? t('lms.editor.questions') : '') || 'Questions';
    tabs.appendChild(tText);
    tabs.appendChild(tQs);
    pane.insertBefore(tabs, pane.firstChild);

    /* The lesson's name, kept in step with the field as it is typed and as
       another lesson is chosen. Truncated by CSS rather than here, so the
       full name is still the button's accessible label. */
    function syncTabName() {
        var name = (titleEl && titleEl.value || '').trim();
        tText.textContent = name || ((typeof t === 'function' ? t('lms.editor.lesson_title') : '') || 'Lesson');
        tText.title = tText.textContent;
    }
    syncTabName();
    if (titleEl) titleEl.addEventListener('input', syncTabName);
    /* selectLesson() rewrites the title field without firing `input`, so the
       field is observed rather than the call site wrapped — 27c's reasoning,
       and it cannot get out of step with a function this file does not own. */
    if (window.MutationObserver && titleEl) {
        new MutationObserver(syncTabName).observe(titleEl, { attributes: true, attributeFilter: ['value'] });
        // …and the value property does not mutate an attribute, so also poll
        // the one event that always follows a lesson change: the pane being
        // shown again.
        new MutationObserver(syncTabName).observe(pane, { attributes: true, attributeFilter: ['style'] });
    }

    function setPage(which) {
        pane.setAttribute('data-lms-page', which);
        tText.classList.toggle('active', which === 'text');
        tQs.classList.toggle('active', which === 'questions');
        tText.setAttribute('aria-pressed', which === 'text' ? 'true' : 'false');
        tQs.setAttribute('aria-pressed', which === 'questions' ? 'true' : 'false');
    }
    tText.addEventListener('click', function () { setPage('text'); });
    tQs.addEventListener('click', function () { setPage('questions'); });

    /* ---- the bottom bar ----
       The three controls Ed named, gathered from the two places the desktop
       layout keeps them. Each is remembered with its original parent and
       next sibling so it can be put back EXACTLY where it was — not merely
       back in the right container — when the viewport leaves mobile. */
    var bar = document.createElement('div');
    bar.className = 'lms-action-bar';
    bar.style.display = 'none';

    var moved = [];
    function claim(el) {
        if (!el) return;
        moved.push({ el: el, parent: el.parentNode, next: el.nextSibling });
        bar.appendChild(el);
    }
    function restore() {
        for (var i = moved.length - 1; i >= 0; i--) {
            var m = moved[i];
            if (m.next && m.next.parentNode === m.parent) m.parent.insertBefore(m.el, m.next);
            else m.parent.appendChild(m.el);
        }
        moved = [];
    }

    function gather() {
        if (moved.length) return;            // already gathered
        var barActions = document.querySelector('.lms-editor-bar-actions');
        if (barActions) {
            // Course settings, then Preview — in the order they already read.
            Array.prototype.slice.call(barActions.children).forEach(claim);
        }
        claim(pane.querySelector('.lms-editor-tools .btn-ai'));
    }

    document.body.appendChild(bar);

    /* ---- and it all goes away above 768px ----
       The nodes go back where they came from and the page attribute is
       removed, or a desktop resize would leave half the lesson hidden with
       no switch to bring it back. */
    function sync() {
        if (mq.matches) {
            gather();
            tabs.style.display = '';
            bar.style.display = '';
            if (!pane.getAttribute('data-lms-page')) setPage('text');
        } else {
            restore();
            tabs.style.display = 'none';
            bar.style.display = 'none';
            pane.removeAttribute('data-lms-page');
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ====================================================================
   LMS PROGRESS — the filters behind one icon on a bottom bar
   (#1402, Ed's request)

   ITS OWN top-level IIFE, for the reason the blocks above document.

   > "at the bottom of the progress screen let's have a bar with icons -
   >  actually will only have one icon which will be a filter icon and
   >  then open a screen with the dropdowns"

   The Progress tab carries three `<select>`s — course, group, status —
   in the panel header. On a desktop they sit on one line beside the
   heading. At 360px they stack into three full-width rows and spend
   about 140px before a single result, which is most of the screen given
   to controls you touch once and then read past.

   So: the three selects are MOVED into a LAYER 7 sheet and the bar
   carries one icon to open it. Moved, not rebuilt — each select keeps
   its id and its inline `onchange="LMS.loadProgress()"`, so the page's
   own filtering keeps working with nothing rewired.

   ⭐ ZERO NEW LMS KEYS. The one word this needs is `common.filter`,
   added to all 25 locales in the same change — a generic word that
   belongs in `common` rather than a fourteenth private copy of "Filter"
   in a module namespace.

   ⚠️ The bar is shown only while the Progress TAB is the visible one.
   The four tabs are panels the page shows and hides by inline style, so
   the panel is observed rather than LMS.switchTab() being wrapped —
   27c's reasoning, and it cannot get out of step with a function this
   file does not own.
   ==================================================================== */
(function () {
    var panel = document.getElementById('panel-progress');
    if (!panel) return;                      // not the LMS console
    var filters = panel.querySelector('.lms-filters');
    if (!filters) return;

    var mq = window.matchMedia('(max-width: 768px)');

    function tr(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var v = window.t(key);
        return (!v || v === key) ? fallback : v;   // a missing key returns the KEY, which is truthy
    }
    var label = tr('common.filter', 'Filter');

    /* ---- the bar ---- */
    var bar = document.createElement('div');
    bar.className = 'lms-progress-bar';
    bar.style.display = 'none';              // injected chrome: @media CSS cannot hide it
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'lms-filter-btn';
    btn.setAttribute('aria-label', label);
    btn.title = label;
    btn.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" ' +
        'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>';
    bar.appendChild(btn);
    document.body.appendChild(bar);

    /* ---- the sheet (LAYER 7's .mobile-sheet chrome) ---- */
    var sheet = document.createElement('div');
    sheet.className = 'mobile-sheet mobile-sheet-lmsfilter';
    sheet.style.display = 'none';
    sheet.innerHTML =
        '<div class="ms-head"><span class="ms-title"></span>' +
        '<button type="button" class="ms-close"></button></div>' +
        '<div class="ms-body"></div>';
    sheet.querySelector('.ms-title').textContent = label;
    sheet.querySelector('.ms-close').textContent = tr('common.close', 'Close');
    document.body.appendChild(sheet);

    /* Where the selects came from, so they go back EXACTLY there. */
    var home = filters.parentNode, homeNext = filters.nextSibling;

    function filtersIntoSheet() {
        if (filters.parentNode !== sheet.querySelector('.ms-body')) {
            sheet.querySelector('.ms-body').appendChild(filters);
        }
    }
    function filtersBackToPage() {
        if (filters.parentNode === home) return;
        if (homeNext && homeNext.parentNode === home) home.insertBefore(filters, homeNext);
        else home.appendChild(filters);
    }

    function openSheet() {
        filtersIntoSheet();
        sheet.style.display = 'flex';
        history.pushState({ lmsFilter: true }, '');
    }
    function hideSheet() { sheet.style.display = 'none'; }
    function closeSheet() {
        if (history.state && history.state.lmsFilter) history.back();
        else hideSheet();
    }
    btn.addEventListener('click', openSheet);
    sheet.querySelector('.ms-close').addEventListener('click', closeSheet);
    window.addEventListener('popstate', hideSheet);

    /* ---- shown only on the Progress tab, and only on a phone ---- */
    function progressVisible() {
        return panel.style.display !== 'none';
    }
    function sync() {
        if (mq.matches && progressVisible()) {
            filtersIntoSheet();
            bar.style.display = '';
            document.body.setAttribute('data-lms-progress-bar', 'on');
        } else {
            hideSheet();
            filtersBackToPage();
            bar.style.display = 'none';
            document.body.removeAttribute('data-lms-progress-bar');
        }
    }
    if (window.MutationObserver) {
        new MutationObserver(sync).observe(panel, { attributes: true, attributeFilter: ['style'] });
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ====================================================================
   PROCESS MAPPER — the process list as a sheet (#1415)

   ITS OWN top-level IIFE, for the reason the blocks above document.

   `.pm-sidebar` is 260px fixed — width AND min-width — which is 72% of a
   360px screen, leaving about 100px of canvas. It becomes a slide-in
   sheet with a button, the same chrome as LAYER 4 and LAYER 29d.

   🔴 AND IT MAY ALREADY BE INVISIBLE. `sidebar-hover` is a stored
   per-analyst preference (Process Mapper → Settings → Left panel) that
   collapses the sidebar to a 16px strip which expands on HOVER. An
   analyst who set that at their desk arrives on a phone to a sliver that
   nothing can open — every process map in the system behind a control
   that a touch screen cannot operate. The CSS neutralises the effect and
   leaves the preference alone; this supplies the way in.

   ⚠️ The button is INJECTED rather than shipped hidden. §25 says hide at
   source, but that only pays when the desktop needs the element — here it
   never does, so nothing is added to the page.
   ==================================================================== */
(function () {
    var layout = document.querySelector('.pm-layout');
    var sidebar = document.getElementById('pmSidebar');
    var bar = document.querySelector('.pm-toolbar-left');
    if (!layout || !sidebar || !bar) return;          // not the mapper page

    var mq = window.matchMedia('(max-width: 768px)');

    function tr(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var v = window.t(key);
        return (!v || v === key) ? fallback : v;      // a missing key returns the KEY, which is truthy
    }
    /* `process-mapper.nav.processes` is the module's own name for this list,
       already translated wherever the namespace exists — so the sheet needs
       no new string. */
    var label = tr('process-mapper.nav.processes', 'Processes');

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pm-sidebar-btn';
    btn.textContent = '☰ ' + label;
    btn.setAttribute('aria-label', label);
    btn.setAttribute('aria-expanded', 'false');
    btn.style.display = 'none';                       // injected chrome: @media CSS cannot hide it
    bar.insertBefore(btn, bar.firstChild);

    var scrim = null;

    function open() {
        document.body.setAttribute('data-pm-sidebar', 'open');
        btn.setAttribute('aria-expanded', 'true');
        if (!scrim) {
            scrim = document.createElement('div');
            scrim.className = 'pm-sidebar-scrim';
            scrim.addEventListener('click', close);
            document.body.appendChild(scrim);
        }
        scrim.style.display = '';
    }
    function close() {
        document.body.removeAttribute('data-pm-sidebar');
        btn.setAttribute('aria-expanded', 'false');
        if (scrim) scrim.style.display = 'none';
    }
    btn.addEventListener('click', function () {
        if (document.body.getAttribute('data-pm-sidebar') === 'open') close(); else open();
    });

    /* Choosing a process closes the sheet — otherwise you tap a map and the
       list stays over the thing you just asked to look at. Delegated,
       because the list is rebuilt from a fetch on every search keystroke and
       the call site does not belong to this file.

       ⚠️ NOT the search box and NOT "+ New": one filters the list you are
       reading and the other opens a dialogue that wants the sheet gone
       anyway but is handled by its own modal. Keying on the item class is
       narrower and cannot catch either. */
    sidebar.addEventListener('click', function (e) {
        if (!mq.matches) return;
        if (e.target.closest('.pm-process-item')) close();
    });

    /* ---- keep the selected item in view when the details sheet opens ----

       LAYER 30f turns `.pm-detail-panel` into a bottom sheet taking 58dvh, and
       Ed's whole point was that you must still be able to SEE what you
       selected. Measured without this: the sheet leaves 195px of canvas
       showing and the step you tapped was below it, so you got its details
       and lost the thing itself.

       The canvas is its own scroller, so the fix is arithmetic rather than
       `scrollIntoView` — which would centre the step in the FULL scrollport
       (609px) and drop it straight back behind the sheet. Aim for ~70px below
       the top of the strip that is still visible.

       ⚠️ Observed rather than wrapped: the panel is opened from five
       different places in process-mapper.js (step, group, lane, connector,
       annotation) and none of those call sites belongs to this file — 27c's
       reasoning, and it cannot get out of step with functions it does not own. */
    var detail = document.getElementById('detailPanel');
    var canvas = document.getElementById('pmCanvas');
    if (detail && canvas && window.MutationObserver) {
        new MutationObserver(function () {
            if (!mq.matches || !detail.classList.contains('open')) return;
            var sel = canvas.querySelector('.pm-step.selected, .pm-group.selected, .pm-lane.selected');
            if (!sel) return;
            var visible = detail.getBoundingClientRect().top - canvas.getBoundingClientRect().top;
            if (visible <= 0) return;
            var top  = sel.offsetTop  - Math.max(12, Math.min(70, visible - sel.offsetHeight - 12));
            var left = sel.offsetLeft - Math.max(12, (canvas.clientWidth - sel.offsetWidth) / 2);
            canvas.scrollTop  = Math.max(0, top);
            canvas.scrollLeft = Math.max(0, left);
        }).observe(detail, { attributes: true, attributeFilter: ['class'] });
    }
    /* ⚠️ And it all goes away above 768px, or a desktop resize leaves the
       page with a fixed panel over the canvas and a scrim across it. */
    function sync() {
        if (mq.matches) {
            btn.style.display = '';
        } else {
            close();
            btn.style.display = 'none';
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ====================================================================
   NETWORK MAPPER — the CMDB class palette as a sheet, and keeping the
   selected node in view (#1464)

   ITS OWN top-level IIFE, for the reason the blocks above document.

   `.nm-palette` is a fixed 240px column. Measured on the real page at
   360×740 that left `.nm-canvas` at 119px — two thirds of the screen
   spent on the palette and 119px for the diagram. LAYER 31e makes it a
   slide-in sheet; this supplies the way in and out.

   ⚠️ The button is INJECTED rather than shipped hidden. §25 says hide at
   source, but that only pays when the desktop needs the element — here it
   never does, so nothing is added to the page and there is nothing for a
   desktop render to get wrong.
   ==================================================================== */
(function () {
    var wrap    = document.querySelector('.nm-canvas-wrap');
    var palette = document.querySelector('.nm-palette');
    var titles  = document.querySelector('.nm-editor-title-area');
    if (!wrap || !palette || !titles) return;         // not the mapper page

    var mq = window.matchMedia('(max-width: 768px)');

    function tr(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var v = window.t(key);
        return (!v || v === key) ? fallback : v;      // a missing key returns the KEY, which is truthy
    }
    /* `network-mapper.editor.palette_title` — "CMDB classes" — is the
       module's own name for this panel, already translated wherever the
       namespace exists. Zero new strings, right in every locale the page
       already works in. */
    var label = tr('network-mapper.editor.palette_title', 'CMDB classes');

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'nm-palette-btn';
    btn.textContent = '☰ ' + label;
    btn.setAttribute('aria-label', label);
    btn.setAttribute('aria-expanded', 'false');
    btn.style.display = 'none';                       // injected chrome: @media CSS cannot set a desktop default
    titles.appendChild(btn);

    var scrim = null;

    function open() {
        document.body.setAttribute('data-nm-palette', 'open');
        btn.setAttribute('aria-expanded', 'true');
        if (!scrim) {
            scrim = document.createElement('div');
            scrim.className = 'nm-palette-scrim';
            scrim.addEventListener('click', close);
            document.body.appendChild(scrim);
        }
        scrim.style.display = '';
    }
    function close() {
        document.body.removeAttribute('data-nm-palette');
        btn.setAttribute('aria-expanded', 'false');
        if (scrim) scrim.style.display = 'none';
    }
    btn.addEventListener('click', function () {
        if (document.body.getAttribute('data-nm-palette') === 'open') close(); else open();
    });

    /* ---- keep the selected node in view when the details sheet opens ----

       LAYER 31f puts `.nm-detail-panel` across the bottom at 58dvh, and the
       point of leaving the map visible above it is defeated if the node you
       just tapped is one of the ones now underneath it.

       The canvas is its own scroller, so this is arithmetic rather than
       `scrollIntoView` — which would centre the node in the FULL scrollport
       and drop it straight back behind the sheet. Aim for ~70px below the
       top of the strip that is still showing.

       ⚠️ Observed, not wrapped: `selectNode` is internal to the module's
       IIFE and the panel is opened from more than one path. A
       MutationObserver on the panel's own class cannot get out of step with
       call sites this file does not own (27c's reasoning, and 30f's).

       ⚠️ And it reads on the next tick. MutationObserver callbacks are
       microtasks, so measuring synchronously in the same task reports the
       layout as it was BEFORE the sheet took its height — LAYER 30f spent a
       round on exactly that and the answer was `check what you measured`,
       not a code change. */
    var detail = document.getElementById('nodeDetailPanel');
    var canvas = document.getElementById('canvas');
    if (detail && canvas && window.MutationObserver) {
        new MutationObserver(function () {
            if (!mq.matches || !detail.classList.contains('open')) return;
            setTimeout(function () {
                var sel = canvas.querySelector('.nm-node.selected');
                if (!sel) return;
                var visible = detail.getBoundingClientRect().top - canvas.getBoundingClientRect().top;
                if (visible <= 0) return;
                var top  = sel.offsetTop  - Math.max(12, Math.min(70, visible - sel.offsetHeight - 12));
                var left = sel.offsetLeft - Math.max(12, (canvas.clientWidth - sel.offsetWidth) / 2);
                canvas.scrollTop  = Math.max(0, top);
                canvas.scrollLeft = Math.max(0, left);
            }, 0);
        }).observe(detail, { attributes: true, attributeFilter: ['class'] });
    }

    /* Present mode hides the palette outright (the module's own
       `display: none !important`), so a sheet left open behind it would
       come back when you exit. Close it on the way in. */
    var present = document.getElementById('presentBtn');
    if (present) { present.addEventListener('click', close); }

    /* ⚠️ And it all goes away above 768px, or a desktop resize leaves the
       page with a scrim across it and the palette parked off-screen. */
    function sync() {
        if (mq.matches) {
            btn.style.display = '';
        } else {
            close();
            btn.style.display = 'none';
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ====================================================================
   HELP PAGES — the contents strip was tappable and inert (#1464)

   Found while opting Network Mapper's guide in, and it is NOT that
   module's bug: it is in every help page in the product, and has been
   since LAYER 16 brought the first one along.

   Each guide's own script does:

       helpMain.scrollTo({ top: … })          // the jump
       helpMain.addEventListener('scroll', …) // the active highlight

   which is right on a desktop, where `.help-main` is the scroller. Below
   900px `help.css` sets `.help-main { overflow-y: visible }` and hands the
   scroll to the document — and `inbox.css` clips <body>, so LAYER 16h gives
   the scroller role to `.help-container` instead. `scrollTo` on an element
   that does not scroll throws nothing and does nothing.

   🔴 So on a phone every numbered contents chip did nothing when tapped,
   and the highlight never moved off "1". §26's shape without the hover: a
   control that is present, looks live, and is not connected to anything —
   which reads as "this app just doesn't do that" rather than as a bug, so
   nobody reports it. Measured on network-mapper, cmdb and lms: identical
   on all three, `elementFromPoint` returning the same node before and
   after the tap (§13's check — the only one that asks whether anything a
   person can SEE moved).

   ⚠️ Fixed here rather than in seventeen page scripts, and gated on
   `mq.matches` so the desktop path is not touched at all. Delegated,
   because the strip is static markup but this file does not own the pages.
   ==================================================================== */
(function () {
    var container = document.querySelector('.help-container');
    var links = document.querySelectorAll('.help-nav-link');
    if (!container || !links.length) return;              // not a help page

    var mq = window.matchMedia('(max-width: 768px)');
    var strip = document.querySelector('.help-sidebar');

    function sectionOf(link) {
        return link.dataset ? document.getElementById(link.dataset.section) : null;
    }

    /* The jump. The page's own handler has already called preventDefault, so
       the anchor will not move either — both listeners run and only this one
       does anything below 768px. */
    document.addEventListener('click', function (e) {
        if (!mq.matches) return;
        var link = e.target && e.target.closest ? e.target.closest('.help-nav-link') : null;
        if (!link) return;
        var el = sectionOf(link);
        if (!el) return;
        var top = container.scrollTop
                + (el.getBoundingClientRect().top - container.getBoundingClientRect().top)
                - 12;
        container.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    });

    /* The highlight. The page's spy is bound to a container that never
       scrolls here, so without this the strip stays on "1" for the whole
       guide — and the strip scrolls sideways, so an active chip that is not
       brought into view is no better than none. */
    var sections = [];
    [].slice.call(links).forEach(function (l) {
        var el = sectionOf(l);
        if (el) sections.push({ link: l, el: el });
    });

    var ticking = false;
    container.addEventListener('scroll', function () {
        if (!mq.matches || ticking) return;
        ticking = true;
        window.requestAnimationFrame(function () {
            ticking = false;
            var top = container.getBoundingClientRect().top;
            var current = sections[0];
            sections.forEach(function (s) {
                if (s.el.getBoundingClientRect().top - top <= 80) current = s;
            });
            if (!current || current.link.classList.contains('active')) return;
            [].slice.call(links).forEach(function (l) { l.classList.remove('active'); });
            current.link.classList.add('active');
            /* Bring the chip into the strip by arithmetic rather than
               `scrollIntoView`, which is free to scroll the ancestors too and
               would fight the scroll that triggered this. */
            if (strip) {
                var c = current.link;
                strip.scrollLeft = Math.max(0, c.offsetLeft - (strip.clientWidth - c.offsetWidth) / 2);
            }
        });
    });
})();

/* ====================================================================
   NETWORK MAPPER — placing, moving and deleting a node with a finger
   (#1468, Ed's request)

   LAYER 31 shipped read-only and said why: you could place a node and then
   never move it, so the missing half was the RECOVERY. Ed asked for the
   drag anyway. The objection still stands, so the answer is all three:

       place    long-press a class tile, drag onto the map, release
       move     long-press a node, drag it
       delete   the node's own sheet gets a Delete

   🔴 The second and third are not extras. `deleteSelectedNode()` is bound
   to Delete/Backspace and nothing else, and a phone has neither — so
   without them a node of the wrong class, or in the wrong place, was
   permanent. A feature whose undo needs a keyboard is a one-way door.

   ⭐ NOT ONE LINE OF network-mapper.js CHANGED. Every gesture ends by
   dispatching the event the module already listens for:

       place   -> a real `drop` DragEvent carrying a DataTransfer
       move    -> `mousedown` on the node, then `mousemove` / `mouseup`
       delete  -> `keydown` { key: 'Delete' }

   so snap-to-grid, the model-coordinate maths, the read-only guard, the
   dirty flag, the connector cleanup and autosave all run exactly as they
   do under a mouse — including guards this file would otherwise have had
   to duplicate and keep in step. §1's "wrap, don't edit", applied to a
   feature rather than a layout.

   ⚠️ §22 said the palette's own HTML5 drag would probably work on touch,
   and this deliberately does NOT use it. The palette is a sheet covering
   the canvas, so it has to get out of the way MID-GESTURE, which a native
   drag will not survive. A hand-rolled touch drag can close the sheet and
   carry on.

   ⚠️ Long press, not immediate drag, and the distinction matters: a short
   drag starting on the canvas is a PAN, and one starting on the palette is
   a SCROLL of the class list. Both stay exactly as they were — the gesture
   only becomes a drag after 320ms without the finger having travelled more
   than 10px, which is how the browser's own long-press drag behaves.
   ==================================================================== */
(function () {
    var canvas  = document.getElementById('canvas');
    var palette = document.querySelector('.nm-palette-body');
    if (!canvas || !palette) return;                  // not the mapper page

    var mq = window.matchMedia('(max-width: 768px)');

    var LONG_PRESS_MS  = 320;
    var CANCEL_MOVE_PX = 10;

    var timer   = null;    // the pending long press
    var start   = null;    // where the finger went down
    var mode    = null;    // null | 'place' | 'move'
    var ghost   = null;    // the tile following the finger, for 'place'
    var subject = null;    // the tile or the node the gesture is about

    /* Read-only versions: the module's own handlers bail on `is_current`, so
       a synthesised event is harmless — but a ghost that follows your finger
       and then does nothing is worse than no gesture at all. */
    function readOnly() {
        var b = document.getElementById('readonlyBanner');
        return !!(b && b.offsetParent !== null);
    }

    function buzz() {
        try { if (navigator.vibrate) navigator.vibrate(15); } catch (e) { /* not everywhere */ }
    }

    function clearTimer() {
        if (timer) { clearTimeout(timer); timer = null; }
    }

    function cleanup() {
        clearTimer();
        if (ghost && ghost.parentNode) { ghost.parentNode.removeChild(ghost); }
        ghost = null;
        if (subject && subject.classList) { subject.classList.remove('nm-touch-drag'); }
        mode = null; start = null; subject = null;
    }

    /* ---- 1. place: a class tile onto the canvas ---------------------- */

    function beginPlace(tile, pt) {
        mode = 'place';
        subject = tile;
        buzz();
        /* The sheet covers the canvas, so it goes — and the gesture survives
           because touchmove/touchend are bound to `document`, not to the tile
           that has just slid off screen with its parent. */
        document.body.removeAttribute('data-nm-palette');
        var scrim = document.querySelector('.nm-palette-scrim');
        if (scrim) { scrim.style.display = 'none'; }
        var btn = document.querySelector('.nm-palette-btn');
        if (btn) { btn.setAttribute('aria-expanded', 'false'); }

        ghost = document.createElement('div');
        ghost.className = 'nm-drag-ghost';
        ghost.innerHTML = tile.innerHTML;
        ghost.style.left = pt.x + 'px';
        ghost.style.top  = pt.y + 'px';
        document.body.appendChild(ghost);
    }

    function finishPlace(pt) {
        var over = document.elementFromPoint(pt.x, pt.y);
        if (!over || !canvas.contains(over)) { return; }   // released off the map

        var classId = parseInt(subject.dataset.classId, 10);
        if (!classId) { return; }

        /* Hand it to the module's own drop handler, which owns the coordinate
           maths (scroll offset, zoom, the half-icon centring, the grid snap)
           and the read-only guard. Rebuilding any of that here would be a
           second copy to keep in step. */
        var dt;
        try { dt = new DataTransfer(); } catch (e) { return; }
        dt.setData('text/plain', JSON.stringify({ kind: 'nm-class', class_id: classId }));
        canvas.dispatchEvent(new DragEvent('drop', {
            bubbles: true, cancelable: true, dataTransfer: dt,
            clientX: pt.x, clientY: pt.y
        }));
    }

    /* ---- 2. move: an existing node ----------------------------------- */

    function beginMove(node, pt) {
        mode = 'move';
        subject = node;
        buzz();
        node.classList.add('nm-touch-drag');
        /* `onNodeMouseDown` selects the node, records the grab offset and
           binds the module's own mousemove/mouseup to `document`. Everything
           after this is just feeding it coordinates. */
        node.dispatchEvent(new MouseEvent('mousedown', {
            bubbles: true, cancelable: true, button: 0,
            clientX: pt.x, clientY: pt.y
        }));
    }

    function relayMouse(type, pt) {
        if (!pt) { return; }
        document.dispatchEvent(new MouseEvent(type, {
            bubbles: true, cancelable: true, button: 0,
            clientX: pt.x, clientY: pt.y
        }));
    }

    /* ---- the gesture ------------------------------------------------- */

    function point(e) {
        var t = (e.touches && e.touches[0]) ? e.touches[0]
              : (e.changedTouches && e.changedTouches[0]);
        return t ? { x: t.clientX, y: t.clientY } : null;
    }

    document.addEventListener('touchstart', function (e) {
        if (!mq.matches || mode || readOnly()) { return; }
        if (!e.touches || e.touches.length !== 1) { return; }
        var pt = point(e);
        if (!pt || !e.target || !e.target.closest) { return; }

        /* An edge handle is the connector drag, which has no touch path and
           is not part of this. Left alone, so it still selects the node. */
        if (e.target.closest('.nm-edge-handle')) { return; }

        var tile = e.target.closest('.nm-palette-tile');
        var node = e.target.closest('.nm-node');
        if (!tile && !node) { return; }

        start = pt;
        timer = setTimeout(function () {
            timer = null;
            if (tile) { beginPlace(tile, start); } else { beginMove(node, start); }
        }, LONG_PRESS_MS);
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
        if (!mq.matches) { return; }
        var pt = point(e);
        if (!pt) { return; }

        /* Before the press has fired, travel means the user is panning the
           canvas or scrolling the class list. Give the gesture back. */
        if (timer) {
            if (Math.abs(pt.x - start.x) > CANCEL_MOVE_PX ||
                Math.abs(pt.y - start.y) > CANCEL_MOVE_PX) { clearTimer(); }
            return;
        }
        if (!mode) { return; }

        /* Non-passive, so this actually takes effect: it stops the canvas
           panning underneath the drag, and it is also what suppresses the
           compatibility mouse events the browser would otherwise synthesise
           at touchend — which would arrive after ours and re-select. */
        e.preventDefault();
        if (mode === 'place') {
            ghost.style.left = pt.x + 'px';
            ghost.style.top  = pt.y + 'px';
        } else {
            relayMouse('mousemove', pt);
        }
    }, { passive: false });

    document.addEventListener('touchend', function (e) {
        if (timer) { clearTimer(); start = null; return; }   // a tap: leave it alone
        if (!mode) { return; }
        var pt = point(e) || start;
        if (mode === 'place') { finishPlace(pt); } else { relayMouse('mouseup', pt); }
        cleanup();
    });

    document.addEventListener('touchcancel', function () {
        if (mode === 'move') { relayMouse('mouseup', start); }
        cleanup();
    });

    /* ---- 3. delete: the way back out --------------------------------- */

    var footer = document.querySelector('.nm-detail-footer');
    if (footer) {
        var label = 'Delete';
        if (typeof window.t === 'function') {
            var v = window.t('common.delete');
            if (v && v !== 'common.delete') { label = v; }   // a missing key returns the KEY
        }
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'nm-detail-delete';
        del.textContent = label;
        del.style.display = 'none';                          // injected chrome (§25)
        del.addEventListener('click', function () {
            /* The module's own keydown handler owns this: it drops the
               connectors touching the node first (or `save_diagram` rejects
               the payload as having dangling references), deselects through
               `selectNode(null)` so the sheet closes, re-renders and marks
               dirty. Reimplementing that here would be four chances to
               diverge from it.

               ⚠️ No extra confirmation, deliberately. The desktop deletes on
               a single keypress, and getting here is already three taps — the
               node, its sheet, this button. A phone-only confirmation would
               make the two behave differently AND need a new string in 24
               locales for the privilege. */
            document.dispatchEvent(new KeyboardEvent('keydown', {
                key: 'Delete', bubbles: true, cancelable: true
            }));
        });
        footer.appendChild(del);

        var syncDel = function () { del.style.display = mq.matches ? '' : 'none'; };
        syncDel();
        if (mq.addEventListener) { mq.addEventListener('change', syncDel); }
        else if (mq.addListener) { mq.addListener(syncDel); }
    }

    /* ⚠️ And a resize out of mobile mid-drag must not strand a ghost, a
       lifted node, or a drag the module still thinks is in progress. */
    function sync() {
        if (!mq.matches && mode) {
            if (mode === 'move') { relayMouse('mouseup', start); }
            cleanup();
        }
    }
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ====================================================================
   WORKFLOW — keep the selected node in the map pane (#1469)

   ITS OWN top-level IIFE, for the reason the blocks above document.

   LAYER 32c turns `.wf-main` into a column: the map on top, the inspector
   underneath taking the larger share, because on this module the panel is
   where the work happens. That leaves about 200px of map — enough for the
   node you are editing and its neighbours, and NOT enough for a node four
   steps down a chain. Measured without this: tapping the second condition
   left it partly below the fold, so you got its fields and lost sight of
   which node they belonged to.

   ⭐ The whole of the rest of this module needed no JavaScript. Adding a
   node is a toolbar button, configuring it is the panel, deleting it is a
   real button — all of it already works by tap, which is why LAYER 32 is
   otherwise pure CSS. This is the one thing the page cannot express in CSS,
   so it gets wrapped: "not needing JS is not a virtue in itself".

   ⚠️ OBSERVED, NOT WRAPPED, and `#wfDetailTitle` is the observation point
   because it is the single funnel. Every path into the panel — tapping a
   node, tapping bare canvas, `+ Condition`, `+ Action`, applying an AI
   proposal — ends in `showBody(title, id)`, which sets that element's text.
   One observer covers all five and cannot get out of step with call sites
   this file does not own (27c's reasoning, and 30f's).

   ⚠️ And it only fires when a NODE is selected. Tapping bare canvas also
   changes the title (to the workflow's own settings) and must not yank the
   map around — there is nothing selected to bring into view.
   ==================================================================== */
(function () {
    var canvas = document.getElementById('wfCanvas');
    var title  = document.getElementById('wfDetailTitle');
    var wrap   = document.querySelector('.wf-canvas-wrap');
    if (!canvas || !title || !wrap || !window.MutationObserver) return;   // not the editor

    var mq = window.matchMedia('(max-width: 768px)');

    function reveal() {
        if (!mq.matches) return;
        var sel = canvas.querySelector('.wf-node.selected');
        if (!sel) return;                       // bare canvas — nothing to reveal

        /* The pane's height, not the viewport's: LAYER 32c splits by
           percentage precisely so no number here has to know what the
           toolbar above has wrapped to. */
        var pane = wrap.clientHeight;
        var top  = sel.offsetTop - Math.max(12, (pane - sel.offsetHeight) / 2);
        var left = sel.offsetLeft - Math.max(12, (canvas.clientWidth - sel.offsetWidth) / 2);
        canvas.scrollTop  = Math.max(0, top);
        canvas.scrollLeft = Math.max(0, left);
    }

    /* ⚠️ On the next tick, not synchronously. MutationObserver callbacks are
       microtasks, so reading the layout in the same task reports it as it was
       BEFORE the panel switched bodies and the pane settled — LAYER 30f spent
       a round on exactly that, and the answer was `check what you measured`
       rather than a code change. */
    new MutationObserver(function () {
        setTimeout(reveal, 0);
    }).observe(title, { childList: true, characterData: true, subtree: true });
})();

/* ====================================================================
   SYSTEM WIKI — the folder tree becomes a sheet (LAYER 36c, #1487)

   ITS OWN top-level IIFE, for the reason the blocks above document.

   The browse page is a 280px folder tree beside the file list, which on a
   360px screen leaves the list **80px** — and a 1110px table inside it,
   clipped by two ancestors that both say `overflow: hidden`, so nothing
   reported it.

   The tree cannot become a chip strip the way CMDB's flat class list did:
   the hierarchy IS the information, and `api` and `api/tickets` are
   different places only because the indentation says so. And it should not
   sit above the list either — Ed's own rule from the Contracts round
   (#1375) is that orientation you have already used should not keep taking
   room. So it goes where the Calendar sidebar went: a full-screen sheet
   behind one button, opened when you want to move folders and gone the
   rest of the time.

   What this does, and only when `mq.matches`:
     1. injects a sub-bar above the container with a single button, whose
        label is HARVESTED from the sidebar's own `.sidebar-title` — so
        there are no new translation keys and it is right in all 24
        locales;
     2. moves the REAL `.wiki-sidebar` into a `.mobile-sheet` (LAYER 7's
        chrome) rather than rebuilding it, so `#folderTree` keeps its id
        and the page's own `loadFolderTree()` and `selectFolder()` keep
        working untouched;
     3. closes the sheet when a folder is chosen, because choosing one is
        the whole reason the sheet was open;
     4. pushes a history entry so the DEVICE BACK BUTTON closes it;
     5. puts the sidebar back and removes the chrome if the viewport goes
        wide again — stricter than the tickets/assets one-way precedent,
        and necessary because 36c hides the sidebar inside the container,
        so without the restore a desktop resize strands the tree in a
        hidden sheet.

   Not one line of the page's own script changes.
   ==================================================================== */
(function () {
    var mq = window.matchMedia('(max-width: 768px)');

    var container = document.querySelector('[data-mobile-module="wiki"] .wiki-container');
    if (!container) return;                       // not the wiki browse page

    var sidebar = container.querySelector('.wiki-sidebar');
    if (!sidebar) return;

    function tr(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var v = window.t(key);
        return (!v || v === key) ? fallback : v;
    }

    /* ⭐ The button's label is the sidebar's own heading, read out of the
       DOM. The page already prints a translated "Folders" there, so there
       is nothing to add to 24 locale files and nothing to get wrong. */
    var titleEl = sidebar.querySelector('.sidebar-title');
    var treeLabel = (titleEl && (titleEl.textContent || '').trim()) || tr('system-wiki.index.folders', 'Folders');

    /* ---- the sub-bar (hidden inline, because @media CSS cannot hide a
            node this file injects — the LAYER 5 rule) ---- */
    var bar = document.createElement('div');
    bar.className = 'mobile-subbar';
    bar.style.display = 'none';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'msb-wikitree';
    btn.innerHTML = '📁 <span></span>';
    btn.querySelector('span').textContent = treeLabel;
    btn.setAttribute('aria-label', treeLabel);
    bar.appendChild(btn);
    container.parentNode.insertBefore(bar, container);

    /* ---- the sheet (LAYER 7's .mobile-sheet chrome) ---- */
    var sheet = document.createElement('div');
    sheet.className = 'mobile-sheet mobile-sheet-wikitree';
    sheet.style.display = 'none';
    sheet.innerHTML =
        '<div class="ms-head"><span class="ms-title"></span>' +
        '<button type="button" class="ms-close"></button></div>' +
        '<div class="ms-body"></div>';
    sheet.querySelector('.ms-title').textContent = treeLabel;
    sheet.querySelector('.ms-close').textContent = tr('common.close', 'Close');
    sheet.querySelector('.ms-close').addEventListener('click', close);
    document.body.appendChild(sheet);

    function open() {
        if (!mq.matches) return;
        treeIntoSheet();
        sheet.style.display = 'flex';
        history.pushState({ wikiTree: true }, '');
    }
    function hide() { sheet.style.display = 'none'; }
    function close() {
        if (history.state && history.state.wikiTree) history.back();
        else hide();
    }
    window.addEventListener('popstate', hide);
    btn.addEventListener('click', open);

    /* Moved LAZILY on first open and moved BACK on the way to desktop.
       Relocating the real node rather than cloning it is what keeps
       `#folderTree` unique — a clone would give the page two elements with
       that id and `loadFolderTree()` would render into whichever came
       first. */
    function treeIntoSheet() {
        if (sheet.contains(sidebar)) return;
        sheet.querySelector('.ms-body').appendChild(sidebar);
    }
    function treeBackToPage() {
        if (container.contains(sidebar)) return;
        container.insertBefore(sidebar, container.firstChild);
    }

    /* 3. Choosing a folder closes the sheet — but ONLY a folder that has
          nothing under it. Delegated on the sheet, and NOT
          `preventDefault`ed: the page's own `selectFolder()` is an inline
          `onclick` on the same element and must still run, because it is
          what reloads the list. This only reacts afterwards.

       🔴🔴 THE CONDITION IS THE WHOLE DESIGN, and closing on every tap was
          wrong in a way only driving it showed. `selectFolder()` **expands
          the tapped folder's children as well as selecting it** — that is
          the page's own behaviour, and it is why the tree opens collapsed.
          So a rule that closed the sheet on any row would shut it at the
          exact moment it had just revealed the next level down, and
          `api/tickets` would be **unreachable on a phone** however many
          times you tried: every tap on `api` would filter the list and
          throw away the thing you were navigating towards.

          A parent is a step; a leaf is an arrival. Tapping a parent expands
          it and the sheet stays, tapping a leaf closes it. `renderTree`
          only puts a chevron glyph in `.tree-toggle` when the node has
          children, so the markup already says which is which and nothing
          has to be inferred.

       ⚠️ The chevron itself is excluded separately. It expands a branch in
          place and calls `stopPropagation()` so the folder is not selected,
          so closing on it would be wrong for the same reason twice over. */
    sheet.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) return;
        if (e.target.closest('.tree-toggle')) return;
        var row = e.target.closest('.tree-item');
        if (!row) return;
        var toggle = row.querySelector('.tree-toggle');
        var hasChildren = !!(toggle && (toggle.textContent || '').trim());
        if (!hasChildren) close();
    });

    /* 5. …and it all goes away above 768px, or a desktop resize leaves the
          folder tree inside a hidden sheet with the page's own copy of it
          display:none in the container. */
    function sync() {
        if (mq.matches) {
            bar.style.display = '';
        } else {
            hide();
            treeBackToPage();
            bar.style.display = 'none';
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ============================================================================
   LAYER 37 - Tickets rota: three readings of one grid.

   The rota is a CSS grid of `160px repeat(N, 1fr)` - a name column and N days,
   so six columns at days-5. Section 11: a sideways scroller stops preserving a
   comparison past about four. Ed's decision: WHICH reading you want is a
   personal preference, so all three stay, flipped by a sticky footer.

   🔴 WHAT THE FIRST ATTEMPT GOT WRONG, because it is the whole reason this is
   built the way it is now. The chooser was the grid's own cells: tap a day
   heading to pick the day, tap a name to pick the analyst. But the rule that
   hid the other days ALSO hid the other day headings, and the rule that hid the
   other analysts ALSO hid their name cells. So each view showed exactly one
   choice and offered no way to reach another. Ed found both immediately.

   The probe did not, and could not: it counted visible cells, measured widths,
   confirmed the empty state survived and reported all three views working. It
   never asked "can you now change which day?" - the second tap again.

   🔑 So the chooser is no longer made of the things being filtered. A strip of
   chips sits above the grid, its labels HARVESTED from the cells' own text, so
   hiding cells cannot take the chooser with it and no new strings are invented.

   Copy / Paste week are RELOCATED into the footer, not cloned - rota.js talks
   to them by id and two #rotaCopyWeekBtn would be two bugs. Same move as the
   calendar's 16a: the real node goes, and comes back when the viewport leaves
   mobile.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'tickets-rota') return;

    var mq = window.matchMedia('(max-width: 768px)');
    var grid = document.getElementById('rotaGrid');
    var container = document.querySelector('.rota-container');
    var header = document.querySelector('.rota-header');
    if (!grid || !container || !header) return;

    var STORE = 'freeitsm.rota.mobileView';
    var VIEWS = ['day', 'analyst', 'week'];
    var view = 'day';
    try {
        var saved = localStorage.getItem(STORE);
        if (VIEWS.indexOf(saved) !== -1) view = saved;
    } catch (e) { /* private mode: the default is fine */ }

    var chosenCol = null;      // which day, in day view
    var chosenRow = null;      // which analyst, in analyst view

    /* Prefer the module's own translations; fall back only if a key is
       missing, which can happen if a page exports fewer namespaces. */
    function tr(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var v = window.t(key);
        return (!v || v === key) ? fallback : v;
    }

    /* Columns per row, read from the class rota.js sets on the grid. */
    function colCount() {
        var m = /\bdays-(\d+)\b/.exec(grid.className);
        return m ? (parseInt(m[1], 10) + 1) : 6;
    }

    var ICONS = {
        day: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/><rect x="7" y="13" width="5" height="4" rx="1" fill="currentColor" stroke="none"/></svg>',
        analyst: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></svg>',
        week: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M9 10v11M15 10v11"/></svg>'
    };
    var LABELS = {
        day:     function () { return tr('common.view_day', 'Day'); },
        analyst: function () { return tr('tickets.group_analyst', 'Analyst'); },
        week:    function () { return tr('common.view_week', 'Week'); }
    };

    var bar = null, viewsWrap = null, actionsWrap = null, chooser = null;

    // ---- the sticky footer -------------------------------------------------
    function buildBar() {
        if (bar) return;
        bar = document.createElement('div');
        bar.className = 'rota-viewbar';

        actionsWrap = document.createElement('div');
        actionsWrap.className = 'rota-vb-actions';

        viewsWrap = document.createElement('div');
        viewsWrap.className = 'rota-vb-views';
        viewsWrap.setAttribute('role', 'group');
        VIEWS.forEach(function (v) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'rota-vb-btn';
            b.dataset.view = v;
            b.innerHTML = ICONS[v] + '<span>' + LABELS[v]() + '</span>';
            b.setAttribute('aria-label', LABELS[v]());
            b.addEventListener('click', function () { setView(v); });
            viewsWrap.appendChild(b);
        });

        bar.appendChild(actionsWrap);      // Copy / Paste, where Ed asked for them
        bar.appendChild(viewsWrap);        // the three readings
        document.body.appendChild(bar);
    }

    /* Move the real Copy / Paste buttons, never copies of them: rota.js finds
       them with getElementById and toggles Paste's display, so a clone would
       leave it talking to the one still in the header. */
    function placeActions(intoFooter) {
        var copyBtn = document.getElementById('rotaCopyWeekBtn');
        var pasteBtn = document.getElementById('rotaPasteWeekBtn');
        var home = document.querySelector('.rota-actions');
        if (!copyBtn || !home || !actionsWrap) return;
        var target = intoFooter ? actionsWrap : home;
        if (copyBtn.parentElement !== target) target.appendChild(copyBtn);
        if (pasteBtn && pasteBtn.parentElement !== target) target.appendChild(pasteBtn);
    }

    function syncBarPressed() {
        if (!viewsWrap) return;
        Array.prototype.forEach.call(viewsWrap.querySelectorAll('.rota-vb-btn'), function (b) {
            b.setAttribute('aria-pressed', b.dataset.view === view ? 'true' : 'false');
        });
    }

    /* The footer's height is not a constant - it holds two rows, and Paste
       appears and disappears. Measure it and let the CSS reserve exactly that
       much, or the last analyst row hides behind it. */
    function reserveSpace() {
        if (!bar) return;
        var h = Math.ceil(bar.getBoundingClientRect().height);
        document.body.style.setProperty('--rota-bar-h', h + 'px');
    }

    // ---- the chooser strip -------------------------------------------------
    function buildChooser() {
        if (chooser) return;
        chooser = document.createElement('div');
        chooser.className = 'rota-chooser';
        chooser.addEventListener('click', function (e) {
            var chip = e.target.closest ? e.target.closest('.rota-ch-btn') : null;
            if (!chip) return;
            if (view === 'day')     chosenCol = parseInt(chip.dataset.col, 10) || 0;
            if (view === 'analyst') chosenRow = parseInt(chip.dataset.row, 10) || 0;
            apply();
        });
        header.parentNode.insertBefore(chooser, header.nextSibling);
    }

    /* Labels harvested from the grid's own cells (section 21): the day chips
       read the day name and number the page already rendered, the analyst chips
       read the name. Nothing invented, so it is right in all 24 locales. */
    function renderChooser() {
        if (!chooser) return;
        chooser.innerHTML = '';
        if (view === 'week') return;

        if (view === 'day') {
            Array.prototype.forEach.call(
                grid.querySelectorAll('.rota-col-header.rota-line-head'), function (head) {
                    var name = head.querySelector('.day-name');
                    var date = head.querySelector('.day-date');
                    var chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'rota-ch-btn';
                    chip.dataset.col = head.dataset.col;
                    chip.innerHTML =
                        '<span class="rota-ch-top">' + (name ? name.textContent : '') + '</span>' +
                        '<span class="rota-ch-sub">' + (date ? date.textContent : '') + '</span>';
                    if (head.classList.contains('today')) chip.classList.add('rota-ch-today');
                    chooser.appendChild(chip);
                });
        } else {
            Array.prototype.forEach.call(
                grid.querySelectorAll('.rota-analyst-name'), function (cell) {
                    var chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'rota-ch-btn rota-ch-wide';
                    chip.dataset.row = cell.dataset.row;
                    chip.innerHTML = '<span class="rota-ch-top">' + cell.textContent.trim() + '</span>';
                    chooser.appendChild(chip);
                });
        }
        syncChooserPressed();
    }

    function syncChooserPressed() {
        if (!chooser) return;
        Array.prototype.forEach.call(chooser.querySelectorAll('.rota-ch-btn'), function (chip) {
            var on = (view === 'day')
                ? (parseInt(chip.dataset.col, 10) === chosenCol)
                : (parseInt(chip.dataset.row, 10) === chosenRow);
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        var cur = chooser.querySelector('.rota-ch-btn[aria-pressed="true"]');
        if (cur && cur.scrollIntoView) {
            // Keep the chosen chip in sight when the week changes.
            cur.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
    }

    function setView(v) {
        view = v;
        try { localStorage.setItem(STORE, v); } catch (e) { /* not essential */ }
        document.body.setAttribute('data-rota-view', v);
        syncBarPressed();
        apply();
    }

    /* Day defaults to today when this week holds it; analyst to the first row. */
    function defaults() {
        var cols = colCount();
        if (chosenCol === null) {
            var today = grid.querySelector('.rota-col-header.rota-line-head.today');
            chosenCol = today ? (parseInt(today.dataset.col, 10) || 0) : 0;
        }
        if (chosenRow === null) {
            var firstName = grid.querySelector('.rota-analyst-name');
            chosenRow = firstName ? (parseInt(firstName.dataset.row, 10) || 0) : 0;
        }
        if (chosenCol > cols - 2) chosenCol = 0;
    }

    function clearTags() {
        Array.prototype.forEach.call(grid.children, function (el) {
            el.classList.remove('rota-m-hide');
            el.removeAttribute('data-mlabel');
        });
    }

    /* The analyst view labels each stacked cell with its date, and it must be
       the format the operator chose in preferences.

       🔴 fmtNaiveDate, NOT fmtDate. A rota day is a NAIVE calendar date - the
       third date kind - and fmtDate treats its argument as a UTC instant and
       converts it into the viewer's zone, which moves a date across midnight
       for anybody west of UTC. The label would be a day out for some people
       and right for everybody testing it in London. */
    function dayLabel(iso) {
        if (!iso) return '';
        if (typeof window.fmtNaiveDate === 'function') {
            var v = window.fmtNaiveDate(iso);
            if (v) return v;
        }
        return iso;                        // never leave the cell unlabelled
    }

    function apply() {
        if (!mq.matches) { clearTags(); return; }
        defaults();
        var cols = colCount();
        var kids = grid.children;

        clearTags();
        renderChooser();

        if (view !== 'week') {
            for (var i = 0; i < kids.length; i++) {
                var el = kids[i];

                /* Section 11: do not swallow the empty state. rota.js renders
                   "no analysts" as one div spanning `grid-column: 1 / -1`; it
                   sits at an arbitrary index and the modulo would hide it like
                   any other cell, deleting the only thing on screen that says
                   why the page is blank. */
                if (el.classList.contains('rota-empty')) continue;

                var col = i % cols;                 // 0 is the name column
                var row = Math.floor(i / cols);     // 0 is the heading row
                var show;

                if (view === 'day') {
                    // The headings live in the chooser now, so the whole
                    // heading row goes: name column plus the chosen day.
                    show = (row > 0) && (col === 0 || col === chosenCol + 1);
                } else {
                    // One analyst's days, stacked. The name is in the chooser,
                    // and each cell carries its own date as a label.
                    show = (row === chosenRow + 1) && (col > 0);
                    // Every cell gets the label, including an empty one: the
                    // date is the point of the row, so a blank day still has
                    // to say which day it is.
                    if (show && el.dataset.date) el.setAttribute('data-mlabel', dayLabel(el.dataset.date));
                }
                if (!show) el.classList.add('rota-m-hide');
            }
        }
        syncChooserPressed();
        reserveSpace();
    }

    /* rota.js replaces the grid's entire innerHTML on a week change, which
       throws the tags away AND replaces the cells the chooser was harvested
       from. Both have to be redone, or the view works until the first tap of
       the next-week arrow and then silently reverts. */
    var mo = new MutationObserver(function () {
        var cols = colCount();
        if (chosenCol !== null && chosenCol > cols - 2) chosenCol = 0;
        if (chosenRow !== null && !grid.querySelector('.rota-analyst-name[data-row="' + chosenRow + '"]')) {
            chosenRow = null;
        }
        apply();
    });
    mo.observe(grid, { childList: true });

    /* Paste appears only once a week has been copied, so the footer changes
       height after rota.js shows it. Re-measure when it does. */
    var pasteBtn = document.getElementById('rotaPasteWeekBtn');
    if (pasteBtn) {
        new MutationObserver(reserveSpace).observe(pasteBtn, { attributes: true, attributeFilter: ['style'] });
    }

    /* Today changed the week and left the chosen day alone, so on the current
       week it looked like a button that did nothing: the same column index was
       still selected. Wrap it - section 1, extend the page's JS from outside -
       and drop the choice so defaults() picks today again.
       mobile.js loads after rota.js, so the global is already there. */
    if (typeof window.goToThisWeek === 'function') {
        var rotaGoToThisWeek = window.goToThisWeek;
        window.goToThisWeek = function () {
            if (mq.matches) chosenCol = null;
            return rotaGoToThisWeek.apply(this, arguments);
        };
    }

    function sync() {
        if (mq.matches) {
            buildBar();
            buildChooser();
            placeActions(true);
            if (bar) bar.style.display = '';
            if (chooser) chooser.style.display = '';
            document.body.setAttribute('data-rota-view', view);
            syncBarPressed();
            apply();
        } else {
            // Desktop must look exactly as it did before this layer existed:
            // the buttons go home, the injected furniture is hidden, the
            // attribute and the measured variable come off.
            placeActions(false);
            if (bar) bar.style.display = 'none';
            if (chooser) { chooser.style.display = 'none'; chooser.innerHTML = ''; }
            document.body.removeAttribute('data-rota-view');
            document.body.style.removeProperty('--rota-bar-h');
            clearTags();
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ============================================================================
   LAYER 39a - a list-and-detail page as a pane stack: Tickets > Users, and
   (LAYER 43) Assets > Users, which is the same shape with assets in it.

   🔴 Tapping a user "did nothing", and so did tapping a group. Both were
   working perfectly: measured after a tap, `.user-detail-container` held 1748
   characters of freshly loaded detail. It is just that the pane is
   `flex: 1 1 0` beside a list that is `width: 400px; min-width: 300px`, so on
   a 360px screen the list takes the whole width and the detail is left
   ZERO PIXELS WIDE, parked at x=360.

   🔑 Nothing was broken, so nothing could be found by looking for a break.
   The fault is the oldest one in this rollout: a fixed-width pane beside a
   flexible one on a screen narrower than the fixed pane.

   ⭐ One block, a list of pages (extract on the second use - Mobile: Assets).
   Each entry names the two panes and the globals that open a record:
     page         the body's data-mobile-page
     list/detail  the two panes
     wraps        globals that open a record; wrapped, never edited (§1)
     deepLink     a query parameter that opens straight to a record. Assets
                  calls selectPerson() inline for ?user_id=, BEFORE this file
                  loads, so the wrap never sees it and the stack would sit on
                  the list with the person loaded out of sight.
   ========================================================================== */
(function () {
    'use strict';

    var STACKS = [
        /* Both users and groups render into the one detail container -
           selectGroup() redraws the whole pane - so one stack serves both. */
        { page: 'tickets-users', list: '.users-list-container',
          detail: '#userDetail, .user-detail-container',
          wraps: ['selectUser', 'selectGroup'], deepLink: 'user_id' },
        { page: 'assets-users', list: '.au-wrap > .au-panel:first-child',
          detail: '#auDetail',
          wraps: ['selectPerson'], deepLink: 'user_id' }
    ];

    var cfg = null;
    for (var i = 0; i < STACKS.length; i++) {
        if (document.body && document.body.getAttribute('data-mobile-page') === STACKS[i].page) { cfg = STACKS[i]; break; }
    }
    if (!cfg) return;

    var mq = window.matchMedia('(max-width: 768px)');
    var detail = document.querySelector(cfg.detail);
    var list = document.querySelector(cfg.list);
    if (!detail || !list) return;

    function tr(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var v = window.t(key);
        return (!v || v === key) ? fallback : v;
    }

    var back = null;

    /* 🔴 The open-a-record functions REDRAW the detail pane wholesale, which
       throws this button away with everything else. Exactly the same fault as
       the rota chooser: anything injected into a pane a page re-renders has to
       be put back, and the only reliable trigger is watching the pane. */
    function buildBack() {
        if (back && back.isConnected) return;
        if (back) { detail.insertBefore(back, detail.firstChild); return; }
        back = document.createElement('button');
        back.type = 'button';
        back.className = 'users-mobile-back';
        /* The label is an existing, already-translated string. */
        back.innerHTML =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
            'aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>' +
            '<span>' + tr('common.back', 'Back') + '</span>';
        back.addEventListener('click', function () { showPane('list'); });
        detail.insertBefore(back, detail.firstChild);
    }

    function showPane(which) {
        document.body.setAttribute('data-users-pane', which);
        if (which === 'detail') {
            // A new record starts at the top, not wherever the last one was read.
            detail.scrollTop = 0;
        }
    }

    /* Wrap rather than edit (§1). The pages call these globals from the row
       markup they generate, so wrapping catches every row including the ones
       drawn after a search or a re-render - which a listener bound to the rows
       would not. */
    cfg.wraps.forEach(function (name) {
        var orig = window[name];
        if (typeof orig !== 'function') return;
        window[name] = function () {
            var out = orig.apply(this, arguments);
            if (mq.matches) {
                // These are async and redraw the pane; switching now is still
                // correct because the pane is what we are revealing.
                showPane('detail');
            }
            return out;
        };
    });

    /* Put it back after every redraw of the pane. */
    new MutationObserver(function () {
        if (mq.matches) buildBack();
    }).observe(detail, { childList: true });

    var linked = false;
    if (cfg.deepLink) {
        try { linked = parseInt(new URLSearchParams(window.location.search).get(cfg.deepLink) || '', 10) > 0; } catch (e) {}
    }

    function sync() {
        if (mq.matches) {
            buildBack();
            if (back) back.style.display = '';
            if (!document.body.getAttribute('data-users-pane')) showPane(linked ? 'detail' : 'list');
        } else {
            // Desktop is a two-pane screen and must stay one.
            if (back) back.style.display = 'none';
            document.body.removeAttribute('data-users-pane');
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ============================================================================
   LAYER 39a2 - Tickets -> Users: a user's actions in a sticky footer (Ed)

   People / Edit / Manager access / Delete wrapped under the name and scrolled
   away with it. They move to a footer pinned to the bottom, as icons - the
   Domains 41i shape:

   - The REAL buttons move, never copies, and each one's words go into
     aria-label (an icon has no name).
   - selectUser() redraws #userDetail wholesale, so the footer is refilled on
     every redraw (watched, as the Back button is); the previous user's
     buttons simply go with the old markup.
   - A group (selectGroup draws into the same pane) has none of the four, so
     the footer hides. "Add to address book" stays in the header.
   - Above 768px the buttons go back where they were.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'tickets-users') return;
    var detail = document.getElementById('userDetail');
    if (!detail) return;
    var mq = window.matchMedia('(max-width: 768px)');

    var SVG = function (paths) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
               'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
    };
    // Matched by what each control does, not by its (translated) words.
    var ACTIONS = [
        { sel: 'a[href*="person"]',
          icon: SVG('<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 16 0v1"/>') },
        { sel: 'button[onclick^="openUserModal"]',
          icon: SVG('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>') },
        { sel: 'a[href^="manager-access.php"]',
          icon: SVG('<path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/>') },
        { sel: 'button[onclick^="deleteUser"]', danger: true,
          icon: SVG('<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>') }
    ];

    var footer = document.createElement('div');
    footer.className = 'users-detail-bar';
    footer.style.display = 'none';
    document.body.appendChild(footer);
    var saved = [];

    function restore() {
        saved.forEach(function (s) {
            if (!s.parent.isConnected) return;          // that user's markup is gone
            s.el.innerHTML = s.html;
            s.el.removeAttribute('aria-label');
            s.el.removeAttribute('title');
            s.el.classList.remove('udb-btn', 'udb-danger');
            s.parent.insertBefore(s.el, s.next);
        });
        saved = [];
        footer.innerHTML = '';
    }

    function fill() {
        restore();
        var actions = detail.querySelector('.user-detail-header > div > div:last-child');
        if (!actions) { footer.style.display = 'none'; document.body.classList.remove('has-users-bar'); return; }
        ACTIONS.forEach(function (a) {
            var el = actions.querySelector(a.sel);
            if (!el) return;
            saved.push({ el: el, html: el.innerHTML, parent: el.parentNode, next: el.nextSibling });
            var label = (el.textContent || '').replace(/\s+/g, ' ').trim();
            el.innerHTML = a.icon;
            if (label) { el.setAttribute('aria-label', label); el.title = label; }
            el.classList.add('udb-btn');
            if (a.danger) el.classList.add('udb-danger');
            footer.appendChild(el);
        });
        var any = footer.children.length > 0;
        footer.style.display = any ? '' : 'none';
        document.body.classList.toggle('has-users-bar', any);
    }

    var busy = false;
    new MutationObserver(function () {
        if (busy || !mq.matches) return;
        busy = true;
        try { fill(); } finally { busy = false; }
    }).observe(detail, { childList: true });

    function sync() {
        if (mq.matches) { fill(); }
        else {
            restore();
            footer.style.display = 'none';
            document.body.classList.remove('has-users-bar');
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ============================================================================
   LAYER 39b - the tickets calendar's ticket modal: icon buttons on a phone.

   Ed's request, and the footer is four controls wide: Close, Open in inbox,
   Clear schedule, Save. At 360px they wrap into a stack that pushes the modal
   body off the screen.

   ⚠️ The icons cannot come from CSS. `content:` on a pseudo-element could draw
   one, but the LABEL still has to go, and an icon-only button with no text has
   no accessible name. So the real text is harvested into `aria-label` and the
   button's markup is swapped - and put back, exactly as it was, the moment the
   viewport leaves mobile. That is the same rule that caught a `title`
   attribute leaking a hover tooltip onto five desktop buttons: an attribute
   cannot be set from a media query, so it has to be removed again by hand.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'tickets-calendar') return;

    var mq = window.matchMedia('(max-width: 768px)');
    var footer = document.querySelector('#ticketModal .modal-footer');
    if (!footer) return;

    var ICONS = {
        close:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        inbox:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h5l2 3h4l2-3h5"/><path d="M5 5h14l2 7v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5z"/></svg>',
        unschedule:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/><path d="M9 15l6 4M15 15l-6 4"/></svg>',
        save:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>'
    };

    /* Which icon belongs to which control, by the handler the page already
       wrote - not by position, which a future edit would quietly change. */
    function iconFor(el) {
        var on = (el.getAttribute('onclick') || '');
        if (el.id === 'ticketModalLink') return ICONS.inbox;
        if (/closeTicketModal/.test(on)) return ICONS.close;
        if (/unschedule/i.test(on)) return ICONS.unschedule;
        if (/save/i.test(on)) return ICONS.save;
        return null;
    }

    var swapped = [];

    function toIcons() {
        if (swapped.length) return;
        Array.prototype.forEach.call(footer.querySelectorAll('.btn'), function (el) {
            var icon = iconFor(el);
            if (!icon) return;
            var label = (el.textContent || '').trim();
            swapped.push({ el: el, html: el.innerHTML, hadAria: el.hasAttribute('aria-label') });
            el.innerHTML = icon;
            // display:none on a label removes it from the accessibility tree,
            // so the name has to be restated here.
            if (label) el.setAttribute('aria-label', label);
            el.classList.add('cal-btn-icon');
        });
    }

    function toText() {
        swapped.forEach(function (s) {
            s.el.innerHTML = s.html;
            if (!s.hadAria) s.el.removeAttribute('aria-label');
            s.el.classList.remove('cal-btn-icon');
        });
        swapped = [];
    }

    function sync() { if (mq.matches) toIcons(); else toText(); }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ============================================================================
   LAYER 40c - Checklists editor: Back and Save move to a sticky footer.

   Ed's request. The editor's top bar carries Back, the title, an unsaved-changes
   flag and Save, which at 360px leaves the title almost nothing; and Save is the
   control you reach for after scrolling to the bottom of a long template, which
   is the far end of the page from where it lives.

   The REAL nodes are relocated, not cloned: `#edSave` has a click handler bound
   in the page's own script and `#edBack` is an <a> carrying the href, so a copy
   would be a button that does nothing next to an original that still works. They
   go home when the viewport leaves mobile.

   ⚠️ The icons cannot come from CSS. An icon-only control with no text has no
   accessible name, so each one's own already-translated label is harvested into
   `aria-label` and the markup is restored exactly on the way back - the same
   rule as the calendar modal in LAYER 39b.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'checklists-edit') return;

    var mq = window.matchMedia('(max-width: 768px)');
    var back = document.getElementById('edBack');
    var save = document.getElementById('edSave');
    var bar = document.querySelector('.ed-bar');
    if (!back || !save || !bar) return;

    var ICONS = {
        edBack: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>',
        edSave: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>'
    };

    var footer = null;
    var saved = [];          // original markup, so desktop gets it back exactly

    function build() {
        if (footer) return;
        footer = document.createElement('div');
        footer.className = 'ed-mobile-bar';
        document.body.appendChild(footer);
    }

    function toIcons() {
        if (saved.length) return;
        [back, save].forEach(function (el) {
            saved.push({ el: el, html: el.innerHTML, parent: el.parentNode, next: el.nextSibling,
                         hadAria: el.hasAttribute('aria-label') });
            var label = (el.textContent || '').trim();
            el.innerHTML = ICONS[el.id] || '';
            if (label) el.setAttribute('aria-label', label);
            el.classList.add('ed-mb-btn');
            footer.appendChild(el);
        });
    }

    function toText() {
        saved.forEach(function (s) {
            s.el.innerHTML = s.html;
            if (!s.hadAria) s.el.removeAttribute('aria-label');
            s.el.classList.remove('ed-mb-btn');
            // Back exactly where it was, not merely back into the bar.
            s.parent.insertBefore(s.el, s.next);
        });
        saved = [];
    }

    /* The footer is fixed, so the editor needs to reserve its height or the last
       step sits behind it. Measured rather than assumed - the bar is one row
       here, but a constant is wrong in whichever state you did not measure. */
    function reserve() {
        if (!footer) return;
        document.body.style.setProperty('--ed-bar-h',
            Math.ceil(footer.getBoundingClientRect().height) + 'px');
    }

    function sync() {
        if (mq.matches) {
            build();
            footer.style.display = '';
            toIcons();
            reserve();
        } else {
            toText();
            if (footer) footer.style.display = 'none';
            document.body.style.removeProperty('--ed-bar-h');
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ============================================================================
   LAYER 40e - Checklists list: the scope panel becomes a sheet.

   Ed: "the top half of the screen which is used to set the scope - can this be
   moved to its own full screen panel which you access by an icon in a sticky
   footer". That is §4's section sheet, and the module is a good candidate: the
   sidebar is a New button, a search box and THREE filter groups (scope,
   categories, roles), which is most of a phone screen before a single template
   is shown.

   The REAL `.chk-sidebar` is relocated into the sheet, not cloned - the filter
   links call `setScopeFilter(..., this)` and friends with `this`, and the
   active-link bookkeeping is done by walking `.scope-link` / `.cat-link` /
   `.role-link`, so a second copy would leave two sets of links disagreeing
   about which one is active. It goes home when the viewport leaves mobile:
   the calendar's 16a move.

   🔑 The sheet closes when a FILTER is tapped, because that is an arrival -
   you have chosen, and the thing you chose is behind the sheet. It does NOT
   close on the search box or on "Manage", which are not arrivals. The System
   Wiki round is the reason that distinction is written down: closing on every
   tap there would have made a whole branch unreachable.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'checklists') return;

    var mq = window.matchMedia('(max-width: 768px)');
    var sidebar = document.querySelector('.chk-sidebar');
    var layout = document.querySelector('.chk-layout');
    if (!sidebar || !layout) return;

    var home = { parent: sidebar.parentNode, next: sidebar.nextSibling };

    function tr(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var v = window.t(key);
        return (!v || v === key) ? fallback : v;
    }

    var ICONS = {
        filter: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 5h18M7 12h10M10 19h4"/></svg>',
        close:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>'
    };

    var sheet = null, sheetBody = null, bar = null;

    function build() {
        if (sheet) return;

        sheet = document.createElement('div');
        sheet.className = 'chk-sheet';
        sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('aria-modal', 'true');

        var head = document.createElement('div');
        head.className = 'chk-sheet-head';
        var title = document.createElement('span');
        title.className = 'chk-sheet-title';
        title.textContent = tr('common.filter', 'Filters');
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'chk-sheet-close';
        close.innerHTML = ICONS.close;
        close.setAttribute('aria-label', tr('common.close', 'Close'));
        close.addEventListener('click', function () { setOpen(false); });
        head.appendChild(title);
        head.appendChild(close);

        sheetBody = document.createElement('div');
        sheetBody.className = 'chk-sheet-body';

        sheet.appendChild(head);
        sheet.appendChild(sheetBody);
        document.body.appendChild(sheet);

        bar = document.createElement('div');
        bar.className = 'chk-fbar';
        var open = document.createElement('button');
        open.type = 'button';
        open.className = 'chk-fbar-btn';
        open.innerHTML = ICONS.filter + '<span>' + tr('common.filter', 'Filters') + '</span>';
        open.setAttribute('aria-label', tr('common.filter', 'Filters'));
        open.addEventListener('click', function () { setOpen(true); });
        bar.appendChild(open);
        document.body.appendChild(bar);

        /* An arrival closes the sheet; a search keystroke or "Manage" does not.
           Delegated, because the category and role links are rendered from the
           page's own data and a listener per link would miss any redraw. */
        sheetBody.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('.chk-filter-link') : null;
            if (link) setOpen(false);
        });
    }

    function setOpen(open) {
        document.body.setAttribute('data-chk-sheet', open ? 'open' : 'closed');
        if (open && sheetBody) sheetBody.scrollTop = 0;
    }

    function place(intoSheet) {
        if (intoSheet) {
            if (sheetBody && sidebar.parentNode !== sheetBody) sheetBody.appendChild(sidebar);
        } else if (sidebar.parentNode !== home.parent) {
            home.parent.insertBefore(sidebar, home.next);
        }
    }

    function reserve() {
        if (!bar) return;
        document.body.style.setProperty('--chk-bar-h',
            Math.ceil(bar.getBoundingClientRect().height) + 'px');
    }

    function sync() {
        if (mq.matches) {
            build();
            place(true);
            if (bar) bar.style.display = '';
            if (!document.body.getAttribute('data-chk-sheet')) setOpen(false);
            reserve();
        } else {
            place(false);
            if (bar) bar.style.display = 'none';
            if (sheet) sheet.style.display = 'none';
            document.body.removeAttribute('data-chk-sheet');
            document.body.style.removeProperty('--chk-bar-h');
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ==========================================================================
   LAYER 41 - Domains: the register's sidebar as a sheet (Techniques §4)

   Search, ten views and six filters stacked above the list on a phone, a
   screen and a half before the first domain. The REAL `.dom-sidebar` node
   moves into a full-screen sheet opened from a Filters bar at the bottom,
   and moves home again when the viewport leaves mobile - so every listener
   domains-register.js attached to it keeps working, and desktop never sees
   the sheet or the bar.

   🔑 The sheet closes when a VIEW is tapped - that is an arrival, and what
   you chose is behind the sheet. It stays open for the search box and the
   filter drop-downs, because you often set several (System Wiki's lesson:
   closing on every tap can make the next choice unreachable).

   The bar shows the current view beside "Filters", harvested from the
   view's own button, so the list's state is visible without opening the
   sheet and no string is invented.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'domains-register') return;

    var mq = window.matchMedia('(max-width: 768px)');
    var sidebar = document.querySelector('.dom-sidebar');
    if (!sidebar) return;

    var home = { parent: sidebar.parentNode, next: sidebar.nextSibling };

    function tr(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var v = window.t(key);
        return (!v || v === key) ? fallback : v;
    }

    var ICONS = {
        filter: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 5h18M7 12h10M10 19h4"/></svg>',
        close:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>'
    };

    var sheet = null, sheetBody = null, bar = null, viewLabel = null;

    function build() {
        if (sheet) return;

        sheet = document.createElement('div');
        sheet.className = 'dom-sheet';
        sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('aria-modal', 'true');

        var head = document.createElement('div');
        head.className = 'dom-sheet-head';
        var title = document.createElement('span');
        title.className = 'dom-sheet-title';
        title.textContent = tr('common.filter', 'Filters');
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'dom-sheet-close';
        close.innerHTML = ICONS.close;
        close.setAttribute('aria-label', tr('common.close', 'Close'));
        close.addEventListener('click', function () { setOpen(false); });
        head.appendChild(title);
        head.appendChild(close);

        sheetBody = document.createElement('div');
        sheetBody.className = 'dom-sheet-body';
        sheet.appendChild(head);
        sheet.appendChild(sheetBody);
        document.body.appendChild(sheet);

        bar = document.createElement('div');
        bar.className = 'dom-fbar';
        var open = document.createElement('button');
        open.type = 'button';
        open.className = 'dom-fbar-btn';
        open.innerHTML = ICONS.filter + '<span>' + tr('common.filter', 'Filters') + '</span>';
        viewLabel = document.createElement('span');
        viewLabel.className = 'dom-fbar-view';
        open.appendChild(viewLabel);
        open.addEventListener('click', function () { setOpen(true); });
        bar.appendChild(open);
        document.body.appendChild(bar);

        /* A view is an arrival; delegated, because the page redraws the views
           on every render and a listener per button would miss the redraw.
           🔴 CAPTURE phase: the page's own click handler re-renders #domViews,
           so by the time a bubbling listener runs the tapped button has been
           replaced and `closest('#domViews button')` finds nothing - the list
           changed and the sheet stayed open over it. Capture runs first. */
        sheetBody.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('#domViews button') : null;
            if (btn) setOpen(false);
        }, true);

        /* Keep the bar's view label in step with the page's own redraws. */
        var views = document.getElementById('domViews');
        if (views && window.MutationObserver) {
            new MutationObserver(showView).observe(views, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
        }
        showView();
    }

    function showView() {
        if (!viewLabel) return;
        var active = document.querySelector('#domViews button.active');
        var text = '';
        if (active) {
            // The button holds the view's name and a count; take the text
            // nodes and the first element only, so the count stays behind.
            for (var i = 0; i < active.childNodes.length; i++) {
                var n = active.childNodes[i];
                if (n.nodeType === 3) text += n.nodeValue;
                else if (n.nodeType === 1 && !n.classList.contains('count')) text += n.textContent;
            }
        }
        text = text.replace(/\s+/g, ' ').trim();
        viewLabel.textContent = text ? '· ' + text : '';
        if (bar) bar.querySelector('.dom-fbar-btn').setAttribute('aria-label', tr('common.filter', 'Filters') + (text ? ' - ' + text : ''));
    }

    function setOpen(open) {
        document.body.setAttribute('data-dom-sheet', open ? 'open' : 'closed');
        if (open && sheetBody) sheetBody.scrollTop = 0;
    }

    function place(intoSheet) {
        if (intoSheet) {
            if (sheetBody && sidebar.parentNode !== sheetBody) sheetBody.appendChild(sidebar);
        } else if (sidebar.parentNode !== home.parent) {
            home.parent.insertBefore(sidebar, home.next);
        }
    }

    function reserve() {
        if (!bar) return;
        document.body.style.setProperty('--dom-bar-h', Math.ceil(bar.getBoundingClientRect().height) + 'px');
    }

    function sync() {
        if (mq.matches) {
            build();
            place(true);
            bar.style.display = '';
            sheet.style.display = '';
            if (!document.body.getAttribute('data-dom-sheet')) setOpen(false);
            reserve();
        } else {
            place(false);
            if (bar) bar.style.display = 'none';
            if (sheet) sheet.style.display = 'none';
            document.body.removeAttribute('data-dom-sheet');
            document.body.style.removeProperty('--dom-bar-h');
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ==========================================================================
   LAYER 41i - Domains: a domain's actions in a sticky footer (Ed)

   Refresh, Check now, Edit and Delete sat in a wrapping row under the hero,
   scrolled away as soon as you read down the page. They move to a footer
   pinned to the bottom, as icons - the Checklists editor's 40c, done the same
   way:

   - The REAL buttons move, never copies: domains-view.js binds each by id,
     so a clone would do nothing beside an original that still works.
   - Each button's own label goes into aria-label (an icon has no name), and
     Refresh and Check keep the icon they already carry; only Edit and Delete
     get one here.
   - busy() swaps a button's contents for "Working..." and back, saving what
     was there when clicked - so the icon is what comes back.
   - Above 768px everything returns exactly where it was, title text and all.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'domains-view') return;

    var mq = window.matchMedia('(max-width: 768px)');
    // Back first, as in the Checklists editor's footer (Ed: "a proper back button").
    var ids = ['domBack', 'btnRefresh', 'btnCheck', 'btnEdit', 'btnDelete'];
    var btns = ids.map(function (id) { return document.getElementById(id); }).filter(Boolean);
    if (btns.length !== ids.length) return;

    var ICONS = {
        domBack:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>',
        btnEdit:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
        btnDelete: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>'
    };

    var footer = null;
    var saved = [];

    function build() {
        if (footer) return;
        footer = document.createElement('div');
        footer.className = 'dom-view-bar';
        document.body.appendChild(footer);
    }

    function toIcons() {
        if (saved.length) return;
        btns.forEach(function (el) {
            saved.push({ el: el, html: el.innerHTML, parent: el.parentNode, next: el.nextSibling,
                         hadAria: el.hasAttribute('aria-label') });
            // The back link reads "← All domains": the arrow is the icon now.
            var label = (el.textContent || '').replace(/\s+/g, ' ').replace(/^[\u2190<]+\s*/, '').trim();
            var own = el.querySelector('svg');
            el.innerHTML = ICONS[el.id] || (own ? own.outerHTML : '');
            if (label) el.setAttribute('aria-label', label);
            el.classList.add('dom-vb-btn');
            footer.appendChild(el);
        });
    }

    function toText() {
        saved.forEach(function (s) {
            s.el.innerHTML = s.html;
            if (!s.hadAria) s.el.removeAttribute('aria-label');
            s.el.classList.remove('dom-vb-btn');
            s.parent.insertBefore(s.el, s.next);      // exactly where it was
        });
        saved = [];
    }

    /* Reserve the footer's real height, so the last card is not behind it. */
    function reserve() {
        if (!footer) return;
        document.body.style.setProperty('--dom-vbar-h', Math.ceil(footer.getBoundingClientRect().height) + 'px');
    }

    function sync() {
        if (mq.matches) {
            build();
            footer.style.display = '';
            toIcons();
            reserve();
        } else {
            toText();
            if (footer) footer.style.display = 'none';
            document.body.style.removeProperty('--dom-vbar-h');
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ==========================================================================
   Contracts list on a phone: Overview / Contracts views + a sticky footer (Ed)

   The page is a sidebar (search, the five figures, quick links, New contract)
   stacked over the contract cards, so the cards started a screen and a half
   down. On a phone it becomes two views and a footer, the tickets rota's
   switcher shape (fixed, bottom, z-index 1200, env() for the home indicator):

     Overview   the figures and quick links (the sidebar)
     Contracts  the list on its own
     Search     the page's own search modal (openSearchModal)
     Add        the sidebar's New contract link (edit.php)
     Filter     a sheet built FROM the page's #partyFilter options; picking
                one sets the real select and fires its change, so
                loadContracts() does the work exactly as on desktop. A dot on
                the button says a filter is on while the select is hidden.

   The view is ONE attribute on <body>; CSS does the hiding. Which view you
   were on is remembered per browser (a convenience, so wrapped in try).
   Zero new strings: every label already exists and is translated.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'contracts-list') return;
    var mq = window.matchMedia('(max-width: 768px)');
    var select = document.getElementById('partyFilter');
    var KEY = 'freeitsm.contracts.mobileView';

    function tr(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var v = window.t(key);
        return (!v || v === key) ? fallback : v;
    }
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    }
    var SVG = function (p) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
               'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
    };
    var ITEMS = [
        { id: 'overview', label: tr('contracts.list.overview', 'Overview'),
          icon: SVG('<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>') },
        { id: 'list', label: tr('contracts.nav.contracts', 'Contracts'),
          icon: SVG('<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>') },
        { id: 'search', label: tr('common.search', 'Search'),
          icon: SVG('<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>') },
        { id: 'add', label: tr('common.add', 'Add'),
          icon: SVG('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>') },
        { id: 'filter', label: tr('common.filter', 'Filter'),
          icon: SVG('<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>') }
    ];

    var bar = document.createElement('nav');
    bar.className = 'ctr-mbar';
    bar.style.display = 'none';
    var btns = {};
    ITEMS.forEach(function (it) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ctr-mbar-btn';
        b.setAttribute('data-act', it.id);
        b.innerHTML = it.icon + '<span>' + esc(it.label) + '</span>';
        b.addEventListener('click', function () { act(it.id); });
        bar.appendChild(b);
        btns[it.id] = b;
    });
    document.body.appendChild(bar);

    // The filter sheet: one row per option of the real select.
    var sheet = document.createElement('div');
    sheet.className = 'ctr-filter-sheet';
    sheet.style.display = 'none';
    document.body.appendChild(sheet);

    function buildSheet() {
        if (!select) return;
        var title = select.getAttribute('aria-label') || tr('common.filter', 'Filter');
        var html = '<div class="cfs-panel" role="dialog" aria-label="' + esc(title) + '">' +
                   '<div class="cfs-head"><span>' + esc(title) + '</span>' +
                   '<button type="button" class="cfs-close">' + esc(tr('common.close', 'Close')) + '</button></div>';
        Array.prototype.forEach.call(select.options, function (o, i) {
            html += '<button type="button" class="cfs-opt' + (o.value === select.value ? ' on' : '') +
                    '" data-i="' + i + '">' + esc(o.textContent) + '</button>';
        });
        sheet.innerHTML = html + '</div>';
        sheet.querySelector('.cfs-close').addEventListener('click', closeSheet);
        Array.prototype.forEach.call(sheet.querySelectorAll('.cfs-opt'), function (el) {
            el.addEventListener('click', function () {
                select.selectedIndex = parseInt(el.getAttribute('data-i'), 10);
                select.dispatchEvent(new Event('change', { bubbles: true }));
                syncDot();
                closeSheet();
                setView('list');
            });
        });
    }
    function openSheet() { buildSheet(); sheet.style.display = 'flex'; }
    function closeSheet() { sheet.style.display = 'none'; }
    sheet.addEventListener('click', function (e) { if (e.target === sheet) closeSheet(); });

    function syncDot() {
        btns.filter.classList.toggle('has-dot', !!(select && select.value));
    }

    function setView(v) {
        document.body.setAttribute('data-contracts-view', v);
        btns.overview.setAttribute('aria-pressed', v === 'overview' ? 'true' : 'false');
        btns.list.setAttribute('aria-pressed', v === 'list' ? 'true' : 'false');
        try { localStorage.setItem(KEY, v); } catch (e) {}
        window.scrollTo(0, 0);
        var lay = document.querySelector('.contracts-layout');
        if (lay) lay.scrollTop = 0;
    }

    function act(id) {
        if (id === 'overview' || id === 'list') setView(id);
        else if (id === 'search' && typeof window.openSearchModal === 'function') window.openSearchModal();
        else if (id === 'add') {
            var a = document.querySelector('.sidebar-add-btn');
            window.location.href = a ? a.getAttribute('href') : 'edit.php';
        }
        else if (id === 'filter') openSheet();
    }

    function sync() {
        if (mq.matches) {
            var v = 'overview';
            try { v = localStorage.getItem(KEY) || 'overview'; } catch (e) {}
            setView(v === 'list' ? 'list' : 'overview');
            bar.style.display = '';
            syncDot();
            if (!select) btns.filter.style.display = 'none';
        } else {
            document.body.removeAttribute('data-contracts-view');
            bar.style.display = 'none';
            closeSheet();
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ==========================================================================
   LAYER 29t - LMS -> Tests builder: the question count gets its heading back

   On a phone each skill row is a small card and the one row of column heads
   above them is hidden (it cannot label a stack). Difficulty and format are
   selects that say what they are; the count is a bare "5". Harvest the
   heading from the hidden head - already translated - onto each row as an
   attribute, and LAYER 29t prints it as the row ::before. Rows are added,
   removed and rebuilt by lms-tests.js, so the list is watched (§21).
   Phone only, and the attribute is removed again above 768px (§25).
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'lms-tests-edit') return;
    var list = document.getElementById('skills');
    var head = document.querySelector('.ct-skill-head');
    if (!list || !head) return;
    var mq = window.matchMedia('(max-width: 768px)');
    var ATTR = 'data-mobile-count-label';

    function label() {
        var cell = head.children[2];
        return cell ? (cell.textContent || '').replace(/\s+/g, ' ').trim() : '';
    }
    function apply() {
        var text = mq.matches ? label() : '';
        Array.prototype.forEach.call(list.querySelectorAll('.ct-skill'), function (row) {
            if (text) { if (row.getAttribute(ATTR) !== text) row.setAttribute(ATTR, text); }
            else row.removeAttribute(ATTR);
        });
    }
    new MutationObserver(apply).observe(list, { childList: true });
    apply();
    if (mq.addEventListener) { mq.addEventListener('change', apply); }
    else if (mq.addListener) { mq.addListener(apply); }
})();

/* ==========================================================================
   LAYER 44a - Projects portfolio: the sidebar as a sheet (Techniques §4)

   A 240px sidebar beside the cards left the card grid 120px wide on a phone.
   The Domains register's answer (LAYER 41), under this module's names: the
   REAL `.prj-sidebar` moves into a full-screen sheet opened from a Filters
   bar at the bottom, and moves home when the viewport leaves mobile - so
   every listener projects-portfolio.js attached keeps working, and desktop
   never builds the sheet or the bar.

   🔑 A view is an arrival: the sheet closes on it. The search box keeps it
   open while you type. The bar shows the current view, harvested from the
   view's own label, so no string is invented.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'projects-portfolio') return;

    var mq = window.matchMedia('(max-width: 768px)');
    var sidebar = document.querySelector('.prj-sidebar');
    if (!sidebar) return;
    var home = { parent: sidebar.parentNode, next: sidebar.nextSibling };

    function tr(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var v = window.t(key);
        return (!v || v === key) ? fallback : v;
    }
    var ICONS = {
        filter: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 5h18M7 12h10M10 19h4"/></svg>',
        close:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>'
    };

    var sheet = null, sheetBody = null, bar = null, viewLabel = null;

    function build() {
        if (sheet) return;
        sheet = document.createElement('div');
        sheet.className = 'prj-msheet';
        sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('aria-modal', 'true');
        var head = document.createElement('div');
        head.className = 'prj-msheet-head';
        var title = document.createElement('span');
        title.className = 'prj-msheet-title';
        title.textContent = tr('common.filter', 'Filter');
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'prj-msheet-close';
        close.innerHTML = ICONS.close;
        close.setAttribute('aria-label', tr('common.close', 'Close'));
        close.addEventListener('click', function () { setOpen(false); });
        head.appendChild(title);
        head.appendChild(close);
        sheetBody = document.createElement('div');
        sheetBody.className = 'prj-msheet-body';
        sheet.appendChild(head);
        sheet.appendChild(sheetBody);
        document.body.appendChild(sheet);

        bar = document.createElement('div');
        bar.className = 'prj-fbar';
        var open = document.createElement('button');
        open.type = 'button';
        open.className = 'prj-fbar-btn';
        open.innerHTML = ICONS.filter + '<span>' + tr('common.filter', 'Filter') + '</span>';
        viewLabel = document.createElement('span');
        viewLabel.className = 'prj-fbar-view';
        open.appendChild(viewLabel);
        open.addEventListener('click', function () { setOpen(true); });
        bar.appendChild(open);
        document.body.appendChild(bar);

        /* CAPTURE phase - the Domains lesson: a page that redraws what was
           tapped detaches it before a bubbling listener can ask where it was.
           This page only toggles classes today, but the capture costs
           nothing and survives the day it starts redrawing. */
        sheetBody.addEventListener('click', function (e) {
            var li = e.target.closest ? e.target.closest('#prjViews li[data-view]') : null;
            if (li) setOpen(false);
        }, true);

        var views = document.getElementById('prjViews');
        if (views && window.MutationObserver) {
            new MutationObserver(showView).observe(views, { subtree: true, attributes: true, attributeFilter: ['class'] });
        }
        showView();
    }

    function showView() {
        if (!viewLabel) return;
        var active = document.querySelector('#prjViews li.active > span');
        var text = active ? (active.textContent || '').replace(/\s+/g, ' ').trim() : '';
        viewLabel.textContent = text ? '· ' + text : '';
        bar.querySelector('.prj-fbar-btn').setAttribute('aria-label', tr('common.filter', 'Filter') + (text ? ' - ' + text : ''));
    }

    function setOpen(open) {
        if (!sheet) return;
        sheet.classList.toggle('open', !!open);
        if (open && sheetBody) sheetBody.scrollTop = 0;
    }

    function place(intoSheet) {
        if (intoSheet) {
            if (sheetBody && sidebar.parentNode !== sheetBody) sheetBody.appendChild(sidebar);
        } else if (sidebar.parentNode !== home.parent) {
            home.parent.insertBefore(sidebar, home.next);
        }
    }

    function reserve() {
        if (!bar) return;
        document.body.style.setProperty('--prj-bar-h', Math.ceil(bar.getBoundingClientRect().height) + 'px');
    }

    function sync() {
        if (mq.matches) {
            build();
            place(true);
            bar.style.display = '';
            sheet.style.display = '';
            reserve();
        } else {
            place(false);
            setOpen(false);
            if (bar) bar.style.display = 'none';
            if (sheet) sheet.style.display = 'none';
            document.body.style.removeProperty('--prj-bar-h');
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ==========================================================================
   LAYER 44b - Projects: a project's actions in a sticky footer (41i's shape)

   "All projects" was a small grey text link, and Edit / Delete sat at the
   foot of the banner and scrolled away. On a phone all three live in a
   footer pinned to the bottom - Back as a chevron square, Edit and Delete as
   icons - done exactly as the Domains view (41i):

   - The REAL link and buttons move, never copies: projects-view.js binds
     #pvEdit and #pvDelete by id, so a clone would do nothing.
   - Each one's own label goes into aria-label (an icon has no name).
   - Above 768px everything returns exactly where it was.
   - Template (3.2.0, save as a template) is an icon square like Back, between
     Edit and Delete; it stays [hidden] for anyone without the permission.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'projects-view') return;

    var mq = window.matchMedia('(max-width: 768px)');
    var btns = [document.querySelector('#prjPage > .prj-back'), document.getElementById('pvEdit'), document.getElementById('pvTemplate'), document.getElementById('pvDelete')];
    if (!btns[0] || !btns[1] || !btns[3]) return;
    btns = btns.filter(Boolean);

    var ICONS = {
        back:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>',
        pvEdit:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
        pvTemplate: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
        pvDelete: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>'
    };

    var footer = null;
    var saved = [];

    function build() {
        if (footer) return;
        footer = document.createElement('div');
        footer.className = 'prj-view-bar';
        document.body.appendChild(footer);
    }

    function toIcons() {
        if (saved.length) return;
        btns.forEach(function (el) {
            saved.push({ el: el, html: el.innerHTML, parent: el.parentNode, next: el.nextSibling,
                         hadAria: el.hasAttribute('aria-label') });
            // The back link reads "← All projects": the arrow becomes the icon.
            var label = (el.textContent || '').replace(/\s+/g, ' ').replace(/^[←<]+\s*/, '').trim();
            // Two actions leave room for their own words (one each, "Edit" /
            // "Delete"); Back is the square, as in the Domains footer.
            var word = document.createElement('span');
            word.textContent = label;
            el.innerHTML = ICONS[el.id] ? ICONS[el.id] + word.outerHTML : ICONS.back;
            if (label) el.setAttribute('aria-label', label);
            el.classList.add('prj-vb-btn');
            footer.appendChild(el);
        });
    }

    function toText() {
        saved.forEach(function (s) {
            s.el.innerHTML = s.html;
            if (!s.hadAria) s.el.removeAttribute('aria-label');
            s.el.classList.remove('prj-vb-btn');
            s.parent.insertBefore(s.el, s.next);      // exactly where it was
        });
        saved = [];
    }

    function reserve() {
        if (!footer) return;
        document.body.style.setProperty('--prj-vbar-h', Math.ceil(footer.getBoundingClientRect().height) + 'px');
    }

    function sync() {
        if (mq.matches) {
            build();
            footer.style.display = '';
            toIcons();
            reserve();
        } else {
            toText();
            if (footer) footer.style.display = 'none';
            document.body.style.removeProperty('--prj-vbar-h');
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ==========================================================================
   LAYER 44c - Projects plan: move a task to another phase without a drag

   On desktop a task is dragged between lanes (HTML5 drag-and-drop). §22
   says that kind of drag CAN fire on iOS from a long press - but here the
   grip only appears on :hover (§26), the row is mostly a link whose long
   press opens the link menu, and a 360px screen shows one lane at a time, so
   the lane you want is usually off-screen. A phone gets a picker instead: a
   small button on each row opens a sheet listing the plan's own lanes.

   🔑 Wrap, don't edit (§1), Network Mapper's way (LAYER 31): choosing a
   lane dispatches the SAME events the page already listens for - a
   `dragstart` on the row and a `drop` on the lane - so projects-view.js's own
   moveTask() does the API call and the redraw, and not one line of it
   changed. The events carry a stub dataTransfer, so no browser needs the
   DataTransfer constructor.

   Zero new strings: the lane names and kind chips come from the lanes'
   own headings, the sheet's title is the task's own title, and the button's
   name is the plan's own word for a lane ("Phase", "Stage", "Sprint").
   Rows are rebuilt on every change, so the plan is watched; nothing is
   injected above 768px, and everything injected is removed when the
   viewport leaves mobile.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'projects-view') return;

    var mq = window.matchMedia('(max-width: 768px)');
    var plan = document.getElementById('pvPlan');
    if (!plan || !window.MutationObserver) return;

    function tr(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var v = window.t(key);
        return (!v || v === key) ? fallback : v;
    }
    function txt(el) { return el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : ''; }
    var ICON_MOVE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16M3 16l4 4 4-4"/><path d="M17 20V4M13 8l4-4 4 4"/></svg>';
    var ICON_CLOSE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';

    var sheet = null, sheetTitle = null, sheetBody = null, current = null, pending = null;

    function build() {
        if (sheet) return;
        sheet = document.createElement('div');
        sheet.className = 'prj-msheet prj-move-sheet';
        sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('aria-modal', 'true');
        var head = document.createElement('div');
        head.className = 'prj-msheet-head';
        sheetTitle = document.createElement('span');
        sheetTitle.className = 'prj-msheet-title';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'prj-msheet-close';
        close.innerHTML = ICON_CLOSE;
        close.setAttribute('aria-label', tr('common.close', 'Close'));
        close.addEventListener('click', function () { setOpen(false); });
        head.appendChild(sheetTitle);
        head.appendChild(close);
        sheetBody = document.createElement('div');
        sheetBody.className = 'prj-msheet-body';
        sheet.appendChild(head);
        sheet.appendChild(sheetBody);
        document.body.appendChild(sheet);
        // A tap on the dimmed plan above the choices closes the sheet.
        sheet.addEventListener('click', function (e) { if (e.target === sheet) setOpen(false); });
        sheetBody.addEventListener('click', function (e) {
            var opt = e.target.closest ? e.target.closest('.prj-move-opt') : null;
            if (!opt || opt.getAttribute('aria-current') === 'true') return;
            moveTo(opt.getAttribute('data-lane'));
        });
    }

    function setOpen(open) {
        if (!sheet) return;
        sheet.classList.toggle('open', !!open);
        if (!open) current = null;
    }

    /** The plan's own word for a lane, from the first lane's kind chip. */
    function laneWord() {
        return txt(plan.querySelector('.prj-lane-kind'));
    }

    function openFor(row) {
        build();
        current = row.getAttribute('data-task');
        var here = row.closest('.prj-lane');
        sheetTitle.textContent = txt(row.querySelector('.prj-task-title'));
        var html = '';
        Array.prototype.forEach.call(plan.querySelectorAll('.prj-lane'), function (lane) {
            var id = lane.getAttribute('data-lane') || '';
            var kind = lane.querySelector('.prj-lane-kind');
            var name = txt(lane.querySelector('.prj-lane-head h3'));
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'prj-move-opt';
            b.setAttribute('data-lane', id);
            if (lane === here) b.setAttribute('aria-current', 'true');
            if (kind) {
                var k = document.createElement('span');
                k.className = 'prj-lane-kind';
                k.textContent = txt(kind);
                b.appendChild(k);
            }
            var n = document.createElement('span');
            n.textContent = name;
            b.appendChild(n);
            html += b.outerHTML;
        });
        sheetBody.innerHTML = html;
        setOpen(true);
    }

    function fire(target, type) {
        var ev = new Event(type, { bubbles: true, cancelable: true });
        var dt = { effectAllowed: 'move', dropEffect: 'move', setData: function () {}, getData: function () { return current || ''; } };
        try { Object.defineProperty(ev, 'dataTransfer', { value: dt }); } catch (err) { /* very old engines */ }
        target.dispatchEvent(ev);
    }

    function moveTo(laneId) {
        var row = current ? plan.querySelector('.prj-task[data-task="' + current + '"]') : null;
        var lane = plan.querySelector('.prj-lane[data-lane="' + laneId + '"]');
        if (!row || !lane) { setOpen(false); return; }
        pending = { task: current, lane: laneId };
        fire(row, 'dragstart');
        fire(lane.querySelector('.prj-lane-tasks') || lane, 'drop');
        fire(row, 'dragend');
        setOpen(false);
    }

    function decorate() {
        if (!mq.matches) return;
        var word = laneWord();
        // Nothing to move between until the plan has at least one lane besides
        // "not in a phase yet".
        var lanes = plan.querySelectorAll('.prj-lane').length;
        Array.prototype.forEach.call(plan.querySelectorAll('.prj-task'), function (row) {
            var btn = row.querySelector('.prj-move-btn');
            if (lanes < 2) { if (btn) btn.remove(); return; }
            if (btn) return;
            btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'prj-move-btn';
            btn.innerHTML = ICON_MOVE;
            if (word) btn.setAttribute('aria-label', word);
            var rm = row.querySelector('.prj-task-remove');
            row.insertBefore(btn, rm || null);
        });
        // A move finished: the page redrew the plan with the task in its new lane.
        if (pending) {
            var moved = plan.querySelector('.prj-lane[data-lane="' + pending.lane + '"] .prj-task[data-task="' + pending.task + '"]');
            if (moved) {
                pending = null;
                if (typeof window.showToast === 'function' && window.Prj && window.Prj.T) window.showToast(window.Prj.T('plan.moved'), 'success');
            }
        }
    }

    function strip() {
        Array.prototype.forEach.call(plan.querySelectorAll('.prj-move-btn'), function (b) { b.remove(); });
        setOpen(false);
        if (sheet) sheet.style.display = 'none';
    }

    /* The picker button lives in the row, so a tap on it must not reach the
       row's link or the page's own click handler's other branches. */
    plan.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.prj-move-btn') : null;
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        openFor(btn.closest('.prj-task'));
    }, true);

    new MutationObserver(decorate).observe(plan, { childList: true, subtree: true });

    function sync() {
        if (mq.matches) {
            if (sheet) sheet.style.display = '';
            decorate();
        } else {
            strip();
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ==========================================================================
   LAYER 44d - Projects scope: change a deliverable's priority without a drag

   On desktop a card is dragged between the MoSCoW columns. On a phone the
   board is one column per row (mobile.css 44k), so the column you want is a
   screen or more away and a drag would have to scroll the page mid-gesture
   - the same reasons as the plan's lanes (44c). So each card gets 44c's
   picker button, opening 44c's bottom sheet listing the five columns, the
   card's own one ticked and not choosable. (Tapping the card itself still
   opens its dialog, whose Priority field does the same thing with more
   taps; the picker is the one-tap move the drag is on desktop.)

   🔑 Wrap, don't edit (§1): choosing a column dispatches the events
   projects-tools.js already listens for - `dragstart` on the card,
   `dragover` on the column (its handler moves the card into the column, so
   the order it sends includes it), `drop`, `dragend` - and its own item_move
   call and redraw do the rest. Not one line of projects-tools.js changed.

   Zero new strings: the column names come from the columns' own headings
   (text nodes only, so the count badge stays behind), the sheet's title is
   the card's own title, the button's name is the dialog's own "Priority"
   label, and the toast is the plan's existing "Moved". Only cards the
   analyst may change carry draggable="true", so only they get a picker.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'projects-view') return;

    var mq = window.matchMedia('(max-width: 768px)');
    var scope = document.getElementById('pvScope');
    if (!scope || !window.MutationObserver) return;

    function tr(key, fallback) {
        if (typeof window.t !== 'function') return fallback;
        var v = window.t(key);
        return (!v || v === key) ? fallback : v;
    }
    function txt(el) { return el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : ''; }
    /** A column heading's own words - text nodes only, so the count badge
        beside them does not come along. */
    function headText(h) {
        if (!h) return '';
        var s = '';
        for (var i = 0; i < h.childNodes.length; i++) {
            if (h.childNodes[i].nodeType === 3) s += h.childNodes[i].nodeValue;
        }
        return s.replace(/\s+/g, ' ').trim();
    }
    var ICON_MOVE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16M3 16l4 4 4-4"/><path d="M17 20V4M13 8l4-4 4 4"/></svg>';
    var ICON_CLOSE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';

    var sheet = null, sheetTitle = null, sheetBody = null, current = null, pending = null;

    function build() {
        if (sheet) return;
        sheet = document.createElement('div');
        sheet.className = 'prj-msheet prj-move-sheet';
        sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('aria-modal', 'true');
        var head = document.createElement('div');
        head.className = 'prj-msheet-head';
        sheetTitle = document.createElement('span');
        sheetTitle.className = 'prj-msheet-title';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'prj-msheet-close';
        close.innerHTML = ICON_CLOSE;
        close.setAttribute('aria-label', tr('common.close', 'Close'));
        close.addEventListener('click', function () { setOpen(false); });
        head.appendChild(sheetTitle);
        head.appendChild(close);
        sheetBody = document.createElement('div');
        sheetBody.className = 'prj-msheet-body';
        sheet.appendChild(head);
        sheet.appendChild(sheetBody);
        document.body.appendChild(sheet);
        sheet.addEventListener('click', function (e) { if (e.target === sheet) setOpen(false); });
        sheetBody.addEventListener('click', function (e) {
            var opt = e.target.closest ? e.target.closest('.prj-move-opt') : null;
            if (!opt || opt.getAttribute('aria-current') === 'true') return;
            moveTo(opt.getAttribute('data-moscow'));
        });
    }

    function setOpen(open) {
        if (!sheet) return;
        sheet.classList.toggle('open', !!open);
        if (!open) current = null;
    }

    function openFor(card) {
        build();
        current = card.getAttribute('data-item');
        var here = card.closest('.prj-moscow-col');
        sheetTitle.textContent = txt(card.querySelector('.prj-item-title'));
        sheetBody.innerHTML = '';
        Array.prototype.forEach.call(scope.querySelectorAll('.prj-moscow-col'), function (col) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'prj-move-opt';
            b.setAttribute('data-moscow', col.getAttribute('data-moscow') || '');
            if (col === here) b.setAttribute('aria-current', 'true');
            var n = document.createElement('span');
            n.textContent = headText(col.querySelector('header h4'));
            b.appendChild(n);
            sheetBody.appendChild(b);
        });
        setOpen(true);
    }

    function fire(target, type) {
        var ev = new Event(type, { bubbles: true, cancelable: true });
        var dt = { effectAllowed: 'move', dropEffect: 'move', setData: function () {}, getData: function () { return current || ''; } };
        try { Object.defineProperty(ev, 'dataTransfer', { value: dt }); } catch (err) { /* very old engines */ }
        target.dispatchEvent(ev);
    }

    function moveTo(moscow) {
        var card = current ? scope.querySelector('.prj-item[data-item="' + current + '"]') : null;
        var col = scope.querySelector('.prj-moscow-col[data-moscow="' + moscow + '"]');
        var zone = col ? col.querySelector('.prj-moscow-cards') : null;
        if (!card || !zone) { setOpen(false); return; }
        pending = { item: current, moscow: moscow };
        fire(card, 'dragstart');
        fire(zone, 'dragover');     // the page moves the card into the column here
        fire(zone, 'drop');
        fire(card, 'dragend');
        setOpen(false);
    }

    function decorate() {
        if (!mq.matches) return;
        var label = txt(document.querySelector('label[for="piMoscow"]'));
        Array.prototype.forEach.call(scope.querySelectorAll('.prj-item[draggable="true"]'), function (card) {
            if (card.querySelector('.prj-move-btn')) return;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'prj-move-btn';
            btn.innerHTML = ICON_MOVE;
            if (label) btn.setAttribute('aria-label', label);
            card.appendChild(btn);
        });
        // A move finished: the page redrew the board with the card in its new column.
        if (pending) {
            var moved = scope.querySelector('.prj-moscow-col[data-moscow="' + pending.moscow + '"] .prj-item[data-item="' + pending.item + '"]');
            if (moved && moved.querySelector('.prj-move-btn')) {
                pending = null;
                if (typeof window.showToast === 'function' && window.Prj && window.Prj.T) window.showToast(window.Prj.T('plan.moved'), 'success');
            }
        }
    }

    function strip() {
        Array.prototype.forEach.call(scope.querySelectorAll('.prj-move-btn'), function (b) { b.remove(); });
        setOpen(false);
        if (sheet) sheet.style.display = 'none';
    }

    /* The picker sits inside the card, whose own click opens the dialog -
       capture, and stop the tap there. */
    scope.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.prj-move-btn') : null;
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        openFor(btn.closest('.prj-item'));
    }, true);

    new MutationObserver(decorate).observe(scope, { childList: true, subtree: true });

    function sync() {
        if (mq.matches) {
            if (sheet) sheet.style.display = '';
            decorate();
        } else {
            strip();
        }
    }
    sync();
    if (mq.addEventListener) { mq.addEventListener('change', sync); }
    else if (mq.addListener) { mq.addListener(sync); }
})();

/* ==========================================================================
   LAYER 44e - Projects RACI: each cell's member name, for the card feed

   mobile.css 44l turns the matrix into one card per deliverable with the
   header hidden, so each cell needs to say whose it is. The names are
   already on the page, translated, in the hidden header (§21) - but the
   shared FEEDS harvester cannot be used: it reads the header's text nodes
   (these headers are all spans) and, rightly for a report, refuses to label
   an empty cell - and here the empty cell is exactly where you tap to
   assign someone. So: the `.prj-raci-name` of the matching header, stamped
   on every cell, as data-prj-member (the shared data-mobile-label is
   printed BEFORE a cell by the §21 CSS; here the name follows the button).
   renderRaci() replaces the whole table on every change, so
   the panel is watched; phone only, and removed when leaving mobile.
   ========================================================================== */
(function () {
    'use strict';

    if (!document.body || document.body.getAttribute('data-mobile-page') !== 'projects-view') return;

    var mq = window.matchMedia('(max-width: 768px)');
    var box = document.getElementById('pvRaci');
    if (!box || !window.MutationObserver) return;

    function apply() {
        var table = box.querySelector('table.prj-raci');
        if (!table || !table.tHead || !table.tHead.rows.length || !table.tBodies[0]) return;
        var heads = table.tHead.rows[0].cells;
        Array.prototype.forEach.call(table.tBodies[0].rows, function (row) {
            if (row.cells.length !== heads.length) return;
            for (var i = 1; i < row.cells.length; i++) {
                var cell = row.cells[i];
                if (!mq.matches) { cell.removeAttribute('data-prj-member'); continue; }
                var name = heads[i].querySelector('.prj-raci-name');
                var text = name ? (name.textContent || '').replace(/\s+/g, ' ').trim() : '';
                if (text && cell.getAttribute('data-prj-member') !== text) cell.setAttribute('data-prj-member', text);
            }
        });
    }

    new MutationObserver(function () { if (mq.matches) apply(); }).observe(box, { childList: true, subtree: true });
    apply();
    if (mq.addEventListener) { mq.addEventListener('change', apply); }
    else if (mq.addListener) { mq.addListener(apply); }
})();
