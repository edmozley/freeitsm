/**
 * FreeITSM Watchtower — Background Service Worker
 * Polls the Watchtower API on a schedule and updates the badge
 */

const DEFAULT_INTERVAL = 5; // minutes

chrome.runtime.onInstalled.addListener(() => {
    setupAlarm();
    fetchDashboard();
});

chrome.runtime.onStartup.addListener(() => {
    setupAlarm();
    fetchDashboard();
});

chrome.alarms.onAlarm.addListener((alarm) => {
    if (alarm.name === 'watchtower-poll') {
        fetchDashboard();
    }
});

// Listen for settings changes
chrome.storage.onChanged.addListener((changes, area) => {
    if (area === 'sync' && (changes.pollInterval || changes.serverUrl || changes.apiKey)) {
        setupAlarm();
        if (changes.serverUrl || changes.apiKey) {
            fetchDashboard();
        }
    }
});

// Access granted on the options page: fetch now rather than at the next poll
chrome.permissions.onAdded.addListener(() => {
    fetchDashboard();
});

// Listen for manual refresh from popup
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.action === 'refresh') {
        fetchDashboard().then(() => sendResponse({ ok: true }));
        return true; // keep channel open for async response
    }
});

/** Recreates the 'watchtower-poll' alarm at the saved poll interval (default 5 minutes). */
async function setupAlarm() {
    await chrome.alarms.clear('watchtower-poll');
    const settings = await chrome.storage.sync.get({ pollInterval: DEFAULT_INTERVAL });
    chrome.alarms.create('watchtower-poll', {
        periodInMinutes: settings.pollInterval
    });
}

/**
 * Fetches get_dashboard_ext.php with the saved API key, stores the result (or the error) in chrome.storage.local for the popup, and sets the toolbar badge to the attention count or '!'.
 */
async function fetchDashboard() {
    const settings = await chrome.storage.sync.get(['serverUrl', 'apiKey']);

    if (!settings.serverUrl || !settings.apiKey) {
        await chrome.storage.local.set({
            dashboardData: null,
            lastError: 'Not configured. Open extension settings to set your server URL and API key.',
            lastFetch: null
        });
        chrome.action.setBadgeText({ text: '!' });
        chrome.action.setBadgeBackgroundColor({ color: '#888' });
        return;
    }

    const url = settings.serverUrl.replace(/\/+$/, '') + '/api/watchtower/get_dashboard_ext.php';

    // Access to the server is granted on the options page (optional host permission). If an upgrade from
    // 1.0.0 did not carry the old blanket access over, pressing Save once in the settings grants it.
    let granted = false;
    try {
        granted = await chrome.permissions.contains({ origins: [new URL(settings.serverUrl).origin + '/*'] });
    } catch (e) {
        granted = false;
    }
    if (!granted) {
        await chrome.storage.local.set({
            dashboardData: null,
            lastError: 'No access to your FreeITSM server yet. Open the extension settings and press Save to allow it.',
            lastFetch: null
        });
        chrome.action.setBadgeText({ text: '!' });
        chrome.action.setBadgeBackgroundColor({ color: '#888' });
        return;
    }

    try {
        const response = await fetch(url, {
            method: 'GET',
            headers: { 'Authorization': settings.apiKey }
        });

        if (response.status === 401 || response.status === 403) {
            await chrome.storage.local.set({
                dashboardData: null,
                lastError: response.status === 401 ? 'API key missing.' : 'Invalid or revoked API key.',
                lastFetch: new Date().toISOString()
            });
            chrome.action.setBadgeText({ text: '!' });
            chrome.action.setBadgeBackgroundColor({ color: '#dc3545' });
            return;
        }

        if (response.status === 429) {
            await chrome.storage.local.set({
                lastError: 'Rate limited. Will retry on next poll.',
                lastFetch: new Date().toISOString()
            });
            chrome.action.setBadgeText({ text: '!' });
            chrome.action.setBadgeBackgroundColor({ color: '#f57c00' });
            return;
        }

        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Unknown error');
        }

        // Compute attention count
        const count = computeAttentionCount(data);

        await chrome.storage.local.set({
            dashboardData: data,
            lastError: null,
            lastFetch: new Date().toISOString()
        });

        // Update badge
        if (count > 0) {
            chrome.action.setBadgeText({ text: count > 99 ? '99+' : String(count) });
            chrome.action.setBadgeBackgroundColor({ color: hasUrgent(data) ? '#dc3545' : '#f57c00' });
        } else {
            chrome.action.setBadgeText({ text: '' });
        }

    } catch (err) {
        await chrome.storage.local.set({
            lastError: 'Connection failed: ' + err.message,
            lastFetch: new Date().toISOString()
        });
        chrome.action.setBadgeText({ text: '!' });
        chrome.action.setBadgeBackgroundColor({ color: '#dc3545' });
    }
}

/**
 * Adds up the items needing attention for the badge - urgent/high and unassigned tickets, unapproved changes, active incidents, contracts expiring in 30 days, overdue reviews, morning checks, and overdue or due-today tasks.
 */
function computeAttentionCount(data) {
    let count = 0;
    if (data.tickets) {
        count += (data.tickets.urgent_high || 0);
        count += (data.tickets.unassigned || 0);
    }
    if (data.changes) {
        count += (data.changes.unapproved || 0);
    }
    if (data.service_status) {
        count += (data.service_status.active_incidents || 0);
    }
    if (data.contracts) {
        count += (data.contracts.expiring_30d || 0);
    }
    if (data.knowledge) {
        count += (data.knowledge.overdue_reviews || 0);
    }
    if (data.morning_checks && data.morning_checks.not_started) {
        count += (data.morning_checks.total_checks || 0);
    } else if (data.morning_checks) {
        // Checks in a status that needs attention. This used to look up a status
        // called 'Fail' — a name FreeITSM has never shipped (the statuses are
        // Green, Amber and Red, and they are renameable), so it always added
        // nothing and the badge silently ignored a morning of failed checks.
        // The server now works this out once and everything reads that.
        count += (data.morning_checks.attention_count || 0);
    }
    if (data.tasks) {
        count += (data.tasks.overdue || 0);
        count += (data.tasks.due_today || 0);
    }
    return count;
}

/**
 * True when there are urgent/high tickets, active incidents or morning checks needing attention - the badge then turns red instead of amber.
 */
function hasUrgent(data) {
    if (data.tickets && data.tickets.urgent_high > 0) return true;
    if (data.service_status && data.service_status.active_incidents > 0) return true;
    if (data.morning_checks && (data.morning_checks.attention_count || 0) > 0) return true;
    return false;
}
