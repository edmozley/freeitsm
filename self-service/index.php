<?php
/**
 * Self-Service Portal - Dashboard
 * Shows ticket summary, recent tickets, and system status
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
I18n::initFromSession();
require_once 'includes/auth.php';

$translationNamespaces = ['common', 'self-service'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('self-service.dashboard.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; background: #f5f5f5; }

        /* Portal Header */
        .portal-header {
            background: #0078d4;
            color: white;
            padding: 0 24px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .portal-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            font-size: 15px;
        }
        .portal-brand img { height: 28px; filter: brightness(0) invert(1); }
        .portal-nav {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .portal-nav a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s;
        }
        .portal-nav a:hover { background: rgba(255,255,255,0.15); color: white; }
        .portal-nav a.active { background: rgba(255,255,255,0.2); color: white; }
        .portal-user {
            display: flex;
            align-items: center;
            position: relative;
        }

        /* Layout */
        .portal-layout {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 24px;
        }

        .welcome-section {
            margin-bottom: 24px;
        }
        .welcome-section h1 {
            font-size: 22px;
            font-weight: 600;
            color: #333;
            margin: 0 0 4px 0;
        }
        .welcome-section p {
            color: #888;
            font-size: 14px;
            margin: 0;
        }

        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        .summary-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid #e5e7eb;
            transition: box-shadow 0.2s;
        }
        .summary-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .summary-card .card-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .summary-card .card-value {
            font-size: 32px;
            font-weight: 700;
            line-height: 1;
        }
        .card-open .card-label { color: #1e40af; }
        .card-open .card-value { color: #1e40af; }
        .card-open { border-top: 3px solid #3b82f6; }
        .card-progress .card-label { color: #c2410c; }
        .card-progress .card-value { color: #c2410c; }
        .card-progress { border-top: 3px solid #f97316; }
        .card-hold .card-label { color: #92400e; }
        .card-hold .card-value { color: #92400e; }
        .card-hold { border-top: 3px solid #f59e0b; }
        .card-total .card-label { color: #555; }
        .card-total .card-value { color: #333; }
        .card-total { border-top: 3px solid #6b7280; }

        /* Two column grid */
        .portal-grid {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 24px;
            align-items: start;
        }

        .portal-section {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        .section-header h2 {
            font-size: 15px;
            font-weight: 600;
            color: #333;
            margin: 0;
        }
        .section-header a {
            font-size: 12px;
            color: #0078d4;
            text-decoration: none;
            font-weight: 500;
        }
        .section-header a:hover { text-decoration: underline; }

        /* Tickets Table */
        .ticket-table {
            width: 100%;
            border-collapse: collapse;
        }
        .ticket-table th {
            background: #f9fafb;
            padding: 10px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e5e7eb;
        }
        .ticket-table td {
            padding: 12px 16px;
            font-size: 13px;
            color: #333;
            border-bottom: 1px solid #f3f4f6;
        }
        .ticket-table tr:last-child td { border-bottom: none; }
        .ticket-table tr:hover { background: #f8fafc; }
        .ticket-table .ticket-link {
            color: #0078d4;
            text-decoration: none;
            font-weight: 500;
        }
        .ticket-table .ticket-link:hover { text-decoration: underline; }
        .ticket-number {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 12px;
            color: #888;
        }

        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .status-open { background: #dbeafe; color: #1e40af; }
        .status-in-progress { background: #fff7ed; color: #c2410c; }
        .status-on-hold { background: #fef3c7; color: #92400e; }
        .status-closed { background: #d1fae5; color: #065f46; }

        /* Priority badges */
        .priority-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 500;
        }
        .priority-high { background: #fee2e2; color: #991b1b; }
        .priority-normal { background: #f3f4f6; color: #6b7280; }
        .priority-low { background: #e0e7ff; color: #3730a3; }

        /* Service Status Panel */
        .service-list { padding: 8px 0; }
        .service-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            border-bottom: 1px solid #f3f4f6;
        }
        .service-item:last-child { border-bottom: none; }
        .service-item .svc-name {
            font-size: 13px;
            font-weight: 500;
            color: #333;
        }
        .impact-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .impact-operational { background: #d1fae5; color: #065f46; }
        .impact-degraded { background: #fff7ed; color: #c2410c; }
        .impact-partial-outage { background: #fff1f2; color: #be123c; }
        .impact-major-outage { background: #fee2e2; color: #991b1b; }
        .impact-maintenance { background: #dbeafe; color: #1e40af; }

        /* Overall status banner */
        .all-operational {
            text-align: center;
            padding: 16px 20px;
            color: #065f46;
            font-size: 13px;
            font-weight: 600;
        }
        .all-operational svg { vertical-align: middle; margin-right: 6px; }

        /* Empty & loading states */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
            font-size: 14px;
        }
        .loading-state {
            text-align: center;
            padding: 30px 20px;
            color: #999;
            font-size: 13px;
        }

        .ticket-date {
            font-size: 12px;
            color: #999;
            white-space: nowrap;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .portal-grid { grid-template-columns: 1fr; }
            .summary-cards { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 500px) {
            .summary-cards { grid-template-columns: 1fr; }
            .portal-nav a span { display: none; }
        }
    </style>
</head>
<body>
    <div class="portal-header">
        <div class="portal-brand">
            <img src="../assets/images/CompanyLogo.png" alt="Logo">
            <span><?php echo htmlspecialchars(t('self-service.portal')); ?></span>
        </div>
        <nav class="portal-nav">
            <a href="index.php" class="active"><?php echo htmlspecialchars(t('self-service.nav.dashboard')); ?></a>
            <a href="new-ticket.php"><?php echo htmlspecialchars(t('self-service.nav.new_ticket')); ?></a>
            <a href="help.php"><?php echo htmlspecialchars(t('self-service.nav.help')); ?></a>
        </nav>
        <?php include 'includes/user-menu.php'; ?>
    </div>

    <div class="portal-layout">
        <div class="welcome-section">
            <h1><?php echo htmlspecialchars(t('self-service.dashboard.welcome', ['name' => $ss_user_name])); ?></h1>
            <p><?php echo htmlspecialchars(t('self-service.dashboard.welcome_sub')); ?></p>
        </div>

        <!-- Summary Cards (rendered dynamically from active ticket_statuses) -->
        <div class="summary-cards" id="summaryCards"></div>

        <!-- Two column layout -->
        <div class="portal-grid">
            <!-- Recent Tickets -->
            <div class="portal-section">
                <div class="section-header">
                    <h2><?php echo htmlspecialchars(t('self-service.dashboard.recent_tickets')); ?></h2>
                </div>
                <div id="ticketsContainer">
                    <div class="loading-state"><?php echo htmlspecialchars(t('self-service.dashboard.loading_tickets')); ?></div>
                </div>
            </div>

            <!-- System Status -->
            <div class="portal-section">
                <div class="section-header">
                    <h2><?php echo htmlspecialchars(t('self-service.dashboard.system_status')); ?></h2>
                </div>
                <div id="statusContainer">
                    <div class="loading-state"><?php echo htmlspecialchars(t('self-service.dashboard.loading_status')); ?></div>
                </div>
            </div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script>
        const API_BASE = '../api/self-service/';

        document.addEventListener('DOMContentLoaded', loadDashboard);

        async function loadDashboard() {
            try {
                const resp = await fetch(API_BASE + 'get_dashboard.php');
                const data = await resp.json();

                if (!data.success) {
                    if (data.error === 'Not authenticated') {
                        window.location.href = 'login.php';
                        return;
                    }
                    console.error('Dashboard error:', data.error);
                    return;
                }

                renderSummaryCards(data.ticket_summary);
                renderRecentTickets(data.recent_tickets);
                renderServiceStatus(data.services);
            } catch (err) {
                console.error('Failed to load dashboard:', err);
            }
        }

        // Lookup map populated from the dashboard payload — used by recent-tickets
        // table to colour status badges without a hardcoded name → class mapping
        let statusColourMap = {};

        function renderSummaryCards(summary) {
            const container = document.getElementById('summaryCards');
            if (!container) return;
            const list = summary && Array.isArray(summary.statuses) ? summary.statuses : [];

            // Refresh the colour lookup for badge rendering
            statusColourMap = {};
            list.forEach(s => { statusColourMap[s.name] = s.colour || '#0078d4'; });

            // One card per non-closed active status, plus a Total card
            const cards = list
                .filter(s => !s.is_closed)
                .map(s => {
                    const c = s.colour || '#0078d4';
                    return `
                        <div class="summary-card" style="border-left: 4px solid ${c};">
                            <div class="card-label">${escapeHtml(s.name)}</div>
                            <div class="card-value">${s.count}</div>
                        </div>
                    `;
                })
                .join('');

            const totalCard = `
                <div class="summary-card card-total">
                    <div class="card-label">${escapeHtml(window.t('self-service.dashboard.total'))}</div>
                    <div class="card-value">${(summary && summary.total) || 0}</div>
                </div>
            `;

            container.innerHTML = cards + totalCard;
        }

        function renderRecentTickets(tickets) {
            const container = document.getElementById('ticketsContainer');

            if (!tickets || tickets.length === 0) {
                container.innerHTML = '<div class="empty-state">' + escapeHtml(window.t('self-service.dashboard.no_tickets')) + ' <a href="new-ticket.php">' + escapeHtml(window.t('self-service.dashboard.create_first')) + '</a></div>';
                return;
            }

            let html = `<table class="ticket-table">
                <thead>
                    <tr>
                        <th>${escapeHtml(window.t('self-service.dashboard.col_ticket'))}</th>
                        <th>${escapeHtml(window.t('self-service.dashboard.col_subject'))}</th>
                        <th>${escapeHtml(window.t('self-service.dashboard.col_status'))}</th>
                        <th>${escapeHtml(window.t('self-service.dashboard.col_priority'))}</th>
                        <th>${escapeHtml(window.t('self-service.dashboard.col_updated'))}</th>
                    </tr>
                </thead>
                <tbody>`;

            tickets.forEach(t => {
                const statusStyle = buildStatusBadgeStyle(t.status_colour || statusColourMap[t.status]);
                const priorityClass = getPriorityClass(t.priority);
                const date = formatDate(t.updated_datetime || t.created_datetime);

                html += `<tr>
                    <td><a href="ticket.php?id=${t.id}" class="ticket-link"><span class="ticket-number">${escapeHtml(t.ticket_number)}</span></a></td>
                    <td><a href="ticket.php?id=${t.id}" class="ticket-link">${escapeHtml(t.subject)}</a></td>
                    <td><span class="status-badge" style="${statusStyle}">${escapeHtml(t.status)}</span></td>
                    <td><span class="priority-badge ${priorityClass}">${escapeHtml(t.priority || 'Normal')}</span></td>
                    <td><span class="ticket-date">${date}</span></td>
                </tr>`;
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        }

        function renderServiceStatus(services) {
            const container = document.getElementById('statusContainer');

            if (!services || services.length === 0) {
                container.innerHTML = '<div class="empty-state">' + escapeHtml(window.t('self-service.dashboard.no_services')) + '</div>';
                return;
            }

            const allOk = services.every(s => s.current_status === 'Operational');

            let html = '';
            if (allOk) {
                html += `<div class="all-operational">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#065f46" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    ${escapeHtml(window.t('self-service.dashboard.all_operational'))}
                </div>`;
            }

            html += '<div class="service-list">';
            services.forEach(svc => {
                const impactClass = getImpactClass(svc.current_status);
                html += `<div class="service-item">
                    <span class="svc-name">${escapeHtml(svc.name)}</span>
                    <span class="impact-badge ${impactClass}">${escapeHtml(svc.current_status)}</span>
                </div>`;
            });
            html += '</div>';

            container.innerHTML = html;
        }

        // Build inline style for a status badge from the lookup colour:
        // tinted background (~12% alpha) with the same colour for text — matches
        // the existing visual language of the legacy hardcoded badges
        function buildStatusBadgeStyle(colour) {
            const c = colour || '#0078d4';
            return `background-color: ${c}1f; color: ${c}; border: 1px solid ${c}33;`;
        }

        function getPriorityClass(priority) {
            const map = {
                'High': 'priority-high',
                'Normal': 'priority-normal',
                'Low': 'priority-low'
            };
            return map[priority] || 'priority-normal';
        }

        function getImpactClass(status) {
            const map = {
                'Operational': 'impact-operational',
                'Degraded': 'impact-degraded',
                'Partial Outage': 'impact-partial-outage',
                'Major Outage': 'impact-major-outage',
                'Maintenance': 'impact-maintenance'
            };
            return map[status] || 'impact-operational';
        }

        function formatDate(dateStr) {
            if (!dateStr) return '';
            try {
                const d = new Date(dateStr);
                return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) +
                       ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
            } catch (e) {
                return dateStr;
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }
    </script>
</body>
</html>
