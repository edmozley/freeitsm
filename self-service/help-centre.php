<?php
// FIRST: this page loads an includes/ file before config.php, so the version guard
// must run before that file is read - see includes/php_version_guard.php.
require_once __DIR__ . '/../includes/php_version_guard.php';
/**
 * Self-Service Portal — Help Centre (the knowledge base).
 *
 * The first reader at the Audience::CUSTOMER rung, which has existed unread
 * since #869. Distinct from help.php, which is the guide to using the portal
 * itself — hence "Using the portal" vs "Help Centre" in the nav.
 *
 * Chrome (head, theme, header, nav, footer) comes from includes/header.php and
 * includes/footer.php; shared styling from assets/css/self-service.css.
 */
$pageTitleKey = 'self-service.help_centre.title';   // a KEY: i18n starts in header.php
$activeNav    = 'help_centre';

// Deep link to one article: /help-centre.php?id=12. Page-specific VALUES go
// through $pageData → window.PAGE, never interpolated into $pageScripts (that
// is a nowdoc — a PHP tag inside it is emitted verbatim and kills the whole
// script block; see includes/footer.php).
// ?article=N as well (PR #253): the editor's article picker writes links the
// analyst Knowledge page understands (?article=N), and an article read here
// resolves them against help-centre.php - so they must open here too. The
// reader still asks whether this requester may read it, as for ?id=.
$pageData = ['articleId' => (int)($_GET['id'] ?? $_GET['article'] ?? 0)];

