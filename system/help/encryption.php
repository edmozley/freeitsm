<?php
/**
 * System Help — Encryption.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'encryption';
require __DIR__ . '/_top.php';
?>
<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What this area does</h3></div>
    <p class="syshelp-lead">FreeITSM holds a number of secrets it needs to connect to other systems — your vCenter password, AI API keys, and the Microsoft/Azure credentials used to read a mailbox. Rather than store these in plain text, it encrypts them with a single <strong>AES-256-GCM</strong> key.</p>
    <p>This page is where you generate and check that key. You only need it once: create the key, keep a safe backup, and the rest happens automatically. Encrypted values are written to the database with an <code>ENC:</code> prefix so FreeITSM knows which fields to decrypt on the fly.</p>
    <div class="syshelp-callout info">The key lives in a single file on the server, outside the database. FreeITSM reads it whenever it needs to decrypt a stored secret, so the file must stay readable by the web server.</div>
</div>

<!-- 2. Key status -->
<div class="syshelp-section" id="status">
    <div class="syshelp-section-header"><h3>Reading the key status</h3></div>
    <p>When you open the page it checks the key file and shows one of three states:</p>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>Key present and valid</h4>
            <p>The green state. A correct key file exists at its path and FreeITSM can encrypt and decrypt normally. Nothing to do.</p>
        </div>
        <div class="syshelp-card">
            <h4>Key invalid</h4>
            <p>The amber state. A file exists but isn't a usable AES-256 key. You can regenerate a valid one — but see the warning before you do.</p>
        </div>
        <div class="syshelp-card">
            <h4>Key missing</h4>
            <p>The red state. No key file has been created yet. Secrets can't be encrypted until you generate one.</p>
        </div>
    </div>
    <p>The status card also shows the exact file path the key is expected at, so you know which file to back up or restore.</p>
</div>

<!-- 3. Setup -->
<div class="syshelp-section" id="setup">
    <div class="syshelp-section-header"><h3>Generating a key</h3></div>
    <p class="syshelp-lead">If the status is <strong>missing</strong>, generating a key is a one-click, one-time step.</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><div class="syshelp-step-num">1</div><div><strong>Open this page.</strong> If no key exists you'll see the red <em>missing</em> status with a <strong>Generate</strong> button.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">2</div><div><strong>Click Generate.</strong> FreeITSM creates a fresh random AES-256 key and writes it to the key file on the server. The status refreshes to green when it succeeds.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">3</div><div><strong>Back up the key file immediately</strong> (see below) before you start saving any credentials that depend on it.</div></div>
    </div>
    <div class="syshelp-callout warn"><strong>Regenerating replaces the existing key.</strong> If a key is already in use, generating a new one means anything encrypted with the old key can no longer be decrypted. Only regenerate when the current key is genuinely lost or invalid, and be ready to re-enter the affected credentials afterwards.</div>
</div>

<!-- 4. What's encrypted -->
<div class="syshelp-section" id="whats">
    <div class="syshelp-section-header"><h3>What gets encrypted</h3></div>
    <p>The key protects the credential fields FreeITSM stores for its integrations:</p>
    <h4>System settings</h4>
    <ul>
        <li>vCenter server, user and password (<code>vcenter_server</code>, <code>vcenter_user</code>, <code>vcenter_password</code>)</li>
        <li>Knowledge AI keys (<code>knowledge_ai_api_key</code>, <code>knowledge_openai_api_key</code>)</li>
    </ul>
    <h4>Mailbox / email connector</h4>
    <ul>
        <li>Azure app credentials (<code>azure_tenant_id</code>, <code>azure_client_id</code>, <code>azure_client_secret</code>)</li>
        <li>OAuth redirect URI, IMAP server and target mailbox (<code>oauth_redirect_uri</code>, <code>imap_server</code>, <code>target_mailbox</code>)</li>
    </ul>
    <p>These are encrypted automatically when you save them in their own settings pages — there's nothing to encrypt by hand here. Stored values carry an <code>ENC:</code> prefix in the database to mark them as encrypted.</p>
</div>

<!-- 5. Backup -->
<div class="syshelp-section highlight" id="backup">
    <div class="syshelp-section-header"><h3>Backups &amp; recovery</h3></div>
    <p class="syshelp-lead">The key file is the one thing you must protect. Encrypted secrets are useless without it, and there is no way to recover them if it's gone.</p>
    <ul>
        <li><strong>Back up the key file</strong> to a secure location as soon as you generate it, and keep that backup with the same care as the secrets themselves.</li>
        <li><strong>Include it in your disaster-recovery plan.</strong> A database backup alone won't restore your integrations — you need both the database and the matching key file.</li>
        <li><strong>If the key is lost or changed</strong>, previously encrypted credentials can no longer be decrypted. You'll need to restore the original key file, or re-enter the affected passwords and keys from scratch.</li>
    </ul>
    <div class="syshelp-callout ok"><strong>Rule of thumb:</strong> generate the key once, back the file up immediately, store the backup safely, and don't regenerate unless you've truly lost it.</div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
