<?php
/**
 * System - Database Verification
 * Checks all tables and columns exist, creates any that are missing.
 */
session_start();
require_once '../../config.php';

$current_page = 'db-verify';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Database Verify</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; }

        .db-verify-container {
            max-width: 900px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .db-verify-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .db-verify-header h2 {
            margin: 0;
            font-size: 22px;
            color: #333;
        }

        .db-verify-header p {
            margin: 5px 0 0 0;
            font-size: 13px;
            color: #888;
        }

        .verify-btn {
            background: #546e7a;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .verify-btn:hover { background: #37474f; }
        .verify-btn:disabled { background: #999; cursor: not-allowed; }

        .results-summary {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-card {
            flex: 1;
            padding: 16px 20px;
            border-radius: 8px;
            text-align: center;
        }

        .summary-card .count {
            font-size: 28px;
            font-weight: 700;
            display: block;
        }

        .summary-card .label {
            font-size: 12px;
            text-transform: uppercase;
            margin-top: 4px;
            display: block;
        }

        .summary-ok { background: #d4edda; color: #155724; }
        .summary-created { background: #fff3cd; color: #856404; }
        .summary-updated { background: #cce5ff; color: #004085; }
        .summary-error { background: #f8d7da; color: #721c24; }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        }

        .results-table th {
            background: #f8f9fa;
            padding: 12px 16px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            color: #666;
            border-bottom: 2px solid #e0e0e0;
        }

        .results-table td {
            padding: 10px 16px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }

        .results-table tr:last-child td { border-bottom: none; }

        .status-pill {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-pill.ok { background: #d4edda; color: #155724; }
        .status-pill.created { background: #fff3cd; color: #856404; }
        .status-pill.updated { background: #cce5ff; color: #004085; }
        .status-pill.error { background: #f8d7da; color: #721c24; }

        .detail-text { font-size: 12px; color: #666; }

        .placeholder-msg {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            color: #888;
        }

        .placeholder-msg svg { color: #ccc; margin-bottom: 15px; }
        .placeholder-msg p { margin: 0; font-size: 14px; }

        .spinner-inline {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="db-verify-container">
        <div class="db-verify-header">
            <div>
                <h2>Database Verification</h2>
                <p>Check all tables and columns exist. Automatically creates any that are missing.</p>
            </div>
            <button class="verify-btn" id="verifyBtn" onclick="runVerification()">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                Run Verification
            </button>
        </div>

        <div id="summaryArea"></div>
        <div id="resultsArea">
            <div class="placeholder-msg">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                    <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                    <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
                </svg>
                <p>Click "Run Verification" to check your database schema</p>
            </div>
        </div>
    </div>

    <script>
        async function runVerification() {
            const btn = document.getElementById('verifyBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-inline"></span> Verifying...';

            document.getElementById('summaryArea').innerHTML = '';
            document.getElementById('resultsArea').innerHTML = '<div class="placeholder-msg"><p>Checking tables...</p></div>';

            try {
                const response = await fetch('../../api/system/db_verify.php');
                const data = await response.json();

                if (data.success) {
                    renderResults(data.results, data.total_tables);
                } else {
                    document.getElementById('resultsArea').innerHTML =
                        '<div class="placeholder-msg" style="color:#d13438;"><p>Error: ' + escapeHtml(data.error) + '</p></div>';
                }
            } catch (error) {
                document.getElementById('resultsArea').innerHTML =
                    '<div class="placeholder-msg" style="color:#d13438;"><p>Failed to connect: ' + escapeHtml(error.message) + '</p></div>';
            }

            btn.disabled = false;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Run Verification';
        }

        function renderResults(results, total) {
            const counts = { ok: 0, created: 0, updated: 0, error: 0 };
            results.forEach(r => counts[r.status] = (counts[r.status] || 0) + 1);

            let summaryHtml = '<div class="results-summary">';
            summaryHtml += `<div class="summary-card summary-ok"><span class="count">${counts.ok}</span><span class="label">OK</span></div>`;
            summaryHtml += `<div class="summary-card summary-created"><span class="count">${counts.created}</span><span class="label">Created</span></div>`;
            summaryHtml += `<div class="summary-card summary-updated"><span class="count">${counts.updated}</span><span class="label">Updated</span></div>`;
            summaryHtml += `<div class="summary-card summary-error"><span class="count">${counts.error}</span><span class="label">Errors</span></div>`;
            summaryHtml += '</div>';
            document.getElementById('summaryArea').innerHTML = summaryHtml;

            let tableHtml = '<table class="results-table"><thead><tr><th>Table</th><th>Status</th><th>Details</th></tr></thead><tbody>';
            results.forEach(r => {
                const statusLabel = r.status === 'ok' ? 'OK' : r.status.charAt(0).toUpperCase() + r.status.slice(1);
                const details = r.details.length > 0 ? r.details.join('; ') : '-';
                tableHtml += `<tr>
                    <td><strong>${escapeHtml(r.table)}</strong></td>
                    <td><span class="status-pill ${r.status}">${statusLabel}</span></td>
                    <td class="detail-text">${escapeHtml(details)}</td>
                </tr>`;
            });
            tableHtml += '</tbody></table>';
            document.getElementById('resultsArea').innerHTML = tableHtml;
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
