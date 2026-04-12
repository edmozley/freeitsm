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

// Listen for manual refresh from popup
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.action === 'refresh') {
        fetchDashboard().then(() => sendResponse({ ok: true }));
        return true; // keep channel open for async response
    }
});

async function setupAlarm() {
    await chrome.alarms.clear('watchtower-poll');
    const settings = await chrome.storage.sync.get({ pollInterval: DEFAULT_INTERVAL });
    chrome.alarms.create('watchtower-poll', {
        periodInMinutes: settings.pollInterval
    });
}

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
    } else if (data.morning_checks && data.morning_checks.statuses) {
        count += (data.morning_checks.statuses['Fail'] || 0);
    }
    if (data.tasks) {
        count += (data.tasks.overdue || 0);
        count += (data.tasks.due_today || 0);
    }
    return count;
}

function hasUrgent(data) {
    if (data.tickets && data.tickets.urgent_high > 0) return true;
    if (data.service_status && data.service_status.active_incidents > 0) return true;
    if (data.morning_checks && data.morning_checks.statuses && data.morning_checks.statuses['Fail'] > 0) return true;
    return false;
}
