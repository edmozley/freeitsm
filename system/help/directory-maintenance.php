<?php
/**
 * System Help — Imported people (#261).
 */
require __DIR__ . '/_init.php';
$helpSlug = 'directory-maintenance';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this page is for</h3>
            <p>People imported from a directory - LDAP, an address book or Microsoft Entra ID - sometimes need a decision nobody else will make: their source was deleted, it no longer brings them in, or they should never have been imported. This page lists them and lets you decide.</p>
        </div>
    </div>
    <p>The same rules hold for every kind of source. <strong>A person is kept up to date by a source only while that source exists.</strong> While it does, the fields it owns are locked and the person cannot be deleted by hand, even with the source switched off, because switching it back on would bring them back. Once the source is deleted, nothing can update them again, so nothing about them is locked any more.</p>
    <div class="help-note"><strong>Nobody with tickets or equipment is ever deleted here.</strong> They are listed as skipped, with the reason. Everything done on this page is recorded in the system log, with who did it, and only covers the companies you can access.</div>
</div>

<!-- 2. Source deleted -->
<div class="help-section" id="orphans">
    <div class="help-section-header"><?php echo helpSectionNum('orphans'); ?>
        <div>
            <h3>People whose source was deleted</h3>
            <p>People still marked as imported by a source that no longer exists.</p>
        </div>
    </div>
    <p>Deleting a source now leaves its people as ordinary people, or marks them as left if you tick the box in the delete question. This list is for the ones from before that, or from a source deleted some other way. System &rarr; Authentication says how many there are, and People shows them as a gap on the organisation chart.</p>
    <ul>
        <li><strong>Keep as ordinary people</strong>: every field can be edited and no import changes them again. Their history stays where it is.</li>
        <li><strong>Mark as left</strong>: as an import would when somebody leaves.</li>
        <li><strong>Delete</strong>: for somebody who should not be here at all.</li>
    </ul>
</div>

<!-- 3. No longer brought in -->
<div class="help-section" id="stale">
    <div class="help-section-header"><?php echo helpSectionNum('stale'); ?>
        <div>
            <h3>People a source no longer brings in</h3>
            <p>Pick a source, and how long.</p>
        </div>
    </div>
    <p>Each import records the date it last found each person. This lists the people still assigned to the source that no import has found for 30 days to a year: removed from the directory, moved out of what is imported, or because the import stopped running. If almost everybody is listed, look at the source's History tab first: the import has probably stopped.</p>
    <p>The missed-run safety check already marks people as left when they go missing; this is the list to review them. The same three choices. Keeping somebody means the source no longer manages them.</p>
</div>

<!-- 4. Remove imported people -->
<div class="help-section" id="remove">
    <div class="help-section-header"><?php echo helpSectionNum('remove'); ?>
        <div>
            <h3>Remove imported people</h3>
            <p>Undo an import that should not have happened.</p>
        </div>
    </div>
    <p>A wrong scope, the wrong tenant, a first run without Preview: this lists the people the source <em>created</em>, from every import or a chosen one, and removes the ones you pick, with their sign-in link and group memberships.</p>
    <ul>
        <li><strong>Only the people it created.</strong> People it matched to somebody already here are never on the list: removing them would delete somebody who existed before the import.</li>
        <li><strong>Only once the source does not import.</strong> Switch the source, or its import, off and save first, or the next import would create everybody again.</li>
    </ul>
    <div class="help-note"><strong>Each source's page links here</strong>, under its Run button, already on that source.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
