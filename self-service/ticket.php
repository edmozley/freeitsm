<?php
/**
 * Self-Service Portal — Ticket.
 *
 * Chrome (head, theme, header, nav, footer) comes from includes/header.php and
 * includes/footer.php; shared styling from assets/css/self-service.css.
 */
$pageTitleKey = 'self-service.ticket.title';   // a KEY: i18n starts in header.php
$activeNav    = 'dashboard';

// Page-specific styling only — shared chrome lives in self-service.css.
$pageStyles = <<<'CSS'
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
        .error-state {
            text-align: center;
            padding: 40px 20px;
            color: #c33;
            font-size: 14px;
        }

        .recordings-section {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 20px;
        }
        .recordings-section h2 {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 14px 0;
            color: #333;
        }
        .recording-card {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
            background: #fafafa;
        }
        .recording-card:last-child { margin-bottom: 0; }
        .recording-card video {
            width: 100%;
            max-height: 360px;
            background: #000;
            border-radius: 4px;
        }
        .recording-meta {
            font-size: 12px;
            color: #777;
            margin-top: 8px;
        }
CSS;

$pageScripts = <<<'JS'
const TICKET_ID = <?php echo $ticketId; ?>;

        document.addEventListener('DOMContentLoaded', loadTicket);

        async function loadTicket() {
            const container = document.getElementById('ticketContent');

            try {
                const resp = await fetch('../api/self-service/get_ticket_detail.php?ticket_id=' + TICKET_ID);
                const data = await resp.json();

                if (!data.success) {
                    container.innerHTML = '<div class="error-state">' + escapeHtml(data.error || window.t('self-service.ticket.load_failed')) + '</div>';
                    return;
                }

                renderTicket(data.ticket, data.thread, data.notes, data.recordings || []);
            } catch (err) {
                container.innerHTML = '<div class="error-state">' + escapeHtml(window.t('self-service.ticket.load_detail_failed')) + '</div>';
            }
        }

        function renderTicket(ticket, thread, notes, recordings) {
            const container = document.getElementById('ticketContent');
            const statusStyle = buildStatusBadgeStyle(ticket.status_colour);
            const priorityClass = getPriorityClass(ticket.priority);
            const created = formatDate(ticket.created_datetime);

            let html = `
                <div class="ticket-header-card">
                    <h1 class="ticket-subject">${escapeHtml(ticket.subject)}</h1>
                    <div class="ticket-meta">
                        <span class="ticket-number-display">${escapeHtml(ticket.ticket_number)}</span>
                        <span class="status-badge" style="${statusStyle}">${escapeHtml(ticket.status)}</span>
                        <span class="priority-badge ${priorityClass}">${escapeHtml(ticket.priority || 'Normal')}</span>
                        ${ticket.department_name ? '<span class="ticket-meta-item">' + escapeHtml(ticket.department_name) + '</span>' : ''}
                        <span class="ticket-meta-item">${escapeHtml(window.t('self-service.ticket.created', { date: created }))}</span>
                    </div>
                </div>`;

            if (recordings && recordings.length) {
                html += '<div class="recordings-section"><h2>' + escapeHtml(window.t('self-service.ticket.screen_recordings')) + '</h2>';
                recordings.forEach(r => {
                    const url = '../api/self-service/get_recording.php?id=' + r.id;
                    const sizeMb = (r.file_size / 1048576).toFixed(1);
                    const durLabel = r.duration_seconds ? formatDuration(r.duration_seconds) : '';
                    const audioLabel = r.has_audio ? ' &middot; ' + escapeHtml(window.t('self-service.ticket.with_audio')) : '';
                    html +=
                        '<div class="recording-card">' +
                            '<video controls preload="metadata" src="' + url + '"></video>' +
                            '<div class="recording-meta">' +
                                escapeHtml(r.original_filename || window.t('self-service.ticket.recording')) +
                                ' &middot; ' + sizeMb + ' MB' +
                                (durLabel ? ' &middot; ' + durLabel : '') +
                                audioLabel +
                            '</div>' +
                        '</div>';
                });
                html += '</div>';
            }

            html += `
                <div class="thread-section">
                    <div class="thread-header">
                        <h2>${escapeHtml(window.t('self-service.ticket.conversation'))}</h2>
                    </div>`;

            // Merge thread and notes into chronological order
            const items = [];
            (thread || []).forEach(t => items.push({ type: 'email', data: t, date: t.received_datetime }));
            (notes || []).forEach(n => items.push({ type: 'note', data: n, date: n.created_datetime }));
            items.sort((a, b) => new Date(a.date) - new Date(b.date));

            if (items.length === 0) {
                html += '<div class="empty-state">' + escapeHtml(window.t('self-service.ticket.no_conversation')) + '</div>';
            } else {
                items.forEach(item => {
                    if (item.type === 'email') {
                        const e = item.data;
                        const dirClass = getDirectionClass(e.direction);
                        const dirLabel = e.direction || window.t('self-service.ticket.message');
                        html += `
                            <div class="thread-item">
                                <div class="thread-item-header">
                                    <div>
                                        <span class="thread-sender">${escapeHtml(e.from_name || window.t('self-service.ticket.unknown_sender'))}</span>
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
                                        <span class="thread-sender">${escapeHtml(n.analyst_name || window.t('self-service.ticket.support'))}</span>
                                        <span class="thread-direction direction-note">${escapeHtml(window.t('self-service.ticket.note'))}</span>
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

        // Build inline style for the status badge from the lookup colour returned
        // by the API — drops the hardcoded name → CSS-class map so any new status
        // configured in Tickets > Settings > Statuses renders correctly here too
        function buildStatusBadgeStyle(colour) {
            const c = colour || '#0078d4';
            return `background-color: ${c}1f; color: ${c}; border: 1px solid ${c}33;`;
        }

        function getPriorityClass(priority) {
            const map = { 'High': 'priority-high', 'Normal': 'priority-normal', 'Low': 'priority-low' };
            return map[priority] || 'priority-normal';
        }

        function getDirectionClass(direction) {
            const map = { 'Inbound': 'direction-inbound', 'Outbound': 'direction-outbound', 'Portal': 'direction-portal', 'Manual': 'direction-manual' };
            return map[direction] || 'direction-inbound';
        }

        function formatDuration(seconds) {
            const m = Math.floor(seconds / 60);
            const s = seconds % 60;
            return m + ':' + (s < 10 ? '0' : '') + s;
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
JS;

require __DIR__ . '/includes/header.php';
?>
        <a href="index.php" class="back-link">&lsaquo; <?php echo htmlspecialchars(t('self-service.ticket.back')); ?></a>

        <div id="ticketContent">
            <div class="loading-state"><?php echo htmlspecialchars(t('self-service.ticket.loading')); ?></div>
        </div>
<?php require __DIR__ . '/includes/footer.php';
