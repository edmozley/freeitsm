/**
 * Reopen the last section of each module (GH #376).
 *
 * Only loaded when the analyst has switched on System -> Preferences -> "Remember
 * the last section in each module" (includes/waffle-menu.php prints
 * window.FREEITSM_SECTIONS and this script). Two halves:
 *
 *   REMEMBER - every module page marks its section with the active button in its
 *   header nav (`.header-nav a.nav-btn.active`). That button's address is stored
 *   for this analyst and this module. Help is never remembered.
 *
 *   RESTORE - a GENERAL way into a module (the waffle menu, the module sidebar, the home page's module
 *   cards, Ctrl+K) that points at the module's front page goes to the remembered
 *   section instead. Nothing else is touched: a link to a record, a bookmark, a
 *   notification, Back and Forward, and the module's own nav buttons all go where
 *   they say.
 *
 * Kept in this browser (localStorage, keyed by analyst), as a recent trail is: the
 * point is switching away and back during the day. If the remembered page has
 * gone or is no longer allowed, that page says so as it would for anyone.
 */
(function () {
    'use strict';
    var cfg = window.FREEITSM_SECTIONS;
    if (!cfg || !cfg.on || !cfg.roots) return;

    function storeKey(module) { return 'freeitsm.section.' + cfg.analyst + '.' + module; }
    function get(module) { try { return localStorage.getItem(storeKey(module)); } catch (e) { return null; } }
    function set(module, v) { try { localStorage.setItem(storeKey(module), v); } catch (e) { /* private window: not remembered */ } }

    /** An address as path + query on this site, or null for anything off-site or unreadable. */
    function local(url) {
        try {
            var u = new URL(url, window.location.href);
            return u.origin === window.location.origin ? u.pathname + u.search : null;
        } catch (e) { return null; }
    }
    /** The module's own folder, e.g. "/freeitsm/asset-management/". */
    function folder(module) {
        var root = local(cfg.base + cfg.roots[module]);
        return root ? root.split('?')[0].replace(/[^/]*$/, '') : null;
    }

    // REMEMBER
    function remember() {
        if (!cfg.module || !cfg.roots[cfg.module]) return;
        var a = document.querySelector('.header-nav a.nav-btn.active[href]');
        if (!a) return;
        var href = local(a.href);
        var dir = folder(cfg.module);
        if (!href || !dir || href.indexOf(dir) !== 0) return;          // only this module's own pages
        if (/help(\.php)?($|[/?])/i.test(href.split('?')[0].slice(dir.length))) return;   // never Help
        set(cfg.module, href);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', remember); else remember();

    /** Where a general entry to this address should go: the remembered section, or null to go as asked. */
    function target(url) {
        var p = local(url);
        if (!p) return null;
        for (var m in cfg.roots) {
            if (!Object.prototype.hasOwnProperty.call(cfg.roots, m)) continue;
            if (local(cfg.base + cfg.roots[m]) !== p) continue;
            if (m === cfg.module) return null;                            // already here: the front page, as asked
            var s = get(m), dir = folder(m);
            return (s && s !== p && dir && s.indexOf(dir) === 0) ? s : null;
        }
        return null;
    }

    window.FreeITSMSections = {
        resolve: function (url) { return target(url) || url; },
        navigate: function (url) { window.location.href = url; }   // a seam for tests/section-memory.php
    };

    // RESTORE - only the general entries. A new tab (middle click, Ctrl/Cmd) goes as asked.
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest && e.target.closest('a.waffle-module-link, a.module-card, a.app-sidebar-link');
        if (!a) return;
        var t = target(a.href);
        if (t) { e.preventDefault(); window.FreeITSMSections.navigate(t); }
    }, true);
})();
