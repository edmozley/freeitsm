<?php
/**
 * System Help — Search.
 * Mostly a diagnostic screen, so the help is organised around the questions it
 * answers: is everything indexed, why is this document not findable, and what
 * does rebuilding actually do.
 */
require __DIR__ . '/_init.php';

$helpSlug = 'search';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this page is for</h3>
            <p>It shows what the search index holds, and lets you rebuild it. It is a diagnostic screen rather than a settings screen — there is nothing here you need to configure to make search work.</p>
        </div>
    </div>
    <div class="help-note ok"><strong>The index keeps itself up to date.</strong> A new ticket, message, note or article is indexed as it is created. You do not need to schedule anything, and you do not need to visit this page as part of normal running.</div>
    <p>Two reasons to come here: something is not turning up in search and you want to know why, or you have changed the database's own search settings and need the index rebuilt to match.</p>
</div>

<!-- 2. The index -->
<div class="help-section" id="index">
    <div class="help-section-header"><?php echo helpSectionNum('index'); ?>
        <div>
            <h3>What the index holds</h3>
            <p>Five kinds of entry, counted separately, so you can see at a glance whether a whole category is missing.</p>
        </div>
    </div>
    <div class="help-table"><table>
        <thead><tr><th>Kind of entry</th><th>Where it comes from</th></tr></thead>
        <tbody>
            <tr><td><strong>Ticket subjects</strong></td><td>the subject line of every ticket</td></tr>
            <tr><td><strong>Messages</strong></td><td>emails in and out on a ticket</td></tr>
            <tr><td><strong>Notes</strong></td><td>notes your team adds to tickets</td></tr>
            <tr><td><strong>Knowledge articles</strong></td><td>the knowledge base</td></tr>
            <tr><td><strong>Attachment text</strong></td><td>the text read out of files attached to tickets — see below</td></tr>
        </tbody>
    </table></div>
    <p>The page also reports when the index was last updated, and how many tickets and articles are <em>not</em> in it. Either <strong>"Everything is indexed. Nothing to do."</strong> or a count that tells you exactly how much a rebuild would pick up.</p>
    <div class="help-note warn"><strong>"The search index has not been created yet."</strong> The table is missing, which happens on an install that has not been through a database check since search was added. Run <a href="db-verify.php">Database Verification</a>, come back, and rebuild.</div>
</div>

<!-- 3. The minimum word length -->
<div class="help-section" id="min-word">
    <div class="help-section-header"><?php echo helpSectionNum('min-word'); ?>
        <div>
            <h3>Short words, and why some searches find nothing</h3>
            <p>This one surprises people, and it is not FreeITSM's doing.</p>
        </div>
    </div>
    <p>The page reports: <em>"Your database ignores words shorter than <strong>n</strong> letters when searching."</em> That is a MySQL/MariaDB setting, not a FreeITSM one, and the default is commonly <strong>3</strong> or <strong>4</strong>.</p>
    <div class="help-note warn"><strong>So a search for <code>VPN</code>, <code>DNS</code>, <code>SQL</code> or a two-letter site code can return nothing at all</strong> even though the words are right there in the tickets. Nothing is broken and nothing is missing from the index — the database is declining to match on a word it considers too short to be worth indexing.</div>
    <p>If your team searches for short acronyms constantly, lower the setting in your database configuration — <code>ft_min_word_len</code> on MySQL, <code>innodb_ft_min_token_size</code> for InnoDB tables — restart the database, then <strong>come back here and rebuild</strong>. The change only applies to entries indexed after it, which is exactly what the rebuild is for.</p>
    <div class="help-note"><strong>This is the one case where rebuilding is genuinely required</strong> rather than just reassuring.</div>
</div>

<!-- 4. Attachments -->
<div class="help-section" id="attachments">
    <div class="help-section-header"><?php echo helpSectionNum('attachments'); ?>
        <div>
            <h3>Attachments</h3>
            <p>Files attached to tickets are read so their contents can be searched — and the page tells you honestly which ones could not be.</p>
        </div>
    </div>
    <p>Every file is given an outcome, counted, so nobody has to guess why a particular document is not turning up:</p>
    <div class="help-table"><table>
        <thead><tr><th>Outcome</th><th>What it means</th></tr></thead>
        <tbody>
            <tr><td><strong>Text read</strong></td><td>Searchable. Nothing to do.</td></tr>
            <tr><td><strong>Text read, very long file shortened</strong></td><td>Searchable, but only the first part of a very long document was kept.</td></tr>
            <tr><td><strong>Waiting to be read</strong> / <strong>Being read now</strong></td><td>In progress. Check back.</td></tr>
            <tr><td><strong>Too large to open</strong></td><td>Beyond the size limit. Its name is still searchable; its contents are not.</td></tr>
            <tr><td><strong>Format cannot be read yet</strong></td><td>Needs a document-reading service — see below.</td></tr>
            <tr><td><strong>Could not be read</strong></td><td>Tried and failed. Usually a corrupt or password-protected file.</td></tr>
        </tbody>
    </table></div>
    <p>Below the counts, <strong>Attachments that are not searchable</strong> lists the actual files and the tickets they are on, so you can judge whether it matters to you.</p>
    <div class="help-note"><strong>Word, Excel, PowerPoint and plain text are read without any extra software.</strong> PDFs, older Office formats and scanned documents need <strong>Apache Tika</strong>, which is set up under <a href="../integrations/">Integrations</a>. Without it those files are simply listed as unreadable — search still works, it just cannot see inside them.</div>
</div>

<!-- 5. Rebuilding -->
<div class="help-section" id="rebuild">
    <div class="help-section-header"><?php echo helpSectionNum('rebuild'); ?>
        <div>
            <h3>Rebuilding</h3>
            <p>Safe, resumable, and not something to do out of habit.</p>
        </div>
    </div>
    <p><strong>Rebuild index</strong> re-reads every ticket, message, note and article and writes the index again from scratch. It reports progress as it goes — <em>"Rebuilding… 400 of 2,100 tickets"</em> — and finishes with a count of everything it indexed.</p>
    <p>Do it when:</p>
    <ul>
        <li>You have changed the database's search settings (the section above).</li>
        <li>The page says tickets or articles are not in the index.</li>
        <li>You genuinely suspect the index has drifted — results that feel stale, or a ticket you know exists that search cannot find.</li>
    </ul>
    <div class="help-note"><strong>Search keeps working while it runs.</strong> Results may be incomplete until it finishes, which on a large install takes a while — but nothing is taken away and no ticket data is touched. The index is derived; it can always be thrown away and rebuilt.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
