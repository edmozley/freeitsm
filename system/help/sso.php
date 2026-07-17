<?php
/**
 * System Help — Single Sign-On (SSO / OIDC).
 * The flagship help article: single-company vs multi-company (MSP) setup.
 */
require __DIR__ . '/_init.php';

// The redirect URI the admin registers in their IdP (same one for every provider).
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirectUri = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'api/auth/oidc_callback.php';

$helpSlug = 'sso';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What SSO does here</h3></div>
    <p class="syshelp-lead">Instead of a FreeITSM password, a person is sent to their own identity provider (IdP) to sign in. FreeITSM never sees their password — it receives a signed token proving who they are. Multi-factor is handled by the provider, so SSO users aren't asked for a separate code.</p>
    <p>It works in two places, independently:</p>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>Analysts</h4>
            <p>Your service-desk staff, on the main login page. Each analyst is assigned a sign-in method (local password, or a specific provider) in <strong>System &rarr; Analysts</strong>.</p>
        </div>
        <div class="syshelp-card">
            <h4>Self-service portal</h4>
            <p>The people who raise tickets, on the portal login page. They're routed to a provider by their email — or, on a multi-company install, by their company.</p>
        </div>
    </div>
    <p>Everything is standard <strong>OpenID Connect</strong>, driven by the provider's discovery document, so the same setup works for Entra, Google, Okta, Keycloak, Auth0, Authentik and others — you only ever supply a display name, issuer URL, client ID and client secret.</p>
    <div class="syshelp-callout info"><strong>Two global switches</strong> live at the top of System &rarr; Single Sign-On: <strong>Enable single sign-on</strong> (the master on/off) and <strong>Allow local login</strong> (whether the password form is offered). Both are reversible at any time — see <em>Break-glass &amp; safety</em>.</div>
</div>

<!-- 2. Which path -->
<div class="syshelp-section" id="which">
    <div class="syshelp-section-header"><h3>First decide: single-company or multi-company?</h3></div>
    <p>This is the only thing you need to get straight before you start. Everything else follows from it.</p>
    <table class="syshelp-table">
        <tr><th></th><th>Single-company</th><th>Multi-company (MSP)</th></tr>
        <tr><td><strong>You are…</strong></td><td>One organisation running FreeITSM for your own staff and users.</td><td>An MSP (or group) supporting several separate client companies from one install.</td></tr>
        <tr><td><strong>How many companies?</strong></td><td>Just one (the silent “Default”).</td><td>Two or more (System &rarr; Companies).</td></tr>
        <tr><td><strong>Identity providers</strong></td><td>Your own IdP(s), shared by everyone.</td><td>Each client brings <em>their own</em> IdP.</td></tr>
        <tr><td><strong>Portal login shows…</strong></td><td>Provider buttons up front.</td><td>Email first, then that person's company's provider(s).</td></tr>
    </table>
    <div class="syshelp-callout"><strong>How FreeITSM decides which you are:</strong> it counts companies. With one company it behaves as a single-company install; the moment you add a second company in System &rarr; Companies, the multi-company behaviour switches on automatically. You don't toggle a setting.</div>
    <p>Pick your section below.</p>
</div>

