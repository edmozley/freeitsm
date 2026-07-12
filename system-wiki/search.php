<?php
/**
 * System Wiki - Search Results Page
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
require_once '../includes/theme.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('wiki');

$current_page = 'search';
$path_prefix = '../';

$translationNamespaces = ['common', 'system-wiki'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('system-wiki.search.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .wiki-search {
            height: calc(100vh - 48px);
            overflow-y: auto;
            background: #f5f7fa;
        }
        .search-content {
            max-width: 1000px;
            margin: 0 auto;
            padding: 24px 20px;
        }
        .search-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
        }
        .search-bar input {
            flex: 1;
            padding: 10px 16px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 15px;
        }
        .search-bar input:focus { outline: none; border-color: #c62828; }
        .search-bar button {
            padding: 10px 24px;
            background: #c62828;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
        }
        .search-bar button:hover { background: #b71c1c; }

        .tabs {
            display: flex;
            gap: 0;
            border-bottom: 2px solid #eee;
            margin-bottom: 16px;
        }
        .tab {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            color: #888;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all 0.15s;
        }
        .tab:hover { color: #555; }
        .tab.active { color: #c62828; border-bottom-color: #c62828; }
        .tab-count {
            background: #f0f0f0;
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 6px;
            color: #888;
        }

        .result-item {
            background: #fff;
            border-radius: 6px;
            padding: 14px 18px;
            margin-bottom: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .result-item a {
            color: #c62828;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
        }
        .result-item a:hover { text-decoration: underline; }
        .result-meta {
            font-size: 12px;
            color: #888;
            margin-top: 4px;
        }
        .result-desc {
            font-size: 13px;
            color: #666;
            margin-top: 4px;
        }
        .type-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
        }
        .type-badge.php { background: #e8eaf6; color: #3f51b5; }
        .type-badge.js { background: #fff8e1; color: #f57f17; }
        .no-results { text-align: center; padding: 40px; color: #aaa; font-size: 14px; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wiki-search">
        <div class="search-content">
            <div class="search-bar">
                <input type="text" id="searchInput" placeholder="<?php echo htmlspecialchars(t('system-wiki.search.search_placeholder')); ?>" autofocus>
                <button onclick="doSearch()"><?php echo htmlspecialchars(t('system-wiki.search.search_btn')); ?></button>
            </div>

            <div class="tabs" id="tabs" style="display:none;">
                <div class="tab active" onclick="switchTab('files', this)"><?php echo htmlspecialchars(t('system-wiki.search.tab_files')); ?> <span class="tab-count" id="filesCount">0</span></div>
                <div class="tab" onclick="switchTab('functions', this)"><?php echo htmlspecialchars(t('system-wiki.search.tab_functions')); ?> <span class="tab-count" id="functionsCount">0</span></div>
                <div class="tab" onclick="switchTab('tables', this)"><?php echo htmlspecialchars(t('system-wiki.search.tab_tables')); ?> <span class="tab-count" id="tablesCount">0</span></div>
            </div>

            <div class="tab-panel active" id="filesPanel"></div>
            <div class="tab-panel" id="functionsPanel"></div>
            <div class="tab-panel" id="tablesPanel"></div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/wiki/';
        const input = document.getElementById('searchInput');

        // Pre-fill from URL
        const urlQ = new URLSearchParams(window.location.search).get('q');
        if (urlQ) {
            input.value = urlQ;
            doSearch();
        }

        input.addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });

        async function doSearch() {
            const q = input.value.trim();
            if (q.length < 2) return;

            // Update URL
            history.replaceState(null, '', 'search.php?q=' + encodeURIComponent(q));

            try {
                const res = await fetch(API_BASE + 'search.php?q=' + encodeURIComponent(q));
                const data = await res.json();

                if (!data.success) return;

                const r = data.results;
                document.getElementById('tabs').style.display = 'flex';

                document.getElementById('filesCount').textContent = r.files.length;
                document.getElementById('functionsCount').textContent = r.functions.length;
                document.getElementById('tablesCount').textContent = r.tables.length;

                // Render files
                document.getElementById('filesPanel').innerHTML = r.files.length === 0
                    ? '<div class="no-results">' + esc(window.t('system-wiki.search.no_files')) + '</div>'
                    : r.files.map(f => `
                        <div class="result-item">
                            <span class="type-badge ${f.file_type.toLowerCase()}">${f.file_type}</span>
                            <a href="file.php?id=${f.id}">${esc(f.file_path)}</a>
                            <div class="result-meta">${f.line_count} ${esc(window.t('system-wiki.search.lines'))} &middot; ${esc(f.folder_path || window.t('system-wiki.search.root'))}</div>
                            ${f.description ? `<div class="result-desc">${esc(f.description)}</div>` : ''}
                        </div>
                    `).join('');

                // Render functions
                document.getElementById('functionsPanel').innerHTML = r.functions.length === 0
                    ? '<div class="no-results">' + esc(window.t('system-wiki.search.no_functions')) + '</div>'
                    : r.functions.map(fn => `
                        <div class="result-item">
                            <a href="function.php?id=${fn.id}">${esc(fn.function_name)}()</a>
                            <div class="result-meta">${esc(window.t('system-wiki.search.in'))} <a href="file.php?id=${fn.file_id}" style="color:#888;">${esc(fn.file_path)}</a> &middot; ${esc(window.t('system-wiki.search.line'))} ${fn.line_number}</div>
                            ${fn.description ? `<div class="result-desc">${esc(fn.description)}</div>` : ''}
                        </div>
                    `).join('');

                // Render tables
                document.getElementById('tablesPanel').innerHTML = r.tables.length === 0
                    ? '<div class="no-results">' + esc(window.t('system-wiki.search.no_tables')) + '</div>'
                    : r.tables.map(t => `
                        <div class="result-item">
                            <a href="table.php?name=${encodeURIComponent(t.table_name)}">${esc(t.table_name)}</a>
                            <div class="result-meta">${esc(window.t('system-wiki.search.refs_across_files', { refs: t.reference_count, files: t.file_count }))}</div>
                        </div>
                    `).join('');

                // Auto-switch to first tab with results
                if (r.files.length > 0) switchTab('files');
                else if (r.functions.length > 0) switchTab('functions');
                else if (r.tables.length > 0) switchTab('tables');

            } catch (e) { console.error(e); }
        }

        function switchTab(name, el) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));

            if (el) el.classList.add('active');
            else document.querySelector(`.tab:nth-child(${name === 'files' ? 1 : name === 'functions' ? 2 : 3})`).classList.add('active');

            document.getElementById(name + 'Panel').classList.add('active');
        }

        function esc(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
