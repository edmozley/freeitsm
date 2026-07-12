<?php
/**
 * System Help — Modules.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'modules';
require __DIR__ . '/_top.php';
?>
<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What this area does</h3></div>
    <p class="syshelp-lead">Modules lets you decide, per analyst, which parts of FreeITSM each person can open — Tickets, Assets, Knowledge, Changes, Calendar, Checks, Reporting, Software, Forms, Contracts, Wiki, Tasks, CMDB and System. It's a single grid of analysts against modules, with a toggle in every cell.</p>
    <p>It does not switch a module on or off for the whole install. Every module stays available; this page just governs who is allowed to reach it. Only active analysts appear in the grid.</p>
    <div class="syshelp-callout info">Changes save the moment you flip a toggle — there is no Save button. A small confirmation appears after each change.</div>
</div>

<!-- 2. The access matrix -->
<div class="syshelp-section" id="matrix">
    <div class="syshelp-section-header"><h3>The access matrix</h3></div>
    <p class="syshelp-lead">Each row is an analyst. The first toggle is <strong>All access</strong>; the remaining columns are the individual modules.</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><div class="syshelp-step-num">1</div><div><strong>All access on.</strong> The analyst can open every module. The individual module toggles are all ticked and greyed out — there's nothing to choose, because they already have the lot. This is the default for a new analyst.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">2</div><div><strong>All access off.</strong> The row switches to restricted mode and the individual toggles become editable. Untick the modules this analyst should <em>not</em> see, and leave the rest ticked.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">3</div><div><strong>Adjust per module.</strong> With All access off, tick or untick any module to grant or remove it. Each change is saved on its own.</div></div>
    </div>
    <div class="syshelp-callout warn"><strong>System is always granted.</strong> The System column stays ticked and locked even in restricted mode — so an analyst who can reach this page can never accidentally lock themselves (or others) out of System administration.</div>
</div>

<!-- 3. What analysts see -->
<div class="syshelp-section" id="effect">
    <div class="syshelp-section-header"><h3>What analysts see</h3></div>
    <p>Access controls where a module shows up for that analyst across the app:</p>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>The waffle menu &amp; navigation</h4>
            <p>Modules the analyst isn't allowed don't appear in their app switcher or navigation — they simply aren't offered.</p>
        </div>
        <div class="syshelp-card">
            <h4>The analyst using the app now</h4>
            <p>If you change your own access, it takes effect on your current session; for others it applies from their next sign-in or page load.</p>
        </div>
    </div>
    <div class="syshelp-callout">Removing a module only hides it from that one analyst. It doesn't delete any data or affect other analysts — restoring access brings the module straight back.</div>
</div>

<!-- 4. Good to know -->
<div class="syshelp-section" id="notes">
    <div class="syshelp-section-header"><h3>Good to know</h3></div>
    <ul>
        <li><strong>Only active analysts are listed.</strong> Deactivated accounts don't appear in the grid.</li>
        <li><strong>All access is the same as ticking everything.</strong> Internally, an analyst with no restrictions stored is treated as having every module — turning All access off and on simply switches between "everything" and "only the ticked ones".</li>
        <li><strong>No undo prompt.</strong> Because each toggle saves immediately, double-check before removing a module from someone who relies on it. Re-ticking it restores access instantly.</li>
    </ul>
    <div class="syshelp-callout ok"><strong>Tip:</strong> leave most analysts on All access and only restrict the handful who should be limited to a few modules — it's quicker to manage and easy to reason about.</div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
