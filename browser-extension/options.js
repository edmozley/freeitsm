/**
 * FreeITSM Watchtower — Options Page
 */

document.addEventListener('DOMContentLoaded', async () => {
    // Load saved settings
    const settings = await chrome.storage.sync.get({
        serverUrl: '',
        apiKey: '',
        pollInterval: 5
    });

    document.getElementById('serverUrl').value = settings.serverUrl;
    document.getElementById('apiKey').value = settings.apiKey;
    document.getElementById('pollInterval').value = settings.pollInterval;

    // Toggle API key visibility
    document.getElementById('toggleKey').addEventListener('click', () => {
        const input = document.getElementById('apiKey');
        input.type = input.type === 'password' ? 'text' : 'password';
    });

    // Save
    document.getElementById('saveBtn').addEventListener('click', async () => {
        const serverUrl = document.getElementById('serverUrl').value.trim().replace(/\/+$/, '');
        const apiKey = document.getElementById('apiKey').value.trim();
        const pollInterval = parseInt(document.getElementById('pollInterval').value, 10);

        if (!serverUrl) {
            showStatus('Please enter a server URL.', 'error');
            return;
        }

        if (!apiKey) {
            showStatus('Please enter an API key.', 'error');
            return;
        }

        if (!await requestServerAccess(serverUrl)) {
            showStatus('Not saved: enter a full address such as https://helpdesk.example.com, and choose Allow when the browser asks for access to it.', 'error');
            return;
        }

        await chrome.storage.sync.set({ serverUrl, apiKey, pollInterval });
        showStatus('Settings saved.' + httpWarning(serverUrl), 'success');
    });

    // Test connection
    document.getElementById('testBtn').addEventListener('click', async () => {
        const serverUrl = document.getElementById('serverUrl').value.trim().replace(/\/+$/, '');
        const apiKey = document.getElementById('apiKey').value.trim();

        if (!serverUrl || !apiKey) {
            showStatus('Enter both server URL and API key before testing.', 'error');
            return;
        }

        if (!await requestServerAccess(serverUrl)) {
            showStatus('Enter a full address such as https://helpdesk.example.com, and choose Allow when the browser asks for access to it.', 'error');
            return;
        }

        showStatus('Testing connection...', 'testing');

        const url = serverUrl + '/api/watchtower/get_dashboard_ext.php';

        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: { 'Authorization': apiKey }
            });

            if (response.status === 401) {
                showStatus('Failed: API key missing or not sent correctly.', 'error');
                return;
            }

            if (response.status === 403) {
                showStatus('Failed: Invalid or revoked API key.', 'error');
                return;
            }

            if (response.status === 429) {
                showStatus('Rate limited. The connection works, but too many requests were sent.', 'error');
                return;
            }

            if (!response.ok) {
                showStatus('Failed: Server returned HTTP ' + response.status, 'error');
                return;
            }

            const data = await response.json();

            if (data.success) {
                showStatus('Connection successful! Watchtower data received.', 'success');
            } else {
                showStatus('Connected, but server returned an error: ' + (data.error || 'Unknown'), 'error');
            }
        } catch (err) {
            showStatus('Connection failed: ' + err.message, 'error');
        }
    });
});

/**
 * Asks the browser for access to this one server only (for example https://helpdesk.example.com/*).
 * TRAP: must be the FIRST await in a click handler - Chrome only shows the prompt during a user gesture.
 * Store reviewers reject (or slow-review) an extension that asks for every website up front, so the
 * manifest lists https/http as OPTIONAL and the user grants their own server here.
 */
async function requestServerAccess(serverUrl) {
    let origin;
    try {
        const u = new URL(serverUrl);
        if (u.protocol !== 'https:' && u.protocol !== 'http:') return false;
        origin = u.origin + '/*';
    } catch (e) {
        return false;
    }
    try {
        return await chrome.permissions.request({ origins: [origin] });
    } catch (e) {
        return false;
    }
}

/** Warning shown with the save message when the server address is plain http. */
function httpWarning(serverUrl) {
    return serverUrl.toLowerCase().startsWith('http://')
        ? ' Warning: this address is not https, so your API key travels unencrypted.'
        : '';
}

/** Shows a success or error message under the settings form. */
function showStatus(msg, type) {
    const el = document.getElementById('statusMsg');
    el.textContent = msg;
    el.className = 'status-msg ' + type;
}
