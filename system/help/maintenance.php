<?php
/**
 * System Help — Maintenance (GH #263).
 */
require __DIR__ . '/_init.php';
$helpSlug = 'maintenance';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What Maintenance is for</h3>
            <p>Some data outlives what it belonged to. Delete a department and its tickets still point at it; nothing breaks loudly, the tickets simply drop out of every queue. <strong>Maintenance</strong> is where the tools that find that kind of leftover live, each next to a count of how much it has found.</p>
        </div>
    </div>
    <p>Open it from <strong>System &rarr; Maintenance</strong>. Each card opens its tool's own page, and the search box filters the cards as you type; press Enter when one card is left to go straight to it.</p>
    <div class="help-note"><strong>Nothing here runs on its own.</strong> The counts only look. Every change is made by you, on the tool's page, where it says what it is about to do.</div>
</div>

<!-- 2. The tools -->
<div class="help-section" id="tools">
    <div class="help-section-header"><?php echo helpSectionNum('tools'); ?>
        <div>
            <h3>The tools</h3>
            <p>Each tool has its own help page with the details.</p>
        </div>
    </div>
    <h4>Orphaned tickets</h4>
    <p>Tickets in a department that has been deleted, which hides them from every team's queue. Reassign them to a real department, one at a time or in bulk. See <a href="orphaned-tickets.php">Orphaned tickets</a>.</p>
    <div class="help-note">A tool keeps its own address when it moves here, so a bookmark to <code>system/orphaned-tickets/</code> still works.</div>
</div>

<!-- 3. The counts -->
<div class="help-section" id="counts">
    <div class="help-section-header"><?php echo helpSectionNum('counts'); ?>
        <div>
            <h3>The counts</h3>
            <p>Each card says how many items its tool has found when the page opened: <strong>Found: 3</strong> in amber when there is something to look at, <strong>Nothing found</strong> when there is not. Reload the page to count again after fixing something.</p>
        </div>
    </div>
    <p>A count is a single quick question to the database, so opening the page is cheap. If a tool cannot be asked - its table does not exist yet because Database Verification has not been run, say - its card shows no count rather than a wrong one.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
