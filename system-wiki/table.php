<?php
/**
 * System Wiki - Single Table Reference Page
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
    <title><?php echo htmlspecialchars(t('system-wiki.table.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .wiki-detail {
            height: calc(100vh - 48px);
            overflow-y: auto;
            background: #f5f7fa;
        }
        .detail-content {
            max-width: 900px;
            margin: 0 auto;
            padding: 24px 20px;
        }
        .breadcrumb {
            font-size: 13px;
            color: #888;
            margin-bottom: 16px;
        }
        .breadcrumb a { color: #c62828; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .breadcrumb span { margin: 0 6px; color: #ccc; }

        .table-header {
            background: #fff;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }
        .table-name {
            font-size: 22px;
            font-weight: 600;
            font-family: monospace;
            color: #333;
            margin-bottom: 8px;
        }
        .table-meta { font-size: 13px; color: #888; }

        .ref-group {
            background: #fff;
            border-radius: 8px;
            margin-bottom: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .ref-group-header {
            padding: 12px 18px;
            font-size: 13px;
            font-weight: 600;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ref-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
        }
        .ref-badge.SELECT { background: #e3f2fd; color: #1565c0; }
        .ref-badge.INSERT { background: #e8f5e9; color: #2e7d32; }
        .ref-badge.UPDATE { background: #fff3e0; color: #e65100; }
        .ref-badge.DELETE { background: #fce4ec; color: #c62828; }
        .ref-badge.JOIN { background: #f3e5f5; color: #7b1fa2; }
        .ref-badge.CREATE { background: #e0f2f1; color: #00695c; }

        .ref-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .ref-table td {
            padding: 6px 18px;
            border-bottom: 1px solid #f5f5f5;
        }
        .ref-table tr:last-child td { border-bottom: none; }
        .ref-table a { color: #c62828; text-decoration: none; }
        .ref-table a:hover { text-decoration: underline; }
        .line-ref { color: #aaa; font-family: monospace; font-size: 12px; }
        .no-data { text-align: center; padding: 40px; color: #aaa; font-size: 14px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wiki-detail">
        <div class="detail-content" id="content">
            <div style="text-align:center;padding:60px;color:#aaa;"><?php echo htmlspecialchars(t('system-wiki.table.loading')); ?></div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/wiki/';
        const tableName = new URLSearchParams(window.location.search).get('name');

        document.addEventListener('DOMContentLoaded', loadTableRefs);

        async function loadTableRefs() {
            if (!tableName) {
                document.getElementById('content').innerHTML = '<div class="no-data">' + esc(window.t('system-wiki.table.no_name')) + '</div>';
                return;
            }

            try {
                const res = await fetch(API_BASE + 'get_table_references.php?table=' + encodeURIComponent(tableName));
                const data = await res.json();

                if (!data.success) {
                    document.getElementById('content').innerHTML = '<div class="no-data">' + esc(window.t('system-wiki.table.error_prefix')) + esc(data.error) + '</div>';
                    return;
                }

                const container = document.getElementById('content');
                const refs = data.references;

                // Group by reference type
                const groups = {};
                for (const r of refs) {
                    if (!groups[r.reference_type]) groups[r.reference_type] = [];
                    groups[r.reference_type].push(r);
                }

                const uniqueFiles = new Set(refs.map(r => r.file_id)).size;

                let html = `
                    <div class="breadcrumb">
                        <a href="./">${esc(window.t('system-wiki.table.wiki'))}</a> <span>/</span>
                        <a href="tables.php">${esc(window.t('system-wiki.table.tables'))}</a> <span>/</span>
                        ${esc(tableName)}
                    </div>
                    <div class="table-header">
                        <div class="table-name">${esc(tableName)}</div>
                        <div class="table-meta">${esc(window.t('system-wiki.table.refs_across_files', { refs: refs.length, files: uniqueFiles }))}</div>
                    </div>
                `;

                const typeOrder = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'JOIN', 'CREATE'];
                for (const type of typeOrder) {
                    if (!groups[type]) continue;
                    html += `
                        <div class="ref-group">
                            <div class="ref-group-header">
                                <span class="ref-badge ${type}">${type}</span>
                                ${groups[type].length} ${esc(window.t('system-wiki.table.references'))}
                            </div>
                            <table class="ref-table">
                                ${groups[type].map(r => `
                                    <tr>
                                        <td><a href="file.php?id=${r.file_id}">${esc(r.file_path)}</a></td>
                                        <td class="line-ref" style="width:60px;">L${r.line_number || ''}</td>
                                    </tr>
                                `).join('')}
                            </table>
                        </div>
                    `;
                }

                if (refs.length === 0) {
                    html += '<div class="no-data">' + esc(window.t('system-wiki.table.no_references')) + '</div>';
                }

                container.innerHTML = html;
            } catch (e) { console.error(e); }
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
