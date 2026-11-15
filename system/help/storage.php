<?php
/**
 * System Help — Storage (#284).
 */
require __DIR__ . '/_init.php';
$helpSlug = 'storage';
require __DIR__ . '/_top.php';
?>
<!-- 1. The connection -->
<div class="help-section" id="connection">
    <div class="help-section-header"><?php echo helpSectionNum('connection'); ?>
        <div>
            <h3>The S3 connection</h3>
        </div>
    </div>
    <p>FreeITSM can keep stored files in any S3-compatible storage: AWS S3, Backblaze B2, Wasabi, Cloudflare R2, MinIO and others. The install has <strong>one</strong> connection - endpoint, region, bucket, keys and an optional prefix - entered on <strong>System → Storage</strong>. <strong>Test</strong> writes, reads back and deletes a small file; the connection is only saved once that works.</p>
    <p>Files (Files → Settings → Storage) and every store switched to S3 use this one bucket, each in its own folder under the prefix: <code>files/</code>, <code>tickets/</code>, <code>changes/</code>, <code>documents/</code>, <code>recordings/</code>, <code>warroom/</code>. Keep the bucket private: every download still goes through FreeITSM, which checks who may see the file.</p>
    <p><strong>Server-side encryption</strong>: most providers encrypt stored files by default; some only when asked. Choose <strong>AES-256</strong> to ask on every file written. Test first: a provider that cannot do it refuses, and the Test says so before anything is saved.</p>
    <p>An endpoint on plain <code>http://</code> is fine for a server on your own network (a MinIO beside FreeITSM). To an address on the internet the page warns you: the files themselves would cross it unencrypted (the secret does not - requests are only signed with it). Use <code>https://</code> if the provider offers it.</p>
    <div class="help-note">While any store keeps files in the bucket, its endpoint, bucket and prefix cannot be changed (move the stores back to the server folder first). The keys, region, path style and encryption can be changed - for example to rotate the access key - except the keys, region and path style while a move is still reading files from the bucket (they wait until it is finished).</div>
</div>

<!-- 2. The stores -->
<div class="help-section" id="stores">
    <div class="help-section-header"><?php echo helpSectionNum('stores'); ?>
        <div>
            <h3>Choosing where each store lives</h3>
        </div>
    </div>
    <p>Each store has its own switch - <strong>Server folder</strong> or <strong>S3-compatible storage</strong> - so you choose what goes to the bucket:</p>
    <ul>
        <li><strong>Ticket attachments</strong> - files on emails, portal tickets and channel messages (usually the largest).</li>
        <li><strong>Change attachments</strong> - evidence and plans on changes.</li>
        <li><strong>Documents</strong> - documents on tickets, assets, articles, projects and other records, and pictures pasted into notes.</li>
        <li><strong>Screen recordings</strong> - recordings made from the self-service portal.</li>
        <li><strong>War-room attachments</strong> - files shared in a war room.</li>
    </ul>
    <p>Files has its own switch on Files → Settings → Storage. Form images, RFP uploads, branding and course content always stay on the server.</p>
</div>

<!-- 3. Moving -->
<div class="help-section" id="moving">
    <div class="help-section-header"><?php echo helpSectionNum('moving'); ?>
        <div>
            <h3>How a move works</h3>
        </div>
    </div>
    <ol>
        <li>Choose the new place for a store and <strong>Apply</strong>. New files go to the new place at once.</li>
        <li>The background job <em>Storage move</em> copies the existing files one by one, checks each copy, and only then reads it from the new place. Until its copy is ready a file is read from the old place, so nothing becomes unreadable during the move. Files that arrive at the old place while the move runs are picked up too.</li>
        <li>When every file is copied, choose <strong>Remove</strong> (the old copies are deleted) or <strong>Finish</strong> (they are kept). Nothing is deleted before that, and Remove only deletes an old copy once its new copy is there, at its size.</li>
    </ol>
    <p>If storage cannot be reached the move simply waits and carries on later. A file that keeps failing is listed with its error; <strong>Retry</strong> tries again, and <strong>Skip</strong> sets aside files whose old copy was already gone, so they cannot block the move.</p>
    <div class="help-note">For <strong>Skip</strong> to tell that a file is missing from a bucket, the access key needs permission to list it (<code>s3:ListBucket</code>). Without it, S3 answers "access denied" rather than "not found" for a missing file, and the file stays failed.</div>
    <div class="help-note">A move only progresses while background jobs run (Reporting → System logs → Background jobs); the page says so if they are not running.</div>
</div>

<!-- 4. Notes -->
<div class="help-section" id="notes">
    <div class="help-section-header"><?php echo helpSectionNum('notes'); ?>
        <div>
            <h3>Good to know</h3>
        </div>
    </div>
    <ul>
        <li><strong>Who can change this</strong>: administrators, and anyone given <strong>Storage and backups</strong> on System → Roles (under Files, with access to Files). It is never part of "Manage everything in Files": whoever holds it can send every stored file to a bucket of their choosing. The same permission is needed to change where Files itself is stored.</li>
        <li><strong>You are told</strong>: every administrator gets a bell, and an email when a mailbox can send, when a storage location changes, a move starts or a move ends - for Files and for each store. Not the person who made the change. A failed Test is recorded in the system log.</li>
        <li>When a store is in S3, things that need the file on this server - search text extraction, sending to a tracker, attaching to an outgoing message - fetch a temporary copy and remove it afterwards.</li>
        <li>If the bucket cannot be reached, people see a "storage cannot be reached" message rather than an error page, and stores kept on the server folder are not affected.</li>
    </ul>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
