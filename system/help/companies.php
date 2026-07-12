<?php
/**
 * System Help — Companies (multi-tenancy).
 */
require __DIR__ . '/_init.php';
$helpSlug = 'companies';
require __DIR__ . '/_top.php';
?>
<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What Companies is for</h3></div>
    <p class="syshelp-lead">A <strong>company</strong> is the user-facing word for a tenant — one of the separate client organisations you support. The Companies page is where you create them, and where you tell FreeITSM how to recognise each one's inbound email so it lands in the right place.</p>
    <p>You don't switch multi-company mode on. FreeITSM works it out by counting companies:</p>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>One company (the default)</h4>
            <p>A fresh install has a single silent <strong>Default</strong> company. The multi-company extras stay hidden — no email-routing fields, no public-domains list. It just behaves like an ordinary single-organisation install.</p>
        </div>
        <div class="syshelp-card">
            <h4>Two or more (MSP mode)</h4>
            <p>The moment you add a second company, multi-company behaviour wakes up automatically: email domains, specific senders, the routing summary and the public email-domains card all appear.</p>
        </div>
    </div>
    <div class="syshelp-callout info">Setting up per-company sign-in for requesters is a separate (but closely related) job. See <a href="sso.php">single sign-on for the self-service portal</a> for the step-by-step guide — it routes a person to their own company's identity provider by email, exactly as the email domains here route their messages.</div>
</div>

<!-- 2. Adding companies -->
<div class="syshelp-section" id="adding">
    <div class="syshelp-section-header"><h3>Adding and editing companies</h3></div>
    <p class="syshelp-lead">Each company is just a name and an active flag to begin with. You add the email routing once it exists.</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><div class="syshelp-step-num">1</div><div><strong>Click Add.</strong> Give the company a name your team will recognise (e.g. the client's trading name). New companies are <strong>Active</strong> by default.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">2</div><div><strong>Save.</strong> Adding the second company is what flips the install into multi-company mode — the email-routing fields appear from then on.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">3</div><div><strong>Re-open the company to configure email.</strong> The email domains, specific senders and the routing summary only show when you <em>edit</em> an existing company on a multi-company install — there's nothing to route with a single company.</div></div>
    </div>
    <h4>Active vs inactive</h4>
    <p>The <strong>Active</strong> toggle controls whether a company is in play. The <strong>Default</strong> company is always active and can't be deactivated — it's the catch-all that keeps the install working.</p>
    <div class="syshelp-callout">Editing email routing is only available once a company has been saved, so add the company first, then re-open it to attach its domains and senders.</div>
</div>

<!-- 3. Routing inbound email -->
<div class="syshelp-section" id="email">
    <div class="syshelp-section-header"><h3>Routing inbound email by domain</h3></div>
    <p class="syshelp-lead">When several companies share one inbox, FreeITSM needs to tell whose ticket is whose. An <strong>email domain</strong> is the main key: mail from anyone at that domain is filed under that company.</p>
    <ul>
        <li>Open a company and add its <strong>email domain(s)</strong> — for example <code>acme.co.uk</code>. A company can have several.</li>
        <li>A message from <code>someone@acme.co.uk</code> arriving on a shared inbox is then attributed to Acme automatically.</li>
        <li>The domains you've added show as chips in the companies list, so you can see the whole routing map at a glance.</li>
    </ul>
    <div class="syshelp-callout warn"><strong>Use real, owned domains.</strong> Don't map a public/free-email domain (like <code>gmail.com</code>) to a company — it would scoop up unrelated people. Those are handled by the public email-domains list and by specific senders instead.</div>
</div>

<!-- 4. Personal & free email -->
<div class="syshelp-section" id="senders">
    <div class="syshelp-section-header"><h3>People on personal or free email</h3></div>
    <p class="syshelp-lead">Not everyone writes in from a company domain. A contractor or a small-business contact might use a personal Gmail or Outlook address that can't safely be mapped by domain. Two tools cover this.</p>
    <h4>Specific senders (address-level routing)</h4>
    <p>On a company, add a <strong>specific sender</strong> — a single full email address (e.g. <code>jo.bloggs@gmail.com</code>) — to route just that one person to that company, even though their domain is shared. It's the precise complement to domain routing.</p>
    <h4>Public / free-email domains</h4>
    <p>The <strong>Public email domains</strong> card (shown only once you have more than one company) lists the domains FreeITSM treats as personal/free — <code>gmail.com</code>, <code>outlook.com</code> and the like. Mail from these is never auto-attributed by domain, so it can only reach a company via a specific sender. FreeITSM ships with a built-in list (expandable from the card); you can add your own as well. The list is add-only.</p>
    <div class="syshelp-callout info">Rule of thumb: <strong>domains</strong> route a whole organisation; <strong>specific senders</strong> route one individual; the <strong>public email-domains list</strong> protects shared providers from being mapped by accident.</div>
</div>

<!-- 5. Summary -->
<div class="syshelp-section highlight" id="summary">
    <div class="syshelp-section-header"><h3>How email reaches this company</h3></div>
    <p class="syshelp-lead">Every company's edit screen has a read-only <strong>How email reaches this company</strong> panel. It's derived from everything above, so you can confirm the routing without guessing — change a domain or sender and the summary updates.</p>
    <p>It spells out each way mail can land here:</p>
    <table class="syshelp-table">
        <tr><th>Path</th><th>What it means</th></tr>
        <tr><td><strong>Pinned inbox</strong></td><td>A mailbox dedicated to this company — everything that arrives there is theirs, no domain matching needed.</td></tr>
        <tr><td><strong>Shared inbox</strong></td><td>A shared mailbox where this company is recognised by its matched <strong>domains</strong> and/or <strong>specific senders</strong> (the panel lists exactly which).</td></tr>
    </table>
    <p>The panel also surfaces warnings worth acting on — for instance a company with domains but no shared inbox to match them on, or an inbox that isn't authenticated. The <strong>Default</strong> company additionally catches anything no other company claims, so nothing is ever dropped.</p>
    <div class="syshelp-callout ok"><strong>If the summary shows no route,</strong> the company won't receive any mail yet: add an email domain or a specific sender, or pin it an inbox, and the panel will fill in.</div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