<!-- 3. Single-company -->
<div class="syshelp-section" id="single">
    <div class="syshelp-section-header"><h3>Single-company setup</h3></div>
    <p class="syshelp-lead">You have one IdP (say Microsoft Entra) and you want your staff and/or portal users to sign in with it. Three steps.</p>

    <div class="syshelp-steps">
        <div class="syshelp-step"><div class="syshelp-step-num">1</div><div><strong>Register an app in your identity provider.</strong> Create an app/registration in Entra, Okta, Google, etc. Set its redirect URI to the address below, and note the <strong>issuer URL</strong>, <strong>client ID</strong> and a <strong>client secret</strong>.<br><br>Redirect URI to register: <code><?php echo htmlspecialchars($redirectUri); ?></code></div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">2</div><div><strong>Add the provider here.</strong> System &rarr; Single Sign-On &rarr; <em>Add provider</em>. Paste the issuer URL, client ID and secret, give it a display name (e.g. “Sign in with Microsoft”), tick <strong>Enabled</strong>, and use <strong>Test</strong> to confirm the issuer is reachable. Turn on the master <strong>Enable single sign-on</strong> switch.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">3</div><div><strong>Decide who uses it.</strong> For <strong>analysts</strong>, set their <em>Sign-in method</em> to this provider in System &rarr; Analysts. For <strong>portal users</strong>, turn on the provider's <em>auto-create users</em> toggle and they're created on first sign-in — or they're matched to an existing record by verified email.</div></div>
    </div>

    <div class="syshelp-callout ok"><strong>That's it.</strong> The login pages now lead with your provider's button, with the password form tucked behind a “local account” link. Nothing else to configure — there are no companies to think about.</div>
</div>

