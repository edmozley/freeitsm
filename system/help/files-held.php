<?php
/**
 * System Help - Deleted folders kept (GH #293).
 */
require __DIR__ . '/_init.php';
$helpSlug = 'files-held';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this page is for</h3>
            <p>Deleted files and folders in <strong>Files</strong> wait in the recycle bin. When an administrator has set how long to keep them (Files &rarr; Settings &rarr; Recycle bin), a job deletes them for good once they have been there that long.</p>
        </div>
    </div>
    <p>The job only deletes a folder if <strong>the person who deleted it could have deleted all of it themselves</strong>. A folder holding folders that person cannot change is <strong>kept</strong> - past its date - and listed here. Nothing on this page is deleted automatically.</p>
</div>

<!-- 2. Why -->
<div class="help-section" id="why">
    <div class="help-section-header"><?php echo helpSectionNum('why'); ?>
        <div>
            <h3>Why a folder is kept</h3>
        </div>
    </div>
    <ul>
        <li><strong>It holds folders its deleter cannot change.</strong> Usually a subfolder that stopped inheriting its permissions and was never shared with them. The page lists those folders.</li>
        <li><strong>Its deleter no longer has Modify on the folder itself</strong> - their access was changed after they deleted it.</li>
        <li><strong>Who deleted it was not recorded</strong>, and something inside it does not inherit its permissions - so it cannot be known who may have been kept out.</li>
    </ul>
    <p>A folder <strong>cannot be deleted at all</strong> while it holds anything the person cannot change, so folders should rarely land here - usually because permissions were changed after the folder was deleted.</p>
    <p><strong>Past its date</strong> means the retention period would already have deleted it, but for this. The Maintenance card counts those.</p>
</div>

<!-- 3. Resolving -->
<div class="help-section" id="resolve">
    <div class="help-section-header"><?php echo helpSectionNum('resolve'); ?>
        <div>
            <h3>Resolving one</h3>
        </div>
    </div>
    <ul>
        <li><strong>Restore it.</strong> Anyone with Modify on the folder can, from the recycle bin in Files. Somebody who can open one of the folders listed - but not the deleted folder - sees that folder in their own recycle bin as <em>Deleted along with a folder above it</em>, and can restore it on its own. It comes back to where the deleted folder was, and exactly the same people can open it as before.</li>
        <li><strong>Give whoever deleted it Modify</strong> on the folders listed. The next run of the recycle bin job then deletes it like anything else past its date.</li>
        <li><strong>Delete it permanently.</strong> Someone with Modify on the folder and on everything in it can, from the recycle bin.</li>
    </ul>
    <div class="help-note">Each run of the job in Reporting &rarr; Logs &rarr; Background jobs says how many folders it kept, and points here.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
