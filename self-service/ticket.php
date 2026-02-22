<?php
/**
 * Self-Service Portal - Ticket Detail View
 */
session_start();
require_once '../config.php';
require_once 'includes/auth.php';

$ticketId = (int)($_GET['id'] ?? 0);
if (!$ticketId) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Self-Service Portal - Ticket Detail</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; background: #f5f5f5; }

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
            gap: 16px;
            font-size: 13px;
        }
        .portal-user .user-name { color: rgba(255,255,255,0.9); }
        .portal-user a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-size: 12px;
        }
        .portal-user a:hover { color: white; }

        .portal-layout {
            max-width: 900px;
            margin: 0 auto;
            padding: 28px 24px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #0078d4;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 20px;
        }
        .back-link:hover { text-decoration: underline; }

        /* Ticket Header */
        .ticket-header-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 20px;
        }
        .ticket-subject {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin: 0 0 12px 0;
        }
        .ticket-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
        }
        .ticket-meta-item {
            font-size: 13px;
            color: #666;
        }
        .ticket-meta-item strong { color: #333; }
        .ticket-number-display {
            font-family: 'Consolas', 'Courier New', monospace;
            background: #f3f4f6;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            color: #555;
        }

        /* Status & Priority badges (reused from dashboard) */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-open { background: #dbeafe; color: #1e40af; }
        .status-in-progress { background: #fff7ed; color: #c2410c; }
        .status-on-hold { background: #fef3c7; color: #92400e; }
        .status-closed { background: #d1fae5; color: #065f46; }
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

        /* Thread */
        .thread-section {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        .thread-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        .thread-header h2 {
            font-size: 15px;
            font-weight: 600;
            color: #333;
            margin: 0;
        }
        .thread-item {
            padding: 20px;
            border-bottom: 1px solid #f3f4f6;
        }
        .thread-item:last-child { border-bottom: none; }
        .thread-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .thread-sender {
            font-size: 13px;
            font-weight: 600;
            color: #333;
        }
        .thread-direction {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .direction-inbound { background: #dbeafe; color: #1e40af; }
        .direction-outbound { background: #d1fae5; color: #065f46; }
        .direction-portal { background: #e0e7ff; color: #3730a3; }
        .direction-manual { background: #f3f4f6; color: #6b7280; }
        .direction-note { background: #fef3c7; color: #92400e; }
        .thread-date {
            font-size: 12px;
            color: #999;
        }
        .thread-body {
            font-size: 14px;
            color: #333;
            line-height: 1.6;
        }
        .thread-body img { max-width: 100%; }

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
        .error-state {
            text-align: center;
            padding: 40px 20px;
            color: #c33;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="portal-header">
        <div class="portal-brand">
            <img src="../assets/images/CompanyLogo.png" alt="Logo">
            <span>Self-Service Portal</span>
        </div>
        <nav class="portal-nav">
            <a href="index.php">Dashboard</a>
            <a href="new-ticket.php">New Ticket</a>
        </nav>
        <div class="portal-user">
            <span class="user-name"><?php echo htmlspecialchars($ss_user_name); ?></span>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="portal-layout">
        <a href="index.php" class="back-link">&lsaquo; Back to Dashboard</a>

        <div id="ticketContent">
            <div class="loading-state">Loading ticket...</div>
        </div>
    </div>

    <script>
        const TICKET_ID = <?php echo $ticketId; ?>;

        document.addEventListener('DOMContentLoaded', loadTicket);

        async function loadTicket() {
            const container = document.getElementById('ticketContent');

            try {
                const resp = await fetch('../api/self-service/get_ticket_detail.php?ticket_id=' + TICKET_ID);
                const data = await resp.json();

                if (!data.success) {
                    container.innerHTML = '<div class="error-state">' + escapeHtml(data.error || 'Failed to load ticket') + '</div>';
                    return;
                }

                renderTicket(data.ticket, data.thread, data.notes);
            } catch (err) {
                container.innerHTML = '<div class="error-state">Failed to load ticket details</div>';
            }
        }

        function renderTicket(ticket, thread, notes) {
            const container = document.getElementById('ticketContent');
            const statusClass = getStatusClass(ticket.status);
            const priorityClass = getPriorityClass(ticket.priority);
            const created = formatDate(ticket.created_datetime);

            let html = `
                <div class="ticket-header-card">
                    <h1 class="ticket-subject">${escapeHtml(ticket.subject)}</h1>
                    <div class="ticket-meta">
                        <span class="ticket-number-display">${escapeHtml(ticket.ticket_number)}</span>
                        <span class="status-badge ${statusClass}">${escapeHtml(ticket.status)}</span>
                        <span class="priority-badge ${priorityClass}">${escapeHtml(ticket.priority || 'Normal')}</span>
                        ${ticket.department_name ? '<span class="ticket-meta-item">' + escapeHtml(ticket.department_name) + '</span>' : ''}
                        <span class="ticket-meta-item">Created ${created}</span>
                    </div>
                </div>

                <div class="thread-section">
                    <div class="thread-header">
                        <h2>Conversation</h2>
                    </div>`;

            // Merge thread and notes into chronological order
            const items = [];
            (thread || []).forEach(t => items.push({ type: 'email', data: t, date: t.received_datetime }));
            (notes || []).forEach(n => items.push({ type: 'note', data: n, date: n.created_datetime }));
            items.sort((a, b) => new Date(a.date) - new Date(b.date));

            if (items.length === 0) {
                html += '<div class="empty-state">No conversation yet</div>';
            } else {
                items.forEach(item => {
                    if (item.type === 'email') {
                        const e = item.data;
                        const dirClass = getDirectionClass(e.direction);
                        const dirLabel = e.direction || 'Message';
                        html += `
                            <div class="thread-item">
                                <div class="thread-item-header">
                                    <div>
                                        <span class="thread-sender">${escapeHtml(e.from_name || 'Unknown')}</span>
                                        <span class="thread-direction ${dirClass}">${escapeHtml(dirLabel)}</span>
                                    </div>
                                    <span class="thread-date">${formatDate(e.received_datetime)}</span>
                                </div>
                                <div class="thread-body">${e.body_content || ''}</div>
                            </div>`;
                    } else {
                        const n = item.data;
                        html += `
                            <div class="thread-item">
                                <div class="thread-item-header">
                                    <div>
                                        <span class="thread-sender">${escapeHtml(n.analyst_name || 'Support')}</span>
                                        <span class="thread-direction direction-note">Note</span>
                                    </div>
                                    <span class="thread-date">${formatDate(n.created_datetime)}</span>
                                </div>
                                <div class="thread-body">${escapeHtml(n.note_text || '')}</div>
                            </div>`;
                    }
                });
            }

            html += '</div>';
            container.innerHTML = html;
        }

        function getStatusClass(status) {
            const map = { 'Open': 'status-open', 'In Progress': 'status-in-progress', 'On Hold': 'status-on-hold', 'Closed': 'status-closed' };
            return map[status] || 'status-open';
        }

        function getPriorityClass(priority) {
            const map = { 'High': 'priority-high', 'Normal': 'priority-normal', 'Low': 'priority-low' };
            return map[priority] || 'priority-normal';
        }

        function getDirectionClass(direction) {
            const map = { 'Inbound': 'direction-inbound', 'Outbound': 'direction-outbound', 'Portal': 'direction-portal', 'Manual': 'direction-manual' };
            return map[direction] || 'direction-inbound';
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
