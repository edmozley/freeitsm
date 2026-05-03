<?php
/**
 * RFP Builder — cross-supplier compare (Phase 6 step 6a).
 *
 * Side-by-side decision-making view: big-number cards ranked by
 * overall score, multi-supplier radar overlay, and a category
 * winners table that highlights the leading supplier per category
 * with the gap to second place.
 *
 * Read-only by design — scoring still happens on scoring.php.
 */
session_start();
require_once '../../config.php';

$current_page = 'rfp-builder';
$path_prefix  = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Compare</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/chart.min.js"></script>
    <style>
        body { overflow: auto; height: auto; }
        .page-wrap { padding: 30px 40px; background: #f5f5f5; min-height: calc(100vh - 48px); box-sizing: border-box; }

        .breadcrumb { font-size: 13px; color: #888; margin-bottom: 8px; }
        .breadcrumb a { color: #666; text-decoration: none; }
        .breadcrumb a:hover { color: #f59e0b; }
        .breadcrumb span.sep { margin: 0 6px; color: #ccc; }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px;
        }
        .page-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: #222; }
        .page-actions { display: flex; gap: 8px; }

        .btn {
            padding: 8px 16px; font-size: 14px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: 1px solid transparent;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.15s; font-family: inherit;
        }
        .btn-secondary { background: white; color: #333; border-color: #ddd; }
        .btn-secondary:hover { background: #f5f5f5; }

        /* ─── Big-number cards ──────────────────────────────────── */

        .compare-cards {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px; margin-bottom: 24px;
        }
        .supplier-card {
            background: white; border-radius: 10px; padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-top: 4px solid #d1d5db;
            position: relative; min-height: 140px;
            display: flex; flex-direction: column;
        }
        .supplier-card.rank-1 { border-top-color: #f59e0b; background: linear-gradient(180deg, #fffbeb 0%, white 30%); }
        .supplier-card.rank-2 { border-top-color: #9ca3af; background: linear-gradient(180deg, #f9fafb 0%, white 30%); }
        .supplier-card.rank-3 { border-top-color: #b45309; }
        .supplier-card .rank-pill {
            position: absolute; top: 12px; right: 14px;
            font-size: 11px; font-weight: 700; color: #666;
            background: #eef0f2; padding: 2px 9px; border-radius: 9px;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .supplier-card.rank-1 .rank-pill { background: #fef3c7; color: #92400e; }
        .supplier-card.rank-2 .rank-pill { background: #e5e7eb; color: #4b5563; }
        .supplier-card.rank-3 .rank-pill { background: #fed7aa; color: #9a3412; }
        .supplier-card .name {
            font-size: 14px; color: #888; line-height: 1.3;
            font-weight: 600;
            padding-right: 60px; /* clear of rank pill */
        }
        .supplier-card .big-score {
            font-size: 44px; font-weight: 700; color: #111827;
            font-variant-numeric: tabular-nums;
            line-height: 1; margin-top: auto; padding-top: 14px;
        }
        .supplier-card .big-score .out-of {
            font-size: 16px; color: #9ca3af; font-weight: 500;
        }
        .supplier-card .meta {
            font-size: 11px; color: #999; margin-top: 8px;
            display: flex; justify-content: space-between;
        }

        /* ─── Section card ──────────────────────────────────────── */

        .section-card {
            background: white; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            margin-bottom: 18px; overflow: hidden;
        }
        .section-card-header {
            padding: 14px 22px; border-bottom: 1px solid #f0f0f0;
            background: #fafbfc;
        }
        .section-card-header h2 {
            margin: 0; font-size: 14px; font-weight: 700; color: #075985;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .section-card-body { padding: 20px 22px; }

        .radar-wrap {
            position: relative; height: 480px;
        }

        /* ─── Winners table ─────────────────────────────────────── */

        .winners-table {
            width: 100%; border-collapse: collapse; font-size: 13px;
        }
        .winners-table th, .winners-table td {
            padding: 10px 12px; text-align: left; border-bottom: 1px solid #f5f5f5;
            vertical-align: middle;
        }
        .winners-table thead th {
            font-size: 11px; font-weight: 700; color: #888;
            text-transform: uppercase; letter-spacing: 0.4px;
            background: #fafbfc; border-bottom: 1px solid #eef0f2;
        }
        .winners-table tbody tr:last-child td { border-bottom: none; }
        .winners-table .cat-name { font-weight: 600; color: #222; }
        .winners-table .score-cell {
            text-align: center; font-variant-numeric: tabular-nums;
            color: #555; min-width: 80px;
        }
        .winners-table .score-cell.winner {
            background: #ecfdf5; color: #047857; font-weight: 700;
            border-radius: 4px;
        }
        .winners-table .score-cell.empty { color: #ccc; }
        .winners-table .winner-col {
            font-weight: 600; color: #047857;
        }
        .winners-table .winner-col .gap {
            font-size: 11px; color: #888; margin-left: 6px; font-weight: 500;
        }
        .winners-table .tied { color: #b45309; font-weight: 600; }

        .empty-card, .loading, .error-state {
            background: white; border-radius: 10px; padding: 40px 24px;
            text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            color: #888;
        }
        .error-state { color: #d13438; }
        .empty-card a { color: #f59e0b; text-decoration: none; font-weight: 600; }
        .empty-card a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../">Contracts</a><span class="sep">›</span>
            <a href="./">RFP Builder</a><span class="sep">›</span>
            <a id="bcRfp" href="#">-</a><span class="sep">›</span>
            <span>Compare</span>
        </div>

        <div class="page-header">
            <h1>Compare suppliers</h1>
            <div class="page-actions">
                <a id="backLink" href="#" class="btn btn-secondary">&larr; Overview</a>
                <a id="suppliersLink" href="#" class="btn btn-secondary">Suppliers</a>
            </div>
        </div>

        <div id="loadingEl" class="loading">Loading…</div>
        <div id="contentEl" style="display:none;"></div>
        <div id="errorEl" class="error-state" style="display:none;"></div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const rfpId = new URLSearchParams(location.search).get('id');
        let pageData = null;
        let radarChart = null;

        // Distinct supplier colours (up to 8 — beyond that we cycle).
        // Tuned for visibility on the white radar canvas.
        const SUPPLIER_COLOURS = [
            { border: '#f59e0b', fill: 'rgba(245, 158, 11, 0.20)' },
            { border: '#3b82f6', fill: 'rgba(59, 130, 246, 0.20)' },
            { border: '#10b981', fill: 'rgba(16, 185, 129, 0.20)' },
            { border: '#ef4444', fill: 'rgba(239, 68, 68, 0.20)' },
            { border: '#8b5cf6', fill: 'rgba(139, 92, 246, 0.20)' },
            { border: '#0ea5e9', fill: 'rgba(14, 165, 233, 0.20)' },
            { border: '#84cc16', fill: 'rgba(132, 204, 22, 0.20)' },
            { border: '#ec4899', fill: 'rgba(236, 72, 153, 0.20)' },
        ];
        const supplierColour = (index) => SUPPLIER_COLOURS[index % SUPPLIER_COLOURS.length];

        document.addEventListener('DOMContentLoaded', () => {
            if (!rfpId) {
                showError('No RFP id supplied. <a href="./">Back to list</a>.');
                return;
            }
            document.getElementById('backLink').href     = 'view.php?id=' + encodeURIComponent(rfpId);
            document.getElementById('suppliersLink').href = 'suppliers.php?id=' + encodeURIComponent(rfpId);
            loadAll();
        });

        async function loadAll() {
            try {
                const [rfpRes, dataRes] = await Promise.all([
                    fetch(API_BASE + 'get_rfp.php?id=' + encodeURIComponent(rfpId)).then(r => r.json()),
                    fetch(API_BASE + 'get_compare_data.php?rfp_id=' + encodeURIComponent(rfpId)).then(r => r.json())
                ]);
                if (!rfpRes.success)  throw new Error(rfpRes.error  || 'Failed to load RFP');
                if (!dataRes.success) throw new Error(dataRes.error || 'Failed to load compare data');

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

            const suppliers = pageData.suppliers || [];

            if (suppliers.length === 0) {
                contentEl.innerHTML = `
                    <div class="empty-card">
                        <p><strong>No suppliers added yet.</strong></p>
                        <p>Add at least one supplier from the <a href="suppliers.php?id=${encodeURIComponent(rfpId)}">Suppliers page</a>, then score them to see comparisons here.</p>
                    </div>
                `;
                return;
            }

            const anyScored = suppliers.some(s => s.overall_avg !== null);
            if (!anyScored) {
                contentEl.innerHTML = `
                    <div class="empty-card">
                        <p><strong>No scores yet.</strong></p>
                        <p>Score at least one supplier from the <a href="suppliers.php?id=${encodeURIComponent(rfpId)}">Suppliers page</a> to start comparing.</p>
                    </div>
                `;
                return;
            }

            // Sort: scored suppliers descending by overall_avg first,
            // unscored suppliers last in their original order.
            const scored   = suppliers.filter(s => s.overall_avg !== null)
                                      .sort((a, b) => b.overall_avg - a.overall_avg);
            const unscored = suppliers.filter(s => s.overall_avg === null);
            const ranked   = scored.concat(unscored);

            // Assign colour by ORIGINAL supplier order (stable across renders);
            // the radar uses these too so card colour matches radar colour.
            const colourBySupplier = new Map();
            suppliers.forEach((s, i) => colourBySupplier.set(s.id, supplierColour(i)));

            contentEl.innerHTML =
                renderCards(ranked, colourBySupplier) +
                renderRadarSection() +
                renderWinnersSection(scored);

            renderRadar(ranked, colourBySupplier);
        }

        function renderCards(ranked, colourBySupplier) {
            return `
                <div class="compare-cards">
                    ${ranked.map((s, i) => renderCard(s, i, ranked.length, colourBySupplier.get(s.id))).join('')}
                </div>
            `;
        }

        function renderCard(s, idx, total, colour) {
            const scored = s.overall_avg !== null;
            // Only assign rank ribbons to scored suppliers, top 3.
            const rank = scored ? (idx + 1) : null;
            const rankClass = rank && rank <= 3 ? ('rank-' + rank) : '';
            const rankLabel = scored ? (rank === 1 ? '1st' : rank === 2 ? '2nd' : rank === 3 ? '3rd' : '#' + rank) : 'Not scored';
            const styleAccent = colour ? ('style="border-top-color:' + colour.border + ';"') : '';

            return `
                <div class="supplier-card ${rankClass}" ${rankClass ? '' : styleAccent}>
                    <span class="rank-pill">${escapeHtml(rankLabel)}</span>
                    <div class="name">${escapeHtml(s.display_name)}</div>
                    <div class="big-score">
                        ${scored ? s.overall_avg.toFixed(2) : '—'}
                        <span class="out-of">${scored ? '/ 5' : ''}</span>
                    </div>
                    <div class="meta">
                        <span>${s.scored_req_count} / ${s.total_req_count} reqs</span>
                        <span>${s.analyst_count} analyst${s.analyst_count === 1 ? '' : 's'}</span>
                    </div>
                </div>
            `;
        }

        function renderRadarSection() {
            return `
                <div class="section-card">
                    <div class="section-card-header"><h2>Per-category radar</h2></div>
                    <div class="section-card-body">
                        <div class="radar-wrap"><canvas id="radarCanvas"></canvas></div>
                    </div>
                </div>
            `;
        }

        function renderWinnersSection(scored) {
            if (scored.length < 2) {
                return `
                    <div class="section-card">
                        <div class="section-card-header"><h2>Category winners</h2></div>
                        <div class="section-card-body" style="color:#999;font-size:13px;text-align:center;padding:24px;">
                            Score at least two suppliers to see category winners.
                        </div>
                    </div>
                `;
            }

            const cats = pageData.categories.slice();
            // Append an "Uncategorised" pseudo-row if any supplier has data there.
            if (pageData.has_orphan_category) {
                cats.push({ id: 0, name: 'Uncategorised' });
            }

            const headers = scored.map(s =>
                '<th class="score-cell">' + escapeHtml(s.display_name) + '</th>'
            ).join('');

            const rows = cats.map(c => {
                const cells = scored.map(s => {
                    const cat = (s.by_category || []).find(x => x.category_id === c.id);
                    return cat;
                });
                // Find the winner across this row's cells (highest avg
                // that isn't null). If multiple suppliers tie within
                // 0.005 we mark them all as joint.
                const numeric = cells
                    .map((cell, i) => ({ idx: i, avg: cell ? cell.avg : null }))
                    .filter(x => x.avg !== null);
                let winnerSet = new Set();
                let leadAvg = null;
                let secondAvg = null;
                if (numeric.length > 0) {
                    numeric.sort((a, b) => b.avg - a.avg);
                    leadAvg = numeric[0].avg;
                    secondAvg = numeric.length > 1 ? numeric[1].avg : null;
                    numeric.forEach(x => {
                        if (Math.abs(x.avg - leadAvg) < 0.005) winnerSet.add(x.idx);
                    });
                }

                const cellHtml = cells.map((cell, i) => {
                    if (!cell || cell.avg === null) {
                        return '<td class="score-cell empty">—</td>';
                    }
                    const isWinner = winnerSet.has(i) && winnerSet.size === 1;
                    const isJoint  = winnerSet.has(i) && winnerSet.size > 1;
                    const cls = isWinner ? 'score-cell winner' : (isJoint ? 'score-cell winner' : 'score-cell');
                    return '<td class="' + cls + '">' + cell.avg.toFixed(2) + '</td>';
                }).join('');

                let winnerCol;
                if (numeric.length === 0) {
                    winnerCol = '<td class="score-cell empty">—</td>';
                } else if (winnerSet.size > 1) {
                    winnerCol = '<td class="winner-col tied">Tied</td>';
                } else {
                    const winnerIdx = [...winnerSet][0];
                    const winName = scored[winnerIdx].display_name;
                    const gap = secondAvg !== null ? (leadAvg - secondAvg) : null;
                    const gapStr = gap !== null ? ' <span class="gap">+' + gap.toFixed(2) + '</span>' : '';
                    winnerCol = '<td class="winner-col">' + escapeHtml(winName) + gapStr + '</td>';
                }

                return `
                    <tr>
                        <td class="cat-name">${escapeHtml(c.name)}</td>
                        ${cellHtml}
                        ${winnerCol}
                    </tr>
                `;
            }).join('');

            return `
                <div class="section-card">
                    <div class="section-card-header"><h2>Category winners</h2></div>
                    <div class="section-card-body" style="padding:0;">
                        <table class="winners-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    ${headers}
                                    <th>Winner</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rows}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        // ─── Radar chart ────────────────────────────────────────

        function renderRadar(ranked, colourBySupplier) {
            const labels = pageData.categories.map(c => c.name);
            const datasets = ranked.filter(s => s.overall_avg !== null).map(s => {
                const colour = colourBySupplier.get(s.id);
                const data = pageData.categories.map(c => {
                    const cat = (s.by_category || []).find(x => x.category_id === c.id);
                    return cat && cat.avg !== null ? cat.avg : 0;
                });
                return {
                    label: s.display_name,
                    data: data,
                    borderColor: colour.border,
                    backgroundColor: colour.fill,
                    borderWidth: 2,
                    pointBackgroundColor: colour.border,
                    pointRadius: 3,
                };
            });

            if (radarChart) { radarChart.destroy(); radarChart = null; }

            const canvas = document.getElementById('radarCanvas');
            radarChart = new Chart(canvas, {
                type: 'radar',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            min: 0, max: 5,
                            ticks: { stepSize: 1, color: '#666', backdropColor: 'transparent' },
                            angleLines: { color: '#ddd' },
                            grid: { color: '#eee' },
                            pointLabels: { font: { size: 12, family: 'Segoe UI, Tahoma, sans-serif' } }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { font: { family: 'Segoe UI, Tahoma, sans-serif' }, padding: 14 }
                        },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => ctx.dataset.label + ': ' + (ctx.raw === 0 ? '—' : ctx.raw.toFixed(2))
                            }
                        }
                    }
                }
            });
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
