<?php
/**
 * Knowledge Base - Article Review Management
 *
 * Pretty URL: /knowledge/review/  (moved from /knowledge/review.php in #395).
 * The page is a sibling to /knowledge/index.php — the "Edit" icon on each row
 * deep-links back to ../?article=N&edit=1 so the main page opens that article
 * straight in TinyMCE edit mode.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

$current_page = 'review';
$path_prefix = '../../';
$translationNamespaces = ['common', 'knowledge'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('knowledge.browser_title.review')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <link rel="stylesheet" href="../../assets/css/knowledge.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
    <style>
        /* Layout: the container takes the remaining viewport height and is
           a flex column. Header / filter tabs / search bar are flex-shrink: 0
           and stay pinned at the top; only the table content scrolls.
           Matches the inner-scroll pattern used elsewhere in the app. */
        .review-container {
            padding: 16px 30px 24px;
            height: calc(100vh - 48px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-shrink: 0;
        }

        .review-header h1 {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin: 0;
        }

        .review-search {
            width: 320px;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .review-search:focus {
            outline: none;
            border-color: #8764b8;
            box-shadow: 0 0 0 2px rgba(135, 100, 184, 0.1);
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 10px;
            flex-shrink: 0;
        }

        .filter-tab {
            padding: 8px 16px;
            background: #f5f5f5;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            color: #666;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-tab:hover {
            background: #e8e8e8;
        }

        .filter-tab.active {
            background: #8764b8;
            color: white;
        }

        .filter-tab .badge {
            background: rgba(0, 0, 0, 0.15);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
        }

        .filter-tab.active .badge {
            background: rgba(255, 255, 255, 0.25);
        }

        .filter-tab.overdue .badge {
            background: #dc3545;
            color: white;
        }

        .filter-tab.active.overdue .badge {
            background: rgba(255, 255, 255, 0.9);
            color: #dc3545;
        }

        /* Scrollable region for the table. The table's own sticky thead keeps
           column headers visible while the body scrolls underneath. */
        .review-content {
            flex-grow: 1;
            overflow-y: auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            min-height: 0;
        }

        .review-table {
            width: 100%;
            border-collapse: collapse;
        }

        .review-table th,
        .review-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .review-table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #f9f9f9;
            font-weight: 600;
            color: #666;
            font-size: 13px;
            text-transform: uppercase;
            border-bottom: 2px solid #e0e0e0;
        }

        .review-table tr:hover td {
            background: #fafafa;
        }

        .review-table tr:last-child td {
            border-bottom: none;
        }

        .article-title-link {
            color: #8764b8;
            text-decoration: none;
            font-weight: 500;
        }

        .article-title-link:hover {
            text-decoration: underline;
        }

        .review-date {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .review-date.overdue {
            color: #dc3545;
            font-weight: 500;
        }

        .review-date.upcoming {
            color: #fd7e14;
        }

        .review-date.ok {
            color: #28a745;
        }

        .review-date .days-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f0f0f0;
        }

        .review-date.upcoming .days-badge {
            background: #fd7e14;
            color: white;
        }

        .no-date {
            color: #999;
            font-style: italic;
        }

        .owner-cell {
            color: #666;
        }

        .owner-cell.unassigned {
            color: #999;
            font-style: italic;
        }

        /* Icon-only edit button — same shape as the action buttons in the
           other settings pages. */
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            padding: 0;
            background: none;
            border: 1px solid #ddd;
            color: #666;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s;
        }
        .action-btn:hover {
            background: #f0f0f0;
            border-color: #8764b8;
            color: #8764b8;
        }
        .action-btn svg {
            width: 16px;
            height: 16px;
        }

        /* Days overdue column — bare number, red, bold. Empty cell if the
           article isn't overdue. */
        .days-overdue {
            color: #dc3545;
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #888;
        }

        .empty-state svg {
            width: 64px;
            height: 64px;
            color: #ddd;
            margin-bottom: 15px;
        }

        .loading {
            display: flex;
            justify-content: center;
            padding: 40px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #8764b8;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="review-container">
        <div class="review-header">
            <h1><?php echo htmlspecialchars(t('knowledge.review.heading')); ?></h1>
            <input type="text" class="review-search" id="reviewSearch" placeholder="<?php echo htmlspecialchars(t('knowledge.review.search_placeholder')); ?>" autocomplete="off">
        </div>

        <div class="filter-tabs">
            <button class="filter-tab active" data-filter="all" onclick="filterArticles('all')">
                <?php echo htmlspecialchars(t('knowledge.review.tab_all')); ?> <span class="badge" id="countAll">0</span>
            </button>
            <button class="filter-tab overdue" data-filter="overdue" onclick="filterArticles('overdue')">
                <?php echo htmlspecialchars(t('knowledge.review.tab_overdue')); ?> <span class="badge" id="countOverdue">0</span>
            </button>
            <button class="filter-tab" data-filter="upcoming" onclick="filterArticles('upcoming')">
                <?php echo htmlspecialchars(t('knowledge.review.tab_upcoming')); ?> <span class="badge" id="countUpcoming">0</span>
            </button>
            <button class="filter-tab" data-filter="no_date" onclick="filterArticles('no_date')">
                <?php echo htmlspecialchars(t('knowledge.review.tab_no_date')); ?> <span class="badge" id="countNoDate">0</span>
            </button>
        </div>

        <div id="reviewContent" class="review-content">
            <div class="loading"><div class="spinner"></div></div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/knowledge/';
        let currentFilter = 'all';
        let currentArticles = [];   // most-recent fetch result, used for client-side search filtering
        let searchTerm = '';

        document.addEventListener('DOMContentLoaded', function() {
            loadReviewList();
            document.getElementById('reviewSearch').addEventListener('input', function() {
                searchTerm = this.value.trim().toLowerCase();
                renderReviewTable(currentArticles);
            });
        });

        async function loadReviewList() {
            try {
                const response = await fetch(API_BASE + 'get_review_list.php?filter=' + currentFilter);
                const data = await response.json();

                if (data.success) {
                    currentArticles = data.articles;
                    renderReviewTable(currentArticles);
                    updateCounts(data.counts);
                } else {
                    document.getElementById('reviewContent').innerHTML =
                        '<div class="empty-state">' + escapeHtml(window.t('knowledge.review.error_loading', { message: data.error })) + '</div>';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('reviewContent').innerHTML =
                    '<div class="empty-state">' + escapeHtml(window.t('knowledge.review.failed_load')) + '</div>';
            }
        }

        function renderReviewTable(articles) {
            const container = document.getElementById('reviewContent');

            // Apply the search filter client-side on top of the server-side
            // tab filter. Match against title + owner_name so typing an
            // analyst's name narrows to "their" articles.
            let filtered = articles;
            if (searchTerm) {
                filtered = articles.filter(a => {
                    const title = (a.title || '').toLowerCase();
                    const owner = (a.owner_name || '').toLowerCase();
                    return title.indexOf(searchTerm) !== -1 || owner.indexOf(searchTerm) !== -1;
                });
            }

            if (filtered.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                            <path d="M9 16l2 2 4-4"></path>
                        </svg>
                        <p>${searchTerm ? escapeHtml(window.t('knowledge.review.no_match', { term: searchTerm })) : escapeHtml(window.t('knowledge.review.no_results'))}</p>
                    </div>
                `;
                return;
            }

            let html = `
                <table class="review-table">
                    <thead>
                        <tr>
                            <th>${escapeHtml(window.t('knowledge.review.col_title'))}</th>
                            <th>${escapeHtml(window.t('knowledge.review.col_owner'))}</th>
                            <th>${escapeHtml(window.t('knowledge.review.col_review_date'))}</th>
                            <th>${escapeHtml(window.t('knowledge.review.col_days_overdue'))}</th>
                            <th>${escapeHtml(window.t('knowledge.review.col_modified'))}</th>
                            <th>${escapeHtml(window.t('knowledge.review.col_edit'))}</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            filtered.forEach(article => {
                const reviewDateClass = article.is_overdue ? 'overdue' :
                    (article.days_until_review !== null && article.days_until_review <= 30) ? 'upcoming' : 'ok';

                let reviewDateHtml = '';
                if (article.next_review_date_formatted) {
                    let dueBadge = '';
                    if (!article.is_overdue && article.days_until_review !== null && article.days_until_review <= 30) {
                        dueBadge = `<span class="days-badge">${escapeHtml(window.t('knowledge.review.in_days', { count: article.days_until_review }))}</span>`;
                    }
                    reviewDateHtml = `<span class="review-date ${reviewDateClass}">${article.next_review_date_formatted} ${dueBadge}</span>`;
                } else {
                    reviewDateHtml = `<span class="no-date">${escapeHtml(window.t('knowledge.review.not_set'))}</span>`;
                }

                const daysOverdueHtml = article.is_overdue
                    ? `<span class="days-overdue">${Math.abs(article.days_until_review)}</span>`
                    : '';

                const ownerHtml = article.owner_name
                    ? `<span class="owner-cell">${escapeHtml(article.owner_name)}</span>`
                    : `<span class="owner-cell unassigned">${escapeHtml(window.t('knowledge.review.unassigned'))}</span>`;

                const modifiedDate = new Date(article.modified_datetime).toLocaleDateString('en-GB', {
                    day: 'numeric', month: 'short', year: 'numeric'
                });

                html += `
                    <tr>
                        <td>
                            <a href="../?article=${article.id}" class="article-title-link">${escapeHtml(article.title)}</a>
                        </td>
                        <td>${ownerHtml}</td>
                        <td>${reviewDateHtml}</td>
                        <td>${daysOverdueHtml}</td>
                        <td>${modifiedDate}</td>
                        <td>
                            <a href="../?article=${article.id}&edit=1" class="action-btn" title="${escapeHtml(window.t('knowledge.review.edit'))}">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                            </a>
                        </td>
                    </tr>
                `;
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        }

        function updateCounts(counts) {
            document.getElementById('countAll').textContent = counts.total || 0;
            document.getElementById('countOverdue').textContent = counts.overdue || 0;
            document.getElementById('countUpcoming').textContent = counts.upcoming || 0;
            document.getElementById('countNoDate').textContent = counts.no_date || 0;
        }

        function filterArticles(filter) {
            currentFilter = filter;

            // Update active tab
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.classList.toggle('active', tab.dataset.filter === filter);
            });

            // Show loading
            document.getElementById('reviewContent').innerHTML =
                '<div class="loading"><div class="spinner"></div></div>';

            loadReviewList();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
