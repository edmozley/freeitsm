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
    <p>You'll find it in <strong>System &rarr; Maintenance</strong>; its card there says how many people lost their source.</p>
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
    <p>Deleting a source now leaves its people as ordinary people, or marks them as leavers if you choose that in the delete question. This list is for the ones from before that, or from a source deleted some other way. System &rarr; Authentication says how many there are, and People shows them as a gap on the organisation chart.</p>
    <ul>
        <li><strong>Keep as ordinary people</strong>: every field can be edited and no import changes them again. Their history stays where it is.</li>
        <li><strong>Mark as leavers</strong>: as an import would when somebody leaves. Leavers are hidden in People unless Show leavers is ticked.</li>
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

<!-- 5. What if -->
<div class="help-section" id="whatif">
    <div class="help-section-header"><?php echo helpSectionNum('whatif'); ?>
        <div>
            <h3>What if: the full effect first</h3>
            <p>Before anything is deleted, a preview lists in full what would happen - and changes nothing.</p>
        </div>
    </div>
    <h4>Deleting a source</h4>
    <p>Deleting a source in <strong>System &rarr; Authentication</strong> opens its preview, which is also the question:</p>
    <ul>
        <li><strong>Every person it imported</strong>, and what happens to each: kept as a current person, or marked as a leaver if you choose that - switching between the two redraws the list. The fields that unlock for them are named.</li>
        <li><strong>Everything else that names it</strong>: people's and analysts' sign-in links, analysts and teams that sign in with it, groups that put people in a team, groups seen at sign-in, imported groups and their members, the address book write log - all deleted with it - and the import history, which is kept.</li>
        <li><strong>The scheduled import</strong>: whether it imports this source now, and how many others it carries on with.</li>
    </ul>
    <p>Nothing changes until you press <strong>Delete</strong>. <strong>Save as PDF</strong> keeps a copy, to read in your own time or pass to whoever owns the directory first.</p>
    <h4>Remove imported people</h4>
    <p><strong>Preview</strong>, beside Remove, lists what it would do to the people you picked - or to everybody in the list when you have picked nobody: who would be deleted and who skipped, and why; how many sign-in links and group memberships go with each; and how many people report to them, who would be left with no manager. It works while the source still imports, so you can look before you switch it off.</p>
    <div class="help-note">A preview of a source's delete covers <strong>every company</strong>, because the delete does. The Remove preview covers the companies you can access, as Remove does.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
