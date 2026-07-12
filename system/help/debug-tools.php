<?php
/**
 * System Help — Debug tools.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'debug-tools';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What Debug tools are</h3></div>
    <p class="syshelp-lead">When a particular action fails, hangs, or seems to do nothing, a debug tool runs the same code the app uses — but narrates every step. Each one returns a single plain-text report so you can see exactly where it broke, and paste that report back to support.</p>
    <p>Every diagnostic is a small, self-contained script. It checks the environment, traces the operation, and prints its findings as plain text under <code>=== SECTION ===</code> headers. There is nothing to configure: you open the page, pick the tool that matches your problem, and run it.</p>
    <div class="syshelp-callout info"><strong>Each tool is purpose-built.</strong> They aren't a general log viewer — each one targets a specific flow that has been known to fail (a demo-data import, a ticket delete) and reports on that flow in depth.</div>
</div>

<!-- 2. How to run one -->
<div class="syshelp-section" id="how">
    <div class="syshelp-section-header"><h3>How to run a diagnostic</h3></div>
    <div class="syshelp-steps">
        <div class="syshelp-step"><div class="syshelp-step-num">1</div><div><strong>Find the matching tool.</strong> Each card names the action it diagnoses and a <em>when to run this</em> line. Pick the one whose symptom matches what you're seeing.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">2</div><div><strong>Fill in any input.</strong> Some tools ask for a value first — the ticket diagnostic, for example, needs a <strong>ticket reference</strong>. If a tool requires a value and you leave it blank, it won't run.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">3</div><div><strong>Click Run.</strong> The report appears in a dark output panel below the card, with each phase under its own heading. Most tools finish in a second or two.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">4</div><div><strong>Click Copy</strong> to put the whole report on your clipboard, then paste it into your support message. The report is plain text and safe to share.</div></div>
    </div>
    <div class="syshelp-callout"><strong>Read the side-effects line first.</strong> Each card tells you what (if anything) the tool persists. Most only read; one actually performs the operation — see below.</div>
</div>

<!-- 3. The diagnostics -->
<div class="syshelp-section" id="tools">
    <div class="syshelp-section-header"><h3>The diagnostics on this page</h3></div>
    <p class="syshelp-lead">Two diagnostics ship today. The list grows as new failure points are added.</p>

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
    <div class="syshelp-callout warn"><strong>D002 is destructive.</strong> On success the ticket and all of its data are permanently deleted. On any error the transaction is rolled back and nothing changes — but only run it on a ticket you actually intend to delete.</div>
</div>

<!-- 4. When to use them -->
<div class="syshelp-section" id="safety">
    <div class="syshelp-section-header"><h3>When to use them — and what to watch</h3></div>
    <p>Debug tools are for diagnostics and troubleshooting, not day-to-day administration. Reach for one only when a normal action has failed and you need to know why, or when support asks you to run a specific diagnostic and send back its output.</p>
    <div class="syshelp-callout warn"><strong>These tools run real code against your live install.</strong> Most only read and report safely, but at least one (the ticket delete) performs a genuine, irreversible operation. Always read the card's <em>side-effects</em> line before clicking Run, and don't run a destructive tool unless you mean its result.</div>
    <div class="syshelp-callout ok"><strong>Safe by default:</strong> the report itself is plain text with no secrets in it, so you can copy it straight into a support ticket or email.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
