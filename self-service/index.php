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
document.addEventListener('DOMContentLoaded', loadTraining);

        /* ---- Outstanding training -------------------------------------------
         *
         * Its own fetch rather than a field on get_dashboard.php: the LMS is a
         * separate module that can be switched off entirely, and the dashboard
         * must not start failing because a module somebody does not use is
         * mid-upgrade. A failure here leaves the panel hidden and the rest of
         * the page exactly as it was.
         *
         * ⚠️ as=portal — the analyst app and the portal share one session, so an
         * administrator signed into both would otherwise be shown their ANALYST
         * courses on the portal's own dashboard (#1529).
         */
        async function loadTraining() {
            const section = document.getElementById('trainingSection');
            if (!section) return;

            let rows = [];
            try {
                const r = await fetch('../api/lms/my_courses.php?as=portal');
                const d = await r.json();
                if (!d.success) return;
                rows = d.data || [];
            } catch (e) { return; }

            // ONLY WHAT IS STILL TO DO. A dashboard panel is a prompt, not a
            // record — listing courses somebody has already passed would make it
            // permanent furniture that stops meaning anything.
            const todo = rows.filter(r => ['passed', 'completed'].indexOf(r.status) === -1);
            if (!todo.length) return;   // stays hidden; costs nothing for everyone else

            // Soonest first, and anything overdue ahead of that.
            todo.sort((a, b) => {
                if (a.is_overdue !== b.is_overdue) return a.is_overdue ? -1 : 1;
                if (!a.deadline) return 1;
                if (!b.deadline) return -1;
                return a.deadline < b.deadline ? -1 : 1;
            });

            // ⚠️ The singular is a separate key, not {count} with a plural "s".
            // The i18n layer does not pluralise at all, and ONE course is the
            // ordinary case — "You have 1 courses to complete" is what somebody
            // given a single piece of training would otherwise read.
            const overdue = todo.filter(r => r.is_overdue).length;
            const one = todo.length === 1;
            document.getElementById('trainingCount').textContent = overdue
                ? window.t(one ? 'self-service.dashboard.training_overdue_one' : 'self-service.dashboard.training_overdue',
                           { count: todo.length, overdue: overdue })
                : window.t(one ? 'self-service.dashboard.training_count_one' : 'self-service.dashboard.training_count',
                           { count: todo.length });

            // Capped at three. The point is to prompt, and the Training page is
            // one click away for the whole list.
            document.getElementById('trainingList').innerHTML = todo.slice(0, 3).map(r => {
                const [pill, pillText] = r.is_overdue
                    ? ['bad',     window.t('self-service.training.status.overdue')]
                    : r.status === 'incomplete'
                        ? ['warn',    window.t('self-service.training.status.in_progress')]
                        : ['neutral', window.t('self-service.training.status.not_started')];

                // A bar only where there is something honest to measure: a SCORM
                // package reports no lesson count, and a course NOBODY HAS
                // OPENED has no position — an empty bar reading "Lesson 0 of 3"
                // is a progress indicator for an absence of progress, and the
                // pill beside it already says "Not started".
                const bar = (r.lesson_count > 0 && r.lesson_position > 0)
                    ? `<div class="tr-dash-bar"><div class="tr-dash-bar-fill" style="width:${
                          Math.round((r.lesson_position / r.lesson_count) * 100)}%"></div></div>
                       <span class="tr-dash-step">${escapeHtml(window.t('self-service.training.step', {
                          current: r.lesson_position, total: r.lesson_count }))}</span>`
                    : '';

                // fmtNaiveDate: a deadline is a picked calendar day, never
                // converted between timezones on the way to the screen.
                const due = r.deadline
                    ? `<span class="tr-dash-due${r.is_overdue ? ' over' : ''}">${escapeHtml(
                          r.is_overdue
                              ? window.t('self-service.dashboard.training_was_due', { date: fmtNaiveDate(r.deadline) })
                              : window.t('self-service.dashboard.training_due',     { date: fmtNaiveDate(r.deadline) }))}</span>`
                    : '';

                return `<a class="tr-dash-card${r.is_overdue ? ' over' : ''}" href="course.php?id=${encodeURIComponent(r.id)}">
                    <span class="tr-dash-name">${escapeHtml(r.title)}</span>
                    ${bar}
                    <span class="tr-dash-meta">
                        <span class="tr-dash-pill ${pill}">${escapeHtml(pillText)}</span>
                        ${due}
                    </span>
                </a>`;
            }).join('');

            section.style.display = '';
        }

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
                renderRequests(data.requests);
                renderRecentTickets(data.recent_tickets);
                renderServiceStatus(data.services, data.default_impact);
                renderStatusIncidents(data.incidents);
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

        // Catalogue requests awaiting / after approval (#928). Hidden entirely when
        // the user has none, so it never adds noise for people who only raise tickets.
        function renderRequests(requests) {
            const section = document.getElementById('requestsSection');
            const container = document.getElementById('requestsContainer');
            if (!section || !container) return;

            const list = Array.isArray(requests) ? requests : [];
            if (list.length === 0) { section.style.display = 'none'; return; }
            section.style.display = '';

            const rows = list.map(r => {
                let statusCell;
                if (r.approval_status === 'approved') {
                    const link = r.ticket_number
                        ? ` <a href="tickets.php?id=${r.ticket_id}" class="ticket-link">${escapeHtml(r.ticket_number)}</a>` : '';
                    statusCell = `<span class="req-pill approved">${escapeHtml(window.t('self-service.dashboard.req_approved'))}</span>${link}`;
                } else if (r.approval_status === 'rejected') {
                    statusCell = `<span class="req-pill rejected">${escapeHtml(window.t('self-service.dashboard.req_rejected'))}</span>`;
                } else {
                    statusCell = `<span class="req-pill pending">${escapeHtml(window.t('self-service.dashboard.req_pending'))}</span>`;
                }
                return `<tr>
                    <td>${escapeHtml(r.form_title)}</td>
                    <td>${statusCell}</td>
                    <td><span class="ticket-date">${formatDate(r.submitted_date)}</span></td>
                </tr>`;
            }).join('');

            /* `req-table` tells this table apart from the recent-tickets one
               below. Both are `.ticket-table`, but this has three columns
               (request, status, submitted) against that one's five — and the
               phone card layout keys on column INDEX, so without a hook the
               status cell here is styled as though it were a subject. The
               class carries no styling at any width, so the desktop table is
               unchanged. */
            container.innerHTML = `<table class="ticket-table req-table">
                <thead><tr>
                    <th>${escapeHtml(window.t('self-service.dashboard.req_col_request'))}</th>
                    <th>${escapeHtml(window.t('self-service.dashboard.req_col_status'))}</th>
                    <th>${escapeHtml(window.t('self-service.dashboard.req_col_submitted'))}</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;
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

        /**
         * Incidents behind an outage, and what has been said about them (#99).
         *
         * ⚠️ COLLAPSED by default, which the requester asked for and which is
         * right for a different reason too: most people looking at a status page
         * want to know whether something is wrong, not to read the whole history.
         * The updates are fetched when somebody opens one, so a portal nobody
         * expands costs nothing.
         *
         * The server sends only incidents that have at least one external
         * update, and only when an administrator has switched this on — so an
         * empty list here is the normal state, not a failure.
         */
        function renderStatusIncidents(incidents) {
            const box = document.getElementById('statusIncidents');
            if (!box) return;

            if (!incidents || !incidents.length) {
                box.innerHTML = '';
                box.hidden = true;
                return;
            }
            box.hidden = false;

            box.innerHTML = `<h3 class="inc-list-heading">${escapeHtml(window.t('self-service.status.incidents_heading'))}</h3>`
                + incidents.map(inc => {
                    const svcs = (inc.services || []).map(s =>
                        `<span class="inc-svc"${s.impact_colour ? ` style="background:${escapeHtml(s.impact_colour)};color:#fff;"` : ''}>${escapeHtml(s.service_name)}</span>`
                    ).join('');

                    // A resolved incident says so plainly. It is the most
                    // reassuring thing on the page for somebody who was hit by it.
                    const resolved = Number(inc.is_resolved) === 1;

                    return `
                    <div class="inc-card${resolved ? ' is-resolved' : ''}" data-incident="${inc.id}">
                        <div class="inc-card-head">
                            <span class="inc-card-title">${escapeHtml(inc.title || '')}</span>
                            <span class="inc-card-state">${escapeHtml(inc.status || '')}</span>
                        </div>
                        ${svcs ? `<div class="inc-card-svcs">${svcs}</div>` : ''}
                        <button type="button" class="inc-card-toggle" onclick="toggleStatusUpdates(${inc.id}, this)">
                            ${escapeHtml(window.t('self-service.status.show_updates', { n: inc.update_count }))}
                        </button>
                        <div class="inc-card-updates" id="incUpd${inc.id}" hidden></div>
                    </div>`;
                }).join('');
        }

        async function toggleStatusUpdates(incidentId, btn) {
            const box = document.getElementById('incUpd' + incidentId);
            if (!box) return;

            if (!box.hidden) {
                box.hidden = true;
                btn.textContent = btn.dataset.showLabel || window.t('self-service.status.show_updates', { n: '' });
                return;
            }

            // Remember the collapsed label before replacing it, so re-collapsing
            // puts back the count rather than a guess at it.
            if (!btn.dataset.showLabel) btn.dataset.showLabel = btn.textContent.trim();
            box.hidden = false;

            if (!box.dataset.loaded) {
                box.innerHTML = `<div class="inc-upd-loading">${escapeHtml(window.t('self-service.status.loading'))}</div>`;
                try {
                    const d = await fetch('../api/self-service/get_incident_updates.php?incident_id=' + incidentId)
                        .then(r => r.json());
                    const rows = (d.success && d.updates) ? d.updates : [];

                    // A table with timestamps, as asked for: what was said, when,
                    // oldest first, so it reads as the story of the outage.
                    box.innerHTML = rows.length
                        ? `<table class="inc-upd-table">${rows.map(u => `
                            <tr>
                                <td class="inc-upd-when">${escapeHtml(formatWhen(u.created_datetime))}</td>
                                <td class="inc-upd-body">
                                    ${u.status ? `<span class="inc-upd-status">${escapeHtml(u.status)}</span>` : ''}
                                    ${u.comment ? `<span class="inc-upd-text">${escapeHtml(u.comment)}</span>` : ''}
                                </td>
                            </tr>`).join('')}</table>`
                        : `<div class="inc-upd-loading">${escapeHtml(window.t('self-service.status.no_updates'))}</div>`;
                    box.dataset.loaded = '1';
                } catch (e) {
                    box.innerHTML = `<div class="inc-upd-loading">${escapeHtml(window.t('self-service.status.load_failed'))}</div>`;
                }
            }
            btn.textContent = window.t('self-service.status.hide_updates');
        }

        /** Stored UTC, shown in the reader's own zone — same as everything else here. */
        function formatWhen(dt) {
            try {
                const d = parseUTCDate(dt);
                return (!d || isNaN(d.getTime())) ? dt : fmtDateTime(d);
            } catch (e) { return dt; }
        }

        function renderServiceStatus(services, defaultImpact) {
            const container = document.getElementById('statusContainer');

            if (!services || services.length === 0) {
                container.innerHTML = '<div class="empty-state">' + escapeHtml(window.t('self-service.dashboard.no_services')) + '</div>';
                return;
            }

            // "All clear" = every service sitting at the configured default impact
            // level, whatever it is called. Testing against the literal 'Operational'
            // meant renaming the level silently hid this banner for good (GH #70).
            const okName = (defaultImpact && defaultImpact.name) || 'Operational';
            const allOk = services.every(s => s.current_status === okName);

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
                // Colour from the lookup row wins; the hardcoded class map is only a
                // fallback for a level with no colour set (GH #70 — a renamed level
                // matched nothing in that map and came out looking Operational).
                const colour = svc.current_status_colour;
                const badgeAttrs = colour
                    ? `class="impact-badge" style="${buildStatusBadgeStyle(colour)}"`
                    : `class="impact-badge ${getImpactClass(svc.current_status)}"`;
                html += `<div class="service-item">
                    <span class="svc-name">${escapeHtml(svc.name)}</span>
                    <span ${badgeAttrs}>${escapeHtml(svc.current_status)}</span>
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
            // `new Date(dateStr)` with no Z treated a UTC database value as browser
            // LOCAL time, so portal timestamps were showing the wrong instant, not
            // just the wrong shape. parseUTCDate (inside fmtDateTime) marks it UTC.
            if (!dateStr) return '';
            try {
                return fmtDateTime(dateStr);
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

        <!-- Outstanding training. ABOVE the ticket counts deliberately: this is
             the one thing on the dashboard that is asking something OF the
             person rather than telling them where their own requests are up to,
             and it is the reason they were emailed. Hidden entirely unless they
             have something to do, so it costs nothing for the majority of
             installs that never push training to the portal. -->
        <style>
            #trainingSection { margin-bottom: 22px; }
            .tr-dash-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
            .tr-dash-all { font-size: 13px; font-weight: 600; text-decoration: none; color: var(--ss-accent, var(--accent, #0078d4)); }

            /* CARDS, not full-width rows. Three grids already run down this page
               — the actions, the ticket counts and the article list — and all of
               them use auto-fill tracks so they line up with each other. A row
               list here stretched a course title across the whole window and
               parked its due date a foot away from it. 240px matches
               .article-grid, the nearest thing in kind. */
            .tr-dash-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
                gap: 12px;
                padding: 16px 20px;
            }

            .tr-dash-card {
                display: flex;
                flex-direction: column;
                gap: 8px;
                padding: 14px 16px;
                border: 1px solid var(--border, #e5e7eb);
                border-radius: 8px;
                background: var(--surface, #fff);
                text-decoration: none;
                color: inherit;
            }
            .tr-dash-card:hover { border-color: var(--ss-accent, var(--accent, #0078d4)); }
            /* The one that is late gets a spine, so it is findable without
               reading — the same device the Contracts totals use for a warning. */
            .tr-dash-card.over { border-left: 3px solid var(--danger-text, #991b1b); }

            .tr-dash-name { font-weight: 600; color: var(--text, #333); line-height: 1.35; }

            .tr-dash-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: auto; }
            .tr-dash-pill {
                display: inline-block;
                padding: 2px 9px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 700;
                border: 1px solid transparent;
            }
            .tr-dash-pill.bad     { background: var(--danger-bg, #fee2e2); color: var(--danger-text, #991b1b); border-color: var(--danger-border, #fca5a5); }
            .tr-dash-pill.warn    { background: var(--warning-bg, #fef3c7); color: var(--warning-text, #92400e); border-color: var(--warning-border, #fcd34d); }
            .tr-dash-pill.neutral { background: var(--surface-3, #f5f6f8); color: var(--text-muted, #666); border-color: var(--border, #e5e7eb); }

            .tr-dash-due  { font-size: 12px; color: var(--text-muted, #666); }
            .tr-dash-due.over { color: var(--danger-text, #991b1b); font-weight: 600; }

            .tr-dash-bar { height: 5px; border-radius: 3px; background: var(--surface-3, #eef0f3); overflow: hidden; }
            .tr-dash-bar-fill { height: 100%; background: var(--ss-accent, var(--accent, #0078d4)); }
            .tr-dash-step { font-size: 11px; color: var(--text-muted, #666); }

            @media (max-width: 640px) {
                .tr-dash-grid { grid-template-columns: 1fr; padding: 14px; }
            }
        </style>
        <div class="portal-section" id="trainingSection" style="display:none;">
            <div class="section-header tr-dash-head">
                <h2 id="trainingCount"></h2>
                <a class="tr-dash-all" href="training.php"><?php echo htmlspecialchars(t('self-service.dashboard.training_all')); ?></a>
            </div>
            <div class="tr-dash-grid" id="trainingList"></div>
        </div>

        <!-- Summary Cards (rendered dynamically from active ticket_statuses) -->
        <div class="summary-cards" id="summaryCards"></div>

        <!-- Your requests (#928): catalogue requests awaiting or after approval.
             Hidden until JS finds at least one, so it costs nothing for ticket-only
             users. A pending request isn't a ticket yet, so this is the ONLY place
             the requester can see it. -->
        <style>
            /* Match the summary-cards bottom margin (22px) so this sits evenly between
               the cards above and the Recent Tickets grid below, rather than butting
               against it — .portal-section carries no margin of its own. */
            #requestsSection { margin-bottom: 22px; }
            .req-pill { display: inline-block; font-size: 12px; font-weight: 600; padding: 2px 10px; border-radius: 11px; }
            .req-pill.pending  { background: var(--warning-bg, #fef3c7); color: var(--warning-text, #92400e); }
            .req-pill.approved { background: var(--success-bg, #dcfce7); color: var(--success-text, #166534); }
            .req-pill.rejected { background: var(--danger-bg, #fee2e2);  color: var(--danger-text, #991b1b); }
        </style>
        <div class="portal-section" id="requestsSection" style="display:none;">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('self-service.dashboard.your_requests')); ?></h2>
            </div>
            <div id="requestsContainer"></div>
        </div>

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
                <?php /* Incidents behind an outage (#99). Hidden until there is
                         something to show, which on most installs is always —
                         it needs an administrator to switch it on AND somebody
                         to have written an external update. */ ?>
                <div id="statusIncidents" hidden></div>
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
