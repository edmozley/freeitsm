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

        await chrome.storage.sync.set({ serverUrl, apiKey, pollInterval });
        showStatus('Settings saved.', 'success');
    });

    // Test connection
    document.getElementById('testBtn').addEventListener('click', async () => {
        const serverUrl = document.getElementById('serverUrl').value.trim().replace(/\/+$/, '');
        const apiKey = document.getElementById('apiKey').value.trim();

        if (!serverUrl || !apiKey) {
            showStatus('Enter both server URL and API key before testing.', 'error');
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

function showStatus(msg, type) {
    const el = document.getElementById('statusMsg');
    el.textContent = msg;
    el.className = 'status-msg ' + type;
}
