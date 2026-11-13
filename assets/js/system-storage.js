/**
 * System -> Storage (#284). Talks to api/system/storage.php (window.ST_API).
 * The connection, then a row per store with its location switch and any move.
 */
(function () {
    'use strict';
    var t = function (k, p) { return window.t('system.storage.' + k, p); };
    var $ = function (id) { return document.getElementById(id); };
    var state = { data: null, polling: null };
    var STORES = ['tickets', 'changes', 'documents', 'recordings', 'warroom'];

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function msg(el, text, ok) {
        el.textContent = text || '';
        el.className = 'st-msg' + (text ? (ok ? ' ok' : ' err') : '');
    }
    function size(bytes) {
        var b = +bytes || 0, u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
        while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
        return (i === 0 ? b : b.toFixed(1)) + ' ' + u[i];
    }
    function where(loc) { return loc === 's3' ? t('s3') : t('local'); }

    async function api(body) {
        var opts = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
        var resp = await fetch(window.ST_API, opts);
        var data;
        try { data = await resp.json(); } catch (e) { data = { success: false, error: t('err_server') }; }
        return data;
    }

    function connection() {
        return { endpoint: $('stEndpoint').value.trim(), region: $('stRegion').value.trim(), bucket: $('stBucket').value.trim(),
                 prefix: $('stPrefix').value.trim(), access_key_id: $('stKey').value.trim(), secret: $('stSecret').value,
                 path_style: $('stPathStyle').checked };
    }

    /** The status, the lock, and (when fill) the fields - a refresh must not overwrite what is being typed. */
    function renderConnection(d, fill) {
        var c = d.connection;
        $('stSource').textContent = d.connection_source === 'storage' ? t('source_storage')
            : d.connection_source === 'files' ? t('source_files') : t('source_none');
        if (c && fill) {
            $('stEndpoint').value = c.endpoint; $('stRegion').value = c.region; $('stBucket').value = c.bucket;
            $('stPrefix').value = c.prefix; $('stKey').value = c.access_key_id; $('stSecret').value = c.secret || '';
            $('stPathStyle').checked = !!c.path_style;
        }
        var locked = !!(c && d.connection_in_use);
        $('stLocked').classList.toggle('st-hidden', !locked);
        ['stEndpoint', 'stBucket', 'stPrefix'].forEach(function (id) { $(id).disabled = locked; });
    }

    function moveBlock(s) {
        var m = s.move;
        if (!m) return '';
        var done = m.verified + m.removed + m.skipped;
        var out = '<div class="st-move">' + esc(t('moving', { from: where(m.from), to: where(m.to), done: done, total: m.total }));
        if (m.failed > 0) out += '<div class="st-msg err">' + esc(t('moving_failed', { n: m.failed, error: m.last_error || '' })) + '</div>';
        var copiedAll = m.pending === 0 && m.failed === 0;
        if (m.cleanup_running) out += '<div>' + esc(t('moving_cleanup')) + '</div>';
        else if (copiedAll) out += '<div>' + esc(t('moving_done')) + '</div>';
        var btns = [];
        if (m.failed > 0) {
            btns.push(['retry_failed', t('retry')]);
            btns.push(['skip_missing', t('skip')]);
        }
        if (copiedAll && !m.cleanup_running) {
            btns.push(['remove_old', t('remove_old')]);
            btns.push(['finish_keep', t('finish_keep')]);
        }
        if (btns.length) {
            out += '<div class="st-actions">' + btns.map(function (b) {
                return '<button class="btn btn-small" type="button" data-store="' + esc(s.store) + '" data-move="' + b[0] + '">' + esc(b[1]) + '</button>';
            }).join('') + '</div>';
        }
        return out + '</div>';
    }

    function renderStores(d) {
        var rows = [];
        rows.push('<tr><td><div class="st-name">' + esc(t('store_files')) + '</div><div class="st-desc">' + esc(t('store_files_desc'))
            + ' <a href="' + esc(window.ST_FILES_SETTINGS) + '">' + esc(t('store_files_link')) + '</a></div>'
            + (d.files.moving ? '<div class="st-move">' + esc(t('files_moving')) + '</div>' : '') + '</td>'
            + '<td>' + esc(t('holds', { n: d.files.files, size: size(d.files.bytes) })) + '</td>'
            + '<td>' + esc(where(d.files.location)) + '</td></tr>');
        d.stores.forEach(function (s) {
            var busy = !!s.move;
            var noS3 = !d.connection || !d.curl;
            rows.push('<tr><td><div class="st-name">' + esc(t('store_' + s.store)) + '</div><div class="st-desc">' + esc(t('store_' + s.store + '_desc')) + '</div>'
                + moveBlock(s) + '<div class="st-msg" data-msg="' + esc(s.store) + '"></div></td>'
                + '<td>' + esc(t('holds', { n: s.files, size: size(s.bytes) })) + '</td>'
                + '<td><div class="st-where"><select class="st-select" data-loc="' + esc(s.store) + '"' + (busy ? ' disabled' : '') + '>'
                + '<option value="local"' + (s.location === 'local' ? ' selected' : '') + '>' + esc(t('local')) + '</option>'
                + '<option value="s3"' + (s.location === 's3' ? ' selected' : '') + (noS3 && s.location !== 's3' ? ' disabled' : '') + '>' + esc(t('s3')) + '</option>'
                + '</select><button class="btn btn-small" type="button" data-apply="' + esc(s.store) + '"' + (busy ? ' disabled' : '') + '>' + esc(t('apply')) + '</button></div></td></tr>');
        });
        $('stStores').innerHTML = rows.join('');
    }

    async function load() {
        var d = await api();
        $('stLoading').classList.add('st-hidden');
        if (!d.success) { $('stLoading').classList.remove('st-hidden'); $('stLoading').textContent = d.error || ''; return; }
        state.data = d;
        $('stContent').classList.remove('st-hidden');
        renderConnection(d, !state.loaded);
        state.loaded = true;
        renderStores(d);
        var moving = d.files.moving || d.stores.some(function (s) { return !!s.move; });
        $('stScheduler').classList.toggle('st-hidden', !(moving && d.scheduler_running === false));
        clearTimeout(state.polling);
        if (moving) state.polling = setTimeout(load, 5000);
    }

    document.addEventListener('DOMContentLoaded', function () {
        $('stTest').addEventListener('click', async function () {
            var b = this;
            b.disabled = true;
            msg($('stConnMsg'), t('loading'), true);
            var d = await api({ action: 'test', connection: connection() });
            b.disabled = false;
            if (!d.success) { msg($('stConnMsg'), d.error, false); return; }
            if (d.test.ok) msg($('stConnMsg'), t('test_ok'), true);
            else msg($('stConnMsg'), t('test_failed', { step: d.test.step, error: d.test.error || '' }), false);
        });
        $('stSave').addEventListener('click', async function () {
            var b = this;
            b.disabled = true;
            msg($('stConnMsg'), t('loading'), true);
            var d = await api({ action: 'save_connection', connection: connection() });
            b.disabled = false;
            if (!d.success) { msg($('stConnMsg'), d.error, false); return; }
            msg($('stConnMsg'), t('saved'), true);
            state.loaded = false;
            load();
        });
        $('stStores').addEventListener('click', async function (e) {
            var apply = e.target.closest('button[data-apply]');
            var mv = e.target.closest('button[data-move]');
            if (apply) {
                var store = apply.getAttribute('data-apply');
                var to = document.querySelector('select[data-loc="' + store + '"]').value;
                var cur = state.data.stores.filter(function (s) { return s.store === store; })[0];
                if (!cur || cur.location === to) return;
                if (!window.confirm(t('confirm_move', { store: t('store_' + store), where: where(to) }))) return;
                apply.disabled = true;
                var d = await api({ action: 'set_location', store: store, location: to });
                if (!d.success) { msg(document.querySelector('[data-msg="' + store + '"]'), d.error, false); apply.disabled = false; return; }
                load();
            } else if (mv) {
                var st = mv.getAttribute('data-store'), what = mv.getAttribute('data-move');
                if (what === 'remove_old' && !window.confirm(t('confirm_remove', { store: t('store_' + st) }))) return;
                mv.disabled = true;
                var r = await api({ action: 'move_action', store: st, move: what });
                if (!r.success) { msg(document.querySelector('[data-msg="' + st + '"]'), r.error, false); mv.disabled = false; return; }
                load();
            }
        });
        load();
    });
})();
