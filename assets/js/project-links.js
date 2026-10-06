/**
 * The Projects panel other modules show (3.2.0): the projects a record - an
 * asset, a change, a ticket, a contract, a CMDB item or a knowledge article -
 * is part of, each in its own colour with its health and progress, plus
 * "Add to a project" and unlink.
 *
 *   ProjectLinks.mount(hostEl, {
 *       kind:  'asset' | 'change' | 'ticket' | 'contract' | 'cmdb' | 'article',
 *       id:    the record's id,
 *       base:  BASE_URL,
 *       cardClass / headClass / titleClass: the host page's own card classes,
 *       bare:  true when the host draws its own heading - just the body,
 *       editable: false to list only,
 *       hideEmpty: true to draw nothing at all when there is nothing linked,
 *   });
 *
 * Every rule - Projects plus the other module, both ends visible, same company,
 * and Projects -> Settings -> General for who may change a project - lives in
 * includes/projects/links.php. A host page only mounts this for an analyst who
 * can open Projects, so nothing here has to ask.
 *
 * Words use window.tf() with English fallbacks, so a host page does not have to
 * export the whole 'projects' translation namespace to show one panel.
 */
(function () {
    'use strict';
    if (window.ProjectLinks) return;

    const L = (k, en, p) => (window.tf ? window.tf('projects.' + k, en, p) : en);
    const esc = v => (v === null || v === undefined) ? '' : String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (m, k) => { if (window.showToast) window.showToast(m, k); };
    // English for the words a host page may not have exported (projects.status / projects.health).
    const EN = {
        status: { proposed: 'Proposed', active: 'Active', on_hold: 'On hold', closed: 'Closed', cancelled: 'Cancelled' },
        health: { green: 'On track', amber: 'At risk', red: 'Off track', none: 'Finished' }
    };

    async function call(base, path, body) {
        const opts = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
        const r = await fetch(base + 'api/projects/' + path, Object.assign({ credentials: 'same-origin' }, opts));
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'Error');
        return d;
    }

    function row(p, editable, base) {
        const health = p.health ? '<span class="pl-health h-' + esc(p.health) + '" title="' + esc(L('health.' + p.health, EN.health[p.health] || p.health)) + '"></span>' : '';
        return '<li class="pl-row">'
            + '<a class="pl-main" href="' + esc(base + p.url) + '">'
            +   '<span class="pl-dot" style="background:' + esc(p.colour) + '"></span>'
            +   '<span class="pl-text"><span class="pl-name">' + esc(p.name) + '</span>'
            +   '<span class="pl-sub">' + esc(p.code) + ' &middot; ' + esc(L('status.' + p.status, EN.status[p.status] || p.status)) + (p.owner_name ? ' &middot; ' + esc(p.owner_name) : '') + '</span></span>'
            + '</a>'
            + health
            + '<span class="pl-bar" title="' + p.progress + '%"><span style="width:' + p.progress + '%;background:' + esc(p.colour) + '"></span></span>'
            + (editable ? '<button type="button" class="pl-unlink" data-pl-unlink="' + p.id + '" title="' + esc(L('links.remove', 'Unlink')) + '" aria-label="' + esc(L('links.remove', 'Unlink')) + '">&times;</button>' : '')
            + '</li>';
    }

    function mount(host, o) {
        if (!host) return;
        const base = o.base || window.APP_BASE || '/';
        const editable = o.editable !== false;
        let rows = [];

        function draw() {
            if (!rows.length && o.hideEmpty) { host.innerHTML = ''; host.hidden = true; return; }
            host.hidden = false;
            const body = (rows.length ? '<ul class="pl-list">' + rows.map(p => row(p, editable, base)).join('') + '</ul>'
                    : '<p class="pl-empty">' + esc(L('links_other.none', 'Not part of any project.')) + '</p>')
                + (editable ? '<div class="pl-add"><input type="text" placeholder="' + esc(L('links_other.add_ph', 'Add to a project...')) + '" autocomplete="off"><ul class="pl-results" hidden></ul></div>' : '');
            if (o.bare) { host.innerHTML = body; return; }
            host.innerHTML = '<div class="' + esc(o.cardClass || 'pl-card') + '">'
                + '<div class="' + esc(o.headClass || 'pl-head') + '"><span class="' + esc(o.titleClass || 'pl-title') + '">' + esc(L('links_other.title', 'Projects')) + (rows.length ? ' <span class="pl-count">' + rows.length + '</span>' : '') + '</span></div>'
                + '<div class="pl-body">' + body + '</div></div>';
        }

        async function load() {
            try { rows = (await call(base, 'links.php?for=' + o.kind + '&id=' + o.id)).projects || []; } catch (e) { rows = []; }
            draw();
        }

        let timer = null;
        host.addEventListener('input', e => {
            const input = e.target.closest('.pl-add input'); if (!input) return;
            clearTimeout(timer);
            const list = host.querySelector('.pl-results');
            timer = setTimeout(async () => {
                try {
                    const d = await call(base, 'links.php?for=' + o.kind + '&id=' + o.id + '&pick=1&q=' + encodeURIComponent(input.value.trim()));
                    list.innerHTML = (d.projects || []).length ? d.projects.map(p => '<li><button type="button" data-pl-add="' + p.id + '"><span class="pl-dot" style="background:' + esc(p.colour) + '"></span>'
                        + '<span class="pl-name">' + esc(p.name) + '</span><span class="pl-sub">' + esc(p.code) + '</span></button></li>').join('')
                        : '<li class="pl-noresult">' + esc(L('links_other.no_projects', 'No live project you can change matches.')) + '</li>';
                    list.hidden = false;
                } catch (err) { toast(err.message, 'error'); }
            }, 180);
        });
        host.addEventListener('focusin', e => { if (e.target.closest('.pl-add input')) e.target.dispatchEvent(new Event('input', { bubbles: true })); });
        host.addEventListener('click', async e => {
            const add = e.target.closest('[data-pl-add]');
            const un = e.target.closest('[data-pl-unlink]');
            if (!add && !un) { if (!e.target.closest('.pl-add')) { const l = host.querySelector('.pl-results'); if (l) l.hidden = true; } return; }
            e.preventDefault();
            try {
                await call(base, 'links.php', { action: add ? 'add' : 'remove', project_id: Number((add || un).dataset[add ? 'plAdd' : 'plUnlink']), kind: o.kind, target_id: o.id });
                toast(add ? L('links.linked', 'Linked') : L('links.unlinked', 'Unlinked'));
                await load();
            } catch (err) { toast(err.message, 'error'); }
        });
        document.addEventListener('click', e => { if (!host.contains(e.target)) { const l = host.querySelector('.pl-results'); if (l) l.hidden = true; } });
        load();
    }

    window.ProjectLinks = { mount: mount };
})();