// Page-specific styling only — shared chrome lives in self-service.css.
$pageStyles = <<<'CSS'
.hc-header { margin-bottom: 20px; }
        .hc-header h1 {
            font-size: 22px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0 0 6px 0;
        }
        .hc-header p {
            font-size: 14px;
            color: var(--text-muted, #666);
            margin: 0;
        }
        .hc-search {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            background: var(--surface, #fff);
            color: var(--text, #333);
            font-family: inherit;
            font-size: 15px;
            margin-bottom: 20px;
        }
        .hc-search:focus { outline: none; border-color: var(--ss-accent, #0078d4); }

        .hc-list { display: grid; gap: 12px; }
        .hc-card {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            padding: 16px 20px;
            cursor: pointer;
            text-align: left;
            width: 100%;
            font-family: inherit;
        }
        .hc-card:hover { border-color: var(--ss-accent, #0078d4); }
        .hc-card-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--text, #333);
            margin-bottom: 6px;
        }
        .hc-card-preview {
            font-size: 13px;
            color: var(--text-muted, #666);
            line-height: 1.5;
        }
        .hc-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .hc-tag {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 10px;
            background: var(--surface-hover, #f3f4f6);
            color: var(--text-muted, #666);
        }
        /* "3 documents" on an article in the list (GH #177). */
        .hc-doc-count {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 12px; color: var(--ss-accent, #0078d4); margin-top: 10px;
        }
        .hc-doc-count svg { width: 13px; height: 13px; flex-shrink: 0; }
        .hc-tree-item .hc-doc-count, .hc-td-title .hc-doc-count { margin: 0 0 0 8px; vertical-align: middle; }

        /* Article view */
        .hc-article {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            padding: 28px 32px;
        }
        .hc-article h1 {
            font-size: 22px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0 0 8px 0;
        }
        .hc-article-meta {
            font-size: 12px;
            color: var(--text-muted, #999);
            margin-bottom: 20px;
        }
        .hc-body {
            font-size: 14px;
            line-height: 1.7;
            color: var(--text, #333);
        }
        /* Article spacing. The portal loads inbox.css, whose first rule is
           `* { margin: 0; padding: 0 }` - so an article's paragraphs ran
           together and a numbered list lost its indent, the numbers hanging
           out past the text. The same values the analyst reader uses
           (assets/css/knowledge.css, .article-content-body), so an article
           reads the same to a customer as to the team that wrote it. */
        .hc-body h1, .hc-body h2, .hc-body h3, .hc-body h4 { margin: 24px 0 12px; line-height: 1.35; }
        .hc-body p { margin-bottom: 16px; }
        .hc-body ul, .hc-body ol { margin-bottom: 16px; padding-left: 24px; }
        .hc-body li { margin-bottom: 4px; }
        .hc-body li > ul, .hc-body li > ol { margin: 4px 0 0; }
        .hc-body blockquote { margin: 0 0 16px; padding: 4px 0 4px 14px; border-left: 3px solid var(--border, #e5e7eb); color: var(--text-muted, #666); }
        .hc-body table { margin-bottom: 16px; border-collapse: collapse; }
        .hc-body th, .hc-body td { border: 1px solid var(--border, #e5e7eb); padding: 6px 10px; text-align: left; vertical-align: top; }
        .hc-body code { background: var(--surface-hover, #f3f4f6); padding: 1px 5px; border-radius: 3px; font-size: 13px; }
        .hc-body pre code { background: none; padding: 0; }
        .hc-body pre { margin-bottom: 16px; }
        .hc-body > :first-child { margin-top: 0; }
        .hc-body > :last-child { margin-bottom: 0; }
        .hc-body img { max-width: 100%; height: auto; }
        .hc-body table { max-width: 100%; }
        .hc-body pre {
            overflow-x: auto;
            background: var(--surface-hover, #f3f4f6);
            padding: 12px;
            border-radius: 6px;
        }
        .hc-body a { color: var(--ss-accent, #0078d4); }
        /* A real button, not a text link: on an article it is the way back, and
           the only control left above it once the toolbar is hidden. */
        .hc-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--ss-accent, #0078d4);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
            background: var(--surface, #fff);
            border: 1px solid var(--ss-accent, #0078d4);
            border-radius: 8px;
            cursor: pointer;
            padding: 9px 16px 9px 12px;
            font-family: inherit;
            line-height: 1;
        }
        .hc-back svg { width: 16px; height: 16px; flex-shrink: 0; }
        .hc-back:hover { background: var(--ss-accent, #0078d4); color: var(--ss-on-accent, #fff); }

        /* ── documents attached to the article (GH #177) ──────────────── */
        .hc-docs { margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border, #e5e7eb); }
        .hc-docs h2 { font-size: 14px; font-weight: 600; color: var(--text, #333); margin: 0 0 10px; }
        /* Tiles: a type icon, the name, then type and size. The whole tile is the
           download. Fills the width, so a phone gets one or two per row. */
        .hc-doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
        .hc-doc {
            display: flex; gap: 12px; align-items: flex-start; padding: 12px;
            border: 1px solid var(--border, #e5e7eb); border-radius: 8px;
            background: var(--surface, #fff); color: var(--text, #333); text-decoration: none; min-width: 0;
        }
        .hc-doc:hover { border-color: var(--ss-accent, #0078d4); }
        /* A page with its corner folded, and the type written on it. The hue is
           the type's (one per KIND of file, set inline) and mid-tone on purpose,
           so white lettering reads on it in both light and dark themes. */
        .hc-doc-icon {
            flex: 0 0 auto; width: 38px; height: 46px; border-radius: 4px; position: relative;
            background: var(--doc-c, #64748b); color: #fff;
            display: flex; align-items: flex-end; justify-content: center; padding-bottom: 7px;
            font-size: 9.5px; font-weight: 700; letter-spacing: .3px;
            clip-path: polygon(0 0, 72% 0, 100% 22%, 100% 100%, 0 100%);
        }
        .hc-doc-icon::after {
            content: ''; position: absolute; top: 0; right: 0; width: 28%; height: 22%;
            background: rgba(255, 255, 255, .35); border-bottom-left-radius: 3px;
        }
        .hc-doc-icon svg { position: absolute; top: 13px; width: 16px; height: 16px; opacity: .9; }
        .hc-doc-text { min-width: 0; flex: 1; }
        .hc-doc-name {
            font-size: 13.5px; font-weight: 500; line-height: 1.35; overflow-wrap: anywhere;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .hc-doc-meta { display: block; font-size: 12px; color: var(--text-muted, #666); margin-top: 4px; }
        .hc-doc-desc { display: block; font-size: 12px; color: var(--text-muted, #666); margin-top: 4px; line-height: 1.4; }

        /* ── Layout toggle ─────────────────────────────────────────── */
        /* stretch, not center: the layout buttons take the search box's height,
           so the toolbar reads as one row rather than a tall box and small pills. */
        .hc-toolbar { display: flex; gap: 12px; align-items: stretch; flex-wrap: wrap; margin-bottom: 20px; }
        .hc-toolbar .hc-search { margin-bottom: 0; flex: 1 1 260px; }
        .hc-layouts { display: flex; gap: 4px; flex-shrink: 0; }
        .hc-layout-btn {
            background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
            color: var(--text-muted, #666); border-radius: 8px; padding: 8px 14px; min-height: 38px;
            display: inline-flex; align-items: center;
            cursor: pointer; font: inherit; font-size: 13.5px; line-height: 1;
        }
        .hc-layout-btn:hover { border-color: var(--ss-accent, #0078d4); }
        .hc-layout-btn.active {
            background: var(--ss-accent, #0078d4); border-color: var(--ss-accent, #0078d4);
            color: var(--ss-on-accent, #fff);
        }

        /* ── cards: a grid, for scanning many titles at once ─────────── */
        .hc-list.hc-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
        /* A card is a <button>, and a button centres its content vertically - so
           in a row of different-length previews every title started at a
           different height. Lay each card out from the top instead, and let the
           preview take the spare space, so titles line up along the top of a row
           and the pills (and "N documents") along the bottom. */
        .hc-list.hc-cards .hc-card { display: flex; flex-direction: column; justify-content: flex-start; }
        .hc-list.hc-cards .hc-card-preview { flex: 1 1 auto; }

        /* ── list: full-width rows with a preview (what this page had) ── */
        .hc-list.hc-rows { display: grid; gap: 12px; }

        /* ── tree: folders with their articles inside ─────────────────── */
        .hc-tree-folder { margin-bottom: 18px; }
        .hc-tree-name {
            font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px;
            color: var(--text-muted, #666); padding: 0 0 6px 2px;
            border-bottom: 1px solid var(--border-soft, #eee); margin-bottom: 8px;
        }
        .hc-tree-items { display: grid; gap: 6px; padding-left: 2px; }
        .hc-tree-item {
            background: none; border: none; text-align: left; cursor: pointer; font: inherit;
            padding: 7px 10px; border-radius: 6px; color: var(--text, #333); font-size: 13.5px;
        }
        .hc-tree-item:hover { background: var(--surface-hover, #f3f4f6); color: var(--ss-accent, #0078d4); }

        /* ── table: the most titles per screen, for a big library ─────── */
        .hc-table { width: 100%; border-collapse: collapse; background: var(--surface, #fff);
                    border: 1px solid var(--border, #e5e7eb); border-radius: 8px; overflow: hidden; }
        .hc-table th {
            /* Follows the administrator's table header colour when one is set. */
            background: var(--ss-table-header-bg, var(--surface-hover, #f9fafb));
            text-align: left; padding: 9px 14px; font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted, #666);
        }
        .hc-table td { padding: 9px 14px; border-top: 1px solid var(--border-soft, #f1f1f1); font-size: 13.5px; }
        .hc-table tbody tr { cursor: pointer; }
        .hc-table tbody tr:hover { background: var(--surface-hover, #f8fafc); }
        .hc-table .hc-td-title { color: var(--text, #333); font-weight: 500; }
        .hc-table .hc-td-folder { color: var(--text-muted, #666); white-space: nowrap; }

        .hc-empty {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            padding: 40px 24px;
            text-align: center;
        }
        .hc-empty-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--text, #333);
            margin-bottom: 6px;
        }
        .hc-empty-hint { font-size: 13px; color: var(--text-muted, #666); }
CSS;

$pageScripts = <<<'JS'
let hcSearchTimer = null;

        document.addEventListener('DOMContentLoaded', function () {
            // The layout preference first, so the list is drawn the way this
            // person left it rather than drawn as cards and then redrawn.
            hcLoadLayout();

            // Deep link straight to an article, otherwise the browsable list.
            if (window.PAGE.articleId) {
                openArticle(window.PAGE.articleId, true);
            } else {
                loadArticles('');
            }

            const box = document.getElementById('hcSearch');
            if (box) {
                box.addEventListener('input', function () {
                    clearTimeout(hcSearchTimer);
                    hcSearchTimer = setTimeout(() => loadArticles(box.value.trim()), 250);
                });
            }
        });

        async function loadArticles(query) {
            const container = document.getElementById('hcContent');
            try {
                const url = '../api/self-service/get_knowledge_articles.php'
                          + (query ? '?q=' + encodeURIComponent(query) : '');
                const response = await fetch(url);
                const data = await response.json();

                if (!data.success) {
                    container.innerHTML = '<div class="hc-empty"><div class="hc-empty-title">'
                        + escapeHtml(window.t('self-service.help_centre.load_failed')) + '</div></div>';
                    return;
                }
                renderList(data.articles || [], query);
            } catch (e) {
                container.innerHTML = '<div class="hc-empty"><div class="hc-empty-title">'
                    + escapeHtml(window.t('self-service.help_centre.load_failed')) + '</div></div>';
            }
        }

        function renderList(articles, query) {
            const container = document.getElementById('hcContent');

            if (!articles.length) {
                // Two very different empty states: "your search found nothing" is
                // the user's problem to solve, "there is nothing here at all" is
                // the service desk's — say which.
                const title = query
                    ? window.t('self-service.help_centre.no_results', { query: query })
                    : window.t('self-service.help_centre.no_articles');
                const hint = query
                    ? window.t('self-service.help_centre.no_results_hint')
                    : window.t('self-service.help_centre.no_articles_hint');
                container.innerHTML = '<div class="hc-empty">'
                    + '<div class="hc-empty-title">' + escapeHtml(title) + '</div>'
                    + '<div class="hc-empty-hint">' + escapeHtml(hint) + '</div></div>';
                return;
            }

            hcArticles = articles;
            hcDrawArticles();
        }

        /* ── Layouts ────────────────────────────────────────────────────
         *
         * Four ways to draw the same list, remembered per person. The names
         * match the analyst side's on purpose: a customer describing "the tree
         * one" to an analyst should be describing the same thing.
         *
         *   cards  a grid - many titles at a glance
         *   list   full-width rows with a preview (what this page always had)
         *   tree   grouped by folder, so the shape of the library is visible
         *   table  the most titles per screen, for a big library
         */
        let hcArticles = [];
        let hcLayout   = 'cards';

        // "3 documents" with a paperclip, when an article has any shown in the
        // Help Centre (GH #177). compact = the number alone, for the tree and
        // table, where a row has room for little more than the title.
        function hcDocCount(a, compact) {
            const n = a.doc_count || 0;
            if (!n) return '';
            const words = n === 1 ? window.t('self-service.help_centre.doc_count_one')
                                  : window.t('self-service.help_centre.doc_count_many', { n: n });
            return '<span class="hc-doc-count" title="' + escapeHtml(words) + '">'
                 + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>'
                 + escapeHtml(compact ? String(n) : words) + '</span>';
        }

        function hcCardsHtml(articles) {
            return '<div class="hc-list hc-cards">' + articles.map(a => {
                const tags = (a.tags || []).map(t => '<span class="hc-tag">' + escapeHtml(t) + '</span>').join('');
                return '<button type="button" class="hc-card" onclick="openArticle(' + a.id + ')">'
                     +   '<div class="hc-card-title">' + escapeHtml(a.title || '') + '</div>'
                     +   '<div class="hc-card-preview">' + escapeHtml(a.preview || '') + '</div>'
                     +   hcDocCount(a, false)
                     +   (tags ? '<div class="hc-tags">' + tags + '</div>' : '')
                     + '</button>';
            }).join('') + '</div>';
        }

        function hcRowsHtml(articles) {
            // The same card markup in a single column: full width means a longer
            // preview line is readable, which is the point of choosing it.
            return '<div class="hc-list hc-rows">' + articles.map(a => {
                const tags = (a.tags || []).map(t => '<span class="hc-tag">' + escapeHtml(t) + '</span>').join('');
                return '<button type="button" class="hc-card" onclick="openArticle(' + a.id + ')">'
                     +   '<div class="hc-card-title">' + escapeHtml(a.title || '') + '</div>'
                     +   '<div class="hc-card-preview">' + escapeHtml(a.preview || '') + '</div>'
                     +   hcDocCount(a, false)
                     +   (tags ? '<div class="hc-tags">' + tags + '</div>' : '')
                     + '</button>';
            }).join('') + '</div>';
        }

        function hcTreeHtml(articles) {
            // Grouped in the order the articles arrived, so the folders follow
            // the same sort the list already uses. An article filed nowhere gets
            // its own heading rather than being dropped - the analyst list does
            // the same, and silently hiding an article is the worse answer.
            const groups = [];
            const byName = {};
            articles.forEach(a => {
                const name = a.folder_name || window.t('self-service.help_centre.unfiled');
                if (!byName[name]) { byName[name] = []; groups.push(name); }
                byName[name].push(a);
            });
            return groups.map(name =>
                '<div class="hc-tree-folder">'
              +   '<div class="hc-tree-name">' + escapeHtml(name) + '</div>'
              +   '<div class="hc-tree-items">' + byName[name].map(a =>
                      '<button type="button" class="hc-tree-item" onclick="openArticle(' + a.id + ')">'
                    + escapeHtml(a.title || '') + hcDocCount(a, true) + '</button>').join('')
              +   '</div>'
              + '</div>').join('');
        }

        function hcTableHtml(articles) {
            return '<table class="hc-table"><thead><tr>'
                 +   '<th>' + escapeHtml(window.t('self-service.help_centre.col_title')) + '</th>'
                 +   '<th>' + escapeHtml(window.t('self-service.help_centre.col_folder')) + '</th>'
                 + '</tr></thead><tbody>'
                 + articles.map(a =>
                     '<tr onclick="openArticle(' + a.id + ')">'
                   +   '<td class="hc-td-title">' + escapeHtml(a.title || '') + hcDocCount(a, true) + '</td>'
                   +   '<td class="hc-td-folder">' + escapeHtml(a.folder_name || window.t('self-service.help_centre.unfiled')) + '</td>'
                   + '</tr>').join('')
                 + '</tbody></table>';
        }

        function hcDrawArticles() {
            const container = document.getElementById('hcContent');
            if (!container) return;
            const draw = { cards: hcCardsHtml, list: hcRowsHtml, tree: hcTreeHtml, table: hcTableHtml };
            container.innerHTML = (draw[hcLayout] || hcCardsHtml)(hcArticles);
        }

        function hcRenderLayoutButtons() {
            const box = document.getElementById('hcLayouts');
            if (!box) return;
            box.innerHTML = ['cards', 'list', 'tree', 'table'].map(m =>
                '<button type="button" class="hc-layout-btn' + (m === hcLayout ? ' active' : '') + '"'
              + ' data-layout="' + m + '" onclick="hcSetLayout(\'' + m + '\')">'
              + escapeHtml(window.t('self-service.help_centre.layout_' + m)) + '</button>').join('');
        }

        function hcSetLayout(mode) {
            if (['cards', 'list', 'tree', 'table'].indexOf(mode) === -1) return;
            hcLayout = mode;
            hcRenderLayoutButtons();
            hcDrawArticles();
            // Fire and forget. The layout has already changed on screen; if the
            // save fails only the MEMORY of it is lost, and an error banner over
            // a view toggle would be worse than quietly forgetting.
            fetch('../api/self-service/preference.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key: 'kb_layout', value: mode })
            }).catch(() => {});
        }

        async function hcLoadLayout() {
            try {
                const r = await fetch('../api/self-service/preference.php?key=kb_layout', { credentials: 'same-origin' });
                const d = await r.json();
                if (d.success && ['cards', 'list', 'tree', 'table'].indexOf(d.value) > -1) hcLayout = d.value;
            } catch (e) { /* the default is already set */ }
            hcRenderLayoutButtons();
        }

        async function openArticle(id, isDeepLink) {
            const container = document.getElementById('hcContent');
            // The whole toolbar goes, not just the search: Cards / List / Tree /
            // Table choose how the LIST is drawn and mean nothing on one article.
            const toolbar = document.getElementById('hcToolbar');
            if (toolbar) toolbar.style.display = 'none';

            try {
                const response = await fetch('../api/self-service/get_knowledge_article.php?id=' + encodeURIComponent(id));
                const data = await response.json();

                if (!data.success) {
                    container.innerHTML = '<div class="hc-empty"><div class="hc-empty-title">'
                        + escapeHtml(window.t('self-service.help_centre.not_found')) + '</div>'
                        + '<div class="hc-empty-hint">'
                        + escapeHtml(window.t('self-service.help_centre.not_found_hint')) + '</div></div>'
                        + backButtonHtml();
                    return;
                }

                const a = data.article;
                const tags = (a.tags || []).map(t =>
                    '<span class="hc-tag">' + escapeHtml(t) + '</span>').join('');

                // The body is TinyMCE HTML stored verbatim — the product has no
                // server-side sanitiser — and this renders it in the requester's
                // session, so it goes through the same cleaner as email bodies.
                container.innerHTML = backButtonHtml()
                    + '<div class="hc-article">'
                    +   '<h1>' + escapeHtml(a.title || '') + '</h1>'
                    +   '<div class="hc-article-meta">'
                    +     escapeHtml(window.t('self-service.help_centre.updated', { date: formatDate(a.modified_datetime) }))
                    +   '</div>'
                    +   (tags ? '<div class="hc-tags" style="margin-bottom:16px">' + tags + '</div>' : '')
                    +   '<div class="hc-body">' + safeArticleHtml(a.body) + '</div>'
                    +   documentsHtml(a.documents || [])
                    + '</div>';

                // Deep links get a real URL to share; in-page navigation doesn't
                // need one, but Back should still return to the list.
                if (!isDeepLink && window.history && window.history.pushState) {
                    window.history.pushState({ articleId: id }, '', 'help-centre.php?id=' + id);
                }
            } catch (e) {
                container.innerHTML = '<div class="hc-empty"><div class="hc-empty-title">'
                    + escapeHtml(window.t('self-service.help_centre.load_failed')) + '</div></div>'
                    + backButtonHtml();
            }
        }

        // The article's documents (GH #177). The server sends only those shown in
        // the Help Centre, on an article this requester may already read. A DMS
        // link opens where it points; a file downloads through the portal route.
        function documentsHtml(docs) {
            if (!docs.length) return '';
            return '<div class="hc-docs"><h2>' + escapeHtml(window.t('self-service.help_centre.documents')) + '</h2>'
                + '<div class="hc-doc-grid">'
                + docs.map(d => {
                    const isLink = d.kind === 'link';
                    const href = isLink
                        ? (/^https?:\/\//i.test(d.external_url || '') ? d.external_url : '#')
                        : '../api/self-service/get_article_document.php?id=' + encodeURIComponent(d.id);
                    const type = docType(d);
                    // The extension is on the icon, so the name drops it - but only
                    // when it IS the extension the icon shows.
                    let name = d.title || d.original_name || '';
                    if (type.ext && name.toLowerCase().endsWith('.' + type.ext)) name = name.slice(0, -(type.ext.length + 1));
                    const action = window.t(isLink ? 'self-service.help_centre.doc_open' : 'self-service.help_centre.doc_download');
                    const meta = [type.label];
                    if (!isLink && d.size_bytes) meta.push(docSize(d.size_bytes));
                    return '<a class="hc-doc" href="' + escapeHtml(href) + '"'
                        + (isLink ? ' target="_blank" rel="noopener noreferrer"' : '')
                        + ' title="' + escapeHtml(action + ': ' + (d.title || d.original_name || '')) + '">'
                        + '<span class="hc-doc-icon" style="--doc-c:' + type.colour + '" aria-hidden="true">'
                        +   (isLink ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>' : '')
                        +   escapeHtml(type.badge) + '</span>'
                        + '<span class="hc-doc-text">'
                        +   '<span class="hc-doc-name">' + escapeHtml(name) + '</span>'
                        +   '<span class="hc-doc-meta">' + escapeHtml(meta.join(' · ')) + '</span>'
                        +   (d.description ? '<span class="hc-doc-desc">' + escapeHtml(d.description) + '</span>' : '')
                        + '</span>'
                        + '</a>';
                }).join('') + '</div></div>';
        }

        // What a document is, for its icon: the word on it, its colour, and the
        // type in words. One colour per KIND of file (every picture format is the
        // same purple), so the colours mean something rather than decorating.
        function docType(d) {
            if (d.kind === 'link') return { badge: 'LINK', label: window.t('self-service.help_centre.doc_link'), colour: '#0e7490', ext: '' };
            const m = /\.([a-z0-9]{1,5})$/i.exec(d.original_name || d.title || '');
            const ext = m ? m[1].toLowerCase() : '';
            const kinds = [
                [['pdf'], '#dc2626'],
                [['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'heic', 'tif', 'tiff'], '#7c3aed'],
                [['doc', 'docx', 'odt', 'rtf'], '#2563eb'],
                [['xls', 'xlsx', 'csv', 'ods'], '#16a34a'],
                [['ppt', 'pptx', 'odp'], '#ea580c'],
                [['zip', '7z', 'rar', 'gz', 'tar'], '#b45309'],
                [['mp4', 'mov', 'avi', 'mkv', 'webm', 'mp3', 'wav'], '#db2777'],
            ];
            const hit = kinds.find(k => k[0].includes(ext));
            return {
                badge:  ext ? ext.toUpperCase().slice(0, 4) : 'FILE',
                label:  ext ? ext.toUpperCase() : window.t('self-service.help_centre.doc_file'),
                colour: hit ? hit[1] : '#64748b',
                ext:    ext,
            };
        }

        function docSize(n) {
            if (n < 1024) return n + ' B';
            if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
            return (n / 1048576).toFixed(1) + ' MB';
        }

        function backButtonHtml() {
            return '<button type="button" class="hc-back" onclick="backToList()">'
                 + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>'
                 + escapeHtml(window.t('self-service.help_centre.back')) + '</button>';
        }

        function backToList() {
            const toolbar = document.getElementById('hcToolbar');
            if (toolbar) toolbar.style.display = '';
            const searchBox = document.getElementById('hcSearch');
            if (searchBox) searchBox.value = '';
            if (window.history && window.history.pushState) {
                window.history.pushState({}, '', 'help-centre.php');
            }
            loadArticles('');
        }

        window.addEventListener('popstate', function (e) {
            if (e.state && e.state.articleId) openArticle(e.state.articleId, true);
            else backToList();
        });

        // Fails closed: if safe-html.js didn't load, show inert text rather than
        // raw markup.
        function safeArticleHtml(html) {
            if (typeof safeHtmlFragment !== 'function') {
                console.error('FreeITSM: assets/js/safe-html.js did not load — article shown as plain text.');
                return typeof escapeHtmlText === 'function' ? escapeHtmlText(html) : '';
            }
            return safeHtmlFragment(html);
        }

        function formatDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr.replace(' ', 'T') + 'Z');
            if (isNaN(d)) return '';
            return fmtDate(d);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : text;
            return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
JS;

require_once __DIR__ . '/includes/header.php';
?>
    <div class="hc-header">
        <h1><?php echo htmlspecialchars(t('self-service.help_centre.heading')); ?></h1>
        <p><?php echo htmlspecialchars(t('self-service.help_centre.lede')); ?></p>
    </div>

    <div class="hc-toolbar" id="hcToolbar">
        <input type="search" class="hc-search" id="hcSearch"
               placeholder="<?php echo htmlspecialchars(t('self-service.help_centre.search_placeholder')); ?>"
               autocomplete="off">
        <?php /* A toggle rather than only a setting, for the reason the analyst
                 side records: unlike where you browse, this is something people
                 genuinely flip during a session. It still persists. */ ?>
        <div class="hc-layouts" id="hcLayouts" role="group"
             aria-label="<?php echo htmlspecialchars(t('self-service.help_centre.layout_label')); ?>"></div>
    </div>

    <div id="hcContent">
        <div class="hc-empty"><div class="hc-empty-hint"><?php echo htmlspecialchars(t('self-service.help_centre.loading')); ?></div></div>
    </div>
<?php
require_once __DIR__ . '/includes/footer.php';
