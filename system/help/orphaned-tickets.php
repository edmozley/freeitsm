<?php
/**
 * System Help — Orphaned tickets.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'orphaned-tickets';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What an orphaned ticket is</h3></div>
    <p class="syshelp-lead">An orphaned ticket is one that belongs to a department that <strong>no longer exists</strong>. The department was deleted; the tickets that pointed at it weren't. They still carry the old department's id, and nothing in FreeITSM answers to it any more.</p>
    <p>That makes them <em>invisible</em>, which is what makes them dangerous. They aren't in any team's queue, because no team is linked to a department that doesn't exist. They aren't in the &ldquo;no department&rdquo; bucket either, because as far as the database is concerned they <em>do</em> have a department. They're simply gone from view — still open, still someone's problem, and nobody can see them.</p>
    <div class="syshelp-callout info"><strong>This page is the safety net.</strong> It finds them and lets you put them back into a real department. If it says <strong>no orphaned tickets</strong>, there's nothing to do — that's the normal state, and worth a glance whenever you've been reorganising departments.</div>
</div>

<!-- 2. How tickets get orphaned -->
<div class="syshelp-section" id="cause">
    <div class="syshelp-section-header"><h3>How tickets get orphaned</h3></div>
    <p class="syshelp-lead">Effectively one way: somebody <strong>deleted a department that still had tickets in it</strong>. Usually during a reorganisation — merging two desks, retiring a service line — where the tickets in the old department were forgotten.</p>
    <div class="syshelp-callout ok"><strong>Deactivating a department is the safe way to retire it.</strong> An inactive department still exists, so its tickets keep working and stay visible; it simply stops being offered for new ones. Prefer that to deleting, and you will never create an orphan.</div>
    <p>Soft-deleted (binned) tickets are never counted as orphans — they're already out of the queues on purpose.</p>
</div>

<!-- 3. Reading the list -->
<div class="syshelp-section" id="reading">
    <div class="syshelp-section-header"><h3>Reading the list</h3></div>
    <p class="syshelp-lead">Each row is one stranded ticket: its number, subject, requester and status, so you can tell what it is at a glance — plus the two columns that matter here.</p>
    <table class="syshelp-table">
        <tr><th>Column</th><th>What it tells you</th></tr>
        <tr><td><strong>Bad department</strong></td><td>The id of the department that no longer exists, in red. Tickets sharing the same id came from the same deleted department, so they very likely all belong in the same new home.</td></tr>
        <tr><td><strong>Reassign to</strong></td><td>The dropdown and <strong>Assign</strong> button that fix this row.</td></tr>
        <tr><td><strong>Company</strong></td><td>On a multi-company install only. Check it before you reassign — see the warning below.</td></tr>
    </table>
    <p>If there are a great many, the page shows the first 1,000 and tells you so. Fix those, reload, and the next batch appears.</p>
</div>

<!-- 4. Reassigning tickets -->
<div class="syshelp-section highlight" id="fixing">
    <div class="syshelp-section-header"><h3>Reassigning tickets</h3></div>
    <p class="syshelp-lead">Pick a department in the row's dropdown, press <strong>Assign</strong>. The ticket moves there immediately, reappears in that department's queue, and the change is written to the ticket's audit trail — so the history shows it was rescued and from where.</p>
    <div class="syshelp-callout warn"><strong>Choose the department before you press Assign.</strong> The dropdown starts on <em>&mdash; choose &mdash;</em>, and pressing Assign while it's still there does not warn you: it sets the ticket to <strong>no department</strong>. That's a valid outcome, just rarely the one you meant. It's recoverable — the ticket then shows up in the no-department bucket rather than vanishing — but check the dropdown first.</div>
    <div class="syshelp-callout"><strong>Reassigning changes the department, never the company.</strong> On a multi-company install the department list is not filtered by company, so it is possible to file a ticket into a department that belongs to a different client. Read the Company column and pick accordingly.</div>
</div>

<!-- 5. Fixing them in bulk -->
<div class="syshelp-section" id="bulk">
    <div class="syshelp-section-header"><h3>Fixing them in bulk</h3></div>
    <p class="syshelp-lead">Orphans usually arrive in a batch — one deleted department, fifty tickets — so fix them as a batch.</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><span class="syshelp-step-num">1</span><div>Tick the rows you want, or use the checkbox in the header to select them all.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">2</span><div>The bulk bar appears, showing how many you've picked.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">3</span><div>Choose the destination department and press <strong>Assign selected</strong>. Every ticket moves, and each one gets its own audit entry.</div></div>
    </div>
    <p>The bulk dropdown also offers <strong>&mdash; No department &mdash;</strong> as an explicit choice, which is the honest option when you genuinely don't know where a batch belongs: it gets them out of limbo and into the no-department bucket where they can at least be seen and triaged.</p>
    <div class="syshelp-callout info"><strong>Sort your fix by the Bad department column.</strong> Everything that shared a deleted department almost certainly shares a destination too — select that group, assign it in one go, and move to the next id.</div>
</div>

<!-- 6. Good to know -->
<div class="syshelp-section" id="notes">
    <div class="syshelp-section-header"><h3>Good to know</h3></div>
    <ul>
        <li><strong>The destination list only offers active departments</strong> — you can't rescue a ticket into another dead end.</li>
        <li><strong>A ticket in a deactivated department is not orphaned</strong> and won't appear here. That department still exists, so its tickets are still reachable. Only <em>deleted</em> departments strand tickets.</li>
        <li><strong>There is no undo</strong>, but there's no damage either: if you send a ticket to the wrong department, open it and change the department in the normal way. The audit trail records both moves.</li>
        <li><strong>Check this page after any department clear-out</strong>, and after a major upgrade or data import. It costs a glance, and an unnoticed orphan is an SLA breach waiting to happen.</li>
    </ul>
    <div class="syshelp-callout ok"><strong>The best fix is prevention:</strong> deactivate departments instead of deleting them, and if you must delete one, move its tickets out first.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
