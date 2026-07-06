<?php
/**
 * System Wiki - Function Detail Page
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
    <title><?php echo htmlspecialchars(t('system-wiki.function.page_title')); ?></title>
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

        .func-header {
            background: #fff;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }
        .func-name {
            font-size: 22px;
            font-weight: 600;
            font-family: monospace;
            color: #333;
            margin-bottom: 12px;
        }
        .func-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            font-size: 13px;
            color: #666;
        }
        .func-meta a { color: #c62828; text-decoration: none; }
        .func-meta a:hover { text-decoration: underline; }
        .func-params {
            padding: 10px 16px;
            background: #f5f7fa;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
            color: #555;
        }
        .func-desc {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #eee;
            font-size: 14px;
            color: #555;
            line-height: 1.5;
        }

        .section {
            background: #fff;
            border-radius: 8px;
            margin-bottom: 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }
        .section-header {
            padding: 14px 20px;
            font-size: 14px;
            font-weight: 600;
            color: #333;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-count {
            background: #f0f0f0;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 11px;
            color: #888;
        }
        .caller-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .caller-table th {
            text-align: left;
            padding: 8px 16px;
            background: #f9f9f9;
            color: #666;
            font-weight: 600;
        }
        .caller-table td {
            padding: 7px 16px;
            border-bottom: 1px solid #f5f5f5;
        }
        .caller-table a { color: #c62828; text-decoration: none; }
        .caller-table a:hover { text-decoration: underline; }
        .line-ref { color: #aaa; font-family: monospace; font-size: 12px; }
        .empty-section { padding: 20px; color: #aaa; font-size: 13px; font-style: italic; }
        .visibility-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
            background: #e8eaf6;
            color: #3f51b5;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wiki-detail">
        <div class="detail-content" id="content">
            <div style="text-align:center;padding:60px;color:#aaa;"><?php echo htmlspecialchars(t('system-wiki.function.loading')); ?></div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/wiki/';
        const funcId = new URLSearchParams(window.location.search).get('id');

        document.addEventListener('DOMContentLoaded', loadFunctionDetail);

        async function loadFunctionDetail() {
            if (!funcId) {
                document.getElementById('content').innerHTML = '<div style="text-align:center;padding:60px;color:#888;">' + esc(window.t('system-wiki.function.no_id')) + '</div>';
                return;
            }

            try {
                const res = await fetch(API_BASE + 'get_function_detail.php?id=' + funcId);
                const data = await res.json();

                if (!data.success) {
                    document.getElementById('content').innerHTML = '<div style="text-align:center;padding:60px;color:#c62828;">' + esc(window.t('system-wiki.function.error_prefix')) + esc(data.error) + '</div>';
                    return;
                }

                const fn = data.function;
                const container = document.getElementById('content');

                let visHtml = '';
                if (fn.visibility) visHtml += `<span class="visibility-badge">${esc(fn.visibility)}</span> `;
                if (fn.is_static == 1) visHtml += `<span class="visibility-badge">${esc(window.t('system-wiki.function.static'))}</span> `;

                let html = `
                    <div class="breadcrumb">
                        <a href="./">${esc(window.t('system-wiki.function.wiki'))}</a> <span>/</span>
                        <a href="file.php?id=${fn.file_id}">${esc(fn.file_name)}</a> <span>/</span>
                        ${esc(fn.function_name)}()
                    </div>
                    <div class="func-header">
                        <div class="func-name">${visHtml}function ${esc(fn.function_name)}(${esc(fn.parameters || '')})</div>
                        <div class="func-meta">
                            <div>${esc(window.t('system-wiki.function.defined_in'))} <a href="file.php?id=${fn.file_id}">${esc(fn.file_path)}</a></div>
                            <div>${esc(window.t('system-wiki.function.line'))} <strong>${fn.line_number}</strong></div>
                        </div>
                        ${fn.parameters ? `<div class="func-params">${esc(window.t('system-wiki.function.parameters'))} ${esc(fn.parameters)}</div>` : ''}
                        ${fn.description ? `<div class="func-desc">${esc(fn.description)}</div>` : ''}
                    </div>

                    <div class="section">
                        <div class="section-header">${esc(window.t('system-wiki.function.called_by'))} <span class="section-count">${data.callers.length}</span></div>
                        ${data.callers.length === 0
                            ? '<div class="empty-section">' + esc(window.t('system-wiki.function.no_callers')) + '</div>'
                            : `<table class="caller-table">
                                <thead><tr><th>${esc(window.t('system-wiki.function.col_file'))}</th><th>${esc(window.t('system-wiki.function.col_line'))}</th></tr></thead>
                                <tbody>${data.callers.map(c => `
                                    <tr>
                                        <td><a href="file.php?id=${c.file_id}">${esc(c.file_path)}</a></td>
                                        <td class="line-ref">L${c.line_number || ''}</td>
                                    </tr>
                                `).join('')}</tbody>
                            </table>`
                        }
                    </div>
                `;

                container.innerHTML = html;
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
