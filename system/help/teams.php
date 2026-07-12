<?php
/**
 * System Help — Teams.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'teams';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What a team is for</h3></div>
    <p class="syshelp-lead">A team is a group of analysts — service desk, infrastructure, applications — that you grant things to <em>once</em> instead of person by person. Add someone to the team and they inherit everything the team has.</p>
    <p>A team carries four things:</p>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>Departments</h4>
            <p>Which ticket departments its members can see. This is the big one — see below.</p>
        </div>
        <div class="syshelp-card">
            <h4>Members</h4>
            <p>The analysts in the team.</p>
        </div>
        <div class="syshelp-card">
            <h4>Company access</h4>
            <p>On a multi-company install, which companies its members can work in.</p>
        </div>
        <div class="syshelp-card">
            <h4>Module access</h4>
            <p>Which modules its members can open (granted on System &rarr; Modules).</p>
        </div>
    </div>
    <p>The table lists each team with its description, how many departments and analysts it has, its display order and whether it's active. Teams are <strong>global</strong> — they are not owned by a company, so the same team can serve several companies at once.</p>
</div>

<!-- 2. Creating a team -->
<div class="syshelp-section" id="creating">
    <div class="syshelp-section-header"><h3>Creating a team</h3></div>
    <p class="syshelp-lead">Press <strong>Add</strong> and give it a name. Everything else is optional.</p>
    <table class="syshelp-table">
        <tr><th>Field</th><th>What it does</th></tr>
        <tr><td><strong>Name</strong></td><td>Required. Note that duplicate names are <em>not</em> rejected — two teams called &ldquo;Support&rdquo; are indistinguishable in every dropdown, so keep names unique yourself.</td></tr>
        <tr><td><strong>Description</strong></td><td>Free text, shown in the table and in the picker when assigning analysts.</td></tr>
        <tr><td><strong>Display order</strong></td><td>Sorts the team list and every team dropdown in the app. Lower numbers first; ties fall back to name.</td></tr>
        <tr><td><strong>Active</strong></td><td>On by default. See the warning at the end of this page about what deactivating does <em>not</em> do.</td></tr>
        <tr><td><strong>Access all modules</strong></td><td><strong>Off</strong> by default for a new team. On means members can open every module.</td></tr>
    </table>
    <p>Once the team exists, use the buttons on its row to fill it out: <strong>Manage departments</strong>, <strong>Manage members</strong>, and — on a multi-company install — <strong>Manage company access</strong>.</p>
</div>

<!-- 3. Departments -->
<div class="syshelp-section highlight" id="departments">
    <div class="syshelp-section-header"><h3>Departments — the setting that decides who sees which tickets</h3></div>
    <p class="syshelp-lead">This is the whole point of teams, and the thing most likely to catch you out. Ticket visibility works like this:</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><span class="syshelp-step-num">1</span><div>An analyst in <strong>no team</strong> sees <strong>every</strong> active department.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">2</span><div>An analyst in <strong>one or more teams</strong> sees only the departments linked to those teams — the union of them.</div></div>
    </div>
    <div class="syshelp-callout warn"><strong>A team with no departments hides everything from its members.</strong> The moment you put someone into their first team, they stop seeing all departments and start seeing only that team's — and if the team has none linked, that's zero departments and an empty ticket list. Link the departments <em>before</em> you add the members.</div>
    <p>The picker lists <em>all</em> departments, including inactive ones (labelled as such), deliberately — so that saving the form doesn't silently drop a link to a department that happens to be switched off.</p>
    <div class="syshelp-callout info">The same links can be edited from the other side, under Tickets &rarr; Settings &rarr; Departments. It's one relationship, two doors.</div>
</div>

<!-- 4. Members -->
<div class="syshelp-section" id="members">
    <div class="syshelp-section-header"><h3>Members</h3></div>
    <p class="syshelp-lead"><strong>Manage members</strong> opens a checkbox list of analysts. Tick who belongs, press <strong>Save</strong> — what you tick <em>replaces</em> the current membership, so it's the full list, not an addition to it.</p>
    <p>This is the same relationship as <strong>Assign teams</strong> on System &rarr; Analysts. Edit it from whichever end suits: from here when you're standing up a team, from there when you're onboarding one person.</p>
</div>

<!-- 5. Company access -->
<div class="syshelp-section" id="companies">
    <div class="syshelp-section-header"><h3>Company access</h3></div>
    <p class="syshelp-lead">Only shown once you have more than one company. <strong>Manage company access</strong> lets you grant the whole team a set of companies — useful when a squad looks after a specific group of clients.</p>
    <table class="syshelp-table">
        <tr><th>Setting</th><th>What it does</th></tr>
        <tr><td><strong>Access all companies</strong></td><td>On means members reach every company, including any you add later. Off reveals a checkbox list.</td></tr>
    </table>
    <div class="syshelp-callout info"><strong>Team company access is added to each member's own access — it never removes any.</strong> The effective answer for a person is <em>their</em> companies plus <em>their teams'</em> companies. So a team is a way to widen access, not to restrict it: to narrow someone down, reduce what's ticked on their own analyst record <em>and</em> take them out of the wider team.</div>
</div>

<!-- 6. Module access -->
<div class="syshelp-section" id="modules">
    <div class="syshelp-section-header"><h3>Module access</h3></div>
    <p class="syshelp-lead">The <strong>Access all modules</strong> toggle lives on the team's Edit form; the specific per-module ticks live on System &rarr; Modules, which has a column for teams as well as analysts.</p>
    <div class="syshelp-callout warn"><strong>Unlike company access, module access does not always widen.</strong> It depends on the install-wide privilege mode set on System &rarr; Modules:
        <ul>
            <li><strong>Most privilege</strong> — an analyst gets the union of their own modules and their teams'. A team can only add.</li>
            <li><strong>Least privilege</strong> — the analyst gets the intersection. Adding someone to a team with a narrow module list can <em>take modules away</em> from them.</li>
        </ul>
        If a module disappears from someone's waffle right after a team change, this is the reason.
    </div>
</div>

<!-- 7. Deleting a team -->
<div class="syshelp-section" id="deleting">
    <div class="syshelp-section-header"><h3>Deleting a team</h3></div>
    <p class="syshelp-lead">Delete removes the team and, with it, every grant it was making. There are <strong>no protections</strong> on this — no check for members, no warning about what they'll lose.</p>
    <div class="syshelp-callout warn"><strong>Deleting a team silently changes what its members can see.</strong> They lose the team's departments, its company access and its module grants all at once. If it was their only team, they flip from &ldquo;that team's departments&rdquo; back to &ldquo;every department&rdquo; — which may be more than you intended.</div>
    <div class="syshelp-callout"><strong>Deactivating is not the safe half-measure it looks like.</strong> Setting a team inactive removes it from the pickers and dropdowns, but its company and module grants <em>keep applying</em> to its members. If your aim is to stop a team granting access, empty its membership or change its grants — don't just switch it off.</div>
    <div class="syshelp-callout ok"><strong>A good order to work in:</strong> create the team &rarr; link its departments &rarr; set its company and module access &rarr; add the members last. That way nobody spends a minute staring at an empty ticket list.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
