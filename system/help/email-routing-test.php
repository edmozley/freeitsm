<?php
/**
 * System Help — Email routing test.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'email-routing-test';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What this tool does</h3></div>
    <p class="syshelp-lead">It answers one question: <em>“if an email came from this sender to this mailbox, which company would the ticket land in?”</em> It runs the real inbound-routing logic and shows you the decision — but it's a pure <strong>dry run</strong>. No ticket is created, no mail is sent, nothing is changed.</p>
    <p>This is purely a diagnostic. It's useful when you want to confirm a new sender or domain mapping behaves the way you expect <em>before</em> real mail arrives, or to understand why a past email routed where it did.</p>
    <div class="syshelp-callout info">Routing only matters on a <strong>multi-company</strong> install — one where you've added more than one company under <a href="companies.php">Companies</a>. With a single company, every email goes to that company and there's nothing to test (see <em>Single-company installs</em> below).</div>
</div>

<!-- 2. Running a test -->
<div class="syshelp-section" id="use">
    <div class="syshelp-section-header"><h3>Running a test</h3></div>
    <p class="syshelp-lead">Fill in the form on the left and the result appears on the right.</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><div class="syshelp-step-num">1</div><div><strong>Enter the sender address.</strong> Type the <em>From</em> email address you want to test, e.g. <code>jane@acme.co.uk</code>. You can leave it blank to test mailbox-only routing.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">2</div><div><strong>Choose a mailbox.</strong> Pick the inbound mailbox the email would arrive at. Each option shows whether the mailbox is <strong>pinned</strong> to a specific company or is a <strong>shared</strong> inbox.</div></div>
        <div class="syshelp-step"><div class="syshelp-step-num">3</div><div><strong>Run it.</strong> Press the <strong>Run</strong> button (or Enter in the address box). The result and the full decision trace appear on the right.</div></div>
    </div>
    <p>The headline result is one of two outcomes: the email routes to a named <strong>company</strong>, or it falls to <strong>triage</strong> (no company could be determined and a human needs to decide).</p>
</div>

<!-- 3. Reading the trace -->
<div class="syshelp-section" id="trace">
    <div class="syshelp-section-header"><h3>Reading the decision trace</h3></div>
    <p class="syshelp-lead">Under the headline, each routing rule is listed in the order it's checked. A ticked rule is the one that decided the result; the rest show why they didn't apply.</p>
    <p>The rules are evaluated top to bottom, and the first one that matches wins:</p>
    <table class="syshelp-table">
        <tr><th>Rule</th><th>What it checks</th></tr>
        <tr><td><strong>Pinned mailbox</strong></td><td>If the mailbox is pinned to one company, the email goes straight to that company — the sender doesn't matter.</td></tr>
        <tr><td><strong>Specific sender</strong></td><td>The exact sender address is mapped to a company (useful for people on personal/free email).</td></tr>
        <tr><td><strong>Domain</strong></td><td>The sender's email domain (e.g. <code>acme.co.uk</code>) is registered to a company. Free-email domains like Gmail are skipped here on purpose.</td></tr>
        <tr><td><strong>Triage</strong></td><td>Nothing above matched, so the email lands in triage for a person to assign.</td></tr>
    </table>
    <div class="syshelp-callout">Each step in the trace is marked as <strong>fired</strong> (it decided the result), <strong>skipped</strong> (it was checked but didn't match), or not evaluated (an earlier rule already won). This tells you exactly why mail routes the way it does.</div>
</div>

<!-- 4. Single-company installs -->
<div class="syshelp-section" id="single">
    <div class="syshelp-section-header"><h3>Single-company installs</h3></div>
    <p class="syshelp-lead">If you've only ever had one company, this tool has nothing to decide.</p>
    <p>FreeITSM works out whether you're multi-company by counting companies. With a single company, all inbound mail simply goes to that company — there's no domain or sender routing to apply. The page will tell you this rather than running a meaningful test.</p>
    <div class="syshelp-callout ok">To make routing matter, add a second company under <a href="companies.php">Companies</a> and give each company its email domains. The routing test then becomes a useful way to confirm your mappings before real mail starts arriving.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
