<?php
/**
 * RFP Builder — single RFP overview.
 * Shows phase tiles for the planned workflow; later phases fill these in.
 */
session_start();
require_once '../../config.php';

$current_page = 'rfp-builder';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - RFP Builder</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .rfp-view-wrap { padding: 30px 40px; background: #f5f5f5; min-height: calc(100vh - 48px); box-sizing: border-box; }

        .breadcrumb { font-size: 13px; color: #888; margin-bottom: 8px; }
        .breadcrumb a { color: #666; text-decoration: none; }
        .breadcrumb a:hover { color: #f59e0b; }
        .breadcrumb span { margin: 0 6px; color: #ccc; }

        .rfp-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px;
        }
        .rfp-header h1 {
            margin: 0; font-size: 24px; font-weight: 700; color: #222;
            display: flex; align-items: center; gap: 12px;
        }
        .rfp-header .rfp-actions { display: flex; gap: 8px; }

        .status-badge {
            display: inline-block; padding: 4px 12px; border-radius: 12px;
            font-size: 13px; font-weight: 500; text-transform: capitalize;
        }
        .status-badge.draft        { background: #e5e7eb; color: #374151; }
        .status-badge.collecting   { background: #dbeafe; color: #1e40af; }
        .status-badge.consolidating { background: #fed7aa; color: #9a3412; }
        .status-badge.generating   { background: #ede9fe; color: #5b21b6; }
        .status-badge.scoring      { background: #ccfbf1; color: #115e59; }
        .status-badge.closed       { background: #d1fae5; color: #065f46; }
        .status-badge.abandoned    { background: #fee2e2; color: #991b1b; }

        .btn {
            padding: 8px 16px; font-size: 14px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: 1px solid transparent;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.15s;
        }
        .btn-primary { background: #f59e0b; color: white; }
        .btn-primary:hover { background: #d97706; }
        .btn-secondary { background: white; color: #333; border-color: #ddd; }
        .btn-secondary:hover { background: #f5f5f5; }
        .btn-danger { background: white; color: #ef4444; border-color: #fca5a5; }
        .btn-danger:hover { background: #fef2f2; }

        .phase-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }

        .phase-tile {
            background: white; border-radius: 10px; padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex; flex-direction: column; gap: 10px;
            border-left: 4px solid #e5e7eb;
        }
        .phase-tile.ready { border-left-color: #f59e0b; }
        .phase-tile.done  { border-left-color: #10b981; }

        .phase-tile-header {
            display: flex; align-items: center; gap: 10px;
        }
        .phase-tile-num {
            width: 28px; height: 28px; border-radius: 50%;
            background: #f3f4f6; color: #555;
            font-size: 13px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .phase-tile.ready .phase-tile-num { background: #fef3c7; color: #b45309; }
        .phase-tile.done  .phase-tile-num { background: #d1fae5; color: #065f46; }
        .phase-tile-title {
            font-size: 15px; font-weight: 600; color: #222;
        }
        .phase-tile-desc { font-size: 13px; color: #666; line-height: 1.5; flex-grow: 1; }
        .phase-tile-stats {
            display: flex; gap: 14px; font-size: 13px; color: #555;
            padding-top: 8px; border-top: 1px solid #f0f0f0;
        }
        .phase-tile-stats strong { color: #222; font-size: 16px; }
        .phase-tile-cta { margin-top: auto; }
        .phase-tile-cta .btn { width: 100%; justify-content: center; }
        .phase-tile-cta .placeholder {
            font-size: 12px; color: #999; font-style: italic; text-align: center;
            padding: 8px 0;
        }

        .meta-card {
            background: white; border-radius: 10px; padding: 20px 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .meta-card h2 {
            margin: 0 0 14px 0; font-size: 14px; font-weight: 600;
            color: #888; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .meta-row {
            display: flex; gap: 16px; padding: 8px 0;
            border-bottom: 1px solid #f5f5f5; font-size: 14px;
        }
        .meta-row:last-child { border-bottom: none; }
        .meta-row .meta-label { color: #888; min-width: 160px; }
        .meta-row .meta-value { color: #333; flex: 1; }

        .loading, .error-state { text-align: center; padding: 60px; color: #999; }
        .error-state { color: #d13438; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="rfp-view-wrap" id="viewWrap">
        <div class="loading" id="loadingEl">Loading...</div>
        <div id="contentEl" style="display:none;">
            <div class="breadcrumb">
                <a href="../">Contracts</a><span>›</span>
                <a href="./">RFP Builder</a><span>›</span>
                <span id="bcName">-</span>
            </div>

            <div class="rfp-header">
                <h1>
                    <span id="rfpName">-</span>
                    <span class="status-badge" id="rfpStatus">draft</span>
                </h1>
                <div class="rfp-actions">
                    <a href="./" class="btn btn-secondary">&larr; Back</a>
                    <button class="btn btn-secondary" onclick="editRfp()">Edit</button>
                    <button class="btn btn-danger" onclick="deleteRfp()">Delete</button>
                </div>
            </div>

            <div class="phase-grid" id="phaseGrid">
                <!-- Tiles rendered by JS -->
            </div>

            <div class="meta-card">
                <h2>Details</h2>
                <div class="meta-row"><div class="meta-label">Created by</div><div class="meta-value" id="metaCreatedBy">-</div></div>
                <div class="meta-row"><div class="meta-label">Created</div><div class="meta-value" id="metaCreatedAt">-</div></div>
                <div class="meta-row"><div class="meta-label">Last updated</div><div class="meta-value" id="metaUpdatedAt">-</div></div>
                <div class="meta-row"><div class="meta-label">Linked contract</div><div class="meta-value" id="metaContract">-</div></div>
                <div class="meta-row"><div class="meta-label">Chosen supplier</div><div class="meta-value" id="metaChosenSupplier">-</div></div>
                <div class="meta-row"><div class="meta-label">Style guide override</div><div class="meta-value" id="metaStyleGuide">-</div></div>
            </div>
        </div>
        <div class="error-state" id="errorEl" style="display:none;"></div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const rfpId = new URLSearchParams(location.search).get('id');
        let currentRfp = null;

        document.addEventListener('DOMContentLoaded', () => {
            if (!rfpId) {
                showError('No RFP id supplied. <a href="./">Back to list</a>.');
                return;
            }
            loadRfp();
        });

        async function loadRfp() {
            try {
                const res = await fetch(API_BASE + 'get_rfp.php?id=' + encodeURIComponent(rfpId));
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load');
                currentRfp = data.rfp;
                render(currentRfp);
            } catch (err) {
                showError(escapeHtml(err.message));
            }
        }

        function render(r) {
            document.getElementById('loadingEl').style.display = 'none';
            document.getElementById('contentEl').style.display = 'block';

            document.getElementById('bcName').textContent = r.name;
            document.getElementById('rfpName').textContent = r.name;
            const statusEl = document.getElementById('rfpStatus');
            statusEl.textContent = r.status;
            statusEl.className = 'status-badge ' + r.status;

            document.getElementById('metaCreatedBy').textContent = r.created_by_name || '-';
            document.getElementById('metaCreatedAt').textContent = formatDateTime(r.created_datetime);
            document.getElementById('metaUpdatedAt').textContent = formatDateTime(r.updated_datetime);
            document.getElementById('metaContract').textContent = r.contract_title || 'Not linked';
            document.getElementById('metaChosenSupplier').textContent = r.chosen_supplier_name || 'Not yet chosen';
            document.getElementById('metaStyleGuide').textContent = r.style_guide ? 'Custom override set' : 'Using system default';

            renderPhases(r);
        }

        function renderPhases(r) {
            const phases = [
                {
                    num: 1, title: 'Source documents', phase: 1,
                    desc: 'Upload .docx files from each contributing department.',
                    stats: [{label: 'Documents', value: r.document_count}],
                    ready: true,
                    cta: '',
                    href: 'documents.php?id=' + r.id
                },
                {
                    num: 2, title: 'Extracted requirements', phase: 2,
                    desc: 'AI extracts each requirement, pain point and challenge from every uploaded document.',
                    stats: [{label: 'Extracted', value: r.extracted_count}],
                    ready: r.extracted_count > 0,
                    cta: r.extracted_count > 0 ? '' : 'Run extraction from Documents first',
                    href: r.extracted_count > 0 ? 'extracted.php?id=' + r.id : null
                },
                {
                    num: 3, title: 'Consolidate &amp; resolve conflicts', phase: 3,
                    desc: 'AI deduplicates requirements across departments, suggests categories &amp; priorities, flags contradictions for you to resolve.',
                    stats: [
                        {label: 'Consolidated', value: r.consolidated_count},
                        {label: 'Locked', value: r.locked_count},
                        {label: 'Open conflicts', value: r.open_conflicts}
                    ],
                    ready: false,
                    cta: 'Coming in Phase 3',
                    href: null
                },
                {
                    num: 4, title: 'Generate RFP document', phase: 4,
                    desc: 'AI writes a coherent RFP section per category, ready to send to suppliers.',
                    stats: [
                        {label: 'Categories', value: r.category_count},
                        {label: 'Sections', value: r.section_count}
                    ],
                    ready: false,
                    cta: 'Coming in Phase 4',
                    href: null
                },
                {
                    num: 5, title: 'Suppliers &amp; scoring', phase: 5,
                    desc: 'Invite existing or prospective suppliers, then score their responses requirement-by-requirement.',
                    stats: [{label: 'Invited', value: r.supplier_count}],
                    ready: false,
                    cta: 'Coming in Phase 5',
                    href: null
                },
                {
                    num: 6, title: 'Compare &amp; decide', phase: 6,
                    desc: 'Cross-supplier radar &amp; category-winner table to drive the final decision.',
                    stats: [],
                    ready: false,
                    cta: 'Coming in Phase 6',
                    href: null
                }
            ];

            const grid = document.getElementById('phaseGrid');
            grid.innerHTML = phases.map(p => `
                <div class="phase-tile ${p.ready ? 'ready' : ''}">
                    <div class="phase-tile-header">
                        <div class="phase-tile-num">${p.num}</div>
                        <div class="phase-tile-title">${p.title}</div>
                    </div>
                    <div class="phase-tile-desc">${p.desc}</div>
                    ${p.stats.length ? `
                        <div class="phase-tile-stats">
                            ${p.stats.map(s => `<div><strong>${s.value}</strong> <span style="color:#888;">${s.label}</span></div>`).join('')}
                        </div>
                    ` : ''}
                    <div class="phase-tile-cta">
                        ${p.href
                            ? `<a class="btn btn-primary" href="${p.href}">Open</a>`
                            : `<div class="placeholder">${escapeHtml(p.cta)}</div>`}
                    </div>
                </div>
            `).join('');
        }

        function editRfp() {
            // Edit modal lives on the list page; bounce back with a hash so the list could open it.
            // For now, just go back — the list page has the edit modal.
            window.location.href = './?edit=' + currentRfp.id;
        }

        async function deleteRfp() {
            if (!confirm(`Delete RFP "${currentRfp.name}"?\n\nThis will permanently remove all documents, requirements, scores, and history for this RFP.`)) return;
            try {
                const res = await fetch(API_BASE + 'delete_rfp.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id: currentRfp.id})
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Delete failed');
                window.location.href = './';
            } catch (err) {
                alert('Delete failed: ' + err.message);
            }
        }

        function showError(html) {
            document.getElementById('loadingEl').style.display = 'none';
            const el = document.getElementById('errorEl');
            el.innerHTML = html;
            el.style.display = 'block';
        }

        function formatDateTime(s) {
            if (!s) return '-';
            const d = new Date(s.replace(' ', 'T'));
            if (isNaN(d)) return s;
            return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
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
