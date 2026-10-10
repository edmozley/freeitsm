/**
 * Files — the desktop's apps: desktop icons, the start menu, Explorer windows,
 * uploads, the clipboard, Permissions, Properties, Recent, Search, Personalise,
 * Help, and the phone list view.
 *
 * Windows, menus and dialogs come from files-wm.js (window.FilesWM); icons from
 * files-icons.js. Everything the server knows comes from api/files/ - and the
 * server decides every permission. The levels this file reads only decide which
 * menu items are offered; a hidden button is a courtesy, never the protection.
 *
 * Levels (includes/files/acl.php): 1 View, 2 Download, 3 Upload, 4 Modify, 5 Full control.
 */
(function () {
    'use strict';

    var B   = window.FILES_BOOT;
    var WM  = window.FilesWM;
    var FI  = window.FilesIcons;
    var L   = WM.L;
    var esc = WM.esc;

    var LV = { VIEW: 1, DOWNLOAD: 2, UPLOAD: 3, MODIFY: 4, FULL: 5 };
    var LEVEL_NAMES = function () {
        return {
            1: L('level.view', 'View'), 2: L('level.download', 'Download'), 3: L('level.upload', 'Upload'),
            4: L('level.modify', 'Modify'), 5: L('level.full', 'Full control')
        };
    };

    // ── Small helpers ──────────────────────────────────────────────────────
    function api(path, body, method) {
        var opts = { method: method || (body !== undefined ? 'POST' : 'GET'), credentials: 'same-origin', headers: {} };
        if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        return fetch(B.api + path, opts).then(function (r) {
            return r.json().catch(function () { return { success: false, error: 'HTTP ' + r.status }; });
        }).then(function (j) {
            if (!j || !j.success) throw new Error((j && j.error) || L('err.generic', 'Something went wrong.'));
            return j;
        });
    }
    function fail(e) { WM.notify(e && e.message ? e.message : String(e), 'error'); }
    function fmtSize(n) {
        if (n == null) return '';
        if (n < 1024) return n + ' B';
        var u = ['KB', 'MB', 'GB', 'TB'], i = -1;
        do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
        return (n >= 100 ? Math.round(n) : n.toFixed(n >= 10 ? 1 : 2)) + ' ' + u[i];
    }
    function fmtWhen(s) { return s ? (window.fmtDateTime ? window.fmtDateTime(s) : s) : ''; }
    function typeName(name) {
        var t = FI.type(name);
        var names = {
            pdf: L('type.pdf', 'PDF document'), word: L('type.word', 'Word document'), excel: L('type.excel', 'Spreadsheet'),
            ppt: L('type.ppt', 'Presentation'), image: L('type.image', 'Image'), video: L('type.video', 'Video'),
            audio: L('type.audio', 'Audio'), archive: L('type.archive', 'Compressed archive'), app: L('type.app', 'Application'),
            disk: L('type.disk', 'Disk image'), text: L('type.text', 'Text document'), code: L('type.code', 'Code'),
            email: L('type.email', 'Email message'), cert: L('type.cert', 'Certificate or key')
        };
        return names[t.key] || (t.ext ? L('type.other', '{ext} file', { ext: t.ext.toUpperCase() }) : L('type.file', 'File'));
    }
    function debounce(fn, ms) {
        var t = null;
        return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); };
    }
    function h(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html != null) e.innerHTML = html;
        return e;
    }
    function svgIcon(path) {
        return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
    }
    var IC = {
        back: svgIcon('<path d="M19 12H5M12 19l-7-7 7-7"/>'),
        fwd: svgIcon('<path d="M5 12h14M12 5l7 7-7 7"/>'),
        up: svgIcon('<path d="M12 19V5M5 12l7-7 7 7"/>'),
        refresh: svgIcon('<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15"/>'),
        newFolder: svgIcon('<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/>'),
        upload: svgIcon('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>'),
        download: svgIcon('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>'),
        cut: svgIcon('<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/>'),
        copy: svgIcon('<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>'),
        paste: svgIcon('<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/>'),
        rename: svgIcon('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>'),
        del: svgIcon('<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>'),
        perms: svgIcon('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'),
        props: svgIcon('<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>'),
        icons: svgIcon('<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>'),
        details: svgIcon('<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>'),
        open: svgIcon('<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>'),
        location: svgIcon('<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>'),
        chevron: '<svg viewBox="0 0 10 10" width="10" height="10"><path d="M3 2l4 3-4 3" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>'
    };

    // ── Preferences (remembered window sizes, view, colour, navbar) ─────────
    var prefs = B.prefs;
    var windowsPref = prefs.windows && !Array.isArray(prefs.windows) ? prefs.windows : {};
    var saveWindows = debounce(function () { api('prefs.php', { windows: windowsPref }).catch(function () {}); }, 800);
    WM.onGeometry(function (kind, g) { windowsPref[kind] = g; saveWindows(); });
    function geoFor(kind) { return windowsPref[kind] || null; }

    // ── A tiny event bus: "these folders changed, refresh what shows them" ──
    var explorers = [];
    function changed(folderIds) {
        explorers.forEach(function (x) { x.onChanged(folderIds || []); });
    }

    // ── The clipboard ──────────────────────────────────────────────────────
    var clip = null;   // {mode: 'cut'|'copy', entries: [{type, id, name}], from: folderId}
    function setClip(mode, entries, from) {
        clip = entries.length ? { mode: mode, entries: entries, from: from } : null;
        explorers.forEach(function (x) { x.markCut(); });
    }
    function paste(target) {
        if (!clip) return Promise.resolve();
        var c = clip;
        return api('transfer.php', { mode: c.mode === 'cut' ? 'move' : 'copy', target: target, entries: c.entries.map(function (e) { return { type: e.type, id: e.id }; }) })
            .then(function (r) {
                if (c.mode === 'cut') setClip('cut', []);
                (r.failed || []).forEach(function (f) { WM.notify(f.error, 'error'); });
                changed([target, c.from]);
            }).catch(fail);
    }
    function transfer(mode, entries, from, target) {
        return api('transfer.php', { mode: mode, target: target, entries: entries })
            .then(function (r) { (r.failed || []).forEach(function (f) { WM.notify(f.error, 'error'); }); changed([target, from]); })
            .catch(fail);
    }

    /** Open a file in the viewer (files-viewer.js). Every open is a 'view' in the audit trail. */
    function viewFile(itemId, versionId) {
        return window.FilesViewer.open(itemId, versionId || 0, {
            geometry: geoFor('viewer'),
            phone: window.matchMedia('(max-width: 768px)').matches,
            onProperties: function (id) { openProperties('item', id); }
        }).catch(fail);
    }

    function download(itemId, versionId) {
        var a = document.createElement('a');
        a.href = B.api + 'download.php?id=' + itemId + (versionId ? '&version=' + versionId : '');
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    // ════════════════════════════════════════════════════════════════════════
    //  EXPLORER
    // ════════════════════════════════════════════════════════════════════════
    function Explorer(folderId, opts) {
        opts = opts || {};
        var self = this;
        this.folderId = folderId || 0;
        this.back = [];
        this.fwd = [];
        this.data = null;
        this.view = prefs.view || 'icons';
        this.sort = { key: 'name', dir: 1 };
        this.filter = '';
        this.sel = {};         // 'f:12' / 'i:34' -> true
        this.anchor = null;
        this.order = [];       // rendered order of keys, for shift-select and arrows
        this.selectAfterLoad = opts.select || null;

        this.win = WM.open({
            kind: 'explorer', title: L('app.documents', 'Documents'), icon: FI.folder(16),
            width: 900, height: 560, geometry: geoFor('explorer'), cascade: explorers.length > 0,
            onClose: function () { var i = explorers.indexOf(self); if (i >= 0) explorers.splice(i, 1); },
            onFocus: function () { if (self.pane && document.activeElement !== self.filterInput) self.pane.focus({ preventScroll: true }); }
        });
        var b = this.win.body;
        b.classList.add('fx');
        b.innerHTML =
            '<div class="fx-nav">' +
                '<button type="button" class="fx-navbtn" data-act="back" title="' + esc(L('ex.back', 'Back')) + '">' + IC.back + '</button>' +
                '<button type="button" class="fx-navbtn" data-act="fwd" title="' + esc(L('ex.forward', 'Forward')) + '">' + IC.fwd + '</button>' +
                '<button type="button" class="fx-navbtn" data-act="up" title="' + esc(L('ex.up', 'Up')) + '">' + IC.up + '</button>' +
                '<button type="button" class="fx-navbtn" data-act="refresh" title="' + esc(L('ex.refresh', 'Refresh')) + '">' + IC.refresh + '</button>' +
                '<div class="fx-address"></div>' +
                '<div class="fx-filter"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-5-5"/></svg>' +
                    '<input type="search" placeholder="' + esc(L('ex.filter', 'Filter this folder')) + '"></div>' +
            '</div>' +
            '<div class="fx-cmd">' +
                '<button type="button" data-act="newfolder">' + IC.newFolder + '<span>' + esc(L('ex.new_folder', 'New folder')) + '</span></button>' +
                '<button type="button" data-act="upload">' + IC.upload + '<span>' + esc(L('ex.upload', 'Upload')) + '</span></button>' +
                '<span class="fx-cmd-sep"></span>' +
                '<button type="button" data-act="cut" title="' + esc(L('ex.cut', 'Cut')) + '">' + IC.cut + '</button>' +
                '<button type="button" data-act="copy" title="' + esc(L('ex.copy', 'Copy')) + '">' + IC.copy + '</button>' +
                '<button type="button" data-act="paste" title="' + esc(L('ex.paste', 'Paste')) + '">' + IC.paste + '</button>' +
                '<button type="button" data-act="rename" title="' + esc(L('ex.rename', 'Rename')) + '">' + IC.rename + '</button>' +
                '<button type="button" data-act="delete" title="' + esc(L('ex.delete', 'Delete')) + '">' + IC.del + '</button>' +
                '<span class="fx-cmd-sep"></span>' +
                '<button type="button" data-act="download">' + IC.download + '<span>' + esc(L('ex.download', 'Download')) + '</span></button>' +
                '<button type="button" data-act="perms" title="' + esc(L('ex.permissions', 'Permissions')) + '">' + IC.perms + '</button>' +
                '<button type="button" data-act="props" title="' + esc(L('ex.properties', 'Properties')) + '">' + IC.props + '</button>' +
                '<span class="fx-cmd-grow"></span>' +
                '<button type="button" data-act="view-icons" title="' + esc(L('ex.view_icons', 'Icons')) + '">' + IC.icons + '</button>' +
                '<button type="button" data-act="view-details" title="' + esc(L('ex.view_details', 'Details')) + '">' + IC.details + '</button>' +
            '</div>' +
            '<div class="fx-main">' +
                '<div class="fx-tree" role="tree"></div>' +
                '<div class="fx-split" title=""></div>' +
                '<div class="fx-pane" tabindex="0"><div class="fx-list"></div><div class="fx-empty" hidden></div><div class="fx-marquee" hidden></div></div>' +
            '</div>' +
            '<div class="fx-status"><span class="fx-st-count"></span><span class="fx-st-sel"></span></div>';

        this.address = b.querySelector('.fx-address');
        this.filterInput = b.querySelector('.fx-filter input');
        this.treeEl = b.querySelector('.fx-tree');
        this.pane = b.querySelector('.fx-pane');
        this.list = b.querySelector('.fx-list');
        this.emptyEl = b.querySelector('.fx-empty');
        this.marquee = b.querySelector('.fx-marquee');
        this.stCount = b.querySelector('.fx-st-count');
        this.stSel = b.querySelector('.fx-st-sel');

        b.querySelector('.fx-nav').addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-act]');
            if (!btn) return;
            ({ back: self.goBack, fwd: self.goForward, up: self.goUp, refresh: self.reload })[btn.dataset.act].call(self);
        });
        b.querySelector('.fx-cmd').addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-act]');
            if (btn && !btn.disabled) self.command(btn.dataset.act);
        });
        this.filterInput.addEventListener('input', function () { self.filter = this.value.trim().toLowerCase(); self.render(); });
        this.filterInput.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { this.value = ''; self.filter = ''; self.render(); self.pane.focus(); } });

        this.bindPane();
        this.bindSplit(b.querySelector('.fx-split'));
        this.tree = new Tree(this);
        explorers.push(this);
        this.load(this.folderId, true);
    }

    Explorer.prototype.load = function (folderId, noHistory) {
        var self = this;
        if (!noHistory && folderId !== this.folderId) { this.back.push(this.folderId); this.fwd = []; }
        this.folderId = folderId;
        this.sel = {};
        this.anchor = null;
        this.filter = '';
        this.filterInput.value = '';
        this.list.classList.add('fx-loading');
        return api('list.php?folder=' + folderId).then(function (r) {
            self.data = r;
            self.list.classList.remove('fx-loading');
            var title = r.folder ? r.folder.name : L('app.documents', 'Documents');
            self.win.setTitle(title);
            self.renderAddress();
            self.render();
            // Expand the tree down to the open folder, as Explorer does.
            var opened = false;
            (r.crumbs || []).slice(0, -1).forEach(function (c) {
                if (!self.tree.open[c.id]) { self.tree.open[c.id] = true; opened = true; }
            });
            if (opened) self.tree.render();
            else self.tree.highlight(folderId);
            if (self.selectAfterLoad) {
                self.sel = {}; self.sel[self.selectAfterLoad] = true; self.anchor = self.selectAfterLoad;
                self.selectAfterLoad = null;
                self.render();
                var n = self.list.querySelector('[data-key="' + self.anchor + '"]');
                if (n) n.scrollIntoView({ block: 'nearest' });
            }
        }).catch(function (e) {
            self.list.classList.remove('fx-loading');
            fail(e);
            // The folder vanished (deleted, or access removed): fall back to the top.
            if (folderId !== 0) self.load(0, true);
        });
    };
    Explorer.prototype.reload = function () {
        var keep = this.sel, anchor = this.anchor, filter = this.filter, self = this;
        return this.load(this.folderId, true).then(function () {
            self.sel = {};
            Object.keys(keep).forEach(function (k) { if (self.findEntry(k)) self.sel[k] = true; });
            self.anchor = anchor;
            self.filter = filter;
            self.filterInput.value = filter;
            self.render();
            self.tree.refresh();
        });
    };
    Explorer.prototype.onChanged = function (ids) {
        if (ids.indexOf(this.folderId) >= 0 || ids.length === 0) this.reload();
        else this.tree.refresh();
    };
    Explorer.prototype.goBack = function () { if (this.back.length) { this.fwd.push(this.folderId); this.load(this.back.pop(), true); } };
    Explorer.prototype.goForward = function () { if (this.fwd.length) { this.back.push(this.folderId); this.load(this.fwd.pop(), true); } };
    Explorer.prototype.goUp = function () { if (this.data && this.data.folder) this.load(this.data.folder.parent || 0); };

    Explorer.prototype.renderAddress = function () {
        var self = this, a = this.address;
        a.innerHTML = '';
        var root = h('button', 'fx-crumb', FI.app('documents', 16) + '<span>' + esc(L('app.documents', 'Documents')) + '</span>');
        root.type = 'button';
        root.addEventListener('click', function () { self.load(0); });
        a.appendChild(root);
        (this.data.crumbs || []).forEach(function (c) {
            a.appendChild(h('span', 'fx-crumb-sep', IC.chevron));
            var btn = h('button', 'fx-crumb');
            btn.type = 'button';
            btn.textContent = c.name;
            btn.addEventListener('click', function () { self.load(c.id); });
            self.dropTarget(btn, function () { return c.id; });
            a.appendChild(btn);
        });
        var nav = this.win.body.querySelector('.fx-nav');
        nav.querySelector('[data-act="back"]').disabled = !this.back.length;
        nav.querySelector('[data-act="fwd"]').disabled = !this.fwd.length;
        nav.querySelector('[data-act="up"]').disabled = !this.data.folder;
    };

    /** Every entry in the folder as one list: folders first. */
    Explorer.prototype.entries = function () {
        var d = this.data, out = [];
        if (!d) return out;
        var lvl = d.folder ? d.folder.level : 0;
        d.folders.forEach(function (f) { out.push({ key: 'f:' + f.id, type: 'folder', id: f.id, name: f.name, modified: f.modified, level: f.level, path: f.path }); });
        d.items.forEach(function (i) { out.push({ key: 'i:' + i.id, type: 'item', id: i.id, name: i.name, modified: i.modified, size: i.size, level: lvl, by: i.modified_by, versions: i.versions }); });
        return out;
    };
    Explorer.prototype.findEntry = function (key) {
        var e = this.entries();
        for (var i = 0; i < e.length; i++) if (e[i].key === key) return e[i];
        return null;
    };
    Explorer.prototype.selected = function () {
        var self = this;
        return this.entries().filter(function (e) { return self.sel[e.key]; });
    };

    Explorer.prototype.render = function () {
        var self = this;
        var all = this.entries();
        var f = this.filter;
        var shown = f ? all.filter(function (e) { return e.name.toLowerCase().indexOf(f) >= 0; }) : all;
        var s = this.sort;
        shown.sort(function (a, b) {
            if (a.type !== b.type) return a.type === 'folder' ? -1 : 1;
            var va, vb;
            if (s.key === 'modified') { va = a.modified || ''; vb = b.modified || ''; }
            else if (s.key === 'size') { va = a.size || 0; vb = b.size || 0; }
            else if (s.key === 'type') { va = a.type === 'folder' ? '' : typeName(a.name); vb = b.type === 'folder' ? '' : typeName(b.name); }
            else { return s.dir * a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }); }
            return s.dir * (va < vb ? -1 : va > vb ? 1 : a.name.localeCompare(b.name, undefined, { numeric: true }));
        });
        this.order = shown.map(function (e) { return e.key; });

        var L_ = this.list;
        L_.className = 'fx-list fx-view-' + this.view;
        var html = '';
        if (this.view === 'details') {
            var arrow = function (k) { return s.key === k ? '<span class="fx-sort">' + (s.dir > 0 ? '▲' : '▼') + '</span>' : ''; };
            html += '<div class="fx-dhead">' +
                '<button type="button" data-sort="name">' + esc(L('col.name', 'Name')) + arrow('name') + '</button>' +
                '<button type="button" data-sort="modified">' + esc(L('col.modified', 'Date modified')) + arrow('modified') + '</button>' +
                '<button type="button" data-sort="type">' + esc(L('col.type', 'Type')) + arrow('type') + '</button>' +
                '<button type="button" data-sort="size">' + esc(L('col.size', 'Size')) + arrow('size') + '</button></div>';
        }
        shown.forEach(function (e) {
            var cls = 'fx-item' + (self.sel[e.key] ? ' fx-sel' : '') + (self.isCut(e) ? ' fx-cut' : '');
            var icon = e.type === 'folder' ? FI.folder(self.view === 'icons' ? 48 : 18) : FI.file(e.name, self.view === 'icons' ? 48 : 18);
            var tip = e.path ? e.path : e.name;
            if (self.view === 'icons') {
                html += '<div class="' + cls + '" data-key="' + e.key + '" draggable="true" title="' + esc(tip) + '">' +
                    '<div class="fx-ic">' + icon + '</div><div class="fx-name">' + esc(e.name) + '</div></div>';
            } else {
                html += '<div class="' + cls + '" data-key="' + e.key + '" draggable="true" title="' + esc(tip) + '">' +
                    '<div class="fx-c fx-c-name"><span class="fx-ic">' + icon + '</span><span class="fx-name">' + esc(e.name) + '</span></div>' +
                    '<div class="fx-c">' + esc(fmtWhen(e.modified)) + '</div>' +
                    '<div class="fx-c">' + esc(e.type === 'folder' ? L('type.folder', 'File folder') : typeName(e.name)) + '</div>' +
                    '<div class="fx-c fx-c-size">' + esc(e.type === 'folder' ? '' : fmtSize(e.size)) + '</div></div>';
            }
        });
        L_.innerHTML = html;
        L_.querySelectorAll('.fx-dhead [data-sort]').forEach(function (b) {
            b.addEventListener('click', function () {
                var k = b.dataset.sort;
                self.sort = { key: k, dir: self.sort.key === k ? -self.sort.dir : 1 };
                self.render();
            });
        });
        L_.querySelectorAll('.fx-item').forEach(function (n) {
            var e = self.findEntry(n.dataset.key);
            if (e && e.type === 'folder') self.dropTarget(n, function () { return e.id; });
        });

        var empty = !shown.length;
        this.emptyEl.hidden = !empty;
        if (empty) {
            this.emptyEl.textContent = f ? L('ex.no_match', 'Nothing here matches "{q}".', { q: this.filter })
                : (this.data && this.data.folder ? L('ex.empty_folder', 'This folder is empty.')
                : L('ex.empty_root', 'Nothing has been shared with you yet.'));
        }
        this.updateStatus();
        this.updateCommands();
    };

    Explorer.prototype.updateStatus = function () {
        var all = this.order.length;
        var sel = this.selected();
        this.stCount.textContent = L('ex.count', '{n} items', { n: all });
        if (sel.length) {
            var bytes = 0, files = 0;
            sel.forEach(function (e) { if (e.type === 'item') { bytes += e.size || 0; files++; } });
            this.stSel.textContent = L('ex.selected', '{n} selected', { n: sel.length }) + (files ? '  ' + fmtSize(bytes) : '');
        } else this.stSel.textContent = '';
    };

    /** What the person may do right now - decides which buttons are live. */
    Explorer.prototype.can = function () {
        var d = this.data || { can: {} };
        var sel = this.selected();
        var min = function (need) { return sel.length > 0 && sel.every(function (e) { return e.level >= need; }); };
        return {
            upload: !!(d.can.upload),
            newFolder: !!(d.can.upload),
            uploadFiles: !!(d.folder && d.can.upload),
            paste: !!(clip && d.can.upload),
            cut: min(LV.MODIFY),
            copy: min(LV.DOWNLOAD),
            rename: sel.length === 1 && sel[0].level >= LV.MODIFY,
            del: min(LV.MODIFY),
            download: sel.length > 0 && sel.every(function (e) { return e.type === 'item' && e.level >= LV.DOWNLOAD; }),
            perms: sel.length === 1 ? sel[0].type === 'folder' : (sel.length === 0 && !!d.folder),
            props: sel.length === 1 || (sel.length === 0 && !!d.folder)
        };
    };
    Explorer.prototype.updateCommands = function () {
        var c = this.can(), cmd = this.win.body.querySelector('.fx-cmd');
        var set = function (act, on) { var b = cmd.querySelector('[data-act="' + act + '"]'); if (b) b.disabled = !on; };
        set('newfolder', c.newFolder); set('upload', c.uploadFiles); set('cut', c.cut); set('copy', c.copy);
        set('paste', c.paste); set('rename', c.rename); set('delete', c.del); set('download', c.download);
        set('perms', c.perms); set('props', c.props);
        cmd.querySelector('[data-act="view-icons"]').classList.toggle('fx-on', this.view === 'icons');
        cmd.querySelector('[data-act="view-details"]').classList.toggle('fx-on', this.view === 'details');
    };

    Explorer.prototype.isCut = function (e) {
        return !!(clip && clip.mode === 'cut' && clip.entries.some(function (c) { return c.type === e.type && c.id === e.id; }));
    };
    Explorer.prototype.markCut = function () {
        var self = this;
        this.list.querySelectorAll('.fx-item').forEach(function (n) {
            var e = self.findEntry(n.dataset.key);
            n.classList.toggle('fx-cut', !!(e && self.isCut(e)));
        });
        this.updateCommands();
    };

    Explorer.prototype.setView = function (v) {
        this.view = v;
        prefs.view = v;
        api('prefs.php', { view: v }).catch(function () {});
        this.render();
    };

    Explorer.prototype.command = function (act, entry) {
        var self = this, sel = this.selected();
        var d = this.data;
        switch (act) {
            case 'newfolder': return this.newFolder();
            case 'upload': if (d.folder) Uploads.pick(d.folder.id); return;
            case 'cut': return setClip('cut', sel.map(toRef), this.folderId);
            case 'copy': return setClip('copy', sel.map(toRef), this.folderId);
            case 'paste': return paste(entry && entry.type === 'folder' ? entry.id : this.folderId);
            case 'rename': if (sel[0]) this.rename(sel[0]); return;
            case 'delete': return this.remove(sel);
            case 'download': sel.forEach(function (e, i) { if (e.type === 'item') setTimeout(function () { download(e.id); }, i * 400); }); return;
            case 'perms':
                if (sel[0] && sel[0].type === 'folder') openPermissions(sel[0].id);
                else if (d.folder) openPermissions(d.folder.id);
                return;
            case 'props':
                if (sel[0]) openProperties(sel[0].type, sel[0].id);
                else if (d.folder) openProperties('folder', d.folder.id);
                return;
            case 'view-icons': return this.setView('icons');
            case 'view-details': return this.setView('details');
        }
    };
    function toRef(e) { return { type: e.type, id: e.id, name: e.name }; }

    Explorer.prototype.open = function (e, newWindow) {
        if (e.type === 'folder') {
            if (newWindow) new Explorer(e.id);
            else this.load(e.id);
            return;
        }
        viewFile(e.id);
    };

    Explorer.prototype.newFolder = function () {
        var self = this;
        api('folder.php', { action: 'create', parent_id: this.folderId, name: L('ex.new_folder', 'New folder') })
            .then(function (r) {
                self.selectAfterLoad = 'f:' + r.id;
                return self.reload().then(function () {
                    changed([]);
                    var e = self.findEntry('f:' + r.id);
                    if (e) self.rename(e);
                });
            }).catch(fail);
    };

    /** Rename in place, as Explorer does: an edit box over the name. */
    Explorer.prototype.rename = function (e) {
        var self = this;
        var node = this.list.querySelector('[data-key="' + e.key + '"]');
        if (!node) return;
        var nameEl = node.querySelector('.fx-name');
        var input = h('textarea', 'fx-rename');
        input.value = e.name;
        input.rows = 1;
        input.spellcheck = false;
        nameEl.replaceWith(input);
        node.draggable = false;
        input.focus();
        var dot = e.type === 'item' ? e.name.lastIndexOf('.') : -1;
        input.setSelectionRange(0, dot > 0 ? dot : e.name.length);
        var done = false;
        function finish(commit) {
            if (done) return;
            done = true;
            var v = input.value.replace(/[\r\n]+/g, ' ').trim();
            if (!commit || !v || v === e.name) { self.render(); self.pane.focus(); return; }
            api(e.type === 'folder' ? 'folder.php' : 'item.php', { action: 'rename', id: e.id, name: v })
                .then(function () { self.reload(); if (e.type === 'folder') changed([]); self.pane.focus(); })
                .catch(function (err) { fail(err); self.render(); });
        }
        input.addEventListener('keydown', function (ev) {
            ev.stopPropagation();
            if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
            else if (ev.key === 'Escape') { ev.preventDefault(); finish(false); }
        });
        input.addEventListener('blur', function () { finish(true); });
        input.addEventListener('pointerdown', function (ev) { ev.stopPropagation(); });
    };

    Explorer.prototype.remove = function (sel) {
        var self = this;
        if (!sel.length) return;
        var msg = sel.length === 1
            ? (sel[0].type === 'folder'
                ? L('ex.del_folder_q', 'Delete the folder "{name}" and everything in it?', { name: sel[0].name })
                : L('ex.del_file_q', 'Delete "{name}"?', { name: sel[0].name }))
            : L('ex.del_many_q', 'Delete these {n} items?', { n: sel.length });
        WM.dialog({
            title: L('ex.delete', 'Delete'), message: msg + '\n\n' + L('ex.del_note', 'They go to the recycle bin, and the deletion is recorded in the audit trail.'),
            buttons: [{ label: L('btn.delete', 'Delete'), value: true, primary: true }, { label: L('btn.cancel', 'Cancel'), value: false, cancel: true }]
        }).then(function (ok) {
            if (!ok) return;
            var chain = Promise.resolve(), anyFolder = false;
            sel.forEach(function (e) {
                if (e.type === 'folder') anyFolder = true;
                chain = chain.then(function () {
                    return api(e.type === 'folder' ? 'folder.php' : 'item.php', { action: 'delete', id: e.id }).catch(fail);
                });
            });
            chain.then(function () { self.reload(); if (anyFolder) changed([]); });
        });
    };

    // Context menus: on an entry, or on empty space.
    Explorer.prototype.entryMenu = function (ev, e) {
        var self = this, c = this.can(), many = this.selected().length > 1;
        var items = [
            { label: L('ex.open', 'Open'), icon: IC.open, action: function () { self.open(e); }, disabled: many },
            e.type === 'folder' ? { label: L('ex.open_new', 'Open in new window'), action: function () { self.open(e, true); }, disabled: many } : null,
            e.type === 'item' ? { label: L('ex.download', 'Download'), icon: IC.download, action: function () { self.command('download'); }, disabled: !c.download } : null,
            { sep: true },
            { label: L('ex.cut', 'Cut'), icon: IC.cut, shortcut: 'Ctrl+X', action: function () { self.command('cut'); }, disabled: !c.cut },
            { label: L('ex.copy', 'Copy'), icon: IC.copy, shortcut: 'Ctrl+C', action: function () { self.command('copy'); }, disabled: !c.copy },
            e.type === 'folder' && clip ? { label: L('ex.paste_into', 'Paste into folder'), icon: IC.paste, action: function () { paste(e.id); }, disabled: e.level < LV.UPLOAD || many } : null,
            { sep: true },
            { label: L('ex.rename', 'Rename'), icon: IC.rename, shortcut: 'F2', action: function () { self.command('rename'); }, disabled: !c.rename },
            { label: L('ex.delete', 'Delete'), icon: IC.del, shortcut: 'Del', action: function () { self.command('delete'); }, disabled: !c.del },
            { sep: true },
            e.type === 'folder' ? { label: L('ex.permissions', 'Permissions'), icon: IC.perms, action: function () { openPermissions(e.id); }, disabled: many } : null,
            { label: L('ex.properties', 'Properties'), icon: IC.props, action: function () { openProperties(e.type, e.id); }, disabled: many }
        ];
        WM.menu(ev.clientX, ev.clientY, items);
    };
    Explorer.prototype.paneMenu = function (ev) {
        var self = this, c = this.can(), d = this.data;
        var sortBy = function (k) { return function () { self.sort = { key: k, dir: self.sort.key === k ? -self.sort.dir : 1 }; self.render(); }; };
        WM.menu(ev.clientX, ev.clientY, [
            { label: L('ex.view', 'View'), sub: [
                { label: L('ex.view_icons', 'Icons'), checked: this.view === 'icons', action: function () { self.setView('icons'); } },
                { label: L('ex.view_details', 'Details'), checked: this.view === 'details', action: function () { self.setView('details'); } }
            ] },
            { label: L('ex.sort_by', 'Sort by'), sub: [
                { label: L('col.name', 'Name'), checked: this.sort.key === 'name', action: sortBy('name') },
                { label: L('col.modified', 'Date modified'), checked: this.sort.key === 'modified', action: sortBy('modified') },
                { label: L('col.type', 'Type'), checked: this.sort.key === 'type', action: sortBy('type') },
                { label: L('col.size', 'Size'), checked: this.sort.key === 'size', action: sortBy('size') }
            ] },
            { label: L('ex.refresh', 'Refresh'), icon: IC.refresh, shortcut: 'F5', action: function () { self.reload(); } },
            { sep: true },
            { label: L('ex.paste', 'Paste'), icon: IC.paste, shortcut: 'Ctrl+V', action: function () { self.command('paste'); }, disabled: !c.paste },
            { sep: true },
            { label: L('ex.new_folder', 'New folder'), icon: IC.newFolder, action: function () { self.newFolder(); }, disabled: !c.newFolder },
            { label: L('ex.upload_files', 'Upload files'), icon: IC.upload, action: function () { self.command('upload'); }, disabled: !c.uploadFiles },
            d && d.folder ? { sep: true } : null,
            d && d.folder ? { label: L('ex.permissions', 'Permissions'), icon: IC.perms, action: function () { openPermissions(d.folder.id); } } : null,
            d && d.folder ? { label: L('ex.properties', 'Properties'), icon: IC.props, action: function () { openProperties('folder', d.folder.id); } } : null
        ]);
    };

    // Mouse, keyboard, drag and drop on the right-hand pane.
    Explorer.prototype.bindPane = function () {
        var self = this, pane = this.pane;

        pane.addEventListener('click', function (ev) {
            var n = ev.target.closest('.fx-item');
            if (ev.target.closest('.fx-rename')) return;
            if (!n) { if (!self.marqueeMoved) { self.sel = {}; self.paint(); } return; }
            var k = n.dataset.key;
            if (ev.shiftKey && self.anchor) {
                var a = self.order.indexOf(self.anchor), b = self.order.indexOf(k);
                if (!ev.ctrlKey && !ev.metaKey) self.sel = {};
                self.order.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (x) { self.sel[x] = true; });
            } else if (ev.ctrlKey || ev.metaKey) {
                if (self.sel[k]) delete self.sel[k]; else self.sel[k] = true;
                self.anchor = k;
            } else {
                // A second click on an already-selected lone item renames it, after a pause - as Explorer does.
                var wasOnly = self.sel[k] && Object.keys(self.sel).length === 1;
                self.sel = {}; self.sel[k] = true; self.anchor = k;
                if (wasOnly && ev.target.closest('.fx-name')) {
                    clearTimeout(self.renameTimer);
                    self.renameTimer = setTimeout(function () { var e = self.findEntry(k); if (e && e.level >= LV.MODIFY) self.rename(e); }, 600);
                }
            }
            self.paint();
        });
        pane.addEventListener('dblclick', function (ev) {
            clearTimeout(self.renameTimer);
            var n = ev.target.closest('.fx-item');
            if (!n || ev.target.closest('.fx-rename')) return;
            var e = self.findEntry(n.dataset.key);
            if (e) self.open(e);
        });
        pane.addEventListener('contextmenu', function (ev) {
            ev.preventDefault();
            var n = ev.target.closest('.fx-item');
            if (!n) { self.sel = {}; self.paint(); self.paneMenu(ev); return; }
            var k = n.dataset.key;
            if (!self.sel[k]) { self.sel = {}; self.sel[k] = true; self.anchor = k; self.paint(); }
            self.entryMenu(ev, self.findEntry(k));
        });
        pane.addEventListener('keydown', function (ev) {
            if (ev.target.closest('.fx-rename')) return;
            var ctrl = ev.ctrlKey || ev.metaKey, sel = self.selected();
            if (ctrl && ev.key.toLowerCase() === 'a') { ev.preventDefault(); self.order.forEach(function (k) { self.sel[k] = true; }); self.paint(); }
            else if (ctrl && ev.key.toLowerCase() === 'c') { if (self.can().copy) self.command('copy'); }
            else if (ctrl && ev.key.toLowerCase() === 'x') { if (self.can().cut) self.command('cut'); }
            else if (ctrl && ev.key.toLowerCase() === 'v') { if (self.can().paste) self.command('paste'); }
            else if (ctrl && ev.key.toLowerCase() === 'f') { ev.preventDefault(); self.filterInput.focus(); }
            else if (ev.key === 'Delete') { if (self.can().del) self.command('delete'); }
            else if (ev.key === 'F2') { ev.preventDefault(); if (self.can().rename) self.command('rename'); }
            else if (ev.key === 'F5') { ev.preventDefault(); self.reload(); }
            else if (ev.key === 'Enter') { if (sel.length === 1) self.open(sel[0]); }
            else if (ev.key === 'Backspace' || (ev.altKey && ev.key === 'ArrowLeft')) { ev.preventDefault(); self.goBack(); }
            else if (ev.altKey && ev.key === 'ArrowRight') { ev.preventDefault(); self.goForward(); }
            else if (ev.altKey && ev.key === 'ArrowUp') { ev.preventDefault(); self.goUp(); }
            else if (['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(ev.key) >= 0) {
                ev.preventDefault();
                self.moveCursor(ev.key, ev.shiftKey);
            }
        });

        // Rubber-band selection, starting on empty space.
        pane.addEventListener('pointerdown', function (ev) {
            self.marqueeMoved = false;
            if (ev.button !== 0 || ev.target.closest('.fx-item') || ev.target.closest('.fx-dhead')) return;
            var pr = pane.getBoundingClientRect();
            var sx = ev.clientX - pr.left + pane.scrollLeft, sy = ev.clientY - pr.top + pane.scrollTop;
            var base = (ev.ctrlKey || ev.metaKey) ? Object.assign({}, self.sel) : {};
            var m = self.marquee;
            pane.setPointerCapture(ev.pointerId);
            function move(e) {
                var x = e.clientX - pr.left + pane.scrollLeft, y = e.clientY - pr.top + pane.scrollTop;
                if (!self.marqueeMoved && Math.abs(x - sx) + Math.abs(y - sy) < 5) return;
                self.marqueeMoved = true;
                var r = { l: Math.min(sx, x), t: Math.min(sy, y), r: Math.max(sx, x), b: Math.max(sy, y) };
                m.hidden = false;
                m.style.left = r.l + 'px'; m.style.top = r.t + 'px';
                m.style.width = (r.r - r.l) + 'px'; m.style.height = (r.b - r.t) + 'px';
                self.sel = Object.assign({}, base);
                self.list.querySelectorAll('.fx-item').forEach(function (n) {
                    var nr = n.getBoundingClientRect();
                    var nl = nr.left - pr.left + pane.scrollLeft, nt = nr.top - pr.top + pane.scrollTop;
                    if (nl < r.r && nl + nr.width > r.l && nt < r.b && nt + nr.height > r.t) self.sel[n.dataset.key] = true;
                });
                self.paint();
            }
            function up() {
                pane.removeEventListener('pointermove', move);
                pane.removeEventListener('pointerup', up);
                m.hidden = true;
                setTimeout(function () { self.marqueeMoved = false; }, 0);
            }
            pane.addEventListener('pointermove', move);
            pane.addEventListener('pointerup', up);
        });

        // Drag out: entries to another folder, window, or the tree.
        pane.addEventListener('dragstart', function (ev) {
            var n = ev.target.closest('.fx-item');
            if (!n) return;
            var k = n.dataset.key;
            if (!self.sel[k]) { self.sel = {}; self.sel[k] = true; self.paint(); }
            var sel = self.selected();
            ev.dataTransfer.effectAllowed = 'copyMove';
            ev.dataTransfer.setData('application/x-freeitsm-files', JSON.stringify({ from: self.folderId, entries: sel.map(toRef) }));
            dragging = { from: self.folderId, entries: sel };
        });
        pane.addEventListener('dragend', function () { dragging = null; });
        this.dropTarget(pane, function () { return self.folderId; }, true);
    };

    /**
     * Make an element accept drops: files from the computer (upload) or entries
     * from any Explorer (move; Ctrl = copy). getFolder() says which folder.
     */
    Explorer.prototype.dropTarget = function (node, getFolder, isPane) {
        var self = this;
        function kind(ev) {
            var t = ev.dataTransfer && ev.dataTransfer.types ? Array.prototype.slice.call(ev.dataTransfer.types) : [];
            if (t.indexOf('application/x-freeitsm-files') >= 0) return 'entries';
            if (t.indexOf('Files') >= 0) return 'files';
            return null;
        }
        node.addEventListener('dragover', function (ev) {
            var k = kind(ev), target = getFolder();
            if (!k) return;
            if (isPane && ev.target.closest('.fx-item[data-key^="f:"]')) return;   // the folder under the pointer handles it
            if (k === 'entries' && dragging && dragging.entries.some(function (e) { return e.type === 'folder' && e.id === target; })) return;
            if (k === 'files' && !target) return;
            ev.preventDefault();
            ev.stopPropagation();
            ev.dataTransfer.dropEffect = (k === 'files' || ev.ctrlKey) ? 'copy' : 'move';
            node.classList.add('fx-drop');
        });
        node.addEventListener('dragleave', function (ev) { if (!node.contains(ev.relatedTarget)) node.classList.remove('fx-drop'); });
        node.addEventListener('drop', function (ev) {
            var k = kind(ev), target = getFolder();
            node.classList.remove('fx-drop');
            if (!k) return;
            if (isPane && ev.target.closest('.fx-item[data-key^="f:"]')) return;
            ev.preventDefault();
            ev.stopPropagation();
            if (k === 'files') {
                Uploads.add(Array.prototype.slice.call(ev.dataTransfer.files), target);
                return;
            }
            var payload;
            try { payload = JSON.parse(ev.dataTransfer.getData('application/x-freeitsm-files')); } catch (e) { return; }
            if (!payload || (payload.from === target && !ev.ctrlKey)) return;
            transfer(ev.ctrlKey ? 'copy' : 'move', payload.entries.map(function (e) { return { type: e.type, id: e.id }; }), payload.from, target);
        });
    };

    Explorer.prototype.moveCursor = function (key, extend) {
        if (!this.order.length) return;
        var i = this.order.indexOf(this.anchor);
        var cols = 1;
        if (this.view === 'icons') {
            var first = this.list.querySelector('.fx-item');
            if (first) cols = Math.max(1, Math.floor(this.list.clientWidth / first.offsetWidth));
        }
        var step = { ArrowDown: cols, ArrowUp: -cols, ArrowRight: this.view === 'icons' ? 1 : 0, ArrowLeft: this.view === 'icons' ? -1 : 0 }[key];
        var j = key === 'Home' ? 0 : key === 'End' ? this.order.length - 1 : (i < 0 ? 0 : Math.max(0, Math.min(this.order.length - 1, i + step)));
        var k = this.order[j];
        if (!extend) this.sel = {};
        this.sel[k] = true;
        this.anchor = k;
        this.paint();
        var n = this.list.querySelector('[data-key="' + k + '"]');
        if (n) n.scrollIntoView({ block: 'nearest' });
    };

    /** Repaint selection without rebuilding the list. */
    Explorer.prototype.paint = function () {
        var self = this;
        this.list.querySelectorAll('.fx-item').forEach(function (n) { n.classList.toggle('fx-sel', !!self.sel[n.dataset.key]); });
        this.updateStatus();
        this.updateCommands();
    };

    Explorer.prototype.bindSplit = function (bar) {
        var self = this;
        bar.addEventListener('pointerdown', function (ev) {
            ev.preventDefault();
            bar.setPointerCapture(ev.pointerId);
            var x0 = ev.clientX, w0 = self.treeEl.offsetWidth;
            function move(e) { self.treeEl.style.width = Math.max(120, Math.min(480, w0 + e.clientX - x0)) + 'px'; }
            function up() { bar.removeEventListener('pointermove', move); bar.removeEventListener('pointerup', up); }
            bar.addEventListener('pointermove', move);
            bar.addEventListener('pointerup', up);
        });
    };

    var dragging = null;

    // ── The left-hand folder tree ──────────────────────────────────────────
    function Tree(ex) {
        this.ex = ex;
        this.el = ex.treeEl;
        this.open = { 0: true };     // expanded folder ids
        this.render();
    }
    Tree.prototype.render = function () {
        var self = this;
        this.el.innerHTML = '';
        var root = this.node({ id: 0, name: L('app.documents', 'Documents'), has_children: true }, 0, true);
        this.el.appendChild(root);
        this.fill(0, root.querySelector('.fx-tkids'), 1);
    };
    Tree.prototype.node = function (f, depth, isRoot) {
        var self = this;
        var wrap = h('div', 'fx-tnode');
        var row = h('div', 'fx-trow');
        row.dataset.id = f.id;
        row.style.paddingLeft = (6 + depth * 14) + 'px';
        row.setAttribute('role', 'treeitem');
        row.innerHTML = '<span class="fx-ttog">' + (f.has_children ? IC.chevron : '') + '</span>' +
            '<span class="fx-tic">' + (isRoot ? FI.app('documents', 16) : FI.folder(16)) + '</span><span class="fx-tname"></span>';
        row.querySelector('.fx-tname').textContent = f.name;
        if (f.path) row.title = f.path;
        var kids = h('div', 'fx-tkids');
        if (this.open[f.id]) row.classList.add('fx-open');
        row.querySelector('.fx-ttog').addEventListener('click', function (ev) {
            ev.stopPropagation();
            if (self.open[f.id]) { delete self.open[f.id]; row.classList.remove('fx-open'); kids.innerHTML = ''; }
            else { self.open[f.id] = true; row.classList.add('fx-open'); self.fill(f.id, kids, depth + 1); }
        });
        row.addEventListener('click', function () { self.ex.load(f.id); });
        row.addEventListener('contextmenu', function (ev) {
            ev.preventDefault();
            if (!f.id) return;
            WM.menu(ev.clientX, ev.clientY, [
                { label: L('ex.open', 'Open'), icon: IC.open, action: function () { self.ex.load(f.id); } },
                { label: L('ex.open_new', 'Open in new window'), action: function () { new Explorer(f.id); } },
                { sep: true },
                clip ? { label: L('ex.paste_into', 'Paste into folder'), icon: IC.paste, action: function () { paste(f.id); }, disabled: (f.level || 0) < LV.UPLOAD } : null,
                { label: L('ex.permissions', 'Permissions'), icon: IC.perms, action: function () { openPermissions(f.id); } },
                { label: L('ex.properties', 'Properties'), icon: IC.props, action: function () { openProperties('folder', f.id); } }
            ]);
        });
        this.ex.dropTarget(row, function () { return f.id; });
        wrap.appendChild(row);
        wrap.appendChild(kids);
        return wrap;
    };
    Tree.prototype.fill = function (id, container, depth) {
        var self = this;
        return api('list.php?tree=1&folder=' + id).then(function (r) {
            container.innerHTML = '';
            r.folders.forEach(function (f) {
                var n = self.node(f, depth);
                container.appendChild(n);
                if (self.open[f.id]) self.fill(f.id, n.querySelector('.fx-tkids'), depth + 1);
            });
            self.highlight(self.ex.folderId);
        }).catch(function () { container.innerHTML = ''; });
    };
    Tree.prototype.refresh = function () { this.render(); };
    Tree.prototype.highlight = function (id) {
        this.el.querySelectorAll('.fx-trow').forEach(function (r) { r.classList.toggle('fx-tcur', +r.dataset.id === id); });
    };

    // ════════════════════════════════════════════════════════════════════════
    //  UPLOADS - chunked, two at a time, with a Transfers window
    // ════════════════════════════════════════════════════════════════════════
    var Uploads = (function () {
        var queue = [], running = 0, MAX_PARALLEL = 2, seqId = 0;
        var win = null, listEl = null;
        var tray = document.getElementById('fdTrayTransfers');
        var input = document.getElementById('fdFileInput');
        var pickFolder = 0;
        var conflictAll = null;   // a "do this for all" answer for the current batch

        input.addEventListener('change', function () {
            add(Array.prototype.slice.call(input.files), pickFolder);
            input.value = '';
        });
        tray.addEventListener('click', openWindow);
        window.addEventListener('beforeunload', function (ev) {
            if (queue.some(function (u) { return u.status === 'queued' || u.status === 'uploading'; })) { ev.preventDefault(); ev.returnValue = ''; }
        });

        function pick(folderId) { pickFolder = folderId; input.click(); }

        function add(files, folderId) {
            if (!folderId) { WM.notify(L('up.need_folder', 'Open a folder first - files go inside folders.'), 'error'); return; }
            conflictAll = null;
            files.forEach(function (f) {
                if (f.size > B.maxUpload) {
                    WM.notify(L('up.too_big', '"{name}" is larger than the {max} limit.', { name: f.name, max: fmtSize(B.maxUpload) }), 'error');
                    return;
                }
                queue.push({ id: ++seqId, file: f, folder: folderId, name: f.name, size: f.size, sent: 0, status: 'queued', error: '' });
            });
            openWindow(true);
            pump();
        }

        function pump() {
            while (running < MAX_PARALLEL) {
                var next = queue.filter(function (u) { return u.status === 'queued'; })[0];
                if (!next) break;
                running++;
                next.status = 'uploading';
                run(next).then(function () {}, function () {}).then(function () { running--; paint(); pump(); });
            }
            paint();
        }

        function askConflict(u, name) {
            if (conflictAll) return Promise.resolve(conflictAll);
            var more = queue.filter(function (x) { return x.status === 'queued'; }).length > 0;
            return WM.dialog({
                title: L('up.conflict_title', 'Replace or keep both?'),
                message: L('up.conflict_msg', 'This folder already has a file called "{name}".', { name: name }),
                check: more ? L('up.do_for_all', 'Do this for every clash in this upload') : null,
                buttons: [
                    { label: L('up.replace', 'Replace'), value: 'version', primary: true },
                    { label: L('up.keep_both', 'Keep both'), value: 'rename' },
                    { label: L('up.skip', 'Skip'), value: 'skip', cancel: true }
                ]
            }).then(function (r) {
                var v = r && typeof r === 'object' ? r.button : r;
                if (r && r.checked) conflictAll = v;
                return v || 'skip';
            });
        }

        function run(u, onConflict) {
            return api('upload.php?action=start', { folder_id: u.folder, name: u.name, size: u.size, on_conflict: onConflict || 'ask' })
                .then(function (r) {
                    if (r.conflict) {
                        return askConflict(u, r.name).then(function (choice) {
                            if (choice === 'skip') { u.status = 'skipped'; return; }
                            return run(u, choice);
                        });
                    }
                    u.token = r.token;
                    u.name = r.name;
                    return sendChunks(u, r.chunk).then(function () {
                        return api('upload.php?action=finish', { token: u.token });
                    }).then(function (fin) {
                        u.status = 'done';
                        u.versionNo = fin.version_no;
                        changed([u.folder]);
                    });
                })
                .catch(function (e) {
                    if (u.status === 'cancelled') return;
                    u.status = 'error';
                    u.error = e.message;
                    if (u.token) api('upload.php?action=cancel', { token: u.token }).catch(function () {});
                });
        }

        function sendChunks(u, chunk) {
            return new Promise(function (resolve, reject) {
                function next(offset) {
                    if (u.status === 'cancelled') { reject(new Error('cancelled')); return; }
                    if (offset >= u.size) { resolve(); return; }
                    var end = Math.min(u.size, offset + chunk);
                    var xhr = u.xhr = new XMLHttpRequest();
                    xhr.open('POST', B.api + 'upload.php?action=chunk&token=' + u.token + '&offset=' + offset);
                    xhr.setRequestHeader('Content-Type', 'application/octet-stream');
                    xhr.upload.onprogress = function (e) { u.sent = offset + (e.loaded || 0); paintOne(u); };
                    xhr.onload = function () {
                        var j = null;
                        try { j = JSON.parse(xhr.responseText); } catch (e) { /* not JSON */ }
                        if (!j || !j.success) { reject(new Error((j && j.error) || ('HTTP ' + xhr.status))); return; }
                        u.sent = j.received;
                        paintOne(u);
                        next(j.received);
                    };
                    xhr.onerror = function () { reject(new Error(L('up.network', 'The connection dropped.'))); };
                    xhr.send(u.file.slice(offset, end));
                }
                next(0);
            });
        }

        function cancel(u) {
            if (u.status === 'queued') { u.status = 'cancelled'; paint(); return; }
            if (u.status !== 'uploading') return;
            u.status = 'cancelled';
            if (u.xhr) try { u.xhr.abort(); } catch (e) { /* gone */ }
            if (u.token) api('upload.php?action=cancel', { token: u.token }).catch(function () {});
            paint();
        }

        function openWindow(quiet) {
            if (win && !win.closed) { if (!quiet) { win.restore(); win.focus(); } return; }
            win = WM.open({ kind: 'transfers', title: L('app.transfers', 'Transfers'), icon: FI.app('transfers', 16),
                width: 460, height: 320, geometry: geoFor('transfers'), single: true,
                onClose: function () { win = null; } });
            win.body.classList.add('fd-transfers');
            win.body.innerHTML = '<div class="fd-tr-head"><button type="button" class="fd-btn fd-tr-clear">' + esc(L('up.clear', 'Clear')) + '</button></div><div class="fd-tr-list"></div>';
            listEl = win.body.querySelector('.fd-tr-list');
            win.body.querySelector('.fd-tr-clear').addEventListener('click', function () {
                queue = queue.filter(function (u) { return u.status === 'queued' || u.status === 'uploading'; });
                paint();
            });
            paint();
        }

        function statusText(u) {
            switch (u.status) {
                case 'queued': return L('up.waiting', 'Waiting');
                case 'uploading': return fmtSize(u.sent) + ' / ' + fmtSize(u.size);
                case 'done': return u.versionNo > 1 ? L('up.done_version', 'Uploaded as version {n}', { n: u.versionNo }) : L('up.done', 'Uploaded');
                case 'skipped': return L('up.skipped', 'Skipped');
                case 'cancelled': return L('up.cancelled', 'Cancelled');
                case 'error': return u.error;
            }
            return '';
        }
        function pct(u) { return u.size ? Math.round(100 * u.sent / u.size) : (u.status === 'done' ? 100 : 0); }

        function paintOne(u) {
            var row = listEl && listEl.querySelector('[data-up="' + u.id + '"]');
            if (!row) return;
            row.querySelector('.fd-tr-bar i').style.width = (u.status === 'done' ? 100 : pct(u)) + '%';
            row.querySelector('.fd-tr-st').textContent = statusText(u);
            paintTray();
        }
        function paint() {
            paintTray();
            if (!listEl || !win || win.closed) return;
            if (!queue.length) { listEl.innerHTML = '<div class="fd-tr-empty">' + esc(L('up.none', 'No transfers.')) + '</div>'; return; }
            listEl.innerHTML = '';
            queue.forEach(function (u) {
                var row = h('div', 'fd-tr-row fd-tr-' + u.status);
                row.dataset.up = u.id;
                row.innerHTML = '<div class="fd-tr-ic">' + FI.file(u.name, 24) + '</div><div class="fd-tr-mid"><div class="fd-tr-name"></div>' +
                    '<div class="fd-tr-bar"><i></i></div><div class="fd-tr-st"></div></div>' +
                    ((u.status === 'queued' || u.status === 'uploading') ? '<button type="button" class="fd-tr-x" title="' + esc(L('btn.cancel', 'Cancel')) + '">&times;</button>' : '');
                row.querySelector('.fd-tr-name').textContent = u.name;
                var x = row.querySelector('.fd-tr-x');
                if (x) x.addEventListener('click', function () { cancel(u); });
                listEl.appendChild(row);
                paintOne(u);
            });
        }
        function paintTray() {
            var active = queue.filter(function (u) { return u.status === 'queued' || u.status === 'uploading'; });
            if (!active.length) { tray.hidden = true; return; }
            var total = 0, sent = 0;
            active.forEach(function (u) { total += u.size; sent += u.sent; });
            tray.hidden = false;
            tray.innerHTML = IC.upload + '<span>' + active.length + '</span><i style="width:' + (total ? Math.round(100 * sent / total) : 0) + '%"></i>';
            tray.title = L('up.tray', '{n} uploading', { n: active.length });
        }

        return { pick: pick, add: add, open: openWindow };
    })();

    // ════════════════════════════════════════════════════════════════════════
    //  PERMISSIONS
    // ════════════════════════════════════════════════════════════════════════
    function openPermissions(folderId) {
        var w = WM.open({ kind: 'perms', key: 'p' + folderId, single: true, title: L('perm.title', 'Permissions'),
            icon: FI.app('permissions', 16), width: 620, height: 520, geometry: geoFor('perms') });
        if (w.loaded) return;
        w.loaded = true;
        var state = null;
        w.body.classList.add('fd-perm');
        w.body.innerHTML = '<div class="fd-pad">' + esc(L('loading', 'Loading...')) + '</div>';

        function load() {
            return api('permissions.php?folder=' + folderId).then(function (r) {
                state = r;
                state.own = r.entries.filter(function (e) { return !e.inherited; }).map(function (e) {
                    return { principal_type: e.principal_type, principal_id: e.principal_id, name: e.name, level: e.level };
                });
                w.setTitle(L('perm.title_for', 'Permissions - {name}', { name: r.folder.name }));
                render();
            }).catch(function (e) { fail(e); w.close(); });
        }

        function render() {
            var names = LEVEL_NAMES();
            var ro = !state.can_edit;
            var b = w.body;
            b.innerHTML =
                '<div class="fd-perm-head">' + FI.folder(32) + '<div><div class="fd-perm-name"></div><div class="fd-perm-path"></div></div></div>' +
                (ro ? '<div class="fd-note">' + esc(L('perm.read_only', 'You can see who has access here, but only someone with Full control can change it.')) + '</div>' : '') +
                // A top-level folder has nothing above it to inherit from.
                (state.has_parent ? '<label class="fd-perm-inherit"><input type="checkbox"' + (state.inherit ? ' checked' : '') + (ro ? ' disabled' : '') + '> ' +
                    esc(L('perm.inherit', 'Include permissions from the parent folder')) + '</label>'
                    : '<div class="fd-perm-inherit fd-muted">' + esc(L('perm.top_level', 'A top-level folder: only the entries below apply.')) + '</div>') +
                '<div class="fd-perm-tablewrap"><table class="fd-table"><thead><tr><th>' + esc(L('perm.who', 'Person or team')) + '</th><th>' + esc(L('perm.access', 'Access')) + '</th><th></th></tr></thead><tbody></tbody></table></div>' +
                (ro ? '' : '<div class="fd-perm-add"><input type="search" class="fd-input" placeholder="' + esc(L('perm.add_ph', 'Add a person or team...')) + '"><div class="fd-perm-results" hidden></div></div>') +
                '<div class="fd-perm-wm"><label>' + esc(L('perm.watermark', 'Watermark')) + ' <select class="fd-select"' + (ro ? ' disabled' : '') + '>' +
                    '<option value="">' + esc(state.has_parent
                        ? L('perm.wm_inherit', 'As the parent folder (currently {state})', { state: state.watermark_parent ? L('perm.wm_on_word', 'on') : L('perm.wm_off_word', 'off') })
                        : L('perm.wm_default', 'Off (the default)')) + '</option>' +
                    '<option value="1"' + (state.watermark === 1 ? ' selected' : '') + '>' + esc(L('perm.wm_on', 'On')) + '</option>' +
                    '<option value="0"' + (state.watermark === 0 ? ' selected' : '') + '>' + esc(L('perm.wm_off', 'Off')) + '</option>' +
                '</select></label><div class="fd-muted">' + esc(L('perm.wm_desc', 'Stamps the viewer\'s name, the time and their IP address across every file opened from this folder and the folders inside it, so a screenshot shows who took it.')) + '</div></div>' +
                '<div class="fd-perm-legend">' + esc(L('perm.legend', 'View: see and open. Download: also take a copy. Upload: also add files and folders. Modify: also rename, move and delete. Full control: also change these permissions.')) + '</div>' +
                '<div class="fd-dialog-btns">' + (ro ? '<button type="button" class="fd-btn fd-btn-primary" data-act="close">' + esc(L('btn.close', 'Close')) + '</button>'
                    : '<button type="button" class="fd-btn fd-btn-primary" data-act="save">' + esc(L('btn.save', 'Save')) + '</button><button type="button" class="fd-btn" data-act="close">' + esc(L('btn.cancel', 'Cancel')) + '</button>') + '</div>';
            b.querySelector('.fd-perm-name').textContent = state.folder.name;
            b.querySelector('.fd-perm-path').textContent = state.folder.path;
            var tb = b.querySelector('tbody');
            var who = function (e) {
                return (e.principal_type === 'team' ? '<span class="fd-badge">' + esc(L('perm.team', 'Team')) + '</span> ' : '') + esc(e.name);
            };
            state.own.forEach(function (e, idx) {
                var tr = h('tr');
                var opts = [1, 2, 3, 4, 5].map(function (l) { return '<option value="' + l + '"' + (l === e.level ? ' selected' : '') + '>' + esc(names[l]) + '</option>'; }).join('');
                tr.innerHTML = '<td>' + who(e) + '</td><td><select class="fd-select"' + (ro ? ' disabled' : '') + '>' + opts + '</select></td>' +
                    '<td>' + (ro ? '' : '<button type="button" class="fd-iconbtn" title="' + esc(L('perm.remove', 'Remove')) + '">' + IC.del + '</button>') + '</td>';
                tr.querySelector('select').addEventListener('change', function () { e.level = +this.value; });
                var rm = tr.querySelector('.fd-iconbtn');
                if (rm) rm.addEventListener('click', function () { state.own.splice(idx, 1); render(); });
                tb.appendChild(tr);
            });
            state.entries.filter(function (e) { return e.inherited; }).forEach(function (e) {
                var tr = h('tr', 'fd-inherited');
                tr.innerHTML = '<td>' + who(e) + '</td><td>' + esc(names[e.level]) + '</td><td class="fd-muted">' +
                    esc(L('perm.from', 'From {path}', { path: e.from_name })) + '</td>';
                tb.appendChild(tr);
            });
            if (!state.own.length && !state.entries.some(function (e) { return e.inherited; })) {
                tb.innerHTML = '<tr><td colspan="3" class="fd-muted">' + esc(L('perm.nobody', 'Nobody has access to this folder.')) + '</td></tr>';
            }

            var wmSel = b.querySelector('.fd-perm-wm select');
            wmSel.addEventListener('change', function () {
                api('folder.php', { action: 'watermark', id: folderId, value: wmSel.value === '' ? null : +wmSel.value })
                    .then(function (r) {
                        WM.notify(r.effective ? L('perm.wm_now_on', 'Files opened from here will be watermarked.') : L('perm.wm_now_off', 'Files opened from here will not be watermarked.'));
                        return load();
                    }).catch(function (e) { fail(e); load(); });
            });
            var inheritBox = b.querySelector('.fd-perm-inherit input');
            if (inheritBox) inheritBox.addEventListener('change', function () {
                var cb = this;
                if (cb.checked) {
                    api('folder.php', { action: 'inherit', id: folderId, inherit: true }).then(load).then(function () { changed([]); }).catch(function (e) { fail(e); load(); });
                    return;
                }
                WM.dialog({
                    title: L('perm.stop_title', 'Stop inheriting?'),
                    message: L('perm.stop_msg', 'This folder will no longer get its permissions from the folder above it. What should happen to the permissions it has inherited?'),
                    buttons: [
                        { label: L('perm.stop_copy', 'Copy'), value: 'copy', primary: true },
                        { label: L('perm.stop_remove', 'Remove'), value: 'remove' },
                        { label: L('btn.cancel', 'Cancel'), value: null, cancel: true }
                    ]
                }).then(function (v) {
                    if (!v) { cb.checked = true; return; }
                    api('folder.php', { action: 'inherit', id: folderId, inherit: false, copy: v === 'copy' })
                        .then(load).then(function () { changed([]); }).catch(function (e) { fail(e); load(); });
                });
            });

            var search = b.querySelector('.fd-perm-add input');
            if (search) {
                var res = b.querySelector('.fd-perm-results');
                var find = debounce(function () {
                    var q = search.value.trim();
                    api('principals.php?q=' + encodeURIComponent(q)).then(function (r) {
                        res.innerHTML = '';
                        r.principals.forEach(function (p) {
                            if (state.own.some(function (e) { return e.principal_type === p.principal_type && e.principal_id === p.principal_id; })) return;
                            var row = h('button', 'fd-perm-res');
                            row.type = 'button';
                            row.innerHTML = (p.principal_type === 'team' ? '<span class="fd-badge">' + esc(L('perm.team', 'Team')) + '</span> ' : '') + esc(p.name);
                            row.addEventListener('click', function () {
                                state.own.push({ principal_type: p.principal_type, principal_id: p.principal_id, name: p.name, level: LV.VIEW });
                                render();
                            });
                            res.appendChild(row);
                        });
                        res.hidden = !res.children.length;
                    }).catch(fail);
                }, 250);
                search.addEventListener('input', find);
                search.addEventListener('focus', find);
                search.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { res.hidden = true; } });
            }

            b.querySelector('.fd-dialog-btns').addEventListener('click', function (ev) {
                var act = ev.target.closest('[data-act]');
                if (!act) return;
                if (act.dataset.act === 'close') { w.close(); return; }
                save();
            });
        }

        function save() {
            // Warn before someone removes their own Full control - they would lose
            // the ability to undo it (Take ownership is the only way back).
            var mine = state.own.filter(function (e) { return e.principal_type === 'analyst' && e.principal_id === B.me.id; })[0];
            var hadMine = state.entries.filter(function (e) { return !e.inherited && e.principal_type === 'analyst' && e.principal_id === B.me.id && e.level === LV.FULL; })[0];
            var go = Promise.resolve(true);
            if (hadMine && (!mine || mine.level < LV.FULL)) {
                go = WM.dialog({
                    title: L('perm.self_title', 'Lower your own access?'),
                    message: L('perm.self_msg', 'You are removing your own Full control here. Unless you have it some other way (a team, or a parent folder) you will not be able to change these permissions again.'),
                    buttons: [{ label: L('btn.save', 'Save'), value: true, primary: true }, { label: L('btn.cancel', 'Cancel'), value: false, cancel: true }]
                });
            }
            go.then(function (ok) {
                if (!ok) return;
                api('permissions.php', { action: 'save', folder: folderId, entries: state.own })
                    .then(function (r) {
                        WM.notify(r.changed ? L('perm.saved', 'Permissions saved.') : L('perm.nochange', 'Nothing changed.'));
                        changed([]);
                        if (r.my_level < LV.VIEW) { w.close(); return; }
                        load();
                    }).catch(fail);
            });
        }
        load();
    }

    // ════════════════════════════════════════════════════════════════════════
    //  PROPERTIES
    // ════════════════════════════════════════════════════════════════════════
    function openProperties(type, id) {
        var w = WM.open({ kind: 'props', key: type + id, single: true, title: L('props.title', 'Properties'),
            icon: FI.app('properties', 16), width: 480, height: 500, geometry: geoFor('props') });
        if (w.loaded) return;
        w.loaded = true;
        w.body.classList.add('fd-props');
        w.body.innerHTML = '<div class="fd-pad">' + esc(L('loading', 'Loading...')) + '</div>';
        api('properties.php?type=' + (type === 'folder' ? 'folder' : 'item') + '&id=' + id).then(function (r) {
            var p = r.properties, names = LEVEL_NAMES();
            w.setTitle(L('props.title_for', '{name} Properties', { name: p.name }));
            var rows = function (pairs) {
                return pairs.filter(function (x) { return x && x[1] !== null && x[1] !== undefined && x[1] !== ''; })
                    .map(function (x) { return '<tr><th>' + esc(x[0]) + '</th><td>' + esc(x[1]) + '</td></tr>'; }).join('');
            };
            var head = '<div class="fd-props-head">' + (p.type === 'folder' ? FI.folder(48) : FI.file(p.name, 48)) + '<div class="fd-props-name"></div></div>';
            var general;
            if (p.type === 'folder') {
                general = rows([
                    [L('props.type', 'Type'), L('type.folder', 'File folder')],
                    [L('props.location', 'Location'), p.path],
                    [L('props.size', 'Size'), fmtSize(p.size) + ' (' + p.size.toLocaleString() + ' ' + L('props.bytes', 'bytes') + ')'],
                    [L('props.contains', 'Contains'), L('props.contains_n', '{files} files, {folders} folders', { files: p.files, folders: p.folders })],
                    [L('props.created', 'Created'), fmtWhen(p.created) + (p.created_by ? ' - ' + p.created_by : '')],
                    [L('props.modified', 'Modified'), p.modified ? fmtWhen(p.modified) + (p.modified_by ? ' - ' + p.modified_by : '') : ''],
                    [L('props.inherits', 'Inherits permissions'), p.inherit ? L('yes', 'Yes') : L('no', 'No')],
                    [L('props.your_access', 'Your access'), names[p.level]]
                ]);
            } else {
                general = rows([
                    [L('props.type', 'Type'), typeName(p.name) + (p.mime ? ' (' + p.mime + ')' : '')],
                    [L('props.location', 'Location'), p.path],
                    [L('props.size', 'Size'), fmtSize(p.size) + ' (' + p.size.toLocaleString() + ' ' + L('props.bytes', 'bytes') + ')'],
                    [L('props.created', 'Created'), fmtWhen(p.created) + (p.created_by ? ' - ' + p.created_by : '')],
                    [L('props.modified', 'Modified'), fmtWhen(p.modified)],
                    [L('props.your_access', 'Your access'), names[p.level]]
                ]);
            }
            var versions = '';
            if (p.type === 'item') {
                versions = '<h4>' + esc(L('props.versions', 'Versions')) + '</h4><div class="fd-props-versions"><table class="fd-table"><thead><tr><th>#</th><th>' +
                    esc(L('props.uploaded', 'Uploaded')) + '</th><th>' + esc(L('col.size', 'Size')) + '</th><th></th></tr></thead><tbody>' +
                    p.versions.map(function (v) {
                        return '<tr><td>' + v.version_no + (v.id === p.current_version_id ? ' <span class="fd-badge">' + esc(L('props.current', 'Current')) + '</span>' : '') + '</td>' +
                            '<td>' + esc(fmtWhen(v.uploaded)) + '<div class="fd-muted">' + esc(v.uploaded_by || '') + '</div>' +
                            '<div class="fd-sha" title="SHA-256">' + esc(v.sha256 || '') + '</div></td>' +
                            '<td>' + esc(fmtSize(v.size)) + '</td>' +
                            '<td class="fd-nowrap"><button type="button" class="fd-iconbtn" data-view="' + v.id + '" title="' + esc(L('ex.open', 'Open')) + '">' + IC.open + '</button>' +
                            (p.level >= LV.DOWNLOAD ? '<button type="button" class="fd-iconbtn" data-v="' + v.id + '" title="' + esc(L('ex.download', 'Download')) + '">' + IC.download + '</button>' : '') + '</td></tr>';
                    }).join('') + '</tbody></table></div>';
            }
            w.body.innerHTML = head + '<table class="fd-kv">' + general + '</table>' + versions +
                '<div class="fd-dialog-btns">' + (p.type === 'folder' ? '<button type="button" class="fd-btn" data-act="perms">' + esc(L('ex.permissions', 'Permissions')) + '</button>'
                    : '<button type="button" class="fd-btn" data-act="view">' + esc(L('ex.open', 'Open')) + '</button>') +
                '<button type="button" class="fd-btn fd-btn-primary" data-act="close">' + esc(L('btn.close', 'Close')) + '</button></div>';
            w.body.querySelector('.fd-props-name').textContent = p.name;
            w.body.addEventListener('click', function (ev) {
                var v = ev.target.closest('[data-v]');
                if (v) { download(id, +v.dataset.v); return; }
                var vw = ev.target.closest('[data-view]');
                if (vw) { viewFile(id, +vw.dataset.view); return; }
                var a = ev.target.closest('[data-act]');
                if (!a) return;
                if (a.dataset.act === 'close') w.close();
                if (a.dataset.act === 'perms') openPermissions(id);
                if (a.dataset.act === 'view') viewFile(id);
            });
        }).catch(function (e) { fail(e); w.close(); });
    }

    // ════════════════════════════════════════════════════════════════════════
    //  RECENT, SEARCH, PERSONALISE, HELP
    // ════════════════════════════════════════════════════════════════════════
    /** Open the folder a file lives in, with the file selected. */
    function openLocation(folderId, itemKey) {
        new Explorer(folderId, { select: itemKey });
    }

    function resultsTable(container, rows) {
        if (!rows.length) { container.innerHTML = '<div class="fd-pad fd-muted">' + esc(L('none_found', 'Nothing found.')) + '</div>'; return; }
        container.innerHTML = '<div class="fx-list fx-view-details fd-results"><div class="fx-dhead"><button type="button">' + esc(L('col.name', 'Name')) +
            '</button><button type="button">' + esc(L('props.location', 'Location')) + '</button><button type="button">' + esc(L('col.modified', 'Date modified')) +
            '</button><button type="button">' + esc(L('col.size', 'Size')) + '</button></div>' +
            rows.map(function (r, i) {
                return '<div class="fx-item" data-i="' + i + '"><div class="fx-c fx-c-name"><span class="fx-ic">' + (r.type === 'folder' ? FI.folder(18) : FI.file(r.name, 18)) +
                    '</span><span class="fx-name">' + esc(r.name) + '</span></div><div class="fx-c">' + esc(r.path || '') + '</div><div class="fx-c">' + esc(fmtWhen(r.modified || r.at)) +
                    '</div><div class="fx-c fx-c-size">' + esc(r.type === 'folder' ? '' : fmtSize(r.size)) + '</div></div>';
            }).join('') + '</div>';
        container.querySelectorAll('.fx-item').forEach(function (n) {
            var r = rows[+n.dataset.i];
            var go = function () { r.type === 'folder' ? new Explorer(r.id) : openLocation(r.folder_id, 'i:' + r.id); };
            n.addEventListener('dblclick', function () { r.type === 'folder' ? new Explorer(r.id) : viewFile(r.id); });
            n.addEventListener('click', function () {
                container.querySelectorAll('.fx-sel').forEach(function (x) { x.classList.remove('fx-sel'); });
                n.classList.add('fx-sel');
            });
            n.addEventListener('contextmenu', function (ev) {
                ev.preventDefault();
                WM.menu(ev.clientX, ev.clientY, [
                    r.type !== 'folder' ? { label: L('ex.open', 'Open'), icon: IC.open, action: function () { viewFile(r.id); } } : null,
                    { label: L('ex.open_location', 'Open file location'), icon: IC.location, action: go },
                    r.type !== 'folder' ? { label: L('ex.download', 'Download'), icon: IC.download, action: function () { download(r.id); }, disabled: (r.level || LV.DOWNLOAD) < LV.DOWNLOAD } : null,
                    { label: L('ex.properties', 'Properties'), icon: IC.props, action: function () { openProperties(r.type === 'folder' ? 'folder' : 'item', r.id); } }
                ]);
            });
        });
    }

    function openRecent() {
        var w = WM.open({ kind: 'recent', single: true, title: L('app.recent', 'Recent'), icon: FI.app('recent', 16), width: 720, height: 420, geometry: geoFor('recent') });
        w.body.classList.add('fd-scroll');
        api('recent.php').then(function (r) {
            resultsTable(w.body, r.recent.map(function (x) { return Object.assign({ type: 'item' }, x); }));
        }).catch(fail);
    }

    function openSearch() {
        var w = WM.open({ kind: 'search', single: true, title: L('app.search', 'Search'), icon: FI.app('search', 16), width: 760, height: 460, geometry: geoFor('search') });
        if (w.loaded) { w.body.querySelector('input').focus(); return; }
        w.loaded = true;
        w.body.classList.add('fd-search');
        w.body.innerHTML = '<div class="fd-search-bar"><input type="search" class="fd-input" placeholder="' + esc(L('search.ph', 'Search names of files and folders you can see')) + '"></div><div class="fd-search-out fd-scroll"></div>';
        var input = w.body.querySelector('input'), out = w.body.querySelector('.fd-search-out');
        var run = debounce(function () {
            var q = input.value.trim();
            if (q.length < 2) { out.innerHTML = '<div class="fd-pad fd-muted">' + esc(L('search.hint', 'Type at least two letters.')) + '</div>'; return; }
            api('search.php?q=' + encodeURIComponent(q)).then(function (r) {
                resultsTable(out, r.folders.map(function (f) { return Object.assign({ type: 'folder' }, f); })
                    .concat(r.items.map(function (i) { return Object.assign({ type: 'item' }, i); })));
            }).catch(fail);
        }, 350);
        input.addEventListener('input', run);
        run();
        input.focus();
    }

    var SWATCHES = ['#1e3a5f', '#0b5394', '#134f5c', '#274e13', '#7f6000', '#783f04', '#660000', '#4c1130', '#20124d', '#3c3c3c', '#111827', '#5b6b7f'];
    function openPersonalise() {
        var w = WM.open({ kind: 'personalise', single: true, title: L('app.personalise', 'Personalise'), icon: FI.app('personalise', 16), width: 440, height: 400, geometry: geoFor('personalise') });
        if (w.loaded) return;
        w.loaded = true;
        w.body.classList.add('fd-pers');
        var nav = document.body.dataset.navbar;
        w.body.innerHTML =
            '<h4>' + esc(L('pers.colour', 'Desktop colour')) + '</h4>' +
            '<div class="fd-swatches">' + SWATCHES.map(function (c) { return '<button type="button" class="fd-swatch" data-c="' + c + '" style="background:' + c + '" title="' + c + '"></button>'; }).join('') +
            '<label class="fd-swatch fd-swatch-custom" title="' + esc(L('pers.custom', 'Choose any colour')) + '"><input type="color"></label></div>' +
            '<h4>' + esc(L('pers.navbar', 'Top navigation bar')) + '</h4>' +
            ['on', 'auto', 'off'].map(function (m) {
                var lab = { on: L('pers.nav_on', 'Always show'), auto: L('pers.nav_auto', 'Show when the mouse reaches the top'), off: L('pers.nav_off', 'Hide - use the start menu instead') }[m];
                return '<label class="fd-radio"><input type="radio" name="fdnav" value="' + m + '"' + (nav === m ? ' checked' : '') + '> ' + esc(lab) + '</label>';
            }).join('') +
            '<p class="fd-muted fd-pers-note">' + esc(L('pers.note', 'These choices are yours alone. The logo on the desktop is set by an administrator.')) + '</p>';
        var current = getComputedStyle(document.getElementById('fdDesktop')).getPropertyValue('--fd-bg').trim();
        var picker = w.body.querySelector('input[type=color]');
        picker.value = /^#[0-9a-f]{6}$/i.test(current) ? current : '#1e3a5f';
        var saveColour = debounce(function (c) { api('prefs.php', { desktop_colour: c }).catch(fail); }, 400);
        function setColour(c) {
            document.getElementById('fdDesktop').style.setProperty('--fd-bg', c);
            w.body.querySelectorAll('.fd-swatch').forEach(function (s) { s.classList.toggle('fd-on', s.dataset.c === c); });
            saveColour(c);
        }
        w.body.querySelectorAll('.fd-swatch[data-c]').forEach(function (s) {
            s.classList.toggle('fd-on', s.dataset.c === current);
            s.addEventListener('click', function () { setColour(s.dataset.c); picker.value = s.dataset.c; });
        });
        picker.addEventListener('input', function () { setColour(picker.value); });
        w.body.querySelectorAll('input[name=fdnav]').forEach(function (r) {
            r.addEventListener('change', function () {
                WM.setNavbar(r.value);
                api('prefs.php', { navbar: r.value }).catch(fail);
            });
        });
    }

    function openHelp() {
        var w = WM.open({ kind: 'help', single: true, title: L('app.help', 'Help'), icon: FI.app('help', 16), width: 900, height: 600, geometry: geoFor('help') });
        if (w.loaded) return;
        w.loaded = true;
        w.body.innerHTML = '<iframe class="fd-frame" src="' + esc(B.urls.help) + '" title="' + esc(L('app.help', 'Help')) + '"></iframe>';
    }

    // ════════════════════════════════════════════════════════════════════════
    //  THE DESKTOP AND THE START MENU
    // ════════════════════════════════════════════════════════════════════════
    var APPS = [
        { key: 'documents', label: function () { return L('app.documents', 'Documents'); }, run: function () { new Explorer(0); } },
        { key: 'recent', label: function () { return L('app.recent', 'Recent'); }, run: openRecent },
        { key: 'search', label: function () { return L('app.search', 'Search'); }, run: openSearch },
        { key: 'transfers', label: function () { return L('app.transfers', 'Transfers'); }, run: function () { Uploads.open(); } },
        { key: 'personalise', label: function () { return L('app.personalise', 'Personalise'); }, run: openPersonalise },
        { key: 'help', label: function () { return L('app.help', 'Help'); }, run: openHelp }
    ];
    if (B.caps.folders || B.caps.storage) {
        APPS.push({ key: 'settings', label: function () { return L('app.settings', 'Settings'); }, run: function () { location.href = B.urls.settings; } });
    }
    var DESKTOP_ICONS = ['documents', 'recent', 'search', 'help'].concat(B.caps.folders || B.caps.storage ? ['settings'] : []);
    function appByKey(k) { return APPS.filter(function (a) { return a.key === k; })[0]; }

    function renderDesktopIcons() {
        var box = document.getElementById('fdIcons');
        box.innerHTML = '';
        DESKTOP_ICONS.forEach(function (k) {
            var a = appByKey(k);
            var b = h('button', 'fd-dicon');
            b.type = 'button';
            b.setAttribute('role', 'listitem');
            b.innerHTML = FI.app(k, 44) + '<span></span>';
            b.querySelector('span').textContent = a.label();
            b.addEventListener('click', function () {
                box.querySelectorAll('.fd-dicon').forEach(function (x) { x.classList.remove('fd-sel'); });
                b.classList.add('fd-sel');
            });
            b.addEventListener('dblclick', function () { a.run(); });
            b.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); a.run(); } });
            b.addEventListener('contextmenu', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                WM.menu(ev.clientX, ev.clientY, [{ label: L('ex.open', 'Open'), icon: IC.open, action: a.run }]);
            });
            if (k === 'documents') {
                // Files dropped on Documents have nowhere to go - folders only at the top.
            }
            box.appendChild(b);
        });
    }

    var desktopEl = document.getElementById('fdDesktop');
    desktopEl.addEventListener('pointerdown', function (ev) {
        if (ev.target === desktopEl || ev.target.id === 'fdIcons') {
            document.querySelectorAll('.fd-dicon.fd-sel').forEach(function (x) { x.classList.remove('fd-sel'); });
            var a = WM.active();
            if (a) a.blur();
        }
    });
    desktopEl.addEventListener('contextmenu', function (ev) {
        if (ev.target !== desktopEl && ev.target.id !== 'fdIcons') return;
        ev.preventDefault();
        WM.menu(ev.clientX, ev.clientY, [
            { label: L('app.documents', 'Documents'), icon: FI.app('documents', 16), action: function () { new Explorer(0); } },
            { label: L('app.search', 'Search'), icon: FI.app('search', 16), action: openSearch },
            { sep: true },
            { label: L('app.personalise', 'Personalise'), icon: FI.app('personalise', 16), action: openPersonalise }
        ]);
    });

    // Start menu
    var startBtn = document.getElementById('fdStart');
    var startMenu = document.getElementById('fdStartMenu');
    function closeStart() { startMenu.hidden = true; startBtn.classList.remove('fd-on'); }
    function openStart() {
        WM.closeMenus();
        WM.hidePreview();
        startBtn.classList.add('fd-on');
        startMenu.innerHTML =
            '<div class="fd-sm-search"><input type="search" class="fd-input" placeholder="' + esc(L('start.search', 'Search files and folders')) + '"></div>' +
            '<div class="fd-sm-cols">' +
                '<div class="fd-sm-col fd-sm-left">' +
                    '<div class="fd-sm-pane fd-sm-pane-apps"><div class="fd-sm-h"><span>' + esc(L('start.apps', 'Apps')) + '</span>' +
                        '<button type="button" class="fd-sm-toggle" data-to="mods">' + esc(L('start.all_modules', 'All modules')) + ' &rsaquo;</button></div><div class="fd-sm-apps"></div></div>' +
                    '<div class="fd-sm-pane fd-sm-pane-mods" hidden><div class="fd-sm-h"><span>' + esc(L('start.modules', 'FreeITSM modules')) + '</span>' +
                        '<button type="button" class="fd-sm-toggle" data-to="apps">&lsaquo; ' + esc(L('start.back', 'Back')) + '</button></div><div class="fd-sm-mods"></div></div>' +
                '</div>' +
                '<div class="fd-sm-col"><div class="fd-sm-h">' + esc(L('start.recent', 'Recent files')) + '</div><div class="fd-sm-recent"><div class="fd-muted fd-pad">' + esc(L('loading', 'Loading...')) + '</div></div></div>' +
            '</div>' +
            '<div class="fd-sm-foot"><span class="fd-sm-me"></span>' +
                '<a class="fd-sm-out" href="' + esc(B.urls.logout) + '">' + FI.app('signout', 18) + '<span>' + esc(L('start.sign_out', 'Sign out')) + '</span></a></div>';
        startMenu.querySelector('.fd-sm-me').textContent = B.me.name;
        var apps = startMenu.querySelector('.fd-sm-apps');
        APPS.forEach(function (a) {
            var b = h('button', 'fd-sm-app');
            b.type = 'button';
            b.innerHTML = FI.app(a.key, 26) + '<span></span>';
            b.querySelector('span').textContent = a.label();
            b.addEventListener('click', function () { closeStart(); a.run(); });
            apps.appendChild(b);
        });
        var mods = startMenu.querySelector('.fd-sm-mods');
        B.modules.forEach(function (m) {
            if (m.key === 'files') return;
            var a = h('a', 'fd-sm-mod');
            a.href = m.url;
            a.innerHTML = '<span class="waffle-module-icon ' + esc(m.key) + '"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + m.icon + '</svg></span><span></span>';
            a.lastChild.textContent = m.name;
            mods.appendChild(a);
        });
        // "All modules" swaps the left column, as Windows' All apps does - 25-odd
        // modules under the apps made the menu taller than the screen.
        startMenu.querySelectorAll('.fd-sm-toggle').forEach(function (b) {
            b.addEventListener('click', function () {
                startMenu.querySelector('.fd-sm-pane-apps').hidden = b.dataset.to !== 'apps';
                startMenu.querySelector('.fd-sm-pane-mods').hidden = b.dataset.to !== 'mods';
            });
        });
        startMenu.querySelector('.fd-sm-search input').addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                var q = this.value.trim();
                closeStart();
                openSearch();
                var w = WM.windows().filter(function (x) { return x.kind === 'search'; })[0];
                if (w) { var i = w.body.querySelector('input'); i.value = q; i.dispatchEvent(new Event('input')); }
            }
        });
        startMenu.hidden = false;
        startMenu.style.zIndex = 99500;
        startMenu.querySelector('.fd-sm-search input').focus();
        api('recent.php').then(function (r) {
            var box = startMenu.querySelector('.fd-sm-recent');
            if (!box) return;
            box.innerHTML = r.recent.length ? '' : '<div class="fd-muted fd-pad">' + esc(L('start.no_recent', 'Files you open, download or upload will appear here.')) + '</div>';
            r.recent.forEach(function (it) {
                var b = h('button', 'fd-sm-file');
                b.type = 'button';
                b.innerHTML = FI.file(it.name, 22) + '<span><span class="fd-sm-fname"></span><span class="fd-sm-fpath"></span></span>';
                b.querySelector('.fd-sm-fname').textContent = it.name;
                b.querySelector('.fd-sm-fpath').textContent = it.path;
                b.addEventListener('click', function () { closeStart(); viewFile(it.id); });
                box.appendChild(b);
            });
        }).catch(function () {});
    }
    startBtn.addEventListener('click', function () { startMenu.hidden ? openStart() : closeStart(); });
    document.addEventListener('pointerdown', function (ev) {
        if (!startMenu.hidden && !startMenu.contains(ev.target) && !startBtn.contains(ev.target)) closeStart();
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !startMenu.hidden) closeStart();
        // The Windows key cannot be caught in a browser; Ctrl+Esc opens Start, as it always has.
        if (ev.key === 'Escape' && ev.ctrlKey) { ev.preventDefault(); startMenu.hidden ? openStart() : closeStart(); }
    });

    // ════════════════════════════════════════════════════════════════════════
    //  PHONES - the same folders as a plain list (no desktop below 768px)
    // ════════════════════════════════════════════════════════════════════════
    var Mobile = (function () {
        var box = document.getElementById('fdMobile');
        var cur = 0, data = null;
        function load(id) {
            cur = id;
            box.innerHTML = '<div class="fd-pad fd-muted">' + esc(L('loading', 'Loading...')) + '</div>';
            api('list.php?folder=' + id).then(function (r) { data = r; render(); }).catch(function (e) { fail(e); if (id) load(0); });
        }
        function render() {
            var d = data;
            var html = '<div class="fdm-bar">' +
                (d.folder ? '<button type="button" class="fdm-back" data-act="up">' + IC.back + '</button>' : '') +
                '<div class="fdm-title"></div>' +
                (d.folder && d.can.upload ? '<button type="button" class="fdm-btn" data-act="upload">' + IC.upload + '</button>' : '') +
                (d.can.upload ? '<button type="button" class="fdm-btn" data-act="newfolder">' + IC.newFolder + '</button>' : '') +
                '</div><div class="fdm-list">';
            d.folders.forEach(function (f) {
                html += '<button type="button" class="fdm-row" data-f="' + f.id + '">' + FI.folder(28) + '<span class="fdm-name">' + esc(f.name) +
                    (f.path ? '<span class="fdm-meta">' + esc(f.path) + '</span>' : '') + '</span></button>';
            });
            d.items.forEach(function (i) {
                html += '<button type="button" class="fdm-row" data-i="' + i.id + '">' + FI.file(i.name, 28) + '<span class="fdm-name">' + esc(i.name) +
                    '<span class="fdm-meta">' + esc(fmtSize(i.size) + ' - ' + fmtWhen(i.modified)) + '</span></span></button>';
            });
            if (!d.folders.length && !d.items.length) {
                html += '<div class="fd-pad fd-muted">' + esc(d.folder ? L('ex.empty_folder', 'This folder is empty.') : L('ex.empty_root', 'Nothing has been shared with you yet.')) + '</div>';
            }
            box.innerHTML = html + '</div>';
            box.querySelector('.fdm-title').textContent = d.folder ? d.folder.name : L('app.documents', 'Documents');
        }
        box.addEventListener('click', function (ev) {
            var a = ev.target.closest('[data-act]');
            if (a) {
                if (a.dataset.act === 'up') load(data.folder.parent || 0);
                if (a.dataset.act === 'upload') Uploads.pick(cur);
                if (a.dataset.act === 'newfolder') {
                    WM.dialog({ title: L('ex.new_folder', 'New folder'), input: { value: L('ex.new_folder', 'New folder') },
                        buttons: [{ label: L('btn.create', 'Create'), value: true, primary: true }, { label: L('btn.cancel', 'Cancel'), value: false, cancel: true }] })
                        .then(function (r) { if (r.button) api('folder.php', { action: 'create', parent_id: cur, name: r.value }).then(function () { load(cur); }).catch(fail); });
                }
                return;
            }
            var f = ev.target.closest('[data-f]');
            if (f) { load(+f.dataset.f); return; }
            var i = ev.target.closest('[data-i]');
            if (i) {
                var lvl = data.folder ? data.folder.level : 0;
                viewFile(+i.dataset.i);
            }
        });
        return { load: load, current: function () { return cur; } };
    })();

    // ── Start ──────────────────────────────────────────────────────────────
    renderDesktopIcons();
    var phone = window.matchMedia('(max-width: 768px)');
    if (phone.matches) Mobile.load(0);
    else new Explorer(0);
    phone.addEventListener && phone.addEventListener('change', function (e) { if (e.matches) Mobile.load(0); });

    // Uploads finishing refresh the phone list too.
    explorers.push({ onChanged: function () { if (phone.matches) Mobile.load(Mobile.current()); }, markCut: function () {} });

    window.FilesDesktop = { explorer: function (id) { return new Explorer(id || 0); }, uploads: Uploads };
})();
