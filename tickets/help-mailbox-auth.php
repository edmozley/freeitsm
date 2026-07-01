<?php
/**
 * Tickets — Mailbox Authentication Admin Guide
 * Standalone deep-dive linked from the main tickets help page (Settings section).
 * Covers the two Microsoft auth modes, the "reading from the right inbox" safeguards,
 * email aliases, OAuth scopes/permissions, Azure setup, and troubleshooting.
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mailbox Authentication — Admin Guide</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=13">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .tk-help-container {
            display: flex;
            height: calc(100vh - 48px);
            background: var(--surface-2, #f5f5f5);
        }
        .tk-help-sidebar {
            width: 280px;
            background: var(--surface, white);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
            overflow-y: auto;
        }
        .tk-help-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .tk-help-back-link {
            font-size: 12px;
            color: var(--accent, #0078d4);
            text-decoration: none;
            margin-bottom: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .tk-help-back-link:hover { text-decoration: underline; }

        .tk-help-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .tk-help-nav-link:hover { background: var(--surface-2, #f5f5f5); color: var(--text, #333); }
        .tk-help-nav-link.active { background: var(--accent-soft, #e3f2fd); color: var(--accent-hover, #005a9e); font-weight: 600; }
        .tk-help-nav-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--border-soft, #eee);
            color: var(--text-dim, #888);
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }
        .tk-help-nav-link.active .tk-help-nav-num { background: var(--accent, #0078d4); color: var(--on-accent, white); }

        .tk-help-main { flex: 1; overflow-y: auto; }

        .tk-help-hero {
            background: linear-gradient(135deg, var(--accent, #0078d4) 0%, var(--accent-hover, #005a9e) 50%, #003d6b 100%);
            color: var(--on-accent, white);
            padding: 40px 48px 36px;
            text-align: center;
        }
        [data-theme-mode="dark"] .tk-help-hero {
            background: linear-gradient(135deg, #1f3f63 0%, #15304c 50%, #0c2031 100%);
        }
        .tk-help-hero h2 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .tk-help-hero p { margin: 0; font-size: 15px; opacity: 0.85; }

        .tk-help-content { max-width: 1120px; margin: 0 auto; padding: 10px 48px 48px; }

        .tk-help-section {
            padding: 28px 0;
            border-bottom: 1px solid var(--border-soft, #eee);
            scroll-margin-top: 20px;
        }
        .tk-help-section:last-child { border-bottom: none; padding-bottom: 0; }
        .tk-help-section-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 16px;
        }
        .tk-help-section-header h3 { margin: 0; font-size: 18px; color: var(--text, #333); }
        .tk-help-section-header p { margin: 6px 0 0; font-size: 14px; color: var(--text-muted, #666); line-height: 1.6; }
        .tk-help-section > p {
            font-size: 14px;
            color: var(--text-muted, #555);
            line-height: 1.7;
            margin: 0 0 14px;
        }
        .tk-help-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--accent-soft, #e3f2fd);
            color: var(--accent-hover, #005a9e);
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .tk-help-section h4 { font-size: 15px; color: var(--text, #333); margin: 22px 0 10px; }
        .tk-help-section h5 { font-size: 14px; color: var(--text-muted, #444); margin: 16px 0 8px; }
        .tk-help-section ul, .tk-help-section ol {
            font-size: 14px; color: var(--text-muted, #555); line-height: 1.7; margin: 8px 0 8px 22px;
        }
        .tk-help-section ul li, .tk-help-section ol li { margin-bottom: 6px; }

        .tk-help-fields { display: flex; flex-direction: column; gap: 8px; margin: 10px 0; }
        .tk-help-fields div {
            padding: 10px 14px;
            background: var(--surface-2, #fafafa);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.5;
        }
        .tk-help-fields div strong { color: var(--text, #333); }

        .tk-help-tip {
            font-size: 13px !important;
            color: var(--accent-hover, #005a9e) !important;
            background: var(--accent-soft, #e3f2fd);
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--accent, #0078d4);
            margin: 14px 0;
        }
        .tk-help-warn {
            font-size: 13px;
            color: var(--warning-text, #92400e);
            background: var(--warning-bg, #fef3c7);
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid var(--warning-border, #f59e0b);
            margin: 14px 0;
            line-height: 1.5;
        }

        .tk-help-option-card {
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            padding: 14px 16px;
            margin: 10px 0;
            background: var(--surface-2, #fafafa);
        }
        .tk-help-option-card .label {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
        }
        .tk-help-option-card .label.delegated { background: var(--accent-soft, #e3f2fd); color: var(--accent-hover, #005a9e); }
        .tk-help-option-card .label.apponly   { background: #ede7f6; color: #5e35b1; }
        .tk-help-option-card strong { color: var(--text, #333); }
        .tk-help-option-card p { font-size: 13px; color: var(--text-muted, #555); line-height: 1.55; margin: 6px 0 0; }

        .tk-help-code {
            font-family: ui-monospace, "Cascadia Mono", "Source Code Pro", Menlo, Consolas, monospace;
            background: var(--surface-2, #f5f5f5);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12.5px;
            color: var(--text, #333);
        }
        .tk-help-code-block {
            font-family: ui-monospace, "Cascadia Mono", "Source Code Pro", Menlo, Consolas, monospace;
            background: #1e293b;
            color: #e2e8f0;
            padding: 14px 16px;
            border-radius: 8px;
            font-size: 12.5px;
            line-height: 1.55;
            overflow-x: auto;
            margin: 12px 0;
            white-space: pre;
        }

        table.tk-help-table { width: 100%; border-collapse: collapse; margin: 14px 0; font-size: 13px; }
        table.tk-help-table th {
            text-align: left;
            background: var(--surface-2, #f5f5f5);
            color: var(--text-muted, #444);
            padding: 10px 12px;
            border-bottom: 2px solid var(--border, #e0e0e0);
            font-weight: 600;
        }
        table.tk-help-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-soft, #eee);
            color: var(--text-muted, #555);
            vertical-align: top;
            line-height: 1.5;
        }
        table.tk-help-table .badge {
            display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; white-space: nowrap;
        }
        .badge.green { background: #e8f5e9; color: #2e7d32; }
        .badge.amber { background: var(--warning-bg, #fef3c7); color: var(--warning-text, #92400e); }
        .badge.red   { background: #fee2e2; color: #991b1b; }
        .badge.blue  { background: var(--accent-soft, #e3f2fd); color: #1565c0; }
        .badge.grey  { background: var(--border-soft, #eee); color: var(--text-muted, #666); }

        @media (max-width: 900px) {
            .tk-help-sidebar { display: none; }
            .tk-help-content { padding: 10px 24px 40px; }
            .tk-help-hero { padding: 30px 24px; }
        }
    </style>
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="tk-help-container">
    <!-- Left pane navigation -->
    <div class="tk-help-sidebar">
        <a href="help.php" class="tk-help-back-link">&larr; Back to Tickets help</a>
        <h3>Mailbox Authentication</h3>
        <a href="#overview" class="tk-help-nav-link active" data-section="overview">
            <span class="tk-help-nav-num">1</span> Overview
        </a>
        <a href="#modes" class="tk-help-nav-link" data-section="modes">
            <span class="tk-help-nav-num">2</span> Delegated vs App-only
        </a>
        <a href="#safeguards" class="tk-help-nav-link" data-section="safeguards">
            <span class="tk-help-nav-num">3</span> Right-inbox safeguards
        </a>
        <a href="#aliases" class="tk-help-nav-link" data-section="aliases">
            <span class="tk-help-nav-num">4</span> Email aliases
        </a>
        <a href="#scopes" class="tk-help-nav-link" data-section="scopes">
            <span class="tk-help-nav-num">5</span> Scopes &amp; permissions 101
        </a>
        <a href="#azure-setup" class="tk-help-nav-link" data-section="azure-setup">
            <span class="tk-help-nav-num">6</span> Azure app registration
        </a>
        <a href="#add-mailbox" class="tk-help-nav-link" data-section="add-mailbox">
            <span class="tk-help-nav-num">7</span> Add &amp; verify a mailbox
        </a>
        <a href="#google" class="tk-help-nav-link" data-section="google">
            <span class="tk-help-nav-num">8</span> Google Workspace
        </a>
        <a href="#troubleshooting" class="tk-help-nav-link" data-section="troubleshooting">
            <span class="tk-help-nav-num">9</span> Troubleshooting
        </a>
    </div>

    <!-- Main content -->
    <div class="tk-help-main" id="helpMain">
        <div class="tk-help-hero">
            <h2>Mailbox Authentication</h2>
            <p>Connecting FreeITSM to a mailbox to turn email into tickets — safely, from the right inbox.</p>
        </div>

        <div class="tk-help-content">

            <!-- 1. Overview -->
            <div class="tk-help-section" id="overview">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">1</span>
                    <div>
                        <h3>Overview</h3>
                        <p>What a mailbox connection does, and the choices you'll make setting one up.</p>
                    </div>
                </div>
                <p>FreeITSM reads inbound email into tickets and sends replies by connecting to a mailbox. Two providers are supported: <strong>Microsoft 365</strong> (via the Microsoft Graph API) and <strong>Google Workspace</strong> (via the Gmail API). Both use OAuth 2.0 — no plaintext mailbox passwords are ever stored.</p>
                <p>For Microsoft 365 there are two ways to authenticate, chosen per mailbox with the <strong>Authentication</strong> dropdown in the mailbox modal:</p>
                <div class="tk-help-fields">
                    <div><strong>Delegated</strong> — you sign in once <em>as the mailbox account</em>; FreeITSM then acts as that user and reads their inbox (Graph <span class="tk-help-code">/me</span>).</div>
                    <div><strong>App-only</strong> — no sign-in; the app authenticates itself with its own client ID + secret and reads the configured mailbox directly (Graph <span class="tk-help-code">/users/&lt;address&gt;</span>).</div>
                </div>
                <p>Configure everything below under <strong>Tickets &rarr; Settings &rarr; Mailboxes</strong>.</p>
                <p class="tk-help-tip">In a hurry? A dedicated mailbox where the sign-in name equals the email address (e.g. a <span class="tk-help-code">support@</span> service-desk mailbox) is the simplest, most robust setup — use Delegated, sign in as that mailbox, done.</p>
            </div>

            <!-- 2. Delegated vs App-only -->
            <div class="tk-help-section" id="modes">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">2</span>
                    <div>
                        <h3>Delegated vs App-only — which to use</h3>
                        <p>Both are first-class. The right choice depends on the mailbox and your Azure access.</p>
                    </div>
                </div>

                <table class="tk-help-table">
                    <tr><th style="width:30%;"></th><th>Delegated (sign in as the mailbox)</th><th>App-only (client credentials)</th></tr>
                    <tr><td><strong>Acts as</strong></td><td>A user — whoever signed in</td><td>The application itself (no user)</td></tr>
                    <tr><td><strong>How you connect</strong></td><td>Click <strong>Authenticate</strong> and sign in via Microsoft once</td><td>No sign-in — uses the app's client ID + secret</td></tr>
                    <tr><td><strong>Reads which inbox</strong></td><td>The signed-in user's inbox (<span class="tk-help-code">/me</span>)</td><td>The target mailbox you typed (<span class="tk-help-code">/users/&lt;address&gt;</span>)</td></tr>
                    <tr><td><strong>Azure permission type</strong></td><td>Delegated permissions</td><td>Application permissions</td></tr>
                    <tr><td><strong>Admin consent?</strong></td><td>Usually not</td><td><strong>Yes</strong> — an admin must grant it</td></tr>
                    <tr><td><strong>Survives the person leaving?</strong></td><td>No — tied to a sign-in</td><td>Yes — not tied to any person</td></tr>
                </table>

                <div class="tk-help-option-card">
                    <span class="label delegated">Use Delegated when</span>
                    <p>You're setting up quickly and have the mailbox's sign-in credentials; the target mailbox is a real account you can sign in as; or you don't have a Global Admin to hand to grant application consent.</p>
                </div>
                <div class="tk-help-option-card">
                    <span class="label apponly">Use App-only when</span>
                    <p>The mailbox is a <strong>shared / service mailbox</strong> nobody logs into; you don't want the connection tied to one person's sign-in (no breakage when staff leave); or you want it to be <strong>impossible</strong> to read the wrong inbox. Requires a Global Admin to grant consent once.</p>
                </div>
                <p class="tk-help-tip">Rule of thumb: for a long-lived, hands-off service-desk mailbox, <strong>App-only</strong> is the cleaner choice. For a quick start, <strong>Delegated</strong> is fine — just make sure you sign in <em>as the mailbox</em>, not as yourself.</p>
            </div>

            <!-- 3. Safeguards -->
            <div class="tk-help-section" id="safeguards">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">3</span>
                    <div>
                        <h3>"Reading from the right inbox" safeguards</h3>
                        <p>How FreeITSM stops a delegated mailbox quietly reading the wrong account.</p>
                    </div>
                </div>
                <p>Delegated mode has a sharp edge: the token belongs to <em>whoever signed in</em>, and it reads <em>their</em> inbox. If you sign in as the wrong account — or change a mailbox's target address without re-authenticating — FreeITSM could read the wrong mail while the label says otherwise. These safeguards prevent that:</p>
                <ol>
                    <li><strong>It records who actually signed in.</strong> On authentication, FreeITSM captures the signed-in account's full set of addresses (primary, UPN and aliases). The primary is shown in the UI; the whole set is kept for matching.</li>
                    <li><strong>It checks before every read and send.</strong> If the configured target isn't one of the signed-in account's addresses, the operation is <strong>blocked</strong> with a clear message rather than silently reading the wrong inbox.</li>
                    <li><strong>Changing the address invalidates the sign-in.</strong> Edit a mailbox's target (or switch its auth mode) and the stored identity is cleared, forcing a fresh sign-in — a stale token can't keep reading the old inbox.</li>
                    <li><strong>The list shows you the truth.</strong> Each mailbox row carries a plain-language status (see below).</li>
                </ol>

                <table class="tk-help-table">
                    <tr><th style="width:22%;">Badge</th><th>Meaning</th></tr>
                    <tr><td><span class="badge green">Reading from X &#10003;</span></td><td>Signed-in account matches the target (or one of its aliases) — all good.</td></tr>
                    <tr><td><span class="badge blue">App-only</span></td><td>Reads the target directly via client credentials.</td></tr>
                    <tr><td><span class="badge amber">Unverified</span></td><td>Authenticated, but the account hasn't been confirmed yet (e.g. authenticated under an older version). Harmless and self-healing — see the tip below.</td></tr>
                    <tr><td><span class="badge red">&#9888; Wrong account</span></td><td>The signed-in account doesn't own the target address — blocked until you re-authenticate or switch to app-only.</td></tr>
                </table>

                <p class="tk-help-tip"><strong>"Unverified" is harmless and self-healing.</strong> It just means the identity hasn't been recorded yet. Click the <strong>Check emails</strong> (envelope) icon on that row once; the identity is back-filled and the badge settles to green &#10003; (or red &#9888; if it genuinely is the wrong account). Reload the list to see it update.</p>
            </div>

            <!-- 4. Aliases -->
            <div class="tk-help-section" id="aliases">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">4</span>
                    <div>
                        <h3>Email aliases (the UPN-vs-email trap)</h3>
                        <p>Why an alias target doesn't get falsely flagged as "Wrong account".</p>
                    </div>
                </div>
                <p>Microsoft 365 has two identifiers that both look like email addresses, and they can differ:</p>
                <div class="tk-help-fields">
                    <div><strong>UPN / sign-in name</strong> — what you log in with, e.g. <span class="tk-help-code">edmozley@contoso.com</span></div>
                    <div><strong>Primary SMTP / alias</strong> — your actual email address(es), e.g. <span class="tk-help-code">ed@contoso.com</span> as a friendlier alias</div>
                </div>
                <p>The access token only carries the account's <strong>UPN / primary</strong> address — not its aliases. So if a mailbox's target is an <em>alias</em> (e.g. <span class="tk-help-code">ed@</span> on the <span class="tk-help-code">edmozley@</span> mailbox), a naive exact-match would wrongly cry "Wrong account" even though it's the same inbox.</p>
                <p>FreeITSM avoids that: on sign-in it reads the mailbox's <strong>full address list</strong> (primary, UPN and every alias, via Graph <span class="tk-help-code">proxyAddresses</span>) and accepts the target if it matches <strong>any</strong> of them. So:</p>
                <div class="tk-help-fields">
                    <div>Target <span class="tk-help-code">ed@</span> while signed in as <span class="tk-help-code">edmozley@</span> &rarr; <span class="badge green">allowed</span> (alias of the same mailbox)</div>
                    <div>Target <span class="tk-help-code">support@</span> while signed in as <span class="tk-help-code">edmozley@</span> &rarr; <span class="badge red">blocked</span> (genuinely different mailbox)</div>
                </div>
                <p>Reading the alias list needs the lightweight <span class="tk-help-code">User.Read</span> scope (see next section). Without it, FreeITSM falls back to matching the primary address only — everything still works, you just can't use a non-primary alias as the target.</p>
                <p class="tk-help-warn">If you point a mailbox at an alias and it still says &#9888; Wrong account, it was almost certainly authenticated <strong>without</strong> <span class="tk-help-code">User.Read</span>. Add it to the scopes and re-authenticate, or use the mailbox's primary address as the target instead.</p>
            </div>

            <!-- 5. Scopes 101 -->
            <div class="tk-help-section" id="scopes">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">5</span>
                    <div>
                        <h3>Scopes &amp; permissions 101</h3>
                        <p>Plain-English: scopes, delegated vs application permissions, admin consent.</p>
                    </div>
                </div>

                <h4>What's a scope?</h4>
                <p>A <strong>scope</strong> (or permission) is a single capability you ask Microsoft for, like <span class="tk-help-code">Mail.Read</span>. The token Microsoft issues is stamped with exactly the scopes you requested and nothing more — like a backstage pass listing which doors it opens. FreeITSM asks for <span class="tk-help-code">Mail.Read</span>, <span class="tk-help-code">Mail.ReadWrite</span>, <span class="tk-help-code">Mail.Send</span>, the lightweight <span class="tk-help-code">User.Read</span>, plus <span class="tk-help-code">openid</span> / <span class="tk-help-code">email</span> / <span class="tk-help-code">offline_access</span> (sign-in plumbing).</p>

                <h4>Delegated permission vs Application permission</h4>
                <p>Same-sounding permission, two very different flavours — this is where everyone trips up:</p>
                <div class="tk-help-fields">
                    <div><strong>Delegated</strong> — the app acts <em>on behalf of a signed-in user</em>, limited to what that user can already reach. <span class="tk-help-code">Mail.Read</span> delegated = "read the mail of whoever signed in." Used by <strong>Delegated</strong> mode.</div>
                    <div><strong>Application</strong> — the app acts <em>as itself, with no user</em>. <span class="tk-help-code">Mail.ReadWrite</span> application = "read/write mail in mailboxes the app is allowed to." Used by <strong>App-only</strong> mode.</div>
                </div>
                <p>The same scope name appears in <strong>both</strong> lists in Azure. For app-only you must add the <strong>Application</strong> versions; the delegated ones won't work for client credentials, and vice-versa.</p>

                <h4>What is "admin consent"?</h4>
                <p>Some permissions are powerful enough that an ordinary user can't approve them for the whole organisation — a <strong>Global Administrator</strong> must click <strong>"Grant admin consent"</strong> in Azure. <strong>All application permissions</strong> need admin consent (there's no user to consent, so an admin must). Many delegated permissions a user can consent to themselves at sign-in.</p>

                <h4>What about <span class="tk-help-code">User.Read</span>?</h4>
                <p><span class="tk-help-code">User.Read</span> is the single lowest-privilege delegated scope: it reads the <strong>signed-in user's own</strong> basic profile (name, email, alias list) and nothing about anyone else or the directory. A user can self-consent — no admin needed. FreeITSM uses it for exactly one thing: reading that account's own aliases so an alias target is recognised (see the Aliases section).</p>
                <p class="tk-help-tip"><strong>Prefer not to grant <span class="tk-help-code">User.Read</span>?</strong> It's optional. Two zero-permission alternatives: (1) point the mailbox at its <strong>primary</strong> address rather than an alias, so exact-match works off the token alone; or (2) use <strong>App-only</strong> mode, which sidesteps the "who signed in" question entirely.</p>
            </div>

            <!-- 6. Azure setup -->
            <div class="tk-help-section" id="azure-setup">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">6</span>
                    <div>
                        <h3>Setting up the Azure app registration</h3>
                        <p>One registration in Microsoft Entra ID (Azure AD) can serve delegated or app-only.</p>
                    </div>
                </div>

                <h4>Common steps</h4>
                <ol>
                    <li><strong>Entra ID &rarr; App registrations &rarr; New registration.</strong> Note the <strong>Application (client) ID</strong> and <strong>Directory (tenant) ID</strong>.</li>
                    <li><strong>Certificates &amp; secrets &rarr; New client secret.</strong> Copy the secret <strong>value</strong> immediately — you can't see it again.</li>
                    <li>Enter the tenant ID, client ID and secret into the FreeITSM mailbox modal.</li>
                </ol>

                <h4>For Delegated</h4>
                <ol>
                    <li><strong>Authentication &rarr; Add a platform &rarr; Web</strong>, and set the <strong>Redirect URI</strong> to your install's <span class="tk-help-code">oauth_callback.php</span> (FreeITSM pre-fills this).</li>
                    <li><strong>API permissions &rarr; Microsoft Graph &rarr; Delegated</strong>: add <span class="tk-help-code">Mail.Read</span>, <span class="tk-help-code">Mail.ReadWrite</span>, <span class="tk-help-code">Mail.Send</span>, <span class="tk-help-code">User.Read</span>, <span class="tk-help-code">offline_access</span>, <span class="tk-help-code">openid</span>, <span class="tk-help-code">email</span>.</li>
                    <li>Save, then in FreeITSM click <strong>Authenticate</strong> and <strong>sign in as the target mailbox</strong>.</li>
                </ol>

                <h4>For App-only</h4>
                <ol>
                    <li><strong>API permissions &rarr; Microsoft Graph &rarr; Application</strong>: add <span class="tk-help-code">Mail.ReadWrite</span> and <span class="tk-help-code">Mail.Send</span>.</li>
                    <li>Click <strong>Grant admin consent</strong> (requires a Global Admin).</li>
                    <li><em>Recommended:</em> lock the app to just the mailboxes it should touch with an <strong>Application Access Policy</strong> — otherwise an app-only app can in principle read every mailbox in the tenant.</li>
                    <li>In FreeITSM, set <strong>Authentication = App-only</strong>. There's no sign-in step — it works on the next check.</li>
                </ol>
                <p class="tk-help-warn">App-only with no Application Access Policy grants the app access to <em>all</em> mailboxes in the tenant. For least privilege, scope it down to the specific mailbox(es) FreeITSM should read.</p>
            </div>

            <!-- 7. Add & verify a mailbox -->
            <div class="tk-help-section" id="add-mailbox">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">7</span>
                    <div>
                        <h3>Adding &amp; verifying a mailbox in FreeITSM</h3>
                        <p>The end-to-end flow once the Azure app exists.</p>
                    </div>
                </div>
                <ol>
                    <li>Go to <strong>Tickets &rarr; Settings &rarr; Mailboxes</strong> and click <strong>Add mailbox</strong>.</li>
                    <li>Pick the <strong>Provider</strong> (Microsoft 365 or Google Workspace), give it a <strong>display name</strong>, and enter the <strong>target mailbox</strong> address.</li>
                    <li>For Microsoft, choose the <strong>Authentication</strong> mode (Delegated or App-only). Choosing App-only hides the redirect-URI and scopes fields — they aren't used.</li>
                    <li>Enter the tenant ID, client ID and client secret. Save.</li>
                    <li><strong>Delegated:</strong> click <strong>Authenticate</strong> and sign in <em>as the target mailbox</em>. If the browser is already signed into another Microsoft account, use <strong>"Sign in with another account"</strong> — otherwise it may grab the wrong inbox (the safeguards will catch it, but it's cleaner to pick the right one).</li>
                    <li>Click the <strong>Check emails</strong> (envelope) icon. The row should show <span class="badge green">Reading from &lt;target&gt; &#10003;</span> (or <span class="badge blue">App-only</span>). If it shows <span class="badge red">&#9888; Wrong account</span>, re-authenticate as the right account or switch to app-only.</li>
                </ol>
                <p class="tk-help-tip">When you change an existing mailbox's <strong>target address</strong>, its stored sign-in is invalidated on purpose — re-authenticate so the identity (and alias list) is captured for the new address.</p>
                <p class="tk-help-warn">Re-using an older mailbox for a new address? Its stored OAuth scopes may pre-date <span class="tk-help-code">User.Read</span>. Either add <span class="tk-help-code">User.Read</span> to the <strong>OAuth scopes</strong> field before authenticating, or add a fresh mailbox (new mailboxes include it by default).</p>
            </div>

            <!-- 8. Google -->
            <div class="tk-help-section" id="google">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">8</span>
                    <div>
                        <h3>Google Workspace</h3>
                        <p>Briefly — Gmail mailboxes behave like delegated mode.</p>
                    </div>
                </div>
                <p>Google mailboxes use the <strong>Gmail API</strong> with OAuth 2.0 and behave like delegated mode — you authorise once and FreeITSM reads/sends as that account. There's no app-only equivalent in the FreeITSM UI for Google; the redirect URI uses <span class="tk-help-code">google_oauth_callback.php</span> instead of <span class="tk-help-code">oauth_callback.php</span>.</p>
            </div>

            <!-- 9. Troubleshooting -->
            <div class="tk-help-section" id="troubleshooting">
                <div class="tk-help-section-header">
                    <span class="tk-help-section-num">9</span>
                    <div>
                        <h3>Troubleshooting</h3>
                        <p>Common symptoms and how to clear them.</p>
                    </div>
                </div>
                <table class="tk-help-table">
                    <tr><th style="width:38%;">Symptom</th><th>Cause &amp; fix</th></tr>
                    <tr><td>Badge stuck on <span class="badge amber">Unverified</span></td><td>Identity not recorded yet — click <strong>Check emails</strong> once to back-fill, then reload the list.</td></tr>
                    <tr><td><span class="badge red">&#9888; Wrong account</span> / "Authentication mismatch"</td><td>The signed-in account doesn't own the target. Re-authenticate <em>as the target</em>, set the target to an address the account owns, or switch to app-only.</td></tr>
                    <tr><td><span class="badge red">&#9888; Wrong account</span> but it <em>is</em> the right mailbox (target is an alias)</td><td>Authenticated without <span class="tk-help-code">User.Read</span>, so the alias list couldn't be read. Add <span class="tk-help-code">User.Read</span> to the scopes and re-authenticate, or use the primary address as the target.</td></tr>
                    <tr><td>App-only: "client-credentials token request failed"</td><td>Wrong/expired client secret, or <strong>admin consent not granted</strong> on the Application permissions.</td></tr>
                    <tr><td>App-only reads nothing / 404 on the mailbox</td><td>The app isn't allowed to access that mailbox. Check the target address and (if set) that the Application Access Policy includes it.</td></tr>
                    <tr><td>Delegated: "Mailbox is not authenticated"</td><td>No stored token — click <strong>Authenticate</strong> and sign in.</td></tr>
                    <tr><td>Replies fail: "Could not determine mailbox for this ticket"</td><td>Manual ticket with no mailbox. Use the <strong>Send replies from</strong> dropdown when raising manual tickets.</td></tr>
                </table>
                <p class="tk-help-tip">For a deeper, regularly-updated write-up, see the <a href="https://github.com/edmozley/freeitsm/wiki/Mailbox-Authentication" target="_blank" rel="noopener">Mailbox Authentication wiki page</a>.</p>
            </div>

        </div>
    </div>
</div>

<script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="../assets/js/i18n.js"></script>
<script>
    // Scroll-spy: highlight active sidebar entry as user scrolls
    const helpMain = document.getElementById('helpMain');
    const navLinks = document.querySelectorAll('.tk-help-nav-link');
    const sections = Array.from(navLinks).map(l => document.getElementById(l.dataset.section)).filter(Boolean);

    function setActive(id) {
        navLinks.forEach(l => l.classList.toggle('active', l.dataset.section === id));
    }

    helpMain.addEventListener('scroll', () => {
        const scrollY = helpMain.scrollTop + 100;
        for (let i = sections.length - 1; i >= 0; i--) {
            if (sections[i].offsetTop <= scrollY) {
                setActive(sections[i].id);
                return;
            }
        }
    });

    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const target = document.getElementById(link.dataset.section);
            if (target) {
                helpMain.scrollTo({ top: target.offsetTop - 20, behavior: 'smooth' });
                setActive(link.dataset.section);
            }
        });
    });
</script>
</body>
</html>
