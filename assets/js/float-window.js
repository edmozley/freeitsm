/**
 * Floating windows - the window itself, for any page.
 *
 * A window drags by its title bar, resizes from any edge or corner, snaps (top =
 * maximise, left or right edge = half the area), maximises on a double-click and
 * comes to the front when touched. It lives inside an AREA element and never
 * leaves it.
 *
 * Two users:
 *   - Files (files-wm.js) - a manager over its desktop, with hooks that add the
 *     taskbar, minimise and the taskbar previews. Everything Files-only stays there.
 *   - Any other page - FloatWin.page() is a manager over a transparent layer that
 *     covers the viewport. No taskbar, so no minimise button; Escape closes the
 *     window in front. Tickets opens note images this way (GH #226).
 *
 * The class names stay fd-* because Files had them first; the window's CSS is in
 * assets/css/float-window.css, which both pages load.
 *
 * TRAP: a window closed by Escape must be the one IN FRONT - Escape on a page with
 * three windows open closes one, not all three, and never the ticket's own modals
 * (those stop the key themselves; this listens in the bubble phase).
 *
 * @wiki Files-Developer-Guide
 */
(function () {
    'use strict';

    /** Creates an element with an optional class and inner HTML. */
    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html != null) e.innerHTML = html;
        return e;
    }
    /** Escapes a value for HTML, treating null as an empty string. */
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    /** Keeps a number between lo and hi. */
    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

    /**
     * A window manager over one area.
     *
     * cfg = {
     *   area:      the element windows live in (required)
     *   ghost:     the snap outline element (made if not given)
     *   minimise:  true to show a minimise button (needs taskRect)
     *   L(key, english): translator for the window buttons ('wm.*' keys)
     *   taskRect(w):     where a minimised window shrinks to (its taskbar button)
     *   onCreate(w) / onFocus(w) / onBlur(w) / onClose(w) / onMinimise(w) / onRestore(w)
     *   onGeometry(kind, geo): a window was moved, resized or maximised
     * }
     */
    function manager(cfg) {
        var area_ = cfg.area;
        var L = cfg.L || function (k, english) { return english; };
        var ghost = cfg.ghost;
        if (!ghost) { ghost = el('div', 'fd-snap-ghost'); ghost.hidden = true; area_.appendChild(ghost); }
        var wins = [];          // every open window, in opening order (= taskbar order)
        var zTop = 10;
        var seq  = 0;
        var active = null;
        var MIN_W = 280, MIN_H = 180;

        /** Returns the area's rectangle - the space windows live in. */
        function area() { return area_.getBoundingClientRect(); }
        /** Calls a cfg hook when it is set. */
        function hook(name, w) { if (cfg[name]) cfg[name](w); }

        // ── Windows ────────────────────────────────────────────────────────
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
            var x = saved && saved.x != null && !opts.cascade ? saved.x : (opts.x != null ? opts.x : 120) + n * 28 + (opts.offsetX || 0);
            var y = saved && saved.y != null && !opts.cascade ? saved.y : (opts.y != null ? opts.y : 30) + n * 26 + (opts.offsetY || 0);
            this.geo = { x: clamp(x, 0, Math.max(0, a.width - 120)), y: clamp(y, 0, Math.max(0, a.height - 40)), w: w, h: h };

            var e = this.el = el('div', 'fd-win');
            e.setAttribute('role', 'dialog');
            e.dataset.kind = this.kind;
            e.innerHTML =
                '<div class="fd-titlebar">' +
                    '<span class="fd-win-icon"></span><span class="fd-win-title"></span>' +
                    '<div class="fd-win-btns">' +
                        (cfg.minimise ? '<button type="button" class="fd-wb fd-wb-min" title="' + esc(L('wm.minimise', 'Minimise')) + '"><svg viewBox="0 0 10 10"><path d="M1 5.5h8"/></svg></button>' : '') +
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
            area_.appendChild(e);
            hook('onCreate', this);

            e.addEventListener('pointerdown', function () { self.focus(); }, true);
            if (cfg.minimise) e.querySelector('.fd-wb-min').addEventListener('click', function (ev) { ev.stopPropagation(); self.minimize(); });
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
            if (active && active !== this) { active.el.classList.remove('fd-active'); hook('onBlur', active); }
            active = this;
            this.el.style.zIndex = ++zTop;
            this.el.classList.add('fd-active');
            hook('onFocus', this);
            if (this.onFocus) this.onFocus(this);
        };
        Win.prototype.blur = function () {
            this.el.classList.remove('fd-active');
            hook('onBlur', this);
            if (active === this) active = null;
        };
        Win.prototype.minimize = function () {
            if (this.minimized || !cfg.minimise) return;
            var e = this.el;
            this.minimized = true;
            hook('onMinimise', this);
            this.blur();
            var to = cfg.taskRect(this), from = e.getBoundingClientRect();
            /** Hides the minimised window once its shrink animation ends and focuses the next one up. */
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
            hook('onRestore', this);
            e.style.display = '';
            var to = e.getBoundingClientRect(), from = cfg.taskRect(this);
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
            var i = wins.indexOf(this);
            if (i >= 0) wins.splice(i, 1);
            hook('onClose', this);
            if (active === this) active = null;
            var e = this.el;
            if (e.animate && e.style.display !== 'none') {
                e.animate([{ opacity: 1, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(.96)' }], { duration: 110 }).onfinish = function () { e.remove(); };
            } else e.remove();
            focusTopmost();
            this.closed = true;
        };

        /** Focuses the highest window that is not minimised, if there is one. */
        function focusTopmost() {
            var best = null, bz = -1;
            wins.forEach(function (w) {
                if (w.minimized) return;
                var z = +w.el.style.zIndex || 0;
                if (z > bz) { bz = z; best = w; }
            });
            if (best) best.focus();
        }

        /**
         * Hands a window's position, size and maximised state to cfg.onGeometry,
         * to be remembered per kind.
         */
        function saveGeo(w) {
            if (cfg.onGeometry) cfg.onGeometry(w.kind, { x: w.geo.x, y: w.geo.y, w: w.geo.w, h: w.geo.h, max: w.maximized });
        }

        // ── Drag, snap and resize ──────────────────────────────────────────
        function snapZone(ev) {
            var a = area();
            if (ev.clientY <= a.top + 2) return 'max';
            if (ev.clientX <= a.left + 2) return 'left';
            if (ev.clientX >= a.right - 3) return 'right';
            return null;
        }
        /** Shows the snap outline for 'max', 'left' or 'right', or hides it when there is no zone. */
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

        /**
         * Starts moving a window by its title bar, with snapping to the top (maximise) or a side (half screen).
         *
         * A maximised or snapped window goes back to its normal size under the pointer once it moves.
         */
        function startDrag(w, ev) {
            if (ev.button !== 0 || ev.target.closest('.fd-wb')) return;
            ev.preventDefault();
            var a = area();
            var startX = ev.clientX, startY = ev.clientY;
            var moved = false, zone = null;
            var ox = w.geo.x, oy = w.geo.y;
            var tb = ev.currentTarget;
            tb.setPointerCapture(ev.pointerId);

            /**
             * Moves the window with the pointer (after a 4px dead zone) and shows the snap outline for the edge it is at.
             */
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
            /** Ends the drag once - snaps or maximises if it ended in a zone, otherwise saves the new position. */
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

        /** Starts resizing a window from an edge or corner handle (not while maximised), keeping the minimum size. */
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
            /** Resizes the window from the grabbed edge or corner as the pointer moves, and clears any snap. */
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
            /** Ends the resize once and saves the window's new geometry. */
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

        return {
            /**
             * Opens a new window, or with single: true restores and focuses an existing one of the same kind and key.
             */
            open: function (opts) {
                if (opts.single) {
                    for (var i = 0; i < wins.length; i++) {
                        if (wins[i].kind === opts.kind && wins[i].key === (opts.key || null)) { wins[i].restore(); wins[i].focus(); return wins[i]; }
                    }
                }
                return new Win(opts);
            },
            /** Returns a copy of the list of open windows, in opening order. */
            windows: function () { return wins.slice(); },
            /** Returns the focused window, or null. */
            active: function () { return active; },
            /** Returns the area's rectangle. */
            area: area
        };
    }

    // ── A page's own windows ───────────────────────────────────────────────
    var pageMgr = null;
    /**
     * The manager for an ordinary page: a transparent layer over the viewport that
     * only catches the pointer where a window is. Made on first use.
     */
    function page() {
        if (pageMgr) return pageMgr;
        var layer = el('div', 'fw-layer');
        document.body.appendChild(layer);
        pageMgr = manager({
            area: layer,
            L: function (key, english) { return window.tf ? window.tf('files.' + key, english) : english; }
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape' || ev.defaultPrevented) return;
            var w = pageMgr.active();
            if (!w) return;
            // Typing somewhere else on the page (a reply, a search box): that Escape is theirs.
            var f = document.activeElement;
            if (f && !layer.contains(f) && (f.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(f.tagName))) return;
            ev.preventDefault();
            w.close();
        });
        return pageMgr;
    }

    window.FloatWin = { manager: manager, page: page, esc: esc, el: el, clamp: clamp };
})();
