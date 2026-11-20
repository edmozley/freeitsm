/**
 * The Tickets inbox's Folders column, collapsible (discussion #292).
 *
 * Preferences -> Left panels -> Tickets sets how it starts, read server-side
 * (ticketFoldersMode() in includes/ticket_list.php) onto .main-container:
 *   always  shown, as it always was (the default)
 *   click   a thin strip; click it - or press [ - and the folders open OVER the list
 *   hover   a thin strip that opens while the pointer is on it
 *
 * For this visit only, [ and the « / » buttons switch it: shown -> a strip, and a
 * strip -> open or closed. Picking a folder, Esc or a click elsewhere closes an
 * open strip. Desktop only: every rule is in a min-width media query in inbox.css,
 * so on a phone the classes do nothing and [ is ignored.
 *
 * GH #413 (5.8.0): "Always" means "always, when there's room". Between the phone
 * layout and 1100px the three columns left the reading pane 120-420px wide, so with
 * the preference on Always the folders start as a strip there and come back when the
 * window is wider. [ and the buttons still switch it for this visit; crossing 1100px
 * again hands it back to the width.
 */
(function () {
    'use strict';
    var mc = document.querySelector('.main-container[data-folders-mode]');
    var pane = document.getElementById('folderContainer');
    if (!mc || !pane) return;

    var pref = mc.getAttribute('data-folders-mode') || 'always';
    var mode = pref;                       // how it behaves right now
    var desktop = window.matchMedia('(min-width: 769px)');
    // TRAP: keep 1100px in step with the list's shrink rule in inbox.css (GH #413).
    var narrow = window.matchMedia('(min-width: 769px) and (max-width: 1100px)');
    if (pref === 'always' && narrow.matches) mode = 'click';

    function apply() {
        mc.classList.toggle('folders-collapsed', mode !== 'always');
        mc.classList.toggle('folders-click', mode === 'click');
        mc.classList.toggle('folders-hover', mode === 'hover');
        if (mode === 'always') mc.classList.remove('folders-open');
    }
    function isOpen() { return mc.classList.contains('folders-open'); }
    function setOpen(open) {
        mc.classList.toggle('folders-open', !!open);
        if (open) {
            // The first folder gets focus, so the keyboard can carry on from [.
            var first = pane.querySelector('.folder-item, .subfolder-item, button.folder-group-btn');
            if (first && typeof first.focus === 'function' && first.tabIndex >= 0) first.focus({ preventScroll: true });
        }
    }

    /** [ and the buttons: shown <-> strip when the preference is Always, else open <-> closed. */
    function toggle() {
        if (mode === 'always') { mode = 'click'; apply(); return; }
        if (pref === 'always') { mode = 'always'; apply(); return; }
        setOpen(!isOpen());
    }

    document.getElementById('folderStripBtn').addEventListener('click', function () {
        if (pref === 'always') { mode = 'always'; apply(); } else setOpen(true);
    });
    document.getElementById('folderCollapseBtn').addEventListener('click', function () {
        if (mode === 'always') { mode = 'click'; apply(); } else setOpen(false);
    });

    // Picking a folder closes an open strip: the list is what you wanted to see.
    var list = document.getElementById('folderList');
    if (list) list.addEventListener('click', function (e) {
        if (mode === 'always' || !isOpen()) return;
        if (e.target.closest('.folder-item, .subfolder-item')) setTimeout(function () { setOpen(false); }, 0);
    });
    document.addEventListener('mousedown', function (e) {
        if (isOpen() && !pane.contains(e.target)) setOpen(false);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) { setOpen(false); return; }
        if (e.key !== '[' || e.ctrlKey || e.metaKey || e.altKey || !desktop.matches) return;
        // Never while typing, nor over a dialog.
        var t = e.target;
        if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
        if (document.querySelector('.modal.active')) return;
        if (document.body.classList.contains('ticket-popout')) return;
        e.preventDefault();
        toggle();
    });

    // Only an Always preference follows the width; Click and Hover are a strip anyway.
    function onWidth() {
        if (pref !== 'always') return;
        mc.classList.remove('folders-open');
        mode = narrow.matches ? 'click' : 'always';
        apply();
    }
    if (narrow.addEventListener) narrow.addEventListener('change', onWidth);
    else if (narrow.addListener) narrow.addListener(onWidth);   // Safari before 14

    apply();
})();
