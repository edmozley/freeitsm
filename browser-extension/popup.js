/**
 * FreeITSM Watchtower — Popup Script
 */

document.addEventListener('DOMContentLoaded', async () => {
    const settings = await chrome.storage.sync.get(['serverUrl', 'apiKey']);

    if (!settings.serverUrl || !settings.apiKey) {
        showNotConfigured();
        return;
    }

    // Load cached data immediately
    const local = await chrome.storage.local.get(['dashboardData', 'lastError', 'lastFetch']);

    if (local.lastError && !local.dashboardData) {
        showError(local.lastError);
        document.getElementById('content').innerHTML = '<div class="not-configured"><p>Unable to connect to your FreeITSM instance.</p></div>';
    } else if (local.dashboardData) {
        renderDashboard(local.dashboardData);
        if (local.lastError) showError(local.lastError);
    }

    if (local.lastFetch) {
        updateTimestamp(local.lastFetch);
    }

    // Bind refresh
    document.getElementById('refreshBtn').addEventListener('click', async () => {
        const btn = document.getElementById('refreshBtn');
        btn.classList.add('spinning');
        await chrome.runtime.sendMessage({ action: 'refresh' });
        const updated = await chrome.storage.local.get(['dashboardData', 'lastError', 'lastFetch']);
        if (updated.dashboardData) {
            renderDashboard(updated.dashboardData);
            hideError();
        }
        if (updated.lastError) showError(updated.lastError);
        if (updated.lastFetch) updateTimestamp(updated.lastFetch);
        btn.classList.remove('spinning');
    });

    // Bind footer links
    document.getElementById('openDashboard').addEventListener('click', (e) => {
        e.preventDefault();
        const url = settings.serverUrl.replace(/\/+$/, '') + '/watchtower/';
        chrome.tabs.create({ url });
    });

    document.getElementById('openSettings').addEventListener('click', (e) => {
        e.preventDefault();
        chrome.runtime.openOptionsPage();
    });
});

function showNotConfigured() {
    document.getElementById('content').innerHTML = `
        <div class="not-configured">
            <p>Welcome to FreeITSM Watchtower.<br>Configure your server URL and API key to get started.</p>
            <button class="setup-btn" id="setupBtn">Setup</button>
        </div>
    `;
    document.getElementById('setupBtn').addEventListener('click', () => {
        chrome.runtime.openOptionsPage();
    });
}

function showError(msg) {
    document.getElementById('errorBanner').style.display = 'flex';
    document.getElementById('errorText').textContent = msg;
}

function hideError() {
    document.getElementById('errorBanner').style.display = 'none';
}

function updateTimestamp(iso) {
    const d = new Date(iso);
    const now = new Date();
    const diffMs = now - d;
    const diffMin = Math.floor(diffMs / 60000);

    let text;
    if (diffMin < 1) text = 'Just now';
    else if (diffMin < 60) text = diffMin + 'm ago';
    else text = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    document.getElementById('lastUpdated').textContent = text;
}

function renderDashboard(data) {
    const html = `
        <div class="cards">
            ${renderMorningChecks(data.morning_checks)}
            ${renderTickets(data.tickets)}
            ${renderChanges(data.changes)}
            ${renderServiceStatus(data.service_status)}
            ${renderCalendar(data.calendar)}
            ${renderContracts(data.contracts)}
            ${renderKnowledge(data.knowledge)}
            ${renderAssets(data.assets)}
            ${renderTasks(data.tasks)}
        </div>
    `;
    document.getElementById('content').innerHTML = html;
}

function renderMorningChecks(mc) {
    if (!mc) return '';
    const done = mc.completed_today || 0;
    const total = mc.total_checks || 0;
    const fails = (mc.statuses && mc.statuses['Fail']) || 0;
    let detail = '';
    if (mc.not_started) {
        detail = '<span class="warn">Not started</span>';
    } else if (fails > 0) {
        detail = `<span class="highlight">${fails} failed</span>`;
    } else if (done === total && total > 0) {
        detail = '<span class="good">All passed</span>';
    } else {
        detail = `${total - done} remaining`;
    }

    return `
        <div class="card">
            <div class="card-header">
                <svg class="card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                <span class="card-title">Checks</span>
            </div>
            <div class="card-metric">${done}/${total}</div>
            <div class="card-detail">${detail}</div>
        </div>
    `;
}

function renderTickets(tk) {
    if (!tk) return '';
    const total = (tk.open || 0) + (tk.in_progress || 0) + (tk.on_hold || 0);
    let details = [];
    if (tk.urgent_high > 0) details.push(`<span class="highlight">${tk.urgent_high} urgent/high</span>`);
    if (tk.unassigned > 0) details.push(`<span class="warn">${tk.unassigned} unassigned</span>`);
    if (details.length === 0) details.push(`${tk.in_progress || 0} in progress`);

    return `
        <div class="card">
            <div class="card-header">
                <svg class="card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                </svg>
                <span class="card-title">Tickets</span>
            </div>
            <div class="card-metric">${total}</div>
            <div class="card-detail">${details.join(' &middot; ')}</div>
        </div>
    `;
}

