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

    // ---- inject the sub-bar (Back / Folders), sitting above the pane area ----
    var bar = document.createElement('div');
    bar.className = 'mobile-subbar';
    bar.innerHTML =
        '<button type="button" class="msb-back" aria-label="Back">‹ Back</button>' +
        '<button type="button" class="msb-folders" aria-label="Folders">☰ Folders</button>';
    mc.parentNode.insertBefore(bar, mc);

    bar.querySelector('.msb-back').addEventListener('click', function () { history.back(); });
    bar.querySelector('.msb-folders').addEventListener('click', function () { pushPane('folders'); });

    // The bar is only part of the layout on mobile; keep it out of desktop
    // entirely (belt-and-suspenders alongside the @media-only styling).
    function syncBar() { bar.style.display = mq.matches ? 'flex' : 'none'; }
    syncBar();
    if (mq.addEventListener) { mq.addEventListener('change', syncBar); }
    else if (mq.addListener) { mq.addListener(syncBar); }
})();
