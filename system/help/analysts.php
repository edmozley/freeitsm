<?php
/**
 * System Help — Analysts.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'analysts';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What this page controls</h3></div>
    <p class="syshelp-lead">An <strong>analyst</strong> is someone who works <em>in</em> FreeITSM — takes tickets, updates assets, writes knowledge. (The people who <em>raise</em> tickets are requesters, and they are created automatically from inbound email or the self-service portal; you don't add them here.)</p>
    <p>System &rarr; Analysts is the one place you create those accounts and decide, for each of them, four separate things:</p>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>Are they an administrator?</h4>
            <p>Administrators can open the System module. Everyone else cannot see it at all.</p>
        </div>
        <div class="syshelp-card">
            <h4>How do they sign in?</h4>
            <p>A local username and password, or an identity provider you've configured under Single Sign-On.</p>
        </div>
        <div class="syshelp-card">
            <h4>What can they reach?</h4>
            <p>Which companies and which modules — set on this page, and topped up by any team they're in.</p>
        </div>
        <div class="syshelp-card">
            <h4>Which teams are they in?</h4>
            <p>Teams decide which ticket departments they see. This is the setting most often behind &ldquo;why can't I see any tickets?&rdquo;</p>
        </div>
    </div>
    <p>The table shows one row per analyst, with their username, full name, email, teams, status, whether they're an admin, and when they last signed in. The four buttons on each row are <strong>Edit</strong>, <strong>Assign teams</strong>, <strong>Reset password</strong> and <strong>Delete</strong>.</p>
    <div class="syshelp-callout info"><strong>Read the chips next to a name.</strong> On a multi-company install, an analyst who can't reach every company gets an orange chip telling you how many they <em>can</em> reach &mdash; and a red <strong>no companies</strong> chip if the answer is none, which means they will see no tickets at all. Hover it for the list.</div>
</div>

<!-- 2. Adding an analyst -->
<div class="syshelp-section" id="adding">
    <div class="syshelp-section-header"><h3>Adding an analyst</h3></div>
    <p class="syshelp-lead">Press <strong>Add</strong>, fill in the form, press <strong>Save</strong>. Only three fields are actually required.</p>
    <table class="syshelp-table">
        <tr><th>Field</th><th>What it does</th></tr>
        <tr><td><strong>Username</strong></td><td>Required, and must be unique — saving a duplicate is rejected. This is what they type to sign in.</td></tr>
        <tr><td><strong>Full name</strong></td><td>Required. The name shown on tickets, assignments and everywhere else in the app.</td></tr>
        <tr><td><strong>Email</strong></td><td>Optional, but set it if you use SSO — it is what links their identity-provider account to this one on first sign-in.</td></tr>
        <tr><td><strong>Password</strong></td><td>Required when creating. When editing, leave it blank to keep the existing password.</td></tr>
        <tr><td><strong>Sign-in method</strong></td><td>Local username and password, or one of your configured providers. See below.</td></tr>
        <tr><td><strong>Access all companies</strong></td><td>Only appears once you have more than one company. On by default.</td></tr>
        <tr><td><strong>Active</strong></td><td>On by default. Turn it off to stop someone signing in without deleting their history.</td></tr>
        <tr><td><strong>Administrator</strong></td><td>Off by default. Grants the System module. See below.</td></tr>
        <tr><td><strong>Access all modules</strong></td><td>On by default. Turn it off to restrict them, then grant specific modules on System &rarr; Modules.</td></tr>
    </table>
    <div class="syshelp-callout"><strong>A new analyst is not in any team.</strong> Creating the account is only half the job — use <strong>Assign teams</strong> afterwards, or think carefully about the department consequences in the Teams section below.</div>
</div>

<!-- 3. Administrators -->
<div class="syshelp-section highlight" id="admin">
    <div class="syshelp-section-header"><h3>Administrators</h3></div>
    <p class="syshelp-lead">The <strong>Administrator</strong> toggle is the single key to the System module — this whole area: analysts, teams, company access, SSO, security, encryption, the API and the rest. An analyst without it cannot open System, cannot see it in the waffle menu, and cannot call its endpoints even by typing the URL directly.</p>
    <p>It is deliberately separate from module access. You cannot grant &ldquo;System&rdquo; as a module on System &rarr; Modules; the admin flag is the only route in. The one exception is <strong>Preferences</strong>, which every analyst can always reach because it only changes their own settings.</p>
    <div class="syshelp-callout warn"><strong>The last administrator is protected.</strong> FreeITSM will refuse to delete, demote or deactivate the only remaining active admin — you'd lock yourself out of System with no way back. Grant admin to someone else first, then change the original.</div>
    <div class="syshelp-callout info"><strong>Grant it sparingly.</strong> An administrator can see and change every company's configuration, read the topology, mint API keys and reset anyone's password. Most analysts, including senior ones, never need it.</div>
</div>

<!-- 4. Sign-in method -->
<div class="syshelp-section" id="signin">
    <div class="syshelp-section-header"><h3>Sign-in method</h3></div>
    <p class="syshelp-lead">Leave this as <strong>Local (username &amp; password)</strong> unless you've set up an identity provider under System &rarr; Single Sign-On. Choosing a provider means this person signs in through it; their two accounts are matched up automatically on first sign-in, <em>by email address</em>.</p>
    <p>So if you assign a provider, the analyst's <strong>Email</strong> field must match the address they hold at that provider — otherwise the link never forms.</p>
    <div class="syshelp-callout"><strong>Assigning a provider does not disable their password.</strong> If you set a sign-in method <em>and</em> leave a password on the account, both routes still work. If you want SSO to be the only way in, that's a decision to make on the Security and SSO pages — see System help &rarr; Single Sign-On, particularly the break-glass advice.</div>
    <div class="syshelp-callout ok"><strong>Deleting a provider is safe.</strong> If you later remove an SSO provider, every analyst who used it quietly reverts to local sign-in rather than being locked out.</div>
</div>

<!-- 5. Company & module access -->
<div class="syshelp-section" id="access">
    <div class="syshelp-section-header"><h3>Company &amp; module access</h3></div>
    <p class="syshelp-lead">Two independent switches, each with the same shape: an <em>all</em> toggle, or a specific list.</p>

    <h4>Companies</h4>
    <p>The company controls only appear once a second company exists — on a single-company install there is nothing to choose. Turn <strong>Access all companies</strong> off and you get a checkbox list; tick the companies this person may work in. They will see tickets, assets and everything else for those companies only.</p>
    <div class="syshelp-callout info"><strong>Team grants are added, never taken away.</strong> If the analyst is in a team that has company access of its own, they get that <em>as well as</em> what you tick here. Unticking a box on this page cannot remove access their team gives them — the modal tells you when that's happening. To take it back, change the team on System &rarr; Teams.</div>

    <h4>Modules</h4>
    <p>Turn <strong>Access all modules</strong> off and the analyst is restricted to whatever is ticked for them on System &rarr; Modules. Leave it on and they can open everything.</p>
    <div class="syshelp-callout warn"><strong>Module access combines with their teams, and how it combines depends on your privilege mode.</strong> In <em>most privilege</em> mode the analyst gets the union — a team can only widen what they can reach. In <em>least privilege</em> mode it's the intersection, so adding someone to a team with a narrow module list can <em>remove</em> modules from them. If a module vanishes from someone's waffle after a team change, this is why.</div>
</div>

<!-- 6. Teams & departments -->
<div class="syshelp-section" id="teams">
    <div class="syshelp-section-header"><h3>Teams &amp; departments</h3></div>
    <p class="syshelp-lead">Use <strong>Assign teams</strong> on a row to put someone into teams. This is the same relationship you can edit from the other end on System &rarr; Teams — change it in either place, it's the same list.</p>
    <p>Teams matter more than they look, because <strong>teams decide which ticket departments an analyst can see</strong>:</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><span class="syshelp-step-num">1</span><div>An analyst in <strong>no team at all</strong> sees <strong>every</strong> active department.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">2</span><div>An analyst in <strong>one or more teams</strong> sees only the departments linked to those teams.</div></div>
    </div>
    <div class="syshelp-callout warn"><strong>The trap:</strong> putting someone into a team that has <em>no</em> departments gives them <em>zero</em> departments — a narrower result than leaving them out of teams altogether. If a new analyst reports an empty ticket list right after you added them to a team, check that team's departments first.</div>
</div>

<!-- 7. Resetting a password -->
<div class="syshelp-section" id="passwords">
    <div class="syshelp-section-header"><h3>Resetting a password</h3></div>
    <p class="syshelp-lead">The key button on a row sets a new local password. Type it twice; the minimum is six characters. It takes effect immediately and the analyst is not emailed — you'll need to tell them yourself.</p>
    <p>Any administrator can reset any account's password, including their own. There's no &ldquo;must change at next sign-in&rdquo; flag here; if you want passwords to age out, use the <strong>Password expiry</strong> setting on System &rarr; Security.</p>
</div>

<!-- 8. Leavers -->
<div class="syshelp-section" id="leavers">
    <div class="syshelp-section-header"><h3>Leavers</h3></div>
    <p class="syshelp-lead">When someone leaves, you almost always want <strong>Active = off</strong>, not <strong>Delete</strong>.</p>
    <table class="syshelp-table">
        <tr><th>Option</th><th>What happens</th></tr>
        <tr><td><strong>Set Active to off</strong></td><td>They can no longer sign in. Their name stays on the tickets, notes and assets they touched, so your history still reads correctly. This is the right choice for a leaver.</td></tr>
        <tr><td><strong>Delete</strong></td><td>The account row is removed outright. There is no undo, and no warning if they still have tickets or tasks assigned to them.</td></tr>
    </table>
    <div class="syshelp-callout warn"><strong>Delete does not check for assigned work.</strong> Reassign anything that belongs to the person before you delete them — nothing will stop you otherwise.</div>
    <div class="syshelp-callout">FreeITSM won't let you delete <strong>your own account</strong>, or the <strong>last active administrator</strong>. Everything else is fair game, so pause before you press it.</div>
    <div class="syshelp-callout ok"><strong>Done.</strong> Add the person, set admin only if they need System, tick their companies and modules, and put them in the right teams — then check the chips on their row read the way you expect.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
