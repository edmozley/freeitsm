<?php
/**
 * Self-Service Portal — Dashboard.
 *
 * Chrome (head, theme, header, nav, footer) comes from includes/header.php and
 * includes/footer.php; shared styling from assets/css/self-service.css. This
 * file is now just the dashboard itself.
 */
$pageTitleKey = "self-service.dashboard.title";   // a KEY: i18n starts in header.php
$activeNav    = "dashboard";

$pageScripts = <<<'JS'
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

            // Separate call, deliberately not awaited above: knowledge is a nice
            // extra, and a slow or failing article fetch must not hold up the
            // tickets the page actually exists for.
            loadPopularArticles();
        }

        // Reuses the Help Centre's own endpoint with sort=popular — no second
        // article query, and therefore no second copy of the visibility rules.
        async function loadPopularArticles() {
            const container = document.getElementById('articlesContainer');
            if (!container) return;
            try {
                const resp = await fetch(API_BASE + 'get_knowledge_articles.php?sort=popular&limit=6');
                const data = await resp.json();
                const list = (data.success && Array.isArray(data.articles)) ? data.articles : [];

                if (list.length === 0) {
                    container.innerHTML = '<div class="empty-state">' + escapeHtml(window.t('self-service.dashboard.no_articles')) + '</div>';
                    return;
                }

                container.innerHTML = '<div class="article-grid">' + list.map(a => `
                    <a class="article-card" href="help-centre.php?id=${encodeURIComponent(a.id)}">
                        <div class="article-card-title">${escapeHtml(a.title)}</div>
                        <div class="article-card-preview">${escapeHtml(a.preview || '')}</div>
                    </a>
                `).join('') + '</div>';
            } catch (err) {
                console.error('Failed to load articles:', err);
                container.innerHTML = '';
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
                    <td><a href="tickets.php?id=${t.id}" class="ticket-link"><span class="ticket-number">${escapeHtml(t.ticket_number)}</span></a></td>
                    <td><a href="tickets.php?id=${t.id}" class="ticket-link">${escapeHtml(t.subject)}</a></td>
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
JS;

require __DIR__ . "/includes/header.php";
?>
        <div class="welcome-section">
            <h1><?php echo htmlspecialchars(t('self-service.dashboard.welcome', ['name' => $ss_user_name])); ?></h1>
            <p><?php echo htmlspecialchars(t('self-service.dashboard.welcome_sub')); ?></p>
        </div>

        <!-- The two things people come here to do. Moved out of the nav bar:
             they are actions, not destinations. -->
        <div class="portal-actions">
            <!-- The explanatory line is a tooltip, not body text: it was the only
                 thing forcing these wider than a status card, and they line up
                 with the cards below because both use the same grid tracks. -->
            <a class="portal-action" href="new-ticket.php"
               title="<?php echo htmlspecialchars(t('self-service.dashboard.action_new_ticket_sub')); ?>">
                <span class="portal-action-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </span>
                <span class="portal-action-title"><?php echo htmlspecialchars(t('self-service.dashboard.action_new_ticket')); ?></span>
            </a>
            <a class="portal-action" href="catalogue.php"
               title="<?php echo htmlspecialchars(t('self-service.dashboard.action_catalogue_sub')); ?>">
                <span class="portal-action-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                </span>
                <span class="portal-action-title"><?php echo htmlspecialchars(t('self-service.dashboard.action_catalogue')); ?></span>
            </a>
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

        <!-- Popular articles. Deflection: the answer someone came to raise a
             ticket about is often already written down. Links into the existing
             Knowledge reader (help-centre.php?id=) rather than rendering the
             article here — there is exactly one portal article viewer. -->
        <div class="portal-section">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('self-service.dashboard.popular_articles')); ?></h2>
                <a class="section-link" href="help-centre.php"><?php echo htmlspecialchars(t('self-service.dashboard.browse_knowledge')); ?></a>
            </div>
            <div id="articlesContainer">
                <div class="loading-state"><?php echo htmlspecialchars(t('self-service.dashboard.loading_articles')); ?></div>
            </div>
        </div>
<?php require __DIR__ . "/includes/footer.php";
