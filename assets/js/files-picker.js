/**
 * Pick a file from Files in a window that feels like a file dialog (GH #303): folders
 * down the left, the folder's files on the right, a search box, and Link / Copy - in
 * a draggable, resizable floating window (FloatWin.page(), as the viewer uses).
 *
 *   FilesPicker.open({ mode: 'link'|'copy'|'both', title?, onPick: function (itemId, how, name) {} })
 *
 * What it shows comes from Files' own endpoints, so it is only ever what the person
 * could see in Files: api/files/list.php (folders and files, with the person's level
 * on each folder) and, for a search across everything, api/documents/find.php's Files
 * results. Copy is offered only with Download on the folder. Whatever is picked is
 * checked again by api/documents/attach.php when it is attached.
 *
 * @wiki Attach-From-Files-Developer-Guide
 */
(function () {
    'use strict';
    var BASE = (document.currentScript && document.currentScript.src || '').replace(/assets\/js\/files-picker\.js.*$/, '');
    var LIST = BASE + 'api/files/list.php';
    var LV_DOWNLOAD = 2;

    /** A translated string under common.files_picker, English as the fallback. */
    function T(key, en, params) {
        var full = 'common.files_picker.' + key;
        var got = window.t ? window.t(full, params) : full;
        if (got === full) {
            got = en;
            if (params) Object.keys(params).forEach(function (k) { got = got.split('{' + k + '}').join(params[k]); });
        }
        return got;
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function getJson(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); })
            .then(function (j) { if (!j.success) throw new Error(j.error || 'Error'); return j; });
    }
    function fmtSize(n) {
        if (!n) return '';
        if (n < 1024) return n + ' B';
        var u = ['KB', 'MB', 'GB'], i = -1;
        do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
        return n.toFixed(n >= 10 ? 0 : 1) + ' ' + u[i];
    }
    function fmtWhen(s) { return s ? (window.fmtDateTime ? window.fmtDateTime(s) : s) : ''; }
    function fileIcon(name) { return window.FilesIcons ? window.FilesIcons.file(name, 16) : ''; }
    function folderIcon() { return window.FilesIcons ? window.FilesIcons.folder(16) : ''; }

    /** Opens the picker window. */
    function open(o) {
        var mode = o.mode === 'copy' || o.mode === 'both' ? o.mode : 'link';
        var w = window.FloatWin.page().open({
            kind: 'files-picker', key: 'picker', single: true,
            title: o.title || T('title', 'Choose a file from Files'),
            icon: window.FilesIcons ? window.FilesIcons.app('files', 16) : '',
            width: 760, height: 480, x: 160, y: 60
        });
        if (w.picker) return w;
        w.picker = true;
        w.body.classList.add('fpk');
        w.body.innerHTML =
            '<div class="fpk-bar"><span class="fpk-path"></span><input type="search" class="fpk-search" placeholder="' + esc(T('search', 'Search all of Files')) + '"></div>' +
            '<div class="fpk-main">' +
                '<div class="fpk-tree" role="tree"></div>' +
                '<div class="fpk-list"><table><thead><tr><th>' + esc(T('col_name', 'Name')) + '</th><th class="fpk-num">' + esc(T('col_size', 'Size')) + '</th><th>' + esc(T('col_modified', 'Modified')) + '</th></tr></thead><tbody></tbody></table><div class="fpk-empty" hidden></div></div>' +
            '</div>' +
            '<div class="fpk-foot"><span class="fpk-sel"></span><span class="fpk-grow"></span></div>';
        var tree = w.body.querySelector('.fpk-tree'), tbody = w.body.querySelector('tbody'), empty = w.body.querySelector('.fpk-empty');
        var pathEl = w.body.querySelector('.fpk-path'), search = w.body.querySelector('.fpk-search');
        var selEl = w.body.querySelector('.fpk-sel'), foot = w.body.querySelector('.fpk-foot');
        var selected = null;   // { item_id, name, level }

        // ── the footer buttons ────────────────────────────────────────────
        var btn = function (label, cls, run) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'fpk-btn' + (cls ? ' ' + cls : ''); b.textContent = label;
            b.addEventListener('click', run); foot.appendChild(b); return b;
        };
        var linkBtn = mode !== 'copy' ? btn(T('link', 'Link'), 'fpk-primary', function () { pick('link'); }) : null;
        var copyBtn = mode !== 'link' ? btn(T('copy', 'Copy'), mode === 'copy' ? 'fpk-primary' : '', function () { pick('copy'); }) : null;
        btn(T('cancel', 'Cancel'), '', function () { w.close(); });
        if (linkBtn) linkBtn.title = T('link_tip', 'A link: opened through Files, under its folder\'s permissions, always the latest version');
        if (copyBtn) copyBtn.title = T('copy_tip', 'A copy of the current version, readable by everyone who can see it');

        /** Enables the buttons for what is selected - Copy only with Download on its folder. */
        function refreshButtons() {
            if (linkBtn) linkBtn.disabled = !selected;
            if (copyBtn) copyBtn.disabled = !selected || selected.level < LV_DOWNLOAD;
            selEl.textContent = selected ? selected.name : T('none_selected', 'Choose a file');
        }
        /** Hands the selection to the caller and closes. */
        function pick(how) {
            if (!selected) return;
            if (how === 'copy' && selected.level < LV_DOWNLOAD) return;
            o.onPick(selected.item_id, how, selected.name);
            w.close();
        }
        /** The default for a double-click: a link when offered, else a copy. */
        function pickDefault() { if (linkBtn) pick('link'); else pick('copy'); }

        // ── the file list ──────────────────────────────────────────────────
        function showRows(rows, withPath) {
            selected = null; refreshButtons();
            if (!rows.length) { tbody.innerHTML = ''; empty.hidden = false; empty.textContent = T(withPath ? 'no_match' : 'empty', withPath ? 'No files match that you can open.' : 'This folder has no files.'); return; }
            empty.hidden = true;
            tbody.innerHTML = rows.map(function (r, i) {
                return '<tr data-i="' + i + '" tabindex="0"><td><span class="fpk-ic">' + fileIcon(r.name) + '</span>' + esc(r.name) +
                       (withPath && r.path ? '<div class="fpk-sub">' + esc(r.path) + '</div>' : '') + '</td>' +
                       '<td class="fpk-num">' + esc(fmtSize(r.size)) + '</td><td>' + esc(fmtWhen(r.modified)) + '</td></tr>';
            }).join('');
            Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr) {
                var r = rows[+tr.dataset.i];
                var choose = function () {
                    Array.prototype.forEach.call(tbody.querySelectorAll('tr.fpk-on'), function (x) { x.classList.remove('fpk-on'); });
                    tr.classList.add('fpk-on');
                    selected = { item_id: r.id, name: r.name, level: r.level };
                    refreshButtons();
                };
                tr.addEventListener('click', choose);
                tr.addEventListener('dblclick', function () { choose(); pickDefault(); });
                tr.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { choose(); pickDefault(); } });
            });
        }
        /** Opens a folder: its files on the right, its name in the bar. */
        function openFolder(id, name, level) {
            search.value = '';
            pathEl.textContent = name;
            Array.prototype.forEach.call(tree.querySelectorAll('.fpk-node.fpk-cur'), function (x) { x.classList.remove('fpk-cur'); });
            var node = tree.querySelector('.fpk-node[data-id="' + id + '"]');
            if (node) node.classList.add('fpk-cur');
            getJson(LIST + '?folder=' + id).then(function (r) {
                showRows((r.items || []).map(function (it) { return { id: it.id, name: it.name, size: it.size, modified: it.modified, level: level }; }), false);
            }).catch(function (e) { tbody.innerHTML = ''; empty.hidden = false; empty.textContent = e.message; });
        }

        // ── the folder tree (expands on click, like Explorer) ──────────────
        function branch(parentEl, parentId) {
            getJson(LIST + '?folder=' + parentId + '&tree=1').then(function (r) {
                var ul = document.createElement('ul');
                (r.folders || []).forEach(function (f) {
                    var li = document.createElement('li');
                    li.innerHTML = '<div class="fpk-node" data-id="' + f.id + '" role="treeitem" tabindex="0" title="' + esc(f.path || f.name) + '">' +
                        '<span class="fpk-twist">' + (f.has_children ? '&#9656;' : '') + '</span>' +
                        '<span class="fpk-ic">' + folderIcon() + '</span><span class="fpk-name">' + esc(f.name) + '</span></div>';
                    var node = li.firstChild, twist = node.querySelector('.fpk-twist'), opened = false;
                    var toggle = function () {
                        if (!f.has_children) return;
                        if (!opened) { branch(li, f.id); opened = true; twist.innerHTML = '&#9662;'; return; }
                        var sub = li.querySelector('ul');
                        if (sub) { sub.hidden = !sub.hidden; twist.innerHTML = sub.hidden ? '&#9656;' : '&#9662;'; }
                    };
                    twist.addEventListener('click', function (ev) { ev.stopPropagation(); toggle(); });
                    node.addEventListener('click', function () { openFolder(f.id, f.path || f.name, f.level); if (!opened) toggle(); });
                    node.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { openFolder(f.id, f.path || f.name, f.level); toggle(); } });
                    ul.appendChild(li);
                });
                if (!(r.folders || []).length && parentId === 0) {
                    tree.innerHTML = '<div class="fpk-empty">' + esc(T('no_folders', 'There is no folder in Files you can open.')) + '</div>';
                    return;
                }
                parentEl.appendChild(ul);
            }).catch(function (e) { parentEl.insertAdjacentHTML('beforeend', '<div class="fpk-empty">' + esc(e.message) + '</div>'); });
        }

        // ── search across all of Files (the same search as the Documents panel's) ──
        var timer = null;
        search.addEventListener('input', function () {
            clearTimeout(timer);
            var q = search.value.trim();
            if (q.length < 2) return;
            timer = setTimeout(function () {
                pathEl.textContent = T('results_for', 'Results for "{q}"', { q: q });
                getJson(BASE + 'api/documents/find.php?q=' + encodeURIComponent(q) + '&parent_type=' + encodeURIComponent(o.parentType) + '&parent_id=' + encodeURIComponent(o.parentId))
                    .then(function (r) {
                        showRows((r.files || []).map(function (f) { return { id: f.item_id, name: f.name, size: f.size, path: f.path, level: f.can_copy ? LV_DOWNLOAD : 1 }; }), true);
                    }).catch(function (e) { tbody.innerHTML = ''; empty.hidden = false; empty.textContent = e.message; });
            }, 250);
        });

        pathEl.textContent = T('documents', 'Documents');
        showRows([], false);
        empty.textContent = T('pick_folder', 'Open a folder on the left, or search.');
        refreshButtons();
        branch(tree, 0);
        setTimeout(function () { search.focus(); }, 0);
        return w;
    }

    window.FilesPicker = { open: open };
})();
