<?php
/**
 * System Help — Debug tools.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'debug-tools';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What Debug tools are</h3>
            <p>When a particular action fails, hangs, or seems to do nothing, a debug tool runs the same code the app uses — but narrates every step. Each one returns a single plain-text report so you can see exactly where it broke, and paste that report back to support.</p>
        </div>
    </div>
    <p>Every diagnostic is a small, self-contained script. It checks the environment, traces the operation, and prints its findings as plain text under <code>=== SECTION ===</code> headers. There is nothing to configure: you open the page, pick the tool that matches your problem, and run it.</p>
    <div class="help-note"><strong>Each tool is purpose-built.</strong> They aren't a general log viewer — each one targets a specific flow that has been known to fail (a demo-data import, a ticket delete) and reports on that flow in depth.</div>
</div>

<!-- 2. How to run one -->
<div class="help-section" id="how">
    <div class="help-section-header"><?php echo helpSectionNum('how'); ?>
        <div>
            <h3>How to run a diagnostic</h3>
        </div>
    </div>
    <div class="help-steps">
        <div class="help-step"><div class="help-step-num">1</div><div><strong>Find the matching tool.</strong> Each card names the action it diagnoses and a <em>when to run this</em> line. Pick the one whose symptom matches what you're seeing.</div></div>
        <div class="help-step"><div class="help-step-num">2</div><div><strong>Fill in any input.</strong> Some tools ask for a value first — the ticket diagnostic, for example, needs a <strong>ticket reference</strong>. If a tool requires a value and you leave it blank, it won't run.</div></div>
        <div class="help-step"><div class="help-step-num">3</div><div><strong>Click Run.</strong> The report appears in a dark output panel below the card, with each phase under its own heading. Most tools finish in a second or two.</div></div>
        <div class="help-step"><div class="help-step-num">4</div><div><strong>Click Copy</strong> to put the whole report on your clipboard, then paste it into your support message. The report is plain text and safe to share.</div></div>
    </div>
    <div class="help-note"><strong>Read the side-effects line first.</strong> Each card tells you what (if anything) the tool persists. Most only read; one actually performs the operation — see below.</div>
</div>

<!-- 3. The diagnostics -->
<div class="help-section" id="tools">
    <div class="help-section-header"><?php echo helpSectionNum('tools'); ?>
        <div>
            <h3>The diagnostics on this page</h3>
            <p>The Debug Tools page lists every diagnostic, and each one's card says when to run it, what it checks and whether it changes anything. Three are described here; the list grows as new failure points are added.</p>
        </div>
    </div>

    <h4>D001 — Demo Core Data Import</h4>
    <p>Run this when you click <strong>Import Core Data</strong> on the Demo Data screen and it fails, hangs, or appears to do nothing. It walks the whole import path and reports:</p>
    <ul>
        <li>PHP version, OS, loaded extensions, session state and memory/POST limits.</li>
        <li>That <code>config.php</code> and <code>db_config.php</code> are present and DB credentials are defined.</li>
        <li>That the required files exist — <code>import_demo_data.php</code>, <code>core.json</code>, <code>functions.php</code>.</li>
        <li>That <code>core.json</code> parses, and how many records it would import per table.</li>
        <li>The database connection — server version, database name and character set.</li>
        <li>Each of the 9 core tables: whether it exists, its row count, and its actual columns versus expected.</li>
        <li>A <strong>write probe</strong> — inserts one sentinel row per table inside a rolled-back transaction.</li>
        <li>A <strong>live import attempt</strong> — runs the real import in-process and captures the response plus any PHP warnings.</li>
    </ul>
    <p><strong>Runtime:</strong> about 2 seconds. <strong>Side effects:</strong> the live-import step will populate demo data if it succeeds; otherwise nothing persists.</p>

    <h4>D002 — Delete Ticket (with full SQL trace)</h4>
    <p>Run this when deleting a ticket fails with a foreign-key error (for example <code>1451 Cannot delete or update a parent row</code> on <code>email_attachments</code>). Enter the <strong>ticket reference</strong> and it deletes the ticket exactly the way the app does, but shows every SQL statement and row count so you can see precisely what happened. It:</p>
    <ul>
        <li>Resolves the ticket from the reference (by <code>ticket_number</code>, falling back to a raw id).</li>
        <li>Audits every table the delete touches — existence, key columns, and each foreign key with its <code>ON DELETE</code> rule.</li>
        <li>Pinpoints the <code>fk_email_attachments_email</code> constraint and lists the exact email ids and attachment ids / filenames / paths that trigger the error.</li>
        <li>Counts the child rows that will be removed (attachments, emails, notes, audit, time entries, and cascade children).</li>
        <li>Performs the delete inside a transaction, echoing every <code>DELETE</code> statement, its parameters and rows affected, then <code>COMMIT</code>.</li>
        <li>Verifies the ticket and its children are gone, and removes the orphaned attachment files from disk.</li>
    </ul>
    <p><strong>Runtime:</strong> about 1 second.</p>
    <div class="help-note warn"><strong>D002 is destructive.</strong> On success the ticket and all of its data are permanently deleted. On any error the transaction is rolled back and nothing changes — but only run it on a ticket you actually intend to delete.</div>

    <h4>D019 — Page health</h4>
    <p>Run this <strong>after every upgrade</strong>, next to Database Verification, and whenever a page shows its loading spinner forever, a blank area, or buttons that do nothing. If one of a page's scripts contains a single mistake the browser cannot read, the browser runs <em>none</em> of that script: the page looks loaded and nothing on it works. The server cannot see this — it sends the page perfectly well — so this tool runs in <strong>your browser</strong>, not on the server.</p>
    <ul>
        <li>It finds every page by looking at the files, so a page added in an update is checked the day it arrives.</li>
        <li>Press <strong>Run</strong> and it opens each page as you, four at a time, with a progress bar and a row per module that turns green or red as it goes.</li>
        <li>Every script on each page — written into the page or loaded from a file — is read by your browser <strong>without being run</strong>.</li>
        <li>A problem names the page, the line, the browser's own message and the code around that line. A script file the page asks for that is not there, and a page that answers with a server error, count as problems too.</li>
        <li>Pages of modules switched off in <strong>System → Modules</strong> are skipped, as are pages that need a record to show and send you elsewhere when opened on their own. Each says why.</li>
        <li>Modules with a problem move to the top. <strong>Problems only</strong> hides the rest; <strong>Copy</strong> and <strong>Download</strong> give you the report to paste into a bug report.</li>
    </ul>
    <p><strong>Runtime:</strong> about a minute. <strong>Side effects:</strong> only the result — the date, who ran it and the counts — is kept, so Database Verification can show when the pages were last checked. Opening each page does on the server whatever opening it normally does, exactly as if you had clicked to it.</p>
    <div class="help-note"><strong>Why it exists.</strong> In 4.3.0 the Assets page never finished loading: one comment had swallowed the code after it. Every check that ran on the server passed. This tool, and the same check run on every change to FreeITSM before release, would have caught it.</div>

    <h4>D020 — Files: can this person delete or move this folder?</h4>
    <p>Run this when somebody is told they <strong>cannot delete or move a folder</strong> in Files and nobody can see why. Deleting or moving a folder takes everything in it along, so it needs <strong>Modify</strong> on the folder <em>and</em> on every folder inside it, however deep, as Windows does. The message they are shown names at most one folder, and only one they can already see, so a refusal can never be used to find the names of folders kept from them. That keeps their message short; this report is the whole picture, for an administrator only.</p>
    <ul>
        <li>Choose the <strong>person</strong> and the <strong>folder</strong>.</li>
        <li>The verdict comes first: PASS, or FAIL with the exact message they are shown.</li>
        <li>Then the folder and everything inside it as a tree, each folder with their level, whether it inherits, and PASS or FAIL. Files are listed under their folder and pass or fail with it, because deleting a file needs Modify on its folder.</li>
        <li>Each FAIL says why: inheritance turned off with nothing granted to them, a grant below Modify, or nothing granted anywhere above.</li>
    </ul>
    <p><strong>Runtime:</strong> instant. <strong>Side effects:</strong> none — read-only. The report shows folder and file names the person may not be allowed to see, so keep it to yourself.</p>

    <?php /* GH #365: the bell named the signed-in user instead of whoever (or whatever) made the change. */ ?>
    <h4>D021 - Notification bell: did that person really do it?</h4>
    <p>Run this when the bell says <em>"Handed to your team by ..."</em>, <em>"Assigned to you by ..."</em> or <em>"... changed the status"</em> and that person says they never touched the ticket. The bell does not keep its sentence - each row keeps the kind of event and a <strong>copy of a name</strong> - while the ticket's <strong>Audit</strong> records who really made each change, or <strong>System</strong> when a workflow did.</p>
    <ul>
        <li>Name the <strong>ticket</strong>.</li>
        <li>It lists every bell row raised for that ticket - the words, the name kept and who received it (a change sent to a whole team appears once) - then the ticket's history and the workflows that ran on it.</li>
        <li>Each bell row is paired with the history line of the same kind (Team, Owner, Status, Priority, Ticket Created, the note itself, the reply's email) nearest in time, and given a verdict: <strong>MATCH</strong>, <strong>MISMATCH</strong>, <strong>AUTOMATIC CREDITED TO A PERSON</strong>, <strong>NOT FOUND</strong> or <strong>NOT CHECKED</strong>.</li>
        <li>Up to 5.7.0 the bell named whoever was signed in when a change ran, so a workflow that assigned a team was announced under the name of whoever's browser triggered it. The report calls those out.</li>
    </ul>
    <p><strong>Runtime:</strong> instant. <strong>Side effects:</strong> none — read-only. Administrators only.</p>
    <div class="help-note"><strong>Paired by kind and time, not by a link.</strong> Rows from before 5.8.0 keep no record of the change that raised them, and when several updates reach someone's bell before they read it the bell merges them into one row that keeps only the latest - the report says so. Each person can ask the same question of their own bell with the <strong>(i)</strong> button on a row; it gives the same answer from the same code.</div>
</div>

<!-- 4. When to use them -->
<div class="help-section" id="safety">
    <div class="help-section-header"><?php echo helpSectionNum('safety'); ?>
        <div>
            <h3>When to use them — and what to watch</h3>
        </div>
    </div>
    <p>Debug tools are for diagnostics and troubleshooting, not day-to-day administration. Reach for one only when a normal action has failed and you need to know why, or when support asks you to run a specific diagnostic and send back its output.</p>
    <div class="help-note warn"><strong>These tools run real code against your live install.</strong> Most only read and report safely, but at least one (the ticket delete) performs a genuine, irreversible operation. Always read the card's <em>side-effects</em> line before clicking Run, and don't run a destructive tool unless you mean its result.</div>
    <div class="help-note ok"><strong>Safe by default:</strong> the report itself is plain text with no secrets in it, so you can copy it straight into a support ticket or email.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
