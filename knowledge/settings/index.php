<?php
/**
 * Knowledge Settings - Configure outbound email settings
 */
session_start();
require_once '../../config.php';

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';  // Two levels up from knowledge/settings/
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Knowledge Settings</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* Page-specific overrides for settings page */
        .container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: none;
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            color: #333;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #8764b8;
            box-shadow: 0 0 0 2px rgba(135, 100, 184, 0.1);
        }

        .form-group small {
            display: block;
            margin-top: 4px;
            color: #888;
            font-size: 12px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .radio-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .radio-option {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .radio-option:hover {
            background: #f9f9f9;
        }

        .radio-option.selected {
            border-color: #8764b8;
            background: #f8f5fb;
        }

        .radio-option input[type="radio"] {
            margin-top: 3px;
        }

        .radio-option-content {
            flex: 1;
        }

        .radio-option-title {
            font-weight: 500;
            color: #333;
            margin-bottom: 4px;
        }

        .radio-option-desc {
            font-size: 13px;
            color: #666;
        }

        .smtp-settings {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            display: none;
        }

        .smtp-settings.active {
            display: block;
        }

        .mailbox-settings {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            display: none;
        }

        .mailbox-settings.active {
            display: block;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #8764b8;
            color: white;
        }

        .btn-primary:hover {
            background: #6b4fa2;
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
            border: 1px solid #ddd;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        .btn-test {
            background: #107c10;
            color: white;
        }

        .btn-test:hover {
            background: #0b5c0b;
        }

        .save-message {
            color: #155724;
            margin-left: 15px;
            display: none;
        }

        .save-message.error {
            color: #d13438;
        }

        .test-result {
            margin-top: 15px;
            padding: 15px;
            border-radius: 6px;
            display: none;
        }

        .test-result.success {
            display: block;
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .test-result.error {
            display: block;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="email" onclick="switchTab('email')">Email</button>
            <button class="tab" data-tab="ai" onclick="switchTab('ai')">AI Assistant</button>
            <button class="tab" data-tab="embeddings" onclick="switchTab('embeddings')">Embeddings</button>
            <button class="tab" data-tab="recycle-bin" onclick="switchTab('recycle-bin')">Recycle Bin</button>
        </div>

        <!-- Email Tab -->
        <div class="tab-content active" id="email-tab">
            <div class="section-header">
                <h2>Outbound Email Settings</h2>
            </div>
            <p style="color: #666; margin-bottom: 20px;">Configure how knowledge articles are shared via email. Choose to use an SMTP server or a configured Microsoft 365 mailbox.</p>

            <form id="emailSettingsForm">
                <div class="radio-group">
                    <label class="radio-option" id="optionSmtp">
                        <input type="radio" name="email_method" value="smtp" onchange="toggleEmailMethod('smtp')">
                        <div class="radio-option-content">
                            <div class="radio-option-title">SMTP Server</div>
                            <div class="radio-option-desc">Use a dedicated SMTP server for sending emails</div>
                        </div>
                    </label>

                    <label class="radio-option" id="optionMailbox">
                        <input type="radio" name="email_method" value="mailbox" onchange="toggleEmailMethod('mailbox')">
                        <div class="radio-option-content">
                            <div class="radio-option-title">Microsoft 365 Mailbox</div>
                            <div class="radio-option-desc">Use a configured mailbox from the Tickets module</div>
                        </div>
                    </label>

                    <label class="radio-option" id="optionDisabled">
                        <input type="radio" name="email_method" value="disabled" onchange="toggleEmailMethod('disabled')">
                        <div class="radio-option-content">
                            <div class="radio-option-title">Disabled</div>
                            <div class="radio-option-desc">Email sharing is disabled</div>
                        </div>
                    </label>
                </div>

                <!-- SMTP Settings -->
                <div class="smtp-settings" id="smtpSettings">
                    <h3 style="font-size: 16px; margin-bottom: 15px;">SMTP Configuration</h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="smtpHost">SMTP Server Hostname</label>
                            <input type="text" id="smtpHost" placeholder="e.g., smtp.office365.com">
                        </div>
                        <div class="form-group">
                            <label for="smtpPort">SMTP Port</label>
                            <input type="number" id="smtpPort" placeholder="e.g., 587">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="smtpEncryption">Connection Type</label>
                            <select id="smtpEncryption">
                                <option value="none">None (Not Recommended)</option>
                                <option value="tls" selected>TLS (STARTTLS)</option>
                                <option value="ssl">SSL</option>
                            </select>
                            <small>TLS is recommended for most servers on port 587</small>
                        </div>
                        <div class="form-group">
                            <label for="smtpAuth">Authentication Required</label>
                            <select id="smtpAuth" onchange="toggleSmtpAuth()">
                                <option value="yes" selected>Yes</option>
                                <option value="no">No</option>
                            </select>
                        </div>
                    </div>

                    <div id="smtpAuthFields">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="smtpUsername">SMTP Username</label>
                                <input type="text" id="smtpUsername" placeholder="e.g., noreply@company.com">
                            </div>
                            <div class="form-group">
                                <label for="smtpPassword">SMTP Password</label>
                                <input type="password" id="smtpPassword" placeholder="Enter password">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="smtpFromEmail">From Email Address</label>
                        <input type="email" id="smtpFromEmail" placeholder="e.g., knowledge@company.com">
                        <small>The email address that will appear in the "From" field</small>
                    </div>

                    <div class="form-group">
                        <label for="smtpFromName">From Name</label>
                        <input type="text" id="smtpFromName" placeholder="e.g., Knowledge Base">
                        <small>The name that will appear alongside the email address</small>
                    </div>

                    <button type="button" class="btn btn-test" onclick="testSmtp()">Test SMTP Connection</button>
                    <div class="test-result" id="smtpTestResult"></div>
                </div>

                <!-- Mailbox Settings -->
                <div class="mailbox-settings" id="mailboxSettings">
                    <h3 style="font-size: 16px; margin-bottom: 15px;">Mailbox Selection</h3>

                    <div class="form-group">
                        <label for="selectedMailbox">Select Mailbox</label>
                        <select id="selectedMailbox">
                            <option value="">Loading mailboxes...</option>
                        </select>
                        <small>Choose a mailbox that has been configured in the Tickets module settings</small>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                    <span class="save-message" id="saveMessage">Settings saved!</span>
                </div>
            </form>
        </div>

        <!-- AI Assistant Tab -->
        <div class="tab-content" id="ai-tab">
            <div class="section-header">
                <h2>AI Assistant</h2>
            </div>
            <p style="color: #666; margin-bottom: 20px;">Configure the AI-powered assistant that answers questions based on your knowledge base articles.</p>

            <form id="aiSettingsForm">
                <h3 style="font-size: 16px; margin-bottom: 15px;">Claude API (Chat)</h3>
                <div class="form-group">
                    <label for="aiApiKey">Anthropic API Key</label>
                    <input type="password" id="aiApiKey" placeholder="sk-ant-...">
                    <small>Get your API key from <a href="https://console.anthropic.com/settings/keys" target="_blank" style="color:#8764b8;">console.anthropic.com</a>. Used for answering questions.</small>
                </div>

                <h3 style="font-size: 16px; margin: 25px 0 15px 0; padding-top: 20px; border-top: 1px solid #e0e0e0;">OpenAI API (Embeddings)</h3>
                <div class="form-group">
                    <label for="openaiApiKey">OpenAI API Key</label>
                    <input type="password" id="openaiApiKey" placeholder="sk-proj-...">
                    <small>Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank" style="color:#8764b8;">platform.openai.com</a>. Used for semantic search (finding relevant articles).</small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save AI Settings</button>
                    <button type="button" class="btn btn-test" onclick="testAiConnection()">Test Connection</button>
                    <span class="save-message" id="aiSaveMessage">Settings saved!</span>
                </div>
                <div class="test-result" id="aiTestResult"></div>
            </form>
        </div>

        <!-- Embeddings Tab -->
        <div class="tab-content" id="embeddings-tab">
            <div class="section-header">
                <h2>Article Embeddings</h2>
            </div>
            <p style="color: #666; margin-bottom: 20px;">Generate vector embeddings for your knowledge articles to enable semantic search. This allows the AI to find the most relevant articles based on meaning, not just keywords.</p>

            <div id="embeddingStatus" style="padding: 15px; background: #f9f9f9; border-radius: 6px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong>Embedding Status</strong>
                        <div id="embeddingStats" style="margin-top: 5px; color: #666; font-size: 13px;">Loading...</div>
                    </div>
                    <button type="button" class="btn btn-primary" id="generateEmbeddingsBtn" onclick="generateEmbeddings()">Generate Embeddings</button>
                </div>
            </div>

            <div id="embeddingProgress" style="display: none;">
                <div style="margin-bottom: 10px;">
                    <span id="embeddingProgressText">Processing...</span>
                </div>
                <div style="background: #e0e0e0; border-radius: 4px; height: 8px; overflow: hidden;">
                    <div id="embeddingProgressBar" style="background: #8764b8; height: 100%; width: 0%; transition: width 0.3s;"></div>
                </div>
            </div>

            <div class="test-result" id="embeddingResult"></div>
        </div>

        <!-- Recycle Bin Tab -->
        <div class="tab-content" id="recycle-bin-tab">
            <div class="section-header">
                <h2>Recycle Bin</h2>
            </div>
            <p style="color: #666; margin-bottom: 20px;">Configure how long archived knowledge articles are retained in the recycle bin before automatic permanent deletion.</p>
            <form id="recycleBinSettingsForm">
                <div class="form-group">
                    <label for="recycleBinDays">Auto-delete after (days)</label>
                    <input type="number" id="recycleBinDays" min="0" max="999" value="30" style="max-width: 200px;">
                    <small>Set to 0 to keep archived articles indefinitely. Range: 0-999 days. Default: 30.</small>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Recycle Bin Settings</button>
                    <span class="save-message" id="recycleBinSaveMessage" style="display:none;">Settings saved!</span>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/knowledge/';

        // Switch tabs
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        // Load settings on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadMailboxes();
            loadSettings();
        });

        function toggleEmailMethod(method) {
            // Update radio option styling
            document.querySelectorAll('.radio-option').forEach(opt => opt.classList.remove('selected'));

            if (method === 'smtp') {
                document.getElementById('optionSmtp').classList.add('selected');
                document.getElementById('smtpSettings').classList.add('active');
                document.getElementById('mailboxSettings').classList.remove('active');
            } else if (method === 'mailbox') {
                document.getElementById('optionMailbox').classList.add('selected');
                document.getElementById('smtpSettings').classList.remove('active');
                document.getElementById('mailboxSettings').classList.add('active');
            } else {
                document.getElementById('optionDisabled').classList.add('selected');
                document.getElementById('smtpSettings').classList.remove('active');
                document.getElementById('mailboxSettings').classList.remove('active');
            }
        }

        function toggleSmtpAuth() {
            const authRequired = document.getElementById('smtpAuth').value === 'yes';
            document.getElementById('smtpAuthFields').style.display = authRequired ? 'block' : 'none';
        }

        async function loadMailboxes() {
            try {
                const response = await fetch('../../api/tickets/get_mailboxes.php');
                const data = await response.json();

                const select = document.getElementById('selectedMailbox');
                select.innerHTML = '<option value="">-- Select a mailbox --</option>';

                if (data.success && data.mailboxes) {
                    data.mailboxes.forEach(mailbox => {
                        const option = document.createElement('option');
                        option.value = mailbox.id;
                        option.textContent = `${mailbox.name} (${mailbox.mailbox_email})`;
                        select.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Error loading mailboxes:', error);
            }
        }

        async function loadSettings() {
            try {
                const response = await fetch(API_BASE + 'get_email_settings.php');
                const data = await response.json();

                if (data.success && data.settings) {
                    const s = data.settings;

                    // Set email method
                    const method = s.email_method || 'disabled';
                    document.querySelector(`input[name="email_method"][value="${method}"]`).checked = true;
                    toggleEmailMethod(method);

                    // Set SMTP fields
                    document.getElementById('smtpHost').value = s.smtp_host || '';
                    document.getElementById('smtpPort').value = s.smtp_port || '587';
                    document.getElementById('smtpEncryption').value = s.smtp_encryption || 'tls';
                    document.getElementById('smtpAuth').value = s.smtp_auth || 'yes';
                    document.getElementById('smtpUsername').value = s.smtp_username || '';
                    // Don't populate password for security
                    document.getElementById('smtpFromEmail').value = s.smtp_from_email || '';
                    document.getElementById('smtpFromName').value = s.smtp_from_name || '';
                    toggleSmtpAuth();

                    // Set mailbox
                    if (s.mailbox_id) {
                        document.getElementById('selectedMailbox').value = s.mailbox_id;
                    }
                }
            } catch (error) {
                console.error('Error loading settings:', error);
            }
        }

        document.getElementById('emailSettingsForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const settings = {
                email_method: document.querySelector('input[name="email_method"]:checked').value,
                smtp_host: document.getElementById('smtpHost').value,
                smtp_port: document.getElementById('smtpPort').value,
                smtp_encryption: document.getElementById('smtpEncryption').value,
                smtp_auth: document.getElementById('smtpAuth').value,
                smtp_username: document.getElementById('smtpUsername').value,
                smtp_password: document.getElementById('smtpPassword').value,
                smtp_from_email: document.getElementById('smtpFromEmail').value,
                smtp_from_name: document.getElementById('smtpFromName').value,
                mailbox_id: document.getElementById('selectedMailbox').value
            };

            try {
                const response = await fetch(API_BASE + 'save_email_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings })
                });
                const data = await response.json();

                const msg = document.getElementById('saveMessage');
                if (data.success) {
                    msg.textContent = 'Settings saved!';
                    msg.classList.remove('error');
                    msg.style.display = 'inline';
                    setTimeout(() => msg.style.display = 'none', 3000);
                } else {
                    msg.textContent = 'Error: ' + data.error;
                    msg.classList.add('error');
                    msg.style.display = 'inline';
                }
            } catch (error) {
                console.error('Error saving settings:', error);
            }
        });

        async function testSmtp() {
            const resultDiv = document.getElementById('smtpTestResult');
            resultDiv.className = 'test-result';
            resultDiv.style.display = 'block';
            resultDiv.textContent = 'Testing connection...';

            const settings = {
                smtp_host: document.getElementById('smtpHost').value,
                smtp_port: document.getElementById('smtpPort').value,
                smtp_encryption: document.getElementById('smtpEncryption').value,
                smtp_auth: document.getElementById('smtpAuth').value,
                smtp_username: document.getElementById('smtpUsername').value,
                smtp_password: document.getElementById('smtpPassword').value
            };

            try {
                const response = await fetch(API_BASE + 'test_smtp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(settings)
                });
                const data = await response.json();

                if (data.success) {
                    resultDiv.className = 'test-result success';
                    resultDiv.textContent = 'Connection successful! SMTP server is reachable.';
                } else {
                    resultDiv.className = 'test-result error';
                    resultDiv.textContent = 'Connection failed: ' + data.error;
                }
            } catch (error) {
                resultDiv.className = 'test-result error';
                resultDiv.textContent = 'Error testing connection: ' + error.message;
            }
        }

        // === AI Settings ===

        // Load AI API key and recycle bin settings on page load
        loadAiSettings();
        loadEmbeddingStats();
        loadRecycleBinSettings();

        async function loadAiSettings() {
            try {
                const response = await fetch(API_BASE + 'get_email_settings.php');
                const data = await response.json();
                if (data.success && data.settings) {
                    if (data.settings.ai_api_key) {
                        document.getElementById('aiApiKey').placeholder = 'Key is saved (enter new key to change)';
                    }
                    if (data.settings.openai_api_key) {
                        document.getElementById('openaiApiKey').placeholder = 'Key is saved (enter new key to change)';
                    }
                }
            } catch (error) {
                console.error('Error loading AI settings:', error);
            }
        }

        document.getElementById('aiSettingsForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const anthropicKey = document.getElementById('aiApiKey').value.trim();
            const openaiKey = document.getElementById('openaiApiKey').value.trim();

            if (!anthropicKey && !openaiKey) {
                const msg = document.getElementById('aiSaveMessage');
                msg.textContent = 'Please enter at least one API key';
                msg.classList.add('error');
                msg.style.display = 'inline';
                return;
            }

            const settings = {};
            if (anthropicKey) settings.ai_api_key = anthropicKey;
            if (openaiKey) settings.openai_api_key = openaiKey;

            try {
                const response = await fetch(API_BASE + 'save_email_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings })
                });
                const data = await response.json();

                const msg = document.getElementById('aiSaveMessage');
                if (data.success) {
                    msg.textContent = 'AI settings saved!';
                    msg.classList.remove('error');
                    msg.style.display = 'inline';
                    if (anthropicKey) {
                        document.getElementById('aiApiKey').value = '';
                        document.getElementById('aiApiKey').placeholder = 'Key is saved (enter new key to change)';
                    }
                    if (openaiKey) {
                        document.getElementById('openaiApiKey').value = '';
                        document.getElementById('openaiApiKey').placeholder = 'Key is saved (enter new key to change)';
                    }
                    setTimeout(() => msg.style.display = 'none', 3000);
                } else {
                    msg.textContent = 'Error: ' + data.error;
                    msg.classList.add('error');
                    msg.style.display = 'inline';
                }
            } catch (error) {
                console.error('Error saving AI settings:', error);
            }
        });

        async function testAiConnection() {
            const resultDiv = document.getElementById('aiTestResult');
            resultDiv.className = 'test-result';
            resultDiv.style.display = 'block';
            resultDiv.textContent = 'Testing connection to Claude API...';

            try {
                const response = await fetch(API_BASE + 'ai_chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ question: 'List the titles of all knowledge articles you have access to.' })
                });
                const data = await response.json();

                if (data.success) {
                    resultDiv.className = 'test-result success';
                    resultDiv.textContent = 'Connection successful! Searched ' + data.articles_searched + ' articles. The AI assistant is ready to use.';
                } else {
                    resultDiv.className = 'test-result error';
                    resultDiv.textContent = 'Error: ' + data.error;
                }
            } catch (error) {
                resultDiv.className = 'test-result error';
                resultDiv.textContent = 'Connection error: ' + error.message;
            }
        }

        // === Embedding Functions ===

        async function loadEmbeddingStats() {
            try {
                const response = await fetch(API_BASE + 'get_embedding_stats.php');
                const data = await response.json();

                const statsDiv = document.getElementById('embeddingStats');
                if (data.success) {
                    const { total, with_embeddings, without_embeddings } = data.stats;
                    if (total === 0) {
                        statsDiv.textContent = 'No published articles found.';
                    } else if (without_embeddings === 0) {
                        statsDiv.innerHTML = `<span style="color: #155724;">All ${total} articles have embeddings</span>`;
                    } else {
                        statsDiv.innerHTML = `${with_embeddings} of ${total} articles have embeddings. <strong>${without_embeddings} need generation.</strong>`;
                    }
                } else {
                    statsDiv.textContent = 'Error loading stats: ' + data.error;
                }
            } catch (error) {
                document.getElementById('embeddingStats').textContent = 'Error loading stats';
            }
        }

        async function generateEmbeddings() {
            const btn = document.getElementById('generateEmbeddingsBtn');
            const progressDiv = document.getElementById('embeddingProgress');
            const progressBar = document.getElementById('embeddingProgressBar');
            const progressText = document.getElementById('embeddingProgressText');
            const resultDiv = document.getElementById('embeddingResult');

            btn.disabled = true;
            btn.textContent = 'Generating...';
            progressDiv.style.display = 'block';
            resultDiv.className = 'test-result';
            resultDiv.style.display = 'none';

            try {
                // Get list of articles needing embeddings
                const listResponse = await fetch(API_BASE + 'get_articles_for_embedding.php');
                const listData = await listResponse.json();

                if (!listData.success) {
                    throw new Error(listData.error || 'Failed to get articles');
                }

                const articles = listData.articles;
                if (articles.length === 0) {
                    progressDiv.style.display = 'none';
                    resultDiv.className = 'test-result success';
                    resultDiv.textContent = 'All articles already have embeddings!';
                    btn.disabled = false;
                    btn.textContent = 'Generate Embeddings';
                    return;
                }

                let processed = 0;
                let errors = 0;

                for (const article of articles) {
                    progressText.textContent = `Processing "${article.title}" (${processed + 1}/${articles.length})...`;
                    progressBar.style.width = ((processed / articles.length) * 100) + '%';

                    try {
                        const response = await fetch(API_BASE + 'generate_embedding.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ article_id: article.id })
                        });
                        const data = await response.json();

                        if (!data.success) {
                            console.error(`Error for article ${article.id}:`, data.error);
                            errors++;
                        }
                    } catch (err) {
                        console.error(`Error for article ${article.id}:`, err);
                        errors++;
                    }

                    processed++;
                }

                progressBar.style.width = '100%';
                progressDiv.style.display = 'none';

                if (errors === 0) {
                    resultDiv.className = 'test-result success';
                    resultDiv.textContent = `Successfully generated embeddings for ${processed} articles!`;
                } else {
                    resultDiv.className = 'test-result error';
                    resultDiv.textContent = `Completed with ${errors} errors out of ${processed} articles.`;
                }

                loadEmbeddingStats();

            } catch (error) {
                progressDiv.style.display = 'none';
                resultDiv.className = 'test-result error';
                resultDiv.textContent = 'Error: ' + error.message;
            }

            btn.disabled = false;
            btn.textContent = 'Generate Embeddings';
        }

        // === Recycle Bin Settings ===

        async function loadRecycleBinSettings() {
            try {
                const response = await fetch(API_BASE + 'get_email_settings.php');
                const data = await response.json();
                if (data.success && data.settings) {
                    const days = data.settings.recycle_bin_days ?? 30;
                    document.getElementById('recycleBinDays').value = days;
                }
            } catch (error) {
                console.error('Error loading recycle bin settings:', error);
            }
        }

        document.getElementById('recycleBinSettingsForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const parsed = parseInt(document.getElementById('recycleBinDays').value, 10);
            const days = Math.max(0, Math.min(999, isNaN(parsed) ? 30 : parsed));
            document.getElementById('recycleBinDays').value = days;

            try {
                const response = await fetch(API_BASE + 'save_email_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: { recycle_bin_days: days } })
                });
                const data = await response.json();

                const msg = document.getElementById('recycleBinSaveMessage');
                if (data.success) {
                    msg.textContent = 'Settings saved!';
                    msg.style.color = '#155724';
                    msg.style.display = 'inline';
                    setTimeout(() => msg.style.display = 'none', 3000);
                } else {
                    msg.textContent = 'Error: ' + data.error;
                    msg.style.color = '#d13438';
                    msg.style.display = 'inline';
                }
            } catch (error) {
                console.error('Error saving recycle bin settings:', error);
            }
        });
    </script>
</body>
</html>
