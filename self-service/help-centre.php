<?php
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
$pageData = ['articleId' => (int)($_GET['id'] ?? 0)];

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
        .hc-body img { max-width: 100%; height: auto; }
        .hc-body table { max-width: 100%; }
        .hc-body pre {
            overflow-x: auto;
            background: var(--surface-hover, #f3f4f6);
            padding: 12px;
            border-radius: 6px;
        }
        .hc-body a { color: var(--ss-accent, #0078d4); }
        .hc-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--ss-accent, #0078d4);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 20px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            font-family: inherit;
        }
        .hc-back:hover { text-decoration: underline; }

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

            container.innerHTML = '<div class="hc-list">' + articles.map(a => {
                const tags = (a.tags || []).map(t =>
                    '<span class="hc-tag">' + escapeHtml(t) + '</span>').join('');
                return '<button type="button" class="hc-card" onclick="openArticle(' + a.id + ')">'
                     +   '<div class="hc-card-title">' + escapeHtml(a.title || '') + '</div>'
                     +   '<div class="hc-card-preview">' + escapeHtml(a.preview || '') + '</div>'
                     +   (tags ? '<div class="hc-tags">' + tags + '</div>' : '')
                     + '</button>';
            }).join('') + '</div>';
        }

        async function openArticle(id, isDeepLink) {
            const container = document.getElementById('hcContent');
            const searchBox = document.getElementById('hcSearch');
            if (searchBox) searchBox.style.display = 'none';

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

        function backButtonHtml() {
            return '<button type="button" class="hc-back" onclick="backToList()">&lsaquo; '
                 + escapeHtml(window.t('self-service.help_centre.back')) + '</button>';
        }

        function backToList() {
            const searchBox = document.getElementById('hcSearch');
            if (searchBox) { searchBox.style.display = ''; searchBox.value = ''; }
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
            return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : text;
            return div.innerHTML;
        }
JS;

require_once __DIR__ . '/includes/header.php';
?>
    <div class="hc-header">
        <h1><?php echo htmlspecialchars(t('self-service.help_centre.heading')); ?></h1>
        <p><?php echo htmlspecialchars(t('self-service.help_centre.lede')); ?></p>
    </div>

    <input type="search" class="hc-search" id="hcSearch"
           placeholder="<?php echo htmlspecialchars(t('self-service.help_centre.search_placeholder')); ?>"
           autocomplete="off">

    <div id="hcContent">
        <div class="hc-empty"><div class="hc-empty-hint"><?php echo htmlspecialchars(t('self-service.help_centre.loading')); ?></div></div>
    </div>
<?php
require_once __DIR__ . '/includes/footer.php';
