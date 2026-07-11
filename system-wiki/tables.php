<?php
/**
 * System Wiki - All Database Tables List
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('wiki');

$current_page = 'tables';
$path_prefix = '../';

$translationNamespaces = ['common', 'system-wiki'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('system-wiki.tables.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .wiki-tables {
            height: calc(100vh - 48px);
            overflow-y: auto;
            background: #f5f7fa;
        }
        .tables-content {
            max-width: 1000px;
            margin: 0 auto;
            padding: 24px 20px;
        }
        .page-title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 16px;
        }
        .page-subtitle {
            font-size: 13px;
            color: #888;
            margin-bottom: 20px;
        }
        .tables-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .tables-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .tables-table th {
            text-align: left;
            padding: 10px 16px;
            background: #f9f9f9;
            color: #666;
            font-weight: 600;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            user-select: none;
        }
        .tables-table th:hover { background: #f0f0f0; }
        .tables-table td {
            padding: 8px 16px;
            border-bottom: 1px solid #f5f5f5;
        }
        .tables-table tr:hover td { background: #fafafa; }
        .tables-table a { color: #c62828; text-decoration: none; font-weight: 500; }
        .tables-table a:hover { text-decoration: underline; }
        .op-count {
            display: inline-block;
            min-width: 22px;
            text-align: center;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
        }
        .op-count.sel { background: #e3f2fd; color: #1565c0; }
        .op-count.ins { background: #e8f5e9; color: #2e7d32; }
        .op-count.upd { background: #fff3e0; color: #e65100; }
        .op-count.del { background: #fce4ec; color: #c62828; }
        .op-count.join { background: #f3e5f5; color: #7b1fa2; }
        .op-count.zero { background: transparent; color: #ddd; }
        .no-data { text-align: center; padding: 40px; color: #aaa; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wiki-tables">
        <div class="tables-content">
            <div class="page-title"><?php echo htmlspecialchars(t('system-wiki.tables.heading')); ?></div>
            <div class="page-subtitle" id="subtitle"><?php echo htmlspecialchars(t('system-wiki.tables.loading')); ?></div>

            <div class="tables-card">
                <table class="tables-table">
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('system-wiki.tables.col_name')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.tables.col_files')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.tables.col_total')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.tables.col_select')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.tables.col_insert')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.tables.col_update')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.tables.col_delete')); ?></th>
                            <th><?php echo htmlspecialchars(t('system-wiki.tables.col_join')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <tr><td colspan="8" class="no-data"><?php echo htmlspecialchars(t('system-wiki.tables.loading')); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/wiki/';

        document.addEventListener('DOMContentLoaded', loadTables);

        async function loadTables() {
            try {
                const res = await fetch(API_BASE + 'get_tables_list.php');
                const data = await res.json();
                const tbody = document.getElementById('tableBody');

                if (!data.success || !data.tables.length) {
                    tbody.innerHTML = '<tr><td colspan="8" class="no-data">' + esc(window.t('system-wiki.tables.no_refs')) + '</td></tr>';
                    document.getElementById('subtitle').textContent = '';
                    return;
                }

                document.getElementById('subtitle').textContent = window.t('system-wiki.tables.discovered', { n: data.tables.length });

                tbody.innerHTML = data.tables.map(t => `
                    <tr>
                        <td><a href="table.php?name=${encodeURIComponent(t.table_name)}">${esc(t.table_name)}</a></td>
                        <td>${t.file_count}</td>
                        <td><strong>${t.reference_count}</strong></td>
                        <td>${opBadge(t.select_count, 'sel')}</td>
                        <td>${opBadge(t.insert_count, 'ins')}</td>
                        <td>${opBadge(t.update_count, 'upd')}</td>
                        <td>${opBadge(t.delete_count, 'del')}</td>
                        <td>${opBadge(t.join_count, 'join')}</td>
                    </tr>
                `).join('');
            } catch (e) { console.error(e); }
        }

        function opBadge(count, cls) {
            return count > 0
                ? `<span class="op-count ${cls}">${count}</span>`
                : `<span class="op-count zero">-</span>`;
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
