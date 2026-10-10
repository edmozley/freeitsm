/**
 * Files — the window manager.
 *
 * Windows that drag, resize from any edge or corner, snap (top = maximise, left
 * or right edge = half the screen), minimise to the taskbar and restore. Hover a
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
 */
(function () {
    'use strict';

    var desktop  = document.getElementById('fdDesktop');
    var tasks    = document.getElementById('fdTasks');
    var previewEl = document.getElementById('fdPreview');
    var ctxEl    = document.getElementById('fdCtx');
    var ghost    = document.getElementById('fdSnapGhost');

    var wins = [];          // every open window, in opening order (= taskbar order)
    var zTop = 10;
    var seq  = 0;
    var active = null;
    var geometryHook = null;
    var MIN_W = 280, MIN_H = 180;

    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html != null) e.innerHTML = html;
        return e;
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function L(key, english, params) { return window.tf ? window.tf('files.' + key, english, params) : english; }
    function area() { return desktop.getBoundingClientRect(); }
    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

    // ── Windows ────────────────────────────────────────────────────────────
    function Win(opts) {
        var self = this;
        this.id = 'w' + (++seq);
        this.kind = opts.kind || 'app';
        this.key = opts.key || null;
        this.title = opts.title || '';
        this.icon = opts.icon || '';
        this.minimized = false;
        this.maximized = false;
        this.onClose = opts.onClose || null;
        this.onFocus = opts.onFocus || null;
        this.onResize = opts.onResize || null;

        var a = area();
        var saved = opts.geometry || null;
        var w = clamp(saved && saved.w || opts.width || 760, MIN_W, Math.max(MIN_W, a.width - 20));
        var h = clamp(saved && saved.h || opts.height || 480, MIN_H, Math.max(MIN_H, a.height - 20));
        // Cascade new windows so they do not open exactly on top of each other.
        var n = wins.length % 8;
        var x = saved && saved.x != null && !opts.cascade ? saved.x : 120 + n * 28 + (opts.offsetX || 0);
        var y = saved && saved.y != null && !opts.cascade ? saved.y : 30 + n * 26 + (opts.offsetY || 0);
        this.geo = { x: clamp(x, 0, Math.max(0, a.width - 120)), y: clamp(y, 0, Math.max(0, a.height - 40)), w: w, h: h };

        var e = this.el = el('div', 'fd-win');
        e.setAttribute('role', 'dialog');
        e.dataset.kind = this.kind;
        e.innerHTML =
            '<div class="fd-titlebar">' +
                '<span class="fd-win-icon"></span><span class="fd-win-title"></span>' +
                '<div class="fd-win-btns">' +
                    '<button type="button" class="fd-wb fd-wb-min" title="' + esc(L('wm.minimise', 'Minimise')) + '"><svg viewBox="0 0 10 10"><path d="M1 5.5h8"/></svg></button>' +
                    '<button type="button" class="fd-wb fd-wb-max" title="' + esc(L('wm.maximise', 'Maximise')) + '"><svg viewBox="0 0 10 10"><rect x="1.5" y="1.5" width="7" height="7"/></svg></button>' +
                    '<button type="button" class="fd-wb fd-wb-close" title="' + esc(L('wm.close', 'Close')) + '"><svg viewBox="0 0 10 10"><path d="M1.5 1.5l7 7M8.5 1.5l-7 7"/></svg></button>' +
                '</div>' +
            '</div>' +
            '<div class="fd-win-body"></div>' +
            ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw'].map(function (d) { return '<div class="fd-rs fd-rs-' + d + '" data-dir="' + d + '"></div>'; }).join('');
        this.body = e.querySelector('.fd-win-body');
        this.setTitle(this.title);
        this.setIcon(this.icon);
        this.apply();
        desktop.appendChild(e);

        // Taskbar button.
        var b = this.btn = el('button', 'fd-task');
        b.type = 'button';
        b.innerHTML = '<span class="fd-task-icon"></span><span class="fd-task-title"></span>';
        b.querySelector('.fd-task-icon').innerHTML = this.icon;
        b.querySelector('.fd-task-title').textContent = this.title;
        b.addEventListener('click', function () {
            hidePreview();
            if (self.minimized) self.restore();
            else if (active === self) self.minimize();
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

        e.addEventListener('pointerdown', function () { self.focus(); }, true);
        e.querySelector('.fd-wb-min').addEventListener('click', function (ev) { ev.stopPropagation(); self.minimize(); });
        e.querySelector('.fd-wb-max').addEventListener('click', function (ev) { ev.stopPropagation(); self.toggleMax(); });
        e.querySelector('.fd-wb-close').addEventListener('click', function (ev) { ev.stopPropagation(); self.close(); });
        var tb = e.querySelector('.fd-titlebar');
        tb.addEventListener('dblclick', function (ev) { if (!ev.target.closest('.fd-wb')) self.toggleMax(); });
        tb.addEventListener('pointerdown', function (ev) { startDrag(self, ev); });
        e.querySelectorAll('.fd-rs').forEach(function (h) {
            h.addEventListener('pointerdown', function (ev) { startResize(self, ev, h.dataset.dir); });
        });

        wins.push(this);
        if (saved && saved.max) this.maximize(true);
        this.focus();
        e.animate && e.animate([{ opacity: 0, transform: 'scale(.96)' }, { opacity: 1, transform: 'scale(1)' }], { duration: 140, easing: 'ease-out' });
    }

    Win.prototype.apply = function () {
        var s = this.el.style;
        s.left = this.geo.x + 'px';
        s.top = this.geo.y + 'px';
        s.width = this.geo.w + 'px';
        s.height = this.geo.h + 'px';
    };
    Win.prototype.setTitle = function (t) {
        this.title = t;
        this.el.querySelector('.fd-win-title').textContent = t;
        this.el.setAttribute('aria-label', t);
        if (this.btn) { this.btn.querySelector('.fd-task-title').textContent = t; this.btn.title = t; }
    };
    Win.prototype.setIcon = function (html) {
        this.icon = html;
        this.el.querySelector('.fd-win-icon').innerHTML = html;
        if (this.btn) this.btn.querySelector('.fd-task-icon').innerHTML = html;
    };
    Win.prototype.focus = function () {
        if (this.minimized) { this.restore(); return; }
        if (active && active !== this) { active.el.classList.remove('fd-active'); active.btn.classList.remove('fd-active'); }
        active = this;
        this.el.style.zIndex = ++zTop;
        this.el.classList.add('fd-active');
        this.btn.classList.add('fd-active');
        if (this.onFocus) this.onFocus(this);
    };
    Win.prototype.blur = function () {
        this.el.classList.remove('fd-active');
        this.btn.classList.remove('fd-active');
        if (active === this) active = null;
    };
    Win.prototype.minimize = function () {
        if (this.minimized) return;
        var self = this, e = this.el;
        this.minimized = true;
        this.btn.classList.add('fd-min');
        this.blur();
        var to = this.btn.getBoundingClientRect(), from = e.getBoundingClientRect();
        var done = function () { e.style.display = 'none'; focusTopmost(); };
        if (e.animate) {
            var dx = (to.left + to.width / 2) - (from.left + from.width / 2);
            var dy = (to.top + to.height / 2) - (from.top + from.height / 2);
            e.animate([{ transform: 'none', opacity: 1 },
                       { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.15)', opacity: 0 }],
                      { duration: 200, easing: 'ease-in' }).onfinish = done;
        } else done();
    };
    Win.prototype.restore = function () {
        if (!this.minimized) { this.focus(); return; }
        var e = this.el;
        this.minimized = false;
        this.btn.classList.remove('fd-min');
        e.style.display = '';
        var to = e.getBoundingClientRect(), from = this.btn.getBoundingClientRect();
        if (e.animate) {
            var dx = (from.left + from.width / 2) - (to.left + to.width / 2);
            var dy = (from.top + from.height / 2) - (to.top + to.height / 2);
            e.animate([{ transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.15)', opacity: 0 },
                       { transform: 'none', opacity: 1 }], { duration: 180, easing: 'ease-out' });
        }
        this.focus();
    };
    Win.prototype.maximize = function (silent) {
        if (this.maximized) return;
        this.maximized = true;
        this.el.classList.add('fd-max');
        this.el.querySelector('.fd-wb-max').title = L('wm.restore_down', 'Restore down');
        if (!silent) saveGeo(this);
        if (this.onResize) this.onResize(this);
    };
    Win.prototype.unmaximize = function () {
        if (!this.maximized) return;
        this.maximized = false;
        this.el.classList.remove('fd-max');
        this.el.querySelector('.fd-wb-max').title = L('wm.maximise', 'Maximise');
        this.apply();
        saveGeo(this);
        if (this.onResize) this.onResize(this);
    };
    Win.prototype.toggleMax = function () { this.maximized ? this.unmaximize() : this.maximize(); };
    Win.prototype.close = function () {
        if (this.onClose && this.onClose(this) === false) return;
        var self = this;
        hidePreview();
        var i = wins.indexOf(this);
        if (i >= 0) wins.splice(i, 1);
        this.btn.remove();
        if (active === this) active = null;
        var e = this.el;
        if (e.animate && e.style.display !== 'none') {
            e.animate([{ opacity: 1, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(.96)' }], { duration: 110 }).onfinish = function () { e.remove(); };
        } else e.remove();
        focusTopmost();
        self.closed = true;
    };

    function focusTopmost() {
        var best = null, bz = -1;
        wins.forEach(function (w) {
            if (w.minimized) return;
            var z = +w.el.style.zIndex || 0;
            if (z > bz) { bz = z; best = w; }
        });
        if (best) best.focus();
    }

    function saveGeo(w) {
        if (geometryHook) geometryHook(w.kind, { x: w.geo.x, y: w.geo.y, w: w.geo.w, h: w.geo.h, max: w.maximized });
    }

    // ── Drag, snap and resize ──────────────────────────────────────────────
    function snapZone(ev) {
        var a = area();
        if (ev.clientY <= a.top + 2) return 'max';
        if (ev.clientX <= a.left + 2) return 'left';
        if (ev.clientX >= a.right - 3) return 'right';
        return null;
    }
    function showGhost(zone) {
        if (!zone) { ghost.hidden = true; return; }
        var a = area();
        var g = zone === 'max' ? [0, 0, a.width, a.height]
              : zone === 'left' ? [0, 0, a.width / 2, a.height]
              : [a.width / 2, 0, a.width / 2, a.height];
        ghost.style.left = g[0] + 'px'; ghost.style.top = g[1] + 'px';
        ghost.style.width = g[2] + 'px'; ghost.style.height = g[3] + 'px';
        ghost.style.zIndex = zTop + 1;
        ghost.hidden = false;
    }

    function startDrag(w, ev) {
        if (ev.button !== 0 || ev.target.closest('.fd-wb')) return;
        ev.preventDefault();
        var a = area();
        var startX = ev.clientX, startY = ev.clientY;
        var moved = false, zone = null;
        var ox = w.geo.x, oy = w.geo.y;
        var tb = ev.currentTarget;
        tb.setPointerCapture(ev.pointerId);

        function move(e) {
            // TRAP: a button released where we never hear about it (over the Help
            // window's frame, outside the browser) left the drag running - and the
            // snap outline then appeared, and stayed, whenever the mouse merely
            // passed the left edge (Ed, 2026-10-10). No button held = the drag is over.
            if (e.buttons === 0) { up(e); return; }
            var dx = e.clientX - startX, dy = e.clientY - startY;
            if (!moved && Math.abs(dx) + Math.abs(dy) < 4) return;
            if (!moved) {
                moved = true;
                document.body.classList.add('fd-dragging');
                // Dragging a maximised or snapped window: it returns to its normal
                // size under the pointer, as Windows does.
                if (w.maximized || w.snapped) {
                    var ratio = (startX - a.left - (w.maximized ? 0 : w.geo.x)) / (w.maximized ? a.width : w.geo.w);
                    if (w.snapped) { w.geo.w = w.snapped.w; w.geo.h = w.snapped.h; w.snapped = null; }
                    if (w.maximized) { w.maximized = false; w.el.classList.remove('fd-max'); }
                    ox = startX - a.left - ratio * w.geo.w;
                    oy = 0;
                    w.apply();
                }
            }
            w.geo.x = clamp(ox + dx, -w.geo.w + 100, a.width - 100);
            w.geo.y = clamp(oy + dy, 0, a.height - 32);
            w.apply();
            zone = snapZone(e);
            showGhost(zone);
        }
        function up(e) {
            tb.removeEventListener('pointermove', move);
            tb.removeEventListener('pointerup', up);
            tb.removeEventListener('pointercancel', up);
            tb.removeEventListener('lostpointercapture', up);
            window.removeEventListener('blur', up);
            document.body.classList.remove('fd-dragging');
            ghost.hidden = true;
            if (ended) return;
            ended = true;
            if (!moved) return;
            if (zone === 'max') { w.maximize(); return; }
            if (zone === 'left' || zone === 'right') {
                w.snapped = { w: w.geo.w, h: w.geo.h };
                w.geo = { x: zone === 'left' ? 0 : Math.floor(a.width / 2), y: 0, w: Math.floor(a.width / 2), h: Math.floor(a.height) };
                w.apply();
                if (w.onResize) w.onResize(w);
                return;
            }
            saveGeo(w);
        }
        var ended = false;
        tb.addEventListener('pointermove', move);
        tb.addEventListener('pointerup', up);
        tb.addEventListener('pointercancel', up);
        tb.addEventListener('lostpointercapture', up);
        window.addEventListener('blur', up);
    }
    // Belt and braces: whatever happened, a click anywhere puts the outline away.
    document.addEventListener('pointerdown', function () { if (!document.body.classList.contains('fd-dragging')) ghost.hidden = true; }, true);

    function startResize(w, ev, dir) {
        if (ev.button !== 0 || w.maximized) return;
        ev.preventDefault();
        ev.stopPropagation();
        var h = ev.currentTarget;
        h.setPointerCapture(ev.pointerId);
        var sx = ev.clientX, sy = ev.clientY;
        var g0 = { x: w.geo.x, y: w.geo.y, w: w.geo.w, h: w.geo.h };
        var a = area();
        document.body.classList.add('fd-dragging');
        function move(e) {
            if (e.buttons === 0) { up(); return; }
            var dx = e.clientX - sx, dy = e.clientY - sy, g = { x: g0.x, y: g0.y, w: g0.w, h: g0.h };
            if (dir.indexOf('e') >= 0) g.w = clamp(g0.w + dx, MIN_W, a.width - g0.x);
            if (dir.indexOf('s') >= 0) g.h = clamp(g0.h + dy, MIN_H, a.height - g0.y);
            if (dir.indexOf('w') >= 0) { g.w = clamp(g0.w - dx, MIN_W, g0.x + g0.w); g.x = g0.x + g0.w - g.w; }
            if (dir.indexOf('n') >= 0) { g.h = clamp(g0.h - dy, MIN_H, g0.y + g0.h); g.y = g0.y + g0.h - g.h; }
            w.geo = g;
            w.snapped = null;
            w.apply();
            if (w.onResize) w.onResize(w);
        }
        var done = false;
        function up() {
            h.removeEventListener('pointermove', move);
            h.removeEventListener('pointerup', up);
            h.removeEventListener('pointercancel', up);
            h.removeEventListener('lostpointercapture', up);
            document.body.classList.remove('fd-dragging');
            if (done) return;
            done = true;
            saveGeo(w);
        }
        h.addEventListener('pointermove', move);
        h.addEventListener('pointerup', up);
        h.addEventListener('pointercancel', up);
        h.addEventListener('lostpointercapture', up);
    }

    // Keep windows reachable when the browser window shrinks.
    window.addEventListener('resize', function () {
        var a = area();
        wins.forEach(function (w) {
            if (w.maximized) { if (w.onResize) w.onResize(w); return; }
            w.geo.w = Math.min(w.geo.w, Math.max(MIN_W, a.width));
            w.geo.h = Math.min(w.geo.h, Math.max(MIN_H, a.height));
            w.geo.x = clamp(w.geo.x, -w.geo.w + 100, Math.max(0, a.width - 100));
            w.geo.y = clamp(w.geo.y, 0, Math.max(0, a.height - 32));
            w.apply();
        });
    });

    // ── Taskbar previews ───────────────────────────────────────────────────
    var pvTimer = null, pvHideTimer = null, pvFor = null;
    function schedulePreview(w) {
        clearTimeout(pvHideTimer);
        clearTimeout(pvTimer);
        pvTimer = setTimeout(function () { showPreview(w); }, pvFor ? 60 : 380);
    }
    function schedulePreviewHide() {
        clearTimeout(pvTimer);
        pvHideTimer = setTimeout(hidePreview, 260);
    }
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
        var first = ctxEl.querySelector('.fd-mi:not([disabled])');
        if (first) first.focus({ preventScroll: true });
    }
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
    function notify(text, kind) {
        if (!notifyBox) { notifyBox = el('div', 'fd-notify-stack'); document.body.appendChild(notifyBox); }
        var n = el('div', 'fd-notify fd-notify-' + (kind || 'info'));
        n.textContent = text;
        notifyBox.appendChild(n);
        setTimeout(function () { n.classList.add('fd-out'); setTimeout(function () { n.remove(); }, 300); }, kind === 'error' ? 6000 : 3200);
    }

    // ── Clock ──────────────────────────────────────────────────────────────
    var clock = document.getElementById('fdClock');
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
    function layoutTop() {
        var mode = document.body.dataset.navbar;
        document.documentElement.style.setProperty('--fd-top', mode === 'on' ? navwrap.offsetHeight + 'px' : '0px');
    }
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
        open: function (opts) {
            // single: re-use (and focus) an existing window with the same kind+key.
            if (opts.single) {
                for (var i = 0; i < wins.length; i++) {
                    if (wins[i].kind === opts.kind && wins[i].key === (opts.key || null)) { wins[i].restore(); wins[i].focus(); return wins[i]; }
                }
            }
            return new Win(opts);
        },
        windows: function () { return wins.slice(); },
        active: function () { return active; },
        menu: menu,
        closeMenus: closeMenus,
        dialog: dialog,
        notify: notify,
        setNavbar: setNavbar,
        onGeometry: function (fn) { geometryHook = fn; },
        hidePreview: hidePreview,
        esc: esc,
        L: L
    };
})();
