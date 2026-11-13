/**
 * Files → Settings (files/settings/index.php). Both tabs read and write through
 * api/files/settings.php; Take ownership is api/files/permissions.php.
 *
 * @wiki Files-Developer-Guide
 */
(function () {
    'use strict';
    var API = window.FILES_API;
    /** Translates a Files string (key under 'files.'), falling back to the English when tf() is not loaded. */
    function L(k, en, p) { return window.tf ? window.tf('files.' + k, en, p) : en; }
    /** Escapes a value for HTML, treating null as an empty string. */
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    /** Calls an api/files endpoint - a GET with no body, a JSON POST with one - and rejects on success: false. */
    function api(path, body) {
        return fetch(API + path, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {})
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j.success) throw new Error(j.error || 'Error'); return j; });
    }
    /** Formats a byte count as B, KB, MB, GB or TB. */
    function fmtSize(n) {
        if (n < 1024) return n + ' B';
        var u = ['KB', 'MB', 'GB', 'TB'], i = -1;
        do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
        return n.toFixed(n >= 10 ? 1 : 2) + ' ' + u[i];
    }

    /** Shows one settings tab and puts it in the address (?tab=) so a reload opens the same tab. */
    window.switchTab = function (id) {
        document.querySelectorAll('.tab').forEach(function (t) { t.classList.toggle('active', t.dataset.tab === id); });
        document.querySelectorAll('.tab-content').forEach(function (c) { c.classList.toggle('active', c.id === id + '-tab'); });
        history.replaceState(null, '', '?tab=' + encodeURIComponent(id));
    };

    var LEVELS = { 0: L('settings.no_access', 'None'), 1: L('level.view', 'View'), 2: L('level.download', 'Download'), 3: L('level.upload', 'Upload'), 4: L('level.modify', 'Modify'), 5: L('level.full', 'Full control') };

    // ── Folders ────────────────────────────────────────────────────────────
    var folBody = document.getElementById('folBody');
    var folders = [];
    /** Fetches every folder for the Folders tab and draws the table, or shows the error in it. */
    function loadFolders() {
        if (!folBody) return;
        api('settings.php?tab=folders').then(function (r) { folders = r.folders; renderFolders(); })
            .catch(function (e) { folBody.innerHTML = '<tr><td colspan="6">' + esc(e.message) + '</td></tr>'; });
    }
    /**
     * Draws the Folders table, filtered by the path box.
     *
     * A folder with no entries of its own and nothing to inherit is marked as one nobody can reach,
     * and every folder where the viewer is short of Full control gets a Take ownership button.
     */
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
            /**
             * Takes ownership of the folder (Full control, audited by the server) once confirmed, then reloads the table.
             */
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
    // A server folder or S3-compatible storage. Changing the location starts a
    // background move (the storage_migrate job); its progress shows in #stoMove.
    var stoRoot = document.getElementById('stoRoot');
    if (stoRoot) {
        var $ = function (id) { return document.getElementById(id); };
        var state = null;          // the last GET ?tab=storage
        var pollTimer = null;

        /** The storage type the radio buttons say. */
        var chosenType = function () {
            var r = document.querySelector('input[name="stoType"]:checked');
            return r ? r.value : 'local';
        };
        /** The S3 fields as the server expects them. */
        var s3Values = function () {
            return { endpoint: $('s3Endpoint').value.trim(), region: $('s3Region').value.trim(), bucket: $('s3Bucket').value.trim(),
                     access_key_id: $('s3Key').value.trim(), secret: $('s3Secret').value, prefix: $('s3Prefix').value.trim(),
                     path_style: $('s3PathStyle').checked };
        };
        /** Shows the S3 block, and relabels the folder as the working folder, when S3 is chosen. */
        var showType = function () {
            var s3 = chosenType() === 's3';
            $('stoS3').hidden = !s3;
            $('stoRootLbl').textContent = s3 ? L('settings.working_root', 'Local working folder') : L('settings.storage_root', 'Storage folder');
            $('stoRootDesc').textContent = s3 ? L('settings.working_root_desc', 'Uploads are assembled here before they are sent to S3.')
                                              : L('settings.storage_root_desc', 'A folder on the server the web server can write to.');
        };
        /** True when the form describes another location than the saved one (so saving moves files). */
        var locationChanged = function () {
            if (!state) return false;
            var t = chosenType();
            if (t !== state.driver) return true;
            // Windows paths: the server stores them with forward slashes.
            if (t === 'local') return (stoRoot.value.trim() || state.default_root).replace(/\\/g, '/').replace(/\/+$/, '') !== state.storage_root;
            var v = s3Values(), o = state.s3;
            var norm = function (p) { return p.split('/').filter(Boolean).join('/'); };
            return v.endpoint.replace(/\/+$/, '') !== o.endpoint || v.bucket !== o.bucket || norm(v.prefix) !== norm(o.prefix);
        };

        /** Draws the status box and the move panel from the server's reply. */
        var render = function (r) {
            state = r;
            var where = r.driver === 's3'
                ? esc(L('settings.storage_s3_now', 'Files are stored in S3-compatible storage at')) + ' <code>' + esc(r.location) + '</code>. '
                : esc(L('settings.storage_now', 'Files are stored in')) + ' <code>' + esc(r.storage_root) + '</code>. ';
            $('stoState').innerHTML = where +
                esc(L('settings.storage_usage', '{n} stored files, {size} in all.', { n: r.stored_files, size: fmtSize(r.stored_bytes) })) +
                (r.free_bytes ? ' ' + esc(L('settings.storage_free', '{size} free on that disk.', { size: fmtSize(r.free_bytes) })) : '') +
                (r.writable ? '' : ' <strong>' + esc(L('settings.not_writable', 'The web server cannot write there - uploads will fail.')) + '</strong>');

            var m = r.migration;
            $('stoMove').hidden = !m;
            $('stoLocked').hidden = !m;
            document.querySelectorAll('input[name="stoType"], #stoRoot, #s3Endpoint, #s3Bucket, #s3Prefix').forEach(function (el) { el.disabled = !!m; });
            clearTimeout(pollTimer);
            if (!m) return;
            var done = m.verified + m.removed + m.skipped;
            var text = m.cleanup_running ? L('settings.move_removing', 'Removing the old copies from {from}…', { from: m.from_label })
                : (m.pending === 0 && m.failed === 0) ? L('settings.move_done', 'Every file has been copied from {from}. The old copies are still there until you remove them.', { from: m.from_label })
                : L('settings.move_progress', 'Moving files from {from}: {done} of {total} copied, {failed} failed.', { from: m.from_label, done: done, total: m.total, failed: m.failed });
            $('stoMoveText').innerHTML = esc(text) +
                ((m.pending > 0 || m.cleanup_running) && r.scheduler_running === false
                    ? '<div class="dflt"><strong>' + esc(L('settings.move_no_scheduler', 'Background jobs are not running, so the move is not progressing. See Reporting -> System logs -> Background jobs.')) + '</strong></div>' : '') +
                (m.skipped ? '<div class="dflt">' + esc(L('settings.move_skipped', '{n} skipped: their old copy was already missing before the move.', { n: m.skipped })) + '</div>' : '') +
                (m.last_error ? '<div class="dflt">' + esc(L('settings.move_last_error', 'Last error: {error}', { error: m.last_error })) + '</div>' : '');
            var copiedAll = m.pending === 0 && m.failed === 0;
            $('stoRetry').hidden = !(m.failed > 0) || m.cleanup_running;
            $('stoSkip').hidden = !(m.failed > 0) || m.cleanup_running;
            $('stoRemoveOld').hidden = !copiedAll || m.cleanup_running;
            $('stoFinishKeep').hidden = !copiedAll || m.cleanup_running;
            if (m.pending > 0 || m.cleanup_running) pollTimer = setTimeout(load, 10000);
        };
        /** Fetches the storage settings and fills the form (the form only on the first load). */
        var load = function (fillForm) {
            return api('settings.php?tab=storage').then(function (r) {
                if (fillForm) {
                    stoRoot.value = r.is_default ? '' : r.storage_root;
                    stoRoot.placeholder = r.default_root;
                    $('stoRootDefault').textContent = L('settings.storage_default', 'Leave empty for the default: {path}', { path: r.default_root });
                    $('stoMax').value = r.max_upload_mb;
                    document.querySelector('input[name="stoType"][value="' + r.driver + '"]').checked = true;
                    $('s3Endpoint').value = r.s3.endpoint;
                    $('s3Region').value = r.s3.region;
                    $('s3Bucket').value = r.s3.bucket;
                    $('s3Key').value = r.s3.access_key_id;
                    $('s3Secret').value = r.s3.secret_set ? '********' : '';
                    $('s3Prefix').value = r.s3.prefix;
                    $('s3PathStyle').checked = !!r.s3.path_style;
                    showType();
                }
                render(r);
            }).catch(function (e) { $('stoState').textContent = e.message; });
        };
        /** Asks first (in the app's dialog when there is one), then runs go(). */
        var confirmThen = function (title, msg, ok, go) {
            if (window.showConfirm) window.showConfirm({ title: title, message: msg, okLabel: ok, onConfirm: go });
            else if (confirm(msg)) go();
        };

        document.querySelectorAll('input[name="stoType"]').forEach(function (r) { r.addEventListener('change', showType); });

        $('s3Test').addEventListener('click', function () {
            var msg = $('s3TestMsg'), b = this;
            b.disabled = true;
            msg.style.color = '';
            msg.textContent = L('settings.testing', 'Testing…');
            api('settings.php', { tab: 'storage_test', s3: s3Values() }).then(function (r) {
                msg.style.color = r.ok ? 'var(--success-text)' : 'var(--danger-text)';
                msg.textContent = r.ok ? L('settings.test_ok', '✓ Connected - wrote, read and deleted a test file.')
                                       : L('settings.test_failed', '✗ Failed at {step}: {error}', { step: r.step, error: r.error });
            }).catch(function (e) { msg.style.color = 'var(--danger-text)'; msg.textContent = e.message; })
              .then(function () { b.disabled = false; });
        });

        $('stoSave').addEventListener('click', function () {
            var msg = $('stoMsg'), b = this;
            var save = function () {
                b.disabled = true;
                msg.style.color = '';
                msg.textContent = chosenType() === 's3' ? L('settings.testing', 'Testing…') : '';
                api('settings.php', { tab: 'storage', driver: chosenType(), storage_root: stoRoot.value.trim(),
                                      max_upload_mb: +$('stoMax').value, s3: s3Values() })
                    .then(function () { msg.style.color = 'var(--success-text)'; msg.textContent = L('settings.saved', 'Saved.'); return load(false); })
                    .catch(function (e) { msg.style.color = 'var(--danger-text)'; msg.textContent = e.message; })
                    .then(function () { b.disabled = false; });
            };
            // Fresh counts first: files uploaded since the page opened count too.
            api('settings.php?tab=storage').then(function (r) { state = r; }, function () {}).then(function () {
                if (locationChanged() && state && state.stored_files > 0) {
                    confirmThen(L('settings.move_confirm_title', 'Move files'),
                        L('settings.move_confirm', 'Existing files ({n}, {size}) will be copied to the new location in the background. They stay readable throughout. Continue?',
                          { n: state.stored_files, size: fmtSize(state.stored_bytes) }),
                        L('btn.save', 'Save'), save);
                } else {
                    save();
                }
            });
        });

        $('stoRetry').addEventListener('click', function () {
            api('settings.php', { tab: 'storage_migration', action: 'retry_failed' }).then(function () { return load(false); })
                .catch(function (e) { alert(e.message); });
        });
        $('stoSkip').addEventListener('click', function () {
            api('settings.php', { tab: 'storage_migration', action: 'skip_missing' }).then(function () { return load(false); })
                .catch(function (e) { alert(e.message); });
        });
        $('stoFinishKeep').addEventListener('click', function () {
            api('settings.php', { tab: 'storage_migration', action: 'finish_keep' }).then(function () { return load(false); })
                .catch(function (e) { alert(e.message); });
        });
        $('stoRemoveOld').addEventListener('click', function () {
            var from = state && state.migration ? state.migration.from_label : '';
            confirmThen(L('settings.remove_old', 'Remove old copies'),
                L('settings.remove_old_confirm', 'This permanently deletes the copies at {from}. Continue?', { from: from }),
                L('settings.remove_old', 'Remove old copies'), function () {
                    api('settings.php', { tab: 'storage_migration', action: 'remove_old' }).then(function () { return load(false); })
                        .catch(function (e) { alert(e.message); });
                });
        });

        load(true);
    }
})();
