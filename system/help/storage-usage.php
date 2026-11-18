<?php
/**
 * System Help - Storage usage (5.6.0).
 */
require __DIR__ . '/_init.php';
$helpSlug = 'storage-usage';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this page is for</h3>
            <p>FreeITSM keeps files in several folders on the server: ticket and change attachments, documents attached to records, Files, learning content, recordings, logos and more. <strong>System &rarr; Storage usage</strong> lists every one of them in one place, with where it is, how many files it holds and how much space they take.</p>
        </div>
    </div>
    <p>It is <strong>read only</strong>. Nothing on the page moves, creates or deletes anything - a folder that has never been needed yet is shown as <em>Not created yet</em> and left that way.</p>
    <div class="help-note">Only administrators can open it, because it shows where files live on the server.</div>
</div>

<!-- 2. Columns -->
<div class="help-section" id="columns">
    <div class="help-section-header"><?php echo helpSectionNum('columns'); ?>
        <div>
            <h3>Reading the table</h3>
        </div>
    </div>
    <ul>
        <li><strong>What it holds</strong> - which part of FreeITSM writes there. A folder inside <code>uploads</code> that FreeITSM does not recognise is listed too, by its name, so nothing there goes unseen.</li>
        <li><strong>Folder</strong> - the full path on the server.</li>
        <li><strong>Files</strong> and <strong>Size</strong> - counted when the page opens, every subfolder included. The small files FreeITSM puts in a folder to keep it private (<code>.htaccess</code>, <code>web.config</code>) are left out.</li>
        <li><strong>Newest file</strong> - when something last landed there.</li>
    </ul>
    <p>The folders are listed straight away and counted a few at a time. On a very large folder counting stops after a few seconds, and its figures read <em>at least</em> - they are a minimum, not the whole.</p>
    <p>Under the table: the <strong>free space</strong> left on the drive that holds FreeITSM.</p>
    <div class="help-note"><strong>Files</strong> can keep its files in a folder of its own, or in S3-compatible storage (Files &rarr; Settings &rarr; Storage). Its row follows that setting. Files kept in S3 are not counted here, and nor is anything kept in the database - pictures embedded in knowledge articles, for example, which are backed up with the database.</div>
</div>

<!-- 3. Backups -->
<div class="help-section" id="backups">
    <div class="help-section-header"><?php echo helpSectionNum('backups'); ?>
        <div>
            <h3>Backups and Docker</h3>
        </div>
    </div>
    <p>A full backup of FreeITSM is the database <strong>plus every folder on this page</strong>. The database holds the records; these folders hold the files they point at, and one without the other leaves attachments that cannot be opened.</p>
    <p>The <strong>encryption key</strong> deserves a backup of its own, kept safe. Without it, stored mailbox passwords and integration credentials can never be read again.</p>
    <p><strong>In Docker</strong> the table has one more column, <strong>Survives an update</strong>. An update replaces the container, and a folder that is not on a volume is emptied when it does. <em>No - inside the container</em> means that folder's files will be lost at the next update: add a volume for it first. This is the same check as the warning shown before an update.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
