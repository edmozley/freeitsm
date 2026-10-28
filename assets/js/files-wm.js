/**
 * Files — the window manager.
 *
 * The desktop's half of the windows. The window itself - drag, resize from any
 * edge or corner, snap, maximise - is assets/js/float-window.js, shared with
 * other pages; this file adds minimising to the taskbar and restoring. Hover a
 * taskbar button for a live preview of the window; click the preview to bring it
 * back. Also the shared pieces every app on the desktop uses: context menus,
 * dialogs, notifications, the clock, and the navbar's on/auto-hide/off.
 *
 * Deliberately our own rather than a library (WinBox and friends): the taskbar
 * and its previews have to know everything about every window, and that
 * coupling is most of the work.
 *
 * The apps (Explorer, uploads, permissions...) live in files-desktop.js and only
 * talk to this file through window.FilesWM.
 *
 * TRAP: a minimised window is display:none, so it has no layout. The preview
 * clones it into a box sized from its remembered geometry instead of measuring
 * it - measuring a hidden element returns zeros and the preview is blank.
 *
 * @wiki Files-Developer-Guide
 */
(function () {
    'use strict';

    var desktop  = document.getElementById('fdDesktop');
    var tasks    = document.getElementById('fdTasks');
    var previewEl = document.getElementById('fdPreview');
    var ctxEl    = document.getElementById('fdCtx');
    var ghost    = document.getElementById('fdSnapGhost');

    var geometryHook = null;

    var el = window.FloatWin.el, esc = window.FloatWin.esc, clamp = window.FloatWin.clamp;
    /** Translates a Files string (key under 'files.'), falling back to the English when tf() is not loaded. */
    function L(key, english, params) { return window.tf ? window.tf('files.' + key, english, params) : english; }
    /** Returns the desktop's rectangle - the space windows live in. */
    function area() { return desktop.getBoundingClientRect(); }

    // ── Windows ────────────────────────────────────────────────────────────
    // The window itself - drag, snap, resize, maximise, focus - is float-window.js,
    // shared with every other page that opens one (Tickets' note images, GH #226).
    // What is added here is what only a desktop has: a taskbar button per window,
    // minimising to it, and the hover preview.
    var mgr = window.FloatWin.manager({
        area: desktop,
        ghost: ghost,
        minimise: true,
        L: L,
        /** A minimised window shrinks into, and grows back out of, its taskbar button. */
        taskRect: function (w) { return w.btn.getBoundingClientRect(); },
        /** Gives a new window its taskbar button. */
        onCreate: function (self) {
            var b = self.btn = el('button', 'fd-task');
            b.type = 'button';
            b.innerHTML = '<span class="fd-task-icon"></span><span class="fd-task-title"></span>';
            b.querySelector('.fd-task-icon').innerHTML = self.icon;
            b.querySelector('.fd-task-title').textContent = self.title;
            b.title = self.title;
            b.addEventListener('click', function () {
                hidePreview();
                if (self.minimized) self.restore();
                else if (mgr.active() === self) self.minimize();
                else self.focus();
            });
            b.addEventListener('mouseenter', function () { schedulePreview(self); });
            b.addEventListener('mouseleave', function () { schedulePreviewHide(); });
            b.addEventListener('contextmenu', function (ev) {
                ev.preventDefault();
                menu(ev.clientX, ev.clientY, [
                    { label: self.minimized ? L('wm.restore', 'Restore') : L('wm.minimise', 'Minimise'), action: function () { self.minimized ? self.restore() : self.minimize(); } },
                    { label: self.maximized ? L('wm.restore_down', 'Restore down') : L('wm.maximise', 'Maximise'), action: function () { if (self.minimized) self.restore(); self.toggleMax(); } },
                    { sep: true },
                    { label: L('wm.close_window', 'Close window'), action: function () { self.close(); } }
                ], { above: true });
            });
            tasks.appendChild(b);
        },
        onFocus: function (w) { w.btn.classList.add('fd-active'); },
        onBlur: function (w) { w.btn.classList.remove('fd-active'); },
        onMinimise: function (w) { w.btn.classList.add('fd-min'); },
        onRestore: function (w) { w.btn.classList.remove('fd-min'); },
        onClose: function (w) { hidePreview(); w.btn.remove(); },
        onGeometry: function (kind, geo) { if (geometryHook) geometryHook(kind, geo); }
    });

    // ── Taskbar previews ───────────────────────────────────────────────────
    var pvTimer = null, pvHideTimer = null, pvFor = null;
    /** Shows a window's taskbar preview after a hover delay - short when a preview is already showing. */
    function schedulePreview(w) {
        clearTimeout(pvHideTimer);
        clearTimeout(pvTimer);
        pvTimer = setTimeout(function () { showPreview(w); }, pvFor ? 60 : 380);
    }
    /** Hides the taskbar preview shortly after the pointer leaves, so it can move onto the preview. */
    function schedulePreviewHide() {
        clearTimeout(pvTimer);
        pvHideTimer = setTimeout(hidePreview, 260);
    }
    /** Hides and empties the taskbar preview at once. */
    function hidePreview() {
        clearTimeout(pvTimer);
        previewEl.hidden = true;
        previewEl.innerHTML = '';
        pvFor = null;
    }
    previewEl.addEventListener('mouseenter', function () { clearTimeout(pvHideTimer); });
    previewEl.addEventListener('mouseleave', schedulePreviewHide);

    /**
     * A live picture of the window: a deep clone, scaled down. Canvases do not
     * clone (a cloned canvas is blank), so each is redrawn from its original;
     * a video becomes a canvas holding its current frame.
     */
    function snapshot(w, boxW, boxH) {
        var a = area();
        var gw = w.maximized ? a.width : w.geo.w, gh = w.maximized ? a.height : w.geo.h;
        var scale = Math.min(boxW / gw, boxH / gh);
        var holder = el('div', 'fd-pv-holder');
        holder.style.width = Math.round(gw * scale) + 'px';
        holder.style.height = Math.round(gh * scale) + 'px';
        var c = w.el.cloneNode(true);
        c.removeAttribute('id');
        c.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
        c.classList.remove('fd-max');
        c.classList.add('fd-pv-clone');
        c.style.cssText = 'position:absolute;left:0;top:0;display:flex;width:' + gw + 'px;height:' + gh + 'px;transform:scale(' + scale + ');transform-origin:0 0;z-index:auto;';
        var oc = w.el.querySelectorAll('canvas'), cc = c.querySelectorAll('canvas');
        for (var i = 0; i < oc.length && i < cc.length; i++) {
            try { cc[i].width = oc[i].width; cc[i].height = oc[i].height; cc[i].getContext('2d').drawImage(oc[i], 0, 0); } catch (e) { /* tainted */ }
        }
        var ov = w.el.querySelectorAll('video'), cv = c.querySelectorAll('video');
        for (var j = 0; j < ov.length && j < cv.length; j++) {
            var cvs = document.createElement('canvas');
            cvs.width = ov[j].videoWidth || 320; cvs.height = ov[j].videoHeight || 180;
            cvs.style.cssText = 'width:100%;height:auto;background:#000';
            try { cvs.getContext('2d').drawImage(ov[j], 0, 0, cvs.width, cvs.height); } catch (e) { /* not ready */ }
            cv[j].replaceWith(cvs);
        }
        holder.appendChild(c);
        return holder;
    }

    /**
     * Shows the live preview of a window above its taskbar button, with a close button.
     *
     * Clicking the picture restores and focuses the window.
     */
    function showPreview(w) {
        if (w.closed) return;
        pvFor = w;
        previewEl.innerHTML = '';
        var head = el('div', 'fd-pv-head');
        head.innerHTML = '<span class="fd-pv-icon">' + w.icon + '</span><span class="fd-pv-title"></span>' +
            '<button type="button" class="fd-pv-close" title="' + esc(L('wm.close', 'Close')) + '">&times;</button>';
        head.querySelector('.fd-pv-title').textContent = w.title;
        head.querySelector('.fd-pv-close').addEventListener('click', function (ev) { ev.stopPropagation(); w.close(); });
        var thumb = el('div', 'fd-pv-thumb');
        thumb.appendChild(snapshot(w, 232, 150));
        thumb.addEventListener('click', function () { hidePreview(); w.restore(); w.focus(); });
        previewEl.appendChild(head);
        previewEl.appendChild(thumb);
        previewEl.hidden = false;
        var b = w.btn.getBoundingClientRect();
        var pw = previewEl.offsetWidth;
        previewEl.style.left = clamp(b.left + b.width / 2 - pw / 2, 6, window.innerWidth - pw - 6) + 'px';
        previewEl.style.bottom = (window.innerHeight - b.top + 8) + 'px';
    }

    // ── Context menus ──────────────────────────────────────────────────────
    /**
     * items: [{label, icon?, action?, disabled?, checked?, shortcut?, sub?: [...]}, {sep: true}]
     * opts.above: open upwards from the point (taskbar).
     */
    function menu(x, y, items, opts) {
        closeMenus();
        build(ctxEl, items);
        ctxEl.hidden = false;
        ctxEl.style.zIndex = 100000;
        var r = ctxEl.getBoundingClientRect();
        var left = Math.min(x, window.innerWidth - r.width - 4);
        var top = (opts && opts.above) ? y - r.height : Math.min(y, window.innerHeight - r.height - 4);
        ctxEl.style.left = Math.max(4, left) + 'px';
        ctxEl.style.top = Math.max(4, top) + 'px';
        // The MENU takes focus, not its first item: focusing an item looked like
        // hovering it, and on an item with a submenu (View) opened the submenu
        // before the mouse had moved (Ed, 2026-10-10). Arrow keys still start at
        // the top - see the keydown handler below.
        ctxEl.tabIndex = -1;
        ctxEl.focus({ preventScroll: true });
    }
    /**
     * Fills a menu container with items, separators and nested submenus.
     *
     * Clicking an item closes the menus and runs its action.
     */
    function build(container, items) {
        container.innerHTML = '';
        items.forEach(function (it) {
            if (!it) return;
            if (it.sep) { container.appendChild(el('div', 'fd-msep')); return; }
            var b = el('button', 'fd-mi' + (it.sub ? ' fd-has-sub' : '') + (it.checked ? ' fd-checked' : ''));
            b.type = 'button';
            b.setAttribute('role', 'menuitem');
            if (it.disabled) b.disabled = true;
            b.innerHTML = '<span class="fd-mi-icon">' + (it.icon || '') + '</span><span class="fd-mi-label"></span>' +
                '<span class="fd-mi-key">' + esc(it.shortcut || '') + '</span>';
            b.querySelector('.fd-mi-label').textContent = it.label;
            if (it.sub) {
                var sub = el('div', 'fd-submenu');
                build(sub, it.sub);
                b.appendChild(sub);
            } else if (it.action) {
                b.addEventListener('click', function (ev) { ev.stopPropagation(); closeMenus(); it.action(); });
            }
            container.appendChild(b);
        });
    }
    /** Hides and empties the context menu. */
    function closeMenus() {
        ctxEl.hidden = true;
        ctxEl.innerHTML = '';
    }
    ctxEl.addEventListener('keydown', function (ev) {
        var items = Array.prototype.slice.call(ctxEl.querySelectorAll(':scope > .fd-mi:not([disabled])'));
        var i = items.indexOf(document.activeElement);
        if (ev.key === 'ArrowDown') { ev.preventDefault(); (items[i + 1] || items[0]).focus(); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
        else if (ev.key === 'Escape') { closeMenus(); }
    });
    document.addEventListener('pointerdown', function (ev) {
        if (!ctxEl.hidden && !ctxEl.contains(ev.target)) closeMenus();
    });
    window.addEventListener('blur', closeMenus);

    // ── Dialogs ────────────────────────────────────────────────────────────
    /**
     * A small modal window. Resolves with the clicked button's value, or with
     * {button, value} when there is an input. Escape = the button marked cancel.
     *   dialog({title, message, icon?, input?: {value, selectName?: true}, buttons: [{label, value, primary?, cancel?}]})
     */
    function dialog(o) {
        return new Promise(function (resolve) {
            var overlay = el('div', 'fd-modal-overlay');
            overlay.style.zIndex = 99000;
            var box = el('div', 'fd-win fd-dialog fd-active');
            box.innerHTML = '<div class="fd-titlebar"><span class="fd-win-title"></span>' +
                '<div class="fd-win-btns"><button type="button" class="fd-wb fd-wb-close"><svg viewBox="0 0 10 10"><path d="M1.5 1.5l7 7M8.5 1.5l-7 7"/></svg></button></div></div>' +
                '<div class="fd-dialog-body">' + (o.icon ? '<div class="fd-dialog-icon">' + o.icon + '</div>' : '') +
                '<div class="fd-dialog-text"><div class="fd-dialog-msg"></div></div></div>' +
                '<div class="fd-dialog-btns"></div>';
            box.querySelector('.fd-win-title').textContent = o.title || '';
            var msg = box.querySelector('.fd-dialog-msg');
            if (o.html) msg.innerHTML = o.html; else msg.textContent = o.message || '';
            var input = null;
            if (o.input) {
                input = el('input', 'fd-input');
                input.type = 'text';
                input.value = o.input.value || '';
                box.querySelector('.fd-dialog-text').appendChild(input);
            }
            var check = null;
            if (o.check) {
                var lab = el('label', 'fd-dialog-check');
                check = el('input');
                check.type = 'checkbox';
                lab.appendChild(check);
                lab.appendChild(document.createTextNode(' ' + o.check));
                box.querySelector('.fd-dialog-text').appendChild(lab);
            }
            var btns = box.querySelector('.fd-dialog-btns');
            var cancelVal = null;
            /**
             * Closes the dialog and resolves its promise - with the button's value, or {button, value} / {button, checked}
             * when it has an input or a tick box.
             */
            function finish(v) {
                document.removeEventListener('keydown', onKey, true);
                overlay.remove();
                if (input) resolve({ button: v, value: input.value });
                else if (check) resolve({ button: v, checked: check.checked });
                else resolve(v);
            }
            (o.buttons || [{ label: L('ok', 'OK'), value: true, primary: true }]).forEach(function (bd) {
                var b = el('button', 'fd-btn' + (bd.primary ? ' fd-btn-primary' : ''));
                b.type = 'button';
                b.textContent = bd.label;
                b.addEventListener('click', function () { finish(bd.value); });
                if (bd.cancel) cancelVal = bd.value;
                btns.appendChild(b);
            });
            box.querySelector('.fd-wb-close').addEventListener('click', function () { finish(cancelVal); });
            /**
             * Escape picks the cancel button; Enter picks the primary button (from the input, when there is one).
             */
            function onKey(ev) {
                if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); finish(cancelVal); }
                else if (ev.key === 'Enter' && (!input || document.activeElement === input)) {
                    var p = (o.buttons || []).filter(function (x) { return x.primary; })[0];
                    ev.preventDefault();
                    finish(p ? p.value : true);
                }
            }
            document.addEventListener('keydown', onKey, true);
            overlay.appendChild(box);
            document.body.appendChild(overlay);
            if (input) {
                input.focus();
                // Select the name without its extension, as Windows does on rename.
                var v = input.value, dot = o.input.selectName ? v.lastIndexOf('.') : -1;
                input.setSelectionRange(0, dot > 0 ? dot : v.length);
            } else {
                var pb = btns.querySelector('.fd-btn-primary') || btns.querySelector('.fd-btn');
                if (pb) pb.focus();
            }
        });
    }

    // ── Notifications ──────────────────────────────────────────────────────
    var notifyBox = null;
    /** Shows a toast in the corner that fades away - after 6 seconds for an error, 3.2 seconds otherwise. */
    function notify(text, kind) {
        if (!notifyBox) { notifyBox = el('div', 'fd-notify-stack'); document.body.appendChild(notifyBox); }
        var n = el('div', 'fd-notify fd-notify-' + (kind || 'info'));
        n.textContent = text;
        notifyBox.appendChild(n);
        setTimeout(function () { n.classList.add('fd-out'); setTimeout(function () { n.remove(); }, 300); }, kind === 'error' ? 6000 : 3200);
    }

    // ── Clock ──────────────────────────────────────────────────────────────
    var clock = document.getElementById('fdClock');
    /** Redraws the taskbar clock (time and date) in the person's time zone. */
    function tick() {
        var now = new Date();
        var opts = window.tzOpts ? window.tzOpts() : {};
        try {
            clock.innerHTML = '<span>' + esc(now.toLocaleTimeString([], Object.assign({ hour: '2-digit', minute: '2-digit' }, opts))) + '</span>' +
                '<span>' + esc(now.toLocaleDateString([], Object.assign({ day: '2-digit', month: '2-digit', year: 'numeric' }, opts))) + '</span>';
        } catch (e) {
            clock.textContent = now.toLocaleTimeString();
        }
    }
    tick();
    setInterval(tick, 15000);

    // ── The navbar: on / auto-hide / off ───────────────────────────────────
    var navwrap = document.getElementById('fdNavwrap');
    var sensor = document.getElementById('fdNavsensor');
    var navHideTimer = null;
    /** Sets --fd-top to the navbar's height when it is always on, so the desktop starts below it; 0 otherwise. */
    function layoutTop() {
        var mode = document.body.dataset.navbar;
        document.documentElement.style.setProperty('--fd-top', mode === 'on' ? navwrap.offsetHeight + 'px' : '0px');
    }
    /** Switches the navbar to 'on', 'auto' (auto-hide) or 'off' and re-lays the desktop out. */
    function setNavbar(mode) {
        document.body.dataset.navbar = mode;
        document.body.classList.remove('fd-nav-shown');
        layoutTop();
        window.dispatchEvent(new Event('resize'));
    }
    sensor.addEventListener('mouseenter', function () {
        if (document.body.dataset.navbar !== 'auto') return;
        clearTimeout(navHideTimer);
        document.body.classList.add('fd-nav-shown');
    });
    navwrap.addEventListener('mouseenter', function () { clearTimeout(navHideTimer); });
    navwrap.addEventListener('mouseleave', function () {
        if (document.body.dataset.navbar !== 'auto') return;
        navHideTimer = setTimeout(function () {
            // Keep it while the waffle or the avatar menu is open from it.
            var open = navwrap.querySelector('.waffle-panel.open, .waffle-panel.active, .user-menu.open, .user-menu.show');
            if (!open) document.body.classList.remove('fd-nav-shown');
        }, 450);
    });
    layoutTop();
    window.addEventListener('resize', layoutTop);

    window.FilesWM = {
        /**
         * Opens a new window, or with single: true restores and focuses an existing one of the same kind and key.
         */
        open: function (opts) { return mgr.open(opts); },
        /** Returns a copy of the list of open windows, in taskbar order. */
        windows: function () { return mgr.windows(); },
        /** Returns the focused window, or null. */
        active: function () { return mgr.active(); },
        menu: menu,
        closeMenus: closeMenus,
        dialog: dialog,
        notify: notify,
        setNavbar: setNavbar,
        /**
         * Sets the function told whenever a window is moved, resized or maximised, so its geometry can be saved.
         */
        onGeometry: function (fn) { geometryHook = fn; },
        hidePreview: hidePreview,
        esc: esc,
        L: L
    };
})();
