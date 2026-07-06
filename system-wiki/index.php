<?php
/**
 * System Wiki - Dashboard + File Browser
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'browse';
$path_prefix = '../';

$translationNamespaces = ['common', 'system-wiki'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('system-wiki.index.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .wiki-container {
            display: flex;
            height: calc(100vh - 48px);
            overflow: hidden;
        }

        /* Stats bar */
        .stats-bar {
            display: flex;
            gap: 12px;
            padding: 16px 20px;
            background: #fff;
            border-bottom: 1px solid #e0e0e0;
            align-items: center;
            flex-wrap: wrap;
        }
        .stat-chip {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #f5f7fa;
            border-radius: 20px;
            font-size: 13px;
            color: #555;
        }
        .stat-chip strong {
            color: #333;
            font-weight: 600;
        }
        .stat-chip.scan-info {
            margin-left: auto;
            color: #888;
            font-size: 12px;
        }
        .search-box {
            margin-left: auto;
            display: flex;
            gap: 6px;
        }
        .search-box input {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            width: 200px;
        }
        .search-box button {
            padding: 6px 14px;
            background: #c62828;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }
        .search-box button:hover { background: #b71c1c; }

        /* Sidebar */
        .wiki-sidebar {
            width: 280px;
            min-width: 280px;
            background: #fff;
            border-right: 1px solid #e0e0e0;
            overflow-y: auto;
            padding: 12px 0;
        }
        .sidebar-title {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: #999;
            padding: 0 16px 8px;
            letter-spacing: 0.5px;
        }
        .tree-item {
            display: flex;
            align-items: center;
            padding: 5px 12px 5px calc(12px + var(--depth, 0) * 18px);
            cursor: pointer;
            font-size: 13px;
            color: #444;
            transition: background 0.1s;
            user-select: none;
        }
        .tree-item:hover { background: #f5f7fa; }
        .tree-item.active { background: #fce4ec; color: #c62828; font-weight: 500; }
        .tree-toggle {
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 4px;
            font-size: 10px;
            color: #999;
            flex-shrink: 0;
        }
        .tree-icon {
            margin-right: 6px;
            font-size: 14px;
            flex-shrink: 0;
        }
        .tree-count {
            margin-left: auto;
            font-size: 11px;
            color: #aaa;
            padding-left: 8px;
        }
        .tree-children { display: none; }
        .tree-children.open { display: block; }

        /* Main content */
        .wiki-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .file-list-header {
            padding: 12px 20px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
            font-weight: 500;
            color: #333;
            background: #fafafa;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .file-list-count { font-size: 12px; color: #888; font-weight: 400; }
        .file-list {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }
        .file-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .file-table th {
            text-align: left;
            padding: 8px 16px;
            background: #f9f9f9;
            border-bottom: 1px solid #eee;
            font-weight: 600;
            color: #666;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .file-table td {
            padding: 7px 16px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        .file-table tr:hover td { background: #fafafa; }
        .file-table tr { cursor: pointer; }
        .file-name-cell {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .file-name-cell a {
            color: #c62828;
            text-decoration: none;
            font-weight: 500;
        }
        .file-name-cell a:hover { text-decoration: underline; }
        .type-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .type-badge.php { background: #e8eaf6; color: #3f51b5; }
        .type-badge.js { background: #fff8e1; color: #f57f17; }
        .file-desc {
            color: #888;
            font-size: 12px;
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #aaa;
            font-size: 14px;
        }
        .no-data h3 { color: #666; margin-bottom: 8px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="stats-bar" id="statsBar">
        <span style="color:#aaa;font-size:13px;"><?php echo htmlspecialchars(t('system-wiki.index.loading_stats')); ?></span>
    </div>

    <div class="wiki-container">
        <div class="wiki-sidebar" id="sidebar">
            <div class="sidebar-title"><?php echo htmlspecialchars(t('system-wiki.index.folders')); ?></div>
            <div id="folderTree"><span style="padding:16px;color:#aaa;font-size:13px;display:block;"><?php echo htmlspecialchars(t('system-wiki.index.loading')); ?></span></div>
        </div>
        <div class="wiki-main">
            <div class="file-list-header">
                <span id="listTitle"><?php echo htmlspecialchars(t('system-wiki.index.all_files')); ?></span>
                <span class="file-list-count" id="listCount"></span>
            </div>
            <div class="file-list">
                <table class="file-table">
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('system-wiki.index.col_file')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.index.col_type')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.index.col_lines')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.index.col_functions')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.index.col_description')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="fileTableBody">
                        <tr><td colspan="5" class="no-data"><?php echo htmlspecialchars(t('system-wiki.index.loading')); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/wiki/';
        let currentFolder = null;

        document.addEventListener('DOMContentLoaded', () => {
            loadStats();
            loadFolderTree();
            loadFiles();
        });

        async function loadStats() {
            try {
                const res = await fetch(API_BASE + 'get_stats.php');
                const data = await res.json();
                const bar = document.getElementById('statsBar');

                if (!data.success || !data.stats) {
                    bar.innerHTML = '<span style="color:#888;font-size:13px;">' + esc(window.t('system-wiki.index.no_scan_data')) + '</span>';
                    return;
                }

                const s = data.stats;
                let scanInfo = '';
                if (s.last_scan) {
                    const d = new Date(s.last_scan);
                    scanInfo = window.t('system-wiki.index.last_scan', { date: d.toLocaleDateString(), time: d.toLocaleTimeString(), duration: s.scan_duration_seconds });
                }

                bar.innerHTML = `
                    <div class="stat-chip"><strong>${s.total_files}</strong> ${esc(window.t('system-wiki.index.stat_files'))}</div>
                    <div class="stat-chip"><strong>${s.php_files}</strong> ${esc(window.t('system-wiki.index.stat_php'))}</div>
                    <div class="stat-chip"><strong>${s.js_files}</strong> ${esc(window.t('system-wiki.index.stat_js'))}</div>
                    <div class="stat-chip"><strong>${s.total_functions}</strong> ${esc(window.t('system-wiki.index.stat_functions'))}</div>
                    <div class="stat-chip"><strong>${s.total_tables}</strong> ${esc(window.t('system-wiki.index.stat_tables'))}</div>
                    <div class="stat-chip"><strong>${s.total_folders}</strong> ${esc(window.t('system-wiki.index.stat_folders'))}</div>
                    <div class="search-box">
                        <input type="text" id="searchInput" placeholder="${esc(window.t('system-wiki.index.search_placeholder'))}" onkeydown="if(event.key==='Enter') doSearch()">
                        <button onclick="doSearch()">${esc(window.t('system-wiki.index.search_btn'))}</button>
                    </div>
                    <div class="stat-chip scan-info">${esc(scanInfo)}</div>
                `;
            } catch (e) { console.error(e); }
        }

        function doSearch() {
            const q = document.getElementById('searchInput').value.trim();
            if (q.length >= 2) {
                window.location.href = 'search.php?q=' + encodeURIComponent(q);
            }
        }

        async function loadFolderTree() {
            try {
                const res = await fetch(API_BASE + 'get_folder_tree.php');
                const data = await res.json();
                if (!data.success) return;

                const container = document.getElementById('folderTree');
                container.innerHTML = renderTree(data.tree, 0);
            } catch (e) { console.error(e); }
        }

        function renderTree(nodes, depth) {
            let html = '';
            for (const node of nodes) {
                const hasChildren = node.children && node.children.length > 0;
                const id = 'tree_' + node.path.replace(/[^a-zA-Z0-9]/g, '_');

                html += `<div class="tree-item" style="--depth:${depth}" onclick="selectFolder('${esc(node.path)}', this)" data-path="${esc(node.path)}">`;
                html += `<span class="tree-toggle" onclick="event.stopPropagation(); toggleTree('${id}', this)">${hasChildren ? '&#9654;' : ''}</span>`;
                html += `<span class="tree-icon">${node.name === '(root)' ? '&#128193;' : '&#128194;'}</span>`;
                html += `<span>${esc(node.name)}</span>`;
                if (node.file_count > 0) html += `<span class="tree-count">${node.file_count}</span>`;
                html += `</div>`;

                if (hasChildren) {
                    html += `<div class="tree-children" id="${id}">`;
                    html += renderTree(node.children, depth + 1);
                    html += `</div>`;
                }
            }
            return html;
        }

        function toggleTree(id, toggle) {
            const el = document.getElementById(id);
            if (!el) return;
            const isOpen = el.classList.toggle('open');
            toggle.innerHTML = isOpen ? '&#9660;' : '&#9654;';
        }

        function selectFolder(path, el) {
            // Remove active from all
            document.querySelectorAll('.tree-item.active').forEach(e => e.classList.remove('active'));
            if (el) el.classList.add('active');

            // Auto-expand children when clicking a parent folder
            const id = 'tree_' + path.replace(/[^a-zA-Z0-9]/g, '_');
            const childContainer = document.getElementById(id);
            if (childContainer && !childContainer.classList.contains('open')) {
                childContainer.classList.add('open');
                const toggle = el ? el.querySelector('.tree-toggle') : null;
                if (toggle && toggle.innerHTML.includes('9654')) toggle.innerHTML = '&#9660;';
            }

            currentFolder = path;
            document.getElementById('listTitle').textContent = path || window.t('system-wiki.index.root_files');
            loadFiles(path);
        }

        async function loadFiles(folder) {
            try {
                let url = API_BASE + 'get_files.php';
                if (folder !== undefined && folder !== null) {
                    url += '?folder=' + encodeURIComponent(folder);
                }

                const res = await fetch(url);
                const data = await res.json();
                const tbody = document.getElementById('fileTableBody');

                if (!data.success || !data.files.length) {
                    tbody.innerHTML = '<tr><td colspan="5" class="no-data"><h3>' + esc(window.t('system-wiki.index.no_files_title')) + '</h3>' + esc(window.t('system-wiki.index.no_files_body')) + '</td></tr>';
                    document.getElementById('listCount').textContent = '';
                    return;
                }

                document.getElementById('listCount').textContent = window.t('system-wiki.index.files_count', { n: data.files.length });

                tbody.innerHTML = data.files.map(f => `
                    <tr onclick="window.location.href='file.php?id=${f.id}'">
                        <td>
                            <div class="file-name-cell">
                                <a href="file.php?id=${f.id}" onclick="event.stopPropagation()">${esc(f.file_name)}</a>
                            </div>
                        </td>
                        <td><span class="type-badge ${f.file_type.toLowerCase()}">${f.file_type}</span></td>
                        <td>${f.line_count.toLocaleString()}</td>
                        <td>${f.function_count}</td>
                        <td><span class="file-desc">${esc(f.description || '')}</span></td>
                    </tr>
                `).join('');
            } catch (e) {
                console.error(e);
            }
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
