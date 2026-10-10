/**
 * Files → Settings (files/settings/index.php). Both tabs read and write through
 * api/files/settings.php; Take ownership is api/files/permissions.php.
 */
(function () {
    'use strict';
    var API = window.FILES_API;
    function L(k, en, p) { return window.tf ? window.tf('files.' + k, en, p) : en; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function api(path, body) {
        return fetch(API + path, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {})
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j.success) throw new Error(j.error || 'Error'); return j; });
    }
    function fmtSize(n) {
        if (n < 1024) return n + ' B';
        var u = ['KB', 'MB', 'GB', 'TB'], i = -1;
        do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
        return n.toFixed(n >= 10 ? 1 : 2) + ' ' + u[i];
    }

    window.switchTab = function (id) {
        document.querySelectorAll('.tab').forEach(function (t) { t.classList.toggle('active', t.dataset.tab === id); });
        document.querySelectorAll('.tab-content').forEach(function (c) { c.classList.toggle('active', c.id === id + '-tab'); });
        history.replaceState(null, '', '?tab=' + encodeURIComponent(id));
    };

    var LEVELS = { 0: L('settings.no_access', 'None'), 1: L('level.view', 'View'), 2: L('level.download', 'Download'), 3: L('level.upload', 'Upload'), 4: L('level.modify', 'Modify'), 5: L('level.full', 'Full control') };

    // ── Folders ────────────────────────────────────────────────────────────
    var folBody = document.getElementById('folBody');
    var folders = [];
    function loadFolders() {
        if (!folBody) return;
        api('settings.php?tab=folders').then(function (r) { folders = r.folders; renderFolders(); })
            .catch(function (e) { folBody.innerHTML = '<tr><td colspan="6">' + esc(e.message) + '</td></tr>'; });
    }
    function renderFolders() {
        var q = (document.getElementById('folFilter').value || '').toLowerCase();
        var rows = folders.filter(function (f) { return !q || f.path.toLowerCase().indexOf(q) >= 0; });
        if (!rows.length) { folBody.innerHTML = '<tr><td colspan="6" style="color:var(--text-dim)">' + esc(L('settings.no_folders', 'No folders yet.')) + '</td></tr>'; return; }
        folBody.innerHTML = rows.map(function (f) {
            // No entries of its own and nothing to inherit: nobody can reach it.
            var orphan = f.entries === 0 && (f.top || !f.inherit);
            return '<tr' + (orphan ? ' class="orphan"' : '') + '><td>' + esc(f.path) + (orphan ? ' - ' + esc(L('settings.orphan', 'nobody has access')) : '') + '</td>' +
                '<td>' + f.entries + '</td><td>' + f.files + '</td><td>' + (f.top ? '-' : esc(f.inherit ? L('yes', 'Yes') : L('no', 'No'))) + '</td>' +
                '<td><span class="fil-pill' + (f.my_level === 5 ? ' full' : '') + '">' + esc(LEVELS[f.my_level]) + '</span></td>' +
                '<td style="text-align:right">' + (f.my_level < 5 ? '<button type="button" class="fil-btn" data-own="' + f.id + '">' + esc(L('settings.take', 'Take ownership')) + '</button>' : '') + '</td></tr>';
        }).join('');
    }
    if (folBody) {
        document.getElementById('folFilter').addEventListener('input', renderFolders);
        folBody.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-own]');
            if (!b) return;
            var f = folders.filter(function (x) { return x.id === +b.dataset.own; })[0];
            var go = function () {
                b.disabled = true;
                api('permissions.php', { action: 'take_ownership', folder: f.id }).then(loadFolders).catch(function (e) { alert(e.message); b.disabled = false; });
            };
            var msg = L('settings.take_confirm', 'Give yourself Full control of "{path}"? You will be able to open every file in it. This is recorded in the audit trail with your name.', { path: f.path });
            if (window.showConfirm) window.showConfirm({ title: L('settings.take', 'Take ownership'), message: msg, okLabel: L('settings.take_ok', 'Take'), onConfirm: go });
            else if (confirm(msg)) go();
        });
        loadFolders();
    }

    // ── Storage ────────────────────────────────────────────────────────────
    var stoRoot = document.getElementById('stoRoot');
    if (stoRoot) {
        api('settings.php?tab=storage').then(function (r) {
            stoRoot.value = r.is_default ? '' : r.storage_root;
            stoRoot.placeholder = r.default_root;
            document.getElementById('stoRootDefault').textContent = L('settings.storage_default', 'Leave empty for the default: {path}', { path: r.default_root });
            document.getElementById('stoMax').value = r.max_upload_mb;
            document.getElementById('stoState').innerHTML =
                esc(L('settings.storage_now', 'Files are stored in')) + ' <code>' + esc(r.storage_root) + '</code>. ' +
                esc(L('settings.storage_usage', '{n} stored files, {size} in all.', { n: r.stored_files, size: fmtSize(r.stored_bytes) })) +
                (r.free_bytes ? ' ' + esc(L('settings.storage_free', '{size} free on that disk.', { size: fmtSize(r.free_bytes) })) : '') +
                (r.writable ? '' : ' <strong>' + esc(L('settings.not_writable', 'The web server cannot write there - uploads will fail.')) + '</strong>');
        }).catch(function (e) { document.getElementById('stoState').textContent = e.message; });
        document.getElementById('stoSave').addEventListener('click', function () {
            var msg = document.getElementById('stoMsg');
            msg.textContent = '';
            api('settings.php', { tab: 'storage', storage_root: stoRoot.value.trim(), max_upload_mb: +document.getElementById('stoMax').value })
                .then(function () { msg.style.color = 'var(--success-text)'; msg.textContent = L('settings.saved', 'Saved.'); })
                .catch(function (e) { msg.style.color = 'var(--danger-text)'; msg.textContent = e.message; });
        });
    }
})();
