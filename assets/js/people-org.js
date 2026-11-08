/**
 * People - the organisation chart page's script (people/org.php).
 *
 * The tree (Cards, Compact, Columns), the chart, the side panel, Levels, zoom
 * and drag, Refresh and Export - everything the page does in the browser. It
 * moved here from inline in people/org.php so the browser caches it (PR #242's
 * review); people/org.php loads it with a ?v= to bump on every change.
 *
 * From the server it needs only window.PEOPLE_ORG, which the page sets first:
 *   api, list               the endpoints (api/people/org.php, api/people/list.php)
 *   levelsDefault, levelsMax  PEOPLE_ORG_CHART_LEVELS and _MAX (includes/people.php)
 * and window.t() for the strings, as every page has.
 *
 * @wiki Organisation-Chart-Developer-Guide
 */
(function () {
    'use strict';
    // What the page hands over from the server (people/org.php): the two
    // endpoints, and the bounds on levels from includes/people.php.
    var CFG = window.PEOPLE_ORG || {};
    var API = CFG.api, LIST = CFG.list;
    var T = function (k, p) { return window.t('people.' + k, p); };
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
    var $ = function (id) { return document.getElementById(id); };
    var treeEl = $('orgTree'), chartEl = $('orgChart'), statusEl = $('orgStatus'), titleEl = $('orgTitle');
    var leaversEl = $('orgLeavers'), searchEl = $('orgSearch'), resultsEl = $('orgResults');
    var params = new URLSearchParams(location.search);

    // Layout choices are this browser's own - a convenience, never relied on.
    function pref(k, d) { try { var v = localStorage.getItem('people.org.' + k); return v === null ? d : v; } catch (e) { return d; } }
    function setPref(k, v) { try { localStorage.setItem('people.org.' + k, v); } catch (e) {} }
    // Levels: a whole number from 1 to the bound on walking a reporting line, or
    // "all" - which 0 also means, no limit. Checked here so a bad value is
    // refused where it is typed; the server holds it to the same bounds
    // whatever it is sent.
    var LEVELS_DEFAULT = CFG.levelsDefault, LEVELS_MAX = CFG.levelsMax;
    function levelsValue(v) {
        v = String(v == null ? '' : v).trim();
        if (v === 'all') return 'all';
        if (!/^\d+$/.test(v)) return '';
        var n = parseInt(v, 10);
        if (n === 0) return 'all';
        return n >= 1 && n <= LEVELS_MAX ? String(n) : '';
    }
    var state = {
        view: params.get('view') === 'chart' ? 'chart' : 'tree',
        person: parseInt(params.get('person') || '0', 10) || 0,
        treeMode: pref('tree', 'cards'), chartCompact: pref('compact', '0') === '1', chartDir: pref('dir', 'v') === 'h' ? 'h' : 'v',
        branch: null, zoom: 1, cols: [], panelOpen: pref('panel', '1') === '1',
        // With somebody chosen, the chart shows where they sit unless asked for only their part.
        chartScope: pref('scope', 'context') === 'branch' ? 'branch' : 'context',
        // How deep below the person the chart goes; the server holds it to its own bounds.
        chartLevels: pref('levels', String(LEVELS_DEFAULT))
    };
    // A link may name the layout too (?tree=columns, ?layout=compact, ?dir=h), and wins.
    if (params.get('tree')) state.treeMode = params.get('tree');
    if (params.get('layout')) state.chartCompact = params.get('layout') === 'compact';
    if (params.get('dir')) state.chartDir = params.get('dir') === 'h' ? 'h' : 'v';
    if (params.get('levels')) state.chartLevels = params.get('levels');
    state.chartLevels = levelsValue(state.chartLevels) || String(LEVELS_DEFAULT);
    if (['cards', 'compact', 'columns'].indexOf(state.treeMode) < 0) state.treeMode = 'cards';
    // ...and is kept as the choice, as a click would be: the address loses it at once (remember()).
    if (params.get('tree')) setPref('tree', state.treeMode);
    if (params.get('layout')) setPref('compact', state.chartCompact ? '1' : '0');
    if (params.get('dir')) setPref('dir', state.chartDir);
    if (params.get('levels')) setPref('levels', state.chartLevels);
    leaversEl.checked = pref('leavers', '0') === '1';

    function get(qs) {
        return fetch(API + '?' + qs + '&leavers=' + (leaversEl.checked ? '1' : '0'), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }
    function say(text) { statusEl.textContent = text || ''; statusEl.hidden = !text; }
    // Keeps the person and the view in the address (without a new history
    // entry), so F5 and a copied link open the page as it is now.
    function remember() {
        var q = new URLSearchParams();
        if (state.person) q.set('person', state.person);
        if (state.view === 'chart') q.set('view', 'chart');
        history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q.toString() : ''));
    }
    // The person the page is open on, said in the sidebar with a way out:
    // after F5 the address still holds them, and nothing else would say so.
    function setFocus(id, name) {
        state.person = id || 0; remember();
        var el = $('orgFocus');
        el.hidden = !state.person;
        el.innerHTML = state.person
            ? '<span>' + esc(T('org.focus', { name: name || '' })) + '</span><button type="button" id="orgFocusClear" aria-label="' + esc(T('org.focus_clear')) + '" title="' + esc(T('org.focus_clear')) + '">&times;</button>'
            : '';
    }
    // Their team and everybody under them, said in full - or only the team when
    // nobody is under its members.
    function underText(n) {
        return n.total > n.reports ? T('org.reports_total', { n: n.reports, total: n.total }) : T('org.reports_n', { n: n.reports });
    }
    function meta(n) { return [n.job_title, n.department, n.company].filter(Boolean).join(' · '); }
    function initials(name) {
        var p = String(name || '?').trim().split(/[\s@._-]+/).filter(Boolean);
        return ((p[0] || '?').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase();
    }
    // One colour per department, the same everywhere and with nothing to set
    // up. Handed out by the department's place in the sorted list the server
    // sends (state.depts), so the first eight never share one; a department
    // not in it yet falls back to its name. A fixed palette rather than any
    // hue, because nearby hues read as the same colour; each is dark enough
    // for white initials and they differ in lightness too. Leavers grey.
    var PALETTE = ['#1d4ed8', '#b45309', '#047857', '#7c3aed', '#be123c', '#0e7490', '#4d7c0f', '#475569'];
    state.depts = {};
    function colour(n) {
        if (!n.is_active) return '#9ca3af';
        var d = String(n.department || '').trim().toLowerCase();
        if (!d) return 'var(--ppl-accent)';
        if (state.depts[d] !== undefined) return PALETTE[state.depts[d] % PALETTE.length];
        var h = 0;
        for (var i = 0; i < d.length; i++) h = (h * 31 + d.charCodeAt(i)) >>> 0;
        return PALETTE[h % PALETTE.length];
    }
    function avatar(n, size) { return '<span class="ppl-org-av ' + size + '" style="background:' + colour(n) + '" aria-hidden="true">' + esc(initials(n.name)) + '</span>'; }
    function leaverPill(n) { return n.is_active ? '' : ' <span class="ppl-pill bad">' + esc(T('org.leaver')) + '</span>'; }
    // The small button on a tree row that opens the chart from that person.
    function asChart(n) {
        return '<button type="button" class="ppl-org-as-chart" data-chart="' + n.id + '" title="' + esc(T('org.as_chart')) + '" aria-label="' + esc(T('org.as_chart') + ': ' + n.name) + '">'
            + '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="5" rx="1"></rect><rect x="2" y="17" width="6" height="5" rx="1"></rect><rect x="16" y="17" width="6" height="5" rx="1"></rect><path d="M12 7v5M5 17v-3h14v3"></path></svg></button>';
    }
    // One person's panel - the columns' and the chart's. `boss` is who they
    // report to ({id, name}) when the reader may see them; `team` the people
    // under them already drawn, each a button that opens their panel instead.
    function detailHtml(n, boss, actions, team) {
        return '<div class="ppl-org-detail-who">' + avatar(n, 'l') + '<div><div class="ppl-org-detail-name">' + esc(n.name) + leaverPill(n) + '</div><div class="ppl-dim">' + esc(meta(n)) + '</div></div></div>'
            + '<dl class="ppl-fields ppl-org-contact" data-contact="' + n.id + '"></dl>'
            + '<dl class="ppl-fields">'
            + (boss ? '<dt>' + esc(T('org.detail_reports_to')) + '</dt><dd><a href="person.php?id=' + boss.id + '">' + esc(boss.name) + '</a></dd>' : '')
            + '<dt>' + esc(T('org.col_under')) + '</dt><dd>' + (n.reports > 0 ? esc(underText(n)) : esc(T('org.detail_none'))) + '</dd></dl>'
            + (team && team.length ? '<div class="ppl-org-team"><h5>' + esc(T('org.detail_team')) + '</h5>' + team.map(function (c) {
                return '<button type="button" data-select="' + c.id + '">' + avatar(c, 's') + '<span>' + esc(c.name) + '</span></button>';
            }).join('') + '</div>' : '')
            + '<div class="ppl-org-detail-actions"><a class="ppl-btn" href="person.php?id=' + n.id + '">' + esc(T('org.open_page')) + '</a>' + (actions || '') + '</div>';
    }
    // How to reach them, fetched once per person and filled into the panel's
    // placeholder - only if the panel still shows that person by then.
    var contactCache = {};
    var COPY_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
    var DONE_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>';
    // copyToClipboard(), not navigator.clipboard directly - see clipboard.js
    // (loaded by the header): it works over plain HTTP and on iOS, and says
    // true only when the text really is on the clipboard. The result goes in
    // the shared toast (toast.js), which floats and goes - never in the card,
    // where it would push the drawing down.
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.ppl-org-copy');
        if (!b) return;
        var toast = function (text, type) { if (typeof window.showToast === 'function') window.showToast(text, type, 2500); };
        window.copyToClipboard(b.dataset.copy).then(function (ok) {
            if (!ok) { toast(T('org.copy_failed'), 'error'); return; }
            b.innerHTML = DONE_ICON; b.classList.add('done'); b.title = T('org.copied');
            // Which field, not the value: the value is already on screen beside the button.
            toast(T('org.copied_field', { field: b.dataset.field }), 'success');
            setTimeout(function () { b.innerHTML = COPY_ICON; b.classList.remove('done'); b.title = b.getAttribute('aria-label'); }, 1500);
        });
    });
    // Fills a panel's contact details from action=person, fetched when the
    // panel is drawn; a panel replaced while it waited is left alone.
    function fillContact(container) {
        var dl = container && container.querySelector('[data-contact]');
        if (!dl) return;
        var id = dl.getAttribute('data-contact');
        var draw = function (p) {
            if (!p || !dl.isConnected) return;
            // Each value with a button that copies it as it is.
            var row = function (key, value, html) {
                if (!value) return '';
                var label = T('org.copy') + ': ' + T('person.' + key);
                return '<dt>' + esc(T('person.' + key)) + '</dt><dd><span class="ppl-org-val">' + (html || esc(value)) + '</span>'
                    + '<button type="button" class="ppl-org-copy" data-copy="' + esc(value) + '" data-field="' + esc(T('person.' + key)) + '" aria-label="' + esc(label) + '" title="' + esc(label) + '">' + COPY_ICON + '</button></dd>';
            };
            var tel = function (v) { return '<a href="tel:' + esc(String(v).replace(/[^\d+]/g, '')) + '">' + esc(v) + '</a>'; };
            dl.innerHTML = row('email', p.email, p.email ? '<a href="mailto:' + esc(p.email) + '">' + esc(p.email) + '</a>' : '')
                + row('phone', p.phone, p.phone ? tel(p.phone) : '') + row('mobile', p.mobile, p.mobile ? tel(p.mobile) : '')
                + row('office', p.office) + row('employee_id', p.employee_id) + row('company', p.company);
        };
        if (contactCache[id]) { draw(contactCache[id]); return; }
        fetch(API + '?action=person&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.success) { contactCache[id] = d.person; draw(d.person); } })
            .catch(function () {});
    }
    // Marks which button of a group (all carrying `attr`) is the one in use.
    function pressed(attr, value) {
        document.querySelectorAll('[' + attr + ']').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute(attr) === value ? 'true' : 'false'); });
    }

    // ------------------------------------------------- tree: cards, compact
    function nodeHtml(n) {
        var toggle = n.reports > 0
            ? '<button type="button" class="ppl-org-toggle" aria-expanded="false" aria-label="' + esc(T('org.expand', { name: n.name })) + '"></button>'
            : '<span class="ppl-org-toggle none" aria-hidden="true"></span>';
        var link = '<a class="ppl-org-name" href="person.php?id=' + n.id + '">' + esc(n.name) + '</a>' + leaverPill(n);
        var row = state.treeMode === 'compact'
            ? toggle + avatar(n, 's') + '<span class="ppl-org-who">' + link + '</span>'
                + '<span class="ppl-org-cells"><span>' + esc(n.job_title || '') + '</span><span>' + esc(n.department || '') + '</span>'
                + '<span class="n" title="' + (n.reports > 0 ? esc(underText(n)) : '') + '">' + (n.reports > 0 ? n.reports + (n.total > n.reports ? ' (' + n.total + ')' : '') : '') + '</span></span>'
            : toggle + avatar(n, 'm') + '<span class="ppl-org-who">' + link + '<span class="ppl-org-meta">' + esc(meta(n)) + '</span></span>'
                + (n.reports > 0 ? '<span class="ppl-org-n" title="' + esc(underText(n)) + '">' + n.reports + (n.total > n.reports ? ' · ' + n.total : '') + '</span>' : '');
        return '<li class="ppl-org-node' + (n.reports > 0 ? ' has-team' : '') + '" data-id="' + n.id + '" data-name="' + esc(n.name) + '">'
            + '<div class="ppl-org-row' + (n.is_active ? '' : ' leaver') + '">' + row + asChart(n) + '</div></li>';
    }
    // One level of the tree as a list under `container`, with a line saying
    // when the server held some back (PEOPLE_ORG_LEVEL_LIMIT).
    function renderLevel(container, d) {
        var ul = document.createElement('ul');
        ul.className = 'ppl-org-level';
        ul.innerHTML = d.people.map(nodeHtml).join('')
            + (d.more ? '<li class="ppl-org-more">' + esc(T('org.level_more', { n: d.limit })) + '</li>' : '');
        container.appendChild(ul);
        return ul;
    }
    // Compact's column titles; nothing in cards.
    function cheadHtml() {
        return state.treeMode === 'compact'
            ? '<div class="ppl-org-chead" aria-hidden="true"><span>' + esc(T('org.col_job')) + '</span><span>' + esc(T('org.col_department')) + '</span><span class="n">' + esc(T('org.col_under')) + '</span></div>'
            : '';
    }
    // Starts the tree (Cards or Compact) again from the top of the organisation.
    function loadTop() {
        treeEl.className = 'ppl-org-tree ' + state.treeMode;
        treeEl.dataset.mode = state.treeMode;
        treeEl.innerHTML = cheadHtml();
        say(T('org.loading'));
        return get('action=level&id=0').then(function (d) {
            if (!d.success) { say(d.error); return; }
            say(d.people.length ? '' : T('org.empty'));
            renderLevel(treeEl, d);
        });
    }
    // Opens or closes one person's team in the tree (`open` omitted: toggles).
    // Their team is fetched the first time only; afterwards it is shown or
    // hidden. Resolves once it is on the page, so callers can chain opens.
    function expand(li, open) {
        var btn = li.querySelector(':scope > .ppl-org-row > .ppl-org-toggle');
        if (!btn || btn.classList.contains('none')) return Promise.resolve();
        var sub = li.querySelector(':scope > ul');
        var want = open === undefined ? btn.getAttribute('aria-expanded') !== 'true' : open;
        btn.setAttribute('aria-expanded', want ? 'true' : 'false');
        btn.setAttribute('aria-label', T(want ? 'org.collapse' : 'org.expand', { name: li.dataset.name }));
        if (!want) { if (sub) sub.hidden = true; saveOpen(); return Promise.resolve(); }
        if (sub) { sub.hidden = false; saveOpen(); return Promise.resolve(); }
        return get('action=level&id=' + li.dataset.id).then(function (d) { if (d.success) renderLevel(li, d); saveOpen(); });
    }
    // Opens the tree down a reporting line - `ids` top first, from action=path -
    // one level at a time, then marks and scrolls to the last person.
    function jumpTree(ids) {
        var i = 0;
        var step = function () {
            var li = treeEl.querySelector('li[data-id="' + ids[i] + '"]');
            if (!li) { say(T('org.not_found')); return false; }
            if (i === ids.length - 1) {
                treeEl.querySelectorAll('.ppl-org-row.hit').forEach(function (r) { r.classList.remove('hit'); });
                var row = li.querySelector('.ppl-org-row');
                row.classList.add('hit');
                row.scrollIntoView({ block: 'nearest' });
                var link = row.querySelector('.ppl-org-name'); if (link) link.focus({ preventScroll: true });
                say('');
                return true;
            }
            i++;
            return expand(li, true).then(step);
        };
        return Promise.resolve(step());
    }

    // --------------------------------------------------------- tree: columns
    // state.cols: one entry per column - who it lists, and who is chosen in it.
    function colsTop() {
        treeEl.className = 'ppl-org-tree columns';
        treeEl.dataset.mode = 'columns';
        treeEl.innerHTML = '<nav class="ppl-org-crumbs" aria-label="' + esc(T('org.crumbs')) + '"></nav>'
            + '<div class="ppl-org-colsview"><div class="ppl-org-strip"></div><aside class="ppl-org-detail" hidden></aside></div>';
        state.cols = [];
        say(T('org.loading'));
        return get('action=level&id=0').then(function (d) {
            if (!d.success) { say(d.error); return; }
            say(d.people.length ? '' : T('org.empty'));
            state.cols = [{ parent: null, people: d.people, more: d.more, sel: 0 }];
            drawCols();
        });
    }
    // Columns: choosing person `id` in column `i` drops the columns to its right
    // and, if they manage anybody, adds a column with their team.
    function pickInCol(i, id) {
        var col = state.cols[i];
        var n = col ? col.people.filter(function (p) { return p.id === id; })[0] : null;
        if (!n) return Promise.resolve(false);
        col.sel = id;
        state.cols = state.cols.slice(0, i + 1);
        setFocus(id, n.name);
        if (!(n.reports > 0)) { drawCols(); return Promise.resolve(true); }
        return get('action=level&id=' + id).then(function (d) {
            if (d.success) state.cols.push({ parent: n, people: d.people, more: d.more, sel: 0 });
            drawCols();
            return true;
        });
    }
    // Draws the Columns layout from state.cols: the columns, the path naming
    // each level above them, and the chosen person's panel on the right.
    function drawCols() {
        var strip = treeEl.querySelector('.ppl-org-strip'), crumbs = treeEl.querySelector('.ppl-org-crumbs'), detail = treeEl.querySelector('.ppl-org-detail');
        if (!strip) return;
        var deepest = -1;
        state.cols.forEach(function (c, i) { if (c.sel) deepest = i; });
        strip.innerHTML = state.cols.map(function (c, i) {
            return '<div class="ppl-org-col"><h4>' + esc(c.parent ? T('org.col_reports', { name: c.parent.name }) : T('org.col_top')) + '</h4>'
                + c.people.map(function (p) {
                    var on = p.id === c.sel;
                    return '<button type="button" class="ppl-org-pick' + (on ? (i === deepest ? ' cur' : ' on') : '') + (p.is_active ? '' : ' leaver') + '" data-col="' + i + '" data-pick="' + p.id + '" aria-pressed="' + (on ? 'true' : 'false') + '">'
                        + avatar(p, 's') + '<span class="t"><b>' + esc(p.name) + '</b><span>' + esc(p.is_active ? (p.job_title || p.department || '') : T('org.leaver')) + '</span></span>'
                        + '<span class="n" title="' + (p.reports > 0 ? esc(underText(p)) : '') + '">' + (p.reports > 0 ? p.reports + (p.total > p.reports ? ' · ' + p.total : '') + ' ›' : '') + '</span></button>';
                }).join('')
                + (c.more ? '<p class="ppl-org-more">' + esc(T('org.level_more', { n: c.people.length })) + '</p>' : '') + '</div>';
        }).join('');

        var trail = state.cols.filter(function (c) { return c.sel; });
        crumbs.innerHTML = '<button type="button" class="ppl-org-crumb" data-top="1">' + esc(T('org.col_top')) + '</button>'
            + trail.map(function (c, i) {
                var p = c.people.filter(function (x) { return x.id === c.sel; })[0];
                return '<span aria-hidden="true">›</span><button type="button" class="ppl-org-crumb" data-col="' + i + '" data-pick="' + c.sel + '"' + (i === trail.length - 1 ? ' aria-current="true"' : '') + '>' + esc(p ? p.name : '') + '</button>';
            }).join('');

        if (deepest < 0) { detail.hidden = true; }
        else {
            var col = state.cols[deepest], n = col.people.filter(function (x) { return x.id === col.sel; })[0];
            detail.hidden = false;
            detail.innerHTML = detailHtml(n, col.parent ? { id: col.parent.id, name: col.parent.name } : null,
                n.reports > 0 ? '<button type="button" class="ppl-btn" data-chart="' + n.id + '">' + esc(T('org.as_chart')) + '</button>' : '');
            fillContact(detail);
        }
        strip.scrollLeft = strip.scrollWidth;
    }
    // jumpTree() for the Columns layout: chooses each person on the line in turn.
    function jumpCols(ids) {
        var i = 0;
        var step = function () {
            return pickInCol(i, ids[i]).then(function (ok) {
                if (!ok) { say(T('org.not_found')); return false; }
                if (++i < ids.length) return step();
                say('');
                return true;
            });
        };
        return step();
    }

    // Opens the tree on one person: asks for their reporting line, ticks
    // Show leavers first if they have left, then opens the line in whichever
    // layout is showing.
    function jumpTo(id) {
        state.person = id; remember();
        return get('action=path&id=' + id).then(function (d) {
            if (!d.success) { setFocus(0); say(T('org.not_found')); return; }
            setFocus(id, d.name);
            if (!d.is_active && !leaversEl.checked) {
                leaversEl.checked = true; setPref('leavers', '1');
                return loadTree().then(function () { return jumpTo(id); });
            }
            return (state.treeMode === 'columns' ? jumpCols(d.ids) : jumpTree(d.ids)).then(function (found) {
                // A current person can have a place only with leavers shown - one
                // whose only reports have left - so try once more with them.
                if (found || leaversEl.checked) return;
                leaversEl.checked = true; setPref('leavers', '1');
                return loadTree().then(function () { return jumpTo(id); });
            });
        });
    }
    function loadTree() { return state.treeMode === 'columns' ? colsTop() : loadTop(); }

    // What is open now, top first, so a change of layout can open it again:
    // the open branches in cards and compact, the chosen line in columns.
    function openIds() {
        if (treeEl.dataset.mode === 'columns') return state.cols.filter(function (c) { return c.sel; }).map(function (c) { return c.sel; });
        return Array.prototype.filter.call(treeEl.querySelectorAll('.ppl-org-toggle[aria-expanded="true"]'), function (b) { return !b.closest('[hidden]'); })
            .map(function (b) { return parseInt(b.closest('li').dataset.id, 10); });
    }
    // The open branches survive F5 for this tab (sessionStorage: a new tab
    // starts closed). Cards and compact only - columns keep their line in ?person=.
    function saveOpen() {
        if (treeEl.dataset.mode === 'columns' || state.restoring) return;
        try { sessionStorage.setItem('people.org.open', JSON.stringify(openIds())); } catch (e) {}
    }
    // The ids of the teams that were open before F5 (see saveOpen()).
    function savedOpen() {
        try { var v = JSON.parse(sessionStorage.getItem('people.org.open') || '[]'); return Array.isArray(v) ? v.map(Number).filter(Boolean) : []; } catch (e) { return []; }
    }
    // Opens those teams again, one after another - a team can only open once
    // the team above it is on the page.
    function reopen(ids) {
        if (!ids.length) return Promise.resolve();
        // Columns show one line, not several branches: the person, if any, decides it.
        if (state.treeMode === 'columns') return Promise.resolve();
        var i = 0;
        // Not saved while it is being put back, or a half-done restore would overwrite the list.
        state.restoring = true;
        var step = function () {
            if (i >= ids.length) { state.restoring = false; saveOpen(); return; }
            var li = treeEl.querySelector('li[data-id="' + ids[i++] + '"]');
            return (li ? expand(li, true) : Promise.resolve()).then(step);
        };
        return step();
    }

    // Expand all opens team after team, a few at a time, and stops at a
    // ceiling: on a large organisation "everything" is thousands of requests.
    var EXPAND_MAX = 300;
    // Opens every closed team that is visible, ten at a time, round after
    // round until none are left or EXPAND_MAX have been opened.
    function expandAll() {
        var opened = 0;
        say(T('org.loading'));
        var round = function () {
            var closed = Array.prototype.filter.call(treeEl.querySelectorAll('.ppl-org-toggle[aria-expanded="false"]'),
                function (b) { return !b.closest('[hidden]'); });
            if (!closed.length) { say(''); return; }
            if (opened >= EXPAND_MAX) { say(T('org.expand_limit', { n: opened })); return; }
            var batch = closed.slice(0, Math.min(10, EXPAND_MAX - opened));
            opened += batch.length;
            return Promise.all(batch.map(function (b) { return expand(b.closest('li'), true); })).then(round);
        };
        return round();
    }
    $('orgExpandAll').addEventListener('click', expandAll);
    $('orgCollapseAll').addEventListener('click', function () {
        treeEl.querySelectorAll('.ppl-org-toggle[aria-expanded="true"]').forEach(function (b) { expand(b.closest('li'), false); });
        say('');
    });

    treeEl.addEventListener('click', function (e) {
        var t = e.target.closest('.ppl-org-toggle');
        if (t && !t.classList.contains('none')) { expand(t.closest('li')); return; }
        var c = e.target.closest('[data-chart]');
        if (c) { state.person = parseInt(c.dataset.chart, 10); setView('chart'); return; }
        var top = e.target.closest('[data-top]');
        if (top) { state.cols = state.cols.slice(0, 1); state.cols[0].sel = 0; setFocus(0); drawCols(); return; }
        var p = e.target.closest('[data-pick]');
        if (p) pickInCol(parseInt(p.dataset.col, 10), parseInt(p.dataset.pick, 10));
    });

    // --------------------------------------------------------------- chart
    // Compact: people with no team of their own are stacked under their
    // manager (top to bottom), or drawn as smaller boxes (left to right).
    function leafy(n) {
        return state.chartCompact && n.children.length > 0
            && n.children.every(function (c) { return !c.children.length && !(c.more > 0); });
    }
    // One person's box in the chart: name, job (or "Leaver"), and a badge with
    // how many are under them - filled when all are drawn, outlined when not.
    function cardHtml(n, isRoot, small) {
        var job = n.is_active ? (n.job_title || n.department || '') : T('org.leaver');
        var label = n.name + (meta(n) ? ', ' + meta(n) : '') + (n.reports > 0 ? ', ' + underText(n) : '') + (n.more > 0 ? ', ' + T('org.more_under', { n: n.more }) : '');
        // Filled: everybody under them is drawn. Outlined: click to see the rest.
        var badge = n.reports > 0
            ? '<span class="ppl-oc-badge' + (n.more > 0 ? '' : ' open') + '" aria-hidden="true">' + (n.more > 0 && n.children.length ? '+' + n.more : n.reports) + '</span>'
            : '';
        // The ring marks the person the drawing is about - the top of their branch, or
        // the end of the line in the organisation - not simply the box at the top.
        var isFocus = !!n.id && n.id === state.chartFocus;
        var pill = isFocus ? '<span class="ppl-oc-pill" aria-hidden="true">' + esc(T('org.focus_pill')) + '</span>' : '';
        return '<div class="ppl-oc-card' + (isFocus ? ' root' : '') + (n.is_active ? '' : ' leaver') + (small ? ' small' : '') + '" style="--dc:' + colour(n) + '"'
            + ' tabindex="0" role="button" data-id="' + n.id + '" aria-label="' + esc(label) + '" title="' + esc(label) + '">'
            + (small ? '' : avatar(n, 'm'))
            + '<span class="ppl-oc-txt"><span class="ppl-oc-name">' + esc(n.name) + '</span><span class="ppl-oc-job">' + esc(job) + '</span></span>'
            + badge + pill + '</div>';
    }
    // A person and everybody drawn under them, top to bottom (Compact stacks a
    // team with no teams of its own - see leafy()).
    function vNode(n, isRoot) {
        var kids = '';
        if (n.children.length) {
            kids = leafy(n)
                ? '<ul class="ppl-oc-stack">' + n.children.map(function (c) { return '<li>' + cardHtml(c, false, true) + '</li>'; }).join('') + '</ul>'
                : '<ul class="t">' + n.children.map(function (c) { return vNode(c, false); }).join('') + '</ul>';
        }
        return '<li class="t">' + cardHtml(n, isRoot, false) + kids + '</li>';
    }
    // vNode() for Left to right.
    function hNode(n, isRoot) {
        var kids = '';
        if (n.children.length) {
            var small = leafy(n);
            kids = '<div class="h-kids">' + n.children.map(function (c) {
                return small ? '<div class="h-node">' + cardHtml(c, false, true) + '</div>' : hNode(c, false);
            }).join('') + '</div>';
        }
        return '<div class="h-node">' + cardHtml(n, isRoot, false) + kids + '</div>';
    }
    function findNode(n, id) {
        if (n.id === id) return n;
        for (var i = 0; i < n.children.length; i++) { var f = findNode(n.children[i], id); if (f) return f; }
        return null;
    }
    // keepView: the same zoom and scroll as before (a refresh).
    // keepZoom: the same zoom only - a layout switch (Cards / Compact, the
    // direction) draws the same people, so their size must not jump. Refitted,
    // a slightly narrower Compact drawing could land on the next 10% step and
    // look bigger than the same cards in Cards. Only a new drawing is fitted.
    function drawChart(keepView, keepZoom) {
        var oldCanvas = chartEl.querySelector('.ppl-org-canvas');
        var scroll = keepView && oldCanvas ? [oldCanvas.scrollLeft, oldCanvas.scrollTop] : null;
        var b = state.branch, root = b.root, whole = root.id === 0;
        state.chartFocus = whole ? 0 : (b.focus || root.id);
        var tops = whole ? root.children : [root];
        var body = state.chartDir === 'h'
            ? '<div class="h-roots">' + tops.map(function (n) { return hNode(n, !whole); }).join('') + '</div>'
            : '<ul class="t">' + tops.map(function (n) { return vNode(n, !whole); }).join('') + '</ul>';

        // The departments in this drawing, as the colour key.
        var seen = {}, legend = [];
        (function walk(n) {
            if (n.id && n.is_active && n.department && !seen[n.department.toLowerCase()]) { seen[n.department.toLowerCase()] = 1; legend.push(n); }
            n.children.forEach(walk);
        })(root);
        legend.sort(function (a, c) { return a.department.localeCompare(c.department); });

        // Who the drawing is about: nobody (the whole organisation), the top of
        // their branch, or - in the organisation - the end of the line.
        var context = !!b.focus, focus = whole ? null : (context ? findNode(root, b.focus) : root);
        var title = whole ? T('org.chart_all') : (focus ? focus.name : root.name);
        var scopeSwitch = whole ? '' : '<div class="ppl-org-seg ppl-org-scope" role="group" aria-label="' + esc(T('org.scope')) + '">'
            + ['context', 'branch'].map(function (s) {
                return '<button type="button" data-scope="' + s + '" aria-pressed="' + (state.chartScope === s ? 'true' : 'false') + '">' + esc(T('org.scope_' + s)) + '</button>';
            }).join('') + '</div>';
        var head = '<div class="ppl-org-chart-head"><div class="ppl-org-chart-title"><span><strong>' + esc(title) + '</strong>'
            + (whole ? '' : (focus && meta(focus) ? ' <span class="ppl-dim">' + esc(meta(focus)) + '</span>' : '')
                + ' · <a href="person.php?id=' + focus.id + '">' + esc(T('org.open_page')) + '</a>'
                + (!context && b.manager
                    ? ' · <a href="#" data-up="' + b.manager.id + '">' + esc(T('org.chart_up', { name: b.manager.name })) + '</a>'
                    : ' · <a href="#" data-up="0">' + esc(T('org.chart_all')) + '</a>'))
            + '</span>' + scopeSwitch + '</div>'
            + '<div class="ppl-dim ppl-org-small">' + esc(T(whole ? 'org.chart_note_all' : (context ? 'org.chart_note_context' : 'org.chart_note'), { depth: T(state.chartLevels === 'all' ? 'org.depth_all' : (state.levels === 1 ? 'org.depth_1' : 'org.depth_n'), { n: state.levels }), max: state.max }))
            + (b.truncated ? ' ' + esc(T('org.chart_truncated')) : '') + '</div>'
            + (legend.length ? '<div class="ppl-org-legend">' + legend.map(function (n) { return '<span><i style="background:' + colour(n) + '"></i>' + esc(n.department) + '</span>'; }).join('') + '</div>' : '')
            + '</div>';
        chartEl.innerHTML = head + '<div class="ppl-org-chartbody">'
            + '<div class="ppl-org-canvas"><div class="ppl-oc-scale"><div class="ppl-oc ' + state.chartDir + '" role="group" aria-label="' + esc(T('org.view_chart') + ': ' + title) + '">' + body + '</div></div></div>'
            + '<aside class="ppl-org-detail' + (state.panelOpen ? '' : ' collapsed') + '" id="orgChartDetail">'
            + '<button type="button" class="ppl-org-panel-toggle" id="orgPanelToggle" aria-expanded="' + (state.panelOpen ? 'true' : 'false') + '"'
            + ' aria-label="' + esc(T(state.panelOpen ? 'org.panel_hide' : 'org.panel_show')) + '" title="' + esc(T(state.panelOpen ? 'org.panel_hide' : 'org.panel_show')) + '">' + (state.panelOpen ? '&rsaquo;' : '&lsaquo;') + '</button>'
            + '<div class="ppl-org-panel-body" id="orgPanelBody" aria-live="polite"><p class="ppl-org-detail-hint">' + esc(T('org.chart_detail_hint')) + '</p></div></aside></div>';

        // Who everybody in the drawing reports to, for their panel.
        state.chartIndex = {};
        (function index(n, boss) {
            if (n.id) state.chartIndex[n.id] = { n: n, boss: boss };
            n.children.forEach(function (c) { index(c, n.id ? { id: n.id, name: n.name } : null); });
        })(root, whole ? null : b.manager);

        // A new drawing opens fitted to the width when it is wider, never below
        // half size: past that the names stop being readable. 100% undoes it.
        var inner = chartEl.querySelector('.ppl-oc'), canvas = chartEl.querySelector('.ppl-org-canvas'), avail = canvas.clientWidth - 32;
        if (!scroll && !keepZoom) state.zoom = inner.offsetWidth > avail && avail > 0 ? Math.max(0.5, Math.floor(avail / inner.offsetWidth * 10) / 10) : 1;
        sizeChart();
        if (scroll) { canvas.scrollLeft = scroll[0]; canvas.scrollTop = scroll[1]; }
        else if (context) {
            // The person the line ends at may be far down or across: bring them into view.
            var card = chartEl.querySelector('.ppl-oc-card[data-id="' + b.focus + '"]');
            if (card) {
                var cr = card.getBoundingClientRect(), vr = canvas.getBoundingClientRect();
                canvas.scrollLeft += (cr.left + cr.width / 2) - (vr.left + vr.width / 2);
                canvas.scrollTop += (cr.top + cr.height / 2) - (vr.top + vr.height / 2);
            }
        }
        state.chartSel = 0;
        if (!whole) selectInChart(state.chartFocus);
        applyDim();
    }
    // Click: who they are, in the panel. Double-click, or Draw from here: their branch.
    // show: the reader picked them, so a folded panel opens to say who they are.
    function selectInChart(id, show) {
        var entry = state.chartIndex && state.chartIndex[id], panel = $('orgPanelBody');
        if (!entry || !panel) return;
        if (show && !state.panelOpen) togglePanel();
        chartEl.querySelectorAll('.ppl-oc-card.sel').forEach(function (c) { c.classList.remove('sel'); });
        var card = chartEl.querySelector('.ppl-oc-card[data-id="' + id + '"]');
        if (card) card.classList.add('sel');
        state.chartSel = id;
        applyDim();
        var n = entry.n, isRoot = n.id === state.chartFocus;
        panel.innerHTML = detailHtml(n, entry.boss,
            (isRoot ? '' : '<button type="button" class="ppl-btn" data-root="' + n.id + '">' + esc(T('org.chart_draw')) + '</button>')
            + '<button type="button" class="ppl-btn" data-tree="' + n.id + '">' + esc(T('org.show_in_tree')) + '</button>',
            n.children);
        fillContact(panel);
    }
    // Dim the others: only once somebody is chosen or selected - with nobody,
    // it would fade the whole drawing and pick out nothing.
    function applyDim() {
        var inner = chartEl.querySelector('.ppl-oc');
        if (inner) inner.classList.toggle('dim', $('orgDim').checked && !!(state.chartFocus || state.chartSel));
    }
    // The panel folds away to give the chart the room; remembered in this browser.
    function togglePanel() {
        state.panelOpen = !state.panelOpen;
        setPref('panel', state.panelOpen ? '1' : '0');
        var aside = $('orgChartDetail'), btn = $('orgPanelToggle');
        if (!aside) return;
        aside.classList.toggle('collapsed', !state.panelOpen);
        btn.setAttribute('aria-expanded', state.panelOpen ? 'true' : 'false');
        btn.setAttribute('aria-label', T(state.panelOpen ? 'org.panel_hide' : 'org.panel_show'));
        btn.title = T(state.panelOpen ? 'org.panel_hide' : 'org.panel_show');
        btn.innerHTML = state.panelOpen ? '&rsaquo;' : '&lsaquo;';
    }
    // Applies the zoom. A CSS scale does not change the space a box takes, so
    // the wrapper is sized to the scaled drawing by hand - or the scroll
    // area would stay the unscaled size.
    function sizeChart() {
        var inner = chartEl.querySelector('.ppl-oc');
        if (!inner) return;
        var w = inner.offsetWidth, h = inner.offsetHeight, wrap = inner.parentNode;
        inner.style.transform = 'scale(' + state.zoom + ')';
        wrap.style.overflow = 'hidden';
        wrap.style.width = Math.ceil(w * state.zoom) + 'px';
        wrap.style.height = Math.ceil(h * state.zoom) + 'px';
        $('orgZoomLevel').textContent = Math.round(state.zoom * 100) + '%';
    }
    // Zoom about a point on screen (the pointer, or the middle of the drawing),
    // which stays where it was - so the wheel zooms into what is under it.
    var ZOOM_MIN = 0.4, ZOOM_MAX = 2;
    function zoomTo(z, x, y) {
        var canvas = chartEl.querySelector('.ppl-org-canvas'), wrap = chartEl.querySelector('.ppl-oc-scale');
        if (!canvas || !wrap) return;
        z = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, Math.round(z * 100) / 100));
        if (z === state.zoom) return;
        if (x === undefined) { var c = canvas.getBoundingClientRect(); x = c.left + c.width / 2; y = c.top + c.height / 2; }
        var r = wrap.getBoundingClientRect(), px = (x - r.left) / state.zoom, py = (y - r.top) / state.zoom;
        state.zoom = z;
        sizeChart();
        r = wrap.getBoundingClientRect();
        canvas.scrollLeft += r.left + px * z - x;
        canvas.scrollTop += r.top + py * z - y;
    }
    // id 0 is the whole organisation, as the tree shows it.
    // quiet: a refresh - no "Loading", and the zoom and scroll stay where they are.
    function loadChart(id, quiet) {
        state.person = id || 0; remember();
        if (!quiet) say(T('org.loading'));
        // Somebody chosen: where they sit (context), or only their part (branch).
        var action = id && state.chartScope === 'context' ? 'context' : 'branch';
        return get('action=' + action + '&id=' + (id || 0) + '&levels=' + encodeURIComponent(state.chartLevels)).then(function (d) {
            if (!d.success) { chartEl.innerHTML = ''; setFocus(0); say(T('org.not_found')); return false; }
            if (!id && !d.branch.root.children.length) { chartEl.innerHTML = ''; say(T('org.empty')); return true; }
            // In the organisation the top box is the top manager, not the person chosen.
            setFocus(id || 0, d.branch.focus ? (findNode(d.branch.root, d.branch.focus) || {}).name : d.branch.root.name);
            state.branch = d.branch; state.levels = d.levels; state.max = d.max;
            // What the server drew to, which is what the buttons show.
            state.chartLevels = d.all ? 'all' : String(d.levels); showLevels();
            if (!quiet) say('');
            drawChart(quiet);
            return true;
        });
    }
    chartEl.addEventListener('click', function (e) {
        if (e.target.closest('#orgPanelToggle')) { togglePanel(); return; }
        var scope = e.target.closest('[data-scope]');
        if (scope) {
            if (scope.dataset.scope === state.chartScope) return;
            state.chartScope = scope.dataset.scope; setPref('scope', state.chartScope);
            loadChart(state.chartFocus || state.person);
            return;
        }
        var up = e.target.closest('[data-up]');
        if (up) { e.preventDefault(); loadChart(parseInt(up.dataset.up, 10)); return; }
        var root = e.target.closest('[data-root]');
        if (root) { loadChart(parseInt(root.dataset.root, 10)); return; }
        var tree = e.target.closest('[data-tree]');
        if (tree) { state.person = parseInt(tree.dataset.tree, 10); setView('tree'); return; }
        var pick = e.target.closest('[data-select], .ppl-oc-card');
        if (pick) selectInChart(parseInt(pick.dataset.select || pick.dataset.id, 10), true);
    });
    chartEl.addEventListener('dblclick', function (e) {
        var card = e.target.closest('.ppl-oc-card');
        if (card && state.branch && parseInt(card.dataset.id, 10) !== state.chartFocus) loadChart(parseInt(card.dataset.id, 10));
    });
    chartEl.addEventListener('keydown', function (e) {
        var card = e.target.closest('.ppl-oc-card');
        if (card && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); selectInChart(parseInt(card.dataset.id, 10), true); }
    });
    // The wheel scrolls the drawing, as it scrolls any page (Shift and the wheel:
    // sideways); Ctrl or Cmd and the wheel zooms it, as in drawing tools - and a
    // trackpad pinch arrives as exactly that. The wheel alone zooming made a page
    // that only had to be scrolled past grow and shrink under the pointer.
    // Over the drawing, Ctrl and the wheel is the drawing's, not the browser's
    // page zoom; anywhere else the browser keeps it. A trackpad's many small
    // steps add up to the same as a mouse's few large ones.
    chartEl.addEventListener('wheel', function (e) {
        if (!e.target.closest('.ppl-org-canvas')) return;
        if (!e.ctrlKey && !e.metaKey) return;   // a plain scroll: the browser's
        e.preventDefault();
        var dy = e.deltaMode === 1 ? e.deltaY * 33 : e.deltaY;
        zoomTo(state.zoom * Math.exp(-Math.max(-300, Math.min(300, dy)) * 0.0015), e.clientX, e.clientY);
    }, { passive: false });
    // Drag to move the drawing. A press that moves a few pixels is a drag, and
    // the click it ends with is swallowed so it does not also pick somebody;
    // a press that stays put is a click as before. Touch scrolls natively.
    var drag = null;
    chartEl.addEventListener('pointerdown', function (e) {
        var canvas = e.target.closest('.ppl-org-canvas');
        if (!canvas || e.button !== 0 || e.pointerType === 'touch' || e.target.closest('a, button, input')) return;
        drag = { canvas: canvas, id: e.pointerId, x: e.clientX, y: e.clientY, left: canvas.scrollLeft, top: canvas.scrollTop, moved: false };
    });
    chartEl.addEventListener('pointermove', function (e) {
        if (!drag || e.pointerId !== drag.id) return;
        var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
        if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 5) return;
        if (!drag.moved) { drag.moved = true; drag.canvas.classList.add('panning'); drag.canvas.setPointerCapture(drag.id); }
        drag.canvas.scrollLeft = drag.left - dx;
        drag.canvas.scrollTop = drag.top - dy;
    });
    var endDrag = function (e) {
        if (!drag || e.pointerId !== drag.id) return;
        if (drag.moved) {
            drag.canvas.classList.remove('panning');
            var swallow = function (c) { c.stopPropagation(); c.preventDefault(); };
            window.addEventListener('click', swallow, { capture: true, once: true });
            setTimeout(function () { window.removeEventListener('click', swallow, true); }, 0);
        }
        drag = null;
    };
    chartEl.addEventListener('pointerup', endDrag);
    chartEl.addEventListener('pointercancel', endDrag);
    document.querySelectorAll('[data-zoom]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var z = parseInt(btn.dataset.zoom, 10);
            zoomTo(z === 0 ? 1 : Math.round((state.zoom + z * 0.2) * 10) / 10);
        });
    });

    // A file name from the drawing's person, safe on every system.
    function fileName(ext) { var f = state.chartFocus && state.chartIndex[state.chartFocus]; return 'org-chart-' + ((f && f.n.name) || 'organisation').replace(/[^\w\-]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase() + '.' + ext; }
    // Saves a file the page has made, through a temporary link.
    function download(blob, name) {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = name;
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }
    // -------------------------------------------------------------- export
    // ⚠️ DRAWN FROM THE DATA, NOT PHOTOGRAPHED FROM THE SCREEN. The first
    // version captured the page with the bundled html2canvas (1.4.1), which
    // cannot parse modern CSS colours (color-mix() computes to color(srgb …))
    // and failed silently: the chart jumped to a corner and nothing downloaded.
    // So the images are an SVG built here from state.branch - crisp at any
    // size, whatever the zoom, whatever the theme - and PNG and PDF are made
    // from that SVG by the browser and the shared PDF writer (pdf-writer.js).
    var EXPORT_ACCENT = '#a21caf';
    // Who an export starts from: the person drawn from, or - for the whole
    // organisation, whose root is an id-0 stand-in - the people at the top.
    function exportTops() {
        var root = state.branch.root;
        return root.id === 0 ? root.children : [root];
    }
    // The heading on an exported image: that person, or "the whole organisation".
    function exportTitle() {
        var f = state.chartFocus && state.chartIndex[state.chartFocus];
        return f ? f.n.name : T('org.chart_all');
    }
    function cut(s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; }
    // Written whole now this is a .js file. It was split while the script sat in
    // people/org.php, where "<" "?" together opens PHP with short_open_tag on.
    var XML_DECL = '<?xml version="1.0" encoding="UTF-8"?>';
    // Text for SVG and XML. Not esc(): that goes through innerHTML, which writes
    // a no-break space as &nbsp; - not an XML entity, so the file would not
    // open. Control characters XML cannot hold at all are dropped.
    function xesc(s) {
        return String(s == null ? '' : s).replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\uFFFE\uFFFF]/g, '')
            .replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c]; });
    }
    // The chart as an SVG, laid out again from the data - boxes of a fixed
    // size placed slot by slot and depth by depth, in either direction -
    // rather than copied from the screen. Returns {svg, width, height}.
    function buildSvg() {
        var W = 200, H = 66, PAD = 28, HEAD = 46, h = state.chartDir === 'h';
        var GX = h ? 60 : 18, GY = h ? 14 : 58;
        var slot = 0, depth = 0, tops = exportTops();
        (function place(list, d) {
            list.forEach(function (n) {
                n._d = d; depth = Math.max(depth, d + 1);
                if (n.children.length) { place(n.children, d + 1); n._s = (n.children[0]._s + n.children[n.children.length - 1]._s) / 2; }
                else n._s = slot++;
            });
        })(tops, 0);
        var px = function (n) { return PAD + (h ? n._d * (W + GX) : n._s * (W + GX)); };
        var py = function (n) { return PAD + HEAD + (h ? n._s * (H + GY) : n._d * (H + GY)); };
        var width = PAD * 2 + (h ? depth * (W + GX) - GX : slot * (W + GX) - GX) + 40;
        var height = PAD * 2 + HEAD + (h ? slot * (H + GY) - GY : depth * (H + GY) - GY) + 24;
        var lines = [], boxes = [];
        (function walk(list) {
            list.forEach(function (n) {
                var x = px(n), y = py(n), dc = colour(n).indexOf('var(') === 0 ? EXPORT_ACCENT : colour(n);
                var focus = n.id && n.id === state.chartFocus;
                n.children.forEach(function (c) {
                    var cx = px(c), cy = py(c);
                    lines.push(h
                        ? '<path d="M' + (x + W) + ',' + (y + H / 2) + 'H' + (x + W + GX / 2) + 'V' + (cy + H / 2) + 'H' + cx + '" fill="none" stroke="#d4d4d8" stroke-width="2"/>'
                        : '<path d="M' + (x + W / 2) + ',' + (y + H) + 'V' + (y + H + GY / 2) + 'H' + (cx + W / 2) + 'V' + cy + '" fill="none" stroke="#d4d4d8" stroke-width="2"/>');
                });
                var job = n.is_active ? (n.job_title || n.department || '') : T('org.leaver');
                boxes.push('<g>'
                    + '<rect x="' + x + '" y="' + y + '" width="' + W + '" height="' + H + '" rx="10" fill="#ffffff" stroke="' + (n.is_active ? '#e5e7eb' : '#9ca3af') + '"' + (n.is_active ? '' : ' stroke-dasharray="5 4"') + '/>'
                    + (h ? '<rect x="' + x + '" y="' + y + '" width="4" height="' + H + '" fill="' + dc + '"/>' : '<rect x="' + x + '" y="' + y + '" width="' + W + '" height="4" fill="' + dc + '"/>')
                    + (focus ? '<rect x="' + x + '" y="' + (y + H - 5) + '" width="' + W + '" height="5" fill="' + EXPORT_ACCENT + '"/>'
                        + '<rect x="' + (x + W - 60) + '" y="' + (y - 10) + '" width="58" height="18" rx="9" fill="' + EXPORT_ACCENT + '"/>'
                        + '<text x="' + (x + W - 31) + '" y="' + (y + 3) + '" font-size="10" font-weight="700" fill="#ffffff" text-anchor="middle">' + xesc(T('org.focus_pill')) + '</text>' : '')
                    + '<circle cx="' + (x + 30) + '" cy="' + (y + H / 2) + '" r="15" fill="' + (n.is_active ? dc : '#9ca3af') + '"/>'
                    + '<text x="' + (x + 30) + '" y="' + (y + H / 2 + 4) + '" font-size="11" font-weight="700" fill="#ffffff" text-anchor="middle">' + xesc(initials(n.name)) + '</text>'
                    + '<text x="' + (x + 54) + '" y="' + (y + 29) + '" font-size="13" font-weight="600" fill="' + (focus ? EXPORT_ACCENT : '#222222') + '">' + xesc(cut(n.name, 20)) + '</text>'
                    + '<text x="' + (x + 54) + '" y="' + (y + 47) + '" font-size="11" fill="' + (n.is_active ? '#6b7280' : '#b91c1c') + '">' + xesc(cut(job, 24)) + '</text>'
                    + (n.more > 0 ? '<text x="' + (h ? x + W + 6 : x + W / 2) + '" y="' + (h ? y + H / 2 + 4 : y + H + 16) + '" font-size="11" font-weight="600" fill="' + EXPORT_ACCENT + '"' + (h ? '' : ' text-anchor="middle"') + '>' + xesc(T('org.more_under', { n: n.more })) + '</text>' : '')
                    + '</g>');
                walk(n.children);
            });
        })(tops);
        var stamp = new Date().toLocaleDateString(document.documentElement.lang || undefined, { year: 'numeric', month: 'long', day: 'numeric' });
        return { width: width, height: height, svg: XML_DECL
            + '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '" font-family="Segoe UI, Helvetica, Arial, sans-serif">'
            + '<rect width="100%" height="100%" fill="#ffffff"/>'
            + '<text x="' + PAD + '" y="' + (PAD + 4) + '" font-size="18" font-weight="700" fill="#222222">' + xesc(exportTitle()) + '</text>'
            + '<text x="' + PAD + '" y="' + (PAD + 24) + '" font-size="11" fill="#6b7280">' + xesc(T('org.export_stamp', { date: stamp })) + '</text>'
            + lines.join('') + boxes.join('') + '</svg>' };
    }
    // SVG to a canvas, by the browser. Kept under the canvas size browsers allow.
    function svgToCanvas(drawing) {
        return new Promise(function (resolve, reject) {
            var scale = Math.min(2, 16000 / Math.max(drawing.width, drawing.height));
            var img = new Image();
            img.onload = function () {
                var c = document.createElement('canvas');
                c.width = Math.round(drawing.width * scale); c.height = Math.round(drawing.height * scale);
                var ctx = c.getContext('2d');
                ctx.scale(scale, scale); ctx.drawImage(img, 0, 0);
                resolve(c);
            };
            img.onerror = reject;
            img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(drawing.svg);
        });
    }
    // The data as a tree, for JSON and XML; CSV flattens it with a level.
    function exportPeople() {
        var map = function (n) {
            return { id: n.id, name: n.name, job_title: n.job_title || null, department: n.department || null,
                     company: n.company || null, left: !n.is_active, reports: n.reports, total: n.total || 0, team: n.children.map(map) };
        };
        return exportTops().map(map);
    }
    // Saves what the chart shows: 'svg', 'png' and 'pdf' from buildSvg(),
    // 'csv', 'json' and 'xml' from the same people as data.
    function exportAs(fmt) {
        if (!state.branch) return;
        var failed = function () { if (typeof window.showToast === 'function') window.showToast(T('org.export_failed'), 'error'); };
        if (fmt === 'svg') { download(new Blob([buildSvg().svg], { type: 'image/svg+xml' }), fileName('svg')); return; }
        if (fmt === 'png') {
            svgToCanvas(buildSvg()).then(function (c) { c.toBlob(function (b) { if (b) download(b, fileName('png')); else failed(); }); }, failed);
            return;
        }
        if (fmt === 'pdf') {
            if (!window.FitsmPdf) { failed(); return; }
            var drawing = buildSvg();
            svgToCanvas(drawing).then(function (c) {
                // The shared PDF writer (assets/js/pdf-writer.js - GH #254). The chart is a
                // picture the browser drew, so every language in it is already right.
                return FitsmPdf.create({ orientation: drawing.width > drawing.height ? 'landscape' : 'portrait', unit: 'pt', format: [drawing.width, drawing.height] }).then(function (pdf) {
                    // JPEG, not PNG: a high-quality JPEG of a chart on a white ground looks
                    // the same and is far smaller.
                    pdf.addImage(c.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, drawing.width, drawing.height);
                    return pdf.save(fileName('pdf'));
                });
            }).catch(failed);
            return;
        }
        if (fmt === 'json') {
            var doc = { title: exportTitle(), exported: new Date().toISOString(), people: exportPeople() };
            download(new Blob([JSON.stringify(doc, null, 2)], { type: 'application/json' }), fileName('json'));
            return;
        }
        if (fmt === 'xml') {
            var person = function (p, indent) {
                var attrs = ['id', 'name', 'job_title', 'department', 'company'].filter(function (k) { return p[k] !== null && p[k] !== ''; })
                    .map(function (k) { return ' ' + k + '="' + xesc(p[k]) + '"'; }).join('') + (p.left ? ' left="true"' : '') + ' reports="' + p.reports + '" total="' + p.total + '"';
                return indent + '<person' + attrs + (p.team.length ? '>\n' + p.team.map(function (c) { return person(c, indent + '  '); }).join('') + indent + '</person>\n' : '/>\n');
            };
            var xml = XML_DECL + '\n<organisation title="' + xesc(exportTitle()) + '" exported="' + new Date().toISOString() + '">\n'
                + exportPeople().map(function (p) { return person(p, '  '); }).join('') + '</organisation>\n';
            download(new Blob([xml], { type: 'application/xml' }), fileName('xml'));
            return;
        }
        // CSV: a level column instead of nesting; a leading = + - @ is defused for spreadsheets.
        var q = function (v) { v = v == null ? '' : String(v); return /[",\n\r]/.test(v) || /^[=+\-@]/.test(v) ? '"' + v.replace(/^([=+\-@])/, "'$1").replace(/"/g, '""') + '"' : v; };
        var rows = [[T('org.csv_level'), T('org.csv_name'), T('org.csv_job'), T('org.csv_department'), T('org.csv_company'), T('org.csv_manager'), T('org.csv_status'), T('org.csv_reports'), T('org.csv_total')]];
        var walk = function (n, d, boss) {
            rows.push([d, n.name, n.job_title, n.department, n.company, boss, n.is_active ? '' : T('org.leaver'), n.reports, n.total || 0]);
            n.children.forEach(function (c) { walk(c, d + 1, n.name); });
        };
        exportTops().forEach(function (n) { walk(n, 0, state.branch.root.id === 0 || state.branch.focus ? '' : (state.branch.manager ? state.branch.manager.name : '')); });
        download(new Blob(['﻿' + rows.map(function (r) { return r.map(q).join(','); }).join('\r\n')], { type: 'text/csv;charset=utf-8' }), fileName('csv'));
    }
    // The menu: opens under its button, closes on a choice, a click elsewhere or Escape.
    function exportMenu(open) {
        $('orgExportMenu').hidden = !open;
        $('orgExport').setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { var first = $('orgExportMenu').querySelector('[data-export]'); if (first) first.focus(); }
    }
    $('orgExport').addEventListener('click', function (e) { e.stopPropagation(); levelsMenu(false); exportMenu($('orgExportMenu').hidden); });
    $('orgExportMenu').addEventListener('click', function (e) {
        var item = e.target.closest('[data-export]');
        if (!item) return;
        exportMenu(false);
        exportAs(item.dataset.export);
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.ppl-org-export')) exportMenu(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('orgExportMenu').hidden) { exportMenu(false); $('orgExport').focus(); } });

    // ---------------------------------------------------------------- gaps
    function loadGaps() {
        return fetch(API + '?action=gaps', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.success) { $('orgGaps').innerHTML = '<p class="ppl-dim ppl-org-small">' + esc(d.error) + '</p>'; return; }
            (d.departments || []).forEach(function (name, i) { state.depts[String(name).trim().toLowerCase()] = i; });
            // A refresh redraws these: the ones that were open stay open.
            var wasOpen = {};
            $('orgGaps').querySelectorAll('details[open]').forEach(function (el) { wasOpen[el.dataset.gap] = true; });
            var block = function (key, g, extra) {
                return '<details class="ppl-org-gap" data-gap="' + key + '"' + (wasOpen[key] ? ' open' : '') + '><summary><span>' + esc(T('org.gap_' + key)) + '</span> <span class="ppl-count">' + g.total + '</span></summary>'
                    + '<p class="ppl-dim ppl-org-small">' + esc(T('org.gap_' + key + '_d')) + '</p>'
                    + (g.people.length ? '<ul>' + g.people.map(function (p) {
                        return '<li><a href="#" data-find="' + p.id + '">' + esc(p.name) + '</a>' + (extra ? ' <span class="ppl-dim">' + esc(extra(p)) + '</span>' : '') + '</li>';
                    }).join('') + '</ul>' : '<p class="ppl-dim ppl-org-small">' + esc(T('org.gap_none')) + '</p>')
                    + (g.total > g.people.length ? '<p class="ppl-dim ppl-org-small">' + esc(T('org.gap_more', { n: g.people.length, total: g.total })) + '</p>' : '')
                    + '</details>';
            };
            $('orgGaps').innerHTML = block('no_manager', d.gaps.no_manager)
                + block('left', d.gaps.left_with_reports, function (p) { return T('org.gap_reports', { n: p.reports }); });
        }).catch(function () {});
    }
    $('orgGaps').addEventListener('click', function (e) {
        var a = e.target.closest('[data-find]');
        if (a) { e.preventDefault(); find(parseInt(a.dataset.find, 10)); }
    });

    // -------------------------------------------------------------- search
    var seq = 0, timer = null;
    // A search result chosen: open that person in whichever view is showing.
    function find(id) {
        resultsEl.hidden = true;
        if (state.view === 'chart') loadChart(id); else jumpTo(id);
    }
    searchEl.addEventListener('input', function () {
        clearTimeout(timer);
        var q = searchEl.value.trim();
        if (!q) { resultsEl.hidden = true; return; }
        timer = setTimeout(function () {
            var my = ++seq;
            fetch(LIST + '?status=all&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
                if (my !== seq) return;
                var people = (d.success ? d.people : []).slice(0, 10);
                resultsEl.innerHTML = people.length ? people.map(function (p) {
                    return '<button type="button" role="option" data-find="' + p.id + '">' + esc(p.name)
                        + '<span class="ppl-dim">' + esc([p.job_title, p.department].filter(Boolean).join(' · ')) + '</span></button>';
                }).join('') : '<p class="ppl-dim ppl-org-small">' + esc(T('org.no_results')) + '</p>';
                resultsEl.hidden = false;
            });
        }, 200);
    });
    resultsEl.addEventListener('click', function (e) {
        var b = e.target.closest('[data-find]');
        if (b) find(parseInt(b.dataset.find, 10));
    });
    // Back to everybody: the chart from the top, the columns at the top, the
    // tree as it is but nobody picked out.
    $('orgFocus').addEventListener('click', function (e) {
        if (!e.target.closest('#orgFocusClear')) return;
        setFocus(0);
        searchEl.value = '';
        if (state.view === 'chart') { loadChart(0); return; }
        if (state.treeMode === 'columns' && state.cols.length) { state.cols = state.cols.slice(0, 1); state.cols[0].sel = 0; drawCols(); return; }
        treeEl.querySelectorAll('.ppl-org-row.hit').forEach(function (r) { r.classList.remove('hit'); });
    });

    // ----------------------------------------------------- views and layouts
    function setView(view) {
        state.view = view; remember();
        pressed('data-view', view);
        treeEl.hidden = view !== 'tree';
        chartEl.hidden = view !== 'chart';
        $('orgTreeTools').hidden = view !== 'tree';
        $('orgChartTools').hidden = view !== 'chart';
        // Mouse and keyboard is about the chart: the tree has no wheel, zoom or drag to explain.
        $('orgKeysFoot').hidden = view !== 'chart';
        // Only one line is open at a time in columns, so there is nothing to expand.
        $('orgExpandAll').hidden = $('orgCollapseAll').hidden = state.treeMode === 'columns';
        titleEl.textContent = T(view === 'chart' ? 'org.view_chart' : 'org.view_tree');
        say('');
        if (view === 'chart') return loadChart(state.person);
        if (treeEl.dataset.mode !== state.treeMode) {
            // A change of layout opens again what was open in the last one.
            var keep = state.keepOpen || [];
            state.keepOpen = null;
            return loadTree()
                .then(function () { return reopen(keep); })
                .then(function () { if (state.person) return jumpTo(state.person); });
        }
        if (state.person) return jumpTo(state.person);
    }
    document.querySelectorAll('.ppl-org-view').forEach(function (b) {
        b.addEventListener('click', function () { setView(b.dataset.view); });
    });
    document.querySelectorAll('[data-treemode]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (b.dataset.treemode === state.treeMode && treeEl.dataset.mode === state.treeMode) return;
            state.keepOpen = treeEl.dataset.mode ? openIds() : [];
            state.treeMode = b.dataset.treemode; setPref('tree', state.treeMode); pressed('data-treemode', state.treeMode);
            setView('tree');
        });
    });
    document.querySelectorAll('[data-chartmode]').forEach(function (b) {
        b.addEventListener('click', function () {
            state.chartCompact = b.dataset.chartmode === 'compact'; setPref('compact', state.chartCompact ? '1' : '0');
            pressed('data-chartmode', b.dataset.chartmode);
            if (state.branch) drawChart(false, true);
        });
    });
    document.querySelectorAll('[data-dir]').forEach(function (b) {
        b.addEventListener('click', function () {
            state.chartDir = b.dataset.dir; setPref('dir', state.chartDir); pressed('data-dir', state.chartDir);
            if (state.branch) drawChart(false, true);
        });
    });
    // Levels menu: the presets, All, and a number of your own.
    function showLevels() {
        var v = state.chartLevels;
        $('orgLevelsText').textContent = T('org.levels_is', { n: v === 'all' ? T('org.levels_all') : v });
        document.querySelectorAll('[data-levels]').forEach(function (b) { b.setAttribute('aria-checked', b.dataset.levels === v ? 'true' : 'false'); });
        $('orgLevelsInput').value = v === 'all' ? '' : v;
        levelsError('');
    }
    // The Levels box's own number: a problem with it, or (no text) the range allowed.
    function levelsError(text) {
        var input = $('orgLevelsInput'), hint = $('orgLevelsHint');
        input.setAttribute('aria-invalid', text ? 'true' : 'false');
        hint.textContent = text || T('org.levels_range', { max: LEVELS_MAX });
        hint.classList.toggle('bad', !!text);
    }
    // Opens or closes the Levels menu, moving focus into it when it opens.
    function levelsMenu(open) {
        $('orgLevelsMenu').hidden = !open;
        $('orgLevels').setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { showLevels(); ($('orgLevelsMenu').querySelector('[aria-checked="true"]') || $('orgLevelsInput')).focus(); }
    }
    // A new depth for the chart ('all' or a number): remembered, and the chart redrawn.
    function setLevels(v) {
        levelsMenu(false);
        if (v === state.chartLevels) return;
        state.chartLevels = v; setPref('levels', v); showLevels();
        if (state.view === 'chart') loadChart(state.chartFocus || state.person);
    }
    $('orgLevels').addEventListener('click', function (e) { e.stopPropagation(); exportMenu(false); levelsMenu($('orgLevelsMenu').hidden); });
    $('orgLevelsMenu').addEventListener('click', function (e) {
        var item = e.target.closest('[data-levels]');
        if (item) setLevels(item.dataset.levels);
    });
    $('orgLevelsForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var v = levelsValue($('orgLevelsInput').value);
        if (!v) { levelsError(T('org.levels_invalid', { max: LEVELS_MAX })); $('orgLevelsInput').focus(); return; }
        setLevels(v);
    });
    $('orgLevelsInput').addEventListener('input', function () { levelsError(''); });
    document.addEventListener('click', function (e) { if (!e.target.closest('.ppl-org-levels')) levelsMenu(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('orgLevelsMenu').hidden) { levelsMenu(false); $('orgLevels').focus(); } });
    leaversEl.addEventListener('change', function () {
        setPref('leavers', leaversEl.checked ? '1' : '0');
        state.keepOpen = treeEl.dataset.mode ? openIds() : [];
        treeEl.dataset.mode = '';
        setView(state.view);
    });

    // Refresh: ask again, keeping what is open, who the page is on, and the panel.
    // ⚠️ Built out of sight and swapped in at once - never cleared and filled
    // in front of the reader, which flickered the whole list even when nothing
    // had changed. Nothing says "Loading" either: that line pushed the page down.
    function refresh() {
        contactCache = {};
        loadGaps();
        // The toast says what happened: up to date only when the answer came back.
        var toast = function (ok) { if (typeof window.showToast === 'function') window.showToast(T(ok ? 'org.refreshed' : 'org.refresh_failed'), ok ? 'success' : 'error', ok ? 2000 : undefined); };
        var failed = function () { state.restoring = false; toast(false); };
        if (state.view === 'chart') {
            var sel = state.chartSel;
            return loadChart(state.person, true).then(function (ok) {
                if (ok && sel && state.chartIndex && state.chartIndex[sel]) selectInChart(sel);
                toast(!!ok);
            }, failed);
        }
        return (state.treeMode === 'columns' ? refreshCols() : refreshTree()).then(function (ok) { toast(!!ok); }, failed);
    }
    // Refresh for Cards and Compact: builds the tree again off the page, with
    // the same teams open, and only then swaps it in at the same scroll - so
    // the reader never sees it collapse and reopen.
    function refreshTree() {
        var ids = openIds(), fresh = document.createElement('div');
        state.restoring = true;
        return get('action=level&id=0').then(function (d) {
            if (!d.success) { state.restoring = false; say(d.error); return false; }
            fresh.innerHTML = cheadHtml();
            renderLevel(fresh, d);
            var i = 0;
            var step = function () {
                if (i >= ids.length) return Promise.resolve();
                var li = fresh.querySelector('li[data-id="' + ids[i++] + '"]');
                return (li ? expand(li, true) : Promise.resolve()).then(step);
            };
            return step().then(function () {
                var row = state.person && fresh.querySelector('li[data-id="' + state.person + '"] > .ppl-org-row');
                if (row) row.classList.add('hit');
                var top = treeEl.scrollTop;
                treeEl.replaceChildren.apply(treeEl, Array.prototype.slice.call(fresh.childNodes));
                treeEl.scrollTop = top;
                state.restoring = false;
                saveOpen();
                say(d.people.length ? '' : T('org.empty'));
                return true;
            });
        });
    }
    // Refresh for Columns: reloads each column down the chosen line.
    function refreshCols() {
        var chain = state.cols.filter(function (c) { return c.sel; }).map(function (c) { return c.sel; });
        var cols = [];
        return get('action=level&id=0').then(function (d) {
            if (!d.success) { say(d.error); return false; }
            cols.push({ parent: null, people: d.people, more: d.more, sel: 0 });
            var i = 0;
            // Down the same line, each column asked again; stops where somebody has gone.
            var step = function () {
                if (i >= chain.length) return Promise.resolve();
                var col = cols[i], n = col.people.filter(function (p) { return p.id === chain[i]; })[0];
                if (!n) return Promise.resolve();
                col.sel = n.id; i++;
                if (!(n.reports > 0)) return Promise.resolve();
                return get('action=level&id=' + n.id).then(function (l) {
                    if (l.success) cols.push({ parent: n, people: l.people, more: l.more, sel: 0 });
                    return step();
                });
            };
            return step().then(function () {
                var strip = treeEl.querySelector('.ppl-org-strip'), left = strip ? strip.scrollLeft : 0;
                state.cols = cols;
                drawCols();
                if (strip) strip.scrollLeft = left;
                return true;
            });
        });
    }
    document.querySelectorAll('[data-refresh]').forEach(function (b) { b.addEventListener('click', refresh); });

    pressed('data-treemode', state.treeMode);
    pressed('data-chartmode', state.chartCompact ? 'compact' : 'cards');
    pressed('data-dir', state.chartDir);
    showLevels();
    $('orgDim').checked = pref('dim', '0') === '1';
    $('orgDim').addEventListener('change', function () { setPref('dim', this.checked ? '1' : '0'); applyDim(); });
    // Mouse and keyboard: the dialog at the foot of the sidebar (it names Ctrl and
    // Cmd both; the wheel listens for either).
    (function () {
        var dlg = $('orgKeys'), btn = $('orgKeysBtn');
        if (!dlg || !btn || typeof dlg.showModal !== 'function') { if (btn) btn.hidden = true; return; }
        btn.addEventListener('click', function () { dlg.showModal(); });
        dlg.addEventListener('click', function (e) {
            // The close button, or a click on the backdrop (the dialog itself, not its content).
            if (e.target.closest('[data-close]') || e.target === dlg) dlg.close();
        });
        dlg.addEventListener('close', function () { btn.focus(); });
    })();
    // What was open in this tab before F5 opens again.
    if (state.treeMode !== 'columns') state.keepOpen = savedOpen();
    // The colours depend on the department list, which comes with the gaps.
    loadGaps().then(function () { setView(state.view); }, function () { setView(state.view); });
})();
