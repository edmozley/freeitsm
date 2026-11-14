/**
 * Locations editor (GH #311) - the one tree editor for asset_locations, used by
 * System -> Locations and by Assets -> Settings -> Locations.
 *
 * Locations used to belong to Assets alone. They now describe the organisation
 * (forms can ask "which site?", and people, tickets and CMDB objects are next),
 * so the list lives in System, while Assets keeps the same editor for the asset
 * managers who look after it there - including people who are not System
 * administrators. Same table, same endpoints, same rules: this file only moved
 * the UI out of the Assets settings page so the two places cannot drift.
 *
 * Usage:  LocationsTree.mount(document.getElementById('locations-tree'),
 *                             { apiBase: '../../api/assets/' });
 *         then call .reload() whenever you want a fresh list.
 *
 * Needs: window.t (i18n.js) with the 'asset-management' namespace loaded,
 * showToast (toast.js), showConfirm (confirm.js) and inbox.css for the modal.
 * The strings are still asset-management.settings.* because they were already
 * translated into every language; moving them would only lose those.
 */
(function () {
    'use strict';

    var T = function (k, p) { return window.t('asset-management.' + k, p); };
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function toast(msg, kind) { if (typeof window.showToast === 'function') window.showToast(msg, kind); }

    var ICON_ADD = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
    var ICON_EDIT = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
    var ICON_DEL = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

    function mount(container, opts) {
        var apiBase = (opts && opts.apiBase) || '../../api/assets/';
        var all = [];
        var canShare = false;      // 2.6.0 - from get_asset_locations.php
        var collapsed = new Set();

        // The modal is built here rather than in each page, so both pages get
        // exactly the same form. The app's canonical header / body / footer modal
        // (inbox.css), plus the System modal classes (system-page.css), which
        // System -> Locations loads and Assets -> Settings does not - the two
        // describe the same look.
        var modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML =
            '<div class="modal-content sys-modal loc-modal" role="dialog" aria-modal="true">' +
              '<div class="modal-header sys-modal-head" data-role="title"></div>' +
              '<form data-role="form" class="sys-modal-form">' +
                '<div class="modal-body sys-modal-body">' +
                '<div class="form-group"><label>' + esc(T('settings.col_name')) + '</label>' +
                  '<input type="text" data-role="name" required autocomplete="off"></div>' +
                '<div class="form-group"><label>' + esc(T('settings.parent_location')) + '</label>' +
                  '<select data-role="parent"></select>' +
                  '<div class="form-hint">' + esc(T('settings.parent_location_hint')) + '</div></div>' +
                // 2.6.0. Only for someone who can reach every company (the list
                // endpoint says so as can_share); the save endpoint enforces the
                // same rule whatever this shows.
                '<div class="form-group" data-role="shared-group" hidden>' +
                  '<label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-role="shared">' +
                  '<span class="toggle-slider"></span></span> ' + esc(T('settings.location_shared')) + '</label>' +
                  '<div class="form-hint">' + esc(T('settings.location_shared_hint')) + '</div></div>' +
                '</div>' +
                '<div class="modal-footer sys-modal-foot">' +
                  '<button type="button" class="btn btn-secondary" data-role="cancel">' + esc(T('common.cancel')) + '</button>' +
                  '<button type="submit" class="btn btn-primary">' + esc(T('common.save')) + '</button>' +
                '</div>' +
              '</form>' +
            '</div>';
        document.body.appendChild(modal);
        var q = function (r) { return modal.querySelector('[data-role="' + r + '"]'); };
        var editingId = null;

        function children(parentId) { return all.filter(function (l) { return l.parent_id === parentId; }); }

        function render() {
            if (all.length === 0) {
                container.innerHTML = '<div class="loc-empty">' + T('settings.no_locations') /* translation with markup, as before the move - not user data */ + '</div>';
                return;
            }
            container.innerHTML = '<ul>' + children(null).map(node).join('') + '</ul>';
        }

        function node(loc) {
            var kids = children(loc.id);
            var hasKids = kids.length > 0;
            var isCollapsed = collapsed.has(loc.id);
            var caretClass = hasKids ? (isCollapsed ? 'collapsed' : '') : 'leaf';
            var count = hasKids ? '<span class="loc-count">' + kids.length + '</span>' : '';
            // A shared location is every company's, so someone who cannot reach
            // them all may add inside it but not change or remove it.
            var shared = loc.is_shared
                ? '<span class="loc-shared" title="' + esc(T('settings.location_shared_hint')) + '">' + esc(T('settings.location_shared_badge')) + '</span>' : '';
            var locked = loc.is_shared && !canShare;
            var row =
                '<div class="loc-row">' +
                  '<span class="loc-caret ' + caretClass + '" data-act="toggle" data-id="' + loc.id + '">&#9662;</span>' +
                  '<span class="loc-name">' + esc(loc.name) + shared + count + '</span>' +
                  '<span class="loc-actions' + (locked ? ' loc-actions-locked' : '') + '">' +
                    '<button type="button" class="action-btn" title="' + esc(T('settings.add_sublocation')) + '" data-act="add" data-id="' + loc.id + '">' + ICON_ADD + '</button>' +
                    '<button type="button" class="action-btn" title="' + esc(T('common.edit')) + '" data-act="edit" data-id="' + loc.id + '">' + ICON_EDIT + '</button>' +
                    '<button type="button" class="action-btn delete" title="' + esc(T('common.delete')) + '" data-act="delete" data-id="' + loc.id + '">' + ICON_DEL + '</button>' +
                  '</span>' +
                '</div>';
            var kidsHtml = hasKids
                ? '<div class="loc-children ' + (isCollapsed ? 'collapsed' : '') + '"><ul>' + kids.map(node).join('') + '</ul></div>' : '';
            return '<li class="loc-node">' + row + kidsHtml + '</li>';
        }

        // Indented <option>s for the parent select. When editing, exclude the
        // node itself and its whole subtree (a node can't sit under itself).
        function parentOptions(excludeId) {
            var exclude = new Set();
            if (excludeId != null) {
                var stack = [excludeId];
                while (stack.length) {
                    var cur = stack.pop();
                    exclude.add(cur);
                    children(cur).forEach(function (c) { stack.push(c.id); });
                }
            }
            var out = ['<option value="">' + esc(T('settings.none_top_level')) + '</option>'];
            (function walk(parentId, depth) {
                children(parentId).forEach(function (loc) {
                    if (exclude.has(loc.id)) return;
                    out.push('<option value="' + loc.id + '">' + '   '.repeat(depth) + esc(loc.name) + '</option>');
                    walk(loc.id, depth + 1);
                });
            })(null, 0);
            return out.join('');
        }

        function open(loc, parentId) {
            editingId = loc ? loc.id : null;
            q('title').textContent = T(loc ? 'settings.edit_location' : 'settings.add_location');
            q('name').value = loc ? loc.name : '';
            q('parent').innerHTML = parentOptions(loc ? loc.id : null);
            var p = loc ? loc.parent_id : parentId;
            q('parent').value = p != null ? String(p) : '';
            // The Shared tick is pre-set on edit - the save sends it every time,
            // so leaving it unset on a shared location would unshare it on an
            // unrelated rename.
            q('shared-group').hidden = !canShare;
            q('shared').checked = !!(loc && loc.is_shared);
            modal.classList.add('active');
            setTimeout(function () { q('name').focus(); }, 50);
        }
        function close() { modal.classList.remove('active'); }

        async function remove(loc) {
            if (children(loc.id).length > 0) { toast(T('settings.location_has_children'), 'error'); return; }
            var ok = await window.showConfirm({ title: T('common.delete'), message: T('settings.delete_location_confirm', { name: loc.name }), okLabel: T('common.delete'), okClass: 'danger' });
            if (!ok) return;
            try {
                var res = await fetch(apiBase + 'delete_asset_location.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: loc.id })
                });
                var data = await res.json();
                if (data.success) { toast(T('toast.deleted'), 'success'); reload(); }
                else toast(T('toast.error', { error: data.error }), 'error');
            } catch (e) { toast(T('settings.delete_location_failed'), 'error'); }
        }

        async function reload() {
            try {
                var res = await fetch(apiBase + 'get_asset_locations.php');
                var data = await res.json();
                if (!data.success) {
                    container.innerHTML = '<div class="loc-empty loc-error">' + esc(T('toast.error', { error: data.error })) + '</div>';
                    return;
                }
                all = data.locations || [];
                canShare = !!data.can_share;
                render();
            } catch (e) {
                console.error('Error loading locations:', e);
                container.innerHTML = '<div class="loc-empty loc-error">' + esc(T('settings.locations_load_failed')) + '</div>';
            }
        }

        container.classList.add('loc-tree');
        container.addEventListener('click', function (e) {
            var b = e.target.closest('[data-act]');
            if (!b) return;
            var id = parseInt(b.getAttribute('data-id'), 10);
            var loc = all.find(function (l) { return l.id === id; });
            switch (b.getAttribute('data-act')) {
                case 'toggle': if (collapsed.has(id)) collapsed.delete(id); else collapsed.add(id); render(); break;
                case 'add': open(null, id); break;
                case 'edit': if (loc) open(loc, null); break;
                case 'delete': if (loc) remove(loc); break;
            }
        });

        q('cancel').addEventListener('click', close);
        q('form').addEventListener('submit', async function (e) {
            e.preventDefault();
            var payload = { name: q('name').value.trim(), parent_id: q('parent').value || null };
            if (canShare) payload.is_shared = q('shared').checked;
            if (!payload.name) { toast(T('settings.name_required'), 'error'); return; }
            if (editingId) payload.id = editingId;
            try {
                var res = await fetch(apiBase + 'save_asset_location.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
                });
                var data = await res.json();
                if (data.success) { close(); toast(T('toast.saved'), 'success'); reload(); }
                else toast(T('toast.error', { error: data.error }), 'error');
            } catch (e2) { toast(T('settings.save_location_failed'), 'error'); }
        });
        // Close on a click that both started and ended on the backdrop, so a
        // text selection dragged out of the form does not close it.
        var downOn = null;
        modal.addEventListener('mousedown', function (e) { downOn = e.target; });
        modal.addEventListener('click', function (e) { if (e.target === modal && downOn === modal) close(); });

        reload();
        return { reload: reload, add: function () { open(null, null); } };
    }

    window.LocationsTree = { mount: mount };
})();
