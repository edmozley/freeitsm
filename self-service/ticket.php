<?php
/**
 * Self-Service Portal — Ticket.
 *
 * Chrome (head, theme, header, nav, footer) comes from includes/header.php and
 * includes/footer.php; shared styling from assets/css/self-service.css.
 */
$pageTitleKey = 'self-service.ticket.title';   // a KEY: i18n starts in header.php
$activeNav    = 'dashboard';

// Which ticket. No id means someone hand-typed the URL — send them home rather
// than rendering a shell that can only fail. (Ownership is enforced by the API,
// not here: this page is a frame, the data comes from get_ticket_detail.php.)
$ticketId = (int)($_GET['id'] ?? 0);
if (!$ticketId) {
    header('Location: index.php');
    exit;
}

// Page-specific VALUES for the script below. They cannot be interpolated into
// $pageScripts: that is a nowdoc, so a PHP tag inside it is emitted verbatim and
// kills the whole script block. See the note in includes/footer.php.
$pageData = ['ticketId' => $ticketId];

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

        /* Attachments on a message. New UI, so it uses the theme tokens rather
           than the fixed colours the rest of this page still carries. */
        .thread-attachments {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }
        .attachment-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 6px;
            background: var(--surface-hover, #fafafa);
            color: var(--text, #333);
            font-size: 12px;
            text-decoration: none;
            max-width: 100%;
        }
        .attachment-chip:hover { border-color: var(--ss-accent, #0078d4); }
        .attachment-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 220px;
        }
        .attachment-size { color: var(--text-muted, #777); flex-shrink: 0; }

        /* Reply composer */
        .reply-section {
            background: var(--surface, white);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            padding: 20px 24px;
            margin-top: 20px;
        }
        .reply-section h2 {
            font-size: 15px;
            font-weight: 600;
            margin: 0 0 14px 0;
            color: var(--text, #333);
        }
        .reply-box {
            width: 100%;
            min-height: 110px;
            padding: 12px;
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 6px;
            background: var(--surface, white);
            color: var(--text, #333);
            font-family: inherit;
            font-size: 14px;
            line-height: 1.5;
            resize: vertical;
        }
        .reply-box:focus {
            outline: none;
            border-color: var(--ss-accent, #0078d4);
        }
        .reply-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        .reply-files {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }
        .reply-file {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 6px;
            background: var(--surface-hover, #fafafa);
            font-size: 12px;
            color: var(--text, #333);
        }
        .reply-file button {
            border: none;
            background: none;
            cursor: pointer;
            color: var(--text-muted, #777);
            font-size: 15px;
            line-height: 1;
            padding: 0;
        }
        .reply-file button:hover { color: var(--danger-text, #c33); }
        .reply-hint {
            font-size: 12px;
            color: var(--text-muted, #777);
        }
        .reply-notice {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            background: var(--success-bg, #d1fae5);
            color: var(--text, #065f46);
        }
        .reply-notice.is-error {
            background: var(--danger-bg, #fee2e2);
            color: var(--danger-text, #c33);
        }
CSS;

$pageScripts = <<<'JS'
const TICKET_ID = window.PAGE.ticketId;

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
                                <div class="thread-body">${safeMessageHtml(e.body_content, e.body_type)}</div>
                                ${renderAttachments(e.attachments)}
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
            html += renderReplyBox();
            container.innerHTML = html;
            wireReplyBox();
        }

        /*
         * Message bodies are arbitrary third-party HTML — anyone who can email the
         * service desk controls them, and this page renders them inside the
         * requester's own signed-in session.
         *
         * The cleaning itself lives in assets/js/safe-html.js, shared with the
         * analyst inbox: both surfaces show the SAME email bodies, and a security
         * control kept in two copies drifts. Fails closed if that file is missing.
         */
        function safeMessageHtml(html, bodyType) {
            if (typeof messageBodyHtml !== 'function') {
                console.error('FreeITSM: assets/js/safe-html.js did not load — message bodies are being shown as plain text.');
                return typeof escapeHtmlText === 'function' ? escapeHtmlText(html) : '';
            }
            return messageBodyHtml(html, bodyType);
        }

        function renderAttachments(attachments) {
            if (!attachments || !attachments.length) return '';
            const chips = attachments.map(a => {
                const url = '../api/self-service/get_attachment.php?id=' + encodeURIComponent(a.id);
                return '<a class="attachment-chip" href="' + url + '" target="_blank" rel="noopener">' +
                           '<span class="attachment-name">' + escapeHtml(a.filename || '') + '</span>' +
                           '<span class="attachment-size">' + formatBytes(a.file_size) + '</span>' +
                       '</a>';
            }).join('');
            return '<div class="thread-attachments">' + chips + '</div>';
        }

        function formatBytes(bytes) {
            const b = Number(bytes) || 0;
            if (b < 1024) return b + ' B';
            if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
            return (b / 1048576).toFixed(1) + ' MB';
        }

        function renderReplyBox() {
            return `
                <div class="reply-section">
                    <h2>${escapeHtml(window.t('self-service.ticket.reply_heading'))}</h2>
                    <textarea class="reply-box" id="replyBody" placeholder="${escapeHtml(window.t('self-service.ticket.reply_placeholder'))}"></textarea>
                    <div class="reply-files" id="replyFiles"></div>
                    <div class="reply-actions">
                        <button type="button" class="btn btn-primary" id="replySend">${escapeHtml(window.t('self-service.ticket.reply_send'))}</button>
                        <input type="file" id="replyFileInput" multiple style="display:none">
                        <button type="button" class="btn btn-secondary" id="replyAttach">${escapeHtml(window.t('self-service.ticket.reply_attach'))}</button>
                        <span class="reply-hint">${escapeHtml(window.t('self-service.ticket.reply_hint'))}</span>
                    </div>
                    <div id="replyNotice"></div>
                </div>`;
        }

        // Files chosen but not yet sent. Held as base64 to match the payload
        // shape create_ticket.php already accepts.
        let pendingReplyFiles = [];

        function wireReplyBox() {
            const attachBtn = document.getElementById('replyAttach');
            const fileInput = document.getElementById('replyFileInput');
            const sendBtn   = document.getElementById('replySend');
            if (!attachBtn || !fileInput || !sendBtn) return;

            attachBtn.addEventListener('click', () => fileInput.click());
            fileInput.addEventListener('change', async () => {
                for (const file of fileInput.files) {
                    try {
                        pendingReplyFiles.push({
                            name: file.name,
                            type: file.type || 'application/octet-stream',
                            size: file.size,
                            content: await fileToBase64(file)
                        });
                    } catch (e) { /* skip a file the browser can't read */ }
                }
                fileInput.value = '';
                renderPendingFiles();
            });

            sendBtn.addEventListener('click', sendReply);
        }

        function fileToBase64(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                // result is a data: URL — the API wants the base64 payload only.
                reader.onload = () => resolve(String(reader.result).split(',')[1] || '');
                reader.onerror = reject;
                reader.readAsDataURL(file);
            });
        }

        function renderPendingFiles() {
            const host = document.getElementById('replyFiles');
            if (!host) return;
            host.innerHTML = pendingReplyFiles.map((f, i) =>
                '<span class="reply-file">' +
                    escapeHtml(f.name) + ' <span class="attachment-size">' + formatBytes(f.size) + '</span>' +
                    '<button type="button" title="' + escapeHtml(window.t('self-service.ticket.reply_remove_file')) + '" onclick="removePendingFile(' + i + ')">&times;</button>' +
                '</span>'
            ).join('');
        }

        function removePendingFile(index) {
            pendingReplyFiles.splice(index, 1);
            renderPendingFiles();
        }

        function showReplyNotice(message, isError) {
            const host = document.getElementById('replyNotice');
            if (!host) return;
            host.innerHTML = '<div class="reply-notice' + (isError ? ' is-error' : '') + '">' + escapeHtml(message) + '</div>';
        }

        async function sendReply() {
            const bodyEl  = document.getElementById('replyBody');
            const sendBtn = document.getElementById('replySend');
            const body    = (bodyEl.value || '').trim();

            if (!body && !pendingReplyFiles.length) {
                showReplyNotice(window.t('self-service.ticket.reply_empty'), true);
                return;
            }

            sendBtn.disabled = true;
            sendBtn.textContent = window.t('self-service.ticket.reply_sending');

            try {
                const response = await fetch('../api/self-service/reply_ticket.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        ticket_id: TICKET_ID,
                        body: body,
                        attachments: pendingReplyFiles.map(f => ({ name: f.name, type: f.type, content: f.content }))
                    })
                });
                const data = await response.json();

                if (!data.success) {
                    showReplyNotice(data.error || window.t('self-service.ticket.reply_failed'), true);
                    return;
                }

                // Sent: clear the composer and reload so the new message (and the
                // reopened status, if it changed) come straight from the server
                // rather than being guessed at here.
                pendingReplyFiles = [];
                bodyEl.value = '';
                const reopened = data.reopened;
                await loadTicket();
                showReplyNotice(
                    reopened ? window.t('self-service.ticket.reply_sent_reopened')
                             : window.t('self-service.ticket.reply_sent'),
                    false
                );
            } catch (err) {
                showReplyNotice(window.t('self-service.ticket.reply_failed'), true);
            } finally {
                // Re-query: a successful send re-renders the whole ticket, so the
                // button captured above is a detached node by now.
                const btn = document.getElementById('replySend');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = window.t('self-service.ticket.reply_send');
                }
            }
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
