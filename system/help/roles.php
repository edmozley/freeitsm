<?php
/**
 * System Help — Roles (RBAC Layer 2).
 */
require __DIR__ . '/_init.php';
$helpSlug = 'roles';
require __DIR__ . '/_top.php';
?>

<!-- 1. What roles are for -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What roles are for</h3>
            <p>A role hands a slice of administration to someone who isn't a full System administrator. The example that prompted this: a <strong>training lead</strong> who should be able to build and assign courses, but has no business changing your SSO, your security rules or your companies.</p>
        </div>
    </div>
    <p>Before roles, that wasn't possible — either you were a System administrator (and could touch everything) or you weren't (and could touch nothing in System). Roles are the middle ground: a named bundle of <strong>settings capabilities</strong> that you grant to specific people or teams.</p>
    <div class="help-note"><strong>This is about settings, not everyday work.</strong> Roles govern who can <em>configure</em> a module — its statuses, its rules, its content. They do not gate the ordinary job: replying to a ticket, taking a course, running a report. That's decided by plain module access, which hasn't changed.</div>
</div>

<!-- 2. Two layers -->
<div class="help-section" id="two-layers">
    <div class="help-section-header"><?php echo helpSectionNum('two-layers'); ?>
        <div>
            <h3>Access vs administration — two separate questions</h3>
            <p>FreeITSM now answers two questions about a person and a module, not one:</p>
        </div>
    </div>
    <div class="help-cards">
        <div class="help-card">
            <h4>Can they use it?</h4>
            <p>Module access — set on <strong>System &rarr; Module Access</strong> and via teams. Decides whether they can open the module and do its day-to-day work at all.</p>
        </div>
        <div class="help-card">
            <h4>Can they administer it?</h4>
            <p>A role granting that module's settings capability. Decides whether, once in, they can also change how it's configured.</p>
        </div>
    </div>
    <p>The two stack: module access gets someone <em>into</em> the LMS to take their courses; an <strong>LMS Manager</strong> role is what additionally lets them build and assign courses. One without the other is perfectly normal — most learners have the first and not the second.</p>
</div>

<!-- 3. Creating a role -->
<div class="help-section" id="creating">
    <div class="help-section-header"><?php echo helpSectionNum('creating'); ?>
        <div>
            <h3>Creating a role</h3>
            <p>Press <strong>Add</strong>, give the role a name, then open it to choose what it grants.</p>
        </div>
    </div>
    <div class="help-steps">
        <div class="help-step"><span class="help-step-num">1</span><div><strong>Name it</strong> for the job it does — &ldquo;LMS Manager&rdquo;, &ldquo;Service Desk Config&rdquo;. The name is what you'll assign, so make it read well.</div></div>
        <div class="help-step"><span class="help-step-num">2</span><div><strong>Tick its capabilities.</strong> These are grouped by module. Each one is a specific piece of administration — currently &ldquo;Manage the LMS&rdquo;; more appear here as other modules are brought into the system.</div></div>
        <div class="help-step"><span class="help-step-num">3</span><div><strong>Assign it</strong> to analysts and/or teams (next section), and <strong>Save</strong>.</div></div>
    </div>
    <div class="help-note"><strong>A role with no capabilities grants nothing</strong> — it's a harmless empty bundle until you tick something. And an <strong>inactive</strong> role grants nothing either, so you can switch a role off without deleting it or un-assigning everyone.</div>
</div>

<!-- 4. Assigning -->
<div class="help-section" id="assigning">
    <div class="help-section-header"><?php echo helpSectionNum('assigning'); ?>
        <div>
            <h3>Assigning it</h3>
            <p>Inside a role you pick who holds it, two ways:</p>
        </div>
    </div>
    <div class="help-table"><table>
        <tr><th>Assign to</th><th>Effect</th></tr>
        <tr><td><strong>Analysts</strong></td><td>Those specific people gain the role's capabilities.</td></tr>
        <tr><td><strong>Teams</strong></td><td>Every member of the team inherits it — and anyone added to the team later gets it automatically. Good for &ldquo;the whole training team administers the LMS&rdquo;.</td></tr>
    </table></div>
    <p>Someone's effective permissions are simply everything their directly-assigned roles grant, plus everything their teams' roles grant, added together. There's no way for one role to take away what another grants — roles only ever add.</p>
    <div class="help-note"><strong>Deny by default.</strong> Until a role grants it, a non-administrator has <em>no</em> access to any module's settings. That's deliberate: a settings screen is administration, so it stays shut until you open it for someone. This is the opposite of module access, where the default is open.</div>
</div>

<!-- 5. Administrators -->
<div class="help-section" id="admins">
    <div class="help-section-header"><?php echo helpSectionNum('admins'); ?>
        <div>
            <h3>Administrators don't need a role</h3>
            <p>A <strong>System administrator</strong> already has every capability, everywhere — they bypass this whole system. So you never assign roles to your admins, and turning an ordinary analyst into an admin (on System &rarr; Analysts) gives them everything regardless of roles.</p>
        </div>
    </div>
    <div class="help-note ok"><strong>This is what makes it safe to switch on.</strong> Because admins keep everything, nothing you do here can lock <em>you</em> — or any administrator — out of anything. Roles only ever <em>add</em> reach for the non-admins you choose.</div>
    <div class="help-note warn"><strong>One consequence worth knowing.</strong> Some module settings screens were, until now, reachable by anyone with the module. As modules move into this system, a non-admin who used to reach those settings will need a role granting them — otherwise they'll find the door closed. That's the intended tightening, but it's worth a heads-up to anyone who quietly relied on the old openness.</div>
</div>

<!-- 6. Good to know -->
<div class="help-section" id="notes">
    <div class="help-section-header"><?php echo helpSectionNum('notes'); ?>
        <div>
            <h3>Good to know</h3>
        </div>
    </div>
    <ul>
        <li><strong>Deleting a role</strong> removes it from everyone who held it, and they lose the access it granted (unless they're an administrator). It can't be undone, but nothing else breaks — recreate it if you delete one by mistake.</li>
        <li><strong>Managing roles is itself administrator-only.</strong> Only System administrators can create, edit and assign roles — the page and its every action are behind the same gate as the rest of System.</li>
        <li><strong>Capabilities grow over time.</strong> Today the list is deliberately small (the LMS is the first module wired in). As more modules gain their own settings capability, they'll appear in the grant list automatically — your existing roles don't change, you just have more to tick.</li>
        <li><strong>Enforced properly, not just hidden.</strong> A role's limits are checked on the server for both the page and its save actions — someone without the capability can't reach the settings by typing the URL or calling the API directly.</li>
    </ul>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
