<?php
/**
 * System Wiki - File Detail Page
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
    <title><?php echo htmlspecialchars(t('system-wiki.file.page_title')); ?></title>
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
            max-width: 1100px;
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

        .file-header {
            background: #fff;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }
        .file-title {
            font-size: 22px;
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        .file-path {
            font-size: 13px;
            color: #888;
            margin-bottom: 12px;
            font-family: monospace;
        }
        .file-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .meta-item {
            font-size: 13px;
            color: #666;
        }
        .meta-item strong { color: #333; }
        .file-description {
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
            cursor: pointer;
            user-select: none;
        }
        .section-header:hover { background: #fafafa; }
        .section-count {
            background: #f0f0f0;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 500;
            color: #888;
        }
        .section-body { padding: 0; }
        .section-body.collapsed { display: none; }

        .detail-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .detail-table th {
            text-align: left;
            padding: 8px 16px;
            background: #f9f9f9;
            color: #666;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .detail-table td {
            padding: 7px 16px;
            border-bottom: 1px solid #f5f5f5;
        }
        .detail-table tr:last-child td { border-bottom: none; }
        .detail-table a { color: #c62828; text-decoration: none; }
        .detail-table a:hover { text-decoration: underline; }

        .type-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
        }
        .type-badge.php { background: #e8eaf6; color: #3f51b5; }
        .type-badge.js { background: #fff8e1; color: #f57f17; }
        .dep-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
        }
        .dep-badge.require_once, .dep-badge.require { background: #e8f5e9; color: #2e7d32; }
        .dep-badge.include_once, .dep-badge.include { background: #e3f2fd; color: #1565c0; }
        .dep-badge.fetch { background: #fff3e0; color: #e65100; }
        .dep-badge.href { background: #f3e5f5; color: #7b1fa2; }
        .dep-badge.redirect { background: #fce4ec; color: #c62828; }
        .dep-badge.form_action { background: #e0f7fa; color: #00838f; }
        .ref-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
        }
        .ref-badge.SELECT { background: #e3f2fd; color: #1565c0; }
        .ref-badge.INSERT { background: #e8f5e9; color: #2e7d32; }
        .ref-badge.UPDATE { background: #fff3e0; color: #e65100; }
        .ref-badge.DELETE { background: #fce4ec; color: #c62828; }
        .ref-badge.JOIN { background: #f3e5f5; color: #7b1fa2; }
        .ref-badge.CREATE { background: #e0f2f1; color: #00695c; }
        .access-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
        }
        .access-badge.read { background: #e3f2fd; color: #1565c0; }
        .access-badge.write { background: #fff3e0; color: #e65100; }

        .line-ref { color: #aaa; font-family: monospace; font-size: 12px; }
        .empty-section { padding: 16px 20px; color: #aaa; font-size: 13px; font-style: italic; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wiki-detail">
        <div class="detail-content" id="content">
            <div style="text-align:center;padding:60px;color:#aaa;"><?php echo htmlspecialchars(t('system-wiki.file.loading')); ?></div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/wiki/';
        const fileId = new URLSearchParams(window.location.search).get('id');

        document.addEventListener('DOMContentLoaded', loadFileDetail);

        async function loadFileDetail() {
            if (!fileId) {
                document.getElementById('content').innerHTML = '<div style="text-align:center;padding:60px;color:#888;">' + esc(window.t('system-wiki.file.no_id')) + '</div>';
                return;
            }

            try {
                const res = await fetch(API_BASE + 'get_file_detail.php?id=' + fileId);
                const data = await res.json();

                if (!data.success) {
                    document.getElementById('content').innerHTML = '<div style="text-align:center;padding:60px;color:#c62828;">' + esc(window.t('system-wiki.file.error_prefix')) + esc(data.error) + '</div>';
                    return;
                }

                renderFileDetail(data);
            } catch (e) {
                console.error(e);
            }
        }

        function renderFileDetail(data) {
            const f = data.file;
            const container = document.getElementById('content');

            // Breadcrumb
            const parts = f.folder_path ? f.folder_path.split('/') : [];
            let breadcrumb = `<a href="./">${esc(window.t('system-wiki.file.wiki'))}</a>`;
            let cumPath = '';
            for (const p of parts) {
                cumPath += (cumPath ? '/' : '') + p;
                breadcrumb += ` <span>/</span> <a href="./?folder=${encodeURIComponent(cumPath)}">${esc(p)}</a>`;
            }
            breadcrumb += ` <span>/</span> ${esc(f.file_name)}`;

            // File size formatting
            const sizeKb = (f.file_size_bytes / 1024).toFixed(1);

            // Last modified
            const modified = f.last_modified ? new Date(f.last_modified).toLocaleDateString() : window.t('system-wiki.file.unknown');

            let html = `
                <div class="breadcrumb">${breadcrumb}</div>
                <div class="file-header">
                    <div class="file-title">
                        <span class="type-badge ${f.file_type.toLowerCase()}">${f.file_type}</span>
                        ${esc(f.file_name)}
                    </div>
                    <div class="file-path">${esc(f.file_path)}</div>
                    <div class="file-meta">
                        <div class="meta-item"><strong>${parseInt(f.line_count).toLocaleString()}</strong> ${esc(window.t('system-wiki.file.lines'))}</div>
                        <div class="meta-item"><strong>${sizeKb}</strong> ${esc(window.t('system-wiki.file.kb'))}</div>
                        <div class="meta-item">${esc(window.t('system-wiki.file.modified'))} <strong>${esc(modified)}</strong></div>
                    </div>
                    ${f.description ? `<div class="file-description">${esc(f.description)}</div>` : ''}
                </div>
            `;

            // Functions section
            html += renderSection(window.t('system-wiki.file.sec_functions'), data.functions, fn => `
                <tr>
                    <td><a href="function.php?id=${fn.id}">${esc(fn.function_name)}</a></td>
                    <td class="line-ref">L${fn.line_number}</td>
                    <td><code>${esc(fn.parameters || '')}</code></td>
                    <td>${fn.visibility ? esc(fn.visibility) : ''} ${fn.is_static == 1 ? esc(window.t('system-wiki.file.static')) : ''}</td>
                    <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(fn.description || '')}</td>
                </tr>
            `, `<th>${esc(window.t('system-wiki.file.col_name'))}</th><th>${esc(window.t('system-wiki.file.col_line'))}</th><th>${esc(window.t('system-wiki.file.col_parameters'))}</th><th>${esc(window.t('system-wiki.file.col_scope'))}</th><th>${esc(window.t('system-wiki.file.col_description'))}</th>`);

            // Classes section
            if (data.classes.length > 0) {
                html += renderSection(window.t('system-wiki.file.sec_classes'), data.classes, cls => `
                    <tr>
                        <td><strong>${esc(cls.class_name)}</strong></td>
                        <td class="line-ref">L${cls.line_number}</td>
                        <td>${cls.extends_class ? esc(window.t('system-wiki.file.extends')) + ' ' + esc(cls.extends_class) : ''}</td>
                        <td>${esc(cls.description || '')}</td>
                    </tr>
                `, `<th>${esc(window.t('system-wiki.file.col_name'))}</th><th>${esc(window.t('system-wiki.file.col_line'))}</th><th>${esc(window.t('system-wiki.file.col_extends'))}</th><th>${esc(window.t('system-wiki.file.col_description'))}</th>`);
            }

            // Dependencies section
            html += renderSection(window.t('system-wiki.file.sec_dependencies'), data.dependencies, dep => `
                <tr>
                    <td><span class="dep-badge ${dep.dependency_type}">${dep.dependency_type}</span></td>
                    <td>${dep.resolved_file_id
                        ? `<a href="file.php?id=${dep.resolved_file_id}">${esc(dep.resolved_name)}</a>`
                        : `<span style="color:#aaa">${esc(dep.target_path)}</span>`}</td>
                    <td class="line-ref">L${dep.line_number || ''}</td>
                </tr>
            `, `<th>${esc(window.t('system-wiki.file.col_type'))}</th><th>${esc(window.t('system-wiki.file.col_target'))}</th><th>${esc(window.t('system-wiki.file.col_line'))}</th>`);

            // Dependents section
            html += renderSection(window.t('system-wiki.file.sec_dependents'), data.dependents, dep => `
                <tr>
                    <td><span class="dep-badge ${dep.dependency_type}">${dep.dependency_type}</span></td>
                    <td><a href="file.php?id=${dep.source_file_id}">${esc(dep.source_file_path)}</a></td>
                    <td class="line-ref">L${dep.line_number || ''}</td>
                </tr>
            `, `<th>${esc(window.t('system-wiki.file.col_type'))}</th><th>${esc(window.t('system-wiki.file.col_source_file'))}</th><th>${esc(window.t('system-wiki.file.col_line'))}</th>`);

            // DB References section
            html += renderSection(window.t('system-wiki.file.sec_db_tables'), data.db_references, ref => `
                <tr>
                    <td><span class="ref-badge ${ref.reference_type}">${ref.reference_type}</span></td>
                    <td><a href="table.php?name=${encodeURIComponent(ref.table_name)}">${esc(ref.table_name)}</a></td>
                    <td class="line-ref">L${ref.line_number || ''}</td>
                </tr>
            `, `<th>${esc(window.t('system-wiki.file.col_operation'))}</th><th>${esc(window.t('system-wiki.file.col_table'))}</th><th>${esc(window.t('system-wiki.file.col_line'))}</th>`);

            // Session Variables section
            html += renderSection(window.t('system-wiki.file.sec_session_vars'), data.session_vars, sv => `
                <tr>
                    <td><span class="access-badge ${sv.access_type}">${sv.access_type}</span></td>
                    <td><code>$_SESSION['${esc(sv.variable_name)}']</code></td>
                    <td class="line-ref">L${sv.line_number || ''}</td>
                </tr>
            `, `<th>${esc(window.t('system-wiki.file.col_access'))}</th><th>${esc(window.t('system-wiki.file.col_variable'))}</th><th>${esc(window.t('system-wiki.file.col_line'))}</th>`);

            container.innerHTML = html;
        }

        function renderSection(title, items, rowFn, headerHtml) {
            const count = items.length;
            return `
                <div class="section">
                    <div class="section-header" onclick="this.nextElementSibling.classList.toggle('collapsed')">
                        ${title} <span class="section-count">${count}</span>
                    </div>
                    <div class="section-body${count === 0 ? ' collapsed' : ''}">
                        ${count === 0
                            ? '<div class="empty-section">' + esc(window.t('system-wiki.file.none_found')) + '</div>'
                            : `<table class="detail-table"><thead><tr>${headerHtml}</tr></thead><tbody>${items.map(rowFn).join('')}</tbody></table>`
                        }
                    </div>
                </div>
            `;
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
