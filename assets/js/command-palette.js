/*
 * Command palette (⌘/Ctrl-K).
 *
 * A global launcher available on every analyst page. Opens with Cmd-K (Mac) or
 * Ctrl-K, lets you jump straight to any module you can access, run a couple of
 * quick actions, and search tickets / CMDB items / assets by name.
 *
 * The page injects two globals before this loads (see renderWaffleMenuJS in
 * includes/waffle-menu.php):
 *   window.CP_BASE     — BASE_URL, prefixed to every navigation target.
 *   window.CP_MODULES  — [{ key, name, path, icon }] already filtered to the
 *                        modules this analyst may see, so the palette never
 *                        offers a destination the waffle launcher wouldn't.
 *
 * Entity search goes to api/system/global_search.php, which applies the same
 * module + company scoping server-side.
 */
(function () {
    'use strict';

    if (window.__cmdpInit) return;      // guard against a double include
    window.__cmdpInit = true;

    var BASE = window.CP_BASE || '';
    // Translate, or the English written right here. The waffle menu injects
    // this file on every in-app page and not all of them export
    // window.translations, so the fallback is the contract, not a nicety.
    function cp(key, english, params) {
        return window.tf ? window.tf('common.palette.' + key, english, params) : english;
    }

    var MODULES = Array.isArray(window.CP_MODULES) ? window.CP_MODULES : [];

    // Generic icons for the search-result types (modules carry their own).
    var ICONS = {
        ticket: '<path d="M22 12h-6l-2 3h-4l-2-3H2"></path><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path>',
        change: '<polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line>',
        problem: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
        knowledge: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>',
        contract: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>',
        ci: '<path d="M2 22V8l10-6 10 6v14"></path><path d="M2 12h20"></path><line x1="12" y1="2" x2="12" y2="22"></line>',
        asset: '<rect x="2" y="3" width="20" height="14" rx="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line>',
        domain: '<path d="M20.9 13.5A10 10 0 1 0 12 22"></path><path d="M2 12h20"></path><path d="M12 2a15.3 15.3 0 0 1 4 10"></path><path d="M12 2a15.3 15.3 0 0 0-4 10 15.3 15.3 0 0 0 4 10"></path><rect x="15" y="17" width="7" height="5" rx="1"></rect><path d="M16.5 17v-1.5a2 2 0 0 1 4 0V17"></path>',
        person: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        company: '<path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-4"></path>',
        command: '<polyline points="4 17 10 11 4 5"></polyline><line x1="12" y1="19" x2="20" y2="19"></line>',
        search: '<circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>'
    };
    // A content hit is still a ticket, so it carries the ticket icon rather than
    // a magnifying glass — the row's job is "here is a ticket", and the group
    // heading above it already says it was found by its text.
    ICONS.ticket_content = ICONS.ticket;
    ICONS.article_content = ICONS.knowledge;
    // An attached document is a file, whatever it hangs off — same glyph as the
    // documents panel uses, so the two read as the same thing.
    ICONS.document = ICONS.contract;
    ICONS.document_content = ICONS.contract;
    // Suppliers and their contacts in People (#153 step 3): a van, and a person.
    ICONS.supplier = '<rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle>';
    ICONS.supplier_contact = ICONS.person;
    // Projects (3.2.0): the staggered bars of its module tile.
    ICONS.project = '<rect x="3" y="4" width="10" height="4" rx="1"></rect><rect x="7" y="10" width="11" height="4" rx="1"></rect><rect x="11" y="16" width="10" height="4" rx="1"></rect>';
    // Built lazily: the palette is constructed on first open, by which point
    // window.translations is certainly in place.
    function typeLabel(type) {
        return {
            ticket:           cp('type_ticket', 'Ticket'),
            change:           cp('type_change', 'Change'),
            problem:          cp('type_problem', 'Problem'),
            knowledge:        cp('type_article', 'Article'),
            contract:         cp('type_contract', 'Contract'),
            domain:           cp('type_domain', 'Domain'),
            project:          cp('type_project', 'Project'),
            person:           cp('type_person', 'Person'),
            company:          cp('type_company', 'Company'),
            supplier:         cp('type_supplier', 'Supplier'),
            supplier_contact: cp('type_supplier_contact', 'Supplier contact'),
            ci:               cp('type_ci', 'Config item'),
            asset:            cp('type_asset', 'Asset'),
            ticket_content:   cp('type_ticket', 'Ticket'),
            article_content:  cp('type_article', 'Article'),
            document:         cp('type_document', 'Document'),
            document_content: cp('type_document', 'Document')
        }[type] || '';
    }

    // Static quick actions. Each has a matcher label and a run().
    var COMMANDS = [
        {
            get label() { return cp('cmd_theme', 'Toggle dark mode'); },
            keywords: 'theme light dark appearance',
            run: function () {
                var cur = document.documentElement.getAttribute('data-theme');
                var next = cur === 'dark' ? 'default' : 'dark';
                // setTheme() (waffle-menu.php) persists the choice and reloads.
                if (typeof window.setTheme === 'function') window.setTheme(next);
            }
        },
        {
            get label() { return cp('cmd_signout', 'Sign out'); },
            keywords: 'logout log out leave',
            run: function () { window.location.href = BASE + 'analyst_logout.php'; }
        }
    ];

    var overlay, input, resultsEl, searchWrap;
    var items = [];        // flat list of currently-shown {el, activate} entries
    var activeIx = -1;
    var searchTimer = null;
    var searchSeq = 0;     // guards against out-of-order async responses

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function build() {
        overlay = document.createElement('div');
        overlay.className = 'cmdp-overlay';
        overlay.innerHTML =
            '<div class="cmdp-box" role="dialog" aria-label="' + esc(cp('aria_dialog', 'Command palette')) + '">' +
                '<div class="cmdp-search">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + ICONS.search + '</svg>' +
                    '<input class="cmdp-input" type="text" autocomplete="off" spellcheck="false" placeholder="' + esc(cp('search_ph', 'Search tickets, assets, items — or jump to a module…')) + '">' +
                    '<div class="cmdp-spinner"></div>' +
                '</div>' +
                '<div class="cmdp-results"></div>' +
                '<div class="cmdp-footer">' +
                    '<span class="cmdp-hint"><span class="cmdp-key">↑</span><span class="cmdp-key">↓</span> ' + esc(cp('hint_navigate', 'navigate')) + '</span>' +
                    '<span class="cmdp-hint"><span class="cmdp-key">↵</span> ' + esc(cp('hint_open', 'open')) + '</span>' +
                    '<span class="cmdp-hint"><span class="cmdp-key">esc</span> ' + esc(cp('hint_close', 'close')) + '</span>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        input = overlay.querySelector('.cmdp-input');
        resultsEl = overlay.querySelector('.cmdp-results');
        searchWrap = overlay.querySelector('.cmdp-search');

        overlay.addEventListener('mousedown', function (e) {
            if (e.target === overlay) close();       // click the backdrop to dismiss
        });
        input.addEventListener('input', onInput);
        input.addEventListener('keydown', onKeydown);
    }

    function open() {
        if (!overlay) build();
        overlay.classList.add('active');
        input.value = '';
        render();                 // initial view: modules + actions
        // focus after the paint so the caret lands reliably
        requestAnimationFrame(function () { input.focus(); });
    }

    function close() {
        if (!overlay) return;
        overlay.classList.remove('active');
        searchWrap.classList.remove('loading');
        if (searchTimer) { clearTimeout(searchTimer); searchTimer = null; }
    }

    function isOpen() { return overlay && overlay.classList.contains('active'); }

    // Substring match with a light prefix boost, so "tas" ranks "Tasks" above a
    // module that merely contains the letters.
    function score(text, q) {
        text = text.toLowerCase();
        var i = text.indexOf(q);
        if (i === -1) return -1;
        return i === 0 ? 2 : 1;
    }

    function matchedModules(q) {
        if (!q) return MODULES.slice();
        return MODULES
            .map(function (m) { return { m: m, s: score(m.name, q) }; })
            .filter(function (x) { return x.s >= 0; })
            .sort(function (a, b) { return b.s - a.s; })
            .map(function (x) { return x.m; });
    }

    function matchedCommands(q) {
        if (!q) return COMMANDS.slice();
        return COMMANDS.filter(function (c) {
            return score(c.label, q) >= 0 || (c.keywords && c.keywords.indexOf(q) !== -1);
        });
    }

    // Render the palette body from the current query + optional server results.
    function render(serverResults) {
        var q = input.value.trim().toLowerCase();
        var html = '';
        items = [];
        var pending = [];   // {activate} in DOM order, wired up after innerHTML

        var mods = matchedModules(q);
        if (mods.length) {
            html += '<div class="cmdp-group-label">Go to</div>';
            mods.forEach(function (m) {
                var idx = pending.length;
                html += row(idx,
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (m.icon || '') + '</svg>',
                    esc(m.name), '', '');
                pending.push(function () { window.location.href = BASE + m.path; });
            });
        }

        var cmds = matchedCommands(q);
        if (cmds.length) {
            html += '<div class="cmdp-group-label">Actions</div>';
            cmds.forEach(function (c) {
                var idx = pending.length;
                html += row(idx,
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + ICONS.command + '</svg>',
                    esc(c.label), '', '');
                pending.push(function () { close(); c.run(); });
            });
        }

        if (serverResults && serverResults.length) {
            // Group the entity results by type, in a stable order.
            // ⚠️ 'ticket_content' is LAST on purpose. Everything before it matched
            // a name or a reference, which is what the palette is for — type a
            // hostname, press Enter, you are there. A content hit is a different
            // intent, and putting it any higher means "LT-001" shows message
            // snippets above the asset actually called LT-001.
            // ⚠️ A TYPE MISSING FROM THIS LIST IS SILENTLY DROPPED. The server can
            // be returning results perfectly and the palette will show nothing,
            // with no error anywhere — which is exactly what happened when
            // documents were added server-side (#76).
            ['ticket', 'change', 'problem', 'knowledge', 'person', 'company', 'supplier', 'supplier_contact', 'contract', 'domain', 'project', 'asset', 'ci', 'document', 'ticket_content', 'article_content', 'document_content'].forEach(function (type) {
                var group = serverResults.filter(function (r) { return r.type === type; });
                if (!group.length) return;
                html += '<div class="cmdp-group-label">' + esc(pluralType(type)) + '</div>';
                group.forEach(function (r) {
                    var idx = pending.length;
                    // A document row carries an ⓘ: the subtitle names ONE place it
                    // is attached, and a document can be on several. Documents only
                    // — nothing else here has more than one home.
                    //
                    // BOTH document types: a content hit is the same document found
                    // by its text instead of its name, and "where does this live?"
                    // is if anything a more pressing question there — you have just
                    // matched a phrase and have no idea what it belongs to.
                    var extra = (type === 'document' || type === 'document_content')
                        ? '<button type="button" class="cmdp-info" data-doc="' + (r.id | 0) +
                          '" title="' + esc(cp('doc_details', 'Document details')) + '" aria-label="' + esc(cp('doc_details', 'Document details')) + '">i</button>'
                        : '';
                    html += row(idx,
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[type] || '') + '</svg>',
                        esc(r.title), esc(r.subtitle || ''), esc(typeLabel(type)), extra);
                    pending.push(function () { window.location.href = BASE + r.url; });
                });
            });
        }

        if (!html) {
            html = '<div class="cmdp-empty">' +
                (q.length >= 2 ? esc(cp('no_matches', 'No matches for “{q}”', { q: q })) : esc(cp('type_to_search', 'Type to search'))) +
                '</div>';
        }

        resultsEl.innerHTML = html;

        // Wire the rendered rows to their actions.
        var rowEls = resultsEl.querySelectorAll('.cmdp-item');
        rowEls.forEach(function (el, i) {
            var activate = pending[i];
            items.push({ el: el, activate: activate });
            el.addEventListener('mousemove', function () { setActive(i); });
            el.addEventListener('click', function (e) {
                // ⚠️ The ⓘ must not also open the row. stopPropagation is not
                // enough on its own — the handler is on the ROW, so the check has
                // to happen here, before activate() navigates away.
                var info = e.target.closest ? e.target.closest('[data-doc]') : null;
                if (info) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (window.FreeITSMDocuments && window.FreeITSMDocuments.info) {
                        window.FreeITSMDocuments.info(BASE + 'api/documents/', info.getAttribute('data-doc'), BASE);
                    }
                    return;
                }
                if (activate) activate();
            });
        });

        activeIx = items.length ? 0 : -1;
        paintActive();
    }

    function pluralType(type) {
        return {
            ticket: cp('group_ticket', 'Tickets'),
            change: cp('group_change', 'Changes'),
            problem: cp('group_problem', 'Problems'),
            knowledge: cp('group_knowledge', 'Knowledge'),
            contract: cp('group_contract', 'Contracts'),
            domain: cp('group_domain', 'Domains'),
            project: cp('group_project', 'Projects'),
            person: cp('group_person', 'People'),
            company: cp('group_company', 'Companies'),
            supplier: cp('group_supplier', 'Suppliers'),
            supplier_contact: cp('group_supplier_contact', 'Supplier contacts'),
            asset: cp('group_asset', 'Assets'),
            ci: cp('group_ci', 'Configuration items'),
            document: cp('group_document', 'Documents'),
            // Says WHERE the match was, not what the thing is — these are the
            // same tickets as the group above, found by their text instead of
            // their name, and the label is the only thing that explains why a
            // ticket whose subject looks unrelated is in the list.
            ticket_content: cp('group_in_tickets', 'Found inside tickets'),
            article_content: cp('group_in_articles', 'Found inside articles'),
            document_content: cp('group_in_documents', 'Found inside documents')
        }[type] || type;
    }

    function row(idx, iconSvg, title, sub, tag, extra) {
        return '<div class="cmdp-item" data-ix="' + idx + '">' +
            '<div class="cmdp-item-icon">' + iconSvg + '</div>' +
            '<div class="cmdp-item-body">' +
                '<div class="cmdp-item-title">' + title + '</div>' +
                (sub ? '<div class="cmdp-item-sub">' + sub + '</div>' : '') +
            '</div>' +
            (extra || '') +
            (tag ? '<span class="cmdp-item-tag">' + tag + '</span>' : '') +
        '</div>';
    }

    function setActive(ix) {
        activeIx = ix;
        paintActive();
    }

    function paintActive() {
        items.forEach(function (it, i) {
            if (i === activeIx) {
                it.el.classList.add('active');
                it.el.scrollIntoView({ block: 'nearest' });
            } else {
                it.el.classList.remove('active');
            }
        });
    }

    function move(delta) {
        if (!items.length) return;
        activeIx = (activeIx + delta + items.length) % items.length;
        paintActive();
    }

    function onKeydown(e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIx >= 0 && items[activeIx] && items[activeIx].activate) items[activeIx].activate();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();      // don't let the page's global Escape also fire
            close();
        }
    }

    function onInput() {
        var q = input.value.trim();
        render();                     // instant client-side view (modules + actions)
        if (searchTimer) clearTimeout(searchTimer);
        if (q.length < 2) {
            searchWrap.classList.remove('loading');
            return;
        }
        searchWrap.classList.add('loading');
        searchTimer = setTimeout(function () { doSearch(q); }, 180);
    }

    function doSearch(q) {
        var seq = ++searchSeq;
        fetch(BASE + 'api/system/global_search.php?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (seq !== searchSeq) return;      // a newer query has superseded this
                if (input.value.trim() !== q) return;
                searchWrap.classList.remove('loading');
                render(data && data.success ? data.results : []);
            })
            .catch(function () {
                if (seq !== searchSeq) return;
                searchWrap.classList.remove('loading');
            });
    }

    // Global trigger: Cmd-K / Ctrl-K toggles the palette from anywhere.
    document.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            isOpen() ? close() : open();
        }
    });
})();
