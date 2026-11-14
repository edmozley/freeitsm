/**
 * The asset <-> configuration item link card (GH #201), on the asset page (side 'asset':
 * "Configuration item") and on the CI page (side 'cmdb': "Asset"). One card, both sides,
 * so the two pages cannot tell different stories about the same link.
 *
 *   CiAssetLink.mount(hostEl, {
 *       side:  'asset' | 'cmdb',
 *       id:    the asset id or the CI id,
 *       base:  BASE_URL,
 *       cardClass / headClass / titleClass: the host page's own card classes,
 *       onState: function ({linked}) - told after each load (the asset page's layout notice uses it),
 *
 * On the asset side the card also shows the linked CI's own "If this failed" result (Part D):
 * the same blast radius the CI page draws, from api/cmdb/get_object_impact.php - so it
 * follows the CMDB's impact settings and nothing else. The asset is never in that graph.
 *   });
 *
 * Every rule - who sees the other end, who may change it, one live link per record,
 * Replace refused when the link changed after you looked - lives in
 * includes/services/asset_ci_links.php. This only draws what api/assets/ci_link.php says.
 * A reader who cannot open the other side gets "restricted": no name, no id, no link.
 *
 * Words use window.tf() with English fallbacks (common.ci_link.*).
 *
 * @wiki Asset-CI-Link-Developer-Guide
 */
