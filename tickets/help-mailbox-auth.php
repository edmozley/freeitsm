<?php
/**
 * Tickets — Mailbox Authentication Admin Guide
 * Standalone deep-dive linked from the main tickets help page (Settings section).
 * Covers the two Microsoft auth modes, the "reading from the right inbox" safeguards,
 * email aliases, OAuth scopes/permissions, Azure setup, and troubleshooting.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('tickets');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mailbox Authentication — Admin Guide</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=69">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--accent-accent);
            --accent-hover: var(--accent-accent-hover);
            --accent-soft:  var(--accent-accent-soft);
            --on-accent:    var(--accent-on-accent);
        }
    </style>
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="help-container">
    <!-- Left pane navigation -->
    <div class="help-sidebar">
        <a href="help.php" class="help-back">&larr; Back to Tickets help</a>
        <h3>Mailbox Authentication</h3>
        <a href="#overview" class="help-nav-link active" data-section="overview">
            <span class="help-nav-num">1</span> Overview
        </a>
        <a href="#modes" class="help-nav-link" data-section="modes">
            <span class="help-nav-num">2</span> Delegated vs App-only
        </a>
        <a href="#safeguards" class="help-nav-link" data-section="safeguards">
            <span class="help-nav-num">3</span> Right-inbox safeguards
        </a>
        <a href="#aliases" class="help-nav-link" data-section="aliases">
            <span class="help-nav-num">4</span> Email aliases
        </a>
        <a href="#scopes" class="help-nav-link" data-section="scopes">
            <span class="help-nav-num">5</span> Scopes &amp; permissions 101
        </a>
        <a href="#azure-setup" class="help-nav-link" data-section="azure-setup">
            <span class="help-nav-num">6</span> Azure app registration
        </a>
        <a href="#add-mailbox" class="help-nav-link" data-section="add-mailbox">
            <span class="help-nav-num">7</span> Add &amp; verify a mailbox
        </a>
        <a href="#origin-health" class="help-nav-link" data-section="origin-health">
            <span class="help-nav-num">8</span> Ticket origin &amp; the ! mark
        </a>
        <a href="#google" class="help-nav-link" data-section="google">
            <span class="help-nav-num">9</span> Google Workspace
        </a>
        <a href="#troubleshooting" class="help-nav-link" data-section="troubleshooting">
            <span class="help-nav-num">10</span> Troubleshooting
        </a>
    </div>

    <!-- Main content -->
    <div class="help-main" id="helpMain">
        <div class="help-hero">
            <h2>Mailbox Authentication</h2>
            <p>Connecting FreeITSM to a mailbox to turn email into tickets — safely, from the right inbox.</p>
        </div>

        <div class="help-content">

            <!-- 1. Overview -->
            <div class="help-section" id="overview">
                <div class="help-section-header">
                    <span class="help-section-num">1</span>
                    <div>
                        <h3>Overview</h3>
                        <p>What a mailbox connection does, and the choices you'll make setting one up.</p>
                    </div>
                </div>
                <p>FreeITSM reads inbound email into tickets and sends replies by connecting to a mailbox. Two providers are supported: <strong>Microsoft 365</strong> (via the Microsoft Graph API) and <strong>Google Workspace</strong> (via the Gmail API). Both use OAuth 2.0 — no plaintext mailbox passwords are ever stored.</p>
                <p>For Microsoft 365 there are two ways to authenticate, chosen per mailbox with the <strong>Authentication</strong> dropdown in the mailbox modal:</p>
                <div class="help-list">
                    <div><strong>Delegated</strong> — you sign in once <em>as the mailbox account</em>; FreeITSM then acts as that user and reads their inbox (Graph <code>/me</code>).</div>
                    <div><strong>App-only</strong> — no sign-in; the app authenticates itself with its own client ID + secret and reads the configured mailbox directly (Graph <code>/users/&lt;address&gt;</code>).</div>
                </div>
                <p>Configure everything below under <strong>Tickets &rarr; Settings &rarr; Mailboxes</strong>.</p>
                <p class="help-note">In a hurry? A dedicated mailbox where the sign-in name equals the email address (e.g. a <code>support@</code> service-desk mailbox) is the simplest, most robust setup — use Delegated, sign in as that mailbox, done.</p>
            </div>

            <!-- 2. Delegated vs App-only -->
            <div class="help-section" id="modes">
                <div class="help-section-header">
                    <span class="help-section-num">2</span>
                    <div>
                        <h3>Delegated vs App-only — which to use</h3>
                        <p>Both are first-class. The right choice depends on the mailbox and your Azure access.</p>
                    </div>
                </div>

                <div class="help-table">
                <table>
                    <tr><th style="width:30%;"></th><th>Delegated (sign in as the mailbox)</th><th>App-only (client credentials)</th></tr>
                    <tr><td><strong>Acts as</strong></td><td>A user — whoever signed in</td><td>The application itself (no user)</td></tr>
                    <tr><td><strong>How you connect</strong></td><td>Click <strong>Authenticate</strong> and sign in via Microsoft once</td><td>No sign-in — uses the app's client ID + secret</td></tr>
                    <tr><td><strong>Reads which inbox</strong></td><td>The signed-in user's inbox (<code>/me</code>)</td><td>The target mailbox you typed (<code>/users/&lt;address&gt;</code>)</td></tr>
                    <tr><td><strong>Azure permission type</strong></td><td>Delegated permissions</td><td>Application permissions</td></tr>
                    <tr><td><strong>Admin consent?</strong></td><td>Usually not</td><td><strong>Yes</strong> — an admin must grant it</td></tr>
                    <tr><td><strong>Survives the person leaving?</strong></td><td>No — tied to a sign-in</td><td>Yes — not tied to any person</td></tr>
                </table>
                </div>

                <div class="help-card">
                    <span class="help-pill info">Use Delegated when</span>
                    <p>You're setting up quickly and have the mailbox's sign-in credentials; the target mailbox is a real account you can sign in as; or you don't have a Global Admin to hand to grant application consent.</p>
                </div>
                <div class="help-card">
                    <span class="help-pill info">Use App-only when</span>
                    <p>The mailbox is a <strong>shared / service mailbox</strong> nobody logs into; you don't want the connection tied to one person's sign-in (no breakage when staff leave); or you want it to be <strong>impossible</strong> to read the wrong inbox. Requires a Global Admin to grant consent once.</p>
                </div>
                <p class="help-note">Rule of thumb: for a long-lived, hands-off service-desk mailbox, <strong>App-only</strong> is the cleaner choice. For a quick start, <strong>Delegated</strong> is fine — just make sure you sign in <em>as the mailbox</em>, not as yourself.</p>
            </div>

            <!-- 3. Safeguards -->
            <div class="help-section" id="safeguards">
                <div class="help-section-header">
                    <span class="help-section-num">3</span>
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

                <div class="help-table">
                <table>
                    <tr><th style="width:22%;">Badge</th><th>Meaning</th></tr>
                    <tr><td><span class="help-pill ok">Reading from X &#10003;</span></td><td>Signed-in account matches the target (or one of its aliases) — all good.</td></tr>
                    <tr><td><span class="help-pill info">App-only</span></td><td>Reads the target directly via client credentials.</td></tr>
                    <tr><td><span class="help-pill warn">Unverified</span></td><td>Authenticated, but the account hasn't been confirmed yet (e.g. authenticated under an older version). Harmless and self-healing — see the tip below.</td></tr>
                    <tr><td><span class="help-pill bad">&#9888; Wrong account</span></td><td>The signed-in account doesn't own the target address — blocked until you re-authenticate or switch to app-only.</td></tr>
                </table>
                </div>

                <p class="help-note"><strong>"Unverified" is harmless and self-healing.</strong> It just means the identity hasn't been recorded yet. Click the <strong>Check emails</strong> (envelope) icon on that row once; the identity is back-filled and the badge settles to green &#10003; (or red &#9888; if it genuinely is the wrong account). Reload the list to see it update.</p>
            </div>

            <!-- 4. Aliases -->
            <div class="help-section" id="aliases">
                <div class="help-section-header">
                    <span class="help-section-num">4</span>
                    <div>
                        <h3>Email aliases (the UPN-vs-email trap)</h3>
                        <p>Why an alias target doesn't get falsely flagged as "Wrong account".</p>
                    </div>
                </div>
                <p>Microsoft 365 has two identifiers that both look like email addresses, and they can differ:</p>
                <div class="help-list">
                    <div><strong>UPN / sign-in name</strong> — what you log in with, e.g. <code>edmozley@contoso.com</code></div>
                    <div><strong>Primary SMTP / alias</strong> — your actual email address(es), e.g. <code>ed@contoso.com</code> as a friendlier alias</div>
                </div>
                <p>The access token only carries the account's <strong>UPN / primary</strong> address — not its aliases. So if a mailbox's target is an <em>alias</em> (e.g. <code>ed@</code> on the <code>edmozley@</code> mailbox), a naive exact-match would wrongly cry "Wrong account" even though it's the same inbox.</p>
                <p>FreeITSM avoids that: on sign-in it reads the mailbox's <strong>full address list</strong> (primary, UPN and every alias, via Graph <code>proxyAddresses</code>) and accepts the target if it matches <strong>any</strong> of them. So:</p>
                <div class="help-list">
                    <div>Target <code>ed@</code> while signed in as <code>edmozley@</code> &rarr; <span class="help-pill ok">allowed</span> (alias of the same mailbox)</div>
                    <div>Target <code>support@</code> while signed in as <code>edmozley@</code> &rarr; <span class="help-pill bad">blocked</span> (genuinely different mailbox)</div>
                </div>
                <p>Reading the alias list needs the lightweight <code>User.Read</code> scope (see next section). Without it, FreeITSM falls back to matching the primary address only — everything still works, you just can't use a non-primary alias as the target.</p>
                <p class="help-note warn">If you point a mailbox at an alias and it still says &#9888; Wrong account, it was almost certainly authenticated <strong>without</strong> <code>User.Read</code>. Add it to the scopes and re-authenticate, or use the mailbox's primary address as the target instead.</p>
            </div>

            <!-- 5. Scopes 101 -->
            <div class="help-section" id="scopes">
                <div class="help-section-header">
                    <span class="help-section-num">5</span>
                    <div>
                        <h3>Scopes &amp; permissions 101</h3>
                        <p>Plain-English: scopes, delegated vs application permissions, admin consent.</p>
                    </div>
                </div>

                <h4>What's a scope?</h4>
                <p>A <strong>scope</strong> (or permission) is a single capability you ask Microsoft for, like <code>Mail.Read</code>. The token Microsoft issues is stamped with exactly the scopes you requested and nothing more — like a backstage pass listing which doors it opens. FreeITSM asks for <code>Mail.Read</code>, <code>Mail.ReadWrite</code>, <code>Mail.Send</code>, the lightweight <code>User.Read</code>, plus <code>openid</code> / <code>email</code> / <code>offline_access</code> (sign-in plumbing).</p>

                <h4>Delegated permission vs Application permission</h4>
                <p>Same-sounding permission, two very different flavours — this is where everyone trips up:</p>
                <div class="help-list">
                    <div><strong>Delegated</strong> — the app acts <em>on behalf of a signed-in user</em>, limited to what that user can already reach. <code>Mail.Read</code> delegated = "read the mail of whoever signed in." Used by <strong>Delegated</strong> mode.</div>
                    <div><strong>Application</strong> — the app acts <em>as itself, with no user</em>. <code>Mail.ReadWrite</code> application = "read/write mail in mailboxes the app is allowed to." Used by <strong>App-only</strong> mode.</div>
                </div>
                <p>The same scope name appears in <strong>both</strong> lists in Azure. For app-only you must add the <strong>Application</strong> versions; the delegated ones won't work for client credentials, and vice-versa.</p>

                <h4>What is "admin consent"?</h4>
                <p>Some permissions are powerful enough that an ordinary user can't approve them for the whole organisation — a <strong>Global Administrator</strong> must click <strong>"Grant admin consent"</strong> in Azure. <strong>All application permissions</strong> need admin consent (there's no user to consent, so an admin must). Many delegated permissions a user can consent to themselves at sign-in.</p>

                <h4>What about <code>User.Read</code>?</h4>
                <p><code>User.Read</code> is the single lowest-privilege delegated scope: it reads the <strong>signed-in user's own</strong> basic profile (name, email, alias list) and nothing about anyone else or the directory. A user can self-consent — no admin needed. FreeITSM uses it for exactly one thing: reading that account's own aliases so an alias target is recognised (see the Aliases section).</p>
                <p class="help-note"><strong>Prefer not to grant <code>User.Read</code>?</strong> It's optional. Two zero-permission alternatives: (1) point the mailbox at its <strong>primary</strong> address rather than an alias, so exact-match works off the token alone; or (2) use <strong>App-only</strong> mode, which sidesteps the "who signed in" question entirely.</p>
            </div>

            <!-- 6. Azure setup -->
            <div class="help-section" id="azure-setup">
                <div class="help-section-header">
                    <span class="help-section-num">6</span>
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
                    <li><strong>Authentication &rarr; Add a platform &rarr; Web</strong>, and set the <strong>Redirect URI</strong> to your install's <code>oauth_callback.php</code> (FreeITSM pre-fills this).</li>
                    <li><strong>API permissions &rarr; Microsoft Graph &rarr; Delegated</strong>: add <code>Mail.Read</code>, <code>Mail.ReadWrite</code>, <code>Mail.Send</code>, <code>User.Read</code>, <code>offline_access</code>, <code>openid</code>, <code>email</code>.</li>
                    <li>Save, then in FreeITSM click <strong>Authenticate</strong> and <strong>sign in as the target mailbox</strong>.</li>
                </ol>

                <h4>For App-only</h4>
                <ol>
                    <li><strong>API permissions &rarr; Microsoft Graph &rarr; Application</strong>: add <code>Mail.ReadWrite</code> and <code>Mail.Send</code>.</li>
                    <li>Click <strong>Grant admin consent</strong> (requires a Global Admin).</li>
                    <li><em>Recommended:</em> lock the app to just the mailboxes it should touch with an <strong>Application Access Policy</strong> — otherwise an app-only app can in principle read every mailbox in the tenant.</li>
                    <li>In FreeITSM, set <strong>Authentication = App-only</strong>. There's no sign-in step — it works on the next check.</li>
                </ol>
                <p class="help-note warn">App-only with no Application Access Policy grants the app access to <em>all</em> mailboxes in the tenant. For least privilege, scope it down to the specific mailbox(es) FreeITSM should read.</p>
            </div>

            <!-- 7. Add & verify a mailbox -->
            <div class="help-section" id="add-mailbox">
                <div class="help-section-header">
                    <span class="help-section-num">7</span>
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
                    <li>Click the <strong>Check emails</strong> (envelope) icon. The row should show <span class="help-pill ok">Reading from &lt;target&gt; &#10003;</span> (or <span class="help-pill info">App-only</span>). If it shows <span class="help-pill bad">&#9888; Wrong account</span>, re-authenticate as the right account or switch to app-only.</li>
                </ol>
                <p class="help-note">When you change an existing mailbox's <strong>target address</strong>, its stored sign-in is invalidated on purpose — re-authenticate so the identity (and alias list) is captured for the new address.</p>
                <p class="help-note warn">Re-using an older mailbox for a new address? Its stored OAuth scopes may pre-date <code>User.Read</code>. Either add <code>User.Read</code> to the <strong>OAuth scopes</strong> field before authenticating, or add a fresh mailbox (new mailboxes include it by default).</p>
            </div>

            <!-- 8. Ticket origin & the health mark -->
            <div class="help-section" id="origin-health">
                <div class="help-section-header">
                    <span class="help-section-num">8</span>
                    <div>
                        <h3>Ticket origin, and the <strong>!</strong> beside a mailbox</h3>
                        <p>What a mailbox records on its tickets, and how it tells you something is off.</p>
                    </div>
                </div>

                <h4>Default ticket origin</h4>
                <p>Each mailbox has a <strong>Default ticket origin</strong> in its settings. Tickets that mailbox opens are recorded as having come from it, so your reports can separate email tickets from any other kind. Without it the <strong>Source</strong> field on those tickets stays empty.</p>
                <p>It is set <strong>per mailbox</strong> rather than once for all email, because a helpdesk address and a monitoring address both arrive as email and are not the same source. Point one at <em>Email</em> and another at an origin you have added called <em>Monitoring</em>, and the tickets can finally be told apart.</p>
                <p class="help-note">Renaming an origin later is safe — the mailbox stores a reference to it, not the word. On installations with more than one company, a mailbox pinned to a company may also use that company's own origins; a shared-intake mailbox may use only the standard ones, because its tickets land in whichever company the sender's domain matches. The standard origins are always available, so every mailbox can be given one.</p>
                <p class="help-note warn">Existing tickets are not backfilled. Nothing knows which mailbox an old ticket came through, so only tickets created from now on carry an origin.</p>

                <h4>The <strong>!</strong> beside a mailbox name</h4>
                <p>A mailbox can be authenticated, green and collecting mail perfectly and still not be doing what you assume — which is exactly how a missing ticket origin goes unnoticed for weeks. When something needs attention, an <strong>!</strong> appears next to the mailbox name. Click it for the list in plain terms, each item saying what the consequence is rather than just naming a setting.</p>
                <ul>
                    <li><strong>Errors</strong> mean mail is not being collected, or is being collected wrongly: reading the wrong inbox, never signed in, stored credentials that cannot be decrypted, or a ticket origin that has since been deleted.</li>
                    <li><strong>Warnings</strong> mean mail is collected but something downstream is not set up: no ticket origin, never checked for mail, not checked for several days, set to file imported mail into a folder that was never named, or an IMAP mailbox with no outgoing server so replies cannot be sent.</li>
                </ul>
                <p>A mailbox with nothing wrong has <strong>no mark at all</strong>.</p>

                <h4>Dismissing a warning</h4>
                <p>Most warnings describe something you may well have meant — a mailbox that genuinely should record no origin, or a receive-only address with no outgoing server. Each one has a <strong>Dismiss</strong> button. Dismissing says <em>"I know"</em>: the mark clears, and the item stays listed inside with a <strong>Restore</strong> beside it, so acknowledging something is not the same as hiding it.</p>
                <p class="help-note"><strong>Errors cannot be dismissed.</strong> Reading the wrong inbox is a fault rather than a preference, and it is the one case where silencing the warning would be worse than never showing it. A mailbox that is simply switched off, or left as shared intake, is not flagged at all — both are ordinary choices and both already show as a badge on the row.</p>
            </div>

            <!-- 9. Google -->
            <div class="help-section" id="google">
                <div class="help-section-header">
                    <span class="help-section-num">9</span>
                    <div>
                        <h3>Google Workspace</h3>
                        <p>Briefly — Gmail mailboxes behave like delegated mode.</p>
                    </div>
                </div>
                <p>Google mailboxes use the <strong>Gmail API</strong> with OAuth 2.0 and behave like delegated mode — you authorise once and FreeITSM reads/sends as that account. There's no app-only equivalent in the FreeITSM UI for Google; the redirect URI uses <code>google_oauth_callback.php</code> instead of <code>oauth_callback.php</code>.</p>
            </div>

            <!-- 10. Troubleshooting -->
            <div class="help-section" id="troubleshooting">
                <div class="help-section-header">
                    <span class="help-section-num">10</span>
                    <div>
                        <h3>Troubleshooting</h3>
                        <p>Common symptoms and how to clear them.</p>
                    </div>
                </div>
                <div class="help-table">
                <table>
                    <tr><th style="width:38%;">Symptom</th><th>Cause &amp; fix</th></tr>
                    <tr><td>Badge stuck on <span class="help-pill warn">Unverified</span></td><td>Identity not recorded yet — click <strong>Check emails</strong> once to back-fill, then reload the list.</td></tr>
                    <tr><td><span class="help-pill bad">&#9888; Wrong account</span> / "Authentication mismatch"</td><td>The signed-in account doesn't own the target. Re-authenticate <em>as the target</em>, set the target to an address the account owns, or switch to app-only.</td></tr>
                    <tr><td><span class="help-pill bad">&#9888; Wrong account</span> but it <em>is</em> the right mailbox (target is an alias)</td><td>Authenticated without <code>User.Read</code>, so the alias list couldn't be read. Add <code>User.Read</code> to the scopes and re-authenticate, or use the primary address as the target.</td></tr>
                    <tr><td>App-only: "client-credentials token request failed"</td><td>Wrong/expired client secret, or <strong>admin consent not granted</strong> on the Application permissions.</td></tr>
                    <tr><td>App-only reads nothing / 404 on the mailbox</td><td>The app isn't allowed to access that mailbox. Check the target address and (if set) that the Application Access Policy includes it.</td></tr>
                    <tr><td>Delegated: "Mailbox is not authenticated"</td><td>No stored token — click <strong>Authenticate</strong> and sign in.</td></tr>
                    <tr><td>Replies fail: "Could not determine mailbox for this ticket"</td><td>Manual ticket with no mailbox. Use the <strong>Send replies from</strong> dropdown when raising manual tickets.</td></tr>
                </table>
                </div>
                <p class="help-note">For a deeper, regularly-updated write-up, see the <a href="https://github.com/edmozley/freeitsm/wiki/Mailbox-Authentication" target="_blank" rel="noopener">Mailbox Authentication wiki page</a>.</p>
            </div>

        </div>
    </div>
</div>

<script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="../assets/js/i18n.js?v=2"></script>
<script>
    // Scroll-spy: highlight active sidebar entry as user scrolls
    const helpMain = document.getElementById('helpMain');
    const navLinks = document.querySelectorAll('.help-nav-link');
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
