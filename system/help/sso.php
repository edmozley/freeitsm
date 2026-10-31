<?php
/**
 * System Help — Single Sign-On (SSO / OIDC).
 * The flagship help article: single-company vs multi-company (MSP) setup.
 */
require __DIR__ . '/_init.php';

// The redirect URI the admin registers in their IdP (same one for every provider).
$scheme = requestScheme();
$redirectUri = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'api/auth/oidc_callback.php';
// Where the two sign-outs send people back (same as auth/analyst_logout.php and self-service/logout.php).
$signoutAnalystUri = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL;
$signoutPortalUri  = $signoutAnalystUri . 'self-service/login.php';

$helpSlug = 'sso';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What SSO does here</h3>
            <p>Instead of a FreeITSM password, a person is sent to their own identity provider (IdP) to sign in. FreeITSM never sees their password — it receives a signed token proving who they are. Multi-factor is handled by the provider, so SSO users aren't asked for a separate code.</p>
        </div>
    </div>
    <p>It works in two places, independently:</p>
    <div class="help-cards">
        <div class="help-card">
            <h4>Analysts</h4>
            <p>Your service-desk staff, on the main login page. Each analyst has one sign-in method (local password, or a specific provider), set for them in <strong>System &rarr; Analysts</strong> or taken from their team in <strong>System &rarr; Teams</strong>.</p>
        </div>
        <div class="help-card">
            <h4>Self-service portal</h4>
            <p>The people who raise tickets, on the portal login page. They're routed to a provider by their email — or, on a multi-company install, by their company.</p>
        </div>
    </div>
    <p>Everything is standard <strong>OpenID Connect</strong>, driven by the provider's discovery document, so the same setup works for Entra, Google, Okta, Keycloak, Auth0, Authentik and others — you only ever supply a display name, issuer URL, client ID and client secret.</p>
    <div class="help-note"><strong>Two global switches</strong> live at the top of System &rarr; Single Sign-On: <strong>Enable single sign-on</strong> (the master on/off) and <strong>Allow local login</strong> (whether the password form is offered). Both are reversible at any time — see <em>Break-glass &amp; safety</em>.</div>
</div>

<!-- 2. Which path -->
<div class="help-section" id="which">
    <div class="help-section-header"><?php echo helpSectionNum('which'); ?>
        <div>
            <h3>First decide: single-company or multi-company?</h3>
        </div>
    </div>
    <p>This is the only thing you need to get straight before you start. Everything else follows from it.</p>
    <div class="help-table"><table>
        <tr><th></th><th>Single-company</th><th>Multi-company (MSP)</th></tr>
        <tr><td><strong>You are…</strong></td><td>One organisation running FreeITSM for your own staff and users.</td><td>An MSP (or group) supporting several separate client companies from one install.</td></tr>
        <tr><td><strong>How many companies?</strong></td><td>Just one (the silent “Default”).</td><td>Two or more (System &rarr; Companies).</td></tr>
        <tr><td><strong>Identity providers</strong></td><td>Your own IdP(s), shared by everyone.</td><td>Each client brings <em>their own</em> IdP.</td></tr>
        <tr><td><strong>Portal login shows…</strong></td><td>Provider buttons up front.</td><td>Email first, then that person's company's provider(s). Global providers can still have a button.</td></tr>
    </table></div>
    <div class="help-note"><strong>How FreeITSM decides which you are:</strong> it counts companies. With one company it behaves as a single-company install; the moment you add a second company in System &rarr; Companies, the multi-company behaviour switches on automatically. You don't toggle a setting.</div>
    <p>Pick your section below.</p>
</div>

<!-- 3. Single-company -->
<div class="help-section" id="single">
    <div class="help-section-header"><?php echo helpSectionNum('single'); ?>
        <div>
            <h3>Single-company setup</h3>
            <p>You have one IdP (say Microsoft Entra) and you want your staff and/or portal users to sign in with it. Three steps.</p>
        </div>
    </div>

    <div class="help-steps">
        <div class="help-step"><div class="help-step-num">1</div><div><strong>Register an app in your identity provider.</strong> Create an app/registration in Entra, Okta, Google, etc. Set its redirect URI to the address below, and note the <strong>issuer URL</strong>, <strong>client ID</strong> and a <strong>client secret</strong>.<br><br>Redirect URI to register: <code><?php echo htmlspecialchars($redirectUri); ?></code><br>Sign-out (post-logout) addresses to register as well: <code><?php echo htmlspecialchars($signoutAnalystUri); ?></code> and <code><?php echo htmlspecialchars($signoutPortalUri); ?></code></div></div>
        <div class="help-step"><div class="help-step-num">2</div><div><strong>Add the provider here.</strong> System &rarr; Single Sign-On &rarr; <em>Add provider</em>. Paste the issuer URL, client ID and secret, give it a display name (e.g. “Sign in with Microsoft”), tick <strong>Enabled</strong>, and use <strong>Test</strong> to confirm the issuer is reachable. Turn on the master <strong>Enable single sign-on</strong> switch.</div></div>
        <div class="help-step"><div class="help-step-num">3</div><div><strong>Decide who uses it.</strong> For <strong>analysts</strong>, set the <em>Sign-in method</em> on their team in System &rarr; Teams, and everyone set to <em>Follow team</em> uses it — or set it for one person in System &rarr; Analysts. To have analysts created the first time they sign in, tick <em>Auto-create IT analysts</em>. For <strong>portal users</strong>, tick <em>Auto-create portal requesters</em> and they're created on first sign-in — or they're matched to an existing record by verified email. The two switches are separate, so you can let customers in without creating analysts (see <a href="#jit-rules">Auto-create rules</a>).</div></div>
    </div>

    <div class="help-note ok"><strong>That's it.</strong> The login pages now lead with your provider's button, with the password form tucked behind a “local account” link. Nothing else to configure — there are no companies to think about.</div>
</div>