<!-- 4. Multi-company -->
<div class="syshelp-section highlight" id="multi">
    <div class="syshelp-section-header"><h3>Multi-company (MSP) setup</h3></div>
    <p class="syshelp-lead">You support several client companies, and each wants <em>their</em> people to sign in with <em>their</em> IdP. The portal figures out which client a person belongs to from their email, and sends them to the right provider. You never show one client's provider to another.</p>

    <h4>What you need from each client</h4>
    <table class="syshelp-table">
        <tr><th>You need…</th><th>Where it goes</th></tr>
        <tr><td>Their <strong>email domain(s)</strong> (e.g. <code>acme.co.uk</code>)</td><td>System &rarr; Companies &rarr; (their company) &rarr; Email domains</td></tr>
        <tr><td>Their IdP <strong>issuer URL, client ID, client secret</strong></td><td>System &rarr; Single Sign-On &rarr; Add provider, with <strong>Company</strong> set to that client</td></tr>
        <tr><td>A decision: <strong>auto-create users</strong> on or off</td><td>The same provider form</td></tr>
    </table>
    <p>And you give the client one thing back: the redirect URI to register in their IdP — <code><?php echo htmlspecialchars($redirectUri); ?></code> (the same for every client).</p>

    <h4>Setting up one client</h4>
    <div class="syshelp-steps">
        <div class="syshelp-step"><div class="syshelp-step-num">1</div><div><strong>Add the company</strong> in System &rarr; Companies (if it isn't there already) and add its <strong>email domain(s)</strong>. This is the key that routes <code>someone@acme.co.uk</code> to Acme. (You can also map an individual address for people on personal/free email.)</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">2</div><div><strong>Add their provider</strong> in System &rarr; Single Sign-On, and set the new <strong>Company</strong> dropdown to that client. That marks the IdP as <em>owned by Acme</em> — it will only ever be offered to Acme's people, never to other clients or to your analysts.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">3</div><div><strong>Choose auto-create.</strong> On = a brand-new Acme requester is created automatically the first time they sign in (zero pre-provisioning). Off = only people who already exist (e.g. raised a ticket) can sign in.</div></div>
    </div>

    <h4>How the routing then works</h4>
    <p>On a multi-company install the portal login shows <strong>just an email box</strong> — no provider buttons up front (that would leak every client's IdP). When someone enters their email:</p>
    <ul>
        <li><strong>Their company has no IdP</strong> &rarr; they get the email + password form.</li>
        <li><strong>One IdP</strong> &rarr; they're sent straight to it.</li>
        <li><strong>Two or more</strong> (a client mid-migration between, say, Entra and Okta) &rarr; they're shown a small picker to choose. After their first successful sign-in they're remembered, so the picker is a once-only step.</li>
    </ul>
    <div class="syshelp-callout info"><strong>Local always works as a floor.</strong> Anyone whose email isn't mapped to a company — including people on Gmail/Outlook personal addresses, or someone locked out of their IdP — falls through to the local password form. No one can be shut out.</div>

    <div class="syshelp-callout warn"><strong>Today, each client hands you a client secret</strong> (which you store and rotate). That's the “bring-your-own-credentials” model and it works for any provider. A future option — where you register one app and each client just clicks “consent”, with no secret to hand over — is designed but not yet built. It will not change anything above; only how a provider is added.</div>
</div>

<!-- 4b. LDAP / Active Directory -->
<div class="syshelp-section" id="ldap">
    <div class="syshelp-section-header"><h3>LDAP / Active Directory</h3></div>
    <p>If your people already exist in Active Directory (or OpenLDAP, FreeIPA, 389 Directory Server), FreeITSM can check their password against it directly. They keep the username and password they already use everywhere else, and you don't create an account here for every new starter.</p>
    <div class="syshelp-callout"><strong>This is not single sign-on.</strong> With SSO the browser bounces to your identity provider and back. With LDAP people type their directory password into <em>FreeITSM's own</em> login form, and we check it with the directory. Both live on this page because both answer the same question — how do people sign in — but they behave differently, and only SSO gives you one shared session across apps.</div>
    <h4>How it works</h4>
    <p>Someone types <code>r.patel</code>, not their full directory path, so FreeITSM does this on every sign-in:</p>
    <ol>
        <li>Connects to your directory and signs in as a <strong>read-only service account</strong>, so it is allowed to look people up.</li>
        <li><strong>Searches</strong> for the person to find their full entry.</li>
        <li>Tries to sign in <strong>as that person</strong> with the password they typed. If the directory accepts it, the password was right.</li>
        <li>Checks their <strong>groups</strong> to decide what, if anything, they're allowed to be.</li>
    </ol>
    <p>That's why the setup form asks for a server, a service account and a base DN — they're the ingredients for those steps. FreeITSM never reads or stores anyone's directory password.</p>
    <div class="syshelp-callout ok"><strong>Leavers are handled for you.</strong> Disable someone in the directory and they can no longer sign in here, immediately — the directory refuses the sign-in, so there is nothing to remember to switch off in FreeITSM.</div>
</div>

<!-- 4c. LDAP setup -->
<div class="syshelp-section" id="ldap-setup">
    <div class="syshelp-section-header"><h3>Setting up a directory</h3></div>
    <p>Go to <strong>System → Authentication</strong>, click <strong>+ Add</strong>, and set <strong>Type</strong> to <em>LDAP / Active Directory</em>. Then pick the <strong>Active Directory</strong> or <strong>OpenLDAP</strong> preset — it fills in the filter and attribute names that are right for that kind of directory, so you only need to supply the four things that are specific to you:</p>
    <ul>
        <li><strong>Server</strong> — a domain controller's hostname or IP, e.g. <code>dc1.example.local</code>.</li>
        <li><strong>Service account</strong> — a <em>read-only</em> account used only to look people up. Active Directory accepts <code>svc-freeitsm@example.local</code>; OpenLDAP wants a full DN like <code>cn=svc-freeitsm,dc=example,dc=com</code>. It never needs write access.</li>
        <li><strong>Base DN</strong> — where to search from, e.g. <code>DC=example,DC=local</code>, or narrow it to <code>OU=Staff,DC=example,DC=local</code>.</li>
        <li><strong>Encryption</strong> — see the warning below.</li>
    </ul>
    <p>Use the <strong>Test</strong> button before saving. Leave the test user blank to check only that the service account can connect and read; fill one in and it runs a real sign-in and shows you exactly which name, email and groups came back. That is the quickest way to catch a wrong attribute name or a too-narrow base DN.</p>
    <div class="syshelp-callout warn"><strong>Use STARTTLS or LDAPS in production.</strong> With encryption set to <em>None</em>, people's passwords cross your network in the clear on every sign-in. Many Active Directory servers refuse password sign-ins over unencrypted LDAP anyway, so if plain LDAP fails with an error about strong authentication, that's why — switch to LDAPS.</div>
    <p>Turn on <strong>Auto-create users on first login (JIT)</strong> so a new starter gets an account the first time they sign in. Read the next section before you do — on its own, that lets anyone in the directory in.</p>
</div>

<!-- 4d. LDAP groups -->
<div class="syshelp-section highlight" id="ldap-groups">
    <div class="syshelp-section-header"><h3>Controlling access by group</h3></div>
    <p>Auto-create is the point of connecting a directory — but by itself it means <em>everyone</em> your directory recognises becomes an analyst. Point that at a 500-person company and you get 500 analysts. Naming groups is what stops that.</p>
    <table class="syshelp-table">
        <thead><tr><th>Setting</th><th>What it does</th></tr></thead>
        <tbody>
            <tr><td><strong>Analyst group</strong></td><td>Members get an analyst account and can use the main FreeITSM login.</td></tr>
            <tr><td><strong>Self-service user group</strong></td><td>Members get a self-service account — they can raise and track their own tickets, but cannot sign in as an analyst.</td></tr>
            <tr><td><strong>Neither</strong></td><td>They cannot sign in at all, even with a correct password.</td></tr>
            <tr><td><strong>Both boxes blank</strong></td><td>No gate: anyone the directory recognises becomes an analyst. Fine for a small single-team install; risky anywhere else.</td></tr>
        </tbody>
    </table>
    <p>Type either the group's plain name (<code>ITSM-Analysts</code>) or its full DN — both work, and case doesn't matter.</p>
    <div class="syshelp-callout ok"><strong>Nested groups work on Active Directory.</strong> If your analyst group contains other groups rather than people directly, members of those inner groups still get in. The AD preset handles this for you. OpenLDAP has no equivalent, so there you must name a group that contains the people themselves.</div>
    <div class="syshelp-callout"><strong>It fails safely.</strong> If FreeITSM can't read your groups for any reason, nobody is let in by accident — an unreadable group list denies access rather than granting it. So if <em>everyone</em> is suddenly refused, suspect the group settings, not people's passwords.</div>
</div>

<!-- 4e. LDAP troubleshooting -->
<div class="syshelp-section" id="ldap-faq">
    <div class="syshelp-section-header"><h3>LDAP troubleshooting</h3></div>
    <ul>
        <li><strong>“No such object”, but the user definitely exists.</strong> Nearly always the service account's permissions, not a missing user — most directories report a subtree they aren't allowed to read as though it isn't there. Check the base DN, then check the service account can read it. OpenLDAP in particular denies reads by default until you grant them.</li>
        <li><strong>Everyone is refused, even with the right password.</strong> Check <em>Access by group</em>. If a group is named and nobody matches it, everyone is denied by design. The Test button shows the groups it found and the access it worked out.</li>
        <li><strong>One person is refused, everyone else is fine.</strong> Are they in the right group? Remember an Active Directory admins group is not automatically your analyst group — name whichever group actually holds your service desk staff.</li>
        <li><strong>“The account is disabled.”</strong> They're disabled in the directory. That's the directory refusing them, and it's working as intended.</li>
        <li><strong>Signs in, but the account has no name or email.</strong> The attribute names don't match your directory. Run Test with that person and compare what comes back.</li>
        <li><strong>Signing in with no email address.</strong> People with no mailbox in the directory (warehouse, shop-floor, and so on) sign in fine — their account is created with the email left blank. They just won't receive ticket email until an address is added to their directory entry.</li>
        <li><strong>Anything about strong authentication.</strong> Your directory requires an encrypted connection. Switch Encryption to LDAPS or STARTTLS.</li>
        <li><strong>The PHP <code>ldap</code> extension is not enabled.</strong> Enable <code>extension=ldap</code> in <code>php.ini</code> and restart your web server. On some setups there are two php.ini files — one for the web server and one for the command line — and both need it.</li>
    </ul>
</div>

<!-- 5. Experience -->
<div class="syshelp-section" id="experience">
    <div class="syshelp-section-header"><h3>What people see when they sign in</h3></div>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>With SSO off</h4>
            <p>The normal username/email + password form. Nothing changes.</p>
        </div>
        <div class="syshelp-card">
            <h4>Single-company, SSO on</h4>
            <p>The provider button(s) lead, e.g. “Sign in with Microsoft”, with a “local account” link underneath.</p>
        </div>
        <div class="syshelp-card">
            <h4>Multi-company portal</h4>
            <p>An email box first. Enter your email and you're routed to your company's provider (or shown a picker, or the password form).</p>
        </div>
        <div class="syshelp-card">
            <h4>Analyst login</h4>
            <p>Only your global (internal) providers ever appear here — clients' IdPs are never shown to staff.</p>
        </div>
    </div>
    <div class="syshelp-callout"><strong>First portal sign-in tip:</strong> a brand-new portal user typing their email may land on the password form, because the email-first router only auto-routes people it has seen before. They should use the <strong>provider button</strong> (single-company) or it routes by company (multi-company); after that first sign-in they're remembered and email-first routing is automatic.</div>
</div>

<!-- 6. Break-glass -->
<div class="syshelp-section" id="breakglass">
    <div class="syshelp-section-header"><h3>Break-glass &amp; safety</h3></div>
    <p>Local login is never hard-disabled, so a broken or offline IdP can't lock everyone out.</p>
    <ul>
        <li><strong>Master kill switch</strong> — turning off <em>Enable single sign-on</em> instantly reverts everyone to local login.</li>
        <li><strong>Allow local login</strong> — when off, the local form is hidden for a clean SSO-only experience, but it's still reachable.</li>
        <li><strong>The <code>?local=1</code> escape hatch</strong> — adding <code>?local=1</code> to a login URL always brings the password form back, even in SSO-only mode. Keep at least one local admin account for this.</li>
        <li><strong>Single logout</strong> — signing out of FreeITSM also ends the session at the provider, so the next visit isn't silently waved through.</li>
    </ul>
    <div class="syshelp-callout ok"><strong>Recommended:</strong> keep one local-password admin account as your break-glass, and confirm <code>?local=1</code> works, before you switch <em>Allow local login</em> off.</div>
</div>

<!-- 7. FAQ -->
<div class="syshelp-section" id="faq">
    <div class="syshelp-section-header"><h3>Troubleshooting</h3></div>

    <h4>“Redirect URI mismatch” after signing in at the provider</h4>
    <p>The redirect URI registered in the IdP must <em>exactly</em> match <code><?php echo htmlspecialchars($redirectUri); ?></code> — scheme, host and path. Copy it from this page (or the SSO settings page) rather than typing it.</p>

    <h4>A portal user typed their email but got the password form, not SSO</h4>
    <p>Single-company: they should click the provider button for their first sign-in. Multi-company: check the company that owns their email domain actually has an enabled provider, and that the domain is listed under System &rarr; Companies. Unmapped domains and personal/free-email addresses are sent to local login by design.</p>

    <h4>“Your email is not verified with the identity provider”</h4>
    <p>The provider sent <code>email_verified: false</code>, or you've turned on <em>Require a verified-email claim</em> for a provider whose tokens omit it. Leave that toggle off unless your IdP lets users self-register unverified addresses.</p>

    <h4>A client's provider button is showing on the analyst login</h4>
    <p>Set the provider's <strong>Company</strong> to that client (not “Global”). Global providers are the only ones offered to analysts; company-owned ones are portal-only.</p>

    <h4>Discovery test fails</h4>
    <p>The issuer URL is wrong or unreachable from the server. It should be the base issuer (no <code>/.well-known/…</code> on the end) — e.g. <code>https://login.microsoftonline.com/&lt;tenant-id&gt;/v2.0</code> for Entra. Use the <strong>Test</strong> button to confirm before saving.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
