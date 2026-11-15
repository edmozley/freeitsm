<?php
/**
 * System Help - Locations in use (GH #311).
 */
require __DIR__ . '/_init.php';
$helpSlug = 'locations-in-use';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this page is for</h3>
            <p><strong>Assets, people, tickets, contracts and projects</strong> can each have a <strong>Location</strong> from System &rarr; Locations. A location that any of them points at <strong>cannot be deleted</strong> - deleting it would quietly leave them with none.</p>
        </div>
    </div>
    <p>Pick a location to see how many of each point at it, and the first few of each with a link. When you try to delete a location that is in use, System &rarr; Locations offers to open this page for it.</p>
</div>

<!-- 2. Moving or clearing -->
<div class="help-section" id="moving">
    <div class="help-section-header"><?php echo helpSectionNum('moving'); ?>
        <div>
            <h3>Moving or clearing</h3>
        </div>
    </div>
    <ul>
        <li><strong>Move</strong> - choose another location under <em>Move them all to</em>. Everything that pointed at the old one now points at the new one.</li>
        <li><strong>Clear</strong> - they keep no location at all.</li>
    </ul>
    <p>Once nothing points at it, the location can be deleted in System &rarr; Locations.</p>
</div>

<!-- 3. Good to know -->
<div class="help-section" id="notes">
    <div class="help-section-header"><?php echo helpSectionNum('notes'); ?>
        <div>
            <h3>Good to know</h3>
        </div>
    </div>
    <ul>
        <li><strong>Each record is checked against its own company.</strong> A company's own location can only be given to that company's records; a shared one to anyone's. A record the new location is not open to is left as it was, and the page says how many.</li>
        <li><strong>History:</strong> assets, tickets and projects record the change in their history. People and contracts keep no history of their location.</li>
        <li><strong>Who can use it:</strong> administrators, and anyone who may manage locations in Assets. A shared location needs access to every company, as deleting one does.</li>
    </ul>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