<!-- 4. Multi-company -->
<div class="help-section" id="multi">
    <div class="help-section-header"><?php echo helpSectionNum('multi'); ?>
        <div>
            <h3>Multi-company (MSP) setup</h3>
            <p>You support several client companies, and each wants <em>their</em> people to sign in with <em>their</em> IdP. The portal figures out which client a person belongs to from their email, and sends them to the right provider. You never show one client's provider to another.</p>
        </div>
    </div>

    <h4>What you need from each client</h4>
    <div class="help-table"><table>
        <tr><th>You need…</th><th>Where it goes</th></tr>
        <tr><td>Their <strong>email domain(s)</strong> (e.g. <code>acme.co.uk</code>)</td><td>System &rarr; Companies &rarr; (their company) &rarr; Email domains</td></tr>
        <tr><td>Their IdP <strong>issuer URL, client ID, client secret</strong></td><td>System &rarr; Single Sign-On &rarr; Add provider, with <strong>Company</strong> set to that client</td></tr>
        <tr><td>A decision: <strong>auto-create users</strong> on or off</td><td>The same provider form</td></tr>
    </table></div>
    <p>And you give the client one thing back: the redirect URI to register in their IdP — <code><?php echo htmlspecialchars($redirectUri); ?></code> (the same for every client).</p>

    <h4>Setting up one client</h4>
    <div class="help-steps">
        <div class="help-step"><div class="help-step-num">1</div><div><strong>Add the company</strong> in System &rarr; Companies (if it isn't there already) and add its <strong>email domain(s)</strong>. This is the key that routes <code>someone@acme.co.uk</code> to Acme. (You can also map an individual address for people on personal/free email.)</div></div>
        <div class="help-step"><div class="help-step-num">2</div><div><strong>Add their provider</strong> in System &rarr; Single Sign-On, and set the new <strong>Company</strong> dropdown to that client. That marks the IdP as <em>owned by Acme</em> — it will only ever be offered to Acme's people, never to other clients or to your analysts.</div></div>
        <div class="help-step"><div class="help-step-num">3</div><div><strong>Choose auto-create.</strong> On = a brand-new Acme requester is created automatically the first time they sign in (zero pre-provisioning). Off = only people who already exist (e.g. raised a ticket) can sign in.</div></div>
    </div>

    <h4>How the routing then works</h4>
    <p>On a multi-company install the portal login leads with an <strong>email box</strong>. A client company's own providers never get a button there — that would show every visitor which IdP each of your clients uses. Your <strong>Global</strong> providers are the exception: they belong to you rather than to a client, so they show as buttons, as on a single-company install (untick <strong>Show the button on the portal login</strong> to hide one). When someone enters their email:</p>
    <ul>
        <li><strong>Their company has no IdP</strong> &rarr; they get the email + password form.</li>
        <li><strong>One IdP</strong> &rarr; they're sent straight to it.</li>
        <li><strong>Two or more</strong> (a client mid-migration between, say, Entra and Okta) &rarr; they're shown a small picker to choose. After their first successful sign-in they're remembered, so the picker is a once-only step.</li>
    </ul>
    <div class="help-note"><strong>Local always works as a floor.</strong> Anyone whose email isn't mapped to a company — including people on Gmail/Outlook personal addresses, or someone locked out of their IdP — falls through to the local password form. No one can be shut out.</div>

    <div class="help-note warn"><strong>Today, each client hands you a client secret</strong> (which you store and rotate). That's the “bring-your-own-credentials” model and it works for any provider. A future option — where you register one app and each client just clicks “consent”, with no secret to hand over — is designed but not yet built. It will not change anything above; only how a provider is added.</div>
</div>

<!-- 4a. Auto-create rules (discussion #155) -->
<div class="help-section" id="jit-rules">
    <div class="help-section-header"><?php echo helpSectionNum('jit-rules'); ?>
        <div>
            <h3>Auto-create rules: portal requesters and analysts</h3>
        </div>
    </div>
    <p>A sign-in provider can create accounts the first time someone signs in through it (sometimes called <em>just-in-time</em> or JIT provisioning). There are two separate switches, so one company sign-in can serve both your staff and your customers:</p>
    <ul>
        <li><strong>Auto-create portal requesters</strong> &mdash; someone signing in on the <em>self-service portal</em> for the first time gets a portal account. Leave it off if only people you already know (for example, contacts created from tickets) should get in.</li>
        <li><strong>Auto-create IT analysts</strong> &mdash; someone signing in on the <em>analyst</em> login for the first time gets an analyst account. Works for OpenID Connect and LDAP providers.</li>
        <li><strong>Default module access for auto-created analysts</strong> &mdash; shown while the analyst switch is on. List the modules new analysts get (for example <code>tickets, knowledge</code>). Blank means every module, so set it for a pilot.</li>
        <li><strong>Company access for auto-created analysts</strong> (multi-company installs) &mdash; which companies a new analyst can see: <strong>every company</strong> (the default, as before), <strong>only this provider's company</strong> (the Default company for a provider that belongs to no company), or <strong>none</strong> until an administrator grants some. It only applies when the account is created.</li>
    </ul>

    <h4>Someone with no analyst account signs in on the analyst login</h4>
    <p>For an OpenID Connect provider with <em>Auto-create IT analysts</em> off, choose what happens under <strong>Non-analyst sign-in action</strong>:</p>
    <ul>
        <li><strong>Warn and confirm</strong> &mdash; a page explains they have no analyst account and offers to continue to the self-service portal. Nothing happens unless they choose it. This is the default for a new provider.</li>
        <li><strong>Automatically switch</strong> &mdash; they go straight to the portal, as if they had signed in there.</li>
        <li><strong>Block</strong> &mdash; they see an error on the analyst login, as before this setting existed.</li>
    </ul>
    <p>Either way the portal applies its own rules: if <em>Auto-create portal requesters</em> is off and they have no portal account, they are refused there instead.</p>
    <div class="help-note"><strong>Upgrading keeps your settings.</strong> Before these switches were split, one <em>auto-create users</em> switch covered both portals. When you upgrade and run Database Verification, any provider that had it on keeps creating analysts as well, and the non-analyst action is set to <strong>Block</strong> so nobody sees a new screen until you choose it.</div>
</div>

<!-- 4a2. Profile sync (discussion #155) -->
<div class="help-section" id="profile-sync">
    <div class="help-section-header"><?php echo helpSectionNum('profile-sync'); ?>
        <div>
            <h3>Keeping profile details in step with your identity provider</h3>
        </div>
    </div>
    <p>An OpenID Connect provider can also fill in people's <strong>name, job title, department, office, phone and mobile</strong> from what it sends at sign-in. Choose how under <strong>Profile attribute sync</strong>:</p>
    <ul>
        <li><strong>Never</strong> (the default) &mdash; only the sign-in details are used. Profile fields are left alone and people edit their own.</li>
        <li><strong>On account creation</strong> &mdash; fills the fields in when an account is auto-created. After that, people can edit them and sign-in does not overwrite them.</li>
        <li><strong>Always</strong> &mdash; updates the fields at every sign-in. Because an edit would be undone at the next one, the fields become read-only in the portal's <strong>My Account</strong> and in the analyst's <strong>Preferences &rarr; My details</strong>, with a note saying they come from the identity provider.</li>
    </ul>
    <p>A portal user's own <em>preferred name</em> is never overwritten. Nothing is written until the person has passed every sign-in check, so a provider that is about to be refused cannot change anyone's details.</p>
    <p>LDAP and CardDAV are not affected by this setting: they keep details up to date their own way, through directory sync and address-book write-back.</p>

    <h4>Getting the details into the sign-in</h4>
    <p>Keep <strong>Scopes</strong> set to the standard <code>openid email profile</code> &mdash; job title and department are <em>claims</em>, not scopes. Add them as claims in your identity provider:</p>
    <ul>
        <li><strong>Microsoft Entra ID:</strong> <em>App registrations &rarr; your app &rarr; Token configuration &rarr; Add optional claim</em>, choose the <strong>ID</strong> token, and add the ones you want. Entra's userinfo endpoint only returns the basic profile, so for Entra these <strong>must</strong> be in the ID token.</li>
        <li><strong>Okta:</strong> <em>Security &rarr; API &rarr; Authorization Servers &rarr; Claims</em>, add claims mapped to <code>user.title</code>, <code>user.department</code> and <code>user.primaryPhone</code>.</li>
        <li><strong>Keycloak:</strong> <em>Client scopes &rarr; profile &rarr; Mappers</em>, add <em>User Attribute</em> mappers for <code>job_title</code>, <code>department</code> and <code>phone_number</code>.</li>
    </ul>
    <p>When sync is on, FreeITSM also asks the provider's userinfo endpoint for anything missing from the ID token. What the ID token says always wins.</p>
</div>

<!-- Groups from the identity provider (3.5.0) -->
<div class="help-section" id="sso-groups">
    <div class="help-section-header"><?php echo helpSectionNum('sso-groups'); ?>
        <div>
            <h3>Groups from the identity provider</h3>
        </div>
    </div>
    <p>When someone signs in with single sign-on, the identity provider sends a few facts about them - name, email, and usually the <strong>groups</strong> they are in. FreeITSM can use those groups to decide <strong>who can be an analyst</strong>, <strong>who is an administrator</strong> and <strong>which teams people are in</strong>, checked again at every sign-in. Manage staff in your identity provider and FreeITSM follows: add someone to a group and they get the access at their next sign-in; take them out and it goes.</p>
    <p>Open the provider in <strong>System &rarr; SSO</strong> and go to the <strong>Groups and teams</strong> tab:</p>
    <ol>
        <li><strong>Where the groups come from</strong> - choose <em>groups</em> for most providers, <em>realm_access.roles</em> for Keycloak, or <em>Something else</em> if yours uses another name.</li>
        <li><strong>Sign in once</strong> through the provider, then open it again. <strong>Groups seen</strong> now lists every group that sign-in sent.</li>
        <li><strong>Name them.</strong> Some providers - Microsoft Entra among them - send a code such as <code>78d21822-f5ee-...</code> rather than a name. Type a name beside each code (find it in Entra under <em>Groups</em>, the group, <em>Object ID</em>). The dropdowns below then show your names.</li>
        <li><strong>Analyst group</strong> (optional) - only people in this group can sign in as analysts, and only they are created automatically. Someone who leaves it is refused at their next sign-in. This is the same as an LDAP directory's analyst group.</li>
        <li><strong>Administrator group</strong> (optional) - people in it are administrators, people not in it are not. Leave it empty to manage administrators by hand. The last administrator is never removed.</li>
        <li><strong>Teams</strong> - add a row for each group: choose the group, choose the team. What the team can do - its modules, companies and roles - is set in <strong>System &rarr; Teams</strong>, the same as for anyone you add to a team by hand.</li>
    </ol>
    <div class="help-note ok"><strong>Setting this up for the first time?</strong> Follow the <a href="sso-groups.php">step-by-step guide</a> - it has a checklist for Microsoft Entra, Okta and Keycloak.</div>
    <h4>What happens at each sign-in</h4>
    <ul>
        <li>People are <strong>added</strong> to the teams their groups point to and <strong>taken out</strong> of those teams when they leave the group. Teams with no row here are never changed, so teams you manage by hand are safe.</li>
        <li>Analysts the provider creates get their modules from these teams (unless the provider names default modules). For companies, choose <strong>Company access for auto-created analysts</strong> on the <em>Signing in</em> tab - set it to <em>None</em> if your teams give the companies.</li>
        <li>Group names match ignoring capital letters, in any language.</li>
        <li>A group that nobody has signed in with yet can still be chosen, but it is pointed out, in case it is misspelt.</li>
    </ul>
    <h4>Getting the groups into the sign-in</h4>
    <ul>
        <li><strong>Microsoft Entra ID:</strong> <em>Token configuration &rarr; Add groups claim</em>. Entra sends group <strong>IDs</strong> (long strings of letters and numbers) unless you set it to send names - either way, choose them from the list after a sign-in.</li>
        <li><strong>Okta:</strong> add a <code>groups</code> claim to the authorization server, with a filter for the groups you want.</li>
        <li><strong>Keycloak:</strong> realm roles arrive in <code>realm_access.roles</code>; or add a <em>Group Membership</em> mapper named <code>groups</code>.</li>
    </ul>
</div>

<!-- 4a3. Portal email domains (GH #147) -->
<div class="help-section" id="portal-domains">
    <div class="help-section-header"><?php echo helpSectionNum('portal-domains'); ?>
        <div>
            <h3>Sending portal users straight to SSO by email domain</h3>
        </div>
    </div>
    <p>Normally the portal's email box only sends someone to single sign-on once it knows them: after their first SSO sign-in, or (on a multi-company install) because their company owns a provider. A first-timer who types their email and presses <strong>Continue</strong> gets the password box, and has to know to use the provider button instead.</p>
    <p>An OpenID Connect provider's <strong>Email domains</strong> box fixes that. List your domains, one per line, for example <code>company.com</code>, and on the portal:</p>
    <ul>
        <li>an address on one of those domains goes <strong>straight to the provider</strong>, even the first time;</li>
        <li>any other address gets the password box, exactly as before.</li>
    </ul>
    <p>Untick <strong>Show the button on the portal login</strong> as well and the page is just the email box and Continue. One box works for your staff and your outside customers, and nobody has to choose.</p>
    <h4>Worth knowing</h4>
    <ul>
        <li><strong>It includes people who use a password today.</strong> Anyone on a listed domain is sent to the provider. When they sign in there, their existing portal account is linked by email, and their tickets stay with them.</li>
        <li><strong>New people need auto-create.</strong> If someone on the domain has no portal account yet, turn on <em>Auto-create portal requesters</em>, or the portal will refuse them after they sign in.</li>
        <li><strong>The whole domain only.</strong> <code>company.com</code> does not cover <code>uk.company.com</code>; add that on its own line.</li>
        <li><strong>One provider per domain</strong>, and never a public email domain such as <code>gmail.com</code>. Saving refuses both.</li>
        <li><strong>Directory users are left alone.</strong> Someone already assigned to an LDAP directory, or to another provider, keeps signing in the way they do now.</li>
        <li><strong>The portal only.</strong> The analyst login is not affected; analysts are assigned to a provider one by one or through their team.</li>
        <li><strong><code>?local=1</code> still works</strong> as the way back to the password form.</li>
    </ul>
</div>
<!-- 4b. LDAP / Active Directory -->
<div class="help-section" id="ldap">
    <div class="help-section-header"><?php echo helpSectionNum('ldap'); ?>
        <div>
            <h3>LDAP / Active Directory</h3>
        </div>
    </div>
    <p>If your people already exist in Active Directory (or OpenLDAP, FreeIPA, 389 Directory Server), FreeITSM can check their password against it directly. They keep the username and password they already use everywhere else, and you don't create an account here for every new starter.</p>
    <div class="help-note"><strong>This is not single sign-on.</strong> With SSO the browser bounces to your identity provider and back. With LDAP people type their directory password into <em>FreeITSM's own</em> login form, and we check it with the directory. Both live on this page because both answer the same question — how do people sign in — but they behave differently, and only SSO gives you one shared session across apps.</div>
    <h4>How it works</h4>
    <p>Someone types <code>r.patel</code>, not their full directory path, so FreeITSM does this on every sign-in:</p>
    <ol>
        <li>Connects to your directory and signs in as a <strong>read-only service account</strong>, so it is allowed to look people up.</li>
        <li><strong>Searches</strong> for the person to find their full entry.</li>
        <li>Tries to sign in <strong>as that person</strong> with the password they typed. If the directory accepts it, the password was right.</li>
        <li>Checks their <strong>groups</strong> to decide what, if anything, they're allowed to be.</li>
    </ol>
    <p>That's why the setup form asks for a server, a service account and a base DN — they're the ingredients for those steps. FreeITSM never reads or stores anyone's directory password.</p>
    <div class="help-note ok"><strong>Leavers are handled for you.</strong> Disable someone in the directory and they can no longer sign in here, immediately — the directory refuses the sign-in, so there is nothing to remember to switch off in FreeITSM.</div>
</div>

<!-- 4c. LDAP setup -->
<div class="help-section" id="ldap-setup">
    <div class="help-section-header"><?php echo helpSectionNum('ldap-setup'); ?>
        <div>
            <h3>Setting up a directory</h3>
        </div>
    </div>
    <p>Go to <strong>System → Authentication</strong>, click <strong>+ Add</strong>, and set <strong>Type</strong> to <em>LDAP / Active Directory</em>. Then pick the <strong>Active Directory</strong> or <strong>OpenLDAP</strong> preset — it fills in the filter and attribute names that are right for that kind of directory, so you only need to supply the four things that are specific to you:</p>
    <ul>
        <li><strong>Server</strong> — a domain controller's hostname or IP, e.g. <code>dc1.example.local</code>.</li>
        <li><strong>Service account</strong> — a <em>read-only</em> account used only to look people up. Active Directory accepts <code>svc-freeitsm@example.local</code>; OpenLDAP wants a full DN like <code>cn=svc-freeitsm,dc=example,dc=com</code>. It never needs write access.</li>
        <li><strong>Base DN</strong> — where to search from, e.g. <code>DC=example,DC=local</code>, or narrow it to <code>OU=Staff,DC=example,DC=local</code>.</li>
        <li><strong>Encryption</strong> — see the warning below.</li>
    </ul>
    <p>Use the <strong>Test</strong> button before saving. Leave the test user blank to check only that the service account can connect and read; fill one in and it runs a real sign-in and shows you exactly which name, email and groups came back. That is the quickest way to catch a wrong attribute name or a too-narrow base DN.</p>
    <div class="help-note warn"><strong>Use STARTTLS or LDAPS in production.</strong> With encryption set to <em>None</em>, people's passwords cross your network in the clear on every sign-in. Many Active Directory servers refuse password sign-ins over unencrypted LDAP anyway, so if plain LDAP fails with an error about strong authentication, that's why — switch to LDAPS.</div>
    <p>Turn on <strong>Auto-create users on first login (JIT)</strong> so a new starter gets an account the first time they sign in. Read the next section before you do — on its own, that lets anyone in the directory in.</p>
</div>

<!-- 4d. LDAP groups -->
<div class="help-section" id="ldap-groups">
    <div class="help-section-header"><?php echo helpSectionNum('ldap-groups'); ?>
        <div>
            <h3>Controlling access by group</h3>
        </div>
    </div>
    <p>Auto-create is the point of connecting a directory — but by itself it means <em>everyone</em> your directory recognises becomes an analyst. Point that at a 500-person company and you get 500 analysts. Naming groups is what stops that.</p>
    <div class="help-table"><table>
        <thead><tr><th>Setting</th><th>What it does</th></tr></thead>
        <tbody>
            <tr><td><strong>Analyst group</strong></td><td>Members get an analyst account and can use the main FreeITSM login.</td></tr>
            <tr><td><strong>Self-service user group</strong></td><td>Members get a self-service account — they can raise and track their own tickets, but cannot sign in as an analyst.</td></tr>
            <tr><td><strong>Neither</strong></td><td>They cannot sign in at all, even with a correct password.</td></tr>
            <tr><td><strong>Both boxes blank</strong></td><td>No gate: anyone the directory recognises becomes an analyst. Fine for a small single-team install; risky anywhere else.</td></tr>
        </tbody>
    </table></div>
    <p>Type either the group's plain name (<code>ITSM-Analysts</code>) or its full DN — both work, and case doesn't matter.</p>
    <div class="help-note ok"><strong>Nested groups work on Active Directory.</strong> If your analyst group contains other groups rather than people directly, members of those inner groups still get in. The AD preset handles this for you. OpenLDAP has no equivalent, so there you must name a group that contains the people themselves.</div>
    <div class="help-note"><strong>It fails safely.</strong> If FreeITSM can't read your groups for any reason, nobody is let in by accident — an unreadable group list denies access rather than granting it. So if <em>everyone</em> is suddenly refused, suspect the group settings, not people's passwords.</div>
</div>

<!-- 4e. LDAP troubleshooting -->
<div class="help-section" id="ldap-faq">
    <div class="help-section-header"><?php echo helpSectionNum('ldap-faq'); ?>
        <div>
            <h3>LDAP troubleshooting</h3>
        </div>
    </div>
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

<!-- 4f. CardDAV -->
<div class="help-section" id="carddav">
    <div class="help-section-header"><?php echo helpSectionNum('carddav'); ?>
        <div>
            <h3>CardDAV address books</h3>
            <p>If the people who raise tickets with you already exist in a shared address book, FreeITSM can read it and keep their contact details up to date here. Change somebody's phone number or job title in the address book, and the next import changes it in your service desk too.</p>
        </div>
    </div>
    <div class="help-note warn"><strong>Nobody signs in through this.</strong> It is the odd one out on this page: a CardDAV address book is not an identity provider and has no login button. It imports <em>contact details</em>, nothing more. It lives here because it is another source of people, and it shares the same safety rules as an LDAP import.</div>
    <h4>Who it imports</h4>
    <p>Contacts become <strong>self-service users</strong> — the people who raise tickets. They are not analysts, and an import can never create one. If you want your service-desk staff to come from a directory, that is the LDAP section above.</p>
    <div class="help-note"><strong>It reads. It never writes.</strong> FreeITSM does not change, add or delete anything in your address book, so it is safe to point at a book you rely on elsewhere. The connection only ever needs an account that can read.</div>
    <p>Any CardDAV server works — it is a standard, and FreeITSM asks the server what it holds rather than assuming. Tested against <strong>Baïkal</strong>; the same setup applies to Nextcloud, ownCloud, Radicale, or anything else built on sabre/dav.</p>
</div>

<!-- 4g. CardDAV setup -->
<div class="help-section" id="carddav-setup">
    <div class="help-section-header"><?php echo helpSectionNum('carddav-setup'); ?>
        <div>
            <h3>Connecting an address book</h3>
        </div>
    </div>
    <p>Go to <strong>System &rarr; Authentication</strong>, click <strong>+ Add</strong>, and set <strong>Type</strong> to <em>CardDAV address book (contacts only, no sign-in)</em>. You then get a page with three tabs — Connection, Contacts and History.</p>

    <div class="help-steps">
        <div class="help-step"><div class="help-step-num">1</div><div><strong>Server URL.</strong> The CardDAV path, not the web page you log into. On a sabre/dav server it usually looks like <code>https://dav.example.com/dav.php/addressbooks/jsmith/</code>. Point it at the level that <em>holds</em> your address books rather than at one book, and let FreeITSM list them.</div></div>
        <div class="help-step"><div class="help-step-num">2</div><div><strong>Username and password.</strong> A read-only account if your server can make one. Leave <strong>Authentication</strong> on <em>Automatic</em> — it asks the server which scheme it wants and uses that. A standard Baïkal wants Digest rather than Basic, which is exactly the sort of thing you should not have to know.</div></div>
        <div class="help-step"><div class="help-step-num">3</div><div><strong>Press Test connection.</strong> This is not just a reachability check — it is how the rest of the form gets filled in. FreeITSM signs in, asks the server which address books it can see, and lists them for you to choose from.</div></div>
        <div class="help-step"><div class="help-step-num">4</div><div><strong>Pick the address book</strong>, then move to the <strong>Contacts</strong> tab to choose which of its contacts you want. Save, and use <strong>Preview</strong> before you import for real.</div></div>
    </div>

    <div class="help-note"><strong>Test connection before the pickers will work.</strong> The address book list and the contact groups both come from your server, so until a test has succeeded there is nothing to choose from and the form says so. If you change the URL or the password afterwards, test again — the lists belong to the old connection until you do.</div>
</div>

<!-- 4h. CardDAV scope -->
<div class="help-section" id="carddav-scope">
    <div class="help-section-header"><?php echo helpSectionNum('carddav-scope'); ?>
        <div>
            <h3>Choosing which contacts</h3>
        </div>
    </div>
    <p>An address book usually holds more than you want in your service desk — suppliers, family, the plumber. <strong>Which contacts to bring in</strong> gives you three answers, and FreeITSM looks inside the book you chose to work out which of them it can offer:</p>
    <div class="help-table"><table>
        <thead><tr><th>Option</th><th>What you get</th></tr></thead>
        <tbody>
            <tr><td><strong>Everyone in the book</strong></td><td>Every contact. Right when the book exists for this purpose.</td></tr>
            <tr><td><strong>Chosen groups</strong></td><td>Only members of the contact groups you tick. Groups are what most address-book apps call a “list” or a “group”.</td></tr>
            <tr><td><strong>Chosen categories</strong></td><td>Only contacts carrying the tags you tick. Categories are the per-contact labels your app may call “tags”.</td></tr>
        </tbody>
    </table></div>
    <p>Both pickers are tick boxes, not a single choice — you can bring in three groups out of nine, and <strong>All</strong> / <strong>None</strong> links save you clicking through a long list. Whether your server offers groups, categories, both or neither depends on what the contacts in it actually use, so FreeITSM shows you what is really there instead of a fixed list.</p>
    <div class="help-note ok"><strong>Not sure which you have?</strong> Press Test connection and look. If the groups list is empty, nothing in that book uses groups — try categories, or bring in everyone and narrow it later. Changing your mind is just a re-run.</div>
    <div class="help-note"><strong>Group cards are not people.</strong> An address book stores a group as a card of its own. FreeITSM skips those, so you never end up with a contact called “Acme Staff” sitting in your people list looking like somebody.</div>
</div>

<!-- 4i. CardDAV fields -->
<div class="help-section" id="carddav-fields">
    <div class="help-section-header"><?php echo helpSectionNum('carddav-fields'); ?>
        <div>
            <h3>What gets imported</h3>
        </div>
    </div>
    <p>Seven things, and each one maps to a field you can already see on a person in FreeITSM:</p>
    <div class="help-table"><table>
        <thead><tr><th>In FreeITSM</th><th>Comes from</th></tr></thead>
        <tbody>
            <tr><td><strong>Name</strong></td><td>The contact's display name.</td></tr>
            <tr><td><strong>Email</strong></td><td>Their first valid email address. This is also how a contact is matched to somebody who already exists here.</td></tr>
            <tr><td><strong>Job title</strong></td><td>The contact's title.</td></tr>
            <tr><td><strong>Department</strong></td><td>The second part of the organisation field, which is where address books keep the department.</td></tr>
            <tr><td><strong>Office</strong></td><td>The town or city from their address — the work address if they have one.</td></tr>
            <tr><td><strong>Phone</strong> and <strong>Mobile</strong></td><td>Their numbers, split by the type the contact card gives them.</td></tr>
        </tbody>
    </table></div>
    <h4>What does not come across, and why</h4>
    <ul>
        <li><strong>Employee number.</strong> A contact card has nowhere to put one. Left empty rather than filled with something that merely looks like one.</li>
        <li><strong>Manager.</strong> There is a way to record it in the standard, but almost nothing writes it, so the reporting line is left alone rather than half-imported.</li>
        <li><strong>A sign-in.</strong> Contacts have no username and no password here. They can still use the self-service portal if you give them access to it the usual way.</li>
    </ul>
    <div class="help-note"><strong>A contact with no unique id is skipped.</strong> Every contact card carries one, and it is what lets FreeITSM recognise the same person on the next import even after a rename or a move between books. A card without one would be imported again as a second copy every single run, so it is skipped and counted instead.</div>
    <div class="help-note"><strong>Somebody already here?</strong> The <strong>When somebody is already here</strong> setting decides: link them to this address book, so the import keeps them up to date from now on, or leave them alone and flag it for you to look at. Matching is by email address.</div>
</div>

<!-- 4i2. CardDAV write-back -->
<div class="help-section" id="carddav-write">
    <div class="help-section-header"><?php echo helpSectionNum('carddav-write'); ?>
        <div>
            <h3>Sending changes back</h3>
        </div>
    </div>
    <p>By default an address book import only ever <em>reads</em>. Switch on <strong>Write changes made in FreeITSM back to the address book</strong>, on the Contacts tab, and it works the other way too: when an analyst corrects a contact's details on a ticket, the contact card is updated as well.</p>
    <p>This exists because the correction usually happens <em>during the call</em>. Without it, the analyst has to remember to go and fix the same detail in a second application afterwards, which is exactly the sort of double entry a service desk tool ought to remove.</p>

    <h4>What it changes, and what it never touches</h4>
    <p>Only the five details an address book actually holds: <strong>job title</strong>, <strong>department</strong>, <strong>office</strong>, <strong>phone</strong> and <strong>mobile</strong>. Employee number and manager stay in FreeITSM, because a contact card has nowhere to keep them — which also means those two are yours to fill in freely even on an imported contact.</p>
    <div class="help-note"><strong>Nothing else on the card is altered.</strong> FreeITSM edits the single line that changed and leaves the rest of the card exactly as it found it — photo, notes, birthday, the full street address, group membership, and anything your other systems have added. It does not rebuild the card from what it knows, which is the usual way an integration quietly strips a contact of everything it does not understand.</div>

    <h4>When two people change the same thing</h4>
    <p>Before writing, FreeITSM re-reads the card and compares it with what it last imported. If somebody has changed <em>the same detail</em> in the address book in the meantime, the change is refused and you are told what their version says — nothing is overwritten. If they changed something else on the card, that is left alone and your change goes through.</p>
    <p>A refusal is not an error to work around. It means two people had different information, and the right next step is to import again and see theirs before deciding.</p>

    <h4>Check permission first</h4>
    <p>Press <strong>Check permission</strong> and FreeITSM asks the server what this account is allowed to do, without writing anything. Plenty of address books are shared or subscribed and are read-only however correct your password is — far better to know that here than to discover it halfway through somebody's edit.</p>
    <div class="help-note"><strong>If the answer is no.</strong> Grant the account write access on the address book server itself. Nothing in FreeITSM can work around a permission the server has not given, and changes simply stay in FreeITSM until it does.</div>

    <h4>When something goes wrong</h4>
    <p><strong>Your work is never lost.</strong> The person's record in FreeITSM is saved first and completely; sending the change onward happens afterwards and cannot undo it. If the address book is unreachable, read-only, or holding a newer value, the save still stands here.</p>
    <p><strong>You are always told.</strong> A save that reached the card says so; one that did not says what stopped it. An unreachable server costs a couple of seconds rather than holding the form open, because a write-back runs on a shorter time limit than an import does.</p>
    <p>Every attempt is recorded on the <strong>History</strong> tab under <em>Changes sent back</em> — who, when, which details, and <strong>what the server itself said</strong>, kept word for word. That last part is the bit worth having: when an address book refuses something, the reason is in its own response, and a tidied-up version of it is no use for diagnosing a server FreeITSM cannot log in to.</p>

    <h4>The four results, and what to do about each</h4>
    <div class="help-table"><table>
        <thead><tr><th>Result</th><th>What it means</th><th>What to do</th></tr></thead>
        <tbody>
            <tr><td><strong>Written</strong></td><td>The contact card was updated.</td><td>Nothing.</td></tr>
            <tr><td><strong>Refused</strong></td><td>Somebody had already changed <em>the same detail</em> in the address book, and FreeITSM did not overwrite them.</td><td>Import to bring their version in, then decide which is right. If yours was, edit again and it will go through.</td></tr>
            <tr><td><strong>Failed</strong></td><td>The server could not be reached, or said no. The log row carries its exact words.</td><td>Run <strong>D015</strong> — it separates "cannot reach", "cannot sign in", "cannot read" and "cannot write".</td></tr>
            <tr><td><strong>Nothing to send</strong></td><td>The card already matched — usually a save where none of the five details actually changed.</td><td>Nothing. It is logged separately so a write-back that is doing nothing cannot look busy.</td></tr>
        </tbody>
    </table></div>
    <h4>Adding people who are not in the address book yet</h4>
    <p>Somebody who emailed in exists in FreeITSM but not in your address book. Tick <strong>Let analysts add people to this address book</strong> (it needs write-back on, and is off by default) and they get an <strong>Add to address book</strong> button on that person in <strong>Tickets → Users</strong> and <strong>Assets → Users</strong>. It creates their contact card and links them, so there is no second copy to type in elsewhere.</p>
    <ul>
        <li><strong>It asks first</strong>, showing which details go on the card.</li>
        <li><strong>No duplicates.</strong> If any card in the book already has their email address, nothing is written; run the import instead and it links that card. FreeITSM's own record is linked, never copied.</li>
        <li><strong>It lands where the import looks.</strong> If the address book only imports one tag or group, the new card gets that tag or joins that group. Then FreeITSM reads the book back and asks the import's own rules whether the card would be picked up; if not, it removes the card again and links nobody. Without this, the next imports would not see the person and would eventually mark them as having left.</li>
        <li>Each addition shows in the write log as <strong>Added</strong>.</li>
    </ul>
    <div class="help-note warn"><strong>Being in your address book may mean something.</strong> Some organisations treat it as the list of people they may hold details about. Switch this on only if analysts should be able to add to that list. And if you later narrow an address book to one tag or group, people added before then are treated like any other card without it.</div>
    <div class="help-note"><strong>"Refused" is not a fault.</strong> It is the safety net working. If you find yourself trying to stop refusals happening, step back — each one is protecting a value somebody else entered deliberately. A refusal is also <em>per detail</em>: if an analyst changed the mobile and somebody else changed the job title, the mobile still goes through.</div>
</div>

<!-- 4i3. CardDAV diagnostics -->
<div class="help-section" id="carddav-diagnostics">
    <div class="help-section-header"><?php echo helpSectionNum('carddav-diagnostics'); ?>
        <div>
            <h3>Checking it is working</h3>
        </div>
    </div>
    <p>Two tools under <strong>System &rarr; Debug Tools</strong> answer the two different questions people actually have.</p>

    <h4>D015 — is the connection healthy?</h4>
    <p>Walks the connection one rung at a time: reach the server, sign in, read the chosen book, and may this account write to it. They fail for entirely different reasons, so each is reported separately rather than as one verdict — being told "it does not work" leaves you four things to check.</p>
    <p>It also names the mismatch that catches people out: <strong>write-back switched on while the account has no write permission</strong>, which saves every change here and has every one of them refused. And it reports how many people are missing the card reference a write-back needs, which is what contacts imported before this feature existed look like until the next import.</p>
    <div class="help-note">It writes nothing. The permission question is asked of the server directly, rather than proved by creating a test contact and deleting it again — a delete can fail, and you would be left with a contact called "FreeITSM test" in a real address book.</div>

    <h4>D016 — do the two lists still agree?</h4>
    <p>A top-to-bottom comparison: every imported person against their card as it stands now, detail by detail, with both values side by side. It also flags cards that have gone from the server, and contacts on the server that never came here (usually correct — they are outside the group or tag you chose).</p>
    <p>Worth running before you switch write-back on, and any time somebody says a number is out of date and you need to know <em>which side</em> is stale.</p>
    <div class="help-note"><strong>It changes nothing, and offers no "fix it" button on purpose.</strong> Which side is right is a judgement, and a single button that reconciles hundreds of contacts at once is how a careful integration turns into a data-loss incident. It also summarises which details drift most across everybody, which is the honest evidence for whether keeping them in step is worth it at all.</div>
</div>

<!-- 4j. CardDAV safety and scheduling -->
<div class="help-section" id="carddav-safety">
    <div class="help-section-header"><?php echo helpSectionNum('carddav-safety'); ?>
        <div>
            <h3>Safety, leavers and scheduling</h3>
        </div>
    </div>
    <p>An import is the sort of job that is quietly destructive when it goes wrong, so it is built around three rules. They are the same rules as an LDAP import, and they are not optional.</p>
    <div class="help-table"><table>
        <thead><tr><th>Rule</th><th>What it means for you</th></tr></thead>
        <tbody>
            <tr><td><strong>Nobody is ever deleted</strong></td><td>The worst an import can do to a person is mark them as having left. Their tickets and history stay exactly where they are.</td></tr>
            <tr><td><strong>A run that looks wrong changes nothing</strong></td><td>If an import finds far fewer contacts than last time — by default a fifth fewer — it stops before touching anything and tells you why. Pointing at the wrong address book looks identical to everybody leaving at once, and this is what keeps the two apart.</td></tr>
            <tr><td><strong>Missing once is noise</strong></td><td>Somebody absent from one import is not treated as a leaver. They have to be missing three imports running (your setting) before they are marked as left. Set it to 0 and that never happens automatically.</td></tr>
        </tbody>
    </table></div>
    <div class="help-note ok"><strong>Preview changes nothing, and runs the real thing.</strong> It is the same code as a live import with the writing switched off, so what it tells you is what would happen — not an estimate. Worth doing first, every time.</div>
    <p>A scope that matches <em>nothing</em> also stops the run rather than importing nobody. A group renamed on your server is far likelier than your whole company leaving.</p>

    <h4>Running it on a schedule</h4>
    <p>Nothing imports on its own. You run it from the <strong>Run</strong> button here, or you set up a scheduled task — the same one that runs an LDAP import:</p>
    <p><code>php scripts/directory_sync.php --all</code></p>
    <p>That picks up every enabled source with importing switched on, address books and directories alike, and sends each through the right engine. Nightly is a sensible starting point. It reports a proper exit code, so a monitored task tells you when an import failed instead of quietly doing nothing for months.</p>

    <h4>History</h4>
    <p>The <strong>History</strong> tab lists every import and what it did — by hand or scheduled, how many contacts were read, created, updated and skipped. A run shown as <strong>stopped</strong> was halted by the safety check, which means nothing was changed and it is waiting on you rather than broken.</p>
</div>

<!-- 4k. CardDAV troubleshooting -->
<div class="help-section" id="carddav-faq">
    <div class="help-section-header"><?php echo helpSectionNum('carddav-faq'); ?>
        <div>
            <h3>CardDAV troubleshooting</h3>
        </div>
    </div>
    <ul>
        <li><strong>Test connection says it connected but found no address books.</strong> The URL is a level too deep or too shallow, or it is the web interface rather than the CardDAV path. The error says which of the two it looks like. On a sabre/dav server the path contains <code>/dav.php/addressbooks/</code>.</li>
        <li><strong>It connects in a browser but not here.</strong> Your browser had a session; this does not. Check the username and password on their own, and remember many servers want a dedicated app password rather than the account password.</li>
        <li><strong>Wrong username or password, but they are definitely right.</strong> Set <strong>Authentication</strong> explicitly to Digest, then to Basic. Automatic asks the server what it offers and the error names the schemes it was given — if that list is empty, something in front of the server is stripping the challenge.</li>
        <li><strong>The groups and categories lists are empty.</strong> Either nothing in that book uses them, or the test ran before you chose the book. Choose the address book, test again, and look at both lists.</li>
        <li><strong>The import stopped and changed nothing.</strong> That is the safety check doing its job. Either the scope matched nothing — a renamed group is the usual reason — or the count dropped sharply. Preview, confirm the numbers look right, and run again.</li>
        <li><strong>Fewer contacts imported than the book holds.</strong> Group cards are skipped, and so is any contact with no unique id. The run's summary counts them separately from the ones it brought in.</li>
        <li><strong>Somebody's details are not updating.</strong> They are probably not linked to this address book — check the conflict setting, and whether they were created here by hand before the import existed. Only people the import manages are kept up to date by it.</li>
        <li><strong>Nothing has imported for weeks.</strong> An import only runs when something runs it. Check your scheduled task exists and is firing; the History tab shows the last run and where it came from.</li>
    </ul>

    <h4>Sending changes back</h4>
    <ul>
        <li><strong>The details are greyed out and I cannot edit them.</strong> Either <em>Write changes made in FreeITSM back to the address book</em> is switched off, or it was ticked and <strong>Save</strong> was never pressed. The tick alone does not store it. With write-back on, all seven details become editable; with it off, the five the address book owns stay read-only.</li>
        <li><strong>Employee number and manager are greyed out on a contact.</strong> They should not be, and are not any more. A contact card has nowhere to keep either, so an address book import never fills them in — which means they are yours to type regardless of write-back. If you still see them locked, the person came from a <em>directory</em> rather than an address book, where all seven genuinely are owned elsewhere.</li>
        <li><strong>It says saved, but the card never changes.</strong> Check the <em>Changes sent back</em> log on the History tab. No row at all means nothing was attempted — the usual cause is that this contact has no card reference yet, which happens to anybody imported before write-back existed. <strong>D015</strong> counts them under "missing a card URL", and the fix is simply to run an import.</li>
        <li><strong>Everything says Refused.</strong> Your copy is stale — something else is maintaining those contacts and FreeITSM has not read them recently. Import, then try again. <strong>D016</strong> shows exactly how far apart the two lists have drifted, and which details are responsible.</li>
        <li><strong>Everything says Failed with the same message.</strong> That is a configuration problem rather than a data one. Run <strong>D015</strong>: it walks reach, sign in, read and write separately, so you find out which of the four is actually broken instead of guessing.</li>
        <li><strong>Failed, saying the account may not write.</strong> The address book is read-only for that account. This must be fixed on the address book server — nothing in FreeITSM can grant a permission the server withholds. Shared, subscribed and published books are commonly read-only however correct the password is.</li>
        <li><strong>It worked, and now it does not.</strong> The log is in date order; the last successful row tells you when the behaviour changed, which is usually enough to remember what else changed that day.</li>
        <li><strong>Does a customer correcting their own details in the portal get sent back?</strong> No. Write-back carries an <em>analyst's</em> edit only. A portal user is still refused on an imported contact, because those five details belong to the address book.</li>
    </ul>
</div>

<!-- 4l. Microsoft Entra ID directory sync -->
<div class="help-section" id="entra">
    <div class="help-section-header"><?php echo helpSectionNum('entra'); ?>
        <div>
            <h3>Importing people and groups from Microsoft Entra ID</h3>
            <p>If you already sign people in with Microsoft Entra ID, the same provider can also bring them into FreeITSM ahead of time &mdash; with their job title, department, office, phones and manager &mdash; along with the groups they belong to.</p>
        </div>
    </div>
    <p>Without this, a person only exists here once they have signed in for the first time. That is too late for most service desks: you cannot assign a laptop to a new starter, raise a ticket on somebody's behalf, or route an approval to "everyone in Finance" until each of them has happened to log in.</p>
    <div class="help-note"><strong>It is not a separate provider.</strong> There is nothing new to add. Any OpenID Connect provider whose issuer is Microsoft Entra ID (<code>login.microsoftonline.com</code>, including the US Government and China clouds) gets an extra <strong>Directory sync (users and groups)</strong> button beside Edit on <strong>System &rarr; Authentication</strong>. That opens a page with four tabs: Connection, Importing people, Groups and History.</div>
    <h4>Who it imports</h4>
    <p>People become <strong>self-service users</strong> &mdash; the people who raise tickets. An import never creates an analyst; analysts still come from sign-in, through the <a href="#jit-rules">auto-create rules</a> and the <a href="#sso-groups">analyst group</a>.</p>
    <div class="help-note ok"><strong>It reads. It never writes.</strong> FreeITSM only reads your directory through Microsoft Graph. Nothing in Entra is created, changed or deleted, and the permissions it asks for are read-only.</div>
    <p>It follows exactly the same safety rules as an LDAP or address-book import: nobody is deleted, a run that looks wrong changes nothing, and somebody has to be missing several runs in a row before they are marked as left. See <a href="#entra-schedule">Safety and scheduling</a>.</p>
</div>

<!-- 4m. Entra setup -->
<div class="help-section" id="entra-setup">
    <div class="help-section-header"><?php echo helpSectionNum('entra-setup'); ?>
        <div>
            <h3>Connecting to Microsoft Graph</h3>
        </div>
    </div>
    <p>Signing a person in and reading the whole directory are different jobs. Sign-in acts <em>on behalf of</em> the person; an import has nobody signed in, so the app registration has to authenticate as itself. That is why it needs two things sign-in does not: <strong>application permissions</strong> and a <strong>client secret</strong>.</p>

    <div class="help-steps">
        <div class="help-step"><div class="help-step-num">1</div><div><strong>Give the app registration permission to read the directory.</strong> In the Microsoft Entra admin centre open <em>App registrations &rarr; your app &rarr; API permissions &rarr; Add a permission &rarr; Microsoft Graph &rarr; <strong>Application</strong> permissions</em> and add:
            <ul>
                <li><code>User.Read.All</code> &mdash; people, their details and their manager</li>
                <li><code>GroupMember.Read.All</code> &mdash; groups and who is in them</li>
            </ul>
            Then press <strong>Grant admin consent</strong>. Until consent is granted the permissions are listed but do nothing. <em>Delegated</em> permissions of the same name are not enough &mdash; they only work while somebody is signed in.</div></div>
        <div class="help-step"><div class="help-step-num">2</div><div><strong>Make sure there is a client secret.</strong> Under <em>Certificates &amp; secrets</em>. Copy the <strong>Value</strong>, not the <em>Secret ID</em> &mdash; Entra shows the value only once, straight after you create it. If your sign-in already uses a secret, that one is used.</div></div>
        <div class="help-step"><div class="help-step-num">3</div><div><strong>Open Directory sync</strong> from the provider's row on <strong>System &rarr; Authentication</strong>. On the <strong>Connection</strong> tab, the <strong>Tenant ID</strong> is normally taken from the issuer URL and can be left blank. Only an issuer using <code>common</code> or <code>organizations</code> needs it typed in: use the <em>Directory (tenant) ID</em> from the app registration's Overview page.</div></div>
        <div class="help-step"><div class="help-step-num">4</div><div><strong>Choose which app registration reads the directory.</strong> <em>The one used for sign-in</em> is simplest. <em>A separate app registration</em>, with its own client ID and secret, keeps directory-wide permissions off the app every user signs in through &mdash; worth it if your security team prefers least privilege.</div></div>
        <div class="help-step"><div class="help-step-num">5</div><div><strong>Press Test connection.</strong> It checks the three things that fail for different reasons, one line each: that Microsoft accepted the app's credentials, that it may read users, and that it may read groups &mdash; with how many of each the directory holds.</div></div>
    </div>

    <div class="help-note warn"><strong>Run Database Verification first.</strong> The settings and the group tables this page uses are created by <strong>System &rarr; Database Verification</strong>. On an install that has not run it since upgrading, the page says so instead of showing the form.</div>
    <div class="help-note"><strong>Client secrets expire.</strong> Entra gives every secret an end date, at most two years away. When it passes, scheduled imports start failing with <code>AADSTS7000222</code>. Put the date in your calendar when you create it, or create the new secret before the old one runs out and paste it in here.</div>
</div>

<!-- 4n. Entra scope -->
<div class="help-section" id="entra-scope">
    <div class="help-section-header"><?php echo helpSectionNum('entra-scope'); ?>
        <div>
            <h3>Choosing which people and groups</h3>
        </div>
    </div>
    <p>On the <strong>Importing people</strong> tab, tick <strong>Import people from this directory</strong>, then answer two separate questions: who comes in as a person, and which groups come in as groups.</p>
    <h4>Which people</h4>
    <div class="help-table"><table>
        <thead><tr><th>Option</th><th>What you get</th></tr></thead>
        <tbody>
            <tr><td><strong>Everybody in the directory</strong></td><td>Every user account. Right for a small tenant that holds people and nothing else.</td></tr>
            <tr><td><strong>Only members of these groups</strong></td><td>People in the groups you choose. Membership is <em>transitive</em>: somebody in a group nested inside a chosen group counts too. This is the usual choice, because most tenants also hold service accounts, shared mailboxes, meeting rooms and test users.</td></tr>
        </tbody>
    </table></div>
    <p><strong>Guests</strong> &mdash; external people invited into your tenant &mdash; are left out unless you tick <strong>Also import guests</strong>.</p>
    <h4>Which groups</h4>
    <div class="help-table"><table>
        <thead><tr><th>Option</th><th>What you get</th></tr></thead>
        <tbody>
            <tr><td><strong>Do not import groups</strong></td><td>People only. The default.</td></tr>
            <tr><td><strong>Only these groups</strong></td><td>The groups you choose, with their members.</td></tr>
            <tr><td><strong>Every group in the directory</strong></td><td>All of them, up to 5,000. Fine for a small tenant; in a large one you will mostly be importing Teams and distribution lists nobody here needs.</td></tr>
        </tbody>
    </table></div>
    <p>Both pickers search Entra as you type a group's name, or accept a pasted <strong>object ID</strong>. A chosen group that has since been deleted in Entra stays on the list marked <em>No longer exists</em>, so you notice rather than silently importing fewer people.</p>
    <div class="help-note"><strong>The two questions are independent on purpose.</strong> A group only links the people who are already being imported. If a group you import has members outside your people scope, they are counted in the run summary but not created &mdash; otherwise ticking a big group would quietly widen who comes in.</div>
</div>

<!-- 4o. Entra fields, sign-in and groups -->
<div class="help-section" id="entra-fields">
    <div class="help-section-header"><?php echo helpSectionNum('entra-fields'); ?>
        <div>
            <h3>What gets imported from Entra</h3>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th>In FreeITSM</th><th>Comes from</th></tr></thead>
        <tbody>
            <tr><td><strong>Name</strong></td><td>Display name; failing that, first name and surname.</td></tr>
            <tr><td><strong>Email</strong></td><td>The <code>mail</code> attribute. Tenants without Exchange licences often leave it empty for everybody, so the user principal name is used instead when it is a real address.</td></tr>
            <tr><td><strong>Username</strong></td><td>The user principal name &mdash; what the person types to sign in to Microsoft.</td></tr>
            <tr><td><strong>Job title</strong>, <strong>Department</strong>, <strong>Office</strong></td><td><code>jobTitle</code>, <code>department</code> and <code>officeLocation</code>.</td></tr>
            <tr><td><strong>Phone</strong> and <strong>Mobile</strong></td><td>The first business phone, and the mobile phone.</td></tr>
            <tr><td><strong>Employee number</strong></td><td><code>employeeId</code>.</td></tr>
            <tr><td><strong>Manager</strong></td><td>Their manager in Entra, if that manager is also imported.</td></tr>
        </tbody>
    </table></div>
    <p>An account <strong>disabled</strong> in Entra is marked as having left here, and re-enabling it brings the person back.</p>

    <h4>Taking a detail from a different attribute</h4>
    <p>The table above is the default. The <strong>Field mapping</strong> tab points any of those fields at another Entra attribute, the same way an LDAP directory's mapping does: <strong>Office</strong> from <code>city</code>, <strong>Department</strong> from <code>employeeOrgData/division</code>, or <strong>Employee number</strong> from <code>onPremisesExtensionAttributes/extensionAttribute5</code>, which many organisations fill from their on-premises Active Directory. A blank box keeps the default, and your own directory extensions (<code>extension_&lt;app id&gt;_&lt;name&gt;</code>) work too.</p>
    <p><strong>Test</strong> on that tab reads one real person and shows what each field would import, with everything that person has, before anything is saved. The unique id (the object id) and the manager are fixed and cannot be mapped. A mapped email that is not an address, or a mapped sign-in name that is empty, falls back to the user principal name, so nobody is left impossible to match.</p>

    <h4>Matching people who are already here</h4>
    <p>A person is recognised first by their Entra <strong>object ID</strong>, which survives renames and changes of email address, then by username, then by email. A match by email on somebody who is not yet linked to this provider is decided by <strong>When somebody is already here</strong>: link them, or leave them alone and flag it in the run.</p>
    <div class="help-note warn"><strong>Linking someone changes how they sign in.</strong> Once a person is linked to Entra they sign in with Microsoft, and any portal password they had stops working. If some of your requesters deliberately use local passwords, choose <em>Leave them alone</em>, preview, and decide case by case.</div>

    <h4>Signing in after an import</h4>
    <p>Imported people can sign in with the provider straight away, and land on the record the import made rather than getting a second copy. You do not need to do anything for this to work.</p>

    <h4>Groups</h4>
    <p>The <strong>Groups</strong> tab lists every imported group with its type (Security, Mail-enabled security, Microsoft 365, Distribution), how many members it has in Entra, and how many of them FreeITSM holds. Click a group to see who is in it. A group that leaves the import scope is marked <strong>Inactive</strong> and keeps its last known members; it is never deleted.</p>

    <div class="help-note ok"><strong>Group names in the sign-in mappings.</strong> Entra sends groups at sign-in as object IDs, so the mapping dropdowns under <a href="#sso-groups">Groups from the identity provider</a> used to show strings like <code>4f1c2a…</code>. Every imported group now appears there by name. A label you typed yourself is never overwritten.</div>
    <h4>Laptops from Intune</h4>
    <p>Every person imported from Entra carries their Entra object ID, and so does every device Intune reports. Switch on <strong>Assign assets to their primary user in Intune</strong> under <strong>Assets &rarr; Settings &rarr; Intune</strong> and each asset nobody holds is given to its device's primary user at the next Intune sync &mdash; matched exactly by that ID, never by name. An asset that already has a holder is never changed.</p>
</div>

<!-- 4p. Entra safety and scheduling -->
<div class="help-section" id="entra-schedule">
    <div class="help-section-header"><?php echo helpSectionNum('entra-schedule'); ?>
        <div>
            <h3>Safety and scheduling for Entra</h3>
        </div>
    </div>
    <p>The rules are the same as for <a href="#carddav-safety">an address-book or LDAP import</a>, and you set them on the Importing people tab:</p>
    <ul>
        <li><strong>Nobody is ever deleted.</strong> The worst an import does is mark somebody as having left.</li>
        <li><strong>Missing once is noise.</strong> Somebody has to be absent for the number of runs you set before they are marked as left. 0 means never automatically.</li>
        <li><strong>A run that looks wrong changes nothing.</strong> If an import finds far fewer people than last time (the percentage you set), it stops before touching anyone. With Entra the usual causes are a group taken out of the scope, or the app losing its Graph permissions &mdash; both look exactly like everybody leaving at once.</li>
        <li><strong>An empty or deleted scope group stops the run.</strong> If the groups you import people from have no members, or one of them no longer exists, nothing is changed.</li>
        <li><strong>A new account never takes over an old record.</strong> When a leaver's sign-in name or email goes to somebody new, Entra gives them a different account. The import shows a conflict and leaves the old record alone, whatever the conflict setting says.</li>
        <li><strong>One import at a time.</strong> A second import of the same source stops and says so, and changes nothing.</li>
    </ul>
    <div class="help-note ok"><strong>Preview first, every time.</strong> It runs the real import with the writing switched off and shows, person by person, what would be created, updated, linked or marked as left.</div>

    <h4>Running it on a schedule</h4>
    <p>Nothing imports on its own. <strong>Import now</strong> runs it once by hand; to keep people current, schedule the same command that runs LDAP and address-book imports:</p>
    <p><code>php scripts/directory_sync.php --all</code></p>
    <p>It imports every enabled source with importing switched on, Entra included, and exits with <code>1</code> when a run failed and <code>2</code> when one was stopped by a safety check, so a monitored task tells you. Once or twice a day is plenty for most organisations. The step-by-step setup for cron, Windows Task Scheduler and Docker is on the wiki: <a href="https://github.com/edmozley/freeitsm/wiki/Directory-Sync-Scheduling" target="_blank" rel="noopener">Directory Sync Scheduling</a>.</p>
    <p>Every run, by hand or scheduled, is listed on the <strong>History</strong> tab with what it did. Click one to see each person and what happened to them.</p>
</div>

<!-- 4q. Entra troubleshooting -->
<div class="help-section" id="entra-faq">
    <div class="help-section-header"><?php echo helpSectionNum('entra-faq'); ?>
        <div>
            <h3>Entra troubleshooting</h3>
        </div>
    </div>
    <ul>
        <li><strong>There is no Directory sync button on my provider.</strong> The issuer URL is not a Microsoft Entra one. It must start with <code>https://login.microsoftonline.com/</code> (or the US Government or China equivalent).</li>
        <li><strong>"No Entra tenant id".</strong> The issuer uses <code>common</code> or <code>organizations</code>, which names no particular directory. Type the Directory (tenant) ID on the Connection tab.</li>
        <li><strong>"The client secret is wrong" (AADSTS7000215).</strong> Almost always the <em>Secret ID</em> was pasted instead of the secret's <em>Value</em>. Create a new secret and copy the value straight away.</li>
        <li><strong>"The client secret has expired" (AADSTS7000222).</strong> Create a new secret in the app registration and paste it in. Scheduled imports fail until you do.</li>
        <li><strong>"No application with this client id" (AADSTS700016)</strong> or <strong>"tenant id was not found" (AADSTS90002).</strong> The client ID and tenant ID belong to different tenants, or one of them was mistyped.</li>
        <li><strong>Test connection signs in but cannot read users or groups (403).</strong> The Graph permissions are missing, were added as <em>Delegated</em> rather than <em>Application</em>, or admin consent was never granted. After granting it, wait a minute or two and test again.</li>
        <li><strong>Fewer people imported than the directory holds.</strong> Guests are left out unless you include them, and with <em>Only members of these groups</em> anybody outside those groups is too. The run's detail names everyone it brought in.</li>
        <li><strong>A group's "Linked" count is lower than "In Entra".</strong> Expected: only people who are imported are linked. Members outside your people scope, guests, and nested groups themselves are counted in Entra but not held here.</li>
        <li><strong>"A different Entra account now uses this sign-in name or email address".</strong> That address belonged to somebody already here, and a new Entra account has taken it over. FreeITSM treats them as two people, so the old record is untouched and the new person is not imported. There is no way yet to separate them from this screen.</li>
        <li><strong>"This account is already linked to a different sign-in".</strong> Somebody signed in with a Microsoft account whose email matches a person already linked to a different account. The sign-in is refused so that nobody takes over the record.</li>
        <li><strong>The import stopped and changed nothing.</strong> That is a safety check. Read the run's message, preview, and if the numbers are genuinely right, run it again or adjust the percentage.</li>
        <li><strong>"Groups were not imported: run Database Verification".</strong> People were imported correctly; the group tables do not exist yet. Run Database Verification and import again.</li>
        <li><strong>Nothing has imported for days.</strong> Check the scheduled task is still running. The History tab shows when the last run happened and whether somebody started it by hand.</li>
        <li><strong>Somebody left a group in Entra and still shows in it.</strong> Membership is brought across at the next import, not the moment it changes. If they are still there after an import, check the group is still being imported (an <em>Inactive</em> group keeps its last members).</li>
    </ul>
</div>

<!-- 5. Experience -->
<div class="help-section" id="experience">
    <div class="help-section-header"><?php echo helpSectionNum('experience'); ?>
        <div>
            <h3>What people see when they sign in</h3>
        </div>
    </div>
    <div class="help-cards">
        <div class="help-card">
            <h4>With SSO off</h4>
            <p>The normal username/email + password form. Nothing changes.</p>
        </div>
        <div class="help-card">
            <h4>Single-company, SSO on</h4>
            <p>The provider button(s) lead, e.g. “Sign in with Microsoft”, with a “local account” link underneath.</p>
        </div>
        <div class="help-card">
            <h4>Multi-company portal</h4>
            <p>An email box first. Enter your email and you're routed to your company's provider (or shown a picker, or the password form).</p>
        </div>
        <div class="help-card">
            <h4>Analyst login</h4>
            <p>Only your global (internal) providers ever appear here — clients' IdPs are never shown to staff.</p>
        </div>
    </div>
    <div class="help-note"><strong>First portal sign-in tip:</strong> a brand-new portal user typing their email may land on the password form, because the email-first router only auto-routes people it has seen before. They should use the <strong>provider button</strong> (single-company) or it routes by company (multi-company); after that first sign-in they're remembered and email-first routing is automatic. To route them on the very first visit, list your domains under the provider's <strong>Email domains</strong> &mdash; see <a href="#portal-domains">Sending portal users straight to SSO</a>.</div>
</div>

<!-- 6. Break-glass -->
<div class="help-section" id="breakglass">
    <div class="help-section-header"><?php echo helpSectionNum('breakglass'); ?>
        <div>
            <h3>Break-glass &amp; safety</h3>
        </div>
    </div>
    <p>Local login is never hard-disabled, so a broken or offline IdP can't lock everyone out.</p>
    <ul>
        <li><strong>Master kill switch</strong> — turning off <em>Enable single sign-on</em> instantly reverts everyone to local login.</li>
        <li><strong>Allow local login</strong> — when off, the local form is hidden for a clean SSO-only experience, but it's still reachable.</li>
        <li><strong>The <code>?local=1</code> escape hatch</strong> — adding <code>?local=1</code> to a login URL always brings the password form back, even in SSO-only mode. Keep at least one local admin account for this.</li>
        <li><strong>Single logout</strong> — signing out of FreeITSM also ends the session at the provider, so the next visit isn't silently waved through.</li>
    </ul>
    <div class="help-note ok"><strong>Recommended:</strong> keep one local-password admin account as your break-glass, and confirm <code>?local=1</code> works, before you switch <em>Allow local login</em> off.</div>
</div>

<!-- 7. FAQ -->
<div class="help-section" id="faq">
    <div class="help-section-header"><?php echo helpSectionNum('faq'); ?>
        <div>
            <h3>Troubleshooting</h3>
        </div>
    </div>

    <h4>“Redirect URI mismatch” after signing in at the provider</h4>
    <p>The redirect URI registered in the IdP must <em>exactly</em> match <code><?php echo htmlspecialchars($redirectUri); ?></code> — scheme, host and path. Copy it from this page (or the SSO settings page) rather than typing it.</p>
    <p>If you moved FreeITSM to a new address (for example from <code>http://localhost/...</code> to an https name), add the new address in the IdP too. FreeITSM builds it from the address you are using.</p>

    <h4>Seeing, or undoing, which provider a portal user is linked to</h4>
    <p>Open them in <strong>Tickets &rarr; Users</strong>: the <em>Signs in with</em> line names their provider. <strong>Unlink</strong> beside it removes the link, after asking. Their tickets and details stay; the email box stops sending them to that provider, and the next time they sign in through a provider they are linked again. People kept up to date from a directory have no Unlink, because the next sync would link them again. Each unlink is written to the system log.</p>

    <h4>“Invalid redirect uri” when signing out</h4>
    <p>The provider was asked to send you back to an address it does not allow. Register both sign-out addresses as allowed post-logout redirect URLs: <code><?php echo htmlspecialchars($signoutAnalystUri); ?></code> for the analyst login and <code><?php echo htmlspecialchars($signoutPortalUri); ?></code> for the self-service portal. They are on the Single Sign-On page with a Copy button each. In Keycloak this is <em>Clients &rarr; your client &rarr; Valid post logout redirect URIs</em>; in Okta, <em>Sign-out redirect URIs</em>.</p>

    <h4>A portal user typed their email but got the password form, not SSO</h4>
    <p>Single-company: they should click the provider button for their first sign-in, or list their domain under the provider's <strong>Email domains</strong> so the email box routes them. If the domain is listed and they still get the password form, check they are not already assigned to a different provider or a directory. Multi-company: check the company that owns their email domain actually has an enabled provider, and that the domain is listed under System &rarr; Companies. Unmapped domains and personal/free-email addresses are sent to local login by design.</p>

    <h4>“Your email is not verified with the identity provider”</h4>
    <p>The provider sent <code>email_verified: false</code>, or you've turned on <em>Require a verified-email claim</em> for a provider whose tokens omit it. Leave that toggle off unless your IdP lets users self-register unverified addresses.</p>

    <h4>A client's provider button is showing on the analyst login</h4>
    <p>Set the provider's <strong>Company</strong> to that client (not “Global”). Global providers are the only ones offered to analysts; company-owned ones are portal-only.</p>

    <h4>Profile details (job title, department, phone) are not updating on SSO login</h4>
    <p>Check the following in your identity provider configuration:</p>
    <ul>
        <li>Verify that <strong>Profile attribute sync</strong> on the sign-in provider is set to <strong>Always</strong> or <strong>Initial</strong>.</li>
        <li>In your Identity Provider (e.g. Microsoft Entra ID / Azure AD App Registration &rarr; Token configuration), ensure optional claims for <code>department</code>, <code>job_title</code> / <code>title</code>, and <code>phone_number</code> / <code>businessPhones</code> are added to the ID and Access Tokens.</li>
        <li>Verify that your configured <strong>Scopes</strong> list in FreeITSM includes the required scopes for your provider (e.g. <code>openid email profile</code>).</li>
    </ul>
    <h4>Discovery test fails</h4>
    <p>The issuer URL is wrong or unreachable from the server. It should be the base issuer (no <code>/.well-known/…</code> on the end) — e.g. <code>https://login.microsoftonline.com/&lt;tenant-id&gt;/v2.0</code> for Entra. Use the <strong>Test</strong> button to confirm before saving.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
