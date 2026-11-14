/**
 * Save to Files (GH #303) - one small dialog, used by the ticket's attachment list
 * (email attachments) and by every Documents panel (stored documents).
 *
 *   window.SaveToFiles.open({ source: 'email_attachment' | 'document', id, name, ticket: bool })
 *
 * Asks api/files/save_from.php for the folders the person can save into and the
 * Files -> Settings -> Sharing defaults, lets them pick one (and "In a folder for this
 * ticket" when saving from a ticket), and on a name that is already there offers the
 * same choices as an upload (Files -> Settings -> Versions). The server checks
 * everything again; this only draws it.
 *
 * @wiki Save-To-Files-Developer-Guide
 */
(function () {
    'use strict';
    // Loaded once: the Documents panel brings it to any page, and a page may also load it itself.
    if (window.SaveToFiles) return;
    // The app's root, from this script's own address - it is loaded from pages at
    // different depths (tickets/, tasks/, assets/...).
    var BASE = (document.currentScript && document.currentScript.src || '').replace(/assets\/js\/save-to-files\.js.*$/, '');
    var API = BASE + 'api/files/save_from.php';

    /** A translated string under common.save_to_files, with the English as the fallback. */
    function T(key, en, params) {
        var full = 'common.save_to_files.' + key;
        var got = window.t ? window.t(full, params) : full;
        if (got === full) {
            got = en;
            if (params) Object.keys(params).forEach(function (k) { got = got.split('{' + k + '}').join(params[k]); });
        }
        return got;
    }
    /** HTML-escapes a value; null and undefined become an empty string. */
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    /** JSON from the endpoint; rejects with the server's message on success: false. */
    function call(body) {
        return fetch(API + (body ? '' : '?targets=1'), body
            ? { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
            : { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j.success) throw new Error(j.error || 'Error'); return j; });
    }

    var CHOICE = function (c) {
        return { version: T('choice_version', 'New version'), rename: T('choice_rename', 'Keep both'),
                 overwrite: T('choice_overwrite', 'Overwrite'), skip: T('choice_skip', 'Skip') }[c] || c;
    };

    /** Opens the dialog for one file. */
    function open(o) {
        var overlay = document.createElement('div');
        overlay.className = 'stf-overlay';
        overlay.innerHTML =
            '<div class="stf-box" role="dialog" aria-modal="true" aria-labelledby="stfTitle">' +
              '<div class="stf-head" id="stfTitle">' + esc(T('title', 'Save to Files')) + '</div>' +
              '<div class="stf-body">' +
                '<div class="stf-file">' + esc(o.name || '') + '</div>' +
                '<div class="stf-msg">' + esc(T('loading', 'Loading...')) + '</div>' +
              '</div>' +
              '<div class="stf-foot"></div>' +
            '</div>';
        document.body.appendChild(overlay);
        var body = overlay.querySelector('.stf-body'), foot = overlay.querySelector('.stf-foot');
        /** Closes the dialog. */
        var close = function () { document.removeEventListener('keydown', onKey, true); overlay.remove(); };
        /** Escape closes it. */
        var onKey = function (ev) { if (ev.key === 'Escape') { ev.stopPropagation(); close(); } };
        document.addEventListener('keydown', onKey, true);
        overlay.addEventListener('mousedown', function (ev) { if (ev.target === overlay) close(); });
        /** Replaces the footer with these buttons: [{label, primary?, run}]. */
        var buttons = function (list) {
            foot.innerHTML = '';
            list.forEach(function (b) {
                var el = document.createElement('button');
                el.type = 'button';
                el.className = 'stf-btn' + (b.primary ? ' stf-primary' : '');
                el.textContent = b.label;
                el.addEventListener('click', b.run);
                foot.appendChild(el);
            });
        };
        /** Shows a message in the dialog, and Close. */
        var say = function (html, isError) {
            body.querySelector('.stf-msg').innerHTML = '<span class="' + (isError ? 'stf-err' : '') + '">' + html + '</span>';
        };

        call(null).then(function (t) {
            if (!t.folders.length) {
                say(esc(T('no_folders', 'There is no folder in Files you can save into. Ask for Upload on a folder.')), true);
                buttons([{ label: T('close', 'Close'), primary: true, run: close }]);
                return;
            }
            body.querySelector('.stf-msg').outerHTML =
                '<label class="stf-label" for="stfFolder">' + esc(T('folder', 'Folder')) + '</label>' +
                '<input type="search" class="stf-filter" placeholder="' + esc(T('filter', 'Type to narrow the list')) + '">' +
                '<select id="stfFolder" class="stf-select" size="8">' + t.folders.map(function (f) {
                    return '<option value="' + f.id + '">' + esc(f.path) + '</option>';
                }).join('') + '</select>' +
                (o.ticket ? '<label class="stf-check"><input type="checkbox" id="stfTicket"' + (t.ticket_folder ? ' checked' : '') + '> ' +
                    esc(T('ticket_folder', 'In a folder for this ticket')) + '</label>' : '') +
                '<div class="stf-msg"></div>';
            var sel = body.querySelector('#stfFolder');
            sel.value = String(t.default_folder || t.folders[0].id);
            var filter = body.querySelector('.stf-filter');
            filter.addEventListener('input', function () {
                var q = filter.value.toLowerCase();
                Array.prototype.forEach.call(sel.options, function (op) { op.hidden = q && op.textContent.toLowerCase().indexOf(q) < 0; });
            });
            /** Sends the save; on a name clash asks which choice, then sends it again. */
            var send = function (onConflict) {
                var tick = body.querySelector('#stfTicket');
                say(esc(T('saving', 'Saving...')));
                call({ source: o.source, id: o.id, folder_id: parseInt(sel.value, 10), ticket_folder: !!(tick && tick.checked), on_conflict: onConflict || 'ask' })
                    .then(function (r) {
                        if (r.conflict) {
                            say(esc(T('conflict', 'This folder already has a file called "{name}".', { name: r.name })));
                            buttons(r.choices.map(function (c) {
                                return { label: CHOICE(c), primary: c === r.default, run: function () { c === 'skip' ? close() : send(c); } };
                            }));
                            return;
                        }
                        say(esc(T('saved', 'Saved as "{name}".', { name: r.name })) +
                            ' <a href="' + esc(r.open_url) + '" target="_blank" rel="noopener">' + esc(T('open', 'Open in Files')) + '</a>');
                        buttons([{ label: T('close', 'Close'), primary: true, run: close }]);
                    })
                    .catch(function (e) { say(esc(e.message), true); main(); });
            };
            /** The normal Save / Cancel footer. */
            var main = function () { buttons([{ label: T('save', 'Save'), primary: true, run: function () { send('ask'); } }, { label: T('cancel', 'Cancel'), run: close }]); };
            main();
            filter.focus();
        }).catch(function (e) {
            say(esc(e.message), true);
            buttons([{ label: T('close', 'Close'), primary: true, run: close }]);
        });
    }

    window.SaveToFiles = { open: open };
})();
