<?php
/**
 * Watchtower — Unified Attention Dashboard
 * Single pane of glass showing actionable items across all modules
 */
session_start();
require_once '../config.php';

$current_page = 'dashboard';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Watchtower</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        /* ── Watchtower Layout ──────────────────────────────────────────────── */
        .wt-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            background: #f0f2f5;
            padding: 24px;
        }
        .wt-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .wt-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
        }
        .wt-refresh-info {
            font-size: 12px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .wt-refresh-btn {
            background: none;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 12px;
            color: #64748b;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .wt-refresh-btn:hover { background: #f8fafc; border-color: #94a3b8; }
        .wt-refresh-btn.spinning svg { animation: wt-spin 0.8s linear infinite; }
        @keyframes wt-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        /* ── Card Grid ──────────────────────────────────────────────────────── */
        .wt-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        @media (max-width: 1100px) { .wt-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 700px) { .wt-grid { grid-template-columns: 1fr; } }

        /* ── Attention Card ─────────────────────────────────────────────────── */
        .wt-card {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            transition: box-shadow 0.15s;
        }
        .wt-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
        .wt-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px 10px;
            border-bottom: 1px solid #f1f5f9;
        }
        .wt-card-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .wt-card-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .wt-card-icon svg { width: 18px; height: 18px; stroke: #fff; stroke-width: 2; fill: none; }
        .wt-card-name {
            font-size: 14px;
            font-weight: 600;
            color: #334155;
        }
        .wt-card-name a {
            color: inherit;
            text-decoration: none;
        }
        .wt-card-name a:hover { text-decoration: underline; }
        .wt-status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .wt-status-dot.green  { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.2); }
        .wt-status-dot.amber  { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,0.2); }
        .wt-status-dot.red    { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.2); }

        .wt-card-body {
            padding: 12px 16px 16px;
            min-height: 80px;
        }
        .wt-card-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 80px;
            color: #94a3b8;
            font-size: 13px;
        }
        .wt-card-loading .wt-spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #e2e8f0;
            border-top-color: #64748b;
            border-radius: 50%;
            animation: wt-spin 0.6s linear infinite;
        }

        /* ── Metrics ────────────────────────────────────────────────────────── */
        .wt-metrics {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .wt-metric {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 52px;
        }
        .wt-metric-value {
            font-size: 22px;
            font-weight: 700;
            line-height: 1.1;
        }
        .wt-metric-label {
            font-size: 11px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 2px;
        }

        /* ── Attention Items ────────────────────────────────────────────────── */
        .wt-attention {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .wt-attention-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 13px;
            color: #334155;
        }
        .wt-attention-item.red    { background: #fef2f2; color: #991b1b; }
        .wt-attention-item.amber  { background: #fffbeb; color: #92400e; }
        .wt-attention-item.green  { background: #f0fdf4; color: #166534; }
        .wt-attention-item.blue   { background: #eff6ff; color: #1e40af; }
        .wt-attention-item.neutral { background: #f8fafc; color: #475569; }
        .wt-attention-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .wt-attention-item.red .wt-attention-dot    { background: #ef4444; }
        .wt-attention-item.amber .wt-attention-dot   { background: #f59e0b; }
        .wt-attention-item.green .wt-attention-dot   { background: #22c55e; }
        .wt-attention-item.blue .wt-attention-dot    { background: #3b82f6; }
        .wt-attention-item.neutral .wt-attention-dot { background: #94a3b8; }
        .wt-attention-bold { font-weight: 600; }

        /* ── Event list ─────────────────────────────────────────────────────── */
        .wt-event-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-top: 6px;
        }
        .wt-event {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 10px;
            background: #f8fafc;
            border-radius: 6px;
            font-size: 12px;
            color: #475569;
        }
        .wt-event-time {
            font-weight: 600;
            color: #334155;
            white-space: nowrap;
            min-width: 50px;
        }
        .wt-event-title {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ── Article list ───────────────────────────────────────────────────── */
        .wt-article-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-top: 6px;
        }
        .wt-article {
            padding: 5px 10px;
            background: #f8fafc;
            border-radius: 6px;
            font-size: 12px;
            color: #475569;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ── Service status items ───────────────────────────────────────────── */
        .wt-service-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            margin-top: 4px;
        }
        .wt-service-item .wt-impact-badge {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            white-space: nowrap;
        }
        .wt-impact-major    { background: #fef2f2; color: #991b1b; }
        .wt-impact-partial  { background: #fff7ed; color: #9a3412; }
        .wt-impact-degraded { background: #fffbeb; color: #92400e; }
        .wt-impact-maint    { background: #eff6ff; color: #1e40af; }

        /* ── All-clear banner ───────────────────────────────────────────────── */
        .wt-all-clear {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f0fdf4;
            border-radius: 6px;
            font-size: 13px;
            color: #166534;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wt-container">
        <div class="wt-top-bar">
            <div class="wt-title">Attention Overview</div>
            <div class="wt-refresh-info">
                <span id="wtLastRefresh"></span>
                <button class="wt-refresh-btn" id="wtRefreshBtn" onclick="loadDashboard()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                    </svg>
                    Refresh
                </button>
            </div>
        </div>

        <div class="wt-grid">
            <!-- Morning Checks -->
            <div class="wt-card" id="wtMorningChecks">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#00acc1;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        </div>
                        <div class="wt-card-name"><a href="../morning-checks/">Morning Checks</a></div>
                    </div>
                    <div class="wt-status-dot" id="wtMcDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Tickets -->
            <div class="wt-card" id="wtTickets">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#0078d4;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                        </div>
                        <div class="wt-card-name"><a href="../tickets/">Tickets</a></div>
                    </div>
                    <div class="wt-status-dot" id="wtTkDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Changes -->
            <div class="wt-card" id="wtChanges">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#00897b;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line></svg>
                        </div>
                        <div class="wt-card-name"><a href="../change-management/">Changes</a></div>
                    </div>
                    <div class="wt-status-dot" id="wtChDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Calendar -->
            <div class="wt-card" id="wtCalendar">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#ef6c00;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        </div>
                        <div class="wt-card-name"><a href="../calendar/">Calendar</a></div>
                    </div>
                    <div class="wt-status-dot" id="wtCalDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Service Status -->
            <div class="wt-card" id="wtServiceStatus">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#10b981;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                        </div>
                        <div class="wt-card-name"><a href="../service-status/">Service Status</a></div>
                    </div>
                    <div class="wt-status-dot" id="wtSsDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Contracts -->
            <div class="wt-card" id="wtContracts">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#f59e0b;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="12" y1="9" x2="8" y2="9"></line></svg>
                        </div>
                        <div class="wt-card-name"><a href="../contracts/">Contracts</a></div>
                    </div>
                    <div class="wt-status-dot" id="wtCtDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Knowledge -->
            <div class="wt-card" id="wtKnowledge">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#8764b8;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                        </div>
                        <div class="wt-card-name"><a href="../knowledge/">Knowledge</a></div>
                    </div>
                    <div class="wt-status-dot" id="wtKbDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Assets -->
            <div class="wt-card" id="wtAssets">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#107c10;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                        </div>
                        <div class="wt-card-name"><a href="../asset-management/">Assets</a></div>
                    </div>
                    <div class="wt-status-dot" id="wtAsDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>
        </div>
    </div>

    <script>
    let refreshTimer = null;

    function setDot(id, color) {
        const dot = document.getElementById(id);
        if (dot) { dot.className = 'wt-status-dot ' + color; }
    }

    function setBody(cardId, html) {
        const card = document.getElementById(cardId);
        if (card) { card.querySelector('.wt-card-body').innerHTML = html; }
    }

    function formatTime(dt) {
        if (!dt) return '';
        const d = new Date(dt);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function attentionItem(level, text) {
        return `<div class="wt-attention-item ${level}"><div class="wt-attention-dot"></div><span>${text}</span></div>`;
    }

    function metric(value, label, color) {
        return `<div class="wt-metric"><div class="wt-metric-value" style="color:${color || '#334155'}">${value}</div><div class="wt-metric-label">${label}</div></div>`;
    }

    function renderMorningChecks(d) {
        const mc = d.morning_checks;
        let html = '';

        // Status dot logic
        if (mc.not_started) {
            setDot('wtMcDot', 'red');
        } else if ((mc.statuses['Fail'] || 0) > 0) {
            setDot('wtMcDot', 'red');
        } else if ((mc.statuses['Warning'] || 0) > 0) {
            setDot('wtMcDot', 'amber');
        } else if (mc.completed_today >= mc.total_checks && mc.total_checks > 0) {
            setDot('wtMcDot', 'green');
        } else {
            setDot('wtMcDot', 'amber');
        }

        html += '<div class="wt-metrics">';
        html += metric(mc.completed_today + '/' + mc.total_checks, 'Done', mc.completed_today >= mc.total_checks ? '#22c55e' : '#f59e0b');
        html += metric(mc.statuses['OK'] || 0, 'OK', '#22c55e');
        html += metric(mc.statuses['Warning'] || 0, 'Warn', (mc.statuses['Warning'] || 0) > 0 ? '#f59e0b' : '#94a3b8');
        html += metric(mc.statuses['Fail'] || 0, 'Fail', (mc.statuses['Fail'] || 0) > 0 ? '#ef4444' : '#94a3b8');
        html += '</div>';

        html += '<div class="wt-attention">';
        if (mc.not_started) {
            html += attentionItem('red', '<span class="wt-attention-bold">Checks not started today</span>');
        } else if (mc.completed_today < mc.total_checks) {
            html += attentionItem('amber', (mc.total_checks - mc.completed_today) + ' checks still pending');
        }
        if ((mc.statuses['Fail'] || 0) > 0) {
            html += attentionItem('red', mc.statuses['Fail'] + ' check(s) failed');
        }
        if ((mc.statuses['Warning'] || 0) > 0) {
            html += attentionItem('amber', mc.statuses['Warning'] + ' check(s) with warnings');
        }
        if (mc.completed_today >= mc.total_checks && mc.total_checks > 0 && !(mc.statuses['Fail'] || 0) && !(mc.statuses['Warning'] || 0)) {
            html += attentionItem('green', 'All checks completed and passing');
        }
        html += '</div>';

        setBody('wtMorningChecks', html);
    }

    function renderTickets(d) {
        const tk = d.tickets;
        const totalOpen = tk.open + tk.in_progress + tk.on_hold;

        if (tk.urgent_high > 0 || tk.unassigned > 0) {
            setDot('wtTkDot', tk.urgent_high > 0 ? 'red' : 'amber');
        } else {
            setDot('wtTkDot', 'green');
        }

        let html = '<div class="wt-metrics">';
        html += metric(totalOpen, 'Open', '#334155');
        html += metric(tk.open, 'New', '#3b82f6');
        html += metric(tk.in_progress, 'Active', '#f59e0b');
        html += metric(tk.on_hold, 'Hold', '#94a3b8');
        html += '</div>';

        html += '<div class="wt-attention">';
        if (tk.urgent_high > 0) {
            html += attentionItem('red', '<span class="wt-attention-bold">' + tk.urgent_high + '</span> urgent/high priority tickets');
        }
        if (tk.unassigned > 0) {
            html += attentionItem('amber', '<span class="wt-attention-bold">' + tk.unassigned + '</span> unassigned tickets');
        }
        if (tk.urgent_high === 0 && tk.unassigned === 0) {
            html += attentionItem('green', 'No urgent items');
        }
        html += '</div>';

        setBody('wtTickets', html);
    }

    function renderChanges(d) {
        const ch = d.changes;

        if (ch.unapproved > 0) {
            setDot('wtChDot', 'amber');
        } else {
            setDot('wtChDot', 'green');
        }

        let html = '<div class="wt-metrics">';
        html += metric(ch.upcoming_7d, 'Next 7d', '#334155');
        html += metric(ch.in_progress_today, 'Active', ch.in_progress_today > 0 ? '#f59e0b' : '#94a3b8');
        html += metric(ch.unapproved, 'Pending', ch.unapproved > 0 ? '#ef4444' : '#94a3b8');
        html += '</div>';

        html += '<div class="wt-attention">';
        if (ch.unapproved > 0) {
            html += attentionItem('amber', '<span class="wt-attention-bold">' + ch.unapproved + '</span> change(s) awaiting approval');
        }
        if (ch.in_progress_today > 0) {
            html += attentionItem('blue', ch.in_progress_today + ' change(s) in progress now');
        }
        if (ch.upcoming_7d > 0) {
            html += attentionItem('neutral', ch.upcoming_7d + ' change(s) scheduled this week');
        }
        if (ch.unapproved === 0 && ch.in_progress_today === 0 && ch.upcoming_7d === 0) {
            html += attentionItem('green', 'No upcoming changes');
        }
        html += '</div>';

        setBody('wtChanges', html);
    }

    function renderCalendar(d) {
        const cal = d.calendar;

        setDot('wtCalDot', cal.today_count > 0 ? 'amber' : 'green');

        let html = '<div class="wt-metrics">';
        html += metric(cal.today_count, 'Today', cal.today_count > 0 ? '#ef6c00' : '#94a3b8');
        html += metric(cal.week_count, 'This week', '#334155');
        html += '</div>';

        if (cal.today_events && cal.today_events.length > 0) {
            html += '<div class="wt-event-list">';
            cal.today_events.forEach(function(ev) {
                const time = ev.all_day == 1 ? 'All day' : formatTime(ev.start_datetime);
                html += `<div class="wt-event"><span class="wt-event-time">${time}</span><span class="wt-event-title">${ev.title}</span></div>`;
            });
            html += '</div>';
        } else {
            html += '<div class="wt-attention">' + attentionItem('green', 'No events today') + '</div>';
        }

        setBody('wtCalendar', html);
    }

    function renderServiceStatus(d) {
        const ss = d.service_status;

        if (ss.all_operational) {
            setDot('wtSsDot', 'green');
        } else {
            const hasMajor = ss.degraded_services.some(s => s.current_status === 'Major Outage' || s.current_status === 'Partial Outage');
            setDot('wtSsDot', hasMajor ? 'red' : 'amber');
        }

        let html = '';
        if (ss.all_operational) {
            html += '<div class="wt-all-clear"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>All systems operational</div>';
        } else {
            html += '<div class="wt-attention">';
            if (ss.active_incidents > 0) {
                html += attentionItem('red', '<span class="wt-attention-bold">' + ss.active_incidents + '</span> active incident(s)');
            }
            html += '</div>';

            ss.degraded_services.forEach(function(svc) {
                let impactClass = 'wt-impact-degraded';
                if (svc.current_status === 'Major Outage') impactClass = 'wt-impact-major';
                else if (svc.current_status === 'Partial Outage') impactClass = 'wt-impact-partial';
                else if (svc.current_status === 'Maintenance') impactClass = 'wt-impact-maint';
                html += `<div class="wt-service-item"><span>${svc.name}</span><span class="wt-impact-badge ${impactClass}">${svc.current_status}</span></div>`;
            });
        }

        setBody('wtServiceStatus', html);
    }

    function renderContracts(d) {
        const ct = d.contracts;

        if (ct.expiring_30d > 0) {
            setDot('wtCtDot', 'red');
        } else if (ct.expiring_90d > 0 || ct.notice_periods_30d > 0) {
            setDot('wtCtDot', 'amber');
        } else {
            setDot('wtCtDot', 'green');
        }

        let html = '<div class="wt-metrics">';
        html += metric(ct.expiring_30d, '30 days', ct.expiring_30d > 0 ? '#ef4444' : '#94a3b8');
        html += metric(ct.expiring_90d, '90 days', ct.expiring_90d > 0 ? '#f59e0b' : '#94a3b8');
        html += metric(ct.notice_periods_30d, 'Notices', ct.notice_periods_30d > 0 ? '#f59e0b' : '#94a3b8');
        html += '</div>';

        html += '<div class="wt-attention">';
        if (ct.expiring_30d > 0) {
            html += attentionItem('red', '<span class="wt-attention-bold">' + ct.expiring_30d + '</span> contract(s) expiring within 30 days');
        }
        if (ct.notice_periods_30d > 0) {
            html += attentionItem('amber', '<span class="wt-attention-bold">' + ct.notice_periods_30d + '</span> notice period(s) approaching');
        }
        if (ct.expiring_30d === 0 && ct.expiring_90d === 0 && ct.notice_periods_30d === 0) {
            html += attentionItem('green', 'No contracts requiring attention');
        }
        html += '</div>';

        setBody('wtContracts', html);
    }

    function renderKnowledge(d) {
        const kb = d.knowledge;

        if (kb.overdue_reviews > 0) {
            setDot('wtKbDot', 'amber');
        } else {
            setDot('wtKbDot', 'green');
        }

        let html = '<div class="wt-attention">';
        if (kb.overdue_reviews > 0) {
            html += attentionItem('amber', '<span class="wt-attention-bold">' + kb.overdue_reviews + '</span> article(s) overdue for review');
        }
        html += '</div>';

        if (kb.recent_articles && kb.recent_articles.length > 0) {
            html += '<div style="font-size:11px;color:#94a3b8;margin-top:8px;text-transform:uppercase;letter-spacing:0.3px;">Published this week</div>';
            html += '<div class="wt-article-list">';
            kb.recent_articles.forEach(function(art) {
                html += `<div class="wt-article">${art.title}</div>`;
            });
            html += '</div>';
        } else {
            if (kb.overdue_reviews === 0) {
                html += '<div class="wt-attention">' + attentionItem('green', 'Knowledge base up to date') + '</div>';
            }
        }

        setBody('wtKnowledge', html);
    }

    function renderAssets(d) {
        const as = d.assets;

        if (as.not_seen_7d > 0) {
            setDot('wtAsDot', 'amber');
        } else {
            setDot('wtAsDot', 'green');
        }

        let html = '<div class="wt-metrics">';
        html += metric(as.total, 'Total', '#334155');
        html += metric(as.not_seen_7d, 'Offline', as.not_seen_7d > 0 ? '#f59e0b' : '#94a3b8');
        html += '</div>';

        html += '<div class="wt-attention">';
        if (as.not_seen_7d > 0) {
            html += attentionItem('amber', '<span class="wt-attention-bold">' + as.not_seen_7d + '</span> asset(s) not seen in 7+ days');
        } else {
            html += attentionItem('green', 'All assets recently active');
        }
        html += '</div>';

        setBody('wtAssets', html);
    }

    function loadDashboard() {
        const btn = document.getElementById('wtRefreshBtn');
        btn.classList.add('spinning');

        fetch('../api/watchtower/get_dashboard.php')
            .then(r => r.json())
            .then(d => {
                if (!d.success) {
                    console.error('Watchtower API error:', d.error);
                    return;
                }

                renderMorningChecks(d);
                renderTickets(d);
                renderChanges(d);
                renderCalendar(d);
                renderServiceStatus(d);
                renderContracts(d);
                renderKnowledge(d);
                renderAssets(d);

                // Update timestamp
                const now = new Date();
                document.getElementById('wtLastRefresh').textContent =
                    'Updated ' + now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            })
            .catch(err => {
                console.error('Watchtower fetch error:', err);
            })
            .finally(() => {
                btn.classList.remove('spinning');
            });
    }

    // Initial load
    loadDashboard();

    // Auto-refresh every 5 minutes
    refreshTimer = setInterval(loadDashboard, 5 * 60 * 1000);
    </script>
</body>
</html>
