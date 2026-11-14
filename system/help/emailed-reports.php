<?php
/**
 * System Help - Emailed reports (5.3.0).
 */
require __DIR__ . '/_init.php';
$helpSlug = 'emailed-reports';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this page is for</h3>
            <p>Some reports have an <strong>Email</strong> button beside PDF. It sends the report to the person who pressed it - and only to them. This page decides what that email carries.</p>
        </div>
    </div>
    <p>The first report with Email is the "what if" before deleting a directory source, and Preview on <a href="directory-maintenance.php">Imported people</a>. There is no box to type somebody else's address: the report goes to the email address on your own analyst account, so it can be read later or forwarded by you.</p>
    <p>Every report emailed is in the email send log (the <strong>Outbound</strong> tab of a mailbox's activity, under Tickets &rarr; Settings &rarr; Mailboxes), whether it was sent or not, as <em>Report emailed to self</em>.</p>
</div>

<!-- 2. The three ways -->
<div class="help-section" id="ways">
    <div class="help-section-header"><?php echo helpSectionNum('ways'); ?>
        <div>
            <h3>The three ways</h3>
            <p>Tick any of them - at least one. Body and attachment are ticked to start with.</p>
        </div>
    </div>
    <ul>
        <li><strong>In the body of the email</strong>: the report itself, readable in the email without opening anything.</li>
        <li><strong>As an attachment</strong>: the PDF travels in the email. It opens wherever the email is read, even where nothing can reach FreeITSM.</li>
        <li><strong>As a download link</strong>: the email carries a link instead of the file. The link opens only for the analyst it was sent to, signed in, until it expires.</li>
    </ul>
    <p>If the only way ticked cannot carry a report - a report with no version for the body, or a download link before Database Verification has added what links need - the PDF is attached instead, so the report still arrives.</p>
    <div class="help-note">An email can carry at most <strong>3 MB</strong> of attachments. A bigger report is refused with a message saying so: download it with PDF instead. The limit is the same for Microsoft 365, Gmail and SMTP, because Microsoft 365 refuses anything much bigger once the file has been encoded for email.</div>
</div>

<!-- 3. Which to choose -->
<div class="help-section" id="choosing">
    <div class="help-section-header"><?php echo helpSectionNum('choosing'); ?>
        <div>
            <h3>Which to choose</h3>
            <p>It depends on where the email is read.</p>
        </div>
    </div>
    <ul>
        <li><strong>FreeITSM is only reachable inside your network</strong> (most internal installs): keep <strong>As an attachment</strong> ticked. A link in an email read on a phone, at home, cannot open a page nobody outside can reach.</li>
        <li><strong>You would rather reports did not sit in mailboxes</strong>: tick <strong>As a download link</strong> alone. The email holds only a link; the file stays in FreeITSM, opens only for its owner, expires, and can be revoked.</li>
        <li><strong>Both</strong> is fine too: the email says the report is also attached.</li>
    </ul>
    <p>When the link tick is shown, a warning under it appears if FreeITSM's address looks unreachable from elsewhere - <code>localhost</code>, a private IP address, a <code>.local</code> name, a name with no dot, or no public address set. Set the <strong>Public web address</strong> under Tickets &rarr; Settings, or keep the attachment.</p>
</div>

<!-- 4. Download links -->
<div class="help-section" id="links">
    <div class="help-section-header"><?php echo helpSectionNum('links'); ?>
        <div>
            <h3>Download links</h3>
            <p>Who can open one, and for how long.</p>
        </div>
    </div>
    <ul>
        <li><strong>Only the analyst it was sent to.</strong> Signed out, the link asks them to sign in and then carries on to the file. Anybody else who opens it - another analyst included - is told it is not theirs.</li>
        <li><strong>Until it expires</strong>: after 1, 7 or 30 days, chosen on this page (7 to start with). An expired link says so.</li>
        <li><strong>Until it is revoked</strong>: an administrator can revoke any link here. Its file is deleted at once.</li>
    </ul>
    <p>A scheduled job, <em>Emailed report links tidy-up</em>, runs daily: it deletes the files of links that have expired or been revoked, and removes the links from the list 90 days after they expired. Nothing to set up.</p>
    <div class="help-note">Download links need a table that <strong>Database Verification</strong> adds. Until it has run, the link tick says so and the other two ways carry on working.</div>
</div>

<!-- 5. Revoking and activity -->
<div class="help-section" id="activity">
    <div class="help-section-header"><?php echo helpSectionNum('activity'); ?>
        <div>
            <h3>Revoking and activity</h3>
            <p>Every link sent, and everything that happened to it.</p>
        </div>
    </div>
    <p><strong>Download links</strong> lists every link: who it was sent to, the file, when it was made and expires, how many times it was downloaded, and whether it is active, expired or revoked. <strong>Revoke</strong> stops it working and deletes its file.</p>
    <p><strong>Recent activity</strong> lists each link sent, each download, each revoke, each tidy-up - and each refusal, with the reason (another analyst, expired, revoked, an unknown link) - with the analyst and IP address. The same events are kept in the system log.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
