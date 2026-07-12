<?php
/**
 * System Help — Database verification.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'db-verify';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What this tool does</h3></div>
    <p class="syshelp-lead">Database verification compares your live database against the schema this version of FreeITSM expects. Where something is missing it adds it; where everything already matches it simply reports a clean bill of health. You run it on demand from System &rarr; Database verification and watch the results appear in a table.</p>
    <p>It is the same routine that runs during first-time setup, so it doubles as a repair and upgrade step you can run at any time.</p>
    <div class="syshelp-callout ok"><strong>Non-destructive by design.</strong> Verification only ever <em>adds</em> structure — it creates missing tables, columns, keys and indexes. It never deletes your rows. The one case where data could be removed (clearing orphaned rows that block a foreign key) is never automatic: it is offered as a separate <strong>Fix</strong> button that asks you to confirm first.</div>
</div>

<!-- 2. What it checks -->
<div class="syshelp-section" id="checks">
    <div class="syshelp-section-header"><h3>What it checks and creates</h3></div>
    <p>The tool walks the full schema for every FreeITSM module and reconciles it with your database.</p>
    <div class="syshelp-cards">
        <div class="syshelp-card">
            <h4>Tables</h4>
            <p>Any table in the expected schema that doesn't exist is created with the correct engine and character set.</p>
        </div>
        <div class="syshelp-card">
            <h4>Columns</h4>
            <p>Missing columns are added to existing tables with their proper type, nullability and default — so an older install picks up new fields.</p>
        </div>
        <div class="syshelp-card">
            <h4>Keys &amp; foreign keys</h4>
            <p>Unique keys and the foreign keys that link tables together (for example tickets to statuses) are added where absent.</p>
        </div>
        <div class="syshelp-card">
            <h4>Indexes</h4>
            <p>Performance indexes the application relies on are created if they're not already present.</p>
        </div>
    </div>
    <p>Alongside structure, the routine also seeds default lookup rows (such as task statuses and priorities) into brand-new tables, and runs idempotent backfill migrations that populate new columns from old ones.</p>
    <div class="syshelp-callout info"><strong>Safe to re-run.</strong> Every step is guarded — anything already in place is left untouched. Running verification a second time on a healthy database makes no changes and reports everything as fine.</div>
</div>

<!-- 3. Running a check -->
<div class="syshelp-section" id="running">
    <div class="syshelp-section-header"><h3>Running a check</h3></div>
    <p class="syshelp-lead">When to run it: after applying a FreeITSM update, after restoring or migrating a database, or whenever something looks like a column or table might be missing.</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><div class="syshelp-step-num">1</div><div>Open <strong>System &rarr; Database verification</strong>.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">2</div><div>Click <strong>Run verification</strong>. The button shows a spinner while the check runs against the database.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">3</div><div>Read the summary cards and results table that appear. If anything shows <strong>Pending</strong>, see the next section.</div></div>
    </div>
    <div class="syshelp-callout"><strong>Who can run it:</strong> you must be signed in to reach the page. It is an admin-level maintenance tool, so run it deliberately rather than as a routine.</div>
</div>

<!-- 4. Reading the results -->
<div class="syshelp-section" id="results">
    <div class="syshelp-section-header"><h3>Reading the results</h3></div>
    <p>Four summary cards at the top tally the run, and the table below lists each table that was touched, its status, and a short detail of what happened.</p>
    <table class="syshelp-table">
        <tr><th>Status</th><th>What it means</th></tr>
        <tr><td><strong>OK</strong></td><td>The table and its columns already matched — nothing to do.</td></tr>
        <tr><td><strong>Created</strong></td><td>The table was missing and has just been created.</td></tr>
        <tr><td><strong>Updated</strong></td><td>The table existed but one or more columns, keys or indexes were added (or a finished migration tidied up legacy columns).</td></tr>
        <tr><td><strong>Pending</strong></td><td>A step couldn't complete yet because data is in the way — usually orphaned rows blocking a foreign key. Nothing was changed; see below.</td></tr>
        <tr><td><strong>Error</strong></td><td>A statement failed. The detail text explains why so you can address it and re-run.</td></tr>
    </table>
    <p>You may also see <strong>Seeded</strong> (default rows inserted into a new lookup table) and <strong>Migrated</strong> (values backfilled from an old column into a new one).</p>
    <div class="syshelp-callout ok">A run where every row is <strong>OK</strong> means your database is fully in step with this version of FreeITSM.</div>
</div>

<!-- 5. Pending & Fix -->
<div class="syshelp-section highlight" id="pending">
    <div class="syshelp-section-header"><h3>Pending rows and the Fix button</h3></div>
    <p class="syshelp-lead">Because verification never deletes your data, there's one situation it can't resolve on its own: when a foreign key can't be added because <strong>orphaned</strong> rows already exist — child rows (such as attachments, notes, audit entries or time entries) whose parent record is gone.</p>
    <p>When this happens the row is marked <strong>Pending</strong>, the detail text says how many orphaned rows were found, and a <strong>Fix</strong> button appears next to it.</p>
    <ul>
        <li>Clicking <strong>Fix</strong> shows a confirmation explaining that the orphaned rows will be permanently deleted — their parent record no longer exists, so the data is already unreachable.</li>
        <li>Only after you confirm are those rows removed. Verification then re-runs automatically, the foreign key adds cleanly, and the Pending row clears.</li>
    </ul>
    <div class="syshelp-callout warn"><strong>Fix is the only step that deletes anything,</strong> and it never acts without your explicit confirmation. If you'd rather investigate first, leave it Pending — nothing else in the run is held up by it.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
