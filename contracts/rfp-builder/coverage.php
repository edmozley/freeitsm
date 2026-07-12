<?php
/**
 * RFP Builder — coverage heatmap (Phase 5 step 5d).
 *
 * Visualises which departments contributed requirements to which
 * categories. Useful for spotting:
 *   - single-source categories (only one dept asked = potential bias)
 *   - multi-dept consensus categories (many depts asked = strong signal)
 *   - departmental specialisations (which depts hit many categories)
 *
 * Heat intensity scales linearly between 0 (no contribution) and the
 * row's max count (so each category compares against itself, not
 * across the whole matrix — keeps the picture readable).
 */
session_start();
require_once '../../config.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'rfp-builder';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'contracts'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('contracts.rfp.coverage.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=20">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { --accent: var(--con-accent, #f59e0b); }
        .page-wrap { padding: 30px 40px; background: var(--app-bg, #f5f5f5); height: calc(100vh - 48px); overflow-y: auto; box-sizing: border-box; }

        .breadcrumb { font-size: 13px; color: var(--text-dim, #888); margin-bottom: 8px; }
        .breadcrumb a { color: var(--text-muted, #666); text-decoration: none; }
        .breadcrumb a:hover { color: var(--con-accent, #f59e0b); }
        .breadcrumb span.sep { margin: 0 6px; color: var(--text-faint, #ccc); }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px;
        }
        .page-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: var(--text, #222); }
        .page-actions { display: flex; gap: 8px; }

        .btn {
            padding: 8px 16px; font-size: 14px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: 1px solid transparent;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.15s; font-family: inherit;
        }
        .btn-secondary { background: var(--surface, #fff); color: var(--text, #333); border-color: var(--border, #ddd); }
        .btn-secondary:hover { background: var(--surface-hover, #f5f5f5); }

        .stats-strip {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
            margin-bottom: 18px;
        }
        .stat-card {
            background: var(--surface, #fff); border-radius: 8px; padding: 14px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border-left: 4px solid var(--border, #ddd);
        }
        .stat-card.total       { border-left-color: #6b7280; }
        .stat-card.multi       { border-left-color: #10b981; }
        .stat-card.single      { border-left-color: #f59e0b; }
        .stat-card.unsupported { border-left-color: #ef4444; }
        .stat-card .stat-value { font-size: 22px; font-weight: 700; color: var(--text, #222); line-height: 1; }
        .stat-card .stat-label {
            font-size: 12px; color: var(--text-dim, #888);
            text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px;
        }

        .matrix-card {
            background: var(--surface, #fff); border-radius: 10px; overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .matrix-scroll {
            overflow-x: auto;
        }

        .heatmap {
            width: 100%; border-collapse: collapse; font-size: 13px;
        }
        .heatmap th, .heatmap td {
            padding: 8px 10px; text-align: center; vertical-align: middle;
            border-bottom: 1px solid var(--border-soft, #f5f5f5);
            white-space: nowrap;
        }
        .heatmap thead th {
            background: var(--surface-2, #fafbfc); border-bottom: 1px solid var(--border-soft, #eef0f2);
            font-size: 11px; font-weight: 700; color: var(--text-muted, #555);
            text-transform: uppercase; letter-spacing: 0.4px;
            position: sticky; top: 0; z-index: 2;
        }
        .heatmap thead th.col-cat {
            text-align: left; padding-left: 16px; min-width: 220px;
        }
        .heatmap thead th .dept-swatch {
            display: inline-block; width: 10px; height: 10px;
            border-radius: 50%; margin-right: 6px; vertical-align: middle;
        }
        .heatmap tbody td.col-cat {
            text-align: left; font-weight: 600; color: var(--text, #222);
            padding-left: 16px; max-width: 280px;
        }
        .heatmap tbody td.col-cat .cat-meta {
            font-size: 11px; font-weight: 400; color: var(--text-dim, #888); margin-top: 2px;
        }

        .heat-cell {
            font-variant-numeric: tabular-nums; font-weight: 600;
            min-width: 64px;
        }
        .heat-cell.zero { color: var(--text-faint, #d1d5db); font-weight: 400; }
        /* The colour shades come from inline styles based on the row's
           max count, so high-traffic cells in any given row pop. */

        .heatmap tbody tr:last-child td { border-bottom: none; }
        .heatmap tbody tr.totals-row td {
            background: var(--surface-2, #fafbfc); font-weight: 700;
            border-top: 2px solid var(--border, #e5e7eb);
        }
        .heatmap td.row-total, .heatmap th.col-total {
            background: var(--surface-3, #f3f4f6); color: var(--text-muted, #374151);
        }

        .coverage-help {
            font-size: 12px; color: var(--text-muted, #666);
            padding: 12px 22px; border-top: 1px solid var(--border-soft, #f0f0f0);
            background: var(--surface-2, #fafbfc);
            display: flex; gap: 18px; align-items: center; flex-wrap: wrap;
        }
        .coverage-help .legend-swatch {
            display: inline-block; width: 14px; height: 14px;
            border-radius: 3px; margin-right: 5px; vertical-align: middle;
        }

        .empty-card, .loading, .error-state {
            background: var(--surface, #fff); border-radius: 10px; padding: 40px 24px;
            text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            color: var(--text-dim, #888);
        }
        .error-state { color: #d13438; }
        .empty-card a { color: var(--con-accent, #f59e0b); text-decoration: none; font-weight: 600; }
        .empty-card a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../"><?php echo htmlspecialchars(t('contracts.title')); ?></a><span class="sep">›</span>
            <a href="./"><?php echo htmlspecialchars(t('contracts.nav.rfp_builder')); ?></a><span class="sep">›</span>
            <a id="bcRfp" href="#">-</a><span class="sep">›</span>
            <span><?php echo htmlspecialchars(t('contracts.rfp.coverage.heading')); ?></span>
        </div>

        <div class="page-header">
            <h1><?php echo htmlspecialchars(t('contracts.rfp.coverage.heading')); ?></h1>
            <div class="page-actions">
                <a id="backLink" href="#" class="btn btn-secondary">&larr; <?php echo htmlspecialchars(t('contracts.rfp.suppliers.overview')); ?></a>
                <a id="consolidateLink" href="#" class="btn btn-secondary"><?php echo htmlspecialchars(t('contracts.rfp.coverage.consolidated_requirements')); ?></a>
            </div>
        </div>

        <div id="loadingEl" class="loading"><?php echo htmlspecialchars(t('common.loading')); ?></div>
        <div id="contentEl" style="display:none;"></div>
        <div id="errorEl" class="error-state" style="display:none;"></div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const rfpId = new URLSearchParams(location.search).get('id');
        let pageData = null;

        document.addEventListener('DOMContentLoaded', () => {
            if (!rfpId) {
                showError(window.t('contracts.rfp.view.no_id') + ' <a href="./">' + window.t('contracts.rfp.view.back_to_list') + '</a>.');
                return;
            }
            document.getElementById('backLink').href        = 'view.php?id=' + encodeURIComponent(rfpId);
            document.getElementById('consolidateLink').href = 'consolidate.php?id=' + encodeURIComponent(rfpId);
            loadAll();
        });

        async function loadAll() {
            try {
                const [rfpRes, dataRes] = await Promise.all([
                    fetch(API_BASE + 'get_rfp.php?id=' + encodeURIComponent(rfpId)).then(r => r.json()),
                    fetch(API_BASE + 'get_coverage.php?rfp_id=' + encodeURIComponent(rfpId)).then(r => r.json())
                ]);
                if (!rfpRes.success)  throw new Error(rfpRes.error  || window.t('contracts.rfp.suppliers.load_rfp_failed'));
                if (!dataRes.success) throw new Error(dataRes.error || window.t('contracts.rfp.coverage.load_failed'));

                const bc = document.getElementById('bcRfp');
                bc.textContent = rfpRes.rfp.name;
                bc.href = 'view.php?id=' + encodeURIComponent(rfpId);

                pageData = dataRes;
                document.getElementById('loadingEl').style.display = 'none';
                render();
            } catch (err) {
                showError(escapeHtml(err.message));
            }
        }

        function render() {
            const contentEl = document.getElementById('contentEl');
            contentEl.style.display = 'block';

            const categories  = pageData.categories  || [];
            const departments = pageData.departments || [];
            const matrix      = pageData.matrix      || {};

            if (categories.length === 0 || departments.length === 0) {
                contentEl.innerHTML = `
                    <div class="empty-card">
                        <p><strong>${escapeHtml(window.t('contracts.rfp.coverage.empty_title'))}</strong></p>
                        <p>${escapeHtml(window.t('contracts.rfp.coverage.empty_body'))}</p>
                        <p><a href="consolidate.php?id=${encodeURIComponent(rfpId)}">${escapeHtml(window.t('contracts.rfp.document.open_consolidation'))}</a></p>
                    </div>
                `;
                return;
            }

            const breakdown = pageData.category_breakdown || {};

            // Stats strip
            const stats = `
                <div class="stats-strip">
                    <div class="stat-card total">
                        <div class="stat-value">${pageData.total_consolidated}</div>
                        <div class="stat-label">${escapeHtml(window.t('contracts.rfp.coverage.stat_consolidated'))}</div>
                    </div>
                    <div class="stat-card multi">
                        <div class="stat-value">${breakdown.multi_source || 0}</div>
                        <div class="stat-label">${escapeHtml(window.t('contracts.rfp.coverage.stat_multi'))}</div>
                    </div>
                    <div class="stat-card single">
                        <div class="stat-value">${breakdown.single_source || 0}</div>
                        <div class="stat-label">${escapeHtml(window.t('contracts.rfp.coverage.stat_single'))}</div>
                    </div>
                    <div class="stat-card unsupported">
                        <div class="stat-value">${breakdown.unsupported || 0}</div>
                        <div class="stat-label">${escapeHtml(window.t('contracts.rfp.coverage.stat_no_linkage'))}</div>
                    </div>
                </div>
            `;

            // Build the table
            const cats = categories.slice();
            if (pageData.has_orphan_category) {
                cats.push({ id: 0, name: window.t('contracts.rfp.compare.uncategorised'), description: null, sort_order: 9999 });
            }

            // Header row
            const headerCells = departments.map(d => {
                const swatch = d.colour
                    ? `<span class="dept-swatch" style="background:${escapeHtml(d.colour)};"></span>`
                    : '';
                return `<th title="${escapeHtml(d.name)}">${swatch}${escapeHtml(d.name)}</th>`;
            }).join('');

            // Body rows
            const bodyRows = cats.map(c => {
                const cid = (c.id !== null && c.id !== undefined) ? c.id : 0;
                const row = matrix[cid] || {};
                // Find the max count in this row so we can scale the
                // heat shade. Row-relative scaling means a category
                // with 1 dept hit gets a darker cell than a category
                // with 5 dept hits — the heatmap shows distribution
                // within the category, not absolute volume.
                const counts = departments.map(d => row[d.id] || 0);
                const rowMax = Math.max(...counts, 1);
                const rowSum = counts.reduce((s, x) => s + x, 0);

                const cells = departments.map(d => {
                    const v = row[d.id] || 0;
                    if (v === 0) {
                        return '<td class="heat-cell zero">—</td>';
                    }
                    const intensity = v / rowMax; // 0..1
                    // Amber gradient — light to dark to match the brand.
                    // 0.15 baseline so even 1-hit cells visibly shade.
                    const alpha = 0.15 + 0.65 * intensity;
                    const colour = `rgba(245, 158, 11, ${alpha.toFixed(2)})`;
                    const textColour = intensity > 0.55 ? '#1f2937' : '#374151';
                    return `<td class="heat-cell" style="background:${colour};color:${textColour};">${v}</td>`;
                }).join('');

                const catCount = pageData.category_totals[cid] || 0;
                return `
                    <tr>
                        <td class="col-cat">
                            ${escapeHtml(c.name)}
                            <div class="cat-meta">${escapeHtml(rowSum === 1 ? window.t('contracts.rfp.coverage.dept_hit_one', { n: rowSum, count: catCount }) : window.t('contracts.rfp.coverage.dept_hit_other', { n: rowSum, count: catCount }))}</div>
                        </td>
                        ${cells}
                        <td class="row-total">${rowSum}</td>
                    </tr>
                `;
            }).join('');

            // Totals row at the bottom (per-department totals)
            const deptTotalCells = departments.map(d => {
                const t = pageData.department_totals[d.id] || 0;
                return `<td class="row-total">${t}</td>`;
            }).join('');
            const grandTotalDeptHits = departments.reduce((s, d) =>
                s + (pageData.department_totals[d.id] || 0), 0);
            const totalsRow = `
                <tr class="totals-row">
                    <td class="col-cat">${escapeHtml(window.t('contracts.rfp.coverage.total_per_dept'))}</td>
                    ${deptTotalCells}
                    <td class="row-total">${grandTotalDeptHits}</td>
                </tr>
            `;

            const matrixHtml = `
                <div class="matrix-card">
                    <div class="matrix-scroll">
                        <table class="heatmap">
                            <thead>
                                <tr>
                                    <th class="col-cat">${escapeHtml(window.t('contracts.rfp.compare.col_category'))}</th>
                                    ${headerCells}
                                    <th class="col-total">${escapeHtml(window.t('contracts.rfp.coverage.row_total'))}</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${bodyRows}
                                ${totalsRow}
                            </tbody>
                        </table>
                    </div>
                    <div class="coverage-help">
                        <div>
                            <span class="legend-swatch" style="background:rgba(245,158,11,0.15);"></span>
                            ${escapeHtml(window.t('contracts.rfp.coverage.legend_lighter'))}
                        </div>
                        <div>
                            <span class="legend-swatch" style="background:rgba(245,158,11,0.80);"></span>
                            ${escapeHtml(window.t('contracts.rfp.coverage.legend_darker'))}
                        </div>
                        <div style="color:var(--text-dim, #888);">
                            ${escapeHtml(window.t('contracts.rfp.coverage.legend_note'))}
                        </div>
                    </div>
                </div>
            `;

            contentEl.innerHTML = stats + matrixHtml;
        }

        function showError(html) {
            document.getElementById('loadingEl').style.display = 'none';
            const el = document.getElementById('errorEl');
            el.innerHTML = html;
            el.style.display = 'block';
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
    </script>
</body>
</html>
