<?php
/**
 * RFP Builder — full AI audit trail (Phase 6 step 6b).
 *
 * Surfaces the entire rfp_processing_log for one RFP in a filterable
 * table — every AI call ever made for this RFP, with action, status,
 * target document/section, tokens (in/out/cached), call duration,
 * and the JSON details payload expanded for inspection. Companion
 * to the AI activity panel on the overview, which only shows the
 * last 25 entries.
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
    <title>Service Desk - AI audit trail</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
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

        .stats-strip {
            display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px;
            margin-bottom: 18px;
        }
        .stat-card {
            background: white; border-radius: 8px; padding: 12px 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border-left: 4px solid #ddd;
        }
        .stat-card.runs   { border-left-color: #6b7280; }
        .stat-card.ok     { border-left-color: #10b981; }
        .stat-card.err    { border-left-color: #ef4444; }
        .stat-card.tin    { border-left-color: #3b82f6; }
        .stat-card.tout   { border-left-color: #8b5cf6; }
        .stat-card.cache  { border-left-color: #f59e0b; }
        .stat-card .stat-value { font-size: 18px; font-weight: 700; color: #222; line-height: 1; font-variant-numeric: tabular-nums; }
        .stat-card .stat-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.4px; margin-top: 5px; }

        .filter-card {
            background: white; border-radius: 10px; padding: 12px 18px;
            margin-bottom: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            display: flex; gap: 14px; align-items: end; flex-wrap: wrap;
        }
        .filter-card .field { display: flex; flex-direction: column; }
        .filter-card label {
            font-size: 11px; font-weight: 600; color: #555;
            margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .filter-card select, .filter-card input[type="text"] {
            padding: 6px 10px; font-size: 13px;
            border: 1px solid #d1d5db; border-radius: 5px;
            font-family: inherit; min-width: 160px;
        }
        .filter-card .clear-link {
            font-size: 13px; color: #888; cursor: pointer;
            padding: 8px 0;
        }
        .filter-card .clear-link:hover { color: #f59e0b; }
        .filter-card .row-count {
            font-size: 12px; color: #666;
            margin-left: auto;
        }

        .audit-card {
            background: white; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
        }
        .audit-table {
            width: 100%; border-collapse: collapse; font-size: 13px;
        }
        .audit-table thead th {
            text-align: left; padding: 10px 14px; font-weight: 600;
            color: #888; border-bottom: 1px solid #eee;
            text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px;
            background: #fafbfc;
            position: sticky; top: 0;
        }
        .audit-table tbody td {
            padding: 9px 14px; border-bottom: 1px solid #f5f5f5;
            color: #333; vertical-align: top;
        }
        .audit-table tbody tr:last-child td { border-bottom: none; }
        .audit-table .num { text-align: right; font-variant-numeric: tabular-nums; }
        .audit-table .target em { color: #999; font-style: normal; }
        .audit-table .err {
            color: #b91c1c; font-size: 12px; margin-top: 3px;
            white-space: normal; overflow-wrap: anywhere;
        }
        .status-pill {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .status-pill.success { background: #d1fae5; color: #065f46; }
        .status-pill.error   { background: #fee2e2; color: #991b1b; }
        .action-pill {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            background: #e5e7eb; color: #374151;
            font-size: 11px; font-weight: 500;
        }
        .empty-state {
            padding: 40px; text-align: center; color: #999; font-size: 13px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="../">Contracts</a><span class="sep">›</span>
            <a href="./">RFP Builder</a><span class="sep">›</span>
            <a id="bcRfp" href="#">-</a><span class="sep">›</span>
            <span>Audit trail</span>
        </div>

        <div class="page-header">
            <h1>AI audit trail</h1>
            <div class="page-actions">
                <a id="backLink" href="#" class="btn btn-secondary">&larr; Overview</a>
            </div>
        </div>

        <div class="stats-strip" id="statsStrip" style="display:none;">
            <div class="stat-card runs">
                <div class="stat-value" id="statRuns">0</div>
                <div class="stat-label">AI runs</div>
            </div>
            <div class="stat-card ok">
                <div class="stat-value" id="statOk">0</div>
                <div class="stat-label">Successful</div>
            </div>
            <div class="stat-card err">
                <div class="stat-value" id="statErr">0</div>
                <div class="stat-label">Errors</div>
            </div>
            <div class="stat-card tin">
                <div class="stat-value" id="statTin">0</div>
                <div class="stat-label">Total input tokens</div>
            </div>
            <div class="stat-card tout">
                <div class="stat-value" id="statTout">0</div>
                <div class="stat-label">Total output tokens</div>
            </div>
            <div class="stat-card cache">
                <div class="stat-value" id="statCache">0</div>
                <div class="stat-label">Cached input</div>
            </div>
        </div>

        <div class="filter-card" id="filterCard" style="display:none;">
            <div class="field">
                <label for="fAction">Action</label>
                <select id="fAction" onchange="applyFilters()">
                    <option value="">All</option>
                </select>
            </div>
            <div class="field">
                <label for="fStatus">Status</label>
                <select id="fStatus" onchange="applyFilters()">
                    <option value="">All</option>
                    <option value="success">success</option>
                    <option value="error">error</option>
                </select>
            </div>
            <div class="field">
                <label for="fTarget">Target contains</label>
                <input type="text" id="fTarget" oninput="applyFilters()" placeholder="filename or title">
            </div>
            <span class="clear-link" onclick="clearFilters()">Clear filters</span>
            <span class="row-count" id="rowCount"></span>
        </div>

        <div id="loadingEl" class="empty-state">Loading…</div>
        <div id="contentEl" style="display:none;"></div>
        <div id="errorEl" class="empty-state" style="display:none; color:#d13438;"></div>
    </div>

    <script>
        const API_BASE = '../../api/rfp-builder/';
        const rfpId = new URLSearchParams(location.search).get('id');
        let allEntries = [];

        document.addEventListener('DOMContentLoaded', () => {
            if (!rfpId) {
                showError('No RFP id supplied. <a href="./">Back to list</a>.');
                return;
            }
            document.getElementById('backLink').href = 'view.php?id=' + encodeURIComponent(rfpId);
            loadAll();
        });

        async function loadAll() {
            try {
                const [rfpRes, logRes] = await Promise.all([
                    fetch(API_BASE + 'get_rfp.php?id=' + encodeURIComponent(rfpId)).then(r => r.json()),
                    // limit=0 → server treats as "give me everything" (clamped to 2000)
                    fetch(API_BASE + 'get_processing_log.php?rfp_id=' + encodeURIComponent(rfpId) + '&limit=0').then(r => r.json())
                ]);
                if (!rfpRes.success) throw new Error(rfpRes.error || 'Failed to load RFP');
                if (!logRes.success) throw new Error(logRes.error || 'Failed to load audit log');

                const bc = document.getElementById('bcRfp');
                bc.textContent = rfpRes.rfp.name;
                bc.href = 'view.php?id=' + encodeURIComponent(rfpId);

                allEntries = logRes.entries || [];
                renderStats(logRes.totals || {}, allEntries);
                buildActionFilter(allEntries);
                document.getElementById('loadingEl').style.display = 'none';
                document.getElementById('filterCard').style.display = 'flex';
                document.getElementById('statsStrip').style.display = 'grid';
                applyFilters();
            } catch (err) {
                showError(escapeHtml(err.message));
            }
        }

        function renderStats(totals, entries) {
            // Cache total in the entries we got (which is everything).
            let cacheRead = 0;
            entries.forEach(e => {
                if (e.cache_read != null) cacheRead += e.cache_read;
            });
            document.getElementById('statRuns').textContent  = totals.run_count       || entries.length;
            document.getElementById('statOk').textContent    = totals.success_count   || entries.filter(e => e.status === 'success').length;
            document.getElementById('statErr').textContent   = totals.error_count     || entries.filter(e => e.status === 'error').length;
            document.getElementById('statTin').textContent   = formatNum(totals.total_tokens_in  || 0);
            document.getElementById('statTout').textContent  = formatNum(totals.total_tokens_out || 0);
            document.getElementById('statCache').textContent = formatNum(cacheRead);
        }

        function buildActionFilter(entries) {
            const sel = document.getElementById('fAction');
            const seen = new Set();
            entries.forEach(e => seen.add(e.action));
            const sorted = [...seen].sort();
            sel.innerHTML = '<option value="">All</option>' +
                sorted.map(a => `<option value="${escapeHtml(a)}">${escapeHtml(a)}</option>`).join('');
        }

        function applyFilters() {
            const fa = document.getElementById('fAction').value;
            const fs = document.getElementById('fStatus').value;
            const ft = document.getElementById('fTarget').value.trim().toLowerCase();
            const filtered = allEntries.filter(e => {
                if (fa && e.action !== fa) return false;
                if (fs && e.status !== fs) return false;
                if (ft) {
                    const target = (e.document_filename || '') + ' ' + (e.section_title || '');
                    if (!target.toLowerCase().includes(ft)) return false;
                }
                return true;
            });
            document.getElementById('rowCount').textContent =
                filtered.length + ' of ' + allEntries.length + ' rows';
            renderTable(filtered);
        }

        function clearFilters() {
            document.getElementById('fAction').value = '';
            document.getElementById('fStatus').value = '';
            document.getElementById('fTarget').value = '';
            applyFilters();
        }

        function renderTable(entries) {
            const contentEl = document.getElementById('contentEl');
            contentEl.style.display = 'block';
            if (entries.length === 0) {
                contentEl.innerHTML = '<div class="audit-card"><div class="empty-state">No entries match the current filters.</div></div>';
                return;
            }
            contentEl.innerHTML = `
                <div class="audit-card">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Action</th>
                                <th>Target</th>
                                <th>Status</th>
                                <th class="num">In</th>
                                <th class="num">Out</th>
                                <th class="num">Cached</th>
                                <th class="num">Time</th>
                                <th>Model</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${entries.map(renderRow).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }

        function renderRow(e) {
            const target = e.document_filename
                ? escapeHtml(e.document_filename)
                : (e.section_title ? escapeHtml(e.section_title) : '<em>—</em>');
            const errLine = e.error
                ? `<div class="err">${escapeHtml(truncate(e.error, 280))}</div>`
                : '';
            const dur = e.duration_ms != null
                ? (e.duration_ms >= 1000 ? (e.duration_ms / 1000).toFixed(1) + 's' : e.duration_ms + 'ms')
                : '—';
            return `
                <tr>
                    <td>${escapeHtml(formatDateTime(e.created_datetime))}</td>
                    <td><span class="action-pill">${escapeHtml(e.action)}</span></td>
                    <td class="target">${target}${errLine}</td>
                    <td><span class="status-pill ${escapeHtml(e.status)}">${escapeHtml(e.status)}</span></td>
                    <td class="num">${e.tokens_in  != null ? formatTokens(e.tokens_in)  : '—'}</td>
                    <td class="num">${e.tokens_out != null ? formatTokens(e.tokens_out) : '—'}</td>
                    <td class="num">${e.cache_read != null ? formatTokens(e.cache_read) : '—'}</td>
                    <td class="num">${dur}</td>
                    <td>${e.model ? escapeHtml(e.model) : '—'}</td>
                </tr>
            `;
        }

        function formatNum(n) { return Number(n || 0).toLocaleString(); }
        function formatTokens(n) {
            n = Number(n) || 0;
            if (n >= 10000) return Math.round(n / 1000) + 'k';
            if (n >= 1000)  return (n / 1000).toFixed(1) + 'k';
            return n.toString();
        }
        function truncate(s, n) {
            if (!s) return '';
            return s.length > n ? s.slice(0, n) + '…' : s;
        }
        function formatDateTime(s) {
            if (!s) return '—';
            const d = new Date(s.replace(' ', 'T'));
            if (isNaN(d)) return s;
            return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
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
