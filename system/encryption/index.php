<?php
/**
 * System - Encryption Key Management
 * Helps administrators generate and manage the AES-256-GCM encryption key
 */
session_start();
require_once '../../config.php';

$current_page = 'encryption';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Encryption</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .main-container {
            flex: 1;
            background: #f5f7fa;
            overflow-y: auto;
        }

        .encryption-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .page-title {
            font-size: 22px;
            font-weight: 600;
            color: #333;
            margin: 0 0 6px 0;
        }

        .page-subtitle {
            font-size: 13px;
            color: #888;
            margin: 0 0 30px 0;
        }

        /* Status card */
        .status-card {
            background: #fff;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            margin-bottom: 24px;
            border-left: 4px solid #ccc;
        }

        .status-card.status-ok {
            border-left-color: #107c10;
        }

        .status-card.status-warning {
            border-left-color: #ca5010;
        }

        .status-card.status-missing {
            border-left-color: #d32f2f;
        }

        .status-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .status-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .status-icon.ok { background: #e8f5e9; color: #107c10; }
        .status-icon.warning { background: #fff3e0; color: #ca5010; }
        .status-icon.missing { background: #ffebee; color: #d32f2f; }

        .status-icon svg { width: 20px; height: 20px; }

        .status-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }

        .status-detail {
            font-size: 13px;
            color: #666;
            line-height: 1.6;
        }

        .status-detail code {
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
            font-family: 'Consolas', 'Courier New', monospace;
        }

        /* Action area */
        .action-area {
            margin-top: 16px;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn svg { width: 16px; height: 16px; }

        .btn-primary {
            background: #546e7a;
            color: #fff;
        }

        .btn-primary:hover { background: #455a64; }

        .btn-danger {
            background: #fff;
            color: #d32f2f;
            border: 1px solid #d32f2f;
        }

        .btn-danger:hover { background: #ffebee; }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Info sections */
        .info-section {
            background: #fff;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }

        .info-section h3 {
            font-size: 15px;
            font-weight: 600;
            color: #333;
            margin: 0 0 16px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .info-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .info-list li {
            padding: 8px 0;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .info-list li svg {
            width: 16px;
            height: 16px;
            color: #546e7a;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .info-list li + li {
            border-top: 1px solid #f5f5f5;
        }

        /* Warning box */
        .warning-box {
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-radius: 6px;
            padding: 14px 16px;
            margin-top: 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .warning-box svg {
            width: 18px;
            height: 18px;
            color: #f9a825;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .warning-box p {
            margin: 0;
            font-size: 13px;
            color: #6d4c00;
            line-height: 1.5;
        }

        /* Encrypted items grid */
        .encrypted-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .encrypted-group h4 {
            font-size: 13px;
            font-weight: 600;
            color: #546e7a;
            margin: 0 0 8px 0;
        }

        .encrypted-group ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .encrypted-group ul li {
            font-size: 12px;
            color: #666;
            padding: 4px 0;
            font-family: 'Consolas', 'Courier New', monospace;
        }

        .encrypted-group ul li::before {
            content: '';
            display: inline-block;
            width: 6px;
            height: 6px;
            background: #546e7a;
            border-radius: 50%;
            margin-right: 8px;
            vertical-align: middle;
        }

        /* Loading */
        .loading-spinner {
            text-align: center;
            padding: 40px;
            color: #888;
            font-size: 13px;
        }

        /* (no additional styles needed) */
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="encryption-container">
            <h1 class="page-title">Encryption</h1>
            <p class="page-subtitle">Manage the encryption key used to protect sensitive data at rest</p>

            <div id="loading" class="loading-spinner">Checking encryption status...</div>

            <div id="content" style="display: none;">
                <!-- Status card - populated by JS -->
                <div id="statusCard" class="status-card"></div>

                <!-- Instructions -->
                <div class="info-section">
                    <h3>How Encryption Works</h3>
                    <ul class="info-list">
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                            FreeITSM uses <strong>AES-256-GCM</strong> authenticated encryption to protect sensitive data stored in the database, such as API keys, vCenter credentials, and mailbox connection details.
                        </li>
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                            The encryption key is a 64-character hex string (256 bits) stored in a file <strong>outside the web root</strong> so it cannot be accessed via a browser.
                        </li>
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                            Key file location: <code id="keyPathDisplay"><?php require_once $path_prefix . 'includes/encryption.php'; echo htmlspecialchars(ENCRYPTION_KEY_PATH); ?></code>
                        </li>
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            Encrypted values in the database are prefixed with <code>ENC:</code> followed by the base64-encoded ciphertext. Unencrypted values are left as-is, allowing gradual migration.
                        </li>
                    </ul>

                    <div class="warning-box">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                        <p><strong>Back up your encryption key.</strong> If the key is lost, any data encrypted with it cannot be recovered. Store a copy somewhere safe outside this server.</p>
                    </div>
                </div>

                <!-- What's encrypted -->
                <div class="info-section">
                    <h3>What's Encrypted</h3>
                    <div class="encrypted-grid">
                        <div class="encrypted-group">
                            <h4>System Settings</h4>
                            <ul>
                                <li>vcenter_server</li>
                                <li>vcenter_user</li>
                                <li>vcenter_password</li>
                                <li>knowledge_ai_api_key</li>
                                <li>knowledge_openai_api_key</li>
                            </ul>
                        </div>
                        <div class="encrypted-group">
                            <h4>Mailbox Connections</h4>
                            <ul>
                                <li>azure_tenant_id</li>
                                <li>azure_client_id</li>
                                <li>azure_client_secret</li>
                                <li>oauth_redirect_uri</li>
                                <li>imap_server</li>
                                <li>target_mailbox</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    let encryptionStatus = {};

    async function checkStatus() {
        try {
            const resp = await fetch('<?php echo $path_prefix; ?>api/system/check_encryption.php');
            const data = await resp.json();
            if (data.success) {
                encryptionStatus = data;
                renderStatus(data);
            } else {
                showError(data.error);
            }
        } catch (e) {
            showError('Failed to check encryption status');
        }
    }

    function renderStatus(data) {
        document.getElementById('loading').style.display = 'none';
        document.getElementById('content').style.display = 'block';

        const card = document.getElementById('statusCard');

        if (data.key_exists && data.key_valid) {
            card.className = 'status-card status-ok';
            card.innerHTML = `
                <div class="status-header">
                    <div class="status-icon ok">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <span class="status-title">Encryption is configured</span>
                </div>
                <div class="status-detail">
                    The encryption key is present and valid at <code>${escapeHtml(data.key_path)}</code>. Sensitive data is being encrypted at rest using AES-256-GCM.
                </div>
            `;
        } else if (data.key_exists && !data.key_valid) {
            card.className = 'status-card status-warning';
            card.innerHTML = `
                <div class="status-header">
                    <div class="status-icon warning">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    </div>
                    <span class="status-title">Invalid encryption key</span>
                </div>
                <div class="status-detail">
                    A key file was found at <code>${escapeHtml(data.key_path)}</code> but it is not a valid 64-character hex string. The key must be exactly 64 hexadecimal characters (256 bits).
                </div>
                <div class="action-area">
                    <button class="btn btn-primary" onclick="generateKey(true)">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        Generate Valid Key
                    </button>
                </div>
            `;
        } else {
            card.className = 'status-card status-missing';
            card.innerHTML = `
                <div class="status-header">
                    <div class="status-icon missing">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                    </div>
                    <span class="status-title">No encryption key found</span>
                </div>
                <div class="status-detail">
                    No encryption key file exists at <code>${escapeHtml(data.key_path)}</code>. Sensitive data cannot be encrypted until a key is generated. Click the button below to generate one automatically.
                </div>
                <div class="action-area">
                    <button class="btn btn-primary" onclick="generateKey(false)" id="generateBtn">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        Generate Encryption Key
                    </button>
                </div>
            `;
        }
    }

    async function generateKey(overwrite) {
        const btn = event.target.closest('.btn');
        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span>Generating...</span>';

        try {
            const resp = await fetch('<?php echo $path_prefix; ?>api/system/generate_encryption_key.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ overwrite: overwrite })
            });
            const data = await resp.json();
            if (data.success) {
                checkStatus(); // Refresh the page status
            } else {
                alert('Error: ' + data.error);
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        } catch (e) {
            alert('Failed to generate key');
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }

    function showError(msg) {
        document.getElementById('loading').innerHTML = 'Error: ' + escapeHtml(msg);
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Init
    checkStatus();
    </script>
</body>
</html>
