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
            <p>Your service-desk staff, on the main login page. Each analyst is assigned a sign-in method (local password, or a specific provider) in <strong>System &rarr; Analysts</strong>.</p>
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
        <tr><td><strong>Portal login shows…</strong></td><td>Provider buttons up front.</td><td>Email first, then that person's company's provider(s).</td></tr>
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
        <div class="help-step"><div class="help-step-num">1</div><div><strong>Register an app in your identity provider.</strong> Create an app/registration in Entra, Okta, Google, etc. Set its redirect URI to the address below, and note the <strong>issuer URL</strong>, <strong>client ID</strong> and a <strong>client secret</strong>.<br><br>Redirect URI to register: <code><?php echo htmlspecialchars($redirectUri); ?></code></div></div>
        <div class="help-step"><div class="help-step-num">2</div><div><strong>Add the provider here.</strong> System &rarr; Single Sign-On &rarr; <em>Add provider</em>. Paste the issuer URL, client ID and secret, give it a display name (e.g. “Sign in with Microsoft”), tick <strong>Enabled</strong>, and use <strong>Test</strong> to confirm the issuer is reachable. Turn on the master <strong>Enable single sign-on</strong> switch.</div></div>
        <div class="help-step"><div class="help-step-num">3</div><div><strong>Decide who uses it.</strong> For <strong>analysts</strong>, set their <em>Sign-in method</em> to this provider in System &rarr; Analysts. For <strong>portal users</strong>, turn on the provider's <em>auto-create users</em> toggle and they're created on first sign-in — or they're matched to an existing record by verified email.</div></div>
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
    <p>On a multi-company install the portal login shows <strong>just an email box</strong> — no provider buttons up front (that would leak every client's IdP). When someone enters their email:</p>
    <ul>
        <li><strong>Their company has no IdP</strong> &rarr; they get the email + password form.</li>
        <li><strong>One IdP</strong> &rarr; they're sent straight to it.</li>
        <li><strong>Two or more</strong> (a client mid-migration between, say, Entra and Okta) &rarr; they're shown a small picker to choose. After their first successful sign-in they're remembered, so the picker is a once-only step.</li>
    </ul>
    <div class="help-note"><strong>Local always works as a floor.</strong> Anyone whose email isn't mapped to a company — including people on Gmail/Outlook personal addresses, or someone locked out of their IdP — falls through to the local password form. No one can be shut out.</div>

    <div class="help-note warn"><strong>Today, each client hands you a client secret</strong> (which you store and rotate). That's the “bring-your-own-credentials” model and it works for any provider. A future option — where you register one app and each client just clicks “consent”, with no secret to hand over — is designed but not yet built. It will not change anything above; only how a provider is added.</div>
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
    <div class="help-note"><strong>First portal sign-in tip:</strong> a brand-new portal user typing their email may land on the password form, because the email-first router only auto-routes people it has seen before. They should use the <strong>provider button</strong> (single-company) or it routes by company (multi-company); after that first sign-in they're remembered and email-first routing is automatic.</div>
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
