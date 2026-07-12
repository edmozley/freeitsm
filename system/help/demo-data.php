<?php
/**
 * System Help — Demo data.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'demo-data';
require __DIR__ . '/_top.php';
?>
<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What demo data is for</h3></div>
    <p class="syshelp-lead">Demo data populates a FreeITSM install with realistic, pre-built sample records so you can explore how each module looks and behaves with content in it — without spending an afternoon entering test data by hand.</p>
    <p>It's aimed squarely at <strong>evaluating and trialling</strong> the product, demos, and training environments. You import one module at a time from <strong>System &rarr; Demo data</strong>, and a record count next to each module tells you roughly how much it will add.</p>
    <div class="syshelp-callout warn"><strong>For evaluation, not production.</strong> Demo data creates real records in your database (including analyst logins). Use it on a fresh or test install, not on a system that already holds your live data. There is no separate sandbox — everything is added to the database you're connected to.</div>
    <div class="syshelp-callout info"><strong>Sample analyst logins.</strong> Core Data creates 4 analysts who can all sign in with the password <code>demo1234</code>. These are demo accounts — never leave them on a system that will go live.</div>
</div>

<!-- 2. What's included -->
<div class="syshelp-section" id="whats">
    <div class="syshelp-section-header"><h3>What's included</h3></div>
    <p class="syshelp-lead">Everything starts with one foundation block, then each module is optional and imported on its own.</p>

    <h4>Core Data (the foundation)</h4>
    <p>Analysts, departments, teams, ticket types, origins and end users. <strong>Every other module depends on this</strong>, so it must be imported first. It adds 4 analysts (password <code>demo1234</code>), 5 departments, 2 teams, 15 end users, 5 ticket types and 4 origins.</p>

    <h4>Per-module data</h4>
    <p>Once Core Data is in, you can import any of these independently:</p>
    <table class="syshelp-table">
        <tr><th>Module</th><th>What it adds</th><th>Approx. records</th></tr>
        <tr><td><strong>Tickets</strong></td><td>30 tickets with emails, notes and audit history across statuses and priorities.</td><td>~115</td></tr>
        <tr><td><strong>Assets</strong></td><td>10 assets (laptops, desktops, monitors) with types, statuses and user assignments.</td><td>~24</td></tr>
        <tr><td><strong>Knowledge Base</strong></td><td>5 articles (VPN, Outlook, passwords, printing, onboarding) with tags.</td><td>~23</td></tr>
        <tr><td><strong>Change Management</strong></td><td>5 changes spanning Draft, Approved, In Progress, Completed and Cancelled.</td><td>~5</td></tr>
        <tr><td><strong>Calendar</strong></td><td>3 categories and 8 events — maintenance windows, meetings, releases.</td><td>~11</td></tr>
        <tr><td><strong>Morning Checks</strong></td><td>6 checks with 30 days of OK / Warning / Fail results.</td><td>~186</td></tr>
        <tr><td><strong>Contracts</strong></td><td>3 suppliers, 5 contacts, 3 contracts with SLA terms, plus lookups.</td><td>~25</td></tr>
        <tr><td><strong>Service Status</strong></td><td>5 services with 2 incidents in resolved and monitoring states.</td><td>~11</td></tr>
        <tr><td><strong>Software</strong></td><td>20 applications with 13 licences (subscription, perpetual, expired, bundled).</td><td>~33</td></tr>
        <tr><td><strong>Forms</strong></td><td>2 forms (New Starter, Equipment Return) with fields and 3 submissions.</td><td>~22</td></tr>
        <tr><td><strong>Tasks</strong></td><td>12 parent tasks with subtasks, due dates and comments across To Do / In Progress / Done.</td><td>~42</td></tr>
        <tr><td><strong>Process Mapper</strong></td><td>6 ITSM flowcharts with auto-laid-out steps and connectors.</td><td>~125</td></tr>
        <tr><td><strong>CMDB</strong></td><td>8 classes, 39 objects and ~30 relationships modelling a small IT estate.</td><td>~310</td></tr>
    </table>

    <h4>Cross-module extras</h4>
    <p>Two extras appear only once their prerequisites are imported, because they link records across modules:</p>
    <ul>
        <li><strong>Software Installed on Assets</strong> — links applications to computers (~55 installation records). Needs both <strong>Software</strong> and <strong>Assets</strong> first.</li>
        <li><strong>Dashboard Widgets</strong> — 15 widgets and 3 per-analyst dashboard layouts. Needs <strong>Tickets</strong> first.</li>
    </ul>
</div>

<!-- 3. How to import -->
<div class="syshelp-section" id="how">
    <div class="syshelp-section-header"><h3>How to import</h3></div>
    <p class="syshelp-lead">Open <strong>System &rarr; Demo data</strong> and work top to bottom. Each module imports on its own with a single click.</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><div class="syshelp-step-num">1</div><div><strong>Import Core Data first.</strong> Click <em>Import</em> on the Core Data card at the top. Every per-module button stays disabled until this succeeds, because the other modules reference analysts, users and types from Core Data.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">2</div><div><strong>Import the modules you want to see.</strong> Once Core Data is in, the module buttons unlock. Click <em>Import</em> on any module — there's no fixed order and you only need the ones you're evaluating.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">3</div><div><strong>Add the cross-module extras.</strong> Once their prerequisites are imported, the <em>Software Installed on Assets</em> and <em>Dashboard Widgets</em> sections appear at the bottom and their buttons unlock.</div></div>
    </div>
    <div class="syshelp-callout ok"><strong>It remembers what you've done.</strong> When you reopen the page it checks what already exists, so imported modules show a green tick and don't have to be re-run.</div>
</div>

<!-- 4. Removing demo data -->
<div class="syshelp-section" id="remove">
    <div class="syshelp-section-header"><h3>Removing demo data</h3></div>
    <p class="syshelp-lead">Anything you import can be removed again from the same page — a module's button turns green once imported, and clicking it again removes that module's demo records.</p>
    <p>After a successful import the <em>Import</em> button becomes a green, ticked button showing the record count. Click it a second time and FreeITSM asks you to confirm before deleting that module's demo data.</p>
    <div class="syshelp-callout warn"><strong>Removal deletes records.</strong> Removing a module deletes the demo records it created. If you've edited a demo ticket or added your own notes to demo content, that work goes too — only remove demo data from a system where losing those records is fine. When in doubt, point demo data at a throwaway install you can drop entirely.</div>
</div>

<!-- 5. Tips & gotchas -->
<div class="syshelp-section" id="tips">
    <div class="syshelp-section-header"><h3>Tips &amp; gotchas</h3></div>
    <ul>
        <li><strong>Core Data is mandatory.</strong> Nothing else can be imported until it's in, and removing Core Data underneath other modules will leave them referencing records that no longer exist.</li>
        <li><strong>Import Assets and Software together</strong> if you want the full picture — only then does the <em>Software Installed on Assets</em> link become available.</li>
        <li><strong>Import Tickets</strong> to unlock the <em>Dashboard Widgets</em> extra, which gives the ticket dashboard something to display.</li>
        <li><strong>Sign in as a demo analyst</strong> with <code>demo1234</code> to see the data through an analyst's eyes, but disable or remove those accounts before any real use.</li>
        <li><strong>Errors show inline.</strong> If an import fails, the reason appears in red under that module's card; fix it and click <em>Import</em> again.</li>
    </ul>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