function renderChanges(ch) {
    if (!ch) return '';
    let details = [];
    if (ch.unapproved > 0) details.push(`<span class="warn">${ch.unapproved} unapproved</span>`);
    if (ch.in_progress_today > 0) details.push(`${ch.in_progress_today} in progress`);
    if (details.length === 0) details.push('None pending');

    return `
        <div class="card">
            <div class="card-header">
                <svg class="card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="17 1 21 5 17 9"></polyline>
                    <path d="M3 11V9a4 4 0 0 1 4-4h14"></path>
                    <polyline points="7 23 3 19 7 15"></polyline>
                    <path d="M21 13v2a4 4 0 0 1-4 4H3"></path>
                </svg>
                <span class="card-title">Changes</span>
            </div>
            <div class="card-metric">${ch.upcoming_7d || 0}</div>
            <div class="card-detail">upcoming 7d &middot; ${details.join(' &middot; ')}</div>
        </div>
    `;
}

function renderServiceStatus(ss) {
    if (!ss) return '';
    if (ss.all_operational) {
        return `
            <div class="card">
                <div class="card-header">
                    <svg class="card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                    </svg>
                    <span class="card-title">Services</span>
                </div>
                <div class="card-metric"><span class="badge-operational">All Operational</span></div>
                <div class="card-detail">No active incidents</div>
            </div>
        `;
    }

    const degradedCount = (ss.degraded_services || []).length;
    return `
        <div class="card">
            <div class="card-header">
                <svg class="card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                </svg>
                <span class="card-title">Services</span>
            </div>
            <div class="card-metric"><span class="badge-degraded">${degradedCount} degraded</span></div>
            <div class="card-detail"><span class="highlight">${ss.active_incidents || 0} active incident${ss.active_incidents !== 1 ? 's' : ''}</span></div>
        </div>
    `;
}

function renderCalendar(cal) {
    if (!cal) return '';
    return `
        <div class="card">
            <div class="card-header">
                <svg class="card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                <span class="card-title">Calendar</span>
            </div>
            <div class="card-metric">${cal.today_count || 0}</div>
            <div class="card-detail">today &middot; ${cal.week_count || 0} this week</div>
        </div>
    `;
}

function renderContracts(ct) {
    if (!ct) return '';
    let detail = '';
    if (ct.expiring_30d > 0) {
        detail = `<span class="highlight">${ct.expiring_30d} in 30 days</span>`;
    } else if (ct.expiring_90d > 0) {
        detail = `<span class="warn">${ct.expiring_90d} in 90 days</span>`;
    } else {
        detail = '<span class="good">None expiring soon</span>';
    }

    const noticeDetail = ct.notice_periods_30d > 0
        ? ` &middot; ${ct.notice_periods_30d} notice period${ct.notice_periods_30d !== 1 ? 's' : ''}`
        : '';

    return `
        <div class="card">
            <div class="card-header">
                <svg class="card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                </svg>
                <span class="card-title">Contracts</span>
            </div>
            <div class="card-metric">${ct.expiring_90d || 0}</div>
            <div class="card-detail">${detail}${noticeDetail}</div>
        </div>
    `;
}

function renderKnowledge(kb) {
    if (!kb) return '';
    let detail = '';
    if (kb.overdue_reviews > 0) {
        detail = `<span class="warn">${kb.overdue_reviews} overdue review${kb.overdue_reviews !== 1 ? 's' : ''}</span>`;
    } else {
        detail = '<span class="good">Reviews up to date</span>';
    }

    return `
        <div class="card">
            <div class="card-header">
                <svg class="card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
                    <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
                </svg>
                <span class="card-title">Knowledge</span>
            </div>
            <div class="card-metric">${(kb.recent_articles || []).length}</div>
            <div class="card-detail">recent articles &middot; ${detail}</div>
        </div>
    `;
}

function renderAssets(as) {
    if (!as) return '';
    let detail = '';
    if (as.not_seen_7d > 0) {
        detail = `<span class="warn">${as.not_seen_7d} not seen 7d</span>`;
    } else {
        detail = '<span class="good">All reporting in</span>';
    }

    return `
        <div class="card">
            <div class="card-header">
                <svg class="card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="8" y1="21" x2="16" y2="21"></line>
                    <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
                <span class="card-title">Assets</span>
            </div>
            <div class="card-metric">${as.total || 0}</div>
            <div class="card-detail">${detail}</div>
        </div>
    `;
}

function renderTasks(tk) {
    if (!tk) return '';
    let detail = '';
    if (tk.overdue > 0) {
        detail = `<span class="highlight">${tk.overdue} overdue</span>`;
    } else if (tk.due_today > 0) {
        detail = `<span class="warn">${tk.due_today} due today</span>`;
    } else {
        detail = '<span class="good">No overdue tasks</span>';
    }

    const total = (tk.todo || 0) + (tk.in_progress || 0);

    return `
        <div class="card">
            <div class="card-header">
                <svg class="card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 11l3 3L22 4"></path>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                </svg>
                <span class="card-title">Tasks</span>
            </div>
            <div class="card-metric">${total}</div>
            <div class="card-detail">active &middot; ${detail}</div>
        </div>
    `;
}
