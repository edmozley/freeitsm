<?php
/**
 * System Help — API.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'api';
require __DIR__ . '/_top.php';
?>
<!-- 1. Overview -->
<div class="syshelp-section" id="overview">
    <div class="syshelp-section-header"><h3>What the API is</h3></div>
    <p class="syshelp-lead">The REST API (version 1) lives at <code>/api/v1/</code> and currently covers the <strong>Tickets module</strong>: creating, searching, updating, trashing and restoring tickets, adding notes, reading conversations, audit history, live SLA state, logging time, and managing requesters — plus the reference data (statuses, priorities, types, origins, departments, analysts, companies) an integration needs.</p>
    <p>Everything an API key does behaves exactly as if it were done in the UI: audit entries are written, assignment and closure emails go out, CSAT can auto-trigger on close, and the workflow engine fires its events — so your workflows run whether a ticket was updated by an analyst or by an integration.</p>
    <div class="syshelp-callout info"><strong>One prerequisite.</strong> Run <a href="db-verify.php">Database Verification</a> once after updating, so the API key tables are created.</div>
</div>

<!-- 2. Keys -->
<div class="syshelp-section" id="keys">
    <div class="syshelp-section-header"><h3>Creating keys</h3></div>
    <p class="syshelp-lead">Open <strong>System &gt; API</strong> and click <strong>Add</strong>. A key has a name (what it's for), an analyst it <em>acts as</em>, a permission set, an optional company scope, an optional expiry date and an optional rate limit.</p>
    <div class="syshelp-steps">
        <div class="syshelp-step"><span class="syshelp-step-num">1</span><div><strong>Name the key</strong> after the integration ("Zabbix monitoring", "Onboarding script") so you can revoke the right one later.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">2</span><div><strong>Choose who it acts as.</strong> Notes, audit entries and time logged through the key are attributed to this analyst. Many teams create a dedicated "Integration" analyst for this.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">3</span><div><strong>Tick only the permissions it needs</strong> — see the next section.</div></div>
        <div class="syshelp-step"><span class="syshelp-step-num">4</span><div><strong>Copy the key immediately.</strong> It is shown once, then only a SHA-256 hash is stored — it can never be displayed again. Lose it and you simply create a new key.</div></div>
    </div>
    <p>From the key list you can edit a key's name, permissions, scope, expiry and rate limit at any time, disable it temporarily, or delete it outright — integrations using a disabled or deleted key stop working immediately.</p>
</div>

<!-- 3. Permissions -->
<div class="syshelp-section" id="permissions">
    <div class="syshelp-section-header"><h3>Granular permissions</h3></div>
    <p class="syshelp-lead">Keys start with <strong>no permissions</strong>. Each resource has its own actions you grant independently, so a key can, say, create tickets but never read them, or read everything but change nothing.</p>
    <table class="syshelp-table">
        <thead><tr><th>Resource</th><th>Actions</th></tr></thead>
        <tbody>
            <tr><td>Tickets</td><td>read, create, update, delete (trash), restore</td></tr>
            <tr><td>Ticket notes</td><td>read, create</td></tr>
            <tr><td>Ticket conversation</td><td>read</td></tr>
            <tr><td>Ticket audit log</td><td>read</td></tr>
            <tr><td>Ticket SLA</td><td>read</td></tr>
            <tr><td>Time entries</td><td>read, create, delete</td></tr>
            <tr><td>Requesters</td><td>read, create, update</td></tr>
            <tr><td>Analysts</td><td>read</td></tr>
            <tr><td>Companies</td><td>read</td></tr>
            <tr><td>Reference data</td><td>read</td></tr>
        </tbody>
    </table>
    <div class="syshelp-callout"><strong>Example.</strong> A monitoring tool that raises tickets when servers go down needs only <em>Tickets: create</em> (and perhaps <em>Reference data: read</em> to look up priority ids). If its key leaks, nobody can read a single ticket with it.</div>
</div>

<!-- 4. Companies -->
<div class="syshelp-section" id="companies">
    <div class="syshelp-section-header"><h3>Company scope (multi-company installs)</h3></div>
    <p class="syshelp-lead">On a multi-company (MSP) install each key is either scoped to <strong>all companies</strong> or to a <strong>specific list</strong>. A scoped key only ever sees, creates or touches tickets belonging to its companies — the same isolation analysts get.</p>
    <p>Tickets created through a scoped key default to your Default company if it's in scope, otherwise the key's first scoped company; pass <code>company_id</code> to file under a specific one. Moving a ticket between companies requires the key to be scoped to <em>both</em> sides.</p>
    <p>On a single-company install this section doesn't appear — there's nothing to scope.</p>
</div>

<!-- 5. Using -->
<div class="syshelp-section" id="using">
    <div class="syshelp-section-header"><h3>Using the API</h3></div>
    <p class="syshelp-lead">Send the key in the <code>Authorization</code> header. Requests and responses are JSON; real HTTP status codes carry the outcome (200/201 success, 401 bad key, 403 missing permission, 404 not visible, 422 invalid input, 429 rate limited).</p>
    <div class="syshelp-card">
        <h4>Create a ticket from anything that can make an HTTP request</h4>
        <pre style="background:#263238;color:#eceff1;border-radius:6px;padding:14px;font-size:12px;overflow-x:auto;">curl -X POST "https://your-server/api/v1/tickets" \
  -H "Authorization: Bearer fitsm_your_key_here" \
  -H "Content-Type: application/json" \
  -d '{"subject":"Disk space low on SQL01","requester_email":"alerts@example.com","description":"C: below 5% free.","priority":"High"}'</pre>
    </div>
    <p>List endpoints support filtering (<code>?state=open&amp;priority=High</code>), search (<code>?q=printer</code>), date ranges, sorting and pagination. Every response from a list endpoint includes a <code>meta</code> block with the total count and page numbers.</p>
    <div class="syshelp-callout info"><strong>Rate limits.</strong> Keys get 60 requests/minute by default (configurable per key). Responses carry <code>X-RateLimit-Remaining</code> so integrations can pace themselves; exceeding the limit returns 429.</div>
    <div class="syshelp-callout"><strong>No URL rewriting?</strong> If your web server doesn't have mod_rewrite, the same endpoints work at <code>/api/v1/index.php/tickets</code> — the docs tester and paths are otherwise identical.</div>
</div>

<!-- 6. Docs -->
<div class="syshelp-section" id="docs">
    <div class="syshelp-section-header"><h3>Documentation &amp; testing</h3></div>
    <p class="syshelp-lead">The <strong>Documentation</strong> button on the API page opens a live reference: every endpoint with its parameters, required permission and an example body — plus a <strong>Try it</strong> panel that fires real requests against your install and shows the response, status code and remaining rate limit in the browser.</p>
    <p>Paste a key at the top of the page (it stays in your browser only), hit <strong>Test</strong> to confirm what the key can do, then expand any endpoint and send. Each request also shows the equivalent <code>curl</code> command ready to paste into a script.</p>
</div>

<!-- 7. Safety -->
<div class="syshelp-section" id="safety">
    <div class="syshelp-section-header"><h3>Good practice</h3></div>
    <ul>
        <li><strong>One key per integration.</strong> Never share a key between systems — you lose the ability to revoke one without breaking the other.</li>
        <li><strong>Least privilege.</strong> Grant the smallest permission set that works; you can always add more later.</li>
        <li><strong>Set expiry dates</strong> on keys for temporary work (contractors, migrations) so they clean themselves up.</li>
        <li><strong>Watch "Last used".</strong> The key list shows when and from which IP each key last authenticated — a key that's never used, or used from somewhere unexpected, is worth investigating.</li>
        <li><strong>Use HTTPS in production.</strong> The key travels in a header; TLS keeps it private.</li>
    </ul>
    <div class="syshelp-callout info"><strong>Note:</strong> the legacy keys under Software settings (used by the asset-inventory script and Watchtower extension) are a separate, older mechanism and are unaffected by anything here.</div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
