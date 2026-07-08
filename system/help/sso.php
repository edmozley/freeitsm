<?php
/**
 * System Help — Single Sign-On (SSO / OIDC).
 * The flagship help article: single-company vs multi-company (MSP) setup.
 */
require __DIR__ . '/_init.php';

// The redirect URI the admin registers in their IdP (same one for every provider).
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirectUri = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'api/auth/oidc_callback.php';

$helpHero = 'Single Sign-On (SSO)';
$helpSub  = 'Let people sign in with their existing identity provider — Microsoft Entra, Google, Okta, Keycloak and any other OpenID Connect provider — for both analysts and the self-service portal. Local passwords keep working as a fallback.';
$helpNav  = [
    ['id' => 'overview',   'label' => 'Overview'],
    ['id' => 'which',      'label' => 'Single or multi-company?'],
    ['id' => 'single',     'label' => 'Single-company setup'],
    ['id' => 'multi',      'label' => 'Multi-company (MSP) setup'],
    ['id' => 'experience', 'label' => 'What people see'],
    ['id' => 'breakglass', 'label' => 'Break-glass & safety'],
    ['id' => 'faq',        'label' => 'Troubleshooting'],
];
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