(function () {
    'use strict';
    if (window.CiAssetLink) return;

    /** Translates a common.ci_link.* key, falling back to the English given. */
    const L = (k, en, p) => (window.tf ? window.tf('common.ci_link.' + k, en, p) : en.replace(/\{(\w+)\}/g, (m, n) => (p && p[n] !== undefined ? p[n] : m)));
    /** Escapes a value for safe use in HTML. */
    const esc = v => (v === null || v === undefined) ? '' : String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (m, k) => { if (window.showToast) window.showToast(m, k); };
    /** A UTC timestamp in the reader's own format, when the page has the shared formatter. */
    const when = s => { if (!s) return ''; try { return window.fmtDateTime(window.parseUTCDate ? window.parseUTCDate(s) : s) || s; } catch (e) { return s; } };
    const REASON = {
        unlinked: 'Unlinked', replaced: 'Replaced', ci_deleted: 'Configuration item deleted', asset_merged: 'Ended when two assets were merged'
    };
    const DETAIL = [
        ['type', 'Type'], ['manufacturer', 'Manufacturer'], ['model', 'Model'], ['serial', 'Serial number'],
        ['asset_tag', 'Asset tag'], ['status', 'Status'], ['location', 'Location']
    ];

    async function call(base, query, body) {
        const opts = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
        const r = await fetch(base + 'api/assets/ci_link.php' + (query || ''), Object.assign({ credentials: 'same-origin' }, opts));
        return r.json();
    }

    /** "by Ed Mozley, 26 Oct 2026 10:14 (REST: Monitoring key)" */
    function byLine(name, at, source) {
        const how = !source || source === 'ui' ? '' : (source.indexOf('api:') === 0 ? L('via_api', 'REST: {key}', { key: source.slice(4) })
            : source.indexOf('integration:') === 0 ? source.slice(12) : source);
        return [name ? L('by', 'by {name}', { name: name }) : L('by_system', 'automatically'), when(at), how ? '(' + how + ')' : '']
            .filter(Boolean).join(' ');
    }

    function mount(host, o) {
        if (!host) return;
        const base = o.base || window.APP_BASE || '/';
        const isAsset = o.side === 'asset';
        let state = null, picking = false, timer = null;
        let creating = null;   // Part C: the Create linked CI preview while its form is open

        const title = isAsset ? L('title_asset', 'Configuration item') : L('title_cmdb', 'Asset');
        const otherModule = isAsset ? L('module_cmdb', 'the CMDB') : L('module_assets', 'Assets');

        function wrap(body) {
            return '<div class="' + esc(o.cardClass || 'cil-card') + '">'
                + '<div class="' + esc(o.headClass || 'cil-head') + '"><span class="' + esc(o.titleClass || 'cil-title') + '">' + esc(title) + '</span></div>'
                + '<div class="cil-body">' + body + '</div></div>';
        }

        function otherLink(cur) {
            if (isAsset) {
                const ic = window.nmRenderIcon ? '<span class="cil-icon">' + window.nmRenderIcon(cur.icon_key || 'box', 18) + '</span>' : '';
                return '<a class="cil-name" href="' + esc(base + 'cmdb/object.php?id=' + cur.id) + '" title="' + esc(L('linked_by', 'Linked {by}', { by: byLine(cur.linked_by, cur.linked_at, cur.link_source) })) + '">'
                    + ic + '<span>' + esc(cur.name) + '</span></a>' + (cur.class_name ? '<span class="cil-class">' + esc(cur.class_name) + '</span>' : '');
            }
            return '<a class="cil-name" href="' + esc(base + 'asset-management/?asset_id=' + cur.id) + '" title="' + esc(L('linked_by', 'Linked {by}', { by: byLine(cur.linked_by, cur.linked_at, cur.link_source) })) + '">'
                + '<span>' + esc(cur.name || ('#' + cur.id)) + '</span></a>';
        }

        function detailsHtml(cur) {
            const d = cur.details || {};
            const rows = DETAIL.filter(([k]) => k in d).map(([k, en]) =>
                '<div class="cil-detail"><span class="cil-dl">' + esc(L('field_' + k, en)) + '</span><span class="cil-dv">' + (d[k] ? esc(d[k]) : '-') + '</span></div>');
            if (d.freshness) {
                const f = d.freshness;
                const state = { current: L('fresh_current', 'Current'), stale: L('fresh_stale', 'Stale'), failed: L('fresh_failed', 'Last read failed'), none: L('fresh_none', 'Nothing reports this asset') }[f.state] || f.state;
                rows.push('<div class="cil-detail"><span class="cil-dl">' + esc(L('field_freshness', 'Inventory')) + '</span><span class="cil-dv cil-fresh-' + esc(f.state) + '">'
                    + esc(state) + (f.last_seen ? ' &middot; ' + esc(L('last_seen', 'last seen {when}', { when: when(f.last_seen) })) : '') + '</span></div>');
            }
            if (!rows.length) return '';
            return '<div class="cil-from">' + esc(L('from_asset', 'From the asset')) + '</div><div class="cil-details">' + rows.join('') + '</div>';
        }

        function historyHtml() {
            const h = (state.history || []);
            if (!h.length) return '';
            const rows = h.map(e => {
                const other = e.other || {};
                const name = other.restricted ? (isAsset ? L('restricted_ci', 'a restricted configuration item') : L('restricted_asset', 'a restricted asset'))
                    : esc(other.name || ('#' + other.id)) + (other.class_name ? ' <span class="cil-class">' + esc(other.class_name) + '</span>' : '');
                const madeOn = e.made_on ? ' <span class="cil-muted">' + esc(L('made_on', '(made on {name}, since merged)', { name: e.made_on.name || ('#' + e.made_on.id) })) + '</span>'
                    : (other.merged ? ' <span class="cil-muted">' + esc(L('made_on', '(made on {name}, since merged)', { name: other.made_on_name || '' })) + '</span>' : '');
                const ended = e.unlinked_at
                    ? '<div class="cil-h-end">' + esc(L('reason_' + e.unlink_reason, REASON[e.unlink_reason] || e.unlink_reason || '')) + ' ' + esc(byLine(e.unlinked_by, e.unlinked_at, e.unlink_source)) + '</div>'
                    : '<div class="cil-h-end cil-live">' + esc(L('live', 'Linked now')) + '</div>';
                return '<li><div>' + name + madeOn + '</div><div class="cil-muted">' + esc(L('linked_by', 'Linked {by}', { by: byLine(e.linked_by, e.linked_at, e.link_source) })) + '</div>' + ended + '</li>';
            }).join('');
            return '<details class="cil-history"><summary>' + esc(L('history', 'Link history')) + ' <span class="cil-count">' + h.length + '</span></summary><ul>' + rows + '</ul></details>';
        }

        function pickerHtml() {
            return '<div class="cil-pick"><input type="text" autocomplete="off" placeholder="' + esc(isAsset ? L('pick_ci', 'Search configuration items...') : L('pick_asset', 'Search assets by name, serial or asset tag...')) + '">'
                + '<ul class="cil-results" hidden></ul>'
                + '<button type="button" class="cil-btn" data-cil="cancel">' + esc(L('cancel', 'Cancel')) + '</button></div>';
        }

        function draw() {
            if (!state) { host.innerHTML = wrap('<p class="cil-muted">' + esc(L('loading', 'Loading...')) + '</p>'); return; }
            if (!state.ready) { host.innerHTML = wrap('<p class="cil-muted">' + esc(L('not_ready', 'Run Database Verification to use links between assets and configuration items.')) + '</p>'); return; }
            const cur = state.current;
            const otherAccess = isAsset ? state.cmdb_access : state.assets_access;
            const canChange = state.can_change && otherAccess;
            let body = '';
            if (cur && cur.restricted) {
                body += '<p class="cil-restricted">' + esc(isAsset ? L('restricted_heading_ci', 'Linked configuration item - restricted') : L('restricted_heading_asset', 'Linked asset - restricted')) + '</p>'
                    + '<p class="cil-muted">' + esc(L('no_access', 'Linking is not available because you cannot open {module}.', { module: otherModule })) + '</p>';
            } else if (cur) {
                body += '<div class="cil-current">' + otherLink(cur)
                    + (canChange ? '<span class="cil-actions"><button type="button" class="cil-btn" data-cil="replace">' + esc(L('replace', 'Replace')) + '</button>'
                        + '<button type="button" class="cil-btn cil-danger" data-cil="unlink">' + esc(L('unlink', 'Unlink')) + '</button></span>' : '') + '</div>';
                if (!isAsset) body += detailsHtml(cur);
                if (isAsset) body += '<div class="cil-impact" data-cil-impact></div>';
            } else {
                body += '<p class="cil-muted">' + esc(isAsset ? L('none_ci', 'No configuration item.') : L('none_asset', 'No asset.')) + '</p>';
                if (!otherAccess) body += '<p class="cil-muted">' + esc(L('no_access', 'Linking is not available because you cannot open {module}.', { module: otherModule })) + '</p>';
                else if (canChange && !picking && !creating) body += '<button type="button" class="cil-btn cil-primary" data-cil="link">' + esc(L('link', 'Link')) + '</button>'
                    + (isAsset && state.create_class ? ' <button type="button" class="cil-btn" data-cil="create">' + esc(L('create', 'Create linked CI')) + '</button>' : '');
                if (creating) body += createHtml();
            }
            if (picking) body += pickerHtml();
            body += historyHtml();
            host.innerHTML = wrap(body);
            if (picking) { const i = host.querySelector('.cil-pick input'); if (i) { i.focus(); search(i.value); } }
            if (creating) fillRefSelects();
            if (cur && !cur.restricted && isAsset) loadImpact(cur, host.querySelector('[data-cil-impact]'));
        }

        async function load() {
            try {
                const d = await call(base, '?' + (isAsset ? 'asset_id=' : 'cmdb_object_id=') + encodeURIComponent(o.id));
                state = d.success ? d : { ready: true, current: null, history: [], can_change: false };
            } catch (e) { state = { ready: true, current: null, history: [], can_change: false }; }
            picking = false;
            creating = null;
            draw();
            if (o.onState) o.onState({ linked: !!(state && state.current) });
        }

        /** "If this failed, affected: 14 (6 Virtual machine, 3 Application, 2 Service)" with Show and, for Network Mapper users, Open as diagram. */
        async function loadImpact(cur, el) {
            if (!el) return;
            let d;
            try { d = await (await fetch(base + 'api/cmdb/get_object_impact.php?id=' + encodeURIComponent(cur.id), { credentials: 'same-origin' })).json(); }
            catch (e) { return; }
            if (!d || !d.success || !d.blast_radius || !el.isConnected) return;
            const br = d.blast_radius;
            const nodes = br.nodes || [];
            const show = '<a href="' + esc(base + 'cmdb/object.php?id=' + cur.id + '#o2Blast') + '">' + esc(L('impact_show', 'Show')) + '</a>';
            let text;
            if (!nodes.length) {
                text = br.no_impact_edges_configured ? L('impact_unconfigured', 'If this failed: nothing is set to carry impact in the CMDB yet.')
                    : L('impact_none', 'If this failed: nothing else in the CMDB depends on it.');
                el.innerHTML = esc(text) + ' ' + show;
                return;
            }
            const byClass = {};
            nodes.forEach(n => { byClass[n.class_name] = (byClass[n.class_name] || 0) + 1; });
            const top = Object.entries(byClass).sort((a, b) => b[1] - a[1]).slice(0, 3).map(([c, n]) => n + ' ' + c).join(', ');
            if (br.grades_used) {
                // GH #199 Part C: graded - how many affected, may be interrupted, redundancy reduced, unknown.
                const c = br.grade_counts || {};
                const EN = { affected: 'affected', may_be_interrupted: 'may be interrupted', redundancy_reduced: 'redundancy reduced', unknown: 'unknown' };
                text = L('impact_graded', 'If this failed: {summary}', { summary: Object.keys(EN).filter(g => c[g]).map(g => c[g] + ' ' + L('grade_' + g, EN[g])).join(' · ') });
            } else {
                text = L(br.truncated ? 'impact_some_more' : 'impact_some', br.truncated ? 'If this failed, affected: at least {n}' : 'If this failed, affected: {n}', { n: nodes.length })
                    + (top ? ' (' + top + ')' : '');
            }
            el.innerHTML = esc(text) + ' ' + show
                + (state.diagram_access ? ' &middot; <a href="#" data-cil="diagram" data-cil-ci="' + Number(cur.id) + '">' + esc(L('impact_diagram', 'Open as diagram')) + '</a>' : '');
        }

        /** The CI page's own diagram hand-off: build a Network Mapper diagram of the blast radius and open it. */
        async function openDiagram(ciId) {
            try {
                const r = await fetch(base + 'api/cmdb/create_impact_diagram.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ object_id: ciId }) });
                const d = await r.json();
                if (!d.success) throw new Error(d.error === 'empty_blast_radius' ? L('impact_diagram_empty', 'Nothing would be affected, so there is nothing to draw.') : (d.error || L('failed', 'The link could not be saved.')));
                window.location.href = base + 'network-mapper/diagram.php?id=' + d.diagram_id + '&fit=1';
            } catch (err) { toast(err.message, 'error'); }
        }

        /** Part C's form: possible matches first (a name is a suggestion, never proof), then the name and the class's required properties. */
        function createHtml() {
            const p = creating;
            let h = '<div class="cil-create"><div class="cil-create-title">' + esc(L('create_title', 'New {class} linked to this asset', { class: p.class.name })) + '</div>';
            if (p.matches.length) {
                h += '<p class="cil-muted">' + esc(L('create_matches', 'These already exist with the same name. A name alone does not prove it is the same equipment - link one if it is.')) + '</p><ul class="cil-matches">'
                    + p.matches.map(m => '<li><span>' + esc(m.name) + '</span> <button type="button" class="cil-btn" data-cil-pick="' + m.id + '" data-cil-label="' + esc(m.name) + '">' + esc(L('create_link_instead', 'Link this one instead')) + '</button></li>').join('') + '</ul>';
            }
            h += '<label class="cil-field"><span>' + esc(L('create_name', 'Name')) + '</span><input type="text" data-cil-name maxlength="255" value="' + esc(p.name || '') + '"></label>';
            p.required.forEach(r => {
                let input;
                if (r.type === 'dropdown') input = '<select data-cil-prop="' + esc(r.key) + '"><option value=""></option>' + r.options.map(v => '<option>' + esc(v) + '</option>').join('') + '</select>';
                else if (r.type === 'boolean') input = '<input type="checkbox" data-cil-prop="' + esc(r.key) + '" data-cil-bool>';
                else if (r.type === 'ref' && r.record_type) input = '<select data-cil-prop="' + esc(r.key) + '" data-cil-record="' + esc(r.record_type) + '"><option value="">' + esc(L('loading', 'Loading...')) + '</option></select>';
                else if (r.type === 'ref') input = '<select data-cil-prop="' + esc(r.key) + '" data-cil-ref="' + Number(r.ref_class_id || 0) + '"><option value="">' + esc(L('loading', 'Loading...')) + '</option></select>';
                else input = '<input type="' + (r.type === 'number' ? 'number' : r.type === 'date' ? 'date' : 'text') + '" data-cil-prop="' + esc(r.key) + '">';
                h += '<label class="cil-field"><span>' + esc(r.label) + ' *</span>' + input + '</label>';
            });
            return h + '<div class="cil-create-actions"><button type="button" class="cil-btn cil-primary" data-cil="create-go">'
                + esc(p.matches.length ? L('create_anyway', 'Create a new one') : L('create_go', 'Create')) + '</button>'
                + '<button type="button" class="cil-btn" data-cil="create-cancel">' + esc(L('cancel', 'Cancel')) + '</button></div></div>';
        }

        /** A required reference property: the CIs it may point at, in the asset's company. */
        function fillRefSelects() {
            // GH #312: a required person / team / location ... - the records the new CI
            // may point at (the asset's company decides which people and locations).
            host.querySelectorAll('select[data-cil-record]').forEach(async sel => {
                try {
                    const res = await fetch(base + 'api/cmdb/search_records.php?type=' + encodeURIComponent(sel.dataset.cilRecord) + '&asset_id=' + encodeURIComponent(o.id) + '&limit=50');
                    const d = await res.json();
                    sel.innerHTML = '<option value=""></option>' + ((d.results || []).map(r => '<option value="' + r.id + '">' + esc(r.name) + '</option>').join(''));
                } catch (e) { sel.innerHTML = '<option value=""></option>'; }
            });
            host.querySelectorAll('select[data-cil-ref]').forEach(async sel => {
                const cls = Number(sel.dataset.cilRef);
                try {
                    const d = await call(base, '?search=cis&asset_id=' + encodeURIComponent(o.id) + '&q=' + (cls ? '&class_id=' + cls : ''));
                    sel.innerHTML = '<option value=""></option>' + ((d.results || []).map(r => '<option value="' + r.id + '">' + esc(r.name) + '</option>').join(''));
                } catch (e) { sel.innerHTML = '<option value=""></option>'; }
            });
        }

        async function openCreate() {
            const d = await call(base, '?create_preview=1&asset_id=' + encodeURIComponent(o.id));
            if (!d.success || !d.class) { toast(d.error || L('create_unmapped', "This asset's type is not mapped to a CMDB class."), 'error'); return; }
            if (d.linked) { load(); return; }
            creating = d; picking = false;
            draw();
        }

        /** Create linked CI: one request makes the CI and the link, or neither. */
        async function createGo(btn) {
            const props = {};
            host.querySelectorAll('[data-cil-prop]').forEach(el => {
                props[el.dataset.cilProp] = el.hasAttribute('data-cil-bool') ? el.checked : (el.value === '' ? null : el.value);
            });
            const nameEl = host.querySelector('[data-cil-name]');
            btn.disabled = true;
            const d = await call(base, '', { action: 'create_ci', asset_id: Number(o.id), name: nameEl ? nameEl.value.trim() : '', properties: props,
                ignore_matches: creating.matches.length > 0 });
            btn.disabled = false;
            if (!d.success) {
                toast(d.error || L('failed', 'The link could not be saved.'), 'error');
                if (d.code === 'possible_matches') openCreate();   // new ones appeared: show them
                return;
            }
            toast(d.created ? L('created', 'Configuration item created and linked') : L('unchanged', 'Already linked'), 'success');
            load();
        }

        function search(q) {
            clearTimeout(timer);
            timer = setTimeout(async () => {
                const list = host.querySelector('.cil-results');
                if (!list) return;
                try {
                    const d = await call(base, '?search=' + (isAsset ? 'cis&asset_id=' : 'assets&cmdb_object_id=') + encodeURIComponent(o.id) + '&q=' + encodeURIComponent(q || ''));
                    const rows = d.success ? (d.results || []) : [];
                    list.innerHTML = rows.length ? rows.map(r => '<li><button type="button" data-cil-pick="' + r.id + '" data-cil-linked="' + (r.linked ? 1 : 0) + '" data-cil-label="' + esc(r.name || '') + '">'
                        + '<span class="cil-r-name">' + esc(r.name || ('#' + r.id)) + '</span>'
                        + '<span class="cil-muted">' + esc(isAsset ? (r.class_name || '') : [r.type_name, r.serial].filter(Boolean).join(' · ')) + (r.linked ? ' &middot; ' + esc(L('already_linked', 'already linked')) : '') + '</span>'
                        + '</button></li>').join('')
                        : '<li class="cil-muted cil-noresult">' + esc(d.success ? L('no_results', 'Nothing matches.') : (d.error || '')) + '</li>';
                    list.hidden = false;
                } catch (e) { list.hidden = true; }
            }, 180);
        }

        /** Link to the picked record; a conflict asks first and then replaces exactly what it showed. */
        async function pick(otherId, label) {
            const pair = isAsset ? { asset_id: Number(o.id), cmdb_object_id: otherId } : { asset_id: otherId, cmdb_object_id: Number(o.id) };
            let d = await call(base, '', Object.assign({ action: 'link' }, pair));
            if (!d.success && (d.code === 'ci_already_linked' || d.code === 'asset_already_linked')) {
                const ok = window.showConfirm
                    ? await window.showConfirm({ title: L('replace_title', 'Replace the link?'), message: d.error + ' ' + L('replace_body', 'Replacing ends that link - it stays in the link history - and links {name} instead. Nothing is deleted.', { name: label }), okLabel: L('replace', 'Replace'), okClass: 'danger' })
                    : window.confirm(d.error);
                if (!ok) return;
                d = await call(base, '', Object.assign({ action: 'link', replace: true, replaces: d.conflicts || [] }, pair));
            }
            if (!d.success) { toast(d.error || L('failed', 'The link could not be saved.'), 'error'); if (d.code === 'link_changed') load(); return; }
            toast(d.unchanged ? L('unchanged', 'Already linked') : L('linked', 'Linked'), 'success');
            load();
        }

        async function unlink() {
            const cur = state && state.current;
            if (!cur) return;
            const ok = window.showConfirm
                ? await window.showConfirm({ title: L('unlink_title', 'Unlink?'), message: L('unlink_body', 'The link to {name} ends and stays in the link history. Both records are kept.', { name: cur.name || '' }), okLabel: L('unlink', 'Unlink'), okClass: 'danger' })
                : window.confirm(L('unlink_title', 'Unlink?'));
            if (!ok) return;
            const d = await call(base, '', Object.assign({ action: 'unlink', link_id: cur.link_id }, isAsset ? { asset_id: Number(o.id) } : { cmdb_object_id: Number(o.id) }));
            if (!d.success) { toast(d.error || L('failed', 'The link could not be saved.'), 'error'); load(); return; }
            toast(L('unlinked', 'Unlinked'), 'success');
            load();
        }

        host.addEventListener('input', e => { if (e.target.closest('.cil-pick input')) search(e.target.value.trim()); });
        host.addEventListener('click', e => {
            const btn = e.target.closest('[data-cil]');
            const res = e.target.closest('[data-cil-pick]');
            if (res) { e.preventDefault(); pick(Number(res.dataset.cilPick), res.dataset.cilLabel || ''); return; }
            if (!btn) return;
            e.preventDefault();
            const what = btn.dataset.cil;
            if (what === 'link' || what === 'replace') { picking = true; draw(); }
            else if (what === 'cancel') { picking = false; draw(); }
            else if (what === 'unlink') unlink();
            else if (what === 'diagram') openDiagram(Number(btn.dataset.cilCi));
            else if (what === 'create') openCreate();
            else if (what === 'create-cancel') { creating = null; draw(); }
            else if (what === 'create-go') createGo(btn);
        });

        draw();
        load();
        return { reload: load };
    }

    window.CiAssetLink = { mount: mount };
})();
