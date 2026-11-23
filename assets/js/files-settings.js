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

    // ── Sharing (GH #303) ──────────────────────────────────────────────────
    var shrBox = document.getElementById('shrModes');
    if (shrBox) {
        var shrMsg = document.getElementById('shrMsg');
        var shrFolder = document.getElementById('shrSaveFolder'), shrTicket = document.getElementById('shrTicketFolder');
        api('settings.php?tab=sharing').then(function (r) {
            var on = shrBox.querySelector('input[value="' + r.mode + '"]');
            if (on) on.checked = true;
            shrFolder.innerHTML = '<option value="0">' + esc(L('settings.save_folder_none', 'None - choose each time')) + '</option>' +
                r.folders.map(function (f) { return '<option value="' + f.id + '">' + esc(f.path) + '</option>'; }).join('');
            shrFolder.value = String(r.default_folder || 0);
            shrTicket.checked = !!r.ticket_folder;
        }).catch(function (e) { shrMsg.textContent = e.message; });
        document.getElementById('shrSave').addEventListener('click', function () {
            var picked = shrBox.querySelector('input:checked');
            shrMsg.style.color = '';
            shrMsg.textContent = '';
            api('settings.php', { tab: 'sharing', mode: picked ? picked.value : '', save_folder: parseInt(shrFolder.value, 10) || 0, save_ticket_folder: shrTicket.checked })
                .then(function () { shrMsg.style.color = 'var(--success-text, #166534)'; shrMsg.textContent = L('settings.saved', 'Saved'); })
                .catch(function (e) { shrMsg.style.color = 'var(--danger-text, #991b1b)'; shrMsg.textContent = e.message; });
        });
    }

    // ── Versions (GH #303) ─────────────────────────────────────────────────
    var verBox = document.getElementById('verChoices');
    if (verBox) {
        var verDefault = document.getElementById('verDefault'), verKeep = document.getElementById('verKeep'), verMsg = document.getElementById('verMsg');
        var CHOICE = { version: L('settings.choice_version', 'New version'), rename: L('settings.choice_rename', 'Keep both'), overwrite: L('settings.choice_overwrite', 'Overwrite') };
        /** Fills the highlighted-choice dropdown from the ticked choices (never Skip), keeping the current pick where it is still ticked. */
        var fillDefault = function (want) {
            var ticked = Array.prototype.filter.call(verBox.querySelectorAll('input'), function (i) { return i.checked && i.value !== 'skip'; }).map(function (i) { return i.value; });
            var keep = want || verDefault.value;
            verDefault.innerHTML = ticked.map(function (c) { return '<option value="' + esc(c) + '">' + esc(CHOICE[c]) + '</option>'; }).join('');
            if (ticked.indexOf(keep) >= 0) verDefault.value = keep;
        };
        verBox.addEventListener('change', function () { fillDefault(); });
        api('settings.php?tab=versions').then(function (r) {
            verBox.querySelectorAll('input').forEach(function (i) { i.checked = r.choices.indexOf(i.value) >= 0; });
            fillDefault(r.default);
            verKeep.innerHTML = r.keep_choices.map(function (n) {
                return '<option value="' + n + '">' + esc(n === 0 ? L('settings.keep_all', 'Every version') : L('settings.keep_n', 'The newest {n}', { n: n })) + '</option>';
            }).join('');
            verKeep.value = String(r.keep);
        }).catch(function (e) { verMsg.textContent = e.message; });
        document.getElementById('verSave').addEventListener('click', function () {
            var choices = Array.prototype.filter.call(verBox.querySelectorAll('input'), function (i) { return i.checked; }).map(function (i) { return i.value; });
            verMsg.style.color = '';
            verMsg.textContent = '';
            api('settings.php', { tab: 'versions', choices: choices, default: verDefault.value, keep: parseInt(verKeep.value, 10) })
                .then(function () { verMsg.style.color = 'var(--success-text, #166534)'; verMsg.textContent = L('settings.saved', 'Saved'); })
                .catch(function (e) { verMsg.style.color = 'var(--danger-text, #991b1b)'; verMsg.textContent = e.message; });
        });
    }

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
                (r.recycle && r.recycle.files ? ' ' + esc(L('settings.storage_in_bin', 'In the recycle bin (freed when it is emptied): {n}, {size}.', { n: r.recycle.files, size: fmtSize(r.recycle.bytes) })) : '') +
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
        var confirmThen = function (title, msg, ok, go, danger) {
            if (window.showConfirm) window.showConfirm({ title: title, message: msg, okLabel: ok, okClass: danger ? 'danger' : undefined, onConfirm: go });
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
                }, true);   // deletes for good: the red button
        });

        load(true);
    }

    // ── Recycle bin ────────────────────────────────────────────────────────
    // How long deleted things are kept. Nothing is purged until this is saved
    // once (an upgrade never deletes anything by itself).
    var rbDays = document.getElementById('rbDays');
    if (rbDays) {
        /** Draws the state box and the field from the server's reply. */
        var rbRender = function (r) {
            rbDays.value = r.days;
            rbDays.max = r.max_days;
            document.getElementById('rbNotSet').hidden = r.configured;
            document.getElementById('rbState').textContent =
                L('settings.recycle_usage', 'Items in the recycle bin: {n} (using {size} of storage).', { n: r.entries, size: fmtSize(r.bin_bytes) }) +
                (!r.configured ? ' ' + L('settings.recycle_off', 'Nothing is deleted automatically until this setting is saved.')
                    : r.days === 0 ? ' ' + L('settings.recycle_forever', 'Deleted items are kept until someone deletes them permanently.') : '') +
                (r.pending_bytes ? ' ' + L('settings.recycle_pending', 'Stored files waiting to be removed because storage could not be reached: {n}.', { n: r.pending_bytes })
                    // null: the queue's table is missing (before Database Verification) - unknown, never "none".
                    : r.pending_bytes === null ? ' ' + L('settings.recycle_pending_unknown', 'Stored files waiting to be removed: unknown until Database Verification has been run.') : '') +
                // Storage answered and said no, again and again: someone has to look at it.
                (r.refused_bytes ? ' ' + L('settings.recycle_refused', 'Stored files storage keeps refusing to delete, now tried again once a day: {n}. The PHP error log says why.', { n: r.refused_bytes }) : '') +
                (r.held ? ' ' + L('settings.recycle_held', 'Deleted folders kept past this period because whoever deleted them could not delete everything in them: {n}. An administrator can see them in System -> Maintenance -> Deleted folders kept.', { n: r.held }) : '');
        };
        api('settings.php?tab=recycle').then(rbRender).catch(function (e) { document.getElementById('rbState').textContent = e.message; });
        document.getElementById('rbSave').addEventListener('click', function () {
            var msg = document.getElementById('rbMsg'), b = this;
            var days = rbDays.value === '' ? -1 : +rbDays.value;
            /** Saves the period and redraws the tab. */
            var save = function () {
                b.disabled = true;
                msg.style.color = '';
                msg.textContent = '';
                api('settings.php', { tab: 'recycle', days: days })
                    .then(function (r) { rbRender(r); msg.style.color = 'var(--success-text)'; msg.textContent = L('settings.saved', 'Saved.'); })
                    .catch(function (e) { msg.style.color = 'var(--danger-text)'; msg.textContent = e.message; })
                    .then(function () { b.disabled = false; });
            };
            if (!(days > 0)) { save(); return; }
            // Items already older than the new period go at the next run (within the hour):
            // say how many first - on the first save that can be everything deleted in the past.
            api('settings.php?tab=recycle&days=' + days).then(function (r) {
                if (!r.due) { save(); return; }
                var q = L('settings.recycle_due_confirm', 'Items already in the recycle bin longer than this will be deleted permanently within the hour, with their stored files (Items: {n}). Continue?', { n: r.due });
                if (window.showConfirm) window.showConfirm({ title: L('settings.recycle_title', 'Recycle bin'), message: q, okLabel: L('btn.save', 'Save'), onConfirm: save });
                else if (confirm(q)) save();
            }).catch(function (e) { msg.style.color = 'var(--danger-text)'; msg.textContent = e.message; });
        });
    }
    // ── Thumbnails (GH #424) ───────────────────────────────────────────────
    // The switch, the backlog, and Clear: pictures are made by the server in batches of
    // about four seconds; PDFs are drawn by this browser (only in folders this person can
    // upload to) two at a time. A tile pops in for each one - green for a picture, blue for
    // a PDF, grey for one set aside - and Pause stops after the batch in hand.
    var thState = document.getElementById('thState');
    if (thState) {
        var thLast = null, thRunning = false, thPaused = false;
        var thDone = 0, thTotal = 0, thSkip = [];
        var el = function (id) { return document.getElementById(id); };
        /** Draws the state box and the counts from the server's reply. */
        var thRender = function (r) {
            thLast = r;
            el('thEnabled').checked = !!r.enabled;
            var b = r.backlog || {}, aside = b.set_aside || {};
            var lines = [];
            if (!r.ready_table) lines.push(L('settings.thumbs_need_verify', 'Run Database Verification (System -> Database Verification) to switch thumbnails on.'));
            else if (!r.gd) lines.push(L('settings.thumbs_no_gd', 'This server cannot make thumbnails: the GD extension is not installed in PHP. Files show their type icons until it is.'));
            else lines.push(L('settings.thumbs_ok', 'Thumbnails made so far: {n}. Each is made once and shared by every copy of the same file.', { n: b.ready || 0 }) +
                (r.webp ? '' : ' ' + L('settings.thumbs_jpeg', 'This server has no WebP support, so thumbnails are saved as JPEG.')));
            thState.className = 'set-box' + (!r.ready_table || !r.gd ? ' warn' : '');
            thState.textContent = lines.join(' ');
            var setAside = (aside.too_large || 0) + (aside.unsupported || 0) + (aside.failed || 0);
            el('thCounts').innerHTML =
                '<div>' + esc(L('settings.thumbs_pictures', 'Pictures still to make:')) + ' <b>' + (b.pictures || 0) + '</b></div>' +
                '<div>' + esc(L('settings.thumbs_pdfs', 'PDFs still to draw:')) + ' <b>' + (b.pdfs || 0) + '</b>' +
                    (b.pdfs ? ' <span style="color:var(--text-dim)">(' + esc(L('settings.thumbs_pdfs_split', 'in folders you can upload to: {open}; waiting for someone with access: {wait}', { open: b.pdfs_open || 0, wait: (b.pdfs || 0) - (b.pdfs_open || 0) })) + ')</span>' : '') + '</div>' +
                (setAside ? '<div style="color:var(--text-dim)">' + esc(L('settings.thumbs_set_aside', 'Set aside: {n} (too large to shrink: {big}; not a picture this server can read: {un}; could not be read: {fail}).',
                    { n: setAside, big: aside.too_large || 0, un: aside.unsupported || 0, fail: aside.failed || 0 })) + '</div>' : '');
            el('thRetry').hidden = thRunning || !(aside.failed > 0);
            var can = r.ready_table && r.gd && r.enabled;
            el('thClear').disabled = thRunning || !can || !((b.pictures || 0) + (b.pdfs_open || 0));
        };
        /** Adds one tile to the progress grid and moves the count and the bar on. */
        var thTile = function (cls) {
            var t = document.createElement('span');
            t.className = 'th-tile' + (cls ? ' ' + cls : '');
            var tiles = el('thTiles');
            tiles.appendChild(t);
            while (tiles.children.length > 220) tiles.removeChild(tiles.firstChild);   // the grid keeps the latest few
            thDone++;
            el('thDone').textContent = thDone;
            el('thFill').style.width = (thTotal ? Math.min(100, Math.round(thDone / thTotal * 100)) : 100) + '%';
        };
        var thMsg = function (text, colour) { var m = el('thMsg'); m.style.color = colour || ''; m.textContent = text || ''; };
        /** Ends a run - finished, paused or failed - and refreshes the counts. */
        var thStop = function (text, colour) {
            thRunning = false;
            el('thPause').hidden = true;
            thMsg(text, colour);
            api('settings.php?tab=thumbnails').then(thRender).catch(function () {});
        };
        /** Pictures first: batches until none are left, or Pause. */
        var thPictures = function () {
            if (thPaused) return thStop(L('settings.thumbs_paused', 'Paused - press Clear to carry on.'));
            return api('settings.php', { tab: 'thumbnails_pictures' }).then(function (r) {
                (r.made || []).forEach(function (s) { thTile(s === 'ready' ? '' : 'th-skip'); });
                thRender(r);
                if ((r.made || []).length && (r.backlog.pictures || 0) > 0) return thPictures();
                return thPdfs();
            });
        };
        /** Then PDFs this browser may draw, ten at a time from the server, two drawing at once. */
        var thPdfs = function () {
            if (thPaused) return thStop(L('settings.thumbs_paused', 'Paused - press Clear to carry on.'));
            if (!window.FilesThumbs) return thStop(L('settings.thumbs_finished', 'Done.'), 'var(--success-text)');
            return api('settings.php', { tab: 'thumbnails_pdfs', skip: thSkip }).then(function (r) {
                if (!r.items.length) return thStop(L('settings.thumbs_finished', 'Done.'), 'var(--success-text)');
                return Promise.all(r.items.map(function (id) {
                    return window.FilesThumbs.fromItem(id).then(function () { thTile('th-pdf'); }, function () { thSkip.push(id); thTile('th-skip'); });
                })).then(thPdfs);
            });
        };
        el('thClear').addEventListener('click', function () {
            if (thRunning || !thLast) return;
            var b = thLast.backlog || {};
            thRunning = true; thPaused = false; thDone = 0; thSkip = [];
            thTotal = (b.pictures || 0) + (b.pdfs_open || 0);
            el('thTiles').innerHTML = '';
            el('thDone').textContent = '0';
            el('thOf').textContent = ' / ' + thTotal;
            el('thFill').style.width = '0';
            el('thRun').hidden = false;
            el('thPause').hidden = false;
            el('thClear').disabled = true;
            thMsg('');
            thPictures().catch(function (e) { thStop(e.message, 'var(--danger-text)'); });
        });
        el('thPause').addEventListener('click', function () { thPaused = true; this.hidden = true; thMsg(L('settings.thumbs_pausing', 'Pausing after this batch...')); });
        el('thRetry').addEventListener('click', function () {
            api('settings.php', { tab: 'thumbnails_retry' }).then(thRender).catch(function (e) { thMsg(e.message, 'var(--danger-text)'); });
        });
        el('thEnabled').addEventListener('change', function () {
            var box = this;
            api('settings.php', { tab: 'thumbnails', enabled: box.checked })
                .then(function (r) { thRender(r); thMsg(L('settings.saved', 'Saved.'), 'var(--success-text)'); })
                .catch(function (e) { box.checked = !box.checked; thMsg(e.message, 'var(--danger-text)'); });
        });
        api('settings.php?tab=thumbnails').then(thRender).catch(function (e) { thState.textContent = e.message; });
    }
})();
